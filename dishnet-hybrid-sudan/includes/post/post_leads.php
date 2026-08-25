<?php
// ═══════════════════════════════════════════════════════════════
// SUPPORT TICKETS / LEADS / SQLITE TOOLS
// ═══════════════════════════════════════════════════════════════



// ── Support Tickets ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create_support_ticket') {
    $retailer = $auth->requireLogin();
    $tickets = $store->load('support_tickets.json') ?: [];
    $ticket = [
        'id' => $store->nextId('support_tickets.json'),
        'customer_name' => trim($_POST['tk_customer_name'] ?? ''),
        'customer_id' => trim($_POST['tk_customer_id'] ?? ''),
        'subject' => trim($_POST['tk_subject'] ?? ''),
        'description' => trim($_POST['tk_description'] ?? ''),
        'priority' => trim($_POST['tk_priority'] ?? 'medium'),
        'category' => trim($_POST['tk_category'] ?? 'other'),
        'status' => 'open',
        'assigned_to' => (int)$retailer['id'],
        'assigned_name' => $retailer['name'],
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $tickets[] = $ticket;
    $store->save('support_tickets.json', $tickets);
    flash('Ticket #' . $ticket['id'] . ' created.', 'success');
    redirect('?page=dashboard&tab=support_tickets');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_ticket_status') {
    $auth->requireLogin();
    $tkId = (int)($_POST['tk_id'] ?? 0);
    $newSt = trim($_POST['tk_status'] ?? '');
    if (in_array($newSt, ['open','in_progress','resolved','closed'])) {
        $tickets = $store->load('support_tickets.json') ?: [];
        foreach ($tickets as &$t) {
            if ((int)($t['id']??0) === $tkId) {
                $t['status'] = $newSt;
                $t['updated_at'] = date('Y-m-d H:i:s');
                if ($newSt === 'resolved') $t['resolved_at'] = date('Y-m-d H:i:s');
                break;
            }
        }
        unset($t);
        $store->save('support_tickets.json', $tickets);
        flash('Ticket updated.', 'success');
    }
    redirect('?page=dashboard&tab=support_tickets');
}

// ── Lead Management ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_lead') {
    $retailer = $auth->requireLogin();
    $rid = (int)$retailer['id'];
    $leads = $store->load('leads.json');
    $leadId = (int)($_POST['lead_id'] ?? 0);

    $lead = [
        // ── Basic contact info ──────────────────────────────────────────
        'customer_name'  => trim($_POST['lead_name']     ?? ''),
        'firstname'      => trim($_POST['lead_firstname'] ?? ''),
        'lastname'       => trim($_POST['lead_lastname']  ?? ''),
        'phone'          => trim($_POST['lead_phone']     ?? ''),
        'email'          => trim($_POST['lead_email']     ?? ''),
        'address'        => trim($_POST['lead_address']   ?? ''),
        // ── Interest & classification ───────────────────────────────────
        'service_type'   => trim($_POST['lead_service']   ?? 'starlink'),
        'interest_plan'  => trim($_POST['lead_plan']      ?? ''),
        'source'         => trim($_POST['lead_source']    ?? ''),
        'source_detail'  => trim($_POST['lead_source_detail'] ?? ''), // e.g. "Facebook Ad #23", "Cold Call - referral from X"
        'priority'       => trim($_POST['lead_priority']  ?? 'medium'),
        'follow_up_date' => trim($_POST['lead_followup']  ?? ''),
        'notes'          => trim($_POST['lead_notes']     ?? ''),
        // ── Full KYC details (optional at lead stage, used at conversion) ─
        'nid_number'     => trim($_POST['lead_nid']       ?? ''),
        'nationality'    => trim($_POST['lead_nationality'] ?? ''),
        'dob'            => trim($_POST['lead_dob']        ?? ''),
        'address_2'      => trim($_POST['lead_address2']   ?? ''),
        'sales_type'     => trim($_POST['lead_sales_type'] ?? 'Cash'), // Cash or Credit — determines CRM lead vs customer on conversion
        'connectivity_type' => trim($_POST['lead_connectivity'] ?? 'New Connection'),
        'crm_sales_person'  => trim($_POST['lead_sales_person'] ?? ''),
        'updated_at'     => date('Y-m-d H:i:s'),
    ];

    // Derive full name if separate first/last provided
    if ($lead['firstname'] && $lead['lastname']) {
        $lead['customer_name'] = $lead['firstname'] . ' ' . $lead['lastname'];
    } elseif ($lead['customer_name'] && !$lead['firstname']) {
        $parts = explode(' ', $lead['customer_name'], 2);
        $lead['firstname'] = $parts[0];
        $lead['lastname']  = $parts[1] ?? '';
    }

    if ($leadId > 0) {
        foreach ($leads as &$l) { if ((int)($l['id']??0)===$leadId) { $l = array_merge($l, $lead); break; } }
        unset($l);
        flash('Lead updated.','success');
    } else {
        $lead['id']           = $store->nextId('leads.json');
        $lead['retailer_id']  = $rid;
        $lead['retailer_name']= $retailer['name'];
        $lead['status']       = 'open';
        $lead['qualified']    = false;  // admin must qualify before CRM push
        $lead['created_at']   = date('Y-m-d H:i:s');
        $leads[] = $lead;
        flash('Lead added.','success');
        // v4.11.3 PERF: Invalidate leads badge cache for this retailer
        try { if (function_exists('invalidateNavCache')) { $store->getPdo()->prepare("DELETE FROM plugin_kv WHERE key=?")->execute(['leads_badge_' . ($retailer['id'] ?? 0)]); } } catch (\Throwable $e) {}
    }
    $store->save('leads.json', $leads);
    redirect('?page=dashboard&tab=leads');
}

// ── Send Quote — Lead (Flow B) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='send_lead_quote') {
    $retailer = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/QuotationService.php';

    $leadId = (int)($_POST['lead_id'] ?? 0);
    $leads  = $store->load('leads.json') ?? [];
    $lead   = null;
    foreach ($leads as $l) { if ((int)($l['id']??0)===$leadId) { $lead=$l; break; } }
    if (!$lead) { flash('Lead not found.','danger'); redirect('?page=dashboard&tab=leads'); }

    // Parse items from POST (simple: label|price pairs, or JSON)
    $items = [];
    $rawItems = json_decode($_POST['quote_items_json'] ?? '[]', true) ?? [];
    foreach ($rawItems as $it) {
        $price = (float)preg_replace('/[^0-9.]/', '', (string)($it['price'] ?? '0'));
        if ($price > 0 || !empty($it['label'])) {
            $items[] = [
                'label'    => trim($it['label'] ?? 'Item'),
                'quantity' => max(1, (int)($it['quantity'] ?? 1)),
                'price'    => $price,
                'unit'     => trim($it['unit'] ?? 'month'),
            ];
        }
    }

    $cfg       = $store->load('kyc_config.json') ?: [];
    $quotSvc   = new QuotationService($store, $dataDir, $cfg);
    $createCrm = ($_POST['create_crm_quote'] ?? '0') === '1';
    $result    = $quotSvc->sendLeadQuote($lead, $items, $retailer, $createCrm);

    if ($result['ok']) {
        $extra = $result['sent_via_wa'] ? ' WhatsApp sent ✅' : ' (WA not configured)';
        flash("Quotation {$result['quote_ref']} sent to {$lead['customer_name']}.{$extra}", 'success');
    } else {
        flash('Quote failed: ' . ($result['error'] ?? 'Unknown error.'), 'danger');
    }
    redirect('?page=dashboard&tab=leads');
}

// ── Send Cash Proforma (Flow C) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='send_cash_proforma') {
    $retailer = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/QuotationService.php';

    $custName  = trim($_POST['cq_customer_name']  ?? '');
    $custPhone = trim($_POST['cq_customer_phone'] ?? '');
    $paid      = (float)($_POST['cq_amount_paid'] ?? 0);
    $rawItems  = json_decode($_POST['cq_items_json'] ?? '[]', true) ?? [];

    $items = [];
    foreach ($rawItems as $it) {
        $price = (float)preg_replace('/[^0-9.]/', '', (string)($it['price'] ?? '0'));
        if ($price > 0) {
            $items[] = ['label'=>trim($it['label']??'Item'), 'quantity'=>max(1,(int)($it['quantity']??1)), 'price'=>$price, 'unit'=>trim($it['unit']??'amount')];
        }
    }

    if (!$custName || !$custPhone || empty($items)) {
        flash('Name, phone, and at least one item are required.', 'danger');
        redirect('?page=dashboard&tab=cash_quote');
    }

    $cfg     = $store->load('kyc_config.json') ?: [];
    $quotSvc = new QuotationService($store, $dataDir, $cfg);
    $result  = $quotSvc->sendCashSaleProforma($custName, $custPhone, $items, $paid, $retailer);

    if ($result['ok']) {
        $waStatus = $result['sent_via_wa'] ? '✅ WhatsApp sent' : '⚠️ WhatsApp not configured';
        flash("Proforma {$result['quote_ref']} created. {$waStatus}", 'success');
    } else {
        flash('Failed: ' . ($result['error'] ?? 'Unknown error.'), 'danger');
    }
    redirect('?page=dashboard&tab=cash_quote');
}

// ── Manual Quote (Flow D) — also accessible from leads tab ───────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='send_manual_quote') {
    $retailer = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/QuotationService.php';

    $rawItems  = json_decode($_POST['mq_items_json'] ?? '[]', true) ?? [];
    $items = [];
    foreach ($rawItems as $it) {
        $price = (float)preg_replace('/[^0-9.]/', '', (string)($it['price'] ?? '0'));
        if ($price > 0) {
            $items[] = ['label'=>trim($it['label']??'Item'), 'quantity'=>max(1,(int)($it['quantity']??1)), 'price'=>$price, 'unit'=>trim($it['unit']??'amount')];
        }
    }

    $data = [
        'crm_client_id'   => (int)($_POST['mq_crm_client_id'] ?? 0),
        'customer_name'   => trim($_POST['mq_customer_name']  ?? ''),
        'customer_phone'  => trim($_POST['mq_customer_phone'] ?? ''),
        'items'           => $items,
        'note'            => trim($_POST['mq_note'] ?? ''),
        'create_crm_quote'=> ($_POST['mq_create_crm'] ?? '0') === '1',
    ];

    $cfg     = $store->load('kyc_config.json') ?: [];
    $quotSvc = new QuotationService($store, $dataDir, $cfg);
    $result  = $quotSvc->sendManualQuote($data, $retailer);

    if ($result['ok']) {
        $waStatus  = $result['sent_via_wa']  ? '✅ WA sent' : '⚠️ WA not configured';
        $crmStatus = $result['sent_via_crm'] ? ' · ✅ UCRM quote created' : '';
        flash("Quote {$result['quote_ref']} sent. {$waStatus}{$crmStatus}", 'success');
    } else {
        flash('Failed: ' . ($result['error'] ?? 'Unknown error.'), 'danger');
    }
    redirect('?page=dashboard&tab=leads');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='update_lead_status') {
    $retailer = $auth->requireLogin();
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $newStatus = trim($_POST['new_status'] ?? '');
    $valid = ['open','contacted','interested','quoted','qualified','won','lost'];
    if (!in_array($newStatus, $valid)) { flash('Invalid status.','danger'); redirect('?page=dashboard&tab=leads'); }

    $leads = $store->load('leads.json');
    $assignedAgentId = 0;
    foreach ($leads as &$l) {
        if ((int)($l['id']??0)===$leadId) {
            $l['status'] = $newStatus;
            $l['updated_at'] = date('Y-m-d H:i:s');
            if ($newStatus === 'won') $l['won_at'] = date('Y-m-d H:i:s');
            if ($newStatus === 'lost') $l['lost_at'] = date('Y-m-d H:i:s');
            unset($l['stale_flagged']); unset($l['stale_reassigned']);
            if (!isset($l['history'])) $l['history'] = [];
            $l['history'][] = ['status'=>$newStatus, 'by'=>$retailer['name'], 'at'=>date('Y-m-d H:i:s'), 'note'=>trim($_POST['status_note']??'')];
            $assignedAgentId = (int)($l['assigned_to'] ?? $l['retailer_id'] ?? 0);
            break;
        }
    }
    unset($l);
    $store->save('leads.json', $leads);

    // ── Instant drip: agent closes lead → immediately give them the next one ──
    if (in_array($newStatus, ['won','lost']) && $assignedAgentId > 0 &&
        ($config['lead_auto_assign_enabled'] ?? false)) {
        $dripSize2 = (int)($config['lead_drip_size'] ?? 5);
        $openSts2  = ['open','contacted','interested','quoted','qualified'];
        $freshLeads2 = $store->load('leads.json') ?? [];
        $myLoad2 = 0;
        foreach ($freshLeads2 as $fl2) {
            if ((int)($fl2['assigned_to']??0) === $assignedAgentId && in_array($fl2['status']??'', $openSts2)) $myLoad2++;
        }
        $canTake2 = max(0, $dripSize2 - $myLoad2);
        if ($canTake2 > 0) {
            $pool2 = array_values(array_filter($freshLeads2, fn($l2)=>in_array($l2['status']??'', $openSts2) && empty($l2['assigned_to'])));
            usort($pool2, function($a,$b){
                $pm=['high'=>0,'medium'=>1,'low'=>2];
                $pa=$pm[$a['priority']??'medium']??1; $pb=$pm[$b['priority']??'medium']??1;
                return $pa!==$pb ? $pa-$pb : strcmp($a['created_at']??'',$b['created_at']??'');
            });
            $newLeads2 = array_slice($pool2, 0, $canTake2);
            if (!empty($newLeads2)) {
                $ag2 = null;
                foreach ($store->load('retailers.json') ?? [] as $rr2) {
                    if ((int)($rr2['id']??0) === $assignedAgentId) { $ag2 = $rr2; break; }
                }
                if ($ag2 && !($ag2['on_leave']??false)) {
                    $now2 = date('Y-m-d H:i:s');
                    $newIds2 = array_column($newLeads2, 'id');
                    foreach ($freshLeads2 as &$fl2) {
                        if (in_array((int)($fl2['id']??0), $newIds2, true)) {
                            $fl2['assigned_to']   = $assignedAgentId;
                            $fl2['assigned_name'] = $ag2['name'];
                            $fl2['assigned_at']   = $now2;
                            $fl2['assigned_by']   = 'instant-drip';
                            if (!isset($fl2['history'])) $fl2['history'] = [];
                            $fl2['history'][] = ['status'=>$fl2['status']??'open','by'=>'Auto-Assign','at'=>$now2,'note'=>'Instant drip assigned'];
                        }
                    }
                    unset($fl2);
                    $store->save('leads.json', $freshLeads2);
                    try { $notify->leadBatchAssigned($ag2, $newLeads2, "Auto-assigned — you closed a lead. Here's your next queue 🎯"); } catch (\Throwable $e) {}
                }
            }
        }
    }

    flash("Lead moved to '{$newStatus}'.", "success");
    redirect('?page=dashboard&tab=leads');
}

// ── Admin: Qualify / Unqualify a lead ─────────────────────────────────────────
// Qualifying = admin verified this lead is real and ready for CRM push on conversion.
// Only qualified leads can be converted to KYC by the sales agent.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='qualify_lead') {
    $admin = $auth->requireAdmin();
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $leads  = $store->load('leads.json');
    foreach ($leads as &$l) {
        if ((int)($l['id']??0)===$leadId) {
            $l['qualified']    = true;
            $l['qualified_by'] = $admin['name'];
            $l['qualified_at'] = date('Y-m-d H:i:s');
            $l['status']       = 'qualified';
            if (!isset($l['history'])) $l['history'] = [];
            $l['history'][] = ['status'=>'qualified','by'=>$admin['name'],'at'=>date('Y-m-d H:i:s'),'note'=>'Qualified by admin — ready for KYC conversion'];
            break;
        }
    }
    unset($l);
    $store->save('leads.json', $leads);
    flash('Lead qualified ✓ — sales agent can now convert to KYC.','success');
    redirect('?page=dashboard&tab=' . ($_POST['back_tab'] ?? 'all_leads'));
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='unqualify_lead') {
    $auth->requireAdmin();
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $leads  = $store->load('leads.json');
    foreach ($leads as &$l) {
        if ((int)($l['id']??0)===$leadId) {
            $l['qualified'] = false;
            $l['status']    = 'contacted';
            if (!isset($l['history'])) $l['history'] = [];
            $l['history'][] = ['status'=>'unqualified','by'=>$_SESSION['kyc_retailer']['name']??'admin','at'=>date('Y-m-d H:i:s'),'note'=>'Qualification removed'];
            break;
        }
    }
    unset($l);
    $store->save('leads.json', $leads);
    flash('Lead qualification removed.','warning');
    redirect('?page=dashboard&tab=all_leads');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='convert_lead') {
    $retailer = $auth->requireLogin();
    $leadId   = (int)($_POST['lead_id'] ?? 0);
    $leads    = $store->load('leads.json');
    $lead     = null;
    foreach ($leads as &$l) { if ((int)($l['id']??0)===$leadId) { $lead = &$l; break; } }
    if (!$lead) { flash('Lead not found.','danger'); redirect('?page=dashboard&tab=leads'); }

    // ── Qualification gate ──────────────────────────────────────────────
    // Admin must qualify the lead before it can be converted to KYC.
    // This prevents unverified social-media prospects from being registered in CRM.
    if (empty($lead['qualified']) && !$isAdmin) {
        flash('This lead must be qualified by an admin before conversion. Ask your admin to mark it as Qualified.','danger');
        redirect('?page=dashboard&tab=leads');
    }

    // ── Mark lead as won (conversion initiated) ──────────────────────────
    $lead['status']       = 'won';
    $lead['won_at']       = date('Y-m-d H:i:s');
    $lead['converted_by'] = $retailer['name'];
    if (!isset($lead['history'])) $lead['history'] = [];
    $lead['history'][] = ['status'=>'won','by'=>$retailer['name'],'at'=>date('Y-m-d H:i:s'),'note'=>'Converted to KYC submission'];
    $store->save('leads.json', $leads);

    // ── Full KYC prefill from lead record ────────────────────────────────
    // Every field that was collected during lead nurturing is pre-filled.
    // Sales agent only needs to verify, add missing docs, and submit.
    //
    // Lead→CRM mapping on conversion:
    //   sales_type=Cash  → isLead:false → Regular Customer in CRM (paid upfront)
    //   sales_type=Credit→ isLead:true  → Client Lead in CRM (not yet paid)
    $_SESSION['kyc_prefill'] = [
        // Customer identity
        'firstname'          => $lead['firstname']  ?? explode(' ', $lead['customer_name'] ?? '')[0] ?? '',
        'lastname'           => $lead['lastname']   ?? (implode(' ', array_slice(explode(' ', $lead['customer_name'] ?? ''), 1)) ?: ''),
        'mobile'             => $lead['phone']      ?? '',
        'email'              => $lead['email']      ?? '',
        // Service & connection
        'customer_type'      => (strtolower($lead['service_type'] ?? 'starlink') === 'fiber' ? 'Fiber'
                                : (strtolower($lead['service_type'] ?? 'starlink') === 'sim' ? 'SIM' : 'StarLink')),
        'connectivity_type'  => $lead['connectivity_type'] ?? 'New Connection',
        // Address
        'address_1'          => $lead['address']   ?? '',
        'address_2'          => $lead['address_2'] ?? '',
        // KYC details collected during lead stage
        'nid_number'         => $lead['nid_number']    ?? '',
        'nationality'        => $lead['nationality']   ?? '',
        'dob'                => $lead['dob']           ?? '',
        // Payment type (determines CRM lead vs regular customer)
        'sales_type'         => $lead['sales_type']    ?? 'Cash',
        // Sales attribution
        'sales_person'       => $lead['crm_sales_person'] ?? '',
        'reference'          => $lead['source_detail'] ?? $lead['source'] ?? '',
        // Lead ID for back-reference
        'from_lead_id'       => $lead['id'],
    ];

    flash('✓ Lead converted! KYC form pre-filled — verify details and submit.','success');
    redirect('?page=dashboard&tab=form');
}
// ── Assign Leads ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='assign_leads') {
    $admin   = $auth->requireAdmin();
    $leadIds = array_map('intval', $_POST['lead_ids'] ?? []);
    $toId    = (int)($_POST['assign_to_id']   ?? 0);
    $toName  = trim($_POST['assign_to_name']  ?? '');

    // If name provided but no ID, look up retailer by name (case-insensitive)
    if (!$toId && $toName) {
        $retailers = $store->load('retailers.json');
        foreach ($retailers as $r) {
            if (strcasecmp(trim($r['name'] ?? ''), $toName) === 0) {
                $toId   = (int)$r['id'];
                $toName = $r['name'];
                break;
            }
        }
    }
    if (!$toId) { flash('Assign target not found.', 'danger'); redirect('?page=dashboard&tab=all_leads'); }

    $leads   = $store->load('leads.json');
    $changed = 0;
    foreach ($leads as &$l) {
        if (in_array((int)($l['id']??0), $leadIds, true)) {
            $l['assigned_to']   = $toId;
            $l['assigned_name'] = $toName;
            $l['assigned_at']   = date('Y-m-d H:i:s');
            $l['assigned_by']   = $admin['name'];
            $changed++;
        }
    }
    unset($l);
    $store->save('leads.json', $leads);
    // ONE WhatsApp summary per agent — not one per lead
    if ($toId && $changed > 0) {
        $assignedRetailer = $store->findOne('retailers.json', 'id', $toId);
        if ($assignedRetailer) {
            // Collect the actual lead objects that were just assigned
            $justAssigned = array_values(array_filter($leads, fn($l) => in_array((int)($l['id']??0), $leadIds, true)));
            if ($changed === 1) {
                try { $notify->leadAssigned($assignedRetailer, $justAssigned[0]); } catch (\Throwable $e) {}
            } else {
                try { $notify->leadBatchAssigned($assignedRetailer, $justAssigned); } catch (\Throwable $e) {}
            }
        }
    }
    flash("✓ {$changed} lead(s) assigned to {$toName}.", 'success');
    redirect('?page=dashboard&tab=all_leads');
}

// ── Set Lead Default Assignee ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='set_default_assignees') {
    $admin = $auth->requireAdmin();
    $cfg   = $store->load('kyc_config.json') ?? [];
    $cfg['default_lead_assignees'] = array_filter(array_map('trim', explode(',', $_POST['assignees'] ?? '')));
    $store->save('kyc_config.json', $cfg);
    flash('✓ Default assignees saved.', 'success');
    redirect('?page=dashboard&tab=all_leads');
}

// ── Save Lead Auto-Assign Settings ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_lead_auto_settings') {
    $admin = $auth->requireAdmin();
    $cfg   = $store->load('kyc_config.json') ?? [];
    $cfg['lead_auto_assign_enabled']    = !empty($_POST['lead_auto_assign_enabled']);
    $cfg['lead_drip_size']              = max(1, min(20, (int)($_POST['lead_drip_size'] ?? 5)));
    $cfg['lead_stale_flag_hours']       = max(12, min(168, (int)($_POST['lead_stale_flag_hours'] ?? 48)));
    $cfg['lead_stale_reassign_hours']   = max(24, min(336, (int)($_POST['lead_stale_reassign_hours'] ?? 72)));
    $store->save('kyc_config.json', $cfg);
    flash('✓ Lead auto-assign settings saved.', 'success');
    redirect('?page=dashboard&tab=all_leads');
}



// ── Smart Lead Distribution ────────────────────────────────────────────────
// Assigns up to N leads per agent using performance-weighted balancing.
// ONE WhatsApp summary per agent regardless of how many leads assigned.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='smart_distribute') {
    $admin = $auth->requireAdmin();

    $maxPerAgent  = max(1, min(50, (int)($_POST['max_per_agent'] ?? 10)));
    $agentIds     = array_filter(array_map('intval', $_POST['agent_ids'] ?? []));
    $strategy     = trim($_POST['strategy'] ?? 'round_robin'); // round_robin | performance | load_balance
    $onlyUnassigned = !empty($_POST['only_unassigned']);
    $filterService  = trim($_POST['filter_service'] ?? '');
    $note           = trim($_POST['distribute_note'] ?? '');

    if (empty($agentIds)) {
        flash('Select at least one agent.', 'danger');
        redirect('?page=dashboard&tab=all_leads');
    }

    $allLeads    = $store->load('leads.json') ?? [];
    $allRetailers = $store->load('retailers.json') ?? [];

    // Resolve selected agents
    $agents = array_values(array_filter($allRetailers, fn($r) =>
        in_array((int)($r['id']??0), $agentIds, true) &&
        ($r['is_active']??true) && empty($r['on_leave'])
    ));

    if (empty($agents)) {
        flash('No active agents selected.', 'danger');
        redirect('?page=dashboard&tab=all_leads');
    }

    // ── Pool: open leads eligible for distribution ─────────────────────
    $openStatuses = ['open','contacted','interested','quoted'];
    $pool = array_values(array_filter($allLeads, function($l) use ($openStatuses, $onlyUnassigned, $filterService) {
        if (!in_array($l['status']??'', $openStatuses)) return false;
        if ($onlyUnassigned && !empty($l['assigned_to'])) return false;
        if ($filterService && strtolower($l['service_type']??'') !== strtolower($filterService)) return false;
        return true;
    }));

    // Sort pool: high priority first, then by oldest created_at
    usort($pool, function($a, $b) {
        $priMap = ['high'=>0,'medium'=>1,'low'=>2];
        $pA = $priMap[$a['priority']??'medium'] ?? 1;
        $pB = $priMap[$b['priority']??'medium'] ?? 1;
        if ($pA !== $pB) return $pA - $pB;
        return strcmp($a['created_at']??'', $b['created_at']??'');
    });

    // ── Calculate per-agent current load ─────────────────────────────────
    $agentLoad = []; // agentId → current open lead count
    $agentWins = []; // agentId → lifetime won leads (performance score)
    foreach ($agents as $ag) {
        $aid = (int)$ag['id'];
        $agentLoad[$aid] = 0;
        $agentWins[$aid] = 0;
    }
    foreach ($allLeads as $l) {
        $aid = (int)($l['assigned_to'] ?? 0);
        if (!isset($agentLoad[$aid])) continue;
        if (in_array($l['status']??'', $openStatuses)) $agentLoad[$aid]++;
        if (($l['status']??'') === 'won') $agentWins[$aid]++;
    }

    // ── Distribution logic ────────────────────────────────────────────────
    // For each agent: how many more leads can we give them?
    $agentCapacity = [];
    foreach ($agents as $ag) {
        $aid = (int)$ag['id'];
        $canTake = max(0, $maxPerAgent - $agentLoad[$aid]);
        $agentCapacity[$aid] = $canTake;
    }

    // Build assignment map: agentId → [lead, lead, ...]
    $assignments = [];
    foreach ($agents as $ag) $assignments[(int)$ag['id']] = [];

    if ($strategy === 'performance') {
        // Best-performing agents get first pick of high-priority leads
        usort($agents, function($a, $b) use ($agentWins) {
            return ($agentWins[(int)$b['id']] ?? 0) - ($agentWins[(int)$a['id']] ?? 0);
        });
    } elseif ($strategy === 'load_balance') {
        // Agents with fewest current leads get filled first
        usort($agents, function($a, $b) use ($agentLoad) {
            return ($agentLoad[(int)$a['id']] ?? 0) - ($agentLoad[(int)$b['id']] ?? 0);
        });
    }
    // round_robin: use agents in the order they were selected

    $agentIndex = 0;
    $totalAgents = count($agents);
    $poolIdx = 0;

    while ($poolIdx < count($pool)) {
        $lead = $pool[$poolIdx];
        $assigned = false;

        // Try each agent in rotation until one has capacity
        for ($try = 0; $try < $totalAgents; $try++) {
            $ag  = $agents[($agentIndex + $try) % $totalAgents];
            $aid = (int)$ag['id'];
            if ($agentCapacity[$aid] > 0) {
                $assignments[$aid][] = $lead;
                $agentCapacity[$aid]--;
                $agentIndex = ($agentIndex + $try + 1) % $totalAgents;
                $assigned = true;
                break;
            }
        }

        if (!$assigned) break; // all agents at capacity
        $poolIdx++;
    }

    // ── Apply assignments to leads.json ──────────────────────────────────
    $totalAssigned = 0;
    $distributedAt = date('Y-m-d H:i:s');

    // Build quick lookup: leadId → agentId
    $leadToAgent = [];
    foreach ($assignments as $aid => $assignedLeads) {
        foreach ($assignedLeads as $l) {
            $leadToAgent[(int)$l['id']] = $aid;
        }
        $totalAssigned += count($assignedLeads);
    }

    // Build agent lookup
    $agentById = [];
    foreach ($agents as $ag) $agentById[(int)$ag['id']] = $ag;

    foreach ($allLeads as &$l) {
        $lid = (int)($l['id']??0);
        if (!isset($leadToAgent[$lid])) continue;
        $aid = $leadToAgent[$lid];
        $ag  = $agentById[$aid];
        $l['assigned_to']   = $aid;
        $l['assigned_name'] = $ag['name'];
        $l['assigned_at']   = $distributedAt;
        $l['assigned_by']   = $admin['name'] . ' (smart-distribute)';
        $l['dist_strategy'] = $strategy;
        if (!isset($l['history'])) $l['history'] = [];
        $l['history'][] = [
            'status' => $l['status'] ?? 'open',
            'by'     => $admin['name'],
            'at'     => $distributedAt,
            'note'   => "Smart distributed ({$strategy})" . ($note ? ": {$note}" : ''),
        ];
    }
    unset($l);
    $store->save('leads.json', $allLeads);

    // ── ONE WhatsApp summary per agent (not one per lead) ─────────────────
    foreach ($agents as $ag) {
        $aid = (int)$ag['id'];
        $agentLeads = $assignments[$aid];
        if (empty($agentLeads)) continue;
        try {
            $notify->leadBatchAssigned($ag, $agentLeads, $note);
        } catch (\Throwable $e) {}
    }

    // ── Save distribution run to history ─────────────────────────────────
    $distLog = $store->load('lead_distribution_log.json') ?? [];
    $distLog[] = [
        'id'          => count($distLog) + 1,
        'run_at'      => $distributedAt,
        'by'          => $admin['name'],
        'strategy'    => $strategy,
        'total'       => $totalAssigned,
        'note'        => $note,
        'assignments' => array_map(fn($ag) => [
            'agent'  => $ag['name'],
            'count'  => count($assignments[(int)$ag['id']]),
        ], $agents),
    ];
    if (count($distLog) > 100) $distLog = array_slice($distLog, -100);
    $store->save('lead_distribution_log.json', $distLog);

    flash("✅ {$totalAssigned} leads distributed across " . count(array_filter($assignments, fn($a)=>!empty($a))) . " agents. 1 WhatsApp summary sent per agent.", 'success');
    redirect('?page=dashboard&tab=all_leads');
}

// ── SQLite Maintenance Actions ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sqlite_export_json') {
    $auth->requireAdmin();
    if (!($store instanceof SqliteStore)) {
        flash('SQLite not active — nothing to export.', 'warning');
    } else {
        $exportDir = $dataDir . '/_json_export_' . date('Ymd_His');
        @mkdir($exportDir, 0755, true);
        $exported  = $store->exportAllToJson($exportDir);
        $summary   = implode(', ', array_map(fn($f,$c)=>"{$f}:{$c}", array_keys($exported), $exported));
        flash("✅ Exported " . count($exported) . " tables to " . basename($exportDir) . ". ({$summary})", 'success');
    }
    redirect('?page=dashboard&tab=maintenance');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sqlite_vacuum') {
    $auth->requireAdmin();
    if (!($store instanceof SqliteStore)) {
        flash('SQLite not active — nothing to vacuum.', 'warning');
    } else {
        $before = file_exists($dataDir.'/plugin.sqlite3') ? filesize($dataDir.'/plugin.sqlite3') : 0;
        $store->query('VACUUM');
        $after  = file_exists($dataDir.'/plugin.sqlite3') ? filesize($dataDir.'/plugin.sqlite3') : 0;
        $saved  = max(0, $before - $after);
        $human  = $saved > 1048576 ? round($saved/1048576,1).'MB' : round($saved/1024,1).'KB';
        flash("✅ VACUUM complete. Recovered {$human} of disk space.", 'success');
    }
    redirect('?page=dashboard&tab=maintenance');
}

// ── CSV Export Handler ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='export_csv') {
    $auth->requireAccountant();
    $exportType = $_POST['export_type'] ?? '';
    $expDate    = $_POST['exp_date']    ?? date('Y-m-d');
    $expMode    = $_POST['exp_mode']    ?? 'day';
    $expRid     = (int)($_POST['exp_rid'] ?? 0);
    $expMonth   = $_POST['exp_month']   ?? date('Y-m');
    $prefix     = $expMode === 'month' ? substr($expDate,0,7) : $expDate;

    $rows   = [];
    $fname  = 'dishnet-export-' . date('Ymd-His') . '.csv';

    if ($exportType === 'collections') {
        $allC = $store->load('payment_collections.json');
        $rows[] = ['Date','Time','Customer','Agent','Method','Amount','Commission','Net','CRM Synced','Invoice'];
        foreach (array_reverse($allC) as $c) {
            if (!str_starts_with($c['created_at']??'', $prefix)) continue;
            $rows[] = [
                substr($c['created_at']??'',0,10),
                substr($c['created_at']??'',11,5),
                $c['customer_name']??'',
                $c['retailer_name']??'',
                $c['method']??'Cash',
                number_format((float)($c['amount']??0),2,'.',''),
                number_format((float)($c['commission']??0),2,'.',''),
                number_format((float)(($c['amount']??0)-($c['commission']??0)),2,'.',''),
                ($c['crm_synced']??false)?'Yes':'No',
                $c['invoice_id']??'',
            ];
        }
        $fname = 'collections-'.$prefix.'.csv';
    } elseif ($exportType === 'ledger') {
        $rPass = $expRid ? $wallet->getPassbook($expRid, 9999) : [];
        $rPass = array_filter($rPass, fn($p)=>str_starts_with($p['created_at']??'',$expMonth));
        $rows[] = ['Date','Time','Trx No','Description','Credit','Debit','Balance'];
        foreach ($rPass as $pe) {
            $isCredit = ($pe['type']??'debit')==='credit';
            $rows[] = [
                substr($pe['created_at']??'',0,10),
                substr($pe['created_at']??'',11,5),
                $pe['trx_no']??'',
                $pe['description']??'',
                $isCredit ? number_format((float)($pe['amount']??0),2,'.','') : '',
                $isCredit ? '' : number_format((float)($pe['amount']??0),2,'.',''),
                number_format((float)($pe['curr_balance']??0),2,'.',''),
            ];
        }
        $allRet4 = $store->load('retailers.json');
        $rName = '';
        foreach ($allRet4 as $r4) { if ((int)($r4['id']??0)===$expRid) { $rName = $r4['name']??'agent'; break; } }
        $fname = 'ledger-'.preg_replace('/[^a-z0-9]/i','-',strtolower($rName)).'-'.$expMonth.'.csv';
    } elseif ($exportType === 'settlement') {
        $allC2 = $store->load('payment_collections.json');
        $pCols = array_filter($allC2, fn($c)=>str_starts_with($c['created_at']??'',$prefix));
        $rows[] = ['Date','Agent','Customer','Method','Amount','Commission','Net'];
        foreach (array_reverse(array_values($pCols)) as $c2) {
            $rows[] = [
                substr($c2['created_at']??'',0,10),
                $c2['retailer_name']??'',
                $c2['customer_name']??'',
                $c2['method']??'Cash',
                number_format((float)($c2['amount']??0),2,'.',''),
                number_format((float)($c2['commission']??0),2,'.',''),
                number_format((float)(($c2['amount']??0)-($c2['commission']??0)),2,'.',''),
            ];
        }
        $fname = 'settlement-'.$prefix.'.csv';
    } elseif ($exportType === 'kyc_hardware') {
        $allA = $store->load('kyc_applications.json');
        $rows[] = ['Date','Agent','Customer','Service','Plan','Hardware Items','HW Total','Plan Price','Today Total','Status'];
        foreach (array_reverse($allA) as $a) {
            if ($expMode !== 'all' && !str_starts_with($a['created_at']??'', $prefix)) continue;
            $hwCart  = json_decode($a['hw_cart_json']??'[]', true) ?: [];
            $hwTotal = array_sum(array_map(fn($h)=>(float)($h['price']??0)*(int)($h['qty']??1), $hwCart));
            $hwItems = implode('; ', array_map(fn($h)=>($h['title']??'?').'×'.($h['qty']??1), $hwCart));
            $planP   = (float)($a['selected_plan_price']??0);
            $rows[] = [
                substr($a['created_at']??'',0,10),
                $a['retailer_name']??$a['sales_person']??'',
                trim(($a['firstname']??'').' '.($a['lastname']??'')),
                $a['customer_type']??'',
                $a['selected_plan_name']??'',
                $hwItems,
                number_format($hwTotal,2,'.',''),
                number_format($planP,2,'.',''),
                number_format($hwTotal+$planP,2,'.',''),
                $a['status']??'',
            ];
        }
        $fname = 'kyc-hardware-'.$prefix.'.csv';
    }

    if (!empty($rows)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$fname.'"');
        header('Pragma: no-cache'); header('Expires: 0');
        $out = fopen('php://output','w');
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
    flash('No data to export for this period.','warning');
    redirect('?page=dashboard&tab=accounts_dashboard');
}
