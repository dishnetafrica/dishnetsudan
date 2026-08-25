<?php
declare(strict_types=1);

/**
 * WalletService v3.0 — Idempotent, locked, typed transactions.
 *
 * Changes from v2.1:
 *  - Idempotency key on every debit/credit (prevents double-charge)
 *  - Transaction types: topup, order_payment, commission, reversal, sim_activation, bundle_recharge
 *  - Counterparty field for future double-entry migration
 *  - Reversal chain via original_trx_no
 *  - Uses appendWithId() for atomic passbook writes (Phase 1 locking)
 *  - PHP 7.4 compatible (no arrow functions, no match)
 *
 * BACKWARD COMPAT: debit() and credit() accept the same positional args
 * as v2.1 for the first 6 params. New params are keyword-appended.
 */
class WalletService
{
    /** @var  */
    private $store;
    /** @var \PDO|null */
    private $pdo;

    const TRX_TOPUP           = 'topup';
    const TRX_ORDER_PAYMENT   = 'order_payment';
    const TRX_COMMISSION      = 'commission';
    const TRX_REVERSAL        = 'reversal';
    const TRX_ADJUSTMENT      = 'adjustment';
    const TRX_SIM_ACTIVATION  = 'sim_activation';
    const TRX_BUNDLE_RECHARGE = 'bundle_recharge';

    public function __construct( $store, $pdo = null)
    {
        $this->store = $store;
        $this->pdo   = $pdo;
    }

    // ══════════════════════════════════════════════════════════
    // BALANCE
    // ══════════════════════════════════════════════════════════

    public function getBalance(int $retailerId): float
    {
        $r = $this->store->findOne('retailers.json', 'id', $retailerId);
        return (float)(isset($r['wallet']) ? $r['wallet'] : 0);
    }

    public function hasSufficientBalance(int $retailerId, float $amount): bool
    {
        return $this->getBalance($retailerId) >= $amount;
    }

    // ══════════════════════════════════════════════════════════
    // DEBIT — idempotent
    // ══════════════════════════════════════════════════════════

    /**
     * Debit retailer wallet. Idempotent if $idempotencyKey provided.
     *
     * First 6 params match v2.1 signature for backward compat.
     * New params appended with defaults.
     *
     * @throws \RuntimeException on insufficient balance
     */
    public function debit(
        int    $retailerId,
        float  $amount,
        string $description    = '',
        string $reference      = '',
        $applicationId         = null,
        string $crmClientId    = '',
        string $idempotencyKey = '',
        string $trxType        = 'order_payment',
        string $createdBy      = 'system'
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Debit amount must be positive.');
        }

        // Idempotency: return cached passbook entry
        if ($idempotencyKey !== '') {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        // B-05 FIX: balance check + wallet update are now atomic under LOCK_EX.
        // Previously getBalance() + updateOne() were two separate file operations —
        // concurrent requests could both pass the balance check and both debit.
        $prevBalance = 0.0;
        $newBalance = $this->store->withLock('retailers.json', function (array $retailers) use ($retailerId, $amount, &$prevBalance) {
            foreach ($retailers as &$r) {
                if ((int)($r['id'] ?? 0) === $retailerId) {
                    $balance = (float)($r['wallet'] ?? 0);
                    if ($amount > $balance) {
                        throw new \RuntimeException(
                            'Insufficient wallet balance. Required: $' . number_format($amount, 2) .
                            ', Available: $' . number_format($balance, 2)
                        );
                    }
                    $prevBalance   = $balance;
                    $r['wallet']   = round($balance - $amount, 2);
                    return ['records' => $retailers, 'result' => $r['wallet']];
                }
            }
            unset($r);
            throw new \RuntimeException("Retailer #{$retailerId} not found.");
        });

        return $this->writeEntry([
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : $this->genKey(),
            'retailer_id'     => $retailerId,
            'entry_type'      => 'debit',
            'trx_type'        => $trxType,
            'amount'          => $amount,
            'prev_balance'    => $prevBalance,
            'curr_balance'    => $newBalance,
            'description'     => $description,
            'reference'       => $reference !== '' ? $reference : ('TXN-' . date('Ymd') . '-' . $retailerId),
            'application_id'  => $applicationId,
            'crm_client_id'   => $crmClientId,
            'counterparty'    => 'master',
            'original_trx_no' => null,
            'created_by'      => $createdBy,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // CREDIT — idempotent
    // ══════════════════════════════════════════════════════════

    /**
     * Credit retailer wallet. First 4 params match v2.1.
     */
    public function credit(
        int    $retailerId,
        float  $amount,
        string $description    = '',
        string $createdBy      = 'admin',
        string $idempotencyKey = '',
        string $trxType        = 'topup',
        string $reference      = '',
        string $originalTrxNo  = ''
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        if ($idempotencyKey !== '') {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        // CRIT-01 FIX: balance read + wallet update are now atomic under LOCK_EX.
        // Previously getBalance() + updateOne() were two separate file operations —
        // concurrent credits (admin top-up + commission at the same moment) could
        // both read the same balance and the second write would overwrite the first,
        // silently erasing the first credit. Mirror debit() pattern exactly.
        $prevBalance = 0.0;
        $newBalance  = $this->store->withLock('retailers.json', function (array $retailers) use ($retailerId, $amount, &$prevBalance) {
            foreach ($retailers as &$r) {
                if ((int)($r['id'] ?? 0) === $retailerId) {
                    $prevBalance = (float)($r['wallet'] ?? 0);
                    $r['wallet'] = round($prevBalance + $amount, 2);
                    return ['records' => $retailers, 'result' => $r['wallet']];
                }
            }
            unset($r);
            throw new \RuntimeException("Retailer #{$retailerId} not found.");
        });

        return $this->writeEntry([
            'idempotency_key'  => $idempotencyKey !== '' ? $idempotencyKey : $this->genKey(),
            'retailer_id'      => $retailerId,
            'entry_type'       => 'credit',
            'trx_type'         => $trxType,
            'amount'           => $amount,
            'prev_balance'     => $prevBalance,
            'curr_balance'     => $newBalance,
            'description'      => $description !== '' ? $description : 'Wallet top-up',
            'reference'        => $reference !== '' ? $reference : ('TOP-' . date('Ymd') . '-' . time()),
            'application_id'   => null,
            'crm_client_id'    => '',
            'counterparty'     => 'master',
            'original_trx_no'  => $originalTrxNo !== '' ? $originalTrxNo : null,
            'created_by'       => $createdBy,
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // REVERSAL — inverse entry linked to original
    // ══════════════════════════════════════════════════════════

    /**
     * Reverse a previous transaction by trx_no.
     * Creates inverse entry. Idempotent (double-reverse returns cached).
     */
    public function reverse(string $originalTrxNo, string $reason, string $createdBy = 'system', string $idempotencyKey = ''): array
    {
        $original = $this->findByTrxNo($originalTrxNo);
        if ($original === null) {
            throw new \RuntimeException("Transaction {$originalTrxNo} not found.");
        }
        if (isset($original['trx_type']) && $original['trx_type'] === self::TRX_REVERSAL) {
            throw new \RuntimeException('Cannot reverse a reversal.');
        }

        $revKey = $idempotencyKey !== '' ? $idempotencyKey : ('REV-' . $originalTrxNo);
        $existing = $this->findByIdempotencyKey($revKey);
        if ($existing !== null) {
            return $existing;
        }

        $retailerId = (int)(isset($original['retailer_id']) ? $original['retailer_id'] : 0);
        $amount     = (float)(isset($original['amount']) ? $original['amount'] : 0);
        $entryType  = isset($original['entry_type']) ? $original['entry_type'] : 'debit';

        // Legacy compat: v2.1 used "Debit"/"Credit"
        if ($entryType === 'Debit') $entryType = 'debit';
        if ($entryType === 'Credit') $entryType = 'credit';

        if ($entryType === 'debit') {
            return $this->credit($retailerId, $amount, 'REVERSAL: ' . $reason, $createdBy,
                $revKey, self::TRX_REVERSAL, 'REV-' . (isset($original['reference']) ? $original['reference'] : ''), $originalTrxNo);
        } else {
            return $this->debit($retailerId, $amount, 'REVERSAL: ' . $reason,
                'REV-' . (isset($original['reference']) ? $original['reference'] : ''),
                isset($original['application_id']) ? $original['application_id'] : null,
                isset($original['crm_client_id']) ? $original['crm_client_id'] : '',
                $revKey, self::TRX_REVERSAL, $createdBy);
        }
    }

    // ══════════════════════════════════════════════════════════
    // QUERIES
    // ══════════════════════════════════════════════════════════

    public function getPassbook(int $retailerId, int $limit = 100): array
    {
        $all = $this->store->findAll('passbook.json', 'retailer_id', $retailerId);
        usort($all, function ($a, $b) {
            return strcmp(
                isset($b['created_at']) ? $b['created_at'] : '',
                isset($a['created_at']) ? $a['created_at'] : ''
            );
        });
        return array_slice($all, 0, $limit);
    }

    public function getSummary(int $retailerId): array
    {
        // v4.11.38: SQL aggregation -- avoids loading every passbook row into PHP
        try {
            $pdo  = $this->store->getPdo();
            $stmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN entry_type IN ('credit','Credit') THEN amount ELSE 0 END) AS total_credit,
                    SUM(CASE WHEN entry_type NOT IN ('credit','Credit') THEN amount ELSE 0 END) AS total_debit,
                    COUNT(*) AS transactions
                FROM [passbook] WHERE retailer_id = ?"
            );
            $stmt->execute([$retailerId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            return [
                'balance'      => $this->getBalance($retailerId),
                'total_credit' => round((float)($row['total_credit'] ?? 0), 2),
                'total_debit'  => round((float)($row['total_debit']  ?? 0), 2),
                'transactions' => (int)($row['transactions'] ?? 0),
                'by_type'      => [],
            ];
        } catch (\Throwable $e) {
            // Fallback: PHP-side aggregation
            $entries = $this->store->findAll('passbook.json', 'retailer_id', $retailerId);
            $totalCredit = 0.0; $totalDebit = 0.0;
            foreach ($entries as $e2) {
                $et  = isset($e2['entry_type']) ? $e2['entry_type'] : 'debit';
                $amt = (float)($e2['amount'] ?? 0);
                if ($et === 'credit' || $et === 'Credit') { $totalCredit += $amt; } else { $totalDebit += $amt; }
            }
            return [
                'balance'      => $this->getBalance($retailerId),
                'total_credit' => round($totalCredit, 2),
                'total_debit'  => round($totalDebit, 2),
                'transactions' => count($entries),
                'by_type'      => [],
            ];
        }
    }

    public function getAllPassbook(int $limit = 500): array
    {
        $all = $this->store->load('passbook.json');
        usort($all, function ($a, $b) {
            return strcmp(
                isset($b['created_at']) ? $b['created_at'] : '',
                isset($a['created_at']) ? $a['created_at'] : ''
            );
        });
        return array_slice($all, 0, $limit);
    }

    /** All retailer balances for admin dashboard */
    public function getAllBalances(): array
    {
        $retailers = $this->store->load('retailers.json');
        $out = [];
        foreach ($retailers as $r) {
            $out[] = [
                'id'        => isset($r['id']) ? (int)$r['id'] : 0,
                'name'      => isset($r['name']) ? $r['name'] : '',
                'email'     => isset($r['email']) ? $r['email'] : '',
                'balance'   => (float)(isset($r['wallet']) ? $r['wallet'] : 0),
                'is_active' => !empty($r['is_active']),
            ];
        }
        return $out;
    }

    public function findByTrxNo(string $trxNo): ?array
    {
        return $this->store->findOne('passbook.json', 'trx_no', $trxNo);
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        if ($key === '') return null;
        return $this->store->findOne('passbook.json', 'idempotency_key', $key);
    }

    // ══════════════════════════════════════════════════════════
    // INTERNAL
    // ══════════════════════════════════════════════════════════

    private function writeEntry(array $data): array
    {
        $entry = array_merge([
            // B-13 FIX: time()+mt_rand had a 1-in-900 collision chance per second.
            // bin2hex(random_bytes(8)) gives 16 hex chars = 2^64 unique values.
            'trx_no'     => 'TRX-' . date('Ymd') . '-' . bin2hex(random_bytes(8)),
            'created_at' => date('Y-m-d H:i:s'),
        ], $data);

        // Write passbook (legacy compat — keep for existing reads)
        $entry = $this->store->appendWithId('passbook.json', $entry);

        // Write wallet_events (migration 009) — crash-safe: same SQLite transaction
        // as the retailers.json balance update that withLock just committed.
        // If wallet_events table doesn't exist yet (pre-migration), skip silently.
        if ($this->pdo !== null) {
            try {
                $evNo = 'WE-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt = $this->pdo->prepare(
                    'INSERT OR IGNORE INTO wallet_events
                      (event_no, retailer_id, retailer_name, direction, amount,
                       prev_balance, curr_balance, trx_type, description,
                       reference, idempotency_key, created_by, created_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $evNo,
                    $entry['retailer_id']     ?? 0,
                    $entry['retailer_name']   ?? '',
                    $entry['entry_type']      ?? 'credit',
                    $entry['amount']          ?? 0,
                    $entry['prev_balance']    ?? 0,
                    $entry['curr_balance']    ?? 0,
                    $entry['trx_type']        ?? 'other',
                    $entry['description']     ?? '',
                    $entry['reference']       ?? '',
                    $entry['idempotency_key'] ?? '',
                    $entry['created_by']      ?? 'system',
                    $entry['created_at'],
                ]);
            } catch (\Throwable $e) {
                // Non-fatal: wallet_events not yet created, or table schema mismatch.
                // passbook.json already written — audit trail not lost.
            }
        }

        return $entry;
    }

    private function genKey(): string
    {
        return sprintf('auto-%s-%04x%04x', date('Ymd-His'), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }
}
