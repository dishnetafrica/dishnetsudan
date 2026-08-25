<?php
// ═══════════════════════════════════════════════════════════════
// LEADS / CALL CENTER
// ═══════════════════════════════════════════════════════════════


    // ── POST upload_call_recording — Android app multipart audio upload ────────
    if ($act === 'upload_call_recording' && $met === 'POST') {
        if (!isset($_FILES['recording'])) $er2('No recording file uploaded.', 422);
        $file   = $_FILES['recording'];
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $phone  = trim($_POST['phone'] ?? '');
        $agent  = trim($_POST['agent_name'] ?? ($me2['name'] ?? 'Unknown'));
        $dir    = $dataDir . '/call_recordings/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'mp4';
        $filename = date('Ymd_His') . '_lead' . $leadId . '_' . preg_replace('/[^0-9+]/', '', $phone) . '.' . $ext;
        $dest     = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) $er2('Failed to save recording.', 500);
        // Log to call_recordings.json
        $recs = $store->load('call_recordings.json') ?? [];
        $recs[] = [
            'id'         => count($recs) + 1,
            'lead_id'    => $leadId,
            'phone'      => $phone,
            'agent'      => $agent,
            'filename'   => $filename,
            'size'       => $file['size'],
            'uploaded_at'=> date('Y-m-d H:i:s'),
        ];
        $store->save('call_recordings.json', $recs);
        $ok2(['saved' => true, 'filename' => $filename]);
    }


    // ── POST convert_to_customer — mark Credit KYC application as paid → converts CRM Lead ──
    if ($act === 'convert_to_customer' && $met === 'POST') {
        $appId = (int)($body['application_id'] ?? 0);
        if (!$appId) $er2('application_id required.', 422);
        $app = $store->findOne('kyc_applications.json', 'id', $appId);
        if (!$app) $er2('Application not found.', 404);
        if ((int)($app['retailer_id'] ?? 0) !== (int)($me2['id'] ?? 0) && !($me2['is_admin'] ?? false))
            $er2('Access denied.', 403);
        $crmClientId = $app['crm_client_id'] ?? null;
        if (!$crmClientId) $er2('No CRM client ID on this application — cannot convert.');
        if (empty($app['is_lead'])) $er2('This customer is already a Regular Customer — no conversion needed.');
        $paymentMethod = trim($body['payment_method'] ?? 'Cash');
        $paymentRef    = trim($body['payment_reference'] ?? '');
        $paymentNote   = trim($body['note'] ?? '');
        $patchResult = $crm->patch("clients/{$crmClientId}", ['isLead' => false]);
        if ($patchResult === null) $er2('CRM update failed: ' . json_encode($crm->getLastError()), 502);
        $amount = (float)($app['amount_charged'] ?? 0);
        $paymentId = null;
        if ($amount > 0) {
            $payResp = $crm->post('payments', [
                'clientId'     => (int)$crmClientId,
                'amount'       => $amount,
                'currencyCode' => 'USD',
                'methodId'     => PaymentUuids::resolve($paymentMethod),
                'note'         => 'Payment received — Lead converted to Regular Customer.'
                                . ($paymentRef ? ' Ref: ' . $paymentRef : '')
                                . ($paymentNote ? ' | ' . $paymentNote : '')
                                . ' | Agent: ' . ($me2['name'] ?? ''),
                'applyToInvoicesAutomatically' => true,
            ]);
            if (!empty($payResp['id'])) $paymentId = $payResp['id'];
        }
        $store->updateOne('kyc_applications.json', 'id', $appId, [
            'is_lead'        => false,
            'converted_at'   => date('Y-m-d H:i:s'),
            'converted_by'   => $me2['name'] ?? 'Agent',
            'payment_method' => $paymentMethod,
            'payment_ref'    => $paymentRef,
            'crm_payment_id' => $paymentId,
            'status'         => 'converted',
        ]);
        $ok2([
            'crm_client_id'  => $crmClientId,
            'application_id' => $appId,
            'payment_id'     => $paymentId,
            'amount'         => $amount,
            'converted_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    // ── POST update_customer_details — agent updates phone/email/address → syncs CRM ──
    if ($act === 'update_customer_details' && $met === 'POST') {
        $appId = (int)($body['application_id'] ?? 0);
        if (!$appId) $er2('application_id required.', 422);
        $app = $store->findOne('kyc_applications.json', 'id', $appId);
        if (!$app) $er2('Application not found.', 404);
        if ((int)($app['retailer_id'] ?? 0) !== (int)($me2['id'] ?? 0) && !($me2['is_admin'] ?? false))
            $er2('Access denied.', 403);
        $crmClientId = $app['crm_client_id'] ?? null;
        if (!$crmClientId) $er2('No CRM client linked to this application.');
        $newMobile   = trim($body['mobile']   ?? '');
        $newEmail    = trim($body['email']    ?? '');
        $newAddress  = trim($body['address']  ?? '');
        $newUsername = trim($body['username'] ?? '');
        $updates = []; $crmPatch = []; $localUpdates = [];
        if ($newMobile !== '' && $newMobile !== ($app['mobile'] ?? '')) {
            $crmPatch['contacts'][]  = ['phone' => $newMobile, 'email' => $newEmail ?: ($app['email'] ?? ''), 'name' => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''))];
            $localUpdates['mobile']  = $newMobile;
            $updates[]               = "📞 Mobile → {$newMobile}";
        }
        if ($newEmail !== '' && $newEmail !== ($app['email'] ?? '')) {
            $crmPatch['contacts'][]  = ['phone' => $newMobile ?: ($app['mobile'] ?? ''), 'email' => $newEmail, 'name' => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''))];
            $localUpdates['email']   = $newEmail;
            $updates[]               = "📧 Email → {$newEmail}";
        }
        if ($newAddress !== '' && $newAddress !== ($app['address_1'] ?? '')) {
            $crmPatch['street1']       = $newAddress;
            $localUpdates['address_1'] = $newAddress;
            $updates[]                 = "🏠 Address → {$newAddress}";
        }
        if ($newUsername !== '' && $newUsername !== ($app['username'] ?? '')) {
            $crmPatch['username']     = $newUsername;
            $localUpdates['username'] = $newUsername;
            $updates[]                = "👤 Username → {$newUsername}";
        }
        if (empty($crmPatch) && empty($localUpdates)) $er2('No changes detected — nothing to update.');
        if (!empty($crmPatch['contacts'])) $crmPatch['contacts'] = [array_pop($crmPatch['contacts'])];
        $patchResult = $crm->patch("clients/{$crmClientId}", $crmPatch);
        if ($patchResult === null) $er2('CRM update failed: ' . json_encode($crm->getLastError()), 502);
        $localUpdates['last_detail_update']    = date('Y-m-d H:i:s');
        $localUpdates['last_update_by']        = $me2['name'] ?? 'Agent';
        $localUpdates['detail_update_history'] = array_merge(
            $app['detail_update_history'] ?? [],
            [['by' => $me2['name'] ?? 'Agent', 'at' => date('Y-m-d H:i:s'), 'changes' => implode(', ', $updates)]]
        );
        $store->updateOne('kyc_applications.json', 'id', $appId, $localUpdates);
        $ok2(['crm_client_id' => $crmClientId, 'changes' => $updates, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    // ── POST log_call — agent logs a call outcome against a lead ─────────────
    if ($act === 'log_call' && $met === 'POST') {
        $leadId    = (int)($body['lead_id'] ?? 0);
        if (!$leadId) $er2('lead_id required.', 422);
        $outcome   = trim($body['outcome'] ?? 'no_answer');
        $note      = trim($body['note'] ?? '');
        $duration  = (int)($body['duration_seconds'] ?? 0);
        $followUp  = trim($body['follow_up_date'] ?? '');
        $newStatus = trim($body['new_status'] ?? '');
        $validStatuses = ['open','contacted','interested','quoted','qualified','lost'];
        $validOutcomes = ['answered','no_answer','busy','callback','interested','not_interested','voicemail'];
        if (!in_array($outcome, $validOutcomes)) $outcome = 'no_answer';
        $leads = $store->load('leads.json') ?? [];
        $found = false;
        $myId  = (int)($me2['id'] ?? 0);
        foreach ($leads as &$l) {
            if ((int)($l['id'] ?? 0) !== $leadId) continue;
            if ((int)($l['retailer_id'] ?? 0) !== $myId &&
                (int)($l['assigned_to'] ?? 0) !== $myId &&
                !($me2['is_admin'] ?? false) &&
                !in_array($me2['role'] ?? '', ['sales','sales_staff','field_agent','collection','support_leader'])) $er2('Access denied.', 403);
            $callEntry = [
                'id'       => count($l['call_log'] ?? []) + 1,
                'by'       => $me2['name'] ?? 'Agent',
                'by_id'    => $myId,
                'at'       => date('Y-m-d H:i:s'),
                'outcome'  => $outcome,
                'note'     => $note,
                'duration' => $duration,
            ];
            if (!isset($l['call_log'])) $l['call_log'] = [];
            $l['call_log'][]   = $callEntry;
            $l['total_calls']  = count($l['call_log']);
            $l['last_caller']  = $me2['name'] ?? 'Agent';
            $l['last_call_at'] = $callEntry['at'];
            if ($newStatus && in_array($newStatus, $validStatuses)) $l['status'] = $newStatus;
            if ($newStatus === '' && in_array($outcome, ['answered','interested'])) {
                if (($l['status'] ?? '') === 'open') $l['status'] = 'contacted';
            }
            if ($outcome === 'not_interested') $l['status'] = 'lost';
            if ($followUp) $l['follow_up_date'] = $followUp;
            unset($l['stale_flagged'], $l['stale_reassigned']);
            // Reset 45/60-min alert flags — call was made, no more urgency
            unset($l['wa_45min_sent'], $l['wa_60min_sent']);
            $l['updated_at'] = date('Y-m-d H:i:s');

            // ── 3-strike rule: auto-dead after max no-answer attempts ──────
            $maxAttempts = (int)($config['lead_max_attempts'] ?? 3);
            $noAnswerOutcomes = ['no_answer', 'busy', 'voicemail'];
            if (in_array($outcome, $noAnswerOutcomes, true)) {
                // Count no-answer calls specifically
                $naCount = count(array_filter($l['call_log'] ?? [], function($cl) use ($noAnswerOutcomes) {
                    return in_array($cl['outcome'] ?? '', $noAnswerOutcomes, true);
                }));
                if ($naCount >= $maxAttempts) {
                    $l['status'] = 'lost';
                    $l['lost_reason'] = 'auto_dead_max_attempts';
                    $l['lost_at']     = date('Y-m-d H:i:s');
                    // Flag for T6 (farewell WA) — sent by cron_lead_alerts or inline below
                    $l['wa_farewell_needed'] = 1;
                }
            }
            $found = true;
            break;
        }
        unset($l);
        if (!$found) $er2('Lead not found.', 404);
        $store->save('leads.json', $leads);
        $updatedLead = null;
        foreach ($leads as $l2) { if ((int)($l2['id']??0) === $leadId) { $updatedLead = $l2; break; } }

        // ── Auto-WA to customer based on outcome ──────────────────────────
        $leadPhone = preg_replace('/[^0-9+]/', '', $updatedLead['phone'] ?? '');
        $leadName  = explode(' ', trim($updatedLead['customer_name'] ?? 'there'))[0];
        if ($leadPhone) {
            try {
                require_once dirname(__DIR__, 2) . '/lib/NotificationService.php';
                $notifyAuto = new NotificationService($store, $config);
                $waMsg = '';
                if (in_array($outcome, ['no_answer','busy','voicemail'], true)) {
                    if (!empty($updatedLead['wa_farewell_needed'])) {
                        // T6 — Dead lead farewell
                        $waMsg = "Hi {$leadName}! We tried reaching you a few times about DishNet Fiber.\n\n"
                               . "We'll leave it here for now — but whenever you're ready for fast fiber internet, we're just a message away 🌐\n\n"
                               . "wa.me/211923400000";
                    } else {
                        // T2 — No answer, will retry
                        $waMsg = "Hi {$leadName}! 👋 We just tried calling you from DishNet Fiber.\n\n"
                               . "We'll try again soon. If you'd like us to call at a specific time, "
                               . "just reply with a time and we'll call exactly then ⏰";
                    }
                } elseif ($outcome === 'interested') {
                    // T3 — Interested confirmation
                    $waMsg = "Hi {$leadName}! 🎉 Great talking to you.\n\n"
                           . "Here's what happens next:\n"
                           . "✅ Our fiber specialist will call you within 2 hours\n"
                           . "✅ He'll confirm your area coverage and pricing\n"
                           . "✅ If you're happy, we can schedule installation this week\n\n"
                           . "Any questions? Just reply here 💬";
                } elseif ($outcome === 'not_interested') {
                    // T6 — Not interested farewell
                    $waMsg = "Hi {$leadName}, thanks for your time! 🙏\n\n"
                           . "No problem at all. Whenever you need fast fiber internet in future, "
                           . "we're just a message away 🌐\n\n"
                           . "wa.me/211923400000";
                } elseif ($outcome === 'callback' && !empty($updatedLead['follow_up_date'])) {
                    // T4 — Callback scheduled
                    $waMsg = "Hi {$leadName}! Thanks for speaking with us 📞\n\n"
                           . "We'll call you back on *" . $updatedLead['follow_up_date'] . "* as agreed.\n\n"
                           . "If this time doesn't work, just reply and we'll reschedule 😊";
                }
                if ($waMsg) {
                    $notifyAuto->sendRaw($leadPhone, $waMsg, 'lead_outcome_auto_wa');
                }
            } catch (\Throwable $waEx) {
                // Non-fatal — never block the log_call response
                error_log('[log_call] Auto-WA failed: ' . $waEx->getMessage());
            }
        }

        $ok2([
            'lead_id'    => $leadId,
            'call_count' => $updatedLead['total_calls'] ?? 1,
            'outcome'    => $outcome,
            'new_status' => $updatedLead['status'] ?? '',
            'auto_dead'  => !empty($updatedLead['wa_farewell_needed']),
        ]);
    }

    // ── GET get_call_recording — serve a recording file ──────────────────────
    if ($act === 'get_call_recording' && $met === 'GET') {
        // Accept token via ?token= for <audio src> embedding
        if (empty($me2['id'])) {
            $qToken = trim($_GET['token'] ?? '');
            if ($qToken) {
                foreach (($store->load('retailers.json') ?? []) as $r) {
                    if (($r['api_token'] ?? '') === $qToken && !empty($r['is_active'])) {
                        $me2 = $r; break;
                    }
                }
            }
            if (empty($me2['id'])) $er2('Unauthorised.', 401);
        }
        $file = trim($_GET['file'] ?? '');
        if (!$file) $er2('file param required.', 422);
        $safe = basename($file);
        $path = $dataDir . '/call_recordings/' . $safe;
        if (!file_exists($path)) $er2('Recording not found.', 404);
        $ext     = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
        $mimeMap = ['mp3'=>'audio/mpeg','m4a'=>'audio/mp4','ogg'=>'audio/ogg','wav'=>'audio/wav','opus'=>'audio/opus','3gp'=>'audio/3gpp'];
        $mime    = $mimeMap[$ext] ?? 'audio/aac';
        header("Content-Type: {$mime}");
        header("Content-Length: " . filesize($path));
        header("Content-Disposition: inline; filename=\"{$safe}\"");
        header("Accept-Ranges: bytes");
        header("Cache-Control: private, max-age=86400");
        readfile($path);
        exit;
    }

    // ── POST run_leads_cron — manually trigger lead cron (admin only) ─────────
    if ($act === 'run_leads_cron' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);
        $cronPath = dirname(__DIR__, 2) . '/cron_leads.php';
        if (!file_exists($cronPath)) $er2('cron_leads.php not found.', 404);
        exec('php ' . escapeshellarg($cronPath) . ' 2>&1', $output, $code);
        $ok2(['exit_code' => $code, 'output' => implode("\n", array_slice($output, -20))]);
    }

    // ── POST bulk_archive_leads — admin only, archive all leads before date ──
    if ($act === 'bulk_archive_leads' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);
        $beforeDate = trim($body['before_date'] ?? date('Y-m-d'));
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDate)) {
            $er2('Invalid date format. Use YYYY-MM-DD.');
        }
        $leads    = $store->load('leads.json') ?? [];
        $archived = 0;
        $now      = date('Y-m-d H:i:s');
        foreach ($leads as &$l) {
            if (!empty($l['archived'])) continue; // already archived
            $created = substr($l['created_at'] ?? '9999-12-31', 0, 10);
            if ($created < $beforeDate) {
                $l['archived']    = 1;
                $l['archived_at'] = $now;
                $l['archived_by'] = $me2['name'] ?? 'Admin';
                $archived++;
            }
        }
        unset($l);
        $store->save('leads.json', $leads);
        $ok2(['archived' => $archived, 'before_date' => $beforeDate]);
    }

    // ── POST conv_to_lead — manually convert a WA conversation to a lead ────
    if ($act === 'conv_to_lead' && $met === 'POST') {
        $convId = (int)($body['conv_id'] ?? 0);
        if (!$convId) $er2('conv_id required.');

        $pdo2 = $store->getPdo();

        // Load conversation
        $cStmt = $pdo2->prepare("SELECT * FROM wa_conversations WHERE id = ?");
        $cStmt->execute([$convId]);
        $conv2 = $cStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$conv2) $er2('Conversation not found.', 404);
        if (!empty($conv2['lead_id'])) $er2('Lead already created for this conversation (#' . $conv2['lead_id'] . ').');
        if (!empty($conv2['crm_client_id'])) $er2('This is an existing CRM customer — not a lead.');

        $phone3 = trim($conv2['phone'] ?? '');
        if (empty($phone3)) $er2('No phone number on this conversation.');

        // Dedup: if active lead already exists for this phone, just link and return
        $allLeads3 = $store->load('leads.json') ?? [];
        $suffix3   = substr(preg_replace('/[^0-9]/', '', $phone3), -9);
        foreach ($allLeads3 as $el3) {
            $es = substr(preg_replace('/[^0-9]/', '', $el3['phone'] ?? ''), -9);
            if ($es && $es === $suffix3 && !in_array($el3['status'] ?? '', ['won','lost','dead'], true)) {
                $pdo2->prepare("UPDATE wa_conversations SET lead_id = ? WHERE id = ?")
                     ->execute([(int)$el3['id'], $convId]);
                $ok2(['lead_id' => (int)$el3['id'], 'assigned_to' => $el3['assigned_name'] ?? ''],
                     'Linked to existing lead #' . $el3['id']);
            }
        }

        // Smart-assign: lightest loaded active sales agent (respects lead_assignee_ids whitelist)
        $salesRoles3  = ['sales', 'field_agent', 'sales_staff'];
        $_allowedIds3 = array_filter(array_map('intval', explode(',', $config['lead_assignee_ids'] ?? '')));
        $retailers3   = $store->load('retailers.json') ?? [];
        $agents3      = array_filter($retailers3, function($r) use ($salesRoles3, $_allowedIds3) {
            if (empty($r['is_active'])) return false;
            if (!in_array($r['role'] ?? '', $salesRoles3, true)) return false;
            if (!empty($_allowedIds3) && !in_array((int)$r['id'], $_allowedIds3, true)) return false;
            return true;
        });
        $assignTo3 = null;
        if (!empty($agents3)) {
            $loads3 = [];
            foreach ($agents3 as $ag3) {
                $aid3 = (int)$ag3['id'];
                $loads3[$aid3] = count(array_filter($allLeads3, function($l) use ($aid3) {
                    return (int)($l['assigned_to'] ?? 0) === $aid3
                        && !in_array($l['status'] ?? '', ['won','lost','dead'], true)
                        && empty($l['archived']);
                }));
            }
            $lightId3 = array_keys($loads3, min($loads3))[0];
            foreach ($agents3 as $ag3) {
                if ((int)$ag3['id'] === $lightId3) { $assignTo3 = $ag3; break; }
            }
        }

        $now3 = date('Y-m-d H:i:s');
        $newLead3 = [
            'id'                => $store->nextId('leads.json'),
            'customer_name'     => $conv2['display_name'] ?? $phone3,
            'phone'             => $phone3,
            'service_type'      => 'fiber',
            'source'            => 'whatsapp_marketing',
            'source_detail'     => 'Manually converted from WA Inbox',
            'priority'          => 'high',
            'notes'             => $conv2['last_message_preview'] ?? '',
            'retailer_id'       => $assignTo3 ? (int)$assignTo3['id'] : 0,
            'assigned_to'       => $assignTo3 ? (int)$assignTo3['id'] : null,
            'assigned_name'     => $assignTo3 ? ($assignTo3['name'] ?? '') : '',
            'assigned_by'       => $me2['name'] ?? 'Admin',
            'assigned_at'       => $now3,
            'daily_assign_to'   => $assignTo3 ? (int)$assignTo3['id'] : null,
            'daily_assign_name' => $assignTo3 ? ($assignTo3['name'] ?? '') : '',
            'daily_assign_date' => date('Y-m-d'),
            'status'            => 'open',
            'wa_assigned_notified' => 0,
            'created_at'        => $now3,
            'updated_at'        => $now3,
        ];
        $allLeads3[] = $newLead3;
        $store->save('leads.json', $allLeads3);

        // Link conversation to new lead
        $pdo2->prepare("UPDATE wa_conversations SET lead_id = ? WHERE id = ?")
             ->execute([(int)$newLead3['id'], $convId]);

        $ok2(
            ['lead_id' => (int)$newLead3['id'], 'assigned_to' => $assignTo3['name'] ?? 'Unassigned'],
            "✅ Lead #{$newLead3['id']} created — assigned to " . ($assignTo3['name'] ?? 'nobody')
        );
    }

    // ── POST save_lead_assignees — save which agents receive new leads ────────
    if ($act === 'save_lead_assignees' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);
        $ids = array_filter(array_map('intval', (array)($body['ids'] ?? [])));
        $config2 = $store->load('kyc_config.json') ?? [];
        $config2['lead_assignee_ids'] = implode(',', $ids);
        $store->save('kyc_config.json', $config2);
        $ok2(['ids' => $ids, 'count' => count($ids)],
             count($ids) === 0 ? 'All agents will receive leads' : count($ids).' agents saved');
    }
