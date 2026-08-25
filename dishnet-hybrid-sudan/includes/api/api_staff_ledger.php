<?php
// ═══════════════════════════════════════════════════════════════════════
// api_staff_ledger.php — Unified Staff Ledger REST API (v4.11.3)
// POST-AUTH: $me2, $rid, $isAdmin, $retailer, $can() available
// Router vars: $act, $met, $ok2(), $er2(), $store, $dataDir
// ═══════════════════════════════════════════════════════════════════════

// ── Fix staff_ledger rows with wrong staff_id for staff payment expenses ──
// GET ?page=api&action=fix_ssp_amounts_in_ledger
// Fixes staff_ledger rows where ssp_amount=0 but source has correct ssp_amount > 0
if ($act === 'fix_ssp_amounts_in_ledger') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo = $store->getPdo();
    $fixed = 0; $skipped = 0; $errors = [];

    // Fix staff_expenses rows (EXP-{id})
    // Uses COALESCE(ssp_amount, amount) to handle cases where ssp_amount=0 but amount=45000
    try {
        $rows = $pdo->query("
            SELECT sl.id as sl_id, sl.idempotency_key,
                   COALESCE(NULLIF(se.ssp_amount,0), se.amount) as correct_ssp,
                   se.currency, se.id as exp_id, se.category, se.ssp_amount, se.amount
            FROM staff_ledger sl
            JOIN staff_expenses se ON sl.idempotency_key = 'EXP-' || se.id
            WHERE sl.ssp_amount = 0
              AND sl.status = 'active'
              AND se.currency = 'SSP'
              AND (se.ssp_amount > 0 OR se.amount > 0)
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $correctSsp = (float)$r['correct_ssp'];
            if ($correctSsp <= 0) { $skipped++; continue; }
            try {
                $pdo->prepare(
                    "UPDATE staff_ledger SET ssp_amount=?, updated_at=datetime('now') WHERE id=?"
                )->execute([$correctSsp, $r['sl_id']]);
                $fixed++;
                logActivity($dataDir, 'fix_ssp_amounts_in_ledger',
                    "Fixed EXP-{$r['exp_id']}: ssp_amount 0 -> {$correctSsp} ({$r['category']})", 'fix_script');
            } catch (\Throwable $e) {
                $errors[] = "EXP-{$r['exp_id']}: " . $e->getMessage();
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'staff_expenses join: ' . $e->getMessage();
    }

    // Fix cash_expenses rows (FEXP-{id}) -- these store ssp_amount in the JSON blob
    try {
        $allExps = $store->load('cash_expenses.json') ?? [];
        foreach ($allExps as $exp) {
            $expId = (int)($exp['id'] ?? 0);
            $currency = strtoupper($exp['currency'] ?? 'USD');
            $sspAmt = (float)($exp['ssp_amount'] ?? 0);
            if (!$expId || $currency !== 'SSP' || $sspAmt <= 0) continue;

            $idemKey = 'FEXP-' . $expId;
            $row = $pdo->prepare(
                "SELECT id, ssp_amount FROM staff_ledger WHERE idempotency_key=? AND status='active' LIMIT 1"
            );
            $row->execute([$idemKey]);
            $existing = $row->fetch(PDO::FETCH_ASSOC);
            if (!$existing || (float)$existing['ssp_amount'] > 0) { $skipped++; continue; }

            try {
                $pdo->prepare(
                    "UPDATE staff_ledger SET ssp_amount=?, updated_at=datetime('now') WHERE id=?"
                )->execute([$sspAmt, $existing['id']]);
                $fixed++;
                logActivity($dataDir, 'fix_ssp_amounts_in_ledger',
                    "Fixed FEXP-{$expId}: ssp_amount 0 -> {$sspAmt}", 'fix_script');
            } catch (\Throwable $e) {
                $errors[] = "FEXP-{$expId}: " . $e->getMessage();
            }
        }
    } catch (\Throwable $e) {
        $errors[] = 'cash_expenses: ' . $e->getMessage();
    }

    $ok2(['fixed' => $fixed, 'skipped' => $skipped, 'errors' => $errors,
          'message' => "Fixed {$fixed} ledger rows with wrong ssp_amount. Run staff_ledger_backfill next."]);
}

// GET ?page=api&action=fix_staff_ledger_staff_payments
if ($act === 'fix_staff_ledger_staff_payments') {
    if (!$isAdmin) $er2('Admin only', 403);
    $fixResult = ['steps' => [], 'fixed' => 0, 'skipped' => 0, 'errors' => [], 'ok' => false];
    include dirname(__DIR__, 2) . '/fix_staff_ledger_staff_payments.php';
    $ok2($fixResult);
}

// ── One-time repair: create missing cash_in for CB-2669 (Diko Apr 2026 advance) ──
// GET ?page=api&action=repair_fexp13_bbc
// Fixes FEXP-13 which was incorrectly moved to Joel (19) but should be under BBC (13)
// The vehicle/fuel expense was PAID BY BBC -- the debit must stay on BBC's ledger
if ($act === 'repair_fexp13_bbc') {
    if (!($me2['is_admin'] ?? false)) $er2('Admin only', 403);
    $steps = [];
    try {
        // Check current state
        $chk = $pdo->prepare("SELECT id, staff_id, staff_name, direction, amount, ssp_amount, description FROM staff_ledger WHERE idempotency_key = 'FEXP-13' LIMIT 1");
        $chk->execute();
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $steps[] = 'FEXP-13 not found in staff_ledger -- nothing to fix';
        } elseif ((int)$row['staff_id'] === 13) {
            $steps[] = 'FEXP-13 already under BBC (staff_id=13) -- no change needed';
        } else {
            $old_id = (int)$row['staff_id'];
            $pdo->prepare(
                "UPDATE staff_ledger SET staff_id=13, staff_name='BBC DishNet',
                 description = description || ' [payer fix: ' || ? || '->' || '13]'
                 WHERE idempotency_key='FEXP-13'"
            )->execute([$old_id]);
            $steps[] = "Fixed FEXP-13: staff_id {$old_id} -> 13 (BBC DishNet)";
            $steps[] = "BBC ledger balance will now reflect the 45,000 SSP vehicle debit correctly";
        }
        $ok2(['steps' => $steps, 'ok' => true]);
    } catch (Throwable $e) {
        $er2('Error: ' . $e->getMessage());
    }
}

// GET ?page=api&action=repair_cashin_cb2669
// Admin only. Safe to run multiple times (idempotent).
if ($act === 'repair_cashin_cb2669') {
    if (!$isAdmin) $er2('Admin only', 403);
    $repairResult = ['steps' => [], 'ok' => false]; // default; overwritten by include
    include dirname(__DIR__, 2) . '/fix_missing_cashin_cb2669.php';
    $ok2($repairResult);
}

if ($act === 'staff_ledger_backfill') {
    if (!$isAdmin) $er2('Admin only', 403);
    $isApiCall = true;
    $pdo = $store->getPdo();
    include dirname(__DIR__, 2) . '/backfill_staff_ledger.php';
    $ok2($backfillResult ?? ['error' => 'backfill did not return result']);
}

if ($act === 'staff_ledger_balance') {
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    $_slPdo   = $store->getPdo();
    $ledger   = new StaffLedgerService($_slPdo);
    $staffId  = (int)($_GET['staff_id'] ?? $rid);
    $currency = strtoupper($_GET['currency'] ?? 'USD');
    $ok2(['staff_id' => $staffId, 'currency' => $currency, 'balance' => $ledger->balance($staffId, $currency)]);
}

if ($act === 'staff_ledger_position') {
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    $ledger  = new StaffLedgerService($store->getPdo());
    $staffId = (int)($_GET['staff_id'] ?? $rid);
    $ok2($ledger->position($staffId, strtoupper($_GET['currency'] ?? 'USD')));
}

if ($act === 'staff_ledger_positions') {
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    $ledger = new StaffLedgerService($store->getPdo());
    $ok2(['positions' => $ledger->allPositions(strtoupper($_GET['currency'] ?? 'USD'))]);
}

if ($act === 'staff_ledger_entries') {
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    $ledger  = new StaffLedgerService($store->getPdo());
    $staffId = (int)($_GET['staff_id'] ?? $rid);
    $filters = array_filter([
        'currency'    => $_GET['currency'] ?? null,
        'category'    => $_GET['category'] ?? null,
        'status'      => $_GET['status'] ?? null,
        'from'        => $_GET['from'] ?? null,
        'to'          => $_GET['to'] ?? null,
        'source_type' => $_GET['source_type'] ?? null,
        'limit'       => (int)($_GET['limit'] ?? 100),
        'offset'      => (int)($_GET['offset'] ?? 0),
    ], function ($v) { return $v !== null; });
    $ok2(['staff_id' => $staffId, 'entries' => $ledger->entries($staffId, $filters)]);
}

if ($act === 'staff_ledger_reconcile') {
    if (!$isAdmin && ($retailer['role'] ?? '') !== 'accountant') $er2('Admin or accountant only', 403);
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
    $_slPdo = $store->getPdo();
    $ledger = new StaffLedgerService($_slPdo);
    $oldSvc = new StaffCashPositionService($store, $_slPdo);
    $oldAll = $oldSvc->getAllPositions();
    $ok2([
        'mismatches'   => $ledger->reconcileVsOld($oldAll),
        'old_count'    => count($oldAll),
        'ledger_count' => count($ledger->allPositions('USD')),
    ]);
}

if ($act === 'staff_ledger_summary') {
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    $ledger  = new StaffLedgerService($store->getPdo());
    $staffId = (int)($_GET['staff_id'] ?? $rid);
    $month   = $_GET['month'] ?? date('Y-m');
    $ok2($ledger->monthlySummary($staffId, $month, strtoupper($_GET['currency'] ?? 'USD')));
}

if ($act === 'staff_ledger_stats') {
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    $ledger = new StaffLedgerService($store->getPdo());
    $ok2(['total_rows' => $ledger->totalRows(), 'by_source' => $ledger->countBySource()]);
}

if ($act === 'ssp_debug' && $isAdmin) {
    // Full SSP diagnostic for one staff member
    // GET ?page=api&action=ssp_debug&staff_id=9
    $sid = (int)($_GET['staff_id'] ?? 0);
    if (!$sid) $er2('staff_id required', 422);
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerService.php';
    require_once dirname(__DIR__, 2) . '/lib/ExpenseGateway.php';
    require_once dirname(__DIR__, 2) . '/lib/DualReadCashPosition.php';
    $pdo    = $store->getPdo();
    $ledger = new StaffLedgerService($pdo);
    $config = $store->load('kyc_config.json') ?? [];

    // 1. Ledger balance
    $ledgerBal = $ledger->balance($sid, 'SSP');

    // 2. Ledger entries breakdown
    $ledgerEntries = $pdo->prepare("SELECT direction, category, source_type, COUNT(*) as cnt, SUM(amount) as total FROM staff_ledger WHERE staff_id=? AND currency='SSP' AND status NOT IN ('voided','cancelled') GROUP BY direction, category, source_type ORDER BY direction, category");
    $ledgerEntries->execute([$sid]);
    $ledgerBreakdown = $ledgerEntries->fetchAll(\PDO::FETCH_ASSOC);

    // 3. cash_ins.json SSP entries
    $cins = $store->findAll('cash_ins.json', 'collector_id', $sid);
    $sspInTotal = 0; $sspInRows = [];
    foreach ($cins as $i) {
        if (!in_array($i['category'] ?? '', ['SSP Received', 'Exchange'])) continue;
        if (in_array($i['status'] ?? 'approved', ['rejected', 'voided'])) continue;
        $amt = (float)($i['ssp_amount'] ?? 0);
        $sspInTotal += $amt;
        $sspInRows[] = ['id'=>$i['id']??'?','cat'=>$i['category'],'ssp_amount'=>$amt,'date'=>substr($i['created_at']??'',0,10),'status'=>$i['status']??'approved'];
    }

    // 4. Expenses (unified gateway)
    $gw = new ExpenseGateway($store);
    $exps = $gw->getByStaff($sid);
    $sspExpTotal = 0; $sspExpRows = [];
    foreach ($exps as $e) {
        if (strtoupper($e['currency'] ?? 'USD') !== 'SSP') continue;
        if (!in_array($e['status'] ?? '', ['approved', 'pending'])) continue;
        $amt = (float)($e['ssp_amount'] ?? $e['amount'] ?? 0);
        $sspExpTotal += $amt;
        $sspExpRows[] = ['id'=>$e['id']??'?','cat'=>$e['category']??$e['expense_type']??'?','amt'=>$amt,'status'=>$e['status'],'date'=>substr($e['submitted_at']??$e['created_at']??'',0,10)];
    }

    // 5. Handovers — check ALL confirmed handovers and show currency field
    $hovs = $store->findAll('cash_handovers.json', 'from_id', $sid);
    $sspHovTotal = 0; $hovRows = [];
    foreach ($hovs as $h) {
        if (($h['status'] ?? '') !== 'confirmed') continue;
        $hCur  = $h['currency'] ?? '(none)';
        $hSsp  = (float)($h['ssp_amount'] ?? 0);
        $hAmt  = (float)($h['amount'] ?? 0);
        $hovRows[] = ['id'=>$h['id']??'?','currency_field'=>$hCur,'amount'=>$hAmt,'ssp_amount'=>$hSsp,'date'=>substr($h['created_at']??'',0,10)];
        // Would be counted as SSP if currency=SSP
        if (strtoupper($hCur) === 'SSP') $sspHovTotal += $hSsp > 0 ? $hSsp : $hAmt;
    }

    // 6. Computed fallback result
    $jsonResult = max(0, $sspInTotal - $sspExpTotal - $sspHovTotal);

    $ok2([
        'staff_id'             => $sid,
        'ledger_enabled'       => ($config['ledger_enabled'] ?? true) !== false,
        'ledger_ssp_balance'   => $ledgerBal,
        'ledger_breakdown'     => $ledgerBreakdown,
        'json_ssp_in'          => $sspInTotal,
        'json_ssp_in_rows'     => $sspInRows,
        'json_ssp_exp_out'     => $sspExpTotal,
        'json_ssp_exp_rows'    => $sspExpRows,
        'json_ssp_hov_counted' => $sspHovTotal,
        'all_confirmed_hovs'   => $hovRows,
        'json_computed_result' => $jsonResult,
        'verdict'              => $ledgerBal > 0 ? 'ledger says ' . $ledgerBal . ' SSP' : ('json fallback says ' . $jsonResult . ' SSP'),
    ]);
}

// ── Backfill HOV-IN entries for historical handovers ─────────────────────────
// Fixes: confirmed handovers before v4.11.39 have no HOV-IN entry for receiver.
// Receiver's (Diko's) cashbook showed less cash than she actually received.
//
// Usage:
//   Dry run (safe, no changes): ?page=api&action=backfill_hov_in
//   Apply:                      ?page=api&action=backfill_hov_in&dry_run=0
// Admin only. Idempotent — safe to run multiple times.
if ($act === 'backfill_hov_in') {
    if (!$isAdmin) $er2('Admin only', 403);

    $pdo    = $store->getPdo();
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $fixed  = []; $skipped = []; $errors = [];

    // Load all confirmed handovers
    $hovs = $store->load('cash_handovers.json');
    if (!is_array($hovs)) $hovs = [];

    foreach ($hovs as $hov) {
        if (($hov['status'] ?? '') !== 'confirmed') { $skipped[] = ['id' => $hov['id'] ?? '?', 'reason' => 'not confirmed']; continue; }
        $id    = (int)($hov['id'] ?? 0);
        $toId  = (int)($hov['to_id'] ?? 0);
        if ($id <= 0 || $toId <= 0) { $skipped[] = ['id' => $id, 'reason' => 'missing id or to_id']; continue; }

        $iKey = 'HOV-IN-' . $id;

        // Check if HOV-IN already exists — idempotent
        $exists = $pdo->prepare("SELECT COUNT(*) FROM staff_ledger WHERE idempotency_key = ?")->execute([$iKey])
            ? (int)$pdo->query("SELECT COUNT(*) FROM staff_ledger WHERE idempotency_key = '$iKey'")->fetchColumn()
            : 0;
        // Re-query properly
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_ledger WHERE idempotency_key = ?");
        $stmt->execute([$iKey]);
        $exists = (int)$stmt->fetchColumn();

        if ($exists > 0) { $skipped[] = ['id' => $id, 'reason' => 'HOV-IN already exists']; continue; }

        $currency  = strtoupper($hov['currency'] ?? 'USD');
        $amount    = round((float)($hov['amount']     ?? 0), 2);
        $sspAmount = round((float)($hov['ssp_amount'] ?? 0), 2);
        $sspRate   = round((float)($hov['ssp_rate']   ?? 0), 4);
        $eventDate = substr($hov['confirmed_at'] ?? $hov['created_at'] ?? date('Y-m-d'), 0, 10);

        if ($amount <= 0 && $sspAmount <= 0) { $skipped[] = ['id' => $id, 'reason' => 'zero amount']; continue; }

        if (!$dryRun) {
            try {
                $pdo->prepare("INSERT INTO staff_ledger
                    (staff_id, staff_name, direction, currency, amount, ssp_amount, ssp_rate,
                     category, subcategory, description, status, source_type, source_id,
                     idempotency_key, counterparty_id, counterparty_name, event_date, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))")
                ->execute([
                    $toId,
                    $hov['to_name'] ?? '',
                    'in',
                    $currency,
                    $amount,
                    $sspAmount,
                    $sspRate,
                    'collection',
                    'handover_received',
                    'Received handover #' . $id . ' from ' . ($hov['from_name'] ?? 'staff') . ' [backfill]',
                    'active',
                    'cash_handovers',
                    (string)$id,
                    $iKey,
                    (int)($hov['from_id'] ?? 0),
                    $hov['from_name'] ?? '',
                    $eventDate,
                ]);
                $fixed[] = ['id' => $id, 'to' => $hov['to_name'] ?? '', 'amount' => $amount, 'currency' => $currency, 'date' => $eventDate];
            } catch (\Throwable $e) {
                $errors[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        } else {
            // Dry run — just report what would be fixed
            $fixed[] = ['id' => $id, 'to' => $hov['to_name'] ?? '', 'amount' => $amount, 'currency' => $currency, 'date' => $eventDate, 'dry_run' => true];
        }
    }

    $ok2([
        'dry_run'      => $dryRun,
        'fixed'        => $fixed,
        'fixed_count'  => count($fixed),
        'skipped'      => $skipped,
        'skipped_count'=> count($skipped),
        'errors'       => $errors,
        'message'      => $dryRun
            ? count($fixed) . ' HOV-IN entries would be created. Add &dry_run=0 to apply.'
            : count($fixed) . ' HOV-IN entries created. ' . count($errors) . ' errors.',
    ]);
}

// ── Fix SSP advances with ssp_amount=0 in staff_ledger ───────────────────────
// ADV-* entries with currency=SSP but ssp_amount=0 — balance() reads ssp_amount
// so these advances were invisible in the SSP BAG calculation.
//
// Usage:
//   Dry run: ?page=api&action=fix_ssp_advance_amounts
//   Apply:   ?page=api&action=fix_ssp_advance_amounts&dry_run=0
if ($act === 'fix_ssp_advance_amounts') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo    = $store->getPdo();
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $fixed  = []; $errors = [];

    try {
        $rows = $pdo->query(
            "SELECT id, staff_id, staff_name, amount, ssp_amount, idempotency_key, event_date, description
             FROM staff_ledger
             WHERE currency = 'SSP'
               AND category = 'advance'
               AND direction = 'in'
               AND (ssp_amount IS NULL OR ssp_amount = 0)
               AND amount > 0
               AND status NOT IN ('voided','cancelled')"
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $fixedSsp = (float)$row['amount'];
            if (!$dryRun) {
                try {
                    $pdo->prepare("UPDATE staff_ledger SET ssp_amount = ? WHERE id = ?")
                        ->execute([$fixedSsp, $row['id']]);
                    $fixed[] = ['ledger_id' => $row['id'], 'key' => $row['idempotency_key'],
                                'staff' => $row['staff_name'], 'ssp_amount' => $fixedSsp, 'date' => $row['event_date']];
                } catch (\Throwable $e) {
                    $errors[] = ['ledger_id' => $row['id'], 'error' => $e->getMessage()];
                }
            } else {
                $fixed[] = ['ledger_id' => $row['id'], 'key' => $row['idempotency_key'],
                            'staff' => $row['staff_name'], 'ssp_amount' => $fixedSsp, 'date' => $row['event_date'], 'dry_run' => true];
            }
        }
    } catch (\Throwable $e) {
        $er2('Query failed: ' . $e->getMessage(), 500);
    }

    $ok2([
        'dry_run'     => $dryRun,
        'fixed'       => $fixed,
        'fixed_count' => count($fixed),
        'errors'      => $errors,
        'message'     => $dryRun
            ? count($fixed) . ' SSP advance entries have ssp_amount=0 and would be fixed. Add &dry_run=0 to apply.'
            : count($fixed) . ' SSP advance entries fixed. ' . count($errors) . ' errors.',
    ]);
}

// ── Debug: show all staff_ledger rows for a staff member ─────────────────────
// ?page=api&action=ledger_debug&staff_id=17
if ($act === 'ledger_debug') {
    if (!$isAdmin) $er2('Admin only', 403);
    $sid = (int)($_GET['staff_id'] ?? 0);
    if (!$sid) $er2('staff_id required', 422);
    $pdo  = $store->getPdo();
    $rows = $pdo->prepare(
        "SELECT id, direction, currency, amount, ssp_amount, category, subcategory,
                idempotency_key, description, event_date, status
         FROM staff_ledger WHERE staff_id = ? ORDER BY id ASC"
    );
    $rows->execute([$sid]);
    $data = $rows->fetchAll(\PDO::FETCH_ASSOC);
    // Also show balance breakdown
    $bal = $pdo->prepare(
        "SELECT currency,
                SUM(CASE WHEN direction='in' THEN ssp_amount ELSE 0 END) as ssp_in,
                SUM(CASE WHEN direction='out' THEN ssp_amount ELSE 0 END) as ssp_out,
                SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) as usd_in,
                SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) as usd_out,
                COUNT(*) as cnt
         FROM staff_ledger WHERE staff_id = ? AND status NOT IN ('voided','cancelled')
         GROUP BY currency"
    );
    $bal->execute([$sid]);
    $ok2(['rows' => $data, 'total_rows' => count($data), 'balance_summary' => $bal->fetchAll(\PDO::FETCH_ASSOC)]);
}

// ── Backfill SSP transfers that were silently dropped (ssp_transfer_in/out ───
// missing from categoryDirection map — every onSSPTransfer call was silently
// rejected. Fix: added categories to map + backfill from cb_ledger.
//
// Usage:
//   Dry run: ?page=api&action=backfill_ssp_transfers
//   Apply:   ?page=api&action=backfill_ssp_transfers&dry_run=0
if ($act === 'backfill_ssp_transfers') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo    = $store->getPdo();
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $fixed  = []; $skipped = []; $errors = [];

    // Find all SSP transfer cb_ledger entries to replay
    $cbs = $pdo->query(
        "SELECT * FROM cb_ledger
         WHERE source = 'ssp_transfer'
           AND currency = 'SSP'
           AND status = 'approved'
         ORDER BY id ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Build transfer pairs keyed by base ref (strip -IN / -OUT suffix)
    $pairs = [];
    foreach ($cbs as $cb) {
        $ref = $cb['validation_ref'] ?? '';
        // Strip -IN / -OUT suffix
        $baseRef = preg_replace('/-(IN|OUT)$/', '', $ref);
        if (!$baseRef) continue;
        $side = str_ends_with($ref, '-IN') ? 'in' : 'out';
        $pairs[$baseRef][$side] = $cb;
    }

    $retailers = $store->load('retailers.json') ?? [];
    $nameMap   = [];
    foreach ($retailers as $r) $nameMap[(int)$r['id']] = $r['name'] ?? '';

    foreach ($pairs as $baseRef => $sides) {
        if (empty($sides['out']) || empty($sides['in'])) {
            $skipped[] = ['ref' => $baseRef, 'reason' => 'incomplete pair'];
            continue;
        }

        $outCb = $sides['out'];
        $inCb  = $sides['in'];
        $sspAmt = round((float)($outCb['ssp_amount'] ?? 0), 0);
        if ($sspAmt <= 0) { $skipped[] = ['ref' => $baseRef, 'reason' => 'zero ssp_amount']; continue; }

        // Check if already in staff_ledger (idempotent)
        $outKey = 'SSPTRFOUT-' . $baseRef;
        $inKey  = 'SSPTRFIN-'  . $baseRef;
        $exists = (int)$pdo->prepare("SELECT COUNT(*) FROM staff_ledger WHERE idempotency_key IN (?,?)")
            ->execute([$outKey, $inKey])
            ? $pdo->query("SELECT COUNT(*) FROM staff_ledger WHERE idempotency_key IN ('$outKey','$inKey')")->fetchColumn()
            : 0;
        $chk = $pdo->prepare("SELECT COUNT(*) FROM staff_ledger WHERE idempotency_key IN (?,?)");
        $chk->execute([$outKey, $inKey]);
        $exists = (int)$chk->fetchColumn();

        if ($exists > 0) { $skipped[] = ['ref' => $baseRef, 'reason' => 'already in ledger']; continue; }

        // Resolve staff IDs from person names
        $toName   = trim($outCb['person'] ?? '');
        $fromName = trim($outCb['description'] ?? '');
        // Extract from_name from description: "SSP given to Diko — 30000 SSP [from Rupesh]"
        preg_match('/\[from ([^\]]+)\]/', $outCb['description'] ?? '', $fromMatch);
        $fromNameClean = $fromMatch[1] ?? '';
        $toId = 0; $fromId = 0;
        foreach ($retailers as $r) {
            if (!$toId && stripos($r['name'] ?? '', $toName) !== false) $toId = (int)$r['id'];
            if (!$fromId && $fromNameClean && stripos($r['name'] ?? '', $fromNameClean) !== false) $fromId = (int)$r['id'];
        }

        $eventDate = substr($outCb['date'] ?? date('Y-m-d'), 0, 10);
        $rate      = round((float)($outCb['ssp_rate'] ?? 0), 4);

        if (!$dryRun && $toId > 0) {
            try {
                require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                StaffLedgerWriter::onSSPTransfer($pdo, [
                    'transfer_ref' => $baseRef,
                    'from_id'      => $fromId,
                    'from_name'    => $fromNameClean ?: 'Accounts',
                    'to_id'        => $toId,
                    'to_name'      => $toName,
                    'ssp_amount'   => $sspAmt,
                    'ssp_rate'     => $rate,
                    'description'  => $outCb['description'] ?? $baseRef,
                    'event_date'   => $eventDate,
                ]);
                $fixed[] = ['ref' => $baseRef, 'to' => $toName, 'from' => $fromNameClean, 'ssp' => $sspAmt, 'date' => $eventDate];
            } catch (\Throwable $e) {
                $errors[] = ['ref' => $baseRef, 'error' => $e->getMessage()];
            }
        } else {
            $fixed[] = ['ref' => $baseRef, 'to' => $toName, 'from' => $fromNameClean, 'ssp' => $sspAmt, 'date' => $eventDate, 'to_id' => $toId, 'dry_run' => true];
        }
    }

    $ok2([
        'dry_run'       => $dryRun,
        'fixed'         => $fixed,
        'fixed_count'   => count($fixed),
        'skipped'       => $skipped,
        'skipped_count' => count($skipped),
        'errors'        => $errors,
        'message'       => $dryRun
            ? count($fixed) . ' SSP transfers would be backfilled. Add &dry_run=0 to apply.'
            : count($fixed) . ' SSP transfers backfilled into staff_ledger. ' . count($errors) . ' errors.',
    ]);
}

// ── Debug: compare cash_ins.json vs staff_ledger for a staff member ──────────
// ?page=api&action=cashin_ledger_diff&staff_id=17
if ($act === 'cashin_ledger_diff') {
    if (!$isAdmin) $er2('Admin only', 403);
    $sid  = (int)($_GET['staff_id'] ?? 0);
    if (!$sid) $er2('staff_id required', 422);
    $pdo  = $store->getPdo();

    // What's in cash_ins.json for this staff
    $allCins = $store->load('cash_ins.json') ?? [];
    $myins   = array_values(array_filter($allCins, fn($i) =>
        (int)($i['collector_id'] ?? 0) === $sid &&
        !in_array($i['status'] ?? 'approved', ['rejected'])
    ));

    // What CIN-* keys exist in staff_ledger
    $cinKeys = $pdo->prepare("SELECT idempotency_key FROM staff_ledger WHERE staff_id = ? AND idempotency_key LIKE 'CIN-%'");
    $cinKeys->execute([$sid]);
    $existing = array_flip($cinKeys->fetchAll(\PDO::FETCH_COLUMN));

    $missing = [];
    foreach ($myins as $ci) {
        $key = 'CIN-' . ($ci['id'] ?? '?');
        if (!isset($existing[$key])) {
            $missing[] = [
                'id'         => $ci['id'] ?? '?',
                'key'        => $key,
                'category'   => $ci['category'] ?? '',
                'currency'   => $ci['currency'] ?? 'USD',
                'amount'     => $ci['amount'] ?? 0,
                'ssp_amount' => $ci['ssp_amount'] ?? 0,
                'description'=> substr($ci['description'] ?? '', 0, 60),
                'status'     => $ci['status'] ?? '',
                'created_at' => $ci['created_at'] ?? '',
            ];
        }
    }

    // Also show all staff_ledger rows for this staff
    $allLedger = $pdo->prepare("SELECT idempotency_key, direction, currency, amount, ssp_amount, category, status FROM staff_ledger WHERE staff_id = ? ORDER BY id ASC");
    $allLedger->execute([$sid]);

    $ok2([
        'staff_id'          => $sid,
        'cash_ins_total'    => count($myins),
        'ledger_cin_keys'   => count($existing),
        'missing_from_ledger' => $missing,
        'missing_count'     => count($missing),
        'all_ledger_rows'   => $allLedger->fetchAll(\PDO::FETCH_ASSOC),
    ]);
}

// ── Targeted fix for Bidal SSP BAG (staff_id=17) ─────────────────────────────
// Problem: 4 cash_ins missing from staff_ledger + FEXP entries double-counting
// expenses already recorded as EXP entries.
//
// Usage:
//   Dry run: ?page=api&action=fix_bidal_ssp_bag
//   Apply:   ?page=api&action=fix_bidal_ssp_bag&dry_run=0
if ($act === 'fix_bidal_ssp_bag') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo    = $store->getPdo();
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $log    = [];

    // ── Step 1: Backfill missing CIN entries with ssp_amount > 0 ─────────────
    $allCins  = $store->load('cash_ins.json') ?? [];
    $existKey = [];
    foreach ($pdo->query("SELECT idempotency_key FROM staff_ledger WHERE staff_id=17")->fetchAll(\PDO::FETCH_COLUMN) as $k) {
        $existKey[$k] = true;
    }

    $cinAdded = [];
    foreach ($allCins as $ci) {
        if ((int)($ci['collector_id'] ?? 0) !== 17) continue;
        $key = 'CIN-' . ($ci['id'] ?? '');
        if (isset($existKey[$key])) continue; // already in ledger
        $ssp = (float)($ci['ssp_amount'] ?? 0);
        if ($ssp <= 0) continue; // CIN-33 and CIN-34 have ssp_amount=0 — skip
        if (in_array($ci['status'] ?? 'approved', ['rejected'])) continue;

        $isVoided = in_array($ci['status'] ?? 'approved', ['voided']);
        $status   = $isVoided ? 'voided' : 'active';

        if (!$dryRun) {
            try {
                $pdo->prepare("INSERT OR IGNORE INTO staff_ledger
                    (staff_id, staff_name, direction, currency, amount, ssp_amount,
                     category, description, status, source_type, source_id,
                     idempotency_key, event_date, created_at)
                    VALUES (17,'Bidal DishNet','in','SSP',0,?,
                     'collection',?,?,
                     'cash_ins',?,
                     ?,?,datetime('now'))")
                ->execute([
                    $ssp,
                    substr($ci['description'] ?? '', 0, 200),
                    $status,
                    (string)($ci['id'] ?? ''),
                    $key,
                    substr($ci['created_at'] ?? date('Y-m-d'), 0, 10),
                ]);
                $cinAdded[] = ['key' => $key, 'ssp' => $ssp, 'desc' => substr($ci['description']??'',0,50), 'status' => $status];
            } catch (\Throwable $e) {
                $log[] = ['step' => 'cin_backfill', 'key' => $key, 'error' => $e->getMessage()];
            }
        } else {
            $cinAdded[] = ['key' => $key, 'ssp' => $ssp, 'desc' => substr($ci['description']??'',0,50), 'status' => $status, 'dry_run' => true];
        }
    }

    // ── Step 2: Void FEXP entries that duplicate EXP entries ─────────────────
    // EXP-11 (fuel 30k) duplicates FEXP-22 (30k) and/or FEXP-29 (30k)
    // EXP-12 (wall clips 15k) duplicates FEXP-15 (15k)
    // Strategy: check if total OUT in staff_ledger > total OUT in cash_expenses.json
    // Safest approach: void FEXP-* entries that have the same ssp_amount as an EXP-* entry
    // Void ALL FEXP entries for Bidal — investigation showed:
    // FEXP-15: duplicate of EXP-12 (wall clips 15k)
    // FEXP-22: duplicate of EXP-11 (fuel 30k)
    // FEXP-17: belongs to Emmanuel (staff_id=15), written to Bidal's ledger in error
    // FEXP-29: source id=29 does not exist in staff_expenses — phantom entry
    $fexpRows = $pdo->query(
        "SELECT id, idempotency_key, ssp_amount, description, status
         FROM staff_ledger
         WHERE staff_id=17 AND idempotency_key LIKE 'FEXP-%'
           AND direction='out' AND currency='SSP' AND status='active'"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $fexpVoided = [];
    foreach ($fexpRows as $fexp) {
        $famt = (float)($fexp['ssp_amount'] ?? 0);
        if (!$dryRun) {
            try {
                $pdo->prepare("UPDATE staff_ledger SET status='voided', description=description||' [voided: erroneous/duplicate FEXP entry]' WHERE id=?")
                    ->execute([$fexp['id']]);
                $fexpVoided[] = ['key' => $fexp['idempotency_key'], 'ssp' => $famt, 'action' => 'voided'];
            } catch (\Throwable $e) {
                $log[] = ['step' => 'fexp_void', 'key' => $fexp['idempotency_key'], 'error' => $e->getMessage()];
            }
        } else {
            $fexpVoided[] = ['key' => $fexp['idempotency_key'], 'ssp' => $famt, 'action' => 'would_void'];
        }
    }

    // ── Compute expected balance after fix ────────────────────────────────────
    // IN: 76k existing active + 30k CIN-70 + 292.5k CIN-71 = 398,500
    // OUT: EXP-11 (30k) + EXP-12 (15k) = 45,000  (all FEXPs voided)
    // Balance: 398,500 - 45,000 = 353,500
    $expectedIn  = 398500;
    $voidedSsp   = array_sum(array_column($fexpVoided, 'ssp'));
    $expectedOut = 121000 - $voidedSsp;
    $expectedBal = $expectedIn - $expectedOut;

    $ok2([
        'dry_run'         => $dryRun,
        'cin_backfilled'  => $cinAdded,
        'cin_count'       => count($cinAdded),
        'fexp_voided'     => $fexpVoided,
        'fexp_count'      => count($fexpVoided),
        'errors'          => $log,
        'projected_balance_ssp' => $expectedBal,
        'target_balance_ssp'    => 353500,
        'message' => $dryRun
            ? 'DRY RUN — ' . count($cinAdded) . ' CIN entries would be added, ' . count($fexpVoided) . ' FEXP duplicates would be voided. Projected SSP balance: ' . $expectedBal
            : 'APPLIED — ' . count($cinAdded) . ' CIN entries added, ' . count($fexpVoided) . ' FEXP duplicates voided.',
    ]);
}

// ── Inspect specific FEXP entries in staff_expenses SQLite ───────────────────
// ?page=api&action=fexp_inspect&ids=17,29
if ($act === 'fexp_inspect') {
    if (!$isAdmin) $er2('Admin only', 403);
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
    if (empty($ids)) $er2('ids required', 422);
    $pdo = $store->getPdo();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = $pdo->prepare("SELECT * FROM staff_expenses WHERE id IN ($placeholders)")->execute($ids)
        ? $pdo->prepare("SELECT * FROM staff_expenses WHERE id IN ($placeholders)")
        : null;
    if (!$rows) $er2('Query failed', 500);
    $rows->execute($ids);
    $expenses = $rows->fetchAll(\PDO::FETCH_ASSOC);
    // Also check cash_expenses.json for any same-amount SSP entries for staff_id=17
    $allExps = array_filter($store->load('cash_expenses.json') ?? [],
        fn($e) => (int)($e['collector_id']??0) === 17 && strtoupper($e['currency']??'USD') === 'SSP'
    );
    $ok2(['staff_expenses_rows' => $expenses, 'cash_expenses_ssp' => array_values($allExps)]);
}

// ── Fix: Bidal bad USD expense EXP-202604-008 ($30,000 USD fuel entry) ───────
// This was 2 litres of fuel mistakenly entered as $30,000 USD instead of SSP.
// Dry run: ?page=api&action=fix_bidal_usd_fuel
// Apply:   ?page=api&action=fix_bidal_usd_fuel&dry_run=0
if ($act === 'fix_bidal_usd_fuel') {
    if (!$isAdmin) $er2('Admin only', 403);
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $pdo    = $store->getPdo();

    // Find in staff_ledger — category='field_expense', staff_id=17, amount=30000 USD
    $rows = $pdo->prepare(
        "SELECT * FROM staff_ledger
         WHERE staff_id = 17
           AND category = 'field_expense'
           AND currency = 'USD'
           AND amount = 30000
           AND (status IS NULL OR status != 'voided')
         ORDER BY created_at DESC LIMIT 5"
    )->execute() ? $pdo->prepare(
        "SELECT * FROM staff_ledger
         WHERE staff_id = 17
           AND category = 'field_expense'
           AND currency = 'USD'
           AND amount = 30000
           AND (status IS NULL OR status != 'voided')
         ORDER BY created_at DESC LIMIT 5"
    ) : null;
    if (!$rows) $er2('Query failed', 500);
    $rows->execute();
    $found = $rows->fetchAll(\PDO::FETCH_ASSOC);

    // Also check cash_expenses.json
    $allExps = $store->load('cash_expenses.json') ?? [];
    $expMatches = array_values(array_filter($allExps, function($e) {
        return (int)($e['collector_id'] ?? 0) === 17
            && strtoupper($e['currency'] ?? 'USD') === 'USD'
            && abs((float)($e['amount'] ?? 0) - 30000) < 0.01
            && ($e['voided'] ?? false) !== true;
    }));

    $voided = [];
    $jsonVoided = [];

    if (!$dryRun) {
        // Void in staff_ledger
        foreach ($found as $row) {
            $pdo->prepare(
                "UPDATE staff_ledger SET status='voided',
                 description = description || ' [VOIDED: erroneous USD amount — was SSP fuel expense]'
                 WHERE id = ?"
            )->execute([$row['id']]);
            $voided[] = ['id' => $row['id'], 'key' => $row['idempotency_key'], 'action' => 'voided'];
        }
        // Void in cash_expenses.json
        $changed = false;
        foreach ($allExps as &$e) {
            foreach ($expMatches as $m) {
                if (($e['id'] ?? null) === ($m['id'] ?? null)) {
                    $e['voided']     = true;
                    $e['voided_by']  = 'admin_fix';
                    $e['voided_at']  = date('Y-m-d H:i:s');
                    $e['voided_note']= 'Erroneous USD amount — fuel expense should be SSP, not $30,000 USD';
                    $jsonVoided[]    = $e['id'];
                    $changed         = true;
                }
            }
        }
        unset($e);
        if ($changed) $store->save('cash_expenses.json', $allExps);
    } else {
        foreach ($found as $row) {
            $voided[] = ['id' => $row['id'], 'key' => $row['idempotency_key'], 'action' => 'would_void'];
        }
        foreach ($expMatches as $m) {
            $jsonVoided[] = $m['id'] . ' (would void)';
        }
    }

    $ok2([
        'dry_run'               => $dryRun,
        'staff_ledger_found'    => count($found),
        'staff_ledger_rows'     => $found,
        'cash_expenses_found'   => count($expMatches),
        'cash_expenses_matches' => $expMatches,
        'voided_ledger'         => $voided,
        'voided_json'           => $jsonVoided,
        'message' => $dryRun
            ? 'DRY RUN — found ' . count($found) . ' staff_ledger rows + ' . count($expMatches) . ' cash_expenses.json entries'
            : 'APPLIED — voided ' . count($voided) . ' ledger rows, ' . count($jsonVoided) . ' JSON entries',
    ]);
}

// ── Debug: Bidal SSP BAG vs cashbook discrepancy ─────────────────────────────
// BAG shows 218,500 SSP but cashbook shows 188,500 — difference 30,000
// ?page=api&action=debug_bidal_ssp_diff
if ($act === 'debug_bidal_ssp_diff') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo = $store->getPdo();

    // All non-voided SSP staff_ledger rows for Bidal (staff_id=17)
    $rows = $pdo->query(
        "SELECT id, idempotency_key, direction, category, ssp_amount, amount, currency,
                description, status, created_at
         FROM staff_ledger
         WHERE staff_id = 17
           AND (ssp_amount > 0 OR currency = 'SSP')
           AND (status IS NULL OR status != 'voided')
         ORDER BY created_at ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $totalIn  = 0;
    $totalOut = 0;
    foreach ($rows as $r) {
        $amt = (float)($r['ssp_amount'] > 0 ? $r['ssp_amount'] : $r['amount']);
        if ($r['direction'] === 'in')  $totalIn  += $amt;
        if ($r['direction'] === 'out') $totalOut += $amt;
    }
    $ledgerBal = $totalIn - $totalOut;

    // cash_ins.json — SSP entries for Bidal
    $allCashIns = $store->load('cash_ins.json') ?? [];
    $sspCashIns = array_values(array_filter($allCashIns, fn($c) =>
        (int)($c['collector_id'] ?? 0) === 17
        && strtoupper($c['currency'] ?? 'USD') === 'SSP'
        && ($c['voided'] ?? false) !== true
    ));
    $cashInTotal = array_sum(array_column($sspCashIns, 'ssp_amount'));

    // cash_expenses.json — SSP entries for Bidal
    $allExps = $store->load('cash_expenses.json') ?? [];
    $sspExps = array_values(array_filter($allExps, fn($e) =>
        (int)($e['collector_id'] ?? 0) === 17
        && strtoupper($e['currency'] ?? 'USD') === 'SSP'
        && ($e['voided'] ?? false) !== true
    ));
    $expTotal = array_sum(array_column($sspExps, 'amount'));

    $ok2([
        'staff_ledger_rows'    => $rows,
        'ledger_ssp_in'        => $totalIn,
        'ledger_ssp_out'       => $totalOut,
        'ledger_balance'       => $ledgerBal,
        'cash_ins_ssp'         => $sspCashIns,
        'cash_ins_total'       => $cashInTotal,
        'cash_expenses_ssp'    => $sspExps,
        'cash_expenses_total'  => $expTotal,
        'json_balance'         => $cashInTotal - $expTotal,
        'bag_shows'            => 218500,
        'cashbook_shows'       => 188500,
        'difference'           => 30000,
        'note' => 'BAG reads from cash_ins.json, cashbook reads from staff_ledger. If ledger_balance=188500 and json_balance=218500, one of the 30k SSP advances is in cash_ins but missing from staff_ledger.',
    ]);
}

// ── Fix: Void duplicate SSP fuel expense EXP-202604-008 for Bidal ────────────
// "fuel — 2.0 ltrs of Fuel" was entered as -30,000 SSP on 10 Apr 09:28
// AND as $30,000 USD (bad currency). The SSP entry is a duplicate of the
// correct pending expense. The USD entry is a wrong-currency error.
// Dry run: ?page=api&action=fix_bidal_fuel_dup
// Apply:   ?page=api&action=fix_bidal_fuel_dup&dry_run=0
if ($act === 'fix_bidal_fuel_dup') {
    if (!$isAdmin) $er2('Admin only', 403);
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $pdo    = $store->getPdo();
    $log    = [];

    // 1. Find the bad SSP fuel expense in staff_ledger (30,000 SSP OUT, staff_id=17)
    $sspRows = $pdo->query(
        "SELECT * FROM staff_ledger
         WHERE staff_id = 17
           AND direction = 'out'
           AND (ssp_amount = 30000 OR (currency='SSP' AND amount=30000))
           AND (description LIKE '%fuel%' OR description LIKE '%Fuel%' OR description LIKE '%202604-008%')
           AND (status IS NULL OR status NOT IN ('voided'))
           AND created_at >= '2026-04-08'
         ORDER BY created_at DESC LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // 2. Find the bad USD fuel expense in staff_ledger ($30,000 USD OUT, staff_id=17)
    $usdRows = $pdo->query(
        "SELECT * FROM staff_ledger
         WHERE staff_id = 17
           AND direction = 'out'
           AND currency = 'USD'
           AND amount = 30000
           AND (description LIKE '%fuel%' OR description LIKE '%Fuel%' OR description LIKE '%202604-008%')
           AND (status IS NULL OR status NOT IN ('voided'))
           AND created_at >= '2026-04-08'
         ORDER BY created_at DESC LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $voidedSSP = [];
    $voidedUSD = [];

    if (!$dryRun) {
        foreach ($sspRows as $r) {
            $pdo->prepare(
                "UPDATE staff_ledger
                 SET status='voided',
                     description = description || ' [VOIDED: duplicate — correct expense pending approval separately]'
                 WHERE id = ?"
            )->execute([$r['id']]);
            $voidedSSP[] = $r['id'];
            $log[] = 'Voided SSP ledger id=' . $r['id'];
        }
        foreach ($usdRows as $r) {
            $pdo->prepare(
                "UPDATE staff_ledger
                 SET status='voided',
                     description = description || ' [VOIDED: wrong currency — fuel expense is SSP not USD]'
                 WHERE id = ?"
            )->execute([$r['id']]);
            $voidedUSD[] = $r['id'];
            $log[] = 'Voided USD ledger id=' . $r['id'];
        }

        // Also check cash_expenses.json for the bad $30k USD entry
        $allExps  = $store->load('cash_expenses.json') ?? [];
        $changed  = false;
        $jsonFixed = [];
        foreach ($allExps as &$e) {
            if ((int)($e['collector_id'] ?? 0) === 17
                && strtoupper($e['currency'] ?? '') === 'USD'
                && abs((float)($e['amount'] ?? 0) - 30000) < 0.01
                && ($e['voided'] ?? false) !== true
                && stripos($e['description'] ?? $e['category'] ?? '', 'fuel') !== false) {
                $e['voided']      = true;
                $e['voided_note'] = 'Wrong currency — fuel is SSP not USD $30,000';
                $e['voided_at']   = date('Y-m-d H:i:s');
                $jsonFixed[]      = $e['id'] ?? 'unknown';
                $changed          = true;
            }
        }
        unset($e);
        if ($changed) $store->save('cash_expenses.json', $allExps);
        $log[] = 'JSON cash_expenses fixed: ' . implode(',', $jsonFixed);
    }

    // Compute corrected SSP balance after fix
    $balRow = $pdo->query(
        "SELECT
           SUM(CASE WHEN direction='in' AND (status IS NULL OR status NOT IN ('voided')) THEN COALESCE(ssp_amount,0) ELSE 0 END) as total_in,
           SUM(CASE WHEN direction='out' AND (status IS NULL OR status NOT IN ('voided')) THEN COALESCE(ssp_amount,0) ELSE 0 END) as total_out
         FROM staff_ledger WHERE staff_id = 17"
    )->fetch(\PDO::FETCH_ASSOC);
    $projectedBal = (float)($balRow['total_in'] ?? 0) - (float)($balRow['total_out'] ?? 0);

    $ok2([
        'dry_run'         => $dryRun,
        'ssp_rows_found'  => $sspRows,
        'usd_rows_found'  => $usdRows,
        'voided_ssp_ids'  => $voidedSSP,
        'voided_usd_ids'  => $voidedUSD,
        'log'             => $log,
        'projected_ssp_balance' => $projectedBal,
        'message' => $dryRun
            ? 'DRY RUN — ' . count($sspRows) . ' SSP rows + ' . count($usdRows) . ' USD rows would be voided'
            : 'APPLIED — ' . count($voidedSSP) . ' SSP + ' . count($voidedUSD) . ' USD ledger rows voided. New balance: ' . $projectedBal . ' SSP',
    ]);
}

// ── Find exact keys for Bidal bad entries (debug) ────────────────────────────
// ?page=api&action=bidal_bad_entries
if ($act === 'bidal_bad_entries') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo = $store->getPdo();

    // All USD entries for Bidal staff_id=17 not voided
    $usd = $pdo->query(
        "SELECT id, idempotency_key, direction, category, amount, currency, description, status, created_at
         FROM staff_ledger
         WHERE staff_id = 17 AND currency = 'USD'
           AND status NOT IN ('voided','cancelled')
         ORDER BY created_at DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // All SSP OUT entries for Bidal not voided — to find the duplicate
    $sspOut = $pdo->query(
        "SELECT id, idempotency_key, direction, category, ssp_amount, amount, currency, description, status, created_at
         FROM staff_ledger
         WHERE staff_id = 17
           AND direction = 'out'
           AND status NOT IN ('voided','cancelled')
           AND ssp_amount > 0
         ORDER BY created_at DESC LIMIT 20"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $ok2(['usd_entries' => $usd, 'ssp_out_entries' => $sspOut]);
}

// ── Find exactly where the -$30,000 USD is in the hero calculation ────────────
// Checks both cash_expenses.json AND staff_expenses SQLite for Bidal USD entries
// ?page=api&action=find_bidal_usd_source
if ($act === 'find_bidal_usd_source') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo = $store->getPdo();

    // 1. cash_expenses.json — USD entries for collector_id=17, not voided
    $allExps = $store->load('cash_expenses.json') ?? [];
    $jsonUsd = array_values(array_filter($allExps, function($e) {
        return (int)($e['collector_id'] ?? 0) === 17
            && strtoupper($e['currency'] ?? 'USD') === 'USD'
            && in_array($e['status'] ?? '', ['approved','pending',''])
            && ($e['voided'] ?? false) !== true;
    }));

    // 2. staff_expenses SQLite — USD for staff_id=17
    $sqliteUsd = $pdo->query(
        "SELECT id, staff_id, amount, currency, category, description, status, expense_date, created_at
         FROM staff_expenses
         WHERE staff_id = 17 AND currency = 'USD'
           AND status IN ('approved','pending')
         ORDER BY created_at DESC"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // 3. cash_ins.json — USD Received for collector_id=17
    $allCashIns = $store->load('cash_ins.json') ?? [];
    $jsonUsdIn = array_values(array_filter($allCashIns, function($i) {
        return (int)($i['collector_id'] ?? 0) === 17
            && ($i['category'] ?? '') === 'USD Received'
            && !in_array($i['status'] ?? 'approved', ['rejected','voided']);
    }));

    // Calculate what the hero should show
    $expTotal  = array_sum(array_column($jsonUsd, 'amount'));
    $sqliteTotal = array_sum(array_column($sqliteUsd, 'amount'));
    $inTotal   = array_sum(array_column($jsonUsdIn, 'amount'));

    $ok2([
        'cash_expenses_json_usd'   => $jsonUsd,
        'cash_expenses_json_total' => $expTotal,
        'staff_expenses_sqlite_usd'   => $sqliteUsd,
        'staff_expenses_sqlite_total' => $sqliteTotal,
        'cash_ins_usd_received'    => $jsonUsdIn,
        'cash_ins_total'           => $inTotal,
        'hero_usd_in'              => $inTotal,
        'hero_usd_exp'             => $expTotal + $sqliteTotal,
        'hero_usd_balance_without_collections' => $inTotal - $expTotal - $sqliteTotal,
        'note' => 'Hero also adds COL-* collections from staff_ledger on top. Total USD in = cash_ins_total + collections($360). Total exp = cash_expenses_json + staff_expenses_sqlite.',
    ]);
}

// ── Void the bad USD entry once found — provide id and source ─────────────────
// ?page=api&action=void_bidal_usd_exp&source=json&id=ENTRY_ID
// ?page=api&action=void_bidal_usd_exp&source=sqlite&id=ROW_ID
// Always dry_run=1 first, add &dry_run=0 to apply
if ($act === 'void_bidal_usd_exp') {
    if (!$isAdmin) $er2('Admin only', 403);
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $source = $_GET['source'] ?? '';
    $entryId = $_GET['id'] ?? '';
    if (!$source || !$entryId) $er2('source and id required', 422);

    $pdo = $store->getPdo();
    $result = [];

    if ($source === 'json') {
        $allExps = $store->load('cash_expenses.json') ?? [];
        $found = null;
        foreach ($allExps as &$e) {
            if ((string)($e['id'] ?? '') === (string)$entryId
                || (string)($e['ref'] ?? '') === (string)$entryId) {
                $found = $e;
                if (!$dryRun) {
                    $e['voided']      = true;
                    $e['voided_note'] = 'Erroneous USD expense — fuel is SSP not USD';
                    $e['voided_at']   = date('Y-m-d H:i:s');
                    $e['voided_by']   = 'admin_fix';
                }
                break;
            }
        }
        unset($e);
        if (!$found) $er2('Entry not found in cash_expenses.json with id=' . $entryId, 404);
        if (!$dryRun) $store->save('cash_expenses.json', $allExps);
        $result = ['source'=>'cash_expenses.json','found'=>$found,'action'=>$dryRun?'would_void':'voided'];

    } elseif ($source === 'sqlite') {
        $row = $pdo->prepare("SELECT * FROM staff_expenses WHERE id=? AND staff_id=17")
                   ->execute([(int)$entryId])
            ? $pdo->prepare("SELECT * FROM staff_expenses WHERE id=? AND staff_id=17")
            : null;
        if (!$row) $er2('Query failed',500);
        $row->execute([(int)$entryId]);
        $found = $row->fetch(\PDO::FETCH_ASSOC);
        if (!$found) $er2('Entry not found in staff_expenses with id=' . $entryId, 404);
        if (!$dryRun) {
            $pdo->prepare("UPDATE staff_expenses SET status='voided' WHERE id=?")->execute([(int)$entryId]);
        }
        $result = ['source'=>'staff_expenses','found'=>$found,'action'=>$dryRun?'would_void':'voided'];
    } else {
        $er2('source must be json or sqlite', 422);
    }

    $ok2(['dry_run'=>$dryRun,'result'=>$result,
          'message'=>$dryRun ? 'DRY RUN only' : 'Entry voided — reload Bidal cashbook to confirm $0.00']);
}

// ── Fix: Correct EXP-202604-008 currency USD→SSP ─────────────────────────────
// Bidal submitted fuel expense 30,000 but selected USD instead of SSP.
// Correct: currency=SSP, ssp_amount=30000, amount=USD equivalent.
// Dry run: ?page=api&action=fix_exp_currency&exp_ref=EXP-202604-008
// Apply:   ?page=api&action=fix_exp_currency&exp_ref=EXP-202604-008&dry_run=0
if ($act === 'fix_exp_currency') {
    if (!$isAdmin) $er2('Admin only', 403);
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';
    $expRef = $_GET['exp_ref'] ?? 'EXP-202604-008';
    $pdo    = $store->getPdo();

    // Get current SSP rate
    $sspRate = 5850.0;
    try {
        require_once dirname(__DIR__, 2) . '/lib/CashbookService.php';
        $sspRate = (new \CashbookService($store, $dataDir ?? ''))->getExchangeRate() ?: 5850.0;
    } catch (\Throwable $e) {}

    // Find in staff_expenses by reference number
    // EXP-202604-008 → id might be stored as expense_ref or we search by staff_id + date + amount
    $rows = $pdo->query(
        "SELECT * FROM staff_expenses
         WHERE staff_id = 17
           AND currency = 'USD'
           AND amount = 30000
           AND status != 'voided'
           AND expense_date >= '2026-04-07'
         ORDER BY created_at DESC LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    // Also check by idempotency key pattern
    $byKey = $pdo->query(
        "SELECT * FROM staff_ledger
         WHERE staff_id = 17
           AND idempotency_key LIKE 'EXP-%'
           AND currency = 'USD'
           AND amount = 30000
           AND status != 'voided'
         ORDER BY created_at DESC LIMIT 5"
    )->fetchAll(\PDO::FETCH_ASSOC);

    $usdEquiv = round(30000 / $sspRate, 2);
    $fixed = [];

    if (!$dryRun) {
        // Fix staff_expenses rows
        foreach ($rows as $r) {
            $pdo->prepare(
                "UPDATE staff_expenses
                 SET currency = 'SSP',
                     ssp_amount = 30000,
                     amount = ?,
                     description = COALESCE(description,'') || ' [currency corrected USD→SSP by admin]'
                 WHERE id = ?"
            )->execute([$usdEquiv, $r['id']]);
            $fixed[] = ['source' => 'staff_expenses', 'id' => $r['id'], 'action' => 'corrected USD→SSP'];
        }
        // Fix staff_ledger rows
        foreach ($byKey as $r) {
            $pdo->prepare(
                "UPDATE staff_ledger
                 SET currency = 'SSP',
                     ssp_amount = 30000,
                     amount = ?,
                     description = description || ' [currency corrected USD→SSP by admin]'
                 WHERE id = ?"
            )->execute([$usdEquiv, $r['id']]);
            $fixed[] = ['source' => 'staff_ledger', 'id' => $r['id'], 'key' => $r['idempotency_key'], 'action' => 'corrected USD→SSP'];
        }
    }

    $ok2([
        'dry_run'             => $dryRun,
        'ssp_rate_used'       => $sspRate,
        'usd_equivalent'      => $usdEquiv,
        'staff_expenses_found'=> $rows,
        'staff_ledger_found'  => $byKey,
        'fixed'               => $fixed,
        'message' => $dryRun
            ? 'DRY RUN — found ' . count($rows) . ' staff_expenses + ' . count($byKey) . ' ledger rows. Would set currency=SSP, ssp_amount=30000, amount=' . $usdEquiv . ' USD'
            : 'APPLIED — corrected ' . count($fixed) . ' rows from USD to SSP',
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// v4.12.8 — Generic Staff Ledger Audit (READ-ONLY)
// ══════════════════════════════════════════════════════════════════════════════
// Purpose: diagnose SSP/USD bag discrepancies like Aida's 1,300,000 vs actual 333,000.
// Compares staff_ledger rows against JSON sources (cash_ins, cash_expenses,
// cash_handovers) + staff_expenses table + cash_advances table.
//
// Usage:
//   ?page=api&action=staff_ledger_audit&staff_id=23&currency=SSP
//   ?page=api&action=staff_ledger_audit&staff_id=23&currency=SSP&html=1  (human view)
//
// Returns per-row reconciliation:
//   - "matched": ledger row has a corresponding JSON/table source row
//   - "phantom_in": ledger IN with no source (shouldn't be counted as bag IN)
//   - "phantom_out": ledger OUT with no source (shouldn't reduce bag)
//   - "missing_in": source shows IN but no ledger row (bag understated)
//   - "missing_out": source shows OUT but no ledger row (bag overstated)
//
// Admin only. Never modifies data. Safe to run anytime.
if ($act === 'staff_ledger_audit') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo     = $store->getPdo();
    $staffId = (int)($_GET['staff_id'] ?? 0);
    $currency= strtoupper($_GET['currency'] ?? 'SSP');
    $asHtml  = !empty($_GET['html']);

    if ($staffId <= 0) $er2('staff_id required', 400);
    if (!in_array($currency, ['SSP','USD'], true)) $er2('currency must be SSP or USD', 400);

    // ── Staff name ──────────────────────────────────────────────────────────
    $staffName = '';
    foreach ($store->load('retailers.json') ?? [] as $r) {
        if ((int)($r['id'] ?? 0) === $staffId) { $staffName = $r['name'] ?? ''; break; }
    }

    // ── 1. ALL staff_ledger rows for this staff/currency ────────────────────
    $amtCol = $currency === 'SSP' ? 'ssp_amount' : 'amount';
    $ledgerRows = $pdo->prepare(
        "SELECT id, idempotency_key, direction, category, subcategory, amount, ssp_amount,
                source_type, source_id, description, status, event_date, created_at
           FROM staff_ledger
          WHERE staff_id = ? AND currency = ?
          ORDER BY COALESCE(event_date, substr(created_at,1,10)) ASC, id ASC"
    );
    $ledgerRows->execute([$staffId, $currency]);
    $ledgerRows = $ledgerRows->fetchAll(\PDO::FETCH_ASSOC);

    $ledgerByKey = [];
    $ledgerTotalIn = 0; $ledgerTotalOut = 0;
    $ledgerActiveIn = 0; $ledgerActiveOut = 0;
    foreach ($ledgerRows as $r) {
        $amt = (float)($r[$amtCol] ?? 0);
        $key = (string)($r['idempotency_key'] ?? '');
        if ($key !== '') $ledgerByKey[$key] = $r;
        if ($r['direction'] === 'in')  $ledgerTotalIn  += $amt;
        if ($r['direction'] === 'out') $ledgerTotalOut += $amt;
        if (($r['status'] ?? '') !== 'voided' && ($r['status'] ?? '') !== 'cancelled') {
            if ($r['direction'] === 'in')  $ledgerActiveIn  += $amt;
            if ($r['direction'] === 'out') $ledgerActiveOut += $amt;
        }
    }

    // ── 2. JSON source: cash_ins.json (advances received / exchange) ────────
    $cins = array_filter($store->load('cash_ins.json') ?: [],
        fn($i) => (int)($i['collector_id'] ?? 0) === $staffId);
    $srcRows = [];
    foreach ($cins as $ci) {
        $cur = ($ci['category'] ?? '') === 'Exchange' ? 'SSP' : strtoupper($ci['currency'] ?? 'SSP');
        if ($cur !== $currency) continue;
        if (in_array($ci['status'] ?? 'approved', ['rejected','voided'])) continue;
        $amt = $currency === 'SSP'
            ? (float)($ci['ssp_amount'] ?? 0)
            : (float)($ci['amount'] ?? 0);
        if ($amt <= 0) continue;
        $srcRows[] = [
            'source'       => 'cash_ins.json',
            'source_id'    => (string)($ci['id'] ?? ''),
            'expected_key' => 'CIN-' . ($ci['id'] ?? ''),
            'direction'    => 'in',
            'amount'       => $amt,
            'category'     => $ci['category'] ?? 'SSP Received',
            'description'  => substr($ci['description'] ?? '', 0, 120),
            'date'         => substr($ci['created_at'] ?? '', 0, 10),
            'status'       => $ci['status'] ?? 'approved',
        ];
    }

    // ── 3. JSON source: cash_expenses.json (field expenses, free-form) ──────
    $fexps = array_filter($store->load('cash_expenses.json') ?: [],
        fn($e) => (int)($e['collector_id'] ?? 0) === $staffId);
    foreach ($fexps as $e) {
        $cur = strtoupper($e['currency'] ?? 'USD');
        if ($cur !== $currency) continue;
        if (in_array($e['status'] ?? '', ['voided','cancelled','rejected','pending'])) continue;
        $amt = $currency === 'SSP'
            ? (float)($e['ssp_amount'] ?? $e['amount'] ?? 0)
            : (float)($e['amount'] ?? 0);
        if ($amt <= 0) continue;
        $srcRows[] = [
            'source'       => 'cash_expenses.json',
            'source_id'    => (string)($e['id'] ?? ''),
            'expected_key' => 'FEXP-' . ($e['id'] ?? ''),
            'direction'    => 'out',
            'amount'       => $amt,
            'category'     => $e['category'] ?? 'Expense',
            'description'  => substr($e['description'] ?? '', 0, 120),
            'date'         => substr($e['submitted_at'] ?? $e['created_at'] ?? '', 0, 10),
            'status'       => $e['status'] ?? 'approved',
        ];
    }

    // ── 4. staff_expenses table (advance-linked, EXP-XXX) ───────────────────
    try {
        $seStmt = $pdo->prepare(
            "SELECT id, amount, ssp_amount, currency, category, description,
                    expense_date, submitted_at, status
               FROM staff_expenses
              WHERE staff_id = ? AND status = 'approved'"
        );
        $seStmt->execute([$staffId]);
        foreach ($seStmt->fetchAll(\PDO::FETCH_ASSOC) as $se) {
            $cur = strtoupper($se['currency'] ?? 'USD');
            if ($cur !== $currency) continue;
            $amt = $currency === 'SSP'
                ? (float)($se['ssp_amount'] ?? 0) ?: (float)($se['amount'] ?? 0)
                : (float)($se['amount'] ?? 0);
            if ($amt <= 0) continue;
            $srcRows[] = [
                'source'       => 'staff_expenses',
                'source_id'    => (string)($se['id'] ?? ''),
                'expected_key' => 'EXP-' . ($se['id'] ?? ''),
                'direction'    => 'out',
                'amount'       => $amt,
                'category'     => $se['category'] ?? 'Advance Expense',
                'description'  => substr($se['description'] ?? '', 0, 120),
                'date'         => $se['expense_date'] ?? substr($se['submitted_at'] ?? '', 0, 10),
                'status'       => $se['status'] ?? 'approved',
            ];
        }
    } catch (\Throwable $e) { /* table may not exist */ }

    // ── 5. cash_handovers.json (OUT — cash returned to office) ──────────────
    $hovs = array_filter($store->load('cash_handovers.json') ?: [],
        fn($h) => (int)($h['from_id'] ?? 0) === $staffId);
    foreach ($hovs as $h) {
        if (($h['status'] ?? '') !== 'confirmed') continue;
        $hSsp  = (float)($h['ssp_amount'] ?? 0);
        $hCur  = strtoupper($h['currency'] ?? '');
        $isSSP = ($hCur === 'SSP') || ($hSsp > 0 && $hCur !== 'USD');
        $cur   = $isSSP ? 'SSP' : 'USD';
        if ($cur !== $currency) continue;
        $amt = $currency === 'SSP'
            ? ($hSsp > 0 ? $hSsp : (float)($h['amount'] ?? 0))
            : (float)($h['amount'] ?? 0);
        if ($amt <= 0) continue;
        $srcRows[] = [
            'source'       => 'cash_handovers.json',
            'source_id'    => (string)($h['id'] ?? ''),
            'expected_key' => 'HOV-' . ($h['id'] ?? ''),
            'direction'    => 'out',
            'amount'       => $amt,
            'category'     => 'Handover',
            'description'  => 'To ' . ($h['to_name'] ?? 'Rupesh'),
            'date'         => substr($h['confirmed_at'] ?? $h['created_at'] ?? '', 0, 10),
            'status'       => $h['status'] ?? 'confirmed',
        ];
    }

    // ── 6. cash_advances table (IN — advances received from issuer) ─────────
    try {
        $caStmt = $pdo->prepare(
            "SELECT id, advance_no, amount, currency, purpose, description,
                    status, issued_at
               FROM cash_advances
              WHERE recipient_id = ? AND status IN ('active','partial','settled')
                AND (parent_advance_id IS NULL OR parent_advance_id = 0)"
        );
        $caStmt->execute([$staffId]);
        foreach ($caStmt->fetchAll(\PDO::FETCH_ASSOC) as $ca) {
            $cur = strtoupper($ca['currency'] ?? 'USD');
            if ($cur !== $currency) continue;
            $amt = (float)($ca['amount'] ?? 0);
            if ($amt <= 0) continue;
            $srcRows[] = [
                'source'       => 'cash_advances',
                'source_id'    => (string)($ca['id'] ?? ''),
                'expected_key' => 'ADV-' . ($ca['id'] ?? ''),
                'direction'    => 'in',
                'amount'       => $amt,
                'category'     => 'Advance',
                'description'  => ($ca['advance_no'] ?? '') . ' — ' . ($ca['purpose'] ?? ''),
                'date'         => substr($ca['issued_at'] ?? '', 0, 10),
                'status'       => $ca['status'] ?? 'active',
            ];
        }
    } catch (\Throwable $e) { /* table may not exist */ }

    // ── 7. Reconciliation — row by row ──────────────────────────────────────
    $sourceByKey = [];
    foreach ($srcRows as $s) {
        $sourceByKey[$s['expected_key']] = $s;
    }

    $reconciled = [];
    $phantoms   = []; // ledger rows with no matching source
    $missing    = []; // source rows with no ledger row
    $srcTotalIn = 0; $srcTotalOut = 0;

    foreach ($srcRows as $s) {
        if ($s['direction'] === 'in')  $srcTotalIn  += $s['amount'];
        if ($s['direction'] === 'out') $srcTotalOut += $s['amount'];

        $key = $s['expected_key'];
        if (isset($ledgerByKey[$key])) {
            $l = $ledgerByKey[$key];
            $lAmt = (float)($l[$amtCol] ?? 0);
            $status = 'matched';
            $note = '';
            if (abs($lAmt - $s['amount']) > 0.5) {
                $status = 'amount_mismatch';
                $note = "ledger={$lAmt}, source={$s['amount']}";
            }
            if ($l['direction'] !== $s['direction']) {
                $status = 'direction_mismatch';
                $note  .= " ledger_dir={$l['direction']}, src_dir={$s['direction']}";
            }
            if (($l['status'] ?? '') === 'voided') {
                $status = 'voided_ledger';
            }
            $reconciled[] = [
                'status'      => $status,
                'key'         => $key,
                'date'        => $s['date'],
                'direction'   => $s['direction'],
                'source'      => $s['source'],
                'amount_src'  => $s['amount'],
                'amount_led'  => $lAmt,
                'description' => $s['description'],
                'note'        => $note,
            ];
        } else {
            $missing[] = [
                'key'         => $key,
                'date'        => $s['date'],
                'direction'   => $s['direction'],
                'source'      => $s['source'],
                'amount'      => $s['amount'],
                'description' => $s['description'],
            ];
        }
    }

    foreach ($ledgerRows as $l) {
        $key = (string)($l['idempotency_key'] ?? '');
        if ($key === '') {
            $phantoms[] = [
                'key'         => '(no idempotency key)',
                'ledger_id'   => (int)$l['id'],
                'date'        => $l['event_date'] ?? substr($l['created_at'] ?? '', 0, 10),
                'direction'   => $l['direction'],
                'amount'      => (float)($l[$amtCol] ?? 0),
                'description' => substr($l['description'] ?? '', 0, 120),
                'source_type' => $l['source_type'] ?? '',
                'status'      => $l['status'] ?? 'active',
            ];
            continue;
        }
        if (!isset($sourceByKey[$key])) {
            $phantoms[] = [
                'key'         => $key,
                'ledger_id'   => (int)$l['id'],
                'date'        => $l['event_date'] ?? substr($l['created_at'] ?? '', 0, 10),
                'direction'   => $l['direction'],
                'amount'      => (float)($l[$amtCol] ?? 0),
                'description' => substr($l['description'] ?? '', 0, 120),
                'source_type' => $l['source_type'] ?? '',
                'status'      => $l['status'] ?? 'active',
            ];
        }
    }

    // ── Summary ─────────────────────────────────────────────────────────────
    $summary = [
        'staff_id'                => $staffId,
        'staff_name'              => $staffName,
        'currency'                => $currency,
        'ledger_rows_total'       => count($ledgerRows),
        'ledger_in_all'           => round($ledgerTotalIn, 2),
        'ledger_out_all'          => round($ledgerTotalOut, 2),
        'ledger_in_active'        => round($ledgerActiveIn, 2),
        'ledger_out_active'       => round($ledgerActiveOut, 2),
        'ledger_balance_active'   => round($ledgerActiveIn - $ledgerActiveOut, 2),
        'source_rows_total'       => count($srcRows),
        'source_in'               => round($srcTotalIn, 2),
        'source_out'              => round($srcTotalOut, 2),
        'source_balance'          => round($srcTotalIn - $srcTotalOut, 2),
        'discrepancy'             => round(($ledgerActiveIn - $ledgerActiveOut) - ($srcTotalIn - $srcTotalOut), 2),
        'matched_count'           => count(array_filter($reconciled, fn($r) => $r['status'] === 'matched')),
        'amount_mismatches'       => count(array_filter($reconciled, fn($r) => $r['status'] === 'amount_mismatch')),
        'phantoms_count'          => count($phantoms),
        'phantoms_in_total'       => round(array_sum(array_map(
            fn($p) => $p['direction']==='in'  && ($p['status']??'')!=='voided' ? $p['amount'] : 0, $phantoms)), 2),
        'phantoms_out_total'      => round(array_sum(array_map(
            fn($p) => $p['direction']==='out' && ($p['status']??'')!=='voided' ? $p['amount'] : 0, $phantoms)), 2),
        'missing_count'           => count($missing),
        'missing_in_total'        => round(array_sum(array_map(
            fn($m) => $m['direction']==='in'  ? $m['amount'] : 0, $missing)), 2),
        'missing_out_total'       => round(array_sum(array_map(
            fn($m) => $m['direction']==='out' ? $m['amount'] : 0, $missing)), 2),
    ];

    // ── HTML rendering (if ?html=1) ─────────────────────────────────────────
    if ($asHtml) {
        header('Content-Type: text/html; charset=UTF-8');
        $h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $fmt = function($n) { return number_format((float)$n, 2); };
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Staff Ledger Audit — ' . $h($staffName) . '</title>';
        echo '<style>body{font-family:-apple-system,sans-serif;max-width:1400px;margin:20px auto;padding:0 16px;color:#0f172a}';
        echo 'h1{font-size:22px;margin:0 0 6px}h2{font-size:16px;margin:24px 0 8px;padding-bottom:6px;border-bottom:2px solid #e5e7eb}';
        echo 'table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px}';
        echo 'th,td{padding:6px 8px;text-align:left;border-bottom:1px solid #e5e7eb;vertical-align:top}';
        echo 'th{background:#f1f5f9;font-weight:700;color:#475569;font-size:10px;text-transform:uppercase;letter-spacing:.5px}';
        echo 'td.n{text-align:right;font-variant-numeric:tabular-nums;font-family:Menlo,monospace}';
        echo '.pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}';
        echo '.pill-ok{background:#dcfce7;color:#166534}.pill-warn{background:#fef3c7;color:#92400e}';
        echo '.pill-bad{background:#fee2e2;color:#b91c1c}.pill-gray{background:#f1f5f9;color:#64748b}';
        echo '.kpi{display:inline-block;background:#f8fafc;padding:8px 12px;margin:2px;border-radius:8px;font-size:12px}';
        echo '.kpi strong{display:block;font-size:20px;font-family:Menlo,monospace}';
        echo '.diff-neg{color:#b91c1c}.diff-pos{color:#16a34a}</style></head><body>';

        echo '<h1>Staff Ledger Audit — ' . $h($staffName) . ' (staff_id=' . $staffId . ')</h1>';
        echo '<div style="color:#64748b;font-size:13px">Currency: <strong>' . $currency . '</strong> · Generated: ' . date('Y-m-d H:i:s') . '</div>';

        $dClass = abs($summary['discrepancy']) < 0.5 ? 'diff-pos' : 'diff-neg';
        echo '<h2>Summary</h2>';
        echo '<div class="kpi">Ledger balance <strong>' . $fmt($summary['ledger_balance_active']) . '</strong></div>';
        echo '<div class="kpi">Source balance <strong>' . $fmt($summary['source_balance']) . '</strong></div>';
        echo '<div class="kpi">Discrepancy <strong class="' . $dClass . '">' . $fmt($summary['discrepancy']) . '</strong></div>';
        echo '<div class="kpi">Phantoms (ledger→no source) <strong class="diff-neg">+' . $fmt($summary['phantoms_in_total']) . ' / -' . $fmt($summary['phantoms_out_total']) . '</strong></div>';
        echo '<div class="kpi">Missing (source→no ledger) <strong class="diff-neg">+' . $fmt($summary['missing_in_total']) . ' / -' . $fmt($summary['missing_out_total']) . '</strong></div>';
        echo '<div class="kpi">Matched <strong>' . $summary['matched_count'] . '</strong></div>';
        echo '<div class="kpi">Mismatches <strong>' . $summary['amount_mismatches'] . '</strong></div>';

        // Phantom rows (most important — these are over-counting the bag)
        if (!empty($phantoms)) {
            echo '<h2>🚨 Phantom ledger rows — in ledger but no source</h2>';
            echo '<p style="font-size:12px;color:#64748b">These rows are inflating the bag. They need to be voided if confirmed phantom.</p>';
            echo '<table><thead><tr><th>Key</th><th>Date</th><th>Dir</th><th>Amount</th><th>Source Type</th><th>Status</th><th>Description</th></tr></thead><tbody>';
            foreach ($phantoms as $p) {
                $pClass = ($p['status'] ?? '') === 'voided' ? 'pill-gray' : ($p['direction']==='in' ? 'pill-bad' : 'pill-warn');
                echo '<tr>';
                echo '<td><code>' . $h($p['key']) . '</code> <span style="color:#94a3b8">#' . (int)$p['ledger_id'] . '</span></td>';
                echo '<td>' . $h($p['date']) . '</td>';
                echo '<td><span class="pill ' . $pClass . '">' . strtoupper($h($p['direction'])) . '</span></td>';
                echo '<td class="n">' . $fmt($p['amount']) . '</td>';
                echo '<td>' . $h($p['source_type']) . '</td>';
                echo '<td>' . $h($p['status']) . '</td>';
                echo '<td>' . $h($p['description']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Missing rows
        if (!empty($missing)) {
            echo '<h2>⚠️ Missing ledger rows — in source but not in ledger</h2>';
            echo '<p style="font-size:12px;color:#64748b">These source rows never made it to staff_ledger. Bag is understated by IN rows / overstated by missing OUT rows.</p>';
            echo '<table><thead><tr><th>Expected Key</th><th>Date</th><th>Dir</th><th>Amount</th><th>Source</th><th>Description</th></tr></thead><tbody>';
            foreach ($missing as $m) {
                $pClass = $m['direction']==='in' ? 'pill-ok' : 'pill-warn';
                echo '<tr>';
                echo '<td><code>' . $h($m['key']) . '</code></td>';
                echo '<td>' . $h($m['date']) . '</td>';
                echo '<td><span class="pill ' . $pClass . '">' . strtoupper($h($m['direction'])) . '</span></td>';
                echo '<td class="n">' . $fmt($m['amount']) . '</td>';
                echo '<td>' . $h($m['source']) . '</td>';
                echo '<td>' . $h($m['description']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Matched rows with amount mismatches
        $mismatches = array_filter($reconciled, fn($r) => $r['status'] === 'amount_mismatch' || $r['status'] === 'direction_mismatch');
        if (!empty($mismatches)) {
            echo '<h2>❗ Amount or direction mismatches</h2>';
            echo '<table><thead><tr><th>Key</th><th>Date</th><th>Dir</th><th>Source Amt</th><th>Ledger Amt</th><th>Note</th><th>Description</th></tr></thead><tbody>';
            foreach ($mismatches as $m) {
                echo '<tr>';
                echo '<td><code>' . $h($m['key']) . '</code></td>';
                echo '<td>' . $h($m['date']) . '</td>';
                echo '<td>' . strtoupper($h($m['direction'])) . '</td>';
                echo '<td class="n">' . $fmt($m['amount_src']) . '</td>';
                echo '<td class="n">' . $fmt($m['amount_led']) . '</td>';
                echo '<td>' . $h($m['note']) . '</td>';
                echo '<td>' . $h($m['description']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Full ledger dump (for reference)
        echo '<h2>Full ledger rows (' . count($ledgerRows) . ')</h2>';
        echo '<table><thead><tr><th>ID</th><th>Key</th><th>Date</th><th>Dir</th><th>Cat</th><th>Amount</th><th>Status</th><th>Description</th></tr></thead><tbody>';
        foreach ($ledgerRows as $l) {
            $amt = (float)($l[$amtCol] ?? 0);
            $pClass = ($l['status'] ?? '') === 'voided' ? 'pill-gray' : ($l['direction']==='in' ? 'pill-ok' : 'pill-warn');
            echo '<tr>';
            echo '<td>' . (int)$l['id'] . '</td>';
            echo '<td><code>' . $h($l['idempotency_key']) . '</code></td>';
            echo '<td>' . $h($l['event_date'] ?? substr($l['created_at'] ?? '', 0, 10)) . '</td>';
            echo '<td><span class="pill ' . $pClass . '">' . strtoupper($h($l['direction'])) . '</span></td>';
            echo '<td>' . $h($l['category']) . ($l['subcategory']?' / '.$h($l['subcategory']):'') . '</td>';
            echo '<td class="n">' . $fmt($amt) . '</td>';
            echo '<td>' . $h($l['status']) . '</td>';
            echo '<td>' . $h(substr($l['description'] ?? '', 0, 80)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '</body></html>';
        exit;
    }

    // JSON response
    $ok2([
        'summary'     => $summary,
        'phantoms'    => $phantoms,
        'missing'     => $missing,
        'reconciled'  => $reconciled,
        'ledger_rows' => $ledgerRows,
        'source_rows' => $srcRows,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// v4.12.9 — Generic backfill for missing expense ledger rows
// ══════════════════════════════════════════════════════════════════════════════
// Complements staff_ledger_audit. Finds all approved source expenses (both
// cash_expenses.json field expenses and staff_expenses table advance expenses)
// that have no corresponding row in staff_ledger, and backfills them using the
// proper StaffLedgerWriter hooks.
//
// Root cause: the auto-approve code path in ExpenseAdvanceService::submitExpense
// (used by field_accountant role) writes the expense row with status='approved'
// but does NOT call StaffLedgerWriter::onExpenseApproved. Only the manual
// approveExpense() path calls the hook. This causes Aida and anyone with
// auto-approve privilege to accumulate ghost OUTs that never debit their bag.
//
// This action backfills retroactively. A proper code fix (calling the hook from
// submitExpense when autoApprove=true) should be done separately.
//
// Usage:
//   Dry run:  ?page=api&action=backfill_missing_expense_ledger_rows
//   Dry run for one staff: &staff_id=23
//   Apply:    &dry_run=0
//   Currency: &currency=SSP or USD (default both)
//
// Admin only. Idempotent (INSERT OR IGNORE on idempotency_key).
if ($act === 'backfill_missing_expense_ledger_rows') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo        = $store->getPdo();
    $dryRun     = ($_GET['dry_run'] ?? '1') !== '0';
    $filterSid  = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
    $filterCur  = strtoupper($_GET['currency'] ?? '');
    if ($filterCur && !in_array($filterCur, ['SSP','USD'], true)) $er2('currency must be SSP or USD', 400);

    require_once dirname(__DIR__, 1) . '/../lib/StaffLedgerWriter.php';

    // ── Build set of existing ledger idempotency keys ───────────────────────
    $keyStmt = $pdo->query("SELECT idempotency_key FROM staff_ledger WHERE idempotency_key IS NOT NULL AND idempotency_key != ''");
    $existingKeys = [];
    foreach ($keyStmt->fetchAll(\PDO::FETCH_COLUMN) as $k) $existingKeys[$k] = true;

    $results = [
        'dry_run'       => $dryRun,
        'scanned'       => 0,
        'already_posted'=> 0,
        'backfilled'    => 0,
        'would_backfill'=> 0,
        'errors'        => [],
        'by_staff'      => [],
        'actions'       => [],
    ];

    // ── Scan cash_expenses.json (field expenses → FEXP-{id}) ────────────────
    foreach ($store->load('cash_expenses.json') ?: [] as $fe) {
        if (($fe['status'] ?? '') !== 'approved') continue;
        $sid = (int)($fe['collector_id'] ?? 0);
        if ($sid <= 0) continue;
        if ($filterSid && $sid !== $filterSid) continue;
        $cur = strtoupper($fe['currency'] ?? 'USD');
        if ($filterCur && $cur !== $filterCur) continue;

        $results['scanned']++;
        $key = 'FEXP-' . ($fe['id'] ?? '');
        if (isset($existingKeys[$key])) { $results['already_posted']++; continue; }

        // Build expense payload for the hook
        $sspAmt = (float)($fe['ssp_amount'] ?? 0);
        $usdAmt = (float)($fe['amount'] ?? 0);
        // For SSP expenses ssp_amount is primary; amount may hold USD equiv or be zero
        $amt    = $cur === 'SSP' ? ($sspAmt > 0 ? $sspAmt : $usdAmt) : $usdAmt;
        if ($amt <= 0) continue;

        $expPayload = [
            'id'           => (int)($fe['id'] ?? 0),
            'staff_id'     => $sid,
            'staff_name'   => $fe['collector_name'] ?? '',
            'collector_id' => $sid,
            'collector_name'=> $fe['collector_name'] ?? '',
            'amount'       => $cur === 'SSP' ? $amt : $usdAmt,
            'ssp_amount'   => $cur === 'SSP' ? $amt : 0,
            'currency'     => $cur,
            'category'     => $fe['category'] ?? 'Expense',
            'description'  => $fe['description'] ?? '',
            'expense_date' => substr($fe['submitted_at'] ?? $fe['created_at'] ?? date('Y-m-d'), 0, 10),
            'submitted_at' => $fe['submitted_at'] ?? $fe['created_at'] ?? date('Y-m-d H:i:s'),
            'created_at'   => $fe['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        $action = [
            'key'         => $key,
            'staff_id'    => $sid,
            'source'      => 'cash_expenses.json',
            'source_id'   => (int)($fe['id'] ?? 0),
            'currency'    => $cur,
            'amount'      => $amt,
            'description' => substr($fe['description'] ?? '', 0, 80),
        ];

        if ($dryRun) {
            $action['action'] = 'would_backfill';
            $results['would_backfill']++;
        } else {
            try {
                StaffLedgerWriter::onExpenseApproved($pdo, $expPayload, 'cash_expenses');
                $action['action'] = 'backfilled';
                $results['backfilled']++;
                $existingKeys[$key] = true;
            } catch (\Throwable $e) {
                $action['action'] = 'error';
                $action['error']  = $e->getMessage();
                $results['errors'][] = $action;
            }
        }
        $results['actions'][] = $action;
        if (!isset($results['by_staff'][$sid])) $results['by_staff'][$sid] = ['count'=>0,'amount_ssp'=>0,'amount_usd'=>0];
        $results['by_staff'][$sid]['count']++;
        if ($cur === 'SSP') $results['by_staff'][$sid]['amount_ssp'] += $amt;
        else                $results['by_staff'][$sid]['amount_usd'] += $amt;
    }

    // ── Scan staff_expenses table (advance expenses → EXP-{id}) ─────────────
    try {
        $seSql = "SELECT id, staff_id, staff_name, amount, ssp_amount, currency, category,
                         description, expense_date, submitted_at, created_at
                    FROM staff_expenses WHERE status = 'approved'";
        $seParams = [];
        if ($filterSid) { $seSql .= ' AND staff_id = ?'; $seParams[] = $filterSid; }
        if ($filterCur) { $seSql .= ' AND currency = ?'; $seParams[] = $filterCur; }
        $seStmt = $pdo->prepare($seSql);
        $seStmt->execute($seParams);
        foreach ($seStmt->fetchAll(\PDO::FETCH_ASSOC) as $se) {
            $sid = (int)$se['staff_id'];
            if ($sid <= 0) continue;
            $cur = strtoupper($se['currency'] ?? 'USD');
            $results['scanned']++;
            $key = 'EXP-' . $se['id'];
            if (isset($existingKeys[$key])) { $results['already_posted']++; continue; }

            $sspAmt = (float)($se['ssp_amount'] ?? 0);
            $usdAmt = (float)($se['amount'] ?? 0);
            $amt    = $cur === 'SSP' ? ($sspAmt > 0 ? $sspAmt : $usdAmt) : $usdAmt;
            if ($amt <= 0) continue;

            $expPayload = [
                'id'           => (int)$se['id'],
                'staff_id'     => $sid,
                'staff_name'   => $se['staff_name'] ?? '',
                'amount'       => $cur === 'SSP' ? $amt : $usdAmt,
                'ssp_amount'   => $cur === 'SSP' ? $amt : 0,
                'currency'     => $cur,
                'category'     => $se['category'] ?? 'Advance Expense',
                'description'  => $se['description'] ?? '',
                'expense_date' => $se['expense_date'] ?: substr($se['submitted_at'] ?? date('Y-m-d'), 0, 10),
                'submitted_at' => $se['submitted_at'] ?? date('Y-m-d H:i:s'),
                'created_at'   => $se['created_at'] ?? date('Y-m-d H:i:s'),
            ];

            $action = [
                'key'         => $key,
                'staff_id'    => $sid,
                'source'      => 'staff_expenses',
                'source_id'   => (int)$se['id'],
                'currency'    => $cur,
                'amount'      => $amt,
                'description' => substr($se['description'] ?? '', 0, 80),
            ];

            if ($dryRun) {
                $action['action'] = 'would_backfill';
                $results['would_backfill']++;
            } else {
                try {
                    StaffLedgerWriter::onExpenseApproved($pdo, $expPayload, 'staff_expenses');
                    $action['action'] = 'backfilled';
                    $results['backfilled']++;
                    $existingKeys[$key] = true;
                } catch (\Throwable $e) {
                    $action['action'] = 'error';
                    $action['error']  = $e->getMessage();
                    $results['errors'][] = $action;
                }
            }
            $results['actions'][] = $action;
            if (!isset($results['by_staff'][$sid])) $results['by_staff'][$sid] = ['count'=>0,'amount_ssp'=>0,'amount_usd'=>0];
            $results['by_staff'][$sid]['count']++;
            if ($cur === 'SSP') $results['by_staff'][$sid]['amount_ssp'] += $amt;
            else                $results['by_staff'][$sid]['amount_usd'] += $amt;
        }
    } catch (\Throwable $e) {
        $results['errors'][] = ['source'=>'staff_expenses','error'=>$e->getMessage()];
    }

    $results['message'] = $dryRun
        ? sprintf('DRY RUN — scanned %d, already in ledger %d, would backfill %d across %d staff. Re-run with &dry_run=0 to apply.',
            $results['scanned'], $results['already_posted'], $results['would_backfill'], count($results['by_staff']))
        : sprintf('APPLIED — scanned %d, already in ledger %d, backfilled %d across %d staff, errors %d',
            $results['scanned'], $results['already_posted'], $results['backfilled'], count($results['by_staff']), count($results['errors']));

    $ok2($results);
}

// ══════════════════════════════════════════════════════════════════════════════
// v4.12.9 — Void FEXP-27 phantom for Aida (staff_id=23)
// ══════════════════════════════════════════════════════════════════════════════
// One-off fix. FEXP-27 (60,000 SSP, "Phone charger [staff_id corrected: 9→23]")
// is an orphaned ledger OUT — the source cash_expenses.json entry with id=27
// exists under a different staff_id (9) and does not belong to Aida. The
// "correction" added a ledger row under Aida but never removed/updated the
// source, leaving a phantom OUT that under-counts her bag.
//
// Usage:
//   Dry run: ?page=api&action=void_aida_fexp27_phantom
//   Apply:   &dry_run=0
if ($act === 'void_aida_fexp27_phantom') {
    if (!$isAdmin) $er2('Admin only', 403);
    $pdo    = $store->getPdo();
    $dryRun = ($_GET['dry_run'] ?? '1') !== '0';

    // Find the specific phantom row
    $stmt = $pdo->prepare(
        "SELECT id, idempotency_key, staff_id, direction, ssp_amount, description, status
           FROM staff_ledger
          WHERE staff_id = 23 AND idempotency_key = 'FEXP-27' AND status = 'active'"
    );
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        $ok2([
            'dry_run' => $dryRun,
            'found'   => false,
            'message' => 'FEXP-27 phantom not found for Aida (staff_id=23) in active state. Nothing to do.',
        ]);
    }

    if ($dryRun) {
        $ok2([
            'dry_run' => true,
            'found'   => true,
            'row'     => $row,
            'message' => 'DRY RUN — would void ledger row id=' . $row['id'] . ' (FEXP-27, 60,000 SSP phantom). Re-run with &dry_run=0 to apply.',
        ]);
    }

    try {
        $pdo->prepare(
            "UPDATE staff_ledger
                SET status = 'voided',
                    description = description || ' [voided v4.12.9: phantom — source cash_expenses.json id=27 belongs to staff_id=9, not 23]'
              WHERE id = ?"
        )->execute([$row['id']]);
        $ok2([
            'dry_run'       => false,
            'found'         => true,
            'voided_id'     => (int)$row['id'],
            'amount_voided' => (float)$row['ssp_amount'],
            'message'       => 'APPLIED — voided FEXP-27 phantom (60,000 SSP). Aida\'s SSP bag will now reflect the correct 333,000 after running backfill.',
        ]);
    } catch (\Throwable $e) {
        $er2('Failed to void FEXP-27: ' . $e->getMessage(), 500);
    }
}
