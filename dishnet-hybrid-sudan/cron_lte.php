#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * DishNet LTE Automation Cron
 * ═══════════════════════════════════════════════════════════════════════
 * Runs every 5 minutes via crontab:
 *   * /5 * * * * php /path/to/cron_lte.php >> /tmp/lte_cron.log 2>&1
 *
 * Tasks performed each run:
 *   1. Auto-suspend   — subscribers expired past grace period → INACTIVE on Magma
 *   2. Auto-reactivate — suspended subs that have been renewed → ACTIVE on Magma
 *   3. Daily report   — if first run after midnight, generate + optionally send via WhatsApp
 *   4. Settlement snapshot — write daily agent settlement snapshot
 */

chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/MagmaApiClient.php';
require_once __DIR__ . '/lib/LteService.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/WalletService.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

$dataDir = getDataDir(__DIR__);
$store   = SqliteStore::create($dataDir); // SQLite — shares same plugin.sqlite3 as public.php
$config  = $store->load('kyc_config.json');
// B-08 FIX: MagmaApiClient constructor takes a single config array, not 4 positional args.
// Previous call would throw ArgumentCountError on PHP 8 strict mode.
$magma   = new MagmaApiClient([
    'magma_host'             => $config['magma_host']             ?? $config['magma_orchestrator_url'] ?? '',
    'magma_network_id'       => $config['magma_network_id']       ?? '',
    'magma_client_cert_path' => $config['magma_client_cert_path'] ?? $config['magma_cert_path'] ?? '',
    'magma_client_key_path'  => $config['magma_client_key_path']  ?? $config['magma_key_path']  ?? '',
    'magma_ca_cert_path'     => $config['magma_ca_cert_path']     ?? '',
]);
$lte     = new LteService($store, $magma, $store->getPdo());
$notify  = new NotificationService($store, $config);
$wallet  = new WalletService($store);

// ── Single-instance lock ────────────────────────────────────────────
$lockFile = $dataDir . '/cron_lte.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    return;
}

$graceDays  = (int)($config['lte_suspend_grace_days'] ?? 0); // 0 = suspend same day
$log        = [];
$suspended  = 0;
$reactivated= 0;
$errors     = [];

function clog(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// ══════════════════════════════════════════════════════════════════════
// TASK 1 — AUTO-SUSPEND expired subscribers
// ══════════════════════════════════════════════════════════════════════
clog('=== LTE Cron Start ===');
set_time_limit(300); // 5-minute safety guard against Magma API hangs

// Read subscribers and subscriptions from SQLite (populated by cron_lte_sync.php step 10b)
// Falls back to JSON if SQLite query fails (e.g. table not yet migrated)
$pdo = $store->getPdo();
$subs = [];
$allSubs = [];
try {
    $subs = $pdo->query("SELECT * FROM lte_subscribers WHERE deleted_at IS NULL")->fetchAll(\PDO::FETCH_ASSOC);
    $allSubs = $pdo->query("SELECT * FROM lte_subscriptions ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    clog("SQLite read failed ({$e->getMessage()}), falling back to JSON");
    $subs = $store->load('lte_subscribers.json') ?? [];
    $allSubs = $store->load('lte_subscriptions.json') ?? [];
}

// Build latest active subscription per subscriber
$latestSub = [];
foreach ($allSubs as $s) {
    $sid = (int)($s['subscriber_id'] ?? 0);
    if (!isset($latestSub[$sid]) || ($s['created_at']??'') > ($latestSub[$sid]['created_at']??'')) {
        $latestSub[$sid] = $s;
    }
}

$today = date('Y-m-d');
$graceCutoff = date('Y-m-d', strtotime("-{$graceDays} days"));

foreach ($subs as $sub) {
    $id     = (int)($sub['id'] ?? 0);
    $status = $sub['status'] ?? 'active';
    $imsi   = $sub['imsi']   ?? '';
    $ls     = $latestSub[$id] ?? null;

    if (!$ls) continue;

    $expiresAt  = $ls['expires_at'] ?? '';
    $subStatus  = $ls['status']     ?? '';

    // ── Suspend: active locally but expired past grace cutoff ──────
    if ($status === 'active' && $expiresAt && $expiresAt < $graceCutoff) {
        clog("SUSPEND #{$id} {$sub['name']} (expired {$expiresAt}, grace {$graceDays}d)");
        $ok = $lte->suspendSubscriber($id, 'auto_expired');
        if ($ok) {
            $suspended++;
            // Log to auto-suspend log
            $susLog = $store->load('lte_auto_suspend_log.json') ?? [];
            $susLog[] = [
                'subscriber_id' => $id,
                'name'          => $sub['name'],
                'phone'         => $sub['phone'] ?? '',
                'imsi'          => $imsi,
                'expires_at'    => $expiresAt,
                'suspended_at'  => date('Y-m-d H:i:s'),
                'grace_days'    => $graceDays,
                'magma_synced'  => !empty($imsi),
            ];
            $store->save('lte_auto_suspend_log.json', $susLog);

            // WhatsApp notification to subscriber
            if (!empty($sub['phone']) && !empty($config['whatsapp_webhook_url'])) {
                $pkgName = $ls['package_name'] ?? '';
                $notify->sendRaw($sub['phone'],
                    "Hello {$sub['name']}! 🚫\n\nYour DishNet LTE plan *{$pkgName}* has expired and your service is now *suspended*.\n\nPlease contact your agent or visit a DishNet office to renew.\n\n_DishNet Africa_",
                    'lte_suspended');
            }
        } else {
            $errors[] = "Failed to suspend #{$id} {$sub['name']}";
            clog("  ERROR: suspend failed for #{$id}");
        }
    }

    // ── Reactivate: suspended but has a valid active subscription ──
    if ($status === 'suspended' && $subStatus === 'active' && $expiresAt >= $today) {
        clog("REACTIVATE #{$id} {$sub['name']} (valid until {$expiresAt})");
        $ok = $lte->reactivateSubscriber($id);
        if ($ok) {
            $reactivated++;
            $store->updateOne('lte_subscribers.json', 'id', $id, [
                'status'     => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $reacLog = $store->load('lte_auto_reactivate_log.json') ?? [];
            $reacLog[] = [
                'subscriber_id' => $id,
                'name'          => $sub['name'],
                'imsi'          => $imsi,
                'expires_at'    => $expiresAt,
                'reactivated_at'=> date('Y-m-d H:i:s'),
                'magma_synced'  => !empty($imsi),
            ];
            $store->save('lte_auto_reactivate_log.json', $reacLog);
        } else {
            $errors[] = "Failed to reactivate #{$id} {$sub['name']}";
            clog("  ERROR: reactivate failed for #{$id}");
        }
    }
}

clog("Suspended: {$suspended} | Reactivated: {$reactivated} | Errors: " . count($errors));

// ══════════════════════════════════════════════════════════════════════
// TASK 2 — DAILY OPS REPORT (once per day, after midnight)
// ══════════════════════════════════════════════════════════════════════
$lastReportDate = trim($store->load('lte_last_report_date.json')[0] ?? '');
$todayStr = date('Y-m-d');

if ($lastReportDate !== $todayStr && date('H') >= 1) { // run after 1am
    clog("Generating daily report for {$todayStr}...");

    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // LTE renewals yesterday
    $allRenewals = $store->load('lte_renewals.json') ?? [];
    $ydRenewals  = array_values(array_filter($allRenewals,
        fn($r) => substr($r['created_at']??'',0,10) === $yesterday));
    $lteRevYd    = array_sum(array_map(fn($r)=>(float)($r['amount_paid']??0), $ydRenewals));

    // New LTE subscribers yesterday
    $newSubs = array_values(array_filter($subs,
        fn($s) => substr($s['created_at']??'',0,10) === $yesterday));

    // LTE expiry status counts
    $expiredCnt  = count(array_filter($subs, fn($s)=>($s['status']??'')==='active' && isset($latestSub[(int)$s['id']]) && ($latestSub[(int)$s['id']]['expires_at']??'') < $today));
    $activeCnt   = count(array_filter($subs, fn($s)=>($s['status']??'')==='active'));
    $suspendedCt = count(array_filter($subs, fn($s)=>($s['status']??'')==='suspended'));

    // Collections yesterday
    $colls    = $store->load('payment_collections.json') ?? [];
    $ydColls  = array_filter($colls, fn($c)=>substr($c['created_at']??'',0,10)===$yesterday);
    $collsRev = array_sum(array_map(fn($c)=>(float)($c['amount']??0), $ydColls));
    $collsCnt = count($ydColls);

    // KYC applications yesterday
    $apps    = $store->load('kyc_applications.json') ?? [];
    $ydApps  = array_filter($apps, fn($a)=>substr($a['created_at']??'',0,10)===$yesterday);
    $appsCnt = count($ydApps);

    // Top agent by LTE renewals yesterday
    $agentRev = [];
    foreach ($ydRenewals as $r) {
        $an = $r['agent_name']??'Unknown';
        $agentRev[$an] = ($agentRev[$an]??0) + (float)($r['amount_paid']??0);
    }
    arsort($agentRev);
    $topAgent    = array_key_first($agentRev) ?? '—';
    $topAgentRev = $agentRev[$topAgent] ?? 0;

    $totalRev = $lteRevYd + $collsRev;

    // Build report
    $report = [
        'date'          => $yesterday,
        'generated_at'  => date('Y-m-d H:i:s'),
        'lte'           => [
            'renewals'      => count($ydRenewals),
            'revenue'       => round($lteRevYd, 2),
            'new_subs'      => count($newSubs),
            'active'        => $activeCnt,
            'suspended'     => $suspendedCt,
            'auto_suspended'=> $suspended,
        ],
        'collections'   => [
            'count'         => $collsCnt,
            'revenue'       => round($collsRev, 2),
        ],
        'kyc'           => ['applications' => $appsCnt],
        'top_agent'     => ['name'=>$topAgent,'revenue'=>round($topAgentRev,2)],
        'total_revenue' => round($totalRev, 2),
        'errors'        => $errors,
    ];

    // Save report to archive
    $reports = $store->load('lte_daily_reports.json') ?? [];
    $reports[] = $report;
    // Keep last 90 reports
    if (count($reports) > 90) $reports = array_slice($reports, -90);
    $store->save('lte_daily_reports.json', $reports);

    // Mark date done
    $store->save('lte_last_report_date.json', [$todayStr]);

    // Send report via WhatsApp to admin
    if (!empty($config['whatsapp_webhook_url']) && !empty($config['whatsapp_admin_phone'])) {
        $msg = "📊 *DishNet Daily Report — {$yesterday}*\n\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "📶 *DishNet 4G*\n"
            . "  • Renewals: *{$report['lte']['renewals']}* (+\${$report['lte']['revenue']})\n"
            . "  • New Subscribers: *{$report['lte']['new_subs']}*\n"
            . "  • Active: {$report['lte']['active']} | Suspended: {$report['lte']['suspended']}\n"
            . ($suspended > 0 ? "  • ⚠ Auto-suspended today: *{$suspended}*\n" : '')
            . "\n💵 *Collections (Starlink/Fiber)*\n"
            . "  • {$report['collections']['count']} transactions — \${$report['collections']['revenue']}\n"
            . "\n📋 *KYC Applications*: {$report['kyc']['applications']}\n"
            . "\n🏆 *Top Agent*: {$topAgent} (\${$topAgentRev})\n"
            . "\n💰 *Total Revenue: \$" . number_format($totalRev, 2) . "*\n"
            . "━━━━━━━━━━━━━━━━━━━━\n"
            . "_DishNet Africa · Operations Hub_";

        $notify->sendAdmin($msg, 'daily_report', [
            'total_revenue' => number_format($totalRev, 2),
        ]);
    }

    clog("Daily report saved. Total revenue: \${$totalRev}");
} else {
    clog("Daily report already generated for {$todayStr}, skipping.");
}

// ══════════════════════════════════════════════════════════════════════
// TASK 3 — SETTLEMENT SNAPSHOT (daily per-agent net-to-dishnet)
// ══════════════════════════════════════════════════════════════════════
$lastSettleDate = trim($store->load('lte_last_settle_date.json')[0] ?? '');

if ($lastSettleDate !== $todayStr && date('H') >= 2) {
    clog("Generating settlement snapshot for {$todayStr}...");

    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $rates = [
        'starlink' => (float)($config['starlink_commission_rate'] ?? $config['commission_rate'] ?? 5),
        'fiber'    => (float)($config['fiber_commission_rate']    ?? $config['commission_rate'] ?? 5),
        'lte'      => (float)($config['lte_commission_rate']      ?? $config['commission_rate'] ?? 5),
    ];

    $agents = [];

    // Collections
    foreach ($store->load('payment_collections.json') as $c) {
        if (substr($c['created_at']??'',0,10) !== $yesterday) continue;
        $aid  = (int)($c['retailer_id']??0);
        $aname= $c['retailer_name']??'Agent #'.$aid;
        if (!isset($agents[$aid])) $agents[$aid] = ['id'=>$aid,'name'=>$aname,'collected'=>0.0,'commission'=>0.0,'net'=>0.0,'transactions'=>0];
        $amt  = (float)($c['amount']??0);
        $svc  = strtolower($c['service_type']??'starlink');
        if (!in_array($svc,['starlink','fiber'])) $svc='starlink';
        $comm = (float)($c['commission']??0) ?: round($amt*$rates[$svc]/100,2);
        $agents[$aid]['collected']    += $amt;
        $agents[$aid]['commission']   += $comm;
        $agents[$aid]['transactions']++;
    }

    // LTE renewals
    foreach ($store->load('lte_renewals.json') as $r) {
        if (substr($r['created_at']??'',0,10) !== $yesterday) continue;
        $aid  = (int)($r['agent_id']??0);
        $aname= $r['agent_name']??'Agent #'.$aid;
        if (!isset($agents[$aid])) $agents[$aid] = ['id'=>$aid,'name'=>$aname,'collected'=>0.0,'commission'=>0.0,'net'=>0.0,'transactions'=>0];
        $amt  = (float)($r['amount_paid']??0);
        $comm = round($amt*$rates['lte']/100,2);
        $agents[$aid]['collected']    += $amt;
        $agents[$aid]['commission']   += $comm;
        $agents[$aid]['transactions']++;
    }

    foreach ($agents as &$ag) {
        $ag['net']        = round($ag['collected'] - $ag['commission'], 2);
        $ag['collected']  = round($ag['collected'],  2);
        $ag['commission'] = round($ag['commission'], 2);
    }
    unset($ag);

    $snapshot = [
        'date'       => $yesterday,
        'created_at' => date('Y-m-d H:i:s'),
        'rates'      => $rates,
        'agents'     => array_values($agents),
        'totals'     => [
            'collected'  => round(array_sum(array_column($agents,'collected')), 2),
            'commission' => round(array_sum(array_column($agents,'commission')),2),
            'net'        => round(array_sum(array_column($agents,'net')), 2),
        ],
        'settled'    => false,
    ];

    $snapshots = $store->load('lte_settlement_snapshots.json') ?? [];
    // avoid duplicates
    $snapshots = array_values(array_filter($snapshots, fn($s)=>($s['date']??'')!==$yesterday));
    $snapshots[] = $snapshot;
    $store->save('lte_settlement_snapshots.json', $snapshots);
    $store->save('lte_last_settle_date.json', [$todayStr]);

    clog("Settlement snapshot saved. Net to DishNet: \${$snapshot['totals']['net']}");
}

// ══════════════════════════════════════════════════════════════════════
// DONE
// ══════════════════════════════════════════════════════════════════════
flock($lockFp, LOCK_UN);
fclose($lockFp);
clog("=== LTE Cron Done | Suspended:{$suspended} Reactivated:{$reactivated} Errors:" . count($errors) . " ===");
