<?php
declare(strict_types=1);
if (!function_exists('str_contains'))   { function str_contains(string $h, string $n): bool   { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool{ return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with'))  { function str_ends_with(string $h, string $n): bool  { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * PayrollService — DishNet Hybrid Telecom v4.11.0
 *
 * Monthly payroll lifecycle:
 *   create period → calculate → approve → disburse (partial/full) → close
 *
 * Each disbursement auto-posts to cb_ledger (source='payroll')
 * and does NOT create cash_ins.json entries (salary = personal pay).
 *
 * Usage:
 *   $ps = new PayrollService($store, $pdo, $hrmService, $cashbookService);
 *   $period = $ps->createPeriod('2026-03');
 *   $ps->calculate($period['id']);
 *   $ps->approve($period['id'], 'Rupesh');
 *   $ps->disburse($lineId, 400.00, ['paid_by_name' => 'Rupesh', ...]);
 *   $ps->closePeriod($period['id']);
 */
class PayrollService
{
    private \StoreInterface $store;
    private \PDO            $pdo;
    private \HrmService     $hrm;
    private $cb; // CashbookService — optional, lazy-loaded

    // How many working days per month (configurable via kyc_config.json)
    private int $workingDaysPerMonth = 26;

    // Component → cashbook category mapping for disbursement posting
    const COMPONENT_CB_MAP = [
        'base_salary' => 'Salary',
        'transport'   => 'Transport Allowance',
        'food'        => 'Food Allowance',
        'housing'     => 'Housing Allowance',
        'bonus'       => 'Bonus',
        'other'       => 'Employee Benefit',
    ];

    public function __construct(\StoreInterface $store, \PDO $pdo, \HrmService $hrm, $cashbookService = null)
    {
        $this->store = $store;
        $this->pdo   = $pdo;
        $this->hrm   = $hrm;
        $this->cb    = $cashbookService;

        // Load config
        $config = $this->store->load('kyc_config.json') ?? [];
        $this->workingDaysPerMonth = (int)($config['hrm_working_days_per_month'] ?? 26);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PAYROLL PERIODS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create a new payroll period (e.g. '2026-03').
     */
    public function createPeriod(string $period): array
    {
        // Validate format YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return ['ok' => false, 'error' => 'Period must be YYYY-MM format'];
        }

        // Check not duplicate
        $stmt = $this->pdo->prepare('SELECT id FROM hrm_payroll_periods WHERE period = ?');
        $stmt->execute([$period]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => "Period {$period} already exists"];
        }

        $start = $period . '-01';
        $end   = date('Y-m-t', strtotime($start)); // last day of month

        $this->pdo->prepare(
            "INSERT INTO hrm_payroll_periods (period, period_start, period_end, status, created_at)
             VALUES (?, ?, ?, 'draft', ?)"
        )->execute([$period, $start, $end, date('Y-m-d H:i:s')]);

        $id = (int)$this->pdo->lastInsertId();
        return ['ok' => true, 'id' => $id, 'period' => $period, 'status' => 'draft'];
    }

    /**
     * Get a payroll period by ID or period string.
     */
    public function getPeriod($idOrPeriod): ?array
    {
        if (is_numeric($idOrPeriod)) {
            $stmt = $this->pdo->prepare('SELECT * FROM hrm_payroll_periods WHERE id = ?');
            $stmt->execute([(int)$idOrPeriod]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM hrm_payroll_periods WHERE period = ?');
            $stmt->execute([$idOrPeriod]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List payroll periods, newest first.
     */
    public function listPeriods(int $limit = 12): array
    {
        return $this->pdo->query(
            "SELECT * FROM hrm_payroll_periods ORDER BY period DESC LIMIT {$limit}"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PAYROLL CALCULATION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Calculate payroll for a period.
     * Creates/updates hrm_payroll_lines for each active employee.
     */
    public function calculate(int $periodId, string $calculatedBy = 'System'): array
    {
        $period = $this->getPeriodById($periodId);
        if (!$period) return ['ok' => false, 'error' => 'Period not found'];
        if ($period['status'] === 'closed') return ['ok' => false, 'error' => 'Period is closed'];

        $periodEnd = $period['period_end'];
        $employees = $this->hrm->listEmployees('active');

        $totalGross = 0;
        $totalDeductions = 0;
        $totalNet = 0;
        $count = 0;

        foreach ($employees as $emp) {
            $rid = (int)$emp['retailer_id'];

            // Get salary structure effective at period end
            $components = $this->hrm->getSalaryStructureAt($rid, $periodEnd);
            if (empty($components)) continue; // No salary defined — skip

            // Calculate earnings from components
            $earnings = [
                'base_salary' => 0, 'transport' => 0, 'food' => 0,
                'housing' => 0, 'bonus' => 0, 'other_earnings' => 0,
            ];
            foreach ($components as $comp) {
                $key = $comp['component'];
                if (isset($earnings[$key])) {
                    $earnings[$key] = round((float)$comp['amount'], 2);
                } else {
                    $earnings['other_earnings'] += round((float)$comp['amount'], 2);
                }
            }
            $gross = round(array_sum($earnings), 2);

            // Calculate deductions
            $deductions = $this->calculateDeductions($rid, $period);
            $totalDeduct = round(array_sum($deductions), 2);
            $net = round($gross - $totalDeduct, 2);

            // Leave impact
            $leaveData = $this->getLeaveImpact($rid, $period);

            // Check existing disbursements for this period+employee
            $disbStmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM hrm_disbursements
                 WHERE period_id = ? AND retailer_id = ? AND status != 'cancelled'"
            );
            $disbStmt->execute([$periodId, $rid]);
            $alreadyDisbursed = round((float)$disbStmt->fetchColumn(), 2);
            $balanceDue = round($net - $alreadyDisbursed, 2);

            // Determine disbursement status
            $dStatus = 'pending';
            if ($alreadyDisbursed >= $net && $net > 0) $dStatus = 'paid';
            elseif ($alreadyDisbursed > 0) $dStatus = 'partial';

            // Upsert payroll line
            $this->pdo->prepare(
                "INSERT INTO hrm_payroll_lines
                 (period_id, retailer_id, employee_name, employee_code,
                  base_salary, transport, food, housing, bonus, other_earnings, gross_pay,
                  advance_deduct, loan_deduct, penalty_deduct, leave_deduct, other_deduct, total_deductions,
                  net_pay, currency, total_disbursed, balance_due, disbursement_status,
                  days_present, days_absent, days_leave,
                  created_at, updated_at)
                 VALUES (?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?)
                 ON CONFLICT(period_id, retailer_id) DO UPDATE SET
                  employee_name=excluded.employee_name, employee_code=excluded.employee_code,
                  base_salary=excluded.base_salary, transport=excluded.transport,
                  food=excluded.food, housing=excluded.housing,
                  bonus=excluded.bonus, other_earnings=excluded.other_earnings,
                  gross_pay=excluded.gross_pay,
                  advance_deduct=excluded.advance_deduct, loan_deduct=excluded.loan_deduct,
                  penalty_deduct=excluded.penalty_deduct, leave_deduct=excluded.leave_deduct,
                  other_deduct=excluded.other_deduct, total_deductions=excluded.total_deductions,
                  net_pay=excluded.net_pay,
                  total_disbursed=excluded.total_disbursed, balance_due=excluded.balance_due,
                  disbursement_status=excluded.disbursement_status,
                  days_present=excluded.days_present, days_absent=excluded.days_absent,
                  days_leave=excluded.days_leave,
                  updated_at=excluded.updated_at"
            )->execute([
                $periodId, $rid, $emp['name'] ?? '', $emp['employee_code'] ?? '',
                $earnings['base_salary'], $earnings['transport'], $earnings['food'],
                $earnings['housing'], $earnings['bonus'], $earnings['other_earnings'], $gross,
                $deductions['advance'] ?? 0, $deductions['loan'] ?? 0,
                $deductions['penalty'] ?? 0, $deductions['leave'] ?? 0,
                $deductions['other'] ?? 0, $totalDeduct,
                $net, 'USD', $alreadyDisbursed, $balanceDue, $dStatus,
                $leaveData['present'] ?? $this->workingDaysPerMonth,
                $leaveData['absent'] ?? 0, $leaveData['leave'] ?? 0,
                date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
            ]);

            $totalGross += $gross;
            $totalDeductions += $totalDeduct;
            $totalNet += $net;
            $count++;
        }

        // Update period totals
        $this->pdo->prepare(
            "UPDATE hrm_payroll_periods SET
                status = 'calculated', calculated_at = ?, calculated_by = ?,
                total_gross = ?, total_deductions = ?, total_net = ?,
                employee_count = ?
             WHERE id = ?"
        )->execute([
            date('Y-m-d H:i:s'), $calculatedBy,
            round($totalGross, 2), round($totalDeductions, 2), round($totalNet, 2),
            $count, $periodId,
        ]);

        return [
            'ok'          => true,
            'employees'   => $count,
            'total_gross' => round($totalGross, 2),
            'total_net'   => round($totalNet, 2),
        ];
    }

    /**
     * Calculate deductions for an employee in a period.
     * Currently: salary advance recovery only. Expandable for loans, penalties.
     */
    private function calculateDeductions(int $retailerId, array $period): array
    {
        // For now, return zeros — advance recovery and loan deductions
        // will be Phase 2 (requires linking to cash_advances system).
        return [
            'advance' => 0.0,
            'loan'    => 0.0,
            'penalty' => 0.0,
            'leave'   => 0.0,
            'other'   => 0.0,
        ];
    }

    /**
     * Get leave impact for payroll (unpaid leave days in the period).
     */
    private function getLeaveImpact(int $retailerId, array $period): array
    {
        $unpaidDays = 0;
        $leaveDays  = 0;

        try {
            $stmt = $this->pdo->prepare(
                "SELECT lr.days, lt.is_paid
                 FROM hrm_leave_requests lr
                 JOIN hrm_leave_types lt ON lr.leave_type_id = lt.id
                 WHERE lr.retailer_id = ? AND lr.status = 'approved'
                   AND lr.start_date <= ? AND lr.end_date >= ?"
            );
            $stmt->execute([$retailerId, $period['period_end'], $period['period_start']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $lr) {
                $leaveDays += (int)$lr['days'];
                if (!(int)$lr['is_paid']) {
                    $unpaidDays += (int)$lr['days'];
                }
            }
        } catch (\Throwable $e) {
            // Leave tables may not exist yet — graceful fallback
        }

        return [
            'present' => max(0, $this->workingDaysPerMonth - $leaveDays),
            'absent'  => 0, // attendance tracking is Phase 2
            'leave'   => $leaveDays,
            'unpaid'  => $unpaidDays,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // APPROVAL
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Approve a calculated payroll period.
     */
    public function approve(int $periodId, string $approvedBy): array
    {
        $period = $this->getPeriodById($periodId);
        if (!$period) return ['ok' => false, 'error' => 'Period not found'];
        if (!in_array($period['status'], ['calculated', 'approved'])) {
            return ['ok' => false, 'error' => 'Period must be calculated first'];
        }

        $this->pdo->prepare(
            "UPDATE hrm_payroll_periods SET status = 'approved', approved_at = ?, approved_by = ? WHERE id = ?"
        )->execute([date('Y-m-d H:i:s'), $approvedBy, $periodId]);

        return ['ok' => true, 'status' => 'approved'];
    }

    // ══════════════════════════════════════════════════════════════════════
    // PAYROLL LINES (view per period)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Get all payroll lines for a period.
     */
    public function getPayrollLines(int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM hrm_payroll_lines WHERE period_id = ? ORDER BY employee_name ASC"
        );
        $stmt->execute([$periodId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a single payroll line by ID.
     */
    public function getPayrollLine(int $lineId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hrm_payroll_lines WHERE id = ?');
        $stmt->execute([$lineId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Adjust a payroll line (bonus, penalty, notes) before or after approval.
     */
    public function adjustLine(int $lineId, array $data): array
    {
        $line = $this->getPayrollLine($lineId);
        if (!$line) return ['ok' => false, 'error' => 'Line not found'];

        $allowed = ['bonus', 'penalty_deduct', 'other_deduct', 'other_earnings', 'notes'];
        $sets = [];
        $params = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "{$f} = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($sets)) return ['ok' => false, 'error' => 'Nothing to adjust'];

        // Recalculate totals
        $bonus   = (float)($data['bonus']          ?? $line['bonus']);
        $otherE  = (float)($data['other_earnings']  ?? $line['other_earnings']);
        $penD    = (float)($data['penalty_deduct']   ?? $line['penalty_deduct']);
        $othD    = (float)($data['other_deduct']     ?? $line['other_deduct']);

        $gross = round((float)$line['base_salary'] + (float)$line['transport'] + (float)$line['food']
                     + (float)$line['housing'] + $bonus + $otherE, 2);
        $totalD = round((float)$line['advance_deduct'] + (float)$line['loan_deduct']
                      + $penD + (float)$line['leave_deduct'] + $othD, 2);
        $net = round($gross - $totalD, 2);
        $balance = round($net - (float)$line['total_disbursed'], 2);

        $sets[] = 'gross_pay = ?';        $params[] = $gross;
        $sets[] = 'total_deductions = ?'; $params[] = $totalD;
        $sets[] = 'net_pay = ?';          $params[] = $net;
        $sets[] = 'balance_due = ?';      $params[] = $balance;
        $sets[] = 'updated_at = ?';       $params[] = date('Y-m-d H:i:s');
        $params[] = $lineId;

        $this->pdo->prepare(
            'UPDATE hrm_payroll_lines SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);

        return ['ok' => true, 'net_pay' => $net, 'balance_due' => $balance];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DISBURSEMENT — The core innovation: partial payments are first-class
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Record a salary disbursement (payment) against a payroll line.
     *
     * This is the KEY method that:
     *   1. Creates hrm_disbursements record
     *   2. Auto-posts to cb_ledger (source='payroll', payroll_ref='PR-YYYY-MM')
     *   3. Updates payroll line totals
     *   4. Does NOT create cash_ins.json (salary = personal pay)
     *
     * @param int   $lineId  Payroll line ID
     * @param float $amount  Amount being paid (can be partial)
     * @param array $opts    Options: paid_by_id, paid_by_name, voucher_ref, description,
     *                       payment_method, component, payment_type
     */
    public function disburse(int $lineId, float $amount, array $opts = []): array
    {
        $line = $this->getPayrollLine($lineId);
        if (!$line) return ['ok' => false, 'error' => 'Payroll line not found'];

        $period = $this->getPeriodById((int)$line['period_id']);
        if (!$period) return ['ok' => false, 'error' => 'Period not found'];
        if ($period['status'] === 'closed') return ['ok' => false, 'error' => 'Period is closed'];
        if (!in_array($period['status'], ['approved', 'calculated'])) {
            return ['ok' => false, 'error' => 'Period must be approved first'];
        }

        $amount = round($amount, 2);
        if ($amount <= 0) return ['ok' => false, 'error' => 'Amount must be > 0'];

        $balanceDue = round((float)$line['balance_due'], 2);
        if ($amount > $balanceDue + 0.01) { // small tolerance
            return ['ok' => false, 'error' => "Amount \${$amount} exceeds balance due \${$balanceDue}"];
        }

        $component   = $opts['component']      ?? 'base_salary';
        $paymentType = $opts['payment_type']    ?? 'salary';
        $paidByName  = $opts['paid_by_name']    ?? 'Rupesh';
        $paidById    = (int)($opts['paid_by_id'] ?? 0);
        $voucherRef  = $opts['voucher_ref']     ?? '';
        $payMethod   = $opts['payment_method']  ?? 'cash';
        $description = $opts['description']     ?? '';
        $now         = date('Y-m-d H:i:s');

        // Build description if not provided
        if (!$description) {
            $description = ucfirst($paymentType) . ' — ' . $line['employee_name'] . ' for ' . $period['period'];
        }

        // 1. Insert disbursement record
        $this->pdo->prepare(
            "INSERT INTO hrm_disbursements
             (payroll_line_id, period_id, retailer_id, employee_name,
              amount, currency, component, payment_type, payment_method,
              description, paid_by_id, paid_by_name, voucher_ref,
              status, paid_at, created_at, updated_at)
             VALUES (?,?,?,?, ?,?,?,?,?, ?,?,?,?, 'paid',?,?,?)"
        )->execute([
            $lineId, (int)$line['period_id'], (int)$line['retailer_id'], $line['employee_name'],
            $amount, $line['currency'] ?? 'USD', $component, $paymentType, $payMethod,
            $description, $paidById, $paidByName, $voucherRef,
            $now, $now, $now,
        ]);
        $disbId = (int)$this->pdo->lastInsertId();

        // 2. Post to cashbook (if CashbookService available)
        $cbSr = '';
        if ($this->cb) {
            $cbCategory = self::COMPONENT_CB_MAP[$component] ?? 'Salary';
            $payrollRef = 'PR-' . $period['period'];
            $valRef     = 'HRM-DISB-' . $disbId;

            try {
                $cbSr = $this->cb->addEntryRaw([
                    'direction'         => 'out',
                    'amount'            => $amount,
                    'currency'          => $line['currency'] ?? 'USD',
                    'category'          => $cbCategory,
                    'person'            => $line['employee_name'],
                    'description'       => $description,
                    'validation_ref'    => $valRef,
                    'validation_status' => $voucherRef ? 'voucher' : 'na',
                    'source'            => 'payroll',
                    'payroll_ref'       => $payrollRef,
                    'project'           => 'dishnet',
                    'approved_by'       => $paidByName,
                    'status'            => 'approved',
                ]);

            } catch (\Throwable $e) {
                // Log but don't fail the disbursement
                $cbSr = 'CB_ERROR: ' . $e->getMessage();
            }

            // Update disbursement with CB reference
            $this->pdo->prepare(
                "UPDATE hrm_disbursements SET cb_sr = ?, cb_posted = 1, cb_posted_at = ? WHERE id = ?"
            )->execute([$cbSr, $now, $disbId]);
        }

        // 3. Update payroll line totals
        $newDisbursed = round((float)$line['total_disbursed'] + $amount, 2);
        $newBalance   = round((float)$line['net_pay'] - $newDisbursed, 2);
        $dStatus = 'partial';
        if ($newBalance <= 0.01) $dStatus = 'paid';

        $this->pdo->prepare(
            "UPDATE hrm_payroll_lines SET
                total_disbursed = ?, balance_due = ?, disbursement_status = ?, updated_at = ?
             WHERE id = ?"
        )->execute([$newDisbursed, max(0, $newBalance), $dStatus, $now, $lineId]);

        // 4. Update period total_disbursed
        $this->pdo->prepare(
            "UPDATE hrm_payroll_periods SET total_disbursed = (
                SELECT COALESCE(SUM(total_disbursed), 0) FROM hrm_payroll_lines WHERE period_id = ?
             ) WHERE id = ?"
        )->execute([(int)$line['period_id'], (int)$line['period_id']]);

        return [
            'ok'              => true,
            'disbursement_id' => $disbId,
            'amount'          => $amount,
            'cb_sr'           => $cbSr,
            'total_disbursed' => $newDisbursed,
            'balance_due'     => max(0, $newBalance),
            'status'          => $dStatus,
        ];
    }

    /**
     * Get disbursement history for a payroll line.
     */
    public function getDisbursements(int $lineId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM hrm_disbursements WHERE payroll_line_id = ? ORDER BY paid_at ASC"
        );
        $stmt->execute([$lineId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get all disbursements for a period.
     */
    public function getPeriodDisbursements(int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM hrm_disbursements WHERE period_id = ? ORDER BY paid_at ASC"
        );
        $stmt->execute([$periodId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Cancel a disbursement (soft — marks as cancelled, does NOT reverse cashbook).
     */
    public function cancelDisbursement(int $disbId, string $reason = ''): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hrm_disbursements WHERE id = ?');
        $stmt->execute([$disbId]);
        $disb = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$disb) return ['ok' => false, 'error' => 'Disbursement not found'];
        if ($disb['status'] === 'cancelled') return ['ok' => false, 'error' => 'Already cancelled'];

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare(
            "UPDATE hrm_disbursements SET status = 'cancelled', description = description || ' [CANCELLED: " . addslashes($reason) . "]', updated_at = ? WHERE id = ?"
        )->execute([$now, $disbId]);

        // Recalculate payroll line totals
        $lineId = (int)$disb['payroll_line_id'];
        $line = $this->getPayrollLine($lineId);
        if ($line) {
            $newTotal = $this->sumDisbursements($lineId);
            $newBalance = round((float)$line['net_pay'] - $newTotal, 2);
            $dStatus = $newTotal <= 0 ? 'pending' : ($newBalance <= 0.01 ? 'paid' : 'partial');
            $this->pdo->prepare(
                "UPDATE hrm_payroll_lines SET total_disbursed = ?, balance_due = ?, disbursement_status = ?, updated_at = ? WHERE id = ?"
            )->execute([$newTotal, max(0, $newBalance), $dStatus, $now, $lineId]);
        }

        return ['ok' => true, 'cancelled' => true, 'note' => 'Cashbook entry must be reversed manually if needed'];
    }

    // ══════════════════════════════════════════════════════════════════════
    // CLOSE PERIOD
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Close a payroll period. Checks all employees are paid or held.
     */
    public function closePeriod(int $periodId): array
    {
        $period = $this->getPeriodById($periodId);
        if (!$period) return ['ok' => false, 'error' => 'Period not found'];
        if ($period['status'] === 'closed') return ['ok' => false, 'error' => 'Already closed'];

        // Check for unpaid lines
        $unpaid = $this->pdo->prepare(
            "SELECT COUNT(*) FROM hrm_payroll_lines
             WHERE period_id = ? AND disbursement_status NOT IN ('paid','held')"
        );
        $unpaid->execute([$periodId]);
        $unpaidCount = (int)$unpaid->fetchColumn();

        $this->pdo->prepare(
            "UPDATE hrm_payroll_periods SET status = 'closed', closed_at = ? WHERE id = ?"
        )->execute([date('Y-m-d H:i:s'), $periodId]);

        return [
            'ok'     => true,
            'status' => 'closed',
            'unpaid' => $unpaidCount,
            'note'   => $unpaidCount > 0
                ? "{$unpaidCount} employee(s) have outstanding balance — consider carrying forward"
                : 'All employees fully paid',
        ];
    }

    /**
     * Hold an employee's salary (intentionally withhold — e.g. absent without leave).
     */
    public function holdLine(int $lineId, string $reason = ''): array
    {
        $line = $this->getPayrollLine($lineId);
        if (!$line) return ['ok' => false, 'error' => 'Line not found'];

        $notes = $line['notes'] ? $line['notes'] . '; ' : '';
        $notes .= 'HELD: ' . ($reason ?: 'No reason given') . ' (' . date('Y-m-d') . ')';

        $this->pdo->prepare(
            "UPDATE hrm_payroll_lines SET disbursement_status = 'held', notes = ?, updated_at = ? WHERE id = ?"
        )->execute([$notes, date('Y-m-d H:i:s'), $lineId]);

        return ['ok' => true, 'status' => 'held'];
    }

    // ══════════════════════════════════════════════════════════════════════
    // PAYSLIP DATA — for WhatsApp and PDF generation
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Generate payslip data for an employee in a period.
     */
    public function getPayslipData(int $periodId, int $retailerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM hrm_payroll_lines WHERE period_id = ? AND retailer_id = ?'
        );
        $stmt->execute([$periodId, $retailerId]);
        $line = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$line) return null;

        $period = $this->getPeriodById($periodId);
        $disbursements = $this->getDisbursements((int)$line['id']);

        // Get retailer phone for WhatsApp
        $retailer = $this->store->findOne('retailers.json', 'id', $retailerId);

        return [
            'period'          => $period['period'] ?? '',
            'period_start'    => $period['period_start'] ?? '',
            'period_end'      => $period['period_end'] ?? '',
            'employee_name'   => $line['employee_name'],
            'employee_code'   => $line['employee_code'] ?? '',
            'phone'           => $retailer['phone'] ?? '',
            'earnings'        => [
                'Base Salary'         => (float)$line['base_salary'],
                'Transport Allowance' => (float)$line['transport'],
                'Food Allowance'      => (float)$line['food'],
                'Housing Allowance'   => (float)$line['housing'],
                'Bonus'               => (float)$line['bonus'],
                'Other'               => (float)$line['other_earnings'],
            ],
            'gross_pay'       => (float)$line['gross_pay'],
            'deductions'      => [
                'Advance Recovery' => (float)$line['advance_deduct'],
                'Loan EMI'         => (float)$line['loan_deduct'],
                'Penalty'          => (float)$line['penalty_deduct'],
                'Unpaid Leave'     => (float)$line['leave_deduct'],
                'Other'            => (float)$line['other_deduct'],
            ],
            'total_deductions' => (float)$line['total_deductions'],
            'net_pay'          => (float)$line['net_pay'],
            'disbursements'    => array_map(function($d) {
                return [
                    'amount'  => (float)$d['amount'],
                    'date'    => substr($d['paid_at'] ?? '', 0, 10),
                    'type'    => $d['payment_type'] ?? 'salary',
                    'method'  => $d['payment_method'] ?? 'cash',
                    'voucher' => $d['voucher_ref'] ?? '',
                ];
            }, $disbursements),
            'total_disbursed'  => (float)$line['total_disbursed'],
            'balance_due'      => (float)$line['balance_due'],
            'status'           => $line['disbursement_status'],
        ];
    }

    /**
     * Get payslip history for an employee across all periods.
     */
    public function getPayslipHistory(int $retailerId, int $limit = 12): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pl.*, pp.period, pp.period_start, pp.period_end, pp.status as period_status
             FROM hrm_payroll_lines pl
             JOIN hrm_payroll_periods pp ON pl.period_id = pp.id
             WHERE pl.retailer_id = ?
             ORDER BY pp.period DESC
             LIMIT ?"
        );
        $stmt->execute([$retailerId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════
    // INTERNALS
    // ══════════════════════════════════════════════════════════════════════

    private function getPeriodById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hrm_payroll_periods WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function sumDisbursements(int $lineId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM hrm_disbursements
             WHERE payroll_line_id = ? AND status != 'cancelled'"
        );
        $stmt->execute([$lineId]);
        return round((float)$stmt->fetchColumn(), 2);
    }
}
