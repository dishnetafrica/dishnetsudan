<?php
// Note: No strict_types - included from master.php

/**
 * cron_evo_sync.php — Evolution API Message Sync
 *
 * Two modes:
 *   1. FULL IMPORT (first run): Fetches all chats and all messages from Evolution API
 *   2. INCREMENTAL (subsequent runs): Only fetches messages newer than last sync
 *
 * Run manually for first import:
 *   php cron_evo_sync.php --full
 *
 * Or via master.php cron (every 30 min) for incremental.
 *
 * Requires Evolution API config in plugin settings:
 *   evo_api_url, evo_api_key, evo_instance_name
 */

chdir(__DIR__);
date_default_timezone_set('Africa/Juba');

// PHP 7.4 polyfills
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/EvolutionApiClient.php';
require_once __DIR__ . '/lib/ConversationService.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);  // Use UCRM's persistent data directory
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];

// Check config
$evoUrl      = trim($config['evo_api_url'] ?? '');
$evoKey      = trim($config['evo_api_key'] ?? '');
$evoInstance = trim($config['evo_instance_name'] ?? '');

if (empty($evoUrl) || empty($evoKey) || empty($evoInstance)) {
    // Silent skip — not configured
    return;
}

$fullMode = in_array('--full', $argv ?? []);

// ── Check if UI requested a full import ──────────────────────────────────────
$preSync = $store->load('evo_sync_state.json') ?? [];
if (!empty($preSync['run_full_import'])) {
    $fullMode = true;
    $preSync['run_full_import'] = false;
    $store->save('evo_sync_state.json', $preSync);
}

// ── Init services ────────────────────────────────────────────────────────────
$evo     = new EvolutionApiClient($evoUrl, $evoKey, $evoInstance, 60);
$convSvc = new ConversationService($dataDir, $store->getPdo());
$pdo     = $store->getPdo();

// ── Load sync state ──────────────────────────────────────────────────────────
$syncState   = $store->load('evo_sync_state.json') ?? [];
$lastSyncAt  = $syncState['last_sync_at'] ?? null;
$syncedChats = $syncState['synced_chats'] ?? [];

evoLog("=== Evolution API sync started (" . ($fullMode ? 'FULL' : 'incremental') . ") ===");

// ── Check connection ─────────────────────────────────────────────────────────
$conn = $evo->connectionState();
$status = $conn['state'] ?? $conn['instance']['state'] ?? 'unknown';
if ($status !== 'open') {
    evoLog("WARNING: Instance '{$evoInstance}' status is '{$status}' — sync may fail");
}

// ── Fetch chats ──────────────────────────────────────────────────────────────
evoLog("Fetching chat list...");
$chats = $evo->findChats();
if (isset($chats['error'])) {
    evoLog("ERROR fetching chats: " . ($chats['error'] ?? 'unknown'));
    return;
}

// Helper to extract JID from a chat object (used to query Evolution API)
$getJid = function($chat) {
    return $chat['remoteJid'] ?? $chat['jid'] ?? $chat['chatId']
        ?? (is_string($chat['id'] ?? null) ? $chat['id'] : null)
        ?? ($chat['lastMessage']['key']['remoteJid'] ?? null)
        ?? '';
};

// Helper to extract real phone from a chat (senderPn = real phone, remoteJid may be @lid)
$getRealPhone = function($chat) {
    // Check lastMessage.key.senderPn first (has the real phone in @s.whatsapp.net format)
    $senderPn = $chat['lastMessage']['key']['senderPn'] ?? '';
    if (!empty($senderPn)) {
        return preg_replace('/[^0-9]/', '', explode('@', $senderPn)[0]);
    }
    // Fall back to JID if it's not @lid format
    $jid = $chat['remoteJid'] ?? $chat['jid'] ?? $chat['chatId']
        ?? (is_string($chat['id'] ?? null) ? $chat['id'] : null)
        ?? ($chat['lastMessage']['key']['remoteJid'] ?? null)
        ?? '';
    if (!empty($jid) && strpos($jid, '@lid') === false) {
        return preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);
    }
    // Last resort: use LID number (not ideal but at least we store something)
    return preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);
};

// Filter to individual chats only (skip groups and status broadcasts)
$individualChats = array_filter($chats, function ($chat) use ($getJid) {
    $jid = $getJid($chat);
    if (empty($jid)) return false;
    if (strpos($jid, '@g.us') !== false) return false;
    if (strpos($jid, 'status@') !== false) return false;
    if (strpos($jid, 'broadcast') !== false) return false;
    return true;
});

$chatCount = count($individualChats);
evoLog("Found {$chatCount} individual chats (skipped groups)");

$imported     = 0;
$skipped      = 0;
$totalMsgs    = 0;
$channel      = $config['evo_channel_name'] ?? 'marketing';

// ── THROTTLE: Cap chats processed per cycle to prevent runaway execution ─────
// In incremental mode, only process 20 chats max. Full mode: 200 (manual only).
$_evoMaxChats = $fullMode ? 200 : (int)($config['evo_sync_max_chats'] ?? 20);
$_evoProcessed = 0;

foreach ($individualChats as $chat) {
    // ── Throttle: stop if we've hit the per-cycle chat limit ─────────────
    if ($_evoProcessed >= $_evoMaxChats) {
        evoLog("THROTTLE: Reached {$_evoMaxChats} chats limit — deferring rest to next cycle");
        break;
    }

    $jid = $getJid($chat);
    if (empty($jid)) continue;

    $phone = $getRealPhone($chat);
    $pushName = $chat['lastMessage']['pushName'] ?? null;

    // Incremental: skip chats already fully synced unless full mode
    if (!$fullMode && isset($syncedChats[$jid]) && !empty($syncedChats[$jid]['complete'])) {
        $skipped++;
        continue;
    }

    evoLog("Syncing {$phone} ({$jid})...");

    try {
        // Fetch messages — paginated
        $page     = 1;
        $pageSize = 500;
        $chatMsgs = 0;

        do {
            $result = $evo->findMessages($jid, $pageSize, $page);

            // Handle different Evolution API response formats
            $messages = null;
            $totalPages = 999; // default: keep going
            if (isset($result['messages']['records']) && is_array($result['messages']['records'])) {
                $messages   = $result['messages']['records'];
                $totalPages = (int)($result['messages']['pages'] ?? 999);
            } elseif (isset($result['messages']) && is_array($result['messages'])) {
                $messages = $result['messages'];
            } elseif (isset($result[0]['key'])) {
                $messages = $result;
            }
            if (!is_array($messages) || empty($messages)) break;

            foreach ($messages as $msg) {
                $convId = $convSvc->importEvoMessage($msg, $channel);
                if ($convId !== null) {
                    $chatMsgs++;
                    $totalMsgs++;
                }
            }

            $page++;

            // Rate limit: be gentle with the API
            usleep(200000); // 200ms between pages

        } while ($page <= $totalPages && $page < 200);

        $syncedChats[$jid] = [
            'phone'    => $phone,
            'messages' => $chatMsgs,
            'synced_at' => date('Y-m-d H:i:s'),
            'complete'  => true,
        ];

        $imported++;

        if ($chatMsgs > 0) {
            evoLog("  → {$phone}: {$chatMsgs} messages imported");
        }

        $_evoProcessed++;

        // Rate limit between chats
        usleep(300000); // 300ms

    } catch (Throwable $e) {
        evoLog("  → ERROR for {$phone}: " . $e->getMessage());
        $syncedChats[$jid] = [
            'phone'    => $phone,
            'error'    => $e->getMessage(),
            'synced_at' => date('Y-m-d H:i:s'),
            'complete'  => false,
        ];
        $_evoProcessed++;
    }
}

// ── Save sync state ──────────────────────────────────────────────────────────
$store->save('evo_sync_state.json', [
    'last_sync_at'  => date('Y-m-d H:i:s'),
    'synced_chats'  => $syncedChats,
    'total_imported' => ($syncState['total_imported'] ?? 0) + $totalMsgs,
    'last_run_mode' => $fullMode ? 'full' : 'incremental',
]);

evoLog("=== Sync complete: {$imported} chats processed, {$totalMsgs} messages imported, {$skipped} skipped ===");

// ── Auto-extract training pairs from new conversations ───────────────────────
if ($totalMsgs > 0) {
    evoLog("Extracting training pairs from imported conversations...");
    $pairsTotal = 0;

    // Get conversations that were touched in this sync
    $stmt = $pdo->prepare(
        "SELECT id FROM wa_conversations WHERE channel = ? AND updated_at >= datetime('now', '-1 hour')"
    );
    $stmt->execute([$channel]);
    $convIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($convIds as $cid) {
        $pairs = $convSvc->extractTrainingPairs((int)$cid);
        $pairsTotal += $pairs;
    }

    evoLog("Extracted {$pairsTotal} training Q&A pairs");
}

echo "[evo_sync] Done: {$imported} chats, {$totalMsgs} msgs, {$skipped} skipped\n";

// ── Helper ───────────────────────────────────────────────────────────────────
function evoLog(string $msg): void
{
    global $dataDir;
    $logFile = $dataDir . '/evo_sync.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    // Trim to 500 lines
    if (file_exists($logFile)) {
        $lines = file($logFile);
        if ($lines && count($lines) > 500) {
            file_put_contents($logFile, implode('', array_slice($lines, -400)));
        }
    }
}
