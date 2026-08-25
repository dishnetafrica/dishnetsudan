<?php
// Note: No strict_types — this file is included from master.php
/**
 * cron/payment_catchup_sync.php
 * 
 * Catches orphaned payments in payment_collections.json that have:
 *   - crm_synced = false (or missing)
 *   - sync_attempts < 5 (retry protection)
 *   - crm_customer_id > 0 (has a valid CRM customer)
 * 
 * These payments were collected but the CRM push was skipped
 * (e.g., CRM was temporarily not configured, or a code path missed them).
 * 
 * This worker finds them and pushes them to CRM now.
 * Runs every 5 minutes via master.php.
 * 
 * IMPORTANT: Uses the original collector's personal UCRM app key (if set)
 * so "Created By" in UCRM shows the agent's name, not the plugin.
 * 
 * Retry protection:
 *   - Tracks sync_attempts per collection
 *   - Gives up after 5 attempts
 *   - Exponential backoff: 5m, 15m, 45m, 2h, 6h
 */
chdir(dirname(__DIR__));
require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';
require_once __DIR__ . '/../lib/PaymentUuids.php';
require_once __DIR__ . '/../lib/CashbookService.php';
require_once __DIR__ . '/../lib/NotificationService.php';

require_once __DIR__ . '/../lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$crm     = CrmApiClient::fromUcrm(dirname(__DIR__), $config);

if (!$crm->isConfigured()) {
    echo "[payment_catchup_sync] CRM not configured — skipping.\n";
    return;
}

// Load retailers for personal key lookup
$retailers = $store->load('retailers.json') ?? [];
$retailerMap = [];
foreach ($retailers as $r) {
    if (!empty($r['id'])) $retailerMap[(int)$r['id']] = $r;
}

$collections = $store->load('payment_collections.json') ?? [];
$cb          = new CashbookService($store, $dataDir);
$now         = date('Y-m-d H:i:s');
$nowTs       = time();
$synced      = 0;
$failed      = 0;
$skipped     = 0;
$gaveUp      = 0;

// Backoff intervals in seconds: 5m, 15m, 45m, 2h, 6h
$backoffSecs = [300, 900, 2700, 7200, 21600];
$maxAttempts = 5;

// Find orphaned payments: not synced, under max attempts, has CRM customer ID
$orphaned = [];
foreach ($collections as $idx => $c) {
    // Already synced — skip
    if (!empty($c['crm_synced'])) {
        continue;
    }
    
    // Check retry attempts
    $attempts = (int)($c['sync_attempts'] ?? 0);
    if ($attempts >= $maxAttempts) {
        $gaveUp++;
        continue; // Gave up after max attempts
    }
    
    // Check backoff timing (don't retry too soon)
    if ($attempts > 0) {
        $lastAttempt = strtotime($c['last_sync_attempt'] ?? '2000-01-01');
        $backoffIdx  = min($attempts - 1, count($backoffSecs) - 1);
        $nextRetry   = $lastAttempt + $backoffSecs[$backoffIdx];
        if ($nowTs < $nextRetry) {
            $skipped++;
            continue; // Too soon to retry
        }
    }
    
    // No CRM customer ID — can't sync
    $custId = (int)($c['crm_customer_id'] ?? 0);
    if ($custId <= 0) {
        $skipped++;
        continue;
    }
    
    // No amount — invalid
    $amount = (float)($c['amount'] ?? 0);
    if ($amount <= 0) {
        $skipped++;
        continue;
    }
    
    $orphaned[$idx] = $c;
}

if (empty($orphaned)) {
    echo "[payment_catchup_sync] No orphaned payments to sync. (Skipped: {$skipped}, Gave up: {$gaveUp})\n";
    return;
}

echo "[payment_catchup_sync] Found " . count($orphaned) . " orphaned payments to sync.\n";

// Log for diagnostics
$diagLog = [];

foreach ($orphaned as $idx => $c) {
    $colId  = $c['id'] ?? $idx;
    $custId = (int)$c['crm_customer_id'];
    $amount = (float)$c['amount'];
    $method = $c['method'] ?? 'Cash';
    $attempts = (int)($c['sync_attempts'] ?? 0) + 1;
    
    // Update attempt counter BEFORE trying (so we track even if crash)
    $collections[$idx]['sync_attempts']     = $attempts;
    $collections[$idx]['last_sync_attempt'] = $now;
    
    // Unique reference for duplicate prevention (based on collection ID)
    $paymentRef = 'COL-' . $colId;
    
    $payload = [
        'clientId'     => $custId,
        'methodId'     => PaymentUuids::resolve($method),
        'amount'       => $amount,
        'currencyCode' => 'USD',
        'note'         => 'Collected by ' . ($c['retailer_name'] ?? 'agent') . ' via DishNet PWA'
                        . (!empty($c['invoice_id']) ? " (Inv #{$c['invoice_id']})" : '')
                        . (!empty($c['note']) ? " — {$c['note']}" : '')
                        . " | Ref: {$paymentRef}"
                        . " [catchup-sync attempt {$attempts}]",
        'applyToInvoicesAutomatically' => true,
    ];
    
    if (!empty($c['invoice_id']) && (int)$c['invoice_id'] > 0) {
        $payload['note'] .= ' | Invoice: ' . $c['invoice_id'];
    }
    
    // Use retailer's personal UCRM app key if available (so "Created By" shows their name)
    $retailerId = (int)($c['retailer_id'] ?? 0);
    $retailer = $retailerMap[$retailerId] ?? null;
    $personalKey = $retailer['ucrm_app_key'] ?? null;
    $crmForPayment = !empty($personalKey)
        ? new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $personalKey, 'X-Auth-App-Key')
        : $crm;
    $usingPersonalKey = !empty($personalKey);
    
    // ── DUPLICATE PREVENTION: Check UCRM before creating ──
    $result  = $crmForPayment->createPaymentSafe($payload, $paymentRef);
    $success = !empty($result['success']) && !empty($result['id']);
    $wasDuplicate = !empty($result['duplicate']);
    
    $logEntry = [
        'timestamp'    => $now,
        'collection_id'=> $colId,
        'customer_name'=> $c['customer_name'] ?? '',
        'crm_customer_id' => $custId,
        'amount'       => $amount,
        'attempt'      => $attempts,
        'retailer_id'  => $retailerId,
        'retailer_name'=> $c['retailer_name'] ?? '',
        'using_personal_key' => $usingPersonalKey,
        'payment_ref'  => $paymentRef,
        'was_duplicate'=> $wasDuplicate,
        'payload'      => $payload,
        'success'      => $success,
        'crm_response' => $result,
        'crm_error'    => $crmForPayment->getLastError(),
    ];
    $diagLog[] = $logEntry;
    
    if ($success) {
        $crmPayId = $result['id'] ?? null;
        
        // Update collection record
        $collections[$idx]['crm_synced']     = true;
        $collections[$idx]['crm_payment_id'] = $crmPayId;
        $collections[$idx]['crm_synced_at']  = $now;
        $collections[$idx]['synced_by']      = 'catchup_sync';
        
        // Post to cashbook (idempotent via SR)
        $colSr = 'COL-' . $colId;
        try {
            $cb->addEntryRaw([
                'sr'                => $colSr,
                'project'           => 'dishnet',
                'date'              => substr($c['created_at'] ?? date('Y-m-d'), 0, 10),
                'direction'         => 'in',
                'amount'            => $amount,
                'currency'          => 'USD',
                'category'          => 'Receipt',
                'category_raw'      => 'Receipt',
                'person'            => $c['retailer_name'] ?? '',
                'description'       => 'Cash collected from ' . ($c['customer_name'] ?? '') . ($custId ? " (CRM #{$custId})" : ''),
                'validation_ref'    => 'PAY-' . $crmPayId,
                'validation_status' => 'na',
                'status'            => 'approved',
                'approved_by'       => 'Catchup Sync',
                'crm_payment_id'    => $crmPayId,
                'crm_client_id'     => $custId,
                'source'            => 'catchup_sync',
                'created_at'        => ($c['created_at'] ?? date('Y-m-d H:i:s')),
            ]);
        } catch (\Throwable $e) {
            echo "[payment_catchup_sync] Cashbook entry failed for COL-{$colId}: " . $e->getMessage() . "\n";
        }
        
        $synced++;
        echo "[payment_catchup_sync] ✓ Synced collection #{$colId} → CRM payment #{$crmPayId} (attempt {$attempts})" . ($usingPersonalKey ? " [personal key]" : "") . "\n";
        
    } else {
        $lastErr = $crmForPayment->getLastError();
        $errMsg  = isset($lastErr['http_code'])
            ? "HTTP {$lastErr['http_code']}: " . json_encode($lastErr['response'] ?? '')
            : ($lastErr['curl_error'] ?? json_encode($lastErr));
        
        $collections[$idx]['last_crm_error'] = $errMsg;
        
        if ($attempts >= $maxAttempts) {
            $collections[$idx]['sync_status'] = 'gave_up';
            echo "[payment_catchup_sync] ✗ GAVE UP on collection #{$colId} after {$attempts} attempts: {$errMsg}\n";
            $gaveUp++;
        } else {
            $nextBackoff = $backoffSecs[min($attempts - 1, count($backoffSecs) - 1)];
            $nextRetryAt = date('Y-m-d H:i:s', $nowTs + $nextBackoff);
            $collections[$idx]['next_retry_at'] = $nextRetryAt;
            echo "[payment_catchup_sync] ✗ Failed collection #{$colId} (attempt {$attempts}/{$maxAttempts}): {$errMsg} — next retry: {$nextRetryAt}\n";
        }
        
        $failed++;
    }
}

// Save updated collections
$store->save('payment_collections.json', $collections);

// Save diagnostic log
$diagFile = $dataDir . '/payment_catchup_log.json';
$existingLog = file_exists($diagFile) ? json_decode(file_get_contents($diagFile), true) : [];
if (!is_array($existingLog)) $existingLog = [];
$existingLog = array_merge($diagLog, $existingLog);
$existingLog = array_slice($existingLog, 0, 100); // keep last 100
file_put_contents($diagFile, json_encode($existingLog, JSON_PRETTY_PRINT));

echo "[payment_catchup_sync] Complete. Synced: {$synced}, Failed: {$failed}, Skipped: {$skipped}, Gave up: {$gaveUp}\n";
