<?php
// ═══════════════════════════════════════════════════════════════════════════
// cron_overdue_email.php — Overdue Invoice Follow-up Email Chain
// Runs weekly (Mondays 09:00 Juba time via master.php)
//
// Full communication chain (all polite, escalating urgency):
//
//   Day 1    UCRM: "Invoice overdue" email          ← UCRM handles this
//   Day 7    WhatsApp reminder                      ← cron_invoice_notify
//   Day 14   Email #1 — Polite nudge
//   Day 31   Email #2 — Gentle follow-up
//   Day 45   WhatsApp mid-point reminder            ← this cron
//   Day 61   Email #3 — Firm but respectful
//   Day 75   WhatsApp urgent reminder               ← this cron
//   Day 90   Email #4 — Final notice
//   Day 120  Email #5 — Post-suspension, settle to restore
//   Day 180  Email #6 — Last formal contact
//
// Each email sent ONCE per invoice per stage (idempotent via overdue_email_log).
// WhatsApp touchpoints also deduped via notification_dedup table.
// ═══════════════════════════════════════════════════════════════════════════

declare(strict_types=1);
// v4.21.73: bumped from 300 to 1800 — Run Now via fastcgi_finish_request can
// run as long as PHP-FPM allows. 30 minutes is enough for ~500 invoices at
// 0.5s throttle. The tab handler also sets 1800; this is belt-and-suspenders
// in case the cron is invoked via master.php directly.
set_time_limit(1800);

// v4.21.67: pluginRoot is the directory of THIS file (cron is at plugin root,
// not in cron/). Was `dirname(__DIR__)` which evaluated to UCRM's plugins
// parent — wrong, but masked because the lib paths happened to look similar.
// Fixed to correctly resolve the plugin root.
$pluginRoot = __DIR__;
require_once $pluginRoot . '/lib/SqliteStore.php';
require_once $pluginRoot . '/lib/CrmApiClient.php';
require_once $pluginRoot . '/lib/NotificationService.php';
require_once $pluginRoot . '/lib/bootstrap_data.php';
// v4.21.67: dunning email/WA builders + SMTP helpers extracted into shared
// library so the workbench bulk-send endpoint can reuse them. Loaded BEFORE
// the main loop calls _buildSubject / _buildEmail / _sendEmail etc.
require_once $pluginRoot . '/lib/OverdueDunningHelpers.php';

// v4.21.69: $dataDir MUST come from getDataDir() — Rule 15 of SAFETY.md.
// The plugin data dir is at /data/ucrm/data/plugins/.../ (set in ucrm.json
// pluginDataDir), NOT at <plugin>/data/. Was the latter for a long time —
// every cron run was reading email_settings.json from an empty in-plugin
// directory and reporting "No SMTP configured" even when the actual config
// (the one Settings → SMTP Diagnostic shows green) lives in the persistent
// data dir. This is the root cause of the "0 sent / 0 failed" puzzle.
$dataDir = getDataDir($pluginRoot);
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?: [];
$pdo     = $store->getPdo();
$notify  = new NotificationService($store, $config);

// ── Excluded client IDs (internal staff, legal cases) ────────────────────────
$excludeClientIds = array_map('intval', array_filter(
    explode(',', $config['overdue_email_exclude_clients'] ?? ''),
    fn($v) => trim($v) !== ''
));
// Always exclude internal staff
$excludeClientIds = array_unique(array_merge($excludeClientIds, [888, 9]));

// ── Log helper ───────────────────────────────────────────────────────────────
// v4.21.73: olog() also appends to a live status file when $cronStatusFile is
// set by the caller (the Run Now handler). Lets the page poll the file every
// few seconds and show progress as the cron runs — instead of waiting for
// the whole batch to finish before any output appears.
$logLines = [];
$cronStatusFile = $dataDir . '/overdue_cron_status.json';
$cronRunId      = $GLOBALS['_overdue_cron_run_id'] ?? '';
$cronStartTs    = time();
function olog(string $msg): void {
    global $logLines, $cronStatusFile, $cronRunId, $cronStartTs;
    $line = '[' . date('H:i:s') . '] ' . $msg;
    $logLines[] = $line;
    echo $msg . "\n";

    // Best-effort live status update. Only flushes every ~2 seconds (or every
    // 10 lines) to avoid hammering the disk on a 500-row batch.
    static $lastFlush = 0;
    static $flushedLines = 0;
    $flushedLines++;
    $now = microtime(true);
    if ($flushedLines >= 10 || ($now - $lastFlush) > 2) {
        // Read live counters from the calling scope via GLOBALS.
        // The cron's main loop sets $sent / $errors / $skipped in its scope;
        // since they're declared at top-level (not inside a function), they
        // live in $GLOBALS.
        @file_put_contents($cronStatusFile, json_encode([
            'run_id'      => $cronRunId,
            'started_at'  => $cronStartTs,
            'finished_at' => null,
            'running'     => true,
            'output'      => implode("\n", $logLines) . "\n",
            'sent'        => (int)($GLOBALS['sent']    ?? 0),
            'errors'      => (int)($GLOBALS['errors']  ?? 0),
            'skipped'     => (int)($GLOBALS['skipped'] ?? 0),
            'updated_at'  => time(),
        ], JSON_PRETTY_PRINT));
        $lastFlush = $now;
        $flushedLines = 0;
    }
}

olog("=== Overdue Follow-up Cron — " . date('Y-m-d H:i:s') . " ===");

// ── Ensure table exists ──────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS overdue_email_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL, client_id INTEGER NOT NULL DEFAULT 0,
        client_name TEXT NOT NULL DEFAULT '', client_email TEXT NOT NULL DEFAULT '',
        stage INTEGER NOT NULL DEFAULT 1, stage_label TEXT NOT NULL DEFAULT '',
        amount_due REAL NOT NULL DEFAULT 0, days_overdue INTEGER NOT NULL DEFAULT 0,
        sent_at TEXT NOT NULL DEFAULT (datetime('now')), sent_by TEXT NOT NULL DEFAULT 'cron',
        success INTEGER NOT NULL DEFAULT 1, error TEXT
    )");
    // v4.21.66: Stage 9 (monthly_recurring) needs to be insertable multiple
    // times — once per ~30 days per invoice. Old unique index on
    // (invoice_number, stage) blocked this. We drop it (idempotent) and
    // replace with a PARTIAL unique that applies only to stages 1-8, plus
    // a non-unique stage-9 lookup index. Existing historical data is safe:
    // the old index was unique on the same columns so no duplicate rows
    // exist for any stage to migrate.
    try { $pdo->exec("DROP INDEX IF EXISTS idx_oel_inv_stage"); } catch (\Throwable $e) {}
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_oel_inv_stage_1to8
                ON overdue_email_log(invoice_number, stage)
                WHERE stage < 9");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_oel_stage9_sent
                ON overdue_email_log(invoice_number, stage, sent_at)
                WHERE stage = 9");
} catch (\Throwable $e) {}

// ── SMTP settings ────────────────────────────────────────────────────────────
olog("Resolving SMTP from dataDir: {$dataDir}");
$smtp = _getSmtpSettings($store, $config);
if (!$smtp) {
    olog("ERROR: No SMTP configured.");
    $efile = $dataDir . '/email_settings.json';
    olog("  Checked file: {$efile} (exists: " . (file_exists($efile) ? 'YES' : 'NO') . ')');
    if (file_exists($efile)) {
        $raw = (string)@file_get_contents($efile);
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $keys = implode(', ', array_keys($parsed));
            $hostVal = (string)($parsed['smtp_host'] ?? '');
            olog("  File parses OK. Keys: [{$keys}]");
            olog("  smtp_host value: '" . ($hostVal === '' ? '(empty/missing)' : $hostVal) . "'");
        } else {
            olog("  File exists but JSON parse failed. Raw size: " . strlen($raw) . ' bytes');
        }
    }
    olog("  Checked: kyc_config.smtp_host (value: '" . ($config['smtp_host'] ?? '') . "')");
    return;
}
olog("SMTP resolved: " . ($smtp['user'] ?? '?') . " via " . ($smtp['host'] ?? '?') . ":" . ($smtp['port'] ?? '?'));

// ── CRM API ──────────────────────────────────────────────────────────────────
// v4.21.67: was `CrmApiClient::fromStore($store)` which doesn't exist; the
// cron's been fataling on every run. Use the standard fromUcrm factory used
// everywhere else in the codebase.
$crm = CrmApiClient::fromUcrm($pluginRoot, $config);
if (!$crm || !$crm->isConfigured()) {
    olog("ERROR: CRM API not configured.");
    return;
}

// ── Fetch all unpaid/partial invoices ────────────────────────────────────────
// v4.21.71: Production UCRM returns 404 on the v4.21.66 'billing/invoices'
// path. Try several known variants (UCRM/UISP renamed endpoints across versions)
// and fall back to the local cache if all fail. Cache is refreshed by the daily
// crm sync cron and by every payment.add webhook (ClientInvoiceCacheRefresher).
olog("Fetching unpaid invoices from UCRM API...");
$endpointAttempts = [
    'invoices?statuses[]=1&statuses[]=2&limit=500',           // current UCRM/UISP (no /billing prefix)
    'billing/invoices?statuses[]=1&statuses[]=2&limit=500',   // older UCRM
    'invoices?status[]=1&status[]=2&limit=500',               // even older — singular 'status'
    'invoices?limit=500',                                     // unfiltered (filter locally)
];
$allInvoices = null;
$workingEndpoint = '';
foreach ($endpointAttempts as $ep) {
    $r = $crm->get($ep);
    $err = $crm->getLastError();
    if (is_array($r) && !empty($r)) {
        $allInvoices = $r;
        $workingEndpoint = $ep;
        olog("  Endpoint OK: {$ep} → " . count($r) . " rows");
        break;
    }
    olog("  Endpoint fail: {$ep} — " . (is_array($err) ? json_encode($err) : 'empty response'));
}

// Locally filter to unpaid statuses if we used the unfiltered endpoint
if ($workingEndpoint === 'invoices?limit=500' && is_array($allInvoices)) {
    $allInvoices = array_values(array_filter($allInvoices, function($i) {
        return in_array((int)($i['status'] ?? 0), [1, 2], true);
    }));
    olog("  Filtered locally to unpaid: " . count($allInvoices) . " rows");
}

// Last-resort fallback — use the cache the workbench reads from
if (empty($allInvoices)) {
    olog("  All API endpoints failed — falling back to ucrm_invoices_cache.json");
    $cache = $store->load('ucrm_invoices_cache.json') ?? [];
    if (is_array($cache) && !empty($cache)) {
        $allInvoices = array_values(array_filter($cache, function($i) {
            $s = (int)($i['status'] ?? $i['invoiceStatus'] ?? 0);
            return in_array($s, [1, 2, 3], true);
        }));
        $workingEndpoint = 'cache:ucrm_invoices_cache.json';
        olog("  Cache had " . count($cache) . " invoices, " . count($allInvoices) . " unpaid");
    }
}

if (!is_array($allInvoices) || empty($allInvoices)) {
    olog("ERROR: No invoices available from API or cache. Cron cannot proceed.");
    return;
}

$statusHist = [];
foreach ($allInvoices as $inv) {
    $s = (int)($inv['status'] ?? $inv['invoiceStatus'] ?? 0);
    $statusHist[$s] = ($statusHist[$s] ?? 0) + 1;
}
olog("Source: {$workingEndpoint} — " . count($allInvoices) . " invoices to consider.");
olog("  status histogram: " . json_encode($statusHist));

// ── Stage definitions ─────────────────────────────────────────────────────────
// type: 'email' | 'whatsapp' | 'both'
$stages = [
    // [min_days, max_days, stage_id, type, label]
    ['min'=>14,  'max'=>30,  'id'=>1, 'type'=>'email',     'label'=>'nudge'],
    ['min'=>31,  'max'=>44,  'id'=>2, 'type'=>'email',     'label'=>'followup'],
    ['min'=>45,  'max'=>60,  'id'=>3, 'type'=>'whatsapp',  'label'=>'wa_midpoint'],
    ['min'=>61,  'max'=>74,  'id'=>4, 'type'=>'email',     'label'=>'firm'],
    ['min'=>75,  'max'=>89,  'id'=>5, 'type'=>'whatsapp',  'label'=>'wa_urgent'],
    ['min'=>90,  'max'=>119, 'id'=>6, 'type'=>'email',     'label'=>'final'],
    ['min'=>120, 'max'=>179, 'id'=>7, 'type'=>'email',     'label'=>'suspended'],
    ['min'=>180, 'max'=>209, 'id'=>8, 'type'=>'email',     'label'=>'last_contact'],
    // v4.21.66: Stage 9 — perpetual monthly recurring follow-up after Day 210.
    // Previously the system went silent after Stage 8 (Day 180). This caused
    // the long tail of 200+/365+/year-old invoices to never be touched again.
    // Stage 9 recurs every 30 days using a special dedup check (see below).
    ['min'=>210, 'max'=>9999,'id'=>9, 'type'=>'both',      'label'=>'monthly_recurring'],
];

$today   = new \DateTime('now', new \DateTimeZone('Africa/Juba'));
$sent    = 0; $skipped = 0; $errors = 0;
// v4.21.74: collect a summary of who got messaged so admin gets ONE
// digest WhatsApp at the end of the run instead of N pings during it.
$adminSummary = [];

// v4.21.68: per-reason skip counters so the diagnostic output tells us
// exactly where invoices fall out of the funnel. Was a single blind $skipped
// counter that gave zero insight into why "0 sent" happens.
$skipReasons = [
    'missing_fields' => 0,    // no invoice_number / client_id / amount / due
    'excluded_client' => 0,   // staff/legal client IDs
    'bad_due_date' => 0,      // unparseable due date string
    'not_yet_overdue' => 0,   // due date in future or today
    'too_fresh' => 0,         // 1-13 days overdue (UCRM/Day-7 WA handles)
    'no_stage_match' => 0,    // shouldn't happen but counted for completeness
    'already_sent_stage' => 0,// idempotent skip (most common reason)
    'no_contact_info' => 0,   // client has neither phone nor email
    'wa_dedup' => 0,          // WA-only stage, deduped by NotificationService
];

foreach ($allInvoices as $inv) {
    $invNum   = $inv['number'] ?? '';
    $invId    = (int)($inv['id'] ?? 0);
    $clientId = (int)($inv['clientId'] ?? 0);
    $amtDue   = (float)($inv['amountToPay'] ?? $inv['total'] ?? 0);
    $dueStr   = $inv['dueDate'] ?? '';

    if (!$invNum || !$clientId || $amtDue <= 0 || !$dueStr) { $skipped++; $skipReasons['missing_fields']++; continue; }
    if (in_array($clientId, $excludeClientIds, true)) { $skipped++; $skipReasons['excluded_client']++; continue; }

    // Days overdue
    try { $dueDate = new \DateTime($dueStr, new \DateTimeZone('Africa/Juba')); }
    catch (\Throwable $e) { $skipped++; $skipReasons['bad_due_date']++; continue; }
    if ($today <= $dueDate) { $skipped++; $skipReasons['not_yet_overdue']++; continue; }
    $daysOverdue = (int)$today->diff($dueDate)->days;

    // Find which stage applies
    $matchedStage = null;
    foreach ($stages as $s) {
        if ($daysOverdue >= $s['min'] && $daysOverdue <= $s['max']) {
            $matchedStage = $s; break;
        }
    }
    if (!$matchedStage) {
        // Days 1-13: UCRM sends Day-1 email + cron_invoice_notify Day-7 WA
        $skipped++;
        if ($daysOverdue < 14) $skipReasons['too_fresh']++;
        else $skipReasons['no_stage_match']++;
        continue;
    }

    // Already sent this stage?
    // v4.21.66: Stage 9 (monthly_recurring) uses a 30-day window instead of
    // a once-ever check. Lets us touch year-old invoices once per month.
    if ((int)$matchedStage['id'] === 9) {
        $chk = $pdo->prepare(
            "SELECT 1 FROM overdue_email_log
             WHERE invoice_number=? AND stage=9 AND success=1
               AND sent_at > datetime('now','-30 days')"
        );
        $chk->execute([$invNum]);
    } else {
        $chk = $pdo->prepare("SELECT 1 FROM overdue_email_log WHERE invoice_number=? AND stage=? AND success=1");
        $chk->execute([$invNum, $matchedStage['id']]);
    }
    if ($chk->fetchColumn()) { $skipped++; $skipReasons['already_sent_stage']++; continue; }

    // Fetch client
    $client = $crm->get("clients/{$clientId}");
    if (!$client) { $skipped++; $skipReasons['no_contact_info']++; continue; }

    $firstName = trim($client['firstName'] ?? '');
    $lastName  = trim($client['lastName']  ?? '');
    $fullName  = trim("{$firstName} {$lastName}") ?: ($client['companyName'] ?? 'Valued Customer');
    $firstName = $firstName ?: $fullName;

    $email = '';
    $phone = '';
    foreach (($client['contacts'] ?? []) as $c) {
        if (!$email && !empty($c['email'])) $email = $c['email'];
        if (!$phone && !empty($c['phone'])) $phone = $c['phone'];
    }

    // Fresh invoice status check — try both paths, skip the check on 404
    // rather than treating it as "still unpaid" (would cause double-sends to
    // already-paid customers if API endpoint shape doesn't match).
    $freshInv = $crm->get("invoices/{$invId}");
    if (!$freshInv) $freshInv = $crm->get("billing/invoices/{$invId}");
    if ($freshInv && !in_array((int)($freshInv['status'] ?? 0), [1,2], true)) {
        olog("SKIP {$invNum} — just paid"); $skipped++; continue;
    }

    $amtFmt     = '$' . number_format($amtDue, 2);
    $dueFmt     = $dueDate->format('d M Y');
    $invoiceUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/') . "/crm/client/{$clientId}";
    $payUrl     = $freshInv['proformaInvoiceLink'] ?? $invoiceUrl;
    $ok         = false;
    $error      = '';

    // ── Send based on type ────────────────────────────────────────────────────
    if (in_array($matchedStage['type'], ['email', 'both']) && $email) {
        // v4.21.72: try/catch covers _sendEmail so a single failure cannot
        // abort the batch. v4.21.74: per-invoice admin alert removed — they
        // accumulate into $adminSummary[] and fire once at end of run instead
        // of N admin WhatsApps for an N-invoice batch.
        try {
            $html    = _buildEmail($matchedStage['id'], $firstName, $fullName, $invNum, $amtFmt, $dueFmt, $daysOverdue, $invoiceUrl, $payUrl, $config);
            $subject = _buildSubject($matchedStage['id'], $invNum, $amtFmt, $daysOverdue, $config);
            $ok      = _sendEmail($smtp, $email, $subject, $html, $error);

            if ($ok) {
                olog("EMAIL stage {$matchedStage['id']} ({$matchedStage['label']}) → {$fullName} <{$email}> — {$invNum} {$amtFmt} ({$daysOverdue}d)");
                $sent++;
                // Track for end-of-run summary (admin gets one digest, not 100 pings)
                $adminSummary[] = [
                    'client' => $fullName,
                    'invoice' => $invNum,
                    'amount' => $amtFmt,
                    'days' => $daysOverdue,
                    'stage' => (int)$matchedStage['id'],
                    'channels' => ['email'],
                ];
            } else {
                olog("EMAIL ERROR stage {$matchedStage['id']} → {$email}: {$error}");
                $errors++;
            }
        } catch (\Throwable $e) {
            olog("EMAIL EXCEPTION stage {$matchedStage['id']} → {$email}: " . $e->getMessage());
            $errors++;
        }
    }

    if (in_array($matchedStage['type'], ['whatsapp', 'both']) && $phone) {
        $waMsg = _buildWhatsApp($matchedStage['id'], $firstName, $invNum, $amtFmt, $dueFmt, $daysOverdue, $invoiceUrl);
        // v4.21.66: Stage 9 dedup key includes year-month so it can fire
        // monthly. Stages 1-8 are still once-ever per invoice.
        $dedupKey = (int)$matchedStage['id'] === 9
            ? "OVDUE-WA-{$invNum}-s9-" . date('Y-m')
            : "OVDUE-WA-{$invNum}-s{$matchedStage['id']}";
        if ($notify->dedupMark($dedupKey)) {
            // v4.21.72: try/catch — a single WhatsApp send failure (network
            // timeout, json_decode crash, Evolution API hiccup) used to kill
            // the entire batch via uncaught exception. Now logs and moves on.
            try {
                $notify->send('overdue_followup', $phone, $fullName, [
                    'customer_name' => $firstName,
                    'invoice_number'=> $invNum,
                    'amount'        => $amtFmt,
                    'due_date'      => $dueFmt,
                    'days_overdue'  => $daysOverdue,
                    'invoice_url'   => $invoiceUrl,
                    '_raw_message'  => $waMsg,
                ]);
                olog("WHATSAPP stage {$matchedStage['id']} ({$matchedStage['label']}) → {$fullName} {$phone} — {$invNum} {$amtFmt}");
                $ok = true; $sent++;
                // Track for end-of-run summary. If this invoice already has
                // an entry from the email branch above (stage type='both'),
                // merge channels into that entry instead of duplicating.
                $merged = false;
                foreach ($adminSummary as &$_e) {
                    if ($_e['invoice'] === $invNum) {
                        $_e['channels'][] = 'whatsapp';
                        $merged = true;
                        break;
                    }
                }
                unset($_e);
                if (!$merged) {
                    $adminSummary[] = [
                        'client' => $fullName,
                        'invoice' => $invNum,
                        'amount' => $amtFmt,
                        'days' => $daysOverdue,
                        'stage' => (int)$matchedStage['id'],
                        'channels' => ['whatsapp'],
                    ];
                }
            } catch (\Throwable $e) {
                olog("WHATSAPP ERROR stage {$matchedStage['id']} → {$phone}: " . $e->getMessage());
                $errors++;
            }
        } else {
            $skipped++; $skipReasons['wa_dedup']++;
        }
    }

    // Log to overdue_email_log
    try {
        $pdo->prepare(
            "INSERT OR IGNORE INTO overdue_email_log
             (invoice_number,client_id,client_name,client_email,stage,stage_label,
              amount_due,days_overdue,sent_by,success,error)
             VALUES (?,?,?,?,?,?,?,?,'cron',?,?)"
        )->execute([
            $invNum, $clientId, $fullName, $email ?: $phone,
            $matchedStage['id'], $matchedStage['label'],
            $amtDue, $daysOverdue, $ok ? 1 : 0, $ok ? null : $error,
        ]);
    } catch (\Throwable $e) {}

    usleep(500000);
}

olog("Done — sent:{$sent} skipped:{$skipped} errors:{$errors}");
olog("--- Skip-reason breakdown (v4.21.68 diagnostic) ---");
foreach ($skipReasons as $reason => $count) {
    if ($count > 0) {
        olog(sprintf("  %-22s %d", $reason, $count));
    }
}
$totalCounted = array_sum($skipReasons);
if ($totalCounted !== $skipped) {
    olog("  WARN: counter drift — \$skipped={$skipped} vs sum of reasons={$totalCounted}");
}

// ── Admin summary digest ─────────────────────────────────────────────────────
// v4.21.74: ONE WhatsApp to admin at end of run, instead of per-invoice pings.
// Lists the unique invoices that got messaged this run so admin sees the
// outreach scope without phone notification spam during business hours.
if (!empty($adminSummary)) {
    try {
        // Group by client for compactness (one client may have multiple invoices)
        $totalAmt = 0;
        $byClient = [];
        foreach ($adminSummary as $row) {
            $key = $row['client'];
            if (!isset($byClient[$key])) {
                $byClient[$key] = ['invoices' => [], 'total' => 0];
            }
            $byClient[$key]['invoices'][] = $row;
            // Parse "$1,234.00" back to float for total
            $amt = (float)preg_replace('/[^0-9.]/', '', $row['amount']);
            $byClient[$key]['total'] += $amt;
            $totalAmt += $amt;
        }

        $msg  = "📨 *Overdue dunning run complete*\n";
        $msg .= "_" . date('D, d M Y H:i') . "_\n\n";
        $msg .= "*Summary:* sent {$sent}, errors {$errors}, skipped {$skipped}\n";
        $msg .= "*Total chased:* $" . number_format($totalAmt, 2) . "\n";
        $msg .= "*Clients contacted:* " . count($byClient) . "\n\n";

        // Top 20 clients by amount, most consequential first
        uasort($byClient, function($a, $b) {
            return $b['total'] <=> $a['total'];
        });
        $msg .= "*Who got messaged:*\n";
        $shown = 0;
        foreach ($byClient as $clientName => $bucket) {
            if ($shown >= 20) break;
            $invSummary = [];
            foreach ($bucket['invoices'] as $r) {
                $chans = [];
                if (in_array('email', $r['channels'])) $chans[] = '📧';
                if (in_array('whatsapp', $r['channels'])) $chans[] = '💬';
                $invSummary[] = "{$r['invoice']} (s{$r['stage']}, {$r['days']}d) " . implode('', $chans);
            }
            // Truncate very long client names
            $cn = mb_strlen($clientName) > 35 ? mb_substr($clientName, 0, 32) . '...' : $clientName;
            $msg .= "• *{$cn}* — $" . number_format($bucket['total'], 2) . "\n";
            $msg .= "   " . implode(' · ', $invSummary) . "\n";
            $shown++;
        }
        if (count($byClient) > 20) {
            $msg .= "\n_…and " . (count($byClient) - 20) . " more clients (full list in dashboard → Overdue Emails)_";
        }

        $notify->sendAdmin($msg, 'overdue_run_summary_' . date('Y-m-d-Hi'));
        olog("Admin digest sent (" . count($byClient) . " clients, " . count($adminSummary) . " sends).");
    } catch (\Throwable $e) {
        olog("Admin digest send failed (non-fatal): " . $e->getMessage());
    }
} else {
    olog("No admin digest — nothing was sent this run.");
}

// v4.21.67: All builders (_buildSubject, _buildEmail, _buildWhatsApp,
// _oel_replace, _sendEmail, _rawSmtp, _getSmtpSettings) live in
// lib/OverdueDunningHelpers.php — required at the top of this file so
// they are loaded before the main loop runs.
