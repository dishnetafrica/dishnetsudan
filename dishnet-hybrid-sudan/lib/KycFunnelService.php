<?php
/**
 * KycFunnelService — KYC Lifecycle Funnel
 *
 * Tracks every KYC application through 6 stages:
 *   1. KYC Submitted
 *   2. CRM Client Created
 *   3. Ticket / Service Provisioned
 *   4. Installed
 *   5. Invoice Created
 *   6. Paid
 *
 * Fiber path:  KYC → CRM → Splynx Ticket → Install Complete → Invoice → Paid
 * Starlink path: KYC → CRM → CRM Service Active → Invoice → Paid
 */
class KycFunnelService
{
    private $pdo;
    private $store;

    public function __construct(\PDO $pdo, $store)
    {
        $this->pdo   = $pdo;
        $this->store = $store;
    }

    /**
     * Get funnel summary counts + per-customer detail.
     *
     * @param string $filter  'all', 'fiber', 'starlink'
     * @return array ['counts' => [...], 'customers' => [...]]
     */
    public function getFunnel(string $filter = 'all'): array
    {
        $apps = $this->store->load('kyc_applications.json') ?? [];
        $excluded = $this->store->load('kyc_excluded.json') ?? [];
        $excludedIds = [];
        foreach ($excluded as $ex) {
            $excludedIds[(int)($ex['app_id'] ?? 0)] = $ex['reason'] ?? 'excluded';
        }
        $customers = [];
        $excludedList = [];

        foreach ($apps as $a) {
            $type = $a['customer_type'] ?? $a['service_type'] ?? '';
            $typeLow = strtolower($type);
            $isFiber = (strpos($typeLow, 'fiber') !== false || strpos($typeLow, 'ftth') !== false);
            $isStarlink = (strpos($typeLow, 'star') !== false);

            if ($filter === 'fiber' && !$isFiber) continue;
            if ($filter === 'starlink' && !$isStarlink) continue;

            $appId    = (int)($a['id'] ?? 0);

            // Skip excluded (cancelled/demo)
            if (isset($excludedIds[$appId])) {
                $excludedList[] = [
                    'app_id' => $appId,
                    'name'   => trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '')),
                    'type'   => $isFiber ? 'Fiber' : ($isStarlink ? 'Starlink' : $type),
                    'reason' => $excludedIds[$appId],
                ];
                continue;
            }

            $crmId    = (int)($a['crm_client_id'] ?? 0);
            $appId    = (int)($a['id'] ?? 0);
            $name     = trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? ''));
            $phone    = $a['mobile'] ?? '';
            $status   = $a['status'] ?? '';
            $createdAt = $a['created_at'] ?? '';

            $c = [
                'app_id'       => $appId,
                'name'         => $name,
                'phone'        => $phone,
                'type'         => $isFiber ? 'Fiber' : ($isStarlink ? 'Starlink' : $type),
                'crm_client_id'=> $crmId,
                'kyc_status'   => $status,
                'created_at'   => $createdAt,
                // Stages
                'stage'        => 1, // KYC Submitted
                's1_kyc'       => true,
                's2_crm'       => false,
                's3_provisioned'=> false,
                's4_installed' => false,
                's5_invoiced'  => false,
                's6_paid'      => false,
                // Detail
                'ticket_id'    => 0,
                'ticket_status'=> '',
                'invoice_count'=> 0,
                'unpaid_count' => 0,
                'total_due'    => 0,
                'area'         => '',
            ];

            // Stage 2: CRM Client Created
            if ($crmId > 0) {
                $c['s2_crm'] = true;
                $c['stage'] = 2;
            }

            // Stage 3: Provisioned (Fiber = ticket exists, Starlink = CRM active)
            if ($crmId > 0) {
                if ($isFiber) {
                    $c = $this->checkFiberTicket($c, $crmId);
                } else {
                    // Starlink: check CRM service status from invoice_cache
                    $c = $this->checkCrmActive($c, $crmId);
                }
            }

            // Stage 5 & 6: Invoice + Paid (from invoice_cache)
            if ($crmId > 0) {
                $c = $this->checkInvoices($c, $crmId);
            }

            $customers[] = $c;
        }

        // Sort by stage (lowest first = stuck earliest)
        usort($customers, function($a, $b) {
            return $a['stage'] - $b['stage'];
        });

        // Count per stage
        $counts = ['kyc' => 0, 'crm' => 0, 'provisioned' => 0, 'installed' => 0, 'invoiced' => 0, 'paid' => 0];
        $fiberCounts = ['kyc' => 0, 'crm' => 0, 'provisioned' => 0, 'installed' => 0, 'invoiced' => 0, 'paid' => 0];
        $starlinkCounts = ['kyc' => 0, 'crm' => 0, 'provisioned' => 0, 'installed' => 0, 'invoiced' => 0, 'paid' => 0];

        foreach ($customers as $c) {
            $ref = &$counts;
            if ($c['type'] === 'Fiber') $fRef = &$fiberCounts;
            else $fRef = &$starlinkCounts;

            if ($c['s1_kyc'])        { $ref['kyc']++; $fRef['kyc']++; }
            if ($c['s2_crm'])        { $ref['crm']++; $fRef['crm']++; }
            if ($c['s3_provisioned']){ $ref['provisioned']++; $fRef['provisioned']++; }
            if ($c['s4_installed'])  { $ref['installed']++; $fRef['installed']++; }
            if ($c['s5_invoiced'])   { $ref['invoiced']++; $fRef['invoiced']++; }
            if ($c['s6_paid'])       { $ref['paid']++; $fRef['paid']++; }
            unset($ref, $fRef);
        }

        return [
            'counts'    => $counts,
            'fiber'     => $fiberCounts,
            'starlink'  => $starlinkCounts,
            'customers' => $customers,
            'excluded'  => $excludedList,
            'total'     => count($customers),
        ];
    }

    /**
     * Get customers stuck at a specific stage.
     * "Stuck" = reached this stage but NOT the next one.
     */
    public function getStuckAt(string $stageName): array
    {
        $funnel = $this->getFunnel();
        $stuck = [];
        $stageMap = [
            'kyc'         => ['has' => 's1_kyc',        'next' => 's2_crm'],
            'crm'         => ['has' => 's2_crm',        'next' => 's3_provisioned'],
            'provisioned' => ['has' => 's3_provisioned', 'next' => 's4_installed'],
            'installed'   => ['has' => 's4_installed',   'next' => 's5_invoiced'],
            'invoiced'    => ['has' => 's5_invoiced',    'next' => 's6_paid'],
            'paid'        => ['has' => 's6_paid',        'next' => null],
        ];

        $map = $stageMap[$stageName] ?? null;
        if (!$map) return [];

        foreach ($funnel['customers'] as $c) {
            if ($c[$map['has']] && ($map['next'] === null || !$c[$map['next']])) {
                $stuck[] = $c;
            }
        }
        return $stuck;
    }

    /**
     * Check if a Fiber KYC has a Splynx ticket.
     */
    private function checkFiberTicket(array $c, int $crmId): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, status_label, area FROM tickets WHERE crm_client_id = ? ORDER BY CASE WHEN status_label='resolved' THEN 0 ELSE 1 END, id DESC LIMIT 1");
            $stmt->execute([$crmId]);
            $ticket = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($ticket) {
                $c['s3_provisioned'] = true;
                $c['stage'] = 3;
                $c['ticket_id'] = (int)$ticket['id'];
                $c['ticket_status'] = $ticket['status_label'];
                $c['area'] = $ticket['area'] ?? '';

                // Stage 4: Installed = service created in Splynx (ticket resolved)
                // install_complete flag is unreliable (set during import on 200+ tickets)
                // Only 'resolved' status means Bidal actually created the service
                if ($ticket['status_label'] === 'resolved') {
                    $c['s4_installed'] = true;
                    $c['stage'] = 4;
                }
            }
        } catch (\Throwable $e) {}
        return $c;
    }

    /**
     * Check if CRM client is active (for Starlink — no Splynx tickets).
     */
    private function checkCrmActive(array $c, int $crmId): array
    {
        try {
            $tbl = "invoice_cache_{$crmId}";
            // Check table exists
            $exists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tbl}'")->fetchColumn();
            if (!$exists) return $c;

            $row = $this->pdo->query("SELECT data FROM {$tbl} LIMIT 1")->fetchColumn();
            if (!$row) return $c;

            $data = json_decode($row, true);
            $client = $data['data']['client'] ?? [];

            if (!empty($client['isActive'])) {
                $c['s3_provisioned'] = true;
                $c['s4_installed']   = true; // Starlink: active = installed
                $c['stage'] = 4;
            }
        } catch (\Throwable $e) {}
        return $c;
    }

    /**
     * Check invoice_cache for invoices and payment status.
     */
    private function checkInvoices(array $c, int $crmId): array
    {
        try {
            $tbl = "invoice_cache_{$crmId}";
            $exists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tbl}'")->fetchColumn();
            if (!$exists) return $c;

            $row = $this->pdo->query("SELECT data FROM {$tbl} LIMIT 1")->fetchColumn();
            if (!$row) return $c;

            $data = json_decode($row, true);
            $invoices = $data['data']['invoices'] ?? [];
            $unpaid   = $data['data']['invoices_unpaid'] ?? [];
            $totalDue = (float)($data['data']['total_due'] ?? 0);

            $c['invoice_count'] = count($invoices);
            $c['unpaid_count']  = count($unpaid);
            $c['total_due']     = $totalDue;

            // Stage 5: Has at least one invoice
            if (count($invoices) > 0) {
                $c['s5_invoiced'] = true;
                $c['stage'] = max($c['stage'], 5);
            }

            // Stage 6: All invoices paid (no unpaid + has invoices)
            if (count($invoices) > 0 && count($unpaid) === 0) {
                $c['s6_paid'] = true;
                $c['stage'] = 6;
            }
        } catch (\Throwable $e) {}
        return $c;
    }

    /**
     * Exclude a KYC app from the funnel (cancelled / demo / duplicate).
     */
    public function excludeApp(int $appId, string $reason = 'cancelled'): bool
    {
        $excluded = $this->store->load('kyc_excluded.json') ?? [];
        // Check not already excluded
        foreach ($excluded as $ex) {
            if ((int)($ex['app_id'] ?? 0) === $appId) return true;
        }
        $excluded[] = [
            'app_id'     => $appId,
            'reason'     => $reason,
            'excluded_at'=> date('Y-m-d H:i:s'),
        ];
        $this->store->save('kyc_excluded.json', $excluded);
        return true;
    }

    /**
     * Restore a previously excluded KYC app back into the funnel.
     */
    public function restoreApp(int $appId): bool
    {
        $excluded = $this->store->load('kyc_excluded.json') ?? [];
        $filtered = array_values(array_filter($excluded, function($ex) use ($appId) {
            return (int)($ex['app_id'] ?? 0) !== $appId;
        }));
        $this->store->save('kyc_excluded.json', $filtered);
        return true;
    }
}
