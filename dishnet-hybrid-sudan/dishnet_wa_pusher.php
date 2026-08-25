#!/usr/bin/env php
<?php
/**
 * dishnet_wa_pusher.php
 * ═══════════════════════════════════════════════════════════════════
 * UPLOAD THIS FILE TO: /www/wwwroot/wa.dishnetafrica.com/dishnet_wa_pusher.php
 * (or wherever your WhatsML root is on the wa.dishnetafrica.com server)
 *
 * PURPOSE:
 *   Monitors the WhatsML MySQL database for new incoming messages
 *   and pushes them in real-time to the DishNet plugin webhook.
 *   Runs every 10 seconds via cron — gives <15 second message delivery.
 *
 * CRON (add to crontab on wa.dishnetafrica.com server):
 *   * * * * * /usr/bin/php /www/wwwroot/wa.dishnetafrica.com/dishnet_wa_pusher.php >> /www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher.log 2>&1
 *   * * * * * sleep 10 && /usr/bin/php /www/wwwroot/wa.dishnetafrica.com/dishnet_wa_pusher.php >> /www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher.log 2>&1
 *   * * * * * sleep 20 && /usr/bin/php /www/wwwroot/wa.dishnetafrica.com/dishnet_wa_pusher.php >> /www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher.log 2>&1
 *   * * * * * sleep 30 && /usr/bin/php /www/wwwroot/wa.dishnetafrica.com/dishnet_wa_pusher.php >> /www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher.log 2>&1
 *   * * * * * sleep 40 && /usr/bin/php /www/wwwroot/wa.dishnetafrica.com/dishnet_wa_pusher.php >> /www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher.log 2>&1
 *   * * * * * sleep 50 && /usr/bin/php /www/wwwroot/wa.dishnetafrica.com/dishnet_wa_pusher.php >> /www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher.log 2>&1
 *   (This fires every 10 seconds — 6 times per minute)
 *
 * HOW IT WORKS:
 *   1. Reads WhatsML MySQL for messages newer than last pushed pkId
 *   2. POSTs each message to plugin wa_webhook.php (same format as WASender webhook)
 *   3. Plugin processes it instantly — auto-reply fires immediately
 *   4. Saves cursor to /tmp/dishnet_pusher_cursor.txt between runs
 *
 * RESULT: Messages appear in inbox in ~5-15 seconds (down from 5+ minutes)
 * ═══════════════════════════════════════════════════════════════════
 */

// ── CONFIG — edit these to match your WhatsML setup ─────────────────────────
$DB_HOST     = '127.0.0.1';
$DB_PORT     = 3306;
$DB_NAME     = 'wa_dishnetafrica';        // WhatsML MySQL database name
$DB_USER     = 'root';                     // MySQL user — check .env on WA server
$DB_PASS     = '';                         // MySQL password — check .env on WA server

// DishNet plugin webhook URL (the plugin's wa_webhook.php)
$WEBHOOK_URL = 'https://crm.dishnetafrica.com/_plugins/dishnet-hybrid-telecom/wa_webhook.php';

// Shared secret (must match "Webhook Secret" in Plugin → WA Settings)
$WEBHOOK_SECRET = 'dnet_wa_feed_2026_x9k4';

// Session → channel mapping (WhatsML session IDs)
// Find these in your WhatsML dashboard or the wa_sync_state.json on the CRM server
$SESSION_MAP = [
    '7cc8d42a-fe45-4a84-80dc-0ae4b0f42cfc' => 'support',   // Support number
    '1d327e8a-de0c-4888-8f81-21675c70ef1e' => 'accounts',  // Accounts number
];

// Cursor file — stores last processed pkId between runs
// IMPORTANT: Must be outside /tmp which is wiped on server reboot.
// A reboot with /tmp cursor would reset to 0 and re-process ALL historical
// messages, sending duplicate bot replies to every customer.
$CURSOR_FILE = '/www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher_cursor.txt';

// Max messages per run (safety cap)
$BATCH_SIZE  = 50;
// ────────────────────────────────────────────────────────────────────────────

date_default_timezone_set('Africa/Juba');

// Ensure persistent data directory exists
$_pusherDataDir = '/www/wwwroot/wa.dishnetafrica.com/data';
if (!is_dir($_pusherDataDir)) @mkdir($_pusherDataDir, 0755, true);

function pusherLog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// ── Single-instance lock ─────────────────────────────────────────────────────
$lockFile = '/www/wwwroot/wa.dishnetafrica.com/data/dishnet_pusher.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another instance is running — skip this run
    fclose($lockFp);
    exit(0);
}
register_shutdown_function(function() use ($lockFp, $lockFile) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
});

// ── Load cursor ──────────────────────────────────────────────────────────────
$lastPkId = 0;
if (file_exists($CURSOR_FILE)) {
    $lastPkId = (int)trim(file_get_contents($CURSOR_FILE));
}

// First run: start from current max to avoid re-processing old messages
if ($lastPkId === 0) {
    try {
        $pdo = new PDO(
            "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER, $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $maxRow = $pdo->query("SELECT COALESCE(MAX(pkId), 0) FROM `Message`")->fetchColumn();
        $lastPkId = (int)$maxRow;
        file_put_contents($CURSOR_FILE, $lastPkId);
        pusherLog("First run — cursor set to pkId={$lastPkId}. Waiting for new messages.");
        exit(0);
    } catch (Throwable $e) {
        pusherLog("DB connect failed (first run): " . $e->getMessage());
        exit(1);
    }
}

// ── Connect to MySQL ─────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
} catch (Throwable $e) {
    pusherLog("DB connect failed: " . $e->getMessage());
    exit(1);
}

// ── Fetch new INCOMING messages since cursor ─────────────────────────────────
// Only fetch messages where fromMe=0 (customer sent) — outgoing already handled by plugin
try {
    $stmt = $pdo->prepare(
        "SELECT pkId, sessionId, remoteJid, message, pushName, `key`, messageTimestamp, fromMe
         FROM `Message`
         WHERE pkId > ?
           AND fromMe = 0
         ORDER BY pkId ASC
         LIMIT ?"
    );
    $stmt->execute([$lastPkId, $BATCH_SIZE]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    pusherLog("DB query failed: " . $e->getMessage());
    exit(1);
}

if (empty($rows)) {
    // No new messages — just update cursor to current max to avoid scanning forever
    try {
        $maxNow = (int)$pdo->query("SELECT COALESCE(MAX(pkId), {$lastPkId}) FROM `Message`")->fetchColumn();
        if ($maxNow > $lastPkId) {
            // There are outgoing messages we skipped — advance cursor
            file_put_contents($CURSOR_FILE, $maxNow);
        }
    } catch (Throwable $e) {}
    exit(0);
}

pusherLog("Found " . count($rows) . " new incoming messages (cursor was pkId={$lastPkId})");

// ── Helper: extract text from Baileys message JSON ───────────────────────────
function extractBaileysText(string $msgRaw): array {
    $msgJson = json_decode($msgRaw, true);
    if (is_string($msgJson)) $msgJson = json_decode($msgJson, true);
    if (!is_array($msgJson)) return ['text' => '', 'type' => 'text'];

    // Standard text message
    if (!empty($msgJson['conversation']))
        return ['text' => $msgJson['conversation'], 'type' => 'text'];

    // Extended text (links, formatting)
    if (!empty($msgJson['extendedTextMessage']['text']))
        return ['text' => $msgJson['extendedTextMessage']['text'], 'type' => 'text'];

    // Image with caption
    if (isset($msgJson['imageMessage'])) {
        $cap = $msgJson['imageMessage']['caption'] ?? '';
        return ['text' => $cap ?: '[Image]', 'type' => 'image'];
    }

    // Audio
    if (isset($msgJson['audioMessage']))
        return ['text' => '[Voice message]', 'type' => 'audio'];

    // Document
    if (isset($msgJson['documentMessage'])) {
        $fn = $msgJson['documentMessage']['fileName'] ?? 'document';
        return ['text' => "[Document: {$fn}]", 'type' => 'document'];
    }

    // Video
    if (isset($msgJson['videoMessage'])) {
        $cap = $msgJson['videoMessage']['caption'] ?? '';
        return ['text' => $cap ?: '[Video]', 'type' => 'video'];
    }

    // Sticker
    if (isset($msgJson['stickerMessage']))
        return ['text' => '[Sticker]', 'type' => 'sticker'];

    // Location
    if (isset($msgJson['locationMessage'])) {
        $lat = $msgJson['locationMessage']['degreesLatitude'] ?? '';
        $lng = $msgJson['locationMessage']['degreesLongitude'] ?? '';
        return ['text' => "[Location: {$lat},{$lng}]", 'type' => 'location'];
    }

    // Contact
    if (isset($msgJson['contactMessage']))
        return ['text' => '[Contact shared]', 'type' => 'contact'];

    return ['text' => '', 'type' => 'unknown'];
}

// ── Process and push each message ────────────────────────────────────────────
$pushed  = 0;
$skipped = 0;
$errors  = 0;
$maxPkId = $lastPkId;

foreach ($rows as $row) {
    $pkId = (int)$row['pkId'];
    if ($pkId > $maxPkId) $maxPkId = $pkId;

    // Map session to channel
    $sessionId = $row['sessionId'] ?? '';
    $channel   = $SESSION_MAP[$sessionId] ?? null;

    // Also try to match by partial session name (WhatsML sometimes strips prefix)
    if (!$channel) {
        foreach ($SESSION_MAP as $sessKey => $chan) {
            if (strpos($sessKey, $sessionId) !== false || strpos($sessionId, substr($sessKey, 0, 8)) !== false) {
                $channel = $chan;
                break;
            }
        }
    }

    if (!$channel) {
        pusherLog("pkId={$pkId}: skipped — unknown session '{$sessionId}'");
        $skipped++;
        continue;
    }

    // Extract phone from remoteJid (e.g. "211912345678@s.whatsapp.net")
    $phone = preg_replace('/[^0-9]/', '', explode('@', $row['remoteJid'])[0]);
    if (empty($phone) || strlen($phone) < 7) {
        pusherLog("pkId={$pkId}: skipped — invalid phone from remoteJid '{$row['remoteJid']}'");
        $skipped++;
        continue;
    }

    // Skip group messages
    if (strpos($row['remoteJid'], '@g.us') !== false) {
        $skipped++;
        continue;
    }

    // Extract message text and type
    $parsed = extractBaileysText($row['message'] ?? '{}');
    $text   = $parsed['text'];
    $type   = $parsed['type'];

    // Get display name from pushName or key JSON
    $name = $row['pushName'] ?? null;
    if (!$name) {
        $keyJson = json_decode($row['key'] ?? '{}', true);
        $name    = $keyJson['pushName'] ?? $keyJson['name'] ?? null;
    }

    // Timestamp
    $ts = (int)($row['messageTimestamp'] ?? time());

    // ── Build webhook payload (same format as WASender webhook) ──────────────
    $payload = [
        'sender'    => $phone,
        'message'   => $text,
        'type'      => $type,
        'name'      => $name,
        'timestamp' => $ts,
        'channel'   => $channel,       // DishNet extension: channel hint
        'pkId'      => $pkId,          // DishNet extension: cursor tracking
        'sessionId' => $sessionId,
        '_source'   => 'dishnet_pusher',
    ];

    // ── POST to plugin webhook ────────────────────────────────────────────────
    $ch = curl_init($WEBHOOK_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-WA-Secret: ' . $WEBHOOK_SECRET,
        ],
        CURLOPT_SSL_VERIFYPEER => false, // internal server call
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        pusherLog("pkId={$pkId} [{$phone}]: CURL error — {$curlError}");
        $errors++;
        continue;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        pusherLog("pkId={$pkId} [{$phone}] [{$channel}]: ✓ pushed — type={$type} text=" . mb_substr($text, 0, 50));
        $pushed++;
    } elseif ($httpCode === 409) {
        // 409 = duplicate (already processed) — not an error, just advance cursor
        $skipped++;
    } else {
        pusherLog("pkId={$pkId} [{$phone}]: HTTP {$httpCode} — " . substr($response, 0, 200));
        $errors++;
    }

    // Small sleep between pushes to avoid hammering the webhook
    if ($pushed > 1) usleep(100000); // 100ms between messages
}

// ── Save cursor ──────────────────────────────────────────────────────────────
file_put_contents($CURSOR_FILE, $maxPkId);
pusherLog("Done. pushed={$pushed} skipped={$skipped} errors={$errors} cursor={$maxPkId}");
