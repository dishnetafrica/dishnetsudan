<?php
/**
 * LteCacheService
 * ═══════════════════════════════════════════════════════════════════════
 * Manages system cache for dashboard performance at scale.
 * 
 * At 50K subscribers, dashboards cannot scan full tables.
 * This service maintains pre-computed statistics that are:
 * - Updated every 5 minutes by a background worker
 * - Read instantly by dashboard queries
 * 
 * Cache keys:
 * - lte_subscriber_counts: {active, suspended, total}
 * - lte_usage_summary: {total_gb, avg_per_user}
 * - lte_revenue_today: {amount, count}
 * - lte_expiring_soon: {today, 3_days, 7_days}
 * - lte_network_health: {magma, prometheus, last_check}
 * - lte_usage_alerts: {warning_50, warning_80, exhausted}
 */

class LteCacheService
{
    private PDO $pdo;
    private int $defaultTtlSeconds = 300; // 5 minutes
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // GENERIC CACHE METHODS
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Get cached value
     */
    public function get(string $key): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT cache_value, updated_at, expires_at
            FROM system_cache
            WHERE cache_key = :key
        ");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        // Check TTL
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            return null; // Expired
        }
        
        return [
            'value'      => json_decode($row['cache_value'], true),
            'updated_at' => $row['updated_at'],
            'expires_at' => $row['expires_at'],
        ];
    }
    
    /**
     * Get just the value (convenience method)
     */
    public function getValue(string $key, $default = null)
    {
        $cached = $this->get($key);
        return $cached ? $cached['value'] : $default;
    }
    
    /**
     * Set cached value
     */
    public function set(string $key, $value, ?int $ttlSeconds = null, string $category = 'lte'): bool
    {
        $ttl = $ttlSeconds ?: $this->defaultTtlSeconds;
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO system_cache (cache_key, cache_value, updated_at, expires_at, category)
            VALUES (:key, :value, datetime('now'), :expires_at, :category)
            ON CONFLICT(cache_key) DO UPDATE SET
                cache_value = excluded.cache_value,
                updated_at = datetime('now'),
                expires_at = excluded.expires_at
        ");
        
        return $stmt->execute([
            ':key'        => $key,
            ':value'      => json_encode($value),
            ':expires_at' => $expiresAt,
            ':category'   => $category,
        ]);
    }
    
    /**
     * Delete cached value
     */
    public function delete(string $key): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM system_cache WHERE cache_key = :key");
        return $stmt->execute([':key' => $key]);
    }
    
    /**
     * Clear all cache for a category
     */
    public function clearCategory(string $category): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM system_cache WHERE category = :cat");
        $stmt->execute([':cat' => $category]);
        return $stmt->rowCount();
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // LTE DASHBOARD CACHE REFRESH (called by worker)
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Refresh all LTE dashboard caches
     * Called by worker_cache.php every 5 minutes
     */
    public function refreshAllLteCache(): array
    {
        $results = [];
        
        $results['subscriber_counts'] = $this->refreshSubscriberCounts();
        $results['usage_summary']     = $this->refreshUsageSummary();
        $results['revenue_today']     = $this->refreshRevenueToday();
        $results['expiring_soon']     = $this->refreshExpiringSoon();
        $results['usage_alerts']      = $this->refreshUsageAlerts();
        
        return $results;
    }
    
    /**
     * Refresh subscriber counts
     * Uses v_lte_subscriber_summary view for optimized query
     */
    public function refreshSubscriberCounts(): array
    {
        // Try using the optimized view first
        try {
            $stmt = $this->pdo->query("SELECT * FROM v_lte_subscriber_summary");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback to direct query if view doesn't exist
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN status = 'terminated' THEN 1 ELSE 0 END) AS terminated,
                    SUM(CASE WHEN last_activity_at >= datetime('now', '-24 hours') THEN 1 ELSE 0 END) AS active_24h,
                    SUM(CASE WHEN last_activity_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS active_7d
                FROM lte_subscribers
            ");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $this->set('lte_subscriber_counts', $data);
        return $data;
    }
    
    /**
     * Refresh usage summary
     */
    public function refreshUsageSummary(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                COALESCE(SUM(bytes_used), 0) AS total_bytes,
                COALESCE(AVG(bytes_used), 0) AS avg_bytes,
                COUNT(*) AS subscription_count
            FROM lte_subscriptions
            WHERE status = 'active'
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $data = [
            'total_gb'     => round($row['total_bytes'] / 1073741824, 2),
            'avg_per_user' => round($row['avg_bytes'] / 1073741824, 2),
            'count'        => (int)$row['subscription_count'],
        ];
        
        $this->set('lte_usage_summary', $data);
        return $data;
    }
    
    /**
     * Refresh today's revenue
     */
    public function refreshRevenueToday(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                COALESCE(SUM(amount_paid), 0) AS amount,
                COUNT(*) AS count
            FROM lte_renewals
            WHERE DATE(created_at) = DATE('now')
        ");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        $data['amount'] = (float)$data['amount'];
        $data['count'] = (int)$data['count'];
        
        $this->set('lte_revenue_today', $data);
        return $data;
    }
    
    /**
     * Refresh expiring soon counts
     * Uses v_lte_expiry_summary view for optimized query
     */
    public function refreshExpiringSoon(): array
    {
        // Try using the optimized view first
        try {
            $stmt = $this->pdo->query("SELECT * FROM v_lte_expiry_summary");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $data = [
                'expired'   => (int)($row['expired'] ?? 0),
                'today'     => (int)($row['expires_today'] ?? 0),
                'in_3_days' => (int)($row['expires_today'] ?? 0) + (int)($row['expires_3_days'] ?? 0),
                'in_7_days' => (int)($row['expires_today'] ?? 0) + (int)($row['expires_3_days'] ?? 0) + (int)($row['expires_7_days'] ?? 0),
            ];
        } catch (PDOException $e) {
            // Fallback to direct query
            $stmt = $this->pdo->query("
                SELECT 
                    SUM(CASE WHEN expires_at < datetime('now') THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN DATE(expires_at) = DATE('now') THEN 1 ELSE 0 END) AS today,
                    SUM(CASE WHEN expires_at BETWEEN datetime('now') AND datetime('now', '+3 days') THEN 1 ELSE 0 END) AS in_3_days,
                    SUM(CASE WHEN expires_at BETWEEN datetime('now') AND datetime('now', '+7 days') THEN 1 ELSE 0 END) AS in_7_days
                FROM lte_subscriptions
                WHERE status = 'active'
            ");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $this->set('lte_expiring_soon', $data);
        return $data;
    }
    
    /**
     * Refresh usage alert counts
     * Uses v_lte_usage_alert_summary view for optimized query
     */
    public function refreshUsageAlerts(): array
    {
        // Try using the optimized view first
        try {
            $stmt = $this->pdo->query("SELECT * FROM v_lte_usage_alert_summary");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback to direct query
            $stmt = $this->pdo->query("
                SELECT 
                    SUM(CASE WHEN usage_percent >= 50 AND usage_percent < 80 THEN 1 ELSE 0 END) AS warning_50,
                    SUM(CASE WHEN usage_percent >= 80 AND usage_percent < 95 THEN 1 ELSE 0 END) AS warning_80,
                    SUM(CASE WHEN usage_percent >= 95 THEN 1 ELSE 0 END) AS critical,
                    SUM(CASE WHEN bytes_used >= bytes_allowed AND bytes_allowed > 0 THEN 1 ELSE 0 END) AS exhausted
                FROM lte_subscriptions
                WHERE status = 'active' AND package_type = 0
            ");
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $this->set('lte_usage_alerts', $data);
        return $data;
    }
    
    /**
     * Update network health status
     */
    public function updateNetworkHealth(string $component, string $status, ?string $error = null): void
    {
        $current = $this->getValue('lte_network_health', [
            'magma'      => 'unknown',
            'prometheus' => 'unknown',
            'last_check' => null,
            'errors'     => [],
        ]);
        
        $current[$component] = $status;
        $current['last_check'] = date('Y-m-d H:i:s');
        
        if ($error) {
            $current['errors'][$component] = $error;
        } else {
            unset($current['errors'][$component]);
        }
        
        $this->set('lte_network_health', $current, 600); // 10 min TTL
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // DASHBOARD DATA RETRIEVAL (fast reads)
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Get all LTE dashboard data in one call
     */
    public function getLteDashboardData(): array
    {
        return [
            'subscriber_counts'  => $this->getValue('lte_subscriber_counts', []),
            'usage_summary'      => $this->getValue('lte_usage_summary', []),
            'revenue_today'      => $this->getValue('lte_revenue_today', []),
            'revenue_month'      => $this->getValue('lte_revenue_month', []),
            'expiring_soon'      => $this->getValue('lte_expiring_soon', []),
            'usage_alerts'       => $this->getValue('lte_usage_alerts', []),
            'usage_distribution' => $this->getValue('lte_usage_distribution', []),
            'network_health'     => $this->getValue('lte_network_health', []),
            'subscriber_growth'  => $this->getValue('lte_subscriber_growth', []),
            'top_agents'         => $this->getValue('lte_top_agents', []),
            'top_packages'       => $this->getValue('lte_top_packages', []),
            'queue_stats'        => $this->getValue('lte_queue_stats', []),
            'cached_at'          => $this->get('lte_subscriber_counts')['updated_at'] ?? null,
        ];
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // DASHBOARD 2: SUBSCRIBER GROWTH
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Refresh subscriber growth metrics
     */
    public function refreshSubscriberGrowth(): array
    {
        // New subscribers by period
        $stmt = $this->pdo->query("
            SELECT 
                SUM(CASE WHEN DATE(created_at) = DATE('now') THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN created_at >= datetime('now', '-7 days') THEN 1 ELSE 0 END) AS week,
                SUM(CASE WHEN created_at >= datetime('now', '-30 days') THEN 1 ELSE 0 END) AS month,
                COUNT(*) AS total
            FROM lte_subscribers
            WHERE status != 'terminated'
        ");
        $growth = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Churn (suspended/terminated in last 30 days)
        $stmt = $this->pdo->query("
            SELECT COUNT(*) AS churned
            FROM lte_subscribers
            WHERE status IN ('suspended', 'terminated')
              AND updated_at >= datetime('now', '-30 days')
        ");
        $churn = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Monthly trend (last 6 months)
        $stmt = $this->pdo->query("
            SELECT 
                strftime('%Y-%m', created_at) AS month,
                COUNT(*) AS new_subscribers
            FROM lte_subscribers
            WHERE created_at >= datetime('now', '-6 months')
            GROUP BY strftime('%Y-%m', created_at)
            ORDER BY month ASC
        ");
        $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [
            'today'        => (int)$growth['today'],
            'this_week'    => (int)$growth['week'],
            'this_month'   => (int)$growth['month'],
            'total'        => (int)$growth['total'],
            'churned_30d'  => (int)$churn['churned'],
            'net_growth'   => (int)$growth['month'] - (int)$churn['churned'],
            'monthly_trend' => $trend,
        ];
        
        $this->set('lte_subscriber_growth', $data);
        return $data;
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // DASHBOARD 3: USAGE DISTRIBUTION
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Refresh usage distribution (capacity planning)
     */
    public function refreshUsageDistribution(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                CASE 
                    WHEN bytes_used < 1073741824 THEN '0-1 GB'
                    WHEN bytes_used < 5368709120 THEN '1-5 GB'
                    WHEN bytes_used < 10737418240 THEN '5-10 GB'
                    WHEN bytes_used < 21474836480 THEN '10-20 GB'
                    ELSE '20+ GB'
                END AS bucket,
                COUNT(*) AS count
            FROM lte_subscriptions
            WHERE status = 'active'
            GROUP BY bucket
            ORDER BY 
                CASE bucket
                    WHEN '0-1 GB' THEN 1
                    WHEN '1-5 GB' THEN 2
                    WHEN '5-10 GB' THEN 3
                    WHEN '10-20 GB' THEN 4
                    ELSE 5
                END
        ");
        $buckets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert to associative for easier frontend use
        $data = [
            'buckets' => $buckets,
            'distribution' => [],
        ];
        
        foreach ($buckets as $b) {
            $data['distribution'][$b['bucket']] = (int)$b['count'];
        }
        
        $this->set('lte_usage_distribution', $data);
        return $data;
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // DASHBOARD 5: REVENUE (Extended)
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Refresh monthly revenue
     */
    public function refreshRevenueMonth(): array
    {
        // This month's revenue
        $stmt = $this->pdo->query("
            SELECT 
                COALESCE(SUM(amount_paid), 0) AS amount,
                COUNT(*) AS count
            FROM lte_renewals
            WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')
        ");
        $month = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Last month for comparison
        $stmt = $this->pdo->query("
            SELECT COALESCE(SUM(amount_paid), 0) AS amount
            FROM lte_renewals
            WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now', '-1 month')
        ");
        $lastMonth = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // ARPU (Average Revenue Per User)
        $activeCount = $this->getValue('lte_subscriber_counts', [])['active'] ?? 1;
        $arpu = $activeCount > 0 ? (float)$month['amount'] / $activeCount : 0;
        
        // Daily trend this month
        $stmt = $this->pdo->query("
            SELECT 
                DATE(created_at) AS date,
                SUM(amount_paid) AS revenue,
                COUNT(*) AS transactions
            FROM lte_renewals
            WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $dailyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [
            'amount'       => (float)$month['amount'],
            'count'        => (int)$month['count'],
            'last_month'   => (float)$lastMonth['amount'],
            'growth_pct'   => $lastMonth['amount'] > 0 
                ? round((($month['amount'] - $lastMonth['amount']) / $lastMonth['amount']) * 100, 1)
                : 0,
            'arpu'         => round($arpu, 2),
            'daily_trend'  => $dailyTrend,
        ];
        
        $this->set('lte_revenue_month', $data);
        return $data;
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // DASHBOARD 7: AGENT PERFORMANCE
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Refresh top agents
     */
    public function refreshTopAgents(): array
    {
        // Top agents today
        $stmt = $this->pdo->query("
            SELECT 
                agent_id,
                agent_name,
                SUM(amount_paid) AS revenue,
                COUNT(*) AS transactions
            FROM lte_renewals
            WHERE DATE(created_at) = DATE('now')
              AND agent_id IS NOT NULL
            GROUP BY agent_id, agent_name
            ORDER BY revenue DESC
            LIMIT 10
        ");
        $today = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top agents this month
        $stmt = $this->pdo->query("
            SELECT 
                agent_id,
                agent_name,
                SUM(amount_paid) AS revenue,
                COUNT(*) AS transactions
            FROM lte_renewals
            WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')
              AND agent_id IS NOT NULL
            GROUP BY agent_id, agent_name
            ORDER BY revenue DESC
            LIMIT 10
        ");
        $month = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [
            'today' => $today,
            'month' => $month,
        ];
        
        $this->set('lte_top_agents', $data);
        return $data;
    }
    
    /**
     * Refresh top packages
     */
    public function refreshTopPackages(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                package_name,
                COUNT(*) AS count,
                SUM(amount_paid) AS revenue
            FROM lte_renewals
            WHERE strftime('%Y-%m', created_at) = strftime('%Y-%m', 'now')
            GROUP BY package_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->set('lte_top_packages', $packages);
        return $packages;
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // SYSTEM HEALTH: QUEUE STATS
    // ═══════════════════════════════════════════════════════════════════════
    
    /**
     * Refresh queue statistics
     */
    public function refreshQueueStats(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                status,
                COUNT(*) AS count
            FROM job_queue
            WHERE job_type LIKE 'lte_%'
            GROUP BY status
        ");
        $byStatus = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Oldest pending job
        $stmt = $this->pdo->query("
            SELECT MIN(created_at) AS oldest
            FROM job_queue
            WHERE status = 'pending' AND job_type LIKE 'lte_%'
        ");
        $oldest = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Failed jobs in last 24h
        $stmt = $this->pdo->query("
            SELECT COUNT(*) AS failed
            FROM job_queue
            WHERE status = 'failed' 
              AND job_type LIKE 'lte_%'
              AND created_at >= datetime('now', '-24 hours')
        ");
        $failed = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $data = [
            'pending'      => (int)($byStatus['pending'] ?? 0),
            'completed'    => (int)($byStatus['completed'] ?? 0),
            'failed'       => (int)($byStatus['failed'] ?? 0),
            'failed_24h'   => (int)$failed['failed'],
            'oldest_pending' => $oldest['oldest'],
            'backlog_age_min' => $oldest['oldest'] 
                ? round((time() - strtotime($oldest['oldest'])) / 60, 1)
                : 0,
        ];
        
        $this->set('lte_queue_stats', $data);
        return $data;
    }
    
    /**
     * Refresh ALL dashboard caches (extended)
     */
    public function refreshAllLteCacheFull(): array
    {
        $results = [];
        
        // Core metrics
        $results['subscriber_counts']  = $this->refreshSubscriberCounts();
        $results['usage_summary']      = $this->refreshUsageSummary();
        $results['revenue_today']      = $this->refreshRevenueToday();
        $results['expiring_soon']      = $this->refreshExpiringSoon();
        $results['usage_alerts']       = $this->refreshUsageAlerts();
        
        // Extended metrics (7 CTO dashboards)
        $results['subscriber_growth']  = $this->refreshSubscriberGrowth();
        $results['usage_distribution'] = $this->refreshUsageDistribution();
        $results['revenue_month']      = $this->refreshRevenueMonth();
        $results['top_agents']         = $this->refreshTopAgents();
        $results['top_packages']       = $this->refreshTopPackages();
        $results['queue_stats']        = $this->refreshQueueStats();
        
        return $results;
    }
}
