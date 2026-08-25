<?php
// ═══════════════════════════════════════════════════════════════
// SPLYNX INTEGRATION
// ═══════════════════════════════════════════════════════════════


    // ── Fiber batch history ───────────────────────────────────────────────────
    if ($act === 'fiber_batches') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader') {
            $er2('Support Leader access required.', 403);
        }
        $batches = $store->load('fiber_batches.json') ?? [];
        // Return most recent 30, newest first
        $batches = array_reverse(array_slice($batches, -30));
        $ok2(['batches' => $batches]);
    }

    // ── Splynx: NOC Dashboard data ────────────────────────────────────────────
    if ($act === 'splynx_dashboard') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader') {
            $er2('Admin/Support Leader access required.', 403);
        }
        $cache   = $store->load('splynx_dashboard_cache.json') ?: [];
        $areas   = ($store->load('splynx_area_stats.json') ?: [])['areas'] ?? [];
        $filter  = $_GET['filter'] ?? 'open'; // default to open tickets
        $areaFilter = $_GET['area'] ?? '';
        $tickets = $splynxTickets->getJobs('all');
        $summary = $splynxTickets->getSummary();
        $areaDispatch = $splynxTickets->getAreaDispatch();

        // If fiber_pipeline wrote a fresher live count (within 2 hours), use it
        // This keeps NOC/My Jobs in sync with the live Splynx count
        $liveAt = $cache['live_updated_at'] ?? '';
        if ($liveAt && (time() - strtotime($liveAt)) < 7200 && isset($cache['live_pipeline_count'])) {
            $summary['total_pending'] = (int)$cache['live_pipeline_count'];
        }

        // Apply filter
        if ($filter === 'open') {
            $tickets = array_values(array_filter($tickets, function($t) {
                return empty($t['install_complete']);
            }));
        } elseif ($filter === 'completed') {
            $tickets = array_values(array_filter($tickets, function($t) {
                return !empty($t['install_complete']);
            }));
        }

        // Apply area filter
        if ($areaFilter) {
            $tickets = array_values(array_filter($tickets, function($t) use ($areaFilter) {
                return ($t['area'] ?? 'Unknown') === $areaFilter;
            }));
        }

        $ok2([
            'summary' => $summary,
            'areas'   => $areas,
            'area_dispatch' => $areaDispatch,
            'tickets' => array_slice(array_reverse($tickets), 0, 100), // most recent 100
            'cache_at' => $cache['updated_at'] ?? null,
            'splynx_configured' => $splynx->isConfigured(),
            'filter'  => $filter,
            'area_filter' => $areaFilter,
        ]);
    }

    // ── Splynx: Provision a single KYC app manually ──────────────────────────
    if ($act === 'splynx_provision' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        $appId = (int)($body['app_id'] ?? 0);
        if (!$appId) $er2('app_id required.', 422);
        $app = $store->findOne('kyc_applications.json', 'id', $appId);
        if (!$app) $er2("Application #{$appId} not found.", 404);
        if (!$splynx->isConfigured()) $er2('Splynx is not configured in Settings.', 503);
        $result = $splynxCusts->provisionFromKyc($app);
        $ok2($result);
    }

    // ── Splynx: Activate service after installation ──────────────────────────
    if ($act === 'splynx_activate' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        $serviceId = (int)($body['service_id'] ?? 0);
        if (!$serviceId) $er2('service_id required.', 422);
        if (!$splynx->isConfigured()) $er2('Splynx is not configured in Settings.', 503);
        $ok = $splynxCusts->activateService($serviceId);
        $ok ? $ok2(['activated' => true]) : $er2('Failed to activate service: ' . json_encode($splynx->getLastError()), 502);
    }

    // ── Splynx: Suspend service ──────────────────────────────────────────────
    if ($act === 'splynx_suspend' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        $serviceId = (int)($body['service_id'] ?? 0);
        if (!$serviceId) $er2('service_id required.', 422);
        if (!$splynx->isConfigured()) $er2('Splynx is not configured in Settings.', 503);
        $ok = $splynxCusts->suspendService($serviceId);
        $ok ? $ok2(['suspended' => true]) : $er2('Failed to suspend service: ' . json_encode($splynx->getLastError()), 502);
    }

    // ── Splynx: Run sync manually ────────────────────────────────────────────
    if ($act === 'splynx_run_sync' && $met === 'POST') {
        $isAdminOrLeader = ($me2['is_admin'] ?? false) || ($me2['role'] ?? '') === 'support_leader';
        if (!$isAdminOrLeader) $er2('Admin or Support Leader access required.', 403);
        if (!$splynx->isConfigured()) $er2('Splynx is not configured in Settings.', 503);
        $ticketResult  = $splynxTickets->syncTickets();
        $billingResult = $splynxCusts->syncBillingStatuses();
        // CRM Enrichment — populate address/phone/area from UCRM
        require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/CrmApiClient.php';
        $crmClient = CrmApiClient::fromUcrm($GLOBALS['_PLUGIN_ROOT'], $config);
        $enrichResult = ['enriched' => 0, 'skipped' => 0, 'failed' => 0];
        if ($crmClient->isConfigured()) {
            $enrichResult = $splynxTickets->enrichFromCrm($crmClient, 50);
        }
        $areas         = $splynxTickets->getAreaBreakdown();
        $store->save('splynx_area_stats.json', ['updated_at' => date('Y-m-d H:i:s'), 'areas' => $areas]);
        $summary       = $splynxTickets->getSummary();
        $store->save('splynx_dashboard_cache.json', array_merge($summary, ['updated_at' => date('Y-m-d H:i:s')]));
        $ok2(['tickets' => $ticketResult, 'billing' => $billingResult, 'enrichment' => $enrichResult, 'at' => date('Y-m-d H:i:s')]);
    }

    // ── Splynx: Connection test ──────────────────────────────────────────────
    if ($act === 'splynx_test') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        $ok2($splynx->testConnection());
    }

    // ════════════════════════════════════════════════════════════════════════
    // fiber_mapping_diag — v4.21.50 mapping reconnaissance
    // ════════════════════════════════════════════════════════════════════════
    // Read-only inspection of the Fiber Finance plugin's mapping/cache files
    // for ONE specific CRM customer (or a list). Used to diagnose why a
    // hybrid customer isn't seeing the fiber card.
    //
    // For each customer ID, returns:
    //   • mapping_row    — their row in fiber_customer_mapping.json (if any)
    //   • usage_row      — their row in fiber_usage_cache.json (if any)
    //   • crm_attribute  — their splynx Id custom attribute on UCRM services
    //   • verdict        — one-line summary of what's happening
    //
    // Plus global stats:
    //   • mapping_meta — how many rows are in mapping, how many have
    //                    crm_customer_id set vs empty
    //   • usage_meta   — fiber_usage_meta.json (last run, fetched count)
    //
    // Usage:
    //   GET /public.php?page=api&action=fiber_mapping_diag
    //     → diagnoses currently logged-in user's account
    //   GET /public.php?page=api&action=fiber_mapping_diag&client_ids=661,1264
    //     → diagnoses these specific CRM client IDs
    if ($act === 'fiber_mapping_diag') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);

        // Resolve which client_ids to diagnose
        $clientIdsParam = trim((string)($_GET['client_ids'] ?? ''));
        $clientIds = [];
        if ($clientIdsParam !== '') {
            foreach (explode(',', $clientIdsParam) as $cid) {
                $cid = (int)trim($cid);
                if ($cid > 0) $clientIds[] = $cid;
            }
        }
        // If none specified, default to the logged-in admin's own client_id
        // (only useful when admin IS the test customer; otherwise pass list)
        if (empty($clientIds)) {
            $clientIds = [(int)$rid];
        }

        // Locate Fiber Finance data dir via cross-plugin path
        $pluginsBase = dirname(__DIR__, 3);
        $ffDataDir   = $pluginsBase . '/dishnet-fiber-finance/data';

        // Load the FF JSONs (read-only)
        $loadJson = function (string $path): array {
            if (!is_file($path)) return ['__missing' => true];
            $raw = @file_get_contents($path);
            $data = json_decode((string)$raw, true);
            return is_array($data) ? $data : ['__parse_error' => true];
        };

        $mapping     = $loadJson($ffDataDir . '/fiber_customer_mapping.json');
        $usageCache  = $loadJson($ffDataDir . '/fiber_usage_cache.json');
        $usageMeta   = $loadJson($ffDataDir . '/fiber_usage_meta.json');
        $services    = $loadJson($ffDataDir . '/fiber_services.json');

        // Build mapping stats
        $mappingStats = [
            'total_rows'          => 0,
            'with_crm_id'         => 0,
            'without_crm_id'      => 0,
            'sample_unmapped'     => [],
        ];
        if (is_array($mapping) && empty($mapping['__missing']) && empty($mapping['__parse_error'])) {
            foreach ($mapping as $m) {
                if (!is_array($m)) continue;
                $mappingStats['total_rows']++;
                $cid = (string)($m['crm_customer_id'] ?? '');
                if ($cid !== '') {
                    $mappingStats['with_crm_id']++;
                } else {
                    $mappingStats['without_crm_id']++;
                    if (count($mappingStats['sample_unmapped']) < 5) {
                        $mappingStats['sample_unmapped'][] = [
                            'splynx_id'    => $m['splynx_customer_id'] ?? '',
                            'splynx_login' => $m['splynx_login'] ?? '',
                            'splynx_name'  => $m['splynx_name'] ?? '',
                        ];
                    }
                }
            }
        }

        // Per-customer diagnosis
        $perCustomer = [];
        foreach ($clientIds as $cid) {
            $diag = [
                'crm_client_id'   => $cid,
                'mapping_row'     => null,
                'usage_row'       => null,
                'crm_client_info' => null,
                'verdict'         => '',
            ];

            // Find in mapping (joined by crm_customer_id)
            if (is_array($mapping)) {
                foreach ($mapping as $m) {
                    if (!is_array($m)) continue;
                    if ((int)($m['crm_customer_id'] ?? 0) === $cid) {
                        $diag['mapping_row'] = $m;
                        break;
                    }
                }
            }

            // Find in usage cache (also joined by crm_customer_id)
            if (is_array($usageCache)) {
                foreach ($usageCache as $row) {
                    if (!is_array($row)) continue;
                    if ((int)($row['crm_customer_id'] ?? 0) === $cid) {
                        $diag['usage_row'] = $row;
                        break;
                    }
                }
            }

            // Pull CRM client info from cache (so we can see name + custom attrs)
            try {
                foreach ($store->load('ucrm_clients_cache.json') ?? [] as $c) {
                    if ((int)($c['id'] ?? 0) !== $cid) continue;
                    // Walk services for splynx Id custom attribute
                    $splynxAttrs = [];
                    foreach (($c['services'] ?? []) as $svc) {
                        $attrs = $svc['attributes'] ?? [];
                        foreach ((array)$attrs as $attr) {
                            $key = strtolower((string)($attr['key'] ?? $attr['name'] ?? ''));
                            $val = (string)($attr['value'] ?? '');
                            if (strpos($key, 'splynx') !== false || strpos($key, 'splynx_id') !== false) {
                                $splynxAttrs[] = [
                                    'service_id' => $svc['id'] ?? null,
                                    'plan'       => $svc['servicePlanName'] ?? '',
                                    'attr_key'   => $attr['key'] ?? $attr['name'] ?? '',
                                    'attr_value' => $val,
                                ];
                            }
                        }
                    }
                    $diag['crm_client_info'] = [
                        'id'            => $c['id'] ?? null,
                        'name'          => $c['firstName'] ?? '' . ' ' . ($c['lastName'] ?? ''),
                        'username'      => $c['username'] ?? '',
                        'company'       => $c['companyName'] ?? '',
                        'plans_count'   => count($c['services'] ?? []),
                        'splynx_attrs'  => $splynxAttrs,
                    ];
                    break;
                }
            } catch (\Throwable $e) {}

            // Look up matching service in fiber_services.json (for cross-check)
            $diag['fiber_services'] = [];
            if (is_array($services)) {
                foreach ($services as $svc) {
                    if (!is_array($svc)) continue;
                    if ((int)($svc['crm_customer_id'] ?? 0) === $cid) {
                        $diag['fiber_services'][] = [
                            'service_id'    => $svc['service_id'] ?? '',
                            'splynx_id'     => $svc['splynx_id'] ?? '',
                            'plan_name'     => $svc['plan_name'] ?? '',
                            'status'        => $svc['status'] ?? '',
                            'splynx_status' => $svc['splynx_status'] ?? '',
                        ];
                    }
                }
            }

            // Verdict: one-line summary
            if (empty($diag['mapping_row']) && empty($diag['usage_row'])) {
                $diag['verdict'] = '❌ NOT FOUND in fiber_customer_mapping.json or fiber_usage_cache.json. '
                    . 'Either (a) customer has no Splynx account, OR (b) has Splynx but not auto-linked to CRM. '
                    . 'Check splynx_attrs in crm_client_info — if non-empty, mapping is missing despite explicit link.';
            } elseif (!empty($diag['mapping_row']) && empty($diag['usage_row'])) {
                $diag['verdict'] = '⚠️  Found in mapping but no usage row yet. '
                    . 'Either FF cron has not run since mapping was created, OR usage fetch failed.';
            } elseif (empty($diag['mapping_row']) && !empty($diag['usage_row'])) {
                $diag['verdict'] = '🤔 Has usage row but no mapping row — unusual. Possible orphaned cache.';
            } else {
                $diag['verdict'] = '✅ Fully mapped. Usage row present. Hybrid customer pill should appear.';
            }

            $perCustomer[] = $diag;
        }

        // ── Tail FF sync log (last 50 lines) ──────────────────────────────
        $logTail = [];
        $logFile = $ffDataDir . '/fiber_sync.log';
        if (is_file($logFile)) {
            $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                $logTail = array_slice($lines, -50);
            }
        }

        // ── Summarize fiber_config.json (no secrets — just status fields) ─
        $configSummary = ['__missing' => true];
        $configFile = $ffDataDir . '/fiber_config.json';
        if (is_file($configFile)) {
            $cfgRaw = json_decode((string)@file_get_contents($configFile), true);
            if (is_array($cfgRaw)) {
                $configSummary = [
                    'auto_sync_enabled'     => $cfgRaw['auto_sync_enabled'] ?? null,
                    'sync_interval_minutes' => $cfgRaw['sync_interval_minutes'] ?? null,
                    'last_auto_sync'        => $cfgRaw['last_auto_sync'] ?? null,
                    'splynx_configured'     => !empty($cfgRaw['splynx_url']) && !empty($cfgRaw['splynx_key']),
                    'crm_id_prefix'         => $cfgRaw['crm_id_prefix'] ?? '(default FTTH)',
                ];
                if (!empty($cfgRaw['last_auto_sync'])) {
                    $lastTs = strtotime($cfgRaw['last_auto_sync']);
                    $intervalSec = (int)($cfgRaw['sync_interval_minutes'] ?? 60) * 60;
                    $nextTs = $lastTs + $intervalSec;
                    $configSummary['next_due_at']      = date('Y-m-d H:i:s', $nextTs);
                    $configSummary['minutes_until_next'] = max(0, (int)round(($nextTs - time()) / 60));
                    $configSummary['is_overdue']         = (time() >= $nextTs);
                }
            }
        }

        $ok2([
            'ff_data_dir'   => $ffDataDir,
            'mapping_stats' => $mappingStats,
            'usage_meta'    => $usageMeta,
            'per_customer'  => $perCustomer,
            'log_tail'      => $logTail,
            'config_summary'=> $configSummary,
        ]);
    }

    // ── Splynx: Debug ticket pull (diagnostic) ──────────────────────────────
    if ($act === 'splynx_debug_tickets') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        if (!$splynx->isConfigured()) $er2('Splynx not configured.', 503);

        $probes = [];
        $paths = [
            // Core paths used by plugin
            'api/2.0/admin/support/tickets',                       // getTickets() - CONFIRMED WORKING
            'api/2.0/admin/customers/customer',                    // getCustomer() - CONFIRMED WORKING
            // Internet services - multiple variants (405 = needs enabling in Splynx API permissions)
            'api/2.0/admin/customers/customer-internet-services',  // primary path
            'api/2.0/admin/internet/ip-assignment',                // fallback to check API access level
            'api/2.0/admin/customers/customer-services',           // alternate naming
        ];

        foreach ($paths as $path) {
            $result = $splynx->get($path, ['limit' => 2]);
            $err    = $splynx->getLastError();
            $code   = $err['http_code'] ?? ($result !== null ? 200 : '?');
            $fix    = null;
            if ((int)$code === 405 && str_contains($path, 'customer-internet-services')) {
                $fix = 'ENDPOINT DISABLED: Go to Splynx -> Administration -> API -> Roles -> edit your API role -> enable GET permission for "customer-internet-services". This is required for Task 6b fiber auto-detection.';
            } elseif ((int)$code === 403) {
                $fix = 'PERMISSION DENIED: The Splynx API token does not have permission for this endpoint. Check Administration -> API -> Roles in Splynx.';
            }
            $probes[$path] = [
                'status'  => $result !== null ? 'OK (' . (is_array($result) ? count($result) : 'non-array') . ')' : 'FAIL ' . $code,
                'error'   => $result === null ? ($err['response']['error']['message'] ?? ($err['response']['message'] ?? json_encode($err))) : null,
                'fix'     => $fix,
                'sample'  => $result !== null ? array_keys(is_array($result) && isset($result[0]) ? $result[0] : (is_array($result) ? $result : [])) : null,
            ];
        }

        // If customer-internet-services is 405, test the mrr_total proxy fallback
        $mrrProxyStatus = null;
        if (isset($probes['api/2.0/admin/customers/customer-internet-services']) &&
            str_contains($probes['api/2.0/admin/customers/customer-internet-services']['status'], '405')) {
            // Get a sample customer to see if mrr_total is populated
            $sampleCustomers = $splynx->get('api/2.0/admin/customers/customer', ['limit' => 3, 'status' => 1]);
            if (is_array($sampleCustomers) && !empty($sampleCustomers)) {
                $mrrSamples = [];
                foreach (array_slice($sampleCustomers, 0, 3) as $sc) {
                    $mrrSamples[] = 'Customer #' . ($sc['id'] ?? '?') . ': mrr_total=' . ($sc['mrr_total'] ?? 'N/A');
                }
                $mrrProxyStatus = 'mrr_total fallback ACTIVE -- samples: ' . implode(', ', $mrrSamples);
            } else {
                $mrrProxyStatus = 'Could not fetch sample customers to verify mrr_total';
            }
        }

        $ok2([
            'splynx_url'       => $config['splynx_url'] ?? '(not set)',
            'probes'           => $probes,
            'mrr_proxy_status' => $mrrProxyStatus,
        ]);
    }

    // ── Splynx: Area Dispatch view (open tickets grouped by area) ──────────
    if ($act === 'splynx_area_dispatch') {
        $isAdminOrLeader = ($me2['is_admin'] ?? false) || ($me2['role'] ?? '') === 'support_leader';
        if (!$isAdminOrLeader) $er2('Admin or Support Leader access required.', 403);
        $areaDispatch = $splynxTickets->getAreaDispatch();
        $ok2(['areas' => $areaDispatch]);
    }

    // ── Splynx: Batch assign engineer to area ────────────────────────────────
    if ($act === 'splynx_batch_assign_area' && $met === 'POST') {
        $isAdminOrLeader = ($me2['is_admin'] ?? false) || ($me2['role'] ?? '') === 'support_leader';
        if (!$isAdminOrLeader) $er2('Admin or Support Leader access required.', 403);
        $area     = trim($body['area'] ?? '');
        $engName  = trim($body['engineer_name'] ?? '');
        $engId    = trim($body['engineer_id'] ?? '');
        if (!$area || !$engName) $er2('area and engineer_name required.', 422);
        $count = $splynxTickets->batchAssignArea($area, $engName, $engId);
        $ok2(['assigned' => $count, 'area' => $area, 'engineer' => $engName]);
    }

    // ── Splynx: CRM Enrich tickets manually ──────────────────────────────────
    if ($act === 'splynx_crm_enrich' && $met === 'POST') {
        $isAdminOrLeader = ($me2['is_admin'] ?? false) || ($me2['role'] ?? '') === 'support_leader';
        if (!$isAdminOrLeader) $er2('Admin or Support Leader access required.', 403);
        require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/CrmApiClient.php';
        $crmClient = CrmApiClient::fromUcrm($GLOBALS['_PLUGIN_ROOT'], $config);
        if (!$crmClient->isConfigured()) $er2('CRM not configured.', 503);
        // Force cache rebuild by deleting old cache
        $store->save('crm_enrich_cache.json', []);
        $result = $splynxTickets->enrichFromCrm($crmClient, 300);
        // Rebuild area stats after enrichment
        if ($result['enriched'] > 0) {
            $areas = $splynxTickets->getAreaBreakdown();
            $store->save('splynx_area_stats.json', ['updated_at' => date('Y-m-d H:i:s'), 'areas' => $areas]);
        }
        $ok2($result);
    }

    // ── Splynx: List available tariffs ──────────────────────────────────────
    if ($act === 'splynx_tariffs') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        if (!$splynx->isConfigured()) $er2('Splynx is not configured.', 503);
        $ok2(['tariffs' => $splynxCusts->getAvailableTariffs()]);
    }

