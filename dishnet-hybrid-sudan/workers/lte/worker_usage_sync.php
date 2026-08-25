<?php
/**
 * LTE Worker: Usage Sync (Event-Driven)
 * ═══════════════════════════════════════════════════════════════════════
 * Syncs usage from Magma Prometheus using event-driven approach.
 * 
 * SCALING STRATEGY:
 * Instead of scanning all 50K subscriptions, we only process:
 * 1. Subscriptions with next_action_at <= NOW()
 * 2. Subscribers active in last 24-48 hours (for unlimited plans)
 * 
 * This reduces 50K queries to ~5K per run.
 * 
 * Run: php workers/lte/worker_usage_sync.php
 * Cron: (every 5 min) php /path/to/workers/lte/worker_usage_sync.php
 */

require_once __DIR__ . '/../../bootstrap.php';

$startTime = microtime(true);
$workerName = 'lte_usage_sync';
$batchSize = 150;  // IMSIs per Prometheus query
$maxPerRun = 1000; // Max subscriptions to process per run

try {
    // Initialize services
    $store = SqliteStore::create($dataDir);
    $pdo = $store->getPdo();
    
    $lteSql = new LteSqliteService($pdo);
    $events = new LteEventService($pdo);
    $cache = new LteCacheService($pdo);
    
    // Load config
    $config = json_decode(file_get_contents($dataDir . '/kyc_config.json'), true) ?: [];
    
    // ═══════════════════════════════════════════════════════════════════════
    // DRY RUN MODE CHECK - Skip all external actions in dry run mode
    // ═══════════════════════════════════════════════════════════════════════
    if (!empty($config['dry_run_mode'])) {
        echo "[DRY RUN] Worker {$workerName} skipped - dry_run_mode is enabled\n";
        exit(0);
    }
    
    // Initialize Magma client
    $magma = new MagmaApiClient(
        $config['magma_host'] ?? '',
        $config['magma_network_id'] ?? 'bluecard',
        $config['magma_client_cert_path'] ?? '',
        $config['magma_client_key_path'] ?? ''
    );
    
    // Log start
    $events->system('cron_started', ['worker' => $workerName]);
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 1: Get subscriptions due for usage check (event-driven)
    // ═══════════════════════════════════════════════════════════════════════
    
    $stmt = $pdo->prepare("
        SELECT 
            sub.id AS subscription_id,
            sub.subscriber_id,
            sub.package_type,
            sub.bytes_allowed,
            sub.bytes_used,
            sub.bytes_baseline,
            sub.warned_50,
            sub.warned_80,
            s.imsi,
            s.phone,
            s.name
        FROM lte_subscriptions sub
        JOIN lte_subscribers s ON s.id = sub.subscriber_id
        WHERE sub.status = 'active'
          AND sub.package_type = 0  -- Data cap plans only
          AND (
            sub.next_action_at IS NULL 
            OR sub.next_action_at <= datetime('now')
          )
        ORDER BY sub.next_action_at ASC NULLS FIRST
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $maxPerRun, PDO::PARAM_INT);
    $stmt->execute();
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalToProcess = count($subscriptions);
    echo "[" . date('Y-m-d H:i:s') . "] Found {$totalToProcess} subscriptions due for usage check\n";
    
    if ($totalToProcess === 0) {
        $events->system('cron_completed', [
            'worker' => $workerName,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'processed' => 0,
            'message' => 'No subscriptions due for usage check',
        ]);
        exit(0);
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 2: Batch fetch usage from Prometheus
    // ═══════════════════════════════════════════════════════════════════════
    
    $imsiList = array_column($subscriptions, 'imsi');
    $imsiChunks = array_chunk($imsiList, $batchSize);
    
    $usageData = [];
    $prometheusErrors = 0;
    
    foreach ($imsiChunks as $i => $chunk) {
        $batchStart = microtime(true);
        
        try {
            $result = $magma->getBulkUsage($chunk);
            $usageData = array_merge($usageData, $result);
            
            $batchDuration = round((microtime(true) - $batchStart) * 1000, 2);
            echo "  Batch " . ($i + 1) . "/" . count($imsiChunks) . ": " . count($chunk) . " IMSIs in {$batchDuration}ms\n";
            
        } catch (Exception $e) {
            $prometheusErrors++;
            $events->error('Prometheus batch failed', [
                'batch' => $i + 1,
                'chunk_size' => count($chunk),
            ], $e);
            echo "  Batch " . ($i + 1) . " FAILED: " . $e->getMessage() . "\n";
        }
        
        // Small delay between batches to avoid overwhelming Prometheus
        usleep(50000); // 50ms
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 3: Process each subscription
    // ═══════════════════════════════════════════════════════════════════════
    
    $stats = [
        'processed'  => 0,
        'updated'    => 0,
        'warned_50'  => 0,
        'warned_80'  => 0,
        'exhausted'  => 0,
        'suspended'  => 0,
        'errors'     => 0,
    ];
    
    // Prepare update statements
    $updateSub = $pdo->prepare("
        UPDATE lte_subscriptions SET
            bytes_used = :bytes_used,
            usage_percent = :usage_percent,
            warned_50 = :warned_50,
            warned_80 = :warned_80,
            last_usage_sync = datetime('now'),
            next_action_at = :next_action_at,
            action_type = :action_type,
            updated_at = datetime('now')
        WHERE id = :id
    ");
    
    $updateSubscriberActivity = $pdo->prepare("
        UPDATE lte_subscribers SET
            last_activity_at = datetime('now'),
            is_online = 1
        WHERE id = :id
    ");
    
    // Notification queue (don't send immediately - use job queue)
    $notifications = [];
    
    foreach ($subscriptions as $sub) {
        $stats['processed']++;
        $imsi = $sub['imsi'];
        
        // Get usage from Prometheus data
        $prometheusBytes = $usageData[$imsi]['bytes_used'] ?? null;
        
        if ($prometheusBytes === null) {
            // No data from Prometheus - subscriber may be offline
            // Schedule next check in 15 minutes
            $updateSub->execute([
                ':id' => $sub['subscription_id'],
                ':bytes_used' => $sub['bytes_used'],
                ':usage_percent' => $sub['bytes_allowed'] > 0 
                    ? round(($sub['bytes_used'] / $sub['bytes_allowed']) * 100, 2) 
                    : 0,
                ':warned_50' => $sub['warned_50'],
                ':warned_80' => $sub['warned_80'],
                ':next_action_at' => date('Y-m-d H:i:s', strtotime('+15 minutes')),
                ':action_type' => 'usage_check',
            ]);
            continue;
        }
        
        // Calculate actual usage (subtract baseline)
        $baseline = (int)($sub['bytes_baseline'] ?? 0);
        $actualUsed = max(0, $prometheusBytes - $baseline);
        $bytesAllowed = (int)$sub['bytes_allowed'];
        
        $usagePercent = $bytesAllowed > 0 
            ? round(($actualUsed / $bytesAllowed) * 100, 2) 
            : 0;
        
        // Update subscriber activity (they're using data)
        $updateSubscriberActivity->execute([':id' => $sub['subscriber_id']]);
        
        // Record usage history sample
        $bytesDelta = max(0, $actualUsed - (int)$sub['bytes_used']);
        if ($bytesDelta > 0) {
            $events->recordUsageSample(
                $sub['subscription_id'],
                $sub['subscriber_id'],
                $imsi,
                $actualUsed,
                $bytesDelta,
                0, // package_type = data_cap
                $bytesAllowed
            );
        }
        
        // Check thresholds
        $warned50 = (int)$sub['warned_50'];
        $warned80 = (int)$sub['warned_80'];
        $nextAction = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        $actionType = 'usage_check';
        
        if ($usagePercent >= 50 && $usagePercent < 80 && !$warned50) {
            $warned50 = 1;
            $stats['warned_50']++;
            $notifications[] = [
                'type' => 'usage_warning_50',
                'phone' => $sub['phone'],
                'name' => $sub['name'],
                'used_gb' => round($actualUsed / 1073741824, 2),
                'total_gb' => round($bytesAllowed / 1073741824, 2),
                'percent' => $usagePercent,
            ];
            $events->usage('usage_warning_50', [
                'subscriber_id' => $sub['subscriber_id'],
                'subscription_id' => $sub['subscription_id'],
                'imsi' => $imsi,
                'details' => ['usage_percent' => $usagePercent],
            ]);
        }
        
        if ($usagePercent >= 80 && $usagePercent < 100 && !$warned80) {
            $warned80 = 1;
            $stats['warned_80']++;
            $notifications[] = [
                'type' => 'usage_warning_80',
                'phone' => $sub['phone'],
                'name' => $sub['name'],
                'used_gb' => round($actualUsed / 1073741824, 2),
                'total_gb' => round($bytesAllowed / 1073741824, 2),
                'percent' => $usagePercent,
            ];
            $events->usage('usage_warning_80', [
                'subscriber_id' => $sub['subscriber_id'],
                'subscription_id' => $sub['subscription_id'],
                'imsi' => $imsi,
                'details' => ['usage_percent' => $usagePercent],
                'severity' => 'warning',
            ]);
        }
        
        if ($actualUsed >= $bytesAllowed) {
            $stats['exhausted']++;
            // Queue suspension job instead of doing it synchronously
            queueJob($pdo, 'lte_suspend', [
                'subscription_id' => $sub['subscription_id'],
                'subscriber_id' => $sub['subscriber_id'],
                'imsi' => $imsi,
                'reason' => 'data_exhausted',
            ]);
            $nextAction = null; // Will be handled by suspension
            $actionType = null;
        }
        
        // Update subscription
        $updateSub->execute([
            ':id' => $sub['subscription_id'],
            ':bytes_used' => $actualUsed,
            ':usage_percent' => $usagePercent,
            ':warned_50' => $warned50,
            ':warned_80' => $warned80,
            ':next_action_at' => $nextAction,
            ':action_type' => $actionType,
        ]);
        
        $stats['updated']++;
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 4: Queue notifications (don't block main loop)
    // ═══════════════════════════════════════════════════════════════════════
    
    foreach ($notifications as $notif) {
        queueJob($pdo, 'lte_notification', $notif);
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 5: Update network health cache
    // ═══════════════════════════════════════════════════════════════════════
    
    $cache->updateNetworkHealth('prometheus', $prometheusErrors > 0 ? 'degraded' : 'ok');
    
    // Log completion
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $events->system('cron_completed', [
        'worker' => $workerName,
        'duration_ms' => $duration,
        'stats' => $stats,
    ]);
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Usage sync completed in {$duration}ms\n";
    echo "  Processed: {$stats['processed']}, Updated: {$stats['updated']}\n";
    echo "  Warnings: 50%={$stats['warned_50']}, 80%={$stats['warned_80']}\n";
    echo "  Exhausted: {$stats['exhausted']}\n";
    
} catch (Exception $e) {
    $events->error('Usage sync failed', ['worker' => $workerName], $e);
    error_log("[LTE Usage Sync] Error: " . $e->getMessage());
    exit(1);
}

/**
 * Helper: Queue a job
 */
function queueJob(PDO $pdo, string $jobType, array $payload, ?string $runAfter = null): int
{
    $stmt = $pdo->prepare("
        INSERT INTO job_queue (job_type, payload, status, run_after, created_at)
        VALUES (:type, :payload, 'pending', :run_after, datetime('now'))
    ");
    $stmt->execute([
        ':type'      => $jobType,
        ':payload'   => json_encode($payload),
        ':run_after' => $runAfter ?: date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}
