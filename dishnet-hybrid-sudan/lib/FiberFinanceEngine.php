<?php
declare(strict_types=1);

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

/**
 * FiberFinanceEngine — DishNet Hybrid v4.10.3
 *
 * Full P&L engine for fiber services. Mirrors Fiber Finance plugin logic
 * but integrated into Hybrid with cashbook, notifications, and admin UI.
 *
 * Financial model:
 *   Revenue      = plan.revenue (what customer pays/month)
 *   Cost         = plan.cost_per_unit (what we pay supplier/month)
 *   Partner      = plan.partner_share (configurable per profit_mode)
 *   Net Profit   = Revenue - Cost - Partner
 *   Margin %     = (Profit / Revenue) × 100
 *
 * Only ACTIVE services generate revenue.
 * Suspended services = Revenue At Risk.
 * Splynx active + CRM not active = Leakage (we pay, don't bill).
 * CRM active + Splynx not active = Profit Anomaly (we bill, service is down).
 */
class FiberFinanceEngine
{
    private \PDO $db;
    private array $config;

    /** Default Splynx status → CRM canonical status (overridable via config['fiber_status_map']) */
    private const DEFAULT_STATUS_MAP = [
        'active'   => 'Active',
        'stopped'  => 'Suspended',
        'disabled' => 'Cancelled',
        'hidden'   => 'Cancelled',
        'pending'  => 'Pending',
        'blocked'  => 'Blocked',
    ];

    public function __construct(\PDO $pdo, array $config = [])
    {
        $this->db     = $pdo;
        $this->config = $config;
        $this->ensureTables();
    }

    /**
     * Get the active status map (config overrides defaults).
     */
    private function getStatusMap(): array
    {
        return array_merge(self::DEFAULT_STATUS_MAP, $this->config['fiber_status_map'] ?? []);
    }

    private function ensureTables(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_services_cache (
            id INTEGER PRIMARY KEY AUTOINCREMENT, splynx_service_id TEXT NOT NULL UNIQUE,
            splynx_customer_id TEXT NOT NULL, customer_name TEXT DEFAULT '', plan_name TEXT DEFAULT '',
            splynx_status TEXT DEFAULT '', crm_status TEXT DEFAULT NULL, crm_client_id TEXT DEFAULT NULL,
            ip_address TEXT DEFAULT '', download_speed TEXT DEFAULT '', upload_speed TEXT DEFAULT '',
            supplier TEXT DEFAULT '', tariff_price REAL DEFAULT 0, last_seen TEXT DEFAULT NULL,
            status_override INTEGER DEFAULT 0, override_reason TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT ''
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_customer_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT, splynx_customer_id TEXT NOT NULL UNIQUE,
            splynx_name TEXT DEFAULT '', splynx_email TEXT DEFAULT '', splynx_phone TEXT DEFAULT '',
            crm_client_id TEXT DEFAULT NULL, crm_name TEXT DEFAULT '', linked_by TEXT DEFAULT 'unmatched',
            linked_at TEXT DEFAULT NULL, last_sync TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT ''
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT, sync_type TEXT NOT NULL DEFAULT 'full',
            started_at TEXT NOT NULL, completed_at TEXT DEFAULT NULL,
            services_total INTEGER DEFAULT 0, services_new INTEGER DEFAULT 0, services_updated INTEGER DEFAULT 0,
            customers_mapped INTEGER DEFAULT 0, customers_unmapped INTEGER DEFAULT 0, errors INTEGER DEFAULT 0,
            log_text TEXT DEFAULT NULL, created_at TEXT NOT NULL DEFAULT ''
        )");
        // Status change history — for churn analytics
        $this->db->exec("CREATE TABLE IF NOT EXISTS fiber_status_changes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, splynx_service_id TEXT NOT NULL,
            old_status TEXT NOT NULL, new_status TEXT NOT NULL,
            changed_at TEXT NOT NULL DEFAULT '', source TEXT DEFAULT 'splynx'
        )");
        try { $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fsc_svc ON fiber_status_changes(splynx_service_id)"); } catch (\Throwable $e) {}
        try { $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fsc_date ON fiber_status_changes(changed_at)"); } catch (\Throwable $e) {}
        // Ensure revenue columns exist on fiber_plan_costs
        try { $this->db->exec("ALTER TABLE fiber_plan_costs ADD COLUMN revenue REAL DEFAULT 0"); } catch (\Throwable $e) {}
        try { $this->db->exec("ALTER TABLE fiber_plan_costs ADD COLUMN partner_share REAL DEFAULT 0"); } catch (\Throwable $e) {}
        try { $this->db->exec("ALTER TABLE fiber_plan_costs ADD COLUMN profit_mode TEXT DEFAULT 'fixed'"); } catch (\Throwable $e) {}
        try { $this->db->exec("ALTER TABLE fiber_plan_costs ADD COLUMN crm_plan_name TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    }

    // ══════════════════════════════════════════════════════════════════════
    // SYNC — Pull from Splynx, cache locally, map customers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Full sync: pull customers + services from Splynx, cache, auto-map.
     */
    public function runSync(): array
    {
        $started = date('Y-m-d H:i:s');
        $log = [];
        $stats = ['services_total' => 0, 'services_new' => 0, 'services_updated' => 0,
                  'customers_mapped' => 0, 'customers_unmapped' => 0, 'errors' => 0];

        require_once __DIR__ . '/SplynxApiClient.php';
        $splynx = SplynxApiClient::fromConfig($this->config);
        if (!$splynx->isConfigured()) {
            return ['ok' => false, 'error' => 'Splynx not configured'];
        }

        // 1. Fetch all customers
        $log[] = '[' . date('H:i:s') . '] Fetching customers from Splynx...';
        $customers = $splynx->get('api/2.0/admin/customers/customer') ?? [];
        $log[] = '[' . date('H:i:s') . '] Found ' . count($customers) . ' customers';

        // 2. Fetch all internet services
        $log[] = '[' . date('H:i:s') . '] Fetching internet services...';
        $services = $splynx->get('api/2.0/admin/customers/customer-internet-services') ?? [];
        $log[] = '[' . date('H:i:s') . '] Found ' . count($services) . ' services';

        // 3. Fetch tariffs for plan name + price lookup
        $tariffs = $splynx->getTariffs();
        $tariffIndex = [];
        foreach ($tariffs as $t) {
            $tariffIndex[(string)($t['id'] ?? '')] = $t;
        }

        // 4. Build customer index
        $custIndex = [];
        foreach ($customers as $c) {
            $custIndex[(string)($c['id'] ?? '')] = $c;
        }

        // 5. Upsert customers into fiber_customer_map
        $log[] = '[' . date('H:i:s') . '] Syncing customer map...';
        $this->db->beginTransaction();
        try {
            $upsertCust = $this->db->prepare(
                "INSERT INTO fiber_customer_map (splynx_customer_id, splynx_name, splynx_email, splynx_phone, last_sync)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(splynx_customer_id) DO UPDATE SET
                 splynx_name = excluded.splynx_name, splynx_email = excluded.splynx_email,
                 splynx_phone = excluded.splynx_phone, last_sync = excluded.last_sync"
            );
            foreach ($customers as $c) {
                $cid = (string)($c['id'] ?? '');
                if (!$cid) continue;
                $name = trim(($c['name'] ?? '') ?: (($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')));
                $upsertCust->execute([$cid, $name, $c['email'] ?? '', $c['phone'] ?? '', $started]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $stats['errors']++;
            $log[] = '[' . date('H:i:s') . '] Customer sync error: ' . $e->getMessage();
        }

        // 6. Auto-map unmapped customers to CRM
        $mapped = $this->autoMapCustomers();
        $stats['customers_mapped'] = $mapped['mapped'];
        $stats['customers_unmapped'] = $mapped['unmapped'];
        $log[] = "[" . date('H:i:s') . "] Auto-map: {$mapped['mapped']} mapped, {$mapped['unmapped']} unmapped";

        // 7. Upsert services into fiber_services_cache
        $log[] = '[' . date('H:i:s') . '] Syncing services...';
        $defaultSupplier = trim($this->config['default_supplier'] ?? $this->config['splynx_default_supplier'] ?? 'Fiber Provider');
        $now = date('Y-m-d H:i:s');
        $stats['services_total'] = count($services);

        $this->db->beginTransaction();
        try {
            $upsertSvc = $this->db->prepare(
                "INSERT INTO fiber_services_cache
                    (splynx_service_id, splynx_customer_id, customer_name, plan_name, splynx_status,
                     ip_address, download_speed, upload_speed, supplier, tariff_price, last_seen, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(splynx_service_id) DO UPDATE SET
                    splynx_customer_id = excluded.splynx_customer_id,
                    customer_name = excluded.customer_name,
                    plan_name = excluded.plan_name,
                    splynx_status = CASE WHEN fiber_services_cache.status_override = 1 THEN fiber_services_cache.splynx_status ELSE excluded.splynx_status END,
                    ip_address = excluded.ip_address,
                    download_speed = excluded.download_speed,
                    upload_speed = excluded.upload_speed,
                    tariff_price = excluded.tariff_price,
                    last_seen = excluded.last_seen,
                    updated_at = excluded.updated_at"
            );

            foreach ($services as $svc) {
                $sid       = (string)($svc['id'] ?? '');
                $custId    = (string)($svc['customer_id'] ?? '');
                if (!$sid) continue;

                $tariffId  = (string)($svc['tariff_id'] ?? '');
                $tariff    = $tariffIndex[$tariffId] ?? null;
                $planName  = $tariff['title'] ?? $tariff['name'] ?? $svc['description'] ?? '';
                $splynxSt  = strtolower($svc['status'] ?? '');
                $custData  = $custIndex[$custId] ?? null;
                $custName  = '';
                if ($custData) {
                    $custName = trim(($custData['name'] ?? '') ?: (($custData['first_name'] ?? '') . ' ' . ($custData['last_name'] ?? '')));
                }

                $isNew = false;
                $oldStatus = '';
                $existRow = $this->db->query("SELECT splynx_status, status_override FROM fiber_services_cache WHERE splynx_service_id = " . $this->db->quote($sid))->fetch(\PDO::FETCH_ASSOC);
                if ($existRow) {
                    $oldStatus = $existRow['splynx_status'];
                    // Don't overwrite if status_override is set
                    if ((int)$existRow['status_override'] === 1) $splynxSt = $oldStatus;
                } else {
                    $isNew = true;
                }

                $upsertSvc->execute([
                    $sid, $custId, $custName, $planName, $splynxSt,
                    $svc['ipv4'] ?? $svc['ip'] ?? '',
                    (string)($tariff['speed_download'] ?? $svc['speed_download'] ?? ''),
                    (string)($tariff['speed_upload'] ?? $svc['speed_upload'] ?? ''),
                    $defaultSupplier,
                    (float)($tariff['price'] ?? 0),
                    $now, $now,
                ]);

                // Log status change
                $statusMap = $this->getStatusMap();
                $newCrm = $statusMap[$splynxSt] ?? 'Suspended';
                if ($isNew) {
                    $this->logStatusChange($sid, 'NEW', $newCrm, 'splynx');
                    $stats['services_new']++;
                } else {
                    $oldCrm = $statusMap[$oldStatus] ?? 'Suspended';
                    if ($oldCrm !== $newCrm) {
                        $this->logStatusChange($sid, $oldCrm, $newCrm, 'splynx');
                    }
                    $stats['services_updated']++;
                }
            }

            // Mark services not seen in this sync as suspended (unless overridden)
            // First, collect IDs that will change for status logging
            $staleActive = $this->db->prepare(
                "SELECT splynx_service_id FROM fiber_services_cache
                 WHERE last_seen < ? AND status_override = 0 AND splynx_status = 'active'"
            );
            $staleActive->execute([$now]);
            foreach ($staleActive->fetchAll(\PDO::FETCH_COLUMN) as $staleSid) {
                $this->logStatusChange($staleSid, 'Active', 'Suspended', 'splynx');
            }

            $this->db->prepare(
                "UPDATE fiber_services_cache SET splynx_status = 'stopped', updated_at = ?
                 WHERE last_seen < ? AND status_override = 0 AND splynx_status = 'active'"
            )->execute([$now, $now]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $stats['errors']++;
            $log[] = '[' . date('H:i:s') . '] Service sync error: ' . $e->getMessage();
        }

        // 8. Enrich services with CRM status from customer map
        $this->enrichCrmStatus();
        $log[] = '[' . date('H:i:s') . '] CRM status enrichment done';

        // 9. Auto-populate plan costs from tariffs
        $this->autoPopulatePlanCosts($tariffIndex, $defaultSupplier);
        $log[] = '[' . date('H:i:s') . '] Plan costs auto-populated from tariffs';

        $completed = date('Y-m-d H:i:s');
        $log[] = "[{$completed}] Sync complete";

        // Save sync log
        $this->db->prepare(
            "INSERT INTO fiber_sync_log (sync_type, started_at, completed_at, services_total, services_new,
             services_updated, customers_mapped, customers_unmapped, errors, log_text)
             VALUES ('full', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([$started, $completed, $stats['services_total'], $stats['services_new'],
            $stats['services_updated'], $stats['customers_mapped'], $stats['customers_unmapped'],
            $stats['errors'], implode("\n", $log)]);

        return array_merge(['ok' => true, 'log' => $log], $stats);
    }

    /**
     * Auto-map unmapped Splynx customers to CRM clients by email → phone → name.
     */
    private function autoMapCustomers(): array
    {
        $unmapped = $this->db->query(
            "SELECT * FROM fiber_customer_map WHERE crm_client_id IS NULL OR crm_client_id = ''"
        )->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($unmapped)) {
            $total = (int)$this->db->query("SELECT COUNT(*) FROM fiber_customer_map")->fetchColumn();
            return ['mapped' => $total, 'unmapped' => 0];
        }

        // Fetch CRM clients
        try {
            require_once __DIR__ . '/CrmApiClient.php';
            $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $this->config);
            $crmClients = $crm->get('clients') ?? [];
        } catch (\Throwable $e) {
            $cnt = (int)$this->db->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NOT NULL AND crm_client_id != ''")->fetchColumn();
            return ['mapped' => $cnt, 'unmapped' => count($unmapped)];
        }

        // Build CRM lookup indexes
        $byEmail = []; $byPhone = []; $byName = [];
        foreach ($crmClients as $cc) {
            $cid = (string)($cc['id'] ?? '');
            if (!$cid) continue;
            $email = strtolower(trim($cc['contacts'][0]['email'] ?? ''));
            $phone = preg_replace('/[^0-9]/', '', $cc['contacts'][0]['phone'] ?? '');
            $name  = strtolower(trim(($cc['firstName'] ?? '') . ' ' . ($cc['lastName'] ?? '')));
            $cname = strtolower(trim($cc['companyName'] ?? ''));
            if ($email) $byEmail[$email] = $cid;
            if ($phone && strlen($phone) >= 8) $byPhone[$phone] = $cid;
            if ($name && strlen($name) > 3) $byName[$name] = $cid;
            if ($cname && strlen($cname) > 3) $byName[$cname] = $cid;
        }

        $mappedCount = 0;
        $update = $this->db->prepare(
            "UPDATE fiber_customer_map SET crm_client_id = ?, crm_name = ?, linked_by = ?, linked_at = ? WHERE splynx_customer_id = ?"
        );

        foreach ($unmapped as $um) {
            $crmId = null; $method = '';
            $email = strtolower(trim($um['splynx_email'] ?? ''));
            $phone = preg_replace('/[^0-9]/', '', $um['splynx_phone'] ?? '');
            $name  = strtolower(trim($um['splynx_name'] ?? ''));

            // Priority: email → phone → name
            if ($email && isset($byEmail[$email])) {
                $crmId = $byEmail[$email]; $method = 'auto_email';
            } elseif ($phone && strlen($phone) >= 8 && isset($byPhone[$phone])) {
                $crmId = $byPhone[$phone]; $method = 'auto_phone';
            } elseif ($name && isset($byName[$name])) {
                $crmId = $byName[$name]; $method = 'auto_name';
            }

            if ($crmId) {
                // Find CRM name
                $crmName = '';
                foreach ($crmClients as $cc) {
                    if ((string)($cc['id'] ?? '') === $crmId) {
                        $crmName = trim(($cc['firstName'] ?? '') . ' ' . ($cc['lastName'] ?? ''));
                        if (!$crmName) $crmName = $cc['companyName'] ?? '';
                        break;
                    }
                }
                $update->execute([$crmId, $crmName, $method, date('Y-m-d H:i:s'), $um['splynx_customer_id']]);
                $mappedCount++;
            }
        }

        $totalMapped = (int)$this->db->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NOT NULL AND crm_client_id != ''")->fetchColumn();
        $totalUnmapped = (int)$this->db->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NULL OR crm_client_id = ''")->fetchColumn();

        return ['mapped' => $totalMapped, 'unmapped' => $totalUnmapped, 'newly_mapped' => $mappedCount];
    }

    /**
     * Enrich service cache with CRM client IDs from customer map.
     */
    private function enrichCrmStatus(): void
    {
        // Build dynamic CASE from configurable status map
        $map = $this->getStatusMap();
        $caseParts = [];
        foreach ($map as $splynx => $crm) {
            $caseParts[] = "WHEN fiber_services_cache.splynx_status = " . $this->db->quote($splynx) . " THEN " . $this->db->quote($crm);
        }
        $caseStr = implode(' ', $caseParts);
        if (empty($caseStr)) $caseStr = "WHEN 1=0 THEN 'Active'"; // fallback

        $this->db->exec(
            "UPDATE fiber_services_cache SET
                crm_client_id = (SELECT crm_client_id FROM fiber_customer_map WHERE fiber_customer_map.splynx_customer_id = fiber_services_cache.splynx_customer_id),
                crm_status = CASE
                    WHEN fiber_services_cache.status_override = 1 THEN fiber_services_cache.crm_status
                    ELSE (SELECT CASE {$caseStr} ELSE 'Suspended' END)
                END"
        );
    }

    /**
     * Auto-create plan cost entries from Splynx tariffs (purchase_cost = tariff price).
     */
    private function autoPopulatePlanCosts(array $tariffIndex, string $defaultSupplier): void
    {
        $existing = [];
        $rows = $this->db->query("SELECT plan_name FROM fiber_plan_costs WHERE effective_to IS NULL")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($rows as $r) { $existing[$r] = true; }

        $insert = $this->db->prepare(
            "INSERT INTO fiber_plan_costs (supplier, plan_name, cost_per_unit, revenue, partner_share, profit_mode, effective_from, source)
             VALUES (?, ?, ?, 0, 0, 'fixed', ?, 'splynx_sync')"
        );

        foreach ($tariffIndex as $t) {
            $name = $t['title'] ?? $t['name'] ?? '';
            if (!$name || isset($existing[$name])) continue;
            $insert->execute([$defaultSupplier, $name, (float)($t['price'] ?? 0), date('Y-m-d')]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // P&L ENGINE — Revenue, Cost, Profit, Margin
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Calculate per-service profit.
     */
    public static function calcServiceProfit(float $revenue, float $cost, float $partnerShare, string $profitMode = 'fixed'): array
    {
        if ($profitMode === 'revenue_share') {
            $partnerAmt = round($revenue * ($partnerShare / 100), 2);
        } elseif ($profitMode === 'profit_share') {
            $gross = round($revenue - $cost, 2);
            $partnerAmt = round($gross * ($partnerShare / 100), 2);
        } else {
            $partnerAmt = $partnerShare;
        }

        $profit = round($revenue - $cost - $partnerAmt, 2);
        $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;

        return [
            'revenue'       => $revenue,
            'cost'          => $cost,
            'partner_share' => round($partnerAmt, 2),
            'profit'        => $profit,
            'margin'        => $margin,
            'is_profitable' => $profit > 0,
        ];
    }

    /**
     * Full portfolio P&L summary from cached services + plan costs.
     */
    public function calculatePnL(): array
    {
        $services  = $this->db->query("SELECT * FROM fiber_services_cache")->fetchAll(\PDO::FETCH_ASSOC);
        $planCosts = $this->getPlanIndex();

        $totals = ['revenue' => 0, 'cost' => 0, 'partner' => 0, 'profit' => 0,
                   'active' => 0, 'suspended' => 0, 'cancelled' => 0, 'pending' => 0,
                   'revenue_at_risk' => 0, 'supplier_payable' => 0];
        $byPlan = [];
        $seenCustomers = [];

        foreach ($services as $svc) {
            $status   = $svc['crm_status'] ?: (self::DEFAULT_STATUS_MAP[$svc['splynx_status']] ?? 'Suspended');
            $planName = $svc['plan_name'] ?? '';
            $plan     = $planCosts[$planName] ?? null;

            $revenue = (float)($plan['revenue'] ?? 0);
            $cost    = (float)($plan['cost_per_unit'] ?? $svc['tariff_price'] ?? 0);
            $partner = (float)($plan['partner_share'] ?? 0);
            $mode    = $plan['profit_mode'] ?? 'fixed';
            $calc    = self::calcServiceProfit($revenue, $cost, $partner, $mode);

            $custId = $svc['crm_client_id'] ?: $svc['splynx_customer_id'];
            if ($custId && !isset($seenCustomers[$custId])) $seenCustomers[$custId] = true;

            if ($status === 'Active') {
                $totals['active']++;
                $totals['revenue']          += $calc['revenue'];
                $totals['cost']             += $calc['cost'];
                $totals['partner']          += $calc['partner_share'];
                $totals['profit']           += $calc['profit'];
                $totals['supplier_payable'] += $calc['cost'];

                if (!isset($byPlan[$planName])) {
                    $byPlan[$planName] = ['plan' => $planName, 'count' => 0, 'revenue' => 0, 'cost' => 0,
                        'partner' => 0, 'profit' => 0, 'margin' => 0];
                }
                $byPlan[$planName]['count']++;
                $byPlan[$planName]['revenue'] += $calc['revenue'];
                $byPlan[$planName]['cost']    += $calc['cost'];
                $byPlan[$planName]['partner'] += $calc['partner_share'];
                $byPlan[$planName]['profit']  += $calc['profit'];
            } elseif ($status === 'Suspended') {
                $totals['suspended']++;
                $totals['revenue_at_risk'] += $revenue;
            } elseif (in_array($status, ['Cancelled', 'Expired'])) {
                $totals['cancelled']++;
            } elseif ($status === 'Pending') {
                $totals['pending']++;
            }
        }

        // Finalize per-plan margins
        foreach ($byPlan as &$bp) {
            $bp['margin'] = $bp['revenue'] > 0 ? round(($bp['profit'] / $bp['revenue']) * 100, 1) : 0;
        }
        unset($bp);

        $overallMargin = $totals['revenue'] > 0 ? round(($totals['profit'] / $totals['revenue']) * 100, 1) : 0;

        return [
            'total_customers'     => count($seenCustomers),
            'total_services'      => count($services),
            'active'              => $totals['active'],
            'suspended'           => $totals['suspended'],
            'cancelled'           => $totals['cancelled'],
            'pending'             => $totals['pending'],
            'total_revenue'       => round($totals['revenue'], 2),
            'total_cost'          => round($totals['cost'], 2),
            'total_partner_share' => round($totals['partner'], 2),
            'total_profit'        => round($totals['profit'], 2),
            'overall_margin'      => $overallMargin,
            'revenue_at_risk'     => round($totals['revenue_at_risk'], 2),
            'supplier_payable'    => round($totals['supplier_payable'], 2),
            'by_plan'             => array_values($byPlan),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // LEAKAGE + ANOMALY DETECTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Leakage: Active in Splynx but NOT Active in CRM.
     * We pay the supplier but don't bill the customer.
     */
    public function detectLeakage(): array
    {
        $planCosts = $this->getPlanIndex();
        $leaks = [];
        $totalLeak = 0;

        // Services active in Splynx
        $stmt = $this->db->query(
            "SELECT s.*, m.crm_client_id as map_crm_id, m.crm_name
             FROM fiber_services_cache s
             LEFT JOIN fiber_customer_map m ON m.splynx_customer_id = s.splynx_customer_id
             WHERE s.splynx_status = 'active'"
        );
        $activeSvcs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($activeSvcs as $svc) {
            $crmStatus = $svc['crm_status'] ?? '';
            // Leak = Splynx active but CRM not Active (or no CRM mapping)
            if ($crmStatus === 'Active') continue;

            $plan     = $planCosts[$svc['plan_name']] ?? null;
            $cost     = (float)($plan['cost_per_unit'] ?? $svc['tariff_price'] ?? 0);
            $revenue  = (float)($plan['revenue'] ?? 0);

            $leaks[] = [
                'service_id'    => $svc['splynx_service_id'],
                'customer_name' => $svc['customer_name'],
                'plan_name'     => $svc['plan_name'],
                'splynx_status' => $svc['splynx_status'],
                'crm_status'    => $crmStatus ?: 'No CRM mapping',
                'crm_client_id' => $svc['map_crm_id'] ?? '',
                'monthly_cost'  => $cost,
                'monthly_revenue' => $revenue,
                'monthly_loss'  => $cost,
                'last_seen'     => $svc['last_seen'] ?? '',
            ];
            $totalLeak += $cost;
        }

        return [
            'leaks'              => $leaks,
            'count'              => count($leaks),
            'total_monthly_loss' => round($totalLeak, 2),
        ];
    }

    /**
     * Profit anomaly: CRM says Active but Splynx says NOT active.
     * We bill the customer but service is down — customer is paying for nothing.
     */
    public function detectProfitAnomalies(): array
    {
        $planCosts = $this->getPlanIndex();
        $anomalies = [];

        // Services NOT active in Splynx but CRM status is Active
        $stmt = $this->db->query(
            "SELECT * FROM fiber_services_cache
             WHERE splynx_status != 'active' AND crm_status = 'Active'"
        );

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $svc) {
            $plan    = $planCosts[$svc['plan_name']] ?? null;
            $revenue = (float)($plan['revenue'] ?? 0);

            $anomalies[] = [
                'service_id'      => $svc['splynx_service_id'],
                'customer_name'   => $svc['customer_name'],
                'plan_name'       => $svc['plan_name'],
                'splynx_status'   => $svc['splynx_status'],
                'crm_status'      => $svc['crm_status'],
                'monthly_revenue' => $revenue,
            ];
        }

        return [
            'anomalies' => $anomalies,
            'count'     => count($anomalies),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // SERVICE + CUSTOMER VIEWS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Get all cached services with optional filters.
     */
    public function getServices(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $where = []; $params = [];
        if (!empty($filters['status'])) {
            $where[] = "splynx_status = ?"; $params[] = strtolower($filters['status']);
        }
        if (!empty($filters['plan'])) {
            $where[] = "plan_name = ?"; $params[] = $filters['plan'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(customer_name LIKE ? OR plan_name LIKE ? OR splynx_service_id LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int)$this->db->prepare("SELECT COUNT(*) FROM fiber_services_cache {$wc}")->execute($params) ?
            $this->db->prepare("SELECT COUNT(*) FROM fiber_services_cache {$wc}") : null;
        // Re-execute for count
        $cStmt = $this->db->prepare("SELECT COUNT(*) FROM fiber_services_cache {$wc}");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $sql = "SELECT * FROM fiber_services_cache {$wc} ORDER BY customer_name ASC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        return ['items' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'total' => $total];
    }

    /**
     * Get customer mapping table.
     */
    public function getCustomerMap(string $filter = 'all', int $limit = 100): array
    {
        $where = '';
        if ($filter === 'mapped') $where = "WHERE crm_client_id IS NOT NULL AND crm_client_id != ''";
        elseif ($filter === 'unmapped') $where = "WHERE crm_client_id IS NULL OR crm_client_id = ''";

        $stmt = $this->db->prepare("SELECT * FROM fiber_customer_map {$where} ORDER BY splynx_name ASC LIMIT ?");
        $stmt->execute([$limit]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $mapped   = (int)$this->db->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NOT NULL AND crm_client_id != ''")->fetchColumn();
        $unmapped = (int)$this->db->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NULL OR crm_client_id = ''")->fetchColumn();

        return ['items' => $items, 'mapped' => $mapped, 'unmapped' => $unmapped, 'total' => $mapped + $unmapped];
    }

    /**
     * Manually link a Splynx customer to a CRM client.
     */
    public function manualMapCustomer(string $splynxCustId, string $crmClientId, string $crmName = ''): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE fiber_customer_map SET crm_client_id = ?, crm_name = ?, linked_by = 'manual', linked_at = ?
             WHERE splynx_customer_id = ?"
        );
        $stmt->execute([$crmClientId, $crmName, date('Y-m-d H:i:s'), $splynxCustId]);
        if ($stmt->rowCount() > 0) {
            $this->enrichCrmStatus();
            return true;
        }
        return false;
    }

    /**
     * Override a service's CRM status manually.
     */
    public function overrideServiceStatus(string $splynxServiceId, string $newStatus, string $reason = ''): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE fiber_services_cache SET crm_status = ?, status_override = 1, override_reason = ?, updated_at = ?
             WHERE splynx_service_id = ?"
        );
        $stmt->execute([$newStatus, $reason, date('Y-m-d H:i:s'), $splynxServiceId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get last sync info.
     */
    public function getLastSync(): ?array
    {
        return $this->db->query("SELECT * FROM fiber_sync_log ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get low-margin plans (below threshold).
     */
    public function getLowMarginPlans(float $threshold = 10.0): array
    {
        $pnl = $this->calculatePnL();
        return array_filter($pnl['by_plan'], function ($p) use ($threshold) {
            return $p['revenue'] > 0 && $p['margin'] < $threshold;
        });
    }

    // ══════════════════════════════════════════════════════════════════════
    // STATUS CHANGE LOGGING — records every status transition for churn analytics
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Log a status change. Called during sync when a service changes status.
     */
    private function logStatusChange(string $serviceId, string $oldStatus, string $newStatus, string $source = 'splynx'): void
    {
        if ($oldStatus === $newStatus) return;
        try {
            $this->db->prepare(
                "INSERT INTO fiber_status_changes (splynx_service_id, old_status, new_status, changed_at, source) VALUES (?, ?, ?, ?, ?)"
            )->execute([$serviceId, $oldStatus, $newStatus, date('Y-m-d H:i:s'), $source]);
            // Prune: keep last 2000
            $count = (int)$this->db->query("SELECT COUNT(*) FROM fiber_status_changes")->fetchColumn();
            if ($count > 2000) {
                $cutoff = $this->db->query("SELECT changed_at FROM fiber_status_changes ORDER BY id DESC LIMIT 1 OFFSET 2000")->fetchColumn();
                if ($cutoff) $this->db->exec("DELETE FROM fiber_status_changes WHERE changed_at < '{$cutoff}'");
            }
        } catch (\Throwable $e) {}
        $this->emitEvent($oldStatus === '' ? 'service_new' : 'status_changed', [
            'service_id' => $serviceId, 'old' => $oldStatus, 'new' => $newStatus, 'source' => $source,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // CHURN ANALYTICS — churned, suspended, reactivated counts in date range
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Calculate churn metrics from status change history.
     * Mirrors FiberFinanceCalculator::calculateChurn() from Fiber Finance plugin.
     */
    public function calculateChurn(string $from = '', string $to = ''): array
    {
        $where = []; $params = [];
        if ($from) { $where[] = "changed_at >= ?"; $params[] = $from; }
        if ($to)   { $where[] = "changed_at <= ?"; $params[] = $to . ' 23:59:59'; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("SELECT old_status, new_status FROM fiber_status_changes {$wc}");
        $stmt->execute($params);

        $churned = 0; $suspended = 0; $reactivated = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $old = $row['old_status']; $new = $row['new_status'];
            if ($old === 'Active' && in_array($new, ['Cancelled', 'Expired'])) $churned++;
            elseif ($old === 'Active' && $new === 'Suspended') $suspended++;
            elseif (in_array($old, ['Suspended', 'Cancelled']) && $new === 'Active') $reactivated++;
        }

        return [
            'churned'      => $churned,
            'suspended'    => $suspended,
            'reactivated'  => $reactivated,
            'net_change'   => $reactivated - $churned,
            'from'         => $from ?: 'all time',
            'to'           => $to ?: 'now',
        ];
    }

    /**
     * Get status change history for display.
     */
    public function getStatusLog(int $limit = 100): array
    {
        $stmt = $this->db->prepare(
            "SELECT sc.*, fsc.customer_name, fsc.plan_name
             FROM fiber_status_changes sc
             LEFT JOIN fiber_services_cache fsc ON fsc.splynx_service_id = sc.splynx_service_id
             ORDER BY sc.id DESC LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ══════════════════════════════════════════════════════════════════════
    // SYNC HEALTH — healthy / stale / critical based on last sync age
    // ══════════════════════════════════════════════════════════════════════

    public function getSyncHealth(): array
    {
        $last = $this->getLastSync();
        $lastAt = $last['completed_at'] ?? $last['started_at'] ?? null;
        $health = 'unknown';
        $ageHours = null;

        if ($lastAt) {
            $ageHours = round((time() - strtotime($lastAt)) / 3600, 1);
            if ($ageHours < 2)      $health = 'healthy';
            elseif ($ageHours < 6)  $health = 'stale';
            else                    $health = 'critical';
        }

        return [
            'health'     => $health,
            'last_sync'  => $lastAt,
            'age_hours'  => $ageHours,
            'services'   => $last['services_total'] ?? 0,
            'errors'     => $last['errors'] ?? 0,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // AUTO-FIX / RECONCILIATION ENGINE with mass change guard
    // Mirrors ReconciliationEngine from Fiber Finance plugin
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Run reconciliation: detect leakage + anomalies, optionally auto-fix.
     * If autoFix=true and >30% of services would change, blocks and returns pending fixes.
     *
     * @param bool $autoFix  Whether to auto-correct CRM status from Splynx
     * @param bool $force    Override mass change guard (operator confirmed)
     * @return array
     */
    public function runReconciliation(bool $autoFix = false, bool $force = false): array
    {
        $statusMap = $this->getStatusMap();
        $planCosts = $this->getPlanIndex();
        $services  = $this->db->query("SELECT * FROM fiber_services_cache")->fetchAll(\PDO::FETCH_ASSOC);
        $total     = count($services);

        $leakageQueue    = [];
        $profitAnomalies = [];
        $pendingFixes    = [];

        foreach ($services as $svc) {
            $crmStatus   = $svc['crm_status'] ?? 'Suspended';
            $splynxRaw   = strtolower($svc['splynx_status'] ?? '');
            $canonicalCrm = $statusMap[$splynxRaw] ?? null;
            if ($splynxRaw === '' || $canonicalCrm === null) continue;

            $plan    = $planCosts[$svc['plan_name']] ?? null;
            $revenue = (float)($plan['revenue'] ?? 0);
            $cost    = (float)($plan['cost_per_unit'] ?? $svc['tariff_price'] ?? 0);

            // Case A: Leakage — Splynx active, CRM not active
            if ($splynxRaw === 'active' && $crmStatus !== 'Active') {
                $leakageQueue[] = [
                    'service_id'    => $svc['splynx_service_id'],
                    'customer_name' => $svc['customer_name'],
                    'plan_name'     => $svc['plan_name'],
                    'splynx_status' => $svc['splynx_status'],
                    'crm_status'    => $crmStatus,
                    'monthly_cost'  => $cost,
                    'monthly_loss'  => $cost,
                    'type'          => 'leakage',
                ];
                if ($autoFix) {
                    $pendingFixes[] = ['id' => $svc['splynx_service_id'], 'old' => $crmStatus, 'new' => 'Active'];
                }
                $this->emitEvent('leakage_detected', [
                    'service_id' => $svc['splynx_service_id'], 'customer' => $svc['customer_name'],
                    'crm_status' => $crmStatus, 'monthly_loss' => $cost,
                ]);
            }

            // Case B: Profit anomaly — Splynx not active, CRM active
            if ($splynxRaw !== 'active' && $crmStatus === 'Active') {
                $profitAnomalies[] = [
                    'service_id'      => $svc['splynx_service_id'],
                    'customer_name'   => $svc['customer_name'],
                    'plan_name'       => $svc['plan_name'],
                    'splynx_status'   => $svc['splynx_status'],
                    'crm_status'      => $crmStatus,
                    'monthly_revenue' => $revenue,
                    'type'            => 'profit_anomaly',
                ];
                if ($autoFix) {
                    $pendingFixes[] = ['id' => $svc['splynx_service_id'], 'old' => $crmStatus, 'new' => $canonicalCrm];
                }
                $this->emitEvent('profit_anomaly_detected', [
                    'service_id' => $svc['splynx_service_id'], 'customer' => $svc['customer_name'],
                    'crm_status' => $crmStatus, 'splynx_status' => $svc['splynx_status'],
                ]);
            }
        }

        // Mass change guard: if >30% would change, block
        $fixCount   = count($pendingFixes);
        $massChange = $total > 0 && ($fixCount / $total) > 0.30;
        $autoFixed  = [];

        if ($autoFix && (!$massChange || $force) && $fixCount > 0) {
            $fixIndex = [];
            foreach ($pendingFixes as $fix) { $fixIndex[$fix['id']] = $fix; }

            $update = $this->db->prepare(
                "UPDATE fiber_services_cache SET crm_status = ?, updated_at = ? WHERE splynx_service_id = ? AND status_override = 0"
            );
            foreach ($fixIndex as $id => $fix) {
                $update->execute([$fix['new'], date('Y-m-d H:i:s'), $id]);
                if ($update->rowCount() > 0) {
                    $autoFixed[] = $fix;
                    $this->logStatusChange($id, $fix['old'], $fix['new'], 'auto_fix');
                    $this->emitEvent('auto_fix_applied', [
                        'service_id' => $id, 'old_status' => $fix['old'], 'new_status' => $fix['new'],
                    ]);
                }
            }
        }

        if ($massChange && !$force) {
            $this->emitEvent('mass_change_blocked', [
                'pending_fixes' => $fixCount, 'total_services' => $total,
                'pct_change' => round(($fixCount / $total) * 100, 1),
            ]);
        }

        $result = [
            'run_at'                 => date('Y-m-d H:i:s'),
            'total_services'         => $total,
            'leakage_count'          => count($leakageQueue),
            'leakage_total_monthly'  => round(array_sum(array_column($leakageQueue, 'monthly_loss')), 2),
            'profit_anomaly_count'   => count($profitAnomalies),
            'auto_fix_enabled'       => $autoFix,
            'auto_fixed_count'       => count($autoFixed),
            'mass_change_blocked'    => $massChange && !$force,
            'pending_fix_count'      => ($massChange && !$force) ? $fixCount : 0,
            'leakage_queue'          => $leakageQueue,
            'profit_anomalies'       => $profitAnomalies,
            'auto_fixed'             => $autoFixed,
        ];

        return $result;
    }

    // ══════════════════════════════════════════════════════════════════════
    // EVENT AUDIT LOG — structured event trail (rotating, last 5000 entries)
    // Mirrors ReconciliationEngine::emitEvent() from Fiber Finance plugin
    // ══════════════════════════════════════════════════════════════════════

    private function emitEvent(string $eventType, array $payload): void
    {
        try {
            $dataDir = method_exists($this->db, 'getDataDir') ? $this->db->getDataDir() : '';
            if (!$dataDir) {
                // Fallback: use a sibling data/ dir or temp
                $dataDir = dirname(__DIR__) . '/data';
                if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
            }
            $logFile = $dataDir . '/fiber_events.log';
            $entry = json_encode([
                'ts'    => date('Y-m-d H:i:s'),
                'event' => $eventType,
                'data'  => $payload,
            ], JSON_UNESCAPED_UNICODE);

            @file_put_contents($logFile, $entry . "\n", FILE_APPEND | LOCK_EX);

            // Rotate: keep last 5000 lines
            if (file_exists($logFile) && filesize($logFile) > 500000) {
                $lines = file($logFile);
                if (count($lines) > 5000) {
                    @file_put_contents($logFile, implode('', array_slice($lines, -4000)), LOCK_EX);
                }
            }
        } catch (\Throwable $e) {
            // Never break main flow
        }
    }

    /**
     * Get recent events from the audit log.
     */
    public function getEventLog(int $limit = 50): array
    {
        try {
            $dataDir = method_exists($this->db, 'getDataDir') ? $this->db->getDataDir() : dirname(__DIR__) . '/data';
            $logFile = $dataDir . '/fiber_events.log';
            if (!file_exists($logFile)) return [];
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);
            $events = [];
            foreach (array_slice($lines, 0, $limit) as $line) {
                $parsed = json_decode($line, true);
                if (is_array($parsed)) $events[] = $parsed;
            }
            return $events;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // ENHANCED P&L — adds true_margin, profit leak flagging, sync health
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Full KPI cache — mirrors KPIEngine.rebuild() from Fiber Finance plugin.
     * Includes true_margin, leakage_exposure, profit_leak_plan_count, sync_health.
     */
    public function buildKpiCache(): array
    {
        $pnl     = $this->calculatePnL();
        $leakage = $this->detectLeakage();
        $health  = $this->getSyncHealth();
        $marginThreshold = (float)($this->config['fiber_margin_threshold'] ?? 10.0);

        // True margin: profit / (cost + partner) × 100
        $denominator = $pnl['total_cost'] + $pnl['total_partner_share'];
        $trueOverallMargin = $denominator > 0 ? round(($pnl['total_profit'] / $denominator) * 100, 1) : 0;

        // Per-plan true margin + leak flagging
        $planStats = [];
        foreach ($pnl['by_plan'] as $bp) {
            $denom = $bp['cost'] + ($bp['partner'] ?? 0);
            $trueMargin = $denom > 0 ? round(($bp['profit'] / $denom) * 100, 1) : 0;
            $isLeak = ($bp['profit'] < 0 || $bp['margin'] < $marginThreshold) && $bp['revenue'] > 0;
            $planStats[] = array_merge($bp, [
                'true_margin' => $trueMargin,
                'is_leak'     => $isLeak,
            ]);
        }

        $profitLeakCount = count(array_filter($planStats, function ($p) { return $p['is_leak'] ?? false; }));

        return array_merge($pnl, [
            'true_overall_margin'    => $trueOverallMargin,
            'leakage_exposure'       => $leakage['total_monthly_loss'],
            'leakage_count'          => $leakage['count'],
            'profit_leak_plan_count' => $profitLeakCount,
            'plan_stats'             => $planStats,
            'sync_health'            => $health['health'],
            'sync_age_hours'         => $health['age_hours'],
            'margin_threshold'       => $marginThreshold,
            'built_at'               => date('Y-m-d H:i:s'),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Get current plan costs indexed by plan_name.
     */
    private function getPlanIndex(): array
    {
        $stmt = $this->db->query("SELECT * FROM fiber_plan_costs WHERE effective_to IS NULL ORDER BY plan_name");
        $index = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $index[$r['plan_name']] = $r;
        }
        return $index;
    }
}
