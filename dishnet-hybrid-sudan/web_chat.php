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

// Config: the files and the vault first, the store only for genuine gaps.
//
// PluginConfig::load() is what AiReplyWorker uses, so reading it the same way
// is what keeps the website and WhatsApp on the same provider and the same key.
// The store is consulted only because SqliteStore migrates kyc_config.json into
// SQLite on first boot and removes the file, so on an install that has never
// re-saved its settings they exist only there.
//
// The direction matters and getting it wrong is what broke this once already:
// merging the store OVER the files let a stale ai_provider from the migration
// snapshot override the live one, so the brain looked for a key that had never
// been set and the website reported itself permanently unavailable while
// WhatsApp carried on working. Gap-filling only, exactly like ConfigVault.
$config = PluginConfig::load(__DIR__, $dataDir);
try {
    $stored = $store->load('kyc_config.json');
    if (is_array($stored)) {
        foreach ($stored as $k => $v) {
            if ($v === null || $v === '') continue;
            if (!array_key_exists($k, $config) || $config[$k] === '' || $config[$k] === null) {
                $config[$k] = $v;
            }
        }
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
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

// ── Config probe ──────────────────────────────────────────────────────────
// The widget needs to know whether it should render at all, and when to ask
// for contact details, before the visitor has typed anything. This costs no
// model call, so it is not metered -- but it is still origin-checked, because
// it reveals which numbers we hand off to.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['probe'])) {
    if ($origin !== '' && !$originOk) {
        out(['ok' => false, 'reason' => 'origin'], 403);
    }
    $mode = (string)($config['web_chat_lead_mode'] ?? 'after');
    if (!in_array($mode, ['before', 'after', 'off'], true)) $mode = 'after';
    out([
        'ok'        => true,
        'enabled'   => PluginConfig::toBool($config['web_chat_enabled'] ?? false),
        'lead_mode' => $mode,
        'handoff'   => $handoff,
        'teaser'    => (string)($config['web_chat_teaser'] ?? ''),
        'teaser_delay' => max(0, (int)($config['web_chat_teaser_delay'] ?? 6)),
    ]);
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

// ── Lead capture ──────────────────────────────────────────────────────────
// Optional by design. A visitor who will not give a number before asking a
// question is exactly the visitor this channel exists to keep, so a missing
// lead is never a reason to refuse an answer.
$lead = is_array($body['lead'] ?? null) ? $body['lead'] : [];
$leadStored = false;
if ($lead) {
    $leadName  = mb_substr(trim((string)($lead['name'] ?? '')), 0, 80);
    $leadPhone = mb_substr(trim((string)($lead['phone'] ?? '')), 0, 32);
    $leadEmail = mb_substr(trim((string)($lead['email'] ?? '')), 0, 120);
    if ($leadEmail !== '' && !filter_var($leadEmail, FILTER_VALIDATE_EMAIL)) $leadEmail = '';
    // Digits only, so a phone number is comparable later. Keep a leading +.
    $leadPhone = preg_replace('/(?!^\+)[^0-9]/', '', $leadPhone);
    if ($leadPhone !== '' || $leadEmail !== '' || $leadName !== '') {
        try {
            $existing = $store->findOne('web_chat_leads.json', 'session', $session);
            $row = ['session' => $session, 'name' => $leadName, 'phone' => $leadPhone,
                    'email' => $leadEmail, 'updated' => gmdate('c')];
            if ($existing) {
                $store->updateOne('web_chat_leads.json', 'session', $session, $row);
            } else {
                $row['created'] = gmdate('c');
                // appendWithId, not append: updateOne locates a row by its id,
                // and a row written without one can never be updated afterwards
                // -- a visitor correcting their number would silently keep the
                // old one.
                $store->appendWithId('web_chat_leads.json', $row);
            }
            $leadStored = true;
        } catch (\Throwable $e) { /* never lose the reply over a lead */ }
    }
}

// A lead on its own is a complete request: store it, answer nothing, meter
// nothing. Without this the details would only survive if the visitor happened
// to send another message afterwards.
if ($message === '') {
    if ($leadStored) {
        out(['ok' => true, 'session' => $session, 'reply' => '',
             'lead_saved' => true, 'handoff' => $handoff]);
    }
    bail('Ask me anything about Starlink in Sudan.', $handoff, 400, 'empty');
}

$guard = new WebChatGuard($store, $config);
$gate  = $guard->check($ip, $session);
if (!$gate['ok']) {
    out(['ok' => false, 'reason' => $gate['reason'], 'reply' => $gate['message'],
         'handoff' => $handoff, 'retry_in' => $gate['retry_in'], 'session' => $session], 200);
}

$brain = new DishNetAiBrain($config);
if (!$brain->isConfigured()) {
    // The visitor gets a courteous fallback; the operator needs to know which
    // key is missing, or this is invisible until someone reports it.
    @file_put_contents($dataDir . '/ai_platform.log', sprintf(
        "[%s] web_chat: no API key for provider '%s' — website chat is answering with the "
        . "WhatsApp fallback only\n",
        gmdate('c'), (string)($config['ai_provider'] ?? 'claude')), FILE_APPEND);
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

$leadMode = (string)($config['web_chat_lead_mode'] ?? 'after');
if (!in_array($leadMode, ['before', 'after', 'off'], true)) $leadMode = 'after';
$haveLead = false;
try {
    $row = $store->findOne('web_chat_leads.json', 'session', $session);
    $haveLead = $row && (($row['phone'] ?? '') !== '' || ($row['email'] ?? '') !== '');
} catch (\Throwable $e) { /* treat as not captured */ }

out([
    'ok'        => true,
    'session'   => $session,
    'reply'     => $reply,
    'escalate'  => !empty($result['escalate']),
    'handoff'   => $handoff,
    'lead_mode' => $leadMode,
    'have_lead' => $haveLead,
]);
