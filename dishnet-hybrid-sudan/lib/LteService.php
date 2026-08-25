<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

require_once __DIR__ . '/StoreInterface.php';

/**
 * LteService — DishNet Africa
 * Business logic layer for DishNet 4G (Magma/Baicells) subscriber management.
 *
 * Manages:
 *  - Subscriber registry (lte_subscribers.json)
 *  - SIM card inventory  (lte_sims.json)
 *  - CPE/Hardware        (lte_hardware.json)
 *  - Data packages       (lte_packages.json)
 *  - Subscriptions       (lte_subscriptions.json)
 *  - Renewal history     (lte_renewals.json)
 *  - Usage cache         (lte_usage_cache.json)
 */
class LteService
{
    private StoreInterface  $store;
    private MagmaApiClient  $magma;
    private bool            $magmaEnabled;
    private ?\PDO           $pdo;

    public function __construct(StoreInterface $store, MagmaApiClient $magma, ?\PDO $pdo = null)
    {
        $this->store        = $store;
        $this->magma        = $magma;
        $this->magmaEnabled = $magma->isConfigured();
        $this->pdo          = $pdo;
    }

    /**
     * Load subscribers — SQLite if available (synced from BlueCard), JSON fallback.
     * SQLite has 6,631 records from BlueCard sync; JSON only has manually-added ones.
     */
    private function loadSubscribers(): array
    {
        if ($this->pdo) {
            try {
                $rows = $this->pdo->query(
                    "SELECT * FROM lte_subscribers WHERE deleted_at IS NULL ORDER BY id ASC"
                )->fetchAll(\PDO::FETCH_ASSOC);
                return $rows ?: [];
            } catch (\Throwable $e) {}
        }
        return $this->store->load('lte_subscribers.json') ?? [];
    }

    private function loadPackages(): array
    {
        if ($this->pdo) {
            try {
                $rows = $this->pdo->query("SELECT * FROM lte_packages WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) return $rows;
            } catch (\Throwable $e) {}
        }
        return $this->store->load('lte_packages.json') ?? [];
    }

    private function loadSubscriptions(): array
    {
        if ($this->pdo) {
            try {
                $rows = $this->pdo->query("SELECT * FROM lte_subscriptions ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) return $rows;
            } catch (\Throwable $e) {}
        }
        return $this->store->load('lte_subscriptions.json') ?? [];
    }

    private function loadRenewals(): array
    {
        if ($this->pdo) {
            try {
                $rows = $this->pdo->query("SELECT * FROM lte_renewals ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) return $rows;
            } catch (\Throwable $e) {}
        }
        return $this->store->load('lte_renewals.json') ?? [];
    }

    private function loadSims(): array
    {
        if ($this->pdo) {
            try {
                $rows = $this->pdo->query("SELECT * FROM lte_sims ORDER BY id ASC")->fetchAll(\PDO::FETCH_ASSOC);
                if ($rows) return $rows;
            } catch (\Throwable $e) {}
        }
        return $this->store->load('lte_sims.json') ?? [];
    }


    /* ═══════════════════════════════════════════════════════
       SUBSCRIBER REGISTRY
    ═══════════════════════════════════════════════════════ */

    /**
     * Get subscribers with optional pagination.
     * When $filters['page'] is set, returns a paged envelope:
     *   ['data'=>[...], 'total'=>N, 'page'=>N, 'per_page'=>N, 'pages'=>N]
     * Without 'page', returns plain array (back-compat for getRenewalQueue).
     */
    public function getSubscribers(array $filters = []): array
    {
        $subs = $this->loadSubscribers();

        if (!empty($filters['status'])) {
            $st = $filters['status'];
            $subs = array_values(array_filter($subs, function($s) use ($st) {
                return ($s['status'] ?? '') === $st;
            }));
        }
        if (!empty($filters['agent_id'])) {
            $aid = (int)$filters['agent_id'];
            $subs = array_values(array_filter($subs, function($s) use ($aid) {
                return (int)($s['agent_id'] ?? 0) === $aid;
            }));
        }
        if (!empty($filters['search'])) {
            $q = strtolower($filters['search']);
            $subs = array_values(array_filter($subs, function($s) use ($q) {
                return strpos(strtolower($s['name'] ?? ''), $q) !== false
                    || strpos($s['msisdn'] ?? '', $q) !== false
                    || strpos($s['imsi']   ?? '', $q) !== false
                    || strpos(strtolower($s['address'] ?? ''), $q) !== false;
            }));
        }

        // Pre-load maps once — O(1) lookup during enrichment regardless of page size
        $allSubscriptions = $this->loadSubscriptions();
        $allPackages      = $this->loadPackages();

        $activeSubMap = [];
        foreach ($allSubscriptions as $s) {
            if (($s['status'] ?? '') === 'active') {
                $activeSubMap[(int)($s['subscriber_id'] ?? 0)] = $s;
            }
        }
        $packageMap = [];
        foreach ($allPackages as $p) {
            $packageMap[(int)($p['id'] ?? 0)] = $p;
        }

        // Sort by expiry asc (expired first) before slicing
        usort($subs, function($a, $b) use ($activeSubMap) {
            $sa = $activeSubMap[(int)($a['id'] ?? 0)] ?? null;
            $sb = $activeSubMap[(int)($b['id'] ?? 0)] ?? null;
            $ea = $sa ? ($sa['expires_at'] ?? '9999-99-99') : '9999-99-99';
            $eb = $sb ? ($sb['expires_at'] ?? '9999-99-99') : '9999-99-99';
            return strcmp($ea, $eb);
        });

        $total   = count($subs);
        $page    = isset($filters['page']) ? max(1, (int)$filters['page']) : null;
        $perPage = isset($filters['per_page']) ? max(10, min(200, (int)$filters['per_page'])) : 50;

        $slice = ($page !== null) ? array_slice($subs, ($page - 1) * $perPage, $perPage) : $subs;

        // Enrich only the slice — not all 6,600+ records
        $enriched = array_map(function($s) use ($activeSubMap, $packageMap) {
            return $this->enrichSubscriber($s, $activeSubMap, $packageMap);
        }, $slice);

        if ($page !== null) {
            return [
                'data'     => $enriched,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int)ceil($total / $perPage),
            ];
        }

        return $enriched;
    }

    public function getSubscriber(int $id): ?array
    {
        $sub = $this->store->findOne('lte_subscribers.json', 'id', $id);
        if (!$sub) return null;

        // Single-subscriber call: build maps inline (2 file reads, still O(1) for one sub)
        $allSubscriptions = $this->loadSubscriptions();
        $allPackages      = $this->loadPackages();

        $activeSubMap = [];
        foreach ($allSubscriptions as $s) {
            if (($s['status'] ?? '') === 'active') {
                $activeSubMap[(int)($s['subscriber_id'] ?? 0)] = $s;
            }
        }
        $packageMap = [];
        foreach ($allPackages as $p) {
            $packageMap[(int)($p['id'] ?? 0)] = $p;
        }

        return $this->enrichSubscriber($sub, $activeSubMap, $packageMap);
    }

    public function createSubscriber(array $data, int $agentId, string $agentName): array
    {
        $record = [
            'name'         => trim($data['name'] ?? ''),
            'phone'        => trim($data['phone'] ?? ''),
            'email'        => trim($data['email'] ?? ''),
            'address'      => trim($data['address'] ?? ''),
            'id_type'      => trim($data['id_type'] ?? ''),
            'id_number'    => trim($data['id_number'] ?? ''),
            'gps_lat'      => (float)($data['gps_lat'] ?? 0) ?: null,
            'gps_lon'      => (float)($data['gps_lon'] ?? 0) ?: null,
            'imsi'         => trim($data['imsi'] ?? ''),
            'msisdn'       => trim($data['msisdn'] ?? ''),
            'sim_id'       => (int)($data['sim_id'] ?? 0) ?: null,
            'hardware_id'  => (int)($data['hardware_id'] ?? 0) ?: null,
            'ucrm_id'      => (int)($data['ucrm_id'] ?? 0) ?: null,
            'status'       => 'active',
            'service_type' => 'lte',
            'agent_id'     => $agentId,
            'agent_name'   => $agentName,
            'notes'        => trim($data['notes'] ?? ''),
            'bluecard_id'  => (int)($data['bluecard_id'] ?? 0) ?: null,
            'registered_by'=> trim($data['registered_by'] ?? 'staff'),
            'created_at'   => date('Y-m-d H:i:s'),
        ];

        $saved = $this->store->appendWithId('lte_subscribers.json', $record);

        // Mark SIM as assigned
        if ($saved['sim_id']) {
            $this->store->updateOne('lte_sims.json', 'id', $saved['sim_id'], [
                'status'        => 'assigned',
                'subscriber_id' => $saved['id'],
                'assigned_at'   => date('Y-m-d H:i:s'),
            ]);
        }
        // Mark hardware as deployed
        if ($saved['hardware_id']) {
            $this->store->updateOne('lte_hardware.json', 'id', $saved['hardware_id'], [
                'status'        => 'deployed',
                'subscriber_id' => $saved['id'],
                'deployed_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        return $saved;
    }

    public function updateSubscriber(int $id, array $data): bool
    {
        $allowed = ['name','phone','email','address','id_type','id_number',
                    'gps_lat','gps_lon','ucrm_id','status','notes'];
        $updates = array_intersect_key($data, array_flip($allowed));
        $updates['updated_at'] = date('Y-m-d H:i:s');
        return $this->store->updateOne('lte_subscribers.json', 'id', $id, $updates);
    }

    /**
     * Enrich a subscriber record with subscription + expiry + package data.
     *
     * B-07 FIX: Parameters $activeSubMap and $packageMap are pre-built by the
     * caller with a single file read each. This method does ZERO file I/O —
     * all lookups are O(1) array access instead of O(n) file reads per subscriber.
     *
     * @param array $activeSubMap  [subscriber_id => active subscription record]
     * @param array $packageMap    [package_id    => package record]
     */
    private function enrichSubscriber(array $sub, array $activeSubMap, array $packageMap): array
    {
        $active = $activeSubMap[(int)($sub['id'] ?? 0)] ?? null;
        $sub['_subscription'] = $active;

        // Compute expiry status
        if ($active) {
            $exp  = $active['expires_at'] ?? '';
            $days = $exp ? (int)ceil((strtotime($exp) - time()) / 86400) : null;
            $sub['_days_remaining'] = $days;
            $sub['_expiry_status']  = $days === null ? 'unknown'
                : ($days < 0 ? 'expired' : ($days === 0 ? 'today' : ($days <= 3 ? 'critical' : ($days <= 7 ? 'warning' : 'ok'))));
        } else {
            $sub['_days_remaining'] = null;
            $sub['_expiry_status']  = 'no_plan';
        }

        // Attach package record — O(1) map lookup, no file I/O
        if ($active && !empty($active['package_id'])) {
            $sub['_package'] = $packageMap[(int)$active['package_id']] ?? null;
        }

        return $sub;
    }

    /* ═══════════════════════════════════════════════════════
       SIM INVENTORY
    ═══════════════════════════════════════════════════════ */

    public function getSims(array $filters = []): array
    {
        $sims = $this->loadSims();
        if (!empty($filters['status'])) {
            $sims = array_filter($sims, fn($s) => ($s['status'] ?? '') === $filters['status']);
        }
        if (!empty($filters['search'])) {
            $q = strtolower($filters['search']);
            $sims = array_filter($sims, fn($s) =>
                str_contains($s['imsi'] ?? '', $q) ||
                str_contains($s['msisdn'] ?? '', $q) ||
                str_contains(strtolower($s['batch'] ?? ''), $q)
            );
        }
        return array_values($sims);
    }

    public function createSim(array $data): array
    {
        $record = [
            'imsi'          => trim($data['imsi'] ?? ''),
            'msisdn'        => trim($data['msisdn'] ?? ''),
            'iccid'         => trim($data['iccid'] ?? ''),
            'batch'         => trim($data['batch'] ?? ''),
            'operator'      => 'DishNet 4G',
            'auth_key'      => trim($data['auth_key'] ?? ''),
            'auth_opc'      => trim($data['auth_opc'] ?? ''),
            'status'        => 'stock',
            'subscriber_id' => null,
            'assigned_at'   => null,
            'purchased_at'  => trim($data['purchased_at'] ?? date('Y-m-d')),
            'notes'         => trim($data['notes'] ?? ''),
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        return $this->store->appendWithId('lte_sims.json', $record);
    }

    public function getSimCounts(): array
    {
        $sims = $this->loadSims();
        $counts = ['stock' => 0, 'assigned' => 0, 'active' => 0, 'suspended' => 0, 'retired' => 0, 'total' => 0];
        foreach ($sims as $s) {
            $st = $s['status'] ?? 'stock';
            $counts[$st] = ($counts[$st] ?? 0) + 1;
            $counts['total']++;
        }
        return $counts;
    }

    /* ═══════════════════════════════════════════════════════
       HARDWARE (CPE / MiFi)
    ═══════════════════════════════════════════════════════ */

    public function getHardware(array $filters = []): array
    {
        $hw = $this->store->load('lte_hardware.json');
        if (!empty($filters['status'])) {
            $hw = array_filter($hw, fn($h) => ($h['status'] ?? '') === $filters['status']);
        }
        if (!empty($filters['type'])) {
            $hw = array_filter($hw, fn($h) => ($h['type'] ?? '') === $filters['type']);
        }
        if (!empty($filters['search'])) {
            $q = strtolower($filters['search']);
            $hw = array_filter($hw, fn($h) =>
                str_contains(strtolower($h['serial'] ?? ''), $q) ||
                str_contains(strtolower($h['model'] ?? ''), $q)
            );
        }
        return array_values($hw);
    }

    public function createHardware(array $data): array
    {
        $record = [
            'type'          => trim($data['type'] ?? 'mifi'), // mifi | outdoor_cpe
            'brand'         => trim($data['brand'] ?? 'Baicells'),
            'model'         => trim($data['model'] ?? ''),
            'serial'        => trim($data['serial'] ?? ''),
            'mac'           => trim($data['mac'] ?? ''),
            'status'        => 'warehouse',
            'subscriber_id' => null,
            'deployed_at'   => null,
            'gps_lat'       => null,
            'gps_lon'       => null,
            'purchase_date' => trim($data['purchase_date'] ?? date('Y-m-d')),
            'purchase_cost' => (float)($data['purchase_cost'] ?? 0),
            'notes'         => trim($data['notes'] ?? ''),
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        return $this->store->appendWithId('lte_hardware.json', $record);
    }

    /* ═══════════════════════════════════════════════════════
       PACKAGE CATALOG
    ═══════════════════════════════════════════════════════ */

    public function getPackages(bool $activeOnly = false): array
    {
        $pkgs = $this->loadPackages();
        if ($activeOnly) {
            $pkgs = array_filter($pkgs, fn($p) => (bool)($p['active'] ?? true));
        }
        // Enrich with subscriber count
        $subs = $this->loadSubscriptions();
        return array_map(function($p) use ($subs) {
            $p['_subscriber_count'] = count(array_filter($subs,
                fn($s) => (int)($s['package_id'] ?? 0) === (int)$p['id'] && ($s['status'] ?? '') === 'active'));
            return $p;
        }, array_values($pkgs));
    }

    public function createPackage(array $data): array
    {
        $record = [
            'name'          => trim($data['name'] ?? ''),
            'type'          => trim($data['type'] ?? 'monthly'), // daily|weekly|monthly|unlimited|corporate
            'duration_days' => (int)($data['duration_days'] ?? 30),
            'data_gb'       => (float)($data['data_gb'] ?? 0),   // 0 = unlimited
            'speed_mbps'    => (float)($data['speed_mbps'] ?? 0), // 0 = uncapped
            'price'         => (float)($data['price'] ?? 0),
            'currency'      => trim($data['currency'] ?? 'USD'),
            'magma_profile' => trim($data['magma_profile'] ?? 'default'), // Magma sub_profile name
            'description'   => trim($data['description'] ?? ''),
            'active'        => true,
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        return $this->store->appendWithId('lte_packages.json', $record);
    }

    /* ═══════════════════════════════════════════════════════
       SUBSCRIPTIONS & RENEWALS
    ═══════════════════════════════════════════════════════ */

    public function getActiveSubscription(int $subscriberId): ?array
    {
        $subs = $this->loadSubscriptions();
        foreach ($subs as $s) {
            if ((int)($s['subscriber_id'] ?? 0) === $subscriberId && ($s['status'] ?? '') === 'active') {
                return $s;
            }
        }
        return null;
    }

    /**
     * Renew or activate a subscription for a subscriber.
     * - Expires old subscription if any
     * - Creates new subscription record
     * - Updates Magma subscriber profile + state (if configured)
     * - Returns renewal record
     */
    public function renewSubscription(int $subscriberId, int $packageId, int $agentId, string $agentName, float $amountPaid, string $paymentMethod = 'cash'): array
    {
        $sub = $this->store->findOne('lte_subscribers.json', 'id', $subscriberId);
        $pkg = $this->store->findOne('lte_packages.json', 'id', $packageId);

        if (!$sub) throw new RuntimeException("Subscriber #{$subscriberId} not found");
        if (!$pkg) throw new RuntimeException("Package #{$packageId} not found");

        $now     = date('Y-m-d H:i:s');
        $today   = date('Y-m-d');
        $expires = date('Y-m-d', strtotime("+{$pkg['duration_days']} days"));

        // B-11 FIX: load + modify + save was not inside a lock.
        // Two concurrent renewals for the same subscriber could both load the
        // same snapshot, both append a new 'active' subscription, and the second
        // save() would overwrite the first — leaving duplicate active records.
        // Now the entire read-modify-write runs under LOCK_EX.
        $newSub = null;
        $this->store->withLock('lte_subscriptions.json', function (array $allSubs) use (
            $subscriberId, $packageId, $pkg, $agentId, $agentName,
            $amountPaid, $paymentMethod, $now, $today, $expires, &$newSub
        ) {
            // Expire any existing active subscriptions for this subscriber
            foreach ($allSubs as &$s) {
                if ((int)($s['subscriber_id'] ?? 0) === $subscriberId && ($s['status'] ?? '') === 'active') {
                    $s['status']     = 'expired';
                    $s['expired_at'] = $now;
                }
            }
            unset($s);

            // Assign next ID atomically
            $newId  = empty($allSubs) ? 1 : (max(array_map(fn($s) => (int)($s['id'] ?? 0), $allSubs)) + 1);
            $newSub = [
                'id'            => $newId,
                'subscriber_id' => $subscriberId,
                'package_id'    => $packageId,
                'package_name'  => $pkg['name'],
                'magma_profile' => $pkg['magma_profile'],
                'started_at'    => $today,
                'expires_at'    => $expires,
                'status'        => 'active',
                'amount_paid'   => $amountPaid,
                'payment_method'=> $paymentMethod,
                'agent_id'      => $agentId,
                'agent_name'    => $agentName,
                'created_at'    => $now,
            ];
            $allSubs[] = $newSub;

            return ['records' => $allSubs, 'result' => null];
        });

        // Renewal log
        $renewal = [
            'subscriber_id'   => $subscriberId,
            'subscriber_name' => $sub['name'],
            'package_id'      => $packageId,
            'package_name'    => $pkg['name'],
            'amount_paid'     => $amountPaid,
            'payment_method'  => $paymentMethod,
            'expires_at'      => $expires,
            'agent_id'        => $agentId,
            'agent_name'      => $agentName,
            'magma_synced'    => false,
            'created_at'      => $now,
        ];
        $renewal = $this->store->appendWithId('lte_renewals.json', $renewal);

        // Ensure subscriber status = active
        $this->store->updateOne('lte_subscribers.json', 'id', $subscriberId, [
            'status'     => 'active',
            'updated_at' => $now,
        ]);

        // Push to Magma if configured
        $magmaResult = ['skipped' => true];
        if ($this->magmaEnabled && !empty($sub['imsi'])) {
            $ok = $this->magma->changeProfile($sub['imsi'], $pkg['magma_profile']);
            $magmaResult = $ok
                ? ['success' => true]
                : ['error' => $this->magma->getLastError()];

            $this->store->updateOne('lte_renewals.json', 'id', $renewal['id'], [
                'magma_synced' => $ok,
                'magma_result' => $magmaResult,
            ]);
        }

        return array_merge($renewal, ['_magma' => $magmaResult, '_expires' => $expires]);
    }

    /**
     * Suspend a subscriber (non-payment / manual).
     * Sets Magma state = INACTIVE, updates local status.
     */
    public function suspendSubscriber(int $subscriberId, string $reason = 'non_payment'): bool
    {
        $sub = $this->store->findOne('lte_subscribers.json', 'id', $subscriberId);
        if (!$sub) return false;

        $this->store->updateOne('lte_subscribers.json', 'id', $subscriberId, [
            'status'       => 'suspended',
            'suspend_reason' => $reason,
            'suspended_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);

        if ($this->magmaEnabled && !empty($sub['imsi'])) {
            return $this->magma->suspendSubscriber($sub['imsi']);
        }
        return true;
    }

    /**
     * Reactivate a suspended subscriber.
     */
    public function reactivateSubscriber(int $subscriberId): bool
    {
        $sub = $this->store->findOne('lte_subscribers.json', 'id', $subscriberId);
        if (!$sub) return false;

        $this->store->updateOne('lte_subscribers.json', 'id', $subscriberId, [
            'status'     => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($this->magmaEnabled && !empty($sub['imsi'])) {
            return $this->magma->activateSubscriber($sub['imsi']);
        }
        return true;
    }

    /* ═══════════════════════════════════════════════════════
       RENEWAL QUEUE (expiring / expired)
    ═══════════════════════════════════════════════════════ */

    public function getRenewalQueue(int $days = 7): array
    {
        $subs    = $this->getSubscribers();
        $queue   = [];
        $cutoff  = date('Y-m-d', strtotime("+{$days} days"));

        foreach ($subs as $sub) {
            $status = $sub['_expiry_status'] ?? 'no_plan';
            if (in_array($status, ['expired', 'today', 'critical', 'warning', 'no_plan'])) {
                $exp = $sub['_subscription']['expires_at'] ?? null;
                if ($status === 'no_plan' || ($exp && $exp <= $cutoff)) {
                    $queue[] = $sub;
                }
            }
        }

        // Sort: expired → today → critical → warning → no_plan
        $order = ['expired' => 0, 'today' => 1, 'critical' => 2, 'warning' => 3, 'no_plan' => 4];
        usort($queue, fn($a, $b) =>
            ($order[$a['_expiry_status']] ?? 9) - ($order[$b['_expiry_status']] ?? 9)
        );

        return $queue;
    }

    /* ═══════════════════════════════════════════════════════
       DASHBOARD STATS
    ═══════════════════════════════════════════════════════ */

    public function getDashboardStats(): array
    {
        // Use SQL COUNTs directly when SQLite is available — much faster than loading all records
        if ($this->pdo) {
            try {
                $today   = date('Y-m-d');
                $in3days = date('Y-m-d', strtotime('+3 days'));
                $in7days = date('Y-m-d', strtotime('+7 days'));
                $month   = date('Y-m');

                $total     = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE deleted_at IS NULL")->fetchColumn();
                $active    = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE status='active' AND deleted_at IS NULL")->fetchColumn();
                $suspended = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE status='suspended' AND deleted_at IS NULL")->fetchColumn();
                $noSim     = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE (imsi IS NULL OR imsi='') AND deleted_at IS NULL")->fetchColumn();

                $expiredCount = 0; $urgentCount = 0; $warningCount = 0;
                try {
                    $expiredCount = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_subscriptions WHERE status='active' AND expires_at < '$today'")->fetchColumn();
                    $urgentCount  = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_subscriptions WHERE status='active' AND expires_at BETWEEN '$today' AND '$in3days'")->fetchColumn();
                    $warningCount = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_subscriptions WHERE status='active' AND expires_at BETWEEN '$in3days' AND '$in7days'")->fetchColumn();
                } catch (\Throwable $e) {}

                $monthRevenue  = 0.0; $todayRenewals = 0;
                try {
                    $monthRevenue  = (float)$this->pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM lte_renewals WHERE strftime('%Y-%m',created_at)='{$month}'")->fetchColumn();
                    $todayRenewals = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_renewals WHERE DATE(created_at)='$today'")->fetchColumn();
                } catch (\Throwable $e) {}

                $totalPackages = 0; $simStock = 0;
                try {
                    $totalPackages = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_packages")->fetchColumn();
                    $simStock      = (int)$this->pdo->query("SELECT COUNT(*) FROM lte_sims WHERE status IN ('stock','in_stock')")->fetchColumn();
                } catch (\Throwable $e) {}

                return [
                    'total_subscribers' => $total,
                    'active'            => $active,
                    'suspended'         => $suspended,
                    'no_sim'            => $noSim,
                    'expired'           => $expiredCount,
                    'expiring_urgent'   => $urgentCount,
                    'expiring_warning'  => $warningCount,
                    'month_revenue'     => round($monthRevenue, 2),
                    'today_renewals'    => $todayRenewals,
                    'total_packages'    => $totalPackages,
                    'sim_stock'         => $simStock,
                    'hw_warehouse'      => 0,
                    'magma_connected'   => $this->magmaEnabled,
                ];
            } catch (\Throwable $e) {
                // Fall through to JSON-based method below
            }
        }

        // Fallback: load from JSON (manual registrations only)
        $subs     = $this->loadSubscribers();
        $renewals = $this->loadRenewals();
        $packages = $this->loadPackages();
        $sims     = $this->loadSims();
        $hw       = $this->store->load('lte_hardware.json') ?? [];

        $total     = count($subs);
        $active    = count(array_filter($subs, fn($s) => ($s['status'] ?? '') === 'active'));
        $suspended = count(array_filter($subs, fn($s) => ($s['status'] ?? '') === 'suspended'));

        $today   = date('Y-m-d');
        $in3days = date('Y-m-d', strtotime('+3 days'));
        $in7days = date('Y-m-d', strtotime('+7 days'));
        $allSubs2    = $this->loadSubscriptions();
        $activeSubs2 = array_filter($allSubs2, fn($s) => ($s['status'] ?? '') === 'active');

        $expiredCount = 0; $urgentCount = 0; $warningCount = 0;
        foreach ($activeSubs2 as $s) {
            $exp = $s['expires_at'] ?? '';
            if (!$exp) continue;
            if ($exp < $today)        $expiredCount++;
            elseif ($exp <= $in3days) $urgentCount++;
            elseif ($exp <= $in7days) $warningCount++;
        }

        $thisMonth    = date('Y-m');
        $monthRevenue = array_reduce($renewals, function($carry, $r) use ($thisMonth) {
            if (str_starts_with($r['created_at'] ?? '', $thisMonth)) $carry += (float)($r['amount_paid'] ?? 0);
            return $carry;
        }, 0.0);
        $todayRenewals = count(array_filter($renewals, fn($r) => str_starts_with($r['created_at'] ?? '', $today)));

        return [
            'total_subscribers' => $total,
            'active'            => $active,
            'suspended'         => $suspended,
            'no_sim'            => count(array_filter($subs, fn($s) => empty($s['imsi']))),
            'expired'           => $expiredCount,
            'expiring_urgent'   => $urgentCount,
            'expiring_warning'  => $warningCount,
            'month_revenue'     => round($monthRevenue, 2),
            'today_renewals'    => $todayRenewals,
            'total_packages'    => count($packages),
            'sim_stock'         => count(array_filter($sims, fn($s) => ($s['status'] ?? '') === 'stock')),
            'hw_warehouse'      => count(array_filter($hw,  fn($h) => ($h['status'] ?? '') === 'warehouse')),
            'magma_connected'   => $this->magmaEnabled,
        ];
    }



    public function getCachedUsage(string $imsi): ?array
    {
        $cache = $this->store->load('lte_usage_cache.json');
        return $cache[$imsi] ?? null;
    }

    public function getAllCachedUsage(): array
    {
        return $this->store->load('lte_usage_cache.json');
    }

    /**
     * Refresh usage cache from Magma in batches.
     * Stores structured usage data per IMSI keyed by IMSI string.
     * Also fetches gateway + eNodeB health and saves to lte_network_health.json.
     */
    public function refreshUsageCache(): array
    {
        if (!$this->magmaEnabled) return ['skipped' => true, 'reason' => 'Magma not configured'];

        $cache    = [];
        $page     = 1;
        $total    = 0;
        $errors   = 0;

        // Pull all subscribers in pages of 200
        while (true) {
            $batch = $this->magma->listSubscribersVerbose($page, 200);
            if (!is_array($batch) || empty($batch)) break;

            foreach ($batch as $imsi => $data) {
                if (!is_string($imsi) || !str_starts_with($imsi, 'IMSI')) continue;

                $lte = $data['lte'] ?? [];
                $mon = $data['monitoring'] ?? [];
                $icmp = $mon['icmp_latency_stats'] ?? [];

                $cache[$imsi] = [
                    'imsi'          => $imsi,
                    'state'         => $lte['state']       ?? 'UNKNOWN',
                    'sub_profile'   => $lte['sub_profile'] ?? 'default',
                    // Latency / reachability from ICMP probes
                    'avg_latency_ms'    => $icmp['avg_latency_ms']  ?? null,
                    'num_probes_sent'   => $icmp['num_probes_sent'] ?? 0,
                    'num_probes_ok'     => $icmp['num_probes_ok']   ?? 0,
                    'latency_valid'     => ($icmp['num_probes_sent'] ?? 0) > 0,
                    // Raw monitoring blob (keeps full data for future use)
                    'monitoring'    => $mon,
                    'cached_at'     => date('Y-m-d H:i:s'),
                ];
                $total++;
            }

            // Magma returns < page_size when we've hit the last page
            if (count($batch) < 200) break;
            if (++$page > 20) break; // safety: max 4000 subscribers per refresh
        }

        $this->store->save('lte_usage_cache.json', $cache);

        // Refresh network health (gateways + eNodeBs)
        $health = $this->refreshNetworkHealth();

        return [
            'refreshed'  => $total,
            'pages'      => $page,
            'errors'     => $errors,
            'gateways'   => $health['gateways'] ?? 0,
            'enodebs'    => $health['enodebs']  ?? 0,
            'at'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Refresh gateway + eNodeB health cache.
     */
    public function refreshNetworkHealth(): array
    {
        if (!$this->magmaEnabled) return ['skipped' => true];

        $gwRaw  = $this->magma->getGatewayStatus();
        $enbRaw = $this->magma->listEnodebs();

        $health = [
            'gateways'   => [],
            'enodebs'    => [],
            'refreshed_at' => date('Y-m-d H:i:s'),
        ];

        if (is_array($gwRaw)) {
            foreach ($gwRaw as $id => $gw) {
                $health['gateways'][] = [
                    'id'          => $id,
                    'name'        => $gw['name'] ?? $id,
                    'online'      => (bool)($gw['checked_in_recently'] ?? false),
                    'enodeb_count'=> (int)($gw['enodeb_count'] ?? 0),
                    'enodebs'     => $gw['enodebs'] ?? [],
                    'hardware_id' => $gw['hardware_id'] ?? '',
                ];
            }
        }

        if (is_array($enbRaw)) {
            foreach ($enbRaw as $serial => $enb) {
                $health['enodebs'][] = [
                    'serial'       => $serial,
                    'name'         => $enb['name'] ?? $serial,
                    'tac'          => $enb['config']['tac'] ?? null,
                    'band'         => $enb['config']['band_width_mhz'] ?? null,
                    'cell_id'      => $enb['config']['cell_id'] ?? null,
                    'transmit'     => (bool)($enb['config']['transmit_enabled'] ?? false),
                    'attached_gw'  => $enb['attached_gateway_id'] ?? null,
                    'state'        => $enb['enodeb_state']['bbu_ip'] ?? null,
                    'connected'    => (bool)($enb['enodeb_state']['connected'] ?? false),
                    'opstate'      => $enb['enodeb_state']['opstate_enabled'] ?? null,
                    'rf_tx'        => $enb['enodeb_state']['rf_tx_on'] ?? null,
                    'gps_connected'=> $enb['enodeb_state']['gps_connected'] ?? null,
                    'lat'          => $enb['enodeb_state']['gps_latitude'] ?? null,
                    'lon'          => $enb['enodeb_state']['gps_longitude'] ?? null,
                ];
            }
        }

        $this->store->save('lte_network_health.json', $health);

        return [
            'gateways' => count($health['gateways']),
            'enodebs'  => count($health['enodebs']),
        ];
    }

    /**
     * Get cached network health (gateways + eNodeBs).
     */
    public function getCachedNetworkHealth(): array
    {
        return $this->store->load('lte_network_health.json') ?: [
            'gateways' => [], 'enodebs' => [], 'refreshed_at' => null
        ];
    }

    /**
     * Build usage summary for display.
     * Merges subscriber registry with usage cache.
     * Returns array of subscribers with usage data attached.
     */
    public function getUsageSummary(array $filters = []): array
    {
        $subscribers = $this->loadSubscribers();
        $usageCache  = $this->store->load('lte_usage_cache.json');
        $subscriptions = $this->loadSubscriptions();

        // Build active subscription map: subscriber_id => subscription
        $subMap = [];
        foreach ($subscriptions as $s) {
            if (($s['status'] ?? '') === 'active') {
                $subMap[(int)($s['subscriber_id'] ?? 0)] = $s;
            }
        }

        $results = [];
        foreach ($subscribers as $sub) {
            $imsi   = $sub['imsi'] ?? '';
            $usage  = $imsi ? ($usageCache[$imsi] ?? null) : null;
            $activeSub = $subMap[(int)($sub['id'] ?? 0)] ?? null;

            // State: prefer Magma live state, fallback to local status
            $magmaState = $usage ? ($usage['state'] ?? 'UNKNOWN') : 'NO_IMSI';
            $localStatus = $sub['status'] ?? 'active';

            // Reachability: based on ICMP probe success rate
            $probesSent = (int)($usage['num_probes_sent'] ?? 0);
            $probesOk   = (int)($usage['num_probes_ok']   ?? 0);
            $reachPct   = $probesSent > 0 ? round($probesOk / $probesSent * 100) : null;
            $latency    = $usage ? ($usage['avg_latency_ms'] ?? null) : null;

            $row = [
                'id'           => $sub['id'],
                'name'         => $sub['name'],
                'phone'        => $sub['phone'] ?? '',
                'imsi'         => $imsi,
                'msisdn'       => $sub['msisdn'] ?? '',
                'local_status' => $localStatus,
                'magma_state'  => $magmaState,
                'sub_profile'  => $usage ? ($usage['sub_profile'] ?? '') : '',
                'latency_ms'   => $latency,
                'reach_pct'    => $reachPct,
                'probes_sent'  => $probesSent,
                'plan_name'    => $activeSub ? ($activeSub['package_name'] ?? '') : '',
                'expires_at'   => $activeSub ? ($activeSub['expires_at'] ?? '') : '',
                'cached_at'    => $usage ? ($usage['cached_at'] ?? '') : '',
                // Alert flags
                '_no_imsi'     => empty($imsi),
                '_magma_mismatch' => $imsi && $magmaState !== 'UNKNOWN' &&
                    (($magmaState === 'ACTIVE') !== ($localStatus === 'active')),
                '_zero_reach'  => $probesSent > 0 && $probesOk === 0,
                '_high_latency'=> $latency !== null && $latency > 200,
            ];

            // Apply filters
            if (!empty($filters['search'])) {
                $q = strtolower($filters['search']);
                if (!str_contains(strtolower($row['name']), $q) &&
                    !str_contains($row['imsi'], $q) &&
                    !str_contains($row['msisdn'], $q)) continue;
            }
            if (!empty($filters['state'])) {
                if ($filters['state'] === 'mismatch'   && !$row['_magma_mismatch']) continue;
                if ($filters['state'] === 'unreachable' && !$row['_zero_reach'])    continue;
                if ($filters['state'] === 'no_imsi'    && !$row['_no_imsi'])        continue;
                if ($filters['state'] === 'active'     && $magmaState !== 'ACTIVE') continue;
                if ($filters['state'] === 'inactive'   && $magmaState !== 'INACTIVE') continue;
            }

            $results[] = $row;
        }

        // Sort by alerts first, then by name
        usort($results, function($a, $b) {
            $aScore = ($a['_zero_reach']?4:0) + ($a['_magma_mismatch']?2:0) + ($a['_no_imsi']?1:0);
            $bScore = ($b['_zero_reach']?4:0) + ($b['_magma_mismatch']?2:0) + ($b['_no_imsi']?1:0);
            if ($aScore !== $bScore) return $bScore - $aScore;
            return strcmp($a['name'], $b['name']);
        });

        return $results;
    }
}