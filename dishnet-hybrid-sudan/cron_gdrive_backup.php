<?php
// Note: No strict_types - included from master.php

/**
 * cron_gdrive_backup.php — DishNet Google Drive Backup Cron
 *
 * Runs via cron/master.php every 6 hours.
 * Internally checks schedule config (daily/twice_daily/weekly) and
 * last_backup timestamp to decide whether to actually run.
 *
 * Schedule options:
 *   daily       → once per day at ~06:00 Juba time
 *   twice_daily → twice per day at ~06:00 and ~18:00 Juba time
 *   weekly      → once per week on Sunday at ~06:00 Juba time
 */

chdir(__DIR__);
date_default_timezone_set('Africa/Juba');

require_once __DIR__ . '/lib/GoogleDriveBackup.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);  // Use UCRM's persistent data directory
if (!is_dir($dataDir)) return;

$gdrive = new GoogleDriveBackup($dataDir);

// Only run if authorized and enabled
if (!$gdrive->isAuthorized()) return;

$config = $gdrive->getConfig();
if (empty($config['enabled'])) return;

$schedule   = $config['schedule'] ?? 'daily';
$lastBackup = $config['last_backup']['time'] ?? null;
$lastTs     = $lastBackup ? strtotime($lastBackup) : 0;
$now        = time();
$hour       = (int)date('G');
$dayOfWeek  = (int)date('w'); // 0=Sunday

// Determine if we should run based on schedule
$shouldRun = false;
$forceRun  = !empty($GLOBALS['_FORCE_CRON_RUN']); // set by force_cron_job API

if ($forceRun) {
    $shouldRun = true;
} else {
switch ($schedule) {
    case 'twice_daily':
        // Run if not already ran within 10 hours
        if (($now - $lastTs) > 36000) {
            $shouldRun = true;
        }
        break;

    case 'weekly':
        // Run on Sunday — but not if already ran within 6 days
        if ($dayOfWeek === 0 && ($now - $lastTs) > 518400) {
            $shouldRun = true;
        }
        break;

    case 'daily':
    default:
        // Run between 02:00–05:59 Juba time if not already ran within 20 hours.
        // master.php no longer gates by run_hour so this script owns the time window.
        // 4-hour window (not just 1 hour) means LTE/heavy jobs eating budget one cycle
        // won't block backup for the whole day — next 5-min master.php call will retry.
        if (($now - $lastTs) > 72000 && $hour >= 2 && $hour <= 5) {
            $shouldRun = true;
        }
        break;
}
} // end if !forceRun

if (!$shouldRun) return;

// Run the backup
$result = $gdrive->runBackup();

// ── WhatsApp notification ────────────────────────────────────────────────
try {
    require_once __DIR__ . '/lib/StoreInterface.php';
    require_once __DIR__ . '/lib/JsonStore.php';
    require_once __DIR__ . '/lib/SqliteStore.php';
    require_once __DIR__ . '/lib/NotificationService.php';
    $bkStore  = SqliteStore::create($dataDir);
    $bkConfig = $bkStore->load('kyc_config.json') ?? [];
    $bkNotify = new NotificationService($bkStore, $bkConfig);

    if ($result['ok']) {
        $bkMsg = "Google Drive Backup OK\n"
               . "CODE: " . ($result['file'] ?? '?') . " (" . ($result['code_size_kb'] ?? '?') . " KB)\n"
               . "DATA: " . ($result['data_file'] ?? '?') . " (" . ($result['data_size_kb'] ?? '?') . " KB)\n"
               . "Total: " . ($result['size_kb'] ?? '?') . " KB in " . ($result['duration'] ?? '?') . "s\n"
               . date('d M Y H:i') . "\n"
               . "CODE = upload to UCRM | DATA = SSH restore";
        $bkNotify->sendAdmin($bkMsg, 'gdrive_backup_ok');
    } else {
        $bkMsg = "❌ *Google Drive Backup FAILED*\n"
               . "Error: " . ($result['error'] ?? 'unknown') . "\n"
               . "🕐 " . date('d M Y H:i') . "\n"
               . "_Check plugin settings → Backup tab_";
        $bkNotify->sendAdmin($bkMsg, 'gdrive_backup_failed');
    }
} catch (\Throwable $waErr) {
    // Non-fatal — backup result is more important than notification
    echo "[gdrive] WA notification failed: " . $waErr->getMessage() . "\n";
}

if ($result['ok']) {
    echo "[gdrive] Backup OK: {$result['file']} ({$result['size_kb']} KB) in {$result['duration']}s\n";
} else {
    echo "[gdrive] Backup FAILED: " . ($result['error'] ?? 'unknown') . "\n";
}
