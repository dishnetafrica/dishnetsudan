<?php
// ═══════════════════════════════════════════════════════════════
// SALES (collections, recharges, wallet)
// ═══════════════════════════════════════════════════════════════

require_once dirname(__DIR__, 2) . '/lib/PaymentUuids.php';

// ── Approve / Reject large pending collection ────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'', ['approve_large_collection','reject_large_collection'])) {
    $admin = $auth->requireAdmin();
    $pcId  = (int)($_POST['pending_collection_id'] ?? 0);
    $pCols = $store->load('pending_collections.json') ?? [];
    $pc    = null; $pcIdx = null;
    foreach ($pCols as $i => $p) {
        if ((int)($p['id']??0) === $pcId) { $pc = $p; $pcIdx = $i; break; }
    }
    if (!$pc) { flash('Pending collection not found.', 'danger'); redirect('?page=dashboard&tab=wallet_admin'); }

    if (($_POST['action']??'') === 'reject_large_collection') {
        $pCols[$pcIdx]['status']      = 'rejected';
        $pCols[$pcIdx]['reviewed_by'] = $admin['name'];
        $pCols[$pcIdx]['reviewed_at'] = date('Y-m-d H:i:s');
        $pCols[$pcIdx]['reject_note'] = trim($_POST['reject_note'] ?? 'Rejected by admin');
        $store->save('pending_collections.json', $pCols);
        logActivity($dataDir,'large_txn_rejected','Large payment rejected',
            '$'.number_format($pc['amount'],2).' for '.($pc['customer_name']??'').' by '.$admin['name']);
        flash('Payment rejected.', 'success');
        redirect('?page=dashboard&tab=wallet_admin');
    }

    // APPROVE — run the actual debit
    $rid = (int)$pc['retailer_id']; $amount = (float)$pc['amount'];
    $custName = $pc['customer_name']??''; $custId = $pc['crm_customer_id']??'';
    $method = $pc['method']??'Cash'; $svcType = $pc['service_type']??'starlink';
    $note = ($pc['note']??'').' [Admin approved: '.$admin['name'].']';
    $invoiceId = $pc['invoice_id']??'';
    $retailerObj = $store->findOne('retailers.json','id',$rid);
    $balanceBefore = $wallet->getBalance($rid);
    if ($balanceBefore < $amount) { flash('Agent wallet insufficient.', 'danger'); redirect('?page=dashboard&tab=wallet_admin'); }
    $idemKey  = 'PAY-APR-'.$rid.'-'.md5($custId.'-'.$amount.'-'.date('Y-m-d').'-'.$pcId);
    $debitTrx = $wallet->debit($rid,$amount,"Approved collection: {$custName} ({$custId})",
                               $retailerObj['name']??'Agent',null,$custId,$idemKey,'order_payment',$admin['name']);
    $balanceAfter = $debitTrx['curr_balance']??($balanceBefore-$amount);
    // Commission — only for external agents, not employees
    $_isEmp = !empty($retailerObj['is_employee']);
    $commRate = 0;
    if (!$_isEmp && !empty($config['commission_on_collection'])) {
        $_rCommType = $retailerObj['commission_type'] ?? 'none';
        $_rCommRate = (float)($retailerObj['commission_rate'] ?? 0);
        if ($_rCommType !== 'none' && $_rCommRate > 0) {
            $commRate = $_rCommRate;
        } else {
            $commRate = (float)($svcType==='fiber'?($config['fiber_commission_rate']??$config['commission_rate']??5):($config['starlink_commission_rate']??$config['commission_rate']??5));
        }
    }
    $commAmount = 0;
    if ($commRate>0){
        $commAmount = round($amount*$commRate/100,2);
        $wallet->credit($rid,$commAmount,"Commission {$commRate}% on {$svcType}: {$custName}",'System','COMM-APR-'.$pcId,'commission');
    }
    $crmSuccess=false; $crmResult=null;
    if ($crm->isConfigured()&&$custId){
        $crmForPayment = !empty($retailerObj['ucrm_app_key'])
            ? new CrmApiClient(rtrim($crm->getBaseUrl(),'/'), $retailerObj['ucrm_app_key'], 'X-Auth-App-Key')
            : $crm;
        $crmPayload=['clientId'=>(int)$custId,'methodId' => PaymentUuids::resolve($method),'amount'=>$amount,'note'=>"Collected by ".($retailerObj['name']??'')." (admin-approved)",'currencyCode'=>'USD'];
        $crmPayload['applyToInvoicesAutomatically']=true;
        $crmResult=$crmForPayment->post('payments',$crmPayload); $crmSuccess=!empty($crmResult)&&isset($crmResult['id']);
    }
    $collection=$store->appendWithId('payment_collections.json',[
        'retailer_id'=>$rid,'retailer_name'=>$retailerObj['name']??'','customer_name'=>$custName,
        'invoice_id'=>$invoiceId,'crm_customer_id'=>$custId,'amount'=>$amount,'method'=>$method,
        'service_type'=>$svcType,'note'=>$note,'commission'=>$commAmount,'comm_rate'=>$commRate,
        'crm_synced'=>$crmSuccess,'crm_payment_id'=>$crmResult['id']??null,
        'balance_before'=>round($balanceBefore,2),'balance_after'=>round($balanceAfter,2),
        'approved_by'=>$admin['name'],'was_large_txn'=>true,'created_at'=>date('Y-m-d H:i:s'),
    ]);
    // Dual-write: staff_ledger
    require_once dirname(__DIR__) . '/lib/StaffLedgerWriter.php';
    StaffLedgerWriter::onCollection($store->getPdo(), array_merge($collection, ['client_name'=>$custName,'collected_at'=>date('Y-m-d H:i:s')]));
    $store->appendWithId('activity_log.json',['event'=>'large_txn_approved','actor'=>$admin['name'],'action'=>'APPROVE+DEBIT',
        'customer'=>$custName,'amount'=>$amount,'balance_before'=>round($balanceBefore,2),'balance_after'=>round($balanceAfter,2),
        'detail'=>'Admin approved $'.number_format($amount,2).' from '.$custName.' | $'.number_format($balanceBefore,2).' -> $'.number_format($balanceAfter,2),
        'created_at'=>date('Y-m-d H:i:s')]);
    logActivity($dataDir,'large_txn_approved','Large payment approved','$'.number_format($amount,2).' for '.$custName.' by '.$admin['name']);
    $pCols[$pcIdx]['status']='approved'; $pCols[$pcIdx]['reviewed_by']=$admin['name'];
    $pCols[$pcIdx]['reviewed_at']=date('Y-m-d H:i:s'); $pCols[$pcIdx]['collection_id']=$collection['id'];
    $store->save('pending_collections.json',$pCols);

    // ── WhatsApp payment receipt to customer (Accounts number) ───────────────
    // Uses dedupMark() so webhook.php can never double-send after this fires.
    // Also queues receipt PDF for cron pickup.
    try {
        $custPhone = _crmCustomerPhone($custId, $crm, $store);
        if ($custPhone) {
            $crmPayId = ($crmSuccess && !empty($crmResult['id'])) ? (int)$crmResult['id'] : 0;
            $txnRef   = $crmPayId ? 'PAY-' . $crmPayId : 'COL-' . ($collection['id'] ?? '');
            $dedupKey = $crmPayId ? 'PAY' . $crmPayId : 'COL' . ($collection['id'] ?? uniqid());

            if ($notify->dedupMark($dedupKey)) {
                $notify->paymentReceived($custPhone, $custName, $amount, $txnRef);

                if ($crmPayId > 0) {
                    $receiptQueue = $store->load('receipt_pdf_queue.json') ?? [];
                    $_alreadyQueued = false;
                    foreach ($receiptQueue as $_rqi) {
                        if ((int)($_rqi['payment_id'] ?? 0) === $crmPayId) { $_alreadyQueued = true; break; }
                    }
                    if (!$_alreadyQueued) {
                        $receiptQueue[] = [
                            'payment_id'    => $crmPayId,
                            'phone'         => $custPhone,
                            'customer_name' => $custName,
                            'amount'        => $amount,
                            'queued_at'     => time(),
                            'sent'          => false,
                            'source'        => 'post_sales',
                        ];
                        $store->save('receipt_pdf_queue.json', array_values($receiptQueue));
                    }
                }
            }
        }
    } catch (\Throwable $waErr) { /* non-fatal */ }

    flash('Payment of $'.number_format($amount,2).' approved and processed.','success');
    redirect('?page=dashboard&tab=wallet_admin');
}

if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='approve_recharge'){
    $admin  = $auth->requireAdmin();
    $reqId  = (int)($_POST['request_id'] ?? 0);
    $req    = $store->findOne('wallet_recharge_requests.json', 'id', $reqId);
    $result = $recharge->approve($reqId, $admin);

    if ($result['success'] && $req) {
        $retailer   = $store->findOne('retailers.json', 'id', (int)($req['retailer_id'] ?? 0));
        $newBalance = $wallet->getBalance((int)($req['retailer_id'] ?? 0));

        // ── Step 1: Save invoice intent locally BEFORE touching UCRM ──────────
        // Even if UCRM is down, the intent is persisted and the cron will retry.
        $store->updateOne('wallet_recharge_requests.json', 'id', $reqId, [
            'invoice_status'      => 'pending',
            'invoice_amount'      => (float)($req['amount'] ?? 0),
            'invoice_note'        => trim($req['note'] ?? ''),
            'invoice_approved_by' => $admin['name'],
            'invoice_queued_at'   => date('Y-m-d H:i:s'),
        ]);

        $crmInvoice    = null;
        $crmInvoiceErr = null;
        $crmClientId   = null;

        if ($retailer && $crm->isConfigured()) {

            // ── Step 2: Auto-find or create Org-7 CRM client (no manual linking needed)
            // ensureRetailerClient() searches by email, creates if not found,
            // stores ftth_crm_client_id back on the retailer record automatically.
            $crmClientId   = $ftthCrm->ensureRetailerClient($retailer);
            $freshRetailer = $store->findOne('retailers.json', 'id', (int)($req['retailer_id'] ?? 0)) ?? $retailer;

            // ── Step 3: Sync wallet balance to CRM client attribute
            // I-01 FIX: Pass $crmClientId to skip the redundant ensureRetailerClient() lookup
            if ($crmClientId) $ftthCrm->syncWalletBalance($freshRetailer, $newBalance, $crmClientId);

            // ── Step 4: Create invoice in UCRM Org-7
            // I-01 FIX: Pass $crmClientId here too — avoids a 3rd CRM GET /clients lookup
            $topupNote  = trim($req['note'] ?? '') ?: 'Wallet top-up — approved by ' . $admin['name'];
            $crmInvoice = $ftthCrm->createTopupInvoice(
                $freshRetailer,
                (float)($req['amount'] ?? 0),
                $topupNote,
                $admin['name'],
                $crmClientId
            );

            if ($crmInvoice && !empty($crmInvoice['id'])) {
                // ✅ Invoice created — update local record with CRM reference
                $store->updateOne('wallet_recharge_requests.json', 'id', $reqId, [
                    'invoice_status'      => 'done',
                    'crm_invoice_id'      => (int)$crmInvoice['id'],
                    'crm_invoice_number'  => $crmInvoice['number'] ?? null,
                    'crm_invoice_synced'  => true,
                    'crm_invoice_at'      => date('Y-m-d H:i:s'),
                    'crm_client_id_used'  => $crmClientId,
                ]);
            } else {
                // ❌ Invoice failed — mark for cron retry (wallet is already credited)
                $crmInvoiceErr = 'UCRM invoice creation failed — will auto-retry via cron.';
                $store->updateOne('wallet_recharge_requests.json', 'id', $reqId, [
                    'invoice_status'      => 'failed',
                    'crm_invoice_synced'  => false,
                    'crm_invoice_err'     => $crmInvoiceErr,
                    'crm_invoice_retry'   => 0,
                    'crm_client_id_used'  => $crmClientId,
                ]);
                error_log("Recharge #{$reqId}: UCRM invoice failed for {$retailer['name']} (CRM client {$crmClientId})");
            }
        } else {
            $crmInvoiceErr = $crm->isConfigured()
                ? 'Retailer record not found — invoice skipped.'
                : 'CRM not configured — invoice skipped.';
            $store->updateOne('wallet_recharge_requests.json', 'id', $reqId, [
                'invoice_status'  => 'failed',
                'crm_invoice_err' => $crmInvoiceErr,
            ]);
        }

        // ── Step 5: Notify BOTH parties via WhatsApp ──────────────────────────
        if ($retailer) {
            // 5a. Retailer — their wallet was topped up
            $notify->rechargeApproved($retailer, (float)($req['amount'] ?? 0), $newBalance, $admin['name'], $crmInvoice['number'] ?? '');

            // 5b. Approver (admin) — confirmation on their phone
            $invoiceRef = !empty($crmInvoice['number'])
                ? ' | Invoice: ' . $crmInvoice['number']
                : ($crmInvoiceErr ? ' | ⚠ Invoice failed — cron will retry' : '');
            $notify->sendAdmin(
                "✅ *Recharge Approved*\n"
                . "Retailer: {$retailer['name']}\n"
                . "Amount: \$" . number_format((float)($req['amount'] ?? 0), 2) . "\n"
                . "New Balance: \$" . number_format($newBalance, 2) . "\n"
                . "Approved by: {$admin['name']}"
                . $invoiceRef,
                'ops_recharge_approved_admin'
            );
        }

        $flashMsg = $result['message'];
        if (!empty($crmInvoice['number'])) $flashMsg .= ' ✅ Invoice ' . $crmInvoice['number'] . ' created in UCRM.';
        elseif ($crmInvoiceErr)            $flashMsg .= ' ⚠ ' . $crmInvoiceErr;
        flash($flashMsg, $crmInvoiceErr ? 'warning' : 'success');
        redirect('?page=dashboard&tab=recharge_requests');
    }

    flash($result['message'], $result['success'] ? 'success' : 'danger');
    redirect('?page=dashboard&tab=recharge_requests');
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='reject_recharge'){
    $admin=$auth->requireAdmin();
    $reqId=(int)($_POST['request_id']??0);
    $reason=trim($_POST['rejection_reason']??'');
    $req = $store->findOne('wallet_recharge_requests.json','id',$reqId);
    $result=$recharge->reject($reqId,$reason,$admin);
    if($result['success'] && $req){
        $retailer = $store->findOne('retailers.json','id',(int)($req['retailer_id']??0));
        if($retailer) $notify->rechargeRejected($retailer,(float)($req['amount']??0),$reason);
    }
    flash($result['message'],$result['success']?'success':'danger');
    redirect('?page=dashboard&tab=recharge_requests');
}
