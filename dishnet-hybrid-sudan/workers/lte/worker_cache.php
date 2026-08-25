<?php
/**
 * LTE Worker: Cache Refresh
 * ═══════════════════════════════════════════════════════════════════════
 * Refreshes dashboard cache every 5 minutes.
 * 
 * Run: php workers/lte/worker_cache.php
 * Cron: (every 5 min) php /path/to/workers/lte/worker_cache.php
 * 
 * At 50K subscribers, this prevents dashboard queries from scanning
 * full tables. All stats are pre-computed and cached.
 */

require_once __DIR__ . '/../../bootstrap.php';

$startTime = microtime(true);
$workerName = 'lte_cache';

try {
    // Initialize services
    $store = SqliteStore::create($dataDir);
    $pdo = $store->getPdo();
    
    $cache = new LteCacheService($pdo);
    $events = new LteEventService($pdo);
    
    // Load config for dry run check
    $config = json_decode(file_get_contents($dataDir . '/kyc_config.json'), true) ?: [];
    
    // Note: Cache refresh is safe (no external calls), but we skip in dry run
    // to allow complete inspection mode without any background activity
    if (!empty($config['dry_run_mode'])) {
        echo "[DRY RUN] Worker {$workerName} skipped - dry_run_mode is enabled\n";
        exit(0);
    }
    
    // Log start
    $events->system('cron_started', ['worker' => $workerName]);
    
    // Refresh all LTE caches (including extended 7 CTO dashboard metrics)
    $results = $cache->refreshAllLteCacheFull();
    
    // Calculate duration
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    // Log completion
    $events->system('cron_completed', [
        'worker'   => $workerName,
        'duration_ms' => $duration,
        'results'  => $results,
    ]);
    
    echo "[" . date('Y-m-d H:i:s') . "] LTE cache refreshed in {$duration}ms\n";
    echo "  - Subscribers: {$results['subscriber_counts']['active']} active\n";
    echo "  - Expiring today: {$results['expiring_soon']['today']}\n";
    echo "  - Usage alerts: {$results['usage_alerts']['critical']} critical\n";
    
} catch (Exception $e) {
    $events->error('Cache refresh failed', ['worker' => $workerName], $e);
    error_log("[LTE Cache] Error: " . $e->getMessage());
    exit(1);
}
