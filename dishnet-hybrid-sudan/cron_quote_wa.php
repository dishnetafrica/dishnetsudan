<?php
/**
 * cron_quote_wa.php — DishNet Hybrid Telecom v4.9.4
 *
 * Sends WhatsApp quotations with PDF for:
 *   A) KYC-generated quotes — wa_quote_pending=true in kyc_applications.json
 *      Waits 3 min after creation so UCRM has time to generate the PDF.
 *      Phone stored in wa_quote_phone field — no extra UCRM API call needed.
 *
 *   B) Manual UCRM quotes — created directly in UCRM by staff
 *      Polls UCRM for open quotes not yet sent via WA.
 *
 * For every quote:
 *   1. Fetch PDF from UCRM  billing/quotes/{id}/pdf
 *   2. Save to data/quote_pdfs/ (permanent — not the 10-min temp_pdf)
 *   3. Send WhatsApp text message (proforma summary)
 *   4. Send WhatsApp PDF document via serve_quote_pdf endpoint
 *
 * Runs every 5 minutes via cron/master.php.
 * PHP 7.4 compatible.
 */

declare(strict_types=1);

if (!function_exists('str_contains'))   { function str_contains(string $h, string $n): bool  { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/bootstrap_data.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/QuotationService.php';

$pluginRoot = __DIR__;
$dataDir    = getDataDir($pluginRoot);
$store      = SqliteStore::create($dataDir);
$config     = $store->load('kyc_config.json') ?? [];

if (($config['quote_wa_cron_enabled'] ?? true) === false) {
    qwa_log('Disabled via config — skipping.');
    return;
}

$crm = CrmApiClient::fromUcrm($pluginRoot, $config);
if (!$crm->isConfigured()) {
    qwa_log('CRM not configured — skipping.');
    return;
}

// Use admin token for quote operations if available (quotes may need higher privileges)
$_adminToken = trim($config['crm_auth_token'] ?? '');
$quoteCrm = $_adminToken
    ? new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $_adminToken, 'x-auth-token')
    : $crm;

$notify  = new NotificationService($store, $config);
$quotSvc = new QuotationService($store, $dataDir, $config);

// ── Ensure quote_pdfs directory exists ─────────────────────────────────────
$pdfDir = $dataDir . '/quote_pdfs';
if (!is_dir($pdfDir)) @mkdir($pdfDir, 0755, true);

// ── Load state ────────────────────────────────────────────────────────────────
$state   = $store->load('quote_wa_state.json') ?? [];
$sentIds = array_map('intval', $state['sent_ids'] ?? []);

// v4.11.3: SQLite-backed dedup table — atomic guard against double-send
// INSERT OR IGNORE means only the first send wins; duplicates are rejected atomically.
$_qwaPdo = $store->getPdo();
try {
    $_qwaPdo->exec("CREATE TABLE IF NOT EXISTS wa_sent_quotes (
        quote_id   INTEGER NOT NULL,
        quote_ref  TEXT    NOT NULL DEFAULT '',
        source     TEXT    NOT NULL DEFAULT '',
        sent_at    TEXT    NOT NULL DEFAULT (datetime('now')),
        PRIMARY KEY (quote_id)
    )");
} catch (\Throwable $e) {}

$sent    = 0;
$skipped = 0;
$failed  = 0;

// ══════════════════════════════════════════════════════════════════════════════
// FLOW A: KYC-pending quotes (wa_quote_pending=true in kyc_applications.json)
// These have phone + quote_id already stored — no extra UCRM lookups needed.
// Wait 3 minutes after creation so UCRM has generated the PDF.
// ══════════════════════════════════════════════════════════════════════════════
$allApps = $store->load('kyc_applications.json') ?? [];
$apps = array_values(array_filter($allApps, function($a) {
    return !empty($a['wa_quote_pending']) && empty($a['wa_quote_sent']);
}));
qwa_log('FLOW A: Found ' . count($apps) . ' pending quote(s) from ' . count($allApps) . ' total apps');

foreach ($apps as $app) {
    $quoteId  = (int)($app['quote_id']  ?? 0);
    $appId    = (int)($app['id']        ?? 0);
    $quoteRef = $app['quote_ref']        ?? ('Q-' . $quoteId);

    if (!$quoteId || !$appId) continue;

    // Wait 3 minutes for UCRM to generate PDF
    $deferredAt = $app['wa_quote_deferred_at'] ?? $app['created_at'] ?? null;
    if ($deferredAt && (time() - strtotime($deferredAt)) < 180) {
        qwa_log("WAIT #{$quoteId} {$quoteRef} — only " . round((time()-strtotime($deferredAt))/60,1) . " min old, waiting 3 min");
        continue;
    }

    // Get phone — stored by KycService or resolve from application
    $phone = preg_replace('/[^0-9+]/', '', $app['wa_quote_phone'] ?? $app['mobile'] ?? '');
    if (!$phone) {
        // Last resort — try UCRM
        $phone = _qwa_resolvePhone((int)($app['crm_client_id'] ?? 0), $crm);
    }
    if (!$phone) {
        qwa_log("SKIP KYC app #{$appId} quote #{$quoteId} — no phone");
        $skipped++;
        continue;
    }

    // Fetch quote details from UCRM — try multiple API paths
    $quote = $quoteCrm->get("billing/quotes/{$quoteId}");
    if (!$quote) {
        // Try alternate path without billing/ prefix
        $quote = $quoteCrm->get("quotes/{$quoteId}");
    }
    if (!$quote) {
        // Try with plugin CRM client instead of admin token
        $quote = $crm->get("billing/quotes/{$quoteId}");
    }
    if (!$quote) {
        $lastErr = $quoteCrm->getLastError();
        qwa_log("SKIP KYC app #{$appId} quote #{$quoteId} — UCRM fetch failed. Error: " . json_encode($lastErr) . " | Base URL: " . $quoteCrm->getBaseUrl());
        $skipped++;
        continue;
    }

    // Auto-approve draft quotes so UCRM generates the PDF
    // (Webhook used to do this, but KYC quotes are now deferred to cron exclusively)
    $quoteStatus = (int)($quote['status'] ?? 0);
    if ($quoteStatus === 0) {
        try {
            $quoteCrm->patch("billing/quotes/{$quoteId}", ['status' => 1]);
            qwa_log("Auto-approved draft #{$quoteRef} → Open");
            // Give UCRM a moment to generate PDF after status change
            sleep(3);
        } catch (\Throwable $apErr) {
            qwa_log("Auto-approve note #{$quoteRef}: " . $apErr->getMessage());
        }
    }

    $customerName = trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? '')) ?: 'Valued Customer';

    // Build items
    $items = _qwa_buildItems($quote, $quotSvc, $app);
    $total = array_sum(array_map(fn($i) => (float)$i['price'] * max(1,(int)$i['quantity']), $items));

    // Fetch + save PDF
    $pdfUrl = _qwa_fetchAndStorePdf($quoteCrm, $quoteId, $quoteRef, $pdfDir, $config, $dataDir);

    // Send text message
    // Look up agent phone from retailers
    $_agentPhone = '';
    $_agentName  = $app['retailer_name'] ?? '';
    if ($_agentName) {
        $_allRetailers = $store->load('retailers.json') ?? [];
        $_agentLower = strtolower(trim($_agentName));
        foreach ($_allRetailers as $_r) {
            if (strtolower($_r['name'] ?? '') === $_agentLower) { $_agentPhone = $_r['phone'] ?? ''; break; }
        }
    }
    $msg = $quotSvc->buildProformaMessage($quoteRef, $customerName, $items, $total, [
        'type'        => 'Quotation',
        'via_crm'     => true,
        'agent'       => $_agentName,
        'agent_phone' => $_agentPhone,
    ]);

    // v4.11.3: Atomic SQLite dedup -- INSERT OR IGNORE prevents double-send
    // If another cron run already sent this quote, rowCount=0 and we skip.
    $_qwaInsert = $_qwaPdo->prepare(
        "INSERT OR IGNORE INTO wa_sent_quotes (quote_id, quote_ref, source) VALUES (?, ?, ?)"
    );
    $_qwaInsert->execute([$quoteId, $quoteRef, 'flow_a_kyc']);
    if ($_qwaInsert->rowCount() === 0) {
        qwa_log("DEDUP BLOCK Flow A: quote #" . $quoteId . " " . $quoteRef . " already in wa_sent_quotes -- skipping");
        $skipped++;
        $store->updateOne('kyc_applications.json', 'id', $appId, [
            'wa_quote_pending' => false,
            'wa_quote_sent'    => true,
            'wa_quote_sent_at' => date('Y-m-d H:i:s'),
            'wa_quote_sent_by' => 'dedup_block',
        ]);
        continue;
    }

    $waSent = false;
    try {
        $notify->sendVia(NotificationService::SUPPORT, $phone, $msg, 'ops_quote_wa');
        $waSent = true;
        qwa_log("TEXT SENT KYC app #{$appId} quote #{$quoteId} {$quoteRef} → {$phone}");
    } catch (\Throwable $e) {
        qwa_log("TEXT FAILED KYC app #{$appId} quote #{$quoteId}: " . $e->getMessage());
        $failed++;
    }

    // Send PDF document
    if ($waSent && $pdfUrl) {
        try {
            $notify->sendDocument(
                NotificationService::SUPPORT,
                $phone,
                $pdfUrl,
                "Quote-{$quoteRef}.pdf",
                "Quote #{$quoteRef} — \${$total}\n— DishNet Africa",
                'ops_quote_pdf'
            );
            qwa_log("PDF SENT KYC app #{$appId} quote #{$quoteId} {$quoteRef} → {$phone}");
        } catch (\Throwable $e) {
            qwa_log("PDF FAILED KYC app #{$appId} quote #{$quoteId}: " . $e->getMessage());
        }
    } elseif ($waSent && !$pdfUrl) {
        qwa_log("PDF UNAVAILABLE for quote #{$quoteId} — text-only sent");
    }

    // Mark done
    if ($waSent) {
        $store->updateOne('kyc_applications.json', 'id', $appId, [
            'wa_quote_pending' => false,
            'wa_quote_sent'    => true,
            'wa_quote_sent_at' => date('Y-m-d H:i:s'),
            'wa_quote_sent_by' => 'cron_quote_wa',
        ]);
        $sentIds[] = $quoteId;
        $sent++;
        // v4.11.3: Write to quotes_log so _qwa_isPluginQuote() detects it cross-run
        $store->appendWithId('quotes_log.json', [
            'type'           => 'kyc_flow_a',
            'quote_ref'      => $quoteRef,
            'crm_client_id'  => (int)($app['crm_client_id'] ?? 0),
            'crm_quote_id'   => $quoteId,
            'kyc_app_id'     => $appId,
            'customer_name'  => $customerName,
            'customer_phone' => $phone,
            'sent_via_wa'    => true,
            'sent_by'        => 'cron_quote_wa_flow_a',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// FLOW B: Manual UCRM quotes (not created through plugin KYC flow)
// Poll UCRM for open quotes not yet in sentIds.
// ══════════════════════════════════════════════════════════════════════════════
$lastChecked = $state['last_checked'] ?? date('Y-m-d', strtotime('-1 day'));
$qs     = http_build_query(['createdDateFrom' => $lastChecked]);
$quotes = $quoteCrm->get("billing/quotes?{$qs}") ?: [];
if (empty($quotes)) $quotes = $quoteCrm->get('billing/quotes') ?: [];

qwa_log('UCRM quotes fetched: ' . count($quotes) . ' since ' . $lastChecked);

foreach ($quotes as $q) {
    $qId     = (int)($q['id']     ?? 0);
    $qNumber = $q['number']       ?? ('Q-' . $qId);
    $status  = (int)($q['status'] ?? 0); // 0=draft 1=open 2=approved 3=rejected 4=void

    if (!$qId) continue;
    if ($status === 3 || $status === 4) { $skipped++; continue; } // skip rejected + void only
    if (in_array($qId, $sentIds, true)) { $skipped++; continue; }

    // Skip quotes from plugin KYC flow — handled in Flow A above
    if (_qwa_isPluginQuote($qId, $store)) {
        $sentIds[] = $qId;
        $skipped++;
        continue;
    }

    $clientId = (int)($q['clientId'] ?? 0);
    if (!$clientId) { $sentIds[] = $qId; $skipped++; continue; }

    $phone = _qwa_resolvePhone($clientId, $crm);
    if (!$phone) {
        qwa_log("SKIP UCRM quote #{$qId} {$qNumber} — no phone for client #{$clientId}");
        $sentIds[] = $qId;
        $skipped++;
        continue;
    }

    $clientName = _qwa_resolveName($clientId, $q, $crm);
    $items      = _qwa_buildItems($q, $quotSvc, []);
    $total      = array_sum(array_map(fn($i) => (float)$i['price'] * max(1,(int)$i['quantity']), $items));

    if (empty($items)) {
        qwa_log("SKIP UCRM quote #{$qId} {$qNumber} — no items");
        $sentIds[] = $qId;
        $skipped++;
        continue;
    }

    // Auto-approve draft quotes so UCRM generates PDF
    if ($status === 0) {
        try {
            $quoteCrm->patch("billing/quotes/{$qId}", ['status' => 1]);
            qwa_log("Auto-approved draft #{$qNumber} → Open");
            sleep(3);
        } catch (\Throwable $apErr) {
            qwa_log("Auto-approve note #{$qNumber}: " . $apErr->getMessage());
        }
    }

    $pdfUrl = _qwa_fetchAndStorePdf($quoteCrm, $qId, $qNumber, $pdfDir, $config, $dataDir);

    $msg = $quotSvc->buildProformaMessage($qNumber, $clientName, $items, $total, [
        'type'    => 'Quotation',
        'via_crm' => true,
        'agent'   => is_string($q['createdBy'] ?? null) ? $q['createdBy'] : '',
    ]);

    // v4.11.3: Atomic SQLite dedup for Flow B (manual UCRM quotes)
    $_qwaInsB = $_qwaPdo->prepare(
        "INSERT OR IGNORE INTO wa_sent_quotes (quote_id, quote_ref, source) VALUES (?, ?, ?)"
    );
    $_qwaInsB->execute([$qId, $qNumber, 'flow_b_ucrm']);
    if ($_qwaInsB->rowCount() === 0) {
        qwa_log("DEDUP BLOCK Flow B: quote #" . $qId . " " . $qNumber . " already in wa_sent_quotes -- skipping");
        $sentIds[] = $qId;
        $skipped++;
        continue;
    }

    $waSent = false;
    try {
        $notify->sendVia(NotificationService::SUPPORT, $phone, $msg, 'ops_quote_wa');
        $waSent = true;
        qwa_log("TEXT SENT UCRM quote #{$qId} {$qNumber} → {$phone} ({$clientName})");
    } catch (\Throwable $e) {
        qwa_log("TEXT FAILED UCRM quote #{$qId}: " . $e->getMessage());
        $failed++;
    }

    if ($waSent && $pdfUrl) {
        try {
            $notify->sendDocument(NotificationService::SUPPORT, $phone, $pdfUrl,
                "Quote-{$qNumber}.pdf",
                "Quote #{$qNumber} — \${$total}\n— DishNet Africa",
                'ops_quote_pdf');
            qwa_log("PDF SENT UCRM quote #{$qId} {$qNumber}");
        } catch (\Throwable $e) {
            qwa_log("PDF FAILED UCRM quote #{$qId}: " . $e->getMessage());
        }
    }

    if ($waSent) {
        $sentIds[] = $qId;
        $sent++;
        // Log to quotes_log
        $store->appendWithId('quotes_log.json', [
            'type'           => 'ucrm_manual',
            'quote_ref'      => $qNumber,
            'crm_client_id'  => $clientId,
            'crm_quote_id'   => $qId,
            'customer_name'  => $clientName,
            'customer_phone' => $phone,
            'items'          => $items,
            'total'          => $total,
            'sent_via_wa'    => true,
            'sent_via_crm'   => true,
            'has_pdf'        => !empty($pdfUrl),
            'sent_by'        => 'cron_quote_wa',
            'currency'       => 'USD',
            'valid_until'    => date('Y-m-d', strtotime('+7 days')),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}

// ── Process PDF pending queue (from webhook auto-approve flow) ────────────────
$qwaStateRaw = $store->load('quote_wa_state.json') ?? [];
$pdfPending  = $qwaStateRaw['pdf_pending'] ?? [];
$pdfDone     = [];

if (!empty($pdfPending)) {
    qwa_log("PDF pending queue: " . count($pdfPending) . " quote(s) waiting");
    $pdfDir = $dataDir . '/quote_pdfs';
    if (!is_dir($pdfDir)) mkdir($pdfDir, 0755, true);

    foreach ($pdfPending as $idx => $pq) {
        $pqId    = (int)($pq['quote_id'] ?? 0);
        $pqNum   = $pq['quote_num'] ?? "Q-{$pqId}";
        $pqPhone = $pq['phone'] ?? '';
        $pqName  = $pq['name'] ?? '';
        $pqAmt   = $pq['amount'] ?? '0.00';
        $pqTime  = (int)($pq['queued_at'] ?? 0);

        if (!$pqId || !$pqPhone) { $pdfDone[] = $idx; continue; }

        // Skip if queued more than 1 hour ago (stale)
        if ($pqTime > 0 && (time() - $pqTime) > 3600) {
            qwa_log("PDF pending #{$pqNum} — stale (queued " . round((time()-$pqTime)/60) . "m ago), removing");
            $pdfDone[] = $idx;
            continue;
        }

        // Wait at least 30s after webhook queued it (UCRM needs time to generate PDF after approve)
        if ($pqTime > 0 && (time() - $pqTime) < 30) {
            qwa_log("PDF pending #{$pqNum} — too fresh (" . (time()-$pqTime) . "s), will retry next run");
            continue;
        }

        $pdfUrl = _qwa_fetchAndStorePdf($quoteCrm, $pqId, $pqNum, $pdfDir, $config, $dataDir);
        if ($pdfUrl) {
            $notify->sendDocument(
                NotificationService::SUPPORT,
                $pqPhone,
                $pdfUrl,
                "Quote-{$pqNum}.pdf",
                "Quote #{$pqNum} — \${$pqAmt}\n— DishNet Africa",
                'ops_quote_pdf'
            );
            qwa_log("PDF SENT from pending queue: #{$pqNum} → {$pqName} ({$pqPhone})");
            $pdfDone[] = $idx;
            // Also add to sentIds so we don't double-process
            if (!in_array($pqId, $sentIds, true)) {
                $sentIds[] = $pqId;
            }
        } else {
            qwa_log("PDF pending #{$pqNum} — PDF still empty, will retry next cron run");
        }
    }

    // Remove completed items from pending queue
    foreach ($pdfDone as $dIdx) {
        unset($pdfPending[$dIdx]);
    }
    $pdfPending = array_values($pdfPending);
}

// ══════════════════════════════════════════════════════════════════════════════
// FLOW C: Payment Receipt PDFs (queued by webhook.php v4.10.4+)
// Webhook sends text receipt immediately, queues PDF here for reliable delivery.
// Uses same getRawContent() pattern as quote PDFs (proven working).
// ══════════════════════════════════════════════════════════════════════════════
$receiptQueue   = $store->load('receipt_pdf_queue.json') ?? [];
$receiptDirty   = false;
$receiptPdfDir  = $dataDir . '/receipt_pdfs';
if (!is_dir($receiptPdfDir)) @mkdir($receiptPdfDir, 0755, true);

$receiptPending = array_filter($receiptQueue, function($r) { return empty($r['sent']); });
if (!empty($receiptPending)) {
    qwa_log("Receipt PDF queue: " . count($receiptPending) . " payment(s) pending");
}

// v4.11.3: Track sent payment IDs to prevent duplicate sends within same run
$_receiptSentIds = [];
foreach ($receiptQueue as $_rsi) {
    if (!empty($_rsi['sent']) && !empty($_rsi['payment_id'])) {
        $_receiptSentIds[(int)$_rsi['payment_id']] = true;
    }
}

foreach ($receiptQueue as $idx => &$rq) {
    if (!empty($rq['sent'])) continue;

    $rPayId  = (int)($rq['payment_id'] ?? 0);
    $rPhone  = $rq['phone'] ?? '';
    $rName   = $rq['customer_name'] ?? 'Customer';
    $rAmount = (float)($rq['amount'] ?? 0);
    $rTime   = (int)($rq['queued_at'] ?? 0);

    if (!$rPayId || !$rPhone) {
        $rq['sent'] = true; $rq['skip_reason'] = 'missing_data';
        $receiptDirty = true;
        continue;
    }

    // v4.11.3: Skip if this payment_id was already sent (duplicate in queue)
    if (isset($_receiptSentIds[$rPayId])) {
        qwa_log("Receipt PDF PAY-{$rPayId} — DEDUP SKIP, already sent");
        $rq['sent'] = true; $rq['skip_reason'] = 'duplicate';
        $receiptDirty = true;
        continue;
    }

    // Skip if older than 24 hours (stale)
    if ($rTime > 0 && (time() - $rTime) > 86400) {
        qwa_log("Receipt PDF PAY-{$rPayId} — stale (" . round((time()-$rTime)/3600) . "h old), removing");
        $rq['sent'] = true; $rq['skip_reason'] = 'stale';
        $receiptDirty = true;
        continue;
    }

    // Wait at least 60s after webhook queued it (UCRM needs time to generate PDF)
    if ($rTime > 0 && (time() - $rTime) < 60) {
        qwa_log("Receipt PDF PAY-{$rPayId} — too fresh (" . (time()-$rTime) . "s), will retry next run");
        continue;
    }

    // UISP receipt PDF via API endpoint: /api/v2.1/payments/{id}/pdf
    // v4.11.3 FIX: was using web UI route /crm/billing/payments/{id}/pdf-receipt
    // v4.21.80 FIX: UISP upgraded to v2.1 — PDF endpoint only works on v2.1.
    //   Strip any /api/vX.Y suffix and reattach /api/v2.1 so this works regardless
    //   of what version CrmApiClient was initialised with.
    $pdfRaw = null;

    // Always use v2.1 for PDF — fall back to v1.0 only if v2.1 returns non-200
    $apiBase    = preg_replace('#/api/v[0-9.]+$#', '', rtrim($crm->getBaseUrl(), '/'));
    $apiBaseV21 = $apiBase . '/api/v2.1';
    $apiBase    = $apiBaseV21;
    $receiptUrl = $apiBase . "/payments/{$rPayId}/pdf";
    $appKey  = $crm->getAppKey();
    $authHdr = $crm->getAuthHeader() . ': ' . $appKey;

    qwa_log("Receipt PDF PAY-{$rPayId} trying API: {$receiptUrl}");

    $ch = curl_init($receiptUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [$authHdr, 'Accept: application/pdf'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $resp && strlen($resp) > 500) {
        $pdfRaw = base64_encode($resp);
        qwa_log("Receipt PDF PAY-{$rPayId} OK via API: HTTP {$httpCode}, " . round(strlen($resp)/1024) . " KB");
    } else {
        qwa_log("Receipt PDF PAY-{$rPayId} API FAIL: HTTP {$httpCode}" . ($curlErr ? ", err: {$curlErr}" : ''));

        // Fallback 1b: try v1.0 in case this is an older UISP install
        if (!$pdfRaw) {
            $_crmBase    = preg_replace('#/api/v[0-9.]+$#', '', rtrim($crm->getBaseUrl(), '/'));
            $receiptUrlV1 = $_crmBase . '/api/v1.0' . "/payments/{$rPayId}/pdf";
            if ($receiptUrlV1 !== $receiptUrl) {
                qwa_log("Receipt PDF PAY-{$rPayId} trying v1.0 fallback: {$receiptUrlV1}");
                $chV1 = curl_init($receiptUrlV1);
                curl_setopt_array($chV1, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [$authHdr, 'Accept: application/pdf'],
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $respV1     = curl_exec($chV1);
                $httpCodeV1 = (int)curl_getinfo($chV1, CURLINFO_HTTP_CODE);
                curl_close($chV1);
                if ($httpCodeV1 === 200 && $respV1 && strlen($respV1) > 500) {
                    $pdfRaw = base64_encode($respV1);
                    qwa_log("Receipt PDF PAY-{$rPayId} OK via v1.0 fallback: " . round(strlen($respV1)/1024) . " KB");
                }
            }
        }

        // Fallback 2: try with admin auth token if configured
        $_adminToken = trim($config['crm_auth_token'] ?? '');
        if ($_adminToken !== '' && !$pdfRaw) {
            $adminAuthHdr = 'x-auth-token: ' . $_adminToken;
            // Try /payments/{id}/pdf with admin token
            qwa_log("Receipt PDF PAY-{$rPayId} retrying with admin token");
            $ch2 = curl_init($receiptUrl);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [$adminAuthHdr, 'Accept: application/pdf'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp2     = curl_exec($ch2);
            $httpCode2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            if ($httpCode2 === 200 && $resp2 && strlen($resp2) > 500) {
                $pdfRaw = base64_encode($resp2);
                qwa_log("Receipt PDF PAY-{$rPayId} OK via admin token: " . round(strlen($resp2)/1024) . " KB");
            } else {
                qwa_log("Receipt PDF PAY-{$rPayId} admin token also failed: HTTP {$httpCode2}");
            }
        }

        // Fallback 2: try /billing/receipts endpoint
        if (!$pdfRaw) {
            $altUrl = $apiBase . "/billing/receipts/{$rPayId}/pdf";
            qwa_log("Receipt PDF PAY-{$rPayId} trying alt: {$altUrl}");
            $ch3 = curl_init($altUrl);
            curl_setopt_array($ch3, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [$authHdr, 'Accept: application/pdf'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp3     = curl_exec($ch3);
            $httpCode3 = (int)curl_getinfo($ch3, CURLINFO_HTTP_CODE);
            curl_close($ch3);

            if ($httpCode3 === 200 && $resp3 && strlen($resp3) > 500) {
                $pdfRaw = base64_encode($resp3);
                qwa_log("Receipt PDF PAY-{$rPayId} OK via alt path: " . round(strlen($resp3)/1024) . " KB");
            }
        }
    }

    if (!$pdfRaw) {
        // Retry count — give up after 5 attempts
        $rq['retry_count'] = ($rq['retry_count'] ?? 0) + 1;
        $receiptDirty = true;
        if ($rq['retry_count'] >= 5) {
            qwa_log("Receipt PDF PAY-{$rPayId} — GIVING UP after {$rq['retry_count']} attempts");
            $rq['sent'] = true; $rq['skip_reason'] = 'pdf_unavailable';
        } else {
            qwa_log("Receipt PDF PAY-{$rPayId} — not ready, attempt #{$rq['retry_count']}, will retry");
        }
        continue;
    }

    // Save PDF locally
    $pdfFilename = "DishNet-Receipt-{$rPayId}.pdf";
    $pdfPath     = $receiptPdfDir . '/' . $pdfFilename;
    file_put_contents($pdfPath, base64_decode($pdfRaw));

    // HMAC token for public serving
    $secret   = ($config['webhook_secret'] ?? 'dishnet');
    $pdfToken = hash_hmac('sha256', $pdfFilename . date('Ymd'), $secret);
    file_put_contents($pdfPath . '.meta', json_encode([
        'token'      => $pdfToken,
        'created'    => time(),
        'payment_id' => $rPayId,
    ]));

    // Build serve URL
    $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
    $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
    $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
    $pdfServeUrl = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
        . '?page=api&action=serve_receipt_pdf&file=' . urlencode($pdfFilename)
        . '&token=' . urlencode($pdfToken);

    // Send PDF via WhatsApp
    try {
        $pdfCaption = "Receipt #PAY-{$rPayId} — USD " . number_format($rAmount, 2) . "\n— DishNet Africa";
        $notify->sendDocument(
            NotificationService::ACCOUNTS,
            $rPhone,
            $pdfServeUrl,
            $pdfFilename,
            $pdfCaption,
            'ops_payment_receipt'
        );
        $rq['sent']    = true;
        $rq['sent_at'] = date('Y-m-d H:i:s');
        $receiptDirty  = true;
        $_receiptSentIds[$rPayId] = true; // v4.11.3: mark for dedup
        $sent++;
        qwa_log("Receipt PDF SENT: PAY-{$rPayId} → {$rName} ({$rPhone}) — " . round(strlen(base64_decode($pdfRaw))/1024) . " KB");
    } catch (\Throwable $e) {
        qwa_log("Receipt PDF WA FAILED PAY-{$rPayId}: " . $e->getMessage());
        $rq['retry_count'] = ($rq['retry_count'] ?? 0) + 1;
        $receiptDirty = true;
        $failed++;
    }
}
unset($rq); // break reference

// Clean up: keep only unsent + last 50 sent
if ($receiptDirty) {
    $unsent = array_values(array_filter($receiptQueue, function($r) { return empty($r['sent']); }));
    $done   = array_values(array_filter($receiptQueue, function($r) { return !empty($r['sent']); }));
    $done   = array_slice($done, -50);
    $store->save('receipt_pdf_queue.json', array_merge($unsent, $done));
}

// ── Save state ────────────────────────────────────────────────────────────────
$store->save('quote_wa_state.json', [
    'sent_ids'     => array_values(array_unique($sentIds)),
    'pdf_pending'  => $pdfPending,
    'last_checked' => date('Y-m-d H:i:s'),
    'last_run_at'  => date('Y-m-d H:i:s'),
    'last_stats'   => ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed],
]);

qwa_log("Done — sent:{$sent} skipped:{$skipped} failed:{$failed}");

// ══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Fetch quote PDF from UCRM, save permanently to data/quote_pdfs/.
 * Returns public URL for WhatsML to fetch, or '' on failure.
 */
function _qwa_fetchAndStorePdf(
    \CrmApiClient $crm,
    int $quoteId,
    string $quoteRef,
    string $pdfDir,
    array $config,
    string $dataDir
): string {
    try {
        // v4.21.90 FIX: Quote PDF endpoint only works on v2.1 (same as payment receipts).
        // getRawContent() appends path to whatever baseUrl the client was initialised with
        // (often v1.0). Force v2.1 via direct cURL so version is always correct.
        $pdfRaw = null;
        $appKey  = $crm->getAppKey();
        $authHdr = $crm->getAuthHeader() . ': ' . $appKey;
        $apiBase = preg_replace('#/api/v[0-9.]+$#', '', rtrim($crm->getBaseUrl(), '/'));

        // Try v2.1 first (UISP standard)
        $pdfUrl21 = $apiBase . '/api/v2.1/quotes/' . $quoteId . '/pdf';
        $ch = curl_init($pdfUrl21);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [$authHdr, 'Accept: application/pdf'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $resp && strlen($resp) > 500) {
            $pdfRaw = base64_encode($resp);
            qwa_log("PDF v2.1 OK for quote #{$quoteId}: HTTP {$code}, " . round(strlen($resp)/1024) . " KB");
        } else {
            qwa_log("PDF v2.1 failed for quote #{$quoteId}: HTTP {$code} — trying v1.0 fallback");
            // Fallback to v1.0
            $pdfUrl10 = $apiBase . '/api/v1.0/quotes/' . $quoteId . '/pdf';
            $ch2 = curl_init($pdfUrl10);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [$authHdr, 'Accept: application/pdf'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp2 = curl_exec($ch2);
            $code2 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            if ($code2 === 200 && $resp2 && strlen($resp2) > 500) {
                $pdfRaw = base64_encode($resp2);
                qwa_log("PDF v1.0 fallback OK for quote #{$quoteId}: " . round(strlen($resp2)/1024) . " KB");
            }
        }

        if (!$pdfRaw) {
            // Last resort: try via CrmApiClient getRawContent (uses whatever baseUrl set)
            $pdfRaw = $crm->getRawContent("quotes/{$quoteId}/pdf");
        }

        if (!$pdfRaw) {
            qwa_log("PDF fetch failed for quote #{$quoteId} — all methods returned empty");
            return '';
        }

        $safeRef  = preg_replace('/[^A-Za-z0-9\-]/', '', $quoteRef);
        $pdfFile  = "quote_{$quoteId}_{$safeRef}.pdf";
        $pdfPath  = $pdfDir . '/' . $pdfFile;
        $metaPath = $pdfPath . '.meta';

        // Use a stable daily token so the URL works for ~24h
        $secret   = ($config['webhook_secret'] ?? 'dishnet');
        $pdfToken = hash_hmac('sha256', $pdfFile . date('Ymd'), $secret);

        file_put_contents($pdfPath, base64_decode($pdfRaw));
        file_put_contents($metaPath, json_encode([
            'token'    => $pdfToken,
            'created'  => time(),
            'quote'    => $quoteRef,
            'filename' => "Quote-{$quoteRef}.pdf",
        ]));

        $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
        $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                 . '?page=api&action=serve_quote_pdf'
                 . '&file=' . urlencode($pdfFile)
                 . '&token=' . urlencode($pdfToken);

        qwa_log("PDF stored: {$pdfFile} (" . round(strlen(base64_decode($pdfRaw))/1024) . " KB)");
        return $pdfUrl;

    } catch (\Throwable $e) {
        qwa_log("PDF store error for quote #{$quoteId}: " . $e->getMessage());
        return '';
    }
}

/**
 * Build line items from UCRM quote, falling back to KYC application data.
 */
function _qwa_buildItems(array $q, \QuotationService $quotSvc, array $app): array
{
    $items = [];
    foreach ($q['items'] ?? [] as $qi) {
        $price = (float)($qi['price'] ?? $qi['unitPrice'] ?? 0);
        $qty   = max(1, (int)($qi['quantity'] ?? 1));
        if ($price <= 0) continue;
        $items[] = [
            'label'    => $qi['label'] ?? $qi['description'] ?? 'Service',
            'quantity' => $qty,
            'price'    => $price,
            'unit'     => $qi['unit'] ?? '',
        ];
    }
    // Fallback: single total line from quote
    if (empty($items) && ($q['total'] ?? 0) > 0) {
        $items[] = ['label' => 'Services', 'quantity' => 1, 'price' => (float)$q['total'], 'unit' => ''];
    }
    // Fallback: build from KYC application data
    if (empty($items) && !empty($app)) {
        if (!empty($app['offer_name']) && !empty($app['offer_price'])) {
            $items[] = ['label' => $app['offer_name'], 'quantity' => 1, 'price' => (float)$app['offer_price'], 'unit' => 'month'];
        }
        if (!empty($app['device_title']) && !empty($app['device_price'])) {
            $items[] = ['label' => $app['device_title'], 'quantity' => 1, 'price' => (float)$app['device_price'], 'unit' => ''];
        }
    }
    return $items;
}

/**
 * Is this quote from plugin KYC flow (and already handled by Flow A)?
 */
function _qwa_isPluginQuote(int $quoteId, \StoreInterface $store): bool
{
    $allApps = $store->load('kyc_applications.json') ?? [];
    foreach ($allApps as $app) {
        if ((int)($app['quote_id'] ?? 0) === $quoteId) {
            // Already sent — skip
            if (!empty($app['wa_quote_sent'])) return true;
            // Still pending — Flow A handles it
            if (!empty($app['wa_quote_pending'])) return true;
            // Legacy without flags — assume handled
            return true;
        }
    }
    $logs = $store->load('quotes_log.json') ?? [];
    foreach ($logs as $log) {
        if ((int)($log['crm_quote_id'] ?? 0) === $quoteId && !empty($log['sent_via_wa'])) return true;
    }
    return false;
}

/**
 * Resolve customer phone from UCRM client contacts.
 */
function _qwa_resolvePhone(int $clientId, \CrmApiClient $crm): string
{
    if (!$clientId) return '';
    try {
        $client = $crm->get("clients/{$clientId}");
        if ($client) {
            foreach (($client['contacts'] ?? []) as $ct) {
                if (!empty($ct['phone'])) return trim($ct['phone']);
            }
            return trim($client['phone'] ?? '');
        }
    } catch (\Throwable $e) {}
    return '';
}

/**
 * Resolve customer display name from UCRM.
 */
function _qwa_resolveName(int $clientId, array $q, \CrmApiClient $crm): string
{
    try {
        $client = $crm->get("clients/{$clientId}");
        if ($client) {
            $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
            if ($name) return $name;
            if (!empty($client['companyName'])) return $client['companyName'];
        }
    } catch (\Throwable $e) {}
    return 'Valued Customer';
}

/**
 * Log to quote_wa_log.json (last 200 entries).
 */
function qwa_log(string $msg): void
{
    global $dataDir;
    $logFile = $dataDir . '/quote_wa_log.json';
    $log = [];
    if (file_exists($logFile)) {
        $raw = json_decode(file_get_contents($logFile), true);
        $log = is_array($raw) ? $raw : [];
    }
    array_unshift($log, ['time' => date('Y-m-d H:i:s'), 'msg' => $msg]);
    $log = array_slice($log, 0, 200);
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
}
