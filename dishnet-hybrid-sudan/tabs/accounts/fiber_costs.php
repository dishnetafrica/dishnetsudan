<?php
// Tab: fiber_costs — Fiber Finance + Purchasing Reconciliation
// Sub-tabs: Dashboard | Finance | Invoices | Reconcile | Leakage | Services | Customers | Plan Costs

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

require_once dirname(__DIR__, 2) . '/lib/FiberPurchaseService.php';
require_once dirname(__DIR__, 2) . '/lib/FiberFinanceEngine.php';
require_once dirname(__DIR__, 2) . '/lib/SplynxApiClient.php';

$fpSvc  = new FiberPurchaseService($store->getPdo(), $config);
$ffEng  = new FiberFinanceEngine($store->getPdo(), $config);
$fcSub  = $_GET['fcsub'] ?? 'dashboard';

// ── POST handlers ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fcAct = $_POST['fc_action'] ?? '';
    $me    = $retailer['name'] ?? 'Admin';

    if ($fcAct === 'create_invoice') {
        $lineItems = [];
        $liPlans = $_POST['li_plan'] ?? [];
        $liQtys  = $_POST['li_qty']  ?? [];
        $liCosts = $_POST['li_cost'] ?? [];
        for ($i = 0; $i < count($liPlans); $i++) {
            $p = trim($liPlans[$i] ?? '');
            if ($p === '') continue;
            $lineItems[] = [
                'plan'      => $p,
                'qty'       => (int)($liQtys[$i] ?? 0),
                'unit_cost' => (float)($liCosts[$i] ?? 0),
                'total'     => round((int)($liQtys[$i] ?? 0) * (float)($liCosts[$i] ?? 0), 2),
            ];
        }
        $id = $fpSvc->createInvoice([
            'supplier'       => $_POST['supplier'] ?? '',
            'invoice_number' => $_POST['invoice_number'] ?? '',
            'invoice_date'   => $_POST['invoice_date'] ?? date('Y-m-d'),
            'billing_period' => $_POST['billing_period'] ?? date('Y-m'),
            'total_amount'   => $_POST['total_amount'] ?? 0,
            'currency'       => $_POST['currency'] ?? 'USD',
            'line_items'     => !empty($lineItems) ? $lineItems : null,
            'notes'          => $_POST['notes'] ?? '',
            'created_by'     => $me,
        ]);
        // WA notification: invoice recorded + variance alert if > 5%
        try {
            $ns  = svc('notify');
            $inv = $fpSvc->getInvoice($id);
            $ns->fiberInvoiceRecorded($inv['supplier'], $inv['invoice_number'],
                (float)$inv['total_amount'], $inv['billing_period'], $me);
            if ($inv['variance_pct'] !== null && abs((float)$inv['variance_pct']) > 5) {
                $ns->fiberVarianceAlert($inv['supplier'], $inv['invoice_number'],
                    (float)$inv['expected_amount'], (float)$inv['total_amount'],
                    (float)$inv['variance'], (float)$inv['variance_pct']);
            }
        } catch (\Throwable $e) {}
        flash("Invoice #{$id} recorded.", 'success');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=invoices');
    }

    if ($fcAct === 'verify_invoice') {
        $fpSvc->verifyInvoice((int)($_POST['inv_id'] ?? 0), $me);
        flash('Invoice verified.', 'success');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=invoices');
    }
    if ($fcAct === 'approve_invoice') {
        $fpSvc->approveInvoice((int)($_POST['inv_id'] ?? 0), $me);
        flash('Invoice approved.', 'success');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=invoices');
    }
    if ($fcAct === 'pay_invoice') {
        $fpSvc->markPaid((int)($_POST['inv_id'] ?? 0), trim($_POST['payment_ref'] ?? 'BANK-' . date('Ymd')));
        flash('Invoice marked as paid.', 'success');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=invoices');
    }
    if ($fcAct === 'post_to_cashbook') {
        $invId = (int)($_POST['inv_id'] ?? 0);
        require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
        $cbSvc  = new CashbookService($store, method_exists($store, 'getDataDir') ? $store->getDataDir() : dirname(__DIR__, 2) . '/data');
        $result = $fpSvc->postToCashbook($invId, $cbSvc, $me);
        if ($result['ok']) {
            // WA notification
            try {
                $ns = svc('notify');
                $inv = $fpSvc->getInvoice($invId);
                $ns->fiberInvoicePosted($inv['supplier'], $inv['invoice_number'],
                    (float)$inv['total_amount'], $inv['billing_period']);
            } catch (\Throwable $e) {}
            flash('✅ Invoice posted to cashbook (Entry #' . $result['cb_entry_id'] . ')', 'success');
        } else {
            flash('❌ ' . ($result['error'] ?? 'Post failed'), 'danger');
        }
        redirect('?page=dashboard&tab=fiber_costs&fcsub=invoices');
    }
    if ($fcAct === 'delete_invoice') {
        $fpSvc->deleteInvoice((int)($_POST['inv_id'] ?? 0));
        flash('Invoice deleted.', 'info');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=invoices');
    }
    if ($fcAct === 'save_plan_cost') {
        $pcId = $fpSvc->recordPlanCost(
            $_POST['pc_supplier'] ?? '', $_POST['pc_plan'] ?? '',
            (float)($_POST['pc_cost'] ?? 0), $_POST['pc_from'] ?? date('Y-m-d')
        );
        // Also save revenue + partner fields on the new record
        $rev = (float)($_POST['pc_revenue'] ?? 0);
        $ps  = (float)($_POST['pc_partner'] ?? 0);
        $pm  = in_array($_POST['pc_profit_mode'] ?? '', ['fixed','revenue_share','profit_share']) ? $_POST['pc_profit_mode'] : 'fixed';
        $crn = trim($_POST['pc_crm_name'] ?? '');
        if ($pcId) {
            try {
                $store->getPdo()->prepare("UPDATE fiber_plan_costs SET revenue=?, partner_share=?, profit_mode=?, crm_plan_name=? WHERE id=?")
                    ->execute([$rev, $ps, $pm, $crn, $pcId]);
            } catch (\Throwable $e) {}
        }
        flash('Plan cost updated.', 'success');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=plan_costs');
    }
    if ($fcAct === 'run_sync') {
        $result = $ffEng->runSync();
        if ($result['ok'] ?? false) {
            flash("Sync complete: {$result['services_total']} services, {$result['customers_mapped']} mapped", 'success');
        } else {
            flash('Sync failed: ' . ($result['error'] ?? 'Unknown'), 'danger');
        }
        redirect('?page=dashboard&tab=fiber_costs&fcsub=dashboard');
    }
    if ($fcAct === 'manual_map') {
        $ok = $ffEng->manualMapCustomer($_POST['splynx_id'] ?? '', $_POST['crm_id'] ?? '', $_POST['crm_name'] ?? '');
        flash($ok ? 'Customer mapped.' : 'Map failed.', $ok ? 'success' : 'danger');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=customers');
    }
    if ($fcAct === 'override_status') {
        $ok = $ffEng->overrideServiceStatus($_POST['svc_id'] ?? '', $_POST['new_status'] ?? '', $_POST['reason'] ?? '');
        flash($ok ? 'Status overridden.' : 'Override failed.', $ok ? 'success' : 'danger');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=services');
    }
    if ($fcAct === 'save_dispute_flag') {
        $fpSvc->saveComparisonFlag(
            $_POST['cmp_period'] ?? '', $_POST['cmp_svc_id'] ?? '',
            ['disputed' => (int)($_POST['cmp_disputed'] ?? 0), 'dispute_reason' => $_POST['cmp_reason'] ?? '',
             'dead_install' => (int)($_POST['cmp_dead'] ?? 0), 'acct_remarks' => $_POST['cmp_remarks'] ?? '',
             'flagged_by' => $me]
        );
        flash('Flag saved.', 'success');
        redirect('?page=dashboard&tab=fiber_costs&fcsub=compare&cmp_period=' . urlencode($_POST['cmp_period'] ?? '') . '&cmp_amount=' . urlencode($_POST['cmp_amount_keep'] ?? ''));
    }
    if ($fcAct === 'import_fiber_data') {
        require_once dirname(__DIR__, 2) . '/lib/fiber_data_import.php';
        $_pluginRoot = $GLOBALS['_PLUGIN_ROOT'] ?? dirname(__DIR__, 2);
        $_dataDir = method_exists($store, 'getDataDir') ? $store->getDataDir() : $_pluginRoot . '/data';
        // Derive sibling plugin data dir from our data dir path
        $_parentPlugins = dirname($_dataDir);  // e.g. /data/ucrm/data/plugins/dishnet-hybrid-telecom
        $_fiberPluginData = dirname($_parentPlugins) . '/dishnet-fiber-finance/data';
        $sourceDir = '';
        foreach ([
            $_dataDir . '/fiber_backup',                   // Our own data dir backup
            $_fiberPluginData,                             // Sibling Fiber Finance plugin (live data!)
            $_pluginRoot . '/data/fiber_backup',           // ZIP extraction: plugin_root/data/
            dirname($_dataDir) . '/data/fiber_backup',     // parent of data dir + /data/
            $_pluginRoot . '/fiber_backup',                // ZIP extraction: plugin_root/
        ] as $_tryDir) {
            if (is_dir($_tryDir) && count(glob($_tryDir . '/fiber_*.json')) > 0) {
                $sourceDir = $_tryDir;
                break;
            }
        }
        if (!$sourceDir) {
            flash("Backup not found. Searched: {$_dataDir}/fiber_backup/, {$_fiberPluginData}/ — Is the Fiber Finance plugin still installed?", 'danger');
        } else {
            $result = importFiberData($store->getPdo(), $sourceDir);
            $s = $result['stats'] ?? [];
            flash("Import done: {$s['services']} services, {$s['customers']} customers, {$s['plans']} plans, {$s['invoices']} invoices, {$s['status_log']} status changes. Errors: {$s['errors']}", 'success');
        }
        redirect('?page=dashboard&tab=fiber_costs&fcsub=dashboard');
    }
}

// ── Data — lazy load per sub-tab (avoid Splynx API calls on every page load) ──
// Only $stats is needed globally (for invoice badge count in tab bar)
$stats    = $fpSvc->getDashboardStatsLight(); // Light version — no Splynx API calls
$lastSync = $ffEng->getLastSync();

// Leakage count for badge (cheap SQLite query)
$_leakageBadge = 0;
try { $_leakageBadge = (int)$store->getPdo()->query("SELECT COUNT(*) FROM fiber_services_cache WHERE splynx_status = 'active' AND (crm_status IS NULL OR crm_status != 'Active')")->fetchColumn(); } catch (\Throwable $_e) {}

function fc_fmt(float $v, string $cur = 'USD'): string {
    return ($cur === 'SSP' ? '' : '$') . number_format($v, 2);
}
function fc_status_badge(string $s): string {
    $colors = ['received'=>'#f59e0b','verified'=>'#3b82f6','approved'=>'#8b5cf6','paid'=>'#22c55e','posted'=>'#64748b'];
    $bg = $colors[$s] ?? '#64748b';
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;background:' . $bg . '22;color:' . $bg . ';">' . htmlspecialchars($s) . '</span>';
}
?>
<style>
.fc-tabs{display:flex;gap:0;margin-bottom:16px;border-bottom:2px solid #334155}
.fc-tab{padding:10px 20px;font-size:13px;font-weight:600;color:#94a3b8;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s}
.fc-tab:hover{color:#e2e8f0;background:#1e293b44}.fc-tab.active{color:#3b82f6;border-bottom-color:#3b82f6}
.fc-tab .fc-badge{display:inline-block;background:#ef4444;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:8px;margin-left:4px}
.fc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px}
.fc-card{background:#1e293b;border:1px solid #334155;border-radius:10px;padding:16px;text-align:center}
.fc-card-num{font-size:24px;font-weight:800}.fc-card-label{font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.fc-table{width:100%;border-collapse:collapse;font-size:13px}
.fc-table th{background:#1e293b;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:8px 10px;text-align:left;border-bottom:1px solid #334155;position:sticky;top:0}
.fc-table td{padding:8px 10px;border-bottom:1px solid #1e293b;vertical-align:top}
.fc-table tr:hover td{background:#1e293b44}
.fc-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.fc-form-group{margin-bottom:12px}.fc-form-group label{display:block;font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:4px}
.fc-form-group input,.fc-form-group select,.fc-form-group textarea{width:100%;padding:8px 10px;border:1px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:13px}
.fc-btn{padding:8px 18px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:600;color:#fff}
.fc-btn-primary{background:#3b82f6}.fc-btn-primary:hover{background:#2563eb}
.fc-btn-green{background:#22c55e}.fc-btn-green:hover{background:#16a34a}
.fc-btn-red{background:#ef4444}.fc-btn-red:hover{background:#dc2626}
.fc-btn-sm{padding:4px 10px;font-size:11px}
.fc-var-pos{color:#ef4444;font-weight:700}.fc-var-neg{color:#22c55e;font-weight:700}.fc-var-zero{color:#94a3b8}
.fc-empty{text-align:center;padding:40px;color:#64748b}
</style>

<!-- Sub-tab bar -->
<div class="fc-tabs" style="overflow-x:auto;white-space:nowrap;">
    <a href="?page=dashboard&tab=fiber_costs&fcsub=dashboard" class="fc-tab <?=$fcSub==='dashboard'?'active':''?>">📊 Dashboard</a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=finance" class="fc-tab <?=$fcSub==='finance'?'active':''?>">📈 Finance</a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=invoices" class="fc-tab <?=$fcSub==='invoices'?'active':''?>">
        🧾 Invoices
        <?php $pend = ($stats['status_counts']['received']??0)+($stats['status_counts']['verified']??0); if($pend>0):?><span class="fc-badge"><?=$pend?></span><?php endif;?>
    </a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=reconcile" class="fc-tab <?=$fcSub==='reconcile'?'active':''?>">🔁 Reconcile</a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=prediction" class="fc-tab <?=$fcSub==='prediction'?'active':''?>">🔮 Prediction</a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=compare" class="fc-tab <?=$fcSub==='compare'?'active':''?>">⚖️ Compare</a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=leakage" class="fc-tab <?=$fcSub==='leakage'?'active':''?>">
        🔍 Leakage
        <?php if($_leakageBadge>0):?><span class="fc-badge"><?=$_leakageBadge?></span><?php endif;?>
    </a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=services" class="fc-tab <?=$fcSub==='services'?'active':''?>">🌐 Services</a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=customers" class="fc-tab <?=$fcSub==='customers'?'active':''?>">👥 Customers</a>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=plan_costs" class="fc-tab <?=$fcSub==='plan_costs'?'active':''?>">💰 Plan Costs</a>
</div>


<?php if ($fcSub === 'dashboard'): ?>
<!-- ═══════════════════ DASHBOARD ═══════════════════ -->
<?php
// Single-pass KPI cache — reads services ONCE, computes P&L + leakage + plan stats together
$kpi      = $ffEng->buildKpiCache();
$pnl      = $kpi; // buildKpiCache includes all calculatePnL fields
$leakage  = ['count' => $kpi['leakage_count'], 'total_monthly_loss' => $kpi['leakage_exposure']];
$stats    = $fpSvc->getDashboardStats(); // Uses local cache now (no Splynx API)
?>
<div class="fc-cards">
    <div class="fc-card"><div class="fc-card-num" style="color:#60a5fa;"><?=fc_fmt($stats['expected_cost'])?></div><div class="fc-card-label">Expected This Month</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#f59e0b;"><?=$pnl['active']?></div><div class="fc-card-label">Active Services</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#ef4444;"><?=fc_fmt($stats['total_paid_ytd'])?></div><div class="fc-card-label">Paid YTD</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#22c55e;"><?=$stats['pending_count']?></div><div class="fc-card-label">Pending Invoices</div></div>
</div>

<!-- P&L Summary Row -->
<?php if ($pnl['active'] > 0): ?>
<div class="fc-cards" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));">
    <div class="fc-card"><div class="fc-card-num" style="color:#3b82f6;font-size:20px;"><?=fc_fmt($pnl['total_revenue'])?></div><div class="fc-card-label">Revenue/mo</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#ef4444;font-size:20px;"><?=fc_fmt($pnl['total_cost'])?></div><div class="fc-card-label">Cost/mo</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:<?=$pnl['total_profit']>=0?'#22c55e':'#ef4444'?>;font-size:20px;"><?=fc_fmt($pnl['total_profit'])?></div><div class="fc-card-label">Net Profit</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#8b5cf6;font-size:20px;"><?=$pnl['overall_margin']?>%</div><div class="fc-card-label">Margin</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#f59e0b;font-size:20px;"><?=fc_fmt($pnl['revenue_at_risk'])?></div><div class="fc-card-label">Revenue at Risk</div></div>
    <?php if ($leakage['count'] > 0): ?>
    <div class="fc-card" style="border-color:#ef444466;"><div class="fc-card-num" style="color:#ef4444;font-size:20px;"><?=$leakage['count']?></div><div class="fc-card-label">Leakage Services</div>
        <div style="font-size:10px;color:#f87171;"><?=fc_fmt($leakage['total_monthly_loss'])?>/mo loss</div></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Sync + Actions -->
<?php
    $syncHealth = $ffEng->getSyncHealth();
    $healthIcon = $syncHealth['health'] === 'healthy' ? '🟢' : ($syncHealth['health'] === 'stale' ? '🟡' : ($syncHealth['health'] === 'critical' ? '🔴' : '⚪'));

    // Search for backup in multiple locations
    $_pluginRoot = $GLOBALS['_PLUGIN_ROOT'] ?? dirname(__DIR__, 2);
    $_dataDir = method_exists($store, 'getDataDir') ? $store->getDataDir() : $_pluginRoot . '/data';
    $_fiberPluginData = dirname(dirname($_dataDir)) . '/dishnet-fiber-finance/data';
    $backupDir = '';
    foreach ([
        $_dataDir . '/fiber_backup',
        $_fiberPluginData,
        $_pluginRoot . '/data/fiber_backup',
        dirname($_dataDir) . '/data/fiber_backup',
        $_pluginRoot . '/fiber_backup',
    ] as $_tryDir) {
        if (is_dir($_tryDir) && count(glob($_tryDir . '/fiber_*.json')) > 0) {
            $backupDir = $_tryDir;
            break;
        }
    }
    $hasBackup = $backupDir !== '';
    $hasServices = false;
    try { $hasServices = (int)$store->getPdo()->query("SELECT COUNT(*) FROM fiber_services_cache")->fetchColumn() > 0; } catch (\Throwable $_e) {}
?>
<div style="display:flex;gap:10px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">
    <form method="post" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Syncing...';">
        <?= csrfField() ?><input type="hidden" name="fc_action" value="run_sync">
        <button type="submit" class="fc-btn fc-btn-primary">🔄 Sync from Splynx</button>
    </form>
    <?php if (!$hasServices): ?>
    <?php if ($hasBackup): ?>
    <form method="post" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing...';return confirm('Import production data from Fiber Finance backup? This will populate all tables.');">
        <?= csrfField() ?><input type="hidden" name="fc_action" value="import_fiber_data">
        <button type="submit" class="fc-btn" style="background:#8b5cf6;">📦 Import Fiber Data</button>
    </form>
    <?php else: ?>
    <form method="post" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing...';">
        <?= csrfField() ?><input type="hidden" name="fc_action" value="import_fiber_data">
        <button type="submit" class="fc-btn" style="background:#8b5cf6;">📦 Import Fiber Data</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($lastSync): ?>
    <span style="font-size:12px;color:#64748b;"><?=$healthIcon?> Last sync: <?=htmlspecialchars(substr($lastSync['completed_at'] ?? $lastSync['started_at'] ?? '', 0, 16))?> · <?=$lastSync['services_total']?> services
        <?php if ($syncHealth['age_hours'] !== null): ?>(<?=number_format($syncHealth['age_hours'], 1)?>h ago)<?php endif; ?>
    </span>
    <?php elseif ($hasServices): ?>
    <span style="font-size:12px;color:#64748b;">⚪ Data imported — run sync to refresh from Splynx</span>
    <?php else: ?>
    <span style="font-size:12px;color:#f59e0b;">⚠️ No data yet — sync from Splynx or import backup</span>
    <?php endif; ?>
</div>

<?php
// ── Alert banners ────────────────────────────────────────────────────
$missingCheck = $fpSvc->checkMissingInvoice();
$priceChanges = $fpSvc->detectPriceChanges();
?>

<?php if ($missingCheck['missing']): ?>
<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:12px;margin-bottom:12px;color:#92400e;font-size:13px;">
    ⏰ <strong>Missing Invoice:</strong> No supplier invoice has been recorded for <strong><?=htmlspecialchars($missingCheck['billing_period'])?></strong> and it's day <?=date('j')?> of the month.
    <a href="?page=dashboard&tab=fiber_costs&fcsub=invoices" style="color:#2563eb;margin-left:8px;">Record invoice →</a>
</div>
<?php endif; ?>

<?php if (!empty($priceChanges['changes'])): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px;margin-bottom:12px;color:#991b1b;font-size:13px;">
    📈 <strong>Price Changes Detected</strong> (<?=htmlspecialchars($priceChanges['current_period'])?> vs <?=htmlspecialchars($priceChanges['previous_period'])?>):
    <?php foreach(array_slice($priceChanges['changes'], 0, 3) as $ch): ?>
    <div style="margin-top:4px;padding-left:20px;">
        <?=$ch['change']>0?'🔴':'🟢'?> <strong><?=htmlspecialchars($ch['plan'])?></strong>:
        $<?=number_format($ch['old_cost'],2)?> → $<?=number_format($ch['new_cost'],2)?>
        (<?=$ch['change_pct']>0?'+':''?><?=number_format($ch['change_pct'],1)?>%)
    </div>
    <?php endforeach; ?>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=plan_costs" style="color:#2563eb;margin-left:20px;font-size:12px;">View Plan Costs →</a>
</div>
<?php endif; ?>

<?php
// ── 6-Month Trend Chart (CSS bars) ──────────────────────────────────
$trend = $fpSvc->getTrend(6);
$trendMax = 1;
foreach ($trend as $t) {
    $trendMax = max($trendMax, $t['actual'], $t['expected'] ?? 0);
}
?>
<?php if (array_sum(array_column($trend, 'actual')) > 0): ?>
<h4 style="margin:20px 0 10px;font-size:14px;color:#e2e8f0;">📊 6-Month Cost Trend</h4>
<div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;">
    <div style="display:flex;align-items:flex-end;gap:12px;height:160px;padding-bottom:24px;position:relative;">
        <?php foreach ($trend as $t):
            $aPct = $trendMax > 0 ? round(($t['actual'] / $trendMax) * 100) : 0;
            $ePct = ($t['expected'] !== null && $trendMax > 0) ? round(($t['expected'] / $trendMax) * 100) : 0;
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;height:100%;justify-content:flex-end;">
            <div style="display:flex;gap:3px;align-items:flex-end;width:100%;justify-content:center;height:100%;">
                <?php if($ePct > 0):?>
                <div style="width:35%;background:#3b82f633;border:1px solid #3b82f666;border-radius:3px 3px 0 0;height:<?=$ePct?>%;" title="Expected: $<?=number_format($t['expected'],0)?>"></div>
                <?php endif;?>
                <div style="width:35%;background:<?=$t['actual']>0?'#f59e0b':'#334155'?>;border-radius:3px 3px 0 0;height:<?=max($aPct,2)?>%;min-height:2px;" title="Actual: $<?=number_format($t['actual'],0)?>"></div>
            </div>
            <div style="font-size:10px;color:#64748b;margin-top:4px;white-space:nowrap;"><?=htmlspecialchars($t['label'])?></div>
            <?php if($t['actual'] > 0):?><div style="font-size:9px;color:#f59e0b;">$<?=number_format($t['actual'],0)?></div><?php endif;?>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:16px;justify-content:center;margin-top:8px;font-size:11px;">
        <span><span style="display:inline-block;width:12px;height:12px;background:#3b82f633;border:1px solid #3b82f666;border-radius:2px;vertical-align:middle;"></span> Expected</span>
        <span><span style="display:inline-block;width:12px;height:12px;background:#f59e0b;border-radius:2px;vertical-align:middle;"></span> Actual (Invoiced)</span>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($stats['expected_by_plan'])): ?>
<h4 style="margin:20px 0 10px;font-size:14px;color:#e2e8f0;">📶 Expected Cost by Plan (<?=$stats['current_period']?>)</h4>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Plan</th><th>Active</th><th>Unit Cost</th><th>Total</th></tr></thead><tbody>
<?php foreach($stats['expected_by_plan'] as $p):?>
<tr>
    <td style="font-weight:600;"><?=htmlspecialchars($p['plan'])?></td>
    <td><?=$p['count']?></td>
    <td><?=fc_fmt($p['unit_cost'])?></td>
    <td style="font-weight:700;"><?=fc_fmt($p['total'])?></td>
</tr>
<?php endforeach;?>
<tr style="background:#1e293b;font-weight:700;">
    <td>Total</td><td><?=$stats['expected_services']?></td><td></td><td><?=fc_fmt($stats['expected_cost'])?></td>
</tr>
</tbody></table></div>
<?php endif;?>

<?php if($stats['latest_invoice']):?>
<h4 style="margin:20px 0 10px;font-size:14px;color:#e2e8f0;">📄 Latest Invoice</h4>
<div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;">
    <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px;">
        <div><span style="color:#94a3b8;">Supplier:</span> <strong><?=htmlspecialchars($stats['latest_invoice']['supplier'])?></strong></div>
        <div><span style="color:#94a3b8;">Invoice:</span> <strong><?=htmlspecialchars($stats['latest_invoice']['invoice_number'])?></strong></div>
        <div><span style="color:#94a3b8;">Amount:</span> <strong style="color:#f59e0b;"><?=fc_fmt((float)$stats['latest_invoice']['total_amount'])?></strong></div>
        <div><span style="color:#94a3b8;">Period:</span> <?=htmlspecialchars($stats['latest_invoice']['billing_period'])?></div>
        <div><?=fc_status_badge($stats['latest_invoice']['status'])?></div>
        <?php if($stats['latest_invoice']['variance'] !== null): $v = (float)$stats['latest_invoice']['variance']; ?>
        <div><span style="color:#94a3b8;">Variance:</span> <span class="<?=$v>0?'fc-var-pos':($v<0?'fc-var-neg':'fc-var-zero')?>"><?=$v>=0?'+':''?><?=fc_fmt($v)?></span></div>
        <?php endif;?>
    </div>
</div>
<?php endif;?>


<?php elseif ($fcSub === 'invoices'): ?>
<!-- ═══════════════════ INVOICES ═══════════════════ -->

<!-- New Invoice Form (collapsible) -->
<details style="margin-bottom:16px;">
    <summary style="cursor:pointer;font-size:14px;font-weight:700;color:#3b82f6;padding:10px 0;">➕ Record New Invoice</summary>
    <form method="post" style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;margin-top:8px;">
        <?= csrfField() ?><input type="hidden" name="fc_action" value="create_invoice">
        <div class="fc-form-grid">
            <div class="fc-form-group"><label>Supplier</label><input type="text" name="supplier" required list="fc-suppliers" placeholder="e.g. Liquid Telecom">
                <datalist id="fc-suppliers"><?php foreach($stats['suppliers'] as $s):?><option value="<?=htmlspecialchars($s)?>"><?php endforeach;?></datalist>
            </div>
            <div class="fc-form-group"><label>Invoice Number</label><input type="text" name="invoice_number" required placeholder="INV-2026-003"></div>
            <div class="fc-form-group"><label>Invoice Date</label><input type="date" name="invoice_date" value="<?=date('Y-m-d')?>"></div>
            <div class="fc-form-group"><label>Billing Period</label><input type="month" name="billing_period" value="<?=date('Y-m')?>"></div>
            <div class="fc-form-group"><label>Total Amount ($)</label><input type="number" name="total_amount" step="0.01" required placeholder="0.00"></div>
            <div class="fc-form-group"><label>Currency</label><select name="currency"><option value="USD">USD</option><option value="SSP">SSP</option></select></div>
        </div>

        <h4 style="font-size:13px;color:#e2e8f0;margin:12px 0 8px;">Line Items (optional)</h4>
        <div id="fc-lines">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 40px;gap:8px;margin-bottom:6px;font-size:11px;color:#94a3b8;">
                <div>Plan Name</div><div>Qty</div><div>Unit Cost</div><div></div>
            </div>
            <div class="fc-line-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 40px;gap:8px;margin-bottom:6px;">
                <input type="text" name="li_plan[]" placeholder="Fiber 100Mbps" style="padding:6px 8px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:12px;">
                <input type="number" name="li_qty[]" placeholder="0" style="padding:6px 8px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:12px;">
                <input type="number" name="li_cost[]" step="0.01" placeholder="0.00" style="padding:6px 8px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:12px;">
                <button type="button" onclick="this.parentElement.remove()" style="background:#ef444433;color:#ef4444;border:none;border-radius:4px;cursor:pointer;font-size:14px;">✕</button>
            </div>
        </div>
        <button type="button" onclick="addLineRow()" style="background:none;border:1px dashed #334155;color:#60a5fa;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px;margin-bottom:12px;">+ Add Line</button>

        <div class="fc-form-group"><label>Notes</label><textarea name="notes" rows="2" placeholder="Optional notes..."></textarea></div>
        <button type="submit" class="fc-btn fc-btn-primary">🧾 Record Invoice</button>
    </form>
</details>

<script>
function addLineRow(){
    var c=document.getElementById('fc-lines');
    var d=document.createElement('div');
    d.className='fc-line-row';
    d.style='display:grid;grid-template-columns:2fr 1fr 1fr 40px;gap:8px;margin-bottom:6px;';
    d.innerHTML='<input type="text" name="li_plan[]" placeholder="Plan name" style="padding:6px 8px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:12px;"><input type="number" name="li_qty[]" placeholder="0" style="padding:6px 8px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:12px;"><input type="number" name="li_cost[]" step="0.01" placeholder="0.00" style="padding:6px 8px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:12px;"><button type="button" onclick="this.parentElement.remove()" style="background:#ef444433;color:#ef4444;border:none;border-radius:4px;cursor:pointer;font-size:14px;">✕</button>';
    c.appendChild(d);
}
</script>

<?php
    $fStatus = $_GET['fstatus'] ?? '';
    $filters = [];
    if ($fStatus) $filters['status'] = $fStatus;
    $invList = $fpSvc->listInvoices($filters, 50);
    $invItems = $invList['items'] ?? [];
?>

<!-- Filter bar -->
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
    <?php foreach(['' => 'All', 'received' => '🟡 Received', 'verified' => '🔵 Verified', 'approved' => '🟣 Approved', 'paid' => '🟢 Paid', 'posted' => '⚪ Posted'] as $fk => $fl): ?>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=invoices<?=$fk?"&fstatus={$fk}":''?>" style="padding:5px 12px;border-radius:6px;border:1px solid <?=$fStatus===$fk?'#3b82f6':'#334155'?>;background:<?=$fStatus===$fk?'#3b82f6':'#1e293b'?>;color:<?=$fStatus===$fk?'#fff':'#e2e8f0'?>;font-size:12px;text-decoration:none;"><?=$fl?></a>
    <?php endforeach;?>
</div>

<?php if(empty($invItems)):?>
<div class="fc-empty"><div style="font-size:48px;margin-bottom:8px;">📭</div><div style="font-size:16px;font-weight:600;">No invoices yet</div><div style="font-size:13px;">Click "+ Record New Invoice" above to start tracking.</div></div>
<?php else:?>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr>
    <th>Supplier</th><th>Invoice #</th><th>Period</th><th>Amount</th><th>Expected</th><th>Variance</th><th>Status</th><th>Actions</th>
</tr></thead><tbody>
<?php foreach($invItems as $inv):
    $v = $inv['variance'] !== null ? (float)$inv['variance'] : null;
    $vCls = $v === null ? 'fc-var-zero' : ($v > 0 ? 'fc-var-pos' : ($v < 0 ? 'fc-var-neg' : 'fc-var-zero'));
?>
<tr>
    <td style="font-weight:600;"><?=htmlspecialchars($inv['supplier'])?></td>
    <td style="font-family:monospace;font-size:12px;"><?=htmlspecialchars($inv['invoice_number'])?></td>
    <td><?=htmlspecialchars($inv['billing_period'])?></td>
    <td style="font-weight:700;"><?=fc_fmt((float)$inv['total_amount'])?></td>
    <td><?=$inv['expected_amount']!==null ? fc_fmt((float)$inv['expected_amount']) : '<span style="color:#64748b;">—</span>'?></td>
    <td><span class="<?=$vCls?>"><?=$v!==null ? ($v>=0?'+':'') . fc_fmt($v) : '—'?></span>
        <?php if($inv['variance_pct']!==null):?><br><span style="font-size:10px;color:#64748b;">(<?=number_format((float)$inv['variance_pct'],1)?>%)</span><?php endif;?>
    </td>
    <td><?=fc_status_badge($inv['status'])?></td>
    <td style="white-space:nowrap;">
        <?php if($inv['status']==='received'):?>
            <form method="post" style="display:inline;"><?= csrfField() ?><input type="hidden" name="fc_action" value="verify_invoice"><input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                <button type="submit" class="fc-btn fc-btn-primary fc-btn-sm" title="Verify">✓ Verify</button></form>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="fc_action" value="delete_invoice"><input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                <button type="submit" class="fc-btn fc-btn-red fc-btn-sm" title="Delete">🗑</button></form>
        <?php elseif($inv['status']==='verified'):?>
            <form method="post" style="display:inline;"><?= csrfField() ?><input type="hidden" name="fc_action" value="approve_invoice"><input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                <button type="submit" class="fc-btn fc-btn-primary fc-btn-sm">✓ Approve</button></form>
        <?php elseif($inv['status']==='approved'):?>
            <form method="post" style="display:inline;"><?= csrfField() ?><input type="hidden" name="fc_action" value="pay_invoice"><input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                <input type="text" name="payment_ref" placeholder="Bank ref" required style="width:100px;padding:3px 6px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:11px;">
                <button type="submit" class="fc-btn fc-btn-green fc-btn-sm">💳 Paid</button></form>
        <?php elseif($inv['status']==='paid'):?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Post to cashbook?')"><?= csrfField() ?><input type="hidden" name="fc_action" value="post_to_cashbook"><input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                <button type="submit" class="fc-btn fc-btn-green fc-btn-sm">📒 Post to CB</button></form>
        <?php else:?>
            <span style="font-size:11px;color:#64748b;">📌 Posted</span>
        <?php endif;?>
    </td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>


<?php elseif ($fcSub === 'reconcile'): ?>
<!-- ═══════════════════ RECONCILE ═══════════════════ -->
<?php
    $rcPeriod = $_GET['rc_period'] ?? date('Y-m');
    $expected = $fpSvc->calculateExpected($rcPeriod);

    // Find invoice for this period
    $periodInvs = $fpSvc->listInvoices(['billing_period' => $rcPeriod], 10);
    $periodInv = !empty($periodInvs['items']) ? $periodInvs['items'][0] : null;

    $recon = null;
    if ($periodInv) {
        $recon = $fpSvc->reconcileInvoice((int)$periodInv['id']);
    }
?>

<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;">
    <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="fiber_costs"><input type="hidden" name="fcsub" value="reconcile">
    <label style="font-size:13px;color:#94a3b8;">Period:</label>
    <input type="month" name="rc_period" value="<?=htmlspecialchars($rcPeriod)?>" style="padding:6px 10px;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:13px;">
    <button type="submit" class="fc-btn fc-btn-primary fc-btn-sm">🔁 Reconcile</button>
</form>

<div class="fc-cards">
    <div class="fc-card"><div class="fc-card-num" style="color:#60a5fa;"><?=fc_fmt($expected['total'])?></div><div class="fc-card-label">Expected (<?=$expected['services']?> services)</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#f59e0b;"><?=$periodInv ? fc_fmt((float)$periodInv['total_amount']) : '—'?></div><div class="fc-card-label">Invoice Amount</div></div>
    <?php if($recon):
        $v = $recon['variance']; $vp = $recon['variance_pct'];
        $vColor = abs($v) < 0.01 ? '#22c55e' : ($v > 0 ? '#ef4444' : '#f59e0b');
    ?>
    <div class="fc-card"><div class="fc-card-num" style="color:<?=$vColor?>;"><?=$v>=0?'+':''?><?=fc_fmt($v)?></div><div class="fc-card-label">Variance (<?=number_format($vp,1)?>%)</div></div>
    <?php else:?>
    <div class="fc-card"><div class="fc-card-num" style="color:#64748b;">—</div><div class="fc-card-label">No Invoice for Period</div></div>
    <?php endif;?>
</div>

<?php if(!$periodInv):?>
<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:14px;margin-bottom:16px;color:#92400e;font-size:13px;">
    ⚠️ No supplier invoice recorded for <strong><?=htmlspecialchars($rcPeriod)?></strong>. <a href="?page=dashboard&tab=fiber_costs&fcsub=invoices" style="color:#2563eb;">Record one →</a>
</div>
<?php endif;?>

<h4 style="font-size:14px;color:#e2e8f0;margin:16px 0 8px;">Expected Cost Breakdown</h4>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Plan</th><th>Active Services</th><th>Unit Cost</th><th>Total Expected</th>
    <?php if($recon && !empty($recon['line_comparison'])):?><th>Invoiced</th><th>Variance</th><?php endif;?>
</tr></thead><tbody>
<?php foreach($expected['by_plan'] as $p):?>
<tr>
    <td style="font-weight:600;"><?=htmlspecialchars($p['plan'])?></td>
    <td><?=$p['count']?></td>
    <td><?=fc_fmt($p['unit_cost'])?></td>
    <td style="font-weight:700;"><?=fc_fmt($p['total'])?></td>
    <?php if($recon && !empty($recon['line_comparison'])):
        $lc = null;
        foreach($recon['line_comparison'] as $l) { if($l['plan'] === $p['plan']){ $lc = $l; break; } }
    ?>
    <td><?=$lc ? fc_fmt($lc['inv_total']) . ' (' . $lc['inv_qty'] . '×' . fc_fmt($lc['inv_unit_cost']) . ')' : '<span style="color:#64748b;">—</span>'?></td>
    <td><?php if($lc): $td = $lc['total_diff']; ?><span class="<?=$td>0?'fc-var-pos':($td<0?'fc-var-neg':'fc-var-zero')?>"><?=$td>=0?'+':''?><?=fc_fmt($td)?></span>
        <?php if($lc['qty_diff'] != 0):?><br><span style="font-size:10px;color:#64748b;">(<?=$lc['qty_diff']>0?'+':''?><?=$lc['qty_diff']?> svc)</span><?php endif;?>
    <?php else:?>—<?php endif;?></td>
    <?php endif;?>
</tr>
<?php endforeach;?>
<tr style="background:#1e293b;font-weight:700;">
    <td>Total</td><td><?=$expected['services']?></td><td></td><td><?=fc_fmt($expected['total'])?></td>
    <?php if($recon && !empty($recon['line_comparison'])):?>
    <td><?=fc_fmt($recon['invoice_total'])?></td>
    <td><span class="<?=$recon['variance']>0?'fc-var-pos':($recon['variance']<0?'fc-var-neg':'fc-var-zero')?>"><?=$recon['variance']>=0?'+':''?><?=fc_fmt($recon['variance'])?></span></td>
    <?php endif;?>
</tr>
</tbody></table></div>


<?php elseif ($fcSub === 'plan_costs'): ?>
<!-- ═══════════════════ PLAN COSTS ═══════════════════ -->
<?php $currentCosts = $fpSvc->getCurrentCosts(); ?>

<div style="background:#1e293b44;border:1px solid #334155;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px;color:#94a3b8;">
    💡 <strong>How it works:</strong> Plans auto-populate from Splynx tariffs when you sync. Set <strong>Revenue</strong> (what you charge) to see profit/margin. Set <strong>Partner Share</strong> if you split revenue with a partner.
</div>

<details style="margin-bottom:16px;">
    <summary style="cursor:pointer;font-size:14px;font-weight:700;color:#3b82f6;padding:10px 0;">➕ Set Plan Cost & Revenue</summary>
    <form method="post" style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:16px;margin-top:8px;">
        <?= csrfField() ?><input type="hidden" name="fc_action" value="save_plan_cost">
        <div class="fc-form-grid">
            <div class="fc-form-group"><label>Supplier</label><input type="text" name="pc_supplier" required list="fc-suppliers3" placeholder="Liquid Telecom">
                <datalist id="fc-suppliers3"><?php foreach($stats['suppliers'] as $s):?><option value="<?=htmlspecialchars($s)?>"><?php endforeach;?></datalist>
            </div>
            <div class="fc-form-group"><label>Plan Name (Splynx)</label><input type="text" name="pc_plan" required placeholder="Fiber 100Mbps"></div>
            <div class="fc-form-group"><label>CRM Plan Name</label><input type="text" name="pc_crm_name" placeholder="Display name in CRM"></div>
            <div class="fc-form-group"><label>Effective From</label><input type="date" name="pc_from" value="<?=date('Y-m-d')?>"></div>
        </div>
        <div class="fc-form-grid" style="grid-template-columns:1fr 1fr 1fr 1fr;">
            <div class="fc-form-group"><label style="color:#ef4444;">💸 Purchase Cost ($/mo)</label><input type="number" name="pc_cost" step="0.01" required placeholder="45.00"></div>
            <div class="fc-form-group"><label style="color:#22c55e;">💰 Revenue ($/mo)</label><input type="number" name="pc_revenue" step="0.01" placeholder="89.99"></div>
            <div class="fc-form-group"><label>🤝 Partner Share</label><input type="number" name="pc_partner" step="0.01" placeholder="5.00"></div>
            <div class="fc-form-group"><label>Profit Mode</label><select name="pc_profit_mode">
                <option value="fixed">Fixed amount</option>
                <option value="revenue_share">% of Revenue</option>
                <option value="profit_share">% of Profit</option>
            </select></div>
        </div>
        <button type="submit" class="fc-btn fc-btn-primary">💰 Save Plan</button>
    </form>
</details>

<?php if(empty($currentCosts)):?>
<div class="fc-empty"><div style="font-size:48px;margin-bottom:8px;">💰</div><div style="font-size:16px;font-weight:600;">No plan costs configured</div>
<div style="font-size:13px;">Run a Splynx sync to auto-populate plans, or add them manually above.</div></div>
<?php else:?>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Plan</th><th>Supplier</th><th style="color:#ef4444;">Cost</th><th style="color:#22c55e;">Revenue</th><th>Partner</th><th>Profit</th><th>Margin</th><th>Mode</th><th>Since</th></tr></thead><tbody>
<?php foreach($currentCosts as $pc):
    $rev = (float)($pc['revenue'] ?? 0);
    $cost = (float)($pc['cost_per_unit'] ?? 0);
    $ps = (float)($pc['partner_share'] ?? 0);
    $pm = $pc['profit_mode'] ?? 'fixed';
    $calc = FiberFinanceEngine::calcServiceProfit($rev, $cost, $ps, $pm);
    $needsRev = $rev <= 0;
?>
<tr <?=$needsRev?'style="background:#f59e0b11;"':''?>>
    <td style="font-weight:600;"><?=htmlspecialchars($pc['plan_name'])?><?=$needsRev?' <span style="color:#f59e0b;font-size:10px;">⚠️ Set Revenue</span>':''?></td>
    <td style="font-size:12px;"><?=htmlspecialchars($pc['supplier'])?></td>
    <td style="font-weight:700;color:#ef4444;"><?=fc_fmt($cost)?></td>
    <td style="font-weight:700;color:<?=$needsRev?'#64748b':'#22c55e'?>;"><?=$needsRev?'—':fc_fmt($rev)?></td>
    <td><?=$ps>0?fc_fmt($calc['partner_share']):'—'?></td>
    <td style="font-weight:700;color:<?=$calc['profit']>=0?'#22c55e':'#ef4444'?>;"><?=$rev>0?fc_fmt($calc['profit']):'—'?></td>
    <td><?php if($rev>0):?><?=$calc['margin']?>%<?php else:?>—<?php endif;?></td>
    <td style="font-size:11px;color:#64748b;"><?=htmlspecialchars($pm)?></td>
    <td style="font-size:11px;color:#64748b;"><?=htmlspecialchars($pc['effective_from'])?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>


<?php elseif ($fcSub === 'finance'): ?>
<!-- ═══════════════════ FINANCE & MARGIN ═══════════════════ -->
<?php $pnl = $ffEng->calculatePnL(); ?>
<?php if ($pnl['active'] === 0): ?>
<div class="fc-empty"><div style="font-size:48px;margin-bottom:8px;">📈</div><div style="font-size:16px;font-weight:600;">No active fiber services</div>
<div style="font-size:13px;">Run a Splynx sync and configure plan pricing to see financial reports.</div></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:16px;text-align:center;">
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;">
        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">Revenue</div>
        <div style="font-size:22px;font-weight:800;color:#3b82f6;"><?=fc_fmt($pnl['total_revenue'])?></div>
        <div style="font-size:11px;color:#64748b;"><?=$pnl['active']?> active</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;">
        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">Supplier Cost</div>
        <div style="font-size:22px;font-weight:800;color:#ef4444;"><?=fc_fmt($pnl['total_cost'])?></div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;">
        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">Partner</div>
        <div style="font-size:22px;font-weight:800;color:#f59e0b;"><?=fc_fmt($pnl['total_partner_share'])?></div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;">
        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">Net Profit</div>
        <div style="font-size:22px;font-weight:800;color:<?=$pnl['total_profit']>=0?'#22c55e':'#ef4444'?>;"><?=fc_fmt($pnl['total_profit'])?></div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;">
        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">Margin</div>
        <div style="font-size:22px;font-weight:800;color:#8b5cf6;"><?=$pnl['overall_margin']?>%</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:14px;">
        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;">At Risk</div>
        <div style="font-size:22px;font-weight:800;color:#f59e0b;"><?=fc_fmt($pnl['revenue_at_risk'])?></div>
        <div style="font-size:11px;color:#64748b;"><?=$pnl['suspended']?> suspended</div>
    </div>
</div>

<?php
$lowMargin = array_filter($pnl['by_plan'], function($p) { return $p['revenue'] > 0 && $p['margin'] < 10; });
if (!empty($lowMargin)):
?>
<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:12px;margin-bottom:12px;color:#92400e;font-size:13px;">
    ⚠️ <strong>Low margin plans:</strong>
    <?php foreach($lowMargin as $lp):?> <strong><?=htmlspecialchars($lp['plan'])?></strong> (<?=$lp['margin']?>%)<?php endforeach;?>
    — below 10% threshold.
</div>
<?php endif;?>

<h4 style="font-size:14px;color:#e2e8f0;margin:16px 0 8px;">Revenue by Plan — Active Services</h4>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Plan</th><th>Active</th><th style="color:#3b82f6;">Revenue</th><th style="color:#ef4444;">Cost</th><th>Partner</th><th>Profit</th><th>Margin</th></tr></thead><tbody>
<?php foreach($pnl['by_plan'] as $bp):?>
<tr>
    <td style="font-weight:600;"><?=htmlspecialchars($bp['plan'])?></td>
    <td><?=$bp['count']?></td>
    <td style="color:#3b82f6;font-weight:700;"><?=fc_fmt($bp['revenue'])?></td>
    <td style="color:#ef4444;"><?=fc_fmt($bp['cost'])?></td>
    <td><?=fc_fmt($bp['partner'])?></td>
    <td style="color:<?=$bp['profit']>=0?'#22c55e':'#ef4444'?>;font-weight:700;"><?=fc_fmt($bp['profit'])?></td>
    <td><?=$bp['margin']?>%</td>
</tr>
<?php endforeach;?>
<tr style="background:#1e293b;font-weight:700;">
    <td>Total</td><td><?=$pnl['active']?></td><td style="color:#3b82f6;"><?=fc_fmt($pnl['total_revenue'])?></td>
    <td style="color:#ef4444;"><?=fc_fmt($pnl['total_cost'])?></td><td><?=fc_fmt($pnl['total_partner_share'])?></td>
    <td style="color:<?=$pnl['total_profit']>=0?'#22c55e':'#ef4444'?>;"><?=fc_fmt($pnl['total_profit'])?></td>
    <td><?=$pnl['overall_margin']?>%</td>
</tr>
</tbody></table></div>
<?php endif;?>


<?php elseif ($fcSub === 'leakage'): ?>
<!-- ═══════════════════ LEAKAGE ═══════════════════ -->
<?php
// Only need active count from PnL — use cheap SQL instead of full calculatePnL()
$_activeCount = 0;
try { $_activeCount = (int)$store->getPdo()->query("SELECT COUNT(*) FROM fiber_services_cache WHERE crm_status = 'Active' OR (crm_status IS NULL AND splynx_status = 'active')")->fetchColumn(); } catch (\Throwable $_e) {}
$pnl       = ['active' => $_activeCount];
$leakage   = $ffEng->detectLeakage();
$anomalies = $ffEng->detectProfitAnomalies();
?>
<div class="fc-cards">
    <div class="fc-card" style="border-color:<?=$leakage['count']>0?'#ef444466':'#22c55e66'?>;">
        <div class="fc-card-num" style="color:<?=$leakage['count']>0?'#ef4444':'#22c55e'?>;"><?=$leakage['count']?></div>
        <div class="fc-card-label">Leakage Services</div>
    </div>
    <div class="fc-card"><div class="fc-card-num" style="color:#ef4444;"><?=fc_fmt($leakage['total_monthly_loss'])?></div><div class="fc-card-label">Monthly Exposure</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#f59e0b;"><?=$anomalies['count']?></div><div class="fc-card-label">Profit Anomalies</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#22c55e;"><?=$pnl['active']?></div><div class="fc-card-label">Active in CRM</div></div>
</div>

<?php if ($leakage['count'] === 0 && $anomalies['count'] === 0): ?>
<div class="fc-empty"><div style="font-size:64px;margin-bottom:8px;">✅</div><div style="font-size:16px;font-weight:600;color:#22c55e;">No leakage detected</div>
<div style="font-size:13px;">All Splynx-active services have matching Active status in CRM.</div></div>
<?php else: ?>

<?php if (!empty($leakage['leaks'])): ?>
<h4 style="font-size:14px;color:#ef4444;margin:16px 0 8px;">🚨 Revenue Leakage — Active in Splynx, not in CRM (<?=$leakage['count']?>)</h4>
<p style="font-size:12px;color:#94a3b8;margin-bottom:8px;">We're paying the supplier for these services but NOT billing the customer.</p>
<div style="overflow-x:auto;border:1px solid #ef444444;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Service ID</th><th>Customer</th><th>Plan</th><th>Splynx</th><th>CRM</th><th>Monthly Loss</th><th>Action</th></tr></thead><tbody>
<?php foreach($leakage['leaks'] as $lk):?>
<tr>
    <td style="font-family:monospace;font-size:11px;"><?=htmlspecialchars($lk['service_id'])?></td>
    <td><?=htmlspecialchars($lk['customer_name'])?></td>
    <td><?=htmlspecialchars($lk['plan_name'])?></td>
    <td><span style="background:#22c55e22;color:#22c55e;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;"><?=htmlspecialchars($lk['splynx_status'])?></span></td>
    <td><span style="background:#ef444422;color:#ef4444;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;"><?=htmlspecialchars($lk['crm_status'])?></span></td>
    <td style="color:#ef4444;font-weight:700;"><?=fc_fmt($lk['monthly_loss'])?>/mo</td>
    <td><form method="post" style="display:inline;"><?= csrfField() ?><input type="hidden" name="fc_action" value="override_status"><input type="hidden" name="svc_id" value="<?=htmlspecialchars($lk['service_id'])?>"><input type="hidden" name="new_status" value="Active"><input type="hidden" name="reason" value="Leakage fix">
        <button type="submit" class="fc-btn fc-btn-primary fc-btn-sm">✅ Mark Active</button></form></td>
</tr>
<?php endforeach;?>
<tr style="background:#1e293b;font-weight:700;color:#ef4444;"><td colspan="5">Total Monthly Leakage</td><td><?=fc_fmt($leakage['total_monthly_loss'])?>/mo</td><td></td></tr>
</tbody></table></div>
<?php endif;?>

<?php if (!empty($anomalies['anomalies'])): ?>
<h4 style="font-size:14px;color:#f59e0b;margin:16px 0 8px;">⚠️ Profit Anomalies — CRM Active, Splynx inactive (<?=$anomalies['count']?>)</h4>
<p style="font-size:12px;color:#94a3b8;margin-bottom:8px;">We're billing the customer but the service is down in Splynx.</p>
<div style="overflow-x:auto;border:1px solid #f59e0b44;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Service ID</th><th>Customer</th><th>Plan</th><th>Splynx</th><th>CRM</th><th>Revenue at Risk</th></tr></thead><tbody>
<?php foreach($anomalies['anomalies'] as $an):?>
<tr>
    <td style="font-family:monospace;font-size:11px;"><?=htmlspecialchars($an['service_id'])?></td>
    <td><?=htmlspecialchars($an['customer_name'])?></td>
    <td><?=htmlspecialchars($an['plan_name'])?></td>
    <td><span style="background:#f59e0b22;color:#f59e0b;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;"><?=htmlspecialchars($an['splynx_status'])?></span></td>
    <td><span style="background:#22c55e22;color:#22c55e;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;"><?=htmlspecialchars($an['crm_status'])?></span></td>
    <td style="color:#f59e0b;font-weight:700;"><?=fc_fmt($an['monthly_revenue'])?>/mo</td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
<?php endif;?>


<?php elseif ($fcSub === 'services'): ?>
<!-- ═══════════════════ SERVICES ═══════════════════ -->
<?php
    $svcFilter = $_GET['svc_status'] ?? '';
    $svcSearch = trim($_GET['svc_q'] ?? '');
    $svcData = $ffEng->getServices(['status' => $svcFilter, 'search' => $svcSearch], 100);
    $svcItems = $svcData['items'] ?? [];
?>
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
    <?php foreach(['' => 'All', 'active' => '🟢 Active', 'stopped' => '🟡 Suspended', 'disabled' => '🔴 Cancelled'] as $sk => $sl): ?>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=services<?=$sk?"&svc_status={$sk}":''?><?=$svcSearch?"&svc_q=".urlencode($svcSearch):''?>" style="padding:5px 12px;border-radius:6px;border:1px solid <?=$svcFilter===$sk?'#3b82f6':'#334155'?>;background:<?=$svcFilter===$sk?'#3b82f6':'#1e293b'?>;color:<?=$svcFilter===$sk?'#fff':'#e2e8f0'?>;font-size:12px;text-decoration:none;"><?=$sl?></a>
    <?php endforeach;?>
    <form method="get" style="display:flex;gap:4px;margin-left:auto;">
        <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="fiber_costs"><input type="hidden" name="fcsub" value="services">
        <?php if($svcFilter):?><input type="hidden" name="svc_status" value="<?=htmlspecialchars($svcFilter)?>"><?php endif;?>
        <input type="text" name="svc_q" value="<?=htmlspecialchars($svcSearch)?>" placeholder="Search..." style="padding:6px 10px;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:12px;width:160px;">
        <button type="submit" class="fc-btn fc-btn-primary fc-btn-sm">🔍</button>
    </form>
</div>

<p style="font-size:12px;color:#64748b;margin-bottom:8px;"><?=$svcData['total']?> services cached from Splynx</p>

<?php if(empty($svcItems)):?>
<div class="fc-empty"><div style="font-size:48px;margin-bottom:8px;">🌐</div><div style="font-size:16px;font-weight:600;">No services cached</div>
<div style="font-size:13px;">Run a Splynx sync to populate the service list.</div></div>
<?php else:?>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Service ID</th><th>Customer</th><th>Plan</th><th>Splynx</th><th>CRM</th><th>IP</th><th>Last Seen</th></tr></thead><tbody>
<?php foreach($svcItems as $sv):
    $ss = $sv['splynx_status'];
    $cs = $sv['crm_status'] ?: '—';
    $ssColor = $ss==='active'?'#22c55e':($ss==='stopped'?'#f59e0b':'#ef4444');
    $csColor = $cs==='Active'?'#22c55e':($cs==='Suspended'?'#f59e0b':($cs==='—'?'#64748b':'#ef4444'));
?>
<tr>
    <td style="font-family:monospace;font-size:11px;"><?=htmlspecialchars($sv['splynx_service_id'])?></td>
    <td><?=htmlspecialchars($sv['customer_name'])?></td>
    <td><?=htmlspecialchars($sv['plan_name'])?></td>
    <td><span style="background:<?=$ssColor?>22;color:<?=$ssColor?>;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;"><?=htmlspecialchars($ss)?></span></td>
    <td><span style="background:<?=$csColor?>22;color:<?=$csColor?>;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;"><?=htmlspecialchars($cs)?></span></td>
    <td style="font-family:monospace;font-size:11px;color:#64748b;"><?=htmlspecialchars($sv['ip_address'])?></td>
    <td style="font-size:11px;color:#64748b;"><?=htmlspecialchars(substr($sv['last_seen'] ?? '', 0, 16))?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>


<?php elseif ($fcSub === 'customers'): ?>
<!-- ═══════════════════ CUSTOMER MAPPING ═══════════════════ -->
<?php
    $cmFilter = $_GET['cm_filter'] ?? 'all';
    $custMap = $ffEng->getCustomerMap($cmFilter, 200);
?>
<div class="fc-cards" style="grid-template-columns:repeat(3,1fr);">
    <div class="fc-card"><div class="fc-card-num" style="color:#22c55e;"><?=$custMap['mapped']?></div><div class="fc-card-label">Mapped</div></div>
    <div class="fc-card" style="border-color:<?=$custMap['unmapped']>0?'#ef444466':'#33415566'?>;"><div class="fc-card-num" style="color:<?=$custMap['unmapped']>0?'#ef4444':'#64748b'?>;"><?=$custMap['unmapped']?></div><div class="fc-card-label">Unmapped</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#60a5fa;"><?=$custMap['total']?></div><div class="fc-card-label">Total Customers</div></div>
</div>

<div style="display:flex;gap:8px;margin-bottom:12px;">
    <?php foreach(['all'=>'All','mapped'=>'✅ Mapped','unmapped'=>'❌ Unmapped'] as $ck=>$cl):?>
    <a href="?page=dashboard&tab=fiber_costs&fcsub=customers&cm_filter=<?=$ck?>" style="padding:5px 12px;border-radius:6px;border:1px solid <?=$cmFilter===$ck?'#3b82f6':'#334155'?>;background:<?=$cmFilter===$ck?'#3b82f6':'#1e293b'?>;color:<?=$cmFilter===$ck?'#fff':'#e2e8f0'?>;font-size:12px;text-decoration:none;"><?=$cl?></a>
    <?php endforeach;?>
</div>

<?php if(empty($custMap['items'])):?>
<div class="fc-empty"><div style="font-size:48px;margin-bottom:8px;">👥</div><div style="font-size:16px;font-weight:600;">No customers synced</div>
<div style="font-size:13px;">Run a Splynx sync to populate the customer list.</div></div>
<?php else:?>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Splynx ID</th><th>Name</th><th>Email</th><th>CRM Client</th><th>Linked By</th><th>Action</th></tr></thead><tbody>
<?php foreach($custMap['items'] as $cm):
    $isMapped = !empty($cm['crm_client_id']);
?>
<tr style="<?=$isMapped?'':'background:#f59e0b08;'?>">
    <td style="font-family:monospace;font-size:11px;"><?=htmlspecialchars($cm['splynx_customer_id'])?></td>
    <td style="font-weight:600;"><?=htmlspecialchars($cm['splynx_name'])?></td>
    <td style="font-size:12px;color:#64748b;"><?=htmlspecialchars($cm['splynx_email'])?></td>
    <td><?=$isMapped ? '<span style="color:#22c55e;">CRM #' . htmlspecialchars($cm['crm_client_id']) . '</span> ' . htmlspecialchars($cm['crm_name']) : '<span style="color:#ef4444;">Unmapped</span>'?></td>
    <td style="font-size:11px;color:#64748b;"><?=htmlspecialchars($cm['linked_by'])?></td>
    <td><?php if(!$isMapped):?>
        <form method="post" style="display:inline-flex;gap:4px;align-items:center;">
            <?= csrfField() ?><input type="hidden" name="fc_action" value="manual_map">
            <input type="hidden" name="splynx_id" value="<?=htmlspecialchars($cm['splynx_customer_id'])?>">
            <input type="text" name="crm_id" placeholder="CRM ID" required style="width:80px;padding:3px 6px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:11px;">
            <button type="submit" class="fc-btn fc-btn-primary fc-btn-sm">🔗 Link</button>
        </form>
    <?php else:?>—<?php endif;?></td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>


<?php elseif ($fcSub === 'prediction'): ?>
<!-- ═══════════════════ INVOICE PREDICTION ═══════════════════ -->
<?php
    $predPeriod = $_GET['pred_period'] ?? date('Y-m');
    $prediction = $fpSvc->generatePredictedInvoice($predPeriod);
    $byPlan     = $prediction['by_plan'] ?? [];
?>

<div style="display:flex;gap:8px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">
    <form method="get" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="fiber_costs"><input type="hidden" name="fcsub" value="prediction">
        <label style="font-size:13px;color:#94a3b8;">Period:</label>
        <input type="month" name="pred_period" value="<?=htmlspecialchars($predPeriod)?>" style="padding:6px 10px;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:13px;">
        <button type="submit" class="fc-btn fc-btn-primary fc-btn-sm">🔮 Generate</button>
    </form>
    <span style="font-size:12px;color:#64748b;">Generated: <?=htmlspecialchars($prediction['generated_at'] ?? '')?></span>
</div>

<?php if (empty($prediction['line_items'])): ?>
<div class="fc-empty"><div style="font-size:48px;margin-bottom:8px;">🔮</div><div style="font-size:16px;font-weight:600;">No services in cache</div>
<div style="font-size:13px;">Run a Splynx sync first to populate service data.</div></div>
<?php else: ?>

<!-- Prediction Summary Cards -->
<div class="fc-cards" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
    <div class="fc-card"><div class="fc-card-num" style="color:#ef4444;font-size:22px;"><?=fc_fmt($prediction['service_total'])?></div><div class="fc-card-label">Service Cost</div>
        <div style="font-size:10px;color:#64748b;"><?=$prediction['active_line_count']?> active + <?=$prediction['disabled_line_count']?> prorated</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#f59e0b;font-size:22px;"><?=fc_fmt($prediction['installation_total'])?></div><div class="fc-card-label">Installation</div>
        <div style="font-size:10px;color:#64748b;"><?=$prediction['new_customer_count']?> new × $<?=number_format($prediction['installation_cost_per_svc'],0)?></div></div>
    <div class="fc-card" style="border-color:#8b5cf666;"><div class="fc-card-num" style="color:#8b5cf6;font-size:22px;"><?=fc_fmt($prediction['grand_total'])?></div><div class="fc-card-label">Predicted Total</div>
        <div style="font-size:10px;color:#64748b;">What 4G Telecom should invoice</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#60a5fa;font-size:22px;"><?=$prediction['service_line_count']?></div><div class="fc-card-label">Total Lines</div></div>
    <?php if ($prediction['audit_flag_count'] > 0): ?>
    <div class="fc-card" style="border-color:#f59e0b66;"><div class="fc-card-num" style="color:#f59e0b;font-size:22px;"><?=$prediction['audit_flag_count']?></div><div class="fc-card-label">Audit Flags</div>
        <div style="font-size:10px;color:#64748b;">Prorated / partial months</div></div>
    <?php endif; ?>
</div>

<!-- Cost by Plan -->
<h4 style="font-size:14px;color:#e2e8f0;margin:16px 0 8px;">Predicted Cost by Plan</h4>
<div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Plan</th><th>Active</th><th>Prorated</th><th>Rate/mo</th><th>Total</th></tr></thead><tbody>
<?php foreach($byPlan as $bp):?>
<tr>
    <td style="font-weight:600;"><?=htmlspecialchars($bp['plan'])?></td>
    <td><?=$bp['active']?></td>
    <td><?=$bp['disabled']?></td>
    <td><?=fc_fmt($bp['rate'])?></td>
    <td style="font-weight:700;"><?=fc_fmt($bp['total'])?></td>
</tr>
<?php endforeach;?>
<tr style="background:#1e293b;font-weight:700;">
    <td>Service Subtotal</td><td><?=$prediction['active_line_count']?></td><td><?=$prediction['disabled_line_count']?></td><td></td><td><?=fc_fmt($prediction['service_total'])?></td>
</tr>
<tr style="background:#1e293b44;">
    <td>Installation (<?=$prediction['new_customer_count']?> new × $<?=number_format($prediction['installation_cost_per_svc'],0)?>)</td><td colspan="3"></td><td style="font-weight:700;"><?=fc_fmt($prediction['installation_total'])?></td>
</tr>
<tr style="background:#8b5cf611;font-weight:700;color:#8b5cf6;">
    <td>Grand Total (Predicted Invoice)</td><td colspan="3"></td><td style="font-size:16px;"><?=fc_fmt($prediction['grand_total'])?></td>
</tr>
</tbody></table></div>

<?php if (!empty($prediction['audit_flags'])): ?>
<h4 style="font-size:14px;color:#f59e0b;margin:16px 0 8px;">Audit Flags (<?=$prediction['audit_flag_count']?>)</h4>
<div style="overflow-x:auto;border:1px solid #f59e0b44;border-radius:8px;">
<table class="fc-table"><thead><tr><th>#</th><th>Flag</th></tr></thead><tbody>
<?php foreach($prediction['audit_flags'] as $af):?>
<tr><td style="font-family:monospace;"><?=$af['line']?></td><td><?=htmlspecialchars($af['flag'])?></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif; ?>

<!-- All Line Items (collapsible) -->
<details style="margin-top:16px;">
    <summary style="cursor:pointer;font-size:14px;font-weight:700;color:#3b82f6;padding:10px 0;">📋 All Service Lines (<?=$prediction['service_line_count']?>)</summary>
    <div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;margin-top:8px;">
    <table class="fc-table"><thead><tr><th>#</th><th>Customer</th><th>Plan</th><th>Status</th><th>Days</th><th>Rate</th><th>Prorate</th><th>Total</th></tr></thead><tbody>
    <?php foreach($prediction['line_items'] as $li):
        $stColor = $li['service_status']==='active' ? '#22c55e' : '#f59e0b';
    ?>
    <tr>
        <td style="font-size:11px;color:#64748b;"><?=$li['line_no']?></td>
        <td><?=htmlspecialchars(substr($li['customer_name'], 0, 30))?><?=$li['is_new_customer']?' <span style="color:#3b82f6;font-size:10px;font-weight:700;">NEW</span>':''?></td>
        <td style="font-size:12px;"><?=htmlspecialchars($li['plan_name'])?></td>
        <td><span style="background:<?=$stColor?>22;color:<?=$stColor?>;padding:1px 6px;border-radius:4px;font-size:10px;"><?=htmlspecialchars($li['service_status'])?></span></td>
        <td><?=$li['active_days']?>/<?=$li['days_in_month']?></td>
        <td><?=fc_fmt($li['monthly_rate'])?></td>
        <td style="font-size:11px;<?=$li['proration_rate']<1?'color:#f59e0b;font-weight:700;':''?>"><?=number_format($li['proration_rate']*100, 0)?>%</td>
        <td style="font-weight:700;"><?=fc_fmt($li['line_total'])?></td>
    </tr>
    <?php endforeach;?>
    </tbody></table></div>
</details>
<?php endif; ?>


<?php elseif ($fcSub === 'compare'): ?>
<!-- ═══════════════════ SUPPLIER COMPARE ═══════════════════ -->
<?php
    $cmpPeriod = $_GET['cmp_period'] ?? date('Y-m');
    $cmpAmount = (float)($_GET['cmp_amount'] ?? 0);
    $cmpRef    = trim($_GET['cmp_ref'] ?? '');
    $comparison = null;
    $prediction = $fpSvc->generatePredictedInvoice($cmpPeriod);

    if ($cmpAmount > 0) {
        $comparison = $fpSvc->compareWithSupplier($cmpPeriod, $cmpAmount, $cmpRef);
    }
?>

<div style="background:#1e293b44;border:1px solid #334155;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12px;color:#94a3b8;">
    ⚖️ <strong>How it works:</strong> Enter the amount 4G Telecom invoiced you. The system compares line by line against our calculated prediction — proration for partial months, cancelled services, new installations — and flags every overcharge.
</div>

<!-- Period + Amount Input -->
<form method="get" style="display:flex;gap:8px;margin-bottom:16px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="fiber_costs"><input type="hidden" name="fcsub" value="compare">
    <div class="fc-form-group" style="margin:0;"><label style="font-size:11px;">Period</label>
        <input type="month" name="cmp_period" value="<?=htmlspecialchars($cmpPeriod)?>" style="padding:6px 10px;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:13px;"></div>
    <div class="fc-form-group" style="margin:0;"><label style="font-size:11px;color:#ef4444;">Supplier Invoice Total ($)</label>
        <input type="number" step="0.01" name="cmp_amount" value="<?=$cmpAmount > 0 ? $cmpAmount : ''?>" placeholder="Enter 4G Telecom amount" required style="padding:6px 10px;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:13px;width:200px;"></div>
    <div class="fc-form-group" style="margin:0;"><label style="font-size:11px;">Invoice Ref</label>
        <input type="text" name="cmp_ref" value="<?=htmlspecialchars($cmpRef)?>" placeholder="INV-2026-02" style="padding:6px 10px;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;font-size:13px;width:140px;"></div>
    <button type="submit" class="fc-btn fc-btn-primary">⚖️ Compare</button>
</form>

<?php if ($cmpAmount <= 0): ?>
<!-- Show prediction summary while waiting for supplier amount -->
<div class="fc-cards">
    <div class="fc-card"><div class="fc-card-num" style="color:#8b5cf6;font-size:20px;"><?=fc_fmt($prediction['grand_total'])?></div><div class="fc-card-label">Our Prediction</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#ef4444;font-size:20px;"><?=fc_fmt($prediction['service_total'])?></div><div class="fc-card-label">Service Cost</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#f59e0b;font-size:20px;"><?=fc_fmt($prediction['installation_total'])?></div><div class="fc-card-label">Installation</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#64748b;font-size:20px;">?</div><div class="fc-card-label">Supplier Amount</div>
        <div style="font-size:10px;color:#f59e0b;">Enter above to compare</div></div>
</div>

<?php else: ?>
<!-- COMPARISON RESULTS -->
<?php
    $v = $comparison['variance'];
    $vColor = $comparison['verdict'] === 'match' || $comparison['verdict'] === 'clean' ? '#22c55e' :
              ($comparison['verdict'] === 'overcharged' || $comparison['verdict'] === 'over' ? '#ef4444' : '#f59e0b');
    $verdictBg = $comparison['verdict'] === 'match' || $comparison['verdict'] === 'clean' ? '#22c55e11' :
                 ($comparison['verdict'] === 'overcharged' || $comparison['verdict'] === 'over' ? '#ef444422' : '#f59e0b22');
?>

<!-- Verdict Banner -->
<div style="background:<?=$verdictBg?>;border:1px solid <?=$vColor?>44;border-radius:8px;padding:14px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;gap:12px;">
        <div style="font-size:32px;"><?=$comparison['verdict']==='overcharged'||$comparison['verdict']==='over'?'🚨':($comparison['verdict']==='match'||$comparison['verdict']==='clean'?'✅':'⚠️')?></div>
        <div>
            <div style="font-size:16px;font-weight:700;color:<?=$vColor?>;"><?=htmlspecialchars($comparison['verdict_message'])?></div>
            <div style="font-size:13px;color:#94a3b8;margin-top:2px;">
                Our prediction: <strong><?=fc_fmt($comparison['our_predicted_total'])?></strong> |
                Supplier charged: <strong style="color:<?=$vColor?>;"><?=fc_fmt($comparison['supplier_total'])?></strong> |
                Variance: <strong style="color:<?=$vColor?>;"><?=$v>=0?'+':''?><?=fc_fmt($v)?> (<?=$comparison['variance_pct']?>%)</strong>
            </div>
        </div>
    </div>
</div>

<!-- Issue Cards -->
<div class="fc-cards">
    <div class="fc-card" style="border-color:<?=$comparison['overcharge_count']>0?'#ef444466':'#33415566'?>;">
        <div class="fc-card-num" style="color:<?=$comparison['overcharge_count']>0?'#ef4444':'#22c55e'?>;font-size:20px;"><?=$comparison['overcharge_count']?></div>
        <div class="fc-card-label">Overcharges</div>
        <?php if($comparison['overcharge_total']>0):?><div style="font-size:10px;color:#ef4444;">+<?=fc_fmt($comparison['overcharge_total'])?> excess</div><?php endif;?>
    </div>
    <div class="fc-card"><div class="fc-card-num" style="color:<?=$comparison['proration_risk_count']>0?'#f59e0b':'#22c55e'?>;font-size:20px;"><?=$comparison['proration_risk_count']?></div><div class="fc-card-label">Proration Risks</div>
        <div style="font-size:10px;color:#64748b;">Cancelled charged full month?</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:<?=$comparison['ghost_charge_count']>0?'#ef4444':'#22c55e'?>;font-size:20px;"><?=$comparison['ghost_charge_count']?></div><div class="fc-card-label">Ghost Charges</div>
        <div style="font-size:10px;color:#64748b;">Not in our records</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#3b82f6;font-size:20px;"><?=$comparison['dispute_count']?></div><div class="fc-card-label">Disputed</div></div>
    <div class="fc-card"><div class="fc-card-num" style="color:#64748b;font-size:20px;"><?=$comparison['dead_install_count']?></div><div class="fc-card-label">Dead Installs</div></div>
</div>

<?php if (!empty($comparison['ghost_charges'])): ?>
<h4 style="font-size:14px;color:#ef4444;margin:16px 0 8px;">🚨 Ghost Charges — Supplier Billed, We Don't Have</h4>
<div style="overflow-x:auto;border:1px solid #ef444444;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Service ID</th><th>Customer</th><th>Plan</th><th>Amount</th><th>Note</th></tr></thead><tbody>
<?php foreach($comparison['ghost_charges'] as $gc):?>
<tr style="background:#ef444411;"><td style="font-family:monospace;"><?=htmlspecialchars($gc['service_id'])?></td><td><?=htmlspecialchars($gc['customer_name'])?></td><td><?=htmlspecialchars($gc['plan_name'])?></td><td style="color:#ef4444;font-weight:700;"><?=fc_fmt($gc['amount'])?></td><td style="font-size:11px;"><?=htmlspecialchars($gc['reason'])?></td></tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>

<!-- Problem Lines: overcharges + proration risks -->
<?php
$problemLines = array_filter($comparison['line_comparison'], function($l) {
    return in_array($l['diff_type'], ['overcharge', 'proration_risk']);
});
?>
<?php if (!empty($problemLines)): ?>
<h4 style="font-size:14px;color:#f59e0b;margin:16px 0 8px;">⚠️ Lines to Check (<?=count($problemLines)?>)</h4>
<p style="font-size:12px;color:#94a3b8;margin-bottom:8px;">These lines may be overcharged. Cancelled services charged full month, or amounts higher than our calculation.</p>
<div style="overflow-x:auto;border:1px solid #f59e0b44;border-radius:8px;">
<table class="fc-table"><thead><tr><th>Customer</th><th>Plan</th><th>Status</th><th>Days</th><th>Our Calc</th><th>Supplier?</th><th>Diff</th><th>Flag</th></tr></thead><tbody>
<?php foreach($problemLines as $pl):
    $diffColor = $pl['diff_type']==='overcharge' ? '#ef4444' : '#f59e0b';
?>
<tr>
    <td><?=htmlspecialchars(substr($pl['customer_name'],0,25))?></td>
    <td style="font-size:11px;"><?=htmlspecialchars($pl['plan_name'])?></td>
    <td><span style="background:<?=$pl['service_status']==='active'?'#22c55e':'#f59e0b'?>22;color:<?=$pl['service_status']==='active'?'#22c55e':'#f59e0b'?>;padding:1px 6px;border-radius:4px;font-size:10px;"><?=htmlspecialchars($pl['service_status'])?></span></td>
    <td><?=$pl['our_days']?>/<?=$pl['days_in_month']?></td>
    <td><?=fc_fmt($pl['our_amount'])?></td>
    <td style="color:<?=$diffColor?>;font-weight:700;"><?=$pl['supplier_amount']!==null?fc_fmt($pl['supplier_amount']):'~$'.number_format($pl['our_rate'],2)?></td>
    <td style="color:<?=$diffColor?>;font-weight:700;"><?=$pl['diff']!==null?($pl['diff']>=0?'+':'').fc_fmt($pl['diff']):'+'.fc_fmt($pl['our_rate']-$pl['our_amount'])?></td>
    <td>
        <form method="post" style="display:inline-flex;gap:3px;">
            <?= csrfField() ?><input type="hidden" name="fc_action" value="save_dispute_flag">
            <input type="hidden" name="cmp_period" value="<?=htmlspecialchars($cmpPeriod)?>">
            <input type="hidden" name="cmp_svc_id" value="<?=htmlspecialchars($pl['service_id'])?>">
            <input type="hidden" name="cmp_amount_keep" value="<?=$cmpAmount?>">
            <input type="hidden" name="cmp_disputed" value="1">
            <input type="text" name="cmp_reason" value="<?=htmlspecialchars($pl['dispute_reason'])?>" placeholder="Reason" style="width:80px;padding:2px 6px;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#e2e8f0;font-size:10px;">
            <button type="submit" class="fc-btn fc-btn-red fc-btn-sm" style="padding:2px 8px;font-size:10px;"><?=$pl['disputed']?'✅ Flagged':'🚩 Dispute'?></button>
        </form>
    </td>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>

<!-- Full Line Comparison (collapsible) -->
<details style="margin-top:16px;">
    <summary style="cursor:pointer;font-size:14px;font-weight:700;color:#3b82f6;padding:10px 0;">📋 All Lines (<?=$comparison['line_count']?>)</summary>
    <div style="overflow-x:auto;border:1px solid #334155;border-radius:8px;margin-top:8px;">
    <table class="fc-table"><thead><tr><th>#</th><th>Customer</th><th>Plan</th><th>Status</th><th>Days</th><th>Our Calc</th><th>Verdict</th><th>Actions</th></tr></thead><tbody>
    <?php foreach($comparison['line_comparison'] as $cl):
        $typeColor = ['match'=>'#22c55e','overcharge'=>'#ef4444','undercharge'=>'#3b82f6','proration_risk'=>'#f59e0b','likely_ok'=>'#64748b','unknown'=>'#64748b'][$cl['diff_type']] ?? '#64748b';
        $typeLabel = ['match'=>'OK','overcharge'=>'OVER','undercharge'=>'Under','proration_risk'=>'Risk','likely_ok'=>'OK','unknown'=>'—'][$cl['diff_type']] ?? '—';
    ?>
    <tr style="<?=$cl['disputed']?'background:#ef444411;':($cl['dead_install']?'background:#64748b11;':'')?>"">
        <td style="font-size:11px;color:#64748b;"><?=$cl['line_no']?></td>
        <td><?=htmlspecialchars(substr($cl['customer_name'],0,25))?><?=$cl['is_new_customer']?' <span style="color:#3b82f6;font-size:9px;">NEW</span>':''?><?=$cl['disputed']?' <span style="color:#ef4444;font-size:9px;">DISPUTED</span>':''?><?=$cl['dead_install']?' <span style="color:#64748b;font-size:9px;">DEAD</span>':''?></td>
        <td style="font-size:11px;"><?=htmlspecialchars($cl['plan_name'])?></td>
        <td><span style="background:<?=$cl['service_status']==='active'?'#22c55e':'#f59e0b'?>22;color:<?=$cl['service_status']==='active'?'#22c55e':'#f59e0b'?>;padding:1px 6px;border-radius:4px;font-size:10px;"><?=htmlspecialchars($cl['service_status'])?></span></td>
        <td><?=$cl['our_days']?>/<?=$cl['days_in_month']?></td>
        <td style="font-weight:700;"><?=fc_fmt($cl['our_amount'])?></td>
        <td><span style="color:<?=$typeColor?>;font-weight:700;font-size:11px;"><?=$typeLabel?></span></td>
        <td style="white-space:nowrap;">
            <form method="post" style="display:inline-flex;gap:2px;">
                <?= csrfField() ?><input type="hidden" name="fc_action" value="save_dispute_flag">
                <input type="hidden" name="cmp_period" value="<?=htmlspecialchars($cmpPeriod)?>">
                <input type="hidden" name="cmp_svc_id" value="<?=htmlspecialchars($cl['service_id'])?>">
                <input type="hidden" name="cmp_amount_keep" value="<?=$cmpAmount?>">
                <?php if(!$cl['disputed']):?>
                <input type="hidden" name="cmp_disputed" value="1">
                <button type="submit" class="fc-btn fc-btn-sm" style="padding:1px 6px;font-size:9px;background:#ef444433;color:#ef4444;">🚩</button>
                <?php endif;?>
                <?php if(!$cl['dead_install']):?>
                <input type="hidden" name="cmp_dead" value="1">
                <button type="submit" class="fc-btn fc-btn-sm" style="padding:1px 6px;font-size:9px;background:#64748b33;color:#64748b;" title="Dead install">💀</button>
                <?php endif;?>
            </form>
        </td>
    </tr>
    <?php endforeach;?>
    </tbody></table></div>
</details>
<?php endif;?>

<?php endif; ?>
