<?php
// ═══════════════════════════════════════════════════════════════
// CASHBOOK
// ═══════════════════════════════════════════════════════════════



    // ══════════════════════════════════════════════════════════════════════
    // CASHBOOK API — dual-currency USD/SSP ledger
    // ══════════════════════════════════════════════════════════════════════
    if (strpos($act, 'cashbook') !== false || in_array($act, [
        'cashbook_balances','cashbook_entries','cashbook_ledger',
        'cashbook_summary','cashbook_pending','cashbook_add_entry',
        'cashbook_approve','cashbook_reject',
        'cashbook_set_opening','cashbook_set_rate',
        'cb_categories','cb_categories_save',
        'record_exchange',
        'fix_diko_ssp_backfill',
        'fix_exchange_usd_out',
        'fix_exchange_rate_6000',
        'fix_expense_ssp_amounts',
        'fix_exchange_cbLedger_backfill',
        'get_exchange_context',
        'accounting_health_check',
    ], true)) {
        require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
        $cb = new CashbookService($store, $dataDir);
        $isAdmin2 = !empty($me2['is_admin']);
        $isAcct   = $isAdmin2 || in_array($me2['role'] ?? '', ['accountant', 'field_accountant'], true);

        // POST record_exchange — atomic USD↔SSP conversion for field staff
        // Chain: cash_ins.json (IN side) + direct staff_ledger writes (both sides)
        // USD→SSP: SSP arrives in bag (+SSP), USD leaves bag (-USD)
        // SSP→USD: USD arrives in bag (+USD), SSP leaves bag (-SSP)
        if ($act === 'record_exchange' && $met === 'POST') {
            $body2        = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $excDir       = trim($body2['exc_direction'] ?? 'usd_to_ssp');
            $excAmt       = round((float)($body2['exc_amount'] ?? 0), 2);  // always USD amount
            $excRate      = round((float)($body2['exc_rate']   ?? 0), 2);
            $excNote      = trim($body2['exc_note'] ?? '');
            $excDate      = trim($body2['exc_date'] ?? date('Y-m-d'));
            $excStaff     = (int)($body2['exc_staff_id'] ?? $me2['id'] ?? 0);
            if (!$excStaff) $excStaff = (int)($me2['id'] ?? 0);
            $excStaffName = (string)($me2['name'] ?? 'Staff');
            $excRef       = 'EXCH-' . date('ymdHis') . '-' . $excStaff;
            $now          = date('Y-m-d H:i:s');
            $by           = (string)($me2['name'] ?? 'Staff');

            if ($excAmt <= 0 || $excRate <= 0) $er2('Amount and rate are required.', 422);
            if (!in_array($excDir, ['usd_to_ssp', 'ssp_to_usd'], true)) $er2('Invalid direction.', 422);

            require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
            require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
            $ledger = new StaffLedgerService($store->getPdo());

            if ($excDir === 'usd_to_ssp') {
                // Staff gives USD, receives SSP
                $sspReceived = round($excAmt * $excRate, 0);
                $desc = $excNote ?: ('Exchange $' . number_format($excAmt, 2) . ' → ' . number_format($sspReceived, 0) . ' SSP @ ' . number_format($excRate, 0) . ' — ' . $excStaffName);

                // ── Side 1: SSP IN ──────────────────────────────────────────
                // Write to cash_ins.json (source of truth for SSP IN display)
                $cinRecord = $store->appendWithId('cash_ins.json', [
                    'collector_id'   => $excStaff,   'collector_name' => $excStaffName,
                    'category'       => 'Exchange',   'currency'       => 'SSP',
                    'ssp_amount'     => $sspReceived, 'usd_given'      => $excAmt,
                    'rate'           => $excRate,     'amount'         => $excAmt,
                    'description'    => $desc,        'exchange_ref'   => $excRef,
                    'status'         => 'approved',   'approved_by'    => $by,
                    'approved_at'    => $now,         'created_at'     => $now,
                ]);
                // Write SSP IN to staff_ledger via StaffLedgerWriter (idempotent CIN-{id})
                StaffLedgerWriter::onCashIn($store->getPdo(), $cinRecord);

                // ── Side 2: USD OUT ─────────────────────────────────────────
                // Write to cash_expenses.json so ExpenseGateway shows it in wallet/CSV
                $expenseId = count($store->load('cash_expenses.json') ?: []) + 1;
                $usdExpEntry = [
                    'id'             => $expenseId,
                    'collector_id'   => $excStaff,
                    'collector_name' => $excStaffName,
                    'currency'       => 'USD',
                    'amount'         => $excAmt,
                    'ssp_amount'     => 0,
                    'category'       => 'Exchange',
                    'description'    => $desc,
                    'exchange_ref'   => $excRef,
                    'status'         => 'approved',
                    'approved_by'    => $by,
                    'approved_at'    => $now,
                    'auto_approved'  => true,
                    'submitted_at'   => $now,
                    'created_at'     => $now,
                    'project'        => 'dishnet',
                ];
                $allExp   = $store->load('cash_expenses.json') ?: [];
                $allExp[] = $usdExpEntry;
                $store->save('cash_expenses.json', $allExp);
                // Also write to staff_ledger directly for balance (idempotent)
                $ledger->record([
                    'staff_id'        => $excStaff,
                    'staff_name'      => $excStaffName,
                    'direction'       => 'out',
                    'currency'        => 'USD',
                    'amount'          => $excAmt,
                    'ssp_amount'      => 0,
                    'category'        => 'expense',
                    'subcategory'     => 'Exchange',
                    'description'     => $desc . ' [USD out]',
                    'status'          => 'active',
                    'source_type'     => 'exchange',
                    'source_id'       => $excRef,
                    'idempotency_key' => 'EXCHUSD-' . $excRef,
                    'event_date'      => $excDate,
                ]);

                // ── Write to company cb_ledger (dual-entry) ─────────────────
                // Company USD pool decreases, SSP pool increases
                $_cbExchRef = 'FIELD-' . $excRef;
                $_dupUsd = $pdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
                $_dupUsd->execute([$_cbExchRef . '-USD']);
                if (!$_dupUsd->fetchColumn()) {
                    $cb->addEntryRaw([
                        'project'           => 'dishnet',
                        'date'              => $excDate,
                        'direction'         => 'out',
                        'amount'            => $excAmt,
                        'currency'          => 'USD',
                        'ssp_amount'        => null,
                        'ssp_rate'          => $excRate,
                        'category'          => 'Exchange',
                        'category_raw'      => 'Exchange',
                        'person'            => $excStaffName,
                        'description'       => $desc,
                        'validation_ref'    => $_cbExchRef . '-USD',
                        'validation_status' => 'done',
                        'status'            => 'approved',
                        'source'            => 'field_exchange',
                    ]);
                }
                $_dupSsp = $pdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
                $_dupSsp->execute([$_cbExchRef . '-SSP']);
                if (!$_dupSsp->fetchColumn()) {
                    $cb->addEntryRaw([
                        'project'           => 'dishnet',
                        'date'              => $excDate,
                        'direction'         => 'in',
                        'amount'            => $excAmt,
                        'currency'          => 'SSP',
                        'ssp_amount'        => $sspReceived,
                        'ssp_rate'          => $excRate,
                        'category'          => 'Exchange',
                        'category_raw'      => 'Exchange',
                        'person'            => $excStaffName,
                        'description'       => $desc, // same as USD side
                        'validation_ref'    => $_cbExchRef . '-SSP',
                        'validation_status' => 'done',
                        'status'            => 'approved',
                        'source'            => 'field_exchange',
                    ]);
                }

                $ok2([
                    'direction'    => 'usd_to_ssp',
                    'usd_given'    => $excAmt,
                    'ssp_received' => $sspReceived,
                    'rate'         => $excRate,
                    'ref'          => $excRef,
                    'cash_in_id'   => $cinRecord['id'] ?? null,
                ]);

            } else {
                // Staff gives SSP, receives USD
                $sspGiven    = round($excAmt * $excRate, 0); // excAmt = USD amount to receive
                $usdReceived = $excAmt;
                $desc = $excNote ?: ('Exchange ' . number_format($sspGiven, 0) . ' SSP → $' . number_format($usdReceived, 2) . ' @ ' . number_format($excRate, 0));

                // ── Side 1: USD IN ──────────────────────────────────────────
                // Write to cash_ins.json as 'USD Received' (onCashIn handles this correctly)
                $cinRecord = $store->appendWithId('cash_ins.json', [
                    'collector_id'   => $excStaff,    'collector_name' => $excStaffName,
                    'category'       => 'USD Received','currency'       => 'USD',
                    'amount'         => $usdReceived,  'ssp_amount'     => 0,
                    'ssp_given'      => $sspGiven,     'rate'           => $excRate,
                    'description'    => $desc,         'exchange_ref'   => $excRef,
                    'status'         => 'approved',    'approved_by'    => $by,
                    'approved_at'    => $now,          'created_at'     => $now,
                ]);
                // Write USD IN to staff_ledger (idempotent CIN-{id})
                StaffLedgerWriter::onCashIn($store->getPdo(), $cinRecord);

                // ── Side 2: SSP OUT ─────────────────────────────────────────
                // Write to cash_expenses.json so ExpenseGateway shows it in wallet/CSV
                $sspExpId = count($store->load('cash_expenses.json') ?: []) + 1;
                $sspExpEntry = [
                    'id'             => $sspExpId,
                    'collector_id'   => $excStaff,
                    'collector_name' => $excStaffName,
                    'currency'       => 'SSP',
                    'amount'         => $sspGiven,
                    'ssp_amount'     => $sspGiven,
                    'category'       => 'Exchange',
                    'description'    => $desc . ' [SSP out]',
                    'exchange_ref'   => $excRef,
                    'status'         => 'approved',
                    'approved_by'    => $by,
                    'approved_at'    => $now,
                    'auto_approved'  => true,
                    'submitted_at'   => $now,
                    'created_at'     => $now,
                    'project'        => 'dishnet',
                ];
                $allExp2   = $store->load('cash_expenses.json') ?: [];
                $allExp2[] = $sspExpEntry;
                $store->save('cash_expenses.json', $allExp2);
                // Also write to staff_ledger directly for balance (idempotent)
                $ledger->record([
                    'staff_id'        => $excStaff,
                    'staff_name'      => $excStaffName,
                    'direction'       => 'out',
                    'currency'        => 'SSP',
                    'amount'          => $sspGiven,
                    'ssp_amount'      => $sspGiven,
                    'category'        => 'expense',
                    'subcategory'     => 'Exchange',
                    'description'     => $desc . ' [SSP out]',
                    'status'          => 'active',
                    'source_type'     => 'exchange',
                    'source_id'       => $excRef,
                    'idempotency_key' => 'EXCHSSP-' . $excRef,
                    'event_date'      => $excDate,
                ]);

                // ── Write to company cb_ledger (dual-entry, SSP→USD) ────────
                // Company SSP pool decreases, USD pool increases
                $_cbExchRef2 = 'FIELD-' . $excRef;
                $_dupSsp2 = $pdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
                $_dupSsp2->execute([$_cbExchRef2 . '-SSP']);
                if (!$_dupSsp2->fetchColumn()) {
                    $cb->addEntryRaw([
                        'project'           => 'dishnet',
                        'date'              => $excDate,
                        'direction'         => 'out',
                        'amount'            => $usdReceived,
                        'currency'          => 'SSP',
                        'ssp_amount'        => $sspGiven,
                        'ssp_rate'          => $excRate,
                        'category'          => 'Exchange',
                        'category_raw'      => 'Exchange',
                        'person'            => $excStaffName,
                        'description'       => $desc . ' [field]',
                        'validation_ref'    => $_cbExchRef2 . '-SSP',
                        'validation_status' => 'done',
                        'status'            => 'approved',
                        'source'            => 'field_exchange',
                    ]);
                }
                $_dupUsd2 = $pdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
                $_dupUsd2->execute([$_cbExchRef2 . '-USD']);
                if (!$_dupUsd2->fetchColumn()) {
                    $cb->addEntryRaw([
                        'project'           => 'dishnet',
                        'date'              => $excDate,
                        'direction'         => 'in',
                        'amount'            => $usdReceived,
                        'currency'          => 'USD',
                        'ssp_amount'        => null,
                        'ssp_rate'          => $excRate,
                        'category'          => 'Exchange',
                        'category_raw'      => 'Exchange',
                        'person'            => $excStaffName,
                        'description'       => 'USD received from field exchange: ' . number_format($sspGiven, 0) . ' SSP @ ' . number_format($excRate, 0) . ' By ' . $excStaffName,
                        'validation_ref'    => $_cbExchRef2 . '-USD',
                        'validation_status' => 'done',
                        'status'            => 'approved',
                        'source'            => 'field_exchange',
                    ]);
                }

                // Phase C: deduct SSP from the original exchange batch(es) via FIFO
                // This ensures batch reconciliation reflects the real remaining SSP
                try {
                    $cb->deductFromBatchesFIFO(
                        $store->load('cash_ins.json') ?: [],
                        $excStaff, $excStaffName,
                        $sspGiven, $excRate, $excRef,
                        $desc, $excDate
                    );
                } catch (\Throwable $e) { /* non-fatal */ }

                $ok2([
                    'direction'    => 'ssp_to_usd',
                    'ssp_given'    => $sspGiven,
                    'usd_received' => $usdReceived,
                    'rate'         => $excRate,
                    'ref'          => $excRef,
                    'cash_in_id'   => $cinRecord['id'] ?? null,
                ]);
            }
        }

        // GET cashbook_balances — both USD+SSP balances + rate
        if ($act === 'cashbook_balances') {
            // v4.11.38: Field agents see their PERSONAL cash bag position.
            // Admin/accountant see the company cashbook (cb_ledger).
            // Previously field agents were shown the company cb_ledger balance which
            // is a completely different data source — caused wrong numbers in Field Register.
            // field_accountant = field staff (Diko) → personal bag
            // accountant / admin = Rupesh → company cb_ledger
            $_isFullAcct = $isAdmin2 || in_array($me2['role'] ?? '', ['accountant'], true);
            if (!$_isFullAcct) {
                require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
                $scpSvc  = new StaffCashPositionService($store, $store->getPdo());
                $agentId = (int)($me2['id'] ?? 0);
                $usdBal  = round($scpSvc->getCashInHand($agentId), 2);
                $sspBal  = (int)$scpSvc->getSSPBalance($agentId);
                $rate    = $cb->getExchangeRate() ?: 5700;
                $ok2([
                    'USD'          => ['balance' => $usdBal],
                    'SSP'          => ['balance' => $sspBal],
                    'exchange_rate'=> $rate,
                    'dishnet'      => ['balance' => $usdBal],
                    '4g'           => ['balance' => 0],
                    'bluecard'     => ['balance' => 0],
                    'combined_usd' => $usdBal,
                    'usd_equivalent_ssp' => 0.0,
                    'source'       => 'personal_bag',
                ]);
            } else {
                $ok2($cb->getBothBalances());
            }
        }

        // GET cashbook_entries — filterable entry list
        if ($act === 'cashbook_entries' && $met === 'GET') {
            $filters = [];
            if (!empty($_GET['currency']))  $filters['currency']  = $_GET['currency'];
            if (!empty($_GET['direction'])) $filters['direction'] = $_GET['direction'];
            if (!empty($_GET['status']))    $filters['status']    = $_GET['status'];
            if (!empty($_GET['category']))  $filters['category']  = $_GET['category'];
            if (!empty($_GET['date_from'])) $filters['date_from'] = $_GET['date_from'];
            if (!empty($_GET['date_to']))   $filters['date_to']   = $_GET['date_to'];
            if (!empty($_GET['limit']))     $filters['limit']     = (int)$_GET['limit'];
            // Non-admins see only their own entries
            if (!$isAcct) $filters['actor_id'] = (int)$me2['id'];
            $ok2(['entries' => $cb->getEntries($filters), 'balances' => $cb->getBothBalances()]);
        }

        // GET cashbook_ledger — running balance ledger for one currency
        if ($act === 'cashbook_ledger' && $met === 'GET') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $currency = strtoupper($_GET['currency'] ?? 'USD');
            $ok2([
                'ledger'  => $cb->getLedger($currency, $_GET['date_from'] ?? '', $_GET['date_to'] ?? ''),
                'summary' => $cb->getSummary($currency, $_GET['date_from'] ?? '', $_GET['date_to'] ?? ''),
                'balances'=> $cb->getBothBalances(),
            ]);
        }

        // GET cashbook_summary — totals in/out by category
        if ($act === 'cashbook_summary' && $met === 'GET') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $ok2($cb->getSummary('dishnet', $_GET['date_from'] ?? '', $_GET['date_to'] ?? ''));
        }

        // GET cashbook_pending — entries awaiting approval
        if ($act === 'cashbook_pending' && $met === 'GET') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $ok2(['pending' => $cb->getPendingEntries()]);
        }

        // POST cashbook_add_entry — log cash in or out
        if ($act === 'cashbook_add_entry' && $met === 'POST') {
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            // Collections logged by field agents are auto-approved
            // Expenses go pending (accountant approves)
            $category    = trim($body['category'] ?? 'adjustment');
            $autoApprove = in_array($category, ['collection','topup','opening'], true) || $isAcct;

            // Handle file upload if multipart
            $photoData = [];
            if (!empty($_FILES['photo']['tmp_name'])) {
                $photoData = ['photo_tmp' => $_FILES['photo']['tmp_name'], 'photo_name' => $_FILES['photo']['name']];
            }
            $result = $cb->addEntry(array_merge($body, $photoData), $me2, $autoApprove);
            if (!$result['success']) $er2($result['message'], 422);
            $ok2($result);
        }

        // POST cashbook_approve — approve a pending entry
        if ($act === 'cashbook_approve' && $met === 'POST') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id   = (int)($body['entry_id'] ?? 0);
            if (!$id) $er2('entry_id required.', 422);
            $result = $cb->approveEntry($id, $me2);
            if (!$result['success']) $er2($result['message'], 422);
            $ok2($result);
        }

        // POST cashbook_reject — reject a pending entry
        if ($act === 'cashbook_reject' && $met === 'POST') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $body   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id     = (int)($body['entry_id'] ?? 0);
            $reason = trim($body['reason'] ?? '');
            if (!$id) $er2('entry_id required.', 422);
            $result = $cb->rejectEntry($id, $reason, $me2);
            if (!$result['success']) $er2($result['message'], 422);
            $ok2($result);
        }

        // GET cashbook_retailer_summary — per-staff collection breakdown (admin/accountant)
        if ($act === 'cashbook_retailer_summary' && $met === 'GET') {
            if (!$isAcct) $er2('Admin/Accountant only.', 403);
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
            $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
            $all = $cb->getEntries(['date_from'=>$dateFrom,'date_to'=>$dateTo,'category'=>'collection','direction'=>'in']);
            $byActor = [];
            foreach ($all as $e) {
                $k = $e['actor_id'] ?? 0;
                $n = $e['actor_name'] ?? 'Unknown';
                if (!isset($byActor[$k])) $byActor[$k] = ['id'=>$k,'name'=>$n,'count'=>0,'usd'=>0,'ssp'=>0,'crm_synced'=>0,'pending'=>0];
                $byActor[$k]['count']++;
                if (($e['currency']??'USD')==='SSP') $byActor[$k]['ssp'] += (float)($e['amount']??0);
                else $byActor[$k]['usd'] += (float)($e['amount']??0);
                if (!empty($e['crm_synced'])) $byActor[$k]['crm_synced']++;
                if (($e['status']??'')!=='approved') $byActor[$k]['pending']++;
            }
            $ok2(['retailers'=>array_values($byActor),'date_from'=>$dateFrom,'date_to'=>$dateTo]);
        }

        // POST cashbook_set_opening — set opening balance (admin only)
        if ($act === 'cashbook_set_opening' && $met === 'POST') {
            if (!$isAdmin2) $er2('Admin only.', 403);
            $body     = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $currency = strtoupper(trim($body['currency'] ?? ''));
            $amount   = round((float)($body['amount'] ?? 0), 2);
            $result   = $cb->setOpeningBalance($currency, $amount, $me2);
            if (!$result['success']) $er2($result['message'], 422);
            $ok2($result);
        }

        // POST cashbook_set_rate — set USD→SSP exchange rate (admin/accountant)
        if ($act === 'cashbook_set_rate' && $met === 'POST') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $rate = round((float)($body['rate'] ?? 0), 4);
            $cb->setExchangeRate($rate, is_array($me2) ? ($me2['name'] ?? 'admin') : (string)$me2);
            $ok2(['ok' => true, 'rate' => $cb->getExchangeRate()]);
        }

        // GET cashbook_rate_history — last 60 days of SSP rate changes
        if ($act === 'cashbook_rate_history' && $met === 'GET') {
            $history = $cb->getRateHistory(60);
            $current = $cb->getExchangeRate();
            $ok2(['current_rate' => $current, 'history' => $history]);
        }

        // ── Cashbook v2 API actions ──────────────────────────────────────

        // GET cashbook_v2_balances — both project balances
        if ($act === 'cashbook_v2_balances' && $met === 'GET') {
            $ok2($cb->getBothBalances());
        }

        // GET cashbook_v2_entries — paginated entries with filters
        if ($act === 'cashbook_v2_entries' && $met === 'GET') {
            $f = array_filter([
                'project'           => $_GET['project'] ?? '',
                'category'          => $_GET['category'] ?? '',
                'validation_status' => $_GET['validation_status'] ?? '',
                'date_from'         => $_GET['date_from'] ?? '',
                'date_to'           => $_GET['date_to'] ?? '',
                'search'            => $_GET['search'] ?? '',
                'status'            => $_GET['status'] ?? '',
            ]);
            $f['limit']  = min(200, max(1, (int)($_GET['limit']  ?? 50)));
            $f['offset'] = max(0, (int)($_GET['offset'] ?? 0));
            $ok2(['entries' => $cb->getEntries($f), 'total' => $cb->countFiltered($f)]);
        }

        // GET cashbook_v2_pending — pending disbursements
        if ($act === 'cashbook_v2_pending' && $met === 'GET') {
            $proj = $_GET['project'] ?? '';
            $ok2([
                'disbursements' => $cb->getPendingDisbursements($proj),
                'staff_position'=> $cb->getStaffCashPosition($proj),
            ]);
        }

        // GET cashbook_v2_summary — category P&L summary
        if ($act === 'cashbook_v2_summary' && $met === 'GET') {
            $ok2($cb->getSummary(
                $_GET['project'] ?? 'dishnet',
                $_GET['date_from'] ?? '',
                $_GET['date_to'] ?? ''
            ));
        }

        // GET cashbook_v2_sites — 4G site tracker
        if ($act === 'cashbook_v2_sites' && $met === 'GET') {
            $ok2($cb->getSiteTracker($_GET['type'] ?? 'power'));
        }

        // GET cashbook_v2_payroll — monthly payroll summary
        if ($act === 'cashbook_v2_payroll' && $met === 'GET') {
            $ok2($cb->getPayrollSummary($_GET['project'] ?? '', $_GET['month'] ?? ''));
        }

        // POST cashbook_v2_add — add new entry
        if ($act === 'cashbook_v2_add' && $met === 'POST') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $result = $cb->addEntry($body, ['name' => $me2], true);
            if (!$result['ok']) $er2($result['error'], 422);
            $ok2($result);
        }

        // POST cashbook_v2_settle — settle a pending disbursement
        if ($act === 'cashbook_v2_settle' && $met === 'POST') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $result = $cb->settleDisb(
                (int)($body['entry_id'] ?? 0),
                trim($body['voucher_no'] ?? ''),
                (float)($body['return_amount'] ?? 0),
                ['name' => $me2]
            );
            if (!$result['ok']) $er2($result['error'] ?? 'Failed', 422);
            $ok2($result);
        }

        // POST cashbook_v2_set_rate — update exchange rate
        if ($act === 'cashbook_v2_set_rate' && $met === 'POST') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $cb->setExchangeRate((float)($body['rate'] ?? 0), $me2);
            $ok2(['ok' => true, 'rate' => $cb->getExchangeRate()]);
        }

        // GET cb_categories — load category config (with built-in defaults)
        if ($act === 'cb_categories' && $met === 'GET') {
            $cats = $store->load('cb_categories.json');
            if (!$cats) {
                $cats = [
                    'in' => [
                        ['id'=>'Receipt','ic'=>'💰','lbl'=>'Receipt','group'=>'in'],
                        ['id'=>'Bank Transfer','ic'=>'🏦','lbl'=>'Bank Transfer','group'=>'in'],
                        ['id'=>'Loan Received','ic'=>'💵','lbl'=>'Loan Received','group'=>'in'],
                        ['id'=>'Loan Return Received','ic'=>'↩️','lbl'=>'Loan Return','group'=>'in'],
                        ['id'=>'Interco In','ic'=>'🔗','lbl'=>'Interco In','group'=>'in'],
                        ['id'=>'Refund','ic'=>'🔙','lbl'=>'Refund','group'=>'in'],
                        ['id'=>'Build Africa','ic'=>'🏗️','lbl'=>'Build Africa','group'=>'in'],
                        ['id'=>'Opening Balance','ic'=>'📊','lbl'=>'Opening Bal','group'=>'in'],
                        ['id'=>'Misc Income','ic'=>'📦','lbl'=>'Misc Income','group'=>'in'],
                        ['id'=>'SSP Return','ic'=>'↩️','lbl'=>'SSP Return','group'=>'in'],
                    ],
                    'out_people' => [
                        ['id'=>'Salary','ic'=>'💼','lbl'=>'Salary','group'=>'out_people'],
                        ['id'=>'Transport Allowance','ic'=>'🚗','lbl'=>'Transport','group'=>'out_people'],
                        ['id'=>'Food Allowance','ic'=>'🍽️','lbl'=>'Food Allow.','group'=>'out_people'],
                        ['id'=>'Commission','ic'=>'💵','lbl'=>'Commission','group'=>'out_people'],
                        ['id'=>'Bonus','ic'=>'💰','lbl'=>'Bonus','group'=>'out_people'],
                        ['id'=>'Employee Benefit','ic'=>'👤','lbl'=>'Emp. Benefit','group'=>'out_people'],
                        ['id'=>'Staff Advance','ic'=>'💸','lbl'=>'Staff Advance','group'=>'out_people'],
                        ['id'=>'SSP Advance','ic'=>'🇸🇸','lbl'=>'SSP Advance','group'=>'out_people'],
                        ['id'=>'Partner Remuneration','ic'=>'🤝','lbl'=>'Partner Rem.','group'=>'out_people'],
                    ],
                    'out_ops' => [
                        ['id'=>'Travel & Field','ic'=>'🏗️','lbl'=>'Travel & Field','group'=>'out_ops'],
                        ['id'=>'Local Purchase','ic'=>'🛒','lbl'=>'Local Purchase','group'=>'out_ops'],
                        ['id'=>'Site Power','ic'=>'⚡','lbl'=>'Site Power','group'=>'out_ops'],
                        ['id'=>'Site Rent','ic'=>'🏠','lbl'=>'Site Rent','group'=>'out_ops'],
                        ['id'=>'Site Expense','ic'=>'🏢','lbl'=>'Site Expense','group'=>'out_ops'],
                        ['id'=>'Airtime','ic'=>'📱','lbl'=>'Airtime','group'=>'out_ops'],
                        ['id'=>'Bandwidth','ic'=>'📡','lbl'=>'Bandwidth','group'=>'out_ops'],
                        ['id'=>'Vehicle','ic'=>'🚗','lbl'=>'Vehicle','group'=>'out_ops'],
                        ['id'=>'Advertising','ic'=>'📢','lbl'=>'Advertising','group'=>'out_ops'],
                        ['id'=>'Renewal Charges','ic'=>'🔄','lbl'=>'Renewal Chgs','group'=>'out_ops'],
                        ['id'=>'Customer Refund','ic'=>'↩️','lbl'=>'Cust. Refund','group'=>'out_ops'],
                        ['id'=>'Customer Commission','ic'=>'🤝','lbl'=>'Cust. Commission','group'=>'out_ops'],
                    ],
                    'out_fin' => [
                        ['id'=>'Tax','ic'=>'💸','lbl'=>'Tax','group'=>'out_fin'],
                        ['id'=>'Govt Fees','ic'=>'🏛️','lbl'=>'Govt Fees','group'=>'out_fin'],
                        ['id'=>'Legal Fees','ic'=>'⚖️','lbl'=>'Legal Fees','group'=>'out_fin'],
                        ['id'=>'Loan Given','ic'=>'💵','lbl'=>'Loan Given','group'=>'out_fin'],
                        ['id'=>'Interco Out','ic'=>'🔗','lbl'=>'Interco Out','group'=>'out_fin'],
                        ['id'=>'Bank Transfer','ic'=>'🏦','lbl'=>'Bank Transfer','group'=>'out_fin'],
                        ['id'=>'Capital Purchase','ic'=>'🖥️','lbl'=>'Capital Purch.','group'=>'out_fin'],
                        ['id'=>'Refund','ic'=>'↩️','lbl'=>'Refund','group'=>'out_fin'],
                        ['id'=>'Discount','ic'=>'📉','lbl'=>'Discount','group'=>'out_fin'],
                        ['id'=>'Build Africa','ic'=>'🏗️','lbl'=>'Build Africa','group'=>'out_fin'],
                        ['id'=>'Misc Expense','ic'=>'📦','lbl'=>'Misc Expense','group'=>'out_fin'],
                    ],
                ];
            }
            // v4.9.10: Merge any new built-in tiles that are missing from a saved config
            // (happens when cb_categories.json was saved before new tiles were added)
            $_newTiles = [
                'in' => [
                    ['id'=>'SSP Return','ic'=>'↩️','lbl'=>'SSP Return','group'=>'in'],
                ],
                'out_people' => [
                    ['id'=>'Partner Remuneration','ic'=>'🤝','lbl'=>'Partner Rem.','group'=>'out_people'],
                    ['id'=>'Staff Advance','ic'=>'💸','lbl'=>'Staff Advance','group'=>'out_people'],
                    ['id'=>'SSP Advance','ic'=>'🇸🇸','lbl'=>'SSP Advance','group'=>'out_people'],
                ],
                'out_ops' => [
                    ['id'=>'Vehicle','ic'=>'🚗','lbl'=>'Vehicle','group'=>'out_ops'],
                    ['id'=>'Advertising','ic'=>'📢','lbl'=>'Advertising','group'=>'out_ops'],
                    ['id'=>'Renewal Charges','ic'=>'🔄','lbl'=>'Renewal Chgs','group'=>'out_ops'],
                    ['id'=>'Customer Refund','ic'=>'↩️','lbl'=>'Cust. Refund','group'=>'out_ops'],
                    ['id'=>'Customer Commission','ic'=>'🤝','lbl'=>'Cust. Commission','group'=>'out_ops'],
                ],
                'out_fin' => [
                    ['id'=>'Govt Fees','ic'=>'🏛️','lbl'=>'Govt Fees','group'=>'out_fin'],
                    ['id'=>'Legal Fees','ic'=>'⚖️','lbl'=>'Legal Fees','group'=>'out_fin'],
                ],
            ];
            foreach ($_newTiles as $grp => $tiles) {
                if (!isset($cats[$grp])) continue;
                $existingIds = array_column($cats[$grp], 'id');
                foreach ($tiles as $tile) {
                    if (!in_array($tile['id'], $existingIds, true)) {
                        $cats[$grp][] = $tile;
                    }
                }
            }
            // v4.9.10: Append BookKeeper account names for "Other..." typeahead search
            // and custom_categories learned from user entries
            if (!isset($cats['bk_accounts'])) {
                $bkFile = dirname(__DIR__) . '/bk_accounts.php';
                $cats['bk_accounts'] = file_exists($bkFile) ? (require $bkFile) : [];
            }
            if (!isset($cats['custom_categories'])) {
                $cats['custom_categories'] = [];
            }
            // v4.9.10: Append sub-item lists for structured categories
            if (!isset($cats['govt_items'])) {
                $cats['govt_items'] = [
                    'NCA Administrative Fees','NCA Operation Fees','NRA Audit Fees',
                    'USAF (Universal Service Fund)','Excise Tax','PIT Tax','BPT Tax','Rental Tax (WT)',
                ];
            }
            if (!isset($cats['vehicle_items'])) {
                $cats['vehicle_items'] = ['Maintenance','Fuel / Diesel','Insurance','Spare Parts','Registration'];
            }
            if (!isset($cats['ad_items'])) {
                $cats['ad_items'] = ['Facebook Ads','Google Ads','Print / Billboard','Other'];
            }
            if (!isset($cats['partners'])) {
                $cats['partners'] = CashbookService::PARTNERS ?? [
                    'Tom (Joseph Luate)','Bhavin (Madlani)','Nirmal (Samani)',
                    'Paji (Shamshare Singh)','Rupesh',
                ];
            }
            $ok2($cats);
        }

        // POST cb_categories_save — admin/accountant only
        if ($act === 'cb_categories_save' && $met === 'POST') {
            if (!$isAcct) $er2('Accountant/Admin only.', 403);
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $allowed = ['in','out_people','out_ops','out_fin'];
            $clean = [];
            foreach ($allowed as $grp) {
                $clean[$grp] = [];
                foreach ($body[$grp] ?? [] as $cat) {
                    $id = trim($cat['id'] ?? '');
                    if ($id === '') continue;
                    $clean[$grp][] = [
                        'id'    => $id,
                        'ic'    => trim($cat['ic'] ?? '📦'),
                        'lbl'   => trim($cat['lbl'] ?? $id),
                        'group' => $grp,
                    ];
                }
            }
            // v4.9.10: Preserve sub-item lists and custom categories
            $preserveKeys = ['bk_accounts','custom_categories','govt_items','vehicle_items','ad_items','partners'];
            foreach ($preserveKeys as $pk) {
                if (isset($body[$pk]) && is_array($body[$pk])) {
                    $clean[$pk] = array_values(array_filter(array_map('trim', array_map('strval', $body[$pk]))));
                } else {
                    // Keep existing values from current file
                    $existing = $store->load('cb_categories.json');
                    if (isset($existing[$pk])) $clean[$pk] = $existing[$pk];
                }
            }
            $store->save('cb_categories.json', $clean);
            $ok2(['ok'=>true,'saved'=>array_sum(array_map('count', $clean))]);
        }

        // GET/POST fix_exchange_rate_6000 — correct Mar 30 exchange rate 5700→6000
        if ($act === 'fix_exchange_rate_6000') {
            if (!$isAdmin2) $er2('Admin only.', 403);
            require_once dirname(__DIR__, 2) . '/fix_exchange_rate_6000.php';
            return;
        }

        // GET/POST fix_exchange_usd_out — admin only, fixes missing USD OUT from Exchange
        if ($act === 'fix_exchange_usd_out') {
            if (!$isAdmin2) $er2('Admin only.', 403);
            require_once dirname(__DIR__, 2) . '/fix_exchange_usd_out.php';
            return;
        }

        // GET accounting_health_check — verifies all Phase 1-3 SSP accounting changes
        if ($act === 'accounting_health_check') {
            if (!$isAdmin2) $er2('Admin only.', 403);
            require_once dirname(__DIR__, 2) . '/fix_accounting_health_check.php';
            return;
        }

        // GET fix_exchange_cbLedger_backfill — write missing exchange entries to cb_ledger
        if ($act === 'fix_exchange_cbLedger_backfill') {
            if (!$isAdmin2) $er2('Admin only.', 403);
            require_once dirname(__DIR__, 2) . '/fix_exchange_cbLedger_backfill.php';
            return;
        }

        // GET get_exchange_context — returns last actual market rate from cash_ins.json
        // plus 7-day stats. Used by exchange form (live banner) and SSP hero card.
        // No auth restriction beyond basic login — any staff can see the rate context.
        if ($act === 'get_exchange_context') {
            $cashIns = $store->load('cash_ins.json') ?? [];
            $ctx     = $cb->getLastExchangeContext($cashIns);
            $ok2($ctx);
            return;
        }

        // GET/POST fix_diko_ssp_backfill — admin only one-time data fix
        if ($act === 'fix_diko_ssp_backfill') {
            if (!$isAdmin2) $er2('Admin only.', 403);
            require_once dirname(__DIR__, 2) . '/fix_diko_ssp_backfill.php';
            return;
        }

        // GET/POST fix_expense_ssp_amounts — backfill correct SSP amounts into
        // cb_ledger for expense_sync rows where ssp_amount is NULL/0 (Phase 1 fix).
        // Dry run by default; add &dry_run=0 to apply.
        if ($act === 'fix_expense_ssp_amounts') {
            if (!$isAdmin2) $er2('Admin only.', 403);
            require_once dirname(__DIR__, 2) . '/fix_expense_ssp_amounts.php';
            return;
        }

    // ── handover_inspect — admin only, view raw handover records by IDs ──
    if ($act === 'handover_inspect') {
        if (!$isAdmin) $er2('Admin only', 403);
        $ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
        $all = $store->load('cash_handovers.json') ?: [];
        if (!empty($ids)) {
            $all = array_values(array_filter($all, fn($h) => in_array((int)($h['id']??0), $ids, true)));
        }
        $ok2(['handovers' => $all, 'count' => count($all)]);
    }

        $er2('Unknown cashbook action: '.$act, 404);
    }
