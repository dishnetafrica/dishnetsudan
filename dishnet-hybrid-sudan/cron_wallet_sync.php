#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron_wallet_sync.php — CRM Org-7 Wallet Balance Auto-Sync
 *
 * Reads each retailer's CRM Org-7 accountBalance and writes it to:
 *   retailers.json  →  wallet           (live outstanding debt)
 *   retailers.json  →  crm_outstanding  (accountant panel tracking field)
 *   passbook.json   →  audit trail entry (if balance changed)
 *   org7_crm_balance_cache.json  →  last sync report
 *
 * UCRM convention: negative accountBalance = retailer owes DishNet.
 *   CRM -$8,595  →  wallet = $8,595  (must recover)
 *   CRM  $0.00   →  wallet = $0.00   (clear)
 *   CRM +$50     →  wallet = $0.00   (credit — no debt owed)
 *
 * Schedule in UCRM Plugin Settings or system cron:
 *   Every 6 hours:  0 (every 6h) php /path/to/cron_wallet_sync.php >> /path/to/wallet_sync.log 2>&1
 *   Every hour:     0  *  * * * php /path/to/cron_wallet_sync.php >> /path/to/wallet_sync.log 2>&1
 *
 * Can also be triggered from plugin Settings tab manually.
 */

chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/WalletService.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json');
$crm     = CrmApiClient::fromUcrm(__DIR__, $config);
$wallet  = new WalletService($store);

// ── Single-instance lock ──────────────────────────────────────────────────
$lockFile = $dataDir . '/cron_wallet_sync.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    log_msg("Already running — skipping.");
    return;
}

// ── Check CRM is configured ───────────────────────────────────────────────
if (!$crm->isConfigured()) {
    log_msg("CRM not configured — skipping wallet sync.");
    cleanup($lockFp, $lockFile);
    return;
}

// ── Check interval — don't run more often than configured ─────────────────
$intervalMinutes = (int)($config['wallet_sync_interval_minutes'] ?? 360); // default 6 hours
if ($intervalMinutes > 0) {
    $lastRunFile = $dataDir . '/wallet_sync_last_run.txt';
    if (file_exists($lastRunFile)) {
        $lastRun = (int)file_get_contents($lastRunFile);
        $elapsed = time() - $lastRun;
        if ($elapsed < $intervalMinutes * 60) {
            $remaining = ceil(($intervalMinutes * 60 - $elapsed) / 60);
            log_msg("Too soon — last run " . round($elapsed/60,1) . "m ago, interval is {$intervalMinutes}m. Next in {$remaining}m.");
            cleanup($lockFp, $lockFile);
            return;
        }
    }
}

log_msg("=== CRM Wallet Sync START ===");

// ── TASK 0: Retry failed recharge invoices ────────────────────────────────
// Any recharge with invoice_status='failed' and retry count < 5 gets retried.
// This handles cases where UCRM was down at approval time.
require_once __DIR__ . '/lib/FtthCrmService.php';
$ftthCrm       = new FtthCrmService($crm, $store, $config);
$allRecharges  = $store->load('wallet_recharge_requests.json') ?? [];
$retried       = 0;
$retryFixed    = 0;
$retryGiveUp   = 0;

foreach ($allRecharges as $r) {
    if (($r['invoice_status'] ?? '') !== 'failed') continue;
    $retryCount = (int)($r['crm_invoice_retry'] ?? 0);
    if ($retryCount >= 5) {
        // Give up after 5 attempts — flag for manual action
        if (($r['crm_invoice_retry'] ?? 0) < 99) {
            $store->updateOne('wallet_recharge_requests.json', 'id', (int)$r['id'], [
                'crm_invoice_retry'  => 99,
                'crm_invoice_err'    => 'Gave up after 5 retries — create invoice manually in UCRM Org-7.',
            ]);
            $retryGiveUp++;
        }
        continue;
    }

    $retailer = $store->findOne('retailers.json', 'id', (int)($r['retailer_id'] ?? 0));
    if (!$retailer) continue;

    // Try to ensure CRM client exists (may have been missing at original approve time)
    $crmClientId = $ftthCrm->ensureRetailerClient($retailer);
    // Re-read once after ensureRetailerClient saves ftth_crm_client_id back
    $freshRetailer = $store->findOne('retailers.json', 'id', (int)($r['retailer_id'] ?? 0)) ?? $retailer;

    $note    = trim($r['invoice_note'] ?? '') ?: 'Wallet top-up — approved by ' . ($r['invoice_approved_by'] ?? 'Admin');
    $invoice = $ftthCrm->createTopupInvoice(
        $freshRetailer,
        (float)($r['invoice_amount'] ?? $r['amount'] ?? 0),
        $note,
        $r['invoice_approved_by'] ?? 'cron_retry'
    );

    $retried++;
    if ($invoice && !empty($invoice['id'])) {
        $store->updateOne('wallet_recharge_requests.json', 'id', (int)$r['id'], [
            'invoice_status'     => 'done',
            'crm_invoice_id'     => (int)$invoice['id'],
            'crm_invoice_number' => $invoice['number'] ?? null,
            'crm_invoice_synced' => true,
            'crm_invoice_at'     => date('Y-m-d H:i:s'),
            'crm_client_id_used' => $crmClientId,
        ]);
        $retryFixed++;
        log_msg("  ✅ Recharge #{$r['id']} ({$retailer['name']}): invoice created → UCRM #{$invoice['id']}");
    } else {
        $store->updateOne('wallet_recharge_requests.json', 'id', (int)$r['id'], [
            'crm_invoice_retry' => $retryCount + 1,
            'crm_invoice_err'   => "Retry " . ($retryCount + 1) . "/5 failed at " . date('Y-m-d H:i:s'),
        ]);
        log_msg("  ⚠ Recharge #{$r['id']} ({$retailer['name']}): retry " . ($retryCount+1) . "/5 failed");
    }
}

if ($retried > 0 || $retryGiveUp > 0) {
    log_msg("  Invoice retries: {$retried} attempted, {$retryFixed} fixed, {$retryGiveUp} gave up (manual action needed)");
}

// ── Load all retailers ────────────────────────────────────────────────────
$retailers = $store->load('retailers.json');
$report    = [];
$updated   = 0;
$unchanged = 0;
$failed    = 0;
$skipped   = 0;

foreach ($retailers as $r) {
    $crmId = (int)($r['ftth_crm_client_id'] ?? 0);
    if (!$crmId) {
        $skipped++;
        continue;
    }

    $crmClient = $crm->get("clients/{$crmId}");
    if (!$crmClient) {
        log_msg("  ⚠ #{$r['id']} {$r['name']} — CRM #{$crmId} fetch failed");
        $failed++;
        $report[] = [
            'id'         => $r['id'],
            'name'       => $r['name'],
            'crm_id'     => $crmId,
            'crm_bal'    => null,
            'plugin_wal' => (float)($r['wallet'] ?? 0),
            'prev_debt'  => (float)($r['crm_outstanding'] ?? 0),
            'owes_amt'   => 0.0,
            'owes'       => false,
            'changed'    => false,
            'error'      => true,
        ];
        continue;
    }

    $crmBal   = (float)($crmClient['accountBalance'] ?? 0);
    $owesAmt  = $crmBal < 0 ? round(-$crmBal, 2) : 0.0;
    $prevWal  = (float)($r['wallet'] ?? 0);
    $prevDebt = (float)($r['crm_outstanding'] ?? 0);
    $changed  = abs($prevWal - $owesAmt) > 0.005;

    $report[] = [
        'id'         => $r['id'],
        'name'       => $r['name'],
        'crm_id'     => $crmId,
        'crm_bal'    => $crmBal,
        'plugin_wal' => $prevWal,
        'prev_debt'  => $prevDebt,
        'owes_amt'   => $owesAmt,
        'owes'       => $owesAmt > 0.005,
        'changed'    => $changed,
        'error'      => false,
    ];

    if (!$changed) {
        $unchanged++;
        log_msg("  — #{$r['id']} {$r['name']}: no change (\${$owesAmt})");
        continue;
    }

    // Write wallet + crm_outstanding
    $store->updateOne('retailers.json', 'id', (int)$r['id'], [
        'wallet'             => $owesAmt,
        'crm_outstanding'    => $owesAmt,
        'crm_outstanding_at' => date('Y-m-d H:i:s'),
        'crm_bal_raw'        => $crmBal,
    ]);

    // Passbook audit entry — record direction of debt change.
    // This wallet tracks DEBT OWED TO DISHNET (not spendable balance).
    //   debt went UP   → debit  (retailer owes more to DishNet)
    //   debt went DOWN → credit (debt reduced, e.g. partial payment)
    // Must use abs() — WalletService throws InvalidArgumentException on amount <= 0.
    $diff    = $owesAmt - $prevWal;
    $absDiff = round(abs($diff), 2);
    if ($absDiff > 0.005) {
        $auditNote = "CRM-sync #{$crmId} — debt " . ($diff > 0 ? 'increased' : 'decreased')
                   . ": \${$prevWal} -> \${$owesAmt}";
        if ($diff > 0) {
            // Debt increased — debit entry (more owed)
            $wallet->debit((int)$r['id'], $absDiff, $auditNote,
                'CRM-SYNC-' . date('Ymd') . '-' . $r['id'], null, '',
                '', 'adjustment', 'cron');
        } else {
            // Debt decreased — credit entry (partial payment / correction)
            $wallet->credit((int)$r['id'], $absDiff, $auditNote,
                'cron', '', 'adjustment');
        }
    }

    $arrow = $owesAmt > $prevWal ? '⬆' : '⬇';
    log_msg("  {$arrow} #{$r['id']} {$r['name']}: \${$prevWal} → \${$owesAmt} (CRM bal: \${$crmBal})");
    $updated++;
}

// ── Sort report: debtors first ────────────────────────────────────────────
usort($report, function($a, $b) {
    if ($a['error'] !== $b['error']) return $a['error'] ? 1 : -1;
    return ($b['owes_amt'] ?? 0) <=> ($a['owes_amt'] ?? 0);
});

// ── Save cache for UI panels ──────────────────────────────────────────────
$store->save('org7_crm_balance_cache.json', [
    'checked_at' => date('Y-m-d H:i:s'),
    'checked_by' => 'cron_auto',
    'applied'    => true,
    'report'     => $report,
]);

// ── Summary ───────────────────────────────────────────────────────────────
$debtors   = count(array_filter($report, fn($x) => $x['owes'] ?? false));
$totalOwed = array_sum(array_column(
    array_filter($report, fn($x) => $x['owes'] ?? false),
    'owes_amt'
));

log_msg("=== DONE — updated: {$updated}, unchanged: {$unchanged}, failed: {$failed}, skipped: {$skipped} ===");
log_msg("    Debtors: {$debtors} | Total outstanding: \${$totalOwed}");

// ── TASK: CRM Direct Payment Reconciliation ───────────────────────────────
// Piggybacks on this cron since we already have a live CRM connection.
// Catches any cash payments posted directly in UCRM (not via Hybrid plugin)
// and inserts them as synthetic field_collections records so cash balances
// stay accurate.
require_once __DIR__ . '/lib/FieldAgentService.php';

log_msg("--- CRM Payment Reconciliation START ---");

$lookbackDays = (int)($config['payment_reconcile_lookback_days'] ?? 7);
$since        = date('Y-m-d', strtotime("-{$lookbackDays} days")) . 'T00:00:00+03:00';
$cashMethodId = trim($config['cash_payment_method_id'] ?? '');

$params   = http_build_query(['createdDateFrom' => $since, 'limit' => 500]);
$payments = $crm->get("payments?{$params}");

if (!is_array($payments)) {
    log_msg("  ⚠ Could not fetch payments from UCRM — " . json_encode($crm->getLastError()));
} else {
    log_msg("  Fetched " . count($payments) . " UCRM payments (last {$lookbackDays} days).");

    // Build lookup of already-known CRM payment IDs
    $allCollections = $store->load(FieldAgentService::COLLECTIONS_FILE);
    $knownCrmIds    = [];
    $clientToAgent  = [];
    foreach ($allCollections as $c) {
        $pid = (int)($c['crm_payment_id'] ?? 0);
        if ($pid > 0) $knownCrmIds[$pid] = true;
        $cid = (int)($c['crm_client_id'] ?? 0);
        $aid = (int)($c['agent_id']      ?? 0);
        if ($cid > 0 && $aid > 0) {
            $clientToAgent[$cid] = ['agent_id' => $aid, 'agent_name' => $c['agent_name'] ?? ''];
        }
    }

    $pmtInserted   = 0;
    $pmtSkipped    = 0;
    $pmtUnassigned = 0;

    foreach ($payments as $payment) {
        $crmPaymentId = (int)($payment['id'] ?? 0);
        if ($crmPaymentId <= 0) continue;

        // Already reconciled
        if (isset($knownCrmIds[$crmPaymentId])) { $pmtSkipped++; continue; }

        // Filter to cash only if UUID is configured
        $methodId = $payment['methodId'] ?? $payment['paymentMethodId'] ?? '';
        if ($cashMethodId !== '' && $methodId !== $cashMethodId) { $pmtSkipped++; continue; }

        $amount      = round((float)($payment['amount'] ?? 0), 2);
        $crmClientId = (int)($payment['clientId'] ?? 0);
        $note        = trim($payment['note'] ?? $payment['notes'] ?? '');
        $createdAt   = str_replace('T', ' ', substr($payment['createdDate'] ?? date('Y-m-d H:i:s'), 0, 19));

        if ($amount <= 0) { $pmtSkipped++; continue; }

        // Agent attribution — match by prior collections for this client
        $agentId   = 0;
        $agentName = 'Unassigned (CRM Direct)';
        if ($crmClientId > 0 && isset($clientToAgent[$crmClientId])) {
            $agentId   = $clientToAgent[$crmClientId]['agent_id'];
            $agentName = $clientToAgent[$crmClientId]['agent_name'];
        }

        // Fetch customer name/phone from CRM
        $customerName  = "CRM Client #{$crmClientId}";
        $customerPhone = '';
        if ($crmClientId > 0) {
            $crmClient = $crm->get("clients/{$crmClientId}");
            if ($crmClient) {
                $customerName = trim(($crmClient['firstName'] ?? '') . ' ' . ($crmClient['lastName'] ?? ''));
                foreach ($crmClient['contacts'] ?? [] as $contact) {
                    $phone = trim($contact['phone'] ?? '');
                    if ($phone !== '') { $customerPhone = $phone; break; }
                }
            }
        }

        $record = $store->appendWithId(FieldAgentService::COLLECTIONS_FILE, [
            'agent_id'        => $agentId,
            'agent_name'      => $agentName,
            'amount'          => $amount,
            'collection_type' => 'subscription',
            'customer_name'   => $customerName,
            'customer_phone'  => $customerPhone,
            'reference'       => "UCRM-PMT-{$crmPaymentId}",
            'note'            => $note ?: "Auto-reconciled from UCRM payment #{$crmPaymentId}",
            'collected_at'    => $createdAt,
            'created_at'      => date('Y-m-d H:i:s'),
            'source'          => 'crm_direct',
            'crm_payment_id'  => $crmPaymentId,
            'crm_client_id'   => $crmClientId,
            'crm_method_id'   => $methodId,
            'reconciled_at'   => date('Y-m-d H:i:s'),
            'needs_review'    => true,
        ]);

        $knownCrmIds[$crmPaymentId] = true;
        if ($agentId > 0) {
            $clientToAgent[$crmClientId] = ['agent_id' => $agentId, 'agent_name' => $agentName];
        } else {
            $pmtUnassigned++;
        }

        $flag = $agentId === 0 ? ' ⚠ NEEDS REVIEW' : '';
        log_msg("  ✅ UCRM PMT #{$crmPaymentId} → Collection #{$record['id']} | \${$amount} | {$customerName} | → {$agentName}{$flag}");
        $pmtInserted++;
    }

    log_msg("  Payment reconcile: inserted={$pmtInserted}, skipped={$pmtSkipped}, unassigned={$pmtUnassigned}");
    if ($pmtUnassigned > 0) {
        log_msg("  ⚠ {$pmtUnassigned} payment(s) unassigned — review in Field Agent tab (filter: source=crm_direct)");
    }

    // Save reconciliation report
    file_put_contents($dataDir . '/payment_reconcile_last_report.json', json_encode([
        'ran_at'        => date('Y-m-d H:i:s'),
        'lookback_days' => $lookbackDays,
        'crm_fetched'   => count($payments),
        'inserted'      => $pmtInserted,
        'skipped'       => $pmtSkipped,
        'unassigned'    => $pmtUnassigned,
    ], JSON_PRETTY_PRINT));
}

log_msg("--- CRM Payment Reconciliation END ---");

// Save last run timestamp
file_put_contents($dataDir . '/wallet_sync_last_run.txt', time());

cleanup($lockFp, $lockFile);
return;

// ══════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════

function cleanup($fp, string $file): void
{
    flock($fp, LOCK_UN);
    fclose($fp);
}

function log_msg(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}
