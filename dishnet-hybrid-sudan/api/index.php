<?php
/**
 * DishNet Hybrid Telecom — REST API
 * Base: /crm/_plugins/dishnet-hybrid-telecom/api/
 * Auth: Bearer token | PHP 7.4+
 */
declare(strict_types=1);
chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/RetailerAuth.php';
require_once __DIR__ . '/../lib/WalletService.php';
require_once __DIR__ . '/../lib/IdempotencyGuard.php';
require_once __DIR__ . '/../lib/SimService.php';
require_once __DIR__ . '/../lib/KycService.php';
require_once __DIR__ . '/../lib/RechargeService.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';
require_once __DIR__ . '/../lib/CrmQueue.php';
require_once __DIR__ . '/../lib/FieldAgentService.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/LoginRateLimiter.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/FtthCrmService.php';
require_once __DIR__ . '/../lib/SplynxApiClient.php';
require_once __DIR__ . '/../lib/SplynxTicketService.php';
require_once __DIR__ . '/../lib/SplynxCustomerService.php';

header('Content-Type: application/json; charset=UTF-8');
// CRIT-05 FIX: Restrict CORS to the specific PWA origin.
// Set env var PWA_ORIGIN=https://your-pwa.dishnetafrica.com
// Falls back to * only if not configured (development mode).
$_allowedOrigin = getenv('PWA_ORIGIN') ?: '*';
header('Access-Control-Allow-Origin: ' . $_allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Idempotency-Key');
header('Access-Control-Expose-Headers: X-Idempotency-Replay');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// CRIT-06 FIX: Use SqliteStore (same backend as public.php and cron workers).
// Previously used JsonStore which reads stale .json.migrated backups when SQLite is active.
$store       = SqliteStore::create($dataDir);
$auth        = new RetailerAuth($store);
$wallet      = new WalletService($store);
$idempotency = new IdempotencyGuard($store);
$sim         = new SimService($store, $wallet);
$queue       = new CrmQueue($store);

$uploadDir = $dataDir . '/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
$config   = $store->load('kyc_config.json');
$crm      = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
$kyc      = new KycService($crm, $store, $wallet, $queue);
$recharge  = new RechargeService($store, $wallet, $uploadDir);
$fieldAgent = new FieldAgentService($store);
$limiter    = new LoginRateLimiter($store);
$notify     = new NotificationService($store, $config);
$splynx        = SplynxApiClient::fromConfig($config);
$splynxTickets = new SplynxTicketService($splynx, $store, $notify, $config);
$splynxCusts   = new SplynxCustomerService($splynx, $splynxTickets, $store, $config);

function apiOk(array $data = [], string $message = 'Success', int $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

function apiError(string $message, int $code = 400, array $errors = []) {
    http_response_code($code);
    echo json_encode(array_filter(['success' => false, 'message' => $message, 'errors' => $errors ?: null]));
    exit;
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Logout (token revocation — call before auth gate so invalid tokens can still logout) ──
if ($action === 'logout' && $method === 'POST') {
    // Allow either a valid token or one that has just expired
    $raw = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$raw && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $raw = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if (preg_match('/^Bearer\s+(.+)$/i', trim($raw), $m)) {
        $logoutToken = trim($m[1]);
        // Find retailer by token directly (bypass expiry check for logout)
        $allRetailers = $store->load('retailers.json');
        foreach ($allRetailers as $rr) {
            if (($rr['api_token'] ?? '') === $logoutToken && !empty($rr['is_active'])) {
                $store->updateOne('retailers.json', 'id', (int)$rr['id'], [
                    'api_token' => null, 'token_issued_at' => null,
                ]);
                apiOk([], 'Logged out. Token revoked.');
            }
        }
    }
    apiOk([], 'Logged out.'); // Token already invalid or not found — treat as success
}

// ── Health (public) ──────────────────────────────────────────
if ($action === 'health') {
    $meta = $store->load('plugin_meta.json');
    apiOk([
        'version'        => '3.0.0',
        'status'         => 'ok',
        'last_heartbeat' => isset($meta['last_heartbeat']) ? $meta['last_heartbeat'] : null,
    ], 'DishNet Hybrid Telecom running.');
}

// ── Android app version check (public, no auth) ────────────────
if ($action === 'app_version') {
    $apkMeta = $store->load('android_app_meta.json');
    $minVer  = isset($apkMeta['min_version']) ? $apkMeta['min_version'] : (isset($apkMeta['version']) ? $apkMeta['version'] : '2.4');
    apiOk([
        'min_version'       => $minVer,
        'latest_version'    => isset($apkMeta['version']) ? $apkMeta['version'] : '2.4',
        'version_code'      => isset($apkMeta['version_code']) ? (int)$apkMeta['version_code'] : 5,
        'required_features' => isset($apkMeta['required_features']) ? $apkMeta['required_features'] : ['barcode_scanner'],
        'update_url'        => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?page=install',
    ]);
}
if ($action === 'login') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body  = getBody();
    $email = trim(isset($body['email']) ? $body['email'] : '');
    $pass  = isset($body['password']) ? $body['password'] : '';
    if (!$email || !$pass) apiError('Email and password are required.');

    // I-02: Rate limiting
    $clientIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $lockCheck = $limiter->check($email, $clientIp);
    if ($lockCheck['locked']) {
        $mins = $lockCheck['retry_in_minutes'];
        apiError("Too many failed attempts. Locked for {$mins} min.", 429);
    }

    $found = null;
    foreach ($store->load('retailers.json') as $r) {
        if (strtolower($r['email']) === strtolower($email) && !empty($r['is_active'])) {
            if (password_verify($pass, $r['password'])) { $found = $r; break; }
        }
    }
    if (!$found) {
        $res2 = $limiter->recordFailure($email, $clientIp);
        if ($res2['locked']) {
            apiError("Too many failed attempts. Locked for {$res2['retry_in_minutes']} min.", 429);
        }
        $left = $res2['attempts_remaining'];
        apiError('Invalid email or password.' . ($left <= 2 ? " {$left} attempt(s) remaining." : ''), 401);
    }
    $limiter->recordSuccess($email, $clientIp);

    // Always rotate the token on login — this invalidates any existing session
    // (e.g. demo account still logged in on another device)
    $newToken = bin2hex(random_bytes(32));
    $store->updateOne('retailers.json', 'id', (int)$found['id'], [
        'api_token'       => $newToken,
        'token_issued_at' => time(),
    ]);
    $found['api_token']       = $newToken;
    $found['token_issued_at'] = time();

    unset($found['password']);
    apiOk([
        'retailer' => $found,
        'token'    => $found['api_token'],
        'wallet'   => $wallet->getSummary($found['id']),
    ], 'Login successful.');
}

// ── Auth gate ────────────────────────────────────────────────
$retailer = $auth->tokenAuth();
if (!$retailer) apiError('Unauthorized.', 401);
$retailerId = (int)$retailer['id'];

// ── GET ?action=me ───────────────────────────────────────────
if ($action === 'me') {
    $r = $auth->safeRetailer($retailer);
    $r['wallet_summary'] = $wallet->getSummary($retailerId);
    apiOk($r, 'Profile loaded.');
}

// ── GET ?action=devices ──────────────────────────────────────
if ($action === 'devices') {
    $type = strtolower(isset($_GET['type']) ? $_GET['type'] : 'all');
    $all  = $store->load('kyc_devices.json');
    $out  = array_values(array_filter($all, function($d) use ($type) {
        $active = isset($d['is_active']) ? $d['is_active'] : true;
        $dtype  = isset($d['type']) ? $d['type'] : 'starlink';
        return $active && ($dtype === $type || $type === 'all');
    }));
    apiOk(['devices' => $out]);
}

// ── GET ?action=packages ─────────────────────────────────────
if ($action === 'packages') {
    $type = strtolower(isset($_GET['type']) ? $_GET['type'] : 'all');
    $all  = $store->load('kyc_packages.json');
    $out  = array_values(array_filter($all, function($p) use ($type) {
        $active = isset($p['is_active']) ? $p['is_active'] : true;
        $ptype  = isset($p['type']) ? $p['type'] : 'starlink';
        return $active && ($ptype === $type || $type === 'all');
    }));
    apiOk(['packages' => $out]);
}

// ── GET ?action=wallet_balance ───────────────────────────────
if ($action === 'wallet_balance') {
    apiOk($wallet->getSummary($retailerId), 'Wallet summary.');
}

// ── GET ?action=wallet_passbook ──────────────────────────────
if ($action === 'wallet_passbook') {
    $page    = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
    $perPage = min(100, max(1, (int)(isset($_GET['per_page']) ? $_GET['per_page'] : 20)));
    $all     = $wallet->getPassbook($retailerId, 1000);
    $total   = count($all);
    $paged   = array_slice($all, ($page - 1) * $perPage, $perPage);
    apiOk([
        'passbook'   => $paged,
        'summary'    => $wallet->getSummary($retailerId),
        'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
    ]);
}


// == GET ?action=wallet_transaction&trx_no=TRX-... ==
if ($action === 'wallet_transaction') {
    $trxNo = isset($_GET['trx_no']) ? trim($_GET['trx_no']) : '';
    if ($trxNo === '') apiError('trx_no required.', 400);
    $entry = $wallet->findByTrxNo($trxNo);
    if (!$entry) apiError('Transaction not found.', 404);
    if ((int)(isset($entry['retailer_id']) ? $entry['retailer_id'] : 0) !== $retailerId) {
        apiError('Access denied.', 403);
    }
    apiOk(['transaction' => $entry]);
}

// == POST ?action=wallet_topup (admin only) ==
if ($action === 'wallet_topup') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    if ($idempotency->handleIfDuplicate()) exit;

    $body     = getBody();
    $targetId = (int)(isset($body['retailer_id']) ? $body['retailer_id'] : 0);
    $amount   = (float)(isset($body['amount']) ? $body['amount'] : 0);
    $note     = trim(isset($body['note']) ? $body['note'] : 'Admin top-up');
    $idempKey = trim(isset($body['idempotency_key']) ? $body['idempotency_key'] : '');

    if ($targetId <= 0) apiError('Valid retailer_id required.');
    if ($amount <= 0 || $amount > 50000) apiError('Amount must be $0.01-$50,000.');

    $target = $store->findOne('retailers.json', 'id', $targetId);
    if (!$target) apiError('Retailer not found.', 404);

    try {
        $entry = $wallet->credit($targetId, $amount, $note, $retailer['name'], $idempKey, 'topup');
        $resp  = ['success' => true, 'message' => 'Top-up successful.', 'data' => ['entry' => $entry]];
        $idempotency->storeCurrentResponse(201, $resp);
        apiOk(['entry' => $entry], 'Top-up successful.', 201);
    } catch (\Exception $e) {
        apiError($e->getMessage(), 400);
    }
}

// == POST ?action=wallet_reverse (admin only) ==
if ($action === 'wallet_reverse') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    if ($idempotency->handleIfDuplicate()) exit;

    $body   = getBody();
    $trxNo  = trim(isset($body['trx_no']) ? $body['trx_no'] : '');
    $reason = trim(isset($body['reason']) ? $body['reason'] : '');

    if ($trxNo === '') apiError('trx_no required.');
    if ($reason === '') apiError('Reason required for reversal.');

    try {
        $entry = $wallet->reverse($trxNo, $reason, $retailer['name']);
        $resp  = ['success' => true, 'message' => 'Reversal completed.', 'data' => ['entry' => $entry]];
        $idempotency->storeCurrentResponse(201, $resp);
        apiOk(['entry' => $entry], 'Reversal completed.', 201);
    } catch (\Exception $e) {
        apiError($e->getMessage(), 400);
    }
}

// == GET ?action=admin_wallets (admin only) ==
if ($action === 'admin_wallets') {
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    $balances = $wallet->getAllBalances();
    $totalFloat = 0.0;
    foreach ($balances as $b) { $totalFloat += $b['balance']; }
    apiOk([
        'wallets'     => $balances,
        'total_float' => round($totalFloat, 2),
        'count'       => count($balances),
    ], 'All wallet balances.');
}

// == GET ?action=admin_passbook (admin only) ==
if ($action === 'admin_passbook') {
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    $page    = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
    $perPage = min(100, max(1, (int)(isset($_GET['per_page']) ? $_GET['per_page'] : 50)));
    $all     = $wallet->getAllPassbook(2000);
    $total   = count($all);
    $paged   = array_slice($all, ($page - 1) * $perPage, $perPage);
    apiOk([
        'passbook'   => $paged,
        'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
    ], 'All transactions.');
}


// == SIM INVENTORY ENDPOINTS ==

// == GET ?action=sim_inventory&status=available ==
if ($action === 'sim_inventory') {
    $status  = isset($_GET['status']) ? trim($_GET['status']) : '';
    $page    = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
    $perPage = min(100, max(1, (int)(isset($_GET['per_page']) ? $_GET['per_page'] : 50)));
    apiOk($sim->getInventory($retailerId, $status, $page, $perPage));
}

// == GET ?action=sim_stock ==
if ($action === 'sim_stock') {
    apiOk($sim->getStockSummary($retailerId), 'Stock summary.');
}

// == GET ?action=sim_detail&id=N ==
if ($action === 'sim_detail') {
    $simId = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    if ($simId <= 0) apiError('SIM id required.', 400);
    $card = $sim->getById($simId);
    if (!$card) apiError('SIM not found.', 404);
    if ((int)(isset($card['owner_org_id']) ? $card['owner_org_id'] : 0) !== $retailerId && empty($retailer['is_admin'])) {
        apiError('Access denied.', 403);
    }
    apiOk(['sim' => $card, 'movements' => $sim->getMovements($simId)]);
}

// == POST ?action=sim_activate ==
if ($action === 'sim_activate') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if ($idempotency->handleIfDuplicate()) exit;
    $body = getBody();
    $sId  = (int)(isset($body['sim_id']) ? $body['sim_id'] : 0);
    $cNm  = trim(isset($body['customer_name']) ? $body['customer_name'] : '');
    $cPh  = trim(isset($body['customer_phone']) ? $body['customer_phone'] : '');
    $fee  = (float)(isset($body['activation_fee']) ? $body['activation_fee'] : 5.00);
    $iKey = trim(isset($body['idempotency_key']) ? $body['idempotency_key'] : '');
    if ($sId <= 0) apiError('sim_id required.');
    if ($cNm === '') apiError('customer_name required.');
    if ($cPh === '') apiError('customer_phone required.');
    $result = $sim->activate($sId, $cNm, $cPh, $retailerId, $retailer['name'], $fee, $iKey);
    if ($result['success']) {
        $idempotency->storeCurrentResponse(201, ['success'=>true,'message'=>$result['message'],'data'=>$result['data']]);
        try { $notify->simActivated($retailer, $result['data']['msisdn'] ?? '', $cNm, $fee); } catch (\Throwable $e) {}
        apiOk($result['data'], $result['message'], 201);
    } else { apiError($result['message'], 400); }
}

// == POST ?action=sim_status_change (admin) ==
if ($action === 'sim_status_change') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (empty($retailer['is_admin'])) apiError('Admin required.', 403);
    $body = getBody();
    $sId  = (int)(isset($body['sim_id']) ? $body['sim_id'] : 0);
    $nSt  = trim(isset($body['status']) ? $body['status'] : '');
    $rsn  = trim(isset($body['reason']) ? $body['reason'] : '');
    if ($sId <= 0) apiError('sim_id required.');
    if ($nSt === '') apiError('status required.');
    $result = $sim->changeStatus($sId, $nSt, $retailerId, $rsn);
    $result['success'] ? apiOk([], $result['message']) : apiError($result['message'], 409);
}

// == POST ?action=sim_allocate (admin) ==
if ($action === 'sim_allocate') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (empty($retailer['is_admin'])) apiError('Admin required.', 403);
    $body = getBody();
    $ids  = isset($body['sim_ids']) ? $body['sim_ids'] : [];
    $tId  = (int)(isset($body['to_org_id']) ? $body['to_org_id'] : 0);
    $tNm  = trim(isset($body['to_org_name']) ? $body['to_org_name'] : '');
    if (empty($ids) || !is_array($ids)) apiError('sim_ids array required.');
    if ($tId <= 0 || $tNm === '') apiError('to_org_id and to_org_name required.');
    $result = $sim->allocate($ids, $tId, $tNm, $retailerId);
    apiOk($result, $result['allocated'] . ' SIMs allocated.');
}

// == POST ?action=sim_return ==
if ($action === 'sim_return') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body = getBody();
    $sId  = (int)(isset($body['sim_id']) ? $body['sim_id'] : 0);
    $tId  = (int)(isset($body['to_org_id']) ? $body['to_org_id'] : 1);
    $tNm  = trim(isset($body['to_org_name']) ? $body['to_org_name'] : 'DishNet HQ');
    if ($sId <= 0) apiError('sim_id required.');
    $result = $sim->returnSim($sId, $tId, $tNm, $retailerId);
    $result['success'] ? apiOk([], $result['message']) : apiError($result['message'], 409);
}

// == POST ?action=sim_inbound (admin) ==
if ($action === 'sim_inbound') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (empty($retailer['is_admin'])) apiError('Admin required.', 403);
    $body = getBody();
    $sims = isset($body['sims']) ? $body['sims'] : [];
    if (empty($sims) || !is_array($sims)) apiError('sims array required.');
    $result = $sim->inboundBatch($sims);
    apiOk($result, $result['imported'] . ' imported, ' . $result['skipped'] . ' skipped.');
}


// POST kyc_submit
if ($action === 'kyc_submit') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body = getBody();
    $req = ['connectivity_type','customer_type','firstname','lastname','mobile','address_1'];
    $err = [];
    foreach ($req as $f) { if (empty(trim($body[$f] ?? ''))) $err[$f] = $f . ' is required.'; }
    if ($err) apiError('Validation failed.', 422, $err);

    // ── Stock availability check (non-blocking) ──────────────────
    $stockWarnings = [];
    if (!empty($body['hw_cart_json'])) {
        try {
            require_once __DIR__ . '/../lib/StockService.php';
            $_stkSvc = StockService::fromStore($store, $dataDir);
            $_stkSvc->ensureTables();
            $hwCart = json_decode($body['hw_cart_json'], true) ?? [];
            if (!empty($hwCart)) {
                $availability = $_stkSvc->checkAvailability($hwCart);
                foreach ($availability as $a) {
                    if (!$a['ok'] && $a['available'] !== null) {
                        $stockWarnings[] = ($a['title'] ?? 'Item') . ': only ' . $a['available'] . ' in stock (need ' . $a['needed'] . ')';
                    }
                }
            }
        } catch (\Throwable $e) {
            // Don't block KYC on stock check failure
        }
    }

    $files = [];
    $result = $kyc->process($body, $files, $retailer);
    if ($result['success']) {
        // I-03: WhatsApp notification — KYC submitted, CRM creation queued.
        // Branch: additional-service-for-existing-customer flow has its own
        // dedicated notification path (Bidal + admin + agent) and a CRM
        // scheduling job auto-created in KycService::handleExisting.
        $resultData = $result['data'] ?? [];
        if (!empty($resultData['is_additional_service'])) {
            $notify->kycAdditionalService($retailer, $resultData);
        } else {
            $notify->kycSubmitted($retailer, $resultData);
        }
        $responseData = $resultData;
        if (!empty($stockWarnings)) {
            $responseData['stock_warnings'] = $stockWarnings;
        }
        $msg = $result['message'];
        if (!empty($stockWarnings)) {
            $msg .= ' ⚠️ Stock warning: ' . implode('; ', $stockWarnings);
        }
        apiOk($responseData, $msg, 201);
    } else {
        apiError($result['message'], 400);
    }
}

// GET kyc_list
if ($action === 'kyc_list') {
    $apps = $kyc->getApplications($retailerId, false);
    $st = $_GET['status'] ?? '';
    if ($st) $apps = array_values(array_filter($apps, fn($a) => ($a['status'] ?? '') === $st));
    // Annotate each app with conversion eligibility
    foreach ($apps as &$a) {
        $a['can_convert'] = !empty($a['is_lead']) && !empty($a['crm_client_id']);
    }
    unset($a);
    apiOk(['applications' => $apps, 'total' => count($apps)]);
}

// GET kyc_leads — convenience: only Credit/lead applications pending conversion
if ($action === 'kyc_leads') {
    $apps = $kyc->getApplications($retailerId, false);
    $leads = array_values(array_filter($apps, fn($a) => !empty($a['is_lead']) && !empty($a['crm_client_id'])));
    apiOk(['leads' => $leads, 'total' => count($leads),
           'message' => count($leads) === 0 ? 'No pending Credit customers.' : count($leads) . ' Credit customer(s) awaiting conversion.']);
}

// GET kyc_view
if ($action === 'kyc_view') {
    $id = (int)($_GET['id'] ?? 0);
    $app = $store->findOne('kyc_applications.json', 'id', $id);
    if (!$app) apiError('Not found.', 404);
    if ((int)($app['retailer_id'] ?? 0) !== $retailerId) apiError('Access denied.', 403);
    apiOk(['application' => $app]);
}

// POST convert_to_customer — mark a Credit KYC application as paid → converts CRM Lead → Regular Customer
if ($action === 'convert_to_customer') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body  = getBody();
    $appId = (int)($body['application_id'] ?? 0);
    if (!$appId) apiError('application_id required.');

    $app = $store->findOne('kyc_applications.json', 'id', $appId);
    if (!$app) apiError('Application not found.', 404);
    if ((int)($app['retailer_id'] ?? 0) !== $retailerId && !($retailer['is_admin'] ?? false))
        apiError('Access denied.', 403);

    $crmClientId = $app['crm_client_id'] ?? null;
    if (!$crmClientId) apiError('No CRM client ID on this application — cannot convert.');
    if (empty($app['is_lead'])) apiError('This customer is already a Regular Customer — no conversion needed.');

    $paymentMethod = trim($body['payment_method'] ?? 'Cash');   // Cash | Bank Transfer | Mobile Money
    $paymentRef    = trim($body['payment_reference'] ?? '');
    $paymentNote   = trim($body['note'] ?? '');

    // Step 1: Patch CRM client — set isLead = false
    $patchResult = $crm->patch("clients/{$crmClientId}", ['isLead' => false]);
    if ($patchResult === null) {
        apiError('CRM update failed: ' . json_encode($crm->getLastError()), 502);
    }

    // Step 2: Post payment to UCRM billing (amount from original application)
    $amount = (float)($app['amount_charged'] ?? 0);
    $paymentId = null;
    if ($amount > 0) {
        $methodMap = ['Cash'=>'Cash','Mobile Money'=>'Check','Bank Transfer'=>'Bank Transfer','Card'=>'Credit Card'];
        $payResp = $crm->post('payments', [
            'clientId'     => (int)$crmClientId,
            'amount'       => $amount,
            'currencyCode' => 'USD',
            'methodId'     => 2,  // 2=Cash, 3=Bank Transfer, 4=Credit Card, 6=Mobile Money
            'note'         => 'Payment received — Lead converted to Regular Customer.'
                            . ($paymentRef ? ' Ref: ' . $paymentRef : '')
                            . ($paymentNote ? ' | ' . $paymentNote : '')
                            . ' | Agent: ' . ($retailer['name'] ?? ''),
            'applyToInvoicesAutomatically' => true,
        ]);
        if (!empty($payResp['id'])) $paymentId = $payResp['id'];
    }

    // Step 3: Update local application record
    $store->updateOne('kyc_applications.json', 'id', $appId, [
        'is_lead'          => false,
        'converted_at'     => date('Y-m-d H:i:s'),
        'converted_by'     => $retailer['name'],
        'payment_method'   => $paymentMethod,
        'payment_ref'      => $paymentRef,
        'crm_payment_id'   => $paymentId,
        'status'           => 'converted',
    ]);

    // Step 4: WhatsApp notification (reuse ops_kyc_submitted style)
    // No dedicated template — just log activity
    logActivity($dataDir, 'convert_to_customer',
        "Lead converted: CRM #{$crmClientId} — {$app['firstname']} {$app['lastname']}",
        "By: {$retailer['name']} | Method: {$paymentMethod}" . ($paymentRef ? " | Ref: {$paymentRef}" : '')
    );

    apiOk([
        'crm_client_id' => $crmClientId,
        'application_id'=> $appId,
        'payment_id'    => $paymentId,
        'amount'        => $amount,
        'converted_at'  => date('Y-m-d H:i:s'),
    ], "✅ {$app['firstname']} {$app['lastname']} is now a Regular Customer in CRM." . ($paymentId ? " Payment #{$paymentId} posted." : ''));
}

// POST recharge_submit
if ($action === 'recharge_submit') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body = getBody();
    $files = [];
    $result = $recharge->submit($body, $files, $retailer);
    if ($result['success']) apiOk($result['data'] ?? [], $result['message'], 201);
    else apiError($result['message'], 400);
}

// GET recharge_list
if ($action === 'recharge_list') {
    $list = $recharge->getForRetailer($retailerId, 50);
    apiOk(['recharges' => $list, 'total' => count($list)]);
}

// ══════════════════════════════════════════════════════════════════════
// FIELD AGENT ENDPOINTS
// ══════════════════════════════════════════════════════════════════════

// POST agent_collection_log — field agent logs a cash collection
if ($action === 'agent_collection_log') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if ($idempotency->handleIfDuplicate()) exit;
    if (!$fieldAgent->isFieldAgent($retailer)) apiError('Field agent role required.', 403);
    $body   = getBody();
    $result = $fieldAgent->logCollection($body, $retailer);
    if ($result['success']) {
        $idempotency->storeCurrentResponse(201, $result);
        apiOk($result['data'] ?? [], $result['message'], 201);
    } else {
        apiError($result['message'], 400);
    }
}

// GET agent_collections — list collections (agent: own; admin: all or ?agent_id=X)
if ($action === 'agent_collections') {
    $isAdmin   = !empty($retailer['is_admin']);
    $filterAid = $isAdmin ? (int)($_GET['agent_id'] ?? 0) : $retailerId;
    $dateFrom  = $_GET['date_from'] ?? null;
    $dateTo    = $_GET['date_to'] ?? null;
    $query     = trim($_GET['q'] ?? '');

    if ($query !== '') {
        $list = $fieldAgent->searchCollections($query, $filterAid ?: null);
    } else {
        $list = $fieldAgent->getCollections($filterAid, $isAdmin && $filterAid === 0, $dateFrom, $dateTo);
    }
    apiOk(['collections' => $list, 'total' => count($list)]);
}

// GET agent_balance — cash balance for agent (or admin querying ?agent_id=X)
if ($action === 'agent_balance') {
    $isAdmin = !empty($retailer['is_admin']);
    $aid     = $isAdmin ? (int)($_GET['agent_id'] ?? $retailerId) : $retailerId;
    $summary = $fieldAgent->getAgentSummary($aid);
    apiOk(['summary' => $summary]);
}

// POST agent_remittance_submit — agent submits remittance request
if ($action === 'agent_remittance_submit') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if ($idempotency->handleIfDuplicate()) exit;
    if (!$fieldAgent->isFieldAgent($retailer)) apiError('Field agent role required.', 403);
    $body   = getBody();
    $result = $fieldAgent->submitRemittance($body, $retailer);
    if ($result['success']) {
        $idempotency->storeCurrentResponse(201, $result);
        apiOk($result['data'] ?? [], $result['message'], 201);
    } else {
        apiError($result['message'], 400);
    }
}

// GET agent_remittances — list remittances
if ($action === 'agent_remittances') {
    $isAdmin = !empty($retailer['is_admin']);
    $aid     = $isAdmin ? (int)($_GET['agent_id'] ?? 0) : $retailerId;
    $status  = $_GET['status'] ?? '';
    $list    = $fieldAgent->getRemittances($aid, $isAdmin && $aid === 0, $status);
    apiOk(['remittances' => $list, 'total' => count($list)]);
}

// POST agent_remittance_approve — admin approves remittance (Rupesh / Nirav)
if ($action === 'agent_remittance_approve') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    if ($idempotency->handleIfDuplicate()) exit;
    $body   = getBody();
    $rid    = (int)($body['remittance_id'] ?? 0);
    if ($rid <= 0) apiError('Valid remittance_id required.');
    $result = $fieldAgent->approveRemittance($rid, $retailer);
    if ($result['success']) {
        $idempotency->storeCurrentResponse(200, $result);
        apiOk($result['data'] ?? [], $result['message']);
    } else {
        apiError($result['message'], 400);
    }
}

// POST agent_remittance_reject — admin rejects remittance
if ($action === 'agent_remittance_reject') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    $body   = getBody();
    $rid    = (int)($body['remittance_id'] ?? 0);
    $reason = trim($body['reason'] ?? '');
    if ($rid <= 0) apiError('Valid remittance_id required.');
    $result = $fieldAgent->rejectRemittance($rid, $reason, $retailer);
    if ($result['success']) apiOk([], $result['message']);
    else apiError($result['message'], 400);
}

// GET agent_daily_summary — daily collection summary (admin report)
if ($action === 'agent_daily_summary') {
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    $aid  = (int)($_GET['agent_id'] ?? 0);
    $days = min((int)($_GET['days'] ?? 30), 365);
    $data = $fieldAgent->getDailySummary($aid ?: null, $days);
    apiOk(['summary' => $data, 'total_days' => count($data)]);
}

// GET agent_pending_remittances — pending remittance report for accountants
if ($action === 'agent_pending_remittances') {
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    $data = $fieldAgent->getPendingRemittanceReport();
    apiOk(['pending' => $data, 'count' => count($data)]);
}

// GET agent_list — list all field agents (admin only)
if ($action === 'agent_list') {
    if (empty($retailer['is_admin'])) apiError('Admin access required.', 403);
    $agents = $fieldAgent->getAllFieldAgents();
    // Strip passwords from output
    $safe = array_map(fn($a) => array_diff_key($a, ['password' => 1, 'api_token' => 1]), $agents);
    apiOk(['agents' => array_values($safe)]);
}

// GET support_engineers — list all support-role retailers (support_leader or admin)
// Used by Route Manager to populate the agent picker
// GET retailer_list — all active retailers for advance picker (admin/accountant only)
if ($action === 'retailer_list' && $method === 'GET') {
    if (!$isManagerOrAdmin) apiError('Manager or admin access required.', 403);
    $all = $store->load('retailers.json') ?? [];
    $safe = array_values(array_filter(array_map(function($r) {
        if (empty($r['is_active'])) return null;
        return [
            'id'     => $r['id'],
            'name'   => $r['name']   ?? '',
            'role'   => $r['role']   ?? 'staff',
            'is_active' => !empty($r['is_active']),
        ];
    }, $all)));
    apiOk(['retailers' => $safe, 'count' => count($safe)]);
}

if ($action === 'support_engineers') {
    if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'support_leader')
        apiError('Support leader or admin only.', 403);
    $all = $store->load('retailers.json') ?? [];
    $supportRoles = ['support_engineer', 'support', 'support_leader'];
    $engineers = array_values(array_filter($all, function($r) use ($supportRoles) {
        return in_array($r['role'] ?? '', $supportRoles, true) && !empty($r['is_active']);
    }));
    $safe = array_map(function($r) {
        return [
            'id'    => $r['id'],
            'name'  => $r['name']  ?? '',
            'role'  => $r['role']  ?? '',
            'phone' => $r['phone'] ?? '',
            'email' => $r['email'] ?? '',
        ];
    }, $engineers);
    apiOk(['agents' => $safe]);
}

// POST update_customer_details — agent updates phone/email/address/username → syncs to UCRM CRM
if ($action === 'update_customer_details') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body  = getBody();
    $appId = (int)($body['application_id'] ?? 0);
    if (!$appId) apiError('application_id required.');

    $app = $store->findOne('kyc_applications.json', 'id', $appId);
    if (!$app) apiError('Application not found.', 404);
    // Agent can only update their own customers; admin can update any
    if ((int)($app['retailer_id'] ?? 0) !== $retailerId && !($retailer['is_admin'] ?? false))
        apiError('Access denied.', 403);

    $crmClientId = $app['crm_client_id'] ?? null;
    if (!$crmClientId) apiError('No CRM client linked to this application.');

    // Collect only the fields that were actually sent
    $updates     = [];
    $crmContacts = [];
    $localUpdates = [];

    $newMobile   = trim($body['mobile']   ?? '');
    $newEmail    = trim($body['email']    ?? '');
    $newAddress  = trim($body['address']  ?? '');
    $newUsername = trim($body['username'] ?? '');

    // Build UCRM patch payload — only include changed fields
    $crmPatch = [];

    if ($newMobile !== '' && $newMobile !== ($app['mobile'] ?? '')) {
        $crmContacts[]         = ['phone' => $newMobile, 'email' => $newEmail ?: ($app['email'] ?? ''),
                                  'name'  => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''))];
        $localUpdates['mobile']  = $newMobile;
        $updates[]               = "📞 Mobile → {$newMobile}";
    }
    if ($newEmail !== '' && $newEmail !== ($app['email'] ?? '')) {
        $crmContacts[]         = ['phone' => $newMobile ?: ($app['mobile'] ?? ''), 'email' => $newEmail,
                                  'name'  => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''))];
        $localUpdates['email']   = $newEmail;
        $updates[]               = "📧 Email → {$newEmail}";
    }
    if ($newAddress !== '' && $newAddress !== ($app['address_1'] ?? '')) {
        $crmPatch['street1']       = $newAddress;
        $localUpdates['address_1'] = $newAddress;
        $updates[]                 = "🏠 Address → {$newAddress}";
    }
    if ($newUsername !== '' && $newUsername !== ($app['username'] ?? '')) {
        $crmPatch['username']          = $newUsername;
        $localUpdates['username']      = $newUsername;
        $updates[]                     = "👤 Username → {$newUsername}";
    }

    if (!empty($crmContacts)) {
        // UCRM contacts array — deduplicated: use the latest phone+email combo
        $crmPatch['contacts'] = [array_pop($crmContacts)];
    }

    if (empty($crmPatch) && empty($localUpdates)) {
        apiError('No changes detected — nothing to update.');
    }

    // Push to UCRM
    $patchResult = $crm->patch("clients/{$crmClientId}", $crmPatch);
    if ($patchResult === null) {
        apiError('CRM update failed: ' . json_encode($crm->getLastError()), 502);
    }

    // Update local application record
    $localUpdates['last_detail_update']    = date('Y-m-d H:i:s');
    $localUpdates['last_update_by']        = $retailer['name'];
    $localUpdates['detail_update_history'] = array_merge(
        $app['detail_update_history'] ?? [],
        [['by' => $retailer['name'], 'at' => date('Y-m-d H:i:s'), 'changes' => implode(', ', $updates)]]
    );
    $store->updateOne('kyc_applications.json', 'id', $appId, $localUpdates);

    logActivity($dataDir, 'customer_details_updated',
        "CRM #{$crmClientId} — " . ($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''),
        implode(' | ', $updates) . ' | By: ' . ($retailer['name'] ?? '')
    );

    apiOk([
        'crm_client_id' => $crmClientId,
        'changes'       => $updates,
        'updated_at'    => date('Y-m-d H:i:s'),
    ], '✅ Customer updated: ' . implode(', ', $updates));
}


// ── POST log_call — agent logs a call outcome against a lead ─────────────────
// Called when agent taps 📞 Call button and submits outcome
if ($action === 'log_call') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body   = getBody();
    $leadId = (int)($body['lead_id'] ?? 0);
    if (!$leadId) apiError('lead_id required.');

    $leads  = $store->load('leads.json') ?? [];
    $found  = false;

    $outcome    = trim($body['outcome']  ?? 'no_answer');  // answered|no_answer|busy|callback|interested|not_interested
    $note       = trim($body['note']     ?? '');
    $duration   = (int)($body['duration_seconds'] ?? 0);
    $followUp   = trim($body['follow_up_date'] ?? '');
    $newStatus  = trim($body['new_status'] ?? '');
    $validStatuses = ['open','contacted','interested','quoted','qualified','lost'];
    $validOutcomes = ['answered','no_answer','busy','callback','interested','not_interested','voicemail'];

    if (!in_array($outcome, $validOutcomes)) $outcome = 'no_answer';

    foreach ($leads as &$l) {
        if ((int)($l['id'] ?? 0) !== $leadId) continue;

        // Access check
        if ((int)($l['retailer_id'] ?? 0) !== $retailerId &&
            (int)($l['assigned_to'] ?? 0) !== $retailerId &&
            !($retailer['is_admin'] ?? false)) {
            apiError('Access denied.', 403);
        }

        // Build call log entry
        $callEntry = [
            'id'       => count($l['call_log'] ?? []) + 1,
            'by'       => $retailer['name'],
            'by_id'    => $retailerId,
            'at'       => date('Y-m-d H:i:s'),
            'outcome'  => $outcome,
            'note'     => $note,
            'duration' => $duration,
        ];
        if (!isset($l['call_log'])) $l['call_log'] = [];
        $l['call_log'][] = $callEntry;

        // Update call stats
        $l['total_calls']  = count($l['call_log']);
        $l['last_caller']  = $retailer['name'];
        $l['last_call_at'] = $callEntry['at'];

        // Update status if provided
        if ($newStatus && in_array($newStatus, $validStatuses)) {
            $l['status'] = $newStatus;
        }

        // Outcome → auto status map
        if ($newStatus === '' && in_array($outcome, ['answered','interested'])) {
            if (($l['status'] ?? '') === 'open') $l['status'] = 'contacted';
        }
        if ($outcome === 'not_interested') $l['status'] = 'lost';

        // Update follow-up date
        if ($followUp) $l['follow_up_date'] = $followUp;

        // Clear stale flags on any update
        unset($l['stale_flagged']);
        unset($l['stale_reassigned']);
        $l['updated_at'] = date('Y-m-d H:i:s');

        // Add to lead history
        if (!isset($l['history'])) $l['history'] = [];
        $outcomeLabel = [
            'answered'=>'Answered','no_answer'=>'No Answer','busy'=>'Busy',
            'callback'=>'Callback Requested','interested'=>'Interested',
            'not_interested'=>'Not Interested','voicemail'=>'Voicemail',
        ][$outcome] ?? $outcome;
        $histNote = "📞 Call: {$outcomeLabel}" . ($duration > 0 ? " (" . round($duration/60, 1) . "m)" : '') . ($note ? " — {$note}" : '');
        $l['history'][] = [
            'status' => $l['status'],
            'by'     => $retailer['name'],
            'at'     => date('Y-m-d H:i:s'),
            'note'   => $histNote,
        ];

        $found = true;
        break;
    }
    unset($l);

    if (!$found) apiError('Lead not found.', 404);
    $store->save('leads.json', $leads);

    // Re-fetch updated lead
    $updatedLead = null;
    foreach ($leads as $l) { if ((int)($l['id']??0) === $leadId) { $updatedLead = $l; break; } }

    // ── 3-strike auto-dead ────────────────────────────────────────────────
    $maxAttempts     = (int)($config['lead_max_attempts'] ?? 3);
    $noAnswerOutcomes = ['no_answer','busy','voicemail'];
    if (in_array($outcome, $noAnswerOutcomes, true) && $updatedLead) {
        $naCount = count(array_filter($updatedLead['call_log'] ?? [], function($cl) use ($noAnswerOutcomes) {
            return in_array($cl['outcome'] ?? '', $noAnswerOutcomes, true);
        }));
        if ($naCount >= $maxAttempts) {
            foreach ($leads as &$l2) {
                if ((int)($l2['id'] ?? 0) !== $leadId) continue;
                $l2['status']           = 'lost';
                $l2['lost_reason']      = 'auto_dead_max_attempts';
                $l2['lost_at']          = date('Y-m-d H:i:s');
                $l2['wa_farewell_needed'] = 1;
                break;
            }
            unset($l2);
            $store->save('leads.json', $leads);
            // Re-fetch
            foreach ($leads as $l3) { if ((int)($l3['id']??0) === $leadId) { $updatedLead = $l3; break; } }
        }
    }

    // ── Reset alert flags (called = no more urgency) ──────────────────────
    foreach ($leads as &$l4) {
        if ((int)($l4['id'] ?? 0) !== $leadId) continue;
        unset($l4['wa_45min_sent'], $l4['wa_60min_sent']);
        break;
    }
    unset($l4);
    $store->save('leads.json', $leads);

    // ── Auto-WA to customer based on outcome ─────────────────────────────
    $leadPhone = preg_replace('/[^0-9+]/', '', $updatedLead['phone'] ?? '');
    $firstName = explode(' ', trim($updatedLead['customer_name'] ?? 'there'))[0] ?: 'there';
    if ($leadPhone) {
        try {
            $waMsg = '';
            if (in_array($outcome, $noAnswerOutcomes, true)) {
                if (!empty($updatedLead['wa_farewell_needed'])) {
                    $waMsg = "Hi {$firstName}! We tried reaching you a few times about DishNet Fiber.\n\n"
                           . "We'll leave it here for now — but whenever you're ready for fast fiber internet, we're just a message away 🌐\n\n"
                           . "wa.me/211923400000";
                } else {
                    $waMsg = "Hi {$firstName}! 👋 We just tried calling you from DishNet Fiber.\n\n"
                           . "We'll try again soon. If you'd like us to call at a specific time, just reply with a time and we'll call exactly then ⏰";
                }
            } elseif ($outcome === 'interested') {
                $waMsg = "Hi {$firstName}! 🎉 Great talking to you.\n\n"
                       . "Here's what happens next:\n"
                       . "✅ Our fiber specialist will call you within 2 hours\n"
                       . "✅ He'll confirm your area coverage and pricing\n"
                       . "✅ If you're happy, we can schedule installation this week\n\n"
                       . "Any questions? Just reply here 💬";
            } elseif ($outcome === 'not_interested') {
                $waMsg = "Hi {$firstName}, thanks for your time! 🙏\n\n"
                       . "No problem at all. Whenever you need fast fiber internet in future, we're just a message away 🌐\n\n"
                       . "wa.me/211923400000";
            } elseif ($outcome === 'callback' && !empty($updatedLead['follow_up_date'])) {
                $waMsg = "Hi {$firstName}! Thanks for speaking with us 📞\n\n"
                       . "We'll call you back on *" . $updatedLead['follow_up_date'] . "* as agreed.\n\n"
                       . "If this time doesn't work, just reply and we'll reschedule 😊";
            }
            if ($waMsg) {
                require_once dirname(__DIR__) . '/lib/NotificationService.php';
                $notifyAuto = new NotificationService($store, $config);
                $notifyAuto->sendRaw($leadPhone, $waMsg, 'lead_outcome_auto_wa');
            }
        } catch (\Throwable $waEx) {
            error_log('[log_call] Auto-WA failed: ' . $waEx->getMessage());
        }
    }

    logActivity($dataDir, 'call_logged',
        "Lead #{$leadId} — {$outcome}",
        "By: {$retailer['name']}" . ($note ? " | Note: {$note}" : '')
    );

    apiOk([
        'lead_id'     => $leadId,
        'call_count'  => $updatedLead['total_calls'] ?? 1,
        'outcome'     => $outcome,
        'new_status'  => $updatedLead['status'] ?? '',
        'auto_dead'   => !empty($updatedLead['wa_farewell_needed']),
    ], "📞 Call logged: {$outcomeLabel}");
}

// ── POST bulk_archive_leads — admin only ──────────────────────────────────────
if ($action === 'bulk_archive_leads') {
    if ($method !== 'POST') apiError('POST required.', 405);
    if (!($retailer['is_admin'] ?? false)) apiError('Admin only.', 403);
    $body       = getBody();
    $beforeDate = trim($body['before_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) apiError('Invalid date format.');
    $leads    = $store->load('leads.json') ?? [];
    $archived = 0;
    $now      = date('Y-m-d H:i:s');
    foreach ($leads as &$l) {
        if (!empty($l['archived'])) continue;
        $created = substr($l['created_at'] ?? '9999-12-31', 0, 10);
        if ($created < $beforeDate) {
            $l['archived']    = 1;
            $l['archived_at'] = $now;
            $l['archived_by'] = $retailer['name'] ?? 'Admin';
            $archived++;
        }
    }
    unset($l);
    $store->save('leads.json', $leads);
    logActivity($dataDir, 'leads_archived', "{$archived} leads archived before {$beforeDate}", "By: {$retailer['name']}");
    apiOk(['archived' => $archived, 'before_date' => $beforeDate], "🗄️ {$archived} leads archived");
}

// ── POST conv_to_lead — manually convert a marketing conversation to a lead ───
if ($action === 'conv_to_lead') {
    if ($method !== 'POST') apiError('POST required.', 405);
    $body   = getBody();
    $convId = (int)($body['conv_id'] ?? 0);
    if (!$convId) apiError('conv_id required.');

    $pdo2 = $store->getPdo();

    // Load conversation
    $convStmt = $pdo2->prepare("SELECT * FROM wa_conversations WHERE id = ?");
    $convStmt->execute([$convId]);
    $conv2 = $convStmt->fetch(PDO::FETCH_ASSOC);
    if (!$conv2) apiError('Conversation not found.', 404);
    if (!empty($conv2['lead_id'])) apiError('Lead already created for this conversation (#' . $conv2['lead_id'] . ').');
    if (!empty($conv2['crm_client_id'])) apiError('This is an existing CRM customer — not a lead.');

    $phone3 = $conv2['phone'] ?? '';
    if (empty($phone3)) apiError('No phone number on conversation.');

    // Dedup: check existing leads
    $allLeads3 = $store->load('leads.json') ?? [];
    $suffix3 = substr(preg_replace('/[^0-9]/', '', $phone3), -9);
    foreach ($allLeads3 as $el3) {
        $es = substr(preg_replace('/[^0-9]/', '', $el3['phone'] ?? ''), -9);
        if ($es && $es === $suffix3 && !in_array($el3['status'] ?? '', ['won','lost','dead'], true)) {
            // Link conv to existing lead
            $pdo2->prepare("UPDATE wa_conversations SET lead_id = ? WHERE id = ?")->execute([(int)$el3['id'], $convId]);
            apiOk(['lead_id' => (int)$el3['id'], 'assigned_to' => $el3['assigned_name'] ?? ''], 'Linked to existing lead #' . $el3['id']);
        }
    }

    // Smart-assign: lightest loaded sales agent
    $salesRoles3  = ['sales','field_agent','sales_staff'];
    $retailers3   = $store->load('retailers.json') ?? [];
    $salesAgents3 = array_filter($retailers3, fn($r) => !empty($r['is_active']) && in_array($r['role'] ?? '', $salesRoles3, true));
    $assignTo3    = null;
    if (!empty($salesAgents3)) {
        $loads3 = [];
        foreach ($salesAgents3 as $ag3) {
            $aid3 = (int)$ag3['id'];
            $loads3[$aid3] = count(array_filter($allLeads3, fn($l) =>
                (int)($l['assigned_to'] ?? 0) === $aid3 &&
                !in_array($l['status'] ?? '', ['won','lost','dead'], true) &&
                empty($l['archived'])
            ));
        }
        $lightId3 = array_keys($loads3, min($loads3))[0];
        foreach ($salesAgents3 as $ag3) {
            if ((int)$ag3['id'] === $lightId3) { $assignTo3 = $ag3; break; }
        }
    }

    $now3 = date('Y-m-d H:i:s');
    $newLead3 = [
        'id'               => $store->nextId('leads.json'),
        'customer_name'    => $conv2['display_name'] ?? $phone3,
        'phone'            => $phone3,
        'service_type'     => 'fiber',
        'source'           => 'whatsapp_marketing',
        'source_detail'    => 'Manually converted from WA Inbox',
        'priority'         => 'high',
        'notes'            => $conv2['last_message_preview'] ?? '',
        'retailer_id'      => $assignTo3 ? (int)$assignTo3['id'] : 0,
        'assigned_to'      => $assignTo3 ? (int)$assignTo3['id'] : null,
        'assigned_name'    => $assignTo3 ? ($assignTo3['name'] ?? '') : '',
        'assigned_by'      => $retailer['name'] ?? 'Admin',
        'assigned_at'      => $now3,
        'daily_assign_to'  => $assignTo3 ? (int)$assignTo3['id'] : null,
        'daily_assign_name'=> $assignTo3 ? ($assignTo3['name'] ?? '') : '',
        'daily_assign_date'=> date('Y-m-d'),
        'status'           => 'open',
        'wa_assigned_notified' => 0,
        'created_at'       => $now3,
        'updated_at'       => $now3,
    ];
    $allLeads3[] = $newLead3;
    $store->save('leads.json', $allLeads3);

    // Link conversation to lead
    $pdo2->prepare("UPDATE wa_conversations SET lead_id = ? WHERE id = ?")->execute([(int)$newLead3['id'], $convId]);

    apiOk(['lead_id' => (int)$newLead3['id'], 'assigned_to' => $assignTo3['name'] ?? 'Unassigned'], "✅ Lead #{$newLead3['id']} created");
}
if ($action === 'upload_call_recording') {
    // Accepts multipart/form-data: recording file + lead_id or phone
    // Called automatically by BCR upload script after call ends
    if ($method !== 'POST') apiError('POST required.', 405);

    $leadId    = (int)($_POST['lead_id']   ?? 0);
    $phone     = trim($_POST['phone']      ?? '');
    $duration  = (int)($_POST['duration']  ?? 0);
    $direction = trim($_POST['direction']  ?? 'outbound'); // inbound|outbound

    // Find lead by id or phone
    $leads = $store->load('leads.json') ?? [];
    $foundIdx = null;
    foreach ($leads as $i => $l) {
        if ($leadId && (int)($l['id']??0) === $leadId) { $foundIdx = $i; break; }
        if ($phone && !empty($l['phone'])) {
            $clean = fn($p) => preg_replace('/[^0-9]/','',$p);
            if ($clean($l['phone']) === $clean($phone)) { $foundIdx = $i; break; }
        }
    }

    // Store recording even if no matching lead (admin can review later)
    $recDir = $dataDir . '/call_recordings';
    if (!is_dir($recDir)) @mkdir($recDir, 0750, true);

    if (empty($_FILES['recording']['tmp_name'])) apiError('No recording file uploaded.');

    $ext      = pathinfo($_FILES['recording']['name'], PATHINFO_EXTENSION) ?: 'aac';
    $safeExt  = in_array(strtolower($ext), ['aac','m4a','mp3','ogg','wav','3gp','opus']) ? strtolower($ext) : 'aac';
    $filename = date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/', '', strtolower($retailer['name']));
    if ($phone) $filename .= '_' . preg_replace('/[^0-9]/', '', $phone);
    $filename .= '.' . $safeExt;
    $destPath = $recDir . '/' . $filename;

    if (!move_uploaded_file($_FILES['recording']['tmp_name'], $destPath)) {
        apiError('Failed to save recording file.', 500);
    }

    $relPath = 'call_recordings/' . $filename;

    // Attach to lead if found
    if ($foundIdx !== null) {
        $l = &$leads[$foundIdx];
        if (!isset($l['call_log'])) $l['call_log'] = [];

        // Find most recent call entry by this agent and attach recording
        $attached = false;
        for ($i = count($l['call_log'])-1; $i >= 0; $i--) {
            if (($l['call_log'][$i]['by_id'] ?? 0) === $retailerId) {
                $l['call_log'][$i]['recording'] = $relPath;
                $l['call_log'][$i]['duration']  = $duration ?: ($l['call_log'][$i]['duration'] ?? 0);
                $attached = true;
                break;
            }
        }
        if (!$attached) {
            // New entry — call came in but agent hadn't logged it
            $l['call_log'][] = [
                'id'        => count($l['call_log']) + 1,
                'by'        => $retailer['name'],
                'by_id'     => $retailerId,
                'at'        => date('Y-m-d H:i:s'),
                'outcome'   => 'answered',
                'direction' => $direction,
                'note'      => 'Auto-logged from recording upload',
                'duration'  => $duration,
                'recording' => $relPath,
            ];
            $l['total_calls'] = count($l['call_log']);
            $l['last_caller'] = $retailer['name'];
            $l['last_call_at']= date('Y-m-d H:i:s');
        }
        unset($l['stale_flagged']);
        unset($l['stale_reassigned']);
        $l['updated_at'] = date('Y-m-d H:i:s');
        unset($l);
        $store->save('leads.json', $leads);
    }

    // Log the recording in standalone recordings log too
    $recLog = $store->load('call_recordings_log.json') ?? [];
    $recLog[] = [
        'id'        => count($recLog) + 1,
        'agent'     => $retailer['name'],
        'agent_id'  => $retailerId,
        'lead_id'   => $foundIdx !== null ? (int)($leads[$foundIdx]['id'] ?? 0) : null,
        'phone'     => $phone,
        'direction' => $direction,
        'duration'  => $duration,
        'file'      => $relPath,
        'recorded_at'=> date('Y-m-d H:i:s'),
    ];
    if (count($recLog) > 50000) $recLog = array_slice($recLog, -50000);
    $store->save('call_recordings_log.json', $recLog);

    apiOk(['file' => $relPath, 'lead_id' => $foundIdx !== null ? (int)($leads[$foundIdx]['id'] ?? 0) : null],
          '✅ Recording uploaded' . ($foundIdx !== null ? ' and attached to lead.' : ' (no lead matched — stored for review).'));
}

// ── GET get_call_recording — serve a recording file ──────────────────────────
if ($action === 'get_call_recording') {
    if ($method !== 'GET') apiError('GET required.', 405);
    // Accept token via ?token= for <audio src> embedding (Bearer header also works)
    if (empty($retailer['id'])) {
        $qToken = trim($_GET['token'] ?? '');
        if ($qToken) {
            foreach (($store->load('retailers.json') ?? []) as $r) {
                if (($r['api_token'] ?? '') === $qToken && !empty($r['is_active'])) {
                    $retailer = $r; $retailerId = (int)$r['id']; break;
                }
            }
        }
        if (empty($retailer['id'])) apiError('Unauthorised.', 401);
    }
    $file = trim($_GET['file'] ?? '');
    if (!$file) apiError('file param required.');

    // Security: only allow access to call_recordings/ dir, no path traversal
    $safe = basename($file);
    $path = $dataDir . '/call_recordings/' . $safe;

    if (!file_exists($path)) apiError('Recording not found.', 404);

    $ext  = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
    $mimeMap = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4','ogg'=>'audio/ogg','wav'=>'audio/wav','opus'=>'audio/opus','3gp'=>'audio/3gpp'];
    $mime = $mimeMap[$ext] ?? 'audio/aac';

    header("Content-Type: {$mime}");
    header("Content-Length: " . filesize($path));
    header("Content-Disposition: inline; filename=\"" . basename($safe) . "\"");
    header("Accept-Ranges: bytes");
    header("Cache-Control: private, max-age=86400");
    readfile($path);
    exit;
}

// ── GET lead_call_queue — agent's prioritised call list ───────────────────────
if ($action === 'lead_call_queue') {
    if ($method !== 'GET') apiError('GET required.', 405);

    $leads   = $store->load('leads.json') ?? [];
    $openSt  = ['open','contacted','interested','quoted'];
    $todayStr= date('Y-m-d');
    $nowTs   = time();

    $queue = array_values(array_filter($leads, fn($l) =>
        in_array($l['status'] ?? '', $openSt) &&
        ((int)($l['assigned_to']??0) === $retailerId || (int)($l['retailer_id']??0) === $retailerId)
    ));

    // Score each lead for priority order
    foreach ($queue as &$ql) {
        $score = 0;
        // Priority
        $priMap = ['high'=>30,'medium'=>10,'low'=>0]; $score += $priMap[$ql['priority'] ?? 'medium'] ?? 10;
        // Follow-up overdue
        if (!empty($ql['follow_up_date'])) {
            if ($ql['follow_up_date'] < $todayStr) $score += 50;
            if ($ql['follow_up_date'] === $todayStr) $score += 40;
        }
        // Never called
        if (empty($ql['call_log'])) $score += 20;
        // Status weight
        $stMap = ['interested'=>15,'contacted'=>5,'open'=>10]; $score += $stMap[$ql['status']??'open'] ?? 0;
        // Stale flag
        if (!empty($ql['stale_flagged'])) $score += 25;
        // Older last call = higher priority
        $lastCallTs = 0;
        foreach ($ql['call_log'] ?? [] as $cl) $lastCallTs = max($lastCallTs, strtotime($cl['at']??'') ?: 0);
        if ($lastCallTs > 0) {
            $hoursAgo = ($nowTs - $lastCallTs) / 3600;
            $score += min(20, (int)($hoursAgo / 12));
        }
        $ql['_score'] = $score;
        $ql['_call_count'] = count($ql['call_log'] ?? []);
        $ql['_last_call_at'] = $lastCallTs ? date('Y-m-d H:i', $lastCallTs) : null;
    }
    unset($ql);

    usort($queue, fn($a, $b) => $b['_score'] - $a['_score']);

    // Return compact queue (no history blob to keep response fast)
    $compact = array_map(fn($l) => [
        'id'            => $l['id'],
        'name'          => $l['customer_name'] ?? '',
        'phone'         => $l['phone'] ?? '',
        'service'       => $l['service_type'] ?? '',
        'address'       => $l['address'] ?? '',
        'status'        => $l['status'] ?? '',
        'priority'      => $l['priority'] ?? 'medium',
        'follow_up'     => $l['follow_up_date'] ?? null,
        'call_count'    => $l['_call_count'],
        'last_call_at'  => $l['_last_call_at'],
        'stale_flagged' => !empty($l['stale_flagged']),
        'notes'         => $l['notes'] ?? '',
        'score'         => $l['_score'],
    ], $queue);

    apiOk(['queue' => $compact, 'count' => count($compact)]);
}


// ── POST run_leads_cron — manually trigger lead cron (admin only) ─────────────
if ($action === 'run_leads_cron' && $method === 'POST') {
    if (!($retailer['is_admin'] ?? false)) apiError('Admin only.', 403);
    $cronPath = __DIR__ . '/../cron_leads.php';
    if (!file_exists($cronPath)) apiError('cron_leads.php not found.', 404);
    exec('php ' . escapeshellarg($cronPath) . ' 2>&1', $output, $code);
    apiOk(['exit_code' => $code, 'output' => implode("\n", array_slice($output, -20))], 'Cron run complete.');
}

// ── GET call_recordings — list recordings (admin only, paginated) ─────────────
if ($action === 'call_recordings' && $method === 'GET') {
    if (!($retailer['is_admin'] ?? false)) apiError('Admin only.', 403);
    $log     = $store->load('call_recordings_log.json') ?? [];
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 50)));
    $page    = max(1, (int)($_GET['page'] ?? 1));
    // Optional filters: ?agent_id=3 &phone=211 &date=2026-03-08
    $fAgent  = trim($_GET['agent_id'] ?? '');
    $fPhone  = trim($_GET['phone']    ?? '');
    $fDate   = trim($_GET['date']     ?? '');
    if ($fAgent || $fPhone || $fDate) {
        $log = array_values(array_filter($log, function($r) use ($fAgent, $fPhone, $fDate) {
            if ($fAgent && (string)($r['agent_id'] ?? '') !== $fAgent) return false;
            if ($fPhone && strpos(preg_replace('/[^0-9]/','',$r['phone']??''),
                                  preg_replace('/[^0-9]/','',$fPhone)) === false) return false;
            if ($fDate  && !str_starts_with($r['recorded_at'] ?? '', $fDate)) return false;
            return true;
        }));
    }
    $total = count($log);
    $log   = array_reverse($log);   // newest first
    $paged = array_slice($log, ($page - 1) * $perPage, $perPage);
    foreach ($paged as &$r) {
        $path = $dataDir . '/' . ($r['file'] ?? '');
        $r['exists']   = file_exists($path);
        $r['size_kb']  = $r['exists'] ? round(filesize($path)/1024, 1) : 0;
        $r['play_url'] = '?action=get_call_recording&file=' . urlencode(basename($r['file'] ?? ''));
    }
    unset($r);
    apiOk([
        'recordings' => $paged,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'pages'      => (int)ceil(max(1,$total) / $perPage),
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// ── FIELD STAFF TRACKING & ROUTE MANAGEMENT ─────────────────────────────────
// ════════════════════════════════════════════════════════════════════════════

// ── POST location_ping — staff app sends GPS every 60s ───────────────────────
// Body: { lat, lon, accuracy?, battery?, speed?, heading?, job_id? }
if ($action === 'location_ping' && $method === 'POST') {
    $lat = (float)($body['lat'] ?? 0);
    $lon = (float)($body['lon'] ?? 0);
    if (!$lat || !$lon) apiError('lat and lon required.', 422);

    $agentId   = (int)($retailer['id'] ?? 0);
    $agentName = $retailer['name'] ?? 'Unknown';
    $now       = date('Y-m-d H:i:s');

    // Live location store — one record per agent (overwrite)
    $live = $store->load('staff_live_locations.json') ?? [];
    $live[$agentId] = [
        'agent_id'   => $agentId,
        'agent_name' => $agentName,
        'lat'        => $lat,
        'lon'        => $lon,
        'accuracy'   => (float)($body['accuracy'] ?? 0),
        'speed'      => (float)($body['speed'] ?? 0),
        'heading'    => (float)($body['heading'] ?? 0),
        'battery'    => (int)($body['battery'] ?? -1),
        'job_id'     => (int)($body['job_id'] ?? 0),
        'updated_at' => $now,
        'is_online'  => true,
    ];
    $store->save('staff_live_locations.json', $live);

    // Trail log — append (keep last 500 pings per agent, rolling)
    $trailKey = "staff_trail_{$agentId}.json";
    $trail = $store->load($trailKey) ?? [];
    $trail[] = [
        'lat'  => $lat, 'lon' => $lon,
        'at'   => $now,
        'j'    => (int)($body['job_id'] ?? 0),  // job context at ping time
        'sp'   => (float)($body['speed'] ?? 0),
        'bat'  => (int)($body['battery'] ?? -1),
    ];
    if (count($trail) > 500) $trail = array_slice($trail, -500);
    $store->save($trailKey, $trail);

    apiOk(['saved' => true, 'server_time' => $now]);
}

// ── POST checkin — staff arrives at job site ──────────────────────────────────
// Body: { job_id, lat, lon, note? }
if ($action === 'job_checkin' && $method === 'POST') {
    $jobId  = (int)($body['job_id'] ?? 0);
    $lat    = (float)($body['lat'] ?? 0);
    $lon    = (float)($body['lon'] ?? 0);
    if (!$jobId) apiError('job_id required.', 422);

    $agentId   = (int)($retailer['id'] ?? 0);
    $agentName = $retailer['name'] ?? 'Unknown';
    $now       = date('Y-m-d H:i:s');

    $checkins = $store->load('job_checkins.json') ?? [];
    // Key = jobId_agentId to allow multiple agents on same job
    $ck = "j{$jobId}_a{$agentId}";
    $checkins[$ck] = [
        'job_id'       => $jobId,
        'agent_id'     => $agentId,
        'agent_name'   => $agentName,
        'checkin_at'   => $now,
        'checkin_lat'  => $lat,
        'checkin_lon'  => $lon,
        'checkout_at'  => null,
        'checkout_lat' => null,
        'checkout_lon' => null,
        'duration_min' => null,
        'note'         => trim($body['note'] ?? ''),
        'status'       => 'checked_in',
    ];
    $store->save('job_checkins.json', $checkins);

    // WhatsApp notification to admin/support leader
    $admins = array_filter($store->load('retailers.json') ?? [],
        fn($r) => ($r['is_admin'] ?? false) || ($r['role'] ?? '') === 'support_leader');
    foreach (array_slice($admins, 0, 2) as $adm) {
        $phone = preg_replace('/[^0-9]/', '', $adm['phone'] ?? '');
        if ($phone) {
            $notify->sendWhatsApp($phone,
                "✅ *Check-In* — {$agentName} arrived at Job #{$jobId}\n" .
                ($lat ? "📍 https://maps.google.com/?q={$lat},{$lon}\n" : '') .
                "🕐 {$now}");
        }
    }

    apiOk(['checked_in' => true, 'checkin_at' => $now]);
}

// ── POST checkout — staff completes job ──────────────────────────────────────
// Body: { job_id, lat, lon, note? }
if ($action === 'job_checkout' && $method === 'POST') {
    $jobId = (int)($body['job_id'] ?? 0);
    $lat   = (float)($body['lat'] ?? 0);
    $lon   = (float)($body['lon'] ?? 0);
    if (!$jobId) apiError('job_id required.', 422);

    $agentId   = (int)($retailer['id'] ?? 0);
    $agentName = $retailer['name'] ?? 'Unknown';
    $now       = date('Y-m-d H:i:s');

    $checkins = $store->load('job_checkins.json') ?? [];
    $ck = "j{$jobId}_a{$agentId}";

    if (!isset($checkins[$ck])) {
        // Auto-create check-in if missing
        $checkins[$ck] = ['job_id' => $jobId, 'agent_id' => $agentId, 'agent_name' => $agentName,
            'checkin_at' => $now, 'checkin_lat' => $lat, 'checkin_lon' => $lon, 'status' => 'auto'];
    }

    $checkinTime = strtotime($checkins[$ck]['checkin_at'] ?? $now);
    $durationMin = round((time() - $checkinTime) / 60);

    $checkins[$ck]['checkout_at']  = $now;
    $checkins[$ck]['checkout_lat'] = $lat;
    $checkins[$ck]['checkout_lon'] = $lon;
    $checkins[$ck]['duration_min'] = $durationMin;
    $checkins[$ck]['note']         = trim($body['note'] ?? $checkins[$ck]['note'] ?? '');
    $checkins[$ck]['status']       = 'completed';
    $store->save('job_checkins.json', $checkins);

    // Notify admin
    $admins = array_filter($store->load('retailers.json') ?? [],
        fn($r) => ($r['is_admin'] ?? false) || ($r['role'] ?? '') === 'support_leader');
    foreach (array_slice($admins, 0, 2) as $adm) {
        $phone = preg_replace('/[^0-9]/', '', $adm['phone'] ?? '');
        if ($phone) {
            $notify->sendWhatsApp($phone,
                "🏁 *Check-Out* — {$agentName} completed Job #{$jobId}\n" .
                "⏱️ Time on site: {$durationMin} min\n" .
                ($lat ? "📍 https://maps.google.com/?q={$lat},{$lon}" : ''));
        }
    }

    // ── Auto-create Accounting Invoice Job for Rupesh ────────────────────────
    // Every job completion triggers an invoice task so Rupesh can raise the
    // customer invoice in the CRM. Uses job_invoice_queue.json as the store.
    try {
        // Fetch CRM job details (client name, job type, etc.)
        $crmJobData = null;
        try {
            $crmApi = new CrmApiClient($dataDir);
            $crmJobData = $crmApi->get("scheduling/jobs/{$jobId}");
        } catch (\Throwable $e) { /* CRM unreachable — proceed with what we have */ }

        $clientName  = $crmJobData['client']['name']   ?? '';
        $clientId    = $crmJobData['client']['id']     ?? 0;
        $jobTitle    = $crmJobData['title']            ?? "Job #{$jobId}";
        $jobType     = $crmJobData['jobType']          ?? 'other';
        $jobNote     = trim($body['note'] ?? $checkins[$ck]['note'] ?? '');

        // Generate invoice job number: IJ-YYYYMMDD-NNN
        $ijQueue  = $store->load('job_invoice_queue.json') ?? [];
        $dateKey  = date('Ymd');
        $todayCount = count(array_filter($ijQueue, function($x) use ($dateKey) {
            return str_contains($x['job_no'] ?? '', "IJ-{$dateKey}-");
        }));
        $ijNo = 'IJ-' . $dateKey . '-' . str_pad((string)($todayCount + 1), 3, '0', STR_PAD_LEFT);

        $ijEntry = [
            'id'                => count($ijQueue) + 1,
            'job_no'            => $ijNo,
            'crm_job_id'        => $jobId,
            'crm_job_title'     => $jobTitle,
            'crm_job_type'      => $jobType,
            'client_name'       => $clientName,
            'client_id'         => (int)$clientId,
            'completed_by_id'   => $agentId,
            'completed_by_name' => $agentName,
            'completed_at'      => $now,
            'duration_min'      => $durationMin,
            'gps_lat'           => $lat ?: null,
            'gps_lon'           => $lon ?: null,
            'field_note'        => $jobNote,
            'status'            => 'pending',   // pending | invoiced | skipped
            'invoice_ref'       => '',           // set by Rupesh when done
            'invoice_note'      => '',
            'actioned_by'       => '',
            'actioned_at'       => null,
            'created_at'        => $now,
        ];
        $ijQueue[] = $ijEntry;
        $store->save('job_invoice_queue.json', $ijQueue);

        // WhatsApp Rupesh (accountant / admin with role 'accountant' or is_admin)
        $allRetailers = $store->load('retailers.json') ?? [];
        $rupeshList = array_filter($allRetailers, function($r) {
            return ($r['is_admin'] ?? false) || ($r['role'] ?? '') === 'accountant';
        });
        foreach (array_slice($rupeshList, 0, 2) as $acc) {
            $accPhone = preg_replace('/[^0-9]/', '', $acc['phone'] ?? '');
            if (!$accPhone) continue;
            $clientLine = $clientName ? "👤 *Client:* {$clientName}\n" : '';
            $typeLine   = $jobType && $jobType !== 'other' ? "🔧 *Job Type:* {$jobType}\n" : '';
            $noteLine   = $jobNote ? "📝 *Note:* {$jobNote}\n" : '';
            $notify->sendWhatsApp($accPhone,
                "🧾 *Invoice Required — {$ijNo}*\n" .
                "Job #{$jobId} completed by {$agentName}\n" .
                $clientLine .
                $typeLine .
                "⏱️ Duration: {$durationMin} min\n" .
                $noteLine .
                "\nPlease raise the invoice in UCRM."
            );
        }
    } catch (\Throwable $ijErr) {
        // Non-blocking — checkout still succeeds even if invoice job fails
        error_log("[InvoiceQueue] Failed for job #{$jobId}: " . $ijErr->getMessage());
    }

    apiOk(['checked_out' => true, 'duration_min' => $durationMin, 'checkout_at' => $now,
           'invoice_job_created' => true]);
}

// ── GET invoice_jobs — Rupesh sees pending invoice tasks ─────────────────────
if ($action === 'invoice_jobs' && $method === 'GET') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);

    $queue   = $store->load('job_invoice_queue.json') ?? [];
    $status  = $_GET['status'] ?? '';   // pending | invoiced | skipped | '' = all
    $limit   = min((int)($_GET['limit'] ?? 50), 200);

    // Filter
    if ($status) {
        $queue = array_values(array_filter($queue, fn($x) => ($x['status'] ?? '') === $status));
    }

    // Sort: newest first
    usort($queue, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    $queue = array_slice($queue, 0, $limit);

    $counts = ['pending' => 0, 'invoiced' => 0, 'skipped' => 0];
    $allQ = $store->load('job_invoice_queue.json') ?? [];
    foreach ($allQ as $x) {
        $s = $x['status'] ?? 'pending';
        if (isset($counts[$s])) $counts[$s]++;
    }

    apiOk(['jobs' => $queue, 'counts' => $counts, 'total' => count($queue)]);
}

// ── POST invoice_job_done — Rupesh marks job as invoiced or skipped ──────────
// Body: { job_no, action: 'invoiced'|'skipped', invoice_ref?, note? }
if ($action === 'invoice_job_done' && $method === 'POST') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);

    $jobNo     = trim($body['job_no']      ?? '');
    $act       = trim($body['action']      ?? 'invoiced');   // invoiced | skipped
    $invRef    = trim($body['invoice_ref'] ?? '');
    $note      = trim($body['note']        ?? '');

    if (!$jobNo) apiError('job_no required.', 422);
    if (!in_array($act, ['invoiced', 'skipped'], true)) apiError('action must be invoiced or skipped.', 422);

    $queue = $store->load('job_invoice_queue.json') ?? [];
    $found = false;
    foreach ($queue as &$entry) {
        if (($entry['job_no'] ?? '') === $jobNo) {
            $entry['status']       = $act;
            $entry['invoice_ref']  = $invRef;
            $entry['invoice_note'] = $note;
            $entry['actioned_by']  = $retailer['name'] ?? 'Accountant';
            $entry['actioned_at']  = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    unset($entry);

    if (!$found) apiError('Invoice job not found.', 404);
    $store->save('job_invoice_queue.json', $queue);

    apiOk(['updated' => true, 'job_no' => $jobNo, 'status' => $act]);
}

// ── GET invoice_jobs_stats — badge count of pending invoice tasks ─────────────
if ($action === 'invoice_jobs_stats' && $method === 'GET') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);
    $queue   = $store->load('job_invoice_queue.json') ?? [];
    $pending = count(array_filter($queue, fn($x) => ($x['status'] ?? 'pending') === 'pending'));
    apiOk(['pending' => $pending]);
}

// ── QUOTATION FLOWS B / C / D ─────────────────────────────────────────────────
// Requires QuotationService
require_once __DIR__ . '/../lib/QuotationService.php';

// ── POST send_lead_quote — Flow B: send quote for a lead ─────────────────────
// Body: { lead_id, items?: [...], create_crm_quote?: bool }
if ($action === 'send_lead_quote' && $method === 'POST') {
    $leadId = (int)($body['lead_id'] ?? 0);
    if (!$leadId) apiError('lead_id required.', 422);

    $leads = $store->load('leads.json') ?? [];
    $lead  = null;
    foreach ($leads as $l) { if ((int)($l['id'] ?? 0) === $leadId) { $lead = $l; break; } }
    if (!$lead) apiError('Lead not found.', 404);

    $items        = $body['items'] ?? [];
    $createCrm    = (bool)($body['create_crm_quote'] ?? false);
    $cfg          = $store->load('kyc_config.json') ?: [];
    $quotSvc      = new QuotationService($store, $dataDir, $cfg);

    $result = $quotSvc->sendLeadQuote($lead, $items, $retailer, $createCrm);
    $result['ok'] ? apiOk($result) : apiError($result['error'] ?? 'Failed.', 422);
}

// ── POST send_cash_proforma — Flow C: instant proforma for cash walk-in ──────
// Body: { customer_name, customer_phone, items: [...], amount_paid?: float }
if ($action === 'send_cash_proforma' && $method === 'POST') {
    $custName  = trim($body['customer_name']  ?? '');
    $custPhone = trim($body['customer_phone'] ?? '');
    $items     = $body['items'] ?? [];
    $paid      = (float)($body['amount_paid'] ?? 0);

    if (!$custName)  apiError('customer_name required.', 422);
    if (!$custPhone) apiError('customer_phone required.', 422);
    if (empty($items)) apiError('items required.', 422);

    $cfg     = $store->load('kyc_config.json') ?: [];
    $quotSvc = new QuotationService($store, $dataDir, $cfg);
    $result  = $quotSvc->sendCashSaleProforma($custName, $custPhone, $items, $paid, $retailer);
    $result['ok'] ? apiOk($result) : apiError($result['error'] ?? 'Failed.', 422);
}

// ── POST send_manual_quote — Flow D: agent manually triggers a quote ──────────
// Body: {
//   customer_name?, customer_phone?, crm_client_id?,
//   items: [{label, quantity, price, unit}],
//   note?, create_crm_quote?: bool
// }
if ($action === 'send_manual_quote' && $method === 'POST') {
    if (empty($body['items'])) apiError('items required.', 422);
    $cfg     = $store->load('kyc_config.json') ?: [];
    $quotSvc = new QuotationService($store, $dataDir, $cfg);
    $result  = $quotSvc->sendManualQuote($body, $retailer);
    $result['ok'] ? apiOk($result) : apiError($result['error'] ?? 'Failed.', 422);
}

// ── GET quote_logs — list sent quotations ────────────────────────────────────
if ($action === 'quote_logs' && $method === 'GET') {
    if (!$isManagerOrAdmin) apiError('Manager or admin required.', 403);
    $cfg     = $store->load('kyc_config.json') ?: [];
    $quotSvc = new QuotationService($store, $dataDir, $cfg);
    $quotes  = $quotSvc->getQuotes([
        'type'  => $_GET['type']  ?? '',
        'phone' => $_GET['phone'] ?? '',
        'limit' => min((int)($_GET['limit'] ?? 50), 200),
    ]);
    apiOk(['quotes' => $quotes, 'total' => count($quotes)]);
}

// ── POST preview_quote_message — return WA message text without sending ──────
// Useful for PWA to show the agent a preview before confirming send.
// Body: { customer_name, items, type?, note? }
if ($action === 'preview_quote_message' && $method === 'POST') {
    $name  = trim($body['customer_name'] ?? 'Customer');
    $items = $body['items'] ?? [];
    if (empty($items)) apiError('items required.', 422);

    $cfg     = $store->load('kyc_config.json') ?: [];
    $quotSvc = new QuotationService($store, $dataDir, $cfg);
    $total   = array_sum(array_map(fn($i) => (float)($i['price'] ?? 0) * max(1,(int)($i['quantity']??1)), $items));
    $ref     = 'PREVIEW-' . strtoupper(substr(md5(uniqid()), 0, 6));
    $msg     = $quotSvc->buildProformaMessage($ref, $name, $items, round($total, 2), [
        'type'  => $body['type'] ?? 'Quotation',
        'agent' => $retailer['name'] ?? '',
        'note'  => $body['note'] ?? '',
    ]);
    apiOk(['message' => $msg, 'total' => round($total, 2), 'ref' => $ref]);
}

// ── GET staff_live_map — admin sees all staff on map ─────────────────────────
if ($action === 'staff_live_map' && $method === 'GET') {
    if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'support_leader')
        apiError('Admin/support leader only.', 403);

    $live = $store->load('staff_live_locations.json') ?? [];
    $now  = time();

    // Mark offline if no ping in 5 minutes
    foreach ($live as &$agent) {
        $lastSeen = strtotime($agent['updated_at'] ?? '2000-01-01');
        $agent['is_online']  = ($now - $lastSeen) < 300;
        $agent['last_seen_min'] = round(($now - $lastSeen) / 60);
    }
    unset($agent);

    // Attach today's checkin status to each agent
    $checkins = $store->load('job_checkins.json') ?? [];
    $today = date('Y-m-d');
    foreach ($live as &$agent) {
        $agentCheckins = array_filter($checkins, fn($c) =>
            $c['agent_id'] === $agent['agent_id'] &&
            strpos($c['checkin_at'] ?? '', $today) === 0
        );
        $agent['jobs_today'] = count($agentCheckins);
        $agent['active_job'] = null;
        foreach ($agentCheckins as $c) {
            if ($c['status'] === 'checked_in') {
                $agent['active_job'] = $c['job_id'];
                break;
            }
        }
    }
    unset($agent);

    apiOk(['staff' => array_values($live), 'timestamp' => date('Y-m-d H:i:s')]);
}

// ── GET staff_trail — trail of one agent for today ───────────────────────────
if ($action === 'staff_trail' && $method === 'GET') {
    if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'support_leader')
        apiError('Admin/support leader only.', 403);

    $agentId = (int)($_GET['agent_id'] ?? 0);
    if (!$agentId) apiError('agent_id required.', 422);

    $trail = $store->load("staff_trail_{$agentId}.json") ?? [];

    // Filter to today only by default
    $since = $_GET['since'] ?? date('Y-m-d') . ' 00:00:00';
    $trail = array_values(array_filter($trail, fn($p) => ($p['at'] ?? '') >= $since));

    apiOk(['agent_id' => $agentId, 'trail' => $trail, 'count' => count($trail)]);
}

// ── GET job_checkins_today — admin sees all check-ins for today ───────────────
if ($action === 'job_checkins_today' && $method === 'GET') {
    if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'support_leader')
        apiError('Admin/support leader only.', 403);

    $date = $_GET['date'] ?? date('Y-m-d');
    $checkins = $store->load('job_checkins.json') ?? [];
    $filtered = array_values(array_filter($checkins,
        fn($c) => strpos($c['checkin_at'] ?? '', $date) === 0
    ));
    usort($filtered, fn($a, $b) => strcmp($a['checkin_at'], $b['checkin_at']));

    // Stats
    $stats = [
        'total_checkins' => count($filtered),
        'completed'      => count(array_filter($filtered, fn($c) => $c['status'] === 'completed')),
        'in_progress'    => count(array_filter($filtered, fn($c) => $c['status'] === 'checked_in')),
        'avg_duration'   => 0,
    ];
    $durations = array_filter(array_column($filtered, 'duration_min'));
    if ($durations) $stats['avg_duration'] = round(array_sum($durations) / count($durations));

    apiOk(['checkins' => $filtered, 'stats' => $stats, 'date' => $date]);
}

// ── POST assign_route — admin assigns ordered route to a staff member ─────────
// Body: { agent_id, job_ids: [1,2,3], date, note? }
if ($action === 'assign_route' && $method === 'POST') {
    if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'support_leader')
        apiError('Admin/support leader only.', 403);

    $agentId = (int)($body['agent_id'] ?? 0);
    $jobIds  = array_map('intval', $body['job_ids'] ?? []);
    $date    = $body['date'] ?? date('Y-m-d');
    if (!$agentId || empty($jobIds)) apiError('agent_id and job_ids required.', 422);

    // Load agent info
    $retailers = $store->load('retailers.json') ?? [];
    $agent = null;
    foreach ($retailers as $r) { if ((int)$r['id'] === $agentId) { $agent = $r; break; } }
    if (!$agent) apiError('Agent not found.', 404);

    $routes = $store->load('staff_routes.json') ?? [];
    $routeKey = "{$agentId}_{$date}";
    $routes[$routeKey] = [
        'agent_id'   => $agentId,
        'agent_name' => $agent['name'] ?? 'Unknown',
        'date'       => $date,
        'job_ids'    => $jobIds,
        'note'       => trim($body['note'] ?? ''),
        'assigned_by'=> $retailer['name'] ?? 'Admin',
        'assigned_at'=> date('Y-m-d H:i:s'),
        'status'     => 'active',
    ];
    $store->save('staff_routes.json', $routes);

    // WhatsApp notification to agent
    $phone = preg_replace('/[^0-9]/', '', $agent['phone'] ?? '');
    if ($phone) {
        $notify->sendWhatsApp($phone,
            "📋 *Route Assigned — {$date}*\n" .
            "You have " . count($jobIds) . " jobs for today.\n" .
            "Jobs: #" . implode(', #', $jobIds) . "\n" .
            ($body['note'] ? "Note: " . $body['note'] . "\n" : '') .
            "Open the app to see your route. 🗺️"
        );
    }

    apiOk(['route_assigned' => true, 'route_key' => $routeKey, 'job_count' => count($jobIds)]);
}

// ── GET my_route — staff gets their route for today ──────────────────────────
if ($action === 'my_route' && $method === 'GET') {
    $agentId = (int)($retailer['id'] ?? 0);
    $date    = $_GET['date'] ?? date('Y-m-d');
    $routes  = $store->load('staff_routes.json') ?? [];
    $route   = $routes["{$agentId}_{$date}"] ?? null;

    if (!$route) {
        apiOk(['route' => null, 'message' => 'No route assigned for today.']);
    }

    // Fetch job details from CRM for each job in route
    $crm = new CrmApiClient($dataDir);
    $jobDetails = [];
    foreach ($route['job_ids'] as $idx => $jid) {
        $job = $crm->get("scheduling/jobs/{$jid}");
        if ($job) {
            $checkins = $store->load('job_checkins.json') ?? [];
            $ck = "j{$jid}_a{$agentId}";
            $checkinStatus = $checkins[$ck]['status'] ?? 'pending';
            $jobDetails[] = [
                'order'         => $idx + 1,
                'id'            => $jid,
                'title'         => $job['title'] ?? "Job #{$jid}",
                'address'       => $job['address'] ?? '',
                'gps_lat'       => $job['gpsLat'] ?? null,
                'gps_lon'       => $job['gpsLon'] ?? null,
                'job_type'      => $job['jobType'] ?? 'other',
                'date'          => $job['date'] ?? $date,
                'client_name'   => $job['client']['name'] ?? '',
                'client_phone'  => $job['client']['phone'] ?? '',
                'checkin_status'=> $checkinStatus,  // pending/checked_in/completed
            ];
        }
    }

    apiOk([
        'route'    => $route,
        'jobs'     => $jobDetails,
        'date'     => $date,
        'progress' => [
            'total'     => count($jobDetails),
            'completed' => count(array_filter($jobDetails, fn($j) => $j['checkin_status'] === 'completed')),
        ]
    ]);
}

// ── GET staff_routes_admin — admin sees all routes for a date ─────────────────
if ($action === 'staff_routes_admin' && $method === 'GET') {
    if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'support_leader')
        apiError('Admin/support leader only.', 403);

    $date   = $_GET['date'] ?? date('Y-m-d');
    $routes = $store->load('staff_routes.json') ?? [];
    $filtered = array_values(array_filter($routes, fn($r) => $r['date'] === $date));

    // Attach live location to each
    $live = $store->load('staff_live_locations.json') ?? [];
    foreach ($filtered as &$route) {
        $aid = $route['agent_id'];
        $route['live_location'] = $live[$aid] ?? null;
        // Count completions
        $checkins = $store->load('job_checkins.json') ?? [];
        $done = 0;
        foreach ($route['job_ids'] as $jid) {
            $ck = "j{$jid}_a{$aid}";
            if (($checkins[$ck]['status'] ?? '') === 'completed') $done++;
        }
        $route['jobs_done'] = $done;
        $route['jobs_total'] = count($route['job_ids']);
    }
    unset($route);

    apiOk(['routes' => $filtered, 'date' => $date]);
}

// ════════════════════════════════════════════════════════════════════════════════
// BIDAL — FTTH Installation Tracking API (v3.6.9)
// ════════════════════════════════════════════════════════════════════════════════
// All endpoints: support_leader or admin only (except install_submit_data which any support can call)

$isLeaderOrAdmin = ($retailer['is_admin'] ?? false) || ($retailer['role'] ?? '') === 'support_leader';
$isSupportAny    = $isLeaderOrAdmin || in_array($retailer['role'] ?? '', ['support', 'support_engineer']);

// ── GET  install_queue — all pending tickets (unassigned + assigned, not complete) ──
if ($action === 'install_queue' && $method === 'GET') {
    if (!$isSupportAny) apiError('Support access required.', 403);
    $filter = $_GET['filter'] ?? 'pending'; // pending|in_progress|testing|completed|all
    $tickets = $splynxTickets->getJobs($filter);
    // Enrich with area
    apiOk(['tickets' => $tickets, 'count' => count($tickets), 'filter' => $filter]);
}

// ── GET  install_ticket — single ticket detail ───────────────────────────────
if ($action === 'install_ticket' && $method === 'GET') {
    if (!$isSupportAny) apiError('Support access required.', 403);
    $id = (int)($_GET['ticket_id'] ?? 0);
    if (!$id) apiError('ticket_id required.', 422);
    $ticket = $splynxTickets->getTicketById($id);
    if (!$ticket) apiError('Ticket not found.', 404);
    apiOk(['ticket' => $ticket]);
}

// ── GET  install_testing_queue — tickets marked ready for commissioning ──────
if ($action === 'install_testing_queue' && $method === 'GET') {
    if (!$isLeaderOrAdmin) apiError('Support leader access required.', 403);
    $queue = $splynxTickets->getTestingQueue();
    apiOk(['queue' => $queue, 'count' => count($queue)]);
}

// ── POST install_assign — assign an engineer to a ticket ────────────────────
if ($action === 'install_assign' && $method === 'POST') {
    if (!$isLeaderOrAdmin) apiError('Support leader access required.', 403);
    $ticketId     = (int)($body['ticket_id']      ?? 0);
    $engName      = trim($body['engineer_name']    ?? '');
    $engId        = trim($body['engineer_id']      ?? '');
    if (!$ticketId || !$engName) apiError('ticket_id and engineer_name required.', 422);
    $ok = $splynxTickets->assignEngineer($ticketId, $engName, $engId);
    $ok ? apiOk(['assigned' => true, 'engineer' => $engName]) : apiError('Ticket not found.', 404);
}

// ── POST install_submit_data — engineer saves ONU/signal/notes ──────────────
if ($action === 'install_submit_data' && $method === 'POST') {
    if (!$isSupportAny) apiError('Support access required.', 403);
    $ticketId = (int)($body['ticket_id'] ?? 0);
    if (!$ticketId) apiError('ticket_id required.', 422);
    $data = [
        'onu_serial'     => $body['onu_serial']     ?? '',
        'olt_port'       => $body['olt_port']        ?? '',
        'signal_db'      => $body['signal_db']       ?? null,
        'notes'          => $body['notes']            ?? '',
        'testing_status' => $body['testing_status']  ?? 'pending',
        'submitted_by'   => $retailer['name'] ?? 'Engineer',
    ];
    $ok = $splynxTickets->saveInstallData($ticketId, $data);
    $ok ? apiOk(['saved' => true]) : apiError('Ticket not found.', 404);
}

// ── POST install_ready — engineer marks job ready for Bidal review ───────────
if ($action === 'install_ready' && $method === 'POST') {
    if (!$isSupportAny) apiError('Support access required.', 403);
    $ticketId = (int)($body['ticket_id'] ?? 0);
    if (!$ticketId) apiError('ticket_id required.', 422);
    $ok = $splynxTickets->markReadyForCommissioning($ticketId, $retailer['name'] ?? 'Engineer');
    $ok ? apiOk(['ready' => true]) : apiError('Ticket not found.', 404);
}

// ── POST install_commission — Bidal approves and commissions ─────────────────
if ($action === 'install_commission' && $method === 'POST') {
    if (!$isLeaderOrAdmin) apiError('Support leader access required.', 403);
    $ticketId  = (int)($body['ticket_id'] ?? 0);
    $notes     = trim($body['notes']      ?? '');
    if (!$ticketId) apiError('ticket_id required.', 422);
    $ticket = $splynxTickets->getTicketById($ticketId);
    // Optionally activate in Splynx
    if ($ticket && !empty($ticket['splynx_service_id']) && $splynx->isConfigured()) {
        $splynxCusts->activateService((int)$ticket['splynx_service_id']);
    }
    $result = $splynxTickets->commissionInstallation($ticketId, $retailer['name'] ?? 'Support Leader', $notes);
    if ($result['ok'] ?? false) {
        // WA: notify assigned engineer they've been approved + commissioned
        if ($ticket) {
            $engId  = $ticket['assigned_engineer_id'] ?? 0;
            $engineer = $engId ? $store->findOne('retailers.json', 'id', (int)$engId) : null;
            if ($engineer && !empty($engineer['phone'])) {
                $notify->installApproved($engineer, $ticket, $retailer['name'] ?? 'Support Leader', $notes);
            }
        }
        apiOk($result);
    } else {
        apiError($result['error'] ?? 'Failed', 404);
    }
}

// ── POST install_reject — Bidal rejects, sends back to engineer ──────────────
if ($action === 'install_reject' && $method === 'POST') {
    if (!$isLeaderOrAdmin) apiError('Support leader access required.', 403);
    $ticketId = (int)($body['ticket_id'] ?? 0);
    $reason   = trim($body['reason']     ?? '');
    if (!$ticketId) apiError('ticket_id required.', 422);
    $ticket = $splynxTickets->getTicketById($ticketId);
    $ok = $splynxTickets->rejectInstallation($ticketId, $retailer['name'] ?? 'Support Leader', $reason);
    if ($ok) {
        // WA: notify assigned engineer of rejection with reason
        if ($ticket) {
            $engId  = $ticket['assigned_engineer_id'] ?? 0;
            $engineer = $engId ? $store->findOne('retailers.json', 'id', (int)$engId) : null;
            if ($engineer && !empty($engineer['phone'])) {
                $notify->installRejected($engineer, $ticket, $retailer['name'] ?? 'Support Leader', $reason);
            }
        }
        apiOk(['rejected' => true]);
    } else {
        apiError('Ticket not found.', 404);
    }
}

// ── POST install_upload_photo — save photo reference ────────────────────────
if ($action === 'install_upload_photo' && $method === 'POST') {
    if (!$isSupportAny) apiError('Support access required.', 403);
    $ticketId  = (int)($body['ticket_id']  ?? 0);
    $photoType = trim($body['photo_type']  ?? 'site');
    $imgData   = $body['image_data']        ?? '';
    if (!$ticketId || !$imgData) apiError('ticket_id and image_data required.', 422);

    // Save to disk
    $uploadDir = $dataDir . '/uploads/install_photos/' . $ticketId . '/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    $filename = $photoType . '_' . time() . '.jpg';

    // Strip data URI prefix if present
    $raw = preg_replace('/^data:image\/\w+;base64,/', '', $imgData);
    $bytes = base64_decode($raw);
    if ($bytes === false || strlen($bytes) < 100) apiError('Invalid image data.', 422);
    file_put_contents($uploadDir . $filename, $bytes);

    $splynxTickets->savePhoto($ticketId, $photoType, $filename, $retailer['name'] ?? 'Engineer');
    apiOk(['saved' => true, 'filename' => $filename]);
}

// ── GET  install_stats — Bidal dashboard summary ─────────────────────────────
if ($action === 'install_stats' && $method === 'GET') {
    if (!$isLeaderOrAdmin) apiError('Support leader access required.', 403);
    $summary = $splynxTickets->getSummary();
    $testing = $splynxTickets->getTestingQueue();
    $summary['testing_queue'] = count($testing);
    $summary['splynx_live']   = $splynx->isConfigured();
    apiOk($summary);
}

// ═══════════════════════════════════════════════════════════════════════════
// FIELD EXPENSE & CASH ADVANCE API  (v4.5)
// ═══════════════════════════════════════════════════════════════════════════

require_once __DIR__ . '/../lib/ExpenseAdvanceService.php';
$expAdv = new ExpenseAdvanceService($store, $dataDir);

$isManagerOrAdmin   = !empty($retailer['is_admin']) || in_array($retailer['role'] ?? '', ['admin','accountant'], true);
$isAccountantRole   = ($retailer['role'] ?? '') === 'accountant' || !empty($retailer['is_admin']);
// Any staff member (all roles can submit expenses against their own advances)
$isActiveStaff      = !empty($retailer['is_active']);

// ── GET advance_list — list advances (filter by status/recipient) ──────────────
// Also aliased as advances_list for backward compatibility
if (($action === 'advance_list' || $action === 'advances_list') && $method === 'GET') {
    $f = [];
    if (!$isManagerOrAdmin || !empty($_GET['mine'])) {
        // Non-admins or explicit mine=1: only see own advances as recipient
        $f['recipient_id'] = $retailerId;
    } else {
        if (!empty($_GET['recipient_id'])) $f['recipient_id'] = (int)$_GET['recipient_id'];
    }
    if (!empty($_GET['status']))    $f['status']    = $_GET['status'];
    if (!empty($_GET['project']))   $f['project']   = $_GET['project'];
    if (!empty($_GET['date_from'])) $f['date_from'] = $_GET['date_from'];
    if (!empty($_GET['date_to']))   $f['date_to']   = $_GET['date_to'];
    $f['limit']  = min((int)($_GET['limit'] ?? 50), 200);
    $f['offset'] = (int)($_GET['offset'] ?? 0);
    $advances = $expAdv->getAdvances($f);
    apiOk(['advances' => $advances, 'count' => count($advances)]);
}

// ── POST advance_create — manager creates a cash advance ──────────────────
if ($action === 'advance_create' && $method === 'POST') {
    if (!$isManagerOrAdmin) apiError('Manager or admin access required.', 403);
    $body   = getBody();
    $result = $expAdv->createAdvance($body, $retailer);
    if (!$result['ok']) apiError($result['error'] ?? 'Failed to create advance.', 422);
    apiOk(['advance' => $result], 'Cash advance created.');
}

// ── POST advance_cancel — cancel unclaimed advance ─────────────────────────
if ($action === 'advance_cancel' && $method === 'POST') {
    if (!$isManagerOrAdmin) apiError('Manager access required.', 403);
    $body  = getBody();
    $advId = (int)($body['advance_id'] ?? 0);
    if (!$advId) apiError('advance_id required.', 422);
    $result = $expAdv->cancelAdvance($advId, $retailer);
    if (!$result['ok']) apiError($result['error'], 422);
    apiOk([], 'Advance cancelled.');
}

// ── POST advance_settle — settle advance (record change returned) ──────────
if ($action === 'advance_settle' && $method === 'POST') {
    if (!$isManagerOrAdmin) apiError('Manager access required.', 403);
    $body         = getBody();
    $advId        = (int)($body['advance_id'] ?? 0);
    $returnAmount = (float)($body['return_amount'] ?? 0);
    $note         = trim($body['note'] ?? '');
    if (!$advId) apiError('advance_id required.', 422);
    $result = $expAdv->settleAdvance($advId, $returnAmount, $note, $retailer);
    if (!$result['ok']) apiError($result['error'], 422);
    apiOk($result, 'Advance settled.');
}

// ── GET expenses_list — list expenses (admin: all; staff: own only) ─────────
if ($action === 'expenses_list' && $method === 'GET') {
    $f = [];
    if (!$isManagerOrAdmin) {
        $f['staff_id'] = $retailerId;
    } else {
        if (!empty($_GET['staff_id'])) $f['staff_id'] = (int)$_GET['staff_id'];
    }
    if (!empty($_GET['advance_id'])) $f['advance_id'] = (int)$_GET['advance_id'];
    if (!empty($_GET['status']))     $f['status']     = $_GET['status'];
    if (!empty($_GET['project']))    $f['project']    = $_GET['project'];
    if (!empty($_GET['date_from']))  $f['date_from']  = $_GET['date_from'];
    if (!empty($_GET['date_to']))    $f['date_to']    = $_GET['date_to'];
    if (!empty($_GET['category']))   $f['category']   = $_GET['category'];
    if (!empty($_GET['flagged']))     $f['flagged']    = true;
    $f['limit']  = min((int)($_GET['limit'] ?? 50), 200);
    $f['offset'] = (int)($_GET['offset'] ?? 0);
    $expenses = $expAdv->getExpenses($f);
    apiOk(['expenses' => $expenses, 'count' => count($expenses)]);
}

// ── POST expense_submit — staff submits an expense with receipt ────────────
// Accepts either multipart/form-data (real file upload) or JSON with base64
if ($action === 'expense_submit' && $method === 'POST') {
    if (!$isActiveStaff) apiError('Active staff account required.', 403);

    // Parse body: prefer multipart, fall back to JSON
    $data = !empty($_POST) ? $_POST : getBody();
    $file = $_FILES['receipt'] ?? null;

    // Handle base64 receipt from PWA (no real file upload)
    if (!$file && !empty($data['receipt_base64'])) {
        $b64  = $data['receipt_base64'];
        $mime = $data['receipt_mime'] ?? 'image/jpeg';
        $ext  = ($mime === 'image/png') ? 'png' : 'jpg';
        $tmpPath = tempnam(sys_get_temp_dir(), 'rcpt_');
        $raw  = base64_decode(preg_replace('/^data:\w+\/\w+;base64,/', '', $b64));
        if ($raw && file_put_contents($tmpPath, $raw)) {
            $file = [
                'name'     => 'receipt_pwa.' . $ext,
                'tmp_name' => $tmpPath,
                'type'     => $mime,
                'size'     => strlen($raw),
                'error'    => 0,
                'data'     => $b64,
                '_tmp_created' => true,
            ];
        }
    }

    $result = $expAdv->submitExpense($data, $file, $retailer);

    // Clean up temp file if we created it
    if (!empty($file['_tmp_created']) && file_exists($file['tmp_name'])) {
        @unlink($file['tmp_name']);
    }

    if (!empty($result['duplicate'])) {
        apiOk($result, 'Already submitted (offline idempotency).');
    }
    if (!$result['ok']) apiError($result['error'] ?? 'Failed to submit expense.', 422);
    apiOk([
        'expense_no'  => $result['expense_no'],
        'id'          => $result['id'],
        'fraud_flags' => $result['fraud_flags'] ?? [],
        'warnings'    => $result['warnings'] ?? [],
    ], 'Expense submitted for approval.');
}

// ── POST expense_approve — accountant approves an expense ──────────────────
if ($action === 'expense_approve' && $method === 'POST') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);
    $body  = getBody();
    $expId = (int)($body['expense_id'] ?? 0);
    if (!$expId) apiError('expense_id required.', 422);
    $result = $expAdv->approveExpense($expId, $retailer);
    if (!$result['ok']) apiError($result['error'], 422);
    apiOk($result, 'Expense approved and posted to cashbook.');
}

// ── POST expense_reject — accountant rejects an expense ────────────────────
if ($action === 'expense_reject' && $method === 'POST') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);
    $body   = getBody();
    $expId  = (int)($body['expense_id'] ?? 0);
    $reason = trim($body['reason'] ?? '');
    if (!$expId)   apiError('expense_id required.', 422);
    if (!$reason)  apiError('Rejection reason required.', 422);
    $result = $expAdv->rejectExpense($expId, $reason, $retailer);
    if (!$result['ok']) apiError($result['error'], 422);
    apiOk([], 'Expense rejected.');
}

// ── GET expense_fraud_scan — run fraud detection on a single expense ────────
if ($action === 'expense_fraud_scan' && $method === 'GET') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);
    $expId = (int)($_GET['expense_id'] ?? 0);
    if (!$expId) apiError('expense_id required.', 422);
    $flags = $expAdv->detectFraud($expId);
    apiOk(['flags' => $flags, 'count' => count($flags)]);
}

// ── GET expense_fraud_report — all pending expenses with fraud flags ─────────
if ($action === 'expense_fraud_report' && $method === 'GET') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);
    $project = $_GET['project'] ?? '';
    $report  = $expAdv->getFraudReport($project);
    apiOk(['flagged' => $report, 'count' => count($report)]);
}

// ── GET staff_cashbook_daily — personal daily cashbook for a date ───────────
if ($action === 'staff_cashbook_daily' && $method === 'GET') {
    $date    = $_GET['date'] ?? date('Y-m-d');
    $staffId = $isManagerOrAdmin ? (int)($_GET['staff_id'] ?? $retailerId) : $retailerId;
    $row     = $expAdv->getDailyCashbook($staffId, $date);
    apiOk(['cashbook' => $row]);
}

// ── GET staff_summary — active advances + pending expenses for one staff ─────
if ($action === 'staff_expense_summary' && $method === 'GET') {
    $staffId = $isManagerOrAdmin ? (int)($_GET['staff_id'] ?? $retailerId) : $retailerId;
    $summary = $expAdv->getStaffSummary($staffId);
    apiOk($summary);
}

// ── GET advance_staff_overview — all staff with active advances ──────────────
if ($action === 'advance_staff_overview' && $method === 'GET') {
    if (!$isManagerOrAdmin) apiError('Manager access required.', 403);
    $overview = $expAdv->getActiveStaffSummary();
    apiOk(['staff' => $overview, 'count' => count($overview)]);
}

// ── GET reconciliation_report — compare staff cashbooks with main cashbook ───
if ($action === 'reconciliation_report' && $method === 'GET') {
    if (!$isAccountantRole) apiError('Accountant access required.', 403);
    $from    = $_GET['date_from'] ?? date('Y-m-01');
    $to      = $_GET['date_to']   ?? date('Y-m-d');
    $project = $_GET['project']   ?? '';
    $report  = $expAdv->getReconciliationReport($from, $to, $project);
    apiOk($report);
}

// ── GET expense_receipt — serve receipt image (secure, path-validated) ──────
if ($action === 'expense_receipt' && $method === 'GET') {
    $expId    = (int)($_GET['expense_id'] ?? 0);
    if (!$expId) apiError('expense_id required.', 422);

    // Staff can only view their own receipts; accountant/admin can view all
    $exp = $expAdv->getExpenseById($expId);
    if (!$exp) apiError('Expense not found.', 404);
    if (!$isManagerOrAdmin && (int)$exp['staff_id'] !== $retailerId) {
        apiError('Access denied.', 403);
    }

    $path = $expAdv->getReceiptPath($exp['receipt_path']);
    if (!$path) apiError('Receipt not found.', 404);

    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ($ext === 'pdf') ? 'application/pdf' : (($ext === 'png') ? 'image/png' : 'image/jpeg');

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="receipt-' . $expId . '.' . $ext . '"');
    header('Cache-Control: private, max-age=3600');
    header('Content-Length: ' . filesize($path));
    // Remove JSON content-type set at top of file
    while (ob_get_level()) ob_end_clean();
    readfile($path);
    exit;
}

// ── GET advance_badges — counts for nav badges ──────────────────────────────
if ($action === 'advance_badges' && $method === 'GET') {
    $pending  = $expAdv->countPending();
    $active   = $expAdv->countActiveAdvances();
    $mySum    = $expAdv->getStaffSummary($retailerId);
    apiOk([
        'pending_approvals'  => $pending,
        'active_advances'    => $active,
        'my_advance_balance' => $mySum['advance_balance'],
        'my_pending_exps'    => $mySum['pending_expenses'],
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// STAFF TRANSFERS — v4.4.26
// Diko gives physical cash to Bidal from his collections/advance.
// Auto-approved, auto-split (collections first, advance covers rest).
// Phantom-cash guards enforced at submit time.
// ══════════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/../lib/StaffTransferService.php';
$trfSvc = new StaffTransferService($store->getPdo(), $store);

// ── GET transfer_my_position — Diko sees his own live cash breakdown ─────────
// Used by PWA "Give Cash" screen to show available amounts before submit.
if ($action === 'transfer_my_position' && $method === 'GET') {
    $pos = $trfSvc->getPosition($retailerId);
    $carryLimit = (float)($store->findOne('plugin_settings.json','key','advance_carry_limit')['value'] ?? 100);
    $collectionsAvail = max(0,
        (float)($pos['collections']       ?? 0)
      - (float)($pos['handovers']         ?? 0)
      - (float)($pos['transfers_sent']    ?? 0)
    );
    apiOk([
        'cash_exposure'     => (float)($pos['cash_exposure']   ?? 0),
        'collections_avail' => $collectionsAvail,
        'advance_avail'     => (float)($pos['advance_balance'] ?? 0),
        'transfers_sent_today' => $trfSvc->sentToday($retailerId),
        'carry_limit'       => $carryLimit,
    ]);
}

// ── GET transfer_colleague_position — check receiver before showing confirm ──
// Lets PWA show "Bidal is already holding $120" warning before submit.
if ($action === 'transfer_colleague_position' && $method === 'GET') {
    $colleagueId = (int)($_GET['colleague_id'] ?? 0);
    if (!$colleagueId) apiError('colleague_id required.', 422);
    $pos = $trfSvc->getPosition($colleagueId);
    $carryLimit = (float)($store->findOne('plugin_settings.json','key','advance_carry_limit')['value'] ?? 100);
    apiOk([
        'cash_exposure' => (float)($pos['cash_exposure'] ?? 0),
        'carry_limit'   => $carryLimit,
        'headroom'      => max(0, $carryLimit - (float)($pos['cash_exposure'] ?? 0)),
    ]);
}

// ── POST transfer_submit — Diko gives cash to Bidal ─────────────────────────
if ($action === 'transfer_submit' && $method === 'POST') {
    $body   = getBody();
    $result = $trfSvc->submit($body, $retailer);
    if (!$result['ok']) apiError($result['error'], 422);
    apiOk($result, 'Cash transferred to ' . ($result['receiver_name'] ?? 'colleague') . '.');
}

// ── GET transfer_my_history — Diko sees his own transfers ───────────────────
if ($action === 'transfer_my_history' && $method === 'GET') {
    $transfers = $trfSvc->forAgent($retailerId, 30);
    apiOk(['transfers' => $transfers]);
}

// ── GET transfer_all_today — Rupesh sees all transfers today (admin only) ────
if ($action === 'transfer_all_today' && $method === 'GET') {
    if (!$isManagerOrAdmin) apiError('Manager access required.', 403);
    apiOk(['transfers' => $trfSvc->todayAll()]);
}

// ── GET transfer_all_positions — Staff Cash Control (admin/accountant only) ──
if ($action === 'transfer_all_positions' && $method === 'GET') {
    if (!$isManagerOrAdmin) apiError('Manager access required.', 403);
    apiOk(['positions' => $trfSvc->allPositions()]);
}

// ── POST transfer_void — Rupesh voids a same-day transfer ───────────────────
if ($action === 'transfer_void' && $method === 'POST') {
    if (!$isManagerOrAdmin) apiError('Manager access required.', 403);
    $body   = getBody();
    $tId    = (int)($body['transfer_id'] ?? 0);
    $reason = trim((string)($body['reason'] ?? ''));
    if (!$tId) apiError('transfer_id required.', 422);
    $result = $trfSvc->void($tId, $reason, $retailer);
    if (!$result['ok']) apiError($result['error'], 422);
    apiOk([], 'Transfer voided.');
}

// ═══════════════════════════════════════════════════════════════════════
// STAFF LEDGER — Unified cash balance (v4.11.3)
// ═══════════════════════════════════════════════════════════════════════

if ($action === 'staff_ledger_backfill') {
    if (!($retailer['is_admin'] ?? false)) apiError('Admin only', 403);
    $isApiCall = true;
    $pdo = $store->getPdo();
    include dirname(__DIR__) . '/backfill_staff_ledger.php';
    apiOk($backfillResult ?? ['error' => 'backfill did not return result']);
}

if ($action === 'staff_ledger_balance') {
    require_once dirname(__DIR__) . '/lib/StaffLedgerService.php';
    $ledger   = new StaffLedgerService($store->getPdo());
    $staffId  = (int)($_GET['staff_id'] ?? $retailerId);
    $currency = strtoupper($_GET['currency'] ?? 'USD');
    apiOk(['staff_id' => $staffId, 'currency' => $currency, 'balance' => $ledger->balance($staffId, $currency)]);
}

if ($action === 'staff_ledger_position') {
    require_once dirname(__DIR__) . '/lib/StaffLedgerService.php';
    $ledger  = new StaffLedgerService($store->getPdo());
    $staffId = (int)($_GET['staff_id'] ?? $retailerId);
    apiOk($ledger->position($staffId, strtoupper($_GET['currency'] ?? 'USD')));
}

if ($action === 'staff_ledger_positions') {
    require_once dirname(__DIR__) . '/lib/StaffLedgerService.php';
    $ledger = new StaffLedgerService($store->getPdo());
    apiOk(['positions' => $ledger->allPositions(strtoupper($_GET['currency'] ?? 'USD'))]);
}

if ($action === 'staff_ledger_entries') {
    require_once dirname(__DIR__) . '/lib/StaffLedgerService.php';
    $ledger  = new StaffLedgerService($store->getPdo());
    $staffId = (int)($_GET['staff_id'] ?? $retailerId);
    $filters = array_filter([
        'currency'    => $_GET['currency'] ?? null,
        'category'    => $_GET['category'] ?? null,
        'status'      => $_GET['status'] ?? null,
        'from'        => $_GET['from'] ?? null,
        'to'          => $_GET['to'] ?? null,
        'source_type' => $_GET['source_type'] ?? null,
        'limit'       => (int)($_GET['limit'] ?? 100),
        'offset'      => (int)($_GET['offset'] ?? 0),
    ], function ($v) { return $v !== null; });
    apiOk(['staff_id' => $staffId, 'entries' => $ledger->entries($staffId, $filters)]);
}

if ($action === 'staff_ledger_reconcile') {
    if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant')
        apiError('Admin or accountant only', 403);
    require_once dirname(__DIR__) . '/lib/StaffLedgerService.php';
    require_once dirname(__DIR__) . '/lib/StaffCashPositionService.php';
    $_slPdo = $store->getPdo();
    $ledger = new StaffLedgerService($_slPdo);
    $oldSvc = new StaffCashPositionService($store, $_slPdo);
    $oldAll = $oldSvc->getAllPositions();
    apiOk([
        'mismatches'   => $ledger->reconcileVsOld($oldAll),
        'old_count'    => count($oldAll),
        'ledger_count' => count($ledger->allPositions('USD')),
    ]);
}

if ($action === 'staff_ledger_summary') {
    require_once dirname(__DIR__) . '/lib/StaffLedgerService.php';
    $ledger  = new StaffLedgerService($store->getPdo());
    $staffId = (int)($_GET['staff_id'] ?? $retailerId);
    $month   = $_GET['month'] ?? date('Y-m');
    apiOk($ledger->monthlySummary($staffId, $month, strtoupper($_GET['currency'] ?? 'USD')));
}

if ($action === 'staff_ledger_stats') {
    require_once dirname(__DIR__) . '/lib/StaffLedgerService.php';
    $ledger = new StaffLedgerService($store->getPdo());
    apiOk(['total_rows' => $ledger->totalRows(), 'by_source' => $ledger->countBySource()]);
}

apiError("Unknown action: '" . htmlspecialchars($action, ENT_QUOTES) . "'.", 404);
