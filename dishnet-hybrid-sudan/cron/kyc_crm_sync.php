<?php
/**
 * cron/kyc_crm_sync.php — v4.11.3
 *
 * Retries CRM sync for KYC applications saved with crm_sync_status='pending'.
 * Runs every 5 minutes via master.php.
 *
 * For each pending record:
 *   1. POST to CRM /clients using saved payload
 *   2. On success: update crm_client_id, crm_sync_status='synced'
 *   3. Then run deferred CRM operations: work order, quote, payment, tag
 *   4. On failure: increment retry count, exponential backoff
 *   5. Give up after 10 attempts (manual intervention needed)
 *
 * Uses return; not exit() — required by master.php (v4.10.4 rule).
 */
chdir(dirname(__DIR__));
require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';
require_once __DIR__ . '/../lib/PaymentUuids.php';

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

$dataDir = getenv('UCRM_PLUGIN_DATA_DIR') ?: (__DIR__ . '/../data');
$config  = file_exists($dataDir . '/config.json') ? json_decode(file_get_contents($dataDir . '/config.json'), true) : [];
$dbPath  = $dataDir . '/data.db';

if (!file_exists($dbPath)) return;

try {
    $store = new SqliteStore($dbPath, $dataDir);
} catch (\Throwable $e) {
    error_log("[kyc_crm_sync] Store init failed: " . $e->getMessage());
    return;
}

// CRM client
$crmUrl = trim($config['ucrm_base_url'] ?? $config['crm_base_url'] ?? '');
$crmKey = trim($config['ucrm_app_key'] ?? $config['plugin_app_key'] ?? '');
if (!$crmUrl || !$crmKey) return; // CRM not configured

$crm = new CrmApiClient(rtrim($crmUrl, '/'), $crmKey, 'X-Auth-App-Key');

// Load all KYC applications
$apps = $store->load('kyc_applications.json') ?? [];
$pending = [];
$now = time();

foreach ($apps as $idx => $app) {
    if (($app['crm_sync_status'] ?? '') !== 'pending') continue;

    // Backoff: skip if not due yet
    $attempts    = (int)($app['crm_sync_attempts'] ?? 0);
    $lastAttempt = $app['crm_sync_last_attempt'] ?? '';
    if ($attempts > 0 && $lastAttempt) {
        // Exponential backoff: 5m, 15m, 45m, 2h, 6h, 12h...
        $backoffSec = min(43200, 300 * pow(3, $attempts - 1));
        $nextRetry  = strtotime($lastAttempt) + $backoffSec;
        if ($now < $nextRetry) continue;
    }

    // Give up after 10 attempts
    if ($attempts >= 10) {
        $apps[$idx]['crm_sync_status'] = 'failed';
        $apps[$idx]['crm_sync_error']  = 'Gave up after 10 attempts';
        $store->save('kyc_applications.json', $apps);
        error_log("[kyc_crm_sync] App #{$app['id']} GAVE UP after 10 attempts");
        continue;
    }

    $pending[] = ['idx' => $idx, 'app' => $app];
}

if (empty($pending)) return;

$synced = 0;
$failed = 0;

foreach ($pending as $item) {
    $idx = $item['idx'];
    $app = $item['app'];
    $appId = $app['id'] ?? 0;

    // Rebuild CRM payload from saved data or stored payload
    $payload = null;
    if (!empty($app['crm_sync_payload'])) {
        $payload = json_decode($app['crm_sync_payload'], true);
    }
    if (!$payload) {
        // Rebuild from application fields
        $payload = [
            'clientType'     => 1,
            'isLead'         => !empty($app['is_lead']),
            'firstName'      => $app['firstname'] ?? '',
            'lastName'       => $app['lastname'] ?? '',
            'street1'        => $app['address_1'] ?? '',
            'street2'        => $app['address_2'] ?? '',
            'city'           => ($app['customer_type'] ?? '') === 'Fiber' ? ($app['fiber_area'] ?? '') : '',
            'countryId'      => null,
            'organizationId' => 2,
            'stateId'        => null,
            'note'           => ($app['device_title'] ?? '') . ' / ' . ($app['offer_name'] ?? ''),
            'zipCode'        => '',
            'username'       => $app['username'] ?? ('AUTO' . $appId),
        ];
        // Rebuild contacts
        if (!empty($app['contacts'])) {
            $payload['contacts'] = $app['contacts'];
        } else {
            $payload['contacts'] = [[
                'name'  => $app['firstname'] ?? '',
                'email' => $app['email'] ?? '',
                'phone' => $app['mobile'] ?? '',
            ]];
        }
    }

    // Attempt CRM creation
    $apps[$idx]['crm_sync_attempts']    = ($apps[$idx]['crm_sync_attempts'] ?? 0) + 1;
    $apps[$idx]['crm_sync_last_attempt'] = date('Y-m-d H:i:s');

    $result = $crm->post('clients', $payload);

    if ($result && !empty($result['id'])) {
        $crmClientId = (string)$result['id'];
        $apps[$idx]['crm_client_id']     = $crmClientId;
        $apps[$idx]['crm_sync_status']   = 'synced';
        $apps[$idx]['crm_sync_payload']  = null;
        $apps[$idx]['crm_synced_at']     = date('Y-m-d H:i:s');

        // ── Deferred CRM operations ─────────────────────────────────
        // Work Order
        try {
            $connectivity = $app['connectivity_type'] ?? 'New Connection';
            $tplId = ($connectivity === 'New Connection') ? 2 : 3;
            $crm->post('documents', [
                'clientId'   => (int)$crmClientId,
                'name'       => 'Work Order',
                'templateId' => $tplId,
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }

        // CRM tag
        try {
            $tagMap = ['Ownership Change' => 56, 'Shifting Connection' => 55];
            $tagId  = $tagMap[$connectivity] ?? 52;
            $crm->patch("clients/{$crmClientId}/add-tag/{$tagId}");
        } catch (\Throwable $e) { /* non-fatal */ }

        // Payment for cash sales
        $isCash = ($app['sales_type'] ?? '') === 'Cash';
        $amount = (float)($app['amount_charged'] ?? 0);
        if ($isCash && $amount > 0) {
            try {
                $payPayload = [
                    'clientId'     => (int)$crmClientId,
                    'amount'       => $amount,
                    'currencyCode' => 'USD',
                    'methodId'     => PaymentUuids::resolve('Cash'),
                    'note'         => 'Cash collected at registration (cron sync) | Agent: '
                                   . ($app['retailer_name'] ?? '') . ' | Ref: KYC-' . $crmClientId,
                ];
                $payResult = $crm->post('payments', $payPayload);
                if (!empty($payResult['id'])) {
                    $apps[$idx]['payment_id']      = $payResult['id'];
                    $apps[$idx]['payment_created']  = true;
                }
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        // Quote creation (deferred from KYC — only if auto-quote enabled)
        $kycConfig = $store->load('kyc_config.json') ?? [];
        $autoQuoteEnabled = ($kycConfig['kyc_auto_quote_enabled'] ?? true) !== false;
        $_quoteToken = trim($kycConfig['crm_auth_token'] ?? '');
        if ($autoQuoteEnabled && $_quoteToken !== '' && empty($app['quote_created'])) {
            try {
                $quoteCrm = new CrmApiClient(
                    rtrim($crm->getBaseUrl(), '/'),
                    $_quoteToken,
                    'x-auth-token'
                );

                // Build quote items from KYC application
                $qItems = [];
                // Main plan
                $planPrice = (float)($app['plan_price'] ?? $app['offer_price'] ?? 0);
                $planName  = $app['offer_name'] ?? $app['plan_name'] ?? 'Service Plan';
                if ($planPrice > 0) {
                    $qItems[] = ['label' => $planName, 'price' => $planPrice, 'quantity' => 1, 'unit' => 'Service'];
                }
                // Accessories
                if (!empty($app['accessories_data'])) {
                    $accData = is_string($app['accessories_data']) ? json_decode($app['accessories_data'], true) : $app['accessories_data'];
                    if (is_array($accData)) {
                        foreach ($accData as $acc) {
                            $accPrice = (float)($acc['price'] ?? 0);
                            if ($accPrice > 0) {
                                $qItems[] = ['label' => $acc['name'] ?? 'Accessory', 'price' => $accPrice, 'quantity' => (int)($acc['qty'] ?? 1), 'unit' => 'Piece'];
                            }
                        }
                    }
                }
                // Installation fee
                $installFee = (float)($app['install_fee'] ?? 0);
                if ($installFee > 0) {
                    $qItems[] = ['label' => 'Installation Fee', 'price' => $installFee, 'quantity' => 1, 'unit' => 'Service'];
                }

                if (!empty($qItems)) {
                    $validityDays = (int)($kycConfig['kyc_quote_validity_days'] ?? 7);
                    $salesPerson  = $app['retailer_name'] ?? $app['sales_person'] ?? '';
                    $qPayload = [
                        'notes'        => 'Auto-generated on KYC registration (cron sync). Sales: ' . $salesPerson,
                        'adminNotes'   => 'Cron sync for App #' . $appId,
                        'items'        => $qItems,
                    ];
                    $qResult = $quoteCrm->post("clients/{$crmClientId}/quotes", $qPayload);
                    if (!empty($qResult['id'])) {
                        $apps[$idx]['quote_id']      = $qResult['id'];
                        $apps[$idx]['quote_created']  = true;
                        $apps[$idx]['quote_ref']      = $qResult['number'] ?? '';
                        // Defer WA PDF to cron_quote_wa
                        $apps[$idx]['wa_quote_pending']     = true;
                        $apps[$idx]['wa_quote_phone']       = $app['mobile'] ?? '';
                        $apps[$idx]['wa_quote_deferred_at'] = date('Y-m-d H:i:s');
                        $quoteCrm->patch("billing/quotes/{$qResult['id']}/send");
                        error_log("[kyc_crm_sync] Quote #{$qResult['id']} created for CRM #{$crmClientId}");
                    } else {
                        $apps[$idx]['quote_error'] = json_encode($quoteCrm->getLastError());
                        error_log("[kyc_crm_sync] Quote POST failed for CRM #{$crmClientId}: " . json_encode($quoteCrm->getLastError()));
                    }
                }
            } catch (\Throwable $e) {
                error_log("[kyc_crm_sync] Quote creation failed for CRM #{$crmClientId}: " . $e->getMessage());
            }
        }

        // Update payment_collections with CRM ID
        try {
            $cols = $store->load('payment_collections.json') ?? [];
            foreach ($cols as $ci => $col) {
                if (($col['kyc_app_id'] ?? 0) == $appId && empty($col['crm_customer_id'])) {
                    $cols[$ci]['crm_customer_id'] = $crmClientId;
                    $cols[$ci]['crm_synced']      = true;
                }
            }
            $store->save('payment_collections.json', $cols);
        } catch (\Throwable $e) { /* non-fatal */ }

        $synced++;
        error_log("[kyc_crm_sync] App #{$appId} synced → CRM #{$crmClientId}");
    } else {
        $lastErr = $crm->getLastError();
        $errMsg  = json_encode($lastErr);

        // Username conflict — try next username
        if (isset($lastErr['http_code']) && (int)$lastErr['http_code'] === 422
            && strpos($errMsg, 'already taken') !== false) {
            $oldUser = $payload['username'] ?? '';
            $newUser = $oldUser . '_' . rand(100, 999);
            $payload['username'] = $newUser;
            $apps[$idx]['crm_sync_payload'] = json_encode($payload);
            $apps[$idx]['username'] = $newUser;
            error_log("[kyc_crm_sync] App #{$appId} username '{$oldUser}' taken, will retry as '{$newUser}'");
        }

        $apps[$idx]['crm_sync_error'] = substr($errMsg, 0, 500);
        $failed++;
        error_log("[kyc_crm_sync] App #{$appId} attempt #{$apps[$idx]['crm_sync_attempts']} failed: " . substr($errMsg, 0, 200));
    }

    $store->save('kyc_applications.json', $apps);

    // Rate limit: don't hammer CRM
    usleep(500000); // 0.5 sec between attempts
}

error_log("[kyc_crm_sync] Done. Synced: {$synced}, Failed: {$failed}");
return;
