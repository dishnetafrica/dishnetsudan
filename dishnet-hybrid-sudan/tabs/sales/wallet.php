<?php
// ── CSV Export ──────────────────────────────────────────────────────────────
if (!empty($_GET['fr_export']) && $_GET['fr_export'] === 'csv') {
    // (ledger built below — rebuild inline for export)
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="field-register-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Date','Direction','Currency','Amount','SSP Amount','Category','Description','Status']);
    // minimal rebuild
    $agId = (int)($retailer['id'] ?? 0);
    $rows = [];
    foreach ($store->load('payment_collections.json') ?: [] as $c) {
        if ((int)($c['retailer_id']??0)!==$agId) continue;
        if (($c['status'] ?? '') === 'voided') continue;
        $rows[] = [substr($c['collected_at']??$c['created_at']??'',0,10),'IN','USD',$c['amount']??0,0,'Collection',$c['client_name']??'','approved'];
    }
    if (!class_exists('ExpenseGateway')) require_once __DIR__ . '/../../lib/ExpenseGateway.php';
    $_frGw = new ExpenseGateway($store);
    foreach ($_frGw->getByStaff($agId) as $e) {
        $rows[] = [substr($e['submitted_at']??'',0,10),'OUT',$e['currency']??'USD',$e['amount']??0,$e['ssp_amount']??0,$e['category']??'Expense',$e['description']??'',$e['status']??'pending'];
    }
    foreach ($store->load('cash_handovers.json') ?: [] as $h) {
        if ((int)($h['from_id']??0)!==$agId) continue;
        $rows[] = [substr($h['created_at']??'',0,10),'OUT','USD',$h['amount']??0,0,'Handover',$h['note']??'',$h['status']??'pending'];
    }
    foreach ($store->load('cash_ins.json') ?: [] as $i) {
        if ((int)($i['collector_id']??0)!==$agId) continue;
        $rows[] = [substr($i['created_at']??'',0,10),'IN',$i['currency']??'SSP',$i['amount']??0,$i['ssp_amount']??0,$i['category']??'SSP Received',$i['description']??'',$i['status']??'approved'];
    }
    usort($rows, fn($a,$b)=>strcmp($a[0],$b[0]));
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out); exit;
}
?>
<?php
// ── Field Register — Cash tracking for field sales agents ────────────────
// Multi-agent: each agent sees only their own entries, filtered by retailer_id.
// Data sources: payment_collections.json, cash_expenses.json,
//               cash_handovers.json, cash_ins.json (SSP received / exchange)

$fr_agentId       = (int)($retailer['id'] ?? 0);
$fr_agentName     = $retailer['name'] ?? 'Field Agent';
// Extended categories: anyone who handles cash in the field (sales, field_agent, collection, field_accountant)
$fr_isAcct        = in_array($userRole ?? '', ['field_accountant','sales','sales_staff','field_agent','collection','support_leader','support']);
$fr_rate      = 5180.0; // default SSP rate
try {
    require_once __DIR__ . '/../../lib/CashbookService.php';
    $_frCb   = new CashbookService($store, $dataDir);
    $fr_rate = $_frCb->getExchangeRate() ?: 5180.0;
} catch (\Throwable $_e) {}
// ── Single source of truth: StaffCashPositionService (JSON-based, matches admin cashbooks) ──
// v4.11.38: was using DualReadCashPosition (staff_ledger SQL) which is stale and incomplete.
require_once __DIR__ . '/../../lib/StaffCashPositionService.php';
$_frScpSvc = new StaffCashPositionService($store, $store->getPdo());
$fr_pos_usd_raw = $_frScpSvc->getCashInHand($fr_agentId); // can be negative = company owes you

// ── Load all data ─────────────────────────────────────────────────────────
$fr_collections = array_filter($store->load('payment_collections.json') ?: [],
    fn($c) => (int)($c['retailer_id'] ?? 0) === $fr_agentId && ($c['status'] ?? '') !== 'voided');

$fr_handovers = array_filter($store->load('cash_handovers.json') ?: [],
    fn($h) => (int)($h['from_id'] ?? 0) === $fr_agentId);

if (!isset($_frGw)) { if (!class_exists('ExpenseGateway')) require_once __DIR__ . '/../../lib/ExpenseGateway.php'; $_frGw = new ExpenseGateway($store); }
$fr_expenses = $_frGw->getByStaff($fr_agentId);

$fr_cashin = array_filter($store->load('cash_ins.json') ?: [],
    fn($i) => (int)($i['collector_id'] ?? 0) === $fr_agentId);

// ── USD calculations ──────────────────────────────────────────────────────
// Includes both payment_collections.json and cash_ins.json category=Collection entries
$fr_usd_collected   = round(array_sum(array_column(array_values(
    array_filter($fr_collections, fn($c) => ($c['currency'] ?? 'USD') === 'USD')), 'amount')), 2)
    + round(array_sum(array_column(array_values(array_filter($fr_cashin,
        fn($i) => ($i['category'] ?? '') === 'Collection'
            && !in_array($i['status'] ?? 'approved', ['rejected','voided']))), 'amount')), 2);

$fr_usd_hov_conf    = round(array_sum(array_column(array_values(
    array_filter($fr_handovers, fn($h) => ($h['status'] ?? '') === 'confirmed'
        && ($h['currency'] ?? 'USD') === 'USD')), 'amount')), 2);

$fr_usd_exp_approv  = round(array_sum(array_column(array_values(
    array_filter($fr_expenses, fn($e) => ($e['currency'] ?? 'USD') === 'USD'
        && in_array($e['status'] ?? '', ['approved']))), 'amount')), 2);

// Exchange: USD given out (reduces USD holding)
$fr_usd_exchanged   = round(array_sum(array_column(array_values(
    array_filter($fr_cashin, fn($i) => ($i['category'] ?? '') === 'Exchange')), 'usd_given')), 2);

// Advances: USD received from office / given to staff
$fr_usd_adv_in = 0; $fr_usd_adv_out = 0;
$fr_ssp_adv_in = 0; $fr_ssp_adv_out = 0;
try {
    $__advStmt = $store->getPdo()->prepare("SELECT issued_by_id, recipient_id, amount, currency FROM cash_advances WHERE (issued_by_id=? OR recipient_id=?) AND status IN ('active','partial','settled')");
    $__advStmt->execute([$fr_agentId, $fr_agentId]);
    foreach ($__advStmt->fetchAll(\PDO::FETCH_ASSOC) as $__a) {
        $__aCur = strtoupper($__a['currency'] ?? 'USD');
        $__aAmt = (float)($__a['amount'] ?? 0);
        if ((int)$__a['recipient_id'] === $fr_agentId) {
            if ($__aCur === 'SSP') $fr_ssp_adv_in += $__aAmt; else $fr_usd_adv_in += $__aAmt;
        }
        if ((int)$__a['issued_by_id'] === $fr_agentId) {
            if ($__aCur === 'SSP') $fr_ssp_adv_out += $__aAmt; else $fr_usd_adv_out += $__aAmt;
        }
    }
} catch (\Throwable $_ae) {}

// USD holding from StaffCashPositionService (single source of truth — matches My Cash)
$fr_usd_holding     = round($fr_pos_usd_raw, 2); // can be negative
$fr_usd_is_negative = $fr_usd_holding < 0;

// ── SSP balance — use StaffCashPositionService (JSON-based, matches admin cashbooks) ──
// v4.11.38: was using DualReadCashPosition (staff_ledger SQL) which missed advance/expense OUT entries.
if (!isset($_frScpSvc)) { require_once __DIR__ . '/../../lib/StaffCashPositionService.php'; $_frScpSvc = new StaffCashPositionService($store, $store->getPdo()); }
$fr_ssp_holding = max(0, (int)$_frScpSvc->getSSPBalance($fr_agentId));
$fr_ssp_usd_eq  = $fr_rate > 0 ? round($fr_ssp_holding / $fr_rate, 2) : 0;
$fr_combined_usd = round($fr_usd_holding + $fr_ssp_usd_eq, 2);

// Keep sub-totals for ledger display (used in summary tab)
$fr_ssp_received    = round(array_sum(array_column(array_values(
    array_filter($fr_cashin, fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange'])
        && !in_array($i['status'] ?? 'approved', ['rejected','voided']))), 'ssp_amount')), 0);
$fr_ssp_exp_approv  = 0; // included in DualRead result
$fr_ssp_exp_pending = 0; // included in DualRead result
$fr_ssp_hov_conf    = 0; // included in DualRead result

// ── Pending counts ────────────────────────────────────────────────────────
$fr_exp_pending = array_values(array_filter($fr_expenses, fn($e) => ($e['status'] ?? '') === 'pending'));
$fr_hov_pending = array_values(array_filter($fr_handovers, fn($h) => ($h['status'] ?? '') === 'pending'));
$fr_in_pending  = array_values(array_filter($fr_cashin,   fn($i) => ($i['status'] ?? 'approved') === 'pending'));
$fr_pending_all = array_merge($fr_exp_pending, $fr_hov_pending, $fr_in_pending);
$fr_pending_cnt = count($fr_pending_all);

// ── Recent activity (today / yesterday / this week) ──────────────────────
$_today     = date('Y-m-d');
$_yesterday = date('Y-m-d', strtotime('-1 day'));
$_weekStart = date('Y-m-d', strtotime('monday this week'));

// Today's collections — payment_collections.json + cash_ins.json category=Collection
$_fr_cashin_collections = array_values(array_filter($fr_cashin,
    fn($i) => ($i['category'] ?? '') === 'Collection'
        && !in_array($i['status'] ?? 'approved', ['rejected','voided'])));
$fr_today_collected = round(array_sum(array_column(array_values(
    array_filter($fr_collections, fn($c) => substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10) === $_today)
), 'amount')), 2)
+ round(array_sum(array_column(array_values(
    array_filter($_fr_cashin_collections, fn($i) => substr($i['created_at'] ?? '', 0, 10) === $_today)
), 'amount')), 2);

// Today's expenses
$fr_today_expenses = round(array_sum(array_column(array_values(
    array_filter($fr_expenses, fn($e) => substr($e['submitted_at'] ?? $e['created_at'] ?? '', 0, 10) === $_today)
), 'amount')), 2) + round(array_sum(array_column(array_values(
    array_filter($fr_expenses, fn($e) => substr($e['submitted_at'] ?? $e['created_at'] ?? '', 0, 10) === $_today
        && ($e['currency'] ?? 'USD') === 'SSP')
), 'ssp_amount')), 0);

// Today's handovers
$fr_today_handovers = round(array_sum(array_column(array_values(
    array_filter($fr_handovers, fn($h) => substr($h['created_at'] ?? '', 0, 10) === $_today)
), 'amount')), 2);

// Yesterday totals
$fr_yest_collected = round(array_sum(array_column(array_values(
    array_filter($fr_collections, fn($c) => substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10) === $_yesterday)
), 'amount')), 2)
+ round(array_sum(array_column(array_values(
    array_filter($_fr_cashin_collections, fn($i) => substr($i['created_at'] ?? '', 0, 10) === $_yesterday)
), 'amount')), 2);
$fr_yest_handovers = round(array_sum(array_column(array_values(
    array_filter($fr_handovers, fn($h) => substr($h['created_at'] ?? '', 0, 10) === $_yesterday)
), 'amount')), 2);

// This week collections count — payment_collections + cash_ins category=Collection
$fr_week_collections = count(array_filter($fr_collections,
    fn($c) => substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10) >= $_weekStart))
    + count(array_filter($_fr_cashin_collections,
    fn($i) => substr($i['created_at'] ?? '', 0, 10) >= $_weekStart));
$fr_week_collected = round(array_sum(array_column(array_values(
    array_filter($fr_collections, fn($c) => substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10) >= $_weekStart)
), 'amount')), 2)
+ round(array_sum(array_column(array_values(
    array_filter($_fr_cashin_collections, fn($i) => substr($i['created_at'] ?? '', 0, 10) >= $_weekStart)
), 'amount')), 2);

// ── SSP activity stats (for support roles who primarily handle SSP) ──────
$fr_ssp_week_in = round(array_sum(array_column(array_values(
    array_filter($fr_cashin, fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange'])
        && !in_array($i['status'] ?? 'approved', ['rejected','voided'])
        && substr($i['created_at'] ?? '', 0, 10) >= $_weekStart)
), 'ssp_amount')), 0);
$fr_ssp_week_out = round(array_sum(array_column(array_values(
    array_filter($fr_expenses, fn($e) => ($e['currency'] ?? 'USD') === 'SSP'
        && in_array($e['status'] ?? '', ['approved','pending'])
        && substr($e['submitted_at'] ?? $e['created_at'] ?? '', 0, 10) >= $_weekStart)
), 'ssp_amount')), 0);
$fr_ssp_pending_cnt = count(array_filter($fr_expenses, fn($e) =>
    ($e['currency'] ?? 'USD') === 'SSP' && ($e['status'] ?? '') === 'pending'));
// Support roles see SSP-first hero (they receive SSP for expenses, not USD collections)
$fr_is_support_role = in_array($userRole ?? '', ['support_leader', 'support']);

// ── View / filters ────────────────────────────────────────────────────────
$fr_view = $_GET['fr_view'] ?? 'ledger';
$fr_curr = strtoupper($_GET['fr_curr'] ?? '');
if (!in_array($fr_curr, ['USD','SSP'])) $fr_curr = '';
$fr_from = $_GET['fr_from'] ?? '';
$fr_to   = $_GET['fr_to']   ?? '';

// ── Build unified ledger ──────────────────────────────────────────────────
$fr_ledger = [];
// Collections (USD IN)
foreach ($fr_collections as $c) {
    if ($fr_curr && $fr_curr !== 'USD') continue;
    $fr_ledger[] = [
        'date'      => substr($c['collected_at'] ?? $c['created_at'] ?? date('Y-m-d'), 0, 10),
        'dir'       => 'in',
        'currency'  => 'USD',
        'amount'    => (float)($c['amount'] ?? 0),
        'ssp_amount'=> 0,
        'category'  => 'Collection',
        'desc'      => $c['customer_name'] ?? $c['client_name'] ?? '',
        'customer_name' => $c['customer_name'] ?? $c['client_name'] ?? '',
        'invoice_id'=> $c['invoice_id'] ?? '',
        'status'    => 'approved', // collections are always approved; crm_synced is a separate sync flag
        'source'    => 'collection',
        'id'        => 'COL-'.($c['id'] ?? ''),
    ];
}
// Cash IN (SSP Received / Exchange)
foreach ($fr_cashin as $i) {
    $cur = ($i['category'] ?? '') === 'Exchange' ? 'SSP' : ($i['currency'] ?? 'SSP');
    if ($fr_curr && $fr_curr !== $cur) continue;
    $fr_ledger[] = [
        'date'      => substr($i['created_at'] ?? date('Y-m-d'), 0, 10),
        'dir'       => 'in',
        'currency'  => $cur,
        'amount'    => (float)($i['amount'] ?? 0),
        'ssp_amount'=> (float)($i['ssp_amount'] ?? 0),
        'category'  => $i['category'] ?? 'SSP Received',
        'desc'      => $i['description'] ?? '',
        'status'    => $i['status'] ?? 'approved',
        'source'    => 'cash_in',
        'id'        => 'CIN-'.($i['id'] ?? ''),
    ];
    // Exchange also creates a USD OUT
    if (($i['category'] ?? '') === 'Exchange' && !$fr_curr || $fr_curr === 'USD') {
        $fr_ledger[] = [
            'date'      => substr($i['created_at'] ?? date('Y-m-d'), 0, 10),
            'dir'       => 'out',
            'currency'  => 'USD',
            'amount'    => (float)($i['usd_given'] ?? 0),
            'ssp_amount'=> 0,
            'category'  => 'Exchange',
            'desc'      => 'USD→SSP exchange: $'.number_format($i['usd_given'] ?? 0,2).' @ '.number_format($i['rate'] ?? 0,0),
            'status'    => $i['status'] ?? 'approved',
            'source'    => 'cash_in',
            'id'        => 'CINE-'.($i['id'] ?? ''),
        ];
    }
}
// Expenses (USD or SSP OUT)
foreach ($fr_expenses as $e) {
    $cur = $e['currency'] ?? 'USD';
    if ($fr_curr && $fr_curr !== $cur) continue;
    $isStaff = !empty($e['is_staff_payment']) || !empty($e['staff_name']);
    $catLabel = $isStaff
        ? 'Staff Payment'
        : ($e['category'] ?? 'Expense');
    $descParts = [];
    if ($isStaff && !empty($e['staff_name'])) $descParts[] = $e['staff_name'];
    if (!$isStaff) $descParts[] = $e['category'] ?? '';
    if (!empty($e['description'])) $descParts[] = $e['description'];
    $fr_ledger[] = [
        'date'      => substr($e['submitted_at'] ?? date('Y-m-d'), 0, 10),
        'dir'       => 'out',
        'currency'  => $cur,
        'amount'    => (float)($e['amount'] ?? 0),
        'ssp_amount'=> (float)($e['ssp_amount'] ?? 0),
        'category'  => $catLabel,
        'desc'      => implode(' — ', array_filter($descParts)),
        'person'    => $e['staff_name'] ?? '',
        'status'    => $e['status'] ?? 'pending',
        'source'    => 'expense',
        'id'        => ($e['source'] ?? 'field') === 'advance' ? 'ADV-'.($e['id'] ?? '') : 'EXP-'.($e['id'] ?? ''),
        'auto_approved' => !empty($e['auto_approved']),
        'submitted_at'  => $e['submitted_at'] ?? '',
        'photo'         => $e['receipt_path'] ?? $e['photo'] ?? '',
    ];
}
// Handovers (USD or SSP OUT)
foreach ($fr_handovers as $h) {
    $hCur = strtoupper($h['currency'] ?? 'USD');
    if ($fr_curr && $fr_curr !== $hCur) continue;
    $hAmt    = (float)($h['amount'] ?? 0);
    $hSsp    = (float)($h['ssp_amount'] ?? 0);
    $hTo     = $h['to_name'] ?? $h['confirmed_by'] ?? 'Office';
    $fr_ledger[] = [
        'date'      => substr($h['created_at'] ?? date('Y-m-d'), 0, 10),
        'dir'       => 'out',
        'currency'  => $hCur,
        'amount'    => $hCur === 'SSP' ? 0 : $hAmt,
        'ssp_amount'=> $hCur === 'SSP' ? ($hSsp ?: $hAmt) : 0,
        'category'  => 'Handover',
        'desc'      => 'Handover to ' . $hTo . (($h['notes'] ?? '') ? ' — '.$h['notes'] : ''),
        'status'    => $h['status'] ?? 'pending',
        'source'    => 'handover',
        'id'        => 'HOV-'.($h['id'] ?? ''),
    ];
}
// Advances received (IN — someone gave me cash)
try {
    $_advReceived = $store->getPdo()->prepare(
        "SELECT * FROM cash_advances WHERE recipient_id = ? AND status IN ('active','partial','settled') ORDER BY issued_at DESC"
    );
    $_advReceived->execute([$fr_agentId]);
    foreach ($_advReceived->fetchAll(\PDO::FETCH_ASSOC) as $adv) {
        $aCur = strtoupper($adv['currency'] ?? 'USD');
        if ($fr_curr && $fr_curr !== $aCur) continue;
        $aAmt = (float)($adv['amount'] ?? 0);
        $fr_ledger[] = [
            'date'      => substr($adv['issued_at'] ?? $adv['created_at'] ?? date('Y-m-d'), 0, 10),
            'dir'       => 'in',
            'currency'  => $aCur,
            'amount'    => $aCur === 'SSP' ? 0 : $aAmt,
            'ssp_amount'=> $aCur === 'SSP' ? $aAmt : 0,
            'category'  => 'Advance Received',
            'desc'      => 'From ' . ($adv['issued_by_name'] ?? 'Office') . ' — ' . ($adv['purpose'] ?? ''),
            'person'    => $adv['issued_by_name'] ?? '',
            'status'    => 'approved',
            'source'    => 'advance_in',
            'id'        => 'ADV-'.($adv['id'] ?? ''),
        ];
    }
} catch (\Throwable $_ae) { /* cash_advances table may not exist yet */ }
// Advances given (OUT — I gave cash to someone)
try {
    $_advGiven = $store->getPdo()->prepare(
        "SELECT * FROM cash_advances WHERE issued_by_id = ? AND status IN ('active','partial','settled') ORDER BY issued_at DESC"
    );
    $_advGiven->execute([$fr_agentId]);
    foreach ($_advGiven->fetchAll(\PDO::FETCH_ASSOC) as $adv) {
        $aCur = strtoupper($adv['currency'] ?? 'USD');
        if ($fr_curr && $fr_curr !== $aCur) continue;
        $aAmt = (float)($adv['amount'] ?? 0);
        $fr_ledger[] = [
            'date'      => substr($adv['issued_at'] ?? $adv['created_at'] ?? date('Y-m-d'), 0, 10),
            'dir'       => 'out',
            'currency'  => $aCur,
            'amount'    => $aCur === 'SSP' ? 0 : $aAmt,
            'ssp_amount'=> $aCur === 'SSP' ? $aAmt : 0,
            'category'  => 'Advance Given',
            'desc'      => 'To ' . ($adv['recipient_name'] ?? 'Staff') . ' — ' . ($adv['purpose'] ?? ''),
            'person'    => $adv['recipient_name'] ?? '',
            'status'    => 'approved',
            'source'    => 'advance_out',
            'id'        => 'ADVO-'.($adv['id'] ?? ''),
        ];
    }
} catch (\Throwable $_ae) { /* cash_advances table may not exist yet */ }
// Date filter + sort
if ($fr_from) $fr_ledger = array_filter($fr_ledger, fn($r) => $r['date'] >= $fr_from);
if ($fr_to)   $fr_ledger = array_filter($fr_ledger, fn($r) => $r['date'] <= $fr_to);
usort($fr_ledger, fn($a,$b) => strcmp($b['date'], $a['date']));
$fr_ledger = array_values($fr_ledger);

// ── Summary data ──────────────────────────────────────────────────────────
$fr_sum_usd_in  = array_sum(array_column(array_values(array_filter($fr_ledger, fn($r)=>$r['dir']==='in'&&$r['currency']==='USD'&&$r['status']==='approved')), 'amount'));
$fr_sum_usd_out = array_sum(array_column(array_values(array_filter($fr_ledger, fn($r)=>$r['dir']==='out'&&$r['currency']==='USD'&&$r['status']==='approved')), 'amount'));
$fr_sum_ssp_in  = array_sum(array_column(array_values(array_filter($fr_ledger, fn($r)=>$r['dir']==='in'&&$r['currency']==='SSP'&&!in_array($r['status'],['rejected','voided']))), 'ssp_amount'));
$fr_sum_ssp_out = array_sum(array_column(array_values(array_filter($fr_ledger, fn($r)=>$r['dir']==='out'&&$r['currency']==='SSP'&&$r['status']==='approved')), 'ssp_amount'));
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
:root{--red:#D41C1C;--green:#059669;--amber:#d97706;--blue:#1e40af;--mute:#94a3b8;--bg:#f5f5f2;--card:#fff;--border:#e8e8e3;--dark:#0f0f0f;--font:'Inter',-apple-system,sans-serif;}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
.fr3-wrap{display:flex;flex-direction:column;min-height:calc(100dvh - 56px);background:var(--bg);font-family:var(--font);}
/* Top bar */
.fr3-bar{background:var(--dark);padding:0 14px;display:flex;align-items:center;gap:10px;height:52px;position:sticky;top:0;z-index:200;}
.fr3-title{font-size:17px;font-weight:800;color:#fff;letter-spacing:.3px;flex:1;}
.fr3-fab{background:var(--red);color:#fff;border:none;border-radius:9px;padding:7px 13px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
/* Balance cards */
.fr3-cards-wrap{padding:14px 14px 0;}
.fr3-bal-card{border-radius:16px;padding:18px 20px;margin-bottom:10px;position:relative;overflow:hidden;}
.fr3-bal-card::before{content:'';position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.06);border-radius:50%;}
.fr3-bal-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.55);margin-bottom:6px;}
.fr3-bal-val{font-size:42px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1;}
.fr3-bal-sub{font-size:11px;color:rgba(255,255,255,.45);margin-top:6px;}
.fr3-bal-usd{background:#1a6b3a;}
.fr3-bal-ssp{background:#1a3a7a;}
.fr3-bal-comb{background:#0f0f0f;display:flex;align-items:center;justify-content:space-between;}
.fr3-rate-pill{background:rgba(255,255,255,.08);border-radius:10px;padding:6px 12px;text-align:center;}
/* Tabs */
.fr3-tabs{background:#fff;border-bottom:2px solid var(--border);display:flex;overflow-x:auto;scrollbar-width:none;}
.fr3-tabs::-webkit-scrollbar{display:none;}
/* N-04: Tab overflow hint — gradient fade on right edge */
.fr3-tabs-wrap{position:relative;}
.fr3-tabs-wrap::after{content:'';position:absolute;right:0;top:0;bottom:2px;width:36px;background:linear-gradient(to left,#fff 40%,transparent);pointer-events:none;z-index:1;}
.fr3-tab{padding:11px 14px;font-size:12px;font-weight:600;color:var(--mute);white-space:nowrap;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:4px;}
.fr3-tab.on{color:var(--red);border-bottom-color:var(--red);}
.fr3-badge{background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;}
/* Toolbar */
.fr3-tb{background:#fff;border-bottom:1px solid var(--border);padding:10px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.fr3-fi{padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;background:#fff;color:#0a0a0a;outline:none;}
.fr3-fi:focus{border-color:var(--red);}
.fr3-curr-btn{padding:6px 12px;border:1.5px solid var(--border);border-radius:20px;font-size:11px;font-weight:600;cursor:pointer;background:#fff;color:#374151;}
.fr3-curr-btn.on{background:var(--dark);color:#fff;border-color:var(--dark);}
.fr3-btn-red{background:var(--red);color:#fff;border:none;border-radius:9px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;}
.fr3-btn-dark{background:var(--dark);color:#fff;border:none;border-radius:9px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;}
/* Ledger cards */
.fr3-lc-wrap{padding:8px 12px;display:flex;flex-direction:column;gap:7px;}
.fr3-lc{background:#fff;border-radius:12px;border:1px solid var(--border);padding:12px 14px;display:flex;align-items:flex-start;gap:12px;}
.fr3-lc.pend{border-left:3px solid var(--amber);}
.fr3-lc.rej{border-left:3px solid #dc2626;opacity:.6;}
.fr3-lc-ic{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.fr3-lc-ic.in{background:#f0fdf4;}
.fr3-lc-ic.out{background:#fef2f2;}
.fr3-lc-body{flex:1;min-width:0;}
.fr3-lc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;}
.fr3-lc-desc{font-size:13px;font-weight:700;color:#0f0f0f;line-height:1.3;flex:1;word-break:break-word;}
.fr3-lc-desc small{font-size:11px;font-weight:400;color:#6b7280;display:block;margin-top:1px;}
.fr3-lc-amt{font-size:14px;font-weight:800;white-space:nowrap;flex-shrink:0;}
.fr3-lc-amt.in{color:var(--green);}
.fr3-lc-amt.out{color:#dc2626;}
.fr3-lc-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:4px;}
.fr3-lc-date{font-size:11px;color:var(--mute);}
.fr3-lc-cat{font-size:10px;background:#f1f5f9;color:#374151;padding:2px 7px;border-radius:10px;font-weight:500;}
.fr3-lc-stat{font-size:9px;font-weight:800;padding:2px 6px;border-radius:8px;}
.fr3-lc-stat.approved{background:#dcfce7;color:#15803d;}
.fr3-lc-stat.pending{background:#fef3c7;color:#92400e;}
.fr3-lc-stat.rejected{background:#fee2e2;color:#991b1b;}
.fr3-lc-stat.cancelled{background:#f1f5f9;color:#64748b;text-decoration:line-through;}
.fr3-empty{text-align:center;padding:40px 20px;color:var(--mute);font-size:13px;}
/* Summary cards */
.fr3-sum-card{background:#fff;border-radius:12px;border:1px solid var(--border);padding:16px 18px;margin-bottom:10px;}
.fr3-sum-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mute);margin-bottom:6px;}
.fr3-sum-val{font-size:28px;font-weight:900;letter-spacing:-1px;line-height:1;}
.fr3-sum-val.g{color:var(--green);}
.fr3-sum-val.r{color:#dc2626;}
.fr3-sum-val.b{color:#0f0f0f;}
.fr3-sum-sub{font-size:11px;color:var(--mute);margin-top:4px;}
/* Category breakdown */
.fr3-bk-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;}
.fr3-bk-row:last-child{border:none;}
.fr3-bk-lbl{font-size:12px;font-weight:600;flex:1;}
.fr3-bk-in{font-family:monospace;font-size:11px;font-weight:700;color:var(--green);min-width:70px;text-align:right;}
.fr3-bk-out{font-family:monospace;font-size:11px;font-weight:700;color:#dc2626;min-width:70px;text-align:right;}

/* ── Entry Modal ── */
.fr3-mo{display:none;position:fixed;inset:0;background:rgba(10,10,10,.65);z-index:9000;align-items:flex-end;justify-content:center;}
.fr3-mo.open{display:flex;}
.fr3-mb{background:#fff;border-radius:18px 18px 0 0;width:100%;max-width:560px;max-height:92dvh;overflow-y:auto;margin-bottom:calc(60px + env(safe-area-inset-bottom,0px));}
@media(min-width:600px){.fr3-mo{align-items:center;}.fr3-mb{border-radius:18px;margin:auto;}}
.fr3-mh{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;}
.fr3-mh.neutral{background:#0f0f0f;}
.fr3-mh.in{background:#1a6b3a;}
.fr3-mh.out{background:#7a1a1a;}
.fr3-mt{font-size:15px;font-weight:900;color:#fff;letter-spacing:.3px;}
.fr3-msub{font-size:11px;color:rgba(255,255,255,.5);margin-top:1px;}
.fr3-mclose{width:30px;height:30px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:none;cursor:pointer;font-size:14px;color:rgba(255,255,255,.7);}
.fr3-mbody{padding:18px 20px;}
/* Currency pills */
.fr3-curr-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}
.fr3-cpill{padding:10px 14px;border-radius:12px;border:2px solid var(--border);background:#fff;cursor:pointer;text-align:center;font-family:var(--font);transition:.12s;}
.fr3-cpill.sel{border-color:var(--dark);background:#0f0f0f;color:#fff;}
.fr3-cpill-lbl{font-size:13px;font-weight:700;}
.fr3-cpill-bal{font-size:10px;color:var(--mute);margin-top:2px;}
.fr3-cpill.sel .fr3-cpill-bal{color:rgba(255,255,255,.5);}
/* Direction cards */
.fr3-dir-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}
.fr3-dir-btn{padding:14px 10px;border-radius:12px;border:2px solid var(--border);background:#fff;cursor:pointer;text-align:center;font-family:var(--font);transition:.12s;}
.fr3-dir-btn.in.sel{border-color:var(--green);background:#f0fdf4;}
.fr3-dir-btn.out.sel{border-color:#dc2626;background:#fef2f2;}
.fr3-dir-ic{font-size:24px;margin-bottom:5px;}
.fr3-dir-lbl{font-size:13px;font-weight:700;color:#0f0f0f;}
.fr3-dir-sub{font-size:10px;color:var(--mute);margin-top:2px;}
/* Category chips */
.fr3-cats{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}
.fr3-cat{padding:12px 10px;border-radius:12px;border:2px solid var(--border);background:#fff;cursor:pointer;text-align:center;font-family:var(--font);transition:.12s;}
.fr3-cat.sel{border-color:var(--dark);background:#0f0f0f;}
.fr3-cat.sel .fr3-cat-ic,.fr3-cat.sel .fr3-cat-lbl{color:#fff;}
.fr3-cat-ic{font-size:20px;margin-bottom:4px;}
.fr3-cat-lbl{font-size:12px;font-weight:700;color:#0f0f0f;}
/* v4.9.10: Group tabs for Cash OUT (same pattern as Rupesh's cashbook) */
.fr3-grp-tabs{display:flex;gap:0;border-bottom:1.5px solid #e2e8f0;margin-bottom:10px;overflow-x:auto;grid-column:1/-1;}
.fr3-grp-tab{padding:8px 10px;font-size:11px;font-weight:600;cursor:pointer;border:none;background:none;color:#94a3b8;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s;flex-shrink:0;font-family:var(--font);}
@media(max-width:400px){.fr3-grp-tab{padding:7px 7px;font-size:10px;}}
.fr3-grp-tab:hover{color:#64748b;}
.fr3-grp-tab.sel{color:#1e293b;border-bottom-color:#1e293b;}
.fr3-grp-dot{display:inline-block;width:6px;height:6px;border-radius:3px;margin-right:4px;vertical-align:middle;}
/* Form fields */
.fr3-fg{margin-bottom:14px;}
.fr3-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);display:block;margin-bottom:5px;}
.fr3-inp{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none;font-family:var(--font);}
.fr3-inp:focus{border-color:var(--red);}
.fr3-sel{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:#fff;outline:none;cursor:pointer;font-family:var(--font);}
.fr3-sel:focus{border-color:var(--red);}
.fr3-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.fr3-aw{position:relative;}
.fr3-as{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:15px;font-weight:800;color:var(--mute);pointer-events:none;}
.fr3-ai{padding-left:28px!important;font-size:22px!important;font-weight:900!important;letter-spacing:-1px;}
/* Exchange breakdown */
.fr3-exch-strip{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:#065f46;font-weight:600;}
/* Handover strip */
.fr3-hov-strip{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;margin-bottom:14px;}
.fr3-hov-row{display:flex;justify-content:space-between;font-size:12px;color:#78350f;padding:3px 0;}
.fr3-hov-row.total{font-weight:800;font-size:13px;border-top:1px solid #fde68a;margin-top:5px;padding-top:8px;}
/* Save button */
.fr3-mfooter{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;position:sticky;bottom:0;background:#fff;}
.fr3-save{flex:1;background:var(--red);color:#fff;border:none;border-radius:10px;padding:13px 20px;font-size:14px;font-weight:700;cursor:pointer;}
.fr3-save:disabled{background:#d4d4d4;color:#9ca3af;cursor:not-allowed;box-shadow:none;}
.fr3-cancel{background:#f8fafc;color:#374151;border:1px solid var(--border);border-radius:10px;padding:13px 16px;font-size:13px;font-weight:500;cursor:pointer;}
</style>

<div class="fr3-wrap" style="padding-bottom:80px;">

<!-- ══ TOP BAR ══ -->
<?php if (!empty($showMobileBack)): ?>
<!-- Mobile: public.php already shows "← Field Register" header — just show Add Entry button -->
<div style="padding:8px 14px 0;display:flex;justify-content:flex-end;">
  <button class="fr3-fab" onclick="fr3Open()">＋ Add Entry</button>
</div>
<?php else: ?>
<!-- Desktop / admin: show full dark bar with title + button -->
<div class="fr3-bar">
  <span class="fr3-title">📋 Field Register</span>
  <button class="fr3-fab" onclick="fr3Open()">＋ Add Entry</button>
</div>
<?php endif; ?>

<!-- ══ BALANCE CARDS ══ -->
<div class="fr3-cards-wrap">

  <?php
    $fr_usd_in_bag = max(0, $fr_usd_holding);
    $fr_owed_by_company = $fr_usd_is_negative ? abs($fr_usd_holding) : 0;
    $fr_actual_combined = round($fr_usd_in_bag + $fr_ssp_usd_eq, 2);
  ?>

  <!-- Main balance card — dark hero style matching My Cash -->
  <?php if ($fr_is_support_role): ?>
  <!-- ══ SUPPORT ROLE HERO: dual currency (SSP + USD side by side) ══ -->
  <div style="background:linear-gradient(135deg,#1e293b,#0f172a);border-radius:16px;padding:18px 20px;margin-bottom:10px;">
    <div style="font-size:10px;font-weight:700;color:#94a3b8;margin-bottom:8px;"><?php echo htmlspecialchars($fr_agentName); ?></div>

    <!-- SSP + USD side by side -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
      <div style="background:rgba(251,146,60,.12);border:1px solid rgba(251,146,60,.25);border-radius:14px;padding:12px 10px;">
        <div style="font-size:9px;font-weight:800;color:#fdba74;text-transform:uppercase;letter-spacing:.8px;">🇸🇸 SSP Bag</div>
        <div style="font-size:26px;font-weight:900;color:#fb923c;letter-spacing:-1px;margin-top:2px;"><?php echo number_format($fr_ssp_holding, 0); ?></div>
        <?php if ($fr_ssp_holding > 0): ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">≈ $<?php echo number_format($fr_ssp_usd_eq, 2); ?> @ <?php echo number_format($fr_rate, 0); ?></div><?php else: ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">no SSP received</div><?php endif; ?>
      </div>
      <div style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25);border-radius:14px;padding:12px 10px;">
        <div style="font-size:9px;font-weight:800;color:#4ade80;text-transform:uppercase;letter-spacing:.8px;">💵 USD Cash</div>
        <div style="font-size:26px;font-weight:900;color:<?php echo $fr_usd_in_bag > 0 ? '#4ade80' : '#475569'; ?>;letter-spacing:-1px;margin-top:2px;">$<?php echo number_format($fr_usd_in_bag, 0); ?></div>
        <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?php echo $fr_usd_in_bag > 0 ? 'in bag' : 'settled'; ?></div>
      </div>
    </div>

    <?php if($fr_ssp_pending_cnt > 0): ?>
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(251,191,36,.12);border-radius:20px;padding:4px 12px;margin-bottom:6px;">
      <span style="font-size:11px;font-weight:700;color:#fbbf24;">⏳ <?php echo $fr_ssp_pending_cnt; ?> expense(s) awaiting approval</span>
    </div>
    <?php endif; ?>

    <!-- Weekly activity -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:4px;padding-top:12px;border-top:1px solid rgba(255,255,255,.06);">
      <div style="text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Received</div>
        <div style="font-size:16px;font-weight:900;color:#4ade80;"><?php echo number_format($fr_ssp_week_in, 0); ?></div>
        <div style="font-size:9px;color:#64748b;">SSP this week</div>
      </div>
      <div style="text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Spent</div>
        <div style="font-size:16px;font-weight:900;color:#f87171;"><?php echo number_format($fr_ssp_week_out, 0); ?></div>
        <div style="font-size:9px;color:#64748b;">SSP this week</div>
      </div>
      <div style="text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Pending</div>
        <div style="font-size:16px;font-weight:900;color:#fbbf24;"><?php echo $fr_ssp_pending_cnt; ?></div>
        <div style="font-size:9px;color:#64748b;">awaiting approval</div>
      </div>
    </div>
  </div>

  <?php else: ?>
  <!-- ══ COLLECTION ROLE HERO: USD-first (Diko, BBC, sales) ══ -->
  <div style="background:linear-gradient(135deg,#1e293b,#0f172a);border-radius:16px;padding:18px 20px;margin-bottom:10px;">
    <div style="font-size:10px;font-weight:700;color:#94a3b8;margin-bottom:4px;"><?php echo htmlspecialchars($fr_agentName); ?></div>

    <?php if ($fr_usd_is_negative): ?>
    <!-- Company owes Diko — show amount as her balance -->
    <div style="font-size:36px;font-weight:900;color:#f59e0b;letter-spacing:-2px;line-height:1;">$<?php echo number_format($fr_owed_by_company, 2); ?></div>
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);border-radius:20px;padding:4px 12px;margin-top:8px;">
      <span style="font-size:11px;font-weight:700;color:#fbbf24;">💡 Rupesh owes you this amount</span>
    </div>
    <?php elseif ($fr_usd_in_bag > 0): ?>
    <!-- Diko has USD cash -->
    <div style="font-size:36px;font-weight:900;color:#4ade80;letter-spacing:-2px;line-height:1;">$<?php echo number_format($fr_usd_in_bag, 2); ?></div>
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(74,222,128,.12);border-radius:20px;padding:4px 12px;margin-top:8px;">
      <span style="font-size:11px;font-weight:700;color:#4ade80;">💵 USD cash in your bag</span>
    </div>
    <?php else: ?>
    <!-- All settled -->
    <div style="font-size:36px;font-weight:900;color:#94a3b8;letter-spacing:-2px;line-height:1;">$0.00</div>
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(148,163,184,.1);border-radius:20px;padding:4px 12px;margin-top:8px;">
      <span style="font-size:11px;font-weight:700;color:#94a3b8;">✅ All settled — no cash pending</span>
    </div>
    <?php endif; ?>

    <?php if($fr_pending_cnt > 0): ?>
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(251,191,36,.12);border-radius:20px;padding:4px 10px;margin-top:6px;margin-left:4px;">
      <span style="font-size:10px;font-weight:700;color:#fbbf24;">⏳ <?php echo $fr_pending_cnt; ?> waiting for Rupesh</span>
    </div>
    <?php endif; ?>

    <!-- Recent activity strip -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,.08);">
      <div style="text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Today</div>
        <?php if ($fr_today_collected > 0): ?>
        <div style="font-size:16px;font-weight:900;color:#4ade80;">+$<?php echo number_format($fr_today_collected,0); ?></div>
        <div style="font-size:9px;color:#64748b;">received</div>
        <?php elseif ($fr_today_handovers > 0): ?>
        <div style="font-size:16px;font-weight:900;color:#f87171;">-$<?php echo number_format($fr_today_handovers,0); ?></div>
        <div style="font-size:9px;color:#64748b;">handed</div>
        <?php else: ?>
        <div style="font-size:14px;font-weight:700;color:#475569;">—</div>
        <div style="font-size:9px;color:#64748b;">no activity</div>
        <?php endif; ?>
      </div>
      <div style="text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Yesterday</div>
        <?php if ($fr_yest_collected > 0): ?>
        <div style="font-size:16px;font-weight:900;color:#4ade80;">+$<?php echo number_format($fr_yest_collected,0); ?></div>
        <div style="font-size:9px;color:#64748b;">received</div>
        <?php elseif ($fr_yest_handovers > 0): ?>
        <div style="font-size:16px;font-weight:900;color:#f87171;">-$<?php echo number_format($fr_yest_handovers,0); ?></div>
        <div style="font-size:9px;color:#64748b;">handed</div>
        <?php else: ?>
        <div style="font-size:14px;font-weight:700;color:#475569;">—</div>
        <div style="font-size:9px;color:#64748b;">no activity</div>
        <?php endif; ?>
      </div>
      <div style="text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">This Week</div>
        <div style="font-size:16px;font-weight:900;color:#60a5fa;">$<?php echo number_format($fr_week_collected,0); ?></div>
        <div style="font-size:9px;color:#64748b;"><?php echo $fr_week_collections; ?> collections</div>
      </div>
    </div>
  </div>

  <?php if ($fr_ssp_holding > 0): ?>
  <!-- SSP card — only shown if she has SSP -->
  <div class="fr3-bal-card fr3-bal-ssp">
    <div class="fr3-bal-lbl">🇸🇸 SSP — Cash in Bag</div>
    <div class="fr3-bal-val"><?php echo number_format($fr_ssp_holding, 0); ?> <span style="font-size:16px;font-weight:700;color:rgba(255,255,255,.45);">SSP</span></div>
    <div class="fr3-bal-sub">≈ $<?php echo number_format($fr_ssp_usd_eq, 2); ?> USD at <?php echo number_format($fr_rate,0); ?> rate</div>
  </div>
  <?php endif; ?>

  <?php endif; /* end support_role vs collection_role hero */ ?>

</div>

<!-- ══ TABS ══ -->
<div class="fr3-tabs-wrap">
<div class="fr3-tabs">
  <?php
  $fr_tabs = [
    ['ledger',  '📋 Ledger',  ''],
    ['pending', '⏳ Pending', $fr_pending_cnt > 0 ? (string)$fr_pending_cnt : ''],
    ['summary', '📊 Summary', ''],
  ];
  foreach ($fr_tabs as [$tid,$tlbl,$tbadge]):
  ?>
  <div class="fr3-tab <?php echo $fr_view===$tid?'on':''; ?>"
    onclick="location.href='?page=dashboard&tab=wallet&fr_view=<?php echo $tid; ?><?php echo $fr_curr?"&fr_curr=$fr_curr":""; ?>'">
    <?php echo $tlbl; ?>
    <?php if($tbadge): ?><span class="fr3-badge"><?php echo $tbadge; ?></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
</div><!-- /fr3-tabs-wrap -->

<!-- ══ TOOLBAR ══ -->
<div class="fr3-tb">
  <button class="fr3-curr-btn <?php echo $fr_curr===''?'on':''; ?>"
    onclick="location.href='?page=dashboard&tab=wallet&fr_view=<?php echo $fr_view; ?>'">All</button>
  <button class="fr3-curr-btn <?php echo $fr_curr==='USD'?'on':''; ?>"
    onclick="location.href='?page=dashboard&tab=wallet&fr_view=<?php echo $fr_view; ?>&fr_curr=USD<?php echo $fr_from?"&fr_from=$fr_from":""; ?><?php echo $fr_to?"&fr_to=$fr_to":""; ?>'">💵 USD</button>
  <button class="fr3-curr-btn <?php echo $fr_curr==='SSP'?'on':''; ?>"
    onclick="location.href='?page=dashboard&tab=wallet&fr_view=<?php echo $fr_view; ?>&fr_curr=SSP<?php echo $fr_from?"&fr_from=$fr_from":""; ?><?php echo $fr_to?"&fr_to=$fr_to":""; ?>'">🇸🇸 SSP</button>
  <input type="date" class="fr3-fi" value="<?php echo htmlspecialchars($fr_from); ?>"
    onchange="location.href='?page=dashboard&tab=wallet&fr_view=<?php echo $fr_view; ?><?php echo $fr_curr?"&fr_curr=$fr_curr":""; ?>&fr_from='+this.value+'<?php echo $fr_to?"&fr_to=$fr_to":""; ?>'">
  <input type="date" class="fr3-fi" value="<?php echo htmlspecialchars($fr_to); ?>"
    onchange="location.href='?page=dashboard&tab=wallet&fr_view=<?php echo $fr_view; ?><?php echo $fr_curr?"&fr_curr=$fr_curr":""; ?><?php echo $fr_from?"&fr_from=$fr_from":""; ?>&fr_to='+this.value">
  <button class="fr3-btn-red" onclick="fr3Open()">＋ Add Entry</button>
  <button class="fr3-btn-dark" onclick="fr3ExportCSV()">↓ CSV</button>
</div>

<!-- ══ LEDGER VIEW ══ -->
<?php if($fr_view === 'ledger'): ?>
<div class="fr3-lc-wrap">
<?php if(empty($fr_ledger)): ?>
  <div class="fr3-empty">No entries yet.<br><small>Tap + Add Entry to log a collection or expense.</small></div>
<?php else: ?>
<?php foreach($fr_ledger as $row): ?>
<?php
  $isIn  = $row['dir'] === 'in';
  $isSsp = $row['currency'] === 'SSP';
  $stat  = $row['status'] ?? 'approved';
  $amtDisplay = $isSsp
    ? number_format($row['ssp_amount'] ?? 0, 0).' SSP'
    : '$'.number_format($row['amount'], 2);
  $catIcons = ['Collection'=>'💰','Expense'=>'🧾','Handover'=>'🤝',
    'SSP Received'=>'🇸🇸','Exchange'=>'🔄','Staff Payment'=>'👤',
    'Advance Received'=>'💵','Advance Given'=>'💸','Fuel'=>'⛽','Transport'=>'🚗','Vehicle'=>'🚗'];
  $ic = $catIcons[$row['category']] ?? ($isIn ? '⬆️' : '⬇️');
?>
<div class="fr3-lc <?php echo $stat==='pending'?'pend':($stat==='rejected'||$stat==='cancelled'?'rej':''); ?>">
  <div class="fr3-lc-ic <?php echo $isIn?'in':'out'; ?>"><?php echo $ic; ?></div>
  <div class="fr3-lc-body">
    <div class="fr3-lc-top">
      <div class="fr3-lc-desc">
        <?php if ($row['source'] === 'collection'): ?>
          <?php echo htmlspecialchars($row['customer_name'] ?? $row['desc'] ?: 'Unknown Customer'); ?>
          <?php if (!empty($row['invoice_id'])): ?>
            <small>Inv #<?php echo htmlspecialchars($row['invoice_id']); ?></small>
          <?php endif; ?>
        <?php else: ?>
          <?php echo htmlspecialchars($row['desc'] ?: $row['category']); ?>
        <?php endif; ?>
      </div>
      <div class="fr3-lc-amt <?php echo $isIn?'in':'out'; ?>"><?php echo ($isIn?'+':'-').$amtDisplay; ?></div>
    </div>
    <div class="fr3-lc-meta">
      <span class="fr3-lc-date"><?php echo date('d M Y', strtotime($row['date'])); ?></span>
      <span class="fr3-lc-cat"><?php echo htmlspecialchars($row['category']); ?></span>
      <span class="fr3-lc-stat <?php echo $stat; ?>"><?php if ($row["source"]==="collection") echo $stat==="approved" ? "✅ CRM Synced" : "⏳ CRM Pending"; else echo ucfirst($stat); ?></span>
      <?php if (!empty($row['photo'])):
        $_expNum = str_replace('EXP-', '', $row['id'] ?? '');
      ?><a href="javascript:void(0)" onclick="dnLbOpen('?page=api&action=expense_photo&id=<?= urlencode($_expNum) ?>')" style="font-size:11px;color:#7C3AED;font-weight:700;text-decoration:none;cursor:pointer;">📎 Receipt</a><?php endif; ?>
      <?php
      // Cancel button: show for pending expenses OR auto-approved expenses submitted today
      $canCancel = false;
      if ($row['source'] === 'expense') {
          if ($stat === 'pending') {
              $canCancel = true;
          } elseif ($stat === 'approved' && !empty($row['auto_approved'])) {
              // Auto-approved: allow cancel same day only
              $submittedDay = substr($row['submitted_at'] ?? $row['date'] ?? '', 0, 10);
              $canCancel = ($submittedDay === date('Y-m-d'));
          }
      }
      ?>
      <?php if ($canCancel): ?>
      <form method="POST" style="display:inline;margin-left:auto;" onsubmit="return confirm('Cancel this expense? <?= $stat === 'approved' ? 'It was auto-approved — cancelling will reverse it.' : '' ?>')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cancel_expense">
        <input type="hidden" name="expense_id" value="<?= (int)str_replace('EXP-','',$row['id']) ?>">
        <button type="submit" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">✕ Cancel</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ══ PENDING VIEW ══ -->
<?php elseif($fr_view === 'pending'): ?>
<div class="fr3-lc-wrap">
<?php if(empty($fr_pending_all)): ?>
  <div class="fr3-empty">✅ No pending items.<br><small>All expenses and handovers have been processed.</small></div>
<?php else: ?>
  <?php foreach($fr_pending_all as $row):
    $isExp = isset($row['collector_id']) && !isset($row['from_id']);
    $isHov = isset($row['from_id']);
    $isCin = isset($row['collector_id']) && !isset($row['from_id']) && !isset($row['category_raw']);
    $typ = $isHov ? 'Handover' : (($row['currency']??'USD')==='SSP'?'SSP Expense':'USD Expense');
    $amt = ($row['currency']??'USD')==='SSP'
      ? number_format($row['ssp_amount']??0,0).' SSP'
      : '$'.number_format($row['amount']??0,2);
  ?>
  <div class="fr3-lc pend">
    <div class="fr3-lc-ic out">⏳</div>
    <div class="fr3-lc-body">
      <div class="fr3-lc-top">
        <div class="fr3-lc-desc"><?php echo htmlspecialchars($row['description'] ?? $row['note'] ?? $typ); ?></div>
        <div class="fr3-lc-amt out"><?php echo $amt; ?></div>
      </div>
      <div class="fr3-lc-meta">
        <span class="fr3-lc-date"><?php echo date('d M Y', strtotime($row['submitted_at'] ?? $row['created_at'] ?? 'now')); ?></span>
        <span class="fr3-lc-cat"><?php echo $typ; ?></span>
        <span class="fr3-lc-stat pending">Awaiting Rupesh</span>
        <?php if ($isExp && !empty($row['id'])): ?>
        <form method="POST" style="display:inline;margin-left:auto;" onsubmit="return confirm('Cancel this pending expense?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="cancel_expense">
          <input type="hidden" name="expense_id" value="<?= (int)$row['id'] ?>">
          <button type="submit" style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;">✕ Cancel</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ══ SUMMARY VIEW ══ -->
<?php elseif($fr_view === 'summary'): ?>
<div style="padding:14px;">
  <!-- USD summary -->
  <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mute);margin-bottom:8px;">💵 USD</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
    <div class="fr3-sum-card">
      <div class="fr3-sum-lbl">Total IN</div>
      <div class="fr3-sum-val g">$<?php echo number_format($fr_sum_usd_in,2); ?></div>
    </div>
    <div class="fr3-sum-card">
      <div class="fr3-sum-lbl">Total OUT</div>
      <div class="fr3-sum-val r">$<?php echo number_format($fr_sum_usd_out,2); ?></div>
    </div>
  </div>
  <div class="fr3-sum-card" style="margin-bottom:14px;">
    <div class="fr3-sum-lbl">📊 Net Flow</div>
    <div class="fr3-sum-val <?php echo ($fr_sum_usd_in-$fr_sum_usd_out)>=0?'g':'r'; ?>">
      $<?php echo number_format($fr_sum_usd_in-$fr_sum_usd_out,2); ?>
      <?php echo ($fr_sum_usd_in-$fr_sum_usd_out)>=0?'▲':'▼'; ?>
    </div>
  </div>
  <div class="fr3-sum-card" style="margin-bottom:20px;">
    <div class="fr3-sum-lbl">🏦 Current USD Holding</div>
    <div class="fr3-sum-val b">$<?php echo number_format($fr_usd_holding,2); ?></div>
  </div>

  <!-- SSP summary -->
  <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mute);margin-bottom:8px;">🇸🇸 SSP</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
    <div class="fr3-sum-card">
      <div class="fr3-sum-lbl">Total IN</div>
      <div class="fr3-sum-val g"><?php echo number_format($fr_sum_ssp_in,0); ?></div>
    </div>
    <div class="fr3-sum-card">
      <div class="fr3-sum-lbl">Total OUT</div>
      <div class="fr3-sum-val r"><?php echo number_format($fr_sum_ssp_out,0); ?></div>
    </div>
  </div>
  <div class="fr3-sum-card">
    <div class="fr3-sum-lbl">🏦 Current SSP Holding</div>
    <div class="fr3-sum-val b"><?php echo number_format($fr_ssp_holding,0); ?> SSP</div>
    <div class="fr3-sum-sub">≈ $<?php echo number_format($fr_ssp_usd_eq,2); ?> USD @ <?php echo number_format($fr_rate,0); ?> SSP/USD</div>
  </div>
</div>
<?php endif; ?>

</div><!-- /fr3-wrap -->

<!-- ══════════════════════════════════════════════
     FIELD REGISTER ENTRY MODAL
     ══════════════════════════════════════════════ -->
<div class="fr3-mo" id="fr3Modal">
<div class="fr3-mb">

  <!-- Header (color changes with direction) -->
  <div class="fr3-mh neutral" id="fr3MH">
    <div>
      <div class="fr3-mt" id="fr3MTitle">Add Entry</div>
      <div class="fr3-msub" id="fr3MSub">Select direction to begin</div>
    </div>
    <button class="fr3-mclose" onclick="fr3Close()">✕</button>
  </div>

  <div class="fr3-mbody">

    <!-- ═══ STEP 1: Currency + Direction + Category ═══ -->
    <div id="fr3Step1">

    <!-- Currency pills -->
    <div class="fr3-curr-row">
      <div class="fr3-cpill" id="fr3PillUSD" onclick="fr3SetCurr('USD')">
        <div class="fr3-cpill-lbl">💵 USD</div>
        <div class="fr3-cpill-bal" id="fr3PillUSDbal">$<?php echo number_format($fr_usd_holding,2); ?></div>
      </div>
      <div class="fr3-cpill sel" id="fr3PillSSP" onclick="fr3SetCurr('SSP')">
        <div class="fr3-cpill-lbl">🇸🇸 SSP</div>
        <div class="fr3-cpill-bal" id="fr3PillSSPbal"><?php echo number_format($fr_ssp_holding,0); ?></div>
      </div>
    </div>

    <!-- Direction cards -->
    <div class="fr3-dir-row">
      <div class="fr3-dir-btn in" id="fr3DirIn" onclick="fr3SetDir('in')">
        <div class="fr3-dir-ic">⬆️</div>
        <div class="fr3-dir-lbl" id="fr3DirInLbl">SSP IN</div>
        <div class="fr3-dir-sub" id="fr3DirInSub">Received · Exchange</div>
      </div>
      <div class="fr3-dir-btn" id="fr3DirExch" onclick="fr3SetDir('exchange')" style="background:#f5f3ff;border-color:#c4b5fd;">
        <div class="fr3-dir-ic">💱</div>
        <div class="fr3-dir-lbl">Exchange</div>
        <div class="fr3-dir-sub">USD ↔ SSP</div>
      </div>
      <div class="fr3-dir-btn out" id="fr3DirOut" onclick="fr3SetDir('out')">
        <div class="fr3-dir-ic">⬇️</div>
        <div class="fr3-dir-lbl">Cash OUT</div>
        <div class="fr3-dir-sub" id="fr3DirOutSub">Expense · Handover</div>
      </div>
    </div>

    <!-- Category section (hidden until direction chosen) -->
    <div id="fr3CatSection" style="display:none;">
      <div class="fr3-lbl" style="margin-bottom:8px;">CATEGORY</div>
      <div class="fr3-cats" id="fr3CatGrid"></div>
    </div>

    <!-- Next button (shown after category selected) -->
    <div id="fr3NextWrap" style="display:none;margin-top:14px;">
      <button type="button" onclick="fr3GoStep2()" id="fr3NextBtn" style="width:100%;padding:14px;background:#1e293b;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;">
        Next →
      </button>
    </div>

    </div><!-- /Step 1 -->

    <!-- ═══ STEP 2: Details (recipient, amount, description, photo) ═══ -->
    <div id="fr3Step2" style="display:none;">

    <!-- Back button -->
    <div style="margin-bottom:12px;">
      <button type="button" onclick="fr3GoStep1()" style="background:none;border:none;color:#6b7280;font-size:13px;font-weight:700;cursor:pointer;padding:4px 0;">← Back</button>
      <span style="font-size:12px;color:#9ca3af;margin-left:8px;" id="fr3Step2Label"></span>
    </div>

    <!-- Fields section -->
    <div id="fr3FieldSection">

      <!-- Expense type (shown for Expense category only) -->
      <div class="fr3-fg" id="fr3ExpTypeWrap" style="display:none;">
        <label class="fr3-lbl">EXPENSE TYPE</label>
        <select class="fr3-sel" id="fr3ExpType" name="expense_type">
          <option value="Fuel">Fuel</option>
          <option value="Food & Drink">Food &amp; Drink</option>
          <option value="Transport">Transport</option>
          <option value="Airtime">Airtime</option>
          <option value="Stationery">Stationery</option>
          <option value="Other">Other Field</option>
        </select>
      </div>

      <!-- Staff payment type (shown for Staff Salary / Staff Advance) -->
      <div class="fr3-fg" id="fr3StaffTypeWrap" style="display:none;">
        <label class="fr3-lbl">PAYMENT TYPE</label>
        <select class="fr3-sel" id="fr3StaffType" name="staff_payment_type">
          <option value="Salary">Monthly Salary</option>
          <option value="Advance">Cash Advance</option>
          <option value="Fuel">Fuel Allowance</option>
          <option value="Transport">Transport Allowance</option>
          <option value="Bonus">Bonus</option>
          <option value="Other">Other</option>
        </select>
      </div>

      <!-- Staff name (shown for Staff Salary / Staff Advance / Staff Payment SSP) -->
      <div class="fr3-fg" id="fr3StaffNameWrap" style="display:none;">
        <label class="fr3-lbl">STAFF NAME *</label>
        <input type="text" class="fr3-inp" id="fr3StaffName" name="staff_name" placeholder="Type to search staff…" list="fr3StaffDatalist" autocomplete="off">
        <datalist id="fr3StaffDatalist">
          <?php
          $_staffForList = $auth->getAllRetailers();
          foreach ($_staffForList as $_sf):
            if (!($_sf['is_active'] ?? true)) continue;
            $_sfRole = ucfirst(str_replace('_', ' ', $_sf['role'] ?? 'staff'));
          ?>
          <option value="<?= h($_sf['name']) ?>" label="<?= h($_sf['name']) ?> — <?= h($_sfRole) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>

      <!-- Commission fields (shown for Customer Commission) -->
      <div id="fr3CommWrap" style="display:none;">
        <div class="fr3-fg">
          <label class="fr3-lbl">CUSTOMER NAME *</label>
          <input type="text" class="fr3-inp" id="fr3CommCustName" placeholder="e.g. John Deng" autocomplete="off" oninput="fr3UpdateSave()">
        </div>
        <div class="fr3-fg">
          <label class="fr3-lbl">CUSTOMER PHONE *</label>
          <input type="tel" class="fr3-inp" id="fr3CommCustPhone" placeholder="e.g. +211 912 345 678" autocomplete="off" oninput="fr3UpdateSave()">
        </div>
        <div class="fr3-fg">
          <label class="fr3-lbl">REASON</label>
          <select class="fr3-sel" id="fr3CommReason">
            <option value="Referral Bonus">Referral Bonus</option>
            <option value="Loyalty Discount">Loyalty Discount</option>
            <option value="Early Payment">Early Payment Discount</option>
            <option value="Installation Discount">Installation Discount</option>
            <option value="Upgrade Incentive">Upgrade Incentive</option>
            <option value="Retention Offer">Retention Offer</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="fr3-fg">
          <label class="fr3-lbl">LINKED INVOICE <span style="color:var(--mute);font-weight:500;text-transform:none;">(optional)</span></label>
          <input type="text" class="fr3-inp" id="fr3CommInvoice" placeholder="e.g. INV-0045 or CRM invoice #" autocomplete="off">
        </div>
      </div>

      <!-- Vehicle subcategory picker (shown for Vehicle category) -->
      <div id="fr3VehicleWrap" style="display:none;">
        <div class="fr3-fg">
          <label class="fr3-lbl">VEHICLE EXPENSE TYPE *</label>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;" id="fr3VehicleGrid">
            <div class="fr3-cat" data-vtype="Fuel / Diesel" onclick="fr3SetVehicle(this)" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">⛽ Fuel</div>
            <div class="fr3-cat" data-vtype="Maintenance" onclick="fr3SetVehicle(this)" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">🔧 Maintenance</div>
            <div class="fr3-cat" data-vtype="Spare Parts" onclick="fr3SetVehicle(this)" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">⚙️ Spare Parts</div>
            <div class="fr3-cat" data-vtype="Insurance" onclick="fr3SetVehicle(this)" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">🛡️ Insurance</div>
            <div class="fr3-cat" data-vtype="Registration" onclick="fr3SetVehicle(this)" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">📋 Registration</div>
            <div class="fr3-cat" data-vtype="Tyre" onclick="fr3SetVehicle(this)" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">🛞 Tyre</div>
          </div>
        </div>
        <div class="fr3-fg">
          <label class="fr3-lbl">VEHICLE / PLATE <span style="color:var(--mute);font-weight:500;text-transform:none;">(optional)</span></label>
          <input type="text" class="fr3-inp" id="fr3VehiclePlate" placeholder="e.g. SSD-1234 or Boda boda">
        </div>
      </div>

      <!-- Exchange breakdown strip (shown for Exchange category) -->
      <div id="fr3ExchStrip" style="display:none;" class="fr3-exch-strip">
        🔄 Exchange: USD you give → SSP you receive (rate auto-calculated)
      </div>

      <!-- USD given (Exchange only) -->
      <div class="fr3-fg" id="fr3UsdGivenWrap" style="display:none;">
        <label class="fr3-lbl">USD YOU GAVE</label>
        <div class="fr3-aw">
          <span class="fr3-as">$</span>
          <input type="number" class="fr3-inp fr3-ai" id="fr3UsdGiven" name="usd_given" placeholder="0.00" step="0.01" min="0" oninput="fr3CalcRate()">
        </div>
      </div>

      <!-- Amount field -->
      <div class="fr3-fg" id="fr3AmtWrap">
        <label class="fr3-lbl" id="fr3AmtLbl">AMOUNT (USD)</label>
        <div class="fr3-aw">
          <span class="fr3-as" id="fr3AmtPrefix">$</span>
          <input type="number" class="fr3-inp fr3-ai" id="fr3Amt" name="amount" placeholder="0.00" step="0.01" min="0" oninput="fr3UpdateSave();fr3CalcRate()">
        </div>
      </div>

      <!-- Auto-rate display (Exchange only) -->
      <div id="fr3RateDisplay" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#065f46;font-weight:600;"></div>

      <!-- Handover breakdown strip -->
      <div id="fr3HovStrip" style="display:none;" class="fr3-hov-strip">
        <div class="fr3-hov-row"><span>Collections</span><span>$<?php echo number_format($fr_usd_collected,2); ?></span></div>
        <div class="fr3-hov-row"><span>Expenses paid</span><span>-$<?php echo number_format($fr_usd_exp_approv,2); ?></span></div>
        <div class="fr3-hov-row"><span>Already handed over</span><span>-$<?php echo number_format($fr_usd_hov_conf,2); ?></span></div>
        <div class="fr3-hov-row total"><span>Holding (to hand over)</span><span>$<?php echo number_format($fr_usd_holding,2); ?></span></div>
      </div>

      <!-- Recipient picker (handover + give advance) -->
      <div id="fr3HovRecipient" style="display:none;" class="fr3-fg">
        <label class="fr3-lbl" id="fr3RecipLabel">HAND OVER TO</label>
        <input type="text" class="fr3-inp" id="fr3StaffSearch" placeholder="🔍 Type name to search..." autocomplete="off"
               oninput="fr3FilterStaff()" onfocus="document.getElementById('fr3StaffList').style.display=''"
               style="font-size:14px;font-weight:600;margin-bottom:4px;">
        <div id="fr3StaffSelected" style="display:none;padding:10px 14px;background:#f0fdf4;border:2px solid #15803d;border-radius:10px;margin-bottom:4px;cursor:pointer;" onclick="fr3ClearStaff()">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div><span style="font-size:14px;font-weight:800;color:#15803d;" id="fr3SelName"></span><br><span style="font-size:11px;color:#6b7280;" id="fr3SelRole"></span></div>
                <span style="color:#9ca3af;font-size:18px;">✕</span>
            </div>
        </div>
        <div id="fr3StaffList" style="display:none;max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
          <?php
          $allStaff = $auth->getAllRetailers();
          foreach ($allStaff as $s):
            if ((int)$s['id'] === (int)$retailer['id']) continue;
            if (!($s['is_active'] ?? true)) continue;
            $sRole = $s['role'] ?? 'sales';
            $sRoleLabel = ucfirst(str_replace('_', ' ', $sRole));
          ?>
          <div class="fr3-staff-item" data-id="<?= (int)$s['id'] ?>" data-name="<?= h($s['name']) ?>" data-role="<?= h($sRoleLabel) ?>"
               onclick="fr3SelectStaff(this)" style="padding:12px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;font-size:13px;">
            <strong style="color:#1e293b;"><?= h($s['name']) ?></strong>
            <span style="color:#9ca3af;font-size:11px;margin-left:6px;"><?= h($sRoleLabel) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <!-- Hidden fields for form submission -->
        <select id="fr3HovToSelect" style="display:none;">
          <?php foreach ($allStaff as $s):
            if ((int)$s['id'] === (int)$retailer['id']) continue;
            if (!($s['is_active'] ?? true)) continue;
          ?>
          <option value="<?= (int)$s['id'] ?>" data-name="<?= h($s['name']) ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Purpose picker (give advance only) -->
      <div id="fr3AdvPurpose" style="display:none;" class="fr3-fg">
        <label class="fr3-lbl">PURPOSE</label>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;" id="fr3PurposeGrid">
          <div class="fr3-cat" data-purpose="fuel" onclick="fr3SetPurpose('fuel')" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">⛽ Fuel</div>
          <div class="fr3-cat" data-purpose="transport" onclick="fr3SetPurpose('transport')" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">🚗 Transport</div>
          <div class="fr3-cat" data-purpose="parts" onclick="fr3SetPurpose('parts')" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">🔧 Parts</div>
          <div class="fr3-cat" data-purpose="food" onclick="fr3SetPurpose('food')" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">🍽 Food</div>
          <div class="fr3-cat" data-purpose="allowance" onclick="fr3SetPurpose('allowance')" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">💰 Allowance</div>
          <div class="fr3-cat" data-purpose="misc" onclick="fr3SetPurpose('misc')" style="padding:8px;font-size:11px;text-align:center;border:2px solid #e2e8f0;border-radius:10px;cursor:pointer;">📦 Other</div>
        </div>
      </div>

      <!-- Description -->
      <div class="fr3-fg">
        <label class="fr3-lbl">DESCRIPTION *</label>
        <input type="text" class="fr3-inp" id="fr3Desc" name="description" placeholder="e.g. Fuel for site visit — Gudele" required>
      </div>

      <!-- Reference (optional) -->
      <div class="fr3-fg">
        <label class="fr3-lbl">REFERENCE <span style="color:var(--mute);font-weight:500;text-transform:none;">(optional)</span></label>
        <input type="text" class="fr3-inp" id="fr3Ref" name="reference" placeholder="e.g. INV-0045">
      </div>

      <!-- Receipt photo (optional) -->
      <div class="fr3-fg">
        <label class="fr3-lbl">RECEIPT PHOTO <span style="color:var(--mute);font-weight:500;text-transform:none;">(optional)</span></label>
        <label style="display:flex;align-items:center;gap:10px;padding:12px 14px;border:1.5px dashed var(--border);border-radius:9px;cursor:pointer;font-size:12px;color:var(--mute);">
          <span style="font-size:20px;">📷</span>
          <span id="fr3PhotoLbl">Tap to capture receipt</span>
          <input type="file" id="fr3Photo" name="photo" accept="image/*" capture="environment" style="display:none;" onchange="fr3PhotoChanged(this)">
        </label>
      </div>

    </div><!-- /FieldSection -->
    </div><!-- /Step 2 -->
  </div><!-- /mbody -->

  <!-- Sticky footer (only visible on step 2) -->
  <div class="fr3-mfooter" id="fr3Footer" style="display:none;">
    <button class="fr3-cancel" onclick="fr3GoStep1()">← Back</button>
    <button class="fr3-save" id="fr3SaveBtn" onclick="fr3Submit()" disabled>Save Entry</button>
  </div>

  <!-- Hidden forms -->
  <form id="fr3FormExp" method="POST" enctype="multipart/form-data" style="display:none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="log_expense">
    <input type="hidden" name="currency" id="fr3fCurrency">
    <input type="hidden" name="category" id="fr3fCategory">
    <input type="hidden" name="expense_type" id="fr3fExpType">
    <input type="hidden" name="amount" id="fr3fAmount">
    <input type="hidden" name="ssp_amount" id="fr3fSspAmount">
    <input type="hidden" name="description" id="fr3fDesc">
    <input type="hidden" name="reference" id="fr3fRef">
    <input type="hidden" name="staff_name" id="fr3fStaffName">
    <input type="hidden" name="staff_payment_type" id="fr3fStaffType">
  </form>
  <form id="fr3FormHov" method="POST" style="display:none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="submit_handover">
    <input type="hidden" name="amount" id="fr3fHovAmount">
    <input type="hidden" name="note" id="fr3fHovNote">
    <input type="hidden" name="to_staff_id" id="fr3fHovTo">
    <input type="hidden" name="to_staff_name" id="fr3fHovToName">
    <input type="hidden" name="currency" id="fr3fHovCurrency">
  </form>
  <form id="fr3FormAdv" method="POST" style="display:none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="field_give_advance">
    <input type="hidden" name="amount" id="fr3fAdvAmount">
    <input type="hidden" name="currency" id="fr3fAdvCurrency">
    <input type="hidden" name="recipient_id" id="fr3fAdvTo">
    <input type="hidden" name="recipient_name" id="fr3fAdvToName">
    <input type="hidden" name="purpose" id="fr3fAdvPurpose">
    <input type="hidden" name="description" id="fr3fAdvDesc">
  </form>
  <form id="fr3FormIn" method="POST" style="display:none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="log_cash_in">
    <input type="hidden" name="currency" id="fr3fInCurrency">
    <input type="hidden" name="category" id="fr3fInCategory">
    <input type="hidden" name="ssp_amount" id="fr3fInSspAmount">
    <input type="hidden" name="usd_given" id="fr3fInUsdGiven">
    <input type="hidden" name="rate" id="fr3fInRate">
    <input type="hidden" name="amount" id="fr3fInAmount">
    <input type="hidden" name="description" id="fr3fInDesc">
  </form>

</div><!-- /fr3-mb -->
</div><!-- /fr3Modal -->

<!-- ═══════════ EXCHANGE MODAL ═══════════════════════════════════════════ -->
<div class="fr3-mo" id="frExchModal">
<div class="fr3-mb">
  <div class="fr3-mh" style="background:#312e81;">
    <div>
      <div style="font-size:15px;font-weight:900;color:#fff;">Exchange · USD ↔ SSP</div>
      <div style="font-size:11px;color:rgba(255,255,255,.6);margin-top:1px;">Convert between currencies</div>
    </div>
    <button class="fr3-mclose" onclick="frExchClose()">✕</button>
  </div>
  <div class="fr3-mbody">

    <!-- Exchange type -->
    <div class="fr3-fg">
      <label class="fr3-lbl">EXCHANGE TYPE</label>
      <div class="fr3-dir-row" style="grid-template-columns:1fr 1fr;">
        <div class="fr3-dir-btn out sel" id="frExchU2S" onclick="frExchSetType('usd_to_ssp')" style="border-color:#dc2626;background:#fef2f2;">
          <div style="font-size:20px;">💵→🇸🇸</div>
          <div style="font-size:12px;font-weight:700;margin-top:4px;">USD → SSP</div>
          <div style="font-size:10px;color:#64748b;margin-top:2px;">Give USD, get SSP</div>
        </div>
        <div class="fr3-dir-btn in" id="frExchS2U" onclick="frExchSetType('ssp_to_usd')">
          <div style="font-size:20px;">🇸🇸→💵</div>
          <div style="font-size:12px;font-weight:700;margin-top:4px;">SSP → USD</div>
          <div style="font-size:10px;color:#64748b;margin-top:2px;">Give SSP, get USD</div>
        </div>
      </div>
    </div>

    <!-- Date -->
    <div class="fr3-fg">
      <label class="fr3-lbl">DATE</label>
      <input type="date" class="fr3-inp" id="frExchDate" value="<?php echo date('Y-m-d'); ?>">
    </div>

    <!-- USD amount -->
    <div class="fr3-fg">
      <label class="fr3-lbl" id="frExchAmtLbl">USD AMOUNT (YOU ARE GIVING)</label>
      <div class="fr3-aw">
        <span class="fr3-as">$</span>
        <input type="number" class="fr3-inp fr3-ai" id="frExchAmt" placeholder="0.00" step="0.01" min="0.01" oninput="frExchCalc()">
      </div>
    </div>

    <!-- Rate -->
    <div class="fr3-fg">
      <label class="fr3-lbl">EXCHANGE RATE (SSP PER $1)</label>
      <input type="number" class="fr3-inp" id="frExchRate" placeholder="e.g. 5700" step="1" min="1"
             value="<?php echo (int)($fr_rate ?? 0) ?: ''; ?>" oninput="frExchCalc()">
    </div>

    <!-- Auto-calc result -->
    <div id="frExchResult" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 14px;margin-bottom:14px;font-size:13px;color:#065f46;font-weight:700;"></div>

    <!-- Note -->
    <div class="fr3-fg">
      <label class="fr3-lbl">NOTE <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
      <input type="text" class="fr3-inp" id="frExchNote" placeholder="e.g. from Rupesh, for airtime…">
    </div>

    <!-- Current balances -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:10px 14px;margin-bottom:14px;">
      <div style="font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:.5px;margin-bottom:6px;">YOUR CURRENT BALANCES</div>
      <div style="display:flex;gap:16px;">
        <div><span style="font-size:11px;color:#64748b;">USD</span><br><span style="font-size:15px;font-weight:900;color:#16a34a;">$<?php echo number_format($fr_usd_holding, 2); ?></span></div>
        <div><span style="font-size:11px;color:#64748b;">SSP</span><br><span style="font-size:15px;font-weight:900;color:#f97316;"><?php echo number_format($fr_ssp_holding, 0); ?></span></div>
      </div>
    </div>

    <!-- Save button -->
    <button id="frExchSaveBtn" onclick="frExchSubmit()"
            style="width:100%;padding:14px;background:#312e81;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:800;cursor:pointer;opacity:.4;pointer-events:none;">
      Save Exchange
    </button>
    <div id="frExchErr" style="display:none;margin-top:10px;padding:10px 12px;background:#fef2f2;border-radius:8px;font-size:12px;color:#dc2626;font-weight:600;"></div>

  </div><!-- /fr3-mbody -->
</div><!-- /fr3-mb -->
</div><!-- /frExchModal -->

<script>
// ── State ──────────────────────────────────────────────────────────────────
var _fr3Curr = 'USD', _fr3Dir = '', _fr3Cat = '';
var _fr3UsdHolding   = Math.max(0, <?php echo $fr_usd_holding; ?>);
var _fr3IsAcct       = <?php echo $fr_isAcct ? 'true' : 'false'; ?>; // all cash-handling roles
var _fr3IsFieldAcct  = <?php echo ($userRole === 'field_accountant') ? 'true' : 'false'; ?>; // field_accountant only (staff payments)
var _fr3IsFieldAgent = <?php echo ($userRole === 'field_agent') ? 'true' : 'false'; ?>; // field_agent (give advance)
var _fr3SspHolding = <?php echo $fr_ssp_holding; ?>;

// ── Open / Close ───────────────────────────────────────────────────────────
function fr3Open(dir, cat) {
  fr3Reset();
  document.getElementById('fr3Modal').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (dir) fr3SetDir(dir);
  if (cat) fr3SetCat(cat);
}
function fr3Close() {
  document.getElementById('fr3Modal').classList.remove('open');
  document.body.style.overflow = '';
}

// ── Reset ──────────────────────────────────────────────────────────────────
function fr3Reset() {
  _fr3Curr = 'USD'; _fr3Dir = ''; _fr3Cat = ''; window._fr3ActiveGrp = 'staff';
  document.getElementById('fr3MH').className = 'fr3-mh neutral';
  document.getElementById('fr3MTitle').textContent = 'Add Entry';
  document.getElementById('fr3MSub').textContent   = 'Select direction to begin';
  document.getElementById('fr3CatSection').style.display  = 'none';
  document.getElementById('fr3FieldSection').style.display = 'none';
  document.getElementById('fr3Step1').style.display = '';
  document.getElementById('fr3Step2').style.display = 'none';
  document.getElementById('fr3NextWrap').style.display = 'none';
  document.getElementById('fr3Footer').style.display = 'none';
  document.getElementById('fr3PillUSD').classList.add('sel');
  document.getElementById('fr3PillSSP').classList.remove('sel');
  ['fr3DirIn','fr3DirOut'].forEach(function(id){
    document.getElementById(id).classList.remove('sel');
  });
  document.getElementById('fr3Amt').value = '';
  document.getElementById('fr3Desc').value = '';
  document.getElementById('fr3Ref').value = '';
  if(document.getElementById('fr3UsdGiven')) document.getElementById('fr3UsdGiven').value = '';
  document.getElementById('fr3SaveBtn').disabled = true;
  document.getElementById('fr3SaveBtn').textContent = 'Save Entry';
}

// ── Currency pill ──────────────────────────────────────────────────────────
function fr3SetCurr(cur) {
  _fr3Curr = cur;
  document.getElementById('fr3PillUSD').classList.toggle('sel', cur==='USD');
  document.getElementById('fr3PillSSP').classList.toggle('sel', cur==='SSP');
  // Update IN button label + subtitle based on currency
  var inBtn    = document.getElementById('fr3DirIn');
  var inLbl    = document.getElementById('fr3DirInLbl');
  var inSub    = document.getElementById('fr3DirInSub');
  if (cur === 'USD' && !_fr3IsAcct) {
    inBtn.style.opacity      = '0.35';
    inBtn.style.pointerEvents = 'none';
    inLbl.textContent = 'USD IN';
    inSub.textContent = 'Use Collect tab';
    if (_fr3Dir === 'in') { _fr3Dir = ''; document.getElementById('fr3DirIn').classList.remove('sel'); document.getElementById('fr3CatSection').style.display='none'; document.getElementById('fr3FieldSection').style.display='none'; }
  } else if (cur === 'USD' && _fr3IsAcct) {
    inBtn.style.opacity      = '1';
    inBtn.style.pointerEvents = '';
    inLbl.textContent = 'USD IN';
    inSub.textContent = 'Advance · Collection';
  } else {
    inBtn.style.opacity      = '1';
    inBtn.style.pointerEvents = '';
    inLbl.textContent = 'SSP IN';
    inSub.textContent = 'Received · Exchange';
  }
  // update amount label
  document.getElementById('fr3AmtLbl').textContent = cur==='SSP' ? 'AMOUNT (SSP)' : 'AMOUNT (USD)';
  document.getElementById('fr3AmtPrefix').textContent = cur==='SSP' ? '' : '$';
  // re-render cats if direction already selected
  if (_fr3Dir) { fr3RenderCats(); _fr3Cat=''; document.getElementById('fr3FieldSection').style.display='none'; }
  fr3UpdateHeader();
}

// ── Direction ──────────────────────────────────────────────────────────────
function fr3SetDir(dir) {
  _fr3Dir = dir; _fr3Cat = '';
  document.getElementById('fr3DirIn').classList.toggle('sel', dir==='in');
  document.getElementById('fr3DirOut').classList.toggle('sel', dir==='out');
  var exchEl = document.getElementById('fr3DirExch');
  if (exchEl) exchEl.classList.toggle('sel', dir==='exchange');

  if (dir === 'exchange') {
    // Close Add Entry modal, open dedicated Exchange modal
    fr3Close();
    frExchOpen();
    return;
  }
  document.getElementById('fr3CatSection').style.display = 'block';
  document.getElementById('fr3FieldSection').style.display = 'none';
  fr3RenderCats();
  fr3UpdateHeader();
}

// ── Exchange Modal ──────────────────────────────────────────────────────────
var _frExchType = 'usd_to_ssp';

function frExchOpen() {
  _frExchType = 'usd_to_ssp';
  frExchSetType('usd_to_ssp');
  document.getElementById('frExchAmt').value  = '';
  document.getElementById('frExchNote').value = '';
  document.getElementById('frExchDate').value = new Date().toISOString().substring(0,10);
  document.getElementById('frExchResult').style.display = 'none';
  document.getElementById('frExchErr').style.display    = 'none';
  var btn = document.getElementById('frExchSaveBtn');
  btn.style.opacity = '.4'; btn.style.pointerEvents = 'none';
  btn.textContent = 'Save Exchange';
  document.getElementById('frExchModal').classList.add('open');
}

function frExchClose() {
  document.getElementById('frExchModal').classList.remove('open');
}

function frExchSetType(type) {
  _frExchType = type;
  document.getElementById('frExchU2S').classList.toggle('sel', type === 'usd_to_ssp');
  document.getElementById('frExchU2S').style.borderColor = type === 'usd_to_ssp' ? '#dc2626' : '';
  document.getElementById('frExchU2S').style.background  = type === 'usd_to_ssp' ? '#fef2f2' : '';
  document.getElementById('frExchS2U').classList.toggle('sel', type === 'ssp_to_usd');
  document.getElementById('frExchS2U').style.borderColor = type === 'ssp_to_usd' ? '#16a34a' : '';
  document.getElementById('frExchS2U').style.background  = type === 'ssp_to_usd' ? '#f0fdf4' : '';
  document.getElementById('frExchAmtLbl').textContent =
    type === 'usd_to_ssp' ? 'USD AMOUNT (YOU ARE GIVING)' : 'USD AMOUNT (YOU WILL RECEIVE)';
  frExchCalc();
}

function frExchCalc() {
  var amt  = parseFloat(document.getElementById('frExchAmt').value)  || 0;
  var rate = parseFloat(document.getElementById('frExchRate').value) || 0;
  var res  = document.getElementById('frExchResult');
  var btn  = document.getElementById('frExchSaveBtn');
  if (amt > 0 && rate > 0) {
    var ssp = Math.round(amt * rate);
    if (_frExchType === 'usd_to_ssp') {
      res.textContent = '✅ You give $' + amt.toFixed(2) + ' → You receive ' + ssp.toLocaleString() + ' SSP  (rate: ' + rate.toLocaleString() + ')';
    } else {
      res.textContent = '✅ You give ' + ssp.toLocaleString() + ' SSP → You receive $' + amt.toFixed(2) + '  (rate: ' + rate.toLocaleString() + ')';
    }
    res.style.display = '';
    btn.textContent = _frExchType === 'usd_to_ssp'
      ? 'Save Exchange · $' + amt.toFixed(2) + ' → ' + ssp.toLocaleString() + ' SSP'
      : 'Save Exchange · ' + ssp.toLocaleString() + ' SSP → $' + amt.toFixed(2);
    btn.style.opacity = '1'; btn.style.pointerEvents = '';
  } else {
    res.style.display = 'none';
    btn.style.opacity = '.4'; btn.style.pointerEvents = 'none';
    btn.textContent = 'Save Exchange';
  }
}

function frExchSubmit() {
  var amt  = parseFloat(document.getElementById('frExchAmt').value)  || 0;
  var rate = parseFloat(document.getElementById('frExchRate').value) || 0;
  var note = document.getElementById('frExchNote').value.trim();
  var date = document.getElementById('frExchDate').value || new Date().toISOString().substring(0,10);
  var err  = document.getElementById('frExchErr');
  err.style.display = 'none';

  if (amt <= 0 || rate <= 0) { err.textContent = 'Please enter amount and rate.'; err.style.display = ''; return; }

  var btn = document.getElementById('frExchSaveBtn');
  btn.textContent = 'Saving…'; btn.style.opacity = '.6'; btn.style.pointerEvents = 'none';

  fetch('?page=api&action=record_exchange', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({
      exc_direction: _frExchType,
      exc_amount:    amt,
      exc_rate:      rate,
      exc_note:      note,
      exc_date:      date,
    })
  })
  .then(function(r){ return r.json(); })
  .then(function(resp) {
    if (resp.status === 'success') {
      frExchClose();
      window.location.reload();
    } else {
      err.textContent = resp.message || resp.error || 'Exchange failed.';
      err.style.display = '';
      btn.textContent = 'Save Exchange'; btn.style.opacity = '1'; btn.style.pointerEvents = '';
    }
  })
  .catch(function() {
    err.textContent = 'Network error. Please try again.';
    err.style.display = '';
    btn.textContent = 'Save Exchange'; btn.style.opacity = '1'; btn.style.pointerEvents = '';
  });
}

// ── Category chips ─────────────────────────────────────────────────────────
function fr3RenderCats() {
  var grid = document.getElementById('fr3CatGrid');
  var cats = [];
  if (_fr3Dir === 'in') {
    if (_fr3IsAcct) {
      if (_fr3Curr === 'USD') {
        cats = [
          {id:'Collection', ic:'💰', lbl:'Collection'},
          {id:'Return',     ic:'↩️', lbl:'Return / Refund IN'},
        ];
        if (_fr3IsFieldAcct) cats.unshift({id:'Advance Received', ic:'💵', lbl:'Advance from Rupesh'});
      } else {
        // SSP IN — same for all
        cats = [
          {id:'SSP Received', ic:'🇸🇸', lbl:'SSP Received'},
          {id:'Exchange',     ic:'🔄', lbl:'Exchange (USD→SSP)'},
        ];
      }
    } else {
      // Basic role — SSP only
      cats = [
        {id:'SSP Received', ic:'🇸🇸', lbl:'SSP Received'},
        {id:'Exchange',     ic:'🔄', lbl:'Exchange (USD→SSP)'},
      ];
    }
  } else {
    // Cash OUT
    if (_fr3IsAcct) {
      if (_fr3Curr === 'SSP') {
        cats = [
          {id:'Expense', ic:'🧾', lbl:'SSP Expense'},
          {id:'Vehicle', ic:'🚗', lbl:'Vehicle (SSP)'},
          {id:'SSP Return', ic:'↩️', lbl:'Return SSP'},
        ];
        if (_fr3IsFieldAcct) cats.push({id:'Staff Payment', ic:'👤', lbl:'Staff Payment (SSP)'});
        if (_fr3IsFieldAgent) cats.push({id:'Give Advance', ic:'💸', lbl:'Give Advance (SSP)'});
        cats.push({id:'Handover', ic:'🤝', lbl:'Handover (SSP)'});
      } else {
        cats = [
          {id:'Expense',  ic:'🧾', lbl:'My Expense'},
          {id:'Handover', ic:'🤝', lbl:'Handover'},
        ];
        if (_fr3IsFieldAcct) {
          cats.splice(1, 0,
            {id:'Staff Salary',  ic:'💼', lbl:'Staff Salary'},
            {id:'Staff Advance', ic:'⛽', lbl:'Staff Advance / Fuel'},
            {id:'Commission',    ic:'🤝', lbl:'Customer Commission'},
            {id:'Refund',        ic:'↩️', lbl:'Customer Refund'},
            {id:'Vehicle',       ic:'🚗', lbl:'Vehicle'},
            {id:'Power',         ic:'⚡', lbl:'Power Bill'}
          );
          cats[cats.length-1].lbl = 'Handover to Rupesh';
        }
        if (_fr3IsFieldAgent) {
          cats.splice(1, 0,
            {id:'Give Advance', ic:'💸', lbl:'Give Advance to Staff'}
          );
        }
      }
    } else {
      if (_fr3Curr === 'SSP') {
        cats = [
          {id:'Expense', ic:'🧾', lbl:'SSP Expense'},
          {id:'Vehicle', ic:'🚗', lbl:'Vehicle (SSP)'},
          {id:'SSP Return', ic:'↩️', lbl:'Return SSP'},
          {id:'Handover', ic:'🤝', lbl:'Handover (SSP)'},
        ];
      } else {
        cats = [
          {id:'Expense',  ic:'🧾', lbl:'Expense'},
          {id:'Vehicle',  ic:'🚗', lbl:'Vehicle'},
          {id:'Handover', ic:'🤝', lbl:'Handover'},
        ];
      }
    }
  }
  grid.innerHTML = '';
  // v4.9.10: Cash OUT with tabs (same experience as Rupesh's cashbook)
  if (_fr3Dir === 'out' && _fr3IsFieldAcct && _fr3Curr !== 'SSP' && cats.length > 4) {
    // Group categories into tabs
    var _fr3Groups = [
      {key:'staff', lbl:'Staff',    dot:'#3b82f6', ids:['Staff Salary','Staff Advance']},
      {key:'site',  lbl:'Sites',    dot:'#f59e0b', ids:['Power']},
      {key:'ops',   lbl:'Ops',      dot:'#10b981', ids:['Expense','Refund','Commission','Vehicle']},
      {key:'xfer',  lbl:'Transfer', dot:'#6b7280', ids:['Handover']},
    ];
    if (!window._fr3ActiveGrp) window._fr3ActiveGrp = 'staff';
    // Tab bar
    var tabHtml = '<div class="fr3-grp-tabs">';
    _fr3Groups.forEach(function(g) {
      var sel = g.key === window._fr3ActiveGrp ? ' sel' : '';
      tabHtml += '<button class="fr3-grp-tab' + sel + '" onclick="fr3SwitchGrp(\'' + g.key + '\')">'
        + '<span class="fr3-grp-dot" style="background:' + g.dot + '"></span>' + g.lbl + '</button>';
    });
    tabHtml += '</div>';
    grid.innerHTML = tabHtml;
    // Find active group's category IDs
    var activeGrp = _fr3Groups.filter(function(g){ return g.key === window._fr3ActiveGrp; })[0];
    var activeCats = activeGrp ? cats.filter(function(c){ return activeGrp.ids.indexOf(c.id) >= 0; }) : cats;
    // Render only active group's tiles
    activeCats.forEach(function(c) {
      var el = document.createElement('div');
      el.className = 'fr3-cat';
      el.setAttribute('data-cat', c.id);
      el.innerHTML = '<div class="fr3-cat-ic">'+c.ic+'</div><div class="fr3-cat-lbl">'+c.lbl+'</div>';
      el.onclick = function() { fr3SetCat(c.id); };
      grid.appendChild(el);
    });
  } else {
    // Flat tiles (Cash IN, SSP, or few categories — no tabs needed)
    cats.forEach(function(c) {
      var el = document.createElement('div');
      el.className = 'fr3-cat';
      el.setAttribute('data-cat', c.id);
      el.innerHTML = '<div class="fr3-cat-ic">'+c.ic+'</div><div class="fr3-cat-lbl">'+c.lbl+'</div>';
      el.onclick = function() { fr3SetCat(c.id); };
      grid.appendChild(el);
    });
  }
}

// v4.9.10: Switch tab group in Cash OUT
function fr3SwitchGrp(key) {
  window._fr3ActiveGrp = key;
  fr3RenderCats();
}

function fr3SetCat(cat) {
  _fr3Cat = cat;
  // highlight chip
  document.querySelectorAll('.fr3-cat').forEach(function(el) {
    el.classList.toggle('sel', el.getAttribute('data-cat') === cat);
  });
  // show fields — now handled by Step 2 via Next button
  document.getElementById('fr3NextWrap').style.display = 'block';
  document.getElementById('fr3FieldSection').style.display = 'none'; // hidden until step 2
  // Update Next button text
  var nextLabels = {'Expense':'Enter Details →','Handover':'Select Recipient →','Give Advance':'Select Recipient →',
    'Collection':'Enter Amount →','Exchange':'Enter Exchange →','SSP Received':'Enter Amount →',
    'Staff Salary':'Enter Details →','Staff Advance':'Enter Details →','Staff Payment':'Enter Details →',
    'Refund':'Enter Refund Details →','Power':'Enter Power Bill →','Commission':'Enter Commission Details →',
    'Vehicle':'Select Vehicle Expense →'};
  document.getElementById('fr3NextBtn').textContent = nextLabels[cat] || 'Next →';
  // show/hide specific fields (pre-configure for when step 2 opens)
  var isExp    = cat === 'Expense';
  var isHov    = cat === 'Handover';
  var isExch   = cat === 'Exchange';
  var isSspRec = cat === 'SSP Received';
  var isStaff  = (cat === 'Staff Salary' || cat === 'Staff Advance' || cat === 'Staff Payment');
  var isGiveAdv = cat === 'Give Advance';
  // v4.9.10: Refund + Power go through same expense form (no staff name needed)
  var isFieldExp = (cat === 'Refund' || cat === 'Power');
  var isComm     = (cat === 'Commission');
  var isVehicle  = (cat === 'Vehicle');
  document.getElementById('fr3ExpTypeWrap').style.display   = isExp    ? '' : 'none';
  document.getElementById('fr3StaffTypeWrap').style.display = isStaff  ? '' : 'none';
  document.getElementById('fr3StaffNameWrap').style.display = isStaff  ? '' : 'none';
  document.getElementById('fr3CommWrap').style.display      = isComm   ? '' : 'none';
  document.getElementById('fr3VehicleWrap').style.display   = isVehicle ? '' : 'none';
  document.getElementById('fr3ExchStrip').style.display     = isExch   ? '' : 'none';
  document.getElementById('fr3UsdGivenWrap').style.display  = isExch   ? '' : 'none';
  document.getElementById('fr3RateDisplay').style.display   = isExch   ? '' : 'none';
  document.getElementById('fr3HovStrip').style.display      = isHov    ? '' : 'none';
  document.getElementById('fr3HovRecipient').style.display  = (isHov || isGiveAdv) ? '' : 'none';
  document.getElementById('fr3AdvPurpose').style.display    = isGiveAdv ? '' : 'none';
  var recLabel = document.getElementById('fr3RecipLabel');
  if (recLabel) recLabel.textContent = isGiveAdv ? 'GIVE ADVANCE TO' : 'HAND OVER TO';
  // Reset staff picker on category change
  if (isHov || isGiveAdv) { fr3ClearStaff(); }
  // Amount label / prefix
  var isSspAmt = (_fr3Curr==='SSP') || isSspRec;
  document.getElementById('fr3AmtLbl').textContent    = isSspAmt ? 'AMOUNT (SSP)' : 'AMOUNT (USD)';
  document.getElementById('fr3AmtPrefix').textContent = isSspAmt ? '' : '$';
  // Pre-fill handover
  if (isHov) {
    document.getElementById('fr3Amt').value = _fr3UsdHolding.toFixed(2);
    fr3HovRecipientChanged();
  }
  if (isGiveAdv) {
    document.getElementById('fr3Amt').value = '';
    _fr3AdvPurpose = '';
    document.querySelectorAll('#fr3PurposeGrid .fr3-cat').forEach(function(el){el.style.borderColor='#e2e8f0';el.style.background='';});
    var sel = document.getElementById('fr3HovToSelect');
    var toName = sel.options[sel.selectedIndex].getAttribute('data-name') || '';
    document.getElementById('fr3Desc').value = 'Cash advance to ' + toName;
  }
  if (isComm) {
    document.getElementById('fr3Amt').value = '';
    document.getElementById('fr3CommCustName').value = '';
    document.getElementById('fr3CommCustPhone').value = '';
    document.getElementById('fr3CommReason').selectedIndex = 0;
    document.getElementById('fr3CommInvoice').value = '';
    document.getElementById('fr3Desc').value = '';
  }
  fr3UpdateSave();
  fr3UpdateHeader();
}

// ── Header color + title ───────────────────────────────────────────────────
var _fr3VehicleType = '';
function fr3SetVehicle(el) {
  _fr3VehicleType = el.getAttribute('data-vtype');
  document.querySelectorAll('#fr3VehicleGrid .fr3-cat').forEach(function(c) {
    c.style.borderColor = (c === el) ? '#1e293b' : '#e2e8f0';
    c.style.background  = (c === el) ? '#0f0f0f' : '';
    c.style.color       = (c === el) ? '#fff' : '';
  });
  // Auto-fill description with vehicle type
  var descEl = document.getElementById('fr3Desc');
  var plate  = (document.getElementById('fr3VehiclePlate').value || '').trim();
  descEl.value = _fr3VehicleType + (plate ? ' — ' + plate : '');
  fr3UpdateSave();
}
function fr3UpdateHeader() {
  var mh = document.getElementById('fr3MH');
  var titles = {
    'in':  {cls:'in',  title:'Cash IN Entry',  sub:'Money coming in'},
    'out': {cls:'out', title:'Cash OUT Entry', sub:'Money going out'},
    '':    {cls:'neutral', title:'Add Entry',  sub:'Select direction to begin'},
  };
  var t = titles[_fr3Dir] || titles[''];
  mh.className = 'fr3-mh '+t.cls;
  document.getElementById('fr3MTitle').textContent = t.title;
  document.getElementById('fr3MSub').textContent   = t.sub;
}

// ── Exchange rate calc ─────────────────────────────────────────────────────
function fr3CalcRate() {
  if (_fr3Cat !== 'Exchange') return;
  var usdEl  = document.getElementById('fr3UsdGiven');
  var sspEl  = document.getElementById('fr3Amt');
  var rateEl = document.getElementById('fr3RateDisplay');
  var usd = parseFloat(usdEl.value) || 0;
  var ssp = parseFloat(sspEl.value) || 0;
  if (usd > 0 && ssp > 0) {
    var rate = Math.round(ssp / usd);
    rateEl.style.display = '';
    rateEl.textContent = '✅ Rate: $'+usd.toFixed(2)+' = '+ssp.toLocaleString()+' SSP  (1 USD = '+rate.toLocaleString()+' SSP)';
  } else {
    rateEl.style.display = 'none';
  }
  fr3UpdateSave();
}

// ── Multi-step navigation ─────────────────────────────────────────────────
function fr3GoStep2() {
  document.getElementById('fr3Step1').style.display = 'none';
  document.getElementById('fr3Step2').style.display = '';
  document.getElementById('fr3FieldSection').style.display = '';
  document.getElementById('fr3Footer').style.display = '';
  var labels = {'Expense':'Expense Details','Handover':'Handover Details','Give Advance':'Advance Details',
    'Collection':'Collection Details','Exchange':'Exchange Details','SSP Received':'SSP Details',
    'Staff Salary':'Staff Payment','Staff Advance':'Staff Advance','Staff Payment':'Staff Payment',
    'Refund':'Refund Details','Power':'Power Bill Details','Commission':'Commission Details'};
  document.getElementById('fr3Step2Label').textContent = labels[_fr3Cat] || 'Details';
  document.querySelector('.fr3-mbody').scrollTop = 0;
}
function fr3GoStep1() {
  document.getElementById('fr3Step1').style.display = '';
  document.getElementById('fr3Step2').style.display = 'none';
  document.getElementById('fr3Footer').style.display = 'none';
  document.querySelector('.fr3-mbody').scrollTop = 0;
}

// ── Searchable staff picker ───────────────────────────────────────────────
function fr3FilterStaff() {
  var q = document.getElementById('fr3StaffSearch').value.toLowerCase();
  var items = document.querySelectorAll('.fr3-staff-item');
  var list = document.getElementById('fr3StaffList');
  list.style.display = '';
  var visible = 0;
  items.forEach(function(el) {
    var name = (el.getAttribute('data-name') || '').toLowerCase();
    var role = (el.getAttribute('data-role') || '').toLowerCase();
    var show = !q || name.indexOf(q) >= 0 || role.indexOf(q) >= 0;
    el.style.display = show ? '' : 'none';
    if (show) visible++;
  });
}
function fr3SelectStaff(el) {
  var id   = el.getAttribute('data-id');
  var name = el.getAttribute('data-name');
  var role = el.getAttribute('data-role');
  // Set hidden select value
  var sel = document.getElementById('fr3HovToSelect');
  for (var i=0; i<sel.options.length; i++) {
    if (sel.options[i].value === id) { sel.selectedIndex = i; break; }
  }
  // Show selected state
  document.getElementById('fr3StaffSearch').style.display = 'none';
  document.getElementById('fr3StaffList').style.display = 'none';
  document.getElementById('fr3StaffSelected').style.display = '';
  document.getElementById('fr3SelName').textContent = name;
  document.getElementById('fr3SelRole').textContent = role;
  // Update description
  if (_fr3Cat === 'Give Advance') {
    document.getElementById('fr3Desc').value = 'Cash advance to ' + name;
  } else {
    document.getElementById('fr3Desc').value = 'Cash handover to ' + name;
  }
  fr3UpdateSave();
}
function fr3ClearStaff() {
  document.getElementById('fr3StaffSearch').style.display = '';
  document.getElementById('fr3StaffSearch').value = '';
  document.getElementById('fr3StaffSelected').style.display = 'none';
  document.getElementById('fr3StaffList').style.display = 'none';
  document.getElementById('fr3Desc').value = '';
  fr3UpdateSave();
}

// ── Handover recipient changed ────────────────────────────────────────────
function fr3HovRecipientChanged() {
  var sel = document.getElementById('fr3HovToSelect');
  if (!sel) return;
  var toName = sel.options[sel.selectedIndex].getAttribute('data-name') || 'Rupesh';
  if (_fr3Cat === 'Give Advance') {
    document.getElementById('fr3Desc').value = 'Cash advance to ' + toName;
  } else {
    document.getElementById('fr3Desc').value = 'Cash handover to ' + toName;
  }
}

// ── Purpose selection for Give Advance ───────────────────────────────────
var _fr3AdvPurpose = '';
function fr3SetPurpose(p) {
  _fr3AdvPurpose = p;
  document.querySelectorAll('#fr3PurposeGrid .fr3-cat').forEach(function(el) {
    var isSel = el.getAttribute('data-purpose') === p;
    el.style.borderColor = isSel ? '#7C3AED' : '#e2e8f0';
    el.style.background = isSel ? '#f5f3ff' : '';
  });
  fr3UpdateSave();
}

// ── Save button text ───────────────────────────────────────────────────────
function fr3UpdateSave() {
  var btn  = document.getElementById('fr3SaveBtn');
  var amt  = parseFloat(document.getElementById('fr3Amt').value) || 0;
  var cur  = (_fr3Cat==='SSP Received'||_fr3Curr==='SSP') ? 'SSP' : 'USD';
  var disp = cur==='SSP' ? Math.round(amt).toLocaleString()+' SSP' : '$'+amt.toFixed(2);
  var ready = _fr3Cat !== '' && amt > 0;
  if (_fr3Cat === 'Exchange') {
    var usd = parseFloat(document.getElementById('fr3UsdGiven').value) || 0;
    ready = usd > 0 && amt > 0;
  }
  btn.disabled = !ready;
  if (_fr3Cat === 'Give Advance') {
    var staffSel = document.getElementById('fr3StaffSelected').style.display !== 'none';
    ready = amt > 0 && _fr3AdvPurpose !== '' && staffSel;
    btn.disabled = !ready;
  }
  if (_fr3Cat === 'Handover') {
    var staffSel2 = document.getElementById('fr3StaffSelected').style.display !== 'none';
    ready = amt > 0 && staffSel2;
    btn.disabled = !ready;
  }
  if (_fr3Cat === 'Commission') {
    var commName  = (document.getElementById('fr3CommCustName').value || '').trim();
    var commPhone = (document.getElementById('fr3CommCustPhone').value || '').trim();
    ready = amt > 0 && commName.length > 0 && commPhone.length > 0;
    btn.disabled = !ready;
  }
  if (_fr3Cat && amt > 0) {
    var labels = {'Collection':'Save Collection','Expense':'Save Expense',
      'Handover':'Hand Over Cash','Give Advance':'Give Advance','SSP Received':'Save SSP Received','Exchange':'Save Exchange',
      'Refund':'Save Refund','Power':'Save Power Bill','Commission':'Pay Commission',
      'Staff Salary':'Save Staff Payment','Staff Advance':'Save Advance','Staff Payment':'Save Payment'};
    btn.textContent = (labels[_fr3Cat]||'Save Entry') + ' · ' + disp;
  } else {
    btn.textContent = 'Save Entry';
  }
}

// ── Photo changed ──────────────────────────────────────────────────────────
function fr3PhotoChanged(input) {
  var lbl = document.getElementById('fr3PhotoLbl');
  lbl.textContent = input.files.length ? '📎 '+input.files[0].name : 'Tap to capture receipt';
}

// ── Submit ─────────────────────────────────────────────────────────────────
function fr3Submit() {
  var amt  = parseFloat(document.getElementById('fr3Amt').value) || 0;
  var desc = document.getElementById('fr3Desc').value.trim();
  var ref  = document.getElementById('fr3Ref').value.trim();

  if (!_fr3Cat || amt <= 0) { alert('Please fill in all required fields.'); return; }

  if (_fr3Cat === 'Handover') {
    var staffSelected = document.getElementById('fr3StaffSelected').style.display !== 'none';
    if (!staffSelected) {
      alert('Please select who you are handing over to.\nTap the search box and pick a staff member.');
      return;
    }
    var sel = document.getElementById('fr3HovToSelect');
    var toName = sel.options[sel.selectedIndex].getAttribute('data-name') || sel.options[sel.selectedIndex].text;
    var toId   = sel.value;
    document.getElementById('fr3fHovAmount').value   = amt;
    document.getElementById('fr3fHovNote').value     = desc;
    document.getElementById('fr3fHovTo').value       = toId;
    document.getElementById('fr3fHovToName').value   = toName;
    document.getElementById('fr3fHovCurrency').value = _fr3Curr;
    document.getElementById('fr3FormHov').submit();

  } else if (_fr3Cat === 'Give Advance') {
    if (!_fr3AdvPurpose) { alert('Please select a purpose for the advance.'); return; }
    var sel = document.getElementById('fr3HovToSelect');
    var toName = sel.options[sel.selectedIndex].getAttribute('data-name') || sel.options[sel.selectedIndex].text;
    var toId   = sel.value;
    document.getElementById('fr3fAdvAmount').value   = amt;
    document.getElementById('fr3fAdvCurrency').value = _fr3Curr;
    document.getElementById('fr3fAdvTo').value       = toId;
    document.getElementById('fr3fAdvToName').value   = toName;
    document.getElementById('fr3fAdvPurpose').value  = _fr3AdvPurpose;
    document.getElementById('fr3fAdvDesc').value     = desc;
    document.getElementById('fr3FormAdv').submit();

  } else if (_fr3Cat === 'Exchange') {
    var usd  = parseFloat(document.getElementById('fr3UsdGiven').value) || 0;
    var rate = usd > 0 ? Math.round(amt / usd) : 0;
    document.getElementById('fr3fInCurrency').value  = 'SSP';
    document.getElementById('fr3fInCategory').value  = 'Exchange';
    document.getElementById('fr3fInSspAmount').value = amt;
    document.getElementById('fr3fInUsdGiven').value  = usd;
    document.getElementById('fr3fInRate').value      = rate;
    document.getElementById('fr3fInAmount').value    = usd; // store USD given as amount
    document.getElementById('fr3fInDesc').value      = desc || ('Exchange $'+usd.toFixed(2)+' @ '+rate+' SSP');
    document.getElementById('fr3FormIn').submit();

  } else if (_fr3Cat === 'SSP Received') {
    document.getElementById('fr3fInCurrency').value  = 'SSP';
    document.getElementById('fr3fInCategory').value  = 'SSP Received';
    document.getElementById('fr3fInSspAmount').value = amt;
    document.getElementById('fr3fInUsdGiven').value  = 0;
    document.getElementById('fr3fInRate').value      = 0;
    document.getElementById('fr3fInAmount').value    = 0;
    document.getElementById('fr3fInDesc').value      = desc;
    document.getElementById('fr3FormIn').submit();

  } else if (_fr3Cat === 'Collection') {
    // Collections come from the Collect tab, not here
    // But allow manual entry → log as expense reverse / cash_in
    document.getElementById('fr3fInCurrency').value  = 'USD';
    document.getElementById('fr3fInCategory').value  = 'Collection';
    document.getElementById('fr3fInSspAmount').value = 0;
    document.getElementById('fr3fInUsdGiven').value  = 0;
    document.getElementById('fr3fInRate').value      = 0;
    document.getElementById('fr3fInAmount').value    = amt;
    document.getElementById('fr3fInDesc').value      = desc;
    document.getElementById('fr3FormIn').submit();

  } else if (_fr3Cat === 'Advance Received' || _fr3Cat === 'Return' || _fr3Cat === 'Collection') {
    // USD Cash IN for field_accountant
    document.getElementById('fr3fInCurrency').value  = 'USD';
    document.getElementById('fr3fInCategory').value  = _fr3Cat;
    document.getElementById('fr3fInSspAmount').value = 0;
    document.getElementById('fr3fInUsdGiven').value  = 0;
    document.getElementById('fr3fInRate').value      = 0;
    document.getElementById('fr3fInAmount').value    = amt;
    document.getElementById('fr3fInDesc').value      = desc;
    document.getElementById('fr3FormIn').submit();

  } else if (_fr3Cat === 'Staff Salary' || _fr3Cat === 'Staff Advance' || _fr3Cat === 'Staff Payment') {
    // Staff payment — goes to expense queue for Rupesh monthly approval
    var isSsp = (_fr3Curr === 'SSP');
    var staffName = document.getElementById('fr3StaffName').value.trim();
    var staffType = document.getElementById('fr3StaffType').value;
    if (!staffName) { alert('Please enter the staff member name.'); return; }
    document.getElementById('fr3fCurrency').value      = isSsp ? 'SSP' : 'USD';
    document.getElementById('fr3fCategory').value      = staffType; // Salary/Advance/Fuel etc
    document.getElementById('fr3fExpType').value       = staffType;
    document.getElementById('fr3fAmount').value        = isSsp ? 0 : amt;
    document.getElementById('fr3fSspAmount').value     = isSsp ? amt : 0;
    document.getElementById('fr3fDesc').value          = desc || (staffType + ' — ' + staffName);
    document.getElementById('fr3fRef').value           = ref;
    document.getElementById('fr3fStaffName').value     = staffName;
    document.getElementById('fr3fStaffType').value     = staffType;
    var photoInput = document.getElementById('fr3Photo');
    if (photoInput.files.length) {
      var clone = photoInput.cloneNode(true);
      clone.name = 'photo';
      document.getElementById('fr3FormExp').appendChild(clone);
    }
    document.getElementById('fr3FormExp').submit();

  } else if (_fr3Cat === 'Commission') {
    // Customer Commission — paid on the spot by Diko, no approval needed
    var commName    = (document.getElementById('fr3CommCustName').value || '').trim();
    var commPhone   = (document.getElementById('fr3CommCustPhone').value || '').trim();
    var commReason  = document.getElementById('fr3CommReason').value;
    var commInvoice = (document.getElementById('fr3CommInvoice').value || '').trim();
    if (!commName || !commPhone) { alert('Customer name and phone are required.'); return; }
    var isSsp = (_fr3Curr === 'SSP');
    var autoDesc = commReason + ' — ' + commName + ' (' + commPhone + ')';
    if (commInvoice) autoDesc += ' [' + commInvoice + ']';
    document.getElementById('fr3fCurrency').value  = isSsp ? 'SSP' : 'USD';
    document.getElementById('fr3fCategory').value  = 'Commission';
    document.getElementById('fr3fExpType').value   = 'Commission';
    document.getElementById('fr3fAmount').value    = isSsp ? 0 : amt;
    document.getElementById('fr3fSspAmount').value = isSsp ? amt : 0;
    document.getElementById('fr3fDesc').value      = desc || autoDesc;
    document.getElementById('fr3fRef').value       = commInvoice || ref;
    document.getElementById('fr3fStaffName').value = '';
    document.getElementById('fr3fStaffType').value = '';
    var photoInput = document.getElementById('fr3Photo');
    if (photoInput.files.length) {
      var clone = photoInput.cloneNode(true);
      clone.name = 'photo';
      document.getElementById('fr3FormExp').appendChild(clone);
    }
    document.getElementById('fr3FormExp').submit();

  } else if (_fr3Cat === 'Vehicle') {
    // Vehicle expense — subcategory from vehicle type picker
    if (!_fr3VehicleType) { alert('Please select a vehicle expense type (Fuel, Maintenance, etc).'); return; }
    var isSsp = (_fr3Curr === 'SSP');
    var plate = (document.getElementById('fr3VehiclePlate').value || '').trim();
    var autoDesc = _fr3VehicleType + (plate ? ' — ' + plate : '');
    if (desc && desc !== autoDesc) autoDesc = desc;
    document.getElementById('fr3fCurrency').value  = isSsp ? 'SSP' : 'USD';
    document.getElementById('fr3fCategory').value  = 'Vehicle';
    document.getElementById('fr3fExpType').value   = _fr3VehicleType;
    document.getElementById('fr3fAmount').value    = isSsp ? 0 : amt;
    document.getElementById('fr3fSspAmount').value = isSsp ? amt : 0;
    document.getElementById('fr3fDesc').value      = autoDesc;
    document.getElementById('fr3fRef').value       = ref;
    document.getElementById('fr3fStaffName').value = '';
    document.getElementById('fr3fStaffType').value = '';
    var photoInput = document.getElementById('fr3Photo');
    if (photoInput.files.length) {
      var clone = photoInput.cloneNode(true);
      clone.name = 'photo';
      document.getElementById('fr3FormExp').appendChild(clone);
    }
    document.getElementById('fr3FormExp').submit();

  } else if (_fr3Cat === 'Refund' || _fr3Cat === 'Power') {
    // v4.9.10: Refund / Power — submit as expense with proper cashbook category
    var catMap = {'Refund':'Refund','Power':'Site Power'};
    var isSsp = (_fr3Curr === 'SSP');
    document.getElementById('fr3fCurrency').value  = isSsp ? 'SSP' : 'USD';
    document.getElementById('fr3fCategory').value  = catMap[_fr3Cat] || _fr3Cat;
    document.getElementById('fr3fExpType').value   = catMap[_fr3Cat] || _fr3Cat;
    document.getElementById('fr3fAmount').value    = isSsp ? 0 : amt;
    document.getElementById('fr3fSspAmount').value = isSsp ? amt : 0;
    document.getElementById('fr3fDesc').value      = desc || _fr3Cat;
    document.getElementById('fr3fRef').value       = ref;
    document.getElementById('fr3fStaffName').value = '';
    document.getElementById('fr3fStaffType').value = '';
    var photoInput = document.getElementById('fr3Photo');
    if (photoInput.files.length) {
      var clone = photoInput.cloneNode(true);
      clone.name = 'photo';
      document.getElementById('fr3FormExp').appendChild(clone);
    }
    document.getElementById('fr3FormExp').submit();

  } else {
    // My own Expense (USD or SSP)
    var isSsp = (_fr3Curr === 'SSP');
    document.getElementById('fr3fCurrency').value  = isSsp ? 'SSP' : 'USD';
    document.getElementById('fr3fCategory').value  = document.getElementById('fr3ExpType').value;
    document.getElementById('fr3fExpType').value   = document.getElementById('fr3ExpType').value;
    document.getElementById('fr3fAmount').value    = isSsp ? 0 : amt;
    document.getElementById('fr3fSspAmount').value = isSsp ? amt : 0;
    document.getElementById('fr3fDesc').value      = desc;
    document.getElementById('fr3fRef').value       = ref;
    var photoInput = document.getElementById('fr3Photo');
    if (photoInput.files.length) {
      var clone = photoInput.cloneNode(true);
      clone.name = 'photo';
      document.getElementById('fr3FormExp').appendChild(clone);
    }
    document.getElementById('fr3FormExp').submit();
  }
}

// ── CSV export ─────────────────────────────────────────────────────────────
function fr3ExportCSV() {
  location.href = '?page=dashboard&tab=wallet&fr_export=csv<?php echo $fr_curr?"&fr_curr=$fr_curr":""; ?><?php echo $fr_from?"&fr_from=$fr_from":""; ?><?php echo $fr_to?"&fr_to=$fr_to":""; ?>';
}

// ── Compat shims for old buttons ───────────────────────────────────────────
function openExpModal()  { fr3Open('out'); fr3SetCat('Expense'); }
function openHovModal()  { fr3Open('out'); fr3SetCat('Handover'); }
</script>
