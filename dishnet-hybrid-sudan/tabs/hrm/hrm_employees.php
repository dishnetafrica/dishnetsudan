<?php
// ── HRM Employees — DishNet Hybrid v4.11.0 ──────────────────────────────
// Employee master list with profile editor and salary structure setup.
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

require_once __DIR__ . '/../../lib/HrmService.php';
require_once __DIR__ . '/../../lib/LeaveService.php';
require_once __DIR__ . '/../../lib/ExpenseAdvanceService.php';
require_once __DIR__ . '/../../lib/CashbookService.php';

$pdo = $store->getPdo();
$hrm   = new HrmService($store, $pdo);
$leave = new LeaveService($store, $pdo);
$_cb   = new CashbookService($store, $dataDir);
$_adv  = new ExpenseAdvanceService($store, $dataDir);

$isAcct = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);
if (!$isAcct) { echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>'; return; }

// POST: update profile
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hAct = $_POST['hrm_action'] ?? '';
    if ($hAct === 'update_profile') {
        $res = $hrm->upsertProfile((int)$_POST['retailer_id'], $_POST);
        $msg = $res['ok'] ? 'Profile updated.' : ($res['error'] ?? 'Error');
    }
    if ($hAct === 'update_salary') {
        $components = [];
        foreach (HrmService::COMPONENTS as $key => $label) {
            $val = (float)($_POST[$key] ?? 0);
            if ($val > 0) $components[$key] = $val;
        }
        $from = $_POST['effective_from'] ?? date('Y-m-01');
        $res = $hrm->setSalaryStructure((int)$_POST['retailer_id'], $components, $from);
        $msg = $res['ok'] ? "Salary updated: {$res['components_set']} components." : 'Error';
    }
}

// Filters
$fDept   = $_GET['dept'] ?? '';
$fStatus = $_GET['emp_status'] ?? 'active';
$employees = $hrm->listEmployees($fStatus, $fDept);

// Selected employee for detail view
$selId = (int)($_GET['emp'] ?? 0);
$selProfile = $selId ? $hrm->getProfile($selId) : null;
$selSalary  = $selId ? $hrm->getSalaryStructure($selId) : [];
$selLeave   = $selId ? $leave->getBalances($selId) : [];

$pendingLeave = $leave->pendingCount();
?>

<style>
.nav-pills{display:flex;gap:8px;margin:0 0 16px;flex-wrap:wrap}
.nav-pill{padding:6px 16px;border-radius:20px;font-size:13px;font-weight:500;text-decoration:none;border:1px solid #d1d5db;color:#374151}
.nav-pill.active{background:#2563eb;color:#fff;border-color:#2563eb}
.emp-grid{display:grid;grid-template-columns:280px 1fr;gap:16px}
@media(max-width:768px){.emp-grid{grid-template-columns:1fr}}
.emp-list{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;max-height:75vh;overflow-y:auto}
.emp-item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f3f4f6;cursor:pointer;text-decoration:none;color:inherit}
.emp-item:hover{background:#f9fafb}
.emp-item.sel{background:#EFF6FF;border-left:3px solid #2563eb}
.emp-av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}
.emp-info{flex:1;min-width:0}
.emp-name{font-size:13px;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.emp-role{font-size:11px;color:#6b7280}
.emp-sal{font-size:12px;font-weight:600;color:#059669}
.detail{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px}
.detail h2{font-size:16px;font-weight:600;margin:0 0 16px;color:#374151}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}
.fg label{font-size:11px;font-weight:600;color:#6b7280;display:block;margin:0 0 3px;text-transform:uppercase;letter-spacing:.5px}
.fg input,.fg select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box}
.fg input:focus,.fg select:focus{outline:none;border-color:#3b82f6}
.btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer}
.btn-p{background:#2563eb;color:#fff}.btn-p:hover{background:#1d4ed8}
.sal-row{display:flex;align-items:center;gap:10px;margin:6px 0}
.sal-row label{width:140px;font-size:12px;font-weight:600;color:#374151}
.sal-row input{flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px}
.sal-row .cur{font-size:12px;color:#6b7280;width:30px}
.leave-pills{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
.leave-pill{padding:6px 12px;border-radius:8px;font-size:12px;font-weight:500;border:1px solid}
.emp-filter{display:flex;gap:8px;margin:0 0 10px;padding:8px 12px}
.emp-filter select{padding:4px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px}
.success-msg{background:#D1FAE5;border:1px solid #6EE7B7;border-radius:8px;padding:8px 14px;color:#065F46;font-size:13px;margin:0 0 12px}
</style>

<div class="nav-pills">
    <a href="?page=dashboard&tab=hrm_dashboard" class="nav-pill">Dashboard</a>
    <a href="?page=dashboard&tab=hrm_employees" class="nav-pill active">Employees</a>
    <a href="?page=dashboard&tab=hrm_payroll" class="nav-pill">Payroll</a>
    <a href="?page=dashboard&tab=hrm_leave" class="nav-pill">Leave<?= $pendingLeave ? " ({$pendingLeave})" : '' ?></a>
</div>

<?php if ($msg): ?><div class="success-msg">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="emp-grid">
    <div>
        <div class="emp-filter">
            <select onchange="location='?page=dashboard&tab=hrm_employees&emp_status='+this.value+'&dept=<?= urlencode($fDept) ?>'">
                <option value="active" <?= $fStatus==='active'?'selected':'' ?>>Active</option>
                <option value="" <?= $fStatus===''?'selected':'' ?>>All</option>
                <option value="terminated" <?= $fStatus==='terminated'?'selected':'' ?>>Terminated</option>
            </select>
            <select onchange="location='?page=dashboard&tab=hrm_employees&dept='+this.value+'&emp_status=<?= urlencode($fStatus) ?>'">
                <option value="">All Depts</option>
                <?php foreach (HrmService::DEPARTMENTS as $d): ?>
                <option value="<?= $d ?>" <?= $fDept===$d?'selected':'' ?>><?= ucfirst($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="emp-list">
            <?php if (empty($employees)): ?>
            <div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px;">No employees found. <a href="?page=dashboard&tab=hrm_dashboard">Seed from dashboard</a>.</div>
            <?php endif; ?>
            <?php
            $avColors = ['#2563EB','#059669','#D97706','#DC2626','#7C3AED','#0891B2','#DB2777','#4F46E5'];
            foreach ($employees as $i => $e):
                $ac = $avColors[$i % count($avColors)];
                $initials = implode('', array_map(function($w){ return strtoupper($w[0] ?? ''); }, array_slice(explode(' ', $e['name'] ?? '?'), 0, 2)));
                $isSel = $selId === (int)$e['retailer_id'];
            ?>
            <a href="?page=dashboard&tab=hrm_employees&emp=<?= $e['retailer_id'] ?>&emp_status=<?= urlencode($fStatus) ?>&dept=<?= urlencode($fDept) ?>" class="emp-item <?= $isSel?'sel':'' ?>">
                <div class="emp-av" style="background:<?= $ac ?>"><?= $initials ?></div>
                <div class="emp-info">
                    <div class="emp-name"><?= htmlspecialchars($e['name'] ?? '') ?></div>
                    <div class="emp-role"><?= htmlspecialchars($e['employee_code'] ?? '') ?> · <?= htmlspecialchars(ucfirst($e['department'] ?? '')) ?></div>
                </div>
                <div class="emp-sal"><?= $e['gross_salary'] > 0 ? '$'.number_format($e['gross_salary'], 0) : '—' ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="detail">
        <?php if (!$selProfile): ?>
        <div style="text-align:center;padding:60px 20px;color:#9ca3af;">
            <div style="font-size:48px;margin:0 0 12px;">👤</div>
            <p>Select an employee from the list</p>
        </div>
        <?php else: ?>
        <h2><?= htmlspecialchars($selProfile['name'] ?? '') ?> <span style="font-weight:400;font-size:13px;color:#6b7280;">— <?= htmlspecialchars($selProfile['employee_code'] ?? '') ?></span></h2>

        <!-- Profile Form -->
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="hrm_action" value="update_profile">
            <input type="hidden" name="retailer_id" value="<?= $selId ?>">
            <div class="form-grid">
                <div class="fg"><label>Designation</label><input name="designation" value="<?= htmlspecialchars($selProfile['designation'] ?? '') ?>"></div>
                <div class="fg"><label>Department</label>
                    <select name="department">
                        <?php foreach (HrmService::DEPARTMENTS as $d): ?>
                        <option value="<?= $d ?>" <?= ($selProfile['department'] ?? '')===$d?'selected':'' ?>><?= ucfirst($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg"><label>Employment Type</label>
                    <select name="employment_type">
                        <?php foreach (HrmService::EMPLOYMENT_TYPES as $t): ?>
                        <option value="<?= $t ?>" <?= ($selProfile['employment_type'] ?? '')===$t?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg"><label>Join Date</label><input type="date" name="join_date" value="<?= htmlspecialchars($selProfile['join_date'] ?? '') ?>"></div>
                <div class="fg"><label>Status</label>
                    <select name="status">
                        <?php foreach (['active','probation','suspended','terminated','resigned'] as $s): ?>
                        <option value="<?= $s ?>" <?= ($selProfile['status'] ?? '')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg"><label>Nationality</label><input name="nationality" value="<?= htmlspecialchars($selProfile['nationality'] ?? 'South Sudanese') ?>"></div>
                <div class="fg"><label>ID Type</label><input name="id_type" value="<?= htmlspecialchars($selProfile['id_type'] ?? '') ?>" placeholder="national_id, passport..."></div>
                <div class="fg"><label>ID Number</label><input name="id_number" value="<?= htmlspecialchars($selProfile['id_number'] ?? '') ?>"></div>
                <div class="fg"><label>Bank Name</label><input name="bank_name" value="<?= htmlspecialchars($selProfile['bank_name'] ?? '') ?>"></div>
                <div class="fg"><label>Bank Account</label><input name="bank_account" value="<?= htmlspecialchars($selProfile['bank_account'] ?? '') ?>"></div>
                <div class="fg"><label>Emergency Contact</label><input name="emergency_name" value="<?= htmlspecialchars($selProfile['emergency_name'] ?? '') ?>" placeholder="Name"></div>
                <div class="fg"><label>Emergency Phone</label><input name="emergency_phone" value="<?= htmlspecialchars($selProfile['emergency_phone'] ?? '') ?>"></div>
            </div>
            <div style="margin:12px 0"><button type="submit" class="btn btn-p">Save Profile</button></div>
        </form>

        <!-- Salary Structure -->
        <h2 style="margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px;">💰 Salary Structure</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="hrm_action" value="update_salary">
            <input type="hidden" name="retailer_id" value="<?= $selId ?>">
            <?php
            $salMap = [];
            foreach ($selSalary as $s) { $salMap[$s['component']] = (float)$s['amount']; }
            $grossTotal = 0;
            foreach (HrmService::COMPONENTS as $key => $label):
                $val = $salMap[$key] ?? 0;
                $grossTotal += $val;
            ?>
            <div class="sal-row">
                <label><?= $label ?></label>
                <span class="cur">$</span>
                <input type="number" name="<?= $key ?>" value="<?= $val > 0 ? $val : '' ?>" placeholder="0" step="0.01" min="0">
            </div>
            <?php endforeach; ?>
            <div class="sal-row" style="font-weight:700;border-top:1px solid #d1d5db;padding-top:8px;margin-top:8px;">
                <label>Gross Monthly</label>
                <span class="cur">$</span>
                <span style="font-size:16px;color:#059669"><?= number_format($grossTotal, 2) ?></span>
            </div>
            <div class="sal-row">
                <label>Effective From</label>
                <input type="date" name="effective_from" value="<?= date('Y-m-01') ?>" style="max-width:180px">
            </div>
            <div style="margin:12px 0"><button type="submit" class="btn btn-p">Update Salary</button></div>
        </form>

        <!-- Leave Balances -->
        <?php if (!empty($selLeave)): ?>
        <h2 style="margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px;">🗓️ Leave Balances (<?= date('Y') ?>)</h2>
        <div class="leave-pills">
            <?php foreach ($selLeave as $lb): if (!(int)$lb['entitlement'] && $lb['leave_type_code'] !== 'UL') continue; ?>
            <div class="leave-pill" style="border-color:<?= $lb['color'] ?>;color:<?= $lb['color'] ?>;">
                <?= htmlspecialchars($lb['leave_type_code']) ?>: <?= $lb['available'] ?>/<?= $lb['entitlement'] + $lb['carried'] ?> days
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Advances & Loans -->
        <?php
        $_empAdv = $_adv->getStaffSummary($selId);
        $_empAdvUsd = (float)($_empAdv['by_currency']['USD']['balance'] ?? 0);
        $_empAdvSsp = (float)($_empAdv['by_currency']['SSP']['balance'] ?? 0);
        $_empPendUsd = (float)($_empAdv['by_currency']['USD']['pending'] ?? 0);
        $_empPendSsp = (float)($_empAdv['by_currency']['SSP']['pending'] ?? 0);
        $_empPendCnt = (int)($_empAdv['pending_expenses'] ?? 0);
        $_hasAdvData = ($_empAdvUsd > 0 || $_empAdvSsp > 0 || $_empPendCnt > 0);
        // Fetch individual active advances
        $_empAdvList = $_adv->getAdvances(['recipient_id' => $selId, 'status' => 'active']);
        $_empAdvList2 = $_adv->getAdvances(['recipient_id' => $selId, 'status' => 'partial']);
        $_empAdvAll = array_merge($_empAdvList, $_empAdvList2);
        ?>
        <h2 style="margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px;">💸 Advances & Loans</h2>
        <?php if (!$_hasAdvData && empty($_empAdvAll)): ?>
        <p style="color:#6b7280;font-size:13px;">No outstanding advances.</p>
        <?php else: ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
            <?php if ($_empAdvUsd > 0): ?>
            <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 16px;font-size:13px;">
                <strong>USD Outstanding:</strong> <span style="color:#dc2626;font-weight:700;">$<?= number_format($_empAdvUsd, 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($_empAdvSsp > 0): ?>
            <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;padding:10px 16px;font-size:13px;">
                <strong>SSP Outstanding:</strong> <span style="color:#dc2626;font-weight:700;"><?= number_format($_empAdvSsp, 0) ?> SSP</span>
            </div>
            <?php endif; ?>
            <?php if ($_empPendCnt > 0): ?>
            <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:10px 16px;font-size:13px;">
                <strong>Pending Claims:</strong> <?= $_empPendCnt ?><?php if ($_empPendUsd > 0) echo ' ($'.number_format($_empPendUsd, 2).')'; ?><?php if ($_empPendSsp > 0) echo ' ('.number_format($_empPendSsp, 0).' SSP)'; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($_empAdvAll)): ?>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead><tr style="border-bottom:2px solid #e5e7eb;">
                <th style="text-align:left;padding:6px 8px;font-size:11px;font-weight:600;color:#6b7280;">Ref</th>
                <th style="text-align:left;padding:6px 8px;font-size:11px;font-weight:600;color:#6b7280;">Date</th>
                <th style="text-align:right;padding:6px 8px;font-size:11px;font-weight:600;color:#6b7280;">Amount</th>
                <th style="text-align:right;padding:6px 8px;font-size:11px;font-weight:600;color:#6b7280;">Remaining</th>
                <th style="text-align:left;padding:6px 8px;font-size:11px;font-weight:600;color:#6b7280;">Purpose</th>
            </tr></thead>
            <tbody>
            <?php foreach ($_empAdvAll as $_ea):
                $_eaBal = $_adv->computeAdvanceBalance((int)$_ea['id']);
                $_eaCur = strtoupper($_ea['currency'] ?? 'USD');
                $_eaSym = $_eaCur === 'SSP' ? '' : '$';
                $_eaSuf = $_eaCur === 'SSP' ? ' SSP' : '';
            ?>
            <tr style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:8px;font-family:monospace;font-weight:600;"><?= htmlspecialchars($_ea['advance_no'] ?? '') ?></td>
                <td style="padding:8px;color:#6b7280;"><?= date('d M Y', strtotime($_ea['created_at'] ?? '')) ?></td>
                <td style="padding:8px;text-align:right;font-weight:600;"><?= $_eaSym ?><?= number_format((float)$_ea['amount'], $_eaCur === 'SSP' ? 0 : 2) ?><?= $_eaSuf ?></td>
                <td style="padding:8px;text-align:right;font-weight:700;color:<?= $_eaBal > 0 ? '#dc2626' : '#059669' ?>;"><?= $_eaSym ?><?= number_format($_eaBal, $_eaCur === 'SSP' ? 0 : 2) ?><?= $_eaSuf ?></td>
                <td style="padding:8px;color:#374151;"><?= htmlspecialchars($_ea['purpose'] ?? $_ea['category'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php endif; ?>

        <!-- BookKeeper Historical Data -->
        <?php
        $_empComp = [];
        $_empLoan = null;
        $_empLoanLedger = [];
        try {
            $_empComp = $hrm->getCompensationBreakdown($selId);
            $_empLoan = $hrm->getLoanSummary($selId);
            if ($_empLoan) {
                $_empLoanLedger = $hrm->getLoanLedger($selId);
            }
        } catch (\Throwable $e) { /* tables may not exist */ }

        if (!empty($_empComp) || $_empLoan):
        ?>
        <h2 style="margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px;">📚 Historical Financial Record</h2>
        <p style="font-size:11px;color:#6b7280;margin:-4px 0 12px;">Source: BookKeeper (2019–2025)</p>

        <?php
        // Aggregate compensation totals
        $_compTotals = ['salary'=>0,'food'=>0,'transport'=>0,'commission'=>0,'bonus'=>0,'benefit'=>0,'housing'=>0];
        $_compByYear = [];
        foreach ($_empComp as $c) {
            $cat = $c['category'];
            $yr  = $c['year'];
            $paid = (float)$c['paid'];
            if (isset($_compTotals[$cat])) $_compTotals[$cat] += $paid;
            if (!isset($_compByYear[$yr])) $_compByYear[$yr] = ['salary'=>0,'food'=>0,'transport'=>0,'commission'=>0,'bonus'=>0,'benefit'=>0,'housing'=>0,'total'=>0];
            if (isset($_compByYear[$yr][$cat])) $_compByYear[$yr][$cat] += $paid;
            $_compByYear[$yr]['total'] += $paid;
        }
        krsort($_compByYear);
        $_compGrand = array_sum($_compTotals);
        ?>

        <?php if ($_compGrand > 0): ?>
        <!-- Total Compensation Cards -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
            <?php
            $catLabels = ['salary'=>['💰','Salary'], 'food'=>['🍽️','Food'], 'transport'=>['🚗','Transport'],
                          'commission'=>['📊','Commission'], 'bonus'=>['🎁','Bonus'], 'benefit'=>['🏥','Benefits'], 'housing'=>['🏠','Housing']];
            foreach ($_compTotals as $cat => $amt):
                if ($amt <= 0) continue;
                $l = $catLabels[$cat] ?? ['📦', ucfirst($cat)];
            ?>
            <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:10px 14px;min-width:110px;">
                <div style="font-size:11px;color:#6b7280;"><?= $l[0] ?> <?= $l[1] ?></div>
                <div style="font-size:16px;font-weight:700;color:#1E293B;">$<?= number_format($amt, 0) ?></div>
            </div>
            <?php endforeach; ?>
            <div style="background:#1E293B;border-radius:8px;padding:10px 14px;min-width:110px;">
                <div style="font-size:11px;color:#94A3B8;">Total Compensation</div>
                <div style="font-size:16px;font-weight:700;color:#fff;">$<?= number_format($_compGrand, 0) ?></div>
            </div>
        </div>

        <!-- Yearly Breakdown Table -->
        <div style="overflow-x:auto;margin-bottom:16px;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="border-bottom:2px solid #e5e7eb;">
                <th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;">Year</th>
                <th style="text-align:right;padding:6px 8px;font-weight:600;color:#6b7280;">Salary</th>
                <th style="text-align:right;padding:6px 8px;font-weight:600;color:#6b7280;">Food</th>
                <th style="text-align:right;padding:6px 8px;font-weight:600;color:#6b7280;">Transport</th>
                <th style="text-align:right;padding:6px 8px;font-weight:600;color:#6b7280;">Commission</th>
                <th style="text-align:right;padding:6px 8px;font-weight:600;color:#6b7280;">Other</th>
                <th style="text-align:right;padding:6px 8px;font-weight:700;color:#1E293B;">Total</th>
            </tr></thead>
            <tbody>
            <?php foreach ($_compByYear as $yr => $d): ?>
            <tr style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:6px 8px;font-weight:600;"><?= $yr ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;"><?= $d['salary'] > 0 ? '$'.number_format($d['salary'],0) : '—' ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;"><?= $d['food'] > 0 ? '$'.number_format($d['food'],0) : '—' ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;"><?= $d['transport'] > 0 ? '$'.number_format($d['transport'],0) : '—' ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;"><?= $d['commission'] > 0 ? '$'.number_format($d['commission'],0) : '—' ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;"><?= ($d['bonus']+$d['benefit']+$d['housing']) > 0 ? '$'.number_format($d['bonus']+$d['benefit']+$d['housing'],0) : '—' ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;font-weight:700;">$<?= number_format($d['total'],0) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <?php if ($_empLoan && (float)$_empLoan['balance'] != 0): ?>
        <!-- Loan Summary -->
        <div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:14px 18px;margin-bottom:12px;">
            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
                <div><span style="font-size:12px;color:#991B1B;">Loan Given:</span><br><strong style="font-size:18px;">$<?= number_format((float)$_empLoan['total_given'], 2) ?></strong></div>
                <div><span style="font-size:12px;color:#065F46;">Repaid:</span><br><strong style="font-size:18px;color:#059669;">$<?= number_format((float)$_empLoan['total_repaid'], 2) ?></strong></div>
                <div><span style="font-size:12px;color:#991B1B;">Balance Owed:</span><br><strong style="font-size:22px;color:#dc2626;">$<?= number_format((float)$_empLoan['balance'], 2) ?></strong></div>
            </div>
        </div>

        <?php if (!empty($_empLoanLedger)): ?>
        <!-- Loan Ledger (Tally style with running balance) -->
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="border-bottom:2px solid #e5e7eb;">
                <th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;">Date</th>
                <th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;">Particulars</th>
                <th style="text-align:left;padding:6px 8px;font-weight:600;color:#6b7280;">Vch</th>
                <th style="text-align:right;padding:6px 8px;font-weight:600;color:#dc2626;">Given (Dr)</th>
                <th style="text-align:right;padding:6px 8px;font-weight:600;color:#059669;">Repaid (Cr)</th>
                <th style="text-align:right;padding:6px 8px;font-weight:700;color:#1E293B;">Balance</th>
            </tr></thead>
            <tbody>
            <?php foreach ($_empLoanLedger as $_ll): ?>
            <tr style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:6px 8px;color:#6b7280;"><?= $_ll['txn_date'] ? date('d M Y', strtotime($_ll['txn_date'])) : '' ?></td>
                <td style="padding:6px 8px;font-size:11px;"><?= htmlspecialchars(substr($_ll['narration'] ?: ($_ll['direction']==='paid' ? 'Loan disbursed' : 'Repayment received'), 0, 50)) ?></td>
                <td style="padding:6px 8px;font-size:11px;color:#6b7280;"><?= htmlspecialchars($_ll['voucher_type'] ?? '') ?> <?= htmlspecialchars($_ll['voucher_no'] ?? '') ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;color:#dc2626;"><?= $_ll['direction']==='paid' ? '$'.number_format((float)$_ll['amount'],2) : '' ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;color:#059669;"><?= $_ll['direction']==='received' ? '$'.number_format((float)$_ll['amount'],2) : '' ?></td>
                <td style="padding:6px 8px;text-align:right;font-family:monospace;font-weight:600;color:<?= $_ll['running_balance'] > 0 ? '#dc2626' : '#059669' ?>;">$<?= number_format((float)$_ll['running_balance'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
