<?php
// ── HRM Leave Management — DishNet Hybrid v4.11.0 ───────────────────────
// Leave requests, approvals, and balance overview.
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

require_once __DIR__ . '/../../lib/LeaveService.php';
require_once __DIR__ . '/../../lib/HrmService.php';

$pdo = $store->getPdo();
$leave = new LeaveService($store, $pdo);
$hrm   = new HrmService($store, $pdo);

$isAcct = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);
if (!$isAcct) { echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>'; return; }

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lAct = $_POST['leave_action'] ?? '';
    if ($lAct === 'approve') {
        $res = $leave->approveRequest((int)$_POST['req_id'], $retailer['name'] ?? 'Admin');
        $msg = ($res['ok'] ?? false) ? 'Leave approved.' : ($res['error'] ?? 'Error');
        if ($res['ok'] ?? false) {
            $req = $leave->getRequest((int)$_POST['req_id']);
            if ($req) {
                $emp = $store->findOne('retailers.json', 'id', (int)$req['retailer_id']);
                if (!empty($emp['phone'])) {
                    try {
                        $n = svc('notify');
                        $n->leaveApproved($emp['phone'], $req['employee_name'], $req['leave_type_name'],
                            $req['start_date'], $req['end_date'], (int)$req['days']);
                    } catch (\Throwable $e) {}
                }
            }
        }
        if (!($res['ok'] ?? false)) $msgType = 'error';
    }
    if ($lAct === 'reject') {
        $reason = $_POST['reject_reason'] ?? '';
        $res = $leave->rejectRequest((int)$_POST['req_id'], $retailer['name'] ?? 'Admin', $reason);
        $msg = ($res['ok'] ?? false) ? 'Leave rejected.' : ($res['error'] ?? 'Error');
        if ($res['ok'] ?? false) {
            $req = $leave->getRequest((int)$_POST['req_id']);
            if ($req) {
                $emp = $store->findOne('retailers.json', 'id', (int)$req['retailer_id']);
                if (!empty($emp['phone'])) {
                    try {
                        $n = svc('notify');
                        $n->leaveRejected($emp['phone'], $req['employee_name'], $req['leave_type_name'], $reason);
                    } catch (\Throwable $e) {}
                }
            }
        }
        if (!($res['ok'] ?? false)) $msgType = 'error';
    }
}

// Filter
$fStatus = $_GET['ls'] ?? 'pending';
$requests = $leave->listRequests(['status' => $fStatus ?: null, 'limit' => 200]);
$pendingCount = $leave->pendingCount();
$leaveTypes = $leave->getLeaveTypes();

// Employee balances for overview
$employees = $hrm->listEmployees('active');
?>

<style>
.nav-pills{display:flex;gap:8px;margin:0 0 16px;flex-wrap:wrap}
.nav-pill{padding:6px 16px;border-radius:20px;font-size:13px;font-weight:500;text-decoration:none;border:1px solid #d1d5db;color:#374151}
.nav-pill.active{background:#2563eb;color:#fff;border-color:#2563eb}
.msg-box{border-radius:8px;padding:10px 14px;font-size:13px;margin:0 0 14px}
.msg-box.success{background:#D1FAE5;border:1px solid #6EE7B7;color:#065F46}
.msg-box.error{background:#FEE2E2;border:1px solid #FECACA;color:#991B1B}
.filter-row{display:flex;gap:8px;margin:0 0 14px;flex-wrap:wrap}
.filter-btn{padding:5px 14px;border-radius:20px;font-size:12px;font-weight:500;text-decoration:none;border:1px solid #d1d5db;color:#374151;background:#fff}
.filter-btn.active{background:#2563eb;color:#fff;border-color:#2563eb}
.filter-btn .badge{display:inline-block;background:rgba(255,255,255,.3);padding:1px 6px;border-radius:10px;font-size:10px;margin-left:4px}
table.leave{width:100%;border-collapse:collapse;font-size:13px}
table.leave th{background:#f9fafb;padding:8px 10px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb}
table.leave td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
table.leave tr:hover{background:#fafafa}
.lstatus{display:inline-block;padding:2px 10px;border-radius:6px;font-size:11px;font-weight:700;text-transform:uppercase}
.lstatus.pending{background:#FEF3C7;color:#92400E}
.lstatus.approved{background:#D1FAE5;color:#065F46}
.lstatus.rejected{background:#FEE2E2;color:#991B1B}
.lstatus.cancelled{background:#E5E7EB;color:#374151}
.ltype-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.btn{padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;border:none;cursor:pointer}
.btn-g{background:#059669;color:#fff}.btn-g:hover{background:#047857}
.btn-r{background:#DC2626;color:#fff}.btn-r:hover{background:#B91C1C}
.bal-section{margin:20px 0;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px}
.bal-section h2{font-size:15px;font-weight:600;margin:0 0 12px;color:#374151}
.bal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px}
.bal-card{border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px}
.bal-card-name{font-size:13px;font-weight:600;color:#111;margin:0 0 6px}
.bal-pills{display:flex;gap:6px;flex-wrap:wrap}
.bal-pill{font-size:11px;padding:2px 8px;border-radius:6px;font-weight:500}
.view-toggle{display:flex;gap:0;margin:0 0 14px}
.view-toggle button{padding:6px 16px;font-size:12px;font-weight:600;border:1px solid #d1d5db;background:#fff;color:#374151;cursor:pointer}
.view-toggle button:first-child{border-radius:8px 0 0 8px}
.view-toggle button:last-child{border-radius:0 8px 8px 0;border-left:0}
.view-toggle button.active{background:#2563eb;color:#fff;border-color:#2563eb}
</style>

<div class="nav-pills">
    <a href="?page=dashboard&tab=hrm_dashboard" class="nav-pill">Dashboard</a>
    <a href="?page=dashboard&tab=hrm_employees" class="nav-pill">Employees</a>
    <a href="?page=dashboard&tab=hrm_payroll" class="nav-pill">Payroll</a>
    <a href="?page=dashboard&tab=hrm_leave" class="nav-pill active">Leave<?= $pendingCount ? " ({$pendingCount})" : '' ?></a>
</div>

<?php if ($msg): ?><div class="msg-box <?= $msgType ?>"><?= $msgType==='success'?'✅':'❌' ?> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php $view = $_GET['lv'] ?? 'requests'; ?>
<div class="view-toggle">
    <button class="<?= $view==='requests'?'active':'' ?>" onclick="location='?page=dashboard&tab=hrm_leave&lv=requests&ls=<?= urlencode($fStatus) ?>'">Requests</button>
    <button class="<?= $view==='balances'?'active':'' ?>" onclick="location='?page=dashboard&tab=hrm_leave&lv=balances'">Balances</button>
</div>

<?php if ($view === 'requests'): ?>

<div class="filter-row">
    <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected',''=>'All'] as $k => $v): ?>
    <a href="?page=dashboard&tab=hrm_leave&ls=<?= $k ?>&lv=requests" class="filter-btn <?= $fStatus===$k?'active':'' ?>"><?= $v ?><?= $k==='pending' && $pendingCount ? "<span class=\"badge\">{$pendingCount}</span>" : '' ?></a>
    <?php endforeach; ?>
</div>

<?php if (empty($requests)): ?>
<div style="text-align:center;padding:40px;color:#9ca3af;">No leave requests found.</div>
<?php else: ?>
<table class="leave">
<thead><tr><th>Employee</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($requests as $r):
    $typeColor = '#6B7280';
    foreach ($leaveTypes as $lt) { if ((int)$lt['id'] === (int)$r['leave_type_id']) { $typeColor = $lt['color'] ?? '#6B7280'; break; } }
?>
<tr>
    <td><strong><?= htmlspecialchars($r['employee_name']) ?></strong></td>
    <td><span class="ltype-dot" style="background:<?= $typeColor ?>"></span><?= htmlspecialchars($r['leave_type_name']) ?></td>
    <td><?= date('M j', strtotime($r['start_date'])) ?></td>
    <td><?= date('M j, Y', strtotime($r['end_date'])) ?></td>
    <td><strong><?= $r['days'] ?></strong></td>
    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($r['reason'] ?? '') ?>"><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
    <td><span class="lstatus <?= $r['status'] ?>"><?= strtoupper($r['status']) ?></span></td>
    <td>
        <?php if ($r['status'] === 'pending'): ?>
        <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="leave_action" value="approve"><input type="hidden" name="req_id" value="<?= $r['id'] ?>"><button type="submit" class="btn btn-g" title="Approve">✅</button></form>
        <form method="POST" style="display:inline" onsubmit="var r=prompt('Reason for rejection:');if(!r)return false;this.querySelector('[name=reject_reason]').value=r;"><?= csrfField() ?><input type="hidden" name="leave_action" value="reject"><input type="hidden" name="req_id" value="<?= $r['id'] ?>"><input type="hidden" name="reject_reason" value=""><button type="submit" class="btn btn-r" title="Reject">❌</button></form>
        <?php elseif ($r['status'] === 'approved'): ?>
        <span style="font-size:11px;color:#6b7280;">By <?= htmlspecialchars($r['approved_by'] ?? '') ?></span>
        <?php elseif ($r['status'] === 'rejected' && $r['rejection_reason']): ?>
        <span style="font-size:11px;color:#991B1B;" title="<?= htmlspecialchars($r['rejection_reason']) ?>">Reason: <?= htmlspecialchars(substr($r['rejection_reason'], 0, 30)) ?></span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<?php elseif ($view === 'balances'): ?>

<div class="bal-section">
    <h2>Leave Balances — <?= date('Y') ?></h2>
    <div class="bal-grid">
        <?php foreach ($employees as $emp):
            $bals = $leave->getBalances((int)$emp['retailer_id']);
            if (empty($bals)) continue;
        ?>
        <div class="bal-card">
            <div class="bal-card-name"><?= htmlspecialchars($emp['name'] ?? '') ?></div>
            <div class="bal-pills">
                <?php foreach ($bals as $b):
                    if (!(int)$b['entitlement'] && $b['leave_type_code'] !== 'UL') continue;
                    $pct = $b['entitlement'] > 0 ? min(100, round(($b['available'] / ($b['entitlement'] + $b['carried'])) * 100)) : 100;
                    $bgColor = $pct > 50 ? '#D1FAE5' : ($pct > 20 ? '#FEF3C7' : '#FEE2E2');
                    $txtColor = $pct > 50 ? '#065F46' : ($pct > 20 ? '#92400E' : '#991B1B');
                ?>
                <span class="bal-pill" style="background:<?= $bgColor ?>;color:<?= $txtColor ?>"><?= $b['leave_type_code'] ?>: <?= $b['available'] ?>/<?= $b['entitlement'] + $b['carried'] ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php endif; ?>
