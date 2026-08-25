<?php
// ── Access gate: accountant or admin only ──
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}
// Tab: accounts_ledger
// Extracted from public.php on 2026-03-15
    // ──────────────────────────────────────────────────────────────────────
    // RETAILER LEDGER — Per-retailer bank-statement style
    // Shows: recharges in, collections out, commissions, wallet movements
    // ──────────────────────────────────────────────────────────────────────
    $allRetL = $store->load('retailers.json');
    $selRetailer = (int)($_GET['rid'] ?? 0);
    // Default to first sales/active retailer if none selected
    if (!$selRetailer) {
        foreach ($allRetL as $rl) {
            if (!empty($rl['is_active']) && ($rl['role']??'sales')==='sales') { $selRetailer=(int)$rl['id']; break; }
        }
    }
    $selR = null;
    foreach ($allRetL as $rl) { if ((int)($rl['id']??0)===$selRetailer) { $selR=$rl; break; } }

    // Load all data for this retailer
    $rPassbook = $selRetailer ? $wallet->getPassbook($selRetailer, 500) : [];
    $rCollections = $selRetailer ? $store->findAll('payment_collections.json','retailer_id',$selRetailer) : [];
    $rRecharges = $selRetailer ? array_filter($store->load('wallet_recharge_requests.json'),fn($r)=>(int)($r['retailer_id']??0)===$selRetailer) : [];
    $rBalance = $selRetailer ? $wallet->getBalance($selRetailer) : 0;
    $rSummary = $selRetailer ? $wallet->getSummary($selRetailer) : ['total_credit'=>0,'total_debit'=>0,'transactions'=>0,'balance'=>0];

    // Compute cash-in-hand metrics
    $totalCollected = array_sum(array_map(fn($c)=>$c['amount']??0, $rCollections));
    $totalCommission = array_sum(array_map(fn($c)=>$c['commission']??0, $rCollections));
    $totalRecharged = array_sum(array_map(fn($r)=>($r['status']??'')==='approved'?($r['amount']??0):0, $rRecharges));
    $netPayable = $totalCollected - $totalCommission; // Amount agent owes DishNet (gross collected minus their commission earned)

    // Monthly breakdown
    $ledgerMonth = $_GET['lm'] ?? date('Y-m');
    $mCollections = array_filter($rCollections, fn($c)=>str_starts_with($c['created_at']??'',$ledgerMonth));
    $mPassbook = array_filter($rPassbook, fn($p)=>str_starts_with($p['created_at']??'',$ledgerMonth));
    $mCollTotal = array_sum(array_map(fn($c)=>$c['amount']??0, $mCollections));
    $mCommTotal = array_sum(array_map(fn($c)=>$c['commission']??0, $mCollections));
    $mNetPayable = $mCollTotal - $mCommTotal;
    $mRecharged = array_sum(array_map(fn($r)=>($r['status']??'')==='approved'&&str_starts_with($r['approved_at']??'',$ledgerMonth)?($r['amount']??0):0, $rRecharges));
    ?>

<style>
.ldg-select{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
.ldg-select select,.ldg-select input{padding:8px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;background:#fff;}
.ldg-hero{border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;position:relative;overflow:hidden;}
.ldg-hero::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.06);}
.ldg-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-top:14px;}
.ldg-pill{background:rgba(255,255,255,.12);border-radius:12px;padding:10px 12px;}
.ldg-pill-v{font-size:20px;font-weight:800;}
.ldg-pill-l{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.5);font-weight:700;margin-top:2px;}
.ldg-card{background:#fff;border-radius:14px;padding:16px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #f1f5f9;}
.ldg-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f8fafc;font-size:13px;}
.ldg-row:last-child{border-bottom:none;}
.ldg-entry{background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.03);border:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;}
.ldg-entry-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.ldg-entry-info{flex:1;min-width:0;}
.ldg-entry-desc{font-size:13px;font-weight:600;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ldg-entry-meta{font-size:10px;color:#9ca3af;margin-top:2px;}
.ldg-entry-amt{text-align:right;flex-shrink:0;}
.ldg-entry-val{font-size:15px;font-weight:800;}
.ldg-entry-bal{font-size:10px;color:#9ca3af;margin-top:1px;}
@media(max-width:600px){.ldg-grid{grid-template-columns:1fr 1fr;}}
</style>

<div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:14px;"><i class="bi bi-journal-text" style="color:#1565C0;margin-right:6px;"></i>Retailer Ledger</div>

<!-- Retailer & Month Selector -->
<div class="ldg-select">
    <select onchange="window.location='?page=dashboard&tab=accounts_ledger&rid='+this.value+'&lm=<?= h($ledgerMonth) ?>'" style="flex:1;min-width:140px;">
        <option value="">— Select Retailer —</option>
        <?php foreach ($allRetL as $rl):
            if(empty($rl['is_active'])) continue;
            $rRole = $rl['role']??'sales';
            if(in_array($rRole,['admin','accountant','support'])) continue; // skip non-field staff
        ?>
        <option value="<?= $rl['id'] ?>" <?= $selRetailer===(int)$rl['id']?'selected':'' ?>>
          <?= h($rl['name']) ?> (<?= h($rRole) ?>)
        </option>
        <?php endforeach; ?>
    </select>
    <input type="month" value="<?= h($ledgerMonth) ?>" onchange="window.location='?page=dashboard&tab=accounts_ledger&rid=<?= $selRetailer ?>&lm='+this.value" style="min-width:130px;">
    <?php if ($selRetailer): ?>
    <form method="POST" style="display:flex;">
        <?= csrfField() ?>
        <input type="hidden" name="action"      value="export_csv">
        <input type="hidden" name="export_type" value="ledger">
        <input type="hidden" name="exp_rid"     value="<?= $selRetailer ?>">
        <input type="hidden" name="exp_month"   value="<?= h($ledgerMonth) ?>">
        <button type="submit" style="background:#1A1A1A;color:#fff;border:none;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;">
            📥 Export CSV
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if ($selR): ?>
<?php
// ── Current view (MUST be first — used by everything below)
$_ledgerView = $_GET['lv'] ?? 'statement';

// ── Load KYC apps for this retailer
// Use getApplications with admin=true to get ALL apps, then filter by selRetailer
$_allApps      = $kyc->getApplications(0, true); // admin=true returns all
$_retailerApps = array_values(array_filter($_allApps,
    fn($a) => (int)($a['retailer_id'] ?? 0) === (int)$selRetailer
));
// Already newest-first from getApplications, but re-sort to be safe
usort($_retailerApps, fn($a,$b) => strcmp($b['created_at']??'', $a['created_at']??''));

// ── Monthly counts for hero pills
$_monthCounts = [];
foreach ($_retailerApps as $_ma) {
    $mo = substr($_ma['created_at']??'',0,7);
    if ($mo) $_monthCounts[$mo] = ($_monthCounts[$mo]??0)+1;
}
krsort($_monthCounts);
$_mRegistered     = $_monthCounts[$ledgerMonth] ?? 0;
$_totalRegistered = count($_retailerApps);

// ── Sales Person Index — built nightly by main.php auto-pull
// Maps retailer/sales-person name → array of UCRM client records with attribution
$_spIndex   = $store->load('sp_client_index.json') ?? [];
$_spSummary = $store->load('sp_summary.json')      ?? [];
$_spName    = trim($selR['name'] ?? '');
// Match index: try exact name, then strip "DishNet" suffix
$_spClients = $_spIndex[$_spName] ?? [];
if (empty($_spClients)) {
    $shortName = preg_replace('/\s+dishnet\s*$/i', '', $_spName);
    $_spClients = $_spIndex[$shortName] ?? [];
    // Also try first word only (e.g. "Mecklyine")
    if (empty($_spClients)) {
        $firstWord = explode(' ', $shortName)[0];
        foreach ($_spIndex as $_spKey => $_spVal) {
            if (stripos($_spKey, $firstWord) === 0) { $_spClients = $_spVal; break; }
        }
    }
}
$_spIndexLastPull = $store->load('ucrm_pull_last_run.json')['ran_at'] ?? null;
// CRM detail cache — no longer needed (data in sp_client_index already)
$_crmDetailCache = [];
?>

<!-- Sub-tab toggle — ALWAYS visible at top -->
<div style="display:flex;gap:6px;margin-bottom:12px;background:#f1f5f9;border-radius:10px;padding:4px;">
  <a href="?page=dashboard&tab=accounts_ledger&rid=<?= $selRetailer ?>&lm=<?= h($ledgerMonth) ?>&lv=statement"
     style="flex:1;text-align:center;padding:8px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;
            background:<?= ($_ledgerView??'statement')!=='customers'?'#fff':'transparent' ?>;
            color:<?= ($_ledgerView??'statement')!=='customers'?'#1565C0':'#6b7280' ?>;
            box-shadow:<?= ($_ledgerView??'statement')!=='customers'?'0 1px 4px rgba(0,0,0,.08)':'none' ?>;">
    📒 Statement
  </a>
  <a href="?page=dashboard&tab=accounts_ledger&rid=<?= $selRetailer ?>&lm=<?= h($ledgerMonth) ?>&lv=customers"
     style="flex:1;text-align:center;padding:8px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;
            background:<?= ($_ledgerView??'statement')==='customers'?'#fff':'transparent' ?>;
            color:<?= ($_ledgerView??'statement')==='customers'?'#1565C0':'#6b7280' ?>;
            box-shadow:<?= ($_ledgerView??'statement')==='customers'?'0 1px 4px rgba(0,0,0,.08)':'none' ?>;">
    👥 Customers
    <?php if ($_totalRegistered > 0): ?>
      <span style="background:#D41C1C;color:#fff;border-radius:20px;padding:1px 7px;font-size:10px;margin-left:4px;"><?= $_totalRegistered ?></span>
    <?php endif; ?>
  </a>
</div>

<?php if (($_ledgerView??'statement') !== 'customers'): ?>
<!-- Retailer Hero — only shown on Statement tab -->
<div class="ldg-hero" style="background:linear-gradient(145deg,#1565C0,#1E88E5);">
    <div style="font-size:11px;color:rgba(255,255,255,.5);font-weight:700;text-transform:uppercase;letter-spacing:1px;"><?= h($selR['name']) ?> &mdash; <?= date('F Y', strtotime($ledgerMonth.'-01')) ?></div>
    <div style="font-size:24px;font-weight:800;margin-top:4px;">Wallet: $<?= number_format($rBalance,2) ?></div>
    <div class="ldg-grid">
        <div class="ldg-pill"><div class="ldg-pill-l">Month Collected</div><div class="ldg-pill-v">$<?= number_format($mCollTotal,2) ?></div></div>
        <div class="ldg-pill"><div class="ldg-pill-l">Commission Earned</div><div class="ldg-pill-v" style="color:#ffab40;">$<?= number_format($mCommTotal,2) ?></div></div>
        <div class="ldg-pill"><div class="ldg-pill-l">Customers This Month</div><div class="ldg-pill-v" style="color:#69f0ae;"><?= $_mRegistered ?></div></div>
        <div class="ldg-pill"><div class="ldg-pill-l">Total Registered</div><div class="ldg-pill-v" style="color:#b2ebf2;"><?= $_totalRegistered ?></div></div>
    </div>
</div>
<?php endif; ?>

<?php if ($_ledgerView !== 'customers'): ?>
<!-- Account Summary Card -->
<div class="ldg-card">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:6px;"><i class="bi bi-calculator" style="color:#D41C1C;"></i> Account Summary (All Time)</div>
    <div class="ldg-row"><span>Total Wallet Recharged</span><strong style="color:#28a745;">$<?= number_format($totalRecharged,2) ?></strong></div>
    <div class="ldg-row"><span>Total Cash Collected</span><strong style="color:#D41C1C;">$<?= number_format($totalCollected,2) ?></strong></div>
    <div class="ldg-row"><span>Total Commission Earned</span><strong style="color:#E65100;">$<?= number_format($totalCommission,2) ?></strong></div>
    <div class="ldg-row"><span>Net Payable to DishNet</span><strong style="color:#dc3545;">$<?= number_format($netPayable,2) ?></strong></div>
    <div class="ldg-row" style="border-top:2px solid #e2e8f0;padding-top:10px;"><span style="font-weight:700;">Current Wallet Balance</span><strong style="color:#D41C1C;font-size:16px;">$<?= number_format($rBalance,2) ?></strong></div>
    <div class="ldg-row"><span>Cash-in-Hand Estimate</span><strong style="color:#6A1B9A;">$<?= number_format(max(0, $totalCollected - $totalRecharged),2) ?></strong></div>
</div>

<!-- Monthly Cash Flow Card -->
<div class="ldg-card">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;display:flex;align-items:center;gap:6px;"><i class="bi bi-arrow-left-right" style="color:#28a745;"></i> Cash Flow — <?= date('F Y', strtotime($ledgerMonth.'-01')) ?></div>
    <div class="ldg-row"><span style="color:#28a745;">&#8593; Wallet Recharged</span><strong style="color:#28a745;">$<?= number_format($mRecharged,2) ?></strong></div>
    <div class="ldg-row"><span style="color:#D41C1C;">&#8593; Commission Credited</span><strong style="color:#D41C1C;">$<?= number_format($mCommTotal,2) ?></strong></div>
    <div class="ldg-row"><span style="color:#dc3545;">&#8595; Collections Debited</span><strong style="color:#dc3545;">$<?= number_format($mCollTotal,2) ?></strong></div>
    <?php
    $mKycDebits = array_sum(array_map(fn($p)=>(($p['trx_type']??'')==='order_payment'&&($p['entry_type']??'')==='debit')?($p['amount']??0):0, $mPassbook));
    $mSimDebits = array_sum(array_map(fn($p)=>(($p['trx_type']??'')==='sim_activation')?($p['amount']??0):0, $mPassbook));
    ?>
    <?php if($mKycDebits>0): ?><div class="ldg-row"><span style="color:#9C27B0;">&#8595; KYC Payments</span><strong style="color:#9C27B0;">$<?= number_format($mKycDebits,2) ?></strong></div><?php endif; ?>
    <?php /* SIM Activations ledger line hidden */ ?>
    <div class="ldg-row" style="border-top:2px solid #e2e8f0;padding-top:10px;">
        <span style="font-weight:700;">Net Cash Flow</span>
        <?php $netFlow = $mRecharged + $mCommTotal - $mCollTotal - $mKycDebits - $mSimDebits; ?>
        <strong style="color:<?= $netFlow>=0?'#28a745':'#dc3545' ?>;font-size:15px;"><?= $netFlow>=0?'+':'' ?>$<?= number_format($netFlow,2) ?></strong>
    </div>
</div>

<!-- Passbook (Statement) -->
<div style="font-size:13px;font-weight:800;color:#1e293b;margin:16px 0 10px;display:flex;align-items:center;gap:6px;">
    <i class="bi bi-list-ul" style="color:#6b7280;"></i> Statement — <?= date('F Y', strtotime($ledgerMonth.'-01')) ?>
    <span style="font-size:10px;font-weight:600;color:#9ca3af;margin-left:auto;"><?= count($mPassbook) ?> entries</span>
</div>

<?php if(empty($mPassbook)): ?>
<div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px;">No transactions in <?= date('F Y', strtotime($ledgerMonth.'-01')) ?></div>
<?php else: ?>
<?php
$typeIcons = [
    'topup'=>['&#128179;','#E8F5E9'],'order_payment'=>['&#128203;','#E3F2FD'],
    'commission'=>['&#11088;','#FFF8E1'],'reversal'=>['&#128260;','#FFF3E0'],
    'sim_activation'=>['&#128225;','#F3E5F5'],'adjustment'=>['&#9881;','#f1f5f9'],
    'bundle_recharge'=>['&#128225;','#E8F5E9'],'payment_collected'=>['&#128181;','#E8F5E9'],
];
// Build passbook table
$pbRows = array_reverse($mPassbook);
$pbDebit = array_sum(array_map(fn($p)=>strtolower($p['entry_type']??'debit')!=='credit'?($p['amount']??0):0, $pbRows));
$pbCredit= array_sum(array_map(fn($p)=>strtolower($p['entry_type']??'debit')==='credit'?($p['amount']??0):0, $pbRows));
?>
<?php if (!empty($pbRows)): ?>
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:10px;">
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;font-size:12px;">
<thead>
<tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Date</th>
    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Description</th>
    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Trx #</th>
    <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:800;color:#16a34a;text-transform:uppercase;letter-spacing:.5px;">Credit</th>
    <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:800;color:#dc3545;text-transform:uppercase;letter-spacing:.5px;">Debit</th>
    <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:800;color:#D41C1C;text-transform:uppercase;letter-spacing:.5px;">Balance</th>
</tr>
</thead>
<tbody>
<?php foreach ($pbRows as $pe):
    $pIsCredit = strtolower($pe['entry_type']??'debit')==='credit';
    $pTrx = strtolower($pe['trx_type']??'adjustment');
    $pIco = $typeIcons[$pTrx] ?? ['&#128176;','#f1f5f9'];
    $pBal = (float)($pe['curr_balance']??0);
?>
<tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
    <td style="padding:8px 12px;white-space:nowrap;color:#64748b;font-size:11px;">
        <div style="font-weight:700;color:#1e293b;"><?= h(substr($pe['created_at']??'',0,10)) ?></div>
        <div><?= h(substr($pe['created_at']??'',11,5)) ?></div>
    </td>
    <td style="padding:8px 12px;">
        <span style="background:<?= $pIco[1] ?>;padding:1px 5px;border-radius:4px;font-size:11px;margin-right:4px;"><?= $pIco[0] ?></span>
        <span style="font-weight:600;color:#1e293b;"><?= h($pe['description']??'Transaction') ?></span>
    </td>
    <td style="padding:8px 12px;font-family:monospace;font-size:10px;color:#9ca3af;"><?= h($pe['trx_no']??'') ?></td>
    <td style="padding:8px 10px;text-align:right;font-weight:800;color:#16a34a;"><?= $pIsCredit ? '+$'.number_format($pe['amount']??0,2) : '' ?></td>
    <td style="padding:8px 10px;text-align:right;font-weight:800;color:#dc3545;"><?= !$pIsCredit ? '-$'.number_format($pe['amount']??0,2) : '' ?></td>
    <td style="padding:8px 10px;text-align:right;font-weight:800;color:<?= $pBal >= 0 ? '#1565C0' : '#dc3545' ?>;">$<?= number_format($pBal,2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr style="background:#f8fafc;border-top:2px solid #e2e8f0;font-weight:800;">
    <td colspan="3" style="padding:9px 12px;font-size:12px;color:#374151;">Period Totals (<?= count($pbRows) ?> entries)</td>
    <td style="padding:9px 10px;text-align:right;color:#16a34a;">+$<?= number_format($pbCredit,2) ?></td>
    <td style="padding:9px 10px;text-align:right;color:#dc3545;">-$<?= number_format($pbDebit,2) ?></td>
    <td style="padding:9px 10px;text-align:right;color:#D41C1C;">$<?= number_format($rBalance,2) ?></td>
</tr>
</tfoot>
</table>
</div>
</div>
<?php else: ?>
<div style="text-align:center;padding:24px;color:#9ca3af;background:#fff;border-radius:14px;border:1px solid #e2e8f0;">
    <i class="bi bi-journal-text" style="font-size:32px;display:block;margin-bottom:8px;color:#d1d5db;"></i>
    No transactions for <?= date('F Y', strtotime($ledgerMonth.'-01')) ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php endif; ?> <!-- end statement view -->



<?php if ($_ledgerView === 'customers'): ?>

<?php
  // ── Filters from GET
  $_custPage   = max(1, (int)($_GET['cp'] ?? 1));
  $_custSearch = trim($_GET['cs'] ?? '');
  $_custStatus = trim($_GET['cst'] ?? '');
  $_custMonth  = trim($_GET['cm'] ?? ''); // '' = all months
  $_perPage    = 20;

  // Build CRM base URL
  $_crmBase = rtrim(preg_replace('#(/crm)?/api/v[^/]*/?$#','',rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')), '/');
  if (empty($_crmBase)) $_crmBase = 'https://crm.dishnetafrica.com';

  // ── Month-wise breakdown (all apps for this retailer)
  $_monthBreakdown = []; // ['2026-03' => ['count'=>X, 'active'=>X, 'pending'=>X, 'charged'=>X]]
  foreach ($_retailerApps as $_ma2) {
    $mo2 = substr($_ma2['created_at']??'', 0, 7);
    if (!$mo2) continue;
    if (!isset($_monthBreakdown[$mo2])) $_monthBreakdown[$mo2] = ['count'=>0,'active'=>0,'pending'=>0,'charged'=>0];
    $_monthBreakdown[$mo2]['count']++;
    if (($a2s = $_ma2['status']??'') === 'new' || $a2s === 'updated') $_monthBreakdown[$mo2]['active']++;
    elseif (in_array($a2s, ['pending','pending_sync'])) $_monthBreakdown[$mo2]['pending']++;
    $_monthBreakdown[$mo2]['charged'] += (float)($_ma2['amount_charged']??0);
  }
  krsort($_monthBreakdown);

  // ── Apply filters to get display list
  $_filtered = $_retailerApps;
  if ($_custMonth) {
    $_filtered = array_values(array_filter($_filtered, fn($a)=>str_starts_with($a['created_at']??'', $_custMonth)));
  }
  if ($_custStatus) {
    $_filtered = array_values(array_filter($_filtered, fn($a)=>($a['status']??'')===$_custStatus));
  }
  if ($_custSearch) {
    $sq = strtolower($_custSearch);
    $_filtered = array_values(array_filter($_filtered, fn($a)=>
      str_contains(strtolower(($a['firstname']??'').' '.($a['lastname']??'').' '.($a['username']??'').' '.($a['mobile']??'')), $sq)
    ));
  }

  // ── Pagination
  $_totalFiltered = count($_filtered);
  $_totalPages    = max(1, (int)ceil($_totalFiltered / $_perPage));
  $_custPage      = min($_custPage, $_totalPages);
  $_pageApps      = array_slice($_filtered, ($_custPage-1)*$_perPage, $_perPage);
  $_pageUrl       = "?page=dashboard&tab=accounts_ledger&rid={$selRetailer}&lm=".h($ledgerMonth)."&lv=customers&cs=".urlencode($_custSearch)."&cst=".urlencode($_custStatus)."&cm=".urlencode($_custMonth);

  // ── Summary counts (from full unfiltered set)
  $_totalApps    = count($_retailerApps);
  $_activeApps   = count(array_filter($_retailerApps, fn($a)=>in_array($a['status']??'',['new','updated'])));
  $_pendingApps  = count(array_filter($_retailerApps, fn($a)=>in_array($a['status']??'',['pending','pending_sync'])));
  $_totalCharged = array_sum(array_column($_retailerApps, 'amount_charged'));
  $_mRegistered  = $_monthBreakdown[date('Y-m')][ 'count'] ?? 0;
?>

<?php
  $_ucrmCount  = count($_spClients);
  $_ucrmLeads  = count(array_filter($_spClients, fn($c) => $c['is_lead'] ?? false));
  $_ucrmActive = $_ucrmCount - $_ucrmLeads;
  $_spStats    = $_spSummary[$_spName] ?? ($_spSummary[preg_replace('/\s+dishnet\s*$/i','',$_spName)] ?? null);
?>
<!-- Summary cards — 2 rows: Plugin registrations + UCRM attributed -->
<div style="background:#EFF6FF;border-radius:10px;padding:8px 12px;margin-bottom:8px;font-size:11px;font-weight:700;color:#1565C0;display:flex;justify-content:space-between;align-items:center;">
  <span>📱 Plugin Registrations (submitted via app)</span>
  <?php if ($_spIndexLastPull): ?>
  <span style="color:#9ca3af;font-weight:600;">UCRM index: <?= h($_spIndexLastPull) ?></span>
  <?php else: ?>
  <span style="color:#E65100;font-weight:600;">⚠ UCRM index not yet built — run Data Sync</span>
  <?php endif; ?>
</div>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px;">
  <div style="background:#1A1A1A;color:#fff;border-radius:12px;padding:14px;text-align:center;">
    <div style="font-size:28px;font-weight:800;"><?= $_totalApps ?></div>
    <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Total Registered</div>
  </div>
  <div style="background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;border-radius:12px;padding:14px;text-align:center;">
    <div style="font-size:28px;font-weight:800;"><?= $_activeApps ?></div>
    <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Active in CRM</div>
  </div>
  <div style="background:linear-gradient(135deg,#E65100,#F57C00);color:#fff;border-radius:12px;padding:14px;text-align:center;">
    <div style="font-size:28px;font-weight:800;"><?= $_pendingApps ?></div>
    <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Pending Sync</div>
  </div>
  <div style="background:linear-gradient(135deg,#1B5E20,#388E3C);color:#fff;border-radius:12px;padding:14px;text-align:center;">
    <div style="font-size:28px;font-weight:800;">$<?= number_format($_totalCharged, 0) ?></div>
    <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Revenue Collected</div>
  </div>
</div>

<!-- UCRM attributed customers banner -->
<div style="background:#F3E5F5;border-radius:10px;padding:8px 12px;margin-bottom:8px;font-size:11px;font-weight:700;color:#7B1FA2;">
  🔗 UCRM CRM Customers — Sales Person = "<?= h($_spName) ?>"
</div>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;">
  <div style="background:linear-gradient(135deg,#6A1B9A,#7B1FA2);color:#fff;border-radius:12px;padding:14px;text-align:center;">
    <div style="font-size:28px;font-weight:800;"><?= $_ucrmCount ?></div>
    <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">In UCRM</div>
  </div>
  <div style="background:linear-gradient(135deg,#1B5E20,#2E7D32);color:#fff;border-radius:12px;padding:14px;text-align:center;">
    <div style="font-size:28px;font-weight:800;"><?= $_ucrmActive ?></div>
    <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Active Clients</div>
  </div>
  <div style="background:linear-gradient(135deg,#E65100,#F57C00);color:#fff;border-radius:12px;padding:14px;text-align:center;">
    <div style="font-size:28px;font-weight:800;"><?= $_ucrmLeads ?></div>
    <div style="font-size:10px;opacity:.7;text-transform:uppercase;letter-spacing:.5px;">Leads</div>
  </div>
</div>
<?php if ($_ucrmCount === 0 && !$_spIndexLastPull): ?>
<div style="background:#FFF3E0;border:1px solid #FFB74D;border-radius:10px;padding:12px;font-size:12px;color:#E65100;margin-bottom:12px;">
  ⚠ UCRM customer index not built yet. Go to <a href="?page=dashboard&tab=ucrm_data" style="color:#E65100;font-weight:700;">UCRM → Data Sync</a> and click "Pull All Data from UCRM" once. After that, nightly auto-pull keeps it updated automatically.
</div>
<?php elseif ($_ucrmCount === 0): ?>
<div style="background:#F3E5F5;border-radius:10px;padding:10px 12px;font-size:12px;color:#7B1FA2;margin-bottom:12px;">
  No UCRM customers attributed to "<?= h($_spName) ?>". The sales person field in UCRM must match this retailer's name exactly.
</div>
<?php else: ?>
<!-- UCRM customer mini-list (top 10 by most recent reg) -->
<div style="background:#fff;border-radius:12px;border:1px solid #E1BEE7;margin-bottom:12px;overflow:hidden;">
  <div style="padding:10px 14px;border-bottom:1px solid #f3e5f5;display:flex;justify-content:space-between;align-items:center;">
    <strong style="font-size:12px;color:#6A1B9A;">Recent UCRM customers</strong>
    <span style="font-size:10px;color:#9ca3af;">showing top <?= min(10,$_ucrmCount) ?> of <?= $_ucrmCount ?></span>
  </div>
  <?php
  $_crmBase2 = rtrim(preg_replace('#(/crm)?/api/v[^/]*/?$#','',rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')), '/');
  foreach (array_slice($_spClients,0,10) as $_uc):
    $_ucBal = (float)($_uc['balance']??0);
  ?>
  <div style="padding:9px 14px;border-bottom:1px solid #faf5ff;display:flex;align-items:center;gap:10px;">
    <div style="width:34px;height:34px;border-radius:10px;background:<?= ($_uc['is_lead']??false)?'#FFF3E0':'#EDE7F6' ?>;color:<?= ($_uc['is_lead']??false)?'#E65100':'#6A1B9A' ?>;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0;">
      <?= strtoupper(substr($_uc['name']??'?',0,1)) ?>
    </div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:12px;font-weight:700;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($_uc['name']??'Unknown') ?></div>
      <div style="font-size:10px;color:#9ca3af;">
        <?php if ($_uc['username']??''): ?>👤 <?= h($_uc['username']) ?> · <?php endif; ?>
        <?php if ($_uc['phone']??''): ?>📞 <?= h($_uc['phone']) ?> · <?php endif; ?>
        <?= h($_uc['reg_date']??'') ?>
        <?php if ($_uc['package']??''): ?> · 📦 <?= h($_uc['package']) ?><?php endif; ?>
      </div>
    </div>
    <div style="text-align:right;flex-shrink:0;">
      <?php if ($_ucBal != 0): ?>
      <div style="font-size:11px;font-weight:800;color:<?= $_ucBal<0?'#c0392b':'#2E7D32' ?>;">$<?= number_format(abs($_ucBal),2) ?></div>
      <?php endif; ?>
      <a href="<?= h($_crmBase2) ?>/crm/client/<?= $_uc['id'] ?>" target="_blank" style="font-size:10px;color:#6A1B9A;font-weight:700;text-decoration:none;">#<?= $_uc['id'] ?> ↗</a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if ($_ucrmCount > 10): ?>
  <div style="padding:8px 14px;background:#faf5ff;font-size:11px;color:#6A1B9A;text-align:center;">
    + <?= $_ucrmCount - 10 ?> more — go to <a href="?page=dashboard&tab=ucrm_data" style="color:#6A1B9A;font-weight:700;">Data Sync → CRM Customers</a> to see all
  </div>
  <?php endif; ?>
</div>
<?php endif; // end ucrmCount ?>

<!-- Month-wise breakdown table -->
<?php if (!empty($_monthBreakdown)): ?>
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:12px;overflow:hidden;">
  <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
    <strong style="font-size:12px;color:#1e293b;">📅 Monthly Registrations</strong>
    <span style="font-size:10px;color:#9ca3af;"><?= count($_monthBreakdown) ?> months</span>
  </div>
  <div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead>
      <tr style="background:#f8fafc;">
        <th style="padding:7px 12px;text-align:left;color:#6b7280;font-weight:600;">Month</th>
        <th style="padding:7px 8px;text-align:center;color:#D41C1C;font-weight:600;">Registered</th>
        <th style="padding:7px 8px;text-align:center;color:#2E7D32;font-weight:600;">Active</th>
        <th style="padding:7px 8px;text-align:center;color:#E65100;font-weight:600;">Pending</th>
        <th style="padding:7px 12px;text-align:right;color:#1B5E20;font-weight:600;">Revenue</th>
        <th style="padding:7px 8px;text-align:center;color:#6b7280;font-weight:600;">View</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach (array_slice($_monthBreakdown, 0, 12, true) as $mo => $mdata): ?>
    <tr style="border-top:1px solid #f8fafc;background:<?= $mo===date('Y-m')?'#EFF6FF':'#fff' ?>;">
      <td style="padding:7px 12px;font-weight:600;"><?= date('M Y', strtotime($mo.'-01')) ?></td>
      <td style="padding:7px 8px;text-align:center;">
        <span style="background:#fff0f0;color:#1565C0;border-radius:20px;padding:2px 8px;font-weight:700;"><?= $mdata['count'] ?></span>
      </td>
      <td style="padding:7px 8px;text-align:center;color:#2E7D32;font-weight:700;"><?= $mdata['active'] ?></td>
      <td style="padding:7px 8px;text-align:center;color:<?= $mdata['pending']>0?'#E65100':'#9ca3af' ?>;font-weight:700;"><?= $mdata['pending'] ?: '—' ?></td>
      <td style="padding:7px 12px;text-align:right;font-weight:700;color:#1B5E20;">$<?= number_format($mdata['charged'],0) ?></td>
      <td style="padding:7px 8px;text-align:center;">
        <a href="<?= $_pageUrl ?>&cm=<?= urlencode($mo) ?>"
           style="font-size:10px;color:#D41C1C;font-weight:700;text-decoration:none;padding:2px 6px;background:#EFF6FF;border-radius:6px;">
          <?= $_custMonth===$mo ? '✓ Active' : 'Filter' ?>
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($_custMonth): ?>
  <div style="padding:8px 14px;background:#EFF6FF;font-size:11px;color:#D41C1C;font-weight:600;">
    Filtered to: <?= date('F Y', strtotime($_custMonth.'-01')) ?>
    <a href="<?= str_replace('&cm='.urlencode($_custMonth),'',$_pageUrl) ?>" style="color:#c0392b;margin-left:8px;font-weight:700;">✕ Clear filter</a>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($_retailerApps)): ?>
<div style="text-align:center;padding:40px 20px;color:#9ca3af;">
  <div style="font-size:40px;margin-bottom:10px;">📭</div>
  <strong>No customers registered yet</strong><br>
  <span style="font-size:12px;">This retailer has not registered any customers through the plugin.</span>
</div>
<?php else: ?>

<style>
.crmc-card{background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:8px;box-shadow:0 1px 6px rgba(0,0,0,.04);border:1px solid #f1f5f9;}
.crmc-name{font-size:13px;font-weight:700;color:#1e293b;}
.crmc-meta{font-size:11px;color:#9ca3af;margin-top:2px;}
.crmc-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;}
</style>

<!-- Filter bar — server-side filtering via URL params -->
<form method="GET" style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="accounts_ledger">
  <input type="hidden" name="rid" value="<?= $selRetailer ?>">
  <input type="hidden" name="lm" value="<?= h($ledgerMonth) ?>">
  <input type="hidden" name="lv" value="customers">
  <?php if ($_custMonth): ?><input type="hidden" name="cm" value="<?= h($_custMonth) ?>"><?php endif; ?>
  <input type="text" name="cs" value="<?= h($_custSearch) ?>" placeholder="🔍 Search name, username, phone…"
    style="flex:1;min-width:140px;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
  <select name="cst" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;background:#fff;">
    <option value="">All Status</option>
    <option value="new"          <?= $_custStatus==='new'?'selected':'' ?>>✅ Active</option>
    <option value="updated"      <?= $_custStatus==='updated'?'selected':'' ?>>✅ Updated</option>
    <option value="pending"      <?= $_custStatus==='pending'?'selected':'' ?>>⏳ Pending</option>
    <option value="pending_sync" <?= $_custStatus==='pending_sync'?'selected':'' ?>>🔄 Syncing</option>
    <option value="crm_failed"   <?= $_custStatus==='crm_failed'?'selected':'' ?>>❌ Failed</option>
  </select>
  <button type="submit" style="padding:8px 14px;background:#D41C1C;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">Search</button>
  <?php if ($_custSearch||$_custStatus): ?>
  <a href="<?= $_pageUrl ?>&cp=1&cs=&cst=" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;color:#6b7280;text-decoration:none;font-weight:600;">✕ Clear</a>
  <?php endif; ?>
</form>

<div style="font-size:11px;color:#9ca3af;margin-bottom:8px;">
  Showing <?= number_format($_totalFiltered) ?> of <?= number_format($_totalApps) ?> customers
  <?php if ($_custMonth): ?> · <?= date('F Y', strtotime($_custMonth.'-01')) ?><?php endif; ?>
  <?php if ($_custSearch): ?> · matching "<?= h($_custSearch) ?>"<?php endif; ?>
  <?php if ($_totalPages > 1): ?> · Page <?= $_custPage ?> of <?= $_totalPages ?><?php endif; ?>
</div>
<div id="crmcList">
<?php foreach ($_pageApps as $_app):
  $_appId     = (int)($_app['id'] ?? 0);
  $_crmId     = (int)($_app['crm_client_id'] ?? 0);
  $_fname     = $_app['firstname'] ?? '';
  $_lname     = $_app['lastname']  ?? '';
  $_fullName  = trim("{$_fname} {$_lname}") ?: ($_app['username'] ?? 'Unknown');
  $_username  = $_app['username']  ?? '';
  $_phone     = $_app['mobile']    ?? '';
  $_email     = $_app['email']     ?? '';
  $_pkg       = $_app['package_choice'] ?? '';
  $_conn      = $_app['connectivity_type'] ?? '';
  $_charged   = (float)($_app['amount_charged'] ?? 0);
  $_sp        = $_app['sales_person'] ?? '';
  $_status    = $_app['status'] ?? 'pending';
  $_date      = substr($_app['created_at'] ?? '', 0, 10);
  $_ref       = $_app['ref'] ?? '';

  // Status styling
  $_statusColors = [
    'new'          => ['#E8F5E9','#2E7D32','✅ Active'],
    'pending'      => ['#FFF8E1','#F57F17','⏳ Pending'],
    'pending_sync' => ['#E3F2FD','#1565C0','🔄 Syncing'],
    'crm_failed'   => ['#FFEBEE','#c0392b','❌ Failed'],
    'exhausted'    => ['#FFEBEE','#c0392b','❌ Exhausted'],
    'updated'      => ['#E8F5E9','#1B5E20','✅ Updated'],
  ];
  [$_sc_bg, $_sc_col, $_sc_label] = $_statusColors[$_status] ?? ['#f1f5f9','#6b7280', ucfirst($_status)];

  // Live CRM data if fetched
  $_crmD      = $_crmId ? ($_crmDetailCache[$_crmId] ?? null) : null;
  $_crmBal    = $_crmD ? (float)($_crmD['accountBalance'] ?? 0) : null;
  $_hasService = $_crmD ? !empty($_crmD['hasService'] ?? false) : null;
?>
<div class="crmc-card" data-name="<?= h(strtolower($_fullName . ' ' . $_username . ' ' . $_phone)) ?>" data-status="<?= h($_status) ?>">
  <div style="display:flex;align-items:flex-start;gap:10px;">
    <!-- Avatar -->
    <div style="width:40px;height:40px;border-radius:12px;background:<?= $_sc_bg ?>;color:<?= $_sc_col ?>;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;font-weight:800;">
      <?= strtoupper(substr($_fname ?: '?', 0, 1)) ?>
    </div>
    <!-- Info -->
    <div style="flex:1;min-width:0;">
      <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
        <span class="crmc-name"><?= h($_fullName) ?></span>
        <span class="crmc-badge" style="background:<?= $_sc_bg ?>;color:<?= $_sc_col ?>;"><?= $_sc_label ?></span>
        <?php if ($_conn): ?>
          <span class="crmc-badge" style="background:#E3F2FD;color:#D41C1C;"><?= h($_conn) ?></span>
        <?php endif; ?>
      </div>
      <div class="crmc-meta">
        <?php if ($_username): ?>👤 <strong><?= h($_username) ?></strong> &middot; <?php endif; ?>
        <?php if ($_phone): ?>📞 <?= h($_phone) ?> &middot; <?php endif; ?>
        <?php if ($_email): ?>✉ <?= h($_email) ?> &middot; <?php endif; ?>
        📅 <?= $_date ?: '—' ?>
      </div>
      <div class="crmc-meta" style="margin-top:3px;">
        <?php if ($_pkg): ?>📦 <?= h($_pkg) ?><?php endif; ?>
        <?php if ($_ref): ?> &middot; 📣 <?= h($_ref) ?><?php endif; ?>
        <?php if ($_charged > 0): ?> &middot; 💵 $<?= number_format($_charged, 2) ?> charged<?php endif; ?>
      </div>
    </div>
    <!-- Right: CRM link + balance -->
    <div style="text-align:right;flex-shrink:0;min-width:70px;">
      <?php if ($_crmBal !== null): ?>
      <div style="font-size:12px;font-weight:800;color:<?= $_crmBal < 0 ?'#c0392b':'#2E7D32' ?>;">
        $<?= number_format(abs($_crmBal), 2) ?>
        <div style="font-size:9px;color:#9ca3af;"><?= $_crmBal < 0 ? 'owes' : 'balance' ?></div>
      </div>
      <?php endif; ?>
      <?php if ($_crmId): ?>
      <a href="<?= h($_crmBase) ?>/crm/client/<?= $_crmId ?>" target="_blank"
         style="font-size:10px;color:#D41C1C;text-decoration:none;font-weight:700;">
        #<?= $_crmId ?> ↗
      </a>
      <?php else: ?>
      <span style="font-size:10px;color:#f59e0b;">⏳ no CRM ID</span>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div><!-- end crmcList -->
<?php if ($_totalPages > 1): ?>
<!-- Pagination -->
<div style="display:flex;justify-content:center;gap:4px;margin-top:16px;flex-wrap:wrap;">
  <?php if ($_custPage > 1): ?>
    <a href="<?= $_pageUrl ?>&cp=<?= $_custPage-1 ?>"
       style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:600;color:#1565C0;text-decoration:none;">← Prev</a>
  <?php endif; ?>
  <?php
    $pStart = max(1, $_custPage-2);
    $pEnd   = min($_totalPages, $_custPage+2);
    for ($pi = $pStart; $pi <= $pEnd; $pi++):
  ?>
    <a href="<?= $_pageUrl ?>&cp=<?= $pi ?>"
       style="padding:6px 12px;border:1px solid <?= $pi===$_custPage?'#1565C0':'#e2e8f0' ?>;border-radius:8px;font-size:12px;font-weight:<?= $pi===$_custPage?'800':'600' ?>;
              background:<?= $pi===$_custPage?'#1565C0':'#fff' ?>;color:<?= $pi===$_custPage?'#fff':'#1e293b' ?>;text-decoration:none;">
      <?= $pi ?>
    </a>
  <?php endfor; ?>
  <?php if ($_custPage < $_totalPages): ?>
    <a href="<?= $_pageUrl ?>&cp=<?= $_custPage+1 ?>"
       style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:600;color:#1565C0;text-decoration:none;">Next →</a>
  <?php endif; ?>
</div>
<div style="text-align:center;font-size:11px;color:#9ca3af;margin-top:6px;">
  <?= (($_custPage-1)*$_perPage)+1 ?>–<?= min($_custPage*$_perPage,$_totalFiltered) ?> of <?= $_totalFiltered ?>
</div>
<?php endif; ?>

<?php endif; ?> <!-- end empty apps check -->
<?php endif; ?> <!-- end customers view -->

<?php else: ?>
<div style="text-align:center;padding:40px 20px;color:#9ca3af;">
    <i class="bi bi-person-badge" style="font-size:48px;display:block;margin-bottom:10px;color:#d1d5db;"></i>
    <div style="font-size:15px;font-weight:700;">Select a retailer</div>
    <div style="font-size:12px;margin-top:4px;">Choose a retailer from the dropdown to view their ledger</div>
</div>
<?php endif; ?>
<div style="height:80px;"></div>


