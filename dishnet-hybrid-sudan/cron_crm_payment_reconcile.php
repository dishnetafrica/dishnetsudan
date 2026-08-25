#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron_crm_payment_reconcile.php — CRM Direct Payment Reconciliation
 *
 * PROBLEM THIS SOLVES:
 *   When someone posts a payment directly in UCRM (bypassing the Hybrid plugin),
 *   that payment never enters field_collections.json. The FieldAgentService
 *   cash_balance = collections − approved_remittances will then be inflated,
 *   because the direct UCRM payment is invisible to Hybrid.
 *
 * WHAT THIS CRON DOES:
 *   1. Fetches all UCRM payments created in the last N days (default: 7)
 *   2. Looks for payments whose `note` or `clientId` matches a known field agent
 *      customer, OR whose methodId matches the Cash payment method UUID
 *   3. Cross-checks each UCRM payment ID against field_collections.json
 *      (using the `crm_payment_id` field added by this cron)
 *   4. Any UCRM payment NOT found in field_collections is inserted as a
 *      synthetic collection record with source='crm_direct'
 *   5. Admin log entry created for audit trail — flagged for review
 *
 * RECONCILIATION FIELDS ADDED TO COLLECTION RECORDS:
 *   source            = 'crm_direct'   ← flag: entered in UCRM, not via Hybrid
 *   crm_payment_id    = <UCRM payment ID>
 *   crm_client_id     = <UCRM client ID>
 *   reconciled_at     = <timestamp>
 *   reconcile_note    = <original UCRM payment note>
 *
 * AGENT ASSIGNMENT:
 *   The cron tries to identify which field agent the payment belongs to by:
 *     a) Matching crm_client_id to a known collection in field_collections
 *        (i.e. the agent who previously collected from that customer)
 *     b) If ambiguous or unknown → assigned to a special agent_id=0 / "Unassigned"
 *        so it still affects total cash visibility but is flagged for manual review
 *
 * Schedule in UCRM Plugin Settings or system cron:
 *   Every 30 minutes:  (every 30min) php /path/to/cron_crm_payment_reconcile.php >> /path/to/reconcile.log 2>&1
 *   Every hour:        0    * * * * php /path/to/cron_crm_payment_reconcile.php >> /path/to/reconcile.log 2>&1
 *
 * Can also be triggered manually from the plugin Settings tab.
 */

chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/FieldAgentService.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);  // Use UCRM's persistent data directory
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json');
$crm     = CrmApiClient::fromUcrm(__DIR__, $config);

// ── Single-instance lock ──────────────────────────────────────────────────
$lockFile = $dataDir . '/cron_crm_payment_reconcile.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    log_msg("Already running — skipping.");
    return;
}

if (!$crm->isConfigured()) {
    log_msg("CRM not configured — skipping reconciliation.");
    cleanup($lockFp, $lockFile);
    return;
}

// ── Interval guard — default every 30 minutes ────────────────────────────
$intervalMinutes = (int)($config['payment_reconcile_interval_minutes'] ?? 30);
if ($intervalMinutes > 0) {
    $lastRunFile = $dataDir . '/payment_reconcile_last_run.txt';
    if (file_exists($lastRunFile)) {
        $lastRun = (int)file_get_contents($lastRunFile);
        $elapsed = time() - $lastRun;
        if ($elapsed < $intervalMinutes * 60) {
            $remaining = ceil(($intervalMinutes * 60 - $elapsed) / 60);
            log_msg("Too soon — last run " . round($elapsed / 60, 1) . "m ago. Next in {$remaining}m.");
            cleanup($lockFp, $lockFile);
            return;
        }
    }
}

log_msg("=== CRM Payment Reconciliation START ===");

$lookbackDays = (int)($config['payment_reconcile_lookback_days'] ?? 7);
$afterDate    = date('Y-m-d', strtotime("-{$lookbackDays} days"));

log_msg("Fetching UCRM cash payments since {$afterDate} ({$lookbackDays}-day lookback)...");

// ── Fetch cash payments from UCRM — paginated DESC + break on date ────────
// Same pattern as CashbookService::syncFromCrmApi (proven reliable).
// methodId=2 = Cash. Sorted DESC so we stop as soon as we pass afterDate.
$payments  = [];
$page      = 1;
$pageSize  = 200;
$apiOk     = true;

do {
    $offset   = ($page - 1) * $pageSize;
    $endpoint = "payments?limit={$pageSize}&offset={$offset}&methodId=2&order=createdDate&direction=DESC";
    $batch    = $crm->get($endpoint);

    if (!is_array($batch)) {
        log_msg("  ⚠ CRM API error on page {$page}: " . json_encode($crm->getLastError()));
        $apiOk = false;
        break;
    }
    if (empty($batch)) break;

    foreach ($batch as $pay) {
        $payDate = substr($pay['createdDate'] ?? '', 0, 10);
        if ($payDate < $afterDate) break 2; // sorted DESC — past window, stop
        $payments[] = $pay;
    }

    $page++;
    if (count($batch) < $pageSize) break; // last page
} while (count($payments) < 5000); // safety cap

if (!$apiOk && empty($payments)) {
    log_msg("  No data from CRM — exiting.");
    cleanup($lockFp, $lockFile);
    return;
}

log_msg("  Fetched " . count($payments) . " UCRM cash payment(s) since {$afterDate}.");

if (empty($payments)) {
    log_msg("  Nothing to reconcile.");
    file_put_contents($dataDir . '/payment_reconcile_last_run.txt', time());
    cleanup($lockFp, $lockFile);
    return;
}

// ── Build dedup index from existing field_collections ────────────────────
// Use validation_ref = 'UCRM-PMT-{id}' as the dedup key (same as what we insert below).
// Faster than scanning crm_payment_id across the full table.
$allCollections = $store->load(FieldAgentService::COLLECTIONS_FILE);
$knownRefs      = [];
$knownCrmIds    = []; // fallback: check old records that pre-date validation_ref field
foreach ($allCollections as $c) {
    $vref = $c['validation_ref'] ?? '';
    if ($vref !== '') $knownRefs[$vref] = true;
    $crmPid = (int)($c['crm_payment_id'] ?? 0);
    if ($crmPid > 0) $knownCrmIds[$crmPid] = true;
}
log_msg("  Dedup index: " . count($knownRefs) . " refs, " . count($knownCrmIds) . " crm_payment_ids.");

// ── Build agent lookup: crm_client_id → agent_id (from prior collections) ─
// If Diko has previously collected from client 123, future direct CRM payments
// for client 123 are attributed to Diko automatically.
$clientToAgent = [];
foreach ($allCollections as $c) {
    $crmClientId = (int)($c['crm_client_id'] ?? 0);
    $agentId     = (int)($c['agent_id']      ?? 0);
    $agentName   = $c['agent_name'] ?? '';
    if ($crmClientId > 0 && $agentId > 0) {
        // Last known agent for this client wins (most recent collection)
        $clientToAgent[$crmClientId] = [
            'agent_id'   => $agentId,
            'agent_name' => $agentName,
        ];
    }
}

// ── Get Cash payment method UUID for filtering ────────────────────────────
// Only reconcile Cash payments — ignore card/bank transfers added directly
$cashMethodId = trim($config['cash_payment_method_id'] ?? '');
// If no cash UUID configured, we reconcile ALL payment methods (safer — admin can review)
$filterByCash = $cashMethodId !== '';
if ($filterByCash) {
    log_msg("  Filtering to Cash method only (UUID: {$cashMethodId})");
} else {
    log_msg("  No cash_payment_method_id configured — reconciling ALL payment methods.");
}

// ── Process each UCRM payment ─────────────────────────────────────────────
$inserted  = 0;
$skipped   = 0;
$unassigned = 0;

foreach ($payments as $payment) {
    $crmPaymentId = (int)($payment['id'] ?? 0);
    if ($crmPaymentId <= 0) {
        continue;
    }

    // Skip if already reconciled — check both validation_ref (new) and crm_payment_id (legacy)
    $vref = 'UCRM-PMT-' . $crmPaymentId;
    if (isset($knownRefs[$vref]) || isset($knownCrmIds[$crmPaymentId])) {
        $skipped++;
        continue;
    }

    // Filter by payment method
    $methodId = $payment['methodId'] ?? $payment['paymentMethodId'] ?? '';
    if ($filterByCash && $methodId !== $cashMethodId) {
        $skipped++;
        continue;
    }
    // Double-check: even without UUID config, skip obvious non-cash if methodId is numeric
    $methodIdInt  = (int)$methodId;
    $methodName   = strtolower($payment['methodName'] ?? $payment['method'] ?? '');
    if (!$filterByCash && $methodIdInt > 0 && $methodIdInt !== 2) {
        // methodId 2 = Cash in standard UCRM. 3=Bank, 6=Mobile Money.
        $skipped++;
        continue;
    }

    $amount      = round((float)($payment['amount'] ?? 0), 2);
    $crmClientId = (int)($payment['clientId'] ?? 0);
    $note        = trim($payment['note'] ?? $payment['notes'] ?? '');
    $createdAt   = substr($payment['createdDate'] ?? date('Y-m-d H:i:s'), 0, 19);
    $createdAt   = str_replace('T', ' ', $createdAt);

    if ($amount <= 0) {
        $skipped++;
        continue;
    }

    // ── Try to identify the field agent ──────────────────────────────────
    $agentId   = 0;
    $agentName = 'Unassigned (CRM Direct)';

    if ($crmClientId > 0 && isset($clientToAgent[$crmClientId])) {
        $agentId   = $clientToAgent[$crmClientId]['agent_id'];
        $agentName = $clientToAgent[$crmClientId]['agent_name'];
    }

    // ── Fetch client details for customer_name / phone ────────────────────
    $customerName  = '';
    $customerPhone = '';
    if ($crmClientId > 0) {
        $crmClient = $crm->get("clients/{$crmClientId}");
        if ($crmClient) {
            $firstName = $crmClient['firstName'] ?? '';
            $lastName  = $crmClient['lastName']  ?? '';
            $customerName = trim("{$firstName} {$lastName}");
            // Try to get phone from contacts
            $contacts = $crmClient['contacts'] ?? [];
            foreach ($contacts as $contact) {
                $phone = trim($contact['phone'] ?? '');
                if ($phone !== '') {
                    $customerPhone = $phone;
                    break;
                }
            }
        }
    }

    // ── Insert synthetic collection record ────────────────────────────────
    $record = $store->appendWithId(FieldAgentService::COLLECTIONS_FILE, [
        'agent_id'         => $agentId,
        'agent_name'       => $agentName,
        'amount'           => $amount,
        'collection_type'  => 'subscription',   // default — admin can reclassify
        'customer_name'    => $customerName ?: "CRM Client #{$crmClientId}",
        'customer_phone'   => $customerPhone,
        'reference'        => "UCRM-PMT-{$crmPaymentId}",
        'note'             => $note ?: "Auto-reconciled from UCRM payment #{$crmPaymentId}",
        'collected_at'     => $createdAt,
        'created_at'       => date('Y-m-d H:i:s'),
        // Reconciliation metadata
        'source'           => 'crm_direct',
        'validation_ref'   => $vref,
        'crm_payment_id'   => $crmPaymentId,
        'crm_client_id'    => $crmClientId,
        'crm_method_id'    => $methodId,
        'reconciled_at'    => date('Y-m-d H:i:s'),
        'reconcile_note'   => "Direct UCRM payment — inserted by reconciliation cron",
        'needs_review'     => true,             // flagged for admin review
    ]);

    // Also update clientToAgent map so subsequent payments in this run get attributed
    if ($agentId > 0 && $crmClientId > 0) {
        $clientToAgent[$crmClientId] = ['agent_id' => $agentId, 'agent_name' => $agentName];
    }

    $assignedTo = $agentId > 0 ? "{$agentName} (agent #{$agentId})" : "UNASSIGNED";
    $flag       = $agentId === 0 ? ' ⚠ NEEDS REVIEW' : '';
    log_msg("  ✅ UCRM #{$crmPaymentId} → Collection #{$record['id']} | \${$amount} | {$customerName} | → {$assignedTo}{$flag}");

    if ($agentId === 0) {
        $unassigned++;
    }
    $inserted++;

    // Add to known sets so we don't double-insert within this run
    $knownRefs[$vref]               = true;
    $knownCrmIds[$crmPaymentId]     = true;
}

// ── Summary ───────────────────────────────────────────────────────────────
log_msg("=== DONE — inserted: {$inserted}, skipped: {$skipped}, unassigned: {$unassigned} ===");

if ($unassigned > 0) {
    log_msg("  ⚠ {$unassigned} payment(s) could not be attributed to a field agent.");
    log_msg("  → Go to Field Agent tab → Collections and filter by 'source=crm_direct' to review.");
}

// ── Save reconciliation report ────────────────────────────────────────────
$reportFile = $dataDir . '/payment_reconcile_last_report.json';
file_put_contents($reportFile, json_encode([
    'ran_at'      => date('Y-m-d H:i:s'),
    'lookback_days' => $lookbackDays,
    'crm_fetched' => count($payments),
    'inserted'    => $inserted,
    'skipped'     => $skipped,
    'unassigned'  => $unassigned,
], JSON_PRETTY_PRINT));

file_put_contents($dataDir . '/payment_reconcile_last_run.txt', time());
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
