<?php
declare(strict_types=1);
if (!function_exists('str_contains'))  { function str_contains(string $h, string $n): bool  { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool { return strncmp($h,$n,strlen($n))===0; } }

/**
 * TransactionIntegrityGuard  --  DishNet Hybrid v4.11.3
 * ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
 * Three-layer protection:
 *
 *  LAYER 1  --  pre-save validation  : warn/block BEFORE writing to the DB
 *  LAYER 2  --  post-save assertions : after write, verify the chain fired
 *  LAYER 3  --  periodic full audit  : scan every record, report broken links
 *
 * Usage
 * ---------------
 *   // Before saving:
 *   $issues = TransactionIntegrityGuard::preSave($context, $data, $store, $pdo);
 *   // $issues[] = ['level'=>'warn|block', 'rule'=>'...', 'msg'=>'...', 'affects'=>'...', 'fix'=>'...']
 *
 *   // After saving:
 *   TransactionIntegrityGuard::postSave($context, $savedRecord, $store, $pdo);
 *
 *   // Full audit (cron / admin tab):
 *   $report = TransactionIntegrityGuard::fullAudit($store, $pdo);
 *
 * PHP 7.4 compatible. No external dependencies beyond SqliteStore + PDO.
 */
class TransactionIntegrityGuard
{
    // ------ SSP rate sanity bounds (SSP per 1 USD) ---------------------------------------------------------------------------------------------------
    const SSP_RATE_MIN = 3000;
    const SSP_RATE_MAX = 35000;

    // ------ Large transaction thresholds ---------------------------------------------------------------------------------------------------------------------------------
    const LARGE_SSP_WARN    = 500000;   // warn above this
    const LARGE_SSP_BLOCK   = 5000000;  // hard block above this (typo guard)
    const LARGE_USD_WARN    = 500;
     const LARGE_USD_BLOCK   = 50000;  // hard block (typo guard -- raised from 5000 as salary can be $5000)

    // ------ Categories that REQUIRE a person for auto-link to fire ---------------------------------------------------
    const PERSON_REQUIRED_CATS = ['SSP Advance', 'Staff Advance', 'Commission', 'Loan Given'];

    // ------ Categories that produce secondary records via chain rules ------------------------------------------
    const CHAIN_CATS = ['SSP Advance', 'Staff Advance', 'Commission'];

    // ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    //  LAYER 1  --  PRE-SAVE VALIDATION
    // ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Run all pre-save rules. Returns array of issues.
     *
     * @param string $context  'cashbook_out' | 'cashbook_exchange' | 'expense_approve'
     *                         | 'cash_in_manual' | 'void_expense' | 'void_cashin'
     * @param array  $data     Form data / record being saved
     * @param mixed  $store    SqliteStore instance (for reading existing records)
     * @param PDO    $pdo      SQLite PDO (for staff_ledger queries)
     * @return array           List of issue arrays, each: [level, rule, msg, affects, fix]
     */
    public static function preSave(string $context, array $data, $store, \PDO $pdo): array
    {
        $issues = [];

        switch ($context) {
            case 'cashbook_out':
                $issues = array_merge($issues,
                    self::rulePersonRequired($data),
                    self::ruleSspRateSanity($data),
                    self::ruleLargeAmount($data),
                    self::ruleExchangeAmountConsistency($data),
                    self::ruleDuplicateCashbookOut($data, $store)
                );
                break;

            case 'cashbook_exchange':
                $issues = array_merge($issues,
                    self::ruleSspRateSanity($data),
                    self::ruleExchangeAmountConsistency($data),
                    self::ruleLargeAmount($data)
                );
                break;

            case 'expense_approve':
                $issues = array_merge($issues,
                    self::ruleDuplicateExpenseApproval($data, $store),
                    self::ruleExpenseVsAdvanceMismatch($data, $pdo)
                );
                break;

            case 'cash_in_manual':
                $issues = array_merge($issues,
                    self::ruleMissingCashbookCounterpart($data, $store),
                    self::ruleLargeAmount($data)
                );
                break;

            case 'void_expense':
                $issues = array_merge($issues,
                    self::ruleVoidCascadeWarning($data, $store, 'expense')
                );
                break;

            case 'void_cashin':
                $issues = array_merge($issues,
                    self::ruleVoidCascadeWarning($data, $store, 'cashin')
                );
                break;
        }

        return $issues;
    }

    // --------- Rule implementations ------------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * R01  --  Person required for advance/commission categories.
     * Without a person the auto-link chain cannot fire - ghost advance.
     */
    private static function rulePersonRequired(array $data): array
    {
        $cat    = trim($data['category'] ?? '');
        $person = trim($data['person'] ?? '');
        $dir    = strtolower($data['direction'] ?? 'out');

        if ($dir === 'out' && in_array($cat, self::PERSON_REQUIRED_CATS, true) && $person === '') {
            return [[
                'level'   => 'block',
                'rule'    => 'R01',
                'msg'     => 'Staff name is required for [' . $cat . '] -- Person field must be filled.',
                'affects' => "Without a name the SSP auto-link chain will NOT fire. "
                           . "The advance will appear as paid in Rupesh's cashbook "
                           . "but will be invisible in the staff's cashbook  --  "
                           . "causing a mismatch exactly like CB-2669.",
                'fix'     => 'Type the staff member\'s name in the Person field before saving.',
            ]];
        }
        return [];
    }

    /**
     * R02  --  SSP rate sanity check.
     * Catches typos like 58,000 instead of 5,800.
     */
    private static function ruleSspRateSanity(array $data): array
    {
        $rate = (float)($data['ssp_rate'] ?? $data['rate'] ?? 0);
        if ($rate <= 0) return [];

        if ($rate < self::SSP_RATE_MIN) {
            return [[
                'level'   => 'block',
                'rule'    => 'R02',
                'msg'     => "SSP rate {$rate} is too low (minimum " . number_format(self::SSP_RATE_MIN) . " SSP/USD).",
                'affects' => 'All SSP amounts calculated from this rate will be under-stated. '
                           . 'Staff cashbook and ledger balances will be wrong.',
                'fix'     => 'Check the current SSP/USD rate. Typical range: 5,000--8,000.',
            ]];
        }
        if ($rate > self::SSP_RATE_MAX) {
            return [[
                'level'   => 'block',
                'rule'    => 'R02',
                'msg'     => "SSP rate " . number_format($rate, 0) . " is too high (maximum " . number_format(self::SSP_RATE_MAX) . " SSP/USD). Possible typo.",
                'affects' => 'All SSP amounts will be 10 over-stated. '
                           . 'This is what caused the SSP 5,800,000 ghost entry on Apr 1 '
                           . '(rate 58,000 instead of 5,800). Staff balance will be massively wrong.',
                'fix'     => 'Divide the rate by 10. E.g. 58,000 - 5,800.',
            ]];
        }
        return [];
    }

    /**
     * R03  --  Exchange amount consistency: USD  rate must equal SSP (within 1%).
     */
    private static function ruleExchangeAmountConsistency(array $data): array
    {
        $cat    = trim($data['category'] ?? '');
        $issues = [];
        if ($cat !== 'Exchange' && strtolower($data['direction'] ?? '') !== 'exchange') {
            return [];
        }

        $usd  = (float)($data['amount'] ?? 0);
        $rate = (float)($data['ssp_rate'] ?? $data['rate'] ?? 0);
        $ssp  = (float)($data['ssp_amount'] ?? $data['exch_ssp_amount'] ?? 0);

        if ($usd > 0 && $rate > 0 && $ssp > 0) {
            $expected = round($usd * $rate, 0);
            $pct      = abs($ssp - $expected) / $expected;
            if ($pct > 0.01) {
                $issues[] = [
                    'level'   => 'block',
                    'rule'    => 'R03',
                    'msg'     => "Exchange mismatch: \${$usd}  {$rate} = SSP " . number_format($expected)
                               . " but entered SSP " . number_format($ssp)
                               . " (difference: SSP " . number_format(abs($ssp - $expected)) . ").",
                    'affects' => 'Both sides of the exchange (USD out and SSP in) will be recorded '
                               . 'with inconsistent amounts. Rupesh\'s cashbook will not balance.',
                    'fix'     => 'Recalculate: SSP = USD  rate. Use the auto-preview shown in the form.',
                ];
            }
        }
        return $issues;
    }

    /**
     * R04  --  Large amount warning/block.
     */
    private static function ruleLargeAmount(array $data): array
    {
        $issues = [];
        $ssp    = (float)($data['ssp_amount'] ?? 0);
        $usd    = (float)($data['amount'] ?? 0);
        $curr   = strtoupper($data['currency'] ?? 'USD');

        if ($curr === 'SSP' && $ssp > 0) {
            if ($ssp >= self::LARGE_SSP_BLOCK) {
                $issues[] = [
                    'level'   => 'block',
                    'rule'    => 'R04',
                    'msg'     => "SSP " . number_format($ssp, 0) . " exceeds the single-transaction limit of SSP "
                               . number_format(self::LARGE_SSP_BLOCK) . ". This is almost certainly a data entry error.",
                    'affects' => 'Would immediately make the receiving staff\'s balance appear as SSP '
                               . number_format($ssp) . ' which is unrealistic for a single transaction.',
                    'fix'     => 'Check the amount. If genuine, split into multiple entries and get Rupesh approval.',
                ];
            } elseif ($ssp >= self::LARGE_SSP_WARN) {
                $issues[] = [
                    'level'   => 'warn',
                    'rule'    => 'R04',
                    'msg'     => "Large SSP transaction: " . number_format($ssp, 0) . " SSP. Please confirm this is correct.",
                    'affects' => 'Staff cashbook will show this as a single large IN. If wrong it will inflate balance.',
                    'fix'     => 'Verify with Rupesh before proceeding.',
                ];
            }
        }

        // Skip large-amount check for payroll categories -- salary is always large
        $cat = trim($data['category'] ?? '');
        $_payrollCats = ['Salary', 'Transport Allowance', 'Food Allowance', 'Bonus', 'Employee Benefit', 'Commission', 'Payroll'];
        if (in_array($cat, $_payrollCats, true)) return [];

        if ($curr === 'USD' && $usd > 0) {
            if ($usd >= self::LARGE_USD_BLOCK) {
                $issues[] = [
                    'level'   => 'block',
                    'rule'    => 'R04',
                    'msg'     => "\${$usd} exceeds the single-transaction limit of \$" . number_format(self::LARGE_USD_BLOCK) . ".",
                    'affects' => 'Would create an implausibly large cashbook entry.',
                    'fix'     => 'Check the amount.',
                ];
            } elseif ($usd >= self::LARGE_USD_WARN) {
                $issues[] = [
                    'level'   => 'warn',
                    'rule'    => 'R04',
                    'msg'     => "Large USD transaction: \${$usd}. Please confirm this is correct.",
                    'affects' => 'Cashbook USD balance will increase by this amount.',
                    'fix'     => 'Verify with Rupesh.',
                ];
            }
        }
        return $issues;
    }

    /**
     * R05  --  Duplicate cashbook OUT.
     * Catches re-submitting the same advance twice (same person, category, amount, same day).
     */
    private static function ruleDuplicateCashbookOut(array $data, $store): array
    {
        if (strtolower($data['direction'] ?? '') !== 'out') return [];

        $cat    = trim($data['category'] ?? '');
        $person = strtolower(trim($data['person'] ?? ''));
        $ssp    = (float)($data['ssp_amount'] ?? 0);
        $usd    = (float)($data['amount'] ?? 0);
        $date   = substr($data['date'] ?? date('Y-m-d'), 0, 10);

        if (!in_array($cat, self::PERSON_REQUIRED_CATS, true) || $person === '') return [];

        try {
            $pdo = $store->getPdo();
            $stmt = $pdo->prepare(
                "SELECT sr, date, person, amount, ssp_amount FROM cb_ledger
                 WHERE LOWER(person) = ? AND category = ? AND date = ?
                   AND status NOT IN ('voided','rejected')
                 LIMIT 5"
            );
            $stmt->execute([$person, $cat, $date]);
            $existing = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($existing as $row) {
                $amtMatch = ($ssp > 0 && abs((float)($row['ssp_amount'] ?? 0) - $ssp) < 1)
                         || ($usd > 0 && abs((float)($row['amount'] ?? 0) - $usd) < 0.01);
                if ($amtMatch) {
                    return [[
                        'level'   => 'warn',
                        'rule'    => 'R05',
                        'msg'     => "Possible duplicate: a {$cat} of the same amount for "
                                   . htmlspecialchars($row['person'] ?? $person)
                                   . " already exists today (SR: " . htmlspecialchars($row['sr'] ?? '') . ").",
                        'affects' => 'Staff would receive double the advance in their cashbook. '
                                   . 'Balance inflated by '
                                   . ($ssp > 0 ? 'SSP ' . number_format($ssp) : '$' . number_format($usd, 2)) . '.',
                        'fix'     => 'Check SR ' . htmlspecialchars($row['sr'] ?? '') . ' before saving. '
                                   . 'If this is intentional, add a distinct description.',
                    ]];
                }
            }
        } catch (\Throwable $e) { /* non-fatal */ }
        return [];
    }

    /**
     * R06  --  Duplicate expense approval guard.
     */
    private static function ruleDuplicateExpenseApproval(array $data, $store): array
    {
        $expId  = (int)($data['expense_id'] ?? $data['id'] ?? 0);
        $amount = (float)($data['amount'] ?? $data['ssp_amount'] ?? 0);
        $staff  = strtolower(trim($data['staff_name'] ?? $data['collector_name'] ?? ''));
        $cat    = trim($data['category'] ?? '');
        $date   = substr($data['expense_date'] ?? $data['submitted_at'] ?? date('Y-m-d'), 0, 10);

        if ($expId <= 0 || $amount <= 0 || $staff === '') return [];

        try {
            $expenses = $store->load('cash_expenses.json') ?? [];
            $count = 0;
            foreach ($expenses as $e) {
                if ((int)($e['id'] ?? 0) === $expId) continue; // skip self
                if (($e['status'] ?? '') === 'approved'
                    && strtolower($e['collector_name'] ?? '') === $staff
                    && trim($e['category'] ?? '') === $cat
                    && substr($e['created_at'] ?? '', 0, 10) === $date
                    && abs((float)($e['ssp_amount'] ?? 0) - $amount) < 1
                ) {
                    $count++;
                }
            }
            if ($count > 0) {
                return [[
                    'level'   => 'warn',
                    'rule'    => 'R06',
                    'msg'     => "Similar approved expense found: {$cat} / " . number_format($amount) . " SSP for {$staff} on {$date}. Possible duplicate.",
                    'affects' => 'Diko\'s cashbook will show two OUT entries for the same expense, '
                               . 'reducing her balance by ' . number_format($amount) . ' SSP twice.',
                    'fix'     => 'Check expense list for today before approving.',
                ]];
            }
        } catch (\Throwable $e) { /* non-fatal */ }
        return [];
    }

    /**
     * R07  --  Expense amount vs advance balance mismatch.
     * Warn if expense would push staff into deficit.
     */
    private static function ruleExpenseVsAdvanceMismatch(array $data, \PDO $pdo): array
    {
        $staffId = (int)($data['staff_id'] ?? $data['collector_id'] ?? 0);
        $amount  = (float)($data['ssp_amount'] ?? $data['amount'] ?? 0);
        if ($staffId <= 0 || $amount <= 0) return [];

        try {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN ssp_amount ELSE 0 END), 0)
                      - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END), 0) AS balance
                 FROM staff_ledger
                 WHERE staff_id = ? AND status = 'active'"
            );
            $stmt->execute([$staffId]);
            $balance = (float)($stmt->fetchColumn() ?? 0);
            $after   = $balance - $amount;

            if ($after < -100000) { // more than SSP 100k deficit
                $staffName = $data['staff_name'] ?? $data['collector_name'] ?? "Staff #{$staffId}";
                return [[
                    'level'   => 'warn',
                    'rule'    => 'R07',
                    'msg'     => "{$staffName}'s current balance is SSP " . number_format($balance)
                               . ". This expense of SSP " . number_format($amount)
                               . " will push them to SSP " . number_format($after) . ".",
                    'affects' => 'Staff cashbook balance will be negative. '
                               . 'This means they are spending more than they have received. '
                               . 'Check if all advances from Rupesh have been recorded.',
                    'fix'     => 'Confirm the staff member has received adequate advances before approving.',
                ]];
            }
        } catch (\Throwable $e) { /* non-fatal */ }
        return [];
    }

    /**
     * R08  --  Manual cash_in without matching Rupesh cashbook OUT.
     * Warns when someone manually adds a large IN with no cb_ref.
     */
    private static function ruleMissingCashbookCounterpart(array $data, $store): array
    {
        $cbRef  = trim($data['cb_ref'] ?? '');
        $ssp    = (float)($data['ssp_amount'] ?? 0);
        $cat    = trim($data['category'] ?? '');
        $approvedBy = trim($data['approved_by'] ?? '');

        // Only warn for manual entries (no cb_ref) above threshold
        if ($cbRef !== '' || $ssp < self::LARGE_SSP_WARN) return [];
        if (in_array($cat, ['Exchange', 'SSP Received'], true)
            && str_contains(strtolower($approvedBy), 'manual')) {
            return [[
                'level'   => 'warn',
                'rule'    => 'R08',
                'msg'     => "Manual SSP IN of " . number_format($ssp) . " SSP with no matching cashbook reference (cb_ref empty).",
                'affects' => 'Staff balance increases by SSP ' . number_format($ssp)
                           . ' but there is no corresponding OUT in Rupesh\'s cashbook. '
                           . 'This was the root cause of the Mar 17 SSP 200,000 orphan entry.',
                'fix'     => 'Ensure Rupesh records the matching OUT in the main cashbook first. '
                           . 'Use the SSP Advance entry (it auto-creates this for the staff).',
            ]];
        }
        return [];
    }

    /**
     * R09  --  Void cascade warning.
     * When voiding, tell user what else will be affected.
     */
    private static function ruleVoidCascadeWarning(array $data, $store, string $type): array
    {
        $issues = [];
        if ($type === 'expense') {
            $expId  = (int)($data['id'] ?? 0);
            $ref    = trim($data['validation_ref'] ?? 'EXP-' . $expId);
            $amount = (float)($data['ssp_amount'] ?? $data['amount'] ?? 0);
            $staff  = trim($data['staff_name'] ?? $data['collector_name'] ?? '');

            $cashIns = $store->load('cash_ins.json') ?? [];
            $linked  = [];
            foreach ($cashIns as $ci) {
                if (($ci['cb_ref'] ?? '') === $ref && ($ci['status'] ?? '') !== 'voided') {
                    $linked[] = $ci;
                }
            }
            if (!empty($linked)) {
                $issues[] = [
                    'level'   => 'warn',
                    'rule'    => 'R09',
                    'msg'     => "Voiding this expense will also void " . count($linked)
                               . " linked cash_in entry(ies) in " . $staff . "'s cashbook.",
                    'affects' => $staff . "'s cashbook balance will increase by SSP "
                               . number_format($amount) . " (the expense OUT will be removed). "
                               . 'If the expense was already paid, also check the main cashbook.',
                    'fix'     => 'Cascade void is automatic. Confirm this is intentional.',
                ];
            }
        }

        if ($type === 'cashin') {
            $ciId   = (int)($data['id'] ?? 0);
            $amount = (float)($data['ssp_amount'] ?? $data['amount'] ?? 0);
            $staff  = trim($data['collector_name'] ?? '');
            $cbRef  = trim($data['cb_ref'] ?? '');
            $issues[] = [
                'level'   => 'warn',
                'rule'    => 'R09',
                'msg'     => "Voiding cash_in #{$ciId} (SSP " . number_format($amount) . " for {$staff}).",
                'affects' => $staff . "'s cashbook balance will decrease by SSP " . number_format($amount)
                           . ($cbRef ? ". The original cashbook entry {$cbRef} in Rupesh's cashbook will NOT be auto-voided  --  that must be done separately." : ''),
                'fix'     => 'If Rupesh\'s cashbook has a matching OUT, void or correct it too to keep both sides consistent.',
            ];
        }
        return $issues;
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    //  LAYER 2  --  POST-SAVE ASSERTIONS
    // ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Run after a successful save. Logs anomalies to activity_log.json.
     * NEVER throws. NEVER blocks.
     *
     * @param string $context  Same contexts as preSave()
     * @param array  $saved    The record that was just saved (must include 'sr'/'id')
     * @param mixed  $store    SqliteStore
     * @param PDO    $pdo
     * @param string $dataDir  For activity log
     */
    public static function postSave(
        string $context, array $saved, $store, \PDO $pdo, string $dataDir = ''
    ): void {
        try {
            switch ($context) {
                case 'cashbook_out':
                    self::assertCashInCreated($saved, $store, $dataDir);
                    break;
                case 'cashbook_exchange':
                    self::assertExchangeDualEntry($saved, $pdo, $dataDir);
                    break;
                case 'expense_approve':
                    self::assertLedgerExpenseOut($saved, $pdo, $dataDir);
                    break;
            }
        } catch (\Throwable $e) {
            self::logIssue($dataDir, 'post_save_assert_error', $e->getMessage());
        }
    }

    private static function assertCashInCreated(array $saved, $store, string $dataDir): void
    {
        $sr       = $saved['sr'] ?? '';
        $cat      = $saved['category'] ?? '';
        $person   = trim($saved['person'] ?? '');
        $dir      = strtolower($saved['direction'] ?? 'out');

        if ($dir !== 'out' || $sr === '' || !in_array($cat, self::CHAIN_CATS, true) || $person === '') {
            return;
        }

        // Give the write a moment then check
        $cashIns = $store->load('cash_ins.json') ?? [];
        $found   = false;
        foreach ($cashIns as $ci) {
            if (($ci['cb_ref'] ?? '') === $sr && ($ci['status'] ?? '') !== 'voided') {
                $found = true;
                break;
            }
        }

        if (!$found) {
            self::logIssue($dataDir, 'chain_break_cashin_missing',
                "POST-SAVE ASSERT FAILED: cashbook OUT {$sr} ({$cat} for {$person}) "
                . "was saved but no matching cash_in found. "
                . "Run fix script or re-save with person field populated.");
        }
    }

    private static function assertExchangeDualEntry(array $saved, \PDO $pdo, string $dataDir): void
    {
        $sr = $saved['sr'] ?? '';
        if ($sr === '') return;

        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM cb_ledger WHERE exchange_ref = ? OR sr = ? AND status != 'voided'"
            );
            $stmt->execute([$sr, $sr]);
            $count = (int)$stmt->fetchColumn();
            // Exchange should create 2 rows
            if ($count < 2) {
                self::logIssue($dataDir, 'chain_break_exchange_missing_leg',
                    "POST-SAVE ASSERT: exchange {$sr} has only {$count} leg(s) in cb_ledger. Expected 2.");
            }
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    private static function assertLedgerExpenseOut(array $saved, \PDO $pdo, string $dataDir): void
    {
        $id      = (int)($saved['id'] ?? 0);
        $iemKey  = 'FEXP-' . $id;
        if ($id <= 0) return;

        try {
            $stmt = $pdo->prepare("SELECT id FROM staff_ledger WHERE idempotency_key = ? LIMIT 1");
            $stmt->execute([$iemKey]);
            if (!$stmt->fetchColumn()) {
                self::logIssue($dataDir, 'chain_break_ledger_expense_missing',
                    "POST-SAVE ASSERT: expense #{$id} approved but staff_ledger row missing (key={$iemKey}). "
                    . "Nightly backfill should catch this, or run backfill_staff_ledger.php.");
            }
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    //  LAYER 3  --  FULL AUDIT SCAN
    // ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Run a full integrity audit across all transaction records.
     * Returns a structured report suitable for the admin tab.
     *
     * @return array {
     *   summary: {total_checks, passed, warnings, errors, last_run},
     *   checks:  [{ id, rule, severity, entity, description, impact, fix, count }]
     * }
     */
    public static function fullAudit($store, \PDO $pdo): array
    {
        $checks  = [];
        $passed  = 0;
        $warns   = 0;
        $errors  = 0;

        // ------ A1: Cashbook OUTs (chain cats) - matching cash_in ---------------------------------------------------
        try {
            $stmt = $pdo->prepare(
                "SELECT sr, date, person, category, ssp_amount, amount, currency
                 FROM cb_ledger
                 WHERE direction = 'out'
                   AND category IN ('SSP Advance','Staff Advance','Commission')
                   AND status NOT IN ('voided','rejected')
                   AND (person IS NOT NULL AND person != '')
                 ORDER BY date DESC"
            );
            $stmt->execute();
            $cbOuts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $cashIns    = $store->load('cash_ins.json') ?? [];
            $cinRefMap  = [];
            foreach ($cashIns as $ci) {
                $ref = $ci['cb_ref'] ?? '';
                if ($ref !== '') $cinRefMap[$ref] = $ci;
            }

            $broken = [];
            foreach ($cbOuts as $row) {
                $sr = $row['sr'] ?? '';
                if ($sr === '') continue;
                if (!isset($cinRefMap[$sr])) {
                    $broken[] = $row;
                } elseif (($cinRefMap[$sr]['status'] ?? '') === 'voided') {
                    // Cash-in was voided but cashbook OUT is still active  --  check if intentional
                    $broken[] = array_merge($row, ['note' => 'cash_in voided but cb_ledger OUT is active']);
                }
            }

            if (empty($broken)) {
                $checks[] = self::ok('A1', 'Cashbook OUT - cash_in chain',
                    count($cbOuts) . ' cashbook advances all have matching staff cash_in entries.');
                $passed++;
            } else {
                foreach ($broken as $b) {
                    $checks[] = self::err('A1', 'Broken advance chain: ' . ($b['sr'] ?? '?'),
                        "Cashbook OUT {$b['sr']} ({$b['category']} for {$b['person']} on {$b['date']}, "
                        . "SSP " . number_format((float)($b['ssp_amount'] ?? 0)) . ") "
                        . "has NO matching cash_in entry" . ($b['note'] ?? '') . ".",
                        'Staff cashbook shows SSP 0 received for this advance. '
                        . 'Their balance is understated by SSP ' . number_format((float)($b['ssp_amount'] ?? 0)) . '.',
                        'Run fix_missing_cashin_cb' . strtolower(str_replace(['-',' '], '_', $b['sr'] ?? '')) . '.php '
                        . 'or use the Repair button below.');
                }
                $errors += count($broken);
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('A1', 'A1 check error', $e->getMessage(), '', '');
            $warns++;
        }

        // ------ A2: cash_ins.json - staff_ledger rows ------------------------------------------------------------------------------------
        try {
            $cashIns = $store->load('cash_ins.json') ?? [];
            $missing = [];
            foreach ($cashIns as $ci) {
                $ciId = (int)($ci['id'] ?? 0);
                if ($ciId <= 0 || ($ci['status'] ?? '') === 'voided') continue;

                $stmt = $pdo->prepare(
                    "SELECT id FROM staff_ledger WHERE idempotency_key = ? LIMIT 1"
                );
                $stmt->execute(['CIN-' . $ciId]);
                if (!$stmt->fetchColumn()) {
                    $missing[] = $ci;
                }
            }
            if (empty($missing)) {
                $checks[] = self::ok('A2', 'cash_ins - staff_ledger sync',
                    'All cash_in entries have matching staff_ledger rows.');
                $passed++;
            } else {
                $checks[] = self::warn('A2',
                    count($missing) . ' cash_in entries missing from staff_ledger',
                    'cash_in IDs: ' . implode(', ', array_column($missing, 'id')),
                    'Staff_ledger balance will differ from cashbook balance. '
                    . 'DualReadCashPosition may report a mismatch.',
                    'Run backfill_staff_ledger.php to resync.');
                $warns++;
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('A2', 'A2 check error', $e->getMessage(), '', '');
            $warns++;
        }

        // ------ A3: Approved expenses - staff_ledger OUT rows ------------------------------------------------------------
        try {
            $expenses = $store->load('cash_expenses.json') ?? [];
            $missing  = [];
            foreach ($expenses as $exp) {
                if (($exp['status'] ?? '') !== 'approved') continue;
                $eid = (int)($exp['id'] ?? 0);
                if ($eid <= 0) continue;
                $stmt = $pdo->prepare(
                    "SELECT id FROM staff_ledger WHERE idempotency_key IN (?, ?) LIMIT 1"
                );
                $stmt->execute(['FEXP-' . $eid, 'EXP-' . $eid]);
                if (!$stmt->fetchColumn()) {
                    $missing[] = $exp;
                }
            }
            if (empty($missing)) {
                $checks[] = self::ok('A3', 'Approved expenses - staff_ledger',
                    'All approved expenses have staff_ledger OUT rows.');
                $passed++;
            } else {
                $checks[] = self::warn('A3',
                    count($missing) . ' approved expenses missing from staff_ledger',
                    'Expense IDs: ' . implode(', ', array_column($missing, 'id')),
                    'Staff_ledger balance is over-stated (missing deductions).',
                    'Run backfill_staff_ledger.php.');
                $warns++;
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('A3', 'A3 check error', $e->getMessage(), '', '');
            $warns++;
        }

        // ------ A4: Voided expenses - cascade void of cash_in ------------------------------------------------------------
        try {
            $expenses = $store->load('cash_expenses.json') ?? [];
            $cashIns  = $store->load('cash_ins.json') ?? [];
            $cinMap   = [];
            foreach ($cashIns as $ci) {
                $ref = $ci['cb_ref'] ?? $ci['validation_ref'] ?? '';
                if ($ref !== '') $cinMap[$ref] = $ci;
            }

            $orphans = [];
            foreach ($expenses as $exp) {
                if (($exp['status'] ?? '') !== 'voided') continue;
                $ref = $exp['validation_ref'] ?? '';
                if ($ref === '') continue;
                if (isset($cinMap[$ref]) && ($cinMap[$ref]['status'] ?? '') !== 'voided') {
                    $orphans[] = ['exp' => $exp, 'ci' => $cinMap[$ref]];
                }
            }
            if (empty($orphans)) {
                $checks[] = self::ok('A4', 'Void cascade completeness',
                    'All voided expenses have matching voided cash_in entries.');
                $passed++;
            } else {
                foreach ($orphans as $o) {
                    $checks[] = self::err('A4',
                        'Orphan cash_in after expense void',
                        "Expense #{$o['exp']['id']} ({$o['exp']['category']}) is voided but "
                        . "cash_in #{$o['ci']['id']} for {$o['ci']['collector_name']} is still ACTIVE.",
                        "Staff balance is inflated by SSP " . number_format((float)($o['ci']['ssp_amount'] ?? 0))
                        . "  --  the expense was cancelled but the staff member still shows the money received.",
                        "Void cash_in #{$o['ci']['id']} in Staff Cashbooks - {$o['ci']['collector_name']}.");
                    $errors++;
                }
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('A4', 'A4 check error', $e->getMessage(), '', '');
            $warns++;
        }

        // ------ A5: Exchange rate sanity scan ---------------------------------------------------------------------------------------------------------------
        try {
            $stmt = $pdo->prepare(
                "SELECT sr, date, person, ssp_rate, amount, ssp_amount
                 FROM cb_ledger
                 WHERE category = 'Exchange'
                   AND status NOT IN ('voided','rejected')
                   AND ssp_rate IS NOT NULL AND ssp_rate > 0
                 ORDER BY date DESC LIMIT 200"
            );
            $stmt->execute();
            $exchanges = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $rateIssues = [];
            foreach ($exchanges as $ex) {
                $rate = (float)($ex['ssp_rate'] ?? 0);
                if ($rate < self::SSP_RATE_MIN || $rate > self::SSP_RATE_MAX) {
                    $rateIssues[] = $ex;
                }
            }
            if (empty($rateIssues)) {
                $checks[] = self::ok('A5', 'Exchange rate sanity',
                    count($exchanges) . ' exchange entries  --  all rates within normal range (' 
                    . number_format(self::SSP_RATE_MIN) . '--' . number_format(self::SSP_RATE_MAX) . ' SSP/USD).');
                $passed++;
            } else {
                foreach ($rateIssues as $ex) {
                    $ssp  = round((float)$ex['amount'] * (float)$ex['ssp_rate'], 0);
                    $checks[] = self::err('A5',
                        'Abnormal exchange rate: SR ' . $ex['sr'],
                        "SR {$ex['sr']} on {$ex['date']} has rate " . number_format((float)$ex['ssp_rate'], 0)
                        . " SSP/USD (outside " . number_format(self::SSP_RATE_MIN) . "--"
                        . number_format(self::SSP_RATE_MAX) . " range).",
                        "SSP amounts computed from this rate will be wildly wrong. "
                        . "This is exactly the bug that created the SSP 5,800,000 ghost entry.",
                        "Correct the ssp_rate on SR {$ex['sr']} in the cashbook CRUD edit.");
                    $errors++;
                }
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('A5', 'A5 check error', $e->getMessage(), '', '');
            $warns++;
        }

        // ------ A6: staff_ledger balance vs DualRead balance ------------------------------------------------------------------
        try {
            $stmt = $pdo->prepare(
                "SELECT staff_id, staff_name,
                    SUM(CASE WHEN direction='in'  THEN ssp_amount ELSE 0 END) -
                    SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END) AS ledger_balance
                 FROM staff_ledger
                 WHERE status = 'active' AND ssp_amount > 0
                 GROUP BY staff_id, staff_name"
            );
            $stmt->execute();
            $ledgerBalances = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $bigDiffs = [];
            foreach ($ledgerBalances as $row) {
                $bal = (float)($row['ledger_balance'] ?? 0);
                // Simple sanity: if balance < -200,000 SSP it's a likely data issue
                if ($bal < -200000) {
                    $bigDiffs[] = $row;
                }
            }
            if (empty($bigDiffs)) {
                $checks[] = self::ok('A6', 'Staff ledger balance sanity',
                    'All staff SSP ledger balances are within acceptable range.');
                $passed++;
            } else {
                foreach ($bigDiffs as $s) {
                    $checks[] = self::warn('A6',
                        "{$s['staff_name']} ledger balance is SSP " . number_format((float)$s['ledger_balance']),
                        "Staff ID {$s['staff_id']} ({$s['staff_name']}) has a ledger balance of SSP "
                        . number_format((float)$s['ledger_balance']) . ".",
                        "They are spending more than they received. Either advances are missing from their cashbook, "
                        . "or expenses are being double-counted.",
                        "Check Staff Cashbooks - {$s['staff_name']} - SSP tab for missing IN entries. "
                        . "Also verify the Ledger Health reconcile tab.");
                    $warns++;
                }
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('A6', 'A6 check error', $e->getMessage(), '', '');
            $warns++;
        }

        // ------ A7: Duplicate active expenses (same staff, day, amount, category) ---
        try {
            $expenses = $store->load('cash_expenses.json') ?? [];
            $seen     = [];
            $dupes    = [];
            foreach ($expenses as $exp) {
                if (($exp['status'] ?? '') !== 'approved') continue;
                $key = strtolower($exp['collector_name'] ?? '') . '|'
                     . ($exp['category'] ?? '') . '|'
                     . number_format((float)($exp['ssp_amount'] ?? 0), 0) . '|'
                     . substr($exp['created_at'] ?? '', 0, 10);
                if (isset($seen[$key])) {
                    $dupes[$key][] = $exp['id'];
                } else {
                    $seen[$key] = $exp['id'];
                }
            }
            if (empty($dupes)) {
                $checks[] = self::ok('A7', 'Duplicate approved expense check',
                    'No duplicate approved expenses found.');
                $passed++;
            } else {
                foreach ($dupes as $key => $ids) {
                    $parts = explode('|', $key);
                    $checks[] = self::warn('A7',
                        'Possible duplicate expenses for ' . $parts[0],
                        "Same category ({$parts[1]}), amount (SSP {$parts[2]}), date ({$parts[3]}), "
                        . "staff ({$parts[0]}) appears " . (count($ids) + 1) . " times. IDs: "
                        . implode(', ', array_merge([$seen[$key]], $ids)) . ".",
                        "Cashbook balance for this staff is reduced by SSP {$parts[2]} extra for each duplicate.",
                        'Void the duplicate entry(ies) keeping only one.');
                    $warns++;
                }
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('A7', 'A7 check error', $e->getMessage(), '', '');
            $warns++;
        }

        return [
            'summary' => [
                'total_checks' => $passed + $warns + $errors,
                'passed'       => $passed,
                'warnings'     => $warns,
                'errors'       => $errors,
                'last_run'     => date('Y-m-d H:i:s'),
            ],
            'checks' => $checks,
        ];
    }

    // --------- Helpers ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

    private static function ok(string $id, string $title, string $msg): array
    {
        return ['id'=>$id,'severity'=>'ok','title'=>$title,'description'=>$msg,'impact'=>'','fix'=>''];
    }
    private static function warn(string $id, string $title, string $desc, string $impact, string $fix): array
    {
        return ['id'=>$id,'severity'=>'warn','title'=>$title,'description'=>$desc,'impact'=>$impact,'fix'=>$fix];
    }
    private static function err(string $id, string $title, string $desc, string $impact, string $fix): array
    {
        return ['id'=>$id,'severity'=>'error','title'=>$title,'description'=>$desc,'impact'=>$impact,'fix'=>$fix];
    }

    private static function logIssue(string $dataDir, string $type, string $message): void
    {
        if ($dataDir === '') return;
        $logFile = $dataDir . '/activity_log.json';
        try {
            $log   = [];
            if (file_exists($logFile)) {
                $raw = file_get_contents($logFile);
                $log = json_decode($raw ?: '[]', true) ?: [];
            }
            $log[] = ['time'=>date('Y-m-d H:i:s'),'type'=>$type,'message'=>$message,'actor'=>'TIG'];
            file_put_contents($logFile, json_encode(array_slice($log, -500), JSON_PRETTY_PRINT));
        } catch (\Throwable $e) { /* swallow */ }
    }
}
