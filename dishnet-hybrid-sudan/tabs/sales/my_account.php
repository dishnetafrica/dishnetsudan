<?php
// Tab: my_account — "My Cash" — Merged from My Account + Field Register + Field Expenses
// Single screen for staff cash management. PHP 7.4 compatible.
// Not for accountant/admin — they use Staff Cash Control instead.

$_mcBlockedRoles = ['accountant'];
if (in_array($userRole ?? '', $_mcBlockedRoles) && !($retailer['is_admin'] ?? false)) {
    echo '<div style="padding:40px;text-align:center;color:#64748b;">';
    echo '<div style="font-size:40px;margin-bottom:12px;">👁️</div>';
    echo '<div style="font-size:14px;font-weight:700;margin-bottom:8px;">This tab is for field staff only</div>';
    echo '<div style="font-size:12px;color:#94a3b8;margin-bottom:16px;">As accountant, use Staff Cash Control to see all staff positions.</div>';
    echo '<a href="?page=dashboard&tab=staff_cash_control" style="background:#1e293b;color:#fff;padding:10px 24px;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;">Go to Staff Cash Control →</a>';
    echo '</div>';
    return;
}

// ── Services ──
require_once __DIR__ . '/../../lib/SnapshotService.php';
require_once __DIR__ . '/../../lib/ExpenseAdvanceService.php';
$_snap  = new SnapshotService($store->getPdo(), $store);
$expAdv = new ExpenseAdvanceService($store, $dataDir);

$agentId   = (int)$retailer['id'];
$agentName = $retailer['name'] ?? 'Agent';
$_mcAllExps = $store->load('cash_expenses.json') ?: [];
$_mcMyExps  = array_filter($_mcAllExps, fn($e) => (int)($e['collector_id'] ?? 0) === $agentId && ($e['status'] ?? '') !== 'voided');

// ── POST handlers (expense + advance) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mc_action'])) {
    $act = $_POST['mc_action'];

    if ($act === 'submit_expense') {
        // ── Balance guard: can't spend what you don't have ──────────────
        $_exCur = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $_exAmt = round((float)($_POST['amount'] ?? 0), 2);
        if ($_exCur === 'SSP') $_exAmt = round((float)($_POST['ssp_amount'] ?? $_POST['amount'] ?? 0), 0);

        // Compute available balance in the requested currency
        $_bgCashIn   = array_filter($store->load('cash_ins.json') ?: [], fn($i) => (int)($i['collector_id'] ?? 0) === $agentId);
        $_bgExpenses = $_mcMyExps;
        $_bgHandovers= array_filter($store->load('cash_handovers.json') ?: [], fn($h) => (int)($h['from_id'] ?? 0) === $agentId);

        if ($_exCur === 'SSP') {
            // v4.12.15 — fix "insufficient balance" false-rejection when SQL
            // staff_ledger is inflated by historical phantoms (same root cause
            // as v4.12.10: the JSON-based calc is authoritative). Francis hit
            // this on 18 Apr — hero showed +60k, guard blocked with −217k.
            // StaffCashPositionService reads JSON sources exactly like the
            // admin Staff Cashbook view and the mobile hero.
            if (!class_exists('StaffCashPositionService')) require_once __DIR__ . '/../../lib/StaffCashPositionService.php';
            $_bgBal = round((new StaffCashPositionService($store, $store->getPdo()))->getSSPBalance($agentId), 0);
            // Subtract PENDING staff_expenses — StaffCashPositionService only counts
            // 'approved' in its SQLite read (line 353), and pending expenses may not
            // yet be dual-written to cash_expenses.json. Approved expenses are
            // already counted by the JSON read, so narrowing to pending avoids
            // double-counting.
            try {
                $_bgUnposted = $store->getPdo()->prepare("SELECT COALESCE(SUM(CASE WHEN ssp_amount>0 THEN ssp_amount ELSE amount END),0) FROM staff_expenses WHERE staff_id=? AND currency='SSP' AND status='pending'");
                $_bgUnposted->execute([$agentId]);
                $_bgBal -= round((float)$_bgUnposted->fetchColumn(), 0);
            } catch (\Throwable $_e) {}
            if ($_exAmt > $_bgBal) {
                flash("Cannot submit " . number_format($_exAmt, 0) . " SSP — you only have " . number_format($_bgBal, 0) . " SSP available.", 'danger');
                redirect('?page=dashboard&tab=my_account&v=expense');
            }
        } else {
            // USD: use DualReadCashPosition
            if (!class_exists('DualReadCashPosition')) require_once __DIR__ . '/../../lib/DualReadCashPosition.php';
            $_bgPos = (new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? ''))->getPosition($agentId);
            $_bgUsd = (float)($_bgPos['cash_exposure'] ?? 0);
            // Negative exposure = company owes you (you have no USD). Zero or positive = you may have cash.
            $_bgAvail = $_bgUsd < 0 ? abs($_bgUsd) : 0;
            // Also check: advances - expenses - handovers for USD
            $_bgUsdIn  = round(array_sum(array_column(array_values(array_filter($_bgCashIn, fn($i) => ($i['currency'] ?? 'USD') === 'USD' && !in_array($i['status'] ?? 'approved', ['rejected','voided']))), 'amount')), 2);
            $_bgUsdExp = round(array_sum(array_column(array_values(array_filter($_bgExpenses, fn($e) => ($e['currency'] ?? 'USD') === 'USD' && in_array($e['status'] ?? '', ['approved','pending']))), 'amount')), 2);
            $_bgUsdHov = round(array_sum(array_column(array_values(array_filter($_bgHandovers, fn($h) => ($h['status'] ?? '') === 'confirmed' && ($h['currency'] ?? 'USD') === 'USD')), 'amount')), 2);
            $_bgUsdBal = max(0, (float)($_bgPos['advance_balance'] ?? 0) + (float)($_bgPos['collections'] ?? 0) + $_bgUsdIn - $_bgUsdExp - $_bgUsdHov);
            if ($_exAmt > 0 && $_bgUsdBal <= 0 && (float)($_bgPos['advance_balance'] ?? 0) <= 0 && (float)($_bgPos['collections'] ?? 0) <= 0 && $_bgUsdIn <= 0) {
                flash("Cannot submit \$" . number_format($_exAmt, 2) . " USD — you have no USD cash. Switch to SSP if you have SSP.", 'danger');
                redirect('?page=dashboard&tab=my_account&v=expense');
            }
        }

        $result = $expAdv->submitExpense($_POST, $_FILES['receipt'] ?? null, $retailer);
        if ($result['ok']) {
            $isAutoApproved = in_array($userRole ?? '', ['accountant','field_accountant']) || ($retailer['is_admin'] ?? false);

            // ── AUTO-LINK: Staff payment auto-approved → create cash_in for receiver ──
            $_staffName = trim($_POST['staff_name'] ?? $_POST['to_staff_name'] ?? '');
            $_isStaffPay = !empty($_staffName);
            $_expCurrency = strtoupper(trim($_POST['currency'] ?? 'USD'));
            $_expAmount = round((float)($_POST['amount'] ?? 0), 2);
            $_expSspAmt = round((float)($_POST['ssp_amount'] ?? $_POST['amount'] ?? 0), 0);
            $_expCategory = trim($_POST['category'] ?? '');
            $_expDesc = trim($_POST['description'] ?? '');

            if ($isAutoApproved && $_isStaffPay) {
                $allRetailers = $store->load('retailers.json') ?? [];
                $_mId = 0; $_mName = ''; $_mPhone = '';
                $_sLow = strtolower($_staffName);
                foreach ($allRetailers as $r) {
                    if (empty($r['is_active'])) continue;
                    $rn = strtolower($r['name'] ?? '');
                    if ($rn === $_sLow) { $_mId=(int)$r['id']; $_mName=$r['name']; $_mPhone=$r['phone']??''; break; }
                    if ($rn && (strpos($rn,$_sLow)!==false||strpos($_sLow,$rn)!==false)) { $_mId=(int)$r['id']; $_mName=$r['name']; $_mPhone=$r['phone']??''; }
                }
                if ($_mId > 0) {
                    $cashIns = $store->load('cash_ins.json') ?? [];
                    $_ciCat = $_expCurrency==='SSP' ? 'SSP Received' : 'USD Received';
                    $_ciDesc = 'From '.$retailer['name'].' — '.$_expCategory;
                    if ($_expDesc) $_ciDesc .= ' ('.substr($_expDesc,0,60).')';
                    $_ciRef = 'MC-STAFF-'.$agentId.'-'.time();
                    $cashIns[] = [
                        'id'=>count($cashIns)+1,'collector_id'=>$_mId,'collector_name'=>$_mName,
                        'amount'=>$_expCurrency==='USD'?$_expAmount:0,'currency'=>$_expCurrency,
                        'ssp_amount'=>$_expCurrency==='SSP'?$_expSspAmt:0,
                        'usd_given'=>0,'rate'=>0,'category'=>$_ciCat,'description'=>$_ciDesc,
                        'status'=>'approved','approved_by'=>'auto (field accountant)',
                        'approved_at'=>date('Y-m-d H:i:s'),'cb_ref'=>$_ciRef,'created_at'=>date('Y-m-d H:i:s'),
                    ];
                    $store->save('cash_ins.json', $cashIns);
                    // Dual-write receiver's cash_in to staff_ledger (idempotent via CIN-{id})
                    try {
                        if (!class_exists('StaffLedgerWriter')) require_once __DIR__ . '/../../lib/StaffLedgerWriter.php';
                        StaffLedgerWriter::onCashIn($store->getPdo(), $cashIns[count($cashIns) - 1]);
                    } catch (\Throwable $_slwErr) {}
                    // WhatsApp to receiver
                    if ($_mPhone) {
                        try {
                            $_lbl = $_expCurrency==='SSP' ? number_format($_expSspAmt,0).' SSP' : '$'.number_format($_expAmount,2);
                            $notify = svc('notify');
                            $notify->sendVia('accounts', $_mPhone,
                                "💰 *Cash Received*\n\nFrom: *{$retailer['name']}*\nAmount: *{$_lbl}*\nFor: {$_expCategory}" . ($_expDesc?" — {$_expDesc}":'') . "\nTime: ".date('d M Y H:i')."\n\nThis is now in your cash balance.\n— DishNet",
                                'staff_cash_transfer');
                        } catch (\Throwable $e) {}
                    }
                }
            }

            flash($isAutoApproved ? 'Cash out recorded and approved.' : 'Cash out submitted. Waiting for approval.', 'success');
        } else {
            flash($result['error'] ?? 'Failed to submit.', 'danger');
        }
        redirect('?page=dashboard&tab=my_account&v=expense');
    }

    if ($act === 'request_advance') {
        $amount  = round((float)($_POST['amount'] ?? 0), 2);
        $purpose = trim($_POST['purpose'] ?? '');
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        if (!in_array($currency, ['USD', 'SSP'])) $currency = 'USD';
        $amtDisplay = $currency === 'SSP' ? number_format($amount) . ' SSP' : '$' . number_format($amount, 2);
        if ($amount > 0 && $purpose) {
            $store->appendWithId('activity_log.json', [
                'event'      => 'advance_request',
                'actor'      => $agentName,
                'action'     => 'REQUEST',
                'detail'     => "Cash advance request: {$amtDisplay} — {$purpose}",
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            try {
                $notify = svc('notify');
                $adminPhone = $config['whatsapp_admin_phone'] ?? '';
                if ($adminPhone) {
                    $notify->sendVia('support', $adminPhone, "Cash Advance Request\n\nFrom: {$agentName}\nAmount: {$amtDisplay}\nPurpose: {$purpose}\n\nApprove in DishNet → Cash Advances tab.", 'cash_advance_request');
                }
            } catch (\Throwable $e) {}
            flash("Advance request for {$amtDisplay} submitted.", 'success');
        } else {
            flash('Enter amount and purpose.', 'danger');
        }
        redirect('?page=dashboard&tab=my_account&v=advance');
    }

    // ── Issue advance to another staff member (field_accountant only) ────────
    if ($act === 'issue_advance' && ($userRole ?? '') === 'field_accountant') {
        $recipientId   = (int)($_POST['recipient_id'] ?? 0);
        $amount        = round((float)($_POST['amount'] ?? 0), 2);
        $purpose       = trim($_POST['purpose'] ?? 'misc');
        $description   = trim($_POST['description'] ?? '');
        $currency      = strtoupper(trim($_POST['currency'] ?? 'USD'));
        if (!in_array($currency, ['USD','SSP'], true)) $currency = 'USD';

        if ($amount <= 0 || !$recipientId) {
            flash('Select a staff member and enter an amount.', 'danger');
            redirect('?page=dashboard&tab=my_account&v=issue_advance');
        }

        // Find recipient name
        $allStaff  = $store->load('retailers.json') ?? [];
        $recipient = null;
        foreach ($allStaff as $r) {
            if ((int)($r['id'] ?? 0) === $recipientId) { $recipient = $r; break; }
        }
        if (!$recipient) {
            flash('Staff member not found.', 'danger');
            redirect('?page=dashboard&tab=my_account&v=issue_advance');
        }

        // Link to Diko's own active advance as parent (child split = no extra cashbook entry)
        $myAdvances = $expAdv->getAdvances(['recipient_id' => $agentId, 'status' => 'active', 'limit' => 1]);
        $parentId   = !empty($myAdvances[0]['id']) ? (int)$myAdvances[0]['id'] : null;

        $result = $expAdv->createAdvance([
            'recipient_id'      => $recipientId,
            'recipient_name'    => $recipient['name'] ?? '',
            'amount'            => $amount,
            'currency'          => $currency,
            'purpose'           => $purpose,
            'description'       => $description ?: 'Issued by ' . $agentName,
            'project'           => 'dishnet',
            'parent_advance_id' => $parentId,
        ], $retailer);

        if ($result['ok']) {
            flash('✅ $' . number_format($amount, 2) . ' advance issued to ' . ($recipient['name'] ?? '') . ' — ' . $result['advance_no'], 'success');
        } else {
            flash('❌ ' . ($result['error'] ?? 'Failed.'), 'danger');
        }
        redirect('?page=dashboard&tab=my_account&v=issue_advance');
    }

    if ($act === 'submit_handover') {
        $hovAmount = round((float)($_POST['hov_amount'] ?? 0), 2);        $hovNote   = trim($_POST['hov_note'] ?? '');
        $hovToId   = (int)($_POST['to_staff_id'] ?? 0);
        $hovToName = trim($_POST['to_staff_name'] ?? '');
        // Fallback: if no recipient selected, find the accountant
        if (!$hovToId || !$hovToName) {
            foreach ($auth->getAllRetailers() as $_r) {
                if (($_r['role'] ?? '') === 'accountant' || !empty($_r['is_admin'])) {
                    $hovToId = (int)$_r['id']; $hovToName = $_r['name']; break;
                }
            }
        }
        if ($hovAmount > 0) {
            // Guard: amount can't exceed agent's cash position
            if (!class_exists('DualReadCashPosition')) require_once __DIR__ . '/../../lib/DualReadCashPosition.php';
            $_hovAgentPos = (new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? ''))->getPosition($agentId);
            $_hovAgentMax = round((float)($_hovAgentPos['cash_exposure'] ?? 0), 2);
            if ($hovAmount > $_hovAgentMax + 0.01 && $_hovAgentMax > 0) {
                flash("Amount \$" . number_format($hovAmount, 2) . " exceeds your cash position of \$" . number_format($_hovAgentMax, 2) . ".", 'danger');
                redirect('?page=dashboard&tab=my_account');
            }
            $hovProject = trim($_POST['hov_project'] ?? 'dishnet');
            if (!in_array($hovProject, ['dishnet','4g','bluecard'])) $hovProject = 'dishnet';
            $hov = [
                'from_id'    => $agentId,
                'from_name'  => $agentName,
                'to_id'      => $hovToId,
                'to_name'    => $hovToName,
                'amount'     => $hovAmount,
                'project'    => $hovProject,
                'note'       => $hovNote,
                'status'     => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $store->appendWithId('cash_handovers.json', $hov);
            $_snap->rebuild($agentId, 'handover', 'mc_handover');
            // Notify recipient (Diko / field accountant) that cash is coming their way
            try {
                if (!isset($notify)) $notify = svc('notify');
                $_hovRecipRetailer = $store->findOne('retailers.json', 'id', $hovToId);
                if ($_hovRecipRetailer && !empty($_hovRecipRetailer['phone'])) {
                    $_hovMsg = "💵 *Cash Handover Incoming*\n\n"
                             . "👤 From: *{$agentName}*\n"
                             . "💰 Amount: *\${$hovAmount}*\n"
                             . "📁 Project: " . strtoupper($hovProject) . "\n"
                             . ($hovNote ? "📝 Note: {$hovNote}\n" : '')
                             . "⏰ " . date('M j, g:i A') . "\n\n"
                             . "👉 Open *Field Cash Chain* tab to confirm receipt.";
                    $notify->sendVia('support', $_hovRecipRetailer['phone'], $_hovMsg,
                        'field_handover_incoming',
                        ['from' => $agentName, 'amount' => (string)$hovAmount]
                    );
                }
            } catch (\Throwable $_hovNotifyErr) { /* non-fatal */ }
            flash("Handover of \${$hovAmount} submitted. Pending confirmation.", 'success');
        } else {
            flash('Enter handover amount.', 'danger');
        }
        redirect('?page=dashboard&tab=my_account');
    }

    // ── record_exchange: atomic USD↔SSP conversion ─────────────────────
    if ($act === 'record_exchange') {
        require_once __DIR__ . '/../../lib/StaffLedgerWriter.php';
        require_once __DIR__ . '/../../lib/ExpenseAdvanceService.php';
        $excDir    = trim($_POST['exc_direction'] ?? 'usd_to_ssp');
        $excAmt    = round((float)($_POST['exc_amount']  ?? 0), 2);
        $excRate   = round((float)($_POST['exc_rate']    ?? 0), 2);
        $excNote   = trim($_POST['exc_note'] ?? '');
        $excSource = trim($_POST['exc_source'] ?? 'money_changer'); // money_changer | customer_ssp
        $excClient = trim($_POST['exc_client_ref'] ?? '');          // client name/ref if customer_ssp
        $excRef    = 'EXCH-' . date('ymdHis') . '-' . $agentId;
        $now       = date('Y-m-d H:i:s');

        if ($excAmt <= 0 || $excRate <= 0) {
            flash('Amount and rate required.', 'danger');
            redirect('?page=dashboard&tab=my_account&v=exchange');
        }

        if ($excDir === 'usd_to_ssp') {
            $sspReceived = round($excAmt * $excRate, 0);
            // Unified description — same string on both USD OUT and SSP IN sides
            if ($excSource === 'customer_ssp' && $excClient) {
                $desc = $excNote ?: ('Client paid SSP: ' . $excClient . ' — $' . number_format($excAmt, 2) . ' @ ' . number_format($excRate, 0) . ' — ' . $agentName);
            } else {
                $desc = $excNote ?: ('Exchange $' . number_format($excAmt, 2) . ' → ' . number_format($sspReceived, 0) . ' SSP @ ' . number_format($excRate, 0) . ' — ' . $agentName);
            }
            // SSP in
            $cinRecord = $store->appendWithId('cash_ins.json', [
                'collector_id' => $agentId, 'collector_name' => $agentName,
                'category' => $excSource === 'customer_ssp' ? 'Customer SSP Payment' : 'Exchange', 'currency' => 'SSP',
                'ssp_amount' => $sspReceived, 'usd_given' => $excAmt,
                'rate' => $excRate, 'amount' => $excAmt,
                'description' => $desc, 'exchange_ref' => $excRef,
                'status' => 'approved', 'approved_by' => $agentName,
                'approved_at' => $now, 'created_at' => $now,
            ]);
            StaffLedgerWriter::onCashIn($store->getPdo(), $cinRecord);
            // USD out via expense auto-approved
            $expSvc = new ExpenseAdvanceService($store, $store->getPdo(), $dataDir);
            $expR = $expSvc->submitExpense($agentId, [
                'amount' => $excAmt, 'currency' => 'USD',
                'category' => 'Exchange', 'description' => $desc,
                'exchange_ref' => $excRef,
            ]);
            if (!empty($expR['id'])) {
                $expSvc->approveExpense($expR['id'], ['id' => $agentId, 'name' => $agentName], 'Exchange auto-approved');
            }

            // ── Explicit cb_ledger writes (same as api_cashbook.php) ──────
            // approveExpense() path is unreliable for exchange entries.
            // Always write both sides directly to cb_ledger so both
            // USD cashbook and SSP cashbook reflect the exchange immediately.
            require_once __DIR__ . '/../../lib/CashbookService.php';
            $_excCb    = new CashbookService($store, $dataDir ?? '');
            $_excPdo   = $_excCb->getPdo();
            $_cbRef    = 'FIELD-' . $excRef;

            $_dupUsd = $_excPdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
            $_dupUsd->execute([$_cbRef . '-USD']);
            if (!$_dupUsd->fetchColumn()) {
                $_excCb->addEntryRaw([
                    'project'           => 'dishnet',
                    'date'              => date('Y-m-d'),
                    'direction'         => 'out',
                    'amount'            => $excAmt,
                    'currency'          => 'USD',
                    'ssp_amount'        => null,
                    'ssp_rate'          => $excRate,
                    'category'          => 'Exchange',
                    'category_raw'      => 'Exchange',
                    'person'            => $agentName,
                    'description'       => $desc,
                    'validation_ref'    => $_cbRef . '-USD',
                    'validation_status' => 'done',
                    'status'            => 'approved',
                    'source'            => 'field_exchange',
                ]);
            }
            $_dupSsp = $_excPdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
            $_dupSsp->execute([$_cbRef . '-SSP']);
            if (!$_dupSsp->fetchColumn()) {
                $_excCb->addEntryRaw([
                    'project'           => 'dishnet',
                    'date'              => date('Y-m-d'),
                    'direction'         => 'in',
                    'amount'            => $excAmt,
                    'currency'          => 'SSP',
                    'ssp_amount'        => $sspReceived,
                    'ssp_rate'          => $excRate,
                    'category'          => $excSource === 'customer_ssp' ? 'Customer SSP Payment' : 'Exchange',
                    'category_raw'      => 'Exchange',
                    'person'            => $agentName,
                    'description'       => $desc, // same as USD side — traceable
                    'validation_ref'    => $_cbRef . '-SSP',
                    'validation_status' => 'done',
                    'status'            => 'approved',
                    'source'            => 'field_exchange',
                ]);
            }
            flash('✅ Exchange: $' . number_format($excAmt, 2) . ' → ' . number_format($sspReceived, 0) . ' SSP @ ' . number_format($excRate, 0), 'success');
        } else {
            // SSP → USD
            $usdReceived = round($excAmt / $excRate, 2);
            $desc = $excNote ?: ('Exchange ' . number_format($excAmt, 0) . ' SSP → $' . number_format($usdReceived, 2) . ' @ ' . number_format($excRate, 0));
            // USD in
            $cinRecord = $store->appendWithId('cash_ins.json', [
                'collector_id' => $agentId, 'collector_name' => $agentName,
                'category' => 'Exchange', 'currency' => 'USD',
                'amount' => $usdReceived, 'ssp_amount' => 0,
                'ssp_given' => $excAmt, 'rate' => $excRate,
                'description' => $desc, 'exchange_ref' => $excRef,
                'status' => 'approved', 'approved_by' => $agentName,
                'approved_at' => $now, 'created_at' => $now,
            ]);
            StaffLedgerWriter::onCashIn($store->getPdo(), $cinRecord);
            // SSP out (negative entry)
            $sspOutRecord = $store->appendWithId('cash_ins.json', [
                'collector_id' => $agentId, 'collector_name' => $agentName,
                'category' => 'Exchange', 'currency' => 'SSP',
                'ssp_amount' => -1 * $excAmt, 'amount' => 0,
                'rate' => $excRate, 'description' => $desc . ' [SSP out]',
                'exchange_ref' => $excRef, 'status' => 'approved',
                'approved_by' => $agentName, 'approved_at' => $now, 'created_at' => $now,
            ]);
            StaffLedgerWriter::onCashIn($store->getPdo(), $sspOutRecord);

            // Phase C: deduct SSP from original exchange batch(es) via FIFO
            try {
                require_once __DIR__ . '/../../lib/CashbookService.php';
                $_revCb = new CashbookService($store, $dataDir ?? '');
                $_revCb->deductFromBatchesFIFO(
                    $store->load('cash_ins.json') ?: [],
                    $agentId, $agentName,
                    $excAmt, $excRate, $excRef,
                    $desc, date('Y-m-d')
                );
            } catch (\Throwable $e) { /* non-fatal */ }

            flash('✅ Exchange: ' . number_format($excAmt, 0) . ' SSP → $' . number_format($usdReceived, 2) . ' @ ' . number_format($excRate, 0), 'success');
        }
        redirect('?page=dashboard&tab=my_account');
    }
}

// ── Load position via DualReadCashPosition (ledger + JSON comparison) ──
require_once __DIR__ . '/../../lib/DualReadCashPosition.php';
$_mcDualSvc = new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? '');
$pos = $_mcDualSvc->getPosition($agentId);
if (!$pos) $pos = [];
$advBal      = (float)($pos['advance_balance'] ?? 0);
$collections = (float)($pos['collections'] ?? 0);
$expenses    = (float)($pos['expenses'] ?? 0);
$handovers   = (float)($pos['handovers'] ?? 0);
$trfSent     = (float)($pos['transfers_sent'] ?? 0);
$trfRecv     = (float)($pos['transfers_received'] ?? $pos['transfers_recv'] ?? 0);
$exposure    = (float)($pos['cash_exposure'] ?? 0);

// ── Expense/advance data ──
$expSummary = $expAdv->getStaffSummary($agentId);
$activeAdvances = $expAdv->getAdvances(['recipient_id' => $agentId, 'status' => 'active', 'limit' => 10]);
$recentExpenses = $expAdv->getExpenses(['staff_id' => $agentId, 'limit' => 20]);
$expCategories  = ['fuel'=>'⛽ Fuel','parts'=>'🔧 Parts','transport'=>'🚗 Transport','allowance'=>'💰 Allowance','food'=>'🍽 Food','other'=>'📦 Other'];

// ── Recent transactions (merged from all sources) ──
$txns = [];
try {
    $advRows = $store->getPdo()->prepare("SELECT id, amount, purpose, status, issued_by_name, created_at FROM cash_advances WHERE recipient_id = ? ORDER BY created_at DESC LIMIT 10");
    $advRows->execute([$agentId]);
    foreach ($advRows->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $txns[] = ['date'=>$a['created_at'],'type'=>'advance','desc'=>'Cash advance'.($a['purpose']?' — '.$a['purpose']:''),'amount'=>(float)$a['amount'],'dir'=>'in','status'=>$a['status']??''];
    }
} catch (\Throwable $e) {}

$allColl = $store->load('payment_collections.json') ?? [];
foreach (array_slice(array_reverse(array_filter($allColl, function($c) use ($agentId) { return (int)($c['retailer_id']??0)===$agentId && ($c['status']??'')!=='voided'; })), 0, 15) as $c) {
    $txns[] = ['date'=>$c['collected_at']??$c['created_at']??'','type'=>'collection','desc'=>'Payment from '.($c['customer_name']??'Customer'),'amount'=>(float)($c['amount']??0),'dir'=>'in','status'=>'active'];
}

$allHov = $store->load('cash_handovers.json') ?? [];
foreach (array_slice(array_reverse(array_filter($allHov, function($h) use ($agentId) { return (int)($h['from_id']??0)===$agentId; })), 0, 10) as $h) {
    $txns[] = ['date'=>$h['created_at']??'','type'=>'handover','desc'=>'Handover to '.($h['to_name']??'Office'),'amount'=>(float)($h['amount']??0),'dir'=>'out','status'=>$h['status']??''];
}

try {
    if (!class_exists('ExpenseGateway')) require_once __DIR__ . '/../../lib/ExpenseGateway.php';
    $_mcGw = new ExpenseGateway($store);
    $_mcAdvExps = $_mcGw->getAll(['staff_id' => $agentId, 'exclude_voided' => true]);
    // Only show advance-sourced expenses (field ones already shown above)
    foreach ($_mcAdvExps as $e) {
        if ($e['source'] !== 'advance') continue;
        $txns[] = ['date'=>$e['submitted_at']??'','type'=>'expense','desc'=>($e['category']??'Expense').($e['description']?' — '.$e['description']:''),'amount'=>(float)$e['amount'],'ssp_amount'=>(float)($e['ssp_amount']??0),'currency'=>$e['currency']??'USD','dir'=>'out','status'=>$e['status']??''];
    }
} catch (\Throwable $e) {}

try {
    $trfRows = $store->getPdo()->prepare("SELECT from_id, from_name, to_name, amount, status, created_at FROM staff_transfers WHERE (from_id=? OR to_id=?) ORDER BY created_at DESC LIMIT 5");
    $trfRows->execute([$agentId, $agentId]);
    foreach ($trfRows->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $isSent = (int)($t['from_id']??0)===$agentId;
        $txns[] = ['date'=>$t['created_at']??'','type'=>'transfer','desc'=>($isSent?'Transfer to '.($t['to_name']??''):'Transfer from '.($t['from_name']??'')),'amount'=>(float)$t['amount'],'dir'=>$isSent?'out':'in','status'=>$t['status']??''];
    }
} catch (\Throwable $e) {}

usort($txns, function($a, $b) { return strcmp($b['date']??'', $a['date']??''); });
$txns = array_slice($txns, 0, 30);

// ── Current sub-view ──
$v = $_GET['v'] ?? 'summary';
$oweText  = $exposure > 0 ? 'You owe company' : ($exposure < 0 ? 'Company owes you' : 'Settled');
$oweColor = $exposure > 0 ? '#dc2626' : ($exposure < 0 ? '#16a34a' : '#64748b');
?>

<style>
.mc-wrap{max-width:560px;margin:0 auto}
.mc-hero{background:linear-gradient(135deg,#1e293b,#334155);border-radius:20px;padding:20px;color:#fff;margin-bottom:12px;box-shadow:0 8px 30px rgba(0,0,0,.2)}
.mc-hero-name{font-size:12px;color:#94a3b8;font-weight:600}
.mc-hero-amt{font-size:36px;font-weight:900;letter-spacing:-2px;margin:4px 0 2px}
.mc-hero-label span{padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
.mc-grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#334155;border-radius:14px;overflow:hidden;margin-top:14px}
.mc-grid4>div{background:#1e293b;padding:10px 6px;text-align:center}
.mc-grid4 .v{font-size:15px;font-weight:800}
.mc-grid4 .l{font-size:8px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.3px}

.mc-tabs{display:flex;gap:0;overflow-x:auto;-webkit-overflow-scrolling:touch;background:#f1f5f9;border-radius:12px;padding:3px;margin-bottom:14px}
.mc-tab{flex:1;padding:9px 6px;border-radius:9px;font-size:11px;font-weight:700;color:#64748b;text-align:center;cursor:pointer;text-decoration:none;white-space:nowrap;min-width:0}
.mc-tab.active{background:#fff;color:#1e293b;box-shadow:0 1px 4px rgba(0,0,0,.08)}

.mc-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:14px}
.mc-card-head{padding:12px 16px;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:700;color:#1e293b;background:#f8fafc}
.mc-row{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #f0f2f5;font-size:13px}
.mc-row:last-child{border-bottom:none}
.mc-row .k{color:#64748b;font-weight:500;display:flex;align-items:center;gap:8px}
.mc-row .v{font-weight:700}
.mc-row.total{background:#f8fafc;font-size:14px}
.mc-row.total .k{color:#1e293b;font-weight:800}

.mc-txn{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f0f2f5}
.mc-txn:last-child{border-bottom:none}
.mc-txn-icon{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.mc-txn-info{flex:1;min-width:0}
.mc-txn-desc{font-size:12px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mc-txn-meta{font-size:10px;color:#94a3b8;margin-top:1px}
.mc-txn-amt{font-size:13px;font-weight:800;flex-shrink:0}
.mc-in{color:#16a34a}.mc-out{color:#dc2626}
.mc-pill{font-size:9px;font-weight:700;padding:2px 6px;border-radius:8px;margin-left:4px}

.mc-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.mc-act{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px 6px;text-align:center;text-decoration:none;color:#1e293b;font-size:10px;font-weight:700;display:flex;flex-direction:column;align-items:center;gap:3px}
.mc-act i{font-size:20px}

.mc-input{width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;box-sizing:border-box;margin-bottom:8px}
.mc-label{font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block}
.mc-btn{border:none;border-radius:12px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer;width:100%;font-family:inherit}
</style>

<div class="mc-wrap">

<?php
// Define support flag before if/else so it's always available
$_mcIsSupport = in_array($retailer['role'] ?? '', ['support_leader', 'support', 'sales', 'sales_staff', 'field_agent', 'collection']);
?>

<?php if (($retailer['role'] ?? '') === 'field_accountant'):
    // Load data for field_accountant
    require_once __DIR__ . '/../../lib/CashbookService.php';
    require_once __DIR__ . '/../../lib/DualReadCashPosition.php';
    require_once __DIR__ . '/../../lib/StaffCashPositionService.php';
    $_mcCollections = array_filter($store->load('payment_collections.json') ?: [], fn($c) => (int)($c['retailer_id'] ?? 0) === $agentId && ($c['status'] ?? '') !== 'voided');
    $_mcCashIn   = array_filter($store->load('cash_ins.json') ?: [], fn($i) => (int)($i['collector_id'] ?? 0) === $agentId);
    $_mcHandovers= array_filter($store->load('cash_handovers.json') ?: [], fn($h) => (int)($h['from_id'] ?? 0) === $agentId);

    // ── v4.12.10: SSP bag via StaffCashPositionService (JSON-based) ──
    // Previously used DualReadCashPosition which reads staff_ledger SQL table.
    // That path went stale for Aida (1,300,000 shown vs 333,000 actual) because
    // the auto-approve code path in submitExpense doesn't post expenses to the
    // ledger. JSON-based calc is the same formula the admin Staff Cashbook view
    // and wallet.php use — single source of truth, always correct.
    $_mcScpSvc   = new StaffCashPositionService($store, $store->getPdo());
    $_mcSspBal   = max(0, round($_mcScpSvc->getSSPBalance($agentId), 0));
    try { $_mcRate = (new CashbookService($store, $dataDir))->getExchangeRate() ?: 5700; } catch (\Throwable $e) { $_mcRate = 5700; }
    $_mcSspUsd   = $_mcRate > 0 ? round($_mcSspBal / $_mcRate, 2) : 0;

    // Today's collections (physical cash received today)
    $_mcToday = date('Y-m-d');
    $_mcTodayCollected = round(array_sum(array_column(array_values(
        array_filter($_mcCollections, fn($c) => substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10) === $_mcToday)
    ), 'amount')), 2);
    // Today's handovers (already submitted today)
    $_mcTodayHanded = round(array_sum(array_column(array_values(
        array_filter($_mcHandovers, fn($h) => substr($h['created_at'] ?? '', 0, 10) === $_mcToday)
    ), 'amount')), 2);
    // Unhanded today = collected today minus any handovers today
    $_mcTodayUnhanded = max(0, $_mcTodayCollected - $_mcTodayHanded);

    // Total lifetime collected (for display)
    $_mcTotalCollected = round(array_sum(array_column(array_values($_mcCollections), 'amount')), 2);
    $_mcTotalHanded = round(array_sum(array_column(array_values(
        array_filter($_mcHandovers, fn($h) => ($h['status'] ?? '') === 'confirmed' && ($h['currency'] ?? 'USD') === 'USD')
    ), 'amount')), 2);

    // This month's collections
    $_mcMonthStart = date('Y-m-01');
    $_mcMonthName  = date('F');
    $_mcMonthCollected = round(array_sum(array_column(array_values(
        array_filter($_mcCollections, fn($c) => substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10) >= $_mcMonthStart)
    ), 'amount')), 2);
    $_mcMonthCount = count(array_filter($_mcCollections,
        fn($c) => substr($c['collected_at'] ?? $c['created_at'] ?? '', 0, 10) >= $_mcMonthStart));

    $_mcOwed = $exposure < 0 ? abs($exposure) : 0;
?>

<!-- ══ Field Accountant — Simple Hero ══ -->
<div style="background:linear-gradient(135deg,#1e293b,#0f172a);border-radius:20px;padding:20px;color:#fff;margin-bottom:12px;">
    <div style="font-size:12px;color:#94a3b8;font-weight:600;margin-bottom:4px;"><?= h($agentName) ?></div>

    <!-- USD + SSP side by side -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div style="background:rgba(22,163,74,.12);border:1px solid rgba(22,163,74,.25);border-radius:14px;padding:14px 12px;">
            <div style="font-size:9px;font-weight:800;color:#4ade80;text-transform:uppercase;letter-spacing:.8px;">💵 Today</div>
            <div style="font-size:28px;font-weight:900;color:#4ade80;letter-spacing:-1px;margin-top:2px;">$<?= number_format($_mcTodayCollected, 0) ?></div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">collected today</div>
        </div>
        <div style="background:rgba(59,130,246,.12);border:1px solid rgba(59,130,246,.25);border-radius:14px;padding:14px 12px;">
            <div style="font-size:9px;font-weight:800;color:#60a5fa;text-transform:uppercase;letter-spacing:.8px;">🇸🇸 SSP</div>
            <div style="font-size:28px;font-weight:900;color:#60a5fa;letter-spacing:-1px;margin-top:2px;"><?= number_format($_mcSspBal, 0) ?></div>
            <?php if ($_mcSspBal > 0): ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">≈ $<?= number_format($_mcSspUsd, 2) ?></div><?php else: ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">in bag</div><?php endif; ?>
        </div>
    </div>

    <!-- Month + status pills -->
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(96,165,250,.1);border:1px solid rgba(96,165,250,.2);border-radius:20px;padding:5px 14px;margin-bottom:6px;">
        <span style="font-size:11px;font-weight:700;color:#60a5fa;">📅 <?= $_mcMonthName ?>: $<?= number_format($_mcMonthCollected, 0) ?> (<?= $_mcMonthCount ?> collections)</span>
    </div>
    <?php if ($_mcOwed > 0): ?>
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);border-radius:20px;padding:5px 14px;">
        <span style="font-size:11px;font-weight:700;color:#fbbf24;">💡 Rupesh owes you $<?= number_format($_mcOwed, 2) ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- ══ Quick Actions — Handover always available ══ -->
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:10px;">
    <a href="?page=dashboard&tab=my_account&v=handover"
       style="background:<?= $v==='handover'?'#dc2626':'#fff' ?>;border:2px solid <?= $v==='handover'?'#dc2626':'#e2e8f0' ?>;border-radius:14px;padding:14px 8px;text-align:center;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:4px;">
        <span style="font-size:22px;">⬆️</span>
        <span style="font-size:12px;font-weight:800;color:<?= $v==='handover'?'#fff':'#dc2626' ?>;">Handover</span>
        <span style="font-size:10px;color:<?= $v==='handover'?'rgba(255,255,255,.7)':'#94a3b8' ?>;">Submit cash to Rupesh</span>
    </a>
    <a href="?page=dashboard&tab=my_account&v=expense"
       style="background:<?= $v==='expense'?'#d97706':'#fff' ?>;border:2px solid <?= $v==='expense'?'#d97706':'#e2e8f0' ?>;border-radius:14px;padding:14px 8px;text-align:center;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:4px;">
        <span style="font-size:22px;">💸</span>
        <span style="font-size:12px;font-weight:800;color:<?= $v==='expense'?'#fff':'#d97706' ?>;">Cash Out</span>
        <span style="font-size:10px;color:<?= $v==='expense'?'rgba(255,255,255,.7)':'#94a3b8' ?>;">Pay staff / expense</span>
    </a>
</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;">
    <a href="?page=dashboard&tab=wallet" style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:10px 6px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">📋</div>
        <div style="font-size:11px;font-weight:700;color:#374151;">Register</div>
    </a>
    <a href="?page=dashboard&tab=my_account&v=advance"
       style="background:<?= $v==='advance'?'#1e293b':'#fff' ?>;border:1.5px solid <?= $v==='advance'?'#1e293b':'#e2e8f0' ?>;border-radius:12px;padding:10px 6px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">📥</div>
        <div style="font-size:11px;font-weight:700;color:<?= $v==='advance'?'#fff':'#374151' ?>;">Advance</div>
    </a>
    <a href="?page=dashboard&tab=my_account&v=issue_advance"
       style="background:<?= $v==='issue_advance'?'#7c3aed':'#fff' ?>;border:1.5px solid <?= $v==='issue_advance'?'#7c3aed':'#e2e8f0' ?>;border-radius:12px;padding:10px 6px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">💳</div>
        <div style="font-size:11px;font-weight:700;color:<?= $v==='issue_advance'?'#fff':'#374151' ?>;">Issue</div>
    </a>
    <a href="?page=dashboard&tab=my_account&v=exchange"
       style="background:<?= $v==='exchange'?'#7c3aed':'#fff' ?>;border:1.5px solid <?= $v==='exchange'?'#7c3aed':'#e2e8f0' ?>;border-radius:12px;padding:10px 6px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">💱</div>
        <div style="font-size:11px;font-weight:700;color:<?= $v==='exchange'?'#fff':'#374151' ?>;">Convert</div>
    </a>
</div>

<?php else: ?>
<!-- ══ Non field_accountant hero ══ -->

<?php
    // Support roles (Bidal, Joel, Emmanuel) — load SSP data and show SSP-first hero
    $_mcIsSupport = in_array($retailer['role'] ?? '', ['support_leader', 'support', 'sales', 'sales_staff', 'field_agent', 'collection']);
    if ($_mcIsSupport) {
        $_mcCashIn   = array_filter($store->load('cash_ins.json') ?: [], fn($i) => (int)($i['collector_id'] ?? 0) === $agentId);
        $_mcExpenses2 = array_values($_mcMyExps);
        $_mcHandovers2= array_filter($store->load('cash_handovers.json') ?: [], fn($h) => (int)($h['from_id'] ?? 0) === $agentId);

        // ── v4.12.10: SSP bag via StaffCashPositionService (JSON-based) ──
        // Previously used DualReadCashPosition which reads staff_ledger SQL.
        // Ledger drifts when auto-approve path skips the hook (Aida bug).
        // JSON-based calc is the same formula admin Staff Cashbook uses.
        // All approved + pending SSP expenses already subtracted by the service,
        // so no defensive subtraction needed here (was the v4.12.7 double-count).
        if (!isset($_mcScpSvc)) {
            require_once __DIR__ . '/../../lib/StaffCashPositionService.php';
            $_mcScpSvc = new StaffCashPositionService($store, $store->getPdo());
        }
        $_mcSspBal2 = max(0, round($_mcScpSvc->getSSPBalance($agentId), 0));
        $_mcSspExp2 = 0; $_mcSspPend2 = 0; // Used by weekly stats below
        $_mcSspHov2 = round(array_sum(array_map(fn($h) => (float)($h['ssp_amount'] ?? ($h['currency'] ?? 'USD') === 'SSP' ? (float)($h['amount'] ?? 0) : 0), array_values(array_filter($_mcHandovers2, fn($h) => ($h['status'] ?? '') === 'confirmed' && (($h['currency'] ?? '') === 'SSP' || (float)($h['ssp_amount'] ?? 0) > 0))))), 0);
        // Still compute pending count for badge display
        $_mcSspPendCnt2 = count(array_filter($_mcExpenses2, fn($e) => ($e['currency'] ?? 'USD') === 'SSP' && ($e['status'] ?? '') === 'pending'));
        try { $_mcSqlPendCnt = $store->getPdo()->prepare("SELECT COUNT(*) FROM staff_expenses WHERE staff_id=? AND currency='SSP' AND status='pending'"); $_mcSqlPendCnt->execute([$agentId]); $_mcSspPendCnt2 += (int)$_mcSqlPendCnt->fetchColumn(); } catch (\Throwable $e) {}
        $_mcSspIn2  = round(array_sum(array_column(array_values(array_filter($_mcCashIn, fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange','USD Received']) && !in_array($i['status'] ?? 'approved', ['rejected','voided']))), 'ssp_amount')), 0);
        try { require_once __DIR__ . '/../../lib/CashbookService.php'; $_mcRate2 = (new CashbookService($store, $dataDir))->getExchangeRate() ?: 5700; } catch (\Throwable $e) { $_mcRate2 = 5700; }
        $_mcSspUsd2 = $_mcRate2 > 0 ? round($_mcSspBal2 / $_mcRate2, 2) : 0;

        // Weekly SSP stats
        $_mcWeekStart2 = date('Y-m-d', strtotime('monday this week'));
        $_mcSspWeekIn2 = round(array_sum(array_column(array_values(array_filter($_mcCashIn, fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange']) && !in_array($i['status'] ?? 'approved', ['rejected','voided']) && substr($i['created_at'] ?? '', 0, 10) >= $_mcWeekStart2)), 'ssp_amount')), 0);
        $_mcSspWeekOut2 = round(array_sum(array_column(array_values(array_filter($_mcExpenses2, fn($e) => ($e['currency'] ?? 'USD') === 'SSP' && in_array($e['status'] ?? '', ['approved','pending']) && substr($e['submitted_at'] ?? $e['created_at'] ?? '', 0, 10) >= $_mcWeekStart2)), 'ssp_amount')), 0);
        // Include staff_expenses SQLite in weekly spent
        try {
            $_mcSqlWeekOut = $store->getPdo()->prepare("SELECT COALESCE(SUM(CASE WHEN ssp_amount>0 THEN ssp_amount ELSE amount END),0) FROM staff_expenses WHERE staff_id=? AND currency='SSP' AND status IN ('approved','pending') AND expense_date>=?");
            $_mcSqlWeekOut->execute([$agentId, $_mcWeekStart2]); $_mcSspWeekOut2 += round((float)$_mcSqlWeekOut->fetchColumn(), 0);
        } catch (\Throwable $e) {}

        // ── Real USD position (cash_ins USD Received + payment collections) ──
        // USD Received from cash_ins — exclude personal pay (salary, allowance, bonus)
        // v4.21.109: keyword list now lives in StaffCashPositionService::PERSONAL_PAY_KEYWORDS
        // — single source shared with admin Staff Cashbooks and StaffLedgerWriter.
        $_mcUsdIn2 = round(array_sum(array_column(array_values(array_filter($_mcCashIn, function($i) {
            if (($i['category'] ?? '') !== 'USD Received') return false;
            if (in_array($i['status'] ?? 'approved', ['rejected','voided'])) return false;
            if (StaffCashPositionService::isPersonalPay($i)) return false;
            return true;
        })), 'amount')), 2);
        // Add payment collections from staff_ledger (COL-* entries)
        try {
            $_mcColStmt = $store->getPdo()->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM staff_ledger
                 WHERE staff_id=? AND direction='in' AND currency='USD'
                   AND category='collection' AND idempotency_key LIKE 'COL-%'
                   AND status NOT IN ('voided','cancelled')"
            );
            $_mcColStmt->execute([$agentId]);
            $_mcUsdIn2 += round((float)$_mcColStmt->fetchColumn(), 2);
        } catch (\Throwable $e) {}
        $_mcUsdExp2 = round(array_sum(array_column(array_values(array_filter($_mcExpenses2, fn($e) =>
            ($e['currency'] ?? 'USD') === 'USD'
            && in_array($e['status'] ?? '', ['approved','pending'])
        )), 'amount')), 2);
        // Include staff_expenses SQLite for USD
        try {
            $_mcSqlUsdExp = $store->getPdo()->prepare("SELECT COALESCE(SUM(amount),0) FROM staff_expenses WHERE staff_id=? AND currency='USD' AND status IN ('approved','pending')");
            $_mcSqlUsdExp->execute([$agentId]); $_mcUsdExp2 += round((float)$_mcSqlUsdExp->fetchColumn(), 2);
        } catch (\Throwable $e) {}
        $_mcUsdHov2 = round(array_sum(array_map(fn($h) => (float)($h['amount'] ?? 0), array_values(array_filter($_mcHandovers2, fn($h) =>
            ($h['status'] ?? '') === 'confirmed'
            && strtoupper($h['currency'] ?? 'USD') === 'USD'
        )))), 2);
        // v4.21.109: use service method for the displayed hero balance so admin
        // staff_cashbooks and this staff portal can never disagree. The
        // component vars ($_mcUsdIn2/$_mcUsdExp2/$_mcUsdHov2) are kept for the
        // detail breakdown card below (USD received / spent / handed over).
        $_mcUsdBal2 = round($_mcScpSvc->getUSDBalance($agentId), 2);
    }
?>

<?php if ($_mcIsSupport): ?>
<!-- ══ Support role — dual currency hero (SSP primary, USD secondary) ══ -->
<div style="background:linear-gradient(135deg,#1e293b,#0f172a);border-radius:20px;padding:20px;color:#fff;margin-bottom:12px;">
    <div style="font-size:12px;color:#94a3b8;font-weight:600;margin-bottom:8px;"><?= h($agentName) ?></div>

    <!-- SSP + USD side by side -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div style="background:rgba(251,146,60,.12);border:1px solid rgba(251,146,60,.25);border-radius:14px;padding:14px 12px;">
            <div style="font-size:9px;font-weight:800;color:#fdba74;text-transform:uppercase;letter-spacing:.8px;">🇸🇸 SSP Bag</div>
            <div style="font-size:28px;font-weight:900;color:#fb923c;letter-spacing:-1px;margin-top:2px;"><?= number_format($_mcSspBal2, 0) ?></div>
            <?php if ($_mcSspBal2 > 0): ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">≈ $<?= number_format($_mcSspUsd2, 2) ?> @ <?= number_format($_mcRate2, 0) ?></div><?php else: ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">no SSP received</div><?php endif; ?>
        </div>
        <div style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.25);border-radius:14px;padding:14px 12px;">
            <div style="font-size:9px;font-weight:800;color:#4ade80;text-transform:uppercase;letter-spacing:.8px;">💵 USD Cash</div>
            <div style="font-size:28px;font-weight:900;color:<?= $_mcUsdBal2 > 0 ? '#4ade80' : '#475569' ?>;letter-spacing:-1px;margin-top:2px;">$<?= number_format($_mcUsdBal2, 2) ?></div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= $_mcUsdBal2 > 0 ? 'in hand' : ($_mcUsdIn2 > 0 ? 'settled' : 'no USD received') ?></div>
        </div>
    </div>

    <?php if ($_mcSspPendCnt2 > 0): ?>
    <div style="display:inline-flex;align-items:center;gap:5px;background:rgba(251,191,36,.12);border-radius:20px;padding:4px 12px;margin-bottom:6px;">
        <span style="font-size:11px;font-weight:700;color:#fbbf24;">⏳ <?= $_mcSspPendCnt2 ?> expense(s) awaiting approval</span>
    </div>
    <?php endif; ?>

    <!-- Weekly activity -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:4px;padding-top:12px;border-top:1px solid rgba(255,255,255,.06);">
        <div style="text-align:center;">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Received</div>
            <div style="font-size:16px;font-weight:900;color:#4ade80;"><?= number_format($_mcSspWeekIn2, 0) ?></div>
            <div style="font-size:9px;color:#64748b;">SSP this week</div>
        </div>
        <div style="text-align:center;">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Spent</div>
            <div style="font-size:16px;font-weight:900;color:#f87171;"><?= number_format($_mcSspWeekOut2, 0) ?></div>
            <div style="font-size:9px;color:#64748b;">SSP this week</div>
        </div>
        <div style="text-align:center;">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;margin-bottom:2px;">Pending</div>
            <div style="font-size:16px;font-weight:900;color:#fbbf24;"><?= $_mcSspPendCnt2 ?></div>
            <div style="font-size:9px;color:#64748b;">awaiting approval</div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ══ Original USD hero ══ -->
<div class="mc-hero">
    <div class="mc-hero-name"><?= h($agentName) ?> · <?= h($retailer['role'] ?? 'Staff') ?></div>
    <div class="mc-hero-amt" style="color:<?= $exposure > 0 ? '#fca5a5' : ($exposure < 0 ? '#86efac' : '#94a3b8') ?>">$<?= number_format(abs($exposure), 2) ?></div>
    <div class="mc-hero-label"><span style="background:<?= $exposure > 0 ? 'rgba(220,38,38,.2);color:#fca5a5' : ($exposure < 0 ? 'rgba(22,163,74,.2);color:#86efac' : 'rgba(148,163,184,.2);color:#94a3b8') ?>"><?= $oweText ?></span></div>
    <div class="mc-grid4">
        <div><div class="v" style="color:#86efac">$<?= number_format($advBal,0) ?></div><div class="l">Advances</div></div>
        <div><div class="v" style="color:#86efac">$<?= number_format($collections,0) ?></div><div class="l">Collected</div></div>
        <div><div class="v" style="color:#fca5a5">$<?= number_format($expenses,0) ?></div><div class="l">Expenses</div></div>
        <div><div class="v" style="color:#fca5a5">$<?= number_format($handovers,0) ?></div><div class="l">Handovers</div></div>
    </div>
</div>
<?php endif; /* end support vs original hero */ ?>

<!-- ══ Action buttons ══ -->
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px;">
    <a href="?page=dashboard&tab=my_account&v=handover"
       style="background:<?= $v==='handover'?'#dc2626':'#fff' ?>;border:2px solid <?= $v==='handover'?'#dc2626':'#e2e8f0' ?>;border-radius:14px;padding:14px 8px;text-align:center;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:4px;">
        <span style="font-size:22px;">⬆️</span>
        <span style="font-size:12px;font-weight:800;color:<?= $v==='handover'?'#fff':'#dc2626' ?>;">Handover</span>
        <span style="font-size:10px;color:<?= $v==='handover'?'rgba(255,255,255,.7)':'#94a3b8' ?>;">Submit cash to office</span>
    </a>
    <a href="?page=dashboard&tab=my_account&v=expense"
       style="background:<?= $v==='expense'?'#d97706':'#fff' ?>;border:2px solid <?= $v==='expense'?'#d97706':'#e2e8f0' ?>;border-radius:14px;padding:14px 8px;text-align:center;text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:4px;">
        <span style="font-size:22px;">💸</span>
        <span style="font-size:12px;font-weight:800;color:<?= $v==='expense'?'#fff':'#d97706' ?>;">Cash Out</span>
        <span style="font-size:10px;color:<?= $v==='expense'?'rgba(255,255,255,.7)':'#94a3b8' ?>;">Record expense / payment</span>
    </a>
</div>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;">
    <a href="?page=dashboard&tab=my_account&v=summary"
       style="background:<?= $v==='summary'||$v==='ledger'?'#1e293b':'#fff' ?>;border:1.5px solid <?= $v==='summary'||$v==='ledger'?'#1e293b':'#e2e8f0' ?>;border-radius:12px;padding:10px 6px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">📊</div>
        <div style="font-size:11px;font-weight:700;color:<?= $v==='summary'||$v==='ledger'?'#fff':'#374151' ?>;">Summary</div>
    </a>
    <a href="?page=dashboard&tab=my_account&v=advance"
       style="background:<?= $v==='advance'?'#1e293b':'#fff' ?>;border:1.5px solid <?= $v==='advance'?'#1e293b':'#e2e8f0' ?>;border-radius:12px;padding:10px 6px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">📥</div>
        <div style="font-size:11px;font-weight:700;color:<?= $v==='advance'?'#fff':'#374151' ?>;">Advance</div>
    </a>
    <a href="?page=dashboard&tab=cash_declaration"
       style="background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:10px 6px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">📋</div>
        <div style="font-size:11px;font-weight:700;color:#374151;">Cash Count</div>
    </a>
</div>
<?php if ($_mcIsSupport): ?>
<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px;">
    <a href="?page=dashboard&tab=my_account&v=ssp_book"
       style="background:<?= $v==='ssp_book'?'#c2410c':'#fff' ?>;border:2px solid <?= $v==='ssp_book'?'#c2410c':'#e2e8f0' ?>;border-radius:12px;padding:10px 8px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">🇸🇸</div>
        <div style="font-size:11px;font-weight:700;color:<?= $v==='ssp_book'?'#fff':'#c2410c' ?>;">SSP Cashbook</div>
    </a>
    <a href="?page=dashboard&tab=my_account&v=usd_book"
       style="background:<?= $v==='usd_book'?'#059669':'#fff' ?>;border:2px solid <?= $v==='usd_book'?'#059669':'#e2e8f0' ?>;border-radius:12px;padding:10px 8px;text-align:center;text-decoration:none;">
        <div style="font-size:14px;">💵</div>
        <div style="font-size:11px;font-weight:700;color:<?= $v==='usd_book'?'#fff':'#059669' ?>;">USD Cashbook</div>
    </a>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($v === 'summary' || $v === 'ledger'): ?>
<!-- ══════════════════════════════════════════════════ SUMMARY / LEDGER ═══ -->
<?php if (($retailer['role'] ?? '') !== 'field_accountant'): ?>
<div class="mc-card">
    <div class="mc-card-head">Cash Position</div>
    <?php if ($_mcIsSupport && $_mcSspBal2 >= 0): ?>
    <!-- Support role: SSP position -->
    <div class="mc-row"><span class="k"><span style="font-size:15px;">📥</span> SSP received from office</span><span class="v mc-in" style="color:#c2410c;">+<?= number_format($_mcSspIn2, 0) ?> SSP</span></div>
    <div class="mc-row"><span class="k"><span style="font-size:15px;">🧾</span> SSP expenses (approved)</span><span class="v mc-out" style="color:#dc2626;">-<?= number_format($_mcSspExp2, 0) ?> SSP</span></div>
    <?php if ($_mcSspPend2 > 0): ?><div class="mc-row"><span class="k"><span style="font-size:15px;">⏳</span> SSP expenses (pending)</span><span class="v" style="color:#d97706;">-<?= number_format($_mcSspPend2, 0) ?> SSP</span></div><?php endif; ?>
    <?php if ($_mcSspHov2 > 0): ?><div class="mc-row"><span class="k"><span style="font-size:15px;">🏦</span> SSP returned to office</span><span class="v mc-out" style="color:#dc2626;">-<?= number_format($_mcSspHov2, 0) ?> SSP</span></div><?php endif; ?>
    <div class="mc-row total"><span class="k">🇸🇸 SSP balance</span><span class="v" style="color:#c2410c;font-weight:900;"><?= number_format($_mcSspBal2, 0) ?> SSP</span></div>
    <?php if ($_mcUsdIn2 > 0 || $_mcUsdExp2 > 0 || $_mcUsdHov2 > 0): ?>
    <div style="border-top:1px solid #e2e8f0;margin-top:8px;padding-top:8px;">
        <div class="mc-row"><span class="k"><span style="font-size:15px;">💵</span> USD received</span><span class="v mc-in">+$<?= number_format($_mcUsdIn2, 2) ?></span></div>
        <div class="mc-row"><span class="k"><span style="font-size:15px;">🧾</span> USD expenses</span><span class="v mc-out">-$<?= number_format($_mcUsdExp2, 2) ?></span></div>
        <?php if ($_mcUsdHov2 > 0): ?><div class="mc-row"><span class="k"><span style="font-size:15px;">🏦</span> USD returned to office</span><span class="v mc-out">-$<?= number_format($_mcUsdHov2, 2) ?></span></div><?php endif; ?>
        <div class="mc-row total"><span class="k">💵 USD balance</span><span class="v" style="color:<?= $_mcUsdBal2 > 0 ? '#059669' : '#475569' ?>;font-weight:900;">$<?= number_format($_mcUsdBal2, 2) ?></span></div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <!-- Non-support: original USD position -->
    <div class="mc-row"><span class="k"><span style="font-size:15px;">📥</span> Advances from company</span><span class="v mc-in">+$<?= number_format($advBal, 2) ?></span></div>
    <div class="mc-row"><span class="k"><span style="font-size:15px;">💳</span> Customer collections</span><span class="v mc-in">+$<?= number_format($collections, 2) ?></span></div>
    <div class="mc-row"><span class="k"><span style="font-size:15px;">🧾</span> Approved expenses</span><span class="v mc-out">-$<?= number_format($expenses, 2) ?></span></div>
    <div class="mc-row"><span class="k"><span style="font-size:15px;">🏦</span> Handovers to office</span><span class="v mc-out">-$<?= number_format($handovers, 2) ?></span></div>
    <?php if ($trfSent > 0): ?><div class="mc-row"><span class="k"><span style="font-size:15px;">↗️</span> Transfers sent</span><span class="v mc-out">-$<?= number_format($trfSent, 2) ?></span></div><?php endif; ?>
    <?php if ($trfRecv > 0): ?><div class="mc-row"><span class="k"><span style="font-size:15px;">↙️</span> Transfers received</span><span class="v mc-in">+$<?= number_format($trfRecv, 2) ?></span></div><?php endif; ?>
    <div class="mc-row total"><span class="k"><?= $exposure >= 0 ? '🔴 You owe company' : '🟢 Company owes you' ?></span><span class="v" style="color:<?= $oweColor ?>">$<?= number_format(abs($exposure), 2) ?></span></div>
    <?php endif; ?>
</div>
<?php endif; /* not field_accountant */ ?>

<?php if ($_mcIsSupport): ?>
<!-- ══ SSP Ledger — every transaction ══ -->
<?php
$_ledgerSSP = [];
foreach ($_mcCashIn as $ci) {
    if (in_array($ci['status'] ?? 'approved', ['rejected','voided'])) continue;
    $sspAmt = (float)($ci['ssp_amount'] ?? 0);
    $usdAmt = (float)($ci['amount'] ?? 0);
    $cat = $ci['category'] ?? '';
    if ($sspAmt > 0 || in_array($cat, ['SSP Received','Exchange'])) {
        $_ledgerSSP[] = ['date'=>$ci['created_at']??'','dir'=>'in','amount'=>$sspAmt,'desc'=>($ci['description']??$cat),'from'=>'Office'];
    }
}
foreach ($_mcExpenses2 as $e) {
    if (($e['currency']??'USD')!=='SSP') continue;
    if (!in_array($e['status']??'',['approved','pending'])) continue;
    $amt = (float)($e['ssp_amount']??0) > 0 ? (float)$e['ssp_amount'] : (float)($e['amount']??0);
    $_ledgerSSP[] = ['date'=>$e['submitted_at']??$e['created_at']??'','dir'=>'out','amount'=>$amt,
        'desc'=>($e['category']??'Expense').($e['description']?' — '.$e['description']:''),
        'status'=>$e['status']??''];
}
foreach ($_mcHandovers2 as $h) {
    if (($h['status']??'')!=='confirmed' || ($h['currency']??'USD')!=='SSP') continue;
    $amt = (float)($h['ssp_amount']??$h['amount']??0);
    $_ledgerSSP[] = ['date'=>$h['created_at']??'','dir'=>'out','amount'=>$amt,'desc'=>'Returned to '.($h['to_name']??'Office')];
}
usort($_ledgerSSP, fn($a,$b)=>strcmp($b['date'],$a['date']));

$_ledgerUSD = [];
foreach ($_mcCashIn as $ci) {
    if (in_array($ci['status'] ?? 'approved', ['rejected','voided'])) continue;
    if (($ci['category']??'') === 'USD Received' && (float)($ci['amount']??0) > 0) {
        $_ledgerUSD[] = ['date'=>$ci['created_at']??'','dir'=>'in','amount'=>(float)$ci['amount'],'desc'=>($ci['description']??'USD Received'),'from'=>'Office'];
    }
}
foreach ($_mcExpenses2 as $e) {
    if (($e['currency']??'USD')!=='USD') continue;
    if (!in_array($e['status']??'',['approved','pending'])) continue;
    $_ledgerUSD[] = ['date'=>$e['submitted_at']??$e['created_at']??'','dir'=>'out','amount'=>(float)($e['amount']??0),
        'desc'=>($e['category']??'Expense').($e['description']?' — '.$e['description']:''),
        'status'=>$e['status']??''];
}
foreach ($_mcHandovers2 as $h) {
    if (($h['status']??'')!=='confirmed' || strtoupper($h['currency']??'USD')!=='USD') continue;
    $_ledgerUSD[] = ['date'=>$h['created_at']??'','dir'=>'out','amount'=>(float)($h['amount']??0),'desc'=>'Returned to '.($h['to_name']??'Office')];
}
usort($_ledgerUSD, fn($a,$b)=>strcmp($b['date'],$a['date']));
?>

<?php if (!empty($_ledgerSSP)): ?>
<div class="mc-card">
    <div class="mc-card-head">🇸🇸 SSP Ledger (<?= count($_ledgerSSP) ?>)</div>
    <?php $_sspRun = 0; foreach (array_reverse($_ledgerSSP) as $_ls): $_sspRun += ($_ls['dir']==='in'?1:-1)*(float)$_ls['amount']; ?>
    <div class="mc-row" style="padding:8px 16px;border-bottom:1px solid #f1f5f9;">
        <div style="flex:1;min-width:0;">
            <div style="font-size:12px;font-weight:600;color:<?= $_ls['dir']==='in'?'#059669':'#dc2626' ?>;">
                <?= $_ls['dir']==='in'?'📥 +':'📤 -' ?><?= number_format((float)$_ls['amount'],0) ?> SSP
            </div>
            <div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(substr($_ls['desc'],0,40)) ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px;color:#374151;font-weight:600;">Bal: <?= number_format(max(0,$_sspRun),0) ?></div>
            <div style="font-size:10px;color:#94a3b8;"><?= date('d M', strtotime($_ls['date'])) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($_ledgerUSD)): ?>
<div class="mc-card">
    <div class="mc-card-head">💵 USD Ledger (<?= count($_ledgerUSD) ?>)</div>
    <?php $_usdRun = 0; foreach (array_reverse($_ledgerUSD) as $_lu): $_usdRun += ($_lu['dir']==='in'?1:-1)*(float)$_lu['amount']; ?>
    <div class="mc-row" style="padding:8px 16px;border-bottom:1px solid #f1f5f9;">
        <div style="flex:1;min-width:0;">
            <div style="font-size:12px;font-weight:600;color:<?= $_lu['dir']==='in'?'#059669':'#dc2626' ?>;">
                <?= $_lu['dir']==='in'?'📥 +$':'📤 -$' ?><?= number_format((float)$_lu['amount'],2) ?>
            </div>
            <div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars(substr($_lu['desc'],0,40)) ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:11px;color:#374151;font-weight:600;">Bal: $<?= number_format(max(0,$_usdRun),2) ?></div>
            <div style="font-size:10px;color:#94a3b8;"><?= date('d M', strtotime($_lu['date'])) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; /* isSupport */ ?>

<!-- Recent transactions -->
<div class="mc-card">
    <div class="mc-card-head">Recent (<?= count($txns) ?>)</div>
    <?php if (empty($txns)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">No transactions yet</div>
    <?php else: ?>
    <?php foreach (array_slice($txns, 0, 15) as $tx):
        $ic = ['advance'=>['📥','#dcfce7'],'collection'=>['💳','#dbeafe'],'handover'=>['🏦','#fef2f2'],'expense'=>['🧾','#fef3c7'],'transfer'=>['↔️','#f3e8ff']][$tx['type']] ?? ['📄','#f1f5f9'];
        $stC = ['active'=>['#dcfce7','#065f46'],'approved'=>['#dcfce7','#065f46'],'pending'=>['#fef3c7','#92400e'],'confirmed'=>['#dcfce7','#065f46'],'partial'=>['#fef3c7','#92400e'],'rejected'=>['#fef2f2','#991b1b']][$tx['status']??''] ?? ['#f1f5f9','#64748b'];
    ?>
    <div class="mc-txn">
        <div class="mc-txn-icon" style="background:<?= $ic[1] ?>"><?= $ic[0] ?></div>
        <div class="mc-txn-info">
            <div class="mc-txn-desc"><?= h(substr($tx['desc'], 0, 45)) ?></div>
            <div class="mc-txn-meta"><?= substr($tx['date']??'', 0, 10) ?><?php if ($tx['status']??''): ?><span class="mc-pill" style="background:<?= $stC[0] ?>;color:<?= $stC[1] ?>"><?= ucfirst($tx['status']) ?></span><?php endif; ?></div>
        </div>
        <?php $_txIsSsp = (($tx["currency"]??"USD")==="SSP"||((float)($tx["ssp_amount"]??0))>0); $_txAmt = $_txIsSsp ? ((float)($tx["ssp_amount"]??0)>0?(float)$tx["ssp_amount"]:(float)$tx["amount"]) : (float)$tx["amount"]; ?><div class="mc-txn-amt <?= $tx["dir"]==="in"?"mc-in":"mc-out" ?>"><?= $tx["dir"]==="in"?"+":"-" ?><?= $_txIsSsp ? number_format($_txAmt,0)." SSP" : "$".number_format($_txAmt,2) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (count($txns) > 15): ?><div style="text-align:center;padding:10px;"><a href="?page=dashboard&tab=my_account&v=ledger" style="font-size:12px;color:#2563eb;font-weight:700;">View full ledger →</a></div><?php endif; ?>
    <?php if (count($txns) > 0 && count($txns) <= 15): ?><div style="text-align:center;padding:10px;"><a href="?page=dashboard&tab=my_account&v=ledger" style="font-size:12px;color:#94a3b8;font-weight:600;">All transactions →</a></div><?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($v === 'ledger'): ?>
<!-- ══ Full ledger view ══ -->
<div class="mc-card">
    <div class="mc-card-head">All Transactions (<?= count($txns) ?>)</div>
    <?php if (empty($txns)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;">No transactions</div>
    <?php else: ?>
    <?php
    $lastDate = '';
    foreach ($txns as $tx):
        $d = substr($tx['date']??'', 0, 10);
        $ic = ['advance'=>['📥','#dcfce7'],'collection'=>['💳','#dbeafe'],'handover'=>['🏦','#fef2f2'],'expense'=>['🧾','#fef3c7'],'transfer'=>['↔️','#f3e8ff']][$tx['type']] ?? ['📄','#f1f5f9'];
        $stC = ['active'=>['#dcfce7','#065f46'],'approved'=>['#dcfce7','#065f46'],'pending'=>['#fef3c7','#92400e'],'confirmed'=>['#dcfce7','#065f46'],'partial'=>['#fef3c7','#92400e'],'rejected'=>['#fef2f2','#991b1b']][$tx['status']??''] ?? ['#f1f5f9','#64748b'];
        if ($d !== $lastDate):
            $lastDate = $d;
    ?>
    <div style="padding:6px 16px;background:#f8fafc;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e2e8f0;"><?= $d ?></div>
    <?php endif; ?>
    <div class="mc-txn">
        <div class="mc-txn-icon" style="background:<?= $ic[1] ?>"><?= $ic[0] ?></div>
        <div class="mc-txn-info">
            <div class="mc-txn-desc"><?= h(substr($tx['desc'], 0, 45)) ?></div>
            <div class="mc-txn-meta"><?= ucfirst($tx['type']) ?><?php if ($tx['status']??''): ?><span class="mc-pill" style="background:<?= $stC[0] ?>;color:<?= $stC[1] ?>"><?= ucfirst($tx['status']) ?></span><?php endif; ?></div>
        </div>
        <?php $_txIsSsp = (($tx["currency"]??"USD")==="SSP"||((float)($tx["ssp_amount"]??0))>0); $_txAmt = $_txIsSsp ? ((float)($tx["ssp_amount"]??0)>0?(float)$tx["ssp_amount"]:(float)$tx["amount"]) : (float)$tx["amount"]; ?><div class="mc-txn-amt <?= $tx["dir"]==="in"?"mc-in":"mc-out" ?>"><?= $tx["dir"]==="in"?"+":"-" ?><?= $_txIsSsp ? number_format($_txAmt,0)." SSP" : "$".number_format($_txAmt,2) ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; // end ledger ?>

<?php elseif ($v === 'ssp_book'): ?>
<!-- ══════════════════════════════════════════════════ SSP CASHBOOK ═══ -->
<?php
// One-time fix: backfill ssp_amount for SSP expenses where it was never set
try { $store->getPdo()->exec("UPDATE staff_expenses SET ssp_amount = amount WHERE currency = 'SSP' AND (ssp_amount = 0 OR ssp_amount IS NULL) AND amount > 0"); } catch (\Throwable $e) {}

// ── Excel download handler ────────────────────────────────────────────────────
if (!empty($_GET['dl']) && $_GET['dl'] === 'xlsx') {
    // Build rows first, then output CSV with Excel-compatible encoding
    $__dlCashIn    = array_filter($store->load('cash_ins.json') ?: [], fn($i) => (int)($i['collector_id'] ?? 0) === $agentId);
    $__dlJsonExps  = array_filter($store->load('cash_expenses.json') ?: [], fn($e) => (int)($e['collector_id'] ?? 0) === $agentId);
    $__dlHandovers = array_filter($store->load('cash_handovers.json') ?: [], fn($h) => (int)($h['from_id'] ?? 0) === $agentId);
    $__dlSqlExps = [];
    try { $__s = $store->getPdo()->prepare("SELECT * FROM staff_expenses WHERE staff_id=? AND status IN ('approved','pending') ORDER BY submitted_at DESC"); $__s->execute([$agentId]); $__dlSqlExps = $__s->fetchAll(PDO::FETCH_ASSOC); } catch (\Throwable $e) {}
    $__dlRows = []; $__dlBal = 0;
    foreach ($__dlCashIn as $ci) {
        if (in_array($ci['status'] ?? 'approved', ['rejected','voided'])) continue;
        $sspAmt = (float)($ci['ssp_amount'] ?? 0);
        if ($sspAmt <= 0) continue;
        $__dlRows[] = ['date' => substr($ci['created_at']??'',0,10), 'time' => substr($ci['created_at']??'',11,5), 'dir' => 'IN', 'amt' => $sspAmt, 'cat' => $ci['category']??'SSP Received', 'desc' => $ci['description']??'', 'status' => $ci['status']??'approved'];
    }
    $__dlAllExps = $__dlSqlExps;
    foreach ($__dlJsonExps as $je) { if (!in_array($je['id']??0, array_column($__dlSqlExps,'legacy_json_id'))) { $__dlAllExps[] = ['currency'=>$je['currency']??'SSP','amount'=>$je['amount']??0,'ssp_amount'=>$je['ssp_amount']??0,'category'=>$je['category']??'Expense','description'=>$je['description']??'','status'=>$je['status']??'pending','submitted_at'=>$je['submitted_at']??'']; } }
    foreach ($__dlAllExps as $e) {
        if (($e['currency']??'USD') !== 'SSP') continue;
        if (in_array($e['status']??'',['voided','cancelled','rejected'])) continue;
        $amt = (float)($e['ssp_amount']??0) ?: (float)($e['amount']??0);
        if ($amt <= 0) continue;
        $__dlRows[] = ['date' => substr($e['submitted_at']??'',0,10), 'time' => substr($e['submitted_at']??'',11,5), 'dir' => 'OUT', 'amt' => $amt, 'cat' => $e['category']??'Expense', 'desc' => ($e['category']??'Expense').($e['description']?' — '.$e['description']:''), 'status' => $e['status']??''];
    }
    foreach ($__dlHandovers as $h) {
        if (($h['status']??'') !== 'confirmed' || ($h['currency']??'USD') !== 'SSP') continue;
        $amt = (float)($h['ssp_amount']??$h['amount']??0);
        if ($amt <= 0) continue;
        $__dlRows[] = ['date' => substr($h['created_at']??'',0,10), 'time' => substr($h['created_at']??'',11,5), 'dir' => 'OUT', 'amt' => $amt, 'cat' => 'Handover', 'desc' => 'Returned to '.($h['to_name']??'Office'), 'status' => 'confirmed'];
    }
    usort($__dlRows, fn($a,$b) => strcmp($a['date'].$a['time'], $b['date'].$b['time']));
    foreach ($__dlRows as &$__r) { $__dlBal += ($__r['dir']==='IN'?1:-1)*(float)$__r['amt']; $__r['balance'] = $__dlBal; } unset($__r);

    $fname = 'ssp-cashbook-' . strtolower(str_replace(' ','-',$agentName)) . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date','Time','Direction','Category','Description','Amount (SSP)','Running Balance (SSP)','Status']);
    foreach ($__dlRows as $row) {
        fputcsv($out, [$row['date'], $row['time'], $row['dir'], $row['cat'], $row['desc'], number_format($row['amt'],0,'.',','), number_format($row['balance'],0,'.',','), $row['status']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['','','','','CLOSING BALANCE', number_format(max(0,$__dlBal),0,'.',','), '', date('d M Y H:i')]);
    fclose($out);
    exit;
}

$_bkCashIn    = array_filter($store->load('cash_ins.json') ?: [], fn($i) => (int)($i['collector_id'] ?? 0) === $agentId);
$_bkJsonExps  = array_filter($store->load('cash_expenses.json') ?: [], fn($e) => (int)($e['collector_id'] ?? 0) === $agentId);
$_bkHandovers = array_filter($store->load('cash_handovers.json') ?: [], fn($h) => (int)($h['from_id'] ?? 0) === $agentId);

// ── Handovers RECEIVED (from staff_ledger HOV-IN entries) ────────────────
// When another staff hands over to this person (e.g. Joel → Diko),
// the HOV-IN ledger entry is the cash IN for the receiver.
$_bkHovReceived = [];
try {
    $_hovStmt = $store->getPdo()->prepare(
        "SELECT * FROM staff_ledger
         WHERE staff_id = ?
           AND idempotency_key LIKE 'HOV-IN-%'
           AND status NOT IN ('voided','cancelled')
         ORDER BY event_date ASC"
    );
    $_hovStmt->execute([$agentId]);
    $_bkHovReceived = $_hovStmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Also read from staff_expenses SQLite (where ExpenseAdvanceService writes)
$_bkSqlExps = [];
try {
    $_bkStmt = $store->getPdo()->prepare("SELECT * FROM staff_expenses WHERE staff_id = ? AND status IN ('approved','pending') ORDER BY submitted_at DESC");
    $_bkStmt->execute([$agentId]);
    $_bkSqlExps = $_bkStmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Merge: use SQLite expenses, add JSON expenses that aren't duplicated
$_bkAllExps = $_bkSqlExps;
$_bkSqlIds = array_column($_bkSqlExps, 'legacy_json_id');
foreach ($_bkJsonExps as $je) {
    $jid = (int)($je['id'] ?? 0);
    if ($jid > 0 && in_array($jid, $_bkSqlIds)) continue; // already in SQLite
    // Normalize JSON expense to look like SQLite row
    $_bkAllExps[] = [
        'currency'    => $je['currency'] ?? 'USD',
        'amount'      => $je['amount'] ?? 0,
        'ssp_amount'  => $je['ssp_amount'] ?? 0,
        'category'    => $je['category'] ?? $je['expense_type'] ?? 'Other',
        'description' => $je['description'] ?? '',
        'status'      => $je['status'] ?? 'pending',
        'submitted_at'=> $je['submitted_at'] ?? $je['created_at'] ?? '',
    ];
}

$_bkRows = [];
foreach ($_bkCashIn as $ci) {
    if (in_array($ci['status'] ?? 'approved', ['rejected','voided'])) continue;
    $sspAmt = (float)($ci['ssp_amount'] ?? 0);
    if ($sspAmt <= 0 && !in_array($ci['category'] ?? '', ['SSP Received','Exchange'])) continue;
    $desc = $ci['description'] ?? $ci['category'] ?? 'SSP Received';
    // Strip ALL leading "From " prefixes to avoid "From From..."
    while (stripos($desc, 'From ') === 0) $desc = substr($desc, 5);
    $_bkRows[] = ['date'=>$ci['created_at']??'','dir'=>'IN','amt'=>$sspAmt,
        'desc'=>$desc, 'cat'=>$ci['category']??'SSP Received',
        'status'=>$ci['status']??'approved'];
}
foreach ($_bkAllExps as $e) {
    // Support staff: include ALL expenses (their expenses are SSP even if tagged USD)
    // For non-support: only SSP-tagged
    $isSsp = ($e['currency']??'USD') === 'SSP';
    $sspAmt = (float)($e['ssp_amount']??0);
    $usdAmt = (float)($e['amount']??0);
    if (!$_mcIsSupport && !$isSsp) continue;
    if (in_array($e['status']??'',['voided','cancelled','rejected'])) continue;
    // Use ssp_amount if > 0, else amount
    $amt = $sspAmt > 0 ? $sspAmt : $usdAmt;
    if ($amt <= 0) continue;
    $_bkRows[] = ['date'=>$e['submitted_at']??$e['created_at']??'','dir'=>'OUT','amt'=>$amt,
        'desc'=>($e['category']??'Expense').($e['description']?' — '.$e['description']:''),
        'cat'=>$e['category']??'Expense','status'=>$e['status']??'pending'];
}
foreach ($_bkHandovers as $h) {
    if (($h['status']??'')!=='confirmed') continue;
    if (($h['currency']??'USD')!=='SSP') continue;
    $amt = (float)($h['ssp_amount']??$h['amount']??0);
    $_bkRows[] = ['date'=>$h['created_at']??'','dir'=>'OUT','amt'=>$amt,
        'desc'=>'Returned to '.($h['to_name']??'Office'),'cat'=>'Handover','status'=>'confirmed'];
}
// ── Handovers RECEIVED — cash IN for receiver (Diko / Rupesh) ────────────
foreach ($_bkHovReceived as $_hr) {
    $cur = strtoupper($_hr['currency'] ?? 'USD');
    if ($cur !== 'SSP') continue;
    $amt = (float)($_hr['ssp_amount'] ?? $_hr['amount'] ?? 0);
    if ($amt <= 0) continue;
    $_bkRows[] = [
        'date'   => $_hr['event_date'] ?? $_hr['created_at'] ?? '',
        'dir'    => 'IN',
        'amt'    => $amt,
        'desc'   => 'From ' . ($_hr['counterparty_name'] ?? 'Staff') . ' — handover received',
        'cat'    => 'Handover Received',
        'status' => 'confirmed',
    ];
}
usort($_bkRows, fn($a,$b)=>strcmp($a['date'],$b['date'])); // chronological
$_bkBal = 0;
foreach ($_bkRows as &$_br) { $_bkBal += ($_br['dir']==='IN'?1:-1)*(float)$_br['amt']; $_br['bal']=$_bkBal; }
unset($_br);
$_bkRows = array_reverse($_bkRows); // newest first for display
?>

<div style="background:linear-gradient(135deg,#c2410c,#ea580c);border-radius:16px;padding:16px;color:#fff;margin-bottom:12px;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.7;">🇸🇸 SSP Cashbook — <?= h($agentName) ?></div>
    <div style="font-size:32px;font-weight:900;margin-top:4px;"><?= number_format(max(0,$_bkBal),0) ?> <span style="font-size:14px;">SSP</span></div>
    <div style="font-size:11px;opacity:.7;margin-top:2px;"><?= count($_bkRows) ?> transactions</div>
    <div style="margin-top:10px;">
        <a href="?page=dashboard&tab=my_account&v=ssp_book&dl=xlsx"
           style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:10px;padding:7px 14px;text-decoration:none;color:#fff;font-size:12px;font-weight:700;">
            📥 Download Excel
        </a>
    </div>
</div>

<?php if (empty($_bkRows)): ?>
<div style="text-align:center;padding:40px;color:#94a3b8;">No SSP transactions yet</div>
<?php else: ?>
<?php foreach ($_bkRows as $_br): ?>
<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fff;border-bottom:1px solid #f1f5f9;">
    <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;background:<?= $_br['dir']==='IN'?'#dcfce7':'#fef2f2' ?>;">
        <?= $_br['dir']==='IN'?'📥':'📤' ?>
    </div>
    <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($_br['desc']) ?></div>
        <div style="font-size:10px;color:#94a3b8;"><?= date('d M Y · H:i', strtotime($_br['date'])) ?><?php if(($_br['status']??'')==='pending'): ?> · <span style="color:#d97706;">⏳ pending</span><?php endif; ?></div>
    </div>
    <div style="text-align:right;">
        <div style="font-size:14px;font-weight:800;color:<?= $_br['dir']==='IN'?'#059669':'#dc2626' ?>;"><?= $_br['dir']==='IN'?'+':'-' ?><?= number_format((float)$_br['amt'],0) ?></div>
        <div style="font-size:10px;color:#64748b;">Bal: <?= number_format(max(0,$_br['bal']),0) ?></div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php elseif ($v === 'usd_book'): ?>
<!-- ══════════════════════════════════════════════════ USD CASHBOOK ═══ -->
<?php
$_ubCashIn    = array_filter($store->load('cash_ins.json') ?: [], fn($i) => (int)($i['collector_id'] ?? 0) === $agentId);
$_ubMyExps    = array_filter($store->load('cash_expenses.json') ?: [], fn($e) => (int)($e['collector_id'] ?? 0) === $agentId);
$_ubHandovers = array_filter($store->load('cash_handovers.json') ?: [], fn($h) => (int)($h['from_id'] ?? 0) === $agentId);

// ── USD collections from staff_ledger (COL-* entries) ────────────────────────
// Payment collections (collect_payment tab) write to staff_ledger as COL-*
// but NOT to cash_ins.json — so they're invisible to the USD Received filter below.
$_ubColRows = [];
try {
    $_ubColStmt = $store->getPdo()->prepare(
        "SELECT amount, description, event_date, idempotency_key, status
         FROM staff_ledger
         WHERE staff_id = ?
           AND direction = 'in'
           AND currency = 'USD'
           AND category = 'collection'
           AND idempotency_key LIKE 'COL-%'
           AND status NOT IN ('voided','cancelled')
         ORDER BY event_date ASC"
    );
    $_ubColStmt->execute([$agentId]);
    $_ubColRows = $_ubColStmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

$_ubRows = [];
// ── USD Received from cash_ins.json (advances, exchanges, etc.) ──────────────
// Exclude personal pay — salary/allowance not field cash (matches StaffLedgerWriter filter)
$_ubPersonalKw = ['salary','transport allowance','food allowance','bonus','employee benefit'];
foreach ($_ubCashIn as $ci) {
    if (in_array($ci['status'] ?? 'approved', ['rejected','voided'])) continue;
    if (($ci['category']??'') !== 'USD Received') continue;
    $amt = (float)($ci['amount']??0);
    if ($amt <= 0) continue;
    $_ubDesc2 = strtolower($ci['description'] ?? '');
    $_ubIsPersonal = false;
    foreach ($_ubPersonalKw as $_kw) { if (strpos($_ubDesc2, $_kw) !== false) { $_ubIsPersonal = true; break; } }
    if ($_ubIsPersonal) continue;
    $_ubDesc = $ci['description'] ?? 'Office';
    while (stripos($_ubDesc, 'From ') === 0) $_ubDesc = substr($_ubDesc, 5);
    $_ubRows[] = ['date'=>$ci['created_at']??'','dir'=>'IN','amt'=>$amt,
        'desc'=>$_ubDesc, 'cat'=>'USD Received',
        'status'=>$ci['status']??'approved'];
}
// ── USD collections from staff_ledger ────────────────────────────────────────
foreach ($_ubColRows as $_uc) {
    $amt = (float)($_uc['amount'] ?? 0);
    if ($amt <= 0) continue;
    $_ubRows[] = [
        'date'   => $_uc['event_date'] ?? '',
        'dir'    => 'IN',
        'amt'    => $amt,
        'desc'   => $_uc['description'] ?? 'Customer payment',
        'cat'    => 'Collection',
        'status' => 'approved',
    ];
}
foreach ($_ubMyExps as $e) {
    if (($e['currency']??'USD')!=='USD') continue;
    if (in_array($e['status']??'',['voided','cancelled','rejected'])) continue;
    $_ubRows[] = ['date'=>$e['submitted_at']??$e['created_at']??'','dir'=>'OUT','amt'=>(float)($e['amount']??0),
        'desc'=>($e['category']??'Expense').($e['description']?' — '.substr($e['description'],0,30):''),
        'cat'=>$e['category']??'Expense','status'=>$e['status']??'pending'];
}
foreach ($_ubHandovers as $h) {
    if (($h['status']??'')!=='confirmed') continue;
    if (strtoupper($h['currency']??'USD')!=='USD') continue;
    $_ubRows[] = ['date'=>$h['created_at']??'','dir'=>'OUT','amt'=>(float)($h['amount']??0),
        'desc'=>'Returned to '.($h['to_name']??'Office'),'cat'=>'Handover','status'=>'confirmed'];
}
// ── Handovers RECEIVED — USD cash IN for receiver ─────────────────────────
$_ubHovRcv = [];
try {
    $_ubHovStmt = $store->getPdo()->prepare(
        "SELECT * FROM staff_ledger
         WHERE staff_id = ?
           AND idempotency_key LIKE 'HOV-IN-%'
           AND currency = 'USD'
           AND status NOT IN ('voided','cancelled')
         ORDER BY event_date ASC"
    );
    $_ubHovStmt->execute([$agentId]);
    $_ubHovRcv = $_ubHovStmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
foreach ($_ubHovRcv as $_uhr) {
    $amt = (float)($_uhr['amount'] ?? 0);
    if ($amt <= 0) continue;
    $_ubRows[] = [
        'date'   => $_uhr['event_date'] ?? $_uhr['created_at'] ?? '',
        'dir'    => 'IN',
        'amt'    => $amt,
        'desc'   => 'From ' . ($_uhr['counterparty_name'] ?? 'Staff') . ' — handover received',
        'cat'    => 'Handover Received',
        'status' => 'confirmed',
    ];
}
usort($_ubRows, fn($a,$b)=>strcmp($a['date'],$b['date']));
$_ubBal = 0;
foreach ($_ubRows as &$_ur) { $_ubBal += ($_ur['dir']==='IN'?1:-1)*(float)$_ur['amt']; $_ur['bal']=$_ubBal; }
unset($_ur);
$_ubRows = array_reverse($_ubRows);
?>

<div style="background:linear-gradient(135deg,#059669,#047857);border-radius:16px;padding:16px;color:#fff;margin-bottom:12px;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.7;">💵 USD Cashbook — <?= h($agentName) ?></div>
    <div style="font-size:32px;font-weight:900;margin-top:4px;">$<?= number_format(max(0,$_ubBal),2) ?></div>
    <div style="font-size:11px;opacity:.7;margin-top:2px;"><?= count($_ubRows) ?> transactions</div>
</div>

<?php if (empty($_ubRows)): ?>
<div style="text-align:center;padding:40px;color:#94a3b8;">No USD transactions yet</div>
<?php else: ?>
<?php foreach ($_ubRows as $_ur): ?>
<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fff;border-bottom:1px solid #f1f5f9;">
    <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;background:<?= $_ur['dir']==='IN'?'#dcfce7':'#fef2f2' ?>;">
        <?= $_ur['dir']==='IN'?'📥':'📤' ?>
    </div>
    <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($_ur['desc']) ?></div>
        <div style="font-size:10px;color:#94a3b8;"><?= date('d M Y · H:i', strtotime($_ur['date'])) ?><?php if(($_ur['status']??'')==='pending'): ?> · <span style="color:#d97706;">⏳ pending</span><?php endif; ?></div>
    </div>
    <div style="text-align:right;">
        <div style="font-size:14px;font-weight:800;color:<?= $_ur['dir']==='IN'?'#059669':'#dc2626' ?>;"><?= $_ur['dir']==='IN'?'+$':'-$' ?><?= number_format((float)$_ur['amt'],2) ?></div>
        <div style="font-size:10px;color:#64748b;">Bal: $<?= number_format(max(0,$_ur['bal']),2) ?></div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php elseif ($v === 'expense'): ?>
<!-- ══════════════════════════════════════════════════ CASH OUT ═══ -->

<!-- Pending expenses count -->
<?php $pendExp = array_filter($recentExpenses, function($e) { return ($e['status']??'') === 'pending'; }); ?>
<?php if (!empty($pendExp) && !in_array($userRole??'', ['accountant','field_accountant'])): ?>
<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:12px;padding:12px 16px;margin-bottom:12px;font-size:12px;font-weight:600;color:#92400e;">
    ⏳ <?= count($pendExp) ?> expense<?= count($pendExp)>1?'s':'' ?> pending approval
</div>
<?php endif; ?>

<div class="mc-card" style="padding:16px;">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:4px;">💸 Record Cash Out</div>
    <div style="font-size:11px;color:#94a3b8;margin-bottom:14px;">Any cash you paid out — transport, supplies, field costs, etc.</div>
    <form method="POST" action="?page=dashboard&tab=my_account&v=expense" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="mc_action" value="submit_expense">
        <input type="hidden" name="submitted_via" value="web">

        <label class="mc-label">Project</label>
        <?php $_expProjs = $retailer['projects'] ?? (!empty($retailer['project']) ? [$retailer['project']] : ['dishnet']); if (!is_array($_expProjs)) $_expProjs = [$_expProjs]; ?>
        <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
            <?php $_epStyles = ['dishnet'=>['DishNet Fiber & Starlink','#1565C0','#E3F2FD'],'4g'=>['DishNet 4G','#E65100','#FFF3E0'],'bluecard'=>['BlueCARD','#2E7D32','#E8F5E9']]; ?>
            <?php $_epFirst = true; foreach ($_epStyles as $_epk => $_epv): if (!in_array($_epk, $_expProjs) && !($isAdmin ?? false)) continue; ?>
            <label style="display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:10px;cursor:pointer;font-size:12px;font-weight:700;border:2px solid <?= $_epv[2] ?>;color:<?= $_epv[1] ?>;background:<?= $_epv[2] ?>;">
                <input type="radio" name="project" value="<?= $_epk ?>" <?= $_epFirst ? 'checked' : '' ?> style="accent-color:<?= $_epv[1] ?>;"> <?= $_epv[0] ?>
            </label>
            <?php $_epFirst = false; endforeach; ?>
        </div>

        <?php $_mcDefaultSSP = $_mcIsSupport && $_mcSspBal2 > 0; ?>
        <div style="display:flex;gap:0;margin-bottom:12px;border-radius:10px;overflow:hidden;border:2px solid #e2e8f0;">
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:<?= $_mcDefaultSSP ? '#f8fafc' : '#f0fdf4' ?>;color:<?= $_mcDefaultSSP ? '#9ca3af' : '#15803d' ?>;" id="mc_cur_usd">
                <input type="radio" name="currency" value="USD" <?= $_mcDefaultSSP ? '' : 'checked' ?> style="display:none;" onchange="mcCur('USD')"> 💵 USD</label>
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:<?= $_mcDefaultSSP ? '#fff7ed' : '#f8fafc' ?>;color:<?= $_mcDefaultSSP ? '#c2410c' : '#9ca3af' ?>;" id="mc_cur_ssp">
                <input type="radio" name="currency" value="SSP" <?= $_mcDefaultSSP ? 'checked' : '' ?> style="display:none;" onchange="mcCur('SSP')"> 🇸🇸 SSP</label>
        </div>

        <label class="mc-label">Amount</label>
        <input type="number" name="amount" class="mc-input" placeholder="0.00" step="0.01" min="0.01" required style="font-size:20px;font-weight:800;text-align:center;">
        <?php if ($_mcIsSupport && $_mcSspBal2 > 0): ?>
        <div id="mc_ssp_hint" style="text-align:center;font-size:11px;color:#c2410c;font-weight:600;margin:-8px 0 12px;<?= $_mcDefaultSSP ? '' : 'display:none;' ?>">🇸🇸 Available: <?= number_format($_mcSspBal2, 0) ?> SSP</div>
        <?php endif; ?>

        <label class="mc-label">Category</label>
        <select name="category" class="mc-input" required>
            <?php foreach ($expCategories as $k => $lbl): ?><option value="<?= $k ?>"><?= $lbl ?></option><?php endforeach; ?>
        </select>

        <label class="mc-label">Description</label>
        <input type="text" name="description" class="mc-input" placeholder="What was this expense for?" required>

        <label class="mc-label">Date</label>
        <input type="date" name="expense_date" class="mc-input" value="<?= date('Y-m-d') ?>">

        <?php if (!empty($activeAdvances)): ?>
        <label class="mc-label">Link to Advance (optional)</label>
        <select name="advance_id" class="mc-input">
            <option value="">— No advance —</option>
            <?php foreach ($activeAdvances as $adv): ?><option value="<?= $adv['id'] ?>"><?= h($adv['advance_no']) ?> — $<?= number_format($adv['balance'], 2) ?> left</option><?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label class="mc-label">Receipt Photo</label>
        <label style="background:#f8fafc;border:2px dashed #d1d5db;border-radius:12px;padding:14px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;margin-bottom:12px;">
            <span style="font-size:22px;">📸</span>
            <span style="font-size:12px;font-weight:700;color:#374151;" id="mc_rcpt_name">Tap to take photo</span>
            <input type="file" name="receipt" accept="image/*" capture="environment" style="display:none;" onchange="document.getElementById('mc_rcpt_name').textContent=this.files[0]?.name||'Tap to upload'">
        </label>

        <button type="submit" class="mc-btn" style="background:#d97706;color:#fff;">💸 Submit Cash Out</button>
    </form>
</div>

<!-- Recent expenses -->
<?php if (!empty($recentExpenses)): ?>
<div class="mc-card">
    <div class="mc-card-head">Expense History</div>
    <?php foreach (array_slice($recentExpenses, 0, 10) as $exp):
        $sc = ['pending'=>['#fef3c7','#92400e'],'approved'=>['#dcfce7','#15803d'],'rejected'=>['#fee2e2','#991b1b']][$exp['status']] ?? ['#f1f5f9','#374151'];
        $catIc = ['fuel'=>'⛽','parts'=>'🔧','transport'=>'🚗','allowance'=>'💰','food'=>'🍽','other'=>'📦'][$exp['category']??''] ?? '📦';
        $ec = strtoupper($exp['currency'] ?? 'USD');
    ?>
    <div class="mc-txn">
        <div class="mc-txn-icon" style="background:#fef3c7"><?= $catIc ?></div>
        <div class="mc-txn-info">
            <div class="mc-txn-desc"><?= $ec==='SSP' ? number_format((float)$exp['amount']).' SSP' : '$'.number_format((float)$exp['amount'],2) ?> · <?= h($exp['category']??'') ?></div>
            <div class="mc-txn-meta"><?= h(substr($exp['expense_date']??$exp['submitted_at']??'',0,10)) ?> <span class="mc-pill" style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>"><?= ucfirst($exp['status']) ?></span></div>
        </div>
        <?php if (!empty($exp['receipt_path'])): ?><a href="javascript:void(0)" onclick="dnLbOpen('?page=api&action=expense_photo&id=<?= (int)$exp['id'] ?>')" style="font-size:16px;text-decoration:none;cursor:pointer;" title="View receipt photo">🧾</a><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($v === 'handover'): ?>
<!-- ══════════════════════════════════════════════════ HANDOVER ═══ -->

<?php
$myHov = array_filter($store->load('cash_handovers.json') ?? [], function($h) use ($agentId) { return (int)($h['from_id']??0)===$agentId; });
$pendHov = array_filter($myHov, function($h) { return ($h['status']??'') === 'pending'; });
$confHov = array_filter($myHov, function($h) { return ($h['status']??'') === 'confirmed'; });
$pendTotal = round(array_sum(array_map(function($h) { return (float)($h['amount']??0); }, $pendHov)), 2);
$confTotal = round(array_sum(array_map(function($h) { return (float)($h['amount']??0); }, $confHov)), 2);
?>

<?php if ($pendTotal > 0): ?>
<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:12px;padding:12px 16px;margin-bottom:12px;font-size:12px;font-weight:600;color:#92400e;">
    ⏳ $<?= number_format($pendTotal, 2) ?> in <?= count($pendHov) ?> pending handover<?= count($pendHov)>1?'s':'' ?> (waiting confirmation)
</div>
<?php endif; ?>

<div class="mc-card" style="padding:16px;">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:4px;">Hand Over Cash</div>
    <div style="font-size:12px;color:#6b7280;margin-bottom:14px;">Submit cash you're handing to the office. One handover per project.</div>
    <form method="POST" action="?page=dashboard&tab=my_account">
        <?= csrfField() ?>
        <input type="hidden" name="mc_action" value="submit_handover">

        <label class="mc-label">Which project book?</label>
        <?php $_myProjs = $retailer['projects'] ?? (!empty($retailer['project']) ? [$retailer['project']] : ['dishnet']); if (!is_array($_myProjs)) $_myProjs = [$_myProjs]; ?>
        <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
            <?php $_hovProjStyles = ['dishnet'=>['DishNet Fiber & Starlink','#1565C0','#E3F2FD'],'4g'=>['DishNet 4G','#E65100','#FFF3E0'],'bluecard'=>['BlueCARD','#2E7D32','#E8F5E9']]; ?>
            <?php $_hovFirst = true; foreach ($_hovProjStyles as $_hpk => $_hpv): if (!in_array($_hpk, $_myProjs) && !($isAdmin ?? false)) continue; ?>
            <label style="display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;cursor:pointer;font-size:13px;font-weight:700;border:2px solid <?= $_hpv[2] ?>;color:<?= $_hpv[1] ?>;background:<?= $_hpv[2] ?>;">
                <input type="radio" name="hov_project" value="<?= $_hpk ?>" <?= $_hovFirst ? 'checked' : '' ?> style="accent-color:<?= $_hpv[1] ?>;"> <?= $_hpv[0] ?>
            </label>
            <?php $_hovFirst = false; endforeach; ?>
        </div>

        <label class="mc-label">Amount (USD)</label>
        <input type="number" name="hov_amount" class="mc-input" placeholder="0.00" step="0.01" min="0.01" required style="font-size:20px;font-weight:800;text-align:center;">

        <label class="mc-label">Handing over to</label>
        <input type="hidden" name="to_staff_id" id="hovToId" value="" required>
        <input type="hidden" name="to_staff_name" id="hovToName" value="">
        <div style="position:relative;">
          <input type="text" id="hovStaffSearch" class="mc-input" placeholder="🔍 Type name..." autocomplete="off"
                 style="font-size:14px;" onfocus="hovShowList()" oninput="hovFilter(this.value)">
          <div id="hovSelected" style="display:none;margin-top:6px;padding:10px 14px;background:#dcfce7;border-radius:10px;font-size:13px;font-weight:700;color:#065f46;">
            ✅ <span id="hovSelName"></span>
            <button type="button" onclick="hovClear()" style="float:right;background:none;border:none;color:#dc2626;font-weight:800;cursor:pointer;font-size:14px;">✕</button>
          </div>
          <div id="hovStaffList" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:50;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;margin-top:4px;">
            <?php
            $_hovAllStaff = $auth->getAllRetailers();
            foreach ($_hovAllStaff as $_hs) {
                if ((int)$_hs['id'] === $agentId) continue;
                if (empty($_hs['is_active'] ?? true)) continue;
                $role = $_hs['role'] ?? '';
                if (!in_array($role, ['accountant','field_accountant','admin'], true) && empty($_hs['is_admin'])) continue;
                $badge = $role === 'accountant' ? 'Accountant' : ($role === 'field_accountant' ? 'Field Accountant' : 'Admin');
                $initial = mb_substr($_hs['name'] ?? '?', 0, 1);
            ?>
            <div class="hov-staff-opt" data-id="<?= (int)$_hs['id'] ?>" data-name="<?= htmlspecialchars($_hs['name'] ?? '') ?>" data-search="<?= strtolower($_hs['name'] ?? '') ?>"
                 onclick="hovPick(<?= (int)$_hs['id'] ?>,'<?= htmlspecialchars(addslashes($_hs['name'] ?? '')) ?>')"
                 style="display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;border-bottom:1px solid #f8fafc;">
              <div style="width:32px;height:32px;border-radius:50%;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#1d4ed8;flex-shrink:0;"><?= $initial ?></div>
              <div style="flex:1;">
                <div style="font-size:13px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($_hs['name'] ?? '') ?></div>
                <div style="font-size:10px;color:#94a3b8;"><?= $badge ?></div>
              </div>
            </div>
            <?php } ?>
          </div>
        </div>
        <script>
        function hovShowList(){document.getElementById('hovStaffList').style.display='block';}
        function hovFilter(v){
          var q=v.toLowerCase();
          document.querySelectorAll('.hov-staff-opt').forEach(function(el){
            el.style.display=el.dataset.search.indexOf(q)>=0?'flex':'none';
          });
          document.getElementById('hovStaffList').style.display='block';
        }
        function hovPick(id,name){
          document.getElementById('hovToId').value=id;
          document.getElementById('hovToName').value=name;
          document.getElementById('hovStaffSearch').style.display='none';
          document.getElementById('hovStaffList').style.display='none';
          document.getElementById('hovSelected').style.display='block';
          document.getElementById('hovSelName').textContent=name;
        }
        function hovClear(){
          document.getElementById('hovToId').value='';
          document.getElementById('hovToName').value='';
          document.getElementById('hovStaffSearch').value='';
          document.getElementById('hovStaffSearch').style.display='';
          document.getElementById('hovSelected').style.display='none';
          document.getElementById('hovStaffList').style.display='none';
        }
        document.addEventListener('click',function(e){
          var list=document.getElementById('hovStaffList');
          var inp=document.getElementById('hovStaffSearch');
          if(list&&!list.contains(e.target)&&e.target!==inp)list.style.display='none';
        });
        </script>

        <label class="mc-label">Note (optional)</label>
        <input type="text" name="hov_note" class="mc-input" placeholder="e.g. Collections from March 15">

        <button type="submit" class="mc-btn" style="background:#dc2626;color:#fff;">🏦 Submit Handover</button>
    </form>
</div>

<!-- Handover history -->
<?php if (!empty($myHov)): ?>
<div class="mc-card">
    <div class="mc-card-head">Handover History</div>
    <?php foreach (array_slice(array_reverse(array_values($myHov)), 0, 10) as $h):
        $hsc = ['pending'=>['#fef3c7','#92400e'],'confirmed'=>['#dcfce7','#065f46'],'rejected'=>['#fee2e2','#991b1b']][$h['status']??''] ?? ['#f1f5f9','#374151'];
    ?>
    <div class="mc-txn">
        <div class="mc-txn-icon" style="background:#fef2f2">🏦</div>
        <div class="mc-txn-info">
            <div class="mc-txn-desc">$<?= number_format((float)($h['amount']??0), 2) ?> to <?= h($h['to_name']??'Office') ?></div>
            <div class="mc-txn-meta"><?= h(substr($h['created_at']??'',0,10)) ?> <span class="mc-pill" style="background:<?= $hsc[0] ?>;color:<?= $hsc[1] ?>"><?= ucfirst($h['status']??'') ?></span></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($v === 'advance'): ?>
<!-- ══════════════════════════════════════════════════ ADVANCE ═══ -->

<!-- Active advances -->
<?php if (!empty($activeAdvances)): ?>
<div class="mc-card">
    <div class="mc-card-head">Active Advances</div>
    <?php foreach ($activeAdvances as $adv):
        $ac = strtoupper($adv['currency'] ?? 'USD');
    ?>
    <div class="mc-txn">
        <div class="mc-txn-icon" style="background:#dcfce7">📥</div>
        <div class="mc-txn-info">
            <div class="mc-txn-desc"><?= h($adv['advance_no']) ?><?php if ($adv['purpose']??''): ?> — <?= h(substr($adv['purpose'],0,30)) ?><?php endif; ?></div>
            <div class="mc-txn-meta">Issued: <?= h(substr($adv['issued_at']??'',0,10)) ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:14px;font-weight:900;color:#15803d;"><?= $ac==='SSP' ? number_format($adv['balance']).' SSP' : '$'.number_format($adv['balance'],2) ?></div>
            <div style="font-size:9px;color:#9ca3af;">of <?= $ac==='SSP' ? number_format((float)$adv['amount']).' SSP' : '$'.number_format((float)$adv['amount'],2) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="mc-card" style="padding:16px;">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:4px;">Request Cash Advance</div>
    <div style="font-size:12px;color:#6b7280;margin-bottom:14px;">Request funds from admin for field operations. You'll be notified when approved.</div>
    <form method="POST" action="?page=dashboard&tab=my_account&v=advance">
        <?= csrfField() ?>
        <input type="hidden" name="mc_action" value="request_advance">

        <div style="display:flex;gap:0;margin-bottom:12px;border-radius:10px;overflow:hidden;border:2px solid #e2e8f0;">
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:#f0fdf4;color:#15803d;" id="mc_adv_usd">
                <input type="radio" name="currency" value="USD" checked style="display:none;" onchange="mcAdvCur('USD')"> 💵 USD</label>
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:#f8fafc;color:#9ca3af;" id="mc_adv_ssp">
                <input type="radio" name="currency" value="SSP" style="display:none;" onchange="mcAdvCur('SSP')"> 🇸🇸 SSP</label>
        </div>

        <label class="mc-label">Amount Needed</label>
        <input type="number" name="amount" class="mc-input" placeholder="0.00" step="0.01" min="1" required style="font-size:20px;font-weight:800;text-align:center;">

        <label class="mc-label">Purpose</label>
        <textarea name="purpose" class="mc-input" rows="3" placeholder="What is this advance for?" required style="resize:vertical;"></textarea>

        <button type="submit" class="mc-btn" style="background:#1d4ed8;color:#fff;">💰 Request Advance</button>
    </form>
</div>
<?php elseif ($v === 'issue_advance' && ($userRole ?? '') === 'field_accountant'): ?>
<!-- ── Issue Advance to Staff (Diko only) ───────────────────────────── -->
<div style="padding:0 0 24px;">
    <div style="font-size:15px;font-weight:800;color:#0f172a;margin-bottom:4px;">💸 Issue Cash to Staff</div>
    <div style="font-size:12px;color:#64748b;margin-bottom:16px;">Give cash advance to a staff member. They will see it in their My Account as money in, and log their own expenses against it.</div>

    <?php
    // Show advances issued by Diko recently
    $issuedByMe = $expAdv->getAdvances(['issued_by_id' => $agentId, 'limit' => 10]);
    if (!empty($issuedByMe)):
    ?>
    <div style="margin-bottom:20px;">
        <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Recently Issued</div>
        <?php foreach ($issuedByMe as $ia): ?>
        <?php
        $iaStatus = $ia['status'] ?? 'active';
        $iaBg = $iaStatus === 'settled' ? '#f0fdf4' : ($iaStatus === 'active' ? '#eff6ff' : '#fef2f2');
        $iaClr = $iaStatus === 'settled' ? '#15803d' : ($iaStatus === 'active' ? '#1d4ed8' : '#dc2626');
        ?>
        <div style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-size:13px;font-weight:700;color:#0f172a;"><?= h($ia['recipient_name'] ?? '') ?></div>
                <div style="font-size:11px;color:#64748b;"><?= h($ia['advance_no'] ?? '') ?> · <?= substr($ia['issued_at'] ?? '', 0, 10) ?><?php if ($ia['purpose']??''): ?> · <?= h($ia['purpose']) ?><?php endif; ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:14px;font-weight:800;color:#0f172a;">$<?= number_format((float)($ia['amount'] ?? 0), 2) ?></div>
                <div style="font-size:10px;font-weight:700;background:<?= $iaBg ?>;color:<?= $iaClr ?>;padding:2px 7px;border-radius:6px;display:inline-block;"><?= ucfirst($iaStatus) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="?page=dashboard&tab=my_account&v=issue_advance">
        <input type="hidden" name="mc_action" value="issue_advance">

        <label class="mc-label">Staff Member</label>
        <?php
        $allAgents = $store->load('retailers.json') ?? [];
        $eligibleStaff = [];
        foreach ($allAgents as $ag) {
            if ((int)($ag['id'] ?? 0) === $agentId) continue;
            if (empty($ag['is_active'])) continue;
            $agRole = $ag['role'] ?? '';
            if (in_array($agRole, ['admin','accountant'], true)) continue;
            $eligibleStaff[] = $ag;
        }
        usort($eligibleStaff, fn($a,$b) => strcasecmp($a['name']??'', $b['name']??''));
        ?>
        <!-- Searchable staff picker -->
        <div style="position:relative;margin-bottom:12px;" id="staffPickerWrap">
            <input type="text" id="staffSearch" autocomplete="off"
                   placeholder="🔍 Search staff by name..."
                   style="width:100%;padding:12px 14px;border:2px solid #e2e8f0;border-radius:12px;font-size:15px;box-sizing:border-box;background:#fff;"
                   oninput="filterStaff(this.value)" onfocus="showStaffList()" onclick="showStaffList()">
            <input type="hidden" name="recipient_id" id="recipient_id_hidden" required>
            <div id="staffDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:999;background:#fff;border:2px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;max-height:240px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.12);">
                <?php foreach ($eligibleStaff as $ag): ?>
                <div class="staff-opt" data-id="<?= (int)$ag['id'] ?>" data-name="<?= h($ag['name']??'') ?>"
                     onclick="selectStaff(<?= (int)$ag['id'] ?>, '<?= addslashes($ag['name']??'') ?>', '<?= addslashes($ag['role']??'') ?>')"
                     style="padding:11px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:14px;font-weight:600;color:#1e293b;"><?= h($ag['name']??'') ?></span>
                    <span style="font-size:11px;color:#94a3b8;background:#f1f5f9;padding:2px 8px;border-radius:6px;"><?= h($ag['role']??'') ?></span>
                </div>
                <?php endforeach; ?>
                <div id="staffNoResults" style="display:none;padding:14px;text-align:center;color:#94a3b8;font-size:13px;">No staff found</div>
            </div>
        </div>
        <script>
        function showStaffList() {
            document.getElementById('staffDropdown').style.display = 'block';
        }
        function filterStaff(q) {
            q = q.toLowerCase();
            var opts = document.querySelectorAll('.staff-opt');
            var any = false;
            opts.forEach(function(el) {
                var name = (el.dataset.name || '').toLowerCase();
                var show = !q || name.indexOf(q) !== -1;
                el.style.display = show ? 'flex' : 'none';
                if (show) any = true;
            });
            document.getElementById('staffNoResults').style.display = any ? 'none' : 'block';
            document.getElementById('staffDropdown').style.display = 'block';
            // Clear selection when typing
            document.getElementById('recipient_id_hidden').value = '';
            document.getElementById('staffSearch').style.borderColor = '#e2e8f0';
        }
        function selectStaff(id, name, role) {
            document.getElementById('recipient_id_hidden').value = id;
            document.getElementById('staffSearch').value = name + ' (' + role + ')';
            document.getElementById('staffSearch').style.borderColor = '#7c3aed';
            document.getElementById('staffDropdown').style.display = 'none';
        }
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!document.getElementById('staffPickerWrap').contains(e.target)) {
                document.getElementById('staffDropdown').style.display = 'none';
            }
        });
        // Highlight on hover
        document.querySelectorAll('.staff-opt').forEach(function(el) {
            el.addEventListener('mouseenter', function() { this.style.background = '#f8fafc'; });
            el.addEventListener('mouseleave', function() { this.style.background = ''; });
        });
        </script>

        <label class="mc-label">Amount (USD)</label>
        <input type="number" name="amount" class="mc-input" placeholder="0.00" step="0.01" min="1" required style="font-size:20px;font-weight:800;text-align:center;">

        <label class="mc-label">Purpose</label>
        <select name="purpose" class="mc-input" style="font-size:14px;">
            <option value="field_work">Field Work</option>
            <option value="fuel">Fuel / Transport</option>
            <option value="ops">Operations</option>
            <option value="misc">Other</option>
        </select>

        <label class="mc-label">Note (optional)</label>
        <input type="text" name="description" class="mc-input" placeholder="e.g. Fuel for site visit Munuki" style="font-size:13px;">

        <button type="submit" class="mc-btn" style="background:#f59e0b;color:#fff;margin-top:8px;">💸 Issue Cash Advance</button>
    </form>
</div>
<?php elseif ($v === 'exchange'): ?>
<!-- ══ Currency Exchange View ══ -->
<?php
// Load current position for balance display
if (!class_exists('DualReadCashPosition')) require_once __DIR__ . '/../../lib/DualReadCashPosition.php';
$_exPos    = (new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? ''))->getPosition($agentId);
$_exUsd    = round((float)($_exPos['cash_exposure'] ?? 0), 2);
// v4.12.15 — SSP from JSON-first source (matches hero card + my_account guard)
if (!class_exists('StaffCashPositionService')) require_once __DIR__ . '/../../lib/StaffCashPositionService.php';
$_exSsp    = round((new StaffCashPositionService($store, $store->getPdo()))->getSSPBalance($agentId), 0);
if (!class_exists('CashbookService')) require_once __DIR__ . '/../../lib/CashbookService.php';
$_exCb     = new CashbookService($store, $dataDir);
$_exRate   = $_exCb->getExchangeRate() ?: 5700;
$_exCtx    = $_exCb->getLastExchangeContext($store->load('cash_ins.json') ?: []);
$_exLastR  = (int)($_exCtx['last_rate'] ?? 0);
$_exPrefill = $_exLastR > 0 ? $_exLastR : $_exRate;
?>
<div style="padding:16px;">
  <div style="background:linear-gradient(135deg,#4c1d95,#7c3aed);border-radius:18px;padding:20px;margin-bottom:16px;color:#fff;">
    <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.7;margin-bottom:4px;">💱 Currency Exchange</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
      <div>
        <div style="font-size:10px;opacity:.6;text-transform:uppercase;letter-spacing:.5px;">USD Bag</div>
        <div style="font-size:22px;font-weight:900;">$<?= number_format($_exUsd, 2) ?></div>
      </div>
      <div>
        <div style="font-size:10px;opacity:.6;text-transform:uppercase;letter-spacing:.5px;">SSP Bag</div>
        <div style="font-size:22px;font-weight:900;"><?= number_format($_exSsp, 0) ?></div>
        <div style="font-size:10px;opacity:.5;">≈ $<?= number_format($_exRate > 0 ? $_exSsp / $_exRate : 0, 2) ?></div>
      </div>
    </div>
    <div style="margin-top:10px;font-size:11px;opacity:.65;">
      <?php if ($_exLastR > 0): ?>
      Last market rate: <strong><?= number_format($_exLastR, 0) ?> SSP/$</strong>
        <?php if (!empty($_exCtx['last_by'])): ?>
        · <?= htmlspecialchars($_exCtx['last_by']) ?>
          <?php
            $m = (int)($_exCtx['last_minutes_ago'] ?? -1);
            if ($m >= 0 && $m < 1440) echo ' · '.($m < 1 ? 'just now' : ($m < 60 ? $m.'m ago' : round($m/60).'h ago'));
          ?>
        <?php endif; ?>
        <?php if ($_exCtx['count_7day'] > 1): ?>
        &nbsp;·&nbsp; 7d: <?= number_format($_exCtx['min_7day'],0) ?>–<?= number_format($_exCtx['max_7day'],0) ?>
        <?php endif; ?>
      <?php else: ?>
      System rate: 1 USD = <?= number_format($_exRate, 0) ?> SSP
      <?php endif; ?>
    </div>
  </div>

  <form method="POST" action="?page=dashboard&tab=my_account&v=exchange" id="exchForm">
    <?= csrfField() ?>
    <input type="hidden" name="mc_action" value="record_exchange">

    <!-- Direction -->
    <div style="margin-bottom:14px;">
      <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Exchange Direction</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <label style="cursor:pointer;">
          <input type="radio" name="exc_direction" value="usd_to_ssp" checked onchange="exCalc()" style="display:none;" id="dir_u2s">
          <div id="lbl_u2s" style="background:#faf5ff;border:2px solid #7c3aed;border-radius:14px;padding:14px 10px;text-align:center;">
            <div style="font-size:22px;margin-bottom:4px;">💵→🇸🇸</div>
            <div style="font-size:13px;font-weight:800;color:#7c3aed;">USD to SSP</div>
          </div>
        </label>
        <label style="cursor:pointer;">
          <input type="radio" name="exc_direction" value="ssp_to_usd" onchange="exCalc()" style="display:none;" id="dir_s2u">
          <div id="lbl_s2u" style="background:#fff;border:2px solid #e2e8f0;border-radius:14px;padding:14px 10px;text-align:center;">
            <div style="font-size:22px;margin-bottom:4px;">🇸🇸→💵</div>
            <div style="font-size:13px;font-weight:800;color:#374151;">SSP to USD</div>
          </div>
        </label>
      </div>
    </div>

    <!-- Amount -->
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px;" id="exAmtLbl">
        USD Amount to Give (max $<?= number_format($_exUsd, 2) ?>)
      </label>
      <div style="position:relative;">
        <span id="exPfx" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:22px;font-weight:900;color:#7c3aed;">$</span>
        <input type="number" name="exc_amount" id="exAmt" step="0.01" min="0.01" required
          oninput="exCalc()"
          style="width:100%;padding:14px 14px 14px 44px;border:2px solid #ddd6fe;border-radius:14px;font-size:26px;font-weight:900;box-sizing:border-box;outline:none;">
      </div>
    </div>

    <!-- Rate — pre-filled from last actual market rate -->
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px;">
        Exchange Rate (SSP per $)
      </label>
      <input type="number" name="exc_rate" id="exRate" step="1" min="1" required value="<?= (int)$_exPrefill ?>"
        oninput="exCalc()"
        style="width:100%;padding:12px 14px;border:2px solid #e2e8f0;border-radius:12px;font-size:18px;font-weight:800;box-sizing:border-box;outline:none;">
      <?php
        $_mr = (int)($_exCtx['last_rate'] ?? 0);
        $_mb = htmlspecialchars($_exCtx['last_by'] ?? '');
        $_mm = (int)($_exCtx['last_minutes_ago'] ?? -1);
        $_mi7 = (int)($_exCtx['min_7day'] ?? 0);
        $_mx7 = (int)($_exCtx['max_7day'] ?? 0);
        if ($_mr > 0 && $_mm >= 0 && $_mm < 1440) {
            $t = $_mm < 1 ? 'just now' : ($_mm < 60 ? $_mm.'m ago' : round($_mm/60).'h ago');
            echo '<div style="font-size:11px;color:#64748b;margin-top:4px;">Last: <strong>'.number_format($_mr,0).'</strong> SSP/$ by '.$_mb.' · '.$t;
            if ($_mi7 > 0 && $_mi7 !== $_mx7) echo ' &nbsp;·&nbsp; 7d range: '.number_format($_mi7,0).'–'.number_format($_mx7,0);
            echo '</div>';
        } else {
            echo '<div style="font-size:11px;color:#94a3b8;margin-top:4px;">No recent exchange &#8212; enter today&#39;s market rate</div>';
        }
      ?>
      <div id="exRateBanner" style="display:none;margin-top:6px;border-radius:8px;padding:7px 10px;font-size:12px;font-weight:600;"></div>
    </div>

    <!-- Live result -->
    <div id="exResult" style="display:none;background:linear-gradient(135deg,#7c3aed,#4f46e5);border-radius:14px;padding:16px;margin-bottom:14px;text-align:center;color:#fff;">
      <div style="font-size:12px;opacity:.8;margin-bottom:4px;">You will receive</div>
      <div style="font-size:30px;font-weight:900;" id="exResultAmt">—</div>
      <div style="font-size:11px;opacity:.7;margin-top:4px;" id="exResultFrm">—</div>
    </div>
    <div id="exWarn" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;font-size:13px;color:#dc2626;margin-bottom:12px;">
      ⚠️ Amount exceeds your current bag balance
    </div>

    <!-- Exchange Source -->
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px;">Source</label>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <label style="cursor:pointer;">
          <input type="radio" name="exc_source" value="money_changer" checked style="display:none;" class="exc-src-radio">
          <div class="exc-src-pill" style="border:2px solid #e2e8f0;border-radius:10px;padding:9px 10px;text-align:center;font-size:12px;font-weight:700;color:#64748b;transition:.15s;" id="exc_src_mc">
            🏪 Money Changer
          </div>
        </label>
        <label style="cursor:pointer;">
          <input type="radio" name="exc_source" value="customer_ssp" style="display:none;" class="exc-src-radio">
          <div class="exc-src-pill" style="border:2px solid #e2e8f0;border-radius:10px;padding:9px 10px;text-align:center;font-size:12px;font-weight:700;color:#64748b;transition:.15s;" id="exc_src_cust">
            👤 Client Paid SSP
          </div>
        </label>
      </div>
    </div>

    <!-- Client name (shown when customer_ssp selected) -->
    <div id="exc_client_row" style="display:none;margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px;">Client Name / Invoice Ref</label>
      <input type="text" name="exc_client_ref" placeholder="e.g. Ibrahim Abdulmahmoud (000162)"
        style="width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:14px;box-sizing:border-box;outline:none;">
    </div>

    <!-- Note -->
    <div style="margin-bottom:18px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px;">Note (optional)</label>
      <input type="text" name="exc_note" placeholder="e.g. Exchanged at Juba market"
        style="width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:12px;font-size:14px;box-sizing:border-box;outline:none;">
    </div>

    <button type="submit"
      style="width:100%;padding:16px;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;border:none;border-radius:14px;font-size:16px;font-weight:800;cursor:pointer;">
      💱 Record Exchange
    </button>
  </form>
<script>
// Exchange source toggle
document.querySelectorAll('.exc-src-radio').forEach(function(r){
  r.addEventListener('change', function(){
    var mc = document.getElementById('exc_src_mc');
    var cu = document.getElementById('exc_src_cust');
    var cr = document.getElementById('exc_client_row');
    if(this.value === 'customer_ssp'){
      cu.style.border='2px solid #7c3aed'; cu.style.color='#7c3aed'; cu.style.background='#f5f3ff';
      mc.style.border='2px solid #e2e8f0'; mc.style.color='#64748b'; mc.style.background='';
      if(cr) cr.style.display='';
    } else {
      mc.style.border='2px solid #7c3aed'; mc.style.color='#7c3aed'; mc.style.background='#f5f3ff';
      cu.style.border='2px solid #e2e8f0'; cu.style.color='#64748b'; cu.style.background='';
      if(cr) cr.style.display='none';
    }
  });
});
</script>
</div>

<script>
var _exUsd = <?= (float)$_exUsd ?>;
var _exSsp = <?= (float)$_exSsp ?>;
var _exLastRate = <?= (int)$_exLastR ?>;
function exCalc() {
  var dir     = document.querySelector('[name=exc_direction]:checked')?.value || 'usd_to_ssp';
  var amt     = parseFloat(document.getElementById('exAmt').value) || 0;
  var rate    = parseFloat(document.getElementById('exRate').value) || <?= (int)$_exPrefill ?>;
  var res     = document.getElementById('exResult');
  var resA    = document.getElementById('exResultAmt');
  var resF    = document.getElementById('exResultFrm');
  var warn    = document.getElementById('exWarn');
  var lbl     = document.getElementById('exAmtLbl');
  var pfx     = document.getElementById('exPfx');
  var lu2s    = document.getElementById('lbl_u2s');
  var ls2u    = document.getElementById('lbl_s2u');
  var banner  = document.getElementById('exRateBanner');

  // ── Rate comparison banner ──────────────────────────────────────────────
  if (banner && _exLastRate > 0 && rate > 0) {
    var diff = Math.round(rate - _exLastRate);
    var absDiff = Math.abs(diff);
    var pct = ((absDiff / _exLastRate) * 100).toFixed(1);
    var bText, bBg, bClr;
    if (absDiff / _exLastRate > 0.30) {
      bText = '⚠ Rate ' + Math.round(rate).toLocaleString() + ' differs >30% from last (' + _exLastRate.toLocaleString() + ') — check for typo';
      bBg = '#fef2f2'; bClr = '#dc2626';
    } else if (absDiff < 10) {
      bText = 'Same as last market rate (' + _exLastRate.toLocaleString() + ' SSP/$)';
      bBg = '#f1f5f9'; bClr = '#64748b';
    } else if ((diff > 0 && dir === 'usd_to_ssp') || (diff < 0 && dir === 'ssp_to_usd')) {
      bText = '▲ Better than last rate by ' + absDiff.toLocaleString() + ' SSP/$ (+' + pct + '%) — good deal';
      bBg = '#f0fdf4'; bClr = '#16a34a';
    } else {
      bText = '▼ ' + absDiff.toLocaleString() + ' SSP/$ below last rate (−' + pct + '%) — try to negotiate';
      bBg = '#fef9ec'; bClr = '#d97706';
    }
    banner.style.display = 'block';
    banner.style.background = bBg; banner.style.color = bClr;
    banner.textContent = bText;
  } else if (banner) { banner.style.display = 'none'; }

  if (dir === 'usd_to_ssp') {
    lbl.textContent = 'USD Amount to Give (max $' + _exUsd.toFixed(2) + ')';
    pfx.textContent = '$';
    lu2s.style.borderColor = '#7c3aed'; lu2s.style.background = '#faf5ff';
    ls2u.style.borderColor = '#e2e8f0'; ls2u.style.background = '#fff';
    if (amt > 0 && rate > 0) {
      var ssp = Math.round(amt * rate);
      var sspLast = _exLastRate > 0 ? Math.round(amt * _exLastRate) : 0;
      resA.textContent = ssp.toLocaleString() + ' SSP';
      var formula = '$' + amt.toFixed(2) + ' × ' + Math.round(rate).toLocaleString() + ' = ' + ssp.toLocaleString() + ' SSP';
      if (_exLastRate > 0 && Math.abs(rate - _exLastRate) >= 10)
        formula += ' (vs ' + sspLast.toLocaleString() + ' at last rate)';
      resF.textContent = formula;
      res.style.display = 'block';
    } else res.style.display = 'none';
    warn.style.display = (amt > _exUsd + 0.01 && amt > 0) ? 'block' : 'none';
  } else {
    lbl.textContent = 'SSP Amount to Give (max ' + Math.round(_exSsp).toLocaleString() + ' SSP)';
    pfx.textContent = 'S£';
    ls2u.style.borderColor = '#7c3aed'; ls2u.style.background = '#faf5ff';
    lu2s.style.borderColor = '#e2e8f0'; lu2s.style.background = '#fff';
    if (amt > 0 && rate > 0) {
      var usd = (amt / rate).toFixed(2);
      resA.textContent = '$' + usd;
      resF.textContent = Math.round(amt).toLocaleString() + ' SSP ÷ ' + Math.round(rate).toLocaleString() + ' = $' + usd;
      res.style.display = 'block';
    } else res.style.display = 'none';
    warn.style.display = (amt > _exSsp + 1 && amt > 0) ? 'block' : 'none';
  }
}
</script>
</div>
<?php endif; ?>
<script>
function mcCur(c){
  var u=document.getElementById('mc_cur_usd'),s=document.getElementById('mc_cur_ssp');
  var h=document.getElementById('mc_ssp_hint');
  if(c==='USD'){u.style.background='#f0fdf4';u.style.color='#15803d';s.style.background='#f8fafc';s.style.color='#9ca3af';if(h)h.style.display='none'}
  else{s.style.background='#fff7ed';s.style.color='#c2410c';u.style.background='#f8fafc';u.style.color='#9ca3af';if(h)h.style.display='block'}
}
function mcAdvCur(c){
  var u=document.getElementById('mc_adv_usd'),s=document.getElementById('mc_adv_ssp');
  if(c==='USD'){u.style.background='#f0fdf4';u.style.color='#15803d';s.style.background='#f8fafc';s.style.color='#9ca3af'}
  else{s.style.background='#eff6ff';s.style.color='#1d4ed8';u.style.background='#f8fafc';u.style.color='#9ca3af'}
}
</script>
