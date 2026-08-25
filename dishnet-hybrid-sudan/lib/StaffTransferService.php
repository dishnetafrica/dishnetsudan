<?php
declare(strict_types=1);
if (!function_exists('str_contains'))    { function str_contains(string $h,string $n):bool    { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')){ function str_starts_with(string $h,string $n):bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * StaffTransferService — DishNet Hybrid Telecom v4.4.26
 *
 * Models: Diko collects $200 → physically gives $80 cash to Bidal
 *         → Bidal spends it → Diko hands remaining $120 to Rupesh.
 *
 * Design (confirmed):
 *   Auto-approve  : status = 'approved' immediately on submit
 *   Auto-split    : collections exhausted first, advance covers rest
 *                   stored as from_collections / from_advance labels only (Option A)
 *                   cash_advances table is NOT touched
 *   Phantom-cash guards before every INSERT:
 *     Guard 1 — sender  : cash_exposure >= amount
 *     Guard 2 — receiver: cash_exposure + amount <= carry_limit
 *   Conservation  : transfers are zero-sum — total field cash unchanged
 *   Void          : Rupesh can reverse; blocked if receiver already spent
 *
 * Old-Advance-Shadow fix:
 *   validateExpenseApproval() — call from ExpenseAdvanceService before approving
 *   an expense; blocks approval against a settled advance.
 *
 * PHP 7.4 compatible.
 */
class StaffTransferService
{
    private \PDO   $pdo;
    private object $store;
    private        $_snap = null;

    const PURPOSES = ['field_work','ops','emergency','misc'];

    public function __construct(\PDO $pdo, object $store)
    {
        $this->pdo   = $pdo;
        $this->store = $store;
        $this->ensureTable();
    }

    /** @return SnapshotService */
    private function snap(): object
    {
        if ($this->_snap === null) {
            if (!class_exists('SnapshotService')) {
                require_once __DIR__ . '/SnapshotService.php';
            }
            $this->_snap = new SnapshotService($this->pdo, $this->store);
        }
        return $this->_snap;
    }

    // ── Submit (Diko taps "Give Cash to Colleague") ───────────────────────────
    /**
     * @param array $data  {to_id, amount, purpose?, description?, currency?}
     * @param array $actor Logged-in retailer (Diko)
     * @return array {ok, transfer_no?, from_collections?, from_advance?, source_label?, error?}
     */
    public function submit(array $data, array $actor): array
    {
        $fromId   = (int)($actor['id']   ?? 0);
        $fromName = trim($actor['name']  ?? '');
        $toId     = (int)($data['to_id'] ?? 0);
        $amount   = round((float)($data['amount'] ?? 0), 2);
        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        $purpose  = in_array($data['purpose'] ?? '', self::PURPOSES, true)
                    ? (string)$data['purpose'] : 'field_work';
        $desc     = trim($data['description'] ?? '');

        // Basic validation
        if (!$fromId)          return $this->err('Sender not identified.');
        if (!$toId)            return $this->err('Please select a colleague.');
        if ($fromId === $toId) return $this->err('You cannot give cash to yourself.');
        if ($amount <= 0)      return $this->err('Amount must be greater than zero.');

        $recipient = $this->findActiveAgent($toId);
        if (!$recipient) return $this->err('Colleague not found or inactive.');
        $toName = trim((string)($recipient['name'] ?? ''));

        // Live positions from VIEW
        $sPos = $this->getPosition($fromId);
        $rPos = $this->getPosition($toId);

        $senderExposure   = (float)($sPos['cash_exposure'] ?? 0);
        $receiverExposure = (float)($rPos['cash_exposure'] ?? 0);
        $carryLimit       = $this->carryLimit();

        // Guard 1 — sender must have enough cash in hand
        if ($amount > $senderExposure) {
            return $this->err(sprintf(
                'You only have $%.2f in hand. Cannot give $%.2f to %s.',
                $senderExposure, $amount, $toName
            ));
        }

        // Guard 2 — receiver cannot exceed carry limit
        $receiverAfter = $receiverExposure + $amount;
        if ($receiverAfter > $carryLimit) {
            return $this->err(sprintf(
                'Transfer blocked: %s is already holding $%.2f. '
                . 'Adding $%.2f would bring them to $%.2f, over the $%.2f limit. '
                . 'Ask %s to hand over to Rupesh first.',
                $toName, $receiverExposure, $amount,
                $receiverAfter, $carryLimit, $toName
            ));
        }

        // Auto-split: collections exhausted first, advance covers rest
        $colAvail        = max(0.0,
            (float)($sPos['collections']      ?? 0)
          - (float)($sPos['handovers']         ?? 0)
          - (float)($sPos['transfers_sent']    ?? 0)
        );
        $fromCollections = round(min($amount, $colAvail), 2);
        $fromAdvance     = round($amount - $fromCollections, 2);

        $trfNo = $this->nextNo();

        $sql = "INSERT INTO staff_transfers
            (transfer_no, from_id, from_name, to_id, to_name,
             amount, currency, from_collections, from_advance,
             purpose, description, status,
             sender_exposure_before, receiver_exposure_before, carry_limit_at_time,
             submitted_at)
            VALUES (?,?,?,?,?, ?,?,?,?, ?,?,'approved', ?,?,?, ?)";

        $this->pdo->prepare($sql)->execute([
            $trfNo, $fromId, $fromName, $toId, $toName,
            $amount, $currency, $fromCollections, $fromAdvance,
            $purpose, $desc,
            $senderExposure, $receiverExposure, $carryLimit,
            date('Y-m-d H:i:s'),
        ]);

        $newId = (int)$this->pdo->lastInsertId();

        // Rebuild snapshot for BOTH sides — transfers are zero-sum
        $snap = $this->snap();
        $snap->rebuild($fromId, 'transfer', $trfNo);
        $snap->rebuild($toId,   'transfer', $trfNo);

        // Dual-write: staff_ledger
        require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
        StaffLedgerWriter::onTransferCreated($this->pdo, [
            'id' => $newId, 'transfer_no' => $trfNo,
            'from_id' => $fromId, 'from_name' => $fromName,
            'to_id' => $toId, 'to_name' => $toName,
            'amount' => $amount, 'currency' => $currency,
            'purpose' => $purpose, 'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'ok'               => true,
            'transfer_no'      => $trfNo,
            'id'               => $newId,
            'receiver_name'    => $toName,
            'from_collections' => $fromCollections,
            'from_advance'     => $fromAdvance,
            'source_label'     => $this->sourceLabel($fromCollections, $fromAdvance),
        ];
    }

    // ── Void (Rupesh only) ────────────────────────────────────────────────────
    /**
     * @return array {ok, error?}
     */
    public function void(int $transferId, string $reason, array $reviewer): array
    {
        if (trim($reason) === '') {
            return $this->err('Please provide a reason for voiding this transfer.');
        }

        $trf = $this->find($transferId);
        if (!$trf)                       return $this->err('Transfer not found.');
        if ($trf['status'] === 'voided') return $this->err('Transfer is already voided.');

        // Safety: block void if receiver already spent the cash
        $rPos             = $this->getPosition((int)$trf['to_id']);
        $receiverExposure = (float)($rPos['cash_exposure'] ?? 0);
        if ($receiverExposure < (float)$trf['amount']) {
            return $this->err(sprintf(
                'Cannot void: %s has already spent some of the $%.2f '
                . '(current exposure $%.2f). Void their related expenses first.',
                $trf['to_name'], (float)$trf['amount'], $receiverExposure
            ));
        }

        $this->pdo->prepare(
            "UPDATE staff_transfers
             SET status='voided', voided_by=?, voided_at=?, void_reason=?
             WHERE id=?"
        )->execute([
            (string)($reviewer['name'] ?? ''),
            date('Y-m-d H:i:s'),
            $reason,
            $transferId,
        ]);

        // Rebuild snapshot for BOTH sides — voiding reverses the transfer's effect
        $snap = $this->snap();
        $snap->rebuild((int)$trf['from_id'], 'transfer', $trf['transfer_no'] ?? '');
        $snap->rebuild((int)$trf['to_id'],   'transfer', $trf['transfer_no'] ?? '');

        // Dual-write: staff_ledger
        require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
        StaffLedgerWriter::onTransferVoided($this->pdo, $transferId, $reviewer['name'] ?? '');

        return ['ok' => true];
    }

    // ── Old-Advance-Shadow guard ───────────────────────────────────────────────
    /**
     * Call from ExpenseAdvanceService::approveExpense() before writing.
     * Blocks retroactive approval against a settled advance.
     */
    public function validateExpenseApproval(int $advanceId): array
    {
        if (!$advanceId) return ['ok' => true];
        $stmt = $this->pdo->prepare(
            "SELECT status FROM cash_advances WHERE id=? LIMIT 1"
        );
        $stmt->execute([$advanceId]);
        $adv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$adv) return ['ok' => true];
        if ($adv['status'] === 'settled') {
            return $this->err(
                'Cannot approve: the linked advance is already settled. '
                . 'Reopen the advance first or link this expense to an active advance.'
            );
        }
        return ['ok' => true];
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    /** Live cash position for one agent from VIEW */
    public function getPosition(int $agentId): array
    {
        try {
            require_once dirname(__FILE__) . '/DualReadCashPosition.php';
            $dual = new \DualReadCashPosition($this->store, $this->pdo);
            $pos = $dual->getPosition($agentId);
            $pos['staff_id'] = $agentId; // VIEW-compatible key
            return $pos;
        } catch (\Throwable $e) {
            // Fallback to VIEW
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT * FROM staff_cash_position WHERE staff_id=? LIMIT 1'
                );
                $stmt->execute([$agentId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) return $row;
            } catch (\Exception $e2) { /* VIEW not yet created */ }
        }
        return [
            'staff_id'=>$agentId,'cash_exposure'=>0.0,
            'collections'=>0.0,'advance_balance'=>0.0,
            'expenses'=>0.0,'handovers'=>0.0,
            'transfers_sent'=>0.0,'transfers_received'=>0.0,
        ];
    }

    /** Transfers for one agent (sent + received), recent first */
    public function forAgent(int $agentId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM staff_transfers
             WHERE from_id=? OR to_id=?
             ORDER BY submitted_at DESC LIMIT ?'
        );
        $stmt->execute([$agentId, $agentId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** All transfers today — Rupesh's transfer log tab */
    public function todayAll(): array
    {
        $today = date('Y-m-d');
        return $this->pdo
            ->query("SELECT * FROM staff_transfers
                     WHERE submitted_at >= '{$today} 00:00:00'
                     ORDER BY submitted_at DESC")
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Cash sent today by one agent */
    public function sentToday(int $agentId): float
    {
        $today = date('Y-m-d');
        $stmt  = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM staff_transfers
             WHERE from_id=? AND status='approved'
             AND submitted_at >= '{$today} 00:00:00'"
        );
        $stmt->execute([$agentId]);
        return (float)$stmt->fetchColumn();
    }

    /** All agents' positions — for Staff Cash Control tab */
    public function allPositions(): array
    {
        // Use DualReadCashPosition for consistent ledger-based reads
        try {
            require_once dirname(__FILE__) . '/DualReadCashPosition.php';
            $dual = new \DualReadCashPosition($this->store, $this->pdo);
            $all = $dual->getAllPositions();
            // Map to VIEW-compatible shape (staff_id instead of agent_id)
            $result = [];
            foreach ($all as $sid => $pos) {
                $pos['staff_id'] = $sid;
                $result[] = $pos;
            }
            // Sort by cash_exposure descending
            usort($result, function ($a, $b) { return ($b['cash_exposure'] ?? 0) <=> ($a['cash_exposure'] ?? 0); });
            return $result;
        } catch (\Throwable $e) {
            // Fallback to VIEW
            try {
                return $this->pdo
                    ->query('SELECT * FROM staff_cash_position ORDER BY cash_exposure DESC')
                    ->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e2) { return []; }
        }
    }

    /** Active agents for Diko's colleague picker */
    public function getActiveAgents(int $excludeId = 0): array
    {
        $all = $this->store->load('retailers.json') ?? [];
        return array_values(array_filter($all, function ($r) use ($excludeId) {
            if ((int)($r['id'] ?? 0) === $excludeId) return false;
            if (empty($r['is_active']))               return false;
            return in_array($r['role'] ?? '', [
                'sales','field_agent','collection',
                'support','support_leader','accountant','admin',
            ], true);
        }));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM staff_transfers WHERE id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findActiveAgent(int $id): ?array
    {
        $r = $this->store->findOne('retailers.json', 'id', $id);
        return ($r && !empty($r['is_active'])) ? $r : null;
    }

    private function nextNo(): string
    {
        $prefix = 'TRF-' . date('Ym') . '-';
        $stmt   = $this->pdo->prepare(
            "SELECT transfer_no FROM staff_transfers
             WHERE transfer_no LIKE ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq  = $last ? ((int)substr($last, -3) + 1) : 1;
        return $prefix . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    }

    private function carryLimit(): float
    {
        try {
            $row = $this->store->findOne(
                'plugin_settings.json', 'key', 'advance_carry_limit'
            );
            return (float)(($row['value'] ?? null) ?: 100);
        } catch (\Throwable $e) { return 100.0; }
    }

    private function sourceLabel(float $col, float $adv): string
    {
        if ($adv <= 0) return 'collection';
        if ($col <= 0) return 'advance';
        return 'mixed';
    }

    private function err(string $msg): array
    {
        return ['ok' => false, 'error' => $msg];
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS staff_transfers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transfer_no TEXT NOT NULL UNIQUE,
            from_id INTEGER NOT NULL, from_name TEXT NOT NULL DEFAULT '',
            to_id   INTEGER NOT NULL, to_name   TEXT NOT NULL DEFAULT '',
            amount REAL NOT NULL, currency TEXT NOT NULL DEFAULT 'USD',
            from_collections REAL NOT NULL DEFAULT 0,
            from_advance     REAL NOT NULL DEFAULT 0,
            purpose     TEXT NOT NULL DEFAULT 'field_work',
            description TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'approved',
            sender_exposure_before   REAL NOT NULL DEFAULT 0,
            receiver_exposure_before REAL NOT NULL DEFAULT 0,
            carry_limit_at_time      REAL NOT NULL DEFAULT 100,
            submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
            voided_by   TEXT NOT NULL DEFAULT '',
            voided_at   TEXT,
            void_reason TEXT NOT NULL DEFAULT ''
        )");
    }
}
