<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json';

/**
 * web_chat.php — the AI chat on dishnetsudan.com.
 *
 * A second door to the same brain. The WhatsApp worker and this endpoint both
 * call DishNetAiBrain::reply(), fed the same uCRM catalogue, so the website
 * and WhatsApp cannot drift into quoting different prices. That is the whole
 * reason it is built this way rather than as a separate bot.
 *
 *   POST /public.php?page=web_chat
 *   { "session": "<id or empty>", "message": "..." }
 *   -> { "ok":true, "session":"...", "reply":"...", "handoff":"https://wa.me/..." }
 *
 * What is deliberately NOT here:
 *
 *   - No API key reaches the browser. The page talks to this endpoint; this
 *     endpoint talks to the provider. Same rule as the Evolution key.
 *   - No account data. A web visitor has no phone number, so no uCRM identity,
 *     so there is nothing account-shaped to disclose. The prompt says so and
 *     the context simply never contains it.
 *   - No unbounded spend. WebChatGuard is checked before the model is called,
 *     and every rejection returns a WhatsApp fallback rather than a dead box.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/PluginConfig.php';
require_once __DIR__ . '/lib/WebChatGuard.php';
require_once __DIR__ . '/lib/DishNetTools.php';
require_once __DIR__ . '/lib/DishNetAiBrain.php';

$store  = SqliteStore::create($dataDir);

// Config comes from two places and needs both.
//
// PluginConfig::load() reads the files and the vault, which is where the
// provider keys live -- they are deliberately not writable from a plugin page.
// But SqliteStore::create() migrates kyc_config.json into SQLite on first boot
// and REMOVES the file, so on any real install the operator's own settings
// (including web_chat_enabled) exist only in the store. Reading just the files
// meant this endpoint reported itself switched off forever; reading just the
// store meant no API key. Operator settings win, secrets fill the gaps.
$config = PluginConfig::load(__DIR__, $dataDir);
try {
    $stored = $store->load('kyc_config.json');
    if (is_array($stored)) {
        $config = array_merge($config, array_filter($stored, function ($v) {
            return $v !== null && $v !== '';
        }));
    }
} catch (\Throwable $e) { /* files alone are better than nothing */ }

// ── CORS ──────────────────────────────────────────────────────────────────
// The widget is served from dishnetsudan.com; this endpoint lives on
// crm.dishnetsudan.com. Cross-origin by construction, so the allowlist is
// what stops any other site embedding our assistant and spending our budget.
$allowed = array_values(array_filter(array_map('trim', explode(',',
    (string)($config['web_chat_origins'] ?? 'https://dishnetsudan.com,https://www.dishnetsudan.com')))));
$origin  = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$originOk = $origin !== '' && in_array($origin, $allowed, true);

if ($originOk) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if ($originOk) {
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    http_response_code($originOk ? 204 : 403);
    exit;
}

function out(array $body, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$waNumber  = preg_replace('/\D+/', '', (string)($config['web_chat_whatsapp']
                                              ?? $config['evo_number_sales'] ?? ''));
$handoff   = $waNumber !== '' ? 'https://wa.me/' . $waNumber : '';

/** Every failure path ends somewhere a customer can actually get help. */
function bail(string $msg, string $handoff, int $code = 200, string $reason = ''): void
{
    out(['ok' => false, 'reason' => $reason, 'reply' => $msg, 'handoff' => $handoff], $code);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bail('Send a message to start.', $handoff, 405, 'method');
}
if ($origin !== '' && !$originOk) {
    // An unknown origin is not a customer problem, so it gets no fallback text
    // and no assistant -- just a refusal.
    out(['ok' => false, 'reason' => 'origin', 'reply' => ''], 403);
}
if (!PluginConfig::toBool($config['web_chat_enabled'] ?? false)) {
    bail('Our chat assistant is off right now. Message us on WhatsApp and we will help you there.',
         $handoff, 200, 'disabled');
}

$raw  = file_get_contents('php://input') ?: '';
if (strlen($raw) > 8000) {
    bail('That message is too long — try a shorter question.', $handoff, 413, 'too_long');
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    bail('Sorry, something went wrong. Message us on WhatsApp and we will help.', $handoff, 400, 'bad_json');
}

$message = trim((string)($body['message'] ?? ''));
$message = mb_substr($message, 0, 1000);
if ($message === '') {
    bail('Ask me anything about Starlink in Sudan.', $handoff, 400, 'empty');
}

// Session ids are issued here, never accepted on trust: a client-supplied id
// is only reused if it looks like one of ours, so nobody can forge their way
// into someone else's history or reset their own quota by inventing ids.
$session = (string)($body['session'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $session)) {
    $session = bin2hex(random_bytes(16));
}

// Client IP. Behind Traefik the socket address is the proxy, so trust the
// forwarded header only for the hop we actually put there.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
if ($fwd !== '') {
    $first = trim(explode(',', $fwd)[0]);
    if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
}

$guard = new WebChatGuard($store, $config);
$gate  = $guard->check($ip, $session);
if (!$gate['ok']) {
    out(['ok' => false, 'reason' => $gate['reason'], 'reply' => $gate['message'],
         'handoff' => $handoff, 'retry_in' => $gate['retry_in'], 'session' => $session], 200);
}

$brain = new DishNetAiBrain($config);
if (!$brain->isConfigured()) {
    bail('Our chat assistant is unavailable. Message us on WhatsApp and we will answer you there.',
         $handoff, 200, 'no_provider');
}

// ── History ───────────────────────────────────────────────────────────────
// Kept server-side and keyed to the session: a browser-supplied transcript
// would be a way to put words in the assistant's mouth.
$HIST = 'web_chat_sessions.json';
$history = [];
try {
    $row = $store->findOne($HIST, 'session', $session);
    if ($row && !empty($row['turns'])) {
        $turns = json_decode((string)$row['turns'], true);
        if (is_array($turns)) $history = $turns;
    }
} catch (\Throwable $e) { /* history is optional */ }

// ── Context: sales posture, no identity ───────────────────────────────────
$tools    = new DishNetTools($store, $config, __DIR__);
$products = $tools->getProducts();

$ctx = [
    'channel'   => 'sales',
    'transport' => 'web',
    'message'   => $message,
    'customer'  => null,
    'history'   => array_slice($history, -10),
];
if ($products['ok']) {
    $ctx['products'] = $products['data'];
} else {
    // The brain falls back to refusing to quote, which is correct, but this
    // must never be silent -- a website quoting nothing is a lost sale.
    @file_put_contents($dataDir . '/ai_platform.log', sprintf(
        "[%s] web_chat: product lookup FAILED — %s (assistant will not quote prices)\n",
        gmdate('c'), (string)($products['error'] ?? 'unknown')), FILE_APPEND);
}

$result = $brain->reply($ctx);
$reply  = trim((string)($result['reply'] ?? ''));

if ($reply === '') {
    // An escalation with no text still needs to say something to the visitor.
    bail('Let me get a colleague to help with that — message us on WhatsApp and we will pick it up.',
         $handoff, 200, 'escalated');
}

// Only a real answer costs the visitor part of their allowance.
$guard->record($ip, $session, $brain->getLastUsage() ?: []);

$history[] = ['role' => 'customer', 'text' => mb_substr($message, 0, 400)];
$history[] = ['role' => 'dishnet',  'text' => mb_substr($reply, 0, 400)];
$history   = array_slice($history, -20);
try {
    $payload = ['session' => $session, 'turns' => json_encode($history, JSON_UNESCAPED_UNICODE),
                'updated' => gmdate('c')];
    if ($store->findOne($HIST, 'session', $session)) {
        $store->updateOne($HIST, 'session', $session, $payload);
    } else {
        $store->append($HIST, $payload);
    }
} catch (\Throwable $e) { /* a lost transcript must not lose the reply */ }

out([
    'ok'       => true,
    'session'  => $session,
    'reply'    => $reply,
    'escalate' => !empty($result['escalate']),
    'handoff'  => $handoff,
]);
