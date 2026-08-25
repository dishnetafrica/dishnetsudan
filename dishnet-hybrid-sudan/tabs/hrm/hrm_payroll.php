<?php
// ── HRM Payroll — DishNet Hybrid v4.11.0 ────────────────────────────────
// Monthly payroll run: create → calculate → approve → disburse → close
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

require_once __DIR__ . '/../../lib/HrmService.php';
require_once __DIR__ . '/../../lib/PayrollService.php';
require_once __DIR__ . '/../../lib/CashbookService.php';
require_once __DIR__ . '/../../lib/LeaveService.php';

$pdo = $store->getPdo();
$hrm   = new HrmService($store, $pdo);
$cb    = new CashbookService($store, $dataDir);
$pay   = new PayrollService($store, $pdo, $hrm, $cb);
$leave = new LeaveService($store, $pdo);

$isAcct = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);
if (!$isAcct) { echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>'; return; }

$msg = '';
$msgType = 'success';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pAct = $_POST['pay_action'] ?? '';
    if ($pAct === 'create') {
        $res = $pay->createPeriod($_POST['period'] ?? '');
        $msg = ($res['ok'] ?? false) ? "Period {$_POST['period']} created." : ($res['error'] ?? 'Error');
        if (!($res['ok'] ?? false)) $msgType = 'error';
    }
    if ($pAct === 'calculate') {
        $res = $pay->calculate((int)$_POST['period_id'], $retailer['name'] ?? 'Admin');
        $msg = ($res['ok'] ?? false) ? "Calculated: {$res['employees']} employees, \${$res['total_net']} net." : ($res['error'] ?? 'Error');
        if (!($res['ok'] ?? false)) $msgType = 'error';
    }
    if ($pAct === 'approve') {
        $res = $pay->approve((int)$_POST['period_id'], $retailer['name'] ?? 'Admin');
        $msg = ($res['ok'] ?? false) ? 'Payroll approved.' : ($res['error'] ?? 'Error');
        if (!($res['ok'] ?? false)) $msgType = 'error';
    }
    if ($pAct === 'close') {
        $res = $pay->closePeriod((int)$_POST['period_id']);
        $msg = ($res['ok'] ?? false) ? 'Period closed.' . ($res['unpaid'] ? " ⚠ {$res['unpaid']} employee(s) not fully paid." : '') : ($res['error'] ?? 'Error');
        if (!($res['ok'] ?? false)) $msgType = 'error';
    }
    if ($pAct === 'disburse') {
        $lineId = (int)$_POST['line_id'];
        $amount = (float)$_POST['disb_amount'];
        $res = $pay->disburse($lineId, $amount, [
            'paid_by_id'     => (int)$retailer['id'],
            'paid_by_name'   => $retailer['name'] ?? 'Admin',
            'voucher_ref'    => $_POST['voucher_ref'] ?? '',
            'description'    => $_POST['disb_desc'] ?? '',
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'component'      => 'base_salary',
            'payment_type'   => $_POST['payment_type'] ?? 'salary',
        ]);
        $msg = ($res['ok'] ?? false) ? "Paid \$" . number_format($amount, 2) . ". Balance: \$" . number_format($res['balance_due'] ?? 0, 2) : ($res['error'] ?? 'Error');
        if (!($res['ok'] ?? false)) $msgType = 'error';
        // Send WA notification
        if (($res['ok'] ?? false)) {
            $line = $pay->getPayrollLine($lineId);
            if ($line) {
                $period = $pay->getPeriod((int)$line['period_id']);
                $emp = $store->findOne('retailers.json', 'id', (int)$line['retailer_id']);
                if (!empty($emp['phone'])) {
                    try {
                        $n = svc('notify');
                        $n->payrollDisbursement($emp['phone'], $line['employee_name'], $amount,
                            'Salary', $period['period'] ?? '', (float)($line['total_disbursed'] ?? 0) + $amount,
                            (float)$line['net_pay'], $_POST['voucher_ref'] ?? '');
                    } catch (\Throwable $e) {}
                }
            }
        }
    }
    if ($pAct === 'hold') {
        $res = $pay->holdLine((int)$_POST['line_id'], $_POST['hold_reason'] ?? '');
        $msg = ($res['ok'] ?? false) ? 'Line held.' : ($res['error'] ?? 'Error');
    }
}

// Load periods + selected period
$periods = $pay->listPeriods(24);
$selPeriodStr = $_GET['period'] ?? ($_POST['sel_period'] ?? '');
$selPeriod = null;
$selLines  = [];

if ($selPeriodStr) {
    $selPeriod = $pay->getPeriod($selPeriodStr);
}
if (!$selPeriod && !empty($periods)) {
    $selPeriod = $periods[0];
}
if ($selPeriod) {
    $selLines = $pay->getPayrollLines((int)$selPeriod['id']);
}

$pendingLeave = $leave->pendingCount();

// Totals
$tGross = $tNet = $tDisb = $tBal = 0;
$countPaid = $countPartial = $countPending = $countHeld = 0;
foreach ($selLines as $l) {
    $tGross += (float)$l['gross_pay'];
    $tNet   += (float)$l['net_pay'];
    $tDisb  += (float)$l['total_disbursed'];
    $tBal   += (float)$l['balance_due'];
    if ($l['disbursement_status'] === 'paid') $countPaid++;
    elseif ($l['disbursement_status'] === 'partial') $countPartial++;
    elseif ($l['disbursement_status'] === 'held') $countHeld++;
    else $countPending++;
}
?>

<style>
.nav-pills{display:flex;gap:8px;margin:0 0 16px;flex-wrap:wrap}
.nav-pill{padding:6px 16px;border-radius:20px;font-size:13px;font-weight:500;text-decoration:none;border:1px solid #d1d5db;color:#374151}
.nav-pill.active{background:#2563eb;color:#fff;border-color:#2563eb}
.msg-box{border-radius:8px;padding:10px 14px;font-size:13px;margin:0 0 14px}
.msg-box.success{background:#D1FAE5;border:1px solid #6EE7B7;color:#065F46}
.msg-box.error{background:#FEE2E2;border:1px solid #FECACA;color:#991B1B}
.toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px;padding:12px 16px;background:#f9fafb;border-radius:10px;border:1px solid #e5e7eb}
.toolbar select,.toolbar input{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px}
.btn{padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;border:none;cursor:pointer}
.btn-p{background:#2563eb;color:#fff}.btn-p:hover{background:#1d4ed8}
.btn-g{background:#059669;color:#fff}.btn-g:hover{background:#047857}
.btn-a{background:#D97706;color:#fff}.btn-a:hover{background:#B45309}
.btn-r{background:#DC2626;color:#fff}.btn-r:hover{background:#B91C1C}
.btn-o{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}.btn-o:hover{background:#e5e7eb}
.pstatus{display:inline-block;padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase}
.pstatus.draft{background:#FEF3C7;color:#92400E}
.pstatus.calculated{background:#DBEAFE;color:#1E40AF}
.pstatus.approved{background:#D1FAE5;color:#065F46}
.pstatus.closed{background:#E5E7EB;color:#374151}
.pstatus.pending{background:#FEF3C7;color:#92400E}
.pstatus.partial{background:#DBEAFE;color:#1E40AF}
.pstatus.paid{background:#D1FAE5;color:#065F46}
.pstatus.held{background:#FEE2E2;color:#991B1B}
table.pay{width:100%;border-collapse:collapse;font-size:13px}
table.pay th{background:#f9fafb;padding:8px 10px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb}
table.pay td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
table.pay tr:hover{background:#fafafa}
table.pay .r{text-align:right}
table.pay tfoot td{font-weight:700;border-top:2px solid #e5e7eb;background:#f9fafb}
.disb-modal{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center}
.disb-modal.open{display:flex}
.disb-box{background:#fff;border-radius:14px;padding:24px;width:400px;max-width:90vw}
.disb-box h3{margin:0 0 16px;font-size:16px}
.disb-box label{display:block;font-size:11px;font-weight:600;color:#6b7280;margin:10px 0 3px;text-transform:uppercase}
.disb-box input,.disb-box select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box}
.summary-row{display:flex;gap:20px;margin:0 0 14px;flex-wrap:wrap}
.summary-item{font-size:13px;color:#6b7280}
.summary-item strong{color:#111;font-size:15px}
</style>

<div class="nav-pills">
    <a href="?page=dashboard&tab=hrm_dashboard" class="nav-pill">Dashboard</a>
    <a href="?page=dashboard&tab=hrm_employees" class="nav-pill">Employees</a>
    <a href="?page=dashboard&tab=hrm_payroll" class="nav-pill active">Payroll</a>
    <a href="?page=dashboard&tab=hrm_leave" class="nav-pill">Leave<?= $pendingLeave ? " ({$pendingLeave})" : '' ?></a>
</div>

<?php if ($msg): ?><div class="msg-box <?= $msgType ?>"><?= $msgType==='success'?'✅':'❌' ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Toolbar -->
<div class="toolbar">
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="page" value="dashboard">
        <input type="hidden" name="tab" value="hrm_payroll">
        <select name="period" onchange="this.form.submit()">
            <option value="">Select Period</option>
            <?php foreach ($periods as $p): ?>
            <option value="<?= $p['period'] ?>" <?= ($selPeriod && $selPeriod['period']===$p['period'])?'selected':'' ?>><?= $p['period'] ?> (<?= strtoupper($p['status']) ?>)</option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Create new period -->
    <form method="POST" style="display:flex;gap:6px;align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="pay_action" value="create">
        <input type="month" name="period" value="<?= date('Y-m') ?>" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
        <button type="submit" class="btn btn-o">+ New Period</button>
    </form>

    <?php if ($selPeriod): ?>
    <!-- Period actions -->
    <?php if ($selPeriod['status'] === 'draft' || $selPeriod['status'] === 'calculated'): ?>
    <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="pay_action" value="calculate"><input type="hidden" name="period_id" value="<?= $selPeriod['id'] ?>"><button type="submit" class="btn btn-p">📊 Calculate</button></form>
    <?php endif; ?>
    <?php if ($selPeriod['status'] === 'calculated'): ?>
    <form method="POST" style="display:inline;" onsubmit="return confirm('Approve payroll for <?= $selPeriod['period'] ?>?')"><?= csrfField() ?><input type="hidden" name="pay_action" value="approve"><input type="hidden" name="period_id" value="<?= $selPeriod['id'] ?>"><button type="submit" class="btn btn-g">✅ Approve</button></form>
    <?php endif; ?>
    <?php if ($selPeriod['status'] === 'approved'): ?>
    <form method="POST" style="display:inline;" onsubmit="return confirm('Close period <?= $selPeriod['period'] ?>?')"><?= csrfField() ?><input type="hidden" name="pay_action" value="close"><input type="hidden" name="period_id" value="<?= $selPeriod['id'] ?>"><button type="submit" class="btn btn-a">🔒 Close Period</button></form>
    <?php endif; ?>
    <span class="pstatus <?= $selPeriod['status'] ?>"><?= strtoupper($selPeriod['status']) ?></span>
    <?php endif; ?>
</div>

<?php if ($selPeriod && !empty($selLines)): ?>

<div class="summary-row">
    <div class="summary-item">Gross: <strong>$<?= number_format($tGross, 2) ?></strong></div>
    <div class="summary-item">Net: <strong>$<?= number_format($tNet, 2) ?></strong></div>
    <div class="summary-item">Disbursed: <strong style="color:#059669">$<?= number_format($tDisb, 2) ?></strong></div>
    <div class="summary-item">Balance: <strong style="color:<?= $tBal > 0 ? '#DC2626' : '#059669' ?>">$<?= number_format($tBal, 2) ?></strong></div>
    <div class="summary-item">✅ <?= $countPaid ?> paid · ⏳ <?= $countPartial ?> partial · ⏸ <?= $countPending ?> pending<?= $countHeld ? " · 🚫 {$countHeld} held" : '' ?></div>
</div>

<div style="overflow-x:auto;">
<table class="pay">
<thead>
<tr><th>Employee</th><th class="r">Base</th><th class="r">Trans</th><th class="r">Food</th><th class="r">Gross</th><th class="r">Deduct</th><th class="r">Net</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th><th></th></tr>
</thead>
<tbody>
<?php foreach ($selLines as $l): ?>
<tr>
    <td><strong><?= htmlspecialchars($l['employee_name']) ?></strong><br><span style="font-size:11px;color:#6b7280"><?= htmlspecialchars($l['employee_code'] ?? '') ?></span></td>
    <td class="r">$<?= number_format((float)$l['base_salary'], 0) ?></td>
    <td class="r">$<?= number_format((float)$l['transport'], 0) ?></td>
    <td class="r">$<?= number_format((float)$l['food'], 0) ?></td>
    <td class="r"><strong>$<?= number_format((float)$l['gross_pay'], 0) ?></strong></td>
    <td class="r"><?= (float)$l['total_deductions'] > 0 ? '-$'.number_format((float)$l['total_deductions'], 0) : '—' ?></td>
    <td class="r"><strong>$<?= number_format((float)$l['net_pay'], 0) ?></strong></td>
    <td class="r" style="color:#059669">$<?= number_format((float)$l['total_disbursed'], 0) ?></td>
    <td class="r" style="color:<?= (float)$l['balance_due'] > 0 ? '#DC2626' : '#059669' ?>">$<?= number_format((float)$l['balance_due'], 0) ?></td>
    <td><span class="pstatus <?= $l['disbursement_status'] ?>"><?= strtoupper($l['disbursement_status']) ?></span></td>
    <td>
        <?php if (in_array($selPeriod['status'], ['approved','calculated']) && $l['disbursement_status'] !== 'paid' && $l['disbursement_status'] !== 'held'): ?>
        <button class="btn btn-p" style="padding:4px 10px;font-size:11px;" onclick="openDisb(<?= $l['id'] ?>,'<?= htmlspecialchars($l['employee_name'],ENT_QUOTES) ?>',<?= (float)$l['balance_due'] ?>)">Pay</button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr><td>TOTAL (<?= count($selLines) ?> staff)</td><td></td><td></td><td></td><td class="r">$<?= number_format($tGross, 0) ?></td><td></td><td class="r">$<?= number_format($tNet, 0) ?></td><td class="r" style="color:#059669">$<?= number_format($tDisb, 0) ?></td><td class="r">$<?= number_format($tBal, 0) ?></td><td></td><td></td></tr>
</tfoot>
</table>
</div>

<?php elseif ($selPeriod && empty($selLines)): ?>
<div style="text-align:center;padding:40px;color:#9ca3af;">
    <p>No payroll lines yet. Click <strong>Calculate</strong> to generate payroll from salary structures.</p>
</div>
<?php elseif (empty($periods)): ?>
<div style="text-align:center;padding:40px;color:#9ca3af;">
    <p>No payroll periods yet. Create one using the form above.</p>
</div>
<?php endif; ?>

<!-- Disbursement Modal -->
<div class="disb-modal" id="disbModal">
<div class="disb-box">
    <h3>💰 Pay <span id="dm_name"></span></h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="pay_action" value="disburse">
        <input type="hidden" name="line_id" id="dm_line_id">
        <label>Amount ($)</label>
        <input type="number" name="disb_amount" id="dm_amount" step="0.01" min="0" required>
        <label>Payment Type</label>
        <select name="payment_type">
            <option value="salary">Full Salary</option>
            <option value="advance">Advance</option>
            <option value="balance">Balance</option>
            <option value="bonus">Bonus</option>
        </select>
        <label>Payment Method</label>
        <select name="payment_method">
            <option value="cash">Cash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="mobile_money">Mobile Money</option>
        </select>
        <label>Voucher Ref</label>
        <input type="text" name="voucher_ref" placeholder="e.g. Voucher No-A0870">
        <label>Description (optional)</label>
        <input type="text" name="disb_desc" placeholder="">
        <div style="display:flex;gap:10px;margin-top:16px;">
            <button type="submit" class="btn btn-g" style="flex:1">Confirm Payment</button>
            <button type="button" class="btn btn-o" onclick="document.getElementById('disbModal').classList.remove('open')">Cancel</button>
        </div>
    </form>
</div>
</div>

<script>
function openDisb(lineId, name, balance) {
    document.getElementById('dm_line_id').value = lineId;
    document.getElementById('dm_name').textContent = name;
    document.getElementById('dm_amount').value = balance.toFixed(2);
    document.getElementById('dm_amount').max = balance;
    document.getElementById('disbModal').classList.add('open');
}
</script>
