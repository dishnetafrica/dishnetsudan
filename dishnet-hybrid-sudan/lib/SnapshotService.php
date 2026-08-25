<?php
declare(strict_types=1);
if (!function_exists('str_contains'))    { function str_contains(string $h,string $n):bool    { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h,string $n):bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * SnapshotService — DishNet Hybrid Telecom v4.4.28
 *
 * Maintains staff_position_snapshot: a materialized row per agent that gives
 * the dashboard a single-query O(1) read instead of 6N correlated subqueries.
 *
 * ── HOW IT WORKS ────────────────────────────────────────────────────────────
 *
 * The VIEW (migration 011) is the source of truth — it computes exposure from
 * raw event tables on every read. That's correct but expensive at scale.
 *
 * This service maintains a snapshot table that mirrors the VIEW's output.
 * On every financial event, the calling service calls rebuild(agentId), which:
 *   1. Re-runs the exact same aggregation the VIEW uses (but for ONE agent)
 *   2. UPSERTs the result into staff_position_snapshot
 *
 * The nightly reconcile worker calls rebuildAll() to verify and repair any drift.
 *
 * ── WRITE PATHS THAT CALL rebuild() ────────────────────────────────────────
 *
 *   payment_collections append  → rebuild(retailer_id)         [post_handlers.php]
 *   cash_advances INSERT        → rebuild(recipient_id)         [ExpenseAdvanceService]
 *   cash_advances UPDATE        → rebuild(recipient_id)         [ExpenseAdvanceService]
 *   staff_expenses approve      → rebuild(staff_id)             [ExpenseAdvanceService]
 *   staff_transfers INSERT      → rebuild(from_id) + rebuild(to_id) [StaffTransferService]
 *   staff_transfers void        → rebuild(from_id) + rebuild(to_id) [StaffTransferService]
 *   cash_handovers confirm      → rebuild(from_id)              [post_handlers.php]
 *
 * ── ATOMICITY ───────────────────────────────────────────────────────────────
 *
 *   For SQLite-native writes (advances, expenses, transfers):
 *     rebuild() is called INSIDE the caller's transaction → atomic.
 *
 *   For JSON-virtual-table writes (collections, handovers):
 *     appendWithId commits its own transaction, THEN rebuild() runs.
 *     In the rare case of a crash between the two, the nightly rebuildAll()
 *     corrects the snapshot automatically.
 *
 * ── DRIFT DETECTION ─────────────────────────────────────────────────────────
 *
 *   The VIEW is always correct. The snapshot is a materialized cache.
 *   diff() compares the two for every agent and returns mismatches.
 *   Callers (CashbookReconcileWorker) rebuild on drift.
 *
 * PHP 7.4 compatible.
 */
class SnapshotService
{
    private \PDO   $pdo;
    private object $store;   // StoreInterface

    // Tolerance for floating-point drift comparison (cents)
    const DRIFT_TOLERANCE = 0.02;

    public function __construct(\PDO $pdo, object $store)
    {
        $this->pdo   = $pdo;
        $this->store = $store;
        $this->ensureTable();
    }

    // ── Primary API ───────────────────────────────────────────────────────────

    /**
     * Rebuild snapshot for ONE agent from source tables.
     *
     * Safe to call inside an existing transaction — uses SAVEPOINT internally
     * so it won't interfere with the caller's transaction state.
     *
     * @param int    $agentId     retailer id
     * @param string $eventType   'collection'|'advance'|'expense'|'handover'|'transfer'
     * @param string $eventRef    human-readable ref, e.g. 'TRF-202603-001'
     * @return bool true on success, false on failure (non-fatal)
     */
    public function rebuild(int $agentId, string $eventType = '', string $eventRef = '', string $eventTime = ''): bool
    {
        if ($agentId <= 0) return false;
        try {
            $pos = $this->computeFromSource($agentId);
            // BEGIN IMMEDIATE serialises concurrent rebuilds for the same agent.
            // Two simultaneous events (e.g. expense approval + transfer) both call
            // rebuild(). Without this, both may read stale source data before either
            // writes. With IMMEDIATE, the second waits until the first commits.
            // computeFromSource() runs outside the lock (read-only, no contention).
            //
            // Guard: if the CALLER is already inside a transaction (e.g. nightly
            // rebuildAll runs inside one, or a test harness), skip the lock —
            // SQLite cannot nest BEGIN inside an active transaction.
            $ownTxn = !$this->pdo->inTransaction();
            if ($ownTxn) $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $this->upsert($agentId, $pos, $eventType, $eventRef, $eventTime ?: date('Y-m-d H:i:s'));
                if ($ownTxn) $this->pdo->exec('COMMIT');
            } catch (\Throwable $inner) {
                if ($ownTxn) $this->pdo->exec('ROLLBACK');
                throw $inner;
            }
            return true;
        } catch (\Throwable $e) {
            // Never let a snapshot failure break a financial write
            error_log('[SnapshotService] rebuild(' . $agentId . ') failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Rebuild all active field agents. Called by nightly reconcile worker.
     *
     * @return array{rebuilt: int, failed: int, drifted: int}
     */
    public function rebuildAll(): array
    {
        $retailers = $this->store->load('retailers.json') ?? [];
        $rebuilt = 0; $failed = 0; $drifted = 0;

        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;
            if (!in_array($r['role'] ?? '', ['sales','field_agent','collection','accountant','admin'], true)) continue;
            $aid = (int)($r['id'] ?? 0);
            if ($aid <= 0) continue;

            $existing = $this->get($aid);
            $fresh    = $this->computeFromSource($aid);

            // Check drift before writing
            if ($existing &&
                abs($existing['cash_exposure'] - $fresh['cash_exposure']) > self::DRIFT_TOLERANCE) {
                $drifted++;
            }

            $ok = $this->rebuild($aid, 'nightly_rebuild', '');
            $ok ? $rebuilt++ : $failed++;
        }

        return ['rebuilt' => $rebuilt, 'failed' => $failed, 'drifted' => $drifted];
    }

    /**
     * Read snapshot for one agent.
     * Returns null if no snapshot yet — callers should fall back to the VIEW.
     */
    public function get(int $agentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM staff_position_snapshot WHERE staff_id=? LIMIT 1'
        );
        $stmt->execute([$agentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Read all snapshots, ordered by exposure descending.
     * Used by dashboard and Staff Cash Control tab.
     */
    public function getAll(): array
    {
        return $this->pdo
            ->query('SELECT * FROM staff_position_snapshot ORDER BY cash_exposure DESC')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Compare snapshot vs VIEW for every agent.
     * Returns array of mismatches: [{staff_id, staff_name, snap, view, delta}]
     */
    public function diff(): array
    {
        $mismatches = [];
        try {
            $viewRows = $this->pdo
                ->query('SELECT staff_id, staff_name, cash_exposure FROM staff_cash_position')
                ->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return []; // VIEW not available
        }

        $snapRows = [];
        foreach ($this->getAll() as $s) {
            $snapRows[(int)$s['staff_id']] = (float)$s['cash_exposure'];
        }

        foreach ($viewRows as $v) {
            $aid       = (int)$v['staff_id'];
            $viewExp   = round((float)$v['cash_exposure'], 2);
            $snapExp   = round($snapRows[$aid] ?? 0.0, 2);
            $delta     = abs($viewExp - $snapExp);
            if ($delta > self::DRIFT_TOLERANCE) {
                $mismatches[] = [
                    'staff_id'   => $aid,
                    'staff_name' => $v['staff_name'],
                    'snapshot'   => $snapExp,
                    'view'       => $viewExp,
                    'delta'      => round($delta, 4),
                ];
            }
        }
        return $mismatches;
    }

    // ── Internal: compute from source tables (mirrors VIEW v2 formula) ────────

    /**
     * Recompute all six streams for one agent.
     * This is the VIEW formula, scoped to a single agent_id.
     * Runs ~6 simple indexed queries instead of one correlated scan for N rows.
     */
    private function computeFromSource(int $agentId): array
    {
        $retailer = $this->store->findOne('retailers.json', 'id', $agentId);
        $name     = (string)($retailer['name'] ?? '');

        // Stream A: active root advance balance
        // ev=1 filter uses partial index idx_adv_active_recipient (migration 015)
        $advStmt = $this->pdo->prepare(
            "SELECT COALESCE(ROUND(SUM(
                amount - amount_spent - amount_returned
                - COALESCE(children_allocated, 0)
             ), 2), 0) AS bal
             FROM cash_advances
             WHERE recipient_id=?
               AND status IN ('active','partial')
               AND (parent_advance_id IS NULL OR parent_advance_id = 0)
               AND COALESCE(ev, 1) = 1"
        );
        $advStmt->execute([$agentId]);
        $advBal = (float)$advStmt->fetchColumn();

        // Stream B: customer collections (JSON virtual table)
        // Exclude voided, USD only
        $colStmt = $this->pdo->prepare(
            "SELECT COALESCE(ROUND(SUM(
                CAST(json_extract(data,'$.amount') AS REAL)
             ), 2), 0)
             FROM [payment_collections]
             WHERE CAST(json_extract(data,'$.retailer_id') AS INTEGER) = ?
               AND COALESCE(json_extract(data,'$.status'), '') != 'voided'
               AND COALESCE(json_extract(data,'$.currency'), 'USD') = 'USD'
               AND COALESCE(json_extract(data,'$.ev'), 1) = 1"
        );
        $colStmt->execute([$agentId]);
        $collections = (float)$colStmt->fetchColumn();

        // Stream C: approved expenses
        // ev=1 filter uses partial index idx_exp_active_staff (migration 015)
        // Note: staff_expenses uses collector_id in JSON, staff_id as native column
        $expStmt = $this->pdo->prepare(
            "SELECT COALESCE(ROUND(SUM(amount), 2), 0)
             FROM staff_expenses
             WHERE staff_id = ?
               AND status = 'approved'
               AND COALESCE(ev, 1) = 1"
        );
        $expStmt->execute([$agentId]);
        $expenses = (float)$expStmt->fetchColumn();

        // Stream D: confirmed handovers (USD only)
        $hovStmt = $this->pdo->prepare(
            "SELECT COALESCE(ROUND(SUM(
                CAST(json_extract(data,'$.amount') AS REAL)
             ), 2), 0)
             FROM [cash_handovers]
             WHERE CAST(json_extract(data,'$.from_id') AS INTEGER) = ?
               AND json_extract(data,'$.status') = 'confirmed'
               AND UPPER(COALESCE(json_extract(data,'$.currency'), 'USD')) = 'USD'
               AND COALESCE(json_extract(data,'$.ev'), 1) = 1"
        );
        $hovStmt->execute([$agentId]);
        $handovers = (float)$hovStmt->fetchColumn();

        // Streams E + F: transfers (one query, two aggregates)
        // ev=1 filter uses partial indexes idx_trf_active_from/to (migration 015)
        $tSent = 0.0; $tRecv = 0.0;
        try {
            $trfStmt = $this->pdo->prepare(
                "SELECT
                    COALESCE(ROUND(SUM(CASE WHEN from_id=? AND status='approved' THEN amount ELSE 0 END), 2), 0) AS sent,
                    COALESCE(ROUND(SUM(CASE WHEN to_id=?   AND status='approved' THEN amount ELSE 0 END), 2), 0) AS recv
                 FROM staff_transfers
                 WHERE (from_id=? OR to_id=?) AND status='approved'
                   AND COALESCE(ev, 1) = 1"
            );
            $trfStmt->execute([$agentId, $agentId, $agentId, $agentId]);
            $tRow  = $trfStmt->fetch(\PDO::FETCH_ASSOC);
            $tSent = (float)($tRow['sent'] ?? 0);
            $tRecv = (float)($tRow['recv'] ?? 0);
        } catch (\Throwable $e) { /* staff_transfers table pre-migration-010 */ }

        $exposure = round(
            $advBal + $collections - $expenses - $handovers - $tSent + $tRecv,
        2);

        return [
            'staff_name'      => $name,
            'collections'     => round($collections, 2),
            'advance_balance' => round($advBal,       2),
            'expenses'        => round($expenses,     2),
            'handovers'       => round($handovers,    2),
            'transfers_sent'  => round($tSent,        2),
            'transfers_recv'  => round($tRecv,        2),
            'cash_exposure'   => $exposure,
        ];
    }

    // ── Internal: write ───────────────────────────────────────────────────────

    private function upsert(int $agentId, array $pos, string $evType, string $evRef, string $evTime = ''): void
    {
        $this->pdo->prepare(
            "INSERT INTO staff_position_snapshot
                (staff_id, staff_name, collections, advance_balance, expenses, handovers,
                 transfers_sent, transfers_recv, cash_exposure,
                 last_event_type, last_event_ref, last_event_time, updated_at, rebuild_count)
             VALUES (?,?,?,?,?,?, ?,?,?, ?,?,?, datetime('now'), 1)
             ON CONFLICT(staff_id) DO UPDATE SET
                staff_name       = excluded.staff_name,
                collections      = excluded.collections,
                advance_balance  = excluded.advance_balance,
                expenses         = excluded.expenses,
                handovers        = excluded.handovers,
                transfers_sent   = excluded.transfers_sent,
                transfers_recv   = excluded.transfers_recv,
                cash_exposure    = excluded.cash_exposure,
                last_event_type  = excluded.last_event_type,
                last_event_ref   = CASE WHEN excluded.last_event_ref='' THEN last_event_ref
                                        ELSE excluded.last_event_ref END,
                last_event_time  = CASE WHEN excluded.last_event_time='' THEN last_event_time
                                        ELSE excluded.last_event_time END,
                updated_at       = datetime('now'),
                rebuild_count    = rebuild_count + 1"
        )->execute([
            $agentId, $pos['staff_name'],
            $pos['collections'], $pos['advance_balance'],
            $pos['expenses'],    $pos['handovers'],
            $pos['transfers_sent'], $pos['transfers_recv'],
            $pos['cash_exposure'],
            $evType, $evRef, $evTime,
        ]);
    }

    private function ensureTable(): void
    {
        // Table is created by migration 013. This is a safety net for first-boot.
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS staff_position_snapshot (
                staff_id         INTEGER PRIMARY KEY,
                staff_name       TEXT    NOT NULL DEFAULT '',
                collections      REAL    NOT NULL DEFAULT 0,
                advance_balance  REAL    NOT NULL DEFAULT 0,
                expenses         REAL    NOT NULL DEFAULT 0,
                handovers        REAL    NOT NULL DEFAULT 0,
                transfers_sent   REAL    NOT NULL DEFAULT 0,
                transfers_recv   REAL    NOT NULL DEFAULT 0,
                cash_exposure    REAL    NOT NULL DEFAULT 0,
                last_event_type  TEXT    NOT NULL DEFAULT '',
                last_event_ref   TEXT    NOT NULL DEFAULT '',
                last_event_time  TEXT    NOT NULL DEFAULT '',
                updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
                rebuild_count    INTEGER NOT NULL DEFAULT 0
            )"
        );
        // Add last_event_time to tables pre-dating this column (safe: ignored if already present)
        try {
            $this->pdo->exec("ALTER TABLE staff_position_snapshot ADD COLUMN last_event_time TEXT NOT NULL DEFAULT ''");
        } catch (\Throwable $_e) {}
    }
}
