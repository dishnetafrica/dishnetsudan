<?php
/**
 * ExpenseGateway — Unified read layer for DishNet expenses.
 *
 * Phase 3: Reads from unified `staff_expenses` SQLite table (single query).
 * Falls back to merge approach if migration hasn't run yet.
 *
 * Normalized shape (every row):
 *   id, source ('field'|'advance'), staff_id, staff_name, amount, ssp_amount,
 *   currency, category, description, status, submitted_at, approved_at,
 *   approved_by, receipt_path, is_staff_payment, advance_id, project
 */
class ExpenseGateway
{
    private $store;
    private $pdo;
    private $cache = null;
    private $unified = null; // null=unknown, true/false after check

    public function __construct($store, \PDO $pdo = null)
    {
        $this->store = $store;
        $this->pdo = $pdo ?: $store->getPdo();
    }

    /**
     * Check if unified table has the `source` column (migration 043 applied).
     */
    private function isUnified(): bool
    {
        if ($this->unified !== null) return $this->unified;
        try {
            $this->pdo->query("SELECT source FROM staff_expenses LIMIT 1");
            // Check if any field-source rows exist (migration imported data)
            $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM staff_expenses WHERE source='field'")->fetchColumn();
            $this->unified = $cnt > 0;
        } catch (\Throwable $e) {
            $this->unified = false;
        }
        return $this->unified;
    }

    /**
     * Get all expenses, merged and normalized.
     */
    public function getAll(array $filters = []): array
    {
        if ($this->cache === null) {
            if ($this->isUnified()) {
                $this->cache = $this->loadUnified();
            } else {
                $this->cache = array_merge(
                    $this->loadFieldExpenses(),
                    $this->loadAdvanceExpenses()
                );
                usort($this->cache, function ($a, $b) {
                    return strcmp($b['submitted_at'] ?? '', $a['submitted_at'] ?? '');
                });
            }
        }

        $result = $this->cache;

        // Apply filters
        if (!empty($filters['staff_id'])) {
            $sid = (int)$filters['staff_id'];
            $result = array_filter($result, fn($e) => (int)$e['staff_id'] === $sid);
        }
        if (!empty($filters['status'])) {
            $st = $filters['status'];
            $result = array_filter($result, fn($e) => ($e['status'] ?? '') === $st);
        }
        if (!empty($filters['currency'])) {
            $cur = strtoupper($filters['currency']);
            $result = array_filter($result, fn($e) => strtoupper($e['currency'] ?? 'USD') === $cur);
        }
        if (!empty($filters['from'])) {
            $from = $filters['from'];
            $result = array_filter($result, fn($e) => substr($e['submitted_at'] ?? '', 0, 10) >= $from);
        }
        if (!empty($filters['to'])) {
            $to = $filters['to'];
            $result = array_filter($result, fn($e) => substr($e['submitted_at'] ?? '', 0, 10) <= $to);
        }
        if (isset($filters['exclude_voided']) && $filters['exclude_voided']) {
            $result = array_filter($result, fn($e) => !in_array($e['status'] ?? '', ['voided', 'cancelled']));
        }

        return array_values($result);
    }

    /**
     * Get expenses for one staff member.
     */
    public function getByStaff(int $staffId, bool $excludeVoided = true): array
    {
        return $this->getAll(array_merge(
            ['staff_id' => $staffId],
            $excludeVoided ? ['exclude_voided' => true] : []
        ));
    }

    /**
     * Get pending expenses (for approval count).
     */
    public function getPending(): array
    {
        return $this->getAll(['status' => 'pending']);
    }

    /**
     * Count pending expenses.
     */
    public function countPending(): int
    {
        return count($this->getPending());
    }

    /**
     * Get approved USD total for a staff member (for cash position).
     */
    public function getApprovedUsdTotal(int $staffId): float
    {
        $exps = $this->getAll(['staff_id' => $staffId, 'status' => 'approved', 'currency' => 'USD']);
        return round(array_sum(array_column($exps, 'amount')), 2);
    }

    /**
     * Summary stats.
     */
    public function getSummary(string $month = ''): array
    {
        if (!$month) $month = date('Y-m');
        $all = $this->getAll(['exclude_voided' => true]);
        $monthExps = array_filter($all, fn($e) => substr($e['submitted_at'] ?? '', 0, 7) === $month);

        $totalUsd = 0;
        $totalSsp = 0;
        $pending = 0;
        $approved = 0;
        $byCategory = [];

        foreach ($monthExps as $e) {
            if (($e['currency'] ?? 'USD') === 'SSP') {
                $totalSsp += (float)$e['amount'];
            } else {
                $totalUsd += (float)$e['amount'];
            }
            if (($e['status'] ?? '') === 'pending') $pending++;
            if (($e['status'] ?? '') === 'approved') $approved++;

            $cat = $e['category'] ?? 'Other';
            if (!isset($byCategory[$cat])) $byCategory[$cat] = 0;
            $byCategory[$cat] += (float)$e['amount'];
        }

        return [
            'total_usd'   => round($totalUsd, 2),
            'total_ssp'   => round($totalSsp, 0),
            'pending'     => $pending,
            'approved'    => $approved,
            'total'       => count($monthExps),
            'by_category' => $byCategory,
            'field_count' => count(array_filter($monthExps, fn($e) => $e['source'] === 'field')),
            'advance_count' => count(array_filter($monthExps, fn($e) => $e['source'] === 'advance')),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // PRIVATE: Load and normalize each source
    // ──────────────────────────────────────────────────────────────────

    /**
     * Load from unified staff_expenses table (Phase 3 — single SQL query).
     */
    private function loadUnified(): array
    {
        $rows = $this->pdo->query(
            "SELECT * FROM staff_expenses ORDER BY submitted_at DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $normalized = [];
        foreach ($rows as $e) {
            $cur = strtoupper($e['currency'] ?? 'USD');
            $normalized[] = [
                'id'               => (int)($e['id'] ?? 0),
                'source'           => $e['source'] ?? 'advance',
                'staff_id'         => (int)($e['staff_id'] ?? 0),
                'staff_name'       => $e['staff_name'] ?? '',
                'amount'           => round((float)($e['amount'] ?? 0), 2),
                'ssp_amount'       => round((float)($e['ssp_amount'] ?? 0), 2),
                'currency'         => $cur,
                'category'         => $e['category'] ?? 'Other',
                'description'      => $e['description'] ?? '',
                'status'           => $e['status'] ?? 'pending',
                'submitted_at'     => $e['submitted_at'] ?? $e['created_at'] ?? '',
                'approved_at'      => $e['approved_at'] ?? $e['reviewed_at'] ?? '',
                'approved_by'      => $e['approved_by'] ?? $e['reviewed_by'] ?? '',
                'receipt_path'     => $e['receipt_path'] ?? '',
                'is_staff_payment' => !empty($e['is_staff_payment']),
                'advance_id'       => (int)($e['advance_id'] ?? 0),
                'project'          => $e['project'] ?? 'dishnet',
                'auto_approved'    => !empty($e['auto_approved']),
            ];
        }
        return $normalized;
    }

    private function loadFieldExpenses(): array
    {
        $raw = $this->store->load('cash_expenses.json') ?? [];
        $normalized = [];
        foreach ($raw as $e) {
            $cur = strtoupper($e['currency'] ?? 'USD');
            $normalized[] = [
                'id'               => (int)($e['id'] ?? 0),
                'source'           => 'field',
                'staff_id'         => (int)($e['collector_id'] ?? 0),
                'staff_name'       => $e['collector_name'] ?? $e['staff_name'] ?? '',
                'amount'           => round((float)($cur === 'SSP' ? ($e['ssp_amount'] ?? $e['amount'] ?? 0) : ($e['amount'] ?? 0)), 2),
                'ssp_amount'       => round((float)($e['ssp_amount'] ?? 0), 2),
                'currency'         => $cur,
                'category'         => $e['category'] ?? $e['expense_type'] ?? 'Other',
                'description'      => $e['description'] ?? $e['note'] ?? '',
                'status'           => $e['status'] ?? 'pending',
                'submitted_at'     => $e['submitted_at'] ?? $e['created_at'] ?? '',
                'approved_at'      => $e['approved_at'] ?? '',
                'approved_by'      => $e['approved_by'] ?? '',
                'receipt_path'     => $e['photo'] ?? $e['receipt_path'] ?? '',
                'is_staff_payment' => !empty($e['is_staff_payment']),
                'advance_id'       => 0,
                'project'          => $e['project'] ?? 'dishnet',
                'auto_approved'    => !empty($e['auto_approved']),
            ];
        }
        return $normalized;
    }

    private function loadAdvanceExpenses(): array
    {
        $normalized = [];
        try {
            $rows = $this->pdo->query(
                "SELECT * FROM staff_expenses ORDER BY submitted_at DESC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $e) {
                $cur = strtoupper($e['currency'] ?? 'USD');
                // v4.12.11 FIX: read ssp_amount from its own column, not from 'amount'.
                // Earlier code fell back to amount for SSP rows — but amount may hold
                // USD equivalent or 0 while ssp_amount holds the real SSP figure.
                // This caused Aida's EXP-47..EXP-58 (total 114k SSP) to appear with
                // ssp_amount=0 and amount=0 in the Field Register CSV, effectively
                // invisible to her.
                $sspAmt = round((float)($e['ssp_amount'] ?? 0), 2);
                $usdAmt = round((float)($e['amount'] ?? 0), 2);
                // If SSP currency but ssp_amount column is empty, fall back to amount
                // (covers legacy rows written before migration 043 normalized this).
                if ($cur === 'SSP' && $sspAmt <= 0 && $usdAmt > 0) $sspAmt = $usdAmt;

                $normalized[] = [
                    'id'               => (int)($e['id'] ?? 0),
                    'source'           => 'advance',
                    'staff_id'         => (int)($e['staff_id'] ?? 0),
                    'staff_name'       => $e['staff_name'] ?? '',
                    'amount'           => $usdAmt,
                    'ssp_amount'       => $sspAmt,
                    'currency'         => $cur,
                    'category'         => $e['category'] ?? 'Advance Expense',
                    'description'      => $e['description'] ?? '',
                    'status'           => $e['status'] ?? 'pending',
                    'submitted_at'     => $e['submitted_at'] ?? $e['created_at'] ?? '',
                    'approved_at'      => $e['approved_at'] ?? '',
                    'approved_by'      => $e['approved_by'] ?? '',
                    'receipt_path'     => $e['receipt_path'] ?? '',
                    'is_staff_payment' => false,
                    'advance_id'       => (int)($e['advance_id'] ?? 0),
                    'project'          => 'dishnet',
                    'auto_approved'    => false,
                ];
            }
        } catch (\Throwable $e) {
            // staff_expenses table may not exist yet
        }
        return $normalized;
    }
}
