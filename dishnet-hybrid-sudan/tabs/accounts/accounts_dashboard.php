<?php
// ── Access gate: accountant or admin only ──
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}
// Tab: accounts_dashboard — REDESIGNED v4.11.38b (deduped, 7 blocks)
$allCols        = $store->load('payment_collections.json');
$allApps        = $store->load('kyc_applications.json');
$allRetailers2  = $auth->getAllRetailers();
$todayCols      = array_filter($allCols, fn($c) => str_starts_with($c['created_at']??'', date('Y-m-d')) && ($c['status']??'active') !== 'voided');
$monthCols      = array_filter($allCols, fn($c) => str_starts_with($c['created_at']??'', date('Y-m'))    && ($c['status']??'active') !== 'voided');
$todayTotal     = array_sum(array_map(fn($c) => $c['amount']??0, $todayCols));
$monthTotal     = array_sum(array_map(fn($c) => $c['amount']??0, $monthCols));
$monthComm      = array_sum(array_map(fn($c) => $c['commission']??0, $monthCols));
$todayComm      = array_sum(array_map(fn($c) => $c['commission']??0, $todayCols));
$totalWalletBal = array_sum(array_column($allRetailers2, 'wallet'));
$monthApps      = array_filter($allApps, fn($a) => substr($a['created_at']??$a['submitted_at']??'',0,7) === date('Y-m'));
$todayApps      = array_filter($allApps, fn($a) => substr($a['created_at']??$a['submitted_at']??'',0,10) === date('Y-m-d'));
$allRecharges2  = $store->load('wallet_recharge_requests.json') ?: [];
$monthRecharges = array_filter($allRecharges2, fn($r) => ($r['status']??'')==='approved' && str_starts_with($r['approved_at']??'', date('Y-m')));
$monthRechargeAmt = array_sum(array_map(fn($r) => $r['amount']??0, $monthRecharges));
$activeCount    = count(array_filter($allRetailers2, fn($r) => !empty($r['is_active'])));

// 30-day chart
$chartDays = [];
for ($d = 29; $d >= 0; $d--) {
    $day = date('Y-m-d', strtotime("-{$d} days"));
    $chartDays[] = ['date' => date('M j', strtotime($day)),
                    'amount' => round(array_sum(array_column(array_filter($allCols, fn($c) => str_starts_with($c['created_at']??'',$day)),'amount')),2)];
}

// ── Active Services (computed early — before HTML) ──
$_svcCacheW  = $store->load('ucrm_services_cache.json') ?? [];
$_svcActiveW = array_filter($_svcCacheW, fn($s) => in_array((int)($s['status']??0), [1,2], true));
$_svcStarlink = 0; $_svcFiber = 0; $_svcLte = 0; $_svcOther = 0;
$_svcSuspended = count(array_filter($_svcCacheW, fn($s) => (int)($s['status']??0) === 4));
foreach ($_svcActiveW as $_sv) {
    $pn = strtolower($_sv['name'] ?? $_sv['servicePlanName'] ?? $_sv['planName'] ?? '');
    if (str_contains($pn,'starlink')) $_svcStarlink++;
    elseif (str_contains($pn,'fiber')||str_contains($pn,'ftth')||str_contains($pn,'fibre')) $_svcFiber++;
    elseif (str_contains($pn,'lte')||str_contains($pn,'4g')||str_contains($pn,'mobile')) $_svcLte++;
    else $_svcOther++;
}
$_svcTotalActive = count($_svcActiveW);
// ── Fiber active count: read directly from Fiber Finance plugin's fiber_services.json
// Same data source & same logic as Fiber Finance dashboard (calculateSummary):
//   status = 'Active' (mapped from Splynx 'active') → activeCount++
//   unique crm_customer_id → totalCustomers (deduplicated)
$_ffDataDir = dirname($store->getDataDir()) . '/../dishnet-fiber-finance/data';
$_ffSvcsFile = rtrim($_ffDataDir, '/') . '/fiber_services.json';
$_svcFiberActive = 0;
$_svcFiberTotal  = 0;
$_svcFiberCustomers = 0;  // unique active customers (Fiber Finance logic)
if (is_file($_ffSvcsFile)) {
    $_ffSvcs = json_decode(file_get_contents($_ffSvcsFile), true) ?: [];
    $_ffSeenCust = [];
    foreach ($_ffSvcs as $_ffs) {
        $_svcFiberTotal++;
        if (($ffs_status = $_ffs['status'] ?? '') === 'Active') {
            $_svcFiberActive++;
            $ffs_cust = $_ffs['crm_customer_id'] ?? $_ffs['customer_id'] ?? '';
            if ($ffs_cust && !in_array($ffs_cust, $_ffSeenCust, true)) {
                $_ffSeenCust[] = $ffs_cust;
                $_svcFiberCustomers++;
            }
        }
    }
    // Override the $_svcFiber with authoritative count
    if ($_svcFiberActive > 0) $_svcFiber = $_svcFiberActive;
}
try { $_lA=(int)$store->getPdo()->query("SELECT COUNT(*) FROM lte_subscribers WHERE is_active=1")->fetchColumn(); if($_lA>0) $_svcLte=$_lA; } catch(\Throwable $e){}
$_siW = $store->load('client_search_index.json') ?? [];
$_acIds = [];
foreach ($_siW as $_si) { $cid=(int)($_si['id']??0); $st=$_si['status']??''; if($cid>0&&($st==1||$st==='active')&&empty($_si['isLead'])) $_acIds[$cid]=true; }
foreach ($_svcActiveW as $_sv) { $cid=(int)($_sv['clientId']??$_sv['_clientId']??0); if($cid>0) $_acIds[$cid]=true; }
$_activeCustomers = count($_acIds);

// ── Cashbook balances ──
require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
$cbDash    = new CashbookService($store, $dataDir);
$cbBals    = $cbDash->getBothBalances();
$cbDishBal = (float)($cbBals['dishnet']['balance'] ?? 0);
$cb4gBal   = (float)($cbBals['4g']['balance']     ?? 0);
$cbBcBal   = (float)($cbBals['bluecard']['balance']?? 0);
$cbTotalBal = $cbDishBal + $cb4gBal + $cbBcBal;
$cbPending  = $cbDash->getPendingDisbursements('dishnet');
$cbPend4g   = $cbDash->getPendingDisbursements('4g');
$cbPendBc   = $cbDash->getPendingDisbursements('bluecard');
$cbPendCount = count($cbPending) + count($cbPend4g) + count($cbPendBc);
$cbPendAmt   = array_sum(array_column($cbPending,'amount')) + array_sum(array_column($cbPend4g,'amount')) + array_sum(array_column($cbPendBc,'amount'));

// ── Field cash positions ──
require_once dirname(__DIR__, 2) . '/lib/ExpenseGateway.php';
$_expGw = new ExpenseGateway($store);
$_adAllRetailers = $store->load('retailers.json') ?? [];
$_adAllCols = $store->load('payment_collections.json') ?: [];
$_adAllHovs = $store->load('cash_handovers.json') ?: [];
$_adAllExpsU = $_expGw->getAll(['exclude_voided' => true]);
require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
$_adJsonSvc = new StaffCashPositionService($store, $store->getPdo());
$_adAllPos  = $_adJsonSvc->getAllPositions();
$fieldPositions = [];
foreach ($_adAllPos as $_adSid => $_adPos) {
    $_adRetailer = null;
    foreach ($_adAllRetailers as $_ar2) { if ((int)($_ar2['id']??0) === $_adSid) { $_adRetailer = $_ar2; break; } }
    if ($_adRetailer && (!empty($_adRetailer['is_admin']) || in_array($_adRetailer['role']??'', ['accountant','admin'], true))) continue;
    $fieldPositions[] = $_adPos;
}
$totalFieldCash = round(array_sum(array_column($fieldPositions, 'cash_exposure')), 2);

// ── Cash aging config ──
$agingTiersCfg = ['light'=>48,'medium'=>24,'heavy'=>12];
$carryLimitCfg = (float)($config['advance_carry_limit'] ?? 100);
$_agentLimitsMap = [];
foreach ($store->load('retailers.json') ?? [] as $_alr) {
    if (!empty($_alr['carry_limit'])) $_agentLimitsMap[(int)$_alr['id']] = (float)$_alr['carry_limit'];
}
$_adGetLimit = function(int $aid) use ($_agentLimitsMap, $carryLimitCfg): float {
    return $_agentLimitsMap[$aid] ?? $carryLimitCfg;
};
// Aging per agent
$ageByAgent = []; $_snapRows = [];
try {
    $stmt = $store->getPdo()->query("SELECT agent_id, last_event_time FROM staff_cash_snapshots");
    $_snapRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($_snapRows as $_sr) {
        $aid = (int)$_sr['agent_id'];
        $t   = $_sr['last_event_time'] ?? '';
        if ($t) { $h = round((time()-strtotime($t))/3600,1); $ageByAgent[$aid] = ['hours'=>$h,'source'=>'snapshot']; }
    }
} catch (\Throwable $e) {}
$agentsOverLimit = []; $agentsAging = [];
foreach ($fieldPositions as $_fp) {
    $aid = (int)($_fp['agent_id']??0);
    $exp = (float)($_fp['cash_exposure']??0);
    if ($exp > $_adGetLimit($aid)) $agentsOverLimit[] = $_fp;
    if (isset($ageByAgent[$aid])) {
        $tier = $exp<50?'light':($exp<=200?'medium':'heavy');
        if ($ageByAgent[$aid]['hours'] > ($agingTiersCfg[$tier]??24)) $agentsAging[] = $_fp;
    }
}
$agentsWithIssues = !empty($agentsOverLimit) || !empty($agentsAging);

// ── Office cash ──
$_cpByStaff = []; $_cpFieldTotal = 0;
foreach ($fieldPositions as $_fp) {
    $exp = (float)($_fp['cash_exposure']??0); if ($exp<=0) continue;
    $role=''; foreach($_adAllRetailers as $_r){if((int)($_r['id']??0)===(int)$_fp['agent_id']){$role=$_r['role']??'';break;}}
    if (in_array($role,['accountant','admin'],true)) continue;
    $_cpByStaff[] = ['name'=>$_fp['staff_name'],'amount'=>$exp,'agent_id'=>(int)$_fp['agent_id']];
    $_cpFieldTotal += $exp;
}
$_cpOffice = round(max(0, $cbTotalBal - $_cpFieldTotal), 2);

// ── Pending actions ──
$_adHqPend=0; $_adHqAmt=0; $_adExpPend=0; $_adAdvActive=0; $_adIjPend=0;
try {
    $_adHqAllFull = $store->load('cash_handovers.json') ?? [];
    $_adHqAll = array_filter($_adHqAllFull, fn($h) => ($h['status']??'')==='pending');
    $_adHqPend = count($_adHqAll);
    $_adHqAmt  = round(array_sum(array_map(fn($h)=>$h['amount']??0, $_adHqAll)),2);
    $_adExpPend = count($_expGw->getPending());
    $_adAdvActive = (int)$store->getPdo()->query("SELECT COUNT(*) FROM cash_advances WHERE status IN ('active','partial') AND (parent_advance_id IS NULL OR parent_advance_id = 0)")->fetchColumn();
    $_adIjAll = $store->load('fiber_invoice_queue.json') ?? [];
    $_adIjPend = count(array_filter($_adIjAll, fn($x)=>($x['status']??'pending')==='pending'));
} catch (\Throwable $e) {}
$_paCrmPend = count(array_filter($allCols, fn($c) => empty($c['crm_synced']) && !empty($c['crm_customer_id'])));
$_paFiberPend=0; $_paFiberAmt=0;
try {
    $store->getPdo()->query("SELECT id FROM fiber_collection_jobs LIMIT 0");
    $_fiiRow = $store->getPdo()->query("SELECT COUNT(*), COALESCE(SUM(amount),0) FROM fiber_collection_jobs WHERE status='pending'")->fetch(\PDO::FETCH_NUM);
    $_paFiberPend = (int)$_fiiRow[0]; $_paFiberAmt = round((float)$_fiiRow[1],2);
} catch (\Throwable $e) {}
$_paTotalActions = $_adHqPend + $_adExpPend + $_paCrmPend + $_paFiberPend;

// ── Expenses ──
$_expSummary = $_expGw->getSummary(date('Y-m'));

// ── KYC activation ──
$_svcCache2 = $store->load('ucrm_services_cache.json') ?? [];
$_activeClientIds = [];
foreach ($_siW as $_si) { $cid=(int)($_si['id']??0); $st=$_si['status']??''; if($cid>0&&($st==1||$st==='active')&&empty($_si['isLead'])) $_activeClientIds[$cid]=true; }
foreach ($_svcCache2 as $_svc) { $st=(int)($_svc['status']??0); if($st===1||$st===2){$cid=(int)($_svc['clientId']??$_svc['_clientId']??0); if($cid>0) $_activeClientIds[$cid]=true;} }
$_kfTotal=count($monthApps); $_kfSynced=0; $_kfActivated=0;
foreach ($monthApps as $_ka) {
    $crmId=(int)($_ka['crm_client_id']??0);
    if ($crmId>0) { $_kfSynced++; if(isset($_activeClientIds[$crmId])) $_kfActivated++; }
}

// ── Unpaid invoice count ──
$_crmInvoiceDue=0;
try { $__invCache=$store->load('ucrm_invoices_cache.json')??[]; $_invUnpaid=array_filter($__invCache,fn($i)=>($i['status']??0)==2&&!empty($i['clientId'])); $_crmInvoiceDue=count(array_unique(array_column($_invUnpaid,'clientId'))); } catch(\Throwable $e){}
?>
<style>
/* ── DishNet Accounts Dashboard — v4.11.38b Clean ── */
@import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,700;0,900;1,700&family=Barlow:wght@400;500;600;700&display=swap');
:root{--red:#D41C1C;--dark:#111;--surface:#fff;--border:#f1f5f9;--muted:#94a3b8;--text:#1e293b;}

.d2-hero{background:var(--dark);border-radius:20px;overflow:hidden;margin-bottom:12px;box-shadow:0 6px 28px rgba(212,28,28,.2);position:relative;}
.d2-hero::after{content:'';position:absolute;top:0;right:0;bottom:0;width:55%;background:radial-gradient(ellipse 90% 80% at 100% 50%,rgba(212,28,28,.4),transparent);pointer-events:none;}
.d2-hero-top{padding:16px 18px 0;display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1;}
.d2-hero-date{font-size:11px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:1.5px;}
.d2-hero-badge{background:var(--red);color:#fff;font-size:10px;font-weight:800;padding:3px 9px;border-radius:20px;letter-spacing:.5px;}
.d2-bal{padding:10px 18px 0;position:relative;z-index:1;}
.d2-bal-lbl{font-size:10px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:3px;}
.d2-bal-val{font-family:'Barlow Condensed',sans-serif;font-size:44px;font-weight:900;color:#fff;line-height:1;letter-spacing:-1px;}
.d2-bal-split{display:flex;gap:12px;margin-top:6px;flex-wrap:wrap;}
.d2-bal-chip{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:5px 10px;display:flex;flex-direction:column;min-width:90px;}
.d2-bal-chip-lbl{font-size:8px;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:1px;}
.d2-bal-chip-val{font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:900;color:#fff;line-height:1.1;margin-top:1px;}
.d2-today{margin:10px 18px 0;padding:10px 12px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:10px;display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1;}
.d2-today-lbl{font-size:10px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1px;}
.d2-today-val{font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;color:#fff;}
.d2-today-sub{font-size:10px;color:rgba(255,255,255,.35);text-align:right;}
.d2-cta{display:grid;grid-template-columns:1fr 1fr 1fr;margin-top:14px;border-top:1px solid rgba(255,255,255,.06);position:relative;z-index:1;}
.d2-cta a{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:13px 4px 11px;gap:4px;text-decoration:none;border-right:1px solid rgba(255,255,255,.06);color:rgba(255,255,255,.55);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;line-height:1;transition:background .15s;-webkit-tap-highlight-color:transparent;}
.d2-cta a:last-child{border-right:none;}
.d2-cta a:active{background:rgba(212,28,28,.25);}
.d2-cta a.hot{background:rgba(212,28,28,.15);color:rgba(255,255,255,.9);}
.d2-cta i{font-size:18px;color:var(--red);}
.d2-cta a.hot i{color:#ff7070;}
.d2-badge{background:var(--red);color:#fff;border-radius:10px;padding:1px 5px;font-size:9px;font-weight:900;margin-left:3px;vertical-align:middle;}
.d2-alert{background:linear-gradient(90deg,#431407,#78350f);border-radius:14px;padding:12px 14px;margin-bottom:10px;display:flex;align-items:center;gap:10px;text-decoration:none;-webkit-tap-highlight-color:transparent;}
.d2-alert:active{opacity:.85;}
.d2-alert-tx strong{display:block;font-size:13px;font-weight:800;color:#fff;line-height:1.3;}
.d2-alert-tx span{font-size:11px;color:rgba(255,255,255,.55);}
.d2-alert-arr{color:rgba(255,255,255,.3);margin-left:auto;flex-shrink:0;}
.d2-kpi{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;margin-bottom:12px;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.d2-kpi::-webkit-scrollbar{display:none;}
.d2-kpi-card{background:#fff;border-radius:12px;padding:11px 13px;border:1px solid var(--border);flex-shrink:0;min-width:90px;box-shadow:0 1px 3px rgba(0,0,0,.04);}
.d2-kpi-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);}
.d2-kpi-val{font-family:'Barlow Condensed',sans-serif;font-size:21px;font-weight:900;color:var(--text);line-height:1.1;margin-top:2px;}
.d2-kpi-sub{font-size:9px;color:var(--muted);margin-top:1px;}
.d2-section{background:#fff;border-radius:13px;border:1px solid var(--border);margin-bottom:8px;overflow:hidden;}
.d2-section-hd{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;cursor:pointer;-webkit-tap-highlight-color:transparent;font-size:12px;font-weight:800;color:var(--text);}
.d2-section-hd i.icon{font-size:14px;margin-right:6px;}
.d2-section-hd .arr{color:var(--muted);font-size:12px;transition:transform .2s;}
.d2-section-hd.open .arr{transform:rotate(180deg);}
.d2-section-body{padding:0 14px 14px;display:none;}
.d2-section-body.open{display:block;}
.d2-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f8fafc;font-size:13px;}
.d2-row:last-child{border-bottom:none;}
/* service cards */
.svc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px;}
@media(min-width:600px){.svc-grid{grid-template-columns:repeat(4,1fr);} .d2-bal-val{font-size:52px;} .d2-kpi{flex-wrap:wrap;overflow:visible;} .d2-kpi-card{flex:1;min-width:80px;} .d2-section-body{display:block!important;} .d2-section-hd .arr{display:none;}}
.svc-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.04);position:relative;overflow:hidden;}
.svc-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.svc-card.starlink::before{background:linear-gradient(90deg,#1d4ed8,#3b82f6);}
.svc-card.fiber::before{background:linear-gradient(90deg,#059669,#34d399);}
.svc-card.lte::before{background:linear-gradient(90deg,#d97706,#fbbf24);}
.svc-card.customers::before{background:linear-gradient(90deg,#7c3aed,#a78bfa);}
.svc-ic{font-size:20px;margin-bottom:6px;}
.svc-val{font-family:'Barlow Condensed',sans-serif;font-size:36px;font-weight:900;line-height:1;letter-spacing:-1px;}
.svc-card.starlink .svc-val{color:#1d4ed8;}
.svc-card.fiber .svc-val{color:#059669;}
.svc-card.lte .svc-val{color:#d97706;}
.svc-card.customers .svc-val{color:#7c3aed;}
.svc-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;margin-top:4px;}
.svc-sub{font-size:11px;color:#64748b;margin-top:2px;}
/* money map */
.mm-office{display:flex;align-items:center;gap:12px;padding:12px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:12px;margin-bottom:8px;}
.mm-field{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:10px;margin-bottom:4px;}
/* fiber status bars */
.fsb-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;cursor:pointer;padding:4px 6px;border-radius:8px;transition:background .15s;}
.fsb-row:hover{background:#f8fafc;}
.fsb-bar{height:24px;border-radius:6px;display:flex;align-items:center;padding:0 10px;font-size:11px;font-weight:700;color:#fff;min-width:28px;}
.fsb-lbl{font-size:12px;font-weight:600;color:#64748b;width:110px;flex-shrink:0;text-align:right;}
.fsb-cnt{font-size:13px;font-weight:800;color:#1e293b;width:30px;text-align:right;flex-shrink:0;}
.fsb-detail{display:none;background:#f8fafc;border-radius:10px;padding:10px 12px;margin:4px 0 8px;max-height:320px;overflow-y:auto;}
.fsb-detail.open{display:block;}
.fsb-detail table{width:100%;border-collapse:collapse;font-size:12px;}
.fsb-detail th{font-size:10px;color:#94a3b8;text-transform:uppercase;padding:4px 6px;border-bottom:1px solid #e2e8f0;text-align:left;position:sticky;top:0;background:#f8fafc;}
.fsb-detail td{padding:5px 6px;border-bottom:1px solid #f1f5f9;color:#334155;}
</style>

<!-- ══════════════════════════════════════════════════════════════
     BLOCK 1: CASHBOOK HERO
     ══════════════════════════════════════════════════════════════ -->
<div class="d2-hero">
  <div class="d2-hero-top">
    <div class="d2-hero-date"><?= date('d M Y') ?></div>
    <div class="d2-hero-badge">CASHBOOK</div>
  </div>
  <div class="d2-bal">
    <div class="d2-bal-lbl">Total Cash Position</div>
    <div class="d2-bal-val">$<?= number_format($cbTotalBal,2) ?></div>
    <div class="d2-bal-split">
      <div class="d2-bal-chip"><span class="d2-bal-chip-lbl">Fiber & Starlink</span><span class="d2-bal-chip-val">$<?= number_format($cbDishBal,2) ?></span></div>
      <div class="d2-bal-chip"><span class="d2-bal-chip-lbl">DishNet 4G</span><span class="d2-bal-chip-val">$<?= number_format($cb4gBal,2) ?></span></div>
      <div class="d2-bal-chip"><span class="d2-bal-chip-lbl">BlueCARD</span><span class="d2-bal-chip-val">$<?= number_format($cbBcBal,2) ?></span></div>
    </div>
  </div>
  <div class="d2-today">
    <div>
      <div class="d2-today-lbl">Today · <?= date('d M') ?></div>
      <div class="d2-today-val">$<?= number_format($todayTotal,2) ?></div>
      <div style="font-size:10px;color:rgba(255,255,255,.35);"><?= count($todayCols) ?> payments · <?= count($todayApps) ?> new KYC</div>
    </div>
    <div class="d2-today-sub">
      <?= date('M Y') ?><br>
      Net $<?= number_format($monthTotal-$monthComm,0) ?><br>
      <?= count($monthApps) ?> KYC
    </div>
  </div>
  <div class="d2-cta">
    <a class="hot" href="?page=dashboard&tab=collect_payment"><i class="bi bi-plus-circle-fill"></i>Collect</a>
    <a href="?page=dashboard&tab=cashbook"><i class="bi bi-journal-bookmark-fill"></i>Cashbook<?php if($cbPendCount>0): ?><span class="d2-badge"><?= $cbPendCount ?></span><?php endif; ?></a>
    <a href="?page=dashboard&tab=accounts_collections"><i class="bi bi-cash-stack"></i>Collections</a>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     BLOCK 2: ALERTS (unreceipted + invoice queue)
     ══════════════════════════════════════════════════════════════ -->
<?php if ($cbPendCount > 0): ?>
<a class="d2-alert" href="?page=dashboard&tab=cashbook&cb_view=pending">
  <span style="font-size:22px;flex-shrink:0;">⏳</span>
  <div class="d2-alert-tx"><strong><?= $cbPendCount ?> unreceipted — $<?= number_format($cbPendAmt,2) ?></strong><span>Cash out awaiting voucher. Tap to settle.</span></div>
  <div class="d2-alert-arr"><i class="bi bi-chevron-right"></i></div>
</a>
<?php endif; ?>
<?php if ($_adIjPend > 0): ?>
<a class="d2-alert" href="?page=dashboard&tab=invoice_queue" style="background:linear-gradient(90deg,#0c4a6e,#075985);">
  <span style="font-size:22px;flex-shrink:0;">🧾</span>
  <div class="d2-alert-tx"><strong><?= $_adIjPend ?> job<?= $_adIjPend>1?'s':'' ?> to invoice</strong><span>Completed jobs awaiting CRM invoice.</span></div>
  <div class="d2-alert-arr"><i class="bi bi-chevron-right"></i></div>
</a>
<?php endif; ?>

<!-- Needs Attention -->
<?php if ($_paTotalActions > 0): ?>
<div class="d2-section">
  <div class="d2-section-hd open" onclick="d2Toggle(this)">
    <span><i class="bi bi-bell-fill icon" style="color:#dc2626;"></i> Needs Attention <span class="d2-badge" style="background:#dc2626;"><?= $_paTotalActions ?></span></span>
    <i class="bi bi-chevron-down arr"></i>
  </div>
  <div class="d2-section-body open">
    <?php if ($_adHqPend > 0): ?>
    <a href="?page=dashboard&tab=handover_queue" style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:#fef2f2;border-radius:10px;text-decoration:none;color:#991b1b;margin-bottom:6px;">
      <span style="font-size:20px;">💵</span>
      <div style="flex:1;font-size:13px;font-weight:700;"><?= $_adHqPend ?> handover<?= $_adHqPend>1?'s':'' ?> to confirm <span style="color:#dc2626;">$<?= number_format($_adHqAmt,2) ?></span></div>
      <i class="bi bi-chevron-right" style="color:#fca5a5;"></i>
    </a>
    <?php endif; ?>
    <?php if ($_adExpPend > 0): ?>
    <a href="?page=dashboard&tab=expense_approvals" style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:#fef3c7;border-radius:10px;text-decoration:none;color:#92400e;margin-bottom:6px;">
      <span style="font-size:20px;">🧾</span>
      <div style="flex:1;font-size:13px;font-weight:700;"><?= $_adExpPend ?> expense<?= $_adExpPend>1?'s':'' ?> to approve</div>
      <i class="bi bi-chevron-right" style="color:#fcd34d;"></i>
    </a>
    <?php endif; ?>
    <?php if ($_paCrmPend > 0): ?>
    <a href="?page=dashboard&tab=accounts_collections" style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:#fef3c7;border-radius:10px;text-decoration:none;color:#92400e;margin-bottom:6px;">
      <span style="font-size:20px;">🔄</span>
      <div style="flex:1;font-size:13px;font-weight:700;"><?= $_paCrmPend ?> payment<?= $_paCrmPend>1?'s':'' ?> pending CRM sync</div>
      <i class="bi bi-chevron-right" style="color:#fcd34d;"></i>
    </a>
    <?php endif; ?>
    <?php if ($_paFiberPend > 0): ?>
    <a href="?page=dashboard&tab=fiber_costs" style="display:flex;align-items:center;gap:12px;padding:10px 12px;background:#eff6ff;border-radius:10px;text-decoration:none;color:#1e40af;margin-bottom:6px;">
      <span style="font-size:20px;">🔧</span>
      <div style="flex:1;font-size:13px;font-weight:700;"><?= $_paFiberPend ?> fiber install<?= $_paFiberPend>1?'s':'' ?> need CRM invoice <span style="color:#dc2626;">$<?= number_format($_paFiberAmt,2) ?></span></div>
      <i class="bi bi-chevron-right" style="color:#93c5fd;"></i>
    </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════
     BLOCK 3: KPI STRIP — 4 key numbers only
     ══════════════════════════════════════════════════════════════ -->
<div class="d2-kpi">
  <div class="d2-kpi-card">
    <div class="d2-kpi-lbl">Agent Float</div>
    <div class="d2-kpi-val" style="color:#dc2626;">$<?= number_format($totalWalletBal,0) ?></div>
    <div class="d2-kpi-sub"><?= $activeCount ?> agents</div>
  </div>
  <div class="d2-kpi-card">
    <div class="d2-kpi-lbl">Field Cash</div>
    <div class="d2-kpi-val" style="color:#7c3aed;">$<?= number_format($totalFieldCash,0) ?></div>
    <div class="d2-kpi-sub"><?= count($_cpByStaff) ?> collectors</div>
  </div>
  <div class="d2-kpi-card">
    <div class="d2-kpi-lbl">Mth Recharge</div>
    <div class="d2-kpi-val" style="color:#2563eb;">$<?= number_format($monthRechargeAmt,0) ?></div>
    <div class="d2-kpi-sub"><?= count($monthRecharges) ?> approved</div>
  </div>
  <div class="d2-kpi-card">
    <div class="d2-kpi-lbl">KYC Month</div>
    <div class="d2-kpi-val" style="color:#059669;"><?= count($monthApps) ?></div>
    <div class="d2-kpi-sub"><?= count($todayApps) ?> today</div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     BLOCK 4: NETWORK HEALTH
     Run fiber_status widget first via output buffer to get accurate
     $_fsCounts (same source as Fiber Finance plugin: invoice_cache_*
     tables, client isActive flag). Cards then use those values.
     ══════════════════════════════════════════════════════════════ -->
<?php
// Run fiber_status widget inside output buffer — captures billing widget HTML
// Cards use fiber_services.json counts (Fiber Finance source = Splynx active services)
ob_start();
include __DIR__ . '/../../includes/widgets/fiber_status.php';
$_fiberWidgetHtml = ob_get_clean();
// Card values: from fiber_services.json (matches Fiber Finance "Active Services" KPI)
$_fiberActiveDisplay  = $_svcFiberActive > 0 ? $_svcFiberActive : ($_fsCounts['active'] ?? $_svcFiber);
$_fiberTotalDisplay   = $_svcFiberTotal  > 0 ? $_svcFiberTotal  : ($_fsTotal ?? 0);
$_fiberCustDisplay    = $_svcFiberCustomers > 0 ? $_svcFiberCustomers : 0;
// Billing widget: $_fsCounts from invoice_cache (fiber_status widget) — used for revenue health
$_fiberBillingActive  = $_fsCounts['active']   ?? 0;
$_fiberSuspended      = $_fsCounts['inactive'] ?? 0;
?>
<div class="d2-section">
  <div class="d2-section-hd open" onclick="d2Toggle(this)">
    <span><i class="bi bi-broadcast icon" style="color:#1d4ed8;"></i> Network & Revenue Health</span>
    <i class="bi bi-chevron-down arr"></i>
  </div>
  <div class="d2-section-body open">

    <!-- 4 service cards — fiber count from invoice_cache (same as Fiber Finance plugin) -->
    <div class="svc-grid">
      <div class="svc-card starlink">
        <div class="svc-ic">🛰️</div>
        <div class="svc-val"><?= $_svcStarlink ?: '—' ?></div>
        <div class="svc-lbl">Starlink</div>
        <div class="svc-sub">Active services</div>
      </div>
      <div class="svc-card fiber">
        <div class="svc-ic">🔌</div>
        <div class="svc-val"><?= $_fiberActiveDisplay ?: '—' ?></div>
        <div class="svc-lbl">Fiber (FTTH)</div>
        <div class="svc-sub"><?= $_fiberCustDisplay > 0 ? $_fiberCustDisplay.' customers' : ($_fiberTotalDisplay > 0 ? 'of '.$_fiberTotalDisplay.' total' : 'Active services') ?></div>
      </div>
      <div class="svc-card lte">
        <div class="svc-ic">📶</div>
        <div class="svc-val"><?= $_svcLte ?: '—' ?></div>
        <div class="svc-lbl">LTE / 4G</div>
        <div class="svc-sub">Active services</div>
      </div>
      <div class="svc-card customers">
        <div class="svc-ic">👥</div>
        <div class="svc-val"><?= $_activeCustomers ?: $_svcTotalActive ?></div>
        <div class="svc-lbl">Customers</div>
        <div class="svc-sub"><?= $_svcSuspended > 0 ? $_svcSuspended.' suspended' : $_svcTotalActive.' services' ?></div>
      </div>
    </div>

    <!-- Revenue health — fiber_services.json for network counts, invoice_cache for billing -->
    <div style="background:#f8fafc;border-radius:10px;padding:12px 14px;margin-bottom:12px;">
      <div style="font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;">Revenue Health</div>
      <?php if ($_svcFiberCustomers > 0): ?>
      <div class="d2-row">
        <span>🔌 Fiber active customers <span style="font-size:10px;color:#94a3b8;">(Splynx)</span></span>
        <strong style="color:#059669;"><?= number_format($_svcFiberCustomers) ?></strong>
      </div>
      <div class="d2-row">
        <span>📡 Fiber active services <span style="font-size:10px;color:#94a3b8;">(Splynx)</span></span>
        <strong style="color:#059669;"><?= number_format($_svcFiberActive) ?></strong>
      </div>
      <?php endif; ?>
      <?php if ($_fiberBillingActive > 0): ?>
      <div class="d2-row">
        <span>✅ CRM active clients <span style="font-size:10px;color:#94a3b8;">(billing)</span></span>
        <strong style="color:#2563eb;"><?= number_format($_fiberBillingActive) ?></strong>
      </div>
      <?php endif; ?>
      <?php if ($_fiberSuspended > 0): ?>
      <div class="d2-row">
        <span>⏸ CRM inactive (billing stopped)</span>
        <strong style="color:#d97706;"><?= number_format($_fiberSuspended) ?></strong>
      </div>
      <?php endif; ?>
      <?php if (!empty($_fsTotalDue) && $_fsTotalDue > 0): ?>
      <div class="d2-row">
        <span>🔴 Fiber revenue at risk</span>
        <strong style="color:#dc2626;">$<?= number_format($_fsTotalDue, 0) ?></strong>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fiber status widget HTML (already computed above via ob_start) -->
    <?php echo $_fiberWidgetHtml; ?>

  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     BLOCK 5: MONEY LOCATIONS
     Office + field staff in one block — no duplication
     ══════════════════════════════════════════════════════════════ -->
<div class="d2-section">
  <div class="d2-section-hd <?= $agentsWithIssues ? 'open' : '' ?>" onclick="d2Toggle(this)">
    <span>
      <i class="bi bi-geo-alt-fill icon" style="color:#059669;"></i> Money Locations
      <?php if ($totalFieldCash > 0): ?>
        <span style="font-size:11px;color:#7c3aed;font-weight:700;margin-left:6px;">$<?= number_format($totalFieldCash,0) ?> in field</span>
      <?php endif; ?>
      <?php if ($agentsOverLimit): ?><span class="d2-badge" style="background:#dc2626;"><?= count($agentsOverLimit) ?> over</span><?php endif; ?>
      <?php if (!empty($agentsAging)): ?><span class="d2-badge" style="background:#d97706;">⏱ <?= count($agentsAging) ?></span><?php endif; ?>
    </span>
    <i class="bi bi-chevron-down arr"></i>
  </div>
  <div class="d2-section-body <?= $agentsWithIssues ? 'open' : '' ?>">
    <!-- Office -->
    <div class="mm-office">
      <span style="font-size:24px;">🏢</span>
      <div style="flex:1;">
        <div style="font-size:11px;font-weight:700;color:#166534;text-transform:uppercase;">Office (Rupesh)</div>
        <div style="font-family:'Barlow Condensed',sans-serif;font-size:28px;font-weight:900;color:#059669;">$<?= number_format(max(0,$_cpOffice),2) ?></div>
      </div>
      <span style="font-size:20px;">✅</span>
    </div>
    <!-- Field staff with aging -->
    <?php foreach ($fieldPositions as $pos):
      $aid   = (int)($pos['agent_id']??0);
      $exp   = (float)($pos['cash_exposure']??0);
      if ($exp==0 && empty($pos['advance_balance'])) continue;
      $isOver= $exp > $_adGetLimit($aid);
      $lim   = $_adGetLimit($aid);
      $pct   = $lim > 0 ? min(100,($exp/$lim)*100) : 0;
      $ageH  = $ageByAgent[$aid]['hours'] ?? null;
      $tier  = $exp<50?'light':($exp<=200?'medium':'heavy');
      $ageOver = $ageH !== null && $ageH > ($agingTiersCfg[$tier]??24);
      $barCol= $ageOver||$isOver ? '#dc2626' : ($exp>$lim*0.7?'#d97706':'#7c3aed');
      if ($ageH!==null) $ageDisplay=$ageH>=48?number_format($ageH/24,1).'d':($ageH>=1?number_format($ageH,0).'h':'<1h');
    ?>
    <div class="mm-field" style="background:<?= $ageOver||$isOver ? '#fef2f2' : '#faf5ff' ?>;">
      <span style="font-size:18px;">💰</span>
      <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:700;color:#1e293b;"><?= h($pos['staff_name']) ?>
          <?php if ($isOver): ?><span style="font-size:9px;background:#fee2e2;color:#dc2626;font-weight:800;border-radius:4px;padding:1px 5px;margin-left:4px;">OVER</span><?php endif; ?>
        </div>
        <div style="height:3px;background:#e2e8f0;border-radius:2px;margin:4px 0;overflow:hidden;"><div style="height:100%;width:<?= number_format($pct,1) ?>%;background:<?= $barCol ?>;border-radius:2px;"></div></div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <?php if($pos['collections']>0): ?><span style="font-size:9px;background:#ecfdf5;color:#059669;border-radius:4px;padding:1px 5px;font-weight:700;">Col $<?= number_format($pos['collections'],0) ?></span><?php endif; ?>
          <?php if(!empty($pos['advance_balance'])&&$pos['advance_balance']>0): ?><span style="font-size:9px;background:#f5f3ff;color:#7c3aed;border-radius:4px;padding:1px 5px;font-weight:700;">Adv $<?= number_format($pos['advance_balance'],0) ?></span><?php endif; ?>
          <?php if($ageH!==null): ?><span style="font-size:9px;background:<?= $ageOver?'#fee2e2':'#f1f5f9' ?>;color:<?= $ageOver?'#dc2626':'#64748b' ?>;border-radius:4px;padding:1px 5px;font-weight:700;"><?= $ageOver?'⏱ ':'🕐 ' ?><?= $ageDisplay ?><?= $ageOver?' ⚠':'' ?></span><?php endif; ?>
        </div>
      </div>
      <div style="font-family:'Barlow Condensed',sans-serif;font-size:18px;font-weight:900;color:<?= $barCol ?>;text-align:right;flex-shrink:0;">$<?= number_format(max(0,$exp),2) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (!empty($_cpByStaff)): ?>
    <div style="display:flex;justify-content:space-between;padding:8px 12px;border-top:2px solid #e5e7eb;margin-top:6px;font-size:12px;font-weight:800;color:#374151;">
      <span>Total in field</span><span style="color:#7c3aed;">$<?= number_format($totalFieldCash,2) ?></span>
    </div>
    <?php endif; ?>
    <?php if (empty($_cpByStaff)): ?>
    <div style="padding:8px 12px;font-size:12px;color:#6b7280;font-style:italic;">All cash is in office — no field holdings.</div>
    <?php endif; ?>
    <div style="font-size:10px;color:#94a3b8;padding-top:6px;">Limit $<?= number_format($carryLimitCfg,0) ?> · <a href="?page=dashboard&tab=staff_cash_control" style="color:#7c3aed;text-decoration:none;">Full view →</a></div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     BLOCK 6: KYC PIPELINE (single funnel — detail drill-down)
     ══════════════════════════════════════════════════════════════ -->
<?php include __DIR__ . '/../../includes/widgets/kyc_funnel.php'; ?>

<!-- ══════════════════════════════════════════════════════════════
     BLOCK 7: FINANCIALS — Expenses + 30-Day Chart
     ══════════════════════════════════════════════════════════════ -->
<?php if ($_expSummary['total'] > 0 || !empty($_expSummary['pending'])): ?>
<div class="d2-section">
  <div class="d2-section-hd" onclick="d2Toggle(this)">
    <span><i class="bi bi-receipt icon" style="color:#d97706;"></i> Expenses <?= date('M Y') ?>
    <?php if (!empty($_expSummary['pending'])&&$_expSummary['pending']>0): ?><span class="d2-badge" style="background:#d97706;"><?= $_expSummary['pending'] ?> pending</span><?php endif; ?>
    </span>
    <i class="bi bi-chevron-down arr"></i>
  </div>
  <div class="d2-section-body">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:10px;">
      <div style="text-align:center;padding:10px 4px;background:#fef3c7;border-radius:10px;">
        <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;color:#d97706;">$<?= number_format($_expSummary['total_usd']??0,0) ?></div>
        <div style="font-size:9px;font-weight:700;color:#6b7280;">USD Total</div>
      </div>
      <div style="text-align:center;padding:10px 4px;background:#fef3c7;border-radius:10px;">
        <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;color:#d97706;"><?= number_format($_expSummary['total_ssp']??0,0) ?></div>
        <div style="font-size:9px;font-weight:700;color:#6b7280;">SSP Total</div>
      </div>
      <div style="text-align:center;padding:10px 4px;background:#f0fdf4;border-radius:10px;">
        <div style="font-family:'Barlow Condensed',sans-serif;font-size:22px;font-weight:900;color:#059669;"><?= $_expSummary['approved']??0 ?></div>
        <div style="font-size:9px;font-weight:700;color:#6b7280;">Approved</div>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-bottom:10px;">
      <div style="flex:1;padding:8px 10px;background:#f8fafc;border-radius:8px;font-size:11px;"><span style="font-weight:700;color:#374151;">Field:</span> <span style="color:#6b7280;"><?= $_expSummary['field_count']??0 ?> entries</span></div>
      <div style="flex:1;padding:8px 10px;background:#f8fafc;border-radius:8px;font-size:11px;"><span style="font-weight:700;color:#374151;">Advance:</span> <span style="color:#6b7280;"><?= $_expSummary['advance_count']??0 ?> entries</span></div>
    </div>
    <?php if (!empty($_expSummary['by_category'])): arsort($_expSummary['by_category']); ?>
    <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;">By Category</div>
    <?php foreach ($_expSummary['by_category'] as $_cat => $_catAmt): ?>
    <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #f8fafc;font-size:12px;">
      <span style="color:#6b7280;"><?= h($_cat) ?></span>
      <span style="font-weight:700;color:#374151;">$<?= number_format($_catAmt,2) ?></span>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- 30-Day Chart -->
<div class="d2-section">
  <div class="d2-section-hd" onclick="d2Toggle(this)">
    <span><i class="bi bi-bar-chart-fill icon" style="color:#D41C1C;"></i>30-Day Revenue</span>
    <i class="bi bi-chevron-down arr"></i>
  </div>
  <div class="d2-section-body">
    <canvas id="revenueTrendChart" height="80"></canvas>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
function d2Toggle(hd){
  hd.classList.toggle('open');
  var body=hd.nextElementSibling;
  body.classList.toggle('open');
  if(body.classList.contains('open')&&!body.dataset.chart){
    body.dataset.chart='1';
    var chartData=<?= json_encode($chartDays) ?>;
    var ctx=document.getElementById('revenueTrendChart');
    if(!ctx||!chartData.length) return;
    new Chart(ctx,{type:'bar',data:{labels:chartData.map(function(d){return d.date;}),datasets:[{data:chartData.map(function(d){return d.amount;}),backgroundColor:'rgba(212,28,28,.12)',borderColor:'#D41C1C',borderWidth:2,borderRadius:3}]},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return' $'+c.raw.toFixed(2);}}}},scales:{x:{grid:{display:false},ticks:{maxTicksLimit:8,font:{size:9}}},y:{grid:{color:'rgba(0,0,0,.04)'},ticks:{callback:function(v){return'$'+v;},font:{size:9}}}}}});
  }
}
</script>

<div style="height:80px;"></div>
