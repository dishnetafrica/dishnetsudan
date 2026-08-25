<?php
/**
 * LTE Worker: Health Check
 * ═══════════════════════════════════════════════════════════════════════
 * Monitors system health and alerts admins on issues.
 * 
 * Checks:
 * - Magma API connectivity and latency
 * - Prometheus query performance
 * - Job queue backlog
 * - Worker last-run times
 * - Database connection
 * 
 * Run: php workers/lte/worker_health.php
 * Cron: 0 * * * * (every hour)
 */

require_once __DIR__ . '/../../bootstrap.php';

$startTime = microtime(true);
$workerName = 'lte_health';

try {
    // Initialize services
    $store = SqliteStore::create($dataDir);
    $pdo = $store->getPdo();
    
    $events = new LteEventService($pdo);
    $cache = new LteCacheService($pdo);
    
    // Load config
    $config = json_decode(file_get_contents($dataDir . '/kyc_config.json'), true) ?: [];
    
    // ═══════════════════════════════════════════════════════════════════════
    // DRY RUN MODE CHECK - Skip health checks in dry run mode
    // ═══════════════════════════════════════════════════════════════════════
    if (!empty($config['dry_run_mode'])) {
        echo "[DRY RUN] Worker {$workerName} skipped - dry_run_mode is enabled\n";
        exit(0);
    }
    
    $health = [
        'checked_at' => date('Y-m-d H:i:s'),
        'issues' => [],
        'warnings' => [],
    ];
    
    // ═══════════════════════════════════════════════════════════════════════
    // 1. DATABASE HEALTH
    // ═══════════════════════════════════════════════════════════════════════
    
    try {
        $dbStart = microtime(true);
        $stmt = $pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE status = 'active'");
        $activeCount = $stmt->fetchColumn();
        $dbLatency = round((microtime(true) - $dbStart) * 1000, 2);
        
        $health['database'] = [
            'status' => 'ok',
            'latency_ms' => $dbLatency,
            'active_subscribers' => (int)$activeCount,
        ];
        
        if ($dbLatency > 1000) {
            $health['warnings'][] = "Database latency high: {$dbLatency}ms";
        }
    } catch (Exception $e) {
        $health['database'] = ['status' => 'error', 'error' => $e->getMessage()];
        $health['issues'][] = 'Database connection failed';
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 2. MAGMA API HEALTH
    // ═══════════════════════════════════════════════════════════════════════
    
    $magmaConfigured = !empty($config['magma_host']) && !empty($config['magma_client_cert_path']);
    
    if ($magmaConfigured) {
        try {
            $magma = new MagmaApiClient(
                $config['magma_host'],
                $config['magma_network_id'] ?? 'bluecard',
                $config['magma_client_cert_path'] ?? '',
                $config['magma_client_key_path'] ?? ''
            );
            
            $magmaStart = microtime(true);
            $networkInfo = $magma->getNetworkInfo();
            $magmaLatency = round((microtime(true) - $magmaStart) * 1000, 2);
            
            $health['magma'] = [
                'status' => $networkInfo ? 'ok' : 'error',
                'latency_ms' => $magmaLatency,
                'network_id' => $config['magma_network_id'] ?? '',
            ];
            
            if (!$networkInfo) {
                $health['issues'][] = 'Magma API unreachable';
            } elseif ($magmaLatency > 2000) {
                $health['warnings'][] = "Magma latency high: {$magmaLatency}ms";
            }
            
            // Test Prometheus
            $promStart = microtime(true);
            $promResult = $magma->getUsageFromPrometheus('000000000000000'); // dummy
            $promLatency = round((microtime(true) - $promStart) * 1000, 2);
            
            $health['prometheus'] = [
                'status' => $promLatency < 5000 ? 'ok' : 'degraded',
                'latency_ms' => $promLatency,
            ];
            
            if ($promLatency > 3000) {
                $health['warnings'][] = "Prometheus latency high: {$promLatency}ms";
            }
            
        } catch (Exception $e) {
            $health['magma'] = ['status' => 'error', 'error' => $e->getMessage()];
            $health['issues'][] = 'Magma connection failed: ' . $e->getMessage();
        }
    } else {
        $health['magma'] = ['status' => 'not_configured'];
        $health['prometheus'] = ['status' => 'not_configured'];
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 3. JOB QUEUE HEALTH
    // ═══════════════════════════════════════════════════════════════════════
    
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) FILTER (WHERE status = 'pending') AS pending,
                COUNT(*) FILTER (WHERE status = 'failed' AND created_at >= datetime('now', '-24 hours')) AS failed_24h,
                MIN(CASE WHEN status = 'pending' THEN created_at END) AS oldest_pending
            FROM job_queue
            WHERE job_type LIKE 'lte_%'
        ");
        $queueStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $backlog = (int)($queueStats['pending'] ?? 0);
        $failed = (int)($queueStats['failed_24h'] ?? 0);
        $oldestPending = $queueStats['oldest_pending'];
        
        $backlogAgeMin = 0;
        if ($oldestPending) {
            $backlogAgeMin = round((time() - strtotime($oldestPending)) / 60, 1);
        }
        
        $health['queue'] = [
            'status' => $backlog < 100 && $failed < 20 ? 'ok' : ($backlog < 500 ? 'warning' : 'critical'),
            'pending' => $backlog,
            'failed_24h' => $failed,
            'backlog_age_min' => $backlogAgeMin,
        ];
        
        if ($backlog > 100) {
            $health['warnings'][] = "Job queue backlog: {$backlog} pending";
        }
        if ($backlog > 500) {
            $health['issues'][] = "Job queue critical: {$backlog} pending";
        }
        if ($failed > 20) {
            $health['warnings'][] = "High failure rate: {$failed} failed jobs in 24h";
        }
        if ($backlogAgeMin > 30) {
            $health['warnings'][] = "Oldest pending job: {$backlogAgeMin} minutes old";
        }
        
    } catch (Exception $e) {
        $health['queue'] = ['status' => 'error', 'error' => $e->getMessage()];
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 4. WORKER HEALTH (check last run times from events)
    // ═══════════════════════════════════════════════════════════════════════
    
    try {
        $stmt = $pdo->query("
            SELECT 
                json_extract(details, '\$.worker') AS worker,
                MAX(created_at) AS last_run
            FROM lte_network_events
            WHERE event_type = 'cron_completed'
              AND created_at >= datetime('now', '-2 hours')
            GROUP BY json_extract(details, '\$.worker')
        ");
        $workerRuns = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $expectedWorkers = ['lte_cache', 'lte_usage_sync', 'lte_expiry', 'lte_jobs'];
        $staleWorkers = [];
        
        foreach ($expectedWorkers as $w) {
            $lastRun = $workerRuns[$w] ?? null;
            if (!$lastRun) {
                $staleWorkers[] = $w;
            } elseif ((time() - strtotime($lastRun)) > 900) { // >15 min
                $staleWorkers[] = $w;
            }
        }
        
        $health['workers'] = [
            'status' => empty($staleWorkers) ? 'ok' : 'warning',
            'last_runs' => $workerRuns,
            'stale' => $staleWorkers,
        ];
        
        if (!empty($staleWorkers)) {
            $health['warnings'][] = 'Stale workers: ' . implode(', ', $staleWorkers);
        }
        
    } catch (Exception $e) {
        $health['workers'] = ['status' => 'unknown', 'error' => $e->getMessage()];
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 5. SUBSCRIPTION HEALTH
    // ═══════════════════════════════════════════════════════════════════════
    
    try {
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) AS total_active,
                SUM(CASE WHEN expires_at <= datetime('now') THEN 1 ELSE 0 END) AS expired_not_suspended,
                SUM(CASE WHEN usage_percent >= 95 THEN 1 ELSE 0 END) AS critical_usage
            FROM lte_subscriptions
            WHERE status = 'active'
        ");
        $subStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $expiredNotSuspended = (int)($subStats['expired_not_suspended'] ?? 0);
        $criticalUsage = (int)($subStats['critical_usage'] ?? 0);
        
        $health['subscriptions'] = [
            'total_active' => (int)($subStats['total_active'] ?? 0),
            'expired_not_suspended' => $expiredNotSuspended,
            'critical_usage' => $criticalUsage,
        ];
        
        if ($expiredNotSuspended > 50) {
            $health['warnings'][] = "{$expiredNotSuspended} expired subscriptions not yet suspended";
        }
        
    } catch (Exception $e) {
        $health['subscriptions'] = ['status' => 'error', 'error' => $e->getMessage()];
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 6. OVERALL STATUS
    // ═══════════════════════════════════════════════════════════════════════
    
    $health['overall'] = [
        'status' => empty($health['issues']) ? (empty($health['warnings']) ? 'healthy' : 'warning') : 'critical',
        'issue_count' => count($health['issues']),
        'warning_count' => count($health['warnings']),
    ];
    
    // Update cache with health status
    $cache->updateNetworkHealth('magma', $health['magma']['status'] ?? 'unknown');
    $cache->updateNetworkHealth('prometheus', $health['prometheus']['status'] ?? 'unknown');
    
    // ═══════════════════════════════════════════════════════════════════════
    // 7. ALERT IF CRITICAL
    // ═══════════════════════════════════════════════════════════════════════
    
    if (!empty($health['issues'])) {
        // Log critical event
        $events->system('health_check_critical', [
            'issues' => $health['issues'],
            'warnings' => $health['warnings'],
        ], 'critical');
        
        // Queue WhatsApp alert to admins
        $adminPhones = $config['admin_alert_phones'] ?? [];
        foreach ($adminPhones as $phone) {
            $stmt = $pdo->prepare("
                INSERT INTO job_queue (job_type, payload, status, run_after, created_at)
                VALUES ('lte_notification', :payload, 'pending', datetime('now'), datetime('now'))
            ");
            $stmt->execute([':payload' => json_encode([
                'type' => 'health_alert',
                'phone' => $phone,
                'issues' => $health['issues'],
            ])]);
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] ⚠️ CRITICAL ISSUES DETECTED:\n";
        foreach ($health['issues'] as $issue) {
            echo "  - {$issue}\n";
        }
    }
    
    // Log completion
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $events->system('cron_completed', [
        'worker' => $workerName,
        'duration_ms' => $duration,
        'status' => $health['overall']['status'],
        'issues' => count($health['issues']),
        'warnings' => count($health['warnings']),
    ]);
    
    // Save health snapshot
    file_put_contents($dataDir . '/lte_health_snapshot.json', json_encode($health, JSON_PRETTY_PRINT));
    
    echo "[" . date('Y-m-d H:i:s') . "] Health check completed in {$duration}ms\n";
    echo "  Status: {$health['overall']['status']}\n";
    echo "  Issues: {$health['overall']['issue_count']}, Warnings: {$health['overall']['warning_count']}\n";
    
} catch (Exception $e) {
    error_log("[LTE Health] Error: " . $e->getMessage());
    exit(1);
}
