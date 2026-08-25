<?php
declare(strict_types=1);

// PHP 7.4 polyfills
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }

/**
 * SplynxTicketService — DishNet Africa
 *
 * Manages the creation and synchronisation of Splynx support tickets
 * triggered by DishNet CRM fiber installation registrations.
 *
 * ── Flow ─────────────────────────────────────────────────────────────────────
 *
 *   KYC Registration (Fiber)
 *         │
 *         ▼
 *   createInstallTicket()          → POST /api/2.0/admin/tickets/tickets
 *         │
 *         │ stores splynx_ticket_id on kyc_application
 *         ▼
 *   syncTickets() [cron every 5m]  → GET /api/2.0/admin/tickets/tickets
 *         │
 *         │ detects status = closed/resolved
 *         ▼
 *   markInstallComplete()          → updates local record, triggers notification
 */
class SplynxTicketService
{
    const STATUS_NEW      = 1;
    const STATUS_OPEN     = 2;  // "Work in progress"
    const STATUS_PENDING  = 3;  // "Resolved"
    const STATUS_SOLVED   = 4;  // "Waiting your answer"
    const STATUS_CLOSED   = 5;  // "Waiting on agent"

    // ── DishNet Custom Splynx Statuses ──────────────────────────────────
    const STATUS_SURVEY_DONE             = 7;
    const STATUS_FIBER_DEPLOYMENT        = 8;
    const STATUS_READY_ONU_MAPPED        = 9;
    const STATUS_CANCEL_BY_CUSTOMER      = 10;
    const STATUS_FIBER_NOT_AVAILABLE     = 11;
    const STATUS_CLIENT_NOT_READY        = 12;

    // Statuses that mean "installation is done / closed"
    // COMPLETED = only Resolved (3). Status 4/5 with closed=0 are still open in Splynx.
    // Rule from Fiber Finance plugin: only closed=1 OR status=3 ends the pipeline.
    const COMPLETED_STATUSES = [3];
    // Statuses that mean "cancelled / not proceeding"
    const CANCELLED_STATUSES = [10, 11, 12];

    // Splynx ticket type IDs — adjust to match your Splynx configuration
    const TYPE_INSTALLATION = 1;
    const TYPE_REPAIR       = 2;

    // Priority IDs in Splynx
    const PRIORITY_LOW    = 1;
    const PRIORITY_NORMAL = 2;
    const PRIORITY_HIGH   = 3;

    private SplynxApiClient $splynx;
    private                 $store;
    private NotificationService $notify;
    private array           $config;

    public function __construct(SplynxApiClient $splynx, $store, NotificationService $notify, array $config = [])
    {
        $this->splynx = $splynx;
        $this->store  = $store;
        $this->notify = $notify;
        $this->config = $config;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CREATE INSTALLATION TICKET
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Create a Splynx ticket for a new fiber KYC application.
     *
     * Called immediately after a Fiber KYC registration is approved.
     *
     * @param array $app    KYC application record
     * @param int   $splynxCustomerId   Splynx customer ID (from SplynxCustomerService)
     * @return int|null     Splynx ticket ID, or null on failure
     */
    public function createInstallTicket(array $app, int $splynxCustomerId): ?int
    {
        if (!$this->splynx->isConfigured()) return null;

        $customerName = $app['customer_name'] ?? $app['name'] ?? 'Unknown Customer';
        $address      = $app['address'] ?? $app['address_1'] ?? '';
        $area         = $app['fiber_area'] ?? '';
        $plan         = $app['plan'] ?? $app['package'] ?? '';
        $appId        = $app['id'] ?? '?';
        $agent        = $app['retailer_name'] ?? $app['agent_name'] ?? '';
        $phone        = $app['phone'] ?? $app['mobile'] ?? '';

        $subject = "Fiber Installation — {$customerName}";

        $description = implode("\n", array_filter([
            "DishNet Fiber Installation Request",
            "─────────────────────────────────",
            "Application ID : DN-{$appId}",
            "Customer Name  : {$customerName}",
            "Phone          : {$phone}",
            "Area           : {$area}",
            "Address        : {$address}",
            $plan    ? "Service Plan   : {$plan}" : '',
            $agent   ? "Sales Agent    : {$agent}" : '',
            "",
            "This ticket was auto-created by the DishNet Hybrid Plugin.",
            "Please update status to Solved when installation is complete.",
        ]));

        $payload = [
            'subject'     => $subject,
            'message'     => $description,
            'status'      => self::STATUS_NEW,
            'priority'    => self::PRIORITY_NORMAL,
            'type'        => self::TYPE_INSTALLATION,
            'customer_id' => $splynxCustomerId,
        ];

        // Assign to fiber team admin if configured
        $fiberAdminId = (int)($this->config['splynx_fiber_admin_id'] ?? 0);
        if ($fiberAdminId > 0) {
            $payload['assigned_to'] = $fiberAdminId;
        }

        $result = $this->splynx->createTicket($payload);

        if (empty($result['id'])) {
            error_log("SplynxTicketService: Failed to create ticket for app {$appId} — " . json_encode($this->splynx->getLastError()));
            return null;
        }

        $ticketId = (int)$result['id'];

        // Store splynx_ticket_id on the KYC application
        $this->store->updateOne('kyc_applications.json', 'id', (int)($app['id'] ?? 0), [
            'splynx_ticket_id'     => $ticketId,
            'splynx_ticket_status' => 'open',
            'splynx_ticket_at'     => date('Y-m-d H:i:s'),
        ]);

        // Also persist in local splynx_tickets store for fast dashboard queries
        $this->persistTicket([
            'id'              => $ticketId,
            'app_id'          => (int)($app['id'] ?? 0),
            'customer_id'     => $splynxCustomerId,
            'customer_name'   => $customerName,
            'address'         => $address,
            'area'            => $area,
            'phone'           => $phone,
            'status'          => self::STATUS_NEW,
            'status_label'    => 'open',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'install_complete' => false,
            'engineer'        => '',
        ]);

        return $ticketId;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // SYNC TICKETS FROM SPLYNX (cron)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Pull all open installation tickets from Splynx and update local cache.
     * Detects when status becomes Solved/Closed → marks install complete.
     *
     * Returns a summary array for logging.
     */
    public function syncTickets(): array
    {
        if (!$this->splynx->isConfigured()) {
            return ['ok' => false, 'error' => 'Splynx not configured', 'synced' => 0, 'completed' => 0];
        }

        $localTickets = $this->store->load('splynx_tickets.json') ?? [];
        $localById    = [];
        foreach ($localTickets as &$lt) {
            $localById[(int)($lt['id'] ?? 0)] = &$lt;
        }
        unset($lt);

        $synced    = 0;
        $completed = 0;
        $imported  = 0;
        $errors    = 0;

        // ── Phase 1: Pull ALL open tickets from Splynx (catches new ones) ───
        $remoteTickets = $this->splynx->getTickets(['page' => 0, 'limit' => 500]);
        $apiError      = $this->splynx->getLastError();
        if (!$remoteTickets) $remoteTickets = [];

        // If zero results, also try without pagination params
        if (empty($remoteTickets)) {
            $remoteTickets = $this->splynx->getTickets([]);
            $apiError      = $this->splynx->getLastError();
            if (!$remoteTickets) $remoteTickets = [];
        }

        // Note: Engineer assignment is managed inside DishNet plugin (Staff & Retailers),
        // NOT from Splynx assign_to. Splynx only provides ticket status updates.

        foreach ($remoteTickets as $remote) {
            $ticketId   = (int)($remote['id'] ?? 0);
            if ($ticketId === 0) continue;

            // ── Splynx field mapping (status only from Splynx) ────────────
            $newStatus  = (int)($remote['status_id'] ?? $remote['status'] ?? 0);
            $statusLbl  = $this->statusLabel($newStatus);
            $subject    = $remote['subject'] ?? '';
            $custId     = (int)($remote['customer_id'] ?? 0);
            $priority   = $remote['priority'] ?? '';
            $isClosed   = ($remote['closed'] ?? '0') === '1';

            // Extract FTTH number from subject for CRM lookup
            // Formats: "D-FTTH000137", "D-FTTH-0006", "D-FTTH0151"
            $ftthNumber = '';
            if (preg_match('/D-FTTH[- ]?(\d+)/i', $subject, $fm)) {
                $ftthNumber = 'D-FTTH' . $fm[1];
            }

            // Extract customer name from subject (remove "Dishnet Installation" prefix)
            $custName = $subject;
            $custName = preg_replace('/^(DishNet |Dishnet )?(New GPON |Fiber )?(Installation|Installtion)\s*[-—]?\s*/i', '', $custName);
            $custName = trim($custName);
            if (!$custName) $custName = $subject;

            // Address comes from CRM enrichment, not from Splynx ticket
            $address = '';

            if (isset($localById[$ticketId])) {
                // ── Existing ticket: update STATUS ONLY from Splynx ─────────
                $local     = &$localById[$ticketId];
                $oldStatus = $local['status'] ?? 0;

                if (!empty($local['install_complete'])) { unset($local); continue; }

                // Only update status-related fields from Splynx
                $local['status']       = $newStatus;
                $local['status_label'] = $statusLbl;
                // Do NOT overwrite engineer — managed by DishNet Staff & Retailers
                $local['updated_at']   = $remote['updated_at'] ?? date('Y-m-d H:i:s');
                $local['priority']     = $priority ?: ($local['priority'] ?? '');
                // Update customer_name only if currently empty or generic
                if (!($local['customer_name'] ?? '') || $local['customer_name'] === $subject) {
                    $local['customer_name'] = $custName;
                }
                if (!empty($ftthNumber)) $local['ftth_number'] = $ftthNumber;
                $synced++;

                // Detect completion (closed flag OR resolved/solved/closed status)
                if ($isClosed || $this->isCompletedStatus($newStatus)) {
                    $local['install_complete']    = true;
                    $local['install_complete_at'] = date('Y-m-d H:i:s');
                    $completed++;
                    if (!empty($local['app_id'])) {
                        $this->store->updateOne('kyc_applications.json', 'id', (int)$local['app_id'], [
                            'splynx_ticket_status' => 'completed',
                            'installation_done_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                    // v4.11.3: Mirror to SQLite tickets table so cron Task 6 fires
                    try {
                        $this->store->getPdo()->prepare(
                            "UPDATE tickets SET install_complete=1, install_complete_at=datetime('now') WHERE id=?"
                        )->execute([$ticketId]);
                    } catch (\Throwable $e) {}
                    $this->notifyInstallComplete($local);
                } elseif ($oldStatus !== $newStatus && !empty($local['app_id'])) {
                    $this->store->updateOne('kyc_applications.json', 'id', (int)$local['app_id'], [
                        'splynx_ticket_status' => $statusLbl,
                    ]);
                }
                unset($local);
            } else {
                // ── New ticket from Splynx: import it ───────────────────────
                $isDone = $isClosed || $this->isCompletedStatus($newStatus);
                $newLocal = [
                    'id'                => $ticketId,
                    'app_id'            => 0,
                    'customer_id'       => $custId,
                    'customer_name'     => $custName ?: $subject,
                    'address'           => '',  // Will be enriched from CRM
                    'status'            => $newStatus,
                    'status_label'      => $statusLbl,
                    'priority'          => $priority,
                    'ftth_number'       => $ftthNumber,
                    'created_at'        => $remote['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at'        => $remote['updated_at'] ?? date('Y-m-d H:i:s'),
                    'install_complete'  => $isDone,
                    'install_complete_at' => $isDone ? ($remote['updated_at'] ?? date('Y-m-d H:i:s')) : null,
                    'engineer'          => '',  // Managed by DishNet Staff & Retailers
                    'subject'           => $subject,
                    'splynx_imported'   => true,
                ];
                $localTickets[] = $newLocal;
                $localById[$ticketId] = &$localTickets[count($localTickets) - 1];
                $imported++;
                $synced++;
            }
        }

        // ── Phase 2: Update any remaining local tickets not in remote pull ──
        // (e.g., if remote pull was paginated and missed some)
        foreach ($localTickets as &$local) {
            $tid = (int)($local['id'] ?? 0);
            if (!$tid || !empty($local['install_complete'])) continue;
            // Check if we already processed this ticket in Phase 1
            if (!empty($local['updated_at']) && $local['updated_at'] === date('Y-m-d H:i:s')) continue;
            // Individual fetch for stragglers
            $remote = $this->splynx->getTicket($tid);
            if (!$remote) { $errors++; continue; }
            $newStatus = (int)($remote['status_id'] ?? $remote['status'] ?? 0);
            $p2Closed  = ($remote['closed'] ?? '0') === '1';
            $local['status']       = $newStatus;
            $local['status_label'] = $this->statusLabel($newStatus);
            // Do NOT overwrite engineer — managed by DishNet
            $local['updated_at']   = $remote['updated_at'] ?? date('Y-m-d H:i:s');
            $synced++;
            if ($p2Closed || $this->isCompletedStatus($newStatus)) {
                $local['install_complete']    = true;
                $local['install_complete_at'] = date('Y-m-d H:i:s');
                $completed++;
                // v4.11.3: Mirror to SQLite tickets table
                try {
                    $this->store->getPdo()->prepare(
                        "UPDATE tickets SET install_complete=1, install_complete_at=datetime('now') WHERE id=?"
                    )->execute([$tid]);
                } catch (\Throwable $e) {}
                $this->notifyInstallComplete($local);
            }
        }
        unset($local);

        $this->store->save('splynx_tickets.json', array_values($localTickets));
        $this->syncAllTicketsToTable($localTickets); // Phase 2: dual-write

        return [
            'ok'           => true,
            'synced'       => $synced,
            'imported'     => $imported,
            'completed'    => $completed,
            'errors'       => $errors,
            'total'        => count($localTickets),
            'remote_pulled'=> count($remoteTickets),
            'api_error'    => $apiError ?: null,
            'at'           => date('Y-m-d H:i:s'),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DASHBOARD QUERIES
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Get all installation jobs with optional filter.
     * filter: all | pending | in_progress | completed
     */
    public function getJobs(string $filter = 'all'): array
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];

        $filtered = array_values(array_filter($tickets, function(array $t) use ($filter): bool {
            if ($filter === 'pending')     return !$t['install_complete'] && in_array($t['status'] ?? 0, [self::STATUS_NEW, self::STATUS_OPEN], true);
            if ($filter === 'in_progress') return !$t['install_complete'] && ($t['status'] ?? 0) === self::STATUS_PENDING;
            if ($filter === 'completed')   return !empty($t['install_complete']);
            return true;
        }));

        // Enrich each ticket with resolved area name
        foreach ($filtered as &$t) {
            $t['area'] = $this->extractArea($t['address'] ?? '');
        }
        unset($t);

        return $filtered;
    }

    /**
     * Summary counts for dashboard.
     */
    public function getSummary(): array
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $new = $surveyed = $deploying = $readyOnu = $resolved = $waiting = $cancelled = $today = 0;
        $fiberNotAvail = $clientNotReady = 0;
        $todayDate    = date('Y-m-d');
        $engineerMap  = [];
        $statusCounts = [];

        foreach ($tickets as $t) {
            $status = (int)($t['status'] ?? 0);
            $label  = $t['status_label'] ?? $this->statusLabel($status);
            $statusCounts[$label] = ($statusCounts[$label] ?? 0) + 1;

            if (!empty($t['install_complete'])) {
                $resolved++;
                if (str_starts_with($t['install_complete_at'] ?? '', $todayDate)) $today++;
            } elseif ($this->isCancelledStatus($status) || $status === 10) {
                $cancelled++;
            } elseif ($status === 11) {
                $fiberNotAvail++;
            } elseif ($status === 12) {
                $clientNotReady++;
            } elseif ($status === 1) {
                $new++;
            } elseif ($status === 7) {
                $surveyed++;
            } elseif ($status === 8) {
                $deploying++;
            } elseif ($status === 9) {
                $readyOnu++;
            } elseif (in_array($status, [4, 5], true)) {
                $waiting++;
            } else {
                // Any unknown status — count as waiting
                $waiting++;
            }

            if (!empty($t['engineer']) && empty($t['install_complete']) && !$this->isCancelledStatus($status)) {
                $engineerMap[$t['engineer']] = ($engineerMap[$t['engineer']] ?? 0) + 1;
            }
        }

        arsort($engineerMap);

        return [
            // Legacy fields for NOC compatibility
            'pending'       => $new + $waiting,
            'in_progress'   => $surveyed + $deploying,
            'completed'     => $resolved,
            'today'         => $today,
            'engineers'     => $engineerMap,
            'total'         => count($tickets),
            // Detailed breakdown matching Splynx statuses
            'new'              => $new,
            'survey_done'      => $surveyed,
            'deploying'        => $deploying,
            'ready_onu'        => $readyOnu,
            'waiting'          => $waiting,
            'cancelled'        => $cancelled,
            'fiber_not_avail'  => $fiberNotAvail,
            'client_not_ready' => $clientNotReady,
            // Pending = everything NOT resolved and NOT cancelled/blocked
            // Same rule as fiber_pipeline.php live API call:
            //   exclude install_complete, exclude cancelled statuses (10/11/12), exclude resolved (3)
            'total_pending'    => $new + $surveyed + $deploying + $readyOnu + $waiting,
            'total_blocked'    => $fiberNotAvail + $clientNotReady,
            'status_counts'    => $statusCounts,
        ];
    }

    /**
     * Area breakdown — groups jobs by area derived from address.
     */
    public function getAreaBreakdown(): array
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];

        // Start with all 50 Juba areas initialized to zero
        $areas = [];
        foreach (self::getJubaAreas() as $name) {
            $areas[$name] = ['area' => $name, 'active' => 0, 'installing' => 0, 'completed' => 0];
        }

        foreach ($tickets as $t) {
            $area = $this->extractArea($t['address'] ?? '');
            if (!isset($areas[$area])) {
                $areas[$area] = ['area' => $area, 'active' => 0, 'installing' => 0, 'completed' => 0];
            }
            if (!empty($t['install_complete'])) {
                $areas[$area]['completed']++;
            } else {
                $areas[$area]['installing']++;
            }
        }

        // Merge with active services from Splynx customer service data (if cached)
        $services = $this->store->load('splynx_services_cache.json') ?? [];
        foreach ($services as $s) {
            $area = $this->extractArea($s['address'] ?? '');
            if (!isset($areas[$area])) {
                $areas[$area] = ['area' => $area, 'active' => 0, 'installing' => 0, 'completed' => 0];
            }
            if (($s['status'] ?? '') === 'active') {
                $areas[$area]['active']++;
            }
        }

        // Filter out areas with zero activity, but keep them all for the area selector
        usort($areas, fn($a, $b) => ($b['active'] + $b['installing'] + $b['completed']) <=> ($a['active'] + $a['installing'] + $a['completed']));
        return array_values($areas);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // CRM ENRICHMENT — populate address/phone/area from UCRM client data
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Enrich tickets that have no address by looking up CRM client data.
     *
     * Strategy (executed in order, first match wins):
     *   1. Parse FTTH number from ticket subject (e.g. "D-FTTH000137")
     *      → Search CRM services for custom attribute "splynxId" matching the
     *        Splynx customer_id on the ticket → get client → pull address
     *   2. Use Splynx customer_id directly → search CRM services with
     *      splynxId attribute → find the owning CRM client → pull address
     *   3. Fuzzy name match as last resort (customer_name vs CRM firstName+lastName)
     *
     * Enriched fields on each ticket:
     *   crm_client_id, crm_client_name, address, phone, area, crm_enriched_at
     *
     * @param CrmApiClient $crm   CRM API client
     * @param int          $limit Max tickets to enrich per run (avoid API flood)
     * @return array ['enriched' => int, 'skipped' => int, 'failed' => int]
     */
    public function enrichFromCrm(\CrmApiClient $crm, int $limit = 30): array
    {
        if (!$crm->isConfigured()) {
            return ['enriched' => 0, 'skipped' => 0, 'failed' => 0, 'error' => 'CRM not configured'];
        }

        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $enriched = $skipped = $failed = $phoneFound = 0;
        $dirty = false;

        // ── Build CRM lookup caches (one-time per run) ─────────────────────
        // We load all CRM clients + services with splynxId once, then match locally.
        // This avoids N+1 API calls per ticket.
        $crmCache = $this->store->load('crm_enrich_cache.json');
        $cacheAge = time() - strtotime($crmCache['cached_at'] ?? '2000-01-01');

        if (!$crmCache || $cacheAge > 3600) { // refresh cache hourly
            $crmCache = $this->buildCrmEnrichCache($crm);
            if ($crmCache) {
                $this->store->save('crm_enrich_cache.json', $crmCache);
            } else {
                return ['enriched' => 0, 'skipped' => 0, 'failed' => 1, 'error' => 'Failed to build CRM cache'];
            }
        }

        $clientsById     = $crmCache['clients_by_id'] ?? [];
        $ticketToCrmMap  = $crmCache['ticket_to_crm'] ?? [];   // splynx_ticket_id => crm_client_id (PRIMARY)
        $splynxToCrmMap  = $crmCache['splynx_to_crm'] ?? [];   // splynx_customer_id => crm_client_id
        $nameIndex       = $crmCache['name_index'] ?? [];       // lowercase "first last" => crm_client_id
        $usernameIndex   = $crmCache['username_index'] ?? [];   // lowercase username/ident => crm_client_id

        // ── TicketID attribute top-up ──────────────────────────────────────
        // UCRM's GET /clients list endpoint often returns attributes[] as empty.
        // GET /clients/{id} (individual) always returns full attributes.
        // For tickets not yet in ticketToCrmMap, fetch the individual client record
        // to read their TicketID attribute. Cached in clientsById to avoid re-fetching.
        // At DishNet scale (~250 tickets, cached hourly) this is ~250 calls once/hour.
        $unmappedTicketIds = [];
        foreach ($tickets as $t) {
            $tid = (int)($t['id'] ?? 0);
            if ($tid > 0 && !isset($ticketToCrmMap[$tid])) {
                $unmappedTicketIds[$tid] = true;
            }
        }
        if (!empty($unmappedTicketIds)) {
            // Scan clientsById for any client whose individual fetch has attributes
            foreach ($clientsById as $cid => $c) {
                $attrs = $c['attributes'] ?? [];
                if (!empty($attrs)) continue; // already have attributes for this client
                // Fetch individual client to get attributes
                $full = $crm->get("clients/{$cid}");
                if (!$full) continue;
                $clientsById[$cid] = $full;
                $rattrs = $full['attributes'] ?? [];
                if (!is_array($rattrs)) continue;
                foreach ($rattrs as $attr) {
                    $key = strtolower(trim($attr['key'] ?? $attr['name'] ?? ''));
                    $val = trim($attr['value'] ?? '');
                    if ($val !== '' && is_numeric($val) &&
                        ($key === 'ticketid' || $key === 'ticket_id' || $key === 'ticket id' || $key === 'ticket')) {
                        $ticketToCrmMap[(int)$val] = (int)$cid;
                    }
                }
            }
            // Persist enriched cache so next run doesn't repeat fetches
            $crmCache['ticket_to_crm'] = $ticketToCrmMap;
            $crmCache['clients_by_id'] = $clientsById;
            $crmCache['cached_at']     = date('Y-m-d H:i:s'); // keep same TTL window
            $this->store->save('crm_enrich_cache.json', $crmCache);
        }

        foreach ($tickets as &$t) {
            // Skip only if FULLY enriched: address + area + phone all present
            // area from KYC counts as enriched (no API call needed for it)
            $hasArea    = !empty($t['area']) && ($t['area'] ?? 'Unknown') !== 'Unknown';
            $hasPhone   = !empty($t['phone']);
            $hasAddress = !empty($t['address']);
            if ($hasArea && $hasPhone && $hasAddress) {
                // Fully populated — mark enriched if not already and skip
                if (empty($t['crm_enriched_at'])) {
                    $t['crm_enriched_at'] = date('Y-m-d H:i:s');
                }
                $skipped++;
                continue;
            }
            // Skip completed that are fully enriched with phone
            if (!empty($t['install_complete']) && !empty($t['crm_enriched_at']) && !empty($t['phone'])) {
                $skipped++;
                continue;
            }

            if ($enriched >= $limit) { $skipped++; continue; }

            $crmClientId = null;

            // ── Strategy 0 (PRIMARY): Ticket ID → CRM TicketID attribute ───
            // CRM client has custom attribute "TicketID" = Splynx ticket ID
            // This is 100% accurate — direct link set during KYC registration
            $ticketId = (int)($t['id'] ?? 0);
            if ($ticketId > 0 && isset($ticketToCrmMap[$ticketId])) {
                $crmClientId = $ticketToCrmMap[$ticketId];
            }

            // ── Strategy 1: FTTH number from subject → CRM username ────────
            // Ticket subject: "Dishnet Installation D-FTTH000189"
            // CRM username:   "FTTH000189"
            // This is the most reliable match — FTTH number IS the CRM username
            if (!empty($t['ftth_number'])) {
                // ftth_number is stored as "D-FTTH000189" — strip the "D-" prefix
                $ftthRaw = $t['ftth_number'];
                // Try multiple formats
                $ftthCandidates = [
                    strtolower($ftthRaw),                          // d-ftth000189
                    strtolower(preg_replace('/^D-/i', '', $ftthRaw)), // ftth000189
                    strtolower(preg_replace('/^D-FTTH/i', 'FTTH', $ftthRaw)), // ftth000189
                ];
                // Also extract just the number part and prepend FTTH
                if (preg_match('/(\d+)/', $ftthRaw, $numMatch)) {
                    $ftthCandidates[] = 'ftth' . $numMatch[1];           // ftth000189
                    $ftthCandidates[] = 'ftth' . ltrim($numMatch[1], '0'); // ftth189
                    $ftthCandidates[] = 'ftth' . str_pad($numMatch[1], 6, '0', STR_PAD_LEFT); // ftth000189
                }
                $ftthCandidates = array_unique($ftthCandidates);

                foreach ($ftthCandidates as $candidate) {
                    if (isset($usernameIndex[$candidate])) {
                        $crmClientId = $usernameIndex[$candidate];
                        break;
                    }
                }
            }

            // ── Strategy 2: Splynx customer_id → CRM via login chain ───────
            if (!$crmClientId) {
                $splynxCustId = (int)($t['customer_id'] ?? 0);
                if ($splynxCustId > 0 && isset($splynxToCrmMap[$splynxCustId])) {
                    $crmClientId = $splynxToCrmMap[$splynxCustId];
                }
            }

            // ── Strategy 3: Exact name match ───────────────────────────────
            if (!$crmClientId) {
                $ticketName = strtolower(trim($t['customer_name'] ?? ''));
                if ($ticketName && isset($nameIndex[$ticketName])) {
                    $crmClientId = $nameIndex[$ticketName];
                }
            }

            // ── Strategy 4: Partial name match (first+last words) ──────────
            if (!$crmClientId) {
                $ticketName = strtolower(trim($t['customer_name'] ?? ''));
                if ($ticketName && strlen($ticketName) > 3) {
                    $ticketWords = array_filter(explode(' ', $ticketName), function($w) { return strlen($w) > 2; });
                    if (count($ticketWords) >= 2) {
                        foreach ($nameIndex as $crmNameKey => $crmId) {
                            $allMatch = true;
                            foreach ($ticketWords as $tw) {
                                if (strpos($crmNameKey, $tw) === false) { $allMatch = false; break; }
                            }
                            if ($allMatch) { $crmClientId = $crmId; break; }
                        }
                    }
                }
            }

            if (!$crmClientId) {
                $failed++;
                // Mark as attempted so we don't keep retrying every cycle
                $t['crm_enrich_attempted'] = date('Y-m-d H:i:s');
                $dirty = true;
                continue;
            }

            // ── Pull client data from cache ────────────────────────────────
            $client = $clientsById[$crmClientId] ?? null;
            if (!$client) { $failed++; $dirty = true; continue; }

            $street   = trim($client['street1'] ?? '');
            $street2  = trim($client['street2'] ?? '');
            $city     = trim($client['city'] ?? '');
            $fullAddr = implode(', ', array_filter([$street, $street2, $city]));

            // ── Phone extraction: UCRM stores phone in contacts[] array ──
            // NOTE: GET /clients list may not include contacts[] in all UCRM versions.
            // We try cache first; if no phone found, fetch individual client to get contacts[].
            $phone = '';
            $phoneSource = '';

            // Source 1: contacts[] array (primary location in UCRM v3+)
            if (!empty($client['contacts']) && is_array($client['contacts'])) {
                foreach ($client['contacts'] as $contact) {
                    $cp = trim($contact['phone'] ?? '');
                    if ($cp) { $phone = $cp; $phoneSource = 'ucrm_contacts'; break; }
                }
            }

            // Source 2: top-level phone field (older UCRM versions / manual entry)
            if (!$phone) {
                $phone = trim($client['phone'] ?? '');
                if ($phone) $phoneSource = 'ucrm_phone';
            }

            // Source 3: phone regex in note field
            if (!$phone && !empty($client['note'])) {
                if (preg_match('/(?:\+?211|0)\s*\d[\d\s\-]{6,12}\d/', $client['note'], $pm)) {
                    $phone = preg_replace('/[\s\-]/', '', $pm[0]);
                    $phoneSource = 'ucrm_note';
                }
            }

            // Source 4: individual client API fetch (contacts[] guaranteed)
            // Only if all cache sources failed — avoids extra API calls when not needed
            if (!$phone) {
                $clientFull = $crm->get("clients/{$crmClientId}");
                if ($clientFull) {
                    // Update cache entry with full data for future runs
                    $clientsById[$crmClientId] = $clientFull;
                    // Try contacts[] from full fetch
                    if (!empty($clientFull['contacts']) && is_array($clientFull['contacts'])) {
                        foreach ($clientFull['contacts'] as $contact) {
                            $cp = trim($contact['phone'] ?? '');
                            if ($cp) { $phone = $cp; $phoneSource = 'ucrm_contacts_full'; break; }
                        }
                    }
                    // Try top-level phone from full fetch
                    if (!$phone) {
                        $ph = trim($clientFull['phone'] ?? '');
                        if ($ph) { $phone = $ph; $phoneSource = 'ucrm_phone_full'; }
                    }
                    // Also update address if we now have better data
                    if (!$fullAddr) {
                        $s1 = trim($clientFull['street1'] ?? '');
                        $s2 = trim($clientFull['street2'] ?? '');
                        $ct = trim($clientFull['city'] ?? '');
                        $fullAddr = implode(', ', array_filter([$s1, $s2, $ct]));
                    }
                }
            }

            $crmName  = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
            if (!$crmName) $crmName = $client['companyName'] ?? '';

            $area = $this->extractArea($fullAddr);

            // If address is still empty but we have the CRM client, try note field
            if (!$fullAddr && !empty($client['note'])) {
                $area = $this->extractArea($client['note']);
                $fullAddr = trim($client['note']);
            }

            // ── Update ticket record ───────────────────────────────────────
            $t['crm_client_id']    = $crmClientId;
            $t['crm_client_name']  = $crmName ?: ($t['customer_name'] ?? '');
            if ($fullAddr) $t['address'] = $fullAddr;
            if ($phone) {
                $t['phone'] = $phone;
                $t['phone_source'] = $phoneSource ?? 'ucrm';
                $phoneFound++;
            }
            // Only overwrite area from CRM if not already set from KYC
            if (empty($t['area']) || ($t['area'] ?? 'Unknown') === 'Unknown') {
                $t['area'] = $area;
            }
            $t['crm_enriched_at']  = date('Y-m-d H:i:s');
            // Update customer_name if CRM has a better one
            if ($crmName && ($t['customer_name'] ?? '') !== $crmName) {
                $t['customer_name'] = $crmName;
            }

            $enriched++;
            $dirty = true;
        }
        unset($t);

        if ($dirty) {
            $this->store->save('splynx_tickets.json', array_values($tickets));
            $this->syncAllTicketsToTable($tickets); // Phase 2: dual-write
        }

        return [
            'enriched' => $enriched, 
            'skipped'  => $skipped, 
            'failed'   => $failed,
            'phones_found' => $phoneFound,
            'cache_info' => [
                'client_count'       => $crmCache['client_count'] ?? 0,
                'service_count'      => $crmCache['service_count'] ?? 0,
                'attr_found'         => $crmCache['attr_found'] ?? 0,
                'splynx_svc_to_crm'  => $crmCache['splynx_svc_to_crm'] ?? 0,
                'splynx_cust_to_crm' => $crmCache['splynx_cust_to_crm'] ?? 0,
                'name_index_count'   => $crmCache['name_index_count'] ?? 0,
                'attr_sample'        => $crmCache['attr_sample'] ?? [],
                'phone_diagnostic'   => $crmCache['phone_diagnostic'] ?? [],
                'cached_at'          => $crmCache['cached_at'] ?? '',
            ],
        ];
    }

    /**
     * Build an in-memory cache for CRM enrichment.
     *
     * PRIMARY matching (100% accurate):
     *   CRM client custom attribute "TicketID" = Splynx ticket ID
     *   e.g. CRM client #1250 has TicketID=252 → matches Splynx ticket #252
     *
     * FALLBACK: FTTH number → CRM username, then partial name match
     */
    private function buildCrmEnrichCache(\CrmApiClient $crm): ?array
    {
        $clients = $crm->get('clients');
        if (!is_array($clients)) return null;

        $clientsById       = [];
        $ticketIdToCrm     = [];
        $usernameIndex     = [];
        $nameIndex         = [];
        $ticketIdSamples   = [];
        $phoneDiag         = ['top_level' => 0, 'contacts' => 0, 'note_regex' => 0, 'none' => 0, 'sample_keys' => []];

        foreach ($clients as $c) {
            $id = (int)($c['id'] ?? 0);
            if (!$id) continue;
            $clientsById[$id] = $c;

            // ── Phone diagnostic ────────────────────────────────────────
            $hasPhone = false;
            if (!empty(trim($c['phone'] ?? ''))) { $phoneDiag['top_level']++; $hasPhone = true; }
            if (!empty($c['contacts']) && is_array($c['contacts'])) {
                foreach ($c['contacts'] as $ct) {
                    if (!empty(trim($ct['phone'] ?? ''))) { $phoneDiag['contacts']++; $hasPhone = true; break; }
                }
            }
            if (!$hasPhone && !empty($c['note']) && preg_match('/(?:\+?211|0)\s*\d[\d\s\-]{6,12}\d/', $c['note'])) {
                $phoneDiag['note_regex']++; $hasPhone = true;
            }
            if (!$hasPhone) $phoneDiag['none']++;
            // Capture sample of first client's keys for debugging
            if (empty($phoneDiag['sample_keys']) && $id) {
                $phoneDiag['sample_keys'] = array_keys($c);
                // Also capture contacts structure if present
                if (!empty($c['contacts']) && is_array($c['contacts']) && !empty($c['contacts'][0])) {
                    $phoneDiag['sample_contact_keys'] = array_keys($c['contacts'][0]);
                }
            }

            // ── PRIMARY: Check custom attributes for TicketID ───────────
            $attrs = $c['attributes'] ?? [];
            if (is_array($attrs)) {
                foreach ($attrs as $attr) {
                    $key = strtolower(trim($attr['key'] ?? $attr['name'] ?? ''));
                    $val = trim($attr['value'] ?? '');
                    if ($val !== '' && is_numeric($val) && ($key === 'ticketid' || $key === 'ticket_id' || $key === 'ticket id' || $key === 'ticket')) {
                        $ticketIdToCrm[(int)$val] = $id;
                        if (count($ticketIdSamples) < 5) {
                            $crmName = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
                            $ticketIdSamples[] = "Ticket#{$val} → CRM#{$id} ({$crmName})";
                        }
                    }
                }
            }

            // ── Username index (FTTH fallback) ──────────────────────────
            $username = strtolower(trim($c['username'] ?? ''));
            if ($username) $usernameIndex[$username] = $id;
            $ident = strtolower(trim($c['userIdent'] ?? ''));
            if ($ident) $usernameIndex[$ident] = $id;

            // ── Name index ──────────────────────────────────────────────
            $first = strtolower(trim($c['firstName'] ?? ''));
            $last  = strtolower(trim($c['lastName'] ?? ''));
            $full  = trim("$first $last");
            if ($full && $full !== ' ') {
                $nameIndex[$full] = $id;
                $reversed = trim("$last $first");
                if ($reversed !== $full) $nameIndex[$reversed] = $id;
            }
            $company = strtolower(trim($c['companyName'] ?? ''));
            if ($company) $nameIndex[$company] = $id;
        }

        return [
            'cached_at'            => date('Y-m-d H:i:s'),
            'client_count'         => count($clientsById),
            'ticket_id_matches'    => count($ticketIdToCrm),
            'username_index_count' => count($usernameIndex),
            'name_index_count'     => count($nameIndex),
            'ticket_id_samples'    => $ticketIdSamples,
            'phone_diagnostic'     => $phoneDiag,
            'clients_by_id'        => $clientsById,
            'ticket_to_crm'        => $ticketIdToCrm,
            'username_index'       => $usernameIndex,
            'name_index'           => $nameIndex,
            'splynx_to_crm'        => [],
        ];
    }

    /**
     * Get open tickets grouped by area for the dispatch view.
     * Returns: [ 'area_name' => ['area' => ..., 'open_count' => ..., 'tickets' => [...]] ]
     */
    public function getAreaDispatch(): array
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $areas = [];

        // Init all 50 Juba areas
        foreach (self::getJubaAreas() as $name) {
            $areas[$name] = [
                'area'       => $name,
                'open_count' => 0,
                'urgent'     => 0,
                'new'        => 0,
                'survey'     => 0,
                'deploying'  => 0,
                'tickets'    => [],
            ];
        }

        foreach ($tickets as $t) {
            // Skip completed and cancelled
            if (!empty($t['install_complete'])) continue;
            $status = (int)($t['status'] ?? 0);
            if ($this->isCancelledStatus($status)) continue;

            $area = $t['area'] ?? $this->extractArea($t['address'] ?? '');
            if (!isset($areas[$area])) {
                $areas[$area] = ['area' => $area, 'open_count' => 0, 'urgent' => 0, 'new' => 0, 'survey' => 0, 'deploying' => 0, 'tickets' => []];
            }

            $areas[$area]['open_count']++;
            $areas[$area]['tickets'][] = $t;

            if ($status === self::STATUS_NEW) $areas[$area]['new']++;
            elseif ($status === self::STATUS_SURVEY_DONE) $areas[$area]['survey']++;
            elseif ($status === self::STATUS_FIBER_DEPLOYMENT) $areas[$area]['deploying']++;

            // Urgent = new ticket older than 3 days without assignment
            $createdDays = (time() - strtotime($t['created_at'] ?? 'now')) / 86400;
            if ($createdDays > 3 && empty($t['assigned_engineer_name'])) {
                $areas[$area]['urgent']++;
            }
        }

        // Sort: areas with most open tickets first
        uasort($areas, function($a, $b) { return $b['open_count'] <=> $a['open_count']; });

        return array_values($areas);
    }

    /**
     * Batch assign engineer to all open tickets in an area.
     * @return int Number of tickets assigned
     */
    public function batchAssignArea(string $area, string $engineerName, string $engineerId = ''): int
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $count   = 0;

        foreach ($tickets as &$t) {
            if (!empty($t['install_complete'])) continue;
            $tArea = $t['area'] ?? $this->extractArea($t['address'] ?? '');
            if ($tArea !== $area) continue;
            if (!empty($t['assigned_engineer_name'])) continue; // already assigned

            $t['assigned_engineer_name'] = $engineerName;
            $t['assigned_engineer_id']   = $engineerId;
            $t['assigned_at']            = date('Y-m-d H:i:s');
            if (empty($t['testing_status'])) $t['testing_status'] = 'pending';
            $count++;
        }
        unset($t);

        if ($count > 0) {
            $this->store->save('splynx_tickets.json', array_values($tickets));
            $this->syncAllTicketsToTable($tickets); // Phase 2: dual-write
        }
        return $count;
    }

    /**
     * Returns the canonical list of all 50 Juba City areas.
     */
    public static function getJubaAreas(): array
    {
        return [
            'Juba Town', 'Hai Jerusalem', 'Hai Mayo', 'Hai Gonyo', 'Hai Tarawa',
            'Hai Darussalam', 'Hai Referendum', 'Hai Mauna', 'St Kizito',
            'Munuki Libya', 'Munuki Melissa', 'New Site', 'Mangaten', 'Thongping',
            'Kololo', 'Hai Amarat', 'Hai Jalaba', 'Hai Cinema', 'Hai Seminary',
            'Hai Malakal', 'Buluk', 'Hai Thoura', 'Custom', 'Nyakuron West',
            'Nyakuron East', 'Rock City', 'Gudele 1', 'Gudele 2', 'Jebel Yesua',
            'Jebel', 'Gurei', 'Konyokonyo', 'Hai Kuwait', 'Mia Saba', 'Lologo',
            'Kor William', 'Kator', 'Atlabara', 'Melikia', 'Hai Neem',
            'Gumbo Market', 'Gumbo Shirkat', 'Hai Jaborona', 'Hai Nimra Talata',
            'Hai Gabat', 'Jondoru', 'Kasire', 'Gbongoroki', 'Hai Game', 'Joppa',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════════

    private function persistTicket(array $ticket): void
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        // Replace if already exists
        foreach ($tickets as $i => $t) {
            if ((int)($t['id'] ?? 0) === (int)$ticket['id']) {
                $tickets[$i] = $ticket;
                $this->store->save('splynx_tickets.json', $tickets);
                $this->syncTicketToTable($ticket); // Phase 2: dual-write
                return;
            }
        }
        $tickets[] = $ticket;
        $this->store->save('splynx_tickets.json', $tickets);
        $this->syncTicketToTable($ticket); // Phase 2: dual-write
    }

    private function notifyInstallComplete(array $ticket): void
    {
        $adminPhone = $this->config['whatsapp_admin_phone'] ?? '';
        if (!$adminPhone) return;

        $name    = $ticket['customer_name'] ?? '?';
        $addr    = $ticket['address'] ?? '?';
        $eng     = $ticket['engineer'] ?? 'Engineer';
        $appId   = $ticket['app_id']   ?? '?';
        $msg     = "✅ *Installation Complete*\n"
                 . "Customer: {$name}\n"
                 . "Address: {$addr}\n"
                 . "Engineer: {$eng}\n"
                 . "App ID: DN-{$appId}\n"
                 . "Ready for service activation in Splynx.";

        try {
            $this->notify->sendWhatsApp($adminPhone, $msg, 'support');
        } catch (\Throwable $e) {
            error_log("SplynxTicketService: WA notify failed — " . $e->getMessage());
        }
    }

    private function statusLabel(int $status, string $remoteName = ''): string
    {
        $lblMap = [
            1  => 'new',
            2  => 'work in progress',
            3  => 'resolved',
            4  => 'waiting your answer',
            5  => 'waiting on agent',
            7  => 'survey done',
            8  => 'fiber deployment in progress',
            9  => 'ready onu mapped',
            10 => 'cancel by customer',
            11 => 'fiber not available',
            12 => 'client not ready',
        ];
        // Fall back to remote name if Splynx gives us the label
        if (isset($lblMap[$status])) return $lblMap[$status];
        if ($remoteName) return strtolower(trim($remoteName));
        // Unknown statuses (e.g. 13) → show as-is; treated as OPEN in pipeline
        return 'status-' . $status;
    }

    /**
     * Is this status considered "install complete"?
     * Resolved (3) = installation done and verified.
     */
    private function isCompletedStatus(int $status): bool
    {
        // 3=Resolved, 4=Solved/Waiting your answer, 5=Closed/Waiting on agent
        // All three mean the installation ticket is done
        return in_array($status, self::COMPLETED_STATUSES, true);
    }

    /**
     * Is this status considered "cancelled / will not proceed"?
     */
    private function isCancelledStatus(int $status): bool
    {
        // 10 = Cancel by Customer, 11 = Fiber Not Available, 12 = Client Not Ready
        // Status 13 or any other unknown status → NOT cancelled → stays in pipeline
        return in_array($status, [10, 11, 12], true);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BIDAL SUPPORT LEADER — Installation Tracking (v3.6.9)
    // ═══════════════════════════════════════════════════════════════════════════

    public function assignEngineer(int $ticketId, string $engineerName, string $engineerId = ''): bool
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $found   = false;
        foreach ($tickets as &$t) {
            if ((int)($t['id'] ?? 0) === $ticketId) {
                $t['assigned_engineer_name'] = $engineerName;
                $t['assigned_engineer_id']   = $engineerId;
                $t['assigned_at']            = date('Y-m-d H:i:s');
                if (empty($t['testing_status'])) $t['testing_status'] = 'pending';
                $found = true; break;
            }
        }
        unset($t);
        if (!$found) return false;
        $this->store->save('splynx_tickets.json', array_values($tickets));
        // Phase 2: dual-write the modified ticket
        foreach ($tickets as $_t) { if ((int)($_t['id']??0) === $ticketId) { $this->syncTicketToTable($_t); break; } }
        return true;
    }

    public function saveInstallData(int $ticketId, array $data): bool
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $found   = false;
        foreach ($tickets as &$t) {
            if ((int)($t['id'] ?? 0) === $ticketId) {
                if (!empty($data['onu_serial']))    $t['onu_serial']     = trim($data['onu_serial']);
                if (!empty($data['olt_port']))       $t['olt_port']       = trim($data['olt_port']);
                if (isset($data['signal_db']))       $t['signal_db']      = (float)$data['signal_db'];
                if (!empty($data['notes']))          $t['install_notes']  = trim($data['notes']);
                if (!empty($data['testing_status'])) $t['testing_status'] = $data['testing_status'];
                $t['install_data_at'] = date('Y-m-d H:i:s');
                $t['install_data_by'] = $data['submitted_by'] ?? '';
                $found = true; break;
            }
        }
        unset($t);
        if (!$found) return false;
        $this->store->save('splynx_tickets.json', array_values($tickets));
        $this->syncAllTicketsToTable($tickets); // Phase 2: dual-write
        return true;
    }

    public function commissionInstallation(int $ticketId, string $commissionedBy, string $notes = ''): array
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $found   = null;
        foreach ($tickets as &$t) {
            if ((int)($t['id'] ?? 0) === $ticketId) {
                $t['install_complete']    = true;
                $t['install_complete_at'] = date('Y-m-d H:i:s');
                $t['commissioned_by']     = $commissionedBy;
                $t['commission_notes']    = $notes;
                $t['testing_status']      = 'approved';
                $found = $t; break;
            }
        }
        unset($t);
        if (!$found) return ['ok' => false, 'error' => 'Ticket not found'];
        $this->store->save('splynx_tickets.json', array_values($tickets));
        $this->syncAllTicketsToTable($tickets); // Phase 2: dual-write
        if (!empty($found['app_id'])) {
            $this->store->updateOne('kyc_applications.json', 'id', (int)$found['app_id'], [
                'splynx_ticket_status' => 'commissioned',
                'installation_done_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $this->notifyInstallComplete($found);
        return ['ok' => true, 'ticket' => $found];
    }

    public function rejectInstallation(int $ticketId, string $rejectedBy, string $reason = ''): bool
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $found   = false;
        foreach ($tickets as &$t) {
            if ((int)($t['id'] ?? 0) === $ticketId) {
                $t['testing_status']  = 'rejected';
                $t['rejected_by']     = $rejectedBy;
                $t['rejection_notes'] = $reason;
                $t['rejected_at']     = date('Y-m-d H:i:s');
                $found = true; break;
            }
        }
        unset($t);
        if (!$found) return false;
        $this->store->save('splynx_tickets.json', array_values($tickets));
        $this->syncAllTicketsToTable($tickets); // Phase 2: dual-write
        return true;
    }

    /**
     * Close a Splynx installation ticket — two-step:
     *   1. Set status → Solved  (STATUS_SOLVED = 4)
     *   2. Add a comment explaining why it was closed
     *   3. Set closed = 1  (hard close)
     *
     * Called automatically when cron detects Bidal has created the Splynx service
     * for this customer — meaning installation is physically complete.
     *
     * @param  int    $ticketId   Splynx ticket ID (e.g. 197)
     * @param  string $custName   Customer name for the comment
     * @param  string $splynxUser Splynx username e.g. FTTH000193
     * @return array  ['ok'=>bool, 'error'=>string]
     */
    public function closeTicket(int $ticketId, string $custName = '', string $splynxUser = ''): array
    {
        if (!$ticketId) {
            return ['ok' => false, 'error' => 'No ticket ID'];
        }

        try {
            // ── Step 1: Set status to Solved ──────────────────────────────
            $solved = $this->splynx->updateTicket($ticketId, [
                'status' => self::STATUS_SOLVED,
            ]);
            if ($solved === null) {
                error_log("[closeTicket] Failed to set Solved on ticket #{$ticketId}: " . json_encode($this->splynx->getLastError()));
                // Non-fatal — attempt close anyway
            }

            // ── Step 2: Add closing comment ───────────────────────────────
            $nameStr = $custName ? " for {$custName}" : '';
            $userStr = $splynxUser ? " (Splynx: {$splynxUser})" : '';
            $comment = "✅ Fiber service activated{$nameStr}{$userStr}. "
                     . "Delivery acknowledgment sent to customer via WhatsApp. "
                     . "Ticket auto-closed by DishNet system on " . date('d M Y H:i') . ".";

            $this->splynx->addTicketMessage($ticketId, $comment);

            // ── Step 3: Hard close ────────────────────────────────────────
            $closed = $this->splynx->updateTicket($ticketId, [
                'closed' => '1',
            ]);
            if ($closed === null) {
                return ['ok' => false, 'error' => 'Closed API call failed: ' . json_encode($this->splynx->getLastError())];
            }

            // ── Update local tickets table ────────────────────────────────
            try {
                $pdo = $this->store->getPdo();
                $pdo->prepare(
                    "UPDATE tickets SET
                        status = ?, status_label = 'solved',
                        splynx_ticket_status = 'completed',
                        install_complete = 1,
                        install_complete_at = datetime('now'),
                        updated_at = datetime('now')
                     WHERE id = ?"
                )->execute([self::STATUS_SOLVED, $ticketId]);
            } catch (\Throwable $e) {
                // Local DB update failure is non-fatal — Splynx is source of truth
                error_log("[closeTicket] Local DB update failed for ticket #{$ticketId}: " . $e->getMessage());
            }

            error_log("[closeTicket] Ticket #{$ticketId} closed successfully");
            return ['ok' => true];

        } catch (\Throwable $e) {
            error_log("[closeTicket] Exception for ticket #{$ticketId}: " . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function getTestingQueue(): array
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        return array_values(array_filter($tickets, fn($t) =>
            empty($t['install_complete']) && ($t['testing_status'] ?? '') === 'ready'
        ));
    }

    public function getEngineerJobs(string $engineerNameOrId, bool $includeComplete = false): array
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        return array_values(array_filter($tickets, function($t) use ($engineerNameOrId, $includeComplete) {
            if (!$includeComplete && !empty($t['install_complete'])) return false;
            return ($t['assigned_engineer_name'] ?? '') === $engineerNameOrId
                || ($t['assigned_engineer_id']   ?? '') === $engineerNameOrId
                || ($t['engineer']               ?? '') === $engineerNameOrId;
        }));
    }

    public function getTicketById(int $ticketId): ?array
    {
        foreach (($this->store->load('splynx_tickets.json') ?? []) as $t) {
            if ((int)($t['id'] ?? 0) === $ticketId) return $t;
        }
        return null;
    }

    public function markReadyForCommissioning(int $ticketId, string $engineerName): bool
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $found   = false;
        foreach ($tickets as &$t) {
            if ((int)($t['id'] ?? 0) === $ticketId) {
                $t['testing_status']     = 'ready';
                $t['ready_at']           = date('Y-m-d H:i:s');
                $t['ready_submitted_by'] = $engineerName;
                $found = true; break;
            }
        }
        unset($t);
        if (!$found) return false;
        $this->store->save('splynx_tickets.json', array_values($tickets));
        $this->syncAllTicketsToTable($tickets); // Phase 2: dual-write
        return true;
    }

    public function savePhoto(int $ticketId, string $photoType, string $filename, string $submittedBy): bool
    {
        $tickets = $this->store->load('splynx_tickets.json') ?? [];
        $found   = false;
        foreach ($tickets as &$t) {
            if ((int)($t['id'] ?? 0) === $ticketId) {
                $t['photos'][] = ['type' => $photoType, 'filename' => $filename,
                    'submitted_by' => $submittedBy, 'at' => date('Y-m-d H:i:s')];
                $found = true; break;
            }
        }
        unset($t);
        if (!$found) return false;
        $this->store->save('splynx_tickets.json', array_values($tickets));
        $this->syncAllTicketsToTable($tickets); // Phase 2: dual-write
        return true;
    }

    /**
     * Extract area name from an address string.
     * "Gudele Block 3, Juba" → "Gudele"
     */
    private function extractArea(string $address): string
    {
        if (!$address) return 'Unknown';

        // ── Complete Juba City area map (50 areas) ──────────────────────────
        // Each entry: canonical name => [search keywords / aliases]
        static $areaMap = null;
        if ($areaMap === null) {
            $areaMap = [
                'Juba Town'          => ['juba town', 'juba'],
                'Hai Jerusalem'      => ['jerusalem', 'hai jerusalem'],
                'Hai Mayo'           => ['hai mayo', 'mayo'],
                'Hai Gonyo'          => ['gonyo', 'hai gonyo'],
                'Hai Tarawa'         => ['tarawa', 'hai tarawa'],
                'Hai Darussalam'     => ['darussalam', 'hai darussalam', 'dar es salaam'],
                'Hai Referendum'     => ['referendum', 'hai referendum'],
                'Hai Mauna'          => ['mauna', 'hai mauna'],
                'St Kizito'          => ['kizito', 'st kizito', 'saint kizito', 'st. kizito'],
                'Munuki Libya'       => ['munuki libya', 'mumuki souk libya', 'mumuki libya', 'munuki souk'],
                'Munuki Melissa'     => ['munuki melissa', 'melissa'],
                'New Site'           => ['new site', 'newsite'],
                'Mangaten'           => ['mangaten'],
                'Thongping'          => ['thongping', 'tongping', 'thong ping', 'tong ping', 'tomping'],
                'Kololo'             => ['kololo'],
                'Hai Amarat'         => ['amarat', 'hai amarat'],
                'Hai Jalaba'         => ['jalaba', 'hai jalaba'],
                'Hai Cinema'         => ['cinema', 'hai cinema', 'hai cenima'],
                'Hai Seminary'       => ['seminary', 'hai seminary'],
                'Hai Malakal'        => ['malakal', 'hai malakal'],
                'Buluk'              => ['buluk', 'bilpham'],
                'Hai Thoura'         => ['thoura', 'hai thoura', 'hai thoora'],
                'Custom'             => ['custom', 'customs'],
                'Nyakuron West'      => ['nyakuron west'],
                'Nyakuron East'      => ['nyakuron east'],
                'Rock City'          => ['rock city'],
                'Gudele 1'           => ['gudele 1', 'gudele one'],
                'Gudele 2'           => ['gudele 2', 'gudele two'],
                'Jebel Yesua'        => ['jebel yesua', 'jebel yeshua'],
                'Jebel'              => ['jebel', 'jabel'],  // 'jabel' is common ticket misspelling
                'Gurei'              => ['gurei', 'guriei'],
                'Konyokonyo'         => ['konyokonyo', 'konyo konyo', 'koniyo koniyo', 'konyo', 'koniyo'],
                'Hai Kuwait'         => ['kuwait', 'hai kuwait'],
                'Mia Saba'           => ['mia saba', 'miasaba'],
                'Lologo'             => ['lologo'],
                'Kor William'        => ['kor william', 'korwilliam'],
                'Kator'              => ['kator'],
                'Atlabara'           => ['atlabara'],
                'Melikia'            => ['melikia', 'malakia', 'malakiya'],
                'Hai Neem'           => ['neem', 'hai neem'],
                'Gumbo Market'       => ['gumbo market'],
                'Gumbo Shirkat'      => ['gumbo shirkat', 'shirkat'],
                'Hai Jaborona'       => ['jaborona', 'hai jaborona', 'hai jabrona'],
                'Hai Nimra Talata'   => ['nimra talata', 'hai nimra', 'nimra'],
                'Hai Gabat'          => ['gabat', 'hai gabat'],
                'Jondoru'            => ['jondoru'],
                'Kasire'             => ['kasire'],
                'Gbongoroki'         => ['gbongoroki'],
                'Hai Game'           => ['hai game', 'game'],
                'Joppa'              => ['joppa'],
            ];
        }

        $addressLower = strtolower(trim($address));

        // Exact-match multi-word patterns first (longest match wins)
        // Sort by keyword length descending so "Gumbo Market" matches before "Gumbo"
        $bestArea   = null;
        $bestLen    = 0;
        foreach ($areaMap as $canonical => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($addressLower, $kw) && strlen($kw) > $bestLen) {
                    $bestArea = $canonical;
                    $bestLen  = strlen($kw);
                }
            }
        }

        // Special handling: bare "Gudele" without 1/2 → "Gudele 1"
        if (!$bestArea && str_contains($addressLower, 'gudele')) {
            $bestArea = 'Gudele 1';
        }
        // Bare "Munuki" without Libya/Melissa → "Munuki Libya"
        if (!$bestArea && str_contains($addressLower, 'munuki')) {
            $bestArea = 'Munuki Libya';
        }
        // Bare "Nyakuron" without East/West → "Nyakuron West"
        if (!$bestArea && str_contains($addressLower, 'nyakuron')) {
            $bestArea = 'Nyakuron West';
        }
        // Bare "Gumbo" without Market/Shirkat → "Gumbo Market"
        if (!$bestArea && str_contains($addressLower, 'gumbo')) {
            $bestArea = 'Gumbo Market';
        }

        if ($bestArea) return $bestArea;

        // Fall back to first word of address
        $first = trim(explode(',', $address)[0]);
        $words = explode(' ', $first);
        return $words[0] ?: 'Other';
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DUAL-WRITE: Sync ticket data to normalized SQL table (Phase 2 v3.8)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Write a single ticket to the normalized tickets SQL table.
     * Called after every JSON blob save to keep both stores in sync.
     *
     * This is a WRITE-ONLY operation during the dual-write period.
     * Reads still come from the JSON blob until Phase 3 migrates them.
     *
     * Safe to call even if the tickets table doesn't exist yet
     * (pre-migration). Silently catches errors.
     */
    public function syncTicketToTable(array $t): void
    {
        try {
            $pdo = $this->store->getPdo();

            // Check if tickets table exists (fast cached check)
            static $tableExists = null;
            if ($tableExists === null) {
                $tableExists = (bool)$pdo->query(
                    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='tickets'"
                )->fetch();
            }
            if (!$tableExists) return;

            $id = (int)($t['id'] ?? 0);
            if (!$id) return;

            $stmt = $pdo->prepare('
                INSERT OR REPLACE INTO tickets (
                    id, app_id, customer_id, crm_client_id,
                    customer_name, crm_client_name, address, phone, phone_source, area,
                    status, status_label, priority, subject, ftth_number,
                    engineer, assigned_engineer_id, assigned_engineer_name, assigned_at,
                    install_complete, install_complete_at,
                    onu_serial, olt_port, signal_db, install_notes, install_data_at, install_data_by,
                    testing_status, commissioned_by, commission_notes,
                    ready_at, ready_submitted_by,
                    rejected_by, rejection_notes, rejected_at,
                    photos, gps_lat, gps_lng,
                    status_changed_at, status_changed_by, cancelled,
                    crm_enriched_at, crm_enrich_attempted,
                    splynx_imported, splynx_ticket_id, splynx_service_id,
                    synced_at, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?
                )
            ');

            $stmt->execute([
                $id,
                (int)($t['app_id'] ?? 0),
                (int)($t['customer_id'] ?? 0),
                !empty($t['crm_client_id']) ? (int)$t['crm_client_id'] : null,

                $t['customer_name'] ?? '',
                $t['crm_client_name'] ?? '',
                $t['address'] ?? '',
                $t['phone'] ?? '',
                $t['phone_source'] ?? '',
                $t['area'] ?? 'Unknown',

                (int)($t['status'] ?? 1),
                $t['status_label'] ?? 'new',
                $t['priority'] ?? '',
                $t['subject'] ?? '',
                $t['ftth_number'] ?? '',

                $t['engineer'] ?? '',
                $t['assigned_engineer_id'] ?? '',
                $t['assigned_engineer_name'] ?? '',
                $t['assigned_at'] ?? null,

                empty($t['install_complete']) ? 0 : 1,
                $t['install_complete_at'] ?? null,

                $t['onu_serial'] ?? '',
                $t['olt_port'] ?? '',
                isset($t['signal_db']) ? (float)$t['signal_db'] : null,
                $t['install_notes'] ?? '',
                $t['install_data_at'] ?? null,
                $t['install_data_by'] ?? '',

                $t['testing_status'] ?? '',
                $t['commissioned_by'] ?? '',
                $t['commission_notes'] ?? '',
                $t['ready_at'] ?? null,
                $t['ready_submitted_by'] ?? '',
                $t['rejected_by'] ?? '',
                $t['rejection_notes'] ?? '',
                $t['rejected_at'] ?? null,

                is_array($t['photos'] ?? null) ? json_encode($t['photos']) : ($t['photos'] ?? '[]'),
                isset($t['gps_lat']) ? (float)$t['gps_lat'] : null,
                isset($t['gps_lng']) ? (float)$t['gps_lng'] : null,

                $t['status_changed_at'] ?? null,
                $t['status_changed_by'] ?? '',
                empty($t['cancelled']) ? 0 : 1,

                $t['crm_enriched_at'] ?? null,
                $t['crm_enrich_attempted'] ?? null,

                empty($t['splynx_imported']) ? 0 : 1,
                (int)($t['splynx_ticket_id'] ?? $t['id'] ?? 0),
                !empty($t['splynx_service_id']) ? (int)$t['splynx_service_id'] : null,

                date('Y-m-d H:i:s'), // synced_at = now
                $t['created_at'] ?? date('Y-m-d H:i:s'),
                $t['updated_at'] ?? date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Dual-write failure is non-fatal — JSON blob is the source of truth
            error_log("SplynxTicketService::syncTicketToTable error for ticket #{$t['id']}: " . $e->getMessage());
        }
    }

    /**
     * Sync ALL tickets to the normalized table.
     * Called after bulk operations (syncTickets, enrichFromCrm, batchAssign).
     */
    private function syncAllTicketsToTable(array $tickets): void
    {
        try {
            $pdo = $this->store->getPdo();
            $pdo->beginTransaction();
            foreach ($tickets as $t) {
                $this->syncTicketToTable($t);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            try { $this->store->getPdo()->rollBack(); } catch (\Throwable $ignore) {}
            error_log("SplynxTicketService::syncAllTicketsToTable error: " . $e->getMessage());
        }
    }
}
