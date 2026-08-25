<?php
// ═══════════════════════════════════════════════════════════════
// FIELD OPS (approvals, collections, handovers, payments)
// ═══════════════════════════════════════════════════════════════

require_once dirname(__DIR__, 2) . '/lib/PaymentUuids.php';

// ── Expense Approve / Reject ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'', ['approve_expense','reject_expense'])) {
    $retailer = $auth->requireAccountant();
    $expId    = (int)($_POST['expense_id'] ?? 0);
    $isApprove= ($_POST['action'] === 'approve_expense');
    $expenses = $store->load('cash_expenses.json') ?: [];
    foreach ($expenses as &$exp) {
        if ((int)($exp['id']??0) !== $expId || ($exp['status']??'') !== 'pending') continue;
        if ($isApprove) {
            $expCur   = $exp['currency'] ?? 'USD';
            $sspRate  = round((float)($_POST['ssp_rate'] ?? 0), 2);
            $sspAmt   = (float)($exp['ssp_amount'] ?? 0);

            // v4.11.3: TIG pre-approve checks
            require_once dirname(__DIR__, 2) . '/lib/TransactionIntegrityGuard.php';
            $_tigIssues = TransactionIntegrityGuard::preSave('expense_approve', array_merge($exp, [
                'ssp_rate' => $sspRate,
            ]), $store, $store->getPdo());
            foreach ($_tigIssues as $_issue) {
                if (($_issue['level'] ?? '') === 'block') {
                    flash('🚫 ' . $_issue['msg'] . ' — ' . htmlspecialchars($_issue['affects']), 'danger');
                    redirect('?page=dashboard&tab=handover_queue');
                }
                if (($_issue['level'] ?? '') === 'warn') {
                    flash('⚠️ ' . $_issue['msg'] . ' <small>' . htmlspecialchars($_issue['affects']) . '</small>', 'warning');
                }
            }

            // For SSP expenses: Rupesh must provide rate
            if ($expCur === 'SSP' && $sspRate <= 0) {
                flash('Please enter the SSP exchange rate to approve this expense.', 'danger');
                redirect('?page=dashboard&tab=handover_queue');
            }
            $usdAmount = ($expCur === 'SSP' && $sspRate > 0)
                ? round($sspAmt / $sspRate, 2)
                : (float)$exp['amount'];

            $exp['status']      = 'approved';
            $exp['approved_by'] = $retailer['name'];
            $exp['approved_at'] = date('Y-m-d H:i:s');
            $exp['amount']      = $usdAmount; // lock in USD equivalent
            $exp['ssp_rate']    = $expCur === 'SSP' ? $sspRate : null;

            // ── Post Cash OUT to cashbook ─────────────────────────────────
            // v4.9.9: dedup guard prevents double-post if approve is somehow triggered twice
            // v4.9.10: staff payments use staff_name as person (not collector_name)
            //          and map staff_payment_type to proper cb_ledger category
            try {
                require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
                $cbExp   = new CashbookService($store, $dataDir);
                $_expRef = 'EXP-'.$expId;
                $_dupChk = $store->getPdo()->prepare("SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1");
                $_dupChk->execute([$_expRef]);
                if (!$_dupChk->fetch()) {
                // v4.9.10: For staff payments, use the RECIPIENT as person, not the collector
                $isStaffPayment = !empty($exp['is_staff_payment']) || !empty($exp['staff_name']);
                $cbPerson = $isStaffPayment && !empty($exp['staff_name'])
                    ? $exp['staff_name']
                    : ($exp['collector_name'] ?? '');
                // v4.9.10: Map staff_payment_type to proper cashbook category
                // e.g. "Salary" stays "Salary", "Transport" → "Transport Allowance"
                $cbCatMap = [
                    'Salary'    => 'Salary',
                    'Advance'   => 'Staff Advance',
                    'Fuel'      => 'Travel & Field',
                    'Transport' => 'Transport Allowance',
                    'Bonus'     => 'Bonus',
                    'Food'      => 'Food Allowance',
                ];
                // v4.11.3: Map field_expenses simplified categories → proper cashbook categories
                // (field_expenses.php uses lowercase short codes: parts, fuel, transport, etc.)
                $_fieldCatMap = [
                    'fuel'      => 'Travel & Field',
                    'parts'     => 'Local Purchase',
                    'transport' => 'Travel & Field',
                    'allowance' => 'Employee Benefit',
                    'food'      => 'Food Allowance',
                    'other'     => 'Misc Expense',
                ];
                $cbCategory = $exp['category'] ?? 'Misc Expense';
                if ($isStaffPayment && !empty($exp['staff_payment_type'])) {
                    $cbCategory = $cbCatMap[$exp['staff_payment_type']] ?? $exp['staff_payment_type'];
                } elseif (!$isStaffPayment && isset($_fieldCatMap[strtolower($cbCategory)])) {
                    // Map lowercase field category to proper cashbook category
                    $cbCategory = $_fieldCatMap[strtolower($cbCategory)];
                }
                $expDesc = $isStaffPayment
                    ? $cbCategory . ' — ' . $cbPerson . ' (via ' . ($exp['collector_name'] ?? 'Field') . ')'
                    : 'Field expense: '.($exp['category']??'').
                      ($exp['description'] ? ' — '.$exp['description'] : '').
                      ' ('.$exp['collector_name'].')';
                $rawData = [
                    'project'           => 'dishnet',
                    'date'              => date('Y-m-d', strtotime($exp['submitted_at'] ?? 'now')),
                    'direction'         => 'out',
                    'amount'            => $usdAmount,
                    'currency'          => $expCur,
                    'category'          => $cbCategory,
                    'category_raw'      => $isStaffPayment ? $cbCategory : ('Field Expense — '.$exp['collector_name']),
                    'person'            => $cbPerson,
                    'description'       => $expDesc,
                    'validation_ref'    => $_expRef,
                    'validation_status' => !empty($exp['photo']) ? 'voucher' : 'na',
                    'status'            => 'approved',
                    'approved_by'       => $retailer['name'],
                    'source'            => 'collect_payment',
                    'created_at'        => date('Y-m-d H:i:s'),
                ];
                if ($expCur === 'SSP') {
                    $rawData['ssp_amount'] = $sspAmt;
                    $rawData['ssp_rate']   = $sspRate;
                }
                $cbExp->addEntryRaw($rawData);

                // ── AUTO-LINK: Staff payment → Cash IN for receiving staff ──
                // v4.11.0: Skip personal pay categories for auto-link
                $_gePersonalCats = ['Salary','Transport Allowance','Food Allowance',
                                    'Bonus','Employee Benefit'];
                if ($isStaffPayment && !empty($exp['staff_name'])
                    && !in_array($cbCategory, $_gePersonalCats, true)) {
                    $_geStaffLower = strtolower(trim($exp['staff_name']));
                    if ($_geStaffLower && $_geStaffLower !== 'staff') {
                        $_geRetailers = $store->load('retailers.json') ?? [];
                        $_geMatchId = 0; $_geMatchName = ''; $_geMatchPhone = '';
                        foreach ($_geRetailers as $_ger) {
                            if (empty($_ger['is_active'])) continue;
                            $_gerName = strtolower($_ger['name'] ?? '');
                            if ($_gerName === $_geStaffLower) { $_geMatchId = (int)$_ger['id']; $_geMatchName = $_ger['name']; $_geMatchPhone = $_ger['phone'] ?? ''; break; }
                            if ($_gerName && (strpos($_gerName, $_geStaffLower) !== false || strpos($_geStaffLower, $_gerName) !== false)) {
                                $_geMatchId = (int)$_ger['id']; $_geMatchName = $_ger['name']; $_geMatchPhone = $_ger['phone'] ?? '';
                            }
                        }
                        if ($_geMatchId > 0) {
                            $_geCashIns = $store->load('cash_ins.json') ?? [];
                            $_geDup = false;
                            foreach ($_geCashIns as $_gci) {
                                if (($_gci['cb_ref'] ?? '') === $_expRef && $_expRef !== '') { $_geDup = true; break; }
                            }
                            if (!$_geDup) {
                                $_geCiCat  = ($expCur === 'SSP') ? 'SSP Received' : 'USD Received';
                                $_geCiDesc = 'From ' . ($exp['collector_name'] ?? 'Field') . ' — ' . $cbCategory;
                                $_geCashIns[] = [
                                    'id'            => count($_geCashIns) + 1,
                                    'collector_id'  => $_geMatchId,
                                    'collector_name'=> $_geMatchName,
                                    'amount'        => $expCur === 'USD' ? $usdAmount : 0,
                                    'currency'      => $expCur,
                                    'ssp_amount'    => $expCur === 'SSP' ? $sspAmt : 0,
                                    'usd_given'     => 0,
                                    'rate'          => 0,
                                    'category'      => $_geCiCat,
                                    'description'   => $_geCiDesc,
                                    'status'        => 'approved',
                                    'approved_by'   => 'auto (expense approve link)',
                                    'approved_at'   => date('Y-m-d H:i:s'),
                                    'cb_ref'        => $_expRef,
                                    'created_at'    => date('Y-m-d H:i:s'),
                                ];
                                $store->save('cash_ins.json', $_geCashIns);
                                logActivity($dataDir, 'expense_approve_auto_link',
                                    "Auto-created Cash IN for {$_geMatchName}: {$expCur} " .
                                    ($expCur === 'SSP' ? number_format($sspAmt, 0) : '$'.number_format($usdAmount, 2)) .
                                    " (cb_ref: {$_expRef})", '');
                                // WhatsApp notification
                                if ($_geMatchPhone) {
                                    try {
                                        $notify->staffCashReceived($_geMatchPhone, $_geMatchName,
                                            $expCur, $usdAmount, $sspAmt,
                                            $cbCategory, $exp['collector_name'] ?? 'Field', $_expRef);
                                    } catch (\Throwable $e) {}
                                }
                            }
                        }
                    }
                }

                } // end dedup guard
            } catch (\Throwable $cbExpErr) {
                logActivity($dataDir, 'cashbook_expense_post_failed', 'Cashbook expense OUT failed', $cbExpErr->getMessage());
            }
            $expLabel = $expCur === 'SSP'
                ? number_format($sspAmt,0).' SSP = $'.number_format($usdAmount,2).' @'.number_format($sspRate,0)
                : '$'.number_format($usdAmount,2);
            flash('\u2705 Expense approved -- '.$expLabel.' posted to Cashbook.', 'success');
            // v4.11.3 PERF: Invalidate nav badge caches after expense changes
            try { if (function_exists('invalidateNavCache')) invalidateNavCache($store->getPdo(), ['nav_badges', 'ledger_mismatch_count']); } catch (\Throwable $e) {}
        } else {
            $exp['status']        = 'rejected';
            $exp['rejected_by']   = $retailer['name'];
            $exp['rejected_at']   = date('Y-m-d H:i:s');
            $exp['reject_reason'] = trim($_POST['reject_reason'] ?? '');
            flash('Expense rejected.', 'warning');
        }
        break;
    }
    unset($exp);
    $store->save('cash_expenses.json', $expenses);
    redirect('?page=dashboard&tab=handover_queue');
}

// ── Submit Handover (Diko) ────────────────────────────────────────────────
// ── Impersonate Retailer (Admin only) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='impersonate_retailer') {
    $admin = $auth->requireAdmin(); // must be admin to impersonate
    $rid   = (int)($_POST['retailer_id'] ?? 0);
    if (!$rid) { flash('Invalid retailer.', 'danger'); redirect('?page=dashboard&tab=api_docs'); }
    $target = $store->findOne('retailers.json', 'id', $rid);
    if (!$target) { flash('Retailer not found.', 'danger'); redirect('?page=dashboard&tab=api_docs'); }
    if (!empty($target['is_admin'])) { flash('Cannot impersonate an admin account.', 'danger'); redirect('?page=dashboard&tab=api_docs'); }

    // Save admin identity + switch session to retailer in one atomic block
    $_SESSION['impersonating_as']    = $rid;
    $_SESSION['impersonating_admin'] = (int)$admin['id'];
    $_SESSION['kyc_retailer'] = [
        'id'              => $target['id'],
        'name'            => $target['name'],
        'email'           => $target['email'],
        'is_admin'        => false,
        'is_field_agent'  => (bool)($target['is_field_agent'] ?? false),
        'role'            => $target['role'] ?? 'sales',
        'must_change_pwd' => false,
        'logged_in_at'    => time(),
        'impersonated_by' => (int)$admin['id'],
    ];
    $_SESSION['kyc_flash'] = ['msg' => '👁 Now viewing as <strong>' . htmlspecialchars($target['name']) . '</strong>. <a href="?page=dashboard&action=stop_impersonate" style="color:#fff;text-decoration:underline;font-weight:700;">Stop →</a>', 'type' => 'warning'];
    redirect('?page=dashboard&tab=form');
}

// ── Stop Impersonating ────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'stop_impersonate') {
    $adminId = (int)($_SESSION['impersonating_admin'] ?? 0);
    if ($adminId) {
        $admin = $store->findOne('retailers.json', 'id', $adminId);
        if ($admin) {
            $_SESSION['kyc_retailer'] = [
                'id'              => $admin['id'],
                'name'            => $admin['name'],
                'email'           => $admin['email'],
                'is_admin'        => true,
                'is_field_agent'  => false,
                'role'            => $admin['role'] ?? 'admin',
                'must_change_pwd' => false,
                'logged_in_at'    => time(),
            ];
        }
    }
    unset($_SESSION['impersonating_as'], $_SESSION['impersonating_admin']);
    flash('✅ Back to admin view.', 'success');
    redirect('?page=dashboard&tab=api_docs');
}

// ── Field agent Cash IN (advance received, etc.) ─────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='log_cash_in') {
    $retailer  = $auth->requireLogin();
    $rid       = (int)$retailer['id'];
    $cat       = trim($_POST['category'] ?? $_POST['ci_category'] ?? '');
    $currency  = trim($_POST['currency'] ?? 'SSP');
    $desc      = trim($_POST['description'] ?? $_POST['ci_desc'] ?? '');
    $sspAmt    = round((float)($_POST['ssp_amount'] ?? 0), 0);
    $usdGiven  = round((float)($_POST['usd_given']  ?? 0), 2);
    $rate      = round((float)($_POST['rate']       ?? 0), 2);
    $amount    = round((float)($_POST['amount']     ?? $_POST['ci_amount'] ?? 0), 2);

    // Validation: need at least some amount
    $hasAmt = ($sspAmt > 0 || $amount > 0 || $usdGiven > 0);
    if (!$hasAmt) { flash('Amount required.','danger'); redirect('?page=dashboard&tab=wallet'); }

    // For Exchange: auto-calc rate if missing
    if ($cat === 'Exchange' && $usdGiven > 0 && $sspAmt > 0 && $rate <= 0) {
        $rate = round($sspAmt / $usdGiven, 2);
    }

    // Status: SSP Received and Exchange go straight to approved (no USD impact yet)
    // Collection also approved immediately
    $status = in_array($cat, ['SSP Received','Exchange','Collection']) ? 'approved' : 'pending';

    $cashIns   = $store->load('cash_ins.json') ?? [];
    $_newCin = [
        'id'            => count($cashIns) + 1,
        'collector_id'  => $rid,
        'collector_name'=> $retailer['name'] ?? '',
        'amount'        => $amount,        // USD amount (for Exchange = USD given)
        'currency'      => $currency,
        'ssp_amount'    => $sspAmt,        // raw SSP amount
        'usd_given'     => $usdGiven,      // for Exchange: USD she gave
        'rate'          => $rate,          // SSP per USD
        'category'      => $cat,
        'description'   => $desc,
        'status'        => $status,
        'approved_by'   => $status === 'approved' ? 'auto' : null,
        'approved_at'   => $status === 'approved' ? date('Y-m-d H:i:s') : null,
        'created_at'    => date('Y-m-d H:i:s'),
    ];
    $cashIns[] = $_newCin;
    $store->save('cash_ins.json', $cashIns);
    // Dual-write: staff_ledger
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
    StaffLedgerWriter::onCashIn($store->getPdo(), $_newCin);

    if ($cat === 'Exchange') {
        $msg = '✅ Exchange recorded: $'.number_format($usdGiven,2).' → '.number_format($sspAmt,0).' SSP @ '.number_format($rate,0);
    } elseif ($cat === 'SSP Received') {
        $msg = '✅ SSP Received: '.number_format($sspAmt,0).' SSP recorded.';
    } else {
        $msg = '✅ Cash IN recorded.';
    }
    flash($msg,'success');
    redirect('?page=dashboard&tab=wallet');
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='submit_handover') {
    $retailer = $auth->requireLogin();
    $rid      = (int)$retailer['id'];
    $amount   = round((float)($_POST['handover_amount'] ?? $_POST['amount'] ?? 0), 2);
    $notes    = trim($_POST['handover_notes'] ?? $_POST['note'] ?? $_POST['notes'] ?? '');
    $toId     = (int)($_POST['to_staff_id'] ?? 0);
    $toName   = trim($_POST['to_staff_name'] ?? '');
    $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
    if (!in_array($currency, ['USD', 'SSP'])) $currency = 'USD';

    if ($amount <= 0) {
        flash('Enter the cash amount you are handing over.', 'danger');
        redirect('?page=dashboard&tab=cashbook');
    }

    // Cash-in-hand validation — USD only (SSP handovers excluded from position calc)
    if ($currency === 'USD') {
        require_once dirname(__DIR__, 2) . '/lib/DualReadCashPosition.php';
        $_cpSvc     = new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? '');
        $_cpPos     = $_cpSvc->getPosition($rid);
        $cashInHand = $_cpPos['cash_in_hand'];
        if ($amount > $cashInHand + 0.01) {
            flash('Handover ($'.number_format($amount,2).') exceeds cash-in-hand ($'.number_format(max(0,$cashInHand),2).'). Check your register.', 'danger');
            redirect('?page=dashboard&tab=cashbook');
        }
    }

    // If no recipient specified, default to accountant
    if (!$toId || !$toName) {
        $allR = $auth->getAllRetailers();
        foreach ($allR as $r) {
            if (($r['role'] ?? '') === 'accountant' || ($r['is_admin'] ?? false)) {
                $toId = (int)$r['id'];
                $toName = $r['name'];
                break;
            }
        }
    }

    $newHovId = $store->nextId('cash_handovers.json');
    $store->appendWithId('cash_handovers.json', [
        'from_id'      => $rid,
        'from_name'    => $retailer['name'],
        'to_id'        => $toId,
        'to_name'      => $toName,
        'amount'       => $amount,
        'currency'     => $currency,
        'notes'        => $notes,
        'status'       => 'pending',
        'submitted_at' => date('Y-m-d H:i:s'),
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
    logActivity($dataDir, 'handover_submitted', 'Cash handover submitted',
        ($currency === 'SSP' ? number_format($amount).' SSP' : '$'.number_format($amount,2)).' from '.$retailer['name'].' to '.$toName);
    try { $notify->remittanceSubmitted($retailer, $amount, $toName ?: 'Accountant', $newHovId); } catch (\Throwable $e) {}
    $amtDisp = $currency === 'SSP' ? number_format($amount).' SSP' : '$'.number_format($amount,2);
    flash("✅ Handover of {$amtDisp} to {$toName} submitted — waiting for confirmation.", 'success');
    redirect('?page=dashboard&tab=cashbook');
}

// ── Field Agent: Give Advance to Staff ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='field_give_advance') {
    $retailer = $auth->requireLogin();
    $rid      = (int)$retailer['id'];
    $amount   = round((float)($_POST['amount'] ?? 0), 2);
    $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
    $recipId  = (int)($_POST['recipient_id'] ?? 0);
    $recipName= trim($_POST['recipient_name'] ?? '');
    $purpose  = trim($_POST['purpose'] ?? 'misc');
    $desc     = trim($_POST['description'] ?? '');

    if ($amount <= 0 || !$recipId || !$recipName) {
        flash('Enter amount and select recipient.', 'danger');
        redirect('?page=dashboard&tab=cashbook');
    }

    // Create the advance via ExpenseAdvanceService
    require_once dirname(__DIR__, 2) . '/lib/ExpenseAdvanceService.php';
    $expAdv = new ExpenseAdvanceService($store, $dataDir);
    $result = $expAdv->createAdvance([
        'recipient_id'   => $recipId,
        'recipient_name' => $recipName,
        'amount'         => $amount,
        'currency'       => $currency,
        'purpose'        => $purpose,
        'description'    => $desc ?: "Field advance from {$retailer['name']}",
        'project'        => 'dishnet',
    ], $retailer);

    if ($result['ok']) {
        $advNo = $result['advance_no'] ?? '';
        $amtDisp = $currency === 'SSP' ? number_format($amount) . ' SSP' : '$' . number_format($amount, 2);
        // Also log as cashbook OUT
        // v4.9.9: fixed 'reference' → 'validation_ref' + dedup guard
        try {
            require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
            $cb = new CashbookService($store, $dataDir);
            $_advRef = $advNo ?: 'ADV-' . time();
            $_dupChk = $store->getPdo()->prepare("SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1");
            $_dupChk->execute([$_advRef]);
            if (!$_dupChk->fetch()) {
            $cb->addEntry([
                'staff_id'        => $rid,
                'staff_name'      => $retailer['name'],
                'project'         => 'dishnet',
                'currency'        => $currency,
                'direction'       => 'out',
                'category'        => 'Employee Benefit',
                'amount'          => $amount,
                'description'     => "Advance to {$recipName} ({$advNo}) — {$purpose}",
                'validation_ref'  => $_advRef,
            ]);
            } // end dedup guard
        } catch (\Throwable $e) { /* non-fatal */ }

        // Notify recipient via WhatsApp
        try {
            $recipR = $store->findOne('retailers.json', 'id', $recipId);
            if ($recipR && !empty($recipR['phone'])) {
                $notify->sendRaw($recipR['phone'],
                    "💸 *Cash Advance Received*\n\nFrom: {$retailer['name']}\nAmount: {$amtDisp}\nPurpose: {$purpose}\nRef: {$advNo}\n\nPlease submit receipts when you spend this money.",
                    'advance_given');
            }
        } catch (\Throwable $e) {}

        flash("✅ Advance of {$amtDisp} given to {$recipName} ({$advNo}).", 'success');
    } else {
        flash($result['error'] ?? 'Failed to create advance.', 'danger');
    }
    redirect('?page=dashboard&tab=cashbook');
}

// ── Confirm Handover (Accountant or Admin) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='confirm_handover') {
    $retailer        = $auth->requireLogin();
    $_isMainAcct     = ($retailer['is_admin'] ?? false) || ($retailer['role'] ?? '') === 'accountant';
    $_isFieldAcct    = ($retailer['role'] ?? '') === 'field_accountant';
    $_confirmerId    = (int)($retailer['id'] ?? 0);

    if (!$_isMainAcct && !$_isFieldAcct) {
        flash('Only accountant or admin can confirm handovers.', 'danger');
        redirect('?page=dashboard&tab=handover_queue');
    }
    $hovId     = (int)($_POST['handover_id'] ?? 0);
    $handovers = $store->load('cash_handovers.json') ?: [];

    // field_accountant can only confirm handovers addressed specifically to them
    if ($_isFieldAcct && !$_isMainAcct) {
        $_targetHov = null;
        foreach ($handovers as $_th) {
            if ((int)($_th['id'] ?? 0) === $hovId) { $_targetHov = $_th; break; }
        }
        if (!$_targetHov || (int)($_targetHov['to_id'] ?? 0) !== $_confirmerId) {
            flash('You can only confirm handovers addressed to you.', 'danger');
            redirect('?page=dashboard&tab=field_handover');
        }
    }
    foreach ($handovers as &$hov) {
        if ((int)($hov['id']??0) !== $hovId || ($hov['status']??'') !== 'pending') continue;
        $hov['status']        = 'confirmed';
        $hov['confirmed_by']  = $retailer['name'];
        $hov['confirmed_at']  = date('Y-m-d H:i:s');
        $hov['confirm_notes'] = trim($_POST['confirm_notes'] ?? '');
        $wallet->credit((int)$hov['from_id'], (float)$hov['amount'],
            'Cash handover confirmed by '.$retailer['name'],
            $retailer['name'], 'HOV-'.$hovId, 'handover_credit');
        logActivity($dataDir, 'handover_confirmed', 'Cash handover confirmed',
            '$'.number_format($hov['amount'],2).' from '.$hov['from_name'].' confirmed by '.$retailer['name']);

        // ── POST Cash IN to cashbook (cleaner flow — no pending entry to approve) ──
        // ── CASHBOOK: handover is INTERNAL cash transfer (Diko→Rupesh) ────────
        // The actual revenue was already posted to cb_ledger by the CRM webhook
        // when each individual payment was created. Writing again here caused
        // double-counting. Handover only updates staff cash position, not the
        // company cashbook. (Fixed v4.9.8)
        $cbReceiptNo = 'HOV-' . $hovId; // reference only, no cashbook entry
        logActivity($dataDir, 'handover_confirmed', 'Internal cash transfer confirmed (no cashbook entry)',
            '$'.number_format($hov['amount'],2).' from '.$hov['from_name'].' HOV-'.$hovId);
        
        // ── Update cash_with on cashbook entries — cash has reached office ──
        try {
            $fromId = (int)$hov['from_id'];
            $store->getPdo()->prepare(
                "UPDATE cb_ledger SET cash_with = 'Office', cash_with_id = 0
                 WHERE cash_with_id = ? AND cash_with != 'Office' AND direction = 'in'"
            )->execute([$fromId]);
        } catch (\Throwable $_e) { /* non-fatal */ }
        
        // ── UPDATE UCRM PAYMENT NOTES with receipt info ──────────────────────────
        // Find collections from this agent that haven't been linked to a handover yet
        // and were created before this handover was submitted
        try {
            $allCols = $store->load('payment_collections.json') ?? [];
            $hovSubmittedAt = strtotime($hov['submitted_at'] ?? $hov['created_at'] ?? 'now');
            $fromId = (int)$hov['from_id'];
            $updatedCount = 0;
            
            foreach ($allCols as $idx => $col) {
                // Skip if not from this agent
                if ((int)($col['retailer_id'] ?? 0) !== $fromId) continue;
                // Skip if already linked to a handover
                if (!empty($col['handover_id'])) continue;
                // Skip if no CRM payment
                if (empty($col['crm_payment_id'])) continue;
                // Skip if created after handover was submitted
                $colTime = strtotime($col['created_at'] ?? '2000-01-01');
                if ($colTime > $hovSubmittedAt) continue;
                // Skip voided
                if (($col['status'] ?? '') === 'voided') continue;
                
                // Build structured note
                $origNote = $col['note'] ?? '';
                $location = 'Office';
                if (preg_match('/@\s*([^\|]+)/i', $origNote, $m)) {
                    $location = trim($m[1]);
                } elseif (!empty($hov['notes'])) {
                    $location = $hov['notes'];
                }
                
                $structuredNote = "Collected by {$hov['from_name']} via DishNet | @ {$location}\n" .
                    "────────────────────────────────\n" .
                    "CASH RECEIVED     : \$" . number_format((float)$col['amount'], 2) . "\n" .
                    "CASH RECEIVED BY  : " . ($retailer['name']) . "\n" .
                    "CASH RECEIPT NO   : " . ($cbReceiptNo ?: 'HOV-'.$hovId) . "\n" .
                    "CASH LOCATION     : " . $location . "\n" .
                    "CASHBOOK DATE     : " . date('d-m-Y');
                
                // PATCH UCRM payment
                $crmPayId = (int)$col['crm_payment_id'];
                $patchResult = $crm->patch("payments/{$crmPayId}", ['note' => $structuredNote]);
                
                if ($patchResult !== null) {
                    // Mark collection as linked to this handover
                    $allCols[$idx]['handover_id'] = $hovId;
                    $allCols[$idx]['handover_receipt'] = $cbReceiptNo;
                    $allCols[$idx]['handover_by'] = $retailer['name'];
                    $allCols[$idx]['handover_at'] = date('Y-m-d H:i:s');
                    $updatedCount++;
                }
            }
            
            if ($updatedCount > 0) {
                $store->save('payment_collections.json', $allCols);
                logActivity($dataDir, 'ucrm_notes_updated', 'UCRM payment notes updated on handover',
                    $updatedCount . ' payments updated with receipt #' . ($cbReceiptNo ?: 'HOV-'.$hovId));
            }
        } catch (\Throwable $ucrmErr) {
            logActivity($dataDir, 'ucrm_notes_update_failed', 'UCRM note update failed', $ucrmErr->getMessage());
        }
        // Notify the agent their handover was confirmed
        $agentRetailer = $store->findOne('retailers.json', 'id', (int)$hov['from_id']);
        if ($agentRetailer) {
            // Unified balance — reads fresh data after save, so confirmed handover is included (v4.4.23)
            if (!class_exists('DualReadCashPosition')) require_once dirname(__DIR__, 2) . '/lib/DualReadCashPosition.php';
            $_notifySvc  = new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? '');
            $cashBalance = $_notifySvc->getCashInHand((int)$hov['from_id']);
            try { $notify->remittanceApproved($agentRetailer, (float)$hov['amount'], $cashBalance, $retailer['name']); } catch (\Throwable $e) {}
        }
        flash('Handover of $'.number_format($hov['amount'],2).' from '.$hov['from_name'].' confirmed. Wallet credited.', 'success');
        // ── CRITICAL: save JSON BEFORE rebuild so snapshot reads confirmed status ──
        $store->save('cash_handovers.json', $handovers);
        $_hovSaved = true;
        // Update position snapshot — confirmed handover reduces agent exposure
        try {
            if (!class_exists('SnapshotService')) require_once dirname(__DIR__, 2) . '/lib/SnapshotService.php';
            (new SnapshotService($store->getPdo(), $store))->rebuild((int)$hov['from_id'], 'handover', 'HOV-'.$hovId);
        } catch (\Throwable $snErr) { /* non-fatal */ }
        // Dual-write: staff_ledger
        require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
        StaffLedgerWriter::onHandoverConfirmed($store->getPdo(), $hov);
        // Zero-crossing check: if exposure now == 0, archive settled events
        // so future snapshot rebuilds only scan the small active-event window
        try {
            if (!class_exists('ArchiveService')) require_once dirname(__DIR__, 2) . '/lib/ArchiveService.php';
            (new ArchiveService($store->getPdo(), $store))->maybeArchive((int)$hov['from_id']);
        } catch (\Throwable $arcErr) { /* non-fatal — nightly rebuildAll() corrects */ }
        break;
    }
    unset($hov);
    if (empty($_hovSaved)) $store->save('cash_handovers.json', $handovers);
    // field_accountant (Diko) returns to her own chain view; main accountant goes to settlement
    redirect($_isFieldAcct
        ? '?page=dashboard&tab=field_handover'
        : '?page=dashboard&tab=accounts_settlement'
    );
}

// ── CRM Auto-Match: resolve customer name → CRM ID from search index ────────
// Returns ['id'=>int,'name'=>string,'score'=>int] or null
/**
 * Resolve a customer phone number from CRM client ID.
 * Tries in order: (1) crm_enrich_cache.json clients_by_id (free, hourly cache),
 * (2) GET /clients/{id} live API call.
 * Returns '' if not found or CRM not configured.
 */
function _crmCustomerPhone(string $custId, \CrmApiClient $crm, \StoreInterface $store): string {
    if (!$custId || !(int)$custId) return '';
    $id = (int)$custId;

    // 1. Try local enrich cache (populated by SplynxTicketService hourly)
    $cache = $store->load('crm_enrich_cache.json');
    if (!empty($cache['clients_by_id'][$id])) {
        $c = $cache['clients_by_id'][$id];
        foreach (($c['contacts'] ?? []) as $ct) {
            if (!empty($ct['phone'])) return trim($ct['phone']);
        }
        if (!empty($c['phone'])) return trim($c['phone']);
    }

    // 2. Live API fetch (non-fatal)
    if (!$crm->isConfigured()) return '';
    try {
        $client = $crm->get("clients/{$id}");
        if (!$client) return '';
        foreach (($client['contacts'] ?? []) as $ct) {
            if (!empty($ct['phone'])) return trim($ct['phone']);
        }
        return trim($client['phone'] ?? '');
    } catch (\Throwable $e) {
        return '';
    }
}

function _crmAutoMatch(string $name, \StoreInterface $store): ?array {
    if (!$name) return null;
    $idx = $store->load('client_search_index.json') ?? [];
    if (empty($idx)) return null;

    $needle = strtolower(preg_replace('/\s+/', ' ', trim($name)));
    $needleWords = array_filter(explode(' ', $needle));
    $best = null; $bestScore = 0;

    foreach ($idx as $c) {
        $haystack = strtolower($c['search'] ?? $c['name'] ?? '');
        $cname    = strtolower($c['name'] ?? '');

        // Exact name match — highest confidence
        if ($cname === $needle) return ['id'=>(int)$c['id'],'name'=>$c['name'],'score'=>100];

        // All words of needle appear in haystack
        $wordMatches = 0;
        foreach ($needleWords as $w) {
            if (strlen($w) >= 3 && strpos($haystack, $w) !== false) $wordMatches++;
        }
        if (count($needleWords) >= 2 && $wordMatches === count($needleWords)) {
            $score = 80 + $wordMatches;
            if ($score > $bestScore) { $bestScore = $score; $best = ['id'=>(int)$c['id'],'name'=>$c['name'],'score'=>$score]; }
        }
        // Partial: first+last name both present (at least 4 chars each)
        if (count($needleWords) >= 2) {
            $first = $needleWords[array_key_first($needleWords)];
            $last  = $needleWords[array_key_last($needleWords)];
            if (strlen($first) >= 4 && strlen($last) >= 4
                && strpos($cname, $first) !== false && strpos($cname, $last) !== false) {
                $score = 75;
                if ($score > $bestScore) { $bestScore = $score; $best = ['id'=>(int)$c['id'],'name'=>$c['name'],'score'=>$score]; }
            }
        }
    }
    return ($bestScore >= 75) ? $best : null;
}

// ── Collect Payment ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='collect_payment') {
    $retailer = $auth->requireLogin();
    $rid = (int)$retailer['id'];
    $custName = trim($_POST['customer_name'] ?? '');
    $custId   = trim($_POST['crm_customer_id'] ?? '');

    // ── Auto-match CRM ID if agent submitted without selecting from search ────
    $autoMatched = false;
    if (!$custId && $custName) {
        $match = _crmAutoMatch($custName, $store);
        if ($match) {
            $custId      = (string)$match['id'];
            $custName    = $match['name']; // use canonical CRM name
            $autoMatched = true;
        }
    }
    $amount   = round((float)($_POST['amount'] ?? 0), 2);
    $method   = trim($_POST['payment_method'] ?? 'Cash');
    $note     = trim($_POST['payment_note'] ?? '');
    $svcType  = trim($_POST['service_type'] ?? 'starlink');
    $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
    if (!in_array($currency, ['USD','SSP'], true)) $currency = 'USD';
    
    // New enhanced fields
    $receiptNo    = trim($_POST['receipt_number'] ?? '');
    $location     = trim($_POST['collection_location'] ?? '');
    $sspAmount    = ($currency === 'SSP' || !empty($_POST['ssp_amount'])) ? round((float)($_POST['ssp_amount'] ?? 0), 0) : null;
    $invoiceNote  = trim($_POST['invoice_note'] ?? '');

    if (!$custName || $amount <= 0) {
        flash('Customer name and amount are required.', 'danger');
        redirect('?page=dashboard&tab=collect_payment');
    }

    // ── LARGE TRANSACTION APPROVAL GATE ──────────────────────────────────────
    $largeThreshold = (float)($config['large_txn_threshold'] ?? 500);
    $requiresApproval = $largeThreshold > 0 && $amount >= $largeThreshold && !($retailer['is_admin'] ?? false);
    if ($requiresApproval) {
        // Save as pending_approval collection instead of processing
        $pending = [
            'customer_name'   => $custName,
            'crm_customer_id' => $custId,
            'amount'          => $amount,
            'method'          => $method,
            'service_type'    => $svcType,
            'note'            => $note,
            'invoice_id'      => trim($_POST['invoice_id'] ?? ''),
            'retailer_id'     => $rid,
            'retailer_name'   => $retailer['name'],
            'status'          => 'pending_approval',
            'submitted_at'    => date('Y-m-d H:i:s'),
        ];
        $store->appendWithId('pending_collections.json', $pending);
        logActivity($dataDir, 'large_txn_flagged', 'Large payment pending admin approval',
            '$'.number_format($amount,2).' for '.$custName.' by '.$retailer['name']);
        flash('⏳ Payment of $'.number_format($amount,2).' exceeds the $'.number_format($largeThreshold,2).' limit and has been sent to admin for approval.', 'warning');
        redirect('?page=dashboard&tab=collect_payment');
    }

    // ── DUPLICATE PAYMENT GUARD ──────────────────────────────────────────────
    // Idempotency key: same agent + same customer + same amount + same day
    // A second submit within 10 minutes returns the cached passbook entry — no double debit
    $idemKey = 'PAY-' . $rid . '-' . md5($custId . '-' . $amount . '-' . date('Y-m-d'));
    // Stricter: also check payment_collections for a recent duplicate (within 5 min)
    $recentCols = $store->load('payment_collections.json') ?? [];
    $fiveMinAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
    foreach (array_reverse($recentCols) as $_rc) {
        if ((int)($_rc['retailer_id']??0) === $rid
            && trim($_rc['crm_customer_id']??'') === $custId
            && (float)($_rc['amount']??0) === $amount
            && ($_rc['created_at']??'') >= $fiveMinAgo) {
            flash('⚠️ Duplicate detected — this payment ($'.number_format($amount,2).' for '.$custName.') was already submitted '.
                  human_time_diff(strtotime($_rc['created_at'])).' ago. If this is intentional, wait 5 minutes and try again.', 'warning');
            redirect('?page=dashboard&tab=collect_payment');
        }
    }

    // ── BALANCE CHECK ─────────────────────────────────────────────────────────
    $balanceBefore = $wallet->getBalance($rid);
    if ($balanceBefore < $amount) {
        flash('Insufficient wallet balance ($' . number_format($balanceBefore, 2) . '). Top up first.', 'danger');
        redirect('?page=dashboard&tab=collect_payment');
    }

    // ── DEBIT with idempotency key ────────────────────────────────────────────
    $debitTrx = $wallet->debit($rid, $amount,
        "Payment collected: {$custName} ({$custId})",
        $retailer['name'], null, $custId, $idemKey, 'order_payment', $retailer['name']);
    $balanceAfter = $debitTrx['curr_balance'] ?? ($balanceBefore - $amount);

    // Auto-commission — only for external agents, not employees
    $isEmployee = !empty($retailer['is_employee']);
    $commRate = 0;
    if (!$isEmployee && !empty($config['commission_on_collection'])) {
        // Per-retailer rate if set, otherwise global config
        $retailerCommType = $retailer['commission_type'] ?? 'none';
        $retailerCommRate = (float)($retailer['commission_rate'] ?? 0);
        if ($retailerCommType !== 'none' && $retailerCommRate > 0) {
            $commRate = $retailerCommRate;
        } else {
            $commRate = (float)(
                $svcType === 'fiber'    ? ($config['fiber_commission_rate']    ?? $config['commission_rate'] ?? 5) :
                ($svcType === 'starlink' ? ($config['starlink_commission_rate'] ?? $config['commission_rate'] ?? 5) :
                ($config['commission_rate'] ?? 5))
            );
        }
    }
    $commAmount = 0;
    if ($commRate > 0) {
        $commAmount = round($amount * $commRate / 100, 2);
        $commIdemKey = 'COMM-' . $rid . '-' . md5($custId . '-' . $amount . '-' . date('Y-m-d'));
        $wallet->credit($rid, $commAmount,
            "Commission {$commRate}% on {$svcType} collection: {$custName}",
            'System', $commIdemKey, 'commission');
    }

    // Try to post payment to CRM
    $crmResult = null;
    $crmSuccess = false;
    $crmError   = '';
    $invoiceId    = trim($_POST['invoice_id'] ?? '');      // numeric UCRM invoice DB id
    $invoiceLabel = trim($_POST['invoice_label'] ?? $invoiceId); // display label e.g. INV012722

    // ── DIAGNOSTIC: Log why CRM push may be skipped ─────────────────────────
    $diagFile = $dataDir . '/payment_push_log.json';
    if (!$crm->isConfigured() || !$custId || !is_numeric($custId)) {
        $skipLog = [
            'timestamp'       => date('Y-m-d H:i:s'),
            'action'          => 'payment_push_SKIPPED',
            'reason'          => !$crm->isConfigured() ? 'CRM not configured' : (!$custId ? 'No CRM customer ID' : 'Non-CRM customer (LTE)'),
            'crm_configured'  => $crm->isConfigured(),
            'crm_base_url'    => $crm->getBaseUrl(),
            'custId'          => $custId,
            'customer_name'   => $custName,
            'amount'          => $amount,
            'retailer'        => $retailer['name'] ?? '',
        ];
        $diagLogs = file_exists($diagFile) ? json_decode(file_get_contents($diagFile), true) : [];
        if (!is_array($diagLogs)) $diagLogs = [];
        array_unshift($diagLogs, $skipLog);
        $diagLogs = array_slice($diagLogs, 0, 50);
        file_put_contents($diagFile, json_encode($diagLogs, JSON_PRETTY_PRINT));
    }

    if ($crm->isConfigured() && $custId) {
        // Use retailer's personal UCRM app key if set — so "Created By" in UCRM shows
        // the agent's name and triggers customer email/WhatsApp under their identity.
        // Fall back to global plugin key if no personal key is configured.
        $personalKey = $retailer['ucrm_app_key'] ?? null;
        $usingPersonalKey = !empty($personalKey);
        $crmForPayment = $usingPersonalKey
            ? new CrmApiClient(rtrim($crm->getBaseUrl(), '/'), $personalKey, 'X-Auth-App-Key')
            : $crm;
        
        // Log which key is being used (diagnostic)
        $keyDiag = [
            'retailer_id'       => $rid,
            'retailer_name'     => $retailer['name'] ?? '',
            'has_personal_key'  => $usingPersonalKey,
            'key_preview'       => $usingPersonalKey ? substr($personalKey, 0, 8) . '...' : 'using_plugin_key',
            'timestamp'         => date('Y-m-d H:i:s'),
        ];
        $keyLogFile = $dataDir . '/payment_key_log.json';
        $keyLogs = file_exists($keyLogFile) ? json_decode(file_get_contents($keyLogFile), true) : [];
        if (!is_array($keyLogs)) $keyLogs = [];
        array_unshift($keyLogs, $keyDiag);
        $keyLogs = array_slice($keyLogs, 0, 30);
        file_put_contents($keyLogFile, json_encode($keyLogs, JSON_PRETTY_PRINT));

        // ── LEAD CHECK: Convert lead to client before payment push ────────────
        // UCRM rejects payments to leads (HTTP 422). If customer is a lead,
        // auto-convert to active client so the payment goes through.
        $clientPreCheck = $crmForPayment->get("clients/{$custId}");
        if ($clientPreCheck && (int)($clientPreCheck['clientType'] ?? 1) === 1) {
            // clientType 1 = Lead, 2 = Active Client
            $convertResult = $crmForPayment->patch("clients/{$custId}", ['clientType' => 2]);
            if ($convertResult) {
                logActivity($dataDir, 'lead_auto_convert',
                    "Lead auto-converted to client: {$custName} (CRM #{$custId})",
                    "Converted during payment collection by {$retailer['name']}. Amount: \${$amount}"
                );
            } else {
                // Conversion failed — payment will likely fail too, but let it try
                logActivity($dataDir, 'lead_convert_failed',
                    "Lead conversion failed: {$custName} (CRM #{$custId})",
                    "UCRM returned: " . json_encode($crmForPayment->getLastError())
                );
            }
        }

        // ── PHANTOM REVENUE GUARD ──────────────────────────────────────────────
        // Prevents double-payment when customer pays online while agent is collecting
        if ($invoiceId && (int)$invoiceId > 0) {
            $invCheck = $crm->get("invoices/{$invoiceId}");
            if ($invCheck && isset($invCheck['id'])) {
                $invTotal    = (float)($invCheck['total'] ?? 0);
                $invPaid     = (float)($invCheck['amountPaid'] ?? 0);
                $invRemaining = round($invTotal - $invPaid, 2);
                if ($invRemaining <= 0) {
                    // Invoice already fully paid — would create phantom credit
                    // Refund wallet debit before rejecting
                    $wallet->credit($rid, $amount, "Refund: Invoice #{$invoiceLabel} already paid", 'System', $idemKey . '-REFUND', 'refund');
                    if ($commAmount > 0) {
                        $wallet->debit($rid, $commAmount, "Commission reversal: Invoice #{$invoiceLabel} already paid", 'System', null, null, $commIdemKey . '-REV', 'commission_reversal', 'System');
                    }
                    flash("⚠️ Invoice #{$invoiceLabel} is already paid (possibly by customer online). Collection cancelled — wallet refunded.", 'warning');
                    redirect('?page=dashboard&tab=collect_payment');
                }
                if ($amount > $invRemaining + 0.01) {
                    // Overpayment — would create phantom revenue
                    $wallet->credit($rid, $amount, "Refund: Amount exceeds invoice balance", 'System', $idemKey . '-REFUND', 'refund');
                    if ($commAmount > 0) {
                        $wallet->debit($rid, $commAmount, "Commission reversal: Overpayment rejected", 'System', null, null, $commIdemKey . '-REV', 'commission_reversal', 'System');
                    }
                    flash("⚠️ Amount (\${$amount}) exceeds invoice balance (\${$invRemaining}). Reduce to \${$invRemaining} or leave invoice blank for customer credit.", 'warning');
                    redirect('?page=dashboard&tab=collect_payment');
                }
            }
        } else {
            // No specific invoice — check client's total outstanding balance
            $clientCheck = $crm->get("clients/{$custId}");
            if ($clientCheck && isset($clientCheck['id'])) {
                $accountBalance = (float)($clientCheck['accountBalance'] ?? 0);
                // Negative = owes money, Positive = has credit
                if ($accountBalance >= 0) {
                    // Client has zero balance or credit — this would add more credit
                    // Allow but warn — some scenarios are legitimate (advance payment)
                    // Log for Rupesh to review
                    logActivity($dataDir, 'credit_payment_warning',
                        "Payment to client with zero/credit balance: {$custName} (CRM #{$custId})",
                        "\${$amount} collected by {$retailer['name']} — client balance was \${$accountBalance}. Review for phantom revenue."
                    );
                }
            }
        }

        // Build comprehensive CRM note
        $crmNoteParts = [];
        $crmNoteParts[] = "Collected by {$retailer['name']} via DishNet";
        if ($receiptNo) $crmNoteParts[] = "Receipt #{$receiptNo}";
        if ($location)  $crmNoteParts[] = "@ {$location}";
        if ($sspAmount) $crmNoteParts[] = "({$sspAmount} SSP received)";
        if ($invoiceLabel) $crmNoteParts[] = "(Inv #{$invoiceLabel})";
        if ($invoiceNote) $crmNoteParts[] = "INV: {$invoiceNote}";
        if ($note) $crmNoteParts[] = $note;
        if ($autoMatched) $crmNoteParts[] = '[CRM ID auto-matched]';
        $crmNote = implode(' | ', array_filter($crmNoteParts));
        
        // Unique reference for duplicate detection in UCRM
        // Format: PAY-{retailer_id}-{customer_id}-{timestamp}
        $paymentRef = 'PAY-' . $rid . '-' . $custId . '-' . date('YmdHis');
        $crmNote .= ' | Ref: ' . $paymentRef;

        $crmPayload = [
            'clientId'     => (int)$custId,
            'methodId'     => PaymentUuids::resolve($method),
            'amount'       => $amount,
            'currencyCode' => 'USD',
            'note'         => $crmNote,
        ];
        if ($invoiceId && (int)$invoiceId > 0) {
            $crmPayload['applyToInvoicesAutomatically'] = true;
            $crmPayload['note'] .= ' | Invoice: ' . $invoiceLabel;
        } else {
            $crmPayload['applyToInvoicesAutomatically'] = true;
        }

        // ── DIAGNOSTIC LOG: Capture full request/response ───────────────────
        $diagLog = [
            'timestamp'       => date('Y-m-d H:i:s'),
            'action'          => 'payment_push',
            'retailer'        => $retailer['name'] ?? '',
            'customer_name'   => $custName,
            'crm_customer_id' => $custId,
            'amount'          => $amount,
            'method'          => $method,
            'method_uuid'     => PaymentUuids::resolve($method),
            'crm_configured'  => $crm->isConfigured(),
            'crm_base_url'    => $crm->getBaseUrl(),
            'payload_sent'    => $crmPayload,
            'payment_ref'     => $paymentRef,
        ];

        // ── DUPLICATE PREVENTION: Check UCRM before creating ──
        $crmResult  = $crmForPayment->createPaymentSafe($crmPayload, $paymentRef);
        $crmSuccess = !empty($crmResult['success']) && !empty($crmResult['id']);
        $wasDuplicate = !empty($crmResult['duplicate']);

        // Add result to diagnostic log
        $diagLog['crm_response']   = $crmResult;
        $diagLog['crm_success']    = $crmSuccess;
        $diagLog['was_duplicate']  = $wasDuplicate;
        $diagLog['last_error']     = $crmForPayment->getLastError();

        // Save to diagnostic log file
        $diagFile = $dataDir . '/payment_push_log.json';
        $diagLogs = file_exists($diagFile) ? json_decode(file_get_contents($diagFile), true) : [];
        if (!is_array($diagLogs)) $diagLogs = [];
        array_unshift($diagLogs, $diagLog); // newest first
        $diagLogs = array_slice($diagLogs, 0, 50); // keep last 50
        file_put_contents($diagFile, json_encode($diagLogs, JSON_PRETTY_PRINT));

        if (!$crmSuccess) {
            $lastErr   = $crmForPayment->getLastError();
            $crmError  = isset($lastErr['http_code'])
                ? "HTTP {$lastErr['http_code']}: " . (is_array($lastErr['response'] ?? null) ? json_encode($lastErr['response']) : ($lastErr['response'] ?? ''))
                : ($lastErr['curl_error'] ?? json_encode($lastErr));
            // Log so it appears in activity log and is visible to admin
            logActivity($dataDir, 'crm_payment_failed',
                "CRM payment POST failed for {$custName} (CRM #{$custId})",
                '$'.number_format($amount,2).' | Error: '.$crmError
            );
            // Queue for retry by cron_sync
            $store->appendWithId('crm_payment_retry.json', [
                'collection_id' => null, // filled after collection is saved
                'customer_name' => $custName,
                'crm_client_id' => $custId,
                'payload'       => $crmPayload,
                'error'         => $crmError,
                'attempts'      => 1,
                'next_retry_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                'created_at'    => date('Y-m-d H:i:s'),
                'status'        => 'pending',
            ]);
        }
    }

    // Log the collection (atomic ID)
    // Auto-derive project from service_type
    $_svcProjectMap = ['fiber'=>'dishnet','starlink'=>'dishnet','lte'=>'4g','bluecard'=>'bluecard'];
    $_colProject = $_svcProjectMap[$svcType] ?? 'dishnet';
    $collection = [
        'retailer_id'     => $rid,
        'retailer_name'   => $retailer['name'],
        'customer_name'   => $custName,
        'invoice_id'      => trim($_POST['invoice_id'] ?? ''),
        'crm_customer_id' => $custId,
        'amount'          => $amount,
        'method'          => $method,
        'service_type'    => $svcType,
        'project'         => $_colProject,
        'note'            => $note,
        'receipt_number'  => $receiptNo,
        'location'        => $location,
        'ssp_amount'      => $sspAmount,
        'invoice_note'    => $invoiceNote,
        'crm_note'        => $crmNote ?? '',
        'commission'      => $commAmount,
        'comm_rate'       => $commRate,
        'crm_synced'      => $crmSuccess,
        'crm_auto_matched'=> $autoMatched ? true : false,
        'crm_payment_id'  => ($crmResult && isset($crmResult['id'])) ? $crmResult['id'] : null,
        'crm_response'    => $crmResult ? json_encode($crmResult) : null,
        'sync_attempts'   => ($crm->isConfigured() && $custId) ? 1 : 0,  // Track if we attempted sync
        'last_sync_attempt' => ($crm->isConfigured() && $custId) ? date('Y-m-d H:i:s') : null,
        'created_at'      => date('Y-m-d H:i:s'),
    ];
    $collection = $store->appendWithId('payment_collections.json', $collection);

    // Update position snapshot — collection adds to agent exposure
    try {
        if (!class_exists('SnapshotService')) require_once dirname(__DIR__, 2) . '/lib/SnapshotService.php';
        (new SnapshotService($store->getPdo(), $store))->rebuild($rid, 'collection', 'COL-' . $collection['id']);
    } catch (\Throwable $snErr) { /* non-fatal — nightly worker corrects */ }

    // Backfill collection_id into retry queue entry (was null when enqueued)
    if (!$crmSuccess && $custId) {
        $retryQueue = $store->load('crm_payment_retry.json') ?? [];
        foreach ($retryQueue as $i => $rq) {
            if (($rq['collection_id'] ?? null) === null
                && $rq['crm_client_id'] === $custId
                && abs(strtotime($rq['created_at']) - time()) < 30) {
                $retryQueue[$i]['collection_id'] = $collection['id'];
                break;
            }
        }
        $store->save('crm_payment_retry.json', $retryQueue);
    }

    // ── AUDIT TRAIL — with before/after balance ──────────────────────────────
    $store->appendWithId('activity_log.json', [
        'event'         => 'payment_collected',
        'actor'         => $retailer['name'],
        'actor_id'      => $rid,
        'action'        => 'DEBIT',
        'entity'        => 'payment',
        'entity_id'     => $collection['id'],
        'customer'      => $custName,
        'crm_id'        => $custId,
        'amount'        => $amount,
        'method'        => $method,
        // ── the critical before/after fields ──
        'balance_before'=> round($balanceBefore, 2),
        'balance_after' => round($balanceAfter, 2),
        'commission'    => $commAmount,
        'idem_key'      => $idemKey,
        'crm_synced'    => $crmSuccess,
        'detail'        => '$'.number_format($amount,2).' from '.$custName
                           .' | wallet '.'$'.number_format($balanceBefore,2).' → '.'$'.number_format($balanceAfter,2)
                           .' | commission $'.number_format($commAmount,2)
                           .' | CRM '.($crmSuccess?'synced':'pending'),
        'ref_id'        => $collection['id'],
        'created_at'    => date('Y-m-d H:i:s'),
    ]);
    logActivity($dataDir, 'payment_collected', 'Payment collected',
        '$'.number_format($amount,2).' from '.$custName.' by '.$retailer['name']
        .' | '.'$'.number_format($balanceBefore,2).' → '.'$'.number_format($balanceAfter,2));

    // ── CASHBOOK: Direct post to cb_ledger for instant visibility ───────────────
    // v4.11.3: Posts immediately so Rupesh sees it in the main cashbook without
    // waiting for the CRM webhook round-trip. Uses crm_payment_id for dedup —
    // webhook.php checks this column and skips if already posted.
    if ($amount > 0 && $currency === 'USD') {
        try {
            $cbPdo = $store->getPdo();
            $cbPdo->exec("CREATE TABLE IF NOT EXISTS cb_ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project TEXT DEFAULT 'dishnet', date TEXT, direction TEXT,
                amount REAL, currency TEXT DEFAULT 'USD', category TEXT,
                category_raw TEXT, person TEXT, description TEXT,
                validation_ref TEXT, validation_status TEXT DEFAULT 'pending',
                status TEXT DEFAULT 'approved', approved_by TEXT,
                crm_payment_id TEXT, crm_client_id TEXT, source TEXT, created_at TEXT
            )");

            $_cbCrmPayId = ($crmSuccess && !empty($crmResult['id'])) ? (int)$crmResult['id'] : null;
            $_cbColId    = $collection['id'] ?? 0;
            $_cbRef      = $_cbCrmPayId ? "PAY-{$_cbCrmPayId}" : "COL-{$_cbColId}";

            // Dedup — skip if webhook or catchup already posted this payment
            $_cbDup = $cbPdo->prepare(
                "SELECT id FROM cb_ledger WHERE validation_ref = ? OR (crm_payment_id = ? AND crm_payment_id IS NOT NULL AND direction = 'in') LIMIT 1"
            );
            $_cbDup->execute([$_cbRef, $_cbCrmPayId]);

            if (!$_cbDup->fetch()) {
                $_cbInvLabel = trim($_POST['invoice_label'] ?? $_POST['invoice_id'] ?? '');
                $_cbDesc = "Payment received from {$custName} (CRM #{$custId})";
                if ($_cbInvLabel) $_cbDesc .= " against {$_cbInvLabel}";
                $_cbDesc .= " — collected by " . ($retailer['name'] ?? 'Agent');
                if ($note) $_cbDesc .= " | {$note}";

                $cbStmt = $cbPdo->prepare(
                    "INSERT INTO cb_ledger (project, date, direction, amount, currency, category,
                        category_raw, person, description, validation_ref, validation_status,
                        status, approved_by, crm_payment_id, crm_client_id, source,
                        cash_with, cash_with_id, created_at)
                     VALUES ('dishnet', ?, 'in', ?, 'USD', 'Receipt', 'Collection',
                        ?, ?, ?, 'wr', 'approved', 'Auto-Collection',
                        ?, ?, 'collect_payment',
                        ?, ?, ?)"
                );
                $cbStmt->execute([
                    date('Y-m-d'),
                    $amount,
                    $retailer['name'] ?? '',
                    $_cbDesc,
                    $_cbRef,
                    $_cbCrmPayId,
                    $custId ?: null,
                    $retailer['name'] ?? 'Office',
                    (int)($retailer['id'] ?? 0),
                    date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $_cbErr) {
            // Non-fatal — webhook/catchup will handle it
            error_log("CollectPayment: cashbook direct post failed: " . $_cbErr->getMessage());
        }
    }

    // ── STAFF LEDGER — record collection so DualReadCashPosition is accurate ──
    // Without this, agent cash-in-hand balance is wrong (BBC -$200 bug Mar 2026).
    try {
        require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
        StaffLedgerWriter::onCollection($store->getPdo(), array_merge($collection, [
            'client_name'  => $custName,
            'collected_at' => date('Y-m-d H:i:s'),
        ]));
    } catch (\Throwable $_slErr) {
        error_log("CollectPayment: StaffLedger write failed: " . $_slErr->getMessage());
    }


    $msg = "$" . number_format($amount, 2) . " collected from {$custName}.";
    if ($commAmount > 0) $msg .= " Commission: +$" . number_format($commAmount, 2) . " ({$commRate}%).";
    if ($crmSuccess) {
        $msg .= " ✅ Payment posted to CRM (ID #{$crmResult['id']}).";
    } else if ($custId) {
        $msg .= " ⚠️ CRM sync failed — queued for retry. Error: " . substr($crmError, 0, 120);
    }

    // ── WhatsApp payment receipt to customer (Accounts number) ───────────────
    // Uses dedupMark() (notification_dedup SQLite table) — same system webhook.php
    // uses — so webhook can never double-send after this fires first.
    // Also queues receipt PDF for cron_quote_wa.php pickup (same as webhook.php).
    try {
        $custPhone = _crmCustomerPhone($custId, $crm, $store);
        if ($custPhone) {
            $crmPayId  = ($crmSuccess && !empty($crmResult['id'])) ? (int)$crmResult['id'] : 0;
            $txnRef    = $crmPayId ? 'PAY-' . $crmPayId : 'COL-' . ($collection['id'] ?? '');
            $dedupKey  = $crmPayId ? 'PAY' . $crmPayId : 'COL' . ($collection['id'] ?? uniqid());

            if (!$notify->dedupMark($dedupKey)) {
                // Already sent (unlikely from field flow but guard against double-tap)
                error_log("[post_field] WA payment receipt SKIPPED — already sent for {$dedupKey}");
            } else {
                $notify->paymentReceived($custPhone, $custName, $amount, $txnRef);

                // Queue receipt PDF — cron_quote_wa.php picks this up in ~5 min
                // Only queue if we have a real CRM payment ID (needed to fetch PDF from UCRM)
                if ($crmPayId > 0) {
                    $receiptQueue = $store->load('receipt_pdf_queue.json') ?? [];
                    $_alreadyQueued = false;
                    foreach ($receiptQueue as $_rqi) {
                        if ((int)($_rqi['payment_id'] ?? 0) === $crmPayId) {
                            $_alreadyQueued = true;
                            break;
                        }
                    }
                    if (!$_alreadyQueued) {
                        $receiptQueue[] = [
                            'payment_id'    => $crmPayId,
                            'phone'         => $custPhone,
                            'customer_name' => $custName,
                            'amount'        => $amount,
                            'queued_at'     => time(),
                            'sent'          => false,
                            'source'        => 'post_field',
                        ];
                        $store->save('receipt_pdf_queue.json', array_values($receiptQueue));
                    }
                }
            }
        }
    } catch (\Throwable $waErr) { /* non-fatal — WA down should never block payment */ }

    flash($msg, $crmSuccess || !$custId ? 'success' : 'warning');
    redirect('?page=dashboard&tab=collect_payment');
}


// ── Approve individual staff payment ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='approve_staff_payment') {
    if (!($retailer['is_admin']??false) && ($retailer['role']??'')<>'accountant') { http_response_code(403); exit; }
    $expId   = (int)($_POST['expense_id'] ?? 0);
    $sspRate = (float)($_POST['ssp_rate'] ?? 0);
    $expenses = $store->load('cash_expenses.json') ?: [];
    foreach ($expenses as &$exp) {
        if ((int)($exp['id']??0) !== $expId || ($exp['status']??'') !== 'pending') continue;
        $cur     = $exp['currency'] ?? 'USD';
        $sspAmt  = (float)($exp['ssp_amount'] ?? 0);
        $usdAmt  = $cur === 'SSP' && $sspRate > 0 ? round($sspAmt / $sspRate, 2) : (float)($exp['amount'] ?? 0);
        $exp['status']        = 'approved';
        $exp['approved_by']   = $retailer['name'];
        $exp['approved_at']   = date('Y-m-d H:i:s');
        $exp['amount']        = $usdAmt;
        if ($cur === 'SSP' && $sspRate > 0) $exp['ssp_rate'] = $sspRate;
        // Post to cashbook
        // v4.9.9: fixed 'reference' → 'validation_ref' (reference was silently ignored)
        //         + dedup guard prevents double-post
        try {
            require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
            $cb = new CashbookService($store, $dataDir);
            $payType = $exp['staff_payment_type'] ?? $exp['expense_type'] ?? 'Staff Payment';
            $staffNm = $exp['staff_name'] ?? 'Staff';
            $_spRef  = 'STAFF-' . $expId;
            $_dupChk = $store->getPdo()->prepare("SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1");
            $_dupChk->execute([$_spRef]);
            if (!$_dupChk->fetch()) {
            $cb->addEntryRaw([
                'direction'       => 'out',
                'currency'        => $cur,
                'amount'          => $usdAmt,
                'ssp_amount'      => $cur === 'SSP' ? $sspAmt : null,
                'ssp_rate'        => $cur === 'SSP' ? $sspRate : null,
                'category'        => $payType,
                'description'     => $payType . ' — ' . $staffNm . ' (via ' . ($exp['collector_name']??'Field') . ')',
                'validation_ref'  => $_spRef,
                'project'         => 'main',
                'source'          => 'staff_payment',
            ]);

            // ── AUTO-LINK: Staff payment → Cash IN for receiving staff ──
            // When Diko pays BBC, create a cash_ins.json entry so BBC sees it.
            // v4.11.0: Skip personal pay categories — salary is the employee's money,
            // not accountable field cash. Only operational categories auto-link.
            $_spPersonalCats = ['Salary','Transport Allowance','Food Allowance',
                                'Bonus','Employee Benefit'];
            $_spStaffLower = strtolower(trim($staffNm));
            if ($_spStaffLower && $_spStaffLower !== 'staff'
                && !in_array($payType, $_spPersonalCats, true)) {
                $_spRetailers = $store->load('retailers.json') ?? [];
                $_spMatchId   = 0;
                $_spMatchName = '';
                $_spMatchPhone = '';
                foreach ($_spRetailers as $_spr) {
                    if (empty($_spr['is_active'])) continue;
                    $_sprName = strtolower($_spr['name'] ?? '');
                    if ($_sprName === $_spStaffLower) { $_spMatchId = (int)$_spr['id']; $_spMatchName = $_spr['name']; $_spMatchPhone = $_spr['phone'] ?? ''; break; }
                    if ($_sprName && (strpos($_sprName, $_spStaffLower) !== false || strpos($_spStaffLower, $_sprName) !== false)) {
                        $_spMatchId = (int)$_spr['id']; $_spMatchName = $_spr['name']; $_spMatchPhone = $_spr['phone'] ?? '';
                    }
                }
                if ($_spMatchId > 0) {
                    $_spCashIns = $store->load('cash_ins.json') ?? [];
                    $_spDup = false;
                    foreach ($_spCashIns as $_sci) {
                        if (($_sci['cb_ref'] ?? '') === $_spRef && $_spRef !== '') { $_spDup = true; break; }
                    }
                    if (!$_spDup) {
                        $_spCiCat  = ($cur === 'SSP') ? 'SSP Received' : 'USD Received';
                        $_spCiDesc = 'From ' . ($exp['collector_name'] ?? 'Field') . ' — ' . $payType;
                        $_spCashIns[] = [
                            'id'            => count($_spCashIns) + 1,
                            'collector_id'  => $_spMatchId,
                            'collector_name'=> $_spMatchName,
                            'amount'        => $cur === 'USD' ? $usdAmt : 0,
                            'currency'      => $cur,
                            'ssp_amount'    => $cur === 'SSP' ? $sspAmt : 0,
                            'usd_given'     => 0,
                            'rate'          => 0,
                            'category'      => $_spCiCat,
                            'description'   => $_spCiDesc,
                            'status'        => 'approved',
                            'approved_by'   => 'auto (staff payment link)',
                            'approved_at'   => date('Y-m-d H:i:s'),
                            'cb_ref'        => $_spRef,
                            'created_at'    => date('Y-m-d H:i:s'),
                        ];
                        $store->save('cash_ins.json', $_spCashIns);
                        logActivity($dataDir, 'staff_payment_auto_link',
                            "Auto-created Cash IN for {$_spMatchName}: {$cur} " .
                            ($cur === 'SSP' ? number_format($sspAmt, 0) : '$'.number_format($usdAmt, 2)) .
                            " (cb_ref: {$_spRef})", '');

                        // WhatsApp notification to receiving staff
                        if ($_spMatchPhone) {
                            try {
                                $notify->staffCashReceived($_spMatchPhone, $_spMatchName,
                                    $cur, $usdAmt, $sspAmt,
                                    $payType, $exp['collector_name'] ?? 'Field', $_spRef);
                            } catch (\Throwable $e) {}
                        }
                    }
                }
            }

            } // end dedup guard
        } catch (\Throwable $e) {}
        break;
    }
    unset($exp);
    $store->save('cash_expenses.json', $expenses);
    $_SESSION['hq_flash'] = ['type'=>'success','msg'=>'Staff payment approved and posted to cashbook.'];
    header('Location: ?page=dashboard&tab=handover_queue&hq_section=staff&hq_sp_status=pending');
    exit;
}

// ── Batch approve all pending staff payments ──────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='batch_approve_staff') {
    if (!($retailer['is_admin']??false) && ($retailer['role']??'')<>'accountant') { http_response_code(403); exit; }
    $expenses = $store->load('cash_expenses.json') ?: [];
    $approved = 0;
    $totalUsd = 0.0;
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $sysRate = $cb->getExchangeRate() ?: 5180.0;
    foreach ($expenses as &$exp) {
        if (($exp['status']??'') !== 'pending') continue;
        if (empty($exp['is_staff_payment']) && empty($exp['staff_name'])) continue;
        $cur    = $exp['currency'] ?? 'USD';
        $sspAmt = (float)($exp['ssp_amount'] ?? 0);
        $rate   = (float)($exp['ssp_rate'] ?? $sysRate);
        $usdAmt = $cur === 'SSP' ? round($sspAmt / $rate, 2) : (float)($exp['amount'] ?? 0);
        $exp['status']        = 'approved';
        $exp['approved_by']   = $retailer['name'];
        $exp['approved_at']   = date('Y-m-d H:i:s');
        $exp['amount']        = $usdAmt;
        if ($cur === 'SSP') $exp['ssp_rate'] = $rate;
        $payType = $exp['staff_payment_type'] ?? $exp['expense_type'] ?? 'Staff Payment';
        $staffNm = $exp['staff_name'] ?? 'Staff';
        // v4.9.9: fixed 'reference' → 'validation_ref' + dedup guard
        $_bspRef = 'BATCH-STAFF-' . ($exp['id']??0);
        try {
            $_dupChk = $store->getPdo()->prepare("SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1");
            $_dupChk->execute([$_bspRef]);
            if (!$_dupChk->fetch()) {
            $cb->addEntryRaw([
                'direction'       => 'out',
                'currency'        => $cur,
                'amount'          => $usdAmt,
                'ssp_amount'      => $cur === 'SSP' ? $sspAmt : null,
                'ssp_rate'        => $cur === 'SSP' ? $rate   : null,
                'category'        => $payType,
                'description'     => $payType . ' — ' . $staffNm . ' (batch approved)',
                'validation_ref'  => $_bspRef,
                'project'         => 'main',
                'source'          => 'staff_payment',
            ]);

            // ── AUTO-LINK: Batch staff payment → Cash IN for receiving staff ──
            // v4.11.0: Skip personal pay categories (same guard as single-approve path)
            $_bPersonalCats = ['Salary','Transport Allowance','Food Allowance',
                               'Bonus','Employee Benefit'];
            $_bStaffLower = strtolower(trim($staffNm));
            if ($_bStaffLower && $_bStaffLower !== 'staff'
                && !in_array($payType, $_bPersonalCats, true)) {
                if (!isset($_bRetailers)) $_bRetailers = $store->load('retailers.json') ?? [];
                $_bMatchId   = 0;
                $_bMatchName = '';
                $_bMatchPhone = '';
                foreach ($_bRetailers as $_br) {
                    if (empty($_br['is_active'])) continue;
                    $_brName = strtolower($_br['name'] ?? '');
                    if ($_brName === $_bStaffLower) { $_bMatchId = (int)$_br['id']; $_bMatchName = $_br['name']; $_bMatchPhone = $_br['phone'] ?? ''; break; }
                    if ($_brName && (strpos($_brName, $_bStaffLower) !== false || strpos($_bStaffLower, $_brName) !== false)) {
                        $_bMatchId = (int)$_br['id']; $_bMatchName = $_br['name']; $_bMatchPhone = $_br['phone'] ?? '';
                    }
                }
                if ($_bMatchId > 0) {
                    if (!isset($_bCashIns)) $_bCashIns = $store->load('cash_ins.json') ?? [];
                    $_bDup = false;
                    foreach ($_bCashIns as $_bci) {
                        if (($_bci['cb_ref'] ?? '') === $_bspRef && $_bspRef !== '') { $_bDup = true; break; }
                    }
                    if (!$_bDup) {
                        $_bCiCat  = ($cur === 'SSP') ? 'SSP Received' : 'USD Received';
                        $_bCiDesc = 'From ' . ($exp['collector_name'] ?? 'Field') . ' — ' . $payType;
                        $_bCashIns[] = [
                            'id'            => count($_bCashIns) + 1,
                            'collector_id'  => $_bMatchId,
                            'collector_name'=> $_bMatchName,
                            'amount'        => $cur === 'USD' ? $usdAmt : 0,
                            'currency'      => $cur,
                            'ssp_amount'    => $cur === 'SSP' ? $sspAmt : 0,
                            'usd_given'     => 0,
                            'rate'          => 0,
                            'category'      => $_bCiCat,
                            'description'   => $_bCiDesc,
                            'status'        => 'approved',
                            'approved_by'   => 'auto (batch staff link)',
                            'approved_at'   => date('Y-m-d H:i:s'),
                            'cb_ref'        => $_bspRef,
                            'created_at'    => date('Y-m-d H:i:s'),
                        ];
                        // Save after each to avoid losing data if loop errors
                        $store->save('cash_ins.json', $_bCashIns);
                        logActivity($dataDir, 'staff_payment_auto_link',
                            "Batch: Auto-created Cash IN for {$_bMatchName}: {$cur} " .
                            ($cur === 'SSP' ? number_format($sspAmt, 0) : '$'.number_format($usdAmt, 2)) .
                            " (cb_ref: {$_bspRef})", '');

                        // WhatsApp notification to receiving staff
                        if ($_bMatchPhone) {
                            try {
                                $notify->staffCashReceived($_bMatchPhone, $_bMatchName,
                                    $cur, $usdAmt, $sspAmt,
                                    $payType, $exp['collector_name'] ?? 'Field', $_bspRef);
                            } catch (\Throwable $e) {}
                        }
                    }
                }
            }

            } // end dedup guard
        } catch (\Throwable $e) {}
        $approved++;
        $totalUsd += $usdAmt;
    }
    unset($exp);
    $store->save('cash_expenses.json', $expenses);
    $_SESSION['hq_flash'] = ['type'=>'success','msg'=>$approved.' staff payment(s) approved — $'.number_format($totalUsd,2).' posted to cashbook.'];
    header('Location: ?page=dashboard&tab=handover_queue&hq_section=staff&hq_sp_status=approved');
    exit;
}

