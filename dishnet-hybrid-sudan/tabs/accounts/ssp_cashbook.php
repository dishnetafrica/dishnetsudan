<?php
// ── SSP Cashbook — Rupesh's SSP Ledger (mirrors USD cashbook, project-wise) ─
// Accountant / Admin only. Reads cb_ledger currency='SSP'.
// Data source: CashbookService::getEntries(['currency'=>'SSP', 'project'=>...])
// CSV export: reuses cashbook CSV endpoint with cb_curr=SSP.

require_once __DIR__ . '/../../lib/CashbookService.php';

$cb    = new CashbookService($store, $dataDir);
$isAcct = ($isAdmin ?? false) || in_array($userRole ?? '', ['accountant','super_admin']);
if (!$isAcct) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">Accountant access required.</div>';
    return;
}

// ── CSV EXPORT ───────────────────────────────────────────────────────────────
if (!empty($_GET['sspcb_export']) && $_GET['sspcb_export'] === 'csv') {
    $proj    = $_GET['sspcb_proj'] ?? '';
    $from    = $_GET['sspcb_from'] ?? '';
    $to      = $_GET['sspcb_to']   ?? '';
    $filters = ['currency' => 'SSP', 'limit' => 9999, 'offset' => 0];
    if ($proj && $proj !== 'all') $filters['project'] = $proj;
    if ($from) $filters['date_from'] = $from;
    if ($to)   $filters['date_to']   = $to;
    $rows = $cb->getEntries($filters);
    $fname = 'ssp-cashbook-' . strtoupper($proj ?: 'ALL') . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    ob_end_clean();
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SR No.', 'Date', 'Description', 'Category', 'Person',
                   'SSP In', 'SSP Out', 'Running Balance', 'Rate', 'Source', 'Ref', 'Status']);
    foreach ($rows as $e) {
        $ssp = (float)($e['ssp_amount'] ?? 0);
        $isIn = ($e['direction'] ?? 'in') === 'in';
        fputcsv($out, [
            $e['sr'] ?? '',
            $e['date'] ?? '',
            $e['description'] ?? '',
            $e['category'] ?? '',
            $e['person'] ?? '',
            $isIn ? number_format($ssp, 0) : '',
            !$isIn ? number_format($ssp, 0) : '',
            isset($e['running_balance']) ? number_format((float)$e['running_balance'], 0) : '',
            isset($e['ssp_rate']) && $e['ssp_rate'] > 0 ? number_format((float)$e['ssp_rate'], 0) : '',
            $e['source'] ?? '',
            $e['validation_ref'] ?? '',
            $e['status'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── POST: Edit cb_ledger entry (rate, description, category) ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sspcb_action'] ?? '') === 'edit_entry') {
    $entryId  = (int)($_POST['entry_id'] ?? 0);
    $newRate  = (float)($_POST['new_rate'] ?? 0);
    $newDesc  = trim($_POST['new_desc'] ?? '');
    $newCat   = trim($_POST['new_cat'] ?? '');
    $pdo      = $store->getPdo();

    if ($entryId > 0) {
        $sets = ['updated_at = datetime(\'now\')'];
        $vals = [];
        if ($newRate > 0)  { $sets[] = 'ssp_rate = ?';   $vals[] = $newRate; }
        if ($newDesc !== '') { $sets[] = 'description = ?'; $vals[] = $newDesc; }
        if ($newCat !== '')  { $sets[] = 'category = ?';    $vals[] = $newCat;
                               $sets[] = 'category_raw = ?'; $vals[] = $newCat; }
        $vals[] = $entryId;

        try {
            $pdo->prepare("UPDATE cb_ledger SET " . implode(', ', $sets) . " WHERE id = ? AND currency = 'SSP'")->execute($vals);
            flash('✅ Entry updated.', 'success');
        } catch (\Throwable $e) {
            flash('Update failed: ' . $e->getMessage(), 'danger');
        }
    }
    redirect('?page=dashboard&tab=ssp_cashbook&sspcb_from=' . ($_POST['sspcb_from'] ?? '') . '&sspcb_to=' . ($_POST['sspcb_to'] ?? ''));
}

// Load staff for modals
$allStaff = $store->load('retailers.json') ?? [];

// ── FILTERS ──────────────────────────────────────────────────────────────────
$proj    = $_GET['sspcb_proj'] ?? 'all';
$from    = $_GET['sspcb_from'] ?? date('Y-m-01');      // default: this month
$to      = $_GET['sspcb_to']   ?? date('Y-m-d');
$cat     = trim($_GET['sspcb_cat'] ?? '');
$search  = trim($_GET['sspcb_q']   ?? '');
$page    = max(1, (int)($_GET['sspcb_pg'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$filters = ['currency' => 'SSP', 'limit' => $perPage, 'offset' => $offset];
if ($proj && $proj !== 'all') $filters['project'] = $proj;
if ($from)   $filters['date_from'] = $from;
if ($to)     $filters['date_to']   = $to;
if ($cat)    $filters['category']  = $cat;
if ($search) $filters['search']    = $search;

$rows  = $cb->getEntries($filters);

// Count for pagination
$cntFilters = $filters; unset($cntFilters['limit']); unset($cntFilters['offset']);
$cntFilters['limit'] = 99999; $cntFilters['offset'] = 0;
$allForCount = $cb->getEntries($cntFilters);
$totalRows = count($allForCount);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Hero totals — all SSP entries (no date filter) for balance
$allFilters = ['currency' => 'SSP', 'limit' => 99999, 'offset' => 0];
if ($proj && $proj !== 'all') $allFilters['project'] = $proj;
$allRows    = $cb->getEntries($allFilters);
$totalIn    = 0; $totalOut = 0;
foreach ($allRows as $r) {
    $ssp = (float)($r['ssp_amount'] ?? 0);
    if (($r['direction'] ?? 'in') === 'in') $totalIn  += $ssp;
    else                                     $totalOut += $ssp;
}
$balance = $totalIn - $totalOut;

// Period totals (within date filter)
$periodIn = 0; $periodOut = 0;
foreach ($allForCount as $r) {
    $ssp = (float)($r['ssp_amount'] ?? 0);
    if (($r['direction'] ?? 'in') === 'in') $periodIn  += $ssp;
    else                                     $periodOut += $ssp;
}

// SSP rate for USD equivalent
$sspRate = $cb->getExchangeRate() ?: 6000;
$balUsd  = $sspRate > 0 ? round($balance / $sspRate, 2) : 0;

// Distinct categories for filter dropdown
$catRows = $cb->query("SELECT DISTINCT category FROM cb_ledger WHERE currency='SSP' AND category != '' ORDER BY category");
$catList = array_column($catRows, 'category');

// Source → display label
function sspcbSourceLabel(string $src): array {
    $map = [
        'field_exchange'  => ['💱 Auto', 'fef3c7', '92400e', 'Staff recorded exchange via app'],
        'customer_ssp'    => ['👤 Client', 'ede9fe', '6d28d9', 'Customer paid invoice in SSP'],
        'expense_sync'    => ['💸 Expense', 'f5f3ff', '6d28d9', 'Field expense approved'],
        'manual'          => ['✎ Manual', 'f8fafc', '64748b', 'Entered manually by Rupesh'],
        'excel_import'    => ['📊 Import', 'f0f9ff', '0369a1', 'Imported from Excel'],
        'ssp_transfer'    => ['↕ Transfer', 'ecfdf5', '065f46', 'SSP transfer between staff'],
        'ssp_return'      => ['↩ Return', 'f0fdf4', '15803d', 'SSP returned to safe'],
        'collect_payment' => ['📱 PWA', 'dcfce7', '15803d', 'Field collect payment'],
        'crm_webhook'     => ['🌐 CRM', 'dbeafe', '1e40af', 'CRM webhook'],
    ];
    return $map[$src] ?? [$src, 'f8fafc', '94a3b8', $src];
}

// URL builder
function sspcbUrl(array $override = []): string {
    $params = array_merge([
        'page'       => 'dashboard',
        'tab'        => 'ssp_cashbook',
        'sspcb_proj' => $_GET['sspcb_proj'] ?? 'all',
        'sspcb_from' => $_GET['sspcb_from'] ?? date('Y-m-01'),
        'sspcb_to'   => $_GET['sspcb_to']   ?? date('Y-m-d'),
        'sspcb_cat'  => $_GET['sspcb_cat']  ?? '',
        'sspcb_q'    => $_GET['sspcb_q']    ?? '',
        'sspcb_pg'   => $_GET['sspcb_pg']   ?? 1,
    ], $override);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$fmtSSP = fn($n) => number_format(round((float)$n, 0), 0);
?>
<style>
/* ── SSP Cashbook Styles ────────────────────────────────── */
:root{--ssp-green:#15803d;--ssp-red:#dc2626;--ssp-blue:#1e40af;--ssp-bg:#f8fafc;--ssp-border:#e2e8f0;}
.sspcb-wrap{background:#f8fafc;min-height:100dvh;padding-bottom:80px;}
/* ── Top Bar ── */
.sspcb-bar{background:#1e293b;padding:0 16px;display:flex;align-items:center;gap:10px;height:52px;position:sticky;top:0;z-index:200;}
.sspcb-title{font-size:17px;font-weight:800;color:#fff;flex:1;}
.sspcb-proj-pills{display:flex;background:rgba(255,255,255,.1);border-radius:20px;padding:3px;gap:2px;}
.sspcb-proj-pills a{padding:4px 10px;border-radius:14px;font-size:11px;font-weight:700;color:rgba(255,255,255,.5);text-decoration:none;}
.sspcb-proj-pills a.on{background:#d41c1c;color:#fff;}
/* ── Hero Cards ── */
.sspcb-heroes{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:14px 14px 0;}
@media(max-width:600px){.sspcb-heroes{grid-template-columns:repeat(2,1fr);}}
.sspcb-card{background:#fff;border-radius:14px;padding:14px 12px;border:1.5px solid #e2e8f0;}
.sspcb-card-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;margin-bottom:4px;}
.sspcb-card-val{font-size:22px;font-weight:900;letter-spacing:-1px;}
.sspcb-card-sub{font-size:10px;color:#94a3b8;margin-top:2px;}
/* ── Filter Bar ── */
.sspcb-filters{display:flex;flex-wrap:wrap;gap:8px;padding:12px 14px;align-items:center;}
.sspcb-filters input,.sspcb-filters select{height:36px;border:1.5px solid #e2e8f0;border-radius:10px;padding:0 10px;font-size:13px;background:#fff;color:#374151;}
.sspcb-filters input[type=date]{min-width:130px;}
.sspcb-filters select{min-width:120px;}
.sspcb-filters input[type=text]{min-width:160px;flex:1;}
.sspcb-btn{height:36px;padding:0 14px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:5px;}
.sspcb-btn-pri{background:#1e293b;color:#fff;}
.sspcb-btn-csv{background:#f0fdf4;color:#15803d;border:1.5px solid #bbf7d0;}
/* ── Table ── */
.sspcb-tbl-wrap{padding:0 14px;overflow-x:auto;}
.sspcb-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.sspcb-tbl th{background:#1e293b;color:#fff;font-size:11px;font-weight:700;padding:9px 10px;text-align:left;position:sticky;top:52px;white-space:nowrap;}
.sspcb-tbl th.num{text-align:right;}
.sspcb-tbl tr:nth-child(even) td{background:#f8fafc;}
.sspcb-tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#1e293b;}
.sspcb-tbl td.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600;}
.sspcb-in{color:#15803d;font-weight:700;}
.sspcb-out{color:#dc2626;font-weight:700;}
.sspcb-bal-pos{color:#0f172a;font-weight:800;}
.sspcb-bal-neg{color:#dc2626;font-weight:800;}
.sspcb-sr{font-size:10px;color:#94a3b8;font-family:monospace;}
.sspcb-desc{max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#374151;}
.sspcb-badge{display:inline-block;padding:2px 6px;border-radius:8px;font-size:10px;font-weight:700;white-space:nowrap;}
.sspcb-rate{font-size:11px;color:#64748b;font-family:monospace;}
/* ── Pagination ── */
.sspcb-pag{display:flex;align-items:center;justify-content:center;gap:6px;padding:16px;}
.sspcb-pag a{padding:6px 12px;background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-weight:700;color:#374151;text-decoration:none;}
.sspcb-pag a.on{background:#1e293b;color:#fff;border-color:#1e293b;}
.sspcb-pag span{font-size:12px;color:#94a3b8;}
/* ── Summary row ── */
.sspcb-sum-row td{background:#1e293b !important;color:#fff;font-weight:700;font-size:13px;}
.sspcb-sum-row td.num{color:#fff;}
</style>

<div class="sspcb-wrap">

<!-- ── Top Bar ── -->
<div class="sspcb-bar">
    <span class="sspcb-title">🇸🇸 SSP Cashbook</span>
    <div class="sspcb-proj-pills">
        <a href="<?= h(sspcbUrl(['sspcb_proj'=>'all','sspcb_pg'=>1])) ?>" class="<?= $proj==='all'?'on':'' ?>">All</a>
        <a href="<?= h(sspcbUrl(['sspcb_proj'=>'dishnet','sspcb_pg'=>1])) ?>" class="<?= $proj==='dishnet'?'on':'' ?>">Starlink</a>
        <a href="<?= h(sspcbUrl(['sspcb_proj'=>'fiber','sspcb_pg'=>1])) ?>" class="<?= $proj==='fiber'?'on':'' ?>">Fiber</a>
        <a href="<?= h(sspcbUrl(['sspcb_proj'=>'4g','sspcb_pg'=>1])) ?>" class="<?= $proj==='4g'?'on':'' ?>">LTE</a>
    </div>
</div>

<!-- ── Hero Cards ── -->
<div class="sspcb-heroes">
    <div class="sspcb-card">
        <div class="sspcb-card-lbl">🇸🇸 Balance</div>
        <div class="sspcb-card-val" style="color:<?= $balance >= 0 ? '#15803d' : '#dc2626' ?>;"><?= $fmtSSP($balance) ?></div>
        <div class="sspcb-card-sub">≈ $<?= number_format($balUsd, 2) ?> @ <?= number_format($sspRate, 0) ?></div>
    </div>
    <div class="sspcb-card">
        <div class="sspcb-card-lbl">▲ Total IN (all time)</div>
        <div class="sspcb-card-val" style="color:#15803d;"><?= $fmtSSP($totalIn) ?></div>
        <div class="sspcb-card-sub">Exchanges + transfers in</div>
    </div>
    <div class="sspcb-card">
        <div class="sspcb-card-lbl">▼ Total OUT (all time)</div>
        <div class="sspcb-card-val" style="color:#dc2626;"><?= $fmtSSP($totalOut) ?></div>
        <div class="sspcb-card-sub">Expenses + transfers out</div>
    </div>
    <div class="sspcb-card">
        <div class="sspcb-card-lbl">📅 Period (<?= h(date('d M', strtotime($from))) ?> – <?= h(date('d M', strtotime($to))) ?>)</div>
        <div class="sspcb-card-val" style="font-size:16px;color:#1e293b;">
            <span style="color:#15803d;">+<?= $fmtSSP($periodIn) ?></span>
            &nbsp;/&nbsp;
            <span style="color:#dc2626;">-<?= $fmtSSP($periodOut) ?></span>
        </div>
        <div class="sspcb-card-sub"><?= $totalRows ?> entries shown</div>
    </div>
</div>

<!-- ── Filter Bar ── -->
<form method="GET" action="" style="margin:0;">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab" value="ssp_cashbook">
    <input type="hidden" name="sspcb_proj" value="<?= h($proj) ?>">
    <div class="sspcb-filters">
        <input type="date" name="sspcb_from" value="<?= h($from) ?>" title="From date">
        <input type="date" name="sspcb_to" value="<?= h($to) ?>" title="To date">
        <select name="sspcb_cat">
            <option value="">All Categories</option>
            <?php foreach ($catList as $c): ?>
            <option value="<?= h($c) ?>" <?= $cat===$c?'selected':'' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="sspcb_q" value="<?= h($search) ?>" placeholder="Search description, person, ref…">
        <button type="submit" class="sspcb-btn sspcb-btn-pri">🔍 Filter</button>
        <a href="<?= h(sspcbUrl(['sspcb_from' => date('Y-m-01'), 'sspcb_to' => date('Y-m-d'), 'sspcb_cat' => '', 'sspcb_q' => '', 'sspcb_pg' => 1])) ?>"
           class="sspcb-btn" style="background:#fff;border:1.5px solid #e2e8f0;color:#64748b;">✕ Reset</a>
        <a href="?page=dashboard&tab=ssp_cashbook&sspcb_export=csv&sspcb_proj=<?= h($proj) ?>&sspcb_from=<?= h($from) ?>&sspcb_to=<?= h($to) ?>"
           class="sspcb-btn sspcb-btn-csv">⬇ CSV</a>
    </div>
</form>

<!-- ── Action Buttons ── -->
<div style="display:flex;gap:8px;padding:0 14px 10px;flex-wrap:wrap;">
    <button onclick="document.getElementById('sspGiveModal').style.display='flex'"
        style="background:#1e293b;color:#fff;border:none;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
        ↕ Give SSP to Staff
    </button>
    <button onclick="document.getElementById('sspReturnModal').style.display='flex'"
        style="background:#f0fdf4;color:#15803d;border:1.5px solid #bbf7d0;border-radius:10px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
        ↩ Receive SSP Return
    </button>
</div>

<!-- ── Table ── -->
<div class="sspcb-tbl-wrap">
<?php if (empty($rows)): ?>
<div style="background:#fff;border-radius:14px;border:1.5px solid #e2e8f0;padding:40px;text-align:center;color:#94a3b8;margin:0 0 16px;">
    No SSP entries found for this period and filter.
    <?php if ($from || $cat || $search): ?>
    <br><a href="<?= h(sspcbUrl(['sspcb_from'=>'','sspcb_to'=>'','sspcb_cat'=>'','sspcb_q'=>'','sspcb_pg'=>1])) ?>"
       style="color:#2563eb;font-size:12px;margin-top:8px;display:inline-block;">Clear filters</a>
    <?php endif; ?>
</div>
<?php else: ?>
<table class="sspcb-tbl">
<thead>
<tr>
    <th style="width:70px;">SR</th>
    <th style="width:80px;">Date</th>
    <th>Description</th>
    <th style="width:130px;">Category</th>
    <th style="width:90px;">Person</th>
    <th class="num" style="width:110px;">SSP In ▲</th>
    <th class="num" style="width:110px;">SSP Out ▼</th>
    <th class="num" style="width:110px;">Balance</th>
    <th style="width:80px;">Rate</th>
    <th style="width:90px;">Source</th>
    <th style="width:40px;"></th>
</tr>
</thead>
<tbody>
<?php
// Period summary row at top
$periodNet = $periodIn - $periodOut;
?>
<tr class="sspcb-sum-row">
    <td colspan="5" style="font-size:11px;letter-spacing:.5px;">
        PERIOD: <?= h(date('d M Y', strtotime($from))) ?> – <?= h(date('d M Y', strtotime($to))) ?>
        &nbsp;·&nbsp; <?= $totalRows ?> entries
    </td>
    <td class="num" style="color:#86efac;"><?= $fmtSSP($periodIn) ?></td>
    <td class="num" style="color:#fca5a5;"><?= $fmtSSP($periodOut) ?></td>
    <td class="num" style="color:<?= $periodNet >= 0 ? '#86efac' : '#fca5a5' ?>;"><?= $fmtSSP($periodNet) ?></td>
    <td colspan="2"></td>
</tr>
<?php foreach ($rows as $row):
    $ssp    = (float)($row['ssp_amount'] ?? 0);
    $isIn   = ($row['direction'] ?? 'in') === 'in';
    $bal    = $row['running_balance'] ?? null;
    $rate   = (float)($row['ssp_rate'] ?? 0);
    $src    = $row['source'] ?? 'manual';
    $status = $row['status'] ?? 'approved';
    [$srcLbl, $srcBg, $srcFg, $srcTitle] = sspcbSourceLabel($src);
    $isVoid = in_array($status, ['voided','cancelled','rejected','reverted']);
    $rowStyle = $isVoid ? 'opacity:.45;' : '';
    $desc     = $row['description'] ?? '';
    $cat      = $row['category'] ?? '';
?>
<tr style="<?= $rowStyle ?>">
    <td class="sspcb-sr"><?= h($row['sr'] ?? '—') ?></td>
    <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?= h(date('d M', strtotime($row['date'] ?? 'today'))) ?></td>
    <td class="sspcb-desc" title="<?= h($desc) ?>">
        <?= h(mb_strimwidth($desc, 0, 55, '…')) ?>
        <?php if ($isVoid): ?><span style="font-size:10px;color:#dc2626;font-weight:700;margin-left:4px;">VOID</span><?php endif; ?>
    </td>
    <td style="font-size:12px;">
        <?php
        $catIcons = ['Exchange'=>'💱','Travel & Field'=>'⛽','Airtime'=>'📶','Local Purchase'=>'🛍',
                     'Salary'=>'👤','Tax'=>'📋','Misc Expense'=>'📦','Staff Advance'=>'💰',
                     'SSP Received'=>'🟢','SSP Transfer'=>'↕','SSP Return'=>'↩'];
        echo ($catIcons[$cat] ?? '▸') . ' ' . h($cat);
        ?>
    </td>
    <td style="font-size:12px;color:#374151;"><?= h(mb_strimwidth($row['person'] ?? '', 0, 18, '…')) ?></td>
    <td class="num <?= $isIn ? 'sspcb-in' : '' ?>">
        <?= $isIn ? $fmtSSP($ssp) : '' ?>
    </td>
    <td class="num <?= !$isIn ? 'sspcb-out' : '' ?>">
        <?= !$isIn ? $fmtSSP($ssp) : '' ?>
    </td>
    <td class="num">
        <?php if ($bal !== null): ?>
        <span class="<?= (float)$bal >= 0 ? 'sspcb-bal-pos' : 'sspcb-bal-neg' ?>"><?= $fmtSSP($bal) ?></span>
        <?php else: ?>
        <span style="color:#cbd5e1;">—</span>
        <?php endif; ?>
    </td>
    <td class="sspcb-rate"
        style="<?= $rate > 0 && $rate > 20000 ? 'color:#dc2626;font-weight:700;' : '' ?>"
        title="<?= $rate > 20000 ? 'Rate looks high — click ✎ to correct' : '' ?>">
        <?= $rate > 0 ? number_format($rate, 0) : '' ?>
        <?php if ($rate > 20000): ?> <span style="font-size:10px;">⚠</span><?php endif; ?>
    </td>
    <td>
        <span class="sspcb-badge" style="background:#<?= $srcBg ?>;color:#<?= $srcFg ?>;" title="<?= h($srcTitle) ?>">
            <?= h($srcLbl) ?>
        </span>
    </td>
    <td style="text-align:center;">
        <?php if (!$isVoid && $src === 'manual' && $isAcct): ?>
        <button type="button" onclick="sspcbEditOpen(<?= (int)($row['id'] ?? 0) ?>, <?= $rate ?>, '<?= h(addslashes($desc)) ?>', '<?= h(addslashes($cat)) ?>')"
            style="background:none;border:1px solid #e2e8f0;border-radius:6px;padding:3px 7px;font-size:11px;cursor:pointer;color:#64748b;" title="Edit rate / description">✎</button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

<!-- ── Pagination ── -->
<?php if ($totalPages > 1): ?>
<div class="sspcb-pag">
    <?php if ($page > 1): ?>
    <a href="<?= h(sspcbUrl(['sspcb_pg' => $page - 1])) ?>">‹ Prev</a>
    <?php endif; ?>
    <span>Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> entries)</span>
    <?php if ($page < $totalPages): ?>
    <a href="<?= h(sspcbUrl(['sspcb_pg' => $page + 1])) ?>">Next ›</a>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ══ Give SSP Modal ══ -->
<div id="sspGiveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff;border-radius:18px;padding:24px;width:min(360px,92vw);box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:4px;">↕ Give SSP to Staff</div>
    <div style="font-size:12px;color:#64748b;margin-bottom:16px;">Rupesh gives SSP from safe to a field staff member</div>
    <form method="POST" action="?page=dashboard&tab=ssp_cashbook">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="give_ssp_to_staff">
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Staff Member</label>
        <select name="ssp_to_staff_id" required style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
          <option value="">— Select staff —</option>
          <?php foreach ($allStaff ?? [] as $_gs):
            if (in_array($_gs['role']??'', ['admin','accountant','super_admin'])) continue;
            if (empty($_gs['is_active'])) continue; ?>
          <option value="<?= (int)$_gs['id'] ?>"><?= htmlspecialchars($_gs['name'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">SSP Amount</label>
        <input type="number" name="ssp_amount" min="1000" step="1000" placeholder="e.g. 400000" required
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Rate (SSP per $1) <span style="color:#64748b;font-weight:400;">— optional</span></label>
        <input type="number" name="ssp_rate" min="0" step="100" placeholder="e.g. 6000"
          value="<?= (int)($cb->getExchangeRate() ?: 6000) ?>"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:16px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Note <span style="color:#64748b;font-weight:400;">— optional</span></label>
        <input type="text" name="ssp_note" placeholder="e.g. For fiber field work this week"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" onclick="document.getElementById('sspGiveModal').style.display='none'"
          style="flex:1;padding:11px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit"
          style="flex:2;padding:11px;background:#1e293b;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;">↕ Give SSP</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ Return SSP Modal ══ -->
<div id="sspReturnModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff;border-radius:18px;padding:24px;width:min(360px,92vw);box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:4px;">↩ Receive SSP Return</div>
    <div style="font-size:12px;color:#64748b;margin-bottom:16px;">Staff returns unspent SSP back to Rupesh's safe</div>
    <form method="POST" action="?page=dashboard&tab=ssp_cashbook">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="return_ssp_from_staff">
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Staff Member</label>
        <select name="ssp_from_staff_id" required style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
          <option value="">— Select staff —</option>
          <?php foreach ($allStaff ?? [] as $_rs):
            if (in_array($_rs['role']??'', ['admin','accountant','super_admin'])) continue;
            if (empty($_rs['is_active'])) continue; ?>
          <option value="<?= (int)$_rs['id'] ?>"><?= htmlspecialchars($_rs['name'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">SSP Amount Returned</label>
        <input type="number" name="ssp_return_amount" min="1" step="1000" placeholder="e.g. 53000" required
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Rate <span style="color:#64748b;font-weight:400;">— optional</span></label>
        <input type="number" name="ssp_return_rate" min="0" step="100" placeholder="e.g. 6000"
          value="<?= (int)($cb->getExchangeRate() ?: 6000) ?>"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:16px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Note <span style="color:#64748b;font-weight:400;">— optional</span></label>
        <input type="text" name="ssp_return_note" placeholder="e.g. Unspent after fiber field day"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" onclick="document.getElementById('sspReturnModal').style.display='none'"
          style="flex:1;padding:11px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit"
          style="flex:2;padding:11px;background:#15803d;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;">↩ Receive SSP</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Entry Modal ──────────────────────────────────────────────────── -->
<div id="sspcbEditModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:18px;padding:24px;width:min(400px,94vw);box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <div style="font-size:15px;font-weight:800;color:#0f172a;">✎ Edit Entry</div>
      <button onclick="document.getElementById('sspcbEditModal').style.display='none'" style="background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;">×</button>
    </div>
    <form method="POST" action="?page=dashboard&tab=ssp_cashbook">
      <?= csrfField() ?>
      <input type="hidden" name="sspcb_action" value="edit_entry">
      <input type="hidden" name="entry_id" id="sspcbEditId">
      <input type="hidden" name="sspcb_from" value="<?= h($from) ?>">
      <input type="hidden" name="sspcb_to" value="<?= h($to) ?>">
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">SSP Rate (SSP per $1 USD)</label>
        <input type="number" name="new_rate" id="sspcbEditRate" min="100" step="100"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:18px;font-weight:700;box-sizing:border-box;">
        <div id="sspcbRateWarn" style="display:none;margin-top:4px;font-size:11px;color:#dc2626;font-weight:600;"></div>
      </div>
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Description</label>
        <input type="text" name="new_desc" id="sspcbEditDesc"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:16px;">
        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Category</label>
        <input type="text" name="new_cat" id="sspcbEditCat"
          style="width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" onclick="document.getElementById('sspcbEditModal').style.display='none'"
          style="flex:1;padding:11px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit"
          style="flex:2;padding:11px;background:#1e293b;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;">✓ Save Changes</button>
      </div>
    </form>
  </div>
</div>
<script>
function sspcbEditOpen(id, rate, desc, cat) {
    document.getElementById('sspcbEditId').value   = id;
    document.getElementById('sspcbEditRate').value = rate;
    document.getElementById('sspcbEditDesc').value = desc;
    document.getElementById('sspcbEditCat').value  = cat;
    // Warn if rate looks wrong
    var warn = document.getElementById('sspcbRateWarn');
    if (rate > 20000) {
        warn.style.display = 'block';
        warn.textContent = '⚠ Rate ' + rate.toLocaleString() + ' looks too high — typical rate is 5,700–6,500 SSP/$.';
    } else { warn.style.display = 'none'; }
    document.getElementById('sspcbEditModal').style.display = 'flex';
    document.getElementById('sspcbEditRate').focus();
}
document.getElementById('sspcbEditRate').addEventListener('input', function() {
    var warn = document.getElementById('sspcbRateWarn');
    var v = parseFloat(this.value);
    if (v > 20000) {
        warn.style.display = 'block';
        warn.textContent = '⚠ Rate ' + v.toLocaleString() + ' looks too high — typical is 5,700–6,500 SSP/$.';
    } else { warn.style.display = 'none'; }
});
</script>

</div><!-- .sspcb-wrap -->
