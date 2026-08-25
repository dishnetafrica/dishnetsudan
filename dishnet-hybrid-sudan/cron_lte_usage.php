#!/usr/bin/env php
<?php
/**
 * DishNet LTE Usage Sync Cron
 * ═══════════════════════════════════════════════════════════════════════
 * Runs every 5 minutes via crontab:
 *   (every 5 min) php /path/to/cron_lte_usage.php >> /tmp/lte_usage.log 2>&1
 *
 * Tasks performed each run:
 *   1. Fetch data usage from Magma Prometheus for all data-cap subscribers
 *   2. Update bytes_used in subscriptions
 *   3. Check usage thresholds (50%, 80%, 100%)
 *   4. Send WhatsApp warnings at thresholds
 *   5. Suspend subscribers who have exhausted their data cap
 *
 * This is separate from cron_lte.php which handles expiry-based suspension.
 * This cron handles DATA USAGE based suspension (type=0 plans only).
 */

date_default_timezone_set('Africa/Juba');
chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/MagmaApiClient.php';
require_once __DIR__ . '/lib/LteSqliteService.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json');

// Initialize Magma client
$magma = new MagmaApiClient([
    'magma_host'             => $config['magma_host'] ?? $config['magma_orchestrator_url'] ?? '',
    'magma_network_id'       => $config['magma_network_id'] ?? '',
    'magma_client_cert_path' => $config['magma_client_cert_path'] ?? $config['magma_cert_path'] ?? '',
    'magma_client_key_path'  => $config['magma_client_key_path'] ?? $config['magma_key_path'] ?? '',
    'magma_ca_cert_path'     => $config['magma_ca_cert_path'] ?? '',
]);

$lte    = new LteSqliteService($store->getPdo(), $magma);
$notify = new NotificationService($store, $config);

// ── Single-instance lock ────────────────────────────────────────────
$lockFile = $dataDir . '/cron_lte_usage.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    return; // Another instance is running
}

function clog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

clog('=== LTE Usage Sync Start ===');
set_time_limit(300); // 5-minute safety guard against Magma API hangs

// Check if Magma is configured
if (!$magma->isConfigured()) {
    clog('ERROR: Magma not configured. Skipping usage sync.');
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    return;
}

// ══════════════════════════════════════════════════════════════════════
// STEP 1 — Fetch usage from Prometheus and update subscriptions
// ══════════════════════════════════════════════════════════════════════

$syncResult = $lte->syncUsageFromPrometheus();

if (!empty($syncResult['skipped'])) {
    clog('Sync skipped: ' . ($syncResult['reason'] ?? 'unknown'));
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    return;
}

clog("Synced {$syncResult['synced']} data-cap subscriptions");

if (!empty($syncResult['errors'])) {
    foreach ($syncResult['errors'] as $err) {
        clog("  ERROR: {$err}");
    }
}

// ══════════════════════════════════════════════════════════════════════
// STEP 2 — Send WhatsApp warnings for threshold crossings
// ══════════════════════════════════════════════════════════════════════

$whatsappEnabled = !empty($config['whatsapp_webhook_url']);
$warningSent = 0;

foreach ($syncResult['warnings'] ?? [] as $warn) {
    $sub = $warn['sub'];
    $type = $warn['type'];
    $percent = $warn['percent'];
    $phone = $sub['_phone'] ?? '';
    $name = $sub['_name'] ?? 'Customer';
    $pkgName = $sub['package_name'] ?? '';
    $bytesUsed = LteSqliteService::formatBytes((int)($sub['bytes_used'] ?? 0));
    $bytesAllowed = LteSqliteService::formatBytes((int)($sub['bytes_allowed'] ?? 0));
    $bytesRemaining = LteSqliteService::formatBytes((int)($sub['bytes_remaining'] ?? 0));
    
    clog("  WARNING {$type}: {$name} ({$phone}) at {$percent}%");
    
    if ($whatsappEnabled && !empty($phone)) {
        $emoji = $type === '80%' ? '🔴' : '🟡';
        $urgency = $type === '80%' ? 'running low' : 'over halfway used';
        
        $message = "{$emoji} *Data Usage Alert*\n\n"
            . "Hello {$name},\n\n"
            . "Your *{$pkgName}* data is {$urgency}!\n\n"
            . "📊 *Usage: {$percent}%*\n"
            . "• Used: {$bytesUsed}\n"
            . "• Remaining: {$bytesRemaining}\n"
            . "• Total: {$bytesAllowed}\n\n";
        
        if ($type === '80%') {
            $message .= "⚠️ Your service will be suspended when data is exhausted.\n\n"
                . "Contact your agent to top up!\n\n";
        }
        
        $message .= "_DishNet Africa_";
        
        $notify->sendRaw($phone, $message, 'lte_usage_warning');
        $warningSent++;
    }
}

clog("Sent {$warningSent} usage warnings");

// ══════════════════════════════════════════════════════════════════════
// STEP 3 — Suspend subscribers who exhausted their data
// ══════════════════════════════════════════════════════════════════════

// SAFETY GUARD: Prevent mass suspension (Prometheus failure, bad data, bug)
// If more than 20 subscribers would be suspended in one run, STOP and alert admin
// Also check percentage threshold (5% of active subscribers)
$exhaustedList = $syncResult['exhausted'] ?? [];
$exhaustedCount = count($exhaustedList);
$maxSuspendPerRun = (int)($config['lte_max_suspend_per_run'] ?? 20);
$maxSuspendPercent = (float)($config['lte_max_suspend_percent'] ?? 5.0);

// Calculate percentage threshold
$totalActive = count($syncResult['processed'] ?? []);
$percentThreshold = ($totalActive > 0) ? ceil($totalActive * ($maxSuspendPercent / 100)) : $maxSuspendPerRun;
$effectiveLimit = max($maxSuspendPerRun, $percentThreshold);

if ($exhaustedCount > $effectiveLimit) {
    clog("⚠️ SAFETY GUARD TRIGGERED: {$exhaustedCount} subscribers marked for suspension");
    clog("   Absolute limit: {$maxSuspendPerRun}, Percent limit ({$maxSuspendPercent}%): {$percentThreshold}");
    clog("   Effective limit: {$effectiveLimit}");
    clog("   SUSPENSIONS HALTED — Manual review required");
    
    // Log the safety trigger
    $safetyLog = $store->load('lte_safety_triggers.json') ?? [];
    $safetyLog[] = [
        'triggered_at'    => date('Y-m-d H:i:s'),
        'type'            => 'mass_suspend_blocked',
        'count_attempted' => $exhaustedCount,
        'absolute_limit'  => $maxSuspendPerRun,
        'percent_limit'   => $percentThreshold,
        'effective_limit' => $effectiveLimit,
        'total_active'    => $totalActive,
        'sample_imsis'    => array_slice(array_column($exhaustedList, '_imsi'), 0, 5),
    ];
    $store->save('lte_safety_triggers.json', $safetyLog);
    
    // Alert admin via WhatsApp
    if ($whatsappEnabled) {
        $notify->sendAdmin(
            "⚠️ *LTE SAFETY GUARD*\n\n" .
            "Mass suspension blocked!\n" .
            "• Attempted: {$exhaustedCount} subscribers\n" .
            "• Threshold: {$maxSuspendPerRun}\n\n" .
            "Possible causes:\n" .
            "• Prometheus failure\n" .
            "• Bad usage data\n" .
            "• System bug\n\n" .
            "Please investigate immediately.",
            'lte_safety_alert'
        );
    }
    
    // Skip suspension, continue to cleanup
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    return;
}

$suspendCount = 0;

foreach ($syncResult['exhausted'] ?? [] as $sub) {
    $subscriberId = (int)($sub['subscriber_id'] ?? 0);
    $name = $sub['_name'] ?? 'Customer';
    $phone = $sub['_phone'] ?? '';
    $pkgName = $sub['package_name'] ?? '';
    $imsi = $sub['_imsi'] ?? '';
    
    clog("  SUSPEND (data exhausted): #{$subscriberId} {$name}");
    
    // Suspend in Magma and locally
    $ok = $lte->suspendForDataExhausted($subscriberId, $sub);
    
    if ($ok) {
        $suspendCount++;
        
        // Log to auto-suspend log
        $susLog = $store->load('lte_auto_suspend_log.json') ?? [];
        $susLog[] = [
            'subscriber_id' => $subscriberId,
            'name'          => $name,
            'phone'         => $phone,
            'imsi'          => $imsi,
            'reason'        => 'data_exhausted',
            'package_name'  => $pkgName,
            'bytes_used'    => $sub['bytes_used'] ?? 0,
            'bytes_allowed' => $sub['bytes_allowed'] ?? 0,
            'suspended_at'  => date('Y-m-d H:i:s'),
            'magma_synced'  => !empty($imsi),
        ];
        $store->save('lte_auto_suspend_log.json', $susLog);
        
        // Send WhatsApp notification
        if ($whatsappEnabled && !empty($phone)) {
            $bytesUsed = LteSqliteService::formatBytes((int)($sub['bytes_used'] ?? 0));
            
            $message = "🚫 *Data Exhausted*\n\n"
                . "Hello {$name},\n\n"
                . "Your *{$pkgName}* data has been fully used ({$bytesUsed}).\n\n"
                . "Your internet service is now *suspended*.\n\n"
                . "To restore service, please purchase a new data package or top-up.\n\n"
                . "Contact your agent or call: +211 927 797 217\n\n"
                . "_DishNet Africa_";
            
            $notify->sendRaw($phone, $message, 'lte_data_exhausted');
        }
    } else {
        clog("    ERROR: Failed to suspend #{$subscriberId}");
    }
}

clog("Suspended {$suspendCount} subscribers for data exhaustion");

// ══════════════════════════════════════════════════════════════════════
// STEP 4 — Save sync summary
// ══════════════════════════════════════════════════════════════════════

$summary = [
    'synced_at'       => date('Y-m-d H:i:s'),
    'subscriptions'   => $syncResult['synced'],
    'warnings_sent'   => $warningSent,
    'suspended'       => $suspendCount,
    'errors'          => count($syncResult['errors'] ?? []),
];

// Keep last 100 sync summaries
$syncLog = $store->load('lte_usage_sync_log.json') ?? [];
$syncLog[] = $summary;
if (count($syncLog) > 100) {
    $syncLog = array_slice($syncLog, -100);
}
$store->save('lte_usage_sync_log.json', $syncLog);

// ══════════════════════════════════════════════════════════════════════
// DONE
// ══════════════════════════════════════════════════════════════════════

flock($lockFp, LOCK_UN);
fclose($lockFp);

clog("=== LTE Usage Sync Done | Synced:{$syncResult['synced']} Warnings:{$warningSent} Suspended:{$suspendCount} ===");
