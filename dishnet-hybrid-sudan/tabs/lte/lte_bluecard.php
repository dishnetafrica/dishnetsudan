<?php
// 
// Tab: lte_bluecard    BlueCard Portal (DishNet 4G)
// DB: dishnetss_bluecard  |  DishNet users: company_id = 1
// Active users: data_mgmt.end_date >= NOW() + is_active=1 + is_deactive=0
// Amounts: balance_topup.amount & product_offering.amount are in CENTS  100
//          load_money.amount is in whole USD
// 
require_once dirname(__DIR__, 2) . '/lib/BlueCardDb.php';

//  Helpers 
function bch($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function bcAmtCents($v): string { return $v === null ? '' : '$' . number_format((float)$v / 100, 2); }
function bcAmtUsd($v): string   { return $v === null ? '' : '$' . number_format((float)$v, 2); }
function bcDate(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '';
    try { return (new DateTime($d))->format('d M Y'); } catch (Throwable $e) { return bch($d); }
}
function bcDatetime(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '';
    try { return (new DateTime($d))->format('d M Y H:i'); } catch (Throwable $e) { return bch($d); }
}
function bcExpiry(?string $d): string {
    if (!$d || $d === '0000-00-00 00:00:00') return '<span class="bc-pill no-plan">No Plan</span>';
    try {
        $dt   = new DateTime($d);
        $diff = (new DateTime())->diff($dt);
        $days = (int)$diff->format('%R%a');
        if ($days < 0)  return '<span class="bc-pill expired">Expired ' . abs($days) . 'd ago</span>';
        if ($days === 0) return '<span class="bc-pill expiring">Expires today</span>';
        if ($days <= 5)  return '<span class="bc-pill expiring">Expires in ' . $days . 'd</span>';
        return '<span class="bc-pill active-plan">' . $dt->format('d M Y') . '</span>';
    } catch (Throwable $e) { return bch($d); }
}

//  Connect 
$bcConfigured = BlueCardDb::isConnected($config);

//  Routing 
$bcTab = $_GET['bc'] ?? 'overview';
$bcValidTabs = ['overview','customers','simcards','loadmoney','recharge','services','plans','agents','passbook','commissions','lmconfig','kyc','simmanage','kycrecords','customerdetail','retailerdetail','agentmerge','retailerledger','settings'];
if (!in_array($bcTab, $bcValidTabs, true)) $bcTab = 'overview';
$bcPage = max(1, (int)($_GET['bcpg'] ?? 1));
$bcPer  = 50;
$bcQ    = trim($_GET['bcq'] ?? '');
$bcSt   = $_GET['bcst'] ?? '';

//  Data fetch  -- local SQLite for synced tables, live HTTP only for write tabs 
$bcRows       = [];
$bcTotal      = 0;
$bcPages      = 1;
$bcStats      = [];
$bcFetchError = null;

// Local SQLite helper
function _bcPdo() {
    global $store;
    return $store->getPdo();
}
function _bcPage(PDO $pdo, string $sql, array $params, int $page, int $per=50): array {
    $cs = $pdo->prepare('SELECT COUNT(*) FROM (' . $sql . ') _t');
    $cs->execute($params); $total = (int)$cs->fetchColumn();
    $pages = max(1, (int)ceil($total/$per));
    $page  = min($page, $pages);
    $ds = $pdo->prepare($sql . ' LIMIT ' . $per . ' OFFSET ' . ($page-1)*$per);
    $ds->execute($params);
    return ['rows'=>$ds->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'pages'=>$pages,'page'=>$page];
}

try {

// Pending counts from local tables (no live call needed)
$_bcPdo = _bcPdo();
try {
    $bcStats['load_pending']     = (int)$_bcPdo->query("SELECT COUNT(*) FROM lte_load_money WHERE status='Pending'")->fetchColumn();
    $bcStats['recharge_pending'] = $bcConfigured ? (int)((BlueCardDb::fetch($config,'bc_pending_counts')??[])['recharge_pending']??0) : 0;
} catch (Throwable $e) { $bcStats['load_pending']=0; $bcStats['recharge_pending']=0; }

if ($bcTab === 'overview') {
    // Mostly from local SQLite, recent_recharges from live
    $now = date('Y-m-d H:i:s');
    $in7 = date('Y-m-d H:i:s', strtotime('+7 days'));
    $pdo2 = _bcPdo();
    $bcStats['total_cust']      = (int)$pdo2->query("SELECT COUNT(*) FROM lte_subscribers WHERE deleted_at IS NULL")->fetchColumn();
    $bcStats['active_cust']     = (int)$pdo2->query("SELECT COUNT(*) FROM lte_subscribers WHERE deleted_at IS NULL AND status='active' AND end_date >= '{$now}'")->fetchColumn();
    $bcStats['expiring_7d']     = (int)$pdo2->query("SELECT COUNT(*) FROM lte_subscribers WHERE deleted_at IS NULL AND end_date BETWEEN '{$now}' AND '{$in7}'")->fetchColumn();
    $bcStats['sim_instock']     = (int)$pdo2->query("SELECT COUNT(*) FROM lte_sims WHERE deleted_at IS NULL AND status IN ('stock','In stock')")->fetchColumn();
    $bcStats['sim_deployed']    = (int)$pdo2->query("SELECT COUNT(*) FROM lte_sims WHERE deleted_at IS NULL AND status NOT IN ('stock','In stock','Internal usage','retired','lost')")->fetchColumn();
    $bcStats['load_pending_amt']= (float)$pdo2->query("SELECT COALESCE(SUM(amount),0) FROM lte_load_money WHERE status='Pending'")->fetchColumn();
    $bcStats['plans_active']    = (int)$pdo2->query("SELECT COUNT(*) FROM lte_packages WHERE is_active=1")->fetchColumn();
    // Recent load money from local
    $bcStats['recent_loads']    = $pdo2->query("SELECT lm.bluecard_id as id, lm.amount, lm.approve_amount, lm.status, lm.created_at, a.firstname, a.lastname, a.mobile FROM lte_load_money lm LEFT JOIN lte_agents a ON a.bluecard_id=lm.bluecard_user_id ORDER BY lm.bluecard_id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    // Recent recharges still from live (pending_recharge not synced)
    if ($bcConfigured) {
        $d = BlueCardDb::fetch($config, 'bc_overview');
        if ($d && !empty($d['recent_recharges'])) $bcStats['recent_recharges'] = $d['recent_recharges'];
    }
    $bcStats['recharge_pending'] = $bcConfigured ? (int)((BlueCardDb::fetch($config,'bc_pending_counts')??[])['recharge_pending']??0) : 0;
}
elseif ($bcTab === 'customers') {
    $pdo2 = _bcPdo();
    $where = 's.deleted_at IS NULL'; $params2 = [];
    if ($bcQ !== '') { $where .= ' AND (s.name LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)'; $lk="%{$bcQ}%"; $params2=[$lk,$lk,$lk]; }
    if ($bcSt==='active')   $where .= " AND s.status='active' AND s.end_date >= '" . date('Y-m-d H:i:s') . "'";
    if ($bcSt==='inactive') $where .= " AND (s.status!='active' OR s.end_date < '" . date('Y-m-d H:i:s') . "')";
    $sql2 = "SELECT s.id, s.bluecard_id, s.name as firstname, '' as lastname, s.phone as mobile, s.email, s.wallet_balance as wallet, s.status, s.end_date as plan_end, s.offer_id, s.area, s.address, s.created_at, CASE WHEN s.status='active' AND s.end_date >= datetime('now') THEN 1 ELSE 0 END as is_active, 0 as is_deactive, p.name as plan_name, CAST(COUNT(sc.id) AS INTEGER) as sim_count FROM lte_subscribers s LEFT JOIN lte_packages p ON p.bluecard_id=s.offer_id LEFT JOIN lte_sims sc ON sc.subscriber_id=s.id AND sc.deleted_at IS NULL WHERE {$where} GROUP BY s.id ORDER BY s.bluecard_id DESC";
    $d = _bcPage($pdo2, $sql2, $params2, $bcPage);
    $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages']; $bcPage=$d['page'];
}
elseif ($bcTab === 'simcards') {
    $pdo2 = _bcPdo();
    $where = 'deleted_at IS NULL'; $params2 = [];
    if ($bcSt!=='') { $where .= ' AND status=?'; $params2[]=$bcSt; }
    if ($bcQ!=='')  { $where .= ' AND (imsi LIKE ? OR msisdn LIKE ?)'; $lk="%{$bcQ}%"; $params2[]=$lk; $params2[]=$lk; }
    $sql2 = "SELECT id, id as bluecard_id, imsi, msisdn, status, created_at FROM lte_sims WHERE {$where} ORDER BY id DESC";
    $d = _bcPage($pdo2, $sql2, $params2, $bcPage);
    $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages']; $bcPage=$d['page'];
}
elseif ($bcTab === 'loadmoney') {
    $bcAgentId = (int)($_GET['bcaid'] ?? 0);
    $pdo2 = _bcPdo();
    $where = '1=1'; $params2 = [];
    if ($bcSt!=='')       { $where .= ' AND lm.status=?';            $params2[]=$bcSt; }
    if ($bcAgentId>0)     { $where .= ' AND lm.bluecard_user_id=?';  $params2[]=$bcAgentId; }
    elseif ($bcQ!=='')    { $where .= ' AND (a.name LIKE ? OR a.mobile LIKE ?)'; $lk="%{$bcQ}%"; $params2[]=$lk; $params2[]=$lk; }
    $sql2 = "SELECT lm.bluecard_id as id, lm.amount, lm.approve_amount, lm.commission, lm.status, lm.lm_type as type, lm.created_at, a.firstname, a.lastname, a.mobile, a.name as master_name FROM lte_load_money lm LEFT JOIN lte_agents a ON a.bluecard_id=lm.bluecard_user_id WHERE {$where} ORDER BY lm.bluecard_id DESC";
    $d = _bcPage($pdo2, $sql2, $params2, $bcPage);
    $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages']; $bcPage=$d['page'];
}
elseif ($bcTab === 'recharge') {
    if ($bcConfigured) {
        $d = BlueCardDb::fetch($config, 'bc_recharge', ['page'=>$bcPage,'q'=>$bcQ,'st'=>$bcSt]);
        if ($d) { $bcRows=$d['rows']??[]; $bcTotal=$d['total']??0; $bcPages=$d['pages']??1; $bcPage=$d['page']??1; }
    }
}
elseif ($bcTab === 'services') {
    $pdo2 = _bcPdo();
    $where = 's.deleted_at IS NULL'; $params2 = [];
    if ($bcQ!=='') { $where .= ' AND (s.name LIKE ? OR s.phone LIKE ?)'; $lk="%{$bcQ}%"; $params2[]=$lk; $params2[]=$lk; }
    $sql2 = "SELECT s.bluecard_id as id, s.name as firstname, '' as lastname, s.phone as mobile, s.imsi as service_id, s.imsi, s.msisdn as serviceIdentity, CASE WHEN s.status='active' AND s.end_date>=datetime('now') THEN 1 ELSE 0 END as isServiceEnabled, s.status as state, s.end_date, p.name as plan_name, p.price as plan_price FROM lte_subscribers s LEFT JOIN lte_packages p ON p.bluecard_id=s.offer_id WHERE {$where} ORDER BY s.end_date ASC";
    $d = _bcPage($pdo2, $sql2, $params2, $bcPage);
    $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages']; $bcPage=$d['page'];
}
elseif ($bcTab === 'plans') {
    $pdo2 = _bcPdo();
    $where = '1=1'; $params2 = [];
    if ($bcSt==='active')   { $where = 'is_active=1'; }
    if ($bcSt==='inactive') { $where = 'is_active=0'; }
    $sql2 = "SELECT bluecard_id as id, name, description, price_cents as amount, price, duration_days as days, is_active, is_popular, created_at FROM lte_packages WHERE {$where} ORDER BY sort_order ASC, bluecard_id ASC";
    $d = _bcPage($pdo2, $sql2, $params2, $bcPage);
    $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages']; $bcPage=$d['page'];
}
elseif ($bcTab === 'agents') {
    $pdo2 = _bcPdo();
    $where = '1=1'; $params2 = [];
    if ($bcSt!=='') { $where .= ' AND role_name=?'; $params2[]=$bcSt; }
    if ($bcQ!=='')  { $where .= ' AND (name LIKE ? OR mobile LIKE ? OR email LIKE ?)'; $lk="%{$bcQ}%"; $params2[]=$lk; $params2[]=$lk; $params2[]=$lk; }
    $sql2 = "SELECT bluecard_id as id, firstname, lastname, name, mobile, email, role_name, role_display, master_name, wallet, lm_commission as lm_commission, is_active, created_at FROM lte_agents WHERE {$where} ORDER BY bluecard_id DESC";
    $d = _bcPage($pdo2, $sql2, $params2, $bcPage);
    $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages']; $bcPage=$d['page'];
}
elseif ($bcTab === 'passbook') {
    $pdo2 = _bcPdo();
    $where = '1=1'; $params2 = [];
    if ($bcQ!=='') {
        // Try to find agent bluecard_id by name/mobile
        $aRow = $pdo2->prepare("SELECT bluecard_id FROM lte_agents WHERE name LIKE ? OR mobile LIKE ? LIMIT 1");
        $lk="%{$bcQ}%"; $aRow->execute([$lk,$lk]); $aId=$aRow->fetchColumn();
        if ($aId) { $where .= ' AND pb.bluecard_user_id=?'; $params2[]=(int)$aId; }
    }
    if ($bcSt==='Credit'||$bcSt==='Debit') { $where .= ' AND pb.entry_type=?'; $params2[]=$bcSt; }
    $sql2 = "SELECT pb.bluecard_id as id, pb.amount, pb.prev_balance as previous_balance, pb.curr_balance as current_balance, pb.entry_type as type, pb.trx_no, pb.description, pb.created_at, a.firstname, a.lastname, a.mobile FROM lte_passbooks pb LEFT JOIN lte_agents a ON a.bluecard_id=pb.bluecard_user_id WHERE {$where} ORDER BY pb.bluecard_id DESC";
    $d = _bcPage($pdo2, $sql2, $params2, $bcPage);
    $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages']; $bcPage=$d['page'];
}
elseif ($bcTab === 'commissions') {
    if ($bcConfigured) {
        $d = BlueCardDb::fetch($config, 'bc_commissions', ['page'=>$bcPage,'q'=>$bcQ]);
        if ($d) { $bcRows=$d['rows']??[]; $bcTotal=$d['total']??0; $bcPages=$d['pages']??1; $bcPage=$d['page']??1; }
    }
}
elseif ($bcTab === 'lmconfig') {
    if ($bcConfigured) {
        $d = BlueCardDb::fetch($config, 'bc_lmconfig');
        if ($d) $bcStats['lmconfig'] = $d;
    }
}
elseif ($bcTab === 'kyc') {
    // SIMs from local, plans from local
    $pdo2 = _bcPdo();
    $bcStats['kyc_sims']  = $pdo2->query("SELECT id, imsi, msisdn FROM lte_sims WHERE deleted_at IS NULL AND status IN ('stock','In stock') ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $bcStats['kyc_plans'] = $pdo2->query("SELECT bluecard_id as id, name, description, price_cents as amount, price as price_usd, duration_days as days FROM lte_packages WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
}
elseif ($bcTab === 'kycrecords') {
    // Read from local SQLite backup  not from BlueCard
    // Skip BlueCard fetch entirely for this tab
}
elseif ($bcTab === 'customerdetail') {
    $bcCuid  = (int)($_GET['bcuid'] ?? 0);
    $bcCsub  = $_GET['bcsub'] ?? 'overview';
    $bcCpage = max(1,(int)($_GET['bcpg'] ?? 1));
    $r = BlueCardDb::fetch($config, 'bc_customer_detail', ['uid'=>$bcCuid]);
    $bcCustData = $r ?? [];
    if ($bcCsub === 'services') {
        $r2 = BlueCardDb::fetch($config, 'bc_customer_services', ['uid'=>$bcCuid,'page'=>$bcCpage]);
        $bcCustData['services'] = $r2 ?? null;
    } elseif ($bcCsub === 'passbook') {
        $r2 = BlueCardDb::fetch($config, 'bc_customer_passbook', ['uid'=>$bcCuid,'page'=>$bcCpage]);
        $bcCustData['passbook'] = $r2 ?? null;
    } elseif ($bcCsub === 'invoices') {
        $r2 = BlueCardDb::fetch($config, 'bc_customer_invoices', ['uid'=>$bcCuid,'page'=>$bcCpage]);
        $bcCustData['invoices'] = $r2 ?? null;
    }
}
elseif ($bcTab === 'retailerdetail') {
    $bcRuid  = (int)($_GET['bcuid'] ?? 0);
    $bcRsub  = $_GET['bcsub'] ?? 'overview';
    $bcRpage = max(1,(int)($_GET['bcpg'] ?? 1));
    $bcRData = [];
    $pdo2    = _bcPdo();
    $monthStart = date('Y-m-01');

    // Load agent profile from local
    $agSt = $pdo2->prepare("SELECT * FROM lte_agents WHERE bluecard_id=? LIMIT 1");
    $agSt->execute([$bcRuid]);
    $agRow = $agSt->fetch(PDO::FETCH_ASSOC);
    if ($agRow) {
        $bcRData = $agRow;
        // Calculate stats locally
        $tl = $pdo2->prepare("SELECT COALESCE(SUM(approve_amount),0) FROM lte_load_money WHERE bluecard_user_id=? AND status='Approve'");
        $tl->execute([$bcRuid]); $bcRData['total_loaded_cents'] = (int)round($tl->fetchColumn()*100);
        $ts = $pdo2->prepare("SELECT COALESCE(SUM(amount_paid),0) FROM lte_renewals r JOIN lte_subscribers s ON s.id=r.subscriber_id JOIN lte_agents a ON a.bluecard_id=? WHERE r.network='dishnet_4g'");
        $ts->execute([$bcRuid]); $bcRData['total_sold_cents'] = 0; // renewals not linked to agent yet
        // Use load_money as proxy for sold: total_loaded - current_wallet = cash owed
        $bcRData['month_loaded_cents'] = (int)round((float)$pdo2->prepare("SELECT COALESCE(SUM(approve_amount),0) FROM lte_load_money WHERE bluecard_user_id=? AND status='Approve' AND created_at>=?")->execute([$bcRuid,$monthStart]) ? 0 : 0);
        $ml=$pdo2->prepare("SELECT COALESCE(SUM(approve_amount),0) FROM lte_load_money WHERE bluecard_user_id=? AND status='Approve' AND created_at>=?"); $ml->execute([$bcRuid,$monthStart]); $bcRData['month_loaded_cents']=(int)round((float)$ml->fetchColumn()*100);
        $cc=$pdo2->prepare("SELECT COUNT(*) FROM lte_subscribers WHERE agent_id=? AND deleted_at IS NULL"); $cc->execute([$bcRuid]); $bcRData['customer_count']=(int)$cc->fetchColumn();
        $rc=$pdo2->prepare("SELECT COUNT(*) FROM lte_load_money WHERE bluecard_user_id=?"); $rc->execute([$bcRuid]); $bcRData['renewal_count']=(int)$rc->fetchColumn();
    }

    if ($bcRsub === 'passbook') {
        $d = _bcPage($pdo2,
            "SELECT pb.bluecard_id as id, pb.amount, pb.prev_balance as previous_balance, pb.curr_balance as current_balance, pb.entry_type as type, pb.trx_no, pb.description, pb.created_at FROM lte_passbooks pb WHERE pb.bluecard_user_id=?",
            [$bcRuid], $bcRpage);
        $bcRData['passbook'] = $d;
    } elseif ($bcRsub === 'loadmoney') {
        $d = _bcPage($pdo2,
            "SELECT lm.bluecard_id as id, lm.amount, lm.approve_amount, lm.commission, lm.status, lm.lm_type as type, lm.created_at, ma.name as master_name FROM lte_load_money lm LEFT JOIN lte_agents ma ON ma.bluecard_id=lm.master_user_id WHERE lm.bluecard_user_id=?",
            [$bcRuid], $bcRpage);
        $bcRData['loadmoney'] = $d;
    } elseif ($bcRsub === 'planssold') {
        // Plans sold = renewals linked to this agent's subscribers
        if ($bcConfigured) {
            $r2 = BlueCardDb::fetch($config, 'bc_retailer_plans_sold', ['uid'=>$bcRuid,'page'=>$bcRpage]);
            $bcRData['planssold'] = is_array($r2) ? $r2 : null;
        }
    } elseif ($bcRsub === 'customers') {
        $d = _bcPage($pdo2,
            "SELECT s.bluecard_id as id, s.name as firstname, '' as lastname, s.phone as mobile, s.status, s.end_date as plan_end, p.name as plan_name, s.wallet_balance as wallet FROM lte_subscribers s LEFT JOIN lte_packages p ON p.bluecard_id=s.offer_id WHERE s.agent_id=? AND s.deleted_at IS NULL",
            [$bcRuid], $bcRpage);
        $bcRData['customers'] = $d;
    } elseif ($bcRsub === 'commissions') {
        if ($bcConfigured) {
            $r2 = BlueCardDb::fetch($config, 'bc_commissions', ['uid'=>$bcRuid,'page'=>$bcRpage]);
            $bcRData['commissions'] = is_array($r2) ? $r2 : null;
        }
    }
}
elseif ($bcTab === 'simmanage') {
    $bcSimSub = $_GET['bcsub'] ?? 'stock';
    $pdo2 = _bcPdo();
    if ($bcSimSub === 'stock') {
        $bcStats['sim_stock'] = ['count' => (int)$pdo2->query("SELECT COUNT(*) FROM lte_sims WHERE deleted_at IS NULL AND status IN ('stock','In stock')")->fetchColumn()];
    } elseif ($bcSimSub === 'assign') {
        $bcStats['sims_available'] = $pdo2->query("SELECT id, imsi, msisdn FROM lte_sims WHERE deleted_at IS NULL AND status IN ('stock','In stock') ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($bcSimSub === 'returns') {
        $d = _bcPage($pdo2, "SELECT bluecard_sim_id as id, imsi, msisdn, status, created_at FROM lte_sims WHERE deleted_at IS NULL AND status NOT IN ('In stock','Internal usage') ORDER BY id DESC", [], $bcPage);
        $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages'];
    } elseif ($bcSimSub === 'history') {
        $bcQ2 = trim($_GET['bcq'] ?? '');
        $where = 'deleted_at IS NULL'; $params2 = [];
        if ($bcSt!=='') { $where .= ' AND status=?'; $params2[]=$bcSt; }
        if ($bcQ2!=='') { $where .= ' AND (imsi LIKE ? OR msisdn LIKE ?)'; $lk="%{$bcQ2}%"; $params2[]=$lk; $params2[]=$lk; }
        $d = _bcPage($pdo2, "SELECT bluecard_sim_id as id, imsi, msisdn, status, created_at FROM lte_sims WHERE {$where} ORDER BY id DESC", $params2, $bcPage);
        $bcRows=$d['rows']; $bcTotal=$d['total']; $bcPages=$d['pages'];
    }
}

} catch (Throwable $bcErr) { $bcFetchError = $bcErr->getMessage(); }

//  Pager 
function bcPager(int $cur, int $total, int $pages, string $tab, string $q, string $st): string {
    if ($pages <= 1 && $total === 0) return '';
    if ($pages <= 1) return '<div class="bc-pager"><span class="bc-pager-info">' . number_format($total) . ' records</span></div>';
    $base = '?page=dashboard&tab=lte_bluecard&bc=' . urlencode($tab)
          . ($q  ? '&bcq='  . urlencode($q)  : '')
          . ($st ? '&bcst=' . urlencode($st) : '');
    $out = '<div class="bc-pager">';
    if ($cur > 1) $out .= '<a href="' . $base . '&bcpg=' . ($cur - 1) . '"></a>';
    $s = max(1, $cur - 2); $e = min($pages, $cur + 2);
    if ($s > 1) { $out .= '<a href="' . $base . '&bcpg=1">1</a>'; if ($s > 2) $out .= '<span></span>'; }
    for ($i = $s; $i <= $e; $i++) {
        $on = $i === $cur ? ' on' : '';
        $out .= '<a href="' . $base . '&bcpg=' . $i . '" class="' . $on . '">' . $i . '</a>';
    }
    if ($e < $pages) { if ($e < $pages - 1) $out .= '<span></span>'; $out .= '<a href="' . $base . '&bcpg=' . $pages . '">' . $pages . '</a>'; }
    if ($cur < $pages) $out .= '<a href="' . $base . '&bcpg=' . ($cur + 1) . '"></a>';
    $out .= '<span class="bc-pager-info">' . number_format($total) . ' records  page ' . $cur . '/' . $pages . '</span></div>';
    return $out;
}
?>

<style>
.bc-wrap{font-family:inherit;color:var(--text);}
/* Header */
.bc-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.bc-hd-title{font-size:18px;font-weight:800;display:flex;align-items:center;gap:10px;}
/* Sub-nav */
.bc-nav{display:flex;border-bottom:2px solid var(--border);margin-bottom:18px;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.bc-nav::-webkit-scrollbar{height:0;}
.bc-nav-a{flex-shrink:0;padding:10px 15px;font-size:12px;font-weight:600;color:var(--text-3);
  border:none;background:transparent;border-bottom:2.5px solid transparent;margin-bottom:-2px;
  display:inline-flex;align-items:center;gap:5px;white-space:nowrap;text-decoration:none;}
.bc-nav-a:hover{color:var(--text-2);text-decoration:none;}
.bc-nav-a.on{color:#1D4ED8;border-bottom-color:#1D4ED8;font-weight:700;}
.bc-nb{background:#E2E8F0;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:700;color:#64748B;}
.bc-nav-a.on .bc-nb{background:rgba(29,78,216,.15);color:#1D4ED8;}
/* Stats */
.bc-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;margin-bottom:18px;}
.bc-stat{background:#fff;border:1px solid var(--border);border-radius:14px;padding:15px 16px;display:flex;align-items:center;gap:12px;}
.bc-stat-ic{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.bc-stat-val{font-size:22px;font-weight:800;line-height:1;}
.bc-stat-lbl{font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-top:2px;}
.bc-stat-sub{font-size:10px;margin-top:1px;}
/* Card */
.bc-card{background:#fff;border:1px solid var(--border);border-radius:13px;overflow:hidden;margin-bottom:16px;}
.bc-card-hd{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.bc-card-hd-t{font-size:13px;font-weight:700;display:flex;align-items:center;gap:7px;}
.bc-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.bc-badge.blue{background:#EFF6FF;color:#1D4ED8;} .bc-badge.green{background:#DCFCE7;color:#16A34A;}
.bc-badge.orange{background:#FEF3C7;color:#D97706;} .bc-badge.red{background:#FEE2E2;color:#DC2626;}
.bc-badge.gray{background:#F1F5F9;color:#64748B;}
/* Table */
.bc-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.bc-tbl th{padding:9px 14px;font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;
  letter-spacing:.5px;background:#F8FAFC;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
.bc-tbl td{padding:10px 14px;border-bottom:1px solid #F8FAFC;vertical-align:middle;}
.bc-tbl tr:last-child td{border-bottom:none;}
.bc-tbl tr:hover td{background:#FAFBFF;}
.mono{font-family:monospace;font-size:11px;color:var(--text-2);}
/* Pills */
.bc-pill{display:inline-flex;align-items:center;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
.bc-pill.active-plan{background:#DCFCE7;color:#15803D;}
.bc-pill.expired,.bc-pill.inactive,.bc-pill.no-plan{background:#FEE2E2;color:#DC2626;}
.bc-pill.expiring{background:#FEF3C7;color:#D97706;}
.bc-pill.active{background:#DCFCE7;color:#15803D;}
.bc-pill.pending{background:#FEF3C7;color:#D97706;}
.bc-pill.approve,.bc-pill.recharged,.bc-pill.enabled,.bc-pill.paid{background:#DCFCE7;color:#15803D;}
.bc-pill.rejected,.bc-pill.cancelled,.bc-pill.disabled,.bc-pill.unpaid{background:#FEE2E2;color:#DC2626;}
.bc-pill.sold{background:#EFF6FF;color:#1D4ED8;}
.bc-pill.in-stock{background:#DCFCE7;color:#15803D;}
.bc-pill.assigned{background:#EDE9FE;color:#6D28D9;}
.bc-pill.returned{background:#F1F5F9;color:#64748B;}
.bc-pill.deactivated{background:#FEE2E2;color:#DC2626;}
/* Toolbar */
.bc-toolbar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
.bc-toolbar input{border:1.5px solid var(--border);border-radius:9px;padding:8px 12px;
  font-size:13px;font-family:inherit;outline:none;background:#fff;flex:1;min-width:180px;}
.bc-toolbar input:focus{border-color:#1D4ED8;box-shadow:0 0 0 3px rgba(29,78,216,.08);}
.bc-toolbar select{border:1.5px solid var(--border);border-radius:9px;padding:8px 12px;
  font-size:13px;font-family:inherit;outline:none;background:#fff;cursor:pointer;}
/* Buttons */
.bc-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 15px;border-radius:8px;
  font-size:12px;font-weight:700;border:none;cursor:pointer;transition:.15s;text-decoration:none;}
.bc-btn.primary{background:#1D4ED8;color:#fff;} .bc-btn.primary:hover{background:#1E40AF;}
.bc-btn.ghost{background:#fff;border:1.5px solid var(--border);color:var(--text-2);}
.bc-btn.ghost:hover{border-color:#1D4ED8;color:#1D4ED8;}
/* Conn */
.bc-conn{display:inline-flex;align-items:center;gap:7px;padding:6px 13px;border-radius:9px;font-size:12px;font-weight:700;}
.bc-conn.ok{background:#DCFCE7;color:#15803D;} .bc-conn.off{background:#FEE2E2;color:#DC2626;} .bc-conn.warn{background:#FEF3C7;color:#D97706;}
.bc-conn-dot{width:7px;height:7px;border-radius:50%;background:currentColor;}
/* Not configured */
.bc-no-cfg{background:#FFF7ED;border:1.5px solid #FED7AA;border-radius:14px;padding:36px 24px;text-align:center;margin-bottom:18px;}
.bc-no-cfg h3{font-size:16px;font-weight:800;color:#92400E;margin:0 0 6px;}
.bc-no-cfg p{font-size:13px;color:#78350F;margin:0 0 16px;}
.bc-empty{padding:36px;text-align:center;color:var(--text-3);font-size:13px;}
/* Overview grid */
.bc-ov-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media(max-width:900px){.bc-ov-grid{grid-template-columns:1fr;}}
/* Plans grid */
.bc-plans-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;padding:14px;}
.bc-plan{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 15px;display:flex;align-items:flex-start;gap:11px;}
.bc-plan-ic{width:38px;height:38px;border-radius:10px;background:#EFF6FF;color:#1D4ED8;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
/* Settings */
.bc-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:580px){.bc-form-grid{grid-template-columns:1fr;}}
.bc-field{margin-bottom:10px;}
.bc-field label{display:block;font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.bc-field input{width:100%;border:1.5px solid var(--border);border-radius:9px;padding:9px 12px;
  font-size:13px;font-family:inherit;outline:none;background:#FAFAFA;box-sizing:border-box;}
.bc-field input:focus{border-color:#1D4ED8;background:#fff;box-shadow:0 0 0 3px rgba(29,78,216,.08);}
.bc-pager{display:flex;align-items:center;gap:5px;padding:12px 16px;border-top:1px solid var(--border);flex-wrap:wrap;}
.bc-pager a,.bc-pager span{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;
  border-radius:7px;font-size:12px;font-weight:700;text-decoration:none;color:var(--text-2);
  border:1.5px solid var(--border);background:#fff;padding:0 5px;}
.bc-pager a:hover{border-color:#1D4ED8;color:#1D4ED8;}
.bc-pager a.on{background:#1D4ED8;color:#fff;border-color:#1D4ED8;}
.bc-pager .bc-pager-info{font-size:11px;color:var(--text-3);border:none;background:none;font-weight:400;}
</style>

<div class="bc-wrap">

<!-- Header  -->
<div class="bc-hd">
  <div class="bc-hd-title">
     BlueCard Portal
    <span class="bc-badge blue">DishNet 4G</span>
  </div>
  <?php
  $bcConnClass = $bcConfigured ? 'ok' : 'off';
  $bcConnLabel = $bcConfigured ? 'Bridge Connected' : 'Bridge Unreachable';
  ?>
  <span class="bc-conn <?= $bcConnClass ?>">
    <span class="bc-conn-dot"></span>
    <?= $bcConnLabel ?>
    <?php if (!$bcConfigured): ?>
    <a href="?page=dashboard&tab=lte_bluecard&bc=settings" style="margin-left:8px;font-size:11px;color:inherit;opacity:.8;">Check </a>
    <?php endif; ?>
  </span>
</div>

<!-- Sub-nav  -->
<div class="bc-nav">
<?php
$bcNavItems = [
    ['id'=>'overview',  'icon'=>'', 'label'=>'Overview'],
    ['id'=>'customers', 'icon'=>'', 'label'=>'Customers'],
    ['id'=>'simcards',  'icon'=>'', 'label'=>'SIM Cards'],
    ['id'=>'loadmoney', 'icon'=>'', 'label'=>'Load Money'],
    ['id'=>'recharge',  'icon'=>'', 'label'=>'Recharges'],
    ['id'=>'services',  'icon'=>'', 'label'=>'Services'],
    ['id'=>'plans',     'icon'=>'', 'label'=>'Plans'],
    ['id'=>'agents',     'icon'=>'', 'label'=>'Agents'],
    ['id'=>'agentmerge',     'icon'=>'', 'label'=>'Agent Merge',     'admin'=>true],
    ['id'=>'retailerledger', 'icon'=>'', 'label'=>'Retailer Ledger', 'admin'=>true],
    ['id'=>'passbook',   'icon'=>'', 'label'=>'Passbook'],
    ['id'=>'commissions','icon'=>'', 'label'=>'Commissions'],
    ['id'=>'lmconfig',   'icon'=>'', 'label'=>'LM Config', 'admin'=>true],
    ['id'=>'kyc',        'icon'=>'', 'label'=>'New KYC'],
    ['id'=>'simmanage',  'icon'=>'', 'label'=>'SIM Mgmt', 'admin'=>true],
    ['id'=>'kycrecords', 'icon'=>'', 'label'=>'KYC Records', 'admin'=>true],
    ['id'=>'settings',   'icon'=>'', 'label'=>'DB Settings', 'admin'=>true],
];
foreach ($bcNavItems as $ni):
    if (!empty($ni['admin']) && !$isAdmin) continue;
    $on  = $bcTab === $ni['id'] ? 'on' : '';
    $url = '?page=dashboard&tab=lte_bluecard&bc=' . $ni['id'];
    $nb  = '';
    if ($ni['id'] === 'loadmoney' && !empty($bcStats['load_pending']))
        $nb = '<span class="bc-nb">' . (int)$bcStats['load_pending'] . '</span>';
    if ($ni['id'] === 'recharge' && !empty($bcStats['recharge_pending']))
        $nb = '<span class="bc-nb">' . (int)$bcStats['recharge_pending'] . '</span>';
?>
  <a href="<?= $url ?>" class="bc-nav-a <?= $on ?>"><?= $ni['icon'] ?> <?= $ni['label'] ?><?= $nb ?></a>
<?php endforeach; ?>
</div>


<?php if ($bcFetchError): ?><div class="kyc-alert error"> DB error: <?= bch($bcFetchError) ?></div><?php endif; ?>

<?php if (!$bcConfigured && $bcTab !== 'settings'): ?>
<div class="bc-no-cfg">
  <div style="font-size:40px;margin-bottom:12px;"></div>
  <h3>BlueCard Bridge Not Reachable</h3>
  <p>Apply the <strong>bc_query patch</strong> to <code>lte_feed.php</code> on WHM, then click Test Connection in DB Settings.</p>
  <?php if ($isAdmin): ?>
  <a href="?page=dashboard&tab=lte_bluecard&bc=settings" class="bc-btn primary"> DB Settings</a>
  <?php endif; ?>
</div>
<?php endif; ?>

<!--  OVERVIEW  -->
<?php if ($bcTab === 'overview' && $bcConfigured): ?>
<div class="bc-stats">
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:#EFF6FF;color:#1D4ED8;"></div>
    <div>
      <div class="bc-stat-val"><?= number_format($bcStats['total_cust'] ?? 0) ?></div>
      <div class="bc-stat-lbl">Total Customers</div>
      <div class="bc-stat-sub" style="color:#16A34A;"><?= number_format($bcStats['active_cust'] ?? 0) ?> active</div>
    </div>
  </div>
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:#FEF3C7;color:#D97706;"></div>
    <div>
      <div class="bc-stat-val" style="color:#D97706;"><?= number_format($bcStats['expiring_7d'] ?? 0) ?></div>
      <div class="bc-stat-lbl">Expiring 7 Days</div>
      <div class="bc-stat-sub" style="color:#DC2626;"><?= number_format(($bcStats['total_cust'] ?? 0) - ($bcStats['active_cust'] ?? 0)) ?> expired</div>
    </div>
  </div>
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:#DCFCE7;color:#16A34A;"></div>
    <div>
      <div class="bc-stat-val"><?= number_format($bcStats['sim_instock'] ?? 0) ?></div>
      <div class="bc-stat-lbl">SIMs In Stock</div>
      <div class="bc-stat-sub" style="color:#1D4ED8;"><?= number_format($bcStats['sim_deployed'] ?? 0) ?> deployed</div>
    </div>
  </div>
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:#FEE2E2;color:#DC2626;"></div>
    <div>
      <div class="bc-stat-val" style="color:#DC2626;"><?= number_format($bcStats['load_pending'] ?? 0) ?></div>
      <div class="bc-stat-lbl">Pending Load Money</div>
      <div class="bc-stat-sub"><?= bcAmtUsd($bcStats['load_pending_amt'] ?? 0) ?> total</div>
    </div>
  </div>
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:#FEF3C7;color:#D97706;"></div>
    <div>
      <div class="bc-stat-val" style="color:#D97706;"><?= number_format($bcStats['recharge_pending'] ?? 0) ?></div>
      <div class="bc-stat-lbl">Pending Recharges</div>
    </div>
  </div>
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:#EDE9FE;color:#6D28D9;"></div>
    <div>
      <div class="bc-stat-val"><?= number_format($bcStats['plans_active'] ?? 0) ?></div>
      <div class="bc-stat-lbl">Active Plans</div>
    </div>
  </div>
</div>

<div class="bc-ov-grid">
  <div class="bc-card">
    <div class="bc-card-hd">
      <div class="bc-card-hd-t"> Recent Load Money</div>
      <a href="?page=dashboard&tab=lte_bluecard&bc=loadmoney" style="font-size:11px;color:#1D4ED8;">View all </a>
    </div>
    <?php if (empty($bcStats['recent_loads'])): ?>
    <div class="bc-empty">No records</div>
    <?php else: ?>
    <table class="bc-tbl">
      <thead><tr><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($bcStats['recent_loads'] as $r): ?>
      <tr>
        <td>
          <div style="font-weight:600;"><?= bch(trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''))) ?: '' ?></div>
          <div class="mono"><?= bch($r['mobile'] ?? '') ?></div>
        </td>
        <td style="font-weight:700;"><?= bcAmtUsd($r['amount'] ?? 0) ?></td>
        <td><span class="bc-pill <?= strtolower(bch($r['status'] ?? '')) ?>"><?= bch($r['status'] ?? '') ?></span></td>
        <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at'] ?? null) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="bc-card">
    <div class="bc-card-hd">
      <div class="bc-card-hd-t"> Recent Recharges</div>
      <a href="?page=dashboard&tab=lte_bluecard&bc=recharge" style="font-size:11px;color:#1D4ED8;">View all </a>
    </div>
    <?php if (empty($bcStats['recent_recharges'])): ?>
    <div class="bc-empty">No records</div>
    <?php else: ?>
    <table class="bc-tbl">
      <thead><tr><th>Mobile</th><th>Plan</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($bcStats['recent_recharges'] as $r): ?>
      <tr>
        <td>
          <div class="mono" style="font-weight:600;"><?= bch($r['mobile'] ?? '') ?></div>
          <div style="font-size:11px;color:var(--text-3);"><?= bch(trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? ''))) ?></div>
        </td>
        <td>
          <div style="font-size:12px;font-weight:600;"><?= bch($r['plan_name'] ?? '') ?></div>
          <?php if (!empty($r['days'])): ?><div style="font-size:11px;color:var(--text-3);"><?= (int)$r['days'] ?> days</div><?php endif; ?>
        </td>
        <td><span class="bc-pill <?= strtolower(bch($r['status'] ?? '')) ?>"><?= bch($r['status'] ?? '') ?></span></td>
        <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at'] ?? null) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!--  CUSTOMERS  -->
<?php if ($bcTab === 'customers' && $bcConfigured): ?>
<form method="GET">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="lte_bluecard">
  <input type="hidden" name="bc" value="customers">
  <div class="bc-toolbar">
    <input type="text" name="bcq" value="<?= bch($bcQ) ?>" placeholder="Name, mobile, email">
    <select name="bcst" onchange="this.form.submit()">
      <option value="">All</option>
      <option value="active"   <?= $bcSt==='active'?'selected':''   ?>>Active</option>
      <option value="inactive" <?= $bcSt==='inactive'?'selected':'' ?>>Inactive / Expired</option>
    </select>
    <button type="submit" class="bc-btn primary"> Search</button>
    <?php if ($bcQ || $bcSt): ?><a href="?page=dashboard&tab=lte_bluecard&bc=customers" class="bc-btn ghost"> Clear</a><?php endif; ?>
  </div>
</form>
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> Customers <span class="bc-badge blue"><?= number_format($bcTotal) ?></span></div>
  </div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No customers found.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Name</th><th>Mobile</th><th>Plan</th><th>Expiry</th><th>Wallet</th><th>SIMs</th><th>Status</th><th>Joined</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r):
        $planEnd   = $r['plan_end'] ?? null;
        $isActive  = $r['is_active'] && !$r['is_deactive'] && $planEnd && strtotime($planEnd) >= time();
        $isDeact   = (bool)$r['is_deactive'];
    ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td>
        <a href="?page=dashboard&tab=lte_bluecard&bc=customerdetail&bcuid=<?= (int)$r['id'] ?>" style="font-weight:600;color:#1D4ED8;text-decoration:none;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></a>
        <div style="font-size:11px;color:var(--text-3);"><?= bch($r['email']??'') ?></div>
      </td>
      <td class="mono"><?= bch($r['mobile'] ?? '') ?></td>
      <td style="font-size:12px;"><?= bch($r['plan_name'] ?? '') ?></td>
      <td><?= bcExpiry($planEnd) ?></td>
      <td style="font-weight:600;"><?= bcAmtUsd($r['wallet'] ?? 0) ?></td>
      <td style="text-align:center;"><?= (int)($r['sim_count'] ?? 0) ?></td>
      <td>
        <?php if ($isDeact): ?><span class="bc-pill deactivated">Deactivated</span>
        <?php elseif ($isActive): ?><span class="bc-pill active">Active</span>
        <?php else: ?><span class="bc-pill expired">Expired</span>
        <?php endif; ?>
      </td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at'] ?? null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'customers', $bcQ, $bcSt) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!--  SIM CARDS  -->
<?php if ($bcTab === 'simcards' && $bcConfigured): ?>
<form method="GET">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="lte_bluecard">
  <input type="hidden" name="bc" value="simcards">
  <div class="bc-toolbar">
    <input type="text" name="bcq" value="<?= bch($bcQ) ?>" placeholder="MSISDN, IMSI, customer">
    <select name="bcst" onchange="this.form.submit()">
      <option value="">All Status</option>
      <option value="In stock"       <?= $bcSt==='In stock'?'selected':''       ?>>In Stock</option>
      <option value="Sold"           <?= $bcSt==='Sold'?'selected':''           ?>>Sold</option>
      <option value="Assigned"       <?= $bcSt==='Assigned'?'selected':''       ?>>Assigned</option>
      <option value="Returned"       <?= $bcSt==='Returned'?'selected':''       ?>>Returned</option>
      <option value="Internal usage" <?= $bcSt==='Internal usage'?'selected':'' ?>>Internal Usage</option>
    </select>
    <button type="submit" class="bc-btn primary"> Search</button>
    <?php if ($bcQ || $bcSt): ?><a href="?page=dashboard&tab=lte_bluecard&bc=simcards" class="bc-btn ghost"> Clear</a><?php endif; ?>
  </div>
</form>
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> SIM Cards <span class="bc-badge blue"><?= number_format($bcTotal) ?></span></div>
    <?php if ($isAdmin): ?><button onclick="bcSimAdd()" class="bc-btn primary" style="font-size:12px;padding:6px 14px;">+ Add SIM</button><?php endif; ?>
  </div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No SIM cards found.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>MSISDN</th><th>IMSI</th><th>Type</th><th>Status</th><th>Assigned To</th><th>Price</th><th>Added</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r):
        $stCss = strtolower(str_replace([' ','_'],'-', $r['status'] ?? ''));
    ?>
    <tr>
      <td class="mono"><?= (int)$r['id'] ?></td>
      <td class="mono" style="font-weight:600;"><?= bch($r['msisdn'] ?? '') ?></td>
      <td class="mono"><?= bch($r['imsi'] ?? '') ?></td>
      <td style="font-size:11px;"><?= bch($r['sim_type'] ?? '') ?></td>
      <td><span class="bc-pill <?= $stCss ?>"><?= bch($r['status'] ?? '') ?></span></td>
      <td>
        <?php if (!empty($r['firstname']) || !empty($r['lastname'])): ?>
          <div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></div>
          <div class="mono" style="font-size:11px;"><?= bch($r['mobile'] ?? '') ?></div>
        <?php else: ?><span style="color:var(--text-3);"></span><?php endif; ?>
      </td>
      <td><?= !empty($r['price']) ? bcAmtUsd($r['price']) : '' ?></td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at'] ?? null) ?></td>
      <?php if ($isAdmin): ?>
      <td style="white-space:nowrap;">
        <button onclick="bcSimEdit(<?= (int)$r['id'] ?>)" class="bc-btn ghost" style="font-size:11px;padding:4px 10px;" title="Edit"></button>
        <button onclick="bcSimDel(<?= (int)$r['id'] ?>,<?= bch(json_encode($r['msisdn']??'')) ?>)" class="bc-btn danger" style="font-size:11px;padding:4px 10px;" title="Delete"></button>
      </td>
      <?php else: ?><td></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'simcards', $bcQ, $bcSt) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!--  LOAD MONEY  -->
<?php if ($bcTab === 'loadmoney' && $bcConfigured): ?>
<?php $bcAgentId = (int)($_GET['bcaid'] ?? 0); ?>
<form method="GET" id="bcLmFilter">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="lte_bluecard">
  <input type="hidden" name="bc" value="loadmoney">
  <input type="hidden" name="bcaid" id="bcLmFilterAid" value="<?= $bcAgentId ?>">
  <div class="bc-toolbar">
    <div style="position:relative;flex:1;min-width:200px;">
      <input type="text" id="bcLmFilterAgent" value="<?= bch($bcAgentId ? '#'.$bcAgentId.' Agent' : '') ?>" placeholder="Filter by agent name / mobile" autocomplete="off"
        style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;"
        oninput="bcLmFilterSearch(this.value)">
      <?php if ($bcAgentId): ?><button type="button" onclick="bcLmFilterClear()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:16px;"></button><?php endif; ?>
      <div id="bcLmFilterDrop" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:100;border:1.5px solid var(--border);border-radius:10px;margin-top:4px;max-height:200px;overflow-y:auto;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.1);"></div>
    </div>
    <select name="bcst" onchange="this.form.submit()">
      <option value="">All Status</option>
      <option value="Pending"  <?= $bcSt==='Pending'?'selected':''  ?>>Pending</option>
      <option value="Approve"  <?= $bcSt==='Approve'?'selected':''  ?>>Approved</option>
      <option value="Rejected" <?= $bcSt==='Rejected'?'selected':'' ?>>Rejected</option>
    </select>
    <button type="submit" class="bc-btn primary"> Search</button>
    <?php if ($bcAgentId || $bcSt): ?><a href="?page=dashboard&tab=lte_bluecard&bc=loadmoney" class="bc-btn ghost"> Clear</a><?php endif; ?>
  </div>
</form>
<?php if ($bcAgentId): ?>
<div style="background:#EFF6FF;border-radius:10px;padding:8px 14px;margin-bottom:10px;font-size:12px;color:#1D4ED8;">
   Filtered by Agent ID #<?= $bcAgentId ?>  <a href="?page=dashboard&tab=lte_bluecard&bc=loadmoney" style="color:#1D4ED8;">Clear filter</a>
</div>
<?php endif; ?>
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> Load Money <span class="bc-badge blue"><?= number_format($bcTotal) ?></span></div>
    <?php if ($isAdmin): ?><button onclick="bcLmAdd()" class="bc-btn primary" style="font-size:12px;padding:6px 14px;">+ Add</button><?php endif; ?>
  </div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No records found.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Agent</th><th>Master</th><th>Requested</th><th>Approved</th><th>Comm</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r): ?>
    <tr>
      <td class="mono"><?= (int)$r['id'] ?></td>
      <td>
        <div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></div>
        <div class="mono" style="font-size:11px;"><?= bch($r['mobile'] ?? '') ?></div>
      </td>
      <td style="font-weight:700;"><?= bcAmtUsd($r['amount'] ?? 0) ?></td>
      <td><?= $r['approve_amount'] !== null ? bcAmtUsd($r['approve_amount']) : '' ?></td>
      <td><?= $r['commission'] !== null ? number_format((float)$r['commission'], 1) . '%' : '' ?></td>
      <td><span class="bc-pill <?= strtolower(bch($r['status'] ?? '')) ?>"><?= bch($r['status'] ?? '') ?></span></td>
      <td>
        <span class="bc-pill <?= $r['payment'] ? 'paid approve' : 'unpaid pending' ?>">
          <?= $r['payment'] ? 'Paid' : 'Unpaid' ?>
        </span>
      </td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDatetime($r['created_at'] ?? null) ?></td>
      <td>
        <?php if (($r['status'] ?? '') === 'Pending' || $isAdmin): ?>
        <button onclick="bcLmStatus(<?= (int)$r['id'] ?>,'<?= bch($r['status']??'') ?>',<?= (int)($r['amount']??0) ?>)" class="bc-btn ghost" style="font-size:11px;padding:4px 10px;" title="Change Status"></button>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <button onclick="bcLmDel(<?= (int)$r['id'] ?>)" class="bc-btn danger" style="font-size:11px;padding:4px 10px;" title="Delete"></button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'loadmoney', $bcQ, $bcSt) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!--  RECHARGES  -->
<?php if ($bcTab === 'recharge' && $bcConfigured): ?>
<form method="GET">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="lte_bluecard">
  <input type="hidden" name="bc" value="recharge">
  <div class="bc-toolbar">
    <input type="text" name="bcq" value="<?= bch($bcQ) ?>" placeholder="Mobile or customer name">
    <select name="bcst" onchange="this.form.submit()">
      <option value="">All Status</option>
      <option value="Pending"   <?= $bcSt==='Pending'?'selected':''   ?>>Pending</option>
      <option value="Recharged" <?= $bcSt==='Recharged'?'selected':'' ?>>Recharged</option>
      <option value="Cancelled" <?= $bcSt==='Cancelled'?'selected':'' ?>>Cancelled</option>
    </select>
    <button type="submit" class="bc-btn primary"> Search</button>
    <?php if ($bcQ || $bcSt): ?><a href="?page=dashboard&tab=lte_bluecard&bc=recharge" class="bc-btn ghost"> Clear</a><?php endif; ?>
  </div>
</form>
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> Recharge Requests <span class="bc-badge blue"><?= number_format($bcTotal) ?></span></div>
  </div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No recharge requests found.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Customer</th><th>Mobile</th><th>Plan</th><th>Days</th><th>Price</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r): ?>
    <tr>
      <td class="mono"><?= (int)$r['id'] ?></td>
      <td style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></td>
      <td class="mono"><?= bch($r['mobile'] ?? '') ?></td>
      <td style="font-weight:600;font-size:12px;"><?= bch($r['plan_name'] ?? '') ?></td>
      <td><?= !empty($r['days']) ? (int)$r['days'] . ' days' : '' ?></td>
      <td><?= isset($r['plan_price']) && $r['plan_price'] !== null ? bcAmtUsd($r['plan_price']) : '' ?></td>
      <td><span class="bc-pill <?= strtolower(bch($r['status'] ?? '')) ?>"><?= bch($r['status'] ?? '') ?></span></td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDatetime($r['created_at'] ?? null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'recharge', $bcQ, $bcSt) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!--  SERVICES  -->
<?php if ($bcTab === 'services' && $bcConfigured): ?>
<form method="GET">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="lte_bluecard">
  <input type="hidden" name="bc" value="services">
  <div class="bc-toolbar">
    <input type="text" name="bcq" value="<?= bch($bcQ) ?>" placeholder="IMSI, identity, customer">
    <select name="bcst" onchange="this.form.submit()">
      <option value="">All</option>
      <option value="enabled"  <?= $bcSt==='enabled'?'selected':''  ?>>Enabled</option>
      <option value="disabled" <?= $bcSt==='disabled'?'selected':'' ?>>Disabled</option>
    </select>
    <button type="submit" class="bc-btn primary"> Search</button>
    <?php if ($bcQ || $bcSt): ?><a href="?page=dashboard&tab=lte_bluecard&bc=services" class="bc-btn ghost"> Clear</a><?php endif; ?>
  </div>
</form>
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> Services <span class="bc-badge blue"><?= number_format($bcTotal) ?></span></div>
  </div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No services found.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Customer</th><th>Service ID</th><th>IMSI</th><th>Identity</th><th>Status</th><th>Type</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r): ?>
    <tr>
      <td class="mono"><?= (int)$r['id'] ?></td>
      <td>
        <div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></div>
        <div class="mono" style="font-size:11px;"><?= bch($r['mobile'] ?? '') ?></div>
      </td>
      <td class="mono"><?= bch($r['service_id'] ?? '') ?></td>
      <td class="mono"><?= bch($r['imsi'] ?? '') ?></td>
      <td class="mono" style="font-size:11px;"><?= bch($r['serviceIdentity'] ?? '') ?></td>
      <td>
        <span class="bc-pill <?= $r['isServiceEnabled'] ? 'enabled' : 'disabled' ?>">
          <?= $r['isServiceEnabled'] ? 'Enabled' : 'Disabled' ?>
        </span>
      </td>
      <td style="font-size:11px;"><?= bch($r['type'] ?? '') ?></td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at'] ?? null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'services', $bcQ, $bcSt) ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!--  PLANS  -->
<?php if ($bcTab === 'plans' && $bcConfigured): ?>
<div class="bc-toolbar">
  <a href="?page=dashboard&tab=lte_bluecard&bc=plans" class="bc-btn <?= !$bcSt?'primary':'ghost' ?>">All (<?= number_format($bcTotal) ?>)</a>
  <a href="?page=dashboard&tab=lte_bluecard&bc=plans&bcst=active" class="bc-btn <?= $bcSt==='active'?'primary':'ghost' ?>"> Active</a>
  <a href="?page=dashboard&tab=lte_bluecard&bc=plans&bcst=inactive" class="bc-btn <?= $bcSt==='inactive'?'primary':'ghost' ?>"> Inactive</a>
</div>
<?php if (empty($bcRows)): ?>
<div class="bc-card"><div class="bc-empty">No plans found.</div></div>
<?php else: ?>
<div class="bc-plans-grid">
  <?php foreach ($bcRows as $r):
    $gb  = $r['Bytes'] ? round($r['Bytes'] / (1024*1024*1024), 1) . ' GB' : null;
    $amt = $r['amount'] !== null ? bcAmtUsd($r['amount']) : '';
  ?>
  <div class="bc-plan" style="<?= !$r['is_active']?'opacity:.55;':'' ?>">
    <div class="bc-plan-ic"><?= $r['is_popular']?'':'' ?></div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:700;margin-bottom:4px;"><?= bch($r['name'] ?? '') ?></div>
      <?php if (!empty($r['description'])): ?>
      <div style="font-size:11px;color:var(--text-3);margin-bottom:7px;"><?= bch(mb_substr($r['description'],0,80)) ?><?= mb_strlen($r['description'])>80?'':'' ?></div>
      <?php endif; ?>
      <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
        <span style="font-size:16px;font-weight:800;color:#1D4ED8;"><?= $amt ?></span>
        <span style="font-size:11px;color:var(--text-3);"><?= (int)($r['days']??30) ?> days</span>
        <?php if ($gb): ?><span class="bc-badge blue"><?= $gb ?></span><?php endif; ?>
        <?php if (!empty($r['is_popular'])): ?><span class="bc-badge green">Popular</span><?php endif; ?>
        <?php if (!$r['is_active']): ?><span class="bc-badge red">Inactive</span><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?= bcPager($bcPage, $bcTotal, $bcPages, 'plans', '', $bcSt) ?>
<?php endif; ?>
<?php endif; ?>

<!--  AGENTS  -->
<?php if ($bcTab === 'agents' && $bcConfigured): ?>
<form method="GET" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="lte_bluecard"><input type="hidden" name="bc" value="agents">
  <input type="text" name="bcq" value="<?= bch($bcQ) ?>" placeholder="Search name / mobile / email" style="flex:1;min-width:200px;border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
  <select name="bcst" style="border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
    <option value="">All Roles</option>
    <option value="admin" <?= $bcSt==='admin'?'selected':'' ?>>Admin</option>
    <option value="dealer" <?= $bcSt==='dealer'?'selected':'' ?>>Dealer</option>
    <option value="retailer" <?= $bcSt==='retailer'?'selected':'' ?>>Retailer</option>
    <option value="franchisee" <?= $bcSt==='franchisee'?'selected':'' ?>>Franchisee</option>
  </select>
  <button type="submit" class="bc-btn primary"> Search</button>
</form>
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> Agents &amp; Dealers <span class="bc-badge gray"><?= number_format($bcTotal) ?></span></div>
    <div style="display:flex;gap:8px;align-items:center;">
      <button onclick="bcSyncAllAgents(this)" class="bc-btn primary" style="font-size:12px;padding:6px 14px;"> Sync All to Plugin Login</button>
      <span id="bcSyncAllMsg" style="font-size:12px;"></span>
    </div>
  </div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No records</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Name</th><th>Mobile</th><th>Role</th><th>Master</th><th>Wallet</th><th>LM Comm%</th><th>Joined</th><th>Plugin Login</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td>
        <div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></div>
        <div style="font-size:11px;color:var(--text-3);"><?= bch($r['email']??'') ?></div>
      </td>
      <td class="mono"><?= bch($r['mobile']??'') ?></td>
      <td><span class="bc-pill <?= in_array($r['role_name']??'',['admin','dealer'],true)?'sold':'assigned' ?>"><?= bch($r['role_display']??$r['role_name']??'') ?></span></td>
      <td style="font-size:12px;"><?= bch($r['master_name']??'') ?></td>
      <td style="font-weight:700;"><?= bcAmtUsd($r['wallet']??0) ?></td>
      <td style="color:var(--text-3);"><?= isset($r['lm_commission']) && $r['lm_commission']!==null ? number_format((float)$r['lm_commission'],1).'%' : '' ?></td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at']??null) ?></td>
      <td>
        <a href="?page=dashboard&tab=lte_bluecard&bc=retailerdetail&bcuid=<?= (int)$r['id'] ?>" class="bc-btn primary" style="font-size:11px;padding:4px 10px;"> Profile</a>
        <button onclick="bcSyncAgent(<?= (int)$r['id'] ?>, this)" class="bc-btn ghost" style="font-size:11px;padding:4px 10px;margin-top:4px;" title="Sync to Plugin Login"> Sync</button>
        <span id="bcSyncMsg_<?= (int)$r['id'] ?>" style="font-size:11px;display:block;margin-top:3px;"></span>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'agents', $bcQ, $bcSt) ?>
</div>
<?php endif; ?>

<!--  PASSBOOK  -->
<?php if ($bcTab === 'passbook' && $bcConfigured): ?>
<form method="GET" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="lte_bluecard"><input type="hidden" name="bc" value="passbook">
  <input type="text" name="bcq" value="<?= bch($bcQ) ?>" placeholder="Search by name or mobile" style="flex:1;min-width:200px;border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
  <select name="bcst" style="border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
    <option value="">All Types</option>
    <option value="Credit" <?= $bcSt==='Credit'?'selected':'' ?>>Credit</option>
    <option value="Debit" <?= $bcSt==='Debit'?'selected':'' ?>>Debit</option>
  </select>
  <button type="submit" class="bc-btn primary"> Search</button>
</form>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Passbook Entries <span class="bc-badge gray"><?= number_format($bcTotal) ?></span></div></div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No records</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>TRX</th><th>User</th><th>Type</th><th>Amount</th><th>Prev Bal</th><th>New Bal</th><th>Description</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r): ?>
    <tr>
      <td class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['trx_no']??'') ?></td>
      <td>
        <div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></div>
        <div class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['mobile']??'') ?></div>
      </td>
      <td><span class="bc-pill <?= ($r['type']??'')==='Credit'?'approve':'rejected' ?>"><?= bch($r['type']??'') ?></span></td>
      <td style="font-weight:700;"><?= bcAmtUsd($r['amount']??0) ?></td>
      <td style="color:var(--text-3);"><?= $r['previous_balance']!==null ? bcAmtUsd($r['previous_balance']) : '' ?></td>
      <td style="color:var(--text-3);"><?= $r['current_balance']!==null ? bcAmtUsd($r['current_balance']) : '' ?></td>
      <td style="font-size:12px;"><?= bch($r['description']??'') ?></td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'passbook', $bcQ, $bcSt) ?>
</div>
<?php endif; ?>

<!--  COMMISSIONS  -->
<?php if ($bcTab === 'commissions' && $bcConfigured): ?>
<form method="GET" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="lte_bluecard"><input type="hidden" name="bc" value="commissions">
  <input type="text" name="bcq" value="<?= bch($bcQ) ?>" placeholder="Search by agent name / mobile" style="flex:1;min-width:200px;border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
  <button type="submit" class="bc-btn primary"> Search</button>
</form>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> User Commissions <span class="bc-badge gray"><?= number_format($bcTotal) ?></span></div></div>
  <?php if (empty($bcRows)): ?><div class="bc-empty">No records</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Agent</th><th>Commission</th><th>Load Money Req #</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($bcRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td>
        <div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></div>
        <div class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['mobile']??'') ?></div>
      </td>
      <td style="font-weight:700;color:#16A34A;"><?= bcAmtUsd($r['amount']??0) ?></td>
      <td class="mono" style="color:var(--text-3);"><?= $r['request_id'] ? '#'.(int)$r['request_id'] : '' ?></td>
      <td style="color:var(--text-3);font-size:11px;"><?= bcDate($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'commissions', $bcQ, '') ?>
</div>
<?php endif; ?>

<!--  LM CONFIG  -->
<?php if ($bcTab === 'lmconfig' && $bcConfigured && $isAdmin): ?>
<?php $lmc = $bcStats['lmconfig'] ?? []; ?>
<div class="bc-card" style="max-width:520px;">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Load Money Commission Config</div></div>
  <div style="padding:18px;">
  <?php if (empty($lmc)): ?>
    <div class="bc-empty">No config found.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>Role</th><th>Commission %</th></tr></thead>
    <tbody>
      <tr><td>Franchisee</td><td style="font-weight:700;"><?= number_format((float)($lmc['franchisee_commission']??0),2) ?>%</td></tr>
      <tr><td>Dealer</td><td style="font-weight:700;"><?= number_format((float)($lmc['dealer_commission']??0),2) ?>%</td></tr>
      <tr><td>Retailer</td><td style="font-weight:700;"><?= number_format((float)($lmc['retailer_commission']??0),2) ?>%</td></tr>
      <tr><td>Customer</td><td style="font-weight:700;"><?= number_format((float)($lmc['customer_commission']??0),2) ?>%</td></tr>
      <tr><td>Other</td><td style="font-weight:700;"><?= number_format((float)($lmc['other_commission']??0),2) ?>%</td></tr>
    </tbody>
  </table>
  <div style="margin-top:12px;font-size:11px;color:var(--text-3);">Last updated: <?= bcDate($lmc['updated_at']??null) ?></div>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!--  NEW KYC  -->
<?php if ($bcTab === 'kyc' && $bcConfigured): ?>
<?php
$kyc_sims  = $bcStats['kyc_sims']  ?? [];
$kyc_plans = $bcStats['kyc_plans'] ?? [];
?>
<div class="bc-card" style="max-width:720px;">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> Register New BlueCARD Customer</div>
  </div>
  <div style="padding:20px;">
    <div id="bcKycMsg"></div>
    <!-- Step indicator -->
    <div style="display:flex;gap:0;margin-bottom:24px;border-radius:12px;overflow:hidden;border:1.5px solid var(--border);">
      <?php foreach(['1 Customer Info','2 SIM & Plan','3 Address'] as $si=>$sl): ?>
      <div class="bc-step-tab" id="bcKycStepTab<?= $si+1 ?>" onclick="bcKycGoStep(<?= $si+1 ?>)"
        style="flex:1;padding:10px;text-align:center;font-size:12px;font-weight:700;cursor:pointer;border-right:1.5px solid var(--border);background:<?= $si===0?'#1D4ED8':'#F8FAFC' ?>;color:<?= $si===0?'#fff':'var(--text-3)' ?>;">
        <?= $sl ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Step 1: Customer Info -->
    <div id="bcKycStep1">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="bc-field"><label>First Name *</label><input type="text" id="kyc_firstname" placeholder="John" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>Last Name</label><input type="text" id="kyc_lastname" placeholder="Doe" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>Email</label><input type="email" id="kyc_email" placeholder="john@example.com" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>Alternate Mobile</label><input type="text" id="kyc_altmobile" placeholder="+211..." style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>WhatsApp</label><input type="text" id="kyc_whatsapp" placeholder="+211..." style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>Gender</label>
          <select id="kyc_gender" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
            <option value="male">Male</option><option value="female">Female</option>
          </select>
        </div>
        <div class="bc-field"><label>Date of Birth</label><input type="date" id="kyc_dob" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>Nationality</label><input type="text" id="kyc_nationality" value="South Sudanese" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      </div>
      <button onclick="bcKycGoStep(2)" class="bc-btn primary" style="margin-top:8px;">Next: SIM & Plan </button>
    </div>

    <!-- Step 2: SIM & Plan -->
    <div id="bcKycStep2" style="display:none;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="bc-field" style="grid-column:1/-1;">
          <label>Select SIM Card (In Stock) *</label>
          <select id="kyc_sim_id" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;" onchange="bcKycSimSelect(this)">
            <option value="">-- Select SIM --</option>
            <?php foreach ($kyc_sims as $s): ?>
            <option value="<?= (int)$s['id'] ?>" data-msisdn="<?= bch($s['msisdn']??'') ?>" data-imsi="<?= bch($s['imsi']??'') ?>">
              MSISDN: <?= bch($s['msisdn']??'') ?> | IMSI: <?= bch($s['imsi']??'') ?> | <?= bch($s['sim_type']??'') ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="bcKycSimInfo" style="display:none;margin-top:6px;background:#F0FDF4;border-radius:8px;padding:8px 12px;font-size:12px;color:#15803D;">
             Selected: <strong id="bcKycSimMsisdn"></strong>
          </div>
        </div>
        <div class="bc-field" style="grid-column:1/-1;">
          <label>Select Plan *</label>
          <select id="kyc_offer_id" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;" onchange="bcKycPlanSelect(this)">
            <option value="">-- Select Plan --</option>
            <?php foreach ($kyc_plans as $p): ?>
            <option value="<?= (int)$p['id'] ?>" data-amt="<?= (int)($p['amount']??0) ?>" data-days="<?= (int)($p['days']??30) ?>" data-bytes="<?= bch($p['Bytes']??'') ?>">
              <?= bch($p['name']??'') ?>  $<?= number_format((float)($p['amount']??0)/100,2) ?> / <?= (int)($p['days']??30) ?> days
            </option>
            <?php endforeach; ?>
          </select>
          <div id="bcKycPlanInfo" style="display:none;margin-top:6px;background:#EFF6FF;border-radius:8px;padding:8px 12px;font-size:12px;color:#1D4ED8;">
             <strong id="bcKycPlanName"></strong>  <span id="bcKycPlanAmt"></span>  <span id="bcKycPlanDays"></span> days  <span id="bcKycPlanBytes"></span>
          </div>
        </div>
        <div class="bc-field">
          <label>Assigned Retailer / Agent</label>
          <input type="text" id="bcKycRetailerSearch" placeholder="Search agent" autocomplete="off"
            style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"
            oninput="bcKycAgentSearch(this.value)">
          <input type="hidden" id="kyc_retailer_id">
          <div id="bcKycAgentDrop" style="display:none;border:1.5px solid var(--border);border-radius:10px;margin-top:4px;max-height:180px;overflow-y:auto;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.1);"></div>
        </div>
        <div class="bc-field">
          <label>Payment Type</label>
          <select id="kyc_payment_type" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
            <option value="Wallet">Wallet</option><option value="Cash">Cash</option>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button onclick="bcKycGoStep(1)" class="bc-btn ghost"> Back</button>
        <button onclick="bcKycGoStep(3)" class="bc-btn primary">Next: Address </button>
      </div>
    </div>

    <!-- Step 3: Address & Submit -->
    <div id="bcKycStep3" style="display:none;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="bc-field"><label>House No.</label><input type="text" id="kyc_house_no" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>Landmark</label><input type="text" id="kyc_landmark" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field" style="grid-column:1/-1;"><label>Address</label><input type="text" id="kyc_address" placeholder="Full address" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label> Customer Photo</label><input type="file" id="kyc_img_cust" accept="image/*,.pdf" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:7px 13px;font-size:12px;font-family:inherit;outline:none;" onchange="kycPreview(this,'kyc_prev_cust')"><img id="kyc_prev_cust" src="" style="display:none;width:64px;height:64px;object-fit:cover;border-radius:8px;margin-top:4px;border:1.5px solid var(--border);"></div>
        <div class="bc-field"><label> ID Front</label><input type="file" id="kyc_img_af" accept="image/*,.pdf" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:7px 13px;font-size:12px;font-family:inherit;outline:none;" onchange="kycPreview(this,'kyc_prev_af')"><img id="kyc_prev_af" src="" style="display:none;width:64px;height:64px;object-fit:cover;border-radius:8px;margin-top:4px;border:1.5px solid var(--border);"></div>
        <div class="bc-field"><label> ID Back</label><input type="file" id="kyc_img_ab" accept="image/*,.pdf" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:7px 13px;font-size:12px;font-family:inherit;outline:none;" onchange="kycPreview(this,'kyc_prev_ab')"><img id="kyc_prev_ab" src="" style="display:none;width:64px;height:64px;object-fit:cover;border-radius:8px;margin-top:4px;border:1.5px solid var(--border);"></div>
        <div class="bc-field"><label> PAN / Other ID</label><input type="file" id="kyc_img_pan" accept="image/*,.pdf" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:7px 13px;font-size:12px;font-family:inherit;outline:none;" onchange="kycPreview(this,'kyc_prev_pan')"><img id="kyc_prev_pan" src="" style="display:none;width:64px;height:64px;object-fit:cover;border-radius:8px;margin-top:4px;border:1.5px solid var(--border);"></div>
        <div class="bc-field"><label>City</label><input type="text" id="kyc_city" value="Juba" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
        <div class="bc-field"><label>Country</label><input type="text" id="kyc_country" value="South Sudan" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      </div>
      <div style="background:#FEF3C7;border-radius:10px;padding:12px 14px;margin:12px 0;font-size:12px;color:#92400E;">
         Submitting will: create customer account, assign SIM (set to Sold), create service record, create data_mgmt entry, debit retailer wallet.
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button onclick="bcKycGoStep(2)" class="bc-btn ghost"> Back</button>
        <button onclick="bcKycSubmit(this)" class="bc-btn success" style="flex:1;"> Register Customer</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!--  SIM MANAGEMENT  -->
<?php if ($bcTab === 'simmanage' && $bcConfigured && $isAdmin):
$bcSimSub = $_GET['bcsub'] ?? 'stock';
$simSubNav = [
    ['id'=>'stock',   'label'=>' Stock Overview'],
    ['id'=>'assign',  'label'=>' Assign SIMs'],
    ['id'=>'returns', 'label'=>' Return Requests'],
    ['id'=>'history', 'label'=>' History'],
];
?>
<!-- Sub-nav -->
<div style="display:flex;border-bottom:2px solid var(--border);margin-bottom:18px;overflow-x:auto;">
  <?php foreach($simSubNav as $sn): $son=$bcSimSub===$sn['id']?'on':''; ?>
  <a href="?page=dashboard&tab=lte_bluecard&bc=simmanage&bcsub=<?= $sn['id'] ?>"
    class="bc-nav-a <?= $son ?>"><?= $sn['label'] ?></a>
  <?php endforeach; ?>
</div>

<?php //  STOCK OVERVIEW 
if ($bcSimSub === 'stock'):
$ss = $bcStats['sim_stock'] ?? null;
$st_d = $ss ?? [];
$stats_d = $st_d['stats'] ?? [];
$statColors = ['In stock'=>'#1D4ED8','Assigned'=>'#7C3AED','Sold'=>'#16A34A','Returned'=>'#64748B','Internal usage'=>'#F59E0B','Returned Request'=>'#DC2626'];
?>
<div class="bc-stats" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));">
  <?php foreach ($statColors as $label=>$color): $cnt=$stats_d[$label]??0; ?>
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:<?= $color ?>20;color:<?= $color ?>;font-size:20px;width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;"></div>
    <div>
      <div class="bc-stat-val" style="color:<?= $color ?>;"><?= number_format($cnt) ?></div>
      <div class="bc-stat-lbl"><?= bch($label) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="bc-stat">
    <div class="bc-stat-ic" style="background:#F1F5F9;font-size:20px;width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;"></div>
    <div>
      <div class="bc-stat-val"><?= number_format($st_d['total']??0) ?></div>
      <div class="bc-stat-lbl">Total SIMs</div>
    </div>
  </div>
</div>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Recent Assignments</div></div>
  <?php $recent=$st_d['recent']??[]; if(empty($recent)): ?><div class="bc-empty">No assignment history yet.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>MSISDN</th><th>IMSI</th><th>Agent</th><th>Role</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($recent as $r): ?>
    <tr>
      <td class="mono" style="font-weight:600;"><?= bch($r['msisdn']??'') ?></td>
      <td class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['imsi']??'') ?></td>
      <td><div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></div><div class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['mobile']??'') ?></div></td>
      <td><span class="bc-badge blue"><?= bch($r['role_display']??'') ?></span></td>
      <td><span class="bc-pill <?= strtolower(str_replace(' ','-',$r['status']??'')) ?>"><?= bch($r['status']??'') ?></span></td>
      <td style="font-size:11px;color:var(--text-3);"><?= bcDate($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; // stock ?>

<?php //  ASSIGN SIMs 
if ($bcSimSub === 'assign'):
$simsAvail = $bcStats['sims_available'] ?? [];
?>
<div class="bc-card" style="max-width:700px;">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Assign SIM Cards to Agent</div></div>
  <div style="padding:20px;">
    <div id="bcSimAssignMsg"></div>
    <div class="bc-form-grid">
      <div class="bc-field" style="grid-column:1/-1;">
        <label>Search & Select Agent *</label>
        <input type="text" id="saAgentSearch" placeholder="Type agent name or mobile" autocomplete="off"
          style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"
          oninput="saAgentLookup(this.value)">
        <input type="hidden" id="saAgentId">
        <div id="saAgentDrop" style="display:none;border:1.5px solid var(--border);border-radius:10px;margin-top:4px;max-height:180px;overflow-y:auto;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.1);"></div>
        <div id="saAgentCard" style="display:none;margin-top:8px;background:#F0FDF4;border:1.5px solid #BBF7D0;border-radius:12px;padding:10px 14px;font-size:12px;color:#15803D;">
          Selected: <strong id="saAgentName"></strong>  Wallet: <strong id="saAgentWallet"></strong>  Role: <strong id="saAgentRole"></strong>
        </div>
      </div>
      <div class="bc-field" style="grid-column:1/-1;">
        <label>Select SIMs to Assign (In Stock: <?= count($simsAvail) ?>) *</label>
        <input type="text" id="saSimSearch" placeholder="Filter by MSISDN or IMSI"
          style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:8px 13px;font-size:12px;font-family:inherit;outline:none;margin-bottom:8px;"
          oninput="saFilterSims(this.value)">
        <div style="border:1.5px solid var(--border);border-radius:10px;max-height:200px;overflow-y:auto;">
          <?php if(empty($simsAvail)): ?>
          <div style="padding:16px;text-align:center;color:var(--text-3);font-size:13px;">No SIMs in stock</div>
          <?php else: ?>
          <table class="bc-tbl" id="saSimTable">
            <thead style="position:sticky;top:0;"><tr><th style="width:36px;"><input type="checkbox" id="saCheckAll" onchange="saToggleAll(this)"></th><th>MSISDN</th><th>IMSI</th><th>Type</th><th>Price</th></tr></thead>
            <tbody>
            <?php foreach($simsAvail as $s): ?>
            <tr class="sa-sim-row">
              <td><input type="checkbox" class="sa-sim-chk" value="<?= (int)$s['id'] ?>" data-price="<?= (float)($s['price']??0) ?>"></td>
              <td class="mono" style="font-weight:600;"><?= bch($s['msisdn']??'') ?></td>
              <td class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($s['imsi']??'') ?></td>
              <td style="font-size:11px;"><?= bch($s['sim_type']??'') ?></td>
              <td><?= !empty($s['price'])?bcAmtUsd($s['price']):'Free' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
        <div style="margin-top:6px;font-size:12px;color:var(--text-3);">Selected: <strong id="saSelectedCount">0</strong> SIMs  Total: <strong id="saSelectedPrice">$0.00</strong></div>
      </div>
      <div class="bc-field">
        <label>Charge Agent Wallet?</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
          <input type="checkbox" id="saChargeWallet" checked> Debit agent's wallet for SIM cost
        </label>
      </div>
    </div>
    <button onclick="saDoAssign(this)" class="bc-btn primary" style="margin-top:8px;"> Assign Selected SIMs</button>
  </div>
</div>
<?php endif; // assign ?>

<?php //  RETURN REQUESTS 
if ($bcSimSub === 'returns'): ?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> SIM Return Requests <span class="bc-badge orange"><?= number_format($bcTotal) ?> pending</span></div></div>
  <?php if(empty($bcRows)): ?><div class="bc-empty">No pending return requests.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th><input type="checkbox" id="retCheckAll" onchange="retToggleAll(this)"></th><th>MSISDN</th><th>IMSI</th><th>Agent</th><th>Master</th><th>Price</th><th>Refund (50%)</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($bcRows as $r): ?>
    <tr>
      <td><input type="checkbox" class="ret-chk" value="<?= (int)($r['sim_id'] ?? 0) ?>"></td>
      <td class="mono" style="font-weight:600;"><?= bch($r['msisdn']??'') ?></td>
      <td class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['imsi']??'') ?></td>
      <td><div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></div><div class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['mobile']??'') ?></div></td>
      <td style="font-size:12px;"><?= bch($r['master_name']??'') ?></td>
      <td><?= !empty($r['price'])?bcAmtUsd($r['price']):'' ?></td>
      <td style="color:#16A34A;font-weight:700;"><?= !empty($r['price'])?bcAmtUsd($r['price']/2):'' ?></td>
      <td style="font-size:11px;color:var(--text-3);"><?= bcDate($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="padding:14px 16px;border-top:1px solid var(--border);display:flex;gap:10px;">
    <button onclick="retAccept(this)" class="bc-btn success"> Accept Selected Returns</button>
    <div id="retMsg" style="font-size:12px;display:flex;align-items:center;color:var(--text-3);">Select SIMs above then click accept.</div>
  </div>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'simmanage', '', '') ?>
  <?php endif; ?>
</div>
<?php endif; // returns ?>

<?php //  HISTORY 
if ($bcSimSub === 'history'): $bcQ2=trim($_GET['bcq']??''); ?>
<form method="GET" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="lte_bluecard">
  <input type="hidden" name="bc" value="simmanage"><input type="hidden" name="bcsub" value="history">
  <input type="text" name="bcq" value="<?= bch($bcQ2) ?>" placeholder="MSISDN, IMSI, agent name" style="flex:1;min-width:200px;border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
  <select name="bcst" style="border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
    <option value="">All Status</option>
    <?php foreach(['In stock','Assigned','Sold','Returned','Returned Request'] as $s): ?>
    <option value="<?= $s ?>" <?= $bcSt===$s?'selected':'' ?>><?= $s ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="bc-btn primary"> Search</button>
</form>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> SIM Assignment History <span class="bc-badge gray"><?= number_format($bcTotal) ?></span></div></div>
  <?php if(empty($bcRows)): ?><div class="bc-empty">No history found.</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>MSISDN</th><th>IMSI</th><th>Agent</th><th>Master</th><th>Status</th><th>Price</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($bcRows as $r): ?>
    <tr>
      <td class="mono" style="font-weight:600;"><?= bch($r['msisdn']??'') ?></td>
      <td class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['imsi']??'') ?></td>
      <td><div style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?: '' ?></div><div class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($r['mobile']??'') ?></div></td>
      <td style="font-size:12px;color:var(--text-3);"><?= bch($r['master_name']??'') ?></td>
      <td><span class="bc-pill <?= strtolower(str_replace(' ','-',$r['status']??'')) ?>"><?= bch($r['status']??'') ?></span></td>
      <td><?= isset($r['price'])&&!empty($r['price'])?bcAmtUsd($r['price']):'' ?></td>
      <td style="font-size:11px;color:var(--text-3);"><?= bcDate($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?= bcPager($bcPage, $bcTotal, $bcPages, 'simmanage', $bcQ2, $bcSt) ?>
  <?php endif; ?>
</div>
<?php endif; // history ?>

<?php endif; // simmanage tab ?>

<!--  RETAILER LEDGER  -->
<?php if ($bcTab === 'retailerledger' && $bcConfigured && $isAdmin): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
  <div>
    <div style="font-size:16px;font-weight:800;"> Retailer Ledger  Outstanding Tracker</div>
    <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
      Outstanding = Total recharged by retailer  Total collected by BBC (4G cashbook Cash IN)
    </div>
  </div>
  <a href="?page=dashboard&tab=cashbook&cb_proj=4g" class="bc-btn primary" style="font-size:12px;"> Record Collection (4G Cashbook)</a>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px;" id="rlSummaryCards">
  <div class="bc-card" style="padding:14px;text-align:center;"><div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Total Recharged</div><div id="rlTotalRecharged" style="font-size:24px;font-weight:800;color:#1D4ED8;"></div></div>
  <div class="bc-card" style="padding:14px;text-align:center;"><div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Total Collected</div><div id="rlTotalCollected" style="font-size:24px;font-weight:800;color:#16A34A;"></div></div>
  <div class="bc-card" style="padding:14px;text-align:center;"><div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">Total Outstanding</div><div id="rlTotalOutstanding" style="font-size:24px;font-weight:800;color:#DC2626;"></div></div>
  <div class="bc-card" style="padding:14px;text-align:center;"><div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;">At/Over Limit</div><div id="rlBlockedCount" style="font-size:24px;font-weight:800;color:#D97706;"></div></div>
</div>

<!-- Main ledger table -->
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t">Retailers <span id="rlCount" class="bc-badge gray"></span></div>
    <div style="display:flex;gap:8px;">
      <input type="text" id="rlSearch" placeholder="Search retailer" oninput="rlFilter(this.value)" style="border:1.5px solid var(--border);border-radius:10px;padding:7px 12px;font-size:12px;font-family:inherit;outline:none;width:200px;">
      <select id="rlStatusFilter" onchange="rlFilter(document.getElementById('rlSearch').value)" style="border:1.5px solid var(--border);border-radius:10px;padding:7px 12px;font-size:12px;font-family:inherit;outline:none;">
        <option value="">All</option>
        <option value="blocked"> At/Over Limit</option>
        <option value="ok"> OK</option>
      </select>
    </div>
  </div>
  <div id="rlTableWrap" style="overflow-x:auto;">
    <div style="padding:24px;text-align:center;color:var(--text-3);"> Loading</div>
  </div>
</div>

<!-- Limit edit modal -->
<div id="rlLimitModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:var(--bg);border-radius:16px;padding:24px;width:380px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="font-size:16px;font-weight:800;margin-bottom:4px;"> Set Retailer Limit</div>
    <div id="rlLimitName" style="font-size:13px;color:var(--text-3);margin-bottom:14px;"></div>
    <div style="margin-bottom:12px;">
      <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;display:block;margin-bottom:5px;">Max Outstanding Limit (USD)</label>
      <input type="number" id="rlLimitAmt" min="0" step="50" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:15px;font-family:inherit;outline:none;font-weight:700;">
    </div>
    <div style="margin-bottom:12px;display:flex;align-items:center;gap:8px;">
      <input type="checkbox" id="rlManualBlock" style="width:18px;height:18px;">
      <label for="rlManualBlock" style="font-size:13px;font-weight:600;cursor:pointer;">Manually blocked (block regardless of limit)</label>
    </div>
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;display:block;margin-bottom:5px;">Notes</label>
      <input type="text" id="rlLimitNotes" placeholder="Reason for limit change" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;">
      <button onclick="rlCloseLimitModal()" class="bc-btn ghost">Cancel</button>
      <button onclick="rlSaveLimit()" class="bc-btn primary"> Save Limit</button>
    </div>
    <div id="rlLimitMsg" style="margin-top:8px;font-size:12px;"></div>
  </div>
</div>

<script>
var _rlData = [];
var _rlLimitBcId = 0;
var _rlLimitBcName = '';
var _rlLimitBcMobile = '';

function rlLoad() {
  fetch('?page=api&action=bc_retailer_ledger')
    .then(function(r){return r.json();})
    .then(function(d){
      _rlData = (d.data||{}).rows || [];
      rlUpdateSummary();
      rlFilter('');
    }).catch(function(){ document.getElementById('rlTableWrap').innerHTML='<div style="padding:20px;color:#DC2626;text-align:center;">Failed to load data.</div>'; });
}
rlLoad();

function rlUpdateSummary() {
  var tr=0,tc=0,to2=0,blocked=0;
  _rlData.forEach(function(r){ tr+=r.recharged; tc+=r.collected; to2+=r.outstanding; if(r.is_blocked) blocked++; });
  document.getElementById('rlTotalRecharged').textContent = '$'+tr.toFixed(2);
  document.getElementById('rlTotalCollected').textContent = '$'+tc.toFixed(2);
  document.getElementById('rlTotalOutstanding').textContent = '$'+to2.toFixed(2);
  document.getElementById('rlBlockedCount').textContent = blocked;
}

function rlFilter(q) {
  q = (q||'').toLowerCase();
  var st = document.getElementById('rlStatusFilter').value;
  var filtered = _rlData.filter(function(r){
    if (q && (r.name||'').toLowerCase().indexOf(q)<0 && (r.mobile||'').indexOf(q)<0) return false;
    if (st==='blocked' && !r.is_blocked) return false;
    if (st==='ok' && r.is_blocked) return false;
    return true;
  });
  document.getElementById('rlCount').textContent = filtered.length;
  if (!filtered.length) { document.getElementById('rlTableWrap').innerHTML='<div style="padding:20px;text-align:center;color:var(--text-3);">No retailers found.</div>'; return; }

  var html = '<table class="bc-tbl"><thead><tr>'
    + '<th>Retailer</th><th>Role</th><th>Recharged</th><th>Collected</th>'
    + '<th>Outstanding</th><th>Limit</th><th>Used%</th><th>Status</th><th>Actions</th>'
    + '</tr></thead><tbody>';

  filtered.forEach(function(r) {
    var pct = Math.min(r.pct_used, 100);
    var barCol = pct>=100?'#DC2626':pct>=80?'#D97706':'#16A34A';
    var statusBadge = r.is_blocked
      ? '<span style="background:#FEE2E2;color:#DC2626;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"> BLOCKED</span>'
      : r.pct_used>=80
        ? '<span style="background:#FEF3C7;color:#D97706;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"> Near Limit</span>'
        : '<span style="background:#DCFCE7;color:#16A34A;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"> OK</span>';

    var bar = '<div style="background:#E2E8F0;border-radius:4px;height:6px;width:80px;display:inline-block;vertical-align:middle;">'
      + '<div style="background:'+barCol+';border-radius:4px;height:6px;width:'+pct+'%;"></div></div>'
      + ' <span style="font-size:11px;color:'+barCol+';">'+r.pct_used+'%</span>';

    html += '<tr>'
      + '<td><div style="font-weight:700;">' + (r.name||'') + '</div><div style="font-size:11px;color:var(--text-3);" class="mono">' + (r.mobile||'') + '</div></td>'
      + '<td style="font-size:12px;">' + (r.role||'') + '</td>'
      + '<td style="font-weight:600;color:#1D4ED8;">$' + r.recharged.toFixed(2) + '</td>'
      + '<td style="font-weight:600;color:#16A34A;">$' + r.collected.toFixed(2) + '</td>'
      + '<td style="font-weight:800;color:' + (r.outstanding>0?'#DC2626':'#64748B') + ';">$' + r.outstanding.toFixed(2) + '</td>'
      + '<td style="font-size:12px;">$' + r.limit_usd.toFixed(0) + '</td>'
      + '<td>' + bar + '</td>'
      + '<td>' + statusBadge + '</td>'
      + '<td><button onclick="rlOpenLimit('+r.bc_user_id+',\''+r.name.replace(/'/g,'\\\'') + '\',\''+r.mobile+'\')" class="bc-btn ghost" style="font-size:11px;padding:3px 9px;"> Set Limit</button></td>'
      + '</tr>';
  });

  html += '</tbody></table>';
  document.getElementById('rlTableWrap').innerHTML = html;
}

function rlOpenLimit(bcId, name, mobile) {
  _rlLimitBcId = bcId; _rlLimitBcName = name; _rlLimitBcMobile = mobile;
  document.getElementById('rlLimitName').textContent = name + '  ' + mobile;
  var existing = _rlData.find(function(r){ return r.bc_user_id===bcId; });
  document.getElementById('rlLimitAmt').value = existing ? existing.limit_usd : 500;
  document.getElementById('rlManualBlock').checked = existing ? existing.manual_block : false;
  document.getElementById('rlLimitNotes').value = '';
  document.getElementById('rlLimitMsg').innerHTML = '';
  document.getElementById('rlLimitModal').style.display = 'flex';
}
function rlCloseLimitModal() { document.getElementById('rlLimitModal').style.display='none'; }
function rlSaveLimit() {
  var amt = parseFloat(document.getElementById('rlLimitAmt').value) || 0;
  var blocked = document.getElementById('rlManualBlock').checked ? 1 : 0;
  var notes = document.getElementById('rlLimitNotes').value.trim();
  fetch('?page=api&action=bc_set_retailer_limit', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({bc_user_id:_rlLimitBcId, limit_usd:amt, is_blocked:blocked, notes:notes, bc_name:_rlLimitBcName, bc_mobile:_rlLimitBcMobile})
  }).then(function(r){return r.json();}).then(function(d){
    if (d.status==='success') {
      document.getElementById('rlLimitMsg').innerHTML='<span style="color:#16A34A;"> Saved!</span>';
      setTimeout(function(){ rlCloseLimitModal(); rlLoad(); },800);
    } else {
      document.getElementById('rlLimitMsg').innerHTML='<span style="color:#DC2626;"> '+(d.message||'Failed')+'</span>';
    }
  });
}
</script>

<?php endif; // retailerledger ?>

<!--  AGENT MERGE  -->
<?php if ($bcTab === 'agentmerge' && $bcConfigured && $isAdmin): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
  <div>
    <div style="font-size:16px;font-weight:800;"> Agent Merge  BlueCard  Plugin Login</div>
    <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Match BlueCard agents to existing plugin accounts, or create new ones.</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button onclick="amSyncAll(this)" class="bc-btn primary" style="font-size:12px;"> Sync All New Agents</button>
    <span id="amSyncMsg" style="font-size:12px;line-height:32px;"></span>
  </div>
</div>

<!-- Legend -->
<div style="display:flex;gap:12px;margin-bottom:14px;flex-wrap:wrap;font-size:12px;">
  <span style="background:#DCFCE7;color:#16A34A;padding:3px 10px;border-radius:20px;font-weight:700;"> Linked</span>
  <span style="background:#FEF3C7;color:#92400E;padding:3px 10px;border-radius:20px;font-weight:700;"> Not Linked</span>
  <span style="background:#EFF6FF;color:#1D4ED8;padding:3px 10px;border-radius:20px;font-weight:700;"> Will Create New</span>
</div>

<!-- Main merge table  loads via JS -->
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t">BlueCard Agents <span id="amAgentCount" class="bc-badge gray"></span></div>
    <div style="display:flex;gap:8px;">
      <input type="text" id="amSearch" placeholder="Search name / mobile" oninput="amFilter(this.value)" style="border:1.5px solid var(--border);border-radius:10px;padding:7px 13px;font-size:12px;font-family:inherit;outline:none;width:220px;">
      <select id="amRoleFilter" onchange="amFilter(document.getElementById('amSearch').value)" style="border:1.5px solid var(--border);border-radius:10px;padding:7px 12px;font-size:12px;font-family:inherit;outline:none;">
        <option value="">All Roles</option>
        <option value="dealer">Dealer</option>
        <option value="retailer">Retailer</option>
        <option value="franchisee">Franchisee</option>
        <option value="admin">Admin</option>
      </select>
      <select id="amStatusFilter" onchange="amFilter(document.getElementById('amSearch').value)" style="border:1.5px solid var(--border);border-radius:10px;padding:7px 12px;font-size:12px;font-family:inherit;outline:none;">
        <option value="">All Status</option>
        <option value="linked">Linked only</option>
        <option value="unlinked">Not linked only</option>
      </select>
    </div>
  </div>
  <div id="amTableWrap" style="overflow-x:auto;">
    <div style="padding:24px;text-align:center;color:var(--text-3);"> Loading agents</div>
  </div>
</div>

<!-- Link modal -->
<div id="amLinkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:var(--bg);border-radius:16px;padding:24px;width:460px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="font-size:16px;font-weight:800;margin-bottom:4px;"> Link to Existing Plugin Account</div>
    <div id="amLinkAgentName" style="font-size:13px;color:var(--text-3);margin-bottom:14px;"></div>
    <div style="margin-bottom:12px;">
      <label style="font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;display:block;margin-bottom:5px;">Search Plugin Account</label>
      <input type="text" id="amLinkSearch" placeholder="Type name, email or phone" oninput="amSearchRetailers(this.value)" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
    </div>
    <div id="amRetailerList" style="max-height:240px;overflow-y:auto;border:1.5px solid var(--border);border-radius:10px;"></div>
    <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end;">
      <button onclick="amCloseLinkModal()" class="bc-btn ghost">Cancel</button>
    </div>
  </div>
</div>

<script>
var _amBcAgents = [];
var _amRetailers = [];
var _amLinkBcId = 0;

(function() {
    // Load BlueCard agents + plugin retailers in parallel
    var done = 0;
    function check() { if (++done >= 2) amRender(document.getElementById('amSearch').value); }

    // Load all BC agents (paginate)
    function loadBcAgents(page) {
        fetch('?page=api&action=bc_proxy&table=bc_agents&page=' + page + '&per=100')
            .then(function(r){return r.json();})
            .then(function(d){
                var rows = (d.data||{}).rows || [];
                _amBcAgents = _amBcAgents.concat(rows);
                var pages = (d.data||{}).pages || 1;
                if (page < pages && page < 20) { loadBcAgents(page + 1); }
                else { check(); }
            }).catch(function(){ check(); });
    }
    loadBcAgents(1);

    // Load plugin retailers
    fetch('?page=api&action=bc_list_retailers')
        .then(function(r){return r.json();})
        .then(function(d){
            _amRetailers = (d.data||{}).retailers || [];
            check();
        }).catch(function(){ check(); });
})();

function amGetPluginMatch(bcId, email, mobile) {
    for (var i = 0; i < _amRetailers.length; i++) {
        var r = _amRetailers[i];
        if (r.bluecard_user_id && parseInt(r.bluecard_user_id) === parseInt(bcId)) return r;
        if (email && r.email && r.email.toLowerCase() === email.toLowerCase()) return r;
        if (mobile && r.phone && r.phone === mobile) return r;
    }
    return null;
}

function amFilter(q) {
    var role = document.getElementById('amRoleFilter').value;
    var status = document.getElementById('amStatusFilter').value;
    amRender(q, role, status);
}

function amRender(q, roleFilter, statusFilter) {
    q = (q||'').toLowerCase();
    roleFilter = roleFilter || document.getElementById('amRoleFilter').value;
    statusFilter = statusFilter || document.getElementById('amStatusFilter').value;
    var filtered = _amBcAgents.filter(function(a) {
        var name = ((a.firstname||'') + ' ' + (a.lastname||'')).toLowerCase();
        var mobile = (a.mobile||'').toLowerCase();
        var email = (a.email||'').toLowerCase();
        var role = (a.role_name||'').toLowerCase();
        var match = amGetPluginMatch(a.id, a.email, a.mobile);
        var isLinked = !!match && match.bluecard_user_id;
        if (q && name.indexOf(q)<0 && mobile.indexOf(q)<0 && email.indexOf(q)<0) return false;
        if (roleFilter && role !== roleFilter) return false;
        if (statusFilter === 'linked' && !isLinked) return false;
        if (statusFilter === 'unlinked' && isLinked) return false;
        return true;
    });

    document.getElementById('amAgentCount').textContent = filtered.length + ' / ' + _amBcAgents.length;

    if (!filtered.length) {
        document.getElementById('amTableWrap').innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-3);">No agents found.</div>';
        return;
    }

    var html = '<table class="bc-tbl"><thead><tr>'
        + '<th>BC ID</th><th>BC Agent</th><th>BC Mobile</th><th>BC Role</th>'
        + '<th>Plugin Account</th><th>Status</th><th>Actions</th>'
        + '</tr></thead><tbody>';

    filtered.forEach(function(a) {
        var match = amGetPluginMatch(a.id, a.email, a.mobile);
        var isLinked = match && match.bluecard_user_id;
        var statusBadge, pluginCell, actions;

        if (isLinked) {
            statusBadge = '<span style="background:#DCFCE7;color:#16A34A;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"> Linked</span>';
            pluginCell = '<div style="font-weight:600;font-size:13px;">' + match.name + '</div>'
                + '<div style="font-size:11px;color:var(--text-3);">' + match.email + '  ' + match.role + '</div>'
                + '<div style="font-size:10px;color:var(--text-3);">Plugin ID #' + match.id + '</div>';
            actions = '<button onclick="amSync(' + a.id + ',this)" class="bc-btn ghost" style="font-size:11px;padding:3px 9px;" title="Re-sync data"> Sync</button>'
                + ' <button onclick="amUnlink(' + match.id + ',this)" class="bc-btn danger" style="font-size:11px;padding:3px 9px;" title="Remove link"> Unlink</button>';
        } else if (match) {
            // Auto-matched by email/phone but not formally linked
            statusBadge = '<span style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"> Matched</span>';
            pluginCell = '<div style="font-weight:600;font-size:13px;">' + match.name + '</div>'
                + '<div style="font-size:11px;color:var(--text-3);">' + match.email + '  ' + match.role + '</div>'
                + '<div style="font-size:10px;color:#D97706;">Plugin ID #' + match.id + ' (not formally linked)</div>';
            actions = '<button onclick="amSync(' + a.id + ',this)" class="bc-btn primary" style="font-size:11px;padding:3px 9px;"> Link & Sync</button>';
        } else {
            statusBadge = '<span style="background:#FEE2E2;color:#DC2626;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"> No Account</span>';
            pluginCell = '<span style="color:var(--text-3);font-size:12px;">No plugin account</span>';
            actions = '<button onclick="amSync(' + a.id + ',this)" class="bc-btn primary" style="font-size:11px;padding:3px 9px;"> Create Login</button>'
                + ' <button onclick="amOpenLink(' + a.id + ',\'' + (a.firstname||'') + ' ' + (a.lastname||'') + '\')" class="bc-btn ghost" style="font-size:11px;padding:3px 9px;"> Link Existing</button>';
        }

        html += '<tr>'
            + '<td class="mono" style="color:var(--text-3);font-size:11px;">' + a.id + '</td>'
            + '<td><div style="font-weight:600;">' + (a.firstname||'') + ' ' + (a.lastname||'') + '</div>'
            +     '<div style="font-size:11px;color:var(--text-3);">' + (a.email||'') + '</div></td>'
            + '<td class="mono" style="font-size:12px;">' + (a.mobile||'') + '</td>'
            + '<td><span class="bc-pill">' + (a.role_display||a.role_name||'') + '</span></td>'
            + '<td>' + pluginCell + '</td>'
            + '<td>' + statusBadge + '</td>'
            + '<td id="amActions_' + a.id + '" style="white-space:nowrap;">' + actions + '</td>'
            + '</tr>';
    });

    html += '</tbody></table>';
    document.getElementById('amTableWrap').innerHTML = html;
}

function amSync(bcUid, btn) {
    var row = document.getElementById('amActions_' + bcUid);
    btn.disabled = true; btn.textContent = '';
    fetch('?page=api&action=bc_agent_sync', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({bc_user_id: bcUid})
    }).then(function(r){return r.json();}).then(function(d){
        if (d.status === 'success') {
            var res = (d.data.results||[])[0];
            var act = res ? res.action : 'done';
            var pwd = res && res.default_pwd ? '  Initial pwd: <strong>' + res.default_pwd + '</strong>' : '';
            // Update local retailer list
            fetch('?page=api&action=bc_list_retailers').then(function(r2){return r2.json();}).then(function(d2){
                _amRetailers = (d2.data||{}).retailers || [];
                amFilter(document.getElementById('amSearch').value);
            });
            if (row) row.innerHTML = '<span style="color:#16A34A;font-size:12px;"> ' + act + pwd + '</span>';
        } else {
            btn.disabled = false; btn.textContent = ' Sync';
            alert(' ' + (d.message||'Failed'));
        }
    }).catch(function(e){ btn.disabled = false; alert(' ' + e); });
}

function amUnlink(pluginId, btn) {
    if (!confirm('Remove the link between this plugin account and BlueCard? The plugin account stays, just the link is removed.')) return;
    btn.disabled = true;
    fetch('?page=api&action=bc_agent_unlink', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({plugin_id: pluginId})
    }).then(function(r){return r.json();}).then(function(d){
        if (d.status === 'success') {
            fetch('?page=api&action=bc_list_retailers').then(function(r2){return r2.json();}).then(function(d2){
                _amRetailers = (d2.data||{}).retailers || [];
                amFilter(document.getElementById('amSearch').value);
            });
        } else { btn.disabled = false; alert(' ' + (d.message||'Failed')); }
    }).catch(function(e){ btn.disabled = false; alert(' ' + e); });
}

function amSyncAll(btn) {
    if (!confirm('Create plugin logins for ALL unlinked BlueCard agents? Existing accounts will be updated.')) return;
    btn.disabled = true; btn.textContent = ' Syncing';
    document.getElementById('amSyncMsg').innerHTML = '';
    fetch('?page=api&action=bc_agent_sync', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({sync_all: true})
    }).then(function(r){return r.json();}).then(function(d){
        btn.disabled = false; btn.textContent = ' Sync All New Agents';
        if (d.status === 'success') {
            var info = d.data;
            document.getElementById('amSyncMsg').innerHTML = '<span style="color:#16A34A;"> Created: ' + info.created + '  Updated: ' + info.updated + '  Skipped: ' + info.skipped + '</span>';
            fetch('?page=api&action=bc_list_retailers').then(function(r2){return r2.json();}).then(function(d2){
                _amRetailers = (d2.data||{}).retailers || [];
                amFilter(document.getElementById('amSearch').value);
            });
        } else {
            document.getElementById('amSyncMsg').innerHTML = '<span style="color:#DC2626;"> ' + (d.message||'Failed') + '</span>';
        }
    }).catch(function(e){
        btn.disabled = false; btn.textContent = ' Sync All New Agents';
        document.getElementById('amSyncMsg').innerHTML = '<span style="color:#DC2626;"> ' + e + '</span>';
    });
}

function amOpenLink(bcId, name) {
    _amLinkBcId = bcId;
    document.getElementById('amLinkAgentName').textContent = 'BlueCard Agent: ' + name + ' (BC #' + bcId + ')';
    document.getElementById('amLinkSearch').value = '';
    document.getElementById('amRetailerList').innerHTML = '<div style="padding:12px;color:var(--text-3);font-size:13px;">Type to search plugin accounts</div>';
    var modal = document.getElementById('amLinkModal');
    modal.style.display = 'flex';
}
function amCloseLinkModal() {
    document.getElementById('amLinkModal').style.display = 'none';
    _amLinkBcId = 0;
}
function amSearchRetailers(q) {
    q = q.toLowerCase();
    var list = document.getElementById('amRetailerList');
    var matches = _amRetailers.filter(function(r){
        return !q || (r.name||'').toLowerCase().indexOf(q)>=0 || (r.email||'').toLowerCase().indexOf(q)>=0 || (r.phone||'').toLowerCase().indexOf(q)>=0;
    }).slice(0,15);
    if (!matches.length) { list.innerHTML='<div style="padding:12px;color:var(--text-3);font-size:13px;">No matches.</div>'; return; }
    list.innerHTML = matches.map(function(r){
        var linked = r.bluecard_user_id ? ' <span style="color:#D97706;font-size:10px;">[BC#'+r.bluecard_user_id+']</span>' : '';
        return '<div onclick="amDoLink('+r.id+')" style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #F1F5F9;font-size:13px;" onmouseover="this.style.background=\'#F8FAFC\'" onmouseout="this.style.background=\'\'"> '
            + '<strong>' + r.name + '</strong>' + linked
            + '<div style="font-size:11px;color:var(--text-3);">' + (r.email||'') + '  ' + (r.phone||'') + '  ' + r.role + '</div></div>';
    }).join('');
}
function amDoLink(pluginId) {
    if (!_amLinkBcId) return;
    fetch('?page=api&action=bc_agent_link', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({bc_user_id: _amLinkBcId, plugin_id: pluginId})
    }).then(function(r){return r.json();}).then(function(d){
        amCloseLinkModal();
        if (d.status === 'success') {
            fetch('?page=api&action=bc_list_retailers').then(function(r2){return r2.json();}).then(function(d2){
                _amRetailers = (d2.data||{}).retailers || [];
                amFilter(document.getElementById('amSearch').value);
            });
        } else { alert(' ' + (d.message||'Link failed')); }
    }).catch(function(e){ amCloseLinkModal(); alert(' ' + e); });
}
</script>

<?php endif; // agentmerge ?>

<!--  CUSTOMER DETAIL  -->
<?php if ($bcTab === 'customerdetail' && $bcConfigured):
$bcCuid  = (int)($_GET['bcuid'] ?? 0);
$bcCsub  = $_GET['bcsub'] ?? 'overview';
$bcCpage = max(1,(int)($_GET['bcpg'] ?? 1));
$cu   = $bcCustData['user']          ?? [];
$dm   = $bcCustData['data_mgmt']     ?? [];
$sim  = $bcCustData['sim']           ?? [];
$bt   = $bcCustData['latest_topup']  ?? [];
$pr   = $bcCustData['pending_recharge'] ?? [];
$master = $bcCustData['master']      ?? [];
$msisdn = $cu['mobile'] ?? '';
$cdSubNav = [
    ['id'=>'overview',  'label'=>' Overview'],
    ['id'=>'services',  'label'=>' Services'],
    ['id'=>'invoices',  'label'=>' Invoices'],
    ['id'=>'passbook',  'label'=>' Passbook'],
    ['id'=>'recharge',  'label'=>' Recharge'],
    ['id'=>'documents', 'label'=>' Documents'],
    ['id'=>'edit',      'label'=>' Edit'],
];
?>
<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
  <a href="?page=dashboard&tab=lte_bluecard&bc=customers" class="bc-btn ghost" style="font-size:12px;padding:6px 12px;"> Back to Customers</a>
  <?php if ($cu): ?>
  <div style="font-size:18px;font-weight:800;"><?= bch(trim(($cu['firstname']??'').' '.($cu['lastname']??''))) ?></div>
  <span class="bc-pill <?= ($cu['is_active']??0)?'active':'expired' ?>"><?= ($cu['is_active']??0)?'Active':'Inactive' ?></span>
  <?php endif; ?>
</div>

<!-- Sub-nav tabs -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:18px;overflow-x:auto;-webkit-overflow-scrolling:touch;">
  <?php foreach($cdSubNav as $sn):
    $isOn = $bcCsub === $sn['id'];
    $url  = '?page=dashboard&tab=lte_bluecard&bc=customerdetail&bcuid='.$bcCuid.'&bcsub='.$sn['id'];
  ?>
  <a href="<?= bch($url) ?>" style="padding:10px 16px;font-size:13px;white-space:nowrap;font-weight:600;text-decoration:none;border-bottom:<?= $isOn?'2px solid #1D4ED8':'2px solid transparent' ?>;color:<?= $isOn?'#1D4ED8':'var(--text-2)' ?>;"><?= $sn['label'] ?></a>
  <?php endforeach; ?>
</div>

<?php if (empty($cu)): ?>
<div class="bc-card"><div style="padding:24px;text-align:center;color:var(--text-3);">Customer not found or failed to load.</div></div>

<?php elseif ($bcCsub === 'overview'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
  <div class="bc-card">
    <div class="bc-card-hd"><div class="bc-card-hd-t"> Customer Info</div>
      <a href="?page=dashboard&tab=lte_bluecard&bc=customerdetail&bcuid=<?= $bcCuid ?>&bcsub=edit" class="bc-btn ghost" style="font-size:11px;padding:4px 10px;"> Edit</a>
    </div>
    <div style="padding:16px;">
      <?php if (!empty($cu['profile'])): ?>
      <img src="<?= bch($cu['profile']) ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--border);margin-bottom:12px;display:block;">
      <?php endif; ?>
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <?php foreach([
          ['Mobile/MSISDN', $cu['mobile']??''],
          ['Email',         $cu['email']??''],
          ['Gender',        $cu['gender']??''],
          ['Date of Birth', $cu['date_of_birth']??''],
          ['Nationality',   $cu['nationality']??''],
          ['Alt Mobile',    $cu['alternateMobileNo']??''],
          ['WhatsApp',      $cu['whatsapp_number']??''],
          ['ID Number',     $cu['aadhar_card_no']??''],
          ['Address',       $cu['address']??''],
          ['City',          $cu['city']??''],
          ['Agent/Master',  $master ? trim(($master['firstname']??'').' '.($master['lastname']??'')) : ''],
        ] as $f): ?>
        <tr style="border-bottom:1px solid #F8FAFC;">
          <td style="padding:5px 0;color:var(--text-3);font-size:11px;font-weight:700;text-transform:uppercase;width:110px;"><?= bch($f[0]) ?></td>
          <td style="padding:5px 0;font-weight:600;"><?= bch($f[1]) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <div>
    <div class="bc-card" style="margin-bottom:14px;">
      <div class="bc-card-hd"><div class="bc-card-hd-t"> Active Plan</div></div>
      <div style="padding:16px;">
        <?php if ($dm): ?>
        <div style="font-size:22px;font-weight:800;color:#1D4ED8;"><?= bch($dm['plan_name']??'') ?></div>
        <div style="font-size:13px;color:var(--text-3);margin-top:4px;">
          <?php if (($dm['plan_type']??0)==2): $daysLeft=round((strtotime($dm['end_date'])-time())/86400); ?>
          Unlimited  <?= max(0,$daysLeft) ?> days left
          <?php else: $gb=round(($cu['data']??0)/1e9,2); $total=round(($dm['data']??0)/1e9,2); ?>
          <?= $gb ?> GB left of <?= $total ?> GB
          <?php endif; ?>
        </div>
        <div style="margin-top:8px;font-size:12px;"><span style="color:var(--text-3);">Expires:</span> <strong><?= bcDate($dm['end_date']??null) ?></strong></div>
        <?php if ($pr): ?>
        <div style="margin-top:10px;background:#FEF3C7;border-radius:8px;padding:8px 12px;font-size:12px;color:#92400E;"> Pending advance: <strong><?= bch($pr['offer_name']??'') ?></strong></div>
        <?php endif; ?>
        <?php else: ?><div style="color:var(--text-3);font-size:13px;">No active plan</div><?php endif; ?>
      </div>
    </div>
    <div class="bc-card" style="margin-bottom:14px;">
      <div class="bc-card-hd"><div class="bc-card-hd-t"> SIM Card</div></div>
      <div style="padding:14px;font-size:13px;">
        <?php if ($sim): ?>
        <div><span style="color:var(--text-3);font-size:11px;">MSISDN</span><br><strong class="mono"><?= bch($sim['msisdn']??'') ?></strong></div>
        <div style="margin-top:8px;"><span style="color:var(--text-3);font-size:11px;">IMSI</span><br><strong class="mono" style="font-size:12px;"><?= bch($sim['imsi']??'') ?></strong></div>
        <div style="margin-top:8px;"><?= $sim['status']??'' ?></div>
        <?php else: ?><div style="color:var(--text-3);">No SIM record</div><?php endif; ?>
      </div>
    </div>
    <?php $hasDocs=!empty($cu['profile'])||!empty($cu['aadhar_card_front_img'])||!empty($cu['aadhar_card_back_img'])||!empty($cu['pan_card_img']); ?>
    <?php if ($hasDocs): ?>
    <div class="bc-card">
      <div class="bc-card-hd"><div class="bc-card-hd-t"> Documents</div></div>
      <div style="padding:12px;display:flex;gap:10px;flex-wrap:wrap;">
        <?php foreach([['Photo',$cu['profile']??''],['ID Front',$cu['aadhar_card_front_img']??''],['ID Back',$cu['aadhar_card_back_img']??''],['PAN',$cu['pan_card_img']??'']] as $doc): if (!$doc[1]) continue; ?>
        <div style="text-align:center;"><a href="<?= bch($doc[1]) ?>" target="_blank"><img src="<?= bch($doc[1]) ?>" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1.5px solid var(--border);" onerror="this.style.display='none'"></a><div style="font-size:10px;color:var(--text-3);margin-top:2px;"><?= bch($doc[0]) ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($bcCsub === 'services'): $svcD=$bcCustData['services']??[]; $srows=$svcD['rows']??[]; $stotal=$svcD['total']??0; $spages=$svcD['pages']??1; ?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Service History <span class="bc-badge gray"><?= number_format($stotal) ?></span></div></div>
  <?php if(empty($srows)): ?><div class="bc-empty">No records</div>
  <?php else: ?><div style="overflow-x:auto;"><table class="bc-tbl">
    <thead><tr><th>REF NO</th><th>Plan</th><th>Amount</th><th>Agent</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach($srows as $sr): $cancelled=!empty($sr['deleted_at']); ?>
    <tr style="<?= $cancelled?'opacity:.5;':'' ?>">
      <td class="mono" style="font-size:11px;color:var(--text-3);">ReFblueCARD<?= str_pad((int)$sr['id'],7,'0',STR_PAD_LEFT) ?></td>
      <td style="font-weight:600;"><?= bch($sr['plan_name']??$sr['productOffering']??'') ?></td>
      <td><?= bcAmtUsd(($sr['amount']??0)/100) ?></td>
      <td style="font-size:12px;"><?= bch($sr['agent_name']??'') ?></td>
      <td style="font-size:11px;"><?= bcDate($sr['end_date']??null) ?></td>
      <td><?= $cancelled?'<span class="bc-pill expired">Cancelled</span>':'<span class="bc-pill active">Active</span>' ?></td>
      <td><?php if(!$cancelled&&$isAdmin): ?><button onclick="bcCancelPlanAdmin(<?= (int)$sr['id'] ?>)" class="bc-btn danger" style="font-size:11px;padding:3px 9px;"> Cancel</button><?php else: ?><?php endif; ?></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
  <?= bcPager($bcCpage,$stotal,$spages,'customerdetail','&bcuid='.$bcCuid.'&bcsub=services') ?>
  <?php endif; ?>
</div>

<?php elseif ($bcCsub === 'invoices'): $invD=$bcCustData['invoices']??[]; $invrows=$invD['rows']??[]; $invtotal=$invD['total']??0; $invpages=$invD['pages']??1; ?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Invoices <span class="bc-badge gray"><?= number_format($invtotal) ?></span></div></div>
  <?php if(empty($invrows)): ?><div class="bc-empty">No invoices</div>
  <?php else: ?><div style="overflow-x:auto;"><table class="bc-tbl">
    <thead><tr><th>REF NO</th><th>Plan</th><th>Amount</th><th>Agent</th><th>Date</th><th>Expires</th><th>PDF</th></tr></thead>
    <tbody>
    <?php foreach($invrows as $r): ?>
    <tr>
      <td class="mono" style="font-size:11px;">ReFblueCARD<?= str_pad((int)$r['id'],7,'0',STR_PAD_LEFT) ?></td>
      <td style="font-weight:600;"><?= bch($r['plan_name']??$r['productOffering']??'') ?></td>
      <td><?= bcAmtUsd(($r['amount']??0)/100) ?></td>
      <td style="font-size:12px;"><?= bch($r['agent_name']??'') ?></td>
      <td style="font-size:11px;"><?= bcDate($r['created_at']??null) ?></td>
      <td style="font-size:11px;"><?= bcDate($r['end_date']??null) ?></td>
      <td><?= !empty($r['invoice_file'])?'<a href="'.bch($r['invoice_file']).'" target="_blank" class="bc-btn ghost" style="font-size:11px;padding:3px 8px;"> PDF</a>':'' ?></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
  <?= bcPager($bcCpage,$invtotal,$invpages,'customerdetail','&bcuid='.$bcCuid.'&bcsub=invoices') ?>
  <?php endif; ?>
</div>

<?php elseif ($bcCsub === 'passbook'): $pbD=$bcCustData['passbook']??[]; $pbrows=$pbD['rows']??[]; $pbtotal=$pbD['total']??0; $pbpages=$pbD['pages']??1; ?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Passbook <span class="bc-badge gray"><?= number_format($pbtotal) ?></span></div></div>
  <?php if(empty($pbrows)): ?><div class="bc-empty">No passbook entries</div>
  <?php else: ?><div style="overflow-x:auto;"><table class="bc-tbl">
    <thead><tr><th>TRX</th><th>Type</th><th>Amount</th><th>Balance</th><th>Description</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($pbrows as $pb): ?>
    <tr>
      <td class="mono" style="font-size:11px;color:var(--text-3);"><?= bch($pb['trx_no']??'') ?></td>
      <td><?= ($pb['type']??'')==='Credit'?'<span class="bc-pill active">Credit</span>':'<span class="bc-pill expired">Debit</span>' ?></td>
      <td style="font-weight:700;<?= ($pb['type']??'')==='Credit'?'color:#16A34A;':'color:#DC2626;' ?>"><?= bcAmtUsd($pb['amount']??0) ?></td>
      <td class="mono" style="font-size:12px;"><?= bcAmtUsd($pb['current_balance']??0) ?></td>
      <td style="font-size:12px;"><?= bch($pb['description']??'') ?></td>
      <td style="font-size:11px;color:var(--text-3);"><?= bcDate($pb['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?></tbody></table></div>
  <?= bcPager($bcCpage,$pbtotal,$pbpages,'customerdetail','&bcuid='.$bcCuid.'&bcsub=passbook') ?>
  <?php endif; ?>
</div>

<?php elseif ($bcCsub === 'recharge' && $isAdmin): ?>
<div class="bc-card" style="max-width:480px;">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Manual Recharge</div></div>
  <div style="padding:18px;">
    <div id="bcAdminRechargeMsg"></div>
    <div style="background:#EFF6FF;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#1D4ED8;"> MSISDN: <strong class="mono"><?= bch($msisdn) ?></strong></div>
    <div class="bc-field"><label>Select Plan *</label>
      <select id="bcAdminRechargePlan" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
        <option value="">-- Select Plan --</option>
        <?php $plsR=$store->getPdo()->query("SELECT id, bluecard_id, name, price_cents as amount, duration_days as days FROM lte_packages WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC); foreach($plsR as $pl): ?>
        <option value="<?= (int)$pl['id'] ?>" data-usd="<?= round(($pl['amount']??0)/100,2) ?>"><?= bch($pl['name']??'') ?>  $<?= number_format(($pl['amount']??0)/100,2) ?> / <?= (int)($pl['days']??30) ?> days</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="bc-field"><label>Agent/Retailer who pays *</label>
      <div id="bcAdminRechargeAgentBox" style="position:relative;">
        <input type="text" id="bcAdminRechargeAgentQ" placeholder="Search agent name / mobile" oninput="bcAdminAgentSearch(this.value)" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
        <input type="hidden" id="bcAdminRechargeAgentId" value="">
        <div id="bcAdminRechargeAgentDrop" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1.5px solid var(--border);border-radius:10px;z-index:50;max-height:180px;overflow-y:auto;"></div>
      </div>
    </div>
    <button onclick="bcAdminDoRecharge('<?= bch($msisdn) ?>',this)" class="bc-btn primary"> Recharge Now</button>
  </div>
</div>

<?php elseif ($bcCsub === 'documents'): ?>
<div class="bc-card" style="max-width:600px;">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> KYC Documents</div></div>
  <div style="padding:18px;">
    <?php $docs=[['Customer Photo','profile'],['ID Proof Front','aadhar_card_front_img'],['ID Proof Back','aadhar_card_back_img'],['PAN / Other ID','pan_card_img']];
    $hasDocs2=false;
    foreach($docs as $doc): if(empty($cu[$doc[1]])) continue; $hasDocs2=true; ?>
    <div style="margin-bottom:18px;">
      <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;margin-bottom:6px;"><?= bch($doc[0]) ?></div>
      <a href="<?= bch($cu[$doc[1]]) ?>" target="_blank"><img src="<?= bch($cu[$doc[1]]) ?>" style="max-width:100%;max-height:240px;border-radius:10px;border:1.5px solid var(--border);" onerror="this.parentNode.innerHTML='<span style=color:var(--text-3);font-size:12px;>Image not accessible</span>'"></a>
    </div>
    <?php endforeach; if(!$hasDocs2): ?><div style="color:var(--text-3);text-align:center;padding:16px;">No documents uploaded.</div><?php endif; ?>
  </div>
</div>

<?php elseif ($bcCsub === 'edit'): ?>
<div class="bc-card" style="max-width:640px;">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Edit Customer</div></div>
  <div style="padding:20px;">
    <div id="bcAdminEditMsg"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div class="bc-field"><label>First Name *</label><input type="text" id="bce_fn" value="<?= bch($cu['firstname']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Last Name</label><input type="text" id="bce_ln" value="<?= bch($cu['lastname']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Email</label><input type="email" id="bce_em" value="<?= bch($cu['email']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Alt Mobile</label><input type="text" id="bce_am" value="<?= bch($cu['alternateMobileNo']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>WhatsApp</label><input type="text" id="bce_wa" value="<?= bch($cu['whatsapp_number']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Gender</label><select id="bce_gn" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"><option value="male" <?= ($cu['gender']??'')==='male'?'selected':'' ?>>Male</option><option value="female" <?= ($cu['gender']??'')==='female'?'selected':'' ?>>Female</option></select></div>
      <div class="bc-field"><label>Date of Birth</label><input type="date" id="bce_db" value="<?= bch($cu['date_of_birth']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Nationality</label><input type="text" id="bce_na" value="<?= bch($cu['nationality']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>ID Number</label><input type="text" id="bce_id" value="<?= bch($cu['aadhar_card_no']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Status</label><select id="bce_st" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"><option value="1" <?= ($cu['is_active']??0)?'selected':'' ?>>Active</option><option value="0" <?= !($cu['is_active']??0)?'selected':'' ?>>Inactive</option></select></div>
      <div class="bc-field" style="grid-column:1/-1;"><label>Address</label><input type="text" id="bce_ad" value="<?= bch($cu['address']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>City</label><input type="text" id="bce_ci" value="<?= bch($cu['city']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>State</label><input type="text" id="bce_s2" value="<?= bch($cu['state']??'') ?>" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
    </div>
    <div style="margin-top:14px;display:flex;gap:10px;">
      <button onclick="bcAdminSaveEdit(<?= $bcCuid ?>,this)" class="bc-btn primary"> Save Changes</button>
      <a href="?page=dashboard&tab=lte_bluecard&bc=customerdetail&bcuid=<?= $bcCuid ?>" class="bc-btn ghost">Cancel</a>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; // customerdetail tab ?>

<!-- 
     RETAILER / AGENT PROFILE DETAIL
 -->
<?php if ($bcTab === 'retailerdetail' && $bcConfigured):
$bcRuid  = (int)($_GET['bcuid'] ?? 0);
$bcRsub  = $_GET['bcsub'] ?? 'overview';
$bcRpage = max(1,(int)($_GET['bcpg'] ?? 1));

// Sub-tabs definition
$rSubTabs = [
    ['id'=>'overview',    'label'=>'Overview',    'icon'=>''],
    ['id'=>'passbook',    'label'=>'Passbook',    'icon'=>''],
    ['id'=>'loadmoney',   'label'=>'Load Money',  'icon'=>''],
    ['id'=>'planssold',   'label'=>'Plans Sold',  'icon'=>''],
    ['id'=>'customers',   'label'=>'Customers',   'icon'=>''],
    ['id'=>'commissions', 'label'=>'Commissions', 'icon'=>''],
];

$rd = $bcRData ?? [];
$rProfile = $rd;

// Calculate cash owed
$totalLoaded    = (float)($rProfile['total_loaded_cents'] ?? 0) / 100;
$currentWallet  = (float)($rProfile['wallet'] ?? 0);
$cashOwed       = $totalLoaded - $currentWallet;

$rName = trim(($rProfile['firstname']??'').' '.($rProfile['lastname']??''));
?>

<!-- Back link -->
<div style="margin-bottom:14px;">
  <a href="?page=dashboard&tab=lte_bluecard&bc=agents" class="bc-btn ghost" style="font-size:12px;padding:5px 14px;">
     Back to Agents
  </a>
</div>

<?php if (!$bcRuid || empty($rProfile)): ?>
<div class="bc-card"><div class="bc-empty">Retailer not found. <a href="?page=dashboard&tab=lte_bluecard&bc=agents">Go back</a></div></div>
<?php else: ?>

<!--  Header Card  -->
<div class="bc-card" style="margin-bottom:14px;">
  <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <!-- Avatar -->
    <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#7C3AED,#4F46E5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:900;flex-shrink:0;">
      <?= strtoupper(mb_substr($rProfile['firstname']??'?',0,1)) ?>
    </div>
    <!-- Info -->
    <div style="flex:1;min-width:0;">
      <div style="font-size:20px;font-weight:900;color:var(--text);margin-bottom:3px;"><?= bch($rName) ?></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px;">
        <span class="bc-pill <?= in_array($rProfile['role_name']??'',['admin','dealer'],true)?'sold':'assigned' ?>"><?= bch($rProfile['role_display']??$rProfile['role_name']??'Agent') ?></span>
        <?php if (!empty($rProfile['is_active'])): ?>
        <span class="bc-pill active">Active</span>
        <?php else: ?>
        <span class="bc-pill expired">Inactive</span>
        <?php endif; ?>
      </div>
      <div style="font-size:13px;color:var(--text-2);display:flex;gap:16px;flex-wrap:wrap;">
        <?php if (!empty($rProfile['mobile'])): ?><span> <?= bch($rProfile['mobile']) ?></span><?php endif; ?>
        <?php if (!empty($rProfile['email'])): ?><span> <?= bch($rProfile['email']) ?></span><?php endif; ?>
        <?php if (!empty($rProfile['master_name'])): ?><span>Master: <?= bch($rProfile['master_name']) ?></span><?php endif; ?>
        <span>Joined: <?= bcDate($rProfile['created_at']??null) ?></span>
      </div>
    </div>
    <!-- Key stats -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;text-align:center;">
      <div style="background:var(--surface2);border-radius:12px;padding:12px 18px;">
        <div style="font-size:11px;color:var(--text-3);margin-bottom:3px;">Current Wallet</div>
        <div style="font-size:20px;font-weight:900;color:#16A34A;"><?= bcAmtUsd($currentWallet) ?></div>
      </div>
      <div style="background:<?= $cashOwed > 0 ? '#FEF2F2' : 'var(--surface2)' ?>;border-radius:12px;padding:12px 18px;">
        <div style="font-size:11px;color:var(--text-3);margin-bottom:3px;">Cash Owed to BBC</div>
        <div style="font-size:20px;font-weight:900;color:<?= $cashOwed > 0 ? '#DC2626' : '#16A34A' ?>;"><?= bcAmtUsd($cashOwed) ?></div>
      </div>
      <div style="background:var(--surface2);border-radius:12px;padding:12px 18px;">
        <div style="font-size:11px;color:var(--text-3);margin-bottom:3px;">Commission</div>
        <div style="font-size:20px;font-weight:900;color:var(--text);"><?= number_format((float)($rProfile['lm_commission']??0),1) ?>%</div>
      </div>
    </div>
  </div>
</div>

<!--  KPI Row  -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:14px;">
  <?php
  $kpis = [
    ['label'=>'Total Loaded',    'val'=>bcAmtUsd((float)($rProfile['total_loaded_cents']??0)/100),  'color'=>'#1D4ED8'],
    ['label'=>'Plans Sold',      'val'=>bcAmtUsd((float)($rProfile['total_sold_cents']??0)/100),    'color'=>'#7C3AED'],
    ['label'=>'Month Loaded',    'val'=>bcAmtUsd((float)($rProfile['month_loaded_cents']??0)/100),  'color'=>'#0891B2'],
    ['label'=>'Month Sold',      'val'=>bcAmtUsd((float)($rProfile['month_sold_cents']??0)/100),    'color'=>'#059669'],
    ['label'=>'Customers',       'val'=>number_format((int)($rProfile['customer_count']??0)),        'color'=>'#D97706'],
    ['label'=>'Total Renewals',  'val'=>number_format((int)($rProfile['renewal_count']??0)),         'color'=>'#6B7280'],
  ];
  foreach ($kpis as $k): ?>
  <div class="bc-card" style="padding:12px;text-align:center;margin-bottom:0;">
    <div style="font-size:11px;color:var(--text-3);margin-bottom:4px;"><?= $k['label'] ?></div>
    <div style="font-size:16px;font-weight:900;color:<?= $k['color'] ?>;"><?= $k['val'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!--  Sub-tab nav  -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;border-bottom:2px solid var(--border);padding-bottom:8px;">
  <?php foreach ($rSubTabs as $rs):
    $rsActive = $bcRsub === $rs['id'];
    $rsUrl = '?page=dashboard&tab=lte_bluecard&bc=retailerdetail&bcuid='.$bcRuid.'&bcsub='.$rs['id'];
  ?>
  <a href="<?= $rsUrl ?>" style="padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;
     background:<?= $rsActive?'var(--primary)':'var(--surface2)' ?>;
     color:<?= $rsActive?'#fff':'var(--text-2)' ?>;">
    <?= $rs['icon'] ?> <?= $rs['label'] ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- 
     OVERVIEW sub-tab
 -->
<?php if ($bcRsub === 'overview'): ?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Cash Outstanding</div></div>
  <div style="padding:16px;">
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;text-align:center;">
      <div style="background:#EFF6FF;border-radius:12px;padding:14px;">
        <div style="font-size:11px;color:#1D4ED8;font-weight:700;margin-bottom:4px;">TOTAL LOADED INTO WALLET</div>
        <div style="font-size:22px;font-weight:900;color:#1D4ED8;"><?= bcAmtUsd($totalLoaded) ?></div>
        <div style="font-size:11px;color:var(--text-3);">All-time approved load money</div>
      </div>
      <div style="background:#F0FDF4;border-radius:12px;padding:14px;">
        <div style="font-size:11px;color:#16A34A;font-weight:700;margin-bottom:4px;">CURRENT WALLET BALANCE</div>
        <div style="font-size:22px;font-weight:900;color:#16A34A;"><?= bcAmtUsd($currentWallet) ?></div>
        <div style="font-size:11px;color:var(--text-3);">Remaining unspent balance</div>
      </div>
      <div style="background:<?= $cashOwed>0?'#FEF2F2':'#F0FDF4' ?>;border-radius:12px;padding:14px;">
        <div style="font-size:11px;color:<?= $cashOwed>0?'#DC2626':'#16A34A' ?>;font-weight:700;margin-bottom:4px;">CASH OWED TO BBC</div>
        <div style="font-size:22px;font-weight:900;color:<?= $cashOwed>0?'#DC2626':'#16A34A' ?>;"><?= bcAmtUsd(abs($cashOwed)) ?></div>
        <div style="font-size:11px;color:var(--text-3);"><?= $cashOwed>0?'Physical cash collected not yet returned':'Fully settled' ?></div>
      </div>
    </div>
    <div style="background:var(--surface2);border-radius:10px;padding:12px;font-size:12px;color:var(--text-3);line-height:1.8;">
      <strong>How this is calculated:</strong><br>
      Cash Owed = Total Loaded into Wallet  Remaining Wallet Balance<br>
      <?= bcAmtUsd($totalLoaded) ?> loaded  <?= bcAmtUsd($currentWallet) ?> remaining = <strong style="color:<?= $cashOwed>0?'#DC2626':'#16A34A' ?>;"><?= bcAmtUsd($cashOwed) ?></strong>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- 
     PASSBOOK sub-tab
 -->
<?php if ($bcRsub === 'passbook'):
  $pbData  = $bcRData['passbook'] ?? null;
  $pbRows  = $pbData['rows'] ?? [];
  $pbTotal = $pbData['total'] ?? 0;
  $pbPages = $pbData['pages'] ?? 1;
?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Passbook <span class="bc-badge gray"><?= number_format($pbTotal) ?></span></div></div>
  <?php if (empty($pbRows)): ?><div class="bc-empty">No transactions</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Date</th><th>Type</th><th>Amount</th><th>Before</th><th>After</th><th>Description</th></tr></thead>
    <tbody>
    <?php foreach ($pbRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td style="font-size:11px;"><?= bcDatetime($r['created_at']??null) ?></td>
      <td><span class="bc-pill <?= ($r['type']??'')==='Credit'?'active':'expired' ?>"><?= bch($r['type']??'') ?></span></td>
      <td style="font-weight:700;color:<?= ($r['type']??'')==='Credit'?'#16A34A':'#DC2626' ?>;">
        <?= ($r['type']??'')==='Credit'?'+':'-' ?><?= bcAmtUsd($r['amount']??0) ?>
      </td>
      <td class="mono" style="font-size:12px;"><?= bcAmtUsd($r['previous_balance']??0) ?></td>
      <td class="mono" style="font-size:12px;"><?= bcAmtUsd($r['current_balance']??0) ?></td>
      <td style="font-size:12px;color:var(--text-2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= bch($r['description']??'') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  // Custom pager for retailerdetail sub-tabs
  if ($pbPages > 1) {
    $base = '?page=dashboard&tab=lte_bluecard&bc=retailerdetail&bcuid='.$bcRuid.'&bcsub=passbook';
    echo '<div class="bc-pager">';
    if ($bcRpage>1) echo '<a href="'.$base.'&bcpg='.($bcRpage-1).'"></a>';
    for ($i=max(1,$bcRpage-2);$i<=min($pbPages,$bcRpage+2);$i++) echo '<a href="'.$base.'&bcpg='.$i.'"'.($i===$bcRpage?' class="on"':'').'>'.$i.'</a>';
    if ($bcRpage<$pbPages) echo '<a href="'.$base.'&bcpg='.($bcRpage+1).'"></a>';
    echo '<span class="bc-pager-info">'.number_format($pbTotal).' records &middot; page '.$bcRpage.'/'.$pbPages.'</span></div>';
  }
  ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- 
     LOAD MONEY sub-tab
 -->
<?php if ($bcRsub === 'loadmoney'):
  $lmData  = $bcRData['loadmoney'] ?? null;
  $lmRows  = $lmData['rows'] ?? [];
  $lmTotal = $lmData['total'] ?? 0;
  $lmPages = $lmData['pages'] ?? 1;
?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Load Money History <span class="bc-badge gray"><?= number_format($lmTotal) ?></span></div></div>
  <?php if (empty($lmRows)): ?><div class="bc-empty">No load money records</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Date</th><th>Amount</th><th>Approved</th><th>Commission</th><th>Status</th><th>Master</th></tr></thead>
    <tbody>
    <?php foreach ($lmRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td style="font-size:11px;"><?= bcDatetime($r['created_at']??null) ?></td>
      <td style="font-weight:700;"><?= bcAmtUsd($r['amount']??0) ?></td>
      <td style="color:#16A34A;font-weight:700;"><?= !empty($r['approve_amount']) ? bcAmtUsd($r['approve_amount']) : '' ?></td>
      <td style="color:#7C3AED;"><?= !empty($r['commission']) ? bcAmtUsd($r['commission']) : '' ?></td>
      <td><span class="bc-pill <?= strtolower($r['status']??'')==='approve'?'active':(strtolower($r['status']??'')==='rejected'?'expired':'pending') ?>"><?= bch($r['status']??'') ?></span></td>
      <td style="font-size:12px;color:var(--text-2);"><?= bch($r['master_name']??'') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  if ($lmPages > 1) {
    $base = '?page=dashboard&tab=lte_bluecard&bc=retailerdetail&bcuid='.$bcRuid.'&bcsub=loadmoney';
    echo '<div class="bc-pager">';
    if ($bcRpage>1) echo '<a href="'.$base.'&bcpg='.($bcRpage-1).'"></a>';
    for ($i=max(1,$bcRpage-2);$i<=min($lmPages,$bcRpage+2);$i++) echo '<a href="'.$base.'&bcpg='.$i.'"'.($i===$bcRpage?' class="on"':'').'>'.$i.'</a>';
    if ($bcRpage<$lmPages) echo '<a href="'.$base.'&bcpg='.($bcRpage+1).'"></a>';
    echo '<span class="bc-pager-info">'.number_format($lmTotal).' records &middot; page '.$bcRpage.'/'.$lmPages.'</span></div>';
  }
  ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- 
     PLANS SOLD sub-tab
 -->
<?php if ($bcRsub === 'planssold'):
  $psData  = $bcRData['planssold'] ?? null;
  $psRows  = $psData['rows'] ?? [];
  $psTotal = $psData['total'] ?? 0;
  $psPages = $psData['pages'] ?? 1;
?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Plans Sold <span class="bc-badge gray"><?= number_format($psTotal) ?></span></div></div>
  <?php if (empty($psRows)): ?><div class="bc-empty">No plans sold</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Date</th><th>Customer</th><th>MSISDN</th><th>Plan</th><th>Amount</th><th>Expiry</th></tr></thead>
    <tbody>
    <?php foreach ($psRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td style="font-size:11px;"><?= bcDatetime($r['created_at']??null) ?></td>
      <td style="font-weight:600;">
        <?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?>
        <div style="font-size:11px;color:var(--text-3);"><?= bch($r['customer_mobile']??'') ?></div>
      </td>
      <td class="mono" style="font-size:12px;"><?= bch($r['msisdn']??'') ?></td>
      <td style="font-size:12px;font-weight:600;"><?= bch($r['plan_name']??'') ?></td>
      <td style="font-weight:700;color:#7C3AED;"><?= bcAmtCents($r['amount']??null) ?></td>
      <td style="font-size:11px;"><?= bcDate($r['end_date']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  if ($psPages > 1) {
    $base = '?page=dashboard&tab=lte_bluecard&bc=retailerdetail&bcuid='.$bcRuid.'&bcsub=planssold';
    echo '<div class="bc-pager">';
    if ($bcRpage>1) echo '<a href="'.$base.'&bcpg='.($bcRpage-1).'"></a>';
    for ($i=max(1,$bcRpage-2);$i<=min($psPages,$bcRpage+2);$i++) echo '<a href="'.$base.'&bcpg='.$i.'"'.($i===$bcRpage?' class="on"':'').'>'.$i.'</a>';
    if ($bcRpage<$psPages) echo '<a href="'.$base.'&bcpg='.($bcRpage+1).'"></a>';
    echo '<span class="bc-pager-info">'.number_format($psTotal).' records &middot; page '.$bcRpage.'/'.$psPages.'</span></div>';
  }
  ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- 
     CUSTOMERS sub-tab
 -->
<?php if ($bcRsub === 'customers'):
  $custData  = $bcRData['customers'] ?? null;
  $custRows  = $custData['rows'] ?? [];
  $custTotal = $custData['total'] ?? 0;
  $custPages = $custData['pages'] ?? 1;
?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Customers Under This Retailer <span class="bc-badge gray"><?= number_format($custTotal) ?></span></div></div>
  <?php if (empty($custRows)): ?><div class="bc-empty">No customers registered under this retailer</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Name</th><th>Mobile</th><th>Plan</th><th>Expiry</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($custRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td style="font-weight:600;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></td>
      <td class="mono"><?= bch($r['mobile']??'') ?></td>
      <td style="font-size:12px;"><?= bch($r['plan_name']??'') ?></td>
      <td style="font-size:11px;"><?= bcDate($r['plan_end']??null) ?></td>
      <td>
        <?php
        $isExp = !empty($r['plan_end']) && strtotime($r['plan_end']) < time();
        $isDeact = !empty($r['is_deactive']);
        $cls = $isDeact ? 'expired' : ($isExp ? 'pending' : 'active');
        $lbl = $isDeact ? 'Deactive' : ($isExp ? 'Expired' : 'Active');
        ?>
        <span class="bc-pill <?= $cls ?>"><?= $lbl ?></span>
      </td>
      <td>
        <a href="?page=dashboard&tab=lte_bluecard&bc=customerdetail&bcuid=<?= (int)$r['id'] ?>" class="bc-btn ghost" style="font-size:11px;padding:3px 9px;"> View</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  if ($custPages > 1) {
    $base = '?page=dashboard&tab=lte_bluecard&bc=retailerdetail&bcuid='.$bcRuid.'&bcsub=customers';
    echo '<div class="bc-pager">';
    if ($bcRpage>1) echo '<a href="'.$base.'&bcpg='.($bcRpage-1).'"></a>';
    for ($i=max(1,$bcRpage-2);$i<=min($custPages,$bcRpage+2);$i++) echo '<a href="'.$base.'&bcpg='.$i.'"'.($i===$bcRpage?' class="on"':'').'>'.$i.'</a>';
    if ($bcRpage<$custPages) echo '<a href="'.$base.'&bcpg='.($bcRpage+1).'"></a>';
    echo '<span class="bc-pager-info">'.number_format($custTotal).' records &middot; page '.$bcRpage.'/'.$custPages.'</span></div>';
  }
  ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- 
     COMMISSIONS sub-tab
 -->
<?php if ($bcRsub === 'commissions'):
  $comData  = $bcRData['commissions'] ?? null;
  $comRows  = $comData['rows'] ?? [];
  $comTotal = $comData['total'] ?? 0;
  $comPages = $comData['pages'] ?? 1;
?>
<div class="bc-card">
  <div class="bc-card-hd"><div class="bc-card-hd-t"> Commissions <span class="bc-badge gray"><?= number_format($comTotal) ?></span></div></div>
  <?php if (empty($comRows)): ?><div class="bc-empty">No commission records</div>
  <?php else: ?>
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Date</th><th>Amount</th><th>Request ID</th></tr></thead>
    <tbody>
    <?php foreach ($comRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td style="font-size:11px;"><?= bcDatetime($r['created_at']??null) ?></td>
      <td style="font-weight:700;color:#7C3AED;"><?= bcAmtUsd($r['amount']??0) ?></td>
      <td class="mono" style="font-size:12px;color:var(--text-3);"><?= bch($r['request_id']??'') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  if ($comPages > 1) {
    $base = '?page=dashboard&tab=lte_bluecard&bc=retailerdetail&bcuid='.$bcRuid.'&bcsub=commissions';
    echo '<div class="bc-pager">';
    if ($bcRpage>1) echo '<a href="'.$base.'&bcpg='.($bcRpage-1).'"></a>';
    for ($i=max(1,$bcRpage-2);$i<=min($comPages,$bcRpage+2);$i++) echo '<a href="'.$base.'&bcpg='.$i.'"'.($i===$bcRpage?' class="on"':'').'>'.$i.'</a>';
    if ($bcRpage<$comPages) echo '<a href="'.$base.'&bcpg='.($bcRpage+1).'"></a>';
    echo '<span class="bc-pager-info">'.number_format($comTotal).' records &middot; page '.$bcRpage.'/'.$comPages.'</span></div>';
  }
  ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; // !empty($rProfile) ?>
<?php endif; // retailerdetail tab ?>

<!--  KYC RECORDS (local backup)  -->
<?php if ($bcTab === 'kycrecords' && $isAdmin):
// Read from local SQLite
$kycPdo  = $store->getPdo();
// Auto-create bc_kyc_records table if it doesn't exist yet
try {
    $kycPdo->exec("CREATE TABLE IF NOT EXISTS bc_kyc_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        firstname TEXT DEFAULT '',
        lastname TEXT DEFAULT '',
        mobile TEXT DEFAULT '',
        msisdn TEXT DEFAULT '',
        email TEXT DEFAULT '',
        address TEXT DEFAULT '',
        id_type TEXT DEFAULT '',
        id_number TEXT DEFAULT '',
        plan_id INTEGER DEFAULT 0,
        plan_name TEXT DEFAULT '',
        sim_id INTEGER DEFAULT 0,
        agent_id INTEGER DEFAULT 0,
        agent_name TEXT DEFAULT '',
        notes TEXT DEFAULT '',
        status TEXT DEFAULT 'pending',
        synced_to_bluecard INTEGER DEFAULT 0,
        created_at TEXT DEFAULT (datetime('now')),
        updated_at TEXT DEFAULT (datetime('now')),
        deleted_at TEXT DEFAULT NULL
    )");
} catch (\Throwable $e) {}
$kycPage = max(1, $bcPage);
$kycQ    = trim($_GET['bcq'] ?? '');
$kycPer  = 50;
$kycWhere = "deleted_at IS NULL";
$kycParams = [];
if ($kycQ !== '') {
    $kycWhere .= " AND (firstname LIKE :q OR lastname LIKE :q OR mobile LIKE :q OR msisdn LIKE :q OR email LIKE :q)";
    $kycParams[':q'] = "%$kycQ%";
}
$kycTotal = 0; $kycRows = [];
try {
    $kycStCount = $kycPdo->prepare("SELECT COUNT(*) FROM bc_kyc_records WHERE $kycWhere");
    $kycStCount->execute($kycParams); $kycTotal = (int)$kycStCount->fetchColumn();
    $kycPages = max(1, (int)ceil($kycTotal / $kycPer));
    $kycPage  = min($kycPage, $kycPages);
    $kycOffset = ($kycPage - 1) * $kycPer;
    $kycSt = $kycPdo->prepare("SELECT * FROM bc_kyc_records WHERE $kycWhere ORDER BY id DESC LIMIT $kycPer OFFSET $kycOffset");
    $kycSt->execute($kycParams);
    $kycRows = $kycSt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $kycRows = []; }
?>
<form method="GET" style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="lte_bluecard"><input type="hidden" name="bc" value="kycrecords">
  <input type="text" name="bcq" value="<?= bch($kycQ) ?>" placeholder="Search name, MSISDN, mobile, email" style="flex:1;min-width:200px;border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;">
  <button type="submit" class="bc-btn primary"> Search</button>
  <?php if ($kycQ): ?><a href="?page=dashboard&tab=lte_bluecard&bc=kycrecords" class="bc-btn ghost"> Clear</a><?php endif; ?>
</form>
<div class="bc-card">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> KYC Records (Local Backup) <span class="bc-badge gray"><?= number_format($kycTotal) ?></span></div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <a href="?page=api&action=bc_kyc_export&fmt=json" class="bc-btn primary" style="font-size:12px;padding:6px 14px;" download> JSON</a>
      <a href="?page=api&action=bc_kyc_export&fmt=csv"  class="bc-btn ghost"   style="font-size:12px;padding:6px 14px;" download> CSV</a>
      <button onclick="document.getElementById('kycImportFile').click()" class="bc-btn ghost" style="font-size:12px;padding:6px 14px;"> Restore</button>
      <input type="file" id="kycImportFile" accept=".json" style="display:none;" onchange="kycDoImport(this)">
      <span id="kycImportMsg" style="font-size:12px;"></span>
    </div>
  </div>
  <?php if (empty($kycRows)): ?>
  <div class="bc-empty">No KYC records in local backup yet. Records are saved automatically after each successful registration.</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="bc-tbl">
    <thead><tr><th>ID</th><th>Customer</th><th>MSISDN</th><th>Plan</th><th>Price</th><th>Agent</th><th>Docs</th><th>BC IDs</th><th>Registered</th></tr></thead>
    <tbody>
    <?php foreach ($kycRows as $r): ?>
    <tr>
      <td class="mono" style="color:var(--text-3);"><?= (int)$r['id'] ?></td>
      <td>
        <div style="font-weight:700;"><?= bch(trim(($r['firstname']??'').' '.($r['lastname']??''))) ?></div>
        <div style="font-size:11px;color:var(--text-3);"><?= bch($r['email']??'') ?></div>
        <?php if (!empty($r['aadhar_card_no'])): ?><div style="font-size:10px;color:var(--text-3);">ID: <?= bch($r['aadhar_card_no']) ?></div><?php endif; ?>
      </td>
      <td class="mono" style="font-weight:600;"><?= bch($r['msisdn']??$r['mobile']??'') ?></td>
      <td style="font-size:12px;"><?= bch($r['plan_name']??'') ?></td>
      <td><?= !empty($r['plan_price']) ? bcAmtUsd($r['plan_price']) : '' ?></td>
      <td style="font-size:12px;color:var(--text-3);"><?= bch($r['retailer_name']??'') ?></td>
      <td>
        <?php
        $docCount = 0;
        foreach (['customer_img','aadhar_card_front_img','aadhar_card_back_img','pan_card_img'] as $dc)
            if (!empty($r[$dc])) $docCount++;
        if ($docCount > 0): ?>
        <span style="color:#16A34A;font-size:12px;"> <?= $docCount ?> docs</span>
        <?php else: ?><span style="color:var(--text-3);font-size:11px;">None</span><?php endif; ?>
      </td>
      <td style="font-size:10px;color:var(--text-3);" class="mono">
        <?php if (!empty($r['bc_user_id'])): ?>U:<?= $r['bc_user_id'] ?><?php endif; ?>
        <?php if (!empty($r['bc_service_id'])): ?> S:<?= $r['bc_service_id'] ?><?php endif; ?>
      </td>
      <td style="font-size:11px;color:var(--text-3);"><?= bcDate($r['created_at']??null) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= bcPager($kycPage, $kycTotal, $kycPages, 'kycrecords', $kycQ, '') ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<!--  SETTINGS  -->
<?php if ($bcTab === 'settings' && $isAdmin): ?>
<div class="bc-card" style="max-width:560px;">
  <div class="bc-card-hd">
    <div class="bc-card-hd-t"> BlueCard Database Connection</div>
  </div>
  <div style="padding:20px;">
    <?php
    $bcFeedUrl = $config['lte_feed_url'] ?? 'http://162.241.149.144/lte_feed.php';
    ?>
    <div id="bcDbMsg"></div>
    <?php if ($bcConfigured): ?>
    <div class="kyc-alert success" style="margin-bottom:14px;"> lte_feed.php bridge is reachable  BlueCard DB connected.</div>
    <?php else: ?>
    <div class="kyc-alert error" style="margin-bottom:14px;"> Cannot reach bridge. Check URL below and ensure bc_query patch is applied.</div>
    <?php endif; ?>
    <div class="bc-field" style="margin-top:12px;">
      <label>Feed URL</label>
      <input type="text" id="bc_feed_url" value="<?= bch($bcFeedUrl) ?>" placeholder="http://162.241.149.144/lte_feed.php" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
    </div>
    <div class="bc-field">
      <label>Feed Token</label>
      <input type="text" id="bc_feed_token" value="<?= bch($config['lte_feed_token'] ?? 'dN4g-LtEfEeD-2026-sEcReT') ?>" placeholder="dN4g-LtEfEeD-2026-sEcReT" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
      <button onclick="bcSave(this)" class="bc-btn primary"> Save URL</button>
      <button onclick="bcTest(this)" class="bc-btn ghost"> Test Connection</button>
      <button onclick="bcForceSync(this)" class="bc-btn ghost" style="color:#D97706;"> Force Full Re-Sync</button>
      <button onclick="bcLocalCounts(this)" class="bc-btn ghost" style="color:#1D4ED8;"> Check Local Tables</button>
      <button onclick="bcDiagnose(this)" class="bc-btn ghost" style="color:#7C3AED;"> Diagnose</button>
    </div>
    <div id="bcLocalCountsResult" style="margin-top:12px;"></div>
    <script>
    function bcMsg(html, type) {
      var el = document.getElementById('bcDbMsg');
      el.innerHTML = '<div class="kyc-alert '+type+'" style="margin-top:12px;">'+html+'</div>';
    }
    function bcDiagnose(btn) {
      btn.disabled=true; btn.textContent='Diagnosing...';
      var TK = document.querySelector('meta[name="api-token"]')?.content || '';
      fetch('?page=api&action=lte_sync_run',{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+TK},body:'{}'})
        .then(r=>r.json()).then(function(d){
          btn.disabled=false; btn.textContent=' Diagnose';
          var ok = d.status==='success';
          var data = d.data || {};
          var html='<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:11px;font-family:monospace;overflow:auto;max-height:300px;">';
          html+='<b>Ping:</b> HTTP '+data.ping?.code+' | '+data.ping?.body+'<br>';
          html+='<b>Users endpoint:</b> ok='+data.users_test?.ok+' count='+data.users_test?.count+' error='+data.users_test?.error+'<br>';
          html+='<b>Agents endpoint:</b> ok='+data.agents_test?.ok+' count='+data.agents_test?.count+' error='+data.agents_test?.error+'<br>';
          html+='<b>Sync enabled:</b> '+data.sync_enabled+'<br>';
          html+='<b>Cron registered:</b> '+data.cron_registered+'<br>';
          html+='<b>State:</b> '+JSON.stringify(data.state||{})+'<br>';
          html+='<b>Data dir:</b> '+data.data_dir+'<br>';
          html+='</div>';
          document.getElementById('bcLocalCountsResult').innerHTML=html;
        }).catch(function(e){btn.disabled=false;btn.textContent=' Diagnose';});
    }
    function bcForceSync(btn) {
      if (!confirm('This will clear sync cursors so the next cron run (every 5 min) does a full re-sync. Continue?')) return;
      btn.disabled = true; btn.textContent = 'Resetting...';
      var TK = document.querySelector('meta[name="api-token"]')?.content || '';
      fetch('?page=api&action=lte_sync_force', {method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer '+TK},body:'{}'})
        .then(r=>r.json()).then(function(d) {
          btn.disabled=false; btn.textContent=' Force Full Re-Sync';
          var ok = d.status==='success';
          bcMsg(ok ? ' Cursors cleared! Wait 5 minutes for cron to sync data, then click Check Local Tables.' : ' Error: '+(d.message||'unknown'), ok?'success':'error');
        }).catch(function(e){btn.disabled=false;btn.textContent=' Force Full Re-Sync';bcMsg(' Error: '+e,'error');});
    }
    function bcLocalCounts(btn) {
      btn.disabled=true; btn.textContent='Checking...';
      var TK = document.querySelector('meta[name="api-token"]')?.content || '';
      fetch('?page=api&action=lte_local_counts',{headers:{'Authorization':'Bearer '+TK}})
        .then(r=>r.json()).then(d=>{
          btn.disabled=false; btn.textContent=' Check Local Tables';
          var ok = d.status==='success' || d.ok;
          if (!ok) { bcMsg(' Error: '+(d.message||d.error||'unknown'),'error'); return; }
          var data = d.data || d;
          var c=data.counts||{}; var html='<div style="background:#f8fafc;border-radius:10px;padding:12px;font-size:12px;font-family:monospace;">';
          html+='<div style="font-weight:700;margin-bottom:8px;color:var(--text);"> Local SQLite Table Counts</div>';
          for(var t in c){
            var v=c[t]; var ok=(typeof v==='number'&&v>0);
            html+='<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border);"><span>'+t+'</span><span style="font-weight:700;color:'+(v==='missing'?'#DC2626':ok?'#16A34A':'#D97706')+';">'+v+'</span></div>';
          }
          html+='<div style="margin-top:8px;color:var(--text-3);">Feed connected: '+(data.feed_connected?'Yes':'No')+' | Last sync: '+(data.last_sync||'never')+'</div>';
          html+='</div>';
          document.getElementById('bcLocalCountsResult').innerHTML=html;
        }).catch(e=>{btn.disabled=false;btn.textContent=' Check Local Tables';});
    }
    function bcSave(btn) {
      var url   = document.getElementById('bc_feed_url').value.trim();
      var token = document.getElementById('bc_feed_token').value.trim();
      if (!url) { bcMsg('Feed URL is required.','error'); return; }
      btn.disabled = true; btn.textContent = '\u23f3 Saving\u2026';
      var TK = document.querySelector('meta[name="api-token"]')?.content || '';
      fetch('?page=api&action=bc_save_config', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer '+TK},
        body: JSON.stringify({feed_url: url, feed_token: token})
      })
      .then(function(r){ return r.json(); })
      .then(function(d) {
        btn.disabled = false; btn.textContent = '\ud83d\udcbe Save URL';
        if ((d.data||d).saved) { bcMsg('\u2705 URL saved. Testing\u2026','success'); setTimeout(function(){ location.reload(); }, 900); }
        else { bcMsg('\u274c ' + (d.message||'Save failed'),'error'); }
      })
      .catch(function(e){ btn.disabled=false; btn.textContent='\ud83d\udcbe Save URL'; bcMsg('\u274c '+e,'error'); });
    }
    function bcTest(btn) {
      var url   = (document.getElementById('bc_feed_url')||{value:''}).value.trim();
      var token = (document.getElementById('bc_feed_token')||{value:''}).value.trim();
      btn.disabled = true; btn.textContent = '\u23f3 Testing\u2026';
      var TK = document.querySelector('meta[name="api-token"]')?.content || '';
      fetch('?page=api&action=bc_test_config', {
        method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer '+TK},
        body: JSON.stringify({feed_url: url, feed_token: token})
      })
      .then(function(r){ return r.json(); })
      .then(function(d) {
        btn.disabled = false; btn.textContent = '\ud83d\udd0c Test Connection';
        var r = d.data || d;
        if (r.ok) { bcMsg('\u2705 Connected! MySQL ' + r.version + ' \u00b7 ' + r.db,'success'); setTimeout(function(){ location.reload(); }, 1200); }
        else { bcMsg('\u274c ' + (r.error || 'Failed \u2014 apply bc_query patch to lte_feed.php on WHM'),'error'); }
      })
      .catch(function(e){ btn.disabled=false; btn.textContent='\ud83d\udd0c Test Connection'; bcMsg('\u274c '+e,'error'); });
    }
    </script>
  </div>
</div>
<?php endif; ?>


<!--  LOAD MONEY MODALS  -->
<div id="bcLmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:18px;padding:24px;width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
      <div style="font-size:16px;font-weight:800;" id="bcLmModalTitle">Add Load Money</div>
      <button onclick="bcLmClose()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748B;"></button>
    </div>
    <div id="bcLmModalMsg"></div>
    <!-- ADD form -->
    <div id="bcLmAddForm">
      <div class="bc-field">
        <label>Search Agent / Dealer</label>
        <input type="text" id="bcLmAgentSearch" placeholder="Type name or mobile" autocomplete="off"
          style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"
          oninput="bcLmAgentLookup(this.value)">
        <div id="bcLmAgentDropdown" style="display:none;border:1.5px solid var(--border);border-radius:10px;margin-top:4px;max-height:200px;overflow-y:auto;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.1);"></div>
        <input type="hidden" id="bcLmUserId">
      </div>
      <div id="bcLmAgentCard" style="display:none;background:#F8FAFC;border:1.5px solid var(--border);border-radius:12px;padding:12px 14px;margin-bottom:12px;">
        <div style="font-size:13px;font-weight:700;" id="bcLmAgentName"></div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;" id="bcLmAgentMeta"></div>
        <div style="display:flex;gap:16px;margin-top:8px;font-size:12px;">
          <span> Wallet: <strong id="bcLmAgentWallet"></strong></span>
          <span> LM Comm: <strong id="bcLmAgentComm"></strong></span>
          <span> Master: <strong id="bcLmAgentMaster"></strong></span>
        </div>
      </div>
      <div class="bc-field"><label>Amount (USD)</label><input type="number" id="bcLmAmount" placeholder="0" min="1" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Type</label>
        <select id="bcLmType" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
          <option value="Load Money">Load Money</option>
          <option value="Credit Money">Credit Money</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button onclick="bcLmDoCreate(this)" class="bc-btn primary" style="flex:1;"> Send Request</button>
        <button onclick="bcLmClose()" class="bc-btn ghost">Cancel</button>
      </div>
    </div>
    <!-- STATUS form -->
    <div id="bcLmStatusForm" style="display:none;">
      <input type="hidden" id="bcLmStatusId">
      <div class="bc-field"><label>Requested Amount</label><div id="bcLmStatusAmtDisplay" style="font-size:18px;font-weight:800;padding:8px 0;"></div></div>
      <div class="bc-field"><label>Approve Amount</label><input type="number" id="bcLmApproveAmt" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div id="bcLmApproveNote" style="display:none;background:#FEF3C7;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;margin-bottom:12px;">
         On Approve: master user wallet will be <strong>debited</strong>, agent wallet will be <strong>credited</strong>, passbook entries created.
      </div>
      <div class="bc-field"><label>Status</label>
        <select id="bcLmNewStatus" onchange="document.getElementById('bcLmApproveNote').style.display=this.value==='Approve'?'':'none'" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
          <option value="Pending">Pending</option>
          <option value="Approve">Approve </option>
          <option value="Rejected">Rejected </option>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <button onclick="bcLmDoStatus(this)" class="bc-btn primary" style="flex:1;"> Update Status</button>
        <button onclick="bcLmClose()" class="bc-btn ghost">Cancel</button>
      </div>
    </div>
  </div>
</div>

<!--  SIM CARD MODAL  -->
<div id="bcSimModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:18px;padding:24px;width:100%;max-width:540px;box-shadow:0 20px 60px rgba(0,0,0,.25);max-height:90vh;overflow-y:auto;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
      <div style="font-size:16px;font-weight:800;" id="bcSimModalTitle">Add SIM Card</div>
      <button onclick="bcSimClose()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748B;"></button>
    </div>
    <div id="bcSimModalMsg"></div>
    <input type="hidden" id="bcSimId">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div class="bc-field" style="grid-column:1/-1;"><label>IMSI *</label><input type="text" id="bcSimImsi" placeholder="460..." style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field" style="grid-column:1/-1;"><label>MSISDN *</label><input type="text" id="bcSimMsisdn" placeholder="211..." style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Auth Key</label><input type="text" id="bcSimAuthKey" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>OPC Value</label><input type="text" id="bcSimOpc" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>SIM Type</label><input type="text" id="bcSimType" placeholder="e.g. nano" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Algo</label><input type="text" id="bcSimAlgo" placeholder="e.g. MILENAGE" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Partner</label><input type="text" id="bcSimPartner" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field"><label>Price (USD)</label><input type="number" id="bcSimPrice" value="0" min="0" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;"></div>
      <div class="bc-field" id="bcSimStatusRow" style="display:none;grid-column:1/-1;"><label>Status</label>
        <select id="bcSimStatus" style="width:100%;border:1.5px solid var(--border);border-radius:10px;padding:9px 13px;font-size:13px;font-family:inherit;outline:none;">
          <option value="In stock">In stock</option>
          <option value="Internal usage">Internal usage</option>
          <option value="Sold">Sold</option>
          <option value="Rent">Rent</option>
          <option value="Returned">Returned</option>
          <option value="Assigned">Assigned</option>
        </select>
      </div>
    </div>
    <div style="display:flex;gap:10px;margin-top:14px;">
      <button onclick="bcSimSave(this)" class="bc-btn primary" style="flex:1;" id="bcSimSaveBtn"> Add SIM</button>
      <button onclick="bcSimClose()" class="bc-btn ghost">Cancel</button>
    </div>
  </div>
</div>

<script>
//  Shared helpers 
//  BlueCard API helper  routes through PHP proxy to avoid mixed-content (HTTPSHTTP) 
var _bcProxy = '?page=api&action=bc_proxy';
function bcPost(table, body, cb) {
    fetch(_bcProxy + '&table=' + encodeURIComponent(table), {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body)
    }).then(function(r){ return r.json(); }).then(cb)
    .catch(function(e){ cb({ok:false,error:''+e}); });
}
function bcGet(table, params, cb) {
    var qs = _bcProxy + '&table=' + encodeURIComponent(table);
    Object.keys(params).forEach(function(k){ qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); });
    fetch(qs).then(function(r){ return r.json(); }).then(cb)
    .catch(function(e){ cb({ok:false,error:''+e}); });
}
function bcAlert(el, msg, type) {
    el.innerHTML = '<div class="kyc-alert '+type+'" style="margin-bottom:12px;">'+msg+'</div>';
}

//  Load Money 
var _bcLmMode = 'add';
function bcLmClose() {
    document.getElementById('bcLmModal').style.display='none';
    document.getElementById('bcLmModalMsg').innerHTML='';
}
var _bcLmSearchTimer = null;
function bcLmAdd() {
    _bcLmMode='add';
    document.getElementById('bcLmModalTitle').textContent='Send Load Money';
    document.getElementById('bcLmAddForm').style.display='';
    document.getElementById('bcLmStatusForm').style.display='none';
    document.getElementById('bcLmUserId').value='';
    document.getElementById('bcLmAgentSearch').value='';
    document.getElementById('bcLmAgentDropdown').style.display='none';
    document.getElementById('bcLmAgentCard').style.display='none';
    document.getElementById('bcLmAmount').value='';
    document.getElementById('bcLmModalMsg').innerHTML='';
    document.getElementById('bcLmModal').style.display='flex';
    setTimeout(function(){ document.getElementById('bcLmAgentSearch').focus(); }, 100);
}
function bcLmAgentLookup(val) {
    var dd = document.getElementById('bcLmAgentDropdown');
    document.getElementById('bcLmUserId').value='';
    document.getElementById('bcLmAgentCard').style.display='none';
    clearTimeout(_bcLmSearchTimer);
    if (val.length < 2) { dd.style.display='none'; return; }
    _bcLmSearchTimer = setTimeout(function() {
        bcGet('bc_agents_search', {q: val}, function(d) {
            dd.innerHTML='';
            if (!d.ok || !d.data || !d.data.length) {
                dd.innerHTML='<div style="padding:12px;font-size:12px;color:var(--text-3);">No agents found</div>';
                dd.style.display='';
                return;
            }
            d.data.forEach(function(a) {
                var el = document.createElement('div');
                el.style.cssText='padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;';
                el.onmouseover=function(){ this.style.background='#EFF6FF'; };
                el.onmouseout=function(){ this.style.background=''; };
                el.innerHTML='<div style="font-weight:700;">'+a.firstname+' '+a.lastname+
                    ' <span style="font-size:11px;color:#1D4ED8;background:#EFF6FF;padding:1px 7px;border-radius:10px;">'+a.role_display+'</span></div>'+
                    '<div style="font-size:11px;color:var(--text-3);">'+a.mobile+'  Wallet: $'+parseFloat(a.wallet||0).toFixed(2)+'</div>';
                el.onclick = function() { bcLmAgentSelect(a); };
                dd.appendChild(el);
            });
            dd.style.display='';
        });
    }, 350);
}
function bcLmAgentSelect(a) {
    document.getElementById('bcLmUserId').value = a.id;
    document.getElementById('bcLmAgentSearch').value = a.firstname+' '+a.lastname;
    document.getElementById('bcLmAgentDropdown').style.display='none';
    // Show agent info card
    document.getElementById('bcLmAgentName').textContent = a.firstname+' '+a.lastname;
    document.getElementById('bcLmAgentMeta').textContent = '#'+a.id+'  '+a.mobile+'  '+a.role_display;
    document.getElementById('bcLmAgentWallet').textContent = '$'+parseFloat(a.wallet||0).toFixed(2);
    document.getElementById('bcLmAgentComm').textContent = parseFloat(a.lm_commission||0).toFixed(1)+'%';
    document.getElementById('bcLmAgentMaster').textContent = a.master_name||'Admin';
    document.getElementById('bcLmAgentCard').style.display='';
    document.getElementById('bcLmAmount').focus();
}
function bcLmStatus(id, currentStatus, amount) {
    _bcLmMode='status';
    document.getElementById('bcLmModalTitle').textContent='Update Load Money #'+id;
    document.getElementById('bcLmAddForm').style.display='none';
    document.getElementById('bcLmStatusForm').style.display='';
    document.getElementById('bcLmStatusId').value=id;
    document.getElementById('bcLmStatusAmtDisplay').textContent='$'+amount.toLocaleString();
    document.getElementById('bcLmApproveAmt').value=amount;
    document.getElementById('bcLmNewStatus').value=currentStatus;
    document.getElementById('bcLmApproveNote').style.display=currentStatus==='Pending'?'':'none';
    document.getElementById('bcLmModalMsg').innerHTML='';
    document.getElementById('bcLmModal').style.display='flex';
}
function bcLmDoCreate(btn) {
    var userId=document.getElementById('bcLmUserId').value.trim();
    var amount=document.getElementById('bcLmAmount').value.trim();
    var type=document.getElementById('bcLmType').value;
    var msg=document.getElementById('bcLmModalMsg');
    if (!userId){bcAlert(msg,' Please search and select an agent first','error');return;}
    if (!amount||parseInt(amount)<=0){bcAlert(msg,' Enter a valid amount','error');return;}
    btn.disabled=true;btn.textContent=' Saving';
    bcPost('bc_lm_create',{user_id:parseInt(userId),amount:parseInt(amount),type:type},function(d){
        btn.disabled=false;btn.textContent=' Create';
        if(d.ok){bcAlert(msg,' Load money request created (commission: $'+d.commission+')','success');setTimeout(function(){bcLmClose();location.reload();},1200);}
        else{bcAlert(msg,' '+(d.error||'Failed'),'error');}
    });
}
function bcLmDoStatus(btn) {
    var id=parseInt(document.getElementById('bcLmStatusId').value);
    var status=document.getElementById('bcLmNewStatus').value;
    var approveAmt=parseInt(document.getElementById('bcLmApproveAmt').value)||null;
    var msg=document.getElementById('bcLmModalMsg');
    btn.disabled=true;btn.textContent=' Updating';
    bcPost('bc_lm_status',{id:id,status:status,approve_amount:approveAmt},function(d){
        btn.disabled=false;btn.textContent=' Update Status';
        if(d.ok){bcAlert(msg,' Status updated to '+status,'success');setTimeout(function(){bcLmClose();location.reload();},900);}
        else{bcAlert(msg,' '+(d.error||'Failed'),'error');}
    });
}
function bcLmDel(id) {
    if(!confirm('Delete Load Money #'+id+'? This cannot be undone.')) return;
    bcPost('bc_lm_delete',{id:id},function(d){
        if(d.ok){location.reload();}
        else{alert(' '+(d.error||'Delete failed'));}
    });
}

//  SIM Cards 
var _bcSimMode='add';
function bcSimClose(){document.getElementById('bcSimModal').style.display='none';}
function _bcSimClear(){
    ['bcSimImsi','bcSimMsisdn','bcSimAuthKey','bcSimOpc','bcSimType','bcSimAlgo','bcSimPartner'].forEach(function(i){document.getElementById(i).value='';});
    document.getElementById('bcSimPrice').value='0';
    document.getElementById('bcSimModalMsg').innerHTML='';
}
function bcSimAdd(){
    _bcSimMode='add';
    document.getElementById('bcSimModalTitle').textContent='Add SIM Card';
    document.getElementById('bcSimSaveBtn').textContent=' Add SIM';
    document.getElementById('bcSimId').value='';
    document.getElementById('bcSimStatusRow').style.display='none';
    _bcSimClear();
    document.getElementById('bcSimModal').style.display='flex';
}
function bcSimEdit(id){
    _bcSimMode='edit';
    document.getElementById('bcSimModalTitle').textContent='Edit SIM Card #'+id;
    document.getElementById('bcSimSaveBtn').textContent=' Save Changes';
    document.getElementById('bcSimId').value=id;
    document.getElementById('bcSimStatusRow').style.display='';
    document.getElementById('bcSimModalMsg').innerHTML='<div style="padding:8px;color:var(--text-3);font-size:12px;"> Loading</div>';
    document.getElementById('bcSimModal').style.display='flex';
    bcGet('bc_sim_get',{id:id},function(d){
        document.getElementById('bcSimModalMsg').innerHTML='';
        if(!d.ok||!d.data){bcAlert(document.getElementById('bcSimModalMsg'),' Could not load record','error');return;}
        var r=d.data;
        document.getElementById('bcSimImsi').value=r.imsi||'';
        document.getElementById('bcSimMsisdn').value=r.msisdn||'';
        document.getElementById('bcSimAuthKey').value=r.auth_key||'';
        document.getElementById('bcSimOpc').value=r.opc_value||'';
        document.getElementById('bcSimType').value=r.sim_type||'';
        document.getElementById('bcSimAlgo').value=r.algo||'';
        document.getElementById('bcSimPartner').value=r.partner||'';
        document.getElementById('bcSimPrice').value=r.price||0;
        document.getElementById('bcSimStatus').value=r.status||'In stock';
    });
}
function bcSimSave(btn){
    var msg=document.getElementById('bcSimModalMsg');
    var imsi=document.getElementById('bcSimImsi').value.trim();
    var msisdn=document.getElementById('bcSimMsisdn').value.trim();
    if(!imsi||!msisdn){bcAlert(msg,' IMSI and MSISDN are required','error');return;}
    btn.disabled=true;btn.textContent=' Saving';
    var payload={
        imsi:imsi,msisdn:msisdn,
        auth_key:document.getElementById('bcSimAuthKey').value.trim(),
        opc_value:document.getElementById('bcSimOpc').value.trim(),
        sim_type:document.getElementById('bcSimType').value.trim(),
        algo:document.getElementById('bcSimAlgo').value.trim(),
        partner:document.getElementById('bcSimPartner').value.trim(),
        price:parseInt(document.getElementById('bcSimPrice').value)||0
    };
    var endpoint='bc_sim_create';
    if(_bcSimMode==='edit'){
        payload.id=parseInt(document.getElementById('bcSimId').value);
        payload.status=document.getElementById('bcSimStatus').value;
        endpoint='bc_sim_update';
    }
    bcPost(endpoint,payload,function(d){
        btn.disabled=false;btn.textContent=(_bcSimMode==='add'?' Add SIM':' Save Changes');
        if(d.ok){bcAlert(msg,' SIM card saved!','success');setTimeout(function(){bcSimClose();location.reload();},900);}
        else{bcAlert(msg,' '+(d.error||'Failed'),'error');}
    });
}
function bcSimDel(id,msisdn){
    if(!confirm('Delete SIM '+msisdn+' (#'+id+')? This will soft-delete and set status to Returned.')) return;
    bcPost('bc_sim_delete',{id:id},function(d){
        if(d.ok){location.reload();}
        else{alert(' '+(d.error||'Delete failed'));}
    });

}

    //  Load Money Agent Filter 
    var _bcLmFiltTimer=null;
    function bcLmFilterSearch(val){
        var dd=document.getElementById('bcLmFilterDrop');
        document.getElementById('bcLmFilterAid').value='';
        clearTimeout(_bcLmFiltTimer);
        if(val.length<2){dd.style.display='none';return;}
        _bcLmFiltTimer=setTimeout(function(){
            bcGet('bc_agents_search',{q:val},function(d){
                dd.innerHTML='';
                if(!d.ok||!d.data||!d.data.length){dd.innerHTML='<div style="padding:10px;font-size:12px;color:var(--text-3);">No agents</div>';dd.style.display='';return;}
                d.data.forEach(function(a){
                    var el=document.createElement('div');
                    el.style.cssText='padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;';
                    el.onmouseover=function(){this.style.background='#EFF6FF';};el.onmouseout=function(){this.style.background='';};
                    el.innerHTML='<strong>'+a.firstname+' '+a.lastname+'</strong> <span style="font-size:11px;color:var(--text-3);">'+a.mobile+'  '+a.role_display+'</span>';
                    el.onclick=function(){document.getElementById('bcLmFilterAid').value=a.id;document.getElementById('bcLmFilterAgent').value=a.firstname+' '+a.lastname;dd.style.display='none';document.getElementById('bcLmFilter').submit();};
                    dd.appendChild(el);
                });
                dd.style.display='';
            });
        },350);
    }
    function bcLmFilterClear(){document.getElementById('bcLmFilterAid').value='';document.getElementById('bcLmFilterAgent').value='';window.location='?page=dashboard&tab=lte_bluecard&bc=loadmoney';}

    //  KYC Form 
    var _bcKycStep=1;
    function bcKycGoStep(n){
        if(n===2&&!document.getElementById('kyc_firstname').value.trim()){bcAlert(document.getElementById('bcKycMsg'),' First name required','error');return;}
        if(n===3&&(!document.getElementById('kyc_sim_id').value||!document.getElementById('kyc_offer_id').value)){bcAlert(document.getElementById('bcKycMsg'),' Select SIM and Plan','error');return;}
        [1,2,3].forEach(function(i){
            document.getElementById('bcKycStep'+i).style.display=i===n?'':'none';
            var t=document.getElementById('bcKycStepTab'+i);
            if(t){t.style.background=i===n?'#1D4ED8':'#F8FAFC';t.style.color=i===n?'#fff':'var(--text-3)';}
        });
        _bcKycStep=n;document.getElementById('bcKycMsg').innerHTML='';
    }
    function bcKycSimSelect(sel){
        var opt=sel.options[sel.selectedIndex];
        if(!opt||!opt.value){document.getElementById('bcKycSimInfo').style.display='none';return;}
        document.getElementById('bcKycSimMsisdn').textContent=opt.dataset.msisdn+' (IMSI: '+opt.dataset.imsi+')';
        document.getElementById('bcKycSimInfo').style.display='';
    }
    function bcKycPlanSelect(sel){
        var opt=sel.options[sel.selectedIndex];
        if(!opt||!opt.value){document.getElementById('bcKycPlanInfo').style.display='none';return;}
        document.getElementById('bcKycPlanName').textContent=opt.text.split('')[0].trim();
        document.getElementById('bcKycPlanAmt').textContent='$'+(parseInt(opt.dataset.amt)/100).toFixed(2);
        document.getElementById('bcKycPlanDays').textContent=opt.dataset.days;
        document.getElementById('bcKycPlanBytes').textContent=opt.dataset.bytes;
        document.getElementById('bcKycPlanInfo').style.display='';
    }
    var _bcKycAgentTimer=null;
    function bcKycAgentSearch(val){
        var dd=document.getElementById('bcKycAgentDrop');
        document.getElementById('kyc_retailer_id').value='';
        clearTimeout(_bcKycAgentTimer);
        if(val.length<2){dd.style.display='none';return;}
        _bcKycAgentTimer=setTimeout(function(){
            bcGet('bc_agents_search',{q:val},function(d){
                dd.innerHTML='';
                if(!d.ok||!d.data||!d.data.length){dd.innerHTML='<div style="padding:10px;font-size:12px;color:var(--text-3);">No agents</div>';dd.style.display='';return;}
                d.data.forEach(function(a){
                    var el=document.createElement('div');
                    el.style.cssText='padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;';
                    el.onmouseover=function(){this.style.background='#EFF6FF';};el.onmouseout=function(){this.style.background='';};
                    el.innerHTML='<strong>'+a.firstname+' '+a.lastname+'</strong> <span style="font-size:11px;color:var(--text-3);">'+a.role_display+'  $'+parseFloat(a.wallet||0).toFixed(2)+'</span>';
                    el.onclick=function(){document.getElementById('kyc_retailer_id').value=a.id;document.getElementById('bcKycRetailerSearch').value=a.firstname+' '+a.lastname;dd.style.display='none';};
                    dd.appendChild(el);
                });
                dd.style.display='';
            });
        },350);
    }
    function bcKycSubmit(btn){
        var msg=document.getElementById('bcKycMsg');
        var p={firstname:document.getElementById('kyc_firstname').value.trim(),lastname:document.getElementById('kyc_lastname').value.trim(),email:document.getElementById('kyc_email').value.trim(),alternateMobileNo:document.getElementById('kyc_altmobile').value.trim(),whatsapp_number:document.getElementById('kyc_whatsapp').value.trim(),gender:document.getElementById('kyc_gender').value,date_of_birth:document.getElementById('kyc_dob').value,nationality:document.getElementById('kyc_nationality').value.trim(),address:document.getElementById('kyc_address').value.trim(),sim_id:parseInt(document.getElementById('kyc_sim_id').value)||0,offer_id:parseInt(document.getElementById('kyc_offer_id').value)||0,retailer_id:parseInt(document.getElementById('kyc_retailer_id').value)||0,payment_type:document.getElementById('kyc_payment_type').value,company_id:1};
        if(!p.firstname){bcAlert(msg,' First name required','error');bcKycGoStep(1);return;}
        if(!p.sim_id){bcAlert(msg,' Please select a SIM','error');bcKycGoStep(2);return;}
        if(!p.offer_id){bcAlert(msg,' Please select a plan','error');bcKycGoStep(2);return;}
        btn.disabled=true;btn.textContent=' Uploading docs';
        kycUploadImages([{input:'kyc_img_cust',field:'customer_img'},{input:'kyc_img_af',field:'aadhar_card_front_img'},{input:'kyc_img_ab',field:'aadhar_card_back_img'},{input:'kyc_img_pan',field:'pan_card_img'}],0,p,function(pl,err){
            if(err){btn.disabled=false;btn.textContent=' Register Customer';bcAlert(msg,' Image upload: '+err,'error');return;}
            btn.textContent=' Registering';
            bcPost('bc_kyc_create',pl,function(d){
                btn.disabled=false;btn.textContent=' Register Customer';
                if(d.ok){
                    var wm=d.wallet!==null?'  Retailer wallet: $'+parseFloat(d.wallet).toFixed(2):'';
                    bcAlert(msg,' Customer registered!<br> MSISDN: <strong>'+d.msisdn+'</strong>  Plan: <strong>'+d.plan+'</strong>  Expires: '+d.end_date+wm,'success');
                    // Save locally to SQLite backup
                    var localPayload=Object.assign({},pl,{user_id:d.user_id,service_id:d.service_id,balance_topup_id:d.balance_topup_id,data_mgmt_id:d.data_mgmt_id,msisdn:d.msisdn,imsi:d.imsi,plan:d.plan,plan_name:d.plan,plan_price:d.plan_price,end_date:d.end_date,offer_id:d.offer_id,sim_id:d.sim_id});
                    fetch('?page=api&action=bc_kyc_save_local',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(localPayload)}).catch(function(){});
                    ['kyc_firstname','kyc_sim_id','kyc_offer_id','kyc_img_cust','kyc_img_af','kyc_img_ab','kyc_img_pan'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});
                    ['kyc_prev_cust','kyc_prev_af','kyc_prev_ab','kyc_prev_pan'].forEach(function(id){var el=document.getElementById(id);if(el){el.style.display='none';el.src='';}});
                }
                else{bcAlert(msg,' '+(d.error||'Registration failed'),'error');}
            });
        });
    }
    function bcSyncAgent(bcUid, btn) {
        var msgEl = document.getElementById('bcSyncMsg_' + bcUid);
        btn.disabled = true; btn.textContent = '';
        if (msgEl) msgEl.innerHTML = '';
        fetch('?page=api&action=bc_agent_sync', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({bc_user_id: bcUid})
        }).then(function(r){return r.json();}).then(function(d){
            btn.disabled = false; btn.textContent = ' Sync';
            if (d.status === 'success' && d.data) {
                var r = (d.data.results||[])[0];
                var action = r ? r.action : 'done';
                var pwd = r && r.default_pwd ? '  Pwd: ' + r.default_pwd : '';
                if (msgEl) msgEl.innerHTML = '<span style="color:#16A34A;"> ' + action + pwd + '</span>';
            } else {
                if (msgEl) msgEl.innerHTML = '<span style="color:#DC2626;"> ' + (d.message||'Failed') + '</span>';
            }
        }).catch(function(e){
            btn.disabled = false; btn.textContent = ' Sync';
            if (msgEl) msgEl.innerHTML = '<span style="color:#DC2626;"> ' + e + '</span>';
        });
    }
    function bcSyncAllAgents(btn) {
        var msgEl = document.getElementById('bcSyncAllMsg');
        if (!confirm('Sync ALL BlueCard agents to plugin login? This will create/update plugin accounts for all dealers, retailers and franchisees.')) return;
        btn.disabled = true; btn.textContent = ' Syncing all';
        msgEl.innerHTML = '';
        fetch('?page=api&action=bc_agent_sync', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({sync_all: true})
        }).then(function(r){return r.json();}).then(function(d){
            btn.disabled = false; btn.textContent = ' Sync All to Plugin Login';
            if (d.status === 'success' && d.data) {
                var info = d.data;
                msgEl.innerHTML = '<span style="color:#16A34A;"> Created: ' + info.created + '  Updated: ' + info.updated + '  Skipped: ' + info.skipped + '</span>';
            } else {
                msgEl.innerHTML = '<span style="color:#DC2626;"> ' + (d.message||'Failed') + '</span>';
            }
        }).catch(function(e){
            btn.disabled = false; btn.textContent = ' Sync All to Plugin Login';
            msgEl.innerHTML = '<span style="color:#DC2626;"> ' + e + '</span>';
        });
    }
    //  Admin Customer Detail JS 
    var _bcAdminAgentTimer = null;
    function bcAdminAgentSearch(val) {
        var dd = document.getElementById('bcAdminRechargeAgentDrop');
        document.getElementById('bcAdminRechargeAgentId').value = '';
        clearTimeout(_bcAdminAgentTimer);
        if (val.length < 2) { dd.style.display = 'none'; return; }
        _bcAdminAgentTimer = setTimeout(function() {
            bcGet('bc_agents_search', {q: val}, function(d) {
                dd.innerHTML = '';
                (d.data || []).forEach(function(a) {
                    var div = document.createElement('div');
                    div.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #F1F5F9;';
                    div.innerHTML = '<strong>' + a.firstname + ' ' + a.lastname + '</strong><div style="font-size:11px;color:#94a3b8;">' + a.mobile + '  Wallet: $' + parseFloat(a.wallet||0).toFixed(2) + '</div>';
                    div.onclick = function() {
                        document.getElementById('bcAdminRechargeAgentQ').value = a.firstname + ' ' + a.lastname;
                        document.getElementById('bcAdminRechargeAgentId').value = a.id;
                        dd.style.display = 'none';
                    };
                    dd.appendChild(div);
                });
                dd.style.display = (d.data||[]).length ? '' : 'none';
            });
        }, 280);
    }
    function bcAdminDoRecharge(msisdn, btn) {
        var msg = document.getElementById('bcAdminRechargeMsg');
        var offerId = parseInt(document.getElementById('bcAdminRechargePlan').value) || 0;
        var agentId = parseInt(document.getElementById('bcAdminRechargeAgentId').value) || 0;
        var planAmt = parseFloat(document.getElementById('bcAdminRechargePlan').options[document.getElementById('bcAdminRechargePlan').selectedIndex]?.getAttribute('data-usd')||'0') || 0;
        if (!offerId) { bcAlert(msg, ' Select a plan', 'error'); return; }
        if (!agentId) { bcAlert(msg, ' Select the agent paying', 'error'); return; }
        btn.disabled = true; btn.textContent = ' Checking limit';
        // Check outstanding before recharge
        fetch('?page=api&action=bc_check_outstanding', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({agent_id: agentId, amount_usd: planAmt})
        }).then(function(r){return r.json();}).then(function(chk){
            var data = (chk.data||chk);
            if (data.blocked) {
                btn.disabled = false; btn.textContent = ' Recharge Now';
                bcAlert(msg, ' BLOCKED: ' + (document.getElementById('bcAdminRechargeAgentQ').value||'Agent')
                    + ' has $' + (data.outstanding||0).toFixed(2) + ' outstanding (limit $' + (data.limit_usd||500).toFixed(0)
                    + '). BBC must collect before next recharge.', 'error');
                return;
            }
            btn.textContent = ' Recharging';
            bcPost('bc_customer_recharge', {offer_id: offerId, msisdn: msisdn, agent_id: agentId, payment_type: 'Wallet'}, function(d) {
                btn.disabled = false; btn.textContent = ' Recharge Now';
                if (d.ok) { bcAlert(msg, ' Recharged! Plan: ' + d.plan + '  Expires: ' + d.end_date
                    + (data.new_outstanding ? '  Agent outstanding now: $' + data.new_outstanding.toFixed(2) : ''), 'success'); }
                else { bcAlert(msg, ' ' + (d.error || 'Failed'), 'error'); }
            });
        }).catch(function(){
            // If check fails, proceed anyway (don't block on network error)
            btn.textContent = ' Recharging';
            bcPost('bc_customer_recharge', {offer_id: offerId, msisdn: msisdn, agent_id: agentId, payment_type: 'Wallet'}, function(d) {
                btn.disabled = false; btn.textContent = ' Recharge Now';
                if (d.ok) { bcAlert(msg, ' Recharged! Plan: ' + d.plan + '  Expires: ' + d.end_date, 'success'); }
                else { bcAlert(msg, ' ' + (d.error || 'Failed'), 'error'); }
            });
        });
    }
    function bcAdminSaveEdit(uid, btn) {
        var msg = document.getElementById('bcAdminEditMsg');
        var payload = {
            id: uid,
            firstname: document.getElementById('bce_fn').value.trim(),
            lastname:  document.getElementById('bce_ln').value.trim(),
            email:     document.getElementById('bce_em').value.trim(),
            alternateMobileNo: document.getElementById('bce_am').value.trim(),
            whatsapp_number: document.getElementById('bce_wa').value.trim(),
            gender:    document.getElementById('bce_gn').value,
            date_of_birth: document.getElementById('bce_db').value,
            nationality: document.getElementById('bce_na').value.trim(),
            aadhar_card_no: document.getElementById('bce_id').value.trim(),
            is_active: document.getElementById('bce_st').value,
            address:   document.getElementById('bce_ad').value.trim(),
            city:      document.getElementById('bce_ci').value.trim(),
            state:     document.getElementById('bce_s2').value.trim()
        };
        if (!payload.firstname) { bcAlert(msg, ' First name required', 'error'); return; }
        btn.disabled = true; btn.textContent = ' Saving';
        bcPost('bc_customer_update', payload, function(d) {
            btn.disabled = false; btn.textContent = ' Save Changes';
            if (d.ok) { bcAlert(msg, ' Saved!', 'success'); setTimeout(function(){ window.location = '?page=dashboard&tab=lte_bluecard&bc=customerdetail&bcuid=' + uid; }, 800); }
            else { bcAlert(msg, ' ' + (d.error || 'Failed'), 'error'); }
        });
    }
    function bcCancelPlanAdmin(btId) {
        if (!confirm('Cancel this plan? If active, the customer will be deactivated and agent refunded.')) return;
        bcPost('bc_customer_cancel_plan', {balance_topup_id: btId, reason: 'Cancelled by admin'}, function(d) {
            if (d.ok) { location.reload(); }
            else { alert(' ' + (d.error || 'Failed')); }
        });
    }
    function kycPreview(inp,prevId){var prev=document.getElementById(prevId);if(!inp.files||!inp.files[0]){prev.style.display='none';return;}var r=new FileReader();r.onload=function(e){prev.src=e.target.result;prev.style.display='';};r.readAsDataURL(inp.files[0]);}
    function kycDoImport(inp) {
        if (!inp.files || !inp.files[0]) return;
        var msg = document.getElementById('kycImportMsg');
        msg.innerHTML = ' Importing';
        var reader = new FileReader();
        reader.onload = function(e) {
            var body;
            try { body = e.target.result; JSON.parse(body); }
            catch(ex) { msg.innerHTML = '<span style="color:#DC2626;"> Invalid JSON file</span>'; return; }
            fetch('?page=api&action=bc_kyc_import', {
                method:'POST', headers:{'Content-Type':'application/json'}, body:body
            }).then(function(r){return r.json();}).then(function(d){
                if (d.status === 'success' || d.data) {
                    var info = d.data || {};
                    msg.innerHTML = '<span style="color:#16A34A;"> Imported: '+info.imported+'  Skipped: '+info.skipped+'</span>';
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    msg.innerHTML = '<span style="color:#DC2626;"> '+(d.message||'Import failed')+'</span>';
                }
            }).catch(function(ex){msg.innerHTML='<span style="color:#DC2626;"> '+ex+'</span>';});
        };
        reader.readAsText(inp.files[0]);
        inp.value = ''; // allow re-select same file
    }
    function kycUploadImages(fields,idx,payload,done){if(idx>=fields.length){done(payload,null);return;}var f=fields[idx];var inp=document.getElementById(f.input);if(!inp||!inp.files||!inp.files[0]){kycUploadImages(fields,idx+1,payload,done);return;}var fd=new FormData();fd.append('file',inp.files[0]);fetch('?page=api&action=bc_proxy&table=bc_upload_img&field='+encodeURIComponent(f.field),{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){if(d.ok){payload[f.field]=d.url;}else{done(payload,d.error||'Upload error');return;}kycUploadImages(fields,idx+1,payload,done);}).catch(function(e){done(payload,''+e);});}

    //  SIM Management: Assign 
    var _saAgentTimer = null;
    function saAgentLookup(val) {
        var dd = document.getElementById('saAgentDrop');
        document.getElementById('saAgentId').value = '';
        document.getElementById('saAgentCard').style.display = 'none';
        clearTimeout(_saAgentTimer);
        if (val.length < 2) { dd.style.display = 'none'; return; }
        _saAgentTimer = setTimeout(function() {
            bcGet('bc_agents_search', {q: val}, function(d) {
                dd.innerHTML = '';
                if (!d.ok || !d.data || !d.data.length) {
                    dd.innerHTML = '<div style="padding:10px;font-size:12px;color:var(--text-3);">No agents found</div>';
                    dd.style.display = '';
                    return;
                }
                d.data.forEach(function(a) {
                    var el = document.createElement('div');
                    el.style.cssText = 'padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:13px;';
                    el.onmouseover = function() { this.style.background = '#EFF6FF'; };
                    el.onmouseout  = function() { this.style.background = ''; };
                    el.innerHTML = '<strong>' + a.firstname + ' ' + a.lastname + '</strong>'
                        + ' <span style="font-size:11px;background:#EFF6FF;color:#1D4ED8;padding:1px 8px;border-radius:20px;">' + a.role_display + '</span>'
                        + '<div style="font-size:11px;color:var(--text-3);">' + a.mobile + '  Wallet: $' + parseFloat(a.wallet||0).toFixed(2) + '</div>';
                    el.onclick = function() {
                        document.getElementById('saAgentId').value = a.id;
                        document.getElementById('saAgentSearch').value = a.firstname + ' ' + a.lastname;
                        document.getElementById('saAgentName').textContent = a.firstname + ' ' + a.lastname;
                        document.getElementById('saAgentWallet').textContent = '$' + parseFloat(a.wallet||0).toFixed(2);
                        document.getElementById('saAgentRole').textContent = a.role_display;
                        document.getElementById('saAgentCard').style.display = '';
                        dd.style.display = 'none';
                    };
                    dd.appendChild(el);
                });
                dd.style.display = '';
            });
        }, 350);
    }
    function saFilterSims(val) {
        var rows = document.querySelectorAll('#saSimTable tbody .sa-sim-row');
        val = val.toLowerCase();
        rows.forEach(function(row) {
            var txt = row.textContent.toLowerCase();
            row.style.display = val === '' || txt.indexOf(val) > -1 ? '' : 'none';
        });
    }
    function saToggleAll(cb) {
        document.querySelectorAll('.sa-sim-chk').forEach(function(c) {
            if (c.closest('tr').style.display !== 'none') c.checked = cb.checked;
        });
        saUpdateCount();
    }
    function saUpdateCount() {
        var chks = document.querySelectorAll('.sa-sim-chk:checked');
        var total = 0;
        chks.forEach(function(c) { total += parseFloat(c.dataset.price || 0); });
        document.getElementById('saSelectedCount').textContent = chks.length;
        document.getElementById('saSelectedPrice').textContent = '$' + total.toFixed(2);
    }
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('sa-sim-chk')) saUpdateCount();
    });
    function saDoAssign(btn) {
        var agentId = parseInt(document.getElementById('saAgentId').value) || 0;
        var msg = document.getElementById('bcSimAssignMsg');
        if (!agentId) { bcAlert(msg, ' Please search and select an agent first', 'error'); return; }
        var simIds = [];
        document.querySelectorAll('.sa-sim-chk:checked').forEach(function(c) { simIds.push(parseInt(c.value)); });
        if (!simIds.length) { bcAlert(msg, ' Select at least one SIM to assign', 'error'); return; }
        var chargeWallet = document.getElementById('saChargeWallet').checked;
        btn.disabled = true; btn.textContent = ' Assigning';
        bcPost('bc_sim_assign', {
            sim_ids: simIds,
            agent_id: agentId,
            charge_wallet: chargeWallet
        }, function(d) {
            btn.disabled = false; btn.textContent = ' Assign Selected SIMs';
            if (d.ok) {
                bcAlert(msg, ' ' + d.assigned + ' SIM(s) assigned successfully!'
                    + (d.charged ? ' Wallet debited $' + parseFloat(d.total_price||0).toFixed(2) : ' (no wallet charge)'), 'success');
                setTimeout(function() { location.reload(); }, 1400);
            } else {
                bcAlert(msg, ' ' + (d.error || 'Assign failed'), 'error');
            }
        });
    }

    //  SIM Management: Return Requests 
    function retToggleAll(cb) {
        document.querySelectorAll('.ret-chk').forEach(function(c) { c.checked = cb.checked; });
    }
    function retAccept(btn) {
        var ids = [];
        document.querySelectorAll('.ret-chk:checked').forEach(function(c) { ids.push(parseInt(c.value)); });
        var msg = document.getElementById('retMsg');
        if (!ids.length) { msg.innerHTML = '<span style="color:#DC2626;"> Select at least one SIM</span>'; return; }
        if (!confirm('Accept return of ' + ids.length + ' SIM(s)? Agent will receive 50% refund.')) return;
        btn.disabled = true; btn.textContent = ' Processing';
        bcPost('bc_sim_return_accept', { sim_ids: ids }, function(d) {
            btn.disabled = false; btn.textContent = ' Accept Selected Returns';
            if (d.ok) {
                msg.innerHTML = '<span style="color:#16A34A;"> ' + d.accepted + ' SIM(s) accepted, 50% refund credited.</span>';
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                msg.innerHTML = '<span style="color:#DC2626;"> ' + (d.error || 'Failed') + '</span>';
            }
        });
    }
</script>
</div><!-- /bc-wrap -->