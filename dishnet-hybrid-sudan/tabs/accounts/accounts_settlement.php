<?php
// ── Access gate: accountant or admin only ──
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}
// Tab: accounts_settlement
// Extracted from public.php on 2026-03-15
    // ──────────────────────────────────────────────────────────────────────
    // DAILY SETTLEMENT — All retailers summary for a given date
    // Shows: who collected what, commission, net payable, wallet status
    // ──────────────────────────────────────────────────────────────────────
    $settleDate = $_GET['sd'] ?? date('Y-m-d');
    $settleMode = $_GET['sm'] ?? 'day'; // day or month
    $allCols3 = $store->load('payment_collections.json');
    $allRet3 = $store->load('retailers.json');
    $allRecharges3 = $store->load('wallet_recharge_requests.json') ?: [];

    if ($settleMode === 'month') {
        $settlePrefix = substr($settleDate, 0, 7);
        $periodLabel = date('F Y', strtotime($settleDate));
    } else {
        $settlePrefix = $settleDate;
        $periodLabel = date('D, M j, Y', strtotime($settleDate));
    }

    $periodCols = array_filter($allCols3, fn($c)=>str_starts_with($c['created_at']??'',$settlePrefix));
    $periodRecharges = array_filter($allRecharges3, fn($r)=>($r['status']??'')==='approved'&&str_starts_with($r['approved_at']??'',$settlePrefix));

    // Build per-retailer breakdown
    $retailerMap = [];
    foreach ($allRet3 as $r3) { $retailerMap[(int)$r3['id']] = $r3; }

    $breakdown = [];
    foreach ($periodCols as $pc) {
        $rId3 = (int)($pc['retailer_id']??0);
        if (!isset($breakdown[$rId3])) {
            $rn = $retailerMap[$rId3]['name'] ?? 'Unknown';
            $rBal = (float)($retailerMap[$rId3]['wallet'] ?? 0);
            $breakdown[$rId3] = ['name'=>$rn,'balance'=>$rBal,'collected'=>0,'commission'=>0,'net'=>0,'count'=>0,'methods'=>[]];
        }
        $amt = (float)($pc['amount']??0);
        $com = (float)($pc['commission']??0);
        $mth = $pc['method']??'Cash';
        $breakdown[$rId3]['collected'] += $amt;
        $breakdown[$rId3]['commission'] += $com;
        $breakdown[$rId3]['net'] += ($amt - $com);
        $breakdown[$rId3]['count']++;
        $breakdown[$rId3]['methods'][$mth] = ($breakdown[$rId3]['methods'][$mth] ?? 0) + $amt;
    }

    // Add recharge totals per retailer
    foreach ($periodRecharges as $pr) {
        $rId3 = (int)($pr['retailer_id']??0);
        if (!isset($breakdown[$rId3])) {
            $rn = $retailerMap[$rId3]['name'] ?? 'Unknown';
            $rBal = (float)($retailerMap[$rId3]['wallet'] ?? 0);
            $breakdown[$rId3] = ['name'=>$rn,'balance'=>$rBal,'collected'=>0,'commission'=>0,'net'=>0,'count'=>0,'methods'=>[]];
        }
        $breakdown[$rId3]['recharged'] = ($breakdown[$rId3]['recharged']??0) + ($pr['amount']??0);
    }

    // Sort by collected desc
    uasort($breakdown, fn($a,$b) => $b['collected'] <=> $a['collected']);

    $grandCollected = array_sum(array_column($breakdown,'collected'));
    $grandCommission = array_sum(array_column($breakdown,'commission'));
    $grandNet = $grandCollected - $grandCommission;
    $grandRecharged = array_sum(array_map(fn($r)=>$r['amount']??0, $periodRecharges));
    $totalAgents = count($breakdown);

    // Cash method breakdown
    $cashTotal = 0; $mobileTotal = 0; $bankTotal = 0;
    foreach ($periodCols as $pc2) {
        $m2 = $pc2['method']??'Cash'; $a2 = (float)($pc2['amount']??0);
        if ($m2==='Cash') $cashTotal+=$a2;
        elseif ($m2==='Mobile Money') $mobileTotal+=$a2;
        else $bankTotal+=$a2;
    }
    ?>

<style>
.stl-filter{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;}
.stl-filter input,.stl-filter select,.stl-filter button{padding:8px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;background:#fff;}
.stl-filter button{background:#6A1B9A;color:#fff;border:none;font-weight:700;cursor:pointer;}
.stl-hero{background:linear-gradient(145deg,#4A148C,#6A1B9A);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;position:relative;overflow:hidden;}
.stl-hero::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.06);}
.stl-grid4{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:14px;}
.stl-pill{background:rgba(255,255,255,.12);border-radius:12px;padding:10px 12px;}
.stl-pill-v{font-size:20px;font-weight:800;}
.stl-pill-l{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.5);font-weight:700;margin-top:2px;}
.stl-card{background:#fff;border-radius:14px;padding:16px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #f1f5f9;}
.stl-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f8fafc;font-size:13px;}
.stl-row:last-child{border-bottom:none;}
.stl-agent{background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:8px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #f1f5f9;}
.stl-agent-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.stl-agent-name{font-size:14px;font-weight:800;color:#1e293b;}
.stl-agent-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:6px;}
.stl-agent-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;}
.stl-agent-stat{text-align:center;padding:8px 4px;border-radius:8px;background:#f8fafc;}
.stl-agent-stat-v{font-size:15px;font-weight:800;}
.stl-agent-stat-l{font-size:9px;color:#9ca3af;font-weight:700;text-transform:uppercase;margin-top:2px;}
@media(max-width:600px){.stl-grid4{grid-template-columns:1fr 1fr;}.stl-agent-grid{grid-template-columns:1fr 1fr 1fr;}}
</style>

<div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:14px;"><i class="bi bi-clipboard-data" style="color:#6A1B9A;margin-right:6px;"></i>Daily Settlement Report</div>

<!-- Date Filter -->
<form class="stl-filter" method="GET">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab" value="accounts_settlement">
    <input type="date" name="sd" value="<?= h($settleDate) ?>" style="flex:1;min-width:130px;">
    <select name="sm">
        <option value="day" <?= $settleMode==='day'?'selected':'' ?>>Day</option>
        <option value="month" <?= $settleMode==='month'?'selected':'' ?>>Month</option>
    </select>
    <button type="submit"><i class="bi bi-funnel"></i> Filter</button>
</form>
<form method="POST" style="display:flex;margin-bottom:16px;">
    <?= csrfField() ?>
    <input type="hidden" name="action"      value="export_csv">
    <input type="hidden" name="export_type" value="settlement">
    <input type="hidden" name="exp_date"    value="<?= h($settleDate) ?>">
    <input type="hidden" name="exp_mode"    value="<?= h($settleMode) ?>">
    <button type="submit" style="background:linear-gradient(135deg,#4A148C,#6A1B9A);color:#fff;border:none;border-radius:10px;padding:8px 18px;font-size:12px;font-weight:800;cursor:pointer;">
        📥 Export Settlement CSV
    </button>
</form>

<!-- Settlement Hero -->
<div class="stl-hero">
    <div style="font-size:11px;color:rgba(255,255,255,.5);font-weight:700;text-transform:uppercase;letter-spacing:1px;">Settlement &mdash; <?= h($periodLabel) ?></div>
    <div style="font-size:24px;font-weight:800;margin-top:4px;">$<?= number_format($grandCollected,2) ?> Collected</div>
    <div class="stl-grid4">
        <div class="stl-pill"><div class="stl-pill-l">Net Payable</div><div class="stl-pill-v" style="color:#69f0ae;">$<?= number_format($grandNet,2) ?></div></div>
        <div class="stl-pill"><div class="stl-pill-l">Commission Paid</div><div class="stl-pill-v" style="color:#ffab40;">$<?= number_format($grandCommission,2) ?></div></div>
        <div class="stl-pill"><div class="stl-pill-l">Wallet Recharges</div><div class="stl-pill-v">$<?= number_format($grandRecharged,2) ?></div></div>
        <div class="stl-pill"><div class="stl-pill-l">Active Agents</div><div class="stl-pill-v"><?= $totalAgents ?></div></div>
    </div>
</div>

<!-- Collection by Method -->
<div class="stl-card">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:6px;"><i class="bi bi-credit-card" style="color:#D41C1C;"></i> Collection by Method</div>
    <div class="stl-row"><span>&#128181; Cash</span><strong style="color:#28a745;">$<?= number_format($cashTotal,2) ?></strong></div>
    <div class="stl-row"><span>&#128241; Mobile Money</span><strong style="color:#D41C1C;">$<?= number_format($mobileTotal,2) ?></strong></div>
    <div class="stl-row"><span>&#127974; Bank Transfer</span><strong style="color:#6A1B9A;">$<?= number_format($bankTotal,2) ?></strong></div>
    <?php if($grandCollected>0): ?>
    <div style="margin-top:10px;display:flex;height:8px;border-radius:4px;overflow:hidden;">
        <?php if($cashTotal>0): ?><div style="background:#28a745;width:<?= round($cashTotal/$grandCollected*100) ?>%;"></div><?php endif; ?>
        <?php if($mobileTotal>0): ?><div style="background:#D41C1C;width:<?= round($mobileTotal/$grandCollected*100) ?>%;"></div><?php endif; ?>
        <?php if($bankTotal>0): ?><div style="background:#6A1B9A;width:<?= round($bankTotal/$grandCollected*100) ?>%;"></div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Reconciliation Check -->
<div class="stl-card">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:6px;"><i class="bi bi-check2-circle" style="color:#28a745;"></i> Reconciliation</div>
    <div class="stl-row"><span>Total Collected from Customers</span><strong>$<?= number_format($grandCollected,2) ?></strong></div>
    <div class="stl-row"><span>Commission Retained by Agents</span><strong style="color:#E65100;">- $<?= number_format($grandCommission,2) ?></strong></div>
    <div class="stl-row" style="border-top:2px solid #e2e8f0;padding-top:10px;"><span style="font-weight:700;">Amount Due to DishNet</span><strong style="color:#28a745;font-size:16px;">$<?= number_format($grandNet,2) ?></strong></div>
    <div class="stl-row"><span>Wallet Recharges (Deposits Received)</span><strong style="color:#D41C1C;">$<?= number_format($grandRecharged,2) ?></strong></div>
    <?php $shortfall = $grandNet - $grandRecharged; ?>
    <?php if($shortfall > 0.01): ?>
    <div class="stl-row" style="background:#FFF3E0;margin:6px -16px -16px;padding:10px 16px;border-radius:0 0 14px 14px;"><span style="font-weight:700;color:#E65100;">&#9888; Outstanding (Cash Not Yet Deposited)</span><strong style="color:#E65100;font-size:15px;">$<?= number_format($shortfall,2) ?></strong></div>
    <?php elseif($shortfall < -0.01): ?>
    <div class="stl-row" style="background:#E8F5E9;margin:6px -16px -16px;padding:10px 16px;border-radius:0 0 14px 14px;"><span style="font-weight:700;color:#28a745;">&#9989; Over-deposited (Credit)</span><strong style="color:#28a745;font-size:15px;">$<?= number_format(abs($shortfall),2) ?></strong></div>
    <?php else: ?>
    <div class="stl-row" style="background:#E8F5E9;margin:6px -16px -16px;padding:10px 16px;border-radius:0 0 14px 14px;"><span style="font-weight:700;color:#28a745;">&#9989; Fully Settled</span><strong style="color:#28a745;">$0.00</strong></div>
    <?php endif; ?>
</div>

<!-- ── Pending Expenses (Rupesh approval queue) ──────────────────── -->
<?php
if (!isset($_expGw)) { require_once dirname(__DIR__, 2) . '/lib/ExpenseGateway.php'; $_expGw = new ExpenseGateway($store); }
$_allPendingExps = $_expGw->getAll(['status' => 'pending']);
$_allPendingExps = array_reverse(array_values($_allPendingExps));
$_allPendingHovs = array_filter($store->load('cash_handovers.json')??[], fn($h)=>($h['status']??'')==='pending');
$_allPendingHovs = array_reverse(array_values($_allPendingHovs));
?>

<?php if (!empty($_allPendingExps)): ?>
<div style="background:#FFF7ED;border-radius:16px;border:2px solid #FED7AA;padding:16px;margin-bottom:14px;">
  <div style="font-size:14px;font-weight:800;color:#c2410c;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
    <i class="bi bi-receipt"></i> Pending Expenses
    <span style="background:#FED7AA;color:#c2410c;border-radius:20px;padding:1px 10px;font-size:11px;"><?= count($_allPendingExps) ?> awaiting approval</span>
  </div>
  <div style="font-size:13px;color:#78350f;margin-bottom:12px;">
    Expense approvals have moved to the unified <strong>Cashbook</strong> pending queue — all entry types (expenses, handovers, collections) are reviewed in one place.
  </div>
  <a href="?page=dashboard&tab=cashbook&cb_view=pending"
    style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:linear-gradient(135deg,#c2410c,#ea580c);color:#fff;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none;">
    <i class="bi bi-journal-bookmark-fill"></i> Review <?= count($_allPendingExps) ?> pending <?= count($_allPendingExps)===1?'expense':'expenses' ?> in Cashbook →
  </a>
</div>
<?php endif; ?>

<!-- ── Pending Handovers — FieldAgentService queue ──────────────── -->
<?php
$_faAlert = null;
try {
    require_once dirname(__DIR__, 2) . '/lib/FieldAgentService.php';
    $_faAlertSvc = new FieldAgentService($store);
    $_faPending  = $_faAlertSvc->getRemittances(0, true, 'pending');
    $_faPendCnt  = count($_faPending);
    $_faPendAmt  = array_sum(array_map(fn($r)=>(float)($r['amount']??0), $_faPending));
} catch (Throwable $e) { $_faPendCnt = 0; $_faPendAmt = 0; $_faPending = []; }
?>
<?php if ($_faPendCnt > 0): ?>
<div style="background:#FFFBEB;border-radius:16px;border:2px solid #FDE68A;padding:16px;margin-bottom:14px;">
  <div style="font-size:14px;font-weight:800;color:#B45309;margin-bottom:8px;display:flex;align-items:center;gap:8px;">
    💵 Pending Cash Handovers
    <span style="background:#FDE68A;color:#B45309;border-radius:20px;padding:1px 10px;font-size:11px;"><?= $_faPendCnt ?> awaiting approval</span>
  </div>
  <div style="font-size:13px;color:#92400E;margin-bottom:4px;">
    <?php foreach (array_slice($_faPending, 0, 3) as $_fp): ?>
    <span style="display:inline-flex;align-items:center;gap:4px;background:#FEF3C7;border-radius:8px;padding:3px 10px;margin:0 4px 4px 0;font-weight:700;">
      <?= htmlspecialchars($_fp['agent_name']??'Agent') ?> — $<?= number_format((float)($_fp['amount']??0),2) ?>
    </span>
    <?php endforeach; ?>
    <?php if ($_faPendCnt > 3): ?><span style="font-size:12px;color:#B45309;">+<?= $_faPendCnt-3 ?> more</span><?php endif; ?>
  </div>
  <div style="font-size:12px;color:#B45309;margin-bottom:12px;">Total: <strong>$<?= number_format($_faPendAmt,2) ?></strong></div>
  <a href="?page=dashboard&tab=handover_queue"
    style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:linear-gradient(135deg,#D97706,#B45309);color:#fff;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none;">
    💵 Review <?= $_faPendCnt ?> pending <?= $_faPendCnt===1?'handover':'handovers' ?> →
  </a>
</div>
<?php endif; ?>
<?php if (!empty($_allPendingHovs)): ?>
<div style="background:#F5F3FF;border-radius:16px;border:2px solid #DDD6FE;padding:16px;margin-bottom:14px;">
  <div style="font-size:14px;font-weight:800;color:#5b21b6;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
    <i class="bi bi-bag-check"></i> Legacy Pending Handovers
    <span style="background:#DDD6FE;color:#5b21b6;border-radius:20px;padding:1px 10px;font-size:11px;"><?= count($_allPendingHovs) ?> awaiting confirmation</span>
  </div>
  <a href="?page=dashboard&tab=cashbook&cb_view=pending"
    style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;background:linear-gradient(135deg,#4A148C,#6A1B9A);color:#fff;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none;">
    <i class="bi bi-journal-bookmark-fill"></i> Confirm <?= count($_allPendingHovs) ?> in Cashbook →
  </a>
</div>
<?php endif; ?>

<!-- ── Cash Custody Summary (all collectors) ─────────────────────── -->
<?php
$_allCollectors = array_filter($store->load('retailers.json')??[], fn($r)=>in_array($r['role']??'sales',['sales','collection']));
$_allColData    = $store->load('payment_collections.json')??[];
$_allExpData    = $_expGw->getAll(['status' => 'approved']);
$_allHovData    = array_filter($store->load('cash_handovers.json')??[], fn($h)=>($h['status']??'')==='confirmed');
$_custodyRows   = [];
foreach ($_allCollectors as $_col) {
    $_cid  = (int)($_col['id']??0);
    $_cCols= array_filter($_allColData, fn($c)=>(int)($c['retailer_id']??0)===$_cid);
    $_cExps= array_filter($_allExpData, fn($e)=>(int)($e['staff_id']??0)===$_cid);
    $_cHovs= array_filter($_allHovData, fn($h)=>(int)($h['from_id']??0)===$_cid);
    $_cih  = array_sum(array_column(array_values($_cCols),'amount'))
           - array_sum(array_column(array_values($_cExps),'amount'))
           - array_sum(array_column(array_values($_cHovs),'amount'));
    if (abs($_cih) < 0.005) continue; // skip zero-balance collectors
    $_custodyRows[] = ['name'=>$_col['name']??'', 'cih'=>$_cih,
        'collected'=>array_sum(array_column(array_values($_cCols),'amount')),
        'expenses' =>array_sum(array_column(array_values($_cExps),'amount')),
        'handedover'=>array_sum(array_column(array_values($_cHovs),'amount')),
    ];
}
usort($_custodyRows, fn($a,$b)=>$b['cih']<=>$a['cih']);
?>
<?php if (!empty($_custodyRows)): ?>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:16px;margin-bottom:14px;">
  <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
    <i class="bi bi-cash-stack" style="color:#F57F17;"></i> Cash Custody Summary
    <span style="font-size:11px;color:#9ca3af;font-weight:600;margin-left:auto;">All-time balances</span>
  </div>
  <div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;font-size:12px;">
  <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
    <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Collector</th>
    <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Collected</th>
    <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Expenses</th>
    <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Handed Over</th>
    <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:800;color:#F57F17;text-transform:uppercase;">Cash in Hand</th>
  </tr></thead>
  <tbody>
  <?php foreach ($_custodyRows as $_cr):
    $_cihColor = $_cr['cih'] > 50 ? '#c2410c' : ($_cr['cih'] > 0 ? '#E65100' : '#16a34a');
  ?>
  <tr style="border-bottom:1px solid #f1f5f9;">
    <td style="padding:9px 10px;font-weight:800;color:#1e293b;"><?= h($_cr['name']) ?></td>
    <td style="padding:9px 10px;text-align:right;color:#374151;">$<?= number_format($_cr['collected'],2) ?></td>
    <td style="padding:9px 10px;text-align:right;color:#c2410c;">$<?= number_format($_cr['expenses'],2) ?></td>
    <td style="padding:9px 10px;text-align:right;color:#16a34a;">$<?= number_format($_cr['handedover'],2) ?></td>
    <td style="padding:9px 10px;text-align:right;font-weight:900;font-size:14px;color:<?= $_cr['cih']>0?'#c2410c':'#16a34a' ?>;">
      $<?= number_format(abs($_cr['cih']),2) ?><?= $_cr['cih']<0?' (over)':'' ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<!-- Per-Agent Breakdown -->
<div style="font-size:13px;font-weight:800;color:#1e293b;margin:16px 0 10px;display:flex;align-items:center;gap:6px;">
    <i class="bi bi-people" style="color:#6b7280;"></i> Agent Breakdown
    <span style="font-size:10px;font-weight:600;color:#9ca3af;margin-left:auto;"><?= $totalAgents ?> agents</span>
</div>

<?php if(empty($breakdown)): ?>
<div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px;">No collections for <?= h($periodLabel) ?></div>
<?php else: ?>
<?php foreach ($breakdown as $bRid => $bd): ?>
<div class="stl-agent">
    <div class="stl-agent-head">
        <div class="stl-agent-name"><?= h($bd['name']) ?></div>
        <span class="stl-agent-badge" style="background:#E3F2FD;color:#D41C1C;">Wallet: $<?= number_format($bd['balance'],2) ?></span>
    </div>
    <div class="stl-agent-grid">
        <div class="stl-agent-stat">
            <div class="stl-agent-stat-v" style="color:#28a745;">$<?= number_format($bd['collected'],2) ?></div>
            <div class="stl-agent-stat-l">Collected</div>
        </div>
        <div class="stl-agent-stat">
            <div class="stl-agent-stat-v" style="color:#E65100;">$<?= number_format($bd['commission'],2) ?></div>
            <div class="stl-agent-stat-l">Commission</div>
        </div>
        <div class="stl-agent-stat">
            <div class="stl-agent-stat-v" style="color:#D41C1C;">$<?= number_format($bd['net'],2) ?></div>
            <div class="stl-agent-stat-l">Net Payable</div>
        </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
        <span style="font-size:10px;color:#6b7280;background:#f8fafc;padding:2px 8px;border-radius:6px;"><?= $bd['count'] ?> payments</span>
        <?php foreach ($bd['methods'] as $mName=>$mAmt): ?>
        <span style="font-size:10px;color:#6b7280;background:#f8fafc;padding:2px 8px;border-radius:6px;"><?= h($mName) ?>: $<?= number_format($mAmt,2) ?></span>
        <?php endforeach; ?>
        <?php if(($bd['recharged']??0)>0): ?>
        <span style="font-size:10px;color:#28a745;background:#E8F5E9;padding:2px 8px;border-radius:6px;">Recharged: $<?= number_format($bd['recharged'],2) ?></span>
        <?php endif; ?>
    </div>
    <?php
    // Per-agent settlement status
    $agentNet = $bd['net'];
    $agentRech = $bd['recharged']??0;
    $agentOwes = $agentNet - $agentRech;
    ?>
    <?php if($agentOwes > 0.01): ?>
    <div style="margin-top:8px;padding:6px 10px;background:#FFF3E0;border-radius:8px;font-size:11px;font-weight:700;color:#E65100;display:flex;justify-content:space-between;">
        <span>&#9888; Owes DishNet</span><span>$<?= number_format($agentOwes,2) ?></span>
    </div>
    <?php elseif($agentOwes < -0.01): ?>
    <div style="margin-top:8px;padding:6px 10px;background:#E8F5E9;border-radius:8px;font-size:11px;font-weight:700;color:#28a745;display:flex;justify-content:space-between;">
        <span>&#9989; Over-deposited</span><span>$<?= number_format(abs($agentOwes),2) ?></span>
    </div>
    <?php else: ?>
    <div style="margin-top:8px;padding:6px 10px;background:#E8F5E9;border-radius:8px;font-size:11px;font-weight:700;color:#28a745;text-align:center;">&#9989; Settled</div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- CRM Sync Status -->
<div class="stl-card">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:6px;"><i class="bi bi-arrow-repeat" style="color:#9C27B0;"></i> CRM Sync Status</div>
    <?php
    $syncedCount = count(array_filter($periodCols, fn($c)=>!empty($c['crm_synced'])));
    $pendingSync = count($periodCols) - $syncedCount;
    ?>
    <div class="stl-row"><span>Synced to CRM</span><strong style="color:#28a745;"><?= $syncedCount ?> / <?= count($periodCols) ?></strong></div>
    <?php if($pendingSync>0): ?>
    <div class="stl-row"><span style="color:#E65100;">&#9888; Pending Sync</span><strong style="color:#E65100;"><?= $pendingSync ?> payments</strong></div>
    <?php endif; ?>
</div>

<div style="height:80px;"></div>



