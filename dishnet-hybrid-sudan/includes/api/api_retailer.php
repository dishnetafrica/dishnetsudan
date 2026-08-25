<?php
// ═══════════════════════════════════════════════════════════════
// RETAILER API (mobile app + dashboard)
// ═══════════════════════════════════════════════════════════════

    if ($act === 'me') { $r2=$me2; unset($r2['password']); $r2['wallet_summary']=$wallet->getSummary($rid); $ok2($r2); }
    if ($act === 'devices') { $ok2(['devices'=>array_values(array_filter($store->load('kyc_devices.json'), function($d){return !empty($d['is_active']);}))]); }
    if ($act === 'packages') { $ok2(['packages'=>array_values(array_filter($store->load('kyc_packages.json'), function($p){return !empty($p['is_active']);}))]); }
    if ($act === 'wallet_balance') { $ok2($wallet->getSummary($rid)); }
    if ($act === 'wallet_passbook') { $pg=max(1,(int)($_GET['pg']??1)); $pp=50; $all=$wallet->getPassbook($rid,1000); $ok2(['passbook'=>array_slice($all,($pg-1)*$pp,$pp),'summary'=>$wallet->getSummary($rid),'pagination'=>['total'=>count($all),'page'=>$pg,'per_page'=>$pp]]); }

    // ── PWA: CRM search alias ────────────────────────────────────────────
    // The old action is crm_search_customer — alias crm_search for the PWA
    if ($act === 'crm_search') {
        $_GET['q'] = $_GET['q'] ?? '';
        $act = 'crm_search_customer';
        // fall-through to the crm_search_customer handler below
    }

    // ── PWA: Client unpaid invoices ──────────────────────────────────────
    if ($act === 'crm_client_invoices') {
        $clientId = (int)($_GET['client_id'] ?? 0);
        if (!$clientId) $er2('client_id required', 422);
        $invoices = [];
        if ($crm->isConfigured()) {
            $raw = $crm->get('invoices?clientId=' . $clientId . '&statuses[]=1&statuses[]=2&limit=20');
            if (is_array($raw)) {
                foreach ($raw as $inv) {
                    $invoices[] = [
                        'id'         => $inv['id'],
                        'number'     => $inv['number'] ?? $inv['invoiceNumber'] ?? '',
                        'total'      => (float)($inv['total'] ?? 0),
                        'amount_due' => (float)($inv['amountToPay'] ?? $inv['total'] ?? 0),
                        'due_date'   => substr($inv['dueDate'] ?? '', 0, 10),
                        'status'     => $inv['status'] ?? 1,
                    ];
                }
            }
        }
        $ok2(['invoices' => $invoices]);
    }

    // ── PWA: Collect payment (JSON API version of the POST form handler) ─
    if ($act === 'collect_payment' && $met === 'POST') {
        $custName = trim($body['customer_name']   ?? '');
        $custId   = trim($body['crm_customer_id'] ?? '');
        $amount   = round((float)($body['amount'] ?? 0), 2);
        $method   = trim($body['payment_method']  ?? 'Cash');
        $note     = trim($body['payment_note']    ?? '');
        $svcType  = trim($body['service_type']    ?? 'starlink');
        $invoiceId= trim($body['invoice_id']      ?? '');
        $currency = strtoupper(trim($body['currency'] ?? 'USD'));
        if (!in_array($currency, ['USD','SSP'], true)) $currency = 'USD';

        if (!$custName || $amount <= 0) $er2('Customer name and amount are required.', 422);

        // Balance check (USD only)
        $balanceBefore = $wallet->getBalance($rid);
        if ($currency === 'USD' && $balanceBefore < $amount) {
            $er2('Insufficient wallet balance ($'.number_format($balanceBefore,2).'). Top up first.', 422);
        }

        // Duplicate guard (5-minute window)
        $recentCols = $store->load('payment_collections.json') ?? [];
        $fiveMinAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        foreach (array_reverse($recentCols) as $_rc) {
            if ((int)($_rc['retailer_id']??0)===$rid
                && trim($_rc['crm_customer_id']??'')===$custId
                && (float)($_rc['amount']??0)===$amount
                && ($_rc['created_at']??'')>=$fiveMinAgo) {
                $er2('Duplicate payment detected — same amount for this customer was collected '.human_time_diff(strtotime($_rc['created_at'])).' ago. Wait 5 minutes if intentional.', 409);
            }
        }

        // Debit wallet
        $idemKey  = 'PAY-'.$rid.'-'.md5($custId.'-'.$amount.'-'.date('Y-m-d'));
        $debitTrx = $wallet->debit($rid, $amount, "Payment collected: {$custName} ({$custId})", $me2['name'], null, $custId, $idemKey, 'order_payment', $me2['name']);
        $balanceAfter = $debitTrx['curr_balance'] ?? ($balanceBefore - $amount);

        // No commission for DishNet staff employees — commission only applies to external retailers
        $commAmount = 0;

        // CRM post
        $crmSuccess = false; $crmPaymentId = null; $crmError = '';
        if ($crm->isConfigured() && $custId) {
            // ── PHANTOM REVENUE GUARD ──────────────────────────────────────────
            // Prevents double-payment when customer pays online while agent is collecting
            if ($invoiceId && (int)$invoiceId > 0) {
                $invCheck = $crm->get("invoices/{$invoiceId}");
                if ($invCheck && isset($invCheck['id'])) {
                    $invTotal    = (float)($invCheck['total'] ?? 0);
                    $invPaid     = (float)($invCheck['amountPaid'] ?? 0);
                    $invRemaining = round($invTotal - $invPaid, 2);
                    if ($invRemaining <= 0) {
                        // Invoice already fully paid — refund wallet and reject
                        $wallet->credit($rid, $amount, "Refund: Invoice #{$invoiceId} already paid", 'System', $idemKey . '-REFUND', 'refund');
                        $er2("Invoice #{$invoiceId} is already paid. Collection cancelled — wallet refunded.", 409);
                    }
                    if ($amount > $invRemaining + 0.01) {
                        // Overpayment — refund and reject
                        $wallet->credit($rid, $amount, "Refund: Amount exceeds invoice balance", 'System', $idemKey . '-REFUND', 'refund');
                        $er2("Amount (\${$amount}) exceeds invoice balance (\${$invRemaining}). Reduce amount or leave invoice blank.", 422);
                    }
                }
            } else {
                // No specific invoice — check client's total outstanding balance
                $clientCheck = $crm->get("clients/{$custId}");
                if ($clientCheck && isset($clientCheck['id'])) {
                    $accountBalance = (float)($clientCheck['accountBalance'] ?? 0);
                    // Negative = owes money, Positive = has credit
                    if ($accountBalance >= 0) {
                        // Client has zero/credit balance — log for review (but allow)
                        logActivity($dataDir, 'credit_payment_warning',
                            "Payment to client with zero/credit balance: {$custName} (CRM #{$custId})",
                            "\${$amount} collected by {$me2['name']} via PWA — client balance was \${$accountBalance}. Review for phantom revenue."
                        );
                    }
                }
            }

            // Unique reference for duplicate prevention
            $paymentRef = 'PWA-' . $rid . '-' . $custId . '-' . date('YmdHis');

            // ── LEAD CHECK: Convert lead to client before payment push ────────
            $clientPreCheck = $crm->get("clients/{$custId}");
            if ($clientPreCheck && (int)($clientPreCheck['clientType'] ?? 1) === 1) {
                $crm->patch("clients/{$custId}", ['clientType' => 2]);
                logActivity($dataDir, 'lead_auto_convert',
                    "Lead auto-converted to client: {$custName} (CRM #{$custId})",
                    "Converted during PWA payment by {$me2['name']}. Amount: \${$amount}"
                );
            }

            $crmPayload = [
                'clientId'     => (int)$custId,
                'methodId'     => PaymentUuids::resolve($method),
                'amount'       => $amount,
                'currencyCode' => 'USD',
                'note'         => "Collected by {$me2['name']} via DishNet PWA".($invoiceId?" (Inv #{$invoiceId})":"").($note?" — {$note}":"")." | Ref: {$paymentRef}",
            ];
            // v4.21.57 — apply payment to the SPECIFIC invoice the staff
            // selected, not "oldest unpaid". Previous code passed
            // applyToInvoicesAutomatically=true regardless of $invoiceId,
            // which meant: when staff collected $50 for invoice 000024 then
            // $50 for invoice 000025, UCRM applied both to whichever was
            // oldest — leaving 000025 unpaid. Now: if invoice selected,
            // pass invoiceIds=[N] AND applyToInvoicesAutomatically=false
            // so UCRM applies to that specific invoice only.
            if ($invoiceId && (int)$invoiceId > 0) {
                $crmPayload['invoiceIds'] = [(int)$invoiceId];
                $crmPayload['applyToInvoicesAutomatically'] = false;
            } else {
                $crmPayload['applyToInvoicesAutomatically'] = true;
            }
            
            // ── DUPLICATE PREVENTION: Check UCRM before creating ──
            $crmResult   = $crm->createPaymentSafe($crmPayload, $paymentRef);
            $crmSuccess  = !empty($crmResult['success']) && !empty($crmResult['id']);
            $crmPaymentId= $crmSuccess ? $crmResult['id'] : null;
            $wasDuplicate= !empty($crmResult['duplicate']);
            
            if (!$crmSuccess) {
                $lastErr   = $crm->getLastError();
                $crmError  = isset($lastErr['http_code']) ? "HTTP {$lastErr['http_code']}: ".json_encode($lastErr['response']??'') : ($lastErr['curl_error']??json_encode($lastErr));
                logActivity($dataDir, 'crm_payment_failed', "CRM payment POST failed for {$custName} (CRM #{$custId})", '$'.number_format($amount,2).' | '.$crmError);
                $store->appendWithId('crm_payment_retry.json', [
                    'customer_name'=>$custName,'crm_client_id'=>$custId,'payload'=>$crmPayload,
                    'error'=>$crmError,'attempts'=>1,'next_retry_at'=>date('Y-m-d H:i:s',strtotime('+5 minutes')),
                    'created_at'=>date('Y-m-d H:i:s'),'status'=>'pending','payment_ref'=>$paymentRef,
                ]);
            }
        }

        // Save collection
        $collection = $store->appendWithId('payment_collections.json', [
            'retailer_id'=>$rid,'retailer_name'=>$me2['name'],'customer_name'=>$custName,
            'invoice_id'=>$invoiceId,'crm_customer_id'=>$custId,'amount'=>$amount,
            'currency'=>$currency,'method'=>$method,'service_type'=>$svcType,'note'=>$note,
            'commission'=>$commAmount,'comm_rate'=>$commRate,'crm_synced'=>$crmSuccess,
            'crm_payment_id'=>$crmPaymentId,'created_at'=>date('Y-m-d H:i:s'),
        ]);

        // Auto-post to cashbook (idempotent via SR = COL-{id})
        try {
            require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
            $cb2 = new CashbookService($store, $dataDir);
            $colId2 = $collection['id'] ?? uniqid();
            $cb2->addEntryRaw([
                'sr'                => 'COL-' . $colId2,
                'project'           => 'dishnet',
                'date'              => date('Y-m-d'),
                'direction'         => 'in',
                'amount'            => $amount,
                'currency'          => $currency,
                'category'          => 'Receipt',
                'category_raw'      => 'Receipt',
                'person'            => $me2['name'] ?? '',
                'description'       => 'Cash collected from '.$custName.($custId?' (CRM #'.$custId.')':'').($invoiceId?' — Inv #'.$invoiceId:''),
                'validation_ref'    => $crmPaymentId ? 'PAY-'.$crmPaymentId : 'COL-'.$colId2,
                'validation_status' => 'na',
                'status'            => 'approved',
                'approved_by'       => $me2['name'] ?? 'PWA Collection',
                'crm_payment_id'    => $crmPaymentId,
                'crm_client_id'     => $custId ? (int)$custId : null,
                'source'            => 'collect_payment',
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $cbErr) {
            // v4.9.19: Log instead of silently swallowing — helps diagnose missing cashbook entries
            logActivity($dataDir, 'cashbook_autopost_failed', 'PWA cashbook auto-post failed', $cbErr->getMessage());
        }

        logActivity($dataDir, 'payment_collected', 'Payment collected via PWA', '$'.number_format($amount,2).' from '.$custName.' by '.$me2['name'].' | CRM '.($crmSuccess?'synced':'pending'));

        // ── WhatsApp payment receipt to customer (Accounts number) ───────────
        try {
            if ($custId && !empty($config['wa_accounts_number'])) {
                // Resolve phone: try enrich cache first, then live CRM fetch
                $custPhone = '';
                $enrichCache = $store->load('crm_enrich_cache.json');
                $cachedClient = $enrichCache['clients_by_id'][(int)$custId] ?? null;
                if ($cachedClient) {
                    foreach (($cachedClient['contacts'] ?? []) as $ct) {
                        if (!empty($ct['phone'])) { $custPhone = $ct['phone']; break; }
                    }
                    if (!$custPhone) $custPhone = trim($cachedClient['phone'] ?? '');
                }
                if (!$custPhone && $crm->isConfigured()) {
                    $liveClient = $crm->get("clients/{$custId}");
                    if ($liveClient) {
                        foreach (($liveClient['contacts'] ?? []) as $ct) {
                            if (!empty($ct['phone'])) { $custPhone = $ct['phone']; break; }
                        }
                        if (!$custPhone) $custPhone = trim($liveClient['phone'] ?? '');
                    }
                }
                if ($custPhone) {
                    $txnRef = $crmPaymentId ? 'CRM-PAY-'.$crmPaymentId : 'COL-'.($collection['id']??'');
                    $notify->paymentReceived($custPhone, $custName, $amount, $txnRef);

                    // Mark as notified — webhook.php checks this to avoid double-send
                    if ($crmPaymentId) {
                        $payLog = $store->load('payment_notify_log.json') ?: [];
                        $payLog['PAY'.$crmPaymentId] = date('Y-m-d H:i:s');
                        $store->save('payment_notify_log.json', $payLog);
                    }
                }
            }
        } catch (\Throwable $waErr) { /* non-fatal */ }

        $ok2([
            'collected'        => true,
            'amount'           => $amount,
            'currency'         => $currency,
            'commission'       => $commAmount,
            'crm_synced'       => $crmSuccess,
            'crm_payment_id'   => $crmPaymentId,
            'crm_error'        => $crmError ?: null,
            'new_balance'      => round($balanceAfter, 2),
            'balance_before'   => round($balanceBefore, 2),
        ]);
    }
    // ── Change own password (used by force-change modal + profile) ──────────
    if ($act === 'change_password') {
        if ($met !== 'POST') $er2('POST required.', 405);
        $curPwd  = trim($body['current_password']  ?? '');
        $newPwd  = trim($body['new_password']       ?? '');
        $confPwd = trim($body['confirm_password']   ?? '');
        if (!$curPwd)                   $er2('Current password is required.');
        if (strlen($newPwd) < 8)        $er2('Password must be at least 8 characters.');
        if ($newPwd !== $confPwd)        $er2('Passwords do not match.');
        // Verify current password
        if (!$auth->verifyPassword($rid, $curPwd)) $er2('Current password is incorrect.');
        $auth->updateRetailer($rid, ['password' => $newPwd], false);
        if (isset($_SESSION['dn_retailer'])) $_SESSION['dn_retailer']['must_change_pwd'] = false;
        $ok2([], 'Password changed successfully.');
    }

    if ($act === 'kyc_submit') {
        if ($met !== 'POST') $er2('POST required.', 405);
        $req = ['connectivity_type','customer_type','firstname','lastname','mobile','address_1'];
        foreach ($req as $f) { if (empty(trim($body[$f] ?? ''))) $er2("$f required.", 422); }

        // ── Stock availability check (non-blocking) ──────────────────
        $stockWarnings = [];
        if (!empty($body['hw_cart_json'])) {
            try {
                require_once __DIR__ . '/../../lib/StockService.php';
                $_stkSvc = StockService::fromStore($store, $dataDir);
                $_stkSvc->ensureTables();
                $hwCart = json_decode($body['hw_cart_json'], true) ?? [];
                if (!empty($hwCart)) {
                    $availability = $_stkSvc->checkAvailability($hwCart);
                    foreach ($availability as $a) {
                        if (!$a['ok'] && $a['available'] !== null) {
                            $stockWarnings[] = ($a['title'] ?? 'Item') . ': only ' . $a['available'] . ' in stock (need ' . $a['needed'] . ')';
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Don't block KYC on stock check failure
            }
        }

        // ── Process KYC submission ───────────────────────────────────
        $res = $kyc->process($body, [], $me2);
        if ($res['success']) {
            logActivity($dataDir, 'kyc_submitted', 'New connection registered', 'CRM #' . ($res['data']['crm_client_id'] ?? '') . ' — ' . ($res['data']['customer_name'] ?? $body['firstname'] ?? ''));
            $responseData = $res['data'] ?? [];
            if (!empty($stockWarnings)) {
                $responseData['stock_warnings'] = $stockWarnings;
            }
            $msg = $res['message'];
            if (!empty($stockWarnings)) {
                $msg .= ' ⚠️ Stock warning: ' . implode('; ', $stockWarnings);
            }
            $ok2($responseData, $msg, 201);
        } else {
            $er2($res['message'], 400);
        }
    }
    if ($act === 'kyc_list') { $a=$kyc->getForRetailer($rid,100); $ok2(['applications'=>$a,'total'=>count($a)]); }
    if ($act === 'recharge_submit') { if($met!=='POST')$er2('POST required.',405); $res=$recharge->submit($body,[],$me2); if($res['success'])$ok2($res['data']??[],$res['message'],201); else $er2($res['message'],400); }
    if ($act === 'recharge_list') { $l=$recharge->getForRetailer($rid,50); $ok2(['recharges'=>$l,'total'=>count($l)]); }
        // ── Org 7 clients — for retailers tab live view ─────────────────────────
    if ($act === 'org7_status') {
        if (!($me2['is_admin'] ?? false)) $er2('Admin only.', 403);
        $cache    = $store->load('org7_crm_cache.json');
        $clients  = $cache['clients'] ?? [];
        $syncStatus = $ftthCrm->getSyncStatus();
        $ok2([
            'fetched_at'      => $cache['fetched_at'] ?? null,
            'org_id'          => $cache['org_id'] ?? ($config['crm_ftth_org_id'] ?? 7),
            'client_count'    => count($clients),
            'clients'         => $clients,
            'plugin_sync'     => $syncStatus,
        ]);
    }

    if ($act === 'crm_search_customer') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) $er2('Search query too short.', 422);

        $results = [];

        // ── 1. UCRM (Starlink + Fiber) ───────────────────────────────
        if ($crm->isConfigured()) {
            $crmResults = $crm->get('clients?search=' . urlencode($q) . '&limit=10');
            if (is_array($crmResults)) {
                foreach ($crmResults as $c) {
                    $results[] = [
                        'source'      => 'ucrm',
                        'id'          => $c['id'],
                        'name'        => trim(($c['firstName']??'').' '.($c['lastName']??'')),
                        'company'     => $c['companyName'] ?? '',
                        'phone'       => $c['contacts'][0]['phone'] ?? ($c['phone'] ?? ''),
                        'email'       => $c['contacts'][0]['email'] ?? ($c['email'] ?? ''),
                        'ucrm_id'     => $c['id'],
                        'balance'     => $c['accountBalance'] ?? null,
                        'type'        => 'starlink_fiber',
                        'type_label'  => 'UCRM Client',
                        'type_color'  => '#D41C1C',
                        'type_icon'   => 'bi-wifi',
                    ];
                }
            }
        }

        // ── 2. LTE Subscribers ───────────────────────────────────────
        $lteAll = $store->load('lte_subscribers.json');
        $ql = strtolower($q);
        foreach ($lteAll as $s) {
            if (str_contains(strtolower($s['name']??''), $ql)
             || str_contains($s['phone']??'', $q)
             || str_contains($s['msisdn']??'', $q)
             || str_contains($s['imsi']??'', $q)
             || str_contains(strtolower($s['email']??''), $ql)) {
                $results[] = [
                    'source'      => 'lte',
                    'id'          => $s['id'],
                    'name'        => $s['name'] ?? '',
                    'company'     => '',
                    'phone'       => $s['phone'] ?? '',
                    'email'       => $s['email'] ?? '',
                    'msisdn'      => $s['msisdn'] ?? '',
                    'imsi'        => $s['imsi'] ?? '',
                    'lte_id'      => $s['id'],
                    'type'        => 'lte',
                    'type_label'  => 'LTE Subscriber',
                    'type_color'  => '#7C3AED',
                    'type_icon'   => 'bi-reception-4',
                    'status'      => $s['status'] ?? 'active',
                ];
            }
        }

        // Cap at 20 total
        $ok2(array_slice($results, 0, 20));
        }


    // ── check_phone_duplicate — real-time phone check for KYC form ────────────
    if ($act === 'check_phone_duplicate') {
        $rawPhone = trim($_GET['phone'] ?? '');
        if (strlen($rawPhone) < 6) $ok2(['status' => 'clear', 'message' => '']);

        $norm = preg_replace('/[^0-9]/', '', $rawPhone);
        $last9 = strlen($norm) >= 9 ? substr($norm, -9) : $norm;

        $matches     = [];    // existing clients that match
        $failedMatch = false; // if only match is a failed app

        // ── Check 1: local kyc_applications.json ─────────────────────────────
        $apps = $store->load('kyc_applications.json') ?? [];
        foreach ($apps as $app) {
            $appPhone = preg_replace('/[^0-9]/', '', $app['mobile'] ?? '');
            $appLast9 = strlen($appPhone) >= 9 ? substr($appPhone, -9) : $appPhone;
            if ($appLast9 !== $last9 || $appLast9 === '') continue;

            $appStatus = $app['sync_status'] ?? $app['status'] ?? '';
            if (in_array($appStatus, ['failed', 'voided', 'rejected', 'cancelled'])) {
                $failedMatch = true;
                continue; // failed apps — allowed to retry
            }
            $matches[] = [
                'source'   => 'plugin',
                'crm_id'   => (int)($app['crm_client_id'] ?? 0),
                'name'     => trim(($app['firstname'] ?? '') . ' ' . ($app['lastname'] ?? ''))
                           ?: ($app['company_name'] ?? 'Unknown'),
                'phone'    => $app['mobile'] ?? '',
                'status'   => $appStatus,
                'app_id'   => $app['id'] ?? null,
                'service'  => $app['connectivity_type'] ?? '',
            ];
        }

        // ── Check 2: client_search_index (SQLite table, O(1) — JSON blob fallback) ──
        $existingCrmIds = array_map(fn($m) => (int)($m['crm_id'] ?? 0), $matches);
        try {
            // SQLite indexed lookup — O(1) via phone_norm index
            $pdo = $store->getPdo();
            $csiRows = $pdo->prepare(
                "SELECT id, name, phone, service FROM client_search_index WHERE phone_norm = ? LIMIT 5"
            );
            $csiRows->execute([$last9]);
            $csiResults = $csiRows->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Table doesn't exist yet (migration 054 not run) — fall back to JSON blob
            $csiResults = null;
        }

        if ($csiResults !== null) {
            // Use SQLite results
            foreach ($csiResults as $cl) {
                $crmId = (int)($cl['id'] ?? 0);
                if (in_array($crmId, $existingCrmIds, true)) continue;
                $matches[] = [
                    'source'  => 'crm',
                    'crm_id'  => $crmId,
                    'name'    => $cl['name'] ?? 'Unknown',
                    'phone'   => $cl['phone'] ?? '',
                    'status'  => 'synced',
                    'service' => $cl['service'] ?? '',
                ];
            }
        } else {
            // Fallback: JSON blob linear scan
            $index = $store->load('client_search_index.json') ?? [];
            foreach ($index as $cl) {
                $clPhone = preg_replace('/[^0-9]/', '', $cl['phone'] ?? '');
                $clLast9 = strlen($clPhone) >= 9 ? substr($clPhone, -9) : $clPhone;
                if ($clLast9 !== $last9 || $clLast9 === '') continue;
                $crmId = (int)($cl['id'] ?? 0);
                if (in_array($crmId, $existingCrmIds, true)) continue;
                $matches[] = [
                    'source'  => 'crm',
                    'crm_id'  => $crmId,
                    'name'    => $cl['name'] ?? 'Unknown',
                    'phone'   => $cl['phone'] ?? '',
                    'status'  => 'synced',
                    'service' => $cl['service'] ?? '',
                ];
            }
        }

        if (empty($matches)) {
            if ($failedMatch) {
                $ok2(['status' => 'failed_retry', 'message' => 'Previous registration failed — retrying allowed.', 'matches' => []]);
            }
            $ok2(['status' => 'clear', 'message' => '', 'matches' => []]);
        }

        // Multiple matches = shared phone
        $responseStatus = count($matches) >= 2 ? 'shared' : 'found';
        $ok2([
            'status'  => $responseStatus,
            'matches' => $matches,
            'count'   => count($matches),
        ]);
    }

    // ── check_name_duplicate — name similarity check for KYC form ────────────
    if ($act === 'check_name_duplicate') {
        $name = strtolower(trim($_GET['name'] ?? ''));
        if (strlen($name) < 3) $ok2(['status' => 'clear', 'matches' => []]);

        $matches = [];

        // Simple similarity: check if name contains or is contained by existing names
        // Also check word overlap (handles "Norwegian Aid" vs "Norwegian Peoples Aid")
        $nameWords = array_filter(explode(' ', $name), fn($w) => strlen($w) > 2);

        $index = $store->load('client_search_index.json') ?? [];
        foreach ($index as $cl) {
            $clName = strtolower(trim($cl['name'] ?? ''));
            if (strlen($clName) < 3) continue;

            $similar = false;
            // Exact or substring match
            if (strpos($clName, $name) !== false || strpos($name, $clName) !== false) {
                $similar = true;
            }
            // Word overlap >= 2 words
            if (!$similar && count($nameWords) >= 2) {
                $clWords = array_filter(explode(' ', $clName), fn($w) => strlen($w) > 2);
                $overlap = count(array_intersect($nameWords, $clWords));
                if ($overlap >= 2) $similar = true;
            }
            // similar_text >= 75%
            if (!$similar) {
                similar_text($name, $clName, $pct);
                if ($pct >= 75) $similar = true;
            }

            if ($similar) {
                $matches[] = [
                    'crm_id'  => (int)($cl['id'] ?? 0),
                    'name'    => $cl['name'] ?? '',
                    'phone'   => $cl['phone'] ?? '',
                    'service' => $cl['service'] ?? '',
                ];
                if (count($matches) >= 3) break; // cap at 3
            }
        }

        $ok2(['status' => empty($matches) ? 'clear' : 'found', 'matches' => $matches]);
    }


    if ($act === 'create_ticket' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $tickets = $store->load('support_tickets.json') ?: [];
        $ticket = [
            'id' => $store->nextId('support_tickets.json'),
            'customer_name' => trim($body['customer_name'] ?? ''),
            'customer_id' => trim((string)($body['customer_id'] ?? '')),
            'subject' => trim($body['subject'] ?? ''),
            'description' => trim($body['note'] ?? ''),
            'priority' => 'medium', 'category' => 'other', 'status' => 'open',
            'assigned_to' => $retailerId, 'assigned_name' => $retailer['name'],
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $tickets[] = $ticket;
        $store->save('support_tickets.json', $tickets);
        $ok2($ticket);
    }

    if ($act === 'customer_invoices') {
        $cid = (int)($_GET['cid'] ?? 0);
        if ($cid <= 0) $er2('Customer ID required.', 422);
        if (!$crm->isConfigured()) $er2('CRM API not configured.', 503);
        $client = $crm->get("clients/{$cid}");
        $invoices = $crm->get("billing/invoices?clientId={$cid}&limit=30&status[]=1&status[]=2");
        $services = $crm->get("clients/{$cid}/services");
        $ok2([
            'client'   => $client,
            'invoices' => is_array($invoices) ? $invoices : [],
            'services' => is_array($services) ? $services : [],
            'balance'  => $client['accountBalance'] ?? 0,
        ]);
    }

    // ── Customer 360 — full profile for the detail view ──────────────────────
    if ($act === 'customer_360') {
        $cid = (int)($_GET['cid'] ?? 0);
        if ($cid <= 0) $er2('Client ID required.', 422);
        if (!$crm->isConfigured()) $er2('CRM API not configured.', 503);

        // ── 1. Core CRM profile ──
        $client = $crm->get("clients/{$cid}");
        if (!$client) $er2('Client not found in UCRM.', 404);

        // ── 2. CRM financial data ──
        $invoices = $crm->get("billing/invoices?clientId={$cid}&limit=50") ?? [];
        $payments = $crm->get("billing/payments?clientId={$cid}&limit=50") ?? [];
        $services = $crm->get("clients/{$cid}/services") ?? [];
        $quotes   = $crm->get("billing/quotes?clientId={$cid}&limit=20") ?? [];
        $credits  = $crm->get("billing/refunds?clientId={$cid}&limit=20") ?? [];

        // ── 3. CRM jobs ──
        $jobs = [];
        try { $jobData = $crm->get("scheduling/jobs?clientId={$cid}&limit=20"); $jobs = is_array($jobData) ? $jobData : []; } catch (\Throwable $e) {}

        // ── 4. Local KYC applications ──
        $kycApps = [];
        try {
            $allKyc = $store->load('kyc_applications.json') ?? [];
            $kycApps = array_values(array_filter($allKyc, function($a) use ($cid) { return (int)($a['crm_client_id'] ?? 0) === $cid; }));
            usort($kycApps, function($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
        } catch (\Throwable $e) {}

        // ── 5. WhatsApp conversations ──
        $waConvs = []; $waRecentMessages = [];
        try {
            require_once $GLOBALS['_PLUGIN_ROOT'] . '/lib/ConversationService.php';
            $_cs = new ConversationService($GLOBALS['dataDir'], $store->getPdo());
            $waConvs = $_cs->findByCrmClient($cid);
            if (empty($waConvs)) {
                $phone = '';
                foreach ($client['contacts'] ?? [] as $ct) {
                    if (!empty($ct['phone'])) { $phone = $ct['phone']; break; }
                    if (!empty($ct['phones'][0]['number'])) { $phone = $ct['phones'][0]['number']; break; }
                }
                if (!$phone) $phone = trim($client['phone'] ?? '');
                if ($phone) {
                    $normalized = preg_replace('/[^0-9]/', '', $phone);
                    if (strlen($normalized) >= 9) $waConvs = $_cs->findByPhone(substr($normalized, -9));
                    if (empty($waConvs) && strlen($normalized) >= 9) $waConvs = $_cs->findByPhone($normalized);
                }
            }
            if (!empty($waConvs[0]['id'])) $waRecentMessages = $_cs->getMessages((int)$waConvs[0]['id'], 10, 0);
        } catch (\Throwable $e) {}

        // ── 6. Local support tickets ──
        $allTickets = $store->load('support_tickets.json') ?? [];
        $myTickets  = array_values(array_filter($allTickets, function($t) use ($cid) { return (string)($t['customer_id'] ?? '') === (string)$cid; }));

        // ── 7. Payment collections ──
        $collections = [];
        try {
            $allColl = $store->load('payment_collections.json') ?? [];
            $collections = array_values(array_filter($allColl, function($c) use ($cid) { return (int)($c['crm_client_id'] ?? 0) === $cid; }));
            usort($collections, function($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
            $collections = array_slice($collections, 0, 20);
        } catch (\Throwable $e) {}

        // ── 8. Cashbook entries ──
        $cbEntries = [];
        try {
            $cn = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''));
            if ($cn) { $cbEntries = $store->query("SELECT * FROM cb_ledger WHERE person LIKE ? ORDER BY created_at DESC LIMIT 10", ['%' . $cn . '%']) ?: []; }
        } catch (\Throwable $e) {}

        // ── 9. Statement ──
        $statement = [];
        foreach (is_array($invoices) ? $invoices : [] as $inv) {
            $statement[] = ['type'=>'invoice','date'=>$inv['createdDate']??$inv['dueDate']??'','ref'=>'#'.($inv['number']??$inv['id']),'desc'=>'Invoice','debit'=>round((float)($inv['total']??0),2),'credit'=>round((float)($inv['amountPaid']??0),2),'status'=>$inv['status']??0,'id'=>$inv['id']];
        }
        foreach (is_array($payments) ? $payments : [] as $pay) {
            $statement[] = ['type'=>'payment','date'=>$pay['createdDate']??'','ref'=>'PAY-'.($pay['id']??''),'desc'=>$pay['method']??'Payment','debit'=>0,'credit'=>round((float)($pay['amount']??0),2),'status'=>99,'id'=>$pay['id']];
        }
        usort($statement, function($a, $b) { return strcmp($b['date'], $a['date']); });

        // ── 10. Stock returns / cancellations ──
        $stockReturns = [];
        try {
            $allReturns = $store->load('stock_returns.json') ?? [];
            $stockReturns = array_values(array_filter($allReturns, function($r) use ($cid) { return (int)($r['crm_client_id'] ?? 0) === $cid; }));
        } catch (\Throwable $e) {}

        // ── 11. Summaries ──
        $openInvs = array_filter(is_array($invoices) ? $invoices : [], function($i) { return ($i['status'] ?? 0) == 1 || ($i['status'] ?? 0) == 2; });
        $totalOwed = 0; foreach ($openInvs as $inv) { $totalOwed += (float)($inv['total'] ?? 0) - (float)($inv['amountPaid'] ?? 0); }
        $activeSvcs = count(array_filter(is_array($services) ? $services : [], function($s) { return ($s['status'] ?? 0) == 1; }));

        $ok2([
            'client'=>$client,'invoices'=>is_array($invoices)?$invoices:[],'payments'=>is_array($payments)?$payments:[],'services'=>is_array($services)?$services:[],'quotes'=>is_array($quotes)?$quotes:[],'credits'=>is_array($credits)?$credits:[],
            'jobs'=>$jobs,'tickets'=>$myTickets,'kyc_apps'=>$kycApps,'wa_convs'=>$waConvs,'wa_messages'=>$waRecentMessages,'collections'=>$collections,'cb_entries'=>$cbEntries,'statement'=>array_slice($statement,0,50),'stock_returns'=>$stockReturns,
            'summary'=>['total_owed'=>round($totalOwed,2),'active_svcs'=>$activeSvcs,'open_invoices'=>count($openInvs),'total_payments'=>count(is_array($payments)?$payments:[]),'total_quotes'=>count(is_array($quotes)?$quotes:[]),'total_jobs'=>count($jobs),'total_tickets'=>count($myTickets),'wa_threads'=>count($waConvs),'wa_unread'=>array_sum(array_column($waConvs,'unread_count')),'kyc_count'=>count($kycApps),'returns'=>count($stockReturns)],
        ]);
    }

    // ── LTE Customer 360 ─────────────────────────────────────────────
    if ($act === 'lte_360') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) $er2('ID required.', 422);
        $sub = $lte->getSubscriber($id);
        if (!$sub) $er2('LTE subscriber not found.', 404);
        // Renewal history
        $renewals = $store->findAll('lte_renewals.json', 'subscriber_id', $id);
        usort($renewals, fn($a,$b) => strcmp($b['created_at']??'',$a['created_at']??''));
        $sub['_renewals'] = array_slice($renewals, 0, 20);
        // All subscriptions (history)
        $allSubs = array_values(array_filter(
            $store->load('lte_subscriptions.json'),
            fn($s) => (int)($s['subscriber_id']??0) === $id
        ));
        usort($allSubs, fn($a,$b) => strcmp($b['created_at']??'',$a['created_at']??''));
        $sub['_subscriptions'] = $allSubs;
        // SIM details
        if (!empty($sub['sim_id'])) {
            $sub['_sim'] = $store->findOne('lte_sims.json','id',(int)$sub['sim_id']);
        }
        // Hardware details
        if (!empty($sub['hardware_id'])) {
            $sub['_hardware'] = $store->findOne('lte_hardware.json','id',(int)$sub['hardware_id']);
        }
        // Usage cache
        if (!empty($sub['imsi'])) {
            $sub['_usage'] = $lte->getCachedUsage($sub['imsi']);
            if ($magma->isConfigured()) {
                $live = $magma->getSubscriber($sub['imsi']);
                $sub['_magma_state'] = $live ? ($live['lte'] ?? null) : null;
            }
        }
        $ok2($sub);
    }

if ($act === 'customer_ledger') {
        $cid = (int)($_GET['cid'] ?? 0);
        if ($cid <= 0) $er2('Customer ID required.', 422);
        if (!$crm->isConfigured()) $er2('CRM API not configured.', 503);

        // ── 5-minute per-client invoice cache ─────────────────────────────
        $cacheKey  = "invoice_cache_{$cid}.json";
        $cacheTTL  = 300; // 5 minutes
        $cached    = $store->load($cacheKey);
        $cacheAge  = isset($cached['cached_at']) ? (time() - strtotime($cached['cached_at'])) : PHP_INT_MAX;
        $forceRefresh = (($_GET['refresh'] ?? '') === '1');

        if (!$forceRefresh && $cached && $cacheAge < $cacheTTL) {
            header('X-Cache: HIT age=' . $cacheAge . 's');
            $ok2($cached['data']);
        }
        // ──────────────────────────────────────────────────────────────────

        $client   = $crm->get("clients/{$cid}");
        $payments = $crm->get("payments?clientId={$cid}&limit=50");

        // Fetch unpaid (status 1) AND partial (status 2) invoices separately
        $invUnpaid  = $crm->get("invoices?clientId={$cid}&limit=50&statuses[]=1") ?? [];
        $invPartial = $crm->get("invoices?clientId={$cid}&limit=50&statuses[]=2") ?? [];
        $allInvoices = array_merge(
            is_array($invUnpaid)  ? $invUnpaid  : [],
            is_array($invPartial) ? $invPartial : []
        );

        // Compute amount still owed per invoice
        $unpaidList = [];
        $totalDue   = 0.0;
        foreach ($allInvoices as $inv) {
            // status 1 = unpaid, status 2 = partial
            $total     = (float)($inv['total']     ?? $inv['amount'] ?? 0);
            $paid      = (float)($inv['amountPaid'] ?? 0);
            $remaining = round($total - $paid, 2);
            if ($remaining <= 0) continue; // already fully paid
            $inv['amountToPay'] = $remaining;
            $unpaidList[] = $inv;
            $totalDue += $remaining;
        }

        // If UCRM has no open invoices but client balance is negative,
        // surface a virtual "outstanding balance" entry so agents can still collect
        $accountBalance = (float)($client['accountBalance'] ?? 0);
        $virtualBalance = null;
        if (empty($unpaidList) && $accountBalance < 0) {
            $virtualBalance = [
                'id'          => 0,
                'number'      => 'BAL',
                'total'       => abs($accountBalance),
                'amountPaid'  => 0,
                'amountToPay' => abs($accountBalance),
                'status'      => 1,
                'createdDate' => date('Y-m-d'),
                'dueDate'     => date('Y-m-d'),
                '_virtual'    => true,
                '_label'      => 'Outstanding Balance',
            ];
            $unpaidList[] = $virtualBalance;
            $totalDue = abs($accountBalance);
        }

        $responseData = [
            'client'          => $client,
            'payments'        => is_array($payments) ? $payments : [],
            'invoices'        => $allInvoices,
            'invoices_unpaid' => $unpaidList,
            'total_due'       => round($totalDue, 2),
            'account_balance' => $accountBalance,
            'has_virtual'     => !empty($virtualBalance),
        ];
        // Save to cache
        $store->save($cacheKey, ['cached_at' => date('Y-m-d H:i:s'), 'data' => $responseData]);
        header('X-Cache: MISS');
        $ok2($responseData);
    }
