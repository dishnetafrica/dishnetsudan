<?php
declare(strict_types=1);

/**
 * cron_starlink_block_retry.php — DishNet Hybrid v4.21.0
 *
 * Retries failed Starlink suspend/restore operations.
 *
 * Run interval: every 10 minutes (registered in cron/master.php).
 *
 * Picks up rows from sl_suspension_state where:
 *   - state IN ('suspending', 'partial_suspend_failed', 'restoring')
 *   - last_attempt_at < now - 5 minutes
 *   - attempt_count < 5
 *
 * After 5 failed attempts, sets state to error_manual_required and sends a
 * WhatsApp alert to the admin number (whatsapp_admin_phone config key).
 *
 * Per Hybrid cron convention (SAFETY.md RULE 11): use return; not exit() so
 * downstream cron jobs in master.php still run even if this one finishes.
 */

// ── Ensure we're in cron context ──────────────────────────────────────────
$pluginDir = __DIR__;
require_once $pluginDir . '/lib/SqliteStore.php';
require_once $pluginDir . '/lib/NotificationService.php';
require_once $pluginDir . '/lib/StarlinkBlockService.php';

// ── Load config (same shape as main.php / cron) ───────────────────────────
$dataDir = $pluginDir . '/data';
if (!is_dir($dataDir)) {
    error_log('[cron_starlink_block_retry] data dir missing — skip');
    return;
}

$configFile = $dataDir . '/config.json';
$config = file_exists($configFile)
    ? (json_decode((string)file_get_contents($configFile), true) ?? [])
    : [];

try {
    $store = SqliteStore::create($dataDir);
} catch (\Throwable $e) {
    error_log('[cron_starlink_block_retry] store init failed: ' . $e->getMessage());
    return;
}

$pdo = $store->getPdo();

// Notification service for admin alerts on retry-exhaustion
try {
    $notify = new NotificationService($store, $config);
} catch (\Throwable $e) {
    $notify = null;
}

// ── Run the retry loop ─────────────────────────────────────────────────────
try {
    $svc = new StarlinkBlockService($pdo, $store, $config, $dataDir, $notify);

    // Step 1: Retry queue — finish in-flight suspends/restores that didn't
    // complete on the original webhook tick (network blip, gRPC timeout, etc).
    $retryResult = $svc->processRetryQueue();
    if (($retryResult['retried'] ?? 0) > 0 || ($retryResult['abandoned'] ?? 0) > 0) {
        error_log(sprintf(
            '[cron_starlink_block_retry] retry: queue=%d retried=%d abandoned=%d',
            $retryResult['queue_size'] ?? 0,
            $retryResult['retried'] ?? 0,
            $retryResult['abandoned'] ?? 0
        ));
    }

    // Step 2: Extension queue (v4.21.4+) — for pause-only mode rows in
    // SUSPENDED state, re-pause any new MAC that joined since the initial
    // block. Closes the leak inherent to pause-only blocking.
    // v4.21.5+ also detects bypass: MACs we paused that are now unpaused
    // (someone manually unpaused via Starlink mobile app). Re-pauses them
    // and alerts admin via WA after threshold (3+ bypass events).
    $extResult = $svc->processExtensionQueue();
    if (($extResult['newly_paused'] ?? 0) > 0
        || ($extResult['bypass_repaused'] ?? 0) > 0
        || ($extResult['pause_failures'] ?? 0) > 0) {
        error_log(sprintf(
            '[cron_starlink_block_retry] extend: queue=%d checked=%d newly_paused=%d bypass_repaused=%d alerts_sent=%d failures=%d',
            $extResult['queue_size'] ?? 0,
            $extResult['checked'] ?? 0,
            $extResult['newly_paused'] ?? 0,
            $extResult['bypass_repaused'] ?? 0,
            $extResult['bypass_alerts_sent'] ?? 0,
            $extResult['pause_failures'] ?? 0
        ));
    }
} catch (\Throwable $e) {
    error_log('[cron_starlink_block_retry] uncaught: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

return;
