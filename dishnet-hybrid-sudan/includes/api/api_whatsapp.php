<?php
// ═══════════════════════════════════════════════════════════════
// WHATSAPP UNIFIED INBOX — API Endpoints
// Uses ConversationService (SQLite) for all conversation data.
// ═══════════════════════════════════════════════════════════════

    // Lazy-load ConversationService
    if (!isset($GLOBALS['_convSvc'])) {
        require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/ConversationService.php';
        $GLOBALS['_convSvc'] = new ConversationService($GLOBALS['dataDir'], $store->getPdo());
    }
    $_convSvc = $GLOBALS['_convSvc'];

    // ── wa_debug: Check webhook log + bot config (admin only) ──────────
    if ($act === 'wa_debug') {
        if (!$isAdmin) $er2('Admin only', 403);
        $logFile = $dataDir . '/wa_webhook_log.json';
        $log = [];
        if (file_exists($logFile)) {
            $raw = json_decode(file_get_contents($logFile), true);
            $log = is_array($raw) ? array_slice($raw, 0, 20) : [];
        }
        $ok2([
            'wa_bot_enabled'         => !empty($config['wa_bot_enabled']),
            'wa_auto_reply_enabled'  => !empty($config['wa_auto_reply_enabled']),
            'wa_app_key'             => !empty($config['wa_app_key']) ? substr($config['wa_app_key'], 0, 8) . '...' : '(empty)',
            'wa_accounts_app_key'    => !empty($config['wa_accounts_app_key']) ? substr($config['wa_accounts_app_key'], 0, 8) . '...' : '(empty)',
            'wa_webhook_secret'      => !empty($config['wa_webhook_secret']) ? '(set)' : '(empty)',
            'recent_webhook_log'     => $log,
        ]);
    }

    // ── wa_crm_raw_debug: Show raw UCRM API response for a client (admin) ──
    // Use this to diagnose why services/payments show empty in the PWA
    // GET ?page=api&action=wa_crm_raw_debug&client_id=1110
    if ($act === 'wa_crm_raw_debug') {
        if (!$isAdmin) $er2('Admin only', 403);
        $clientId = (int)($_GET['client_id'] ?? 0);
        if (!$clientId) $er2('client_id required. e.g. &client_id=1110', 422);
        $result = ['client_id' => $clientId, 'client' => null, 'services' => [], 'payments' => [], 'notes' => []];
        try {
            $result['client'] = svc('crm')->get("clients/{$clientId}");
            $result['notes'][] = 'client: OK';
        } catch (Throwable $e) {
            $result['notes'][] = 'client ERROR: ' . $e->getMessage();
        }
        try {
            $svcs = svc('crm')->get("clients/{$clientId}/services") ?? [];
            // Show raw first 3 services with ALL fields so we can see the real structure
            $result['services_raw'] = array_slice($svcs, 0, 3);
            $result['services_count'] = count($svcs);
            // Also show what our parser extracts
            $stLabel = [0 => 'Prep', 1 => 'Active', 2 => 'Ended', 4 => 'Quoted', 5 => 'Suspended'];
            $result['services_parsed'] = array_map(function($s) use ($stLabel) {
                return [
                    'extracted_name'   => $s['servicePlanName'] ?? $s['name'] ?? '(empty)',
                    'extracted_status' => $stLabel[(int)($s['status'] ?? -1)] ?? 'Unknown (' . ($s['status'] ?? 'null') . ')',
                    'raw_status_int'   => $s['status'] ?? null,
                    'raw_name'         => $s['name'] ?? null,
                    'raw_servicePlanName' => $s['servicePlanName'] ?? null,
                    'raw_id'           => $s['id'] ?? null,
                ];
            }, $svcs);
            $result['notes'][] = 'services: ' . count($svcs) . ' found';
        } catch (Throwable $e) {
            $result['notes'][] = 'services ERROR: ' . $e->getMessage();
        }
        try {
            $pays = svc('crm')->get("payments?clientId={$clientId}&limit=5") ?? [];
            $result['payments_count'] = count($pays);
            $result['payments_raw']   = array_slice($pays, 0, 2); // first 2 raw
            $result['notes'][] = 'payments: ' . count($pays) . ' found';
        } catch (Throwable $e) {
            $result['notes'][] = 'payments ERROR: ' . $e->getMessage();
        }
        $ok2($result);
    }

    // ── wa_update_keys: Update WA app keys (admin only) ────────────────
    if ($act === 'wa_update_keys') {
        if (!$isAdmin) $er2('Admin only', 403);
        $newSupport  = trim($_GET['support_key']  ?? $body['support_key']  ?? '');
        $newAccounts = trim($_GET['accounts_key'] ?? $body['accounts_key'] ?? '');
        $newAuth     = trim($_GET['auth_key']     ?? $body['auth_key']     ?? '');
        $changed = [];
        if ($newSupport)  { $config['wa_app_key']          = $newSupport;  $changed[] = 'support_key'; }
        if ($newAccounts) { $config['wa_accounts_app_key'] = $newAccounts; $changed[] = 'accounts_key'; }
        if ($newAuth)     { $config['wa_auth_key']         = $newAuth;     $changed[] = 'auth_key'; }
        if (empty($changed)) $er2('No keys provided. Use support_key, accounts_key, auth_key params.', 422);
        $store->save('kyc_config.json', $config);
        $ok2([
            'updated'          => $changed,
            'wa_app_key'       => substr($config['wa_app_key'] ?? '', 0, 12) . '...',
            'wa_accounts_key'  => substr($config['wa_accounts_app_key'] ?? '', 0, 12) . '...',
            'wa_auth_key'      => '(set)',
        ], 'Keys updated');
    }

    // ── wa_toggle_bot: Enable/disable auto-reply (admin only) ────────
    if ($act === 'wa_toggle_bot') {
        if (!$isAdmin) $er2('Admin only', 403);
        $enable = isset($_GET['on']) ? (bool)$_GET['on'] : (isset($body['enabled']) ? (bool)$body['enabled'] : !($config['wa_bot_enabled'] ?? false));
        $config['wa_bot_enabled'] = $enable;
        $store->save('kyc_config.json', $config);
        $ok2(['wa_bot_enabled' => $enable], $enable ? 'Auto-reply enabled' : 'Auto-reply disabled');
    }

    // ── wa_test_webhook: Simulate an incoming message (admin only) ───
    if ($act === 'wa_test_webhook') {
        if (!$isAdmin) $er2('Admin only', 403);
        $testPhone = trim($_GET['phone'] ?? '211921443006');
        $testText  = trim($_GET['text'] ?? 'hi');
        $testChannel = trim($_GET['channel'] ?? 'support');
        try {
            require_once dirname(__DIR__, 2) . '/lib/WaAutoReplyService.php';
            require_once dirname(__DIR__, 2) . '/lib/ConversationService.php';
            require_once dirname(__DIR__, 2) . '/lib/NotificationService.php';
            $notify = new NotificationService($store, $config);
            $convSvc = new ConversationService($dataDir, $store->getPdo());
            $autoReply = new WaAutoReplyService($store, $store->getPdo(), $notify, $config, $convSvc);
            $result = $autoReply->handleIncoming($testPhone, $testText, $testChannel, 'Test User');
            $ok2([
                'test_phone'   => $testPhone,
                'test_text'    => $testText,
                'test_channel' => $testChannel,
                'bot_enabled'  => !empty($config['wa_bot_enabled']),
                'result'       => $result,
            ]);
        } catch (Throwable $e) {
            $er2('Test failed: ' . $e->getMessage(), 500);
        }
    }

    // ── wa_sync_log: Show cron sync state + last sync output (admin) ──
    if ($act === 'wa_sync_log') {
        if (!$isAdmin) $er2('Admin only', 403);
        $stateFile = $dataDir . '/wa_sync_state.json';
        $syncState = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
        $cronLogFile = $dataDir . '/wa_bot_cron_log.json';
        $cronLog = file_exists($cronLogFile) ? (json_decode(file_get_contents($cronLogFile), true) ?: []) : [];
        $ok2([
            'sync_state'    => $syncState,
            'bot_cron_log'  => array_slice($cronLog, 0, 10),
            'bot_enabled'   => !empty($config['wa_bot_enabled']),
            'session_map'   => [
                'support'  => $config['wa_session_support']  ?? '7cc8d42a-fe45-4a84-80dc-0ae4b0f42cfc',
                'accounts' => $config['wa_session_accounts'] ?? '1d327e8a-de0c-4888-8f81-21675c70ef1e',
            ],
        ]);
    }


    // ── wa_media_debug: show captured WASender media payloads ────────────
    if ($act === 'wa_media_debug') {
        if (!$isAdmin) $er2('Admin only.', 403);
        try {
            $rows = $store->getPdo()->query(
                "SELECT id, type, phone, payload, created_at FROM wa_media_debug ORDER BY id DESC LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC);
            $ok2(['count' => count($rows), 'payloads' => $rows]);
        } catch (Throwable $e) {
            $ok2(['count' => 0, 'payloads' => [], 'note' => 'No media messages received yet — table will be created when first media arrives']);
        }
        return;
    }

    // ── wa_conversations: List all conversations + stats ─────────────
    if ($act === 'wa_conversations') {
        $filters = [];
        if (!empty($_GET['channel']))  $filters['channel']  = $_GET['channel'];
        if (!empty($_GET['status']))   $filters['status']   = $_GET['status'];
        if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
        if (!empty($_GET['search']))   $filters['search']   = $_GET['search'];
        if (!empty($_GET['state']))    $filters['state']    = $_GET['state'];

        $unreadOnly = ($_GET['unread'] ?? '') === '1';
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $convs = $_convSvc->listConversations($filters, $limit, $offset);
        if ($unreadOnly) {
            $convs = array_values(array_filter($convs, fn($c) => (int)($c['unread_count'] ?? 0) > 0));
        }

        $ok2([
            'conversations' => $convs,
            'total'         => $_convSvc->countConversations($filters),
            'stats'         => $_convSvc->getStats(),
        ]);
    }

    // ── wa_thread_messages: Get paginated messages for a thread ──────
    if ($act === 'wa_thread_messages') {
        $convId = (int)($_GET['id'] ?? 0);
        if (!$convId) $er2('id required.', 422);
        $conv = $_convSvc->getConversation($convId);
        if (!$conv) $er2('Conversation not found.', 404);
        $limit  = min((int)($_GET['limit'] ?? 100), 500);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $_convSvc->markRead($convId);
        $ok2([
            'conversation' => $conv,
            'messages'     => $_convSvc->getMessages($convId, $limit, $offset),
            'total'        => $_convSvc->countMessages($convId),
        ]);
    }

    // ── wa_send_reply: Staff reply via WhatsML + store ───────────────
    if ($act === 'wa_send_reply' && $met === 'POST') {
        $convId = (int)($body['conversation_id'] ?? $body['conv_id'] ?? 0);
        $text   = trim($body['message'] ?? $body['text'] ?? '');
        if (!$convId || !$text) $er2('conversation_id and message required.', 422);
        $conv = $_convSvc->getConversation($convId);
        if (!$conv) $er2('Conversation not found.', 404);
        $phone   = $conv['phone'];
        $sender  = ($conv['channel'] ?? 'support') === 'accounts' ? 'accounts' : 'support';
        $staffNm = $retailer['name'] ?? 'Staff';

        svc('notify')->sendVia($sender, $phone, $text, 'wa_staff_reply');
        $_convSvc->storeMessage($convId, [
            'direction' => 'out', 'role' => 'agent', 'body' => $text,
            'agent_name' => $staffNm, 'event_key' => 'wa_staff_reply',
            'sent_at' => gmdate('Y-m-d H:i:s'),
        ]);
        // Disengage bot — staff is now handling this conversation
        try {
            $store->getPdo()->prepare(
                "UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
            )->execute([$convId]);
        } catch (Throwable $_e) {}
        $ok2(['sent' => true, 'to' => $phone, 'channel' => $sender]);
    }

    // ── wa_send_document: Send document via WhatsML + store ──────────
    if ($act === 'wa_send_document' && $met === 'POST') {
        $convId = (int)($body['conversation_id'] ?? 0);
        $docUrl = trim($body['document_url'] ?? $body['url'] ?? '');
        $fname  = trim($body['filename'] ?? 'document.pdf');
        $cap    = trim($body['caption'] ?? '');
        if (!$convId || !$docUrl) $er2('conversation_id and document_url required.', 422);
        $conv = $_convSvc->getConversation($convId);
        if (!$conv) $er2('Conversation not found.', 404);
        $sender = ($conv['channel'] ?? 'support') === 'accounts' ? 'accounts' : 'support';

        svc('notify')->sendDocument($sender, $conv['phone'], $docUrl, $fname, $cap, 'wa_send_document');
        $_convSvc->storeMessage($convId, [
            'direction' => 'out', 'role' => 'agent',
            'body' => $cap ?: "[Document: {$fname}]",
            'media_type' => 'document', 'media_url' => $docUrl,
            'agent_name' => $retailer['name'] ?? 'Staff',
            'event_key' => 'wa_send_document', 'sent_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $ok2(['sent' => true, 'filename' => $fname]);
    }

    // ── wa_mark_read: Mark thread as read ────────────────────────────
    if ($act === 'wa_mark_read' && $met === 'POST') {
        $convId = (int)($body['conversation_id'] ?? $body['conv_id'] ?? 0);
        if (!$convId) $er2('conversation_id required.', 422);
        $_convSvc->markRead($convId);
        $ok2(['marked_read' => true]);
    }

    // ── wa_crm_client_info: Quick CRM lookup for chat header ─────────
    if ($act === 'wa_crm_client_info') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        if (!$clientId) $er2('client_id required.', 422);
        $info = ['balance' => 0, 'services' => '', 'status' => ''];
        try {
            $cl = svc('crm')->get("clients/{$clientId}");
            if ($cl) {
                $info['balance'] = (float)($cl['accountBalance'] ?? 0);
                $info['status'] = ($cl['isLead'] ?? false) ? 'Lead' : 'Active';
                // Get services
                $svcs = svc('crm')->get("clients/{$clientId}/services") ?? [];
                $plans = [];
                foreach ($svcs as $s) {
                    $pn = $s['name'] ?? $s['servicePlanName'] ?? '';
                    $st = $s['status'] ?? 0;
                    $stLabel = [0 => 'Prep', 1 => 'Active', 2 => 'Suspended', 3 => 'Ended', 5 => 'Quoted'];
                    if ($pn) $plans[] = $pn . ' (' . ($stLabel[$st] ?? '?') . ')';
                }
                $info['services'] = implode(', ', array_slice($plans, 0, 3));
                if (count($plans) > 3) $info['services'] .= ' +' . (count($plans) - 3);
            }
        } catch (Throwable $e) {}
        $ok2($info);
    }

    // ── wa_link_client: Link conversation to CRM client ─────────────
    if ($act === 'wa_link_client' && $met === 'POST') {
        $convId   = (int)($body['conversation_id'] ?? 0);
        $clientId = (int)($body['crm_client_id'] ?? 0);
        if (!$convId || !$clientId) $er2('conversation_id and crm_client_id required.', 422);
        $clientName = '';
        try {
            $cl = svc('crm')->get("clients/{$clientId}");
            $clientName = trim(($cl['firstName'] ?? '') . ' ' . ($cl['lastName'] ?? '')) ?: ($cl['companyName'] ?? "#{$clientId}");
        } catch (Throwable $e) { $clientName = "Client #{$clientId}"; }
        $_convSvc->linkToCrm($convId, $clientId, $clientName);
        $ok2(['linked' => true, 'crm_client_id' => $clientId, 'crm_client_name' => $clientName]);
    }

    // ── wa_auto_link: Batch auto-link unlinked conversations ────────
    if ($act === 'wa_auto_link' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $idx = $store->load('client_search_index.json') ?? [];
        $phoneMap = [];
        foreach ($idx as $c) {
            $ph = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
            if (strlen($ph) >= 9) $phoneMap[substr($ph, -9)] = ['id' => (int)$c['id'], 'name' => $c['name'] ?? ''];
        }
        $unlinked = $store->getPdo()
            ->query("SELECT id, phone FROM wa_conversations WHERE crm_client_id IS NULL OR crm_client_id = 0")
            ->fetchAll(PDO::FETCH_ASSOC);
        $linked = 0; $unmatched = 0;
        foreach ($unlinked as $row) {
            $tail = substr(preg_replace('/[^0-9]/', '', $row['phone']), -9);
            $m = $phoneMap[$tail] ?? null;
            if ($m) { $_convSvc->linkToCrm((int)$row['id'], $m['id'], $m['name']); $linked++; }
            else { $unmatched++; }
        }
        $ok2(['linked' => $linked, 'unmatched' => $unmatched]);
    }

    // ── wa_close_thread: Close/archive conversation ─────────────────
    if ($act === 'wa_close_thread' && $met === 'POST') {
        $convId = (int)($body['conversation_id'] ?? $body['conv_id'] ?? 0);
        if (!$convId) $er2('conversation_id required.', 422);
        $store->getPdo()->prepare("UPDATE wa_conversations SET status = 'closed', updated_at = datetime('now') WHERE id = ?")
              ->execute([$convId]);
        $ok2(['closed' => true]);
    }

    // ── wa_categorise: Set conversation category ────────────────────
    if ($act === 'wa_categorise' && $met === 'POST') {
        $convId = (int)($body['conversation_id'] ?? 0);
        $cat    = trim($body['category'] ?? '');
        $allow  = ['billing','technical','onboarding','general','complaint','marketing'];
        if (!$convId || !$cat) $er2('conversation_id and category required.', 422);
        if (!in_array($cat, $allow, true)) $er2('Invalid category.', 422);
        $_convSvc->categorise($convId, $cat);
        $ok2(['categorised' => true, 'category' => $cat]);
    }

    // ── wa_quick_action: Create KYC lead or ticket from thread ──────
    if ($act === 'wa_quick_action' && $met === 'POST') {
        $convId = (int)($body['conversation_id'] ?? 0);
        $action = trim($body['action'] ?? '');
        if (!$convId || !$action) $er2('conversation_id and action required.', 422);
        $conv = $_convSvc->getConversation($convId);
        if (!$conv) $er2('Conversation not found.', 404);

        if ($action === 'create_kyc_lead') {
            $newLead = $store->appendWithId('leads.json', [
                'customer_name' => $conv['display_name'] ?: 'Unknown',
                'phone'         => $conv['phone'],
                'source'        => 'whatsapp',
                'status'        => 'open',
                'notes'         => 'From WA inbox #' . $convId,
                'retailer_id'   => $rid,
                'retailer_name' => $retailer['name'] ?? '',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            $_convSvc->categorise($convId, 'onboarding');
            $ok2(['lead_id' => $newLead['id'], 'action' => 'create_kyc_lead']);
        } elseif ($action === 'create_ticket') {
            $pdo = $store->getPdo();
            $pdo->prepare("INSERT INTO tickets (phone, customer_name, issue, source, status, created_at) VALUES (?, ?, ?, 'whatsapp', 'open', datetime('now'))")
                ->execute([$conv['phone'], $conv['display_name'] ?? 'Unknown', 'From WA inbox #' . $convId]);
            $_convSvc->categorise($convId, 'technical');
            $ok2(['ticket_id' => (int)$pdo->lastInsertId(), 'action' => 'create_ticket']);
        } else {
            $er2('Unknown action.', 422);
        }
    }

    // ── wa_sync_status: sync state with freshness flag ───────────────
    if ($act === 'wa_sync_status') {
        $sf    = $GLOBALS['dataDir'] . '/wa_sync_state.json';
        $state = file_exists($sf) ? (json_decode(file_get_contents($sf), true) ?: []) : [];
        $lastAt = $state['last_sync_at'] ?? null;
        $state['is_fresh'] = $lastAt && (time() - strtotime($lastAt)) < 120; // fresh if < 2 min
        $ok2($state);
    }

    // ── wa_trigger_sync: non-admin-gated quick sync (throttled to 1/min) ──
    if ($act === 'wa_trigger_sync') {
        $sf    = $GLOBALS['dataDir'] . '/wa_sync_state.json';
        $state = file_exists($sf) ? (json_decode(file_get_contents($sf), true) ?: []) : [];
        $lastAt = $state['last_sync_at'] ?? null;
        // Throttle: don't trigger if synced within last 30 seconds
        if ($lastAt && (time() - strtotime($lastAt)) < 30) {
            $ok2(['triggered' => false, 'reason' => 'throttled', 'last_sync_at' => $lastAt]);
        } else {
            // Fire background sync via cron script (non-blocking)
            $cronScript = dirname(__DIR__, 2) . '/cron_wa_sync.php';
            if (file_exists($cronScript) && function_exists('exec')) {
                @exec('php ' . escapeshellarg($cronScript) . ' > /dev/null 2>&1 &');
            }
            $ok2(['triggered' => true, 'last_sync_at' => $lastAt]);
        }
    }

    // ── wa_run_sync: Manual sync trigger (runs one cycle inline) ────
    if ($act === 'wa_run_sync' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        set_time_limit(60);

        $dd = $GLOBALS['dataDir'];
        $cf = $config;
        $feedUrl    = $cf['wa_feed_url']    ?? 'https://wa.dishnetafrica.com/message_feed.php';
        $feedSecret = $cf['wa_feed_secret'] ?? 'dnet_wa_feed_2026_x9k4';
        $batchSize  = (int)($cf['wa_sync_batch_size'] ?? 200);
        $sessionMap = [
            ($cf['wa_session_support']  ?? '7cc8d42a-fe45-4a84-80dc-0ae4b0f42cfc') => 'support',
            ($cf['wa_session_accounts'] ?? '1d327e8a-de0c-4888-8f81-21675c70ef1e') => 'accounts',
        ];

        // Load state
        $stateFile = $dd . '/wa_sync_state.json';
        $state = file_exists($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
        $lastPkId = (int)($state['last_synced_pkId'] ?? 0);
        $totalSynced = (int)($state['total_synced'] ?? 0);

        // Fetch from feed
        $url = $feedUrl . '?secret=' . urlencode($feedSecret) . '&since=' . $lastPkId . '&limit=' . $batchSize;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) $er2('Feed curl error: ' . $curlErr, 502);
        if ($httpCode !== 200) $er2('Feed HTTP ' . $httpCode . ': ' . substr($resp, 0, 200), 502);

        $data = json_decode($resp, true);
        if (!$data || empty($data['ok'])) $er2('Feed invalid response: ' . substr($resp, 0, 200), 502);

        $rows = $data['messages'] ?? [];
        if (empty($rows)) {
            $state['last_sync_at'] = date('Y-m-d H:i:s');
            file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
            $ok2(['stored' => 0, 'skipped' => 0, 'message' => 'No new messages']);
        }

        // CRM phone map for auto-link
        $cidx = $store->load('client_search_index.json') ?? [];
        $phoneMap = [];
        foreach ($cidx as $c) {
            $ph = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
            if (strlen($ph) >= 9) $phoneMap[substr($ph, -9)] = ['id' => (int)$c['id'], 'name' => $c['name'] ?? ''];
        }

        // Process
        $stored = 0; $skipped = 0; $errors = 0; $linked = 0; $maxPkId = $lastPkId; $lastErr = '';
        foreach ($rows as $row) {
            $pkId = (int)$row['pkId'];
            if ($pkId > $maxPkId) $maxPkId = $pkId;
            try {
                $channel = $sessionMap[$row['sessionId']] ?? null;
                if (!$channel) { $skipped++; continue; }
                $phone = preg_replace('/[^0-9]/', '', explode('@', $row['remoteJid'])[0]);
                if (empty($phone) || strlen($phone) < 7) { $skipped++; continue; }

                // Double-decode Baileys JSON
                $msgRaw = $row['message'] ?? '{}';
                $msgJson = json_decode($msgRaw, true);
                if (is_string($msgJson)) $msgJson = json_decode($msgJson, true);
                if (!is_array($msgJson)) $msgJson = [];

                // Parse
                $body = $msgJson['conversation']
                    ?? $msgJson['extendedTextMessage']['text']
                    ?? $msgJson['imageMessage']['caption']
                    ?? $msgJson['documentMessage']['caption']
                    ?? $msgJson['videoMessage']['caption']
                    ?? '';
                $mediaType = null;
                if (isset($msgJson['imageMessage']))    $mediaType = 'image';
                if (isset($msgJson['documentMessage'])) $mediaType = 'document';
                if (isset($msgJson['audioMessage']))    $mediaType = 'audio';
                if (isset($msgJson['videoMessage']))    $mediaType = 'video';
                if (isset($msgJson['stickerMessage']))  $mediaType = 'sticker';

                if (empty($body) && !$mediaType) { $skipped++; continue; }

                $fromMe = (bool)$row['fromMe'];
                $ts = (int)$row['messageTimestamp'];
                $sentAt = $ts > 0 ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');

                $conv = $_convSvc->ensureConversation($phone, $channel, $row['pushName'] ?? null, 'wa_sync');
                $isNew = (strtotime($conv['created_at'] ?? '') >= time() - 5);

                $msgId = $_convSvc->storeMessage($conv['id'], [
                    'direction' => $fromMe ? 'out' : 'in',
                    'role' => $fromMe ? 'agent' : 'customer',
                    'body' => $body ?: ('[' . ($mediaType ?? 'media') . ']'),
                    'media_type' => $mediaType,
                    'wa_message_id' => $row['id'],
                    'agent_name' => $fromMe ? 'DishNet' : null,
                    'sent_at' => $sentAt,
                ]);
                if ($msgId === null) { $skipped++; continue; }
                $stored++;

                if ($isNew && empty($conv['crm_client_id'])) {
                    $tail = substr($phone, -9);
                    $m = $phoneMap[$tail] ?? null;
                    if ($m) { $_convSvc->linkToCrm($conv['id'], $m['id'], $m['name']); $linked++; }
                }
            } catch (Throwable $e) { $errors++; $lastErr = $e->getMessage(); }
        }

        // Save state
        $state['last_synced_pkId'] = $maxPkId;
        $state['total_synced'] = $totalSynced + $stored;
        $state['last_sync_at'] = date('Y-m-d H:i:s');
        $state['last_batch_size'] = count($rows);
        $state['last_stored'] = $stored;
        $state['last_skipped'] = $skipped;
        $state['last_errors'] = $errors;
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

        $ok2(['stored' => $stored, 'skipped' => $skipped, 'errors' => $errors, 'linked' => $linked, 'batch' => count($rows), 'maxPkId' => $maxPkId, 'last_error' => $lastErr]);
    }

    // ── wa_send_quote_pdf: Fetch quote PDF from UCRM, send via WhatsApp ─
    if ($act === 'wa_send_quote_pdf' && $met === 'POST') {
        $appId    = (int)($body['application_id'] ?? 0);
        $quoteId  = (int)($body['crm_quote_id'] ?? 0);
        $sendTo   = trim($body['phone'] ?? '');
        $ccAdmin  = (bool)($body['cc_admin'] ?? true);

        // Load application to get details
        $app = null;
        $crmClientId = 0;
        if ($appId) {
            $app = $store->findOne('kyc_applications.json', 'id', $appId);
            if ($app && !$quoteId) $quoteId = (int)($app['quote_id'] ?? $app['crm_quote_id'] ?? 0);
            if ($app && !$sendTo) $sendTo = preg_replace('/[^0-9]/', '', $app['mobile'] ?? $app['phone'] ?? '');
            if ($app) $crmClientId = (int)($app['crm_client_id'] ?? 0);
        }

        $crm = svc('crm');

        // If no local quote_id, search UCRM for quotes belonging to this client
        $quoteLookupDebug = ['crm_client_id' => $crmClientId];
        if (!$quoteId && $crmClientId) {
            // Try client-specific endpoint
            $clientQuotes = $crm->get("clients/{$crmClientId}/quotes");
            $quoteLookupDebug['clients_endpoint'] = $clientQuotes !== null
                ? (is_array($clientQuotes) ? count($clientQuotes) . ' quotes' : gettype($clientQuotes))
                : 'null (API error: ' . json_encode($crm->getLastError()) . ')';

            if (empty($clientQuotes) || !is_array($clientQuotes)) {
                // Fallback: list all billing quotes (may be large)
                $allQuotes = $crm->get("billing/quotes");
                $quoteLookupDebug['billing_all'] = $allQuotes !== null
                    ? (is_array($allQuotes) ? count($allQuotes) . ' total quotes' : gettype($allQuotes))
                    : 'null';

                // Filter by client ID locally
                if (is_array($allQuotes)) {
                    $clientQuotes = array_filter($allQuotes, function($q) use ($crmClientId) {
                        return (int)($q['clientId'] ?? 0) === $crmClientId;
                    });
                    $clientQuotes = array_values($clientQuotes);
                    $quoteLookupDebug['filtered_for_client'] = count($clientQuotes) . ' quotes';
                }
            }

            if (!empty($clientQuotes) && is_array($clientQuotes)) {
                usort($clientQuotes, function($a, $b) {
                    return strcmp($b['createdDate'] ?? '', $a['createdDate'] ?? '');
                });
                $quoteId = (int)($clientQuotes[0]['id'] ?? 0);
                $quoteLookupDebug['found_quote_id'] = $quoteId;
                $quoteLookupDebug['quote_number'] = $clientQuotes[0]['number'] ?? '?';
                if ($quoteId && $appId) {
                    $store->updateOne('kyc_applications.json', 'id', $appId, ['quote_id' => $quoteId]);
                }
            }
        }

        if (!$quoteId) {
            $er2('No quote found. Debug: ' . json_encode($quoteLookupDebug), 422);
        }
        if (!$sendTo) $er2('No phone number to send to.', 422);

        // Fetch quote details from UCRM (may fail with plugin app key — that's OK)
        $quote = null;
        try {
            $quote = $crm->get("billing/quotes/{$quoteId}");
        } catch (Throwable $e) {
            // Plugin app key may not have quote read permission — continue
        }

        $quoteNum = $quote['number'] ?? ($app['quote_ref'] ?? "Q-{$quoteId}");
        $amount   = $quote['totalUntaxed'] ?? $quote['total'] ?? ($app['total_amount'] ?? '');

        // Fetch PDF via API — this often works even when GET details doesn't
        $pdfRaw = $crm->getRawContent("quotes/{$quoteId}/pdf");

        // If API PDF fails, try the proforma invoice PDF endpoint
        if (!$pdfRaw) {
            $pdfRaw = $crm->getRawContent("quotes/{$quoteId}/pdf");
        }

        if (!$pdfRaw) {
            // Last resort: generate a text-based WhatsApp message instead of PDF
            $notify = svc('notify');
            $custName = $app ? trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? '')) : '';
            $items = $quote['items'] ?? [];
            $itemLines = '';
            foreach ($items as $qi) {
                $itemLines .= "\n  • " . ($qi['label'] ?? 'Item') . ' — $' . number_format((float)($qi['total'] ?? $qi['price'] ?? 0), 2);
            }

            $textQuote = "📄 *Quote #{$quoteNum}*\n\n"
                . "Dear {$custName},\n\n"
                . "Here is your DishNet quotation:\n"
                . $itemLines . "\n\n"
                . "💰 *Total: \${$amount}*\n\n"
                . "Valid for 30 days.\n"
                . "To proceed, contact your agent or call +211 921 443 006.\n\n"
                . "— DishNet Africa";

            $notify->sendVia('support', $sendTo, $textQuote, 'ops_quote_text');
            $sentTo = [$sendTo];

            $adminPhone = trim($config['whatsapp_admin_phone'] ?? '');
            if ($ccAdmin && $adminPhone && $adminPhone !== $sendTo) {
                $notify->sendVia('support', $adminPhone, "📋 Quote #{$quoteNum} sent to {$custName} ({$sendTo})\nAmount: \${$amount}", 'ops_quote_admin_cc');
                $sentTo[] = $adminPhone;
            }

            $ok2([
                'sent' => true,
                'quote_id' => $quoteId,
                'quote_number' => $quoteNum,
                'method' => 'text_message',
                'note' => 'PDF not available via API — sent as text quote instead',
                'sent_to' => $sentTo,
            ]);
        }

        // Save to temp dir with HMAC token
        $dd = $GLOBALS['dataDir'];
        $tempDir = $dd . '/temp_pdf';
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

        $pdfFile  = "quote_{$quoteId}_" . substr(md5(uniqid()), 0, 8) . '.pdf';
        $pdfPath  = $tempDir . '/' . $pdfFile;
        $pdfToken = hash_hmac('sha256', $pdfFile, ($config['webhook_secret'] ?? 'dishnet') . date('Ymd'));
        file_put_contents($pdfPath, base64_decode($pdfRaw));
        file_put_contents($pdfPath . '.meta', json_encode([
            'token' => $pdfToken, 'created' => time(), 'quote' => $quoteNum,
        ]));

        $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
        $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                 . '?page=api&action=serve_temp_pdf'
                 . '&file=' . urlencode($pdfFile)
                 . '&token=' . urlencode($pdfToken);

        $notify = svc('notify');
        $custName = $app ? trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? '')) : '';
        $caption = "Quote #{$quoteNum}" . ($amount ? " — \${$amount}" : '') . "\n— DishNet Africa";

        // Send to customer
        $notify->sendDocument('support', $sendTo, $pdfUrl, "Quote-{$quoteNum}.pdf", $caption, 'ops_quote_pdf_manual');
        $sentTo = [$sendTo];

        // CC to admin
        $adminPhone = trim($config['whatsapp_admin_phone'] ?? '');
        if ($ccAdmin && $adminPhone && $adminPhone !== $sendTo) {
            $adminCaption = "📋 Quote #{$quoteNum} sent to {$custName} ({$sendTo})\nAmount: \${$amount}\nSent by: " . ($retailer['name'] ?? 'Admin');
            $notify->sendDocument('support', $adminPhone, $pdfUrl, "Quote-{$quoteNum}.pdf", $adminCaption, 'ops_quote_pdf_admin_cc');
            $sentTo[] = $adminPhone;
        }

        $ok2([
            'sent' => true,
            'quote_id' => $quoteId,
            'quote_number' => $quoteNum,
            'pdf_url' => $pdfUrl,
            'sent_to' => $sentTo,
        ], "Quote PDF sent via WhatsApp");
    }

    // ── wa_sync_reset: Reset sync cursor to re-pull all messages ───
    if ($act === 'wa_sync_reset' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $sf = $GLOBALS['dataDir'] . '/wa_sync_state.json';
        file_put_contents($sf, json_encode(['last_synced_pkId' => 0, 'total_synced' => 0, 'reset_at' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT));
        $ok2(['reset' => true, 'message' => 'Sync cursor reset to 0. Click Sync Now to re-pull.']);
    }

    // ── wa_sync_test: Diagnostic — test feed connection ────────────
    if ($act === 'wa_sync_test') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $diag = [];
        $diag['php_version'] = PHP_VERSION;
        $diag['curl_loaded'] = extension_loaded('curl');
        $diag['pdo_drivers'] = PDO::getAvailableDrivers();

        $feedUrl    = $config['wa_feed_url']    ?? 'https://wa.dishnetafrica.com/message_feed.php';
        $feedSecret = $config['wa_feed_secret'] ?? 'dnet_wa_feed_2026_x9k4';
        $testUrl = $feedUrl . '?secret=' . urlencode($feedSecret) . '&since=0&limit=1';
        $diag['feed_url'] = $feedUrl;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $testUrl, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            $diag['connected'] = false;
            $diag['error'] = 'curl error: ' . $err;
        } elseif ($code !== 200) {
            $diag['connected'] = false;
            $diag['error'] = 'HTTP ' . $code . ': ' . substr($resp, 0, 200);
        } else {
            $json = json_decode($resp, true);
            if ($json && !empty($json['ok'])) {
                $diag['connected'] = true;
                $diag['status'] = 'OK — feed API reachable, messages available';
                $diag['sample'] = $json;
            } else {
                $diag['connected'] = false;
                $diag['error'] = 'Invalid feed response: ' . substr($resp, 0, 200);
            }
        }

        // Also show sync state
        $sf = $GLOBALS['dataDir'] . '/wa_sync_state.json';
        $diag['sync_state'] = file_exists($sf) ? (json_decode(file_get_contents($sf), true) ?: []) : [];

        $ok2($diag);
    }

    // ═══════════════════════════════════════════════════════════════
    // LEGACY WaBot endpoints (backward compat for old UI)
    // ═══════════════════════════════════════════════════════════════
    if ($act === 'wa_reply' && $met === 'POST') {
        $convId = (int)($body['conv_id'] ?? 0); $text = trim($body['text'] ?? '');
        if (!$convId || !$text) $er2('conv_id and text required.', 422);
        if (svc('waBot')->staffReply($convId, $text, $retailer['name'] ?? 'Staff')) $ok2(['sent' => true]);
        else $er2('Not found.', 404);
    }
    if ($act === 'wa_close_conversation' && $met === 'POST') {
        svc('waBot')->closeConversation((int)($body['conv_id'] ?? 0), $retailer['name'] ?? 'Staff');
        $ok2(['closed' => true]);
    }
    if ($act === 'wa_tickets') { $ok2(['tickets' => svc('waBot')->getAllTickets()]); }
    if ($act === 'wa_update_ticket' && $met === 'POST') {
        $tid = (int)($body['ticket_id'] ?? 0);
        $upd = array_intersect_key($body, array_flip(['status','priority','assigned_to','notes']));
        if ($tid && $upd) svc('waBot')->updateTicket($tid, $upd);
        $ok2(['updated' => true]);
    }

    // ── wa_quick_replies: Return configurable quick reply presets per channel ─
    if ($act === 'wa_quick_replies') {
        $ch = trim($_GET['channel'] ?? 'support');
        $presets = [
            'support' => [
                ['label' => 'Speed test',        'text' => 'Please run a speed test at fast.com and send me a screenshot'],
                ['label' => 'Restart router',    'text' => 'Please restart your router — unplug it, wait 30 seconds, plug back in'],
                ['label' => 'Team will call',    'text' => 'Our support team will call you within the hour'],
                ['label' => 'Reboot dish',       'text' => 'Please reboot the Starlink dish from the Starlink app'],
                ['label' => 'Check cables',      'text' => 'Please check all cable connections and power supply'],
                ['label' => 'Checking now',      'text' => 'Please hold on, I am checking your account now'],
                ['label' => 'Site visit',        'text' => 'I will arrange a technician site visit for you. What is your location?'],
                ['label' => 'Create ticket',     'text' => '__ACTION__:create_ticket'],
            ],
            'accounts' => [
                ['label' => 'Payment received',  'text' => 'Your payment has been received and is being processed now'],
                ['label' => 'Allow 24hrs',       'text' => 'Please allow up to 24 hours for your payment to reflect on the account'],
                ['label' => 'Send receipt',      'text' => 'Please send a photo of your payment receipt to this number'],
                ['label' => 'Balance cleared',   'text' => 'Your account balance has been cleared ✅'],
                ['label' => 'Invoice sent',      'text' => 'I have sent your invoice to this number now'],
                ['label' => 'Checking account',  'text' => 'Let me check your account — please hold on a moment'],
                ['label' => 'Create ticket',     'text' => '__ACTION__:create_ticket'],
            ],
        ];
        $ok2(['replies' => $presets[$ch] ?? $presets['support']]);
    }

    // ── wa_customer_360: Full CRM context for a client ────────────────────────
    if ($act === 'wa_customer_360') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        if (!$clientId) $er2('client_id required.', 422);
        $result = [
            'client_id' => $clientId,
            'balance'   => 0,
            'status'    => '',
            'name'      => '',
            'email'     => '',
            'phone'     => '',
            'services'  => [],
            'payments'  => [],
            'tickets'   => [],
        ];
        try {
            // Client info
            $cl = svc('crm')->get("clients/{$clientId}");
            if ($cl) {
                $result['name']    = trim(($cl['firstName'] ?? '') . ' ' . ($cl['lastName'] ?? '')) ?: ($cl['companyName'] ?? '');
                // Email: check contacts array first, then top-level
                $result['email']   = '';
                if (!empty($cl['contacts']) && is_array($cl['contacts'])) {
                    foreach ($cl['contacts'] as $c) {
                        if (!empty($c['email'])) { $result['email'] = $c['email']; break; }
                    }
                }
                if (!$result['email']) $result['email'] = $cl['email'] ?? '';
                // Phone same pattern
                $result['phone']   = '';
                if (!empty($cl['contacts']) && is_array($cl['contacts'])) {
                    foreach ($cl['contacts'] as $c) {
                        if (!empty($c['phone'])) { $result['phone'] = $c['phone']; break; }
                    }
                }
                if (!$result['phone']) $result['phone'] = $cl['phone'] ?? '';
                $result['balance'] = (float)($cl['accountBalance'] ?? 0);
                $result['status']  = ($cl['isLead'] ?? false) ? 'Lead' : (($cl['isArchived'] ?? false) ? 'Archived' : 'Active');
            }
        } catch (Throwable $e) {}
        try {
            // Services — UCRM primary field is servicePlanName (confirmed from customer_lookup.php)
            // Status integers: 0=Prep, 1=Active, 2=Ended, 4=Quoted, 5=Suspended
            $svcs = svc('crm')->get("clients/{$clientId}/services") ?? [];
            $stLabel = [0 => 'Prep', 1 => 'Active', 2 => 'Ended', 4 => 'Quoted', 5 => 'Suspended'];
            foreach ($svcs as $s) {
                // servicePlanName is the primary field in UCRM; name may be a custom override
                $svcName = $s['servicePlanName'] ?? $s['name'] ?? ($s['servicePlan']['name'] ?? 'Service');
                $svcStatus = (int)($s['status'] ?? 0);
                $result['services'][] = [
                    'name'        => $svcName,
                    'status'      => $stLabel[$svcStatus] ?? 'Other',
                    'status_int'  => $svcStatus,
                    'price'       => (float)($s['price'] ?? 0),
                    'active_from' => substr($s['activeFrom'] ?? '', 0, 10),
                    'active_to'   => substr($s['activeTo']   ?? '', 0, 10),
                ];
            }
        } catch (Throwable $e) {}
        try {
            // Payments — correct UCRM endpoint: payments?clientId=X (NOT clients/{id}/payments)
            $pays = svc('crm')->get("payments?clientId={$clientId}&limit=5") ?? [];
            // Also try with direction sort
            if (empty($pays)) {
                $pays = svc('crm')->get("payments?clientId={$clientId}") ?? [];
            }
            foreach (array_slice($pays, 0, 5) as $p) {
                $result['payments'][] = [
                    'date'   => substr($p['createdDate'] ?? $p['created_at'] ?? '', 0, 10),
                    'amount' => (float)($p['amount'] ?? 0),
                    'method' => $p['paymentType']['name'] ?? ($p['methodName'] ?? 'Cash'),
                    'note'   => $p['note'] ?? '',
                ];
            }
        } catch (Throwable $e) {}
        try {
            // Tickets — match by crm_client_id first (most reliable), fallback to phone
            $pdo = $store->getPdo();
            $rows = $pdo->prepare(
                "SELECT id, subject, status_label as status, created_at FROM tickets
                 WHERE (crm_client_id = ? OR phone = ?)
                   AND status_label NOT IN ('closed','solved','cancelled')
                 ORDER BY created_at DESC LIMIT 5"
            );
            $rows->execute([$clientId, $result['phone']]);
            $result['tickets'] = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // Fallback: simpler query if status_label column differs
            try {
                $rows2 = $store->getPdo()->prepare(
                    "SELECT id, subject, status, created_at FROM tickets
                     WHERE crm_client_id = ? ORDER BY created_at DESC LIMIT 5"
                );
                $rows2->execute([$clientId]);
                $result['tickets'] = $rows2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) {}
        }
        $ok2($result);
    }

    // ── wa_upload_media: Accept file upload, save to data/uploads/wa/ ─────────
    if ($act === 'wa_upload_media' && $met === 'POST') {
        if (empty($_FILES['file'])) $er2('No file uploaded.', 422);
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) $er2('Upload error: ' . $file['error'], 422);

        $maxBytes = 16 * 1024 * 1024; // 16MB WhatsApp limit
        if ($file['size'] > $maxBytes) $er2('File too large (max 16MB).', 413);

        $allowedExt = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xlsx','mp3','ogg','mp4'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) $er2('File type not allowed.', 415);

        $uploadDir = $GLOBALS['dataDir'] . '/uploads/wa';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
        $filename = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_' . $safeName;
        $dest = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) $er2('Failed to save file.', 500);

        // Build public URL (served via page=wa_media)
        $baseUrl = rtrim((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . strtok($_SERVER['REQUEST_URI'] ?? '/public.php', '?'), '/');
        $publicUrl = $baseUrl . '/public.php?page=wa_media&f=' . urlencode($filename);

        $ok2(['url' => $publicUrl, 'filename' => $filename, 'ext' => $ext, 'size' => $file['size']]);
    }

    // ── wa_send_image: Send image via WhatsApp ───────────────────────────────
    if ($act === 'wa_send_image' && $met === 'POST') {
        $convId  = (int)($body['conversation_id'] ?? 0);
        $imgUrl  = trim($body['image_url'] ?? '');
        $caption = trim($body['caption'] ?? '');
        if (!$convId || !$imgUrl) $er2('conversation_id and image_url required.', 422);
        $conv = $_convSvc->getConversation($convId);
        if (!$conv) $er2('Conversation not found.', 404);

        $sender  = ($conv['channel'] ?? 'support') === 'accounts' ? 'accounts' : 'support';
        $staffNm = $retailer['name'] ?? 'Staff';

        svc('notify')->sendImage($sender, $conv['phone'], $imgUrl, $caption, 'wa_image_send');
        $_convSvc->storeMessage($convId, [
            'direction'  => 'out', 'role' => 'agent',
            'body'       => $caption ?: '[Image]',
            'media_type' => 'image', 'media_url' => $imgUrl,
            'agent_name' => $staffNm, 'event_key' => 'wa_image_send',
            'sent_at'    => date('Y-m-d H:i:s'),
        ]);
        try {
            $store->getPdo()->prepare(
                "UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
            )->execute([$convId]);
        } catch (Throwable $e) {}
        $ok2(['sent' => true, 'image_url' => $imgUrl]);
    }

    // ── wa_send_media: Generic media send (doc/image/video/audio) ────────────
    if ($act === 'wa_send_media' && $met === 'POST') {
        $convId    = (int)($body['conversation_id'] ?? 0);
        $mediaUrl  = trim($body['media_url'] ?? '');
        $mediaType = trim($body['media_type'] ?? 'document'); // image|document|audio|video
        $filename  = trim($body['filename'] ?? 'file');
        $caption   = trim($body['caption'] ?? '');
        if (!$convId || !$mediaUrl) $er2('conversation_id and media_url required.', 422);
        $conv = $_convSvc->getConversation($convId);
        if (!$conv) $er2('Conversation not found.', 404);

        $sender  = ($conv['channel'] ?? 'support') === 'accounts' ? 'accounts' : 'support';
        $staffNm = $retailer['name'] ?? 'Staff';

        if ($mediaType === 'image') {
            svc('notify')->sendImage($sender, $conv['phone'], $mediaUrl, $caption, 'wa_media_send');
        } else {
            svc('notify')->sendDocument($sender, $conv['phone'], $mediaUrl, $filename, $caption, 'wa_media_send');
        }

        $bodyStr = $caption ?: ('[' . ucfirst($mediaType) . ': ' . $filename . ']');
        $_convSvc->storeMessage($convId, [
            'direction'  => 'out', 'role' => 'agent',
            'body'       => $bodyStr,
            'media_type' => $mediaType, 'media_url' => $mediaUrl,
            'agent_name' => $staffNm, 'event_key' => 'wa_media_send',
            'sent_at'    => date('Y-m-d H:i:s'),
        ]);
        try {
            $store->getPdo()->prepare(
                "UPDATE wa_conversations SET state = 'human_active', last_human_reply_at = datetime('now'), updated_at = datetime('now') WHERE id = ?"
            )->execute([$convId]);
        } catch (Throwable $e) {}
        $ok2(['sent' => true, 'media_type' => $mediaType]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // FTTH Installation Command Center — install_* endpoints
    // (previously only in api/index.php, now also available via ?page=api)
    // ═══════════════════════════════════════════════════════════════════════════

    $isLeaderOrAdmin2 = ($me2['is_admin'] ?? false) || ($me2['role'] ?? '') === 'support_leader';
    $isSupportAny2    = $isLeaderOrAdmin2 || in_array($me2['role'] ?? '', ['support', 'support_engineer']);
