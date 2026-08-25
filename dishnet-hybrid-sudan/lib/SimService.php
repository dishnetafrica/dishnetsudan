<?php
declare(strict_types=1);

/**
 * SimService v3.0 — SIM card inventory, activation, transfers, fraud.
 *
 * Storage: data/sim_cards.json, data/sim_movements.json
 *
 * SIM record structure:
 * {
 *   "id": 1,
 *   "iccid": "8923400012345678901",
 *   "msisdn": "+211912345678",
 *   "imsi": "412010012345678",
 *   "status": "available",
 *   "owner_org_id": 1,
 *   "owner_org_name": "DishNet HQ",
 *   "activated_customer_name": null,
 *   "activated_customer_phone": null,
 *   "activated_at": null,
 *   "activated_by_retailer_id": null,
 *   "pin": null,
 *   "puk": null,
 *   "created_at": "2026-01-15 10:00:00",
 *   "updated_at": "2026-01-15 10:00:00"
 * }
 *
 * State machine:
 *   available -> allocated -> activated -> suspended -> expired
 *                                      -> blacklisted (terminal)
 *   allocated -> available (return)
 *   suspended -> activated (reactivate)
 */
class SimService
{
    /** @var  */
    private $store;

    /** @var WalletService */
    private $wallet;

    const FILE       = 'sim_cards.json';
    const MOVES_FILE = 'sim_movements.json';
    const FRAUD_FILE = 'sim_fraud_log.json';

    // State machine: status => [allowed next statuses]
    const TRANSITIONS = [
        'available'   => ['allocated'],
        'allocated'   => ['available', 'activated'],
        'activated'   => ['suspended', 'blacklisted'],
        'suspended'   => ['activated', 'expired', 'blacklisted'],
        'expired'     => ['blacklisted'],
        'blacklisted' => [],  // terminal
    ];

    // Velocity limits
    const MAX_ACTIVATIONS_PER_RETAILER_PER_HOUR = 10;
    const MAX_ACTIVATIONS_PER_MSISDN_PER_DAY    = 2;

    public function __construct( $store, WalletService $wallet)
    {
        $this->store  = $store;
        $this->wallet = $wallet;
    }

    // ══════════════════════════════════════════════════════════
    // INBOUND — receive SIMs from supplier into HQ stock
    // ══════════════════════════════════════════════════════════

    /**
     * Bulk import SIMs. Skips duplicates by ICCID.
     * @param array $sims  Each: ['iccid'=>..., 'msisdn'=>..., 'imsi'=>..., 'pin'=>..., 'puk'=>...]
     * @return array       ['imported'=>int, 'skipped'=>int]
     */
    public function inboundBatch(array $sims, int $orgId = 1, string $orgName = 'DishNet HQ'): array
    {
        $existing = $this->store->load(self::FILE);
        $existingIccids = [];
        foreach ($existing as $s) {
            $existingIccids[isset($s['iccid']) ? $s['iccid'] : ''] = true;
        }

        $imported = 0;
        $skipped  = 0;
        $now      = date('Y-m-d H:i:s');

        foreach ($sims as $sim) {
            $iccid = trim(isset($sim['iccid']) ? $sim['iccid'] : '');
            if ($iccid === '' || isset($existingIccids[$iccid])) {
                $skipped++;
                continue;
            }

            $this->store->appendWithId(self::FILE, [
                'iccid'                    => $iccid,
                'msisdn'                   => trim(isset($sim['msisdn']) ? $sim['msisdn'] : ''),
                'imsi'                     => trim(isset($sim['imsi']) ? $sim['imsi'] : ''),
                'status'                   => 'available',
                'owner_org_id'             => $orgId,
                'owner_org_name'           => $orgName,
                'activated_customer_name'  => null,
                'activated_customer_phone' => null,
                'activated_at'             => null,
                'activated_by_retailer_id' => null,
                'pin'                      => isset($sim['pin']) ? $sim['pin'] : null,
                'puk'                      => isset($sim['puk']) ? $sim['puk'] : null,
                'created_at'               => $now,
                'updated_at'               => $now,
            ]);
            $existingIccids[$iccid] = true;
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    // ══════════════════════════════════════════════════════════
    // ALLOCATE — HQ/Dist transfers SIMs to retailer
    // ══════════════════════════════════════════════════════════

    /**
     * @param int[] $simIds       SIM IDs to allocate
     * @param int   $toOrgId      Target retailer org ID
     * @param string $toOrgName   Target org name
     * @param int   $byUserId     Who performed the transfer
     * @return array              ['allocated'=>int, 'errors'=>array]
     */
    public function allocate(array $simIds, int $toOrgId, string $toOrgName, int $byUserId): array
    {
        $allocated = 0;
        $errors    = [];
        $now       = date('Y-m-d H:i:s');

        foreach ($simIds as $simId) {
            $sim = $this->store->findOne(self::FILE, 'id', (int)$simId);
            if (!$sim) {
                $errors[] = "SIM #{$simId}: not found";
                continue;
            }
            if (($sim['status'] ?? '') !== 'available') {
                $errors[] = "SIM #{$simId}: status is '{$sim['status']}', must be 'available'";
                continue;
            }

            $fromOrgId   = isset($sim['owner_org_id']) ? (int)$sim['owner_org_id'] : 0;
            $fromOrgName = isset($sim['owner_org_name']) ? $sim['owner_org_name'] : '';

            $this->store->updateOne(self::FILE, 'id', (int)$simId, [
                'status'         => 'allocated',
                'owner_org_id'   => $toOrgId,
                'owner_org_name' => $toOrgName,
                'updated_at'     => $now,
            ]);

            $this->logMovement([
                'sim_id'        => (int)$simId,
                'iccid'         => isset($sim['iccid']) ? $sim['iccid'] : '',
                'movement_type' => 'allocate',
                'from_org_id'   => $fromOrgId,
                'from_org_name' => $fromOrgName,
                'to_org_id'     => $toOrgId,
                'to_org_name'   => $toOrgName,
                'by_user_id'    => $byUserId,
                'created_at'    => $now,
            ]);

            $allocated++;
        }

        return ['allocated' => $allocated, 'errors' => $errors];
    }

    // ══════════════════════════════════════════════════════════
    // RETURN — retailer returns SIM to parent org
    // ══════════════════════════════════════════════════════════

    public function returnSim(int $simId, int $toOrgId, string $toOrgName, int $byUserId): array
    {
        $sim = $this->store->findOne(self::FILE, 'id', $simId);
        if (!$sim) return ['success' => false, 'message' => 'SIM not found.'];
        if (($sim['status'] ?? '') !== 'allocated') {
            return ['success' => false, 'message' => "SIM status is '{$sim['status']}', must be 'allocated' to return."];
        }

        $now = date('Y-m-d H:i:s');
        $this->store->updateOne(self::FILE, 'id', $simId, [
            'status'         => 'available',
            'owner_org_id'   => $toOrgId,
            'owner_org_name' => $toOrgName,
            'updated_at'     => $now,
        ]);

        $this->logMovement([
            'sim_id'        => $simId,
            'iccid'         => isset($sim['iccid']) ? $sim['iccid'] : '',
            'movement_type' => 'return',
            'from_org_id'   => isset($sim['owner_org_id']) ? (int)$sim['owner_org_id'] : 0,
            'from_org_name' => isset($sim['owner_org_name']) ? $sim['owner_org_name'] : '',
            'to_org_id'     => $toOrgId,
            'to_org_name'   => $toOrgName,
            'by_user_id'    => $byUserId,
            'created_at'    => $now,
        ]);

        return ['success' => true, 'message' => 'SIM returned.'];
    }

    // ══════════════════════════════════════════════════════════
    // ACTIVATE — retailer activates SIM for customer
    // ══════════════════════════════════════════════════════════

    /**
     * Activate a SIM: validates state, runs fraud check, debits wallet, changes status.
     * Idempotent via wallet idempotency key.
     */
    public function activate(
        int    $simId,
        string $customerName,
        string $customerPhone,
        int    $retailerId,
        string $retailerName,
        float  $activationFee = 5.00,
        string $idempotencyKey = ''
    ): array {
        $sim = $this->store->findOne(self::FILE, 'id', $simId);
        if (!$sim) return ['success' => false, 'message' => 'SIM not found.'];

        if (($sim['status'] ?? '') !== 'allocated') {
            return ['success' => false, 'message' => "SIM must be 'allocated'. Current: '{$sim['status']}'."];
        }

        // Verify retailer owns this SIM
        if ((int)(isset($sim['owner_org_id']) ? $sim['owner_org_id'] : 0) !== $retailerId) {
            return ['success' => false, 'message' => 'SIM not allocated to your organization.'];
        }

        // Fraud: velocity check
        $fraudResult = $this->velocityCheck($retailerId, isset($sim['msisdn']) ? $sim['msisdn'] : '');
        if (!$fraudResult['allowed']) {
            $this->logFraud($retailerId, $simId, $fraudResult['rule'], $fraudResult['score']);
            return ['success' => false, 'message' => 'Blocked: ' . $fraudResult['reason']];
        }

        // Debit wallet (idempotent)
        $walletEntry = null;
        if ($activationFee > 0) {
            $iKey = $idempotencyKey !== '' ? $idempotencyKey : ('SIM-ACT-' . $simId . '-' . date('Ymd'));
            try {
                $walletEntry = $this->wallet->debit(
                    $retailerId,
                    $activationFee,
                    'SIM activation: ' . (isset($sim['msisdn']) ? $sim['msisdn'] : $sim['iccid']),
                    'SIM-' . $simId,
                    null,
                    '',
                    $iKey,
                    WalletService::TRX_SIM_ACTIVATION,
                    'system'
                );
            } catch (\RuntimeException $e) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        // Update SIM status
        $now = date('Y-m-d H:i:s');
        $this->store->updateOne(self::FILE, 'id', $simId, [
            'status'                   => 'activated',
            'activated_customer_name'  => $customerName,
            'activated_customer_phone' => $customerPhone,
            'activated_at'             => $now,
            'activated_by_retailer_id' => $retailerId,
            'updated_at'              => $now,
        ]);

        $this->logMovement([
            'sim_id'        => $simId,
            'iccid'         => isset($sim['iccid']) ? $sim['iccid'] : '',
            'movement_type' => 'activate',
            'from_org_id'   => $retailerId,
            'from_org_name' => $retailerName,
            'to_org_id'     => $retailerId,
            'to_org_name'   => $customerName,
            'by_user_id'    => $retailerId,
            'customer_name' => $customerName,
            'customer_phone'=> $customerPhone,
            'created_at'    => $now,
        ]);

        return [
            'success'      => true,
            'message'      => 'SIM activated for ' . $customerName,
            'data'         => [
                'sim_id'       => $simId,
                'msisdn'       => isset($sim['msisdn']) ? $sim['msisdn'] : '',
                'customer'     => $customerName,
                'fee_charged'  => $activationFee,
                'wallet_trx'   => $walletEntry !== null ? (isset($walletEntry['trx_no']) ? $walletEntry['trx_no'] : '') : null,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════
    // STATUS CHANGE — generic state machine enforcement
    // ══════════════════════════════════════════════════════════

    public function changeStatus(int $simId, string $newStatus, int $byUserId, string $reason = ''): array
    {
        $sim = $this->store->findOne(self::FILE, 'id', $simId);
        if (!$sim) return ['success' => false, 'message' => 'SIM not found.'];

        $current = isset($sim['status']) ? $sim['status'] : 'unknown';
        $allowed = isset(self::TRANSITIONS[$current]) ? self::TRANSITIONS[$current] : [];

        if (!in_array($newStatus, $allowed, true)) {
            return [
                'success' => false,
                'message' => "Cannot transition from '{$current}' to '{$newStatus}'. Allowed: " . implode(', ', $allowed),
            ];
        }

        $this->store->updateOne(self::FILE, 'id', $simId, [
            'status'     => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logMovement([
            'sim_id'        => $simId,
            'iccid'         => isset($sim['iccid']) ? $sim['iccid'] : '',
            'movement_type' => 'status_change',
            'from_status'   => $current,
            'to_status'     => $newStatus,
            'reason'        => $reason,
            'by_user_id'    => $byUserId,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => "SIM status changed: {$current} -> {$newStatus}"];
    }

    // ══════════════════════════════════════════════════════════
    // FRAUD — velocity checks
    // ══════════════════════════════════════════════════════════

    /**
     * Check activation velocity limits.
     * Returns ['allowed'=>bool, 'rule'=>string, 'reason'=>string, 'score'=>int]
     */
    public function velocityCheck(int $retailerId, string $msisdn): array
    {
        $movements = $this->store->load(self::MOVES_FILE);
        $now       = time();
        $oneHourAgo = date('Y-m-d H:i:s', $now - 3600);
        $oneDayAgo  = date('Y-m-d H:i:s', $now - 86400);

        // Rule 1: retailer activations per hour
        $retailerHourly = 0;
        foreach ($movements as $m) {
            if (($m['movement_type'] ?? '') === 'activate'
                && (isset($m['by_user_id']) ? (int)$m['by_user_id'] : 0) === $retailerId
                && (isset($m['created_at']) ? $m['created_at'] : '') >= $oneHourAgo) {
                $retailerHourly++;
            }
        }
        if ($retailerHourly >= self::MAX_ACTIVATIONS_PER_RETAILER_PER_HOUR) {
            return [
                'allowed' => false,
                'rule'    => 'F-SIM-01',
                'reason'  => "Exceeded {$retailerHourly}/" . self::MAX_ACTIVATIONS_PER_RETAILER_PER_HOUR . " activations per hour.",
                'score'   => 70,
            ];
        }

        // Rule 2: same MSISDN (SIM number) re-activated too many times per day
        // BUG FIX: The original code compared customer_phone against msisdn —
        // two completely different fields. customer_phone is the buyer's phone;
        // msisdn is the SIM's own number. The rule's purpose is to prevent the
        // same physical SIM from being activated/deactivated/reactivated in a
        // churn loop. We compare movement msisdn against the SIM's msisdn.
        if ($msisdn !== '') {
            $msisdnDaily = 0;
            foreach ($movements as $m) {
                if (($m['movement_type'] ?? '') === 'activate'
                    && (isset($m['msisdn']) ? $m['msisdn'] : '') === $msisdn
                    && (isset($m['created_at']) ? $m['created_at'] : '') >= $oneDayAgo) {
                    $msisdnDaily++;
                }
            }
            if ($msisdnDaily >= self::MAX_ACTIVATIONS_PER_MSISDN_PER_DAY) {
                return [
                    'allowed' => false,
                    'rule'    => 'F-SIM-02',
                    'reason'  => "MSISDN {$msisdn} activated {$msisdnDaily} times in 24h.",
                    'score'   => 50,
                ];
            }
        }

        // Rule 3: off-hours bonus score (not blocking, just logged)
        $hour = (int)date('H');
        $score = ($hour >= 23 || $hour < 5) ? 15 : 0;

        return ['allowed' => true, 'rule' => '', 'reason' => '', 'score' => $score];
    }

    // ══════════════════════════════════════════════════════════
    // QUERIES
    // ══════════════════════════════════════════════════════════

    /** Get SIMs owned by an org, optionally filtered by status */
    public function getInventory(int $orgId, string $status = '', int $page = 1, int $perPage = 50): array
    {
        $all = $this->store->load(self::FILE);
        $filtered = [];
        foreach ($all as $s) {
            if ((isset($s['owner_org_id']) ? (int)$s['owner_org_id'] : 0) !== $orgId) continue;
            if ($status !== '' && (isset($s['status']) ? $s['status'] : '') !== $status) continue;
            $filtered[] = $s;
        }

        $total  = count($filtered);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($filtered, $offset, $perPage);

        return [
            'sims'       => $paged,
            'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ];
    }

    /** Stock summary: count by status for an org */
    public function getStockSummary(int $orgId): array
    {
        $all = $this->store->load(self::FILE);
        $summary = ['available' => 0, 'allocated' => 0, 'activated' => 0, 'suspended' => 0, 'expired' => 0, 'blacklisted' => 0, 'total' => 0];

        foreach ($all as $s) {
            if ((isset($s['owner_org_id']) ? (int)$s['owner_org_id'] : 0) !== $orgId) continue;
            $st = isset($s['status']) ? $s['status'] : 'unknown';
            if (isset($summary[$st])) $summary[$st]++;
            $summary['total']++;
        }
        return $summary;
    }

    /** Movement history for a SIM */
    public function getMovements(int $simId): array
    {
        return $this->store->findAll(self::MOVES_FILE, 'sim_id', $simId);
    }

    /** Single SIM by ID */
    public function getById(int $simId): ?array
    {
        return $this->store->findOne(self::FILE, 'id', $simId);
    }

    /** Single SIM by ICCID */
    public function getByIccid(string $iccid): ?array
    {
        return $this->store->findOne(self::FILE, 'iccid', $iccid);
    }

    // ══════════════════════════════════════════════════════════
    // INTERNAL
    // ══════════════════════════════════════════════════════════

    private function logMovement(array $data): void
    {
        $this->store->appendWithId(self::MOVES_FILE, $data);
    }

    private function logFraud(int $retailerId, int $simId, string $rule, int $score): void
    {
        $this->store->appendWithId(self::FRAUD_FILE, [
            'retailer_id' => $retailerId,
            'sim_id'      => $simId,
            'rule'        => $rule,
            'score'       => $score,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
