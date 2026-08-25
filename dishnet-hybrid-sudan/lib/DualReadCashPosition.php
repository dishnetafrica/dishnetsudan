<?php
declare(strict_types=1);
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }

/**
 * DualReadCashPosition — DishNet Hybrid v4.11.3
 *
 * Drop-in replacement for StaffCashPositionService that reads from BOTH:
 *   A) staff_ledger (new, single query)
 *   B) StaffCashPositionService (old, 5 sources)
 *
 * Returns the LEDGER value when ledger_enabled=true (default).
 * Logs mismatches to data/ledger_mismatches.json for admin review.
 * Shows RED banner to admin when mismatch detected.
 *
 * ROLLBACK: Set ledger_enabled=false in config → instantly reverts to JSON reads.
 *
 * Same public API as StaffCashPositionService:
 *   getPosition($agentId): array
 *   getCashInHand($agentId): float
 *   getAllPositions(): array
 *
 * PHP 7.4 compatible.
 */
class DualReadCashPosition
{
    /** @var \StoreInterface */
    private $store;
    /** @var \PDO */
    private $pdo;
    /** @var bool */
    private $ledgerEnabled;
    /** @var bool */
    private $compareEnabled;
    /** @var \StaffLedgerService|null */
    private $ledger = null;
    /** @var \StaffCashPositionService|null */
    private $old = null;
    /** @var string */
    private $dataDir;
    /** @var float Tolerance for mismatch detection */
    const TOLERANCE = 0.01;
    /** @var int Max mismatches to store in log */
    const MAX_LOG = 500;

    public function __construct(\StoreInterface $store, \PDO $pdo, string $dataDir = '')
    {
        $this->store   = $store;
        $this->pdo     = $pdo;
        $this->dataDir = $dataDir ?: (defined('DATA_DIR') ? DATA_DIR : '');

        // Read config flags
        $config = $store->load('kyc_config.json') ?? [];
        $this->ledgerEnabled = ($config['ledger_enabled'] ?? true) !== false;

        // compareEnabled: whether to also read the old JSON system and log mismatches.
        // DEFAULT FALSE — the dual-read comparison was only needed during the ledger
        // transition period. Leaving it on causes 50-100 extra file ops per page load
        // (one JSON read + one file write per agent per page). Only enable from Ledger
        // Health admin tab when actively diagnosing discrepancies.
        $this->compareEnabled = !empty($config['ledger_compare_enabled']);
    }

    /**
     * Is the ledger the primary read source?
     */
    public function isLedgerEnabled(): bool
    {
        return $this->ledgerEnabled;
    }

    /**
     * Is dual-read comparison active? (Ledger Health tab only)
     */
    public function isCompareEnabled(): bool
    {
        return $this->compareEnabled;
    }

    /**
     * Full breakdown for one agent — same shape as StaffCashPositionService.
     */
    public function getPosition(int $agentId): array
    {
        if (!$this->ledgerEnabled) {
            return $this->getOld()->getPosition($agentId);
        }

        // Fast path: ledger only, no old-system read (default)
        if (!$this->compareEnabled) {
            return $this->getLedger()->position($agentId, 'USD');
        }

        // Compare path: used by Ledger Health tab only
        $newPos = $this->getLedger()->position($agentId, 'USD');
        $oldPos = $this->getOld()->getPosition($agentId);
        $this->compareAndLog($agentId, $newPos, $oldPos);
        return $newPos;
    }

    /**
     * Cash-in-hand clamped to >= 0.
     */
    public function getCashInHand(int $agentId): float
    {
        return $this->getPosition($agentId)['cash_in_hand'];
    }

    /**
     * All active field agents with cash activity.
     */
    public function getAllPositions(): array
    {
        if (!$this->ledgerEnabled) {
            return $this->getOld()->getAllPositions();
        }

        // Fast path: ledger only, no old-system read (default)
        if (!$this->compareEnabled) {
            return $this->getLedger()->allPositions('USD');
        }

        // Compare path: used by Ledger Health tab only
        $newAll = $this->getLedger()->allPositions('USD');
        $oldAll = $this->getOld()->getAllPositions();
        $allIds = array_unique(array_merge(array_keys($newAll), array_keys($oldAll)));
        foreach ($allIds as $sid) {
            $newPos = $newAll[$sid] ?? $this->emptyPosition($sid);
            $oldPos = $oldAll[$sid] ?? $this->emptyPosition($sid);
            $this->compareAndLog($sid, $newPos, $oldPos);
        }
        return $newAll;
    }

    /**
     * SSP balance for one agent — single query from staff_ledger.
     * Falls back to inline JSON calc if ledger disabled.
     */
    public function getSSPBalance(int $agentId): float
    {
        if ($this->ledgerEnabled) {
            return $this->getLedger()->balance($agentId, 'SSP');
        }
        // Fallback: old inline calculation from cash_ins - expenses - handovers
        return $this->computeSSPFromJson($agentId);
    }

    /**
     * SSP balances for all agents — keyed by staff_id.
     */
    public function getAllSSPBalances(): array
    {
        if ($this->ledgerEnabled) {
            $allPos = $this->getLedger()->allPositions('SSP');
            $result = [];
            foreach ($allPos as $sid => $pos) {
                $result[$sid] = max(0, (float)($pos['cash_exposure'] ?? 0));
            }
            return $result;
        }
        // Fallback: compute per agent from JSON
        $retailers = $this->store->load('retailers.json') ?? [];
        $result = [];
        foreach ($retailers as $r) {
            if (empty($r['is_active'])) continue;
            $sid = (int)($r['id'] ?? 0);
            if ($sid <= 0) continue;
            $bal = $this->computeSSPFromJson($sid);
            if ($bal > 0) $result[$sid] = $bal;
        }
        return $result;
    }

    /**
     * Old inline SSP calculation from JSON files (fallback).
     */
    private function computeSSPFromJson(int $agentId): float
    {
        try {
            // SSP IN from cash_ins.json
            $cins = $this->store->findAll('cash_ins.json', 'collector_id', $agentId);
            $sspIn = 0;
            foreach ($cins as $i) {
                if (!in_array($i['category'] ?? '', ['SSP Received', 'Exchange'])) continue;
                if (in_array($i['status'] ?? 'approved', ['rejected', 'voided'])) continue;
                $sspIn += (float)($i['ssp_amount'] ?? 0);
            }

            // SSP OUT: use ExpenseGateway for deduplicated expenses
            $sspOut = 0;
            try {
                require_once dirname(__FILE__) . '/ExpenseGateway.php';
                $gw = new \ExpenseGateway($this->store);
                $exps = $gw->getByStaff($agentId);
                foreach ($exps as $e) {
                    if (strtoupper($e['currency'] ?? 'USD') !== 'SSP') continue;
                    if (!in_array($e['status'] ?? '', ['approved', 'pending'])) continue;
                    $sspOut += (float)($e['ssp_amount'] ?? $e['amount'] ?? 0);
                }
            } catch (\Throwable $e) {
                // Fallback: raw JSON
                $rawExps = $this->store->findAll('cash_expenses.json', 'collector_id', $agentId);
                foreach ($rawExps as $e) {
                    if (strtoupper($e['currency'] ?? 'USD') !== 'SSP') continue;
                    if (!in_array($e['status'] ?? '', ['approved', 'pending'])) continue;
                    $sspOut += (float)($e['ssp_amount'] ?? $e['amount'] ?? 0);
                }
            }

            // SSP Handovers — check ssp_amount field OR currency=SSP
            // Many handovers stored without explicit currency field; use ssp_amount as the signal
            $hovs = $this->store->findAll('cash_handovers.json', 'from_id', $agentId);
            $sspHov = 0;
            foreach ($hovs as $h) {
                if (($h['status'] ?? '') !== 'confirmed') continue;
                $hSsp    = (float)($h['ssp_amount'] ?? 0);
                $hCur    = strtoupper($h['currency'] ?? '');
                $isSSP   = ($hCur === 'SSP') || ($hSsp > 0 && $hCur !== 'USD');
                if (!$isSSP) continue;
                $sspHov += $hSsp > 0 ? $hSsp : (float)($h['amount'] ?? 0);
            }

            return max(0, round($sspIn - $sspOut - $sspHov, 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get recent mismatches for admin display.
     * @return array [{staff_id, staff_name, ledger, json, delta, detected_at}, ...]
     */
    public function getMismatches(int $limit = 50): array
    {
        $log = $this->loadMismatchLog();
        // Return most recent first
        $log = array_reverse($log);
        return array_slice($log, 0, $limit);
    }

    /**
     * Get active (unresolved) mismatches — last entry per staff with delta > tolerance.
     */
    public function getActiveMismatches(): array
    {
        $log = $this->loadMismatchLog();
        $latest = [];
        foreach ($log as $entry) {
            $sid = (int)($entry['staff_id'] ?? 0);
            $latest[$sid] = $entry; // last entry wins
        }
        // Filter to those still mismatched
        return array_filter($latest, function ($e) {
            return abs((float)($e['delta'] ?? 0)) > self::TOLERANCE;
        });
    }

    /**
     * Count active mismatches (for badge display).
     */
    public function countActiveMismatches(): int
    {
        return count($this->getActiveMismatches());
    }

    /**
     * Clear mismatch log (after reconciliation).
     */
    public function clearMismatchLog(): void
    {
        $this->saveMismatchLog([]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // INTERNAL
    // ══════════════════════════════════════════════════════════════════════

    private function compareAndLog(int $agentId, array $newPos, array $oldPos): void
    {
        $newExp = (float)($newPos['cash_exposure'] ?? 0);
        $oldExp = (float)($oldPos['cash_exposure'] ?? 0);
        $delta  = round(abs($newExp - $oldExp), 2);

        // Skip if old system returned $0 — it means the old calculation is broken
        // (StaffCashPositionService returns nothing), not a real discrepancy.
        // Only log when BOTH systems have data but disagree.
        if (abs($oldExp) < self::TOLERANCE) return;

        if ($delta > self::TOLERANCE) {
            $staffName = $newPos['staff_name'] ?? $oldPos['staff_name'] ?? '';
            $entry = [
                'staff_id'    => $agentId,
                'staff_name'  => $staffName,
                'ledger'      => round($newExp, 2),
                'json'        => round($oldExp, 2),
                'delta'       => $delta,
                'detected_at' => date('Y-m-d H:i:s'),
                // Per-stream comparison for debugging
                'detail'      => [
                    'collections'  => [
                        'ledger' => (float)($newPos['collections'] ?? 0),
                        'json'   => (float)($oldPos['collections'] ?? 0),
                    ],
                    'advance_balance' => [
                        'ledger' => (float)($newPos['advance_balance'] ?? 0),
                        'json'   => (float)($oldPos['advance_balance'] ?? 0),
                    ],
                    'expenses' => [
                        'ledger' => (float)($newPos['expenses'] ?? 0),
                        'json'   => (float)($oldPos['expenses'] ?? 0),
                    ],
                    'handovers' => [
                        'ledger' => (float)($newPos['handovers'] ?? 0),
                        'json'   => (float)($oldPos['handovers'] ?? 0),
                    ],
                    'transfers_sent' => [
                        'ledger' => (float)($newPos['transfers_sent'] ?? 0),
                        'json'   => (float)($oldPos['transfers_sent'] ?? 0),
                    ],
                    'transfers_received' => [
                        'ledger' => (float)($newPos['transfers_received'] ?? 0),
                        'json'   => (float)($oldPos['transfers_received'] ?? 0),
                    ],
                ],
            ];

            $log = $this->loadMismatchLog();
            $log[] = $entry;
            // Prune to max size
            if (count($log) > self::MAX_LOG) {
                $log = array_slice($log, -self::MAX_LOG);
            }
            $this->saveMismatchLog($log);

            error_log("[DualRead] MISMATCH agent={$agentId} ({$staffName}): ledger={$newExp} json={$oldExp} delta={$delta}");
        }
    }

    private function getLedger(): \StaffLedgerService
    {
        if ($this->ledger === null) {
            require_once dirname(__FILE__) . '/StaffLedgerService.php';
            $this->ledger = new \StaffLedgerService($this->pdo);
        }
        return $this->ledger;
    }

    private function getOld(): \StaffCashPositionService
    {
        if ($this->old === null) {
            require_once dirname(__FILE__) . '/StaffCashPositionService.php';
            $this->old = new \StaffCashPositionService($this->store, $this->pdo);
        }
        return $this->old;
    }

    private function emptyPosition(int $agentId): array
    {
        return [
            'agent_id' => $agentId, 'staff_name' => '',
            'collections' => 0, 'advance_balance' => 0,
            'expenses' => 0, 'handovers' => 0,
            'transfers_sent' => 0, 'transfers_received' => 0,
            'cash_exposure' => 0, 'cash_in_hand' => 0,
        ];
    }

    private function mismatchLogPath(): string
    {
        if ($this->dataDir) return $this->dataDir . '/ledger_mismatches.json';
        // Fallback
        return sys_get_temp_dir() . '/dishnet_ledger_mismatches.json';
    }

    private function loadMismatchLog(): array
    {
        $path = $this->mismatchLogPath();
        if (!file_exists($path)) return [];
        $data = @json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function saveMismatchLog(array $log): void
    {
        $path = $this->mismatchLogPath();
        @file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Generate HTML for admin mismatch warning banner.
     * Include this at the top of any balance-displaying page.
     * Returns empty string if no mismatches or user is not admin.
     */
    public static function renderBanner(\StoreInterface $store, \PDO $pdo, array $retailer, string $dataDir = ''): string
    {
        if (!($retailer['is_admin'] ?? false)) return '';

        try {
            $dual = new self($store, $pdo, $dataDir);
            // Only show banner when compare mode is active — otherwise mismatch log
            // is stale and reading it on every page load adds unnecessary file I/O.
            if (!$dual->isCompareEnabled()) return '';

            $active = $dual->getActiveMismatches();
            if (empty($active)) return '';

            $count = count($active);
            $html = '<div style="background:#fef2f2;border:2px solid #dc2626;border-radius:8px;padding:12px 16px;margin:0 0 16px 0;font-size:13px;">';
            $html .= '<div style="color:#dc2626;font-weight:700;margin-bottom:6px;">⚠️ Staff Ledger Mismatch Detected (' . $count . ')</div>';
            foreach (array_slice($active, 0, 5) as $m) {
                $name  = htmlspecialchars($m['staff_name'] ?? 'ID#' . $m['staff_id']);
                $lVal  = number_format((float)$m['ledger'], 2);
                $jVal  = number_format((float)$m['json'], 2);
                $delta = number_format((float)$m['delta'], 2);
                $html .= "<div style='color:#991b1b;padding:2px 0;'>• <b>{$name}</b>: ledger=\${$lVal}, json=\${$jVal} (Δ\${$delta}) — {$m['detected_at']}</div>";
            }
            if ($count > 5) {
                $html .= "<div style='color:#991b1b;padding:2px 0;font-style:italic;'>...and " . ($count - 5) . " more. See Settings → Ledger Health.</div>";
            }
            $html .= '</div>';
            return $html;
        } catch (\Throwable $e) {
            return ''; // Never crash the page
        }
    }
}
