<?php
/**
 * LTE Worker: Maintenance
 * ═══════════════════════════════════════════════════════════════════════
 * Handles cleanup and maintenance tasks:
 * - Archive old network events (>180 days)
 * - Archive old usage history (>90 days)
 * - Clean completed jobs (>24 hours)
 * - Vacuum database (weekly)
 * - Generate data retention report
 * 
 * Run: php workers/lte/worker_maintenance.php
 * Cron: 0 3 * * * (daily at 3 AM)
 */

require_once __DIR__ . '/../../bootstrap.php';

$startTime = microtime(true);
$workerName = 'lte_maintenance';

try {
    // Initialize services
    $store = SqliteStore::create($dataDir);
    $pdo = $store->getPdo();
    
    $events = new LteEventService($pdo);
    
    // Load config
    $config = json_decode(file_get_contents($dataDir . '/kyc_config.json'), true) ?: [];
    
    // ═══════════════════════════════════════════════════════════════════════
    // DRY RUN MODE CHECK - Skip maintenance in dry run mode
    // ═══════════════════════════════════════════════════════════════════════
    if (!empty($config['dry_run_mode'])) {
        echo "[DRY RUN] Worker {$workerName} skipped - dry_run_mode is enabled\n";
        exit(0);
    }
    
    $retentionDays = [
        'network_events' => (int)($config['lte_event_retention_days'] ?? 180),
        'usage_history'  => (int)($config['lte_usage_retention_days'] ?? 90),
        'completed_jobs' => 1, // Always 24 hours
    ];
    
    $stats = [
        'events_archived' => 0,
        'usage_archived' => 0,
        'jobs_cleaned' => 0,
        'vacuum_run' => false,
    ];
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting maintenance worker\n";
    
    // Log start
    $events->system('cron_started', ['worker' => $workerName]);
    
    // ═══════════════════════════════════════════════════════════════════════
    // 1. ARCHIVE OLD NETWORK EVENTS
    // ═══════════════════════════════════════════════════════════════════════
    
    echo "  Cleaning network events older than {$retentionDays['network_events']} days...\n";
    
    $stmt = $pdo->prepare("
        DELETE FROM lte_network_events
        WHERE created_at < datetime('now', :days || ' days')
    ");
    $stmt->execute([':days' => -$retentionDays['network_events']]);
    $stats['events_archived'] = $stmt->rowCount();
    
    echo "    Deleted {$stats['events_archived']} old events\n";
    
    // ═══════════════════════════════════════════════════════════════════════
    // 2. ARCHIVE OLD USAGE HISTORY
    // ═══════════════════════════════════════════════════════════════════════
    
    echo "  Cleaning usage history older than {$retentionDays['usage_history']} days...\n";
    
    $stmt = $pdo->prepare("
        DELETE FROM lte_usage_history
        WHERE recorded_at < datetime('now', :days || ' days')
    ");
    $stmt->execute([':days' => -$retentionDays['usage_history']]);
    $stats['usage_archived'] = $stmt->rowCount();
    
    echo "    Deleted {$stats['usage_archived']} old usage samples\n";
    
    // ═══════════════════════════════════════════════════════════════════════
    // 3. CLEAN COMPLETED JOBS
    // ═══════════════════════════════════════════════════════════════════════
    
    echo "  Cleaning completed jobs older than 24 hours...\n";
    
    $stmt = $pdo->query("
        DELETE FROM job_queue
        WHERE status = 'completed'
          AND completed_at < datetime('now', '-24 hours')
    ");
    $stats['jobs_cleaned'] = $stmt->rowCount();
    
    echo "    Deleted {$stats['jobs_cleaned']} completed jobs\n";
    
    // ═══════════════════════════════════════════════════════════════════════
    // 4. CLEAN FAILED JOBS OLDER THAN 7 DAYS
    // ═══════════════════════════════════════════════════════════════════════
    
    $stmt = $pdo->query("
        DELETE FROM job_queue
        WHERE status = 'failed'
          AND created_at < datetime('now', '-7 days')
    ");
    $failedCleaned = $stmt->rowCount();
    
    if ($failedCleaned > 0) {
        echo "    Deleted {$failedCleaned} old failed jobs\n";
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 5. VACUUM DATABASE (weekly - on Sundays)
    // ═══════════════════════════════════════════════════════════════════════
    
    $dayOfWeek = (int)date('w'); // 0 = Sunday
    
    if ($dayOfWeek === 0) {
        echo "  Running VACUUM (weekly maintenance)...\n";
        
        // Get DB size before
        $stmt = $pdo->query("SELECT page_count * page_size AS size FROM pragma_page_count(), pragma_page_size()");
        $sizeBefore = $stmt->fetchColumn();
        
        $pdo->exec('VACUUM');
        $stats['vacuum_run'] = true;
        
        // Get DB size after
        $stmt = $pdo->query("SELECT page_count * page_size AS size FROM pragma_page_count(), pragma_page_size()");
        $sizeAfter = $stmt->fetchColumn();
        
        $savedMb = round(($sizeBefore - $sizeAfter) / 1048576, 2);
        echo "    VACUUM complete, saved {$savedMb} MB\n";
        $stats['vacuum_saved_mb'] = $savedMb;
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 6. ANALYZE TABLES (update query planner statistics)
    // ═══════════════════════════════════════════════════════════════════════
    
    echo "  Running ANALYZE...\n";
    $pdo->exec('ANALYZE');
    
    // ═══════════════════════════════════════════════════════════════════════
    // 7. GENERATE DATA RETENTION REPORT
    // ═══════════════════════════════════════════════════════════════════════
    
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM lte_subscribers) AS subscribers,
            (SELECT COUNT(*) FROM lte_subscriptions) AS subscriptions,
            (SELECT COUNT(*) FROM lte_sims) AS sims,
            (SELECT COUNT(*) FROM lte_renewals) AS renewals,
            (SELECT COUNT(*) FROM lte_network_events) AS events,
            (SELECT COUNT(*) FROM lte_usage_history) AS usage_samples,
            (SELECT COUNT(*) FROM job_queue WHERE job_type LIKE 'lte_%') AS jobs
    ");
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['table_counts'] = $counts;
    
    // Get database size
    $dbPath = $dataDir . '/hybrid.db';
    $stats['db_size_mb'] = file_exists($dbPath) ? round(filesize($dbPath) / 1048576, 2) : 0;
    
    echo "\n  Table counts:\n";
    foreach ($counts as $table => $count) {
        echo "    {$table}: {$count}\n";
    }
    echo "  Database size: {$stats['db_size_mb']} MB\n";
    
    // ═══════════════════════════════════════════════════════════════════════
    // 8. CHECK FOR ORPHANED RECORDS
    // ═══════════════════════════════════════════════════════════════════════
    
    // Subscriptions without subscribers
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM lte_subscriptions s
        LEFT JOIN lte_subscribers sub ON sub.id = s.subscriber_id
        WHERE sub.id IS NULL
    ");
    $orphanedSubs = $stmt->fetchColumn();
    
    if ($orphanedSubs > 0) {
        $stats['orphaned_subscriptions'] = $orphanedSubs;
        echo "  ⚠️ Found {$orphanedSubs} orphaned subscriptions\n";
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 9. LOG COMPLETION
    // ═══════════════════════════════════════════════════════════════════════
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    $events->system('cron_completed', [
        'worker' => $workerName,
        'duration_ms' => $duration,
        'stats' => $stats,
    ]);
    
    // Save maintenance report
    $report = [
        'run_at' => date('Y-m-d H:i:s'),
        'duration_ms' => $duration,
        'stats' => $stats,
        'retention_policy' => $retentionDays,
    ];
    file_put_contents($dataDir . '/lte_maintenance_report.json', json_encode($report, JSON_PRETTY_PRINT));
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Maintenance completed in {$duration}ms\n";
    
} catch (Exception $e) {
    $events->error('Maintenance failed', ['worker' => $workerName], $e);
    error_log("[LTE Maintenance] Error: " . $e->getMessage());
    exit(1);
}
