<?php
// ── HRM Dashboard — DishNet Hybrid v4.11.0 ──────────────────────────────
// Overview: headcount, payroll status, monthly payroll cost, leave pending
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

require_once __DIR__ . '/../../lib/HrmService.php';
require_once __DIR__ . '/../../lib/PayrollService.php';
require_once __DIR__ . '/../../lib/LeaveService.php';
require_once __DIR__ . '/../../lib/ExpenseAdvanceService.php';
require_once __DIR__ . '/../../lib/CashbookService.php';

$pdo = $store->getPdo();
$hrm   = new HrmService($store, $pdo);
$leave = new LeaveService($store, $pdo);

$isAcct = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);
if (!$isAcct) { echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>'; return; }

// Check if seeded
$empCount = (int)$pdo->query("SELECT COUNT(*) FROM hrm_employees")->fetchColumn();
$seedMsg = '';
$bkMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['hrm_action'] ?? '') === 'seed') {
    $r1 = $hrm->seedFromRetailers();
    $r2 = $hrm->seedSalaryFromCashbook();
    $seedMsg = "Seeded {$r1['created']} employees, {$r2['components_set']} salary components.";
    $empCount = (int)$pdo->query("SELECT COUNT(*) FROM hrm_employees")->fetchColumn();
}

// BookKeeper baked data — auto-seed on first load, or manual re-import
$_pluginRoot = realpath(__DIR__ . '/../..');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['hrm_action'] ?? '') === 'reimport_bookkeeper') {
    $bkResult = $hrm->seedFromBakedJson($_pluginRoot);
    if ($bkResult['ok'] ?? false) {
        $unmatched = !empty($bkResult['unmatched']) ? ' Unmatched: ' . implode(', ', array_slice($bkResult['unmatched'], 0, 10)) : '';
        $bkMsg = "✅ Imported {$bkResult['vouchers_imported']} vouchers. " .
                 "{$bkResult['employees_matched']}/{$bkResult['employees_found']} employees matched." . $unmatched;
    } else {
        $bkMsg = "❌ " . ($bkResult['error'] ?? 'Unknown error');
    }
}

// SUDAN EDITION: the automatic import is removed.
//
// This previously imported bk_employee_seed.json into an empty
// hrm_financial_history the first time anyone opened this page -- 904 South
// Sudan accounting records pulled in by merely looking at the dashboard, with
// nobody choosing it. That file does not ship in this edition.
//
// The manual re-import above is kept, and HrmService::seedFromBakedJson()
// remains: importing a bookkeeping export is real functionality. It now runs
// only when an administrator asks for it.

$stats = $hrm->getStats();
$pendingLeave = $leave->pendingCount();

// Latest payroll period
$latestPeriod = $pdo->query("SELECT * FROM hrm_payroll_periods ORDER BY period DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Outstanding advances per active employee
$cb  = new CashbookService($store, $dataDir);
$adv = new ExpenseAdvanceService($store, $dataDir);
$_advStaff = [];
$_advTotalUsd = 0;
$_advTotalSsp = 0;
$_activeEmps = $hrm->listEmployees('active');
foreach ($_activeEmps as $_emp) {
    $rid = (int)$_emp['retailer_id'];
    $summary = $adv->getStaffSummary($rid);
    $usdBal = (float)($summary['by_currency']['USD']['balance'] ?? 0);
    $sspBal = (float)($summary['by_currency']['SSP']['balance'] ?? 0);
    $pendCnt = (int)($summary['pending_expenses'] ?? 0);
    if ($usdBal > 0 || $sspBal > 0 || $pendCnt > 0) {
        $_advStaff[] = [
            'name'      => $_emp['name'],
            'dept'      => $_emp['department'] ?? '',
            'usd'       => $usdBal,
            'ssp'       => $sspBal,
            'pending'   => $pendCnt,
            'pend_usd'  => (float)($summary['by_currency']['USD']['pending'] ?? 0),
            'pend_ssp'  => (float)($summary['by_currency']['SSP']['pending'] ?? 0),
        ];
        $_advTotalUsd += $usdBal;
        $_advTotalSsp += $sspBal;
    }
}
usort($_advStaff, function($a, $b) { return ($b['usd'] + $b['ssp']) <=> ($a['usd'] + $a['ssp']); });

// BookKeeper imported data
$_bkCount = 0;
$_bkLoans = [];
try {
    $_bkCount = (int)$pdo->query("SELECT COUNT(*) FROM hrm_financial_history WHERE source='bookkeeper'")->fetchColumn();
    $_bkLoans = $hrm->getAllLoanSummaries();
} catch (\Throwable $e) { /* table may not exist yet */ }

$deptColors = [
    'operations'=>'#3B82F6','finance'=>'#10B981','security'=>'#F59E0B',
    'household'=>'#EC4899','tech'=>'#8B5CF6','management'=>'#EF4444',
    'sales'=>'#06B6D4','support'=>'#F97316',
];
?>

<style>
.hrm-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin:16px 0}
.hrm-card{background:var(--bg-card,#fff);border:1px solid #e5e7eb;border-radius:12px;padding:18px;text-align:center}
.hrm-card h3{font-size:28px;margin:0;font-weight:700}
.hrm-card p{font-size:12px;color:#6b7280;margin:4px 0 0}
.hrm-card.blue h3{color:#2563eb}.hrm-card.green h3{color:#059669}
.hrm-card.amber h3{color:#d97706}.hrm-card.purple h3{color:#7c3aed}
.hrm-card.red h3{color:#dc2626}
.adv-tbl{width:100%;border-collapse:collapse;font-size:13px;margin:8px 0}
.adv-tbl th{text-align:left;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;padding:6px 8px;border-bottom:2px solid #e5e7eb}
.adv-tbl td{padding:8px;border-bottom:1px solid #f3f4f6}
.adv-tbl tr:hover{background:#f9fafb}
.adv-cur{font-family:monospace;font-weight:600;text-align:right}
.adv-warn{color:#dc2626}.adv-ok{color:#6b7280}
.dept-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin:12px 0}
.dept-pill{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb}
.dept-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.dept-name{font-size:13px;color:#374151;flex:1;text-transform:capitalize}
.dept-cnt{font-size:15px;font-weight:700;color:#111}
.hrm-section{margin:20px 0;padding:16px;background:#fff;border:1px solid #e5e7eb;border-radius:12px}
.hrm-section h2{font-size:15px;font-weight:600;margin:0 0 12px;color:#374151}
.payroll-status{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;text-transform:uppercase}
.payroll-status.draft{background:#FEF3C7;color:#92400E}
.payroll-status.calculated{background:#DBEAFE;color:#1E40AF}
.payroll-status.approved{background:#D1FAE5;color:#065F46}
.payroll-status.closed{background:#E5E7EB;color:#374151}
.seed-box{background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:20px;text-align:center;margin:20px 0}
.seed-box p{color:#92400E;font-size:13px;margin:6px 0}
.btn-seed{background:#D97706;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-weight:600;cursor:pointer;font-size:14px}
.btn-seed:hover{background:#B45309}
.nav-pills{display:flex;gap:8px;margin:0 0 16px;flex-wrap:wrap}
.nav-pill{padding:6px 16px;border-radius:20px;font-size:13px;font-weight:500;text-decoration:none;border:1px solid #d1d5db;color:#374151}
.nav-pill.active{background:#2563eb;color:#fff;border-color:#2563eb}
</style>

<div class="nav-pills">
    <a href="?page=dashboard&tab=hrm_dashboard" class="nav-pill active">Dashboard</a>
    <a href="?page=dashboard&tab=hrm_employees" class="nav-pill">Employees</a>
    <a href="?page=dashboard&tab=hrm_payroll" class="nav-pill">Payroll</a>
    <a href="?page=dashboard&tab=hrm_leave" class="nav-pill">Leave<?= $pendingLeave ? " ({$pendingLeave})" : '' ?></a>
</div>

<?php if ($seedMsg): ?>
<div style="background:#D1FAE5;border:1px solid #6EE7B7;border-radius:8px;padding:12px;margin:0 0 14px;color:#065F46;font-size:13px;">
    ✅ <?= htmlspecialchars($seedMsg) ?>
</div>
<?php endif; ?>

<?php if ($bkMsg): ?>
<div style="background:<?= strpos($bkMsg, '✅') !== false ? '#D1FAE5;border:1px solid #6EE7B7;color:#065F46' : '#FEE2E2;border:1px solid #FECACA;color:#991B1B' ?>;border-radius:8px;padding:12px;margin:0 0 14px;font-size:13px;">
    <?= $bkMsg ?>
</div>
<?php endif; ?>

<?php if ($empCount === 0): ?>
<div class="seed-box">
    <h3 style="margin:0;font-size:18px;color:#92400E;">👥 No Employees Imported Yet</h3>
    <p>Click below to import your staff from the retailer list and estimate salary structures from cashbook history.</p>
    <form method="POST" style="margin-top:12px;">
        <?= csrfField() ?>
        <input type="hidden" name="hrm_action" value="seed">
        <button type="submit" class="btn-seed">Import Employees from Retailer List</button>
    </form>
</div>
<?php else: ?>

<div class="hrm-cards">
    <div class="hrm-card blue">
        <h3><?= $stats['total_active'] ?></h3>
        <p>Active Employees</p>
    </div>
    <div class="hrm-card green">
        <h3>$<?= number_format($stats['monthly_payroll_est'], 0) ?></h3>
        <p>Monthly Payroll (Est.)</p>
    </div>
    <div class="hrm-card amber">
        <h3><?= $pendingLeave ?></h3>
        <p>Pending Leave Requests</p>
    </div>
    <div class="hrm-card purple">
        <h3><?= $latestPeriod ? ucfirst($latestPeriod['status']) : 'None' ?></h3>
        <p>Latest Payroll: <?= $latestPeriod['period'] ?? '—' ?></p>
    </div>
    <?php if ($_advTotalUsd > 0 || $_advTotalSsp > 0): ?>
    <div class="hrm-card red">
        <h3><?= count($_advStaff) ?></h3>
        <p>Staff with Open Advances</p>
    </div>
    <?php endif; ?>
</div>

<div class="hrm-section">
    <h2>Departments</h2>
    <div class="dept-grid">
        <?php foreach ($stats['by_department'] as $d): $c = $deptColors[$d['department']] ?? '#6B7280'; ?>
        <div class="dept-pill">
            <div class="dept-dot" style="background:<?= $c ?>"></div>
            <span class="dept-name"><?= htmlspecialchars($d['department']) ?></span>
            <span class="dept-cnt"><?= $d['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($latestPeriod): ?>
<div class="hrm-section">
    <h2>Latest Payroll — <?= htmlspecialchars($latestPeriod['period']) ?></h2>
    <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
        <div><span class="payroll-status <?= $latestPeriod['status'] ?>"><?= strtoupper($latestPeriod['status']) ?></span></div>
        <div><strong>Gross:</strong> $<?= number_format((float)$latestPeriod['total_gross'], 2) ?></div>
        <div><strong>Net:</strong> $<?= number_format((float)$latestPeriod['total_net'], 2) ?></div>
        <div><strong>Disbursed:</strong> $<?= number_format((float)$latestPeriod['total_disbursed'], 2) ?></div>
        <div><strong>Staff:</strong> <?= $latestPeriod['employee_count'] ?></div>
        <a href="?page=dashboard&tab=hrm_payroll&period=<?= urlencode($latestPeriod['period']) ?>" style="color:#2563eb;font-size:13px;">View details →</a>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($_advStaff)): ?>
<div class="hrm-section">
    <h2>💸 Outstanding Advances & Loans</h2>
    <div style="display:flex;gap:20px;margin-bottom:12px;flex-wrap:wrap;">
        <?php if ($_advTotalUsd > 0): ?><div style="font-size:13px;"><strong>Total USD:</strong> <span style="color:#dc2626;font-weight:700;">$<?= number_format($_advTotalUsd, 2) ?></span></div><?php endif; ?>
        <?php if ($_advTotalSsp > 0): ?><div style="font-size:13px;"><strong>Total SSP:</strong> <span style="color:#dc2626;font-weight:700;"><?= number_format($_advTotalSsp, 0) ?> SSP</span></div><?php endif; ?>
    </div>
    <div style="overflow-x:auto;">
    <table class="adv-tbl">
        <thead><tr>
            <th>Employee</th><th>Department</th>
            <th style="text-align:right;">USD Balance</th>
            <th style="text-align:right;">SSP Balance</th>
            <th style="text-align:right;">Pending Claims</th>
        </tr></thead>
        <tbody>
        <?php foreach ($_advStaff as $_as): ?>
        <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($_as['name']) ?></td>
            <td style="text-transform:capitalize;color:#6b7280;"><?= htmlspecialchars($_as['dept']) ?></td>
            <td class="adv-cur <?= $_as['usd'] > 0 ? 'adv-warn' : 'adv-ok' ?>"><?= $_as['usd'] > 0 ? '$'.number_format($_as['usd'], 2) : '—' ?></td>
            <td class="adv-cur <?= $_as['ssp'] > 0 ? 'adv-warn' : 'adv-ok' ?>"><?= $_as['ssp'] > 0 ? number_format($_as['ssp'], 0).' SSP' : '—' ?></td>
            <td style="text-align:right;"><?php if ($_as['pending'] > 0): ?><span style="background:#FEF3C7;color:#92400E;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;"><?= $_as['pending'] ?> pending</span><?php else: ?>—<?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($_bkLoans)): ?>
<div class="hrm-section">
    <h2>📚 BookKeeper Loan Balances (Historical)</h2>
    <p style="font-size:12px;color:#6b7280;margin:-6px 0 12px;">Imported from BookKeeper accounting records (2019–2025)</p>
    <div style="overflow-x:auto;">
    <table class="adv-tbl">
        <thead><tr>
            <th>Employee</th>
            <th style="text-align:right;">Total Given</th>
            <th style="text-align:right;">Total Repaid</th>
            <th style="text-align:right;">Balance Owed</th>
            <th>Last Transaction</th>
        </tr></thead>
        <tbody>
        <?php $_bkLoanTotal = 0; foreach ($_bkLoans as $_bl): $_bkLoanTotal += (float)$_bl['balance']; ?>
        <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($_bl['employee_name']) ?></td>
            <td style="text-align:right;font-family:monospace;">$<?= number_format((float)$_bl['total_given'], 2) ?></td>
            <td style="text-align:right;font-family:monospace;color:#059669;">$<?= number_format((float)$_bl['total_repaid'], 2) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $_bl['balance'] > 0 ? '#dc2626' : '#059669' ?>;">$<?= number_format((float)$_bl['balance'], 2) ?></td>
            <td style="color:#6b7280;font-size:12px;"><?= $_bl['last_txn_date'] ? date('d M Y', strtotime($_bl['last_txn_date'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:2px solid #1E293B;font-weight:700;">
            <td>TOTAL</td><td></td><td></td>
            <td style="text-align:right;font-family:monospace;color:#dc2626;">$<?= number_format($_bkLoanTotal, 2) ?></td>
            <td></td>
        </tr>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- BookKeeper Data (baked in) -->
<?php if ($_bkCount > 0): ?>
<div class="hrm-section" style="border-color:#C084FC;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div>
            <h2 style="margin:0;">📚 BookKeeper Historical Data</h2>
            <p style="font-size:12px;color:#6b7280;margin:4px 0 0;"><?= number_format($_bkCount) ?> records from 2019–2026 • Auto-imported from BookKeeper accounting backup</p>
        </div>
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="hrm_action" value="reimport_bookkeeper">
            <button type="submit" style="background:#7C3AED;color:#fff;border:none;padding:6px 16px;border-radius:6px;font-weight:600;font-size:12px;cursor:pointer;"
                    onclick="return confirm('Re-import BookKeeper data? This will refresh employee matching.')">
                🔄 Re-import
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>
