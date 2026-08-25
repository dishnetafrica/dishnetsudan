<?php
// ═══════════════════════════════════════════════════════════════════════
// api_hrm.php — HRM Module REST API (v4.11.0)
// POST-AUTH: $me2, $rid, $isAdmin, $retailer, $can() available
// ═══════════════════════════════════════════════════════════════════════

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

// ── Lazy service loaders ────────────────────────────────────────────────
$_hrmSvc = null;
$_paySvc = null;
$_leaveSvc = null;

$hrmSvc = function() use (&$_hrmSvc, $store) {
    if (!$_hrmSvc) {
        require_once __DIR__ . '/../../lib/HrmService.php';
        $_hrmSvc = new HrmService($store, $store->getPdo());
    }
    return $_hrmSvc;
};
$paySvc = function() use (&$_paySvc, $store, $hrmSvc, $dataDir) {
    if (!$_paySvc) {
        require_once __DIR__ . '/../../lib/PayrollService.php';
        require_once __DIR__ . '/../../lib/CashbookService.php';
        $cb = new CashbookService($store, $dataDir);
        $_paySvc = new PayrollService($store, $store->getPdo(), $hrmSvc(), $cb);
    }
    return $_paySvc;
};
$leaveSvc = function() use (&$_leaveSvc, $store) {
    if (!$_leaveSvc) {
        require_once __DIR__ . '/../../lib/LeaveService.php';
        $_leaveSvc = new LeaveService($store, $store->getPdo());
    }
    return $_leaveSvc;
};

$adminOnly = function() use ($isAdmin, $er2) {
    if (!$isAdmin) $er2('Admin access required', 403);
};

// ══════════════════════════════════════════════════════════════════════
// EMPLOYEES
// ══════════════════════════════════════════════════════════════════════

// GET hrm_employees — list all employees
if ($act === 'hrm_employees' && $met === 'GET') {
    $adminOnly();
    $ok2($hrmSvc()->listEmployees($_GET['status'] ?? '', $_GET['department'] ?? ''));
}

// GET hrm_employee — single employee profile
if ($act === 'hrm_employee' && $met === 'GET') {
    $empId = (int)($_GET['id'] ?? 0);
    if (!$empId) $er2('Missing id');
    // Staff can view own profile, admin can view any
    if (!$isAdmin && $empId !== $rid) $er2('Access denied', 403);
    $profile = $hrmSvc()->getProfile($empId);
    if (!$profile) $er2('Employee not found', 404);
    $ok2($profile);
}

// POST hrm_employee_update — update employee profile
if ($act === 'hrm_employee_update' && $met === 'POST') {
    $adminOnly();
    $empId = (int)($body['retailer_id'] ?? 0);
    if (!$empId) $er2('Missing retailer_id');
    $ok2($hrmSvc()->upsertProfile($empId, $body));
}

// GET hrm_stats — dashboard stats
if ($act === 'hrm_stats' && $met === 'GET') {
    $adminOnly();
    $ok2($hrmSvc()->getStats());
}

// POST hrm_seed — seed employees from retailers + cashbook
if ($act === 'hrm_seed' && $met === 'POST') {
    $adminOnly();
    $r1 = $hrmSvc()->seedFromRetailers();
    $r2 = $hrmSvc()->seedSalaryFromCashbook($body['effective_from'] ?? '');
    $ok2(['employees' => $r1, 'salary' => $r2]);
}

// ══════════════════════════════════════════════════════════════════════
// SALARY STRUCTURE
// ══════════════════════════════════════════════════════════════════════

// GET hrm_salary — get salary structure for an employee
if ($act === 'hrm_salary' && $met === 'GET') {
    $empId = (int)($_GET['id'] ?? 0);
    if (!$empId) $er2('Missing id');
    if (!$isAdmin && $empId !== $rid) $er2('Access denied', 403);
    $ok2([
        'structure' => $hrmSvc()->getSalaryStructure($empId),
        'gross'     => $hrmSvc()->getGrossSalary($empId),
        'history'   => $isAdmin ? $hrmSvc()->getSalaryHistory($empId) : [],
    ]);
}

// POST hrm_salary_update — set salary structure
if ($act === 'hrm_salary_update' && $met === 'POST') {
    $adminOnly();
    $empId = (int)($body['retailer_id'] ?? 0);
    if (!$empId) $er2('Missing retailer_id');
    $components = $body['components'] ?? [];
    $from = $body['effective_from'] ?? date('Y-m-01');
    if (empty($components)) $er2('No components provided');
    $ok2($hrmSvc()->setSalaryStructure($empId, $components, $from, $body['currency'] ?? 'USD'));
}

// ══════════════════════════════════════════════════════════════════════
// PAYROLL
// ══════════════════════════════════════════════════════════════════════

// GET hrm_payroll_periods — list periods
if ($act === 'hrm_payroll_periods' && $met === 'GET') {
    $adminOnly();
    $ok2($paySvc()->listPeriods((int)($_GET['limit'] ?? 12)));
}

// POST hrm_payroll_create — create period
if ($act === 'hrm_payroll_create' && $met === 'POST') {
    $adminOnly();
    $period = $body['period'] ?? '';
    if (!$period) $er2('Missing period (YYYY-MM)');
    $ok2($paySvc()->createPeriod($period));
}

// POST hrm_payroll_calculate — calculate payroll
if ($act === 'hrm_payroll_calculate' && $met === 'POST') {
    $adminOnly();
    $periodId = (int)($body['period_id'] ?? 0);
    if (!$periodId) $er2('Missing period_id');
    $ok2($paySvc()->calculate($periodId, $retailer['name'] ?? 'Admin'));
}

// POST hrm_payroll_approve — approve payroll
if ($act === 'hrm_payroll_approve' && $met === 'POST') {
    $adminOnly();
    $periodId = (int)($body['period_id'] ?? 0);
    if (!$periodId) $er2('Missing period_id');
    $ok2($paySvc()->approve($periodId, $retailer['name'] ?? 'Admin'));
}

// GET hrm_payroll_lines — get lines for a period
if ($act === 'hrm_payroll_lines' && $met === 'GET') {
    $adminOnly();
    $periodId = (int)($_GET['period_id'] ?? 0);
    if (!$periodId) $er2('Missing period_id');
    $ok2([
        'period' => $paySvc()->getPeriod($periodId),
        'lines'  => $paySvc()->getPayrollLines($periodId),
    ]);
}

// POST hrm_payroll_disburse — record a disbursement
if ($act === 'hrm_payroll_disburse' && $met === 'POST') {
    $adminOnly();
    $lineId = (int)($body['line_id'] ?? 0);
    $amount = (float)($body['amount'] ?? 0);
    if (!$lineId || $amount <= 0) $er2('Missing line_id or amount');
    $result = $paySvc()->disburse($lineId, $amount, [
        'paid_by_id'     => $rid,
        'paid_by_name'   => $retailer['name'] ?? 'Admin',
        'voucher_ref'    => $body['voucher_ref']    ?? '',
        'description'    => $body['description']    ?? '',
        'payment_method' => $body['payment_method'] ?? 'cash',
        'component'      => $body['component']      ?? 'base_salary',
        'payment_type'   => $body['payment_type']   ?? 'salary',
    ]);

    // Send WhatsApp notification if disbursement succeeded
    if (($result['ok'] ?? false)) {
        $line = $paySvc()->getPayrollLine($lineId);
        if ($line) {
            $period = $paySvc()->getPeriod((int)$line['period_id']);
            $empRetailer = $store->findOne('retailers.json', 'id', (int)$line['retailer_id']);
            $phone = $empRetailer['phone'] ?? '';
            if ($phone) {
                try {
                    $notify = svc('notify');
                    $component = PayrollService::COMPONENT_CB_MAP[$body['component'] ?? 'base_salary'] ?? 'Salary';
                    $notify->payrollDisbursement($phone, $line['employee_name'], $amount,
                        $component, $period['period'] ?? '',
                        (float)$line['total_disbursed'] + $amount, (float)$line['net_pay'],
                        $body['voucher_ref'] ?? '');
                } catch (\Throwable $e) { /* don't fail on notification error */ }
            }
        }
    }

    $ok2($result);
}

// GET hrm_disbursements — get disbursements for a line
if ($act === 'hrm_disbursements' && $met === 'GET') {
    $lineId = (int)($_GET['line_id'] ?? 0);
    if (!$lineId) $er2('Missing line_id');
    $ok2($paySvc()->getDisbursements($lineId));
}

// POST hrm_payroll_close — close period
if ($act === 'hrm_payroll_close' && $met === 'POST') {
    $adminOnly();
    $periodId = (int)($body['period_id'] ?? 0);
    if (!$periodId) $er2('Missing period_id');
    $ok2($paySvc()->closePeriod($periodId));
}

// POST hrm_payroll_hold — hold a line
if ($act === 'hrm_payroll_hold' && $met === 'POST') {
    $adminOnly();
    $lineId = (int)($body['line_id'] ?? 0);
    if (!$lineId) $er2('Missing line_id');
    $ok2($paySvc()->holdLine($lineId, $body['reason'] ?? ''));
}

// POST hrm_payroll_adjust — adjust a line
if ($act === 'hrm_payroll_adjust' && $met === 'POST') {
    $adminOnly();
    $lineId = (int)($body['line_id'] ?? 0);
    if (!$lineId) $er2('Missing line_id');
    $ok2($paySvc()->adjustLine($lineId, $body));
}

// GET hrm_payslip — get payslip data
if ($act === 'hrm_payslip' && $met === 'GET') {
    $periodId = (int)($_GET['period_id'] ?? 0);
    $empId    = (int)($_GET['retailer_id'] ?? $rid);
    if (!$periodId) $er2('Missing period_id');
    if (!$isAdmin && $empId !== $rid) $er2('Access denied', 403);
    $data = $paySvc()->getPayslipData($periodId, $empId);
    if (!$data) $er2('Payslip not found', 404);
    $ok2($data);
}

// GET hrm_my_payslips — staff self-service payslip history
if ($act === 'hrm_my_payslips' && $met === 'GET') {
    $ok2($paySvc()->getPayslipHistory($rid));
}

// POST hrm_send_payslip — send payslip via WhatsApp
if ($act === 'hrm_send_payslip' && $met === 'POST') {
    $adminOnly();
    $periodId = (int)($body['period_id'] ?? 0);
    $empId    = (int)($body['retailer_id'] ?? 0);
    if (!$periodId || !$empId) $er2('Missing period_id or retailer_id');
    $data = $paySvc()->getPayslipData($periodId, $empId);
    if (!$data) $er2('Payslip not found', 404);
    $phone = $data['phone'] ?? '';
    if (!$phone) $er2('No phone number for employee');
    try {
        $notify = svc('notify');
        $notify->payslipMonthly($phone, $data['employee_name'], $data);
        $ok2(['ok' => true, 'sent_to' => $phone]);
    } catch (\Throwable $e) {
        $er2('Failed to send: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════
// LEAVE MANAGEMENT
// ══════════════════════════════════════════════════════════════════════

// GET hrm_leave_types — list leave types
if ($act === 'hrm_leave_types' && $met === 'GET') {
    $ok2($leaveSvc()->getLeaveTypes());
}

// GET hrm_leave_balance — balances for an employee
if ($act === 'hrm_leave_balance' && $met === 'GET') {
    $empId = (int)($_GET['id'] ?? $rid);
    if (!$isAdmin && $empId !== $rid) $er2('Access denied', 403);
    $ok2($leaveSvc()->getBalances($empId, (int)($_GET['year'] ?? date('Y'))));
}

// POST hrm_leave_request — submit leave request
if ($act === 'hrm_leave_request' && $met === 'POST') {
    $typeId = (int)($body['leave_type_id'] ?? 0);
    $start  = $body['start_date'] ?? '';
    $end    = $body['end_date']   ?? '';
    if (!$typeId || !$start || !$end) $er2('Missing leave_type_id, start_date, or end_date');
    $result = $leaveSvc()->submitRequest($rid, $retailer['name'] ?? '', $typeId, $start, $end, $body['reason'] ?? '');
    // Notify admin of new leave request
    if ($result['ok'] ?? false) {
        try {
            $notify = svc('notify');
            $notify->leaveRequestPending($retailer['name'] ?? '', $result['leave_type'] ?? '',
                $start, $end, (int)($result['days'] ?? 0), $body['reason'] ?? '');
        } catch (\Throwable $e) {}
    }
    $ok2($result);
}

// GET hrm_leave_requests — list requests (admin: all, staff: own)
if ($act === 'hrm_leave_requests' && $met === 'GET') {
    $filters = ['limit' => (int)($_GET['limit'] ?? 100)];
    if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
    if (!$isAdmin) $filters['retailer_id'] = $rid;
    elseif (!empty($_GET['retailer_id'])) $filters['retailer_id'] = (int)$_GET['retailer_id'];
    $ok2($leaveSvc()->listRequests($filters));
}

// POST hrm_leave_approve — approve a request
if ($act === 'hrm_leave_approve' && $met === 'POST') {
    $adminOnly();
    $reqId = (int)($body['id'] ?? 0);
    if (!$reqId) $er2('Missing id');
    $result = $leaveSvc()->approveRequest($reqId, $retailer['name'] ?? 'Admin');
    // Send WhatsApp notification
    if ($result['ok'] ?? false) {
        $req = $leaveSvc()->getRequest($reqId);
        if ($req) {
            $emp = $store->findOne('retailers.json', 'id', (int)$req['retailer_id']);
            if (!empty($emp['phone'])) {
                try {
                    $notify = svc('notify');
                    $notify->leaveApproved($emp['phone'], $req['employee_name'], $req['leave_type_name'],
                        $req['start_date'], $req['end_date'], (int)$req['days']);
                } catch (\Throwable $e) {}
            }
        }
    }
    $ok2($result);
}

// POST hrm_leave_reject — reject a request
if ($act === 'hrm_leave_reject' && $met === 'POST') {
    $adminOnly();
    $reqId = (int)($body['id'] ?? 0);
    if (!$reqId) $er2('Missing id');
    $reason = $body['reason'] ?? '';
    $result = $leaveSvc()->rejectRequest($reqId, $retailer['name'] ?? 'Admin', $reason);
    if ($result['ok'] ?? false) {
        $req = $leaveSvc()->getRequest($reqId);
        if ($req) {
            $emp = $store->findOne('retailers.json', 'id', (int)$req['retailer_id']);
            if (!empty($emp['phone'])) {
                try {
                    $notify = svc('notify');
                    $notify->leaveRejected($emp['phone'], $req['employee_name'], $req['leave_type_name'], $reason);
                } catch (\Throwable $e) {}
            }
        }
    }
    $ok2($result);
}

// POST hrm_leave_cancel — cancel own request
if ($act === 'hrm_leave_cancel' && $met === 'POST') {
    $reqId = (int)($body['id'] ?? 0);
    if (!$reqId) $er2('Missing id');
    $req = $leaveSvc()->getRequest($reqId);
    if (!$req) $er2('Not found', 404);
    if (!$isAdmin && (int)$req['retailer_id'] !== $rid) $er2('Access denied', 403);
    $ok2($leaveSvc()->cancelRequest($reqId));
}

// GET hrm_leave_pending_count — badge count
if ($act === 'hrm_leave_pending_count' && $met === 'GET') {
    $ok2(['count' => $leaveSvc()->pendingCount()]);
}
