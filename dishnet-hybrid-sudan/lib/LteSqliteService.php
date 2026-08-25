<?php
declare(strict_types=1);

/**
 * LteSqliteService — DishNet Africa
 * SQLite-based business logic layer for DishNet 4G (Magma/Baicells) subscriber management.
 *
 * Tables:
 *  - lte_subscribers    (customers)
 *  - lte_sims           (SIM inventory)
 *  - lte_packages       (plan catalog)
 *  - lte_subscriptions  (active/historical with bytes tracking)
 *  - lte_renewals       (renewal history)
 *
 * NOTE: This replaces the JSON-based LteService for production use.
 */
class LteSqliteService
{
    private PDO $db;
    private MagmaApiClient $magma;
    private bool $magmaEnabled;

    public function __construct(PDO $db, MagmaApiClient $magma)
    {
        $this->db           = $db;
        $this->magma        = $magma;
        $this->magmaEnabled = $magma->isConfigured();
    }

    /* ═══════════════════════════════════════════════════════
       SUBSCRIBER CRUD
    ═══════════════════════════════════════════════════════ */

    public function getSubscribers(array $filters = []): array
    {
        $sql = "SELECT s.*, 
                       sub.package_name, sub.expires_at, sub.bytes_used, 
                       sub.bytes_allowed, sub.usage_percent, sub.status as sub_status
                FROM lte_subscribers s
                LEFT JOIN lte_subscriptions sub ON sub.subscriber_id = s.id AND sub.status = 'active'
                WHERE s.deleted_at IS NULL";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND s.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['agent_id'])) {
            $sql .= " AND s.agent_id = :agent_id";
            $params[':agent_id'] = (int)$filters['agent_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (s.name LIKE :search OR s.phone LIKE :search OR s.imsi LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $sql .= " ORDER BY COALESCE(sub.expires_at, '9999-99-99') ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSubscriber(int $id): ?array
    {
        $sql = "SELECT s.*, 
                       sub.id as subscription_id, sub.package_id, sub.package_name, 
                       sub.package_type, sub.magma_profile, sub.started_at, sub.expires_at,
                       sub.bytes_allowed, sub.bytes_used, sub.bytes_remaining, sub.usage_percent,
                       sub.warned_50, sub.warned_80, sub.warned_100, sub.last_usage_sync,
                       sub.status as sub_status, sub.amount_paid, sub.payment_method,
                       sim.auth_key, sim.auth_opc, sim.iccid, sim.status as sim_status
                FROM lte_subscribers s
                LEFT JOIN lte_subscriptions sub ON sub.subscriber_id = s.id AND sub.status = 'active'
                LEFT JOIN lte_sims sim ON sim.id = s.sim_id
                WHERE s.id = :id AND s.deleted_at IS NULL";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ?: null;
    }

    public function getSubscriberByImsi(string $imsi): ?array
    {
        $sql = "SELECT * FROM lte_subscribers WHERE imsi = :imsi AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':imsi' => $imsi]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createSubscriber(array $data, int $agentId, string $agentName): array
    {
        $sql = "INSERT INTO lte_subscribers 
                (name, phone, email, address, area, id_type, id_number, gps_lat, gps_lon,
                 imsi, msisdn, sim_id, hardware_id, ucrm_id, status, agent_id, agent_name,
                 registered_by, notes, service_type, created_at)
                VALUES 
                (:name, :phone, :email, :address, :area, :id_type, :id_number, :gps_lat, :gps_lon,
                 :imsi, :msisdn, :sim_id, :hardware_id, :ucrm_id, 'active', :agent_id, :agent_name,
                 :registered_by, :notes, 'lte', datetime('now'))";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':name'         => trim($data['name'] ?? ''),
            ':phone'        => trim($data['phone'] ?? '') ?: null,
            ':email'        => trim($data['email'] ?? '') ?: null,
            ':address'      => trim($data['address'] ?? '') ?: null,
            ':area'         => trim($data['area'] ?? '') ?: null,
            ':id_type'      => trim($data['id_type'] ?? '') ?: null,
            ':id_number'    => trim($data['id_number'] ?? '') ?: null,
            ':gps_lat'      => !empty($data['gps_lat']) ? (float)$data['gps_lat'] : null,
            ':gps_lon'      => !empty($data['gps_lon']) ? (float)$data['gps_lon'] : null,
            ':imsi'         => trim($data['imsi'] ?? '') ?: null,
            ':msisdn'       => trim($data['msisdn'] ?? '') ?: null,
            ':sim_id'       => !empty($data['sim_id']) ? (int)$data['sim_id'] : null,
            ':hardware_id'  => !empty($data['hardware_id']) ? (int)$data['hardware_id'] : null,
            ':ucrm_id'      => !empty($data['ucrm_id']) ? (int)$data['ucrm_id'] : null,
            ':agent_id'     => $agentId,
            ':agent_name'   => $agentName,
            ':registered_by'=> $data['registered_by'] ?? 'staff',
            ':notes'        => trim($data['notes'] ?? '') ?: null,
        ]);
        
        $id = (int)$this->db->lastInsertId();
        
        // Mark SIM as assigned
        if (!empty($data['sim_id'])) {
            $this->db->prepare("UPDATE lte_sims SET status = 'assigned', subscriber_id = :sub_id, assigned_at = datetime('now') WHERE id = :sim_id")
                     ->execute([':sub_id' => $id, ':sim_id' => (int)$data['sim_id']]);
        }
        
        return $this->getSubscriber($id);
    }

    public function updateSubscriber(int $id, array $data): bool
    {
        $allowed = ['name', 'phone', 'email', 'address', 'area', 'id_type', 'id_number',
                    'gps_lat', 'gps_lon', 'ucrm_id', 'status', 'notes'];
        
        $sets = [];
        $params = [':id' => $id];
        
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }
        
        if (empty($sets)) return false;
        
        $sets[] = "updated_at = datetime('now')";
        $sql = "UPDATE lte_subscribers SET " . implode(', ', $sets) . " WHERE id = :id";
        
        return $this->db->prepare($sql)->execute($params);
    }

    /* ═══════════════════════════════════════════════════════
       SIM INVENTORY
    ═══════════════════════════════════════════════════════ */

    public function getSims(array $filters = []): array
    {
        $sql = "SELECT * FROM lte_sims WHERE deleted_at IS NULL";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (imsi LIKE :search OR msisdn LIKE :search OR iccid LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSimByImsi(string $imsi): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM lte_sims WHERE imsi = :imsi");
        $stmt->execute([':imsi' => $imsi]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function createSim(array $data): array
    {
        $sql = "INSERT INTO lte_sims 
                (imsi, msisdn, iccid, auth_key, auth_opc, auth_algo, status, batch, vendor, 
                 purchase_date, notes, created_at)
                VALUES 
                (:imsi, :msisdn, :iccid, :auth_key, :auth_opc, :auth_algo, 'stock', :batch, :vendor,
                 :purchase_date, :notes, datetime('now'))";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':imsi'         => trim($data['imsi']),
            ':msisdn'       => trim($data['msisdn'] ?? $data['imsi']),
            ':iccid'        => trim($data['iccid'] ?? '') ?: null,
            ':auth_key'     => trim($data['auth_key']),
            ':auth_opc'     => trim($data['auth_opc']),
            ':auth_algo'    => $data['auth_algo'] ?? 'Milenage',
            ':batch'        => trim($data['batch'] ?? '') ?: null,
            ':vendor'       => $data['vendor'] ?? 'DishNet',
            ':purchase_date'=> $data['purchase_date'] ?? date('Y-m-d'),
            ':notes'        => trim($data['notes'] ?? '') ?: null,
        ]);
        
        $id = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare("SELECT * FROM lte_sims WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function importSims(array $sims): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        $insertStmt = $this->db->prepare(
            "INSERT OR IGNORE INTO lte_sims 
             (imsi, msisdn, iccid, auth_key, auth_opc, auth_algo, status, batch, vendor, created_at)
             VALUES (:imsi, :msisdn, :iccid, :auth_key, :auth_opc, :auth_algo, 'stock', :batch, :vendor, datetime('now'))"
        );
        
        foreach ($sims as $sim) {
            try {
                $insertStmt->execute([
                    ':imsi'     => trim($sim['imsi']),
                    ':msisdn'   => trim($sim['msisdn'] ?? $sim['imsi']),
                    ':iccid'    => trim($sim['iccid'] ?? '') ?: null,
                    ':auth_key' => trim($sim['auth_key']),
                    ':auth_opc' => trim($sim['auth_opc'] ?? $sim['opc_value'] ?? ''),
                    ':auth_algo'=> 'Milenage',
                    ':batch'    => $sim['batch'] ?? 'import-' . date('Ymd'),
                    ':vendor'   => 'DishNet',
                ]);
                
                if ($insertStmt->rowCount() > 0) {
                    $imported++;
                } else {
                    $skipped++; // IMSI already exists
                }
            } catch (Exception $e) {
                $errors[] = "IMSI {$sim['imsi']}: " . $e->getMessage();
            }
        }
        
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /* ═══════════════════════════════════════════════════════
       PACKAGES
    ═══════════════════════════════════════════════════════ */

    public function getPackages(bool $activeOnly = false): array
    {
        $sql = "SELECT p.*, 
                       (SELECT COUNT(*) FROM lte_subscriptions sub 
                        WHERE sub.package_id = p.id AND sub.status = 'active') as active_count
                FROM lte_packages p";
        
        if ($activeOnly) {
            $sql .= " WHERE p.is_active = 1";
        }
        
        $sql .= " ORDER BY p.sort_order ASC, p.price ASC";
        
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPackage(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM lte_packages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ═══════════════════════════════════════════════════════
       SUBSCRIPTIONS & RENEWALS
    ═══════════════════════════════════════════════════════ */

    public function getActiveSubscription(int $subscriberId): ?array
    {
        $sql = "SELECT * FROM lte_subscriptions 
                WHERE subscriber_id = :sub_id AND status = 'active' 
                ORDER BY created_at DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':sub_id' => $subscriberId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function renewSubscription(int $subscriberId, int $packageId, int $agentId, string $agentName, 
                                       float $amountPaid, string $paymentMethod = 'cash'): array
    {
        $subscriber = $this->getSubscriber($subscriberId);
        $package = $this->getPackage($packageId);
        
        if (!$subscriber) throw new RuntimeException("Subscriber #{$subscriberId} not found");
        if (!$package) throw new RuntimeException("Package #{$packageId} not found");
        
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $expiresAt = date('Y-m-d', strtotime("+{$package['duration_days']} days"));
        
        // CRITICAL: Capture current Prometheus usage as baseline for delta calculation
        // This prevents the bug where cumulative Prometheus usage causes instant 100%
        $bytesBaseline = 0;
        if ($this->magmaEnabled && !empty($subscriber['imsi'])) {
            $usage = $this->magma->getUsageFromPrometheus($subscriber['imsi']);
            if ($usage && isset($usage['bytes_used'])) {
                $bytesBaseline = (int)$usage['bytes_used'];
            }
        }
        
        $this->db->beginTransaction();
        
        try {
            // Expire any existing active subscriptions
            $this->db->prepare(
                "UPDATE lte_subscriptions SET status = 'expired', expired_at = :now, updated_at = :now 
                 WHERE subscriber_id = :sub_id AND status = 'active'"
            )->execute([':sub_id' => $subscriberId, ':now' => $now]);
            
            // Create new subscription with bytes tracking
            $pkgType = (int)($package['type'] ?? 2);
            $bytesAllowed = ($pkgType === 0) ? (int)$package['bytes_allowed'] : null;
            
            $this->db->prepare(
                "INSERT INTO lte_subscriptions 
                 (subscriber_id, package_id, package_name, package_type, magma_profile,
                  started_at, expires_at, bytes_allowed, bytes_baseline, bytes_used, bytes_remaining, usage_percent,
                  status, amount_paid, payment_method, agent_id, agent_name, created_at)
                 VALUES 
                 (:sub_id, :pkg_id, :pkg_name, :pkg_type, :profile,
                  :started, :expires, :bytes_allowed, :bytes_baseline, 0, :bytes_remaining, 0,
                  'active', :amount, :method, :agent_id, :agent_name, :now)"
            )->execute([
                ':sub_id'         => $subscriberId,
                ':pkg_id'         => $packageId,
                ':pkg_name'       => $package['name'],
                ':pkg_type'       => $pkgType,
                ':profile'        => $package['magma_profile'] ?? 'default',
                ':started'        => $today,
                ':expires'        => $expiresAt,
                ':bytes_allowed'  => $bytesAllowed,
                ':bytes_baseline' => $bytesBaseline,
                ':bytes_remaining'=> $bytesAllowed,
                ':amount'         => $amountPaid,
                ':method'         => $paymentMethod,
                ':agent_id'       => $agentId,
                ':agent_name'     => $agentName,
                ':now'            => $now,
            ]);
            
            $subscriptionId = (int)$this->db->lastInsertId();
            
            // Create renewal record
            $this->db->prepare(
                "INSERT INTO lte_renewals 
                 (subscriber_id, subscription_id, package_id, subscriber_name, package_name, 
                  package_price, duration_days, amount_paid, payment_method, started_at, expires_at,
                  agent_id, agent_name, is_new, created_at)
                 VALUES 
                 (:sub_id, :subs_id, :pkg_id, :sub_name, :pkg_name,
                  :pkg_price, :days, :amount, :method, :started, :expires,
                  :agent_id, :agent_name, :is_new, :now)"
            )->execute([
                ':sub_id'     => $subscriberId,
                ':subs_id'    => $subscriptionId,
                ':pkg_id'     => $packageId,
                ':sub_name'   => $subscriber['name'],
                ':pkg_name'   => $package['name'],
                ':pkg_price'  => $package['price'],
                ':days'       => $package['duration_days'],
                ':amount'     => $amountPaid,
                ':method'     => $paymentMethod,
                ':started'    => $today,
                ':expires'    => $expiresAt,
                ':agent_id'   => $agentId,
                ':agent_name' => $agentName,
                ':is_new'     => empty($subscriber['subscription_id']) ? 1 : 0,
                ':now'        => $now,
            ]);
            
            $renewalId = (int)$this->db->lastInsertId();
            
            // Ensure subscriber is active
            $this->db->prepare(
                "UPDATE lte_subscribers SET status = 'active', updated_at = :now WHERE id = :id"
            )->execute([':id' => $subscriberId, ':now' => $now]);
            
            $this->db->commit();
            
            // Push to Magma
            $magmaResult = ['skipped' => true];
            if ($this->magmaEnabled && !empty($subscriber['imsi'])) {
                $ok = $this->magma->changeProfile($subscriber['imsi'], $package['magma_profile'] ?? 'default');
                $magmaResult = $ok ? ['success' => true] : ['error' => $this->magma->getLastError()];
                
                $this->db->prepare(
                    "UPDATE lte_renewals SET magma_synced = :synced, magma_profile = :profile WHERE id = :id"
                )->execute([
                    ':synced'  => $ok ? 1 : 0,
                    ':profile' => $package['magma_profile'] ?? 'default',
                    ':id'      => $renewalId,
                ]);
            }
            
            return [
                'renewal_id'      => $renewalId,
                'subscription_id' => $subscriptionId,
                'subscriber_id'   => $subscriberId,
                'package_name'    => $package['name'],
                'expires_at'      => $expiresAt,
                'amount_paid'     => $amountPaid,
                '_magma'          => $magmaResult,
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /* ═══════════════════════════════════════════════════════
       SUSPEND / REACTIVATE
    ═══════════════════════════════════════════════════════ */

    public function suspendSubscriber(int $subscriberId, string $reason = 'manual'): bool
    {
        $sub = $this->getSubscriber($subscriberId);
        if (!$sub) return false;
        
        $now = date('Y-m-d H:i:s');
        
        $this->db->prepare(
            "UPDATE lte_subscribers SET status = 'suspended', suspend_reason = :reason, 
             suspended_at = :now, updated_at = :now WHERE id = :id"
        )->execute([':id' => $subscriberId, ':reason' => $reason, ':now' => $now]);
        
        // Suspend in Magma
        if ($this->magmaEnabled && !empty($sub['imsi'])) {
            $this->magma->suspendSubscriber($sub['imsi']);
        }
        
        return true;
    }

    public function reactivateSubscriber(int $subscriberId): bool
    {
        $sub = $this->getSubscriber($subscriberId);
        if (!$sub) return false;
        
        $now = date('Y-m-d H:i:s');
        
        $this->db->prepare(
            "UPDATE lte_subscribers SET status = 'active', suspend_reason = NULL, 
             suspended_at = NULL, updated_at = :now WHERE id = :id"
        )->execute([':id' => $subscriberId, ':now' => $now]);
        
        // Reactivate in Magma
        if ($this->magmaEnabled && !empty($sub['imsi'])) {
            $this->magma->activateSubscriber($sub['imsi']);
        }
        
        return true;
    }

    /* ═══════════════════════════════════════════════════════
       USAGE SYNC (Prometheus)
    ═══════════════════════════════════════════════════════ */

    /**
     * Get all active data-cap subscriptions that need usage sync.
     */
    public function getDataCapSubscriptions(): array
    {
        $sql = "SELECT sub.*, s.imsi, s.phone, s.name as subscriber_name
                FROM lte_subscriptions sub
                JOIN lte_subscribers s ON s.id = sub.subscriber_id
                WHERE sub.status = 'active' 
                  AND sub.package_type = 0 
                  AND s.imsi IS NOT NULL
                  AND s.deleted_at IS NULL";
        
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update subscription usage from Prometheus data.
     * NOTE: $prometheusTotal is the cumulative bytes from Prometheus.
     * Actual usage = $prometheusTotal - bytes_baseline
     */
    public function updateSubscriptionUsage(int $subscriptionId, int $prometheusTotal): array
    {
        $stmt = $this->db->prepare("SELECT * FROM lte_subscriptions WHERE id = :id");
        $stmt->execute([':id' => $subscriptionId]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sub) return ['error' => 'Subscription not found'];
        
        $bytesAllowed = (int)($sub['bytes_allowed'] ?? 0);
        if ($bytesAllowed <= 0) return ['skipped' => true, 'reason' => 'No bytes_allowed'];
        
        // CRITICAL: Subtract baseline to get actual usage in this billing period
        $bytesBaseline = (int)($sub['bytes_baseline'] ?? 0);
        $bytesUsed = max(0, $prometheusTotal - $bytesBaseline);
        
        $bytesRemaining = max(0, $bytesAllowed - $bytesUsed);
        $usagePercent = round(($bytesUsed / $bytesAllowed) * 100, 2);
        $now = date('Y-m-d H:i:s');
        
        $updates = [
            'bytes_used'      => $bytesUsed,
            'bytes_remaining' => $bytesRemaining,
            'usage_percent'   => $usagePercent,
            'last_usage_sync' => $now,
        ];
        
        $warnings = [];
        
        // Check thresholds
        if ($usagePercent >= 50 && !$sub['warned_50']) {
            $updates['warned_50'] = 1;
            $warnings[] = '50%';
        }
        if ($usagePercent >= 80 && !$sub['warned_80']) {
            $updates['warned_80'] = 1;
            $warnings[] = '80%';
        }
        if ($bytesUsed >= $bytesAllowed && !$sub['warned_100']) {
            $updates['warned_100'] = 1;
            $updates['status'] = 'exhausted';
            $warnings[] = '100%';
        }
        
        // Build UPDATE query
        $sets = [];
        $params = [':id' => $subscriptionId];
        foreach ($updates as $k => $v) {
            $sets[] = "{$k} = :{$k}";
            $params[":{$k}"] = $v;
        }
        $sets[] = "updated_at = datetime('now')";
        
        $this->db->prepare("UPDATE lte_subscriptions SET " . implode(', ', $sets) . " WHERE id = :id")
                 ->execute($params);
        
        return [
            'subscription_id' => $subscriptionId,
            'bytes_used'      => $bytesUsed,
            'usage_percent'   => $usagePercent,
            'warnings'        => $warnings,
            'exhausted'       => $bytesUsed >= $bytesAllowed,
        ];
    }

    /**
     * Get usage alerts (subscribers at >= threshold).
     */
    public function getUsageAlerts(int $threshold = 80): array
    {
        $sql = "SELECT sub.*, s.name, s.phone, s.imsi
                FROM lte_subscriptions sub
                JOIN lte_subscribers s ON s.id = sub.subscriber_id
                WHERE sub.status = 'active'
                  AND sub.package_type = 0
                  AND sub.usage_percent >= :threshold
                ORDER BY sub.usage_percent DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':threshold' => $threshold]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ═══════════════════════════════════════════════════════
       DASHBOARD STATS
    ═══════════════════════════════════════════════════════ */

    public function getDashboardStats(): array
    {
        // Subscriber counts
        $subCounts = [];
        try {
            $subCounts = $this->db->query(
                "SELECT status, COUNT(*) as cnt FROM lte_subscribers WHERE deleted_at IS NULL GROUP BY status"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {}
        
        // SIM counts
        $simCounts = [];
        try {
            $simCounts = $this->db->query(
                "SELECT status, COUNT(*) as cnt FROM lte_sims WHERE deleted_at IS NULL GROUP BY status"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {}
        
        // Hardware counts (table may not exist yet)
        $hwCounts = [];
        try {
            $hwCounts = $this->db->query(
                "SELECT status, COUNT(*) as cnt FROM lte_hardware WHERE deleted_at IS NULL GROUP BY status"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {}
        
        // Package count
        $pkgCount = 0;
        try {
            $pkgCount = (int)$this->db->query(
                "SELECT COUNT(*) FROM lte_packages WHERE is_active = 1"
            )->fetchColumn();
        } catch (Exception $e) {}
        
        // Subscribers without SIM
        $noSimCount = 0;
        try {
            $noSimCount = (int)$this->db->query(
                "SELECT COUNT(*) FROM lte_subscribers WHERE (imsi IS NULL OR imsi = '') AND deleted_at IS NULL"
            )->fetchColumn();
        } catch (Exception $e) {}
        
        // Expiry breakdown: expired, urgent (<=3d), warning (<=7d)
        $expiredCount = 0;
        $urgentCount = 0;
        $warningCount = 0;
        try {
            $expiredCount = (int)$this->db->query(
                "SELECT COUNT(*) FROM lte_subscriptions 
                 WHERE status = 'active' AND expires_at < date('now')"
            )->fetchColumn();
            
            $urgentCount = (int)$this->db->query(
                "SELECT COUNT(*) FROM lte_subscriptions 
                 WHERE status = 'active' AND expires_at >= date('now') AND expires_at <= date('now', '+3 days')"
            )->fetchColumn();
            
            $warningCount = (int)$this->db->query(
                "SELECT COUNT(*) FROM lte_subscriptions 
                 WHERE status = 'active' AND expires_at > date('now', '+3 days') AND expires_at <= date('now', '+7 days')"
            )->fetchColumn();
        } catch (Exception $e) {}
        
        // Revenue this month
        $monthRevenue = 0.0;
        $todayRenewals = 0;
        try {
            $monthRevenue = (float)$this->db->query(
                "SELECT COALESCE(SUM(amount_paid), 0) FROM lte_renewals 
                 WHERE created_at >= date('now', 'start of month')"
            )->fetchColumn();
            
            $todayRenewals = (int)$this->db->query(
                "SELECT COUNT(*) FROM lte_renewals WHERE date(created_at) = date('now')"
            )->fetchColumn();
        } catch (Exception $e) {}
        
        $total = is_array($subCounts) ? array_sum($subCounts) : 0;
        
        // Return format matching old LteService for compatibility
        return [
            'total_subscribers' => $total,
            'active'            => (int)($subCounts['active'] ?? 0),
            'suspended'         => (int)($subCounts['suspended'] ?? 0),
            'no_sim'            => $noSimCount,
            'expired'           => $expiredCount,
            'expiring_urgent'   => $urgentCount,
            'expiring_warning'  => $warningCount,
            'month_revenue'     => $monthRevenue,
            'today_renewals'    => $todayRenewals,
            'total_packages'    => $pkgCount,
            'sim_stock'         => (int)($simCounts['stock'] ?? 0),
            'hw_warehouse'      => (int)($hwCounts['warehouse'] ?? 0),
            'magma_connected'   => true, // Will be updated by actual connection check
        ];
    }

    /* ═══════════════════════════════════════════════════════
       UTILITIES
    ═══════════════════════════════════════════════════════ */

    /**
     * Bulk update subscription usage with transaction batching.
     * Much faster than individual updates at scale (5000+ subscribers).
     * 
     * @param array $updates Array of ['subscription_id' => int, 'prometheus_total' => int]
     * @return array Summary: synced count, warnings, exhausted list
     */
    public function bulkUpdateUsage(array $updates): array
    {
        if (empty($updates)) return ['synced' => 0];
        
        $synced = 0;
        $warnings = [];
        $exhausted = [];
        $errors = [];
        $now = date('Y-m-d H:i:s');
        
        // Process in transaction batches of 100 for optimal SQLite performance
        $batches = array_chunk($updates, 100);
        
        foreach ($batches as $batch) {
            $this->db->beginTransaction();
            
            try {
                foreach ($batch as $update) {
                    $result = $this->updateSubscriptionUsage(
                        (int)$update['subscription_id'],
                        (int)$update['prometheus_total']
                    );
                    
                    if (!empty($result['error'])) {
                        $errors[] = $result['error'];
                        continue;
                    }
                    
                    if (!empty($result['skipped'])) continue;
                    
                    $synced++;
                    
                    if (!empty($result['warnings'])) {
                        $warnings[] = [
                            'subscription_id' => $update['subscription_id'],
                            'warnings' => $result['warnings'],
                            'usage_percent' => $result['usage_percent'],
                        ];
                    }
                    
                    if (!empty($result['exhausted'])) {
                        $exhausted[] = [
                            'subscription_id' => $update['subscription_id'],
                            'bytes_used' => $result['bytes_used'],
                        ];
                    }
                }
                
                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                $errors[] = "Batch failed: " . $e->getMessage();
            }
        }
        
        return [
            'synced'    => $synced,
            'processed' => $updates,
            'warnings'  => $warnings,
            'exhausted' => $exhausted,
            'errors'    => $errors,
        ];
    }

    public static function formatBytes(int $bytes, int $decimals = 2): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1);
        return round($bytes / pow(1024, $factor), $decimals) . ' ' . $units[$factor];
    }

    /* ═══════════════════════════════════════════════════════
       PROMETHEUS USAGE SYNC (for cron_lte_usage.php)
    ═══════════════════════════════════════════════════════ */

    /**
     * Sync usage from Prometheus for all data-cap subscriptions.
     * Returns summary with warnings and exhausted lists.
     */
    public function syncUsageFromPrometheus(): array
    {
        if (!$this->magmaEnabled) {
            return ['skipped' => true, 'reason' => 'Magma not configured'];
        }
        
        $dataCapSubs = $this->getDataCapSubscriptions();
        if (empty($dataCapSubs)) {
            return ['synced' => 0, 'message' => 'No data-cap subscriptions to sync'];
        }
        
        // Collect all IMSIs for bulk query
        $imsiList = array_filter(array_column($dataCapSubs, 'imsi'));
        $imsiList = array_unique($imsiList);
        
        if (empty($imsiList)) {
            return ['synced' => 0, 'message' => 'No IMSIs to query'];
        }
        
        // Bulk fetch usage from Prometheus
        $usageData = $this->magma->getBulkUsageFromPrometheus($imsiList);
        
        $synced = 0;
        $warnings = [];
        $exhausted = [];
        $errors = [];
        $now = date('Y-m-d H:i:s');
        
        $this->db->beginTransaction();
        
        try {
            foreach ($dataCapSubs as $sub) {
                $imsi = $sub['imsi'];
                $subId = (int)$sub['id'];
                $usage = $usageData[$imsi] ?? null;
                
                if (!$usage) {
                    $errors[] = "No usage data for IMSI {$imsi}";
                    continue;
                }
                
                $prometheusTotal = (int)$usage['bytes_used'];
                $bytesBaseline = (int)($sub['bytes_baseline'] ?? 0);
                $bytesUsed = max(0, $prometheusTotal - $bytesBaseline);
                $bytesAllowed = (int)($sub['bytes_allowed'] ?? 0);
                
                if ($bytesAllowed <= 0) continue;
                
                $bytesRemaining = max(0, $bytesAllowed - $bytesUsed);
                $usagePercent = round(($bytesUsed / $bytesAllowed) * 100, 2);
                
                // Build update
                $warned50 = (int)($sub['warned_50'] ?? 0);
                $warned80 = (int)($sub['warned_80'] ?? 0);
                $warned100 = (int)($sub['warned_100'] ?? 0);
                $subWarnings = [];
                
                if ($usagePercent >= 50 && !$warned50) {
                    $warned50 = 1;
                    $subWarnings[] = '50%';
                    $warnings[] = ['type' => '50%', 'sub' => $sub, 'percent' => $usagePercent];
                }
                if ($usagePercent >= 80 && !$warned80) {
                    $warned80 = 1;
                    $subWarnings[] = '80%';
                    $warnings[] = ['type' => '80%', 'sub' => $sub, 'percent' => $usagePercent];
                }
                if ($bytesUsed >= $bytesAllowed && !$warned100) {
                    $warned100 = 1;
                    $subWarnings[] = '100%';
                    $sub['bytes_used'] = $bytesUsed;
                    $sub['usage_percent'] = $usagePercent;
                    $exhausted[] = $sub;
                }
                
                // Update subscription
                $stmt = $this->db->prepare(
                    "UPDATE lte_subscriptions 
                     SET bytes_used = :used, bytes_remaining = :remaining, usage_percent = :percent,
                         warned_50 = :w50, warned_80 = :w80, warned_100 = :w100, 
                         last_usage_sync = :now, updated_at = :now
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':used'      => $bytesUsed,
                    ':remaining' => $bytesRemaining,
                    ':percent'   => $usagePercent,
                    ':w50'       => $warned50,
                    ':w80'       => $warned80,
                    ':w100'      => $warned100,
                    ':now'       => $now,
                    ':id'        => $subId,
                ]);
                
                $synced++;
            }
            
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            $errors[] = "Transaction failed: " . $e->getMessage();
        }
        
        return [
            'synced'    => $synced,
            'warnings'  => $warnings,
            'exhausted' => $exhausted,
            'errors'    => $errors,
        ];
    }

    /**
     * Suspend subscriber due to data exhaustion.
     */
    public function suspendForDataExhausted(int $subscriberId, array $subData = []): bool
    {
        $sub = $this->getSubscriber($subscriberId);
        if (!$sub) return false;
        
        $now = date('Y-m-d H:i:s');
        
        // Update subscriber status
        $this->db->prepare(
            "UPDATE lte_subscribers SET status = 'suspended', magma_state = 'INACTIVE', updated_at = :now WHERE id = :id"
        )->execute([':id' => $subscriberId, ':now' => $now]);
        
        // Update subscription status
        $this->db->prepare(
            "UPDATE lte_subscriptions SET status = 'exhausted', updated_at = :now 
             WHERE subscriber_id = :sub_id AND status = 'active'"
        )->execute([':sub_id' => $subscriberId, ':now' => $now]);
        
        // Suspend in Magma
        if ($this->magmaEnabled && !empty($sub['imsi'])) {
            $this->magma->suspendSubscriber($sub['imsi']);
        }
        
        return true;
    }

    /**
     * Get renewal queue (subscriptions expiring soon).
     */
    public function getRenewalQueue(int $days = 7): array
    {
        $sql = "SELECT s.*, sub.package_name, sub.expires_at, sub.bytes_used, 
                       sub.bytes_allowed, sub.usage_percent, sub.status as sub_status
                FROM lte_subscribers s
                JOIN lte_subscriptions sub ON sub.subscriber_id = s.id AND sub.status = 'active'
                WHERE s.deleted_at IS NULL
                  AND sub.expires_at BETWEEN date('now') AND date('now', '+' || :days || ' days')
                ORDER BY sub.expires_at ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':days' => $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get SIM counts by status.
     */
    public function getSimCounts(): array
    {
        $sql = "SELECT status, COUNT(*) as cnt FROM lte_sims WHERE deleted_at IS NULL GROUP BY status";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /* ═══════════════════════════════════════════════════════
       HARDWARE MANAGEMENT (CPE/Routers)
    ═══════════════════════════════════════════════════════ */

    public function getHardware(array $filters = []): array
    {
        // Hardware table not yet created - return empty for now
        // TODO: Create lte_hardware table in migration 027
        return [];
    }

    public function createHardware(array $data): array
    {
        // Hardware table not yet created
        return ['error' => 'Hardware management not yet implemented'];
    }

    /* ═══════════════════════════════════════════════════════
       PACKAGE MANAGEMENT
    ═══════════════════════════════════════════════════════ */

    public function createPackage(array $data): array
    {
        $now = date('Y-m-d H:i:s');
        
        $stmt = $this->db->prepare(
            "INSERT INTO lte_packages 
             (name, description, price, type, bytes_allowed, days, magma_profile, is_active, created_at)
             VALUES (:name, :desc, :price, :type, :bytes, :days, :profile, :active, :now)"
        );
        
        $stmt->execute([
            ':name'    => $data['name'] ?? '',
            ':desc'    => $data['description'] ?? '',
            ':price'   => (float)($data['price'] ?? 0),
            ':type'    => (int)($data['type'] ?? 0),
            ':bytes'   => $data['bytes_allowed'] ?? null,
            ':days'    => (int)($data['days'] ?? 30),
            ':profile' => $data['magma_profile'] ?? 'default',
            ':active'  => (int)($data['is_active'] ?? 1),
            ':now'     => $now,
        ]);
        
        return $this->getPackage((int)$this->db->lastInsertId());
    }

    /* ═══════════════════════════════════════════════════════
       USAGE CACHE (for API compatibility)
    ═══════════════════════════════════════════════════════ */

    public function getCachedUsage(string $imsi): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT bytes_used, bytes_allowed, bytes_remaining, usage_percent, last_usage_sync
             FROM lte_subscriptions sub
             JOIN lte_subscribers s ON s.id = sub.subscriber_id
             WHERE s.imsi = :imsi AND sub.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([':imsi' => $imsi]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getAllCachedUsage(): array
    {
        $sql = "SELECT s.imsi, sub.bytes_used, sub.bytes_allowed, sub.usage_percent, sub.last_usage_sync
                FROM lte_subscriptions sub
                JOIN lte_subscribers s ON s.id = sub.subscriber_id
                WHERE sub.status = 'active' AND sub.bytes_allowed IS NOT NULL";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUsageSummary(array $filters = []): array
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN usage_percent >= 80 THEN 1 ELSE 0 END) as critical,
                    SUM(CASE WHEN usage_percent >= 50 AND usage_percent < 80 THEN 1 ELSE 0 END) as warning,
                    SUM(CASE WHEN usage_percent < 50 THEN 1 ELSE 0 END) as healthy
                FROM lte_subscriptions
                WHERE status = 'active' AND bytes_allowed IS NOT NULL";
        
        return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function refreshUsageCache(): array
    {
        // Trigger a sync from Prometheus
        return $this->syncUsageFromPrometheus();
    }

    /* ═══════════════════════════════════════════════════════
       NETWORK HEALTH (Magma gateway status)
    ═══════════════════════════════════════════════════════ */

    public function getCachedNetworkHealth(): ?array
    {
        if (!$this->magmaEnabled) {
            return null;
        }
        
        // Fetch live from Magma (could cache in future)
        return $this->magma->getGatewayStatus();
    }

    public function refreshNetworkHealth(): ?array
    {
        return $this->getCachedNetworkHealth();
    }
}


