<?php
/**
 * LTE Worker: Expiry Check (Event-Driven)
 * ═══════════════════════════════════════════════════════════════════════
 * Handles subscription expiry, reminders, and auto-suspension.
 * 
 * SCALING STRATEGY:
 * Uses next_action_at field to process only subscriptions due for action.
 * Actions are queued instead of executed synchronously.
 * 
 * Run: php workers/lte/worker_expiry.php
 * Cron: (every 5 min) php /path/to/workers/lte/worker_expiry.php
 */

require_once __DIR__ . '/../../bootstrap.php';

$startTime = microtime(true);
$workerName = 'lte_expiry';
$maxPerRun = 500;

try {
    // Initialize services
    $store = SqliteStore::create($dataDir);
    $pdo = $store->getPdo();
    
    $events = new LteEventService($pdo);
    
    // Load config
    $config = json_decode(file_get_contents($dataDir . '/kyc_config.json'), true) ?: [];
    
    // ═══════════════════════════════════════════════════════════════════════
    // DRY RUN MODE CHECK - Skip all external actions in dry run mode
    // ═══════════════════════════════════════════════════════════════════════
    if (!empty($config['dry_run_mode'])) {
        echo "[DRY RUN] Worker {$workerName} skipped - dry_run_mode is enabled\n";
        exit(0);
    }
    
    $graceDays = (int)($config['lte_suspend_grace_days'] ?? 0);
    $reminderDays = $config['lte_expiry_reminder_days'] ?? [3, 1, 0];
    
    // Log start
    $events->system('cron_started', ['worker' => $workerName]);
    
    $stats = [
        'processed'        => 0,
        'reminders_queued' => 0,
        'suspensions_queued' => 0,
    ];
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 1: Send expiry reminders (3 days, 1 day, today)
    // ═══════════════════════════════════════════════════════════════════════
    
    foreach ($reminderDays as $daysLeft) {
        // Find subscriptions expiring in exactly $daysLeft days that haven't been reminded
        $reminderField = 'reminder_' . $daysLeft . '_sent';
        
        // Check if we need to add the reminder tracking columns
        // For now, use next_action_at with action_type = 'expiry_reminder_X'
        
        $targetDate = date('Y-m-d', strtotime("+{$daysLeft} days"));
        
        $stmt = $pdo->prepare("
            SELECT 
                sub.id AS subscription_id,
                sub.subscriber_id,
                sub.expires_at,
                sub.next_action_at,
                sub.action_type,
                s.phone,
                s.name,
                s.imsi,
                p.name AS package_name
            FROM lte_subscriptions sub
            JOIN lte_subscribers s ON s.id = sub.subscriber_id
            LEFT JOIN lte_packages p ON p.id = sub.package_id
            WHERE sub.status = 'active'
              AND DATE(sub.expires_at) = :target_date
              AND (
                sub.action_type IS NULL 
                OR sub.action_type != :action_type
              )
            LIMIT :limit
        ");
        $stmt->bindValue(':target_date', $targetDate, PDO::PARAM_STR);
        $stmt->bindValue(':action_type', 'expiry_reminder_' . $daysLeft, PDO::PARAM_STR);
        $stmt->bindValue(':limit', 100, PDO::PARAM_INT);
        $stmt->execute();
        $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($expiring as $sub) {
            // Queue reminder notification
            queueJob($pdo, 'lte_notification', [
                'type' => 'expiry_reminder',
                'phone' => $sub['phone'],
                'name' => $sub['name'],
                'package' => $sub['package_name'],
                'days_left' => $daysLeft,
                'expires_at' => $sub['expires_at'],
            ]);
            
            // Update next_action_at based on reminder sent
            $nextAction = null;
            $nextType = null;
            
            if ($daysLeft === 3) {
                // Next reminder at 1 day
                $nextAction = date('Y-m-d H:i:s', strtotime($sub['expires_at'] . ' -1 day'));
                $nextType = 'expiry_reminder_1';
            } elseif ($daysLeft === 1) {
                // Next reminder at expiry day
                $nextAction = date('Y-m-d H:i:s', strtotime($sub['expires_at']));
                $nextType = 'expiry_reminder_0';
            } elseif ($daysLeft === 0) {
                // Next action is suspension (at end of day + grace)
                $nextAction = date('Y-m-d H:i:s', strtotime($sub['expires_at'] . ' +' . $graceDays . ' days +1 day'));
                $nextType = 'suspend';
            }
            
            $updateStmt = $pdo->prepare("
                UPDATE lte_subscriptions SET
                    next_action_at = :next_action_at,
                    action_type = :action_type,
                    updated_at = datetime('now')
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':id' => $sub['subscription_id'],
                ':next_action_at' => $nextAction,
                ':action_type' => 'expiry_reminder_' . $daysLeft, // Mark as sent
            ]);
            
            $stats['reminders_queued']++;
            $stats['processed']++;
            
            $events->lifecycle('subscription_expiry_reminder', [
                'subscriber_id' => $sub['subscriber_id'],
                'subscription_id' => $sub['subscription_id'],
                'details' => ['days_left' => $daysLeft],
            ]);
        }
        
        if (count($expiring) > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Queued " . count($expiring) . " reminders for {$daysLeft}-day expiry\n";
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 2: Auto-suspend expired subscriptions
    // ═══════════════════════════════════════════════════════════════════════
    
    $graceDate = date('Y-m-d H:i:s', strtotime("-{$graceDays} days"));
    
    $stmt = $pdo->prepare("
        SELECT 
            sub.id AS subscription_id,
            sub.subscriber_id,
            sub.expires_at,
            s.imsi,
            s.phone,
            s.name,
            p.name AS package_name
        FROM lte_subscriptions sub
        JOIN lte_subscribers s ON s.id = sub.subscriber_id
        LEFT JOIN lte_packages p ON p.id = sub.package_id
        WHERE sub.status = 'active'
          AND sub.expires_at < :grace_date
          AND (
            sub.next_action_at IS NULL 
            OR sub.next_action_at <= datetime('now')
          )
          AND (sub.action_type IS NULL OR sub.action_type = 'suspend')
        LIMIT :limit
    ");
    $stmt->bindValue(':grace_date', $graceDate, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $maxPerRun, PDO::PARAM_INT);
    $stmt->execute();
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($expired as $sub) {
        // Queue suspension job
        queueJob($pdo, 'lte_suspend', [
            'subscription_id' => $sub['subscription_id'],
            'subscriber_id' => $sub['subscriber_id'],
            'imsi' => $sub['imsi'],
            'reason' => 'expired',
            'phone' => $sub['phone'],
            'name' => $sub['name'],
            'package' => $sub['package_name'],
        ]);
        
        // Mark as queued for suspension
        $updateStmt = $pdo->prepare("
            UPDATE lte_subscriptions SET
                action_type = 'suspension_queued',
                updated_at = datetime('now')
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $sub['subscription_id']]);
        
        $stats['suspensions_queued']++;
        $stats['processed']++;
    }
    
    if (count($expired) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Queued " . count($expired) . " suspensions for expired subscriptions\n";
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // STEP 3: Check for renewed subscribers that need reactivation
    // ═══════════════════════════════════════════════════════════════════════
    
    $stmt = $pdo->prepare("
        SELECT 
            sub.id AS subscription_id,
            sub.subscriber_id,
            sub.expires_at,
            s.imsi,
            s.status AS subscriber_status,
            s.magma_state
        FROM lte_subscriptions sub
        JOIN lte_subscribers s ON s.id = sub.subscriber_id
        WHERE sub.status = 'active'
          AND sub.expires_at > datetime('now')
          AND s.status = 'suspended'
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', 100, PDO::PARAM_INT);
    $stmt->execute();
    $needReactivation = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($needReactivation as $sub) {
        queueJob($pdo, 'lte_reactivate', [
            'subscription_id' => $sub['subscription_id'],
            'subscriber_id' => $sub['subscriber_id'],
            'imsi' => $sub['imsi'],
        ]);
        $stats['processed']++;
    }
    
    if (count($needReactivation) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Queued " . count($needReactivation) . " reactivations\n";
    }
    
    // Log completion
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $events->system('cron_completed', [
        'worker' => $workerName,
        'duration_ms' => $duration,
        'stats' => $stats,
    ]);
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Expiry check completed in {$duration}ms\n";
    echo "  Processed: {$stats['processed']}\n";
    echo "  Reminders queued: {$stats['reminders_queued']}\n";
    echo "  Suspensions queued: {$stats['suspensions_queued']}\n";
    
} catch (Exception $e) {
    $events->error('Expiry check failed', ['worker' => $workerName], $e);
    error_log("[LTE Expiry] Error: " . $e->getMessage());
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
