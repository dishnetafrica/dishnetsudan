<?php
/**
 * LTE Worker: Daily Report
 * ═══════════════════════════════════════════════════════════════════════
 * Generates and sends daily summary report to admins.
 * 
 * Report includes:
 * - Revenue summary
 * - Subscriber changes
 * - Expiry status
 * - Usage alerts
 * - Agent performance
 * - System health
 * 
 * Run: php workers/lte/worker_report.php
 * Cron: 0 6 * * * (daily at 6 AM)
 */

require_once __DIR__ . '/../../bootstrap.php';

$startTime = microtime(true);
$workerName = 'lte_report';

try {
    // Initialize services
    $store = SqliteStore::create($dataDir);
    $pdo = $store->getPdo();
    
    $events = new LteEventService($pdo);
    $cache = new LteCacheService($pdo);
    
    // Load config
    $config = json_decode(file_get_contents($dataDir . '/kyc_config.json'), true) ?: [];
    
    // ═══════════════════════════════════════════════════════════════════════
    // DRY RUN MODE CHECK - Skip report generation in dry run mode
    // ═══════════════════════════════════════════════════════════════════════
    if (!empty($config['dry_run_mode'])) {
        echo "[DRY RUN] Worker {$workerName} skipped - dry_run_mode is enabled\n";
        exit(0);
    }
    
    // Log start
    $events->system('cron_started', ['worker' => $workerName]);
    
    echo "[" . date('Y-m-d H:i:s') . "] Generating daily report\n";
    
    // ═══════════════════════════════════════════════════════════════════════
    // 1. REFRESH CACHE TO GET LATEST DATA
    // ═══════════════════════════════════════════════════════════════════════
    
    $cache->refreshAllLteCacheFull();
    $data = $cache->getLteDashboardData();
    
    // ═══════════════════════════════════════════════════════════════════════
    // 2. YESTERDAY'S STATS
    // ═══════════════════════════════════════════════════════════════════════
    
    $stmt = $pdo->query("
        SELECT 
            COALESCE(SUM(amount_paid), 0) AS revenue,
            COUNT(*) AS transactions
        FROM lte_renewals
        WHERE DATE(created_at) = DATE('now', '-1 day')
    ");
    $yesterday = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT COUNT(*) AS new_subscribers
        FROM lte_subscribers
        WHERE DATE(created_at) = DATE('now', '-1 day')
    ");
    $newSubs = $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT COUNT(*) AS suspended
        FROM lte_network_events
        WHERE event_type = 'subscription_suspended'
          AND DATE(created_at) = DATE('now', '-1 day')
    ");
    $suspensions = $stmt->fetchColumn();
    
    // ═══════════════════════════════════════════════════════════════════════
    // 3. TOP AGENTS YESTERDAY
    // ═══════════════════════════════════════════════════════════════════════
    
    $stmt = $pdo->query("
        SELECT 
            agent_name,
            SUM(amount_paid) AS revenue,
            COUNT(*) AS transactions
        FROM lte_renewals
        WHERE DATE(created_at) = DATE('now', '-1 day')
          AND agent_id IS NOT NULL
        GROUP BY agent_id, agent_name
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $topAgents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ═══════════════════════════════════════════════════════════════════════
    // 4. CURRENT STATUS
    // ═══════════════════════════════════════════════════════════════════════
    
    $sc = $data['subscriber_counts'] ?? [];
    $es = $data['expiring_soon'] ?? [];
    $ua = $data['usage_alerts'] ?? [];
    $rm = $data['revenue_month'] ?? [];
    
    // ═══════════════════════════════════════════════════════════════════════
    // 5. BUILD REPORT MESSAGE
    // ═══════════════════════════════════════════════════════════════════════
    
    $reportDate = date('l, F j, Y');
    $yesterdayDate = date('F j', strtotime('-1 day'));
    
    $msg = "📊 *DishNet LTE Daily Report*\n";
    $msg .= "_{$reportDate}_\n\n";
    
    // Yesterday's Summary
    $msg .= "*Yesterday ({$yesterdayDate})*\n";
    $msg .= "💰 Revenue: \$" . number_format($yesterday['revenue'], 2) . " ({$yesterday['transactions']} transactions)\n";
    $msg .= "👤 New subscribers: {$newSubs}\n";
    $msg .= "🚫 Suspended: {$suspensions}\n\n";
    
    // Current Status
    $msg .= "*Current Status*\n";
    $msg .= "✅ Active: " . ($sc['active'] ?? 0) . " subscribers\n";
    $msg .= "⏸️ Suspended: " . ($sc['suspended'] ?? 0) . "\n";
    $msg .= "⚠️ Expiring today: " . ($es['today'] ?? 0) . "\n";
    $msg .= "📅 Expiring in 3 days: " . ($es['in_3_days'] ?? 0) . "\n\n";
    
    // Usage Alerts
    $criticalUsage = ($ua['critical'] ?? 0) + ($ua['exhausted'] ?? 0);
    if ($criticalUsage > 0) {
        $msg .= "*⚠️ Usage Alerts*\n";
        $msg .= "🔴 Critical (95%+): " . ($ua['critical'] ?? 0) . "\n";
        $msg .= "🚫 Exhausted: " . ($ua['exhausted'] ?? 0) . "\n\n";
    }
    
    // Month to Date
    $msg .= "*Month to Date*\n";
    $msg .= "💰 Revenue: \$" . number_format($rm['amount'] ?? 0, 2) . "\n";
    $msg .= "📈 Growth: " . ($rm['growth_pct'] ?? 0) . "% vs last month\n";
    $msg .= "💵 ARPU: \$" . number_format($rm['arpu'] ?? 0, 2) . "\n\n";
    
    // Top Agents
    if (!empty($topAgents)) {
        $msg .= "*🏆 Top Agents Yesterday*\n";
        $rank = 1;
        foreach ($topAgents as $agent) {
            $emoji = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : '  '));
            $msg .= "{$emoji} {$agent['agent_name']}: \$" . number_format($agent['revenue'], 2) . " ({$agent['transactions']} sales)\n";
            $rank++;
        }
        $msg .= "\n";
    }
    
    // Footer
    $msg .= "_DishNet Africa — Connecting South Sudan_";
    
    // ═══════════════════════════════════════════════════════════════════════
    // 6. SEND TO ADMINS
    // ═══════════════════════════════════════════════════════════════════════
    
    $adminPhones = $config['daily_report_phones'] ?? $config['admin_alert_phones'] ?? [];
    
    echo "  Report:\n";
    echo $msg . "\n\n";
    
    if (!empty($adminPhones)) {
        foreach ($adminPhones as $phone) {
            // Queue notification job
            $stmt = $pdo->prepare("
                INSERT INTO job_queue (job_type, payload, status, run_after, created_at)
                VALUES ('lte_notification', :payload, 'pending', datetime('now'), datetime('now'))
            ");
            $stmt->execute([':payload' => json_encode([
                'type' => 'daily_report',
                'phone' => $phone,
                'message' => $msg,
            ])]);
            echo "  Queued report for {$phone}\n";
        }
    } else {
        echo "  No admin phones configured for daily report\n";
    }
    
    // ═══════════════════════════════════════════════════════════════════════
    // 7. SAVE REPORT
    // ═══════════════════════════════════════════════════════════════════════
    
    $report = [
        'generated_at' => date('Y-m-d H:i:s'),
        'report_date' => date('Y-m-d'),
        'yesterday' => [
            'revenue' => (float)$yesterday['revenue'],
            'transactions' => (int)$yesterday['transactions'],
            'new_subscribers' => (int)$newSubs,
            'suspensions' => (int)$suspensions,
        ],
        'current' => [
            'active' => (int)($sc['active'] ?? 0),
            'suspended' => (int)($sc['suspended'] ?? 0),
            'expiring_today' => (int)($es['today'] ?? 0),
            'expiring_3_days' => (int)($es['in_3_days'] ?? 0),
        ],
        'month_to_date' => [
            'revenue' => (float)($rm['amount'] ?? 0),
            'growth_pct' => (float)($rm['growth_pct'] ?? 0),
            'arpu' => (float)($rm['arpu'] ?? 0),
        ],
        'top_agents' => $topAgents,
        'message' => $msg,
    ];
    
    // Save to daily reports folder
    $reportsDir = $dataDir . '/reports';
    if (!is_dir($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    
    $reportFile = $reportsDir . '/lte_daily_' . date('Y-m-d') . '.json';
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
    
    // ═══════════════════════════════════════════════════════════════════════
    // 8. LOG COMPLETION
    // ═══════════════════════════════════════════════════════════════════════
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    $events->system('cron_completed', [
        'worker' => $workerName,
        'duration_ms' => $duration,
        'report_date' => date('Y-m-d'),
        'recipients' => count($adminPhones),
    ]);
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Daily report generated in {$duration}ms\n";
    
} catch (Exception $e) {
    $events->error('Daily report failed', ['worker' => $workerName], $e);
    error_log("[LTE Report] Error: " . $e->getMessage());
    exit(1);
}
