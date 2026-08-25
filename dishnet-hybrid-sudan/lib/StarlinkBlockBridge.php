<?php
/**
 * StarlinkBlockBridge — server-side bridge to dishnet-data-report's test_block
 * endpoints. Replaces the broken StarlinkBlockService.suspend()/restore() chain
 * for webhook-driven flows from v4.21.29 onward.
 *
 * Why this exists:
 *   StarlinkBlockService was built around reading sl_kits.json + wifi_router_map.json
 *   directly and orchestrating per-MAC pauses via gRPC. In production, sl_kits.json
 *   is incomplete and wifi_router_map.json has different entries for different
 *   plugin versions. Data Report's dr_wifi_test_block does all this correctly
 *   in one call, has a dish-reachability probe, idempotency check, and proper
 *   audit logging. The Block Manager UI uses it. The webhook handlers should too.
 *
 * What this does:
 *   suspendClient(clientId, freshClient?, triggeredBy) →
 *     1. VIP guard (same isVipClient check StarlinkBlockService uses)
 *     2. Resolve client → KIT serials (from UCRM service.name regex; falls back
 *        to sl_kits.json if needed). Same logic as the audit endpoint.
 *     3. For each KIT, GET dr_wifi_lookup_by_kit → router_id
 *     4. POST dr_wifi_test_block {router_id, mode:'pause_only', by:'webhook'}
 *
 *   restoreClient(clientId, triggeredBy) →
 *     1. Resolve client → KIT serials
 *     2. Read data-report's wifi_test_block_state.json directly (server-side,
 *        same JSON file) to find which of this client's KITs are currently
 *        paused — only call unblock for those.
 *     3. For each, POST dr_wifi_test_unblock {router_id, by:'webhook'}
 *
 *   Both methods are idempotent: suspend skips already-paused, restore skips
 *   not-paused. Returns a summary array suitable for whLog().
 *
 * Auth note:
 *   Server-to-server cURL from PHP runs without the admin's PHPSESSID cookie.
 *   Data Report's admin endpoints (test_block, test_unblock, lookup_by_kit)
 *   require admin context — so we either need an internal API key or to
 *   enable a server-to-server auth path. Approach used here: write a simple
 *   shared-secret header that data-report can validate. If config doesn't
 *   have the secret, falls back to attempting unauthenticated call (works
 *   if data-report isn't gating these actions strictly, which is the case
 *   today since they're whitelisted as adminActions and data-report only
 *   checks "is there a session" not "is the session admin").
 */

declare(strict_types=1);

class StarlinkBlockBridge
{
    /** @var \PDO */ private $pdo;
    /** @var mixed */ private $store;
    /** @var array */ private $config;
    /** @var string */ private $dataDir;
    /** @var mixed */ private $notify;
    /** @var string */ private $drBaseUrl = '';

    const HTTP_TIMEOUT_SEC = 25;
    const KIT_REGEX = '/\bKIT[A-Z0-9]{8,}\b/i';

    public function __construct(\PDO $pdo, $store, array $config, string $dataDir, $notify = null)
    {
        $this->pdo     = $pdo;
        $this->store   = $store;
        $this->config  = $config;
        $this->dataDir = $dataDir;
        $this->notify  = $notify;
        $this->drBaseUrl = $this->resolveDataReportUrl();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PUBLIC: SUSPEND
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Suspend a client's Starlink dish(es) via data-report's test_block.
     *
     * @param int $clientId
     * @param array|null $freshClient  Pass UCRM /clients/{id} response if caller has it (webhook does)
     * @param string $triggeredBy      e.g. 'webhook:service.suspend', 'webhook:postpone_revert'
     * @return array { ok, routers_processed, routers_failed, skipped_reason, attempts: [{router_id, kit, ok, error?}] }
     */
    public function suspendClient(int $clientId, ?array $freshClient = null, string $triggeredBy = 'webhook'): array
    {
        // 1. VIP guard — reuse existing logic from StarlinkBlockService for parity
        try {
            if ($this->isVipClient($clientId, $freshClient)) {
                $vipResult = ['ok' => true, 'skipped_reason' => 'vip', 'routers_processed' => 0, 'routers_failed' => 0, 'attempts' => []];
                $this->logEvent('suspend', $clientId, $triggeredBy, $vipResult);
                return $vipResult;
            }
        } catch (\Throwable $e) {
            // Don't let VIP-check failure block the whole flow; log and continue
            $this->log("VIP guard threw: {$e->getMessage()} — proceeding (fail-open)");
        }

        // 2. Resolve KIT serials for this client (same logic as audit endpoint)
        $kits = $this->resolveClientKits($clientId);
        if (empty($kits)) {
            $noKitResult = [
                'ok' => true,
                'skipped_reason' => 'no_kits',
                'routers_processed' => 0,
                'routers_failed' => 0,
                'attempts' => [],
                'resolve_diag' => $this->lastResolveDiag, // v4.21.35: surface why no kits
            ];
            $this->logEvent('suspend', $clientId, $triggeredBy, $noKitResult);
            return $noKitResult;
        }

        // 3. For each KIT: lookup router via data-report, then call test_block
        $attempts = [];
        $processed = 0;
        $failed    = 0;
        foreach ($kits as $kit) {
            $lookup = $this->drGet('dr_wifi_lookup_by_kit', ['kit_serial' => $kit]);
            if (!$lookup['ok'] || empty($lookup['json']['ok']) || empty($lookup['json']['found'])) {
                $attempts[] = ['kit' => $kit, 'ok' => false, 'error' => 'router_not_in_map'];
                $failed++;
                continue;
            }
            $routerId = (string)($lookup['json']['router_id'] ?? '');
            if ($routerId === '') {
                $attempts[] = ['kit' => $kit, 'ok' => false, 'error' => 'empty_router_id'];
                $failed++;
                continue;
            }

            $mode = (string)($this->config['starlink_block_default_mode'] ?? 'rename_only');
            if (!in_array($mode, ['pause_only', 'rename_only', 'full'], true)) $mode = 'rename_only';

            $blockResp = $this->drPost('dr_wifi_test_block', [
                'router_id' => $routerId,
                'mode'      => $mode,
                'by'        => $triggeredBy,
            ]);
            if ($blockResp['ok'] && !empty($blockResp['json']['ok'])) {
                $attempts[] = ['kit' => $kit, 'router_id' => $routerId, 'ok' => true];
                $processed++;
            } else {
                $err = (string)($blockResp['json']['error'] ?? $blockResp['error'] ?? 'unknown');
                // Idempotency: data-report says "already in test-block state" → not a failure
                if (stripos($err, 'already in test-block') !== false) {
                    $attempts[] = ['kit' => $kit, 'router_id' => $routerId, 'ok' => true, 'note' => 'already_paused'];
                    $processed++;
                } else {
                    $attempts[] = ['kit' => $kit, 'router_id' => $routerId, 'ok' => false, 'error' => $err];
                    $failed++;
                }
            }
        }

        $result = [
            'ok'                 => $failed === 0,
            'routers_processed'  => $processed,
            'routers_failed'     => $failed,
            'skipped_reason'     => '',
            'attempts'           => $attempts,
        ];
        $this->logEvent('suspend', $clientId, $triggeredBy, $result);
        return $result;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // PUBLIC: RESTORE
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Restore (unpause) a client's Starlink dish(es) if any are currently
     * in data-report's test_block state. Idempotent — no-op for clients
     * who have nothing paused.
     *
     * Approach: read data-report's wifi_test_block_state.json directly
     * (server-side, sibling plugin), find routers for this client's KITs,
     * call test_unblock for any that are currently paused. This avoids
     * the "no row in sl_suspension_state" pitfall the StarlinkBlockService
     * version had.
     *
     * @return array { ok, routers_restored, routers_failed, attempts: [...] }
     */
    public function restoreClient(int $clientId, string $triggeredBy = 'webhook'): array
    {
        $kits = $this->resolveClientKits($clientId);
        if (empty($kits)) {
            $r = [
                'ok' => true,
                'routers_restored' => 0,
                'routers_failed' => 0,
                'attempts' => [],
                'note' => 'no_kits',
                'resolve_diag' => $this->lastResolveDiag, // v4.21.35
            ];
            $this->logEvent('restore', $clientId, $triggeredBy, $r);
            return $r;
        }

        // Build a kit_serial → router_id map from sibling data-report files
        // wifi_test_block_state.json is keyed on router_id like "Router-XXXXX..."
        // wifi_router_map.json is keyed on raw routerId (no "Router-" prefix)
        $blockedRouters = $this->readDataReportFile('wifi_test_block_state.json');
        if (empty($blockedRouters)) {
            $r = ['ok' => true, 'routers_restored' => 0, 'routers_failed' => 0, 'attempts' => [], 'note' => 'nothing_paused'];
            $this->logEvent('restore', $clientId, $triggeredBy, $r);
            return $r;
        }
        $routerMap = $this->readDataReportFile('wifi_router_map.json');

        // Walk currently-paused routers and check if any belong to this client
        $myRouters = []; // [router_id_full, kit]
        $kitSet = [];
        foreach ($kits as $k) $kitSet[strtoupper(trim($k))] = true;
        foreach ($blockedRouters as $routerIdFull => $stateRow) {
            $rawId = (strpos((string)$routerIdFull, 'Router-') === 0)
                ? substr((string)$routerIdFull, 7) : (string)$routerIdFull;
            $rmEntry = $routerMap[$rawId] ?? null;
            if (!is_array($rmEntry)) continue;
            $kit = strtoupper(trim((string)($rmEntry['kit_serial'] ?? '')));
            if ($kit !== '' && isset($kitSet[$kit])) {
                $myRouters[] = ['router_id' => (string)$routerIdFull, 'kit' => $kit];
            }
        }

        if (empty($myRouters)) {
            $r = ['ok' => true, 'routers_restored' => 0, 'routers_failed' => 0, 'attempts' => [], 'note' => 'client_not_in_paused'];
            $this->logEvent('restore', $clientId, $triggeredBy, $r);
            return $r;
        }

        // For each paused router, call test_unblock
        $attempts  = [];
        $restored  = 0;
        $failed    = 0;
        foreach ($myRouters as $r) {
            $resp = $this->drPost('dr_wifi_test_unblock', [
                'router_id' => $r['router_id'],
                'by'        => $triggeredBy,
            ]);
            if ($resp['ok'] && !empty($resp['json']['ok'])) {
                $attempts[] = ['router_id' => $r['router_id'], 'kit' => $r['kit'], 'ok' => true];
                $restored++;
            } else {
                $err = (string)($resp['json']['error'] ?? $resp['error'] ?? 'unknown');
                $attempts[] = ['router_id' => $r['router_id'], 'kit' => $r['kit'], 'ok' => false, 'error' => $err];
                $failed++;
            }
        }

        $result = [
            'ok'                => $failed === 0,
            'routers_restored'  => $restored,
            'routers_failed'    => $failed,
            'attempts'          => $attempts,
        ];
        $this->logEvent('restore', $clientId, $triggeredBy, $result);
        return $result;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // VIP GUARD (delegate to StarlinkBlockService for behavior parity)
    // ═════════════════════════════════════════════════════════════════════════

    public function isVipClient(int $clientId, ?array $freshClient = null): bool
    {
        // Reuse StarlinkBlockService's isVipClient if available — keeps the
        // VIP semantics in one place. Otherwise replicate the core check.
        if (class_exists('StarlinkBlockService') || @class_exists('\\StarlinkBlockService')) {
            try {
                $svc = new \StarlinkBlockService($this->pdo, $this->store, $this->config, $this->dataDir, $this->notify);
                return $svc->isVipClient($clientId, $freshClient);
            } catch (\Throwable $e) { /* fall through to local impl */ }
        }
        // Local fallback
        $vipTagId   = (int)($this->config['starlink_block_vip_tag_id']   ?? 84);
        $vipTagName = (string)($this->config['starlink_block_vip_tag_name'] ?? 'NO_AUTO_BLOCK');
        $explicit   = $this->config['starlink_block_vip_clients'] ?? '';
        if (is_string($explicit) && $explicit !== '') {
            foreach (preg_split('/[,\s]+/', $explicit) as $v) {
                if ((int)trim($v) === $clientId) return true;
            }
        } elseif (is_array($explicit)) {
            foreach ($explicit as $v) if ((int)$v === $clientId) return true;
        }
        $tags = $freshClient['attributes'] ?? $freshClient['tags'] ?? $freshClient['clientTags'] ?? [];
        if (!is_array($tags)) return false;
        foreach ($tags as $t) {
            if ((int)($t['id'] ?? 0) === $vipTagId) return true;
            if ((string)($t['name'] ?? '') === $vipTagName) return true;
        }
        return false;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // KIT RESOLUTION (mirrors audit endpoint multi-source approach)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Resolve client_id → KIT serials. Two sources:
     *   (A) sl_kits.json (Starlink Finance plugin)
     *   (B) UCRM /clients/{id}/services regex on service.name
     * Union, deduped, uppercase. Returns empty array if neither yields anything.
     */
    private function resolveClientKits(int $clientId): array
    {
        $found = [];
        $diag  = [
            'src_a_path'    => null,
            'src_a_present' => false,
            'src_a_entries' => 0,
            'src_a_matched' => 0,
            'src_b_attempted' => false,
            'src_b_url'     => '',
            'src_b_appkey_len' => 0,
            'src_b_services_count' => 0,
            'src_b_kits_extracted' => 0,
            'src_b_error'   => '',
        ];

        // Source A: sl_kits.json
        $kitsJson = null;
        foreach ([
            dirname(__DIR__, 2) . '/dishnet-starlink-finance/data/sl_kits.json',
            dirname(__DIR__, 1) . '/../dishnet-starlink-finance/data/sl_kits.json',
        ] as $p) {
            if (file_exists($p)) {
                $diag['src_a_path']    = $p;
                $diag['src_a_present'] = true;
                $raw = @file_get_contents($p);
                if ($raw !== false) {
                    $kitsJson = json_decode((string)$raw, true);
                    if (is_array($kitsJson)) {
                        $diag['src_a_entries'] = count($kitsJson);
                        break;
                    }
                }
            }
        }
        if (is_array($kitsJson)) {
            foreach ($kitsJson as $key => $val) {
                if (!is_array($val)) continue;
                $cid = (int)(
                    $val['client_id'] ?? $val['crm_client_id'] ?? $val['ucrm_client_id']
                    ?? $val['clientId'] ?? $val['crmClientId'] ?? $val['customer_id'] ?? 0
                );
                if ($cid !== $clientId) continue;
                $ks = (string)(
                    $val['kit_serial'] ?? $val['kit'] ?? $val['serial']
                    ?? $val['kitSerial'] ?? (is_string($key) ? $key : '')
                );
                if ($ks !== '') {
                    $found[strtoupper(trim($ks))] = true;
                    $diag['src_a_matched']++;
                }
            }
        }

        // Source B: UCRM service.name regex (fallback for clients not in sl_kits.json)
        // v4.21.35: extensive diagnostics so we can see exactly why this fails.
        if (empty($found)) {
            $diag['src_b_attempted'] = true;
            try {
                if (!class_exists('CrmApiClient')) {
                    @require_once __DIR__ . '/CrmApiClient.php';
                }
                if (!class_exists('CrmApiClient')) {
                    $diag['src_b_error'] = 'CrmApiClient class not loadable';
                } else {
                    $pluginRoot = dirname(__DIR__);
                    $crm = \CrmApiClient::fromUcrm($pluginRoot, is_array($this->config) ? $this->config : []);
                    $diag['src_b_url']        = $crm->getBaseUrl();
                    $diag['src_b_appkey_len'] = strlen($crm->getAppKey());
                    if ($crm->getBaseUrl() === '' || $crm->getAppKey() === '') {
                        $diag['src_b_error'] = 'CrmApiClient unconfigured (base_url or app_key empty after fromUcrm)';
                    } else {
                        // v4.21.36: try the same endpoint patterns the audit uses,
                        // since clients/{id}/services may not be available on this
                        // UCRM build. Try in order:
                        //   1. clients/services?clientId=X    (some UCRM versions)
                        //   2. clients/{clientId}             (single client; .services subkey)
                        //   3. clients/{clientId}/services    (REST sub-resource)
                        // Whichever returns an array wins. Diagnostic captures which.
                        $svcs = null;
                        $diag['src_b_endpoint_tried'] = '';

                        // Strategy 1: collection endpoint with clientId filter
                        $r1 = $crm->get("clients/services?clientId={$clientId}&limit=100");
                        if (is_array($r1)) {
                            $svcs = $r1;
                            $diag['src_b_endpoint_tried'] = "clients/services?clientId={$clientId}";
                        }

                        // Strategy 2: single client (services may be embedded)
                        if ($svcs === null) {
                            $r2 = $crm->get("clients/{$clientId}");
                            if (is_array($r2)) {
                                if (!empty($r2['services']) && is_array($r2['services'])) {
                                    $svcs = $r2['services'];
                                    $diag['src_b_endpoint_tried'] = "clients/{$clientId} (services subkey)";
                                } elseif (!empty($r2['attributes']) && is_array($r2['attributes'])) {
                                    // Sometimes service info is in attributes
                                    $svcs = $r2['attributes'];
                                    $diag['src_b_endpoint_tried'] = "clients/{$clientId} (attributes)";
                                } else {
                                    // Got the client back; try the sub-resource as a last resort
                                    $diag['src_b_endpoint_tried'] = "clients/{$clientId} (no services subkey)";
                                }
                            }
                        }

                        // Strategy 3: explicit sub-resource (original)
                        if ($svcs === null) {
                            $r3 = $crm->get("clients/{$clientId}/services");
                            if (is_array($r3)) {
                                $svcs = $r3;
                                $diag['src_b_endpoint_tried'] = "clients/{$clientId}/services";
                            }
                        }

                        if (!is_array($svcs)) {
                            $diag['src_b_error'] = 'UCRM API returned non-array on all strategies (likely auth or 404)';
                        } else {
                            $diag['src_b_services_count'] = count($svcs);
                            // The client name often appears in the service name; the KIT regex
                            // matches on service name OR any string field in the row that
                            // contains "KITxxxx".
                            foreach ($svcs as $s) {
                                if (!is_array($s)) continue;
                                // Try multiple fields where the KIT might be embedded
                                $candidates = [];
                                foreach (['name', 'serviceName', 'note', 'tariffName'] as $f) {
                                    if (!empty($s[$f])) $candidates[] = (string)$s[$f];
                                }
                                foreach ($candidates as $haystack) {
                                    if (preg_match_all(self::KIT_REGEX, $haystack, $m)) {
                                        foreach ($m[0] as $kit) {
                                            $found[strtoupper(trim($kit))] = true;
                                            $diag['src_b_kits_extracted']++;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $diag['src_b_error'] = 'exception: ' . $e->getMessage();
                $this->log("KIT regex source threw: {$e->getMessage()}");
            }
        }

        // Stash diagnostics for the most recent call so suspend/restore can
        // surface them when no_kits happens.
        $this->lastResolveDiag = $diag;

        return array_keys($found);
    }

    /** @var array */ private $lastResolveDiag = [];
    public function getLastResolveDiag(): array { return $this->lastResolveDiag; }

    // ═════════════════════════════════════════════════════════════════════════
    // DATA REPORT URL RESOLUTION + HTTP HELPERS
    // ═════════════════════════════════════════════════════════════════════════

    private function resolveDataReportUrl(): string
    {
        $override = trim((string)($this->config['data_report_plugin_url'] ?? ''));
        if ($override !== '') return rtrim($override, '/');

        // Try ucrm.json sibling — same approach StarlinkBlockService uses
        $ucrmJson = null;
        foreach ([$this->dataDir . '/../ucrm.json', $this->dataDir . '/ucrm.json'] as $p) {
            if (file_exists($p)) {
                $ucrmJson = json_decode((string)@file_get_contents($p), true);
                if (is_array($ucrmJson)) break;
            }
        }
        if (!is_array($ucrmJson)) return '';

        $base = (string)($ucrmJson['ucrmPublicUrl'] ?? '');
        if ($base === '') return '';
        $base = preg_replace('#/api/v\d+\.\d+/?$#', '', $base);
        $base = rtrim($base, '/');
        if (substr($base, -4) === '/crm') $base = substr($base, 0, -4);
        $base = rtrim($base, '/') . '/crm';
        return $base . '/_plugins/dishnet-data-report/public.php';
    }

    private function drGet(string $action, array $params = []): array
    {
        if ($this->drBaseUrl === '') {
            return ['ok' => false, 'error' => 'data-report URL unresolved', 'json' => null];
        }
        $params['action'] = $action;
        $url = $this->drBaseUrl . '?' . http_build_query($params);
        return $this->doCurl($url, null);
    }

    private function drPost(string $action, array $payload): array
    {
        if ($this->drBaseUrl === '') {
            return ['ok' => false, 'error' => 'data-report URL unresolved', 'json' => null];
        }
        $url = $this->drBaseUrl . '?action=' . urlencode($action);
        return $this->doCurl($url, json_encode($payload));
    }

    private function doCurl(string $url, ?string $postBody): array
    {
        $authHeader = $this->internalAuthHeader();

        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($authHeader !== '') $headers[] = $authHeader;

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT_SEC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($postBody !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $postBody;
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) return ['ok' => false, 'error' => "curl: {$err}", 'http_code' => $code, 'json' => null];
        $j = json_decode((string)$body, true);
        return [
            'ok'        => ($code >= 200 && $code < 300),
            'json'      => is_array($j) ? $j : null,
            'http_code' => $code,
            'raw'       => (string)$body,
        ];
    }

    /**
     * Resolve the shared internal-auth secret used for plugin-to-plugin
     * cURL. Stored in a sibling file under /data/.../plugins/_dishnet_shared/
     * so both Hybrid and data-report can read+write it. If the file doesn't
     * exist, we generate one on first use.
     *
     * Returns: "X-DishNet-Internal-Auth: <secret>" or empty string if we
     *          can't establish a secret (in which case the cURL will hit
     *          data-report's normal session gate and fail — webhook still
     *          succeeds, just no auto-block happens).
     */
    private function internalAuthHeader(): string
    {
        // Path candidates for the shared secret file. Prefer a sibling
        // directory both plugins can reach via dirname() jumps.
        $candidates = [
            dirname(__DIR__, 2) . '/_dishnet_shared/internal_auth.json',
            dirname(__DIR__, 1) . '/../_dishnet_shared/internal_auth.json',
        ];
        $file = $candidates[0]; // canonical write path

        $secret = '';
        foreach ($candidates as $p) {
            if (file_exists($p)) {
                $j = @json_decode((string)@file_get_contents($p), true);
                if (is_array($j) && !empty($j['secret'])) {
                    $secret = (string)$j['secret'];
                    break;
                }
            }
        }
        if ($secret === '') {
            // Generate, write atomically, return new secret
            try {
                @mkdir(dirname($file), 0755, true);
                $secret = bin2hex(random_bytes(24));
                $payload = json_encode(['secret' => $secret, 'created_at' => date('c'), 'created_by' => 'StarlinkBlockBridge']);
                @file_put_contents($file, $payload, LOCK_EX);
                @chmod($file, 0640);
            } catch (\Throwable $_) {
                return '';
            }
        }
        return $secret !== '' ? ("X-DishNet-Internal-Auth: " . $secret) : '';
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SIBLING PLUGIN FILE READS
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Read a JSON file from dishnet-data-report's data/ directory.
     * Returns array or empty array on any failure.
     */
    private function readDataReportFile(string $filename): array
    {
        foreach ([
            dirname(__DIR__, 2) . '/dishnet-data-report/data/' . $filename,
            dirname(__DIR__, 1) . '/../dishnet-data-report/data/' . $filename,
        ] as $p) {
            if (file_exists($p)) {
                $raw = @file_get_contents($p);
                if ($raw !== false) {
                    $j = json_decode((string)$raw, true);
                    if (is_array($j)) return $j;
                }
            }
        }
        return [];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // LOGGING
    // ═════════════════════════════════════════════════════════════════════════

    private function log(string $msg): void
    {
        // Plain-text log for free-form notes (VIP guard errors, etc.).
        try {
            $logFile = rtrim($this->dataDir, '/') . '/sl_block_bridge.log';
            $line = '[' . date('c') . '] ' . $msg . "\n";
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $_) { /* swallow */ }
    }

    /**
     * Append a structured event to sl_block_bridge_events.json (JSONL).
     * One line per event so we can tail/grep/query without parsing the
     * whole file. Events are the audit trail for what the bridge actually
     * did — viewable in the Bridge Activity Log UI tab.
     *
     * Schema:
     *   {ts, kind, client_id, triggered_by, ok, summary, attempts}
     * kind = 'suspend' | 'restore'
     */
    public function logEvent(string $kind, int $clientId, string $triggeredBy, array $result): void
    {
        try {
            $logFile = rtrim($this->dataDir, '/') . '/sl_block_bridge_events.json';
            $event = [
                'ts'           => date('c'),
                'kind'         => $kind,
                'client_id'    => $clientId,
                'triggered_by' => $triggeredBy,
                'ok'           => !empty($result['ok']),
                'routers_processed' => $result['routers_processed'] ?? $result['routers_restored'] ?? 0,
                'routers_failed'    => $result['routers_failed'] ?? 0,
                'skipped_reason'    => $result['skipped_reason'] ?? '',
                'note'              => $result['note'] ?? '',
                'attempts'          => $result['attempts'] ?? [],
            ];
            $line = json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

            // Cap file size: rotate if >2MB (keep last ~5000 events).
            if (@filesize($logFile) > 2 * 1024 * 1024) {
                $this->rotateEventLog($logFile);
            }
        } catch (\Throwable $_) { /* swallow — never break webhook on log fail */ }
    }

    private function rotateEventLog(string $logFile): void
    {
        try {
            $lines = @file($logFile);
            if (!is_array($lines)) return;
            $keep  = array_slice($lines, -2500); // keep last 2500
            @file_put_contents($logFile, implode('', $keep), LOCK_EX);
        } catch (\Throwable $_) {}
    }

    /**
     * Read recent bridge events for the UI / audit endpoint.
     * Returns latest-first, capped at $limit (default 200).
     */
    public function readRecentEvents(int $limit = 200): array
    {
        try {
            $logFile = rtrim($this->dataDir, '/') . '/sl_block_bridge_events.json';
            if (!file_exists($logFile)) return [];
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) return [];
            $lines = array_reverse($lines);
            $out = [];
            $count = 0;
            foreach ($lines as $ln) {
                if ($count >= $limit) break;
                $j = json_decode($ln, true);
                if (is_array($j)) { $out[] = $j; $count++; }
            }
            return $out;
        } catch (\Throwable $_) { return []; }
    }
}
