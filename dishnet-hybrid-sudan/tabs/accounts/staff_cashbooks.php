<?php
// ── Staff Cashbooks — v4.9.17 — Matches Accountant Cashbook Design ──────────
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

$isAcctMgr = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);
if (!$isAcctMgr) { echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>'; return; }

require_once __DIR__ . '/../../lib/DualReadCashPosition.php';
require_once __DIR__ . '/../../lib/CashbookService.php';
require_once __DIR__ . '/../../lib/WalletService.php';
$cpSvc = new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? '');
$walSvc = new WalletService($store);
try { $cbSvc = new CashbookService($store, $dataDir); $sysRate = $cbSvc->getExchangeRate() ?: 5700; } catch (\Throwable $e) { $sysRate = 5700; }
// Last actual market rate from cash_ins.json (may differ from system rate per person/changer)
$_scExcCtx  = isset($cbSvc) ? $cbSvc->getLastExchangeContext($store->load('cash_ins.json') ?: []) : [];
$_scLastRate = (int)($_scExcCtx['last_rate'] ?? 0);
$_scPrefill  = $_scLastRate > 0 ? $_scLastRate : $sysRate; // pre-fill with last real rate

// Early declaration — $selId is set properly below after allStaff is loaded
$selId    = (int)($_GET['sc_staff'] ?? 0);
$pdo      = $store->getPdo();
$allStaff = $store->load('retailers.json') ?? [];

// Duplicate-guard: find field_exchange cb_ledger entries for this staff member
// recorded by the plugin in the last 48h — warn Rupesh not to re-enter them manually.
$_scAutoExchanges = [];
if ($selId > 0) {
    try {
        $_aeStmt = $pdo->prepare(
            "SELECT id, date, amount, ssp_amount, ssp_rate, description, created_at
             FROM cb_ledger
             WHERE source='field_exchange' AND direction='out' AND currency='USD'
               AND person=? AND created_at >= datetime('now','-48 hours')
             ORDER BY created_at DESC LIMIT 10"
        );
        // Match by staff name (person column)
        $_selStaffName = '';
        foreach ($allStaff as $_st) {
            if ((int)($_st['id'] ?? 0) === $selId) { $_selStaffName = $_st['name'] ?? ''; break; }
        }
        if ($_selStaffName) {
            $_aeStmt->execute([$_selStaffName]);
            $_scAutoExchanges = $_aeStmt->fetchAll(\PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $_ae) {}
}

$allStaff = $store->load('retailers.json') ?? [];
$fieldStaff = array_values(array_filter($allStaff, fn($r) => in_array($r['role'] ?? '', ['field_accountant','sales','sales_staff','field_agent','collection','support_leader','support']) && !empty($r['is_active'])));
$selId = (int)($_GET['sc_staff'] ?? 0);
$selStaff = null;
if ($selId) { foreach ($allStaff as $s) { if ((int)($s['id']??0)===$selId) { $selStaff=$s; break; } } }

// CSV export is handled by includes/routes.php (runs before HTML output)

// POST actions
$scMsg=''; $scOk=null;
$_scAllExps=$store->load('cash_expenses.json')?:[];

// Phase 3 dual-write helper: sync JSON change to unified SQLite
$_scSyncToSqlite = function(int $jsonId, array $updates) use ($store) {
    try {
        $pdo = $store->getPdo();
        $pdo->query("SELECT source FROM staff_expenses LIMIT 0"); // check migration applied
        $sets = []; $vals = [];
        foreach ($updates as $k => $v) { $sets[] = "{$k} = ?"; $vals[] = $v; }
        $sets[] = "updated_at = ?"; $vals[] = date('Y-m-d H:i:s');
        $vals[] = $jsonId;
        $pdo->prepare("UPDATE staff_expenses SET " . implode(', ', $sets) . " WHERE legacy_json_id = ? AND source = 'field'")->execute($vals);
    } catch (\Throwable $e) { /* migration not yet applied */ }
};

if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['sc_action']) && csrfCheck()) {
    $scAction=$_POST['sc_action']; $scExpId=(int)($_POST['expense_id']??0);
    $expenses=$_scAllExps;

    // v4.11.3: TIG pre-void warning — show cascade impact before proceeding
    if ($scAction === 'void_entry' && $scExpId > 0) {
        require_once dirname(__DIR__,2).'/lib/TransactionIntegrityGuard.php';
        $_voidTarget = null;
        foreach ($expenses as $_vte) { if ((int)($_vte['id']??0)===$scExpId) { $_voidTarget=$_vte; break; } }
        if ($_voidTarget) {
            $_tigWarnIssues = TransactionIntegrityGuard::preSave('void_expense', $_voidTarget, $store, $pdo);
            foreach ($_tigWarnIssues as $_wi) {
                // Void warnings never block — but get shown as a flash after action
                $scMsg .= ' ⚠️ ' . ($_wi['msg'] ?? '');
            }
        }
    }
    if ($scAction==='void_entry'&&$scExpId) { foreach($expenses as &$ex){ if((int)($ex['id']??0)!==$scExpId)continue; if(($ex['status']??'')==='voided'){$scMsg='Already voided.';$scOk=false;break;} $ex['prev_status']=$ex['status'];$ex['status']='voided';$ex['voided_by']=$retailer['name'];$ex['voided_at']=date('Y-m-d H:i:s');$ex['void_reason']=trim($_POST['void_reason']??'');$scMsg='✅ Entry voided.';$scOk=true;break;} unset($ex); if($scOk){$store->save('cash_expenses.json',$expenses);
        // ── CASCADE VOID: auto-void matching cash_in (via cb_ref) ──
        $_voidedExp = null; foreach($expenses as $_ve){ if((int)($_ve['id']??0)===$scExpId){$_voidedExp=$_ve;break;} }
        if ($_voidedExp) {
            $_vRef = 'EXP-' . $scExpId;
            // Void matching cash_in
            $_vCashIns = $store->load('cash_ins.json') ?? [];
            $_vCiChanged = false;
            foreach ($_vCashIns as &$_vci) {
                if (($_vci['cb_ref'] ?? '') === $_vRef && ($_vci['status'] ?? 'approved') !== 'voided') {
                    $_vci['prev_status'] = $_vci['status'] ?? 'approved';
                    $_vci['status']      = 'voided';
                    $_vci['voided_by']   = $retailer['name'];
                    $_vci['voided_at']   = date('Y-m-d H:i:s');
                    $_vci['void_reason'] = 'Cascade: expense #' . $scExpId . ' voided — ' . trim($_POST['void_reason'] ?? '');
                    $_vCiChanged = true;
                    $scMsg .= ' · Cash IN for ' . ($_vci['collector_name'] ?? 'staff') . ' also voided.';
                }
            }
            unset($_vci);
            if ($_vCiChanged) $store->save('cash_ins.json', $_vCashIns);
            // Void matching cb_ledger entry
            try {
                $__vPdo = $store->getPdo();
                $__vStmt = $__vPdo->prepare("UPDATE cb_ledger SET status = 'voided', description = description || ' [VOIDED: ' || ? || ']' WHERE validation_ref = ? AND status != 'voided'");
                $__vStmt->execute([trim($_POST['void_reason'] ?? 'expense voided'), $_vRef]);
                if ($__vStmt->rowCount() > 0) $scMsg .= ' · Cashbook entry voided.';
            } catch (\Throwable $e) { /* non-fatal */ }
        }
    } }
    if ($scOk && $scAction==='void_entry') $_scSyncToSqlite($scExpId, ['status'=>'voided','voided_by'=>$retailer['name'],'voided_at'=>date('Y-m-d H:i:s'),'void_reason'=>trim($_POST['void_reason']??''),'prev_status'=>'approved']);
    if ($scOk && $scAction==='void_entry') { require_once dirname(__DIR__,2).'/lib/StaffLedgerWriter.php'; StaffLedgerWriter::onExpenseVoided($pdo, $scExpId, 'cash_expenses', $retailer['name']); }
    if ($scAction==='edit_entry'&&$scExpId) { foreach($expenses as &$ex){ if((int)($ex['id']??0)!==$scExpId)continue; if(($ex['status']??'')!=='pending'){$scMsg='Only pending entries can be edited.';$scOk=false;break;} $na=round((float)($_POST['edit_amount']??0),2); if($na>0){if(($ex['currency']??'USD')==='SSP')$ex['ssp_amount']=$na;else $ex['amount']=$na;} if(trim($_POST['edit_desc']??''))$ex['description']=trim($_POST['edit_desc']); if(trim($_POST['edit_category']??''))$ex['category']=trim($_POST['edit_category']); $ex['edited_by']=$retailer['name'];$ex['edited_at']=date('Y-m-d H:i:s');$scMsg='✅ Entry updated.';$scOk=true;break;} unset($ex); if($scOk)$store->save('cash_expenses.json',$expenses); }
    if ($scOk && $scAction==='edit_entry') { $u=['edited_by'=>$retailer['name'],'edited_at'=>date('Y-m-d H:i:s')]; $na=round((float)($_POST['edit_amount']??0),2); if($na>0)$u['amount']=$na; if(trim($_POST['edit_desc']??''))$u['description']=trim($_POST['edit_desc']); if(trim($_POST['edit_category']??''))$u['category']=trim($_POST['edit_category']); $_scSyncToSqlite($scExpId, $u); }
    if ($scAction==='quick_approve'&&$scExpId) { foreach($expenses as &$ex){ if((int)($ex['id']??0)!==$scExpId)continue; if(($ex['status']??'')!=='pending'){$scMsg='Not pending.';$scOk=false;break;} $ex['status']='approved';$ex['approved_by']=$retailer['name'];$ex['approved_at']=date('Y-m-d H:i:s');$scMsg='✅ Approved.';$scOk=true;break;} unset($ex); if($scOk){$store->save('cash_expenses.json',$expenses);
        // v4.9.18: SSP Advance chain — merge approved SSP expense to Rupesh's cb_ledger
        $_apExp=null; foreach($expenses as $_ae){if((int)($_ae['id']??0)===$scExpId){$_apExp=$_ae;break;}}
        if($_apExp && ($_apExp['currency']??'USD')==='SSP' && (float)($_apExp['ssp_amount']??0)>0){
            try{
                require_once __DIR__.'/../../lib/SspAdvanceService.php';
                $_sspSvc2=new SspAdvanceService($store,$dataDir);
                $chainRef2='FIELD-'.strtoupper(preg_replace('/[^a-zA-Z0-9]/','',substr($_apExp['collector_name']??'STAFF',0,10))).'-'.$scExpId;
                $_regRow=$_sspSvc2->findByChainRef($chainRef2);
                if($_regRow && (int)$_regRow['merged_to_cb']===0){
                    $_mr=$_sspSvc2->mergeExpenseToLedger((int)$_regRow['id'],$retailer['name']);
                    if(!empty($_mr['cb_sr']))$scMsg.=' → merged to cashbook as '.$_mr['cb_sr'];
                }
            }catch(\Throwable $e2){/* silent — approval still works even if merge fails */}
        }
        // ── AUTO-LINK: Staff payment → Cash IN for receiving staff ──
        if ($_apExp && (!empty($_apExp['is_staff_payment']) || !empty($_apExp['staff_name'])) && !empty($_apExp['staff_name'])) {
            $_qsLower = strtolower(trim($_apExp['staff_name']));
            if ($_qsLower && $_qsLower !== 'staff') {
                $_qsRetailers = $store->load('retailers.json') ?? [];
                $_qsMatchId = 0; $_qsMatchName = ''; $_qsMatchPhone = '';
                foreach ($_qsRetailers as $_qsr) {
                    if (empty($_qsr['is_active'])) continue;
                    $_qsrName = strtolower($_qsr['name'] ?? '');
                    if ($_qsrName === $_qsLower) { $_qsMatchId = (int)$_qsr['id']; $_qsMatchName = $_qsr['name']; $_qsMatchPhone = $_qsr['phone'] ?? ''; break; }
                    if ($_qsrName && (strpos($_qsrName, $_qsLower) !== false || strpos($_qsLower, $_qsrName) !== false)) {
                        $_qsMatchId = (int)$_qsr['id']; $_qsMatchName = $_qsr['name']; $_qsMatchPhone = $_qsr['phone'] ?? '';
                    }
                }
                if ($_qsMatchId > 0) {
                    $_qsCins = $store->load('cash_ins.json') ?? [];
                    $_qsRef = 'EXP-' . $scExpId;
                    $_qsDup = false;
                    foreach ($_qsCins as $_qci) { if (($_qci['cb_ref'] ?? '') === $_qsRef) { $_qsDup = true; break; } }
                    if (!$_qsDup) {
                        $_qsCur = $_apExp['currency'] ?? 'USD';
                        $_qsCins[] = [
                            'id'            => count($_qsCins) + 1,
                            'collector_id'  => $_qsMatchId,
                            'collector_name'=> $_qsMatchName,
                            'amount'        => $_qsCur === 'USD' ? (float)($_apExp['amount'] ?? 0) : 0,
                            'currency'      => $_qsCur,
                            'ssp_amount'    => $_qsCur === 'SSP' ? (float)($_apExp['ssp_amount'] ?? 0) : 0,
                            'usd_given'     => 0,
                            'rate'          => 0,
                            'category'      => $_qsCur === 'SSP' ? 'SSP Received' : 'USD Received',
                            'description'   => 'From ' . ($_apExp['collector_name'] ?? 'Field') . ' — ' . ($_apExp['staff_payment_type'] ?? $_apExp['category'] ?? 'Staff Payment'),
                            'status'        => 'approved',
                            'approved_by'   => 'auto (quick approve link)',
                            'approved_at'   => date('Y-m-d H:i:s'),
                            'cb_ref'        => $_qsRef,
                            'created_at'    => date('Y-m-d H:i:s'),
                        ];
                        $store->save('cash_ins.json', $_qsCins);
                        // Dual-write receiver's cash_in to staff_ledger (idempotent via CIN-{id})
                        try {
                            require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                            StaffLedgerWriter::onCashIn($store->getPdo(), $_qsCins[count($_qsCins) - 1]);
                        } catch (\Throwable $_slwErr2) {}
                        $scMsg .= ' · Linked to ' . $_qsMatchName . "'s register.";
                    }
                }
            }
        }
    } }
    if ($scOk && $scAction==='quick_approve') $_scSyncToSqlite($scExpId, ['status'=>'approved','approved_by'=>$retailer['name'],'approved_at'=>date('Y-m-d H:i:s')]);
    if ($scOk && $scAction==='quick_approve') { $_apExp2=null; foreach($expenses as $_ae2){if((int)($_ae2['id']??0)===$scExpId){$_apExp2=$_ae2;break;}} if($_apExp2){ require_once dirname(__DIR__,2).'/lib/StaffLedgerWriter.php'; StaffLedgerWriter::onExpenseApproved($pdo, $_apExp2, 'cash_expenses'); } }
    // ── Quick-approve: write to company cb_ledger (was missing) ─────────
    // SAFETY RULE 8: amount must be USD. For SSP expenses convert via system rate.
    if ($scOk && $scAction==='quick_approve') {
        $_qaExp=null; foreach($expenses as $_qae){if((int)($_qae['id']??0)===$scExpId){$_qaExp=$_qae;break;}}
        if ($_qaExp) {
            try {
                require_once dirname(__DIR__,2).'/lib/CashbookService.php';
                $_qaCb   = new CashbookService($store,$dataDir);
                $_qaCur  = $_qaExp['currency'] ?? 'USD';
                $_qaSsp  = (float)($_qaExp['ssp_amount'] ?? $_qaExp['amount'] ?? 0);
                $_qaRate = $_qaCb->getExchangeRate() ?: 5800.0;
                $_qaUsd  = ($_qaCur === 'SSP') ? round($_qaSsp / max(1.0,$_qaRate), 2) : (float)($_qaExp['amount'] ?? 0);
                $_qaRef  = 'CEXP-' . $scExpId;
                $_qaChk  = $pdo->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
                $_qaChk->execute([$_qaRef]);
                if (!$_qaChk->fetchColumn()) {
                    $_qaCat  = $_qaExp['category'] ?? 'Misc Expense';
                    $_qaDesc = ($_qaExp['description'] ?? $_qaCat)
                             . ' — ' . ($_qaExp['collector_name'] ?? $_qaExp['staff_name'] ?? 'Staff')
                             . ' [quick-approved]';
                    $_qaCb->addEntryRaw([
                        'project'           => $_qaExp['project'] ?? 'dishnet',
                        'date'              => substr($_qaExp['created_at'] ?? date('Y-m-d'), 0, 10),
                        'direction'         => 'out',
                        'amount'            => $_qaUsd,
                        'currency'          => $_qaCur,
                        'ssp_amount'        => ($_qaCur === 'SSP') ? $_qaSsp : null,
                        'ssp_rate'          => ($_qaCur === 'SSP') ? $_qaRate : null,
                        'category'          => $_qaCat,
                        'category_raw'      => $_qaCat,
                        'person'            => $_qaExp['collector_name'] ?? $_qaExp['staff_name'] ?? '',
                        'description'       => $_qaDesc,
                        'validation_ref'    => $_qaRef,
                        'validation_status' => !empty($_qaExp['receipt_path']) ? 'wr' : 'pending',
                        'status'            => 'approved',
                        'approved_by'       => $retailer['name'],
                        'source'            => 'expense_sync',
                    ]);
                }
            } catch (\Throwable $e2) { /* non-fatal — cashbook write never blocks approval */ }
        }
    }

    // ── Void a cash_in entry directly ──────────────────────────────────
    if ($scAction === 'void_cash_in') {
        $ciId = (int)($_POST['cash_in_id'] ?? 0);
        $reason = trim($_POST['void_reason'] ?? '');
        if ($ciId && $reason) {
            $cashIns = $store->load('cash_ins.json') ?? [];
            foreach ($cashIns as &$ci) {
                if ((int)($ci['id'] ?? 0) !== $ciId) continue;
                if (($ci['status'] ?? 'approved') === 'voided') { $scMsg = 'Already voided.'; $scOk = false; break; }
                $ci['prev_status'] = $ci['status'] ?? 'approved';
                $ci['status']      = 'voided';
                $ci['voided_by']   = $retailer['name'];
                $ci['voided_at']   = date('Y-m-d H:i:s');
                $ci['void_reason'] = $reason;
                $scMsg = '✅ Cash IN #' . $ciId . ' voided for ' . ($ci['collector_name'] ?? 'staff') . '.';
                $scOk = true;
                logActivity($dataDir, 'void_cash_in', "Voided cash_in #{$ciId} for " . ($ci['collector_name'] ?? '?') . ": {$reason}", $retailer['name']);
                break;
            }
            unset($ci);
            if ($scOk) {
                $store->save('cash_ins.json', $cashIns);
                // Dual-write: staff_ledger
                require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                StaffLedgerWriter::onCashInVoided($store->getPdo(), $ciId, $retailer['name']);
            }
        } else {
            $scMsg = 'Reason is required to void.'; $scOk = false;
        }
    }

    // ── VOID COLLECTION ──────────────────────────────────────────────────
    if ($scAction === 'void_collection') {
        $colId  = (int)($_POST['collection_id'] ?? 0);
        $reason = trim($_POST['void_reason'] ?? '');
        if (!$colId || !$reason) { $scMsg = 'Collection ID and reason required.'; $scOk = false; }
        else {
            $allCols = $store->load('payment_collections.json') ?? [];
            foreach ($allCols as &$col) {
                if ((int)($col['id'] ?? 0) !== $colId) continue;
                if (($col['status'] ?? '') === 'voided') { $scMsg = 'Already voided.'; $scOk = false; break; }
                $col['prev_status'] = $col['status'] ?? 'approved';
                $col['status']      = 'voided';
                $col['voided_by']   = $retailer['name'];
                $col['voided_at']   = date('Y-m-d H:i:s');
                $col['void_reason'] = $reason;
                $col['audit_log']   = $col['audit_log'] ?? [];
                $col['audit_log'][] = ['action'=>'void','by'=>$retailer['name'],'at'=>date('Y-m-d H:i:s'),'reason'=>$reason];
                $scMsg = '✅ Collection #' . $colId . ' voided ($' . number_format($col['amount'] ?? 0, 2) . ').';
                $scOk  = true;
                // Also void matching cb_ledger entry
                try {
                    $__cPdo = $store->getPdo();
                    $__cRef = ($col['crm_payment_id'] ?? null) ? 'PAY-' . $col['crm_payment_id'] : 'COL-' . $colId;
                    $__cPdo->prepare("UPDATE cb_ledger SET status='voided', description=description||' [VOIDED: '||?||']' WHERE validation_ref=? AND status!='voided'")->execute([$reason, $__cRef]);
                } catch (\Throwable $e) {}
                break;
            }
            unset($col);
            if ($scOk) {
                $store->save('payment_collections.json', $allCols);
                // Dual-write: staff_ledger
                require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                StaffLedgerWriter::onCollectionVoided($store->getPdo(), $colId, $retailer['name'], $reason);
            }
        }
    }

    // ── CORRECT COLLECTION AMOUNT ────────────────────────────────────────
    if ($scAction === 'correct_collection') {
        $colId    = (int)($_POST['collection_id'] ?? 0);
        $newAmt   = round((float)($_POST['correct_amount'] ?? 0), 2);
        $reason   = trim($_POST['correct_reason'] ?? '');
        if (!$colId || $newAmt <= 0 || !$reason) { $scMsg = 'Collection ID, new amount and reason required.'; $scOk = false; }
        else {
            $allCols = $store->load('payment_collections.json') ?? [];
            foreach ($allCols as &$col) {
                if ((int)($col['id'] ?? 0) !== $colId) continue;
                if (($col['status'] ?? '') === 'voided') { $scMsg = 'Cannot correct voided entry.'; $scOk = false; break; }
                $oldAmt = (float)($col['amount'] ?? 0);
                $col['amount']    = $newAmt;
                $col['audit_log'] = $col['audit_log'] ?? [];
                $col['audit_log'][] = ['action'=>'correct','by'=>$retailer['name'],'at'=>date('Y-m-d H:i:s'),'old_amount'=>$oldAmt,'new_amount'=>$newAmt,'reason'=>$reason];
                $col['corrected_by'] = $retailer['name'];
                $col['corrected_at'] = date('Y-m-d H:i:s');
                $scMsg = '✅ Collection #' . $colId . ' corrected: $' . number_format($oldAmt, 2) . ' → $' . number_format($newAmt, 2);
                $scOk  = true;
                // Update cb_ledger amount too
                try {
                    $__cPdo = $store->getPdo();
                    $__cRef = ($col['crm_payment_id'] ?? null) ? 'PAY-' . $col['crm_payment_id'] : 'COL-' . $colId;
                    $__cPdo->prepare("UPDATE cb_ledger SET amount=?, description=description||' [CORRECTED: '||?||' by '||?||']' WHERE validation_ref=? AND status!='voided'")->execute([$newAmt, '$'.number_format($oldAmt,2).'→$'.number_format($newAmt,2).': '.$reason, $retailer['name'], $__cRef]);
                } catch (\Throwable $e) {}
                break;
            }
            unset($col);
            if ($scOk) $store->save('payment_collections.json', $allCols);
        }
    }

    // ── ADD MANUAL ENTRY (correction/adjustment) ─────────────────────────
    if ($scAction === 'add_manual_entry') {
        $manDir   = trim($_POST['man_direction'] ?? 'in');
        $manAmt   = round((float)($_POST['man_amount'] ?? 0), 2);
        $manCat   = trim($_POST['man_category'] ?? 'Adjustment');
        $manDesc  = trim($_POST['man_description'] ?? '');
        $manCur   = strtoupper(trim($_POST['man_currency'] ?? 'USD'));
        $manStaff = (int)($_POST['man_staff_id'] ?? $selId);
        if ($manAmt <= 0 || !$manDesc) { $scMsg = 'Amount and description required.'; $scOk = false; }
        else {
            if ($manCur === 'SSP') {
                $_manCinRecord = $store->appendWithId('cash_ins.json', [
                    'collector_id'   => $manStaff,
                    'collector_name' => $selStaff['name'] ?? '',
                    'category'       => $manCat,
                    'currency'       => 'SSP',
                    'ssp_amount'     => $manAmt,
                    'amount'         => 0,
                    'description'    => $manDesc . ' [Manual: ' . $retailer['name'] . ']',
                    'status'         => 'approved',
                    'approved_by'    => $retailer['name'],
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                // Dual-write: staff_ledger
                require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
                StaffLedgerWriter::onCashIn($store->getPdo(), $_manCinRecord);
            } else {
                $store->appendWithId('payment_collections.json', [
                    'retailer_id'     => $manStaff,
                    'retailer_name'   => $selStaff['name'] ?? '',
                    'customer_name'   => $manDesc,
                    'amount'          => $manAmt,
                    'currency'        => 'USD',
                    'method'          => 'Cash',
                    'service_type'    => 'manual',
                    'note'            => 'Manual entry by ' . $retailer['name'] . ': ' . $manCat,
                    'source'          => 'manual_adjustment',
                    'crm_synced'      => false,
                    'commission'      => 0,
                    'status'          => $manDir === 'in' ? 'approved' : 'voided',
                    'audit_log'       => [['action'=>'manual_create','by'=>$retailer['name'],'at'=>date('Y-m-d H:i:s'),'reason'=>$manDesc]],
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
            }
            $scMsg = '✅ Manual ' . ($manCur === 'SSP' ? 'SSP' : 'USD') . ' entry added: $' . number_format($manAmt, 2) . ' — ' . $manDesc;
            $scOk  = true;
        }
    }

    // ── ADMIN WRITE-OFF EXPENSE (v4.21.110) ──────────────────────────────
    // Post an approved OUT expense under the *target* staff's collector_id.
    // Used to zero out a staff balance when cash is acknowledged as gone
    // (loss, system-build artifact, cash discrepancy write-off).
    //
    // Admin/accountant only (page-level guard at top already enforces this).
    // CSRF-protected. Always 'approved' status. Always dual-writes to
    // staff_expenses SQLite for the unified ledger. Audit-logged.
    //
    // POST params:
    //   sc_action       = 'admin_writeoff_expense'
    //   wo_staff_id     = (int) target staff retailer_id
    //   wo_currency     = 'USD' | 'SSP'
    //   wo_amount       = (float) > 0
    //   wo_reason       = (string) required, non-empty
    if ($scAction === 'admin_writeoff_expense') {
        $woStaff   = (int)($_POST['wo_staff_id'] ?? 0);
        $woCur     = strtoupper(trim($_POST['wo_currency'] ?? 'USD'));
        $woAmt     = round((float)($_POST['wo_amount'] ?? 0), 2);
        $woReason  = trim($_POST['wo_reason'] ?? '');
        if (!in_array($woCur, ['USD','SSP'], true)) $woCur = 'USD';

        // Resolve target staff name
        $woStaffName = '';
        foreach ($allStaff as $_ws) {
            if ((int)($_ws['id'] ?? 0) === $woStaff) { $woStaffName = $_ws['name'] ?? ''; break; }
        }

        if ($woStaff <= 0 || $woAmt <= 0 || $woReason === '' || $woStaffName === '') {
            $scMsg = 'Write-off: staff_id, amount > 0, and reason are all required.';
            $scOk  = false;
        } else {
            $woNow  = date('Y-m-d H:i:s');
            $woDesc = 'Write-off — ' . $woReason . ' [by ' . ($retailer['name'] ?? 'Admin') . ' on ' . $woNow . ']';

            // 1. Append to cash_expenses.json (auto-approved write-off)
            $_woExp = $store->appendWithId('cash_expenses.json', [
                'collector_id'    => $woStaff,
                'collector_name'  => $woStaffName,
                'amount'          => $woCur === 'USD' ? $woAmt : 0,
                'currency'        => $woCur,
                'ssp_amount'      => $woCur === 'SSP' ? $woAmt : null,
                'ssp_rate'        => null,
                'category'        => 'Write-off',
                'expense_type'    => 'Write-off',
                'description'     => $woDesc,
                'photo'           => '',
                'status'          => 'approved',
                'auto_approved'   => false,
                'approved_by'     => $retailer['name'] ?? 'Admin',
                'approved_at'     => $woNow,
                'submitted_at'    => $woNow,
                'created_at'      => $woNow,
                'is_writeoff'     => true,
                'writeoff_reason' => $woReason,
            ]);

            // 2. Dual-write to staff_expenses SQLite (unified ledger)
            try {
                $_woPdo = $store->getPdo();
                $_woPdo->query("SELECT source FROM staff_expenses LIMIT 0"); // migration 043 check
                $_woStmt = $_woPdo->prepare(
                    "INSERT INTO staff_expenses (
                        source, legacy_json_id, staff_id, staff_name,
                        category, amount, ssp_amount, currency, description, expense_date,
                        submitted_via, status, auto_approved, approved_by, approved_at,
                        submitted_at, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $_woStmt->execute([
                    'field',
                    (int)($_woExp['id'] ?? 0),
                    $woStaff,
                    $woStaffName,
                    'Write-off',
                    $woCur === 'USD' ? $woAmt : 0,
                    $woCur === 'SSP' ? $woAmt : 0,
                    $woCur,
                    $woDesc,
                    substr($woNow, 0, 10),
                    'admin_writeoff',
                    'approved',
                    0,
                    $retailer['name'] ?? 'Admin',
                    $woNow,
                    $woNow,
                    $woNow,
                    $woNow,
                ]);
            } catch (\Throwable $_woE) { /* migration not applied; JSON is authoritative */ }

            // 3. Activity log
            logActivity(
                $dataDir,
                'admin_writeoff_expense',
                'Write-off ' . ($woCur === 'SSP' ? number_format($woAmt, 0) . ' SSP' : '$' . number_format($woAmt, 2))
                    . ' for ' . $woStaffName . ' (#' . $woStaff . '): ' . $woReason,
                $retailer['name'] ?? 'Admin'
            );

            $scMsg = '✅ Write-off posted: '
                   . ($woCur === 'SSP' ? number_format($woAmt, 0) . ' SSP' : '$' . number_format($woAmt, 2))
                   . ' against ' . $woStaffName . '. Reason: ' . $woReason;
            $scOk  = true;
        }
    }

    // ── record_exchange: Atomic USD↔SSP conversion ─────────────────────
    if ($scAction === 'record_exchange') {
        require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
        $excDir   = trim($_POST['exc_direction'] ?? 'usd_to_ssp'); // usd_to_ssp | ssp_to_usd
        $excAmt   = round((float)($_POST['exc_amount'] ?? 0), 2);
        $excRate  = round((float)($_POST['exc_rate']   ?? 0), 2);
        $excNote  = trim($_POST['exc_note'] ?? '');
        $excStaff = (int)($_POST['exc_staff_id'] ?? $selId);
        $excStaffName = $selStaff['name'] ?? 'Staff';
        $excRef   = 'EXCH-' . date('ymdHis') . '-' . $excStaff;
        $now      = date('Y-m-d H:i:s');
        $by       = $retailer['name'] ?? 'Admin';

        if ($excAmt <= 0 || $excRate <= 0) {
            $scMsg = 'Amount and rate are required.'; $scOk = false;
        } else {
            if ($excDir === 'usd_to_ssp') {
                // ── Side A: SSP coming IN → cash_ins.json (category=Exchange) ──
                $sspReceived = round($excAmt * $excRate, 0);
                $desc = $excNote ?: ('Exchange $' . number_format($excAmt, 2) . ' → ' . number_format($sspReceived, 0) . ' SSP @ ' . number_format($excRate, 0) . ' — ' . $excStaffName);
                $cinRecord = $store->appendWithId('cash_ins.json', [
                    'collector_id'   => $excStaff,
                    'collector_name' => $excStaffName,
                    'category'       => 'Exchange',
                    'currency'       => 'SSP',
                    'ssp_amount'     => $sspReceived,
                    'usd_given'      => $excAmt,
                    'rate'           => $excRate,
                    'amount'         => $excAmt,  // USD given — for ledger balance
                    'description'    => $desc,
                    'exchange_ref'   => $excRef,
                    'status'         => 'approved',
                    'approved_by'    => $by,
                    'approved_at'    => $now,
                    'created_at'     => $now,
                ]);
                StaffLedgerWriter::onCashIn($store->getPdo(), $cinRecord);

                // ── Side B: USD going OUT → staff_expenses (so it reduces USD bag) ──
                require_once dirname(__DIR__, 2) . '/lib/ExpenseAdvanceService.php';
                $expSvc = new ExpenseAdvanceService($store, $store->getPdo(), $dataDir);
                $expResult = $expSvc->submitExpense($excStaff, [
                    'amount'       => $excAmt,
                    'currency'     => 'USD',
                    'category'     => 'Exchange',
                    'description'  => $desc,
                    'exchange_ref' => $excRef,
                ]);
                // Auto-approve the expense so it hits the USD balance immediately
                if (!empty($expResult['id'])) {
                    $expSvc->approveExpense($expResult['id'], ['id' => $retailer['id'] ?? 0, 'name' => $by], 'Exchange auto-approved');
                }

                // ── Explicit cb_ledger writes (dedup-safe) ───────────────────
                // Always write both sides so both USD and SSP cashbooks reflect
                // the exchange immediately — don't rely on approveExpense() path alone.
                $_excCbSc  = new CashbookService($store, $dataDir);
                $_excPdoSc = $_excCbSc->getPdo();
                $_cbRefSc  = 'FIELD-' . $excRef;

                $_dU = $_excPdoSc->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
                $_dU->execute([$_cbRefSc . '-USD']);
                if (!$_dU->fetchColumn()) {
                    $_excCbSc->addEntryRaw([
                        'project' => 'dishnet', 'date' => date('Y-m-d'),
                        'direction' => 'out', 'amount' => $excAmt,
                        'currency' => 'USD', 'ssp_amount' => null, 'ssp_rate' => $excRate,
                        'category' => 'Exchange', 'category_raw' => 'Exchange',
                        'person' => $excStaffName, 'description' => $desc,
                        'validation_ref' => $_cbRefSc . '-USD', 'validation_status' => 'done',
                        'status' => 'approved', 'source' => 'field_exchange',
                    ]);
                }
                $_dS = $_excPdoSc->prepare("SELECT id FROM cb_ledger WHERE validation_ref=? LIMIT 1");
                $_dS->execute([$_cbRefSc . '-SSP']);
                if (!$_dS->fetchColumn()) {
                    $_excCbSc->addEntryRaw([
                        'project' => 'dishnet', 'date' => date('Y-m-d'),
                        'direction' => 'in', 'amount' => $excAmt,
                        'currency' => 'SSP', 'ssp_amount' => $sspReceived, 'ssp_rate' => $excRate,
                        'category' => 'Exchange', 'category_raw' => 'Exchange',
                        'person' => $excStaffName,
                        'description' => $desc, // same as USD side
                        'validation_ref' => $_cbRefSc . '-SSP', 'validation_status' => 'done',
                        'status' => 'approved', 'source' => 'field_exchange',
                    ]);
                }

                $scMsg = '✅ Exchange recorded: $' . number_format($excAmt, 2) . ' → ' . number_format($sspReceived, 0) . ' SSP @ ' . number_format($excRate, 0) . ' rate. Both sides posted.';
                $scOk  = true;

            } else {
                // ── SSP to USD: SSP going OUT, USD coming IN ──
                $usdReceived = round($excAmt / $excRate, 2);
                $desc = $excNote ?: ('Exchange ' . number_format($excAmt, 0) . ' SSP → $' . number_format($usdReceived, 2) . ' @ ' . number_format($excRate, 0));

                // ── Side A: USD coming IN → cash_ins.json (USD Received) ──
                $cinRecord = $store->appendWithId('cash_ins.json', [
                    'collector_id'   => $excStaff,
                    'collector_name' => $excStaffName,
                    'category'       => 'Exchange',
                    'currency'       => 'USD',
                    'amount'         => $usdReceived,
                    'ssp_amount'     => 0,
                    'ssp_given'      => $excAmt,   // SSP given
                    'rate'           => $excRate,
                    'description'    => $desc,
                    'exchange_ref'   => $excRef,
                    'status'         => 'approved',
                    'approved_by'    => $by,
                    'approved_at'    => $now,
                    'created_at'     => $now,
                ]);
                StaffLedgerWriter::onCashIn($store->getPdo(), $cinRecord);

                // ── Side B: SSP going OUT → cash_ins.json (SSP negative / void equivalent) ──
                // Record as a voided SSP Received entry so the SSP bag decreases
                $sspOutRecord = $store->appendWithId('cash_ins.json', [
                    'collector_id'   => $excStaff,
                    'collector_name' => $excStaffName,
                    'category'       => 'Exchange',
                    'currency'       => 'SSP',
                    'ssp_amount'     => -1 * $excAmt,  // negative = outgoing SSP
                    'amount'         => 0,
                    'rate'           => $excRate,
                    'description'    => $desc . ' [SSP OUT]',
                    'exchange_ref'   => $excRef,
                    'status'         => 'approved',
                    'approved_by'    => $by,
                    'approved_at'    => $now,
                    'created_at'     => $now,
                ]);
                StaffLedgerWriter::onCashIn($store->getPdo(), $sspOutRecord);

                // Phase C: deduct SSP from original exchange batch(es) via FIFO
                try {
                    (new CashbookService($store, $dataDir))->deductFromBatchesFIFO(
                        $store->load('cash_ins.json') ?: [],
                        $aid, $selName,
                        $excAmt, $excRate, $excRef,
                        $desc, date('Y-m-d')
                    );
                } catch (\Throwable $e) { /* non-fatal */ }

                $scMsg = '✅ Exchange recorded: ' . number_format($excAmt, 0) . ' SSP → $' . number_format($usdReceived, 2) . ' @ ' . number_format($excRate, 0) . ' rate. Both sides posted.';
                $scOk  = true;
            }
        }
    }
}
// v4.11.38: Single source of truth for all balance displays (card, detail, field register)
if (!class_exists('StaffCashPositionService')) require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
if (!isset($_jsonCpSvc)) $_jsonCpSvc = new StaffCashPositionService($store, $store->getPdo());

$sc_ledger=[]; $sc_usd=0; $sc_ssp=0; $sc_pend=0; $sc_usd_col=0; $sc_wallet=0;
if ($selStaff) {
    // One-time fix: backfill ssp_amount for SSP expenses
    try { $store->getPdo()->exec("UPDATE staff_expenses SET ssp_amount = amount WHERE currency = 'SSP' AND (ssp_amount = 0 OR ssp_amount IS NULL) AND amount > 0"); } catch (\Throwable $e) {}

    $aid=$selId; $_pos=$cpSvc->getPosition($aid); $sc_usd=round($_jsonCpSvc->getUSDBalance($aid),2);
    $cols=array_filter($store->load('payment_collections.json')?:[],fn($c)=>(int)($c['retailer_id']??0)===$aid&&($c['status']??'')!=='voided');
    $hovs=array_filter($store->load('cash_handovers.json')?:[],fn($h)=>(int)($h['from_id']??0)===$aid);
    $exps=array_filter($_scAllExps,fn($e)=>(int)($e['collector_id']??0)===$aid&&!in_array($e['status']??'',['voided','cancelled']));
    $cins=array_filter($store->load('cash_ins.json')?:[],fn($i)=>(int)($i['collector_id']??0)===$aid);

    // SSP balance via DualReadCashPosition (single ledger query, no double-counting)
    $_sc_ssp_raw = round($cpSvc->getSSPBalance($aid), 0);

    // Fallback to direct JSON formula when:
    // (a) ledger returns 0 but cash_in entries exist (backfill incomplete), OR
    // (b) ledger returns positive but JSON formula returns 0 (staff payment expense
    //     stored under issuer's staff_id instead of recipient's — e.g. Meckline bug)
    $_needFallback = ($_sc_ssp_raw <= 0);
    if (!$_needFallback && $_sc_ssp_raw > 0) {
        // Quick JSON cross-check: if expenses exist but cash_ins are zero, ledger is over-counting
        require_once dirname(__DIR__, 2) . '/lib/ExpenseGateway.php';
        $_scGw = new ExpenseGateway($store, $store->getPdo(), $dataDir);
        $_scExpsUnified = array_filter($_scGw->getByStaff($aid), fn($e) => !in_array($e['status'] ?? '', ['voided','cancelled']));
        $_scSspIn  = round(array_sum(array_column(array_values(array_filter($cins, fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange']) && !in_array($i['status'] ?? 'approved', ['rejected','voided']))), 'ssp_amount')), 0);
        $_scSspExp = round(array_sum(array_column(array_values(array_filter($_scExpsUnified, fn($e) => ($e['currency'] ?? 'USD') === 'SSP' && in_array($e['status'] ?? '', ['approved']))), 'ssp_amount')), 0);
        $_scSspHov = round(array_sum(array_map(fn($h) => (float)($h['ssp_amount'] ?? $h['amount'] ?? 0), array_values(array_filter($hovs, fn($h) => ($h['status'] ?? '') === 'confirmed' && strtoupper($h['currency'] ?? 'USD') === 'SSP')))), 0);
        $_jsonResult = max(0, $_scSspIn - $_scSspExp - $_scSspHov);
        // If JSON says 0 (or much lower) but ledger says positive — expenses tracked under wrong staff_id
        if ($_jsonResult < $_sc_ssp_raw && $_scSspExp > 0 && $_scSspIn === 0) {
            $_sc_ssp_raw = $_jsonResult; // trust JSON — it reads collector_id which is always correct
            $_needFallback = false; // already computed above
        }
    }
    if ($_needFallback) {
        if (!isset($_scGw)) {
            require_once dirname(__DIR__, 2) . '/lib/ExpenseGateway.php';
            $_scGw = new ExpenseGateway($store, $store->getPdo(), $dataDir);
            $_scExpsUnified = array_filter($_scGw->getByStaff($aid), fn($e) => !in_array($e['status'] ?? '', ['voided','cancelled']));
            $_scSspIn  = round(array_sum(array_column(array_values(array_filter($cins, fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange']) && !in_array($i['status'] ?? 'approved', ['rejected','voided']))), 'ssp_amount')), 0);
            $_scSspExp = round(array_sum(array_column(array_values(array_filter($_scExpsUnified, fn($e) => ($e['currency'] ?? 'USD') === 'SSP' && in_array($e['status'] ?? '', ['approved']))), 'ssp_amount')), 0);
            $_scSspHov = round(array_sum(array_map(fn($h) => (float)($h['ssp_amount'] ?? $h['amount'] ?? 0), array_values(array_filter($hovs, fn($h) => ($h['status'] ?? '') === 'confirmed' && strtoupper($h['currency'] ?? 'USD') === 'SSP')))), 0);
        }
        $_sc_ssp_raw = max(0, $_scSspIn - $_scSspExp - $_scSspHov);
    }

    $sc_ssp = max(0, $_sc_ssp_raw); // clamp to 0 for display (negative = overspent)
    // SSP sub-totals for hero badges
    // v4.11.3: All-time SSP IN — read from staff_ledger (same source as hero balance)
    // so the badge IN total is always consistent with the hero number.
    // $sspAllTimeIn = sum of all active 'in' rows for this staff in SSP
    try {
        $_slIn = $store->getPdo()->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM staff_ledger
             WHERE staff_id=? AND currency='SSP' AND direction='in' AND status='active'"
        );
        $_slIn->execute([$aid]);
        $sspIn = (int)$_slIn->fetchColumn();
    } catch (\Throwable $_e) {
        // Fallback to cash_ins.json direct read if ledger unavailable
        $sspIn = (int)round(array_sum(array_column(array_values(array_filter($cins,
            fn($i) => in_array($i['category'] ?? '', ['SSP Received','Exchange'])
                   && !in_array($i['status'] ?? 'approved', ['rejected','voided'])
        )), 'ssp_amount')), 0);
    }
    $sspOut  = max(0, (int)round($sspIn - $_sc_ssp_raw, 0)); // total out = total in − balance
    $_sspHov = 0; // included in sspOut via ledger
    $sc_usd_col=round(array_sum(array_column(array_values(array_filter($cols,fn($c)=>($c['currency']??'USD')==='USD')),'amount')),2);
    $sc_wallet=round($walSvc->getBalance($aid),2);
    $fFrom=$_GET['sc_from']??date('Y-m-d',strtotime('-30 days')); $fTo=$_GET['sc_to']??date('Y-m-d');
    foreach($cols as $c){$sc_ledger[]=['date'=>substr($c['collected_at']??$c['created_at']??date('Y-m-d'),0,10),'datetime'=>($c['collected_at']??$c['created_at']??date('Y-m-d H:i:s')),'dir'=>'in','cur'=>'USD','amt'=>(float)($c['amount']??0),'ssp'=>0,'cat'=>'Collection','desc'=>$c['customer_name']??'','status'=>empty($c['crm_synced'])?'pending':'approved','src'=>'collection','rid'=>(int)($c['id']??0),'auto'=>false,'photo'=>'','person'=>$c['customer_name']??''];}
    foreach($cins as $i){$cur=($i['category']??'')==='Exchange'?'SSP':($i['currency']??'SSP');$sc_ledger[]=['date'=>substr($i['created_at']??date('Y-m-d'),0,10),'datetime'=>($i['created_at']??date('Y-m-d H:i:s')),'dir'=>'in','cur'=>$cur,'amt'=>(float)($i['amount']??0),'ssp'=>(float)($i['ssp_amount']??0),'cat'=>$i['category']??'SSP Received','desc'=>$i['description']??'','status'=>$i['status']??'approved','src'=>'cash_in','rid'=>(int)($i['id']??0),'auto'=>false,'photo'=>'','person'=>''];}
    foreach($exps as $e){$cur=$e['currency']??'USD';$isSt=!empty($e['is_staff_payment'])||!empty($e['staff_name']);$p=[];if($isSt&&!empty($e['staff_name']))$p[]=$e['staff_name'];if(!$isSt)$p[]=$e['category']??'';if(!empty($e['description']))$p[]=$e['description'];$sc_ledger[]=['entry_date'=>substr($e['submitted_at']??$e['created_at']??date('Y-m-d'),0,10),'datetime'=>(function($e){$t=$e['submitted_at']??$e['created_at']??'';return(strlen($t)>10?$t:($e['approved_at']??$t?:date('Y-m-d H:i:s')));})($e),'date'=>substr((function($e){$t=$e['submitted_at']??$e['created_at']??'';return strlen($t)>10?$t:($e['approved_at']??$t?:date('Y-m-d H:i:s'));})($e),0,10),'dir'=>'out','cur'=>$cur,'amt'=>(float)($e['amount']??0),'ssp'=>(float)($e['ssp_amount']??0),'cat'=>$isSt?'Staff Payment':($e['expense_type']??$e['category']??'Expense'),'desc'=>implode(' — ',array_filter($p)),'status'=>$e['status']??'pending','src'=>'expense','rid'=>(int)($e['id']??0),'auto'=>!empty($e['auto_approved']),'photo'=>$e['photo']??'','person'=>$e['staff_name']??''];}
    foreach($hovs as $h){$hc=strtoupper($h['currency']??'USD');$sc_ledger[]=['date'=>substr($h['created_at']??date('Y-m-d'),0,10),'datetime'=>(function($h){$t=$h['confirmed_at']??$h['submitted_at']??$h['created_at']??'';return(strlen($t)>10?$t:date('Y-m-d H:i:s'));})($h),'dir'=>'out','cur'=>$hc,'amt'=>(float)($h['amount']??0),'ssp'=>(float)($h['ssp_amount']??$h['amount']??0),'cat'=>'Handover','desc'=>'To '.($h['to_name']??'Rupesh'),'status'=>$h['status']??'pending','src'=>'handover','rid'=>(int)($h['id']??0),'auto'=>false,'photo'=>'','person'=>$h['to_name']??''];}

    // ── Advances received (root only, active/partial) ───────────────────
    try {
        $_advStmt = $store->getPdo()->prepare(
            "SELECT id, advance_no, amount, currency, purpose, description,
                    amount_spent, amount_returned, children_allocated, status, issued_at
             FROM cash_advances
             WHERE recipient_id = ? AND status IN ('active','partial','settled')
               AND (parent_advance_id IS NULL OR parent_advance_id = 0)
             ORDER BY issued_at DESC"
        );
        $_advStmt->execute([$aid]);
        foreach ($_advStmt->fetchAll(\PDO::FETCH_ASSOC) as $_adv) {
            $_advCur = strtoupper($_adv['currency'] ?? 'USD');
            $_advAmt = (float)($_adv['amount'] ?? 0);
            $_advDesc = ($_adv['advance_no'] ?? '') . ' — ' . ($_adv['purpose'] ?? '') . ($_adv['description'] ? ': ' . $_adv['description'] : '');
            $sc_ledger[] = ['date'=>substr($_adv['issued_at'] ?? date('Y-m-d'), 0, 10),'dir'=>'in','cur'=>$_advCur,'amt'=>$_advCur==='USD'?$_advAmt:0,'ssp'=>$_advCur==='SSP'?$_advAmt:0,'cat'=>'Advance Received','desc'=>$_advDesc,'status'=>$_adv['status']??'active','src'=>'advance','rid'=>(int)($_adv['id']??0),'auto'=>false,'photo'=>'','person'=>''];
        }
    } catch (Throwable $_ae) {}

    // ── Staff expenses (advance-linked, from SQLite) ────────────────────
    try {
        $_seStmt = $store->getPdo()->prepare(
            "SELECT id, expense_no, amount, currency, category, description, expense_date, status
             FROM staff_expenses
             WHERE staff_id = ? AND status = 'approved'
             ORDER BY expense_date DESC"
        );
        $_seStmt->execute([$aid]);
        foreach ($_seStmt->fetchAll(\PDO::FETCH_ASSOC) as $_se) {
            $_seCur = strtoupper($_se['currency'] ?? 'USD');
            $_seAmt = (float)($_se['amount'] ?? 0);
            $_seDesc = ($_se['expense_no'] ?? '') . ' — ' . ($_se['category'] ?? '') . ($_se['description'] ? ': ' . $_se['description'] : '');
            $sc_ledger[] = ['date'=>$_se['expense_date'] ?? date('Y-m-d'),'dir'=>'out','cur'=>$_seCur,'amt'=>$_seCur==='USD'?$_seAmt:0,'ssp'=>$_seCur==='SSP'?$_seAmt:0,'cat'=>'Advance Expense','desc'=>$_seDesc,'status'=>'approved','src'=>'staff_expense','rid'=>(int)($_se['id']??0),'auto'=>false,'photo'=>'','person'=>''];
        }
    } catch (Throwable $_ae) {}

    // ── Staff transfers (sent = OUT, received = IN) ─────────────────────
    try {
        $_trStmt = $store->getPdo()->prepare(
            "SELECT id, transfer_no, from_id, from_name, to_id, to_name,
                    amount, currency, purpose, description, status, submitted_at
             FROM staff_transfers
             WHERE (from_id = ? OR to_id = ?) AND status = 'approved'
             ORDER BY submitted_at DESC"
        );
        $_trStmt->execute([$aid, $aid]);
        foreach ($_trStmt->fetchAll(\PDO::FETCH_ASSOC) as $_tr) {
            $_trCur = strtoupper($_tr['currency'] ?? 'USD');
            $_trAmt = (float)($_tr['amount'] ?? 0);
            $_isSender = (int)$_tr['from_id'] === $aid;
            $_trDesc = ($_tr['transfer_no'] ?? '') . ' — ' . ($_isSender ? 'To ' . ($_tr['to_name']??'') : 'From ' . ($_tr['from_name']??''));
            if (!empty($_tr['description'])) $_trDesc .= ' (' . $_tr['description'] . ')';
            $sc_ledger[] = ['date'=>substr($_tr['submitted_at'] ?? date('Y-m-d'), 0, 10),'dir'=>$_isSender?'out':'in','cur'=>$_trCur,'amt'=>$_trCur==='USD'?$_trAmt:0,'ssp'=>$_trCur==='SSP'?$_trAmt:0,'cat'=>$_isSender?'Transfer Out':'Transfer In','desc'=>$_trDesc,'status'=>'approved','src'=>'transfer','rid'=>(int)($_tr['id']??0),'auto'=>false,'photo'=>'','person'=>$_isSender?($_tr['to_name']??''):($_tr['from_name']??'')];
        }
    } catch (Throwable $_ae) {}

    // v4.11.38 SINGLE SOURCE OF TRUTH: All three balance displays (admin card, admin detail,
    // field register) now call StaffCashPositionService — one class, one calculation.
    // If the balance logic ever needs to change, change StaffCashPositionService ONLY.
    // DO NOT re-introduce inline balance calculations here — they will diverge again.
    // v4.21.109 SINGLE SOURCE OF TRUTH: USD now goes through getUSDBalance() so
    // admin and staff_portal can never drift. cash_exposure (collection-only)
    // is still available via getPosition() for Staff Cash Control.
    $sc_usd = round($_jsonCpSvc->getUSDBalance($aid), 2);
    $sc_ssp = max(0, (int)$_jsonCpSvc->getSSPBalance($aid));

    // Keep sc_ledger derivations for CSV export and cross-check reference only
    $_allUsd = array_values(array_filter($sc_ledger, fn($r) => $r['cur'] === 'USD'));
    $_allUsdIn  = array_sum(array_column(array_values(array_filter($_allUsd, fn($r) => $r['dir'] === 'in'  && !in_array($r['status'], ['voided','cancelled','rejected','reverted']))), 'amt'));
    $_allUsdOut = array_sum(array_column(array_values(array_filter($_allUsd, fn($r) => $r['dir'] === 'out' && !in_array($r['status'], ['voided','cancelled','rejected','reverted']))), 'amt'));
    $_allSsp    = array_values(array_filter($sc_ledger, fn($r) => $r['cur'] === 'SSP'));
    $_allSspIn  = array_sum(array_column(array_values(array_filter($_allSsp, fn($r) => $r['dir'] === 'in'  && !in_array($r['status'], ['voided','cancelled','rejected','reverted']))), 'ssp'));
    $_allSspOut = array_sum(array_column(array_values(array_filter($_allSsp, fn($r) => $r['dir'] === 'out' && !in_array($r['status'], ['voided','cancelled','rejected','reverted']))), 'ssp'));

    $sc_ledger=array_values(array_filter($sc_ledger,fn($r)=>$r['date']>=$fFrom&&$r['date']<=$fTo));
    usort($sc_ledger,fn($a,$b)=>strcmp($b['datetime']??$b['date'],$a['datetime']??$a['date']));
    $sc_pend=count(array_filter($sc_ledger,fn($r)=>$r['status']==='pending'));
}

// Split by currency
$uL=array_values(array_filter($sc_ledger,fn($r)=>$r['cur']==='USD'));
$sL=array_values(array_filter($sc_ledger,fn($r)=>$r['cur']==='SSP'));
$uIn=array_sum(array_column(array_values(array_filter($uL,fn($r)=>$r['dir']==='in'&&!in_array($r['status'],['voided','cancelled','rejected','reverted']))),'amt'));
$uOut=array_sum(array_column(array_values(array_filter($uL,fn($r)=>$r['dir']==='out'&&!in_array($r['status'],['voided','cancelled','rejected','reverted']))),'amt'));
$sIn=array_sum(array_column(array_values(array_filter($sL,fn($r)=>$r['dir']==='in'&&!in_array($r['status'],['voided','cancelled','rejected','reverted']))),'ssp'));
$sOut=array_sum(array_column(array_values(array_filter($sL,fn($r)=>$r['dir']==='out'&&!in_array($r['status'],['voided','cancelled','rejected','reverted']))),'ssp'));
$uPend=count(array_filter($uL,fn($r)=>$r['status']==='pending'));
$sPend=count(array_filter($sL,fn($r)=>$r['status']==='pending'));

// Staff summaries — compute USD, SSP and wallet for each staff member
// v4.11.3 FIX: Direct JSON calculation — same sources as ledger (proven correct by CSV)
$staffSums=[];
// v4.21.109: USD now via getAllUSDBalances() so support_leader / non-collecting
// staff show their operational USD balance on the landing tiles (previously they
// showed $0 because getAllPositions only returns collection-side cash_exposure).
$_allPositions = $_jsonCpSvc->getAllPositions();
$_allSspBals   = $_jsonCpSvc->getAllSSPBalances();
$_allUsdBals   = $_jsonCpSvc->getAllUSDBalances();
foreach($fieldStaff as $fs){
    $fid=(int)$fs['id'];

    // Pull from ledger position (same as inside detail view)
    $_fPos  = $_allPositions[$fid] ?? null;
    // USD: prefer getUSDBalance result; fall back to cash_exposure for staff
    // who DO have collection exposure but no USD-Received row yet.
    $_fUsd  = isset($_allUsdBals[$fid]) ? round((float)$_allUsdBals[$fid], 2)
            : ($_fPos ? round((float)$_fPos['cash_exposure'], 2) : 0);
    $_fSsp  = (int)($_allSspBals[$fid] ?? 0);
    $staffSums[$fid]=[
        'name'   => $fs['name']??'',
        'role'   => ucfirst(str_replace('_',' ',$fs['role']??'')),
        'usd'    => $_fUsd,
        'ssp'    => $_fSsp,
        'pend'   => 0,
        'wallet' => round($walSvc->getBalance($fid),2),
    ];
}
// Sort: staff with money (any balance) first, then alphabetical
uasort($staffSums, function($a, $b) {
    $aHas = ($a['usd'] != 0 || $a['ssp'] > 0 || $a['wallet'] > 0) ? 1 : 0;
    $bHas = ($b['usd'] != 0 || $b['ssp'] > 0 || $b['wallet'] > 0) ? 1 : 0;
    if ($aHas !== $bHas) return $bHas - $aHas;
    return strcmp($a['name'], $b['name']);
});function scM(float $n):string{return($n<0?'-':'').'$'.number_format(abs($n),2);}
function scCatIc(string $c):string{$m=['Collection'=>'💰','Expense'=>'🧾','Handover'=>'🤝','SSP Received'=>'🇸🇸','Exchange'=>'🔄','Staff Payment'=>'👤','Fuel'=>'⛽','Transport'=>'🚗','Commission'=>'🤝','Refund'=>'↩️','Power'=>'⚡','Vehicle'=>'🚗'];return $m[$c]??'📝';}

// Currency tab: usd (default) or ssp
$curTab=$_GET['sc_cur']??'usd';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
:root{--red:#D41C1C;--dark:#0f0f0f;--green:#059669;--amber:#d97706;--blue:#1e40af;--mute:#94a3b8;--bg:#f5f5f2;--card:#fff;--border:#e8e8e3;--font:'Inter',-apple-system,'Segoe UI',sans-serif;}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent;}
.cb3-wrap{display:flex;flex-direction:column;min-height:calc(100dvh - 56px);background:var(--bg);padding-bottom:80px;font-family:var(--font);}
.cb3-bar{background:var(--dark);padding:0 14px;display:flex;align-items:center;gap:10px;height:52px;position:sticky;top:0;z-index:200;}
.cb3-title{font-size:17px;font-weight:800;color:#fff;letter-spacing:.3px;flex:1;}
.cb3-hero{background:var(--dark);padding:20px 18px 18px;position:relative;overflow:hidden;}
.cb3-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:200px;height:200px;background:var(--red);opacity:.07;border-radius:50%;}
.cb3-hero-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.35);margin-bottom:6px;}
.cb3-hero-bal{font-size:46px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1;margin-bottom:4px;}
.cb3-hero-bal span{font-size:24px;font-weight:600;color:rgba(255,255,255,.4);margin-right:2px;}
.cb3-hero-sub{font-size:11px;color:rgba(255,255,255,.3);margin-top:6px;}
.cb3-hero-pills{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap;}
.cb3-pill{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:20px;font-size:10px;font-weight:700;}
.cb3-pill-g{background:rgba(5,150,105,.15);color:#4ade80;}
.cb3-pill-r{background:rgba(212,28,28,.15);color:#fca5a5;}
.cb3-pill-a{background:rgba(217,119,6,.15);color:#fcd34d;}
.cb3-stats{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);}
.cb3-stat{background:#fff;padding:14px 16px;}
.cb3-stat-lbl{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:var(--mute);margin-bottom:4px;}
.cb3-stat-val{font-size:22px;font-weight:900;letter-spacing:-1px;line-height:1;}
.cb3-stat-sub{font-size:10px;color:var(--mute);margin-top:3px;}
.cb3-stat-val.g{color:var(--green);}.cb3-stat-val.r{color:#dc2626;}.cb3-stat-val.a{color:var(--amber);}
.cb3-tabs{background:#fff;border-bottom:2px solid var(--border);display:flex;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;}
.cb3-tabs::-webkit-scrollbar{display:none;}
.cb3-tab{padding:11px 14px;font-size:12px;font-weight:600;color:var(--mute);white-space:nowrap;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:4px;}
.cb3-tab.on{color:var(--red);border-bottom-color:var(--red);}
.cb3-badge{background:#fef3c7;color:#92400e;border-radius:10px;padding:1px 6px;font-size:9px;font-weight:800;}
.cb3-cards{padding:8px 12px;display:flex;flex-direction:column;gap:7px;}
.cb3-card{background:#fff;border-radius:12px;border:1px solid var(--border);overflow:hidden;display:flex;position:relative;}
.cb3-card.pend{border-left:3px solid var(--amber);}
.cb3-card-body{flex:1;padding:10px 12px;min-width:0;}
.cb3-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px;}
.cb3-card-desc{font-size:13px;font-weight:600;color:#0f0f0f;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;}
.cb3-card-amt{font-size:14px;font-weight:700;white-space:nowrap;flex-shrink:0;}
.cb3-card-amt.in{color:var(--green);}.cb3-card-amt.out{color:#dc2626;}
.cb3-card-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
.cb3-card-date{font-size:11px;color:var(--mute);font-weight:500;}
.cb3-card-cat{font-size:10px;background:#f1f5f9;color:#374151;padding:2px 7px;border-radius:10px;font-weight:500;}
.cb3-card-acts{display:flex;gap:4px;margin-top:6px;}
.cb3-pip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:800;}
.cb3-pip.approved{background:#dcfce7;color:#166534;}.cb3-pip.pending{background:#fef3c7;color:#92400e;}
.cb3-pip.rejected,.cb3-pip.voided,.cb3-pip.cancelled{background:#fee2e2;color:#991b1b;}.cb3-pip.auto{background:#e0f2fe;color:#0369a1;}
.cb3-ab{border:none;border-radius:8px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;}
.cb3-ab.ok{background:#059669;color:#fff;}.cb3-ab.ed{background:#3b82f6;color:#fff;}.cb3-ab.dl{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}
/* Staff picker — redesigned tiles */
.sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;padding:14px;}
.sc-cd{background:#fff;border:1.5px solid var(--border);border-radius:14px;padding:14px 14px 12px;cursor:pointer;transition:.15s;text-decoration:none;display:block;color:inherit;position:relative;}
.sc-cd:hover{border-color:#94a3b8;box-shadow:0 4px 16px rgba(0,0,0,.06);}
.sc-cd.on{border-color:var(--red);background:#fef2f2;}
.sc-cd.has-money{border-color:#a7f3d0;}
.sc-nm{font-size:14px;font-weight:800;color:#0f0f0f;margin-bottom:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sc-rl{font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;}
.sc-bv{font-size:20px;font-weight:900;letter-spacing:-.5px;}
.sc-bv.p{color:var(--green);}.sc-bv.n{color:#dc2626;}.sc-bv.z{color:#cbd5e1;}
.sc-bdg{background:var(--red);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:800;margin-left:4px;vertical-align:middle;}
.sc-sub-row{display:flex;gap:12px;margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9;flex-wrap:wrap;}
.sc-sub-item{display:flex;flex-direction:column;gap:1px;min-width:0;}
.sc-sub-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;}
.sc-sub-val{font-size:13px;font-weight:800;letter-spacing:-.3px;}
.sc-sub-val.ssp{color:#c2410c;}
.sc-sub-val.wal{color:#6d28d9;}
.sc-sub-val.zero{color:#e2e8f0;}
/* Desktop table */
@media(min-width:700px){.cb3-cards{display:none;}.sc-tbl-w{display:block!important;}}
.sc-tbl-w{display:none;overflow-x:auto;background:#fff;}
.sc-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.sc-tbl th{padding:10px 12px;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;background:#f8f8f5;border-bottom:1.5px solid var(--border);text-align:left;}
.sc-tbl td{padding:10px 12px;border-bottom:1px solid #f5f5f2;vertical-align:middle;}
.sc-tbl tr:hover td{background:#fafaf8;}
.sc-fdr{display:flex;gap:8px;flex-wrap:wrap;padding:10px 14px;background:#fff;border-bottom:1px solid var(--border);}
.sc-fdr input{border:1.5px solid var(--border);border-radius:8px;padding:7px 10px;font-size:12px;background:#fff;color:#0a0a0a;outline:none;font-family:var(--font);}
.sc-fdr input:focus{border-color:var(--red);}
@media(max-width:700px){.sc-grid{grid-template-columns:repeat(2,1fr);gap:8px;padding:10px;}.sc-nm{font-size:13px;}.sc-bv{font-size:17px;}.sc-sub-val{font-size:12px;}}
/* View toggle buttons */
.sc-vbtn{border:none;background:transparent;padding:6px 12px;font-size:12px;font-weight:700;color:#94a3b8;cursor:pointer;font-family:var(--font);transition:.15s;}
.sc-vbtn.on{background:var(--dark);color:#fff;}
.sc-vbtn:hover:not(.on){background:#f1f5f9;color:#374151;}
</style>

<div class="cb3-wrap">

<?php if ($scMsg): ?>
<div style="background:<?=$scOk?'#dcfce7':'#fee2e2'?>;border:1px solid <?=$scOk?'#86efac':'#fca5a5'?>;border-radius:12px;padding:12px 16px;margin:12px;font-size:13px;font-weight:700;color:<?=$scOk?'#166534':'#dc2626'?>;"><?=htmlspecialchars($scMsg)?></div>
<?php endif; ?>

<!-- ══ Top Bar ══ -->
<div class="cb3-bar">
  <span class="cb3-title">📋 Staff Cashbooks</span>
</div>

<?php if (!$selStaff): ?>
<!-- ══ Staff Picker ══ -->
<?php
  // Compute totals
  $_totUsd = $_totSsp = $_totWal = $_cntBal = 0;
  foreach ($staffSums as $_ts) {
      $_totUsd += $_ts['usd']; $_totSsp += $_ts['ssp']; $_totWal += $_ts['wallet'];
      if ($_ts['usd'] != 0 || $_ts['ssp'] > 0 || $_ts['wallet'] > 0) $_cntBal++;
  }
  $_cntAll = count($staffSums);
?>

<!-- Summary Bar -->
<div style="display:flex;gap:16px;padding:12px 14px;background:#fff;border-bottom:1px solid var(--border);flex-wrap:wrap;align-items:center;">
  <div style="font-size:11px;color:#64748b;">
    <span style="font-weight:800;color:#0f0f0f;"><?=$_cntBal?></span> of <?=$_cntAll?> staff holding cash
  </div>
  <div style="display:flex;gap:14px;margin-left:auto;flex-wrap:wrap;">
    <div style="font-size:11px;"><span style="color:#94a3b8;">USD</span> <span style="font-weight:800;color:<?=$_totUsd>=0?'var(--green)':'#dc2626'?>;"><?=scM($_totUsd)?></span></div>
    <?php if($_totSsp>0):?><div style="font-size:11px;"><span style="color:#94a3b8;">SSP</span> <span style="font-weight:800;color:#c2410c;"><?=number_format($_totSsp,0)?></span></div><?php endif;?>
    <?php if($_totWal>0):?><div style="font-size:11px;"><span style="color:#94a3b8;">Wallet</span> <span style="font-weight:800;color:#6d28d9;"><?=scM($_totWal)?></span></div><?php endif;?>
  </div>
</div>

<!-- Toolbar: View Toggle + Filter -->
<div style="display:flex;gap:6px;padding:10px 14px;background:#f8f8f5;border-bottom:1px solid var(--border);align-items:center;flex-wrap:wrap;">
  <div style="display:flex;border:1.5px solid var(--border);border-radius:8px;overflow:hidden;background:#fff;">
    <button onclick="scView('grid')" id="scBtnGrid" class="sc-vbtn on" title="Tile view">▦</button>
    <button onclick="scView('list')" id="scBtnList" class="sc-vbtn" title="List view">☰</button>
  </div>
  <div style="display:flex;border:1.5px solid var(--border);border-radius:8px;overflow:hidden;background:#fff;margin-left:4px;">
    <button onclick="scFilter('all')" id="scFAll" class="sc-vbtn on">All (<?=$_cntAll?>)</button>
    <button onclick="scFilter('bal')" id="scFBal" class="sc-vbtn">With Balance (<?=$_cntBal?>)</button>
  </div>
  <input type="text" id="scSearch" placeholder="Search staff…" oninput="scDoSearch(this.value)" style="margin-left:auto;border:1.5px solid var(--border);border-radius:8px;padding:6px 10px;font-size:12px;background:#fff;font-family:var(--font);width:160px;outline:none;">
</div>

<!-- GRID VIEW (tiles) -->
<div class="sc-grid" id="scGridView">
<?php foreach($staffSums as $sid=>$ss):
  $bc=$ss['usd']>0?'p':($ss['usd']<0?'n':'z');
  $hasMoney = ($ss['usd'] != 0 || $ss['ssp'] > 0 || $ss['wallet'] > 0);
?>
<a href="?page=dashboard&tab=staff_cashbooks&sc_staff=<?=$sid?>" class="sc-cd<?=$hasMoney?' has-money':''?>" data-name="<?=htmlspecialchars(strtolower($ss['name']))?>" data-bal="<?=$hasMoney?'1':'0'?>">
  <div class="sc-nm"><?=htmlspecialchars($ss['name'])?><?php if($ss['pend']>0):?><span class="sc-bdg"><?=$ss['pend']?></span><?php endif;?></div>
  <div class="sc-rl"><?=htmlspecialchars($ss['role'])?></div>
  <div class="sc-bv <?=$bc?>"><?=scM($ss['usd'])?></div>
  <?php if($ss['ssp'] > 0 || $ss['wallet'] > 0): ?>
  <div class="sc-sub-row">
    <?php if($ss['ssp'] > 0): ?>
    <div class="sc-sub-item">
      <div class="sc-sub-lbl">🇸🇸 SSP</div>
      <div class="sc-sub-val ssp"><?=number_format($ss['ssp'],0)?></div>
    </div>
    <?php endif; ?>
    <?php if($ss['wallet'] > 0): ?>
    <div class="sc-sub-item">
      <div class="sc-sub-lbl">💳 Wallet</div>
      <div class="sc-sub-val wal"><?=scM($ss['wallet'])?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</a>
<?php endforeach; ?>
</div>

<!-- LIST VIEW (table) -->
<div id="scListView" style="display:none;overflow-x:auto;background:#fff;">
<table style="width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font);">
  <thead>
    <tr style="background:#f8f8f5;">
      <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Staff</th>
      <th style="padding:10px 14px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">USD Cash</th>
      <th style="padding:10px 14px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">SSP Bag</th>
      <th style="padding:10px 14px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Wallet</th>
      <th style="padding:10px 14px;text-align:center;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Pending</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($staffSums as $sid=>$ss):
    $hasMoney = ($ss['usd'] != 0 || $ss['ssp'] > 0 || $ss['wallet'] > 0);
  ?>
    <tr class="sc-list-row" data-name="<?=htmlspecialchars(strtolower($ss['name']))?>" data-bal="<?=$hasMoney?'1':'0'?>" onclick="window.location='?page=dashboard&tab=staff_cashbooks&sc_staff=<?=$sid?>'" style="cursor:pointer;border-bottom:1px solid #f1f5f9;<?=$hasMoney?'':'opacity:.5;'?>">
      <td style="padding:10px 14px;">
        <div style="font-weight:800;color:#0f0f0f;font-size:13px;"><?=htmlspecialchars($ss['name'])?></div>
        <div style="font-size:10px;color:#94a3b8;font-weight:600;text-transform:uppercase;"><?=htmlspecialchars($ss['role'])?></div>
      </td>
      <td style="padding:10px 14px;text-align:right;font-weight:900;font-size:14px;color:<?=$ss['usd']>0?'var(--green)':($ss['usd']<0?'#dc2626':'#cbd5e1')?>;"><?=scM($ss['usd'])?></td>
      <td style="padding:10px 14px;text-align:right;font-weight:800;font-size:13px;color:<?=$ss['ssp']>0?'#c2410c':'#e2e8f0'?>;"><?=$ss['ssp']>0?number_format($ss['ssp'],0):'—'?></td>
      <td style="padding:10px 14px;text-align:right;font-weight:800;font-size:13px;color:<?=$ss['wallet']>0?'#6d28d9':'#e2e8f0'?>;"><?=$ss['wallet']>0?scM($ss['wallet']):'—'?></td>
      <td style="padding:10px 14px;text-align:center;"><?php if($ss['pend']>0):?><span style="background:#fef3c7;color:#92400e;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:800;"><?=$ss['pend']?></span><?php else:?><span style="color:#e2e8f0;">—</span><?php endif;?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f8f8f5;border-top:2px solid var(--border);">
      <td style="padding:10px 14px;font-weight:900;font-size:12px;color:#0f0f0f;">TOTAL (<?=$_cntAll?> staff)</td>
      <td style="padding:10px 14px;text-align:right;font-weight:900;font-size:14px;color:<?=$_totUsd>=0?'var(--green)':'#dc2626'?>;"><?=scM($_totUsd)?></td>
      <td style="padding:10px 14px;text-align:right;font-weight:900;font-size:13px;color:#c2410c;"><?=number_format($_totSsp,0)?></td>
      <td style="padding:10px 14px;text-align:right;font-weight:900;font-size:13px;color:#6d28d9;"><?=scM($_totWal)?></td>
      <td></td>
    </tr>
  </tfoot>
</table>
</div>

<script>
var _scView='grid', _scFilter='all', _scSearch='';
function scView(v){
  _scView=v;
  document.getElementById('scGridView').style.display = v==='grid'?'':'none';
  document.getElementById('scListView').style.display = v==='list'?'':'none';
  document.getElementById('scBtnGrid').className = 'sc-vbtn'+(v==='grid'?' on':'');
  document.getElementById('scBtnList').className = 'sc-vbtn'+(v==='list'?' on':'');
  scApply();
}
function scFilter(f){
  _scFilter=f;
  document.getElementById('scFAll').className = 'sc-vbtn'+(f==='all'?' on':'');
  document.getElementById('scFBal').className = 'sc-vbtn'+(f==='bal'?' on':'');
  scApply();
}
function scDoSearch(q){ _scSearch=q.toLowerCase().trim(); scApply(); }
function scApply(){
  // Grid items
  var cards=document.querySelectorAll('#scGridView .sc-cd');
  cards.forEach(function(c){
    var show=true;
    if(_scFilter==='bal' && c.getAttribute('data-bal')!=='1') show=false;
    if(_scSearch && c.getAttribute('data-name').indexOf(_scSearch)===-1) show=false;
    c.style.display=show?'':'none';
  });
  // List rows
  var rows=document.querySelectorAll('.sc-list-row');
  rows.forEach(function(r){
    var show=true;
    if(_scFilter==='bal' && r.getAttribute('data-bal')!=='1') show=false;
    if(_scSearch && r.getAttribute('data-name').indexOf(_scSearch)===-1) show=false;
    r.style.display=show?'':'none';
  });
}
</script>

<?php else: ?>
<!-- ══ Selected Staff Cashbook ══ -->

<!-- Hero Card -->
<div class="cb3-hero">
  <div class="cb3-hero-lbl"><?=htmlspecialchars($selStaff['name']??'')?> — <?=htmlspecialchars(ucfirst(str_replace('_',' ',$selStaff['role']??'')))?></div>
  <?php if ($curTab==='ssp'): ?>
  <div class="cb3-hero-bal"><?=number_format($sc_ssp,0)?> <span>SSP</span></div>
  <div class="cb3-hero-sub">≈ <?=scM($sysRate>0?$sc_ssp/$sysRate:0)?> @ <?=number_format($sysRate,0)?> · <?=htmlspecialchars($selStaff['phone']??'')?></div>
  <?php else: ?>
  <?php
    // v4.11.3 FIX: Hero card must show ALL-TIME cash position (same as Diko sees)
    // $uIn/$uOut are date-filtered (last 30 days). $sc_usd is from StaffCashPositionService = ALL TIME.
    $_uHovOut = array_sum(array_column(array_values(array_filter($uL, fn($r) => $r['cat'] === 'Handover' && $r['dir'] === 'out' && !in_array($r['status'], ['voided','cancelled','rejected','reverted']))), 'amt'));
    $_uCashWithStaff = $sc_usd;  // ALL-TIME from StaffCashPositionService (matches Diko's view)
  ?>
  <div class="cb3-hero-bal"><span>$</span><?=number_format(abs($_uCashWithStaff),2)?></div>
  <div class="cb3-hero-sub"><?=$_uCashWithStaff>0?'⚠ Cash still with staff':'Cash settled'?> · <?=htmlspecialchars($selStaff['phone']??'')?></div>
  <?php endif; ?>
  <div class="cb3-hero-pills">
    <span class="cb3-pill cb3-pill-g">▲ <?=$curTab==='ssp'?number_format($sIn,0).' SSP':scM($uIn)?> received</span>
    <span class="cb3-pill cb3-pill-r">▼ <?=$curTab==='ssp'?number_format($sOut,0).' SSP':scM($uOut)?> out</span>
    <?php if(($curTab==='usd'?$uPend:$sPend)>0):?><span class="cb3-pill cb3-pill-a"><?=$curTab==='usd'?$uPend:$sPend?> pending</span><?php endif;?>
    <?php if($sc_wallet>0):?><span class="cb3-pill" style="background:rgba(109,40,217,.15);color:#c4b5fd;">💳 Wallet: <?=scM($sc_wallet)?></span><?php endif;?>
  </div>
</div>

<!-- Stat Grid -->
<div class="cb3-stats">
  <div class="cb3-stat">
    <div class="cb3-stat-lbl"><?=$curTab==='ssp'?'SSP':'USD'?> collected</div>
    <div class="cb3-stat-val g"><?=$curTab==='ssp'?number_format($sIn,0):scM($uIn)?></div>
  </div>
  <div class="cb3-stat">
    <div class="cb3-stat-lbl">Handed over</div>
    <div class="cb3-stat-val" style="color:#2563eb;"><?=$curTab==='ssp'?number_format($sOut,0):scM($_uHovOut ?? $uOut)?></div>
    <div class="cb3-stat-sub">Given to accounts</div>
  </div>
  <div class="cb3-stat">
    <div class="cb3-stat-lbl" style="color:#dc2626;">💰 Cash with staff</div>
    <div class="cb3-stat-val" style="color:<?=($_uCashWithStaff??0)>0?'#dc2626':'#059669'?>;"><?=$curTab==='ssp'?number_format($sc_ssp,0):scM($_uCashWithStaff ?? ($uIn-$uOut))?></div>
    <div class="cb3-stat-sub"><?=($_uCashWithStaff??0)>0?'Needs handover':'All settled ✓'?></div>
  </div>
  <div class="cb3-stat">
    <div class="cb3-stat-lbl">💳 Wallet balance</div>
    <div class="cb3-stat-val" style="color:#6d28d9;"><?=scM($sc_wallet)?></div>
    <div class="cb3-stat-sub">Prepaid credit</div>
  </div>
</div>

<!-- Currency Tabs -->
<div class="cb3-tabs">
  <a href="?page=dashboard&tab=staff_cashbooks&sc_staff=<?=$selId?>&sc_cur=usd<?=!empty($fFrom)?'&sc_from='.urlencode($fFrom):''?><?=!empty($fTo)?'&sc_to='.urlencode($fTo):''?>"
     class="cb3-tab <?=$curTab==='usd'?'on':''?>">💵 USD Cashbook
    <?php if($uPend>0):?><span class="cb3-badge"><?=$uPend?></span><?php endif;?></a>
  <a href="?page=dashboard&tab=staff_cashbooks&sc_staff=<?=$selId?>&sc_cur=ssp<?=!empty($fFrom)?'&sc_from='.urlencode($fFrom):''?><?=!empty($fTo)?'&sc_to='.urlencode($fTo):''?>"
     class="cb3-tab <?=$curTab==='ssp'?'on':''?>">🇸🇸 SSP Cashbook
    <?php if($sPend>0):?><span class="cb3-badge"><?=$sPend?></span><?php endif;?></a>
  <a href="?page=dashboard&tab=staff_cashbooks" class="cb3-tab" style="margin-left:auto;font-size:11px;">← All Staff</a>
</div>

<!-- Date Filter -->
<div class="sc-fdr">
  <form method="GET" style="display:contents;">
    <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="staff_cashbooks">
    <input type="hidden" name="sc_staff" value="<?=$selId?>"><input type="hidden" name="sc_cur" value="<?=$curTab?>">
    <input type="date" name="sc_from" value="<?=htmlspecialchars($fFrom)?>">
    <input type="date" name="sc_to" value="<?=htmlspecialchars($fTo)?>">
    <button type="submit" style="padding:7px 14px;background:var(--dark);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;">Filter</button>
  </form>
  <a href="?page=dashboard&tab=staff_cashbooks&sc_staff=<?=$selId?>&sc_cur=<?=$curTab?>&sc_from=<?=urlencode($fFrom)?>&sc_to=<?=urlencode($fTo)?>&sc_export=csv"
     style="padding:7px 14px;background:#fff;border:1.5px solid var(--border);border-radius:8px;font-size:12px;font-weight:700;color:#374151;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
    ↓ CSV
  </a>
  <?php if($isAcctMgr && $selStaff): ?>
  <button onclick="scAddManual()" style="padding:7px 14px;background:#059669;border:none;border-radius:8px;font-size:12px;font-weight:700;color:#fff;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
    ➕ Manual Entry
  </button>
  <button onclick="scOpenExchange()" style="padding:7px 14px;background:#7c3aed;border:none;border-radius:8px;font-size:12px;font-weight:700;color:#fff;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:4px;">
    💱 Convert Currency
  </button>
  <?php endif; ?>
</div>

<?php
$activeL = ($curTab==='ssp') ? $sL : $uL;
$isSSP   = ($curTab==='ssp');
if (empty($activeL)): ?>
<div style="padding:40px 20px;text-align:center;color:var(--mute);">
  <div style="font-size:32px;margin-bottom:8px;">📭</div>
  <div style="font-size:14px;font-weight:700;">No <?=$isSSP?'SSP':'USD'?> entries</div>
  <div style="font-size:12px;margin-top:4px;">for <?=htmlspecialchars($fFrom)?> to <?=htmlspecialchars($fTo)?></div>
</div>
<?php else: ?>

<!-- Mobile: Card Layout -->
<div class="cb3-cards">
<?php foreach($activeL as $row):
  $isIn=$row['dir']==='in'; $st=$row['status']??'approved';
  $ad=$isSSP?number_format($row['ssp']??0,0).' SSP':'$'.number_format($row['amt'],2);
  $isE=($row['src']==='expense'); $isP=($st==='pending'); $rid=(int)($row['rid']??0);
  $dead=in_array($st,['voided','cancelled','reverted']);
?>
<div class="cb3-card <?=$isP?'pend':''?>" style="<?=$dead?'opacity:.5;':'';?>">
  <div class="cb3-card-body">
    <div class="cb3-card-top">
      <div class="cb3-card-desc"><?=scCatIc($row['cat'])?> <?=htmlspecialchars($row['desc']?:$row['cat'])?></div>
      <div class="cb3-card-amt <?=$isIn?'in':'out'?>"><?=($isIn?'+':'-').$ad?></div>
    </div>
    <div class="cb3-card-meta">
      <span class="cb3-card-date"><?=date('d M Y',strtotime($row['date']))?><?php if(!empty($row['datetime'])&&strlen($row['datetime'])>10): ?> <span style="color:var(--mute);font-size:10px;"><?=date('H:i',strtotime($row['datetime']))?></span><?php endif; ?><?php if(!empty($row['entry_date'])&&$row['entry_date']!==$row['date']): ?> <span style="color:#f59e0b;font-size:10px;" title="Expense entered for this date">📅 <?=date('d M',strtotime($row['entry_date']))?></span><?php endif; ?></span>
      <span class="cb3-card-cat"><?=htmlspecialchars($row['cat'])?></span>
      <?php if($row['auto']):?><span class="cb3-pip auto">⚡ Auto</span>
      <?php else:?><span class="cb3-pip <?=$st?>"><?=ucfirst($st)?></span><?php endif;?>
      <?php if($row['photo']):?><a href="javascript:void(0)" onclick="dnLbOpen('?page=api&action=expense_photo&id=<?=(int)$row['rid']?>')" style="font-size:11px;color:#3b82f6;text-decoration:none;cursor:pointer;" title="View receipt">📎 Receipt</a><?php endif;?>
    </div>
    <?php if($isE&&$rid>0&&!$dead):?>
    <div class="cb3-card-acts">
      <?php if($isP):?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Approve?')"><?=csrfField()?><input type="hidden" name="sc_action" value="quick_approve"><input type="hidden" name="expense_id" value="<?=$rid?>"><button type="submit" class="cb3-ab ok">✓ Approve</button></form>
        <button class="cb3-ab ed" onclick="scEdit(<?=$rid?>,<?=htmlspecialchars(json_encode($row['desc']),ENT_QUOTES)?>,<?=$isSSP?($row['ssp']??0):$row['amt']?>,<?=htmlspecialchars(json_encode($row['cat']),ENT_QUOTES)?>)">✎ Edit</button>
      <?php endif;?>
      <button class="cb3-ab dl" onclick="scVoid(<?=$rid?>,<?=htmlspecialchars(json_encode($ad.' — '.($row['desc']?:$row['cat'])),ENT_QUOTES)?>)">✕ Void</button>
    </div>
    <?php elseif($row['src']==='cash_in'&&$rid>0&&!$dead):?>
    <div class="cb3-card-acts">
      <button class="cb3-ab dl" onclick="scVoidCi(<?=$rid?>,<?=htmlspecialchars(json_encode($ad.' — '.($row['desc']?:$row['cat'])),ENT_QUOTES)?>)">✕ Void</button>
    </div>
    <?php elseif($row['src']==='collection'&&$rid>0&&!$dead):?>
    <div class="cb3-card-acts">
      <button class="cb3-ab ed" onclick="scCorrectCol(<?=$rid?>,<?=htmlspecialchars(json_encode($row['desc']?:$row['cat']),ENT_QUOTES)?>,<?=$row['amt']?>)">✎ Correct</button>
      <button class="cb3-ab dl" onclick="scVoidCol(<?=$rid?>,<?=htmlspecialchars(json_encode($ad.' — '.($row['desc']?:$row['cat'])),ENT_QUOTES)?>)">✕ Void</button>
    </div>
    <?php endif;?>
  </div>
</div>
<?php endforeach;?>
</div>

<!-- Desktop: Table Layout -->
<div class="sc-tbl-w">
<table class="sc-tbl">
  <thead><tr><th>Date</th><th>Dir</th><th>Category</th><th>Description</th><th>Amount</th><th>Status</th><th style="min-width:140px;">Actions</th></tr></thead>
  <tbody>
  <?php foreach($activeL as $row):
    $isIn=$row['dir']==='in'; $st=$row['status']??'approved';
    $ad=$isSSP?number_format($row['ssp']??0,0).' SSP':'$'.number_format($row['amt'],2);
    $isE=($row['src']==='expense'); $isP=($st==='pending'); $rid=(int)($row['rid']??0);
    $dead=in_array($st,['voided','cancelled','reverted']);
  ?>
  <tr style="<?=$dead?'opacity:.5;text-decoration:line-through;':''?>">
    <td style="font-weight:700;white-space:nowrap;"><?=date('d M',strtotime($row['date']))?><?php if(!empty($row['datetime'])&&strlen($row['datetime'])>10): ?><br><span style="font-size:10px;font-weight:400;color:#94a3b8;"><?=date('H:i',strtotime($row['datetime']))?></span><?php endif; ?><?php if(!empty($row['entry_date'])&&$row['entry_date']!==$row['date']): ?><br><span style="font-size:9px;color:#f59e0b;" title="Entered for">📅<?=date('d M',strtotime($row['entry_date']))?></span><?php endif; ?></td>
    <td><span style="background:<?=$isIn?'#dcfce7':'#fee2e2'?>;color:<?=$isIn?'#166534':'#991b1b'?>;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:800;"><?=$isIn?'▲ IN':'▼ OUT'?></span></td>
    <td style="font-weight:700;"><?=scCatIc($row['cat'])?> <?=htmlspecialchars($row['cat'])?></td>
    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($row['desc'])?>"><?=htmlspecialchars($row['desc']?:'—')?><?php if($row['photo']):?> <a href="javascript:void(0)" onclick="dnLbOpen('?page=api&action=expense_photo&id=<?=(int)$row['rid']?>')" style="font-size:11px;color:#3b82f6;text-decoration:none;cursor:pointer;" title="View receipt">📎</a><?php endif;?></td>
    <td style="font-weight:900;color:<?=$isIn?'var(--green)':'#dc2626'?>;white-space:nowrap;"><?=($isIn?'+':'-').$ad?></td>
    <td><?php if($row['auto']):?><span class="cb3-pip auto">⚡ Auto</span><?php else:?><span class="cb3-pip <?=$st?>"><?=ucfirst($st)?></span><?php endif;?></td>
    <td>
      <?php if($isE&&$rid>0&&!$dead):?>
        <?php if($isP):?>
          <form method="POST" style="display:inline;" onsubmit="return confirm('Approve?')"><?=csrfField()?><input type="hidden" name="sc_action" value="quick_approve"><input type="hidden" name="expense_id" value="<?=$rid?>"><button type="submit" class="cb3-ab ok">✓</button></form>
          <button class="cb3-ab ed" onclick="scEdit(<?=$rid?>,<?=htmlspecialchars(json_encode($row['desc']),ENT_QUOTES)?>,<?=$isSSP?($row['ssp']??0):$row['amt']?>,<?=htmlspecialchars(json_encode($row['cat']),ENT_QUOTES)?>)">✎</button>
        <?php endif;?>
        <button class="cb3-ab dl" onclick="scVoid(<?=$rid?>,<?=htmlspecialchars(json_encode($ad.' — '.($row['desc']?:$row['cat'])),ENT_QUOTES)?>)">✕</button>
      <?php elseif($row['src']==='cash_in'&&$rid>0&&!$dead):?>
        <button class="cb3-ab dl" onclick="scVoidCi(<?=$rid?>,<?=htmlspecialchars(json_encode($ad.' — '.($row['desc']?:$row['cat'])),ENT_QUOTES)?>)">✕</button>
      <?php elseif($row['src']==='collection'&&$rid>0&&!$dead):?>
        <button class="cb3-ab ed" onclick="scCorrectCol(<?=$rid?>,<?=htmlspecialchars(json_encode($row['desc']?:$row['cat']),ENT_QUOTES)?>,<?=$row['amt']?>)">✎</button>
        <button class="cb3-ab dl" onclick="scVoidCol(<?=$rid?>,<?=htmlspecialchars(json_encode($ad.' — '.($row['desc']?:$row['cat'])),ENT_QUOTES)?>)">✕</button>
      <?php else:?><span style="color:#d4d4d4;font-size:11px;">—</span><?php endif;?>
    </td>
  </tr>
  <?php endforeach;?>
  </tbody>
</table>
</div>

<div style="padding:10px 14px;font-size:12px;color:var(--mute);text-align:center;background:#fff;border-top:1px solid var(--border);">
  <?=count($activeL)?> <?=$isSSP?'SSP':'USD'?> entries · <?=htmlspecialchars($fFrom)?> to <?=htmlspecialchars($fTo)?>
</div>
<?php endif;?>
<?php endif;?>
</div>

<!-- ══ Edit Modal ══ -->
<div id="scEM" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px;">
<div style="font-size:18px;font-weight:900;color:#0f0f0f;margin-bottom:16px;">✎ Edit entry</div>
<form method="POST"><?=csrfField()?>
<input type="hidden" name="sc_action" value="edit_entry"><input type="hidden" name="expense_id" id="seId">
<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Amount</label><input type="number" name="edit_amount" id="seAmt" step="0.01" min="0" required style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:18px;font-weight:900;box-sizing:border-box;"></div>
<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Category</label><input type="text" name="edit_category" id="seCat" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"></div>
<div style="margin-bottom:16px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Description</label><input type="text" name="edit_desc" id="seDesc" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"></div>
<div style="display:flex;gap:10px;"><button type="button" onclick="document.getElementById('scEM').style.display='none'" style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button><button type="submit" style="flex:2;background:#3b82f6;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">Save</button></div>
</form></div></div>

<!-- ══ Void Modal ══ -->
<div id="scVM" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px;">
<div style="font-size:18px;font-weight:900;color:#dc2626;margin-bottom:4px;">✕ Void entry</div>
<div style="font-size:13px;color:#64748b;margin-bottom:16px;" id="svInfo"></div>
<form method="POST"><?=csrfField()?>
<input type="hidden" name="sc_action" value="void_entry"><input type="hidden" name="expense_id" id="svId">
<div style="margin-bottom:16px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Reason (required)</label><input type="text" name="void_reason" required placeholder="e.g. Duplicate entry" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"></div>
<div style="display:flex;gap:10px;"><button type="button" onclick="document.getElementById('scVM').style.display='none'" style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button><button type="submit" style="flex:2;background:#dc2626;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">Void</button></div>
</form></div></div>

<!-- ══ Void Cash IN Modal ══ -->
<div id="scVCi" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px;">
<div style="font-size:18px;font-weight:900;color:#dc2626;margin-bottom:4px;">✕ Void Cash Received</div>
<div style="font-size:13px;color:#64748b;margin-bottom:4px;" id="svCiInfo"></div>
<div style="font-size:11px;color:#b91c1c;margin-bottom:16px;">This will remove this entry from the staff member's balance.</div>
<form method="POST"><?=csrfField()?>
<input type="hidden" name="sc_action" value="void_cash_in"><input type="hidden" name="cash_in_id" id="svCiId">
<div style="margin-bottom:16px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Reason (required)</label><input type="text" name="void_reason" required placeholder="e.g. Wrong amount, duplicate entry" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"></div>
<div style="display:flex;gap:10px;"><button type="button" onclick="document.getElementById('scVCi').style.display='none'" style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button><button type="submit" style="flex:2;background:#dc2626;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">Void</button></div>
</form></div></div>

<!-- ══ Void Collection Modal ══ -->
<div id="scVCol" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px;">
<div style="font-size:18px;font-weight:900;color:#dc2626;margin-bottom:4px;">✕ Void Collection</div>
<div style="font-size:13px;color:#64748b;margin-bottom:16px;" id="svColInfo"></div>
<form method="POST"><?=csrfField()?>
<input type="hidden" name="sc_action" value="void_collection"><input type="hidden" name="collection_id" id="svColId">
<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Reason *</label><input type="text" name="void_reason" required placeholder="Why is this being voided?" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"></div>
<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:10px;padding:10px;font-size:12px;color:#991b1b;margin-bottom:16px;">⚠ This will void the collection AND the matching cashbook entry. Wallet is NOT auto-refunded — do that separately if needed.</div>
<div style="display:flex;gap:10px;"><button type="button" onclick="document.getElementById('scVCol').style.display='none'" style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button><button type="submit" style="flex:2;background:#dc2626;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">Void Collection</button></div>
</form></div></div>

<!-- ══ Correct Collection Modal ══ -->
<div id="scCCol" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px;">
<div style="font-size:18px;font-weight:900;color:#3b82f6;margin-bottom:4px;">✎ Correct Collection Amount</div>
<div style="font-size:13px;color:#64748b;margin-bottom:16px;" id="scColDesc"></div>
<form method="POST"><?=csrfField()?>
<input type="hidden" name="sc_action" value="correct_collection"><input type="hidden" name="collection_id" id="scColId">
<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Correct Amount ($)</label><input type="number" name="correct_amount" id="scColAmt" step="0.01" min="0.01" required style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:18px;font-weight:900;box-sizing:border-box;"></div>
<div style="margin-bottom:16px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Reason *</label><input type="text" name="correct_reason" required placeholder="Why is this being corrected?" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"></div>
<div style="display:flex;gap:10px;"><button type="button" onclick="document.getElementById('scCCol').style.display='none'" style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button><button type="submit" style="flex:2;background:#3b82f6;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">Save Correction</button></div>
</form></div></div>

<!-- ══ Manual Entry Modal ══ -->
<div id="scManual" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px;">
<div style="font-size:18px;font-weight:900;color:#059669;margin-bottom:16px;">➕ Add Manual Entry</div>
<form method="POST"><?=csrfField()?>
<input type="hidden" name="sc_action" value="add_manual_entry">
<input type="hidden" name="man_staff_id" value="<?=$selId?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
  <div><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Direction</label><select name="man_direction" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"><option value="in">↑ IN (received)</option><option value="out">↓ OUT (paid)</option></select></div>
  <div><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Currency</label><select name="man_currency" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"><option value="USD">USD</option><option value="SSP">SSP</option></select></div>
</div>
<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Amount</label><input type="number" name="man_amount" step="0.01" min="0.01" required style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:18px;font-weight:900;box-sizing:border-box;"></div>
<div style="margin-bottom:12px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Category</label><select name="man_category" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"><option value="Adjustment">Adjustment</option><option value="Correction">Correction</option><option value="Cash Advance">Cash Advance</option><option value="SSP Received">SSP Received</option><option value="Exchange">Exchange</option><option value="Other">Other</option></select></div>
<div style="margin-bottom:16px;"><label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:4px;">Description / Reason *</label><input type="text" name="man_description" required placeholder="What is this entry for?" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;"></div>
<div style="display:flex;gap:10px;"><button type="button" onclick="document.getElementById('scManual').style.display='none'" style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button><button type="submit" style="flex:2;background:#059669;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">Add Entry</button></div>
</form></div></div>

<!-- ══ Auto-exchange warning banner ══ -->
<?php if (!empty($_scAutoExchanges)): ?>
<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:12px;color:#92400e;">
    <strong>⚠ Recent auto-recorded exchange(s) for <?= htmlspecialchars($_selStaffName ?? 'this staff') ?>:</strong>
    <?php foreach ($_scAutoExchanges as $_ae): ?>
    <div style="margin-top:4px;font-family:monospace;font-size:11px;">
        💱 $<?= number_format((float)$_ae['amount'], 0) ?>
        → <?= number_format((float)($_ae['ssp_amount'] ?? 0), 0) ?> SSP
        @ <?= number_format((float)($_ae['ssp_rate'] ?? 0), 0) ?>
        · <?= substr($_ae['created_at'] ?? '', 11, 5) ?> today
        <span style="background:#fde68a;color:#92400e;border-radius:4px;padding:1px 5px;font-size:9px;font-weight:800;">AUTO</span>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:6px;color:#78350f;font-size:11px;">These were recorded by the staff member's device. <strong>Do not enter them again manually.</strong></div>
</div>
<?php endif; ?>

<!-- ══ Currency Exchange Modal ══ -->
<div id="scExchangeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:20px;padding:24px;width:100%;max-width:420px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
    <span style="font-size:28px;">💱</span>
    <div>
      <div style="font-size:18px;font-weight:900;color:#7c3aed;">Currency Exchange</div>
      <div style="font-size:12px;color:#64748b;">USD ↔ SSP for <?= htmlspecialchars($selStaff['name'] ?? 'Staff') ?></div>
    </div>
  </div>

  <!-- Live position preview -->
  <div style="background:#f8f5ff;border:1px solid #ddd6fe;border-radius:10px;padding:10px 14px;margin:14px 0;display:flex;gap:16px;font-size:13px;flex-wrap:wrap;">
    <div><span style="color:#64748b;">USD bag:</span> <strong id="scEx_usdBal">$<?= number_format($sc_usd, 2) ?></strong></div>
    <div><span style="color:#64748b;">SSP bag:</span> <strong id="scEx_sspBal"><?= number_format($sc_ssp, 0) ?> SSP</strong></div>
    <?php if ($_scLastRate > 0): ?>
    <div><span style="color:#64748b;">Last market rate:</span> <strong><?= number_format($_scLastRate, 0) ?> SSP/$</strong>
      <?php if (!empty($_scExcCtx['last_by'])): ?>
      <span style="color:#94a3b8;font-size:11px;">by <?= htmlspecialchars($_scExcCtx['last_by']) ?></span>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div><span style="color:#64748b;">System rate:</span> <strong><?= number_format($sysRate, 0) ?></strong></div>
    <?php endif; ?>
  </div>

  <form method="POST" id="scExchangeForm"><?= csrfField() ?>
    <input type="hidden" name="sc_action" value="record_exchange">
    <input type="hidden" name="exc_staff_id" value="<?= (int)$selId ?>">

    <!-- Direction toggle -->
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:6px;">Exchange Direction</label>
      <div style="display:flex;gap:8px;">
        <label style="flex:1;display:flex;align-items:center;gap:8px;background:#faf5ff;border:2px solid #7c3aed;border-radius:10px;padding:10px 12px;cursor:pointer;">
          <input type="radio" name="exc_direction" value="usd_to_ssp" checked onchange="scExCalc()"> 💵→🇸🇸 USD to SSP
        </label>
        <label style="flex:1;display:flex;align-items:center;gap:8px;background:#fff;border:2px solid #e2e8f0;border-radius:10px;padding:10px 12px;cursor:pointer;">
          <input type="radio" name="exc_direction" value="ssp_to_usd" onchange="scExCalc()"> 🇸🇸→💵 SSP to USD
        </label>
      </div>
    </div>

    <!-- Amount given -->
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:6px;" id="scEx_amtLbl">USD Amount to Give</label>
      <div style="position:relative;">
        <span id="scEx_amtPfx" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:20px;font-weight:900;color:#7c3aed;">$</span>
        <input type="number" name="exc_amount" id="scEx_amount" step="0.01" min="0.01" required
          oninput="scExCalc()"
          style="width:100%;padding:12px 12px 12px 36px;border:2px solid #ddd6fe;border-radius:10px;font-size:22px;font-weight:900;box-sizing:border-box;color:#1e293b;">
      </div>
      <div style="font-size:11px;color:#94a3b8;margin-top:4px;" id="scEx_amtHint">Max available: $<?= number_format($sc_usd, 2) ?></div>
    </div>

    <!-- Rate — pre-filled from last actual market rate, not system rate -->
    <div style="margin-bottom:14px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:6px;">Exchange Rate (SSP per USD)</label>
      <input type="number" name="exc_rate" id="scEx_rate" step="1" min="1" required
        value="<?= (int)$_scPrefill ?>" oninput="scExCalc()"
        style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:16px;font-weight:700;box-sizing:border-box;">
      <?php
        $_rctBy   = htmlspecialchars($_scExcCtx['last_by'] ?? '');
        $_rctMins = (int)($_scExcCtx['last_minutes_ago'] ?? -1);
        $_rctMin7 = (int)($_scExcCtx['min_7day'] ?? 0);
        $_rctMax7 = (int)($_scExcCtx['max_7day'] ?? 0);
        if ($_rctMins >= 0 && $_rctMins < 1440) {
            $t = $_rctMins < 1 ? 'just now' : ($_rctMins < 60 ? $_rctMins.'m ago' : round($_rctMins/60).'h ago');
            echo '<div style="font-size:11px;color:#64748b;margin-top:3px;">Last market rate: <strong>'.number_format($_scLastRate,0).'</strong> SSP/$ by '.$_rctBy.' · '.$t;
            if ($_rctMin7 > 0) echo ' &nbsp;·&nbsp; 7d range: '.number_format($_rctMin7,0).'–'.number_format($_rctMax7,0);
            echo '</div>';
        } else {
            echo '<div style="font-size:11px;color:#94a3b8;margin-top:3px;">No recent exchange on record — enter current market rate</div>';
        }
      ?>
      <!-- Rate comparison banner — updated live by scExCalc() -->
      <div id="scEx_rateBanner" style="display:none;margin-top:6px;border-radius:8px;padding:7px 10px;font-size:12px;font-weight:600;"></div>
    </div>

    <!-- Live result -->
    <div id="scEx_result" style="background:linear-gradient(135deg,#7c3aed,#4f46e5);border-radius:12px;padding:14px 16px;margin-bottom:14px;color:#fff;text-align:center;display:none;">
      <div style="font-size:12px;opacity:.8;margin-bottom:4px;" id="scEx_resultLbl">You will receive</div>
      <div style="font-size:28px;font-weight:900;" id="scEx_resultAmt">—</div>
      <div style="font-size:11px;opacity:.7;margin-top:4px;" id="scEx_resultFormula">—</div>
    </div>

    <!-- Warning if amount exceeds balance -->
    <div id="scEx_warn" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-size:12px;color:#dc2626;margin-bottom:12px;">
      ⚠️ Amount exceeds current bag balance. Check before proceeding.
    </div>

    <!-- Note -->
    <div style="margin-bottom:16px;">
      <label style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;display:block;margin-bottom:6px;">Note (optional)</label>
      <input type="text" name="exc_note" placeholder="e.g. Exchanged at Juba market" style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:14px;box-sizing:border-box;">
    </div>

    <div style="display:flex;gap:10px;">
      <button type="button" onclick="document.getElementById('scExchangeModal').style.display='none'"
        style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button>
      <button type="submit" id="scEx_submit"
        style="flex:2;background:#7c3aed;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">
        💱 Record Exchange
      </button>
    </div>
  </form>
</div>
</div>

<script>
function scEdit(id,desc,amt,cat){document.getElementById('seId').value=id;document.getElementById('seAmt').value=amt;document.getElementById('seDesc').value=desc;document.getElementById('seCat').value=cat;document.getElementById('scEM').style.display='flex';}
function scVoid(id,info){document.getElementById('svId').value=id;document.getElementById('svInfo').textContent=info;document.getElementById('scVM').style.display='flex';}
function scVoidCi(id,info){document.getElementById('svCiId').value=id;document.getElementById('svCiInfo').textContent=info;document.getElementById('scVCi').style.display='flex';}
function scVoidCol(id,info){document.getElementById('svColId').value=id;document.getElementById('svColInfo').textContent=info;document.getElementById('scVCol').style.display='flex';}
function scCorrectCol(id,desc,amt){document.getElementById('scColId').value=id;document.getElementById('scColDesc').textContent=desc;document.getElementById('scColAmt').value=amt;document.getElementById('scCCol').style.display='flex';}
function scAddManual(){document.getElementById('scManual').style.display='flex';}
function scOpenExchange(){document.getElementById('scExchangeModal').style.display='flex';scExCalc();}

function scExCalc(){
  var dir    = document.querySelector('[name=exc_direction]:checked')?.value || 'usd_to_ssp';
  var amt    = parseFloat(document.getElementById('scEx_amount').value) || 0;
  var rate   = parseFloat(document.getElementById('scEx_rate').value) || <?= (int)$_scPrefill ?>;
  var usdBal = <?= round($sc_usd, 2) ?>;
  var sspBal = <?= round($sc_ssp, 0) ?>;
  var lastRate = <?= (int)$_scLastRate ?>;
  var res    = document.getElementById('scEx_result');
  var resAmt = document.getElementById('scEx_resultAmt');
  var resFrm = document.getElementById('scEx_resultFormula');
  var resLbl = document.getElementById('scEx_resultLbl');
  var warn   = document.getElementById('scEx_warn');
  var lbl    = document.getElementById('scEx_amtLbl');
  var pfx    = document.getElementById('scEx_amtPfx');
  var hint   = document.getElementById('scEx_amtHint');
  var banner = document.getElementById('scEx_rateBanner');

  // ── Rate comparison banner ──────────────────────────────────────────────
  if (banner && lastRate > 0 && rate > 0) {
    var diff = Math.round(rate - lastRate);
    var absDiff = Math.abs(diff);
    var pct = ((absDiff / lastRate) * 100).toFixed(1);
    var bannerText, bannerBg, bannerClr;
    if (absDiff < 10) {
      bannerText = 'Same as last market rate (' + lastRate.toLocaleString() + ' SSP/$)';
      bannerBg = '#f1f5f9'; bannerClr = '#64748b';
    } else if (diff > 0) {
      // Higher rate = more SSP per $ = better for USD→SSP, worse for SSP→USD
      if (dir === 'usd_to_ssp') {
        bannerText = '▲ Better than last rate by ' + absDiff.toLocaleString() + ' SSP/$ (+' + pct + '%) — good deal';
        bannerBg = '#f0fdf4'; bannerClr = '#16a34a';
      } else {
        bannerText = '▲ You give more SSP per $ than last rate (+' + absDiff.toLocaleString() + ' SSP/$) — consider negotiating';
        bannerBg = '#fef9ec'; bannerClr = '#d97706';
      }
    } else {
      if (dir === 'usd_to_ssp') {
        bannerText = '▼ Lower than last rate by ' + absDiff.toLocaleString() + ' SSP/$ (−' + pct + '%) — try to negotiate';
        bannerBg = '#fef9ec'; bannerClr = '#d97706';
      } else {
        bannerText = '▼ Better rate than last exchange — you give less SSP per $';
        bannerBg = '#f0fdf4'; bannerClr = '#16a34a';
      }
    }
    // Plausibility guard: flag if rate differs >30% from last (likely typo)
    if (absDiff / lastRate > 0.3) {
      bannerText = '⚠ Rate ' + Math.round(rate).toLocaleString() + ' differs >30% from last rate (' + lastRate.toLocaleString() + ') — check for typo';
      bannerBg = '#fef2f2'; bannerClr = '#dc2626';
    }
    banner.style.display = 'block';
    banner.style.background = bannerBg;
    banner.style.color = bannerClr;
    banner.textContent = bannerText;
  } else if (banner) {
    banner.style.display = 'none';
  }

  if (dir === 'usd_to_ssp') {
    lbl.textContent  = 'USD Amount to Give';
    pfx.textContent  = '$';
    hint.textContent = 'Max available: $' + usdBal.toFixed(2);
    resLbl.textContent = 'You will receive';
    if (amt > 0 && rate > 0) {
      var ssp = Math.round(amt * rate);
      var sspAtLast = lastRate > 0 ? Math.round(amt * lastRate) : 0;
      resAmt.textContent = ssp.toLocaleString() + ' SSP';
      var formula = '$' + amt.toFixed(2) + ' × ' + Math.round(rate).toLocaleString() + ' = ' + ssp.toLocaleString() + ' SSP';
      if (lastRate > 0 && Math.abs(rate - lastRate) >= 10) {
        formula += ' (vs ' + sspAtLast.toLocaleString() + ' at last rate)';
      }
      resFrm.textContent = formula;
      res.style.display = 'block';
    } else { res.style.display = 'none'; }
    warn.style.display = (amt > usdBal && amt > 0) ? 'block' : 'none';
  } else {
    lbl.textContent  = 'SSP Amount to Give';
    pfx.textContent  = 'S£';
    hint.textContent = 'Max available: ' + Math.round(sspBal).toLocaleString() + ' SSP';
    resLbl.textContent = 'You will receive';
    if (amt > 0 && rate > 0) {
      var usd = amt / rate;
      resAmt.textContent = '$' + usd.toFixed(2);
      resFrm.textContent = Math.round(amt).toLocaleString() + ' SSP ÷ ' + Math.round(rate).toLocaleString() + ' = $' + usd.toFixed(2);
      res.style.display = 'block';
    } else { res.style.display = 'none'; }
    warn.style.display = (amt > sspBal && amt > 0) ? 'block' : 'none';
  }
}
</script>

