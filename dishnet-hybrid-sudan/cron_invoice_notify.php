#!/usr/bin/env php
<?php
/**
 * cron_invoice_notify.php — New Invoice Notification Scanner
 *
 * Catches auto-generated invoices that UCRM creates without firing webhooks.
 * Runs every 15 minutes via master.php.
 *
 * Flow:
 *   1. Fetch all unpaid invoices from UCRM API
 *   2. Filter to ones created in last 24 hours
 *   3. Check invoice_notify_log.json for already-sent
 *   4. Send WhatsApp text + PDF for any new ones
 *   5. Update log to prevent double-sends
 *
 * De-duplication: invoice_notify_log.json is shared with webhook.php
 * so invoices caught by webhook are not re-sent by this cron.
 */

date_default_timezone_set('Africa/Juba');
chdir(__DIR__);

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/NotificationService.php';

function ilog(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    file_put_contents(
        $dataDir . '/invoice_notify_cron.log',
        "[{$ts}] {$msg}\n",
        FILE_APPEND | LOCK_EX
    );
}

// ── Single-instance lock ─────────────────────────────────────────────────
$lockFile = $dataDir . '/invoice_notify.lock';
$lockFp   = fopen($lockFile, 'c+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    return; // Another instance running
}

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];

$crm = CrmApiClient::fromUcrm(__DIR__, $config);
if (!$crm->isConfigured()) {
    ilog("SKIP — CRM not configured");
    flock($lockFp, LOCK_UN); fclose($lockFp); return;
}

$notify = new NotificationService($store, $config);

// ── Dedup: uses SQLite notification_dedup table (atomic, shared with webhook.php) ──

$sent    = 0;
$skipped = 0;
$errors  = 0;
$cutoffTime = strtotime('-24 hours');

// ── Fetch unpaid invoices from UCRM ──────────────────────────────────────
// Only fetch status 1=Unpaid, 2=Partial. NEVER include paid (3), void (4), or draft (0).
$allInvoices = $crm->get('billing/invoices?statuses[]=1&statuses[]=2&limit=500') ?? [];
if (empty($allInvoices)) {
    $allInvoices = $crm->get('billing/invoices?status[]=1&status[]=2&limit=500') ?? [];
}

// Filter to invoices created in last 24 hours
$recentInvoices = [];
foreach ($allInvoices as $inv) {
    $created = $inv['createdDate'] ?? $inv['created_at'] ?? $inv['createdAt'] ?? '';
    if (!$created) continue;
    $ts = strtotime($created);
    if ($ts && $ts >= $cutoffTime) {
        $recentInvoices[] = $inv;
    }
}

ilog("Fetched " . count($allInvoices) . " invoices, " . count($recentInvoices) . " in last 24h");

foreach ($recentInvoices as $inv) {
    $invoiceId  = (int)($inv['id'] ?? 0);
    $invoiceNum = (string)($inv['number'] ?? $invoiceId);
    $total      = (float)($inv['total'] ?? 0);
    $clientId   = (int)($inv['clientId'] ?? 0);
    $dueDate    = substr($inv['dueDate'] ?? $inv['maturityDate'] ?? '', 0, 10);

    if (!$invoiceId || !$clientId || $total <= 0) { $skipped++; continue; }

    // Skip draft (0), paid (3), and void (4) invoices — only send for unpaid (1) and partial (2)
    // UCRM status: 0=Draft, 1=Unpaid, 2=Partial, 3=Paid, 4=Void
    $invStatus = (int)($inv['status'] ?? -1);
    if (!in_array($invStatus, [1, 2], true)) { $skipped++; continue; }

    // ── Fresh status re-check (prevents sending to customers who just paid) ──
    // The bulk invoice fetch at the top of this cron can be 1-5 minutes stale.
    // A customer may have paid between the bulk fetch and this point.
    // Fetching the single invoice is fast (~100ms) and prevents false reminders.
    $freshInv = $crm->get("billing/invoices/{$invoiceId}");
    if ($freshInv) {
        $freshStatus = (int)($freshInv['status'] ?? -1);
        if (!in_array($freshStatus, [1, 2], true)) {
            ilog("SKIP #{$invoiceNum} — freshly paid/voided (status={$freshStatus})");
            $skipped++;
            continue;
        }
    }

    // Already notified? (atomic check+mark — shared with webhook.php via SQLite)
    $logKey = "INV{$invoiceNum}";
    if (!$notify->dedupMark($logKey)) { $skipped++; continue; }

    // Fetch client for phone + name
    $client = $crm->get("clients/{$clientId}");
    if (!$client) { $errors++; ilog("ERROR: client/{$clientId} not found"); continue; }

    $phone = '';
    foreach (($client['contacts'] ?? []) as $c) {
        if (!empty($c['phone'])) { $phone = $c['phone']; break; }
    }
    if (!$phone) { $skipped++; continue; }

    $firstName = $client['firstName'] ?? '';
    $lastName  = $client['lastName']  ?? '';
    $fullName  = trim("{$firstName} {$lastName}")
              ?: ($client['companyName'] ?? 'Customer');

    // ── Send WhatsApp text notification ──────────────────────────────────
    // Extract service description from invoice line items
    // Formats: "...Services Plan FTTH50 Duration ..." or "...Service Plan Starlink Residential : Period ..."
    $itemLabels = [];
    foreach ($inv['items'] ?? [] as $item) {
        $raw = trim($item['label'] ?? $item['name'] ?? $item['description'] ?? '');
        if (!$raw) continue;
        if (preg_match('/Services?\s+Plan\s+(.+?)\s*:?\s*(?:Duration|Period)\s+(.+)$/ui', $raw, $m)) {
            $itemLabels[] = trim($m[1]) . ' · ' . trim($m[2]);
        } elseif (preg_match('/Services?\s+Plan\s+(.+?)$/ui', $raw, $m)) {
            $itemLabels[] = trim($m[1]);
        } else {
            $itemLabels[] = $raw;
        }
    }
    $itemLabels = array_values(array_unique($itemLabels));
    $totalItems = count($itemLabels);
    if ($totalItems === 0) {
        $svcName = '';
    } elseif ($totalItems <= 3) {
        $svcName = implode(', ', $itemLabels);
    } else {
        $svcName = implode(', ', array_slice($itemLabels, 0, 2)) . ' + ' . ($totalItems - 2) . ' more';
    }

    $notify->invoiceCreated($phone, $fullName, $invoiceNum, $total, $dueDate ?: 'See invoice', $svcName);
    ilog("SENT: #{$invoiceNum} \${$total} → {$fullName} ({$phone})");

    // ── Send invoice PDF ─────────────────────────────────────────────────
    try {
        $pdfRaw = $crm->getRawContent("invoices/{$invoiceId}/pdf");
        if ($pdfRaw) {
            $tempDir = $dataDir . '/temp_pdf';
            if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

            // Clean old temp files (>10 min)
            foreach (glob($tempDir . '/*.pdf') as $_tf) {
                if (time() - filemtime($_tf) > 600) { @unlink($_tf); @unlink($_tf . '.meta'); }
            }

            $pdfFile  = "inv_{$invoiceId}_" . substr(md5(uniqid()), 0, 8) . '.pdf';
            $pdfPath  = $tempDir . '/' . $pdfFile;
            $pdfToken = hash_hmac('sha256', $pdfFile, ($config['webhook_secret'] ?? 'dishnet') . date('Ymd'));

            file_put_contents($pdfPath, base64_decode($pdfRaw));
            file_put_contents($pdfPath . '.meta', json_encode([
                'token'   => $pdfToken,
                'created' => time(),
                'invoice' => $invoiceNum,
            ]));

            $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
            $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                     . '?page=api&action=serve_temp_pdf'
                     . '&file=' . urlencode($pdfFile)
                     . '&token=' . urlencode($pdfToken);

            $notify->sendDocument(
                'accounts',
                $phone,
                $pdfUrl,
                "{$invoiceNum}.pdf",
                "Invoice #{$invoiceNum} — \${$total} — Due: {$dueDate}\n— DishNet Africa",
                'ops_invoice_pdf'
            );
            ilog("PDF sent: #{$invoiceNum}");
        }
    } catch (\Throwable $pdfErr) {
        ilog("PDF error for #{$invoiceNum}: " . $pdfErr->getMessage());
    }

    // dedup already marked atomically by dedupMark() above
    $sent++;
    usleep(500000); // 0.5s between messages
}

ilog("Done: sent={$sent}, skipped={$skipped}, errors={$errors}");

// ══════════════════════════════════════════════════════════════════════════════
// PASS 2: RENEWAL REMINDERS — 5 days before next billing period
// Runs max once per day. Hardened: explicit-date-only, safety cap,
// first-run dry-run, abort-on-anomaly. See SAFETY.md.
// ══════════════════════════════════════════════════════════════════════════════
$renewalLog     = $store->load('renewal_remind_log.json') ?: [];
$lastRenewalRun = $renewalLog['_last_run'] ?? '';
$today          = date('Y-m-d');

// Tunables (override via kyc_config.json if present)
$renewWindowMin   = (int)($config['renewal_window_min_days'] ?? 4);   // earliest days-out
$renewWindowMax   = (int)($config['renewal_window_max_days'] ?? 6);   // latest days-out
$renewMaxPerRun   = (int)($config['renewal_max_per_run']     ?? 40);  // absolute send cap per run
$renewMaxPctOfAll = (float)($config['renewal_max_pct_of_all'] ?? 0.25); // fraction-of-active brake

// ── Renewal reminders are DISABLED by default. ──────────────────────────────
// Set "renewal_reminders_enabled": true in kyc_config.json to turn them back on.
// While off, this whole pass is skipped and NO renewal WhatsApp is ever sent.
$renewalEnabled = !empty($config['renewal_reminders_enabled']);

if ($renewalEnabled && $lastRenewalRun !== $today) {
    $renewSent  = 0;
    $renewSkip  = 0;

    // Fetch active services (status 1 = active)
    $services = $crm->get('clients/services?statuses[]=1&limit=500') ?? [];
    if (empty($services)) {
        $services = $crm->get('clients/services?status=1&limit=500') ?? [];
    }
    $activeCount = count($services);

    ilog("Renewal check: {$activeCount} active services, window {$renewWindowMin}-{$renewWindowMax}d");

    // ── First-run protection: if we've never run before, DO NOT send.
    //    Just record what we *would* send and stamp the log. Forces a human
    //    to eyeball the first batch before any message goes out. ─────────────
    $isFirstRun = empty($lastRenewalRun);

    // ── PHASE 1: build the candidate list (no sending yet) ──────────────────
    $candidates = [];   // each: ['svc'=>, 'svcId'=>, 'clientId'=>, 'svcName'=>, 'price'=>, 'nextBilling'=>, 'rKey'=>]
    foreach ($services as $svc) {
        $svcId    = (int)($svc['id'] ?? 0);
        $clientId = (int)($svc['clientId'] ?? 0);
        $svcName  = $svc['name'] ?? $svc['servicePlanName'] ?? 'Internet Service';
        $price    = (float)($svc['price'] ?? $svc['totalPrice'] ?? 0);

        if (!$svcId || !$clientId || $price <= 0) continue;

        // ── Next billing date: TRUST EXPLICIT FIELDS ONLY ──────────────────
        // UCRM exposes the real next-billing date as nextInvoicingDay.
        // Do NOT fall back to activeTo (that is the contract END date, often
        // null or far-future) and do NOT estimate from a start date — a
        // guessed date is how a whole customer base gets reminded at once.
        // No reliable date  ->  skip this service silently.
        $nextBilling = '';
        if (!empty($svc['nextInvoicingDay'])) {
            $nextBilling = substr($svc['nextInvoicingDay'], 0, 10);
        }
        if (!$nextBilling || !strtotime($nextBilling)) { $renewSkip++; continue; }

        // Window check: only 4-6 days out (centered on ~5)
        $daysUntil = (int)floor((strtotime($nextBilling) - strtotime($today)) / 86400);
        if ($daysUntil < $renewWindowMin || $daysUntil > $renewWindowMax) continue;

        // Dedup keyed on the RENEWAL month (not today's month) so a renewal
        // straddling a month boundary still fires exactly once per cycle.
        $rKey = "REN{$svcId}_" . substr($nextBilling, 0, 7);
        if (isset($renewalLog[$rKey])) { $renewSkip++; continue; }

        $candidates[] = [
            'svcId'       => $svcId,
            'clientId'    => $clientId,
            'svcName'     => $svcName,
            'price'       => $price,
            'nextBilling' => $nextBilling,
            'rKey'        => $rKey,
        ];
    }

    $candCount = count($candidates);

    // ── PHASE 2: SAFETY BRAKE ───────────────────────────────────────────────
    // If an implausible share of customers matched, the date logic is almost
    // certainly broken (bad API field, clock skew). Abort the batch, alert,
    // and DO NOT stamp _last_run so a fixed run can retry today.
    $pctCap = $activeCount > 0 ? (int)ceil($activeCount * $renewMaxPctOfAll) : $renewMaxPerRun;
    $hardCap = min($renewMaxPerRun, max(1, $pctCap));

    if ($candCount > $hardCap) {
        ilog("RENEWAL ABORT: {$candCount} candidates exceed cap {$hardCap} ({$activeCount} active). "
           . "Date logic likely wrong — NOTHING SENT. Sample dates: "
           . implode(', ', array_map(function($c){ return $c['svcId'].'@'.$c['nextBilling']; }, array_slice($candidates,0,5))));
        try { $notify->sendAdmin(
            "⚠️ Renewal cron aborted: {$candCount} of {$activeCount} active services matched the 5-day window "
          . "(cap {$hardCap}). Likely a billing-date bug — no reminders sent. Check invoice_notify_cron.log.",
            'ops_renewal_abort'
        ); } catch (\Throwable $e) { /* non-fatal */ }
        // intentionally DO NOT set _last_run — allow corrected retry same day
        $store->save('renewal_remind_log.json', $renewalLog);
    } else {
        // ── PHASE 3: first run is a dry run ────────────────────────────────
        if ($isFirstRun) {
            foreach ($candidates as $c) {
                ilog("RENEWAL DRY-RUN (first run, NOT sent): {$c['svcName']} \${$c['price']} "
                   . "svc#{$c['svcId']} renews {$c['nextBilling']}");
            }
            ilog("Renewal first-run dry run: {$candCount} would-send. Stamping log; live sends begin next run.");
            $renewalLog['_last_run'] = $today;
            $store->save('renewal_remind_log.json', $renewalLog);
        } else {
            // ── PHASE 4: real send ─────────────────────────────────────────
            foreach ($candidates as $c) {
                if ($renewSent >= $hardCap) { ilog("Renewal cap {$hardCap} reached, stopping."); break; }

                $client = $crm->get("clients/{$c['clientId']}");
                if (!$client) { $renewSkip++; continue; }

                $custPhone = '';
                foreach (($client['contacts'] ?? []) as $ct) {
                    if (!empty($ct['phone'])) { $custPhone = $ct['phone']; break; }
                }
                if (!$custPhone) { $renewSkip++; continue; }

                $custName = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
                          ?: ($client['companyName'] ?? 'Customer');

                $notify->renewalReminder($custPhone, $custName, $c['svcName'], $c['price'], $c['nextBilling']);
                ilog("RENEWAL REMIND: {$c['svcName']} \${$c['price']} -> {$custName} ({$custPhone}) renews {$c['nextBilling']}");

                $renewalLog[$c['rKey']] = date('Y-m-d H:i:s');
                $renewSent++;
                usleep(500000);
            }
            $renewalLog['_last_run'] = $today;

            // Prune old entries (>90 days)
            $cutoff90 = date('Y-m', strtotime('-90 days'));
            foreach ($renewalLog as $k => $v) {
                if (is_string($k) && isset($k[0]) && $k[0] === 'R' && is_string($v) && substr($v, 0, 7) < $cutoff90) {
                    unset($renewalLog[$k]);
                }
            }
            $store->save('renewal_remind_log.json', $renewalLog);
            ilog("Renewal reminders: candidates={$candCount}, sent={$renewSent}, skipped={$renewSkip}, cap={$hardCap}");
        }
    }
}

flock($lockFp, LOCK_UN);
fclose($lockFp);
return;
