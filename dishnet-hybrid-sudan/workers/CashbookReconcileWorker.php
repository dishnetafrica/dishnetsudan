<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * CashbookReconcileWorker
 * DishNet Hybrid v4.5
 *
 * Nightly worker (called from cron_sync.php) that:
 *
 *   1. Computes staff_cashbook_daily snapshots for all active staff
 *      for the last N days (default: 7 to catch late submissions)
 *   2. Verifies each approved expense has a corresponding cb_ledger row
 *      (re-posts any that slipped through due to errors)
 *   3. Flags mismatches between staff cashbook totals and main cashbook
 *   4. Marks advances as 'settled' if balance reaches ≤ 0
 *   5. Generates a summary log entry for Rupesh's morning review
 *
 * ── Schedule ─────────────────────────────────────────────────────────────
 *   Called from cron_sync.php at 23:00 Juba time (UTC+3).
 *   Lock file: data/reconcile.lock (prevents concurrent runs).
 *
 * ── Returns ──────────────────────────────────────────────────────────────
 *   array [
 *     'staff_days_computed'   => int,
 *     'advances_auto_settled' => int,
 *     'expense_postings_fixed'=> int,
 *     'flags_raised'          => int,
 *     'duration_ms'           => int,
 *     'errors'                => array,
 *   ]
 */
class CashbookReconcileWorker
{
    private \PDO    $pdo;
    private         $store;
    private         $expSvc;    // ExpenseAdvanceService
    private         $cb;        // CashbookService
    private string  $dataDir;
    private string  $logFile;
    private array   $errors = [];

    public function __construct($store, string $dataDir)
    {
        require_once __DIR__ . '/../lib/ExpenseAdvanceService.php';
        require_once __DIR__ . '/../lib/CashbookService.php';

        $this->store   = $store;
        $this->dataDir = rtrim($dataDir, '/');
        $this->pdo     = $store->getPdo();
        $this->expSvc  = new \ExpenseAdvanceService($store, $dataDir);
        $this->cb      = new \CashbookService($store, $dataDir);
        $this->logFile = $dataDir . '/reconcile.log';
    }

    // ══════════════════════════════════════════════════════════════════════
    // MAIN ENTRY POINT
    // ══════════════════════════════════════════════════════════════════════

    public function run(int $lookbackDays = 7): array
    {
        $startMs = (int)(microtime(true) * 1000);
        $this->log("=== CashbookReconcileWorker START " . date('Y-m-d H:i:s') . " ===");

        $staffDays      = 0;
        $autoSettled    = 0;
        $fixed          = 0;
        $flagsRaised    = 0;

        // ── Step 1: Find all staff who have had advances or expenses in the period ──
        $cutoff = date('Y-m-d', strtotime("-{$lookbackDays} days"));

        $staffRows = $this->pdo->prepare("
            SELECT DISTINCT recipient_id AS staff_id, recipient_name AS staff_name
            FROM cash_advances
            WHERE issued_at >= ? AND status != 'cancelled'
            UNION
            SELECT DISTINCT staff_id, staff_name
            FROM staff_expenses
            WHERE expense_date >= ?
        ");
        $staffRows->execute([$cutoff, $cutoff]);
        $staffList = $staffRows->fetchAll(\PDO::FETCH_ASSOC);

        $this->log("Found " . count($staffList) . " active staff to reconcile.");

        // ── Step 2: Compute daily cashbooks for each staff x each day ──────────
        foreach ($staffList as $staff) {
            $staffId   = (int)$staff['staff_id'];
            $staffName = $staff['staff_name'];

            for ($d = 0; $d <= $lookbackDays; $d++) {
                $date = date('Y-m-d', strtotime("-{$d} days"));
                try {
                    $row = $this->computeAndStore($staffId, $staffName, $date);
                    $staffDays++;
                    if ($row['flag_count'] > 0) {
                        $flagsRaised += $row['flag_count'];
                    }
                } catch (\Throwable $e) {
                    $this->errors[] = "Staff #{$staffId} date {$date}: " . $e->getMessage();
                    $this->log("ERROR: " . end($this->errors));
                }
            }
        }

        // ── Step 3: Verify expense → cb_ledger integrity ───────────────────────
        // v4.20.3: Skip imprest-suppressed expenses. These are SSP expenses that
        // are linked to a cash advance — by design (SAFETY Rule 16) they don't post
        // to cb_ledger because the advance issue already did. Sentinel:
        // cashbook_entry_id = -1. Without this filter the worker would re-create
        // the duplicate cb_ledger row and undo the v4.20.3 fix.
        $orphaned = $this->pdo->query("
            SELECT id, expense_no, staff_name, amount, currency, expense_date, category, project, description, advance_id
            FROM staff_expenses
            WHERE status='approved'
              AND cashbook_entry_id = 0
              AND NOT (currency='SSP' AND advance_id IS NOT NULL AND advance_id > 0)
              AND submitted_at >= '" . date('Y-m-d', strtotime('-30 days')) . "'
        ")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($orphaned as $exp) {
            try {
                $cbCat  = \ExpenseAdvanceService::CATEGORY_CB_MAP[$exp['category']] ?? 'Misc Expense';
                $cbDesc = "Field expense {$exp['expense_no']} — {$exp['category']}" .
                          ($exp['description'] ? ': ' . $exp['description'] : '') .
                          " — " . $exp['staff_name'] . " [reconcile-fix]";

                $this->cb->addEntryRaw([
                    'project'           => $exp['project'],
                    'date'              => $exp['expense_date'],
                    'direction'         => 'out',
                    'amount'            => (float)$exp['amount'],
                    'currency'          => $exp['currency'],
                    'ssp_amount'        => strtoupper($exp['currency'] ?? '') === 'SSP' ? (float)$exp['amount'] : 0,
                    'category'          => $cbCat,
                    'category_raw'      => ucfirst($exp['category']),
                    'person'            => $exp['staff_name'],
                    'description'       => $cbDesc,
                    'validation_ref'    => $exp['expense_no'] . '-FIX',
                    'validation_status' => 'pending',
                    'status'            => 'approved',
                    'approved_by'       => 'Reconcile Worker',
                    'source'            => 'expense_sync',
                    'created_at'        => date('Y-m-d H:i:s'),
                ]);

                $cbId = (int)$this->pdo->query("SELECT MAX(id) FROM cb_ledger WHERE source='expense_sync'")->fetchColumn();

                $this->pdo->prepare(
                    "UPDATE staff_expenses SET cashbook_entry_id=?, cashbook_posted_at=? WHERE id=?"
                )->execute([$cbId, date('Y-m-d H:i:s'), (int)$exp['id']]);

                $fixed++;
                $this->log("Fixed orphaned expense {$exp['expense_no']} → cb_ledger #{$cbId}");
            } catch (\Throwable $e) {
                $this->errors[] = "Fix expense #{$exp['id']}: " . $e->getMessage();
            }
        }

        // ── Step 4: Auto-settle advances where balance ≤ 0.01 ────────────────
        // Must check children are settled first; use correct balance formula.
        $nearZero = $this->pdo->query("
            SELECT id, advance_no, recipient_name
            FROM cash_advances
            WHERE status IN ('active','partial')
              AND (amount - amount_spent - amount_returned - COALESCE(children_allocated,0)) <= 0.01
        ")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($nearZero as $adv) {
            try {
                // Skip if unsettled children exist
                $childCheck = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM cash_advances WHERE parent_advance_id=? AND status IN ('active','partial')"
                );
                $childCheck->execute([(int)$adv['id']]);
                if ((int)$childCheck->fetchColumn() > 0) {
                    $this->log("Skipping auto-settle {$adv['advance_no']} — has unsettled children.");
                    continue;
                }

                $this->pdo->prepare(
                    "UPDATE cash_advances SET status='settled', settled_at=?, settlement_note=?, updated_at=? WHERE id=?"
                )->execute([date('Y-m-d H:i:s'), 'Auto-settled by reconcile worker', date('Y-m-d H:i:s'), (int)$adv['id']]);
                $autoSettled++;
                $this->log("Auto-settled advance {$adv['advance_no']} for {$adv['recipient_name']}");
            } catch (\Throwable $e) {
                $this->errors[] = "Auto-settle advance #{$adv['id']}: " . $e->getMessage();
            }
        }

        // ── Step 5: Advance Aging Check ─────────────────────────────────────
        // Purpose-based time limits — flag overdue advances and block new advances.
        // Limits: fuel=24h, transport=24h, parts=72h, equipment=72h, allowance=48h, misc=48h
        $purposeLimitsHours = [
            'fuel'      => 24,
            'transport' => 24,
            'food'      => 24,
            'parts'     => 72,
            'misc'      => 48,
            'allowance' => 48,
        ];
        $overdueCount = 0;

        try {
            $openAdvances = $this->pdo->query("
                SELECT id, advance_no, recipient_id, recipient_name, purpose,
                       issued_at, expected_settle_at,
                       (amount - amount_spent - amount_returned - COALESCE(children_allocated,0)) AS balance,
                       overdue_notified
                FROM cash_advances
                WHERE status IN ('active','partial')
                  AND parent_advance_id IS NULL
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $nowTs = time();

            foreach ($openAdvances as $adv) {
                $limitHours = $purposeLimitsHours[$adv['purpose'] ?? 'misc'] ?? 48;
                $issuedTs   = strtotime($adv['issued_at'] ?? '');
                if (!$issuedTs) continue;

                $ageHours = ($nowTs - $issuedTs) / 3600;
                $isOverdue = $ageHours > $limitHours;

                // Also check against expected_settle_at if set
                if (!$isOverdue && !empty($adv['expected_settle_at'])) {
                    $isOverdue = strtotime($adv['expected_settle_at']) < $nowTs;
                }

                $this->pdo->prepare(
                    "UPDATE cash_advances SET is_overdue=?, overdue_since=CASE WHEN is_overdue=0 AND ? THEN ? ELSE overdue_since END, updated_at=? WHERE id=?"
                )->execute([
                    $isOverdue ? 1 : 0,
                    $isOverdue ? 1 : 0,
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    (int)$adv['id'],
                ]);

                if ($isOverdue && !(int)$adv['overdue_notified']) {
                    $overdueCount++;
                    $this->log("OVERDUE: {$adv['advance_no']} — {$adv['recipient_name']} — {$adv['purpose']} — age " . round($ageHours, 1) . "h (limit {$limitHours}h) — balance \${$adv['balance']}");
                    // Mark notified so WA only fires once
                    $this->pdo->prepare("UPDATE cash_advances SET overdue_notified=1 WHERE id=?")->execute([(int)$adv['id']]);
                }
            }
            if ($overdueCount > 0) $this->log("{$overdueCount} overdue advance(s) flagged.");
        } catch (\Throwable $e) {
            $this->errors[] = "Aging check: " . $e->getMessage();
        }

        // ── Step 6: Cash Carry Limit Check ──────────────────────────────────
        // If any staff member holds more than $carryLimit, insert an alert.
        // Formula: advances_received - expenses_approved - cash_returned (across ALL time)
        $carryLimit = 100.0; // USD — can be made configurable via kyc_config.json
        $carryAlerts = 0;

        try {
            $carryRows = $this->pdo->query("
                SELECT
                    ca.recipient_id   AS staff_id,
                    ca.recipient_name AS staff_name,
                    COALESCE(SUM(CASE WHEN ca.parent_advance_id IS NULL THEN ca.amount ELSE 0 END), 0) AS root_advances,
                    COALESCE(SUM(ca.amount_spent), 0)    AS total_spent,
                    COALESCE(SUM(ca.amount_returned), 0) AS total_returned
                FROM cash_advances ca
                WHERE ca.status IN ('active','partial')
                  AND ca.parent_advance_id IS NULL
                GROUP BY ca.recipient_id, ca.recipient_name
            ")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($carryRows as $row) {
                $cashInHand = round(
                    (float)$row['root_advances'] - (float)$row['total_spent'] - (float)$row['total_returned'],
                    2
                );
                if ($cashInHand > $carryLimit) {
                    // Check if unresolved alert already exists for this staff
                    $existing = $this->pdo->prepare(
                        "SELECT id FROM staff_carry_alerts WHERE staff_id=? AND resolved=0 LIMIT 1"
                    );
                    $existing->execute([(int)$row['staff_id']]);
                    if (!$existing->fetchColumn()) {
                        $this->pdo->prepare("
                            INSERT INTO staff_carry_alerts (staff_id, staff_name, cash_in_hand, carry_limit, flagged_at)
                            VALUES (?,?,?,?,?)
                        ")->execute([
                            (int)$row['staff_id'], $row['staff_name'],
                            $cashInHand, $carryLimit,
                            date('Y-m-d H:i:s'),
                        ]);
                        $carryAlerts++;
                        $this->log("CARRY ALERT: {$row['staff_name']} holds \${$cashInHand} > limit \${$carryLimit}");
                    }
                } else {
                    // Resolve any open alert
                    $this->pdo->prepare(
                        "UPDATE staff_carry_alerts SET resolved=1, resolved_at=? WHERE staff_id=? AND resolved=0"
                    )->execute([date('Y-m-d H:i:s'), (int)$row['staff_id']]);
                }
            }
        } catch (\Throwable $e) {
            $this->errors[] = "Carry limit check: " . $e->getMessage();
        }

        // ── Step 7: Global Ledger Reconciliation Snapshot ───────────────────
        // Verify: SUM(cb_ledger.in) - SUM(cb_ledger.out) agrees with
        //         SUM(root_advances.out) - SUM(expenses.out) - SUM(returns.in)
        $driftOk = true;
        $driftAmount = 0.0;

        try {
            foreach (['dishnet', '4g'] as $proj) {
                $cbRow = $this->pdo->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN direction='in'  THEN amount ELSE 0 END), 0) AS cb_in,
                        COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END), 0) AS cb_out
                    FROM cb_ledger WHERE project=? AND status='approved' AND NOT(amount=0 AND sr='')
                ");
                $cbRow->execute([$proj]);
                $cb = $cbRow->fetch(\PDO::FETCH_ASSOC);
                $cbNet = round((float)$cb['cb_in'] - (float)$cb['cb_out'], 2);

                $advRow = $this->pdo->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN parent_advance_id IS NULL THEN amount ELSE 0 END), 0) AS advances_out,
                        COALESCE(SUM(amount_spent), 0)    AS expenses_out,
                        COALESCE(SUM(amount_returned), 0) AS returns_in
                    FROM cash_advances WHERE project=? AND status != 'cancelled'
                ");
                $advRow->execute([$proj]);
                $adv = $advRow->fetch(\PDO::FETCH_ASSOC);

                $outstanding = round((float)$adv['advances_out'] - (float)$adv['expenses_out'] - (float)$adv['returns_in'], 2);
                // cb_net should equal (advances_out - expenses_out - returns_in) * -1
                // because advances go OUT of cb, expenses go OUT of cb, returns come IN
                // simpler: just log it for the accountant to review
                $drift = round($cbNet + $outstanding, 2); // should be ~0 in a clean system
                $thisDriftOk = abs($drift) < 1.0; // allow $1 rounding tolerance

                if (!$thisDriftOk) {
                    $driftOk = false;
                    $driftAmount = max(abs($driftAmount), abs($drift));
                    $this->log("DRIFT DETECTED [{$proj}]: cb_net=\${$cbNet} outstanding=\${$outstanding} drift=\${$drift}");
                }

                $this->pdo->prepare("
                    INSERT INTO ledger_reconcile_log
                      (run_at, project, cb_in, cb_out, cb_net, advances_out, expenses_out, returns_in, outstanding, drift, drift_ok)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    date('Y-m-d H:i:s'), $proj,
                    (float)$cb['cb_in'], (float)$cb['cb_out'], $cbNet,
                    (float)$adv['advances_out'], (float)$adv['expenses_out'], (float)$adv['returns_in'],
                    $outstanding, $drift, $thisDriftOk ? 1 : 0,
                ]);
            }
        } catch (\Throwable $e) {
            $this->errors[] = "Global reconciliation: " . $e->getMessage();
        }

        // ── Step 8: Generate summary ─────────────────────────────────────────
        $durationMs = (int)(microtime(true) * 1000) - $startMs;
        $summary = [
            'staff_days_computed'    => $staffDays,
            'advances_auto_settled'  => $autoSettled,
            'expense_postings_fixed' => $fixed,
            'flags_raised'           => $flagsRaised,
            'overdue_advances'       => $overdueCount,
            'carry_alerts'           => $carryAlerts,
            'ledger_drift_ok'        => $driftOk,
            'ledger_drift_amount'    => $driftAmount,
            'duration_ms'            => $durationMs,
            'errors'                 => $this->errors,
        ];

        $this->log("Summary: " . json_encode($summary));
        $this->log("=== CashbookReconcileWorker END ({$durationMs}ms) ===\n");

        // Persist run metadata for admin tab display
        try {
            $meta = $this->store->load('cashbook_meta_v2.json');
            $meta['reconcile_last_run']    = date('Y-m-d H:i:s');
            $meta['reconcile_last_summary']= $summary;
            $this->store->save('cashbook_meta_v2.json', $meta);
        } catch (\Throwable $e) { /* non-blocking */ }

        return $summary;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Compute and store the daily cashbook for one staff member on one date.
     * Returns the row as stored (with flag_count).
     */
    private function computeAndStore(int $staffId, string $staffName, string $date): array
    {
        // Opening balance = previous day's closing balance
        $prev = $this->pdo->prepare(
            "SELECT closing_balance FROM staff_cashbook_daily WHERE staff_id=? AND date<? ORDER BY date DESC LIMIT 1"
        );
        $prev->execute([$staffId, $date]);
        $opening = round((float)($prev->fetchColumn() ?: 0.0), 2);

        // Advances received this day (root or child — staff personally holds this cash)
        $advStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM cash_advances
             WHERE recipient_id=? AND DATE(issued_at)=? AND status!='cancelled'"
        );
        $advStmt->execute([$staffId, $date]);
        $advances = round((float)$advStmt->fetchColumn(), 2);

        // Advances allocated OUT to others (child advances this staff issued from their balance)
        $allocStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM cash_advances
             WHERE issued_by_id=? AND DATE(issued_at)=?
               AND parent_advance_id IS NOT NULL AND status!='cancelled'"
        );
        $allocStmt->execute([$staffId, $date]);
        $allocated = round((float)$allocStmt->fetchColumn(), 2);

        // Approved expenses this day
        $expStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM staff_expenses
             WHERE staff_id=? AND expense_date=? AND status='approved'"
        );
        $expStmt->execute([$staffId, $date]);
        $expenses = round((float)$expStmt->fetchColumn(), 2);

        // Cash returned this day
        $retStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount_returned),0) FROM cash_advances
             WHERE recipient_id=? AND DATE(settled_at)=?"
        );
        $retStmt->execute([$staffId, $date]);
        $returned = round((float)$retStmt->fetchColumn(), 2);

        // Correct personal balance: opening + received - given_to_others - spent - returned
        $closing = round($opening + $advances - $allocated - $expenses - $returned, 2);

        // Flags
        $noRcptStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM staff_expenses
             WHERE staff_id=? AND expense_date=? AND status='approved' AND receipt_hash=''"
        );
        $noRcptStmt->execute([$staffId, $date]);
        $flagMissing  = (int)$noRcptStmt->fetchColumn();
        $flagOverspend = $closing < -0.01 ? 1 : 0;

        $lastRec = $this->pdo->prepare(
            "SELECT MAX(date) FROM staff_cashbook_daily WHERE staff_id=? AND is_reconciled=1"
        );
        $lastRec->execute([$staffId]);
        $lastRecDate    = $lastRec->fetchColumn() ?: '';
        $daysSinceRec   = $lastRecDate ? (int)round((strtotime($date) - strtotime($lastRecDate)) / 86400) : 999;
        $flagUnrec      = ($daysSinceRec > 2 && ($advances > 0 || $expenses > 0)) ? 1 : 0;

        $flagCount = (int)($flagMissing > 0) + $flagOverspend + $flagUnrec;

        // cb entry IDs
        $cbStmt = $this->pdo->prepare(
            "SELECT cashbook_entry_id FROM staff_expenses
             WHERE staff_id=? AND expense_date=? AND status='approved' AND cashbook_entry_id>0"
        );
        $cbStmt->execute([$staffId, $date]);
        $cbIds = json_encode(array_column($cbStmt->fetchAll(\PDO::FETCH_ASSOC), 'cashbook_entry_id'));

        $now = date('Y-m-d H:i:s');

        // Preserve accountant reconciliation flags
        $existingRec = $this->pdo->prepare(
            "SELECT is_reconciled, reconciled_by, reconciled_at FROM staff_cashbook_daily WHERE staff_id=? AND date=?"
        );
        $existingRec->execute([$staffId, $date]);
        $existing = $existingRec->fetch(\PDO::FETCH_ASSOC);

        $isRec = (int)($existing['is_reconciled'] ?? 0);
        $recBy = $existing['reconciled_by'] ?? '';
        $recAt = $existing['reconciled_at'] ?? null;

        $this->pdo->prepare("
            INSERT INTO staff_cashbook_daily
              (staff_id,staff_name,date,opening_balance,
               advances_received,advances_allocated,
               expenses_approved,cash_returned,closing_balance,
               is_reconciled,reconciled_by,reconciled_at,
               flag_missing_receipts,flag_overspend,flag_unreconciled,flag_count,
               cb_entry_ids,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT(staff_id,date) DO UPDATE SET
              opening_balance=excluded.opening_balance,
              advances_received=excluded.advances_received,
              advances_allocated=excluded.advances_allocated,
              expenses_approved=excluded.expenses_approved,
              cash_returned=excluded.cash_returned,
              closing_balance=excluded.closing_balance,
              flag_missing_receipts=excluded.flag_missing_receipts,
              flag_overspend=excluded.flag_overspend,
              flag_unreconciled=excluded.flag_unreconciled,
              flag_count=excluded.flag_count,
              cb_entry_ids=excluded.cb_entry_ids,
              updated_at=excluded.updated_at
        ")->execute([
            $staffId, $staffName, $date,
            $opening, $advances, $allocated, $expenses, $returned, $closing,
            $isRec, $recBy, $recAt,
            $flagMissing, $flagOverspend, $flagUnrec, $flagCount,
            $cbIds, $now,
        ]);

        return [
            'staff_id'   => $staffId,
            'date'       => $date,
            'closing'    => $closing,
            'flag_count' => $flagCount,
        ];
    }

    private function log(string $msg): void
    {
        @file_put_contents($this->logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
