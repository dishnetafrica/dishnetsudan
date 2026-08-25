<?php
// Note: No strict_types - included from master.php
chdir(__DIR__);
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

/**
 * cron_wa_bot.php — WhatsApp Auto-Reply + Maintenance Cron (v4.11.3)
 *
 * Run every 5 minutes via UCRM cron:
 *   1. Auto-close stale conversations (24h no customer message)
 *   2. Reset human_active → new after 24h cooldown
 *   3. Weekly digest (Monday 8AM) — bot vs human handled stats
 */

require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/NotificationService.php';

// ── Prevent overlapping runs ──────────────────────────────────────────────
$lockFile = $dataDir . '/wa_bot_cron.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    echo date('Y-m-d H:i:s') . " [wa_bot_cron] Already running — skipping.\n";
    return;
}
file_put_contents($lockFile, (string)getmypid());
register_shutdown_function(function() use ($lockFile) { @unlink($lockFile); });

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];
$pdo    = $store->getPdo();

if (empty($config['wa_bot_enabled']) && empty($config['wa_auto_reply_enabled'])) {
    echo date('Y-m-d H:i:s') . " [wa_bot_cron] Auto-reply disabled — exiting.\n";
    return;
}

$stats = ['auto_closed' => 0, 'cooldown_reset' => 0, 'digest_sent' => false];

// ══════════════════════════════════════════════════════════════════════
// 1. AUTO-CLOSE: conversations with no customer message in 24h
// ══════════════════════════════════════════════════════════════════════
try {
    $stmt = $pdo->prepare(
        "UPDATE wa_conversations SET status = 'closed', state = 'closed', updated_at = datetime('now')
         WHERE status = 'active'
           AND state NOT IN ('closed')
           AND last_message_at < datetime('now', '-24 hours')
           AND last_message_at IS NOT NULL"
    );
    $stmt->execute();
    $stats['auto_closed'] = $stmt->rowCount();
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . " [wa_bot_cron] auto_close error: " . $e->getMessage() . "\n";
}

// ══════════════════════════════════════════════════════════════════════
// 2. COOLDOWN RESET: human_active conversations where last human reply
//    was >24h ago → reset to 'new' so bot can re-engage
// ══════════════════════════════════════════════════════════════════════
try {
    $stmt = $pdo->prepare(
        "UPDATE wa_conversations SET state = 'new', updated_at = datetime('now')
         WHERE state = 'human_active'
           AND status = 'active'
           AND last_human_reply_at < datetime('now', '-24 hours')"
    );
    $stmt->execute();
    $stats['cooldown_reset'] = $stmt->rowCount();
} catch (\Throwable $e) {
    echo date('Y-m-d H:i:s') . " [wa_bot_cron] cooldown_reset error: " . $e->getMessage() . "\n";
}

// ══════════════════════════════════════════════════════════════════════
// 3. WEEKLY DIGEST: Send on Monday between 08:00-08:05
// ══════════════════════════════════════════════════════════════════════
$isMonday = (int)date('N') === 1;
$hour = (int)date('H');
$min  = (int)date('i');
$digestFile = $dataDir . '/wa_digest_last.txt';
$lastDigest = file_exists($digestFile) ? trim(file_get_contents($digestFile)) : '';
$thisWeek   = date('Y-W');

if ($isMonday && $hour === 8 && $min < 6 && $lastDigest !== $thisWeek) {
    try {
        $notify = new NotificationService($store, $config);

        // Stats for last 7 days
        $stmt = $pdo->query(
            "SELECT
                COUNT(DISTINCT conversation_id) as total_convs,
                SUM(CASE WHEN role = 'bot' THEN 1 ELSE 0 END) as bot_msgs,
                SUM(CASE WHEN role = 'agent' THEN 1 ELSE 0 END) as human_msgs,
                SUM(CASE WHEN role = 'customer' THEN 1 ELSE 0 END) as customer_msgs
             FROM wa_messages
             WHERE sent_at >= datetime('now', '-7 days')"
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Per-channel breakdown
        $stmt = $pdo->query(
            "SELECT c.channel,
                    COUNT(DISTINCT m.conversation_id) as convs,
                    SUM(CASE WHEN m.role='customer' THEN 1 ELSE 0 END) as inbound,
                    SUM(CASE WHEN m.role IN ('bot','agent') THEN 1 ELSE 0 END) as outbound
             FROM wa_messages m
             JOIN wa_conversations c ON c.id = m.conversation_id
             WHERE m.sent_at >= datetime('now', '-7 days')
             GROUP BY c.channel"
        );
        $channels = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Tickets created this week
        $ticketCount = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE created_at >= datetime('now', '-7 days')");
            $ticketCount = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {}

        // Needs human right now
        $stmt = $pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE state = 'needs_human' AND status = 'active'");
        $needsHuman = (int)$stmt->fetchColumn();

        $totalConvs = (int)($row['total_convs'] ?? 0);
        $botMsgs    = (int)($row['bot_msgs'] ?? 0);
        $humanMsgs  = (int)($row['human_msgs'] ?? 0);
        $custMsgs   = (int)($row['customer_msgs'] ?? 0);

        $msg = "📊 *WhatsApp Weekly Digest*\n"
             . "_Week of " . date('j M Y', strtotime('-7 days')) . " – " . date('j M Y') . "_\n\n"
             . "💬 *{$totalConvs}* active conversations\n"
             . "📨 *{$custMsgs}* customer messages\n"
             . "🤖 *{$botMsgs}* bot replies\n"
             . "👤 *{$humanMsgs}* staff replies\n"
             . "🎫 *{$ticketCount}* tickets created\n";

        if ($needsHuman > 0) {
            $msg .= "\n🔴 *{$needsHuman}* conversations need human attention right now!\n";
        }

        if (!empty($channels)) {
            $msg .= "\n*By channel:*\n";
            foreach ($channels as $ch) {
                $chName = ucfirst($ch['channel'] ?? 'unknown');
                $msg .= "  {$chName}: {$ch['convs']} convs, {$ch['inbound']} in / {$ch['outbound']} out\n";
            }
        }

        $msg .= "\n_DishNet WhatsApp System_";

        $notify->sendAdmin($msg, 'wa_weekly_digest', []);
        file_put_contents($digestFile, $thisWeek);
        $stats['digest_sent'] = true;

    } catch (\Throwable $e) {
        echo date('Y-m-d H:i:s') . " [wa_bot_cron] digest error: " . $e->getMessage() . "\n";
    }
}

// ── Log ──────────────────────────────────────────────────────────────────
$logEntry = [
    'ran_at'         => date('Y-m-d H:i:s'),
    'auto_closed'    => $stats['auto_closed'],
    'cooldown_reset' => $stats['cooldown_reset'],
    'digest_sent'    => $stats['digest_sent'],
];

$cronLog = $dataDir . '/wa_bot_cron_log.json';
$log     = [];
if (file_exists($cronLog)) {
    $raw = json_decode(file_get_contents($cronLog), true);
    $log = is_array($raw) ? $raw : [];
}
array_unshift($log, $logEntry);
$log = array_slice($log, 0, 200);
file_put_contents($cronLog, json_encode($log, JSON_PRETTY_PRINT));

echo date('Y-m-d H:i:s') . " [wa_bot_cron] Done. Closed: {$stats['auto_closed']}, Reset: {$stats['cooldown_reset']}, Digest: " . ($stats['digest_sent'] ? 'sent' : 'no') . "\n";
return;
