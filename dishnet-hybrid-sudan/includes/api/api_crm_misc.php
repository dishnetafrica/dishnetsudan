<?php
// ═══════════════════════════════════════════════════════════════
// CRM SYNC / SURVEYS / SIGNATURES
// ═══════════════════════════════════════════════════════════════


    // ── Save customer signature for a job ────────────────────────────────────

    if ($act === 'save_job_signature' && $met === 'POST') {
        $jobId    = (int)($body['job_id']     ?? 0);
        $sigData  = trim($body['signature']   ?? ''); // base64 PNG data URL
        $sigName  = trim($body['signer_name'] ?? '');
        if (!$jobId || !$sigData) $er2('job_id and signature required.', 422);

        $signatures = $store->load('job_signatures.json') ?? [];
        $idx = array_search($jobId, array_column($signatures, 'job_id'));
        $record = [
            'job_id'      => $jobId,
            'signer_name' => $sigName,
            'signed_by'   => $me2['name'] ?? 'Engineer',
            'signed_at'   => date('Y-m-d H:i:s'),
            'signature'   => $sigData,
        ];
        if ($idx !== false) $signatures[$idx] = $record;
        else $signatures[] = $record;
        $store->save('job_signatures.json', $signatures);

        // Log note to UCRM client
        if (!empty($body['crm_client_id'])) {
            $crm->post('clients/' . (int)$body['crm_client_id'] . '/client-logs', [
                'message' => "✅ Customer signature captured on-site by {$record['signed_by']} at {$record['signed_at']}. Signer: {$sigName}.",
            ]);
        }
        $ok2(['saved' => true, 'signed_at' => $record['signed_at']]);
    }

    if ($act === 'scheduling_jobs_all') {
        if (!($me2['is_admin'] ?? false) && ($me2['role'] ?? '') !== 'support_leader') {
            $er2('Support Leader access required.', 403);
        }
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);
        $date = trim($_GET['date'] ?? date('Y-m-d'));
        $jobs = $crm->get("scheduling/jobs?limit=100&status[]=open&status[]=pending&dateFrom={$date}&dateTo={$date}");
        if ($jobs === null) $er2('CRM API error: '.json_encode($crm->getLastError()), 502);
        $ok2(['jobs' => is_array($jobs) ? $jobs : [], 'date' => $date]);
    }


    // ── Site survey: save survey result for a job ─────────────────────────────
    if ($act === 'save_survey_result' && $met === 'POST') {
        $jobId    = (int)($body['job_id']    ?? 0);
        $clientId = (int)($body['client_id'] ?? 0);
        if (!$jobId) $er2('job_id required.', 422);

        $survey = [
            'job_id'           => $jobId,
            'client_id'        => $clientId,
            'surveyed_by'      => $me2['name'] ?? 'Support',
            'surveyed_at'      => date('Y-m-d H:i:s'),
            // Electric poles
            'poles_available'  => $body['poles_available']  ?? 'unknown', // yes|no|partial
            'poles_count'      => (int)($body['poles_count'] ?? 0),
            'poles_note'       => trim($body['poles_note']   ?? ''),
            // Fiber cable
            'fiber_available'  => $body['fiber_available']  ?? 'unknown', // yes|no|partial
            'fiber_distance_m' => (int)($body['fiber_distance_m'] ?? 0),
            'fiber_note'       => trim($body['fiber_note']   ?? ''),
            // Router installation
            'router_location'  => trim($body['router_location']  ?? ''),  // free text: wall/ceiling/pole/etc
            'router_note'      => trim($body['router_note']      ?? ''),
            // Access points
            'ap_needed'        => (bool)($body['ap_needed']       ?? false),
            'ap_count'         => (int)($body['ap_count']         ?? 0),
            'ap_locations'     => trim($body['ap_locations']      ?? ''),
            // Extra hardware
            'extra_hardware'   => trim($body['extra_hardware']    ?? ''),  // free text: switches, PoE, cabling etc
            // Overall
            'feasibility'      => $body['feasibility']   ?? 'feasible', // feasible|not_feasible|conditional
            'general_notes'    => trim($body['general_notes']    ?? ''),
        ];

        // Save locally
        $surveys = $store->load('site_surveys.json') ?? [];
        // Replace existing survey for same job if exists
        $idx = array_search($jobId, array_column($surveys, 'job_id'));
        if ($idx !== false) $surveys[$idx] = $survey;
        else $surveys[] = $survey;
        $store->save('site_surveys.json', $surveys);

        // Optionally append survey summary to UCRM client note
        if ($clientId && $crm->isConfigured() && !empty($body['update_crm_note'])) {
            $feasLabel = ['feasible'=>'✅ Feasible','not_feasible'=>'❌ Not Feasible','conditional'=>'⚠ Conditional'][$survey['feasibility']] ?? $survey['feasibility'];
            $note = "\n\n--- SITE SURVEY {$survey['surveyed_at']} by {$survey['surveyed_by']} ---\n"
                . "Feasibility: {$feasLabel}\n"
                . "Poles: {$survey['poles_available']}" . ($survey['poles_count'] ? " ({$survey['poles_count']})" : "") . ($survey['poles_note'] ? " - {$survey['poles_note']}" : "") . "\n"
                . "Fiber: {$survey['fiber_available']}" . ($survey['fiber_distance_m'] ? " ~{$survey['fiber_distance_m']}m" : "") . ($survey['fiber_note'] ? " - {$survey['fiber_note']}" : "") . "\n"
                . "Router: " . ($survey['router_location'] ?: 'TBD') . ($survey['router_note'] ? " ({$survey['router_note']})" : "") . "\n"
                . ($survey['ap_needed'] ? "Access Points: {$survey['ap_count']} needed - {$survey['ap_locations']}\n" : "Access Points: Not needed\n")
                . ($survey['extra_hardware'] ? "Extra Hardware: {$survey['extra_hardware']}\n" : '')
                . ($survey['general_notes'] ? "Notes: {$survey['general_notes']}\n" : '');
            $client = $crm->get("clients/{$clientId}");
            if ($client) {
                $crm->patch("clients/{$clientId}", ['note' => ($client['note'] ?? '') . $note]);
            }
        }

        $ok2(['saved' => true, 'survey' => $survey]);
    }

    // ── GPS update: capture browser location and PATCH UCRM client ────────────
    if ($act === 'update_client_gps' && $met === 'POST') {
        $clientId = (int)($body['client_id'] ?? 0);
        $lat      = (float)($body['lat'] ?? 0);
        $lon      = (float)($body['lon'] ?? 0);
        if (!$clientId) $er2('client_id required.', 422);
        if (!$lat || !$lon) $er2('lat and lon required.', 422);
        if (!$crm->isConfigured()) $er2('CRM not configured.', 503);

        // Verify the requesting user has a job assigned for this client (security)
        $ucrmUserId = (int)($me2['ucrm_user_id'] ?? 0);
        $isAdmin    = $me2['is_admin'] ?? false;
        $isLeader   = ($me2['role'] ?? '') === 'support_leader';
        if (!$isAdmin && !$isLeader && $ucrmUserId) {
            // Check any open job for this client is assigned to this user
            $jobs = $crm->get("scheduling/jobs?clientId={$clientId}&assigneeId={$ucrmUserId}&limit=5") ?? [];
            if (empty($jobs)) $er2('Access denied — no job assigned to you for this client.', 403);
        }

        $result = $crm->patch("clients/{$clientId}", ['gpsLat' => $lat, 'gpsLon' => $lon]);
        if ($result === null) $er2('CRM GPS update failed: ' . json_encode($crm->getLastError()), 502);

        // Also log it
        $store->append('activity_log.json', [
            'id'         => $store->nextId('activity_log.json'),
            'event'      => 'gps_updated',
            'actor'      => $me2['name'] ?? 'Support',
            'detail'     => "GPS updated for client #{$clientId}: {$lat}, {$lon}",
            'ref_id'     => $clientId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $ok2(['updated' => true, 'lat' => $lat, 'lon' => $lon, 'client_id' => $clientId]);
    }

    // ── Get existing survey for a job ──────────────────────────────────────────
    if ($act === 'get_survey') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        if (!$jobId) $er2('job_id required.', 422);
        $surveys = $store->load('site_surveys.json') ?? [];
        $found   = null;
        foreach ($surveys as $s) { if ((int)($s['job_id'] ?? 0) === $jobId) { $found = $s; break; } }
        $ok2(['survey' => $found]);
    }


    // ── UCRM Pull Sync — import data FROM UCRM into local cache ──────────────
    // GET ?page=api&action=ucrm_pull_sync&entity=clients|plans|services|invoices
    if ($act === 'ucrm_pull_sync') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        if (!$crm->isConfigured()) $er2('UCRM API not configured. Set crm_base_url and crm_auth_token in Settings.', 503);

        $entity = trim($_GET['entity'] ?? 'clients');
        $page   = max(1, (int)($_GET['pg'] ?? 1));
        $limit  = 100;
        $offset = ($page - 1) * $limit;

        set_time_limit(60);

        switch ($entity) {

            case 'clients':
                $data = $crm->get("clients?limit={$limit}&offset={$offset}");
                if ($data === null) $er2('UCRM error: ' . json_encode($crm->getLastError()), 502);
                $items = is_array($data) ? $data : [];
                // Merge into local cache (keyed by CRM ID)
                $cache = $store->load('ucrm_clients_cache.json') ?? [];
                $map   = [];
                foreach ($cache as $c) { if (!empty($c['id'])) $map[(int)$c['id']] = $c; }
                foreach ($items as $c) { if (!empty($c['id'])) $map[(int)$c['id']] = $c; }
                $all = array_values($map);
                $store->save('ucrm_clients_cache.json', $all);
                // Rebuild global search index so all features see fresh data immediately
                _buildClientSearchIndex($store, $all);
                $ok2(['entity'=>'clients','page'=>$page,'fetched'=>count($items),'total_cached'=>count($map),'has_more'=>count($items)===$limit]);
                break;

            case 'plans':
                $data = $crm->get("service-plans?limit=200");
                if ($data === null) $er2('UCRM error: ' . json_encode($crm->getLastError()), 502);
                $items = is_array($data) ? $data : [];
                $store->save('ucrm_plans_cache.json', $items);
                // Plans changed — rebuild index so plan names are fresh
                _buildClientSearchIndex($store, $store->load('ucrm_clients_cache.json') ?? []);
                $ok2(['entity'=>'plans','fetched'=>count($items),'total_cached'=>count($items),'has_more'=>false]);
                break;

            case 'services':
                // BULK endpoint — one call returns ALL services across ALL clients.
                // Matches Starlink Finance: apiGet($apiUrl, $appKey, 'clients/services', ['limit'=>5000])
                // Old per-client loop (clients/{id}/services × 2000 clients) caused 2000 errors.
                set_time_limit(55);
                $data = $crm->get("clients/services?limit={$limit}&offset={$offset}");
                if ($data === null) {
                    $data = $crm->get('clients/services'); // fallback: no pagination params
                }
                if (!is_array($data)) {
                    $ok2(['entity'=>'services','page'=>$page,'fetched'=>0,'total_cached'=>0,
                          'has_more'=>false,'clients_processed'=>0,'errors'=>1,
                          'error'=>json_encode($crm->getLastError())]);
                    break;
                }
                // API returns {items:[...],totalCount:N} or flat array
                $items      = isset($data['items']) ? $data['items'] : array_values(array_filter($data, 'is_array'));
                $totalCount = (int)($data['totalCount'] ?? count($items));
                $fetched    = 0;
                $cache      = $store->load('ucrm_services_cache.json') ?? [];
                $map        = [];
                foreach ($cache as $s) { if (!empty($s['id'])) $map[(int)$s['id']] = $s; }
                foreach ($items as $s) {
                    if (!empty($s['id'])) { $map[(int)$s['id']] = $s; $fetched++; }
                }
                $store->save('ucrm_services_cache.json', array_values($map));
                $hasMore = ($offset + $fetched < $totalCount) && ($fetched === $limit);
                $ok2(['entity'=>'services','page'=>$page,'fetched'=>$fetched,
                      'total_cached'=>count($map),'has_more'=>$hasMore,
                      'clients_processed'=>$fetched,'errors'=>0]);
                break;

            case 'invoices':
                // Correct UCRM v2 path — no statuses[] filter at bulk level, use status param
                // Try without status filter first (most compatible), then filter locally
                $data = $crm->get("invoices?limit={$limit}&offset={$offset}");
                if ($data === null) {
                    // Fallback: try with singular status param
                    $data = $crm->get("invoices?limit={$limit}&offset={$offset}&status=1");
                }
                if ($data === null) $er2('UCRM error: ' . json_encode($crm->getLastError()), 502);
                $items = is_array($data) ? $data : [];
                // Filter to unpaid/partial locally (status 1=unpaid, 2=partial, 3=paid)
                $unpaid = array_filter($items, fn($inv) => in_array($inv['status'] ?? 0, [1, 2]));
                $cache  = $store->load('ucrm_invoices_cache.json') ?? [];
                $map    = [];
                foreach ($cache as $inv) { if (!empty($inv['id'])) $map[(int)$inv['id']] = $inv; }
                foreach ($unpaid as $inv) { if (!empty($inv['id'])) $map[(int)$inv['id']] = $inv; }
                $store->save('ucrm_invoices_cache.json', array_values($map));
                $ok2(['entity'=>'invoices','page'=>$page,'fetched'=>count($items),'unpaid_cached'=>count($unpaid),'total_cached'=>count($map),'has_more'=>count($items)===$limit]);
                break;

            default:
                $er2('Unknown entity: '.$entity, 422);
        }
    }

    // ── Live CRM connection test ───────────────────────────────────────────────
    if ($act === 'test_crm_connection') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        $result = $crm->testConnection(__DIR__, $config);
        $ok2($result);
    }

    // ── Test Quote API — verify admin token can access billing endpoints ──
    if ($act === 'test_quote_api') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);

        $quoteToken = trim($config['crm_auth_token'] ?? '');
        $quoteUrl   = trim($config['crm_base_url'] ?? '');

        if ($quoteToken === '') {
            $er2('No Admin Auth Token configured. Go to UCRM → My Profile → API tokens → Create → paste in Settings above.', 422);
        }

        // Use admin token
        $quoteCrm = new CrmApiClient(
            $quoteUrl !== '' ? $quoteUrl : rtrim($crm->getBaseUrl(), '/'),
            $quoteToken,
            'x-auth-token'
        );

        // Test 1: Can we reach the API with this token?
        $versionTest = $quoteCrm->get('version');
        if ($versionTest === null) {
            $lastErr = $quoteCrm->getLastError();
            $httpCode = $lastErr['http_code'] ?? 0;
            if ($httpCode === 401 || $httpCode === 403) {
                $er2("Token rejected (HTTP {$httpCode}). The token may be expired or not an admin token. Create a fresh one in UCRM → My Profile → API tokens.", 422);
            }
            $er2('CRM connection failed with admin token: ' . json_encode($lastErr), 500);
        }

        // Test 2: Can we access a client? (proves token works for API calls)
        $clientTest = $quoteCrm->get('clients?limit=1');
        if ($clientTest === null) {
            $lastErr = $quoteCrm->getLastError();
            $er2('Token works for version but not clients API: ' . json_encode($lastErr), 500);
        }

        $crmVersion = $versionTest['version'] ?? ($versionTest['raw'] ?? 'unknown');
        $ok2([
            'quote_api_working' => true,
            'crm_version'       => $crmVersion,
            'auth_method'       => 'x-auth-token (admin)',
            'base_url'          => $quoteCrm->getBaseUrl(),
        ], "Admin token verified! CRM version: {$crmVersion}. Quotes will work on next KYC.");
    }

    // ── UCRM Pull Sync status ─────────────────────────────────────────────────
    // ── Debug: inspect cached client structure ──────────────────────────────
    if ($act === 'debug_client_sample') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only', 403);
        $clients = $store->load('ucrm_clients_cache.json');
        $sample = array_slice($clients, 0, 3);
        $ok2(['total' => count($clients), 'sample' => $sample, 'first_keys' => $clients ? array_keys($clients[0]) : []]);
    }

    if ($act === 'ucrm_sync_status') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        $clientsCache  = $store->load('ucrm_clients_cache.json')  ?? [];
        $plansCache    = $store->load('ucrm_plans_cache.json')     ?? [];
        $servicesCache = $store->load('ucrm_services_cache.json')  ?? [];
        $invoicesCache = $store->load('ucrm_invoices_cache.json')  ?? [];
        $syncMeta      = $store->load('ucrm_sync_meta.json')       ?? [];
        $ok2([
            'clients'  => count($clientsCache),
            'plans'    => count($plansCache),
            'services' => count($servicesCache),
            'invoices' => count($invoicesCache),
            'last_sync'=> $syncMeta['last_sync'] ?? null,
            'crm_ok'   => $crm->isConfigured(),
        ]);
    }

    // ── Save sync meta timestamp ──────────────────────────────────────────────
    if ($act === 'ucrm_sync_done' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin access required.', 403);
        $store->save('ucrm_sync_meta.json', ['last_sync' => date('Y-m-d H:i:s'), 'by' => $me2['name'] ?? 'Admin']);

        // ── BUILD SALES PERSON INDEX from freshly-pulled ucrm_clients_cache ──
        // Runs immediately after manual "Pull All Data" completes
        $ATTR_SP  = 1; $ATTR_PRI = 36; $ATTR_PKG = 41; $ATTR_REF = 43;
        $allCached = $store->load('ucrm_clients_cache.json') ?? [];
        $spIdx = []; $spSum = [];

        foreach ($allCached as $cc) {
            $ccId    = (int)($cc['id'] ?? 0); if (!$ccId) continue;
            $isLead  = (bool)($cc['isLead'] ?? false);
            $fname   = trim($cc['firstName'] ?? '');
            $lname   = trim($cc['lastName']  ?? '');
            $ccName  = trim("$fname $lname") ?: trim($cc['companyName'] ?? $cc['username'] ?? '');
            $ccBal   = (float)($cc['accountBalance'] ?? 0);
            $ccReg   = substr($cc['registrationDate'] ?? $cc['createdAt'] ?? '', 0, 10);
            $ccUser  = trim($cc['username'] ?? '');
            $ccPhone = '';
            foreach ($cc['contacts'] ?? [] as $ct) {
                if (!$ccPhone && !empty($ct['phone'])) { $ccPhone = $ct['phone']; break; }
            }
            if (!$ccPhone) $ccPhone = trim($cc['phone'] ?? '');

            $attrs = [];
            foreach ($cc['attributes'] ?? ($cc['customAttributes'] ?? []) as $at) {
                $attrs[(int)($at['customAttributeId'] ?? 0)] = trim($at['value'] ?? '');
            }
            $sp  = $attrs[$ATTR_SP]  ?? ''; if (!$sp) continue;
            $pkg = $attrs[$ATTR_PKG] ?? '';
            $pri = $attrs[$ATTR_PRI] ?? '';
            $ref = $attrs[$ATTR_REF] ?? '';

            if (!isset($spIdx[$sp])) $spIdx[$sp] = [];
            $spIdx[$sp][$ccId] = [
                'id'       => $ccId, 'name'     => $ccName, 'username' => $ccUser,
                'phone'    => $ccPhone, 'is_lead'  => $isLead, 'balance'  => $ccBal,
                'package'  => $pkg, 'priority' => $pri, 'ref'      => $ref, 'reg_date' => $ccReg,
            ];

            if (!isset($spSum[$sp])) $spSum[$sp] = ['count'=>0,'active'=>0,'leads'=>0,'packages'=>[],'months'=>[]];
            $spSum[$sp]['count']++;
            if ($isLead) $spSum[$sp]['leads']++; else $spSum[$sp]['active']++;
            if ($pkg) $spSum[$sp]['packages'][$pkg] = ($spSum[$sp]['packages'][$pkg] ?? 0) + 1;
            $mo6 = substr($ccReg, 0, 7);
            if ($mo6) $spSum[$sp]['months'][$mo6] = ($spSum[$sp]['months'][$mo6] ?? 0) + 1;
        }

        // Sort each SP's clients newest first
        $spIdxFlat = [];
        foreach ($spIdx as $spKey => $spClients) {
            $arr = array_values($spClients);
            usort($arr, fn($a,$b) => strcmp($b['reg_date']??'', $a['reg_date']??''));
            $spIdxFlat[$spKey] = $arr;
        }
        arsort($spSum);

        $store->save('sp_client_index.json', $spIdxFlat);
        $store->save('sp_summary.json',      $spSum);

        $totalAttrib = array_sum(array_map('count', $spIdx));
        $ok2([
            'saved'       => true,
            'sp_count'    => count($spIdx),
            'attributed'  => $totalAttrib,
            'total_cached'=> count($allCached),
            'message'     => "Sales index built: " . count($spIdx) . " sales persons, {$totalAttrib} attributed clients from " . count($allCached) . " total.",
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    // LTE MODULE — API ACTIONS
    // ══════════════════════════════════════════════════════════════════

    // ── LTE table bootstrap — runs on every LTE API call ─────────────
    // Ensures lte_* tables exist with PROPER columns (not blob format).
    // SqliteStore::ensureTable() may have created these as (id,data) blobs
    // before the SQL migrations could run — this detects and fixes that.
    if (strncmp($act, 'lte_', 4) === 0 || strncmp($act, 'magma_', 6) === 0) {
        if (empty($GLOBALS['_lteTablesChecked'])) {
            $GLOBALS['_lteTablesChecked'] = true;
            $pluginRootBoot   = $GLOBALS['_PLUGIN_ROOT'] ?? dirname(__DIR__);
            $lteBootMigrations = [
                '020_lte_subscribers.sql', '021_lte_sims.sql',
                '022_lte_subscriptions.sql', '023_lte_renewals.sql',
                '024_lte_packages.sql',
            ];
            // Expected column per table — if missing, table has wrong schema
            $_lteExpectedCols = [
                'lte_subscribers' => 'name', 'lte_sims' => 'auth_key',
                'lte_subscriptions' => 'subscriber_id', 'lte_renewals' => 'subscriber_id',
                'lte_packages' => 'price',
            ];
            $pdo3 = $store->getPdo();
            foreach ($lteBootMigrations as $_mf) {
                $_mp = $pluginRootBoot . '/migrations/' . $_mf;
                if (!file_exists($_mp)) continue;
                // Extract table name from filename (e.g. 020_lte_subscribers.sql → lte_subscribers)
                $_tbl = preg_replace('/^\d+_(.+)\.sql$/', '$1', $_mf);
                $_mustCol = $_lteExpectedCols[$_tbl] ?? '';
                // Check if existing table has blob schema (id+data only)
                if ($_mustCol) {
                    try {
                        $_cols = $pdo3->query("PRAGMA table_info({$_tbl})")->fetchAll(\PDO::FETCH_COLUMN, 1);
                        if (!empty($_cols) && !in_array($_mustCol, $_cols)) {
                            // Table exists but has wrong schema — drop it so migration can recreate
                            $pdo3->exec("DROP TABLE IF EXISTS {$_tbl}");
                            error_log("[LTE boot] Dropped blob-format table {$_tbl} — will recreate with proper columns");
                        }
                    } catch (\Throwable $_ce) { /* table doesn't exist yet — fine */ }
                }
                try { $pdo3->exec(file_get_contents($_mp)); } catch (\Throwable $_me) { /* already exists — fine */ }
            }
            unset($pdo3, $_mf, $_mp, $_me, $lteBootMigrations, $pluginRootBoot, $_lteExpectedCols, $_tbl, $_mustCol, $_cols, $_ce);
        }
    }

    // ── LTE Dashboard stats ──────────────────────────────────────────
    if ($act === 'lte_stats') {
        if (!($can('lte_dashboard') || $can('lte_subscribers'))) $er2('Access denied.', 403);
        try {
            $ok2($lte->getDashboardStats());
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'no such table') !== false) { $ok2(['total_subscribers'=>0,'active'=>0,'suspended'=>0,'magma_connected'=>false]); }
            else $er2('lte_stats error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
        }
    }

    // ── LTE Subscriber list ──────────────────────────────────────────
    if ($act === 'lte_subscribers') {
        if (!$can('lte_subscribers')) $er2('Access denied.', 403);
        $filters = [
            'status'    => $_GET['status']    ?? '',
            'search'    => $_GET['search']    ?? '',
            'agent_id'  => $isAdmin ? ($_GET['agent_id'] ?? '') : $retailerId,
            'page'      => isset($_GET['page']) ? (int)$_GET['page'] : 1,
            'per_page'  => isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50,
        ];
        try {
            $result = $lte->getSubscribers(array_filter($filters, function($v) { return $v !== '' && $v !== null && $v !== 0; }));
            // getSubscribers always returns paged envelope when page filter present
            // Ensure it's an envelope (has 'data' key)
            if (!isset($result['data'])) {
                // Fallback: wrap plain array (shouldn't happen when page>=1)
                $ok2(['data' => $result, 'total' => count($result), 'page' => 1, 'per_page' => count($result), 'pages' => 1]);
            } else {
                $ok2($result);
            }
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'no such table') !== false) { $ok2(['data'=>[],'total'=>0,'page'=>1,'per_page'=>50,'pages'=>0]); }
            else $er2('lte_subscribers error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
        }
    }

    // ── LTE Single subscriber ────────────────────────────────────────
    if ($act === 'lte_subscriber') {
        if (!$can('lte_subscribers')) $er2('Access denied.', 403);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) $er2('ID required.', 422);
        $sub = $lte->getSubscriber($id);
        if (!$sub) $er2('Subscriber not found.', 404);
        // attach renewal history
        $renewals = $store->findAll('lte_renewals.json', 'subscriber_id', $id);
        usort($renewals, fn($a,$b) => strcmp($b['created_at']??'',$a['created_at']??''));
        $sub['_renewals'] = array_slice($renewals, 0, 20);
        // attach Magma live state if configured
        if ($magma->isConfigured() && !empty($sub['imsi'])) {
            $liveState = $magma->getSubscriber($sub['imsi']);
            $sub['_magma_state'] = $liveState ? ($liveState['lte'] ?? null) : null;
        }
        $ok2($sub);
    }

    // ── LTE Create subscriber ────────────────────────────────────────
    if ($act === 'lte_create_subscriber' && $met === 'POST') {
        if (!$can('lte_subscribers')) $er2('Access denied.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($body['name']))   $er2('Name required.', 422);
        if (empty($body['phone']))  $er2('Phone required.', 422);
        $saved = $lte->createSubscriber($body, $retailerId, $retailer['name']);

        // If package_id provided, immediately activate subscription
        $activePkg = null;
        if (!empty($body['package_id']) && !empty($body['amount_paid'])) {
            $renewal = $lte->renewSubscription(
                $saved['id'], (int)$body['package_id'], $retailerId, $retailer['name'],
                (float)$body['amount_paid'], $body['payment_method'] ?? 'cash'
            );
            $saved['_first_renewal'] = $renewal;
            $activePkg = $store->findOne('lte_packages.json', 'id', (int)$body['package_id']);
        }

        // ── Quote / Proforma on LTE registration ───────────────────────────
        // Send UCRM quote + WA proforma (mirrors Starlink/Fiber KYC flow).
        // If subscriber has a ucrm_id, push to UCRM billing/quotes as well.
        // Non-blocking — quote failure must never break subscriber creation.
        try {
            require_once dirname(__DIR__, 2) . '/lib/QuotationService.php';
            $quotSvc    = new QuotationService($store, $dataDir, $config);
            $quoteItems = [];
            if ($activePkg) {
                $item = [
                    'label'    => ($activePkg['name'] ?? 'LTE Plan')
                                  . (isset($activePkg['speed_mbps']) && $activePkg['speed_mbps']
                                     ? ' (' . $activePkg['speed_mbps'] . ' Mbps)' : ''),
                    'quantity' => 1,
                    'price'    => (float)($activePkg['price'] ?? $body['amount_paid'] ?? 0),
                    'unit'     => 'month',
                ];
                if (!empty($activePkg['ucrm_product_id'])) {
                    $item['productId'] = (int)$activePkg['ucrm_product_id'];
                }
                $quoteItems[] = $item;
            } elseif (!empty($body['amount_paid'])) {
                $quoteItems[] = [
                    'label'    => 'LTE Service',
                    'quantity' => 1,
                    'price'    => (float)$body['amount_paid'],
                    'unit'     => 'month',
                ];
            }
            if (!empty($quoteItems)) {
                $ucrmId      = (int)($saved['ucrm_id'] ?? 0);
                $customerName = $saved['name'];
                $phone        = $saved['phone'];
                if ($ucrmId > 0) {
                    // Full flow: UCRM quote + WA proforma
                    $quoteRef = 'LTE-' . date('Ymd') . '-' . $saved['id'];
                    $crmResult  = $quotSvc->createCrmQuote($ucrmId, $quoteItems, $quoteRef, $retailer);
                    $crmQuoteId = $crmResult['ok'] ? (int)$crmResult['quote_id'] : 0;
                    // Use UCRM's own number as canonical ref
                    if ($crmResult['ok'] && !empty($crmResult['quote_number'])) {
                        $quoteRef = $crmResult['quote_number'];
                    }
                    $fakeApp = [
                        'mobile'         => $phone,
                        'firstname'      => $customerName,
                        'lastname'       => '',
                        'customer_name'  => $customerName,
                        'crm_client_id'  => $ucrmId,
                        'quote_ref'      => $quoteRef,
                    ];
                    if ($crmQuoteId) {
                        $quotSvc->sendKycQuoteWhatsApp($fakeApp, $quoteItems, $crmQuoteId, $retailer);
                        $saved['_quote_id']  = $crmQuoteId;
                        $saved['_quote_ref'] = $quoteRef;
                    } else {
                        // UCRM failed — fall back to WA-only proforma
                        $quotSvc->sendCashSaleProforma($customerName, $phone, $quoteItems, (float)($body['amount_paid'] ?? 0), $retailer);
                    }
                } else {
                    // No UCRM client yet — WA proforma only
                    $quotSvc->sendCashSaleProforma($customerName, $phone, $quoteItems, (float)($body['amount_paid'] ?? 0), $retailer);
                }
            }
        } catch (Throwable $qe) {
            error_log('[lte_create_subscriber] Quote send failed: ' . $qe->getMessage());
        }
        // ── End quote ───────────────────────────────────────────────────────

        $ok2($saved);
    }

    // ── LTE Update subscriber ────────────────────────────────────────
    if ($act === 'lte_update_subscriber' && $met === 'POST') {
        if (!$can('lte_subscribers')) $er2('Access denied.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        if (!$id) $er2('ID required.', 422);
        $lte->updateSubscriber($id, $body);
        $ok2(['updated' => true]);
    }

    // ── LTE Bulk Import (CSV rows) ────────────────────────────────────
    if ($act === 'lte_bulk_import' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        // $body already parsed at top of api_handlers.php — do NOT re-read php://input
        $rows = $body['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) $er2('No rows provided.', 422);

        // Load existing IMSI + MSISDN + phone to detect duplicates
        $existing = $store->load('lte_subscribers.json');
        $existImsi   = [];
        $existMsisdn = [];
        $existPhone  = [];
        foreach ($existing as $e) {
            if (!empty($e['imsi']))   $existImsi[trim($e['imsi'])]     = true;
            if (!empty($e['msisdn'])) $existMsisdn[trim($e['msisdn'])] = true;
            if (!empty($e['phone']))  $existPhone[trim($e['phone'])]   = true;
        }

        $results   = ['imported'=>0,'skipped'=>0,'errors'=>[],'rows'=>[]];
        $packages  = $store->load('lte_packages.json');
        $pkgByName = [];
        foreach ($packages as $p) { $pkgByName[strtolower(trim($p['name']))] = $p; }
        $pendingSubscriptions = [];

        foreach ($rows as $i => $row) {
            $rowNum = $i + 1;
            $name   = trim($row['name']   ?? '');
            $phone  = trim($row['phone']  ?? '');
            $imsi   = trim($row['imsi']   ?? '');
            $msisdn = trim($row['msisdn'] ?? '');

            if (empty($name) || empty($phone)) {
                $results['errors'][] = "Row {$rowNum}: name and phone required";
                $results['skipped']++;
                continue;
            }

            // Duplicate check
            if ($imsi   && isset($existImsi[$imsi]))     { $results['skipped']++; $results['rows'][] = ['row'=>$rowNum,'status'=>'duplicate','reason'=>'IMSI exists']; continue; }
            if ($msisdn && isset($existMsisdn[$msisdn])) { $results['skipped']++; $results['rows'][] = ['row'=>$rowNum,'status'=>'duplicate','reason'=>'MSISDN exists']; continue; }
            if (isset($existPhone[$phone]))               { $results['skipped']++; $results['rows'][] = ['row'=>$rowNum,'status'=>'duplicate','reason'=>'Phone exists']; continue; }

            $data = [
                'name'          => $name,
                'phone'         => $phone,
                'email'         => trim($row['email']      ?? ''),
                'address'       => trim($row['address']    ?? ''),
                'area'          => trim($row['area']       ?? ''),
                'imsi'          => $imsi,
                'msisdn'        => $msisdn,
                'iccid'         => trim($row['iccid']      ?? ''),
                'id_type'       => trim($row['id_type']    ?? ''),
                'id_number'     => trim($row['id_number']  ?? ''),
                'notes'         => trim($row['notes']      ?? ''),
                'bluecard_id'   => (int)($row['bluecard_id'] ?? 0),
                'registered_by' => 'bulk_import',
                'gps_lat'       => is_numeric($row['lat'] ?? '') ? (float)$row['lat'] : null,
                'gps_lon'       => is_numeric($row['lon'] ?? '') ? (float)$row['lon'] : null,
            ];

            $saved = $lte->createSubscriber($data, $retailerId, $retailer['name']);

            // Optionally create subscription if package + expiry provided
            $pkgName    = strtolower(trim($row['package'] ?? ''));
            $expiresAt  = trim($row['expires_at'] ?? '');
            $amtPaid    = (float)($row['amount_paid'] ?? 0);
            $pkgMatched = $pkgByName[$pkgName] ?? null;

            if ($pkgMatched && $expiresAt) {
                $pendingSubscriptions[] = [
                    'subscriber_id'  => $saved['id'],
                    'package_id'     => $pkgMatched['id'],
                    'package_name'   => $pkgMatched['name'],
                    'magma_profile'  => $pkgMatched['magma_profile'] ?? 'default',
                    'started_at'     => trim($row['started_at'] ?? date('Y-m-d')),
                    'expires_at'     => $expiresAt,
                    'status'         => (strtotime($expiresAt) >= strtotime('today')) ? 'active' : 'expired',
                    'amount_paid'    => $amtPaid,
                    'payment_method' => trim($row['payment_method'] ?? 'import'),
                    'agent_id'       => $retailerId,
                    'agent_name'     => $retailer['name'],
                    'created_at'     => date('Y-m-d H:i:s'),
                ];
            }

            // Mark IMSI/phone as used for remainder of this batch
            if ($imsi)   $existImsi[$imsi]     = true;
            if ($msisdn) $existMsisdn[$msisdn] = true;
            $existPhone[$phone] = true;

            $results['imported']++;
            $results['rows'][] = ['row'=>$rowNum,'status'=>'ok','id'=>$saved['id'],'name'=>$name];
        }

        // Batch-save any subscriptions collected (single file write)
        if (!empty($pendingSubscriptions)) {
            $allSubs2 = $store->load('lte_subscriptions.json');
            $nextId2  = empty($allSubs2) ? 1 : (max(array_map(function($s){ return (int)($s['id']??0); }, $allSubs2)) + 1);
            foreach ($pendingSubscriptions as $ps) {
                $ps['id'] = $nextId2++;
                $allSubs2[] = $ps;
            }
            $store->save('lte_subscriptions.json', $allSubs2);
        }

        $ok2($results);
    }

    // ── BlueCard CSV: Import Packages (3_packages.csv) ─────────────────────
    if ($act === 'lte_import_packages' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $rows = $body['rows'] ?? [];
        if (empty($rows)) $er2('No rows.', 422);

        // lte_packages.json is the canonical store (LteService::getPackages() reads from it)
        $pkgs   = $store->load('lte_packages.json') ?: [];
        $byName = [];
        foreach ($pkgs as $p) { $byName[strtolower(trim($p['name'] ?? ''))] = true; }
        $nextId = empty($pkgs) ? 1 : (max(array_map(function($p){ return (int)($p['id']??0); }, $pkgs)) + 1);

        $imported = 0; $skipped = 0; $errors = [];
        foreach ($rows as $i => $row) {
            $name = trim($row['name'] ?? '');
            if (!$name) { $skipped++; continue; }
            if (isset($byName[strtolower($name)])) { $skipped++; continue; }

            $priceUsd = (float)($row['price_usd'] ?? $row['price'] ?? 0);
            $bytesRaw = trim($row['bytes_total'] ?? '');
            $bytes    = ($bytesRaw !== '' && $bytesRaw !== '55555555555555' && strlen($bytesRaw) > 3)
                        ? (int)$bytesRaw : null;
            $planType = (int)($row['plan_type'] ?? ($bytes ? 0 : 2));

            $pkg = [
                'id'               => $nextId++,
                'name'             => $name,
                'description'      => strip_tags(trim($row['description'] ?? $name)),
                'price'            => $priceUsd,
                'price_cents'      => (int)round($priceUsd * 100),
                'type'             => $planType,
                'type_label'       => $planType === 0 ? 'data_cap' : 'unlimited',
                'bytes_allowed'    => $bytes,
                'bytes_display'    => $bytes ? round($bytes / 1e9, 1) . ' GB' : 'Unlimited',
                'days'             => (int)($row['days'] ?? 31),
                'days_display'     => ((int)($row['days'] ?? 31)) . ' days',
                'magma_profile'    => trim($row['speed_profile'] ?? $row['magma_profile'] ?? 'default'),
                'active_apns'      => trim($row['active_apns'] ?? 'cmnet'),
                'active_policies'  => 'omni',
                'is_active'        => (bool)(int)($row['is_active'] ?? 1),
                'active'           => (bool)(int)($row['is_active'] ?? 1),
                'is_popular'       => false,
                'sort_order'       => $nextId - 1,
                'lifecycle_status' => trim($row['lifecycle_status'] ?? 'In Production'),
                'bluecard_id'      => (int)($row['bluecard_id'] ?? 0),
                'created_at'       => date('Y-m-d H:i:s'),
            ];
            $pkgs[] = $pkg;
            $byName[strtolower($name)] = true;
            $imported++;
        }

        if ($imported > 0) $store->save('lte_packages.json', $pkgs);
        $ok2(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // ── BlueCard CSV: Import SIM Cards (2_sim_cards.csv) ────────────────────
    // NOTE: canonical store is lte_sims.json (LteService reads from JSON, not SQLite)
    if ($act === 'lte_import_sims_csv' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $rows = $body['rows'] ?? [];
        if (empty($rows)) $er2('No rows.', 422);

        $sims   = $store->load('lte_sims.json') ?: [];
        $byImsi = [];
        foreach ($sims as $s) { $byImsi[trim($s['imsi'] ?? '')] = true; }
        $nextId = empty($sims) ? 1 : (max(array_map(function($s){ return (int)($s['id']??0); }, $sims)) + 1);

        $imported = 0; $skipped = 0; $errors = [];
        foreach ($rows as $i => $row) {
            $imsi = trim($row['imsi'] ?? '');
            $ak   = trim($row['auth_key'] ?? '');
            if (!$imsi || strlen($ak) < 32) { $skipped++; continue; }
            if (isset($byImsi[$imsi]))       { $skipped++; continue; }
            $sims[] = [
                'id'                => $nextId++,
                'imsi'              => $imsi,
                'msisdn'            => trim($row['msisdn'] ?? $imsi),
                'iccid'             => trim($row['iccid'] ?? ''),
                'auth_key'          => $ak,
                'auth_opc'          => trim($row['auth_opc'] ?? ''),
                'algo'              => trim($row['algo'] ?? 'Milenage'),
                'status'            => 'in_stock',
                'subscriber_id'     => null,
                'holder_id'         => null,
                'holder_type'       => 'admin',
                'magma_provisioned' => false,
                'bluecard_id'       => (int)($row['bluecard_sim_id'] ?? $row['bluecard_id'] ?? 0),
                'vendor'            => 'DishNet',
                'created_at'        => date('Y-m-d H:i:s'),
            ];
            $byImsi[$imsi] = true;
            $imported++;
        }
        if ($imported > 0) $store->save('lte_sims.json', $sims);
        $ok2(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // ── BlueCard CSV: Import Customers (1_customers.csv) ─────────────────────
    // NOTE: routed through lte_bulk_import by JS auto-detection. This handler
    // is kept as a fallback but is NOT the primary path.
    if ($act === 'lte_import_customers_csv' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $rows = $body['rows'] ?? [];
        if (empty($rows)) $er2('No rows.', 422);

        $subs   = $store->load('lte_subscribers.json') ?: [];
        $byPhone = [];
        foreach ($subs as $s) { $byPhone[trim($s['phone'] ?? '')] = true; }
        $nextId = empty($subs) ? 1 : (max(array_map(function($s){ return (int)($s['id']??0); }, $subs)) + 1);

        $imported = 0; $skipped = 0; $errors = [];
        foreach ($rows as $i => $row) {
            $fn    = trim($row['firstname'] ?? '');
            $ln    = trim($row['lastname']  ?? '');
            $name  = trim($row['name'] ?? trim("$fn $ln"));
            $phone = trim($row['phone'] ?? $row['mobile'] ?? '');
            if (!$name || !$phone) { $skipped++; continue; }
            if (isset($byPhone[$phone])) { $skipped++; continue; }
            $subs[] = [
                'id'              => $nextId++,
                'name'            => $name,
                'phone'           => $phone,
                'email'           => trim($row['email'] ?? ''),
                'address'         => trim($row['address'] ?? $row['area'] ?? ''),
                'area'            => trim($row['area'] ?? ''),
                'imsi'            => null,
                'msisdn'          => null,
                'sim_id'          => null,
                'status'          => 'active',
                'registered_by'   => 'bulk_import',
                'agent_id'        => $retailerId,
                'agent_name'      => $retailer['name'] ?? 'Import',
                'bluecard_id'     => (int)($row['bluecard_id'] ?? 0),
                'created_at'      => date('Y-m-d H:i:s'),
            ];
            $byPhone[$phone] = true;
            $imported++;
        }
        if ($imported > 0) $store->save('lte_subscribers.json', $subs);
        $ok2(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }

    // ── BlueCard CSV: Import Subscriptions (4_subscriptions.csv) ─────────────
    if ($act === 'lte_import_subscriptions_csv' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $rows = $body['rows'] ?? [];
        if (empty($rows)) $er2('No rows.', 422);

        // All lookups from JSON (canonical store)
        $allSubs  = $store->load('lte_subscribers.json') ?: [];
        $allPkgs  = $store->load('lte_packages.json')    ?: [];
        $allSims  = $store->load('lte_sims.json')        ?: [];
        $subsList = $store->load('lte_subscriptions.json') ?: [];

        // Build lookup maps
        $subByBcId = []; // bluecard_id => subscriber record
        foreach ($allSubs as $s) {
            $bid = (int)($s['bluecard_id'] ?? 0);
            if ($bid) $subByBcId[$bid] = $s;
        }
        $pkgByName = [];
        foreach ($allPkgs as $p) { $pkgByName[strtolower(trim($p['name'] ?? ''))] = $p; }
        $simByImsi = [];
        foreach ($allSims as $s) { $simByImsi[trim($s['imsi'] ?? '')] = $s; }
        // Existing active subscriptions (dedup by subscriber_id)
        $activeSubs = [];
        foreach ($subsList as $s) {
            if (($s['status'] ?? '') === 'active') $activeSubs[(int)($s['subscriber_id'] ?? 0)] = true;
        }
        $nextId = empty($subsList) ? 1 : (max(array_map(function($s){ return (int)($s['id']??0); }, $subsList)) + 1);

        $imported = 0; $skipped = 0; $errors = [];
        $subUpdates = []; // subscriber id => updates

        foreach ($rows as $i => $row) {
            $bcUserId = (int)($row['bluecard_user_id'] ?? 0);
            $subRec   = $subByBcId[$bcUserId] ?? null;
            if (!$subRec) { $skipped++; continue; }
            $subId = (int)$subRec['id'];

            // Skip if already has active subscription
            if (isset($activeSubs[$subId])) { $skipped++; continue; }

            $pkgName = strtolower(trim($row['package_name'] ?? ''));
            $pkgRec  = $pkgByName[$pkgName] ?? null;
            $imsi    = trim($row['imsi'] ?? '');
            $state   = strtolower(trim($row['state'] ?? 'active'));
            $status  = in_array($state, ['deactivated','inactive','disabled']) ? 'suspended' : 'active';
            $expiry  = trim($row['end_date'] ?? $row['expires_at'] ?? date('Y-m-d', strtotime('+30 days')));

            $entry = [
                'id'             => $nextId++,
                'subscriber_id'  => $subId,
                'package_id'     => $pkgRec ? (int)$pkgRec['id'] : null,
                'package_name'   => $pkgRec ? $pkgRec['name'] : ($row['package_name'] ?? ''),
                'package_type'   => $pkgRec ? (int)($pkgRec['type'] ?? 2) : 2,
                'bytes_allowed'  => $pkgRec ? ($pkgRec['bytes_allowed'] ?? null) : null,
                'bytes_used'     => 0,
                'magma_profile'  => $pkgRec ? ($pkgRec['magma_profile'] ?? 'default') : 'default',
                'status'         => $status,
                'started_at'     => trim($row['created_at'] ?? date('Y-m-d')),
                'expires_at'     => $expiry,
                'amount_paid'    => 0,
                'payment_method' => 'import',
                'agent_id'       => $retailerId,
                'agent_name'     => $retailer['name'] ?? 'Import',
                'bluecard_id'    => (int)($row['bluecard_svc_id'] ?? 0),
                'created_at'     => date('Y-m-d H:i:s'),
            ];
            $subsList[] = $entry;
            $activeSubs[$subId] = true;

            // Queue subscriber IMSI update
            if ($imsi && (!$subRec['imsi'])) {
                $simRec = $simByImsi[$imsi] ?? null;
                $subUpdates[$subId] = [
                    'imsi'   => $imsi,
                    'msisdn' => $imsi,
                    'sim_id' => $simRec ? (int)$simRec['id'] : null,
                ];
            }
            $imported++;
        }

        if ($imported > 0) {
            $store->save('lte_subscriptions.json', $subsList);
            // Apply IMSI updates to subscribers
            if (!empty($subUpdates)) {
                $allSubs2 = $store->load('lte_subscribers.json') ?: [];
                foreach ($allSubs2 as &$sub) {
                    $upd = $subUpdates[(int)($sub['id'] ?? 0)] ?? null;
                    if ($upd) {
                        $sub['imsi']   = $upd['imsi'];
                        $sub['msisdn'] = $upd['msisdn'];
                        if ($upd['sim_id']) $sub['sim_id'] = $upd['sim_id'];
                    }
                }
                unset($sub);
                $store->save('lte_subscribers.json', $allSubs2);
                // Also update sim status → assigned
                if (!empty($subUpdates)) {
                    $allSims2 = $store->load('lte_sims.json') ?: [];
                    $imsiSet  = array_column($subUpdates, 'imsi');
                    foreach ($allSims2 as &$sim) {
                        if (in_array($sim['imsi'] ?? '', $imsiSet)) $sim['status'] = 'assigned';
                    }
                    unset($sim);
                    $store->save('lte_sims.json', $allSims2);
                }
            }
        }
        $ok2(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'imsi_linked' => count($subUpdates)]);
    }

    // ── BlueCard CSV: Import Data Usage (5_data_usage.csv) ───────────────────
    if ($act === 'lte_import_usage_csv' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $rows = $body['rows'] ?? [];
        if (empty($rows)) $er2('No rows.', 422);

        $subsList = $store->load('lte_subscriptions.json') ?: [];
        $allSubs  = $store->load('lte_subscribers.json')   ?: [];

        // Build IMSI → subscriber_id map
        $subIdByImsi = [];
        foreach ($allSubs as $s) {
            if (!empty($s['imsi'])) $subIdByImsi[trim($s['imsi'])] = (int)$s['id'];
        }
        // Build subscriber_id → subscription index
        $subIdxById = [];
        foreach ($subsList as $idx => $s) {
            if (($s['status'] ?? '') === 'active') $subIdxById[(int)($s['subscriber_id'] ?? 0)] = $idx;
        }

        $imported = 0; $skipped = 0;
        foreach ($rows as $row) {
            $imsi = trim($row['imsi'] ?? '');
            if (!$imsi) { $skipped++; continue; }
            $subId = $subIdByImsi[$imsi] ?? null;
            if (!$subId) { $skipped++; continue; }
            $idx = $subIdxById[$subId] ?? null;
            if ($idx === null) { $skipped++; continue; }

            $bytesRaw = trim($row['bytes_total'] ?? '');
            $bytes    = ($bytesRaw && $bytesRaw !== '55555555555555') ? (int)$bytesRaw : null;
            $expiry   = trim($row['end_date'] ?? '');
            if ($bytes !== null) $subsList[$idx]['bytes_allowed'] = $bytes;
            if ($expiry)         $subsList[$idx]['expires_at']    = $expiry;
            $subsList[$idx]['bytes_used'] = (int)($row['bytes_used'] ?? 0);
            $imported++;
        }
        if ($imported > 0) $store->save('lte_subscriptions.json', $subsList);
        $ok2(['imported' => $imported, 'skipped' => $skipped, 'errors' => []]);
    }

    // ── BlueCard CSV: Import Recharge History (6_recharge_history.csv) ───────
    if ($act === 'lte_import_recharge_csv' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $rows = $body['rows'] ?? [];
        if (empty($rows)) $er2('No rows.', 422);

        $allSubs  = $store->load('lte_subscribers.json') ?: [];
        $allPkgs  = $store->load('lte_packages.json')    ?: [];
        $renewals = $store->load('lte_renewals.json')    ?: [];

        $subByBcId = [];
        foreach ($allSubs as $s) {
            $bid = (int)($s['bluecard_id'] ?? 0);
            if ($bid) $subByBcId[$bid] = (int)$s['id'];
        }
        $pkgByName = [];
        foreach ($allPkgs as $p) { $pkgByName[strtolower(trim($p['name'] ?? ''))] = (int)$p['id']; }
        // Existing bluecard_ids to dedup
        $existBcIds = [];
        foreach ($renewals as $r) {
            $bid = (int)($r['bluecard_id'] ?? 0);
            if ($bid) $existBcIds[$bid] = true;
        }
        $nextId = empty($renewals) ? 1 : (max(array_map(function($r){ return (int)($r['id']??0); }, $renewals)) + 1);

        $imported = 0; $skipped = 0; $errors = [];
        foreach ($rows as $i => $row) {
            $bcUserId  = (int)($row['bluecard_user_id'] ?? 0);
            $subId     = $subByBcId[$bcUserId] ?? 0;
            if (!$subId) { $skipped++; continue; }
            $bcTopupId = (int)($row['bluecard_topup_id'] ?? $row['bluecard_id'] ?? 0);
            if ($bcTopupId && isset($existBcIds[$bcTopupId])) { $skipped++; continue; }
            $pkgName   = trim($row['package_name'] ?? '');
            $pkgId     = $pkgByName[strtolower($pkgName)] ?? null;
            $priceUsd  = (float)($row['price_usd'] ?? 0);
            $renewals[] = [
                'id'             => $nextId++,
                'subscriber_id'  => $subId,
                'package_id'     => $pkgId,
                'package_name'   => $pkgName,
                'package_price'  => $priceUsd,
                'amount_paid'    => $priceUsd,
                'payment_method' => 'import',
                'is_addon'       => (bool)(int)($row['is_addon'] ?? 0),
                'agent_id'       => $retailerId,
                'agent_name'     => $retailer['name'] ?? 'Import',
                'bluecard_id'    => $bcTopupId,
                'created_at'     => trim($row['created_at'] ?? date('Y-m-d H:i:s')),
            ];
            if ($bcTopupId) $existBcIds[$bcTopupId] = true;
            $imported++;
        }
        if ($imported > 0) $store->save('lte_renewals.json', $renewals);
        $ok2(['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
    }

        // ── LTE Renew subscription ───────────────────────────────────────
    if ($act === 'lte_renew' && $met === 'POST') {
        if (!$can('lte_renewal')) $er2('Access denied.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $subId = (int)($body['subscriber_id'] ?? 0);
        $pkgId = (int)($body['package_id']    ?? 0);
        $amt   = (float)($body['amount_paid'] ?? 0);
        $meth  = trim($body['payment_method'] ?? 'cash');
        if (!$subId || !$pkgId) $er2('subscriber_id and package_id required.', 422);
        try {
            $result = $lte->renewSubscription($subId, $pkgId, $retailerId, $retailer['name'], $amt, $meth);
            // Auto-send WhatsApp renewal confirmation
            $sub2 = $lte->getSubscriber($subId);
            if (!empty($sub2['phone']) && !empty($config['wa_plugin_url'])) {
                $pkgN   = $result['package_name'] ?? '';
                $expAt  = $result['_expires']     ?? '';
                $notify->sendRaw($sub2['phone'],
                    "Hello {$sub2['name']}! ✅\n\nYour DishNet LTE plan *{$pkgN}* has been renewed.\n\n📅 Valid until: *{$expAt}*\n\nThank you for choosing DishNet Africa! 🙏\n\n_DishNet Africa_",
                    'lte_renewed');
            }
            $ok2($result);
        } catch (RuntimeException $e) {
            $er2($e->getMessage(), 422);
        }
    }

    // ── LTE Suspend/Reactivate ───────────────────────────────────────
    if ($act === 'lte_suspend' && $met === 'POST') {
        if (!$can('lte_subscribers') && !$isAdmin) $er2('Access denied.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['subscriber_id'] ?? 0);
        if (!$id) $er2('subscriber_id required.', 422);
        $ok  = $lte->suspendSubscriber($id, $body['reason'] ?? 'manual');
        $ok2(['suspended' => $ok, 'magma' => $magma->isConfigured()]);
    }

    if ($act === 'lte_reactivate' && $met === 'POST') {
        if (!$can('lte_subscribers') && !$isAdmin) $er2('Access denied.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['subscriber_id'] ?? 0);
        if (!$id) $er2('subscriber_id required.', 422);
        $ok  = $lte->reactivateSubscriber($id);
        $ok2(['reactivated' => $ok]);
    }

    // ── LTE Renewal queue ────────────────────────────────────────────
    if ($act === 'lte_renewal_queue') {
        if (!$can('lte_renewal')) $er2('Access denied.', 403);
        $days = (int)($_GET['days'] ?? 7);
        try {
            $ok2($lte->getRenewalQueue($days));
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'no such table') !== false) { $ok2([]); }
            else $er2('lte_renewal_queue error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
        }
    }

    // ── LTE SIM inventory ────────────────────────────────────────────
    if ($act === 'lte_sims') {
        if (!$can('lte_sims')) $er2('Access denied.', 403);
        $page    = max(1, (int)($_GET['page']     ?? 1));
        $perPage = max(10, min(200, (int)($_GET['per_page'] ?? 50)));
        $filters = ['status' => $_GET['status'] ?? '', 'search' => $_GET['search'] ?? ''];
        $allSims = $lte->getSims(array_filter($filters));
        $total   = count($allSims);
        $pages   = (int)ceil($total / $perPage);
        $slice   = array_slice($allSims, ($page - 1) * $perPage, $perPage);
        $ok2([
            'sims'     => $slice,
            'counts'   => $lte->getSimCounts(),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ]);
    }

    if ($act === 'lte_create_sim' && $met === 'POST') {
        if (!$can('lte_sims') && !$isAdmin) $er2('Access denied.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($body['imsi']))   $er2('IMSI required.', 422);
        if (empty($body['msisdn'])) $er2('MSISDN required.', 422);
        $ok2($lte->createSim($body));
    }

    // ── LTE Hardware ─────────────────────────────────────────────────
    if ($act === 'lte_hardware') {
        if (!$can('lte_sims')) $er2('Access denied.', 403);
        $filters = ['status'=>$_GET['status']??'','type'=>$_GET['type']??'','search'=>$_GET['search']??''];
        $ok2($lte->getHardware(array_filter($filters)));
    }

    if ($act === 'lte_create_hardware' && $met === 'POST') {
        if (!$can('lte_sims') && !$isAdmin) $er2('Access denied.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($body['serial'])) $er2('Serial number required.', 422);
        $ok2($lte->createHardware($body));
    }

    // ── LTE Packages ─────────────────────────────────────────────────
    if ($act === 'lte_packages') {
        if (!$can('lte_renewal')) $er2('Access denied.', 403);
        try {
            $ok2($lte->getPackages());
        } catch (\Throwable $e) {
            // Table may not exist yet — return empty instead of 500
            if (strpos($e->getMessage(), 'no such table') !== false) { $ok2([]); }
            else $er2('lte_packages error: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
        }
    }

    if ($act === 'lte_create_package' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($body['name'])) $er2('Package name required.', 422);
        $ok2($lte->createPackage($body));
    }

    if ($act === 'lte_toggle_package' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = (int)($body['id'] ?? 0);
        $pkgs = $store->load('lte_packages.json');
        foreach ($pkgs as &$p) {
            if ((int)($p['id']??0) === $id) { $p['active'] = !(bool)($p['active']??true); break; }
        }
        $store->save('lte_packages.json', $pkgs);
        $ok2(['toggled' => true]);
    }

    // ── Magma usage cache refresh ────────────────────────────────────
    if ($act === 'lte_refresh_usage') {
        if (!$isAdmin && !$can('lte_dashboard')) $er2('Access denied.', 403);
        $ok2($lte->refreshUsageCache());
    }

    // ── Usage summary for monitor screen ─────────────────────────────
    if ($act === 'lte_usage_summary') {
        if (!$can('lte_dashboard') && !$isAdmin) $er2('Access denied.', 403);
        $filters = ['search'=>$_GET['search']??'','state'=>$_GET['state']??''];
        $summary = $lte->getUsageSummary(array_filter($filters));
        $cache   = $lte->getAllCachedUsage();
        $total=$active=$inactive=$noImsi=$unreachable=$highLat=$mismatch=0;
        $latencies=[];
        foreach ($summary as $row) {
            $total++;
            if ($row['magma_state']==='ACTIVE')    $active++;
            if ($row['magma_state']==='INACTIVE')  $inactive++;
            if ($row['_no_imsi'])       $noImsi++;
            if ($row['_zero_reach'])    $unreachable++;
            if ($row['_high_latency'])  $highLat++;
            if ($row['_magma_mismatch'])$mismatch++;
            if ($row['latency_ms']!==null) $latencies[]=$row['latency_ms'];
        }
        $avgLat   = $latencies ? round(array_sum($latencies)/count($latencies),1) : null;
        $cachedAt = $cache ? (reset($cache)['cached_at'] ?? null) : null;
        $ok2(['subscribers'=>$summary,'stats'=>[
            'total'=>$total,'active'=>$active,'inactive'=>$inactive,
            'no_imsi'=>$noImsi,'unreachable'=>$unreachable,'high_lat'=>$highLat,
            'mismatch'=>$mismatch,'avg_lat_ms'=>$avgLat,'cached_at'=>$cachedAt,
        ]]);
    }

    // ── Network health: gateways + eNodeBs ───────────────────────────
    if ($act === 'lte_network_health') {
        if (!$can('lte_dashboard') && !$isAdmin) $er2('Access denied.', 403);
        $ok2(['configured'=>$magma->isConfigured(),'health'=>$lte->getCachedNetworkHealth(),'error'=>$magma->getLastError()]);
    }

    // ── Refresh network health only (fast) ───────────────────────────
    if ($act === 'lte_refresh_health') {
        if (!$isAdmin && !$can('lte_dashboard')) $er2('Access denied.', 403);
        if (!$magma->isConfigured()) $ok2(['skipped'=>true,'reason'=>'Magma not configured']);
        $ok2($lte->refreshNetworkHealth());
    }

    // ── Magma network health ─────────────────────────────────────────
    if ($act === 'lte_magma_health') {
        if (!$isAdmin && !$can('lte_dashboard')) $er2('Access denied.', 403);
        if (!$magma->isConfigured()) $ok2(['configured'=>false]);
        $network  = $magma->getNetwork();
        $gateways = $magma->getGatewayStatus();
        $enodebs  = $magma->listEnodebs();
        $ok2(['configured'=>true,'network'=>$network,'gateways'=>$gateways,'enodebs'=>$enodebs,'error'=>$magma->getLastError()]);
    }

    // ── POST kyc_update_username — edit username on a KYC application ────
    if ($act === 'kyc_update_username' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $appId    = (int)($body['app_id']  ?? 0);
        $username = trim($body['username'] ?? '');
        if (!$appId)    $er2('Missing app_id.', 400);
        if (!$username) $er2('Username cannot be empty.', 400);
        // Sanitise — alphanumeric, dots, underscores, hyphens only
        if (!preg_match('/^[A-Za-z0-9._\-]{2,60}$/', $username)) {
            $er2('Invalid username format. Use letters, numbers, dots, underscores or hyphens (2-60 chars).', 400);
        }
        $updated = $store->updateOne('kyc_applications.json', 'id', $appId, [
            'username'           => $username,
            'username_edited_by' => $retailer['name'] ?? 'admin',
            'username_edited_at' => date('Y-m-d H:i:s'),
        ]);
        if (!$updated) $er2('Application not found.', 404);
        $ok2(['ok' => true, 'username' => $username]);
    }

// ── Overdue email template preview ──────────────────────────────────────────
// POST ?page=api&action=overdue_email_preview
// Body: {stage, subject, para1, para2, cta, footer}
if ($act === 'overdue_email_preview' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin only', 403);
    $body  = json_decode(file_get_contents('php://input'), true) ?: [];
    $stage = (int)($body['stage'] ?? 1);
    $cfg   = $store->load('kyc_config.json') ?: [];

    $globalPhone = $cfg['overdue_email_phone']           ?? '+211 921 443 002';
    $globalEmail = $cfg['overdue_email_accounts_email']  ?? 'accounts@dishnetafrica.com';

    $tpl = [
        'subject' => $body['subject'] ?? '',
        'para1'   => $body['para1']   ?? '',
        'para2'   => $body['para2']   ?? '',
        'cta'     => $body['cta']     ?? '',
        'footer'  => $body['footer']  ?? '',
    ];

    // Stage-accurate preview data so content reads naturally
    $stageDays  = [1=>7, 2=>14, 3=>31, 4=>61, 5=>90, 6=>120];
    $stageSubs  = [
        1 => 'Your DishNet service is suspended. Pay now to reconnect instantly.',
        2 => 'Still suspended after 14 days — one payment gets you back online.',
        3 => 'We genuinely miss having you connected. Easy to fix.',
        4 => 'Your connection is ready and waiting — just needs your payment.',
        5 => 'We are still here and your connection can still be restored.',
        6 => 'Your DishNet service can still be restored — final notice.',
    ];
    $previewDays = $stageDays[$stage] ?? 14;
    $previewSub  = $stageSubs[$stage] ?? 'Your DishNet service is currently suspended.';

    $vars = [
        'first_name'     => 'Moses',
        'invoice_number' => 'INV012910',
        'amount'         => '$50.00',
        'due_date'       => '9 Apr 2026',
        'days_overdue'   => (string)$previewDays,
        'invoice_url'    => 'https://crm.dishnetafrica.com/crm/client/1260',
        'accounts_phone' => $globalPhone,
        'accounts_email' => $globalEmail,
    ];

    $rep = function(string $t) use ($vars): string {
        foreach ($vars as $k => $v) $t = str_replace('{{' . $k . '}}', (string)$v, $t);
        return $t;
    };

    $heroColors = [1=>'linear-gradient(135deg,#D41C1C 0%,#8b0000 100%)',
                   2=>'linear-gradient(135deg,#1d4ed8 0%,#1e3a8a 100%)',
                   3=>'linear-gradient(135deg,#d97706 0%,#92400e 100%)',
                   4=>'linear-gradient(135deg,#D41C1C 0%,#7f1d1d 100%)',
                   5=>'linear-gradient(135deg,#374151 0%,#111827 100%)',
                   6=>'linear-gradient(135deg,#374151 0%,#111827 100%)'];
    $ctaBgs  = [1=>'#059669',2=>'#059669',3=>'#d97706',4=>'#D41C1C',5=>'#D41C1C',6=>'#374151'];
    $ctaBoxs = [1=>['#F0FDF4','#BBF7D0','#065f46'],2=>['#F0FDF4','#BBF7D0','#065f46'],
                3=>['#fffbeb','#fde68a','#92400e'],4=>['#fef2f2','#fecaca','#7f1d1d'],
                5=>['#fef2f2','#fecaca','#7f1d1d'],6=>['#f8fafc','#e2e8f0','#475569']];
    $brdClrs = [1=>'#D41C1C',2=>'#1d4ed8',3=>'#d97706',4=>'#D41C1C',5=>'#374151',6=>'#374151'];

    $heroBg  = $heroColors[$stage] ?? $heroColors[1];
    $ctaBtn  = $ctaBgs[$stage]     ?? '#059669';
    $box     = $ctaBoxs[$stage]    ?? $ctaBoxs[1];
    $brd     = $brdClrs[$stage]    ?? '#D41C1C';

    $p1   = $rep($tpl['para1']);
    $p2   = $rep($tpl['para2']);
    $ft   = $rep($tpl['footer']);
    $ctaT = $rep($tpl['cta']);

    $html = '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#f5f5f5;font-family:Helvetica,Arial,sans-serif;}
.em{max-width:600px;margin:0 auto;background:#fff;}
.hdr{background:#fff;border-bottom:3px solid #D41C1C;padding:15px 24px;display:table;width:100%;}
.hdr-l{display:table-cell;vertical-align:bottom;}
.hdr-r{display:table-cell;vertical-align:bottom;text-align:right;}
.logo{font-size:26px;font-weight:900;color:#D41C1C;letter-spacing:-.5px;line-height:1;}
.logo-bar{height:4px;width:110px;background:#D41C1C;margin-top:3px;}
.logo-tag{font-size:8px;color:#aaa;margin-top:4px;}
.hdr-lbl{font-size:9px;text-transform:uppercase;letter-spacing:2px;color:#bbb;}
.hdr-date{font-size:11px;color:#888;margin-top:3px;}
.hero{padding:28px 24px 24px;position:relative;overflow:hidden;}
.hc{position:absolute;top:-40px;right:-40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none;}
.h-eye{font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:8px;}
.h-greet{font-family:Georgia,serif;font-size:24px;font-weight:400;color:#fff;line-height:1.2;margin-bottom:6px;}
.h-sub{font-size:13px;color:rgba(255,255,255,.72);line-height:1.55;max-width:300px;}
.h-amt{position:absolute;right:24px;top:50%;transform:translateY(-50%);text-align:right;}
.h-amt-num{font-size:36px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1;}
.h-amt-lbl{font-size:8px;color:rgba(255,255,255,.5);font-weight:700;letter-spacing:1.5px;text-transform:uppercase;margin-top:3px;}
.bd{background:#fff;padding:22px 24px 18px;}
.meta{width:100%;border-collapse:collapse;margin-bottom:18px;}
.meta td{padding:8px 10px;border:1px solid #e0e0e0;width:25%;vertical-align:top;}
.ml{font-size:7px;text-transform:uppercase;letter-spacing:1px;color:#bbb;font-weight:700;margin-bottom:3px;}
.mv{font-size:12px;font-weight:700;color:#141414;}
.mv.red{color:#D41C1C;font-size:16px;}
.msg{font-family:Georgia,serif;font-size:13px;color:#444;line-height:1.8;padding-left:13px;margin-bottom:18px;}
.cta-blk{padding:14px;text-align:center;margin-bottom:12px;}
.cta-lbl{font-size:9px;margin-bottom:9px;font-weight:600;letter-spacing:.3px;}
.cta-a{display:inline-block;color:#fff;font-size:13px;font-weight:800;padding:12px 32px;border-radius:4px;text-decoration:none;letter-spacing:.4px;text-transform:uppercase;}
.note{padding:7px 11px;font-size:8px;color:#555;line-height:1.65;margin-bottom:12px;}
.note b{color:#141414;}
.cgen{font-size:8px;color:#bbb;font-style:italic;}
.help{background:#f8f8f8;border-top:1px solid #f0f0f0;padding:16px 24px;display:flex;align-items:center;gap:12px;}
.hi{width:38px;height:38px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.ht{font-size:12px;font-weight:800;color:#141414;margin-bottom:2px;}
.hd{font-size:11px;color:#888;line-height:1.5;}
.fty{background:#fff;padding:0 24px;}
.fty table{width:100%;border-collapse:collapse;border-top:1px solid #e8e8e8;}
.fty td{padding:10px 0;vertical-align:middle;border:none;}
.ft1{font-size:11px;font-weight:700;color:#141414;}
.ft2{font-size:8px;color:#aaa;margin-top:2px;}
.ft3{font-size:8px;color:#888;}
.redbar{height:2px;background:#D41C1C;}
.strip table{width:100%;border-collapse:collapse;background:#fff;}
.strip td{padding:6px 24px;font-size:8px;color:#aaa;border:none;}
.oc{font-size:9px;color:#D41C1C;text-align:center;font-style:italic;font-weight:600;}
.rr{text-align:right;}
.dkft{background:#141414;padding:18px 24px;}
.sr{display:flex;gap:7px;margin-bottom:13px;}
.sc{width:28px;height:28px;border-radius:4px;background:#222;color:#555;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;text-decoration:none;}
.fl{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:11px;}
.fl a{font-size:10px;color:#555;text-decoration:none;font-weight:600;}
.fl a.u{color:#D41C1C;}
.lg{font-size:10px;color:#3a3a3a;line-height:1.7;}
.lg a{color:#555;text-decoration:underline;}
</style></head><body>
<div class="em">
  <div class="hdr">
    <div class="hdr-l"><div class="logo">DishNet</div><div class="logo-bar"></div><div class="logo-tag">Internet Service Provider</div></div>
    <div class="hdr-r"><div class="hdr-lbl">Service Notice</div><div class="hdr-date">' . date('d M Y') . '</div></div>
  </div>
  <div class="hero" style="background:' . $heroBg . ';">
    <div class="hc"></div>
    <div class="h-eye">Stage ' . $stage . ' — Day ' . $previewDays . ' Preview</div>
    <div class="h-greet">Hi <strong>Moses</strong>,</div>
    <div class="h-sub">' . $previewSub . '</div>
    <div class="h-amt"><div class="h-amt-num">$50</div><div class="h-amt-lbl">to restore</div></div>
  </div>
  <div class="bd">
    <table class="meta">
      <tr>
        <td><div class="ml">Invoice</div><div class="mv">INV012910</div></td>
        <td><div class="ml">Amount Due</div><div class="mv red">$50.00</div></td>
        <td><div class="ml">Due Date</div><div class="mv">9 Apr 2026</div></td>
        <td><div class="ml">Status</div><div class="mv" style="color:#D41C1C;font-size:11px;">SUSPENDED</div><div style="font-size:9px;color:#bbb;margin-top:1px;">' . $previewDays . ' days overdue</div></td>
      </tr>
    </table>
    <p class="msg" style="border-left:3px solid ' . $brd . ';">' . htmlspecialchars($p1) . '</p>
    <p class="msg" style="border-left:3px solid transparent;">' . htmlspecialchars($p2) . '</p>
    <div class="cta-blk" style="border:1px solid ' . $box[1] . ';background:' . $box[0] . ';">
      <div class="cta-lbl" style="color:' . $box[2] . ';">Pay this invoice to restore your service instantly</div>
      <a href="#" class="cta-a" style="background:' . $ctaBtn . ';">' . htmlspecialchars($ctaT) . ' &rarr;</a>
    </div>
    <div class="note" style="border:1px solid #e0e0e0;border-left:3px solid ' . $brd . ';"><b>Please note:</b> ' . htmlspecialchars($ft) . '</div>
    <div class="cgen">This is a computer generated notice — no signature required.</div>
  </div>
  <div class="help">
    <div class="hi">&#x1F4AC;</div>
    <div><div class="ht">Need help getting reconnected?</div><div class="hd">Reply to this email &nbsp;&middot;&nbsp; WhatsApp ' . htmlspecialchars($globalPhone) . ' &nbsp;&middot;&nbsp; Call +211 921 443 002</div></div>
  </div>
  <div class="fty"><table><tr>
    <td><div class="ft1">Thank you for your business.</div><div class="ft2">For queries contact ' . htmlspecialchars($globalPhone) . ' or ' . htmlspecialchars($globalEmail) . '</div></td>
    <td style="text-align:right;"><div class="ft3">DishNet Africa Ltd.</div></td>
  </tr></table></div>
  <div class="redbar"></div>
  <div class="strip"><table><tr>
    <td>DishNet Africa Ltd &middot; South Sudan</td>
    <td class="oc">Of course we can ...</td>
    <td class="rr">www.dishnetafrica.com</td>
  </tr></table></div>
  <div class="dkft">
    <div class="sr"><a href="#" class="sc">f</a><a href="#" class="sc">in</a><a href="#" class="sc">X</a><a href="#" class="sc">wa</a></div>
    <div class="fl"><a href="#">View Invoice</a><a href="#">Support</a><a href="#">dishnetafrica.com</a><a href="#" class="u">Unsubscribe</a></div>
    <div class="lg">&copy; ' . date('Y') . ' DishNet Africa Ltd &middot; South Sudan<br>You are receiving this because you have an active account with DishNet Africa.</div>
  </div>
</div>
</body></html>';

    $ok2(['html' => $html, 'subject' => $rep($tpl['subject'])]);
}

// ══════════════════════════════════════════════════════════════════════════════
// DRY-RUN SIMULATOR: Auto-invoice on Splynx service activation
// Learn how this would work BEFORE deploying.
//
// GET  ?page=api&action=splynx_invoice_dryrun          — shows what WOULD happen
// GET  ?page=api&action=splynx_invoice_dryrun&client_id=123  — test specific client
// ══════════════════════════════════════════════════════════════════════════════
if ($act === 'splynx_invoice_dryrun') {
    if (!$isAdmin) $er2('Admin only', 403);

    $testClientId = (int)($_GET['client_id'] ?? 0);

    // ── STEP 1: Understand the full flow ─────────────────────────────────────
    $flow = [
        'trigger'   => 'Splynx fires webhook → service.activated event',
        'step1'     => 'webhook.php receives splynx.service.activated → queued to events table',
        'step2'     => 'Event processor reads event → extracts splynx_customer_id from payload',
        'step3'     => 'Look up fiber_customer_map to find crm_client_id from splynx_customer_id',
        'step4'     => 'Call UCRM API: GET /clients/{crm_client_id}/services → find active service',
        'step5'     => 'Call UCRM API: POST /clients/{crm_client_id}/invoices → create invoice',
        'step6'     => 'Send WhatsApp to customer with invoice details',
        'step7'     => 'Log in plugin_kv to dedup (don\'t double-invoice)',
    ];

    // ── STEP 2: Check what Splynx webhook payload looks like ─────────────────
    $splynxPayloadExample = [
        'event'       => 'service.activated',
        'customer_id' => 123,     // Splynx customer ID
        'service_id'  => 456,     // Splynx service ID
        'tariff_id'   => 7,       // Splynx plan ID
        'tariff_name' => 'FTTH50',
        'price'       => 80.00,
        'start_date'  => date('Y-m-d'),
    ];

    // ── STEP 3: Check UCRM invoice creation API payload ──────────────────────
    $ucrm_invoice_api = [
        'endpoint'   => 'POST /api/v1.0/clients/{clientId}/invoices',
        'auth'       => 'X-Auth-Token: {admin crm_auth_token}  (NOT plugin app key)',
        'body_example' => [
            'items' => [[
                'label'    => 'FTTH50 — Monthly Internet Service',
                'quantity' => 1,
                'price'    => 80.00,
                'unit'     => 'month',
            ]],
            'maturityDays' => 14,
            'invoiceTemplateId' => null, // uses default
        ],
        'response' => ['id' => 9999, 'number' => 'INV013000', 'total' => 80.00],
    ];

    // ── STEP 4: Check current fiber_customer_map for test client ─────────────
    $mappedClients = [];
    $crmServiceData = null;
    $existingInvoices = [];
    $wouldCreate = null;

    try {
        $pdo = $store->getPdo();

        // How many clients are in the map?
        $mapCount = (int)$pdo->query("SELECT COUNT(*) FROM fiber_customer_map")->fetchColumn();

        // Sample some mapped clients
        $sampleMapped = $pdo->query(
            "SELECT crm_client_id, splynx_customer_id, created_at
             FROM fiber_customer_map
             ORDER BY created_at DESC LIMIT 5"
        )->fetchAll(\PDO::FETCH_ASSOC);

        // If test client specified, simulate full flow for them
        if ($testClientId > 0) {
            // Check if they are in map
            $mapRow = $pdo->prepare("SELECT * FROM fiber_customer_map WHERE crm_client_id=? LIMIT 1");
            $mapRow->execute([$testClientId]);
            $mapEntry = $mapRow->fetch(\PDO::FETCH_ASSOC);

            // Fetch client from UCRM
            $crmClient = $crm->get("clients/{$testClientId}") ?? [];
            $clientName = trim(($crmClient['firstName'] ?? '') . ' ' . ($crmClient['lastName'] ?? ''))
                       ?: ($crmClient['companyName'] ?? 'Unknown');
            $outstanding = $crmClient['accountOutstanding'] ?? 'N/A';

            // Fetch active services
            $services = $crm->get("clients/{$testClientId}/services") ?? [];
            $activeServices = array_values(array_filter($services, fn($s) =>
                ($s['status'] ?? '') === 'active'
            ));

            // Fetch recent invoices
            $existingInvoices = $crm->get("billing/invoices?clientId={$testClientId}&limit=5") ?? [];

            // What invoice WOULD be created
            if (!empty($activeServices)) {
                $svc = $activeServices[0];
                $wouldCreate = [
                    'client_id'    => $testClientId,
                    'client_name'  => $clientName,
                    'outstanding'  => $outstanding,
                    'service_name' => $svc['name'] ?? 'Internet Service',
                    'invoice_item' => ($svc['name'] ?? 'Internet Service') . ' — Monthly',
                    'amount'       => $svc['price'] ?? 0,
                    'due_date'     => date('Y-m-d', strtotime('+14 days')),
                    'in_fiber_map' => !empty($mapEntry),
                    'splynx_id'    => $mapEntry['splynx_customer_id'] ?? 'NOT IN MAP',
                    'would_send_wa'=> !empty($crmClient['contacts'][0]['phone'] ?? ''),
                    'wa_phone'     => $crmClient['contacts'][0]['phone'] ?? 'no phone on file',
                    'note'         => 'This is DRY RUN — nothing was created or sent',
                ];
            }

            $crmServiceData = [
                'client_name'     => $clientName,
                'active_services' => $activeServices,
                'map_entry'       => $mapEntry ?: 'NOT IN FIBER MAP',
            ];
        }

    } catch (\Throwable $e) {
        $mapCount   = 'error: ' . $e->getMessage();
        $sampleMapped = [];
    }

    // ── STEP 5: List current risks / questions to answer ─────────────────────
    $risks = [
        'RISK 1: Double-invoicing' => 'UCRM already auto-generates invoices monthly. If we ALSO create one on activation, customer gets 2 invoices for month 1. Solution: only create invoice if no current-month invoice exists for this client.',
        'RISK 2: Wrong amount' => 'Splynx tariff price may differ from UCRM service price. Always use UCRM service price, not Splynx price.',
        'RISK 3: Splynx ≠ UCRM client' => 'fiber_customer_map links them. If a client is NOT in the map, we cannot create the UCRM invoice. Currently ' . (is_int($mapCount) ? $mapCount : '?') . ' clients mapped.',
        'RISK 4: Timing race' => 'Splynx fires activated before UCRM service is fully provisioned. Add 30-second delay or retry.',
        'RISK 5: Reinstated customers' => 'Unsuspend (not new activation) should NOT create a new invoice. Must check if service was previously active.',
    ];

    // ── STEP 6: What already handles activation ───────────────────────────────
    $alreadyBuilt = [
        'service.activated webhook' => 'Already handled in webhook.php — sends WA to customer + fires FiberInstallService',
        'fiber_customer_map' => 'Table already exists linking Splynx → UCRM client IDs',
        'UCRM invoice API' => 'crm_auth_token in config gives POST access to /clients/{id}/invoices',
        'Dedup pattern' => 'plugin_kv table already used for dedup keys',
        'WA notification' => 'NotificationService.sendVia() already sends invoice notifications',
    ];

    $ok2([
        'dry_run'              => true,
        'full_flow'            => $flow,
        'splynx_payload_example' => $splynxPayloadExample,
        'ucrm_invoice_api'     => $ucrm_invoice_api,
        'fiber_map_count'      => $mapCount,
        'fiber_map_sample'     => $sampleMapped,
        'risks_to_resolve'     => $risks,
        'already_built'        => $alreadyBuilt,
        'test_client'          => $crmServiceData,
        'would_create_invoice' => $wouldCreate,
        'existing_invoices'    => array_slice($existingInvoices, 0, 3),
        'next_step'            => 'Test with a real client: add &client_id=YOUR_CRM_ID to this URL',
        'deploy_checklist'     => [
            '☐ Confirm UCRM does NOT already auto-invoice on activation (check System → Billing → Invoicing)',
            '☐ Test with client_id= to see what invoice would be created',
            '☐ Decide: create invoice immediately, or next billing cycle?',
            '☐ Decide: prorated first month, or full month?',
            '☐ Confirm crm_auth_token has invoice create permission',
            '☐ Add 30-day dedup: never create > 1 invoice per client per month',
        ],
    ]);
}

// ── Run fiber_customer_map auto-link + show status ───────────────────────────
// ?page=api&action=fiber_map_status       — just show counts
// ?page=api&action=fiber_map_status&run=1 — run auto-map then show counts
if ($act === 'fiber_map_status') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo = $store->getPdo();

    // Current counts
    $total    = (int)$pdo->query("SELECT COUNT(*) FROM fiber_customer_map")->fetchColumn();
    $mapped   = (int)$pdo->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NOT NULL AND crm_client_id != ''")->fetchColumn();
    $unmapped = $total - $mapped;

    // Sample of unmapped — show what data is available to match on
    $unmappedSample = $pdo->query(
        "SELECT splynx_customer_id, splynx_name, splynx_email, splynx_phone
         FROM fiber_customer_map
         WHERE crm_client_id IS NULL OR crm_client_id = ''
         LIMIT 10"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Sample of mapped
    $mappedSample = $pdo->query(
        "SELECT splynx_customer_id, splynx_name, crm_client_id, crm_name, linked_by
         FROM fiber_customer_map
         WHERE crm_client_id IS NOT NULL AND crm_client_id != ''
         LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $autoMapResult = null;
    if (($_GET['run'] ?? '') === '1') {
        try {
            require_once dirname(__DIR__, 2) . '/lib/FiberFinanceEngine.php';
            $ffe = new FiberFinanceEngine($store->getPdo(), $store->load('kyc_config.json') ?: []);
            // Call the sync which internally runs autoMapCustomers
            $autoMapResult = $ffe->syncFromSplynx();
        } catch (\Throwable $e) {
            $autoMapResult = ['error' => $e->getMessage()];
        }
        // Refresh counts after run
        $total    = (int)$pdo->query("SELECT COUNT(*) FROM fiber_customer_map")->fetchColumn();
        $mapped   = (int)$pdo->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NOT NULL AND crm_client_id != ''")->fetchColumn();
        $unmapped = $total - $mapped;
    }

    $ok2([
        'total_in_map'    => $total,
        'crm_linked'      => $mapped,
        'not_linked'      => $unmapped,
        'unmapped_sample' => $unmappedSample,
        'mapped_sample'   => $mappedSample,
        'auto_map_result' => $autoMapResult,
        'next_steps'      => [
            'If splynx_email/phone fields are populated' => 'Run ?page=api&action=fiber_map_status&run=1 to auto-link by email/phone',
            'If fields are empty' => 'The Splynx sync has not pulled contact data yet. Run a full Splynx sync first from Fiber Finance tab.',
            'Manual link' => 'Use Fiber Finance → Customer Map tab to link manually',
        ],
    ]);
}

// ── CRM Client Inspector — pull real services + invoices for learning ─────────
// ?page=api&action=crm_client_inspect&client_id=123
// Shows: client details, services, recent invoices, billing settings
// Safe read-only — creates nothing
if ($act === 'crm_client_inspect') {
    if (!$isAdmin) $er2('Admin only', 403);
    $clientId = (int)($_GET['client_id'] ?? 0);
    if (!$clientId) $er2('client_id required', 422);

    // ── 1. Client basics ──────────────────────────────────────────────────
    $client = $crm->get("clients/{$clientId}") ?? [];
    if (empty($client)) $er2("Client {$clientId} not found in CRM", 404);

    $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
          ?: ($client['companyName'] ?? 'Unknown');
    $contacts = $client['contacts'] ?? [];
    $phone = ''; $email = '';
    foreach ($contacts as $c) {
        if (!$phone && !empty($c['phone']))  $phone = $c['phone'];
        if (!$email && !empty($c['email']))  $email = $c['email'];
    }

    // ── 2. Services ───────────────────────────────────────────────────────
    $services = $crm->get("clients/{$clientId}/services") ?? [];
    $servicesSummary = array_map(fn($s) => [
        'id'           => $s['id'],
        'name'         => $s['name'] ?? 'n/a',
        'status'       => $s['status'] ?? 'n/a',
        'price'        => $s['price'] ?? 0,
        'currencyCode' => $s['currencyCode'] ?? 'USD',
        'activeTo'     => $s['activeTo'] ?? null,
        'activeFrom'   => $s['activeFrom'] ?? null,
        'invoicingStart' => $s['invoicingStart'] ?? null,
        'nextInvoicingDayAdjustment' => $s['nextInvoicingDayAdjustment'] ?? null,
        'invoicingPeriodType' => $s['invoicingPeriodType'] ?? null,
        'invoicingPeriodStartDay' => $s['invoicingPeriodStartDay'] ?? null,
        'hasIndividualPrice' => $s['hasIndividualPrice'] ?? false,
        'raw_keys'     => array_keys($s), // show all available fields
    ], $services);

    // ── 3. Recent invoices ────────────────────────────────────────────────
    $invoices = $crm->get("billing/invoices?clientId={$clientId}&limit=5") ?? [];
    $invoicesSummary = array_map(fn($i) => [
        'id'          => $i['id'],
        'number'      => $i['number'] ?? 'n/a',
        'status'      => match((int)($i['status'] ?? 0)) {
            0 => '0=draft', 1 => '1=unpaid', 2 => '2=partially_paid',
            3 => '3=paid', 4 => '4=void', default => $i['status']
        },
        'total'       => $i['total'] ?? 0,
        'amountDue'   => $i['amountDue'] ?? 0,
        'dueDate'     => $i['dueDate'] ?? null,
        'createdDate' => $i['createdDate'] ?? null,
        'items'       => array_map(fn($it) => [
            'label' => $it['label'] ?? '',
            'price' => $it['price'] ?? 0,
            'quantity' => $it['quantity'] ?? 1,
            'unit'  => $it['unit'] ?? '',
        ], $i['items'] ?? []),
        'raw_keys'    => array_keys($i),
    ], $invoices);

    // ── 4. Fiber map entry ────────────────────────────────────────────────
    $pdo = $store->getPdo();
    $mapRow = $pdo->prepare("SELECT * FROM fiber_customer_map WHERE crm_client_id = ? LIMIT 1");
    $mapRow->execute([$clientId]);
    $fiberMap = $mapRow->fetch(\PDO::FETCH_ASSOC) ?: 'NOT IN FIBER MAP';

    // ── 5. Splynx services cache for this client ──────────────────────────
    $splynxServices = [];
    try {
        if (is_array($fiberMap) && !empty($fiberMap['splynx_customer_id'])) {
            $splynxServices = $pdo->prepare(
                "SELECT * FROM fiber_services_cache WHERE splynx_customer_id = ? LIMIT 5"
            )->execute([$fiberMap['splynx_customer_id']])
                ? $pdo->prepare("SELECT * FROM fiber_services_cache WHERE splynx_customer_id = ? LIMIT 5")
                : null;
            if ($splynxServices) {
                $splynxServices->execute([$fiberMap['splynx_customer_id']]);
                $splynxServices = $splynxServices->fetchAll(\PDO::FETCH_ASSOC);
            }
        }
    } catch (\Throwable $e) { $splynxServices = ['error' => $e->getMessage()]; }

    // ── 6. What invoice WOULD look like if we created one now ─────────────
    $wouldCreateInvoice = null;
    $activeService = null;
    foreach ($services as $s) {
        if (($s['status'] ?? '') === 'active') { $activeService = $s; break; }
    }

    // Check if current-month invoice already exists (double-invoice check)
    $thisMonth = date('Y-m');
    $hasCurrentMonthInvoice = false;
    foreach ($invoices as $inv) {
        $invDate = substr($inv['createdDate'] ?? '', 0, 7);
        if ($invDate === $thisMonth && (int)($inv['status'] ?? 0) !== 4) {
            $hasCurrentMonthInvoice = true;
            break;
        }
    }

    if ($activeService) {
        $wouldCreateInvoice = [
            'would_block' => $hasCurrentMonthInvoice
                ? 'YES — current month invoice already exists (double-invoice prevention)'
                : 'NO — safe to create',
            'has_current_month_invoice' => $hasCurrentMonthInvoice,
            'proposed_endpoint' => "POST /api/v1.0/clients/{$clientId}/invoices",
            'proposed_body' => [
                'items' => [[
                    'label'    => ($activeService['name'] ?? 'Internet Service') . ' — ' . date('F Y'),
                    'quantity' => 1,
                    'price'    => $activeService['price'] ?? 0,
                    'unit'     => 'month',
                ]],
                'maturityDays' => 14,
            ],
            'proposed_total'  => $activeService['price'] ?? 0,
            'proposed_due'    => date('Y-m-d', strtotime('+14 days')),
            'note' => 'DRY RUN — nothing created',
        ];
    }

    // ── 7. Key questions answered for this client ─────────────────────────
    $insights = [
        'invoicing_frequency' => !empty($activeService['invoicingPeriodType'])
            ? 'UCRM auto-invoices this service: period_type=' . $activeService['invoicingPeriodType']
            : 'No invoicingPeriodType found — UCRM may not auto-invoice, or data not returned',
        'invoicing_start' => $activeService['invoicingStart'] ?? 'not set',
        'double_invoice_risk' => $hasCurrentMonthInvoice
            ? '⚠️  RISK: Current month invoice exists — would double-invoice if we create one now'
            : '✅ Safe: No current month invoice found',
        'splynx_linked' => is_array($fiberMap)
            ? '✅ Linked to Splynx ID: ' . ($fiberMap['splynx_customer_id'] ?? '?')
            : '❌ Not in fiber_customer_map — cannot auto-invoice via Splynx trigger',
    ];

    $ok2([
        'client_id'             => $clientId,
        'client_name'           => $name,
        'phone'                 => $phone,
        'email'                 => $email,
        'account_balance'       => $client['accountBalance'] ?? null,
        'account_outstanding'   => $client['accountOutstanding'] ?? null,
        'services'              => $servicesSummary,
        'recent_invoices'       => $invoicesSummary,
        'fiber_map_entry'       => $fiberMap,
        'splynx_services_cache' => $splynxServices,
        'would_create_invoice'  => $wouldCreateInvoice,
        'insights'              => $insights,
        'note' => 'READ ONLY — nothing was created or modified',
    ]);
}

// ── Fiber map: try matching Splynx name (000XXX) to UCRM userIdent ────────────
// splynx_name contains "000001" format = likely UCRM custom ID (userIdent)
// ?page=api&action=fiber_map_match_ident          — dry run, show what would match
// ?page=api&action=fiber_map_match_ident&apply=1  — actually update the map
if ($act === 'fiber_map_match_ident') {
    if (!$isAdmin) $er2('Admin only', 403);
    $apply = ($_GET['apply'] ?? '0') === '1';
    $pdo   = $store->getPdo();

    // Get all unmapped Splynx rows where name looks like 000XXX
    $unmapped = $pdo->query(
        "SELECT splynx_customer_id, splynx_name FROM fiber_customer_map
         WHERE (crm_client_id IS NULL OR crm_client_id = '')
           AND splynx_name REGEXP '^0+[0-9]+$'
         LIMIT 200"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // SQLite doesn't support REGEXP — use LIKE instead
    if (empty($unmapped)) {
        $unmapped = $pdo->query(
            "SELECT splynx_customer_id, splynx_name FROM fiber_customer_map
             WHERE (crm_client_id IS NULL OR crm_client_id = '')
             LIMIT 200"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    if (empty($unmapped)) $er2('No unmapped rows found', 404);

    // Fetch all UCRM clients (paginated)
    $allCrmClients = [];
    $page = 1;
    do {
        $batch = $crm->get("clients?limit=100&offset=" . (($page-1)*100)) ?? [];
        $allCrmClients = array_merge($allCrmClients, $batch);
        $page++;
    } while (count($batch) === 100 && $page <= 20);

    // Build lookup: userIdent → client
    $byIdent = [];
    $byName  = [];
    foreach ($allCrmClients as $cc) {
        $ident = trim($cc['userIdent'] ?? '');
        $fname = strtolower(trim(($cc['firstName'] ?? '') . ' ' . ($cc['lastName'] ?? '')));
        $cname = strtolower(trim($cc['companyName'] ?? ''));
        if ($ident) $byIdent[$ident] = $cc;
        if ($fname) $byName[$fname]  = $cc;
        if ($cname) $byName[$cname]  = $cc;
    }

    $matches    = [];
    $noMatches  = [];

    foreach ($unmapped as $row) {
        $splynxId   = $row['splynx_customer_id'];
        $splynxName = trim($row['splynx_name'] ?? '');

        $matched = null; $method = '';

        // Try exact userIdent match (e.g. "000001" → userIdent "000001")
        if (isset($byIdent[$splynxName])) {
            $matched = $byIdent[$splynxName];
            $method  = 'userIdent_exact';
        }
        // Try stripping leading zeros (000170 → 170 → userIdent "170")
        elseif (isset($byIdent[ltrim($splynxName, '0')])) {
            $matched = $byIdent[ltrim($splynxName, '0')];
            $method  = 'userIdent_no_leading_zeros';
        }
        // Try with leading zeros padded to 6 digits
        elseif (isset($byIdent[str_pad((string)(int)$splynxName, 6, '0', STR_PAD_LEFT)])) {
            $matched = $byIdent[str_pad((string)(int)$splynxName, 6, '0', STR_PAD_LEFT)];
            $method  = 'userIdent_padded';
        }

        if ($matched) {
            $crmClientId = (int)($matched['id'] ?? 0);
            $crmName = trim(($matched['firstName'] ?? '') . ' ' . ($matched['lastName'] ?? ''))
                     ?: ($matched['companyName'] ?? '');
            $matches[] = [
                'splynx_id'    => $splynxId,
                'splynx_name'  => $splynxName,
                'crm_client_id'=> $crmClientId,
                'crm_name'     => $crmName,
                'crm_ident'    => $matched['userIdent'] ?? '',
                'method'       => $method,
            ];

            if ($apply && $crmClientId > 0) {
                $pdo->prepare(
                    "UPDATE fiber_customer_map
                     SET crm_client_id=?, crm_name=?, linked_by='auto_ident', linked_at=datetime('now')
                     WHERE splynx_customer_id=?"
                )->execute([$crmClientId, $crmName, $splynxId]);
            }
        } else {
            $noMatches[] = ['splynx_id' => $splynxId, 'splynx_name' => $splynxName];
        }
    }

    // Refresh counts
    $nowLinked = (int)$pdo->query("SELECT COUNT(*) FROM fiber_customer_map WHERE crm_client_id IS NOT NULL AND crm_client_id != ''")->fetchColumn();

    $ok2([
        'dry_run'         => !$apply,
        'crm_clients_fetched' => count($allCrmClients),
        'unmapped_checked'=> count($unmapped),
        'matched'         => count($matches),
        'not_matched'     => count($noMatches),
        'match_preview'   => array_slice($matches, 0, 20),
        'no_match_sample' => array_slice($noMatches, 0, 10),
        'now_linked_total'=> $nowLinked,
        'message' => $apply
            ? 'APPLIED — linked ' . count($matches) . ' Splynx customers to CRM clients'
            : 'DRY RUN — would link ' . count($matches) . ' of ' . count($unmapped) . ' unmapped rows. Add &apply=1 to commit.',
    ]);
}

// ── Deep CRM inspect: services via multiple API paths + raw invoice structure ──
// ?page=api&action=crm_deep_inspect&client_id=1304
if ($act === 'crm_deep_inspect') {
    if (!$isAdmin) $er2('Admin only', 403);
    $clientId = (int)($_GET['client_id'] ?? 0);
    if (!$clientId) $er2('client_id required', 422);

    $result = [];

    // Try multiple service API paths — UCRM has changed these across versions
    $paths = [
        "clients/{$clientId}/services",
        "clients-services?clientId={$clientId}",
        "clients/{$clientId}",
    ];
    foreach ($paths as $path) {
        try {
            $data = $crm->get($path);
            $result['api_paths'][$path] = [
                'count'    => is_array($data) ? count($data) : 'not array',
                'type'     => gettype($data),
                'preview'  => is_array($data) ? array_slice($data, 0, 2) : $data,
            ];
        } catch (\Throwable $e) {
            $result['api_paths'][$path] = ['error' => $e->getMessage()];
        }
    }

    // Raw invoice structure — what does a real DishNet fiber invoice look like?
    $recentInvoices = $crm->get("billing/invoices?limit=5&statuses[]=1&statuses[]=2&statuses[]=3") ?? [];
    $result['recent_any_invoice_sample'] = array_map(fn($i) => [
        'id'          => $i['id'],
        'number'      => $i['number'] ?? '',
        'clientId'    => $i['clientId'] ?? '',
        'total'       => $i['total'] ?? 0,
        'status'      => $i['status'] ?? '',
        'createdDate' => $i['createdDate'] ?? '',
        'items'       => $i['items'] ?? [],
        'taxable'     => $i['taxable'] ?? false,
    ], array_slice($recentInvoices, 0, 3));

    // Check if this client has invoices AT ALL across all statuses
    $allStatuses = [];
    foreach ([0,1,2,3,4] as $st) {
        $inv = $crm->get("billing/invoices?clientId={$clientId}&statuses[]={$st}&limit=3") ?? [];
        if (!empty($inv)) {
            $allStatuses[$st] = array_map(fn($i) => [
                'id'     => $i['id'],
                'number' => $i['number'] ?? '',
                'total'  => $i['total'] ?? 0,
                'date'   => $i['createdDate'] ?? '',
                'items'  => array_map(fn($it) => $it['label'] ?? '', $i['items'] ?? []),
            ], $inv);
        }
    }
    $result['client_invoices_all_statuses'] = empty($allStatuses)
        ? 'NO INVOICES FOUND IN ANY STATUS for this client'
        : $allStatuses;

    // Check splynx map for this client's userIdent
    $pdo   = $store->getPdo();
    $client = $crm->get("clients/{$clientId}") ?? [];
    $ident  = $client['userIdent'] ?? '';

    $splynxRow = null;
    if ($ident) {
        // Look for splynx_name matching this ident (with/without leading zeros)
        $variants = [$ident, ltrim($ident,'0'), str_pad((string)(int)$ident,6,'0',STR_PAD_LEFT)];
        foreach ($variants as $v) {
            $r = $pdo->prepare("SELECT * FROM fiber_customer_map WHERE splynx_name=? LIMIT 1");
            $r->execute([$v]);
            $row = $r->fetch(\PDO::FETCH_ASSOC);
            if ($row) { $splynxRow = $row; $splynxRow['_matched_variant'] = $v; break; }
        }
    }

    $result['client_ident']          = $ident;
    $result['splynx_map_by_ident']   = $splynxRow ?: 'No fiber_customer_map row found for ident=' . $ident;
    $result['conclusion'] = [
        'has_ucrm_services'  => !empty($result['api_paths']["clients/{$clientId}/services"]['count'])
                                && $result['api_paths']["clients/{$clientId}/services"]['count'] > 0
                                ? 'YES' : 'NO — fiber clients likely have no UCRM service records',
        'has_any_invoices'   => !empty($allStatuses) ? 'YES' : 'NO',
        'in_splynx_map'      => $splynxRow ? 'YES — Splynx ID: ' . ($splynxRow['splynx_customer_id'] ?? '?') : 'NO',
        'invoice_approach'   => empty($allStatuses)
            ? 'Invoices are created MANUALLY by Rupesh — UCRM does not auto-invoice these clients'
            : 'UCRM has invoice history — check items to see price structure',
    ];

    $ok2($result);
}

// ── Fiber invoice architecture inspector ─────────────────────────────────────
// Reads crm_enrich_cache + pulls a real fiber client with invoice history
// ?page=api&action=fiber_invoice_arch
if ($act === 'fiber_invoice_arch') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo = $store->getPdo();

    // 1. Read the existing enrichment cache for known client links
    $cache = $store->load('crm_enrich_cache.json') ?? [];
    $ticketToCrm = $cache['ticket_to_crm'] ?? [];
    $cacheAge    = isset($cache['cached_at'])
        ? round((time()-strtotime($cache['cached_at']))/3600, 1) . ' hours old'
        : 'no cache';

    // 2. Get a sample of known fiber clients from the cache
    $clientsById = $cache['clients_by_id'] ?? [];
    $fiberClients = [];
    foreach ($clientsById as $cid => $c) {
        $name = trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''))
              ?: ($c['companyName'] ?? '');
        // Only pick clients whose name or username suggests fiber/FTTH
        $username = strtolower($c['username'] ?? '');
        $nameL = strtolower($name);
        if (str_contains($username, 'ftth') || str_contains($nameL, 'ftth')
            || !empty($c['userIdent'])) {
            $fiberClients[$cid] = [
                'id' => $cid, 'name' => $name,
                'username' => $c['username'] ?? '',
                'userIdent' => $c['userIdent'] ?? '',
                'attrs' => $c['attributes'] ?? [],
            ];
            if (count($fiberClients) >= 10) break;
        }
    }

    // 3. Pull real recent invoices — find ones that look like FTTH
    $recentPaid = $crm->get("billing/invoices?limit=20&statuses[]=3") ?? [];
    $ftthInvoices = [];
    foreach ($recentPaid as $inv) {
        foreach ($inv['items'] ?? [] as $item) {
            $label = strtolower($item['label'] ?? '');
            if (str_contains($label, 'ftth') || str_contains($label, 'fiber')
                || str_contains($label, 'optical')) {
                $ftthInvoices[] = [
                    'invoice_id'  => $inv['id'],
                    'number'      => $inv['number'],
                    'client_id'   => $inv['clientId'],
                    'total'       => $inv['total'],
                    'created'     => $inv['createdDate'],
                    'due'         => $inv['dueDate'],
                    'item_label'  => $item['label'],
                    'item_price'  => $item['price'],
                    'item_qty'    => $item['quantity'],
                    'item_unit'   => $item['unit'] ?? '',
                ];
                break;
            }
        }
        if (count($ftthInvoices) >= 5) break;
    }

    // If no paid, try unpaid
    if (empty($ftthInvoices)) {
        $unpaid = $crm->get("billing/invoices?limit=20&statuses[]=1") ?? [];
        foreach ($unpaid as $inv) {
            foreach ($inv['items'] ?? [] as $item) {
                $label = strtolower($item['label'] ?? '');
                if (str_contains($label,'ftth') || str_contains($label,'fiber')
                    || str_contains($label,'internet') || str_contains($label,'monthly')) {
                    $ftthInvoices[] = [
                        'invoice_id'=> $inv['id'],
                        'number'    => $inv['number'],
                        'client_id' => $inv['clientId'],
                        'total'     => $inv['total'],
                        'created'   => $inv['createdDate'],
                        'due'       => $inv['dueDate'],
                        'item_label'=> $item['label'],
                        'item_price'=> $item['price'],
                        'item_qty'  => $item['quantity'],
                        'item_unit' => $item['unit'] ?? '',
                    ];
                    break;
                }
            }
            if (count($ftthInvoices) >= 5) break;
        }
    }

    // 4. If we found a real fiber invoice, deep-inspect that client
    $fiberClientDetail = null;
    if (!empty($ftthInvoices)) {
        $sampleClientId = $ftthInvoices[0]['client_id'];
        $sc = $crm->get("clients/{$sampleClientId}") ?? [];
        $fiberClientDetail = [
            'client_id'   => $sampleClientId,
            'name'        => trim(($sc['firstName']??'').' '.($sc['lastName']??'')) ?: ($sc['companyName']??''),
            'userIdent'   => $sc['userIdent'] ?? null,
            'username'    => $sc['username'] ?? null,
            'attributes'  => $sc['attributes'] ?? [],
            'services'    => $crm->get("clients/{$sampleClientId}/services") ?? [],
            'in_fiber_map'=> (function() use($pdo, $sampleClientId) {
                $r = $pdo->prepare("SELECT * FROM fiber_customer_map WHERE crm_client_id=? LIMIT 1");
                $r->execute([$sampleClientId]);
                return $r->fetch(\PDO::FETCH_ASSOC) ?: 'NOT IN MAP';
            })(),
        ];
    }

    // 5. Splynx→CRM map approach summary
    $splynxMapRows = $pdo->query(
        "SELECT splynx_customer_id, splynx_name, crm_client_id, crm_name, linked_by
         FROM fiber_customer_map WHERE crm_client_id IS NOT NULL LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $ok2([
        'crm_cache_age'          => $cacheAge,
        'ticket_to_crm_count'    => count($ticketToCrm),
        'fiber_clients_in_cache' => array_values($fiberClients),
        'ftth_invoices_found'    => $ftthInvoices,
        'fiber_client_detail'    => $fiberClientDetail,
        'fiber_map_linked_sample'=> $splynxMapRows,
        'architecture_conclusion'=> [
            'how_splynx_links_to_ucrm' => 'UCRM client has custom attribute "splynxId" OR ticketId. SplynxTicketService uses ticketId. fiber_customer_map is separate and currently empty (crm_client_id=null for all 180 rows).',
            'how_invoices_are_created' => 'Manually by Rupesh in UCRM. No auto-invoicing exists yet.',
            'price_source'             => 'From Splynx tariff (UCRM has no service records for fiber clients).',
            'what_we_need_to_build'    => '1) Fix fiber_customer_map linking. 2) On Splynx service.activated webhook: find UCRM client_id via ticketId or splynxId attr, create UCRM invoice using Splynx tariff price, send WA.',
            'safest_link_method'       => 'UCRM client attribute "splynxId" — if populated. Fallback: ticketId attr → fiber_customer_map.',
        ],
        'note' => 'READ ONLY',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// STARLINK MANUAL SUSPEND TOOLING (admin-only, v4.21.21)
//
// Two endpoints that let an admin discover and act on UCRM-suspended Starlink
// customers who haven't been auto-blocked yet (e.g. deployed before v4.21.0
// auto-block was working — see v4.21.17 changelog for that diagnosis).
//
// 1. GET  ?page=api&action=sl_audit_suspended
//    Returns one row per UCRM-suspended client with: VIP status, KIT count,
//    already-blocked status, and a 'blockable' flag (suspended + non-VIP +
//    has KIT + not already blocked). READ-ONLY. No side effects.
//
// 2. POST ?page=api&action=sl_manual_suspend
//    Body: {client_id: int, mode: 'pause_only'|'full' (default pause_only)}
//    Calls StarlinkBlockService::suspend() — same code path as webhook
//    service.suspend. State row written, audit logged, restore-on-payment
//    works automatically. Idempotent (skips if already in suspended state).
// ═════════════════════════════════════════════════════════════════════════════

if ($act === 'sl_audit_suspended' && $met === 'GET') {
    $_slRole = $retailer['role'] ?? '';
    if (!$isAdmin && !in_array($_slRole, ['accountant', 'support_leader'], true)) $er2('Access restricted.', 403);

    $debug = !empty($_GET['debug']);

    if (!$crm->isConfigured()) $er2('UCRM API not configured. Check Settings → System.', 503);

    // ── Load support data ────────────────────────────────────────────────────
    $clientCache = $store->load('ucrm_clients_cache.json')  ?? [];
    $clientById  = [];
    foreach ($clientCache as $c) {
        $cid = (int)($c['id'] ?? 0);
        if ($cid > 0) $clientById[$cid] = $c;
    }

    // ── Fetch suspended services LIVE from UCRM ──────────────────────────────
    // UCRM service status codes (authoritative — UISP REST API spec):
    //   0=Prepared, 1=Active, 2=Ended, 3=Suspended (admin), 4=Suspended (no pay),
    //   5=Quoted, 6=Inactive, 7=Cancelled, 8=Obsolete
    // We want 3 + 4 — both "Suspended" variants.
    //
    // Try the proper REST filter first, fall back to fetching all + filtering
    // client-side if the API rejects the filter syntax.
    $rawSuspendedServices = null;
    $apiStrategy = '';

    foreach (['statuses[]=3&statuses[]=4', 'status[]=3&status[]=4'] as $filter) {
        $svcRaw = $crm->get("clients/services?{$filter}&limit=500");
        if (is_array($svcRaw) && !empty($svcRaw)) {
            $rawSuspendedServices = $svcRaw;
            $apiStrategy = "filter:{$filter}";
            break;
        }
    }

    // Fallback: fetch everything and filter ourselves (slower but bulletproof)
    if ($rawSuspendedServices === null) {
        $allSvc = $crm->get('clients/services?limit=2000');
        if (is_array($allSvc)) {
            $rawSuspendedServices = array_values(array_filter($allSvc, function ($s) {
                $st = (int)($s['status'] ?? 0);
                return $st === 3 || $st === 4;
            }));
            $apiStrategy = 'fallback:fetch_all_filter_local';
        }
    }

    if (!is_array($rawSuspendedServices)) {
        $err = $crm->getLastError();
        $er2('UCRM API call failed: ' . (is_array($err) ? json_encode($err) : (string)$err), 502);
    }

    // Group suspended services by client_id
    $suspendedByClient = []; // client_id => array of service rows
    $statusHist = [];
    // ── Filter: only Starlink services ───────────────────────────────────────
    // We only want suspended services that are actually Starlink — FTTH/LTE
    // customers are on a different infrastructure (MikroTik/OLT) and should
    // never appear here. Two signals make a service "Starlink-relevant":
    //   (a) Service name contains a KIT serial pattern (operator's title
    //       convention is "Site : Customer (KITxxxxx) : Service Plan ...")
    //   (b) servicePlanName contains "starlink" (case-insensitive)
    // A service must match at least ONE of these to be included. This filters
    // out FTTH (e.g. "Service Plan FTTH50"), LTE, and other non-Starlink
    // suspended services without affecting genuine Starlink suspensions
    // missing a KIT in their title (those still match (b) by plan name).
    $kitRegexFilter = '/\bKIT[A-Z0-9]{8,}\b/i';
    $nonStarlinkSkipped = 0;
    $rawSuspendedStarlink = [];
    foreach ($rawSuspendedServices as $svc) {
        $name = (string)($svc['name'] ?? '');
        $plan = (string)($svc['servicePlanName'] ?? '');
        $hasKit = preg_match($kitRegexFilter, $name) === 1;
        $isStarlinkPlan = stripos($plan, 'starlink') !== false || stripos($name, 'starlink') !== false;
        if ($hasKit || $isStarlinkPlan) {
            $rawSuspendedStarlink[] = $svc;
        } else {
            $nonStarlinkSkipped++;
        }
    }
    $rawSuspendedServices = $rawSuspendedStarlink;

    foreach ($rawSuspendedServices as $svc) {
        $cid = (int)($svc['clientId'] ?? 0);
        if ($cid <= 0) continue;
        $st  = (int)($svc['status'] ?? 0);
        $statusHist[$st] = ($statusHist[$st] ?? 0) + 1;
        $suspendedByClient[$cid] = $suspendedByClient[$cid] ?? [];
        $suspendedByClient[$cid][] = [
            'service_id' => (int)($svc['id'] ?? 0),
            'name'       => (string)($svc['name'] ?? $svc['servicePlanName'] ?? ''),
            'status'     => $st,
            'plan_id'    => (int)($svc['servicePlanId'] ?? 0),
            'plan_name'  => (string)($svc['servicePlanName'] ?? ''),
        ];
    }

    // ── Debug mode: collect basic counts; full debug payload returned after
    //     kit-loading so we can include kit-source diagnostics too. ──────────
    $debugEarly = null;
    if ($debug) {
        $sampleSuspendedServices = array_slice($rawSuspendedServices, 0, 8);
        $sampleSlim = array_map(function ($s) {
            return [
                'id'         => $s['id'] ?? null,
                'clientId'   => $s['clientId'] ?? null,
                'status'     => $s['status'] ?? null,
                'name'       => $s['name'] ?? '',
                'servicePlanName' => $s['servicePlanName'] ?? '',
            ];
        }, $sampleSuspendedServices);
        $debugEarly = [
            'api_strategy'               => $apiStrategy,
            'raw_suspended_services'     => count($rawSuspendedServices),
            'distinct_suspended_clients' => count($suspendedByClient),
            'service_status_histogram'   => $statusHist,
            'sample_services'            => $sampleSlim,
        ];
    }

    // ── VIP guard config ─────────────────────────────────────────────────────
    $vipTagId        = (int)($config['starlink_block_vip_tag_id']   ?? 84);
    $vipTagName      = (string)($config['starlink_block_vip_tag_name'] ?? 'NO_AUTO_BLOCK');
    $vipExplicitRaw  = $config['starlink_block_vip_clients'] ?? '';
    $vipExplicit     = [];
    if (is_array($vipExplicitRaw)) {
        foreach ($vipExplicitRaw as $v) $vipExplicit[(int)$v] = true;
    } elseif (is_string($vipExplicitRaw) && $vipExplicitRaw !== '') {
        foreach (preg_split('/[,\s]+/', $vipExplicitRaw) as $v) {
            $vid = (int)trim($v);
            if ($vid > 0) $vipExplicit[$vid] = true;
        }
    }

    $checkVip = function (array $client) use ($vipTagId, $vipTagName, $vipExplicit): array {
        $cid = (int)($client['id'] ?? 0);
        if (isset($vipExplicit[$cid])) {
            return ['is_vip' => true, 'reason' => 'explicit_config_list'];
        }
        $tags = $client['attributes'] ?? $client['tags'] ?? $client['clientTags'] ?? [];
        if (!is_array($tags)) $tags = [];
        foreach ($tags as $t) {
            $tid   = (int)($t['id']   ?? $t['tagId']   ?? 0);
            $tname = (string)($t['name'] ?? $t['tagName'] ?? '');
            if ($tid > 0 && $tid === $vipTagId)               return ['is_vip' => true, 'reason' => "tag_id={$tid}"];
            if ($tname !== '' && $tname === $vipTagName)      return ['is_vip' => true, 'reason' => "tag_name={$tname}"];
        }
        return ['is_vip' => false, 'reason' => ''];
    };

    // ── Load Starlink kits map ───────────────────────────────────────────────
    // Two signal sources:
    //   (A) sl_kits.json — Starlink Finance plugin's managed inventory
    //   (B) UCRM service.name regex — looks for KIT serials embedded in
    //       service titles like "Site : ACME (KIT401723651PG7) : ..."
    //       This is reliable because whoever provisions the service in UCRM
    //       puts the KIT in the title — visible in the screenshot data.
    //
    // (B) wins on field robustness, (A) wins when service names don't
    // contain the KIT. We union both and dedupe.
    $kitsByClient = [];
    $kitsSource   = []; // client_id => 'json' | 'service_name' | 'both'

    // Source A: sl_kits.json
    $kitsJsonLoaded = false;
    $kitsJsonPath   = '';
    $kitsJsonCount  = 0;
    // v4.21.63 — kitFinanceByKit holds the FULL row from sl_kits.json keyed
    // by uppercase KIT serial. Used for cliff computation (real billing-cycle
    // source) AFTER the per-customer KIT extraction loop completes.
    $kitFinanceByKit = [];
    foreach ([
        dirname(__DIR__, 3) . '/dishnet-starlink-finance/data/sl_kits.json',
        dirname(__DIR__, 2) . '/../dishnet-starlink-finance/data/sl_kits.json',
    ] as $p) {
        if (file_exists($p)) {
            $raw = @file_get_contents($p);
            if ($raw !== false) {
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    $kitsJsonLoaded = true;
                    $kitsJsonPath   = $p;
                    $kitsJsonCount  = count($j);
                    foreach ($j as $key => $val) {
                        if (!is_array($val)) continue;
                        // Try every plausible client-id field name
                        $cid = (int)(
                            $val['client_id']
                            ?? $val['crm_client_id']
                            ?? $val['ucrm_client_id']
                            ?? $val['clientId']
                            ?? $val['crmClientId']
                            ?? $val['customer_id']
                            ?? $val['customerId']
                            ?? $val['assigned_client_id']
                            ?? $val['assigned_to']
                            ?? 0
                        );
                        if ($cid <= 0) continue;
                        $ks = (string)(
                            $val['kit_serial']
                            ?? $val['kit_number']    // v4.21.65 — Finance plugin's actual field name
                            ?? $val['kit']
                            ?? $val['serial']
                            ?? $val['kitSerial']
                            ?? (is_string($key) ? $key : '')
                        );
                        if ($ks !== '') {
                            $kitsByClient[$cid][] = $ks;
                            $kitsSource[$cid] = 'json';
                            // v4.21.63 — also retain full Finance row keyed by
                            // KIT serial. Used later for cliff computation
                            // since this row holds account_number, billing
                            // cycle dates, plan info — much richer than the
                            // data-report sl_svc_cache.json fallback.
                            $kitFinanceByKit[strtoupper($ks)] = $val;
                        }
                    }
                    break;
                }
            }
        }
    }

    // Source B: extract from UCRM service.name regex
    // Matches KIT serials like:
    //   KIT401723651PG7  (12+ alnum after KIT, no dash)
    //   KIT4M00366138    (4M-style)
    //   KIT4M0190361xxx
    // Pattern: "KIT" + at least 8 alphanumeric chars
    $kitRegex = '/\bKIT[A-Z0-9]{8,}\b/i';
    foreach ($suspendedByClient as $cid => $svcs) {
        $foundFromName = [];
        foreach ($svcs as $svc) {
            $name = (string)($svc['name'] ?? '');
            if ($name === '') continue;
            if (preg_match_all($kitRegex, $name, $m)) {
                foreach ($m[0] as $kit) {
                    $foundFromName[] = strtoupper(trim($kit));
                }
            }
        }
        $foundFromName = array_values(array_unique($foundFromName));
        if (!empty($foundFromName)) {
            if (isset($kitsByClient[$cid])) {
                // Merge json + service-name kits, dedupe
                $merged = array_values(array_unique(array_merge($kitsByClient[$cid], $foundFromName)));
                $kitsByClient[$cid] = $merged;
                $kitsSource[$cid]   = 'both';
            } else {
                $kitsByClient[$cid] = $foundFromName;
                $kitsSource[$cid]   = 'service_name';
            }
        }
    }

    // ── Debug mode emit (after kit loading, includes kit-source diagnostics) ─
    if ($debug) {
        // Count by kit source
        $sourceCounts = ['json' => 0, 'service_name' => 0, 'both' => 0, 'none' => 0];
        foreach ($suspendedByClient as $cid => $_) {
            $sourceCounts[$kitsSource[$cid] ?? 'none']++;
        }
        // Sample of kits-by-client to see what we resolved per customer
        $kitSamples = [];
        $sampleN = 0;
        foreach ($suspendedByClient as $cid => $_) {
            if ($sampleN >= 8) break;
            $kitSamples[] = [
                'client_id' => $cid,
                'kits'      => $kitsByClient[$cid] ?? [],
                'source'    => $kitsSource[$cid] ?? 'none',
            ];
            $sampleN++;
        }

        // Inspect wifi_router_map.json (dishnet-data-report) — needed for block to
        // actually run gRPC calls. KITs found above have to translate to router IDs.
        $routerMapPath   = '';
        $routerMapTotal  = 0;
        $routerMapKitsMatched = 0;
        foreach ([
            dirname(__DIR__, 3) . '/dishnet-data-report/data/wifi_router_map.json',
            dirname(__DIR__, 2) . '/../dishnet-data-report/data/wifi_router_map.json',
        ] as $rp) {
            if (file_exists($rp)) {
                $routerMapPath = $rp;
                $rmRaw = @file_get_contents($rp);
                if ($rmRaw !== false) {
                    $rm = json_decode($rmRaw, true);
                    if (is_array($rm)) {
                        $routerMapTotal = count($rm);
                        // Build a set of router-map KITs (uppercase, trimmed)
                        $rmKitSet = [];
                        foreach ($rm as $rid => $rinfo) {
                            $ks = strtoupper(trim((string)($rinfo['kit_serial'] ?? '')));
                            if ($ks !== '') $rmKitSet[$ks] = true;
                        }
                        // Count how many of our suspended-customer KITs match
                        foreach ($kitsByClient as $cid => $kits) {
                            if (!isset($suspendedByClient[$cid])) continue;
                            foreach ($kits as $k) {
                                if (isset($rmKitSet[strtoupper(trim($k))])) {
                                    $routerMapKitsMatched++;
                                    break; // one match per client is enough
                                }
                            }
                        }
                    }
                }
                break;
            }
        }

        $ok2(array_merge($debugEarly, [
            'mode'                  => 'debug',
            'kits_json_loaded'      => $kitsJsonLoaded,
            'kits_json_path'        => $kitsJsonPath,
            'kits_json_total'       => $kitsJsonCount,
            'kits_by_source'        => $sourceCounts,
            'kits_sample_per_client'=> $kitSamples,
            'router_map_path'       => $routerMapPath,
            'router_map_total'      => $routerMapTotal,
            'router_map_kits_matched_for_suspended' => $routerMapKitsMatched,
            'note'                  => 'Status 3 = admin suspend, 4 = no-payment suspend. KIT source: json (sl_kits.json), service_name (regex on UCRM service.name), both, none. router_map_kits_matched_for_suspended = how many suspended customers have at least one KIT that resolves to a router in dishnet-data-report — block can only succeed for these.',
        ]));
    }

    // ── Already-blocked check ────────────────────────────────────────────────
    // Two sources:
    //   (A) Hybrid's own sl_suspension_state table — webhook-driven blocks
    //   (B) Data Report's wifi_test_block_state.json — manual / Block Manager
    //       blocks (this is where Pauses Tab + console script writes to)
    // We OR the two — if a client appears in either source, mark as already
    // blocked. (B) is the more important source since the webhook-driven
    // path through StarlinkBlockService isn't currently functional (v4.22 plan).
    $alreadyBlocked = [];
    try {
        $pdo2 = $store->getPdo();
        $stmt = $pdo2->query("SELECT DISTINCT client_id FROM sl_suspension_state WHERE state IN ('suspending','suspended','partial_suspend_failed','restoring','error_manual_required')");
        if ($stmt) {
            while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $alreadyBlocked[(int)$r['client_id']] = true;
            }
        }
    } catch (\Throwable $e) { /* table may not exist yet — fine */ }

    // Cross-reference with data-report's actual paused state. Read both
    // wifi_test_block_state.json (router_ids currently paused) and
    // wifi_router_map.json (router_id → kit_serial). For each suspended
    // client, if any of their KITs maps to a router that's currently paused,
    // mark as already_blocked.
    try {
        $drPaths = [
            'state' => null,
            'map'   => null,
        ];
        foreach ([
            dirname(__DIR__, 3) . '/dishnet-data-report/data/wifi_test_block_state.json',
            dirname(__DIR__, 2) . '/../dishnet-data-report/data/wifi_test_block_state.json',
        ] as $p) {
            if (file_exists($p)) { $drPaths['state'] = $p; break; }
        }
        foreach ([
            dirname(__DIR__, 3) . '/dishnet-data-report/data/wifi_router_map.json',
            dirname(__DIR__, 2) . '/../dishnet-data-report/data/wifi_router_map.json',
        ] as $p) {
            if (file_exists($p)) { $drPaths['map'] = $p; break; }
        }

        if ($drPaths['state'] && $drPaths['map']) {
            $blockedRouters = json_decode((string)@file_get_contents($drPaths['state']), true) ?: [];
            $routerMap = json_decode((string)@file_get_contents($drPaths['map']), true) ?: [];

            // Build set of currently-paused KIT serials (uppercase, trimmed)
            $pausedKits = [];
            foreach ($blockedRouters as $routerId => $stateRow) {
                // Strip "Router-" prefix to match wifi_router_map keying
                $rawId = (strpos($routerId, 'Router-') === 0) ? substr($routerId, 7) : (string)$routerId;
                $rmEntry = $routerMap[$rawId] ?? null;
                if (!is_array($rmEntry)) continue;
                $kit = strtoupper(trim((string)($rmEntry['kit_serial'] ?? '')));
                if ($kit !== '') $pausedKits[$kit] = true;
            }

            // For each suspended client, if any of their KITs is in the
            // paused-KITs set, mark as already_blocked.
            foreach ($suspendedByClient as $cid => $_) {
                if (isset($alreadyBlocked[$cid])) continue;
                $clientKits = $kitsByClient[$cid] ?? [];
                foreach ($clientKits as $kit) {
                    if (isset($pausedKits[strtoupper(trim((string)$kit))])) {
                        $alreadyBlocked[$cid] = true;
                        break;
                    }
                }
            }
        }
    } catch (\Throwable $e) { /* best effort */ }

    // ── Load Starlink subscription cache from data-report ────────────────────
    // v4.21.59: data-report's hourly cron writes per-service-line subscription
    // data (endDate / active / canResumeService / status / pendingActivation)
    // to dishnet-data-report/data/sl_svc_cache.json keyed by service line
    // number. We load it here and build a KIT-serial → SL-row index so each
    // suspended-customer row can be enriched with "scheduled to end on X" and
    // "Y days until cliff" — same date Starlink shows in its account portal.
    // If the cache file is missing or stale, rows degrade gracefully (cliff
    // fields just become null and the UI shows "—" instead of a date).
    $slSvcByKit = [];
    $slSvcCacheStale = false;
    $slSvcCacheLoadedCount = 0;
    foreach ([
        dirname(__DIR__, 3) . '/dishnet-data-report/data/sl_svc_cache.json',
        dirname(__DIR__, 2) . '/../dishnet-data-report/data/sl_svc_cache.json',
    ] as $slCachePath) {
        if (!file_exists($slCachePath)) continue;
        $age = time() - @filemtime($slCachePath);
        if ($age > 7200) $slSvcCacheStale = true; // older than 2h = warn
        $slRaw = @file_get_contents($slCachePath);
        if ($slRaw === false) continue;
        $slCache = json_decode($slRaw, true);
        if (!is_array($slCache)) continue;
        $slSvcCacheLoadedCount = count($slCache);
        foreach ($slCache as $slNum => $slRow) {
            if (!is_array($slRow)) continue;
            $kit = (string)($slRow['kit_number'] ?? '');
            if ($kit !== '') {
                $slSvcByKit[$kit] = $slRow + ['service_line' => $slNum];
            }
        }
        break;
    }

    // ── Build rows ───────────────────────────────────────────────────────────
    $rows = [];
    $totals = [
        'suspended_total'     => count($suspendedByClient),
        'with_kit'            => 0,
        'vip_skip'            => 0,
        'already_blocked'     => 0,
        'blockable'           => 0,
        'non_starlink_skipped'=> $nonStarlinkSkipped,
        'sl_cliff_imminent'   => 0,  // ≤7 days until Starlink cuts service
    ];

    foreach ($suspendedByClient as $cid => $suspendedSvcs) {
        $cClient = $clientById[$cid] ?? null;

        // If client not in cache, we still want the row but with limited info.
        // Try to fetch live as a last resort for the customer-facing fields.
        if (!$cClient) {
            $live = $crm->get("clients/{$cid}");
            if (is_array($live)) {
                $cClient = $live;
                $clientById[$cid] = $live;
            } else {
                $cClient = ['id' => $cid];
            }
        }

        $name = trim((string)($cClient['companyName'] ?? '')
                     ?: trim((string)($cClient['firstName'] ?? '') . ' ' . (string)($cClient['lastName'] ?? ''))
                     ?: ('Client #' . $cid));

        // Phone — try contacts first, then top-level
        $phone = '';
        if (!empty($cClient['contacts']) && is_array($cClient['contacts'])) {
            foreach ($cClient['contacts'] as $ct) {
                if (!empty($ct['phone'])) { $phone = (string)$ct['phone']; break; }
                if (!empty($ct['phones'][0]['number'])) { $phone = (string)$ct['phones'][0]['number']; break; }
            }
        }
        if ($phone === '') $phone = (string)($cClient['phone'] ?? $cClient['mobile'] ?? '');

        $balance = (float)($cClient['accountBalance'] ?? 0);
        $plans   = implode(', ', array_filter(array_unique(array_column($suspendedSvcs, 'name'))));

        $vip     = $checkVip($cClient);
        $kits    = $kitsByClient[$cid] ?? [];
        $blocked = isset($alreadyBlocked[$cid]);

        if (!empty($kits)) $totals['with_kit']++;
        if ($vip['is_vip']) $totals['vip_skip']++;
        if ($blocked) $totals['already_blocked']++;

        $blockable = !$vip['is_vip'] && !$blocked && !empty($kits);
        if ($blockable) $totals['blockable']++;

        // ── v4.21.63: Cliff computation, Finance-primary ─────────────────
        // Walk this customer's KITs and find the EARLIEST cliff across them.
        // A cliff is the next billing date (after today) when DishNet would
        // be charged again for that KIT — that's the deadline for "block now
        // vs wait for cycle end" decisions.
        //
        // Source priority:
        //   1. Finance plugin sl_kits.json — kit row has account_number plus
        //      one of: next_invoice_date, cycle_end, billing_day. THIS is
        //      the authoritative billing schedule (matches the UI screenshot
        //      with "Apr 21 – May 20" cycle). When present, use it.
        //   2. Data-report sl_svc_cache.json — subscription_endDate from
        //      Starlink's portal API. Different concept (subscription
        //      lifecycle, not billing cycle) but historically what the cliff
        //      column tried to use. Kept as fallback for KITs not in Finance.
        //
        // Cliff = earliest valid date across both sources for all KITs.
        $slEndDate = null;
        $slEndKit  = '';
        $slActive  = null;
        $slCanResume = null;
        $slPlanName  = '';
        $slCliffSource = null;

        $today    = time();
        $todayDay = (int)date('j', $today);
        $year     = (int)date('Y', $today);
        $month    = (int)date('n', $today);

        foreach ($kits as $kit) {
            $kitU = strtoupper($kit);
            $finRow = $kitFinanceByKit[$kitU] ?? null;
            $candEndIso = null;
            $candSource = null;

            if (is_array($finRow)) {
                // Try explicit date fields first. v4.21.64: real field names
                // confirmed via diagnostic — Finance plugin uses
                // last_invoice_date and starlink_billing_day. None of the
                // generic names (next_invoice_date / cycle_end_date / etc.)
                // I tried in v4.21.63 actually exist on these rows.
                foreach (['next_invoice_date', 'cycle_end_date', 'cycle_end',
                          'next_billing_date', 'subscription_end_date'] as $f) {
                    if (!empty($finRow[$f])) {
                        $candEndIso = (string)$finRow[$f];
                        $candSource = 'finance:' . $f;
                        break;
                    }
                }
                // Primary path: starlink_billing_day (1-31) → next occurrence
                // from today. v4.21.64 — confirmed this is the real field.
                if ($candEndIso === null) {
                    $bd = (int)(
                        $finRow['starlink_billing_day']
                        ?? $finRow['billing_day']
                        ?? $finRow['billingDay']
                        ?? $finRow['bill_day']
                        ?? 0
                    );
                    if ($bd >= 1 && $bd <= 31) {
                        if ($todayDay >= $bd) {
                            // This month's bill day already passed → next month
                            $nextTs = mktime(0, 0, 0, $month + 1, $bd, $year);
                        } else {
                            // Bill day still ahead this month
                            $nextTs = mktime(0, 0, 0, $month, $bd, $year);
                        }
                        $candEndIso = date('Y-m-d', $nextTs);
                        $candSource = 'finance:starlink_billing_day';
                    }
                }
                // v4.21.64 — last_invoice_date + 1 month is also a clean
                // signal when starlink_billing_day is missing/zero. Most
                // accounts bill exactly one month after the previous bill.
                if ($candEndIso === null && !empty($finRow['last_invoice_date'])) {
                    $lastInv = strtotime((string)$finRow['last_invoice_date']);
                    if ($lastInv !== false) {
                        $candEndIso = date('Y-m-d', strtotime('+1 month', $lastInv));
                        $candSource = 'finance:last_invoice+1mo';
                    }
                }
                // Last finance fallback: cycle_start + ~30 days
                if ($candEndIso === null && !empty($finRow['cycle_start'] ?? $finRow['cycle_start_date'] ?? null)) {
                    $cs = strtotime((string)($finRow['cycle_start'] ?? $finRow['cycle_start_date']));
                    if ($cs !== false) {
                        $candEndIso = date('Y-m-d', $cs + 30 * 86400);
                        $candSource = 'finance:cycle_start+30';
                    }
                }
                // v4.21.64 — capture pretty plan name + KIT status from Finance
                if ($slPlanName === '') {
                    $slPlanName = (string)(
                        $finRow['plan_name'] ?? $finRow['plan']
                        ?? $finRow['billing_package'] ?? ''
                    );
                }
                // If KIT already marked Inactive in Finance, the "cliff" is
                // historical — DishNet has presumably already stopped paying.
                // We still report the date but mark status so UI can dim it.
                if (!empty($finRow['status']) && strcasecmp((string)$finRow['status'], 'Inactive') === 0) {
                    $candSource = ($candSource ?: 'finance') . ':inactive';
                }
            }

            // Fallback to data-report subscription endDate
            if ($candEndIso === null) {
                $slRow = $slSvcByKit[$kit] ?? null;
                if (is_array($slRow) && !empty($slRow['subscription_endDate'])) {
                    $candEndIso = (string)$slRow['subscription_endDate'];
                    $candSource = 'datareport:subscription_endDate';
                    if ($slActive === null)    $slActive    = $slRow['subscription_active']  ?? null;
                    if ($slCanResume === null) $slCanResume = $slRow['canResumeService']     ?? null;
                    if ($slPlanName === '')    $slPlanName  = (string)($slRow['product_desc'] ?? '');
                }
            }

            if ($candEndIso === null) continue;
            $endTs = strtotime($candEndIso);
            if ($endTs === false) continue;

            // Adopt as global earliest if it's earlier than current best
            if ($slEndDate === null || $endTs < strtotime($slEndDate)) {
                $slEndDate     = $candEndIso;
                $slEndKit      = $kit;
                $slCliffSource = $candSource;
                // Capture plan name from Finance row if available (pretty for UI)
                if (is_array($finRow) && $slPlanName === '') {
                    $slPlanName = (string)(
                        $finRow['plan_name'] ?? $finRow['plan']
                        ?? $finRow['service_plan'] ?? $finRow['product_desc'] ?? ''
                    );
                }
            }
        }

        $daysUntilCliff = null;
        if ($slEndDate !== null) {
            $diffSeconds = strtotime($slEndDate) - time();
            $daysUntilCliff = (int)floor($diffSeconds / 86400);
            if ($daysUntilCliff <= 7 && $daysUntilCliff > -30) {
                // 7-day-or-less window AND not deeply lapsed → "imminent cliff"
                $totals['sl_cliff_imminent']++;
            }
        }

        $rows[] = [
            'client_id'         => $cid,
            'name'              => $name,
            'phone'             => $phone,
            'balance'           => $balance,
            'plans'             => $plans,
            'suspended_services' => $suspendedSvcs,
            'is_vip'            => $vip['is_vip'],
            'vip_reason'        => $vip['reason'],
            'kit_count'         => count($kits),
            'kit_serials'       => $kits,
            'kit_source'        => $kitsSource[$cid] ?? 'none',
            'already_blocked'   => $blocked,
            'blockable'         => $blockable,
            // v4.21.59 — Starlink subscription cliff
            'sl_end_date'       => $slEndDate,
            'sl_end_kit'        => $slEndKit,
            'sl_days_until_cliff' => $daysUntilCliff,
            'sl_active'         => $slActive,
            'sl_can_resume'     => $slCanResume,
            // v4.21.63 — which source provided the cliff (for debugging)
            'sl_cliff_source'   => $slCliffSource,
            'sl_plan_name'      => $slPlanName,
        ];
    }

    // Sort: blockable first, then VIPs, then already-blocked, then no-kit
    usort($rows, function ($a, $b) {
        $rank = function ($r) {
            if ($r['blockable']) return 0;
            if ($r['already_blocked']) return 1;
            if ($r['is_vip']) return 2;
            return 3;
        };
        $ra = $rank($a); $rb = $rank($b);
        if ($ra !== $rb) return $ra <=> $rb;
        return strcasecmp($a['name'], $b['name']);
    });

    $ok2([
        'totals'       => $totals,
        'rows'         => $rows,
        'api_strategy' => $apiStrategy,
        'config' => [
            'vip_tag_id'   => $vipTagId,
            'vip_tag_name' => $vipTagName,
            'vip_explicit' => array_keys($vipExplicit),
        ],
        // v4.21.59 — Starlink cache freshness
        'sl_svc_cache' => [
            'loaded'       => $slSvcCacheLoadedCount > 0,
            'service_lines'=> $slSvcCacheLoadedCount,
            'kits_indexed' => count($slSvcByKit),
            'stale'        => $slSvcCacheStale,
        ],
        'note' => 'Live UCRM data (clients/services?status=3,4). READ-ONLY. Append &debug=1 to inspect raw service data.',
    ]);
}

if ($act === 'sl_accounts_dump' && $met === 'GET') {
    // v4.21.62 — diagnostic: read sl_accounts.json from Starlink Finance.
    // sl_kits.json has account_number per kit but no billing_day; the
    // billing_day lives on the parent account record. Confirmed via
    // sl_kits_dump that account_number IS on kit rows. Now we need to
    // verify the accounts file shape so the cliff column can JOIN
    // kit.account_number → account.billing_day.
    if (!$isAdmin) $er2('Admin access required.', 403);
    $candidates = [
        dirname(__DIR__, 3) . '/dishnet-starlink-finance/data/sl_accounts.json',
        dirname(__DIR__, 2) . '/../dishnet-starlink-finance/data/sl_accounts.json',
        // Also try alternative names — Finance plugin could use any of these
        dirname(__DIR__, 3) . '/dishnet-starlink-finance/data/accounts.json',
        dirname(__DIR__, 3) . '/dishnet-starlink-finance/data/sl_account_cycles.json',
    ];
    $aPath = null;
    $tried = [];
    foreach ($candidates as $p) {
        $tried[] = ['path' => $p, 'exists' => file_exists($p)];
        if (file_exists($p) && $aPath === null) { $aPath = $p; }
    }
    if (!$aPath) {
        // List the data directory to see what files Finance actually writes
        $dataDir = dirname(__DIR__, 3) . '/dishnet-starlink-finance/data';
        $listing = is_dir($dataDir) ? array_values(array_diff(scandir($dataDir), ['.', '..'])) : [];
        $ok2([
            'exists'        => false,
            'tried_paths'   => $tried,
            'data_dir'      => $dataDir,
            'data_dir_list' => $listing,
        ], 'sl_accounts.json not found — see data_dir_list for what is there');
    }
    $raw = @file_get_contents($aPath);
    $accs = json_decode($raw, true) ?: [];
    $totalAccs = count($accs);
    $allFields = [];
    $samples = [];
    $billDayDist = [];
    $searchHits = [];
    $needles = trim($_GET['search_accounts'] ?? '');
    $accNeedles = $needles !== '' ? array_filter(array_map('trim', explode(',', $needles))) : [];
    foreach ($accs as $key => $row) {
        if (!is_array($row)) continue;
        foreach (array_keys($row) as $f) $allFields[$f] = ($allFields[$f] ?? 0) + 1;
        $bd = (int)($row['billing_day'] ?? $row['billingDay'] ?? $row['bill_day'] ?? 0);
        if ($bd > 0) $billDayDist[$bd] = ($billDayDist[$bd] ?? 0) + 1;
        if (count($samples) < 5) $samples[] = ['key' => $key, 'row' => $row];
        if (!empty($accNeedles)) {
            $rowAcc = (string)(
                $row['account_number'] ?? $row['account'] ?? $row['acct']
                ?? (is_string($key) ? $key : '')
            );
            foreach ($accNeedles as $n) {
                if ($rowAcc === $n) {
                    $searchHits[] = ['account' => $rowAcc, 'row' => $row];
                    break;
                }
            }
        }
    }
    $ok2([
        'exists'                  => true,
        'path'                    => $aPath,
        'mtime'                   => date('c', filemtime($aPath)),
        'age_seconds'             => time() - filemtime($aPath),
        'size_bytes'              => filesize($aPath),
        'total_accounts'          => $totalAccs,
        'billing_day_distribution'=> $billDayDist,
        'all_field_frequencies'   => $allFields,
        'samples'                 => $samples,
        'search_hits'             => $searchHits,
    ], 'Accounts dump');
}

if ($act === 'sl_kits_dump' && $met === 'GET') {
    // v4.21.61 — diagnostic: read sl_kits.json from Starlink Finance plugin.
    // The "cliff" we want isn't from data-report's sl_svc_cache.json (which
    // tracks subscription endDate per service-line) — it's from the Starlink
    // Finance plugin which models per-account billing cycles. Each KIT lives
    // under a Starlink account; the account has a billing_day; the cliff is
    // the next billing-day after today. This dump shows the actual field
    // shape so we can wire the cliff column to use the right source.
    if (!$isAdmin) $er2('Admin access required.', 403);
    $kPath = null;
    foreach ([
        dirname(__DIR__, 3) . '/dishnet-starlink-finance/data/sl_kits.json',
        dirname(__DIR__, 2) . '/../dishnet-starlink-finance/data/sl_kits.json',
    ] as $p) {
        if (file_exists($p)) { $kPath = $p; break; }
    }
    if (!$kPath) $ok2(['exists' => false], 'sl_kits.json not found');
    $raw = @file_get_contents($kPath);
    $kits = json_decode($raw, true) ?: [];
    $totalKits = count($kits);
    $allFields = [];
    $samples = [];
    $accSeen = [];
    $billDaySeen = [];
    $searchHits = [];
    $searchKits = trim($_GET['search_kits'] ?? '');
    $needles = $searchKits !== '' ? array_filter(array_map('trim', explode(',', $searchKits))) : [];
    foreach ($kits as $key => $row) {
        if (!is_array($row)) continue;
        foreach (array_keys($row) as $f) $allFields[$f] = ($allFields[$f] ?? 0) + 1;
        $acc = (string)($row['account_number'] ?? $row['account'] ?? $row['acct'] ?? '');
        if ($acc !== '') $accSeen[$acc] = true;
        $bd = (int)($row['billing_day'] ?? $row['billingDay'] ?? $row['bill_day'] ?? 0);
        if ($bd > 0) $billDaySeen[$bd] = ($billDaySeen[$bd] ?? 0) + 1;
        if (count($samples) < 5) $samples[] = ['key' => $key, 'row' => $row];
        if (!empty($needles)) {
            $rowKit = strtoupper((string)(
                $row['kit_serial'] ?? $row['kit_number'] ?? $row['kit']
                ?? $row['kitSerial'] ?? (is_string($key) ? $key : '')
            ));
            foreach ($needles as $n) {
                if ($rowKit === strtoupper($n)) {
                    $searchHits[] = ['kit' => $rowKit, 'row' => $row];
                    break;
                }
            }
        }
    }
    $ok2([
        'exists'              => true,
        'path'                => $kPath,
        'mtime'               => date('c', filemtime($kPath)),
        'age_seconds'         => time() - filemtime($kPath),
        'size_bytes'          => filesize($kPath),
        'total_kits'          => $totalKits,
        'distinct_accounts'   => count($accSeen),
        'account_samples'     => array_slice(array_keys($accSeen), 0, 10),
        'billing_day_distribution' => $billDaySeen,
        'all_field_frequencies' => $allFields,
        'kit_samples'         => $samples,
        'search_hits'         => $searchHits,
    ], 'Kits dump');
}

if ($act === 'sl_svc_cache_dump' && $met === 'GET') {
    // v4.21.60 — diagnostic: read sl_svc_cache.json and return a structured
    // sample so we can compare KIT formats between cache and audit. Admin-
    // only since the cache contains operational fleet info.
    if (!$isAdmin) $er2('Admin access required.', 403);
    $cachePath = null;
    foreach ([
        dirname(__DIR__, 3) . '/dishnet-data-report/data/sl_svc_cache.json',
        dirname(__DIR__, 2) . '/../dishnet-data-report/data/sl_svc_cache.json',
    ] as $p) {
        if (file_exists($p)) { $cachePath = $p; break; }
    }
    if (!$cachePath) $ok2(['exists' => false], 'sl_svc_cache.json not found');
    $raw = @file_get_contents($cachePath);
    $cache = json_decode($raw, true) ?: [];
    $totalSLs = count($cache);
    // Bucket by kit_number presence
    $withKit = []; $withoutKit = []; $kitSamples = [];
    $endDateSamples = []; $allFieldNames = [];
    foreach ($cache as $slNum => $row) {
        if (!is_array($row)) continue;
        foreach (array_keys($row) as $f) $allFieldNames[$f] = ($allFieldNames[$f] ?? 0) + 1;
        if (!empty($row['kit_number'])) {
            $withKit[] = ['sl' => $slNum, 'kit' => $row['kit_number']];
            if (count($kitSamples) < 10) $kitSamples[] = $row['kit_number'];
        } else {
            $withoutKit[] = $slNum;
        }
        if (!empty($row['subscription_endDate']) && count($endDateSamples) < 5) {
            $endDateSamples[] = [
                'sl'    => $slNum,
                'kit'   => $row['kit_number'] ?? '(none)',
                'end'   => $row['subscription_endDate'],
                'active'=> $row['subscription_active'] ?? null,
            ];
        }
    }
    // Search for specific KITs if provided as comma-separated query
    $hits = [];
    $searchKits = trim($_GET['search_kits'] ?? '');
    if ($searchKits !== '') {
        $needles = array_filter(array_map('trim', explode(',', $searchKits)));
        foreach ($cache as $slNum => $row) {
            if (!is_array($row)) continue;
            $rowKit = (string)($row['kit_number'] ?? '');
            foreach ($needles as $n) {
                if ($rowKit === $n) {
                    $hits[] = ['sl' => $slNum, 'kit' => $rowKit, 'row' => $row];
                    break;
                }
            }
        }
    }
    $ok2([
        'exists'              => true,
        'path'                => $cachePath,
        'mtime'               => date('c', filemtime($cachePath)),
        'age_seconds'         => time() - filemtime($cachePath),
        'size_bytes'          => filesize($cachePath),
        'total_service_lines' => $totalSLs,
        'with_kit_number'     => count($withKit),
        'without_kit_number'  => count($withoutKit),
        'kit_samples'         => $kitSamples,
        'kits_without_kit_first5' => array_slice($withoutKit, 0, 5),
        'with_end_date_samples' => $endDateSamples,
        'all_field_frequencies' => $allFieldNames,
        'search_hits'         => $hits,
    ], 'Cache dump');
}

if ($act === 'sl_manual_suspend' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin access required.', 403);

    $clientId = (int)($body['client_id'] ?? 0);
    if ($clientId <= 0) $er2('client_id required (positive integer).', 422);

    $mode = (string)($body['mode'] ?? 'pause_only');
    if (!in_array($mode, ['pause_only', 'full'], true)) $er2('mode must be pause_only or full.', 422);

    // Load StarlinkBlockService — webhook.php pattern, not autoloaded
    if (!class_exists('StarlinkBlockService')) {
        $svcPath = dirname(__DIR__, 2) . '/lib/StarlinkBlockService.php';
        if (!file_exists($svcPath)) $er2('StarlinkBlockService not found at ' . $svcPath, 500);
        require_once $svcPath;
    }

    // Override block_mode in config so suspend() picks up the requested mode.
    // (StarlinkBlockService reads starlink_block_default_mode from config; we
    // mutate just for this one call without persisting.)
    $localConfig = is_array($config) ? $config : [];
    $localConfig['starlink_block_default_mode'] = $mode;

    // Resolve service_id from search index / services cache (best-effort)
    // suspend() accepts serviceId=0 — it'll pick the first active service
    // from CRM by client_id internally if needed.
    $serviceId = 0;
    try {
        foreach ($store->load('ucrm_services_cache.json') ?? [] as $svc) {
            $svcCid = (int)($svc['clientId'] ?? $svc['_clientId'] ?? 0);
            if ($svcCid === $clientId) {
                $serviceId = (int)($svc['id'] ?? 0);
                break;
            }
        }
    } catch (\Throwable $_) {}

    try {
        $svcInst = new \StarlinkBlockService($store->getPdo(), $store, $localConfig, $dataDir, $notify);
        $triggeredBy = 'manual:' . ($me2['name'] ?? $me2['username'] ?? 'admin');
        $eventType   = 'admin.manual_suspend';

        // Pass null as $freshClient — service will load it via the CRM API if VIP check needs it
        $result = $svcInst->suspend($clientId, $serviceId, $triggeredBy, $eventType, null);

        $ok2([
            'ok'                 => !empty($result['ok']),
            'client_id'          => $clientId,
            'mode'               => $mode,
            'routers_processed'  => (int)($result['routers_processed'] ?? 0),
            'routers_failed'     => (int)($result['routers_failed']    ?? 0),
            'skipped_reason'     => (string)($result['skipped_reason']  ?? ''),
            'triggered_by'       => $triggeredBy,
            'service_id'         => $serviceId,
        ]);
    } catch (\Throwable $e) {
        $er2('Suspend threw: ' . $e->getMessage(), 500);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// sl_bridge_test_restore  — manually fire the bridge restore for one client
// Added in v4.21.30 as a debugging / verification tool.
//
// Same code path as a real payment.add webhook — calls
// StarlinkBlockBridge::restoreClient() — but invoked synchronously from the
// Block Manager UI. Lets the operator verify the auto-restore chain works
// end-to-end on a specific customer WITHOUT having to actually fire a UCRM
// payment event. Result returned inline with full attempt details (router
// IDs, success/error per router) so you can see exactly what happened.
//
// Body: { "client_id": <int> }
// Returns: { ok, routers_restored, routers_failed, attempts: [...], note }
//
// Same VIP-protection, same idempotency (skips not-paused), same data-report
// HTTP path. The only difference vs. a real webhook is the triggered_by tag
// (ui:manual_test_restore) so you can spot it in the bridge events log.
// ═════════════════════════════════════════════════════════════════════════════

if ($act === 'sl_bridge_test_restore' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin access required.', 403);

    $clientId = (int)($body['client_id'] ?? 0);
    if ($clientId <= 0) $er2('client_id required (positive integer).', 422);

    if (!class_exists('StarlinkBlockBridge')) {
        @require_once dirname(__DIR__, 2) . '/lib/StarlinkBlockBridge.php';
    }
    if (!class_exists('StarlinkBlockBridge')) {
        $er2('StarlinkBlockBridge class not loaded.', 500);
    }

    $triggeredBy = 'ui:manual_test_restore:' . ($me2['name'] ?? $me2['username'] ?? 'admin');

    try {
        $bridge = new \StarlinkBlockBridge($store->getPdo(), $store, $config, $dataDir, $notify);
        $result = $bridge->restoreClient($clientId, $triggeredBy);

        $ok2([
            'ok'                => !empty($result['ok']),
            'client_id'         => $clientId,
            'routers_restored'  => (int)($result['routers_restored'] ?? 0),
            'routers_failed'    => (int)($result['routers_failed']   ?? 0),
            'attempts'          => $result['attempts'] ?? [],
            'note'              => (string)($result['note'] ?? ''),
            'resolve_diag'      => $result['resolve_diag'] ?? null,
            'triggered_by'      => $triggeredBy,
            'plugin_version'    => '4.21.37',
        ]);
    } catch (\Throwable $e) {
        $er2('Bridge test restore threw: ' . $e->getMessage(), 500);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// sl_bridge_test_suspend  — manually fire the bridge suspend for one client
// Added in v4.21.30. Mirror of sl_bridge_test_restore for the block path.
//
// Same code path as service.suspend webhook — calls
// StarlinkBlockBridge::suspendClient(). Lets the operator verify auto-block
// works without having to actually suspend the customer in UCRM admin.
//
// Body: { "client_id": <int> }
// Returns: { ok, routers_processed, routers_failed, skipped_reason, attempts }
// ═════════════════════════════════════════════════════════════════════════════

if ($act === 'sl_bridge_test_suspend' && $met === 'POST') {
    if (!$isAdmin) $er2('Admin access required.', 403);

    $clientId = (int)($body['client_id'] ?? 0);
    if ($clientId <= 0) $er2('client_id required (positive integer).', 422);

    if (!class_exists('StarlinkBlockBridge')) {
        @require_once dirname(__DIR__, 2) . '/lib/StarlinkBlockBridge.php';
    }
    if (!class_exists('StarlinkBlockBridge')) {
        $er2('StarlinkBlockBridge class not loaded.', 500);
    }

    // Fetch fresh client for VIP check (same as webhook does)
    $freshClient = null;
    try {
        if ($crm->isConfigured()) {
            $freshClient = $crm->get("clients/{$clientId}");
        }
    } catch (\Throwable $_) {}

    $triggeredBy = 'ui:manual_test_suspend:' . ($me2['name'] ?? $me2['username'] ?? 'admin');

    try {
        $bridge = new \StarlinkBlockBridge($store->getPdo(), $store, $config, $dataDir, $notify);
        $result = $bridge->suspendClient($clientId, $freshClient, $triggeredBy);

        $ok2([
            'ok'                => !empty($result['ok']),
            'client_id'         => $clientId,
            'routers_processed' => (int)($result['routers_processed'] ?? 0),
            'routers_failed'    => (int)($result['routers_failed']    ?? 0),
            'skipped_reason'    => (string)($result['skipped_reason']  ?? ''),
            'attempts'          => $result['attempts'] ?? [],
            'resolve_diag'      => $result['resolve_diag'] ?? null,
            'triggered_by'      => $triggeredBy,
            'plugin_version'    => '4.21.37',
        ]);
    } catch (\Throwable $e) {
        $er2('Bridge test suspend threw: ' . $e->getMessage(), 500);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// sl_bridge_events  — read bridge activity log (auto-block / auto-restore audit)
// Added in v4.21.30. Returns last N events from sl_block_bridge_events.json.
// Used by the Block Manager UI's "Bridge Activity" panel and by audits to
// verify auto-restore actually fired after a payment.
//
// Query params:
//   limit       (int, default 200, max 500)
//   kind        (optional: 'suspend' | 'restore' — filter)
//   client_id   (optional int — filter to one customer)
//   only_failed (optional 1/0 — only return ok=false rows)
// ═════════════════════════════════════════════════════════════════════════════

if ($act === 'sl_bridge_events' && $met === 'GET') {
    if (!$isAdmin) $er2('Admin access required.', 403);

    $limit = (int)($_GET['limit'] ?? 200);
    if ($limit <= 0) $limit = 200;
    if ($limit > 500) $limit = 500;

    $kind       = trim((string)($_GET['kind'] ?? ''));
    $clientId   = (int)($_GET['client_id'] ?? 0);
    $onlyFailed = !empty($_GET['only_failed']);

    if (!class_exists('StarlinkBlockBridge')) {
        @require_once dirname(__DIR__, 2) . '/lib/StarlinkBlockBridge.php';
    }
    if (!class_exists('StarlinkBlockBridge')) {
        $ok2(['events' => [], 'total' => 0, 'note' => 'StarlinkBlockBridge not loaded']);
        return;
    }

    try {
        $bridge = new \StarlinkBlockBridge($store->getPdo(), $store, $config, $dataDir);
        // Read more than requested if filters are active so we can filter then trim.
        $readLimit = ($kind || $clientId || $onlyFailed) ? min(500, $limit * 5) : $limit;
        $events = $bridge->readRecentEvents($readLimit);

        $filtered = [];
        foreach ($events as $ev) {
            if ($kind !== '' && (string)($ev['kind'] ?? '') !== $kind) continue;
            if ($clientId > 0 && (int)($ev['client_id'] ?? 0) !== $clientId) continue;
            if ($onlyFailed && !empty($ev['ok'])) continue;
            $filtered[] = $ev;
            if (count($filtered) >= $limit) break;
        }

        // Optional enrichment: client name from cache
        $clientCache = $store->load('ucrm_clients_cache.json') ?? [];
        $nameById = [];
        foreach ($clientCache as $c) {
            $cid = (int)($c['id'] ?? 0);
            if ($cid > 0) {
                $nm = trim((string)($c['firstName'] ?? '') . ' ' . (string)($c['lastName'] ?? ''))
                      ?: (string)($c['companyName'] ?? '');
                $nameById[$cid] = $nm;
            }
        }
        foreach ($filtered as &$ev) {
            $ev['client_name'] = $nameById[(int)($ev['client_id'] ?? 0)] ?? '';
        }
        unset($ev);

        $ok2([
            'events' => $filtered,
            'total'  => count($filtered),
            'has_more' => count($events) > count($filtered),
        ]);
    } catch (\Throwable $e) {
        $er2('Bridge events read threw: ' . $e->getMessage(), 500);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// sl_payment_restore_audit  — verify auto-restore actually fired after payments
// Added in v4.21.30.
//
// For each customer who has paid recently (configurable lookback, default 24h),
// check whether their dish is still in data-report's paused state. If so, the
// auto-restore didn't fire (or failed) — they need manual intervention.
//
// Output rows: client_id, name, paid_at, last_amount, kit, router_id, paused_since
//
// Lookback in hours via ?lookback=24 (default). Bounded 1..168 (one week).
// ═════════════════════════════════════════════════════════════════════════════

if ($act === 'sl_payment_restore_audit' && $met === 'GET') {
    if (!$isAdmin) $er2('Admin access required.', 403);

    $lookbackHours = (int)($_GET['lookback'] ?? 24);
    if ($lookbackHours < 1) $lookbackHours = 1;
    if ($lookbackHours > 168) $lookbackHours = 168;

    if (!$crm->isConfigured()) $er2('UCRM API not configured.', 503);

    // 1. Read currently-paused routers from data-report
    $pausedRouters = [];
    $routerMap     = [];
    foreach ([
        dirname(__DIR__, 3) . '/dishnet-data-report/data/wifi_test_block_state.json',
        dirname(__DIR__, 2) . '/../dishnet-data-report/data/wifi_test_block_state.json',
    ] as $p) {
        if (file_exists($p)) {
            $pausedRouters = json_decode((string)@file_get_contents($p), true) ?: [];
            break;
        }
    }
    foreach ([
        dirname(__DIR__, 3) . '/dishnet-data-report/data/wifi_router_map.json',
        dirname(__DIR__, 2) . '/../dishnet-data-report/data/wifi_router_map.json',
    ] as $p) {
        if (file_exists($p)) {
            $routerMap = json_decode((string)@file_get_contents($p), true) ?: [];
            break;
        }
    }

    if (empty($pausedRouters)) {
        $ok2(['stuck' => [], 'lookback_hours' => $lookbackHours, 'note' => 'no_paused_dishes']);
        return;
    }

    // Map paused router_ids → kit_serial
    $pausedKitToRouter = [];
    foreach ($pausedRouters as $routerIdFull => $stateRow) {
        $rawId = (strpos((string)$routerIdFull, 'Router-') === 0)
            ? substr((string)$routerIdFull, 7) : (string)$routerIdFull;
        $rmEntry = $routerMap[$rawId] ?? null;
        if (!is_array($rmEntry)) continue;
        $kit = strtoupper(trim((string)($rmEntry['kit_serial'] ?? '')));
        if ($kit === '') continue;
        $pausedKitToRouter[$kit] = [
            'router_id'    => (string)$routerIdFull,
            'paused_since' => (string)($stateRow['blocked_at'] ?? ''),
            'paused_by'    => (string)($stateRow['blocked_by'] ?? ''),
        ];
    }

    // 2. Fetch recent payments from UCRM
    $sinceIso = gmdate('c', time() - ($lookbackHours * 3600));
    $payments = $crm->get('payments?createdDateFrom=' . urlencode($sinceIso) . '&limit=500');
    if (!is_array($payments)) $payments = [];

    // Group payments by client_id, keep most recent per client
    $latestPaymentByClient = [];
    foreach ($payments as $p) {
        $cid = (int)($p['clientId'] ?? 0);
        if ($cid <= 0) continue;
        $createdAt = (string)($p['createdDate'] ?? $p['method'] ?? '');
        $existing  = $latestPaymentByClient[$cid] ?? null;
        if (!$existing || strcmp($createdAt, $existing['createdDate'] ?? '') > 0) {
            $latestPaymentByClient[$cid] = [
                'client_id'   => $cid,
                'amount'      => (float)($p['amount'] ?? 0),
                'createdDate' => $createdAt,
                'method'      => (string)($p['method'] ?? ''),
            ];
        }
    }

    if (empty($latestPaymentByClient)) {
        $ok2(['stuck' => [], 'lookback_hours' => $lookbackHours, 'note' => 'no_recent_payments']);
        return;
    }

    // 3. For each paying client, resolve their KITs and check against pausedKitToRouter
    // Use the same multi-source KIT resolution as the audit endpoint.
    $slKitsCandidates = [
        dirname(__DIR__, 3) . '/dishnet-starlink-finance/data/sl_kits.json',
        dirname(__DIR__, 2) . '/../dishnet-starlink-finance/data/sl_kits.json',
    ];
    $slKits = null;
    foreach ($slKitsCandidates as $p) {
        if (file_exists($p)) {
            $slKits = json_decode((string)@file_get_contents($p), true);
            if (is_array($slKits)) break;
        }
    }
    $kitsByClient = []; // client_id => [kit_serials]
    if (is_array($slKits)) {
        foreach ($slKits as $key => $val) {
            if (!is_array($val)) continue;
            $cid = (int)(
                $val['client_id'] ?? $val['crm_client_id'] ?? $val['ucrm_client_id']
                ?? $val['clientId'] ?? $val['crmClientId'] ?? $val['customer_id'] ?? 0
            );
            if ($cid === 0) continue;
            $ks = (string)(
                $val['kit_serial'] ?? $val['kit_number'] ?? $val['kit'] ?? $val['serial']
                ?? $val['kitSerial'] ?? (is_string($key) ? $key : '')
            );
            if ($ks !== '') {
                $kitsByClient[$cid] = $kitsByClient[$cid] ?? [];
                $kitsByClient[$cid][] = strtoupper(trim($ks));
            }
        }
    }

    // Client name cache
    $clientCache = $store->load('ucrm_clients_cache.json') ?? [];
    $nameById = [];
    foreach ($clientCache as $c) {
        $cid = (int)($c['id'] ?? 0);
        if ($cid > 0) {
            $nm = trim((string)($c['firstName'] ?? '') . ' ' . (string)($c['lastName'] ?? ''))
                  ?: (string)($c['companyName'] ?? '');
            $nameById[$cid] = $nm;
        }
    }

    // For clients without sl_kits.json entry, fall back to UCRM service.name regex
    foreach ($latestPaymentByClient as $cid => $_) {
        if (!empty($kitsByClient[$cid])) continue;
        try {
            $svcs = $crm->get("clients/{$cid}/services");
            if (is_array($svcs)) {
                foreach ($svcs as $s) {
                    $svcName = (string)($s['name'] ?? '');
                    if ($svcName !== '' && preg_match_all('/\bKIT[A-Z0-9]{8,}\b/i', $svcName, $m)) {
                        foreach ($m[0] as $kit) {
                            $kitsByClient[$cid] = $kitsByClient[$cid] ?? [];
                            $kitsByClient[$cid][] = strtoupper(trim($kit));
                        }
                    }
                }
            }
        } catch (\Throwable $_) {}
    }

    // 4. Build "stuck" list: paying clients whose KITs are still in pausedKitToRouter
    $stuck = [];
    foreach ($latestPaymentByClient as $cid => $payment) {
        $kits = $kitsByClient[$cid] ?? [];
        foreach ($kits as $kit) {
            if (isset($pausedKitToRouter[$kit])) {
                $info = $pausedKitToRouter[$kit];
                $stuck[] = [
                    'client_id'    => $cid,
                    'name'         => $nameById[$cid] ?? '',
                    'kit'          => $kit,
                    'router_id'    => $info['router_id'],
                    'paused_since' => $info['paused_since'],
                    'paused_by'    => $info['paused_by'],
                    'paid_at'      => $payment['createdDate'],
                    'last_amount'  => $payment['amount'],
                    'paid_method'  => $payment['method'],
                ];
                break; // one row per client, even if multiple KITs
            }
        }
    }

    $ok2([
        'stuck'                  => $stuck,
        'lookback_hours'         => $lookbackHours,
        'recent_payments_total'  => count($latestPaymentByClient),
        'currently_paused_total' => count($pausedKitToRouter),
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// sl_extend_cron_health — Hybrid-side view of pause-extension cron
// Added in v4.21.33. Reads data/sl_extend_cron.log.json (written by
// cron_starlink_extend_pauses.php which fires from master.php every 10 min).
//
// Returns same shape as data-report's dr_wifi_test_block_extend_health so
// the UI can use whichever source is more recent:
//   { ok, last_run_ts, last_run_seconds_ago, last_run_status, last_24h, recent_runs }
// ═════════════════════════════════════════════════════════════════════════════

if ($act === 'sl_extend_cron_health' && $met === 'GET') {
    if (!$isAdmin) $er2('Admin access required.', 403);

    $logFile = $dataDir . '/sl_extend_cron.log.json';
    $log = [];
    if (file_exists($logFile)) {
        $log = json_decode((string)@file_get_contents($logFile), true);
        if (!is_array($log)) $log = [];
    }

    $now = time();
    $lastRun = $log[0] ?? null;
    $lastRunTs = $lastRun ? (string)($lastRun['ts'] ?? '') : '';
    $lastRunEpoch = $lastRunTs ? strtotime($lastRunTs) : 0;
    $lastRunSecondsAgo = $lastRunEpoch ? max(0, $now - $lastRunEpoch) : null;

    if ($lastRunSecondsAgo === null) {
        $status = 'never';
    } elseif ($lastRunSecondsAgo < 720) {
        $status = 'healthy';
    } elseif ($lastRunSecondsAgo < 1500) {
        $status = 'stale';
    } else {
        $status = 'very_stale';
    }

    $cutoff24h = $now - 86400;
    $totalRuns = 0;
    $totalNewlyPaused = 0;
    $totalFailures = 0;
    $totalErrors = 0;
    foreach ($log as $row) {
        $epoch = strtotime((string)($row['ts'] ?? ''));
        if (!$epoch || $epoch < $cutoff24h) continue;
        $totalRuns++;
        $totalNewlyPaused += (int)($row['total_newly_paused'] ?? 0);
        $totalFailures    += (int)($row['total_failures']     ?? 0);
        if (empty($row['ok'])) $totalErrors++;
    }

    $ok2([
        'ok'                   => true,
        'source'               => 'hybrid_master_cron',
        'last_run_ts'          => $lastRunTs,
        'last_run_seconds_ago' => $lastRunSecondsAgo,
        'last_run_status'      => $status,
        'last_24h' => [
            'total_runs'         => $totalRuns,
            'total_newly_paused' => $totalNewlyPaused,
            'total_failures'     => $totalFailures,
            'total_errors'       => $totalErrors,
            'expected_runs'      => min(144, count($log)),
        ],
        'recent_runs' => array_slice($log, 0, 20),
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
// OVERDUE WORKBENCH ENDPOINTS — v4.21.66
//
// Operator-side counterpart to the automated dunning chain (cron_overdue_email).
// All endpoints admin or accountant only. All writes go through
// OverdueWorkbenchService which logs to overdue_workbench_log for audit.
//
//   GET  ?action=owb_list           — full overdue feed with filters
//   GET  ?action=owb_detail         — full history for one invoice
//   POST ?action=owb_note           — add a note (free text) or log a contact
//   POST ?action=owb_promise        — record promise to pay
//   POST ?action=owb_clear_promise  — clear promise (back to open)
//   POST ?action=owb_status         — change status
//   POST ?action=owb_assign         — assign or unassign a retailer
//   POST ?action=owb_bulk_assign    — bulk assign N invoices
//   POST ?action=owb_bulk_status    — bulk status change
//   GET  ?action=owb_smtp_check     — check whether plugin SMTP is healthy
// ═════════════════════════════════════════════════════════════════════════════

$_owbAllowed = function() use ($me2): bool {
    if (!empty($me2['is_admin'])) return true;
    $role = (string)($me2['role'] ?? '');
    return in_array($role, ['accountant', 'field_accountant'], true);
};

$_owbSvc = null;
$_owbGet = function() use (&$_owbSvc, $store, $crm) {
    if ($_owbSvc !== null) return $_owbSvc;
    require_once dirname(__DIR__, 2) . '/lib/OverdueWorkbenchService.php';
    $cfg = $store->load('kyc_config.json') ?: [];
    $_owbSvc = new OverdueWorkbenchService($store, $cfg, $crm);
    return $_owbSvc;
};

$_owbBy = function() use ($me2) {
    return ['name' => (string)($me2['name'] ?? 'admin'), 'id' => (int)($me2['id'] ?? 0)];
};

if ($act === 'owb_list' && $met === 'GET') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    if (!$crm->isConfigured()) $er2('UCRM API not configured.', 503);

    $filters = [
        'bucket'                  => (string)($_GET['bucket']        ?? 'all'),
        'status'                  => (string)($_GET['status']        ?? ''),
        'assigned_to'             => (string)($_GET['assigned_to']   ?? ''),
        'min_amount'              => (float) ($_GET['min_amount']    ?? 0),
        'client_search'           => (string)($_GET['q']             ?? ''),
        'exclude_paused'          => !isset($_GET['include_paused']),
        'only_promises_due_today' => !empty($_GET['only_promises_due']),
        'only_broken_promises'    => !empty($_GET['only_broken']),
        'unassigned_only'         => !empty($_GET['unassigned_only']),
    ];
    $svc = $_owbGet();
    $r   = $svc->listOverdue($filters);
    $ok2($r);
}

if ($act === 'owb_detail' && $met === 'GET') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNum = trim((string)($_GET['invoice_number'] ?? ''));
    if ($invNum === '') $er2('invoice_number required', 422);
    $svc = $_owbGet();
    $ok2($svc->detail($invNum));
}

if ($act === 'owb_note' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNum  = trim((string)($body['invoice_number'] ?? ''));
    $note    = (string)($body['note'] ?? '');
    $contact = isset($body['contact_with']) && $body['contact_with'] !== ''
               ? (string)$body['contact_with'] : null;
    if ($invNum === '') $er2('invoice_number required', 422);
    if (trim($note) === '') $er2('note required', 422);
    $svc = $_owbGet();
    $r = $svc->addNote($invNum, $note, $_owbBy(), $contact);
    if (!$r['ok']) $er2($r['error'] ?? 'Failed', 422);
    $ok2($r);
}

if ($act === 'owb_promise' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNum  = trim((string)($body['invoice_number']    ?? ''));
    $date    = trim((string)($body['promised_pay_date'] ?? ''));
    $amount  = isset($body['promised_amount']) && $body['promised_amount'] !== ''
               ? (float)$body['promised_amount'] : null;
    $note    = (string)($body['note'] ?? '');
    if ($invNum === '') $er2('invoice_number required', 422);
    if ($date   === '') $er2('promised_pay_date required (YYYY-MM-DD)', 422);
    $svc = $_owbGet();
    $r = $svc->setPromise($invNum, $date, $amount, $note, $_owbBy());
    if (!$r['ok']) $er2($r['error'] ?? 'Failed', 422);
    $ok2($r);
}

if ($act === 'owb_clear_promise' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNum = trim((string)($body['invoice_number'] ?? ''));
    $note   = (string)($body['note'] ?? '');
    if ($invNum === '') $er2('invoice_number required', 422);
    $svc = $_owbGet();
    $r = $svc->clearPromise($invNum, $note, $_owbBy());
    if (!$r['ok']) $er2($r['error'] ?? 'Failed', 422);
    $ok2($r);
}

if ($act === 'owb_assign' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNum   = trim((string)($body['invoice_number'] ?? ''));
    $assignee = isset($body['assigned_to']) && $body['assigned_to'] !== null
                ? trim((string)$body['assigned_to']) : null;
    $note     = (string)($body['note'] ?? '');
    if ($invNum === '') $er2('invoice_number required', 422);
    $svc = $_owbGet();
    $r = $svc->assignTo($invNum, $assignee, $note, $_owbBy());
    if (!$r['ok']) $er2($r['error'] ?? 'Failed', 422);
    $ok2($r);
}

if ($act === 'owb_status' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNum     = trim((string)($body['invoice_number'] ?? ''));
    $newStatus  = trim((string)($body['status']         ?? ''));
    $pauseUntil = isset($body['pause_until']) && $body['pause_until'] !== ''
                  ? (string)$body['pause_until'] : null;
    $note       = (string)($body['note'] ?? '');
    if ($invNum    === '') $er2('invoice_number required', 422);
    if ($newStatus === '') $er2('status required', 422);
    $svc = $_owbGet();
    $r = $svc->setStatus($invNum, $newStatus, $pauseUntil, $note, $_owbBy());
    if (!$r['ok']) $er2($r['error'] ?? 'Failed', 422);
    $ok2($r);
}

if ($act === 'owb_bulk_assign' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNums  = $body['invoice_numbers'] ?? [];
    $assignee = isset($body['assigned_to']) && $body['assigned_to'] !== null
                ? trim((string)$body['assigned_to']) : null;
    $note     = (string)($body['note'] ?? '');
    if (!is_array($invNums) || empty($invNums)) $er2('invoice_numbers (non-empty array) required', 422);
    if (count($invNums) > 500) $er2('Bulk operations limited to 500 invoices.', 422);
    $svc = $_owbGet();
    $ok2($svc->bulkAssign($invNums, $assignee, $note, $_owbBy()));
}

if ($act === 'owb_bulk_status' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $invNums    = $body['invoice_numbers'] ?? [];
    $newStatus  = trim((string)($body['status'] ?? ''));
    $pauseUntil = isset($body['pause_until']) && $body['pause_until'] !== ''
                  ? (string)$body['pause_until'] : null;
    $note       = (string)($body['note'] ?? '');
    if (!is_array($invNums) || empty($invNums)) $er2('invoice_numbers (non-empty array) required', 422);
    if ($newStatus === '') $er2('status required', 422);
    if (count($invNums) > 500) $er2('Bulk operations limited to 500 invoices.', 422);
    $svc = $_owbGet();
    $ok2($svc->bulkSetStatus($invNums, $newStatus, $pauseUntil, $note, $_owbBy()));
}

// ─────────────────────────────────────────────────────────────────────────────
// owb_smtp_check — quick health check on plugin SMTP without sending an email.
// Tests TCP connect + TLS handshake + AUTH against the plugin's email_settings.json.
// Surfaces the result inline in the workbench so the operator knows whether
// emails will go out today (independent of UCRM's own mailer status).
// ─────────────────────────────────────────────────────────────────────────────
if ($act === 'owb_smtp_check' && $met === 'GET') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);

    require_once dirname(__DIR__, 2) . '/lib/MailService.php';
    $mailer = new MailService($dataDir);
    $cfg = $mailer->getConfig();
    if (!is_array($cfg) || empty($cfg['host'])) {
        $ok2([
            'ok'      => false,
            'reason'  => 'not_configured',
            'message' => 'Plugin SMTP not configured. Open Settings → SMTP Diagnostic.',
            'source'  => $cfg['_source'] ?? 'unset',
        ]);
    }

    $host = (string)$cfg['host'];
    $port = (int)$cfg['port'];
    $enc  = strtolower((string)($cfg['enc'] ?? 'tls'));
    $user = (string)($cfg['user'] ?? '');
    $pass = (string)($cfg['pass'] ?? '');

    $errno = 0; $errstr = '';
    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 8,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer'=>false,'verify_peer_name'=>false]])
    );
    if (!$fp) {
        $ok2(['ok'=>false, 'reason'=>'connect_failed', 'message'=>"Connect failed: {$errstr}", 'host'=>$host, 'port'=>$port]);
    }
    stream_set_timeout($fp, 8);

    $read = function() use ($fp) { $b=''; while(($l=fgets($fp,1024))!==false){$b.=$l;if(strlen($l)<4||$l[3]!=='-')break;} return $b; };
    $write = function($c) use ($fp) { fputs($fp, $c . "\r\n"); };

    $banner = $read();
    if (substr($banner, 0, 3) !== '220') { @fclose($fp); $ok2(['ok'=>false,'reason'=>'banner','message'=>"Banner: {$banner}"]); }

    $hn = function_exists('gethostname') ? gethostname() : 'localhost';
    $write("EHLO {$hn}"); $ehlo = $read();
    if (substr($ehlo, 0, 3) !== '250') { @fclose($fp); $ok2(['ok'=>false,'reason'=>'ehlo','message'=>"EHLO: {$ehlo}"]); }

    if ($enc === 'tls') {
        $write('STARTTLS');
        $sttls = $read();
        if (substr($sttls, 0, 3) !== '220') { @fclose($fp); $ok2(['ok'=>false,'reason'=>'starttls','message'=>"STARTTLS: {$sttls}"]); }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            @fclose($fp);
            $ok2(['ok'=>false, 'reason'=>'tls', 'message'=>'TLS handshake failed']);
        }
        $write("EHLO {$hn}"); $read();
    }

    if ($user !== '' && $pass !== '') {
        $write('AUTH LOGIN');
        $a1 = $read();
        if (substr($a1, 0, 3) !== '334') { @fclose($fp); $ok2(['ok'=>false,'reason'=>'auth_init','message'=>"AUTH LOGIN: {$a1}"]); }
        $write(base64_encode($user));
        $a2 = $read();
        if (substr($a2, 0, 3) !== '334') { @fclose($fp); $ok2(['ok'=>false,'reason'=>'auth_user','message'=>"User rejected: {$a2}"]); }
        $write(base64_encode($pass));
        $a3 = $read();
        if (substr($a3, 0, 3) !== '235') {
            @fclose($fp);
            // Most common cause for Microsoft 365 shown in production:
            // password expired or app password rotated.
            $hint = '';
            if (stripos($host, 'office365') !== false || stripos($host, 'outlook') !== false) {
                $hint = ' Microsoft 365 hint: app passwords rotate. Generate a new one or use a fresh OAuth-enabled account password.';
            }
            $ok2(['ok'=>false,'reason'=>'auth_pass','message'=>"Password rejected: {$a3}.{$hint}"]);
        }
    }

    $write('QUIT');
    @fclose($fp);
    $ok2([
        'ok'      => true,
        'message' => 'SMTP healthy — TCP, TLS, and AUTH all succeeded. Outbound email should work.',
        'host'    => $host,
        'port'    => $port,
        'source'  => $cfg['_source'] ?? '',
    ]);
}

// CSV export of current filtered list (Rule 13 compliant)
if ($act === 'owb_export_csv' && $met === 'GET') {
    if (!$_owbAllowed()) {
        ob_end_clean(); http_response_code(403);
        echo "Workbench requires admin or accountant role.";
        exit;
    }
    $filters = [
        'bucket'                  => (string)($_GET['bucket']        ?? 'all'),
        'status'                  => (string)($_GET['status']        ?? ''),
        'assigned_to'             => (string)($_GET['assigned_to']   ?? ''),
        'min_amount'              => (float) ($_GET['min_amount']    ?? 0),
        'client_search'           => (string)($_GET['q']             ?? ''),
        'exclude_paused'          => !isset($_GET['include_paused']),
        'only_promises_due_today' => !empty($_GET['only_promises_due']),
        'only_broken_promises'    => !empty($_GET['only_broken']),
        'unassigned_only'         => !empty($_GET['unassigned_only']),
    ];
    $svc = $_owbGet();
    $r   = $svc->listOverdue($filters);

    ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="overdue-workbench-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice','Client ID','Client','Phone','Email','Amount Due','Due Date',
        'Days Overdue','Bucket','Status','Assigned To','Promised Date','Promised Amt',
        'Last Note','Last Action By','Last Action At','Contact Attempts','Last Email Stage','Last Email At']);
    foreach ($r['rows'] as $row) {
        fputcsv($out, [
            $row['invoice_number'], $row['client_id'], $row['client_name'],
            $row['phone'], $row['email'], number_format((float)$row['amount_due'], 2, '.', ''),
            $row['due_date_fmt'], $row['days_overdue'], $row['bucket'], $row['status'],
            $row['assigned_to'] ?? '', $row['promised_pay_date'] ?? '',
            $row['promised_amount'] !== null ? number_format((float)$row['promised_amount'], 2, '.', '') : '',
            $row['last_note'] ?? '', $row['last_action_by'] ?? '', $row['last_action_at'] ?? '',
            $row['contact_attempts'] ?? 0, $row['last_email_label'] ?? '', $row['last_email_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// owb_bulk_send — bulk-send dunning emails + WhatsApps from the workbench
//
// v4.21.67 — operator-triggered batch send. UI in tabs/admin/overdue_workbench.php
// fires this once the operator has filtered the list down to a target group
// (e.g. "31-60 days, unassigned, $50+") and clicked Send Messages.
//
// Body:
//   {
//     invoice_numbers: [...],          // required, max 500
//     channels: ['email','whatsapp'],  // both, or one
//     stage_override: int|null,        // 1-9 to force a specific template,
//                                      // null = auto-pick by days overdue
//     throttle_ms: int,                // pause between sends. Default 2000.
//     respect_dedup: bool,             // default true — honor the 30-day
//                                      // stage-9 rule and once-ever stages 1-8
//                                      // dedup. Set false to force-resend
//                                      // (logged as 'force' in the audit).
//   }
//
// Returns:
//   {
//     attempted: N,
//     sent_email: N, sent_wa: N,
//     skipped_dedup: N, skipped_no_contact: N,
//     errors: [{invoice_number, error}, ...],
//     elapsed_seconds: float,
//   }
//
// Idempotency:
//   - Each invoice's effective stage is logged to overdue_email_log (sent_by =
//     'workbench:<retailer>') so the next cron run sees it and won't re-fire
//     stage 1-8 for the same invoice.
//   - Stage 9 dedup is the standard 30-day window — unchanged from cron.
//   - WA dedup keys reuse the cron's pattern: OVDUE-WA-{inv}-s{stage} or
//     OVDUE-WA-{inv}-s9-{Y-m} for stage 9.
//
// Logging:
//   - Per-invoice action logged to overdue_workbench_log (action='bulk_send')
//     with channel + stage + outcome in the detail field.
//   - Run summary written to data/owb_bulk_send.log.json (last 50 entries) so
//     the workbench can show a "recent bulk sends" panel.
//
// Safety:
//   - Hard cap 500 invoices per call (matches existing bulk-action limit).
//   - Throttle defaults to 2000ms (1 send / 2 seconds) per Bhavin's pick.
//   - set_time_limit(600) — 10 minutes hard ceiling. 500 invoices × 2s ≈ 17min
//     would overrun, so the caller should split into batches when needed.
//   - SMTP failure on first send aborts the email side of the batch (still
//     attempts WA on remaining invoices). Avoids 500 hopeless attempts.
// ═════════════════════════════════════════════════════════════════════════════
if ($act === 'owb_bulk_send' && $met === 'POST') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);

    $invNums  = $body['invoice_numbers'] ?? [];
    $channels = $body['channels'] ?? ['email', 'whatsapp'];
    $stageOv  = isset($body['stage_override']) && $body['stage_override'] !== '' && $body['stage_override'] !== null
                ? (int)$body['stage_override'] : null;
    $throttleMs = isset($body['throttle_ms']) ? max(0, min(10000, (int)$body['throttle_ms'])) : 2000;
    $respectDedup = !isset($body['respect_dedup']) || !empty($body['respect_dedup']);

    if (!is_array($invNums) || empty($invNums)) $er2('invoice_numbers (non-empty array) required', 422);
    if (count($invNums) > 500) $er2('Bulk send limited to 500 invoices per call.', 422);
    if (!is_array($channels) || empty($channels)) $er2('channels must be non-empty array', 422);
    foreach ($channels as $ch) {
        if (!in_array($ch, ['email','whatsapp'], true)) {
            $er2("Invalid channel: {$ch}. Allowed: email, whatsapp", 422);
        }
    }
    if ($stageOv !== null && ($stageOv < 1 || $stageOv > 9)) {
        $er2('stage_override must be between 1 and 9', 422);
    }

    // v4.21.73: Run in BACKGROUND. Same fastcgi_finish_request pattern the
    // overdue cron Run Now uses. Returns a job_id immediately so the modal
    // can switch to live-polling mode instead of holding open a multi-minute
    // HTTP request that browsers and proxies will time out.
    $jobId   = uniqid('owb_bs_', true);
    $jobFile = $dataDir . '/owb_bulk_send_jobs/' . $jobId . '.json';
    @mkdir(dirname($jobFile), 0755, true);

    // Initial state — JS picks this up via owb_bulk_send_status
    @file_put_contents($jobFile, json_encode([
        'job_id'      => $jobId,
        'started_at'  => time(),
        'finished_at' => null,
        'running'     => true,
        'attempted_total' => count($invNums),
        'progress'    => 0,
        'sent_email'  => 0,
        'sent_wa'     => 0,
        'skipped_dedup' => 0,
        'skipped_no_contact' => 0,
        'errors'      => [],
        'log'         => '',
        'channels'    => $channels,
        'stage_override' => $stageOv,
        'throttle_ms' => $throttleMs,
    ], JSON_PRETTY_PRINT));

    // Flush job_id to caller so the JS can start polling
    ignore_user_abort(true);
    @set_time_limit(1800);
    ob_end_clean();
    header('Content-Type: application/json');
    header('Connection: close');
    $resp = json_encode([
        'status' => 'success',
        'message' => 'Bulk send queued. Poll owb_bulk_send_status?job_id=' . $jobId,
        'data' => [
            'job_id' => $jobId,
            'queued' => true,
            'attempted_total' => count($invNums),
        ],
    ]);
    header('Content-Length: ' . strlen($resp));
    echo $resp;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        @flush();
    }

    // From here, the user is gone. Run the batch and write progress to file.
    set_time_limit(1800);

    $pluginRoot = dirname(__DIR__, 2);
    require_once $pluginRoot . '/lib/OverdueDunningHelpers.php';
    require_once $pluginRoot . '/lib/NotificationService.php';
    require_once $pluginRoot . '/lib/OverdueWorkbenchService.php';

    $cfg    = $store->load('kyc_config.json') ?: [];
    $notify = new NotificationService($store, $cfg);
    $owb    = new OverdueWorkbenchService($store, $cfg, $crm);

    $smtp = _getSmtpSettings($store, $cfg);
    $emailEnabled = in_array('email', $channels, true) && is_array($smtp) && !empty($smtp['host']);
    $waEnabled    = in_array('whatsapp', $channels, true);
    $emailDisabledReason = '';
    if (in_array('email', $channels, true) && !$emailEnabled) {
        $emailDisabledReason = !is_array($smtp) || empty($smtp['host'])
            ? 'SMTP not configured — open Settings → SMTP Diagnostic'
            : 'SMTP misconfigured';
    }

    $pdo = $store->getPdo();

    // Pre-fetch dedup state for all invoices in one query — avoids N round-trips.
    $dedupExisting = [];
    if ($respectDedup && !empty($invNums)) {
        $placeholders = rtrim(str_repeat('?,', count($invNums)), ',');
        try {
            $stmt = $pdo->prepare(
                "SELECT invoice_number, stage, sent_at, success
                 FROM overdue_email_log
                 WHERE invoice_number IN ({$placeholders})
                 ORDER BY sent_at DESC"
            );
            $stmt->execute(array_values($invNums));
            while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $key = $r['invoice_number'];
                if (!isset($dedupExisting[$key])) $dedupExisting[$key] = [];
                $dedupExisting[$key][] = $r;
            }
        } catch (\Throwable $e) {}
    }

    // Resolve invoice → client + days overdue. Prefer a single batch fetch
    // from the cache to keep this fast.
    $invoiceCache = $store->load('ucrm_invoices_cache.json') ?? [];
    $invoiceByNumber = [];
    foreach ($invoiceCache as $inv) {
        $n = (string)($inv['number'] ?? '');
        if ($n !== '') $invoiceByNumber[$n] = $inv;
    }

    $clientCache = $store->load('ucrm_clients_cache.json') ?? [];
    $clientById = [];
    foreach ($clientCache as $c) {
        $cid = (int)($c['id'] ?? 0);
        if ($cid > 0) $clientById[$cid] = $c;
    }

    $today = new \DateTime('now', new \DateTimeZone('Africa/Juba'));
    $startTs = microtime(true);
    $sentEmail = 0; $sentWa = 0;
    $skippedDedup = 0; $skippedNoContact = 0;
    $errors = []; $logRows = [];

    $byName = (string)($me2['name'] ?? 'admin');
    $byArr  = ['name' => $byName, 'id' => (int)($me2['id'] ?? 0)];

    $smtpFailedHard = false; // set true if first email fails — abort email side

    foreach ($invNums as $invNum) {
        $invNum = trim((string)$invNum);
        if ($invNum === '') continue;

        $inv = $invoiceByNumber[$invNum] ?? null;
        if (!$inv) {
            // Try a live fetch for this one invoice (fallback)
            $r = $crm->get('billing/invoices?number=' . urlencode($invNum) . '&limit=1');
            if (is_array($r) && !empty($r[0])) $inv = $r[0];
        }
        if (!$inv) {
            $errors[] = ['invoice_number' => $invNum, 'error' => 'invoice_not_found'];
            continue;
        }

        $clientId = (int)($inv['clientId'] ?? 0);
        $client   = $clientById[$clientId] ?? null;
        if (!$client) {
            $cl = $crm->get("clients/{$clientId}");
            if (is_array($cl)) $client = $cl;
        }
        if (!$client) {
            $errors[] = ['invoice_number' => $invNum, 'error' => 'client_not_found'];
            continue;
        }

        $firstName = trim((string)($client['firstName'] ?? ''));
        $lastName  = trim((string)($client['lastName'] ?? ''));
        $fullName  = trim("{$firstName} {$lastName}") ?: (string)($client['companyName'] ?? "Client #{$clientId}");
        if ($firstName === '') $firstName = $fullName;

        $email = ''; $phone = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!$email && !empty($c['email'])) $email = (string)$c['email'];
            if (!$phone && !empty($c['phone'])) $phone = (string)$c['phone'];
        }

        if ($email === '' && $phone === '') {
            $skippedNoContact++;
            $errors[] = ['invoice_number' => $invNum, 'error' => 'no_email_and_no_phone'];
            continue;
        }

        $dueStr = (string)($inv['dueDate'] ?? '');
        try { $due = new \DateTime($dueStr, new \DateTimeZone('Africa/Juba')); }
        catch (\Throwable $e) { $errors[] = ['invoice_number' => $invNum, 'error' => 'bad_due_date']; continue; }

        $daysOverdue = (int)$today->diff($due)->days;
        $amtDue      = (float)($inv['amountToPay'] ?? $inv['total'] ?? 0);
        $amtFmt      = '$' . number_format($amtDue, 2);
        $dueFmt      = $due->format('d M Y');

        // Stage selection
        $stage = $stageOv !== null ? $stageOv : _stageForDays($daysOverdue);
        if ($stage <= 0) {
            $errors[] = ['invoice_number' => $invNum, 'error' => 'not_yet_in_chain (days_overdue=' . $daysOverdue . ')'];
            continue;
        }

        // Dedup check — match the cron's logic exactly
        if ($respectDedup) {
            $alreadySent = false;
            $existingForInv = $dedupExisting[$invNum] ?? [];
            if ($stage === 9) {
                // Stage 9: skip if any successful stage-9 row in last 30 days
                $cutoff30d = (new \DateTime('-30 days'))->format('Y-m-d H:i:s');
                foreach ($existingForInv as $row) {
                    if ((int)$row['stage'] === 9
                        && (int)$row['success'] === 1
                        && (string)$row['sent_at'] > $cutoff30d) {
                        $alreadySent = true; break;
                    }
                }
            } else {
                // Stages 1-8: skip if any successful row for this stage exists
                foreach ($existingForInv as $row) {
                    if ((int)$row['stage'] === $stage && (int)$row['success'] === 1) {
                        $alreadySent = true; break;
                    }
                }
            }
            if ($alreadySent) {
                $skippedDedup++;
                continue;
            }
        }

        // Build the invoice URL (used in WhatsApp + as the email pay link)
        $portalBase = (string)($cfg['crm_base_url'] ?? '');
        if ($portalBase === '') {
            $ucrmFile = dirname($pluginRoot) . '/ucrm.json';
            if (file_exists($ucrmFile)) {
                $u = json_decode((string)@file_get_contents($ucrmFile), true) ?: [];
                $portalBase = (string)($u['ucrmPublicUrl'] ?? '');
                $portalBase = preg_replace('#/api/v[\d.]+/?$#', '', $portalBase);
                $portalBase = rtrim($portalBase, '/');
                if (!preg_match('#/crm$#', $portalBase)) $portalBase .= '/crm';
            }
        }
        $invoiceId = (int)($inv['id'] ?? 0);
        $invoiceUrl = $portalBase && $invoiceId ? "{$portalBase}/client-zone/invoices/{$invoiceId}/pay" : '';
        $payUrl = $invoiceUrl;

        // ── EMAIL SEND ─────────────────────────────────────────────────
        $emailOk = null; $emailErr = '';
        if ($emailEnabled && $email !== '' && !$smtpFailedHard) {
            $subject = _buildSubject($stage, $invNum, $amtFmt, $daysOverdue, $cfg);
            $html    = _buildEmail($stage, $firstName, $fullName, $invNum,
                                    $amtFmt, $dueFmt, $daysOverdue, $invoiceUrl, $payUrl, $cfg);
            $err = '';
            $emailOk = _sendEmail($smtp, $email, $subject, $html, $err);
            $emailErr = $err;
            if ($emailOk) {
                $sentEmail++;
            } else {
                $errors[] = ['invoice_number' => $invNum, 'error' => "email_failed: {$emailErr}"];
                // If first email fails with auth/connect issue, abort email side
                // for remaining invoices — saves 499 doomed attempts.
                if ($sentEmail === 0 && (
                    stripos($emailErr, 'auth') !== false
                    || stripos($emailErr, 'connect') !== false
                    || stripos($emailErr, 'rcpt rejected') === false
                )) {
                    $smtpFailedHard = true;
                }
            }

            // Log to overdue_email_log so the cron sees this and skips
            try {
                $pdo->prepare(
                    "INSERT OR IGNORE INTO overdue_email_log
                     (invoice_number,client_id,client_name,client_email,stage,stage_label,
                      amount_due,days_overdue,sent_by,success,error)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $invNum, $clientId, $fullName, $email,
                    $stage, "bulk_send_s{$stage}",
                    $amtDue, $daysOverdue,
                    "workbench:{$byName}",
                    $emailOk ? 1 : 0,
                    $emailOk ? null : $emailErr,
                ]);
            } catch (\Throwable $e) {}
        }

        // ── WHATSAPP SEND ───────────────────────────────────────────────
        $waOk = null;
        if ($waEnabled && $phone !== '') {
            $waMsg = _buildWhatsApp($stage, $firstName, $invNum, $amtFmt, $dueFmt, $daysOverdue, $invoiceUrl);
            $dedupKey = $stage === 9
                ? "OVDUE-WA-{$invNum}-s9-" . date('Y-m')
                : "OVDUE-WA-{$invNum}-s{$stage}";
            // Honor dedup unless caller passed respect_dedup=false
            $shouldFire = $respectDedup ? $notify->dedupMark($dedupKey) : true;
            if ($shouldFire) {
                try {
                    $notify->send('overdue_followup', $phone, $fullName, [
                        'customer_name' => $firstName,
                        'invoice_number'=> $invNum,
                        'amount'        => $amtFmt,
                        'due_date'      => $dueFmt,
                        'days_overdue'  => $daysOverdue,
                        'invoice_url'   => $invoiceUrl,
                        '_raw_message'  => $waMsg,
                    ]);
                    $waOk = true;
                    $sentWa++;
                } catch (\Throwable $e) {
                    $waOk = false;
                    $errors[] = ['invoice_number' => $invNum, 'error' => 'wa_failed: ' . $e->getMessage()];
                }
            }
            // No `else` — silent dedup skip is fine for WA
        }

        // ── Workbench audit log entry (one per invoice) ────────────────
        $detail = "Bulk send stage {$stage}, " . ($daysOverdue) . "d overdue, " . $amtFmt . ". ";
        $parts = [];
        if ($emailOk === true)  $parts[] = "email→{$email} ✓";
        if ($emailOk === false) $parts[] = "email→{$email} ✗ ({$emailErr})";
        if ($waOk === true)     $parts[] = "wa→{$phone} ✓";
        if ($waOk === false)    $parts[] = "wa→{$phone} ✗";
        $detail .= implode(', ', $parts);
        try {
            $owb->addNote(
                $invNum,
                $detail,
                $byArr,
                'bulk_send'  // contactWith — increments contact_attempts
            );
        } catch (\Throwable $e) {}

        $logRows[] = [
            'invoice_number' => $invNum,
            'client_name' => $fullName,
            'amount' => $amtFmt,
            'days' => $daysOverdue,
            'stage' => $stage,
            'email_ok' => $emailOk,
            'wa_ok'    => $waOk,
        ];

        // v4.21.73: write live progress every ~2 seconds OR every 10 invoices
        // (whichever first) so the workbench modal's polling JS shows real
        // counters as the batch runs. Cheap — JSON serialize + file write,
        // ~1ms on a fast disk.
        static $lastJobFlush = 0;
        static $invoicesSinceFlush = 0;
        $invoicesSinceFlush++;
        $now = microtime(true);
        if ($invoicesSinceFlush >= 10 || ($now - $lastJobFlush) > 2) {
            @file_put_contents($jobFile, json_encode([
                'job_id'      => $jobId,
                'started_at'  => (int)$startTs,
                'finished_at' => null,
                'running'     => true,
                'attempted_total'    => count($invNums),
                'progress'           => count($logRows),
                'sent_email'         => $sentEmail,
                'sent_wa'            => $sentWa,
                'skipped_dedup'      => $skippedDedup,
                'skipped_no_contact' => $skippedNoContact,
                'errors_count'       => count($errors),
                'errors'             => array_slice($errors, -20), // last 20 only
                'channels'           => $channels,
                'stage_override'     => $stageOv,
                'throttle_ms'        => $throttleMs,
                'updated_at'         => time(),
            ], JSON_PRETTY_PRINT));
            $lastJobFlush = $now;
            $invoicesSinceFlush = 0;
        }

        // Throttle to be kind to SMTP and WA backend
        if ($throttleMs > 0) usleep($throttleMs * 1000);
    }

    $elapsed = round(microtime(true) - $startTs, 2);

    // Persist a run summary so the UI can show "recent bulk sends"
    try {
        $sumFile = $dataDir . '/owb_bulk_send.log.json';
        $existing = file_exists($sumFile)
            ? (json_decode((string)file_get_contents($sumFile), true) ?: [])
            : [];
        array_unshift($existing, [
            'ts' => date('Y-m-d H:i:s'),
            'by' => $byName,
            'attempted' => count($invNums),
            'sent_email' => $sentEmail,
            'sent_wa' => $sentWa,
            'skipped_dedup' => $skippedDedup,
            'skipped_no_contact' => $skippedNoContact,
            'errors_count' => count($errors),
            'elapsed_seconds' => $elapsed,
            'channels' => $channels,
            'stage_override' => $stageOv,
            'throttle_ms' => $throttleMs,
        ]);
        $existing = array_slice($existing, 0, 50);
        file_put_contents($sumFile, json_encode($existing, JSON_PRETTY_PRINT));
    } catch (\Throwable $e) {}

    // v4.21.73: HTTP response was flushed earlier with just {job_id, queued}.
    // Final results are written to the job file for the polling JS to pick up.
    @file_put_contents($jobFile, json_encode([
        'job_id'      => $jobId,
        'started_at'  => (int)$startTs,
        'finished_at' => time(),
        'running'     => false,
        'attempted_total'    => count($invNums),
        'progress'           => count($logRows),
        'sent_email'         => $sentEmail,
        'sent_wa'            => $sentWa,
        'skipped_dedup'      => $skippedDedup,
        'skipped_no_contact' => $skippedNoContact,
        'errors_count'       => count($errors),
        'errors'             => $errors,
        'elapsed_seconds'    => $elapsed,
        'smtp_aborted_mid_run' => $smtpFailedHard,
        'email_disabled_reason' => $emailDisabledReason,
        'channels'           => $channels,
        'stage_override'     => $stageOv,
        'throttle_ms'        => $throttleMs,
        'finished_human'     => date('Y-m-d H:i:s'),
    ], JSON_PRETTY_PRINT));

    // ── Admin digest WhatsApp ────────────────────────────────────────────
    // v4.21.74: ONE summary message to admin at end of batch instead of N
    // pings during it. Lists who got messaged grouped by client.
    if ($sentEmail > 0 || $sentWa > 0) {
        try {
            // Filter to actually-touched rows
            $touchedRows = array_filter($logRows, function($r) {
                return $r['email_ok'] === true || $r['wa_ok'] === true;
            });

            $byClient = [];
            $totalAmt = 0;
            foreach ($touchedRows as $row) {
                $key = $row['client_name'];
                if (!isset($byClient[$key])) {
                    $byClient[$key] = ['invoices' => [], 'total' => 0];
                }
                $byClient[$key]['invoices'][] = $row;
                $amt = (float)preg_replace('/[^0-9.]/', '', $row['amount']);
                $byClient[$key]['total'] += $amt;
                $totalAmt += $amt;
            }

            $msg  = "📨 *Bulk send complete* (workbench)\n";
            $msg .= "_Triggered by *{$byName}* — " . date('D, d M Y H:i') . "_\n\n";
            $msg .= "*Summary:* {$sentEmail} email, {$sentWa} WhatsApp\n";
            if ($skippedDedup > 0)       $msg .= "*Skipped:* {$skippedDedup} (already sent)\n";
            if (count($errors) > 0)      $msg .= "*Errors:* " . count($errors) . "\n";
            $msg .= "*Total chased:* $" . number_format($totalAmt, 2) . "\n";
            $msg .= "*Clients:* " . count($byClient) . "\n";
            $msg .= "*Elapsed:* {$elapsed}s\n\n";

            uasort($byClient, function($a, $b) { return $b['total'] <=> $a['total']; });
            $msg .= "*Who got messaged:*\n";
            $shown = 0;
            foreach ($byClient as $clientName => $bucket) {
                if ($shown >= 20) break;
                $invSummary = [];
                foreach ($bucket['invoices'] as $r) {
                    $chans = [];
                    if ($r['email_ok'] === true) $chans[] = '📧';
                    if ($r['wa_ok']    === true) $chans[] = '💬';
                    $invSummary[] = "{$r['invoice_number']} (s{$r['stage']}, {$r['days']}d) " . implode('', $chans);
                }
                $cn = mb_strlen($clientName) > 35 ? mb_substr($clientName, 0, 32) . '...' : $clientName;
                $msg .= "• *{$cn}* — $" . number_format($bucket['total'], 2) . "\n";
                $msg .= "   " . implode(' · ', $invSummary) . "\n";
                $shown++;
            }
            if (count($byClient) > 20) {
                $msg .= "\n_…and " . (count($byClient) - 20) . " more (full list: dashboard → Overdue Workbench)_";
            }

            $notify->sendAdmin($msg, 'owb_bulk_send_summary_' . $jobId);
        } catch (\Throwable $e) {
            // Non-fatal — digest is a nice-to-have
        }
    }

    exit; // already flushed response, just stop
}

// owb_bulk_send_status — JS polls this every few seconds while a bulk-send
// job is running. Returns the job's current state JSON.
if ($act === 'owb_bulk_send_status' && $met === 'GET') {
    if (!$_owbAllowed()) $er2('Workbench requires admin or accountant role.', 403);
    $jobId = trim((string)($_GET['job_id'] ?? ''));
    if ($jobId === '' || !preg_match('/^owb_bs_[a-zA-Z0-9._]+$/', $jobId)) {
        $er2('Invalid job_id', 422);
    }
    $jobFile = $dataDir . '/owb_bulk_send_jobs/' . $jobId . '.json';
    if (!file_exists($jobFile)) {
        $er2('Job not found (may have expired)', 404);
    }
    $state = json_decode((string)@file_get_contents($jobFile), true) ?: [];
    $ok2($state);
}
