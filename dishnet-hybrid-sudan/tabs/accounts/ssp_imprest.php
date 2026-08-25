<?php
// ── SSP Imprest — Company-level SSP cash & P&L view ───────────────────────
// v4.20.4 — paired with the v4.20.3 imprest model fix.
//
// THREE SUB-VIEWS, switched via ?ssp_view=:
//   - position  (default): daily cash position (main till + per-staff imprest)
//   - holders            : per-staff imprest balances + drill-down
//   - pnl                : period P&L by category (imprest + direct, blended)
//
// READ-ONLY. No POST handlers. No money paths touched.
// ──────────────────────────────────────────────────────────────────────────

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

// Access: admin + accountant only
if (!($retailer['is_admin'] ?? false) && !in_array($retailer['role'] ?? '', ['admin','accountant'], true)) {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied — admin/accountant only.</div>';
    return;
}

require_once __DIR__ . '/../../lib/SspImprestReportService.php';

$svc = new SspImprestReportService($store, $dataDir);

// CSV export — must run before any HTML output (Rule 13: ob_end_clean before headers)
if (($_GET['ssp_view'] ?? '') === 'audit' && ($_GET['ssp_export'] ?? '') === 'csv') {
    if (function_exists('ob_get_level') && ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="ssp_imprest_audit_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['# DishNet SSP Imprest Audit — generated ' . date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['== Section 1: Per-staff advance reconciliation ==']);
    fputcsv($out, ['staff_id','staff_name','cb_ledger_total','cb_ledger_rows','staff_ledger_total','staff_ledger_rows','diff','link_method','status']);
    foreach ($svc->auditAdvanceTotals() as $r) {
        fputcsv($out, [$r['staff_id'],$r['staff_name'],$r['cb_ledger_total'],$r['cb_ledger_rows'],$r['staff_ledger_total'],$r['staff_ledger_rows'],$r['diff'],$r['link_method'],$r['status']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['== Section 2: Per-staff expense duplicate detection ==']);
    fputcsv($out, ['staff_id','staff_name','expense_total','expense_rows','cb_duplicate_total','cb_duplicate_rows','diff','status']);
    foreach ($svc->auditExpenseTotals() as $r) {
        fputcsv($out, [$r['staff_id'],$r['staff_name'],$r['expense_total'],$r['expense_rows'],$r['cb_duplicate_total'],$r['cb_duplicate_rows'],$r['diff'],$r['status']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['== Section 3: Company-level reconciliation ==']);
    $rec = $svc->auditCompanyReconciliation();
    foreach (['cb_ledger_ssp_balance','total_legacy_duplicates','reconstructed_main_till','total_imprest','reconstructed_company_ssp'] as $k) {
        fputcsv($out, [$k, $rec[$k]]);
    }
    fclose($out);
    exit;
}

// View selector
$view = $_GET['ssp_view'] ?? 'position';
if (!in_array($view, ['position','holders','pnl','audit'], true)) $view = 'position';

// Drill-down: holder history
$drillStaffId = (int)($_GET['ssp_drill'] ?? 0);

// P&L date range — default to current month
$today    = date('Y-m-d');
$monthStart = date('Y-m-01');
$dateFrom = $_GET['ssp_from'] ?? $monthStart;
$dateTo   = $_GET['ssp_to']   ?? $today;
// Validate (very lightly)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $monthStart;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = $today;

// Helper: build URL keeping tab+view, swapping query bits
$urlBase = '?page=dashboard&tab=ssp_imprest';
$mkLink = function(array $extra) use ($urlBase) {
    return $urlBase . '&' . http_build_query($extra);
};

$fmt = function($n) { return number_format((float)$n, 0); };
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap');
.imp{font-family:'DM Sans',-apple-system,sans-serif;padding-bottom:40px;color:#0f0f0f;}
.imp-hd{margin-bottom:18px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.imp-hd h2{font-size:22px;font-weight:900;margin:0 0 3px;}
.imp-hd-sub{font-size:12px;color:#94a3b8;}
.imp-tabs{display:flex;gap:2px;border-bottom:1.5px solid #ececec;margin-bottom:18px;}
.imp-tab{padding:10px 18px;font-size:12px;font-weight:700;color:#94a3b8;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1.5px;text-transform:uppercase;letter-spacing:.4px;}
.imp-tab:hover{color:#0f0f0f;}
.imp-tab.active{color:#0f0f0f;border-bottom-color:#0f0f0f;}
/* KPI grid */
.imp-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:22px;}
.imp-kpi{background:#fff;border-radius:14px;border:1.5px solid #ececec;padding:16px 18px;}
.imp-kpi-v{font-size:30px;font-weight:900;line-height:1;letter-spacing:-.5px;}
.imp-kpi-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#b0b0b0;margin-top:7px;}
.imp-kpi-h{font-size:11px;color:#94a3b8;margin-top:6px;font-weight:500;}
.imp-kpi.accent{background:#0f0f0f;color:#fff;}
.imp-kpi.accent .imp-kpi-l{color:#94a3b8;}
.imp-kpi.accent .imp-kpi-h{color:#cbd5e1;}
/* Today flow chips */
.imp-flow{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:22px;}
.imp-flow-cell{background:#fff;border:1.5px solid #ececec;border-radius:10px;padding:11px 14px;}
.imp-flow-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;}
.imp-flow-v{font-size:18px;font-weight:800;margin-top:4px;}
.imp-flow-cell.in .imp-flow-v{color:#15803D;}
.imp-flow-cell.out .imp-flow-v{color:#DC2626;}
/* Section heading */
.imp-section{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin:18px 0 10px;}
/* Table */
.imp-table{width:100%;border-collapse:collapse;background:#fff;border-radius:16px;overflow:hidden;border:1.5px solid #ececec;}
.imp-table th{background:#f8f8f8;padding:9px 14px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;text-align:right;border-bottom:1.5px solid #ececec;}
.imp-table th:first-child,.imp-table td:first-child{text-align:left;}
.imp-table td{padding:11px 14px;font-size:13px;border-bottom:1px solid #f3f4f6;text-align:right;vertical-align:middle;}
.imp-table tr:last-child td{border-bottom:none;}
.imp-table tr.tot td{background:#fafafa;font-weight:800;border-top:2px solid #0f0f0f;}
.imp-table tr:hover td{background:#fcfcfc;}
.imp-table tr.tot:hover td{background:#fafafa;}
.imp-name{font-weight:700;font-size:13px;}
.imp-name a{color:#0f0f0f;text-decoration:none;border-bottom:1px dotted #94a3b8;}
.imp-name a:hover{border-bottom-color:#0f0f0f;}
.imp-sub{font-size:10px;color:#94a3b8;margin-top:2px;}
/* Status chips */
.imp-chip{font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;display:inline-block;letter-spacing:.3px;text-transform:uppercase;}
.imp-chip.fresh{background:#DCFCE7;color:#15803D;}
.imp-chip.stale{background:#FEF3C7;color:#92400E;}
.imp-chip.overdue{background:#FED7AA;color:#9A3412;}
.imp-chip.overdrawn{background:#FEE2E2;color:#991B1B;}
.imp-chip.zero{background:#F3F4F6;color:#6B7280;}
.imp-bal{font-weight:900;font-size:16px;letter-spacing:-.3px;}
.imp-bal.danger{color:#DC2626;}
/* Forms */
.imp-controls{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;}
.imp-controls label{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;}
.imp-controls input[type=date]{font-family:'DM Sans',sans-serif;font-size:13px;padding:7px 10px;border:1.5px solid #ececec;border-radius:8px;}
.imp-btn{background:#0f0f0f;color:#fff;border:0;padding:8px 16px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:700;border-radius:8px;cursor:pointer;text-decoration:none;display:inline-block;}
.imp-btn:hover{background:#333;}
.imp-btn.ghost{background:#fff;color:#0f0f0f;border:1.5px solid #ececec;}
.imp-btn.ghost:hover{background:#fafafa;}
/* Empty state */
.imp-empty{text-align:center;padding:50px;color:#94a3b8;font-size:14px;background:#fafafa;border-radius:14px;border:1.5px dashed #ececec;}
/* Notes */
.imp-notes{margin-top:14px;font-size:11px;color:#94a3b8;line-height:1.7;}
.imp-notes strong{color:#0f0f0f;font-weight:700;}
/* Drill-down history */
.imp-history{background:#fff;border-radius:14px;border:1.5px solid #ececec;padding:18px;margin-top:14px;}
.imp-history h3{font-size:14px;font-weight:800;margin:0 0 12px;}
.imp-hist-row{display:grid;grid-template-columns:90px 60px 110px 110px 1fr;gap:12px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:12px;}
.imp-hist-row:last-child{border-bottom:none;}
.imp-hist-date{color:#94a3b8;font-variant-numeric:tabular-nums;}
.imp-hist-dir{font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;}
.imp-hist-dir.in{color:#15803D;}
.imp-hist-dir.out{color:#DC2626;}
.imp-hist-amt{font-weight:700;text-align:right;font-variant-numeric:tabular-nums;}
.imp-hist-cat{color:#0f0f0f;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.3px;}
.imp-hist-desc{color:#666;font-size:12px;}
@media(max-width:640px){
  .imp-kpis,.imp-flow{grid-template-columns:repeat(2,1fr);}
  .imp-table{display:block;overflow-x:auto;}
  .imp-hist-row{grid-template-columns:80px 1fr;font-size:11px;}
}
</style>

<div class="imp">
  <div class="imp-hd">
    <div>
      <h2>SSP Imprest · Company View</h2>
      <div class="imp-hd-sub">Where every shilling is, right now · v4.20.4 · Imprest model active since v4.20.3</div>
    </div>
  </div>

  <div class="imp-tabs">
    <a class="imp-tab <?= $view==='position' ? 'active' : '' ?>" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'position'])) ?>">Daily Position</a>
    <a class="imp-tab <?= $view==='holders'  ? 'active' : '' ?>" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'holders'])) ?>">Imprest Holders</a>
    <a class="imp-tab <?= $view==='pnl'      ? 'active' : '' ?>" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'pnl'])) ?>">P&amp;L by Category</a>
    <a class="imp-tab <?= $view==='audit'    ? 'active' : '' ?>" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'audit'])) ?>">Audit &amp; Reconcile</a>
  </div>

<?php if ($view === 'position'): ?>
  <?php
  $totals = $svc->companyTotals();
  $f = $totals['today'];
  ?>

  <div class="imp-section">As of <?= htmlspecialchars($totals['as_of']) ?> · Total company SSP</div>
  <div class="imp-kpis">
    <div class="imp-kpi">
      <div class="imp-kpi-v"><?= $fmt($totals['main_till_balance']) ?></div>
      <div class="imp-kpi-l">SSP in Main Till</div>
      <div class="imp-kpi-h">Physical cash at the office cashbox (cb_ledger net SSP)</div>
    </div>
    <div class="imp-kpi">
      <div class="imp-kpi-v"><?= $fmt($totals['in_imprest']) ?></div>
      <div class="imp-kpi-l">SSP held by Staff</div>
      <div class="imp-kpi-h"><?= (int)$totals['imprest_holder_count'] ?> staff holding imprest · <?= (int)$totals['stale_holder_count'] ?> stale (&gt;30d)</div>
    </div>
    <div class="imp-kpi accent">
      <div class="imp-kpi-v"><?= $fmt($totals['total_company_ssp']) ?></div>
      <div class="imp-kpi-l">Total Company SSP</div>
      <div class="imp-kpi-h">Main till + all staff imprest balances</div>
    </div>
  </div>

  <div class="imp-section">Today's flow</div>
  <div class="imp-flow">
    <div class="imp-flow-cell out">
      <div class="imp-flow-l">Advances Issued</div>
      <div class="imp-flow-v"><?= $fmt($f['advances_issued']) ?></div>
    </div>
    <div class="imp-flow-cell out">
      <div class="imp-flow-l">Direct Expenses</div>
      <div class="imp-flow-v"><?= $fmt($f['direct_expenses']) ?></div>
    </div>
    <div class="imp-flow-cell out">
      <div class="imp-flow-l">Imprest Spent (approved)</div>
      <div class="imp-flow-v"><?= $fmt($f['imprest_expenses']) ?></div>
    </div>
    <div class="imp-flow-cell in">
      <div class="imp-flow-l">Returns Received</div>
      <div class="imp-flow-v"><?= $fmt($f['returns_received']) ?></div>
    </div>
  </div>

  <div class="imp-notes">
    <p><strong>How to read this:</strong> Main Till is the SSP that physically sits in the office cashbox. SSP held by Staff is what's been advanced to imprest holders (Aida, Diko, etc.) but not yet spent. Together they should equal the total SSP the company controls. If your physical count of either disagrees with the system, drill into Imprest Holders to find which staff member's count is off.</p>
    <p><strong>Today's flow:</strong> Advances Issued = SSP that left the till for an imprest holder (the till drops, total company SSP is unchanged). Direct Expenses = SSP that left the till and the company at the same time (paid a vendor directly). Imprest Spent = imprest cash that was finally classified as spent (the till is unchanged, total company SSP drops). Returns = imprest cash coming back to the till.</p>
  </div>

<?php elseif ($view === 'holders'): ?>
  <?php
  $holders = $svc->imprestHolders();
  $totalsBal     = array_sum(array_column($holders, 'balance'));
  $totalsAdv     = array_sum(array_column($holders, 'advances'));
  $totalsExp     = array_sum(array_column($holders, 'expenses'));
  ?>

  <div class="imp-section">Per-staff SSP imprest position · staff_ledger source</div>
  <?php if (empty($holders)): ?>
    <div class="imp-empty">No imprest activity recorded yet.</div>
  <?php else: ?>
    <table class="imp-table">
      <thead>
        <tr>
          <th>Staff member</th>
          <th>Advances received</th>
          <th>Spent (approved)</th>
          <th>Returned</th>
          <th>Net transfers</th>
          <th>Current balance</th>
          <th>Last activity</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($holders as $h):
            $netTransfer = $h['transfers_in'] - $h['transfers_out'];
            $isNeg = $h['balance'] < -0.5;
        ?>
        <tr>
          <td>
            <div class="imp-name"><a href="<?= htmlspecialchars($mkLink(['ssp_view'=>'holders','ssp_drill'=>$h['staff_id']])) ?>"><?= htmlspecialchars($h['staff_name']) ?></a></div>
            <div class="imp-sub"><?= (int)$h['movement_count'] ?> ledger entries</div>
          </td>
          <td><?= $fmt($h['advances']) ?></td>
          <td><?= $fmt($h['expenses']) ?></td>
          <td><?= $fmt($h['returns']) ?></td>
          <td><?= ($netTransfer >= 0 ? '+' : '') . $fmt($netTransfer) ?></td>
          <td><span class="imp-bal<?= $isNeg ? ' danger' : '' ?>"><?= $fmt($h['balance']) ?></span></td>
          <td>
            <?php if ($h['last_movement_at']): ?>
              <div style="font-variant-numeric:tabular-nums;"><?= htmlspecialchars($h['last_movement_at']) ?></div>
              <div class="imp-sub"><?= (int)$h['days_since_movement'] ?> days ago</div>
            <?php else: ?>
              <div class="imp-sub">never</div>
            <?php endif; ?>
          </td>
          <td><span class="imp-chip <?= htmlspecialchars($h['status']) ?>"><?= htmlspecialchars($h['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <tr class="tot">
          <td>Totals</td>
          <td><?= $fmt($totalsAdv) ?></td>
          <td><?= $fmt($totalsExp) ?></td>
          <td>—</td>
          <td>—</td>
          <td><span class="imp-bal"><?= $fmt($totalsBal) ?></span></td>
          <td colspan="2"></td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($drillStaffId > 0):
    $hist = $svc->holderHistory($drillStaffId, 200);
    $drillName = '';
    foreach ($holders as $hh) { if ($hh['staff_id'] === $drillStaffId) { $drillName = $hh['staff_name']; break; } }
  ?>
    <div class="imp-history">
      <h3><?= htmlspecialchars($drillName) ?> · SSP movement history (last 200 entries)</h3>
      <?php if (empty($hist)): ?>
        <div class="imp-empty">No history.</div>
      <?php else: ?>
        <?php foreach ($hist as $row): ?>
        <div class="imp-hist-row">
          <div class="imp-hist-date"><?= htmlspecialchars($row['date']) ?></div>
          <div class="imp-hist-dir <?= htmlspecialchars($row['direction']) ?>"><?= htmlspecialchars($row['direction']) ?></div>
          <div class="imp-hist-amt"><?= $fmt($row['ssp_amount']) ?> SSP</div>
          <div class="imp-hist-cat"><?= htmlspecialchars(str_replace('_',' ', $row['category'])) ?></div>
          <div class="imp-hist-desc"><?= htmlspecialchars($row['description'] ?: '—') ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="imp-notes">
    <p><strong>Status meanings:</strong> <span class="imp-chip fresh">FRESH</span> activity within 14 days · <span class="imp-chip stale">STALE</span> 14–30 days idle · <span class="imp-chip overdue">OVERDUE</span> &gt;30 days idle · <span class="imp-chip overdrawn">OVERDRAWN</span> negative balance (data bug or unreversed expense) · <span class="imp-chip zero">ZERO</span> settled.</p>
    <p><strong>Reconciling with physical cash:</strong> Ask the holder to count their SSP. If their count matches the Current balance column, that staff member is clean. If not, click their name to see every movement and find the discrepancy. Overdue holders should be brought in to settle — they're sitting on company cash too long.</p>
  </div>

<?php elseif ($view === 'pnl'): ?>
  <?php $pnl = $svc->pAndLByCategory($dateFrom, $dateTo); ?>

  <form method="get" class="imp-controls">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab" value="ssp_imprest">
    <input type="hidden" name="ssp_view" value="pnl">
    <label>From</label>
    <input type="date" name="ssp_from" value="<?= htmlspecialchars($dateFrom) ?>">
    <label>To</label>
    <input type="date" name="ssp_to" value="<?= htmlspecialchars($dateTo) ?>">
    <button type="submit" class="imp-btn">Apply</button>
    <a class="imp-btn ghost" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'pnl','ssp_from'=>date('Y-m-01'),'ssp_to'=>date('Y-m-d')])) ?>">This month</a>
    <a class="imp-btn ghost" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'pnl','ssp_from'=>date('Y-m-01', strtotime('-1 month')),'ssp_to'=>date('Y-m-t', strtotime('-1 month'))])) ?>">Last month</a>
    <a class="imp-btn ghost" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'pnl','ssp_from'=>date('Y-m-d', strtotime('-30 days')),'ssp_to'=>date('Y-m-d')])) ?>">Last 30 days</a>
  </form>

  <div class="imp-section">SSP P&amp;L · <?= htmlspecialchars($pnl['period']['from']) ?> → <?= htmlspecialchars($pnl['period']['to']) ?> · <?= (int)$pnl['period']['days'] ?> days</div>

  <?php if (empty($pnl['rows'])): ?>
    <div class="imp-empty">No SSP expenses recorded in this period.</div>
  <?php else: ?>
    <table class="imp-table">
      <thead>
        <tr>
          <th>Category</th>
          <th>Imprest-funded</th>
          <th>Direct payment</th>
          <th>Total SSP</th>
          <th>% of total</th>
        </tr>
      </thead>
      <tbody>
        <?php $grand = $pnl['totals']['grand_total']; ?>
        <?php foreach ($pnl['rows'] as $r):
          $pct = $grand > 0 ? round($r['total'] / $grand * 100, 1) : 0;
        ?>
        <tr>
          <td><div class="imp-name"><?= htmlspecialchars($r['category_label']) ?></div></td>
          <td><?= $fmt($r['from_imprest']) ?></td>
          <td><?= $fmt($r['from_direct']) ?></td>
          <td><span class="imp-bal"><?= $fmt($r['total']) ?></span></td>
          <td><?= $pct ?>%</td>
        </tr>
        <?php endforeach; ?>
        <tr class="tot">
          <td>Totals</td>
          <td><?= $fmt($pnl['totals']['imprest_total']) ?></td>
          <td><?= $fmt($pnl['totals']['direct_total']) ?></td>
          <td><span class="imp-bal"><?= $fmt($pnl['totals']['grand_total']) ?></span></td>
          <td>100%</td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="imp-notes">
    <?php foreach ($pnl['notes'] as $n): ?>
    <p><?= htmlspecialchars($n) ?></p>
    <?php endforeach; ?>
    <p><strong>For the accountant:</strong> The Total SSP column is the company's actual SSP expense for each category in this period — what hits the P&amp;L. Imprest-funded and Direct payment are just the two channels through which the cash flowed. Use the Total column when posting to Tally or any external book; the split is for internal audit only.</p>
  </div>

<?php elseif ($view === 'audit'): ?>
  <?php
  $advAudit = $svc->auditAdvanceTotals();
  $expAudit = $svc->auditExpenseTotals();
  $rec      = $svc->auditCompanyReconciliation();

  // Aggregate flags for the header summary
  $advMismatchCount = 0; foreach ($advAudit as $r) if ($r['status'] !== 'match') $advMismatchCount++;
  $expDoubleCount   = 0; foreach ($expAudit as $r) if ($r['status'] === 'double_duplicate') $expDoubleCount++;
  $expSingleCount   = 0; foreach ($expAudit as $r) if ($r['status'] === 'single_duplicate') $expSingleCount++;
  ?>

  <div class="imp-section">Reconciliation summary · as of <?= htmlspecialchars($rec['as_of']) ?></div>

  <div class="imp-kpis">
    <div class="imp-kpi">
      <div class="imp-kpi-v"><?= $fmt($rec['cb_ledger_ssp_balance']) ?></div>
      <div class="imp-kpi-l">cb_ledger SSP balance</div>
      <div class="imp-kpi-h">What the system thinks the main till has, raw</div>
    </div>
    <div class="imp-kpi">
      <div class="imp-kpi-v"><?= $fmt($rec['total_legacy_duplicates']) ?></div>
      <div class="imp-kpi-l">Legacy duplicates</div>
      <div class="imp-kpi-h">Total phantom outflow from the pre-v4.20.3 bug</div>
    </div>
    <div class="imp-kpi accent">
      <div class="imp-kpi-v"><?= $fmt($rec['reconstructed_company_ssp']) ?></div>
      <div class="imp-kpi-l">Reconstructed Company SSP</div>
      <div class="imp-kpi-h">What physical SSP cash count should match on cutover day</div>
    </div>
  </div>

  <div class="imp-notes">
    <p><strong>How to use this number:</strong> On install/cutover day, count all physical SSP cash — both in the main till and in every staff member's hand. Total it. Compare against <strong>Reconstructed Company SSP</strong> above. If they match (within a small tolerance), the books reconcile and you can post a single Tally journal entry for the difference between cb_ledger SSP balance and reconstructed_main_till. If they don't match, the gap is real cash leakage independent of the bug — investigate the staff with the largest activity in the holders view first.</p>
  </div>

  <div class="imp-section">Section 1 · Per-staff advance reconciliation
    <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:<?= $advMismatchCount > 0 ? '#DC2626' : '#15803D' ?>;">
      <?= $advMismatchCount > 0 ? "⚠ {$advMismatchCount} mismatch(es)" : '✓ All match' ?>
    </span>
  </div>

  <?php if (empty($advAudit)): ?>
    <div class="imp-empty">No advance history found.</div>
  <?php else: ?>
    <table class="imp-table">
      <thead>
        <tr>
          <th>Staff member</th>
          <th>cb_ledger advance</th>
          <th>(rows)</th>
          <th>staff_ledger advance</th>
          <th>(rows)</th>
          <th>Diff</th>
          <th>Link method</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($advAudit as $r):
          $isMatch = $r['status'] === 'match';
          $statusClass = $isMatch ? 'fresh' : ($r['link_method'] === 'name_fuzzy' ? 'stale' : 'overdrawn');
        ?>
        <tr>
          <td><div class="imp-name"><?= htmlspecialchars($r['staff_name']) ?></div><div class="imp-sub">retailer #<?= (int)$r['staff_id'] ?></div></td>
          <td><?= $fmt($r['cb_ledger_total']) ?></td>
          <td><div class="imp-sub"><?= (int)$r['cb_ledger_rows'] ?></div></td>
          <td><?= $fmt($r['staff_ledger_total']) ?></td>
          <td><div class="imp-sub"><?= (int)$r['staff_ledger_rows'] ?></div></td>
          <td><span class="imp-bal<?= $r['diff'] != 0 ? ' danger' : '' ?>"><?= $r['diff'] > 0 ? '+' : '' ?><?= $fmt($r['diff']) ?></span></td>
          <td><div class="imp-sub" style="font-family:monospace;"><?= htmlspecialchars($r['link_method']) ?></div></td>
          <td><span class="imp-chip <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars(str_replace('_',' ',$r['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="imp-notes">
    <p><strong>Reading this section:</strong> Each row compares what the main cashbook thinks was given to a staff member as an SSP advance against what the staff register thinks they received. If these numbers match, the dual-write between cb_ledger and staff_ledger is intact for that staff. <code>cb_higher</code> means cb_ledger has more advance entries than staff_ledger — usually a missed dual-write (rare, but worth investigating). <code>sl_higher</code> means staff_ledger received an advance that cb_ledger doesn't show — even rarer.</p>
    <p><strong>Link method:</strong> <code>ssp_register</code> is the most reliable (joins via cb_ssp_register, post-v4.9.18 advances). <code>cash_with_id</code> uses the v4.11.3+ retailer-id column. <code>name_exact</code> and <code>name_fuzzy</code> are best-effort matches from the cb_ledger person field; if you see a fuzzy match where the diff is non-zero, the mismatch may be a name-resolution false positive rather than a real bug — verify by looking at the actual rows.</p>
  </div>

  <div class="imp-section" style="margin-top:28px;">Section 2 · Per-staff expense duplicate detection
    <span style="float:right;font-weight:500;text-transform:none;letter-spacing:0;color:#94a3b8;">
      <?= $expDoubleCount ?> double-duplicate · <?= $expSingleCount ?> single-duplicate
    </span>
  </div>

  <?php if (empty($expAudit)): ?>
    <div class="imp-empty">No advance-linked SSP expenses found.</div>
  <?php else: ?>
    <table class="imp-table">
      <thead>
        <tr>
          <th>Staff member</th>
          <th>staff_expenses approved</th>
          <th>(rows)</th>
          <th>cb_ledger duplicate</th>
          <th>(rows)</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php $totExp = 0; $totDup = 0; ?>
        <?php foreach ($expAudit as $r):
          $totExp += $r['expense_total']; $totDup += $r['cb_duplicate_total'];
          $sClass = ['clean'=>'zero','single_duplicate'=>'overdue','double_duplicate'=>'overdrawn','partial_duplicates'=>'stale'][$r['status']] ?? 'zero';
        ?>
        <tr>
          <td><div class="imp-name"><?= htmlspecialchars($r['staff_name']) ?></div><div class="imp-sub">retailer #<?= (int)$r['staff_id'] ?></div></td>
          <td><?= $fmt($r['expense_total']) ?></td>
          <td><div class="imp-sub"><?= (int)$r['expense_rows'] ?></div></td>
          <td><span class="imp-bal<?= $r['cb_duplicate_total'] > 0 ? ' danger' : '' ?>"><?= $fmt($r['cb_duplicate_total']) ?></span></td>
          <td><div class="imp-sub"><?= (int)$r['cb_duplicate_rows'] ?></div></td>
          <td><span class="imp-chip <?= htmlspecialchars($sClass) ?>"><?= htmlspecialchars(str_replace('_',' ',$r['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
        <tr class="tot">
          <td>Totals</td>
          <td><?= $fmt($totExp) ?></td>
          <td>—</td>
          <td><span class="imp-bal"><?= $fmt($totDup) ?></span></td>
          <td>—</td>
          <td></td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="imp-notes">
    <p><strong>Reading this section:</strong> <code>staff_expenses approved</code> is the real per-staff total of approved SSP expenses paid from imprest — the source of truth for what was spent. <code>cb_ledger duplicate</code> is how much of that ALSO got posted to the main cashbook before v4.20.3 (the bug). After v4.20.3 installs, <strong>any new advance-linked SSP expense will increment the staff_expenses column but not the cb_ledger column</strong>, so for fresh activity you'll see expense_total grow while cb_duplicate stays flat — that's the fix working.</p>
    <p><strong>Status meanings:</strong> <span class="imp-chip zero">CLEAN</span> no duplicates posted (post-cutover ideal) · <span class="imp-chip overdue">SINGLE DUPLICATE</span> each expense was posted once to cb_ledger (legacy single-source bug) · <span class="imp-chip overdrawn">DOUBLE DUPLICATE</span> each expense was posted twice (both ExpenseAdvanceService AND mergeExpenseToLedger fired — worst case) · <span class="imp-chip stale">PARTIAL</span> some expenses duplicated, others not.</p>
    <p><strong>Total cb_ledger duplicate</strong> = the total phantom outflow from the bug. This number is what makes the main cashbook show less SSP than physically exists.</p>
  </div>

  <div style="margin-top:24px;">
    <a class="imp-btn" href="<?= htmlspecialchars($mkLink(['ssp_view'=>'audit','ssp_export'=>'csv'])) ?>">⬇ Download full audit (CSV)</a>
  </div>

<?php endif; ?>

</div>
