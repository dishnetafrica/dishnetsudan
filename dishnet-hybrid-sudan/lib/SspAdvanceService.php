<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

require_once __DIR__ . '/CashbookService.php';

/**
 * SspAdvanceService — SSP Cash Chain between Rupesh and field staff
 * DishNet Hybrid v4.20.3
 *
 * ── Flow (imprest model, v4.20.3+) ──────────────────────────────────────────
 *
 *   1. Rupesh gives SSP to staff (cashbook Cash OUT → "SSP Advance")
 *      → addEntry to cb_ledger (existing flow — physical cash leaves the till)
 *      → registerAdvanceIssue() creates cb_ssp_register IN for staff
 *      → Both linked by SSPADV-{staff}-{ts}
 *
 *   2. Staff records SSP expense (field register Cash OUT)
 *      → Saved in cash_expenses.json (existing flow)
 *      → registerExpense() creates cb_ssp_register OUT for tracking
 *
 *   3. Rupesh approves expense
 *      → mergeExpenseToLedger() flips register status to 'merged'
 *      → cb_ledger is NOT touched (the SSP cash already left at step 1).
 *        Posting another OUT here would double-count — see SAFETY.md Rule 16.
 *      → Per-category breakdown lives in cb_ssp_register, not cb_ledger.
 *
 *   4. Staff returns remaining SSP
 *      → recordReturn() creates cb_ssp_register OUT + cb_ledger IN
 *        (genuine cash flowing back into the till, so cb_ledger DOES record it)
 *      → Both linked by SSPRET-{staff}-{ts}
 *
 * PHP 7.4 compatible. No match(), no named args, no str_starts_with.
 */
class SspAdvanceService
{
    /** @var \PDO */
    private $pdo;

    /** @var CashbookService */
    private $cb;

    /** @var string */
    private $dataDir;

    public function __construct($store, string $dataDir)
    {
        $this->pdo     = $store->getPdo();
        $this->cb      = new CashbookService($store, $dataDir);
        $this->dataDir = $dataDir;
        $this->ensureTable();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TABLE SETUP
    // ═══════════════════════════════════════════════════════════════════

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS cb_ssp_register (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            staff_id        INTEGER NOT NULL,
            staff_name      TEXT NOT NULL,
            cashbook        TEXT NOT NULL DEFAULT 'dishnet',
            date            TEXT NOT NULL,
            direction       TEXT NOT NULL,
            ssp_amount      INTEGER NOT NULL,
            usd_equivalent  REAL DEFAULT 0,
            ssp_rate        REAL DEFAULT 0,
            category        TEXT NOT NULL DEFAULT '',
            description     TEXT DEFAULT '',
            status          TEXT NOT NULL DEFAULT 'pending',
            approved_by     TEXT DEFAULT '',
            approved_at     TEXT,
            source_type     TEXT NOT NULL DEFAULT 'expense',
            chain_ref       TEXT NOT NULL DEFAULT '',
            merged_to_cb    INTEGER NOT NULL DEFAULT 0,
            merged_at       TEXT,
            cb_ledger_id    INTEGER,
            cb_sr           TEXT DEFAULT '',
            created_at      TEXT NOT NULL,
            created_by      TEXT DEFAULT ''
        )");
        // Indexes — safe to run multiple times
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ssp_reg_staff  ON cb_ssp_register(staff_id, date)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ssp_reg_status ON cb_ssp_register(status)");
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ssp_reg_chain ON cb_ssp_register(chain_ref)");
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. ISSUE SSP ADVANCE (Rupesh gives SSP to staff)
    // ═══════════════════════════════════════════════════════════════════
    //  Called from post_cashbook.php AFTER cb_ledger OUT is already created.
    //  Creates matching IN on staff's ssp_register.

    /**
     * @param int    $staffId     Retailer ID of staff member
     * @param string $staffName   Display name (e.g. "BBC")
     * @param int    $sspAmount   SSP amount issued
     * @param float  $rate        Exchange rate used
     * @param string $cashbook    Project (dishnet/4g)
     * @param string $cbSr        SR number from the cb_ledger OUT entry
     * @param string $description Purpose text
     * @param string $actorName   Who issued (e.g. "Rupesh")
     * @return array {ok, chain_ref, register_id}
     */
    public function registerAdvanceIssue(
        int $staffId, string $staffName, int $sspAmount, float $rate,
        string $cashbook, string $cbSr, string $description, string $actorName
    ): array {
        $now      = date('Y-m-d H:i:s');
        $chainRef = 'SSPADV-' . $this->slugName($staffName) . '-' . time();
        $usdEquiv = $rate > 0 ? round($sspAmount / $rate, 2) : 0;

        // Dedup: check if this cb_sr already linked
        $exists = $this->pdo->prepare("SELECT id FROM cb_ssp_register WHERE cb_sr = ? LIMIT 1");
        $exists->execute([$cbSr]);
        if ($exists->fetchColumn()) {
            return ['ok' => true, 'skipped' => 'already_linked', 'chain_ref' => ''];
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO cb_ssp_register
             (staff_id, staff_name, cashbook, date, direction, ssp_amount, usd_equivalent,
              ssp_rate, category, description, status, approved_by, approved_at,
              source_type, chain_ref, merged_to_cb, cb_sr, created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $staffId, $staffName, $cashbook, date('Y-m-d'), 'in', $sspAmount, $usdEquiv,
            $rate, 'SSP Advance', $description, 'approved', $actorName, $now,
            'advance_issue', $chainRef, 0, $cbSr, $now, $actorName,
        ]);

        return [
            'ok'          => true,
            'chain_ref'   => $chainRef,
            'register_id' => (int)$this->pdo->lastInsertId(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. REGISTER STAFF SSP EXPENSE (for tracking)
    // ═══════════════════════════════════════════════════════════════════
    //  Called from post_cashbook.php after cash_expenses.json save.
    //  Creates cb_ssp_register OUT entry for chain tracking.

    /**
     * @param int    $staffId
     * @param string $staffName
     * @param int    $sspAmount
     * @param string $category     Fuel, Vehicle, Food, etc.
     * @param string $description
     * @param string $cashbook
     * @param int    $expenseJsonId  ID in cash_expenses.json (for linking)
     * @return array {ok, chain_ref, register_id}
     */
    public function registerExpense(
        int $staffId, string $staffName, int $sspAmount,
        string $category, string $description, string $cashbook,
        int $expenseJsonId = 0
    ): array {
        $now      = date('Y-m-d H:i:s');
        $chainRef = 'FIELD-' . $this->slugName($staffName) . '-' . ($expenseJsonId ?: time());

        // Dedup
        $exists = $this->pdo->prepare("SELECT id FROM cb_ssp_register WHERE chain_ref = ? LIMIT 1");
        $exists->execute([$chainRef]);
        if ($exists->fetchColumn()) {
            return ['ok' => true, 'skipped' => 'already_registered', 'chain_ref' => $chainRef];
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO cb_ssp_register
             (staff_id, staff_name, cashbook, date, direction, ssp_amount, usd_equivalent,
              ssp_rate, category, description, status, source_type, chain_ref,
              merged_to_cb, created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $staffId, $staffName, $cashbook, date('Y-m-d'), 'out', $sspAmount, 0,
            0, $category, $description, 'pending', 'expense', $chainRef,
            0, $now, $staffName,
        ]);

        return [
            'ok'          => true,
            'chain_ref'   => $chainRef,
            'register_id' => (int)$this->pdo->lastInsertId(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. APPROVE & MERGE TO CB_LEDGER
    // ═══════════════════════════════════════════════════════════════════
    //  Called when Rupesh approves a staff SSP expense.
    //  Auto-posts matching entry to his cb_ledger.

    /**
     * @param int    $registerId   cb_ssp_register.id
     * @param string $approverName Who approved
     * @param float  $rate         Current SSP rate for USD equivalent
     * @return array {ok, cb_ledger_id, cb_sr} or {ok, skipped}
     */
    public function mergeExpenseToLedger(int $registerId, string $approverName, float $rate = 0): array
    {
        $row = $this->pdo->prepare("SELECT * FROM cb_ssp_register WHERE id = ?");
        $row->execute([$registerId]);
        $reg = $row->fetch(\PDO::FETCH_ASSOC);
        if (!$reg) {
            return ['ok' => false, 'error' => 'Register entry not found'];
        }
        if ((int)$reg['merged_to_cb'] === 1) {
            return ['ok' => true, 'skipped' => 'already_merged'];
        }

        $chainRef = $reg['chain_ref'];
        $now      = date('Y-m-d H:i:s');

        // v4.20.3 SSP IMPREST FIX (SAFETY RULE 16):
        //
        // Before this release, this function posted a fresh OUT to cb_ledger when an
        // SSP field expense was approved. That was a duplicate: the SSP cash had
        // ALREADY left the main cashbook at advance-issue time (CB-XXXX, category
        // "SSP Advance"), and posting another OUT here meant the same shillings were
        // counted twice. Across the visible cashbook window this caused ~3.25M SSP of
        // phantom outflow, which is exactly why physical cash and system cash didn't
        // match.
        //
        // Under the new imprest model, the staff register (cb_ssp_register) carries
        // the per-category breakdown. cb_ledger only sees the advance issue (cash
        // physically leaving the till) and never sees the downstream expense. So
        // this function now just flips the register row to merged and returns,
        // without touching cb_ledger.
        //
        // Existing dedup check below is preserved as defence-in-depth: if an old
        // cb_ledger row for this chain_ref happens to exist (from before the fix),
        // we still return cleanly without creating another.

        // DEDUP: Check cb_ledger for this chain_ref (legacy entries pre-v4.20.3)
        $dupCheck = $this->pdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1");
        $dupCheck->execute([$chainRef]);
        $existingCbId = $dupCheck->fetchColumn();

        // Calculate USD equivalent for register row (audit trail only)
        if ($rate <= 0) {
            $rate = $this->cb->getExchangeRate() ?: 5700;
        }
        $sspAmt   = (int)$reg['ssp_amount'];
        $usdEquiv = round($sspAmt / $rate, 2);

        // Mark register as merged. cb_ledger_id stays 0 / NULL for new entries
        // (imprest-suppressed); legacy entries keep their existing cb_ledger_id.
        if ($existingCbId) {
            $this->pdo->prepare(
                "UPDATE cb_ssp_register SET
                    merged_to_cb=1, merged_at=?, cb_ledger_id=?,
                    status='merged', approved_by=?, approved_at=?,
                    usd_equivalent=?, ssp_rate=?
                 WHERE id=?"
            )->execute([$now, (int)$existingCbId, $approverName, $now, $usdEquiv, $rate, $registerId]);
            return ['ok' => true, 'skipped' => 'already_in_ledger'];
        }

        $this->pdo->prepare(
            "UPDATE cb_ssp_register SET
                merged_to_cb=1, merged_at=?,
                status='merged', approved_by=?, approved_at=?,
                usd_equivalent=?, ssp_rate=?
             WHERE id=?"
        )->execute([$now, $approverName, $now, $usdEquiv, $rate, $registerId]);

        return ['ok' => true, 'skipped' => 'imprest_suppressed', 'chain_ref' => $chainRef];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. RECORD SSP RETURN (staff returns remaining SSP)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param int    $staffId
     * @param string $staffName
     * @param int    $sspAmount   Amount being returned
     * @param float  $rate
     * @param string $cashbook
     * @param string $actorName   Who recorded
     * @return array {ok, chain_ref}
     */
    public function recordReturn(
        int $staffId, string $staffName, int $sspAmount, float $rate,
        string $cashbook, string $actorName
    ): array {
        $now      = date('Y-m-d H:i:s');
        $chainRef = 'SSPRET-' . $this->slugName($staffName) . '-' . time();
        $usdEquiv = $rate > 0 ? round($sspAmount / $rate, 2) : 0;

        // 1. Register OUT on staff side (staff gives back SSP)
        $this->pdo->prepare(
            "INSERT INTO cb_ssp_register
             (staff_id, staff_name, cashbook, date, direction, ssp_amount, usd_equivalent,
              ssp_rate, category, description, status, approved_by, approved_at,
              source_type, chain_ref, merged_to_cb, created_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $staffId, $staffName, $cashbook, date('Y-m-d'), 'out', $sspAmount, $usdEquiv,
            $rate, 'SSP Return', 'Returned remaining SSP to office', 'approved', $actorName, $now,
            'return', $chainRef, 1, $now, $staffName,
        ]);

        // 2. Post Cash IN to Rupesh's cb_ledger
        $desc = 'SSP returned by ' . $staffName . ': ' . number_format($sspAmount, 0) . ' SSP';
        $sr = $this->cb->addEntryRaw([
            'project'           => $cashbook,
            'date'              => date('Y-m-d'),
            'direction'         => 'in',
            'amount'            => $usdEquiv,
            'currency'          => 'SSP',
            'ssp_amount'        => $sspAmount,
            'ssp_rate'          => $rate,
            'category'          => 'SSP Return',
            'category_raw'      => 'SSP Return',
            'person'            => $staffName,
            'description'       => $desc,
            'validation_ref'    => $chainRef,
            'validation_status' => 'done',
            'status'            => 'approved',
            'approved_by'       => $actorName,
            'source'            => 'ssp_return',
        ]);

        return ['ok' => true, 'chain_ref' => $chainRef, 'cb_sr' => $sr];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  5. STAFF SSP POSITION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get SSP balance for a staff member in a date range.
     * @return array {received, spent, returned, balance, pending_count}
     */
    public function getStaffSspPosition(int $staffId, string $dateFrom = '', string $dateTo = ''): array
    {
        $w = ['staff_id = ?'];
        $p = [$staffId];
        if ($dateFrom) { $w[] = 'date >= ?'; $p[] = $dateFrom; }
        if ($dateTo)   { $w[] = 'date <= ?'; $p[] = $dateTo; }
        $wStr = implode(' AND ', $w);

        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN direction='in'  AND source_type='advance_issue' AND status != 'rejected' THEN ssp_amount ELSE 0 END), 0) as received,
                COALESCE(SUM(CASE WHEN direction='out' AND source_type='expense'       AND status IN ('approved','merged') THEN ssp_amount ELSE 0 END), 0) as spent,
                COALESCE(SUM(CASE WHEN direction='out' AND source_type='return'        AND status != 'rejected' THEN ssp_amount ELSE 0 END), 0) as returned,
                COALESCE(SUM(CASE WHEN direction='out' AND source_type='expense'       AND status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count
             FROM cb_ssp_register WHERE $wStr"
        );
        $stmt->execute($p);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $received = (int)($row['received'] ?? 0);
        $spent    = (int)($row['spent'] ?? 0);
        $returned = (int)($row['returned'] ?? 0);
        $pending  = (int)($row['pending_count'] ?? 0);

        return [
            'received'      => $received,
            'spent'         => $spent,
            'returned'      => $returned,
            'balance'       => $received - $spent - $returned,
            'pending_count' => $pending,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  6. DAY RECONCILIATION (all staff)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @return array [{staff_id, staff_name, received, spent, returned, balance, status}]
     */
    public function getDayReconciliation(string $date = ''): array
    {
        if (!$date) $date = date('Y-m-d');

        $stmt = $this->pdo->prepare(
            "SELECT staff_id, staff_name,
                SUM(CASE WHEN direction='in'  AND source_type='advance_issue' THEN ssp_amount ELSE 0 END) as received,
                SUM(CASE WHEN direction='out' AND source_type='expense' AND status IN ('approved','merged') THEN ssp_amount ELSE 0 END) as spent,
                SUM(CASE WHEN direction='out' AND source_type='return' THEN ssp_amount ELSE 0 END) as returned,
                SUM(CASE WHEN direction='out' AND source_type='expense' AND status='pending' THEN 1 ELSE 0 END) as pending
             FROM cb_ssp_register
             WHERE date = ? AND status != 'rejected'
             GROUP BY staff_id, staff_name
             ORDER BY staff_name"
        );
        $stmt->execute([$date]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $received = (int)$r['received'];
            $spent    = (int)$r['spent'];
            $returned = (int)$r['returned'];
            $balance  = $received - $spent - $returned;

            $status = 'no_advance';
            if ($received > 0) {
                $status = ($balance === 0) ? 'balanced' : (($balance > 0) ? 'outstanding' : 'overdrawn');
            }

            $result[] = [
                'staff_id'      => (int)$r['staff_id'],
                'staff_name'    => $r['staff_name'],
                'received'      => $received,
                'spent'         => $spent,
                'returned'      => $returned,
                'balance'       => $balance,
                'pending_count' => (int)$r['pending'],
                'status'        => $status,
            ];
        }
        return $result;
    }

    /**
     * Reconciliation for a date range (week/month view).
     * @return array [{staff_id, staff_name, received, spent, returned, balance, status}]
     */
    public function getRangeReconciliation(string $dateFrom, string $dateTo): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT staff_id, staff_name,
                SUM(CASE WHEN direction='in'  AND source_type='advance_issue' THEN ssp_amount ELSE 0 END) as received,
                SUM(CASE WHEN direction='out' AND source_type='expense' AND status IN ('approved','merged') THEN ssp_amount ELSE 0 END) as spent,
                SUM(CASE WHEN direction='out' AND source_type='return' THEN ssp_amount ELSE 0 END) as returned,
                SUM(CASE WHEN direction='out' AND source_type='expense' AND status='pending' THEN 1 ELSE 0 END) as pending
             FROM cb_ssp_register
             WHERE date >= ? AND date <= ? AND status != 'rejected'
             GROUP BY staff_id, staff_name
             ORDER BY staff_name"
        );
        $stmt->execute([$dateFrom, $dateTo]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $received = (int)$r['received'];
            $spent    = (int)$r['spent'];
            $returned = (int)$r['returned'];
            $balance  = $received - $spent - $returned;

            $status = 'no_advance';
            if ($received > 0) {
                $status = ($balance === 0) ? 'balanced' : (($balance > 0) ? 'outstanding' : 'overdrawn');
            }

            $result[] = [
                'staff_id'      => (int)$r['staff_id'],
                'staff_name'    => $r['staff_name'],
                'received'      => $received,
                'spent'         => $spent,
                'returned'      => $returned,
                'balance'       => $balance,
                'pending_count' => (int)$r['pending'],
                'status'        => $status,
            ];
        }
        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  7. STAFF SSP LEDGER (drill-down)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get all SSP register entries for a staff member.
     */
    public function getStaffLedger(int $staffId, string $dateFrom = '', string $dateTo = '', int $limit = 100): array
    {
        $w = ['staff_id = ?'];
        $p = [$staffId];
        if ($dateFrom) { $w[] = 'date >= ?'; $p[] = $dateFrom; }
        if ($dateTo)   { $w[] = 'date <= ?'; $p[] = $dateTo; }
        $wStr = implode(' AND ', $w);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM cb_ssp_register WHERE $wStr ORDER BY date DESC, id DESC LIMIT ?"
        );
        $p[] = $limit;
        $stmt->execute($p);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Find register entry by chain_ref.
     */
    public function findByChainRef(string $ref): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cb_ssp_register WHERE chain_ref = ? LIMIT 1");
        $stmt->execute([$ref]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Make a URL-safe slug from staff name (e.g. "BBC", "Diko", "Francis")
     */
    private function slugName(string $name): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9]/', '', $name);
        return strtoupper(substr($slug, 0, 10)) ?: 'STAFF';
    }
}
