<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * FieldAgentService
 *
 * Handles the complete cash collection and remittance workflow for
 * field agents (e.g. Diko) who collect physical cash from customers
 * and periodically remit it to company accountants (Rupesh / Nirav).
 *
 * ── Cash balance concept ────────────────────────────────────────────────
 *
 *   cash_balance = SUM(collections) − SUM(approved remittances)
 *
 *   When Diko collects $200 from a customer  →  cash_balance +200
 *   When Diko remits   $500 to Rupesh        →  cash_balance −500  (after approval)
 *
 *   cash_balance represents money Diko is physically holding that has
 *   not yet been handed over to the company. It should always move
 *   toward zero after every remittance cycle.
 *
 * ── Collection record ───────────────────────────────────────────────────
 * {
 *   "id":               1,
 *   "agent_id":         5,
 *   "agent_name":       "Diko",
 *   "amount":           200.00,
 *   "collection_type":  "kyc|subscription|sim_activation|other",
 *   "customer_name":    "James Okello",
 *   "customer_phone":   "+211912345678",
 *   "reference":        "STAR000045",        ← invoice no / username / KYC app ID
 *   "note":             "Monthly bill Jan",
 *   "collected_at":     "2026-03-04 10:00:00",
 *   "created_at":       "2026-03-04 10:00:00"
 * }
 *
 * ── Remittance record ───────────────────────────────────────────────────
 * {
 *   "id":               1,
 *   "agent_id":         5,
 *   "agent_name":       "Diko",
 *   "amount":           500.00,
 *   "remitted_to":      "Rupesh",
 *   "note":             "Cash bag #12",
 *   "status":           "pending|approved|rejected",
 *   "approved_by":      null,
 *   "approved_at":      null,
 *   "rejection_reason": null,
 *   "created_at":       "2026-03-04 14:00:00",
 *   "updated_at":       "2026-03-04 14:00:00"
 * }
 */
class FieldAgentService
{
    const COLLECTIONS_FILE  = 'field_collections.json';
    const REMITTANCES_FILE  = 'field_remittances.json';
    const LOG_FILE          = 'activity_log.json';

    const COLLECTION_TYPES  = ['kyc', 'subscription', 'sim_activation', 'other'];
    const MAX_COLLECTION    = 50000;

    private  $store;

    public function __construct( $store)
    {
        $this->store = $store;
    }

    // ══════════════════════════════════════════════════════════════════════
    // COLLECTIONS — logged by field agent (Diko)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Log a cash collection from a customer.
     *
     * @param array $post     ['amount', 'collection_type', 'customer_name',
     *                         'customer_phone', 'reference', 'note', 'collected_at']
     * @param array $agent    Logged-in field agent retailer record
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array]
     */
    public function logCollection(array $post, array $agent): array
    {
        if (!$this->isFieldAgent($agent)) {
            return ['success' => false, 'message' => 'Access denied: field agent role required.'];
        }

        $amount = round((float)($post['amount'] ?? 0), 2);
        if ($amount <= 0 || $amount > self::MAX_COLLECTION) {
            return ['success' => false, 'message' => "Amount must be between \$0.01 and \$" . number_format(self::MAX_COLLECTION)];
        }

        $type = trim($post['collection_type'] ?? 'other');
        if (!in_array($type, self::COLLECTION_TYPES, true)) {
            $type = 'other';
        }

        $customerName  = trim($post['customer_name'] ?? '');
        $customerPhone = trim($post['customer_phone'] ?? '');
        $reference     = trim($post['reference'] ?? '');
        $note          = trim($post['note'] ?? '');
        $collectedAt   = trim($post['collected_at'] ?? date('Y-m-d H:i:s'));

        // Validate collected_at format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $collectedAt)) {
            $collectedAt = date('Y-m-d H:i:s');
        }

        $record = $this->store->appendWithId(self::COLLECTIONS_FILE, [
            'agent_id'        => (int)$agent['id'],
            'agent_name'      => $agent['name'],
            'amount'          => $amount,
            'collection_type' => $type,
            'customer_name'   => $customerName,
            'customer_phone'  => $customerPhone,
            'reference'       => $reference,
            'note'            => $note,
            'collected_at'    => $collectedAt,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->log(
            'collection_logged',
            $agent['name'],
            "Collected \${$amount} ({$type}) from {$customerName}" . ($reference ? " [Ref: {$reference}]" : ''),
            $record['id']
        );

        return [
            'success' => true,
            'message' => "\${$amount} collection logged successfully.",
            'data'    => [
                'collection_id' => $record['id'],
                'cash_balance'  => $this->getCashBalance((int)$agent['id']),
            ],
        ];
    }

    /**
     * Get all collections for an agent (or all agents if admin).
     */
    public function getCollections(int $agentId, bool $isAdmin = false, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $all = $this->store->load(self::COLLECTIONS_FILE);

        if (!$isAdmin) {
            $all = array_values(array_filter($all, fn($c) => (int)($c['agent_id'] ?? 0) === $agentId));
        }

        if ($dateFrom) {
            $all = array_values(array_filter($all, fn($c) => ($c['collected_at'] ?? '') >= $dateFrom));
        }
        if ($dateTo) {
            $all = array_values(array_filter($all, fn($c) => ($c['collected_at'] ?? '') <= $dateTo . ' 23:59:59'));
        }

        usort($all, fn($a, $b) => strcmp($b['collected_at'] ?? '', $a['collected_at'] ?? ''));
        return $all;
    }

    // ══════════════════════════════════════════════════════════════════════
    // REMITTANCES — submitted by agent, approved by accountant admin
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Agent submits a remittance request (they are about to hand over cash).
     *
     * @param array $post   ['amount', 'remitted_to', 'note']
     * @param array $agent  Logged-in field agent
     * @return array
     */
    public function submitRemittance(array $post, array $agent): array
    {
        if (!$this->isFieldAgent($agent)) {
            return ['success' => false, 'message' => 'Access denied: field agent role required.'];
        }

        $amount = round((float)($post['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Please enter a valid remittance amount.'];
        }

        // Cannot remit more than current cash balance
        $cashBalance = $this->getCashBalance((int)$agent['id']);
        if ($amount > $cashBalance) {
            return [
                'success' => false,
                'message' => "Cannot remit \${$amount}. Your current cash balance is \$" . number_format($cashBalance, 2) . '.',
            ];
        }

        $remittedTo = trim($post['remitted_to'] ?? '');
        if ($remittedTo === '') {
            return ['success' => false, 'message' => 'Please specify who you are remitting to (e.g. Rupesh or Nirav).'];
        }

        $now    = date('Y-m-d H:i:s');
        $record = $this->store->appendWithId(self::REMITTANCES_FILE, [
            'agent_id'         => (int)$agent['id'],
            'agent_name'       => $agent['name'],
            'amount'           => $amount,
            'remitted_to'      => $remittedTo,
            'note'             => trim($post['note'] ?? ''),
            'status'           => 'pending',
            'approved_by'      => null,
            'approved_at'      => null,
            'rejection_reason' => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $this->log(
            'remittance_submitted',
            $agent['name'],
            "Submitted remittance of \${$amount} to {$remittedTo}",
            $record['id']
        );

        return [
            'success' => true,
            'message' => "Remittance of \${$amount} to {$remittedTo} submitted. Awaiting approval.",
            'data'    => [
                'remittance_id' => $record['id'],
                'cash_balance'  => $cashBalance, // balance not yet reduced; reduced on approval
            ],
        ];
    }

    /**
     * Accountant admin approves a remittance.
     * This reduces the agent's effective cash balance.
     *
     * @param int   $remittanceId
     * @param array $admin   Logged-in admin (Rupesh or Nirav)
     * @return array
     */
    public function approveRemittance(int $remittanceId, array $admin): array
    {
        if (empty($admin['is_admin'])) {
            return ['success' => false, 'message' => 'Admin access required.'];
        }

        $req = $this->store->findOne(self::REMITTANCES_FILE, 'id', $remittanceId);
        if (!$req) {
            return ['success' => false, 'message' => 'Remittance request not found.'];
        }
        if ($req['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Request is already ' . $req['status'] . '.'];
        }

        $now = date('Y-m-d H:i:s');
        $this->store->updateOne(self::REMITTANCES_FILE, 'id', $remittanceId, [
            'status'      => 'approved',
            'approved_by' => $admin['name'],
            'approved_at' => $now,
            'updated_at'  => $now,
        ]);

        $this->log(
            'remittance_approved',
            $admin['name'],
            "Approved remittance #{$remittanceId} — \${$req['amount']} from {$req['agent_name']} to {$req['remitted_to']}",
            $remittanceId
        );

        return [
            'success' => true,
            'message' => "\${$req['amount']} remittance from {$req['agent_name']} approved.",
            'data'    => [
                'remittance_id' => $remittanceId,
                'agent_cash_balance' => $this->getCashBalance((int)$req['agent_id']),
            ],
        ];
    }

    /**
     * Accountant admin rejects a remittance (e.g. amount mismatch).
     */
    public function rejectRemittance(int $remittanceId, string $reason, array $admin): array
    {
        if (empty($admin['is_admin'])) {
            return ['success' => false, 'message' => 'Admin access required.'];
        }

        $req = $this->store->findOne(self::REMITTANCES_FILE, 'id', $remittanceId);
        if (!$req) {
            return ['success' => false, 'message' => 'Remittance request not found.'];
        }
        if ($req['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Request is already ' . $req['status'] . '.'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'Please provide a rejection reason.'];
        }

        $now = date('Y-m-d H:i:s');
        $this->store->updateOne(self::REMITTANCES_FILE, 'id', $remittanceId, [
            'status'           => 'rejected',
            'approved_by'      => $admin['name'],
            'approved_at'      => $now,
            'rejection_reason' => $reason,
            'updated_at'       => $now,
        ]);

        $this->log(
            'remittance_rejected',
            $admin['name'],
            "Rejected remittance #{$remittanceId} from {$req['agent_name']} — Reason: {$reason}",
            $remittanceId
        );

        return [
            'success' => true,
            'message' => "Remittance #{$remittanceId} rejected.",
        ];
    }

    /**
     * Get remittances (for agent: own only; for admin: all or filtered by agent).
     */
    public function getRemittances(int $agentId, bool $isAdmin = false, string $status = ''): array
    {
        $all = $this->store->load(self::REMITTANCES_FILE);

        if (!$isAdmin) {
            $all = array_values(array_filter($all, fn($r) => (int)($r['agent_id'] ?? 0) === $agentId));
        }

        if ($status !== '') {
            $all = array_values(array_filter($all, fn($r) => ($r['status'] ?? '') === $status));
        }

        usort($all, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return $all;
    }

    // ══════════════════════════════════════════════════════════════════════
    // BALANCE & REPORTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Calculate how much cash an agent is currently holding.
     *
     *   cash_balance = total_collected − total_approved_remittances
     *
     * Pending remittances are NOT subtracted yet (only approved ones are).
     */
    public function getCashBalance(int $agentId): float
    {
        $collections = $this->store->findAll(self::COLLECTIONS_FILE, 'agent_id', $agentId);
        $totalCollected = array_sum(array_map(fn($c) => (float)($c['amount'] ?? 0), $collections));

        $remittances = $this->store->findAll(self::REMITTANCES_FILE, 'agent_id', $agentId);
        $totalRemitted = 0.0;
        foreach ($remittances as $r) {
            if (($r['status'] ?? '') === 'approved') {
                $totalRemitted += (float)($r['amount'] ?? 0);
            }
        }

        return round($totalCollected - $totalRemitted, 2);
    }

    /**
     * Full summary for an agent — used on their dashboard.
     */
    public function getAgentSummary(int $agentId): array
    {
        $collections = $this->store->findAll(self::COLLECTIONS_FILE, 'agent_id', $agentId);
        $remittances = $this->store->findAll(self::REMITTANCES_FILE, 'agent_id', $agentId);

        $totalCollected   = array_sum(array_map(fn($c) => (float)($c['amount'] ?? 0), $collections));
        $totalRemitted    = 0.0;
        $pendingRemittance = 0.0;

        foreach ($remittances as $r) {
            if (($r['status'] ?? '') === 'approved') {
                $totalRemitted += (float)($r['amount'] ?? 0);
            } elseif (($r['status'] ?? '') === 'pending') {
                $pendingRemittance += (float)($r['amount'] ?? 0);
            }
        }

        // Breakdown by collection type
        $byType = [];
        foreach (self::COLLECTION_TYPES as $t) {
            $byType[$t] = array_sum(array_map(
                fn($c) => ($c['collection_type'] ?? '') === $t ? (float)($c['amount'] ?? 0) : 0,
                $collections
            ));
        }

        // Today's collections
        $today = date('Y-m-d');
        $todayAmount = array_sum(array_map(
            fn($c) => str_starts_with($c['collected_at'] ?? '', $today) ? (float)($c['amount'] ?? 0) : 0,
            $collections
        ));

        return [
            'agent_id'          => $agentId,
            'cash_balance'      => round($totalCollected - $totalRemitted, 2),
            'total_collected'   => round($totalCollected, 2),
            'total_remitted'    => round($totalRemitted, 2),
            'pending_remittance'=> round($pendingRemittance, 2),
            'today_collected'   => round($todayAmount, 2),
            'collection_count'  => count($collections),
            'by_type'           => $byType,
        ];
    }

    /**
     * Daily collection summary — for admin report view.
     * Groups all agents' collections by date.
     */
    public function getDailySummary(?int $agentId = null, int $days = 30): array
    {
        $all = $this->store->load(self::COLLECTIONS_FILE);

        if ($agentId !== null) {
            $all = array_values(array_filter($all, fn($c) => (int)($c['agent_id'] ?? 0) === $agentId));
        }

        $cutoff = date('Y-m-d', strtotime("-{$days} days"));
        $all    = array_filter($all, fn($c) => substr($c['collected_at'] ?? '', 0, 10) >= $cutoff);

        $grouped = [];
        foreach ($all as $c) {
            $date      = substr($c['collected_at'] ?? date('Y-m-d'), 0, 10);
            $agentName = $c['agent_name'] ?? 'Unknown';
            $key       = $date . '|' . $agentName;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'date'       => $date,
                    'agent_name' => $agentName,
                    'agent_id'   => (int)($c['agent_id'] ?? 0),
                    'total'      => 0.0,
                    'count'      => 0,
                    'by_type'    => [],
                ];
            }
            $grouped[$key]['total'] += (float)($c['amount'] ?? 0);
            $grouped[$key]['count']++;
            $t = $c['collection_type'] ?? 'other';
            $grouped[$key]['by_type'][$t] = ($grouped[$key]['by_type'][$t] ?? 0) + (float)($c['amount'] ?? 0);
        }

        $result = array_values($grouped);
        usort($result, fn($a, $b) => strcmp($b['date'], $a['date']));
        return $result;
    }

    /**
     * Pending remittances report — for accountants (Rupesh/Nirav).
     * Shows all agents with outstanding cash balances and pending requests.
     */
    public function getPendingRemittanceReport(): array
    {
        $allRemittances = $this->store->load(self::REMITTANCES_FILE);
        $pending = array_values(array_filter($allRemittances, fn($r) => ($r['status'] ?? '') === 'pending'));

        usort($pending, fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));

        // Enrich with agent cash balance
        $agentBalances = [];
        foreach ($pending as &$r) {
            $aid = (int)($r['agent_id'] ?? 0);
            if (!isset($agentBalances[$aid])) {
                $agentBalances[$aid] = $this->getCashBalance($aid);
            }
            $r['agent_cash_balance'] = $agentBalances[$aid];
        }
        unset($r);

        return $pending;
    }

    /**
     * Per-customer collection history — search by phone, name, or reference.
     */
    public function searchCollections(string $query, ?int $agentId = null): array
    {
        $all = $this->store->load(self::COLLECTIONS_FILE);

        if ($agentId !== null) {
            $all = array_values(array_filter($all, fn($c) => (int)($c['agent_id'] ?? 0) === $agentId));
        }

        $q = strtolower(trim($query));
        if ($q !== '') {
            $all = array_values(array_filter($all, function ($c) use ($q) {
                return str_contains(strtolower($c['customer_name']  ?? ''), $q)
                    || str_contains(strtolower($c['customer_phone'] ?? ''), $q)
                    || str_contains(strtolower($c['reference']      ?? ''), $q)
                    || str_contains(strtolower($c['note']           ?? ''), $q);
            }));
        }

        usort($all, fn($a, $b) => strcmp($b['collected_at'] ?? '', $a['collected_at'] ?? ''));
        return array_slice($all, 0, 200);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A field agent is any retailer with role='field_agent' OR is_field_agent=true.
     * Admin accounts can also act as field agents if flagged.
     */
    public function isFieldAgent(array $retailer): bool
    {
        return ($retailer['role'] ?? '') === 'field_agent'
            || !empty($retailer['is_field_agent']);
    }

    /**
     * Get all field agents (for admin dropdown / report filter).
     */
    public function getAllFieldAgents(): array
    {
        $all = $this->store->load('retailers.json');
        return array_values(array_filter($all, fn($r) => $this->isFieldAgent($r)));
    }

    private function log(string $event, string $actor, string $detail, ?int $refId = null): void
    {
        $this->store->appendWithId(self::LOG_FILE, [
            'event'      => $event,
            'actor'      => $actor,
            'detail'     => $detail,
            'ref_id'     => $refId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
