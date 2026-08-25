<?php
declare(strict_types=1);
chdir(dirname(__DIR__, 2));

/**
 * DishNet Hybrid — API v2 Router
 * Base: /crm/_plugins/dishnet-hybrid-telecom/api/v2/
 *
 * RESTful routing with:
 *   - JWT authentication (Bearer token)
 *   - Role-based access control (RBAC)
 *   - Rate limiting per user
 *   - Idempotency support (X-Idempotency-Key header)
 *   - Backward compatibility: v1 tokens also accepted
 *
 * PHP 7.4 compatible. Zero external dependencies.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__, 2));
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once dirname(__DIR__, 2) . '/lib/StoreInterface.php';
require_once dirname(__DIR__, 2) . '/lib/SqliteStore.php';
require_once dirname(__DIR__, 2) . '/lib/JwtAuth.php';
require_once dirname(__DIR__, 2) . '/lib/RetailerAuth.php';
require_once dirname(__DIR__, 2) . '/lib/WalletService.php';
require_once dirname(__DIR__, 2) . '/lib/IdempotencyGuard.php';
require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
require_once dirname(__DIR__, 2) . '/lib/CrmQueue.php';
require_once dirname(__DIR__, 2) . '/lib/EventBus.php';
require_once dirname(__DIR__, 2) . '/lib/NotificationService.php';
require_once dirname(__DIR__, 2) . '/lib/SplynxApiClient.php';
require_once dirname(__DIR__, 2) . '/lib/SplynxTicketService.php';
require_once dirname(__DIR__, 2) . '/lib/SplynxCustomerService.php';
require_once dirname(__DIR__, 2) . '/lib/LoginRateLimiter.php';

// ── CORS ─────────────────────────────────────────────────────────────────────
$origin = getenv('PWA_ORIGIN') ?: '*';
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Idempotency-Key');
header('Access-Control-Expose-Headers: X-Idempotency-Replay, X-RateLimit-Remaining');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Services ─────────────────────────────────────────────────────────────────
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$auth    = new RetailerAuth($store);
$wallet  = new WalletService($store);
$idem    = new IdempotencyGuard($store);
$jwt     = JwtAuth::fromConfig($config);
$bus     = new EventBus($store->getPdo());
$notify  = new NotificationService($store, $config);
$crm     = CrmApiClient::fromUcrm(dirname(__DIR__, 2), $config);
$queue   = new CrmQueue($store);
$splynx        = SplynxApiClient::fromConfig($config);
$splynxTickets = new SplynxTicketService($splynx, $store, $notify, $config);
$limiter = new LoginRateLimiter($store);

// ── Response helpers ─────────────────────────────────────────────────────────
function jsonOk(array $data = [], string $msg = 'OK', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => true, 'message' => $msg, 'data' => $data]);
    exit;
}
function jsonErr(string $msg, int $code = 400, array $errors = []): void {
    http_response_code($code);
    echo json_encode(array_filter(['ok' => false, 'message' => $msg, 'errors' => $errors ?: null]));
    exit;
}
function getBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    return json_decode($raw, true) ?? [];
}

// ── Route parsing ────────────────────────────────────────────────────────────
// URL: /api/v2/{resource}/{id?}/{action?}
// Examples:
//   POST /api/v2/auth/login
//   GET  /api/v2/tickets
//   POST /api/v2/install/42/accept
//   GET  /api/v2/wallet/balance

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$basePath   = '/api/v2/';
$pathPos    = strpos($requestUri, $basePath);
$pathInfo   = $pathPos !== false
    ? substr($requestUri, $pathPos + strlen($basePath))
    : trim($_GET['route'] ?? '', '/');
$pathInfo   = strtok($pathInfo, '?'); // strip query string
$segments   = array_values(array_filter(explode('/', $pathInfo)));

$resource = $segments[0] ?? '';
$resourceId = $segments[1] ?? '';
$subAction  = $segments[2] ?? '';
$method     = $_SERVER['REQUEST_METHOD'];

// ── Rate limiting ────────────────────────────────────────────────────────────
// Simple per-IP rate limit: 120 requests/minute for authenticated, 20 for anon
$rateLimitKey = 'apiv2_' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$rateWindow   = 60; // seconds
$rateLimit    = 20; // default (unauthenticated)

// Rate limit check (using SQLite for state)
try {
    $pdo = $store->getPdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS _rate_limits (
        key TEXT PRIMARY KEY, count INTEGER DEFAULT 0, window_start INTEGER
    )");
    $now = time();
    $rl = $pdo->prepare("SELECT count, window_start FROM _rate_limits WHERE key = ?");
    $rl->execute([$rateLimitKey]);
    $row = $rl->fetch(\PDO::FETCH_ASSOC);

    if ($row && ($now - (int)$row['window_start']) < $rateWindow) {
        $currentCount = (int)$row['count'];
    } else {
        $currentCount = 0;
        $pdo->prepare("INSERT OR REPLACE INTO _rate_limits (key, count, window_start) VALUES (?, 0, ?)")
            ->execute([$rateLimitKey, $now]);
    }
} catch (\Throwable $e) { $currentCount = 0; }

// ── Authentication ───────────────────────────────────────────────────────────
// Public endpoints (no auth required)
$publicEndpoints = ['auth/login', 'auth/register', 'health'];
$currentPath = $resource . ($resourceId ? '/' . $resourceId : '');

$me = null;
if (!in_array($currentPath, $publicEndpoints, true)) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = '';

    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }

    if (!$token) {
        jsonErr('Authentication required. Send: Authorization: Bearer <token>', 401);
    }

    // Try JWT first, then fall back to v1 API token
    try {
        $claims = $jwt->verify($token);
        $me = $store->findOne('retailers.json', 'id', (int)$claims['sub']);
        if (!$me) jsonErr('User not found', 401);
        $me['_auth'] = 'jwt';
        $me['_claims'] = $claims;
        $rateLimit = 120; // authenticated users get higher limit
    } catch (\RuntimeException $e) {
        // Fallback: try v1 bearer token
        $me = $auth->tokenAuth($token);
        if (!$me) jsonErr('Invalid or expired token', 401);
        $me['_auth'] = 'v1_token';
        $rateLimit = 120;
    }

    if (!($me['is_active'] ?? true)) {
        jsonErr('Account deactivated', 403);
    }
}

// Enforce rate limit (now that we know authenticated vs not)
if ($currentCount >= $rateLimit) {
    header('Retry-After: ' . $rateWindow);
    header('X-RateLimit-Remaining: 0');
    jsonErr('Rate limit exceeded. Try again in ' . $rateWindow . ' seconds.', 429);
}
try {
    $pdo->prepare("UPDATE _rate_limits SET count = count + 1 WHERE key = ?")->execute([$rateLimitKey]);
} catch (\Throwable $e) {}
header('X-RateLimit-Remaining: ' . max(0, $rateLimit - $currentCount - 1));

// ── RBAC helper ──────────────────────────────────────────────────────────────
$role = $me['role'] ?? 'sales';
$isAdmin = ($me['is_admin'] ?? false) || $role === 'admin';

$roleAccess = [
    'admin'          => ['*'],
    'support_leader' => ['tickets', 'install', 'noc', 'customers', 'auth', 'health'],
    'support'        => ['tickets', 'install', 'auth', 'health'],
    'sales'          => ['wallet', 'customers', 'kyc', 'auth', 'health'],
    'accountant'     => ['wallet', 'reports', 'auth', 'health'],
    'field_agent'    => ['wallet', 'install', 'auth', 'health'],
];
$allowed = $roleAccess[$role] ?? ['auth', 'health'];
if (!$isAdmin && !in_array('*', $allowed) && !in_array($resource, $allowed)) {
    jsonErr("Access denied: {$role} cannot access /{$resource}", 403);
}

// ── Idempotency ──────────────────────────────────────────────────────────────
$idemKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
if ($idemKey && $method === 'POST') {
    $cached = $idem->check($idemKey);
    if ($cached !== null) {
        header('X-Idempotency-Replay: true');
        echo json_encode($cached);
        exit;
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ROUTE DISPATCH
// ══════════════════════════════════════════════════════════════════════════════

switch ($resource) {

    // ── Auth ──────────────────────────────────────────────────────────────
    case 'auth':
        if ($resourceId === 'login' && $method === 'POST') {
            $body = getBody();
            $email = strtolower(trim($body['email'] ?? $body['identifier'] ?? ''));
            $password = $body['password'] ?? '';
            if (!$email || !$password) jsonErr('Email and password required');

            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $lock = $limiter->check($email, $ip);
            if ($lock['locked']) jsonErr("Account locked for {$lock['retry_in_minutes']} minutes", 429);

            $user = $auth->webLogin($email, $password);
            if (!$user) {
                $limiter->recordFailure($email, $ip);
                jsonErr('Invalid credentials', 401);
            }
            $limiter->recordSuccess($email, $ip);

            $token = $jwt->issue([
                'sub'  => (int)$user['id'],
                'role' => $user['role'] ?? 'sales',
                'name' => $user['name'] ?? '',
            ]);
            unset($user['password']);
            jsonOk([
                'token'   => $token,
                'user'    => $user,
                'wallet'  => $wallet->getSummary((int)$user['id']),
            ], 'Login successful');
        }

        if ($resourceId === 'refresh' && $method === 'POST') {
            $authH = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (!preg_match('/^Bearer\s+(.+)$/i', $authH, $m)) jsonErr('Token required', 401);
            try {
                $newToken = $jwt->refresh(trim($m[1]));
                jsonOk(['token' => $newToken], 'Token refreshed');
            } catch (\RuntimeException $e) {
                jsonErr('Cannot refresh: ' . $e->getMessage(), 401);
            }
        }

        if ($resourceId === 'me' && $method === 'GET') {
            $safe = $me;
            unset($safe['password'], $safe['_auth'], $safe['_claims']);
            jsonOk([
                'user'   => $safe,
                'wallet' => $wallet->getSummary((int)$me['id']),
            ]);
        }
        break;

    // ── Health ────────────────────────────────────────────────────────────
    case 'health':
        $summary = $bus->getSummary();
        jsonOk([
            'status'  => 'ok',
            'version' => '4.0.0',
            'api'     => 'v2',
            'time'    => date('c'),
            'events'  => $summary,
        ]);
        break;

    // ── Tickets ──────────────────────────────────────────────────────────
    case 'tickets':
        if ($method === 'GET' && !$resourceId) {
            // List tickets with optional filters
            $area   = trim($_GET['area'] ?? '');
            $status = $_GET['status'] ?? '';
            $tickets = $store->load('splynx_tickets.json') ?? [];

            if ($area) {
                $tickets = array_values(array_filter($tickets,
                    fn($t) => strcasecmp($t['area'] ?? '', $area) === 0));
            }
            if ($status !== '') {
                $statusInt = (int)$status;
                $tickets = array_values(array_filter($tickets,
                    fn($t) => (int)($t['status'] ?? 0) === $statusInt));
            }
            // Hide completed unless requested
            if (empty($_GET['include_completed'])) {
                $tickets = array_values(array_filter($tickets,
                    fn($t) => empty($t['install_complete'])));
            }

            jsonOk(['tickets' => $tickets, 'total' => count($tickets)]);
        }

        if ($method === 'GET' && $resourceId) {
            $ticket = $splynxTickets->getTicketById((int)$resourceId);
            if (!$ticket) jsonErr('Ticket not found', 404);
            // Include event history
            $history = $bus->getEntityHistory('ticket', (int)$resourceId, 20);
            jsonOk(['ticket' => $ticket, 'events' => $history]);
        }

        if ($method === 'PATCH' && $resourceId && $subAction === 'status') {
            $body = getBody();
            $newStatus = (int)($body['status_id'] ?? 0);
            if (!$newStatus) jsonErr('status_id required');

            $allowed = [1,2,3,4,5,7,8,9,10,11,12];
            if (!in_array($newStatus, $allowed)) jsonErr('Invalid status_id');

            // Update locally (SplynxTicketService does dual-write)
            $ticketId = (int)$resourceId;
            $tickets = $store->load('splynx_tickets.json') ?? [];
            $found = false;
            $lblMap = [1=>'new',2=>'work in progress',3=>'resolved',4=>'waiting your answer',5=>'waiting on agent',7=>'survey done',8=>'fiber deployment in progress',9=>'ready onu mapped',10=>'cancel by customer',11=>'fiber not available',12=>'client not ready'];

            foreach ($tickets as &$t) {
                if ((int)($t['id'] ?? 0) === $ticketId) {
                    $t['status'] = $newStatus;
                    $t['status_label'] = $lblMap[$newStatus] ?? 'status-' . $newStatus;
                    $t['status_changed_at'] = date('Y-m-d H:i:s');
                    $t['status_changed_by'] = $me['name'] ?? 'API';
                    if ($newStatus === 3) {
                        $t['install_complete'] = true;
                        $t['install_complete_at'] = $t['install_complete_at'] ?? date('Y-m-d H:i:s');
                    }
                    $found = true;
                    $splynxTid = (int)($t['splynx_ticket_id'] ?? $t['id']);
                    break;
                }
            }
            unset($t);
            if (!$found) jsonErr('Ticket not found', 404);
            $store->save('splynx_tickets.json', array_values($tickets));

            // Emit event for async Splynx push
            $eventId = $bus->emit('ticket.status_changed', 'ticket', $ticketId, [
                'ticket_id'        => $ticketId,
                'splynx_ticket_id' => $splynxTid ?? 0,
                'new_status'       => $newStatus,
                'changed_by'       => $me['name'] ?? 'API',
            ], 2, 'api_v2');

            if ($idemKey) $idem->store($idemKey, ['ok' => true, 'event_id' => $eventId]);
            jsonOk(['updated' => true, 'event_id' => $eventId]);
        }
        break;

    // ── Install workflow ─────────────────────────────────────────────────
    case 'install':
        if ($method === 'POST' && $resourceId && $subAction === 'accept') {
            // Engineer accepts a job assignment
            $ticketId = (int)$resourceId;
            $ticket = $splynxTickets->getTicketById($ticketId);
            if (!$ticket) jsonErr('Ticket not found', 404);

            // Verify this engineer is assigned
            $myId = (string)($me['id'] ?? 0);
            if (($ticket['assigned_engineer_id'] ?? '') !== $myId && !$isAdmin) {
                jsonErr('You are not assigned to this ticket', 403);
            }

            $bus->emit('install.accepted', 'ticket', $ticketId, [
                'ticket_id'     => $ticketId,
                'engineer_id'   => $myId,
                'engineer_name' => $me['name'] ?? '',
                'accepted_at'   => date('Y-m-d H:i:s'),
            ], 3, 'engineer');

            jsonOk(['accepted' => true, 'ticket_id' => $ticketId]);
        }

        if ($method === 'POST' && $resourceId && $subAction === 'gps') {
            // Engineer submits GPS coordinates
            $body = getBody();
            $lat = (float)($body['lat'] ?? 0);
            $lng = (float)($body['lng'] ?? 0);
            if (!$lat || !$lng) jsonErr('lat and lng required');

            // Validate GPS is within Juba bounds (rough: 4.8-4.95 lat, 31.55-31.7 lng)
            $inBounds = ($lat >= 4.7 && $lat <= 5.1 && $lng >= 31.4 && $lng <= 31.8);

            $ticketId = (int)$resourceId;
            $tickets = $store->load('splynx_tickets.json') ?? [];
            foreach ($tickets as &$t) {
                if ((int)($t['id'] ?? 0) === $ticketId) {
                    $t['gps_lat'] = $lat;
                    $t['gps_lng'] = $lng;
                    $t['gps_valid'] = $inBounds;
                    $t['gps_submitted_at'] = date('Y-m-d H:i:s');
                    break;
                }
            }
            unset($t);
            $store->save('splynx_tickets.json', array_values($tickets));
            $splynxTickets->syncTicketToTable(array_values(array_filter($tickets, fn($t) => (int)($t['id'] ?? 0) === $ticketId))[0] ?? []);

            jsonOk(['gps_valid' => $inBounds, 'lat' => $lat, 'lng' => $lng]);
        }

        if ($method === 'POST' && $resourceId && $subAction === 'onu') {
            // Engineer submits ONU serial + signal reading
            $body = getBody();
            $onuSerial = trim($body['onu_serial'] ?? '');
            $signalDb  = isset($body['signal_db']) ? (float)$body['signal_db'] : null;
            $oltPort   = trim($body['olt_port'] ?? '');
            if (!$onuSerial) jsonErr('onu_serial required');

            // Signal validation: acceptable range is -8 to -28 dBm
            $signalOk = $signalDb !== null && $signalDb >= -28 && $signalDb <= -8;

            $splynxTickets->saveInstallData((int)$resourceId, [
                'onu_serial'   => $onuSerial,
                'signal_db'    => $signalDb,
                'olt_port'     => $oltPort,
                'install_data_by' => $me['name'] ?? 'Engineer',
            ]);

            jsonOk([
                'saved'     => true,
                'signal_ok' => $signalOk,
                'signal_db' => $signalDb,
                'warning'   => !$signalOk ? 'Signal outside acceptable range (-28 to -8 dBm)' : null,
            ]);
        }

        if ($method === 'POST' && $resourceId && $subAction === 'complete') {
            // Engineer marks installation as complete
            $body = getBody();
            $ticketId = (int)$resourceId;
            $ticket = $splynxTickets->getTicketById($ticketId);
            if (!$ticket) jsonErr('Ticket not found', 404);

            // Validation: must have ONU serial and GPS
            if (empty($ticket['onu_serial'])) jsonErr('ONU serial not submitted yet');
            if (empty($ticket['gps_lat'])) jsonErr('GPS not submitted yet');

            // Mark ready for commissioning
            $ok = $splynxTickets->markReadyForCommissioning($ticketId, $me['name'] ?? 'Engineer');
            if (!$ok) jsonErr('Failed to mark ticket ready');

            $bus->emit('install.ready', 'ticket', $ticketId, [
                'ticket_id'     => $ticketId,
                'engineer_name' => $me['name'] ?? '',
                'onu_serial'    => $ticket['onu_serial'] ?? '',
                'signal_db'     => $ticket['signal_db'] ?? null,
            ], 2, 'engineer');

            jsonOk(['ready_for_commissioning' => true]);
        }

        if ($method === 'POST' && $resourceId && $subAction === 'photo') {
            // Engineer uploads a photo
            $body = getBody();
            $imgData   = $body['image_data'] ?? '';
            $photoType = trim($body['photo_type'] ?? 'site');
            if (!$imgData) jsonErr('image_data required (base64)');

            $uploadDir = $dataDir . '/uploads/install_photos/' . $resourceId . '/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $filename = $photoType . '_' . time() . '.jpg';
            $raw = preg_replace('/^data:image\\/\\w+;base64,/', '', $imgData);
            $bytes = base64_decode($raw);
            if ($bytes === false || strlen($bytes) < 100) jsonErr('Invalid image data');
            file_put_contents($uploadDir . $filename, $bytes);

            $splynxTickets->savePhoto((int)$resourceId, $photoType, $filename, $me['name'] ?? 'Engineer');
            jsonOk(['saved' => true, 'filename' => $filename, 'type' => $photoType]);
        }
        break;

    // ── Wallet ───────────────────────────────────────────────────────────
    case 'wallet':
        if ($method === 'GET' && ($resourceId === 'balance' || !$resourceId)) {
            $rid = (int)($me['id'] ?? 0);
            jsonOk($wallet->getSummary($rid));
        }
        if ($method === 'GET' && $resourceId === 'passbook') {
            $rid = (int)($me['id'] ?? 0);
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
            jsonOk(['entries' => $wallet->getPassbook($rid, $limit)]);
        }
        break;

    // ── NOC ──────────────────────────────────────────────────────────────
    case 'noc':
        if ($method === 'GET' && $resourceId === 'areas') {
            $areas = SplynxTicketService::getJubaAreas();
            jsonOk(['areas' => $areas]);
        }
        if ($method === 'GET' && $resourceId === 'dispatch') {
            $enriched = $splynxTickets->enrichFromCrm($store, $crm);
            $tickets = $store->load('splynx_tickets.json') ?? [];
            $open = array_values(array_filter($tickets, fn($t) => empty($t['install_complete'])));
            jsonOk([
                'tickets'  => $open,
                'total'    => count($open),
                'enriched' => $enriched,
            ]);
        }
        break;

    // ── Default ──────────────────────────────────────────────────────────
    default:
        jsonErr("Unknown resource: /{$resource}", 404);
}

// If we reach here, the route was matched but no handler responded
jsonErr("Method {$method} not supported for /{$resource}/{$resourceId}/{$subAction}", 405);
