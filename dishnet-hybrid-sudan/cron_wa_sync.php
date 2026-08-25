#!/usr/bin/env php
<?php
/**
 * DishNet WhatsApp Message Sync Cron
 * ══════════════════════════════════════════════════════════════════════
 * Polls WhatsML message_feed.php API over HTTP every 60 seconds.
 * No pdo_mysql needed — uses curl only.
 *
 * Cron: (every 1 min) via cron/master.php
 *
 * PHP 7.4 compatible.
 */

date_default_timezone_set('Africa/Juba');
chdir(__DIR__);

require_once __DIR__ . '/lib/error_handler.php';
$GLOBALS['_DISHNET_ERROR_FORMAT'] = 'json';

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/ConversationService.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$convSvc = new ConversationService($dataDir, $store->getPdo());

// ── Single-instance lock ────────────────────────────────────────────
$lockFile = $dataDir . '/cron_wa_sync.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    return;
}
set_time_limit(120);

function wsLog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// ── Config ──────────────────────────────────────────────────────────
$feedUrl    = $config['wa_feed_url']    ?? 'https://wa.dishnetafrica.com/message_feed.php';
$feedSecret = $config['wa_feed_secret'] ?? 'dnet_wa_feed_2026_x9k4';
$syncEnabled = (bool)($config['wa_sync_enabled'] ?? true);
$batchSize   = (int)($config['wa_sync_batch_size'] ?? 200);

// Session to channel mapping
$sessionMap = [
    ($config['wa_session_support']  ?? '7cc8d42a-fe45-4a84-80dc-0ae4b0f42cfc') => 'support',
    ($config['wa_session_accounts'] ?? '1d327e8a-de0c-4888-8f81-21675c70ef1e') => 'accounts',
];

if (!$syncEnabled) {
    wsLog('WA sync disabled.');
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}

// ── Load sync state ─────────────────────────────────────────────────
$stateFile  = $dataDir . '/wa_sync_state.json';
$state      = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$lastPkId   = (int)($state['last_synced_pkId'] ?? 0);
$totalSynced = (int)($state['total_synced'] ?? 0);

wsLog("=== WA Sync Start === cursor={$lastPkId}");

// ── Fetch from WhatsML feed API ─────────────────────────────────────
$url = $feedUrl . '?secret=' . urlencode($feedSecret)
     . '&since=' . $lastPkId
     . '&limit=' . $batchSize;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_FOLLOWLOCATION => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    wsLog("ERROR: curl failed: {$curlErr}");
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}
if ($httpCode !== 200) {
    wsLog("ERROR: HTTP {$httpCode} from feed API. Response: " . substr($response, 0, 300));
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}

$data = json_decode($response, true);
if (!$data || empty($data['ok'])) {
    wsLog("ERROR: Invalid response from feed API: " . substr($response, 0, 300));
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}

$rows = $data['messages'] ?? [];
if (empty($rows)) {
    wsLog('No new messages. Done.');
    // Still update last_sync_at so UI shows green dot
    $state['last_sync_at'] = date('Y-m-d H:i:s');
    $state['last_batch_size'] = 0;
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}

wsLog('Fetched ' . count($rows) . ' new messages from WhatsML.');

// ── CRM client cache for auto-linking ───────────────────────────────
$clientIndex = $store->load('client_search_index.json') ?? [];
$clientPhoneMap = [];
foreach ($clientIndex as $c) {
    $ph = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
    if (strlen($ph) >= 9) {
        $clientPhoneMap[substr($ph, -9)] = ['id' => (int)$c['id'], 'name' => $c['name'] ?? ''];
    }
}

// ── Process messages ────────────────────────────────────────────────
$stored    = 0;
$skipped   = 0;
$newConvs  = 0;
$linked    = 0;
$maxPkId   = $lastPkId;
$errors    = 0;

foreach ($rows as $row) {
    $pkId = (int)$row['pkId'];
    if ($pkId > $maxPkId) $maxPkId = $pkId;

    try {
        // 1. Map session to channel
        $channel = $sessionMap[$row['sessionId']] ?? null;
        if (!$channel) { $skipped++; continue; }

        // 2. Extract phone from remoteJid
        $phone = preg_replace('/[^0-9]/', '', explode('@', $row['remoteJid'])[0]);
        if (empty($phone) || strlen($phone) < 7) { $skipped++; continue; }

        // 3. Parse Baileys JSON (WhatsML stores it double-encoded: JSON string inside JSON string)
        $msgRaw = $row['message'] ?? '{}';
        $msgJson = json_decode($msgRaw, true);
        // If first decode gives a string, it's double-encoded — decode again
        if (is_string($msgJson)) {
            $msgJson = json_decode($msgJson, true);
        }
        if (!is_array($msgJson)) $msgJson = [];
        $parsed = parseBaileysMessage($msgJson);

        if (empty($parsed['body']) && empty($parsed['media_type'])) { $skipped++; continue; }

        // 4. Direction
        $fromMe   = (bool)$row['fromMe'];
        $direction = $fromMe ? 'out' : 'in';
        $role      = $fromMe ? 'agent' : 'customer';
        $pushName  = $row['pushName'] ?? null;

        // 5. Timestamp
        $ts = (int)$row['messageTimestamp'];
        $sentAt = $ts > 0 ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        // 6. Ensure conversation
        $conv = $convSvc->ensureConversation($phone, $channel, $pushName, 'wa_sync');
        $isNew = (strtotime($conv['created_at'] ?? '') >= time() - 5);

        // 7. Store message (dedup by wa_message_id)
        $msgId = $convSvc->storeMessage($conv['id'], [
            'direction'     => $direction,
            'role'          => $role,
            'body'          => $parsed['body'] ?: ('[' . ($parsed['media_type'] ?? 'media') . ']'),
            'media_type'    => $parsed['media_type'],
            'media_url'     => $parsed['media_url'],
            'wa_message_id' => $row['id'],
            'agent_name'    => $fromMe ? 'DishNet' : null,
            'sent_at'       => $sentAt,
        ]);

        if ($msgId === null) { $skipped++; continue; }
        $stored++;

        // 8a. Auto-reply for incoming customer messages (only fresh — within last 5 mins)
        $msgAge = time() - $ts;
        // Skip own business numbers
        $_ownPhones = ['211921443002', '211921443006']; // Support + Accounts numbers
        $_isOwnNum = in_array($phone, $_ownPhones) || in_array(substr($phone, -9), array_map(fn($p) => substr($p, -9), $_ownPhones));
        if ($direction === 'in' && !empty($parsed['body']) && !empty($config['wa_bot_enabled']) && $msgAge < 300 && !$_isOwnNum) {
            wsLog("  Auto-reply check: phone={$phone} channel={$channel} age={$msgAge}s text=" . substr($parsed['body'], 0, 30));
            try {
                if (!isset($autoReplySvc)) {
                    require_once __DIR__ . '/lib/NotificationService.php';
                    require_once __DIR__ . '/lib/WaAutoReplyService.php';
                    $notify = new NotificationService($store, $config);
                    $autoReplySvc = new WaAutoReplyService($store, $store->getPdo(), $notify, $config, $convSvc);
                }
                $arResult = $autoReplySvc->handleIncoming($phone, $parsed['body'], $channel, $pushName, $conv['id']);
                if ($arResult['replied']) {
                    wsLog("  Auto-replied to {$phone} on {$channel}: {$arResult['action']}");
                } else {
                    wsLog("  Auto-reply skipped for {$phone}: {$arResult['action']}");
                }
            } catch (Throwable $e) {
                wsLog("  Auto-reply error for {$phone}: " . $e->getMessage());
            }
        } elseif ($direction === 'in' && !empty($parsed['body']) && !empty($config['wa_bot_enabled'])) {
            wsLog("  Skipped auto-reply: phone={$phone} age={$msgAge}s own=" . ($_isOwnNum ? 'yes' : 'no'));
        }

        // 8b. Auto-link to CRM
        if ($isNew && empty($conv['crm_client_id'])) {
            $phoneTail = substr($phone, -9);
            $match = $clientPhoneMap[$phoneTail] ?? null;
            if ($match) {
                $convSvc->linkToCrm($conv['id'], $match['id'], $match['name']);
                $linked++;
            }
            $newConvs++;
        }
    } catch (Throwable $e) {
        $errors++;
        wsLog("ERROR pkId={$pkId}: " . $e->getMessage());
    }
}

// ── Update sync state ───────────────────────────────────────────────
$state['last_synced_pkId'] = $maxPkId;
$state['total_synced']     = $totalSynced + $stored;
$state['last_sync_at']     = date('Y-m-d H:i:s');
$state['last_batch_size']  = count($rows);
$state['last_stored']      = $stored;
$state['last_skipped']     = $skipped;
$state['last_errors']      = $errors;
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

wsLog("Done: stored={$stored}, skipped={$skipped}, errors={$errors}, newConvs={$newConvs}, linked={$linked}, maxPkId={$maxPkId}");

flock($lockFp, LOCK_UN);
fclose($lockFp);

// ══════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════

function parseBaileysMessage(array $msg): array
{
    $body = '';
    $mediaType = null;
    $mediaUrl  = null;

    if (isset($msg['conversation'])) {
        $body = $msg['conversation'];
    } elseif (isset($msg['extendedTextMessage']['text'])) {
        $body = $msg['extendedTextMessage']['text'];
    }

    if (isset($msg['imageMessage'])) {
        $mediaType = 'image';
        $mediaUrl  = $msg['imageMessage']['url'] ?? null;
        if (empty($body)) $body = $msg['imageMessage']['caption'] ?? '';
    }
    if (isset($msg['documentMessage'])) {
        $mediaType = 'document';
        $mediaUrl  = $msg['documentMessage']['url'] ?? null;
        if (empty($body)) $body = $msg['documentMessage']['caption'] ?? ($msg['documentMessage']['fileName'] ?? '');
    }
    if (isset($msg['audioMessage'])) {
        $mediaType = 'audio';
        $mediaUrl  = $msg['audioMessage']['url'] ?? null;
    }
    if (isset($msg['videoMessage'])) {
        $mediaType = 'video';
        $mediaUrl  = $msg['videoMessage']['url'] ?? null;
        if (empty($body)) $body = $msg['videoMessage']['caption'] ?? '';
    }
    if (isset($msg['stickerMessage'])) {
        $mediaType = 'sticker';
    }
    if (isset($msg['locationMessage'])) {
        $mediaType = 'location';
        $lat = $msg['locationMessage']['degreesLatitude'] ?? '';
        $lng = $msg['locationMessage']['degreesLongitude'] ?? '';
        if ($lat && $lng) $body = "Location: {$lat},{$lng}";
    }

    return ['body' => $body, 'media_type' => $mediaType, 'media_url' => $mediaUrl];
}
