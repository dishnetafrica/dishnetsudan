<?php
/**
 * LteEventService
 * ═══════════════════════════════════════════════════════════════════════
 * Unified network event logging for the LTE module.
 * 
 * Replaces scattered log tables with a single event stream for:
 * - Audit trails
 * - Troubleshooting
 * - Analytics
 * - Compliance
 * 
 * Event Categories:
 * - lifecycle: subscriber/subscription state changes
 * - usage: data usage events
 * - network: Magma API interactions
 * - billing: payment/wallet events
 * - system: cron, imports, errors
 */

class LteEventService
{
    private PDO $pdo;
    
    // Event types by category
    const LIFECYCLE_EVENTS = [
        'subscriber_created',
        'subscriber_updated',
        'subscription_started',
        'subscription_renewed',
        'subscription_upgraded',
        'subscription_expired',
        'subscription_suspended',
        'subscription_reactivated',
        'subscription_terminated',
        'sim_assigned',
        'sim_swapped',
        'profile_changed',
    ];
    
    const USAGE_EVENTS = [
        'usage_synced',
        'usage_warning_50',
        'usage_warning_80',
        'usage_warning_95',
        'usage_exhausted',
        'usage_reset',
    ];
    
    const NETWORK_EVENTS = [
        'magma_provisioned',
        'magma_suspended',
        'magma_activated',
        'magma_profile_changed',
        'magma_deleted',
        'magma_error',
        'magma_timeout',
    ];
    
    const BILLING_EVENTS = [
        'payment_received',
        'wallet_debited',
        'wallet_credited',
        'commission_paid',
        'refund_issued',
    ];
    
    const SYSTEM_EVENTS = [
        'cron_started',
        'cron_completed',
        'import_started',
        'import_completed',
        'migration_completed',
        'error_occurred',
        'safety_guard_triggered',
    ];
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Log a network event
     */
    public function log(
        string $eventType,
        string $category,
        array $details = [],
        ?int $subscriberId = null,
        ?int $subscriptionId = null,
        ?int $simId = null,
        ?string $imsi = null,
        string $severity = 'info',
        ?string $actorType = null,
        ?int $actorId = null,
        ?string $actorName = null
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO lte_network_events 
            (subscriber_id, subscription_id, sim_id, imsi, event_type, event_category, 
             severity, details, actor_type, actor_id, actor_name, created_at)
            VALUES 
            (:subscriber_id, :subscription_id, :sim_id, :imsi, :event_type, :event_category,
             :severity, :details, :actor_type, :actor_id, :actor_name, datetime('now'))
        ");
        
        $stmt->execute([
            ':subscriber_id'   => $subscriberId,
            ':subscription_id' => $subscriptionId,
            ':sim_id'          => $simId,
            ':imsi'            => $imsi,
            ':event_type'      => $eventType,
            ':event_category'  => $category,
            ':severity'        => $severity,
            ':details'         => json_encode($details),
            ':actor_type'      => $actorType ?: 'system',
            ':actor_id'        => $actorId,
            ':actor_name'      => $actorName,
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // CONVENIENCE METHODS
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Log lifecycle event
     */
    public function lifecycle(string $event, array $context): int
    {
        return $this->log(
            $event,
            'lifecycle',
            $context['details'] ?? [],
            $context['subscriber_id'] ?? null,
            $context['subscription_id'] ?? null,
            $context['sim_id'] ?? null,
            $context['imsi'] ?? null,
            $context['severity'] ?? 'info',
            $context['actor_type'] ?? null,
            $context['actor_id'] ?? null,
            $context['actor_name'] ?? null
        );
    }
    
    /**
     * Log usage event
     */
    public function usage(string $event, array $context): int
    {
        return $this->log(
            $event,
            'usage',
            $context['details'] ?? [],
            $context['subscriber_id'] ?? null,
            $context['subscription_id'] ?? null,
            null,
            $context['imsi'] ?? null,
            $context['severity'] ?? 'info',
            'cron',
            null,
            'usage_sync'
        );
    }
    
    /**
     * Log network/Magma event
     */
    public function network(string $event, array $context): int
    {
        return $this->log(
            $event,
            'network',
            $context['details'] ?? [],
            $context['subscriber_id'] ?? null,
            $context['subscription_id'] ?? null,
            $context['sim_id'] ?? null,
            $context['imsi'] ?? null,
            $context['severity'] ?? 'info',
            $context['actor_type'] ?? 'system',
            $context['actor_id'] ?? null,
            $context['actor_name'] ?? null
        );
    }
    
    /**
     * Log billing event
     */
    public function billing(string $event, array $context): int
    {
        return $this->log(
            $event,
            'billing',
            $context['details'] ?? [],
            $context['subscriber_id'] ?? null,
            $context['subscription_id'] ?? null,
            null,
            null,
            $context['severity'] ?? 'info',
            $context['actor_type'] ?? 'system',
            $context['actor_id'] ?? null,
            $context['actor_name'] ?? null
        );
    }
    
    /**
     * Log system event
     */
    public function system(string $event, array $details = [], string $severity = 'info'): int
    {
        return $this->log($event, 'system', $details, null, null, null, null, $severity, 'system');
    }
    
    /**
     * Log error
     */
    public function error(string $message, array $context = [], ?\Throwable $exception = null): int
    {
        $details = array_merge($context, [
            'message' => $message,
        ]);
        
        if ($exception) {
            $details['exception'] = [
                'class'   => get_class($exception),
                'message' => $exception->getMessage(),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
            ];
        }
        
        return $this->log(
            'error_occurred',
            'system',
            $details,
            $context['subscriber_id'] ?? null,
            $context['subscription_id'] ?? null,
            null,
            $context['imsi'] ?? null,
            'error',
            'system'
        );
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // QUERY METHODS
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Get recent events for a subscriber
     */
    public function getSubscriberEvents(int $subscriberId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM lte_network_events
            WHERE subscriber_id = :id
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $subscriberId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get recent events by category
     */
    public function getEventsByCategory(string $category, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM lte_network_events
            WHERE event_category = :category
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':category', $category, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get event counts for dashboard
     */
    public function getEventSummary(string $since = '-24 hours'): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                event_category,
                event_type,
                COUNT(*) as count
            FROM lte_network_events
            WHERE created_at >= datetime('now', :since)
            GROUP BY event_category, event_type
            ORDER BY count DESC
        ");
        $stmt->execute([':since' => $since]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get error events for monitoring
     */
    public function getRecentErrors(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM lte_network_events
            WHERE severity IN ('error', 'critical')
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // USAGE HISTORY
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Record usage sample to time-series table
     */
    public function recordUsageSample(
        int $subscriptionId,
        int $subscriberId,
        string $imsi,
        int $bytesUsed,
        int $bytesDelta,
        int $planType = 0,
        ?int $bytesAllowed = null
    ): int {
        $usagePercent = ($bytesAllowed && $bytesAllowed > 0) 
            ? round(($bytesUsed / $bytesAllowed) * 100, 2) 
            : null;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO lte_usage_history 
            (subscription_id, subscriber_id, imsi, bytes_used, bytes_delta, 
             plan_type, bytes_allowed, usage_percent, recorded_at, source)
            VALUES 
            (:subscription_id, :subscriber_id, :imsi, :bytes_used, :bytes_delta,
             :plan_type, :bytes_allowed, :usage_percent, datetime('now'), 'prometheus')
        ");
        
        $stmt->execute([
            ':subscription_id' => $subscriptionId,
            ':subscriber_id'   => $subscriberId,
            ':imsi'            => $imsi,
            ':bytes_used'      => $bytesUsed,
            ':bytes_delta'     => $bytesDelta,
            ':plan_type'       => $planType,
            ':bytes_allowed'   => $bytesAllowed,
            ':usage_percent'   => $usagePercent,
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    /**
     * Get usage history for a subscription (for graphs)
     */
    public function getUsageHistory(int $subscriptionId, string $since = '-7 days'): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                recorded_at,
                bytes_used,
                bytes_delta,
                usage_percent
            FROM lte_usage_history
            WHERE subscription_id = :id
              AND recorded_at >= datetime('now', :since)
            ORDER BY recorded_at ASC
        ");
        $stmt->execute([':id' => $subscriptionId, ':since' => $since]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get aggregated daily usage for a subscriber
     */
    public function getDailyUsage(int $subscriberId, int $days = 30): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                date(recorded_at) as date,
                SUM(bytes_delta) as bytes_used,
                MAX(usage_percent) as max_usage_percent
            FROM lte_usage_history
            WHERE subscriber_id = :id
              AND recorded_at >= datetime('now', :days || ' days')
            GROUP BY date(recorded_at)
            ORDER BY date ASC
        ");
        $stmt->execute([':id' => $subscriberId, ':days' => -$days]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // CLEANUP
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Archive old events (call from maintenance worker)
     */
    public function archiveOldEvents(int $daysToKeep = 180): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM lte_network_events
            WHERE created_at < datetime('now', :days || ' days')
        ");
        $stmt->execute([':days' => -$daysToKeep]);
        
        return $stmt->rowCount();
    }
    
    /**
     * Archive old usage history
     */
    public function archiveOldUsageHistory(int $daysToKeep = 90): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM lte_usage_history
            WHERE recorded_at < datetime('now', :days || ' days')
        ");
        $stmt->execute([':days' => -$daysToKeep]);
        
        return $stmt->rowCount();
    }
}
