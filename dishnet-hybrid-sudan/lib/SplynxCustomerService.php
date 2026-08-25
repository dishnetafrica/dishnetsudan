<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * SplynxCustomerService — DishNet Africa
 *
 * Automates the creation of Splynx customers and internet services
 * from DishNet CRM KYC registrations.
 *
 * ── When is this called? ─────────────────────────────────────────────────────
 *
 *   Option A: Immediately on KYC approval (auto-provision):
 *     provisionFromKyc($app)
 *       → ensureCustomer()       — creates/links Splynx customer
 *       → createService()        — creates internet service (status=disabled)
 *       → SplynxTicketService::createInstallTicket()  — creates install ticket
 *
 *   Option B: After installation complete (manual trigger or auto from ticket sync):
 *     activateService($splynxServiceId)
 *       → PUT /customer-internet-services/{id}  status=active
 *
 * ── Splynx customer fields ───────────────────────────────────────────────────
 *
 *   login      (required, unique) — e.g. "DN-00421"
 *   name       Full name
 *   phone      
 *   email
 *   address    
 *   tariff_id  Splynx tariff plan ID (mapped from DishNet plan name)
 *   router_id  (optional) Splynx router to assign
 *   ip_pool    (optional)
 */
class SplynxCustomerService
{
    private SplynxApiClient     $splynx;
    private SplynxTicketService $tickets;
    private                     $store;
    private array               $config;

    public function __construct(
        SplynxApiClient     $splynx,
        SplynxTicketService $tickets,
                            $store,
        array               $config = []
    ) {
        $this->splynx  = $splynx;
        $this->tickets = $tickets;
        $this->store   = $store;
        $this->config  = $config;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // FULL PROVISION: Customer + Service + Ticket
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Full auto-provision pipeline for a newly approved Fiber KYC application.
     *
     * Steps:
     *   1. Ensure Splynx customer exists (create or link)
     *   2. Create internet service in Splynx (status = disabled until installed)
     *   3. Create installation ticket in Splynx
     *   4. Save all IDs back to the KYC application record
     *
     * @param array $app   KYC application record
     * @return array       ['ok' => bool, 'customer_id' => int, 'service_id' => int, 'ticket_id' => int, 'error' => string]
     */
    public function provisionFromKyc(array $app): array
    {
        if (!$this->splynx->isConfigured()) {
            return ['ok' => false, 'error' => 'Splynx not configured'];
        }

        $result = ['ok' => false, 'customer_id' => 0, 'service_id' => 0, 'ticket_id' => 0, 'error' => ''];

        // ── 1. Ensure Splynx customer ──────────────────────────────────────────
        $customerId = $this->ensureCustomer($app);
        if (!$customerId) {
            $result['error'] = 'Failed to create/find Splynx customer: ' . json_encode($this->splynx->getLastError());
            return $result;
        }
        $result['customer_id'] = $customerId;

        // ── 2. Create internet service (disabled until installed) ──────────────
        $serviceId = $this->createService($customerId, $app);
        if (!$serviceId) {
            $result['error'] = 'Failed to create Splynx service: ' . json_encode($this->splynx->getLastError());
            // Don't abort — ticket is still useful even if service create failed
        }
        $result['service_id'] = $serviceId;

        // ── 3. Create installation ticket ─────────────────────────────────────
        $ticketId = $this->tickets->createInstallTicket($app, $customerId);
        $result['ticket_id'] = $ticketId ?? 0;

        // ── 4. Persist all IDs back to KYC record ─────────────────────────────
        $updates = [
            'splynx_customer_id' => $customerId,
            'splynx_provisioned_at' => date('Y-m-d H:i:s'),
        ];
        if ($serviceId) $updates['splynx_service_id'] = $serviceId;
        if ($ticketId)  $updates['splynx_ticket_id']  = $ticketId;

        $this->store->updateOne('kyc_applications.json', 'id', (int)($app['id'] ?? 0), $updates);

        $result['ok'] = true;
        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CUSTOMER MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Create or find a Splynx customer for a KYC application.
     * Returns Splynx customer ID or null.
     */
    public function ensureCustomer(array $app): ?int
    {
        // Already provisioned?
        if (!empty($app['splynx_customer_id'])) {
            return (int)$app['splynx_customer_id'];
        }

        $login = $this->generateLogin($app);
        $name  = $app['customer_name'] ?? $app['name'] ?? 'Unknown';
        $phone = $app['phone'] ?? '';
        $email = $app['email'] ?? '';
        $addr  = $app['address'] ?? '';

        $payload = [
            'login'   => $login,
            'name'    => $name,
            'phone'   => $phone,
            'email'   => $email,
            'address' => $addr,
            'status'  => 'inactive',   // becomes active after installation
            'note'    => "DishNet KYC ID: " . ($app['id'] ?? ''),
        ];

        // Try to find by email first
        if ($email) {
            $found = $this->splynx->get('api/2.0/admin/customers/customer', ['email' => $email]);
            if (!empty($found[0]['id'])) {
                $id = (int)$found[0]['id'];
                $this->store->updateOne('kyc_applications.json', 'id', (int)($app['id'] ?? 0), [
                    'splynx_customer_id' => $id,
                ]);
                return $id;
            }
        }

        // Try to find by login
        $found = $this->splynx->get('api/2.0/admin/customers/customer', ['login' => $login]);
        if (!empty($found[0]['id'])) {
            return (int)$found[0]['id'];
        }

        $result = $this->splynx->post('api/2.0/admin/customers/customer', $payload);
        if (empty($result['id'])) return null;

        return (int)$result['id'];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SERVICE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Create an internet service in Splynx (initially disabled).
     * Returns Splynx service ID or null.
     */
    public function createService(int $splynxCustomerId, array $app): ?int
    {
        $tariffId = $this->resolveTariffId($app['plan'] ?? $app['package'] ?? '');

        $payload = [
            'customer_id' => $splynxCustomerId,
            'tariff_id'   => $tariffId ?: ($this->config['splynx_default_tariff_id'] ?? 1),
            'status'      => 'disabled',   // enabled after engineer completes installation
            'start_date'  => date('Y-m-d'),
            'description' => 'DishNet Fiber — auto-provisioned by Hybrid Plugin',
        ];

        // Optional: assign to specific router/IP pool
        $routerId = (int)($this->config['splynx_default_router_id'] ?? 0);
        if ($routerId) $payload['router_id'] = $routerId;

        $result = $this->splynx->post('api/2.0/admin/customers/customer-internet-services', $payload);
        if (empty($result['id'])) return null;

        return (int)$result['id'];
    }

    /**
     * Activate a service after installation is confirmed.
     */
    public function activateService(int $splynxServiceId): bool
    {
        $result = $this->splynx->put(
            "api/2.0/admin/customers/customer-internet-services/{$splynxServiceId}",
            ['status' => 'active']
        );
        return $result !== null;
    }

    /**
     * Suspend a service (customer hasn't paid).
     */
    public function suspendService(int $splynxServiceId): bool
    {
        $result = $this->splynx->put(
            "api/2.0/admin/customers/customer-internet-services/{$splynxServiceId}",
            ['status' => 'disabled']
        );
        return $result !== null;
    }

    /**
     * Sync CRM billing status to Splynx.
     * Called by cron when CRM marks a customer as suspended or restored.
     *
     * @param int    $splynxServiceId
     * @param string $crmStatus  'active' | 'inactive' | 'suspended'
     */
    public function syncBillingStatus(int $splynxServiceId, string $crmStatus): bool
    {
        $splynxStatusMap = ['active'=>'active','suspended'=>'disabled','inactive'=>'disabled'];
        $splynxStatus = $splynxStatusMap[$crmStatus] ?? null;
        if (!$splynxStatus) return false;

        $result = $this->splynx->put(
            "api/2.0/admin/customers/customer-internet-services/{$splynxServiceId}",
            ['status' => $splynxStatus]
        );
        return $result !== null;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // TARIFF MAPPING
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Map DishNet plan name → Splynx tariff ID.
     * Falls back to config default or 0 (meaning Splynx's own default).
     */
    public function resolveTariffId(string $planName): int
    {
        if (!$planName) return 0;

        // Admin-configurable mapping in kyc_config.json:
        // "splynx_tariff_map": {"10Mbps Fiber": 5, "20Mbps Fiber": 6}
        $map = $this->config['splynx_tariff_map'] ?? [];
        if (isset($map[$planName])) return (int)$map[$planName];

        // Try partial match
        $planLower = strtolower($planName);
        foreach ($map as $key => $id) {
            if (str_contains($planLower, strtolower((string)$key))) {
                return (int)$id;
            }
        }

        return 0;
    }

    /**
     * Get all available tariffs from Splynx (for settings UI / mapping).
     */
    public function getAvailableTariffs(): array
    {
        return $this->splynx->getTariffs();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BULK STATUS SYNC (cron)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Sync service statuses from CRM to Splynx for all provisioned customers.
     * Runs via cron_splynx_sync.php every 5 minutes.
     */
    public function syncBillingStatuses(): array
    {
        $apps = $this->store->load('kyc_applications.json') ?? [];
        $synced = $failed = $skipped = 0;

        foreach ($apps as $app) {
            $serviceId  = (int)($app['splynx_service_id']  ?? 0);
            $crmStatus  = $app['crm_service_status'] ?? '';

            if (!$serviceId || !$crmStatus) { $skipped++; continue; }

            $ok = $this->syncBillingStatus($serviceId, $crmStatus);
            $ok ? $synced++ : $failed++;
        }

        return ['synced' => $synced, 'failed' => $failed, 'skipped' => $skipped];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Generate a unique Splynx login from KYC record.
     * Format: DN-00421 (padded app ID) or DN-<phone_last6>
     */
    private function generateLogin(array $app): string
    {
        if (!empty($app['id'])) {
            return 'DN-' . str_pad((string)(int)$app['id'], 5, '0', STR_PAD_LEFT);
        }
        $phone = preg_replace('/\D/', '', $app['phone'] ?? '');
        if (strlen($phone) >= 6) {
            return 'DN-' . substr($phone, -6);
        }
        return 'DN-' . substr(md5(($app['customer_name'] ?? '') . time()), 0, 8);
    }
}
