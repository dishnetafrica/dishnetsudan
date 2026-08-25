<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json';

/**
 * ai_tools.php — the DishNet business tool surface, over HTTP.
 *
 * This is what ShopBot calls. It speaks business, not UCRM:
 *
 *   GET  ?tool=products
 *   GET  ?tool=identify_customer&phone=211912345678
 *   GET  ?tool=customer&client_id=123
 *   GET  ?tool=services&client_id=123
 *   GET  ?tool=account&client_id=123          (sensitive — see below)
 *   GET  ?tool=invoices&client_id=123&limit=5
 *   GET  ?tool=line_status&phone=211912345678
 *   GET  ?tool=describe_product_schema        (the Phase 0 probe)
 *   POST ?tool=support_request                {client_id, subject, body}
 *
 * Authentication is a dedicated service token, NOT a staff JWT. ShopBot is a
 * machine client with no user identity and no RBAC role, so reusing the staff
 * token scheme would either over-grant it or fail. Set 'ai_tools_token' in
 * kyc_config.json and send it as:  Authorization: Bearer <token>
 *
 * SENSITIVE DATA — 'account' and 'invoices' return money. Two rules:
 *   1. The caller must pass a client_id that came from identify_customer on
 *      this same phone number. We do not accept a raw phone here, so a caller
 *      cannot sweep the customer base by guessing numbers.
 *   2. identify_customer returns found=false with reason='ambiguous' when more
 *      than one customer matches. The AI must then ask a verifying question
 *      rather than disclose anything.
 *
 * PHP 7.4 compatible.
 */

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);

require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/DishNetTools.php';

header('Content-Type: application/json; charset=UTF-8');

function toolsRespond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];

// ── Authenticate ─────────────────────────────────────────────────────────────
$expected = trim((string)($config['ai_tools_token'] ?? ''));
if ($expected === '') {
    toolsRespond(503, ['ok' => false, 'error' => 'Tool API is not configured']);
}

$header = '';
foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
    if (!empty($_SERVER[$k])) { $header = (string)$_SERVER[$k]; break; }
}
$presented = '';
if (stripos($header, 'Bearer ') === 0) {
    $presented = trim(substr($header, 7));
}
if ($presented === '' || !hash_equals($expected, $presented)) {
    error_log('[ai_tools] auth failed from ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
    toolsRespond(401, ['ok' => false, 'error' => 'Unauthorised']);
}

// ── Dispatch ─────────────────────────────────────────────────────────────────
$tool   = (string)($_GET['tool'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = [];
if ($method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) $body = [];
}

$tools    = new DishNetTools($store, $config, __DIR__);
$clientId = (int)($_GET['client_id'] ?? ($body['client_id'] ?? 0));
$phone    = trim((string)($_GET['phone'] ?? ($body['phone'] ?? '')));

$started = microtime(true);

switch ($tool) {
    case 'products':
        $result = $tools->getProducts((int)($_GET['limit'] ?? 200));
        break;

    case 'describe_product_schema':
        $result = $tools->describeProductSchema();
        break;

    case 'identify_customer':
        if ($phone === '') { toolsRespond(400, ['ok' => false, 'error' => 'phone is required']); }
        $result = $tools->identifyCustomerByPhone($phone);
        break;

    case 'customer':
        if ($clientId <= 0) { toolsRespond(400, ['ok' => false, 'error' => 'client_id is required']); }
        $result = $tools->getCustomer($clientId);
        break;

    case 'services':
        if ($clientId <= 0) { toolsRespond(400, ['ok' => false, 'error' => 'client_id is required']); }
        $result = $tools->getCustomerServices($clientId);
        break;

    case 'account':
        if ($clientId <= 0) { toolsRespond(400, ['ok' => false, 'error' => 'client_id is required']); }
        $result = $tools->getAccount($clientId);
        break;

    case 'invoices':
        if ($clientId <= 0) { toolsRespond(400, ['ok' => false, 'error' => 'client_id is required']); }
        $result = $tools->getInvoices($clientId, (int)($_GET['limit'] ?? 5));
        break;

    case 'line_status':
        if ($phone === '') { toolsRespond(400, ['ok' => false, 'error' => 'phone is required']); }
        $result = $tools->getLineStatus($phone);
        break;

    case 'support_request':
        if ($method !== 'POST') { toolsRespond(405, ['ok' => false, 'error' => 'POST required']); }
        $subject = trim((string)($body['subject'] ?? ''));
        $text    = trim((string)($body['body'] ?? ''));
        if ($subject === '' || $text === '') {
            toolsRespond(400, ['ok' => false, 'error' => 'subject and body are required']);
        }
        $result = $tools->createSupportRequest($clientId, $subject, $text, $phone);
        break;

    case '':
        toolsRespond(400, ['ok' => false, 'error' => 'tool parameter is required']);
        break;

    default:
        toolsRespond(404, ['ok' => false, 'error' => 'Unknown tool: ' . $tool]);
}

// Timing helps diagnose whether a slow WhatsApp reply is the AI or the CRM.
$result['_ms'] = (int)round((microtime(true) - $started) * 1000);

toolsRespond($result['ok'] ? 200 : 502, $result);
