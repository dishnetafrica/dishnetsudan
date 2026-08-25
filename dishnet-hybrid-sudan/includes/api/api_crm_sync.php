<?php
// ═══════════════════════════════════════════════════════════════
// CRM SYNC / CLIENT INDEX
// ═══════════════════════════════════════════════════════════════

    // ══════════════════════════════════════════════════════════════════════

    // ══════════════════════════════════════════════════════════════════════
    // BACKGROUND CLIENT SYNC + SEARCH INDEX
    // GET  ?page=api&action=bg_client_sync   — fetch last 100 modified clients,
    //      merge into cache, rebuild search index. Called silently from browser.
    // GET  ?page=api&action=client_search_index — serve compact index as JSON.
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'bg_client_sync' && $met === 'GET') {
        // This endpoint is read-only cache refresh with no sensitive output.
        // Accept: Bearer token (PWA agent), plugin session (retailer), or
        // UISP admin session (no plugin login — valid same-origin browser request).
        $bgUser = $auth->tokenAuth() ?? $auth->currentRetailer();
        if (!$bgUser) {
            // UISP admin browsing plugin directly — authenticated by UISP, not plugin.
            // Verify same-origin via Referer header to prevent unauthenticated external calls.
            $ref  = $_SERVER['HTTP_REFERER'] ?? '';
            $host = $_SERVER['HTTP_HOST']    ?? 'x';
            if (empty($ref) || strpos($ref, $host) === false) {
                $er2('Unauthorized.', 401);
            }
            // Same-origin UISP admin — allowed
        }

        $metaKey  = 'client_delta_sync_meta.json';
        $meta     = $store->load($metaKey) ?? [];
        $lastRun  = (int)($meta['last_run_ts'] ?? 0);

        // Throttle: at most once per 90 seconds per request
        if (time() - $lastRun < 90) {
            $ok2(['synced' => false, 'reason' => 'throttled', 'next_in' => (90 - (time() - $lastRun))]);
        }

        if (!$crm->isConfigured()) {
            $ok2(['synced' => false, 'reason' => 'crm_not_configured']);
        }

        // Fetch last 100 clients (recently modified first in UCRM default sort)
        $fresh = $crm->get('clients?direction=DESC&limit=100');
        if (!is_array($fresh) || !count($fresh)) {
            $ok2(['synced' => false, 'reason' => 'no_data']);
        }

        // Merge into cache
        $existing = $store->load('ucrm_clients_cache.json') ?? [];
        $map = [];
        foreach ($existing as $c) { if (!empty($c['id'])) $map[(int)$c['id']] = $c; }
        $merged = 0;
        foreach ($fresh as $c) { if (!empty($c['id'])) { $map[(int)$c['id']] = $c; $merged++; } }
        $all = array_values($map);
        $store->save('ucrm_clients_cache.json', $all);

        // Rebuild global search index via shared helper (all fields: name/phone/email/plans/bal)
        $idx = _buildClientSearchIndex($store, $all);

        $store->save($metaKey, ['last_run_ts' => time(), 'last_run' => date('Y-m-d H:i:s'), 'count' => $merged]);
        $ok2(['synced' => true, 'merged' => $merged, 'total' => count($idx)]);
    }

    if ($act === 'client_search_index' && $met === 'GET') {
        // Serve compact search index — global, used by all plugin features
        $auth->requireLogin();
        $idx = $store->load('client_search_index.json') ?? [];
        // First-run fallback: build from full cache if index hasn't been created yet
        if (!count($idx)) {
            $full = $store->load('ucrm_clients_cache.json') ?? [];
            $idx = _buildClientSearchIndex($store, $full);
        }
        header('Cache-Control: private, max-age=60'); // browser can cache 60s
        $ok2($idx);
    }
    // ─── sync_now_ajax: Push pending KYC applications to CRM ───────────────────
    if ($act === 'sync_now_ajax') {
        // Allow session-based admin auth as fallback (for same-origin browser calls)
        $isAdmin = $me2['is_admin'] ?? false;
        if (!$isAdmin && !empty($_SESSION['kyc_retailer_id'])) {
            $sessRetailer = $store->findOne('retailers.json', 'id', (int)$_SESSION['kyc_retailer_id']);
            $isAdmin = $sessRetailer['is_admin'] ?? false;
            if ($isAdmin) $me2 = $sessRetailer; // use session user
        }
        if (!$isAdmin) {
            $er2('Admin access required', 403);
        }
        set_time_limit(120);

        // Load required services
        require_once dirname(__DIR__, 2) . '/lib/CrmQueue.php';
        require_once dirname(__DIR__, 2) . '/lib/WalletService.php';

        $queue  = new CrmQueue($store);
        $wallet = new WalletService($store);
        $notify = svc('notify');

        $pending = $queue->getPending(20);
        $log = []; $success = 0; $failed = 0; $processed = 0;
        $ts = function() { return date('H:i:s'); };

        foreach ($pending as $job) {
            $processed++;
            $jobId = $job['id'] ?? 0;
            $name  = trim(($job['firstname'] ?? '') . ' ' . ($job['lastname'] ?? ''));
            $log[] = $ts() . " Processing #{$jobId}: {$name}";

            try {
                // Create client in CRM
                $payload = [
                    'firstName'        => $job['firstname'] ?? '',
                    'lastName'         => $job['lastname'] ?? '',
                    'street1'          => $job['address'] ?? '',
                    'organizationId'   => 7,
                    'isLead'           => false,
                    'username'         => $job['username'] ?? null,
                ];
                $contacts = [];
                if (!empty($job['phone'])) $contacts[] = ['phone' => $job['phone'], 'name' => 'Primary'];
                if (!empty($job['email'])) $contacts[] = ['email' => $job['email'], 'name' => 'Primary'];
                if (!empty($contacts)) $payload['contacts'] = $contacts;

                $result = $crm->post('clients', $payload);
                if (empty($result['id'])) {
                    throw new \Exception('CRM returned no client ID');
                }
                $crmClientId = $result['id'];

                $queue->markCompleted($jobId, $crmClientId);
                $store->updateOne('kyc_applications.json', 'id', $job['application_id'] ?? 0, [
                    'status'        => 'new',
                    'crm_client_id' => $crmClientId,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);

                $log[] = $ts() . "   🎉 → CRM #{$crmClientId}";
                $success++;
            } catch (\Throwable $e) {
                $queue->markFailed($jobId, $e->getMessage());
                $log[] = $ts() . "   ✗ " . $e->getMessage();
                $failed++;
            }
        }

        $log[] = $ts() . " Done: {$success} synced, {$failed} failed.";
        $store->save('sync_last_run.json', [
            'ran_at'    => date('Y-m-d H:i:s'),
            'ran_by'    => $me2['name'] ?? 'Admin',
            'lines'     => $log,
            'success'   => $success,
            'failed'    => $failed,
            'processed' => $processed,
        ]);

        $ok2([
            'log'       => $log,
            'summary'   => $queue->getSummary(),
            'processed' => $processed,
            'success'   => $success,
            'failed'    => $failed,
        ], 'Sync complete');
    }
