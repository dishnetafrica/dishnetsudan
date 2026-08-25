<?php
// ── Cashbook v2 — Rupesh's Digital Excel Replacement ─────────────────────
require_once __DIR__ . '/../../lib/CashbookService.php';
$cb      = new CashbookService($store, $dataDir);
$meta    = $cb->getMeta();
$isAdmin = in_array($userRole ?? '', ['admin','accountant','super_admin']) || ($isAdmin ?? false);

// ── CSV EXPORT ──────────────────────────────────────────────────────────────
if (!empty($_GET['cb_export']) && $_GET['cb_export'] === 'csv') {
    $proj  = in_array($_GET['cb_proj'] ?? 'dishnet', ['dishnet','4g','bluecard']) ? $_GET['cb_proj'] : 'dishnet';
    // Field agents can only export their own entries
    $_csvIsField = in_array($userRole ?? '', ['sales','sales_staff','field_agent','collection']) && !($isAdmin ?? false);
    $_csvPerson  = ($_csvIsField && !empty($retailer['name'])) ? $retailer['name'] : '';
    $_csvCurr = in_array(strtoupper($_GET['cb_curr'] ?? ''), ['USD','SSP']) ? strtoupper($_GET['cb_curr']) : '';
    $csvFilters = array_filter(['project' => $proj, 'date_from' => $_GET['cb_from'] ?? '', 'date_to' => $_GET['cb_to'] ?? '', 'person' => $_csvPerson, 'currency' => $_csvCurr, 'limit' => 9999, 'offset' => 0]);
    $rows  = $cb->getEntries($csvFilters);
    $_csvIsSSP = ($_csvCurr === 'SSP');
    $_csvIsAll = ($_csvCurr === '');
    $fname = 'cashbook-'.strtoupper($proj).'-'.($_csvCurr ?: 'ALL').'-'.date('Y-m-d').'.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    $out = fopen('php://output','w');
    // v4.9.18: Currency-separated CSV columns
    if ($_csvIsAll) {
        fputcsv($out, ['SR No.','Date','Particulars','Category','Person','Currency',
            'Received USD','Payment USD','USD Balance',
            'Received SSP','Payment SSP','SSP Balance',
            'Ref','Status','Source']);
    } elseif ($_csvIsSSP) {
        fputcsv($out, ['SR No.','Date','Particulars','Category','Person',
            'Received SSP','Payment SSP','SSP Balance','Ref','Status','Source']);
    } else {
        fputcsv($out, ['SR No.','Date','Particulars','Category','Person',
            'Received USD','Payment USD','USD Balance','Ref','Status','Source']);
    }
    foreach ($rows as $e) {
        $isIn = $e['direction']==='in';
        $catDisplay = $e['category'];
        $personVal  = trim($e['person'] ?? '');
        if ($personVal !== '') $catDisplay .= '-' . $personVal;
        $isSspRow = ($e['currency'] ?? 'USD') === 'SSP';
        $usdAmt   = (float)$e['amount'];
        $sspAmt   = (float)($e['ssp_amount'] ?? 0);
        $bal      = $e['running_balance'] ?? '';
        $balCurr  = $e['_bal_currency'] ?? ($isSspRow ? 'SSP' : 'USD');
        $ref      = $e['validation_ref'] ?? '';
        $status   = $e['validation_status'] ?? '';
        $source   = $e['source'] ?? '';

        if ($_csvIsAll) {
            // Both USD and SSP columns — fill the correct side
            fputcsv($out, [
                $e['sr'], $e['date'], $e['description'], $catDisplay, $personVal,
                $isSspRow ? 'SSP' : 'USD',
                // USD columns
                (!$isSspRow && $isIn)  ? $usdAmt : '',
                (!$isSspRow && !$isIn) ? $usdAmt : '',
                (!$isSspRow)           ? $bal     : '',
                // SSP columns
                ($isSspRow && $isIn)   ? $sspAmt  : '',
                ($isSspRow && !$isIn)  ? $sspAmt  : '',
                ($isSspRow)            ? $bal      : '',
                $ref, $status, $source
            ]);
        } elseif ($_csvIsSSP) {
            fputcsv($out, [$e['sr'],$e['date'],$e['description'],$catDisplay,$personVal,
                $isIn ? $sspAmt : '', $isIn ? '' : $sspAmt, $bal, $ref, $status, $source]);
        } else {
            fputcsv($out, [$e['sr'],$e['date'],$e['description'],$catDisplay,$personVal,
                $isIn ? $usdAmt : '', $isIn ? '' : $usdAmt, $bal, $ref, $status, $source]);
        }
    }
    fclose($out); exit;
}

$seeded      = !empty($meta['seeded_at']);
$seedCount   = (int)($meta['seeded_count'] ?? 0);
$seeded2026  = !empty($meta['seeded_2026_at']);
$seed2026Cnt = (int)($meta['seeded_2026_count'] ?? 0);
$proj      = in_array($_GET['cb_proj'] ?? 'dishnet', ['dishnet','4g','bluecard']) ? ($_GET['cb_proj'] ?? 'dishnet') : 'dishnet';
$view      = $_GET['cb_view'] ?? 'ledger';
$dateFrom  = $_GET['cb_from'] ?? '';
$dateTo    = $_GET['cb_to']   ?? '';
$filterCat  = $_GET['cb_cat']  ?? '';
$filterVal  = $_GET['cb_vs']   ?? '';
$filterDir  = in_array($_GET['cb_dir'] ?? '', ['in','out']) ? ($_GET['cb_dir'] ?? '') : '';
$filterCurr = in_array(strtoupper($_GET['cb_curr'] ?? ''), ['USD','SSP']) ? strtoupper($_GET['cb_curr']) : '';
$search     = trim($_GET['cb_q'] ?? '');
$page       = max(1, (int)($_GET['cb_page'] ?? 1));
$perPage    = 50;
$offset     = ($page - 1) * $perPage;

// ── Field agent: auto-scope to own entries (read-only view of their cash flow) ──
$isFieldAgent = in_array($userRole ?? '', ['sales','sales_staff','field_agent','field_accountant','collection'])
              && !($isAdmin ?? false);
$agentPersonFilter = ''; // person LIKE filter
if ($isFieldAgent && !empty($retailer['name'])) {
    $agentPersonFilter = $retailer['name'];
}

$bal    = $cb->getBothBalances();
$dnBal  = $bal['dishnet']['balance'];
$g4Bal  = $bal['4g']['balance'];
$bcBal  = $bal['bluecard']['balance'] ?? 0;

$filters = array_filter([
    'project'           => $proj,
    'category'          => $filterCat,
    'validation_status' => $filterVal,
    'direction'         => $filterDir,
    'currency'          => $filterCurr,
    'date_from'         => $dateFrom,
    'date_to'           => $dateTo,
    'search'            => $search,
    'person'            => $agentPersonFilter, // scopes field agents to own entries
]);
$totalRows  = $cb->countFiltered($filters);
$totalPages = (int)ceil($totalRows / $perPage);
$filters['limit']  = $perPage;
$filters['offset'] = $offset;
$ledger = $cb->getEntries($filters);

$pendingDisbs = $cb->getPendingDisbursements($proj);
$staffPos     = $cb->getStaffCashPosition($proj);
$pendingTotal = array_sum(array_column($pendingDisbs, 'amount'));
$pendingCount = count($pendingDisbs);
$projBal      = $proj === '4g' ? $g4Bal : ($proj === 'bluecard' ? $bcBal : $dnBal);

// ── Smart history: person→category patterns from past entries ────────────
$_personHistory = $cb->query(
    "SELECT person, category, direction, COUNT(*) as cnt, MAX(date) as last_used
     FROM cb_ledger WHERE person != '' AND status='approved'
     GROUP BY person, category, direction ORDER BY cnt DESC"
);
// Build person→[{category, direction, cnt}] map
$_smartPersons = [];
foreach ($_personHistory as $ph) {
    $pName = trim($ph['person']);
    if (!$pName) continue;
    if (!isset($_smartPersons[$pName])) {
        $_smartPersons[$pName] = ['cats' => [], 'total' => 0, 'last' => $ph['last_used']];
    }
    $_smartPersons[$pName]['cats'][] = [
        'cat' => $ph['category'], 'dir' => $ph['direction'], 'cnt' => (int)$ph['cnt']
    ];
    $_smartPersons[$pName]['total'] += (int)$ph['cnt'];
    if ($ph['last_used'] > $_smartPersons[$pName]['last']) {
        $_smartPersons[$pName]['last'] = $ph['last_used'];
    }
}
// Sort by total usage desc
uasort($_smartPersons, function($a, $b) { return $b['total'] - $a['total']; });
// Also get distinct persons as simple list (for search)
$_allPersonNames = array_keys($_smartPersons);
// Merge with static STAFF (add anyone missing)
$_staticStaff = array_unique(array_merge(CashbookService::STAFF['dishnet'], CashbookService::STAFF['4g']));
foreach ($_staticStaff as $sn) {
    if (!isset($_smartPersons[$sn])) {
        $_smartPersons[$sn] = ['cats' => [], 'total' => 0, 'last' => ''];
        $_allPersonNames[] = $sn;
    }
}
$_allPersonNames = array_unique($_allPersonNames);
sort($_allPersonNames);

// Build reverse map: category→[{person, cnt}] — for "pick category → suggest persons"
$_catToPersons = [];
foreach ($_personHistory as $ph) {
    $cat = trim($ph['category']);
    $pName = trim($ph['person']);
    if (!$cat || !$pName) continue;
    if (!isset($_catToPersons[$cat])) $_catToPersons[$cat] = [];
    $_catToPersons[$cat][] = ['name' => $pName, 'cnt' => (int)$ph['cnt']];
}

// Fallback: for categories with NO person data, mine descriptions from recent entries
$_descHistory = $cb->query(
    "SELECT category, description, COUNT(*) as cnt, MAX(date) as last_used
     FROM cb_ledger WHERE person = '' AND description != '' AND status='approved'
     GROUP BY category, description ORDER BY cnt DESC"
);
foreach ($_descHistory as $dh) {
    $cat = trim($dh['category']);
    if (!$cat) continue;
    // Only add if this category has fewer than 3 person-based entries
    $existingCount = count($_catToPersons[$cat] ?? []);
    if ($existingCount >= 3) continue;
    if (!isset($_catToPersons[$cat])) $_catToPersons[$cat] = [];
    // Truncate description to first meaningful chunk (max 45 chars)
    $desc = trim($dh['description']);
    if (strlen($desc) > 45) {
        // Try to cut at a word boundary
        $desc = substr($desc, 0, 45);
        $lastSpace = strrpos($desc, ' ');
        if ($lastSpace > 20) $desc = substr($desc, 0, $lastSpace);
        $desc .= '…';
    }
    // Check not a duplicate of existing name
    $isDup = false;
    foreach ($_catToPersons[$cat] as $existing) {
        if (strcasecmp($existing['name'], $desc) === 0) { $isDup = true; break; }
    }
    if (!$isDup && (int)$dh['cnt'] >= 1) {
        $_catToPersons[$cat][] = [
            'name' => $desc, 'cnt' => (int)$dh['cnt'], 'src' => 'desc'
        ];
    }
}

// Sort each category's entries by count desc
foreach ($_catToPersons as &$_cpArr) {
    usort($_cpArr, function($a, $b) { return $b['cnt'] - $a['cnt']; });
    $_cpArr = array_slice($_cpArr, 0, 8); // keep top 8
}
unset($_cpArr);

// ── Seed defaults from BookKeeper history (fills gaps where cb_ledger person is empty) ──
$_bkSeeds = [
    // Staff payments
    'Salary'         => ['Bidal','Emmanuel','Ochiti','Kamanda','Modi Mawa Francis','Diko','Amos','Mackline Anena'],
    'Transport Allowance' => ['Bidal','Emmanuel','Diko','Mackline Anena','Modi Mawa Francis','Kamanda'],
    'Food Allowance' => ['Bidal','Emmanuel','Ochiti','Kamanda','Diko','Mackline Anena','Modi Mawa Francis'],
    'Bonus'          => ['Bidal','Emmanuel','Ochiti','Kamanda','Amos','Diko','Modi Mawa Francis','Mackline Anena'],
    'Employee Benefit'=> ['Kamanda','Emmanuel','Bidal','Ochiti','Diko','Amos'],
    'Staff Advance'  => ['BBC','Bidal','Emmanuel','Kamanda','Diko','Ochiti','Amos','Justus','Meckline','Modi Mawa Francis'],
    'SSP Advance'    => ['BBC','Bidal','Emmanuel','Kamanda','Diko','Ochiti','Amos','Modi Mawa Francis'],
    'Commission'     => ['Christine','NID Bank','Robert','Sokiri','Peter','Stephen Eku','Ahmed - ICAP','Charles - Afenet','Kennedy Bidali - Afenet','Emmanuel Alli - AFENET'],
    // Sites
    'Site Power'     => ['JEDCO','Electricity - Tomping','Electricity - Munuki','Electricity - City Mall'],
    'Site Rent'      => ['Tomping Branch','City Mall Office','Tower GMSH','UAP Tower','Guest House','Tower Nimule','Gudele Medical','UNMISS Accommodation'],
    'Site Expense'   => ['Emmanuel','Kamanda','Bidal','Kennedy','Geoffrey','Sokiri'],
    // Suppliers & Vendors (from BookKeeper narrations)
    'Local Purchase' => ['Atul','Francis','Kamanda','Amos','Gukina Electricals','Chesco Hi-Tech','CVL General Supply','Bimot Enterprises','Dubai Store','C/C Electrical Shop','Dubai For Exhibition'],
    'Capital Purchase'=> ['Bimot Enterprises','Friends IT','OYEI Times','Flyfine Digital','CVL General Supply'],
    'Bandwidth'      => ['4G Telecom','Bentley Walker','Intersat / BSS Africa','LEOKONNECT','Liquid Telecom','Digital Trend / Wilken','XCEED NET'],
    'Airtime'        => ['MTN','Zain','VivaCell'],
    'Travel & Field' => ['Kamanda','Emmanuel','Francis','Amos','Bidal','Junubin Logistics','Sokiri'],
    // Finance
    'Exchange'       => ['Diko','Rupesh','BBC','Juba Trading'],
    'Tax'            => ['NRA Audit','BPT Tax','PIT Tax','Excise Tax','WT Rental Tax'],
    'Loan Given'     => ['Harpal Bapu','Arkangelo','Dynamic Construction','Build Africa','Bhavin','Staff Advance'],
    'Loan Received'  => ['Waka General Trading','4G Telecom Advance','BBC'],
    'Interco Out'    => ['DishNet 4G','BlueCARD','Build Africa'],
    'Bank Transfer'  => ['ECO Bank','Stanbic Bank','Equity Bank'],
    'Discount'       => ['Customer Discount','Promotional'],
    'Build Africa'   => ['Build Africa','Tax & Work Permit','Iron Bed'],
    'Misc Expense'   => ['Arkangelo','Charles','Rupesh','Chirag Patel','Amos','Yash','Manoj Bhai'],
    'Refund'         => ['Customer Refund'],
    'Customer Refund'=> ['Customer Refund','Overpayment','Service Issue','Cancelled Subscription'],
    'Customer Commission' => ['Referral Bonus','Loyalty Discount','Promotional','Agent Bonus'],
    // v4.9.10: new categories from BookKeeper audit
    'Govt Fees'      => ['NCA Administrative Fees','NCA Operation Fees','NRA Audit Fees','USAF (Universal Service Fund)','Excise Tax','PIT Tax','BPT Tax','Rental Tax (WT)'],
    'Legal Fees'     => ['Lawyer Fees','Court Fees','Work Permit','Visa Fees','Registration'],
    'Vehicle'        => ['Maintenance','Fuel / Diesel','Insurance','Spare Parts','Registration','Tyre'],
    'Advertising'    => ['Facebook Ads','Google Ads','Print / Billboard','Promotional Material'],
    'Partner Remuneration' => ['Tom (Joseph Luate)','Bhavin (Madlani)','Nirmal (Samani)','Paji (Shamshare Singh)','Rupesh'],
    'Renewal Charges'=> ['License Renewal','Domain / Hosting','Software Charges','Splynx','Zoom','SSL Certificate','AFRINIC'],
];
foreach ($_bkSeeds as $seedCat => $seedNames) {
    $existing = count($_catToPersons[$seedCat] ?? []);
    if ($existing >= 6) continue; // v4.9.10: raised from 4 to fill more categories
    if (!isset($_catToPersons[$seedCat])) $_catToPersons[$seedCat] = [];
    $existingNames = array_map(function($e) { return strtolower($e['name']); }, $_catToPersons[$seedCat]);
    foreach ($seedNames as $sn) {
        if (in_array(strtolower($sn), $existingNames)) continue;
        $_catToPersons[$seedCat][] = ['name' => $sn, 'cnt' => 0, 'src' => 'seed'];
    }
}

// ── Accounting integrity alerts ───────────────────────────────────────────
$_alerts = [];
// Alert 1: Pending disbursements older than 14 days (no receipt follow-up)
$overduePend = array_filter($pendingDisbs, fn($d) => ($d['days_pending']??0) > 14);
if (count($overduePend) > 0) {
    $_alerts[] = ['level'=>'red','icon'=>'⏰','title'=>count($overduePend).' disbursement(s) with no receipt >14 days',
        'detail'=>'Staff received cash but no voucher received. Oldest: '.(reset($overduePend)['date']??'').' — '.htmlspecialchars(reset($overduePend)['person']??'').' $'.number_format(reset($overduePend)['amount'],0),
        'link'=>'?'.http_build_query(array_merge($_GET,['cb_view'=>'pending']))];
}
// Alert 2: Negative balance
if ($projBal < 0) {
    $_alerts[] = ['level'=>'red','icon'=>'🚨','title'=>'Cash balance is NEGATIVE: $'.number_format($projBal,2),
        'detail'=>'Outflows exceed inflows. Check for missing receipts or unrecorded bank withdrawals.','link'=>null];
}
// Alert 3: Duplicate SRs in ledger
$_dupCheck = $cb->query("SELECT sr,COUNT(*) as cnt FROM cb_ledger WHERE project=? AND sr!='' GROUP BY sr HAVING cnt>1 LIMIT 5", [$proj]);
if (!empty($_dupCheck)) {
    $_alerts[] = ['level'=>'amber','icon'=>'♻️','title'=>count($_dupCheck).' duplicate SR number(s) detected',
        'detail'=>'SRs appearing more than once: '.implode(', ', array_column($_dupCheck,'sr')),'link'=>null];
}
// Alert 4: Large pending disbursements > $500 outstanding
$largePend = array_filter($pendingDisbs, fn($d) => $d['amount'] >= 500);
if (count($largePend) > 0) {
    $_alerts[] = ['level'=>'amber','icon'=>'💸','title'=>count($largePend).' large disbursement(s) ≥$500 without receipt',
        'detail'=>'Total: $'.number_format(array_sum(array_column(array_values($largePend),'amount')),0).' pending','link'=>'?'.http_build_query(array_merge($_GET,['cb_view'=>'pending']))];
}
// Alert 5: CRM sync stale
$_cbMeta = $cb->getMeta();
$_syncAt  = $_cbMeta['crm_sync_at'] ?? null;
if (!$_syncAt || strtotime($_syncAt) < strtotime('-2 days')) {
    $_alerts[] = ['level'=>'amber','icon'=>'🔄','title'=>'CRM sync has not run in '.($_syncAt ? floor((time()-strtotime($_syncAt))/86400).'d' : 'never'),
        'detail'=>'Automatic cash receipts from UCRM may be missing from the cashbook.','link'=>null];
}
// Alert 6: Cashbook has entries but seeded balance looks off (entries last 7 days = 0 cash in)
$_recentIn = $cb->query(
    "SELECT SUM(amount) as tot FROM cb_ledger WHERE project=? AND direction='in' AND date>=? AND status='approved'",
    [$proj, date('Y-m-d', strtotime('-7 days'))]
);
$_recentInAmt = (float)($_recentIn[0]['tot'] ?? 0);
if ($projBal > 0 && $_recentInAmt == 0 && $pendingCount > 0) {
    $_alerts[] = ['level'=>'amber','icon'=>'📭','title'=>'No cash received in last 7 days',
        'detail'=>'If collections are happening, check CRM sync or manual entries.','link'=>null];
}
$alertCount = count($_alerts);

function cbValBadge(string $s): string {
    $map = ['voucher'=>['#dbeafe','#1e40af','Voucher'],'wr'=>['#f1f5f9','#374151','WR'],
        'online'=>['#ede9fe','#5b21b6','Online'],'jedco'=>['#e0f2fe','#0369a1','Jedco'],
        'pending'=>['#fef3c7','#92400e','&#9888; Pending'],'done'=>['#dcfce7','#15803d','&#10003; Done'],
        'exchange'=>['#f0fdf4','#065f46','Exch'],'na'=>['#f8fafc','#94a3b8','&mdash;'],
    ];
    $c = $map[$s] ?? ['#f8fafc','#94a3b8',$s];
    return '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:800;background:'.$c[0].';color:'.$c[1].';white-space:nowrap;">'.$c[2].'</span>';
}
function cbCatIcon(string $cat): string {
    $m=['Receipt'=>'&#128176;','Salary'=>'&#128188;','Transport Allowance'=>'&#128663;','Food Allowance'=>'&#127860;',
        'Commission'=>'&#129309;','Site Power'=>'&#9889;','Site Rent'=>'&#127959;','Exchange'=>'&#128178;',
        'Tax'=>'&#128203;','Travel & Field'=>'&#9992;','Local Purchase'=>'&#128717;','Airtime'=>'&#128241;',
        'Loan Given'=>'&#128228;','Loan Received'=>'&#128229;','Bank Transfer'=>'&#127974;','Bonus'=>'&#127873;',
        'Misc Expense'=>'&#128204;','Opening Balance'=>'&#127937;','Build Africa'=>'&#127962;',
        'Site Expense'=>'&#128295;','Refund'=>'&#8617;','Discount'=>'&#127991;',
        'Interco In'=>'&#128256;','Interco Out'=>'&#128256;',
        'Customer Refund'=>'&#8617;','Customer Commission'=>'&#129309;','Staff Advance'=>'&#128184;',
    ];
    return $m[$cat] ?? '&#128204;';
}
?>
<style>
/* ═══════════════════════════════════════════════════
   CASHBOOK v3 — Mobile-First 3-Screen Design
   Screens: home | ledger | pending | payroll | sites | summary
   ═══════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
:root{
  --red:#D41C1C; --red-dk:#a51515; --dark:#0f0f0f; --green:#059669;
  --amber:#d97706; --blue:#1e40af; --mute:#94a3b8;
  --bg:#f5f5f2; --card:#fff; --border:#e8e8e3;
  --font:'Inter',-apple-system,'Segoe UI',sans-serif;
}
* { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }

/* ── Shell ── */
.cb3-wrap{display:flex;flex-direction:column;min-height:calc(100dvh - 56px);background:var(--bg);padding-bottom:80px;}

/* ── Top nav bar ── */
.cb3-bar{background:var(--dark);padding:0 14px;display:flex;align-items:center;gap:10px;height:52px;position:sticky;top:0;z-index:200;}
.cb3-back{display:flex;align-items:center;justify-content:center;width:34px;height:34px;background:rgba(255,255,255,.08);border-radius:9px;text-decoration:none;color:#fff;font-size:17px;flex-shrink:0;border:none;cursor:pointer;}
.cb3-title{font-size:17px;font-weight:800;color:#fff;letter-spacing:.3px;flex:1;}
.cb3-proj{display:flex;background:rgba(255,255,255,.08);border-radius:20px;padding:3px;gap:1px;}
.cb3-proj a{padding:4px 12px;border-radius:15px;font-size:11px;font-weight:600;text-decoration:none;color:rgba(255,255,255,.45);transition:.1s;}
.cb3-proj a.on{background:var(--red);color:#fff;}
.cb3-fab{background:var(--red);color:#fff;border:none;border-radius:9px;padding:7px 13px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}

/* ── Tab strip ── */
.cb3-tabs{background:#fff;border-bottom:2px solid var(--border);display:flex;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;}
.cb3-tabs::-webkit-scrollbar{display:none;}
.cb3-tab{padding:11px 14px;font-size:12px;font-weight:600;color:var(--mute);white-space:nowrap;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:4px;}
.cb3-tab.on{color:var(--red);border-bottom-color:var(--red);}
.cb3-badge{background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;}

/* ── HOME screen ── */
.cb3-hero{background:var(--dark);padding:20px 18px 18px;position:relative;overflow:hidden;}
.cb3-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:var(--red);opacity:.07;border-radius:50%;}
.cb3-hero-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.35);margin-bottom:6px;}
.cb3-hero-bal{font-size:46px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1;margin-bottom:4px;}
.cb3-hero-bal span{font-size:24px;font-weight:600;color:rgba(255,255,255,.4);margin-right:2px;}
.cb3-hero-sub{font-size:11px;color:rgba(255,255,255,.3);margin-top:6px;}
.cb3-hero-pills{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap;}
.cb3-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:20px;font-size:10px;font-weight:700;}
.cb3-pill-g{background:rgba(5,150,105,.15);color:#4ade80;}
.cb3-pill-r{background:rgba(212,28,28,.15);color:#fca5a5;}
.cb3-pill-a{background:rgba(217,119,6,.15);color:#fcd34d;}

/* ── Stat grid ── */
.cb3-stats{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);}
.cb3-stat{background:#fff;padding:14px 16px;}
.cb3-stat-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mute);margin-bottom:4px;}
.cb3-stat-val{font-size:22px;font-weight:900;letter-spacing:-1px;line-height:1;}
.cb3-stat-sub{font-size:10px;color:var(--mute);margin-top:3px;}
.cb3-stat.span2{grid-column:span 2;}
.cb3-stat-val.g{color:var(--green);}
.cb3-stat-val.r{color:#dc2626;}
.cb3-stat-val.a{color:var(--amber);}
.cb3-stat-val.b{color:var(--blue);}

/* ── Quick action tiles ── */
.cb3-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:14px;}
.cb3-act{background:#fff;border-radius:14px;padding:16px 14px;display:flex;flex-direction:column;gap:6px;text-decoration:none;border:1.5px solid var(--border);cursor:pointer;font-family:var(--font);transition:.12s;-webkit-tap-highlight-color:transparent;}
.cb3-act:active{transform:scale(.97);}
.cb3-act-ic{font-size:26px;line-height:1;}
.cb3-act-lbl{font-size:13px;font-weight:800;color:#0f0f0f;}
.cb3-act-sub{font-size:10px;color:var(--mute);}
.cb3-act.primary{background:var(--red);border-color:var(--red);}
.cb3-act.primary .cb3-act-lbl,.cb3-act.primary .cb3-act-sub{color:#fff;}
.cb3-act.primary .cb3-act-sub{color:rgba(255,255,255,.65);}
.cb3-act-badge{background:var(--amber);color:#fff;border-radius:10px;padding:1px 7px;font-size:9px;font-weight:800;align-self:flex-start;}

/* ── Sync strip ── */
.cb3-sync{background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;padding:10px 16px;cursor:pointer;}
.cb3-sync.stale{background:#fff7ed;}
.cb3-sync.fresh{background:#f0fdf4;}
.cb3-sync-ic{font-size:15px;}
.cb3-sync-tx{flex:1;font-size:11px;font-weight:600;color:#374151;}
.cb3-sync-arr{color:var(--mute);font-size:18px;}

/* ── Ledger cards (mobile) / table (desktop) ── */
.cb3-search-bar{padding:10px 14px;background:#fff;border-bottom:1px solid var(--border);display:flex;gap:8px;align-items:center;position:sticky;top:52px;z-index:100;}
.cb3-search-inp{flex:1;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;font-family:var(--font);outline:none;background:#fff;}
.cb3-search-inp:focus{border-color:var(--red);background:#fff;}
.cb3-filter-btn{padding:8px 12px;background:#f8f8f5;border:1.5px solid var(--border);border-radius:9px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;color:#374151;font-family:var(--font);}
.cb3-filter-btn.on{background:var(--dark);color:#fff;border-color:var(--dark);}

/* Mobile: Card layout */
.cb3-cards{padding:8px 12px;display:flex;flex-direction:column;gap:7px;}
.cb3-card{background:#fff;border-radius:12px;border:1px solid var(--border);overflow:hidden;display:flex;position:relative;}
.cb3-card.pend{border-left:3px solid var(--amber);}
.cb3-card-sel{width:38px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border-right:1px solid var(--border);background:#fafaf8;}
.cb3-card-body{flex:1;padding:10px 12px;min-width:0;}
.cb3-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px;}
.cb3-card-desc{font-size:13px;font-weight:600;color:#0f0f0f;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;}
.cb3-card-amt{font-size:14px;font-weight:700;white-space:nowrap;flex-shrink:0;}
.cb3-card-amt.in{color:var(--green);}
.cb3-card-amt.out{color:#dc2626;}
.cb3-card-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.cb3-card-date{font-size:11px;color:var(--mute);font-weight:500;}
.cb3-card-cat{font-size:10px;background:#f1f5f9;color:#374151;padding:2px 7px;border-radius:10px;font-weight:500;}
.cb3-card-src{font-size:9px;padding:1px 6px;border-radius:10px;font-weight:800;}
.cb3-card-src.crm{background:#dbeafe;color:#1e40af;}
.cb3-card-src.pwa{background:#dcfce7;color:#15803d;}
.cb3-card-sr{font-size:9px;color:var(--mute);font-family:monospace;}
.cb3-card-menu{width:38px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border-left:1px solid var(--border);background:#fafaf8;cursor:pointer;border:none;font-size:17px;color:var(--mute);}

/* Desktop: show table instead */
@media(min-width:700px){
  .cb3-cards{display:none;}
  .cb3-tbl-wrap{display:block!important;}
  .cb3-search-bar{top:0;}
  .cb3-bar{position:static;}
  .cb3-tabs{position:static;}
}
.cb3-tbl-wrap{display:none;overflow-x:auto;background:#fff;}

/* ── Filter drawer ── */
.cb3-fdr{display:none;background:#fff;border-bottom:1px solid var(--border);padding:12px 14px;gap:8px;flex-wrap:wrap;}
.cb3-fdr.open{display:flex;}
.cb3-fdr-fi{padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;background:#fff;color:#0a0a0a;outline:none;}
.cb3-fdr-fi:focus{border-color:var(--red);}

/* ── Pager ── */
.cb3-pager{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#fff;border-top:1px solid var(--border);margin-top:auto;}
.cb3-pg-info{font-size:12px;color:var(--mute);font-weight:500;}
.cb3-pg-btns{display:flex;gap:4px;}
.cb3-pg{padding:6px 11px;border:1px solid var(--border);border-radius:7px;background:#fff;font-size:12px;font-weight:500;cursor:pointer;color:#374151;text-decoration:none;}
.cb3-pg.on{background:var(--dark);color:#fff;border-color:var(--dark);}

/* ── Bulk bar ── */
.cb3-bulk{display:none;position:sticky;top:52px;z-index:150;background:var(--dark);padding:9px 14px;align-items:center;gap:8px;flex-wrap:wrap;}
.cb3-bulk.show{display:flex;}
.cb3-bulk-cnt{font-size:13px;font-weight:800;color:#fff;white-space:nowrap;}
.cb3-bulk-btns{display:flex;gap:6px;flex-wrap:wrap;flex:1;}
.cb3-bulk-btn{border:none;border-radius:7px;padding:5px 11px;font-size:11px;font-weight:600;cursor:pointer;}
.cb3-bulk-del{background:#dc2626;color:#fff;border:none;border-radius:7px;padding:7px 15px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}

/* ── Panel (pending/payroll etc) ── */
.cb3-panel{padding:14px;}
/* Pending cards */
.cb3-disb{border:1px solid var(--border);border-radius:12px;padding:14px;background:#fff;margin-bottom:8px;}
.cb3-disb.overdue{border-left:3px solid #dc2626;}
.cb3-disb.mild{border-left:3px solid var(--amber);}
.cb3-disb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
.cb3-disb-person{font-weight:800;font-size:13px;}
.cb3-disb-desc{font-size:12px;color:#64748b;margin-top:2px;}
.cb3-disb-amt{font-size:18px;font-weight:900;color:#dc2626;white-space:nowrap;}
.cb3-disb-meta{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;}
.cb3-disb-tag{background:#f1f5f9;color:#374151;border-radius:4px;padding:1px 7px;font-size:10px;font-weight:700;}
.cb3-disb-acts{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;}
.cb3-settle{padding:8px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;color:#15803d;font-size:12px;font-weight:600;cursor:pointer;}
.cb3-remind{padding:8px 14px;background:#f8fafc;border:1.5px solid var(--border);border-radius:8px;color:#374151;font-size:12px;font-weight:500;cursor:pointer;}
/* Staff grid */
.cb3-sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:12px;}
.cb3-sc{border:1px solid var(--border);border-radius:10px;padding:12px;background:#fff;}
.cb3-sc-name{font-weight:800;font-size:12px;display:flex;align-items:center;gap:5px;}
.cb3-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.cb3-dot.overdue{background:#dc2626;animation:cbPulse 1.5s infinite;}
.cb3-dot.pending{background:var(--amber);}
@keyframes cbPulse{0%,100%{opacity:1}50%{opacity:.3}}
/* Summary bars */
.cb3-sum-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;}
.cb3-sum-row:last-child{border:none;}
.cb3-sum-lbl{font-size:11px;font-weight:600;min-width:140px;}
.cb3-sum-bw{flex:1;height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden;}
.cb3-sum-b{height:100%;border-radius:3px;}
.cb3-sum-a{font-family:monospace;font-size:11px;font-weight:700;min-width:65px;text-align:right;}
/* Interco cards */
.cb3-iccard{background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:12px;padding:16px;color:#fff;margin-bottom:10px;}
.cb3-iclbl{font-size:10px;color:rgba(255,255,255,.4);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;}
.cb3-icval{font-size:24px;font-weight:900;letter-spacing:-1px;}
/* Site table */
.cb3-site-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.cb3-site-tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-size:9px;font-weight:800;color:var(--mute);text-transform:uppercase;letter-spacing:.5px;}
.cb3-site-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;}
/* Payroll table */
.cb3-pay-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.cb3-pay-tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-size:9px;font-weight:800;color:var(--mute);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);}
.cb3-pay-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;font-weight:500;}
.cb3-pay-tbl tfoot td{font-weight:900;background:#f8fafc;}

/* ── Modals (entry / settle / edit / CRUD) ── */
.cb3-overlay{display:none;position:fixed;inset:0;background:rgba(10,10,10,.6);z-index:9000;align-items:flex-end;justify-content:center;}
.cb3-overlay.open{display:flex;}
.cb3-sheet{background:#fff;border-radius:20px 20px 0 0;width:100%;max-width:580px;max-height:93dvh;overflow-y:auto;transform:translateY(100%);transition:transform .22s cubic-bezier(.32,1,.23,1);}
.cb3-overlay.open .cb3-sheet{transform:translateY(0);}
@media(min-width:580px){.cb3-overlay{align-items:center;}.cb3-sheet{border-radius:18px;max-height:88vh;margin:auto;}}
.cb3-sh-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1;}
.cb3-sh-title{font-size:15px;font-weight:900;}
.cb3-sh-close{width:30px;height:30px;border-radius:8px;border:1px solid var(--border);background:none;cursor:pointer;font-size:14px;color:#64748b;font-family:var(--font);}
.cb3-sh-body{padding:16px 18px;}
.cb3-sh-foot{padding:12px 18px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:#fff;}
/* Form elements */
.cb3-fg{margin-bottom:12px;}
.cb3-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);display:block;margin-bottom:5px;}
.cb3-inp{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none;}
.cb3-inp:focus{border-color:var(--red);}
.cb3-sel{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:#fff;outline:none;cursor:pointer;}
.cb3-sel:focus{border-color:var(--red);}
.cb3-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.cb3-aw{position:relative;}
.cb3-as{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:15px;font-weight:800;color:var(--mute);pointer-events:none;}
.cb3-ai{padding-left:28px!important;font-size:22px!important;font-weight:900!important;letter-spacing:-1px;}
/* Direction picker */
.cb3-dir-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
.cb3-dir-btn{padding:14px 10px;border-radius:12px;border:2px solid var(--border);background:#fff;cursor:pointer;text-align:center;font-family:var(--font);transition:.12s;}
.cb3-dir-btn.in.sel{border-color:var(--green);background:#f0fdf4;}
.cb3-dir-btn.out.sel{border-color:#dc2626;background:#fef2f2;}
.cb3-warn{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:12px;color:#78350f;font-weight:600;margin-bottom:12px;}
/* Buttons */
.cb3-btn-primary{background:var(--red);color:#fff;border:none;border-radius:10px;padding:11px 20px;font-size:13px;font-weight:700;cursor:pointer;}
.cb3-btn-secondary{background:#f8fafc;color:#374151;border:1px solid var(--border);border-radius:10px;padding:11px 16px;font-size:13px;font-weight:500;cursor:pointer;}
.cb3-btn-danger{background:#fff1f2;color:#dc2626;border:1.5px solid #fecaca;border-radius:10px;padding:11px 16px;font-size:13px;font-weight:700;cursor:pointer;}
.cb3-btn-green{background:#059669;color:#fff;border:none;border-radius:10px;padding:11px 16px;font-size:13px;font-weight:700;cursor:pointer;}
/* No-seed banner */
.cb3-noseed{background:#fffbeb;border:2px solid #fde68a;border-radius:14px;padding:28px 20px;margin:16px;text-align:center;}
/* Misc */
.cb3-empty{text-align:center;padding:40px 20px;color:var(--mute);font-size:13px;}
.cb3-fi{padding:7px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:12px;background:#fff;color:#0a0a0a;outline:none;}
.cb3-fi:focus{border-color:var(--red);}
/* backwards compat for old class refs still in view sections */
.cbv2-disb{border:1px solid var(--border);border-radius:12px;padding:14px;background:#fff;margin-bottom:8px;}
.cbv2-disb.overdue{border-left:3px solid #dc2626;}
.cbv2-disb.mild{border-left:3px solid var(--amber);}
.cbv2-disb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
.cbv2-disb-person{font-weight:800;font-size:13px;}
.cbv2-disb-desc{font-size:12px;color:#64748b;margin-top:2px;}
.cbv2-disb-amt{font-size:18px;font-weight:900;color:#dc2626;white-space:nowrap;}
.cbv2-disb-meta{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;}
.cbv2-disb-tag{background:#f1f5f9;color:#374151;border-radius:4px;padding:1px 7px;font-size:10px;font-weight:700;}
.cbv2-disb-acts{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;}
.cbv2-settle{padding:8px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;color:#15803d;font-size:12px;font-weight:600;cursor:pointer;}
.cbv2-remind{padding:8px 14px;background:#f8fafc;border:1.5px solid var(--border);border-radius:8px;color:#374151;font-size:12px;font-weight:500;cursor:pointer;}
.cbv2-sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;margin-bottom:12px;}
.cbv2-sc{border:1px solid var(--border);border-radius:10px;padding:12px;background:#fff;}
.cbv2-sc-name{font-weight:800;font-size:12px;display:flex;align-items:center;gap:5px;}
.cbv2-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.cbv2-dot.overdue{background:#dc2626;}
.cbv2-dot.pending{background:var(--amber);}
.cbv2-sum-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;}
.cbv2-sum-row:last-child{border:none;}
.cbv2-sum-label{font-size:11px;font-weight:600;min-width:140px;}
.cbv2-sum-bw{flex:1;height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden;}
.cbv2-sum-b{height:100%;border-radius:3px;}
.cbv2-sum-a{font-family:monospace;font-size:11px;font-weight:700;min-width:65px;text-align:right;}
.cbv2-iccard{background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:12px;padding:16px;color:#fff;margin-bottom:10px;}
.cbv2-iclbl{font-size:10px;color:rgba(255,255,255,.4);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;}
.cbv2-icval{font-size:24px;font-weight:900;letter-spacing:-1px;}
.cbv2-site-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.cbv2-site-tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-size:9px;font-weight:800;color:var(--mute);text-transform:uppercase;letter-spacing:.5px;}
.cbv2-site-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;}
.cbv2-panel{padding:14px;}
.cbv2-tb{display:flex;gap:8px;align-items:center;padding:10px 14px;background:#fff;border-bottom:1px solid var(--border);flex-wrap:wrap;}
.cbv2-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.cbv2-tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-size:9px;font-weight:800;color:var(--mute);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);white-space:nowrap;}
.cbv2-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.cbv2-in{font-family:monospace;font-weight:800;color:var(--green);font-size:12px;}
.cbv2-out{font-family:monospace;font-weight:800;color:#dc2626;font-size:12px;}
.cbv2-noseed{background:#fffbeb;border:2px solid #fde68a;border-radius:14px;padding:28px 24px;margin:20px;text-align:center;}
.cbv2-bsave{background:var(--red);color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;}
.cbv2-bcancel{background:#f8fafc;color:#374151;border:1px solid var(--border);border-radius:9px;padding:10px 16px;font-size:13px;font-weight:500;cursor:pointer;}
.cbv2-bgreen{background:#059669;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-size:12px;font-weight:700;cursor:pointer;}
.cbv2-mo{display:none;position:fixed;inset:0;background:rgba(10,10,10,.65);z-index:9000;align-items:flex-end;justify-content:center;}
.cbv2-mo.open{display:flex;}
.cbv2-mb{background:#fff;border-radius:18px 18px 0 0;width:100%;max-width:560px;max-height:92vh;overflow-y:auto;}
@media(min-width:640px){.cbv2-mb{border-radius:18px;margin:auto;}}
.cbv2-mh{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1;}
.cbv2-mt{font-size:15px;font-weight:900;letter-spacing:.3px;}
.cbv2-mc{width:30px;height:30px;border-radius:7px;border:1px solid var(--border);background:none;cursor:pointer;font-size:14px;color:#64748b;}
.cbv2-mbody{padding:18px 20px;}
.cbv2-mfooter{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:#fff;}
.cbv2-dir-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
.cbv2-dir-btn{padding:12px;border-radius:10px;border:2px solid var(--border);background:#fff;cursor:pointer;text-align:center;font-family:var(--font);transition:.12s;}
.cbv2-dir-btn.in.sel{border-color:var(--green);background:#f0fdf4;}
.cbv2-dir-btn.out.sel{border-color:#dc2626;background:#fef2f2;}
.cbv2-fg{margin-bottom:12px;}
.cbv2-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);display:block;margin-bottom:5px;}
.cbv2-inp{width:100%;padding:9px 11px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;outline:none;}
.cbv2-inp:focus{border-color:var(--red);}
.cbv2-sel{width:100%;padding:9px 11px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;background:#fff;outline:none;cursor:pointer;}
.cbv2-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.cbv2-aw{position:relative;}
.cbv2-as{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:15px;font-weight:800;color:var(--mute);pointer-events:none;}
.cbv2-ai{padding-left:26px!important;font-size:20px!important;font-weight:900!important;}
.cbv2-pw{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:12px;color:#78350f;font-weight:600;margin-bottom:12px;}
.cbv2-so{display:none;position:fixed;inset:0;background:rgba(10,10,10,.65);z-index:9100;align-items:center;justify-content:center;}
.cbv2-so.open{display:flex;}
.cbv2-sbox{background:#fff;border-radius:16px;width:420px;padding:24px;max-width:95vw;}
.cbv2-noseed{background:#fffbeb;border:2px solid #fde68a;border-radius:14px;padding:28px 24px;margin:20px;text-align:center;}
/* ── Bulk bar old compat ── */
#cbBulkBar{font-family:var(--font);}
/* ── CRUD modal old compat ── */
.cbcrud-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:flex-end;justify-content:center;}
.cbcrud-box{background:#fff;border-radius:20px 20px 0 0;padding:20px 18px 28px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;transform:translateY(100%);transition:transform .22s cubic-bezier(.32,1,.23,1);}
.cbcrud-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.cbcrud-title{font-size:15px;font-weight:900;color:#111;}
.cbcrud-close{background:#f1f5f9;border:none;border-radius:50%;width:30px;height:30px;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;}
.cbcrud-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.cbcrud-row.full{grid-template-columns:1fr;}
.cbcrud-lbl{font-size:10px;font-weight:800;color:var(--mute);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.cbcrud-inp{width:100%;border:1.5px solid var(--border);border-radius:8px;padding:8px 10px;font-size:13px;color:#1e293b;box-sizing:border-box;}
.cbcrud-inp:focus{outline:none;border-color:var(--red);}
.cbcrud-actions{display:flex;gap:8px;margin-top:16px;}
.cbcrud-save{flex:1;background:var(--red);color:#fff;border:none;border-radius:10px;padding:12px;font-size:13px;font-weight:700;cursor:pointer;}
.cbcrud-del{background:#fff1f2;color:#dc2626;border:1.5px solid #fecaca;border-radius:10px;padding:12px 16px;font-size:13px;font-weight:700;cursor:pointer;}
.cbcrud-src{font-size:10px;background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:10px;font-weight:700;}
@media(min-width:541px){.cbcrud-ov{align-items:center;}.cbcrud-box{border-radius:16px;max-height:85vh;}}
.cbv2-wrap{display:flex;flex-direction:column;gap:0;background:var(--bg);min-height:calc(100vh - 120px);}
.cbv2-topbar{background:#0a0a0a;padding:14px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.cbv2-title{font-size:16px;font-weight:900;color:#fff;letter-spacing:.5px;}
.cbv2-proj-pill{display:flex;background:rgba(255,255,255,.08);border-radius:20px;padding:3px;gap:1px;}
.cbv2-proj-btn{padding:4px 14px;border-radius:16px;font-size:11px;font-weight:700;cursor:pointer;border:none;background:transparent;color:rgba(255,255,255,.4);transition:.12s;text-decoration:none;display:inline-block;}
.cbv2-proj-btn.active{background:#D41C1C;color:#fff;}
.cbv2-spacer{flex:1;}
.cbv2-add-btn{background:#D41C1C;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:5px;}
.cbv2-seed-w{background:#78350f;color:#fef3c7;padding:3px 12px;border-radius:6px;font-size:11px;font-weight:700;}
/* ── Balance strip — mobile first ── */
.cbv2-balstrip{background:#e5e5e0;display:flex;flex-direction:column;gap:1px;}
.cbv2-balcard{background:#fff;padding:14px 18px;}
.cbv2-balcard-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:4px;}
.cbv2-balcard-val{font-size:22px;font-weight:900;letter-spacing:-1px;}
.cbv2-balcard-sub{font-size:10px;color:#94a3b8;margin-top:2px;}
.cbv2-bal-main{padding:16px 18px 14px;}
.cbv2-bal-big{font-size:32px!important;letter-spacing:-2px!important;}
.cbv2-green .cbv2-balcard-val{color:#059669;}
.cbv2-blue .cbv2-balcard-val{color:#1e40af;}
.cbv2-amber .cbv2-balcard-val{color:#d97706;}
.cbv2-dark{background:#0a0a0a!important;}
.cbv2-dark .cbv2-balcard-lbl{color:rgba(255,255,255,.4);}
.cbv2-dark .cbv2-balcard-val{color:#4ade80;}
.cbv2-dark .cbv2-balcard-sub{color:rgba(255,255,255,.3);}
/* Chip row */
.cbv2-balchips{display:grid;grid-template-columns:repeat(3,1fr);background:#e5e5e0;gap:1px;}
.cbv2-chip{background:#fff;padding:10px 12px;}
.cbv2-chip-lbl{font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;margin-bottom:3px;}
.cbv2-chip-val{font-size:16px;font-weight:900;letter-spacing:-.5px;line-height:1;}
.cbv2-chip-sub{font-size:9px;color:#94a3b8;margin-top:2px;}
.cbv2-chip-warn .cbv2-chip-val{color:#d97706;}
.cbv2-chip-blue .cbv2-chip-val{color:#1e40af;}
.cbv2-chip-dark{background:#0a0a0a!important;}
.cbv2-chip-dark .cbv2-chip-lbl{color:rgba(255,255,255,.35);}
.cbv2-chip-dark .cbv2-chip-val{color:#4ade80;}
.cbv2-chip-dark .cbv2-chip-sub{color:rgba(255,255,255,.25);}
/* CRM sync bar */
.cbv2-syncbar{background:#fff;border-bottom:1px solid #e5e5e0;}
.cbv2-syncbar.stale{background:#fff7ed;}
.cbv2-syncbar.fresh{background:#f0fdf4;}
.cbv2-syncbar-main{display:flex;align-items:center;gap:8px;padding:9px 16px;cursor:pointer;-webkit-tap-highlight-color:transparent;}
.cbv2-syncbar-ic{font-size:14px;flex-shrink:0;}
.cbv2-syncbar-tx{flex:1;font-size:11px;font-weight:600;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.cbv2-syncbar-tx strong{font-weight:800;}
.cbv2-syncbar-arr{color:#94a3b8;font-size:18px;font-weight:300;flex-shrink:0;}
.cbv2-alert{display:flex;align-items:center;gap:10px;padding:10px 18px;background:#fffbeb;border-bottom:1px solid #fde68a;cursor:pointer;}
.cbv2-alert-t{font-size:12px;color:#78350f;font-weight:700;}
.cbv2-badge{background:#f59e0b;color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:800;margin-left:4px;}
.cbv2-nav{display:flex;background:#fff;border-bottom:2px solid #e5e5e0;padding:0 18px;overflow-x:auto;}
.cbv2-nav-a{padding:10px 14px;font-size:12px;font-weight:700;color:#94a3b8;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;text-decoration:none;display:flex;align-items:center;gap:4px;}
.cbv2-nav-a:hover{color:#0a0a0a;}
.cbv2-nav-a.active{color:#D41C1C;border-bottom-color:#D41C1C;}
.cbv2-nb{background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;}
.cbv2-tb{display:flex;gap:8px;align-items:center;padding:12px 18px;background:#fff;border-bottom:1px solid #e5e5e0;flex-wrap:wrap;}
.cbv2-fi{padding:7px 10px;border:1.5px solid #e5e5e0;border-radius:7px;font-size:12px;font-family:inherit;background:#fff;color:#0a0a0a;outline:none;}
.cbv2-fi:focus{border-color:#D41C1C;}
.cbv2-search{flex:1;min-width:160px;}
.cbv2-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.cbv2-tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e5e5e0;white-space:nowrap;}
.cbv2-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.cbv2-tbl tr:hover td{background:#fafaf5;}
/* Mobile: hide non-essential columns */
@media(max-width:600px){
  .cb-col-bal,.cb-col-ref,.cb-col-src,.cb-col-cat,.cb-col-person{display:none;}
  .cbv2-tbl td,.cbv2-tbl th{padding:8px 6px;font-size:11px;}
  .cbv2-tbl .cbv2-desc{max-width:120px;}
  /* M-06: Sticky first column so date/ref stays visible while scrolling right */
  .cbv2-tbl td:first-child,
  .cbv2-tbl th:first-child{position:sticky;left:0;z-index:2;background:#fff;box-shadow:2px 0 4px rgba(0,0,0,.06);}
  .cbv2-tbl th:first-child{background:#f8fafc;}
}
.cbv2-sr{font-family:monospace;font-size:10px;color:#94a3b8;}
.cbv2-date{font-family:monospace;font-size:10px;color:#94a3b8;white-space:nowrap;}
.cbv2-desc{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500;}
.cbv2-cat{font-size:11px;color:#64748b;}
.cbv2-in{font-family:monospace;font-weight:800;color:#059669;font-size:12px;}
.cbv2-out{font-family:monospace;font-weight:800;color:#dc2626;font-size:12px;}
.cbv2-bal{font-family:monospace;font-weight:700;font-size:12px;}
.cbv2-person{font-size:11px;color:#64748b;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cbv2-pager{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;background:#f8fafc;border-top:1px solid #e5e5e0;}
.cbv2-pi{font-size:11px;color:#94a3b8;}
.cbv2-pb{display:flex;gap:4px;}
.cbv2-pg{padding:5px 10px;border:1px solid #e5e5e0;border-radius:6px;background:#fff;font-size:11px;font-weight:700;cursor:pointer;color:#374151;text-decoration:none;}
.cbv2-pg.sel{background:#0a0a0a;color:#fff;border-color:#0a0a0a;}
.cbv2-pg:hover:not(.sel){background:#f1f5f9;}
.cbv2-panel{padding:18px;}
.cbv2-disb{border:1px solid #e5e5e0;border-radius:12px;padding:14px;background:#fff;margin-bottom:10px;}
.cbv2-disb.overdue{border-left:3px solid #dc2626;}
.cbv2-disb.mild{border-left:3px solid #f59e0b;}
.cbv2-disb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;}
.cbv2-disb-person{font-weight:800;font-size:13px;}
.cbv2-disb-desc{font-size:12px;color:#64748b;margin-top:2px;max-width:500px;}
.cbv2-disb-amt{font-size:18px;font-weight:900;color:#dc2626;white-space:nowrap;}
.cbv2-disb-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.cbv2-disb-tag{background:#f1f5f9;color:#374151;border-radius:4px;padding:1px 7px;font-size:10px;font-weight:700;}
.cbv2-disb-acts{display:flex;gap:6px;margin-top:10px;}
.cbv2-settle{padding:7px 14px;background:#f0fdf4;border:1.5px solid #86efac;border-radius:7px;color:#15803d;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;}
.cbv2-remind{padding:7px 14px;background:#f8fafc;border:1.5px solid #e5e5e0;border-radius:7px;color:#374151;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;}
.cbv2-sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;margin-bottom:16px;}
.cbv2-sc{border:1px solid #e5e5e0;border-radius:10px;padding:12px;background:#fff;}
.cbv2-sc-name{font-weight:800;font-size:12px;display:flex;align-items:center;gap:5px;}
.cbv2-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.cbv2-dot.overdue{background:#dc2626;animation:cbPulse 1.5s infinite;}
.cbv2-dot.pending{background:#f59e0b;}
@keyframes cbPulse{0%,100%{opacity:1}50%{opacity:.3}}
.cbv2-sum-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;}
.cbv2-sum-row:last-child{border:none;}
.cbv2-sum-label{font-size:11px;font-weight:600;min-width:160px;}
.cbv2-sum-bw{flex:1;height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden;}
.cbv2-sum-b{height:100%;border-radius:3px;}
.cbv2-sum-a{font-family:monospace;font-size:11px;font-weight:700;min-width:70px;text-align:right;}
.cbv2-site-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.cbv2-site-tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;}
.cbv2-site-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;}
.cbv2-iccard{background:linear-gradient(135deg,#0f172a,#1e293b);border-radius:14px;padding:16px;color:#fff;margin-bottom:12px;}
.cbv2-iclbl{font-size:10px;color:rgba(255,255,255,.4);font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;}
.cbv2-icval{font-size:24px;font-weight:900;letter-spacing:-1px;}
/* modal */
.cbv2-mo{display:none;position:fixed;inset:0;background:rgba(10,10,10,.65);z-index:9000;align-items:flex-end;justify-content:center;}
.cbv2-mo.open{display:flex;}
.cbv2-mb{background:#fff;border-radius:18px 18px 0 0;width:100%;max-width:560px;max-height:92vh;overflow-y:auto;}
@media(min-width:640px){.cbv2-mb{border-radius:18px;margin:auto;}}
.cbv2-mh{padding:16px 20px;border-bottom:1px solid #e5e5e0;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1;}
.cbv2-mt{font-size:15px;font-weight:900;letter-spacing:.3px;}
.cbv2-mc{width:30px;height:30px;border-radius:7px;border:1px solid #e5e5e0;background:none;cursor:pointer;font-size:14px;color:#64748b;}
.cbv2-mbody{padding:18px 20px;}
.cbv2-mfooter{padding:14px 20px;border-top:1px solid #e5e5e0;display:flex;gap:8px;justify-content:flex-end;position:sticky;bottom:0;background:#fff;}
.cbv2-dir-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
.cbv2-dir-btn{padding:12px;border-radius:10px;border:2px solid #e5e5e0;background:#fff;cursor:pointer;text-align:center;font-family:inherit;transition:.12s;}
.cbv2-dir-btn.in.sel{border-color:#059669;background:#f0fdf4;}
.cbv2-dir-btn.out.sel{border-color:#dc2626;background:#fef2f2;}
.cbv2-fg{margin-bottom:12px;}
.cbv2-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;display:block;margin-bottom:5px;}
.cbv2-inp{width:100%;padding:9px 11px;border:1.5px solid #e5e5e0;border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:.12s;}
.cbv2-inp:focus{border-color:#D41C1C;}
.cbv2-sel{width:100%;padding:9px 11px;border:1.5px solid #e5e5e0;border-radius:8px;font-size:13px;font-family:inherit;background:#fff;outline:none;cursor:pointer;}
.cbv2-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.cbv2-aw{position:relative;}
.cbv2-as{position:absolute;left:11px;top:50%;transform:translateY(-50%);font-size:15px;font-weight:800;color:#94a3b8;pointer-events:none;}
.cbv2-ai{padding-left:26px!important;font-size:20px!important;font-weight:900!important;}
.cbv2-pw{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;font-size:12px;color:#78350f;font-weight:600;margin-bottom:12px;}
.cbv2-bsave{background:#D41C1C;color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
.cbv2-bcancel{background:#f8fafc;color:#374151;border:1px solid #e5e5e0;border-radius:9px;padding:10px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;}
/* Searchable category dropdown */
.cbv2-cg{padding:5px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;background:#f8f8f5;border-top:1px solid #f1f5f9;}
.cbv2-cg:first-child{border-top:none;}
.cbv2-ci{padding:9px 14px;font-size:13px;cursor:pointer;color:#0f0f0f;}
.cbv2-ci:hover,.cbv2-ci.active{background:#FEF3C7;color:#92400E;}
.cbv2-ci.sel{background:#D41C1C;color:#fff;font-weight:700;}
.cbv2-ci[data-grp="in"]{border-left:3px solid #059669;}
.cbv2-ci[data-grp="out"]{border-left:3px solid #D41C1C;}
.cbv2-so{display:none;position:fixed;inset:0;background:rgba(10,10,10,.65);z-index:9100;align-items:center;justify-content:center;}
.cbv2-so.open{display:flex;}
.cbv2-sbox{background:#fff;border-radius:16px;width:420px;padding:24px;max-width:95vw;}
.cbv2-bgreen{background:#059669;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;}
.cbv2-noseed{background:#fffbeb;border:2px solid #fde68a;border-radius:14px;padding:28px 24px;margin:20px;text-align:center;box-shadow:0 4px 20px rgba(217,119,6,.1);}

/* ═══ CB4 — Admin Entry Modal (mobile-first, matches field register UX) ═══ */
.cb4-mo{display:none;position:fixed;inset:0;background:rgba(10,10,10,.65);z-index:9000;align-items:flex-end;justify-content:center;}
.cb4-mo.open{display:flex;}
.cb4-mb{background:#fff;border-radius:18px 18px 0 0;width:100%;max-width:560px;max-height:92dvh;display:flex;flex-direction:column;margin-bottom:calc(60px + env(safe-area-inset-bottom,0px));}
@media(min-width:600px){.cb4-mo{align-items:center;}.cb4-mb{border-radius:18px;margin:auto;max-height:88vh;}}
.cb4-mh{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1;flex-shrink:0;border-radius:18px 18px 0 0;}
@media(min-width:600px){.cb4-mh{border-radius:18px 18px 0 0;}}
.cb4-mh.neutral{background:#0f0f0f;}
.cb4-mh.in{background:#1a6b3a;}
.cb4-mh.out{background:#7a1a1a;}
.cb4-mt{font-size:15px;font-weight:900;color:#fff;letter-spacing:.3px;}
.cb4-msub{font-size:11px;color:rgba(255,255,255,.5);margin-top:1px;}
.cb4-mclose{width:30px;height:30px;border-radius:8px;border:1px solid rgba(255,255,255,.15);background:none;cursor:pointer;font-size:14px;color:rgba(255,255,255,.7);}
.cb4-scroll{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;}
.cb4-mbody{padding:18px 20px;}
/* Currency pills */
.cb4-curr-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}
.cb4-cpill{border:2px solid var(--border);border-radius:12px;padding:12px 14px;cursor:pointer;text-align:center;transition:.12s;}
.cb4-cpill.sel{border-color:var(--dark);background:#0f0f0f;}
.cb4-cpill-lbl{font-size:13px;font-weight:700;color:#374151;}
.cb4-cpill.sel .cb4-cpill-lbl{color:#fff;}
.cb4-cpill-bal{font-size:18px;font-weight:900;color:#0f0f0f;letter-spacing:-1px;margin-top:2px;}
.cb4-cpill.sel .cb4-cpill-bal{color:#4ade80;}
/* Direction cards */
.cb4-dir-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;}
.cb4-dir-btn{padding:14px 10px;border-radius:12px;border:2px solid var(--border);background:#fff;cursor:pointer;text-align:center;transition:.12s;}
.cb4-dir-btn.in.sel{border-color:var(--green);background:#f0fdf4;}
.cb4-dir-btn.out.sel{border-color:#dc2626;background:#fef2f2;}
.cb4-dir-btn.exch.sel{border-color:#7c3aed;background:#f5f3ff;}
.cb4-dir-ic{font-size:20px;margin-bottom:2px;}
.cb4-dir-lbl{font-size:12px;font-weight:800;}
.cb4-dir-sub{font-size:10px;color:#94a3b8;}
/* Category chips */
.cb4-cat-section{margin-top:4px;}
.cb4-cat-hdr{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:10px 0 6px;padding-left:2px;}
.cb4-cat-hdr:first-child{margin-top:0;}
.cb4-cats{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;}
.cb4-cat{border:2px solid var(--border);border-radius:10px;padding:10px 6px;cursor:pointer;text-align:center;transition:.12s;background:#fff;}
.cb4-cat:active{transform:scale(.96);}
.cb4-cat.sel{border-color:var(--dark);background:#0f0f0f;}
.cb4-cat-ic{font-size:16px;margin-bottom:2px;}
.cb4-cat-lbl{font-size:10px;font-weight:700;color:#374151;line-height:1.2;}
.cb4-cat.sel .cb4-cat-lbl{color:#fff;}
.cb4-cat-other{border-style:dashed;border-color:#60a5fa;background:#eff6ff;}
.cb4-cat-other .cb4-cat-lbl{color:#1d4ed8;}
.cb4-oth-item{padding:10px 14px;font-size:13px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;}
.cb4-oth-item:hover{background:#f8fafc;}
.cb4-oth-src{font-size:10px;color:#94a3b8;}
.cb4-oth-new{color:#1d4ed8;font-style:italic;}
/* v4.9.10: Group tabs for Cash OUT */
.cb4-grp-tabs{display:flex;gap:0;border-bottom:1.5px solid #e2e8f0;margin-bottom:10px;overflow-x:auto;}
.cb4-grp-tab{padding:8px 10px;font-size:11px;font-weight:600;cursor:pointer;border:none;background:none;color:#94a3b8;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s;flex-shrink:0;font-family:inherit;}
@media(max-width:400px){.cb4-grp-tab{padding:7px 7px;font-size:10px;}}
.cb4-grp-tab:hover{color:#64748b;}
.cb4-grp-tab.sel{color:#1e293b;border-bottom-color:#1e293b;}
.cb4-grp-dot{display:inline-block;width:6px;height:6px;border-radius:3px;margin-right:4px;vertical-align:middle;}
/* v4.9.10: Custom site search dropdown */
.cb4-site-drop{display:none;position:absolute;left:0;right:0;top:100%;background:#fff;border:1.5px solid #e2e8f0;border-radius:0 0 9px 9px;max-height:200px;overflow-y:auto;z-index:20;box-shadow:0 4px 12px rgba(0,0,0,.1);}
@media(max-height:700px){.cb4-site-drop{max-height:140px;}}
.cb4-site-drop.open{display:block;}
.cb4-site-opt{padding:9px 14px;font-size:13px;cursor:pointer;border-bottom:1px solid #f8fafc;color:#334155;}
.cb4-site-opt:hover{background:#f0fdf4;}
.cb4-site-opt.hide{display:none;}
.cb4-site-opt mark{background:#fef08a;color:#1e293b;border-radius:2px;padding:0 1px;}
/* Next button */
.cb4-next-wrap{margin-top:16px;}
.cb4-next{width:100%;padding:14px;background:#1e293b;color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;}
.cb4-next:disabled{opacity:.4;cursor:not-allowed;}
/* Step 2 form fields */
.cb4-fg{margin-bottom:12px;}
.cb4-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;display:block;margin-bottom:5px;}
.cb4-inp{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none;box-sizing:border-box;font-family:inherit;}
.cb4-inp:focus{border-color:var(--red);}
.cb4-sel{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:#fff;outline:none;cursor:pointer;box-sizing:border-box;font-family:inherit;}
.cb4-sel:focus{border-color:var(--red);}
.cb4-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;}
.cb4-aw{position:relative;}
.cb4-as{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:15px;font-weight:800;color:#94a3b8;pointer-events:none;}
.cb4-ai{padding-left:28px!important;font-size:22px!important;font-weight:900!important;letter-spacing:-1px;}
.cb4-rate-calc{font-size:10px;color:#94a3b8;margin-top:3px;}
/* Project toggle */
.cb4-proj-row{display:flex;background:#f1f5f9;border-radius:8px;padding:3px;gap:2px;margin-bottom:12px;}
.cb4-proj-btn{flex:1;padding:7px 0;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#64748b;font-family:inherit;text-align:center;}
.cb4-proj-btn.sel{background:#0f0f0f;color:#fff;}
/* Info strip */
.cb4-info{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;font-size:12px;color:#166534;font-weight:600;margin-bottom:12px;}
.cb4-info.warn{background:#fffbeb;border-color:#fde68a;color:#78350f;}
/* Sticky footer */
.cb4-mfooter{padding:12px 20px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:space-between;position:sticky;bottom:0;background:#fff;flex-shrink:0;border-radius:0 0 18px 18px;}
.cb4-cancel{background:#f8fafc;color:#374151;border:1px solid var(--border);border-radius:10px;padding:12px 18px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;}
.cb4-save{background:var(--red);color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;flex:1;max-width:260px;text-align:center;}
.cb4-save:disabled{opacity:.4;cursor:not-allowed;}
/* Category search */
.cb4-cat-search{width:100%;padding:9px 12px 9px 32px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none;box-sizing:border-box;font-family:inherit;margin-bottom:10px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 10-1.397 1.398h-.001l3.85 3.85a1 1 0 001.415-1.414l-3.85-3.85zm-5.242.156a5 5 0 110-10 5 5 0 010 10z'/%3E%3C/svg%3E") no-repeat 10px center;}
.cb4-cat-search:focus{border-color:var(--red);}
.cb4-cat-search::placeholder{color:#bbb;}
/* Person search panel */
.cb4-person-wrap{position:relative;}
.cb4-person-inp{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;outline:none;box-sizing:border-box;font-family:inherit;}
.cb4-person-inp:focus{border-color:var(--red);}
.cb4-person-drop{display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:#fff;border:1.5px solid var(--red);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.15);z-index:9999;max-height:240px;overflow-y:auto;}
.cb4-person-drop.open{display:block;}
.cb4-person-item{padding:10px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:space-between;}
.cb4-person-item:hover,.cb4-person-item.hl{background:#fef3c7;}
.cb4-person-item:last-child{border:none;}
.cb4-person-name{font-weight:700;color:#1e293b;}
.cb4-person-meta{font-size:10px;color:#94a3b8;font-weight:500;}
.cb4-person-cnt{background:#f1f5f9;color:#64748b;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;}
/* Smart suggestion strip */
.cb4-smart-strip{margin-bottom:14px;}
.cb4-smart-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:6px;display:flex;align-items:center;gap:4px;}
.cb4-smart-chips{display:flex;gap:6px;flex-wrap:wrap;}
.cb4-smart-chip{background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;color:#166534;cursor:pointer;display:flex;align-items:center;gap:4px;transition:.12s;}
.cb4-smart-chip:hover{background:#dcfce7;border-color:#4ade80;}
.cb4-smart-chip .cnt{font-size:9px;background:#bbf7d0;color:#166534;border-radius:6px;padding:0 5px;font-weight:800;}
/* Suggested persons strip (category → person) */
.cb4-suggest-strip{margin-bottom:6px;}
.cb4-suggest-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:6px;display:flex;align-items:center;gap:4px;}
.cb4-suggest-chips{display:flex;gap:6px;flex-wrap:wrap;}
.cb4-suggest-chip{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;padding:6px 12px;font-size:11px;font-weight:700;color:#1e40af;cursor:pointer;display:flex;align-items:center;gap:4px;transition:.12s;}
.cb4-suggest-chip:hover{background:#dbeafe;border-color:#60a5fa;}
.cb4-suggest-chip .cnt{font-size:9px;background:#bfdbfe;color:#1e40af;border-radius:6px;padding:0 5px;font-weight:800;}
.cb4-suggest-chip.desc{background:#fef3c7;border-color:#fde68a;color:#78350f;}
.cb4-suggest-chip.desc:hover{background:#fde68a;border-color:#f59e0b;}
.cb4-suggest-chip.desc .cnt{background:#fde68a;color:#78350f;}
</style>

<div class="cbv2-wrap cb3-wrap">

<!-- ══ TOP BAR ══ -->
<div class="cb3-bar">
  <a href="?page=dashboard" class="cb3-back" title="Dashboard">&#8592;</a>
  <span class="cb3-title">Cashbook</span>
  <div class="cb3-proj">
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_proj'=>'dishnet','cb_page'=>1])); ?>" class="<?php echo $proj==='dishnet'?'on':''; ?>">Fiber&SL</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_proj'=>'4g','cb_page'=>1])); ?>" class="<?php echo $proj==='4g'?'on':''; ?>">4G</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_proj'=>'bluecard','cb_page'=>1])); ?>" class="<?php echo $proj==='bluecard'?'on':''; ?>">BlueCARD</a>
  </div>
  <?php if(!$isFieldAgent): ?><button class="cb3-fab" onclick="cbv2OpenModal()">＋ Entry</button><?php endif; ?>
</div>

<!-- ══ BALANCE STRIP — compact, same as before ══ -->
<?php if ($isFieldAgent): ?>
<!-- ── Field Agent Personal Cash View ── -->
<?php
$fa_cols = $store->findAll('payment_collections.json','retailer_id',(int)$retailer['id']);
$fa_hovConf = array_filter($store->load('cash_handovers.json')??[], fn($h)=>(int)($h['from_id']??0)===(int)$retailer['id'] && ($h['status']??'')==='confirmed');
$fa_hovPend = array_filter($store->load('cash_handovers.json')??[], fn($h)=>(int)($h['from_id']??0)===(int)$retailer['id'] && ($h['status']??'')==='pending');
$fa_colAmt  = round(array_sum(array_column($fa_cols,'amount')),2);
$fa_hovAmt  = round(array_sum(array_column(array_values($fa_hovConf),'amount')),2);
$fa_pendAmt = round(array_sum(array_map(fn($h)=>(float)($h['amount']??0),$fa_hovPend)),2);
$fa_cih     = max(0, $fa_colAmt - $fa_hovAmt);
$today      = date('Y-m-d');
$fa_todayCols = array_filter($fa_cols, fn($c)=>strpos($c['collected_at']??$c['created_at']??'',$today)===0);
$fa_todayAmt  = round(array_sum(array_column(array_values($fa_todayCols),'amount')),2);
?>
<div style="background:#fff;border-bottom:1px solid var(--border);">
  <div style="padding:14px 18px 10px;border-bottom:1px solid var(--border);">
    <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mute);margin-bottom:3px;">My Cash In Hand</div>
    <div style="font-size:28px;font-weight:800;letter-spacing:-.5px;color:<?php echo $fa_cih>0?'var(--red)':'var(--mute)'; ?>;line-height:1;">$<?php echo number_format($fa_cih,2); ?></div>
    <div style="font-size:10px;color:var(--mute);margin-top:3px;"><?php echo date('d M Y'); ?> · My ledger only</div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;">
    <div style="padding:10px 14px;border-right:1px solid var(--border);">
      <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--mute);margin-bottom:2px;">Today</div>
      <div style="font-size:18px;font-weight:900;color:var(--green);">$<?php echo number_format($fa_todayAmt,0); ?></div>
      <div style="font-size:9px;color:var(--mute);"><?php echo count($fa_todayCols); ?> collections</div>
    </div>
    <div style="padding:10px 14px;border-right:1px solid var(--border);">
      <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--mute);margin-bottom:2px;">Pending HOV</div>
      <div style="font-size:18px;font-weight:900;color:<?php echo $fa_pendAmt>0?'var(--amber)':'var(--mute)'; ?>;">$<?php echo number_format($fa_pendAmt,0); ?></div>
      <div style="font-size:9px;color:var(--mute);">awaiting Rupesh</div>
    </div>
    <div style="padding:10px 14px;background:#f8f8f5;">
      <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--mute);margin-bottom:2px;">Total Handed</div>
      <div style="font-size:18px;font-weight:900;color:var(--blue);">$<?php echo number_format($fa_hovAmt,0); ?></div>
      <div style="font-size:9px;color:var(--mute);">confirmed</div>
    </div>
  </div>
  <!-- Quick actions for field_accountant -->
  <div style="display:flex;gap:8px;padding:12px 16px;border-top:1px solid var(--border);">
    <a href="?page=dashboard&tab=my_account&v=exchange"
      style="flex:1;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:12px;padding:10px 8px;text-align:center;text-decoration:none;">
      <div style="font-size:18px;">💱</div>
      <div style="font-size:11px;font-weight:800;color:#7c3aed;margin-top:2px;">Convert Currency</div>
      <div style="font-size:10px;color:#94a3b8;">USD ↔ SSP</div>
    </a>
    <a href="?page=dashboard&tab=my_account&v=expense"
      style="flex:1;background:#fff7ed;border:1.5px solid #fed7aa;border-radius:12px;padding:10px 8px;text-align:center;text-decoration:none;">
      <div style="font-size:18px;">💸</div>
      <div style="font-size:11px;font-weight:800;color:#c2410c;margin-top:2px;">Log Expense</div>
      <div style="font-size:10px;color:#94a3b8;">Cash out</div>
    </a>
    <a href="?page=dashboard&tab=my_account&v=handover"
      style="flex:1;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:12px;padding:10px 8px;text-align:center;text-decoration:none;">
      <div style="font-size:18px;">⬆️</div>
      <div style="font-size:11px;font-weight:800;color:#dc2626;margin-top:2px;">Handover</div>
      <div style="font-size:10px;color:#94a3b8;">To Rupesh</div>
    </a>
  </div>
</div>
<?php else: ?>
<?php
  // Rupesh's balance data
  $sspBal     = $bal['SSP']['balance'] ?? 0.0;
  $sspUsdEq   = $bal['usd_equivalent_ssp'] ?? 0.0;
  $xRate      = $bal['exchange_rate'] ?? 5180.0;
  $combinedUsd= round($projBal, 2);
?>
<!-- ── Beautiful Balance Cards (matches screenshot style) ── -->
<div style="padding:14px 14px 0;background:var(--bg);">
  <!-- Title -->
  <div style="font-size:11px;font-weight:700;color:var(--mute);margin-bottom:10px;letter-spacing:.5px;">
    💰 Cashbook &nbsp;·&nbsp; <span style="font-weight:500;"><?php echo $filterCurr==='USD'?'USD Ledger':($filterCurr==='SSP'?'SSP Ledger':'Dual-currency cash ledger · USD &amp; SSP'); ?></span>
  </div>

  <?php if ($filterCurr !== 'SSP'): ?>
  <!-- Green USD card -->
  <div style="background:#1a6b3a;border-radius:16px;padding:18px 20px;margin-bottom:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.06);border-radius:50%;"></div>
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.55);margin-bottom:6px;">💵 USD BALANCE</div>
    <div style="font-size:42px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1;">$<?php echo number_format($projBal,2); ?></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:6px;"><?php echo date('d M Y'); ?> &nbsp;·&nbsp; <?php echo number_format($seedCount); ?> entries &nbsp;·&nbsp; <?php echo $proj==='4g'?'4G':'Fiber&SL'; ?></div>
    <?php if($pendingCount>0): ?>
    <div style="margin-top:10px;display:inline-flex;align-items:center;gap:5px;background:rgba(0,0,0,.25);border-radius:20px;padding:4px 10px;cursor:pointer;" onclick="location.href='?<?php echo htmlspecialchars(http_build_query(array_merge($_GET,['cb_view'=>'pending']))); ?>'">
      <span style="font-size:9px;font-weight:800;color:#fcd34d;">⚠ <?php echo $pendingCount; ?> pending · $<?php echo number_format($pendingTotal,0); ?></span>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($filterCurr !== 'USD'): ?>
  <!-- Blue SSP card -->
  <div style="background:#1a3a7a;border-radius:16px;padding:18px 20px;margin-bottom:10px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-20px;right:-20px;width:120px;height:120px;background:rgba(255,255,255,.06);border-radius:50%;"></div>
    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.55);margin-bottom:6px;">🇸🇸 SSP BALANCE</div>
    <div style="font-size:42px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1;"><?php echo number_format($sspBal,0); ?> <span style="font-size:16px;font-weight:700;color:rgba(255,255,255,.45);">SSP</span></div>
    <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:6px;">Separate from USD &middot; <?php echo date("d M Y"); ?><?php if($sspBal!=0 && $xRate>0): ?> &nbsp;·&nbsp; ≈ $<?php echo number_format($sspBal/$xRate,2); ?> USD<?php endif; ?></div>
  </div>
  <?php endif; ?>

  <?php if ($filterCurr === ''): ?>
  <!-- Black combined card -->
  <div style="background:#0f0f0f;border-radius:16px;padding:18px 20px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
    <div>
      <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.35);margin-bottom:6px;">COMBINED (USD EQUIVALENT)</div>
      <div style="font-size:38px;font-weight:900;color:#4ade80;letter-spacing:-2px;line-height:1;">$<?php echo number_format($combinedUsd,2); ?></div>
    </div>
    <div style="background:rgba(255,255,255,.08);border-radius:10px;padding:6px 12px;text-align:center;">
      <div style="font-size:10px;color:rgba(255,255,255,.4);font-weight:700;">1 USD =</div>
      <div style="font-size:13px;font-weight:900;color:#fff;"><?php echo number_format($xRate,0); ?> SSP</div>
    </div>
  </div>
  <?php endif; ?>

  <?php
  // ── Exchange SSP Backfill Banner (admin only) ─────────────────────────
  $_exchDismissed = !empty($meta['exchange_ssp_banner_dismissed']);
  if ($isAdmin && !$_exchDismissed) {
      $_exchUsdCount = $cb->query("SELECT COUNT(*) as n FROM cb_ledger WHERE category='Exchange' AND currency='USD'")[0]['n'] ?? 0;
      $_exchSspCount = $cb->query("SELECT COUNT(*) as n FROM cb_ledger WHERE category='Exchange' AND currency='SSP'")[0]['n'] ?? 0;
      $_exchMissing  = (int)$_exchUsdCount - (int)$_exchSspCount;
      if ($_exchMissing > 0):
  ?>
  <div style="background:#fef3c7;border:1.5px solid #fbbf24;border-radius:12px;padding:12px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div style="font-size:12px;color:#92400e;">
      <strong>⚠ <?php echo $_exchMissing; ?> Exchange entries missing SSP counterpart</strong>
      <br><span style="font-size:11px;color:#a16207;">USD Cash OUT was recorded but SSP Cash IN was not. This affects SSP balance accuracy.</span>
    </div>
    <div style="display:flex;gap:6px;align-items:center;">
      <form method="POST" style="margin:0;" onsubmit="return confirm('This will create <?php echo $_exchMissing; ?> SSP entries to match existing USD Exchange entries. Continue?');">
        <?= csrfField() ?>
        <input type="hidden" name="cb_action" value="backfill_exchange_ssp">
        <button type="submit" style="background:#d97706;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;">
          Fix Now — Create <?php echo $_exchMissing; ?> SSP entries
        </button>
      </form>
      <form method="POST" style="margin:0;">
        <?= csrfField() ?>
        <input type="hidden" name="cb_action" value="dismiss_exchange_banner">
        <button type="submit" style="background:transparent;color:#92400e;border:1.5px solid #d97706;border-radius:8px;padding:8px 12px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;">
          ✕ Dismiss
        </button>
      </form>
    </div>
  </div>
  <?php endif; } ?>

  <!-- SSP Rate Reference — global, all projects, all staff -->
  <?php
    $rateHistory   = $cb->getRateHistory(30);
    $rateUpdatedAt = $meta['rate_updated_at'] ?? null;
    $rateUpdatedBy = $meta['rate_updated_by'] ?? '';
    $prevRate2     = isset($rateHistory[1]) ? (float)$rateHistory[1]['rate'] : null;
    $rateChange2   = $prevRate2 ? round($xRate - $prevRate2) : null;
    $rateChangeCol = $rateChange2 > 0 ? '#4ade80' : ($rateChange2 < 0 ? '#f87171' : '#94a3b8');
    // Last actual market rate from field exchanges (what Diko/BBC got at money changer)
    $_cbExcCtx2  = $cb->getLastExchangeContext($store->load('cash_ins.json') ?: []);
    $_cbLastRate = (int)($_cbExcCtx2['last_rate'] ?? 0);
    $_cbLastBy   = $_cbExcCtx2['last_by'] ?? '';
    $_cbLastAgo  = '';
    if (!empty($_cbExcCtx2['last_at'])) {
        $_cbDiffMins = max(0, (int)floor((time() - strtotime($_cbExcCtx2['last_at'])) / 60));
        if ($_cbDiffMins < 60)       $_cbLastAgo = $_cbDiffMins . 'm ago';
        elseif ($_cbDiffMins < 1440) $_cbLastAgo = round($_cbDiffMins/60) . 'h ago';
        else                         $_cbLastAgo = round($_cbDiffMins/1440) . 'd ago';
    }
    $_cbRateDiff = $_cbLastRate > 0 && $xRate > 0 ? $_cbLastRate - (int)$xRate : 0;
  ?>
  <div style="background:#111827;border-radius:16px;padding:16px 20px;margin-bottom:10px;border:1px solid rgba(255,255,255,.07);">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="background:rgba(245,158,11,.12);border-radius:10px;padding:8px 14px;text-align:center;">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:rgba(245,158,11,.6);margin-bottom:3px;">SSP Reference Rate</div>
          <div style="display:flex;align-items:baseline;gap:6px;">
            <span style="font-size:26px;font-weight:900;color:#f59e0b;letter-spacing:-1px;"><?php echo number_format($xRate,0); ?></span>
            <span style="font-size:11px;color:rgba(255,255,255,.45);font-weight:600;">SSP = 1 USD</span>
            <?php if ($rateChange2 !== null && $rateChange2 !== 0): ?>
            <span style="font-size:11px;font-weight:800;color:<?php echo $rateChangeCol; ?>;"><?php echo ($rateChange2>0?'▲+':'▼').number_format(abs($rateChange2)); ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div>
          <div style="font-size:12px;color:rgba(255,255,255,.75);font-weight:600;">🇸🇸 South Sudanese Pound</div>
          <div style="font-size:10px;color:rgba(255,255,255,.3);margin-top:3px;">Applies to all cashbooks · for reference only<?php if($rateUpdatedAt): ?> · Updated <?php echo date('d M H:i',strtotime($rateUpdatedAt)); ?><?php if($rateUpdatedBy): ?> by <?php echo htmlspecialchars($rateUpdatedBy); ?><?php endif; ?><?php endif; ?></div>
        </div>
        <?php if ($_cbLastRate > 0): ?>
        <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);border-radius:10px;padding:8px 14px;">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:rgba(16,185,129,.7);margin-bottom:3px;">Last Field Rate</div>
          <div style="display:flex;align-items:baseline;gap:5px;">
            <span style="font-size:22px;font-weight:900;color:#10b981;letter-spacing:-1px;"><?php echo number_format($_cbLastRate, 0); ?></span>
            <span style="font-size:10px;color:rgba(255,255,255,.4);font-weight:600;">SSP/$</span>
            <?php if ($_cbRateDiff != 0): ?>
            <span style="font-size:10px;font-weight:800;color:<?php echo $_cbRateDiff > 0 ? '#4ade80' : '#f87171'; ?>;">
              <?php echo ($_cbRateDiff > 0 ? '▲+' : '▼') . number_format(abs($_cbRateDiff)); ?>
            </span>
            <?php endif; ?>
          </div>
          <div style="font-size:9px;color:rgba(255,255,255,.3);margin-top:2px;">
            <?php echo htmlspecialchars($_cbLastBy); ?><?php if ($_cbLastAgo): ?> · <?php echo $_cbLastAgo; ?><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php if ($isAdmin || ($retailer['role']??'')=== 'accountant'): ?>
      <div style="display:flex;gap:6px;align-items:center;" id="sspRateForm">
        <input type="number" id="sspRateInput" value="<?php echo (int)$xRate; ?>" min="1" step="1"
               style="width:96px;padding:6px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.06);color:#fff;font-size:13px;font-weight:700;text-align:center;">
        <button onclick="updateSspRate()" id="sspRateBtn"
                style="padding:6px 14px;background:#f59e0b;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;">Set Rate</button>
        <span id="sspRateMsg" style="font-size:11px;display:none;"></span>
      </div>
      <script>
      function updateSspRate(){
        var rate=parseInt(document.getElementById('sspRateInput').value,10);
        if(!rate||rate<1){alert('Enter a valid rate');return;}
        var btn=document.getElementById('sspRateBtn'),msg=document.getElementById('sspRateMsg');
        btn.disabled=true;btn.textContent='...';
        var tok='<?php echo h($retailer['api_token'] ?? ''); ?>';
        fetch('?page=api&action=cashbook_set_rate',{
          credentials:'same-origin',
          method:'POST',
          headers:{'Content-Type':'application/json','Authorization':'Bearer '+tok},
          body:JSON.stringify({rate:rate})})
        .then(function(r){return r.json();})
        .then(function(d){
          var r=d&&d.data?d.data:d;
          if(r&&r.ok){btn.textContent='Saved!';btn.style.background='#10b981';setTimeout(function(){location.reload();},700);}
          else{btn.disabled=false;btn.textContent='Set Rate';msg.textContent=r.error||d.message||'Failed';msg.style.color='#fca5a5';msg.style.display='inline';}
        })
        .catch(function(){btn.disabled=false;btn.textContent='Set Rate';msg.textContent='Network error';msg.style.color='#fca5a5';msg.style.display='inline';});
      }
      </script>
      <?php endif; ?>
    </div>
    <!-- Rate history pills -->
    <?php if(!empty($rateHistory)): ?>
    <div style="display:flex;gap:4px;flex-wrap:wrap;">
      <?php foreach(array_slice($rateHistory,0,10) as $ri=>$rh):
        $riToday=(substr($rh['effective_date'],0,10)===date('Y-m-d'));
        $riPrev=isset($rateHistory[$ri+1])?(float)$rateHistory[$ri+1]['rate']:null;
        $riCh=$riPrev?round((float)$rh['rate']-$riPrev):null;
        $riCol=$riCh>0?'#4ade80':($riCh<0?'#f87171':null);
      ?>
      <div style="background:<?php echo $riToday?'rgba(245,158,11,.15)'  :'rgba(255,255,255,.05)';?>;border-radius:6px;padding:4px 9px;text-align:center;min-width:50px;">
        <div style="font-size:9px;color:<?php echo $riToday?'#f59e0b':'rgba(255,255,255,.35)';?>;font-weight:<?php echo $riToday?'800':'600';?>;"><?php echo $riToday?'Today':date('d M',strtotime($rh['effective_date']));?></div>
        <div style="font-size:12px;font-weight:900;color:#fff;"><?php echo number_format((float)$rh['rate'],0);?></div>
        <?php if($riCol&&$riCh):?><div style="font-size:8px;color:<?php echo $riCol;?>;font-weight:700;"><?php echo($riCh>0?'+':'').number_format($riCh);?></div><?php endif;?>
      </div>
      <?php endforeach;?>
    </div>
    <?php else:?>
    <div style="font-size:11px;color:rgba(255,255,255,.25);text-align:center;padding:4px 0;">No rate history yet — set today's rate to start tracking.</div>
    <?php endif;?>
  </div>
</div>
<?php endif; // end field agent / admin balance strip ?>




<?php
// ── CRM Sync status ──────────────────────────────────────────────────────
$crmSyncAt      = $meta['crm_sync_at']     ?? null;
$crmSyncTotal   = (int)($meta['crm_sync_total']  ?? 0);
$crmSyncCutoff  = $meta['crm_sync_cutoff'] ?? null;
$crmSyncSource  = $meta['crm_sync_source'] ?? null;
// Determine if sync is stale (last sync > 1 day ago or never run)
$syncStale = !$crmSyncAt || (strtotime($crmSyncAt) < strtotime('-1 day'));
?>

<?php if ($seeded && !$isFieldAgent): ?>
<div class="cb3-sync cbv2-syncbar <?php echo $syncStale?'stale':'fresh'; ?>" id="cbSyncBar">
  <div class="cbv2-syncbar-main" onclick="document.getElementById('cbSyncForm').style.display=document.getElementById('cbSyncForm').style.display==='none'?'flex':'none'">
    <span class="cbv2-syncbar-ic"><?php echo $syncStale?'⏳':'✅'; ?></span>
    <span class="cbv2-syncbar-tx">
      <strong><?php echo $syncStale?'CRM Sync Due':'CRM Synced'; ?></strong>
      <?php if($crmSyncAt): echo ' · '.date('d M H:i',strtotime($crmSyncAt)).' · '.$crmSyncTotal.' imported'; else: echo ' · tap to run first sync'; endif; ?>
    </span>
    <span class="cbv2-syncbar-arr">&#8250;</span>
  </div>
  <div id="cbSyncForm" style="display:none;padding:10px 14px 12px;border-top:1px solid rgba(0,0,0,.06);display:none;">
    <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
<?= csrfField() ?>
      <input type="hidden" name="cb_action" value="crm_sync">
      <label style="font-size:10px;font-weight:700;color:#6b7280;">From date (blank = auto)</label>
      <input type="date" name="sync_from" class="cbv2-fi" style="font-size:11px;padding:5px 8px;">
      <button type="submit" class="cbv2-bsave" style="padding:7px 14px;font-size:11px;">🔄 Sync Now</button>
    </form>
    <?php if($crmSyncCutoff): ?>
    <div style="font-size:10px;color:#6b7280;margin-top:6px;">Auto-cutoff: <?php echo $crmSyncCutoff; ?> &nbsp;·&nbsp; <?php echo number_format($crmSyncTotal); ?> total imported
    &nbsp;·&nbsp; <a href="?page=api&action=debug_cashbook_sync" target="_blank" style="color:#2563eb;text-decoration:underline;">🔍 Debug</a>
    &nbsp;·&nbsp; <a href="?page=api&action=debug_cashbook_sync&run=1" target="_blank" style="color:#dc2626;text-decoration:underline;">⚡ Debug + Sync</a>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if(!$isFieldAgent): ?>
<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:4px 14px 8px;flex-wrap:wrap;">
  <span style="font-size:11px;color:#9ca3af;"><?php echo number_format($seedCount); ?> entries · seeded <?php echo $meta['seeded_at'] ? date('d M Y', strtotime($meta['seeded_at'])) : '—'; ?></span>
  <form method="POST" onsubmit="return confirm('Wipe Excel entries and reload from latest baked-in data (3,263 entries)?\n\nManual + collection entries are preserved.')">
<?= csrfField() ?>
    <input type="hidden" name="cb_action" value="cb_reseed">
    <button type="submit" style="background:#f1f5f9;color:#dc2626;border:1.5px solid #fca5a5;border-radius:7px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
      ♻ Reset &amp; Reseed
    </button>
  </form>
</div>
<?php endif; ?>

<?php if (!$seeded && !$isFieldAgent): ?>
<div class="cbv2-noseed" id="cbSeedBanner">
  <div style="font-size:28px;margin-bottom:8px;">&#128452;</div>
  <h3 style="margin:0 0 6px;font-size:16px;font-weight:900;color:#92400e;">Cashbook Not Yet Loaded</h3>
  <p style="margin:0 0 16px;font-size:13px;color:#78350f;line-height:1.5;">
    3,263 entries from Jan 2025–Mar 2026 are ready to load.<br>
    Click once — takes about 10 seconds.
  </p>
  <form method="POST" onsubmit="cbStartSeed(this)">
<?= csrfField() ?>
    <input type="hidden" name="cb_action" value="cb_reseed">
    <button type="submit" id="cbSeedBtn" style="
      background:#d97706;color:#fff;border:none;border-radius:9px;
      padding:12px 28px;font-size:14px;font-weight:900;cursor:pointer;
      font-family:inherit;display:inline-flex;align-items:center;gap:8px;
      box-shadow:0 4px 14px rgba(217,119,6,.35);transition:.15s;">
      <span id="cbSeedBtnIc">&#11014;</span>
      <span id="cbSeedBtnTx">Load Cashbook Data</span>
    </button>
  </form>
</div>
<script>
function cbStartSeed(form) {
  document.getElementById('cbSeedBtnIc').innerHTML = '&#9203;';
  document.getElementById('cbSeedBtnTx').textContent = 'Loading… please wait';
  document.getElementById('cbSeedBtn').disabled = true;
  document.getElementById('cbSeedBtn').style.opacity = '0.7';
}
</script>
<?php endif; ?>
<?php endif; // end if ($seeded && !$isFieldAgent) ?>

<?php if ($isFieldAgent): ?>
<div style="padding:40px 20px;text-align:center;font-family:Inter,sans-serif;">
  <div style="font-size:40px;margin-bottom:12px;">📋</div>
  <div style="font-size:16px;font-weight:800;color:#0f0f0f;margin-bottom:6px;">Field Register</div>
  <div style="font-size:13px;color:#94a3b8;margin-bottom:20px;">Your cash tracking is in the Register tab.</div>
  <a href="?page=dashboard&tab=wallet" style="background:#D41C1C;color:#fff;border-radius:10px;padding:12px 28px;font-size:14px;font-weight:700;text-decoration:none;">Go to My Register →</a>
</div>
<?php else: ?>
<div class="cb3-tabs">
<?php
$navItems=[['ledger','📋 Ledger',''],['pending','⏳ Pending',$pendingCount>0?(string)$pendingCount:''],
    ['payroll','💼 Payroll',''],['sites','📡 Sites (4G)',''],
    ['interco','🔄 Inter-co',''],['summary','📊 Summary',''],
    ['alerts','🚦 Alerts',$alertCount>0?(string)$alertCount:'']];
foreach($navItems as $ni):
    list($nv,$lbl,$badge)=$ni;
    $href='?'.http_build_query(array_merge($_GET,['cb_view'=>$nv,'cb_page'=>1]));
?>
<a href="<?php echo $href; ?>" class="cb3-tab <?php echo $view===$nv?'on':''; ?>">
  <?php echo $lbl; ?><?php if($badge): ?> <span class="cb3-badge"><?php echo $badge; ?></span><?php endif; ?>
</a>
<?php endforeach; ?>
<?php if ($isAdmin): ?>
<a href="?<?php echo http_build_query(array_merge($_GET,['cb_view'=>'categories','cb_page'=>1])); ?>"
   class="cb3-tab <?php echo $view==='categories'?'on':''; ?>" style="<?php echo $view==='categories'?'':'color:#7c3aed;'; ?>">
  ⚙️ Categories
</a>
<?php endif; ?>
</div>

<?php if ($view === 'ledger'): ?>

<?php
// ── Detect handover double-count entries ─────────────────────────────────
$_hovDupes = [];
if ($isAdmin || ($retailer['role'] ?? '') === 'accountant') {
    try {
        $_hovStmt = $cb->getPdo()->prepare(
            "SELECT id, sr, date, amount, person, validation_ref
             FROM cb_ledger
             WHERE (category_raw = 'Cash Handover' OR validation_ref LIKE 'HOV-%')
               AND direction = 'in'
               AND status NOT IN ('voided_reconcile','deleted')
             ORDER BY date DESC"
        );
        $_hovStmt->execute();
        $_hovDupes = $_hovStmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_e) {}
}
?>
<?php if (!empty($_hovDupes)): ?>
<div id="hovDupeBanner" style="margin:12px 14px;background:#fef3c7;border:2px solid #f59e0b;border-radius:12px;padding:14px 16px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
        <div>
            <div style="font-size:13px;font-weight:800;color:#92400e;margin-bottom:4px;">⚠️ Double-Counted Handovers Found</div>
            <div style="font-size:12px;color:#92400e;">
                <?php echo count($_hovDupes); ?> handover receipt<?php echo count($_hovDupes) > 1 ? 's' : ''; ?> totalling
                <strong>$<?php echo number_format(array_sum(array_column($_hovDupes, 'amount')), 2); ?></strong>
                are inflating your cashbook. These collections were already posted by CRM — the handover is an internal transfer, not new revenue.
            </div>
            <div style="margin-top:8px;font-size:11px;color:#b45309;">
                <?php foreach ($_hovDupes as $_hd): ?>
                <div style="padding:2px 0;"><?php echo htmlspecialchars($_hd['date'] . ' · ' . $_hd['validation_ref'] . ' · ' . $_hd['person']); ?> — <strong>$<?php echo number_format((float)$_hd['amount'], 2); ?></strong></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px;">
        <button onclick="cbHovReconcile()" id="hovFixBtn"
            style="background:#D41C1C;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;">
            🧹 Fix Now — Void Duplicates
        </button>
        <span id="hovFixMsg" style="font-size:11px;color:#92400e;align-self:center;"></span>
    </div>
</div>
<script>
function cbHovReconcile() {
    if (!confirm('This will void <?php echo count($_hovDupes); ?> duplicate handover entries ($<?php echo number_format(array_sum(array_column($_hovDupes, 'amount')), 2); ?>). Continue?')) return;
    var btn = document.getElementById('hovFixBtn');
    var msg = document.getElementById('hovFixMsg');
    btn.disabled = true; btn.textContent = '⏳ Fixing...';
    fetch('?page=api&action=cashbook_handover_reconcile', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-Api-Token':'<?php echo h($retailer['api_token'] ?? ''); ?>'},
        body: JSON.stringify({dry_run: false})
    }).then(function(r){return r.json()}).then(function(d) {
        var data = d.data || d;
        btn.textContent = '✅ Done';
        btn.style.background = '#16a34a';
        msg.textContent = data.entries_found + ' entries voided. Reloading...';
        setTimeout(function(){ location.reload(); }, 1500);
    }).catch(function(e) {
        btn.disabled = false; btn.textContent = '🧹 Fix Now';
        msg.textContent = 'Error: ' + e.message;
        msg.style.color = '#dc2626';
    });
}
</script>
<?php endif; ?>
<!-- ── Primary filter bar: Currency + Date + Actions ── -->
<div style="padding:12px 14px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap;position:sticky;top:52px;z-index:100;">
  <?php foreach(['' => 'All', 'USD' => '💵 USD', 'SSP' => '🇸🇸 SSP'] as $cv => $cl): ?>
  <a href="?<?php echo http_build_query(array_merge($_GET,['cb_curr'=>$cv,'cb_page'=>1])); ?>"
     style="padding:8px 16px;border-radius:20px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;border:1.5px solid;
            <?php echo $filterCurr===$cv
              ? 'background:#0f0f0f;color:#fff;border-color:#0f0f0f;'
              : 'background:#fff;color:#374151;border-color:#e2e8f0;'; ?>"><?php echo $cl; ?></a>
  <?php endforeach; ?>
  <input type="date" value="<?php echo htmlspecialchars($dateFrom); ?>" onchange="cbv2F('cb_from',this.value)"
    style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;font-family:inherit;color:#374151;min-width:120px;">
  <input type="date" value="<?php echo htmlspecialchars($dateTo); ?>" onchange="cbv2F('cb_to',this.value)"
    style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:12px;font-family:inherit;color:#374151;min-width:120px;">
  <?php if(!$isFieldAgent): ?>
  <button onclick="cbv2OpenModal()" style="padding:8px 18px;background:#D41C1C;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;">+ Add Entry</button>
  <?php endif; ?>
  <a href="?<?php echo http_build_query(array_merge($_GET,['cb_export'=>'csv','cb_proj'=>$proj])); ?>"
    style="padding:8px 16px;background:#0f0f0f;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;">↓ <?php echo $filterCurr ? $filterCurr : 'CSV'; ?></a>
  <?php if(!$filterCurr): ?>
  <a href="?<?php echo http_build_query(array_merge($_GET,['cb_export'=>'csv','cb_proj'=>$proj,'cb_curr'=>'USD'])); ?>"
    style="padding:8px 12px;background:#fff;color:#374151;border:1.5px solid #e2e8f0;border-radius:10px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;">↓ USD only</a>
  <a href="?<?php echo http_build_query(array_merge($_GET,['cb_export'=>'csv','cb_proj'=>$proj,'cb_curr'=>'SSP'])); ?>"
    style="padding:8px 12px;background:#fff;color:#92400e;border:1.5px solid #fde68a;border-radius:10px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;">↓ SSP only</a>
  <?php endif; ?>
</div>
<!-- ── Search + advanced filter ── -->
<div class="cb3-search-bar" style="top:108px;">
  <input type="text" class="cb3-search-inp" placeholder="🔍 Search description, SR, person…"
    value="<?php echo htmlspecialchars($search); ?>" oninput="cbv2FD('cb_q',this.value)">
  <button class="cb3-filter-btn <?php echo ($filterCat||$filterVal||$filterDir)?'on':''; ?>"
    onclick="document.getElementById('cb3FilterDrawer').classList.toggle('open')">
    ⚙ More<?php
    $nf=0;
    if($filterCat) $nf++;
    if($filterVal) $nf++;
    if($filterDir) $nf++;
    if($nf): ?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:0 6px;font-size:10px;"><?php echo $nf; ?></span><?php endif; ?>
  </button>
</div>
<!-- Filter drawer (collapsed by default) -->
<?php $anyFilter = $filterCat||$filterVal||$filterDir; $anyFilterAll = $dateFrom||$dateTo||$filterCat||$filterVal||$filterDir||$filterCurr; ?>
<div class="cb3-fdr <?php echo $anyFilter?'open':''; ?>" id="cb3FilterDrawer">

  <!-- Row 1: Direction pills -->
  <div style="display:flex;gap:6px;align-items:center;width:100%;flex-wrap:wrap;">
    <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;white-space:nowrap;">Flow:</span>
    <?php foreach([''=> 'All', 'in'=>'↓ Cash In', 'out'=>'↑ Cash Out'] as $dv=>$dl): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_dir'=>$dv,'cb_page'=>1])); ?>"
       style="padding:5px 12px;border-radius:20px;font-size:11px;font-weight:700;text-decoration:none;white-space:nowrap;border:1.5px solid;
              <?php echo $filterDir===$dv
                ? ($dv==='in' ? 'background:#DCFCE7;color:#15803D;border-color:#86EFAC;'
                   : ($dv==='out' ? 'background:#FEE2E2;color:#DC2626;border-color:#FCA5A5;'
                   : 'background:#0f0f0f;color:#fff;border-color:#0f0f0f;'))
                : 'background:#fff;color:#374151;border-color:#e8e8e3;'; ?>">
      <?php echo $dl; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Row 3: Category search (live filter) + Status -->
  <div style="display:flex;gap:6px;align-items:center;width:100%;flex-wrap:wrap;">
    <div style="position:relative;flex:1;min-width:160px;">
      <input type="text" id="cbCatSearch"
        value="<?php echo htmlspecialchars($filterCat); ?>"
        placeholder="🗂 Search category / expense head…"
        class="cb3-fdr-fi" style="width:100%;box-sizing:border-box;padding-right:28px;"
        oninput="cbCatFilter(this.value)"
        onfocus="document.getElementById('cbCatDrop').style.display='block'"
        onblur="setTimeout(()=>document.getElementById('cbCatDrop').style.display='none',200)"
        autocomplete="off">
      <?php if($filterCat): ?>
      <button onclick="cbv2F('cb_cat','')" style="position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;font-size:14px;color:#94a3b8;cursor:pointer;line-height:1;padding:0;">×</button>
      <?php endif; ?>
      <!-- Category dropdown -->
      <div id="cbCatDrop" style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid #e8e8e3;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:500;max-height:220px;overflow-y:auto;">
        <div style="padding:6px 10px;border-bottom:1px solid #f1f5f9;">
          <span style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;">Cash In</span>
        </div>
        <?php foreach(CashbookService::CATEGORIES_IN as $c): ?>
        <div class="cb-cat-opt" data-cat="<?php echo htmlspecialchars($c); ?>"
          onclick="cbv2F('cb_cat','<?php echo htmlspecialchars(addslashes($c)); ?>')"
          style="padding:8px 12px;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:8px;<?php echo $filterCat===$c?'background:#f0fdf4;font-weight:700;':''; ?>">
          <span><?php echo cbCatIcon($c); ?></span> <?php echo htmlspecialchars($c); ?>
        </div>
        <?php endforeach; ?>
        <div style="padding:6px 10px;border-top:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;">
          <span style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;">Cash Out / Expense Heads</span>
        </div>
        <?php foreach(CashbookService::CATEGORIES_OUT as $c): ?>
        <div class="cb-cat-opt" data-cat="<?php echo htmlspecialchars($c); ?>"
          onclick="cbv2F('cb_cat','<?php echo htmlspecialchars(addslashes($c)); ?>')"
          style="padding:8px 12px;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:8px;<?php echo $filterCat===$c?'background:#fff7ed;font-weight:700;':''; ?>">
          <span><?php echo cbCatIcon($c); ?></span> <?php echo htmlspecialchars($c); ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <select class="cb3-fdr-fi" style="min-width:130px;" onchange="cbv2F('cb_vs',this.value)">
      <option value="">All Status</option>
      <?php foreach(CashbookService::VAL_STATUSES as $k=>$v2): ?>
      <option value="<?php echo $k; ?>" <?php echo $filterVal===$k?'selected':''; ?>><?php echo htmlspecialchars($v2); ?></option>
      <?php endforeach; ?>
    </select>
    <?php if($anyFilterAll): ?>
    <a href="?<?php echo http_build_query(['page'=>'dashboard','tab'=>'cashbook','cb_proj'=>$proj,'cb_view'=>'ledger']); ?>"
      style="padding:6px 12px;background:#fee2e2;color:#dc2626;border-radius:7px;font-size:11px;font-weight:800;text-decoration:none;white-space:nowrap;">✕ Clear all</a>
    <?php endif; ?>
  </div>

</div>
<script>
function cbCatFilter(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('.cb-cat-opt').forEach(function(el) {
    el.style.display = !q || el.dataset.cat.toLowerCase().includes(q) ? '' : 'none';
  });
  document.getElementById('cbCatDrop').style.display = 'block';
}
// Hover highlight for cat options
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.cb-cat-opt').forEach(function(el) {
    el.addEventListener('mouseenter', function() { this.style.background = '#f8f8f5'; });
    el.addEventListener('mouseleave', function() { this.style.background = ''; });
  });
});
</script>
<?php if($isAdmin): ?>
<!-- Bulk action bar (hidden until selection) -->
<div id="cbBulkBar" class="cb3-bulk">
  <span id="cbBulkCount" class="cb3-bulk-cnt">0 selected</span>
  <div class="cb3-bulk-btns">
    <button onclick="cbBulkSelectSource('crm_api_sync')" class="cb3-bulk-btn" style="background:#1e40af;color:#fff;">🌐 CRM Sync</button>
    <button onclick="cbBulkSelectSource('crm_sync')"     class="cb3-bulk-btn" style="background:#065f46;color:#fff;">📦 Local Sync</button>
    <button onclick="cbBulkSelectAll()"                  class="cb3-bulk-btn" style="background:#374151;color:#fff;">☑ All Page</button>
    <button onclick="cbBulkClear()"                      class="cb3-bulk-btn" style="background:#374151;color:#fff;">✕ Clear</button>
  </div>
  <button onclick="cbBulkDelete()" class="cb3-bulk-del">🗑 Delete</button>
</div>
<form id="cbBulkForm" method="POST">
<?= csrfField() ?>
  <input type="hidden" name="cb_action" value="bulk_delete_entries">
  <input type="hidden" name="cb_proj"   value="<?php echo $proj; ?>">
  <div id="cbBulkIds"></div>
</form>
<?php endif; ?>

<?php if(empty($ledger)): ?>
<div class="cb3-empty"><?php echo $seeded?'No entries match your filters.':'Import Excel data first.'; ?></div>
<?php else: ?>

<!-- ══ MOBILE: Card layout ══ -->
<div class="cb3-cards">
<?php foreach($ledger as $e):
  $isIn=$e['direction']==='in'; $isPend=$e['validation_status']==='pending';
  $src=$e['source']??'manual';
  $srcBadge=''; 
  if($src==='crm_api_sync'||$src==='crm_webhook') $srcBadge='<span class="cb3-card-src crm">🌐CRM</span>';
  elseif($src==='collect_payment'||$src==='crm_sync') $srcBadge='<span class="cb3-card-src pwa">📱PWA</span>';
  $crudData = htmlspecialchars(json_encode(['sr'=>$e['sr'],'date'=>$e['date'],'direction'=>$e['direction'],'amount'=>$e['amount'],'category'=>$e['category'],'category_raw'=>$e['category_raw']??'','person'=>$e['person'],'description'=>$e['description'],'validation_ref'=>$e['validation_ref'],'validation_status'=>$e['validation_status'],'source'=>$src]),ENT_QUOTES);
?>
<div class="cb3-card <?php echo $isPend?'pend':''; ?>" data-id="<?php echo $e['id']; ?>" data-src="<?php echo htmlspecialchars($src); ?>">
  <?php if($isAdmin): ?>
  <div class="cb3-card-sel">
    <input type="checkbox" class="cb-row-chk" value="<?php echo $e['id']; ?>" onchange="cbSelChanged()" style="width:16px;height:16px;cursor:pointer;">
  </div>
  <?php endif; ?>
  <div class="cb3-card-body">
    <div class="cb3-card-top">
      <div class="cb3-card-desc" title="<?php echo htmlspecialchars($e['description']); ?>"><?php echo htmlspecialchars(mb_strimwidth($e['description'],0,55,'…')); ?></div>
      <div class="cb3-card-amt <?php echo $isIn?'in':'out'; ?>"><?php
        if (($e['currency']??'USD')==='SSP' && !empty($e['ssp_amount'])):
          echo ($isIn?'+':'-') . number_format((float)$e['ssp_amount'],0) . ' <span style="font-size:10px;font-weight:800;background:#fef3c7;color:#92400e;border-radius:6px;padding:1px 5px;">SSP</span>';
          if (!empty($e['ssp_rate'])): ?><div style="font-size:9px;color:#92400e;margin-top:2px;">≈ $<?php echo number_format($e['amount'],2); ?> @<?php echo number_format($e['ssp_rate'],0); ?></div><?php endif;
        else:
          echo ($isIn?'+':'-') . '$' . number_format($e['amount'],2);
        endif; ?></div>
    </div>
    <div class="cb3-card-meta">
      <span class="cb3-card-date"><?php echo $e['date']; ?></span>
      <span class="cb3-card-cat"><?php echo cbCatIcon($e['category']); ?> <?php echo htmlspecialchars($e['category']); ?></span>
      <?php if($e['person']): ?><span class="cb3-card-cat"><?php echo htmlspecialchars(mb_strimwidth($e['person'],0,15,'…')); ?></span><?php endif; ?>
      <?php if(($e['currency']??'USD')==='SSP'): ?><span style="background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;">SSP</span><?php endif; ?>
      <?php echo $srcBadge; ?>
      <?php echo cbValBadge($e['validation_status']); ?>
      <?php $_cw = $e['cash_with'] ?? ''; if ($_cw && $_cw !== 'Office' && $isIn): ?>
        <span style="background:#fef2f2;color:#dc2626;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;">💰 <?= htmlspecialchars($_cw) ?></span>
      <?php elseif ($_cw === 'Office' && $isIn): ?>
        <span style="background:#dcfce7;color:#166534;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;">✅ Office</span>
      <?php endif; ?>
      <span class="cb3-card-sr"><?php echo htmlspecialchars($e['sr']); ?></span>
    </div>
  </div>
  <?php if($isAdmin): ?>
  <button class="cb3-card-menu" onclick="cbCrud(<?php echo $e['id']; ?>,<?php echo $crudData; ?>)" style="background:#fafaf8;border:none;border-left:1px solid var(--border);font-size:18px;color:#94a3b8;cursor:pointer;padding:0 12px;">⋮</button>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- ══ DESKTOP: Table layout ══ -->
<div class="cb3-tbl-wrap">
<table class="cbv2-tbl">
  <thead><tr>
    <?php if($isAdmin): ?><th style="width:30px;"><input type="checkbox" id="cbSelAll" onchange="cbSelToggleAll(this)" style="cursor:pointer;width:14px;height:14px;"></th><?php endif; ?>
    <th>SR</th><th>Date</th><th>Particulars</th><th>Category</th><th>Person</th>
    <th style="color:var(--green);"><?= $filterCurr==='SSP'?'In SSP ↓':'In ↓' ?></th><th style="color:#dc2626;"><?= $filterCurr==='SSP'?'Out SSP ↑':'Out ↑' ?></th><th><?= $filterCurr==='SSP'?'Balance SSP':'Balance' ?></th><th>Ref</th><th>Status</th><th>Src</th>
    <?php if($isAdmin): ?><th></th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php foreach($ledger as $e):
    $isIn=$e['direction']==='in'; $isPend=$e['validation_status']==='pending';
    $src=$e['source']??'manual';
    $crudData2 = htmlspecialchars(json_encode(['sr'=>$e['sr'],'date'=>$e['date'],'direction'=>$e['direction'],'amount'=>$e['amount'],'category'=>$e['category'],'category_raw'=>$e['category_raw']??'','person'=>$e['person'],'description'=>$e['description'],'validation_ref'=>$e['validation_ref'],'validation_status'=>$e['validation_status'],'source'=>$src]),ENT_QUOTES); ?>
  <tr <?php echo $isPend?'style="background:#fffbeb;"':''; ?> data-id="<?php echo $e['id']; ?>" data-src="<?php echo htmlspecialchars($src); ?>">
    <?php if($isAdmin): ?><td><input type="checkbox" class="cb-row-chk" value="<?php echo $e['id']; ?>" onchange="cbSelChanged()" style="cursor:pointer;width:14px;height:14px;"></td><?php endif; ?>
    <td style="font-family:monospace;font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars($e['sr']); ?></td>
    <td style="font-family:monospace;font-size:10px;white-space:nowrap;"><?php echo $e['date']; ?></td>
    <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500;" title="<?php echo htmlspecialchars($e['description']); ?>"><?php echo htmlspecialchars(mb_strimwidth($e['description'],0,65,'…')); ?></td>
    <td style="font-size:11px;"><?php echo cbCatIcon($e['category']); ?> <?php echo htmlspecialchars($e['category']); ?></td>
    <td style="font-size:11px;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(mb_strimwidth($e['person'],0,18,'…')); ?><?php
      $_cw2 = $e['cash_with'] ?? '';
      if ($_cw2 && $_cw2 !== 'Office' && $isIn): ?> <span style="background:#fef2f2;color:#dc2626;border-radius:8px;padding:1px 5px;font-size:9px;font-weight:800;">💰<?= htmlspecialchars($_cw2) ?></span><?php
      elseif ($_cw2 === 'Office' && $isIn): ?> <span style="background:#dcfce7;color:#166534;border-radius:8px;padding:1px 5px;font-size:9px;font-weight:800;">✅</span><?php
      endif; ?></td>
    <?php $_isSsp = ($e['currency']??'USD')==='SSP'; $_sspAmt = !empty($e['ssp_amount']) ? number_format((float)$e['ssp_amount'],0).' SSP' : '$'.number_format($e['amount'],2); ?>
    <td class="cbv2-in"><?php echo $isIn ? ($_isSsp ? '<span style="color:#92400e;font-weight:700;">'.$_sspAmt.'</span>' : '$'.number_format($e['amount'],2)) : ''; ?></td>
    <td class="cbv2-out"><?php echo !$isIn ? ($_isSsp ? '<span style="color:#92400e;font-weight:700;">'.$_sspAmt.'</span>' : '$'.number_format($e['amount'],2)) : ''; ?></td>
    <td style="font-family:monospace;font-size:11px;"><?php
      if ($e['running_balance'] !== null) {
          // v4.9.18: Use _bal_currency to show correct format (SSP vs USD)
          if ($filterCurr === 'SSP' || ($e['_bal_currency'] ?? '') === 'SSP') {
              echo number_format($e['running_balance'], 0) . ' <span style="color:#92400e;font-size:9px;">SSP</span>';
          } else {
              echo '$' . number_format($e['running_balance'], 2);
          }
      } else { echo '—'; }
    ?></td>
    <td style="font-size:10px;color:#94a3b8;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars(mb_strimwidth($e['validation_ref'],0,20,'…')); ?></td>
    <td><?php echo cbValBadge($e['validation_status']); ?></td>
    <td><?php if($src==='crm_api_sync'||$src==='crm_webhook') echo '<span style="background:#dbeafe;color:#1e40af;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">🌐CRM</span>';
      elseif($src==='collect_payment'||$src==='crm_sync') echo '<span style="background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">📱PWA</span>';
      elseif($src==='field_exchange') echo '<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;" title="Auto-recorded by staff via exchange form — do NOT enter manually">💱Auto</span>';
      elseif($src==='expense_sync') echo '<span style="background:#f5f3ff;color:#6d28d9;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:800;">💸Exp</span>';
      else echo '<span style="color:#cbd5e1;font-size:10px;">✎</span>'; ?></td>
    <?php if($isAdmin): ?>
    <td style="white-space:nowrap;">
      <?php if($isPend): ?>
      <button onclick="cbv2OS(<?php echo $e['id']; ?>,'<?php echo htmlspecialchars(addslashes($e['sr'])); ?>','<?php echo htmlspecialchars(addslashes($e['person'])); ?>',<?php echo $e['amount']; ?>)"
        style="padding:3px 8px;background:#fef3c7;border:1px solid #fde68a;border-radius:5px;font-size:10px;font-weight:800;cursor:pointer;color:#92400e;">Settle</button>
      <?php endif; ?>
      <button onclick="cbCrud(<?php echo $e['id']; ?>,<?php echo $crudData2; ?>)"
        style="padding:3px 8px;background:#f8fafc;border:1px solid #e5e5e0;border-radius:5px;font-size:11px;cursor:pointer;color:#64748b;">⋮</button>
    </td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<!-- ══ PAGER ══ -->
<div class="cb3-pager">
  <div class="cb3-pg-info"><?php echo number_format($offset+1); ?>–<?php echo number_format(min($offset+$perPage,$totalRows)); ?> of <?php echo number_format($totalRows); ?></div>
  <div class="cb3-pg-btns">
    <?php if($page>1): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>1])); ?>" class="cb3-pg">«</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$page-1])); ?>" class="cb3-pg">‹</a>
    <?php endif; ?>
    <?php for($p2=max(1,$page-2);$p2<=min($totalPages,$page+2);$p2++): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$p2])); ?>" class="cb3-pg <?php echo $p2===$page?'on':''; ?>"><?php echo $p2; ?></a>
    <?php endfor; ?>
    <?php if($page<$totalPages): ?>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$page+1])); ?>" class="cb3-pg">›</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_page'=>$totalPages])); ?>" class="cb3-pg">»</a>
    <?php endif; ?>
  </div>
</div>

<?php elseif($view==='pending'): ?>
<div class="cbv2-panel">
  <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
    <span style="font-size:12px;color:#78350f;font-weight:600;">&#9888; Cash given to staff with no receipt yet. Click <strong>Settle</strong> when voucher received. Enter change returned if any &mdash; auto-posts as Cash IN.</span>
    <?php if($pendingCount > 0 && $isAdmin): ?>
    <button onclick="document.getElementById('cbSettleAllBox').style.display='block'" style="background:#d97706;color:#fff;border:none;border-radius:7px;padding:7px 14px;font-size:11px;font-weight:800;cursor:pointer;white-space:nowrap;font-family:inherit;">
      &#10003; Settle All <?php echo $pendingCount; ?> Items
    </button>
    <?php endif; ?>
  </div>
  <?php if($pendingCount > 0 && $isAdmin): ?>
  <div id="cbSettleAllBox" style="display:none;background:#fff8f0;border:1.5px solid #fed7aa;border-radius:10px;padding:16px;margin-bottom:14px;">
    <div style="font-size:13px;font-weight:800;color:#92400e;margin-bottom:10px;">&#128204; Settle All <?php echo $pendingCount; ?> Pending Items</div>
    <p style="font-size:12px;color:#78350f;margin-bottom:12px;line-height:1.5;">
      This will mark all <strong><?php echo $pendingCount; ?> items</strong> ($<?php echo number_format($pendingTotal,2); ?>) as settled with the note below.<br>
      Use this to close out historical items from the Excel import and start fresh from today (11 Mar 2026).
    </p>
    <form method="POST">
<?= csrfField() ?>
      <input type="hidden" name="cb_action" value="settle_all_pending">
      <input type="hidden" name="project" value="<?php echo $proj; ?>">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="text" name="settle_note" value="Carried Forward — settled 11 Mar 2026" class="cbv2-fi" style="flex:1;min-width:0;font-size:16px!important;">
        <button type="submit" style="background:#b45309;color:#fff;border:none;border-radius:7px;padding:8px 18px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;">
          &#10003; Confirm Settle All
        </button>
        <button type="button" onclick="document.getElementById('cbSettleAllBox').style.display='none'" style="background:#f1f5f9;color:#374151;border:1px solid #e5e5e0;border-radius:7px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;">Cancel</button>
      </div>
    </form>
  </div>
  <?php endif; ?>
  <?php if(!empty($staffPos)): ?>
  <div class="cbv2-sg">
    <?php foreach($staffPos as $s): ?>
    <div class="cbv2-sc">
      <div class="cbv2-sc-name"><div class="cbv2-dot <?php echo $s['status']; ?>"></div><?php echo htmlspecialchars($s['person']); ?></div>
      <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?php echo $s['cnt']; ?> items</div>
      <div style="display:flex;gap:10px;margin-top:8px;">
        <div><div style="font-size:15px;font-weight:900;color:#dc2626;">$<?php echo number_format($s['total'],0); ?></div><div style="font-size:9px;text-transform:uppercase;color:#94a3b8;">Unreceipted</div></div>
        <div><div style="font-size:15px;font-weight:900;color:<?php echo $s['days_oldest']>60?'#dc2626':'#d97706'; ?>;"><?php echo $s['days_oldest']; ?>d</div><div style="font-size:9px;text-transform:uppercase;color:#94a3b8;">Oldest</div></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if(empty($pendingDisbs)): ?>
    <div style="text-align:center;padding:40px;color:#94a3b8;">&#10003; No pending disbursements!</div>
  <?php else: ?>
    <?php foreach($pendingDisbs as $d): ?>
    <div class="cbv2-disb <?php echo $d['days_pending']>30?'overdue':'mild'; ?>">
      <div class="cbv2-disb-head">
        <div>
          <div class="cbv2-disb-person"><?php echo $d['days_pending']>30?'&#128308; ':'&#128993; '; echo htmlspecialchars($d['person']?:'(no person)'); ?></div>
          <div class="cbv2-disb-desc"><?php echo htmlspecialchars(mb_strimwidth($d['description'],0,120,'…')); ?></div>
        </div>
        <div class="cbv2-disb-amt">$<?php echo number_format($d['amount'],2); ?></div>
      </div>
      <div class="cbv2-disb-meta">
        <span class="cbv2-disb-tag"><?php echo htmlspecialchars($d['sr']); ?></span>
        <span class="cbv2-disb-tag"><?php echo $d['date']; ?></span>
        <span class="cbv2-disb-tag"><?php echo $d['days_pending']; ?> days</span>
        <span class="cbv2-disb-tag"><?php echo htmlspecialchars($d['category']); ?></span>
        <?php if($d['project']==='4g'): ?><span class="cbv2-disb-tag" style="background:#e0f2fe;color:#0369a1;">4G</span><?php elseif($d['project']==='bluecard'): ?><span class="cbv2-disb-tag" style="background:#dcfce7;color:#166534;">BC</span><?php endif; ?>
      </div>
      <div class="cbv2-disb-acts">
        <button class="cbv2-settle" onclick="cbv2OS(<?php echo $d['id']; ?>,'<?php echo htmlspecialchars(addslashes($d['sr'])); ?>','<?php echo htmlspecialchars(addslashes($d['person'])); ?>',<?php echo $d['amount']; ?>)">&#10003; Settle — Enter Voucher</button>
        <button class="cbv2-remind" onclick="cbv2SendReminder(<?php echo $d['id']; ?>,'<?php echo htmlspecialchars(addslashes($d['person'])); ?>')">&#128241; WhatsApp Reminder</button>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php elseif($view==='payroll'): ?>
<?php
// getPayrollSummary now returns pre-pivoted rows: person,salary,transport,food,other,voucher_ref
$payMonth = $_GET['cb_pm'] ?? date('Y-m');
$payroll  = $cb->getPayrollSummary($proj, $payMonth);
$totS=0; $totT=0; $totF=0; $totO=0;
foreach($payroll as $r){ $totS+=$r['salary']; $totT+=$r['transport']; $totF+=$r['food']; $totO+=$r['other']; }
$grand = $totS+$totT+$totF+$totO;
?>
<div class="cbv2-tb">
  <input type="month" class="cbv2-fi" value="<?php echo $payMonth; ?>" onchange="cbv2F('cb_pm',this.value)">
  <span style="font-size:12px;color:#94a3b8;">Total: <strong>$<?php echo number_format($grand,2); ?></strong></span>
</div>
<div style="overflow-x:auto;background:#fff;">
<table class="cbv2-tbl">
  <thead><tr><th>Employee</th><th>Salary</th><th>Transport</th><th>Food Allow.</th><th>Other</th><th>Total</th><th>Voucher</th></tr></thead>
  <tbody>
  <?php if(empty($payroll)): ?>
  <tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8;">No payroll for <?php echo $payMonth; ?></td></tr>
  <?php else: ?>
  <?php foreach($payroll as $r):
    $rowT = $r['salary']+$r['transport']+$r['food']+$r['other']; ?>
  <tr>
    <td style="font-weight:700;"><?php echo htmlspecialchars($r['person']); ?></td>
    <td class="cbv2-out"><?php echo $r['salary']  ? '$'.number_format($r['salary'],0)    : '—'; ?></td>
    <td class="cbv2-out"><?php echo $r['transport']? '$'.number_format($r['transport'],0) : '—'; ?></td>
    <td class="cbv2-out"><?php echo $r['food']     ? '$'.number_format($r['food'],0)      : '—'; ?></td>
    <td class="cbv2-out"><?php echo $r['other']    ? '$'.number_format($r['other'],0)     : '—'; ?></td>
    <td style="font-weight:900;font-size:13px;">$<?php echo number_format($rowT,0); ?></td>
    <td style="font-size:10px;color:#94a3b8;"><?php echo htmlspecialchars(substr($r['voucher_ref']??'',0,15)); ?></td>
  </tr>
  <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
  <tfoot><tr style="background:#f8fafc;font-weight:900;"><td>TOTAL</td>
    <td class="cbv2-out">$<?php echo number_format($totS,0); ?></td>
    <td class="cbv2-out">$<?php echo number_format($totT,0); ?></td>
    <td class="cbv2-out">$<?php echo number_format($totF,0); ?></td>
    <td class="cbv2-out">$<?php echo number_format($totO,0); ?></td>
    <td style="font-size:14px;">$<?php echo number_format($grand,0); ?></td>
    <td></td>
  </tr></tfoot>
</table>
</div>

<?php elseif($view==='sites'): ?>
<?php
$siteType=$_GET['cb_st']??'power';
$sites=$cb->getSiteTracker($siteType);
$today=date('Y-m-d');
?>
<div class="cbv2-tb">
  <div style="display:flex;background:#f1f5f9;border-radius:8px;padding:3px;gap:2px;">
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_st'=>'power'])); ?>"
       style="padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;color:<?php echo $siteType==='power'?'#fff':'#64748b'; ?>;background:<?php echo $siteType==='power'?'#0a0a0a':'transparent'; ?>;">&#9889; Power</a>
    <a href="?<?php echo http_build_query(array_merge($_GET,['cb_st'=>'rent'])); ?>"
       style="padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;color:<?php echo $siteType==='rent'?'#fff':'#64748b'; ?>;background:<?php echo $siteType==='rent'?'#0a0a0a':'transparent'; ?>;">&#127959; Rent</a>
  </div>
  <span style="font-size:12px;color:#94a3b8;"><?php echo count($sites); ?> sites</span>
</div>
<div style="overflow-x:auto;background:#fff;">
<table class="cbv2-site-tbl">
  <thead><tr><th>Site Name</th><th>Count</th><th>Total Paid</th><th>Last Payment</th><th>Days Since</th><th>Status</th></tr></thead>
  <tbody>
  <?php if(empty($sites)): ?>
  <tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">No 4G site <?php echo $siteType; ?> data.</td></tr>
  <?php else: ?>
  <?php foreach($sites as $s):
    $dSince=(int)round((strtotime($today)-strtotime($s['last_date'] ?? ''))/86400);
    $sStatus=$dSince>45?'overdue':($s['last_status']==='pending'?'pending':'paid'); ?>
  <tr>
    <td style="font-weight:700;"><?php echo htmlspecialchars(preg_replace('/^(Power-|Site Rent-|Rent-)/i','',$s['site_name'])); ?></td>
    <td style="color:#94a3b8;"><?php echo $s['cnt']; ?>&times;</td>
    <td style="font-weight:700;">$<?php echo number_format($s['total_paid'] ?? 0,0); ?></td>
    <td style="font-family:monospace;font-size:11px;color:#64748b;"><?php echo $s['last_date']; ?></td>
    <td style="font-size:12px;font-weight:700;color:<?php echo $dSince>45?'#dc2626':($dSince>30?'#d97706':'#374151'); ?>;"><?php echo $dSince; ?>d</td>
    <td style="font-size:11px;font-weight:700;color:<?php echo $sStatus==='paid'?'#059669':($sStatus==='pending'?'#d97706':'#dc2626'); ?>;">
      <?php echo $sStatus==='paid'?'&#10003; Paid':($sStatus==='pending'?'&#9203; No receipt':'&#128308; Overdue'); ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php elseif($view==='interco'): ?>
<?php
$interco=$cb->getIntercoTransfers();
$icIn=array_sum(array_column(array_filter($interco,fn($r)=>$r['direction']==='in'),'amount'));
$icOut=array_sum(array_column(array_filter($interco,fn($r)=>$r['direction']==='out'),'amount'));
?>
<div class="cbv2-panel">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px;">
    <div class="cbv2-iccard"><div class="cbv2-iclbl">Africa &#8594; 4G (Loaned)</div><div class="cbv2-icval" style="color:#f87171;">$<?php echo number_format($icOut,2); ?></div></div>
    <div class="cbv2-iccard"><div class="cbv2-iclbl">4G &#8594; Africa (Repaid)</div><div class="cbv2-icval" style="color:#4ade80;">$<?php echo number_format($icIn,2); ?></div></div>
    <div class="cbv2-iccard"><div class="cbv2-iclbl">Net 4G Owes</div><div class="cbv2-icval" style="color:#fbbf24;">$<?php echo number_format(abs($icOut-$icIn),2); ?></div></div>
  </div>
  <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:14px;font-size:12px;color:#78350f;">
    <strong>Flow:</strong> Rupesh transfers from Africa book when 4G needs funds. BBC &amp; Yogibhai repay via collections. Tagged <em>DishNet Africa Ltd-Loan Return</em>.
  </div>
  <div style="overflow-x:auto;background:#fff;border-radius:10px;border:1px solid #e5e5e0;">
  <table class="cbv2-tbl">
    <thead><tr><th>Date</th><th>Project</th><th>Direction</th><th>Amount</th><th>Description</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach($interco as $e): ?>
    <tr>
      <td class="cbv2-date"><?php echo $e['date']; ?></td>
      <td><?php
        $ePrj = $e['project'] ?? 'dishnet';
        if ($ePrj === '4g') echo '<span style="background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:800;">4G</span>';
        elseif ($ePrj === 'bluecard') echo '<span style="background:#dcfce7;color:#166534;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:800;">BC</span>';
        else echo '<span style="background:#f1f5f9;color:#374151;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:800;">Fiber&SL</span>';
      ?></td>
      <td><?php echo $e['direction']==='in'?'<span style="color:#059669;font-weight:700;">&#8592; Received</span>':'<span style="color:#dc2626;font-weight:700;">&#8594; Sent</span>'; ?></td>
      <td style="font-weight:800;font-size:13px;">$<?php echo number_format($e['amount'],2); ?></td>
      <td style="font-size:12px;color:#374151;"><?php echo htmlspecialchars(mb_strimwidth($e['description'],0,60,'…')); ?></td>
      <td><?php echo cbValBadge($e['validation_status']); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php elseif($view==='summary'): ?>
<?php
$sumYear=$_GET['cb_yr']??date('Y');
$summary=$cb->getSummary($proj,$sumYear.'-01-01',$sumYear.'-12-31');
$maxIn=max(1,max(array_column($summary['in']??[[1]],'total')));
$maxOut=max(1,max(array_column($summary['out']??[[1]],'total')));
$inColors=['#059669','#0891b2','#7c3aed','#0369a1','#065f46','#1d4ed8'];
$outColors=['#dc2626','#ea580c','#d97706','#7c3aed','#0d9488','#1d4ed8','#6d28d9','#374151'];
?>
<div class="cbv2-tb">
  <select class="cbv2-fi" onchange="cbv2F('cb_yr',this.value)">
    <?php foreach(['2026','2025','2024'] as $yr): ?>
    <option value="<?php echo $yr; ?>" <?php echo $sumYear===$yr?'selected':''; ?>><?php echo $yr; ?></option>
    <?php endforeach; ?>
  </select>
  <span style="font-size:12px;color:#94a3b8;">IN: <strong style="color:#059669;">$<?php echo number_format($summary['total_in'],0); ?></strong> &nbsp; OUT: <strong style="color:#dc2626;">$<?php echo number_format($summary['total_out'],0); ?></strong> &nbsp; Net: <strong style="color:<?php echo $summary['balance']>=0?'#059669':'#dc2626'; ?>;">$<?php echo number_format($summary['balance'],0); ?></strong></span>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#e5e5e0;">
  <div style="background:#fff;padding:18px;">
    <div style="font-size:13px;font-weight:800;color:#059669;margin-bottom:14px;">&#128176; Cash IN &mdash; $<?php echo number_format($summary['total_in'],0); ?></div>
    <?php $i=0; foreach($summary['in'] as $cat=>$d): $col=$inColors[$i%count($inColors)]; $i++; ?>
    <div class="cbv2-sum-row">
      <div class="cbv2-sum-label"><?php echo cbCatIcon($cat).' '.htmlspecialchars($cat); ?></div>
      <div class="cbv2-sum-bw"><div class="cbv2-sum-b" style="width:<?php echo round($d['total']/$maxIn*100); ?>%;background:<?php echo $col; ?>;"></div></div>
      <div class="cbv2-sum-a" style="color:<?php echo $col; ?>;">$<?php echo number_format($d['total'],0); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="background:#fff;padding:18px;">
    <div style="font-size:13px;font-weight:800;color:#dc2626;margin-bottom:14px;">&#128184; Cash OUT &mdash; $<?php echo number_format($summary['total_out'],0); ?></div>
    <?php $i=0; foreach($summary['out'] as $cat=>$d): $col=$outColors[$i%count($outColors)]; $i++; ?>
    <div class="cbv2-sum-row">
      <div class="cbv2-sum-label"><?php echo cbCatIcon($cat).' '.htmlspecialchars($cat); ?></div>
      <div class="cbv2-sum-bw"><div class="cbv2-sum-b" style="width:<?php echo round($d['total']/$maxOut*100); ?>%;background:<?php echo $col; ?>;"></div></div>
      <div class="cbv2-sum-a" style="color:<?php echo $col; ?>;">$<?php echo number_format($d['total'],0); ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php elseif($view==='alerts'): ?>
<div style="padding-bottom:40px;">
  <div style="margin-bottom:18px;">
    <div style="font-size:17px;font-weight:800;color:#0f0f0f;margin-bottom:3px;">🚦 Accounting Integrity</div>
    <div style="font-size:12px;color:#94a3b8;">Auto-detected issues that could mean errors, missing receipts, or data gaps</div>
  </div>
  <?php if(empty($_alerts)): ?>
  <div style="background:#dcfce7;border:1.5px solid #86efac;border-radius:14px;padding:32px;text-align:center;">
    <div style="font-size:36px;margin-bottom:10px;">✅</div>
    <div style="font-size:15px;font-weight:800;color:#15803d;">All clear — no issues detected</div>
    <div style="font-size:12px;color:#166534;margin-top:4px;">Balance is positive, no overdue disbursements, no duplicates</div>
  </div>
  <?php else: ?>
  <?php foreach($_alerts as $al): ?>
  <?php
    $bg  = $al['level']==='red'  ? '#FEF2F2' : '#FFFBEB';
    $bd  = $al['level']==='red'  ? '#FECACA' : '#FDE68A';
    $tc  = $al['level']==='red'  ? '#DC2626' : '#B45309';
  ?>
  <div style="background:<?php echo $bg; ?>;border:1.5px solid <?php echo $bd; ?>;border-radius:14px;padding:16px 18px;margin-bottom:12px;display:flex;gap:14px;align-items:flex-start;">
    <div style="font-size:26px;flex-shrink:0;line-height:1;margin-top:2px;"><?php echo $al['icon']; ?></div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:14px;font-weight:800;color:<?php echo $tc; ?>;margin-bottom:4px;"><?php echo htmlspecialchars($al['title']); ?></div>
      <div style="font-size:12px;color:#374151;line-height:1.5;"><?php echo $al['detail']; ?></div>
      <?php if($al['link']): ?>
      <a href="<?php echo htmlspecialchars($al['link']); ?>" style="display:inline-block;margin-top:8px;font-size:11px;font-weight:700;color:<?php echo $tc; ?>;text-decoration:underline;">→ View entries</a>
      <?php endif; ?>
    </div>
    <div style="font-size:10px;font-weight:800;padding:3px 9px;border-radius:20px;background:<?php echo $al['level']==='red'?'#DC2626':'#D97706'; ?>;color:#fff;flex-shrink:0;text-transform:uppercase;"><?php echo $al['level']==='red'?'Critical':'Warning'; ?></div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Known structural issues advisory -->
  <div style="background:#f8f8f5;border:1px solid #e5e5e0;border-radius:14px;padding:18px;margin-top:18px;">
    <div style="font-size:13px;font-weight:800;color:#374151;margin-bottom:12px;">📋 Structural observations (review manually)</div>
    <?php
    // SR number range sanity check
    $srCount = $cb->query("SELECT COUNT(*) as c FROM cb_ledger WHERE project=?", [$proj])[0]['c']??0;
    $dupSrCount = $cb->query("SELECT COUNT(*) as c FROM (SELECT sr FROM cb_ledger WHERE project=? AND sr!='' GROUP BY sr HAVING COUNT(*)>1)", [$proj])[0]['c']??0;
    // Categories with wrong direction
    $wrongDir = $cb->query(
        "SELECT category, direction, COUNT(*) as cnt, SUM(amount) as total FROM cb_ledger WHERE project=? AND status='approved'
         AND ((category IN ('Salary','Commission','Tax','Site Power','Site Rent','Loan Given') AND direction='in')
           OR (category IN ('Receipt','Loan Return Received') AND direction='out'))
         GROUP BY category,direction ORDER BY total DESC LIMIT 10", [$proj]);
    // Entries with no person on salary/travel/loan given
    $missingPerson = $cb->query(
        "SELECT category, COUNT(*) as cnt, SUM(amount) as total FROM cb_ledger 
         WHERE project=? AND status='approved' AND (person IS NULL OR person='')
         AND category IN ('Salary','Commission','Loan Given','Travel & Field','Food Allowance','Transport Allowance')
         GROUP BY category ORDER BY total DESC", [$proj]);
    ?>
    <div style="font-size:12px;color:#374151;line-height:2;">
      <div>📊 Total entries in ledger: <strong><?php echo number_format($srCount); ?></strong>
        <?php if($dupSrCount>0): ?> &nbsp;·&nbsp; <span style="color:#D41C1C;font-weight:700;">⚠ <?php echo $dupSrCount; ?> duplicate SR numbers</span><?php endif; ?></div>
      <?php if(!empty($wrongDir)): ?>
      <div style="margin-top:8px;"><span style="color:#D41C1C;font-weight:700;">⚠ Categories recorded in unexpected direction:</span>
        <div style="margin-top:4px;font-size:11px;">
        <?php foreach($wrongDir as $w): ?>
          <div style="padding:3px 0;border-bottom:1px solid #e5e5e0;"><?php echo htmlspecialchars($w['category']); ?> as <?php echo strtoupper($w['direction']); ?> — <?php echo $w['cnt']; ?> entries totalling $<?php echo number_format($w['total'],0); ?></div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if(!empty($missingPerson)): ?>
      <div style="margin-top:8px;"><span style="color:#D97706;font-weight:700;">⚠ Person not recorded on salary/expense entries:</span>
        <div style="margin-top:4px;font-size:11px;">
        <?php foreach($missingPerson as $mp): ?>
          <div style="padding:3px 0;border-bottom:1px solid #e5e5e0;"><?php echo htmlspecialchars($mp['category']); ?> — <?php echo $mp['cnt']; ?> entries &nbsp;$<?php echo number_format($mp['total'],0); ?> with no person name</div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php elseif($view==='categories' && $isAdmin): ?>
<!-- ══ Category Manager — merged config + real ledger usage ══ -->
<div style="padding-bottom:60px;">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
    <div>
      <div style="font-size:17px;font-weight:800;color:#0f0f0f;">⚙️ Cashbook Categories</div>
      <div style="font-size:12px;color:#94a3b8;margin-top:2px;">
        Configure what appears in the Add Entry form. Usage stats pulled from actual ledger.
      </div>
    </div>
    <button onclick="cbCatSave()" id="cbCatSaveBtn"
      style="background:#16a34a;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">
      💾 Save
    </button>
  </div>

  <!-- Legend -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;font-size:11px;">
    <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#16a34a;display:inline-block;"></span> In config &amp; used</span>
    <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#94a3b8;display:inline-block;"></span> In config, never used</span>
    <span style="display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;display:inline-block;"></span> In ledger but missing from config</span>
  </div>

  <!-- Loading state -->
  <div id="cbCatLoading" style="text-align:center;padding:40px;color:#94a3b8;">
    <div style="font-size:28px;margin-bottom:8px;">⏳</div>
    <div style="font-size:13px;font-weight:600;">Loading categories &amp; usage data...</div>
  </div>

  <!-- Main content (hidden until loaded) -->
  <div id="cbCatContent" style="display:none;">

    <!-- Orphan alert -->
    <div id="cbCatOrphanBox" style="display:none;background:#fffbeb;border:1.5px solid #fcd34d;border-radius:12px;padding:14px 16px;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:800;color:#92400e;margin-bottom:8px;">
        ⚠️ Categories found in ledger but not in your config
      </div>
      <div style="font-size:11px;color:#78350f;margin-bottom:10px;">
        These were used historically. Add them to a group so they appear in the form.
      </div>
      <div id="cbCatOrphanList" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
    </div>

    <!-- Four group panels -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" id="cbCatGroups">
      <div class="cbcat-panel" data-group="in">
        <div class="cbcat-panel-hdr" style="background:#dcfce7;color:#166534;">💰 Cash IN</div>
        <div class="cbcat-list" id="cbcatList_in"></div>
        <div class="cbcat-addbtn" onclick="cbCatAdd('in')">＋ Add</div>
      </div>
      <div class="cbcat-panel" data-group="out_people">
        <div class="cbcat-panel-hdr" style="background:#dbeafe;color:#1d4ed8;">👤 Pay People</div>
        <div class="cbcat-list" id="cbcatList_out_people"></div>
        <div class="cbcat-addbtn" onclick="cbCatAdd('out_people')">＋ Add</div>
      </div>
      <div class="cbcat-panel" data-group="out_ops">
        <div class="cbcat-panel-hdr" style="background:#fef3c7;color:#92400e;">🏢 Operations</div>
        <div class="cbcat-list" id="cbcatList_out_ops"></div>
        <div class="cbcat-addbtn" onclick="cbCatAdd('out_ops')">＋ Add</div>
      </div>
      <div class="cbcat-panel" data-group="out_fin">
        <div class="cbcat-panel-hdr" style="background:#fce7f3;color:#9d174d;">💸 Finance</div>
        <div class="cbcat-list" id="cbcatList_out_fin"></div>
        <div class="cbcat-addbtn" onclick="cbCatAdd('out_fin')">＋ Add</div>
      </div>
    </div>

    <div style="margin-top:14px;font-size:11px;color:#94a3b8;text-align:center;">
      💡 Renaming a category only affects the form label — existing ledger entries keep their original name.
    </div>
  </div>
</div>

<style>
.cbcat-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
.cbcat-panel-hdr{padding:9px 14px;font-size:12px;font-weight:800;}
.cbcat-list{padding:6px;}
.cbcat-row{display:flex;align-items:center;gap:6px;padding:5px 4px;border-bottom:1px solid #f8fafc;position:relative;}
.cbcat-row:last-child{border-bottom:none;}
.cbcat-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.cbcat-ic{width:28px;height:28px;border:1px solid #e2e8f0;border-radius:6px;text-align:center;font-size:14px;padding:0;background:#f8fafc;cursor:text;flex-shrink:0;}
.cbcat-name{flex:1;border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;font-size:12px;font-weight:600;background:#fff;min-width:0;}
.cbcat-lbl{width:72px;border:1px solid #e2e8f0;border-radius:6px;padding:4px 6px;font-size:10px;background:#fff;color:#64748b;flex-shrink:0;}
.cbcat-usage{font-size:9px;font-weight:700;color:#64748b;white-space:nowrap;flex-shrink:0;text-align:right;min-width:46px;}
.cbcat-del{width:22px;height:22px;background:#fef2f2;color:#dc2626;border:none;border-radius:5px;cursor:pointer;font-size:12px;flex-shrink:0;}
.cbcat-addbtn{padding:7px 14px;font-size:11px;font-weight:700;color:#3b82f6;cursor:pointer;text-align:center;border-top:1px dashed #e2e8f0;}
.cbcat-addbtn:hover{background:#eff6ff;}
.cbcat-orphan-chip{background:#fffbeb;border:1.5px solid #fcd34d;border-radius:8px;padding:5px 10px;font-size:11px;font-weight:700;color:#92400e;cursor:pointer;display:flex;align-items:center;gap:5px;}
.cbcat-orphan-chip:hover{background:#fef3c7;}
@media(max-width:640px){#cbCatGroups{grid-template-columns:1fr!important;} .cbcat-lbl{display:none;}}
</style>

<script>
var _cbCatData  = null;  // config from cb_categories.json
var _cbUsageMap = {};    // category name (lowercase) => {total, count, direction}

function cbCatInit() {
  var p1 = fetch('?page=api&action=cb_categories', {credentials:'same-origin'}).then(function(r){return r.json();});
  // Fetch ALL-TIME summary for all 3 projects merged
  var p2 = Promise.all([
    fetch('?page=api&action=cashbook_v2_summary&project=dishnet', {credentials:'same-origin'}).then(function(r){return r.json();}),
    fetch('?page=api&action=cashbook_v2_summary&project=4g',      {credentials:'same-origin'}).then(function(r){return r.json();}),
    fetch('?page=api&action=cashbook_v2_summary&project=bluecard',{credentials:'same-origin'}).then(function(r){return r.json();})
  ]);

  Promise.all([p1, p2]).then(function(results) {
    _cbCatData = results[0].data || results[0];
    var summaries = results[1].map(function(s){ return s.data || s; });

    // Merge usage across all projects
    _cbUsageMap = {};
    summaries.forEach(function(s) {
      if (!s || typeof s !== 'object') return;
      ['in','out'].forEach(function(dir) {
        var cats = s[dir] || {};
        Object.keys(cats).forEach(function(catName) {
          var key = catName.toLowerCase();
          if (!_cbUsageMap[key]) _cbUsageMap[key] = {name:catName, total:0, count:0, direction:dir};
          _cbUsageMap[key].total  += cats[catName].total || 0;
          _cbUsageMap[key].count  += cats[catName].count || 0;
        });
      });
    });

    document.getElementById('cbCatLoading').style.display = 'none';
    document.getElementById('cbCatContent').style.display = 'block';
    cbCatRender();
    cbCatShowOrphans();
  }).catch(function(e) {
    document.getElementById('cbCatLoading').innerHTML =
      '<div style="color:#dc2626;font-size:13px;">❌ Failed to load: ' + e.message + '</div>';
  });
}

function cbCatUsage(catName) {
  return _cbUsageMap[catName.toLowerCase()] || null;
}

function cbCatRender() {
  if (!_cbCatData) return;
  ['in','out_people','out_ops','out_fin'].forEach(function(grp) {
    var list = document.getElementById('cbcatList_' + grp);
    if (!list) return;
    list.innerHTML = '';
    (_cbCatData[grp] || []).forEach(function(cat, idx) {
      list.innerHTML += cbCatRow(grp, idx, cat);
    });
  });
}

function cbCatRow(grp, idx, cat) {
  var u = cbCatUsage(cat.id);
  var dotColor = u ? '#16a34a' : '#cbd5e1';
  var usageHtml = u
    ? '<span class="cbcat-usage" title="' + u.count + ' entries">' + u.count + 'x&nbsp;$' + Math.round(u.total).toLocaleString() + '</span>'
    : '<span class="cbcat-usage" style="color:#e2e8f0;">–</span>';

  return '<div class="cbcat-row">' +
    '<span class="cbcat-dot" style="background:' + dotColor + ';" title="' + (u?u.count+' entries, $'+Math.round(u.total):'Never used') + '"></span>' +
    '<input class="cbcat-ic" type="text" value="' + cbEsc(cat.ic||'📦') + '" ' +
      'onchange="cbCatUpdate(\'' + grp + '\',' + idx + ',\'ic\',this.value)" maxlength="4" title="Emoji">' +
    '<input class="cbcat-name" type="text" value="' + cbEsc(cat.id) + '" ' +
      'onchange="cbCatUpdate(\'' + grp + '\',' + idx + ',\'id\',this.value)" ' +
      'placeholder="Category name">' +
    '<input class="cbcat-lbl" type="text" value="' + cbEsc(cat.lbl||cat.id) + '" ' +
      'onchange="cbCatUpdate(\'' + grp + '\',' + idx + ',\'lbl\',this.value)" placeholder="Label" maxlength="16">' +
    usageHtml +
    '<button class="cbcat-del" onclick="cbCatRemove(\'' + grp + '\',' + idx + ')" title="Remove">✕</button>' +
  '</div>';
}

function cbCatShowOrphans() {
  // Find categories in ledger NOT present in any config group
  var configured = {};
  ['in','out_people','out_ops','out_fin'].forEach(function(grp) {
    (_cbCatData[grp] || []).forEach(function(c) { configured[c.id.toLowerCase()] = true; });
  });

  var orphans = [];
  Object.keys(_cbUsageMap).forEach(function(key) {
    if (!configured[key]) orphans.push(_cbUsageMap[key]);
  });
  orphans.sort(function(a,b){ return b.total - a.total; });

  var box  = document.getElementById('cbCatOrphanBox');
  var list = document.getElementById('cbCatOrphanList');
  if (!orphans.length) { box.style.display='none'; return; }

  box.style.display = 'block';
  list.innerHTML = orphans.map(function(o) {
    var grp = o.direction === 'in' ? 'in' : 'out_ops';
    return '<div class="cbcat-orphan-chip" onclick="cbCatAddOrphan(' +
      cbEscJs(o.name) + ',\'' + grp + '\')" title="Click to add to ' + grp + '">' +
      '📦 ' + cbEsc(o.name) +
      ' <span style="background:#fde68a;border-radius:4px;padding:1px 5px;font-size:9px;">' +
        o.count + 'x · $' + Math.round(o.total).toLocaleString() +
      '</span>' +
      ' <span style="color:#16a34a;font-size:13px;">＋</span>' +
    '</div>';
  }).join('');
}

function cbCatAddOrphan(name, grp) {
  if (!_cbCatData) return;
  if (!_cbCatData[grp]) _cbCatData[grp] = [];
  _cbCatData[grp].push({id:name, ic:'📦', lbl:name.substring(0,14), group:grp});
  cbCatRender();
  cbCatShowOrphans();
  // Scroll to the group
  var el = document.getElementById('cbcatList_'+grp);
  if (el) el.scrollIntoView({behavior:'smooth',block:'center'});
}

function cbCatUpdate(grp, idx, field, val) {
  if (_cbCatData && _cbCatData[grp] && _cbCatData[grp][idx]) {
    _cbCatData[grp][idx][field] = val;
    // Re-render only the usage dot/stats without resetting focus
  }
}

function cbCatAdd(grp) {
  if (!_cbCatData) _cbCatData = {in:[],out_people:[],out_ops:[],out_fin:[]};
  if (!_cbCatData[grp]) _cbCatData[grp] = [];
  _cbCatData[grp].push({id:'',ic:'📦',lbl:'',group:grp});
  cbCatRender();
  var list = document.getElementById('cbcatList_'+grp);
  if (list) {
    var inputs = list.querySelectorAll('.cbcat-name');
    if (inputs.length) { inputs[inputs.length-1].focus(); }
  }
}

function cbCatRemove(grp, idx) {
  if (!_cbCatData || !_cbCatData[grp]) return;
  var name = _cbCatData[grp][idx].id || 'this category';
  var u = cbCatUsage(name);
  var msg = u
    ? 'Remove "' + name + '"?\n\n⚠️ This category has ' + u.count + ' entries ($' + Math.round(u.total).toLocaleString() + ') in the ledger.\nExisting entries will NOT be deleted, but it will disappear from the form.'
    : 'Remove "' + name + '"?';
  if (!confirm(msg)) return;
  _cbCatData[grp].splice(idx, 1);
  cbCatRender();
  cbCatShowOrphans();
}

function cbCatSave() {
  var btn = document.getElementById('cbCatSaveBtn');
  if (!btn || !_cbCatData) return;
  btn.disabled = true; btn.textContent = '⏳ Saving...';
  fetch('?page=api&action=cb_categories_save', {
    credentials:'same-origin', method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify(_cbCatData)
  })
  .then(function(r){ return r.json(); })
  .then(function(resp) {
    var d = resp.data || resp;
    if (d.ok) {
      btn.textContent = '✅ Saved ' + d.saved + ' categories!';
      btn.style.background = '#15803d';
      if (typeof _cb4CatsIN !== 'undefined') {
        _cb4CatsIN         = _cbCatData.in         || [];
        _cb4CatsOUT_people = _cbCatData.out_people  || [];
        _cb4CatsOUT_ops    = _cbCatData.out_ops     || [];
        _cb4CatsOUT_fin    = _cbCatData.out_fin     || [];
      }
      setTimeout(function(){ btn.disabled=false; btn.textContent='💾 Save'; btn.style.background=''; }, 3000);
    } else {
      alert('Save failed: ' + (d.error||'Unknown error'));
      btn.textContent='❌ Failed'; btn.disabled=false;
    }
  })
  .catch(function(e){ alert('Error: '+e.message); btn.textContent='❌ Error'; btn.disabled=false; });
}

function cbEsc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}
function cbEscJs(s) {
  return "'" + String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'") + "'";
}

// Boot
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', cbCatInit);
} else {
  cbCatInit();
}
</script>

<?php endif; ?>
<?php endif; // isFieldAgent ?>

<!-- ═══════════ NEW ENTRY MODAL ═══════════ -->
<!-- ═══════════════════════════════════════════════════════
     CB4 — ADMIN ENTRY MODAL (2-step wizard, mobile-first)
     ═══════════════════════════════════════════════════════ -->
<div class="cb4-mo" id="cb4Modal">
<div class="cb4-mb">

  <!-- Header (color changes with direction) -->
  <div class="cb4-mh neutral" id="cb4MH">
    <div>
      <div class="cb4-mt" id="cb4MTitle">Add Entry</div>
      <div class="cb4-msub" id="cb4MSub">Select direction to begin</div>
    </div>
    <button class="cb4-mclose" onclick="cb4Close()">✕</button>
  </div>

  <div class="cb4-scroll">
  <div class="cb4-mbody">

    <!-- ═══ STEP 1: Currency + Direction + Category ═══ -->
    <div id="cb4Step1">

    <!-- Currency pills -->
    <div class="cb4-curr-row">
      <div class="cb4-cpill sel" id="cb4PillUSD" onclick="cb4SetCurr('USD')">
        <div class="cb4-cpill-lbl">💵 USD</div>
        <div class="cb4-cpill-bal" id="cb4PillUSDbal">$<?php echo number_format($projBal,2); ?></div>
      </div>
      <div class="cb4-cpill" id="cb4PillSSP" onclick="cb4SetCurr('SSP')">
        <div class="cb4-cpill-lbl">🇸🇸 SSP</div>
        <div class="cb4-cpill-bal">SSP</div>
      </div>
    </div>

    <!-- Direction cards -->
    <div class="cb4-dir-row" style="grid-template-columns:1fr 1fr 1fr;">
      <div class="cb4-dir-btn in" id="cb4DirIn" onclick="cb4SetDir('in')">
        <div class="cb4-dir-ic">⬆️</div>
        <div class="cb4-dir-lbl" id="cb4DirInLbl">Cash IN</div>
        <div class="cb4-dir-sub">Receipt</div>
      </div>
      <div class="cb4-dir-btn" id="cb4DirExch" onclick="cb4SetDir('exchange')" style="border:2px solid var(--border);">
        <div class="cb4-dir-ic">🔄</div>
        <div class="cb4-dir-lbl">Exchange</div>
        <div class="cb4-dir-sub">USD ↔ SSP</div>
      </div>
      <div class="cb4-dir-btn out" id="cb4DirOut" onclick="cb4SetDir('out')">
        <div class="cb4-dir-ic">⬇️</div>
        <div class="cb4-dir-lbl">Cash OUT</div>
        <div class="cb4-dir-sub">Expense · Salary</div>
      </div>
    </div>

    <!-- Category section (hidden until direction chosen) -->
    <div id="cb4CatSection" style="display:none;">
      <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:8px;">CATEGORY</div>
      <input type="text" class="cb4-cat-search" id="cb4CatSearch" placeholder="Search categories…" oninput="cb4FilterCats(this.value)">
      <div id="cb4CatGrid"></div>
      <!-- v4.9.10: "Other..." typeahead search panel -->
      <div id="cb4OtherPanel" style="display:none;margin-top:10px;">
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:6px;">SEARCH OR TYPE NEW CATEGORY</div>
        <input type="text" class="cb4-cat-search" id="cb4OtherInput" placeholder="Type to search BookKeeper + history…"
               oninput="cb4OtherFilter(this.value)" autocomplete="off">
        <div id="cb4OtherResults" style="max-height:200px;overflow-y:auto;border:1.5px solid var(--border);border-radius:9px;background:#fff;display:none;"></div>
      </div>
    </div>

    <!-- Next button -->
    <div id="cb4NextWrap" style="display:none;margin-top:16px;">
      <button type="button" class="cb4-next" id="cb4NextBtn" onclick="cb4GoStep2()" disabled>
        Next →
      </button>
    </div>

    </div><!-- /Step 1 -->

    <!-- ═══ STEP 2: Details ═══ -->
    <div id="cb4Step2" style="display:none;">

    <!-- Back -->
    <div style="margin-bottom:12px;">
      <button type="button" onclick="cb4GoStep1()" style="background:none;border:none;color:#6b7280;font-size:13px;font-weight:700;cursor:pointer;padding:4px 0;">← Back</button>
      <span style="font-size:12px;color:#9ca3af;margin-left:8px;" id="cb4Step2Label"></span>
    </div>

    <!-- Project toggle -->
    <div id="cb4RegularFields">
    <div class="cb4-fg">
      <label class="cb4-lbl">PROJECT BOOK</label>
      <div class="cb4-proj-row">
        <button type="button" class="cb4-proj-btn<?php echo $proj==='dishnet'?' sel':''; ?>" id="cb4ProjDN" onclick="cb4SetProj('dishnet')">DishNet Fiber & Starlink</button>
        <button type="button" class="cb4-proj-btn<?php echo $proj==='4g'?' sel':''; ?>" id="cb4Proj4G" onclick="cb4SetProj('4g')">DishNet 4G</button>
        <button type="button" class="cb4-proj-btn<?php echo $proj==='bluecard'?' sel':''; ?>" id="cb4ProjBC" onclick="cb4SetProj('bluecard')">BlueCARD</button>
      </div>
    </div>

    <!-- Date -->
    <div class="cb4-fg">
      <label class="cb4-lbl">DATE</label>
      <input type="date" class="cb4-inp" id="cb4Date" value="<?php echo date('Y-m-d'); ?>">
    </div>

    <!-- Amount -->
    <div class="cb4-fg" id="cb4AmtWrapOuter">
      <label class="cb4-lbl" id="cb4AmtLbl">AMOUNT (USD)</label>
      <div class="cb4-aw">
        <span class="cb4-as" id="cb4AmtSym">$</span>
        <input type="number" class="cb4-inp cb4-ai" id="cb4Amt" placeholder="0.00" step="0.01" min="0.01" oninput="cb4Update()">
      </div>
    </div>

    <!-- SSP Rate (shown only for SSP) -->
    <div class="cb4-fg" id="cb4RateWrap" style="display:none;">
      <label class="cb4-lbl">EXCHANGE RATE (SSP per $1)</label>
      <input type="number" class="cb4-inp" id="cb4Rate" placeholder="e.g. 5500" step="1" min="1" value="<?php echo (int)$xRate ?: ''; ?>" oninput="cb4CalcRate()">
      <div class="cb4-rate-calc" id="cb4RateCalc"></div>
    </div>

    <!-- Dynamic: Expense type (for Travel & Field, Local Purchase, Site Expense etc.) -->
    <div class="cb4-fg" id="cb4SiteWrap" style="display:none;">
      <label class="cb4-lbl">SITE NAME</label>
      <div style="position:relative;" id="cb4SitePickWrap">
        <input type="text" class="cb4-inp" id="cb4SiteName" placeholder="Type to search sites..." autocomplete="off"
               oninput="cb4SiteFilter(this.value)" onfocus="cb4SiteFilter(this.value);var _si=this;setTimeout(function(){_si.scrollIntoView({block:'center',behavior:'smooth'});},300);">
        <div class="cb4-site-drop" id="cb4SiteDrop">
          <?php
          // v4.9.10: Comprehensive site list — BookKeeper locations + cb_ledger history
          $_siteNames = [
              'Tomping Branch — JEDCO','Tomping Branch — M-Gurush',
              'City Mall Office','Munuki','Hai Saura','Wamo Site',
              'Kator New Site','Konyo Konyo / Yatco','Jebel Market',
              'Custom Market','Jabrona','Home / Office — JEDCO',
              'Tomping Branch Office','City Mall Office — Rent',
              'Tower GMSH','UAP Tower','Tower Nimule',
              'Guest House (Dishnet)','Gudele Medical — Server Room',
              'Shop — Advance Rent','UNMISS Accommodation',
              'JEDCO','SSEC (Govt Power)',
              'Generator — Fuel','Generator — Maintenance',
          ];
          foreach (['Site Power','Site Rent'] as $_sc) {
              foreach ($_catToPersons[$_sc] ?? [] as $_sp) {
                  $_sn = trim($_sp['name'] ?? '');
                  if (!$_sn || strlen($_sn) > 35 || ($_sp['src'] ?? '') === 'desc') continue;
                  if (!in_array($_sn, $_siteNames)) $_siteNames[] = $_sn;
              }
          }
          sort($_siteNames);
          $_siteNames = array_values(array_unique($_siteNames));
          foreach ($_siteNames as $_sn): ?>
          <div class="cb4-site-opt" data-v="<?= htmlspecialchars(strtolower($_sn)) ?>" onclick="cb4SitePick(this)"><?= htmlspecialchars($_sn) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Dynamic: Month (for Salary, Transport Allowance, etc.) -->
    <div class="cb4-fg" id="cb4MonthWrap" style="display:none;">
      <label class="cb4-lbl">MONTH</label>
      <input type="month" class="cb4-inp" id="cb4Month" value="<?php echo date('Y-m'); ?>">
    </div>

    <!-- Dynamic: Invoice / Receipt (for Receipt category) -->
    <div class="cb4-2col" id="cb4InvWrap" style="display:none;">
      <div class="cb4-fg"><label class="cb4-lbl">Invoice No.</label>
        <input type="text" class="cb4-inp" id="cb4InvRef" placeholder="INV012630"></div>
      <div class="cb4-fg"><label class="cb4-lbl">Receipt No.</label>
        <input type="text" class="cb4-inp" id="cb4RcptRef" placeholder="R-3049"></div>
    </div>

    <!-- Suggested persons for this category (auto-learned) -->
    <div class="cb4-suggest-strip" id="cb4SuggestStrip" style="display:none;">
      <div class="cb4-suggest-lbl" id="cb4SuggestLbl">👤 Frequently used for <span id="cb4SuggestCatName"></span></div>
      <div class="cb4-suggest-chips" id="cb4SuggestChips"></div>
    </div>

    <!-- Smart suggestion strip (appears when person selected and has history) -->
    <div class="cb4-smart-strip" id="cb4SmartStrip" style="display:none;">
      <div class="cb4-smart-lbl">⚡ Frequently used with this person</div>
      <div class="cb4-smart-chips" id="cb4SmartChips"></div>
    </div>

    <!-- Person / Staff (searchable) -->
    <div class="cb4-fg">
      <label class="cb4-lbl">PERSON / STAFF / SUPPLIER</label>
      <div class="cb4-person-wrap" id="cb4PersonWrap">
        <input type="text" class="cb4-person-inp" id="cb4Person" placeholder="Search staff or supplier name…"
               autocomplete="off" onfocus="cb4PersonOpen();var _pi=this;setTimeout(function(){_pi.scrollIntoView({block:'center',behavior:'smooth'});},300);" oninput="cb4PersonFilter(this.value)">
        <div class="cb4-person-drop" id="cb4PersonDrop">
          <?php foreach ($_allPersonNames as $pn):
            $pData = $_smartPersons[$pn] ?? ['total'=>0,'last'=>''];
            $pCnt  = (int)$pData['total'];
            $pLast = $pData['last'] ? date('M j', strtotime($pData['last'])) : '';
          ?>
          <div class="cb4-person-item" data-name="<?php echo htmlspecialchars($pn); ?>" onclick="cb4PersonPick(this)">
            <div>
              <span class="cb4-person-name"><?php echo htmlspecialchars($pn); ?></span>
              <?php if ($pLast): ?><span class="cb4-person-meta">&nbsp;· last <?php echo $pLast; ?></span><?php endif; ?>
            </div>
            <?php if ($pCnt): ?><span class="cb4-person-cnt"><?php echo $pCnt; ?> entries</span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Description -->
    <div class="cb4-fg">
      <label class="cb4-lbl">DESCRIPTION / PARTICULARS *</label>
      <input type="text" class="cb4-inp" id="cb4Desc" placeholder="e.g. Payment from Shalina Pharmacy — INV010451" required>
    </div>

    <!-- Info for disbursements -->
    <div class="cb4-info warn" id="cb4DisbInfo" style="display:none;">
      ⚠️ If cash given to staff with no receipt yet — set Validation to <strong>Pending</strong>. Settle later under Pending Receipts.
    </div>

    <?php if($isAdmin || $isAccountant): ?>
    <div class="cb4-2col">
      <div class="cb4-fg"><label class="cb4-lbl">Validation Ref</label>
        <input type="text" class="cb4-inp" id="cb4ValRef" placeholder="Voucher No-A0169"></div>
      <div class="cb4-fg"><label class="cb4-lbl">Validation Status</label>
        <select class="cb4-sel" id="cb4ValStatus">
          <?php foreach(CashbookService::VAL_STATUSES as $k=>$vs): ?>
          <option value="<?php echo $k; ?>"><?php echo htmlspecialchars($vs); ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <?php endif; ?>
    </div><!-- /cb4RegularFields -->

    <!-- v4.9.10: Exchange sub-form (outside RegularFields so it stays visible when regular fields hidden) -->
    <div id="cb4ExchWrap" style="display:none;">
      <div class="cb4-fg">
        <label class="cb4-lbl">EXCHANGE TYPE</label>
        <div class="cb4-dir-row" style="grid-template-columns:1fr 1fr;">
          <div class="cb4-dir-btn out sel" id="cb4ExchUSD2SSP" onclick="cb4SetExchType('usd_to_ssp')">
            <div class="cb4-dir-ic">💵→🇸🇸</div>
            <div class="cb4-dir-lbl" style="font-size:11px;">USD → SSP</div>
          </div>
          <div class="cb4-dir-btn in" id="cb4ExchSSP2USD" onclick="cb4SetExchType('ssp_to_usd')">
            <div class="cb4-dir-ic">🇸🇸→💵</div>
            <div class="cb4-dir-lbl" style="font-size:11px;">SSP → USD</div>
          </div>
        </div>
      </div>
      <div class="cb4-fg">
        <label class="cb4-lbl">PROJECT BOOK</label>
        <div class="cb4-proj-row">
          <button type="button" class="cb4-proj-btn<?php echo $proj==='dishnet'?' sel':''; ?>" onclick="cb4SetProj('dishnet')">DishNet Fiber & Starlink</button>
          <button type="button" class="cb4-proj-btn<?php echo $proj==='4g'?' sel':''; ?>" onclick="cb4SetProj('4g')">DishNet 4G</button>
          <button type="button" class="cb4-proj-btn<?php echo $proj==='bluecard'?' sel':''; ?>" onclick="cb4SetProj('bluecard')">BlueCARD</button>
        </div>
      </div>
      <div class="cb4-fg">
        <label class="cb4-lbl">DATE</label>
        <input type="date" class="cb4-inp" id="cb4ExchDate" value="<?php echo date('Y-m-d'); ?>">
      </div>
      <div class="cb4-fg">
        <label class="cb4-lbl" id="cb4ExchAmtLbl">USD AMOUNT (giving out)</label>
        <div class="cb4-aw"><span class="cb4-as">$</span>
          <input type="number" class="cb4-inp cb4-ai" id="cb4ExchAmt" placeholder="0.00" step="0.01" min="0.01" oninput="cb4ExchCalc()">
        </div>
      </div>
      <div class="cb4-fg">
        <label class="cb4-lbl">EXCHANGE RATE (SSP per $1)</label>
        <input type="number" class="cb4-inp" id="cb4ExchRate" placeholder="e.g. 5700" step="1" min="1" value="<?php echo (int)$xRate ?: ''; ?>" oninput="cb4ExchCalc()">
      </div>
      <div id="cb4ExchCalcResult" style="display:none;background:var(--color-background-success, #f0fdf4);color:var(--color-text-success, #15803d);padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:12px;"></div>
      <div class="cb4-fg">
        <label class="cb4-lbl">BY (who exchanged)</label>
        <input type="text" class="cb4-inp" id="cb4ExchPerson" placeholder="e.g. Diko, Rupesh, BBC..." autocomplete="off" list="cb4ExchPersonList">
        <datalist id="cb4ExchPersonList">
          <?php foreach ($_catToPersons['Exchange'] ?? [] as $_ep): ?>
          <option value="<?= htmlspecialchars($_ep['name']) ?>">
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="cb4-fg">
        <label class="cb4-lbl">NOTE <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
        <input type="text" class="cb4-inp" id="cb4ExchNote" placeholder="e.g. recd from client, for airtime...">
      </div>
      <div class="cb4-fg" style="background:#f8fafc;padding:10px 12px;border-radius:8px;border:1px solid #e2e8f0;">
        <div style="font-size:10px;font-weight:800;color:#94a3b8;letter-spacing:.5px;margin-bottom:4px;">AUTO-GENERATED DESCRIPTION</div>
        <div id="cb4ExchDescPreview" style="font-size:12px;color:#334155;font-weight:500;">—</div>
      </div>
    </div>

    </div><!-- /Step 2 -->

  </div><!-- /mbody -->
  </div><!-- /scroll -->

  <!-- Sticky footer (Step 2 only) -->
  <div class="cb4-mfooter" id="cb4Footer" style="display:none;">
    <button class="cb4-cancel" onclick="cb4GoStep1()">← Back</button>
    <button class="cb4-save" id="cb4SaveBtn" onclick="cb4Submit()" disabled>Save Entry</button>
  </div>

  <!-- Hidden form for actual POST -->
  <form id="cb4Form" method="POST" style="display:none;">
<?= csrfField() ?>
    <input type="hidden" name="cb_action" value="add_entry">
    <input type="hidden" name="direction" id="cb4fDir">
    <input type="hidden" name="currency" id="cb4fCurr">
    <input type="hidden" name="project" id="cb4fProj">
    <input type="hidden" name="date" id="cb4fDate">
    <input type="hidden" name="amount" id="cb4fAmt">
    <input type="hidden" name="ssp_rate" id="cb4fRate">
    <input type="hidden" name="category" id="cb4fCat">
    <input type="hidden" name="category_raw" id="cb4fCatRaw">
    <input type="hidden" name="person" id="cb4fPerson">
    <input type="hidden" name="description" id="cb4fDesc">
    <input type="hidden" name="validation_ref" id="cb4fValRef">
    <input type="hidden" name="validation_status" id="cb4fValStatus">
    <input type="hidden" name="inv_ref" id="cb4fInvRef">
    <input type="hidden" name="rcpt_ref" id="cb4fRcptRef">
    <input type="hidden" name="pay_month" id="cb4fMonth">
    <input type="hidden" name="exchange_type" id="cb4fExchType">
    <input type="hidden" name="exch_ssp_amount" id="cb4fExchSSP">
  </form>

</div><!-- /cb4-mb -->
</div><!-- /cb4Modal -->

<!-- ═══════════ SETTLE MODAL ═══════════ -->
<div class="cbv2-so" id="cbv2SettleO">
<div class="cbv2-sbox">
  <div style="font-size:15px;font-weight:900;margin-bottom:4px;" id="cbv2ST">Settle Disbursement</div>
  <div style="font-size:12px;color:#64748b;margin-bottom:16px;" id="cbv2SS"></div>
  <form method="POST">
<?= csrfField() ?>
    <input type="hidden" name="cb_action" value="settle_disb">
    <input type="hidden" name="entry_id" id="cbv2SID">
    <div class="cbv2-fg"><label class="cbv2-lbl">Voucher Number</label>
      <input type="text" name="voucher_no" class="cbv2-inp" placeholder="Voucher No-A0399" required></div>
    <div class="cbv2-fg"><label class="cbv2-lbl">Change Returned USD (0 if none)</label>
      <div class="cbv2-aw"><span class="cbv2-as">$</span>
        <input type="number" name="return_amount" class="cbv2-inp" style="padding-left:24px;" value="0" min="0" step="0.01"></div>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
      <button type="button" class="cbv2-bcancel" onclick="cbv2CS()">Cancel</button>
      <button type="submit" class="cbv2-bgreen">&#10003; Mark Settled</button>
    </div>
  </form>
</div>
</div>

<script>
var _d2='in';
function cbv2F(k,v){var u=new URL(window.location.href);u.searchParams.set(k,v);u.searchParams.set('cb_page','1');window.location.href=u.toString();}
var _dt;function cbv2FD(k,v){clearTimeout(_dt);_dt=setTimeout(function(){cbv2F(k,v);},600);}
// ═══ CB4 — Admin Entry Modal Logic ═══════════════════════════════════════
var _cb4Curr = 'USD', _cb4Dir = '', _cb4Cat = '', _cb4Proj = '<?php echo $proj; ?>';

// ── Smart person history (auto-learned from past entries) ───────────────
var _cb4PersonHistory = <?php echo json_encode($_smartPersons, JSON_UNESCAPED_UNICODE); ?>;
// ── Reverse map: category → frequent persons ────────────────────────────
var _cb4CatToPersons = <?php echo json_encode($_catToPersons, JSON_UNESCAPED_UNICODE); ?>;

// ── Category definitions — loaded from API (admin-configurable) ──────────
var _cb4CatsIN = [];
var _cb4CatsOUT_people = [];
var _cb4CatsOUT_ops = [];
var _cb4CatsOUT_fin = [];
// v4.9.10: BookKeeper account names + custom categories for "Other..." search
var _cb4BkAccounts = [];
var _cb4CustomCats = [];

// Load categories from server (cb_categories.json via API)
var _cb4CatsReady = false;
(function loadCbCategories() {
  fetch('?page=api&action=cb_categories', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(resp) {
      var d = resp.data || resp;
      _cb4CatsIN          = d.in          || [];
      _cb4CatsOUT_people  = d.out_people  || [];
      _cb4CatsOUT_ops     = d.out_ops     || [];
      _cb4CatsOUT_fin     = d.out_fin     || [];
      _cb4BkAccounts      = d.bk_accounts      || [];
      _cb4CustomCats      = d.custom_categories || [];
      // If API returned empty (shouldn't happen), load fallbacks
      if (!_cb4CatsIN.length && !_cb4CatsOUT_people.length) { throw new Error('empty'); }
      _cb4CatsReady = true;
    })
    .catch(function(){
      // Fallback to built-in defaults if API fails
      _cb4CatsIN = [
        {id:'Receipt',ic:'💰',lbl:'Receipt'},
        {id:'Bank Transfer',ic:'🏦',lbl:'Bank Transfer'},{id:'Loan Received',ic:'💵',lbl:'Loan Received'},
        {id:'Refund',ic:'🔙',lbl:'Refund'},{id:'Opening Balance',ic:'📊',lbl:'Opening Bal'},
        {id:'Misc Income',ic:'📦',lbl:'Misc Income'}
      ];
      _cb4CatsOUT_people = [
        {id:'Salary',ic:'💼',lbl:'Salary'},{id:'Transport Allowance',ic:'🚗',lbl:'Transport'},
        {id:'Food Allowance',ic:'🍽️',lbl:'Food Allow.'},{id:'Commission',ic:'💵',lbl:'Commission'},
        {id:'Bonus',ic:'💰',lbl:'Bonus'},{id:'Employee Benefit',ic:'👤',lbl:'Emp. Benefit'},
        {id:'Staff Advance',ic:'💸',lbl:'Staff Advance'},
        {id:'SSP Advance',ic:'🇸🇸',lbl:'SSP Advance'}
      ];
      _cb4CatsOUT_ops = [
        {id:'Travel & Field',ic:'🏗️',lbl:'Travel & Field'},{id:'Local Purchase',ic:'🛒',lbl:'Local Purchase'},
        {id:'Site Power',ic:'⚡',lbl:'Site Power'},{id:'Airtime',ic:'📱',lbl:'Airtime'},
        {id:'Bandwidth',ic:'📡',lbl:'Bandwidth'},
        {id:'Customer Refund',ic:'↩️',lbl:'Cust. Refund'},{id:'Customer Commission',ic:'🤝',lbl:'Cust. Commission'}
      ];
      _cb4CatsOUT_fin = [
        {id:'Tax',ic:'💸',lbl:'Tax'},{id:'Loan Given',ic:'💵',lbl:'Loan Given'},
        {id:'Bank Transfer',ic:'🏦',lbl:'Bank Transfer'},{id:'Misc Expense',ic:'📦',lbl:'Misc Expense'}
      ];
      _cb4CatsReady = true;
    });
})();

// ── Open / Close ────────────────────────────────────────────────────────
function cb4Open(dir, cat) {
  if (!_cb4CatsReady) {
    // Categories still loading — retry in 200ms (max 5 retries = 1s)
    if (!cb4Open._retries) cb4Open._retries = 0;
    if (cb4Open._retries++ < 5) { setTimeout(function(){ cb4Open(dir, cat); }, 200); return; }
  }
  cb4Open._retries = 0;
  cb4Reset();
  document.getElementById('cb4Modal').classList.add('open');
  document.body.style.overflow = 'hidden';
  if (dir) cb4SetDir(dir);
  if (cat) cb4SetCat(cat);
}
function cb4Close() {
  document.getElementById('cb4Modal').classList.remove('open');
  document.body.style.overflow = '';
}
// Backward compat alias
function cbv2OpenModal(dir, cat) { cb4Open(dir, cat); }
function cbv2CloseModal() { cb4Close(); }

// ── Auto-open from URL param ────────────────────────────────────────────
(function(){
  var p=new URLSearchParams(window.location.search);
  var d=p.get('cb_open');
  var cat=p.get('cb_cat_open')||'';
  if(d==='in'||d==='out'){
    setTimeout(function(){ cb4Open(d, cat||null); },150);
  }
})();

// ── Reset ───────────────────────────────────────────────────────────────
function cb4Reset() {
  _cb4Curr='USD'; _cb4Dir=''; _cb4Cat=''; _cb4ActiveGrp='out_people';
  var mh=document.getElementById('cb4MH');
  mh.className='cb4-mh neutral';
  document.getElementById('cb4MTitle').textContent='Add Entry';
  document.getElementById('cb4MSub').textContent='Select direction to begin';
  document.getElementById('cb4CatSection').style.display='none';
  document.getElementById('cb4NextWrap').style.display='none';
  document.getElementById('cb4Step1').style.display='';
  document.getElementById('cb4Step2').style.display='none';
  document.getElementById('cb4Footer').style.display='none';
  document.getElementById('cb4PillUSD').classList.add('sel');
  document.getElementById('cb4PillSSP').classList.remove('sel');
  document.getElementById('cb4DirIn').classList.remove('sel');
  document.getElementById('cb4DirOut').classList.remove('sel');
  document.getElementById('cb4NextBtn').disabled=true;
  // Reset step 2 fields
  document.getElementById('cb4Amt').value='';
  document.getElementById('cb4Desc').value='';
  document.getElementById('cb4Person').value='';
  document.getElementById('cb4Date').value='<?php echo date("Y-m-d"); ?>';
  if(document.getElementById('cb4ValRef')) document.getElementById('cb4ValRef').value='';
  if(document.getElementById('cb4ValStatus')) document.getElementById('cb4ValStatus').value='na';
  document.getElementById('cb4RateWrap').style.display='none';
  document.getElementById('cb4Rate').value='';
  document.getElementById('cb4SiteName').value='';
  document.getElementById('cb4InvRef').value='';
  document.getElementById('cb4RcptRef').value='';
  document.getElementById('cb4Month').value='<?php echo date("Y-m"); ?>';
  document.getElementById('cb4SaveBtn').disabled=true;
  document.getElementById('cb4SaveBtn').textContent='Save Entry';
  // Hide all dynamic fields
  document.getElementById('cb4SiteWrap').style.display='none';
  document.getElementById('cb4MonthWrap').style.display='none';
  document.getElementById('cb4InvWrap').style.display='none';
  document.getElementById('cb4DisbInfo').style.display='none';
  // Reset search & smart fields
  document.getElementById('cb4CatSearch').value='';
  document.getElementById('cb4SmartStrip').style.display='none';
  document.getElementById('cb4SmartChips').innerHTML='';
  document.getElementById('cb4SuggestStrip').style.display='none';
  document.getElementById('cb4SuggestChips').innerHTML='';
  cb4PersonClose();
}

// ── Currency pill ───────────────────────────────────────────────────────
function cb4SetCurr(c) {
  _cb4Curr = c;
  document.getElementById('cb4PillUSD').classList.toggle('sel', c==='USD');
  document.getElementById('cb4PillSSP').classList.toggle('sel', c==='SSP');
  document.getElementById('cb4AmtLbl').textContent = c==='SSP' ? 'AMOUNT (SSP)' : 'AMOUNT (USD)';
  document.getElementById('cb4AmtSym').textContent = c==='SSP' ? '' : '$';
  document.getElementById('cb4RateWrap').style.display = c==='SSP' ? '' : 'none';
  if (_cb4Dir) { cb4RenderCats(); _cb4Cat=''; document.getElementById('cb4NextWrap').style.display='none'; }
  cb4UpdateHeader();
}

// ── Direction ───────────────────────────────────────────────────────────
function cb4SetDir(dir) {
  _cb4Dir = dir; _cb4Cat = '';
  document.getElementById('cb4DirIn').classList.toggle('sel', dir==='in');
  document.getElementById('cb4DirOut').classList.toggle('sel', dir==='out');
  document.getElementById('cb4DirExch').classList.toggle('sel', dir==='exchange');
  // v4.9.10: Exchange skips category — goes straight to exchange form
  if (dir === 'exchange') {
    document.getElementById('cb4CatSection').style.display = 'none';
    document.getElementById('cb4NextWrap').style.display = 'none';
    _cb4Cat = 'Exchange';
    cb4UpdateHeader();
    // Go straight to Step 2 with exchange form visible
    setTimeout(function() { cb4GoStep2(); cb4ShowExchangeForm(); }, 50);
    return;
  }
  document.getElementById('cb4ExchWrap').style.display = 'none';
  document.getElementById('cb4CatSection').style.display = 'block';
  document.getElementById('cb4CatSearch').value = '';
  document.getElementById('cb4NextWrap').style.display='none';
  document.getElementById('cb4NextBtn').disabled=true;
  cb4RenderCats();
  cb4UpdateHeader();
  setTimeout(function() {
    var sec = document.getElementById('cb4CatSection');
    if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, 100);
}

// ── Header color ────────────────────────────────────────────────────────
function cb4UpdateHeader() {
  var mh=document.getElementById('cb4MH');
  if(!_cb4Dir) { mh.className='cb4-mh neutral'; document.getElementById('cb4MTitle').textContent='Add Entry'; document.getElementById('cb4MSub').textContent='Select direction to begin'; return; }
  if (_cb4Dir === 'exchange') {
    mh.className = 'cb4-mh neutral';
    document.getElementById('cb4MTitle').textContent = 'Exchange · USD ↔ SSP';
    document.getElementById('cb4MSub').textContent = 'Convert between currencies';
    return;
  }
  mh.className = 'cb4-mh ' + (_cb4Dir==='in'?'in':'out');
  var dirLabel = _cb4Dir==='in' ? 'Cash IN' : 'Cash OUT';
  var curLabel = _cb4Curr==='SSP' ? 'SSP' : 'USD';
  document.getElementById('cb4MTitle').textContent = dirLabel + ' · ' + curLabel;
  document.getElementById('cb4MSub').textContent = _cb4Cat ? _cb4Cat : 'Select a category below';
}

// ── Render category chips ───────────────────────────────────────────────
// v4.9.10: Cash OUT uses tabbed groups (Staff|Sites|Business|Operations|Finance)
var _cb4ActiveGrp = 'out_people';  // default tab for Cash OUT
var _cb4OutGroups = [
  {key:'out_people', lbl:'Staff',      dot:'#3b82f6'},
  {key:'out_sites',  lbl:'Sites',      dot:'#f59e0b'},
  {key:'out_biz',    lbl:'Business',   dot:'#8b5cf6'},
  {key:'out_ops',    lbl:'Ops',        dot:'#10b981'},
  {key:'out_fin',    lbl:'Finance',    dot:'#6b7280'},
];
function _cb4GrpCats(key) {
  switch(key) {
    case 'out_people':
      return (_cb4CatsOUT_people||[]).filter(function(c){ return c.id!=='Partner Remuneration'; });
    case 'out_sites':
      return (_cb4CatsOUT_ops||[]).filter(function(c){ return c.id==='Site Power'||c.id==='Site Rent'; });
    case 'out_biz':
      var bizIds = ['Govt Fees','Legal Fees','Vehicle','Advertising','Renewal Charges','Partner Remuneration'];
      var found = [];
      (_cb4CatsOUT_fin||[]).concat(_cb4CatsOUT_ops||[]).concat(_cb4CatsOUT_people||[]).forEach(function(c){
        if (bizIds.indexOf(c.id) >= 0) found.push(c);
      });
      // Dedupe by id
      var seen = {};
      found = found.filter(function(c){ if(seen[c.id]) return false; seen[c.id]=true; return true; });
      // JS fallback: if saved config was old and PHP merge didn't reach client, build from defaults
      if (found.length === 0) {
        found = [
          {id:'Govt Fees',ic:'🏛️',lbl:'Govt Fees'},
          {id:'Legal Fees',ic:'⚖️',lbl:'Legal Fees'},
          {id:'Vehicle',ic:'🚗',lbl:'Vehicle'},
          {id:'Advertising',ic:'📢',lbl:'Advertising'},
          {id:'Renewal Charges',ic:'🔄',lbl:'Renewal Chgs'},
          {id:'Partner Remuneration',ic:'🤝',lbl:'Partner Rem.'}
        ];
      }
      return found;
    case 'out_ops':
      var skipOps = ['Site Power','Site Rent','Vehicle','Advertising','Renewal Charges'];
      return (_cb4CatsOUT_ops||[]).filter(function(c){ return skipOps.indexOf(c.id) < 0; });
    case 'out_fin':
      var skipFin = ['Govt Fees','Legal Fees'];
      return (_cb4CatsOUT_fin||[]).filter(function(c){ return skipFin.indexOf(c.id) < 0; });
    default: return [];
  }
}
function cb4RenderCats() {
  var grid = document.getElementById('cb4CatGrid');
  grid.innerHTML = '';
  if (_cb4Dir === 'in') {
    // Cash IN: flat grid, no tabs
    grid.innerHTML += cb4BuildChipGroup('', _cb4CatsIN);
  } else {
    // Cash OUT: tab bar + one group at a time
    var tabHtml = '<div class="cb4-grp-tabs">';
    _cb4OutGroups.forEach(function(g) {
      var sel = g.key === _cb4ActiveGrp ? ' sel' : '';
      tabHtml += '<button class="cb4-grp-tab'+sel+'" onclick="cb4SwitchGrp(\''+g.key+'\')">'
        + '<span class="cb4-grp-dot" style="background:'+g.dot+'"></span>' + g.lbl + '</button>';
    });
    tabHtml += '</div>';
    grid.innerHTML += tabHtml;
    // Render only the active group's tiles
    grid.innerHTML += cb4BuildChipGroup('', _cb4GrpCats(_cb4ActiveGrp));
  }
  // "Other..." tile always at bottom
  grid.innerHTML += '<div class="cb4-cats" style="margin-top:8px;grid-template-columns:1fr;">'
    + '<div class="cb4-cat cb4-cat-other" data-cat="__other__" onclick="cb4ShowOtherSearch()">'
    + '<div class="cb4-cat-ic">🔎</div><div class="cb4-cat-lbl">Other... (search BookKeeper + history)</div></div></div>';
  // Hide the Other search panel initially
  document.getElementById('cb4OtherPanel').style.display = 'none';
}
function cb4SwitchGrp(key) {
  _cb4ActiveGrp = key;
  cb4RenderCats();
  // Re-apply search filter if user has typed something
  var q = document.getElementById('cb4CatSearch').value;
  if (q) cb4FilterCats(q);
}
// v4.9.10: Custom site search dropdown
function cb4SiteFilter(q) {
  var drop = document.getElementById('cb4SiteDrop');
  var opts = drop.querySelectorAll('.cb4-site-opt');
  q = q.toLowerCase().trim();
  var visible = 0;
  opts.forEach(function(el) {
    var v = el.getAttribute('data-v') || '';
    if (!q || v.indexOf(q) >= 0) {
      el.classList.remove('hide');
      // Highlight match
      var orig = el.textContent;
      if (q) {
        var idx = orig.toLowerCase().indexOf(q);
        if (idx >= 0) {
          el.innerHTML = orig.substring(0, idx) + '<mark>' + orig.substring(idx, idx + q.length) + '</mark>' + orig.substring(idx + q.length);
        }
      } else {
        el.innerHTML = orig;
      }
      visible++;
    } else {
      el.classList.add('hide');
    }
  });
  drop.classList.toggle('open', visible > 0);
}
function cb4SitePick(el) {
  var text = el.textContent || el.innerText;
  document.getElementById('cb4SiteName').value = text.trim();
  document.getElementById('cb4SiteDrop').classList.remove('open');
  cb4Update();
}
// Close site dropdown on outside click
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('cb4SitePickWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('cb4SiteDrop').classList.remove('open');
  }
});
function cb4BuildChipGroup(title, cats) {
  var html = '';
  if (title) html += '<div class="cb4-cat-hdr">'+title+'</div>';
  html += '<div class="cb4-cats">';
  cats.forEach(function(c) {
    html += '<div class="cb4-cat" data-cat="'+c.id+'" onclick="cb4SetCat(\''+c.id.replace(/'/g,"\\'")+'\')">'
          + '<div class="cb4-cat-ic">'+c.ic+'</div><div class="cb4-cat-lbl">'+c.lbl+'</div></div>';
  });
  html += '</div>';
  return html;
}

// ── Select category ─────────────────────────────────────────────────────
function cb4SetCat(cat) {
  _cb4Cat = cat;
  document.querySelectorAll('.cb4-cat').forEach(function(el) {
    el.classList.toggle('sel', el.getAttribute('data-cat') === cat);
  });
  document.getElementById('cb4NextWrap').style.display='block';
  document.getElementById('cb4NextBtn').disabled=false;
  document.getElementById('cb4NextBtn').textContent='Next → ' + cat;
  cb4UpdateHeader();
  // Auto-advance to Step 2 after brief visual feedback (mobile-friendly: no need to find Next button)
  setTimeout(function() { cb4GoStep2(); }, 180);
}

// ── Step navigation ─────────────────────────────────────────────────────
function cb4GoStep2() {
  if (!_cb4Cat) return;
  document.getElementById('cb4Step1').style.display='none';
  document.getElementById('cb4Step2').style.display='';
  document.getElementById('cb4Footer').style.display='flex';
  // Restore regular fields, hide exchange form (unless Exchange mode)
  if (_cb4Dir !== 'exchange') {
    document.getElementById('cb4RegularFields').style.display = '';
    document.getElementById('cb4ExchWrap').style.display = 'none';
  }
  document.getElementById('cb4Step2Label').textContent = (_cb4Dir==='in'?'IN':'OUT') + ' · ' + _cb4Curr + ' · ' + _cb4Cat;
  // Show/hide category-specific fields
  var isSalary = ['Salary','Transport Allowance','Food Allowance','Bonus','Employee Benefit','Partner Remuneration'].indexOf(_cb4Cat)!==-1;
  var isDisb = ['Travel & Field','Local Purchase','Site Expense','Vehicle'].indexOf(_cb4Cat)!==-1;
  var isSite = ['Site Power','Site Rent'].indexOf(_cb4Cat)!==-1;
  var isReceipt = _cb4Cat==='Receipt';
  document.getElementById('cb4MonthWrap').style.display = isSalary ? '' : 'none';
  document.getElementById('cb4DisbInfo').style.display = isDisb ? '' : 'none';
  document.getElementById('cb4SiteWrap').style.display = isSite ? '' : 'none';
  document.getElementById('cb4InvWrap').style.display = isReceipt ? '' : 'none';
  // Auto-set validation to pending for disbursements
  if (isDisb && document.getElementById('cb4ValStatus')) {
    document.getElementById('cb4ValStatus').value = 'pending';
  }
  cb4Update();
  // Show suggested persons for this category (reverse lookup)
  cb4ShowSuggestedPersons(_cb4Cat);
  cb4ReorderPersonDrop(_cb4Cat);
  // Show smart suggestions if person already has a value
  var personVal = document.getElementById('cb4Person').value.trim();
  if (personVal) cb4ShowSmartSuggestions(personVal);
}
function cb4GoStep1() {
  document.getElementById('cb4Step1').style.display='';
  document.getElementById('cb4Step2').style.display='none';
  document.getElementById('cb4Footer').style.display='none';
}

// ── Project toggle ──────────────────────────────────────────────────────
function cb4SetProj(p) {
  _cb4Proj = p;
  document.getElementById('cb4ProjDN').classList.toggle('sel', p==='dishnet');
  document.getElementById('cb4Proj4G').classList.toggle('sel', p==='4g');
  document.getElementById('cb4ProjBC').classList.toggle('sel', p==='bluecard');
}

// ── Rate calculator ─────────────────────────────────────────────────────
function cb4CalcRate() {
  var ssp = parseFloat(document.getElementById('cb4Amt').value) || 0;
  var rate = parseFloat(document.getElementById('cb4Rate').value) || 0;
  var calc = document.getElementById('cb4RateCalc');
  if (ssp>0 && rate>0) { calc.textContent = '≈ $'+(ssp/rate).toFixed(2)+' USD equivalent'; }
  else { calc.textContent = ''; }
}

// ── Update save button ──────────────────────────────────────────────────
function cb4Update() {
  var amt = parseFloat(document.getElementById('cb4Amt').value) || 0;
  var desc = document.getElementById('cb4Desc').value.trim();
  var btn = document.getElementById('cb4SaveBtn');
  var ready = amt > 0;
  btn.disabled = !ready;
  if (ready) {
    var sym = _cb4Curr==='SSP' ? '' : '$';
    var sfx = _cb4Curr==='SSP' ? ' SSP' : '';
    var disp = sym + (amt < 1000 ? amt.toFixed(_cb4Curr==='SSP'?0:2) : Math.round(amt).toLocaleString()) + sfx;
    var labels = {
      'Receipt':'Save Receipt','Exchange':'Save Exchange','Salary':'Save Salary',
      'Travel & Field':'Save Disbursement','Local Purchase':'Save Purchase',
      'Site Power':'Save Site Power','Site Rent':'Save Site Rent',
      'Govt Fees':'Save Govt Fee','Legal Fees':'Save Legal Fee',
      'Vehicle':'Save Vehicle Exp','Partner Remuneration':'Save Remuneration',
      'Advertising':'Save Ad Expense','Renewal Charges':'Save Renewal'
    };
    btn.textContent = (labels[_cb4Cat] || 'Save Entry') + ' · ' + disp;
  } else {
    btn.textContent = 'Save Entry';
  }
}

// ── Exchange flow ──────────────────────────────────────────────────────
var _cb4ExchType = 'usd_to_ssp'; // default: USD leaves, SSP arrives

function cb4ShowExchangeForm() {
  // Hide all regular Step 2 fields, show exchange-specific form
  document.getElementById('cb4RegularFields').style.display = 'none';
  document.getElementById('cb4ExchWrap').style.display = '';
  document.getElementById('cb4Step2Label').textContent = 'Exchange · USD ↔ SSP';
  // Reset exchange form
  _cb4ExchType = 'usd_to_ssp';
  cb4SetExchType('usd_to_ssp');
  document.getElementById('cb4ExchAmt').value = '';
  document.getElementById('cb4ExchPerson').value = '';
  document.getElementById('cb4ExchNote').value = '';
  cb4ExchCalc();
  // Show footer
  document.getElementById('cb4Footer').style.display = 'flex';
  cb4ExchUpdateSave();
}

function cb4SetExchType(type) {
  _cb4ExchType = type;
  var u2s = document.getElementById('cb4ExchUSD2SSP');
  var s2u = document.getElementById('cb4ExchSSP2USD');
  u2s.classList.toggle('sel', type === 'usd_to_ssp');
  s2u.classList.toggle('sel', type === 'ssp_to_usd');
  // Update label
  document.getElementById('cb4ExchAmtLbl').textContent = type === 'usd_to_ssp' ? 'USD AMOUNT (giving out)' : 'USD AMOUNT (receiving)';
  cb4ExchCalc();
}

function cb4ExchCalc() {
  var amt  = parseFloat(document.getElementById('cb4ExchAmt').value) || 0;
  var rate = parseFloat(document.getElementById('cb4ExchRate').value) || 0;
  var calc = document.getElementById('cb4ExchCalcResult');
  var person = document.getElementById('cb4ExchPerson').value.trim();
  var note   = document.getElementById('cb4ExchNote').value.trim();

  if (amt > 0 && rate > 0) {
    var ssp = Math.round(amt * rate);
    if (_cb4ExchType === 'usd_to_ssp') {
      calc.textContent = 'SSP received: ' + ssp.toLocaleString() + ' SSP';
    } else {
      calc.textContent = 'SSP given: ' + ssp.toLocaleString() + ' SSP';
    }
    calc.style.display = '';
  } else {
    calc.style.display = 'none';
  }

  // Auto-generate description preview
  var preview = document.getElementById('cb4ExchDescPreview');
  if (amt > 0 && rate > 0) {
    var desc = _cb4ExchType === 'usd_to_ssp'
      ? 'Exchange USD to SSP (' + amt + '@' + rate + ')'
      : 'Exchange SSP to USD (' + amt + '@' + rate + ')';
    if (person) desc += ' By ' + person;
    if (note) desc += ' - ' + note;
    desc += ' [' + (new Date().toISOString().substring(0,7).replace('-','-')) + ']';
    preview.textContent = desc;
  } else {
    preview.textContent = '—';
  }

  cb4ExchUpdateSave();
}

function cb4ExchUpdateSave() {
  var amt  = parseFloat(document.getElementById('cb4ExchAmt').value) || 0;
  var rate = parseFloat(document.getElementById('cb4ExchRate').value) || 0;
  var btn  = document.getElementById('cb4SaveBtn');
  btn.disabled = !(amt > 0 && rate > 0);
  if (amt > 0 && rate > 0) {
    var ssp = Math.round(amt * rate);
    btn.textContent = 'Save Exchange · $' + amt.toFixed(2) + ' ↔ ' + ssp.toLocaleString() + ' SSP';
  } else {
    btn.textContent = 'Save Exchange';
  }
}

// ── Submit ──────────────────────────────────────────────────────────────
function cb4Submit() {
  // v4.9.10: Exchange has its own submit path
  if (_cb4Dir === 'exchange') {
    var amt  = parseFloat(document.getElementById('cb4ExchAmt').value) || 0;
    var rate = parseFloat(document.getElementById('cb4ExchRate').value) || 0;
    if (amt <= 0 || rate <= 0) { alert('Please enter amount and rate.'); return; }
    var ssp    = Math.round(amt * rate);
    var person = document.getElementById('cb4ExchPerson').value.trim();
    var note   = document.getElementById('cb4ExchNote').value.trim();
    // Auto-generate description matching Rupesh's Excel pattern
    var desc = _cb4ExchType === 'usd_to_ssp'
      ? 'Exchange USD to SSP (' + amt + '@' + rate + ')'
      : 'Exchange SSP to USD (' + amt + '@' + rate + ')';
    if (person) desc += ' By ' + person;
    if (note) desc += ' - ' + note;
    desc += ' [' + (new Date().toISOString().substring(0,7)) + ']';
    // Direction: USD→SSP = out (USD leaves), SSP→USD = in (USD arrives)
    var dir = _cb4ExchType === 'usd_to_ssp' ? 'out' : 'in';
    // Fill hidden form
    document.getElementById('cb4fDir').value       = dir;
    document.getElementById('cb4fCurr').value      = 'USD';
    document.getElementById('cb4fProj').value      = _cb4Proj;
    document.getElementById('cb4fDate').value      = document.getElementById('cb4ExchDate').value || document.getElementById('cb4Date').value;
    document.getElementById('cb4fAmt').value        = amt;
    document.getElementById('cb4fRate').value       = rate;
    document.getElementById('cb4fCat').value        = 'Exchange';
    document.getElementById('cb4fCatRaw').value     = 'Exchange';
    document.getElementById('cb4fPerson').value     = person;
    document.getElementById('cb4fDesc').value       = desc;
    document.getElementById('cb4fValRef').value     = '';
    document.getElementById('cb4fValStatus').value  = 'done';
    document.getElementById('cb4fInvRef').value     = '';
    document.getElementById('cb4fRcptRef').value    = '';
    document.getElementById('cb4fMonth').value      = '';
    document.getElementById('cb4fExchType').value   = _cb4ExchType;
    document.getElementById('cb4fExchSSP').value    = ssp;
    document.getElementById('cb4Form').submit();
    return;
  }

  var amt = parseFloat(document.getElementById('cb4Amt').value) || 0;
  if (amt <= 0) { alert('Please enter an amount.'); return; }

  // v4.11.3: SSP Advance / Staff Advance MUST have a person so the auto-link
  // chain can write the matching IN to the staff cashbook.
  var _advanceCats = ['SSP Advance', 'Staff Advance'];
  if (_cb4Dir === 'out' && _advanceCats.indexOf(_cb4Cat) !== -1) {
    var _personVal = document.getElementById('cb4Person').value.trim();
    if (!_personVal) {
      alert('⚠️ Staff name is required for ' + _cb4Cat + '.\n\nWithout a name the advance will NOT appear in the staff cashbook.');
      document.getElementById('cb4Person').focus();
      return;
    }
  }

  // Fill hidden form fields
  document.getElementById('cb4fDir').value = _cb4Dir;
  document.getElementById('cb4fCurr').value = _cb4Curr;
  document.getElementById('cb4fProj').value = _cb4Proj;
  document.getElementById('cb4fDate').value = document.getElementById('cb4Date').value;
  document.getElementById('cb4fAmt').value = amt;
  document.getElementById('cb4fRate').value = _cb4Curr==='SSP' ? (document.getElementById('cb4Rate').value || '0') : '';
  document.getElementById('cb4fCat').value = _cb4Cat;
  // For Site Power/Site Rent, use site name as category_raw
  var siteVal = document.getElementById('cb4SiteName').value.trim();
  document.getElementById('cb4fCatRaw').value = siteVal ? siteVal : _cb4Cat;
  document.getElementById('cb4fPerson').value = document.getElementById('cb4Person').value.trim();
  document.getElementById('cb4fDesc').value = document.getElementById('cb4Desc').value.trim();
  document.getElementById('cb4fValRef').value = document.getElementById('cb4ValRef') ? document.getElementById('cb4ValRef').value.trim() : '';
  document.getElementById('cb4fValStatus').value = document.getElementById('cb4ValStatus') ? document.getElementById('cb4ValStatus').value : 'na';
  document.getElementById('cb4fInvRef').value = document.getElementById('cb4InvRef') ? document.getElementById('cb4InvRef').value : '';
  document.getElementById('cb4fRcptRef').value = document.getElementById('cb4RcptRef') ? document.getElementById('cb4RcptRef').value : '';
  document.getElementById('cb4fMonth').value = document.getElementById('cb4Month') ? document.getElementById('cb4Month').value : '';

  document.getElementById('cb4Form').submit();
}

// ── Wire up input events ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  var ai=document.getElementById('cb4Amt');
  if(ai) { ai.addEventListener('input', function() { cb4Update(); if(_cb4Curr==='SSP') cb4CalcRate(); }); }
  var di=document.getElementById('cb4Desc');
  if(di) { di.addEventListener('input', cb4Update); }
});

// ═══ CATEGORY SEARCH FILTER ═════════════════════════════════════════════
function cb4FilterCats(q) {
  q = q.toLowerCase().trim();
  if (!q) {
    // Empty search — restore tabbed view
    cb4RenderCats();
    return;
  }
  // v4.9.10: When searching Cash OUT, search ALL groups as flat list (ignore tabs)
  if (_cb4Dir === 'out') {
    var allCats = [];
    _cb4OutGroups.forEach(function(g) { allCats = allCats.concat(_cb4GrpCats(g.key)); });
    // Dedupe by id
    var seen = {};
    allCats = allCats.filter(function(c){ if(seen[c.id]) return false; seen[c.id]=true; return true; });
    var matched = allCats.filter(function(c) {
      return c.id.toLowerCase().indexOf(q) >= 0 || c.lbl.toLowerCase().indexOf(q) >= 0;
    });
    var grid = document.getElementById('cb4CatGrid');
    grid.innerHTML = '';
    // Hide tab bar during search, show flat results
    grid.innerHTML += cb4BuildChipGroup('', matched);
    // Keep Other... visible
    grid.innerHTML += '<div class="cb4-cats" style="margin-top:8px;grid-template-columns:1fr;">'
      + '<div class="cb4-cat cb4-cat-other" data-cat="__other__" onclick="cb4ShowOtherSearch()">'
      + '<div class="cb4-cat-ic">🔎</div><div class="cb4-cat-lbl">Other...</div></div></div>';
    // Auto-open Other panel if very few matches
    if (q.length >= 3 && matched.length <= 2) {
      cb4ShowOtherSearch();
      document.getElementById('cb4OtherInput').value = q;
      cb4OtherFilter(q);
    }
  } else {
    // Cash IN — simple filter on visible tiles
    var chips = document.querySelectorAll('.cb4-cat');
    chips.forEach(function(el) {
      var catId = (el.getAttribute('data-cat') || '').toLowerCase();
      var lbl = (el.querySelector('.cb4-cat-lbl') || {}).textContent || '';
      var match = !q || catId.indexOf(q) >= 0 || lbl.toLowerCase().indexOf(q) >= 0;
      el.style.display = match ? '' : 'none';
    });
  }
}

// ═══ "OTHER..." TYPEAHEAD SEARCH (v4.9.10) ═════════════════════════════
function cb4ShowOtherSearch() {
  document.getElementById('cb4OtherPanel').style.display = '';
  var inp = document.getElementById('cb4OtherInput');
  inp.value = '';
  inp.focus();
  document.getElementById('cb4OtherResults').style.display = 'none';
  // Highlight the Other tile
  document.querySelectorAll('.cb4-cat').forEach(function(el) {
    el.classList.toggle('sel', el.getAttribute('data-cat') === '__other__');
  });
}
function cb4OtherFilter(q) {
  q = q.toLowerCase().trim();
  var results = document.getElementById('cb4OtherResults');
  if (q.length < 2) { results.style.display = 'none'; return; }
  var html = '';
  var shown = 0;
  // 1. Search custom_categories (recently used, shown first)
  var customs = _cb4CustomCats.filter(function(c) { return c.toLowerCase().indexOf(q) >= 0; });
  if (customs.length) {
    html += '<div style="padding:5px 14px;font-size:10px;color:#94a3b8;font-weight:700;border-bottom:1px solid #f1f5f9;">RECENTLY USED</div>';
    customs.slice(0, 5).forEach(function(c) {
      html += '<div class="cb4-oth-item" onclick="cb4OtherPick(\'' + c.replace(/'/g, "\\'") + '\')">'
            + '<span>' + _cb4HighlightMatch(c, q) + '</span><span class="cb4-oth-src">custom</span></div>';
      shown++;
    });
  }
  // 2. Search BookKeeper accounts
  var bkMatches = _cb4BkAccounts.filter(function(a) { return a.toLowerCase().indexOf(q) >= 0; });
  if (bkMatches.length) {
    html += '<div style="padding:5px 14px;font-size:10px;color:#94a3b8;font-weight:700;border-bottom:1px solid #f1f5f9;">FROM BOOKKEEPER</div>';
    bkMatches.slice(0, 8).forEach(function(a) {
      html += '<div class="cb4-oth-item" onclick="cb4OtherPick(\'' + a.replace(/'/g, "\\'") + '\')">'
            + '<span>' + _cb4HighlightMatch(a, q) + '</span><span class="cb4-oth-src">BookKeeper</span></div>';
      shown++;
    });
  }
  // 3. Always show "Create new" option
  if (q.length >= 2) {
    html += '<div class="cb4-oth-item cb4-oth-new" onclick="cb4OtherPick(\'' + q.replace(/'/g, "\\'") + '\')">'
          + '<span>+ Create "' + q + '" as new category</span></div>';
  }
  results.innerHTML = html;
  results.style.display = shown > 0 || q.length >= 2 ? '' : 'none';
}
function _cb4HighlightMatch(text, q) {
  var idx = text.toLowerCase().indexOf(q);
  if (idx < 0) return text;
  return text.substring(0, idx) + '<strong>' + text.substring(idx, idx + q.length) + '</strong>' + text.substring(idx + q.length);
}
function cb4OtherPick(name) {
  // Save to custom_categories for next time (if not already in the list)
  if (_cb4CustomCats.indexOf(name) < 0 && _cb4BkAccounts.indexOf(name) < 0) {
    _cb4CustomCats.unshift(name);
    // v4.9.10: Persist to cb_categories.json so it survives page reload
    cb4SaveCustomCat(name);
  }
  // Hide the Other panel
  document.getElementById('cb4OtherPanel').style.display = 'none';
  // Set as category and advance
  cb4SetCat(name);
}
// v4.9.10: Fire-and-forget save of custom category to server
function cb4SaveCustomCat(name) {
  try {
    // Load current config, append custom, save back
    fetch('?page=api&action=cb_categories', {credentials:'same-origin'})
      .then(function(r) { return r.json(); })
      .then(function(resp) {
        var d = resp.data || resp;
        var customs = d.custom_categories || [];
        if (customs.indexOf(name) < 0) {
          customs.unshift(name);
          if (customs.length > 50) customs = customs.slice(0, 50); // cap at 50
        }
        d.custom_categories = customs;
        return fetch('?page=api&action=cb_categories_save', {
          method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type':'application/json'},
          body: JSON.stringify(d)
        });
      })
      .catch(function() { /* silent — never block the form */ });
  } catch(e) { /* silent */ }
}

// ═══ PERSON SEARCHABLE DROPDOWN ═════════════════════════════════════════
var _cb4PersonOpen = false;

function cb4PersonOpen() {
  var drop = document.getElementById('cb4PersonDrop');
  drop.classList.add('open');
  _cb4PersonOpen = true;
  cb4PersonFilter(document.getElementById('cb4Person').value);
}
function cb4PersonClose() {
  document.getElementById('cb4PersonDrop').classList.remove('open');
  _cb4PersonOpen = false;
}
function cb4PersonFilter(q) {
  q = q.toLowerCase().trim();
  var items = document.querySelectorAll('.cb4-person-item');
  var shown = 0;
  items.forEach(function(el) {
    var name = (el.getAttribute('data-name') || '');
    var nameLow = name.toLowerCase();
    var match = !q || nameLow.indexOf(q) >= 0;
    el.style.display = match ? '' : 'none';
    if (match) {
      shown++;
      // v4.9.10: Highlight matching text in yellow
      var nameEl = el.querySelector('.cb4-person-name');
      if (nameEl) {
        if (q && nameLow.indexOf(q) >= 0) {
          var idx = nameLow.indexOf(q);
          nameEl.innerHTML = name.substring(0, idx) + '<mark style="background:#fef08a;border-radius:2px;padding:0 1px;">'
            + name.substring(idx, idx + q.length) + '</mark>' + name.substring(idx + q.length);
        } else {
          nameEl.textContent = name;
        }
      }
    }
  });
  // Show dropdown if filtering
  if (!_cb4PersonOpen && q.length > 0) cb4PersonOpen();
}
function cb4PersonPick(el) {
  var name = el.getAttribute('data-name');
  document.getElementById('cb4Person').value = name;
  cb4PersonClose();
  cb4ShowSmartSuggestions(name);
  cb4Update();
}
// Close on outside click
document.addEventListener('click', function(e) {
  if (_cb4PersonOpen && !document.getElementById('cb4PersonWrap').contains(e.target)) {
    cb4PersonClose();
  }
});

// ═══ SMART CATEGORY SUGGESTIONS (auto-learned) ═════════════════════════
function cb4ShowSmartSuggestions(personName) {
  var strip = document.getElementById('cb4SmartStrip');
  var chips = document.getElementById('cb4SmartChips');
  var data = _cb4PersonHistory[personName];

  if (!data || !data.cats || data.cats.length === 0) {
    strip.style.display = 'none';
    return;
  }

  // Filter to match current direction, sort by count desc, take top 4
  var matched = data.cats.filter(function(c) { return c.dir === _cb4Dir; });
  matched.sort(function(a,b) { return b.cnt - a.cnt; });
  matched = matched.slice(0, 4);

  if (matched.length === 0) {
    // Show all cats regardless of direction if none match
    matched = data.cats.slice(0, 4);
  }

  chips.innerHTML = '';
  matched.forEach(function(m) {
    var ch = document.createElement('div');
    ch.className = 'cb4-smart-chip';
    ch.innerHTML = m.cat + ' <span class="cnt">' + m.cnt + '×</span>';
    ch.onclick = function() {
      // Jump back to Step 1, set direction + category, then forward again
      cb4GoStep1();
      if (m.dir === 'in' || m.dir === 'out') cb4SetDir(m.dir);
      cb4SetCat(m.cat);
    };
    chips.appendChild(ch);
  });
  strip.style.display = '';
}

// ═══ SUGGESTED PERSONS FOR CATEGORY (reverse auto-learn) ════════════════
function cb4ShowSuggestedPersons(cat) {
  var strip = document.getElementById('cb4SuggestStrip');
  var chips = document.getElementById('cb4SuggestChips');
  var catLabel = document.getElementById('cb4SuggestCatName');
  var data = _cb4CatToPersons[cat];

  if (!data || data.length === 0) {
    strip.style.display = 'none';
    return;
  }

  catLabel.textContent = cat;
  chips.innerHTML = '';
  // Show top entries for this category (max 6)
  var shown = data.slice(0, 6);
  var hasPersons = shown.some(function(p) { return p.src !== 'desc'; });
  var hasDescs = shown.some(function(p) { return p.src === 'desc'; });
  // Set label
  var lblEl = document.getElementById('cb4SuggestLbl');
  if (hasPersons && !hasDescs) {
    lblEl.innerHTML = '👤 Frequently used for <span id="cb4SuggestCatName">' + cat + '</span>';
  } else if (!hasPersons && hasDescs) {
    lblEl.innerHTML = '📝 Recent ' + cat + ' entries';
  } else {
    lblEl.innerHTML = '⚡ Previous ' + cat + ' entries';
  }
  shown.forEach(function(p) {
    var isDesc = p.src === 'desc';
    var ch = document.createElement('div');
    ch.className = isDesc ? 'cb4-suggest-chip desc' : 'cb4-suggest-chip';
    var cntBadge = p.cnt > 0 ? ' <span class="cnt">' + p.cnt + '×</span>' : '';
    ch.innerHTML = (isDesc ? '📝 ' : '👤 ') + p.name + cntBadge;
    ch.onclick = function() {
      if (isDesc) {
        // Description-sourced: fill description field
        document.getElementById('cb4Desc').value = p.name;
      } else {
        // Person-sourced: fill person field
        document.getElementById('cb4Person').value = p.name;
        cb4ShowSmartSuggestions(p.name);
      }
      cb4PersonClose();
      cb4Update();
      // Focus amount field
      var amtEl = document.getElementById('cb4Amt');
      if (amtEl) setTimeout(function() { amtEl.focus(); }, 80);
    };
    chips.appendChild(ch);
  });
  strip.style.display = '';
}

// ═══ REORDER PERSON DROPDOWN BY CATEGORY RELEVANCE ══════════════════════
function cb4ReorderPersonDrop(cat) {
  var drop = document.getElementById('cb4PersonDrop');
  var items = Array.from(drop.querySelectorAll('.cb4-person-item'));
  var catPersons = _cb4CatToPersons[cat] || [];

  // Build lookup: personName → count for this category
  var relevance = {};
  catPersons.forEach(function(p) { relevance[p.name] = (p.cnt || 0) + 1; }); // +1 so seeds (cnt=0) become 1, real entries stay high

  // Sort: relevant persons first (by count desc), then rest alphabetical
  items.sort(function(a, b) {
    var aName = a.getAttribute('data-name');
    var bName = b.getAttribute('data-name');
    var aRel = relevance.hasOwnProperty(aName) ? relevance[aName] : 0;
    var bRel = relevance.hasOwnProperty(bName) ? relevance[bName] : 0;
    if (aRel > 0 && bRel === 0) return -1;
    if (aRel === 0 && bRel > 0) return 1;
    if (aRel > 0 && bRel > 0) return bRel - aRel; // both relevant — higher count first
    return aName.localeCompare(bName); // both non-relevant — alphabetical
  });

  // Re-append in new order
  items.forEach(function(el) { drop.appendChild(el); });
}
function cbv2OS(id,sr,person,amt){document.getElementById('cbv2SID').value=id;document.getElementById('cbv2ST').textContent='Settle: '+sr;document.getElementById('cbv2SS').textContent='Given to '+person+' — $'+amt.toFixed(2);document.getElementById('cbv2SettleO').classList.add('open');document.body.style.overflow='hidden';}
function cbv2CS(){document.getElementById('cbv2SettleO').classList.remove('open');document.body.style.overflow='';}
function cbv2OE(id){cbCrud(id,null);}

// ── Bulk Select ─────────────────────────────────────────────────────────
function cbSelChanged(){
  var chks = document.querySelectorAll('.cb-row-chk:checked');
  var bar  = document.getElementById('cbBulkBar');
  var cnt  = document.getElementById('cbBulkCount');
  cnt.textContent = chks.length + ' selected';
  if(chks.length>0){bar.classList.add('show');}else{bar.classList.remove('show');}
  // sync select-all checkbox state
  var all = document.querySelectorAll('.cb-row-chk');
  document.getElementById('cbSelAll').indeterminate = chks.length > 0 && chks.length < all.length;
  document.getElementById('cbSelAll').checked = chks.length === all.length && all.length > 0;
}
function cbSelToggleAll(master){
  document.querySelectorAll('.cb-row-chk').forEach(function(c){ c.checked = master.checked; });
  cbSelChanged();
}
function cbBulkSelectSource(src){
  document.querySelectorAll('.cb-row-chk').forEach(function(c){
    var tr = c.closest('tr');
    c.checked = tr && tr.dataset.src === src;
  });
  cbSelChanged();
}
function cbBulkSelectAll(){
  document.querySelectorAll('.cb-row-chk').forEach(function(c){ c.checked = true; });
  cbSelChanged();
}
function cbBulkClear(){
  document.querySelectorAll('.cb-row-chk').forEach(function(c){ c.checked = false; });
  document.getElementById('cbSelAll').checked = false;
  document.getElementById('cbSelAll').indeterminate = false;
  document.getElementById('cbBulkBar').classList.remove('show');
}
function cbBulkDelete(){
  var chks = document.querySelectorAll('.cb-row-chk:checked');
  if (!chks.length) return;
  if (!confirm('Delete ' + chks.length + ' entr' + (chks.length===1?'y':'ies') + '? This cannot be undone.')) return;
  var container = document.getElementById('cbBulkIds');
  container.innerHTML = '';
  chks.forEach(function(c){
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'entry_ids[]'; inp.value = c.value;
    container.appendChild(inp);
  });
  document.getElementById('cbBulkForm').submit();
}

// ── CRUD Modal ──────────────────────────────────────────────────────────
var _cbCrudId = null;
function cbCrud(id, data) {
  _cbCrudId = id;
  var m = document.getElementById('cbCrudModal');
  if (data) {
    document.getElementById('cbCrudId').value    = id;
    document.getElementById('cbCrudDate').value  = data.date || '';
    document.getElementById('cbCrudDir').value   = data.direction || 'in';
    document.getElementById('cbCrudAmt').value   = data.amount || '';
    document.getElementById('cbCrudCat').value   = data.category || '';
    // Show original field category (category_raw) as a hint for expense_sync entries
    var rawHint = document.getElementById('cbCrudCatRawHint');
    if (rawHint) {
      var raw = (data.category_raw || '').trim();
      var isMapped = raw && raw.toLowerCase() !== (data.category || '').toLowerCase();
      if (isMapped && data.source === 'expense_sync') {
        rawHint.textContent = '📋 Original field category: ' + raw;
        rawHint.style.display = 'block';
      } else {
        rawHint.style.display = 'none';
      }
    }
    document.getElementById('cbCrudPerson').value= data.person || '';
    document.getElementById('cbCrudDesc').value  = data.description || '';
    document.getElementById('cbCrudRef').value   = data.validation_ref || '';
    document.getElementById('cbCrudVS').value    = data.validation_status || 'na';
    // Show source badge
    var src = data.source || 'manual';
    var srcEl = document.getElementById('cbCrudSrcBadge');
    var srcMap = {crm_sync:'🔄 CRM Sync',crm_webhook:'🌐 CRM Webhook',collect_payment:'📱 PWA',manual:'✏️ Manual',field_exchange:'💱 Auto (staff exchange — do not duplicate)',expense_sync:'💸 Auto (expense approval)'};
    srcEl.textContent = srcMap[src] || src;
    srcEl.style.display = 'inline';
  }
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  setTimeout(function(){ m.querySelector('.cbcrud-box').style.transform='translateY(0)'; }, 10);
}
function cbCrudClose() {
  var m = document.getElementById('cbCrudModal');
  m.querySelector('.cbcrud-box').style.transform = 'translateY(100%)';
  setTimeout(function(){ m.style.display='none'; document.body.style.overflow=''; }, 220);
}
function cbCrudDelete() {
  if (!confirm('Permanently delete this entry? This cannot be undone.')) return;
  document.getElementById('cbCrudAction').value = 'delete_entry';
  document.getElementById('cbCrudForm').submit();
}
function cbv2SendReminder(id,person){if(confirm('Send WhatsApp reminder to '+person+'?')){window.location.href='?<?php echo http_build_query(array_merge(['page'=>'dashboard','tab'=>'cashbook'],['cb_remind'=>'1'])); ?>&cb_rid='+id;}}
</script>

<!-- ── CRUD Modal ──────────────────────────────────────────────────────── -->
<style>
.cbcrud-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;align-items:flex-end;justify-content:center;}
.cbcrud-box{background:#fff;border-radius:20px 20px 0 0;padding:20px 18px 28px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;
  transform:translateY(100%);transition:transform .22s cubic-bezier(.32,1,.23,1);}
.cbcrud-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.cbcrud-title{font-size:15px;font-weight:900;color:#111;}
.cbcrud-close{background:#f1f5f9;border:none;border-radius:50%;width:30px;height:30px;font-size:15px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;}
.cbcrud-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;}
.cbcrud-row.full{grid-template-columns:1fr;}
.cbcrud-lbl{font-size:10px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.cbcrud-inp{width:100%;border:1.5px solid #e5e5e0;border-radius:8px;padding:8px 10px;font-size:13px;font-family:inherit;color:#1e293b;box-sizing:border-box;}
.cbcrud-inp:focus{outline:none;border-color:#D41C1C;}
.cbcrud-actions{display:flex;gap:8px;margin-top:16px;}
.cbcrud-save{flex:1;background:#D41C1C;color:#fff;border:none;border-radius:10px;padding:12px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
.cbcrud-del{background:#fff1f2;color:#dc2626;border:1.5px solid #fecaca;border-radius:10px;padding:12px 16px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
.cbcrud-src{font-size:10px;background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:10px;font-weight:700;}
@media(min-width:541px){.cbcrud-ov{align-items:center;}.cbcrud-box{border-radius:16px;max-height:85vh;}}
</style>

<div class="cbcrud-ov" id="cbCrudModal" onclick="if(event.target===this)cbCrudClose()">
  <div class="cbcrud-box">
    <div class="cbcrud-hd">
      <span class="cbcrud-title">✏️ Edit Entry</span>
      <span class="cbcrud-src" id="cbCrudSrcBadge"></span>
      <button class="cbcrud-close" onclick="cbCrudClose()">✕</button>
    </div>
    <form id="cbCrudForm" method="POST">
<?= csrfField() ?>
      <input type="hidden" name="cb_action" id="cbCrudAction" value="update_entry">
      <input type="hidden" name="entry_id"  id="cbCrudId">
      <input type="hidden" name="cb_proj"   value="<?php echo $proj; ?>">

      <div class="cbcrud-row">
        <div>
          <div class="cbcrud-lbl">Date</div>
          <input type="date" name="date" id="cbCrudDate" class="cbcrud-inp">
        </div>
        <div>
          <div class="cbcrud-lbl">Direction</div>
          <select name="direction" id="cbCrudDir" class="cbcrud-inp">
            <option value="in">Cash IN ↓</option>
            <option value="out">Cash OUT ↑</option>
          </select>
        </div>
      </div>

      <div class="cbcrud-row">
        <div>
          <div class="cbcrud-lbl">Amount (USD)</div>
          <input type="number" name="amount" id="cbCrudAmt" class="cbcrud-inp" step="0.01" min="0.01">
        </div>
        <div>
          <div class="cbcrud-lbl">Category</div>
          <select name="category" id="cbCrudCat" class="cbcrud-inp">
            <?php foreach(['Receipt','Salary','Transport Allowance','Food Allowance','Bonus','Employee Benefit','Site Power','Site Rent','Travel & Field','Local Purchase','Refund','Exchange','Transfer','Other Expense','Misc Expense','Staff Advance','SSP Advance','Capital Purchase','Tax','Govt Fees','Legal Fees','Bank Transfer','Vehicle','Airtime','Bandwidth','Advertising','Customer Refund','Commission','Partner Remuneration'] as $cat): ?>
            <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
            <?php endforeach; ?>
          </select>
          <div id="cbCrudCatRawHint" style="display:none;font-size:10px;color:#7c3aed;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;padding:4px 8px;margin-top:4px;font-weight:700;"></div>
        </div>
      </div>

      <div class="cbcrud-row">
        <div>
          <div class="cbcrud-lbl">Person / Staff</div>
          <input type="text" name="person" id="cbCrudPerson" class="cbcrud-inp" placeholder="e.g. Bidal">
        </div>
        <div>
          <div class="cbcrud-lbl">Validation Status</div>
          <select name="validation_status" id="cbCrudVS" class="cbcrud-inp">
            <option value="na">N/A</option>
            <option value="voucher">Voucher</option>
            <option value="online">Online / CRM</option>
            <option value="pending">Pending Receipt</option>
            <option value="jedco">Jedco</option>
            <option value="exchange">Exchange</option>
          </select>
        </div>
      </div>

      <div class="cbcrud-row full">
        <div>
          <div class="cbcrud-lbl">Description / Particulars</div>
          <textarea name="description" id="cbCrudDesc" class="cbcrud-inp" rows="3" style="resize:vertical;"></textarea>
        </div>
      </div>

      <div class="cbcrud-row full">
        <div>
          <div class="cbcrud-lbl">Validation Ref / Voucher No.</div>
          <input type="text" name="validation_ref" id="cbCrudRef" class="cbcrud-inp" placeholder="e.g. Voucher No-A0123">
        </div>
      </div>

      <div class="cbcrud-actions">
        <button type="submit" class="cbcrud-save">💾 Save Changes</button>
        <button type="button" class="cbcrud-del" onclick="cbCrudDelete()">🗑 Delete</button>
      </div>
    </form>
  </div>
</div>

