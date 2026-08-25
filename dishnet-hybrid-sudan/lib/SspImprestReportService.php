<?php
declare(strict_types=1);

// PHP 7.4 polyfills (Rule 5)
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * SspImprestReportService — read-only reports for the v4.20.3 imprest model.
 * DishNet Hybrid v4.20.4
 *
 * THREE REPORTS, ONE SERVICE
 * ──────────────────────────
 *
 *   1. companyTotals($asOf)
 *      Top-of-page KPI block. Answers: how much SSP does the company hold
 *      right now, split between main till and staff imprest? Plus today's
 *      flow-through.
 *
 *   2. imprestHolders()
 *      Per-staff balance table. Shows current cash held, days since last
 *      activity, freshness flag. The accountant's daily safety net.
 *
 *   3. pAndLByCategory($from, $to)
 *      Period P&L. Sums SSP spent per category, blending two sources:
 *      (a) staff_expenses approved (covers imprest-funded spending — the
 *          v4.20.3 fix moved this OUT of cb_ledger by design)
 *      (b) cb_ledger direct payments (covers vendor payments etc. that
 *          never touched a staff imprest)
 *      Together they give the full company SSP P&L for the period.
 *
 * RULE 8 reminder: cb_ledger.amount is USD. SSP figures live in
 * cb_ledger.ssp_amount. Same for staff_ledger.
 *
 * READ-ONLY — this service NEVER writes. No money paths touched.
 */
class SspImprestReportService
{
    /** @var \PDO */
    private $pdo;

    /** @var object */
    private $store;

    /** @var string */
    private $dataDir;

    /** Map of staff_expenses.category → friendly label for P&L display */
    private static $expenseCategoryLabels = [
        'fuel'      => 'Fuel & Transport',
        'parts'     => 'Parts & Local Purchase',
        'transport' => 'Transport Allowance',
        'allowance' => 'Allowances & Benefits',
        'food'      => 'Food Allowance',
        'other'     => 'Misc Expenses',
    ];

    /** cb_ledger category → P&L label (so direct payments merge with imprest categories cleanly) */
    private static $cbCategoryLabels = [
        'Travel & Field'      => 'Fuel & Transport',
        'Local Purchase'      => 'Parts & Local Purchase',
        'Transport Allowance' => 'Transport Allowance',
        'Misc Expense'        => 'Misc Expenses',
        'Office Expense'      => 'Misc Expenses',
        'Maintenance'         => 'Maintenance',
        'Utilities'           => 'Utilities',
        'Customer Refund'     => 'Customer Refunds',
        'Customer Commission' => 'Customer Commissions',
        'Vendor Payment'      => 'Vendor Payments',
        'Bank Charge'         => 'Bank Charges',
    ];

    /** cb_ledger categories that count as direct (non-imprest) SSP expenses */
    private static $directExpenseCbCategories = [
        'Travel & Field',
        'Local Purchase',
        'Misc Expense',
        'Office Expense',
        'Maintenance',
        'Utilities',
        'Customer Refund',
        'Customer Commission',
        'Vendor Payment',
        'Bank Charge',
    ];

    /** cb_ledger categories that are imprest-issuance (NOT P&L expense — balance-sheet only) */
    private static $imprestIssueCategories = [
        'SSP Advance',
        'Staff Advance',
    ];

    /** cb_ledger categories that are personal pay (separate P&L bucket) */
    private static $personalPayCategories = [
        'Salary',
        'Transport Allowance',
        'Food Allowance',
        'Bonus',
        'Employee Benefit',
        'Commission',
    ];

    public function __construct($store, string $dataDir)
    {
        $this->pdo     = $store->getPdo();
        $this->store   = $store;
        $this->dataDir = $dataDir;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  REPORT 1 — companyTotals
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Company-wide SSP position snapshot.
     *
     * @param string $asOfDate YYYY-MM-DD; defaults to today
     * @return array {
     *   main_till_balance:  cb_ledger SSP IN-OUT, all-time (physical cash in main till)
     *   in_imprest:         sum of all staff SSP imprest balances (cash held by field)
     *   total_company_ssp:  main_till + in_imprest (= total SSP the company controls)
     *   today: {
     *     advances_issued:   SSP given to staff today (cb_ledger OUT, SSP Advance)
     *     direct_expenses:   non-imprest expenses paid today (cb_ledger OUT, expense cats, source!=manual not in imprest cats)
     *     imprest_expenses:  staff_expenses approved today, imprest-suppressed
     *     returns_received:  SSP returned to office today (cb_ledger IN, SSP Return)
     *   }
     *   imprest_holder_count: how many staff currently hold non-zero SSP imprest
     *   stale_holder_count:   how many of those have had no activity in >30 days
     * }
     */
    public function companyTotals(string $asOfDate = ''): array
    {
        if ($asOfDate === '') $asOfDate = date('Y-m-d');

        // Main till = cb_ledger SSP all-time net flow.
        // Includes: revenue, advances issued, direct expenses, exchanges, salaries, returns.
        // All these are physical cash movements at the main cashbook.
        $r = $this->pdo->query(
            "SELECT
                ROUND(COALESCE(SUM(CASE WHEN direction='in'  THEN ssp_amount ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END), 0), 0) AS net
             FROM cb_ledger
             WHERE currency='SSP' AND status != 'voided'"
        )->fetch(\PDO::FETCH_ASSOC);
        $mainTill = (float)($r['net'] ?? 0);

        // In-imprest = sum across all active staff of their SSP cash_exposure
        // (advances received - expenses - returns - handovers - transfers, net).
        // This comes from staff_ledger which is the v4.11.3 source of truth.
        $imprestRows = $this->pdo->query(
            "SELECT staff_id,
                ROUND(
                    COALESCE(SUM(CASE WHEN direction='in'  THEN ssp_amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END), 0)
                , 0) AS bal
             FROM staff_ledger
             WHERE currency='SSP' AND status NOT IN ('voided','cancelled')
             GROUP BY staff_id"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $inImprest = 0.0;
        $holderCount = 0;
        foreach ($imprestRows as $row) {
            $b = (float)$row['bal'];
            // Tolerance for floating-point dust; only positive balances count
            // (negative would mean over-spent, surfaced separately in the holders report)
            if ($b > 0.5) {
                $inImprest += $b;
                $holderCount++;
            }
        }

        // Today's flow
        $today = $this->todayFlow($asOfDate);

        // Stale holders = positive imprest, no movement in 30+ days
        $staleStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (
                SELECT staff_id,
                    SUM(CASE WHEN direction='in' THEN ssp_amount ELSE -ssp_amount END) AS bal,
                    MAX(COALESCE(NULLIF(event_date,''), DATE(created_at))) AS last_evt
                FROM staff_ledger
                WHERE currency='SSP' AND status NOT IN ('voided','cancelled')
                GROUP BY staff_id
                HAVING bal > 0.5 AND last_evt < ?
             )"
        );
        $cutoff = date('Y-m-d', strtotime($asOfDate . ' -30 days'));
        $staleStmt->execute([$cutoff]);
        $staleCount = (int)$staleStmt->fetchColumn();

        return [
            'as_of'                => $asOfDate,
            'main_till_balance'    => round($mainTill, 0),
            'in_imprest'           => round($inImprest, 0),
            'total_company_ssp'    => round($mainTill + $inImprest, 0),
            'today'                => $today,
            'imprest_holder_count' => $holderCount,
            'stale_holder_count'   => $staleCount,
        ];
    }

    /** @return array {advances_issued, direct_expenses, imprest_expenses, returns_received} */
    private function todayFlow(string $date): array
    {
        // Advances issued today (cb_ledger OUT, SSP Advance / Staff Advance)
        $advCats = "'" . implode("','", self::$imprestIssueCategories) . "'";
        $r1 = $this->pdo->prepare(
            "SELECT COALESCE(SUM(ssp_amount), 0) FROM cb_ledger
             WHERE date = ? AND currency='SSP' AND direction='out'
               AND category IN ($advCats) AND status != 'voided'"
        );
        $r1->execute([$date]);
        $advances = (float)$r1->fetchColumn();

        // Direct expenses (cb_ledger OUT, non-imprest categories, not exchange/salary/refund)
        // Excluded: imprest issue, payroll, exchange, return — those aren't "expenses"
        $excluded = array_merge(
            self::$imprestIssueCategories,
            self::$personalPayCategories,
            ['Exchange', 'SSP Return', 'Cash Adjustment']
        );
        $excludedSql = "'" . implode("','", $excluded) . "'";
        $r2 = $this->pdo->prepare(
            "SELECT COALESCE(SUM(ssp_amount), 0) FROM cb_ledger
             WHERE date = ? AND currency='SSP' AND direction='out'
               AND category NOT IN ($excludedSql) AND status != 'voided'"
        );
        $r2->execute([$date]);
        $directExp = (float)$r2->fetchColumn();

        // Imprest-funded expenses approved today (staff_expenses, currency=SSP, advance-linked)
        $r3 = $this->pdo->prepare(
            "SELECT COALESCE(SUM(ssp_amount), 0) FROM staff_expenses
             WHERE DATE(reviewed_at) = ?
               AND status='approved' AND currency='SSP'
               AND advance_id IS NOT NULL AND advance_id > 0"
        );
        $r3->execute([$date]);
        $imprestExp = (float)$r3->fetchColumn();

        // Returns to office today (cb_ledger IN, SSP Return)
        $r4 = $this->pdo->prepare(
            "SELECT COALESCE(SUM(ssp_amount), 0) FROM cb_ledger
             WHERE date = ? AND currency='SSP' AND direction='in'
               AND category='SSP Return' AND status != 'voided'"
        );
        $r4->execute([$date]);
        $returns = (float)$r4->fetchColumn();

        return [
            'advances_issued'  => round($advances, 0),
            'direct_expenses'  => round($directExp, 0),
            'imprest_expenses' => round($imprestExp, 0),
            'returns_received' => round($returns, 0),
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    //  REPORT 2 — imprestHolders
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Per-staff SSP imprest position. The accountant's daily safety net.
     *
     * @return array of rows, ordered by balance DESC. Each row:
     *   staff_id, staff_name, advances, expenses, returns,
     *   transfers_in, transfers_out, balance, last_movement_at,
     *   days_since_movement, status ('fresh'|'stale'|'overdue'|'overdrawn'|'zero')
     */
    public function imprestHolders(): array
    {
        // Pull aggregated position per staff from staff_ledger.
        // Mirrors StaffLedgerService::position() math but in one round-trip.
        $stmt = $this->pdo->query(
            "SELECT staff_id, staff_name,
                ROUND(COALESCE(SUM(CASE WHEN category='advance'        AND direction='in'  THEN ssp_amount ELSE 0 END), 0), 0) AS advances,
                ROUND(COALESCE(SUM(CASE WHEN category='expense'        AND direction='out' THEN ssp_amount ELSE 0 END), 0), 0) AS expenses,
                ROUND(COALESCE(SUM(CASE WHEN category='advance_return' AND direction='out' THEN ssp_amount ELSE 0 END), 0), 0) AS returns,
                ROUND(COALESCE(SUM(CASE WHEN (category='transfer_in'  OR category='ssp_transfer_in')  AND direction='in'  THEN ssp_amount ELSE 0 END), 0), 0) AS transfers_in,
                ROUND(COALESCE(SUM(CASE WHEN (category='transfer_out' OR category='ssp_transfer_out') AND direction='out' THEN ssp_amount ELSE 0 END), 0), 0) AS transfers_out,
                ROUND(COALESCE(SUM(CASE WHEN category='handover'       AND direction='out' THEN ssp_amount ELSE 0 END), 0), 0) AS handovers,
                ROUND(
                    COALESCE(SUM(CASE WHEN direction='in'  THEN ssp_amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END), 0)
                , 0) AS balance,
                MAX(COALESCE(NULLIF(event_date,''), DATE(created_at))) AS last_movement_at,
                COUNT(*) AS movement_count
             FROM staff_ledger
             WHERE currency='SSP' AND status NOT IN ('voided','cancelled')
             GROUP BY staff_id, staff_name
             HAVING movement_count > 0
             ORDER BY balance DESC"
        );
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Resolve current staff names from retailers.json — staff_name on
        // ledger rows is captured at write-time and may be stale.
        $retailers = $this->store->load('retailers.json') ?? [];
        $nameById = [];
        foreach ($retailers as $r) {
            $nameById[(int)($r['id'] ?? 0)] = $r['name'] ?? '';
        }

        $today = date('Y-m-d');
        $result = [];
        foreach ($rows as $row) {
            $sid     = (int)$row['staff_id'];
            $balance = (float)$row['balance'];
            $last    = $row['last_movement_at'] ?: '';
            $days    = ($last && $last !== '0000-00-00')
                ? (int)((strtotime($today) - strtotime($last)) / 86400)
                : 0;

            // Status classification
            if ($balance < -0.5) {
                $status = 'overdrawn';     // owes the company — usually a data bug
            } elseif (abs($balance) < 0.5) {
                $status = 'zero';          // settled, no current imprest
            } elseif ($days > 30) {
                $status = 'overdue';       // sitting on cash for over a month
            } elseif ($days > 14) {
                $status = 'stale';         // 2+ weeks no activity, watch list
            } else {
                $status = 'fresh';
            }

            $result[] = [
                'staff_id'            => $sid,
                'staff_name'          => $nameById[$sid] ?: ($row['staff_name'] ?: 'Staff #' . $sid),
                'advances'            => (float)$row['advances'],
                'expenses'            => (float)$row['expenses'],
                'returns'             => (float)$row['returns'],
                'transfers_in'        => (float)$row['transfers_in'],
                'transfers_out'       => (float)$row['transfers_out'],
                'handovers'           => (float)$row['handovers'],
                'balance'             => $balance,
                'last_movement_at'    => $last,
                'days_since_movement' => $days,
                'movement_count'      => (int)$row['movement_count'],
                'status'              => $status,
            ];
        }

        return $result;
    }

    /**
     * Drill-down: full SSP movement history for one staff member.
     * @return array of {date, direction, ssp_amount, category, description, source_type, source_id}
     */
    public function holderHistory(int $staffId, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(NULLIF(event_date,''), DATE(created_at)) AS date,
                direction, ssp_amount, category, subcategory, description,
                source_type, source_id, status, created_at
             FROM staff_ledger
             WHERE staff_id = ? AND currency='SSP'
               AND status NOT IN ('voided','cancelled')
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$staffId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  REPORT 3 — pAndLByCategory
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Period SSP P&L by category, blending imprest-funded and direct payments.
     *
     * @param string $dateFrom YYYY-MM-DD inclusive
     * @param string $dateTo   YYYY-MM-DD inclusive
     * @return array {
     *   period: {from, to, days},
     *   rows: [{category_label, from_imprest, from_direct, total}, ...] — ordered by total DESC
     *   totals: {imprest_total, direct_total, grand_total},
     *   notes: [...]
     * }
     */
    public function pAndLByCategory(string $dateFrom, string $dateTo): array
    {
        // ── Side A: imprest-funded spend (staff_expenses, currency=SSP, advance-linked)
        // After v4.20.3 these no longer touch cb_ledger by design, so the only
        // place they live is staff_expenses.
        $stmtA = $this->pdo->prepare(
            "SELECT category, ROUND(COALESCE(SUM(ssp_amount),0), 0) AS total
             FROM staff_expenses
             WHERE status='approved' AND currency='SSP'
               AND expense_date BETWEEN ? AND ?
             GROUP BY category"
        );
        $stmtA->execute([$dateFrom, $dateTo]);
        $imprestRows = $stmtA->fetchAll(\PDO::FETCH_ASSOC);

        $byLabel = [];
        $imprestTotal = 0.0;
        foreach ($imprestRows as $r) {
            $cat = (string)$r['category'];
            $label = self::$expenseCategoryLabels[$cat] ?? ucfirst($cat);
            if (!isset($byLabel[$label])) {
                $byLabel[$label] = ['from_imprest' => 0.0, 'from_direct' => 0.0];
            }
            $byLabel[$label]['from_imprest'] += (float)$r['total'];
            $imprestTotal += (float)$r['total'];
        }

        // ── Side B: direct payments (cb_ledger SSP OUT, expense categories, not imprest issue)
        // These are vendor-direct payments where cash left the till AND was an expense
        // in the same transaction. Already in cb_ledger as source='manual' or 'expense_sync' (free-standing).
        $directCatsSql = "'" . implode("','", self::$directExpenseCbCategories) . "'";
        $stmtB = $this->pdo->prepare(
            "SELECT category, ROUND(COALESCE(SUM(ssp_amount),0), 0) AS total
             FROM cb_ledger
             WHERE currency='SSP' AND direction='out'
               AND date BETWEEN ? AND ?
               AND status != 'voided'
               AND category IN ($directCatsSql)
             GROUP BY category"
        );
        $stmtB->execute([$dateFrom, $dateTo]);
        $directRows = $stmtB->fetchAll(\PDO::FETCH_ASSOC);

        $directTotal = 0.0;
        foreach ($directRows as $r) {
            $rawCat = (string)$r['category'];
            $label  = self::$cbCategoryLabels[$rawCat] ?? $rawCat;
            if (!isset($byLabel[$label])) {
                $byLabel[$label] = ['from_imprest' => 0.0, 'from_direct' => 0.0];
            }
            $byLabel[$label]['from_direct'] += (float)$r['total'];
            $directTotal += (float)$r['total'];
        }

        // Build rows array, sorted by total
        $rows = [];
        foreach ($byLabel as $label => $vals) {
            $rows[] = [
                'category_label' => $label,
                'from_imprest'   => round($vals['from_imprest'], 0),
                'from_direct'    => round($vals['from_direct'], 0),
                'total'          => round($vals['from_imprest'] + $vals['from_direct'], 0),
            ];
        }
        usort($rows, function($a, $b) { return $b['total'] <=> $a['total']; });

        // Days in period (inclusive)
        $days = max(1, (int)((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1);

        return [
            'period' => [
                'from' => $dateFrom,
                'to'   => $dateTo,
                'days' => $days,
            ],
            'rows' => $rows,
            'totals' => [
                'imprest_total' => round($imprestTotal, 0),
                'direct_total'  => round($directTotal, 0),
                'grand_total'   => round($imprestTotal + $directTotal, 0),
            ],
            'notes' => [
                'Imprest-funded spend comes from staff_expenses (post-v4.20.3 lives only there for advance-linked SSP).',
                'Direct payments come from cb_ledger expense categories where the till paid the vendor directly.',
                'Salaries, allowances, exchanges, advances issued, returns are NOT shown — those are not P&L expenses.',
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    //  REPORT 4 — cashFlowSummary (supporting view for the audit trail)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Period SSP cash flow at the main till. Pure cb_ledger view — every
     * shilling that physically moved in or out, grouped by category.
     *
     * @return array {
     *   period: {from, to},
     *   inflow:  [{category, total, count}, ...]
     *   outflow: [{category, total, count}, ...]
     *   totals:  {total_in, total_out, net}
     * }
     */
    public function cashFlowSummary(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT direction, category, ROUND(SUM(ssp_amount), 0) AS total, COUNT(*) AS count
             FROM cb_ledger
             WHERE currency='SSP' AND status != 'voided'
               AND date BETWEEN ? AND ?
             GROUP BY direction, category
             ORDER BY direction, total DESC"
        );
        $stmt->execute([$dateFrom, $dateTo]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $inflow = []; $outflow = [];
        $totalIn = 0.0; $totalOut = 0.0;
        foreach ($rows as $r) {
            $entry = [
                'category' => $r['category'],
                'total'    => (float)$r['total'],
                'count'    => (int)$r['count'],
            ];
            if ($r['direction'] === 'in') {
                $inflow[] = $entry;
                $totalIn += $entry['total'];
            } else {
                $outflow[] = $entry;
                $totalOut += $entry['total'];
            }
        }

        return [
            'period'  => ['from' => $dateFrom, 'to' => $dateTo],
            'inflow'  => $inflow,
            'outflow' => $outflow,
            'totals'  => [
                'total_in'  => round($totalIn, 0),
                'total_out' => round($totalOut, 0),
                'net'       => round($totalIn - $totalOut, 0),
            ],
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    //  AUDIT METHODS (v4.20.4 — pre-reconciliation diagnostic)
    // ════════════════════════════════════════════════════════════════════════
    //
    //  Read-only checks that compare cb_ledger (main cashbook) against
    //  staff_ledger / cb_ssp_register / staff_expenses to expose where
    //  legacy double-counting lives, on a per-staff basis.
    //
    //  These methods NEVER write. They are diagnostic only — Bhavin /
    //  Rupesh decide what to do with the findings.
    //
    //  Linkage strategy (cb_ledger has no staff_id column on legacy rows):
    //
    //    1. Preferred: cb_ssp_register.cb_sr  ↔  cb_ledger.sr  (covers post-v4.9.18 advances)
    //    2. Fallback:  cb_ledger.cash_with_id (covers v4.11.3+ entries)
    //    3. Last:      fuzzy name match against retailers.json
    //
    //  Mismatches identified by fallbacks 2/3 are flagged 'name_match'
    //  in the output so the accountant knows the link is approximate.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Per-staff: cb_ledger advance total vs staff_ledger advance total.
     * If they disagree, either an advance hit cb_ledger but not staff_ledger
     * (rare — would mean the dual-write failed) or vice versa.
     *
     * @return array of {staff_id, staff_name, cb_ledger_total, staff_ledger_total,
     *                   diff, link_method, status}
     */
    public function auditAdvanceTotals(): array
    {
        // Step 1: build a name → retailer_id map for fallback resolution
        $retailers = $this->store->load('retailers.json') ?? [];
        $retailerById   = [];
        $retailerByName = []; // lowercased name -> id
        foreach ($retailers as $r) {
            $rid = (int)($r['id'] ?? 0);
            if ($rid <= 0) continue;
            $retailerById[$rid] = $r['name'] ?? '';
            $nm = strtolower(trim($r['name'] ?? ''));
            if ($nm !== '') $retailerByName[$nm] = $rid;
        }

        // Step 2: Aggregate cb_ledger SSP advances per resolved staff_id.
        //   Resolution priority:
        //     a) JOIN cb_ssp_register on cb_sr (most reliable, covers post-v4.9.18)
        //     b) cb_ledger.cash_with_id (v4.11.3+ rows)
        //     c) fuzzy name match (last resort, flagged in output)
        $cbAdvances = []; // staff_id => ['total' => float, 'rows' => int, 'methods' => set]

        // (a) Via cb_ssp_register link
        $rowsA = $this->pdo->query(
            "SELECT r.staff_id, r.staff_name, l.ssp_amount, l.sr
             FROM cb_ssp_register r
             JOIN cb_ledger l ON l.sr = r.cb_sr
             WHERE r.source_type='advance_issue'
               AND l.currency='SSP' AND l.direction='out'
               AND l.status != 'voided'
               AND l.category IN ('SSP Advance','Staff Advance')"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $linkedSrs = []; // sr => true (so we don't double-count via fallback)
        foreach ($rowsA as $r) {
            $sid = (int)$r['staff_id'];
            if ($sid <= 0) continue;
            if (!isset($cbAdvances[$sid])) $cbAdvances[$sid] = ['total'=>0,'rows'=>0,'methods'=>[]];
            $cbAdvances[$sid]['total']   += (float)$r['ssp_amount'];
            $cbAdvances[$sid]['rows']    += 1;
            $cbAdvances[$sid]['methods']['ssp_register'] = true;
            $linkedSrs[$r['sr']] = true;
        }

        // (b) + (c) — handle anything not covered by (a)
        $rowsBC = $this->pdo->query(
            "SELECT id, sr, ssp_amount, person, cash_with_id
             FROM cb_ledger
             WHERE currency='SSP' AND direction='out'
               AND status != 'voided'
               AND category IN ('SSP Advance','Staff Advance')"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rowsBC as $r) {
            if (isset($linkedSrs[$r['sr']])) continue; // already counted via (a)
            $sid = (int)($r['cash_with_id'] ?? 0);
            $method = 'cash_with_id';
            if ($sid <= 0) {
                // Fallback (c): fuzzy name match
                $person = strtolower(trim((string)$r['person']));
                if ($person === '') continue; // cannot resolve
                if (isset($retailerByName[$person])) {
                    $sid = $retailerByName[$person];
                    $method = 'name_exact';
                } else {
                    // Contains match
                    foreach ($retailerByName as $rn => $rid) {
                        if (strpos($rn, $person) !== false || strpos($person, $rn) !== false) {
                            $sid = $rid;
                            $method = 'name_fuzzy';
                            break;
                        }
                    }
                }
                if ($sid <= 0) continue; // unresolvable
            }
            if (!isset($cbAdvances[$sid])) $cbAdvances[$sid] = ['total'=>0,'rows'=>0,'methods'=>[]];
            $cbAdvances[$sid]['total']   += (float)$r['ssp_amount'];
            $cbAdvances[$sid]['rows']    += 1;
            $cbAdvances[$sid]['methods'][$method] = true;
        }

        // Step 3: Aggregate staff_ledger SSP advance total per staff
        $rowsSL = $this->pdo->query(
            "SELECT staff_id, staff_name,
                ROUND(COALESCE(SUM(ssp_amount), 0), 0) AS total,
                COUNT(*) AS rows
             FROM staff_ledger
             WHERE currency='SSP' AND direction='in'
               AND category='advance'
               AND status NOT IN ('voided','cancelled')
             GROUP BY staff_id, staff_name"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $slAdvances = []; // staff_id => ['total','rows']
        foreach ($rowsSL as $r) {
            $sid = (int)$r['staff_id'];
            if ($sid <= 0) continue;
            $slAdvances[$sid] = [
                'total' => (float)$r['total'],
                'rows'  => (int)$r['rows'],
                'name'  => $r['staff_name'],
            ];
        }

        // Step 4: Merge — every staff_id present in either side
        $allSids = array_unique(array_merge(array_keys($cbAdvances), array_keys($slAdvances)));
        sort($allSids);

        $result = [];
        foreach ($allSids as $sid) {
            $cb = $cbAdvances[$sid] ?? ['total'=>0,'rows'=>0,'methods'=>[]];
            $sl = $slAdvances[$sid] ?? ['total'=>0,'rows'=>0,'name'=>''];
            $name = $retailerById[$sid] ?? ($sl['name'] ?? ('Staff #' . $sid));
            $diff = round($cb['total'] - $sl['total'], 0);

            // Link method summary
            $methods = array_keys($cb['methods']);
            $linkMethod = empty($methods) ? 'no_cb_data'
                : (count($methods) > 1 ? 'mixed' : $methods[0]);

            // Status: ok if within 1 SSP, else mismatch
            if (abs($diff) < 1) {
                $status = 'match';
            } elseif ($diff > 0) {
                $status = 'cb_higher';   // cb_ledger has more advance than staff_ledger — possible missing dual-write
            } else {
                $status = 'sl_higher';   // staff_ledger has more — rarer, possible cb_ledger missing rows
            }

            $result[] = [
                'staff_id'           => $sid,
                'staff_name'         => $name,
                'cb_ledger_total'    => round($cb['total'], 0),
                'cb_ledger_rows'     => $cb['rows'],
                'staff_ledger_total' => round($sl['total'], 0),
                'staff_ledger_rows'  => $sl['rows'],
                'diff'               => $diff,
                'link_method'        => $linkMethod,
                'status'             => $status,
            ];
        }

        // Sort by absolute diff DESC so worst mismatches surface first
        usort($result, function($a, $b) { return abs($b['diff']) <=> abs($a['diff']); });
        return $result;
    }

    /**
     * Per-staff: how much SSP-with-advance_id expense has been double-posted
     * to cb_ledger historically (i.e. legacy duplicates that v4.20.3 prevents
     * from accruing further). This is the per-staff slice of the ~3.25M figure.
     *
     * @return array of {staff_id, staff_name, expense_total, cb_duplicate_total,
     *                   diff, status}
     *   Where:
     *     expense_total       = staff_expenses approved SSP with advance_id
     *     cb_duplicate_total  = matching cb_ledger rows (source IN ('expense_sync','field_merge'),
     *                           validation_ref matches expense_no or chain_ref)
     */
    public function auditExpenseTotals(): array
    {
        // Step 1: per-staff sum from staff_expenses (advance-linked SSP, approved)
        $expRows = $this->pdo->query(
            "SELECT staff_id, staff_name,
                ROUND(COALESCE(SUM(ssp_amount), 0), 0) AS total,
                COUNT(*) AS rows
             FROM staff_expenses
             WHERE status='approved' AND currency='SSP'
               AND advance_id IS NOT NULL AND advance_id > 0
             GROUP BY staff_id, staff_name"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $expBySid = [];
        foreach ($expRows as $r) {
            $sid = (int)$r['staff_id'];
            if ($sid <= 0) continue;
            $expBySid[$sid] = [
                'total' => (float)$r['total'],
                'rows'  => (int)$r['rows'],
                'name'  => $r['staff_name'],
            ];
        }

        // Step 2: per-staff sum from cb_ledger duplicate posts
        // Match approach: validation_ref starts with 'EXP-' or matches a chain_ref pattern.
        // cashbook_entry_id on staff_expenses links explicitly when > 0 (legacy).
        // Build expense_no -> staff_id map first, then sum cb_ledger rows whose
        // validation_ref equals an expense_no.
        $expNoToSid = [];
        $rows2 = $this->pdo->query(
            "SELECT expense_no, staff_id, ssp_amount
             FROM staff_expenses
             WHERE status='approved' AND currency='SSP'
               AND advance_id IS NOT NULL AND advance_id > 0"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows2 as $r) {
            $expNoToSid[(string)$r['expense_no']] = (int)$r['staff_id'];
        }

        $cbDupBySid = [];
        if (!empty($expNoToSid)) {
            // Use IN (...) with expense_no list. Limit to avoid SQL parameter cap.
            $expNos = array_keys($expNoToSid);
            $batches = array_chunk($expNos, 500);
            foreach ($batches as $batch) {
                $ph = implode(',', array_fill(0, count($batch), '?'));
                $stmt = $this->pdo->prepare(
                    "SELECT validation_ref, ssp_amount FROM cb_ledger
                     WHERE source IN ('expense_sync','field_merge')
                       AND currency='SSP' AND direction='out'
                       AND status != 'voided'
                       AND validation_ref IN ($ph)"
                );
                $stmt->execute($batch);
                while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    $sid = $expNoToSid[$r['validation_ref']] ?? 0;
                    if ($sid <= 0) continue;
                    if (!isset($cbDupBySid[$sid])) $cbDupBySid[$sid] = ['total'=>0,'rows'=>0];
                    $cbDupBySid[$sid]['total'] += (float)$r['ssp_amount'];
                    $cbDupBySid[$sid]['rows']  += 1;
                }
            }
        }

        // Also pick up cb_ledger rows from SspAdvanceService::mergeExpenseToLedger
        // (chain_ref starting with FIELD-) — those use a different validation_ref
        // pattern. Match on the cb_ssp_register.staff_id directly.
        $rowsB = $this->pdo->query(
            "SELECT r.staff_id, l.ssp_amount
             FROM cb_ssp_register r
             JOIN cb_ledger l ON l.validation_ref = r.chain_ref
             WHERE r.source_type='expense'
               AND l.currency='SSP' AND l.direction='out'
               AND l.status != 'voided'
               AND l.source IN ('expense_sync','field_merge')"
        )->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rowsB as $r) {
            $sid = (int)$r['staff_id'];
            if ($sid <= 0) continue;
            if (!isset($cbDupBySid[$sid])) $cbDupBySid[$sid] = ['total'=>0,'rows'=>0];
            $cbDupBySid[$sid]['total'] += (float)$r['ssp_amount'];
            $cbDupBySid[$sid]['rows']  += 1;
        }

        // Step 3: merge sides
        $retailers = $this->store->load('retailers.json') ?? [];
        $retailerById = [];
        foreach ($retailers as $r) $retailerById[(int)($r['id'] ?? 0)] = $r['name'] ?? '';

        $allSids = array_unique(array_merge(array_keys($expBySid), array_keys($cbDupBySid)));
        sort($allSids);

        $result = [];
        foreach ($allSids as $sid) {
            $exp = $expBySid[$sid] ?? ['total'=>0,'rows'=>0,'name'=>''];
            $cb  = $cbDupBySid[$sid] ?? ['total'=>0,'rows'=>0];
            $name = $retailerById[$sid] ?? ($exp['name'] ?: ('Staff #' . $sid));
            $diff = round($exp['total'] - $cb['total'], 0);

            // Status: classify the duplication pattern.
            //
            //   clean             — no cb_ledger duplicates exist for this staff
            //   single_duplicate  — cb_dup ≈ expense_total (each expense posted once to cb_ledger;
            //                       typical when only ExpenseAdvanceService::approveExpense fired)
            //   double_duplicate  — cb_dup ≈ 2× expense_total (each expense posted twice;
            //                       happens when both approveExpense AND mergeExpenseToLedger fired)
            //   partial_duplicates — anything else (some expenses duplicated, others not)
            $tol = max(1.0, $exp['total'] * 0.02); // 2% tolerance for rounding
            if ($cb['total'] < 1) {
                $status = 'clean';
            } elseif (abs($cb['total'] - $exp['total']) <= $tol) {
                $status = 'single_duplicate';
            } elseif (abs($cb['total'] - 2 * $exp['total']) <= $tol) {
                $status = 'double_duplicate';
            } else {
                $status = 'partial_duplicates';
            }

            $result[] = [
                'staff_id'             => $sid,
                'staff_name'           => $name,
                'expense_total'        => round($exp['total'], 0),
                'expense_rows'         => $exp['rows'],
                'cb_duplicate_total'   => round($cb['total'], 0),
                'cb_duplicate_rows'    => $cb['rows'],
                'diff'                 => $diff,
                'status'               => $status,
            ];
        }

        usort($result, function($a, $b) { return $b['cb_duplicate_total'] <=> $a['cb_duplicate_total']; });
        return $result;
    }

    /**
     * Company-level reconciliation summary. Pulls the headline numbers
     * Bhavin needs to decide on the size of the cutover-day adjustment entry.
     *
     * @return array {
     *   cb_ledger_ssp_balance:     what cb_ledger says the main till has (running net)
     *   total_legacy_duplicates:   sum of cb_duplicate_total across all staff
     *   reconstructed_main_till:   cb_ledger_balance + total_legacy_duplicates
     *                              (= what the main till would say if duplicates were reversed)
     *   total_imprest:             sum of positive staff_ledger balances
     *   reconstructed_company_ssp: reconstructed_main_till + total_imprest
     *                              (= what physical SSP cash count should show on cutover day)
     *   notes: [...]
     * }
     */
    public function auditCompanyReconciliation(): array
    {
        // cb_ledger SSP net (running balance, all-time)
        $r = $this->pdo->query(
            "SELECT ROUND(
                COALESCE(SUM(CASE WHEN direction='in'  THEN ssp_amount ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END), 0)
            , 0) AS net
             FROM cb_ledger
             WHERE currency='SSP' AND status != 'voided'"
        )->fetch(\PDO::FETCH_ASSOC);
        $cbBal = (float)($r['net'] ?? 0);

        // Total legacy duplicates from auditExpenseTotals
        $expAudit = $this->auditExpenseTotals();
        $legacyDups = 0.0;
        foreach ($expAudit as $row) $legacyDups += (float)$row['cb_duplicate_total'];

        // Total positive staff imprest balances
        $imprestRows = $this->pdo->query(
            "SELECT staff_id,
                ROUND(
                    COALESCE(SUM(CASE WHEN direction='in'  THEN ssp_amount ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END), 0)
                , 0) AS bal
             FROM staff_ledger
             WHERE currency='SSP' AND status NOT IN ('voided','cancelled')
             GROUP BY staff_id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $totalImprest = 0.0;
        foreach ($imprestRows as $row) {
            $b = (float)$row['bal'];
            if ($b > 0.5) $totalImprest += $b;
        }

        $reconstructedTill    = $cbBal + $legacyDups;
        $reconstructedCompany = $reconstructedTill + $totalImprest;

        return [
            'as_of'                       => date('Y-m-d H:i:s'),
            'cb_ledger_ssp_balance'       => round($cbBal, 0),
            'total_legacy_duplicates'     => round($legacyDups, 0),
            'reconstructed_main_till'     => round($reconstructedTill, 0),
            'total_imprest'               => round($totalImprest, 0),
            'reconstructed_company_ssp'   => round($reconstructedCompany, 0),
            'notes' => [
                'cb_ledger_ssp_balance is what the system thinks the main till has (running net of cb_ledger SSP).',
                'total_legacy_duplicates is the sum of double-posted expense rows that v4.20.3 prevents going forward.',
                'reconstructed_main_till = cb_ledger balance + duplicates. This is what the main till SHOULD show if the bug had never existed.',
                'reconstructed_company_ssp = reconstructed main till + all positive staff imprest balances. This is the figure to count physical cash against on cutover day.',
                'If your physical SSP count on cutover day equals reconstructed_company_ssp, the books reconcile. Any difference is real cash leakage independent of the bug.',
            ],
        ];
    }
}
