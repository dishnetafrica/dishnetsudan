<?php
// Note: No strict_types - included from master.php
/**
 * cron/crm_payment_retry.php
 * Retries failed CRM payment POSTs (from crm_payment_retry.json).
 * Runs every 5 minutes via master.php.
 * Gives up after 5 attempts (logged as 'failed' permanently).
 * 
 * IMPORTANT: Uses the original collector's personal UCRM app key (if set)
 * so "Created By" in UCRM shows the agent's name, not the plugin.
 */
chdir(dirname(__DIR__));
require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';
require_once __DIR__ . '/../lib/PaymentUuids.php';

require_once __DIR__ . '/../lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$crm     = CrmApiClient::fromUcrm(dirname(__DIR__), $config);

if (!$crm->isConfigured()) { echo "[crm_payment_retry] CRM not configured — skipping.\n"; return; }

// Load retailers and collections for personal key lookup
$retailers = $store->load('retailers.json') ?? [];
$retailerMap = [];
foreach ($retailers as $r) {
    if (!empty($r['id'])) $retailerMap[(int)$r['id']] = $r;
}
$collections = $store->load('payment_collections.json') ?? [];
$collectionMap = [];
foreach ($collections as $c) {
    if (!empty($c['id'])) $collectionMap[$c['id']] = $c;
}

$queue   = $store->load('crm_payment_retry.json') ?? [];
$now     = date('Y-m-d H:i:s');
$changed = false;

foreach ($queue as &$item) {
    if (($item['status'] ?? '') !== 'pending') continue;
    if (($item['next_retry_at'] ?? '9999') > $now) continue;

    $attempts = (int)($item['attempts'] ?? 1);
    $payload  = $item['payload'] ?? [];

    // Resolve methodId → UCRM UUID (rejects slugs/integers with HTTP 422)
    // Check all possible source keys for the payment method value
    $rawMethod = $payload['methodId'] ?? $payload['method'] ?? $payload['paymentType'] ?? null;
    foreach (['method','paymentType'] as $_mk) { unset($payload[$_mk]); }
    $payload['methodId'] = PaymentUuids::resolve($rawMethod);
    $item['payload'] = $payload;

    // Look up retailer's personal key from original collection
    $colId = $item['collection_id'] ?? null;
    $collection = $colId ? ($collectionMap[$colId] ?? null) : null;
    $retailerId = $collection ? (int)($collection['retailer_id'] ?? 0) : 0;
    $retailer = $retailerId ? ($retailerMap[$retailerId] ?? null) : null;
    $personalKey = $retailer['ucrm_app_key'] ?? null;
    $crmForPayment = !empty($personalKey)
        ? new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $personalKey, 'X-Auth-App-Key')
        : $crm;
    $usingPersonalKey = !empty($personalKey);

    // Extract or create unique reference for duplicate prevention
    $paymentRef = $item['payment_ref'] ?? ($colId ? 'RETRY-COL-' . $colId : 'RETRY-' . $item['id']);
    
    // Ensure note contains reference
    if (strpos($payload['note'] ?? '', 'Ref:') === false) {
        $payload['note'] = ($payload['note'] ?? '') . ' | Ref: ' . $paymentRef;
    }

    // ── LEAD CHECK: Convert lead to client before payment push ────────
    $clientPreCheck = $crmForPayment->get("clients/{$payload['clientId']}");
    if ($clientPreCheck && (int)($clientPreCheck['clientType'] ?? 1) === 1) {
        $crmForPayment->patch("clients/{$payload['clientId']}", ['clientType' => 2]);
        echo "[crm_payment_retry] Auto-converted lead #{$payload['clientId']} to client\n";
    }

    // ── DUPLICATE PREVENTION: Check UCRM before creating ──
    $result  = $crmForPayment->createPaymentSafe($payload, $paymentRef);
    $success = !empty($result['success']) && !empty($result['id']);
    $wasDuplicate = !empty($result['duplicate']);

    if ($success) {
        $item['status']       = 'synced';
        $item['crm_id']       = $result['id'];
        $item['synced_at']    = $now;
        $item['used_personal_key'] = $usingPersonalKey;
        $item['was_duplicate'] = $wasDuplicate;
        $dupNote = $wasDuplicate ? ' [was duplicate]' : '';
        echo "[crm_payment_retry] Synced payment for CRM #{$payload['clientId']} — payment ID #{$result['id']}" . ($usingPersonalKey ? " [personal key]" : "") . "{$dupNote}\n";
        // Back-fill crm_payment_id in payment_collections.json
        if (!empty($item['collection_id'])) {
            $store->updateOne('payment_collections.json', 'id', (int)$item['collection_id'], [
                'crm_synced'     => true,
                'crm_payment_id' => $result['id'],
            ]);
        }
    } else {
        $lastErr = $crmForPayment->getLastError();
        $errMsg  = isset($lastErr['http_code'])
            ? "HTTP {$lastErr['http_code']}: " . json_encode($lastErr['response'] ?? '')
            : ($lastErr['curl_error'] ?? json_encode($lastErr));

        $item['attempts']    = $attempts + 1;
        $item['last_error']  = $errMsg;

        if ($item['attempts'] >= 5) {
            $item['status'] = 'failed';
            echo "[crm_payment_retry] GAVE UP on CRM #{$payload['clientId']} after 5 attempts. Last error: {$errMsg}\n";
            // Mark the collection as permanently failed so UI shows red "Failed" badge
            if (!empty($item['collection_id'])) {
                $store->updateOne('payment_collections.json', 'id', (int)$item['collection_id'], [
                    'crm_sync_status' => 'failed',
                    'crm_sync_error'  => $errMsg,
                ]);
            }
        } else {
            // Exponential backoff: 5m, 15m, 45m, 2h
            $backoff = [300, 900, 2700, 7200][$attempts - 1] ?? 7200;
            $item['next_retry_at'] = date('Y-m-d H:i:s', time() + $backoff);
            echo "[crm_payment_retry] Retry {$item['attempts']}/5 failed for CRM #{$payload['clientId']}: {$errMsg} — next in {$backoff}s\n";
        }
    }
    $changed = true;
}
unset($item);

if ($changed) $store->save('crm_payment_retry.json', $queue);

// ── Record sync state checkpoint ──────────────────────────────────────────
// Lets Rupesh see at a glance when outbound CRM sync last ran / last succeeded.
try {
    $pdo = $store->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_sync_state (
        entity TEXT PRIMARY KEY,
        last_run_at TEXT NOT NULL DEFAULT '',
        last_success_at TEXT NOT NULL DEFAULT '',
        last_cursor TEXT NOT NULL DEFAULT '',
        run_count INTEGER NOT NULL DEFAULT 0,
        success_count INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $synced  = array_filter($queue, fn($r) => ($r['status'] ?? '') === 'synced');
    $failed  = array_filter($queue, fn($r) => ($r['status'] ?? '') === 'failed');
    $pending = array_filter($queue, fn($r) => ($r['status'] ?? '') === 'pending');
    $lastErr = '';
    foreach (array_reverse($queue) as $r) {
        if (!empty($r['last_error'])) { $lastErr = $r['last_error']; break; }
    }
    $stmt = $pdo->prepare(
        "INSERT INTO crm_sync_state (entity, last_run_at, last_success_at, run_count, success_count, last_error, updated_at)
         VALUES ('payment_retry', :now, :suc, 1, :sc, :le, :now)
         ON CONFLICT(entity) DO UPDATE SET
           last_run_at    = :now,
           last_success_at= CASE WHEN :sc2 > 0 THEN :now2 ELSE last_success_at END,
           run_count      = run_count + 1,
           success_count  = success_count + :sc3,
           last_error     = :le2,
           updated_at     = :now3"
    );
    $sucCount = count($synced);
    $now2     = date('Y-m-d H:i:s');
    $stmt->execute([
        ':now' => $now2, ':suc' => $sucCount > 0 ? $now2 : '',
        ':sc'  => $sucCount, ':sc2' => $sucCount, ':sc3' => $sucCount,
        ':now2'=> $now2, ':now3'=> $now2,
        ':le'  => $lastErr, ':le2' => $lastErr,
    ]);
    $pending_count = count($pending);
    $failed_count  = count($failed);
    echo "[crm_payment_retry] sync_state recorded — pending:{$pending_count} synced:{$sucCount} failed:{$failed_count}\n";
} catch (\Throwable $e) {
    error_log('[crm_payment_retry] sync_state write failed: ' . $e->getMessage());
}
