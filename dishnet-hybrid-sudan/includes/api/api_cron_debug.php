<?php
// ═══════════════════════════════════════════════════════════════
// CRON / DEBUG / BACKUP / SERVE (pre-auth)
// ═══════════════════════════════════════════════════════════════


    // ─── DIAGNOSTIC: View master scheduler status ────────────────────────────
    // GET ?page=api&action=cron_status
    if ($act === 'cron_status' && $met === 'GET') {
        // $dataDir inherited from public.php (UCRM persistent)
        $schedule = $store->load('master_schedule.json') ?? [];
        
        // Debug: also try reading the file directly to check if it's a store issue
        $dbPath = $dataDir . '/plugin.sqlite3';
        $debugSchedule = [];
        try {
            $debugPdo = new \PDO('sqlite:' . $dbPath, null, null, [\PDO::ATTR_TIMEOUT => 3]);
            $debugPdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            // Check if table exists
            $tableCheck = $debugPdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='master_schedule'")->fetchColumn();
            if ($tableCheck) {
                $rows = $debugPdo->query("SELECT id, data FROM [master_schedule] LIMIT 5")->fetchAll(\PDO::FETCH_ASSOC);
                $debugSchedule = $rows;
            } else {
                $debugSchedule = ['note' => 'master_schedule table not found (normal — schedule stored in JSON)'];
            }
        } catch (\Throwable $e) {
            $debugSchedule = ['error' => $e->getMessage()];
        }
        
        // Check if cron is running (last run within 5 minutes)
        $lastMasterRun = 0;
        foreach ($schedule as $job => $info) {
            $lr = (int)($info['last_run'] ?? 0);
            if ($lr > $lastMasterRun) $lastMasterRun = $lr;
        }
        $cronRunning = (time() - $lastMasterRun) < 300;
        
        // Load integrity check result from last main.php run
        $integrityCheck = [];
        $integrityFile  = $dataDir . '/integrity_check.json';
        if (file_exists($integrityFile)) {
            $integrityCheck = @json_decode(file_get_contents($integrityFile), true) ?? [];
        }

        // Load backup meta
        $backupMeta = [];
        $backupMetaFile = $dataDir . '/last_backup_meta.json';
        if (file_exists($backupMetaFile)) {
            $backupMeta = @json_decode(file_get_contents($backupMetaFile), true) ?? [];
        }
        $backupDir = $dataDir . '/backups';
        $backupCount = count(glob($backupDir . '/auto-backup-*.zip') ?: []);
        $latestBackup = null;
        if ($backupCount > 0) {
            $backupFiles = glob($backupDir . '/auto-backup-*.zip') ?: [];
            usort($backupFiles, fn($a,$b) => filemtime($b) - filemtime($a));
            $latest = $backupFiles[0];
            $latestBackup = ['file' => basename($latest), 'size_kb' => round(filesize($latest)/1024,1), 'created' => date('Y-m-d H:i:s', filemtime($latest))];
        }

        $ok2([
            'cron_running'      => $cronRunning,
            'last_activity'     => $lastMasterRun > 0 ? date('Y-m-d H:i:s', $lastMasterRun) : 'never',
            'seconds_ago'       => $lastMasterRun > 0 ? (time() - $lastMasterRun) : null,
            'jobs'              => $schedule,
            'integrity'         => $integrityCheck,
            'backups'           => ['count' => $backupCount, 'latest' => $latestBackup, 'last_meta' => $backupMeta],
            'debug'             => [
                'data_dir'        => $dataDir,
                'db_path'         => $dbPath,
                'db_exists'       => file_exists($dbPath),
                'db_size_kb'      => file_exists($dbPath) ? round(filesize($dbPath)/1024,1) : 0,
                'schedule_count'  => count($schedule),
                'raw_table_rows'  => $debugSchedule,
            ],
        ]);
    }

    // ─── LTE SYNC RUN: Manually trigger sync with full error reporting ─────
    // GET ?page=api&action=lte_sync_run
    if ($act === 'lte_sync_run' && $met === 'GET') {
        $syncPath = dirname(__DIR__, 2) . '/cron_lte_sync.php';
        if (!file_exists($syncPath)) {
            $er2('cron_lte_sync.php not found at: ' . $syncPath, 500);
        }

        // Capture all output and errors
        ob_start();
        $prevErr = error_reporting(E_ALL);
        $errors = [];
        set_error_handler(function($no, $str, $file, $line) use (&$errors) {
            $errors[] = "PHP [{$no}]: {$str} in " . basename($file) . ":{$line}";
            return true;
        });

        $syncStart = microtime(true);
        try {
            include $syncPath;
        } catch (\Throwable $e) {
            $errors[] = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
        }
        $syncMs = round((microtime(true) - $syncStart) * 1000);

        restore_error_handler();
        error_reporting($prevErr);
        $output = ob_get_clean();

        // Read state after run — try both store and direct file
        $postState = $store->load('lte_sync_state.json') ?? [];

        // Also check direct file paths
        $pluginRootCheck = dirname(__DIR__, 2);
        require_once $pluginRootCheck . '/lib/bootstrap_data.php';
        $dataDirCheck = getDataDir($pluginRootCheck);
        $stateFileDirect = $dataDirCheck . '/lte_sync_state.json';
        $directState = file_exists($stateFileDirect)
            ? json_decode(file_get_contents($stateFileDirect), true)
            : null;

        $ok2([
            'ran_at'     => date('Y-m-d H:i:s'),
            'elapsed_ms' => $syncMs,
            'errors'     => $errors,
            'output'     => $output ?: null,
            'debug'      => [
                'dataDir_from_getDataDir' => $dataDirCheck,
                'state_file_path'         => $stateFileDirect,
                'state_file_exists'       => file_exists($stateFileDirect),
                'state_from_store'        => $postState ? ($postState['last_sync'] ?? 'no last_sync key') : 'empty',
                'state_from_file'         => $directState ? ($directState['last_sync'] ?? 'no last_sync key') : 'file not found',
            ],
            'sync_state' => [
                'last_sync' => ($directState['last_sync'] ?? $postState['last_sync'] ?? 'never'),
                'cursors'   => [
                    'users' => ($directState['users_max_id'] ?? $postState['users_max_id'] ?? 0),
                    'sims'  => ($directState['sims_max_id'] ?? $postState['sims_max_id'] ?? 0),
                    'topup' => ($directState['topup_max_id'] ?? $postState['topup_max_id'] ?? 0),
                ],
                'last_stats' => ($directState['last_stats'] ?? $postState['last_stats'] ?? null),
            ],
        ]);
    }

    // ─── LTE FEED TEST: Check if UCRM can reach the WHM server ────────────
    // GET ?page=api&action=lte_feed_test
    if ($act === 'lte_feed_test' && $met === 'GET') {
        $feedUrl   = $config['lte_feed_url'] ?? 'https://162.241.149.144/lte_feed.php';
        $feedToken = $config['lte_feed_token'] ?? 'dN4g-LtEfEeD-2026-sEcReT';

        $url = $feedUrl . '?table=counts';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['X-Feed-Token: ' . $feedToken, 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $ok2([
            'feed_url'     => $url,
            'http_code'    => $code,
            'curl_error'   => $err ?: null,
            'response_size'=> strlen($body ?: ''),
            'response_body'=> $body ? json_decode($body, true) : null,
            'connect_time' => round($info['connect_time'] ?? 0, 3),
            'total_time'   => round($info['total_time'] ?? 0, 3),
        ]);
    }

    // ─── LTE SYNC STATUS: View BlueCard sync progress ───────────────────────
    // GET ?page=api&action=lte_sync_status
    if ($act === 'lte_sync_status' && $met === 'GET') {
        $stateFilePath = $dataDir . '/lte_sync_state.json';
        $syncState = file_exists($stateFilePath)
            ? json_decode(file_get_contents($stateFilePath), true)
            : [];

        // Count records in SQLite tables
        $pdo = $store->getPdo();
        $counts = [];
        $tables = ['lte_subscribers', 'lte_packages', 'lte_sims', 'lte_renewals', 'lte_data_mgmt'];
        foreach ($tables as $t) {
            try {
                $row = $pdo->query("SELECT COUNT(*) as c FROM {$t}")->fetch();
                $counts[$t] = (int)($row['c'] ?? 0);
            } catch (\Exception $e) {
                $counts[$t] = 'table not found';
            }
        }

        // Check JSON file sizes
        $jsonFiles = [];
        $jsons = ['lte_subscribers.json', 'lte_packages.json', 'lte_sims.json', 'lte_renewals.json', 'lte_subscriptions.json'];
        foreach ($jsons as $jf) {
            $fp = $dataDir . '/' . $jf;
            if (file_exists($fp)) {
                $d = json_decode(file_get_contents($fp), true);
                $jsonFiles[$jf] = is_array($d) ? count($d) . ' records' : 'invalid JSON';
            } else {
                $jsonFiles[$jf] = 'not created yet';
            }
        }

        // Active vs suspended breakdown
        try {
            $active = (int)$pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE status='active' AND deleted_at IS NULL")->fetchColumn();
            $suspended = (int)$pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE status='suspended' AND deleted_at IS NULL")->fetchColumn();
            $retailers = (int)$pdo->query("SELECT COUNT(*) FROM lte_subscribers WHERE service_type='retailer' AND deleted_at IS NULL")->fetchColumn();
        } catch (\Exception $e) {
            $active = $suspended = $retailers = '?';
        }

        // Revenue from renewals
        try {
            $monthRev = $pdo->query("SELECT COALESCE(SUM(amount_paid),0) FROM lte_renewals WHERE created_at >= date('now','start of month')")->fetchColumn();
        } catch (\Exception $e) {
            $monthRev = 0;
        }

        $ok2([
            'sync_state'      => [
                'last_sync'   => $syncState['last_sync'] ?? 'never',
                'cursors'     => [
                    'users_max_id'   => $syncState['users_max_id'] ?? 0,
                    'sims_max_id'    => $syncState['sims_max_id'] ?? 0,
                    'topup_max_id'   => $syncState['topup_max_id'] ?? 0,
                    'datamgmt_max_id'=> $syncState['datamgmt_max_id'] ?? 0,
                ],
                'last_stats'  => $syncState['last_stats'] ?? null,
            ],
            'sqlite_counts'   => $counts,
            'json_files'      => $jsonFiles,
            'summary'         => [
                'active'      => $active,
                'suspended'   => $suspended,
                'retailers'   => $retailers,
                'month_revenue' => round((float)$monthRev, 2),
            ],
            'bluecard_counts' => $syncState['bluecard_counts'] ?? null,
            'lte_revenue'     => $syncState['lte_revenue'] ?? null,
        ]);
    }

    // ─── WEB-BASED CRON TRIGGER (no server access needed) ─────────────────────
    // GET ?page=api&action=cron_trigger&key=dishnet2026
    // Use with: cron-job.org, UptimeRobot, or any external scheduler
    // Set to run every 5 minutes
    if ($act === 'cron_trigger' && $met === 'GET') {
        $key = $_GET['key'] ?? '';
        if ($key !== 'dishnet2026') $er2('Invalid cron key', 403);
        
        ob_start();
        $startTime = microtime(true);
        
        try {
            // Run master.php directly
            $masterPath = dirname(__DIR__, 2) . '/cron/master.php';
            if (file_exists($masterPath)) {
                include $masterPath;
                $output = ob_get_clean();
                $elapsed = round((microtime(true) - $startTime) * 1000);
                
                $ok2([
                    'ran_at'      => date('Y-m-d H:i:s'),
                    'elapsed_ms'  => $elapsed,
                    'output'      => $output,
                    'status'      => 'success',
                    'debug'       => [
                        'master_dataDir' => $dataDir ?? 'not set',
                        'master_store'   => isset($store) ? get_class($store) : 'not set',
                    ],
                ]);
            } else {
                ob_end_clean();
                $er2('master.php not found', 500);
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            $er2('Cron error: ' . $e->getMessage(), 500);
        }
    }

    // ─── DIAGNOSTIC: View webhook log ───────────────────────────────────────
    // GET ?page=api&action=webhook_log
    if ($act === 'webhook_log' && $met === 'GET') {
        // $dataDir inherited from public.php (UCRM persistent)
        $logFile = $dataDir . '/webhook_log.json';
        $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        if (!is_array($logs)) $logs = [];
        $ok2([
            'count' => count($logs),
            'logs'  => array_slice($logs, 0, 50),
        ]);
    }

    // ─── MANUAL: Trigger catchup sync now ────────────────────────────────────
    // GET ?page=api&action=run_catchup_sync
    if ($act === 'run_catchup_sync' && $met === 'GET') {
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/cron/payment_catchup_sync.php';
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $er2('Catchup sync failed: ' . $e->getMessage(), 500);
        }
        $ok2([
            'output' => $output,
            'ran_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ─── DEBUG: Test payment sync step-by-step ───────────────────────────────
    // GET ?page=api&action=debug_payment_sync
    if ($act === 'debug_payment_sync' && $met === 'GET') {
        require_once dirname(__DIR__, 2) . '/lib/PaymentUuids.php';
        $cols = $store->load('payment_collections.json') ?? [];
        $pending = array_filter($cols, function($c) { return empty($c['crm_synced']); });
        $pendingList = [];
        foreach (array_slice(array_values($pending), 0, 20) as $c) {
            $pendingList[] = [
                'id'              => $c['id'] ?? '?',
                'customer_name'   => $c['customer_name'] ?? '',
                'crm_customer_id' => $c['crm_customer_id'] ?? 0,
                'amount'          => $c['amount'] ?? 0,
                'method'          => $c['method'] ?? 'Cash',
                'method_uuid'     => PaymentUuids::resolve($c['method'] ?? 'Cash'),
                'retailer_name'   => $c['retailer_name'] ?? '',
                'created_at'      => $c['created_at'] ?? '',
                'sync_attempts'   => $c['sync_attempts'] ?? 0,
                'last_sync_attempt' => $c['last_sync_attempt'] ?? null,
                'next_retry_at'   => $c['next_retry_at'] ?? null,
                'sync_status'     => $c['sync_status'] ?? 'pending',
                'last_crm_error'  => $c['last_crm_error'] ?? null,
            ];
        }
        
        // Count by status
        $gaveUp = count(array_filter($pending, function($c) { return ($c['sync_status'] ?? '') === 'gave_up'; }));
        $retrying = count(array_filter($pending, function($c) { 
            return ($c['sync_attempts'] ?? 0) > 0 && ($c['sync_status'] ?? '') !== 'gave_up'; 
        }));
        $fresh = count($pending) - $gaveUp - $retrying;
        
        // Test CRM connection
        $crmOk = false;
        $crmError = null;
        $crmTest = null;
        if ($crm->isConfigured()) {
            try {
                $crmTest = $crm->get('version');
                $crmOk = !empty($crmTest);
            } catch (\Throwable $e) {
                $crmError = $e->getMessage();
            }
        }
        
        // Test single payment POST (dry run - just show what would be sent)
        $testPayload = null;
        if (!empty($pendingList)) {
            $first = $pendingList[0];
            $testPayload = [
                'clientId'     => (int)$first['crm_customer_id'],
                'methodId'     => $first['method_uuid'],
                'amount'       => (float)$first['amount'],
                'currencyCode' => 'USD',
                'note'         => 'TEST - Collected by '.$first['retailer_name'].' via DishNet PWA',
                'applyToInvoicesAutomatically' => true,
            ];
        }
        
        $ok2([
            'crm_configured'     => $crm->isConfigured(),
            'crm_base_url'       => $crm->getBaseUrl(),
            'crm_connection_ok'  => $crmOk,
            'crm_version'        => $crmTest,
            'crm_error'          => $crmError,
            'total_collections'  => count($cols),
            'pending_sync'       => count($pending),
            'pending_fresh'      => $fresh,
            'pending_retrying'   => $retrying,
            'pending_gave_up'    => $gaveUp,
            'pending_list'       => $pendingList,
            'test_payload'       => $testPayload,
            'payment_methods'    => [
                'Cash'          => PaymentUuids::CASH,
                'Bank_Transfer' => PaymentUuids::BANK_TRANSFER,
            ],
            'retry_config'       => [
                'max_attempts'   => 5,
                'backoff_secs'   => [300, 900, 2700, 7200, 21600],
                'backoff_human'  => ['5m', '15m', '45m', '2h', '6h'],
            ],
        ]);
    }

    // ─── DEBUG: Actually test one payment POST ───────────────────────────────
    // GET ?page=api&action=test_payment_post&collection_id=123
    if ($act === 'test_payment_post' && $met === 'GET') {
        $colId = (int)($_GET['collection_id'] ?? 0);
        if (!$colId) $er2('collection_id required', 400);
        
        $cols = $store->load('payment_collections.json') ?? [];
        $col = null;
        foreach ($cols as $c) {
            if (($c['id'] ?? 0) == $colId) { $col = $c; break; }
        }
        if (!$col) $er2('Collection not found', 404);
        
        $custId = (int)($col['crm_customer_id'] ?? 0);
        if (!$custId) $er2('No CRM customer ID on this collection', 400);
        
        $payload = [
            'clientId'     => $custId,
            'methodId'     => PaymentUuids::resolve($col['method'] ?? 'Cash'),
            'amount'       => (float)$col['amount'],
            'currencyCode' => 'USD',
            'note'         => 'Collected by '.($col['retailer_name']??'agent').' via DishNet PWA',
            'applyToInvoicesAutomatically' => true,
        ];
        
        $result = $crm->post('payments', $payload);
        $lastErr = $crm->getLastError();
        
        $ok2([
            'collection_id' => $colId,
            'payload_sent'  => $payload,
            'crm_response'  => $result,
            'crm_error'     => $lastErr,
            'success'       => !empty($result) && isset($result['id']),
        ]);
    }

    // ─── POST force_retry_collection — manually re-push a stuck collection ────
    // POST ?page=api&action=force_retry_collection  { collection_id: 123 }
    // Admin only. Resets retry counter and immediately attempts CRM push.
    if ($act === 'force_retry_collection' && $met === 'POST') {
        // Pre-auth context — read session directly to check admin
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_frcSess    = $_SESSION['kyc_retailer'] ?? null;
        $_frcRetailer = $_frcSess['cached_record'] ?? $_frcSess ?? [];
        $_frcIsAdmin  = (bool)($_frcRetailer['is_admin'] ?? false);
        if (!$_frcIsAdmin) $er2('Admin access required.', 403);
        $retailer = $_frcRetailer;
        require_once dirname(__DIR__, 2) . '/lib/PaymentUuids.php';

        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $colId = (int)($body['collection_id'] ?? $_POST['collection_id'] ?? 0);
        $force = !empty($body['force']) || !empty($_POST['force']);
        if (!$colId) $er2('collection_id required', 400);

        // Load collection
        $cols = $store->load('payment_collections.json') ?? [];
        $col  = null;
        foreach ($cols as $c) {
            if ((int)($c['id'] ?? 0) === $colId) { $col = $c; break; }
        }
        if (!$col) $er2('Collection not found.', 404);
        // v4.21.58: allow ?force=1 override for collections that were marked
        // synced but whose CRM payment ID is actually a different collection's
        // payment (caused by the v4.21.56-and-earlier dedup bug). Operator
        // confirms manually before forcing a duplicate post.
        if (!empty($col['crm_synced']) && !$force) {
            $ok2(['already_synced' => true, 'crm_payment_id' => $col['crm_payment_id'] ?? null],
                 'Already synced. Pass force=1 to push again (will create a NEW UCRM payment).');
        }

        $custId = (int)($col['crm_customer_id'] ?? 0);
        if (!$custId) $er2('No CRM customer ID on this collection. Cannot push to UCRM.', 400);

        // Build payload — v4.21.58: when collection has a numeric invoice_id,
        // apply payment to that specific UCRM invoice (not "oldest unpaid").
        // The earlier comment claiming invoice_id was a text reference was
        // wrong — collect_payment.php line 1098 passes inv.id (numeric UCRM
        // invoice ID) to cpSelectInvoice. The HTTP 422 the old comment cited
        // happened because the previous attempt used singular 'invoiceId'
        // field; UCRM 2.x expects plural array 'invoiceIds'.
        $colInvId = (int)($col['invoice_id'] ?? 0);
        $payload = [
            'clientId'     => $custId,
            'methodId'     => PaymentUuids::resolve($col['method'] ?? 'Cash'),
            'amount'       => (float)$col['amount'],
            'currencyCode' => 'USD',
            'note'         => 'Collected by '.($col['retailer_name'] ?? 'agent')
                            . ' | Manual retry by '.($retailer['name'] ?? 'Admin')
                            . ' | Ref: RETRY-COL-' . $colId
                            . ($colInvId > 0 ? ' | Inv #'.$colInvId : ''),
        ];
        if ($colInvId > 0) {
            $payload['invoiceIds'] = [$colInvId];
            $payload['applyToInvoicesAutomatically'] = false;
        } else {
            $payload['applyToInvoicesAutomatically'] = true;
        }

        // v4.13.1 FIX — Admin override: always use master plugin key.
        // Previously this path used the *collecting* retailer's personal key, which
        // meant that if their UCRM App Key was stale/revoked/disabled, admin retry
        // would fail with HTTP 401 forever — the same reason the collection was
        // stuck in the first place.
        //
        // Master key ($crm) is the plugin's auto-generated key from ucrm.json —
        // guaranteed valid as long as the plugin is installed. Attribution to the
        // original retailer is preserved in the payment note (built above):
        //   "Collected by X | Manual retry by Admin Y | Ref: RETRY-COL-N"
        //
        // The automatic cron (cron/crm_payment_retry.php) and the normal payment
        // post paths (post_sales.php, post_field.php, post_kyc.php) still use
        // personal keys — that's intentional for per-retailer UCRM audit trail.
        // Only the *admin force retry* path uses the master key.
        $crmForPayment = $crm;

        // Lookup retailer record only for logging context (not for key selection)
        $retailers   = $store->load('retailers.json') ?? [];
        $colRetailer = null;
        foreach ($retailers as $r) {
            if ((int)($r['id'] ?? 0) === (int)($col['retailer_id'] ?? 0)) { $colRetailer = $r; break; }
        }

        // Attempt payment POST
        $result  = $crmForPayment->post('payments', $payload);
        $success = !empty($result) && isset($result['id']);

        if ($success) {
            // Mark collection as synced
            $store->updateOne('payment_collections.json', 'id', $colId, [
                'crm_synced'      => true,
                'crm_payment_id'  => $result['id'],
                'crm_sync_status' => 'synced',
                'crm_sync_error'  => null,
            ]);
            // Mark retry queue entry as synced too (if exists)
            $retryQueue = $store->load('crm_payment_retry.json') ?? [];
            $rqChanged  = false;
            foreach ($retryQueue as $i => $rq) {
                if ((int)($rq['collection_id'] ?? 0) === $colId) {
                    $retryQueue[$i]['status']    = 'synced';
                    $retryQueue[$i]['crm_id']    = $result['id'];
                    $retryQueue[$i]['synced_at'] = date('Y-m-d H:i:s');
                    $rqChanged = true;
                }
            }
            if ($rqChanged) $store->save('crm_payment_retry.json', $retryQueue);

            $ok2([
                'synced'        => true,
                'collection_id' => $colId,
                'crm_payment_id'=> $result['id'],
                'customer'      => $col['customer_name'] ?? '',
                'amount'        => $col['amount'],
            ], 'Payment synced to CRM successfully.');
        } else {
            $lastErr = $crmForPayment->getLastError();
            $errMsg  = isset($lastErr['http_code'])
                ? 'HTTP ' . $lastErr['http_code'] . ': ' . json_encode($lastErr['response'] ?? '')
                : ($lastErr['curl_error'] ?? json_encode($lastErr));

            // Update collection with latest error
            $store->updateOne('payment_collections.json', 'id', $colId, [
                'crm_sync_status' => 'failed',
                'crm_sync_error'  => $errMsg,
            ]);

            $er2('CRM push failed: ' . $errMsg, 502);
        }
    }

    // ─── BACKUP: Download all plugin data as ZIP ─────────────────────────────
    // GET ?page=api&action=backup_download&key=dishnet2026
    if ($act === 'backup_download' && $met === 'GET') {
        $key = $_GET['key'] ?? '';
        if ($key !== 'dishnet2026') $er2('Invalid backup key', 403);
        
        // $dataDir inherited from public.php (UCRM persistent)
        $zipFile = sys_get_temp_dir() . '/dishnet-backup-' . date('Y-m-d-His') . '.zip';
        
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            $er2('Failed to create ZIP', 500);
        }
        
        // Add all JSON files
        $files = glob($dataDir . '/*.json');
        foreach ($files as $f) {
            $zip->addFile($f, 'data/' . basename($f));
        }
        
        // Add SQLite database if exists
        $dbFile = $dataDir . '/dishnet.sqlite';
        if (file_exists($dbFile)) {
            $zip->addFile($dbFile, 'data/dishnet.sqlite');
        }
        
        // Add config (already in glob above, but explicit for clarity)
        $configFile = $dataDir . '/config.json';
        if (file_exists($configFile)) {
            $zip->addFile($configFile, 'data/config.json');
        }
        
        $zip->close();
        
        // Send file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="dishnet-backup-' . date('Y-m-d-His') . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
        exit;
    }

    // ── PUBLIC: Serve temp PDF files (no auth — WhatsML fetches these) ────
    // GET ?page=api&action=serve_temp_pdf&file=inv_12345_abc.pdf&token=HMAC
    // Files auto-expire after 10 minutes. Token prevents guessing.
    if ($act === 'serve_temp_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $tempDir = $dataDir . '/temp_pdf';
        $path    = $tempDir . '/' . $file;
        $metaPath = $path . '.meta';

        if (!file_exists($path) || !file_exists($metaPath)) $er2('File not found or expired', 404);

        // Verify HMAC token
        $meta = json_decode(file_get_contents($metaPath), true) ?: [];
        if (!hash_equals($meta['token'] ?? '', $token)) $er2('Invalid token', 403);

        // Check expiry (10 min)
        if (time() - (int)($meta['created'] ?? 0) > 600) {
            @unlink($path);
            @unlink($metaPath);
            $er2('File expired', 410);
        }

        // Serve the PDF
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);

        // Clean up after serving
        @unlink($path);
        @unlink($metaPath);
        exit;
    }

    // ── PUBLIC: Serve permanent quote PDF files (no auth — WhatsML fetches these) ──
    // GET ?page=api&action=serve_quote_pdf&file=quote_123_abc.pdf&token=HMAC
    // Token is daily HMAC(file+date, webhook_secret) — accepts today and yesterday.
    if ($act === 'serve_quote_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $pdfDir   = $dataDir . '/quote_pdfs';
        $path     = $pdfDir . '/' . $file;
        $metaPath = $path . '.meta';

        if (!file_exists($path)) $er2('Quote PDF not found', 404);

        // Verify token — stored in .meta OR recompute from daily HMAC
        $secret = ($config['webhook_secret'] ?? 'dishnet');
        $valid  = false;
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true) ?: [];
            if (hash_equals($meta['token'] ?? '', $token)) $valid = true;
        }
        // Also accept recomputed token for today and yesterday (daily rotation)
        if (!$valid) {
            $todayTok = hash_hmac('sha256', $file . date('Ymd'), $secret);
            $ydayTok  = hash_hmac('sha256', $file . date('Ymd', strtotime('-1 day')), $secret);
            if (hash_equals($todayTok, $token) || hash_equals($ydayTok, $token)) $valid = true;
        }
        if (!$valid) $er2('Invalid token', 403);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        $dispName = ($meta['filename'] ?? null) ?: str_replace('_', '-', $file);
        header('Content-Disposition: inline; filename="' . $dispName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }

    // ── PUBLIC: Serve delivery acknowledgment PDF (no auth — WASender fetches these) ──
    // GET ?page=api&action=serve_delivery_pdf&file=DishNet_starlink_KYC-2026-0347.pdf&token=HMAC
    // Permanent storage — these are legal documents. Token uses daily HMAC.
    if ($act === 'serve_delivery_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $pdfDir = $dataDir . '/delivery_pdfs';
        $path   = $pdfDir . '/' . $file;

        if (!file_exists($path)) $er2('Delivery PDF not found', 404);

        // Verify token — check .meta file OR recompute daily HMAC
        $secret = ($config['webhook_secret'] ?? 'dishnet');
        $valid  = false;
        $metaPath = $path . '.meta';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true) ?: [];
            if (hash_equals($meta['token'] ?? '', $token)) $valid = true;
        }
        // Accept recomputed token for today and yesterday (daily rotation)
        if (!$valid) {
            $todayTok = hash_hmac('sha256', $file . date('Ymd'), $secret);
            $ydayTok  = hash_hmac('sha256', $file . date('Ymd', strtotime('-1 day')), $secret);
            if (hash_equals($todayTok, $token) || hash_equals($ydayTok, $token)) $valid = true;
        }
        if (!$valid) $er2('Invalid token', 403);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        $dispName = ($meta['filename'] ?? null) ?: str_replace('_', '-', $file);
        header('Content-Disposition: inline; filename="' . $dispName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=604800'); // 7 days — legal docs don't change
        readfile($path);
        exit;
    }

    // ── PUBLIC: Serve payment receipt PDF (no auth — WASender fetches these) ──
    // GET ?page=api&action=serve_receipt_pdf&file=DishNet-Receipt-8386.pdf&token=HMAC
    if ($act === 'serve_receipt_pdf') {
        $file  = basename(trim($_GET['file']  ?? ''));
        $token = trim($_GET['token'] ?? '');
        if (!$file || !$token) $er2('Missing file or token', 400);

        $pdfDir = $dataDir . '/receipt_pdfs';
        $path   = $pdfDir . '/' . $file;

        if (!file_exists($path)) $er2('Receipt PDF not found', 404);

        $secret = ($config['webhook_secret'] ?? 'dishnet');
        $valid  = false;
        $metaPath = $path . '.meta';
        if (file_exists($metaPath)) {
            $meta = json_decode(file_get_contents($metaPath), true) ?: [];
            if (hash_equals($meta['token'] ?? '', $token)) $valid = true;
        }
        if (!$valid) {
            $todayTok = hash_hmac('sha256', $file . date('Ymd'), $secret);
            $ydayTok  = hash_hmac('sha256', $file . date('Ymd', strtotime('-1 day')), $secret);
            if (hash_equals($todayTok, $token) || hash_equals($ydayTok, $token)) $valid = true;
        }
        if (!$valid) $er2('Invalid token', 403);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=86400');
        readfile($path);
        exit;
    }


    // ── Reconcile: void double-counted handover entries in cashbook ──────────
    // Handovers are internal cash transfers (Diko→Rupesh). The actual revenue
    // was already posted by the CRM webhook for each individual payment.
    // This endpoint finds and voids the duplicate handover entries.
    if ($act === 'cashbook_handover_reconcile' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);

        require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
        $cb  = new CashbookService($store, $dataDir);
        $pdo = $store->getPdo();

        $dryRun = !empty($body['dry_run']);

        // Find all handover entries in cb_ledger
        $hovEntries = $pdo->prepare(
            "SELECT id, sr, date, amount, person, description, validation_ref, status
             FROM cb_ledger
             WHERE (category_raw = 'Cash Handover' OR validation_ref LIKE 'HOV-%')
               AND direction = 'in'
               AND status != 'voided_reconcile'
             ORDER BY date DESC"
        );
        $hovEntries->execute();
        $rows = $hovEntries->fetchAll(\PDO::FETCH_ASSOC);

        $totalAmount = 0;
        $voided = [];

        foreach ($rows as $row) {
            $totalAmount += (float)$row['amount'];
            $voided[] = [
                'id'     => (int)$row['id'],
                'sr'     => $row['sr'],
                'date'   => $row['date'],
                'amount' => (float)$row['amount'],
                'person' => $row['person'],
                'ref'    => $row['validation_ref'],
                'desc'   => substr($row['description'], 0, 60),
            ];
        }

        if (!$dryRun && !empty($rows)) {
            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare(
                "UPDATE cb_ledger SET status = 'voided_reconcile',
                    description = description || ' [VOIDED: handover double-count reconcile " . date('Y-m-d H:i') . "]'
                 WHERE id IN ({$placeholders})"
            )->execute($ids);

            logActivity($dataDir, 'handover_reconcile', 'Voided double-counted handover entries',
                count($rows) . ' entries totalling $' . number_format($totalAmount, 2) . ' voided by ' . ($me2['name'] ?? 'Admin'));
        }

        $ok2([
            'dry_run'       => $dryRun,
            'entries_found'  => count($rows),
            'total_amount'   => round($totalAmount, 2),
            'voided'         => $voided,
            'message'        => $dryRun
                ? count($rows) . ' handover entries found totalling $' . number_format($totalAmount, 2) . '. Run again with dry_run=false to void them.'
                : count($rows) . ' entries voided. $' . number_format($totalAmount, 2) . ' removed from cashbook.',
        ]);
    }

    // ─── VIEW ERROR LOGS (admin link — no SSH needed) ──────────────────────
    // GET ?page=api&action=import_cashbook_csv  (admin only, one-time restore)
    if ($act === 'import_cashbook_csv' && $met === 'GET') {
        require_once dirname(__DIR__, 2) . '/import_cashbook_csv.php';
        exit;
    }

    // GET ?page=api&action=receipt_pdf_debug
    // GET ?page=api&action=quote_wa_log
    // ── Serve expense receipt photo ────────────────────────────────────
    // GET ?page=api&action=expense_photo&id=17
    // Serves the receipt image for an expense. Staff can view their own, admin can view all.
    // ── Run expense migration (Phase 3) ────────────────────────────
    if ($act === 'migrate_expenses' && $met === 'POST') {
        require_once $GLOBALS['_PLUGIN_ROOT'] . '/migrations/migrate_expenses_data.php';
        $result = migrateFieldExpenses($store, $dataDir);
        $ok2($result, $result['message'] ?? 'Done');
    }

    // ── KYC Funnel: exclude/restore (admin only) ────────────────────
    if ($act === 'kyc_exclude' && $met === 'POST') {
        if (empty($_sess['is_admin'])) $er2('Admin only', 403);
        $appId  = (int)($_POST['app_id'] ?? 0);
        $reason = $_POST['reason'] ?? 'cancelled';
        if (!$appId) $er2('Missing app_id', 400);
        if (!in_array($reason, ['cancelled', 'demo', 'duplicate', 'test'], true)) $reason = 'cancelled';
        require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/KycFunnelService.php';
        $svc = new KycFunnelService($store->getPdo(), $store);
        $svc->excludeApp($appId, $reason);
        $ok2(['app_id' => $appId, 'reason' => $reason], 'Excluded from funnel');
    }
    if ($act === 'kyc_restore' && $met === 'POST') {
        if (empty($_sess['is_admin'])) $er2('Admin only', 403);
        $appId = (int)($_POST['app_id'] ?? 0);
        if (!$appId) $er2('Missing app_id', 400);
        require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/KycFunnelService.php';
        $svc = new KycFunnelService($store->getPdo(), $store);
        $svc->restoreApp($appId);
        $ok2(['app_id' => $appId], 'Restored to funnel');
    }

    if ($act === 'expense_photo') {
        $expId = (int)($_GET['id'] ?? 0);
        if (!$expId) $er2('Missing expense id', 400);

        // Authenticate via session (browser link) or API token
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_sess = $_SESSION['kyc_retailer'] ?? null;
        $_photoUser = $_sess['cached_record'] ?? $_sess ?? [];
        $_isPhotoAdmin = !empty($_photoUser['is_admin']) || in_array($_photoUser['role'] ?? '', ['accountant', 'field_accountant', 'support_leader'], true);
        $_photoUserId = (int)($_photoUser['id'] ?? 0);
        if (!$_photoUserId) $er2('Login required', 401);

        // Check both expense systems
        $receiptPath = null;

        // 1. Check staff_expenses SQLite (new system)
        try {
            $expRow = $store->getPdo()->prepare("SELECT staff_id, receipt_path FROM staff_expenses WHERE id = ?");
            $expRow->execute([$expId]);
            $row = $expRow->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['receipt_path'])) {
                $receiptPath = $row['receipt_path'];
                if (!$_isPhotoAdmin && (int)($row['staff_id'] ?? 0) !== $_photoUserId) {
                    $er2('Access denied', 403);
                }
            }
        } catch (\Throwable $e) {}

        // 2. Check cash_expenses.json (old system)
        if (!$receiptPath) {
            $exps = $store->load('cash_expenses.json') ?? [];
            foreach ($exps as $ex) {
                if ((int)($ex['id'] ?? 0) === $expId && !empty($ex['photo'])) {
                    $receiptPath = $ex['photo'];
                    if (!$_isPhotoAdmin && (int)($ex['collector_id'] ?? 0) !== $_photoUserId) {
                        $er2('Access denied', 403);
                    }
                    break;
                }
            }
        }

        if (!$receiptPath) $er2('No receipt photo for this expense', 404);

        // Resolve full path (handle both with and without uploads/ prefix)
        $fullPath = $dataDir . '/' . $receiptPath;
        if (!file_exists($fullPath)) $fullPath = $dataDir . '/uploads/' . $receiptPath;
        if (!file_exists($fullPath)) $er2('Receipt file not found on disk', 404);

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
        $mime = $mimes[$ext] ?? 'application/octet-stream';
        $isThumb = !empty($_GET['thumb']);

        while (ob_get_level() > 0) ob_end_clean();

        // Thumbnail: resize to 300px width, compress to ~30KB
        if ($isThumb && in_array($ext, ['jpg','jpeg','png']) && function_exists('imagecreatefromjpeg')) {
            $thumbDir = $dataDir . '/uploads/thumbs';
            if (!is_dir($thumbDir)) @mkdir($thumbDir, 0755, true);
            $thumbFile = $thumbDir . '/th_' . md5($fullPath) . '.jpg';

            // Serve cached thumb if exists and newer than source
            if (file_exists($thumbFile) && filemtime($thumbFile) >= filemtime($fullPath)) {
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . filesize($thumbFile));
                header('Cache-Control: public, max-age=604800');
                readfile($thumbFile);
                exit;
            }

            // Generate thumbnail
            $src = null;
            if ($ext === 'png') $src = @imagecreatefrompng($fullPath);
            else $src = @imagecreatefromjpeg($fullPath);

            if ($src) {
                $w = imagesx($src); $h = imagesy($src);
                $newW = 300; $newH = (int)($h * ($newW / max($w, 1)));
                $thumb = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagejpeg($thumb, $thumbFile, 60);
                imagedestroy($src);
                imagedestroy($thumb);

                header('Content-Type: image/jpeg');
                header('Content-Length: ' . filesize($thumbFile));
                header('Cache-Control: public, max-age=604800');
                readfile($thumbFile);
                exit;
            }
            // Fallback: serve original if GD fails
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: private, max-age=86400');
        readfile($fullPath);
        exit;
    }

    if ($act === 'quote_wa_log' && $met === 'GET') {
        $logFile = $dataDir . '/quote_wa_log.json';
        $log = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?: []) : [];
        $ok2(['entries' => count($log), 'log' => array_slice($log, 0, 30)]);
    }



    // ─────────────────────────────────────────────────────────────────────────
    // POST ?page=api&action=wa_admin_alert
    // Body: {"message": "...", "source": "..."}
    // Sends a WhatsApp message to the admin phone (whatsapp_admin_phone config).
    // No auth — uses internal-call check via referrer or shared marker header.
    // Used by Data Report's cron_auto_block.php to send digests.
    // ─────────────────────────────────────────────────────────────────────────
    if ($act === 'wa_admin_alert' && $met === 'POST') {
        $body = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
        $message = trim((string)($body['message'] ?? ''));
        $source  = trim((string)($body['source'] ?? 'unknown'));

        if ($message === '') {
            $er2('message field required', 400);
            return;
        }

        $adminPhone = preg_replace('/[^0-9]/', '', trim($config['whatsapp_admin_phone'] ?? ''));
        if (!$adminPhone) {
            $er2('whatsapp_admin_phone not configured', 500);
            return;
        }

        try {
            $notify = new \NotificationService($store, $config);
            // sendVia: sender, phone, message, event, vars
            $notify->sendVia(\NotificationService::SUPPORT, $adminPhone, $message, 'admin_alert_' . $source, []);
            $ok2(['sent' => true, 'phone' => $adminPhone, 'source' => $source, 'len' => strlen($message)]);
        } catch (\Throwable $e) {
            $er2('Send failed: ' . $e->getMessage(), 500);
        }
        return;
    }

    if ($act === 'receipt_pdf_debug' && $met === 'GET') {
        $queue = $store->load('receipt_pdf_queue.json') ?? [];
        $logFile = $dataDir . '/quote_wa_log.json';
        $log = file_exists($logFile) ? (json_decode(file_get_contents($logFile), true) ?: []) : [];
        $receiptLog = array_values(array_filter($log, function($l) {
            return stripos($l['msg'] ?? '', 'receipt') !== false;
        }));
        $failedCount = count(array_filter($queue, function($r) {
            return !empty($r['sent']) && ($r['skip_reason'] ?? '') === 'pdf_unavailable';
        }));
        $ok2([
            'queue_total'   => count($queue),
            'queue_pending' => count(array_filter($queue, function($r) { return empty($r['sent']); })),
            'queue_failed'  => $failedCount,
            'retry_url'     => '?page=api&action=receipt_pdf_retry',
            'queue'         => array_slice($queue, -20),
            'recent_log'    => array_slice($receiptLog, 0, 30),
        ]);
    }

    // POST ?page=api&action=receipt_pdf_retry — reset failed receipts for retry
    if ($act === 'receipt_pdf_retry') {
        $queue = $store->load('receipt_pdf_queue.json') ?? [];
        $reset = 0;
        foreach ($queue as &$rq) {
            if (!empty($rq['sent']) && ($rq['skip_reason'] ?? '') === 'pdf_unavailable') {
                $rq['sent']        = false;
                $rq['skip_reason'] = null;
                $rq['retry_count'] = 0;
                $rq['queued_at']   = time();
                $reset++;
            }
        }
        unset($rq);
        if ($reset > 0) $store->save('receipt_pdf_queue.json', $queue);
        $ok2(['reset' => $reset], "Re-queued {$reset} failed receipt(s) for retry with new API path.");
    }

    // ── Force-run a daily cron job (bypasses hour gate) ──────────────────
    // GET ?page=api&action=force_cron_job&job=cashbook_summary
    if ($act === 'force_cron_job') {
        $jobName = trim($_GET['job'] ?? '');
        $allowedJobs = [
            'bidal_summary'      => dirname(__DIR__, 2) . '/cron/bidal_summary.php',
            'cashbook_summary'   => dirname(__DIR__, 2) . '/cron/cashbook_summary.php',
            'cashbook_reconcile' => dirname(__DIR__, 2) . '/cron/cashbook_reconcile.php',
            'staff_jobs'         => dirname(__DIR__, 2) . '/cron/staff_jobs_summary.php',
            'cash_carry_remind'  => dirname(__DIR__, 2) . '/cron/cash_carry_reminder.php',
            'wallet_sync'        => dirname(__DIR__, 2) . '/cron_wallet_sync.php',
            'maintenance'        => dirname(__DIR__, 2) . '/cron_maintenance.php',
            'kyc_crm_sync'       => dirname(__DIR__, 2) . '/cron/kyc_crm_sync.php',
            'gdrive_backup'      => dirname(__DIR__, 2) . '/cron_gdrive_backup.php',
            'staff_ssp_report'   => dirname(__DIR__, 2) . '/cron/staff_ssp_report.php',
            'quote_wa'           => dirname(__DIR__, 2) . '/cron_quote_wa.php',
        ];
        if (!$jobName) {
            $ok2(['available_jobs' => array_keys($allowedJobs)], 'Pass ?job=NAME to force-run. Available jobs listed.');
        } elseif (!isset($allowedJobs[$jobName])) {
            $er2("Unknown job: {$jobName}. Available: " . implode(', ', array_keys($allowedJobs)), 404);
        } else {
            $scriptPath = $allowedJobs[$jobName];
            if (!file_exists($scriptPath)) $er2("Script not found: {$scriptPath}", 500);

            // v4.21.83: Set unlimited time — some jobs (quote_wa with 6 PDFs) take 60s+
            // Without this, PHP kills the process mid-run and corrupts output buffer / SQLite WAL
            @set_time_limit(0);
            ignore_user_abort(true);

            ob_start();
            $startT = microtime(true);
            $GLOBALS['_FORCE_CRON_RUN'] = true;
            try {
                include $scriptPath;
            } catch (\Throwable $e) {
                $output = ob_get_clean();
                $er2("Job {$jobName} crashed: " . $e->getMessage() . "\nOutput: " . substr($output, 0, 500), 500);
            }
            $output = ob_get_clean();
            $durationMs = round((microtime(true) - $startT) * 1000);
            // Update schedule so master.php knows it ran
            $schedule = $store->load('master_schedule.json') ?? [];
            $schedule[$jobName] = ['last_run' => time(), 'last_run_at' => date('Y-m-d H:i:s'), 'duration_ms' => $durationMs];
            $store->save('master_schedule.json', $schedule);
            $ok2([
                'job'         => $jobName,
                'duration_ms' => $durationMs,
                'output'      => substr($output, 0, 2000),
            ], "Job {$jobName} executed successfully in {$durationMs}ms.");
        }
    }

    // ── Webhook diagnostic — check if UCRM has webhook registered ─────────
    // GET ?page=api&action=webhook_status
    if ($act === 'webhook_status') {
        $crm = svc('crm');
        $webhooks = $crm->getWebhooks();

        // Expected webhook URL
        $pluginUrl = rtrim($config['crm_base_url'] ?? '', '/');
        $pluginUrl = preg_replace('#/api/v[0-9.]+$#', '', $pluginUrl);
        $pluginUrl = preg_replace('#/crm$#', '', $pluginUrl);
        $expectedUrl = $pluginUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php?page=webhook';

        // Find matching webhook
        $found = null;
        foreach ($webhooks as $wh) {
            $url = $wh['url'] ?? '';
            if (strpos($url, 'dishnet-hybrid') !== false || strpos($url, 'page=webhook') !== false) {
                $found = $wh;
                break;
            }
        }

        // Webhook log stats
        $whLogFile = $dataDir . '/webhook_log.json';
        $whLog = file_exists($whLogFile) ? (json_decode(file_get_contents($whLogFile), true) ?: []) : [];
        $lastEvent = !empty($whLog) ? ($whLog[0]['received_at'] ?? 'unknown') : 'never';

        $ok2([
            'webhook_registered'  => $found !== null,
            'webhook_id'          => $found['id'] ?? null,
            'webhook_url'         => $found['url'] ?? null,
            'webhook_active'      => !empty($found['isActive']),
            'expected_url'        => $expectedUrl,
            'total_webhooks'      => count($webhooks),
            'log_entries'         => count($whLog),
            'last_event'          => $lastEvent,
            'register_url'        => '?page=api&action=webhook_register',
        ], $found ? 'Webhook found (ID: ' . ($found['id'] ?? '?') . ')' : 'No DishNet webhook registered in UCRM! Use ?action=webhook_register to create one.');
    }

    // ── Auto-register webhook in UCRM ───────────────────────────────────
    // GET ?page=api&action=webhook_register
    if ($act === 'webhook_register') {
        $crm = svc('crm');

        // Build webhook URL
        $pluginUrl = rtrim($config['crm_base_url'] ?? '', '/');
        $pluginUrl = preg_replace('#/api/v[0-9.]+$#', '', $pluginUrl);
        $pluginUrl = preg_replace('#/crm$#', '', $pluginUrl);
        $webhookUrl = $pluginUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php?page=webhook';

        // Check if already exists
        $existing = $crm->getWebhooks();
        foreach ($existing as $wh) {
            if (strpos($wh['url'] ?? '', 'dishnet-hybrid') !== false) {
                $ok2(['already_exists' => true, 'webhook' => $wh], 'Webhook already registered (ID: ' . ($wh['id'] ?? '?') . ')');
            }
        }

        // Register new webhook with all billing events
        $result = $crm->createWebhook($webhookUrl, [
            'client.add', 'client.edit', 'client.delete',
            'invoice.add', 'invoice.edit', 'invoice.near_due', 'invoice.overdue', 'invoice.draft_approved',
            'payment.add', 'payment.edit',
            'service.suspend', 'service.activate', 'service.end',
        ]);

        if ($result && !empty($result['id'])) {
            $ok2(['registered' => true, 'webhook' => $result], 'Webhook registered! ID: ' . $result['id'] . ' — UCRM will now send events to the plugin.');
        } else {
            $er2('Failed to register webhook: ' . json_encode($crm->getLastError()), 500);
        }
    }

    // ── Retry failed quote for a specific KYC app ─────────────────────
    // GET ?page=api&action=retry_quote&app_id=40
    if ($act === 'retry_quote') {
        $appId = (int)($_GET['app_id'] ?? 0);
        if (!$appId) $er2('Pass ?app_id=N', 400);
        $apps = $store->load('kyc_applications.json') ?? [];
        $app = null;
        foreach ($apps as $a) { if ((int)($a['id'] ?? 0) === $appId) { $app = $a; break; } }
        if (!$app) $er2('App not found', 404);
        $crmId = (int)($app['crm_client_id'] ?? 0);
        if (!$crmId) $er2('No CRM client ID on this app', 400);
        if (!empty($app['quote_created'])) $ok2(['already_created' => true, 'quote_id' => $app['quote_id'] ?? null], 'Quote already exists.');

        $cfg = $store->load('kyc_config.json') ?? [];
        $token = trim($cfg['crm_auth_token'] ?? '');
        if (!$token) $er2('No admin auth token configured in Settings.', 400);

        $quoteCrm = new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $token, 'x-auth-token');

        // Build items from app data
        $qItems = [];
        $pkgId = (int)($app['package_choice'] ?? 0);
        $plan = $store->findOne('subscription_plans.json', 'id', $pkgId);
        if (!$plan) $plan = $store->findOne('kyc_packages.json', 'id', $pkgId);
        if ($plan && (float)($plan['customer_price'] ?? 0) > 0) {
            $qItems[] = ['label' => $plan['name'] ?? 'Service', 'price' => (float)$plan['customer_price'], 'quantity' => 1, 'unit' => 'month'];
        }
        $hwCart = !empty($app['hw_cart_json']) ? (is_string($app['hw_cart_json']) ? json_decode($app['hw_cart_json'], true) : $app['hw_cart_json']) : [];
        foreach ($hwCart ?: [] as $hw) {
            $hwP = (float)($hw['price'] ?? 0);
            if ($hwP > 0) $qItems[] = ['label' => $hw['title'] ?? 'Hardware', 'price' => $hwP, 'quantity' => (int)($hw['qty'] ?? 1), 'unit' => 'piece'];
        }
        if (($app['customer_type'] ?? '') === 'Fiber') {
            $instFee = (float)($cfg['fiber_install_fee'] ?? 100);
            if ($instFee > 0) $qItems[] = ['label' => 'Installation Fee', 'price' => $instFee, 'quantity' => 1, 'unit' => 'amount'];
        }
        if (empty($qItems)) $er2('No quote items (plan price is $0 or no plan selected)', 400);

        $qPayload = [
            'notes'      => 'Auto-generated (retry). Sales: ' . ($app['retailer_name'] ?? ''),
            'adminNotes' => 'Retry for App #' . $appId,
            'items'      => $qItems,
        ];
        $result = $quoteCrm->post("clients/{$crmId}/quotes", $qPayload);
        if (!empty($result['id'])) {
            $store->updateOne('kyc_applications.json', 'id', $appId, [
                'quote_id'             => $result['id'],
                'quote_ref'            => $result['number'] ?? '',
                'quote_created'        => true,
                'quote_error'          => null,
                'wa_quote_pending'     => true,
                'wa_quote_phone'       => $app['mobile'] ?? '',
                'wa_quote_deferred_at' => date('Y-m-d H:i:s'),
            ]);
            $quoteCrm->patch("billing/quotes/{$result['id']}/send");
            $ok2(['quote_id' => $result['id'], 'items' => count($qItems), 'total' => array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $qItems))],
                "Quote #{$result['id']} created! PDF will be sent via WhatsApp in ~5 min.");
        } else {
            $er2('Quote creation failed: ' . json_encode($quoteCrm->getLastError()), 500);
        }
    }

    // ── KYC Quote debug — check why quote was/wasn't created ──────────
    // GET ?page=api&action=kyc_quote_debug&app_id=38  (or &crm_id=1285)
    if ($act === 'kyc_quote_debug') {
        $apps = $store->load('kyc_applications.json') ?? [];
        $appId = (int)($_GET['app_id'] ?? 0);
        $crmId = trim($_GET['crm_id'] ?? '');
        $app = null;
        foreach ($apps as $a) {
            if ($appId && (int)($a['id'] ?? 0) === $appId) { $app = $a; break; }
            if ($crmId && (string)($a['crm_client_id'] ?? '') === $crmId) { $app = $a; break; }
        }
        if (!$app) $er2('App not found. Pass ?app_id=N or ?crm_id=N', 404);

        // Check subscription_plans for the selected package
        $pkgId = (int)($app['package_choice'] ?? 0);
        $plan = $store->findOne('subscription_plans.json', 'id', $pkgId);
        if (!$plan) $plan = $store->findOne('kyc_packages.json', 'id', $pkgId);

        $ok2([
            'app_id'             => $app['id'] ?? null,
            'crm_client_id'      => $app['crm_client_id'] ?? null,
            'customer_name'      => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? '')),
            'customer_type'      => $app['customer_type'] ?? null,
            'sales_type'         => $app['sales_type'] ?? null,
            'package_choice'     => $pkgId,
            'plan_found'         => $plan ? true : false,
            'plan_name'          => $plan['name'] ?? null,
            'plan_price'         => $plan['customer_price'] ?? ($plan['amount'] ?? null),
            'device_id'          => $app['device_id'] ?? null,
            'hw_cart_json'       => $app['hw_cart_json'] ?? null,
            'quote_ref'          => $app['quote_ref'] ?? null,
            'quote_id'           => $app['quote_id'] ?? null,
            'quote_created'      => $app['quote_created'] ?? false,
            'quote_error'        => $app['quote_error'] ?? null,
            'quote_debug'        => $app['quote_debug'] ?? null,
            'wa_quote_pending'   => $app['wa_quote_pending'] ?? false,
            'wa_quote_sent'      => $app['wa_quote_sent'] ?? false,
            'crm_sync_status'    => $app['crm_sync_status'] ?? null,
            'amount_charged'     => $app['amount_charged'] ?? null,
        ]);
    }

    // GET ?page=api&action=view_error_log&file=cashbook
    // Requires active browser session (admin logged in)
    if ($act === 'view_error_log' && $met === 'GET') {
        $sessionUser = $auth->tokenAuth() ?: ($_SESSION['kyc_retailer'] ?? null);
        if (!$sessionUser || !in_array($sessionUser['role'] ?? '', ['admin','super_admin','accountant'])) {
            http_response_code(403);
            $ok2(['error' => 'Admin login required. Open the cashbook first, then click this link.']);
        }
        $allowed = [
            'cashbook'  => 'cashbook_errors.log',
            'gdrive'    => 'gdrive_backup.log',
            'main'      => 'main_cron.log',
            'lte_sync'  => 'lte_sync.log',
            'wa_sync'   => 'wa_sync.log',
        ];
        $which = $_GET['file'] ?? 'cashbook';
        if (!isset($allowed[$which])) {
            $ok2(['error' => 'Unknown log. Use: ' . implode(', ', array_keys($allowed))]);
        }
        $logPath = $dataDir . '/' . $allowed[$which];
        if (!file_exists($logPath)) {
            $ok2(['file' => $allowed[$which], 'exists' => false, 'lines' => [], 'message' => 'Log file does not exist yet — no errors recorded.']);
        }
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $tail  = array_slice($lines ?: [], -50); // Last 50 lines
        $ok2([
            'file'      => $allowed[$which],
            'exists'    => true,
            'size_kb'   => round(filesize($logPath) / 1024, 1),
            'total_lines' => count($lines ?: []),
            'showing'   => 'last 50 lines',
            'lines'     => $tail,
        ]);
    }

    // ─── DEBUG: Cashbook sync diagnostics ────────────────────────────────
    // GET ?page=api&action=debug_cashbook_sync           → diagnose only
    // GET ?page=api&action=debug_cashbook_sync&run=1     → diagnose + run sync
    // GET ?page=api&action=debug_cashbook_sync&from=2026-03-17  → sync from specific date
    if ($act === 'debug_cashbook_sync' && $met === 'GET') {
        $sessionUser = $auth->tokenAuth() ?: ($_SESSION['kyc_retailer'] ?? null);
        if (!$sessionUser || !in_array($sessionUser['role'] ?? '', ['admin','super_admin','accountant'])) {
            http_response_code(403);
            $ok2(['error' => 'Admin login required. Open the plugin dashboard first, then click this link.']);
        }

        require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
        require_once dirname(__DIR__, 2) . '/lib/PaymentUuids.php';
        $cb = new CashbookService($store, $dataDir);

        $out = ['ts' => date('Y-m-d H:i:s T'), 'version' => '4.12.5'];

        // ── 1. CRM connection check ──
        $out['crm_configured'] = $crm->isConfigured();
        $out['crm_ok'] = false;
        if ($crm->isConfigured()) {
            try {
                $ver = $crm->get('version');
                $out['crm_ok'] = !empty($ver);
                $out['crm_version'] = $ver;
            } catch (\Throwable $e) {
                $out['crm_error'] = $e->getMessage();
            }
        }

        // ── 2. Cashbook entry counts by source ──
        $pdo = $store->getPdo();
        $srcCounts = $pdo->query(
            "SELECT source, COUNT(*) as cnt, SUM(amount) as total
             FROM cb_ledger WHERE direction='in' AND status='approved'
             GROUP BY source ORDER BY cnt DESC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $out['cashbook_sources'] = $srcCounts;

        $totalCb = $pdo->query("SELECT COUNT(*) as n FROM cb_ledger WHERE direction='in' AND status='approved'")->fetch(\PDO::FETCH_ASSOC);
        $out['cashbook_total_in'] = (int)($totalCb['n'] ?? 0);

        // ── 3. Today's cashbook entries ──
        $today = date('Y-m-d');
        $todayCb = $pdo->prepare(
            "SELECT sr, amount, source, validation_ref, description, created_at
             FROM cb_ledger WHERE direction='in' AND date=? AND status='approved'
             ORDER BY created_at DESC"
        );
        $todayCb->execute([$today]);
        $out['cashbook_today'] = $todayCb->fetchAll(\PDO::FETCH_ASSOC);
        $out['cashbook_today_count'] = count($out['cashbook_today']);
        $out['cashbook_today_total'] = array_sum(array_column($out['cashbook_today'], 'amount'));

        // ── 4. Today's collections from payment_collections.json ──
        $cols = $store->load('payment_collections.json') ?? [];
        $todayCols = array_filter($cols, function($c) use ($today) {
            return substr($c['created_at'] ?? '', 0, 10) === $today;
        });
        $colsSummary = [];
        foreach (array_values($todayCols) as $c) {
            $colsSummary[] = [
                'id'             => $c['id'] ?? '?',
                'customer'       => $c['customer_name'] ?? '',
                'crm_id'         => $c['crm_customer_id'] ?? 0,
                'amount'         => (float)($c['amount'] ?? 0),
                'crm_synced'     => !empty($c['crm_synced']),
                'crm_payment_id' => $c['crm_payment_id'] ?? null,
                'retailer'       => $c['retailer_name'] ?? '',
                'created_at'     => $c['created_at'] ?? '',
            ];
        }
        $out['collections_today'] = $colsSummary;
        $out['collections_today_count'] = count($colsSummary);
        $out['collections_today_total'] = array_sum(array_column($colsSummary, 'amount'));

        // ── 5. Gap analysis: collections with no matching cashbook entry ──
        $cbRefs = $pdo->query("SELECT validation_ref FROM cb_ledger WHERE direction='in'")->fetchAll(\PDO::FETCH_COLUMN);
        $cbRefSet = array_flip($cbRefs);
        $missing = [];
        foreach ($todayCols as $c) {
            $colId = $c['id'] ?? 0;
            $crmPayId = $c['crm_payment_id'] ?? 0;
            $refCol = 'COL-' . $colId;
            $refPay = $crmPayId ? 'PAY-' . $crmPayId : '';
            $inCb = isset($cbRefSet[$refCol]) || ($refPay && isset($cbRefSet[$refPay]));
            if (!$inCb) {
                $missing[] = [
                    'collection_id'  => $colId,
                    'customer'       => $c['customer_name'] ?? '',
                    'amount'         => (float)($c['amount'] ?? 0),
                    'crm_payment_id' => $crmPayId,
                    'expected_refs'  => array_filter([$refCol, $refPay]),
                    'created_at'     => $c['created_at'] ?? '',
                ];
            }
        }
        $out['missing_from_cashbook'] = $missing;
        $out['missing_count'] = count($missing);

        // ── 6. Sync metadata ──
        $meta = $store->load('cashbook_meta_v2.json') ?? [];
        $out['sync_meta'] = [
            'last_sync'   => $meta['crm_sync_at'] ?? 'never',
            'last_cutoff' => $meta['crm_sync_cutoff'] ?? '?',
            'last_source' => $meta['crm_sync_source'] ?? '?',
            'total_ever'  => $meta['crm_sync_total'] ?? 0,
            'cron_pull'   => $meta['cron_pull_at'] ?? 'never',
        ];

        // ── 7. Recent cashbook errors from activity log ──
        $logPath = $dataDir . '/cashbook_errors.log';
        $logLines = [];
        if (file_exists($logPath)) {
            $all = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $logLines = array_slice($all ?: [], -10);
        }
        $actLog = $store->load('activity_log.json') ?? [];
        $cbErrors = [];
        foreach (array_reverse($actLog) as $a) {
            if (strpos($a['action'] ?? '', 'cashbook') !== false) {
                $cbErrors[] = $a;
                if (count($cbErrors) >= 10) break;
            }
        }
        $out['recent_cashbook_log'] = $logLines;
        $out['recent_activity_errors'] = $cbErrors;

        // ── 8. Optionally RUN the sync ──
        $doRun = ($_GET['run'] ?? '') === '1';
        if ($doRun) {
            $fromDate = trim($_GET['from'] ?? '');
            $result = $cb->syncFromCrmApi($crm->isConfigured() ? $crm : null, $fromDate, $cols);
            $out['sync_result'] = $result;

            // Re-check gaps after sync
            $cbRefsAfter = $pdo->query("SELECT validation_ref FROM cb_ledger WHERE direction='in'")->fetchAll(\PDO::FETCH_COLUMN);
            $cbRefSetAfter = array_flip($cbRefsAfter);
            $stillMissing = [];
            foreach ($todayCols as $c) {
                $colId = $c['id'] ?? 0;
                $crmPayId = $c['crm_payment_id'] ?? 0;
                $refCol = 'COL-' . $colId;
                $refPay = $crmPayId ? 'PAY-' . $crmPayId : '';
                $inCb = isset($cbRefSetAfter[$refCol]) || ($refPay && isset($cbRefSetAfter[$refPay]));
                if (!$inCb) {
                    $stillMissing[] = ['collection_id' => $colId, 'customer' => $c['customer_name'] ?? '', 'amount' => (float)($c['amount'] ?? 0)];
                }
            }
            $out['after_sync_still_missing'] = $stillMissing;
        } else {
            $out['hint'] = 'Add &run=1 to actually run the sync. Add &from=2026-03-17 to set cutoff date.';
        }

        $ok2($out);
    }
    // ─── FULL DATA DIAGNOSTIC ────────────────────────────────────────────────
    // GET ?page=api&action=data_diagnostic
    // Shows ALL table row counts, JSON file sizes, config, alternate data dirs
    if ($act === 'data_diagnostic' && $met === 'GET') {

        $result = [];

        // 1. Current data dir info
        $result['data_dir'] = $dataDir;
        $result['data_dir_exists'] = is_dir($dataDir);

        // 2. Check for alternate possible data dirs (old paths)
        $possibleDirs = [
            '/data/ucrm/data/plugins/dishnet-hybrid-telecom/data',
            '/usr/src/ucrm/data/plugins/dishnet-hybrid-telecom/data',
            dirname(__DIR__) . '/data',
            __DIR__ . '/../../data',
        ];
        $altDirs = [];
        foreach ($possibleDirs as $d) {
            $d = realpath($d) ?: $d;
            if ($d === realpath($dataDir)) continue;
            if (is_dir($d)) {
                $sqlite = $d . '/plugin.sqlite3';
                $altDirs[$d] = [
                    'exists'         => true,
                    'sqlite_exists'  => file_exists($sqlite),
                    'sqlite_size_kb' => file_exists($sqlite) ? round(filesize($sqlite)/1024, 1) : 0,
                    'json_files'     => count(glob($d . '/*.json') ?: []),
                ];
            }
        }
        $result['alternate_dirs'] = $altDirs;

        // 3. SQLite file info
        $dbPath = $dataDir . '/plugin.sqlite3';
        $result['sqlite'] = [
            'path'    => $dbPath,
            'exists'  => file_exists($dbPath),
            'size_kb' => file_exists($dbPath) ? round(filesize($dbPath)/1024, 1) : 0,
        ];

        // 4. All table row counts
        $tableCounts = [];
        if (file_exists($dbPath)) {
            try {
                $dpdo = new \PDO('sqlite:' . $dbPath);
                $dpdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $tables = $dpdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                               ->fetchAll(\PDO::FETCH_COLUMN);
                foreach ($tables as $t) {
                    try {
                        $cnt = $dpdo->query("SELECT COUNT(*) FROM [{$t}]")->fetchColumn();
                        $tableCounts[$t] = (int)$cnt;
                    } catch (\Throwable $e) {
                        $tableCounts[$t] = 'error: ' . $e->getMessage();
                    }
                }
            } catch (\Throwable $e) {
                $tableCounts['_error'] = $e->getMessage();
            }
        }
        $result['table_row_counts'] = $tableCounts;

        // 5. cb_ledger sample + project values
        if (!empty($tableCounts['cb_ledger']) && $tableCounts['cb_ledger'] > 0) {
            try {
                $result['cb_ledger_projects'] = $dpdo->query(
                    "SELECT project, COUNT(*) as cnt FROM cb_ledger GROUP BY project"
                )->fetchAll(\PDO::FETCH_ASSOC);
                $result['cb_ledger_latest'] = $dpdo->query(
                    "SELECT id, date, direction, amount, category, project, status FROM cb_ledger ORDER BY id DESC LIMIT 5"
                )->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}
        }

        // 6. JSON files — name + size + record count
        $jsonFiles = glob($dataDir . '/*.json') ?: [];
        $jsonInfo = [];
        foreach ($jsonFiles as $f) {
            $bn   = basename($f);
            $size = filesize($f);
            $data = @json_decode(file_get_contents($f), true);
            $count = is_array($data) ? count($data) : (is_string($data) ? 1 : 0);
            $jsonInfo[$bn] = ['size_bytes' => $size, 'records' => $count];
        }
        $result['json_files'] = $jsonInfo;

        // 7. Config snapshot (redact sensitive keys)
        $cfgPath = $dataDir . '/config.json';
        if (file_exists($cfgPath)) {
            $cfg = @json_decode(file_get_contents($cfgPath), true) ?? [];
            $redactKeys = ['api_key','password','secret','token','key','auth'];
            foreach ($cfg as $k => $v) {
                foreach ($redactKeys as $rk) {
                    if (stripos($k, $rk) !== false) { $cfg[$k] = '***'; break; }
                }
            }
            $result['config'] = $cfg;
        } else {
            $result['config'] = 'config.json NOT FOUND';
        }

        // 8. ucrm.json pluginDataDir
        $ucrmPaths = [dirname(__DIR__) . '/ucrm.json', dirname(__DIR__) . '/data/ucrm.json'];
        foreach ($ucrmPaths as $up) {
            if (file_exists($up)) {
                $uc = @json_decode(file_get_contents($up), true) ?? [];
                $result['ucrm_json'] = ['path' => $up, 'pluginDataDir' => $uc['pluginDataDir'] ?? 'NOT SET'];
                break;
            }
        }

        $ok2($result);
    }

    // ─── FILESYSTEM SEARCH FOR LOST DATA ────────────────────────────────────
    // GET ?page=api&action=find_lost_data
    if ($act === 'find_lost_data' && $met === 'GET') {
        $result = [];

        // Search for any plugin.sqlite3 files on the server
        $searchRoots = ['/data', '/usr/src', '/var', '/opt', '/home'];
        $foundSqlite = [];
        $foundJsons  = [];

        foreach ($searchRoots as $root) {
            if (!is_dir($root)) continue;
            // Use find via iterator — safe, read-only
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) continue;
                $fn = $file->getFilename();
                $fp = $file->getPathname();

                // Look for plugin SQLite files
                if ($fn === 'plugin.sqlite3') {
                    $sz = $file->getSize();
                    $rowCount = 0;
                    $tables = [];
                    try {
                        $tp = new \PDO('sqlite:' . $fp);
                        $tlist = $tp->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
                        foreach ($tlist as $t) {
                            $c = (int)$tp->query("SELECT COUNT(*) FROM [{$t}]")->fetchColumn();
                            $tables[$t] = $c;
                            $rowCount += $c;
                        }
                    } catch (\Throwable $e) {}
                    $foundSqlite[] = ['path' => $fp, 'size_kb' => round($sz/1024,1), 'total_rows' => $rowCount, 'tables' => $tables];
                }

                // Look for key cash data JSON files
                if (in_array($fn, ['cash_ins.json','kyc_applications.json','payment_collections.json','passbook.json','cash_expenses.json','retailers.json'])) {
                    $sz = $file->getSize();
                    $data = @json_decode(file_get_contents($fp), true);
                    $foundJsons[] = ['file' => $fn, 'path' => $fp, 'size_kb' => round($sz/1024,1), 'records' => is_array($data) ? count($data) : 0];
                }
            }
        }

        $result['found_sqlite_files'] = $foundSqlite;
        $result['found_data_json_files'] = $foundJsons;
        $result['current_data_dir'] = $dataDir;

        // Also check backup files created by BackupService
        $backupDir = $dataDir . '/backups';
        $backups = [];
        if (is_dir($backupDir)) {
            foreach (glob($backupDir . '/*.zip') ?: [] as $bk) {
                $backups[] = ['file' => basename($bk), 'size_kb' => round(filesize($bk)/1024,1), 'created' => date('Y-m-d H:i:s', filemtime($bk))];
            }
        }
        $result['backups_found'] = $backups;

        $ok2($result);
    }

    // ─── FIX SSP LEDGER ──────────────────────────────────────────────────────
    // GET  ?page=api&action=fix_ssp_ledger&staff_id=19          → dry run
    // POST ?page=api&action=fix_ssp_ledger  body: staff_id=19   → apply
    if ($act === 'fix_ssp_ledger') {
        // Pre-auth context — read session directly (same pattern as force_cron_job)
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_fixSess     = $_SESSION['kyc_retailer'] ?? null;
        $_fixRetailer = $_fixSess['cached_record'] ?? $_fixSess ?? [];
        if (empty($_fixRetailer['is_admin'])) $er2('Admin login required. Open the plugin dashboard first, then visit this URL.', 403);
        $staffId = (int)($_GET['staff_id'] ?? $_POST['staff_id'] ?? 0);
        if (!$staffId) $er2('staff_id required. Usage: ?page=api&action=fix_ssp_ledger&staff_id=19');
        $apply   = ($met === 'POST') || !empty($_GET['apply']);

        require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
        $_fixPdo = $store->getPdo(); // $pdo not available pre-auth

        // Find staff name
        $allR = $store->load('retailers.json') ?? [];
        $staffName = 'Unknown';
        foreach ($allR as $r) { if ((int)$r['id'] === $staffId) { $staffName = $r['name'] ?? 'Staff'; break; } }

        // Get current staff_ledger SSP rows
        $rows = $_fixPdo->prepare("SELECT id, direction, ssp_amount, amount, status, idempotency_key, event_date, subcategory FROM staff_ledger WHERE staff_id=? AND currency='SSP' ORDER BY event_date ASC");
        $rows->execute([$staffId]);
        $ledgerRows = $rows->fetchAll(PDO::FETCH_ASSOC);
        $existingKeys = array_column($ledgerRows, null, 'idempotency_key');

        $ledgerIn  = array_sum(array_column(array_filter($ledgerRows, fn($r) => $r['direction']==='in'  && $r['status']!=='voided'), 'ssp_amount'));
        $ledgerOut = array_sum(array_column(array_filter($ledgerRows, fn($r) => $r['direction']==='out' && $r['status']!=='voided'), 'ssp_amount'));

        // Find missing/zero-ssp cash_ins for this staff
        $cashIns = $store->load('cash_ins.json') ?? [];
        $toFix = []; $alreadyOk = [];
        foreach ($cashIns as $ci) {
            if ((int)($ci['collector_id'] ?? 0) !== $staffId) continue;
            if (!in_array($ci['category'] ?? '', ['SSP Received', 'Exchange'])) continue;
            if (in_array($ci['status'] ?? '', ['rejected', 'voided'])) continue;
            $id  = (int)($ci['id'] ?? 0);
            $key = 'CIN-' . $id;
            $ssp = (float)($ci['ssp_amount'] ?? $ci['amount'] ?? 0);
            if ($ssp <= 0) continue;
            $inLedger  = isset($existingKeys[$key]);
            $ledgerSsp = $inLedger ? (float)($existingKeys[$key]['ssp_amount'] ?? 0) : null;
            if (!$inLedger || $ledgerSsp <= 0) {
                $toFix[] = ['ci' => $ci, 'id' => $id, 'key' => $key, 'ssp' => $ssp, 'in_ledger' => $inLedger, 'ledger_row' => $existingKeys[$key] ?? null];
            } else {
                $alreadyOk[] = $key;
            }
        }

        $fixed = 0;
        if ($apply && !empty($toFix)) {
            $ledger = new StaffLedgerService($_fixPdo);
            foreach ($toFix as $item) {
                $ci = $item['ci']; $key = $item['key']; $ssp = $item['ssp'];
                if ($item['in_ledger'] && ($item['ledger_row']['ssp_amount'] ?? 0) <= 0) {
                    $_fixPdo->prepare("UPDATE staff_ledger SET ssp_amount=?, amount=?, updated_at=datetime('now') WHERE idempotency_key=?")->execute([$ssp, $ssp, $key]);
                } else {
                    $corrKey = $key . '-SSP-FIXED';
                    $exists  = $_fixPdo->query("SELECT id FROM staff_ledger WHERE idempotency_key=" . $_fixPdo->quote($corrKey))->fetchColumn();
                    if (!$exists) {
                        $ledger->record(['staff_id'=>$staffId,'staff_name'=>$staffName,'direction'=>'in','currency'=>'SSP','amount'=>$ssp,'ssp_amount'=>$ssp,'category'=>'collection','subcategory'=>'SSP Received','description'=>($ci['description']??'').' [ledger-fix]','status'=>'active','source_type'=>'cash_ins','source_id'=>(string)$item['id'],'idempotency_key'=>$corrKey,'event_date'=>substr($ci['created_at']??date('Y-m-d'),0,10)]);
                    }
                }
                $fixed++;
            }
        }

        $newBal = (float)$_fixPdo->query("SELECT COALESCE(SUM(CASE WHEN direction='in' THEN ssp_amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END),0) FROM staff_ledger WHERE staff_id={$staffId} AND currency='SSP' AND status NOT IN ('voided','cancelled')")->fetchColumn();

        $ok2([
            'staff_id'       => $staffId,
            'staff_name'     => $staffName,
            'mode'           => $apply ? 'APPLIED' : 'DRY_RUN — send POST or add &apply=1 to apply',
            'ledger_before'  => ['rows' => count($ledgerRows), 'ssp_in' => $ledgerIn, 'ssp_out' => $ledgerOut, 'net' => $ledgerIn - $ledgerOut],
            'entries_ok'     => count($alreadyOk),
            'entries_fixed'  => count($toFix),
            'fixed_applied'  => $fixed,
            'ssp_balance_now'=> $newBal,
            'next_step'      => $apply ? ($fixed > 0 ? "Done — Joel SSP BAG should now show " . number_format($newBal, 0) . " SSP" : "Nothing to fix — balance already correct") : "To apply: POST to ?page=api&action=fix_ssp_ledger with staff_id={$staffId}, or add &apply=1 to URL",
        ], $apply && $fixed > 0 ? "Fixed {$fixed} SSP entries. New balance: " . number_format($newBal, 0) . " SSP" : ($apply ? "Nothing to fix" : "Dry run — {$fixed} entries need fixing"));
    }

    // ─── SHOW STAFF LEDGER SSP ROWS ─────────────────────────────────────────
    // GET ?page=api&action=staff_ssp_rows&staff_id=19
    if ($act === 'staff_ssp_rows' && $met === 'GET') {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_dSess = $_SESSION['kyc_retailer'] ?? null;
        $_dUser = $_dSess['cached_record'] ?? $_dSess ?? [];
        if (empty($_dUser['is_admin'])) $er2('Admin login required', 403);
        $staffId = (int)($_GET['staff_id'] ?? 0);
        if (!$staffId) $er2('staff_id required');
        $_dPdo = $store->getPdo();

        $rows = $_dPdo->prepare(
            "SELECT id, direction, currency, amount, ssp_amount, category, subcategory,
                    status, source_type, source_id, idempotency_key, event_date, description
             FROM staff_ledger
             WHERE staff_id = ? AND currency = 'SSP'
             ORDER BY event_date ASC, id ASC"
        );
        $rows->execute([$staffId]);
        $allRows = $rows->fetchAll(PDO::FETCH_ASSOC);

        $inTotal  = 0; $outTotal = 0;
        $inRows   = []; $outRows = [];
        foreach ($allRows as $r) {
            if ($r['status'] === 'voided') continue;
            if ($r['direction'] === 'in')  { $inTotal  += (float)$r['ssp_amount']; $inRows[]  = $r; }
            else                           { $outTotal += (float)$r['ssp_amount']; $outRows[] = $r; }
        }

        // Detect duplicate OUT entries by source_id
        $seenSources = [];
        $duplicates  = [];
        foreach ($outRows as $r) {
            $srcKey = $r['source_type'] . ':' . $r['source_id'];
            if (isset($seenSources[$srcKey])) {
                $duplicates[] = $r;
            } else {
                $seenSources[$srcKey] = $r['id'];
            }
        }

        $ok2([
            'staff_id'    => $staffId,
            'ssp_in'      => $inTotal,
            'ssp_out'     => $outTotal,
            'net'         => $inTotal - $outTotal,
            'expected_net'=> 'Run SSP cashbook view for correct figure',
            'in_rows'     => $inRows,
            'out_rows'    => $outRows,
            'duplicate_out_count' => count($duplicates),
            'duplicates'  => $duplicates,
            'hint'        => count($duplicates) > 0
                ? 'POST ?page=api&action=void_duplicate_ssp&staff_id=' . $staffId . ' to void duplicates'
                : 'No simple duplicates found — review out_rows manually',
        ]);
    }

    // ─── VOID FEXP SSP OUT ENTRIES (old cash_expenses system duplicates) ──────
    // GET  ?page=api&action=void_duplicate_ssp&staff_id=19   → dry run
    // POST ?page=api&action=void_duplicate_ssp&staff_id=19   → apply
    if ($act === 'void_duplicate_ssp') {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_vSess = $_SESSION['kyc_retailer'] ?? null;
        $_vUser = $_vSess['cached_record'] ?? $_vSess ?? [];
        if (empty($_vUser['is_admin'])) $er2('Admin login required', 403);
        $staffId = (int)($_POST['staff_id'] ?? $_GET['staff_id'] ?? 0);
        if (!$staffId) $er2('staff_id required');
        $applyFix = ($met === 'POST') || !empty($_GET['apply']);
        $_vPdo = $store->getPdo();

        // Find all active FEXP-* OUT entries (from old cash_expenses.json system).
        // These duplicate the advances already tracked as CIN-* IN entries — voiding
        // them restores the correct balance: net = IN - EXP-* only.
        $rows = $_vPdo->prepare(
            "SELECT id, ssp_amount, idempotency_key, event_date, description
             FROM staff_ledger
             WHERE staff_id=? AND currency='SSP' AND direction='out'
               AND source_type='cash_expenses'
               AND status NOT IN ('voided','cancelled')
             ORDER BY id ASC"
        );
        $rows->execute([$staffId]);
        $fexpRows = $rows->fetchAll(PDO::FETCH_ASSOC);

        $voided = [];
        if ($applyFix) {
            foreach ($fexpRows as $r) {
                $_vPdo->prepare(
                    "UPDATE staff_ledger
                     SET status='voided', voided_by='void_duplicate_ssp',
                         voided_at=datetime('now'),
                         void_reason='FEXP cash_expenses entry duplicates advance already tracked as CIN-* IN — voided by admin fix',
                         updated_at=datetime('now')
                     WHERE id=?"
                )->execute([$r['id']]);
                $voided[] = ['id'=>$r['id'], 'key'=>$r['idempotency_key'], 'ssp'=>$r['ssp_amount']];
            }
        }

        $newBal = (float)$_vPdo->query(
            "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN ssp_amount ELSE 0 END),0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END),0)
             FROM staff_ledger WHERE staff_id={$staffId} AND currency='SSP'
               AND status NOT IN ('voided','cancelled')"
        )->fetchColumn();

        $ok2([
            'mode'            => $applyFix ? 'APPLIED' : 'DRY_RUN — add &apply=1 or POST to apply',
            'fexp_found'      => count($fexpRows),
            'fexp_to_void'    => array_map(fn($r) => ['id'=>$r['id'],'key'=>$r['idempotency_key'],'ssp'=>$r['ssp_amount'],'desc'=>substr($r['description'],0,60)], $fexpRows),
            'voided'          => $voided,
            'ssp_balance_now' => $newBal,
            'message' => $applyFix
                ? ($voided ? 'Voided ' . count($voided) . ' FEXP entries. SSP balance now: ' . number_format($newBal, 0) : 'Nothing to void.')
                : count($fexpRows) . ' FEXP entries will be voided. Expected balance after fix: ' . number_format($newBal + array_sum(array_column($fexpRows,'ssp_amount')), 0) . ' SSP',
        ]);
    }

    // ─── FIX RELAY HANDOVER LEDGER (Diko "company owes" fix) ────────────────
    // GET  ?page=api&action=fix_relay_ledger        → dry run, finds all gaps
    // GET  ?page=api&action=fix_relay_ledger&apply=1 → apply fix
    //
    // Root cause: relay handovers (field_accountant → main accountant) create
    // HOV-OUT for Diko but no HOV-IN (individual staff submitted to main accountant,
    // not Diko). Fix: insert HOV-RELAY-IN-{id} for each confirmed relay that is missing it.
    if ($act === 'fix_relay_ledger') {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_rSess = $_SESSION['kyc_retailer'] ?? null;
        $_rUser = $_rSess['cached_record'] ?? $_rSess ?? [];
        if (empty($_rUser['is_admin'])) $er2('Admin login required', 403);
        $applyFix = !empty($_GET['apply']);
        $_rPdo = $store->getPdo();

        // Find all confirmed relay handovers
        // Note: older relay handovers may not have type='relay' set explicitly.
        // Also match by: source_handover_ids array present (chain relay), or
        // from_id is a field_accountant role.
        $allHov = $store->load('cash_handovers.json') ?? [];
        $allRetailers = $store->load('retailers.json') ?? [];
        $fieldAcctIds = [];
        foreach ($allRetailers as $r) {
            if (($r['role'] ?? '') === 'field_accountant') {
                $fieldAcctIds[] = (int)$r['id'];
            }
        }

        $relays = array_filter($allHov, fn($h) =>
            ($h['status'] ?? '') === 'confirmed'
            && (
                ($h['type'] ?? '') === 'relay'
                || !empty($h['source_handover_ids'])
                || in_array((int)($h['from_id'] ?? 0), $fieldAcctIds, true)
            )
        );

        // Load retailers to get field_accountant details
        $retailers = $store->load('retailers.json') ?? [];
        $retailerMap = [];
        foreach ($retailers as $r) { $retailerMap[(int)$r['id']] = $r; }

        $toFix = []; $alreadyOk = [];
        foreach ($relays as $hov) {
            $hovId   = (int)($hov['id'] ?? 0);
            $fromId  = (int)($hov['from_id'] ?? 0);
            $amount  = (float)($hov['amount'] ?? 0);
            $relayKey = 'HOV-RELAY-IN-' . $hovId;

            // Check if relay IN entry already exists
            $exists = $_rPdo->prepare(
                "SELECT id FROM staff_ledger WHERE idempotency_key = ? LIMIT 1"
            );
            $exists->execute([$relayKey]);
            $existingRow = $exists->fetchColumn();

            if ($existingRow) {
                $alreadyOk[] = $relayKey;
            } else {
                $toFix[] = [
                    'hov_id'     => $hovId,
                    'from_id'    => $fromId,
                    'from_name'  => $hov['from_name'] ?? '?',
                    'to_name'    => $hov['to_name'] ?? '?',
                    'amount'     => $amount,
                    'date'       => substr($hov['confirmed_at'] ?? $hov['created_at'] ?? '', 0, 10),
                    'relay_key'  => $relayKey,
                ];
            }
        }

        $fixed = 0;
        if ($applyFix) {
            require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
            $ledger = new StaffLedgerService($_rPdo);
            foreach ($toFix as $item) {
                $hov = null;
                foreach ($allHov as $h) {
                    if ((int)($h['id'] ?? 0) === $item['hov_id']) { $hov = $h; break; }
                }
                if (!$hov) continue;

                $ledger->record([
                    'staff_id'          => $item['from_id'],
                    'staff_name'        => $item['from_name'],
                    'direction'         => 'in',
                    'currency'          => strtoupper($hov['currency'] ?? 'USD'),
                    'amount'            => $item['amount'],
                    'ssp_amount'        => (float)($hov['ssp_amount'] ?? 0),
                    'category'          => 'collection',
                    'subcategory'       => 'relay_received',
                    'description'       => 'Relay chain received #' . $item['hov_id'] . ' — collected from field staff, relayed to ' . $item['to_name'] . ' [retro-fix]',
                    'status'            => 'active',
                    'source_type'       => 'cash_handovers',
                    'source_id'         => (string)$item['hov_id'],
                    'idempotency_key'   => $item['relay_key'],
                    'counterparty_name' => $item['to_name'],
                    'event_date'        => $item['date'],
                ]);
                $fixed++;
            }
        }

        // Calculate current balance for affected staff after fix
        $balances = [];
        $affectedIds = array_unique(array_column($toFix, 'from_id'));
        foreach ($affectedIds as $sid) {
            $b = (float)$_rPdo->query(
                "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0)
                      - COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0)
                 FROM staff_ledger WHERE staff_id={$sid} AND currency='USD'
                   AND status NOT IN ('voided','cancelled')"
            )->fetchColumn();
            $name = $retailerMap[$sid]['name'] ?? 'staff_' . $sid;
            $balances[$name] = round($b, 2);
        }

        $ok2([
            'mode'           => $applyFix ? 'APPLIED' : 'DRY_RUN — add &apply=1 to fix',
            'relay_total'    => count($relays),
            'already_ok'     => count($alreadyOk),
            'missing_relay_in' => count($toFix),
            'to_fix'         => $toFix,
            'fixed'          => $fixed,
            'balances_after' => $balances,
            'message'        => $applyFix
                ? "Fixed {$fixed} relay handovers. Check balances_after."
                : count($toFix) . ' relay handovers missing HOV-RELAY-IN entry. Add &apply=1 to fix.',
        ]);
    }

    // ─── GOOGLE DRIVE BACKUP DIAGNOSTIC ─────────────────────────────────────
    // GET ?page=api&action=gdrive_status
    if ($act === 'gdrive_status' && $met === 'GET') {
        require_once dirname(__DIR__, 2) . '/lib/GoogleDriveBackup.php';
        $gdrive  = new GoogleDriveBackup($dataDir);
        $status  = $gdrive->getStatus();
        $logs    = $gdrive->getRecentLogs(20);
        $config  = $gdrive->getConfig();

        // Redact tokens
        foreach (['access_token','refresh_token','client_secret'] as $k) {
            if (!empty($config[$k])) $config[$k] = substr($config[$k], 0, 8) . '***';
        }

        // Check if cron job is registered and when it last ran
        $masterSchedule = $store->load('master_schedule.json') ?? [];
        $gdriveJob      = $masterSchedule['gdrive_backup'] ?? null;

        $ok2([
            'status'          => $status,
            'recent_logs'     => $logs,
            'config_redacted' => $config,
            'cron_job'        => $gdriveJob,
            'log_file'        => $dataDir . '/gdrive_backup.log',
            'log_exists'      => file_exists($dataDir . '/gdrive_backup.log'),
        ]);
    }

    // POST ?page=api&action=kyc_exclude  body: app_id, reason (cancelled|demo|duplicate)
    // POST ?page=api&action=kyc_restore  body: app_id
    if (($act === 'kyc_exclude' || $act === 'kyc_restore') && $met === 'POST') {
        if (empty($retailer['is_admin'])) $er2('Admin only', 403);
        $appId = (int)($_POST['app_id'] ?? 0);
        if (!$appId) $er2('app_id required');

        require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/KycFunnelService.php';
        $funnelSvc = new KycFunnelService($store->getPdo(), $store);

        if ($act === 'kyc_exclude') {
            $reason = $_POST['reason'] ?? 'cancelled';
            if (!in_array($reason, ['cancelled', 'demo', 'duplicate'], true)) $reason = 'cancelled';
            $funnelSvc->excludeApp($appId, $reason);
            $ok2(['excluded' => $appId, 'reason' => $reason]);
        } else {
            $funnelSvc->restoreApp($appId);
            $ok2(['restored' => $appId]);
        }
    }
