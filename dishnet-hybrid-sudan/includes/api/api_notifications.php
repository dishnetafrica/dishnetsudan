<?php
// ═══════════════════════════════════════════════════════════════
// NOTIFICATIONS / WEBHOOKS
// ═══════════════════════════════════════════════════════════════

    // ── Auth gate for diagnostic actions ────────────────────────────
    // True if: admin session, OR ?debug_key= matches the webhook_secret.
    // Used by test_invoice_notify, webhook_setup, webhook_status,
    // webhook_cleanup, notification_log, check_wa_config.
    $debugAuth = ($isAdmin ?? false);
    if (!$debugAuth) {
        $providedKey = (string)($_GET['debug_key'] ?? $_POST['debug_key'] ?? '');
        $expectedKey = (string)($config['webhook_secret'] ?? '');
        if ($providedKey !== '' && $expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
            $debugAuth = true;
        }
    }

    // Test invoice notification (admin only)
    // Usage: ?page=api&action=test_invoice_notify&client_id=123
    // Or: ?page=api&action=test_invoice_notify&client_id=123&debug_key=YOUR_WEBHOOK_SECRET
    if ($act === 'test_invoice_notify' && $met === 'GET') {
        if (!$debugAuth) $er2('Admin or debug_key required', 403);
        
        $clientId = (int)($_GET['client_id'] ?? 0);
        if (!$clientId) $er2('Missing client_id parameter', 400);
        
        // Fetch client from CRM
        $client = $crm->get("clients/{$clientId}");
        if (!$client) $er2("Client #{$clientId} not found in CRM", 404);
        
        $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
        $phone = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $phone = $c['phone']; break; }
        }
        
        $debug = [
            'client_id' => $clientId,
            'name' => $name,
            'phone' => $phone ?: '(NO PHONE FOUND)',
            'contacts_count' => count($client['contacts'] ?? []),
            'contacts' => array_map(function($c) {
                return ['phone' => $c['phone'] ?? '', 'email' => $c['email'] ?? ''];
            }, $client['contacts'] ?? []),
            'dry_run_mode' => ($config['dry_run_mode'] ?? false) ? 'ON' : 'OFF',
            'wa_configured' => !empty($config['wa_plugin_url']) && !empty($config['wa_app_key']),
        ];
        
        if (!$phone) {
            $debug['error'] = 'No phone number found in client contacts';
            $ok2($debug, 'Test failed - no phone');
        }
        
        // Send test invoice notification
        $testInvoiceNum = 'TEST-' . date('His');
        $testAmount = 100.00;
        $testDueDate = date('Y-m-d', strtotime('+7 days'));
        
        $notify->invoiceCreated($phone, $name, $testInvoiceNum, $testAmount, $testDueDate);
        
        $debug['sent'] = true;
        $debug['test_invoice'] = [
            'number' => $testInvoiceNum,
            'amount' => $testAmount,
            'due_date' => $testDueDate,
        ];
        
        $ok2($debug, 'Test invoice notification sent');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WEBHOOK AUTO-SETUP
    // ═══════════════════════════════════════════════════════════════════════════

    // Auto-setup webhook in UCRM (creates or updates)
    // Usage: ?page=api&action=webhook_setup
    // Or: ?page=api&action=webhook_setup&debug_key=YOUR_WEBHOOK_SECRET
    if ($act === 'webhook_setup' && $met === 'POST') {
        if (!$debugAuth) {
            $er2('Admin login required. Current user: ' . ($currentUser['username'] ?? 'none') . ', is_admin: ' . ($isAdminDebug ? 'yes' : 'no'), 403);
        }
        
        $secret = $config['webhook_secret'] ?? '';
        if (empty($secret)) {
            // Generate secret if not set
            $secret = bin2hex(random_bytes(16));
            $config['webhook_secret'] = $secret;
            $store->save('kyc_config.json', $config);
        }
        
        $result = $crm->autoSetupWebhook(__DIR__ . '/..', $secret);
        
        // Add debug info
        $result['debug'] = [
            'crm_configured' => $crm->isConfigured(),
            'crm_base_url' => $crm->getBaseUrl() ? substr($crm->getBaseUrl(), 0, 60) : '(empty)',
            'current_user' => $currentUser['username'] ?? 'unknown',
        ];
        
        if ($result['success']) {
            // Save to config
            $config['webhook_auto_setup_done'] = true;
            $config['webhook_id'] = $result['webhook_id'] ?? null;
            $config['webhook_url'] = $result['url'] ?? null;
            $store->save('kyc_config.json', $config);
            
            $ok2($result, 'Webhook configured successfully');
        } else {
            // Return error with debug info
            $result['crm_last_error'] = $crm->getLastError();
            $er2(json_encode($result), 500);
        }
    }

    // Check webhook status
    // Usage: ?page=api&action=webhook_status
    if ($act === 'webhook_status' && $met === 'GET') {
        if (!$debugAuth) $er2('Admin or debug_key required', 403);
        
        // Get all webhooks from UCRM
        $webhooks = $crm->getWebhooks();
        
        // Find ours
        $ours = null;
        $others = [];
        foreach ($webhooks as $wh) {
            if (strpos($wh['url'] ?? '', 'dishnet-hybrid-telecom') !== false) {
                $ours = $wh;
            } else {
                $others[] = [
                    'id' => $wh['id'] ?? null,
                    'url' => $wh['url'] ?? '',
                    'active' => $wh['isActive'] ?? false,
                ];
            }
        }
        
        // Get expected URL
        $ucrmConfig = [];
        foreach ([dirname(__DIR__, 2) . '/ucrm.json', dirname(__DIR__, 2) . '/data/ucrm.json'] as $path) {
            if (file_exists($path)) {
                $c = json_decode(file_get_contents($path), true);
                if (is_array($c) && !empty($c)) { $ucrmConfig = $c; break; }
            }
        }
        $expectedUrl = rtrim($ucrmConfig['ucrmPublicUrl'] ?? '', '/') . '/_plugins/dishnet-hybrid-telecom/webhook.php';
        
        $ok2([
            'configured' => $ours !== null,
            'webhook' => $ours ? [
                'id' => $ours['id'] ?? null,
                'url' => $ours['url'] ?? '',
                'active' => $ours['isActive'] ?? false,
                'events' => $ours['eventTypes'] ?? 'all',
                'url_correct' => ($ours['url'] ?? '') === $expectedUrl,
            ] : null,
            'expected_url' => $expectedUrl,
            'other_webhooks_count' => count($others),
            'webhook_secret_set' => !empty($config['webhook_secret']),
        ], 'Webhook status');
    }

    // Delete duplicate/wrong webhooks for this plugin
    // Usage: ?page=api&action=webhook_cleanup
    if ($act === 'webhook_cleanup' && $met === 'POST') {
        if (!$debugAuth) $er2('Admin or debug_key required', 403);
        
        $webhooks = $crm->getWebhooks();
        $deleted = [];
        $kept = null;
        
        // Find all webhooks pointing to our plugin
        $ours = [];
        foreach ($webhooks as $wh) {
            if (strpos($wh['url'] ?? '', 'dishnet-hybrid-telecom') !== false) {
                $ours[] = $wh;
            }
        }
        
        // Keep the first active one (or first if none active), delete the rest
        usort($ours, function($a, $b) {
            // Active ones first
            $aActive = ($a['isActive'] ?? false) ? 0 : 1;
            $bActive = ($b['isActive'] ?? false) ? 0 : 1;
            return $aActive - $bActive;
        });
        
        foreach ($ours as $i => $wh) {
            if ($i === 0) {
                $kept = $wh;
            } else {
                $crm->deleteWebhook((int)$wh['id']);
                $deleted[] = ['id' => $wh['id'], 'url' => $wh['url']];
            }
        }
        
        $ok2([
            'kept' => $kept ? ['id' => $kept['id'], 'url' => $kept['url']] : null,
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
        ], 'Webhook cleanup complete');
    }

    // Check notification log
    // Usage: ?page=api&action=notification_log&limit=20
    // Or: ?page=api&action=notification_log&limit=20&debug_key=YOUR_WEBHOOK_SECRET
    if ($act === 'notification_log' && $met === 'GET') {
        if (!$debugAuth) $er2('Admin or debug_key required', 403);
        
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 20)));
        $since = trim($_GET['since'] ?? '');
        $phone = trim($_GET['phone'] ?? '');
        
        try {
            $notify = svc('notify');
            $result = $notify->getAuditLog($limit, $since, $phone);
            $ok2($result, 'Notification audit log');
        } catch (\Throwable $e) {
            $ok2(['entries' => [], 'total' => 0, 'error' => $e->getMessage()], 'Notification log error');
        }
    }

    // Check WhatsApp/notification config
    // Usage: ?page=api&action=check_wa_config
    // Or: ?page=api&action=check_wa_config&debug_key=YOUR_WEBHOOK_SECRET
    if ($act === 'check_wa_config' && $met === 'GET') {
        if (!$debugAuth) $er2('Admin or debug_key required', 403);
        
        $ok2([
            'wa_plugin_url' => !empty($config['wa_plugin_url']) ? substr($config['wa_plugin_url'], 0, 50) . '...' : '(not set)',
            'wa_app_key' => !empty($config['wa_app_key']) ? substr($config['wa_app_key'], 0, 8) . '...' : '(not set)',
            'wa_auth_key' => !empty($config['wa_auth_key']) ? substr($config['wa_auth_key'], 0, 8) . '...' : '(not set)',
            'wa_accounts_app_key' => !empty($config['wa_accounts_app_key']) ? substr($config['wa_accounts_app_key'], 0, 8) . '...' : '(not set)',
            'whatsapp_admin_phone' => $config['whatsapp_admin_phone'] ?? '(not set)',
            'dry_run_mode' => ($config['dry_run_mode'] ?? false) ? 'ON' : 'OFF',
            'webhook_secret' => !empty($config['webhook_secret']) ? 'SET (' . strlen($config['webhook_secret']) . ' chars)' : '(not set)',
            'crm_base_url' => $config['crm_base_url'] ?? '(auto from ucrm.json)',
        ], 'WhatsApp configuration');
    }

    // ── Diagnostic: Check UCRM suspension / notification settings ──────────
    if ($act === 'crm_check_settings') {
        if (!$isAdmin) $er2('Admin only', 403);
        if (!$crm->isConfigured()) $er2('CRM not configured', 503);

        $result = ['_crm_base' => $crm->getBaseUrl()];

        // 1. Organization/billing settings
        try {
            $org = $crm->get('organizations');
            $result['organizations'] = array_map(function($o) {
                return [
                    'id' => $o['id'] ?? null,
                    'name' => $o['name'] ?? '',
                    'currency' => $o['currencyCode'] ?? '',
                ];
            }, $org ?? []);
        } catch (\Throwable $e) { $result['organizations'] = 'error: ' . $e->getMessage(); }

        // 2. Service plans (to check suspension settings)
        try {
            $plans = $crm->get('service-plans') ?? [];
            $result['service_plans'] = array_map(function($p) {
                return [
                    'id' => $p['id'] ?? null,
                    'name' => $p['name'] ?? '',
                    'invoicingPeriodType' => $p['invoicingPeriodType'] ?? null,
                    'invoicingPeriodMonths' => $p['invoicingPeriodMonths'] ?? null,
                    'invoicingDaysBeforeDueDate' => $p['invoicingDaysBeforeDueDate'] ?? null,
                    'earlyTerminationFeePrice' => $p['earlyTerminationFeePrice'] ?? null,
                    'minimumContractLengthMonths' => $p['minimumContractLengthMonths'] ?? null,
                ];
            }, $plans);
        } catch (\Throwable $e) { $result['service_plans'] = 'error: ' . $e->getMessage(); }

        // 3. Notification templates / settings
        try {
            $notifSettings = $crm->get('notification-settings');
            $result['notification_settings'] = $notifSettings;
        } catch (\Throwable $e) { $result['notification_settings'] = 'error: ' . $e->getMessage(); }

        // 4. Invoice templates / maturity settings
        try {
            $invoiceTemplates = $crm->get('invoice-templates');
            $result['invoice_templates'] = $invoiceTemplates;
        } catch (\Throwable $e) { $result['invoice_templates'] = 'error: ' . $e->getMessage(); }

        // 5. Billing settings (suspension, late fees, etc.)
        try {
            $billing = $crm->get('billing');
            $result['billing'] = $billing;
        } catch (\Throwable $e) { $result['billing'] = 'error: ' . $e->getMessage(); }

        // 6. Suspension settings specifically
        try {
            $suspend = $crm->get('suspend');
            $result['suspend'] = $suspend;
        } catch (\Throwable $e) { $result['suspend'] = 'error: ' . $e->getMessage(); }

        // 7. Try general settings/options
        try {
            $options = $crm->get('options');
            $result['options'] = $options;
        } catch (\Throwable $e) { $result['options'] = 'error: ' . $e->getMessage(); }

        // 8. Check client-zone settings (notification-related)
        try {
            $cz = $crm->get('client-zone-pages');
            $result['client_zone_pages'] = $cz;
        } catch (\Throwable $e) { $result['client_zone_pages'] = 'error: ' . $e->getMessage(); }

        // 9. Sample: check a recent unpaid invoice to see maturityDate logic
        try {
            $unpaid = $crm->get('invoices?status[]=0&status[]=1&limit=5&direction=DESC') ?? [];
            $result['sample_unpaid_invoices'] = array_map(function($inv) {
                return [
                    'id'           => $inv['id'] ?? null,
                    'number'       => $inv['number'] ?? null,
                    'clientId'     => $inv['clientId'] ?? null,
                    'status'       => $inv['status'] ?? null,
                    'total'        => $inv['total'] ?? null,
                    'createdDate'  => $inv['createdDate'] ?? null,
                    'dueDate'      => $inv['dueDate'] ?? null,
                    'maturityDate' => $inv['maturityDate'] ?? null,
                    'emailSentDate'=> $inv['emailSentDate'] ?? null,
                ];
            }, $unpaid);
        } catch (\Throwable $e) { $result['sample_unpaid_invoices'] = 'error: ' . $e->getMessage(); }

        $ok2($result, 'CRM settings diagnostic');
    }

    // ── Configure UCRM notifications — disable duplicates, keep essentials ──
    // GET ?page=api&action=crm_fix_notifications           — apply recommended settings
    // GET ?page=api&action=crm_fix_notifications&dry_run=1 — show what would change
    if ($act === 'crm_fix_notifications') {
        if (!$isAdmin) $er2('Admin only', 403);
        if (!$crm->isConfigured()) $er2('CRM not configured', 503);

        $dryRun = !empty($_GET['dry_run']);

        // First read current values
        $current = $crm->get('options');
        if (!$current) $er2('Failed to fetch UCRM options', 500);

        $changes = [
            // TURN OFF: near-due email (broken "3 days" template, Hybrid handles via WhatsApp)
            'notificationInvoiceNearDue' => false,

            // TURN OFF: overdue email at Day+1 (Hybrid sends WhatsApp at Day+1, +3, +5)
            'notificationInvoiceOverdue' => false,

            // KEEP ON: new invoice email (useful alongside Hybrid's WhatsApp)
            // 'notificationInvoiceNew' => true,  // already true, no change needed

            // KEEP ON: suspension notification (UCRM controls the actual suspend)
            // 'notificationServiceSuspended' => true,  // already true
        ];

        $result = [
            'dry_run' => $dryRun,
            'changes' => [],
        ];

        foreach ($changes as $key => $newVal) {
            $oldVal = $current[$key] ?? null;
            $result['changes'][] = [
                'setting' => $key,
                'old'     => $oldVal,
                'new'     => $newVal,
                'changed' => $oldVal !== $newVal,
            ];
        }

        // What stays enabled
        $result['kept_enabled'] = [
            'notificationInvoiceNew'       => $current['notificationInvoiceNew'] ?? null,
            'notificationServiceSuspended' => $current['notificationServiceSuspended'] ?? null,
            'stopServiceDue'               => $current['stopServiceDue'] ?? null,
            'stopServiceDueDays'           => $current['stopServiceDueDays'] ?? null,
        ];

        if (!$dryRun) {
            $patchResult = $crm->patch('options', $changes);
            if ($patchResult !== null) {
                $result['status'] = 'applied';
                $result['message'] = 'UCRM notifications updated. Near-due and overdue emails disabled. Hybrid plugin handles WhatsApp reminders.';
                logActivity($dataDir, 'crm_notifications_configured', 'UCRM notification settings updated',
                    'Disabled: notificationInvoiceNearDue, notificationInvoiceOverdue. By: ' . ($me2['name'] ?? 'admin'));
            } else {
                $result['status'] = 'failed';
                $result['message'] = 'PATCH /options failed — check UCRM API permissions';
            }
        } else {
            $result['message'] = 'Dry run — no changes made. Remove &dry_run=1 to apply.';
        }

        $ok2($result);
    }

    // ── Invoice Notification Scanner (manual trigger) ────────────────────
    // GET ?page=api&action=invoice_notify_scan           → dry run (preview)
    // GET ?page=api&action=invoice_notify_scan&send=1    → actually send
    if ($act === 'invoice_notify_scan') {
        if (!$isAdmin) $er2('Admin only', 403);

        $doSend = ($_GET['send'] ?? '0') === '1';
        $invNotifyLog = $store->load('invoice_notify_log.json') ?: [];
        $results2 = [];

        // Query UCRM for recent invoices — try multiple endpoint formats
        $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
        $todayDate     = date('Y-m-d');

        $allInvoices = $crm->get("billing/invoices?createdDateFrom={$yesterdayDate}&createdDateTo={$todayDate}&limit=500");
        $endpoint = "billing/invoices?createdDateFrom=...";
        if (empty($allInvoices)) {
            $allInvoices = $crm->get("invoices?createdDateFrom={$yesterdayDate}&createdDateTo={$todayDate}&limit=500");
            $endpoint = "invoices?createdDateFrom=...";
        }
        if (empty($allInvoices)) {
            $allInvoices = $crm->get('invoices?statuses[]=1&limit=500') ?: [];
            $endpoint = "invoices?statuses[]=1 (fallback)";
        }

        // Filter to last 24h
        $cutoffTime = strtotime('-24 hours');
        $recent = [];
        foreach (($allInvoices ?: []) as $inv) {
            $created = $inv['createdDate'] ?? $inv['createdAt'] ?? $inv['created_at'] ?? '';
            $ts = $created ? strtotime($created) : 0;
            $isRecent = !$created || ($ts && $ts >= $cutoffTime);

            $invoiceNum = (string)($inv['number'] ?? $inv['id'] ?? '?');
            $logKey     = "INV{$invoiceNum}";
            $alreadySent = isset($invNotifyLog[$logKey]);

            $clientId = (int)($inv['clientId'] ?? 0);
            $total    = (float)($inv['total'] ?? 0);

            $entry = [
                'invoice_id'  => $inv['id'] ?? null,
                'number'      => $invoiceNum,
                'total'       => $total,
                'client_id'   => $clientId,
                'created'     => $created,
                'due_date'    => $inv['dueDate'] ?? $inv['maturityDate'] ?? '',
                'is_recent'   => $isRecent,
                'already_sent'=> $alreadySent,
                'action'      => 'skip',
            ];

            if ($isRecent && !$alreadySent && $clientId && $total > 0) {
                // Fetch client phone
                $client2 = $crm->get("clients/{$clientId}");
                $phone2 = '';
                foreach (($client2['contacts'] ?? []) as $c2) {
                    if (!empty($c2['phone'])) { $phone2 = $c2['phone']; break; }
                }
                $name2 = trim(($client2['firstName'] ?? '') . ' ' . ($client2['lastName'] ?? ''));

                $entry['phone'] = $phone2 ?: '(none)';
                $entry['name']  = $name2;

                if ($phone2 && $doSend) {
                    $notify->invoiceCreated($phone2, $name2, $invoiceNum, $total, $entry['due_date'] ?: 'See invoice');
                    $invNotifyLog[$logKey] = date('Y-m-d H:i:s');
                    $entry['action'] = 'SENT';
                    usleep(500000);
                } elseif ($phone2) {
                    $entry['action'] = 'would_send';
                } else {
                    $entry['action'] = 'no_phone';
                }
            }

            $recent[] = $entry;
        }

        if ($doSend && !empty($invNotifyLog)) {
            $store->save('invoice_notify_log.json', $invNotifyLog);
        }

        $ok2([
            'endpoint_used'  => $endpoint,
            'total_fetched'  => count($allInvoices ?: []),
            'recent_count'   => count($recent),
            'mode'           => $doSend ? 'LIVE — messages sent' : 'DRY RUN — add &send=1 to send',
            'invoices'       => $recent,
        ], 'Invoice notification scanner');
    }

    // ── Test: Probe WhatsML API for document sending support ──────────────
    // GET ?page=api&action=wa_test_document&to=PHONE
    // Tries multiple field patterns to find the correct one for sending documents
    if ($act === 'wa_test_document') {
        if (!$isAdmin) $er2('Admin only', 403);

        $testPhone = trim($_GET['to'] ?? '');
        if (!$testPhone) $er2('Provide &to=PHONE (e.g. 211921443009)', 400);

        $waUrl    = rtrim(trim($config['wa_plugin_url'] ?? ''), '/');
        $appKey   = trim($config['wa_accounts_app_key'] ?? $config['wa_app_key'] ?? '');
        $authKey  = trim($config['wa_auth_key'] ?? '');

        if (!$waUrl || !$appKey || !$authKey) $er2('WA config missing (wa_plugin_url, wa_app_key, wa_auth_key)', 500);

        $endpoint = $waUrl . '/api/whatsapp-web/send-message';
        $testTo   = preg_replace('/[^0-9]/', '', $testPhone);

        // A tiny test PDF (1-page blank)
        $testPdfUrl = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf';

        $results = [];

        // Helper function to send test
        $tryMethod = function(string $label, array $formData, bool $isJson = false) use ($endpoint, &$results) {
            $ch = curl_init();
            $opts = [
                CURLOPT_URL            => $endpoint,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_POST           => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true,
            ];
            if ($isJson) {
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
                $opts[CURLOPT_POSTFIELDS] = json_encode($formData);
            } else {
                $opts[CURLOPT_POSTFIELDS] = $formData;
            }
            curl_setopt_array($ch, $opts);
            $resp     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            $results[$label] = [
                'http_code' => $httpCode,
                'success'   => $httpCode >= 200 && $httpCode < 300 && !$err,
                'response'  => $resp ? json_decode($resp, true) ?: substr($resp, 0, 300) : $err,
                'fields'    => array_map(function($v) { return is_string($v) && strlen($v) > 50 ? substr($v,0,50).'...' : $v; }, $formData),
            ];
        };

        // Test 1: type=document + media_url (form-data)
        $tryMethod('form_type_document_media_url', [
            'app_key'   => $appKey,
            'auth_key'  => $authKey,
            'to'        => $testTo,
            'type'      => 'document',
            'message'   => 'Test doc from DishNet plugin',
            'media_url' => $testPdfUrl,
            'filename'  => 'test-invoice.pdf',
        ]);

        // Test 2: type=document + url field
        $tryMethod('form_type_document_url', [
            'app_key'   => $appKey,
            'auth_key'  => $authKey,
            'to'        => $testTo,
            'type'      => 'document',
            'message'   => 'Test doc from DishNet plugin',
            'url'       => $testPdfUrl,
            'filename'  => 'test-invoice.pdf',
        ]);

        // Test 3: JSON with documentUrl (wasenderapi pattern)
        $tryMethod('json_documentUrl', [
            'app_key'     => $appKey,
            'auth_key'    => $authKey,
            'to'          => $testTo,
            'message'     => 'Test doc from DishNet plugin',
            'documentUrl' => $testPdfUrl,
            'fileName'    => 'test-invoice.pdf',
        ], true);

        // Test 4: type=media + media_type=document
        $tryMethod('form_type_media_document', [
            'app_key'    => $appKey,
            'auth_key'   => $authKey,
            'to'         => $testTo,
            'type'       => 'media',
            'media_type' => 'document',
            'message'    => 'Test doc from DishNet plugin',
            'media_url'  => $testPdfUrl,
            'filename'   => 'test-invoice.pdf',
        ]);

        // Test 5: document_url field (no type)
        $tryMethod('form_document_url_field', [
            'app_key'      => $appKey,
            'auth_key'     => $authKey,
            'to'           => $testTo,
            'message'      => 'Test doc from DishNet plugin',
            'document_url' => $testPdfUrl,
            'filename'     => 'test-invoice.pdf',
        ]);

        // Identify winner
        $winner = null;
        foreach ($results as $label => $r) {
            if ($r['success'] && isset($r['response']['status']) && $r['response']['status'] === true) {
                $winner = $label;
                break;
            }
            if ($r['success'] && !isset($r['response']['error'])) {
                $winner = $label;
            }
        }

        $ok2([
            'endpoint'   => $endpoint,
            'test_phone' => $testTo,
            'test_pdf'   => $testPdfUrl,
            'winner'     => $winner ?: 'none — check responses below',
            'results'    => $results,
        ], 'WhatsML document API probe');
    }

    // ── Backfill crm_client_type on existing KYC applications ────────────
    // GET ?page=api&action=backfill_client_type&dry_run=1
    if ($act === 'backfill_client_type') {
        if (!$isAdmin) $er2('Admin only', 403);

        $dryRun = ($_GET['dry_run'] ?? '1') === '1';
        $apps   = $store->load('kyc_applications.json') ?? [];
        $updated = 0;
        $already = 0;
        $details = [];

        foreach ($apps as &$a) {
            // Skip if already tagged
            if (!empty($a['crm_client_type'])) { $already++; continue; }

            // Determine type from existing is_lead field
            $isLead = $a['is_lead'] ?? $a['isLead'] ?? null;
            if ($isLead !== null) {
                $type = $isLead ? 'lead' : 'regular';
            } else {
                // Fallback: if has crm_client_id and amount_charged > 0, likely regular
                $hasCrm = !empty($a['crm_client_id']);
                $hasPay = ((float)($a['amount_charged'] ?? 0)) > 0;
                $type   = ($hasCrm && $hasPay) ? 'regular' : 'lead';
            }

            $a['crm_client_type'] = $type;
            $updated++;
            $details[] = [
                'id'    => $a['id'] ?? '?',
                'name'  => trim(($a['firstname'] ?? '') . ' ' . ($a['lastname'] ?? '')),
                'crm_id'=> $a['crm_client_id'] ?? '',
                'type'  => $type,
            ];
        }
        unset($a);

        if (!$dryRun) {
            $store->save('kyc_applications.json', $apps);
        }

        $ok2([
            'dry_run'  => $dryRun,
            'updated'  => $updated,
            'already'  => $already,
            'total'    => count($apps),
            'samples'  => array_slice($details, 0, 20),
            'note'     => $dryRun ? 'Remove &dry_run=1 to apply' : 'Applied successfully',
        ], 'Backfill crm_client_type');
    }

    // ══════════════════════════════════════════════════════════════════════
    // NOTIFICATION QUEUE — view failed sends, retry single/bulk, dismiss
    // ══════════════════════════════════════════════════════════════════════

    // GET ?page=api&action=notification_queue&status=failed&limit=50&offset=0
    if ($act === 'notification_queue' && $met === 'GET') {
        $status = $_GET['status'] ?? 'failed';
        $limit  = max(1, min(200, (int)($_GET['limit']  ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $notify = svc('notify');
        $queue  = $notify->getQueue($status, $limit, $offset);
        $stats  = $notify->getQueueStats();
        $ok2(['queue' => $queue['items'], 'total' => $queue['total'], 'stats' => $stats], 'Notification queue');
    }

    // POST ?page=api&action=notification_retry&id=123
    if ($act === 'notification_retry' && $met === 'POST') {
        $qId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$qId) $er2('id required', 422);
        $notify = svc('notify');
        $result = $notify->retryOne($qId, $retailer['name'] ?? 'Admin');
        if ($result['success']) {
            $ok2($result, 'Message resent successfully');
        } else {
            $er2($result['error'] ?? 'Retry failed', 422);
        }
    }

    // POST ?page=api&action=notification_retry_bulk
    if ($act === 'notification_retry_bulk' && $met === 'POST') {
        $maxBatch = max(1, min(100, (int)($_POST['max'] ?? 50)));
        $notify   = svc('notify');
        $result   = $notify->retryBulk($retailer['name'] ?? 'Admin', $maxBatch);
        $ok2($result, "Bulk retry: {$result['sent']}/{$result['total']} sent");
    }

    // POST ?page=api&action=notification_dismiss&id=123
    if ($act === 'notification_dismiss' && $met === 'POST') {
        $qId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$qId) $er2('id required', 422);
        $notify = svc('notify');
        $ok2(['dismissed' => $notify->dismissOne($qId, $retailer['name'] ?? 'Admin')], 'Dismissed');
    }

    // POST ?page=api&action=notification_dismiss_all
    if ($act === 'notification_dismiss_all' && $met === 'POST') {
        $notify = svc('notify');
        $count  = $notify->dismissAll($retailer['name'] ?? 'Admin');
        $ok2(['dismissed' => $count], "{$count} notifications dismissed");
    }

    // POST ?page=api&action=notification_purge&days=30
    if ($act === 'notification_purge' && $met === 'POST') {
        $days   = max(1, (int)($_POST['days'] ?? 30));
        $notify = svc('notify');
        $count  = $notify->purgeQueue($days);
        $ok2(['purged' => $count, 'older_than_days' => $days], "{$count} old records purged");
    }
