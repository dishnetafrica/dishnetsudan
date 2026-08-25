<?php
/**
 * cron_crm_payment_gap.php — Catch missed CRM webhook payments
 * DishNet Hybrid v4.9.18
 *
 * Problem: When plugin restarts (update/deploy), UCRM webhook fires
 * but plugin returns 500 → UCRM retries 3x then gives up → payment
 * never appears in cashbook.
 *
 * Fix: Every 5 minutes, pull last 50 CRM payments created today,
 * check each against cb_ledger by PAY-{id} validation_ref,
 * auto-post any that are missing.
 *
 * Dedup: Uses same PAY-{id} ref format as webhook.php, so a payment
 * posted by webhook will never be duplicated by this cron, and vice versa.
 *
 * Runs via: cron/master.php → 'crm_pay_gap' every 300s (5 min)
 */
chdir(__DIR__);
date_default_timezone_set('Africa/Juba');

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/CashbookService.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

// PHP 7.4 polyfills
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) { echo "[crm_pay_gap] No data dir.\n"; return; }

$store  = \SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];
$crm    = CrmApiClient::fromUcrm(__DIR__, $config);

if (!$crm->isConfigured()) {
    echo "[crm_pay_gap] CRM not configured — skipping.\n";
    return;
}

$cb  = new CashbookService($store, $dataDir);
$pdo = $store->getPdo();

// ── Pull recent payments from CRM ────────────────────────────────────────
// UCRM API: GET /api/v1.0/billing/payments?dateFrom=YYYY-MM-DD&limit=50
$today    = date('Y-m-d');
$payments = $crm->get("billing/payments?dateFrom={$today}&limit=50&direction=DESC");

if (!is_array($payments) || empty($payments)) {
    echo "[crm_pay_gap] No CRM payments today or API error.\n";
    return;
}

echo "[crm_pay_gap] Found " . count($payments) . " CRM payments for {$today}.\n";

$posted  = 0;
$skipped = 0;
$errors  = 0;

foreach ($payments as $payment) {
    $paymentId = (int)($payment['id'] ?? 0);
    $clientId  = (int)($payment['clientId'] ?? 0);
    $amount    = (float)($payment['amount'] ?? 0);

    if (!$paymentId || !$clientId || $amount <= 0) {
        $skipped++;
        continue;
    }

    // ── Dedup check: same 3-way check as webhook.php ─────────────────
    $dupCheck = $pdo->prepare(
        "SELECT id FROM cb_ledger
         WHERE validation_ref IN (?, ?)
            OR (crm_payment_id = ? AND direction = 'in')
         LIMIT 1"
    );
    $dupCheck->execute(["PAY-{$paymentId}", "CRM-PAY-{$paymentId}", $paymentId]);

    if ($dupCheck->fetch()) {
        $skipped++;
        continue;
    }

    // ── Missing! Fetch client details and post ───────────────────────
    try {
        $client = $crm->get("clients/{$clientId}") ?? [];
        $name   = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
        if (!$name) $name = "Client #{$clientId}";

        // Build description (same format as webhook.php)
        $invoiceIds = $payment['invoiceIds'] ?? ($payment['invoices'] ?? []);
        $invNos     = [];
        if (!empty($invoiceIds)) {
            foreach ((array)$invoiceIds as $iid) {
                $invNos[] = 'INV' . str_pad((string)(int)$iid, 6, '0', STR_PAD_LEFT);
            }
        }
        $invStr = $invNos ? ' against ' . implode(' & ', $invNos) : '';
        $note   = trim($payment['note'] ?? '');
        $method = trim($payment['methodName'] ?? ($payment['method'] ?? 'Online'));
        $desc   = "Payment received from {$name} (CRM #{$clientId}){$invStr}";
        if ($note) $desc .= " — {$note}";

        // Validation status
        $methodLower = strtolower($method);
        if (strpos($methodLower, 'cash') !== false) {
            $valStatus = 'wr';
        } elseif (strpos($methodLower, 'bank') !== false || strpos($methodLower, 'transfer') !== false) {
            $valStatus = 'online';
        } else {
            $valStatus = 'online';
        }

        // Post to cashbook — identical format to webhook.php
        $cb->addEntryRaw([
            'sr'                => 'CRM-' . $paymentId,
            'project'           => 'dishnet',
            'date'              => $today,
            'direction'         => 'in',
            'amount'            => $amount,
            'currency'          => 'USD',
            'category'          => 'Receipt',
            'category_raw'      => 'Receipt',
            'person'            => '',
            'description'       => $desc,
            'validation_ref'    => "PAY-{$paymentId}",
            'validation_status' => $valStatus,
            'status'            => 'approved',
            'crm_payment_id'    => $paymentId,
            'crm_client_id'     => $clientId,
            'source'            => 'crm_gap_fill',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        $posted++;
        echo "[crm_pay_gap] ✅ Gap filled: \${$amount} from {$name} (PAY-{$paymentId})\n";

    } catch (\Throwable $e) {
        $errors++;
        echo "[crm_pay_gap] ❌ Error posting PAY-{$paymentId}: " . $e->getMessage() . "\n";
    }
}

echo "[crm_pay_gap] Done. Posted: {$posted}, Skipped: {$skipped}, Errors: {$errors}\n";

// Log if we filled any gaps
if ($posted > 0) {
    try {
        $store->appendWithId('activity_log.json', [
            'event'      => 'crm_pay_gap',
            'actor'      => 'System',
            'action'     => 'GAP_FILL',
            'detail'     => "{$posted} missed CRM payment(s) auto-posted to cashbook",
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {}
}
