<?php
// ═══════════════════════════════════════════════════════════════
// LTE MODULE
// ═══════════════════════════════════════════════════════════════


    // ══════════════════════════════════════════════════════════════════════
    // UNIFIED DASHBOARD STATS
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'unified_stats') {
        if (!$isAdmin && !$can('lte_dashboard') && !$can('accounts_dash')) $er2('Access denied.',403);

        // ── LTE stats ────────────────────────────────────────────────
        $lteS       = $lte->getDashboardStats();
        $lteRenewals= $store->load('lte_renewals.json');
        $thisMonth  = date('Y-m');
        $lteMthRev  = array_sum(array_map(fn($r)=>(float)($r['amount_paid']??0),
            array_filter($lteRenewals, fn($r)=>str_starts_with($r['created_at']??'',$thisMonth))));

        // ── UCRM stats (from cache) ───────────────────────────────────
        $ucrmClients  = count($store->load('ucrm_clients_cache.json')  ?? []);
        $ucrmServices = $store->load('ucrm_services_cache.json') ?? [];
        $ucrmActive   = count(array_filter($ucrmServices, fn($s)=>($s['status']??0)===1));
        $ucrmSuspended= count(array_filter($ucrmServices, fn($s)=>($s['status']??0)===2));
        $ucrmInvoices = $store->load('ucrm_invoices_cache.json') ?? [];
        $ucrmOverdue  = count(array_filter($ucrmInvoices, fn($i)=>($i['status']??0)===2));
        $ucrmMthRev   = array_sum(array_map(fn($i)=>(float)($i['total']??0),
            array_filter($ucrmInvoices, fn($i)=>str_starts_with($i['createdDate']??'',$thisMonth)&&($i['status']??0)!==3)));

        // ── Collections (all agents this month) ──────────────────────
        $allColls = $store->load('payment_collections.json') ?? [];
        $mthColls = array_filter($allColls, fn($c)=>str_starts_with($c['created_at']??'',$thisMonth));
        $collsAmt = array_sum(array_map(fn($c)=>(float)($c['amount']??0), $mthColls));

        // ── Applications pipeline ─────────────────────────────────────
        $apps       = $store->load('kyc_applications.json') ?? [];
        $appsPending= count(array_filter($apps, fn($a)=>($a['status']??'')==='pending'));
        $appsMth    = count(array_filter($apps, fn($a)=>str_starts_with($a['created_at']??'',$thisMonth)));

        // ── Leads ─────────────────────────────────────────────────────
        $leads      = $store->load('leads.json') ?? [];
        $leadsOpen  = count(array_filter($leads, fn($l)=>!in_array($l['status']??'',['converted','lost'])));

        // ── Tickets ───────────────────────────────────────────────────
        $tickets    = $store->load('support_tickets.json') ?? [];
        $ticketsOpen= count(array_filter($tickets, fn($t)=>($t['status']??'')==='open'));

        // ── LTE expiry alerts ─────────────────────────────────────────
        $lteQueue   = $lte->getRenewalQueue();
        $expiredCnt = count(array_filter($lteQueue, fn($q)=>($q['_expiry_status']??'')==='expired'));
        $urgentCnt  = count(array_filter($lteQueue, fn($q)=>($q['_expiry_status']??'')==='critical'));

        $ok2([
            'month' => $thisMonth,
            'lte'   => [
                'total'       => $lteS['total']??0,
                'active'      => $lteS['active']??0,
                'suspended'   => $lteS['suspended']??0,
                'expired'     => $lteS['expired']??0,
                'expiring'    => $lteS['expiring_soon']??0,
                'urgent'      => $urgentCnt,
                'mth_revenue' => $lteMthRev,
                'renewals_today'=> $lteS['renewals_today']??0,
            ],
            'ucrm'  => [
                'clients'     => $ucrmClients,
                'active'      => $ucrmActive,
                'suspended'   => $ucrmSuspended,
                'overdue_inv' => $ucrmOverdue,
                'mth_revenue' => $ucrmMthRev,
            ],
            'ops'   => [
                'collections_mth' => $collsAmt,
                'apps_pending'    => $appsPending,
                'apps_mth'        => $appsMth,
                'leads_open'      => $leadsOpen,
                'tickets_open'    => $ticketsOpen,
            ],
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // UNIFIED AGENT COMMISSIONS — Starlink + Fiber + LTE
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'lte_commission_summary') {
        if (!$isAdmin && !$can('commissions')) $er2('Access denied.',403);
        $from  = $_GET['from'] ?? date('Y-m-01');
        $to    = $_GET['to']   ?? date('Y-m-t');
        $agentFilter = (int)($_GET['agent_id'] ?? 0);

        $rates = [
            'starlink' => (float)($config['starlink_commission_rate'] ?? $config['commission_rate'] ?? 5),
            'fiber'    => (float)($config['fiber_commission_rate']    ?? $config['commission_rate'] ?? 5),
            'lte'      => (float)($config['lte_commission_rate']      ?? $config['commission_rate'] ?? 5),
        ];

        // Helper: blank agent bucket
        $blank = fn($id,$name) => [
            'agent_id'   => $id, 'agent_name' => $name,
            'starlink'   => ['collections'=>0,'revenue'=>0.0,'commission'=>0.0,'rate'=>$rates['starlink']],
            'fiber'      => ['collections'=>0,'revenue'=>0.0,'commission'=>0.0,'rate'=>$rates['fiber']],
            'lte'        => ['renewals'=>0,   'revenue'=>0.0,'commission'=>0.0,'rate'=>$rates['lte']],
            'total'      => ['transactions'=>0,'revenue'=>0.0,'commission'=>0.0,'net_to_dishnet'=>0.0],
        ];
        $byAgent = [];

        // ── 1. Starlink/Fiber collections ─────────────────────────────
        $collections = $store->load('payment_collections.json') ?? [];
        foreach ($collections as $c) {
            $d = substr($c['created_at']??'',0,10);
            if ($d < $from || $d > $to) continue;
            $aid  = (int)($c['retailer_id']??0);
            $aname= $c['retailer_name']??'Agent #'.$aid;
            if ($agentFilter && $aid !== $agentFilter) continue;
            if (!isset($byAgent[$aid])) $byAgent[$aid] = $blank($aid,$aname);

            // Determine service type — use stored field, else infer from note/description
            $svc = strtolower($c['service_type'] ?? '');
            if (!in_array($svc,['starlink','fiber'])) {
                $desc = strtolower(($c['note']??'').($c['customer_name']??''));
                $svc  = str_contains($desc,'fiber') ? 'fiber' : 'starlink';
            }
            $amt  = (float)($c['amount']??0);
            // Commission: use stored commission if present, else recalc
            $comm = (float)($c['commission']??0) ?: round($amt * $rates[$svc] / 100, 2);
            $byAgent[$aid][$svc]['collections']++;
            $byAgent[$aid][$svc]['revenue']    += $amt;
            $byAgent[$aid][$svc]['commission'] += $comm;
        }

        // ── 2. LTE renewals ───────────────────────────────────────────
        $renewals = $store->load('lte_renewals.json') ?? [];
        foreach ($renewals as $r) {
            $d = substr($r['created_at']??'',0,10);
            if ($d < $from || $d > $to) continue;
            $aid  = (int)($r['agent_id']??0);
            $aname= $r['agent_name']??'Agent #'.$aid;
            if ($agentFilter && $aid !== $agentFilter) continue;
            if (!isset($byAgent[$aid])) $byAgent[$aid] = $blank($aid,$aname);
            $amt  = (float)($r['amount_paid']??0);
            $comm = round($amt * $rates['lte'] / 100, 2);
            $byAgent[$aid]['lte']['renewals']++;
            $byAgent[$aid]['lte']['revenue']    += $amt;
            $byAgent[$aid]['lte']['commission'] += $comm;
        }

        // ── 3. Compute totals per agent ───────────────────────────────
        foreach ($byAgent as &$ag) {
            $totalRev  = $ag['starlink']['revenue'] + $ag['fiber']['revenue'] + $ag['lte']['revenue'];
            $totalComm = $ag['starlink']['commission'] + $ag['fiber']['commission'] + $ag['lte']['commission'];
            $totalTxn  = $ag['starlink']['collections'] + $ag['fiber']['collections'] + $ag['lte']['renewals'];
            $ag['total'] = [
                'transactions'  => $totalTxn,
                'revenue'       => round($totalRev, 2),
                'commission'    => round($totalComm, 2),
                'net_to_dishnet'=> round($totalRev - $totalComm, 2),
            ];
            // Round sub-totals
            foreach (['starlink','fiber','lte'] as $svc) {
                $ag[$svc]['revenue']    = round($ag[$svc]['revenue'], 2);
                $ag[$svc]['commission'] = round($ag[$svc]['commission'], 2);
            }
        }
        unset($ag);

        usort($byAgent, fn($a,$b) => $b['total']['revenue'] <=> $a['total']['revenue']);

        $grandRev   = array_sum(array_map(fn($a)=>$a['total']['revenue'],   $byAgent));
        $grandComm  = array_sum(array_map(fn($a)=>$a['total']['commission'],$byAgent));
        $grandTxn   = array_sum(array_map(fn($a)=>$a['total']['transactions'],$byAgent));

        $ok2([
            'from'=>$from,'to'=>$to,'rates'=>$rates,
            'agents'  => array_values($byAgent),
            'totals'  => ['transactions'=>$grandTxn,'revenue'=>round($grandRev,2),
                          'commission'=>round($grandComm,2),'net_to_dishnet'=>round($grandRev-$grandComm,2)],
        ]);
    }

    if ($act === 'lte_commission_detail') {
        if (!$isAdmin && !$can('commissions')) $er2('Access denied.',403);
        $aid  = (int)($_GET['agent_id']??0);
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-t');
        if (!$aid) $er2('agent_id required.',422);
        $rates = [
            'starlink' => (float)($config['starlink_commission_rate'] ?? $config['commission_rate'] ?? 5),
            'fiber'    => (float)($config['fiber_commission_rate']    ?? $config['commission_rate'] ?? 5),
            'lte'      => (float)($config['lte_commission_rate']      ?? $config['commission_rate'] ?? 5),
        ];
        $rows = [];
        // Collections
        foreach ($store->load('payment_collections.json') as $c) {
            $d = substr($c['created_at']??'',0,10);
            if ((int)($c['retailer_id']??0)!==$aid||$d<$from||$d>$to) continue;
            $svc  = strtolower($c['service_type']??'');
            if (!in_array($svc,['starlink','fiber'])) $svc = 'starlink';
            $amt  = (float)($c['amount']??0);
            $comm = (float)($c['commission']??0) ?: round($amt*$rates[$svc]/100,2);
            $rows[] = ['date'=>$d,'type'=>$svc,'description'=>'Collection: '.($c['customer_name']??''),'amount'=>$amt,'commission'=>$comm,'net'=>round($amt-$comm,2),'method'=>$c['method']??'Cash','ref'=>'COL-'.($c['id']??'')];
        }
        // LTE renewals
        foreach ($store->load('lte_renewals.json') as $r) {
            $d = substr($r['created_at']??'',0,10);
            if ((int)($r['agent_id']??0)!==$aid||$d<$from||$d>$to) continue;
            $amt  = (float)($r['amount_paid']??0);
            $comm = round($amt*$rates['lte']/100,2);
            $rows[] = ['date'=>$d,'type'=>'lte','description'=>'LTE Renewal: '.($r['subscriber_name']??'').' · '.($r['package_name']??''),'amount'=>$amt,'commission'=>$comm,'net'=>round($amt-$comm,2),'method'=>$r['payment_method']??'cash','ref'=>'LTE-'.($r['id']??'')];
        }
        usort($rows,fn($a,$b)=>strcmp($b['date'],$a['date']));
        $ok2(['agent_id'=>$aid,'from'=>$from,'to'=>$to,'rates'=>$rates,'rows'=>$rows,
              'totals'=>['transactions'=>count($rows),'revenue'=>round(array_sum(array_column($rows,'amount')),2),
                         'commission'=>round(array_sum(array_column($rows,'commission')),2),
                         'net_to_dishnet'=>round(array_sum(array_column($rows,'net')),2)]]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // WHATSAPP RENEWAL REMINDERS
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'lte_send_reminder' && $met === 'POST') {
        if (!$isAdmin && !$can('lte_renewal')) $er2('Access denied.',403);
        $body = json_decode(file_get_contents('php://input'),true)??[];
        $subId= (int)($body['subscriber_id']??0);
        if (!$subId) $er2('subscriber_id required.',422);
        $sub  = $lte->getSubscriber($subId);
        if (!$sub) $er2('Subscriber not found.',404);
        $phone= $sub['phone']??'';
        if (empty($phone)) $er2('No phone number on record.',422);

        // Get active subscription
        $allSubs = array_filter($store->load('lte_subscriptions.json'),
            fn($s)=>(int)($s['subscriber_id']??0)===$subId&&($s['status']??'')==='active');
        $activeSub = $allSubs ? reset($allSubs) : null;
        $expiresAt = $activeSub['expires_at'] ?? '';
        $pkgName   = $activeSub['package_name'] ?? '';
        $daysLeft  = $expiresAt ? (int)floor((strtotime($expiresAt)-time())/86400) : null;

        $msgType   = $body['type'] ?? 'reminder'; // reminder | expired | renewed | suspended

        $msgs = [
            'reminder'  => "Hello {$sub['name']}! 👋\n\nYour DishNet LTE plan *{$pkgName}* expires in *{$daysLeft} day(s)* on *{$expiresAt}*.\n\nRenew now to avoid interruption.\n📞 Call your agent or visit a DishNet office.\n\n_DishNet Africa_",
            'expired'   => "Hello {$sub['name']}! ⚠️\n\nYour DishNet LTE plan *{$pkgName}* has *expired*.\n\nYour service is now suspended. Please renew immediately.\n📞 Contact your agent to restore service.\n\n_DishNet Africa_",
            'suspended' => "Hello {$sub['name']}! 🚫\n\nYour DishNet LTE service has been *suspended* due to non-renewal.\n\nPlease contact us to restore your connection.\n📞 DishNet Support\n\n_DishNet Africa_",
            'renewed'   => "Hello {$sub['name']}! ✅\n\nYour DishNet LTE plan *{$pkgName}* has been renewed.\n\n📅 Valid until: *{$expiresAt}*\n\nThank you for choosing DishNet Africa! 🙏\n\n_DishNet Africa_",
        ];
        $message = $msgs[$msgType] ?? $msgs['reminder'];

        $notify->sendRaw($phone, $message, 'lte_'.$msgType);

        // Log reminder in renewal history
        $log = $store->load('lte_reminder_log.json') ?? [];
        $log[] = [
            'subscriber_id' => $subId,
            'name'          => $sub['name'],
            'phone'         => $phone,
            'type'          => $msgType,
            'plan'          => $pkgName,
            'expires'       => $expiresAt,
            'sent_by'       => $retailer['name'],
            'sent_at'       => date('Y-m-d H:i:s'),
        ];
        $store->save('lte_reminder_log.json', $log);

        $ok2(['sent'=>true,'to'=>$phone,'type'=>$msgType]);
    }

    // Bulk reminders — send to all expiring within N days
    if ($act === 'lte_bulk_remind' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.',403);
        $body    = json_decode(file_get_contents('php://input'),true)??[];
        $days    = (int)($body['days']??3);
        $msgType = $body['type']??'reminder';
        $queue   = $lte->getRenewalQueue();
        $sent=0; $skipped=0;
        foreach ($queue as $sub) {
            $dLeft = $sub['_days_remaining']??null;
            $status= $sub['_expiry_status']??'';
            // Only send to: expiring within $days, or expired (if type=expired)
            if ($msgType==='expired'  && $status!=='expired') { $skipped++; continue; }
            if ($msgType==='reminder' && ($dLeft===null||$dLeft>$days||$dLeft<0)) { $skipped++; continue; }
            if (empty($sub['phone'])) { $skipped++; continue; }

            $body2 = ['subscriber_id'=>$sub['id'],'type'=>$msgType];
            // Re-use single send logic by calling the service directly
            $phone  = $sub['phone'];
            $pkgN   = $sub['_subscription']['package_name']??'';
            $expAt  = $sub['expires_at']??'';
            $msgs2  = [
                'reminder' => "Hello {$sub['name']}! 👋\n\nYour DishNet LTE plan *{$pkgN}* expires in *{$dLeft} day(s)* on *{$expAt}*.\n\nRenew now to avoid interruption.\n📞 Contact your agent.\n\n_DishNet Africa_",
                'expired'  => "Hello {$sub['name']}! ⚠️\n\nYour DishNet LTE plan *{$pkgN}* has *expired*.\n\nPlease renew immediately.\n📞 DishNet Support\n\n_DishNet Africa_",
            ];
            $notify->sendRaw($phone, $msgs2[$msgType]??$msgs2['reminder'], 'lte_'.$msgType);
            $log2  = $store->load('lte_reminder_log.json')??[];
            $log2[]= ['subscriber_id'=>$sub['id'],'name'=>$sub['name'],'phone'=>$phone,'type'=>$msgType,'plan'=>$pkgN,'expires'=>$expAt,'sent_by'=>'bulk/'.$retailer['name'],'sent_at'=>date('Y-m-d H:i:s')];
            $store->save('lte_reminder_log.json',$log2);
            $sent++;
        }
        $ok2(['sent'=>$sent,'skipped'=>$skipped]);
    }

    // Reminder log
    if ($act === 'lte_reminder_log') {
        if (!$isAdmin && !$can('lte_renewal')) $er2('Access denied.',403);
        $log  = $store->load('lte_reminder_log.json')??[];
        usort($log, fn($a,$b)=>strcmp($b['sent_at']??'',$a['sent_at']??''));
        $ok2(array_slice($log,0,200));
    }

    // ══════════════════════════════════════════════════════════════════════
    // LTE STATEMENT / RECEIPT (JSON — rendered to PDF in frontend)
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'lte_statement') {
        if (!$can('lte_subscribers')&&!$isAdmin) $er2('Access denied.',403);
        $subId = (int)($_GET['id']??0);
        if (!$subId) $er2('id required.',422);
        $sub   = $lte->getSubscriber($subId);
        if (!$sub) $er2('Not found.',404);
        $renewals = array_filter($store->load('lte_renewals.json'),fn($r)=>(int)($r['subscriber_id']??0)===$subId);
        usort($renewals, fn($a,$b)=>strcmp($b['created_at']??'',$a['created_at']??''));
        $totalPaid = array_sum(array_map(fn($r)=>(float)($r['amount_paid']??0),$renewals));
        $ok2([
            'subscriber' => $sub,
            'renewals'   => array_values($renewals),
            'total_paid' => $totalPaid,
            'company'    => ['name'=>'DishNet Africa Limited','address'=>'Juba, South Sudan','phone'=>$config['whatsapp_admin_phone']??''],
            'generated'  => date('Y-m-d H:i:s'),
        ]);
    }




    // ══════════════════════════════════════════════════════════════════════
    // AUTO-SUSPEND — manual trigger + status
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'run_wallet_sync' && $met === 'POST') {
        $auth->requireAdmin();
        $cronPath = $config['wallet_sync_cron_path'] ?? (__DIR__.'/cron_wallet_sync.php');
        if (!file_exists($cronPath)) { $er2("cron_wallet_sync.php not found at: {$cronPath}", 404); }
        $output = []; $code = 0;
        exec('php ' . escapeshellarg($cronPath) . ' 2>&1', $output, $code);
        $ok2(['success' => $code === 0, 'output' => implode("
", $output), 'exit_code' => $code]);
    }

    if ($act === 'run_payment_reconcile' && $met === 'POST') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);

        // Always run inline — exec() is unreliable inside UCRM web context
        // (empty output, permission issues, path problems). Inline is faster
        // and gives full error visibility via ob_start().

        // ── Diagnostics header ────────────────────────────────────────
        $diag = [];
        $diag[] = '=== DIAGNOSTIC INFO ===';
        $diag[] = 'PHP version : ' . PHP_VERSION;
        $diag[] = 'SAPI        : ' . php_sapi_name();
        $diag[] = '__DIR__     : ' . __DIR__;
        $diag[] = 'dataDir     : ' . $dataDir;

        // Resolve cron path (used only for file_exists check)
        $cronPath = realpath(dirname(__DIR__, 2) . '/cron_wallet_sync.php') ?: (dirname(__DIR__, 2) . '/cron_wallet_sync.php');
        $diag[] = 'cronPath    : ' . $cronPath;
        $diag[] = 'cron exists : ' . (file_exists($cronPath) ? 'YES' : 'NO');
        $diag[] = '=== END DIAGNOSTIC ===';

        if (!file_exists($cronPath)) {
            $ok2(['success' => false, 'exit_code' => -1,
                  'output' => implode("\n", $diag) . "\n\nERROR: cron_wallet_sync.php not found at: {$cronPath}",
                  'reconcile_lines' => ['❌ cron_wallet_sync.php not found — check path above'],
                  'report' => null]);
            return;
        }

        // ── Always run inline (exec unreliable in UCRM web context) ──
        $lastRunFile = $dataDir . '/payment_reconcile_last_run.txt';
        if (file_exists($lastRunFile)) unlink($lastRunFile);

        $log = [];  // collect output lines directly — no ob_start (conflicts with API buffer)
        try {
            require_once dirname(__DIR__, 2) . '/lib/FieldAgentService.php';
            $lookbackDays = (int)($config['payment_reconcile_lookback_days'] ?? 7);
            $since        = date('Y-m-d', strtotime("-{$lookbackDays} days")) . 'T00:00:00+03:00';
            $cashMethodId = trim($config['cash_payment_method_id'] ?? '');
            $params       = http_build_query(['createdDateFrom' => $since, 'limit' => 500]);

            $log[] = "Fetching UCRM payments since {$since}...";
            $payments = $crm->get("payments?{$params}");

            if (!is_array($payments)) {
                $log[] = "ERROR: Could not fetch payments — " . json_encode($crm->getLastError());
            } else {
                $log[] = "Fetched " . count($payments) . " UCRM payments (last {$lookbackDays} days).";

                $allCols     = $store->load(FieldAgentService::COLLECTIONS_FILE);
                $knownCrmIds = [];
                $clientAgent = [];
                foreach ($allCols as $c) {
                    $pid = (int)($c['crm_payment_id'] ?? 0);
                    if ($pid > 0) $knownCrmIds[$pid] = true;
                    $cid = (int)($c['crm_client_id'] ?? 0);
                    $aid = (int)($c['agent_id'] ?? 0);
                    if ($cid > 0 && $aid > 0) {
                        $clientAgent[$cid] = ['agent_id' => $aid, 'agent_name' => $c['agent_name'] ?? ''];
                    }
                }
                $log[] = "Already-known CRM payment IDs: " . count($knownCrmIds);

                $ins = 0; $skp = 0; $una = 0;
                foreach ($payments as $pmt) {
                    $pid = (int)($pmt['id'] ?? 0);
                    if (!$pid) continue;
                    if (isset($knownCrmIds[$pid])) { $skp++; continue; }
                    $mid = $pmt['methodId'] ?? $pmt['paymentMethodId'] ?? '';
                    if ($cashMethodId !== '' && $mid !== $cashMethodId) { $skp++; continue; }
                    $amt = round((float)($pmt['amount'] ?? 0), 2);
                    if ($amt <= 0) { $skp++; continue; }
                    $cid       = (int)($pmt['clientId'] ?? 0);
                    $note      = trim($pmt['note'] ?? $pmt['notes'] ?? '');
                    $createdAt = str_replace('T', ' ', substr($pmt['createdDate'] ?? date('Y-m-d H:i:s'), 0, 19));
                    $agentId   = 0;
                    $agentName = 'Unassigned (CRM Direct)';
                    if ($cid > 0 && isset($clientAgent[$cid])) {
                        $agentId   = $clientAgent[$cid]['agent_id'];
                        $agentName = $clientAgent[$cid]['agent_name'];
                    }
                    $custName  = "CRM Client #{$cid}";
                    $custPhone = '';
                    if ($cid > 0) {
                        $cl = $crm->get("clients/{$cid}");
                        if ($cl) {
                            $custName = trim(($cl['firstName'] ?? '') . ' ' . ($cl['lastName'] ?? ''));
                            foreach ($cl['contacts'] ?? [] as $ct) {
                                if (!empty($ct['phone'])) { $custPhone = $ct['phone']; break; }
                            }
                        }
                    }
                    $rec = $store->appendWithId(FieldAgentService::COLLECTIONS_FILE, [
                        'agent_id'        => $agentId,
                        'agent_name'      => $agentName,
                        'amount'          => $amt,
                        'collection_type' => 'subscription',
                        'customer_name'   => $custName,
                        'customer_phone'  => $custPhone,
                        'reference'       => "UCRM-PMT-{$pid}",
                        'note'            => $note ?: "Auto-reconciled from UCRM payment #{$pid}",
                        'collected_at'    => $createdAt,
                        'created_at'      => date('Y-m-d H:i:s'),
                        'source'          => 'crm_direct',
                        'crm_payment_id'  => $pid,
                        'crm_client_id'   => $cid,
                        'crm_method_id'   => $mid,
                        'reconciled_at'   => date('Y-m-d H:i:s'),
                        'needs_review'    => true,
                    ]);
                    $knownCrmIds[$pid] = true;
                    if ($agentId > 0) {
                        $clientAgent[$cid] = ['agent_id' => $agentId, 'agent_name' => $agentName];
                    } else {
                        $una++;
                    }
                    $flag  = $agentId === 0 ? ' ⚠ NEEDS REVIEW' : '';
                    $log[] = "✅ UCRM PMT #{$pid} → Collection #{$rec['id']} | \${$amt} | {$custName} | → {$agentName}{$flag}";
                    $ins++;
                }
                $log[] = "Payment reconcile: inserted={$ins}, skipped={$skp}, unassigned={$una}";
                if ($una > 0) {
                    $log[] = "⚠ {$una} payment(s) unattributed — review in Field Agent tab (filter: source=crm_direct)";
                }
                file_put_contents($dataDir . '/payment_reconcile_last_report.json', json_encode([
                    'ran_at'        => date('Y-m-d H:i:s'),
                    'lookback_days' => $lookbackDays,
                    'crm_fetched'   => count($payments),
                    'inserted'      => $ins,
                    'skipped'       => $skp,
                    'unassigned'    => $una,
                ], JSON_PRETTY_PRINT));
                file_put_contents($dataDir . '/payment_reconcile_last_run.txt', time());
            }
        } catch (\Throwable $e) {
            $log[] = "EXCEPTION: " . $e->getMessage();
            $log[] = $e->getTraceAsString();
        }

        $allOutput      = implode("\n", $diag) . "\n\n" . implode("\n", $log);
        $hasError       = !empty(array_filter($log, fn($l) => str_contains($l, 'EXCEPTION') || str_contains($l, 'ERROR:')));
        $reconcileLines = array_values(array_filter($log, fn($l) =>
            str_contains($l, 'UCRM PMT')         ||
            str_contains($l, 'Payment reconcile') ||
            str_contains($l, 'NEEDS REVIEW')      ||
            str_contains($l, 'inserted=')         ||
            str_contains($l, 'Fetched')           ||
            str_contains($l, 'EXCEPTION')         ||
            str_contains($l, 'ERROR')
        ));
        $reportFile = $dataDir . '/payment_reconcile_last_report.json';
        $report     = file_exists($reportFile) ? json_decode(file_get_contents($reportFile), true) : null;
        $ok2([
            'success'         => !$hasError,
            'exit_code'       => 0,
            'output'          => $allOutput,
            'reconcile_lines' => $reconcileLines,
            'report'          => $report,
        ]);
        return;
    }

    if ($act === 'lte_run_cron' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $cronPath = $config['lte_cron_path'] ?? (__DIR__.'/cron_lte.php');
        if (!file_exists($cronPath)) $er2('cron_lte.php not found at: '.$cronPath, 404);
        $output = []; $code = 0;
        exec('php ' . escapeshellarg($cronPath) . ' 2>&1', $output, $code);
        $ok2(['exit_code'=>$code, 'output'=>implode("\n", $output)]);
    }

    if ($act === 'lte_auto_suspend_log') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $log  = $store->load('lte_auto_suspend_log.json')    ?? [];
        $reac = $store->load('lte_auto_reactivate_log.json') ?? [];
        usort($log,  fn($a,$b)=>strcmp($b['suspended_at']??'',$a['suspended_at']??'')); 
        usort($reac, fn($a,$b)=>strcmp($b['reactivated_at']??'',$a['reactivated_at']??'')); 
        $ok2(['suspended'=>array_slice($log,0,100),'reactivated'=>array_slice($reac,0,100)]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DAILY OPS REPORTS
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'lte_daily_reports') {
        if (!$isAdmin && !$can('accounts_dash')) $er2('Access denied.', 403);
        $reports = $store->load('lte_daily_reports.json') ?? [];
        usort($reports, fn($a,$b)=>strcmp($b['date']??'',$a['date']??'')); 
        $ok2(array_slice($reports, 0, (int)($_GET['limit'] ?? 30)));
    }

    if ($act === 'lte_report_today' && $met === 'POST') {
        if (!$isAdmin && !$can('accounts_dash')) $er2('Access denied.', 403);
        $today = date('Y-m-d');
        $pdo2  = $store->getPdo();

        // Use SQLite for accurate counts (BlueCard sync writes here — 6,631 subscribers)
        try {
            $activeCnt   = (int)$pdo2->query("SELECT COUNT(*) FROM lte_subscribers WHERE status='active' AND deleted_at IS NULL")->fetchColumn();
            $suspendedCt = (int)$pdo2->query("SELECT COUNT(*) FROM lte_subscribers WHERE status='suspended' AND deleted_at IS NULL")->fetchColumn();
            $newSubsToday= (int)$pdo2->query("SELECT COUNT(*) FROM lte_subscribers WHERE DATE(created_at)='$today' AND deleted_at IS NULL")->fetchColumn();
            $todayRenCount=(int)$pdo2->query("SELECT COUNT(*) FROM lte_renewals WHERE DATE(created_at)='$today'")->fetchColumn();
            $lteRevToday = (float)($pdo2->query("SELECT COALESCE(SUM(amount_paid),0) FROM lte_renewals WHERE DATE(created_at)='$today'")->fetchColumn());
        } catch (\Throwable $e) {
            // Fallback to JSON if SQLite tables don't exist yet
            $allRenewals  = $store->load('lte_renewals.json') ?? [];
            $todayRenewals= array_values(array_filter($allRenewals, fn($r)=>substr($r['created_at']??'',0,10)===$today));
            $lteRevToday  = array_sum(array_map(fn($r)=>(float)($r['amount_paid']??0), $todayRenewals));
            $subs         = $store->load('lte_subscribers.json') ?? [];
            $newSubsToday = count(array_filter($subs, fn($s)=>substr($s['created_at']??'',0,10)===$today));
            $activeCnt    = count(array_filter($subs, fn($s)=>($s['status']??'')==='active'));
            $suspendedCt  = count(array_filter($subs, fn($s)=>($s['status']??'')==='suspended'));
            $todayRenCount= count($todayRenewals);
        }

        $colls      = $store->load('payment_collections.json') ?? [];
        $todayColls = array_filter($colls, fn($c)=>substr($c['created_at']??'',0,10)===$today);
        $collsRev   = array_sum(array_map(fn($c)=>(float)($c['amount']??0), $todayColls));
        $apps       = $store->load('kyc_applications.json') ?? [];
        $appsCnt    = count(array_filter($apps, fn($a)=>substr($a['created_at']??'',0,10)===$today));

        // Agent revenue — from today's renewals + collections
        $agentRev = [];
        try {
            $renRows = $pdo2->query("SELECT agent_name, SUM(amount_paid) as rev FROM lte_renewals WHERE DATE(created_at)='$today' GROUP BY agent_name")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($renRows as $r) { $agentRev[$r['agent_name']??'Unknown'] = (float)$r['rev']; }
        } catch (\Throwable $e) {}
        foreach ($todayColls as $c) { $an=$c['retailer_name']??'Unknown'; $agentRev[$an]=($agentRev[$an]??0)+(float)($c['amount']??0); }
        arsort($agentRev);
        $topAgent=$agentRev?array_key_first($agentRev):'—'; $topAgentRev=$agentRev[$topAgent]??0;
        $ok2(['date'=>$today,'generated_at'=>date('Y-m-d H:i:s'),'is_live'=>true,
            'lte'=>['renewals'=>$todayRenCount,'revenue'=>round($lteRevToday,2),'new_subs'=>$newSubsToday,'active'=>$activeCnt,'suspended'=>$suspendedCt],
            'collections'=>['count'=>count($todayColls),'revenue'=>round($collsRev,2)],
            'kyc'=>['applications'=>$appsCnt],
            'top_agent'=>['name'=>$topAgent,'revenue'=>round($topAgentRev,2)],
            'total_revenue'=>round($lteRevToday+$collsRev,2)]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // RETAILER SETTLEMENT
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'lte_settlements') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $snaps = $store->load('lte_settlement_snapshots.json') ?? [];
        usort($snaps, fn($a,$b)=>strcmp($b['date']??'',$a['date']??'')); 
        $ok2(array_slice($snaps, 0, 60));
    }

    if ($act === 'lte_mark_settled' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $date    = $body['date']     ?? '';
        $agentId = (int)($body['agent_id'] ?? 0);
        $note    = trim($body['note'] ?? '');
        if (!$date) $er2('date required.', 422);
        $snaps = $store->load('lte_settlement_snapshots.json') ?? [];
        $found = false;
        foreach ($snaps as &$snap) {
            if (($snap['date']??'')===$date) {
                if ($agentId) {
                    foreach ($snap['agents'] as &$ag) {
                        if ((int)($ag['id']??0)===$agentId) {
                            $ag['settled']=$ag['settled_at']=$ag['settled_by']=$ag['settle_note']=null;
                            $ag['settled']=true; $ag['settled_at']=date('Y-m-d H:i:s');
                            $ag['settled_by']=$retailer['name']; $ag['settle_note']=$note; $found=true;
                        }
                    } unset($ag);
                } else {
                    $snap['settled']=true; $snap['settled_at']=date('Y-m-d H:i:s');
                    $snap['settled_by']=$retailer['name']; $snap['settle_note']=$note;
                    foreach ($snap['agents'] as &$ag) {
                        if (empty($ag['settled'])) { $ag['settled']=true; $ag['settled_at']=date('Y-m-d H:i:s'); $ag['settled_by']=$retailer['name']; }
                    } unset($ag); $found=true;
                }
            }
        } unset($snap);
        if (!$found) $er2('Snapshot not found for date: '.$date, 404);
        $store->save('lte_settlement_snapshots.json', $snaps);
        $store->appendWithId('activity_log.json', ['event'=>'settlement_marked','actor'=>$retailer['name'],
            'detail'=>"Settlement marked for {$date}".($agentId?" (agent #{$agentId})":" (all agents)").($note?" — {$note}":""),
            'created_at'=>date('Y-m-d H:i:s')]);
        $ok2(['settled'=>true,'date'=>$date]);
    }

    if ($act === 'lte_settlement_generate' && $met === 'POST') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $date = $body['date'] ?? date('Y-m-d', strtotime('-1 day'));
        $rates = [
            'starlink'=>(float)($config['starlink_commission_rate']??$config['commission_rate']??5),
            'fiber'   =>(float)($config['fiber_commission_rate']??$config['commission_rate']??5),
            'lte'     =>(float)($config['lte_commission_rate']??$config['commission_rate']??5),
        ];
        $agents=[];
        foreach ($store->load('payment_collections.json')??[] as $c) {
            if (substr($c['created_at']??'',0,10)!==$date) continue;
            $aid=(int)($c['retailer_id']??0); $aname=$c['retailer_name']??'Agent #'.$aid;
            if (!isset($agents[$aid])) $agents[$aid]=['id'=>$aid,'name'=>$aname,'collected'=>0.0,'commission'=>0.0,'net'=>0.0,'transactions'=>0,'settled'=>false];
            $svc=strtolower($c['service_type']??'starlink'); if(!in_array($svc,['starlink','fiber']))$svc='starlink';
            $amt=(float)($c['amount']??0); $comm=(float)($c['commission']??0)?:round($amt*$rates[$svc]/100,2);
            $agents[$aid]['collected']+=$amt; $agents[$aid]['commission']+=$comm; $agents[$aid]['transactions']++;
        }
        foreach ($store->load('lte_renewals.json')??[] as $r) {
            if (substr($r['created_at']??'',0,10)!==$date) continue;
            $aid=(int)($r['agent_id']??0); $aname=$r['agent_name']??'Agent #'.$aid;
            if (!isset($agents[$aid])) $agents[$aid]=['id'=>$aid,'name'=>$aname,'collected'=>0.0,'commission'=>0.0,'net'=>0.0,'transactions'=>0,'settled'=>false];
            $amt=(float)($r['amount_paid']??0); $comm=round($amt*$rates['lte']/100,2);
            $agents[$aid]['collected']+=$amt; $agents[$aid]['commission']+=$comm; $agents[$aid]['transactions']++;
        }
        foreach ($agents as &$ag) { $ag['net']=round($ag['collected']-$ag['commission'],2); $ag['collected']=round($ag['collected'],2); $ag['commission']=round($ag['commission'],2); } unset($ag);
        $snapshot=['date'=>$date,'created_at'=>date('Y-m-d H:i:s'),'rates'=>$rates,'agents'=>array_values($agents),
            'totals'=>['collected'=>round(array_sum(array_column($agents,'collected')),2),'commission'=>round(array_sum(array_column($agents,'commission')),2),'net'=>round(array_sum(array_column($agents,'net')),2)],'settled'=>false];
        $snaps=$store->load('lte_settlement_snapshots.json')??[];
        $snaps=array_values(array_filter($snaps,fn($s)=>($s['date']??'')===$date?false:true));
        $snaps[]=$snapshot; $store->save('lte_settlement_snapshots.json',$snaps);
        $ok2($snapshot);
    }

    // ══════════════════════════════════════════════════════════════════════
    // UCRM SYNC HEALTH
    // ══════════════════════════════════════════════════════════════════════
    if ($act === 'ucrm_sync_health') {
        if (!$isAdmin) $er2('Admin only.', 403);
        $queue   = $store->load('crm_queue.json') ?? [];
        $failed  = array_values(array_filter($queue, fn($j)=>($j['status']??'')==='failed'));
        $pending = array_values(array_filter($queue, fn($j)=>($j['status']??'')==='pending'));
        $unsynced= array_values(array_filter($store->load('kyc_applications.json')??[],
            fn($a)=>in_array($a['status']??'',['pending','pending_sync'])&&empty($a['crm_client_id'])));
        $stuck   = array_values(array_filter($unsynced,
            fn($a)=>!empty($a['created_at'])&&(time()-strtotime($a['created_at']))>1800));
        $colls   = $store->load('payment_collections.json') ?? [];
        $unsyncedColls=array_values(array_filter($colls,fn($c)=>empty($c['crm_synced'])&&!empty($c['crm_customer_id'])));
        $lockFile=$dataDir.'/cron_sync.lock'; $lteLock=$dataDir.'/cron_lte.lock';
        $actLog  = $store->load('activity_log.json') ?? [];
        $logErrs = array_values(array_filter($actLog, fn($e)=>
            str_contains(strtolower($e['detail']??''),'fail')||str_contains(strtolower($e['event']??''),'fail')));
        usort($logErrs,fn($a,$b)=>strcmp($b['created_at']??'',$a['created_at']??'')); 
        $ok2([
            'queue'=>['failed'=>count($failed),'pending'=>count($pending),'failed_jobs'=>array_slice($failed,0,20)],
            'applications'=>['unsynced'=>count($unsynced),'stuck'=>count($stuck),'stuck_apps'=>array_slice($stuck,0,20)],
            'collections'=>['unsynced'=>count($unsyncedColls),'items'=>array_slice($unsyncedColls,0,10)],
            'cron'=>['sync_last_run'=>file_exists($lockFile)?date('Y-m-d H:i:s',filemtime($lockFile)):null,
                     'lte_last_run' =>file_exists($lteLock) ?date('Y-m-d H:i:s',filemtime($lteLock)):null],
            'recent_errors'=>array_slice($logErrs,0,10),
        ]);
    }

    // ── Daily summary email ──────────────────────────────────────────────────
    if ($act === 'send_daily_summary') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);
        $eSettings  = $store->load('email_settings.json');
        $recipients = trim($eSettings['recipients'] ?? '');
        if (!$recipients) $er2('No email recipients configured. Set them in Settings → Email Notifications.', 400);

        // Gather stats
        $allApps    = $store->load('kyc_applications.json');
        $today      = date('Y-m-d');
        $todayApps  = array_filter($allApps, fn($a) => substr($a['created_at']??'',0,10) === $today);
        $allLedger  = $store->load('passbook.json');
        $todayPay   = array_filter($allLedger, fn($t) => substr($t['created_at']??'',0,10)===$today && ($t['type']??'')==='debit');
        $todayRev   = array_sum(array_column(array_values($todayPay),'amount'));
        $pendingQ   = array_filter($allApps, fn($a) => in_array($a['status']??'',['pending','pending_sync']));
        $wallets    = $store->load('retailers.json');
        $lowBal     = array_filter($wallets, fn($r) => ($store->load('passbook.json') && $wallet->getBalance($r['id']??0) < 50 && !empty($r['is_active'])));

        $body  = "<html><body style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>";
        $body .= "<h2 style='color:#D41C1C;'>📡 DishNet Daily Summary — ".date('l, F j, Y')."</h2>";
        $body .= "<hr style='border:1px solid #eee;'>";
        $body .= "<h3>📋 Applications Today</h3>";
        $body .= "<table style='border-collapse:collapse;width:100%;font-size:14px;'>";
        $body .= "<tr><td style='padding:6px 10px;border:1px solid #ddd;'>New Applications</td><td style='padding:6px 10px;border:1px solid #ddd;font-weight:bold;color:#D41C1C;'>".count($todayApps)."</td></tr>";
        $body .= "<tr><td style='padding:6px 10px;border:1px solid #ddd;'>Revenue Collected Today</td><td style='padding:6px 10px;border:1px solid #ddd;font-weight:bold;color:#16a34a;'>$".number_format($todayRev,2)."</td></tr>";
        $body .= "<tr><td style='padding:6px 10px;border:1px solid #ddd;'>Pending CRM Sync</td><td style='padding:6px 10px;border:1px solid #ddd;color:".( count($pendingQ)>0?'#D97706':'#16a34a' )."'>".count($pendingQ)."</td></tr>";
        $body .= "</table>";
        if (count($lowBal) > 0) {
            $body .= "<h3 style='color:#D97706;'>⚠️ Low Wallet Alerts</h3><ul>";
            foreach (array_slice(array_values($lowBal),0,5) as $r) {
                $bal = $wallet->getBalance($r['id']??0);
                $body .= "<li>".htmlspecialchars($r['name']??'')." — Balance: $".number_format($bal,2)."</li>";
            }
            $body .= "</ul>";
        }
        $body .= "<hr style='border:1px solid #eee;margin-top:20px;'>";
        $body .= "<p style='font-size:11px;color:#999;'>DishNet Africa Ltd · Auto-generated daily summary</p>";
        $body .= "</body></html>";

        $smtpHost = trim($eSettings['smtp_host'] ?? '');
        $error = '';
        $sent  = false;

        if ($smtpHost) {
            $sent = sendSmtpEmail(
                $smtpHost, (int)($eSettings['smtp_port']??587),
                $eSettings['smtp_user']??'', $eSettings['smtp_pass']??'',
                $eSettings['smtp_enc']??'tls',
                'DishNet Africa', $eSettings['smtp_from']??$eSettings['smtp_user']??'',
                $recipients, '📡 DishNet Daily Summary — '.date('M j, Y'), $body, $error
            );
        } else {
            // Try UCRM built-in mailer
            $sent = sendUcrmEmail($crm->getBaseUrl()??'', $crm->getAppKey()??'', $recipients,
                '📡 DishNet Daily Summary — '.date('M j, Y'), $body, $error);
        }

        if ($sent) {
            logActivity($dataDir,'daily_summary','Daily summary email sent','To: '.$recipients);
            $ok2(['sent'=>true,'recipients'=>$recipients],'Daily summary sent successfully');
        } else {
            $er2('Email failed: '.$error, 500);
        }
    }
