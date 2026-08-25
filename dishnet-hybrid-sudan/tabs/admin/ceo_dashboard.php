<?php
// ══════════════════════════════════════════════════════════════════════
// CEO Dashboard v2 — DishNet Africa at a Glance
// Rebuilt: cash chain, field exposure, AI bot stats, fiber pipeline,
// cashbook balance, SSP position, staff ledger, WA pulse, HRM snapshot.
// ══════════════════════════════════════════════════════════════════════
?>
<style>
.cd-page { max-width: 1100px; margin: 0 auto; padding: 0 4px 60px; }
.cd-hero  { background: linear-gradient(135deg,#0f172a,#1e293b); border-radius: 16px; padding: 16px 18px; margin-bottom: 16px; color: #fff; }
.cd-section { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin: 18px 0 8px; display: flex; align-items: center; gap: 6px; }
.cd-section::after { content:''; flex: 1; height: 1px; background: #e2e8f0; }
.cd-g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px; }
.cd-g3 { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin-bottom: 12px; }
.cd-g4 { display: grid; grid-template-columns: repeat(2,1fr); gap: 8px; margin-bottom: 12px; }
.cd-g6 { display: grid; grid-template-columns: repeat(3,1fr); gap: 8px; margin-bottom: 12px; }
@media(min-width:600px) { .cd-g4 { grid-template-columns: repeat(4,1fr); } .cd-g6 { grid-template-columns: repeat(6,1fr); } }
.cd-k  { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:12px 10px; text-align:center; }
.cd-kv { font-size:22px; font-weight:900; line-height:1.1; }
.cd-kl { font-size:9px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.3px; margin-top:2px; }
.cd-ks { font-size:10px; color:#94a3b8; margin-top:2px; }
.cd-alert-red    { background:#fef2f2; border-color:#fca5a5; }
.cd-alert-amber  { background:#fffbeb; border-color:#fde68a; }
.cd-alert-green  { background:#f0fdf4; border-color:#bbf7d0; }
.cd-alert-blue   { background:#eff6ff; border-color:#bfdbfe; }
.cd-alert-purple { background:#f5f3ff; border-color:#ddd6fe; }
.cd-card { background:#fff; border-radius:12px; border:1px solid #e2e8f0; padding:14px; margin-bottom:12px; }
.cd-card-title { font-size:11px; font-weight:800; color:#1e293b; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; }
.cd-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid #f1f5f9; font-size:12px; }
.cd-row:last-child { border-bottom:none; }
.cd-today { background:#0f172a; border-radius:14px; padding:16px; margin-bottom:12px; }
.cd-today-g { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
@media(max-width:480px) { .cd-today-g { grid-template-columns:repeat(2,1fr); } }
.cd-today-tile { background:#1e293b; border-radius:10px; padding:11px 8px; text-align:center; }
.cd-today-num { font-size:24px; font-weight:900; color:#fff; line-height:1; }
.cd-today-lbl { font-size:9px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }
.cd-today-sub { font-size:10px; color:#64748b; margin-top:2px; }
.cd-chain { background:#f8fafc; border-radius:10px; padding:10px 14px; display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600; margin-bottom:6px; flex-wrap:wrap; }
.cd-chain-amt { font-size:15px; font-weight:900; }
.cd-actions { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
.cd-actions a { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:8px 14px; font-size:11px; font-weight:700; color:#374151; text-decoration:none; white-space:nowrap; }
.cd-bar { background:#e2e8f0; border-radius:4px; height:6px; overflow:hidden; margin-top:4px; }
.cd-bar-fill { height:100%; border-radius:4px; }
</style>
<?php
if (!function_exists('human_time_diff_ceo')) {
    function human_time_diff_ceo(int $ts): string {
        $d = time() - $ts;
        if ($d < 60) return $d . 's ago';
        if ($d < 3600) return round($d/60) . 'm ago';
        if ($d < 86400) return round($d/3600) . 'h ago';
        return round($d/86400) . 'd ago';
    }
}

$pdo       = $store->getPdo();
$today     = date('Y-m-d');
$thisMonth = date('Y-m');
$lastMonth = date('Y-m', strtotime('-1 month'));

// ── JSON stores ──────────────────────────────────────────────────────
$collections = $store->load('payment_collections.json') ?? [];
$leads       = $store->load('leads.json') ?? [];
$apps        = $store->load('kyc_applications.json') ?? [];
$retailers   = $store->load('retailers.json') ?? [];
$searchIdx   = $store->load('client_search_index.json') ?? $store->load('ucrm_search_index.json') ?? [];
$clients     = $store->load('ucrm_clients_cache.json') ?? [];
$services    = $store->load('ucrm_services_cache.json') ?? [];
$plans       = $store->load('ucrm_plans_cache.json') ?? [];
$lteSubs     = $store->load('lte_subscribers.json') ?? [];
$allHov      = $store->load('cash_handovers.json') ?? [];
$planNames   = [];
foreach ($plans as $p) { if (!empty($p['id'])) $planNames[(int)$p['id']] = $p['name'] ?? ''; }

// ── Collections ──────────────────────────────────────────────────────
$todayCols     = array_filter($collections, fn($c) => str_starts_with($c['created_at']??'',$today));
$monthCols     = array_filter($collections, fn($c) => str_starts_with($c['created_at']??'',$thisMonth));
$lastMonthCols = array_filter($collections, fn($c) => str_starts_with($c['created_at']??'',$lastMonth));
$revenueToday     = array_sum(array_map(fn($c)=>(float)($c['amount']??0), $todayCols));
$revenueMonth     = array_sum(array_map(fn($c)=>(float)($c['amount']??0), $monthCols));
$revenueLastMonth = array_sum(array_map(fn($c)=>(float)($c['amount']??0), $lastMonthCols));
$commissionMonth  = array_sum(array_map(fn($c)=>(float)($c['commission']??0), $monthCols));
$revGrowth = $revenueLastMonth > 0 ? round(($revenueMonth-$revenueLastMonth)/$revenueLastMonth*100) : 0;
$overdue   = abs(array_sum(array_filter(array_map(fn($c)=>(float)($c['bal']??0),$searchIdx),fn($b)=>$b<0)));

// ── MRR ─────────────────────────────────────────────────────────────
$mrr = 0;
foreach ($services as $s) { if (($s['status']??9) <= 3) $mrr += (float)($s['price'] ?? $s['totalPrice'] ?? 0); }

// ── KYC breakdown ────────────────────────────────────────────────────
$todayApps     = array_filter($apps, fn($a) => str_starts_with($a['created_at'] ?? $a['submitted_at'] ?? '',$today));
$monthApps     = array_filter($apps, fn($a) => str_starts_with($a['created_at'] ?? $a['submitted_at'] ?? '',$thisMonth));
$lastMonthApps = array_filter($apps, fn($a) => str_starts_with($a['created_at'] ?? $a['submitted_at'] ?? '',$lastMonth));
$todayCash = 0; $todayCredit = 0; $todayCashAmt = 0;
$svcBreak = ['starlink'=>['c'=>0,'a'=>0],'fiber'=>['c'=>0,'a'=>0],'lte'=>['c'=>0,'a'=>0]];
foreach ($todayApps as $a) {
    $ct  = strtolower($a['customer_type'] ?? $a['connectivity_type'] ?? '');
    $amt = (float)($a['amount_charged'] ?? 0);
    $isCash = strtolower($a['sales_type'] ?? '') !== 'credit';
    if ($isCash) { $todayCash++; $todayCashAmt += $amt; } else $todayCredit++;
    $b = (strpos($ct,'fiber')!==false||strpos($ct,'ftth')!==false)?'fiber':((strpos($ct,'lte')!==false||strpos($ct,'4g')!==false)?'lte':'starlink');
    $svcBreak[$b]['c']++; $svcBreak[$b]['a'] += $amt;
}
$todaySalesTotal = $todayCash + $todayCredit;
$todayLeads = count(array_filter($leads, fn($l)=>str_starts_with($l['created_at']??$l['submitted_at']??'',$today)));

// ── Cashbook ─────────────────────────────────────────────────────────
$cbUsd = 0; $cbSsp = 0; $cbIn = 0; $cbOut = 0;
try {
    $cbUsd = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0) FROM cb_ledger WHERE project='dishnet' AND status NOT IN ('void','rejected')")->fetchColumn();
    $cbSsp = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN COALESCE(NULLIF(ssp_amount,0),amount) ELSE -COALESCE(NULLIF(ssp_amount,0),amount) END),0) FROM cb_ledger WHERE project='dishnet' AND currency='SSP' AND status NOT IN ('void','rejected')")->fetchColumn();
    $cbIn  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM cb_ledger WHERE direction='in' AND project='dishnet' AND status NOT IN ('void','rejected') AND date >= '{$thisMonth}-01'")->fetchColumn();
    $cbOut = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM cb_ledger WHERE direction='out' AND project='dishnet' AND status NOT IN ('void','rejected') AND date >= '{$thisMonth}-01'")->fetchColumn();
} catch (Throwable $e) {}

// ── Cash chain ───────────────────────────────────────────────────────
$pendSalesDiko = array_values(array_filter($allHov, fn($h)=>($h['status']??'')==='pending'&&($h['type']??'')!=='relay'));
$pendRelays    = array_values(array_filter($allHov, fn($h)=>($h['status']??'')==='pending'&&($h['type']??'')==='relay'));
$pendSalesAmt  = array_sum(array_map(fn($h)=>(float)($h['amount']??0), $pendSalesDiko));
$pendRelayAmt  = array_sum(array_map(fn($h)=>(float)($h['amount']??0), $pendRelays));
$fieldExposure = 0;
try {
    if (!class_exists('StaffLedgerService')) require_once dirname(__DIR__,2).'/lib/StaffLedgerService.php';
    $_slSvc = new StaffLedgerService($pdo);
    foreach ($retailers as $_r) {
        if ($_r['is_admin']??false) continue;
        if (!in_array($_r['role']??'',['sales','sales_staff','field_agent','collection','field_accountant'],true)) continue;
        $fieldExposure += max(0,(float)$_slSvc->balance((int)$_r['id'],'USD'));
    }
} catch (Throwable $e) {}

// ── Customers ────────────────────────────────────────────────────────
$totalClients    = count($clients);
$activeClients   = count(array_filter($searchIdx, fn($c)=>($c['status']??0)==1));
if (!$activeClients) $activeClients = count(array_filter($clients,fn($c)=>!($c['isLead']??false)));
$suspendedClients= count(array_filter($searchIdx,fn($c)=>in_array($c['status']??0,[5,6],true)));
$svcTypes=['starlink'=>0,'fiber'=>0,'lte'=>0,'other'=>0];
foreach ($services as $s) {
    if (($s['status']??9)>3) continue;
    $pn=strtolower($planNames[(int)($s['servicePlanId']??0)]??'');
    if (strpos($pn,'starlink')!==false||strpos($pn,'satellite')!==false) $svcTypes['starlink']++;
    elseif (strpos($pn,'fiber')!==false||strpos($pn,'ftth')!==false) $svcTypes['fiber']++;
    elseif (strpos($pn,'lte')!==false||strpos($pn,'4g')!==false) $svcTypes['lte']++;
    else $svcTypes['other']++;
}
$newThisMonth = count($monthApps); $newLastMonth = count($lastMonthApps);
$custGrowth   = $newLastMonth>0 ? round(($newThisMonth-$newLastMonth)/$newLastMonth*100) : 0;
$lteActive    = count(array_filter($lteSubs,fn($s)=>($s['status']??'')==='active'));

// ── AI Bot ───────────────────────────────────────────────────────────
$aiProvider=''; $aiCallsToday=0; $aiCallsMonth=0; $aiCostMonth=0.0; $aiConvsActive=0;
$aiProvider = $config['ai_provider'] ?? 'claude';
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ai_log (id INTEGER PRIMARY KEY AUTOINCREMENT,channel TEXT,input_text TEXT,output_text TEXT,input_tokens INTEGER DEFAULT 0,output_tokens INTEGER DEFAULT 0,cost_usd REAL DEFAULT 0,created_at TEXT DEFAULT (datetime('now')))");
    $aiCallsToday = (int)$pdo->query("SELECT COUNT(*) FROM wa_ai_log WHERE created_at >= '{$today}'")->fetchColumn();
    $aiCallsMonth = (int)$pdo->query("SELECT COUNT(*) FROM wa_ai_log WHERE created_at >= '{$thisMonth}-01'")->fetchColumn();
    $aiCostMonth  = (float)$pdo->query("SELECT COALESCE(SUM(cost_usd),0) FROM wa_ai_log WHERE created_at >= '{$thisMonth}-01'")->fetchColumn();
} catch (Throwable $e) {}
try { $aiConvsActive=(int)$pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE updated_at >= datetime('now','-24 hours') AND status='open'")->fetchColumn(); } catch(Throwable $e){}

// ── WA notifications ─────────────────────────────────────────────────
$waMsgToday=0; $waMsgMonth=0;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,sender TEXT,event TEXT,phone TEXT,preview TEXT,success INTEGER DEFAULT 0,http_code INTEGER,error TEXT,sent_at TEXT DEFAULT (datetime('now')))");
    $waMsgToday=(int)$pdo->query("SELECT COUNT(*) FROM notification_audit_log WHERE sent_at >= '{$today}'")->fetchColumn();
    $waMsgMonth=(int)$pdo->query("SELECT COUNT(*) FROM notification_audit_log WHERE sent_at >= '{$thisMonth}-01'")->fetchColumn();
} catch(Throwable $e){}

// ── Fiber pipeline ───────────────────────────────────────────────────
$fiberPending=0; $fiberDnSent=0; $fiberDnMissing=0; $fiberClosed=0;
try {
    $fiberPending = (int)$pdo->query("SELECT COUNT(*) FROM fiber_collection_jobs WHERE status='pending'")->fetchColumn();
    $fiberDnSent  = (int)$pdo->query("SELECT COUNT(*) FROM fiber_collection_jobs WHERE delivery_note_sent=1")->fetchColumn();
    $fiberTotal   = (int)$pdo->query("SELECT COUNT(*) FROM fiber_collection_jobs")->fetchColumn();
    $fiberDnMissing = max(0,$fiberTotal-$fiberDnSent);
    $fiberClosed  = (int)$pdo->query("SELECT COUNT(*) FROM fiber_collection_jobs WHERE ticket_closed=1")->fetchColumn();
} catch(Throwable $e){}

// ── Pipeline ─────────────────────────────────────────────────────────
$openLeads=(int)count(array_filter($leads,fn($l)=>!in_array($l['status']??'',['won','lost'])));
$wonLeads =(int)count(array_filter($leads,fn($l)=>($l['status']??'')==='won'));
$lostLeads=(int)count(array_filter($leads,fn($l)=>($l['status']??'')==='lost'));
$convRate =($wonLeads+$lostLeads)>0?round($wonLeads/($wonLeads+$lostLeads)*100):0;

// ── Operations ───────────────────────────────────────────────────────
$openTickets=0; try{$openTickets=(int)$pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')")->fetchColumn();}catch(Throwable $e){}
$declToday=0;$declMissing=0;$declDiscrep=0;
try {
    $declToday  =(int)$pdo->query("SELECT COUNT(*) FROM staff_cash_declarations WHERE declaration_date='{$today}'")->fetchColumn();
    $declDiscrep=(int)$pdo->query("SELECT COUNT(*) FROM staff_cash_declarations WHERE declaration_date='{$today}' AND status='discrepancy'")->fetchColumn();
    $fieldStaff =count(array_filter($retailers,fn($r)=>in_array($r['role']??'',['sales','sales_staff','field_agent','collection','field_accountant','support','support_leader'],true)&&($r['is_active']??true)));
    $declMissing=max(0,$fieldStaff-$declToday);
} catch(Throwable $e){}
$pendingApps=count(array_filter($apps,fn($a)=>($a['status']??'')==='pending'));

// ── Agent scoreboard ─────────────────────────────────────────────────
$agentPerf=[];
foreach($retailers as $r){
    if($r['is_admin']??false) continue;
    $rId=(int)$r['id'];
    $rRev=array_sum(array_map(fn($c)=>(float)($c['amount']??0),array_filter($monthCols,fn($c)=>(int)($c['retailer_id']??0)===$rId)));
    $rApps=count(array_filter($monthApps,fn($a)=>(int)($a['retailer_id']??0)===$rId));
    $rPend=count(array_filter($allHov,fn($h)=>(int)($h['from_id']??0)===$rId&&($h['status']??'')==='pending'));
    $rExp=0;
    try{ if(class_exists('StaffLedgerService')) $rExp=max(0,(float)(new StaffLedgerService($pdo))->balance($rId,'USD')); }catch(Throwable $e){}
    if($rRev>0||$rApps>0) $agentPerf[]=['name'=>$r['name']??'?','rev'=>$rRev,'apps'=>$rApps,'pend'=>$rPend,'exp'=>$rExp];
}
usort($agentPerf,fn($a,$b)=>$b['rev']<=>$a['rev']);

// ── HRM ──────────────────────────────────────────────────────────────
$hrmHead=0; $hrmPay=0;
try{$hrmHead=(int)$pdo->query("SELECT COUNT(*) FROM hrm_employees WHERE status='active'")->fetchColumn();
    $hrmPay=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM hrm_disbursements WHERE period LIKE '{$thisMonth}%' AND status='paid'")->fetchColumn();}catch(Throwable $e){}

// ── Sync freshness ───────────────────────────────────────────────────
$syncMeta=$store->load('ucrm_sync_meta.json')??$store->load('sync_last_run.json')??[];
$lastSync=$syncMeta['last_sync']??$syncMeta['last_run']??$syncMeta['finished_at']??null;
$syncAgo=$lastSync?human_time_diff_ceo((int)strtotime($lastSync)):'Unknown';

// ── Alerts ───────────────────────────────────────────────────────────
$alerts=[];
if(count($pendSalesDiko)) $alerts[]=['🔴',count($pendSalesDiko).' unconfirmed handover'.(count($pendSalesDiko)>1?'s':'').' from sales staff ($'.number_format($pendSalesAmt,0).') — Diko needs to confirm','red'];
if(count($pendRelays))    $alerts[]=['🟠',count($pendRelays).' relay'.(count($pendRelays)>1?'s':'').' from Diko pending ($'.number_format($pendRelayAmt,0).') — Rupesh to confirm','amber'];
if($declMissing>0)        $alerts[]=['🟡',"{$declMissing} staff member".($declMissing>1?'s':'')." haven't submitted today's cash declaration",'amber'];
if($overdue>500)          $alerts[]=['💸','$'.number_format($overdue,0).' overdue balance across customers','amber'];
if($fiberDnMissing>0)     $alerts[]=['📄',"{$fiberDnMissing} fiber installation".($fiberDnMissing>1?'s':'')." missing delivery note PDF",'amber'];
if($fieldExposure>2000)   $alerts[]=['💼','$'.number_format($fieldExposure,0).' total cash in field (high exposure)','amber'];
?>

<div class="cd-page">

<!-- HEADER -->
<div class="cd-hero">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
        <div>
            <div style="font-size:20px;font-weight:900;letter-spacing:-.5px;">📊 CEO Dashboard</div>
            <div style="font-size:12px;color:#64748b;margin-top:2px;">DishNet Africa · <?= date('l, d M Y') ?> · <?= date('g:i A') ?> EAT</div>
        </div>
        <div style="text-align:right;font-size:11px;color:#64748b;line-height:1.8;">
            <div>CRM sync: <?= $syncAgo ?></div>
            <div>AI: <?= strtoupper($aiProvider ?: 'off') ?> · <?= $aiCallsToday ?> replies today</div>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px;">
        <div style="background:rgba(255,255,255,.07);border-radius:12px;padding:12px 14px;">
            <div style="font-size:9px;font-weight:700;color:#475569;text-transform:uppercase;">Today's Cash</div>
            <div style="font-size:26px;font-weight:900;color:#10b981;letter-spacing:-1px;">$<?= number_format($revenueToday) ?></div>
            <div style="font-size:11px;color:#64748b;"><?= count($todayCols) ?> payments</div>
        </div>
        <div style="background:rgba(255,255,255,.07);border-radius:12px;padding:12px 14px;">
            <div style="font-size:9px;font-weight:700;color:#475569;text-transform:uppercase;"><?= date('M') ?> Revenue</div>
            <div style="font-size:26px;font-weight:900;color:#fff;letter-spacing:-1px;">$<?= number_format($revenueMonth) ?></div>
            <div style="font-size:11px;color:<?= $revGrowth>=0?'#10b981':'#f87171' ?>;"><?= $revGrowth>=0?'↑':'↓' ?><?= abs($revGrowth) ?>% vs <?= date('M',strtotime('-1 month')) ?></div>
        </div>
        <div style="background:rgba(255,255,255,.07);border-radius:12px;padding:12px 14px;">
            <div style="font-size:9px;font-weight:700;color:#475569;text-transform:uppercase;">MRR</div>
            <div style="font-size:26px;font-weight:900;color:#a78bfa;letter-spacing:-1px;">$<?= number_format($mrr) ?></div>
            <div style="font-size:11px;color:#64748b;">ARPU $<?= $activeClients>0?number_format($mrr/$activeClients,0):'0' ?></div>
        </div>
    </div>
</div>

<!-- ALERTS -->
<?php foreach($alerts as [$icon,$msg,$type]): ?>
<div style="background:<?= $type==='red'?'#fef2f2':'#fffbeb' ?>;border:1px solid <?= $type==='red'?'#fca5a5':'#fde68a' ?>;border-radius:10px;padding:9px 14px;margin-bottom:6px;font-size:12px;font-weight:600;color:<?= $type==='red'?'#991b1b':'#92400e' ?>;">
    <?= $icon ?> <?= h($msg) ?>
</div>
<?php endforeach; ?>

<!-- TODAY -->
<div class="cd-section">🔥 Today — <?= date('d M Y') ?></div>
<div class="cd-today">
    <div class="cd-today-g">
        <div class="cd-today-tile"><div class="cd-today-num"><?= $todaySalesTotal ?></div><div class="cd-today-lbl">Total KYC</div><div class="cd-today-sub"><?= count($monthApps) ?> /mo</div></div>
        <div class="cd-today-tile"><div class="cd-today-num" style="color:#10b981;"><?= $todayCash ?></div><div class="cd-today-lbl">💵 Cash</div><div class="cd-today-sub">$<?= number_format($todayCashAmt,0) ?></div></div>
        <div class="cd-today-tile"><div class="cd-today-num" style="color:#60a5fa;"><?= $todayCredit ?></div><div class="cd-today-lbl">📋 Credit</div><div class="cd-today-sub">CRM leads</div></div>
        <div class="cd-today-tile"><div class="cd-today-num" style="color:#fbbf24;"><?= $todayLeads ?></div><div class="cd-today-lbl">🎯 Leads</div><div class="cd-today-sub"><?= $openLeads ?> open</div></div>
    </div>
    <div style="height:1px;background:#1e293b;margin:10px 0;"></div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
        <?php foreach(['starlink'=>['🛰','#818cf8'],'fiber'=>['🔌','#34d399'],'lte'=>['📡','#60a5fa']] as $k=>[$ic,$cl]): ?>
        <div style="background:#1e293b;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:16px;"><?= $ic ?></div>
            <div style="font-size:20px;font-weight:900;color:<?= $cl ?>;"><?= $svcBreak[$k]['c'] ?></div>
            <div style="font-size:9px;font-weight:700;color:#475569;text-transform:uppercase;"><?= strtoupper($k) ?></div>
            <?php if($svcBreak[$k]['a']>0): ?><div style="font-size:10px;color:#64748b;">$<?= number_format($svcBreak[$k]['a'],0) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- CASHBOOK -->
<div class="cd-section">🏦 Cashbook — DishNet</div>
<div class="cd-g4">
    <div class="cd-k <?= $cbUsd<0?'cd-alert-red':'cd-alert-green' ?>">
        <div class="cd-kv" style="color:<?= $cbUsd>=0?'#15803d':'#991b1b' ?>;">$<?= number_format($cbUsd,0) ?></div>
        <div class="cd-kl">USD Balance</div><div class="cd-ks">Running total</div>
    </div>
    <div class="cd-k cd-alert-purple">
        <div class="cd-kv" style="color:#7c3aed;font-size:18px;"><?= number_format($cbSsp,0) ?> SSP</div>
        <div class="cd-kl">SSP Balance</div><div class="cd-ks">≈$<?= number_format($cbSsp/6000,0) ?></div>
    </div>
    <div class="cd-k cd-alert-blue">
        <div class="cd-kv" style="color:#1d4ed8;">$<?= number_format($cbIn,0) ?></div>
        <div class="cd-kl">IN <?= date('M') ?></div><div class="cd-ks">Cash received</div>
    </div>
    <div class="cd-k cd-alert-amber">
        <div class="cd-kv" style="color:#92400e;">$<?= number_format($cbOut,0) ?></div>
        <div class="cd-kl">OUT <?= date('M') ?></div><div class="cd-ks">Expenses</div>
    </div>
</div>

<!-- CASH CHAIN -->
<div class="cd-section">💸 Cash Chain — Sales → Diko → Rupesh</div>
<div class="cd-card">
    <?php if(empty($pendSalesDiko)&&empty($pendRelays)): ?>
    <div style="text-align:center;color:#15803d;font-size:13px;font-weight:700;padding:6px;">✅ All clear — no pending handovers</div>
    <?php else: ?>
    <?php foreach($pendSalesDiko as $ph): ?>
    <div class="cd-chain" style="border-left:3px solid #f87171;">
        <span style="color:#f87171;">⏳</span>
        <strong><?= h($ph['from_name']??'?') ?></strong> → <?= h($ph['to_name']??'Diko') ?>
        <span class="cd-chain-amt" style="color:#f87171;margin-left:auto;">$<?= number_format((float)($ph['amount']??0),0) ?></span>
        <span style="font-size:10px;color:#94a3b8;"><?= substr($ph['created_at']??'',11,5) ?></span>
    </div>
    <?php endforeach; ?>
    <?php foreach($pendRelays as $rel): ?>
    <div class="cd-chain" style="border-left:3px solid #f59e0b;">
        <span style="color:#f59e0b;">⛓</span>
        <strong><?= h($rel['from_name']??'Diko') ?></strong> → <?= h($rel['to_name']??'Rupesh') ?>
        <span style="background:#fef3c7;color:#92400e;font-size:9px;font-weight:700;border-radius:4px;padding:1px 6px;">RELAY</span>
        <span class="cd-chain-amt" style="color:#f59e0b;margin-left:auto;">$<?= number_format((float)($rel['amount']??0),0) ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;text-align:center;">
        <div><div style="font-size:18px;font-weight:900;color:#f87171;"><?= count($pendSalesDiko) ?></div><div style="font-size:9px;color:#64748b;font-weight:700;text-transform:uppercase;">Sales→Diko</div></div>
        <div><div style="font-size:18px;font-weight:900;color:#f59e0b;"><?= count($pendRelays) ?></div><div style="font-size:9px;color:#64748b;font-weight:700;text-transform:uppercase;">Diko→Rupesh</div></div>
        <div><div style="font-size:18px;font-weight:900;color:<?= $fieldExposure>2000?'#991b1b':'#15803d' ?>;">$<?= number_format($fieldExposure,0) ?></div><div style="font-size:9px;color:#64748b;font-weight:700;text-transform:uppercase;">Field Exposure</div></div>
    </div>
</div>

<!-- CUSTOMERS -->
<div class="cd-section">👥 Customers</div>
<div class="cd-g6">
    <div class="cd-k"><div class="cd-kv"><?= number_format($totalClients) ?></div><div class="cd-kl">Total</div></div>
    <div class="cd-k cd-alert-green"><div class="cd-kv" style="color:#15803d;"><?= number_format($activeClients) ?></div><div class="cd-kl">Active</div></div>
    <div class="cd-k cd-alert-red"><div class="cd-kv" style="color:#991b1b;"><?= number_format($suspendedClients) ?></div><div class="cd-kl">Suspended</div></div>
    <div class="cd-k cd-alert-blue"><div class="cd-kv" style="color:#1d4ed8;"><?= $newThisMonth ?></div><div class="cd-kl">New <?= date('M') ?></div><div class="cd-ks"><?= $custGrowth>=0?'↑':'↓' ?><?= abs($custGrowth) ?>% MoM</div></div>
    <div class="cd-k"><div style="font-size:12px;font-weight:800;margin-top:4px;line-height:1.8;"><div style="color:#818cf8;">🛰 <?= $svcTypes['starlink'] ?></div><div style="color:#34d399;">🔌 <?= $svcTypes['fiber'] ?></div><div style="color:#60a5fa;">📡 <?= $svcTypes['lte'] ?></div></div></div>
    <div class="cd-k cd-alert-purple"><div class="cd-kv" style="color:#7c3aed;"><?= $lteActive ?></div><div class="cd-kl">LTE Active</div></div>
</div>

<!-- AGENTS + PIPELINE -->
<div class="cd-section">🏆 Agents &amp; Pipeline — <?= date('M Y') ?></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
    <div class="cd-card">
        <div class="cd-card-title">Agent Scoreboard <a href="?page=dashboard&tab=staff_cash_control" style="font-size:10px;color:#1d4ed8;font-weight:600;text-decoration:none;">Full →</a></div>
        <?php foreach(array_slice($agentPerf,0,6) as $i=>$ap): ?>
        <div class="cd-row">
            <div>
                <span style="font-size:10px;color:#94a3b8;margin-right:4px;">#<?= $i+1 ?></span>
                <span style="font-weight:600;"><?= h($ap['name']) ?></span>
                <?php if($ap['pend']>0): ?><span style="background:#fee2e2;color:#991b1b;font-size:9px;font-weight:700;border-radius:4px;padding:1px 5px;margin-left:4px;">⏳<?= $ap['pend'] ?></span><?php endif; ?>
            </div>
            <div style="text-align:right;">
                <div style="font-size:13px;font-weight:800;color:#15803d;">$<?= number_format($ap['rev'],0) ?></div>
                <?php if($ap['exp']>0): ?><div style="font-size:10px;color:#f59e0b;">$<?= number_format($ap['exp'],0) ?> holding</div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if(empty($agentPerf)): ?><div style="color:#9ca3af;font-size:11px;text-align:center;padding:10px;">No activity this month</div><?php endif; ?>
    </div>
    <div class="cd-card">
        <div class="cd-card-title">Pipeline <a href="?page=dashboard&tab=all_leads" style="font-size:10px;color:#1d4ed8;font-weight:600;text-decoration:none;">View →</a></div>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:10px;">
            <div style="text-align:center;"><div style="font-size:22px;font-weight:900;color:#f59e0b;"><?= $openLeads ?></div><div style="font-size:9px;font-weight:700;color:#6b7280;text-transform:uppercase;">Active</div></div>
            <div style="text-align:center;"><div style="font-size:22px;font-weight:900;color:#15803d;"><?= $wonLeads ?></div><div style="font-size:9px;font-weight:700;color:#6b7280;text-transform:uppercase;">Won</div></div>
            <div style="text-align:center;"><div style="font-size:22px;font-weight:900;color:#991b1b;"><?= $lostLeads ?></div><div style="font-size:9px;font-weight:700;color:#6b7280;text-transform:uppercase;">Lost</div></div>
            <div style="text-align:center;"><div style="font-size:22px;font-weight:900;color:<?= $convRate>=20?'#15803d':($convRate>=10?'#f59e0b':'#991b1b') ?>;"><?= $convRate ?>%</div><div style="font-size:9px;font-weight:700;color:#6b7280;text-transform:uppercase;">Conv. Rate</div></div>
        </div>
        <div class="cd-bar"><div class="cd-bar-fill" style="width:<?= $convRate ?>%;background:#15803d;"></div></div>
    </div>
</div>

<!-- FIBER PIPELINE -->
<div class="cd-section">🔌 Fiber Pipeline</div>
<div class="cd-g4">
    <div class="cd-k <?= $fiberPending>0?'cd-alert-amber':'' ?>"><div class="cd-kv" style="color:<?= $fiberPending>0?'#92400e':'#15803d' ?>;"><?= $fiberPending ?></div><div class="cd-kl">Pending Invoice</div><div class="cd-ks">Collection jobs</div></div>
    <div class="cd-k <?= $fiberDnMissing>0?'cd-alert-red':'' ?>"><div class="cd-kv" style="color:<?= $fiberDnMissing>0?'#991b1b':'#15803d' ?>"><?= $fiberDnSent ?></div><div class="cd-kl">Notes Sent</div><div class="cd-ks"><?= $fiberDnMissing ?> missing</div></div>
    <div class="cd-k"><div class="cd-kv" style="color:#15803d;"><?= $fiberClosed ?></div><div class="cd-kl">Tickets Closed</div><div class="cd-ks">Auto-Splynx</div></div>
    <div class="cd-k cd-alert-red"><div class="cd-kv" style="color:#991b1b;">$<?= number_format($overdue,0) ?></div><div class="cd-kl">Overdue</div><div class="cd-ks">All customers</div></div>
</div>

<!-- AI BOT -->
<div class="cd-section">🤖 WhatsApp Bot — <?= strtoupper($aiProvider ?: 'off') ?></div>
<div class="cd-g4">
    <div class="cd-k cd-alert-purple"><div class="cd-kv" style="color:#7c3aed;"><?= $aiCallsToday ?></div><div class="cd-kl">AI Replies Today</div><div class="cd-ks"><?= $aiCallsMonth ?> this month</div></div>
    <div class="cd-k"><div class="cd-kv"><?= $aiConvsActive ?></div><div class="cd-kl">Active Convos</div><div class="cd-ks">Last 24h</div></div>
    <div class="cd-k"><div class="cd-kv"><?= $waMsgToday ?></div><div class="cd-kl">WA Sent Today</div><div class="cd-ks"><?= number_format($waMsgMonth) ?> this month</div></div>
    <div class="cd-k cd-alert-amber"><div class="cd-kv" style="color:#92400e;font-size:18px;">$<?= number_format($aiCostMonth,3) ?></div><div class="cd-kl">AI Cost <?= date('M') ?></div></div>
</div>

<!-- OPS -->
<div class="cd-section">⚡ Operations</div>
<div class="cd-g4">
    <div class="cd-k <?= $openTickets>10?'cd-alert-red':($openTickets>5?'cd-alert-amber':'') ?>">
        <div class="cd-kv" style="color:<?= $openTickets>10?'#991b1b':($openTickets>5?'#92400e':'#15803d') ?>;"><?= $openTickets ?></div>
        <div class="cd-kl">Open Tickets</div>
        <a href="?page=dashboard&tab=splynx_noc" style="font-size:10px;color:#1d4ed8;text-decoration:none;">View →</a>
    </div>
    <div class="cd-k <?= $declMissing>0?'cd-alert-red':($declDiscrep>0?'cd-alert-amber':'cd-alert-green') ?>">
        <div class="cd-kv" style="color:<?= $declMissing>0?'#991b1b':($declDiscrep>0?'#92400e':'#15803d') ?>;"><?= $declToday ?>/<?= $declToday+$declMissing ?></div>
        <div class="cd-kl">Declarations</div>
        <div class="cd-ks"><?= $declMissing>0?"{$declMissing} missing":($declDiscrep>0?"{$declDiscrep} discrep.":'All clear') ?></div>
    </div>
    <div class="cd-k <?= $pendingApps>5?'cd-alert-amber':'' ?>">
        <div class="cd-kv" style="color:<?= $pendingApps>5?'#92400e':'#1e293b' ?>;"><?= $pendingApps ?></div>
        <div class="cd-kl">Pending KYCs</div>
        <a href="?page=dashboard&tab=all_apps" style="font-size:10px;color:#1d4ed8;text-decoration:none;">Process →</a>
    </div>
    <div class="cd-k <?= $hrmHead>0?'':'cd-alert-blue' ?>">
        <?php if($hrmHead>0): ?>
        <div class="cd-kv"><?= $hrmHead ?></div><div class="cd-kl">Active Staff</div>
        <?php if($hrmPay>0): ?><div class="cd-ks">Payroll $<?= number_format($hrmPay,0) ?></div><?php endif; ?>
        <?php else: ?>
        <div class="cd-kv" style="font-size:14px;"><?= $syncAgo ?></div>
        <div class="cd-kl">Last Sync</div>
        <?php endif; ?>
    </div>
</div>

<!-- QUICK LINKS -->
<div class="cd-section">🔗 Quick Links</div>
<div class="cd-actions">
    <a href="?page=dashboard&tab=daily_report">📋 Daily Report</a>
    <a href="?page=dashboard&tab=handover_queue">💸 Handover Queue</a>
    <a href="?page=dashboard&tab=field_handover">⛓ Field Chain</a>
    <a href="?page=dashboard&tab=cashbook">📒 Cashbook</a>
    <a href="?page=dashboard&tab=all_collections">💰 Collections</a>
    <a href="?page=dashboard&tab=all_leads">🎯 Leads</a>
    <a href="?page=dashboard&tab=fiber_pipeline">🔌 Fiber</a>
    <a href="?page=dashboard&tab=lte_dashboard">📡 LTE</a>
    <a href="?page=dashboard&tab=splynx_noc">🔧 NOC</a>
    <a href="?page=dashboard&tab=whatsapp">🤖 WA Bot</a>
    <a href="?page=dashboard&tab=staff_cash_control">👥 Staff Cash</a>
    <a href="?page=dashboard&tab=settings">⚙️ Settings</a>
</div>

</div>
