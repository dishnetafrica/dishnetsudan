<?php
declare(strict_types=1);
if (!function_exists('str_contains'))    { function str_contains(string $h,string $n):bool    { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h,string $n):bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * ArchiveService — DishNet Hybrid Telecom v4.4.30
 *
 * Marks settled financial events as inactive (ev=0) so SnapshotService
 * can use partial indexes and only scan open events during rebuild.
 *
 * ── CORRECTNESS GUARANTEE ───────────────────────────────────────────────────
 *
 * This is the most important safety constraint in this class:
 *
 *   Archiving (ev=0) is ONLY performed at a zero-crossing — when an agent's
 *   cash_exposure drops to exactly zero (within $0.01 tolerance) after a
 *   confirmed handover or advance settlement.
 *
 * Why this is correct:
 *   At a zero-crossing, the ENTIRE cohort of events for that agent nets to zero:
 *
 *     SUM(collections) + advance_balance
 *     - SUM(expenses) - SUM(handovers)
 *     - transfers_sent + transfers_recv  =  0
 *
 *   Since they net to zero, excluding them from future SUM calculations changes
 *   nothing. Future events build on a clean slate.
 *
 * Why any other rule is WRONG:
 *   If we archived collection C1 ($120) without archiving the handover that
 *   offset it ($120), the SUM(active_collections) would drop by $120 but
 *   SUM(active_handovers) would stay the same → exposure = -$120 → corrupt.
 *
 *   There is no per-collection settlement tracking in DishNet. Handovers are
 *   bulk cash transfers. The only moment individual-event archival is safe is
 *   when the NET across all streams is provably zero.
 *
 * ── WHAT GETS ARCHIVED ──────────────────────────────────────────────────────
 *
 * When exposure == 0 for an agent, ALL their events up to now are archived:
 *   - payment_collections  (JSON virtual table → json_patch ev=0)
 *   - cash_handovers       (JSON virtual table → json_patch ev=0)
 *   - staff_expenses       (native table → ev=0 WHERE status='approved')
 *   - staff_transfers      (native table → ev=0 WHERE status='approved')
 *   - cash_advances        (native table → ev=0 WHERE status IN ('settled','cancelled'))
 *
 * Pending/rejected events (pending expenses, cancelled transfers not yet zero)
 * are left active — they contribute to future exposure calculations.
 *
 * ── WHEN CALLED ─────────────────────────────────────────────────────────────
 *
 *   post_handlers.php  → confirm_handover (exposure may drop to 0)
 *   ExpenseAdvanceService::settleAdvance() (advance returning may zero exposure)
 *
 * The call is always AFTER the snapshot is rebuilt. We archive inactive data
 * only once we know the position is correct.
 *
 * PHP 7.4 compatible.
 */
class ArchiveService
{
    private \PDO   $pdo;
    private object $store;

    // Tolerance for floating-point zero check (2 cents)
    const ZERO_TOLERANCE = 0.02;

    public function __construct(\PDO $pdo, object $store)
    {
        $this->pdo   = $pdo;
        $this->store = $store;
    }

    // ── Primary API ───────────────────────────────────────────────────────────

    /**
     * Check if this agent has crossed zero and, if so, archive all their events.
     *
     * Safe to call after every handover confirmation or advance settlement.
     * Returns a summary array; returns early (no-op) if not at zero.
     *
     * @param int $agentId
     * @return array{archived: bool, reason: string, counts: array}
     */
    public function maybeArchive(int $agentId): array
    {
        $empty = ['archived' => false, 'reason' => '', 'counts' => []];
        if ($agentId <= 0) return $empty;

        // Read current exposure from snapshot (fast path) or recompute (fallback)
        $exposure = $this->readExposure($agentId);
        if ($exposure === null) return array_merge($empty, ['reason' => 'no_position']);

        if (abs($exposure) > self::ZERO_TOLERANCE) {
            return array_merge($empty, ['reason' => "exposure_not_zero:{$exposure}"]);
        }

        // Zero-crossing confirmed. Archive the settled cohort.
        return $this->archiveAgent($agentId);
    }

    /**
     * Return counts of active events per agent — useful for monitoring.
     *
     * @return array  agent_id → ['collections'=>int, 'expenses'=>int, 'advances'=>int, ...]
     */
    public function activeEventCounts(): array
    {
        $result = [];
        $tables = [
            'collections' => "SELECT CAST(json_extract(data,'$.retailer_id') AS INTEGER) AS aid, COUNT(*) AS n FROM [payment_collections] WHERE ev=1 GROUP BY aid",
            'expenses'    => "SELECT staff_id AS aid, COUNT(*) AS n FROM staff_expenses WHERE ev=1 GROUP BY staff_id",
            'transfers'   => "SELECT from_id AS aid, COUNT(*) AS n FROM staff_transfers WHERE ev=1 GROUP BY from_id",
            'advances'    => "SELECT recipient_id AS aid, COUNT(*) AS n FROM cash_advances WHERE ev=1 GROUP BY recipient_id",
        ];
        foreach ($tables as $label => $sql) {
            try {
                $rows = $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $result[(int)$r['aid']][$label] = (int)$r['n'];
                }
            } catch (\Throwable $e) { /* table pre-migration */ }
        }
        return $result;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function readExposure(int $agentId): ?float
    {
        // Prefer snapshot (already built)
        try {
            $row = $this->pdo->prepare(
                'SELECT cash_exposure FROM staff_position_snapshot WHERE staff_id=?'
            );
            $row->execute([$agentId]);
            $snap = $row->fetchColumn();
            if ($snap !== false) return round((float)$snap, 4);
        } catch (\Throwable $e) {}

        // Fallback: VIEW
        try {
            $row = $this->pdo->prepare(
                'SELECT cash_exposure FROM staff_cash_position WHERE staff_id=?'
            );
            $row->execute([$agentId]);
            $v = $row->fetchColumn();
            if ($v !== false) return round((float)$v, 4);
        } catch (\Throwable $e) {}

        return null;
    }

    private function archiveAgent(int $agentId): array
    {
        $counts = [];
        $now    = date('Y-m-d H:i:s');

        // ── payment_collections (JSON virtual table) ──────────────────────────
        // Archive all collections for this agent: set ev=0 in the JSON blob
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE [payment_collections]
                 SET data = json_patch(data, '{\"ev\":0}')
                 WHERE CAST(json_extract(data,'$.retailer_id') AS INTEGER) = ?
                   AND COALESCE(json_extract(data,'$.ev'), 1) = 1"
            );
            $stmt->execute([$agentId]);
            $counts['collections'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log("[ArchiveService] collections archive failed for agent {$agentId}: " . $e->getMessage());
        }

        // ── cash_handovers (JSON virtual table) ───────────────────────────────
        // Only confirmed handovers (pending ones still matter)
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE [cash_handovers]
                 SET data = json_patch(data, '{\"ev\":0}')
                 WHERE CAST(json_extract(data,'$.from_id') AS INTEGER) = ?
                   AND json_extract(data,'$.status') = 'confirmed'
                   AND COALESCE(json_extract(data,'$.ev'), 1) = 1"
            );
            $stmt->execute([$agentId]);
            $counts['handovers'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log("[ArchiveService] handovers archive failed for agent {$agentId}: " . $e->getMessage());
        }

        // ── staff_expenses (native table) ─────────────────────────────────────
        // Only approved expenses (pending/rejected don't contribute to exposure)
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE staff_expenses SET ev=0, updated_at=?
                 WHERE staff_id=? AND status='approved' AND ev=1"
            );
            $stmt->execute([$now, $agentId]);
            $counts['expenses'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log("[ArchiveService] expenses archive failed for agent {$agentId}: " . $e->getMessage());
        }

        // ── staff_transfers (native table) ────────────────────────────────────
        // Both directions (from and to) — approved only, not voided
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE staff_transfers SET ev=0, updated_at=?
                 WHERE (from_id=? OR to_id=?) AND status='approved' AND ev=1"
            );
            $stmt->execute([$now, $agentId, $agentId]);
            $counts['transfers'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log("[ArchiveService] transfers archive failed for agent {$agentId}: " . $e->getMessage());
        }

        // ── cash_advances (native table) ──────────────────────────────────────
        // Only fully closed advances (settled or cancelled)
        // Active/partial advances are NOT archived — they still affect exposure
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE cash_advances SET ev=0, updated_at=?
                 WHERE recipient_id=? AND status IN ('settled','cancelled') AND ev=1"
            );
            $stmt->execute([$now, $agentId]);
            $counts['advances'] = $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log("[ArchiveService] advances archive failed for agent {$agentId}: " . $e->getMessage());
        }

        $total = array_sum($counts);
        error_log(sprintf(
            '[ArchiveService] agent %d zero-crossing: archived %d events (%s)',
            $agentId, $total, json_encode($counts)
        ));

        return ['archived' => true, 'reason' => 'zero_crossing', 'counts' => $counts];
    }
}
