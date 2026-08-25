<?php
declare(strict_types=1);
if (!function_exists('str_contains'))    { function str_contains(string $h, string $n): bool    { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * StaffLedgerService — DishNet Hybrid Telecom v4.11.3
 *
 * THE single source of truth for every field agent's cash position.
 * Replaces 5 divergent data sources with one SQLite table (staff_ledger).
 *
 * ── DESIGN ──────────────────────────────────────────────────────────────
 *
 * Every cash movement that affects a field agent's position gets ONE row:
 *   direction = 'in'  → cash flowing TO agent (collection, advance, transfer_in)
 *   direction = 'out' → cash flowing FROM agent (expense, handover, transfer_out, advance_return)
 *
 * Balance = SUM(amount WHERE direction='in') - SUM(amount WHERE direction='out')
 *   filtered by: status NOT IN ('voided','cancelled'), currency='USD'
 *
 * ── CATEGORIES ──────────────────────────────────────────────────────────
 *
 *   collection     → agent collected cash from customer         (in)
 *   advance        → agent received advance from company        (in)
 *   advance_return → agent returned unspent advance cash        (out)
 *   expense        → agent spent on field ops                   (out)
 *   handover       → agent handed cash to accountant            (out)
 *   transfer_out   → agent sent cash to another agent           (out)
 *   transfer_in    → agent received cash from another agent     (in)
 *
 * ── IDEMPOTENCY ─────────────────────────────────────────────────────────
 *
 * Every record() call requires an idempotency_key. The UNIQUE index on
 * idempotency_key prevents double-inserts. Callers use:
 *   COL-{collection_json_id}    for collections
 *   ADV-{advance_sqlite_id}     for advances
 *   ADVRET-{advance_id}         for advance returns
 *   EXP-{expense_id}            for expenses (both sources)
 *   FEXP-{json_id}              for legacy field expenses from JSON
 *   HOV-{handover_json_id}      for handovers
 *   TRFOUT-{transfer_id}        for transfer out
 *   TRFIN-{transfer_id}         for transfer in
 *
 * ── USAGE ───────────────────────────────────────────────────────────────
 *
 *   $ledger = new StaffLedgerService($pdo);
 *
 *   // Record a new entry (idempotent)
 *   $ledger->record([
 *       'staff_id'        => 5,
 *       'staff_name'      => 'Diko',
 *       'direction'       => 'in',
 *       'currency'        => 'USD',
 *       'amount'          => 150.00,
 *       'category'        => 'collection',
 *       'source_type'     => 'payment_collections',
 *       'source_id'       => '42',
 *       'idempotency_key' => 'COL-42',
 *       'description'     => 'Payment from customer #123',
 *   ]);
 *
 *   // Get balance
 *   $bal = $ledger->balance(5, 'USD');  // => 150.00
 *
 *   // Get full position breakdown
 *   $pos = $ledger->position(5, 'USD');
 *   // => ['collections'=>150, 'advances'=>0, 'expenses'=>0, 'handovers'=>0, ...]
 *
 *   // Get ledger entries
 *   $entries = $ledger->entries(5, ['currency' => 'USD', 'limit' => 50]);
 *
 * PHP 7.4 compatible.
 */
class StaffLedgerService
{
    /** @var \PDO */
    private $pdo;

    /** @var array Category → direction mapping (for validation) */
    private static $categoryDirection = [
        'collection'        => 'in',
        'advance'           => 'in',
        'advance_return'    => 'out',
        'expense'           => 'out',
        'handover'          => 'out',
        'transfer_out'      => 'out',
        'transfer_in'       => 'in',
        'ssp_transfer_in'   => 'in',   // SSP given to staff (Diko→Bidal)
        'ssp_transfer_out'  => 'out',  // SSP sent from staff (Diko→Bidal, Diko's side)
    ];

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  WRITE: record + void
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Record a cash movement. Idempotent — duplicate idempotency_key is silently ignored.
     *
     * @param array $data Required keys: staff_id, direction, amount, category, source_type, idempotency_key
     * @return int|null Inserted row ID, or null if duplicate (idempotent skip)
     */
    public function record(array $data): ?int
    {
        $staffId = (int)($data['staff_id'] ?? 0);
        if ($staffId <= 0) {
            error_log('[StaffLedger] record() called with invalid staff_id: ' . json_encode($data));
            return null;
        }

        $direction = $data['direction'] ?? '';
        if (!in_array($direction, ['in', 'out'], true)) {
            error_log('[StaffLedger] record() invalid direction: ' . $direction);
            return null;
        }

        $amount = round((float)($data['amount'] ?? 0), 2);
        if ($amount < 0) {
            error_log('[StaffLedger] record() negative amount: ' . $amount);
            return null;
        }

        $category = $data['category'] ?? '';
        if (!isset(self::$categoryDirection[$category])) {
            error_log('[StaffLedger] record() invalid category: ' . $category);
            return null;
        }

        // Validate direction matches category
        if (self::$categoryDirection[$category] !== $direction) {
            error_log('[StaffLedger] record() direction mismatch: ' . $category . ' expects ' . self::$categoryDirection[$category] . ', got ' . $direction);
            return null;
        }

        $idempotencyKey = trim($data['idempotency_key'] ?? '');

        $sql = "INSERT INTO staff_ledger
            (staff_id, staff_name, direction, currency, amount, ssp_amount, ssp_rate,
             category, subcategory, description, status,
             source_type, source_id, idempotency_key,
             counterparty_id, counterparty_name,
             crm_payment_id, crm_client_id,
             event_date, created_at, updated_at,
             voided_by, void_reason)
            VALUES (?,?,?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?, ?,?, ?,datetime('now'),datetime('now'),
             '','')";

        // Use INSERT OR IGNORE for idempotency when key is present
        if ($idempotencyKey !== '') {
            $sql = str_replace('INSERT INTO', 'INSERT OR IGNORE INTO', $sql);
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $staffId,
                (string)($data['staff_name'] ?? ''),
                $direction,
                strtoupper($data['currency'] ?? 'USD'),
                $amount,
                round((float)($data['ssp_amount'] ?? 0), 2),
                round((float)($data['ssp_rate'] ?? 0), 4),
                $category,
                (string)($data['subcategory'] ?? ''),
                (string)($data['description'] ?? ''),
                (string)($data['status'] ?? 'active'),
                (string)($data['source_type'] ?? ''),
                (string)($data['source_id'] ?? ''),
                $idempotencyKey,
                (int)($data['counterparty_id'] ?? 0),
                (string)($data['counterparty_name'] ?? ''),
                (int)($data['crm_payment_id'] ?? 0),
                (int)($data['crm_client_id'] ?? 0),
                (string)($data['event_date'] ?? date('Y-m-d')),
            ]);

            $rowCount = $stmt->rowCount();
            if ($rowCount > 0) {
                return (int)$this->pdo->lastInsertId();
            }

            // ── Idempotency skip: row already exists ─────────────────────
            // If the existing row is voided but the incoming status is active,
            // reactivate it. This handles the pattern: approve → void → re-approve.
            // Without this, re-approved expenses are silently lost from the balance.
            if ($idempotencyKey !== '' && ($data['status'] ?? 'active') === 'active') {
                $check = $this->pdo->prepare(
                    "SELECT id, status FROM staff_ledger WHERE idempotency_key = ? LIMIT 1"
                );
                $check->execute([$idempotencyKey]);
                $existing = $check->fetch(\PDO::FETCH_ASSOC);
                if ($existing && $existing['status'] === 'voided') {
                    // Reactivate: restore amount, direction, and status
                    $reactivate = $this->pdo->prepare(
                        "UPDATE staff_ledger SET
                            status = 'active',
                            amount = ?,
                            ssp_amount = ?,
                            description = ?,
                            voided_by = '',
                            voided_at = NULL,
                            void_reason = '',
                            updated_at = datetime('now')
                         WHERE id = ?"
                    );
                    $reactivate->execute([
                        $amount,
                        round((float)($data['ssp_amount'] ?? 0), 2),
                        (string)($data['description'] ?? ''),
                        (int)$existing['id'],
                    ]);
                    error_log("[StaffLedger] Reactivated voided entry key={$idempotencyKey} id={$existing['id']}");
                    return (int)$existing['id'];
                }
            }

            return null; // Genuine idempotent skip — already active
        } catch (\Throwable $e) {
            // Check if it's a UNIQUE constraint violation (idempotency)
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')
                || str_contains($e->getMessage(), 'unique')) {
                return null; // Idempotent skip
            }
            error_log('[StaffLedger] record() error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Record a batch of entries. Uses a transaction for performance.
     * Returns count of actually inserted rows (excludes idempotent skips).
     */
    public function recordBatch(array $entries): int
    {
        $inserted = 0;
        $ownTxn = !$this->pdo->inTransaction();
        if ($ownTxn) $this->pdo->exec('BEGIN');
        try {
            foreach ($entries as $data) {
                $id = $this->record($data);
                if ($id !== null) $inserted++;
            }
            if ($ownTxn) $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($ownTxn) $this->pdo->exec('ROLLBACK');
            throw $e;
        }
        return $inserted;
    }

    /**
     * Void an entry by its idempotency_key.
     * Sets status='voided' so it no longer counts in balance calculations.
     *
     * @return bool true if a row was updated
     */
    public function voidByKey(string $idempotencyKey, string $voidedBy = '', string $reason = ''): bool
    {
        if (trim($idempotencyKey) === '') return false;
        $stmt = $this->pdo->prepare(
            "UPDATE staff_ledger SET
                status = 'voided',
                voided_by = ?,
                voided_at = datetime('now'),
                void_reason = ?,
                updated_at = datetime('now')
             WHERE idempotency_key = ?
               AND status != 'voided'"
        );
        $stmt->execute([$voidedBy, $reason, $idempotencyKey]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Void an entry by source_type + source_id.
     */
    public function voidBySource(string $sourceType, string $sourceId, string $voidedBy = '', string $reason = ''): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE staff_ledger SET
                status = 'voided',
                voided_by = ?,
                voided_at = datetime('now'),
                void_reason = ?,
                updated_at = datetime('now')
             WHERE source_type = ? AND source_id = ?
               AND status != 'voided'"
        );
        $stmt->execute([$voidedBy, $reason, $sourceType, $sourceId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Void all entries matching a CRM payment ID (for payment.delete webhook cascade).
     */
    public function voidByCrmPayment(int $crmPaymentId, string $voidedBy = '', string $reason = ''): int
    {
        if ($crmPaymentId <= 0) return 0;
        $stmt = $this->pdo->prepare(
            "UPDATE staff_ledger SET
                status = 'voided',
                voided_by = ?,
                voided_at = datetime('now'),
                void_reason = ?,
                updated_at = datetime('now')
             WHERE crm_payment_id = ?
               AND status != 'voided'"
        );
        $stmt->execute([$voidedBy, $reason, $crmPaymentId]);
        return $stmt->rowCount();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  READ: balance, position, entries
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Net cash balance for one staff member.
     * THE query that replaces 5 different calculations.
     *
     * @param int    $staffId
     * @param string $currency 'USD' or 'SSP'
     * @return float
     */
    public function balance(int $staffId, string $currency = 'USD'): float
    {
        $currency = strtoupper($currency);
        // SSP rows store the actual SSP figure in ssp_amount, not in amount.
        // amount holds the USD equivalent which must NOT be summed for SSP balance.
        $amtCol = $currency === 'SSP' ? 'ssp_amount' : 'amount';

        $stmt = $this->pdo->prepare(
            "SELECT ROUND(
                COALESCE(SUM(CASE WHEN direction='in'  THEN {$amtCol} ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN direction='out' THEN {$amtCol} ELSE 0 END), 0)
            , 2) AS balance
            FROM staff_ledger
            WHERE staff_id = ?
              AND currency = ?
              AND status NOT IN ('voided','cancelled')"
        );
        $stmt->execute([$staffId, $currency]);
        return (float)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Full position breakdown for one staff member.
     * Returns the same shape as StaffCashPositionService::getPosition()
     * for drop-in compatibility.
     */
    public function position(int $staffId, string $currency = 'USD'): array
    {
        $cur = strtoupper($currency);
        // SSP rows store the real SSP figure in ssp_amount, not amount (which holds USD equiv)
        $a = $cur === 'SSP' ? 'ssp_amount' : 'amount';
        $stmt = $this->pdo->prepare(
            "SELECT
                ROUND(COALESCE(SUM(CASE WHEN category='collection'     AND direction='in'  THEN {$a} ELSE 0 END), 0), 2) AS collections,
                ROUND(COALESCE(SUM(CASE WHEN category='advance'        AND direction='in'  THEN {$a} ELSE 0 END), 0), 2) AS advances,
                ROUND(COALESCE(SUM(CASE WHEN category='advance_return' AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS advance_returns,
                ROUND(COALESCE(SUM(CASE WHEN category='expense'        AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS expenses,
                ROUND(COALESCE(SUM(CASE WHEN category='handover'       AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS handovers,
                ROUND(COALESCE(SUM(CASE WHEN category='transfer_out'   AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS transfers_sent,
                ROUND(COALESCE(SUM(CASE WHEN category='transfer_in'    AND direction='in'  THEN {$a} ELSE 0 END), 0), 2) AS transfers_received,
                ROUND(
                    COALESCE(SUM(CASE WHEN direction='in'  THEN {$a} ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN {$a} ELSE 0 END), 0)
                , 2) AS cash_exposure
            FROM staff_ledger
            WHERE staff_id = ? AND currency = ?
              AND status NOT IN ('voided','cancelled')"
        );
        $stmt->execute([$staffId, $cur]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $row = [
                'collections' => 0, 'advances' => 0, 'advance_returns' => 0,
                'expenses' => 0, 'handovers' => 0,
                'transfers_sent' => 0, 'transfers_received' => 0,
                'cash_exposure' => 0,
            ];
        }

        $exposure = (float)$row['cash_exposure'];
        // Advance balance = advances - advance_returns - expenses (advance-linked portion)
        // For compatibility, report advance_balance as net of advances minus returns
        $advanceBalance = (float)$row['advances'] - (float)$row['advance_returns'];

        return [
            'agent_id'           => $staffId,
            'staff_name'         => $this->getStaffName($staffId),
            'float_balance'      => 0.0,
            'collections'        => (float)$row['collections'],
            'advance_balance'    => round($advanceBalance, 2),
            'expenses'           => (float)$row['expenses'],
            'handovers'          => (float)$row['handovers'],
            'transfers_sent'     => (float)$row['transfers_sent'],
            'transfers_received' => (float)$row['transfers_received'],
            'cash_exposure'      => $exposure,
            'cash_in_hand'       => max(0.0, $exposure),
        ];
    }

    /**
     * All staff positions (for dashboard). Keyed by staff_id.
     */
    public function allPositions(string $currency = 'USD'): array
    {
        $cur = strtoupper($currency);
        // SSP rows store the real SSP figure in ssp_amount, not amount
        $a = $cur === 'SSP' ? 'ssp_amount' : 'amount';
        $stmt = $this->pdo->prepare(
            "SELECT
                staff_id,
                MAX(staff_name) AS staff_name,
                ROUND(COALESCE(SUM(CASE WHEN category='collection'     AND direction='in'  THEN {$a} ELSE 0 END), 0), 2) AS collections,
                ROUND(COALESCE(SUM(CASE WHEN category='advance'        AND direction='in'  THEN {$a} ELSE 0 END), 0), 2) AS advances,
                ROUND(COALESCE(SUM(CASE WHEN category='advance_return' AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS advance_returns,
                ROUND(COALESCE(SUM(CASE WHEN category='expense'        AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS expenses,
                ROUND(COALESCE(SUM(CASE WHEN category='handover'       AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS handovers,
                ROUND(COALESCE(SUM(CASE WHEN category='transfer_out'   AND direction='out' THEN {$a} ELSE 0 END), 0), 2) AS transfers_sent,
                ROUND(COALESCE(SUM(CASE WHEN category='transfer_in'    AND direction='in'  THEN {$a} ELSE 0 END), 0), 2) AS transfers_received,
                ROUND(
                    COALESCE(SUM(CASE WHEN direction='in'  THEN {$a} ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN {$a} ELSE 0 END), 0)
                , 2) AS cash_exposure
            FROM staff_ledger
            WHERE currency = ?
              AND status NOT IN ('voided','cancelled')
            GROUP BY staff_id
            HAVING cash_exposure != 0 OR collections > 0 OR advances > 0"
        );
        $stmt->execute([$cur]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $sid = (int)$row['staff_id'];
            $exposure = (float)$row['cash_exposure'];
            $result[$sid] = [
                'agent_id'           => $sid,
                'staff_name'         => $row['staff_name'],
                'float_balance'      => 0.0,
                'collections'        => (float)$row['collections'],
                'advance_balance'    => round((float)$row['advances'] - (float)$row['advance_returns'], 2),
                'expenses'           => (float)$row['expenses'],
                'handovers'          => (float)$row['handovers'],
                'transfers_sent'     => (float)$row['transfers_sent'],
                'transfers_received' => (float)$row['transfers_received'],
                'cash_exposure'      => $exposure,
                'cash_in_hand'       => max(0.0, $exposure),
            ];
        }
        return $result;
    }

    /**
     * Get ledger entries for one staff member with filters.
     *
     * @param int   $staffId
     * @param array $filters Keys: currency, category, status, from, to, limit, offset
     * @return array
     */
    public function entries(int $staffId, array $filters = []): array
    {
        $where = ['staff_id = ?'];
        $params = [$staffId];

        if (!empty($filters['currency'])) {
            $where[] = 'currency = ?';
            $params[] = strtoupper($filters['currency']);
        }
        if (!empty($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        } else {
            // Default: exclude voided
            $where[] = "status NOT IN ('voided','cancelled')";
        }
        if (!empty($filters['from'])) {
            $where[] = 'event_date >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'event_date <= ?';
            $params[] = $filters['to'];
        }
        if (!empty($filters['source_type'])) {
            $where[] = 'source_type = ?';
            $params[] = $filters['source_type'];
        }

        $limit  = min((int)($filters['limit'] ?? 100), 500);
        $offset = max((int)($filters['offset'] ?? 0), 0);

        $sql = "SELECT * FROM staff_ledger
                WHERE " . implode(' AND ', $where) . "
                ORDER BY created_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Monthly summary for one staff member.
     */
    public function monthlySummary(int $staffId, string $month = '', string $currency = 'USD'): array
    {
        if (!$month) $month = date('Y-m');
        $from = $month . '-01';
        $to   = $month . '-31';
        $cur  = strtoupper($currency);
        $a    = $cur === 'SSP' ? 'ssp_amount' : 'amount';

        $stmt = $this->pdo->prepare(
            "SELECT
                category,
                direction,
                ROUND(SUM({$a}), 2) AS total,
                COUNT(*) AS cnt
            FROM staff_ledger
            WHERE staff_id = ? AND currency = ?
              AND event_date BETWEEN ? AND ?
              AND status NOT IN ('voided','cancelled')
            GROUP BY category, direction"
        );
        $stmt->execute([$staffId, $cur, $from, $to]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $summary = [
            'month'      => $month,
            'currency'   => $cur,
            'total_in'   => 0,
            'total_out'  => 0,
            'net'        => 0,
            'categories' => [],
        ];

        foreach ($rows as $r) {
            $cat = $r['category'];
            $dir = $r['direction'];
            $tot = (float)$r['total'];

            $summary['categories'][$cat] = [
                'direction' => $dir,
                'total'     => $tot,
                'count'     => (int)$r['cnt'],
            ];

            if ($dir === 'in')  $summary['total_in']  += $tot;
            if ($dir === 'out') $summary['total_out'] += $tot;
        }

        $summary['net'] = round($summary['total_in'] - $summary['total_out'], 2);
        return $summary;
    }

    /**
     * Reconciliation: compare ledger balance vs old sources.
     * Returns per-staff comparison for debugging the migration.
     */
    public function reconcileVsOld(array $oldPositions): array
    {
        $ledgerPositions = $this->allPositions('USD');
        $mismatches = [];

        // Check all agents in old positions
        $allIds = array_unique(array_merge(
            array_keys($oldPositions),
            array_keys($ledgerPositions)
        ));

        foreach ($allIds as $sid) {
            $oldExp = (float)($oldPositions[$sid]['cash_exposure'] ?? 0);
            $newExp = (float)($ledgerPositions[$sid]['cash_exposure'] ?? 0);
            $delta  = round(abs($oldExp - $newExp), 2);

            if ($delta > 0.02) {
                $mismatches[] = [
                    'staff_id'     => $sid,
                    'staff_name'   => $oldPositions[$sid]['staff_name'] ?? $ledgerPositions[$sid]['staff_name'] ?? '',
                    'old_exposure' => $oldExp,
                    'new_exposure' => $newExp,
                    'delta'        => $delta,
                    'old_detail'   => $oldPositions[$sid] ?? null,
                    'new_detail'   => $ledgerPositions[$sid] ?? null,
                ];
            }
        }

        usort($mismatches, function ($a, $b) {
            return $b['delta'] <=> $a['delta'];
        });

        return $mismatches;
    }

    /**
     * Count entries by source_type (for backfill verification).
     */
    public function countBySource(): array
    {
        $rows = $this->pdo->query(
            "SELECT source_type, status, COUNT(*) AS cnt, ROUND(SUM(amount), 2) AS total
             FROM staff_ledger
             GROUP BY source_type, status
             ORDER BY source_type, status"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $key = $r['source_type'] . ':' . $r['status'];
            $result[$key] = ['count' => (int)$r['cnt'], 'total' => (float)$r['total']];
        }
        return $result;
    }

    /**
     * Total rows in the ledger.
     */
    public function totalRows(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM staff_ledger')->fetchColumn();
    }

    // ══════════════════════════════════════════════════════════════════════
    //  INTERNAL
    // ══════════════════════════════════════════════════════════════════════

    private function getStaffName(int $staffId): string
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT staff_name FROM staff_ledger WHERE staff_id = ? AND staff_name != '' LIMIT 1"
            );
            $stmt->execute([$staffId]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Safety net: ensure table exists (migration 045 should have created it).
     */
    private function ensureTable(): void
    {
        try {
            $this->pdo->query('SELECT 1 FROM staff_ledger LIMIT 1');
        } catch (\Throwable $e) {
            // Table doesn't exist yet — migration hasn't run
            // Create a minimal version so service doesn't crash
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS staff_ledger (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    staff_id        INTEGER NOT NULL,
                    staff_name      TEXT NOT NULL DEFAULT '',
                    direction       TEXT NOT NULL CHECK(direction IN ('in','out')),
                    currency        TEXT NOT NULL DEFAULT 'USD',
                    amount          REAL NOT NULL CHECK(amount >= 0),
                    ssp_amount      REAL NOT NULL DEFAULT 0,
                    ssp_rate        REAL NOT NULL DEFAULT 0,
                    category        TEXT NOT NULL,
                    subcategory     TEXT NOT NULL DEFAULT '',
                    description     TEXT NOT NULL DEFAULT '',
                    status          TEXT NOT NULL DEFAULT 'active',
                    voided_by       TEXT NOT NULL DEFAULT '',
                    voided_at       TEXT,
                    void_reason     TEXT NOT NULL DEFAULT '',
                    source_type     TEXT NOT NULL,
                    source_id       TEXT NOT NULL DEFAULT '',
                    idempotency_key TEXT NOT NULL DEFAULT '',
                    counterparty_id   INTEGER NOT NULL DEFAULT 0,
                    counterparty_name TEXT NOT NULL DEFAULT '',
                    crm_payment_id  INTEGER NOT NULL DEFAULT 0,
                    crm_client_id   INTEGER NOT NULL DEFAULT 0,
                    event_date      TEXT NOT NULL DEFAULT '',
                    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
                    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
                )"
            );
            $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_sl_idempotency ON staff_ledger(idempotency_key) WHERE idempotency_key != ''");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_sl_staff_currency ON staff_ledger(staff_id, currency, status)");
        }
    }
}
