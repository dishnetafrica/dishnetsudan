<?php
/**
 * LTE Worker: Job Processor
 * ═══════════════════════════════════════════════════════════════════════
 * Processes queued jobs asynchronously:
 * - lte_suspend: Suspend subscriber in Magma
 * - lte_reactivate: Reactivate subscriber in Magma
 * - lte_notification: Send WhatsApp notifications
 * - lte_profile_change: Change Magma profile
 * 
 * SCALING STRATEGY:
 * Network calls (Magma API, WhatsApp) are moved off the main thread.
 * Workers process jobs in batches, with retries and error handling.
 * 
 * Run: php workers/lte/worker_jobs.php
 * Cron: * * * * * php /path/to/workers/lte/worker_jobs.php
 * (runs every minute)
 */

require_once __DIR__ . '/../../bootstrap.php';

$startTime = microtime(true);
$workerName = 'lte_jobs';
$maxJobsPerRun = 100;
$maxRetries = 3;

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
    $magma = null;
    if (!empty($config['magma_host'])) {
        $magma = new MagmaApiClient(
            $config['magma_host'],
            $config['magma_network_id'] ?? 'bluecard',
            $config['magma_client_cert_path'] ?? '',
            $config['magma_client_key_path'] ?? ''
        );
    }
    
    // Initialize notification service if available
    $notify = null;
    if (class_exists('NotificationService')) {
        $notify = new NotificationService($store, $config);
    }
    
    // Log start (only if we actually process jobs)
    $loggedStart = false;
    
    // ═══════════════════════════════════════════════════════════════════════
    // FETCH PENDING JOBS
    // ═══════════════════════════════════════════════════════════════════════
    
    $stmt = $pdo->prepare("
        SELECT * FROM job_queue
        WHERE status = 'pending'
          AND job_type LIKE 'lte_%'
          AND run_after <= datetime('now')
          AND (attempts IS NULL OR attempts < :max_retries)
        ORDER BY run_after ASC, created_at ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':max_retries', $maxRetries, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $maxJobsPerRun, PDO::PARAM_INT);
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($jobs) === 0) {
        // No jobs to process, exit silently
        exit(0);
    }
    
    // Log start
    $events->system('cron_started', ['worker' => $workerName, 'job_count' => count($jobs)]);
    echo "[" . date('Y-m-d H:i:s') . "] Processing " . count($jobs) . " LTE jobs\n";
    
    $stats = [
        'processed' => 0,
        'success'   => 0,
        'failed'    => 0,
        'retried'   => 0,
    ];
    
    // ═══════════════════════════════════════════════════════════════════════
    // PROCESS EACH JOB
    // ═══════════════════════════════════════════════════════════════════════
    
    foreach ($jobs as $job) {
        $stats['processed']++;
        $jobId = $job['id'];
        $jobType = $job['job_type'];
        $payload = json_decode($job['payload'], true) ?: [];
        $attempts = (int)($job['attempts'] ?? 0) + 1;
        
        try {
            switch ($jobType) {
                case 'lte_suspend':
                    $result = processLteSuspend($pdo, $magma, $events, $payload);
                    break;
                    
                case 'lte_reactivate':
                    $result = processLteReactivate($pdo, $magma, $events, $payload);
                    break;
                    
                case 'lte_notification':
                    $result = processLteNotification($pdo, $notify, $events, $payload);
                    break;
                    
                case 'lte_profile_change':
                    $result = processLteProfileChange($pdo, $magma, $events, $payload);
                    break;
                    
                default:
                    throw new Exception("Unknown job type: {$jobType}");
            }
            
            // Mark job as completed
            $updateStmt = $pdo->prepare("
                UPDATE job_queue SET
                    status = 'completed',
                    attempts = :attempts,
                    completed_at = datetime('now'),
                    result = :result
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':id' => $jobId,
                ':attempts' => $attempts,
                ':result' => json_encode($result),
            ]);
            
            $stats['success']++;
            echo "  ✓ Job #{$jobId} ({$jobType}): Success\n";
            
        } catch (Exception $e) {
            // Handle job failure
            if ($attempts >= $maxRetries) {
                // Max retries reached, mark as failed
                $updateStmt = $pdo->prepare("
                    UPDATE job_queue SET
                        status = 'failed',
                        attempts = :attempts,
                        error = :error,
                        completed_at = datetime('now')
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id' => $jobId,
                    ':attempts' => $attempts,
                    ':error' => $e->getMessage(),
                ]);
                
                $stats['failed']++;
                $events->error("Job #{$jobId} failed permanently", [
                    'job_type' => $jobType,
                    'attempts' => $attempts,
                ], $e);
                
                echo "  ✗ Job #{$jobId} ({$jobType}): FAILED (max retries)\n";
                
            } else {
                // Schedule retry with exponential backoff
                $backoffMinutes = pow(2, $attempts); // 2, 4, 8 minutes
                $runAfter = date('Y-m-d H:i:s', strtotime("+{$backoffMinutes} minutes"));
                
                $updateStmt = $pdo->prepare("
                    UPDATE job_queue SET
                        attempts = :attempts,
                        error = :error,
                        run_after = :run_after
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id' => $jobId,
                    ':attempts' => $attempts,
                    ':error' => $e->getMessage(),
                    ':run_after' => $runAfter,
                ]);
                
                $stats['retried']++;
                echo "  ↻ Job #{$jobId} ({$jobType}): Retry scheduled in {$backoffMinutes} min\n";
            }
        }
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // CLEANUP OLD COMPLETED JOBS
    // ═══════════════════════════════════════════════════════════════════════
    
    // Delete completed jobs older than 24 hours
    $pdo->exec("
        DELETE FROM job_queue 
        WHERE status = 'completed' 
          AND completed_at < datetime('now', '-24 hours')
    ");
    
    // Log completion
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    $events->system('cron_completed', [
        'worker' => $workerName,
        'duration_ms' => $duration,
        'stats' => $stats,
    ]);
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Job processing completed in {$duration}ms\n";
    echo "  Processed: {$stats['processed']}, Success: {$stats['success']}\n";
    echo "  Failed: {$stats['failed']}, Retried: {$stats['retried']}\n";
    
} catch (Exception $e) {
    $events->error('Job processor failed', ['worker' => $workerName], $e);
    error_log("[LTE Jobs] Error: " . $e->getMessage());
    exit(1);
}

// ═══════════════════════════════════════════════════════════════════════════
// JOB HANDLERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Process LTE suspension job
 */
function processLteSuspend(PDO $pdo, ?MagmaApiClient $magma, LteEventService $events, array $payload): array
{
    $subscriptionId = $payload['subscription_id'];
    $subscriberId = $payload['subscriber_id'];
    $imsi = $payload['imsi'];
    $reason = $payload['reason'] ?? 'unknown';
    
    // Call Magma API to suspend
    if ($magma) {
        $result = $magma->suspendSubscriber($imsi);
        if (!$result) {
            throw new Exception("Magma suspend failed for IMSI {$imsi}");
        }
    }
    
    // Update local records
    $stmt = $pdo->prepare("
        UPDATE lte_subscriptions SET
            status = :status,
            suspended_at = datetime('now'),
            suspension_reason = :reason,
            next_action_at = NULL,
            action_type = NULL,
            updated_at = datetime('now')
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $subscriptionId,
        ':status' => $reason === 'data_exhausted' ? 'exhausted' : 'expired',
        ':reason' => $reason,
    ]);
    
    $stmt = $pdo->prepare("
        UPDATE lte_subscribers SET
            status = 'suspended',
            magma_state = 'INACTIVE',
            updated_at = datetime('now')
        WHERE id = :id
    ");
    $stmt->execute([':id' => $subscriberId]);
    
    // Log event
    $events->lifecycle('subscription_suspended', [
        'subscriber_id' => $subscriberId,
        'subscription_id' => $subscriptionId,
        'imsi' => $imsi,
        'details' => ['reason' => $reason],
        'severity' => 'warning',
    ]);
    
    $events->network('magma_suspended', [
        'subscriber_id' => $subscriberId,
        'imsi' => $imsi,
        'details' => ['reason' => $reason],
    ]);
    
    // Queue notification
    if (!empty($payload['phone'])) {
        $notifyPayload = [
            'type' => 'suspended',
            'phone' => $payload['phone'],
            'name' => $payload['name'] ?? 'Customer',
            'reason' => $reason,
            'package' => $payload['package'] ?? 'LTE Plan',
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO job_queue (job_type, payload, status, run_after, created_at)
            VALUES ('lte_notification', :payload, 'pending', datetime('now'), datetime('now'))
        ");
        $stmt->execute([':payload' => json_encode($notifyPayload)]);
    }
    
    return ['suspended' => true, 'imsi' => $imsi, 'reason' => $reason];
}

/**
 * Process LTE reactivation job
 */
function processLteReactivate(PDO $pdo, ?MagmaApiClient $magma, LteEventService $events, array $payload): array
{
    $subscriptionId = $payload['subscription_id'];
    $subscriberId = $payload['subscriber_id'];
    $imsi = $payload['imsi'];
    
    // Call Magma API to reactivate
    if ($magma) {
        $result = $magma->activateSubscriber($imsi);
        if (!$result) {
            throw new Exception("Magma activate failed for IMSI {$imsi}");
        }
    }
    
    // Update local records
    $stmt = $pdo->prepare("
        UPDATE lte_subscribers SET
            status = 'active',
            magma_state = 'ACTIVE',
            updated_at = datetime('now')
        WHERE id = :id
    ");
    $stmt->execute([':id' => $subscriberId]);
    
    // Reset next_action_at for usage tracking
    $stmt = $pdo->prepare("
        UPDATE lte_subscriptions SET
            next_action_at = datetime('now', '+5 minutes'),
            action_type = 'usage_check',
            updated_at = datetime('now')
        WHERE id = :id AND status = 'active'
    ");
    $stmt->execute([':id' => $subscriptionId]);
    
    // Log event
    $events->lifecycle('subscription_reactivated', [
        'subscriber_id' => $subscriberId,
        'subscription_id' => $subscriptionId,
        'imsi' => $imsi,
    ]);
    
    $events->network('magma_activated', [
        'subscriber_id' => $subscriberId,
        'imsi' => $imsi,
    ]);
    
    return ['reactivated' => true, 'imsi' => $imsi];
}

/**
 * Process LTE notification job
 */
function processLteNotification(PDO $pdo, $notify, LteEventService $events, array $payload): array
{
    $type = $payload['type'];
    $phone = $payload['phone'] ?? null;
    $name = $payload['name'] ?? 'Customer';
    
    if (!$phone) {
        return ['skipped' => true, 'reason' => 'no_phone'];
    }
    
    // Build message based on type
    $message = '';
    
    switch ($type) {
        case 'usage_warning_50':
            $used = $payload['used_gb'] ?? '?';
            $total = $payload['total_gb'] ?? '?';
            $message = "⚠️ *Data Usage Alert*\n\n" .
                      "Hello {$name},\n\n" .
                      "You have used *50%* of your data plan.\n" .
                      "Used: {$used} GB / {$total} GB\n\n" .
                      "Top up soon to avoid interruption.\n\n" .
                      "_DishNet Africa_";
            break;
            
        case 'usage_warning_80':
            $used = $payload['used_gb'] ?? '?';
            $total = $payload['total_gb'] ?? '?';
            $message = "🚨 *Low Data Warning*\n\n" .
                      "Hello {$name},\n\n" .
                      "You have used *80%* of your data plan!\n" .
                      "Used: {$used} GB / {$total} GB\n\n" .
                      "Please top up now to continue service.\n\n" .
                      "_DishNet Africa_";
            break;
            
        case 'expiry_reminder':
            $days = $payload['days_left'] ?? 0;
            $package = $payload['package'] ?? 'your plan';
            $expires = $payload['expires_at'] ?? '';
            
            if ($days === 0) {
                $message = "⏰ *Plan Expires Today*\n\n" .
                          "Hello {$name},\n\n" .
                          "Your *{$package}* expires TODAY.\n\n" .
                          "Renew now to continue service.\n\n" .
                          "📞 +211 927 797 217\n\n" .
                          "_DishNet Africa_";
            } else {
                $message = "🔔 *Reminder: Plan Expiring Soon*\n\n" .
                          "Hello {$name},\n\n" .
                          "Your *{$package}* will expire in *{$days} day(s)*.\n\n" .
                          "Contact your agent or visit our office to renew.\n\n" .
                          "_DishNet Africa_";
            }
            break;
            
        case 'suspended':
            $reason = $payload['reason'] ?? 'expired';
            $reasonText = $reason === 'data_exhausted' ? 'data exhausted' : 'plan expired';
            $package = $payload['package'] ?? 'LTE Plan';
            
            $message = "🚫 *Service Suspended*\n\n" .
                      "Hello {$name},\n\n" .
                      "Your DishNet LTE service (*{$package}*) has been " .
                      "suspended due to {$reasonText}.\n\n" .
                      "Please renew to restore service.\n\n" .
                      "📞 +211 927 797 217\n\n" .
                      "_DishNet Africa_";
            break;
            
        case 'reactivated':
            $package = $payload['package'] ?? 'LTE Plan';
            $expires = $payload['expires_at'] ?? '';
            
            $message = "✅ *Service Restored*\n\n" .
                      "Hello {$name},\n\n" .
                      "Welcome back! Your DishNet LTE service is now active.\n\n" .
                      "📦 Plan: {$package}\n" .
                      ($expires ? "📅 Valid until: {$expires}\n\n" : "\n") .
                      "_DishNet Africa_";
            break;
            
        case 'daily_report':
            // Pre-formatted message from worker_report.php
            $message = $payload['message'] ?? '';
            if (empty($message)) {
                return ['skipped' => true, 'reason' => 'empty_report'];
            }
            break;
            
        case 'health_alert':
            $issues = $payload['issues'] ?? [];
            if (empty($issues)) {
                return ['skipped' => true, 'reason' => 'no_issues'];
            }
            
            $message = "🚨 *LTE System Alert*\n\n" .
                      "Critical issues detected:\n\n";
            foreach ($issues as $issue) {
                $message .= "• {$issue}\n";
            }
            $message .= "\nPlease investigate immediately.\n\n" .
                       "_DishNet NOC_";
            break;
            
        default:
            return ['skipped' => true, 'reason' => 'unknown_type', 'type' => $type];
    }
    
    // Send via notification service
    if ($notify && method_exists($notify, 'sendWhatsApp')) {
        $sent = $notify->sendWhatsApp($phone, $message);
    } else {
        // Log that we would send (notification service not available)
        $sent = true;
        echo "    [Would send to {$phone}]: " . substr($message, 0, 50) . "...\n";
    }
    
    return [
        'sent' => $sent,
        'type' => $type,
        'phone' => substr($phone, 0, 8) . '***',
    ];
}

/**
 * Process Magma profile change job
 */
function processLteProfileChange(PDO $pdo, ?MagmaApiClient $magma, LteEventService $events, array $payload): array
{
    $imsi = $payload['imsi'];
    $newProfile = $payload['profile'];
    $subscriberId = $payload['subscriber_id'] ?? null;
    
    if (!$magma) {
        throw new Exception("Magma client not configured");
    }
    
    $result = $magma->changeProfile($imsi, $newProfile);
    if (!$result) {
        throw new Exception("Magma profile change failed for IMSI {$imsi}");
    }
    
    // Log event
    $events->network('magma_profile_changed', [
        'subscriber_id' => $subscriberId,
        'imsi' => $imsi,
        'details' => ['new_profile' => $newProfile],
    ]);
    
    return ['changed' => true, 'imsi' => $imsi, 'profile' => $newProfile];
}
