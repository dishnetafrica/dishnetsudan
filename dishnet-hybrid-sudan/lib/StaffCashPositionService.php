<?php
declare(strict_types=1);
if (!function_exists('str_contains'))   { function str_contains(string $h, string $n): bool   { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h, string $n): bool{ return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * StaffCashPositionService — DishNet Hybrid Telecom v4.4.24
 *
 * Single source of truth for every field agent's cash exposure.
 * Queries the staff_cash_position SQL VIEW (migration 008) when available,
 * falls back to PHP-side calculation for backward compatibility.
 *
 * Formula (see also: migrations/008_staff_cash_position_view.sql):
 *
 *   cash_exposure =
 *     advance_balance   (active root advances: amount − spent − returned − allocated)
 *     + collections     (all customer payments received by this agent)
 *     − expenses        (daily cash expenses approved, from cash_expenses.json)
 *     − handovers       (cash confirmed handed to Rupesh, from cash_handovers.json)
 *
 * NOTE: advance-linked staff_expenses and advance returns are already deducted
 * inside advance_balance — they are NOT subtracted again here.
 *
 * NOTE: cash_expenses.json = daily agent ops (food, transport) NOT linked to an
 * advance. Separate from staff_expenses (SQLite) which are advance-linked.
 *
 * Usage:
 *   $svc = new StaffCashPositionService($store, $store->getPdo());
 *   $pos = $svc->getPosition($agentId);   // full breakdown for one agent
 *   $cih = $svc->getCashInHand($agentId); // just the cash_exposure number
 *   $all = $svc->getAllPositions();        // all active agents, keyed by id
 *
 * The VIEW returns cash_exposure which may be negative (data mismatch / over-
 * handover). getCashInHand() clamps to 0 for UI display. getPosition() exposes
 * raw_cash so callers can detect anomalies.
 */
class StaffCashPositionService
{
    /**
     * v4.21.109: Personal-pay keyword filter — single source of truth.
     *
     * Cash given to staff as salary, allowance, or bonus is THEIR money,
     * not company operational cash. It must be excluded from every "cash
     * with staff" / "field cash" / "in hand" balance display, otherwise
     * paying someone their salary makes it look like they're carrying
     * company cash they need to hand back.
     *
     * Previously duplicated in:
     *   - lib/StaffLedgerWriter.php (excludes from ledger writes)
     *   - tabs/sales/my_account.php (excludes from staff portal USD hero)
     *
     * Now centralised here so admin and staff views can never drift.
     * StaffLedgerWriter.php and my_account.php both consume this list.
     */
    public const PERSONAL_PAY_KEYWORDS = [
        'salary',
        'transport allowance',
        'food allowance',
        'bonus',
        'employee benefit',
    ];

    private \StoreInterface $store;
    private \PDO            $pdo;
    private bool            $viewAvailable;

    public function __construct(\StoreInterface $store, \PDO $pdo)
    {
        $this->store         = $store;
        $this->pdo           = $pdo;
        // v4.11.3: Always use PHP path — the SQLite VIEW is stale and misses:
        // 1. Voided collections (VIEW counts them, PHP excludes them)
        // 2. staff_expenses SQLite (VIEW only reads cash_expenses JSON)
        // 3. Recent handover confirmations (VIEW lags behind JSON writes)
        $this->viewAvailable = false;
    }

    /**
     * v4.21.109: Returns true if a cash_ins entry is personal pay (salary,
     * allowance, bonus, benefit) and should NOT be counted as field cash.
     *
     * Checks both the description and the cb_ref because some flows put
     * the keyword in one but not the other.
     *
     * @param array $entry cash_ins.json row (or anything with description/cb_ref)
     */
    public static function isPersonalPay(array $entry): bool
    {
        $desc = strtolower((string)($entry['description'] ?? ''));
        $ref  = strtolower((string)($entry['cb_ref']      ?? ''));
        foreach (self::PERSONAL_PAY_KEYWORDS as $kw) {
            if (strpos($desc, $kw) !== false) return true;
            if (strpos($ref,  $kw) !== false) return true;
        }
        return false;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Full breakdown for one agent.
     *
     * @return array{
     *   agent_id: int, staff_name: string,
     *   float_balance: float,
     *   advance_balance: float, collections: float,
     *   expenses: float, handovers: float,
     *   cash_exposure: float, cash_in_hand: float
     * }
     */
    public function getPosition(int $agentId): array
    {
        if ($this->viewAvailable) {
            return $this->fromView($agentId);
        }
        return $this->fromPhp($agentId);
    }

    /**
     * cash_exposure clamped to >= 0. Safe for all UI display.
     */
    public function getCashInHand(int $agentId): float
    {
        return $this->getPosition($agentId)['cash_in_hand'];
    }

    /**
     * All active field agents with any cash activity.
     * Returns array keyed by agent_id.
     */
    public function getAllPositions(): array
    {
        if ($this->viewAvailable) {
            return $this->allFromView();
        }
        return $this->allFromPhp();
    }

    // ── VIEW-based path (primary) ─────────────────────────────────────────────

    private function fromView(int $agentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM staff_cash_position WHERE staff_id = ?'
        );
        $stmt->execute([$agentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->emptyPosition($agentId);
        }

        return $this->normalise($row);
    }

    private function allFromView(): array
    {
        $rows   = $this->pdo->query('SELECT * FROM staff_cash_position')->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $pos                     = $this->normalise($row);
            $result[$pos['agent_id']] = $pos;
        }
        return $result;
    }

    /** Convert a VIEW row to the standard position array. */
    private function normalise(array $row): array
    {
        $exposure = round((float)($row['cash_exposure'] ?? 0), 2);
        return [
            'agent_id'           => (int)($row['staff_id']            ?? 0),
            'staff_name'         => (string)($row['staff_name']        ?? ''),
            'float_balance'      => round((float)($row['float_balance']      ?? 0), 2),
            'advance_balance'    => round((float)($row['advance_balance']    ?? 0), 2),
            'collections'        => round((float)($row['collections']        ?? 0), 2),
            'expenses'           => round((float)($row['expenses']           ?? 0), 2),
            'handovers'          => round((float)($row['handovers']          ?? 0), 2),
            // v4.4.26: transfer streams from VIEW v2 (migration 011)
            'transfers_sent'     => round((float)($row['transfers_sent']     ?? 0), 2),
            'transfers_received' => round((float)($row['transfers_received'] ?? 0), 2),
            'cash_exposure'      => $exposure,
            'cash_in_hand'       => max(0.0, $exposure),
        ];
    }

    // ── PHP fallback path (pre-migration-008 or VIEW creation failure) ────────

    private function fromPhp(int $agentId): array
    {
        $retailer        = $this->store->findOne('retailers.json', 'id', $agentId);
        $advanceBalance  = $this->sumAdvanceBalance($agentId);
        $collections     = $this->sumCollections($agentId);
        $expenses        = $this->sumDailyExpenses($agentId);
        $handovers       = $this->sumHandovers($agentId);
        // v4.4.26: include transfer streams so PHP fallback matches VIEW v2
        [$tSent, $tRecv] = $this->sumTransfers($agentId);
        $exposure        = round(
            $advanceBalance + $collections - $expenses - $handovers
            - $tSent + $tRecv,
        2);

        return [
            'agent_id'           => $agentId,
            'staff_name'         => (string)($retailer['name'] ?? ''),
            'float_balance'      => round((float)($retailer['wallet'] ?? 0), 2),
            'advance_balance'    => $advanceBalance,
            'collections'        => $collections,
            'expenses'           => $expenses,
            'handovers'          => $handovers,
            'transfers_sent'     => $tSent,
            'transfers_received' => $tRecv,
            'cash_exposure'      => $exposure,
            'cash_in_hand'       => max(0.0, $exposure),
        ];
    }

    private function allFromPhp(): array
    {
        $retailers = $this->store->load('retailers.json') ?? [];
        $result    = [];
        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;
            // v4.11.38: Match same roles as fieldStaff in staff_cashbooks.php
            // 'sales_staff' and 'support*' were missing — caused $0 on card for those roles
            if (!in_array($r['role'] ?? '', ['sales', 'sales_staff', 'field_agent', 'collection', 'field_accountant', 'support_leader', 'support'], true)) continue;
            $aid = (int)($r['id'] ?? 0);
            if ($aid <= 0) continue;
            $pos = $this->fromPhp($aid);
            if ($pos['collections'] > 0 || $pos['advance_balance'] > 0) {
                $result[$aid] = $pos;
            }
        }
        return $result;
    }

    private function sumCollections(int $agentId): float
    {
        $rows = $this->store->findAll('payment_collections.json', 'retailer_id', $agentId);
        $valid = array_filter($rows, fn($c) => ($c['status'] ?? '') !== 'voided' && ($c['currency'] ?? 'USD') === 'USD');
        return round((float)array_sum(array_column(array_values($valid), 'amount')), 2);
    }

    private function sumAdvanceBalance(int $agentId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(
                amount - amount_spent - amount_returned - COALESCE(children_allocated, 0)
             ), 0) AS bal
             FROM cash_advances
             WHERE recipient_id = ?
               AND status IN ('active','partial')
               AND (parent_advance_id IS NULL OR parent_advance_id = 0)"
        );
        $stmt->execute([$agentId]);
        return round((float)($stmt->fetchColumn() ?? 0.0), 2);
    }

    private function sumDailyExpenses(int $agentId): float
    {
        // v4.11.38: Read directly from cash_expenses.json — same source as detail view.
        // ExpenseGateway in unified mode reads only staff_expenses SQLite, which may
        // be missing entries that exist only in cash_expenses.json (e.g. commissions,
        // exchanges recorded before migration). This caused card to show $100 more
        // than detail view because approved expenses were silently returning $0.
        //
        // Match detail view filter exactly:
        //   collector_id = agent, status NOT IN (voided, cancelled), currency = USD
        $rows     = $this->store->findAll('cash_expenses.json', 'collector_id', $agentId);
        $excluded = ['voided', 'cancelled', 'rejected'];
        $counted  = array_filter($rows, fn($e) =>
            !in_array($e['status'] ?? '', $excluded, true) &&
            strtoupper($e['currency'] ?? 'USD') === 'USD'
        );
        $jsonTotal = round((float)array_sum(array_column(array_values($counted), 'amount')), 2);

        // Also include advance-linked expenses from SQLite (source != field)
        // to avoid missing expenses that are only in staff_expenses table
        $sqliteTotal = 0.0;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM staff_expenses
                 WHERE staff_id = ? AND status = 'approved'
                 AND (currency = 'USD' OR currency IS NULL OR currency = '')
                 AND (source IS NULL OR source != 'field')"
            );
            $stmt->execute([$agentId]);
            $sqliteTotal = round((float)$stmt->fetchColumn(), 2);
        } catch (\Throwable $e) {}

        return round($jsonTotal + $sqliteTotal, 2);
    }

    private function sumHandovers(int $agentId): float
    {
        // v4.11.38: Match detail view filter exactly.
        // Detail view excludes: voided, cancelled, rejected, reverted.
        // This means pending handovers ARE deducted (staff submitted = counts as out).
        // Previously only 'confirmed' was counted, causing card to show $100 more
        // than detail whenever a pending handover existed.
        $rows      = $this->store->findAll('cash_handovers.json', 'from_id', $agentId);
        $excluded  = ['voided', 'cancelled', 'rejected', 'reverted'];
        $counted   = array_filter($rows, fn($h) =>
            !in_array($h['status'] ?? '', $excluded, true) &&
            ($h['currency'] ?? 'USD') === 'USD'  // USD only — exclude SSP handovers
        );
        return round((float)array_sum(array_column(array_values($counted), 'amount')), 2);
    }

    private function emptyPosition(int $agentId): array
    {
        return [
            'agent_id'           => $agentId,
            'staff_name'         => '',
            'float_balance'      => 0.0,
            'advance_balance'    => 0.0,
            'collections'        => 0.0,
            'expenses'           => 0.0,
            'handovers'          => 0.0,
            'transfers_sent'     => 0.0,
            'transfers_received' => 0.0,
            'cash_exposure'      => 0.0,
            'cash_in_hand'       => 0.0,
        ];
    }

    /**
     * Returns [transfers_sent, transfers_received] for PHP fallback path.
     * Silently returns [0, 0] if staff_transfers table doesn't exist yet.
     */
    private function sumTransfers(int $agentId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    ROUND(SUM(CASE WHEN from_id=? AND status='approved' AND (currency IS NULL OR currency='USD') THEN amount ELSE 0 END), 2) AS sent,
                    ROUND(SUM(CASE WHEN to_id=?   AND status='approved' AND (currency IS NULL OR currency='USD') THEN amount ELSE 0 END), 2) AS recv
                 FROM staff_transfers
                 WHERE (from_id=? OR to_id=?) AND status='approved' AND (currency IS NULL OR currency='USD')"
            );
            $stmt->execute([$agentId, $agentId, $agentId, $agentId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return [
                round((float)($row['sent'] ?? 0), 2),
                round((float)($row['recv'] ?? 0), 2),
            ];
        } catch (\Throwable $e) {
            // staff_transfers table not yet created (pre-migration-010)
            return [0.0, 0.0];
        }
    }

    private function checkViewExists(): bool
    {
        try {
            $row = $this->pdo
                ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='view' AND name='staff_cash_position'")
                ->fetchColumn();
            return (int)$row > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── SSP Balance methods (v4.11.38) ────────────────────────────────────────

    /**
     * SSP balance for one agent — reads directly from JSON (same source as detail view).
     * DualReadCashPosition::getSSPBalance() reads staff_ledger which can be stale.
     */
    public function getSSPBalance(int $agentId): float
    {
        $excluded = ['voided', 'cancelled', 'rejected', 'reverted'];

        // SSP IN: cash_ins.json (SSP Received + Exchange entries)
        // v4.21.109: exclude personal pay (salary/allowance/bonus) — same rule
        // as USD path and as StaffLedgerWriter::onCashIn. Paying SSP salary into
        // cash_ins.json must NOT count as field cash the staff is holding.
        $cins  = $this->store->findAll('cash_ins.json', 'collector_id', $agentId);
        $sspIn = 0.0;
        foreach ($cins as $i) {
            if (!in_array($i['category'] ?? '', ['SSP Received', 'Exchange'], true)) continue;
            if (in_array($i['status'] ?? 'approved', $excluded, true)) continue;
            if (self::isPersonalPay($i)) continue;
            $sspIn += (float)($i['ssp_amount'] ?? 0);
        }

        // SSP OUT: cash_expenses.json (currency=SSP)
        $exps   = $this->store->findAll('cash_expenses.json', 'collector_id', $agentId);
        $sspOut = 0.0;
        foreach ($exps as $e) {
            if (strtoupper($e['currency'] ?? 'USD') !== 'SSP') continue;
            if (in_array($e['status'] ?? '', $excluded, true)) continue;
            $sspOut += (float)($e['ssp_amount'] ?? $e['amount'] ?? 0);
        }

        // SSP OUT: advance-linked expenses from SQLite (source != field to avoid double-count)
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(ssp_amount),0) FROM staff_expenses
                 WHERE staff_id=? AND currency='SSP' AND status='approved'
                 AND (source IS NULL OR source != 'field')"
            );
            $stmt->execute([$agentId]);
            $sspOut += (float)$stmt->fetchColumn();
        } catch (\Throwable $e) {}

        // SSP OUT: handovers in SSP
        $hovs = $this->store->findAll('cash_handovers.json', 'from_id', $agentId);
        foreach ($hovs as $h) {
            if (in_array($h['status'] ?? '', $excluded, true)) continue;
            $hSsp  = (float)($h['ssp_amount'] ?? 0);
            $hCur  = strtoupper($h['currency'] ?? '');
            $isSSP = ($hCur === 'SSP') || ($hSsp > 0 && $hCur !== 'USD');
            if (!$isSSP) continue;
            $sspOut += $hSsp > 0 ? $hSsp : (float)($h['amount'] ?? 0);
        }

        return max(0.0, round($sspIn - $sspOut, 0));
    }

    /**
     * SSP balances for all active staff — keyed by retailer_id.
     */
    public function getAllSSPBalances(): array
    {
        $retailers = $this->store->load('retailers.json') ?? [];
        $result    = [];
        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;
            $aid = (int)($r['id'] ?? 0);
            if ($aid <= 0) continue;
            $bal = $this->getSSPBalance($aid);
            if ($bal > 0) $result[$aid] = $bal;
        }
        return $result;
    }

    // ── USD Balance methods (v4.21.109) ───────────────────────────────────────

    /**
     * USD balance for one agent — operational cash they're holding for the company.
     *
     * Mirrors the staff portal formula (tabs/sales/my_account.php) so admin
     * staff_cashbooks and the staff's own My Cash dashboard NEVER disagree.
     *
     * Formula:
     *   USD IN  = cash_ins.json (category=USD Received, excludes personal pay)
     *           + payment_collections.json (all customer collections)
     *           + cash_advances.json (active/partial root advances, USD)
     *   USD OUT = cash_expenses.json (currency=USD)
     *           + staff_expenses SQLite (currency=USD, source!=field)
     *           + cash_handovers.json (currency=USD)
     *
     * IMPORTANT: this is DIFFERENT from getPosition()->cash_exposure, which
     * answers a narrower question ("collection cash exposure for field agents").
     * cash_exposure intentionally ignores cash_ins.json USD Received, which is
     * why staff like Bidal (support_leader who never collects) showed $0 there
     * while their own portal correctly showed their $1,110 operational USD.
     *
     * Keep using getPosition()/getCashInHand() in Staff Cash Control where
     * collection exposure is the actual metric. Use getUSDBalance() everywhere
     * else that displays "USD with staff" / "USD in hand".
     */
    public function getUSDBalance(int $agentId): float
    {
        $excluded = ['voided', 'cancelled', 'rejected', 'reverted'];

        // USD IN: cash_ins.json USD Received — exclude personal pay
        $cins   = $this->store->findAll('cash_ins.json', 'collector_id', $agentId);
        $usdIn  = 0.0;
        foreach ($cins as $i) {
            if (($i['category'] ?? '') !== 'USD Received') continue;
            if (in_array($i['status'] ?? 'approved', $excluded, true)) continue;
            if (self::isPersonalPay($i)) continue;
            $usdIn += (float)($i['amount'] ?? 0);
        }

        // USD IN: customer payment collections (the field agent collected cash)
        $cols = $this->store->findAll('payment_collections.json', 'retailer_id', $agentId);
        foreach ($cols as $c) {
            if (in_array($c['status'] ?? '', $excluded, true)) continue;
            if (strtoupper($c['currency'] ?? 'USD') !== 'USD') continue;
            $usdIn += (float)($c['amount'] ?? 0);
        }

        // USD IN: active/partial root advances in USD (formal Cash Advance system)
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(
                    amount - amount_spent - amount_returned - COALESCE(children_allocated, 0)
                 ), 0)
                 FROM cash_advances
                 WHERE recipient_id = ?
                   AND status IN ('active','partial')
                   AND (currency = 'USD' OR currency IS NULL OR currency = '')
                   AND (parent_advance_id IS NULL OR parent_advance_id = 0)"
            );
            $stmt->execute([$agentId]);
            $usdIn += (float)$stmt->fetchColumn();
        } catch (\Throwable $e) {}

        // USD OUT: cash_expenses.json USD
        $exps   = $this->store->findAll('cash_expenses.json', 'collector_id', $agentId);
        $usdOut = 0.0;
        foreach ($exps as $e) {
            if (strtoupper($e['currency'] ?? 'USD') !== 'USD') continue;
            if (in_array($e['status'] ?? '', $excluded, true)) continue;
            $usdOut += (float)($e['amount'] ?? 0);
        }

        // USD OUT: advance-linked staff_expenses (source != field to avoid double-count
        // with cash_expenses.json which is the "field" source for the unified gateway)
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM staff_expenses
                 WHERE staff_id=? AND status='approved'
                   AND (currency='USD' OR currency IS NULL OR currency='')
                   AND (source IS NULL OR source != 'field')"
            );
            $stmt->execute([$agentId]);
            $usdOut += (float)$stmt->fetchColumn();
        } catch (\Throwable $e) {}

        // USD OUT: handovers in USD
        $hovs = $this->store->findAll('cash_handovers.json', 'from_id', $agentId);
        foreach ($hovs as $h) {
            if (in_array($h['status'] ?? '', $excluded, true)) continue;
            if (strtoupper($h['currency'] ?? 'USD') !== 'USD') continue;
            $usdOut += (float)($h['amount'] ?? 0);
        }

        return max(0.0, round($usdIn - $usdOut, 2));
    }

    /**
     * USD balances for all active staff — keyed by retailer_id.
     * Only returns rows with a non-zero balance.
     */
    public function getAllUSDBalances(): array
    {
        $retailers = $this->store->load('retailers.json') ?? [];
        $result    = [];
        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;
            $aid = (int)($r['id'] ?? 0);
            if ($aid <= 0) continue;
            $bal = $this->getUSDBalance($aid);
            if ($bal > 0) $result[$aid] = $bal;
        }
        return $result;
    }
}
