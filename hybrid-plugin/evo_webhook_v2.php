<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json';

/**
 * evo_webhook_v2.php — authenticated Evolution API webhook receiver.
 *
 * Replaces evo_webhook.php, which accepts any POST from anyone. Deploy this
 * alongside the old one and cut instances over individually; delete the old
 * file once all three numbers are on this endpoint.
 *
 * Contract with Evolution API (v2.3.7):
 *
 *   POST https://crm.dishnetafrica.com/_plugins/dishnet-hybrid-telecom/evo_webhook_v2.php?token=<secret>
 *   Events: MESSAGES_UPSERT, MESSAGES_UPDATE, CONNECTION_UPDATE
 *
 * The token is the authentication — Evolution v2 does not sign payloads.
 * Because it travels in the URL, the endpoint MUST be HTTPS, and the token
 * must never be logged. See EvoWebhookGuard.
 *
 * This handler does no AI work and no CRM work. It validates, records, queues
 * and returns — normally in a few milliseconds. Everything expensive happens
 * in AiReplyWorker, driven by the existing EventBus.
 *
 * PHP 7.4 compatible.
 */

if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/EventBus.php';
require_once __DIR__ . '/lib/EvolutionApiService.php';
require_once __DIR__ . '/lib/EvoWebhookGuard.php';
require_once __DIR__ . '/lib/ConversationService.php';

/** Always answer Evolution quickly and in a shape it will not retry on. */
function evoRespond(int $code, string $outcome, array $extra = []): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => $code < 300 ? 'ok' : 'error', 'outcome' => $outcome] + $extra);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    evoRespond(405, 'post_required');
}

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];
$pdo    = $store->getPdo();

$guard = new EvoWebhookGuard($pdo, $config);

// ── 1. Authenticate ──────────────────────────────────────────────────────────
list($authOk, $authErr) = $guard->authenticate();
if (!$authOk) {
    error_log(EvoWebhookGuard::safeLogLine('-', '-', 'auth_failed'));
    // 401, not 403 — and deliberately terse. Do not describe what was wrong.
    evoRespond(401, 'unauthorised');
}

// ── 2. Parse ─────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') evoRespond(400, 'empty_body');
if (strlen($raw) > 512 * 1024)     evoRespond(413, 'body_too_large');

$payload = json_decode($raw, true);
if (!is_array($payload)) evoRespond(400, 'invalid_json');

$event    = EvoWebhookGuard::normaliseEvent($payload);
$instance = trim((string)($payload['instance'] ?? ($payload['instanceName'] ?? '')));

// ── 3. Validate event ────────────────────────────────────────────────────────
if (!$guard->isAllowedEvent($event)) {
    // 200 on purpose: the event is legitimate, we simply do not act on it.
    // A non-2xx would make Evolution retry something we will never want.
    evoRespond(200, 'event_ignored', ['event' => $event]);
}

// ── 4. Validate instance ─────────────────────────────────────────────────────
// An unmapped instance is rejected rather than defaulted. Guessing here would
// route one number's customers into another number's business context.
$evo     = new EvolutionApiService($config);
$channel = $evo->channelFor($instance);
if ($channel === '') {
    error_log(EvoWebhookGuard::safeLogLine($event, $instance, 'unknown_instance'));
    evoRespond(200, 'unknown_instance');
}

// Connection updates are worth recording but carry no message.
if ($event === 'connection.update') {
    $state = $payload['data']['state'] ?? ($payload['state'] ?? 'unknown');
    error_log(EvoWebhookGuard::safeLogLine($event, $instance, 'connection_' . $state));
    evoRespond(200, 'connection_recorded', ['channel' => $channel, 'state' => $state]);
}

// ── 5. Extract the message ───────────────────────────────────────────────────
$data = $payload['data'] ?? [];
if (!is_array($data)) evoRespond(200, 'no_data');

// MESSAGES_UPSERT can arrive as a single object or a list depending on build.
$messages = isset($data['key']) ? [$data] : (is_array($data) ? array_values($data) : []);

$queued  = 0;
$skipped = 0;
$bus     = new EventBus($pdo);
$convSvc = new ConversationService($dataDir, $pdo);

foreach ($messages as $msg) {
    if (!is_array($msg) || empty($msg['key'])) { $skipped++; continue; }

    $key       = $msg['key'];
    $messageId = (string)($key['id'] ?? '');
    $fromMe    = !empty($key['fromMe']);
    $remoteJid = (string)($key['remoteJid'] ?? '');

    // Our own outbound messages echo back. Record them, never reply to them.
    if ($fromMe) { $skipped++; continue; }

    // Groups and broadcasts are not customer conversations.
    if (str_contains($remoteJid, '@g.us') || str_contains($remoteJid, 'broadcast')) {
        $skipped++;
        continue;
    }

    // ── 6. Replay window ─────────────────────────────────────────────────
    $ts = isset($msg['messageTimestamp']) ? (int)$msg['messageTimestamp'] : null;
    if (!$guard->isFresh($ts)) {
        error_log(EvoWebhookGuard::safeLogLine($event, $instance, 'stale_message'));
        $skipped++;
        continue;
    }

    // ── 7. Idempotency ───────────────────────────────────────────────────
    // Claim before doing anything with side effects. A duplicate delivery
    // loses the race here and is dropped, so the customer is never answered
    // twice for one message.
    if (!$guard->claim($messageId, $instance, $event)) {
        $skipped++;
        continue;
    }

    // Prefer senderPn when present — @lid JIDs carry no phone number.
    $phone = EvolutionApiService::phoneFromJid((string)($key['senderPn'] ?? ''));
    if ($phone === '') $phone = EvolutionApiService::phoneFromJid($remoteJid);
    if ($phone === '') { $skipped++; continue; }

    $text = evoExtractText($msg);
    $pushName = trim((string)($msg['pushName'] ?? ''));

    // ── 8. Persist the inbound message ───────────────────────────────────
    // Storing before queueing means the conversation is complete in the admin
    // inbox even if AI processing later fails.
    $convId = null;
    try {
        $convId = $convSvc->importEvoMessage($msg, $channel);
    } catch (\Throwable $e) {
        error_log('[evo_webhook_v2] conversation store failed: ' . $e->getMessage());
    }

    // ── 9. Queue for the AI ──────────────────────────────────────────────
    // Media-only messages are stored and surfaced to staff but not sent to the
    // AI, which cannot act on them yet.
    if ($text === '') { $skipped++; continue; }

    try {
        $bus->emit(
            'ai.reply',
            'conversation',
            (int)($convId ?? 0),
            [
                'channel'           => $channel,
                'whatsapp_instance' => $instance,
                'customer_phone'    => $phone,
                'message'           => $text,
                'push_name'         => $pushName,
                'wa_message_id'     => $messageId,
                'remote_jid'        => $remoteJid,
                'received_at'       => gmdate('c'),
            ],
            3,                 // above normal: a waiting customer
            'evo_webhook'
        );
        $queued++;
    } catch (\Throwable $e) {
        error_log('[evo_webhook_v2] queue failed: ' . $e->getMessage());
    }
}

// Opportunistic housekeeping, roughly 1 request in 200.
if (random_int(1, 200) === 1) $guard->prune();

error_log(EvoWebhookGuard::safeLogLine($event, $instance, "queued={$queued} skipped={$skipped}"));
evoRespond(200, 'accepted', ['channel' => $channel, 'queued' => $queued, 'skipped' => $skipped]);


/**
 * Pull readable text out of Evolution's message envelope.
 * Covers the shapes v2.3.7 emits for plain text, extended text and captions.
 */
function evoExtractText(array $msg): string
{
    $m = $msg['message'] ?? [];
    if (!is_array($m)) return '';

    $candidates = [
        $m['conversation'] ?? null,
        $m['extendedTextMessage']['text'] ?? null,
        $m['imageMessage']['caption'] ?? null,
        $m['videoMessage']['caption'] ?? null,
        $m['documentMessage']['caption'] ?? null,
        $m['buttonsResponseMessage']['selectedDisplayText'] ?? null,
        $m['listResponseMessage']['title'] ?? null,
        $m['templateButtonReplyMessage']['selectedDisplayText'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') return trim($c);
    }
    return '';
}
