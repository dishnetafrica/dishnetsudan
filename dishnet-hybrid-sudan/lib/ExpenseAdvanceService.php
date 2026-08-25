<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

require_once __DIR__ . '/CashbookService.php';

/**
 * ExpenseAdvanceService — Field Expense & Cash Advance System
 * DishNet Hybrid v4.5
 *
 * ── Workflow ────────────────────────────────────────────────────────────────
 *
 *   1. Manager (Diko) creates a cash_advance → staff_cashbook_daily opening ↑
 *   2. Staff spends money, uploads receipt via PWA → staff_expenses row (pending)
 *   3. Accountant (Rupesh) reviews → approves or rejects
 *   4. On approval → cb_ledger entry (Travel & Field / OUT) posted immediately
 *   5. Nightly CashbookReconcileWorker → computes staff_cashbook_daily, flags issues
 *   6. Staff settles advance (returns change) → cash_advance status = settled
 *
 * ── Fraud Detection ─────────────────────────────────────────────────────────
 *
 *   flag_duplicate : same receipt image SHA1 already exists in expense_receipts
 *   flag_overspend : expense amount > remaining advance balance
 *   flag_no_receipt: submitted without photo (zero receipt_hash)
 *
 * ── Cashbook Integration ────────────────────────────────────────────────────
 *
 *   Approved expenses → cb_ledger (direction=out, source='expense_sync',
 *                                  category from CATEGORY_CB_MAP)
 *   Advance issued   → cb_ledger (direction=out, category='Travel & Field',
 *                                  source='advance_issued')
 *   Cash returned    → cb_ledger (direction=in, category='Receipt',
 *                                  source='advance_return')
 *
 * PHP 7.4 compatible. Uses same SqliteStore PDO as CashbookService.
 */
class ExpenseAdvanceService
{
    // ── Constants ────────────────────────────────────────────────────────────

    const PURPOSES = ['fuel', 'parts', 'transport', 'allowance', 'food', 'misc'];
    const CATEGORIES = ['fuel', 'parts', 'transport', 'allowance', 'food', 'other'];

    /** Map expense category → CashbookService CATEGORIES_OUT label */
    const CATEGORY_CB_MAP = [
        'fuel'      => 'Travel & Field',
        'parts'     => 'Local Purchase',
        'transport' => 'Transport Allowance',
        'allowance' => 'Employee Benefit',
        'food'      => 'Food Allowance',
        'other'     => 'Misc Expense',
    ];

    /** Receipt directory relative to $dataDir */
    const RECEIPT_DIR = 'uploads/expense_receipts';

    /** Max receipt file size: 8 MB */
    const MAX_RECEIPT_BYTES = 8388608;

    /** Max advance amount (anti-fraud) */
    const MAX_ADVANCE = 5000.0;

    private \PDO    $pdo;
    private         $store;   // SqliteStore
    private string  $dataDir;
    private CashbookService $cb;
    private         $_snap = null;

    public function __construct($store, string $dataDir)
    {
        $this->store   = $store;
        $this->dataDir = rtrim($dataDir, '/');
        $this->pdo     = $store->getPdo();
        $this->cb      = new CashbookService($store, $dataDir);
        $this->initReceiptDir();
        // Lazy-init SnapshotService (loaded on first use, safe if migration 013 not yet run)
        $this->_snap = null;
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

    private function initReceiptDir(): void
    {
        $dir = $this->dataDir . '/' . self::RECEIPT_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CASH ADVANCES
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Manager creates a cash advance for a staff member.
     *
     * Posts immediately to cb_ledger as a pending disbursement so Rupesh
     * sees it in the cashbook outflow.
     *
     * @param array $data ['recipient_id', 'recipient_name', 'amount', 'currency',
     *                     'purpose', 'description', 'expected_settle_at', 'project']
     * @param array $manager  Logged-in retailer (manager/admin)
     * @return array  ['ok'=>bool, 'id'=>int, 'advance_no'=>string, 'error'=>string]
     */
    public function createAdvance(array $data, array $manager): array
    {
        $amount = round((float)($data['amount'] ?? 0), 2);
        if ($amount <= 0 || $amount > self::MAX_ADVANCE) {
            return ['ok' => false, 'error' => 'Amount must be between $0.01 and $' . number_format(self::MAX_ADVANCE)];
        }

        $recipientId   = (int)($data['recipient_id'] ?? 0);
        $recipientName = trim($data['recipient_name'] ?? '');
        if (!$recipientId || !$recipientName) {
            return ['ok' => false, 'error' => 'Recipient required.'];
        }

        // ── Fraud-prevention gate: block if recipient has overdue advances ────
        // Root advances only — sub-allocations from an existing advance are still
        // permitted so a manager can continue splitting operational cash.
        $parentAdvanceIdPre = isset($data['parent_advance_id']) && (int)$data['parent_advance_id'] > 0
            ? (int)$data['parent_advance_id'] : null;
        if ($parentAdvanceIdPre === null && !($data['bypass_overdue_check'] ?? false)) {
            $overdueChk = $this->pdo->prepare(
                "SELECT COUNT(*) FROM cash_advances
                 WHERE recipient_id=? AND is_overdue=1 AND status IN ('active','partial')
                 AND (parent_advance_id IS NULL OR parent_advance_id=0)"
            );
            $overdueChk->execute([$recipientId]);
            if ((int)$overdueChk->fetchColumn() > 0) {
                return ['ok' => false,
                    'error' => "{$recipientName} has overdue advances that must be settled before a new advance can be issued.",
                    'blocked_reason' => 'overdue_advance'];
            }
        }

        // ── Fraud-prevention gate: block if recipient is over carry limit ─────
        if ($parentAdvanceIdPre === null && !($data['bypass_carry_check'] ?? false)) {
            $rList     = $this->store->load('retailers.json') ?? [];
            $recipient = null;
            foreach ($rList as $r) {
                if ((int)($r['id'] ?? 0) === $recipientId) { $recipient = $r; break; }
            }
            if ($recipient && !empty($recipient['carry_overlimit'])) {
                $inHand = number_format((float)($recipient['cash_in_hand'] ?? 0), 2);
                return ['ok' => false,
                    'error' => "{$recipientName} is holding \${$inHand} in cash, which exceeds the carry limit. They must return or account for existing cash first.",
                    'blocked_reason' => 'carry_overlimit'];
            }
        }

        $purpose = trim($data['purpose'] ?? 'misc');
        if (!in_array($purpose, self::PURPOSES, true)) $purpose = 'misc';

        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        if (!in_array($currency, ['USD', 'SSP'], true)) $currency = 'USD';

        $project = in_array($data['project'] ?? '', ['dishnet', '4g', 'bluecard'], true)
            ? $data['project'] : 'dishnet';

        $now       = date('Y-m-d H:i:s');
        $issuedAt  = $data['issued_at'] ?? $now;
        $settleBy  = trim($data['expected_settle_at'] ?? '');
        $desc      = trim($data['description'] ?? '');

        // ── Hierarchy: determine if this is a root or child advance ──────────
        $parentAdvanceId = isset($data['parent_advance_id']) && (int)$data['parent_advance_id'] > 0
            ? (int)$data['parent_advance_id']
            : null;

        $isChildAdvance = ($parentAdvanceId !== null);
        $parentAdv      = null;
        $rootAdvanceId  = 0; // filled after insert for root; filled from parent for child

        if ($isChildAdvance) {
            // Validate parent exists and is active
            $parentAdv = $this->getAdvanceById($parentAdvanceId);
            if (!$parentAdv) {
                return ['ok' => false, 'error' => 'Parent advance not found.'];
            }
            if (!in_array($parentAdv['status'], ['active', 'partial'], true)) {
                return ['ok' => false, 'error' => 'Parent advance is not active — cannot sub-allocate.'];
            }
            // Validate parent has enough remaining balance
            $parentBalance = $this->computeAdvanceBalance((int)$parentAdv['id']);
            if ($amount > $parentBalance + 0.005) {
                return ['ok' => false, 'error' => sprintf(
                    'Parent advance only has %.2f %s remaining — cannot allocate %.2f.',
                    $parentBalance, $parentAdv['currency'] ?? 'USD', $amount
                )];
            }
            // Currency must match parent
            if ($currency !== ($parentAdv['currency'] ?? 'USD')) {
                return ['ok' => false, 'error' => 'Child advance currency must match parent (' . $parentAdv['currency'] . ').'];
            }
            // Inherit root_advance_id from parent
            $rootAdvanceId = (int)($parentAdv['root_advance_id'] ?? $parentAdvanceId);
            if ($rootAdvanceId === 0) $rootAdvanceId = $parentAdvanceId;
        }

        // Generate advance number: ADV-YYYYMM-NNN
        $advNo = $this->nextAdvanceNo();
        // Prefix child advances so they are visually distinct
        if ($isChildAdvance) {
            $advNo = str_replace('ADV-', 'SUB-', $advNo);
        }

        $this->pdo->prepare("
            INSERT INTO cash_advances
              (advance_no,project,issued_by_id,issued_by_name,
               recipient_id,recipient_name,amount,currency,purpose,description,
               amount_spent,amount_returned,children_allocated,status,
               parent_advance_id,root_advance_id,
               issued_at,expected_settle_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,0,0,0,'active',?,?,?,?,?,?)
        ")->execute([
            $advNo, $project,
            (int)$manager['id'], $manager['name'] ?? 'Manager',
            $recipientId, $recipientName,
            $amount, $currency, $purpose, $desc,
            $parentAdvanceId,   // NULL for root, parent id for child
            $rootAdvanceId,     // 0 for root (updated below), parent's root for child
            $issuedAt, $settleBy ?: null, $now, $now,
        ]);
        $advId = (int)$this->pdo->lastInsertId();

        // ── ATOMIC: all post-insert updates succeed together or roll back ────
        $this->pdo->beginTransaction();
        try {
            if (!$isChildAdvance) {
                // Root advance: root_advance_id = own id (set after insert since we need the id)
                $this->pdo->prepare(
                    "UPDATE cash_advances SET root_advance_id=? WHERE id=?"
                )->execute([$advId, $advId]);
                $rootAdvanceId = $advId;

                // ── RULE: Only root advances post to the company cashbook ───────
                $cbDesc = "Cash advance {$advNo} — {$purpose} — issued to {$recipientName}";
                if ($desc) $cbDesc .= ': ' . $desc;

                $this->cb->addEntryRaw([
                    'project'           => $project,
                    'date'              => substr($issuedAt, 0, 10),
                    'direction'         => 'out',
                    'amount'            => $amount,
                    'currency'          => $currency,
                    'category'          => 'Travel & Field',
                    'category_raw'      => "Advance: {$purpose}",
                    'person'            => $recipientName,
                    'description'       => $cbDesc,
                    'validation_ref'    => $advNo,
                    'validation_status' => 'pending',
                    'status'            => 'approved',
                    'approved_by'       => $manager['name'] ?? 'Manager',
                    'source'            => 'advance_issued',
                    'created_at'        => $now,
                ]);

                $this->auditLog('advance_created', $manager['name'] ?? '',
                    "[ROOT] {$advNo}: \${$amount} to {$recipientName} for {$purpose} → cb_ledger OUT posted", $advId);

            } else {
                // ── RULE: Child advance is internal transfer — NO cashbook entry ─
                // Deduct from parent's children_allocated running total
                $this->pdo->prepare(
                    "UPDATE cash_advances SET children_allocated = children_allocated + ?, updated_at=? WHERE id=?"
                )->execute([$amount, $now, $parentAdvanceId]);

                // Update parent status to 'partial' if it was 'active'
                $this->pdo->prepare("
                    UPDATE cash_advances
                    SET status = CASE
                        WHEN status = 'active' THEN 'partial'
                        ELSE status
                    END,
                    updated_at=?
                    WHERE id=?
                ")->execute([$now, $parentAdvanceId]);

                $this->auditLog('advance_created', $manager['name'] ?? '',
                    "[CHILD of ADV#{$parentAdvanceId}] {$advNo}: \${$amount} to {$recipientName} — internal transfer, NO cashbook entry", $advId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
                return ['ok' => false, 'error' => "Advance number {$advNo} already exists in ledger (duplicate prevented). Try again."];
            }
            throw $e;
        }
        // ── END ATOMIC ───────────────────────────────────────────────────────

        // Rebuild snapshot for the recipient (advance adds to their exposure)
        $this->snap()->rebuild($recipientId, 'advance', $advNo);

        // Dual-write: staff_ledger
        require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
        StaffLedgerWriter::onAdvanceIssued($this->pdo, [
            'id' => $advId, 'advance_no' => $advNo,
            'recipient_id' => $recipientId, 'recipient_name' => $recipientName,
            'amount' => $amount, 'currency' => $currency, 'purpose' => $purpose,
            'issued_by_id' => (int)$manager['id'], 'issued_by_name' => $manager['name'] ?? 'Manager',
            'issued_at' => $issuedAt,
        ]);

        return [
            'ok'               => true,
            'id'               => $advId,
            'advance_no'       => $advNo,
            'is_child'         => $isChildAdvance,
            'parent_advance_id'=> $parentAdvanceId,
            'root_advance_id'  => $rootAdvanceId,
        ];
    }

    /**
     * Compute the true remaining balance of an advance, accounting for:
     *   - expenses charged against it
     *   - cash returned
     *   - child advances allocated out of it
     *
     * This is the ONLY place balance should be computed — use this everywhere.
     */
    public function computeAdvanceBalance(int $advId): float
    {
        $adv = $this->pdo->prepare("SELECT amount, amount_spent, amount_returned, children_allocated FROM cash_advances WHERE id=?");
        $adv->execute([$advId]);
        $row = $adv->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return 0.0;

        return round(
            (float)$row['amount']
            - (float)$row['amount_spent']
            - (float)$row['amount_returned']
            - (float)($row['children_allocated'] ?? 0),
            2
        );
    }

    /**
     * Staff cancels an unclaimed advance (no expenses yet).
     */
    public function cancelAdvance(int $advId, array $actor): array
    {
        $adv = $this->getAdvanceById($advId);
        if (!$adv) return ['ok' => false, 'error' => 'Advance not found.'];
        if (!in_array($adv['status'], ['active', 'partial'], true)) {
            return ['ok' => false, 'error' => 'Only active/partial advances can be cancelled.'];
        }

        $now = date('Y-m-d H:i:s');

        // ── ATOMIC: cancel child + restore parent's children_allocated ────────
        // If this is a child advance, the parent's children_allocated was
        // incremented when this advance was created. Cancellation must reverse
        // that — otherwise the parent's available balance stays artificially low.
        $parentAdvId = (int)($adv['parent_advance_id'] ?? 0);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
                UPDATE cash_advances SET status='cancelled', updated_at=? WHERE id=?
            ")->execute([$now, $advId]);

            if ($parentAdvId > 0) {
                // Restore the allocated amount back to the parent's balance
                $this->pdo->prepare(
                    "UPDATE cash_advances
                     SET children_allocated = MAX(0, children_allocated - ?), updated_at=?
                     WHERE id=?"
                )->execute([(float)$adv['amount'], $now, $parentAdvId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return ['ok' => false, 'error' => 'Cancel failed: ' . $e->getMessage()];
        }

        $this->auditLog('advance_cancelled', $actor['name'] ?? '',
            "{$adv['advance_no']} cancelled" . ($parentAdvId ? " — parent #{$parentAdvId} children_allocated restored" : ''), $advId);

        // Rebuild snapshot for recipient (cancelled advance removes from exposure)
        $this->snap()->rebuild((int)$adv['recipient_id'], 'advance', $adv['advance_no']);

        // Dual-write: staff_ledger
        require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
        StaffLedgerWriter::onAdvanceCancelled($this->pdo, $advId, $actor['name'] ?? '');

        return ['ok' => true];
    }

    /**
     * Manager/accountant settles an advance: record cash returned, close the advance.
     */
    public function settleAdvance(int $advId, float $returnAmount, string $note, array $actor): array
    {
        $adv = $this->getAdvanceById($advId);
        if (!$adv) return ['ok' => false, 'error' => 'Advance not found.'];
        if ($adv['status'] === 'settled') return ['ok' => false, 'error' => 'Already settled.'];

        // Guard: cannot settle parent until all children are settled
        $unsettledChildren = $this->pdo->prepare(
            "SELECT COUNT(*) FROM cash_advances WHERE parent_advance_id=? AND status IN ('active','partial')"
        );
        $unsettledChildren->execute([$advId]);
        $childCount = (int)$unsettledChildren->fetchColumn();
        if ($childCount > 0) {
            return ['ok' => false, 'error' => "Cannot settle: {$childCount} sub-advance(s) still outstanding. Settle children first."];
        }

        $returnAmount = round(max(0.0, $returnAmount), 2);
        // Use correct balance formula including children_allocated
        $balance = $this->computeAdvanceBalance($advId);

        if ($returnAmount > $balance + 0.01) {
            return ['ok' => false, 'error' => sprintf(
                'Return amount $%.2f exceeds remaining balance $%.2f.', $returnAmount, $balance
            )];
        }

        $now = date('Y-m-d H:i:s');

        // ── ATOMIC: settle record + cashbook post must succeed together ──────
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("
                UPDATE cash_advances
                SET amount_returned=amount_returned+?,
                    status='settled', settlement_note=?, settled_at=?, updated_at=?
                WHERE id=?
            ")->execute([$returnAmount, $note, $now, $now, $advId]);

            // Post cash return to main cashbook ONLY for root advances
            $isRoot = empty($adv['parent_advance_id']);

            if ($returnAmount > 0 && $isRoot) {
                $this->cb->addEntryRaw([
                    'project'           => $adv['project'],
                    'date'              => date('Y-m-d'),
                    'direction'         => 'in',
                    'amount'            => $returnAmount,
                    'currency'          => $adv['currency'],
                    'category'          => 'Receipt',
                    'category_raw'      => 'Advance Return',
                    'person'            => $adv['recipient_name'],
                    'description'       => "Advance return — {$adv['advance_no']} — {$adv['recipient_name']}" . ($note ? ': ' . $note : ''),
                    'validation_ref'    => $adv['advance_no'] . '-RET',
                    'validation_status' => 'done',
                    'status'            => 'approved',
                    'approved_by'       => $actor['name'] ?? 'Accountant',
                    'source'            => 'advance_return',
                    'created_at'        => $now,
                ]);
            } elseif ($returnAmount > 0 && !$isRoot) {
                // Child returned cash: reduce parent's children_allocated
                $this->pdo->prepare(
                    "UPDATE cash_advances SET children_allocated = MAX(0, children_allocated - ?), updated_at=? WHERE id=?"
                )->execute([$returnAmount, $now, (int)$adv['parent_advance_id']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
                return ['ok' => false, 'error' => 'Return already recorded for this advance (duplicate prevented). Check advance status.'];
            }
            throw $e;
        }
        // ── END ATOMIC ───────────────────────────────────────────────────────

        $isRoot = empty($adv['parent_advance_id']);
        $this->auditLog('advance_settled', $actor['name'] ?? '',
            "{$adv['advance_no']} settled — return \${$returnAmount}" . ($isRoot ? ' [ROOT→cb_ledger IN]' : ' [CHILD→parent freed]'), $advId);

        // Rebuild snapshot for recipient (settled advance removes from exposure)
        $this->snap()->rebuild((int)$adv['recipient_id'], 'advance', $adv['advance_no']);

        // Dual-write: staff_ledger (advance return)
        if ($returnAmount > 0) {
            require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
            StaffLedgerWriter::onAdvanceReturn(
                $this->pdo, $advId,
                (int)$adv['recipient_id'], $adv['recipient_name'] ?? '',
                $returnAmount, $adv['currency'] ?? 'USD'
            );
        }

        // Zero-crossing check: if exposure now == 0, archive settled events
        try {
            if (!class_exists('ArchiveService')) require_once __DIR__ . '/ArchiveService.php';
            (new \ArchiveService($this->pdo, $this->store))->maybeArchive((int)$adv['recipient_id']);
        } catch (\Throwable $e) { /* non-fatal */ }

        return ['ok' => true, 'return_posted' => ($returnAmount > 0 && $isRoot)];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // EXPENSES
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Staff submits an expense (web form or PWA).
     *
     * @param array       $data  ['advance_id', 'category', 'amount', 'currency',
     *                            'description', 'expense_date', 'offline_uuid']
     * @param array|null  $file  $_FILES['receipt'] — optional but flagged if missing
     * @param array       $staff Logged-in retailer
     * @return array  ['ok'=>bool, 'id'=>int, 'expense_no'=>string, 'fraud_flags'=>array]
     */
    public function submitExpense(array $data, ?array $file, array $staff): array
    {
        $staffId = (int)$staff['id'];
        $amount  = round((float)($data['amount'] ?? 0), 2);
        if ($amount <= 0) return ['ok' => false, 'error' => 'Amount must be > 0.'];

        $category = trim($data['category'] ?? 'other');
        if (!in_array($category, self::CATEGORIES, true)) $category = 'other';

        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        if (!in_array($currency, ['USD', 'SSP'], true)) $currency = 'USD';

        // ── Balance guard: can't spend what you don't have ──────────────
        if ($currency === 'SSP') {
            $sspAmt = round((float)($data['ssp_amount'] ?? $data['amount'] ?? 0), 0);
            if ($sspAmt <= 0) $sspAmt = round($amount, 0);
            // v4.12.15 — use JSON-first StaffCashPositionService (same as the
            // mobile hero card since v4.12.10 and admin Staff Cashbook view).
            // Previously used DualReadCashPosition which reads SQL staff_ledger,
            // which could show -217k for Francis even though his actual JSON-based
            // balance was +60k. Hero and guard must agree.
            try {
                require_once dirname(__FILE__) . '/StaffCashPositionService.php';
                $_bgBal = round((new StaffCashPositionService($this->store, $this->pdo))->getSSPBalance($staffId), 0);
            } catch (\Throwable $_bgErr) {
                // Fallback: raw cash_ins minus ExpenseGateway (deduped)
                $_bgCashIn = array_filter($this->store->load('cash_ins.json') ?: [],
                    fn($i) => (int)($i['collector_id'] ?? 0) === $staffId);
                $_bgIn = round(array_sum(array_column(array_values(array_filter($_bgCashIn,
                    fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange'])
                        && !in_array($i['status'] ?? 'approved', ['rejected','voided']))), 'ssp_amount')), 0);
                $_bgOut = 0;
                try {
                    require_once dirname(__FILE__) . '/ExpenseGateway.php';
                    $gw = new ExpenseGateway($this->store);
                    foreach ($gw->getByStaff($staffId) as $e) {
                        if (strtoupper($e['currency'] ?? 'USD') !== 'SSP') continue;
                        if (!in_array($e['status'] ?? '', ['approved','pending'])) continue;
                        $_bgOut += (float)($e['ssp_amount'] ?? $e['amount'] ?? 0);
                    }
                } catch (\Throwable $e) {}
                $_bgBal = max(0, $_bgIn - round($_bgOut, 0));
            }
            if ($sspAmt > $_bgBal) {
                return ['ok' => false, 'error' => 'Cannot submit ' . number_format($sspAmt, 0)
                    . ' SSP — you only have ' . number_format($_bgBal, 0) . ' SSP available.'];
            }
        } elseif ($currency === 'USD' && !in_array($staff['role'] ?? '', ['field_accountant'])) {
            $_bgUsdIn = array_filter($this->store->load('cash_ins.json') ?: [],
                fn($i) => (int)($i['collector_id'] ?? 0) === $staffId
                    && ($i['currency'] ?? 'USD') === 'USD' && !in_array($i['status'] ?? 'approved', ['rejected','voided']));
            $_bgUsdInAmt = round(array_sum(array_column(array_values($_bgUsdIn), 'amount')), 2);
            try {
                $_bgAdvStmt = $this->pdo->prepare(
                    "SELECT COALESCE(SUM(amount - amount_spent - amount_returned), 0) FROM staff_advances
                     WHERE recipient_id = ? AND currency = 'USD' AND status = 'active'");
                $_bgAdvStmt->execute([$staffId]);
                $_bgAdvBal = max(0, round((float)$_bgAdvStmt->fetchColumn(), 2));
            } catch (\Throwable $e) { $_bgAdvBal = 0; }
            if ($amount > 0 && $_bgUsdInAmt <= 0 && $_bgAdvBal <= 0) {
                return ['ok' => false, 'error' => 'Cannot submit $' . number_format($amount, 2)
                    . ' USD — you have no USD cash. Switch to SSP if you have SSP.'];
            }
        }

        $desc        = trim($data['description'] ?? '');
        $expDate     = trim($data['expense_date'] ?? date('Y-m-d'));
        $advId       = (int)($data['advance_id'] ?? 0) ?: null;
        $offlineUuid = trim($data['offline_uuid'] ?? '');
        $via         = trim($data['submitted_via'] ?? 'web');

        // ── Backdate guard (v4.11.3) ──────────────────────────────────────
        // Staff may not submit expenses older than 2 days.
        // Accountants/admins are exempt (they may need to correct past entries).
        $isPrivileged = in_array($staff['role'] ?? '', ['accountant', 'field_accountant'], true)
                     || !empty($staff['is_admin']);
        if (!$isPrivileged) {
            $today       = date('Y-m-d');
            $maxPastDate = date('Y-m-d', strtotime('-2 days'));
            if ($expDate > $today) {
                return ['ok' => false, 'error' => 'Expense date cannot be in the future.'];
            }
            if ($expDate < $maxPastDate) {
                return ['ok' => false, 'error' =>
                    'Expense date ' . $expDate . ' is too far in the past. ' .
                    'You can only submit expenses from the last 2 days. ' .
                    'Contact Rupesh to record older entries.'];
            }
        }

        // Idempotency: reject duplicate offline UUID
        if ($offlineUuid) {
            $dup = $this->pdo->prepare("SELECT id FROM staff_expenses WHERE offline_uuid=? LIMIT 1");
            $dup->execute([$offlineUuid]);
            if ($dup->fetchColumn()) {
                return ['ok' => true, 'duplicate' => true, 'error' => 'Already submitted (offline sync).'];
            }
        }

        // Validate linked advance exists and belongs to this staff member
        $adv = null;
        if ($advId) {
            $adv = $this->getAdvanceById($advId);
            if (!$adv) return ['ok' => false, 'error' => 'Linked advance not found.'];
            if ((int)$adv['recipient_id'] !== $staffId && empty($staff['is_admin'])) {
                return ['ok' => false, 'error' => 'Advance not assigned to you.'];
            }
        }

        // Handle receipt upload
        $receiptPath = '';
        $receiptHash = '';
        $flagNoReceipt  = 0;
        $flagDuplicate  = 0;
        $flagOverspend  = 0;

        if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            $result = $this->saveReceipt($file, $staffId);
            if (!$result['ok']) return ['ok' => false, 'error' => $result['error']];
            $receiptPath = $result['path'];
            $receiptHash = $result['hash'];

            // Fraud: duplicate hash
            if ($this->isDuplicateHash($receiptHash)) {
                $flagDuplicate = 1;
            }
        } else {
            $flagNoReceipt = 1;
        }

        // Fraud: overspend check against linked advance
        if ($adv) {
            $balance = round((float)$adv['amount'] - (float)$adv['amount_spent'] - (float)$adv['amount_returned'], 2);
            if ($amount > $balance + 0.01) {
                $flagOverspend = 1;
            }
        }

        $expNo  = $this->nextExpenseNo();
        $now    = date('Y-m-d H:i:s');
        $project = $adv['project'] ?? ($data['project'] ?? 'dishnet');

        // field_accountant has authority to approve their own cash outs
        $autoApprove = in_array($staff['role'] ?? '', ['accountant', 'field_accountant'], true)
                    || !empty($staff['is_admin']);
        // v4.12.11 FIX: INSERT as 'pending' regardless of autoApprove, then (if
        // autoApprove) run the full approveExpense() pipeline below which atomically
        // posts to cb_ledger AND staff_ledger via StaffLedgerWriter. Previously this
        // inserted as 'approved' directly, skipping BOTH cb_ledger and staff_ledger —
        // which is why Aida's 10 FEXP rows never posted to the ledger (before v4.12.10
        // the mobile bag read from staff_ledger and showed inflated 1,300,000 vs
        // actual 333,000). v4.12.10 made the mobile bag read from JSON so the
        // user-visible bug is gone, but admin reports that still read staff_ledger
        // would be wrong. This fix unifies the two ledgers so both paths agree.
        $initStatus  = 'pending';

        $sspAmount    = ($currency === 'SSP') ? $amount : 0;
        // Phase 2: auto-link SSP expense to oldest open exchange batch for this staff.
        // Staff never see this — it happens silently in the background.
        // Uses oldest-first (FIFO) so earlier exchanges are exhausted before newer ones.
        $exchangeRef  = trim($data['exchange_ref'] ?? '');   // manual override if ever needed
        $exchangeRate = null;
        if ($currency === 'SSP' && !$exchangeRef) {
            try {
                $_batches = $this->cb->getOpenExchangeBatches(
                    $this->store->load('cash_ins.json') ?: [], $staffId, 60
                );
                // FIFO: oldest first — reverse the newest-first default
                $_batches = array_reverse($_batches);
                if (!empty($_batches)) {
                    $exchangeRef  = $_batches[0]['exchange_ref'];
                    $exchangeRate = $_batches[0]['rate'] > 0 ? (float)$_batches[0]['rate'] : null;
                }
            } catch (\Throwable $e) { /* non-fatal — expense still saved without link */ }
        } elseif (isset($data['exchange_rate']) && (float)$data['exchange_rate'] > 0) {
            $exchangeRate = (float)$data['exchange_rate'];
        }

        $this->pdo->prepare("
            INSERT INTO staff_expenses
              (expense_no,advance_id,staff_id,staff_name,project,category,amount,ssp_amount,currency,
               description,expense_date,receipt_path,receipt_hash,
               offline_uuid,submitted_via,status,exchange_ref,exchange_rate,
               flag_duplicate,flag_overspend,flag_no_receipt,
               submitted_at,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $expNo, $advId, $staffId, $staff['name'] ?? '',
            $project, $category, $amount, $sspAmount, $currency,
            $desc, $expDate, $receiptPath, $receiptHash,
            $offlineUuid, $via, $initStatus, $exchangeRef, $exchangeRate,
            $flagDuplicate, $flagOverspend, $flagNoReceipt,
            $now, $now, $now,
        ]);
        $expId = (int)$this->pdo->lastInsertId();

        // Insert into expense_receipts table (for multi-photo future-proofing)
        if ($receiptPath) {
            $this->pdo->prepare("
                INSERT INTO expense_receipts (expense_id,file_path,file_hash,file_size,mime_type,uploaded_by_id,uploaded_at)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                $expId, $receiptPath, $receiptHash,
                $file['size'] ?? 0,
                $file['type'] ?? 'image/jpeg',
                $staffId, $now,
            ]);
        }

        $fraudFlags = array_filter([
            $flagDuplicate ? 'duplicate_receipt' : null,
            $flagOverspend ? 'overspend'          : null,
            $flagNoReceipt ? 'no_receipt'          : null,
        ]);

        $this->auditLog('expense_submitted', $staff['name'] ?? '',
            "{$expNo}: \${$amount} ({$category})" . ($advId ? " on advance ADV#" . $advId : ''), $expId);

        // v4.12.11: complete the auto-approve flow through approveExpense() so
        // cb_ledger + staff_ledger both receive the entry (atomic, same txn).
        // Non-fatal on error — expense still exists as 'pending' for manual review.
        $autoApproveResult = null;
        if ($autoApprove) {
            try {
                $autoApproveResult = $this->approveExpense($expId, $staff);
            } catch (\Throwable $e) {
                error_log('[ExpenseAdvanceService] auto-approve failed for exp ' . $expId . ': ' . $e->getMessage());
                $autoApproveResult = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'ok'             => true,
            'id'             => $expId,
            'expense_no'     => $expNo,
            'fraud_flags'    => array_values($fraudFlags),
            'warnings'       => $this->buildWarnings($flagDuplicate, $flagOverspend, $flagNoReceipt),
            'auto_approved'  => $autoApprove && $autoApproveResult && !empty($autoApproveResult['ok']),
            'approve_result' => $autoApproveResult,
        ];
    }

    /**
     * Accountant approves an expense → posts to cb_ledger, updates advance totals.
     */
    public function approveExpense(int $expId, array $admin): array
    {
        $exp = $this->getExpenseById($expId);
        if (!$exp) return ['ok' => false, 'error' => 'Expense not found.'];
        if ($exp['status'] !== 'pending') return ['ok' => false, 'error' => 'Expense is already ' . $exp['status'] . '.'];

        // Old-Advance-Shadow guard (v4.4.26): block retroactive approval
        // against a settled advance — prevents silent balance drift.
        if (!empty($exp['advance_id'])) {
            $advRow = $this->pdo->prepare(
                "SELECT status FROM cash_advances WHERE id=? LIMIT 1"
            );
            $advRow->execute([(int)$exp['advance_id']]);
            $advStatus = $advRow->fetchColumn();
            if ($advStatus === 'settled') {
                return ['ok' => false, 'error' =>
                    'Cannot approve: the advance linked to this expense (' .
                    $exp['advance_id'] . ') is already settled. ' .
                    'Reopen the advance first or unlink this expense.'
                ];
            }
        }

        $now    = date('Y-m-d H:i:s');
        $cbCat  = self::CATEGORY_CB_MAP[$exp['category']] ?? 'Misc Expense';
        $cbDesc = "Field expense {$exp['expense_no']} — {$exp['category']}" .
                  ($exp['description'] ? ': ' . $exp['description'] : '') .
                  " — " . $exp['staff_name'];

        // ── ATOMIC: all three writes succeed together or none do ─────────────
        $this->pdo->beginTransaction();
        try {
            // 1. Post to main cashbook (INSERT cb_ledger)
            // SAFETY RULE 8: cb_ledger.amount is always USD.
            // For SSP expenses: amount = ssp_amount / rate (USD equivalent).
            // For USD expenses: amount = the USD amount directly.
            //
            // v4.20.3 SSP IMPREST FIX (SAFETY RULE 16):
            // When an SSP expense is linked to a cash_advance (advance_id IS NOT NULL),
            // the main cashbook ALREADY recorded the SSP OUT at advance-issue time
            // (e.g. CB-2768 "Advance given to Aida 600,000"). Posting another OUT here
            // would double-count: physical SSP left the till exactly once (at advance
            // time), but cb_ledger would show it leaving twice.
            //
            // Fix: skip the cb_ledger write for advance-linked SSP expenses. The advance
            // running total (cash_advances.amount_spent) and the staff register
            // (cb_ssp_register / staff_ledger) still update — that's where the
            // per-category breakdown lives. cb_ledger remains the source of truth for
            // physical-cash flow only.
            //
            // USD path, free-standing SSP expenses (no advance_id), and all other
            // currencies are unaffected and continue to post to cb_ledger as before.
            $_expCur    = $exp['currency'] ?? 'USD';
            $_expSspAmt = (float)($exp['ssp_amount'] ?? 0);
            $_expAdvId  = (int)($exp['advance_id'] ?? 0);
            $_isImprestSuppressed = ($_expCur === 'SSP') && ($_expAdvId > 0);

            // Prefer the exchange batch rate stored on the expense (actual rate Diko/BBC got
            // at the money changer) over the global system rate — more accurate USD equivalent.
            $_expSysRate = ($exp['exchange_rate'] ?? null)
                ? (float)$exp['exchange_rate']
                : ($this->cb->getExchangeRate() ?: 5800.0);
            $_cbAmount  = ($_expCur === 'SSP')
                ? round($_expSspAmt / max(1.0, $_expSysRate), 2)
                : (float)$exp['amount'];

            $cbId = 0;
            if (!$_isImprestSuppressed) {
                $cbResult = $this->cb->addEntryRaw([
                    'project'           => $exp['project'],
                    'date'              => $exp['expense_date'],
                    'direction'         => 'out',
                    'amount'            => $_cbAmount,
                    'currency'          => $_expCur,
                    'ssp_amount'        => ($_expCur === 'SSP') ? $_expSspAmt : null,
                    'ssp_rate'          => ($_expCur === 'SSP') ? $_expSysRate : null,
                    'category'          => $cbCat,
                    'category_raw'      => ucfirst($exp['category']),
                    'person'            => $exp['staff_name'],
                    'description'       => $cbDesc,
                    'validation_ref'    => $exp['expense_no'],
                    'validation_status' => $exp['receipt_path'] ? 'wr' : 'pending',
                    'status'            => 'approved',
                    'approved_by'       => $admin['name'] ?? 'Accountant',
                    'source'            => 'expense_sync',
                    'created_at'        => $now,
                ]);

                // 2. Get the cb_ledger id — MAX(id) is safe inside same transaction (single writer)
                $cbId = (int)$this->pdo->query("SELECT MAX(id) FROM cb_ledger WHERE source='expense_sync'")->fetchColumn();
            } else {
                // Sentinel: cashbook_entry_id = -1 means "intentionally suppressed
                // because this SSP expense is settled against an advance that already
                // hit cb_ledger". The reconcile worker (Step 3 orphan repost) checks
                // for this and skips, otherwise it would undo the fix.
                $cbId = -1;
            }

            // 3. Mark expense approved
            $this->pdo->prepare("
                UPDATE staff_expenses
                SET status='approved', reviewed_by=?, reviewed_at=?,
                    cashbook_entry_id=?, cashbook_posted_at=?, updated_at=?
                WHERE id=?
            ")->execute([$admin['name'] ?? '', $now, $cbId, $now, $now, $expId]);

            // 4. Update advance running totals
            if ($exp['advance_id']) {
                $this->pdo->prepare("
                    UPDATE cash_advances
                    SET amount_spent = amount_spent + ?,
                        status = CASE
                            WHEN (amount - amount_spent - amount_returned - children_allocated - ?) < 0.01 THEN 'settled'
                            WHEN amount_spent + ? > 0 THEN 'partial'
                            ELSE status
                        END,
                        updated_at = ?
                    WHERE id = ?
                ")->execute([
                    (float)$exp['amount'],
                    (float)$exp['amount'],
                    (float)$exp['amount'],
                    $now,
                    (int)$exp['advance_id'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            // If it was a UNIQUE constraint on validation_ref, that means it was already posted
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
                return ['ok' => false, 'error' => 'Ledger entry for this expense already exists (duplicate prevented). Refresh and check expense status.'];
            }
            throw $e;
        }
        // ── END ATOMIC ───────────────────────────────────────────────────────

        $_auditTag = ($cbId === -1)
            ? "{$exp['expense_no']} approved — settled against advance #{$exp['advance_id']} (cb_ledger suppressed v4.20.3)"
            : "{$exp['expense_no']} approved — cb_ledger #{$cbId}";
        $this->auditLog('expense_approved', $admin['name'] ?? '',
            $_auditTag, $expId);

        // Rebuild snapshot for the agent (approved expense reduces their exposure)
        $this->snap()->rebuild((int)$exp['staff_id'], 'expense', $exp['expense_no']);

        // Dual-write: staff_ledger
        require_once dirname(__FILE__) . '/StaffLedgerWriter.php';
        StaffLedgerWriter::onExpenseApproved($this->pdo, $exp, 'staff_expenses');

        // Phase 3: auto-close batch if fully spent (non-fatal — never blocks approval)
        if (!empty($exp['exchange_ref'])) {
            try { $this->cb->autoCloseIfComplete((string)$exp['exchange_ref']); }
            catch (\Throwable $e) { /* silent */ }
        }

        return ['ok' => true, 'cashbook_entry_id' => $cbId];
    }

    /**
     * Accountant rejects an expense with a reason.
     */
    public function rejectExpense(int $expId, string $reason, array $admin): array
    {
        $exp = $this->getExpenseById($expId);
        if (!$exp) return ['ok' => false, 'error' => 'Expense not found.'];
        if ($exp['status'] !== 'pending') return ['ok' => false, 'error' => 'Expense already ' . $exp['status'] . '.'];

        $reason = trim($reason);
        if (!$reason) return ['ok' => false, 'error' => 'Rejection reason is required.'];

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            UPDATE staff_expenses
            SET status='rejected', reviewed_by=?, reviewed_at=?, reject_reason=?, updated_at=?
            WHERE id=?
        ")->execute([$admin['name'] ?? '', $now, $reason, $now, $expId]);

        $this->auditLog('expense_rejected', $admin['name'] ?? '',
            "{$exp['expense_no']} rejected: {$reason}", $expId);

        return ['ok' => true];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FRAUD DETECTION
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Run full fraud scan on a pending expense. Returns array of flag descriptions.
     * Also updates flag columns on the expense row.
     */
    public function detectFraud(int $expId): array
    {
        $exp = $this->getExpenseById($expId);
        if (!$exp) return [];

        $flags = [];
        $now   = date('Y-m-d H:i:s');
        $updates = [];

        // 1. No receipt image
        if (!$exp['receipt_hash']) {
            $flags[] = ['code' => 'no_receipt', 'detail' => 'Submitted without a receipt photo.'];
            $updates['flag_no_receipt'] = 1;
        }

        // 2. Duplicate receipt image hash
        if ($exp['receipt_hash'] && $this->isDuplicateHashExcluding($exp['receipt_hash'], $expId)) {
            $flags[] = ['code' => 'duplicate_receipt', 'detail' => 'Same receipt image was used on another expense.'];
            $updates['flag_duplicate'] = 1;
        }

        // 3. Overspend beyond advance balance
        if ($exp['advance_id']) {
            $adv = $this->getAdvanceById((int)$exp['advance_id']);
            if ($adv) {
                $usedBefore = $this->pdo->prepare("
                    SELECT COALESCE(SUM(amount),0) FROM staff_expenses
                    WHERE advance_id=? AND status='approved' AND id!=?
                ");
                $usedBefore->execute([(int)$exp['advance_id'], $expId]);
                $alreadySpent = (float)$usedBefore->fetchColumn();
                $remaining    = (float)$adv['amount'] - $alreadySpent - (float)$adv['amount_returned'];

                if ((float)$exp['amount'] > $remaining + 0.01) {
                    $flags[] = [
                        'code'   => 'overspend',
                        'detail' => sprintf('Expense $%.2f exceeds remaining advance balance $%.2f.', (float)$exp['amount'], max(0, $remaining)),
                    ];
                    $updates['flag_overspend'] = 1;
                }
            }
        }

        // 4. Suspiciously round amount (heuristic)
        $amt = (float)$exp['amount'];
        if (fmod($amt, 50.0) === 0.0 && $amt >= 100.0 && !$exp['receipt_hash']) {
            $flags[] = ['code' => 'suspicious_round', 'detail' => 'Large round-number amount with no receipt.'];
        }

        // 5. Future-dated expense
        if ($exp['expense_date'] > date('Y-m-d')) {
            $flags[] = ['code' => 'future_date', 'detail' => 'Expense date is in the future.'];
        }

        // Persist flag updates
        if ($updates) {
            $sets = implode(',', array_map(fn($k) => "{$k}=?", array_keys($updates)));
            $vals = array_values($updates);
            $vals[] = $now;
            $vals[] = $expId;
            $this->pdo->prepare("UPDATE staff_expenses SET {$sets}, updated_at=? WHERE id=?")->execute($vals);
        }

        return $flags;
    }

    /**
     * Scan all pending expenses and return summary fraud report.
     */
    public function getFraudReport(string $project = ''): array
    {
        $w = ["status='pending'"]; $p = [];
        if ($project) { $w[] = "project=?"; $p[] = $project; }

        $rows = $this->pdo->prepare(
            "SELECT * FROM staff_expenses WHERE " . implode(' AND ', $w) . " ORDER BY submitted_at DESC"
        );
        $rows->execute($p);
        $exps = $rows->fetchAll(\PDO::FETCH_ASSOC);

        $flagged = [];
        foreach ($exps as $exp) {
            $flags = $this->detectFraud((int)$exp['id']);
            if ($flags) {
                $flagged[] = ['expense' => $exp, 'flags' => $flags];
            }
        }
        return $flagged;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // STAFF DAILY CASHBOOK
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Compute (or recompute) a staff member's cashbook for a given date.
     * Called by CashbookReconcileWorker nightly.
     *
     * @return array  The staff_cashbook_daily row
     */
    public function computeDailyCashbook(int $staffId, string $staffName, string $date): array
    {
        // Opening balance = closing balance from previous day
        $prev = $this->pdo->prepare(
            "SELECT closing_balance FROM staff_cashbook_daily WHERE staff_id=? AND date<? ORDER BY date DESC LIMIT 1"
        );
        $prev->execute([$staffId, $date]);
        $openingBalance = (float)($prev->fetchColumn() ?: 0.0);

        // Advances RECEIVED by this staff member on this date (root or child — they hold this cash)
        $advRow = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM cash_advances
             WHERE recipient_id=? AND DATE(issued_at)=? AND status!='cancelled'"
        );
        $advRow->execute([$staffId, $date]);
        $advancesReceived = round((float)$advRow->fetchColumn(), 2);

        // Advances ALLOCATED OUT to others on this date (child advances this staff issued)
        // These reduce the holder's personal cash balance since they handed money on to someone else.
        $allocRow = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM cash_advances
             WHERE issued_by_id=? AND DATE(issued_at)=?
               AND parent_advance_id IS NOT NULL AND status!='cancelled'"
        );
        $allocRow->execute([$staffId, $date]);
        $advancesAllocated = round((float)$allocRow->fetchColumn(), 2);

        // Approved expenses on this date
        $expRow = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM staff_expenses
             WHERE staff_id=? AND expense_date=? AND status='approved'"
        );
        $expRow->execute([$staffId, $date]);
        $expensesApproved = round((float)$expRow->fetchColumn(), 2);

        // Cash returned on this date (settlements)
        $retRow = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount_returned),0) FROM cash_advances
             WHERE recipient_id=? AND DATE(settled_at)=?"
        );
        $retRow->execute([$staffId, $date]);
        $cashReturned = round((float)$retRow->fetchColumn(), 2);

        // Correct personal balance formula:
        //   opening + received - allocated_to_others - expenses_spent - cash_returned
        $closingBalance = round(
            $openingBalance + $advancesReceived - $advancesAllocated - $expensesApproved - $cashReturned,
            2
        );

        // Integrity flags
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM staff_expenses
             WHERE staff_id=? AND expense_date=? AND status='approved' AND receipt_hash=''"
        );
        $stmt->execute([$staffId, $date]);
        $flagMissingReceipts = (int)$stmt->fetchColumn();

        $flagOverspend = $closingBalance < -0.01 ? 1 : 0;

        $lastRec = $this->pdo->prepare(
            "SELECT MAX(date) FROM staff_cashbook_daily WHERE staff_id=? AND is_reconciled=1"
        );
        $lastRec->execute([$staffId]);
        $lastRecDate      = $lastRec->fetchColumn() ?: '';
        $daysSince        = $lastRecDate ? (int)round((strtotime($date) - strtotime($lastRecDate)) / 86400) : 999;
        $flagUnreconciled = ($daysSince > 2) ? 1 : 0;

        $flagCount = (int)($flagMissingReceipts > 0) + $flagOverspend + $flagUnreconciled;

        // cb_ledger entry IDs for expense postings today
        $cbIds = $this->pdo->prepare(
            "SELECT cashbook_entry_id FROM staff_expenses
             WHERE staff_id=? AND expense_date=? AND status='approved' AND cashbook_entry_id>0"
        );
        $cbIds->execute([$staffId, $date]);
        $cbEntryIds = json_encode($cbIds->fetchAll(\PDO::FETCH_COLUMN));

        $now = date('Y-m-d H:i:s');

        $this->pdo->prepare("
            INSERT INTO staff_cashbook_daily
              (staff_id,staff_name,date,opening_balance,
               advances_received,advances_allocated,
               expenses_approved,cash_returned,closing_balance,
               flag_missing_receipts,flag_overspend,flag_unreconciled,flag_count,
               cb_entry_ids,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
            $openingBalance, $advancesReceived, $advancesAllocated,
            $expensesApproved, $cashReturned, $closingBalance,
            $flagMissingReceipts, $flagOverspend, $flagUnreconciled, $flagCount,
            $cbEntryIds, $now,
        ]);

        $r = $this->pdo->prepare("SELECT * FROM staff_cashbook_daily WHERE staff_id=? AND date=?");
        $r->execute([$staffId, $date]);
        return $r->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Simplified version of computeDailyCashbook that just returns the row.
     */
    public function getDailyCashbook(int $staffId, string $date): ?array
    {
        $r = $this->pdo->prepare("SELECT * FROM staff_cashbook_daily WHERE staff_id=? AND date=?");
        $r->execute([$staffId, $date]);
        return $r->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Mark a day as reconciled by accountant.
     */
    public function reconcileDay(int $staffId, string $date, string $note, array $admin): array
    {
        $row = $this->getDailyCashbook($staffId, $date);
        if (!$row) return ['ok' => false, 'error' => 'No cashbook row for that date.'];

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("
            UPDATE staff_cashbook_daily
            SET is_reconciled=1, reconciled_by=?, reconciled_at=?,
                flag_unreconciled=0,
                flag_count=flag_missing_receipts+flag_overspend,
                notes=?, updated_at=?
            WHERE staff_id=? AND date=?
        ")->execute([$admin['name'] ?? '', $now, $note, $now, $staffId, $date]);

        $this->auditLog('day_reconciled', $admin['name'] ?? '',
            "Staff #{$staffId} {$date} reconciled", 0);

        return ['ok' => true];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // QUERIES
    // ══════════════════════════════════════════════════════════════════════════

    public function getAdvanceById(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT * FROM cash_advances WHERE id=?");
        $s->execute([$id]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public function getExpenseById(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT * FROM staff_expenses WHERE id=?");
        $s->execute([$id]);
        return $s->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** List advances with optional filters */
    public function getAdvances(array $f = []): array
    {
        $w = ['1=1']; $p = [];
        if (!empty($f['project']))      { $w[] = "project=?";       $p[] = $f['project']; }
        if (!empty($f['status']))       { $w[] = "status=?";        $p[] = $f['status']; }
        if (!empty($f['recipient_id'])) { $w[] = "recipient_id=?";  $p[] = $f['recipient_id']; }
        if (!empty($f['issued_by_id'])) { $w[] = "issued_by_id=?";  $p[] = $f['issued_by_id']; }
        if (!empty($f['date_from']))    { $w[] = "issued_at>=?";     $p[] = $f['date_from']; }
        if (!empty($f['date_to']))      { $w[] = "issued_at<=?";     $p[] = $f['date_to'] . ' 23:59:59'; }

        // Optionally filter by root-only or child-only
        if (isset($f['roots_only']) && $f['roots_only']) {
            $w[] = "parent_advance_id IS NULL";
        }
        if (!empty($f['parent_id'])) {
            $w[] = "parent_advance_id=?"; $p[] = $f['parent_id'];
        }

        $limit  = (int)($f['limit'] ?? 100);
        $offset = (int)($f['offset'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM cash_advances WHERE " . implode(' AND ', $w) .
            " ORDER BY issued_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($p);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Enrich with correct balance (including children_allocated) and hierarchy info
        foreach ($rows as &$r) {
            $r['children_allocated'] = (float)($r['children_allocated'] ?? 0);
            $r['balance'] = round(
                (float)$r['amount']
                - (float)$r['amount_spent']
                - (float)$r['amount_returned']
                - $r['children_allocated'],
                2
            );
            $r['is_child'] = !empty($r['parent_advance_id']);
            $r['is_root']  = empty($r['parent_advance_id']);
        }
        return $rows;
    }

    /** List expenses with optional filters */
    public function getExpenses(array $f = []): array
    {
        $w = ['1=1']; $p = [];
        if (!empty($f['staff_id']))    { $w[] = "staff_id=?";    $p[] = $f['staff_id']; }
        if (!empty($f['advance_id']))  { $w[] = "advance_id=?";  $p[] = $f['advance_id']; }
        if (!empty($f['status']))      { $w[] = "status=?";      $p[] = $f['status']; }
        if (!empty($f['project']))     { $w[] = "project=?";     $p[] = $f['project']; }
        if (!empty($f['date_from']))   { $w[] = "expense_date>=?"; $p[] = $f['date_from']; }
        if (!empty($f['date_to']))     { $w[] = "expense_date<=?"; $p[] = $f['date_to']; }
        if (!empty($f['category']))    { $w[] = "category=?";    $p[] = $f['category']; }
        if (isset($f['flagged']) && $f['flagged']) {
            $w[] = "(flag_duplicate=1 OR flag_overspend=1 OR flag_no_receipt=1)";
        }

        $limit  = (int)($f['limit'] ?? 100);
        $offset = (int)($f['offset'] ?? 0);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM staff_expenses WHERE " . implode(' AND ', $w) .
            " ORDER BY submitted_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($p);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Count pending expenses (for nav badge) */
    public function countPending(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM staff_expenses WHERE status='pending'")->fetchColumn();
    }

    /** Count active advances */
    public function countActiveAdvances(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM cash_advances WHERE status IN ('active','partial')")->fetchColumn();
    }

    /** Staff summary: active advances, unsubmitted amounts, etc. */
    public function getStaffSummary(int $staffId): array
    {
        $adv = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total,
                    COALESCE(SUM(amount_spent),0) as spent,
                    COALESCE(SUM(amount_returned),0) as returned,
                    COALESCE(SUM(children_allocated),0) as allocated
             FROM cash_advances WHERE recipient_id=? AND status IN ('active','partial')"
        );
        $adv->execute([$staffId]);
        $advRow = $adv->fetch(\PDO::FETCH_ASSOC);

        $pending = $this->pdo->prepare(
            "SELECT COUNT(*) as cnt, COALESCE(SUM(amount),0) as total
             FROM staff_expenses WHERE staff_id=? AND status='pending'"
        );
        $pending->execute([$staffId]);
        $pendRow = $pending->fetch(\PDO::FETCH_ASSOC);

        $balance = round(
            (float)($advRow['total'] ?? 0)
            - (float)($advRow['spent'] ?? 0)
            - (float)($advRow['returned'] ?? 0)
            - (float)($advRow['allocated'] ?? 0),
            2
        );

        // Per-currency breakdown
        $byCurrency = ['USD' => ['advances' => 0, 'balance' => 0, 'pending' => 0], 'SSP' => ['advances' => 0, 'balance' => 0, 'pending' => 0]];
        try {
            $advByCur = $this->pdo->prepare(
                "SELECT currency, COUNT(*) as cnt,
                        COALESCE(SUM(amount),0) - COALESCE(SUM(amount_spent),0) - COALESCE(SUM(amount_returned),0) - COALESCE(SUM(children_allocated),0) as balance
                 FROM cash_advances WHERE recipient_id=? AND status IN ('active','partial') GROUP BY currency"
            );
            $advByCur->execute([$staffId]);
            foreach ($advByCur->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $cur = strtoupper($r['currency'] ?? 'USD');
                if (isset($byCurrency[$cur])) {
                    $byCurrency[$cur]['advances'] = (int)$r['cnt'];
                    $byCurrency[$cur]['balance'] = round((float)$r['balance'], 2);
                }
            }
            $pendByCur = $this->pdo->prepare(
                "SELECT currency, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total
                 FROM staff_expenses WHERE staff_id=? AND status='pending' GROUP BY currency"
            );
            $pendByCur->execute([$staffId]);
            foreach ($pendByCur->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $cur = strtoupper($r['currency'] ?? 'USD');
                if (isset($byCurrency[$cur])) {
                    $byCurrency[$cur]['pending'] = round((float)$r['total'], 2);
                }
            }
        } catch (\Throwable $e) { /* tables may lack currency column in old installs */ }

        return [
            'active_advances'     => (int)($advRow['cnt'] ?? 0),
            'advance_total'       => round((float)($advRow['total'] ?? 0), 2),
            'advance_balance'     => $balance,
            'pending_expenses'    => (int)($pendRow['cnt'] ?? 0),
            'pending_expense_amt' => round((float)($pendRow['total'] ?? 0), 2),
            'by_currency'         => $byCurrency,
        ];
    }

    /** All staff with active advances (for accountant overview) */
    public function getActiveStaffSummary(): array
    {
        $rows = $this->pdo->query("
            SELECT
                recipient_id AS staff_id,
                recipient_name AS staff_name,
                COUNT(*) AS advance_count,
                SUM(amount) AS total_advanced,
                SUM(amount_spent) AS total_spent,
                SUM(amount_returned) AS total_returned,
                SUM(children_allocated) AS total_allocated,
                SUM(amount - amount_spent - amount_returned - children_allocated) AS balance
            FROM cash_advances
            WHERE status IN ('active','partial')
            GROUP BY recipient_id, recipient_name
            ORDER BY balance DESC
        ")->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * Reconciliation report: compare staff cashbooks against main cashbook.
     */
    public function getReconciliationReport(string $dateFrom, string $dateTo, string $project = ''): array
    {
        // Staff total (approved expenses in period)
        $w = ["status='approved'", "expense_date>=?", "expense_date<=?"]; $p = [$dateFrom, $dateTo];
        if ($project) { $w[] = "project=?"; $p[] = $project; }
        $staffTotal = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM staff_expenses WHERE " . implode(' AND ', $w)
        );
        $staffTotal->execute($p);
        $totalExpenses = round((float)$staffTotal->fetchColumn(), 2);

        // v4.20.3: Imprest-suppressed expenses don't post to cb_ledger by design
        // (advance-linked SSP — the advance issue ALREADY recorded the OUT). Subtract
        // them from the staff total so variance compares apples to apples. Sentinel:
        // cashbook_entry_id = -1.
        $suppW = $w; $suppW[] = "cashbook_entry_id = -1";
        $suppStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM staff_expenses WHERE " . implode(' AND ', $suppW)
        );
        $suppStmt->execute($p);
        $imprestSuppressed = round((float)$suppStmt->fetchColumn(), 2);
        $totalExpensesPostable = round($totalExpenses - $imprestSuppressed, 2);

        // Main cashbook total for expense_sync source
        $cbw = ["source='expense_sync'", "date>=?", "date<=?", "direction='out'"]; $cbp = [$dateFrom, $dateTo];
        if ($project) { $cbw[] = "project=?"; $cbp[] = $project; }
        $cbTotal = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM cb_ledger WHERE " . implode(' AND ', $cbw)
        );
        $cbTotal->execute($cbp);
        $cbExpenses = round((float)$cbTotal->fetchColumn(), 2);

        $variance = round($totalExpensesPostable - $cbExpenses, 2);

        // Unsettled advances
        $unsettled = $this->pdo->prepare(
            "SELECT advance_no, recipient_name, amount, amount_spent, amount_returned,
                    amount - amount_spent - amount_returned AS balance, issued_at
             FROM cash_advances
             WHERE status IN ('active','partial') AND issued_at<=?
             ORDER BY issued_at ASC"
        );
        $unsettled->execute([$dateTo . ' 23:59:59']);
        $unsettledAdvances = $unsettled->fetchAll(\PDO::FETCH_ASSOC);

        // Unreconciled staff days
        $unrecDays = $this->pdo->prepare(
            "SELECT staff_name, date, flag_count
             FROM staff_cashbook_daily
             WHERE is_reconciled=0 AND date BETWEEN ? AND ? AND flag_count>0
             ORDER BY date ASC"
        );
        $unrecDays->execute([$dateFrom, $dateTo]);
        $unreconciledDays = $unrecDays->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'period'                => ['from' => $dateFrom, 'to' => $dateTo],
            'staff_expense_total'   => $totalExpenses,
            'imprest_suppressed'    => $imprestSuppressed,    // v4.20.3
            'staff_postable_total'  => $totalExpensesPostable, // v4.20.3 (staff_total - imprest_suppressed)
            'cashbook_total'        => $cbExpenses,
            'variance'              => $variance,
            'is_balanced'           => abs($variance) < 0.02,
            'unsettled_advances'    => $unsettledAdvances,
            'unreconciled_days'     => $unreconciledDays,
            'generated_at'          => date('Y-m-d H:i:s'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // RECEIPT SERVING (for admin tab — avoids exposing raw upload paths)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Get full filesystem path for a receipt file.
     * Returns null if path is outside the receipt dir (path traversal guard).
     */
    public function getReceiptPath(string $relativePath): ?string
    {
        // Sanitize: strip leading slashes, reject traversal
        $safe = ltrim(str_replace(['../', './', '\\'], '', $relativePath), '/');
        $full = $this->dataDir . '/uploads/' . $safe;
        if (!str_starts_with(realpath(dirname($full)) ?: '', realpath($this->dataDir . '/uploads'))) {
            return null;
        }
        return file_exists($full) ? $full : null;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // INTERNAL HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    private function saveReceipt(array $file, int $staffId): array
    {
        if ($file['size'] > self::MAX_RECEIPT_BYTES) {
            return ['ok' => false, 'error' => 'Receipt photo too large (max 8 MB).'];
        }

        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext     = strtolower(pathinfo($file['name'] ?? 'file.jpg', PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return ['ok' => false, 'error' => 'Receipt must be JPG, PNG, or PDF.'];
        }

        // MIME check: verify actual file content matches declared extension.
        // Prevents a renamed malicious file (e.g. shell.php → shell.jpg) from
        // passing the extension allowlist above.
        $allowedMimes = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'application/pdf' => ['pdf'],
        ];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) finfo_close($finfo);
        if (!isset($allowedMimes[$mimeType])) {
            return ['ok' => false, 'error' => 'Receipt file type not allowed. Upload a JPG, PNG, or PDF.'];
        }
        // Also confirm extension matches MIME (catches .jpg file with PDF content etc.)
        if (!in_array($ext, $allowedMimes[$mimeType], true)) {
            $ext = $allowedMimes[$mimeType][0]; // use correct extension from MIME
        }

        $hash    = sha1_file($file['tmp_name']);
        $dir     = $this->dataDir . '/' . self::RECEIPT_DIR;
        $fname   = 'rcpt-' . $staffId . '-' . date('Ymd-His') . '-' . substr($hash, 0, 8) . '.' . $ext;
        $dest    = $dir . '/' . $fname;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            // Fallback for non-HTTP contexts (base64 from PWA)
            if (!empty($file['data'])) {
                $bytes = base64_decode(preg_replace('/^data:\w+\/\w+;base64,/', '', $file['data']));
                if (file_put_contents($dest, $bytes) === false) {
                    return ['ok' => false, 'error' => 'Failed to save receipt photo.'];
                }
                $hash = sha1($bytes);
            } else {
                return ['ok' => false, 'error' => 'Failed to save receipt photo.'];
            }
        }

        // Compress: max 1200px, 70% quality
        $this->compressReceipt(self::RECEIPT_DIR . '/' . $fname);

        return ['ok' => true, 'path' => self::RECEIPT_DIR . '/' . $fname, 'hash' => $hash];
    }

    /**
     * Compress saved receipt image in-place.
     */
    private function compressReceipt(string $path): void
    {
        require_once dirname(__DIR__) . '/lib/ImageCompressor.php';
        compressImage($this->dataDir . '/' . $path);
    }

    private function isDuplicateHash(string $hash): bool
    {
        if (!$hash) return false;
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM expense_receipts WHERE file_hash=?");
        $s->execute([$hash]);
        return (int)$s->fetchColumn() > 0;
    }

    private function isDuplicateHashExcluding(string $hash, int $expId): bool
    {
        if (!$hash) return false;
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) FROM expense_receipts er
             JOIN staff_expenses se ON se.id=er.expense_id
             WHERE er.file_hash=? AND se.id!=?"
        );
        $s->execute([$hash, $expId]);
        return (int)$s->fetchColumn() > 0;
    }

    private function nextAdvanceNo(): string
    {
        $prefix = 'ADV-' . date('Ym') . '-';
        // Search both ADV- and SUB- prefixes to get the true last sequence number
        $s = $this->pdo->prepare(
            "SELECT advance_no FROM cash_advances
             WHERE (advance_no LIKE ? OR advance_no LIKE ?)
             ORDER BY id DESC LIMIT 1"
        );
        $s->execute([$prefix . '%', 'SUB-' . date('Ym') . '-%']);
        $last = $s->fetchColumn();
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            return $prefix . str_pad((string)((int)$m[1] + 1), 3, '0', STR_PAD_LEFT);
        }
        return $prefix . '001';
    }

    private function nextExpenseNo(): string
    {
        $prefix = 'EXP-' . date('Ym') . '-';
        $s = $this->pdo->prepare("SELECT expense_no FROM staff_expenses WHERE expense_no LIKE ? ORDER BY id DESC LIMIT 1");
        $s->execute([$prefix . '%']);
        $last = $s->fetchColumn();
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            return $prefix . str_pad((string)((int)$m[1] + 1), 3, '0', STR_PAD_LEFT);
        }
        return $prefix . '001';
    }

    private function buildWarnings(int $dup, int $over, int $noRcpt): array
    {
        $w = [];
        if ($dup)   $w[] = '⚠️ This receipt image was used on a previous expense — please verify.';
        if ($over)  $w[] = '⚠️ Amount exceeds remaining advance balance.';
        if ($noRcpt) $w[] = '⚠️ No receipt uploaded — expense may be queried by accountant.';
        return $w;
    }

    private function auditLog(string $event, string $actor, string $detail, int $refId = 0): void
    {
        try {
            $this->store->appendWithId('activity_log.json', [
                'event'      => $event,
                'actor'      => $actor,
                'detail'     => $detail,
                'ref_id'     => $refId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* non-blocking */ }
    }
}
