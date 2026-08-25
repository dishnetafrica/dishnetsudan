<?php
// ═══════════════════════════════════════════════════════════════
// EXPENSES / CASHBOOK
// ═══════════════════════════════════════════════════════════════


// ── Log Expense (Diko) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='log_expense') {
    $retailer  = $auth->requireLogin();
    $rid       = (int)$retailer['id'];
    $currency  = strtoupper(trim($_POST['expense_currency'] ?? $_POST['currency'] ?? 'USD'));
    if (!in_array($currency, ['USD','SSP'], true)) $currency = 'USD';
    $rawAmount = round((float)($_POST['expense_amount'] ?? $_POST['amount'] ?? 0), 2);
    // SSP expenses store the amount in ssp_amount field, not amount
    if ($currency === 'SSP' && $rawAmount <= 0) {
        $rawAmount = round((float)($_POST['ssp_amount'] ?? 0), 2);
    }
    $category  = trim($_POST['expense_category'] ?? $_POST['category'] ?? '');
    $desc      = trim($_POST['expense_desc'] ?? $_POST['description'] ?? '');

    // ── Backdate guard (v4.11.3) ──────────────────────────────────────────
    // Field staff may not submit expenses older than 2 days via wallet/web form.
    // Accountants and admins are exempt.
    $_expSubmitDate = trim($_POST['expense_date'] ?? '');
    if ($_expSubmitDate) {
        $_isPrivilegedRole = in_array($retailer['role'] ?? '', ['accountant', 'field_accountant'], true)
                          || !empty($retailer['is_admin']);
        if (!$_isPrivilegedRole) {
            if ($_expSubmitDate > date('Y-m-d')) {
                flash('Expense date cannot be in the future.', 'danger');
                redirect('?page=dashboard&tab=wallet');
            }
            if ($_expSubmitDate < date('Y-m-d', strtotime('-2 days'))) {
                flash('Expense date ' . $_expSubmitDate . ' is too old. You can only submit expenses from the last 2 days. Contact Rupesh for older entries.', 'danger');
                redirect('?page=dashboard&tab=wallet');
            }
        }
    }

    // SSP expenses: Diko just enters SSP amount — NO rate from her side.
    // Rate is applied by Rupesh at approval time (same as main cashbook).
    // For USD validation of cash-in-hand: SSP expenses are tracked separately,
    // they do NOT reduce her USD cash-in-hand until Rupesh approves.

    if ($rawAmount <= 0 || !$category) {
        flash('Amount and category are required.', 'danger');
        redirect('?page=dashboard&tab=wallet');
    }

    // For USD expenses: validate against USD cash-in-hand (same source as My Cash display)
    // Field accountant: bypass ALL USD checks — all expenses go to Rupesh for approval.
    // Diko may hold physical cash (advance, payroll, or over-handover reimbursement)
    // that isn't fully tracked. Rupesh is the approval gate, not the balance check.
    $isFieldAcctRole = in_array($retailer['role'] ?? '', ['field_accountant']);
    if ($currency === 'USD' && !$isFieldAcctRole) {
        require_once dirname(__DIR__, 2) . '/lib/DualReadCashPosition.php';
        $_posCheck = new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? '');
        $usdInHand = $_posCheck->getCashInHand($rid);
        if ($rawAmount > $usdInHand + 0.01) {
            flash('Expense ($'.number_format($rawAmount,2).') exceeds your USD cash in hand ($'.number_format(max(0,$usdInHand),2).'). You cannot log an expense for more than you are holding.', 'danger');
            redirect('?page=dashboard&tab=wallet');
        }
    }

    // For SSP expenses: validate against SSP holding balance via JSON-first source.
    // v4.12.15 — was DualReadCashPosition (SQL staff_ledger, can be stale);
    // switched to StaffCashPositionService (JSON, authoritative) to match the hero.
    if ($currency === 'SSP') {
        if (!class_exists('StaffCashPositionService')) require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
        $_sspBal = max(0, round((new StaffCashPositionService($store, $store->getPdo()))->getSSPBalance($rid), 0));
        if ($rawAmount > $_sspBal + 1) {
            flash('SSP expense ('.number_format($rawAmount,0).' SSP) exceeds your SSP balance ('.number_format($_sspBal,0).' SSP). Cancel a wrong entry or ask Rupesh for more SSP.', 'danger');
            redirect('?page=dashboard&tab=wallet');
        }
    }

    $photoPath = null;
    if (!empty($_FILES['expense_photo']['tmp_name']) || !empty($_FILES['photo']['tmp_name'])) {
        $_photoKey = !empty($_FILES['expense_photo']['tmp_name']) ? 'expense_photo' : 'photo';
        $uploadsDir = $dataDir . '/uploads/expenses';
        if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
        $ext   = strtolower(pathinfo($_FILES[$_photoKey]['name'], PATHINFO_EXTENSION));
        $fname = 'exp-' . $rid . '-' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES[$_photoKey]['tmp_name'], $uploadsDir . '/' . $fname)) {
            $photoPath = 'uploads/expenses/' . $fname;
            // Compress: max 1200px, 70% quality → ~100KB from 2-3MB
            require_once dirname(__DIR__, 2) . '/lib/ImageCompressor.php';
            compressImage($uploadsDir . '/' . $fname);
        }
    }

    $staffName    = trim($_POST['staff_name']         ?? '');
    $staffPayType = trim($_POST['staff_payment_type'] ?? '');
    $expType      = trim($_POST['expense_type']       ?? $category);
    // Staff payments from field_accountant: skip USD-in-hand validation
    // (Rupesh gave her a separate advance for payroll)
    $isStaffPay   = in_array($category, ['Salary','Advance','Fuel','Transport','Bonus','Other']) && !empty($staffName);

    // Auto-approve logic:
    // 1. field_accountant/accountant → ALL their expenses auto-approve (they are trusted)
    // 2. SSP under limit → auto-approve for any role (small field costs)
    $sspAutoLimit = (float)($config['ssp_auto_approve_limit'] ?? 500000);
    $isFieldAcct  = in_array($retailer['role'] ?? '', ['field_accountant', 'accountant'], true)
                 || !empty($retailer['is_admin']);
    $autoApprove  = $isFieldAcct
                 || ($currency === 'SSP' && $rawAmount <= $sspAutoLimit && !$isStaffPay);

    $store->appendWithId('cash_expenses.json', [
        'collector_id'       => $rid,
        'collector_name'     => $retailer['name'],
        'amount'             => $currency === 'USD' ? $rawAmount : 0,
        'currency'           => $currency,
        'ssp_amount'         => $currency === 'SSP' ? $rawAmount : null,
        'ssp_rate'           => null,
        'category'           => $category,
        'expense_type'       => $expType,
        'staff_name'         => $staffName ?: null,
        'staff_payment_type' => $staffPayType ?: null,
        'is_staff_payment'   => $isStaffPay,
        'description'        => $desc,
        'photo'              => $photoPath,
        'status'             => $autoApprove ? 'approved' : 'pending',
        'auto_approved'      => $autoApprove,
        'approved_by'        => $autoApprove ? 'System (SSP auto)' : null,
        'approved_at'        => $autoApprove ? date('Y-m-d H:i:s') : null,
        'submitted_at'       => date('Y-m-d H:i:s'),
        'created_at'         => date('Y-m-d H:i:s'),
    ]);

    // ── Phase 3: Dual-write to unified staff_expenses SQLite ──
    try {
        $_allExpJson = $store->load('cash_expenses.json') ?: [];
        $_lastJsonId = 0;
        foreach ($_allExpJson as $_ej) { $_lastJsonId = max($_lastJsonId, (int)($_ej['id'] ?? 0)); }
        $_pdo3 = $store->getPdo();
        // Check if source column exists (migration 043)
        $_pdo3->query("SELECT source FROM staff_expenses LIMIT 0");
        $_pdo3->prepare("INSERT INTO staff_expenses (
            source, legacy_json_id, staff_id, staff_name, project,
            category, amount, ssp_amount, currency, description, expense_date,
            receipt_path, submitted_via, status,
            is_staff_payment, auto_approved, approved_by, approved_at,
            staff_payment_to_name, staff_payment_type,
            submitted_at, created_at, updated_at
        ) VALUES (
            'field', ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, 'web', ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?, ?
        )")->execute([
            $_lastJsonId, $rid, $retailer['name'], $proj ?? 'dishnet',
            $category, $currency === 'USD' ? $rawAmount : 0,
            $currency === 'SSP' ? $rawAmount : 0, $currency, $desc, date('Y-m-d'),
            $photoPath, $autoApprove ? 'approved' : 'pending',
            $isStaffPay ? 1 : 0, $autoApprove ? 1 : 0,
            $autoApprove ? 'System (SSP auto)' : '', $autoApprove ? date('Y-m-d H:i:s') : null,
            $staffName ?: '', $staffPayType ?: '',
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $_dw) { /* migration not yet applied — JSON is primary */ }

    // v4.9.18: SSP Advance chain — register SSP expense for tracking
    if ($currency === 'SSP' && $rawAmount > 0 && !$isStaffPay) {
        try {
            require_once dirname(__DIR__, 2) . '/lib/SspAdvanceService.php';
            $_sspSvc = new SspAdvanceService($store, $dataDir);
            $cashbook = $proj ?? 'dishnet';
            $_sspRate = (float)($cb->getExchangeRate() ?: 5700);

            if ($category === 'SSP Return') {
                // SSP Return: staff giving back remaining SSP → auto-posts Cash IN to Rupesh
                $_sspSvc->recordReturn(
                    $rid, $retailer['name'], (int)$rawAmount, $_sspRate,
                    $cashbook, $retailer['name']
                );
            } else {
                // Regular SSP expense: register for tracking + optional auto-merge
                $_allExp = $store->load('cash_expenses.json') ?: [];
                $_lastExpId = 0;
                foreach ($_allExp as $_ex) {
                    if ((int)($_ex['collector_id'] ?? 0) === $rid) {
                        $_lastExpId = max($_lastExpId, (int)($_ex['id'] ?? 0));
                    }
                }
                $_regResult = $_sspSvc->registerExpense(
                    $rid, $retailer['name'], (int)$rawAmount,
                    $category, $desc, $cashbook, $_lastExpId
                );
                // If auto-approved, also merge to Rupesh's cb_ledger immediately
                if ($autoApprove && ($_regResult['ok'] ?? false) && empty($_regResult['skipped'])) {
                    $_sspSvc->mergeExpenseToLedger(
                        (int)$_regResult['register_id'],
                        'System (SSP auto)',
                        $_sspRate
                    );
                }
            }
        } catch (\Throwable $e) {
            logActivity($dataDir, 'ssp_register_error',
                "Failed to register SSP expense: " . $e->getMessage(), '');
        }
    }
    $label = $currency === 'SSP'
        ? number_format($rawAmount,0).' SSP'
        : '$'.number_format($rawAmount,2);
    logActivity($dataDir, 'expense_logged', $autoApprove ? 'Expense auto-approved' : 'Field expense submitted',
        $label.' — '.$category.' by '.$retailer['name']);

    // ── AUTO-LINK: When Diko gives cash to Bidal (auto-approved staff payment) ──
    // Immediately create cash_in for receiver + send WhatsApp notification.
    // No need for Rupesh to approve — field_accountant is trusted.
    if ($autoApprove && $isStaffPay && !empty($staffName)) {
        $allRetailers = $store->load('retailers.json') ?? [];
        $matchedId    = 0;
        $matchedName  = '';
        $matchedPhone = '';
        $staffLower   = strtolower(trim($staffName));
        foreach ($allRetailers as $r) {
            if (empty($r['is_active'])) continue;
            $rName = strtolower($r['name'] ?? '');
            if ($rName === $staffLower) { $matchedId = (int)$r['id']; $matchedName = $r['name']; $matchedPhone = $r['phone'] ?? ''; break; }
            if ($rName && (strpos($rName, $staffLower) !== false || strpos($staffLower, $rName) !== false)) {
                $matchedId = (int)$r['id']; $matchedName = $r['name']; $matchedPhone = $r['phone'] ?? '';
            }
        }

        if ($matchedId > 0) {
            // Create cash_in for receiver
            $cashIns = $store->load('cash_ins.json') ?? [];
            $ciCategory = ($currency === 'SSP') ? 'SSP Received' : 'USD Received';
            $ciDesc     = 'From ' . $retailer['name'] . ' — ' . $category;
            if ($desc) $ciDesc .= ' (' . substr($desc, 0, 60) . ')';
            $ciRef = 'EXP-STAFF-' . $rid . '-' . time();

            // Dedup check
            $dupFound = false;
            foreach ($cashIns as $ci) {
                if (($ci['cb_ref'] ?? '') === $ciRef) { $dupFound = true; break; }
            }
            if (!$dupFound) {
                $cashIns[] = [
                    'id'            => count($cashIns) + 1,
                    'collector_id'  => $matchedId,
                    'collector_name'=> $matchedName,
                    'amount'        => $currency === 'USD' ? $rawAmount : 0,
                    'currency'      => $currency,
                    'ssp_amount'    => $currency === 'SSP' ? $rawAmount : 0,
                    'usd_given'     => 0,
                    'rate'          => 0,
                    'category'      => $ciCategory,
                    'description'   => $ciDesc,
                    'status'        => 'approved',
                    'approved_by'   => 'auto (field accountant)',
                    'approved_at'   => date('Y-m-d H:i:s'),
                    'cb_ref'        => $ciRef,
                    'created_at'    => date('Y-m-d H:i:s'),
                ];
                $store->save('cash_ins.json', $cashIns);
                // Write to staff_ledger so field balance updates immediately
                try {
                    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                    StaffLedgerWriter::onCashIn($store->getPdo(), $cashIns[count($cashIns) - 1]);
                } catch (\Throwable $_slErr) {
                    logActivity($dataDir, 'staff_ledger_error', 'onCashIn failed: ' . $_slErr->getMessage(), $ciRef ?? '');
                }
                logActivity($dataDir, 'staff_cash_transfer',
                    "Auto Cash IN for {$matchedName}: {$label} from {$retailer['name']}",
                    "Category: {$category}, ref: {$ciRef}");
            }

            // WhatsApp notification to receiver
            if ($matchedPhone) {
                try {
                    $notify = svc('notify');
                    $waMsg = "💰 *Cash Received*\n\n"
                           . "From: *{$retailer['name']}*\n"
                           . "Amount: *{$label}*\n"
                           . "For: {$category}" . ($desc ? " — {$desc}" : '') . "\n"
                           . "Time: " . date('d M Y H:i') . "\n\n"
                           . "This is now in your cash balance.\n"
                           . "— DishNet";
                    $notify->sendVia('accounts', $matchedPhone, $waMsg, 'staff_cash_transfer');
                } catch (\Throwable $e) {
                    logActivity($dataDir, 'wa_staff_transfer_failed',
                        "WhatsApp to {$matchedName} failed: " . $e->getMessage(), '');
                }
            }
        }
    }

    if ($autoApprove) {
        flash("\u2705 {$label} expense for {$category} — auto-approved.", 'success');
    } else {
        flash("\u2705 {$label} expense submitted for {$category} — Rupesh will approve shortly.", 'success');
    }
    redirect('?page=dashboard&tab=wallet');
}

// ── Cancel Own Expense (staff) ────────────────────────────────────────
// Pending: always cancellable. Auto-approved: cancellable same day only.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cancel_expense') {
    $retailer = $auth->requireLogin();
    $rid      = (int)$retailer['id'];
    $expId    = (int)($_POST['expense_id'] ?? 0);
    if (!$expId) {
        flash('Missing expense ID.', 'danger');
        redirect('?page=dashboard&tab=wallet');
    }
    $expenses = $store->load('cash_expenses.json') ?: [];
    $found = false;
    foreach ($expenses as &$exp) {
        if ((int)($exp['id'] ?? 0) !== $expId) continue;
        if ((int)($exp['collector_id'] ?? 0) !== $rid) {
            flash('You can only cancel your own expenses.', 'danger');
            redirect('?page=dashboard&tab=wallet');
        }
        $expStatus = $exp['status'] ?? '';
        $isAutoApproved = !empty($exp['auto_approved']);
        $submittedDay   = substr($exp['submitted_at'] ?? $exp['created_at'] ?? '', 0, 10);
        $isToday        = ($submittedDay === date('Y-m-d'));

        if ($expStatus === 'pending') {
            // Always allow
        } elseif ($expStatus === 'approved' && $isAutoApproved && $isToday) {
            // Auto-approved same day — allow cancel
        } else {
            $reason = ($expStatus === 'approved' && $isAutoApproved && !$isToday)
                ? 'Auto-approved entries can only be cancelled on the same day. Ask Rupesh to void it from Staff Cashbooks.'
                : 'Only pending or same-day auto-approved expenses can be cancelled. This one is '.$expStatus.'.';
            flash($reason, 'danger');
            redirect('?page=dashboard&tab=wallet');
        }
        $exp['status']       = 'cancelled';
        $exp['cancelled_at'] = date('Y-m-d H:i:s');
        $exp['cancelled_by'] = $retailer['name'];
        $found = true;
        break;
    }
    unset($exp);
    if (!$found) {
        flash('Expense not found.', 'danger');
        redirect('?page=dashboard&tab=wallet');
    }
    $store->save('cash_expenses.json', $expenses);
    $cur   = $expenses[array_search($expId, array_column($expenses, 'id'))]['currency'] ?? 'USD';
    $amt   = $cur === 'SSP'
        ? number_format((float)($expenses[array_search($expId, array_column($expenses, 'id'))]['ssp_amount'] ?? 0), 0).' SSP'
        : '$'.number_format((float)($expenses[array_search($expId, array_column($expenses, 'id'))]['amount'] ?? 0), 2);
    logActivity($dataDir, 'expense_cancelled', 'Expense cancelled by staff', $amt.' — '.$retailer['name']);
    flash('Expense cancelled ('.$amt.').', 'success');
    redirect('?page=dashboard&tab=wallet');
}

// ── Approve / Reject Expense (Rupesh) ────────────────────────────────────
// ── Cashbook: First-Run Setup (admin only) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cashbook_first_run_setup') {
    $retailer = $auth->requireAdmin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $msgs = [];

    $usdOpening = round((float)($_POST['usd_opening'] ?? 0), 2);
    $sspOpening = round((float)($_POST['ssp_opening'] ?? 0), 2);
    $rate       = round((float)($_POST['rate'] ?? 0), 2);

    if ($usdOpening >= 0) {
        $r = $cb->setOpeningBalance('USD', $usdOpening, $retailer);
        $msgs[] = $r['message'];
    }
    if ($sspOpening >= 0 && $sspOpening > 0) {
        $r = $cb->setOpeningBalance('SSP', $sspOpening, $retailer);
        $msgs[] = $r['message'];
    }
    if ($rate > 0) {
        $r = $cb->setExchangeRate($rate, $retailer);
        $msgs[] = $r['message'];
    }

    flash('✅ Cashbook opened: ' . implode(' · ', $msgs), 'success');
    redirect('?page=dashboard&tab=cashbook');
}

// ── Cashbook: Add Entry (web form) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cashbook_add_entry') {
    $retailer = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $isAdmin3 = !empty($retailer['is_admin']);
    $isAcct3  = $isAdmin3 || in_array($retailer['role'] ?? '', ['accountant'], true);
    $category = trim($_POST['category'] ?? 'adjustment');
    $autoApprove = in_array($category, ['collection','topup','opening'], true) || $isAcct3;
    $data = $_POST;
    if (!empty($_FILES['photo']['tmp_name'])) {
        $data['photo_tmp']  = $_FILES['photo']['tmp_name'];
        $data['photo_name'] = $_FILES['photo']['name'];
    }
    $result = $cb->addEntry($data, $retailer, $autoApprove);

    // ── AUTO-LINK: Cash OUT to staff → auto-create Cash IN on their Field Register ──
    // When Rupesh pays SSP (or USD) to a staff member, auto-create a matching entry
    // in cash_ins.json so the staff member sees it without manual entry.
    $cbDir      = $data['direction'] ?? 'in';
    $cbPerson   = trim($data['person'] ?? '');

    // v4.11.3: If person field is blank for SSP Advance / Staff Advance, try to
    // extract staff name from description. Prevents CB-2669-style ghost advances
    // where Rupesh typed the name in the description but left Person empty.
    if ($cbPerson === '' && in_array($category, ['SSP Advance', 'Staff Advance'], true)) {
        $_descScan     = strtolower(trim($data['description'] ?? '') . ' ' . trim($data['particulars'] ?? ''));
        $_allRetailers = $store->load('retailers.json') ?? [];
        foreach ($_allRetailers as $_r) {
            if (empty($_r['is_active'])) continue;
            $_rLower = strtolower($_r['name'] ?? '');
            if ($_rLower === '') continue;
            // Match any word-boundary token of the retailer name found in description
            $_parts = array_filter(explode(' ', $_rLower));
            foreach ($_parts as $_part) {
                if (strlen($_part) >= 3 && str_contains($_descScan, $_part)) {
                    $cbPerson = $_r['name'];
                    logActivity($dataDir, 'person_desc_fallback',
                        "Auto-matched person '{$cbPerson}' from description for {$category}", '');
                    break 2;
                }
            }
        }
    }
    $cbCurrency = strtoupper($data['currency'] ?? 'USD');
    $cbAmount   = (float)($data['amount'] ?? 0);
    $cbSspAmt   = (float)($data['ssp_amount'] ?? 0);
    // Staff categories that should auto-link to field register
    $staffCats  = ['Salary','Transport Allowance','Food Allowance','Staff Advance',
                   'Bonus','Employee Benefit','Commission',
                   'SSP Advance'];  // v4.9.18: SSP advance chain

    // v4.11.0: Personal pay categories — these are the employee's own money,
    // NOT accountable DishNet cash. They must NOT inflate the field register
    // balance. Salary is handled by HRM payroll; others are personal benefits.
    $personalCats = ['Salary','Transport Allowance','Food Allowance',
                     'Bonus','Employee Benefit'];

    if (($result['ok'] ?? $result['success'] ?? false)
        && $cbDir === 'out'
        && $cbPerson !== ''
        && in_array($category, $staffCats, true)
        && !in_array($category, $personalCats, true) // v4.11.0: skip personal pay
    ) {
        // Match person name to a retailer ID (case-insensitive contains)
        $allRetailers = $store->load('retailers.json') ?? [];
        $matchedId    = 0;
        $matchedName  = '';
        $matchedPhone = '';
        $personLower  = strtolower($cbPerson);
        foreach ($allRetailers as $r) {
            if (empty($r['is_active'])) continue;
            $rName = strtolower($r['name'] ?? '');
            // Exact match first
            if ($rName === $personLower) { $matchedId = (int)$r['id']; $matchedName = $r['name']; $matchedPhone = $r['phone'] ?? ''; break; }
            // Contains match (e.g. "Diko" matches "Ms Diko Jeseka")
            if ($rName && (strpos($rName, $personLower) !== false || strpos($personLower, $rName) !== false)) {
                $matchedId = (int)$r['id']; $matchedName = $r['name']; $matchedPhone = $r['phone'] ?? '';
            }
        }

        if ($matchedId > 0) {
            $cbSr     = $result['sr'] ?? '';
            $cashIns  = $store->load('cash_ins.json') ?? [];
            // Dedup check: don't create if this SR already linked
            $dupFound = false;
            foreach ($cashIns as $ci) {
                if (($ci['cb_ref'] ?? '') === $cbSr && $cbSr !== '') { $dupFound = true; break; }
            }
            if (!$dupFound) {
                $ciCategory = ($cbCurrency === 'SSP') ? 'SSP Received' : 'USD Received';
                $ciDesc     = 'From ' . ($retailer['name'] ?? 'Office') . ' — ' . $category;
                if (!empty($data['description'])) $ciDesc .= ' (' . trim($data['description']) . ')';
                $cashIns[] = [
                    'id'            => count($cashIns) + 1,
                    'collector_id'  => $matchedId,
                    'collector_name'=> $matchedName,
                    'amount'        => $cbCurrency === 'USD' ? $cbAmount : 0,
                    'currency'      => $cbCurrency,
                    'ssp_amount'    => $cbCurrency === 'SSP' ? $cbSspAmt : 0,
                    'usd_given'     => 0,
                    'rate'          => 0,
                    'category'      => $ciCategory,
                    'description'   => $ciDesc,
                    'status'        => 'approved',
                    'approved_by'   => 'auto (cashbook link)',
                    'approved_at'   => date('Y-m-d H:i:s'),
                    'cb_ref'        => $cbSr,
                    'created_at'    => date('Y-m-d H:i:s'),
                ];
                $store->save('cash_ins.json', $cashIns);
                // Write to staff_ledger so field balance updates immediately
                try {
                    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                    StaffLedgerWriter::onCashIn($store->getPdo(), $cashIns[count($cashIns) - 1]);
                } catch (\Throwable $_slErr) {
                    logActivity($dataDir, 'staff_ledger_error', 'onCashIn failed: ' . $_slErr->getMessage(), $cbSr);
                }
                logActivity($dataDir, 'cashbook_auto_link',
                    "Auto-created Cash IN for {$matchedName}: {$cbCurrency} " .
                    ($cbCurrency === 'SSP' ? number_format($cbSspAmt, 0) : '$'.number_format($cbAmount, 2)) .
                    " (cb_ref: {$cbSr})", '');

                // WhatsApp notification to receiving staff
                if ($matchedPhone) {
                    try {
                        $notify->staffCashReceived($matchedPhone, $matchedName,
                            $cbCurrency, $cbAmount, $cbSspAmt,
                            $category, $retailer['name'] ?? 'Office', $cbSr);
                    } catch (\Throwable $e) {}
                }
            }

            // v4.9.18: SSP Advance chain — register advance issue on staff side
            // v4.9.19: Also trigger for "Staff Advance" when currency is SSP —
            // Rupesh naturally picks "Staff Advance" for all staff advances,
            // the SSP chain should fire based on currency, not category name.
            $isSspAdvance = $cbCurrency === 'SSP' && $cbSspAmt > 0
                && in_array($category, ['SSP Advance', 'Staff Advance'], true);
            if ($isSspAdvance) {
                try {
                    require_once dirname(__DIR__, 2) . '/lib/SspAdvanceService.php';
                    $sspSvc = new SspAdvanceService($store, $dataDir);
                    $sspSvc->registerAdvanceIssue(
                        $matchedId, $matchedName, (int)$cbSspAmt,
                        (float)($data['ssp_rate'] ?? 0), $proj,
                        $cbSr, trim($data['description'] ?? ''),
                        $retailer['name'] ?? 'Rupesh'
                    );
                } catch (\Throwable $e) {
                    logActivity($dataDir, 'ssp_advance_error',
                        "Failed to register SSP advance: " . $e->getMessage(), $cbSr);
                }
            }
        }
    }

    // ── AUTO-CRM REFUND: Customer Refund / Commission → post to CRM /refunds ──
    // When Rupesh records a customer refund or commission with a CRM client ID,
    // auto-post to CRM so the credit appears on the customer's account.
    $crmRefundCats = ['Customer Refund', 'Customer Commission'];
    $crmRefundMsg  = '';
    if (($result['ok'] ?? $result['success'] ?? false)
        && $cbDir === 'out'
        && in_array($category, $crmRefundCats, true)
    ) {
        // Extract CRM client ID from person field, description, or validation_ref
        // Patterns: "000123", "#123", "CRM 123", or just digits
        $crmClientId = 0;
        $searchFields = [$cbPerson, $data['description'] ?? '', $data['validation_ref'] ?? ''];
        foreach ($searchFields as $sf) {
            // Match 3-6 digit number (common CRM IDs) or "FTTH000xxx" format
            if (preg_match('/\b(?:FTTH)?0*(\d{1,6})\b/i', $sf, $m)) {
                $crmClientId = (int)$m[1];
                if ($crmClientId > 0) break;
            }
            // Match "#123" or "CRM 123" or "client 123"
            if (preg_match('/(?:#|CRM|client)\s*(\d+)/i', $sf, $m)) {
                $crmClientId = (int)$m[1];
                if ($crmClientId > 0) break;
            }
        }

        if ($crmClientId > 0) {
            try {
                // $crm is the CrmApiClient from public.php (via svc('crm'))
                if (isset($crm) && $crm->isConfigured()) {
                    require_once dirname(__DIR__, 2) . '/lib/PaymentUuids.php';
                    $refundPayload = [
                        'clientId'  => $crmClientId,
                        'methodId'  => PaymentUuids::CASH,
                        'amount'    => $cbAmount > 0 ? $cbAmount : ($cbSspAmt > 0 ? round($cbSspAmt / ($sysRate ?? 5700), 2) : 0),
                        'note'      => "DishNet {$category} — " . ($data['description'] ?? $cbPerson) .
                                       " (CB ref: " . ($result['sr'] ?? '') . ")",
                    ];
                    $refundResult = $crm->post('refunds', $refundPayload);
                    if (!empty($refundResult['id'])) {
                        // Update cb_ledger with CRM refund ID
                        $cb->query("UPDATE cb_ledger SET crm_payment_id=?, validation_status='done', updated_at=? WHERE sr=?",
                            [(int)$refundResult['id'], date('Y-m-d H:i:s'), $result['sr'] ?? '']);
                        $crmRefundMsg = ' · CRM refund #' . $refundResult['id'] . ' posted';
                        logActivity($dataDir, 'crm_refund_posted',
                            "CRM refund #{$refundResult['id']} for client {$crmClientId}: \${$refundPayload['amount']}", '');

                        // ── WhatsApp customer notification ────────────────────────────
                        try {
                            $refundClient = $crm->get("clients/{$crmClientId}") ?? [];
                            $refundName   = trim(($refundClient['firstName'] ?? '') . ' ' . ($refundClient['lastName'] ?? ''))
                                         ?: ($refundClient['companyName'] ?? 'Valued Customer');
                            $refundPhone  = '';
                            foreach (($refundClient['contacts'] ?? []) as $_rc) {
                                if (!empty($_rc['phone'])) { $refundPhone = $_rc['phone']; break; }
                            }
                            if ($refundPhone) {
                                $_refAmt    = number_format($refundPayload['amount'], 2);
                                $_refRef    = $result['sr'] ?? ('REF-' . $refundResult['id']);
                                $_refCat    = $category === 'Customer Commission' ? 'Commission' : 'Refund';
                                $_refNote   = trim($data['description'] ?? $cbPerson ?? '');
                                $refundWaMsg = "💰 *Cash {$_refCat} — DishNet Africa*\n\n"
                                    . "Dear *{$refundName}*,\n\n"
                                    . "We have processed a {$_refCat} of *\${$_refAmt}* to you.\n"
                                    . "Reference: *{$_refRef}*\n"
                                    . ($_refNote ? "Note: {$_refNote}\n" : '')
                                    . "Date: " . date('d M Y') . "\n\n"
                                    . "If you have any questions, please contact our office.\n"
                                    . "— _DishNet Africa_";
                                if (!isset($notify)) $notify = svc('notify');
                                $notify->sendVia('accounts', $refundPhone, $refundWaMsg,
                                    'customer_refund', ['customer' => $refundName, 'amount' => $_refAmt]);
                                $crmRefundMsg .= ' · WA sent to customer';
                                logActivity($dataDir, 'crm_refund_wa_sent',
                                    "Refund WA sent to {$refundName} ({$refundPhone}): \${$_refAmt}", '');
                            }
                        } catch (\Throwable $_waRefErr) {
                            // Non-fatal — refund already posted to CRM
                            logActivity($dataDir, 'crm_refund_wa_failed', $_waRefErr->getMessage(), '');
                        }
                    } else {
                        $crmRefundMsg = ' · ⚠ CRM refund failed (check API log)';
                        logActivity($dataDir, 'crm_refund_failed',
                            "Failed refund for client {$crmClientId}: " . json_encode($refundResult ?? 'null'), '');
                    }
                } else {
                    $crmRefundMsg = ' · CRM not configured';
                }
            } catch (\Throwable $crmEx) {
                $crmRefundMsg = ' · ⚠ CRM error: ' . $crmEx->getMessage();
                logActivity($dataDir, 'crm_refund_error', $crmEx->getMessage(), '');
            }
        }
    }

    if ($result['ok'] ?? $result['success'] ?? false) {
        flash(($result['message'] ?? '✅ Entry added.') . $crmRefundMsg, 'success');
        // v4.11.3: Post-save assertion — verify chain fired correctly
        $_savedRecord = ['sr' => $result['sr'] ?? '', 'category' => $_tigData['category'] ?? '', 'person' => $_tigData['person'] ?? '', 'direction' => $_tigData['direction'] ?? 'out'];
        TransactionIntegrityGuard::postSave($_tigCtx, $_savedRecord, $store, $store->getPdo(), $dataDir);
    } else flash($result['message'] ?? '❌ Error adding entry.', 'danger');
    redirect('?page=dashboard&tab=cashbook');
}

// ── Cashbook: Approve Entry (web form) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cashbook_approve_entry') {
    $retailer = $auth->requireLogin();
    if (empty($retailer['is_admin']) && ($retailer['role']??'') !== 'accountant') {
        flash('Accountant or admin access required.', 'danger');
        redirect('?page=dashboard&tab=cashbook&cb_view=pending');
    }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $result = $cb->approveEntry((int)($_POST['entry_id'] ?? 0), $retailer);
    if ($result['success']) flash($result['message'], 'success');
    else flash($result['message'], 'danger');
    redirect('?page=dashboard&tab=cashbook&cb_view=pending');
}

// ── Cashbook: Reject Entry (web form) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cashbook_reject_entry') {
    $retailer = $auth->requireLogin();
    if (empty($retailer['is_admin']) && ($retailer['role']??'') !== 'accountant') {
        flash('Accountant or admin access required.', 'danger');
        redirect('?page=dashboard&tab=cashbook&cb_view=pending');
    }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $result = $cb->rejectEntry((int)($_POST['entry_id'] ?? 0), trim($_POST['reject_reason'] ?? ''), $retailer);
    if ($result['success']) flash($result['message'], 'warning');
    else flash($result['message'], 'danger');
    redirect('?page=dashboard&tab=cashbook&cb_view=pending');
}

// ── Cashbook: Set Opening Balance (admin) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cashbook_set_opening') {
    $retailer = $auth->requireAdmin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $result = $cb->setOpeningBalance(strtoupper(trim($_POST['currency'] ?? '')), (float)($_POST['amount'] ?? 0), $retailer);
    if ($result['success']) flash($result['message'], 'success');
    else flash($result['message'], 'danger');
    redirect('?page=dashboard&tab=cashbook');
}

// ── Cashbook: Set Exchange Rate (admin/accountant) ────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='cashbook_set_rate') {
    $retailer = $auth->requireLogin();
    if (empty($retailer['is_admin']) && ($retailer['role']??'') !== 'accountant') {
        flash('Accountant or admin access required.', 'danger');
        redirect('?page=dashboard&tab=cashbook');
    }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $cb->setExchangeRate((float)($_POST['rate'] ?? 0), is_array($retailer) ? ($retailer['name'] ?? 'admin') : 'admin');
    flash('Exchange rate updated.', 'success');
    redirect('?page=dashboard&tab=cashbook');
}

// ── Cashbook v2: Add Entry (from tab form) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='add_entry') {
    $retailer = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    require_once dirname(__DIR__, 2) . '/lib/TransactionIntegrityGuard.php';
    $cb = new CashbookService($store, $dataDir);

    // ── v4.11.3: Pre-save integrity check ─────────────────────────────────────
    $exchType = trim($_POST['exchange_type'] ?? '');
    $_tigCtx  = $exchType ? 'cashbook_exchange' : 'cashbook_out';
    $_tigData = [
        'direction'  => trim($_POST['direction'] ?? 'out'),
        'category'   => trim($_POST['category'] ?? ''),
        'person'     => trim($_POST['person'] ?? ''),
        'currency'   => strtoupper(trim($_POST['currency'] ?? 'USD')),
        'amount'     => (float)($_POST['amount'] ?? 0),
        'ssp_amount' => (float)($_POST['exch_ssp_amount'] ?? $_POST['ssp_amount'] ?? 0),
        'ssp_rate'   => (float)($_POST['ssp_rate'] ?? 0),
        'date'       => trim($_POST['date'] ?? date('Y-m-d')),
    ];
    // Skip TIG for personal pay categories — salary/allowance are HRM payroll,
    // not field cash. TIG rules don't apply and would give false positives.
    $_tigPersonalCats = ['Salary','Transport Allowance','Food Allowance','Bonus','Employee Benefit'];
    $_tigSkip = in_array($_tigData['category'], $_tigPersonalCats, true);
    if (!$_tigSkip) {
        try {
            $_tigIssues = TransactionIntegrityGuard::preSave($_tigCtx, $_tigData, $store, $store->getPdo());
            foreach ($_tigIssues as $_issue) {
                if (($_issue['level'] ?? '') === 'block') {
                    flash('🚫 ' . $_issue['msg']
                        . ' <br><small><b>Affects:</b> ' . htmlspecialchars($_issue['affects'])
                        . '</small><br><small><b>Fix:</b> ' . htmlspecialchars($_issue['fix']) . '</small>',
                        'danger');
                    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?page=dashboard'));
                    exit;
                }
                if (($_issue['level'] ?? '') === 'warn') {
                    flash('⚠️ ' . $_issue['msg']
                        . ' <br><small><b>Affects:</b> ' . htmlspecialchars($_issue['affects']) . '</small>',
                        'warning');
                }
            }
        } catch (\Throwable $_tigErr) {
            // TIG is advisory only — never block a save due to TIG error
            logActivity($dataDir, 'tig_error', 'TIG preSave error: ' . $_tigErr->getMessage(), '');
        }
    }
    // ── End pre-save check ────────────────────────────────────────────────────

    // v4.9.10: EXCHANGE DUAL-ENTRY — creates 2 rows (1 USD + 1 SSP)
    $exchType = trim($_POST['exchange_type'] ?? '');
    if ($exchType && in_array($exchType, ['usd_to_ssp','ssp_to_usd'])) {
        $usdAmt   = round((float)($_POST['amount'] ?? 0), 2);
        $sspAmt   = round((float)($_POST['exch_ssp_amount'] ?? 0), 0);
        $rate     = round((float)($_POST['ssp_rate'] ?? 0), 0);
        $desc     = trim($_POST['description'] ?? '');
        $person   = trim($_POST['person'] ?? '');
        $project  = $_POST['project'] ?? 'dishnet';
        $date     = $_POST['date'] ?? date('Y-m-d');
        $exchRef  = 'EXCH-' . time();

        if ($usdAmt <= 0 || $rate <= 0) {
            flash('Exchange requires amount and rate.', 'danger');
            redirect('?page=dashboard&tab=cashbook');
        }
        if ($sspAmt <= 0) $sspAmt = round($usdAmt * $rate, 0);

        // Entry 1: USD side
        $usdDir = ($exchType === 'usd_to_ssp') ? 'out' : 'in';
        $usdEntry = [
            'project'           => $project,
            'date'              => $date,
            'direction'         => $usdDir,
            'amount'            => $usdAmt,
            'currency'          => 'USD',
            'ssp_amount'        => null,
            'ssp_rate'          => $rate,
            'category'          => 'Exchange',
            'category_raw'      => 'Exchange',
            'person'            => $person,
            'description'       => $desc,
            'validation_ref'    => $exchRef,
            'validation_status' => 'done',
        ];
        $r1 = $cb->addEntry($usdEntry, $retailer, true);

        // Entry 2: SSP side (opposite direction)
        $sspDir = ($exchType === 'usd_to_ssp') ? 'in' : 'out';
        $sspDesc = ($exchType === 'usd_to_ssp')
            ? 'SSP received from exchange: $' . number_format($usdAmt, 2) . ' @ ' . number_format($rate, 0)
            : 'SSP given for exchange: $' . number_format($usdAmt, 2) . ' @ ' . number_format($rate, 0);
        if ($person) $sspDesc .= ' By ' . $person;
        $sspEntry = [
            'project'           => $project,
            'date'              => $date,
            'direction'         => $sspDir,
            'amount'            => round($usdAmt, 2),
            'currency'          => 'SSP',
            'ssp_amount'        => $sspAmt,
            'ssp_rate'          => $rate,
            'category'          => 'Exchange',
            'category_raw'      => 'Exchange',
            'person'            => $person,
            'description'       => $sspDesc,
            'validation_ref'    => $exchRef,
            'validation_status' => 'done',
        ];
        $r2 = $cb->addEntry($sspEntry, $retailer, true);

        if ($r1['ok'] && $r2['ok']) {
            $sspFmt = number_format($sspAmt, 0);
            flash("Exchange saved: {$r1['sr']} (USD) + {$r2['sr']} (SSP) · \${$usdAmt} ↔ {$sspFmt} SSP", 'success');
        } else {
            flash('Exchange partially failed: ' . ($r1['error'] ?? '') . ' / ' . ($r2['error'] ?? ''), 'danger');
        }
        $redir = $_SERVER['HTTP_REFERER'] ?? '?page=dashboard&tab=cashbook';
        redirect($redir);
    }

    // ── Regular (non-exchange) entry ──────────────────────────────────────
    $currency  = strtoupper(trim($_POST['currency'] ?? 'USD'));
    if (!in_array($currency, ['USD','SSP'], true)) $currency = 'USD';
    $rawAmount = (float)($_POST['amount'] ?? 0);
    $sspRate   = (float)($_POST['ssp_rate'] ?? 0);

    // If SSP and no rate provided, fall back to saved exchange rate
    if ($currency === 'SSP' && $sspRate <= 0) {
        $sspRate = $cb->getExchangeRate(); // saved rate from settings
    }
    // Hard block: SSP entry with no rate at all = would corrupt USD balance
    if ($currency === 'SSP' && $sspRate <= 0) {
        flash('SSP entry requires an exchange rate. Please set the exchange rate in Cashbook settings first.', 'danger');
        redirect('?page=dashboard&tab=cashbook');
    }

    // For SSP entries, store the SSP amount and also calculate USD equivalent
    $usdAmount = ($currency === 'SSP')
        ? round($rawAmount / $sspRate, 2)
        : $rawAmount;
    // Build description — enrich with optional context fields
    $desc = trim($_POST['description'] ?? '');
    $payMonth = trim($_POST['pay_month'] ?? '');
    if ($payMonth && !$desc) {
        $desc = ($_POST['category'] ?? '') . ' — ' . $payMonth;
    } elseif ($payMonth && strpos($desc, $payMonth) === false) {
        $desc .= ' [' . $payMonth . ']';
    }
    // Build validation_ref — enrich with invoice/receipt refs
    $valRef = trim($_POST['validation_ref'] ?? '');
    $invRef  = trim($_POST['inv_ref']  ?? '');
    $rcptRef = trim($_POST['rcpt_ref'] ?? '');
    $refParts = array_filter([$valRef, $invRef ? 'INV:'.$invRef : '', $rcptRef ? 'R:'.$rcptRef : '']);
    $valRef = implode(' / ', $refParts);

    $data = [
        'project'           => $_POST['project']            ?? 'dishnet',
        'date'              => $_POST['date']                ?? date('Y-m-d'),
        'direction'         => $_POST['direction']           ?? 'in',
        'amount'            => $usdAmount,
        'currency'          => $currency,
        'ssp_amount'        => $currency === 'SSP' ? $rawAmount : null,
        'ssp_rate'          => $currency === 'SSP' && $sspRate > 0 ? $sspRate : null,
        'category'          => $_POST['category']            ?? 'Receipt',
        'category_raw'      => $_POST['category_raw']        ?? $_POST['category'] ?? '',
        'person'            => trim($_POST['person']         ?? ''),
        'description'       => $desc,
        'validation_ref'    => $valRef,
        'validation_status' => $_POST['validation_status']   ?? 'na',
    ];
    $result = $cb->addEntry($data, $retailer, true);
    if ($result['ok']) {
        $flashMsg = 'Entry '.$result['sr'].' saved.';

        // ── AUTO-CREATE SSP COUNTERPART for Exchange entries ──────────────
        // When Rupesh manually types "Exchange USD to SSP (350@5700)" as a
        // regular USD entry, also create the matching SSP Cash IN entry so
        // the SSP cashbook stays correct.
        $isCatExchange = (stripos($data['category'] ?? '', 'Exchange') !== false);
        $isCurrUSD     = ($data['currency'] ?? 'USD') === 'USD';
        if ($isCatExchange && $isCurrUSD) {
            // Parse rate from description: "(350@5700)" or "(350*5700)"
            $exchRate = 0;
            $exchUsd  = abs((float)$data['amount']);
            if (preg_match('/(\d+(?:\.\d+)?)\s*[@*x×]\s*(\d+(?:\.\d+)?)/', $data['description'] ?? '', $rm)) {
                $a = (float)$rm[1]; $b = (float)$rm[2];
                // Heuristic: the bigger number is the rate (SSP/USD is always > 1000)
                if ($a > $b) { $exchRate = $a; $exchUsd = $b; }
                else         { $exchRate = $b; $exchUsd = $a; }
            }
            // Fallback: use saved exchange rate if we couldn't parse
            if ($exchRate <= 0) $exchRate = $cb->getExchangeRate();

            if ($exchRate > 0 && $exchUsd > 0) {
                $sspAmt  = round($exchUsd * $exchRate, 0);
                $exchRef = 'EXCH-' . $result['id'];
                // Opposite direction: USD out → SSP in, USD in → SSP out
                $sspDir  = ($data['direction'] === 'out') ? 'in' : 'out';
                $sspDesc = ($sspDir === 'in')
                    ? "SSP received from exchange: \${$exchUsd} @ " . number_format($exchRate, 0)
                    : "SSP given for exchange: \${$exchUsd} @ " . number_format($exchRate, 0);
                $person = trim($data['person'] ?? '');
                if (!$person) {
                    // Try to extract person from description: "By Rupesh", "by Diko"
                    if (preg_match('/\bBy\s+(\w+)/i', $data['description'] ?? '', $pm)) {
                        $person = $pm[1];
                    }
                }
                if ($person) $sspDesc .= ' By ' . $person;

                $sspEntry = [
                    'project'           => $data['project'],
                    'date'              => $data['date'],
                    'direction'         => $sspDir,
                    'amount'            => round($exchUsd, 2),
                    'currency'          => 'SSP',
                    'ssp_amount'        => $sspAmt,
                    'ssp_rate'          => $exchRate,
                    'category'          => 'Exchange',
                    'category_raw'      => 'Exchange',
                    'person'            => $person,
                    'description'       => $sspDesc,
                    'validation_ref'    => $exchRef,
                    'validation_status' => 'done',
                ];
                $r2 = $cb->addEntry($sspEntry, $retailer, true);
                if ($r2['ok']) {
                    $sspFmt = number_format($sspAmt, 0);
                    $flashMsg = "Exchange saved: {$result['sr']} (USD) + {$r2['sr']} (SSP) · \${$exchUsd} ↔ {$sspFmt} SSP";
                    // Also update the original USD entry with the EXCH ref and ssp_rate
                    try {
                        $cb->query("UPDATE cb_ledger SET validation_ref=?, ssp_rate=? WHERE id=?",
                            [$exchRef, $exchRate, $result['id']]);
                    } catch (\Throwable $e) { /* non-fatal */ }
                }
            }
        }

        // ── AUTO-LINK: Cash OUT to staff → auto-create Cash IN on Field Register ──
        // Mirror of the same logic in the API handler (action=cashbook_add_entry).
        // When Rupesh pays SSP (or USD) to a staff member, auto-create a matching
        // entry in cash_ins.json so the staff member sees it without manual entry.
        $cbDir      = $data['direction'] ?? 'in';
        $cbPerson   = trim($data['person'] ?? '');
        $cbCurrency = strtoupper($data['currency'] ?? 'USD');
        $cbAmount   = (float)($data['amount'] ?? 0);
        $cbSspAmt   = (float)($data['ssp_amount'] ?? 0);
        $staffCats  = ['Salary','Transport Allowance','Food Allowance','Staff Advance',
                       'Bonus','Employee Benefit','Commission','SSP Advance'];
        // v4.11.0: Personal pay — skip auto-link (same guard as form path above)
        $personalCats = ['Salary','Transport Allowance','Food Allowance',
                         'Bonus','Employee Benefit'];

        if ($cbDir === 'out' && $cbPerson !== '' && in_array($data['category'] ?? '', $staffCats, true)
            && !in_array($data['category'] ?? '', $personalCats, true)) {
            $allRetailers = $store->load('retailers.json') ?? [];
            $matchedId    = 0;
            $matchedName  = '';
            $matchedPhone = '';
            $personLower  = strtolower($cbPerson);
            foreach ($allRetailers as $r) {
                if (empty($r['is_active'])) continue;
                $rName = strtolower($r['name'] ?? '');
                if ($rName === $personLower) { $matchedId = (int)$r['id']; $matchedName = $r['name']; $matchedPhone = $r['phone'] ?? ''; break; }
                if ($rName && (strpos($rName, $personLower) !== false || strpos($personLower, $rName) !== false)) {
                    $matchedId = (int)$r['id']; $matchedName = $r['name']; $matchedPhone = $r['phone'] ?? '';
                }
            }

            if ($matchedId > 0) {
                $cbSr     = $result['sr'] ?? '';
                $cashIns  = $store->load('cash_ins.json') ?? [];
                $dupFound = false;
                foreach ($cashIns as $ci) {
                    if (($ci['cb_ref'] ?? '') === $cbSr && $cbSr !== '') { $dupFound = true; break; }
                }
                if (!$dupFound) {
                    $ciCategory = ($cbCurrency === 'SSP') ? 'SSP Received' : 'USD Received';
                    $ciDesc     = 'From ' . ($retailer['name'] ?? 'Office') . ' — ' . ($data['category'] ?? '');
                    if (!empty($data['description'])) $ciDesc .= ' (' . trim($data['description']) . ')';
                    $cashIns[] = [
                        'id'            => count($cashIns) + 1,
                        'collector_id'  => $matchedId,
                        'collector_name'=> $matchedName,
                        'amount'        => $cbCurrency === 'USD' ? $cbAmount : 0,
                        'currency'      => $cbCurrency,
                        'ssp_amount'    => $cbCurrency === 'SSP' ? $cbSspAmt : 0,
                        'usd_given'     => 0,
                        'rate'          => 0,
                        'category'      => $ciCategory,
                        'description'   => $ciDesc,
                        'status'        => 'approved',
                        'approved_by'   => 'auto (cashbook link)',
                        'approved_at'   => date('Y-m-d H:i:s'),
                        'cb_ref'        => $cbSr,
                        'created_at'    => date('Y-m-d H:i:s'),
                    ];
                    $store->save('cash_ins.json', $cashIns);
                    // Write to staff_ledger so field balance updates immediately
                    try {
                        require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                        StaffLedgerWriter::onCashIn($store->getPdo(), $cashIns[count($cashIns) - 1]);
                    } catch (\Throwable $_slErr) {
                        logActivity($dataDir, 'staff_ledger_error', 'onCashIn failed: ' . $_slErr->getMessage(), $cbSr);
                    }
                    logActivity($dataDir, 'cashbook_auto_link',
                        "Auto-created Cash IN for {$matchedName}: {$cbCurrency} " .
                        ($cbCurrency === 'SSP' ? number_format($cbSspAmt, 0) : '$'.number_format($cbAmount, 2)) .
                        " (cb_ref: {$cbSr})", '');
                    $flashMsg .= ' · Auto-linked to ' . $matchedName . "'s field register.";

                    // WhatsApp notification to receiving staff
                    if ($matchedPhone) {
                        try {
                            $notify->staffCashReceived($matchedPhone, $matchedName,
                                $cbCurrency, $cbAmount, $cbSspAmt,
                                $data['category'] ?? 'Cash', $retailer['name'] ?? 'Office', $cbSr);
                        } catch (\Throwable $e) {}
                    }
                }

                // SSP Advance chain — register advance on staff side
                $isSspAdvance = $cbCurrency === 'SSP' && $cbSspAmt > 0
                    && in_array($data['category'] ?? '', ['SSP Advance', 'Staff Advance'], true);
                if ($isSspAdvance) {
                    try {
                        require_once dirname(__DIR__, 2) . '/lib/SspAdvanceService.php';
                        $sspSvc = new SspAdvanceService($store, $dataDir);
                        $sspSvc->registerAdvanceIssue(
                            $matchedId, $matchedName, (int)$cbSspAmt,
                            (float)($data['ssp_rate'] ?? 0), $data['project'] ?? 'dishnet',
                            $cbSr, trim($data['description'] ?? ''),
                            $retailer['name'] ?? 'Rupesh'
                        );
                    } catch (\Throwable $e) {
                        logActivity($dataDir, 'ssp_advance_error',
                            "Failed to register SSP advance: " . $e->getMessage(), $cbSr);
                    }
                }
            }
        }

        flash($flashMsg, 'success');
    }
    else flash($result['error'] ?? 'Failed.', 'danger');
    $redir = $_SERVER['HTTP_REFERER'] ?? '?page=dashboard&tab=cashbook';
    redirect($redir);
}

// ── Cashbook: Backfill missing SSP entries for historical Exchange records ───
// ── Dismiss Exchange SSP banner ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='dismiss_exchange_banner') {
    $retailer = $auth->requireLogin();
    if (!csrfCheck()) { flash('Security error.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $meta = $cb->getMeta();
    $meta['exchange_ssp_banner_dismissed'] = true;
    $meta['exchange_ssp_banner_dismissed_at'] = date('Y-m-d H:i:s');
    file_put_contents($dataDir . '/' . CashbookService::META_FILE,
        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flash('Exchange SSP banner dismissed.', 'info');
    redirect('?page=dashboard&tab=cashbook');
}

// One-time fix: creates SSP Cash IN entries for all 300 old Exchange-category
// entries that only had the USD side recorded.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='backfill_exchange_ssp') {
    $retailer = $auth->requireLogin();
    if (!($isAdmin ?? false)) { flash('Admin only.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }
    if (!csrfCheck()) { flash('Security error.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }

    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);

    // Find all Exchange USD entries that have NO SSP counterpart
    $usdEntries = $cb->query(
        "SELECT id, sr, date, direction, amount, person, description, project, validation_ref
         FROM cb_ledger
         WHERE category = 'Exchange' AND currency = 'USD'
         ORDER BY id ASC"
    );

    // Get existing EXCH refs to skip already-paired entries
    $existingRefs = [];
    $sspExch = $cb->query("SELECT validation_ref FROM cb_ledger WHERE category='Exchange' AND currency='SSP' AND validation_ref LIKE 'EXCH-%'");
    foreach ($sspExch as $r) { $existingRefs[$r['validation_ref']] = true; }

    $created = 0;
    $skipped = 0;
    $failed  = 0;
    $totalSSP = 0;

    foreach ($usdEntries as $e) {
        $ref = trim($e['validation_ref'] ?? '');
        // Skip if this entry already has an SSP counterpart (by EXCH ref)
        if ($ref && isset($existingRefs[$ref])) { $skipped++; continue; }

        // Also skip if an SSP counterpart was already created by us (EXCH-{id})
        $autoRef = 'EXCH-' . $e['id'];
        if (isset($existingRefs[$autoRef])) { $skipped++; continue; }

        // Parse rate from description
        $desc = $e['description'] ?? '';
        if (!preg_match('/(\d+(?:\.\d+)?)\s*[@*x×]\s*(\d+(?:\.\d+)?)/', $desc, $m)) {
            $failed++;
            continue;
        }
        $a = (float)$m[1]; $b = (float)$m[2];
        if ($a > $b) { $rate = $a; $usd = $b; }
        else         { $rate = $b; $usd = $a; }

        if ($rate <= 0 || $usd <= 0) { $failed++; continue; }

        $sspAmt = round($usd * $rate, 0);
        $exchRef = 'EXCH-' . $e['id'];

        // Opposite direction
        $sspDir = ($e['direction'] === 'out') ? 'in' : 'out';
        $sspDesc = ($sspDir === 'in')
            ? 'SSP received from exchange: $' . number_format($usd, 2) . ' @ ' . number_format($rate, 0)
            : 'SSP given for exchange: $' . number_format($usd, 2) . ' @ ' . number_format($rate, 0);

        // Extract person from "By Name"
        $person = trim($e['person'] ?? '');
        if (!$person && preg_match('/\bBy\s+(\w+)/i', $desc, $pm)) {
            $person = $pm[1];
        }
        if ($person) $sspDesc .= ' By ' . $person;
        $sspDesc .= ' [backfill]';

        $sspEntry = [
            'project'           => $e['project'],
            'date'              => $e['date'],
            'direction'         => $sspDir,
            'amount'            => round($usd, 2),
            'currency'          => 'SSP',
            'ssp_amount'        => $sspAmt,
            'ssp_rate'          => $rate,
            'category'          => 'Exchange',
            'category_raw'      => 'Exchange',
            'person'            => $person,
            'description'       => $sspDesc,
            'validation_ref'    => $exchRef,
            'validation_status' => 'done',
            'created_at'        => $e['date'] . ' 12:00:00', // same date as original
        ];

        $r2 = $cb->addEntry($sspEntry, $retailer, true);
        if ($r2['ok']) {
            $created++;
            $totalSSP += $sspAmt;
            // Update original USD entry with EXCH ref and rate
            try {
                $cb->query("UPDATE cb_ledger SET validation_ref=?, ssp_rate=? WHERE id=? AND (validation_ref IS NULL OR validation_ref='')",
                    [$exchRef, $rate, $e['id']]);
            } catch (\Throwable $ex) { /* non-fatal */ }
        } else {
            $failed++;
        }
    }

    $sspFmt = number_format($totalSSP, 0);
    flash("Exchange backfill complete: {$created} SSP entries created ({$sspFmt} SSP total), {$skipped} already paired, {$failed} failed.", 'success');
    redirect('?page=dashboard&tab=cashbook&cb_curr=SSP');
}

// ── Cashbook v2: Settle Disbursement ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='settle_disb') {
    $retailer = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb = new CashbookService($store, $dataDir);
    $result = $cb->settleDisb(
        (int)($_POST['entry_id']        ?? 0),
        trim($_POST['voucher_no']       ?? ''),
        (float)($_POST['return_amount'] ?? 0),
        $retailer
    );
    if ($result['ok']) {
        $msg = 'Settled with '.$result['voucher'];
        if ($result['return_posted']) $msg .= ' — change returned posted as Cash IN';
        flash($msg, 'success');
    } else { flash($result['error'] ?? 'Failed.', 'danger'); }
    redirect('?page=dashboard&tab=cashbook&cb_view=pending');
}

// ── Cashbook: Import Excel Data (one-time seed from plugin UI) ───────────
// ── Cashbook: Bulk Delete Entries ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='bulk_delete_entries') {
    $retailer = $auth->requireLogin();
    if (!csrfCheck()) { flash('Security error.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb  = new CashbookService($store, $dataDir);
    $ids = array_filter(array_map('intval', (array)($_POST['entry_ids'] ?? [])));
    if (empty($ids)) { flash('No entries selected.', 'warning'); redirect('?page=dashboard&tab=cashbook'); }
    $deleted = 0;
    foreach ($ids as $id) {
        $r = $cb->deleteEntry($id, $retailer);
        if ($r['ok']) $deleted++;
    }
    flash('🗑 '.$deleted.' entr'.($deleted===1?'y':'ies').' deleted.', 'success');
    $proj = in_array($_POST['cb_proj']??'dishnet',['dishnet','4g']) ? $_POST['cb_proj'] : 'dishnet';
    redirect('?page=dashboard&tab=cashbook&cb_proj='.$proj);
}

// ── Cashbook: Update Entry ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='update_entry') {
    $retailer = $auth->requireLogin();
    if (!csrfCheck()) { flash('Security error.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb  = new CashbookService($store, $dataDir);
    $id  = (int)($_POST['entry_id'] ?? 0);
    if (!$id) { flash('Invalid entry ID.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }

    $allowed = ['date','direction','amount','category','person','description','validation_ref','validation_status'];
    $data = [];
    foreach ($allowed as $f) {
        if (isset($_POST[$f]) && $_POST[$f] !== '') {
            $data[$f] = $f === 'amount' ? round((float)$_POST[$f], 2) : trim($_POST[$f]);
        }
    }
    if (isset($data['direction']) && !in_array($data['direction'], ['in','out'])) unset($data['direction']);
    if (isset($data['amount']) && $data['amount'] <= 0) { flash('Amount must be greater than 0.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }

    $result = $cb->updateEntry($id, $data, $retailer);
    if ($result['ok']) {
        flash('✅ Entry #'.$id.' updated.', 'success');
    } else {
        flash('❌ Update failed: '.($result['error'] ?? 'Unknown error'), 'danger');
    }
    $proj = in_array($_POST['cb_proj']??'dishnet',['dishnet','4g']) ? $_POST['cb_proj'] : 'dishnet';
    redirect('?page=dashboard&tab=cashbook&cb_proj='.$proj);
}

// ── Cashbook: Delete Entry ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='delete_entry') {
    $retailer = $auth->requireLogin();
    if (!csrfCheck()) { flash('Security error.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb  = new CashbookService($store, $dataDir);
    $id  = (int)($_POST['entry_id'] ?? 0);
    if (!$id) { flash('Invalid entry ID.', 'danger'); redirect('?page=dashboard&tab=cashbook'); }

    $result = $cb->deleteEntry($id, $retailer);
    if ($result['ok']) {
        flash('🗑 Entry #'.$id.' deleted.', 'success');
    } else {
        flash('❌ Delete failed: '.($result['error'] ?? 'Unknown error'), 'danger');
    }
    $proj = in_array($_POST['cb_proj']??'dishnet',['dishnet','4g']) ? $_POST['cb_proj'] : 'dishnet';
    redirect('?page=dashboard&tab=cashbook&cb_proj='.$proj);
}

// ── Cashbook: Sync CRM payments → cashbook Cash IN ───────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='crm_sync') {
    $retailer    = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb          = new CashbookService($store, $dataDir);
    $afterDate   = trim($_POST['sync_from'] ?? '');
    $localCols   = $store->load('payment_collections.json') ?: [];

    // Use CRM API directly — falls back to local collections if API unreachable
    $result = $cb->syncFromCrmApi($crm ?? null, $afterDate, $localCols);

    $srcLabel = $result['source'] === 'crm_api'
        ? '🌐 CRM API (all billing payments)'
        : ($result['source'] === 'local_fallback' ? '📦 Local PWA collections (CRM API unreachable)' : '📦 Local collections');

    $redirectTab = ($_POST['redirect_tab'] ?? '') === 'all_collections' ? 'all_collections' : 'cashbook&cb_view=ledger';
    $colsUpdated = ''; // collections sync info in result if available
    if ($result['imported'] > 0) {
        flash('✅ Sync complete — '.$result['imported'].' cash payment'
            .($result['imported']!==1?'s':'').' imported from '.$result['cutoff']
            .'. Collections list updated (new entries shown as "Direct UCRM Entry").'
            .' ('.$result['skipped'].' skipped/already present)', 'success');
    } else {
        flash('ℹ️ Nothing new from '.$result['cutoff'].'. '
            .$result['skipped'].' entries checked. '
            .'Any pending collections matched to UCRM payments are now marked Synced.', 'info');
    }
    redirect('?page=dashboard&tab='.$redirectTab);
}

// ── Cashbook: Bulk settle all pending disbursements (carried forward) ────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='settle_all_pending') {
    $retailer = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb      = new CashbookService($store, $dataDir);
    $proj    = in_array($_POST['project']??'', ['dishnet','4g']) ? $_POST['project'] : 'dishnet';
    $note    = trim($_POST['settle_note'] ?? 'Carried Forward — settled '.date('d M Y'));
    $pending = $cb->getPendingDisbursements($proj);
    $count   = 0;
    foreach ($pending as $e) {
        $cb->settleDisb((int)$e['id'], $note, 0.0, $retailer);
        $count++;
    }
    flash('✅ '.$count.' pending items marked as settled — "'.$note.'". Starting fresh from today.', 'success');
    redirect('?page=dashboard&tab=cashbook&cb_view=pending&cb_proj='.$proj);
}

// ── Cashbook: Reset & Reseed from baked Excel data ────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['cb_action']??'')==='cb_reseed') {
    $admin = $auth->requireLogin();
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cb  = new CashbookService($store, $dataDir);
    $pdo = $store->getPdo();

    // 1. Wipe all Excel-sourced entries (keep manual/collect_payment entries)
    $pdo->exec("DELETE FROM cb_ledger WHERE source IN ('excel_import','excel_upload') OR source IS NULL OR source = ''");
    // Also wipe any 2026-UDAL duplicates from failed previous imports
    $pdo->exec("DELETE FROM cb_ledger WHERE sr LIKE '2026-%'");
    // Wipe original seeded entries (UDAL-* and UD4G-*)
    $pdo->exec("DELETE FROM cb_ledger WHERE (sr LIKE 'UDAL-%' OR sr LIKE 'UD4G-%') AND source = 'excel_import'");

    // 2. Clear seed flags
    $meta = $cb->getMeta();
    unset($meta['seeded_at'], $meta['seeded_count'],
          $meta['seeded_2026_at'], $meta['seeded_2026_count']);
    file_put_contents($dataDir . '/cashbook_meta_v2.json',
        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 3. Run seeder ($cb already in scope)
    $seedFile = dirname(__DIR__, 2) . '/seed_cashbook.php';
    if (!file_exists($seedFile)) {
        flash('seed_cashbook.php not found.', 'danger');
        redirect('?page=dashboard&tab=cashbook');
    }
    @set_time_limit(300);
    require $seedFile;

    // 4. Mark seeded
    $cnt  = $cb->countEntries();
    $meta2 = $cb->getMeta();
    $meta2['seeded_at']    = date('Y-m-d H:i:s');
    $meta2['seeded_count'] = $cnt;
    file_put_contents($dataDir . '/cashbook_meta_v2.json',
        json_encode($meta2, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    flash('✅ Cashbook reset & reseeded — '.$cnt.' entries loaded from Excel data.', 'success');
    redirect('?page=dashboard&tab=cashbook');
}

// ── Close / verify an SSP exchange batch (Phase 3) ───────────────────────
// Rupesh marks a batch as physically verified — removes it from open view.
// Accountant or admin only.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='close_exchange_batch') {
    $retailer = $auth->requireLogin();
    if (($retailer['role']??'')<>'accountant' && empty($retailer['is_admin'])) {
        flash('Accountant access required.','danger');
        redirect('?page=dashboard&tab=ssp_overview');
    }
    $excRef = trim($_POST['exchange_ref'] ?? '');
    $note   = trim($_POST['close_note'] ?? '');
    if (!$excRef) {
        flash('No exchange ref provided.','danger');
        redirect('?page=dashboard&tab=ssp_overview');
    }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $cbSvc = new CashbookService($store, $dataDir);
    $pdo   = $cbSvc->getPdo();
    // Ensure table exists (migration may not have run yet)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssp_batch_states (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        exchange_ref TEXT NOT NULL UNIQUE,
        state TEXT NOT NULL DEFAULT 'open',
        closed_by TEXT NOT NULL DEFAULT '',
        closed_at TEXT,
        note TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO ssp_batch_states (exchange_ref,state,closed_by,closed_at,note,created_at)
         VALUES (?,?,?,?,?,?)
         ON CONFLICT(exchange_ref) DO UPDATE SET
           state=excluded.state, closed_by=excluded.closed_by,
           closed_at=excluded.closed_at, note=excluded.note"
    )->execute([$excRef, 'closed', $retailer['name'], $now, $note, $now]);
    flash('✅ Batch '.$excRef.' marked as verified and closed.','success');
    redirect('?page=dashboard&tab=ssp_overview');
}

// ── Reopen a closed SSP exchange batch ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='reopen_exchange_batch') {
    $retailer = $auth->requireLogin();
    if (empty($retailer['is_admin'])) {
        flash('Admin access required.','danger');
        redirect('?page=dashboard&tab=ssp_overview');
    }
    $excRef = trim($_POST['exchange_ref'] ?? '');
    if (!$excRef) { redirect('?page=dashboard&tab=ssp_overview'); }
    require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
    $pdo = (new CashbookService($store, $dataDir))->getPdo();
    $pdo->prepare("DELETE FROM ssp_batch_states WHERE exchange_ref=?")->execute([$excRef]);
    flash('Batch '.$excRef.' reopened.','success');
    redirect('?page=dashboard&tab=ssp_overview');
}

// ── Give SSP to Staff (Rupesh → Diko/BBC etc.) ───────────────────────────
// Accountant only. Creates cb_ledger SSP OUT (Rupesh) + SSP IN (staff).
// Also writes two staff_ledger rows via StaffLedgerWriter::onSSPTransfer().
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='give_ssp_to_staff') {
    $retailer = $auth->requireLogin();
    if (($retailer['role']??'')<>'accountant' && empty($retailer['is_admin'])) {
        flash('Accountant access required.','danger');
        redirect('?page=dashboard&tab=ssp_cashbook');
    }
    require_once dirname(__DIR__,2).'/lib/CashbookService.php';
    require_once dirname(__DIR__,2).'/lib/StaffLedgerWriter.php';

    $toId    = (int)($_POST['ssp_to_staff_id'] ?? 0);
    $sspAmt  = round((float)($_POST['ssp_amount'] ?? 0), 0);
    $sspRate = round((float)($_POST['ssp_rate'] ?? 0), 0);
    $note    = trim($_POST['ssp_note'] ?? '');

    if (!$toId || $sspAmt <= 0) {
        flash('Staff member and SSP amount are required.','danger');
        redirect('?page=dashboard&tab=ssp_cashbook');
    }

    // Resolve staff name
    $allStaff  = $store->load('retailers.json') ?? [];
    $toName    = '';
    foreach ($allStaff as $s) { if ((int)($s['id']??0)===$toId) { $toName=$s['name']??''; break; } }
    if (!$toName) { flash('Staff member not found.','danger'); redirect('?page=dashboard&tab=ssp_cashbook'); }

    $fromId   = (int)($retailer['id'] ?? 0);
    $fromName = $retailer['name'] ?? 'Rupesh';
    $ref      = 'SSPGIV-' . date('ymdHis') . '-' . $toId;
    $desc     = $note ?: ('SSP given to ' . $toName . ' — ' . number_format($sspAmt, 0) . ' SSP');
    $now      = date('Y-m-d H:i:s');
    $today    = date('Y-m-d');

    $cb  = new CashbookService($store, $dataDir);
    $pdo = $cb->getPdo();

    // cb_ledger: SSP OUT from Rupesh's safe
    $cb->addEntryRaw([
        'project'           => 'dishnet',
        'date'              => $today,
        'direction'         => 'out',
        'amount'            => $sspRate > 0 ? round($sspAmt / $sspRate, 2) : 0,
        'currency'          => 'SSP',
        'ssp_amount'        => $sspAmt,
        'ssp_rate'          => $sspRate,
        'category'          => 'SSP Transfer',
        'category_raw'      => 'SSP Transfer',
        'person'            => $toName,
        'description'       => $desc,
        'validation_ref'    => $ref . '-OUT',
        'validation_status' => 'done',
        'status'            => 'approved',
        'source'            => 'ssp_transfer',
    ]);

    // cb_ledger: SSP IN to staff (Diko's bag)
    $cb->addEntryRaw([
        'project'           => 'dishnet',
        'date'              => $today,
        'direction'         => 'in',
        'amount'            => 0,
        'currency'          => 'SSP',
        'ssp_amount'        => $sspAmt,
        'ssp_rate'          => $sspRate,
        'category'          => 'SSP Transfer',
        'category_raw'      => 'SSP Transfer',
        'person'            => $toName,
        'description'       => $desc . ' [from ' . $fromName . ']',
        'validation_ref'    => $ref . '-IN',
        'validation_status' => 'done',
        'status'            => 'approved',
        'source'            => 'ssp_transfer',
    ]);

    // staff_ledger: both sides
    StaffLedgerWriter::onSSPTransfer($pdo, [
        'transfer_ref' => $ref,
        'from_id'      => $fromId,
        'from_name'    => $fromName,
        'to_id'        => $toId,
        'to_name'      => $toName,
        'ssp_amount'   => $sspAmt,
        'ssp_rate'     => $sspRate,
        'description'  => $desc,
        'event_date'   => $today,
    ]);

    flash('✅ ' . number_format($sspAmt, 0) . ' SSP given to ' . $toName . '. Both cashbooks updated.', 'success');
    redirect('?page=dashboard&tab=ssp_cashbook');
}

// ── Return SSP from Staff (Diko/BBC → Rupesh safe) ───────────────────────
// Accountant only. Creates cb_ledger SSP OUT (staff) + SSP IN (Rupesh safe).
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='return_ssp_from_staff') {
    $retailer = $auth->requireLogin();
    if (($retailer['role']??'')<>'accountant' && empty($retailer['is_admin'])) {
        flash('Accountant access required.','danger');
        redirect('?page=dashboard&tab=ssp_cashbook');
    }
    require_once dirname(__DIR__,2).'/lib/CashbookService.php';
    require_once dirname(__DIR__,2).'/lib/StaffLedgerWriter.php';

    $fromId   = (int)($_POST['ssp_from_staff_id'] ?? 0);
    $sspAmt   = round((float)($_POST['ssp_return_amount'] ?? 0), 0);
    $sspRate  = round((float)($_POST['ssp_return_rate'] ?? 0), 0);
    $note     = trim($_POST['ssp_return_note'] ?? '');

    if (!$fromId || $sspAmt <= 0) {
        flash('Staff member and SSP amount are required.','danger');
        redirect('?page=dashboard&tab=ssp_cashbook');
    }

    $allStaff  = $store->load('retailers.json') ?? [];
    $fromName  = '';
    foreach ($allStaff as $s) { if ((int)($s['id']??0)===$fromId) { $fromName=$s['name']??''; break; } }
    if (!$fromName) { flash('Staff not found.','danger'); redirect('?page=dashboard&tab=ssp_cashbook'); }

    $toId   = (int)($retailer['id'] ?? 0);
    $toName = $retailer['name'] ?? 'Rupesh';
    $ref    = 'SSPRET-' . date('ymdHis') . '-' . $fromId;
    $desc   = $note ?: ('SSP returned by ' . $fromName . ' — ' . number_format($sspAmt, 0) . ' SSP');
    $today  = date('Y-m-d');

    $cb  = new CashbookService($store, $dataDir);
    $pdo = $cb->getPdo();

    // cb_ledger: SSP OUT from staff
    $cb->addEntryRaw([
        'project'           => 'dishnet',
        'date'              => $today,
        'direction'         => 'out',
        'amount'            => 0,
        'currency'          => 'SSP',
        'ssp_amount'        => $sspAmt,
        'ssp_rate'          => $sspRate,
        'category'          => 'SSP Return',
        'category_raw'      => 'SSP Return',
        'person'            => $fromName,
        'description'       => $desc,
        'validation_ref'    => $ref . '-OUT',
        'validation_status' => 'done',
        'status'            => 'approved',
        'source'            => 'ssp_return',
    ]);

    // cb_ledger: SSP IN to Rupesh safe
    $cb->addEntryRaw([
        'project'           => 'dishnet',
        'date'              => $today,
        'direction'         => 'in',
        'amount'            => $sspRate > 0 ? round($sspAmt / $sspRate, 2) : 0,
        'currency'          => 'SSP',
        'ssp_amount'        => $sspAmt,
        'ssp_rate'          => $sspRate,
        'category'          => 'SSP Return',
        'category_raw'      => 'SSP Return',
        'person'            => $fromName,
        'description'       => $desc . ' [to safe]',
        'validation_ref'    => $ref . '-IN',
        'validation_status' => 'done',
        'status'            => 'approved',
        'source'            => 'ssp_return',
    ]);

    // staff_ledger: staff OUT, Rupesh IN
    StaffLedgerWriter::onSSPTransfer($pdo, [
        'transfer_ref' => $ref,
        'from_id'      => $fromId,
        'from_name'    => $fromName,
        'to_id'        => $toId,
        'to_name'      => $toName,
        'ssp_amount'   => $sspAmt,
        'ssp_rate'     => $sspRate,
        'description'  => $desc,
        'event_date'   => $today,
    ]);

    // Try to auto-close any exchange batches now that SSP has returned
    try {
        $cashIns = $store->load('cash_ins.json') ?? [];
        (new CashbookService($store, $dataDir))->autoCloseCompletedBatches($cashIns);
    } catch (\Throwable $e) {}

    flash('✅ ' . number_format($sspAmt, 0) . ' SSP returned from ' . $fromName . ' to safe.', 'success');
    redirect('?page=dashboard&tab=ssp_cashbook');
}
