<?php
// Tab: whatsapp
// Extracted from public.php on 2026-03-15

// ══════════════════════════════════════════════════════════════════════════════
// WHATSAPP TAB  v2.0  —  Config · Event Map + CRUD · Message Log · UCRM Webhook
// ══════════════════════════════════════════════════════════════════════════════

// ── Handle CRUD actions for message templates ─────────────────────────────
$waTplFile = 'wa_templates.json';
$waTpls    = $store->load($waTplFile) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($tab === 'whatsapp' || $tab === 'wa_leads')) {
    $waAct = $_POST['wa_action'] ?? '';

    // Save single template
    if ($waAct === 'wa_save_template') {
        $key     = trim($_POST['tpl_key'] ?? '');
        $body    = trim($_POST['tpl_body'] ?? '');
        $sender  = in_array($_POST['tpl_sender']??'', ['support','accounts']) ? $_POST['tpl_sender'] : 'support';
        $enabled = isset($_POST['tpl_enabled']) ? 1 : 0;
        if ($key && $body) {
            $waTpls[$key] = ['body'=>$body,'sender'=>$sender,'enabled'=>$enabled,'updated_at'=>date('Y-m-d H:i:s')];
            $store->save($waTplFile, $waTpls);
            flash("Template '{$key}' saved.", 'success');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=events');
    }

    // Reset template to code default
    if ($waAct === 'wa_reset_template') {
        $key = trim($_POST['tpl_key'] ?? '');
        if ($key && isset($waTpls[$key])) {
            unset($waTpls[$key]);
            $store->save($waTplFile, $waTpls);
            flash("Template '{$key}' reset to default.", 'success');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=events');
    }

    // Test send
    if ($waAct === 'wa_test_send') {
        $key   = trim($_POST['tpl_key'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', trim($_POST['test_phone'] ?? $config['whatsapp_admin_phone'] ?? ''));
        $body  = trim($_POST['tpl_body'] ?? '');
        $sndr  = in_array($_POST['tpl_sender']??'', ['support','accounts']) ? $_POST['tpl_sender'] : 'support';
        if ($phone && $body) {
            $testMsg = str_replace(
                ['{{agent_name}}','{{customer_name}}','{{amount}}','{{crm_id}}','{{username}}','{{invoice_number}}','{{due_date}}','{{service_name}}','{{reason}}','{{ref}}'],
                ['Test Agent','John Doe','100.00','#1234','STAR000001','INV-001',date('Y-m-d'),'Starlink','Test reason','REF-001'],
                $body
            );
            $notify->sendVia($sndr, $phone, $testMsg, $key);
            flash("Test message sent to {$phone}.", 'success');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=events');
    }

    // ── Google Drive Backup actions ──────────────────────────────────────
    if ($waAct === 'gdrive_save_config') {
        require_once dirname(__DIR__, 2) . '/lib/GoogleDriveBackup.php';
        $gd   = new GoogleDriveBackup($dataDir);
        $conf = $gd->getConfig();
        $conf['client_id']       = trim($_POST['gdrive_client_id'] ?? '');
        $conf['client_secret']   = trim($_POST['gdrive_client_secret'] ?? '');
        $conf['folder_name']     = trim($_POST['gdrive_folder_name'] ?? '') ?: 'DishNet-Backups';
        $conf['retention_count'] = max(1, min(30, (int)($_POST['gdrive_retention'] ?? 7)));
        $conf['schedule']        = in_array($_POST['gdrive_schedule'] ?? '', ['daily','twice_daily','weekly']) ? $_POST['gdrive_schedule'] : 'daily';
        $conf['enabled']         = isset($_POST['gdrive_enabled']) ? 1 : 0;
        // Clear cached folder_id if folder name changed
        if (($conf['folder_name'] ?? '') !== ($gd->getConfig()['folder_name'] ?? '')) {
            unset($conf['folder_id']);
        }
        $gd->saveConfig($conf);
        flash('Google Drive settings saved.', 'success');
        redirect('?page=dashboard&tab=whatsapp&subtab=gdrive');
    }

    if ($waAct === 'gdrive_authorize') {
        require_once dirname(__DIR__, 2) . '/lib/GoogleDriveBackup.php';
        $gd          = new GoogleDriveBackup($dataDir);
        $redirectUrl = trim($_POST['gdrive_redirect_url'] ?? '');
        $result      = $gd->exchangeCode($redirectUrl);
        if ($result['ok']) {
            flash('Google Drive authorized successfully!', 'success');
        } else {
            flash('Authorization failed: ' . ($result['error'] ?? 'unknown'), 'danger');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=gdrive');
    }

    if ($waAct === 'gdrive_disconnect') {
        require_once dirname(__DIR__, 2) . '/lib/GoogleDriveBackup.php';
        $gd = new GoogleDriveBackup($dataDir);
        $gd->disconnect();
        flash('Google Drive disconnected.', 'success');
        redirect('?page=dashboard&tab=whatsapp&subtab=gdrive');
    }

    if ($waAct === 'gdrive_backup_now') {
        require_once dirname(__DIR__, 2) . '/lib/GoogleDriveBackup.php';
        $gd     = new GoogleDriveBackup($dataDir);
        $result = $gd->runBackup();
        if ($result['ok']) {
            flash("Backup uploaded: {$result['file']} ({$result['size_kb']} KB) in {$result['duration']}s", 'success');
        } else {
            flash('Backup failed: ' . ($result['error'] ?? 'unknown'), 'danger');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=gdrive');
    }

    // ── Evolution API actions ────────────────────────────────────────────
    if ($waAct === 'evo_import_now') {
        // Lazy-fetch: pull recent messages for a single conversation from Evolution API
        $fetchJid = trim($_POST['fetch_jid'] ?? '');
        $evoUrl  = trim($config['evo_api_url'] ?? '');
        $evoKey  = trim($config['evo_api_key'] ?? '');
        $evoInst = trim($config['evo_instance_name'] ?? '');
        $convId  = (int)($_POST['conv_id'] ?? 0);
        if ($fetchJid && $evoUrl && $evoKey && $evoInst) {
            require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
            require_once dirname(__DIR__, 2) . '/lib/ConversationService.php';
            $evo2 = new EvolutionApiClient($evoUrl, $evoKey, $evoInst, 30);
            $convSvc3 = new ConversationService($dataDir, $store->getPdo());
            $ch2 = $config['evo_channel_name'] ?? 'marketing';
            try {
                $msgs = $evo2->findMessages($fetchJid, 50, 1);
                $records = $msgs['messages']['records'] ?? $msgs['messages'] ?? (isset($msgs[0]['key']) ? $msgs : []);
                if (!is_array($records)) $records = [];
                $imported = 0;
                foreach ($records as $m) {
                    if ($convSvc3->importEvoMessage($m, $ch2) !== null) $imported++;
                }
                flash("Fetched {$imported} messages from Evolution API.", 'success');
            } catch (Throwable $e) {
                flash('Fetch failed: ' . $e->getMessage(), 'danger');
            }
        } else {
            flash('Evolution API not configured or no JID provided.', 'danger');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=conversations' . ($convId ? '&conv_id=' . $convId : ''));
    }

    if ($waAct === 'evo_load_chats') {
        // Load chat list from Evolution API and create conversation stubs (no messages)
        $evoUrl  = trim($config['evo_api_url'] ?? '');
        $evoKey  = trim($config['evo_api_key'] ?? '');
        $evoInst = trim($config['evo_instance_name'] ?? '');
        if ($evoUrl && $evoKey && $evoInst) {
            require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
            require_once dirname(__DIR__, 2) . '/lib/ConversationService.php';
            $evo2 = new EvolutionApiClient($evoUrl, $evoKey, $evoInst, 30);
            $convSvc3 = new ConversationService($dataDir, $store->getPdo());
            $ch2 = $config['evo_channel_name'] ?? 'marketing';
            try {
                $chats = $evo2->findChats();
                $created = 0;
                foreach ($chats as $chat) {
                    $jid = $chat['remoteJid'] ?? $chat['jid'] ?? (is_string($chat['id'] ?? null) ? $chat['id'] : null)
                        ?? ($chat['lastMessage']['key']['remoteJid'] ?? null) ?? '';
                    if (empty($jid) || strpos($jid, '@g.us') !== false || strpos($jid, 'status@') !== false) continue;
                    // Extract phone: prefer senderPn from lastMessage
                    $senderPn = $chat['lastMessage']['key']['senderPn'] ?? '';
                    $phone = !empty($senderPn)
                        ? preg_replace('/[^0-9]/', '', explode('@', $senderPn)[0])
                        : preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);
                    if (empty($phone)) continue;
                    $pushName = $chat['lastMessage']['pushName'] ?? $chat['name'] ?? null;
                    // Store JID as metadata for lazy-fetch later
                    $conv = $convSvc3->ensureConversation($phone, $ch2, $pushName, 'evo_chat_list');
                    // Store JID mapping
                    try {
                        $convSvc3->getPdo()->prepare(
                            "UPDATE wa_conversations SET evo_jid = ? WHERE id = ? AND (evo_jid IS NULL OR evo_jid = '')"
                        )->execute([$jid, $conv['id']]);
                    } catch (Throwable $e) { /* column may not exist yet */ }
                    $created++;
                }
                flash("Loaded {$created} chats from Evolution API. Click any chat to fetch messages.", 'success');
            } catch (Throwable $e) {
                flash('Failed: ' . $e->getMessage(), 'danger');
            }
        } else {
            flash('Configure Evolution API first.', 'danger');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=conversations');
    }

    if ($waAct === 'evo_setup_webhook') {
        $evoUrl  = trim($config['evo_api_url'] ?? '');
        $evoKey  = trim($config['evo_api_key'] ?? '');
        $evoInst = trim($config['evo_instance_name'] ?? '');
        if ($evoUrl && $evoKey && $evoInst) {
            require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
            $evo = new EvolutionApiClient($evoUrl, $evoKey, $evoInst);
            $webhookUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/') . '/_plugins/dishnet-hybrid-telecom/evo_webhook.php';
            $result = $evo->setWebhook($webhookUrl);
            if (empty($result['error'])) {
                flash("Webhook configured: {$webhookUrl}", 'success');
            } else {
                flash('Webhook setup failed: ' . ($result['error'] ?? 'unknown'), 'danger');
            }
        } else {
            flash('Configure Evolution API first.', 'danger');
        }
        redirect('?page=dashboard&tab=whatsapp&subtab=conversations');
    }

    // ── Lead Recovery actions ────────────────────────────────────────────
    if ($waAct === 'lead_import_contacts') {
        require_once dirname(__DIR__, 2) . '/lib/LeadRecoveryService.php';
        $leadSvc = new LeadRecoveryService($store->getPdo());
        $jsonFile = $dataDir . '/IsOnWhatsapp.json';
        if (file_exists($jsonFile)) {
            $data = json_decode(file_get_contents($jsonFile), true);
            if (is_array($data)) {
                $count = $leadSvc->importContacts($data);
                flash("Imported {$count} WhatsApp contacts from IsOnWhatsapp.json", 'success');
            } else {
                flash('Invalid JSON in IsOnWhatsapp.json', 'danger');
            }
        } else {
            flash("Upload IsOnWhatsapp.json to data/ folder first. Expected: {$jsonFile}", 'danger');
        }
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($waAct === 'lead_import_upload') {
        require_once dirname(__DIR__, 2) . '/lib/LeadRecoveryService.php';
        $leadSvc = new LeadRecoveryService($store->getPdo());
        if (!empty($_FILES['lead_file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['lead_file']['tmp_name']);
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data)) {
                // Save for future re-imports
                file_put_contents($dataDir . '/IsOnWhatsapp.json', $raw);
                $count = $leadSvc->importContacts($data);
                flash("Uploaded & imported {$count} WhatsApp contacts.", 'success');
            } else {
                flash('Invalid JSON file. Expected array of objects with remoteJid field.', 'danger');
            }
        } else {
            flash('No file uploaded.', 'danger');
        }
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($waAct === 'lead_crm_crossref') {
        require_once dirname(__DIR__, 2) . '/lib/LeadRecoveryService.php';
        $leadSvc = new LeadRecoveryService($store->getPdo());
        
        // Use local CRM cache — no API call needed
        $crmClients = $store->load('ucrm_clients_cache.json') ?? [];
        if (empty($crmClients)) {
            flash('CRM client cache is empty. Go to Settings → Data Sync and run a sync first.', 'danger');
        } else {
            // Also load the search index which has clean phone fields
            $searchIdx = $store->load('ucrm_search_index.json') ?? [];
            
            // Build phone → client lookup from search index (has clean phone field)
            $crmPhoneMap = [];
            foreach ($searchIdx as $entry) {
                $phone = preg_replace('/[^0-9]/', '', $entry['phone'] ?? '');
                if (!empty($phone) && !empty($entry['id'])) {
                    $crmPhoneMap[$phone] = ['id' => $entry['id'], 'name' => $entry['name'] ?? ''];
                }
            }
            // Also scan full client cache for extra phone fields
            foreach ($crmClients as $client) {
                $cid = $client['id'] ?? null;
                $name = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
                if (!$name) $name = $client['companyName'] ?? 'Unknown';
                foreach ($client['contacts'] ?? [] as $ct) {
                    $p = preg_replace('/[^0-9]/', '', $ct['phone'] ?? '');
                    if ($p && $cid) $crmPhoneMap[$p] = ['id' => $cid, 'name' => $name];
                }
                $p = preg_replace('/[^0-9]/', '', $client['phone'] ?? '');
                if ($p && $cid) $crmPhoneMap[$p] = ['id' => $cid, 'name' => $name];
            }
            
            $result = $leadSvc->crossReferenceLocal($crmPhoneMap);
            flash("Cross-referenced against " . count($crmPhoneMap) . " CRM phones. Found {$result['leads']} unconverted leads, {$result['customers']} existing customers.", 'success');
        }
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($waAct === 'lead_send_followup') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $phone  = trim($_POST['lead_phone'] ?? '');
        $msg    = trim($_POST['followup_msg'] ?? '');
        if ($leadId && $phone && $msg) {
            $sent = false;
            // Try Evolution API first
            $evoUrl  = trim($config['evo_api_url'] ?? '');
            $evoKey  = trim($config['evo_api_key'] ?? '');
            $evoInst = trim($config['evo_instance_name'] ?? '');
            if ($evoUrl && $evoKey && $evoInst) {
                require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
                $evo = new EvolutionApiClient($evoUrl, $evoKey, $evoInst);
                $result = $evo->sendText($phone, $msg);
                $sent = empty($result['error']);
            }
            // Fallback to WASender
            if (!$sent) {
                $notif = svc('notify');
                $sent = $notif->sendVia('support', $phone, $msg);
            }
            if ($sent) {
                require_once dirname(__DIR__, 2) . '/lib/LeadRecoveryService.php';
                $leadSvc = new LeadRecoveryService($store->getPdo());
                $leadSvc->markFollowedUp($leadId, "Follow-up sent: " . substr($msg, 0, 100));
                flash("Follow-up sent to {$phone}", 'success');
            } else {
                flash("Failed to send message to {$phone}", 'danger');
            }
        }
        redirect('?page=dashboard&tab=wa_leads');
    }

    if ($waAct === 'lead_update_status') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $status = trim($_POST['lead_status'] ?? '');
        if ($leadId && $status) {
            require_once dirname(__DIR__, 2) . '/lib/LeadRecoveryService.php';
            $leadSvc = new LeadRecoveryService($store->getPdo());
            $leadSvc->updateStatus($leadId, $status);
        }
        redirect('?page=dashboard&tab=wa_leads');
    }
}

// Re-load after possible save
$waTpls = $store->load($waTplFile) ?? [];

$waOk       = !empty($config['wa_app_key']) && !empty($config['wa_auth_key']) && !empty($config['wa_plugin_url']);
// Notification audit log from SQLite (replaces 500-entry JSON)
$_waPdo = $store->getPdo();
$_waPdo->exec("CREATE TABLE IF NOT EXISTS notification_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, sender TEXT, event TEXT, phone TEXT, preview TEXT, success INTEGER NOT NULL DEFAULT 0, http_code INTEGER, error TEXT, sent_at TEXT NOT NULL DEFAULT (datetime('now')))");
$_waRows = $_waPdo->query("SELECT sender, event, phone AS 'to', preview, success, http_code, error, sent_at FROM notification_audit_log ORDER BY id DESC LIMIT 50")->fetchAll(\PDO::FETCH_ASSOC);
foreach ($_waRows as &$_wr) { $_wr['success'] = (bool)$_wr['success']; } unset($_wr);
$waNotifLog = $_waRows;
$whLog = [];
$whLogFile = $dataDir . '/webhook_log.json';
if (file_exists($whLogFile)) {
    $raw2 = json_decode(file_get_contents($whLogFile), true);
    $whLog = is_array($raw2) ? array_slice(array_reverse($raw2), 0, 30) : [];
}
$waSubtab = $_GET['subtab'] ?? 'config';
$settingsSection = $_GET['section'] ?? '';

// ── All events with their code defaults ──────────────────────────────────────
$waEventDefs = [
    'ops_kyc_submitted'       => ['label'=>'New Registration',       'trigger'=>'Agent submits registration (KYC/Cash Sale/Lead)',  'recipient'=>'Agent + Admin',    'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{customer_name}}','{{customer_phone}}','{{service_type}}','{{amount}}','{{app_id}}','{{area}}','{{source}}'],
        'default_body'=>"📋 *New Registration*\n\n👤 {{customer_name}}\n📍 {{area}}\n📶 {{service_type}} | \${{amount}}\n\n{{source}}\n🔖 App #{{app_id}}\n\n👷 Agent: {{agent_name}}\n📱 {{customer_phone}}"],
    'ops_kyc_additional_service' => ['label'=>'Additional Service (Existing)', 'trigger'=>'Agent submits additional service for an existing CRM customer at a new location',  'recipient'=>'Agent + Bidal + Admin', 'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{customer_name}}','{{customer_phone}}','{{crm_id}}','{{service_type}}','{{amount}}','{{app_id}}','{{new_address}}','{{quote_ref}}','{{crm_job_id}}','{{source}}'],
        'default_body'=>"🔄 *Additional Service — Existing Customer*\n\n👤 {{customer_name}}\n🔗 CRM #{{crm_id}} (existing client)\n📍 *NEW SITE:* {{new_address}}\n📶 {{service_type}} | \${{amount}}\n\n{{source}}\n📄 Quote: {{quote_ref}}\n🗓 CRM Job #{{crm_job_id}}\n🔖 App #{{app_id}}\n\n⚠️ Additional location for an existing customer.\nDo NOT modify the existing service. Create a new service line at the new site.\n\n👷 Agent: {{agent_name}}\n📱 {{customer_phone}}"],
    'ops_kyc_crm_created'     => ['label'=>'CRM Account Created',   'trigger'=>'Customer successfully added to UCRM',    'recipient'=>'Agent', 'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{customer_name}}','{{crm_id}}','{{username}}','{{app_id}}','{{service_type}}','{{area}}','{{next_step}}'],
        'default_body'=>"✅ *CRM Account Created*\n\n👤 {{customer_name}}\n🔗 CRM #{{crm_id}} | {{username}}\n📶 {{service_type}} | {{area}}\n\n📋 App #{{app_id}} in Plugin\n➡️ Next: {{next_step}}"],
    'ops_kyc_crm_failed'      => ['label'=>'CRM Creation Failed',   'trigger'=>'All 3 retries exhausted, wallet refunded','recipient'=>'Agent + Admin',   'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{customer_name}}','{{amount_refunded}}','{{error}}','{{app_id}}','{{service_type}}'],
        'default_body'=>"⚠️ *CRM Account Failed*\n\n👤 {{customer_name}}\n📋 App #{{app_id}} | {{service_type}}\n\n❌ *Error:* {{error}}\n\n💰 *Wallet refunded:* \${{amount_refunded}}\n\n📋 Check existing accounts or resubmit.\n❓ Help: +211 921 443 006"],
    'ops_kyc_customer_welcome'=> ['label'=>'Customer Welcome',      'trigger'=>'Sent to customer after CRM creation',    'recipient'=>'Customer',         'default_sender'=>'support',
        'vars'=>['{{customer_name}}','{{service_type}}','{{username}}'],
        'default_body'=>"👋 *Welcome to DishNet Internet!*\nDear {{customer_name}},\n\nYour {{service_type}} service is being set up.\n\n🛠 Support: https://wa.me/211921443006\n💼 Account: https://wa.me/211921443002\n\n— DishNet Team"],
    'ops_lead_assigned'       => ['label'=>'Lead Assigned',         'trigger'=>'Lead assigned to an agent by admin',     'recipient'=>'Agent',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{lead_name}}','{{lead_phone}}','{{source}}'],
        'default_body'=>"🎯 *New Lead Assigned*\nHi {{agent_name}},\nName: {{lead_name}}  |  Phone: {{lead_phone}}\nOpen the app to follow up."],
    'ops_sim_activated'       => ['label'=>'SIM Activated',         'trigger'=>'SIM card activated for customer',        'recipient'=>'Agent',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{customer_name}}','{{msisdn}}','{{fee}}'],
        'default_body'=>"📡 *SIM Activated*\nCustomer: {{customer_name}}  |  MSISDN: {{msisdn}}\nFee: \${{fee}}"],
    'ops_fiber_batch'         => ['label'=>'Fiber Batch Dispatched','trigger'=>'Bidal creates fiber deployment batch',    'recipient'=>'Support Leader',   'default_sender'=>'support',
        'vars'=>['{{batch_name}}','{{partner}}','{{created}}','{{total}}','{{failed}}'],
        'default_body'=>"🔧 *Fiber Batch Dispatched*\nBatch: {{batch_name}}  |  Partner: {{partner}}\nJobs: {{created}}/{{total}} created"],
    'ops_wallet_topped_up'    => ['label'=>'Wallet Topped Up',      'trigger'=>'Admin credits agent wallet',             'recipient'=>'Agent',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{amount}}','{{new_balance}}','{{note}}'],
        'default_body'=>"💰 *Wallet Topped Up*\nHi {{agent_name}},\nAdded: \${{amount}}  |  Balance: \${{new_balance}}"],
    'ops_recharge_submitted'  => ['label'=>'Recharge Requested',    'trigger'=>'Agent requests wallet top-up',           'recipient'=>'Admin',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{amount}}','{{request_id}}'],
        'default_body'=>"💳 *Recharge Request #{{request_id}}*\nFrom: {{agent_name}}\nAmount: \${{amount}}\nApprove in plugin."],
    'ops_recharge_approved'   => ['label'=>'Recharge Approved',     'trigger'=>'Admin approves recharge request',        'recipient'=>'Agent',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{amount}}','{{new_balance}}','{{approved_by}}'],
        'default_body'=>"✅ *Recharge Approved*\nHi {{agent_name}},\nAmount: \${{amount}}  |  Balance: \${{new_balance}}\nBy: {{approved_by}}"],
    'ops_recharge_rejected'   => ['label'=>'Recharge Rejected',     'trigger'=>'Admin rejects recharge request',         'recipient'=>'Agent',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{amount}}','{{reason}}'],
        'default_body'=>"❌ *Recharge Rejected*\nHi {{agent_name}},\nAmount: \${{amount}}\nReason: {{reason}}"],
    'ops_handover_submitted'  => ['label'=>'Cash Handover Submitted','trigger'=>'Diko submits end-of-day handover',       'recipient'=>'Admin (Rupesh)',   'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{amount}}','{{remitted_to}}','{{ref}}'],
        'default_body'=>"💵 *Cash Handover #{{ref}}*\nAgent: {{agent_name}}\nAmount: \${{amount}}  |  To: {{remitted_to}}\nCount and confirm in plugin."],
    'ops_handover_approved'   => ['label'=>'Handover Confirmed',    'trigger'=>'Rupesh confirms physical cash receipt',  'recipient'=>'Agent (Diko)',     'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{amount}}','{{cash_balance}}','{{approved_by}}'],
        'default_body'=>"✅ *Handover Confirmed*\nHi {{agent_name}},\nConfirmed: \${{amount}}  |  Cash-in-hand: \${{cash_balance}}\nBy: {{approved_by}}"],
    'ops_handover_rejected'   => ['label'=>'Handover Rejected',     'trigger'=>'Rupesh rejects handover amount',         'recipient'=>'Agent (Diko)',     'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{amount}}','{{reason}}'],
        'default_body'=>"⚠️ *Handover Rejected*\nHi {{agent_name}},\nAmount: \${{amount}}\nReason: {{reason}}"],
    'ops_invoice_created'     => ['label'=>'Invoice Created',       'trigger'=>'Plugin generates invoice for customer',  'recipient'=>'Customer',         'default_sender'=>'accounts',
        'default_enabled'=>false, 'ucrm_handles'=>'invoice.add',
        'vars'=>['{{customer_name}}','{{invoice_number}}','{{amount}}','{{due_date}}'],
        'default_body'=>"🧾 *Invoice #{{invoice_number}}*\nDear {{customer_name}},\nAmount: \${{amount}}  |  Due: {{due_date}}\nPay: https://dishnetafrica.com/tutorials/index.html\nHelp: +211 921 443 002\n— DishNet Accounts"],
    'ops_payment_received'    => ['label'=>'Payment Received',      'trigger'=>'Payment collected via plugin',           'recipient'=>'Customer',         'default_sender'=>'accounts',
        'default_enabled'=>false, 'ucrm_handles'=>'payment.add',
        'vars'=>['{{customer_name}}','{{amount}}','{{txn_id}}'],
        'default_body'=>"✅ *Payment Received*\nDear {{customer_name}},\nAmount: \${{amount}}  |  Ref: {{txn_id}}\nThank you — DishNet Accounts"],
    'ops_low_balance'         => ['label'=>'Low Balance Warning',   'trigger'=>'Pre-suspension balance alert',           'recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{outstanding}}','{{due_date}}'],
        'default_body'=>"⚠️ *Payment Reminder*\nDear {{customer_name}},\nOutstanding: \${{outstanding}}  |  Due: {{due_date}}\nPay: https://dishnetafrica.com/tutorials/index.html\n— DishNet Accounts"],
    'ops_pre_due_d7'          => ['label'=>'Due in 7 Days',          'trigger'=>'7 days before invoice due (cron)',       'recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{invoice_number}}','{{amount}}','{{currency}}','{{due_date}}','{{service_name}}'],
        'default_body'=>"📋 *Upcoming Invoice — DishNet Africa*\n\nHi {{customer_name}},\n\nInvoice #{{invoice_number}} of *{{amount}} {{currency}}* is due on *{{due_date}}* (7 days).\n\n💳 Pay early: https://dishnetafrica.com/tutorials/index.html\n— DishNet Accounts"],
    'ops_pre_due_d3'          => ['label'=>'Due in 3 Days',          'trigger'=>'3 days before invoice due (cron)',       'recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{invoice_number}}','{{amount}}','{{currency}}','{{due_date}}','{{service_name}}'],
        'default_body'=>"⏰ *Due in 3 Days — DishNet Africa*\n\nHi {{customer_name}},\n\nInvoice #{{invoice_number}} is due on *{{due_date}}*.\n💰 Amount: *{{amount}} {{currency}}*\n\nPay now: https://dishnetafrica.com/tutorials/index.html\nHelp: +211 921 443 002\n— DishNet Accounts"],
    'ops_pre_due_d1'          => ['label'=>'Due Tomorrow',           'trigger'=>'1 day before invoice due (cron)',        'recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{invoice_number}}','{{amount}}','{{currency}}','{{due_date}}','{{service_name}}'],
        'default_body'=>"🔴 *Due Tomorrow — DishNet Africa*\n\nDear {{customer_name}},\n\nInvoice #{{invoice_number}} of *{{amount}} {{currency}}* is due *tomorrow* ({{due_date}}).\n\nPay today: https://dishnetafrica.com/tutorials/index.html\n📞 +211 921 443 002\n— DishNet Accounts"],
    'ops_overdue_d1'          => ['label'=>'Overdue Day +1',        'trigger'=>'1 day after invoice due (cron)',         'recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{invoice_number}}','{{amount}}','{{currency}}'],
        'default_body'=>"⏰ *Gentle Reminder — DishNet Africa*\n\nHi {{customer_name}},\n\nYour invoice #{{invoice_number}} of *{{amount}} {{currency}}* was due yesterday.\n\nPay today to avoid interruption:\n🔗 https://dishnetafrica.com/tutorials/index.html\n\nNeed help? +211 921 443 002\n— DishNet Accounts"],
    'ops_overdue_d3'          => ['label'=>'Overdue Day +3',        'trigger'=>'3 days after invoice due (cron)',        'recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{invoice_number}}','{{amount}}','{{currency}}'],
        'default_body'=>"🔴 *Account Overdue — DishNet Africa*\n\nDear {{customer_name}},\n\nOutstanding: *{{amount}} {{currency}}* (Invoice #{{invoice_number}}).\nService at risk of suspension.\n\nPay now: https://dishnetafrica.com/tutorials/index.html\nQuestions? +211 921 443 002\n— DishNet Accounts"],
    'ops_overdue_d5'          => ['label'=>'Overdue Day +5',        'trigger'=>'5 days after invoice due — final notice','recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{invoice_number}}','{{amount}}','{{currency}}','{{service_name}}'],
        'default_body'=>"🚨 *Final Notice — Service Suspending Tonight*\n\nDear {{customer_name}},\n\nYour service *{{service_name}}* suspends at midnight.\n💰 Outstanding: *{{amount}} {{currency}}*\n📋 Invoice: #{{invoice_number}}\n\nPay before midnight:\n🔗 https://dishnetafrica.com/tutorials/index.html\n\n📞 +211 921 443 002\n— DishNet Accounts"],
    'ops_install_confirmed'   => ['label'=>'Installation Confirmed','trigger'=>'Admin books installation appointment',   'recipient'=>'Customer',         'default_sender'=>'support',
        'vars'=>['{{customer_name}}','{{service_type}}','{{install_date}}','{{install_time}}','{{tech_name}}'],
        'default_body'=>"📅 *Installation Confirmed*\n\nHi {{customer_name}},\n\nYour *{{service_type}}* installation is booked! ✅\n📆 Date: *{{install_date}}*\n🕐 Time: {{install_time}}\n👷 Technician: {{tech_name}}\n\nTechnician calls 30 min before arrival.\n\nQuestions? 📞 +211 921 443 006\n— DishNet Support"],
    'ops_outage_alert'        => ['label'=>'Planned Maintenance',   'trigger'=>'Bulk-send from admin UI',                'recipient'=>'Customer',         'default_sender'=>'support',
        'vars'=>['{{customer_name}}','{{maint_date}}','{{maint_start}}','{{maint_end}}'],
        'default_body'=>"🔧 *Planned Maintenance — DishNet Africa*\n\nDear {{customer_name}},\n\nMaintenance in your area:\n📅 Date: *{{maint_date}}*\n🕐 Window: {{maint_start}} – {{maint_end}}\n\nWe apologise for any inconvenience.\n— DishNet Technical Team"],
    'event_service_end'       => ['label'=>'Service Ended (Churn)', 'trigger'=>'UCRM service.end webhook received',      'recipient'=>'Customer',         'default_sender'=>'accounts',
        'vars'=>['{{customer_name}}','{{service_name}}'],
        'default_body'=>"👋 *We're Sorry to See You Go — DishNet Africa*\n\nHi {{customer_name}},\n\nYour DishNet service has ended.\n\nIf there's anything we could have done better, we'd like to know.\n\nThinking of reconnecting? Contact us for reconnection offers.\n📞 +211 921 443 006\n— The DishNet Team"],
    'ops_commission_summary'  => ['label'=>'Monthly Commission',    'trigger'=>'Admin triggers monthly commission report','recipient'=>'Agent',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{month}}','{{new_customers}}','{{commission_amount}}','{{bonus}}','{{total_payout}}','{{pay_date}}'],
        'default_body'=>"💰 *Monthly Commission — DishNet Africa*\n\nHi {{agent_name}},\n\nHere is your commission for *{{month}}*:\n\n👥 New customers:  {{new_customers}}\n💵 Commission:     \${{commission_amount}}\n🎁 Bonus:          \${{bonus}}\n━━━━━━━━━━━━\n💰 Total:          *\${{total_payout}}*\n\nPayment by: {{pay_date}}\n— DishNet Operations"],
    'ops_agent_lead_nudge'    => ['label'=>'Lead Follow-up Nudge',  'trigger'=>'Cron — agent has stale leads',           'recipient'=>'Agent',            'default_sender'=>'support',
        'vars'=>['{{agent_name}}','{{pending_leads}}','{{deadline}}'],
        'default_body'=>"📋 *Lead Follow-up Reminder*\n\nHi {{agent_name}},\n\nYou have *{{pending_leads}}* pending lead(s) not updated this week.\n\nFollow up before *{{deadline}}* — stale leads are lost revenue.\n\nOpen Operations Hub to review.\n— DishNet Operations"],
];
?>

<style>
/* ── WhatsApp tab chrome ─────────────────────────────── */
.wa2-hero{background:linear-gradient(135deg,#075e54 0%,#128c7e 100%);border-radius:18px;padding:22px 24px;color:#fff;margin-bottom:18px;position:relative;overflow:hidden;}
.wa2-hero::before{content:'';position:absolute;top:-50px;right:-50px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.06);}
.wa2-subtabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:20px;}
.wa2-stab{padding:10px 20px;font-size:13px;font-weight:700;color:#64748b;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.15s;}
.wa2-stab.active{color:#075e54;border-bottom-color:#25D366;}
.wa2-stab:hover{color:#075e54;}
/* ── Event map table ─────────────────────────────────── */
.wa2-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.wa2-tbl th{background:#f8fafc;padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid #e2e8f0;}
.wa2-tbl td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.wa2-tbl tr:hover td{background:#f8fafc;}
.wa2-tbl tr.customised td{background:#fffbeb;}
.wa2-tbl tr.dup-warning td{background:#fef2f2;}
.wa2-tbl tr.dup-warning:hover td{background:#fee2e2;}
.wa2-badge{display:inline-flex;align-items:center;gap:3px;padding:2px 9px;border-radius:10px;font-size:10px;font-weight:800;}
.wa2-supp{background:#dbeafe;color:#1d4ed8;}
.wa2-acct{background:#d1fae5;color:#065f46;}
.wa2-on{background:#dcfce7;color:#15803d;}
.wa2-off{background:#fee2e2;color:#991b1b;}
.wa2-custom{background:#fef3c7;color:#92400e;}
.wa2-edit-btn{background:#075e54;color:#fff;border:none;border-radius:8px;padding:4px 12px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;}
.wa2-edit-btn:hover{background:#128c7e;}
/* ── Edit modal ──────────────────────────────────────── */
.wa2-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998;justify-content:center;align-items:flex-start;padding:max(20px,calc(env(safe-area-inset-top)+12px)) 20px 20px;overflow-y:auto;}
.wa2-modal-bg.open{display:flex;}
.wa2-modal{background:#fff;border-radius:18px;padding:0;width:100%;max-width:620px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);margin-top:30px;}
.wa2-modal-hd{background:linear-gradient(135deg,#075e54,#128c7e);color:#fff;padding:16px 20px;border-radius:18px 18px 0 0;display:flex;justify-content:space-between;align-items:center;}
.wa2-modal-body{padding:20px;}
.wa2-field{margin-bottom:16px;}
.wa2-field label{display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;}
.wa2-field textarea{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:monospace;resize:vertical;min-height:160px;background:#fafafa;box-sizing:border-box;}
.wa2-field textarea:focus{outline:none;border-color:#25D366;background:#fff;}
.wa2-field input[type=text]{width:100%;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;background:#fafafa;box-sizing:border-box;}
.wa2-sender-toggle{display:flex;gap:8px;}
.wa2-sender-opt{flex:1;border:2px solid #e2e8f0;border-radius:10px;padding:10px;text-align:center;cursor:pointer;transition:.15s;font-size:12px;font-weight:700;}
.wa2-sender-opt.sel-support{border-color:#1d4ed8;background:#dbeafe;color:#1d4ed8;}
.wa2-sender-opt.sel-accounts{border-color:#065f46;background:#d1fae5;color:#065f46;}
.wa2-var-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px;}
.wa2-var-chip{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:2px 8px;font-size:11px;font-family:monospace;cursor:pointer;color:#374151;}
.wa2-var-chip:hover{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8;}
.wa2-preview-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;font-size:12px;font-family:monospace;white-space:pre-wrap;color:#1e293b;margin-top:8px;min-height:60px;}
.wa2-modal-footer{display:flex;gap:8px;padding:14px 20px;border-top:1px solid #f1f5f9;flex-wrap:wrap;}
.wa2-btn-save{background:linear-gradient(135deg,#128c7e,#075e54);color:#fff;border:none;border-radius:10px;padding:10px 22px;font-size:13px;font-weight:800;cursor:pointer;}
.wa2-btn-reset{background:#fff;color:#92400e;border:2px solid #fde68a;border-radius:10px;padding:10px 18px;font-size:12px;font-weight:700;cursor:pointer;}
.wa2-btn-test{background:#1d4ed8;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-size:12px;font-weight:700;cursor:pointer;}
.wa2-btn-close{background:#f1f5f9;color:#374151;border:none;border-radius:10px;padding:10px 18px;font-size:12px;font-weight:700;cursor:pointer;}
.wa2-log-row{display:flex;align-items:flex-start;gap:8px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:12px;}
.wa2-log-row:last-child{border-bottom:none;}
.wa2-log-ok{background:#dcfce7;color:#166534;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:800;flex-shrink:0;}
.wa2-log-fail{background:#fee2e2;color:#991b1b;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:800;flex-shrink:0;}
</style>

<!-- ── Hero ───────────────────────────────────────────────────────────── -->
<div class="wa2-hero">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
        <div style="font-size:36px;line-height:1;">
            <svg viewBox="0 0 24 24" width="36" height="36" fill="#25D366"><path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.37 5.07L2 22l5.08-1.35A9.96 9.96 0 0 0 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm4.64 13.42c-.2.55-1.15 1.05-1.58 1.1-.4.05-.9.07-1.45-.09-.34-.1-.77-.24-1.31-.47-2.3-1-3.8-3.33-3.91-3.48-.12-.15-.95-1.26-.95-2.4 0-1.15.6-1.71.81-1.94.2-.23.44-.28.59-.28.15 0 .3.001.43.006.14.006.33-.054.52.4.2.47.67 1.62.73 1.74.06.12.1.26.02.42-.08.15-.12.25-.24.38-.12.13-.25.29-.36.39-.12.11-.24.23-.1.46.14.23.62 1.02 1.33 1.65.91.81 1.68 1.06 1.92 1.18.23.12.37.1.5-.06.14-.16.59-.69.74-.93.16-.23.31-.19.52-.12.21.07 1.35.64 1.58.76.23.12.39.18.44.28.05.1.05.56-.15 1.1z"/></svg>
        </div>
        <div>
            <div style="font-size:22px;font-weight:900;">WhatsApp Notifications</div>
            <div style="font-size:12px;opacity:.75;">WASender integration · Ops events sent from this plugin</div>
        </div>
        <div style="margin-left:auto;">
            <?php if($waOk): ?>
            <span style="background:rgba(37,211,102,.2);border:1px solid rgba(37,211,102,.4);color:#25D366;padding:6px 16px;border-radius:20px;font-size:12px;font-weight:800;">● Connected — Notifications Active</span>
            <?php else: ?>
            <span style="background:rgba(239,68,68,.2);border:1px solid rgba(239,68,68,.4);color:#fca5a5;padding:6px 16px;border-radius:20px;font-size:12px;font-weight:800;">⚠ Not Configured</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Sub-tabs ────────────────────────────────────────────────────────── -->
<div class="wa2-subtabs">
    <a href="?page=dashboard&tab=whatsapp&subtab=config"   class="wa2-stab <?= $waSubtab==='config'  ?'active':'' ?>">⚙ Configuration</a>
    <a href="?page=dashboard&tab=whatsapp&subtab=events"   class="wa2-stab <?= $waSubtab==='events'  ?'active':'' ?>">📋 Event Map
        <?php $customCount = count(array_filter($waEventDefs, fn($k)=>isset($waTpls[$k]), ARRAY_FILTER_USE_KEY)); ?>
        <?php if($customCount>0): ?><span style="background:#f59e0b;color:#fff;border-radius:8px;padding:1px 6px;font-size:10px;font-weight:800;margin-left:4px;"><?= $customCount ?> custom</span><?php endif; ?>
    </a>
    <a href="?page=dashboard&tab=whatsapp&subtab=log"      class="wa2-stab <?= $waSubtab==='log'     ?'active':'' ?>">📊 Message Log</a>
    <a href="?page=dashboard&tab=whatsapp&subtab=webhook"  class="wa2-stab <?= $waSubtab==='webhook' ?'active':'' ?>">🔔 UCRM Webhook</a>
    <a href="?page=dashboard&tab=whatsapp&subtab=gdrive"   class="wa2-stab <?= $waSubtab==='gdrive'  ?'active':'' ?>" style="border-left:2px solid #e2e8f0;">
        <span style="color:#4285f4;">◉</span> Google Drive
        <?php
        $gdBackupSvc = null;
        require_once dirname(__DIR__, 2) . '/lib/GoogleDriveBackup.php';
        $gdBackupSvc = new GoogleDriveBackup($dataDir);
        $gdConf      = $gdBackupSvc->getConfig();
        ?>
        <?php if($gdBackupSvc->isAuthorized()): ?><span style="background:#22c55e;color:#fff;border-radius:8px;padding:1px 6px;font-size:9px;font-weight:800;margin-left:3px;">●</span><?php endif; ?>
    </a>
    <a href="?page=dashboard&tab=whatsapp&subtab=conversations" class="wa2-stab <?= $waSubtab==='conversations' ?'active':'' ?>" style="border-left:2px solid #e2e8f0;">
        💬 Conversations
        <?php
        // Quick conversation count using existing store PDO
        $convCountAll = 0;
        try {
            $convCountAll = (int)$store->getPdo()->query("SELECT COUNT(*) FROM wa_conversations")->fetchColumn();
        } catch (Throwable $e) {}
        ?>
        <?php if($convCountAll > 0): ?><span style="background:#7C3AED;color:#fff;border-radius:8px;padding:1px 6px;font-size:9px;font-weight:800;margin-left:3px;"><?= number_format($convCountAll) ?></span><?php endif; ?>
    </a>
    <a href="?page=dashboard&tab=whatsapp&subtab=autoreplies" class="wa2-stab <?= $waSubtab==='autoreplies' ?'active':'' ?>">🤖 Auto-Reply</a>
    <a href="?page=dashboard&tab=wa_leads" class="wa2-stab <?= $waSubtab==='leads' ?'active':'' ?>" style="border-left:2px solid #e2e8f0;">
        🎯 WA Leads →
        <?php
        $leadCount = 0;
        try { $leadCount = (int)$store->getPdo()->query("SELECT COUNT(*) FROM wa_lead_recovery WHERE is_customer = 0")->fetchColumn(); } catch (Throwable $e) {}
        ?>
        <?php if($leadCount > 0): ?><span style="background:#ef4444;color:#fff;border-radius:8px;padding:1px 6px;font-size:9px;font-weight:800;margin-left:3px;"><?= number_format($leadCount) ?></span><?php endif; ?>
    </a>
</div>

<!-- ════════════════════════════════════════════════════
     SUBTAB: Configuration
     ════════════════════════════════════════════════════ -->
<?php if($waSubtab === 'config'): ?>

<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="crm_base_url"             value="<?= h($config['crm_base_url']??'') ?>">
<input type="hidden" name="crm_auth_token"           value="<?= h($config['crm_auth_token']??'') ?>">
<input type="hidden" name="commission_rate"           value="<?= h((string)($config['commission_rate']??5)) ?>">
<input type="hidden" name="lte_commission_rate"       value="<?= h((string)($config['lte_commission_rate']??5)) ?>">
<input type="hidden" name="starlink_commission_rate"  value="<?= h((string)($config['starlink_commission_rate']??5)) ?>">
<input type="hidden" name="fiber_commission_rate"     value="<?= h((string)($config['fiber_commission_rate']??5)) ?>">
<input type="hidden" name="commission_on_collection"  value="<?= h((string)($config['commission_on_collection']??1)) ?>">
<input type="hidden" name="commission_on_kyc"         value="<?= h((string)($config['commission_on_kyc']??1)) ?>">
<input type="hidden" name="default_target"            value="<?= h((string)($config['retailer_targets']['default']??0)) ?>">
<input type="hidden" name="ftth_attr_wallet_balance"  value="<?= h((string)($config['ftth_attr_wallet_balance']??101)) ?>">
<input type="hidden" name="ftth_attr_retailer_id"     value="<?= h((string)($config['ftth_attr_retailer_id']??102)) ?>">
<input type="hidden" name="ftth_attr_retailer_role"   value="<?= h((string)($config['ftth_attr_retailer_role']??103)) ?>">
<input type="hidden" name="magma_host"                value="<?= h($config['magma_host']??'') ?>">
<input type="hidden" name="magma_network_id"          value="<?= h($config['magma_network_id']??'') ?>">
<input type="hidden" name="magma_client_cert_path"    value="<?= h($config['magma_client_cert_path']??'') ?>">
<input type="hidden" name="magma_client_key_path"     value="<?= h($config['magma_client_key_path']??'') ?>">
<input type="hidden" name="magma_ca_cert_path"        value="<?= h($config['magma_ca_cert_path']??'') ?>">
<input type="hidden" name="webhook_secret"            value="<?= h($config['webhook_secret']??'') ?>"><?php
// Build tariff map display string
$tariffMapDisplay = implode(', ', array_map(
    fn($k,$v) => "{$k}:{$v}",
    array_keys($config['splynx_tariff_map'] ?? []),
    array_values($config['splynx_tariff_map'] ?? [])
));
$splynxStatus = $splynx->isConfigured() ? $splynx->testConnection() : ['ok' => false, 'error' => 'Not configured'];
?>
<input type="hidden" name="whatsapp_webhook_url"      value="<?= h($config['whatsapp_webhook_url']??'') ?>">
<input type="hidden" name="whatsapp_webhook_secret"   value="<?= h($config['whatsapp_webhook_secret']??'') ?>">
<input type="hidden" name="star_seq_start"            value="<?= h((string)($config['star_seq_start']??0)) ?>">
<input type="hidden" name="ftth_seq_start"            value="<?= h((string)($config['ftth_seq_start']??0)) ?>">
<input type="hidden" name="fiber_install_fee"         value="<?= h((string)($config['fiber_install_fee']??100)) ?>">
<input type="hidden" name="auto_quote_enabled"        value="<?= h((string)($config['auto_quote_enabled']??1)) ?>">
<input type="hidden" name="auto_quote_validity_days"  value="<?= h((string)($config['auto_quote_validity_days']??7)) ?>">

<input type="hidden" name="auto_quote_validity_days"  value="<?= h((string)($config['auto_quote_validity_days']??7)) ?>">

<a class="settings-section-anchor" id="settings-whatsapp"></a>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-robot" style="color:#25D366;"></i> Auto-Reply Bot
    </div>
    <div style="padding:18px;">
        <?php
        $_waSupOn  = !empty($config['wa_bot_enabled']);
        $_waAccOn  = !empty($config['wa_accounts_autoreply_enabled']);
        $_waSupNum = $config['wa_phone_number'] ?? '';
        $_waAccNum = $config['wa_accounts_number'] ?? '';
        ?>
        <!-- Per-number toggle rows -->
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:18px;">

          <!-- Support number -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:18px;">🟢</span>
              <div>
                <div style="font-size:13px;font-weight:700;color:#1e293b;">Support number</div>
                <?php if ($_waSupNum): ?><div style="font-size:11px;color:#64748b;"><?= h($_waSupNum) ?></div><?php endif; ?>
              </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <span style="font-size:12px;color:<?= $_waSupOn ? '#16a34a' : '#94a3b8' ?>;font-weight:600;"><?= $_waSupOn ? 'Auto-reply ON' : 'Auto-reply OFF' ?></span>
              <div style="position:relative;width:44px;height:24px;display:inline-block;">
                <input type="checkbox" name="wa_bot_enabled" value="1" <?= $_waSupOn ? 'checked' : '' ?>
                  style="opacity:0;width:0;height:0;position:absolute;" id="tog_support"
                  onchange="updateToggle(this,'tog_support_track','tog_support_label','Auto-reply ON','Auto-reply OFF','#16a34a','#94a3b8')">
                <span id="tog_support_track" style="position:absolute;top:0;left:0;right:0;bottom:0;border-radius:12px;transition:.2s;background:<?= $_waSupOn ? '#16a34a' : '#cbd5e1' ?>;cursor:pointer;" onclick="document.getElementById('tog_support').click()">
                  <span style="position:absolute;top:3px;left:<?= $_waSupOn ? '23px' : '3px' ?>;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s;" id="tog_support_knob"></span>
                </span>
              </div>
            </label>
          </div>

          <!-- Accounts number -->
          <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:18px;">🔵</span>
              <div>
                <div style="font-size:13px;font-weight:700;color:#1e293b;">Accounts number</div>
                <?php if ($_waAccNum): ?><div style="font-size:11px;color:#64748b;"><?= h($_waAccNum) ?></div><?php endif; ?>
              </div>
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <span style="font-size:12px;color:<?= $_waAccOn ? '#16a34a' : '#94a3b8' ?>;font-weight:600;" id="tog_accounts_label"><?= $_waAccOn ? 'Auto-reply ON' : 'Auto-reply OFF' ?></span>
              <div style="position:relative;width:44px;height:24px;display:inline-block;">
                <input type="checkbox" name="wa_accounts_autoreply_enabled" value="1" <?= $_waAccOn ? 'checked' : '' ?>
                  style="opacity:0;width:0;height:0;position:absolute;" id="tog_accounts"
                  onchange="updateToggle(this,'tog_accounts_track','tog_accounts_label','Auto-reply ON','Auto-reply OFF','#16a34a','#94a3b8')">
                <span id="tog_accounts_track" style="position:absolute;top:0;left:0;right:0;bottom:0;border-radius:12px;transition:.2s;background:<?= $_waAccOn ? '#16a34a' : '#cbd5e1' ?>;cursor:pointer;" onclick="document.getElementById('tog_accounts').click()">
                  <span style="position:absolute;top:3px;left:<?= $_waAccOn ? '23px' : '3px' ?>;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s;" id="tog_accounts_knob"></span>
                </span>
              </div>
            </label>
          </div>

        </div>
        <script>
        function updateToggle(cb, trackId, labelId, onTxt, offTxt, onClr, offClr) {
          var on = cb.checked;
          var track = document.getElementById(trackId);
          var label = document.getElementById(labelId);
          var knobId = trackId.replace('_track','_knob');
          var knob  = document.getElementById(knobId);
          track.style.background = on ? onClr : '#cbd5e1';
          if (knob) knob.style.left = on ? '23px' : '3px';
          if (label) { label.textContent = on ? onTxt : offTxt; label.style.color = on ? onClr : offClr; }
        }
        </script>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Bot Timeout (minutes)</label>
                <input type="number" name="wa_bot_timeout_minutes" class="form-control" value="<?= h((string)($config['wa_bot_timeout_minutes'] ?? 15)) ?>" min="1" max="120">
                <small class="text-muted">Auto-close conversations after this many minutes of inactivity</small>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Webhook Secret</label>
                <input type="text" name="wa_webhook_secret" class="form-control" value="<?= h($config['wa_webhook_secret'] ?? '') ?>" placeholder="Optional — leave empty to accept all">
                <small class="text-muted">Shared secret for WASender webhook verification</small>
            </div>
        </div>

        <!-- AI Bot Settings Section -->
        <?php
        $_aiProvider    = $config['ai_provider'] ?? 'claude';
        $_hasClaudeKey  = !empty($config['claude_api_key']);
        $_hasOpenaiKey  = !empty($config['openai_api_key']);
        $_activeKey     = $_aiProvider === 'openai' ? $_hasOpenaiKey : $_hasClaudeKey;
        $_customInstr   = $config['bot_custom_instructions'] ?? '';
        ?>
        <div style="border:1.5px solid #e0e7ff;border-radius:12px;padding:16px;background:#f5f3ff;">
            <div style="font-size:13px;font-weight:800;color:#4f46e5;margin-bottom:4px;">🤖 AI Bot Settings</div>
            <div style="font-size:11px;color:#6d28d9;margin-bottom:14px;">
                When a customer message doesn't match a keyword template, the AI generates a natural reply using their live CRM data, Splynx status, and your custom instructions below.
            </div>

            <!-- Provider selector -->
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">AI Provider</label>
                <div style="display:flex;gap:8px;">
                    <label style="display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;border:2px solid <?= $_aiProvider==='claude'?'#6366f1':'#e2e8f0' ?>;background:<?= $_aiProvider==='claude'?'#ede9fe':'#fff' ?>;color:<?= $_aiProvider==='claude'?'#4f46e5':'#64748b' ?>;">
                        <input type="radio" name="ai_provider" value="claude" <?= $_aiProvider==='claude'?'checked':'' ?> style="accent-color:#6366f1;">
                        🧠 Claude (Anthropic)
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;border:2px solid <?= $_aiProvider==='openai'?'#10b981':'#e2e8f0' ?>;background:<?= $_aiProvider==='openai'?'#d1fae5':'#fff' ?>;color:<?= $_aiProvider==='openai'?'#065f46':'#64748b' ?>;">
                        <input type="radio" name="ai_provider" value="openai" <?= $_aiProvider==='openai'?'checked':'' ?> style="accent-color:#10b981;">
                        ✨ ChatGPT (OpenAI)
                    </label>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:6px;">
                    Both use the same DishNet knowledge base and CRM context. ChatGPT is ~5× cheaper. Claude is more cautious. Either works well.
                </div>
            </div>

            <!-- API keys — two rows, always visible -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#4f46e5;margin-bottom:5px;">
                        🧠 Anthropic API Key
                        <?php if ($_hasClaudeKey && $_aiProvider==='claude'): ?>
                        <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;margin-left:4px;">ACTIVE</span>
                        <?php elseif ($_hasClaudeKey): ?>
                        <span style="background:#f1f5f9;color:#94a3b8;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600;margin-left:4px;">Saved</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="claude_api_key" class="form-control"
                           value="<?= h($config['claude_api_key'] ?? '') ?>"
                           placeholder="sk-ant-api03-..."
                           autocomplete="new-password"
                           style="font-family:monospace;font-size:12px;">
                    <small class="text-muted">Get key: <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></small>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#065f46;margin-bottom:5px;">
                        ✨ OpenAI API Key
                        <?php if ($_hasOpenaiKey && $_aiProvider==='openai'): ?>
                        <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;margin-left:4px;">ACTIVE</span>
                        <?php elseif ($_hasOpenaiKey): ?>
                        <span style="background:#f1f5f9;color:#94a3b8;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:600;margin-left:4px;">Saved</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="openai_api_key" class="form-control"
                           value="<?= h($config['openai_api_key'] ?? '') ?>"
                           placeholder="sk-proj-..."
                           autocomplete="new-password"
                           style="font-family:monospace;font-size:12px;">
                    <small class="text-muted">Get key: <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></small>
                </div>
            </div>

            <!-- Usage stats for active provider -->
            <?php
            try {
                if ($_aiProvider === 'openai' && $_hasOpenaiKey) {
                    require_once dirname(__DIR__,2).'/lib/GptWaClient.php';
                    $_aiStats = (new GptWaClient($config['openai_api_key'], $store->getPdo()))->getUsageStats(30);
                } elseif ($_hasClaudeKey) {
                    require_once dirname(__DIR__,2).'/lib/ClaudeWaClient.php';
                    $_aiStats = (new ClaudeWaClient($config['claude_api_key'], $store->getPdo()))->getUsageStats(30);
                } else { $_aiStats = []; }
                if (!empty($_aiStats['calls'])) {
                    echo '<div style="background:#fff;border-radius:8px;padding:8px 12px;margin-bottom:14px;font-size:11px;color:#4f46e5;font-weight:600;">'
                       . '📊 Last 30 days: <strong>' . number_format((int)$_aiStats['calls']) . ' AI replies</strong> · '
                       . number_format((int)$_aiStats['in_tok'] + (int)$_aiStats['out_tok']) . ' tokens · '
                       . '$' . number_format((float)$_aiStats['total_cost'], 4) . ' cost</div>';
                }
            } catch (Throwable $e) {}
            ?>

            <!-- Custom instructions — n8n-style behaviour config -->
            <div style="border-top:1px solid #ddd6fe;padding-top:14px;margin-top:4px;">

                <?php
                $_builtInText = htmlspecialchars(
"You are Dee, a WhatsApp support agent for DishNet Africa (Juba, South Sudan).

PERSONA
- Name: Dee. Warm, professional, straight to the point — like a helpful colleague, not a bot.
- Not a menu system. Have real, natural conversations.
- Match the customer's language (English, Arabic, or mix).
- 1-2 emojis max, only when natural. Never start with 'Of course!', 'Certainly!', 'Great question!'.

ROLE
SUPPORT line: internet problems, upgrades, new connections.
ACCOUNTS line: bills, payments, balances, invoices.

CUSTOMER ACCOUNT (injected live from CRM)
- Customer name, service type, plan, balance, account status (Active/Suspended), last payment.
- Plan expiry date: bot warns automatically — 7 days (gentle), 3 days (urgent), expired (block troubleshooting).
- If suspended: NEVER do technical troubleshooting — direct to accounts +211 927 797 217.
- If account not found: can still give basic steps, ask them to call to locate account.

FIBER — LIVE SPLYNX DATA (injected in real time)
- Line status: ONLINE or OFFLINE (from Splynx PPPoE session).
- If ONLINE: fault is AFTER the router (WiFi/device). Do NOT ask to check ONT lights.
- If OFFLINE: guide ONT light check. LOS red = escalate immediately for tech visit.
- DishNet topology: [Fiber line] -> [ONT] -> [DishNet PPPoE router] -> [Customer WiFi/devices]
- DishNet manages all PPPoE and router config. Customers do NOT set up PPPoE themselves.

STARLINK
- DishNet manages ALL Starlink WiFi settings. Customers have NO Starlink app access.
- WiFi password/name change: ask what they want, tell them team will update it — done.
- After any WiFi change: all devices disconnect. Always warn them.
- Never ask for current password. Never suggest 192.168.1.1 or Starlink app.
- Dish 'Searching' > 5 min: obstruction (trees/buildings). Rain: temporary fade, self-recovers.
- Capped plans (50GB/100GB/150GB): slow near month end = data exhausted, not a fault.

LTE / 4G
1. Confirm plan is active and not expired first.
2. No bars: coverage issue, ask location.
3. Has bars, no data: restart, airplane mode 10s, reinsert SIM. APN = 'internet'.
4. Test SIM in another device to isolate SIM vs device.

SOUTH SUDAN CONTEXT
- Power cuts: restart equipment first — most common fix in Juba.
- Generator switch: brief drop, self-recovers in ~5 min, normal.
- Rain: Starlink rain fade, temporary. Overheating: move router/dish from sun.
- Peak hours 6-10 PM: congestion is normal on all services.

PLANS & PRICING
Starlink: \$65 (50GB), \$80 (100GB), \$112 (150GB), \$189 (Unlimited Standard), \$218 (Priority).
Fiber: \$50 / \$75 / \$100 per month.
LTE: \$25 Silver, \$40 Gold, \$80 Platinum, \$110-120 Diamond, \$200-250 Enterprise.

CONTACTS
Support/Accounts: +211 927 797 217
Sales (new connections): +211 923 400 000
Office: Airport Road, Kololo Area, opposite the Ministries, Juba — 8 AM-8 PM daily.
Website: dishnetafrica.com

ESCALATE TO HUMAN WHEN
- LOS light red on fiber ONT (needs tech visit)
- Starlink not connecting after full restart
- LTE bars but no data after all steps
- Account suspended: direct to accounts team
- Customer frustrated or issue repeating
- Billing dispute, refund, cancellation
Say: I'm flagging this to our team now. Someone will follow up within the hour.

AUTO-ESCALATION
After 6 bot turns without resolution, automatically creates a CRM job for Bidal.

REPLY STYLE
- 3-5 lines max. Ask ONE question at a time.
- Acknowledge frustration before jumping to steps.
- Never repeat info already given in conversation history.
- Strict data protection: you only know the ONE customer in your context."
                ); ?>
                                <!-- Built-in instructions viewer — collapsible -->
                <div style="margin-bottom:12px;">
                    <button type="button" onclick="toggleBuiltIn()"
                        style="display:flex;align-items:center;gap:8px;width:100%;background:#f5f3ff;border:1.5px solid #ddd6fe;border-radius:10px;padding:10px 14px;cursor:pointer;font-size:12px;font-weight:700;color:#6d28d9;text-align:left;">
                        <span id="builtInChevron" style="font-size:14px;transition:.2s;">▶</span>
                        📋 View Built-in Instructions (what the bot already follows)
                        <span style="margin-left:auto;font-size:10px;font-weight:600;color:#9333ea;background:#ede9fe;border-radius:6px;padding:2px 8px;">READ-ONLY</span>
                    </button>
                    <div id="builtInPanel" style="display:none;margin-top:6px;">
                        <div style="background:#1e293b;border-radius:10px;padding:2px;">
                            <textarea readonly rows="22"
                                style="width:100%;box-sizing:border-box;background:#1e293b;color:#e2e8f0;border:none;border-radius:10px;padding:14px;font-size:11px;font-family:'Courier New',monospace;line-height:1.7;resize:vertical;outline:none;"
                                onclick="this.select()"
                            ><?= $_builtInText ?></textarea>
                        </div>
                        <div style="font-size:10px;color:#94a3b8;margin-top:4px;padding:0 4px;">
                            💡 This is a summary of the built-in system prompt. The full prompt includes live CRM data, Splynx fiber status, and conversation history injected automatically on every message.
                            Click the textarea to select all and copy.
                        </div>
                    </div>
                </div>

                <!-- Mode selector -->
                <?php $_instrMode = $config['bot_instructions_mode'] ?? 'append'; ?>
                <div style="margin-bottom:10px;">
                    <label style="display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:6px;">How should your custom instructions work?</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;cursor:pointer;font-size:12px;font-weight:700;border:2px solid <?= $_instrMode==='append'?'#6366f1':'#e2e8f0' ?>;background:<?= $_instrMode==='append'?'#ede9fe':'#fff' ?>;color:<?= $_instrMode==='append'?'#4f46e5':'#64748b' ?>;">
                            <input type="radio" name="bot_instructions_mode" value="append" <?= $_instrMode==='append'?'checked':'' ?> style="accent-color:#6366f1;" onchange="updateModeHint(this.value)">
                            ➕ Add to built-in
                            <span style="font-size:10px;font-weight:600;opacity:.7;">(recommended)</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;cursor:pointer;font-size:12px;font-weight:700;border:2px solid <?= $_instrMode==='override'?'#dc2626':'#e2e8f0' ?>;background:<?= $_instrMode==='override'?'#fee2e2':'#fff' ?>;color:<?= $_instrMode==='override'?'#991b1b':'#64748b' ?>;">
                            <input type="radio" name="bot_instructions_mode" value="override" <?= $_instrMode==='override'?'checked':'' ?> style="accent-color:#dc2626;" onchange="updateModeHint(this.value)">
                            🔄 Replace built-in entirely
                            <span style="font-size:10px;font-weight:600;opacity:.7;">(advanced)</span>
                        </label>
                    </div>
                    <div id="modeHint" style="margin-top:6px;font-size:11px;padding:6px 10px;border-radius:8px;font-weight:600;
                        background:<?= $_instrMode==='override'?'#fee2e2':'#f0fdf4' ?>;
                        color:<?= $_instrMode==='override'?'#991b1b':'#065f46' ?>;">
                        <?php if($_instrMode==='override'): ?>
                        ⚠️ Override mode: ONLY your text below is sent to the AI — all DishNet troubleshooting, CRM data injection, and escalation rules are disabled. Use only if you want full control.
                        <?php else: ?>
                        ✅ Append mode: your instructions are added AFTER the built-in DishNet knowledge base. Built-in CRM data, Splynx status, and escalation rules still apply.
                        <?php endif; ?>
                    </div>
                </div>

                <!-- The instructions textarea -->
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:4px;">
                    📝 Your Custom Instructions
                    <span style="font-size:10px;font-weight:600;color:#94a3b8;margin-left:6px;">plain English — takes effect on next customer message</span>
                </label>
                <div style="font-size:11px;color:#6b7280;margin-bottom:8px;">
                    Examples of what you can write:
                    <ul style="margin:4px 0 0 16px;padding:0;line-height:1.8;">
                        <li><em style="color:#7c3aed;">Always reply in Arabic first, then English.</em></li>
                        <li><em style="color:#7c3aed;">We are running 10% discount on fiber until 30 April 2026. Mention this when customers ask about pricing.</em></li>
                        <li><em style="color:#7c3aed;">Do not discuss any competitor ISPs (Gemtel, Vivacell, etc).</em></li>
                        <li><em style="color:#7c3aed;">If a customer asks about Gudele coverage, tell them fiber is coming by June 2026.</em></li>
                        <li><em style="color:#7c3aed;">Always end conversations with: "Is there anything else I can help you with?"</em></li>
                        <li><em style="color:#7c3aed;">Office is closed on Fridays. Tell customers to call on Saturday.</em></li>
                    </ul>
                </div>
                <textarea name="bot_custom_instructions" id="customInstrTA" rows="8"
                    style="width:100%;box-sizing:border-box;border:1.5px solid #e0e7ff;border-radius:10px;padding:12px;font-size:13px;line-height:1.7;resize:vertical;font-family:inherit;outline:none;transition:.2s;"
                    placeholder="Write your instructions here in plain English. The AI will follow them on every conversation."
                    onfocus="this.style.borderColor='#6366f1'"
                    onblur="this.style.borderColor='#e0e7ff'"
                ><?= h($_customInstr) ?></textarea>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;flex-wrap:wrap;gap:6px;">
                    <small class="text-muted">Changes save when you click <strong>Save Settings</strong> below. Takes effect immediately on next customer message — no restart needed.</small>
                    <button type="button" onclick="document.getElementById('customInstrTA').value=''"
                        style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:4px 10px;font-size:11px;font-weight:600;color:#64748b;cursor:pointer;">
                        Clear
                    </button>
                </div>
            </div>

            <script>
            function toggleBuiltIn(){
                var p=document.getElementById('builtInPanel');
                var c=document.getElementById('builtInChevron');
                var shown=p.style.display!=='none';
                p.style.display=shown?'none':'block';
                c.textContent=shown?'▶':'▼';
            }
            function updateModeHint(val){
                var h=document.getElementById('modeHint');
                if(val==='override'){
                    h.style.background='#fee2e2';h.style.color='#991b1b';
                    h.innerHTML='⚠️ Override mode: ONLY your text below is sent to the AI — all DishNet troubleshooting, CRM data injection, and escalation rules are disabled. Use only if you want full control.';
                } else {
                    h.style.background='#f0fdf4';h.style.color='#065f46';
                    h.innerHTML='✅ Append mode: your instructions are added AFTER the built-in DishNet knowledge base. Built-in CRM data, Splynx status, and escalation rules still apply.';
                }
                // Update label colors
                document.querySelectorAll('input[name="bot_instructions_mode"]').forEach(function(r){
                    var lbl=r.parentElement;
                    var isSelected=r.value===val;
                    var isOverride=r.value==='override';
                    lbl.style.borderColor=isSelected?(isOverride?'#dc2626':'#6366f1'):'#e2e8f0';
                    lbl.style.background=isSelected?(isOverride?'#fee2e2':'#ede9fe'):'#fff';
                    lbl.style.color=isSelected?(isOverride?'#991b1b':'#4f46e5'):'#64748b';
                });
            }
            </script>
        </div>
    </div>
</div>

<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-key-fill" style="color:#128c7e;"></i> WASender API Credentials
    </div>
    <div style="padding:18px;">
        <?php if($waOk): ?><div style="background:#dcfce7;border:1px solid #86efac;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#15803d;font-weight:600;">✅ Connected — App Key and Auth Key configured. Notifications active.</div><?php else: ?><div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400e;">⚠ Credentials missing — enter App Key + Auth Key to enable notifications.</div><?php endif; ?>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">WASender Server URL</label>
            <input type="url" name="wa_plugin_url" class="form-control" value="<?= h($config['wa_plugin_url'] ?: 'http://wa.dishnetafrica.com') ?>" placeholder="http://wa.dishnetafrica.com">
            <small class="text-muted">Base URL only. Plugin calls <code>{URL}/api/whatsapp-web/send-message</code></small>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#1d4ed8;margin-bottom:5px;">🛠 Support App Key</label>
                <input type="text" name="wa_app_key" class="form-control" autocomplete="off" value="<?= h($config['wa_app_key'] ?? '') ?>" placeholder="2313647c-...">
                <small class="text-muted">WASender → Apps → "Suport" app → App Key</small>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#065f46;margin-bottom:5px;">💼 Accounts App Key</label>
                <input type="text" name="wa_accounts_app_key" class="form-control" autocomplete="off" value="<?= h($config['wa_accounts_app_key'] ?? '') ?>" placeholder="6b930efb-...">
                <small class="text-muted">WASender → Apps → "ACcount" app → App Key</small>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">🔑 Auth Key <span style="font-weight:400;color:#9ca3af;">(shared)</span></label>
                <input type="text" name="wa_auth_key" class="form-control" autocomplete="off" value="<?= h($config['wa_auth_key'] ?? '') ?>" placeholder="OCshy4x...">
                <small class="text-muted">User-level key — same for all apps</small>
            </div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:11px;color:#1d4ed8;">
            <strong>How it works:</strong> Each WASender "App" is bound to one phone number. The <strong>Support App Key</strong> routes messages to the Support number, the <strong>Accounts App Key</strong> routes to the Accounts number. The Auth Key is your user credential — shared across all apps.
        </div>
        <!-- v4.21.114: emergency "route all via Accounts" toggle -->
        <?php $_faOn = ($config['wa_force_accounts'] ?? false) === true || ($config['wa_force_accounts'] ?? '') === '1'; ?>
        <div id="faToggleWrap" style="background:<?= $_faOn ? '#fffbeb' : '#f8fafc' ?>;border:1px solid <?= $_faOn ? '#fcd34d' : '#e2e8f0' ?>;border-radius:10px;padding:12px 14px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
            <div style="padding-right:12px;">
                <div style="font-size:12px;font-weight:700;color:#374151;">🚨 Route ALL messages via Accounts number</div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;">Turn ON when the Support WhatsApp is blocked. Every notification — Support and Accounts — goes out on the Accounts app key/number. Takes effect on the next message, no restart. <strong>Turn OFF once Support recovers.</strong></div>
                <?php if ($_faOn): ?><div style="font-size:11px;color:#b45309;font-weight:700;margin-top:4px;">⚠ ACTIVE — all traffic is currently on the Accounts number. Watch for over-volume; get Support unblocked or a new number provisioned.</div><?php endif; ?>
            </div>
            <div style="position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0;margin-left:12px;cursor:pointer;">
                <input type="hidden" name="wa_force_accounts" value="0">
                <input type="checkbox" name="wa_force_accounts" value="1" id="faToggleCb" <?= $_faOn ? 'checked' : '' ?> style="opacity:0;width:0;height:0;position:absolute;">
                <span id="faToggleTrack" style="position:absolute;top:0;left:0;right:0;bottom:0;background:<?= $_faOn ? '#f59e0b' : '#cbd5e1' ?>;border-radius:24px;transition:.3s;cursor:pointer;"></span>
                <span id="faToggleKnob" style="position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:.3s;transform:<?= $_faOn ? 'translateX(20px)' : 'translateX(0)' ?>;pointer-events:none;"></span>
            </div>
        </div>
        <script>
        (function(){
            var cb=document.getElementById('faToggleCb'),
                tr=document.getElementById('faToggleTrack'),
                kn=document.getElementById('faToggleKnob'),
                wr=document.getElementById('faToggleWrap');
            if(!cb||!tr||!kn||!wr)return;
            cb.addEventListener('change',function(){
                var on=cb.checked;
                tr.style.background=on?'#f59e0b':'#cbd5e1';
                kn.style.transform=on?'translateX(20px)':'translateX(0)';
                wr.style.background=on?'#fffbeb':'#f8fafc';
                wr.style.borderColor=on?'#fcd34d':'#e2e8f0';
            });
            tr.addEventListener('click',function(){cb.checked=!cb.checked;cb.dispatchEvent(new Event('change'));});
        })();
        </script>
        <!-- v4.9.20: PDF document sending toggle -->
        <?php $_pdfOn = ($config['wa_send_pdf'] ?? true) !== false; ?>
        <div id="pdfToggleWrap" style="background:<?= $_pdfOn ? '#f0fdf4' : '#fef2f2' ?>;border:1px solid <?= $_pdfOn ? '#bbf7d0' : '#fecaca' ?>;border-radius:10px;padding:12px 14px;margin-top:10px;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="font-size:12px;font-weight:700;color:#374151;">PDF Documents via WhatsApp</div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;">Send invoice/quote PDFs as WhatsApp attachments. Turn off to send text-only notifications.</div>
            </div>
            <div style="position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0;margin-left:12px;cursor:pointer;">
                <input type="hidden" name="wa_send_pdf" value="0">
                <input type="checkbox" name="wa_send_pdf" value="1" id="pdfToggleCb" <?= $_pdfOn ? 'checked' : '' ?> style="opacity:0;width:0;height:0;position:absolute;">
                <span id="pdfToggleTrack" style="position:absolute;top:0;left:0;right:0;bottom:0;background:<?= $_pdfOn ? '#22c55e' : '#ef4444' ?>;border-radius:24px;transition:.3s;cursor:pointer;"></span>
                <span id="pdfToggleKnob" style="position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:.3s;transform:<?= $_pdfOn ? 'translateX(20px)' : 'translateX(0)' ?>;pointer-events:none;"></span>
            </div>
        </div>
        <script>
        (function(){
            var cb=document.getElementById('pdfToggleCb'),
                tr=document.getElementById('pdfToggleTrack'),
                kn=document.getElementById('pdfToggleKnob'),
                wr=document.getElementById('pdfToggleWrap');
            if(!cb||!tr||!kn||!wr)return;
            cb.addEventListener('change',function(){
                var on=cb.checked;
                tr.style.background=on?'#22c55e':'#ef4444';
                kn.style.transform=on?'translateX(20px)':'translateX(0)';
                wr.style.background=on?'#f0fdf4':'#fef2f2';
                wr.style.borderColor=on?'#bbf7d0':'#fecaca';
            });
            tr.addEventListener('click',function(){cb.checked=!cb.checked;cb.dispatchEvent(new Event('change'));});
        })();
        </script>
    </div>
</div>

<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-phone-fill" style="color:#128c7e;"></i> Phone Numbers
    </div>
    <div style="padding:18px;">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#1d4ed8;">
            <strong>Two numbers, two purposes.</strong> Support = ops events (KYC, wallet, leads, handover). Accounts = billing events (invoices, payments, overdue reminders).
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#1d4ed8;margin-bottom:5px;">🛠 Support Number</label>
                <input type="text" name="wa_support_number" class="form-control" value="<?= h($config['wa_support_number']??'') ?>" placeholder="211921443006">
                <small class="text-muted">KYC, wallet, leads, handover, SIM activations</small>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#065f46;margin-bottom:5px;">💼 Accounts Number</label>
                <input type="text" name="wa_accounts_number" class="form-control" value="<?= h($config['wa_accounts_number']??'') ?>" placeholder="211921443002">
                <small class="text-muted">Invoices, payments, overdue reminders</small>
            </div>
        </div>
        <div style="max-width:360px;">
            <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">🔔 Admin Alert Number (Rupesh)</label>
            <input type="text" name="whatsapp_admin_phone" class="form-control" value="<?= h($config['whatsapp_admin_phone']??'') ?>" placeholder="211921443002">
            <small class="text-muted">Receives: KYC submitted, recharge requests, handover alerts</small>
        </div>
    </div>
</div>

<!-- ── Evolution API (Marketing WhatsApp) ──────────────────────────────── -->
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;gap:8px;">
        <span style="color:#7C3AED;">📡</span> Evolution API (Marketing / Sales WhatsApp)
    </div>
    <div style="padding:18px;">
        <div style="background:#fff8e1;border:1px solid #f0c040;border-radius:6px;padding:12px 14px;margin-bottom:14px;font-size:14px"><b>These fields no longer save.</b> Evolution API settings moved to <a href="?page=dashboard&amp;tab=wa_ai_setup"><b>WhatsApp &rarr; WhatsApp AI</b></a>, which also detects your instances, shows the pairing QR and registers the webhook. Both screens used to write the same keys, so saving here could overwrite a working setup.</div><div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#6d28d9;">
            <strong>Sync conversations from your Evolution API instance.</strong> Messages are stored locally for conversation history, search, and AI training data.
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Evolution API URL</label>
                <input type="url" name="evo_api_url" class="form-control" value="<?= h($config['evo_api_url'] ?? '') ?>" placeholder="https://evo-evolution-api.4zz82b.easypanel.host">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Instance Name (Support)</label>
                <input type="text" name="evo_instance_name" class="form-control" value="<?= h($config['evo_instance_name'] ?? '') ?>" placeholder="sales">
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Instance Name (Accounts) <span style="font-size:10px;color:#d97706;">for auto-reply toggle</span></label>
                <input type="text" name="evo_accounts_instance_name" class="form-control" value="<?= h($config['evo_accounts_instance_name'] ?? '') ?>" placeholder="accounts">
                <small class="text-muted">Leave empty if one Evolution instance only</small>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Channel Label</label>
                <input type="text" name="evo_channel_name" class="form-control" value="<?= h($config['evo_channel_name'] ?? 'marketing') ?>" placeholder="marketing">
                <small class="text-muted">Tag in conversation store</small>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">🔑 API Key</label>
                <input type="text" name="evo_api_key" class="form-control" autocomplete="off" value="<?= h($config['evo_api_key'] ?? '') ?>" placeholder="C1A7C0DD-..." style="font-size:11px;">
            </div>
            <div style="display:flex;align-items:flex-end;gap:8px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="evo_auto_reply_enabled" <?= !empty($config['evo_auto_reply_enabled']) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#7C3AED;">
                    <span style="font-size:12px;font-weight:700;color:#374151;">Enable auto-reply templates on incoming messages</span>
                </label>
            </div>
        </div>
        <?php
        // Test connection if configured
        $evoStatus = null;
        if (!empty($config['evo_api_url']) && !empty($config['evo_api_key']) && !empty($config['evo_instance_name'])) {
            require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
            $evoTest = new EvolutionApiClient($config['evo_api_url'], $config['evo_api_key'], $config['evo_instance_name']);
            try {
                $evoConn = $evoTest->connectionState();
                $evoStatus = $evoConn['state'] ?? $evoConn['instance']['state'] ?? 'unknown';
            } catch (Throwable $e) {
                $evoStatus = 'error: ' . $e->getMessage();
            }
        }
        ?>
        <?php if ($evoStatus): ?>
        <div style="background:<?= $evoStatus === 'open' ? '#dcfce7' : '#fee2e2' ?>;border:1px solid <?= $evoStatus === 'open' ? '#86efac' : '#fca5a5' ?>;border-radius:10px;padding:10px 14px;font-size:12px;color:<?= $evoStatus === 'open' ? '#15803d' : '#991b1b' ?>;font-weight:600;">
            <?= $evoStatus === 'open' ? '✅ Connected — Instance "' . h($config['evo_instance_name']) . '" is online' : '⚠ Status: ' . h($evoStatus) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<button type="submit" style="display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#128c7e,#075e54);color:#fff;border:none;border-radius:12px;padding:12px 28px;font-size:14px;font-weight:800;cursor:pointer;width:100%;justify-content:center;">
    <i class="bi bi-check2-circle" style="font-size:18px;"></i> Save Configuration
</button>
</form>

<!-- ════════════════════════════════════════════════════
     SUBTAB: Event Map + CRUD
     ════════════════════════════════════════════════════ -->
<?php elseif($waSubtab === 'events'): ?>

<!-- Duplicate Prevention Warning -->
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:14px 16px;margin-bottom:14px;font-size:12px;">
    <div style="display:flex;align-items:flex-start;gap:10px;">
        <span style="font-size:18px;">⚠️</span>
        <div>
            <div style="font-weight:700;color:#92400e;margin-bottom:4px;">Duplicate Prevention Active</div>
            <div style="color:#78350f;">
                <strong>ops_invoice_created</strong> and <strong>ops_payment_received</strong> are <span style="background:#fee2e2;color:#991b1b;padding:1px 6px;border-radius:4px;font-weight:700;">OFF by default</span> because UCRM already sends these via WASender (Accounts number).<br>
                Enable them only if you've disabled UCRM's notifications, or customer will receive duplicate messages.
            </div>
        </div>
    </div>
</div>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#1d4ed8;">
    <strong>Two notification paths — they don't conflict:</strong><br>
    ① <strong>UCRM Direct → WASender (Accounts)</strong> — billing events (invoice, payment, suspend) — configured in UCRM → System → Notifications<br>
    ② <strong>Plugin → WASender (Support/Accounts)</strong> — internal ops (KYC, wallet, handover, lead, LTE) — configured here
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <div style="font-size:13px;font-weight:800;color:#1e293b;">📋 <?= count($waEventDefs) ?> events · <?= count($waTpls) ?> customised</div>
    <div style="font-size:11px;color:#9ca3af;">Click any row to view / edit the message</div>
</div>

<!-- Events table -->
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
<table class="wa2-tbl">
<thead><tr>
    <th>Event</th>
    <th>When Triggered</th>
    <th>Recipient</th>
    <th>Handled By</th>
    <th>Sender</th>
    <th>Status</th>
    <th></th>
</tr></thead>
<tbody>
<?php foreach($waEventDefs as $evtKey => $def):
    $custom   = $waTpls[$evtKey] ?? null;
    $sender   = $custom['sender']  ?? $def['default_sender'];
    // Check default_enabled field for events that should be OFF by default (to avoid UCRM duplicates)
    $defaultEnabled = $def['default_enabled'] ?? true;
    $enabled  = $custom ? (bool)($custom['enabled'] ?? 1) : $defaultEnabled;
    $body     = $custom['body']    ?? $def['default_body'];
    $isCustom = ($custom !== null);
    $ucrmHandles = $def['ucrm_handles'] ?? null;
    $preview  = mb_substr(str_replace(["\n","*"], [' ',''], $body), 0, 45) . '…';
    
    // Determine who handles this event
    if ($ucrmHandles && !$enabled) {
        $handledBy = 'ucrm';      // UCRM handles, plugin OFF
    } elseif ($ucrmHandles && $enabled) {
        $handledBy = 'both';      // Both sending = DUPLICATE!
    } else {
        $handledBy = 'plugin';    // Plugin only
    }
?>
<tr class="<?= $isCustom?'customised':'' ?><?= $handledBy==='both'?' dup-warning':'' ?>" style="cursor:pointer;" onclick="waOpenEdit('<?= h(addslashes($evtKey)) ?>')">
    <td>
        <div style="font-weight:700;color:#1e293b;"><?= h($def['label']) ?></div>
        <div style="font-size:10px;font-family:monospace;color:#9ca3af;"><?= h($evtKey) ?></div>
    </td>
    <td style="color:#64748b;font-size:11px;"><?= h($def['trigger']) ?></td>
    <td style="font-weight:600;color:#065f46;font-size:11px;"><?= h($def['recipient']) ?></td>
    <td>
        <?php if($handledBy === 'ucrm'): ?>
        <span class="wa2-badge" style="background:#dbeafe;color:#1e40af;">🏢 UCRM</span>
        <?php elseif($handledBy === 'both'): ?>
        <span class="wa2-badge" style="background:#fee2e2;color:#991b1b;">⚠️ BOTH</span>
        <?php else: ?>
        <span class="wa2-badge" style="background:#d1fae5;color:#065f46;">🔌 Plugin</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if($sender === 'support'): ?>
        <span class="wa2-badge wa2-supp">🛠 Support</span>
        <?php else: ?>
        <span class="wa2-badge wa2-acct">💼 Accounts</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if(!$enabled): ?>
        <span class="wa2-badge wa2-off">⏸ off</span>
        <?php else: ?>
        <span class="wa2-badge wa2-on">▶ on</span>
        <?php endif; ?>
    </td>
    <td onclick="event.stopPropagation()">
        <button class="wa2-edit-btn" onclick="waOpenEdit('<?= h(addslashes($evtKey)) ?>')">Edit ✏</button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ════════════════════════════════════════════════════
     SUBTAB: Message Log
     ════════════════════════════════════════════════════ -->
<?php elseif($waSubtab === 'log'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <div style="font-size:13px;font-weight:800;color:#1e293b;">📊 Last <?= count($waNotifLog) ?> messages sent by this plugin</div>
    <?php
    $totalSent = count($waNotifLog);
    $totalOk   = count(array_filter($waNotifLog, fn($l)=>!empty($l['success'])));
    $totalFail = $totalSent - $totalOk;
    ?>
    <div style="display:flex;gap:8px;font-size:11px;">
        <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:8px;font-weight:700;">✓ <?= $totalOk ?> sent</span>
        <?php if($totalFail>0): ?><span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:8px;font-weight:700;">✗ <?= $totalFail ?> failed</span><?php endif; ?>
    </div>
</div>

<?php if(empty($waNotifLog)): ?>
<div style="text-align:center;padding:40px;color:#9ca3af;font-size:13px;background:#fff;border-radius:16px;border:1px dashed #e2e8f0;">
    No messages sent yet.
</div>
<?php else: ?>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
        <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">Time</th>
        <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">Status</th>
        <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">From</th>
        <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">To</th>
        <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">Event</th>
        <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">Preview</th>
        <th style="padding:8px 12px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">HTTP</th>
    </tr></thead>
    <tbody>
    <?php foreach($waNotifLog as $i=>$nl):
        $ok  = !empty($nl['success']);
        $snd = $nl['sender'] ?? 'support';
    ?>
    <tr style="background:<?= $i%2===0?'#fff':'#fafafa' ?>;border-bottom:1px solid #f1f5f9;">
        <td style="padding:8px 12px;color:#9ca3af;white-space:nowrap;"><?= h(substr($nl['sent_at']??'',0,16)) ?></td>
        <td style="padding:8px 12px;"><span class="<?= $ok?'wa2-log-ok':'wa2-log-fail' ?>"><?= $ok?'✓ sent':'✗ fail' ?></span></td>
        <td style="padding:8px 12px;">
            <?php if($snd==='accounts'): ?>
            <span class="wa2-badge wa2-acct">💼</span>
            <?php else: ?>
            <span class="wa2-badge wa2-supp">🛠</span>
            <?php endif; ?>
        </td>
        <td style="padding:8px 12px;font-family:monospace;color:#374151;"><?= h($nl['to']??'') ?></td>
        <td style="padding:8px 12px;font-family:monospace;font-size:11px;color:#6b7280;"><?= h($nl['event']??'') ?></td>
        <td style="padding:8px 12px;color:#374151;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($nl['preview']??'') ?></td>
        <td style="padding:8px 12px;">
            <span style="color:<?= $ok?'#15803d':'#991b1b' ?>;font-weight:700;"><?= h((string)($nl['http_code']??'—')) ?></span>
            <?php if(!$ok && !empty($nl['error'])): ?>
            <div style="font-size:10px;color:#dc2626;"><?= h(substr($nl['error'],0,40)) ?></div>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════
     SUBTAB: UCRM Webhook
     ════════════════════════════════════════════════════ -->
<?php elseif($waSubtab === 'webhook'): ?>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:12px;color:#1d4ed8;">
    <strong>What this does:</strong> UCRM can send event webhooks (invoice, payment, suspension) directly to <code>webhook.php</code> in this plugin.
    This is an <em>optional second layer</em> — the WASender plugin already handles those automatically. Only configure this if you want extra custom messages, internal ops logging, or custom actions on UCRM events.
</div>

<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:18px;margin-bottom:14px;">
    <div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:12px;">📋 How to wire it up in UCRM</div>
    <ol style="margin:0;padding-left:18px;font-size:12px;line-height:2.2;color:#374151;">
        <li>Go to <strong>UCRM → System → Webhooks → Add Webhook</strong></li>
        <li>URL:
            <code style="background:#f8fafc;padding:3px 8px;border-radius:6px;font-size:11px;border:1px solid #e2e8f0;">
                <?= h('https://crm.dishnetafrica.com/_plugins/dishnet-hybrid-telecom/webhook.php') ?>
            </code>
            <button onclick="navigator.clipboard.writeText('https://crm.dishnetafrica.com/_plugins/dishnet-hybrid-telecom/webhook.php');this.textContent='✓';setTimeout(()=>this.textContent='Copy',2000)"
                style="margin-left:6px;background:#075e54;color:#fff;border:none;border-radius:6px;padding:2px 10px;font-size:11px;cursor:pointer;">Copy</button>
        </li>
        <li>Events: <code>client.add</code>, <code>invoice.add</code>, <code>payment.add</code>, <code>service.suspend</code>, <code>service.unsuspend</code>, <code>service.end</code>, <code>quote.approve</code>, <code>ticket.add</code></li>
        <li>Webhook Secret: paste your secret below (generate one if empty)</li>
    </ol>
</div>

<?php if(!empty($whLog)): ?>
<div style="font-size:13px;font-weight:800;color:#1e293b;margin-bottom:10px;">📥 Recent UCRM webhook events (last <?= count($whLog) ?>)</div>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
<?php foreach($whLog as $i=>$wh): ?>
<div style="display:flex;gap:10px;align-items:baseline;padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:12px;background:<?= $i%2===0?'#fff':'#fafafa' ?>;">
    <span style="color:#9ca3af;white-space:nowrap;font-size:11px;"><?= h(substr($wh['received_at']??'',0,16)) ?></span>
    <span style="background:#dbeafe;color:#1d4ed8;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:700;flex-shrink:0;"><?= h($wh['event']??'?') ?></span>
    <span style="color:#374151;"><?= h($wh['message']??'') ?></span>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div style="text-align:center;padding:30px;color:#9ca3af;font-size:12px;background:#fff;border-radius:16px;border:1px dashed #e2e8f0;">
    No webhook events received yet. Once you wire up UCRM → Webhooks, events appear here.
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════
     SUBTAB: Google Drive Backup (aaPanel-style)
     ════════════════════════════════════════════════════ -->
<?php elseif($waSubtab === 'gdrive'): ?>
<?php
    if (!$gdBackupSvc) {
        require_once dirname(__DIR__, 2) . '/lib/GoogleDriveBackup.php';
        $gdBackupSvc = new GoogleDriveBackup($dataDir);
    }
    $gdConf        = $gdBackupSvc->getConfig();
    $gdAuthorized  = $gdBackupSvc->isAuthorized();
    $gdConfigured  = $gdBackupSvc->isConfigured();
    $gdLastBackup  = $gdConf['last_backup'] ?? null;
    $gdLogs        = $gdBackupSvc->getRecentLogs(20);
    $gdDriveFiles  = [];
    $gdConnInfo    = null;

    // Fetch Drive files and connection info if authorized
    if ($gdAuthorized) {
        $gdConnInfo   = $gdBackupSvc->testConnection();
        $gdDriveFiles = $gdBackupSvc->listBackups();
    }
?>

<!-- Google Drive Hero -->
<div style="background:linear-gradient(135deg,#4285f4 0%,#34a853 50%,#fbbc04 75%,#ea4335 100%);border-radius:18px;padding:22px 24px;color:#fff;margin-bottom:18px;position:relative;overflow:hidden;">
    <div style="position:absolute;top:-40px;right:-40px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.08);"></div>
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
        <div style="font-size:36px;line-height:1;">
            <svg viewBox="0 0 87.3 78" width="36" height="32"><path d="M6.6 66.85L3.3 72.1 15.25 72.1 18.6 66.85z" fill="#0066DA"/><path d="M43.65 25.5L29.7 0 15.8 0 43.65 48.35z" fill="#00AC47"/><path d="M43.65 25.5L57.6 0 71.5 0 43.65 48.35z" fill="#EA4335"/><path d="M72 66.85L75.3 72.1 87.3 48.35 80.65 48.35z" fill="#00832D"/><path d="M43.65 48.35L57.6 72.1 71.5 72.1 43.65 25.5z" fill="#2684FC"/><path d="M15.8 0L0 25.5 6.6 48.35 43.65 25.5z" fill="#FFBA00"/></svg>
        </div>
        <div>
            <div style="font-size:22px;font-weight:900;">Google Drive Backup</div>
            <div style="font-size:12px;opacity:.85;">Automatic cloud backup · aaPanel-style OAuth2 · Keep your data safe</div>
        </div>
        <div style="margin-left:auto;">
            <?php if($gdAuthorized): ?>
            <span style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:800;">● Connected<?php if($gdConnInfo && $gdConnInfo['ok']): ?> — <?= h($gdConnInfo['email'] ?? '') ?><?php endif; ?></span>
            <?php elseif($gdConfigured): ?>
            <span style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:800;">⚠ Not Authorized</span>
            <?php else: ?>
            <span style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);padding:6px 16px;border-radius:20px;font-size:12px;font-weight:800;">⚙ Setup Required</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if(!$gdConfigured): ?>
<!-- ── SETUP GUIDE ─── -->
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;padding:18px 20px;margin-bottom:18px;">
    <div style="font-size:14px;font-weight:800;color:#1d4ed8;margin-bottom:12px;">📋 First-time Setup — Create Google Cloud Credentials</div>
    <ol style="margin:0;padding-left:18px;font-size:12px;line-height:2.4;color:#374151;">
        <li>Go to <a href="https://console.cloud.google.com/projectcreate" target="_blank" style="color:#4285f4;font-weight:700;">Google Cloud Console</a> → Create a new project (e.g. "DishNet Backup")</li>
        <li>Enable the <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" style="color:#4285f4;font-weight:700;">Google Drive API</a></li>
        <li>Go to <strong>APIs & Services → OAuth consent screen</strong> → Choose "External" → Fill app name → Add your email as test user</li>
        <li>Go to <strong>APIs & Services → Credentials → Create Credentials → OAuth client ID</strong></li>
        <li>Application type: <strong>Desktop app</strong> (not Web application)</li>
        <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> and paste below</li>
    </ol>
</div>
<?php endif; ?>

<!-- ── CREDENTIALS FORM ─── -->
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;gap:8px;">
        <span style="color:#4285f4;">🔑</span> Google OAuth2 Credentials
    </div>
    <div style="padding:18px;">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="wa_action" value="gdrive_save_config">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Client ID</label>
                    <input type="text" name="gdrive_client_id" class="form-control" value="<?= h($gdConf['client_id'] ?? '') ?>" placeholder="123456789-xxxx.apps.googleusercontent.com" style="font-size:11px;">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Client Secret</label>
                    <input type="text" name="gdrive_client_secret" class="form-control" value="<?= h($gdConf['client_secret'] ?? '') ?>" placeholder="GOCSPX-xxxxxxxxxxxx" style="font-size:11px;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Drive Folder Name</label>
                    <input type="text" name="gdrive_folder_name" class="form-control" value="<?= h($gdConf['folder_name'] ?? 'DishNet-Backups') ?>" placeholder="DishNet-Backups">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Keep Last N Backups</label>
                    <input type="number" name="gdrive_retention" class="form-control" value="<?= (int)($gdConf['retention_count'] ?? 7) ?>" min="1" max="30">
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Schedule</label>
                    <select name="gdrive_schedule" class="form-control">
                        <option value="daily" <?= ($gdConf['schedule'] ?? 'daily') === 'daily' ? 'selected' : '' ?>>Daily (3 AM)</option>
                        <option value="twice_daily" <?= ($gdConf['schedule'] ?? '') === 'twice_daily' ? 'selected' : '' ?>>Twice Daily (3 AM + 3 PM)</option>
                        <option value="weekly" <?= ($gdConf['schedule'] ?? '') === 'weekly' ? 'selected' : '' ?>>Weekly (Sunday 3 AM)</option>
                    </select>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="gdrive_enabled" <?= !empty($gdConf['enabled']) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:#34a853;">
                    <span style="font-size:13px;font-weight:700;color:#374151;">Enable automatic backups</span>
                </label>
            </div>
            <button type="submit" style="background:#4285f4;color:#fff;border:none;border-radius:10px;padding:10px 24px;font-size:13px;font-weight:800;cursor:pointer;">
                💾 Save Settings
            </button>
        </form>
    </div>
</div>

<?php if($gdConfigured && !$gdAuthorized): ?>
<!-- ── AUTHORIZATION FLOW ─── -->
<div style="background:#fff;border-radius:16px;border:2px solid #fbbc04;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #fde68a;background:#fffbeb;display:flex;align-items:center;gap:8px;">
        <span style="color:#ea4335;">🔗</span> Step 2: Authorize Google Drive Access
    </div>
    <div style="padding:18px;">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;margin-bottom:16px;">
            <div style="font-size:12px;font-weight:700;color:#15803d;margin-bottom:8px;">How it works (aaPanel-style):</div>
            <ol style="margin:0;padding-left:16px;font-size:12px;line-height:2;color:#374151;">
                <li>Click <strong>"Open Authorization Link"</strong> below → signs into Google in a new tab</li>
                <li>Click <strong>Allow</strong> to grant DishNet access to Google Drive</li>
                <li>You'll be redirected to <code>localhost</code> (will show an error — that's normal!)</li>
                <li><strong>Copy the full URL</strong> from the browser address bar</li>
                <li>Paste it into the box below and click <strong>Confirm</strong></li>
            </ol>
        </div>

        <div style="margin-bottom:16px;">
            <a href="<?= h($gdBackupSvc->getAuthUrl()) ?>" target="_blank" rel="noopener"
               style="display:inline-flex;align-items:center;gap:8px;background:#4285f4;color:#fff;border-radius:10px;padding:12px 24px;font-size:14px;font-weight:800;text-decoration:none;">
                🔗 Open Authorization Link
            </a>
        </div>

        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="wa_action" value="gdrive_authorize">
            <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Paste the full redirect URL here:</label>
            <input type="text" name="gdrive_redirect_url" class="form-control"
                   placeholder="http://localhost?code=4/0AX4XfW...&scope=https://www.googleapis.com/auth/drive.file"
                   style="font-size:11px;font-family:monospace;margin-bottom:12px;" required>
            <button type="submit" style="background:#34a853;color:#fff;border:none;border-radius:10px;padding:10px 24px;font-size:13px;font-weight:800;cursor:pointer;">
                ✓ Confirm Authorization
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if($gdAuthorized): ?>
<!-- ── CONNECTION STATUS ─── -->
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="color:#34a853;">✅</span> Connected to Google Drive
        </div>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Disconnect Google Drive? You can re-authorize later.')">
            <?= csrfField() ?>
            <input type="hidden" name="wa_action" value="gdrive_disconnect">
            <button type="submit" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;border-radius:8px;padding:4px 14px;font-size:11px;font-weight:700;cursor:pointer;">
                Disconnect
            </button>
        </form>
    </div>
    <div style="padding:18px;">
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;">
            <div style="background:#f0fdf4;border-radius:12px;padding:12px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;">Account</div>
                <div style="font-size:12px;font-weight:800;color:#15803d;"><?= h($gdConnInfo['email'] ?? '—') ?></div>
            </div>
            <div style="background:#eff6ff;border-radius:12px;padding:12px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;">Storage Used</div>
                <div style="font-size:14px;font-weight:800;color:#1d4ed8;"><?= h($gdConnInfo['used'] ?? '—') ?></div>
            </div>
            <div style="background:#fefce8;border-radius:12px;padding:12px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;">Backups in Drive</div>
                <div style="font-size:14px;font-weight:800;color:#92400e;"><?= count($gdDriveFiles) ?></div>
            </div>
            <div style="background:#fdf2f8;border-radius:12px;padding:12px;text-align:center;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:4px;">Last Backup</div>
                <div style="font-size:12px;font-weight:800;color:#9333ea;"><?= h($gdLastBackup ? substr($gdLastBackup['time'], 0, 16) : 'Never') ?></div>
            </div>
        </div>

        <!-- Manual Backup Button -->
        <div style="margin-top:16px;display:flex;gap:10px;align-items:center;">
            <form method="POST" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="wa_action" value="gdrive_backup_now">
                <button type="submit" style="background:linear-gradient(135deg,#34a853,#1e8e3e);color:#fff;border:none;border-radius:10px;padding:10px 24px;font-size:13px;font-weight:800;cursor:pointer;display:flex;align-items:center;gap:6px;"
                        onclick="this.disabled=true;this.innerHTML='⏳ Uploading to Drive…';this.form.submit();">
                    ⬆ Backup Now
                </button>
            </form>
            <?php if($gdLastBackup): ?>
            <span style="font-size:11px;color:#6b7280;">Last: <?= h($gdLastBackup['file'] ?? '') ?> (<?= h($gdLastBackup['size_kb'] ?? '?') ?> KB, <?= h($gdLastBackup['duration'] ?? '?') ?>s)</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── BACKUPS IN DRIVE ─── -->
<?php if(!empty($gdDriveFiles)): ?>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;gap:8px;">
        ☁ Backups in Google Drive (<?= count($gdDriveFiles) ?>)
    </div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead>
        <tr style="background:#f8fafc;">
            <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">File</th>
            <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">Size</th>
            <th style="padding:8px 14px;text-align:left;font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;">Created</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach($gdDriveFiles as $gi => $gf): ?>
        <tr style="border-bottom:1px solid #f1f5f9;background:<?= $gi % 2 === 0 ? '#fff' : '#fafafa' ?>;">
            <td style="padding:8px 14px;font-family:monospace;color:#1e293b;font-weight:600;"><?= h($gf['name'] ?? '') ?></td>
            <td style="padding:8px 14px;color:#64748b;"><?= h($gdBackupSvc->formatBytes((int)($gf['size'] ?? 0))) ?></td>
            <td style="padding:8px 14px;color:#9ca3af;white-space:nowrap;"><?= h(substr($gf['createdTime'] ?? '', 0, 16)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<?php endif; /* end gdAuthorized */ ?>

<!-- ── BACKUP LOG ─── -->
<?php if(!empty($gdLogs)): ?>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;margin-bottom:14px;overflow:hidden;">
    <div style="padding:13px 18px;font-size:13px;font-weight:800;color:#1e293b;border-bottom:1px solid #f1f5f9;background:#f8fafc;">
        📋 Backup Log (last <?= count($gdLogs) ?> entries)
    </div>
    <div style="padding:12px 16px;max-height:250px;overflow-y:auto;background:#0f172a;border-radius:0 0 16px 16px;">
        <?php foreach($gdLogs as $gl): ?>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:<?= str_contains($gl, 'ERROR') ? '#ef4444' : (str_contains($gl, '===') ? '#22c55e' : '#94a3b8') ?>;line-height:1.8;white-space:pre-wrap;"><?= h($gl) ?></div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════
     SUBTAB: Conversations
     ════════════════════════════════════════════════════ -->
<?php elseif($waSubtab === 'conversations'): ?>
<?php
    require_once dirname(__DIR__, 2) . '/lib/ConversationService.php';
    $convSvc2 = new ConversationService($dataDir, $store->getPdo());
    $convList = [];
    $convStats = [];
    $convViewId = (int)($_GET['conv_id'] ?? 0);
    $convMessages = [];
    $convDetail = null;
    $convFilter = $_GET['cf'] ?? '';
    $convSearch = $_GET['cs'] ?? '';
    $convPage   = max(1, (int)($_GET['cp'] ?? 1));

    try {
        $filters = ['status' => 'active'];
        if ($convFilter) $filters['channel'] = $convFilter;
        if ($convSearch) $filters['search']  = $convSearch;
        $convList  = $convSvc2->listConversations($filters, 30, ($convPage - 1) * 30);
        $convStats = $convSvc2->getStats();

        if ($convViewId) {
            $convDetail   = $convSvc2->getConversation($convViewId);
            $convMessages = $convSvc2->getMessages($convViewId, 200);
            $convSvc2->markRead($convViewId);

            // Lazy-fetch: if conversation has evo_jid and fewer than 3 local messages, pull from Evolution API
            if ($convDetail && $evoConfigured && count($convMessages) < 3) {
                $evoJid = $convDetail['evo_jid'] ?? '';
                if (!empty($evoJid)) {
                    try {
                        require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
                        $evoLazy = new EvolutionApiClient($config['evo_api_url'], $config['evo_api_key'], $config['evo_instance_name'], 15);
                        $evoMsgs = $evoLazy->findMessages($evoJid, 30, 1);
                        $evoRecords = $evoMsgs['messages']['records'] ?? $evoMsgs['messages'] ?? [];
                        if (is_array($evoRecords)) {
                            $ch = $config['evo_channel_name'] ?? 'marketing';
                            foreach ($evoRecords as $em) {
                                $convSvc2->importEvoMessage($em, $ch);
                            }
                            // Re-fetch local messages after import
                            $convMessages = $convSvc2->getMessages($convViewId, 200);
                        }
                    } catch (Throwable $e) { /* silent */ }
                }
            }
        }
    } catch (Throwable $e) {
        // Tables may not exist yet — show empty state
    }

    $evoSyncState = $store->load('evo_sync_state.json') ?? [];
    $evoConfigured = !empty($config['evo_api_url']) && !empty($config['evo_api_key']) && !empty($config['evo_instance_name']);

    // ── Run inline diagnostic if requested ───────────────────────────────
    $diagResults = null;
    if (isset($_GET['diagnose']) && $evoConfigured) {
        require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
        $diagResults = [];

        // 1. Check config
        $diagResults[] = ['check' => 'Config saved', 'status' => 'ok',
            'detail' => "URL: {$config['evo_api_url']} | Instance: {$config['evo_instance_name']} | Key: " . substr($config['evo_api_key'], 0, 12) . '...'];

        // 2. Check tables exist
        try {
            $tblCheck = $store->getPdo()->query("SELECT COUNT(*) FROM wa_conversations")->fetchColumn();
            $diagResults[] = ['check' => 'SQLite tables', 'status' => 'ok', 'detail' => "wa_conversations table exists ({$tblCheck} rows)"];
        } catch (Throwable $e) {
            $diagResults[] = ['check' => 'SQLite tables', 'status' => 'fail', 'detail' => 'Tables NOT created — migration 017 has not run. ' . $e->getMessage()];
        }

        // 3. Test Evolution API connection
        try {
            $diagEvo = new EvolutionApiClient($config['evo_api_url'], $config['evo_api_key'], $config['evo_instance_name'], 15);
            $diagConn = $diagEvo->connectionState();
            $diagState = $diagConn['state'] ?? $diagConn['instance']['state'] ?? 'unknown';
            $diagResults[] = ['check' => 'API connection', 'status' => $diagState === 'open' ? 'ok' : 'warn',
                'detail' => "Instance state: {$diagState}" . (isset($diagConn['error']) ? ' — ' . $diagConn['error'] : '')];
        } catch (Throwable $e) {
            $diagResults[] = ['check' => 'API connection', 'status' => 'fail', 'detail' => $e->getMessage()];
        }

        // 4. Try fetching chats
        if (isset($diagEvo)) {
            try {
                $diagChats = $diagEvo->findChats();
                if (isset($diagChats['error'])) {
                    $diagResults[] = ['check' => 'Fetch chats', 'status' => 'fail', 'detail' => $diagChats['error']];
                } else {
                    $chatCount = count($diagChats);

                    // Show raw structure of first chat so we can see field names
                    $firstRaw = $diagChats[0] ?? [];
                    $rawKeys = array_keys($firstRaw);
                    $diagResults[] = ['check' => 'Chat structure', 'status' => 'ok',
                        'detail' => 'Keys: ' . implode(', ', $rawKeys)];

                    // Find the JID field — check direct fields first, then nested in lastMessage
                    $jidField = null;
                    $sampleJid = '';
                    // Direct fields
                    foreach (['id', 'remoteJid', 'jid', 'chatId'] as $candidate) {
                        if (!empty($firstRaw[$candidate]) && is_string($firstRaw[$candidate])) {
                            $jidField = $candidate;
                            $sampleJid = $firstRaw[$candidate];
                            break;
                        }
                    }
                    // Nested: lastMessage.key.remoteJid (Evolution API v2.3)
                    if (!$jidField && !empty($firstRaw['lastMessage']['key']['remoteJid'])) {
                        $jidField = 'lastMessage.key.remoteJid';
                        $sampleJid = $firstRaw['lastMessage']['key']['remoteJid'];
                    }
                    $diagResults[] = ['check' => 'JID field', 'status' => $jidField ? 'ok' : 'fail',
                        'detail' => $jidField
                            ? "Field: '{$jidField}' | Sample: {$sampleJid}"
                            : 'Could not find JID field! First chat: ' . json_encode(array_slice($firstRaw, 0, 5))];

                    // Check for senderPn (real phone number for @lid format)
                    $sampleSenderPn = $firstRaw['lastMessage']['key']['senderPn'] ?? '';
                    if ($sampleSenderPn) {
                        $diagResults[] = ['check' => 'Real phone (senderPn)', 'status' => 'ok',
                            'detail' => "senderPn found: {$sampleSenderPn} — real phone numbers available"];
                    } elseif (strpos($sampleJid, '@lid') !== false) {
                        $diagResults[] = ['check' => 'Real phone (senderPn)', 'status' => 'warn',
                            'detail' => "Using @lid format — senderPn not in first chat's lastMessage. Phone may be extracted from message-level senderPn during import."];
                    }

                    // Helper to extract JID from a chat object
                    $getJid = function($c) {
                        return $c['remoteJid'] ?? $c['jid'] ?? $c['chatId']
                            ?? (is_string($c['id'] ?? null) ? $c['id'] : null)
                            ?? ($c['lastMessage']['key']['remoteJid'] ?? null)
                            ?? '';
                    };

                    // Filter: individual = NOT group (@g.us) and NOT status
                    $individual = 0;
                    if ($jidField) {
                        $individual = count(array_filter($diagChats, function($c) use ($getJid) {
                            $j = $getJid($c);
                            return !empty($j) && strpos($j, '@g.us') === false && strpos($j, 'status@') === false;
                        }));
                    }
                    $diagResults[] = ['check' => 'Individual chats', 'status' => $individual > 0 ? 'ok' : 'warn',
                        'detail' => "{$chatCount} total, {$individual} individual (non-group)"];

                    // 5. Try fetching messages from first individual chat
                    if ($individual > 0) {
                        $firstIndiv = array_values(array_filter($diagChats, function($c) use ($getJid) {
                            $j = $getJid($c);
                            return !empty($j) && strpos($j, '@g.us') === false && strpos($j, 'status@') === false;
                        }))[0] ?? null;
                        if ($firstIndiv) {
                            $testJid = $getJid($firstIndiv);
                            try {
                                $testMsgs = $diagEvo->findMessages($testJid, 3, 1);
                                // Show raw response structure
                                $responseType = gettype($testMsgs);
                                $topKeys = is_array($testMsgs) ? array_keys($testMsgs) : [];
                                $diagResults[] = ['check' => 'Messages API response', 'status' => 'ok',
                                    'detail' => "Type: {$responseType} | Top keys: " . implode(', ', array_slice($topKeys, 0, 10))
                                    . ' | Sample: ' . mb_substr(json_encode($testMsgs, JSON_UNESCAPED_UNICODE), 0, 300)];

                                // Try to find actual message array
                                $msgArr = null;
                                if (isset($testMsgs['messages']['records']) && is_array($testMsgs['messages']['records'])) {
                                    $msgArr = $testMsgs['messages']['records'];
                                } elseif (isset($testMsgs['messages']) && is_array($testMsgs['messages'])) {
                                    $msgArr = $testMsgs['messages'];
                                } elseif (isset($testMsgs[0]['key'])) {
                                    $msgArr = $testMsgs; // Direct array of messages
                                } elseif (isset($testMsgs[0]['messages'])) {
                                    $msgArr = $testMsgs[0]['messages'];
                                }
                                $msgCount = is_array($msgArr) ? count($msgArr) : 0;
                                if ($msgCount > 0) {
                                    $firstMsg = $msgArr[0];
                                    $keyType = gettype($firstMsg['key'] ?? null);
                                    $msgBodyType = gettype($firstMsg['message'] ?? null);
                                    $keyPreview = is_string($firstMsg['key'] ?? null) ? mb_substr($firstMsg['key'], 0, 80) : 'decoded_array';
                                    $diagResults[] = ['check' => 'Fetch messages', 'status' => 'ok',
                                        'detail' => "{$msgCount} msgs | key type: {$keyType} ({$keyPreview}) | message type: {$msgBodyType}"];
                                } else {
                                    $diagResults[] = ['check' => 'Fetch messages', 'status' => 'warn',
                                        'detail' => "0 messages from {$testJid}"];
                                }
                            } catch (Throwable $e) {
                                $diagResults[] = ['check' => 'Fetch messages', 'status' => 'fail', 'detail' => $e->getMessage()];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $diagResults[] = ['check' => 'Fetch chats', 'status' => 'fail', 'detail' => $e->getMessage()];
            }
        }

        // 6. Check sync state
        $diagResults[] = ['check' => 'Import flag', 'status' => !empty($evoSyncState['run_full_import']) ? 'warn' : 'ok',
            'detail' => !empty($evoSyncState['run_full_import'])
                ? 'Full import is QUEUED — waiting for cron to pick it up (runs every 5 min)'
                : 'No pending import. Last sync: ' . ($evoSyncState['last_sync_at'] ?? 'Never')];

        // 7. Check master cron schedule
        $masterSchedule = $store->load('master_schedule.json') ?? [];
        $evoLastRun = $masterSchedule['evo_sync']['last_run_at'] ?? 'Never';
        $diagResults[] = ['check' => 'Cron evo_sync', 'status' => $evoLastRun !== 'Never' ? 'ok' : 'warn',
            'detail' => "Last cron run: {$evoLastRun}" . ($evoLastRun === 'Never' ? ' — cron has never executed this job yet' : '')];

        // 8. Check sync log
        $evoLogFile = $dataDir . '/evo_sync.log';
        $lastLogLines = [];
        if (file_exists($evoLogFile)) {
            $allLines = file($evoLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lastLogLines = array_slice($allLines ?: [], -10);
        }
        $diagResults[] = ['check' => 'Sync log', 'status' => !empty($lastLogLines) ? 'ok' : 'warn',
            'detail' => !empty($lastLogLines) ? 'Last ' . count($lastLogLines) . ' log entries available' : 'No sync log yet — cron has not run'];
    }
?>

<?php if ($diagResults !== null): ?>
<!-- ── Diagnostic Results ─── -->
<div style="background:#0f172a;border-radius:16px;padding:16px 20px;margin-bottom:16px;border:2px solid <?= in_array('fail', array_column($diagResults, 'status')) ? '#ef4444' : '#22c55e' ?>;">
    <div style="font-size:14px;font-weight:800;color:#fff;margin-bottom:12px;">🔬 Diagnostic Results</div>
    <?php foreach ($diagResults as $d): ?>
    <div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #1e293b;">
        <span style="font-size:14px;flex-shrink:0;"><?= $d['status']==='ok' ? '✅' : ($d['status']==='warn' ? '⚠️' : '❌') ?></span>
        <div>
            <span style="font-weight:700;color:#e2e8f0;font-size:12px;"><?= h($d['check']) ?></span>
            <span style="color:#94a3b8;font-size:11px;margin-left:8px;"><?= h($d['detail']) ?></span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (!empty($lastLogLines)): ?>
    <div style="margin-top:12px;font-size:11px;font-weight:700;color:#94a3b8;">Sync Log (last <?= count($lastLogLines) ?> lines):</div>
    <div style="background:#1e293b;border-radius:8px;padding:8px 12px;margin-top:6px;max-height:200px;overflow-y:auto;">
        <?php foreach ($lastLogLines as $ll): ?>
        <div style="font-family:monospace;font-size:10px;color:<?= strpos($ll, 'ERROR') !== false ? '#ef4444' : (strpos($ll, '===') !== false ? '#22c55e' : '#94a3b8') ?>;line-height:1.7;"><?= h($ll) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:16px;">
    <?php
    $chTotals = [];
    foreach ($convStats['by_channel'] ?? [] as $ch) $chTotals[$ch['channel']] = $ch;
    $totalConv = array_sum(array_column($convStats['by_channel'] ?? [], 'cnt'));
    $totalMsgs2 = array_sum(array_column($convStats['by_channel'] ?? [], 'msgs'));
    ?>
    <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Total Conversations</div>
        <div style="font-size:20px;font-weight:900;color:#1e293b;"><?= number_format($totalConv) ?></div>
    </div>
    <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Total Messages</div>
        <div style="font-size:20px;font-weight:900;color:#1e293b;"><?= number_format($totalMsgs2) ?></div>
    </div>
    <div style="background:#dbeafe;border-radius:12px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#1d4ed8;">🛠 Support</div>
        <div style="font-size:16px;font-weight:900;color:#1d4ed8;"><?= number_format((int)($chTotals['support']['cnt'] ?? 0)) ?></div>
    </div>
    <div style="background:#d1fae5;border-radius:12px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#065f46;">💼 Accounts</div>
        <div style="font-size:16px;font-weight:900;color:#065f46;"><?= number_format((int)($chTotals['accounts']['cnt'] ?? 0)) ?></div>
    </div>
    <div style="background:#f5f3ff;border-radius:12px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#7C3AED;">📡 Marketing</div>
        <div style="font-size:16px;font-weight:900;color:#7C3AED;"><?= number_format((int)($chTotals['marketing']['cnt'] ?? 0)) ?></div>
    </div>
</div>

<!-- Action buttons -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
    <?php if ($evoConfigured): ?>
    <?php
        // Check webhook status
        $webhookStatus = null;
        try {
            require_once dirname(__DIR__, 2) . '/lib/EvolutionApiClient.php';
            $evoWh = new EvolutionApiClient($config['evo_api_url'], $config['evo_api_key'], $config['evo_instance_name'], 10);
            $whConf = $evoWh->findWebhook();
            $webhookStatus = $whConf;
        } catch (Throwable $e) { $webhookStatus = ['error' => $e->getMessage()]; }
        $whEnabled = !empty($webhookStatus['enabled'] ?? $webhookStatus['webhook']['enabled'] ?? $webhookStatus[0]['enabled'] ?? false);
        $whUrl = $webhookStatus['url'] ?? $webhookStatus['webhook']['url'] ?? $webhookStatus[0]['url'] ?? '';
    ?>
    <?php if ($whEnabled && $whUrl): ?>
    <span style="background:#dcfce7;color:#15803d;border-radius:10px;padding:6px 14px;font-size:12px;font-weight:700;">✅ Webhook active — new messages captured automatically</span>
    <?php else: ?>
    <form method="POST" style="margin:0;">
        <?= csrfField() ?>
        <input type="hidden" name="wa_action" value="evo_setup_webhook">
        <button type="submit" style="background:#ef4444;color:#fff;border:none;border-radius:10px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;animation:pulse 2s infinite;">
            🔗 Activate Webhook (REQUIRED)
        </button>
    </form>
    <style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:.7}}</style>
    <?php endif; ?>
    <?php if ($totalConv === 0): ?>
    <form method="POST" style="margin:0;">
        <?= csrfField() ?>
        <input type="hidden" name="wa_action" value="evo_load_chats">
        <button type="submit" style="background:#7C3AED;color:#fff;border:none;border-radius:10px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;"
                onclick="this.disabled=true;this.innerHTML='⏳ Loading 4,730 chats…';this.form.submit();">
            📋 Load Chat List from Evolution API
        </button>
    </form>
    <?php endif; ?>
    <a href="?page=dashboard&tab=whatsapp&subtab=conversations&diagnose=1" style="background:#f59e0b;color:#fff;border:none;border-radius:10px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
        🔬 Diagnose
    </a>
    <?php else: ?>
    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 16px;font-size:12px;color:#92400e;font-weight:600;">
        ⚠ Evolution API not configured — go to <a href="?page=dashboard&tab=whatsapp&subtab=config" style="color:#1d4ed8;font-weight:800;">⚙ Configuration</a> and fill in the Evolution API section.
    </div>
    <?php endif; ?>
</div>

<!-- Filter bar -->
<div style="display:flex;gap:8px;margin-bottom:14px;align-items:center;">
    <a href="?page=dashboard&tab=whatsapp&subtab=conversations" style="background:<?= !$convFilter ? '#1e293b' : '#f1f5f9' ?>;color:<?= !$convFilter ? '#fff' : '#374151' ?>;border-radius:8px;padding:5px 14px;font-size:12px;font-weight:700;text-decoration:none;">All</a>
    <a href="?page=dashboard&tab=whatsapp&subtab=conversations&cf=support" style="background:<?= $convFilter==='support' ? '#1d4ed8' : '#f1f5f9' ?>;color:<?= $convFilter==='support' ? '#fff' : '#374151' ?>;border-radius:8px;padding:5px 14px;font-size:12px;font-weight:700;text-decoration:none;">🛠 Support</a>
    <a href="?page=dashboard&tab=whatsapp&subtab=conversations&cf=accounts" style="background:<?= $convFilter==='accounts' ? '#065f46' : '#f1f5f9' ?>;color:<?= $convFilter==='accounts' ? '#fff' : '#374151' ?>;border-radius:8px;padding:5px 14px;font-size:12px;font-weight:700;text-decoration:none;">💼 Accounts</a>
    <a href="?page=dashboard&tab=whatsapp&subtab=conversations&cf=marketing" style="background:<?= $convFilter==='marketing' ? '#7C3AED' : '#f1f5f9' ?>;color:<?= $convFilter==='marketing' ? '#fff' : '#374151' ?>;border-radius:8px;padding:5px 14px;font-size:12px;font-weight:700;text-decoration:none;">📡 Marketing</a>
    <form method="GET" style="margin-left:auto;display:flex;gap:6px;">
        <input type="hidden" name="page" value="dashboard">
        <input type="hidden" name="tab" value="whatsapp">
        <input type="hidden" name="subtab" value="conversations">
        <input type="text" name="cs" value="<?= h($convSearch) ?>" placeholder="Search phone, name, CRM..." style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;width:200px;">
        <button type="submit" style="background:#1e293b;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;">🔍</button>
    </form>
</div>

<?php if ($convViewId && $convDetail): ?>
<!-- ── Conversation Detail View ─── -->
<a href="?page=dashboard&tab=whatsapp&subtab=conversations<?= $convFilter ? '&cf='.$convFilter : '' ?>" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:#1d4ed8;font-weight:700;text-decoration:none;margin-bottom:10px;">← Back to list</a>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
    <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
        <div>
            <div style="font-weight:800;color:#1e293b;"><?= h($convDetail['display_name'] ?? 'Unknown') ?></div>
            <div style="font-size:11px;color:#6b7280;font-family:monospace;"><?= h($convDetail['phone']) ?> · <?= h($convDetail['channel']) ?><?= $convDetail['crm_client_name'] ? ' · CRM: '.h($convDetail['crm_client_name']) : '' ?></div>
        </div>
        <div style="display:flex;gap:6px;">
            <span style="background:<?= $convDetail['channel']==='support' ? '#dbeafe' : ($convDetail['channel']==='accounts' ? '#d1fae5' : '#f5f3ff') ?>;color:<?= $convDetail['channel']==='support' ? '#1d4ed8' : ($convDetail['channel']==='accounts' ? '#065f46' : '#7C3AED') ?>;padding:3px 10px;border-radius:8px;font-size:10px;font-weight:800;"><?= h(ucfirst($convDetail['channel'])) ?></span>
            <?php if ($convDetail['category']): ?><span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:8px;font-size:10px;font-weight:800;"><?= h($convDetail['category']) ?></span><?php endif; ?>
            <span style="font-size:10px;color:#9ca3af;"><?= (int)$convDetail['message_count'] ?> msgs</span>
            <?php $detailEvoJid = $convDetail['evo_jid'] ?? ''; ?>
            <?php if ($detailEvoJid && $evoConfigured): ?>
            <form method="POST" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="wa_action" value="evo_import_now">
                <input type="hidden" name="fetch_jid" value="<?= h($detailEvoJid) ?>">
                <input type="hidden" name="conv_id" value="<?= $convViewId ?>">
                <button type="submit" style="background:#7C3AED;color:#fff;border:none;border-radius:8px;padding:3px 10px;font-size:10px;font-weight:700;cursor:pointer;">⬇ Fetch History</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div style="padding:14px 18px;max-height:500px;overflow-y:auto;background:#f0f2f5;" id="convMsgBox">
        <?php foreach ($convMessages as $cm): ?>
        <div style="display:flex;justify-content:<?= $cm['direction']==='in' ? 'flex-start' : 'flex-end' ?>;margin-bottom:6px;">
            <div style="max-width:75%;background:<?= $cm['direction']==='in' ? '#fff' : '#dcf8c6' ?>;border-radius:<?= $cm['direction']==='in' ? '4px 12px 12px 12px' : '12px 4px 12px 12px' ?>;padding:8px 12px;box-shadow:0 1px 2px rgba(0,0,0,.06);">
                <?php if ($cm['role'] === 'bot'): ?><div style="font-size:9px;font-weight:800;color:#7C3AED;margin-bottom:2px;">🤖 Auto-Reply</div><?php endif; ?>
                <div style="font-size:13px;color:#1e293b;white-space:pre-wrap;word-break:break-word;"><?= h($cm['body']) ?></div>
                <div style="font-size:9px;color:#9ca3af;text-align:right;margin-top:3px;"><?= h(substr($cm['sent_at'] ?? '', 11, 5)) ?> · <?= h($cm['role']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($convMessages)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af;font-size:12px;">No messages loaded for this conversation.</div>
        <?php endif; ?>
    </div>
</div>
<script>var b=document.getElementById('convMsgBox');if(b)b.scrollTop=b.scrollHeight;</script>

<?php else: ?>
<!-- ── Conversation List ─── -->
<?php if (empty($convList)): ?>
<div style="text-align:center;padding:40px;color:#9ca3af;font-size:12px;background:#fff;border-radius:16px;border:1px dashed #e2e8f0;">
    <?php if (!$evoConfigured): ?>
    No conversations yet. Configure the Evolution API in the ⚙ Configuration tab first.
    <?php else: ?>
    No conversations yet. Click <strong>📋 Load Chat List from Evolution API</strong> above to load your 4,730 chats.<br>
    Then click any chat to lazy-load its messages. New messages are captured automatically via webhook.
    <?php endif; ?>
</div>
<?php else: ?>
<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
<table style="width:100%;border-collapse:collapse;font-size:12px;">
<thead><tr style="background:#f8fafc;">
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Contact</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Channel</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Last Message</th>
    <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Msgs</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Category</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;">Last Active</th>
</tr></thead>
<tbody>
<?php foreach ($convList as $ci => $cv): ?>
<tr style="border-bottom:1px solid #f1f5f9;background:<?= $ci%2===0?'#fff':'#fafafa' ?>;">
    <td style="padding:9px 12px;cursor:pointer;" onclick="location.href='?page=dashboard&tab=whatsapp&subtab=conversations&conv_id=<?= $cv['id'] ?>'">
        <div style="font-weight:700;color:#1e293b;"><?= h($cv['display_name'] ?? 'Unknown') ?></div>
        <div style="font-size:10px;color:#9ca3af;font-family:monospace;"><?= h($cv['phone']) ?><?= $cv['crm_client_name'] ? ' · '.h($cv['crm_client_name']) : '' ?></div>
        <?php if (!empty($cv['lead_id'])): ?>
        <div style="font-size:10px;color:#16a34a;margin-top:2px;">✅ Lead #<?= (int)$cv['lead_id'] ?> created</div>
        <?php endif; ?>
    </td>
    <td style="padding:9px 12px;cursor:pointer;" onclick="location.href='?page=dashboard&tab=whatsapp&subtab=conversations&conv_id=<?= $cv['id'] ?>'">
        <span style="background:<?= $cv['channel']==='support'?'#dbeafe':($cv['channel']==='accounts'?'#d1fae5':'#f5f3ff') ?>;color:<?= $cv['channel']==='support'?'#1d4ed8':($cv['channel']==='accounts'?'#065f46':'#7C3AED') ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:800;"><?= h(ucfirst($cv['channel'])) ?></span>
    </td>
    <td style="padding:9px 12px;color:#64748b;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;" onclick="location.href='?page=dashboard&tab=whatsapp&subtab=conversations&conv_id=<?= $cv['id'] ?>'"><?= h(mb_substr($cv['last_message_preview'] ?? '', 0, 60)) ?></td>
    <td style="padding:9px 12px;text-align:center;font-weight:700;cursor:pointer;" onclick="location.href='?page=dashboard&tab=whatsapp&subtab=conversations&conv_id=<?= $cv['id'] ?>'">
        <?= (int)$cv['message_count'] ?>
        <?php if ((int)$cv['unread_count'] > 0): ?><span style="background:#ef4444;color:#fff;border-radius:8px;padding:1px 5px;font-size:9px;font-weight:800;margin-left:3px;"><?= $cv['unread_count'] ?></span><?php endif; ?>
    </td>
    <td style="padding:9px 12px;cursor:pointer;" onclick="location.href='?page=dashboard&tab=whatsapp&subtab=conversations&conv_id=<?= $cv['id'] ?>'"><?php if ($cv['category']): ?><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><?= h($cv['category']) ?></span><?php endif; ?></td>
    <td style="padding:9px 12px;color:#9ca3af;white-space:nowrap;font-size:11px;cursor:pointer;" onclick="location.href='?page=dashboard&tab=whatsapp&subtab=conversations&conv_id=<?= $cv['id'] ?>'"><?= h(substr($cv['last_message_at'] ?? '', 0, 16)) ?></td>
    <?php if ($cv['channel'] === 'marketing' && empty($cv['lead_id']) && empty($cv['crm_client_id'])): ?>
    <td style="padding:6px 12px;">
        <button onclick="convToLead(<?= $cv['id'] ?>, '<?= h(addslashes($cv['display_name'] ?? '')) ?>', '<?= h($cv['phone']) ?>')"
                style="background:#D41C1C;color:#fff;border:none;border-radius:8px;padding:5px 10px;font-size:10px;font-weight:700;cursor:pointer;white-space:nowrap;">
            + Lead
        </button>
    </td>
    <?php else: ?>
    <td style="padding:6px 12px;"></td>
    <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; /* conv detail vs list */ ?>

<!-- ════════════════════════════════════════════════════
     SUBTAB: Auto-Reply Templates
     ════════════════════════════════════════════════════ -->
<?php elseif($waSubtab === 'autoreplies'): ?>
<?php
    require_once dirname(__DIR__, 2) . '/lib/TemplateReplyEngine.php';
    $arTemplates = [];
    try {
        $arEngine = new TemplateReplyEngine($store->getPdo());
        $arTemplates = $arEngine->listTemplates();
    } catch (Throwable $e) {}
?>

<div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:14px;padding:16px 20px;margin-bottom:18px;">
    <div style="font-size:14px;font-weight:800;color:#7C3AED;margin-bottom:6px;">🤖 Auto-Reply Template Engine</div>
    <div style="font-size:12px;color:#6d28d9;line-height:1.8;">
        When a customer sends a message, the engine checks templates in <strong>priority order</strong> (lowest number = checked first).
        If a keyword matches, the response is sent automatically. Designed to upgrade to AI later — just add a new <code>match_type: ai</code> template.
    </div>
</div>

<div style="background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;">
<table style="width:100%;border-collapse:collapse;font-size:12px;">
<thead><tr style="background:#f8fafc;">
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">PRIORITY</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">NAME</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">CHANNEL</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">KEYWORDS</th>
    <th style="padding:8px 12px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;">RESPONSE PREVIEW</th>
    <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:700;color:#6b7280;">HITS</th>
    <th style="padding:8px 12px;text-align:center;font-size:10px;font-weight:700;color:#6b7280;">STATUS</th>
</tr></thead>
<tbody>
<?php foreach ($arTemplates as $ai => $ar): ?>
<tr style="border-bottom:1px solid #f1f5f9;background:<?= $ai%2===0?'#fff':'#fafafa' ?>;">
    <td style="padding:8px 12px;font-weight:800;color:#7C3AED;font-size:14px;text-align:center;"><?= (int)$ar['priority'] ?></td>
    <td style="padding:8px 12px;font-weight:700;color:#1e293b;"><?= h($ar['name']) ?></td>
    <td style="padding:8px 12px;">
        <span style="background:<?= $ar['channel']==='support'?'#dbeafe':($ar['channel']==='accounts'?'#d1fae5':($ar['channel']==='both'?'#f1f5f9':'#f5f3ff')) ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><?= h($ar['channel']) ?></span>
    </td>
    <td style="padding:8px 12px;color:#64748b;font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(mb_substr($ar['match_pattern'], 0, 50)) ?></td>
    <td style="padding:8px 12px;color:#374151;font-size:11px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h(mb_substr(str_replace("\n", ' ', $ar['response_body']), 0, 60)) ?></td>
    <td style="padding:8px 12px;text-align:center;font-weight:700;color:#6b7280;"><?= number_format((int)$ar['hit_count']) ?></td>
    <td style="padding:8px 12px;text-align:center;">
        <span style="background:<?= $ar['enabled'] ? '#dcfce7' : '#fee2e2' ?>;color:<?= $ar['enabled'] ? '#15803d' : '#991b1b' ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:800;"><?= $ar['enabled'] ? '▶ ON' : '⏸ OFF' ?></span>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($arTemplates)): ?>
<tr><td colspan="7" style="padding:30px;text-align:center;color:#9ca3af;">No templates loaded. The 7 default templates will appear after the first message sync or import.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php elseif($waSubtab === 'leads'): ?>
<div style="text-align:center;padding:40px;">
    <div style="font-size:36px;margin-bottom:12px;">🎯</div>
    <div style="font-size:15px;font-weight:700;color:#1e293b;margin-bottom:8px;">Lead Recovery has moved!</div>
    <a href="?page=dashboard&tab=wa_leads" style="background:#7C3AED;color:#fff;border:none;border-radius:10px;padding:10px 28px;font-size:13px;font-weight:700;text-decoration:none;display:inline-block;">Go to WA Leads →</a>
</div>

<?php endif; /* end subtab */ ?>


<!-- ── Edit Modal ─────────────────────────────────────────────────────── -->
<div class="wa2-modal-bg" id="waModalBg" onclick="if(event.target===this)waCloseEdit()">
<div class="wa2-modal" id="waModal">
    <div class="wa2-modal-hd">
        <div>
            <div style="font-size:16px;font-weight:900;" id="waModalTitle">Edit Message</div>
            <div style="font-size:11px;opacity:.75;" id="waModalKey"></div>
        </div>
        <button onclick="waCloseEdit()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:8px;width:32px;height:32px;font-size:16px;cursor:pointer;font-weight:700;">✕</button>
    </div>
    <div class="wa2-modal-body">

        <!-- Status toggle -->
        <div class="wa2-field">
            <label>Status</label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="waModalEnabled" style="width:18px;height:18px;accent-color:#25D366;">
                <span style="font-size:13px;font-weight:700;" id="waEnabledLabel">Enabled — this message will send</span>
            </label>
        </div>

        <!-- Sender toggle -->
        <div class="wa2-field">
            <label>Sender Number</label>
            <div class="wa2-sender-toggle">
                <div class="wa2-sender-opt" id="wa-opt-support" onclick="waSetSender('support')">
                    🛠 Support<br><span style="font-weight:400;font-size:10px;"><?= h($config['wa_support_number']??'211921443006') ?></span>
                </div>
                <div class="wa2-sender-opt" id="wa-opt-accounts" onclick="waSetSender('accounts')">
                    💼 Accounts<br><span style="font-weight:400;font-size:10px;"><?= h($config['wa_accounts_number']??'211921443002') ?></span>
                </div>
            </div>
            <input type="hidden" id="waModalSender" value="support">
        </div>

        <!-- Message body -->
        <div class="wa2-field">
            <label>Message Text <span style="font-weight:400;color:#9ca3af;">— *bold* supported, \n = new line</span></label>
            <textarea id="waModalBody" oninput="waUpdatePreview()"></textarea>
            <div style="font-size:11px;color:#6b7280;margin-top:5px;">Click a variable chip below to insert it at cursor:</div>
            <div class="wa2-var-chips" id="waVarChips"></div>
        </div>

        <!-- Live preview -->
        <div class="wa2-field">
            <label>Live Preview <span style="font-weight:400;color:#9ca3af;">(variables replaced with sample values)</span></label>
            <div class="wa2-preview-box" id="waPreview"></div>
        </div>

        <!-- Default message (collapsible) -->
        <details style="margin-top:4px;">
            <summary style="font-size:12px;font-weight:700;color:#6b7280;cursor:pointer;margin-bottom:8px;">📋 View code default message</summary>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:11px;font-family:monospace;white-space:pre-wrap;color:#374151;" id="waDefaultBody"></div>
        </details>

        <!-- Test phone -->
        <div class="wa2-field" style="margin-top:14px;">
            <label>Test Send — phone number <span style="font-weight:400;color:#9ca3af;">(digits only, e.g. 211921443006)</span></label>
            <input type="text" id="waTestPhone" value="<?= h(preg_replace('/[^0-9]/','', $config['whatsapp_admin_phone']??'')) ?>" placeholder="211921443006">
        </div>
    </div>

    <div class="wa2-modal-footer">
        <form method="POST" id="waSaveForm" style="display:contents;">
            <?= csrfField() ?>
            <input type="hidden" name="wa_action"    value="wa_save_template">
            <input type="hidden" name="tpl_key"      id="fTplKey">
            <input type="hidden" name="tpl_body"     id="fTplBody">
            <input type="hidden" name="tpl_sender"   id="fTplSender">
            <input type="hidden" name="tpl_enabled"  id="fTplEnabled" value="1">
            <button type="button" class="wa2-btn-save" onclick="waSave()">💾 Save Changes</button>
        </form>
        <form method="POST" id="waResetForm" style="display:contents;">
            <?= csrfField() ?>
            <input type="hidden" name="wa_action" value="wa_reset_template">
            <input type="hidden" name="tpl_key"   id="fResetKey">
            <button type="button" class="wa2-btn-reset" onclick="waReset()">↺ Reset to Default</button>
        </form>
        <form method="POST" id="waTestForm" style="display:contents;">
            <?= csrfField() ?>
            <input type="hidden" name="wa_action"   value="wa_test_send">
            <input type="hidden" name="tpl_key"     id="fTestKey">
            <input type="hidden" name="tpl_body"    id="fTestBody">
            <input type="hidden" name="tpl_sender"  id="fTestSender">
            <input type="hidden" name="test_phone"  id="fTestPhone">
            <button type="button" class="wa2-btn-test" onclick="waTestSend()">📤 Test Send</button>
        </form>
        <button type="button" class="wa2-btn-close" onclick="waCloseEdit()">Cancel</button>
    </div>
</div>
</div>

<script>
// ── Event definitions injected from PHP ───────────────────────────────────
var waEvents = <?= json_encode(array_values(array_map(fn($k,$d)=>['key'=>$k,'label'=>$d['label'],'default_body'=>$d['default_body'],'vars'=>$d['vars'],'default_sender'=>$d['default_sender']], array_keys($waEventDefs), $waEventDefs)), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?>;
var waTpls   = <?= json_encode((object)$waTpls, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' ?>;

// Sample values for preview
var waSampleVars = {
    '{{agent_name}}':'Diko','{{customer_name}}':'John Doe','{{service_type}}':'Starlink',
    '{{amount}}':'120.00','{{ref}}':'KYC-42','{{crm_id}}':'#1234','{{username}}':'STAR000053',
    '{{amount_refunded}}':'120.00','{{error}}':'Timeout','{{invoice_number}}':'INV-2026-001',
    '{{due_date}}':new Date().toISOString().slice(0,10),'{{service_name}}':'Starlink Standard',
    '{{reason}}':'Incorrect amount','{{request_id}}':'77','{{approved_by}}':'Rupesh',
    '{{new_balance}}':'350.00','{{cash_balance}}':'200.00','{{remitted_to}}':'Rupesh',
    '{{msisdn}}':'211922000001','{{fee}}':'5.00','{{batch_name}}':'Fiber-Batch-001',
    '{{partner}}':'Bidal','{{created}}':'8','{{total}}':'10','{{failed}}':'2',
    '{{lead_name}}':'Jane Smith','{{lead_phone}}':'211921000000','{{source}}':'💳 Cash Sale',
    '{{currency}}':'USD','{{outstanding}}':'80.00','{{install_date}}':'2026-03-15',
    '{{install_time}}':'10:00-12:00','{{tech_name}}':'Moses','{{maint_date}}':'2026-03-20',
    '{{maint_start}}':'02:00','{{maint_end}}':'06:00','{{txn_id}}':'TXN-001234',
    '{{month}}':'February 2026','{{new_customers}}':'12','{{commission_amount}}':'240.00',
    '{{bonus}}':'50.00','{{total_payout}}':'290.00','{{pay_date}}':'2026-03-07',
    '{{pending_leads}}':'5','{{deadline}}':'Friday','{{note}}':'Monthly top-up',
    '{{app_id}}':'42','{{area}}':'Tomping, Juba','{{customer_phone}}':'+211 912 345 678',
    '{{next_step}}':'Schedule installation',
};

var waCurrentKey = null;

function waOpenEdit(key) {
    waCurrentKey = key;
    var def = waEvents.find(function(e){ return e.key === key; });
    if (!def) return;
    var custom = waTpls[key] || {};

    document.getElementById('waModalTitle').textContent = def.label;
    document.getElementById('waModalKey').textContent   = key;
    document.getElementById('waModalBody').value        = custom.body || def.default_body;
    document.getElementById('waDefaultBody').textContent = def.default_body;
    document.getElementById('waModalEnabled').checked  = custom.enabled !== 0;

    var sender = custom.sender || def.default_sender;
    waSetSender(sender);

    // Var chips
    var chips = document.getElementById('waVarChips');
    chips.innerHTML = '';
    (def.vars||[]).forEach(function(v){
        var c = document.createElement('span');
        c.className = 'wa2-var-chip'; c.textContent = v;
        c.onclick = function(){ waInsertVar(v); };
        chips.appendChild(c);
    });

    waUpdatePreview();
    document.getElementById('waModalBg').classList.add('open');
    document.body.style.overflow = 'hidden';
    // Update enabled label
    waToggleEnabledLabel();
}

function waCloseEdit(){
    document.getElementById('waModalBg').classList.remove('open');
    document.body.style.overflow = '';
    waCurrentKey = null;
}

function waSetSender(s){
    document.getElementById('waModalSender').value = s;
    document.getElementById('wa-opt-support').className  = 'wa2-sender-opt' + (s==='support'  ? ' sel-support'  : '');
    document.getElementById('wa-opt-accounts').className = 'wa2-sender-opt' + (s==='accounts' ? ' sel-accounts' : '');
}

function waUpdatePreview(){
    var body = document.getElementById('waModalBody').value;
    var preview = body;
    Object.keys(waSampleVars).forEach(function(k){ preview = preview.split(k).join(waSampleVars[k]); });
    // Bold *text* → bold styling
    preview = preview.replace(/\*([^*]+)\*/g, '$1');
    document.getElementById('waPreview').textContent = preview;
}

function waToggleEnabledLabel(){
    var cb  = document.getElementById('waModalEnabled');
    var lbl = document.getElementById('waEnabledLabel');
    lbl.textContent = cb.checked ? 'Enabled — this message will send' : 'Disabled — this message is paused';
    lbl.style.color = cb.checked ? '#15803d' : '#991b1b';
}
document.getElementById('waModalEnabled').addEventListener('change', waToggleEnabledLabel);

function waInsertVar(v){
    var ta  = document.getElementById('waModalBody');
    var pos = ta.selectionStart;
    ta.value = ta.value.slice(0, pos) + v + ta.value.slice(ta.selectionEnd);
    ta.selectionStart = ta.selectionEnd = pos + v.length;
    ta.focus();
    waUpdatePreview();
}

function waSave(){
    document.getElementById('fTplKey').value    = waCurrentKey;
    document.getElementById('fTplBody').value   = document.getElementById('waModalBody').value;
    document.getElementById('fTplSender').value = document.getElementById('waModalSender').value;
    document.getElementById('fTplEnabled').value = document.getElementById('waModalEnabled').checked ? '1' : '0';
    if (!document.getElementById('waModalEnabled').checked) {
        // Add a hidden disabled field
        document.getElementById('fTplEnabled').value = '0';
    }
    document.getElementById('waSaveForm').submit();
}

function waReset(){
    var def = waEvents.find(function(e){ return e.key === waCurrentKey; });
    if (!def) return;
    if (!confirm('Reset "' + def.label + '" to the code default? Your customisation will be lost.')) return;
    document.getElementById('fResetKey').value = waCurrentKey;
    document.getElementById('waResetForm').submit();
}

function waTestSend(){
    var phone = document.getElementById('waTestPhone').value.replace(/[^0-9]/g,'');
    if (!phone) { alert('Enter a phone number to test-send to.'); return; }
    if (!confirm('Send test message for "' + waCurrentKey + '" to ' + phone + '?')) return;
    document.getElementById('fTestKey').value    = waCurrentKey;
    document.getElementById('fTestBody').value   = document.getElementById('waModalBody').value;
    document.getElementById('fTestSender').value = document.getElementById('waModalSender').value;
    document.getElementById('fTestPhone').value  = phone;
    document.getElementById('waTestForm').submit();
}

// Close on Escape
document.addEventListener('keydown', function(e){ if(e.key==='Escape') waCloseEdit(); });


// Auto-scroll to section if ?section= in URL
(function(){
    var sec = '<?= h($settingsSection ?? '') ?>';
    if (sec) {
        var el = document.getElementById('settings-' + sec);
        if (el) setTimeout(function(){ el.scrollIntoView({behavior:'smooth', block:'start'}); }, 300);
    }
})();
</script>

<script>
// ── Manual "Convert to Lead" from conversation card ──────────────────────
function convToLead(convId, name, phone) {
    if (!confirm('Create a lead for ' + (name || phone) + '?\n\nThey will be assigned to the next available agent.')) return;
    fetch('?page=api&action=conv_to_lead', {
        credentials: 'same-origin',
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Authorization': 'Bearer <?= h($retailer['api_token'] ?? '') ?>'},
        body: JSON.stringify({conv_id: convId})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.status === 'success') {
            alert('✅ Lead #' + (d.data?.lead_id || '') + ' created and assigned to ' + (d.data?.assigned_to || 'agent'));
            location.reload();
        } else {
            alert('❌ ' + (d.message || 'Error'));
        }
    })
    .catch(function(e){ alert('❌ Network error: ' + e); });
}
</script>


