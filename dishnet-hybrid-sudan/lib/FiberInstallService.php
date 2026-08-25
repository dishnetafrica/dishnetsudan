<?php
/**
 * FiberInstallService — Creates collection jobs when Splynx service goes active.
 *
 * CRM handles actual invoicing. This service just:
 *   1. Detects new installs (from cron_splynx_sync.php)
 *   2. Creates a collection job for the accountant team
 *   3. Sends WhatsApp notifications
 *   4. Tracks collection status
 *
 * Dedup: splynx_service_id UNIQUE in fiber_collection_jobs prevents duplicates.
 */
class FiberInstallService
{
    private $pdo;
    private $store;
    private $config;
    private $dataDir;

    public function __construct(\PDO $pdo, $store, array $config, string $dataDir)
    {
        $this->pdo     = $pdo;
        $this->store   = $store;
        $this->config  = $config;
        $this->dataDir = $dataDir;
    }

    /**
     * Process a newly activated Splynx service — create collection job + notify.
     */
    public function processNewInstall(array $service, array $customer, array $ticket = []): array
    {
        $splynxServiceId  = (int)($service['id'] ?? 0);
        $splynxCustomerId = (int)($service['customer_id'] ?? $customer['id'] ?? 0);

        if (!$splynxServiceId || !$splynxCustomerId) {
            return ['ok' => false, 'error' => 'Missing service or customer ID', 'action' => 'error'];
        }

        // Dedup by splynx_service_id
        $existing = $this->pdo->prepare("SELECT id, status FROM fiber_collection_jobs WHERE splynx_service_id = ?");
        $existing->execute([$splynxServiceId]);
        if ($existing->fetch()) {
            return ['ok' => true, 'action' => 'exists'];
        }
        // Also dedup by ticket_id (in case service_id changed)
        $ticketId = (int)($ticket['id'] ?? 0);
        if ($ticketId > 0) {
            $existing2 = $this->pdo->prepare("SELECT id FROM fiber_collection_jobs WHERE ticket_id = ?");
            $existing2->execute([$ticketId]);
            if ($existing2->fetch()) {
                return ['ok' => true, 'action' => 'exists'];
            }
        }

        // Resolve info (prefer ticket, fallback to Splynx)
        $custName  = trim($ticket['customer_name'] ?? $customer['name'] ?? $customer['login'] ?? '');
        $custPhone = $this->cleanPhone($ticket['phone'] ?? $customer['phone'] ?? $customer['phone_number'] ?? '');
        $area      = $ticket['area'] ?? 'Unknown';
        $tariffName = $service['tariff_name'] ?? $service['description'] ?? '';
        $amount     = $this->resolvePlanPrice($tariffName, (float)($service['price'] ?? 0));
        $kycLink    = $this->findKycApp($splynxCustomerId, $custPhone);

        // Create collection job
        $this->pdo->prepare("INSERT INTO fiber_collection_jobs (
            splynx_customer_id, splynx_service_id, ticket_id,
            customer_name, phone, area,
            plan_name, amount, currency,
            kyc_app_id, crm_client_id,
            status, created_at, updated_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))")->execute([
            $splynxCustomerId, $splynxServiceId, (int)($ticket['id'] ?? 0),
            $custName, $custPhone, $area,
            $tariffName, $amount, 'USD',
            $kycLink['kyc_app_id'], $kycLink['crm_client_id'],
            'pending',
        ]);
        $jobId = (int)$this->pdo->lastInsertId();

        // WhatsApp notifications
        $this->notifyAccountant($jobId, $custName, $custPhone, $area, $tariffName, $amount);
        $this->notifyInstaller($custName, $tariffName, $area);

        // ── Auto-send delivery note to customer ───────────────────────────
        // Fires once per install (dedup via splynx_service_id already confirmed above).
        // Requires a matched KYC record — if none found, alerts Bidal silently.
        $this->sendDeliveryNote($jobId, $kycLink, $custName, $custPhone, $area, $tariffName);

        // ── Create CRM scheduling job for Rupesh (accountant) ─────────────
        // Creates a UCRM scheduling job so Rupesh sees it in CRM → Scheduling
        // and can create the invoice directly from the job screen.
        $this->createCrmInvoiceJob($jobId, $custName, $custPhone, $area, $tariffName, $amount, $kycLink);

        // ── Auto-close Splynx installation ticket ─────────────────────────
        // Ticket is closed once service is active in Splynx = installation done.
        // Two-step: status → Solved, then closed = 1.
        if ($ticketId > 0) {
            $this->closeInstallTicket($ticketId, $custName, $splynxCustomerId);
        }

        return ['ok' => true, 'action' => 'created', 'job_id' => $jobId, 'amount' => $amount];
    }

    private function resolvePlanPrice(string $tariffName, float $splynxPrice): float
    {
        if (!$tariffName) return $splynxPrice ?: 0;
        $stmt = $this->pdo->prepare("SELECT cost_per_unit FROM fiber_plan_costs WHERE plan_name = ? LIMIT 1");
        $stmt->execute([$tariffName]);
        $p = $stmt->fetchColumn();
        if ($p !== false && (float)$p > 0) return (float)$p;

        $all = $this->pdo->query("SELECT plan_name, cost_per_unit FROM fiber_plan_costs")->fetchAll(\PDO::FETCH_ASSOC);
        $tariffLow = strtolower($tariffName);
        foreach ($all as $pl) {
            $planLow = strtolower($pl['plan_name']);
            if (strpos($tariffLow, $planLow) !== false || strpos($planLow, $tariffLow) !== false) return (float)$pl['cost_per_unit'];
            if (preg_match('/(\d+)\s*mbps/i', $tariffLow, $m1) && preg_match('/(\d+)\s*mbps/i', $planLow, $m2)) {
                if ($m1[1] === $m2[1]) return (float)$pl['cost_per_unit'];
            }
        }
        return $splynxPrice ?: 0;
    }

    private function findKycApp(int $splynxCustId, string $phone): array
    {
        $result = ['kyc_app_id' => 0, 'crm_client_id' => 0];
        try {
            $map = $this->pdo->prepare("SELECT crm_client_id FROM fiber_customer_map WHERE splynx_customer_id = ?");
            $map->execute([(string)$splynxCustId]);
            $crmId = $map->fetchColumn();
            if ($crmId && (int)$crmId > 0) $result['crm_client_id'] = (int)$crmId;
        } catch (\Throwable $e) {}

        if ($phone) {
            $apps = $this->store->load('kyc_applications.json') ?? [];
            $suffix = substr(preg_replace('/[^0-9]/', '', $phone), -9);
            foreach ($apps as $a) {
                $appSuffix = substr(preg_replace('/[^0-9]/', '', $a['mobile'] ?? ''), -9);
                if ($appSuffix && $appSuffix === $suffix) {
                    $result['kyc_app_id']    = (int)($a['id'] ?? 0);
                    $result['crm_client_id'] = (int)($a['crm_client_id'] ?? $result['crm_client_id']);
                    break;
                }
            }
        }

        // ── Backfill fiber_customer_map if we resolved crm_client_id ──────────
        // The map may have splynx_customer_id but null crm_client_id because it
        // was inserted by FiberFinanceEngine sync before KYC was completed.
        // Now that we have both IDs, write the link back so it persists.
        if ($splynxCustId > 0 && $result['crm_client_id'] > 0) {
            try {
                $this->pdo->prepare(
                    "UPDATE fiber_customer_map
                     SET crm_client_id = ?, linked_by = 'kyc_match', linked_at = datetime('now')
                     WHERE splynx_customer_id = ?
                       AND (crm_client_id IS NULL OR crm_client_id = '' OR crm_client_id = '0')"
                )->execute([(string)$result['crm_client_id'], (string)$splynxCustId]);
            } catch (\Throwable $e) {}
        }

        return $result;
    }

    private function notifyAccountant(int $jobId, string $name, string $phone, string $area, string $plan, float $amount): void
    {
        try {
            require_once dirname(__DIR__) . '/lib/NotificationService.php';
            $notify = new NotificationService($this->store, $this->config);
            $msg = "New Fiber Install — Invoice Needed\n\n"
                . "Customer: {$name}\n"
                . "Phone: {$phone}\n"
                . "Area: {$area}\n"
                . "Plan: {$plan}\n"
                . "Amount: \${$amount}\n\n"
                . "Please create invoice in CRM.";
            $notify->sendAdmin($msg, 'fiber_collection_job');
            $this->pdo->prepare("UPDATE fiber_collection_jobs SET wa_sent_accountant = 1 WHERE id = ?")->execute([$jobId]);
        } catch (\Throwable $e) {}
    }

    private function notifyInstaller(string $name, string $plan, string $area): void
    {
        try {
            require_once dirname(__DIR__) . '/lib/NotificationService.php';
            $notify = new NotificationService($this->store, $this->config);
            $msg = "Installation Confirmed\n\n"
                . "Customer: {$name}\nPlan: {$plan}\nArea: {$area}\n\n"
                . "Invoice job created for accounts team.";
            $notify->sendVia('support', '', $msg, 'fiber_install_confirmed');
        } catch (\Throwable $e) {}
    }

    /**
     * Create a UCRM scheduling job for the accountant (Rupesh) so it appears
     * in CRM -> Scheduling -> Jobs and he can raise the invoice from there.
     *
     * Assigned to: retailer with role=accountant who has a ucrm_user_id set.
     * Fallback:    if no accountant found, uses config key 'accountant_ucrm_user_id'.
     * Never throws — job creation failure must not block the install flow.
     */
    private function createCrmInvoiceJob(
        int $jobId, string $custName, string $custPhone,
        string $area, string $plan, float $amount, array $kycLink
    ): void {
        try {
            require_once dirname(__DIR__) . '/lib/CrmApiClient.php';
            $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $this->config);
            if (!$crm->isConfigured()) {
                error_log('[FiberInstall] createCrmInvoiceJob: CRM not configured');
                return;
            }

            // Resolve accountant UCRM user ID
            // 1. Look for retailer with role=accountant + ucrm_user_id set
            // 2. Fallback to config key accountant_ucrm_user_id
            $ucrmUserId = 0;
            $retailers  = $this->store->load('retailers.json') ?? [];
            foreach ($retailers as $r) {
                if (empty($r['is_active'])) continue;
                $role = strtolower($r['role'] ?? '');
                if (in_array($role, ['accountant', 'admin'], true) && !empty($r['ucrm_user_id'])) {
                    $ucrmUserId = (int)$r['ucrm_user_id'];
                    break;
                }
            }
            if ($ucrmUserId <= 0) {
                $ucrmUserId = (int)($this->config['accountant_ucrm_user_id'] ?? 0);
            }
            if ($ucrmUserId <= 0) {
                error_log("[FiberInstall] createCrmInvoiceJob: no accountant ucrm_user_id found for job #{$jobId}");
                // Still create unassigned job so it shows in CRM
            }

            $crmClientId = (int)($kycLink['crm_client_id'] ?? 0);
            $today       = date('Y-m-d');
            $refNo       = 'FIB-INV-' . $jobId;

            $title = "Fiber Invoice — {$custName}";
            $desc  = implode("\n", array_filter([
                "Customer  : {$custName}",
                "Phone     : {$custPhone}",
                "Area      : {$area}",
                "Plan      : {$plan}",
                "Amount    : \${$amount}",
                "Ref       : {$refNo}",
                "",
                "Action: Create invoice in CRM for this fiber installation.",
            ]));

            $payload = [
                'title'       => $title,
                'date'        => $today . 'T08:00:00.000Z',
                'duration'    => 30,
                'description' => $desc,
                'status'      => 1, // Open
            ];
            if ($ucrmUserId > 0)  $payload['assignedUserId'] = $ucrmUserId;
            if ($crmClientId > 0) $payload['clientId']       = $crmClientId;

            $newJob = $crm->post('scheduling/jobs', $payload);
            if (!$newJob || empty($newJob['id'])) {
                error_log('[FiberInstall] createCrmInvoiceJob: CRM job creation failed — ' . json_encode($crm->getLastError()));
                return;
            }
            $newJobId = (int)$newJob['id'];

            // Add a task checklist item
            $crm->post("scheduling/jobs/{$newJobId}/job-tasks", [
                'name' => "Create CRM invoice for {$custName} — {$plan} — \${$amount}",
            ]);

            // Record CRM job ID on the collection job
            try {
                $this->pdo->prepare(
                    "UPDATE fiber_collection_jobs SET crm_job_id = ?, updated_at = datetime('now') WHERE id = ?"
                )->execute([$newJobId, $jobId]);
            } catch (\Throwable $e) {}

            error_log("[FiberInstall] CRM invoice job #{$newJobId} created for {$custName} (collection job #{$jobId})");

        } catch (\Throwable $e) {
            error_log('[FiberInstall] createCrmInvoiceJob exception: ' . $e->getMessage());
        }
    }

    /**
     * Generate and WhatsApp the delivery acknowledgment PDF to the customer.
     * Called once per new install — dedup is handled by the caller (splynx_service_id unique).
     *
     * If no KYC record is found: skip silently + alert Bidal on WhatsApp so he can
     * follow up manually. Never throws — delivery note failure must not block the
     * collection job creation.
     */
    private function sendDeliveryNote(
        int    $jobId,
        array  $kycLink,
        string $custName,
        string $custPhone,
        string $area,
        string $tariffName
    ): void {
        try {
            $kycAppId = (int)($kycLink['kyc_app_id'] ?? 0);

            // ── No KYC match → alert Bidal and skip ──────────────────────
            if (!$kycAppId) {
                require_once dirname(__DIR__) . '/lib/NotificationService.php';
                $notify = new NotificationService($this->store, $this->config);
                $firstName = explode(' ', trim($custName))[0] ?: 'Customer';
                $notify->sendVia('support', '', (
                    "⚠️ *Delivery Note not sent* — no KYC record found\n\n"
                    . "Customer: *{$custName}*\n"
                    . "Phone: {$custPhone}\n"
                    . "Area: {$area} · Plan: {$tariffName}\n\n"
                    . "Please send the delivery note manually or check KYC is linked to this customer."
                ), 'fiber_delivery_no_kyc', []);
                error_log("[FiberInstall] Delivery note skipped for job #{$jobId} — no KYC app found for phone {$custPhone}");
                return;
            }

            // ── Load the full KYC app record ─────────────────────────────
            $app = $this->findFullKycApp($kycAppId);
            if (!$app) {
                error_log("[FiberInstall] Delivery note skipped for job #{$jobId} — KYC app #{$kycAppId} not found in store");
                return;
            }

            // ── Generate and send PDF ─────────────────────────────────────
            // Mark as post-installation so DeliveryPdfService uses the correct caption
            $app['installation_done'] = true;
            if (empty($app['mobile'])) $app['mobile'] = $custPhone;
            if (empty($app['customer_type'])) $app['customer_type'] = 'fiber';

            // Represent Bidal as the DishNet representative on the document
            $bidalRetailer = ['name' => 'Bidal DishNet', 'role' => 'field_agent'];
            // Look up Bidal's actual retailer record if available
            try {
                $retailers = $this->store->load('retailers.json') ?? [];
                foreach ($retailers as $r) {
                    if (stripos($r['name'] ?? '', 'bidal') !== false) {
                        $bidalRetailer = $r;
                        break;
                    }
                }
            } catch (\Throwable $e) {}

            require_once dirname(__DIR__) . '/lib/DeliveryPdfService.php';
            $pdfSvc = new DeliveryPdfService($this->store, $this->dataDir, $this->config);
            $result = $pdfSvc->generateAndSend($app, $bidalRetailer, (string)($kycLink['crm_client_id'] ?? ''));

            if ($result['ok']) {
                // Record that delivery note was sent on the collection job
                $this->pdo->prepare(
                    "UPDATE fiber_collection_jobs SET delivery_note_sent = 1, delivery_note_sent_at = datetime('now') WHERE id = ?"
                )->execute([$jobId]);
                error_log("[FiberInstall] Delivery note sent for job #{$jobId} — {$result['filename']}");
            } else {
                error_log("[FiberInstall] Delivery note FAILED for job #{$jobId}: " . ($result['error'] ?? 'unknown'));
            }

        } catch (\Throwable $e) {
            // Never let delivery note failure crash the install flow
            error_log("[FiberInstall] sendDeliveryNote exception for job #{$jobId}: " . $e->getMessage());
        }
    }

    /**
     * Load the full KYC application record by ID.
     * Returns null if not found.
     */
    private function findFullKycApp(int $kycAppId): ?array
    {
        try {
            // Try SQLite store first (structured table)
            $stmt = $this->pdo->prepare(
                "SELECT * FROM [kyc_applications] WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$kycAppId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                // data column may be a JSON blob
                if (isset($row['data']) && is_string($row['data'])) {
                    $decoded = json_decode($row['data'], true);
                    if (is_array($decoded)) return array_merge($row, $decoded);
                }
                return $row;
            }
        } catch (\Throwable $e) {}

        // Fallback: search the JSON blob store
        try {
            $apps = $this->store->load('kyc_applications.json') ?? [];
            foreach ($apps as $a) {
                if ((int)($a['id'] ?? 0) === $kycAppId) return $a;
            }
        } catch (\Throwable $e) {}

        return null;
    }

    /**
     * Close the Splynx installation ticket — called after service is confirmed active.
     * Instantiates SplynxTicketService inline (same pattern as NotificationService usage).
     * Non-fatal: ticket close failure never blocks the install job creation.
     */
    private function closeInstallTicket(int $ticketId, string $custName, int $splynxCustomerId): void
    {
        try {
            require_once dirname(__DIR__) . '/lib/SplynxApiClient.php';
            require_once dirname(__DIR__) . '/lib/SplynxTicketService.php';
            require_once dirname(__DIR__) . '/lib/NotificationService.php';

            if (empty($this->config['splynx_url']) || empty($this->config['splynx_key'])) {
                error_log("[FiberInstall] closeInstallTicket: Splynx not configured, skipping ticket #{$ticketId}");
                return;
            }

            $splynxClient = \SplynxApiClient::fromConfig($this->config);
            if (!$splynxClient->isConfigured()) {
                error_log("[FiberInstall] closeInstallTicket: SplynxApiClient not configured");
                return;
            }

            $notify     = new \NotificationService($this->store, $this->config);
            $ticketSvc  = new \SplynxTicketService($splynxClient, $this->store, $notify, $this->config);

            // Splynx username format: FTTH + zero-padded customer ID (e.g. FTTH000193)
            $splynxUser = 'FTTH' . str_pad((string)$splynxCustomerId, 6, '0', STR_PAD_LEFT);

            $result = $ticketSvc->closeTicket($ticketId, $custName, $splynxUser);

            if ($result['ok']) {
                error_log("[FiberInstall] Ticket #{$ticketId} closed for {$custName} ({$splynxUser})");
                // Record in DB so the UI can show the status
                $this->pdo->prepare(
                    "UPDATE fiber_collection_jobs SET ticket_closed = 1, ticket_closed_at = datetime('now') WHERE ticket_id = ?"
                )->execute([$ticketId]);
            } else {
                error_log("[FiberInstall] Ticket #{$ticketId} close FAILED: " . ($result['error'] ?? 'unknown'));
            }
        } catch (\Throwable $e) {
            // Never let ticket close failure crash the install flow
            error_log("[FiberInstall] closeInstallTicket exception for ticket #{$ticketId}: " . $e->getMessage());
        }
    }

    // ── Dashboard queries ──

    public function getPending(): array
    {
        return $this->pdo->query("SELECT * FROM fiber_collection_jobs WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countPending(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM fiber_collection_jobs WHERE status = 'pending'")->fetchColumn();
    }

    public function pendingTotal(): float
    {
        return round((float)$this->pdo->query("SELECT COALESCE(SUM(amount),0) FROM fiber_collection_jobs WHERE status = 'pending'")->fetchColumn(), 2);
    }

    public function markInvoiced(int $jobId, string $collectedBy = '', int $collectionId = 0): bool
    {
        $stmt = $this->pdo->prepare("UPDATE fiber_collection_jobs SET status = 'invoiced', invoiced_at = datetime('now'), invoiced_by = ?, payment_collection_id = ?, updated_at = datetime('now') WHERE id = ? AND status = 'pending'");
        $stmt->execute([$collectedBy, $collectionId, $jobId]);
        return $stmt->rowCount() > 0;
    }

    public function markCancelled(int $jobId, string $reason = ''): bool
    {
        $stmt = $this->pdo->prepare("UPDATE fiber_collection_jobs SET status = 'cancelled', cancel_reason = ?, updated_at = datetime('now') WHERE id = ? AND status = 'pending'");
        $stmt->execute([$reason, $jobId]);
        return $stmt->rowCount() > 0;
    }

    private function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', $phone);
    }
}
