<?php
// ═══════════════════════════════════════════════════════════════
// PAYMENT ADMIN (pre-auth, session-authed)
// ═══════════════════════════════════════════════════════════════


    // ─── VOID PAYMENT: Reverse a mistaken collection ──────────────────────────
    // POST ?page=api&action=void_payment
    // Body: { "collection_id": 123, "reason": "Customer didn't pay" }
    // Permissions: admin, accounts, support_leader, or the original retailer (within 24h)
    if ($act === 'void_payment' && $met === 'POST') {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        require_once dirname(__DIR__, 2) . '/lib/WalletService.php';
        require_once dirname(__DIR__, 2) . '/lib/RetailerAuth.php';
        
        // Session auth for admin users
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $sessionUser = $_SESSION['admin'] ?? $_SESSION['retailer'] ?? null;
        
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $colId  = (int)($body['collection_id'] ?? 0);
        $reason = trim($body['reason'] ?? '');
        
        if (!$colId) $er2('collection_id required', 400);
        if (!$reason) $er2('reason required', 400);
        
        // Find the collection
        $collection = $store->findOne('payment_collections.json', 'id', $colId);
        if (!$collection) $er2('Collection not found', 404);
        
        // Already voided?
        if (($collection['status'] ?? '') === 'voided') {
            $er2('This payment was already voided on ' . ($collection['voided_at'] ?? '?'), 400);
        }
        
        // Permission check
        $canVoid = false;
        $voidedBy = 'Unknown';
        
        // Admin/accounts/support_leader from session can always void
        $userRole = $sessionUser['role'] ?? '';
        if (in_array($userRole, ['admin', 'accounts', 'support_leader', 'ceo'])) {
            $canVoid = true;
            $voidedBy = $sessionUser['name'] ?? $sessionUser['email'] ?? $userRole;
        }
        // Retailer from session can void their own within 24 hours
        elseif (!empty($sessionUser['id']) && ($sessionUser['id'] ?? 0) == ($collection['retailer_id'] ?? -1)) {
            $colTime = strtotime($collection['created_at'] ?? '2000-01-01');
            $hoursSince = (time() - $colTime) / 3600;
            if ($hoursSince <= 24) {
                $canVoid = true;
                $voidedBy = $sessionUser['name'] ?? 'Retailer #' . $sessionUser['id'];
            } else {
                $er2('You can only void your own collections within 24 hours. Contact admin.', 403);
            }
        }
        
        if (!$canVoid) {
            $er2('You do not have permission to void this payment. Please login as admin.', 403);
        }
        
        $amount     = (float)($collection['amount'] ?? 0);
        $retailerId = (int)($collection['retailer_id'] ?? 0);
        $custName   = $collection['customer_name'] ?? 'Unknown';
        $crmPayId   = $collection['crm_payment_id'] ?? null;
        
        // 1. Try to delete payment from UCRM (if it was synced)
        $crmDeleted = false;
        $crmError   = null;
        if ($crmPayId) {
            $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
            if ($crm->isConfigured()) {
                // UCRM API: DELETE /payments/{id}
                $delResult = $crm->delete("payments/{$crmPayId}");
                if ($delResult === true || (is_array($delResult) && empty($delResult['code']))) {
                    $crmDeleted = true;
                } else {
                    $lastErr = $crm->getLastError();
                    $crmError = $lastErr['http_code'] ?? 'Unknown error';
                    // 404 means already deleted - that's OK
                    if (($lastErr['http_code'] ?? 0) == 404) {
                        $crmDeleted = true;
                        $crmError = null;
                    }
                }
            }
        }
        
        // 2. Credit retailer's wallet back
        $wallet = new WalletService($store);
        $walletCredited = false;
        if ($retailerId > 0 && $amount > 0) {
            $idemKey = 'VOID-COL-' . $colId;
            $walletResult = $wallet->credit(
                $retailerId,
                $amount,
                "VOID: {$custName} — {$reason}",
                $voidedBy,
                $idemKey,
                'void_reversal'
            );
            $walletCredited = !empty($walletResult['success']);
        }
        
        // 3. Mark collection as voided
        $store->updateOne('payment_collections.json', 'id', $colId, [
            'status'           => 'voided',
            'voided_at'        => date('Y-m-d H:i:s'),
            'voided_by'        => $voidedBy,
            'void_reason'      => $reason,
            'crm_deleted'      => $crmDeleted,
            'crm_delete_error' => $crmError,
            'wallet_credited'  => $walletCredited,
        ]);
        
        // 4. Log activity
        logActivity($dataDir, 'payment_voided', 
            "Payment voided: {$custName} — \${$amount}",
            "By: {$voidedBy} | Reason: {$reason} | CRM deleted: " . ($crmDeleted ? 'Yes' : 'No') . " | Wallet credited: " . ($walletCredited ? 'Yes' : 'No')
        );
        
        $ok2([
            'voided'          => true,
            'collection_id'   => $colId,
            'amount'          => $amount,
            'customer'        => $custName,
            'crm_deleted'     => $crmDeleted,
            'crm_error'       => $crmError,
            'wallet_credited' => $walletCredited,
            'voided_by'       => $voidedBy,
        ], "Payment voided successfully. Wallet credited \${$amount} back to retailer.");
    }

    // ─── MANUAL: Patch UCRM payment note with handover info ─────────────────────
    // POST ?page=api&action=patch_ucrm_payment
    // Body: { "collection_id": 123, "handover_id": 45 }
    // Admin only - used to fix collections that weren't linked during handover
    if ($act === 'patch_ucrm_payment' && $met === 'POST') {
        // Session auth for admin
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $sessionUser = $_SESSION['admin'] ?? null;
        if (!$sessionUser || !in_array($sessionUser['role'] ?? '', ['admin', 'accounts', 'ceo'])) {
            $er2('Admin only', 403);
        }
        
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $colId = (int)($body['collection_id'] ?? 0);
        $hovId = (int)($body['handover_id'] ?? 0);
        
        if (!$colId) $er2('collection_id required', 400);
        if (!$hovId) $er2('handover_id required', 400);
        
        // Find collection
        $collection = $store->findOne('payment_collections.json', 'id', $colId);
        if (!$collection) $er2('Collection not found', 404);
        
        // Find handover
        $allHov = $store->load('cash_handovers.json') ?? [];
        $handover = null;
        foreach ($allHov as $h) {
            if ((int)($h['id'] ?? 0) === $hovId) { $handover = $h; break; }
        }
        if (!$handover) $er2('Handover not found', 404);
        
        $crmPayId = (int)($collection['crm_payment_id'] ?? 0);
        if (!$crmPayId) $er2('Collection has no CRM payment ID', 400);
        
        // Build structured note
        $location = 'Office';
        $origNote = $collection['note'] ?? '';
        if (preg_match('/@\s*([^\|]+)/i', $origNote, $m)) {
            $location = trim($m[1]);
        } elseif (!empty($handover['notes'])) {
            $location = $handover['notes'];
        }
        
        $cbReceipt = $handover['cashbook_receipt'] ?? ('HOV-' . $hovId);
        $confirmedBy = $handover['confirmed_by'] ?? $sessionUser['name'];
        
        $structuredNote = "Collected by {$handover['from_name']} via DishNet | @ {$location}\n" .
            "────────────────────────────────\n" .
            "CASH RECEIVED     : \$" . number_format((float)$collection['amount'], 2) . "\n" .
            "CASH RECEIVED BY  : " . $confirmedBy . "\n" .
            "CASH RECEIPT NO   : " . $cbReceipt . "\n" .
            "CASH LOCATION     : " . $location . "\n" .
            "CASHBOOK DATE     : " . date('d-m-Y', strtotime($handover['confirmed_at'] ?? 'now'));
        
        // Try PATCH
        $patchResult = $crm->patch("payments/{$crmPayId}", ['note' => $structuredNote]);
        
        if ($patchResult === null) {
            // Try to get last error
            $lastErr = $crm->getLastError();
            $er2('UCRM PATCH failed: ' . ($lastErr['message'] ?? 'Unknown error') . ' (HTTP ' . ($lastErr['http_code'] ?? '?') . ')', 500);
        }
        
        // Update collection with handover linkage
        $store->updateOne('payment_collections.json', 'id', $colId, [
            'handover_id'      => $hovId,
            'handover_receipt' => $cbReceipt,
            'handover_by'      => $confirmedBy,
            'handover_at'      => date('Y-m-d H:i:s'),
            'ucrm_note_patched' => true,
        ]);
        
        logActivity($dataDir, 'ucrm_payment_patched', 
            "Manual UCRM note patch: Collection #{$colId}",
            "CRM Payment #{$crmPayId} linked to Handover #{$hovId} by {$sessionUser['name']}"
        );
        
        $ok2([
            'patched'       => true,
            'collection_id' => $colId,
            'crm_payment_id' => $crmPayId,
            'handover_id'   => $hovId,
            'receipt'       => $cbReceipt,
        ], 'UCRM payment note updated and collection linked to handover.');
    }

    // ─── MANUAL: Link collection to handover (without UCRM patch) ───────────────
    // POST ?page=api&action=link_collection_handover
    // Body: { "collection_id": 123, "handover_id": 45 }
    if ($act === 'link_collection_handover' && $met === 'POST') {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $sessionUser = $_SESSION['admin'] ?? null;
        if (!$sessionUser || !in_array($sessionUser['role'] ?? '', ['admin', 'accounts', 'ceo'])) {
            $er2('Admin only', 403);
        }
        
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $colId = (int)($body['collection_id'] ?? 0);
        $hovId = (int)($body['handover_id'] ?? 0);
        
        if (!$colId) $er2('collection_id required', 400);
        if (!$hovId) $er2('handover_id required', 400);
        
        $collection = $store->findOne('payment_collections.json', 'id', $colId);
        if (!$collection) $er2('Collection not found', 404);
        
        $allHov = $store->load('cash_handovers.json') ?? [];
        $handover = null;
        foreach ($allHov as $h) {
            if ((int)($h['id'] ?? 0) === $hovId) { $handover = $h; break; }
        }
        if (!$handover) $er2('Handover not found', 404);
        
        $cbReceipt = $handover['cashbook_receipt'] ?? ('HOV-' . $hovId);
        $confirmedBy = $handover['confirmed_by'] ?? $sessionUser['name'];
        
        $store->updateOne('payment_collections.json', 'id', $colId, [
            'handover_id'      => $hovId,
            'handover_receipt' => $cbReceipt,
            'handover_by'      => $confirmedBy,
            'handover_at'      => date('Y-m-d H:i:s'),
        ]);
        
        logActivity($dataDir, 'collection_linked', 
            "Collection #{$colId} manually linked to Handover #{$hovId}",
            "By {$sessionUser['name']}"
        );
        
        $ok2([
            'linked'        => true,
            'collection_id' => $colId,
            'handover_id'   => $hovId,
            'receipt'       => $cbReceipt,
        ], 'Collection linked to handover.');
    }

    // ─── GET VOIDABLE PAYMENTS: List recent collections that can be voided ────
    // GET fix_staff_ledger_collections_backfill — backfill missing collections into staff_ledger
    if ($act === 'fix_staff_ledger_collections_backfill') {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_su = $_SESSION['kyc_retailer'] ?? null;
        if (!$_su || !in_array($_su['role'] ?? '', ['admin','accountant','ceo'], true)) {
            $er2('Admin only.', 403);
        }
        require_once dirname(__DIR__, 2) . '/fix_staff_ledger_collections_backfill.php';
        return;
    }

    // GET ?page=api&action=hq_debug_collections&agent_id=X — debug balance for an agent
    if ($act === 'hq_debug_collections') {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_hqSess = $_SESSION['kyc_retailer'] ?? null;
        $_hqUser = $_hqSess['cached_record'] ?? $_hqSess ?? [];
        if (empty($_hqUser['is_admin']) && !in_array($_hqUser['role'] ?? '', ['accountant', 'admin'], true)) {
            $er2('Admin only.', 403);
        }
        $agentId = (int)($_GET['agent_id'] ?? 0);
        if (!$agentId) $er2('agent_id required.', 422);
        $agent = $store->findOne('retailers.json', 'id', $agentId);
        $allCols = $store->load('payment_collections.json') ?? [];
        $allHovs = $store->load('cash_handovers.json') ?? [];
        // Collections for this agent
        $myCols = array_values(array_filter($allCols, fn($c) => (int)($c['retailer_id']??0) === $agentId && ($c['status']??'')!=='voided'));
        // Confirmed handovers
        $myHovs = array_values(array_filter($allHovs, fn($h) => (int)($h['from_id']??0) === $agentId && ($h['status']??'')==='confirmed'));
        $colTotal = round(array_sum(array_column($myCols,'amount')),2);
        $hovTotal = round(array_sum(array_column($myHovs,'amount')),2);
        // Recent collections not matching this agent
        $unmatched = array_values(array_filter($allCols, function($c) use($agentId,$agent) {
            $name = strtolower($agent['name']??'');
            $desc = strtolower($c['customer_name']??''.($c['description']??''));
            return (int)($c['retailer_id']??0) !== $agentId
                && ($name && str_contains($desc, explode(' ',$name)[0]??''));
        }));
        $ok2([
            'agent'            => $agent['name']??'Unknown',
            'agent_id'         => $agentId,
            'collections_count'=> count($myCols),
            'collections_total'=> $colTotal,
            'handovers_count'  => count($myHovs),
            'handovers_total'  => $hovTotal,
            'computed_balance' => round($colTotal - $hovTotal, 2),
            'recent_collections'=> array_slice($myCols, -5),
            'recent_handovers' => array_slice($myHovs, -5),
            'possibly_unmatched'=> array_slice($unmatched, 0, 5),
        ]);
        return;
    }

    // GET ?page=api&action=voidable_payments&retailer_id=5
    if ($act === 'voidable_payments' && $met === 'GET') {
        $filterRetailer = (int)($_GET['retailer_id'] ?? 0);
        $hoursBack = (int)($_GET['hours'] ?? 72);
        $since = date('Y-m-d H:i:s', strtotime("-{$hoursBack} hours"));
        
        $allCols = $store->load('payment_collections.json') ?? [];
        $voidable = [];
        
        foreach ($allCols as $col) {
            // Skip already voided
            if (($col['status'] ?? '') === 'voided') continue;
            
            // Skip old collections
            if (($col['created_at'] ?? '') < $since) continue;
            
            // Filter by retailer if specified
            if ($filterRetailer > 0 && ($col['retailer_id'] ?? 0) != $filterRetailer) continue;
            
            $voidable[] = [
                'id'              => $col['id'],
                'retailer_id'     => $col['retailer_id'] ?? 0,
                'retailer_name'   => $col['retailer_name'] ?? '',
                'customer_name'   => $col['customer_name'] ?? '',
                'crm_customer_id' => $col['crm_customer_id'] ?? '',
                'amount'          => $col['amount'] ?? 0,
                'method'          => $col['method'] ?? '',
                'crm_synced'      => $col['crm_synced'] ?? false,
                'crm_payment_id'  => $col['crm_payment_id'] ?? null,
                'created_at'      => $col['created_at'] ?? '',
                'note'            => $col['note'] ?? '',
            ];
        }
        
        // Sort by created_at desc
        usort($voidable, function($a, $b) {
            return strcmp($b['created_at'], $a['created_at']);
        });
        
        $ok2([
            'count'    => count($voidable),
            'hours'    => $hoursBack,
            'payments' => $voidable,
        ]);
    }

    // ─── DIAGNOSTIC: Check for duplicate payments in UCRM ─────────────────────
    // GET ?page=api&action=check_duplicate_payments&client_id=123&days=30
    if ($act === 'check_duplicate_payments' && $met === 'GET') {
        require_once dirname(__DIR__, 2) . '/lib/CrmApiClient.php';
        $crm = CrmApiClient::fromUcrm(dirname(__DIR__), $config);
        
        if (!$crm->isConfigured()) {
            $er2('CRM not configured');
        }
        
        $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
        $days     = min(90, max(1, (int)($_GET['days'] ?? 30)));
        $since    = date('Y-m-d', strtotime("-{$days} days"));
        
        // Fetch payments
        $endpoint = $clientId > 0 
            ? "payments?clientId={$clientId}&createdDateFrom={$since}"
            : "payments?createdDateFrom={$since}&limit=500";
        
        $payments = $crm->get($endpoint);
        
        if (!is_array($payments)) {
            $er2('Failed to fetch payments: ' . json_encode($crm->getLastError()));
        }
        
        // Group by client + amount + date to find duplicates
        $grouped = [];
        foreach ($payments as $p) {
            $pClientId = $p['clientId'] ?? 0;
            $pAmount   = (float)($p['amount'] ?? 0);
            $pDate     = substr($p['createdDate'] ?? '', 0, 10);
            $pNote     = $p['note'] ?? '';
            
            // Key: client + amount + date
            $key = "{$pClientId}|{$pAmount}|{$pDate}";
            
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = [
                'id'          => $p['id'],
                'clientId'    => $pClientId,
                'clientName'  => $p['clientFirstName'] ?? '' . ' ' . ($p['clientLastName'] ?? ''),
                'amount'      => $pAmount,
                'date'        => $pDate,
                'note'        => $pNote,
                'createdDate' => $p['createdDate'] ?? '',
            ];
        }
        
        // Find groups with more than 1 payment (duplicates)
        $duplicates = [];
        foreach ($grouped as $key => $group) {
            if (count($group) > 1) {
                $duplicates[] = [
                    'key'      => $key,
                    'count'    => count($group),
                    'payments' => $group,
                ];
            }
        }
        
        // Sort by count (most duplicates first)
        usort($duplicates, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        $ok2([
            'period'           => "Last {$days} days (since {$since})",
            'client_filter'    => $clientId > 0 ? $clientId : 'all',
            'total_payments'   => count($payments),
            'duplicate_groups' => count($duplicates),
            'duplicates'       => $duplicates,
            'hint'             => 'Duplicate groups show payments with same client + amount + date. Review notes to identify true duplicates.',
        ]);
    }

    // ── Issue Credit Note + optional Cash Refund ────────────────────────────
    // POST ?page=api&action=issue_credit_note
    // Body: { client_id, amount, reason, refund_type, invoice_id }
    // refund_type: 'credit_note' = UCRM credit only (no physical cash)
    //              'cash_refund' = cash given back to customer + UCRM credit note
    //
    // Cash refund flow:
    //   1. Check staff has enough USD cash in hand (staff_ledger)
    //   2. Debit staff_ledger (cash leaves their bag)
    //   3. Post OUT to cb_ledger (cashbook: Customer Refund)
    //   4. Create UCRM credit note
    //   5. Send WA to customer
    if ($act === 'issue_credit_note' && $met === 'POST') {
        $retailer   = $auth->requireLogin();
        $body       = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $clientId   = (int)($body['client_id']  ?? 0);
        $amount     = round((float)($body['amount'] ?? 0), 2);
        $reason     = trim($body['reason'] ?? '');
        $refundType = trim($body['refund_type'] ?? 'credit_note');
        $invoiceId  = (int)($body['invoice_id'] ?? 0);
        $staffId    = (int)($retailer['id'] ?? 0);
        $staffName  = $retailer['name'] ?? '';

        if (!$clientId)  $er2('client_id required', 400);
        if ($amount <= 0) $er2('amount must be > 0', 400);
        if (!$reason)    $er2('reason required', 400);
        if (!in_array($refundType, ['credit_note','cash_refund'], true)) $refundType = 'credit_note';

        $_cnRole    = $retailer['role'] ?? '';
        $_cnAllowed = in_array($_cnRole, [
            'sales','sales_staff','support','support_leader','accountant','field_accountant'
        ], true) || !empty($retailer['is_admin']);
        if (!$_cnAllowed) $er2('Permission denied', 403);
        if (!$crm->isConfigured()) $er2('CRM not configured', 503);

        // ── Step 1: Validate cash balance (cash_refund only) ─────────────────
        $cashBefore = 0.0;
        if ($refundType === 'cash_refund') {
            require_once dirname(__DIR__, 2) . '/lib/DualReadCashPosition.php';
            $_rfPos    = (new DualReadCashPosition($store, $store->getPdo(), $dataDir))->getPosition($staffId);
            $cashBefore = round((float)($_rfPos['cash_exposure'] ?? 0), 2);
            if ($cashBefore < $amount - 0.01) {
                $er2(
                    "Insufficient cash in hand. You have \${$cashBefore}, refund needs \${$amount}. "
                    . "Use 'Credit Note Only' if you are not giving physical cash.",
                    422
                );
            }
        }

        // ── Step 2: Fetch customer ────────────────────────────────────────────
        $_cnClient = $crm->get("clients/{$clientId}") ?? [];
        $_cnName   = trim(($_cnClient['firstName'] ?? '') . ' ' . ($_cnClient['lastName'] ?? ''))
                   ?: ($_cnClient['companyName'] ?? 'Client #' . $clientId);
        $_cnPhone  = '';
        foreach (($_cnClient['contacts'] ?? []) as $_cc) {
            if (!empty($_cc['phone'])) { $_cnPhone = $_cc['phone']; break; }
        }

        // ── Step 3: Create UCRM credit note ──────────────────────────────────
        $_cnPayload = [
            'clientId' => $clientId,
            'items'    => [['label' => $reason, 'quantity' => 1, 'price' => $amount, 'tax1Id' => null]],
            'note'     => ($refundType === 'cash_refund' ? 'CASH REFUND — ' : 'CREDIT NOTE — ')
                        . "by {$staffName} — {$reason}",
        ];
        if ($invoiceId > 0) $_cnPayload['invoiceId'] = $invoiceId;

        $cnResult = $crm->post('billing/credit-notes', $_cnPayload);
        if (empty($cnResult['id'])) $cnResult = $crm->post('billing/credit-notes/add', $_cnPayload);
        if (empty($cnResult['id'])) $er2('UCRM credit note failed: ' . json_encode($cnResult ?? 'null'), 502);

        $cnId  = (int)$cnResult['id'];
        $cnNum = (string)($cnResult['number'] ?? $cnId);
        $cbSr  = '';

        // ── Step 4: Debit staff bag + cashbook (cash_refund only) ─────────────
        if ($refundType === 'cash_refund') {
            require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
            require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';

            // Debit staff_ledger
            (new StaffLedgerService($store->getPdo()))->record([
                'staff_id'          => $staffId,
                'staff_name'        => $staffName,
                'direction'         => 'out',
                'currency'          => 'USD',
                'amount'            => $amount,
                'ssp_amount'        => 0,
                'category'          => 'expense',
                'subcategory'       => 'customer_refund',
                'description'       => "Cash refund to {$_cnName} (CRM #{$clientId}) — {$reason} — CN #{$cnNum}",
                'status'            => 'active',
                'source_type'       => 'credit_notes',
                'source_id'         => (string)$cnId,
                'idempotency_key'   => 'REFUND-CN-' . $cnId,
                'counterparty_id'   => $clientId,
                'counterparty_name' => $_cnName,
                'event_date'        => date('Y-m-d'),
            ]);

            // Post to cashbook
            $cb   = new CashbookService($store, $dataDir);
            $cbSr = $cb->addEntryRaw([
                'project'           => 'dishnet',
                'date'              => date('Y-m-d'),
                'direction'         => 'out',
                'amount'            => $amount,
                'currency'          => 'USD',
                'category'          => 'Customer Refund',
                'category_raw'      => 'Customer Refund',
                'person'            => $_cnName,
                'description'       => "Cash refund to {$_cnName} (CRM #{$clientId}) by {$staffName} — {$reason} — CN #{$cnNum}",
                'validation_ref'    => 'CN-' . $cnNum,
                'validation_status' => 'done',
                'status'            => 'approved',
                'approved_by'       => $staffName,
                'crm_client_id'     => $clientId,
                'source'            => 'staff_refund',
                'created_at'        => date('Y-m-d H:i:s'),
            ]);

            logActivity($dataDir, 'cash_refund_issued',
                "Cash refund \${$amount} to {$_cnName} by {$staffName} — CB:{$cbSr} CN:#{$cnNum}",
                "Cash before: \${$cashBefore} | After: \$" . round($cashBefore - $amount, 2)
            );
        }

        // ── Step 5: WhatsApp to customer ──────────────────────────────────────
        $waLabel   = $refundType === 'cash_refund' ? 'Cash Refund' : 'Credit Note';
        $waDetail  = $refundType === 'cash_refund'
            ? "Cash of *\${$amount}* has been returned to you."
            : "A credit of *\${$amount}* has been applied to your account and will offset your next invoice.";
        try {
            if ($_cnPhone) {
                if (!isset($notify)) $notify = svc('notify');
                $notify->sendVia('accounts', $_cnPhone,
                    "💳 *{$waLabel} — DishNet Africa*\n\n"
                    . "Dear *{$_cnName}*,\n\n"
                    . $waDetail . "\n"
                    . "Reference: *#{$cnNum}*\n"
                    . "Reason: {$reason}\n"
                    . ($cbSr ? "Receipt: {$cbSr}\n" : '')
                    . "Processed by: {$staffName}\n"
                    . "Date: " . date('d M Y H:i') . "\n\n"
                    . "— _DishNet Africa_",
                    'customer_refund'
                );
            }
        } catch (\Throwable $_waErr) { /* non-fatal */ }

        logActivity($dataDir, 'credit_note_issued', "CN #{$cnNum} \${$amount} ({$refundType}) for {$_cnName} by {$staffName}", $reason);

        $ok2([
            'credit_note_id'  => $cnId,
            'credit_note_num' => $cnNum,
            'refund_type'     => $refundType,
            'client_id'       => $clientId,
            'client_name'     => $_cnName,
            'amount'          => $amount,
            'cashbook_ref'    => $cbSr ?: null,
            'cash_before'     => $refundType === 'cash_refund' ? $cashBefore : null,
            'cash_after'      => $refundType === 'cash_refund' ? round($cashBefore - $amount, 2) : null,
            'issued_by'       => $staffName,
            'wa_sent'         => !empty($_cnPhone),
        ], "{$waLabel} #{$cnNum} (\${$amount}) for {$_cnName}" .
           ($refundType === 'cash_refund' ? " — cash: \${$cashBefore} → \$" . round($cashBefore-$amount,2) : ''));
    }

    // ── Void personal-pay CIN entries from staff_ledger ──────────────────────
    // GET  ?page=api&action=void_salary_cashin&staff_id=X   → dry run
    // GET  ?page=api&action=void_salary_cashin&staff_id=X&apply=1 → apply
    // Finds CIN-* IN entries in staff_ledger where the description contains
    // salary/allowance keywords — these are personal pay, not field cash,
    // and should not appear as "USD in hand" on the staff's cash position.
    if ($act === 'void_salary_cashin') {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_vsSess = $_SESSION['kyc_retailer'] ?? null;
        $_vsUser = $_vsSess['cached_record'] ?? $_vsSess ?? [];
        if (empty($_vsUser['is_admin'])) $er2('Admin login required', 403);

        $staffId = (int)($_GET['staff_id'] ?? 0);
        if (!$staffId) $er2('staff_id required', 400);
        $apply   = !empty($_GET['apply']);
        $_vsPdo  = $store->getPdo();

        // Personal pay keywords (same as StaffLedgerWriter)
        $personalKeywords = ['salary', 'transport allowance', 'food allowance', 'bonus', 'employee benefit'];

        // Find all active CIN-* USD IN entries for this staff
        $rows = $_vsPdo->prepare(
            "SELECT id, amount, ssp_amount, description, idempotency_key, event_date, status
             FROM staff_ledger
             WHERE staff_id = ? AND direction = 'in' AND currency = 'USD'
               AND idempotency_key LIKE 'CIN-%'
               AND status NOT IN ('voided','cancelled')
             ORDER BY event_date ASC"
        );
        $rows->execute([$staffId]);
        $allCins = $rows->fetchAll(PDO::FETCH_ASSOC);

        $toVoid = []; $toKeep = [];
        foreach ($allCins as $row) {
            $desc = strtolower($row['description'] ?? '');
            $isPersonal = false;
            foreach ($personalKeywords as $kw) {
                if (strpos($desc, $kw) !== false) { $isPersonal = true; break; }
            }
            if ($isPersonal) {
                $toVoid[] = $row;
            } else {
                $toKeep[] = $row;
            }
        }

        $voided = 0;
        if ($apply) {
            foreach ($toVoid as $row) {
                $_vsPdo->prepare(
                    "UPDATE staff_ledger SET status='voided', voided_by='void_salary_cashin',
                     voided_at=datetime('now'),
                     void_reason='Personal pay (salary/allowance) — not field cash, excluded from bag',
                     updated_at=datetime('now') WHERE id=?"
                )->execute([$row['id']]);
                $voided++;
            }
        }

        // Get balance before and after
        $bal = (float)$_vsPdo->query(
            "SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0)
                  - COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0)
             FROM staff_ledger WHERE staff_id={$staffId} AND currency='USD'
               AND status NOT IN ('voided','cancelled')"
        )->fetchColumn();

        // Staff name
        $allR = $store->load('retailers.json') ?? [];
        $staffName = 'Staff #' . $staffId;
        foreach ($allR as $r) { if ((int)$r['id'] === $staffId) { $staffName = $r['name']; break; } }

        $ok2([
            'staff_id'       => $staffId,
            'staff_name'     => $staffName,
            'mode'           => $apply ? 'APPLIED' : 'DRY_RUN — add &apply=1 to fix',
            'personal_entries_found' => count($toVoid),
            'field_entries_kept'     => count($toKeep),
            'to_void'        => array_map(fn($r) => [
                'id'  => $r['id'], 'key' => $r['idempotency_key'],
                'amt' => $r['amount'], 'date' => $r['event_date'],
                'desc'=> substr($r['description'], 0, 80)
            ], $toVoid),
            'voided'         => $voided,
            'usd_balance_now'=> round($bal, 2),
            'message'        => $apply
                ? "Voided {$voided} personal-pay entries. {$staffName} USD bag now: \${$bal}"
                : count($toVoid) . " personal-pay CIN entries will be voided. Balance will change by -\$" . array_sum(array_column($toVoid, 'amount')),
        ]);
    }
