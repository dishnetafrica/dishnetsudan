<?php
declare(strict_types=1);

/**
 * MagmaApiClient — DishNet Africa
 * Northbound REST API client for Facebook/Meta Magma Orchestrator (Orc8r).
 *
 * Auth: Magma Orc8r uses mutual TLS (client certificate + key).
 * Base URL: https://{orc8r-host}:9443/magma/v1
 *
 * Subscriber status values:
 *   ACTIVE    — subscriber can attach and use data
 *   INACTIVE  — subscriber blocked at core (suspended)
 *
 * Sub-profile: maps to Magma policy/QoS profile name (e.g. "monthly_20gb", "unlimited_4mbps")
 */
class MagmaApiClient
{
    private string $baseUrl;
    private string $networkId;
    private string $certPath;   // path to client cert PEM
    private string $keyPath;    // path to client key PEM
    private string $caPath;     // path to Orc8r CA cert (optional, for verification)
    private array  $lastError = [];
    private int    $timeout   = 20;
    private bool   $dryRunMode = false;
    private string $dataDir = '';

    public function __construct(array $cfg)
    {
        $this->baseUrl    = rtrim($cfg['magma_host']       ?? '', '/') . '/magma/v1';
        $this->networkId  = $cfg['magma_network_id']       ?? '';
        $this->certPath   = $cfg['magma_client_cert_path'] ?? '';
        $this->keyPath    = $cfg['magma_client_key_path']  ?? '';
        $this->caPath     = $cfg['magma_ca_cert_path']     ?? '';
        $this->dryRunMode = (bool)($cfg['dry_run_mode']    ?? false);
        $this->dataDir    = $cfg['data_dir'] ?? dirname(__DIR__) . '/data';
    }
    
    /**
     * Check if dry run mode is enabled
     */
    public function isDryRunMode(): bool
    {
        return $this->dryRunMode;
    }
    
    /**
     * Set dry run mode dynamically
     */
    public function setDryRunMode(bool $enabled): void
    {
        $this->dryRunMode = $enabled;
    }
    
    /**
     * Log dry run action (for debugging/audit)
     */
    private function logDryRun(string $action, array $data = []): array
    {
        $logEntry = [
            'status'    => 'dry_run_skipped',
            'action'    => $action,
            'data'      => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'message'   => "DRY RUN: {$action} skipped - no network call made",
        ];
        
        // Log to file for debugging
        $logFile = $this->dataDir . '/dry_run_magma_log.json';
        $existing = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        if (!is_array($existing)) $existing = [];
        $existing[] = $logEntry;
        // Keep last 500 entries
        if (count($existing) > 500) {
            $existing = array_slice($existing, -500);
        }
        @file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));
        
        return $logEntry;
    }

    public function isConfigured(): bool
    {
        return !empty($this->baseUrl)
            && !empty($this->networkId)
            && !empty($this->certPath)
            && !empty($this->keyPath);
    }

    public function getLastError(): array { return $this->lastError; }
    public function getNetworkId(): string { return $this->networkId; }

    /* ── Subscriber CRUD ─────────────────────────────────── */

    /** Get all subscribers (paginated, max 500 per call) */
    public function listSubscribers(int $page = 1, int $pageSize = 500): ?array
    {
        return $this->get("networks/{$this->networkId}/subscribers?page={$page}&page_size={$pageSize}&verbose=true");
    }

    /** Get single subscriber by IMSI (format: IMSI001010000000001) */
    public function getSubscriber(string $imsi): ?array
    {
        return $this->get("networks/{$this->networkId}/subscribers/{$imsi}");
    }

    /**
     * Provision a new subscriber on the Magma network.
     * $data keys: imsi, key (hex auth key), opc (hex OPc), sub_profile, msisdn
     */
    public function createSubscriber(array $data): ?array
    {
        // DRY RUN GUARD
        if ($this->dryRunMode) {
            return $this->logDryRun('createSubscriber', $data);
        }
        
        $imsi = $data['imsi'];
        $payload = [
            'id'   => $imsi,
            'lte'  => [
                'state'       => 'ACTIVE',
                'auth_algo'   => 'MILENAGE',
                'auth_key'    => $data['auth_key']    ?? '',
                'auth_opc'    => $data['auth_opc']    ?? '',
                'sub_profile' => $data['sub_profile'] ?? 'default',
            ],
            'active_apns'            => $data['active_apns'] ?? ['internet'],
            'active_base_names'      => [],
            'active_policies'        => [],
            'name'                   => $data['name'] ?? '',
            'monitoring'             => ['icmp_latency_stats' => []],
        ];
        return $this->post("networks/{$this->networkId}/subscribers", $payload);
    }

    /** Update subscriber — change profile, state, etc. */
    public function updateSubscriber(string $imsi, array $updates): ?array
    {
        // DRY RUN GUARD
        if ($this->dryRunMode) {
            return $this->logDryRun('updateSubscriber', ['imsi' => $imsi, 'updates' => $updates]);
        }
        
        return $this->put("networks/{$this->networkId}/subscribers/{$imsi}", $updates);
    }

    /** Suspend a subscriber (block from network) */
    public function suspendSubscriber(string $imsi): bool
    {
        // DRY RUN GUARD
        if ($this->dryRunMode) {
            $this->logDryRun('suspendSubscriber', ['imsi' => $imsi]);
            return true; // Pretend success
        }
        
        $sub = $this->getSubscriber($imsi);
        if (!$sub) return false;
        $sub['lte']['state'] = 'INACTIVE';
        $result = $this->put("networks/{$this->networkId}/subscribers/{$imsi}", $sub);
        return $result !== null;
    }

    /** Reactivate a suspended subscriber */
    public function activateSubscriber(string $imsi): bool
    {
        // DRY RUN GUARD
        if ($this->dryRunMode) {
            $this->logDryRun('activateSubscriber', ['imsi' => $imsi]);
            return true; // Pretend success
        }
        
        $sub = $this->getSubscriber($imsi);
        if (!$sub) return false;
        $sub['lte']['state'] = 'ACTIVE';
        $result = $this->put("networks/{$this->networkId}/subscribers/{$imsi}", $sub);
        return $result !== null;
    }

    /** Change subscriber's data plan (sub_profile) */
    public function changeProfile(string $imsi, string $newProfile): bool
    {
        // DRY RUN GUARD
        if ($this->dryRunMode) {
            $this->logDryRun('changeProfile', ['imsi' => $imsi, 'new_profile' => $newProfile]);
            return true; // Pretend success
        }
        
        $sub = $this->getSubscriber($imsi);
        if (!$sub) return false;
        $sub['lte']['sub_profile'] = $newProfile;
        $sub['lte']['state']       = 'ACTIVE'; // ensure active on plan change
        $result = $this->put("networks/{$this->networkId}/subscribers/{$imsi}", $sub);
        return $result !== null;
    }

    /** Delete a subscriber from the network */
    public function deleteSubscriber(string $imsi): bool
    {
        // DRY RUN GUARD
        if ($this->dryRunMode) {
            $this->logDryRun('deleteSubscriber', ['imsi' => $imsi]);
            return true; // Pretend success
        }
        
        $result = $this->delete("networks/{$this->networkId}/subscribers/{$imsi}");
        return $result !== null;
    }

    /* ── Usage / Monitoring ──────────────────────────────── */

    /** Get subscriber session state (includes data usage if available) */
    public function getSubscriberState(string $imsi): ?array
    {
        return $this->get("networks/{$this->networkId}/subscribers/{$imsi}/lte_subscription");
    }

    /** Get network-wide subscriber state map */
    public function getNetworkSubscriberState(): ?array
    {
        return $this->get("networks/{$this->networkId}/gateway_configs");
    }

    /* ── Network / Gateway info ──────────────────────────── */

    /** List all gateways (eNodeBs / AGWs) */
    public function listGateways(): ?array
    {
        return $this->get("networks/{$this->networkId}/gateways");
    }

    /** Get network summary */
    public function getNetwork(): ?array
    {
        return $this->get("networks/{$this->networkId}");
    }

    /** List subscriber policies */
    public function listPolicies(): ?array
    {
        return $this->get("networks/{$this->networkId}/policies/rules");
    }

    /** List base names (policy groups) */
    public function listBaseNames(): ?array
    {
        return $this->get("networks/{$this->networkId}/policies/base_names");
    }

    /* ── HTTP layer ──────────────────────────────────────── */

    private function get(string $path): ?array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $payload): ?array
    {
        return $this->request('POST', $path, $payload);
    }

    private function put(string $path, array $payload): ?array
    {
        return $this->request('PUT', $path, $payload);
    }

    private function delete(string $path): ?array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $payload = []): ?array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch  = curl_init();

        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            // mTLS — client certificate auth
            CURLOPT_SSLCERT        => $this->certPath,
            CURLOPT_SSLKEY         => $this->keyPath,
            CURLOPT_SSL_VERIFYPEER => !empty($this->caPath),
            CURLOPT_SSL_VERIFYHOST => !empty($this->caPath) ? 2 : 0,
        ];

        if (!empty($this->caPath)) {
            $opts[CURLOPT_CAINFO] = $this->caPath;
        }

        if ($payload) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($ch, $opts);

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $this->lastError = ['curl_error' => $curlErr, 'url' => $url];
            return null;
        }

        // 204 No Content = success with no body
        if ($httpCode === 204) return ['success' => true];

        $decoded = json_decode($raw, true);

        if ($httpCode >= 400) {
            $this->lastError = ['http_code' => $httpCode, 'response' => $decoded ?? $raw, 'url' => $url];
            return null;
        }

        return is_array($decoded) ? $decoded : ['raw' => $raw];
    }

    /* ── Data Usage / Session Stats ─────────────────────── */

    /**
     * Get per-subscriber data usage from Magma NMS directory records.
     * Magma stores UE session data in the directory service:
     *   GET /networks/{id}/directory_records  — last known session per IMSI
     * Each record has: location_history, identifiers (ipv4), etc.
     * Actual byte counts come from the subscriber's monitoring.icmp_latency_stats
     * and from the FeG/AGW reported session via subscriber verbose endpoint.
     */
    public function getSubscriberUsage(string $imsi): ?array
    {
        // verbose=true includes monitoring state with session data
        $sub = $this->get("networks/{$this->networkId}/subscribers/{$imsi}?verbose=true");
        if (!$sub) return null;

        // Extract session/usage fields from verbose subscriber data
        $lte = $sub['lte'] ?? [];
        $mon = $sub['monitoring'] ?? [];

        // Magma AGW reports cumulative bytes via subscriber state
        // Path: subscriber.state.subscriber_state.{imsi}.session_stats
        $state = $sub['state'] ?? [];

        return [
            'imsi'           => $imsi,
            'lte_state'      => $lte['state'] ?? 'UNKNOWN',
            'sub_profile'    => $lte['sub_profile'] ?? 'default',
            'monitoring'     => $mon,
            // Session state if available (populated when UE is attached)
            'session_state'  => $state,
            'avg_latency_ms' => $mon['icmp_latency_stats']['avg_latency_ms'] ?? null,
            'latency_valid'  => ($mon['icmp_latency_stats']['num_probes_sent'] ?? 0) > 0,
        ];
    }

    /**
     * Get all subscribers with verbose data in one call.
     * Returns keyed array: IMSI => subscriber data.
     * Use page_size carefully — for 2000+ subscribers pull in batches.
     */
    public function listSubscribersVerbose(int $page = 1, int $pageSize = 200): ?array
    {
        return $this->get(
            "networks/{$this->networkId}/subscribers" .
            "?page={$page}&page_size={$pageSize}&verbose=true"
        );
    }

    /**
     * Get gateway / eNodeB status and connected UE count.
     * Returns array of gateways with status, checked_in_recently, num_enodeb.
     */
    public function getGatewayStatus(): ?array
    {
        $gws = $this->get("networks/{$this->networkId}/gateways");
        if (!is_array($gws)) return null;

        $result = [];
        foreach ($gws as $gwId => $gw) {
            $status = $gw['status'] ?? [];
            $result[$gwId] = [
                'id'                  => $gwId,
                'name'                => $gw['name'] ?? $gwId,
                'description'         => $gw['description'] ?? '',
                'checked_in_recently' => $status['checked_in_recently'] ?? false,
                'meta'                => $status['meta'] ?? [],
                'platform_info'       => $status['platform_info'] ?? [],
                'hardware_id'         => $gw['device']['hardware_id'] ?? '',
                'enodeb_count'        => count($gw['connected_enodeb_serials'] ?? []),
                'enodebs'             => $gw['connected_enodeb_serials'] ?? [],
            ];
        }
        return $result;
    }

    /**
     * Get all eNodeBs (Baicells base stations) for the network.
     */
    public function listEnodebs(): ?array
    {
        return $this->get("networks/{$this->networkId}/enodebs");
    }

    /**
     * Get a single eNodeB status.
     */
    public function getEnodeb(string $enodebSerial): ?array
    {
        return $this->get("networks/{$this->networkId}/enodebs/{$enodebSerial}");
    }

    /**
     * Get network-wide subscriber directory records.
     * Useful for finding last known IP and location of each IMSI.
     */
    public function getDirectoryRecords(): ?array
    {
        return $this->get("networks/{$this->networkId}/directory_records");
    }

    /* ── Prometheus Usage Queries ─────────────────────────── */

    /**
     * Get subscriber data usage from Prometheus.
     * This is the PRIMARY method for tracking bytes consumed.
     * 
     * Query: sum(ue_reported_usage{IMSI="IMSI..."}) by (IMSI)
     * Returns: Total bytes consumed since subscriber was provisioned
     * 
     * @param string $imsi Raw IMSI without "IMSI" prefix (e.g., "460000000005238")
     * @return array|null ['imsi' => ..., 'bytes_used' => ..., 'bytes_gb' => ...]
     */
    public function getUsageFromPrometheus(string $imsi): ?array
    {
        // Prometheus query for subscriber usage
        // The IMSI in Magma is stored as "IMSI460000000005238"
        $query = 'sum(ue_reported_usage{IMSI="IMSI' . $imsi . '"}) by (IMSI)';
        $encodedQuery = urlencode($query);
        
        $result = $this->get("networks/{$this->networkId}/prometheus/query?query={$encodedQuery}");
        
        if (!$result || ($result['status'] ?? '') !== 'success') {
            $this->lastError = [
                'method' => 'getUsageFromPrometheus',
                'imsi' => $imsi,
                'response' => $result,
            ];
            return null;
        }
        
        // Parse Prometheus response format:
        // {"status":"success","data":{"resultType":"vector","result":[
        //   {"metric":{"IMSI":"IMSI460..."},"value":[1710423456.789,"12345678"]}
        // ]}}
        $data = $result['data']['result'][0] ?? null;
        
        if (!$data) {
            // No data found - subscriber might be new or never connected
            return [
                'imsi'        => $imsi,
                'bytes_used'  => 0,
                'bytes_gb'    => 0.0,
                'timestamp'   => time(),
                'queried_at'  => date('Y-m-d H:i:s'),
                'has_data'    => false,
            ];
        }
        
        // value[0] = unix timestamp, value[1] = bytes as string
        $bytesUsed = (int)($data['value'][1] ?? 0);
        
        return [
            'imsi'        => $imsi,
            'bytes_used'  => $bytesUsed,
            'bytes_gb'    => round($bytesUsed / 1073741824, 3), // 1024^3
            'bytes_mb'    => round($bytesUsed / 1048576, 2),    // 1024^2
            'timestamp'   => (int)($data['value'][0] ?? time()),
            'queried_at'  => date('Y-m-d H:i:s'),
            'has_data'    => true,
        ];
    }

    /**
     * Batch fetch usage for multiple subscribers in one Prometheus query.
     * More efficient than individual queries for bulk sync.
     * 
     * SCALABILITY: At 5000+ subscribers, a single regex query would timeout.
     * This method batches queries into groups of 100 IMSIs.
     * 
     * @param array $imsiList Array of raw IMSIs (without "IMSI" prefix)
     * @param int $batchSize Number of IMSIs per Prometheus query (default 150)
     * @return array Keyed by IMSI: ['460000000005238' => ['bytes_used' => ...], ...]
     */
    public function getBulkUsageFromPrometheus(array $imsiList, int $batchSize = 150): array
    {
        if (empty($imsiList)) return [];
        
        $allUsage = [];
        $now = date('Y-m-d H:i:s');
        
        // Split into batches to avoid Prometheus query length/timeout issues
        $batches = array_chunk($imsiList, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            // Build regex pattern for this batch
            // Query: sum(ue_reported_usage{IMSI=~"IMSI(460...|460...)"}) by (IMSI)
            $pattern = 'IMSI(' . implode('|', $batch) . ')';
            $query = 'sum(ue_reported_usage{IMSI=~"' . $pattern . '"}) by (IMSI)';
            $encodedQuery = urlencode($query);
            
            $result = $this->get("networks/{$this->networkId}/prometheus/query?query={$encodedQuery}");
            
            if (!$result || ($result['status'] ?? '') !== 'success') {
                $this->lastError = [
                    'method' => 'getBulkUsageFromPrometheus',
                    'batch_index' => $batchIndex,
                    'batch_size' => count($batch),
                    'total_imsis' => count($imsiList),
                    'response' => $result,
                ];
                // Continue with other batches even if one fails
                continue;
            }
            
            foreach ($result['data']['result'] ?? [] as $row) {
                // Extract IMSI from "IMSI460000000005238" format
                $fullImsi = $row['metric']['IMSI'] ?? '';
                $imsi = str_replace('IMSI', '', $fullImsi);
                
                if (!$imsi) continue;
                
                $bytesUsed = (int)($row['value'][1] ?? 0);
                
                $allUsage[$imsi] = [
                    'bytes_used'  => $bytesUsed,
                    'bytes_gb'    => round($bytesUsed / 1073741824, 3),
                    'bytes_mb'    => round($bytesUsed / 1048576, 2),
                    'timestamp'   => (int)($row['value'][0] ?? time()),
                    'queried_at'  => $now,
                ];
            }
            
            // Small delay between batches to avoid rate limiting
            if ($batchIndex < count($batches) - 1) {
                usleep(50000); // 50ms
            }
        }
        
        // Fill in zeros for IMSIs with no data
        foreach ($imsiList as $imsi) {
            if (!isset($allUsage[$imsi])) {
                $allUsage[$imsi] = [
                    'bytes_used'  => 0,
                    'bytes_gb'    => 0.0,
                    'bytes_mb'    => 0.0,
                    'timestamp'   => time(),
                    'queried_at'  => $now,
                ];
            }
        }
        
        return $allUsage;
    }

    /**
     * Test Prometheus connectivity by running a simple query.
     * @return array ['success' => bool, 'message' => string, 'query_time_ms' => int]
     */
    public function testPrometheusConnection(): array
    {
        $start = microtime(true);
        
        // Simple query: count all subscribers with any usage
        $query = urlencode('count(ue_reported_usage)');
        $result = $this->get("networks/{$this->networkId}/prometheus/query?query={$query}");
        
        $elapsed = round((microtime(true) - $start) * 1000);
        
        if (!$result) {
            return [
                'success' => false,
                'message' => 'Failed to connect: ' . json_encode($this->lastError),
                'query_time_ms' => $elapsed,
            ];
        }
        
        if (($result['status'] ?? '') !== 'success') {
            return [
                'success' => false,
                'message' => 'Query failed: ' . json_encode($result),
                'query_time_ms' => $elapsed,
            ];
        }
        
        $count = (int)($result['data']['result'][0]['value'][1] ?? 0);
        
        return [
            'success' => true,
            'message' => "Prometheus OK. {$count} subscribers with usage data.",
            'subscriber_count' => $count,
            'query_time_ms' => $elapsed,
        ];
    }

}
