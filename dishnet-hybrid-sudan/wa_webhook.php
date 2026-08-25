<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json'; // webhooks always return JSON

/**
 * wa_webhook.php — WhatsApp Incoming Message Receiver
 *
 * Configure your WA Sender (wa-whatsappsender) plugin to POST incoming
 * messages to this URL:
 *
 *   https://yourserver.com/_plugins/dishnet-hybrid-telecom/wa_webhook.php
 *
 * ── In WASender Settings → Webhook:
 *      Webhook URL    : https://yourserver.com/_plugins/dishnet-hybrid-telecom/wa_webhook.php
 *      Webhook Secret : (paste wa_webhook_secret from DishNet Settings)
 *
 * ── Expected POST payload (WASender format):
 *   {
 *     "app_key":  "...",
 *     "sender":   "211912345678",          ← customer phone
 *     "message":  "Hello, I need help",
 *     "type":     "text",
 *     "name":     "John Doe",              ← optional display name
 *     "timestamp": 1712345678
 *   }
 *
 * Also accepts alternative field names used by some WASender versions:
 *   "from", "phone", "contact", "body", "text"
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/WaBotService.php';

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];

// ── Response helper ────────────────────────────────────────────────────────
function waResp(int $code, string $msg, array $data = []): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => $code < 300 ? 'ok' : 'error', 'message' => $msg] + $data);
    exit;
}

function waLog(string $event, string $msg, array $data = []): void {
    global $dataDir;
    $logFile = $dataDir . '/wa_webhook_log.json';
    $log = [];
    if (file_exists($logFile)) {
        $raw = json_decode(file_get_contents($logFile), true);
        $log = is_array($raw) ? $raw : [];
    }
    $maxId = empty($log) ? 0 : max(array_map(fn($r) => (int)($r['id'] ?? 0), $log));
    array_unshift($log, [
        'id'          => $maxId + 1,
        'event'       => $event,
        'message'     => $msg,
        'data'        => $data,
        'received_at' => date('Y-m-d H:i:s'),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    $log = array_slice($log, 0, 500);
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── Only accept POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    waResp(405, 'POST required.');
}

// ── Verify webhook secret ─────────────────────────────────────────────────
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) waResp(400, 'Empty body.');

$secret = trim($config['wa_webhook_secret'] ?? '');
if ($secret !== '') {
    $receivedSig = $_SERVER['HTTP_X_WA_SECRET']
                ?? $_SERVER['HTTP_X_WEBHOOK_SECRET']
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    // Strip "Bearer " prefix if present
    $receivedSig = preg_replace('/^Bearer\s+/i', '', trim($receivedSig));

    if (!hash_equals($secret, $receivedSig)) {
        // Also allow secret as query param for easy testing
        $qSecret = trim($_GET['secret'] ?? '');
        if (!hash_equals($secret, $qSecret)) {
            waLog('auth_failed', 'Invalid webhook secret');
            waResp(401, 'Unauthorized.');
        }
    }
}

// ── Parse payload ─────────────────────────────────────────────────────────
$payload = json_decode($rawBody, true);
if (!is_array($payload)) waResp(400, 'Invalid JSON.');

// Normalise field names — WASender uses different keys in different versions
$phone   = $payload['sender']   ?? $payload['from']    ?? $payload['phone']
        ?? $payload['contact']  ?? $payload['msisdn']  ?? '';
$text    = $payload['message']  ?? $payload['body']    ?? $payload['text']
        ?? $payload['content']  ?? '';
$name    = $payload['name']     ?? $payload['display_name'] ?? $payload['contact_name'] ?? null;
$type    = $payload['type']     ?? 'text';

// ── Pusher dedup: if message came from dishnet_wa_pusher, track pkId ─────────
// Fix #3: Uses SQLite INSERT OR IGNORE instead of JSON file (was race condition).
$pusherPkId  = (int)($payload['pkId'] ?? 0);
$isPusherMsg = ($payload['_source'] ?? '') === 'dishnet_pusher';

if ($isPusherMsg && $pusherPkId > 0) {
    $wpdo = $store->getPdo();

    // Ensure table exists (migration 054 may not have run yet)
    try {
        $wpdo->exec("CREATE TABLE IF NOT EXISTS wa_pusher_processed (
            pkid INTEGER PRIMARY KEY,
            processed_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $wpdo->exec("CREATE TABLE IF NOT EXISTS wa_sync_cursor (
            id INTEGER PRIMARY KEY DEFAULT 1,
            last_pkid INTEGER NOT NULL DEFAULT 0,
            total_synced INTEGER NOT NULL DEFAULT 0,
            last_sync_at TEXT,
            last_batch_size INTEGER NOT NULL DEFAULT 0
        )");
        $wpdo->exec("INSERT OR IGNORE INTO wa_sync_cursor (id, last_pkid) VALUES (1, 0)");
    } catch (\Throwable $_e) {}

    // Atomic dedup — INSERT OR IGNORE is atomic in SQLite; rowCount=0 means duplicate
    try {
        $wstmt = $wpdo->prepare("INSERT OR IGNORE INTO wa_pusher_processed (pkid) VALUES (?)");
        $wstmt->execute([$pusherPkId]);
        $wInserted = $wstmt->rowCount();
    } catch (\Throwable $_e) {
        $wInserted = 1; // on error assume new — safer to process than skip
    }

    if ($wInserted === 0) {
        waResp(409, 'Already processed.', ['pkId' => $pusherPkId]);
    }

    // Prune old entries (keep last 1000) — prevents unbounded table growth
    try {
        $wpdo->exec("DELETE FROM wa_pusher_processed WHERE pkid NOT IN
            (SELECT pkid FROM wa_pusher_processed ORDER BY pkid DESC LIMIT 1000)");
    } catch (\Throwable $_e) {}

    // Advance wa_sync_cursor so cron_wa_sync skips this pkId
    try {
        $wCursor = (int)$wpdo->query("SELECT last_pkid FROM wa_sync_cursor WHERE id=1")->fetchColumn();
        if ($pusherPkId > $wCursor) {
            $wpdo->prepare("UPDATE wa_sync_cursor SET last_pkid=?, last_sync_at=datetime('now') WHERE id=1")
                 ->execute([$pusherPkId]);
        }
    } catch (\Throwable $_e) {}
}


// ── Media payload logger — capture exact payload for non-text messages ──────
// This logs the FULL payload to SQLite so we can see exactly what WASender
// sends for images, audio, video — then build proper handling from real data.
if (!in_array(strtolower($type), ['text', 'chat', 'conversation', ''])) {
    try {
        $store->getPdo()->exec("CREATE TABLE IF NOT EXISTS wa_media_debug (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT, phone TEXT, payload TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $store->getPdo()->prepare(
            "INSERT INTO wa_media_debug (type, phone, payload, created_at) VALUES (?,?,?,datetime('now'))"
        )->execute([
            strtolower($type),
            $phone,
            // Log everything EXCEPT actual base64 data to keep size small
            json_encode(array_map(function($v) {
                if (is_string($v) && strlen($v) > 200) return '[' . strlen($v) . ' bytes]';
                return $v;
            }, $payload)),
        ]);
    } catch (Throwable $e) {}
}

// ── Media message handling ─────────────────────────────────────────────────
// Map WASender type → human-readable and acknowledgment text
$_waMediaAck = [
    'image'    => "Thanks for the photo 📷 Could you also describe the issue in a message so we can help faster? Our team will review the image.",
    'video'    => "Thanks for the video. Could you also type a brief description of the issue? Our team will review it.",
    'audio'    => "We received your voice note. We're not able to listen to audio messages automatically — could you also type a quick message describing what you need? Our team will listen and respond.",
    'ptt'      => "We received your voice note. We're not able to listen to audio messages automatically — could you also type a quick message describing what you need?",
    'document' => "Got your document. What is this regarding? Our team will review it.",
    'location' => "Got your location 📍 Are you requesting a site visit or installation? Type YES to confirm or tell us more.",
    'sticker'  => null,  // ignore silently
    'contact'  => "Got the contact. What would you like us to do with it?",
    'vcard'    => "Got the contact. What would you like us to do with it?",
];
$_waTypeNorm = strtolower($type);
if (!in_array($_waTypeNorm, ['text', 'chat', 'conversation', ''])) {
    $ackMsg = $_waMediaAck[$_waTypeNorm] ?? null;
    if ($ackMsg && !empty($phone) && (!empty($config['wa_bot_enabled']) || !empty($config['wa_auto_reply_enabled']))) {
        // Don't ack on accounts number if accounts autoreply is disabled
        if (($waChannel ?? 'support') === 'accounts' && empty($config['wa_accounts_autoreply_enabled'])) {
            waLog('accounts_autoreply_off', "Accounts auto-reply disabled — media from {$phone} logged only");
        } else {
        try {
            require_once __DIR__ . '/lib/ConversationService.php';
            $_waMedConvSvc = new ConversationService($dataDir, $store->getPdo());
            $_waMedConv    = $_waMedConvSvc->ensureConversation($phone, $waChannel ?? 'support', $name ?: null, 'webhook');
            $_waMedConvSvc->storeMessage($_waMedConv['id'], [
                'direction'  => 'in',
                'role'       => 'customer',
                'body'       => '[' . strtoupper($_waTypeNorm) . ' received]',
                'media_type' => $_waTypeNorm,
                'sent_at'    => date('Y-m-d H:i:s'),
            ]);
            // Send ack
            require_once __DIR__ . '/lib/NotificationService.php';
            $_waMedNotify = new NotificationService($store, $config);
            $_waMedNotify->sendRaw($phone, $ackMsg, 'wa_media_ack');
            // Store reply
            $_waMedConvSvc->storeMessage($_waMedConv['id'], [
                'direction'  => 'out',
                'role'       => 'agent',
                'body'       => $ackMsg,
                'agent_name' => 'DishNet Bot',
                'sent_at'    => date('Y-m-d H:i:s'),
            ]);
            // Alert team
            $_waMedNotify->sendAdmin(
                "📎 *Media received from " . ($name ?: $phone) . "* ({$phone})\nType: " . strtoupper($_waTypeNorm) . "\n\n_Auto-acknowledged. Please review in WA Inbox._",
                'wa_media_received'
            );
        } catch (Throwable $e) { /* non-fatal */ }
        } // end else (accounts autoreply enabled)
    } elseif (!$ackMsg) {
        waLog('skipped', "Sticker/unknown media type: {$type}", ['phone' => $phone]);
    }
    waResp(200, "Media message ({$type}) acknowledged.");
}

if (empty($phone)) waResp(400, 'Missing sender phone.');
if (empty($text))  waResp(200, 'Empty message — ignored.');

// ── Store in conversation SQLite store (always, even if bot disabled) ─────
// Determine channel: match app_key to support or accounts
$incomingAppKey = $payload['app_key'] ?? '';
$waChannel = 'support'; // default
if ($incomingAppKey && $incomingAppKey === ($config['wa_accounts_app_key'] ?? '')) {
    $waChannel = 'accounts';
}

try {
    require_once __DIR__ . '/lib/ConversationService.php';
    $convSvc = new ConversationService($dataDir, $store->getPdo());
    $conv2 = $convSvc->ensureConversation($phone, $waChannel, $name ?: null, 'webhook');
    $convSvc->storeMessage($conv2['id'], [
        'direction'     => 'in',
        'role'          => 'customer',
        'body'          => $text,
        'wa_message_id' => $payload['message_id'] ?? $payload['id'] ?? null,
        'sent_at'       => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    // Never break webhook for conversation logging
    waLog('conv_store_error', $e->getMessage());
}

// ── Check bot is enabled ───────────────────────────────────────────────────
// ── Check auto-reply is enabled ───────────────────────────────────────
if (empty($config['wa_bot_enabled']) && empty($config['wa_auto_reply_enabled'])) {
    waLog('bot_disabled', "Auto-reply disabled — message from {$phone} logged only");
    waResp(200, 'Auto-reply disabled — message logged only.');
}

// ── Per-channel guard: Accounts number can be silenced independently ───────
// wa_accounts_autoreply_enabled = false  →  log only, no auto-reply on Accounts WA
// Support channel is completely unaffected by this flag.
if ($waChannel === 'accounts' && empty($config['wa_accounts_autoreply_enabled'])) {
    waLog('accounts_autoreply_off', "Accounts auto-reply disabled — message from {$phone} logged only");
    waResp(200, 'Accounts auto-reply disabled — message logged only.');
}

// ── Process via WaAutoReplyService (channel-aware) ──────────────────
require_once __DIR__ . '/lib/WaAutoReplyService.php';
$notify = new NotificationService($store, $config);

try {
    require_once __DIR__ . '/lib/ConversationService.php';
    $_arConvSvc = new ConversationService($dataDir, $store->getPdo());
    $autoReply = new WaAutoReplyService($store, $store->getPdo(), $notify, $config, $_arConvSvc);
    $result = $autoReply->handleIncoming($phone, $text, $waChannel, $name ?: null, $conv2['id'] ?? null);
    waLog('message_received', "Processed via WaAutoReply ({$waChannel})", [
        'phone'   => $phone,
        'text'    => substr($text, 0, 100),
        'channel' => $waChannel,
        'replied' => $result['replied'],
        'action'  => $result['action'],
    ]);
    waResp(200, 'Message processed.', ['channel' => $waChannel, 'replied' => $result['replied'], 'action' => $result['action']]);
} catch (Throwable $e) {
    waLog('error', 'Exception: ' . $e->getMessage(), ['phone' => $phone, 'trace' => substr($e->getTraceAsString(), 0, 300)]);
    waResp(500, 'Internal error: ' . $e->getMessage());
}
