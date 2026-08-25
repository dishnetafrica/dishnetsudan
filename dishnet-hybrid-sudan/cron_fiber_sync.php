#!/usr/bin/env php
<?php
/**
 * cron_fiber_sync.php — Background Splynx + CRM sync for Fiber Finance
 *
 * Called by cron/master.php every executionPeriod (5 min).
 * Actual sync runs based on configurable interval (default 60 min).
 *
 * Tasks:
 *  1. Splynx service + customer sync (if interval elapsed)
 *  2. CRM customer auto-mapping enrichment
 *  3. Monthly snapshot (1st of month)
 *  4. Missing invoice alert (10th of month)
 *  5. Low margin / leakage alerts
 */

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

chdir(__DIR__);

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/lib/SqliteStore.php';

if (!isset($dataDir)) {
    require_once __DIR__ . '/lib/bootstrap_data.php';
    $dataDir = getDataDir(__DIR__);
}
if (!isset($store))  $store  = SqliteStore::create($dataDir);
if (!isset($config)) $config = $store->load('kyc_config.json') ?? [];

// Single-instance lock
$lockFile = $dataDir . '/fiber_sync.lock';
$lockFp = @fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    return; // Another instance running
}

require_once __DIR__ . '/lib/FiberFinanceEngine.php';
require_once __DIR__ . '/lib/FiberPurchaseService.php';
require_once __DIR__ . '/lib/SplynxApiClient.php';

$splynx = SplynxApiClient::fromConfig($config);
if (!$splynx->isConfigured()) {
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return; // Splynx not configured — skip silently
}

$pdo    = $store->getPdo();
$ffEng  = new FiberFinanceEngine($pdo, $config);
$fpSvc  = new FiberPurchaseService($pdo, $config);

$now    = time();
$today  = date('Y-m-d');
$period = date('Y-m');
$dayOfMonth = (int)date('j');

// ── Task 1: Splynx Sync (respect interval) ──────────────────────────────
$syncInterval = max(5, (int)($config['fiber_sync_interval_minutes'] ?? $config['splynx_sync_interval_minutes'] ?? 60));
$lastSync     = $ffEng->getLastSync();
$lastSyncTs   = $lastSync ? strtotime($lastSync['completed_at'] ?? $lastSync['started_at'] ?? '2000-01-01') : 0;
$minutesSince = ($now - $lastSyncTs) / 60;

if ($minutesSince >= $syncInterval) {
    $result = $ffEng->runSync();
    if ($result['ok'] ?? false) {
        // Log success
        @file_put_contents($dataDir . '/fiber_cron.log',
            date('Y-m-d H:i:s') . " SYNC OK: {$result['services_total']} svcs, {$result['customers_mapped']} mapped\n",
            FILE_APPEND | LOCK_EX);
    } else {
        @file_put_contents($dataDir . '/fiber_cron.log',
            date('Y-m-d H:i:s') . " SYNC FAIL: " . ($result['error'] ?? 'unknown') . "\n",
            FILE_APPEND | LOCK_EX);
    }
}

// ── Task 2: Monthly snapshot (1st of month, once per period) ─────────────
if ($dayOfMonth === 1) {
    try {
        $fpSvc->takeSnapshot($period);
    } catch (\Throwable $e) {}
}

// ── Task 3: Missing invoice alert (10th of month) ────────────────────────
$alertDay = (int)($config['fiber_invoice_alert_day'] ?? 10);
if ($dayOfMonth === $alertDay) {
    try {
        $check = $fpSvc->checkMissingInvoice($period, $alertDay);
        if ($check['missing'] ?? false) {
            // Check if we already alerted today
            $alertKey = 'fiber_missing_alert_' . $period;
            $alerted = $store->load('fiber_cron_state.json') ?? [];
            if (($alerted[$alertKey] ?? '') !== $today) {
                require_once __DIR__ . '/lib/NotificationService.php';
                $notify = new NotificationService($store, $config);
                $notify->fiberMissingInvoice($period);
                $alerted[$alertKey] = $today;
                $store->save('fiber_cron_state.json', $alerted);
            }
        }
    } catch (\Throwable $e) {}
}

// ── Task 4: Leakage + low margin alerts (daily at sync time) ─────────────
if ($minutesSince >= $syncInterval) {
    try {
        $leakage = $ffEng->detectLeakage();
        $lowMargin = $ffEng->getLowMarginPlans((float)($config['fiber_margin_threshold'] ?? 10.0));

        $alertState = $store->load('fiber_cron_state.json') ?? [];

        // Leakage alert (once per day if count > 0)
        if ($leakage['count'] > 0 && ($alertState['leakage_alert_date'] ?? '') !== $today) {
            require_once __DIR__ . '/lib/NotificationService.php';
            $notify = new NotificationService($store, $config);
            $notify->sendAdmin(
                "🔍 *Fiber Leakage Alert*\n\n"
                . "{$leakage['count']} service(s) active in Splynx but NOT billing in CRM.\n"
                . "Monthly exposure: \$" . number_format($leakage['total_monthly_loss'], 2) . "\n\n"
                . "👉 Review in Plugin → Fiber Costs → Leakage",
                'fiber_leakage_alert'
            );
            $alertState['leakage_alert_date'] = $today;
            $store->save('fiber_cron_state.json', $alertState);
        }

        // Low margin alert (once per day)
        if (!empty($lowMargin) && ($alertState['margin_alert_date'] ?? '') !== $today) {
            $planNames = array_map(function($p) { return $p['plan'] . ' (' . $p['margin'] . '%)'; }, array_values($lowMargin));
            require_once __DIR__ . '/lib/NotificationService.php';
            $notify = new NotificationService($store, $config);
            $notify->sendAdmin(
                "⚠️ *Low Margin Fiber Plans*\n\n"
                . implode("\n", $planNames) . "\n\n"
                . "Below " . ($config['fiber_margin_threshold'] ?? 10) . "% threshold.\n"
                . "Review pricing in Plugin → Fiber Costs → Plan Costs",
                'fiber_low_margin_alert'
            );
            $alertState['margin_alert_date'] = $today;
            $store->save('fiber_cron_state.json', $alertState);
        }
    } catch (\Throwable $e) {}
}

// ── Rotate cron log (keep last 200 lines) ────────────────────────────────
$cronLog = $dataDir . '/fiber_cron.log';
if (file_exists($cronLog) && filesize($cronLog) > 50000) {
    $lines = file($cronLog);
    file_put_contents($cronLog, implode('', array_slice($lines, -100)), LOCK_EX);
}

flock($lockFp, LOCK_UN);
fclose($lockFp);
