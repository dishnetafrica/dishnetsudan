<?php
declare(strict_types=1);
if (!function_exists('str_contains'))   { function str_contains(string $h, string $n): bool   { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool{ return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with'))  { function str_ends_with(string $h, string $n): bool  { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * HrmService — DishNet Hybrid Telecom v4.11.0
 *
 * Employee HR profiles and salary structure management.
 * Extends retailer accounts (retailers.json) with HR-specific data
 * stored in SQLite (hrm_employees, hrm_salary_structures).
 *
 * Usage:
 *   $hrm = new HrmService($store, $store->getPdo());
 *   $employees = $hrm->listEmployees();
 *   $hrm->upsertProfile(42, ['designation' => 'Field Agent', ...]);
 *   $hrm->setSalaryComponent(42, 'base_salary', 'Base Salary', 400.00, '2026-03-01');
 */
class HrmService
{
    private \StoreInterface $store;
    private \PDO            $pdo;

    // Departments available at DishNet
    const DEPARTMENTS = [
        'operations', 'finance', 'security', 'household',
        'tech', 'management', 'sales', 'support',
    ];

    // Employment types
    const EMPLOYMENT_TYPES = [
        'full_time', 'part_time', 'contract', 'casual',
    ];

    // Salary component keys (standard)
    const COMPONENTS = [
        'base_salary'  => 'Base Salary',
        'transport'    => 'Transport Allowance',
        'food'         => 'Food Allowance',
        'housing'      => 'Housing Allowance',
        'bonus'        => 'Bonus',
        'other'        => 'Other Allowance',
    ];

    public function __construct(\StoreInterface $store, \PDO $pdo)
    {
        $this->store = $store;
        $this->pdo   = $pdo;
    }

    // ══════════════════════════════════════════════════════════════════════
    // EMPLOYEE PROFILES
    // ══════════════════════════════════════════════════════════════════════

    /**
     * List all employees with their HR profiles merged with retailer data.
     * Optionally filter by status/department.
     */
    public function listEmployees(string $status = '', string $department = ''): array
    {
        $retailers = $this->store->load('retailers.json') ?? [];
        // Index retailers by id
        $rMap = [];
        foreach ($retailers as $r) {
            $rMap[(int)($r['id'] ?? 0)] = $r;
        }

        $sql = 'SELECT * FROM hrm_employees';
        $params = [];
        $where  = [];
        if ($status) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }
        if ($department) {
            $where[]  = 'department = ?';
            $params[] = $department;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY employee_code ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $rid = (int)$row['retailer_id'];
            $retailer = $rMap[$rid] ?? [];
            $row['name']      = $retailer['name']     ?? '(unknown)';
            $row['phone']     = $retailer['phone']    ?? '';
            $row['role']      = $retailer['role']     ?? 'sales';
            $row['is_active'] = !empty($retailer['is_active']);
            $row['gross_salary'] = $this->getGrossSalary($rid);
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Get a single employee HR profile by retailer_id.
     */
    public function getProfile(int $retailerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hrm_employees WHERE retailer_id = ?');
        $stmt->execute([$retailerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        $retailer = $this->store->findOne('retailers.json', 'id', $retailerId);
        $row['name']  = $retailer['name']  ?? '';
        $row['phone'] = $retailer['phone'] ?? '';
        $row['role']  = $retailer['role']  ?? '';
        return $row;
    }

    /**
     * Create or update an employee HR profile.
     * Pass only the fields you want to change.
     */
    public function upsertProfile(int $retailerId, array $data): array
    {
        $existing = $this->getProfile($retailerId);
        $allowedFields = [
            'employee_code', 'designation', 'department', 'employment_type',
            'join_date', 'contract_end', 'probation_end',
            'bank_name', 'bank_account', 'bank_branch', 'mobile_money_no',
            'emergency_name', 'emergency_phone', 'emergency_rel',
            'id_type', 'id_number', 'nationality',
            'status', 'termination_date', 'termination_reason', 'notes',
        ];

        $fields = [];
        foreach ($allowedFields as $f) {
            if (array_key_exists($f, $data)) {
                $fields[$f] = $data[$f];
            }
        }
        $fields['updated_at'] = date('Y-m-d H:i:s');

        if ($existing) {
            // UPDATE
            $sets = [];
            $params = [];
            foreach ($fields as $k => $v) {
                $sets[] = "{$k} = ?";
                $params[] = $v;
            }
            $params[] = $retailerId;
            $this->pdo->prepare(
                'UPDATE hrm_employees SET ' . implode(', ', $sets) . ' WHERE retailer_id = ?'
            )->execute($params);

            return ['ok' => true, 'action' => 'updated', 'retailer_id' => $retailerId];
        }

        // INSERT
        $fields['retailer_id'] = $retailerId;
        if (empty($fields['employee_code'])) {
            $fields['employee_code'] = $this->generateEmployeeCode();
        }
        $fields['created_at'] = date('Y-m-d H:i:s');

        $cols = array_keys($fields);
        $placeholders = array_fill(0, count($cols), '?');
        $this->pdo->prepare(
            'INSERT INTO hrm_employees (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
        )->execute(array_values($fields));

        return ['ok' => true, 'action' => 'created', 'retailer_id' => $retailerId, 'employee_code' => $fields['employee_code']];
    }

    /**
     * Auto-generate next employee code: DN-001, DN-002, ...
     */
    private function generateEmployeeCode(): string
    {
        $max = $this->pdo->query("SELECT MAX(CAST(REPLACE(employee_code, 'DN-', '') AS INTEGER)) FROM hrm_employees")->fetchColumn();
        $next = ($max ? (int)$max : 0) + 1;
        return 'DN-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
    }

    // ══════════════════════════════════════════════════════════════════════
    // SALARY STRUCTURE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Get current salary structure for an employee.
     * Returns only active components where effective_from <= today and effective_to is null.
     */
    public function getSalaryStructure(int $retailerId): array
    {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM hrm_salary_structures
             WHERE retailer_id = ? AND is_active = 1
               AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY component ASC"
        );
        $stmt->execute([$retailerId, $today, $today]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get salary structure effective at a specific date (for payroll calculation).
     */
    public function getSalaryStructureAt(int $retailerId, string $date): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM hrm_salary_structures
             WHERE retailer_id = ? AND is_active = 1
               AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY component ASC"
        );
        $stmt->execute([$retailerId, $date, $date]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Set (create or update) a salary component for an employee.
     * Closes any existing component with the same key.
     */
    public function setSalaryComponent(int $retailerId, string $component, string $label, float $amount, string $effectiveFrom, string $currency = 'USD'): array
    {
        // Close any existing active component of same type
        $this->pdo->prepare(
            "UPDATE hrm_salary_structures SET effective_to = ?, is_active = 0, updated_at = ?
             WHERE retailer_id = ? AND component = ? AND is_active = 1"
        )->execute([
            date('Y-m-d', strtotime($effectiveFrom) - 86400), // day before new effective
            date('Y-m-d H:i:s'),
            $retailerId,
            $component,
        ]);

        // Insert new component
        $this->pdo->prepare(
            "INSERT INTO hrm_salary_structures (retailer_id, component, label, amount, currency, effective_from, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $retailerId, $component, $label, $amount, $currency,
            $effectiveFrom, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);

        return ['ok' => true, 'component' => $component, 'amount' => $amount, 'effective_from' => $effectiveFrom];
    }

    /**
     * Bulk-set salary structure for an employee.
     * Expects: ['base_salary' => 400, 'transport' => 40, 'food' => 60, ...]
     */
    public function setSalaryStructure(int $retailerId, array $components, string $effectiveFrom, string $currency = 'USD'): array
    {
        $results = [];
        foreach ($components as $key => $amount) {
            $label = self::COMPONENTS[$key] ?? ucfirst(str_replace('_', ' ', $key));
            if ((float)$amount > 0) {
                $results[] = $this->setSalaryComponent($retailerId, $key, $label, (float)$amount, $effectiveFrom, $currency);
            }
        }
        return ['ok' => true, 'components_set' => count($results)];
    }

    /**
     * Get gross salary (sum of active components) for an employee.
     */
    public function getGrossSalary(int $retailerId): float
    {
        $components = $this->getSalaryStructure($retailerId);
        return round(array_sum(array_column($components, 'amount')), 2);
    }

    /**
     * Get salary history for an employee (all components including expired).
     */
    public function getSalaryHistory(int $retailerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM hrm_salary_structures WHERE retailer_id = ? ORDER BY effective_from DESC, component ASC"
        );
        $stmt->execute([$retailerId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DATA SEEDING — Import existing employees from retailers + cashbook
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Seed HR profiles from retailers.json for all employees.
     * Skips already-seeded employees.
     * Returns count of created profiles.
     */
    public function seedFromRetailers(): array
    {
        $retailers = $this->store->load('retailers.json') ?? [];
        $created = 0;
        $skipped = 0;

        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;
            // Only seed is_employee (or roles that imply employee)
            $isEmp = ($r['is_employee'] ?? true); // default true — DishNet staff
            if (!$isEmp) continue;

            $rid = (int)($r['id'] ?? 0);
            if ($rid <= 0) continue;

            // Already has HR profile?
            $existing = $this->getProfile($rid);
            if ($existing) { $skipped++; continue; }

            // Map role to department
            $roleDept = [
                'admin'            => 'management',
                'accountant'       => 'finance',
                'field_accountant' => 'finance',
                'field_agent'      => 'operations',
                'sales'            => 'sales',
                'collection'       => 'operations',
                'support'          => 'support',
                'support_leader'   => 'support',
                'noc'              => 'tech',
            ];
            $dept = $roleDept[$r['role'] ?? 'sales'] ?? 'operations';

            $this->upsertProfile($rid, [
                'designation'     => ucfirst(str_replace('_', ' ', $r['role'] ?? 'staff')),
                'department'      => $dept,
                'employment_type' => 'full_time',
                'status'          => 'active',
            ]);
            $created++;
        }

        return ['ok' => true, 'created' => $created, 'skipped' => $skipped];
    }

    /**
     * Estimate salary components from cashbook history.
     * Scans cb_ledger for "Salary", "Transport Allowance", "Food Allowance"
     * entries per person and computes the mode (most common amount) as
     * the monthly rate.
     *
     * Call AFTER seedFromRetailers().
     * Returns count of components set.
     */
    public function seedSalaryFromCashbook(string $effectiveFrom = ''): array
    {
        if (!$effectiveFrom) {
            $effectiveFrom = date('Y-m-01'); // 1st of current month
        }

        $catMap = [
            'Salary'              => 'base_salary',
            'Transport Allowance' => 'transport',
            'Food Allowance'      => 'food',
        ];

        // Get all employees
        $stmt = $this->pdo->query('SELECT retailer_id FROM hrm_employees WHERE status = \'active\'');
        $employees = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // Get retailers for name matching
        $retailers = $this->store->load('retailers.json') ?? [];
        $nameToId  = [];
        foreach ($retailers as $r) {
            if (!empty($r['name']) && !empty($r['id'])) {
                $nameToId[strtolower(trim($r['name']))] = (int)$r['id'];
            }
        }

        // Query last 6 months of salary/transport/food from cashbook
        $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
        $cbStmt = $this->pdo->prepare(
            "SELECT person, category, amount
             FROM cb_ledger
             WHERE direction = 'out'
               AND category IN ('Salary','Transport Allowance','Food Allowance')
               AND date >= ?
               AND amount > 0
             ORDER BY person, category"
        );
        $cbStmt->execute([$sixMonthsAgo]);

        // Group: person → category → [amounts]
        $data = [];
        foreach ($cbStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $person = strtolower(trim($row['person']));
            $cat    = $row['category'];
            if (!$person) continue;
            $data[$person][$cat][] = (float)$row['amount'];
        }

        $componentsSet = 0;

        foreach ($data as $personLower => $cats) {
            // Match person to employee
            $rid = $nameToId[$personLower] ?? 0;
            if (!$rid) {
                // Fuzzy match
                foreach ($nameToId as $rName => $rId) {
                    if (strpos($rName, $personLower) !== false || strpos($personLower, $rName) !== false) {
                        $rid = $rId;
                        break;
                    }
                }
            }
            if (!$rid || !in_array($rid, $employees)) continue;

            // Check if already has salary structure
            $existing = $this->getSalaryStructure($rid);
            if (!empty($existing)) continue; // don't overwrite

            foreach ($cats as $cat => $amounts) {
                $compKey = $catMap[$cat] ?? null;
                if (!$compKey) continue;
                // Use mode (most common amount) as the monthly rate
                $freq = array_count_values(array_map(function($a) { return (string)round($a, 2); }, $amounts));
                arsort($freq);
                $modeAmount = (float)array_key_first($freq);
                if ($modeAmount > 0) {
                    $label = self::COMPONENTS[$compKey] ?? $cat;
                    $this->setSalaryComponent($rid, $compKey, $label, $modeAmount, $effectiveFrom);
                    $componentsSet++;
                }
            }
        }

        return ['ok' => true, 'components_set' => $componentsSet];
    }

    // ══════════════════════════════════════════════════════════════════════
    // STATS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Quick headcount and department stats for dashboard.
     */
    public function getStats(): array
    {
        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM hrm_employees WHERE status = 'active'")->fetchColumn();
        $byDept = $this->pdo->query(
            "SELECT department, COUNT(*) as cnt FROM hrm_employees WHERE status = 'active' GROUP BY department ORDER BY cnt DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $byType = $this->pdo->query(
            "SELECT employment_type, COUNT(*) as cnt FROM hrm_employees WHERE status = 'active' GROUP BY employment_type"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $totalGross = (float)$this->pdo->query(
            "SELECT COALESCE(SUM(amount), 0) FROM hrm_salary_structures WHERE is_active = 1"
        )->fetchColumn();

        return [
            'total_active'       => $total,
            'by_department'      => $byDept,
            'by_employment_type' => $byType,
            'monthly_payroll_est'=> round($totalGross, 2),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // BookKeeper Import (one-time seed)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Category prefixes in BookKeeper account names.
     * Pattern: "Salary-Nirav", "Loan Given-Mamma Bekary", etc.
     */
    private const BK_CATEGORY_MAP = [
        'salary'        => ['Salary', 'Remuneration', 'Wages'],
        'food'          => ['Food Allowance', 'Food Exp', 'Food'],
        'transport'     => ['Transportation', 'Transporation', 'Transport', 'Transport Allowance'],
        'commission'    => ['Commission', 'Sales Commission'],
        'bonus'         => ['Bonus', 'Performance Bonus'],
        'benefit'       => ['Employee Benefit', 'Benefit', 'Medical', 'Insurance'],
        'loan_given'    => ['Loan Given', 'Advance Given', 'Staff Advance', 'Staff Loan'],
        'loan_repaid'   => ['Loan Return', 'Loan Retunr', 'Loan Received', 'Loan Repayment',
                            'Advance Return', 'Advance Received'],
        'housing'       => ['Housing', 'Rent Allowance', 'House Allowance'],
    ];

    /**
     * Import employee financial history from a BookKeeper backup database.
     *
     * @param string $bkDbPath Path to uploaded BookKeeper .db file
     * @return array Summary of import results
     */
    public function seedFromBookKeeper(string $bkDbPath): array
    {
        if (!file_exists($bkDbPath)) {
            return ['ok' => false, 'error' => 'BookKeeper DB file not found.'];
        }

        try {
            $bk = new \PDO('sqlite:' . $bkDbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Cannot open BookKeeper DB: ' . $e->getMessage()];
        }

        // 1. Verify it's a real BookKeeper DB
        $tables = [];
        foreach ($bk->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
            $tables[] = $r['name'];
        }
        if (!in_array('vouchers', $tables) || !in_array('account_detail', $tables)) {
            return ['ok' => false, 'error' => 'Not a valid BookKeeper database (missing vouchers/account_detail tables).'];
        }

        // 2. Load all BookKeeper accounts
        $bkAccounts = $bk->query("SELECT aname, a_type, op_bal, cl_bal, type FROM account_detail")->fetchAll(\PDO::FETCH_ASSOC);

        // 3. Identify employee-related accounts by prefix matching
        $empAccounts = []; // account_name => ['category' => ..., 'employee' => ...]
        foreach ($bkAccounts as $acct) {
            $name = trim($acct['aname'] ?? '');
            if (!$name || strpos($name, '-') === false) continue;

            // Split "Category-EmployeeName"
            $dashPos = strpos($name, '-');
            $prefix  = trim(substr($name, 0, $dashPos));
            $empName = trim(substr($name, $dashPos + 1));
            if (!$empName || strlen($empName) < 2) continue;

            $category = $this->matchBkCategory($prefix);
            if ($category) {
                $empAccounts[$name] = [
                    'category'  => $category,
                    'employee'  => $empName,
                    'op_bal'    => (float)($acct['op_bal'] ?? 0),
                    'cl_bal'    => (float)($acct['cl_bal'] ?? 0),
                    'bal_type'  => $acct['type'] ?? 'd',
                ];
            }
        }

        if (empty($empAccounts)) {
            return ['ok' => false, 'error' => 'No employee-related accounts found in BookKeeper DB.'];
        }

        // 4. Build HRM employee name → retailer_id map for matching
        $hrmEmployees = $this->pdo->query("SELECT retailer_id, employee_code FROM hrm_employees WHERE status != 'terminated'")->fetchAll(\PDO::FETCH_ASSOC);
        $retailers = $this->store->load('retailers.json') ?? [];
        $nameToRid = [];
        foreach ($retailers as $r) {
            $rName = strtolower(trim($r['name'] ?? ''));
            if ($rName && !empty($r['id'])) {
                $nameToRid[$rName] = (int)$r['id'];
                // Also map first name only for fuzzy matching
                $first = explode(' ', $rName)[0];
                if (strlen($first) >= 3 && !isset($nameToRid[$first])) {
                    $nameToRid[$first] = (int)$r['id'];
                }
            }
        }

        // 5. Clear previous BookKeeper imports — run inside a transaction so a
        //    mid-import failure rolls back the DELETEs (no empty table left behind).
        $this->pdo->beginTransaction();
        try {
        $this->pdo->exec("DELETE FROM hrm_financial_history WHERE source = 'bookkeeper'");
        $this->pdo->exec("DELETE FROM hrm_loan_summary");

        // 6. Import vouchers for each employee account
        $insertStmt = $this->pdo->prepare(
            "INSERT INTO hrm_financial_history 
             (retailer_id, employee_name, bk_account, category, txn_date, amount, currency, direction,
              narration, voucher_no, voucher_type, bk_debit_acct, bk_credit_acct, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'bookkeeper')"
        );

        $imported = 0;
        $matched  = 0;
        $employees = [];

        foreach ($empAccounts as $acctName => $meta) {
            $empName = $meta['employee'];
            $category = $meta['category'];

            // Try to match to HRM employee
            $rid = $this->fuzzyMatchEmployee($empName, $nameToRid);
            if ($rid) $matched++;
            $employees[$empName] = $rid;

            // Fetch all vouchers where this account is debit or credit
            $vouchers = $bk->prepare(
                "SELECT date, debit, credit, amount, narration, v_type, vch_no 
                 FROM vouchers WHERE debit = ? OR credit = ? ORDER BY date, v_id"
            );
            $vouchers->execute([$acctName, $acctName]);

            foreach ($vouchers->fetchAll(\PDO::FETCH_ASSOC) as $v) {
                $isDr = ($v['debit'] === $acctName);
                // For employee accounts:
                //   Debit to "Salary-X" = company paying salary (paid to employee)
                //   Credit to "Loan Return-X" = employee returning money (received from employee)
                $direction = 'paid';
                $actualCat = $category;

                if (in_array($category, ['loan_repaid'])) {
                    // Loan repayment accounts: credit = company received money back
                    $direction = $isDr ? 'paid' : 'received';
                } elseif (in_array($category, ['loan_given'])) {
                    // Loan given: debit = money goes out to employee
                    $direction = $isDr ? 'paid' : 'received';
                } else {
                    // Salary/food/transport/etc: debit = expense paid to employee
                    $direction = $isDr ? 'paid' : 'received';
                }

                $insertStmt->execute([
                    $rid,
                    $empName,
                    $acctName,
                    $actualCat,
                    $v['date'] ?? '',
                    abs((float)($v['amount'] ?? 0)),
                    'USD',
                    $direction,
                    $v['narration'] ?? '',
                    $v['vch_no'] ?? '',
                    $v['v_type'] ?? '',
                    $v['debit'] ?? '',
                    $v['credit'] ?? '',
                ]);
                $imported++;
            }
        }

        // 7. Compute loan summaries
        $this->rebuildLoanSummaries();

        $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return ['ok' => false, 'error' => 'Import failed (rolled back): ' . $e->getMessage()];
        }

        return [
            'ok'               => true,
            'accounts_found'   => count($empAccounts),
            'employees_found'  => count($employees),
            'employees_matched'=> $matched,
            'vouchers_imported'=> $imported,
            'unmatched'        => array_keys(array_filter($employees, function($v) { return $v === null; })),
        ];
    }

    /**
     * Match a BookKeeper category prefix to our standard category.
     */
    private function matchBkCategory(string $prefix): ?string
    {
        $prefixLower = strtolower(trim($prefix));
        foreach (self::BK_CATEGORY_MAP as $category => $prefixes) {
            foreach ($prefixes as $p) {
                if (strtolower($p) === $prefixLower) return $category;
            }
        }
        return null;
    }

    /**
     * Seed from baked-in JSON (extracted from BookKeeper at build time).
     * Auto-runs once if hrm_financial_history is empty.
     *
     * @param string $pluginRoot Path to plugin root (where bk_employee_seed.json lives)
     * @return array Import summary
     */
    public function seedFromBakedJson(string $pluginRoot): array
    {
        $jsonPath = rtrim($pluginRoot, '/') . '/bk_employee_seed.json';
        if (!file_exists($jsonPath)) {
            return ['ok' => false, 'error' => 'Seed file not found: bk_employee_seed.json'];
        }

        $records = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($records) || empty($records)) {
            return ['ok' => false, 'error' => 'Seed file is empty or invalid.'];
        }

        // Build name → retailer_id map
        $retailers = $this->store->load('retailers.json') ?? [];
        $nameToRid = [];
        foreach ($retailers as $r) {
            $rName = strtolower(trim($r['name'] ?? ''));
            if ($rName && !empty($r['id'])) {
                $nameToRid[$rName] = (int)$r['id'];
                $first = explode(' ', $rName)[0];
                if (strlen($first) >= 3 && !isset($nameToRid[$first])) {
                    $nameToRid[$first] = (int)$r['id'];
                }
            }
        }

        // Clear previous imports — DELETEs are inside the transaction so a
        // mid-import failure rolls them back (table never left empty).
        $insertStmt = $this->pdo->prepare(
            "INSERT INTO hrm_financial_history
             (retailer_id, employee_name, bk_account, category, txn_date, amount, currency, direction,
              narration, voucher_no, voucher_type, bk_debit_acct, bk_credit_acct, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'bookkeeper')"
        );

        $imported = 0;
        $employees = [];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("DELETE FROM hrm_financial_history WHERE source = 'bookkeeper'");
            $this->pdo->exec("DELETE FROM hrm_loan_summary");
            foreach ($records as $r) {
                $empName = $r['employee'] ?? '';
                if (!$empName) continue;

                if (!isset($employees[$empName])) {
                    $employees[$empName] = $this->fuzzyMatchEmployee($empName, $nameToRid);
                }
                $rid = $employees[$empName];

                $insertStmt->execute([
                    $rid,
                    $empName,
                    $r['bk_account'] ?? '',
                    $r['category'] ?? 'other',
                    $r['date'] ?? '',
                    abs((float)($r['amount'] ?? 0)),
                    'USD',
                    $r['direction'] ?? 'paid',
                    $r['narration'] ?? '',
                    $r['voucher_no'] ?? '',
                    $r['voucher_type'] ?? '',
                    $r['dr_acct'] ?? '',
                    $r['cr_acct'] ?? '',
                ]);
                $imported++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => 'Import failed: ' . $e->getMessage()];
        }

        $this->rebuildLoanSummaries();

        $matched = count(array_filter($employees));
        return [
            'ok'                => true,
            'vouchers_imported' => $imported,
            'employees_found'   => count($employees),
            'employees_matched' => $matched,
            'unmatched'         => array_keys(array_filter($employees, function($v) { return $v === null; })),
        ];
    }

    /**
     * Fuzzy match an employee name from BookKeeper to HRM retailer_id.
     */
    private function fuzzyMatchEmployee(string $bkName, array $nameToRid): ?int
    {
        $lower = strtolower(trim($bkName));
        // Exact match
        if (isset($nameToRid[$lower])) return $nameToRid[$lower];
        // First name match
        $first = explode(' ', $lower)[0];
        if (strlen($first) >= 3 && isset($nameToRid[$first])) return $nameToRid[$first];
        // Contains match
        foreach ($nameToRid as $name => $rid) {
            if (strlen($name) >= 4 && (strpos($lower, $name) !== false || strpos($name, $lower) !== false)) {
                return $rid;
            }
        }
        return null;
    }

    /**
     * Rebuild loan summaries from hrm_financial_history.
     */
    public function rebuildLoanSummaries(): void
    {
        // Wrap DELETE + re-insert in a transaction so a failure mid-loop
        // never leaves hrm_loan_summary empty between cron cycles.
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("DELETE FROM hrm_loan_summary");

            $rows = $this->pdo->query("
                SELECT employee_name, retailer_id, currency,
                       SUM(CASE WHEN category = 'loan_given' AND direction = 'paid' THEN amount ELSE 0 END) as given,
                       SUM(CASE WHEN category IN ('loan_repaid','loan_given') AND direction = 'received' THEN amount ELSE 0 END) as repaid,
                       MAX(txn_date) as last_date
                FROM hrm_financial_history
                WHERE category IN ('loan_given', 'loan_repaid')
                GROUP BY employee_name, currency
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $stmt = $this->pdo->prepare(
                "INSERT INTO hrm_loan_summary (retailer_id, employee_name, total_given, total_repaid, balance, currency, last_txn_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($rows as $r) {
                $given  = (float)($r['given'] ?? 0);
                $repaid = (float)($r['repaid'] ?? 0);
                $stmt->execute([
                    $r['retailer_id'],
                    $r['employee_name'],
                    $given,
                    $repaid,
                    round($given - $repaid, 2),
                    $r['currency'] ?? 'USD',
                    $r['last_date'] ?? '',
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            error_log('[HrmService] rebuildLoanSummaries rolled back: ' . $e->getMessage());
        }
    }

    /**
     * Get financial history for an employee from BookKeeper import.
     */
    public function getFinancialHistory(int $retailerId, string $category = '', int $limit = 500): array
    {
        $sql = "SELECT * FROM hrm_financial_history WHERE retailer_id = ?";
        $params = [$retailerId];
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        $sql .= " ORDER BY txn_date DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get compensation breakdown for an employee (totals by category by year).
     */
    public function getCompensationBreakdown(int $retailerId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT category,
                   strftime('%Y', txn_date) as year,
                   SUM(CASE WHEN direction='paid' THEN amount ELSE 0 END) as paid,
                   SUM(CASE WHEN direction='received' THEN amount ELSE 0 END) as received,
                   COUNT(*) as txn_count
            FROM hrm_financial_history
            WHERE retailer_id = ? AND category NOT IN ('loan_given','loan_repaid')
            GROUP BY category, year
            ORDER BY year DESC, paid DESC
        ");
        $stmt->execute([$retailerId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get loan ledger for an employee (chronological with running balance).
     */
    public function getLoanLedger(int $retailerId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM hrm_financial_history
            WHERE retailer_id = ? AND category IN ('loan_given','loan_repaid')
            ORDER BY txn_date ASC, id ASC
        ");
        $stmt->execute([$retailerId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Compute running balance
        $balance = 0;
        foreach ($rows as &$r) {
            if ($r['direction'] === 'paid') {
                $balance += (float)$r['amount'];
            } else {
                $balance -= (float)$r['amount'];
            }
            $r['running_balance'] = round($balance, 2);
        }
        return $rows;
    }

    /**
     * Get loan summary for an employee.
     */
    public function getLoanSummary(int $retailerId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM hrm_loan_summary WHERE retailer_id = ?");
        $stmt->execute([$retailerId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get all loan summaries (for dashboard overview).
     */
    public function getAllLoanSummaries(): array
    {
        return $this->pdo->query(
            "SELECT * FROM hrm_loan_summary WHERE balance > 0 ORDER BY balance DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get total compensation summary per employee (for dashboard).
     */
    public function getCompensationTotals(): array
    {
        return $this->pdo->query("
            SELECT employee_name, retailer_id,
                   SUM(CASE WHEN category='salary' AND direction='paid' THEN amount ELSE 0 END) as salary,
                   SUM(CASE WHEN category='food' AND direction='paid' THEN amount ELSE 0 END) as food,
                   SUM(CASE WHEN category='transport' AND direction='paid' THEN amount ELSE 0 END) as transport,
                   SUM(CASE WHEN category='commission' AND direction='paid' THEN amount ELSE 0 END) as commission,
                   SUM(CASE WHEN category='bonus' AND direction='paid' THEN amount ELSE 0 END) as bonus,
                   SUM(CASE WHEN category='benefit' AND direction='paid' THEN amount ELSE 0 END) as benefit,
                   SUM(CASE WHEN category='housing' AND direction='paid' THEN amount ELSE 0 END) as housing,
                   SUM(CASE WHEN direction='paid' AND category NOT IN ('loan_given') THEN amount ELSE 0 END) as total_comp
            FROM hrm_financial_history
            GROUP BY employee_name
            ORDER BY total_comp DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);
    }
}
