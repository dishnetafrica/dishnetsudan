<?php
// ── Handover Queue — Rupesh / Accountant ────────────────────────────────
// Shows pending cash handovers from field agents.
// On confirm: posts Cash IN to cb_ledger + credits agent wallet.
// On reject:  marks rejected with reason, notifies agent.
// -------------------------------------------------------------------------
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}

// Handle reject POST inline (confirm is handled in post_handlers.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_handover') {
    $hovId  = (int)($_POST['handover_id'] ?? 0);
    $reason = trim($_POST['reject_reason'] ?? '');
    if ($hovId > 0 && $reason !== '') {
        $handovers = $store->load('cash_handovers.json') ?: [];
        foreach ($handovers as &$hov) {
            if ((int)($hov['id'] ?? 0) === $hovId && ($hov['status'] ?? '') === 'pending') {
                $hov['status']        = 'rejected';
                $hov['reject_reason'] = $reason;
                $hov['confirmed_by']  = $retailer['name'];
                $hov['confirmed_at']  = date('Y-m-d H:i:s');
                // Notify agent
                try {
                    $agR = $store->findOne('retailers.json', 'id', (int)$hov['from_id']);
                    if ($agR) $notify->remittanceRejected($agR, (float)$hov['amount'], $reason, 0, $hovId);
                } catch (\Throwable $e) {}
                break;
            }
        }
        unset($hov);
        $store->save('cash_handovers.json', $handovers);
    }
    header('Location: ?page=dashboard&tab=handover_queue');
    exit;
}

// ── Admin: Revert a CONFIRMED handover ──────────────────────────────────
// Undoes: wallet credit, collection links, snapshot. No cashbook change
// (handovers don't touch cashbook since v4.9.8).
// v4.9.9: new action — previously there was no way to undo a confirmed handover.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revert_handover') {
    if (!($retailer['is_admin'] ?? false)) {
        flash('Only admin can revert confirmed handovers.', 'danger');
        header('Location: ?page=dashboard&tab=handover_queue');
        exit;
    }
    $hovId  = (int)($_POST['handover_id'] ?? 0);
    $reason = trim($_POST['revert_reason'] ?? '');
    if ($hovId <= 0 || $reason === '') {
        flash('Handover ID and reason are required to revert.', 'danger');
        header('Location: ?page=dashboard&tab=handover_queue');
        exit;
    }

    $handovers = $store->load('cash_handovers.json') ?: [];
    $reverted  = false;

    foreach ($handovers as &$hov) {
        if ((int)($hov['id'] ?? 0) !== $hovId || ($hov['status'] ?? '') !== 'confirmed') continue;

        $fromId   = (int)$hov['from_id'];
        $hovAmt   = (float)$hov['amount'];
        $fromName = $hov['from_name'] ?? 'Agent';

        // 1. Wallet debit — reverse the credit that was given on confirmation
        try {
            $wallet->debit(
                $fromId,
                $hovAmt,
                'Handover HOV-' . $hovId . ' reverted by ' . $retailer['name'] . ' — ' . $reason,
                'REVHOV-' . $hovId,         // reference
                null,                        // applicationId
                '',                          // crmClientId
                'REVHOV-' . $hovId,          // idempotencyKey (prevents double-revert)
                'handover_revert',           // trxType
                $retailer['name']            // createdBy
            );
        } catch (\Throwable $wErr) {
            flash('Revert failed: ' . $wErr->getMessage() . ' (wallet debit rejected — agent may have insufficient balance).', 'danger');
            header('Location: ?page=dashboard&tab=handover_queue');
            exit;
        }

        // 2. Unlink collections that were tied to this handover
        try {
            $allCols = $store->load('payment_collections.json') ?? [];
            $unlinked = 0;
            foreach ($allCols as $idx => $col) {
                if ((int)($col['handover_id'] ?? 0) === $hovId) {
                    unset($allCols[$idx]['handover_id']);
                    unset($allCols[$idx]['handover_receipt']);
                    unset($allCols[$idx]['handover_by']);
                    unset($allCols[$idx]['handover_at']);
                    $unlinked++;
                }
            }
            if ($unlinked > 0) {
                $store->save('payment_collections.json', array_values($allCols));
            }
        } catch (\Throwable $e) {
            logActivity($dataDir, 'revert_handover_unlink_failed', 'Failed to unlink collections', $e->getMessage());
        }

        // 3. Mark handover as reverted
        $hov['status']        = 'reverted';
        $hov['reverted_by']   = $retailer['name'];
        $hov['reverted_at']   = date('Y-m-d H:i:s');
        $hov['revert_reason'] = $reason;

        // 4. Save before snapshot rebuild (critical order)
        $store->save('cash_handovers.json', $handovers);

        // 5. Rebuild snapshot — agent's exposure goes back up
        try {
            if (!class_exists('SnapshotService')) require_once dirname(__DIR__, 2) . '/lib/SnapshotService.php';
            (new SnapshotService($store->getPdo(), $store))->rebuild($fromId, 'handover_revert', 'REVHOV-' . $hovId);
        } catch (\Throwable $snErr) { /* non-fatal — nightly reconcile corrects */ }

        // 5b. Dual-write: staff_ledger
        require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
        StaffLedgerWriter::onHandoverReverted($store->getPdo(), $hovId, $retailer['name']);

        // 6. Log
        logActivity($dataDir, 'handover_reverted', 'Cash handover reverted',
            '$' . number_format($hovAmt, 2) . ' from ' . $fromName .
            ' (HOV-' . $hovId . ') reverted by ' . $retailer['name'] . ' — ' . $reason);

        // 7. Notify agent via WhatsApp
        try {
            $agR = $store->findOne('retailers.json', 'id', $fromId);
            if ($agR && !empty($agR['phone'])) {
                $notify->sendRaw($agR['phone'],
                    "⚠️ *Handover Reverted*\n\n"
                    . "Your handover of \$" . number_format($hovAmt, 2) . " (HOV-{$hovId}) has been *reverted* by " . $retailer['name'] . ".\n\n"
                    . "Reason: " . $reason . "\n\n"
                    . "Your wallet has been debited by \$" . number_format($hovAmt, 2) . ".\n"
                    . "Please contact admin if you have questions.\n\n"
                    . "_DishNet Africa_",
                    'handover_reverted');
            }
        } catch (\Throwable $e) {}

        flash('↩ Handover HOV-' . $hovId . ' of $' . number_format($hovAmt, 2) . ' from ' . $fromName . ' has been reverted. Wallet debited.', 'success');
        $reverted = true;
        break;
    }
    unset($hov);

    if (!$reverted) {
        flash('Handover not found or not in confirmed status.', 'danger');
    }
    header('Location: ?page=dashboard&tab=handover_queue');
    exit;
}

// ── Admin: Record handover on behalf of agent (auto-confirmed) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_record_handover') {
    $agentId   = (int)($_POST['agent_id'] ?? 0);
    $agentName = trim($_POST['agent_name'] ?? '');
    $amount    = round((float)($_POST['amount'] ?? 0), 2);
    $notes     = trim($_POST['notes'] ?? 'Recorded by admin');
    $currency  = strtoupper(trim($_POST['currency'] ?? 'USD'));
    if (!in_array($currency, ['USD', 'SSP'])) $currency = 'USD';

    if ($agentId <= 0 || $amount <= 0) {
        flash('Agent and amount required.', 'danger');
        header('Location: ?page=dashboard&tab=handover_queue');
        exit;
    }

    // Guard: amount can't exceed agent's cash position
    require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php'; // v4.11.38: JSON source
    $_hovMaxPos = (new StaffCashPositionService($store, $store->getPdo()))->getPosition($agentId);
    $_hovMaxCash = round((float)($_hovMaxPos['cash_exposure'] ?? 0), 2);
    if ($amount > $_hovMaxCash + 0.01) {
        flash("Amount \${$amount} exceeds {$agentName}'s cash position of \${$_hovMaxCash}. Enter a smaller amount.", 'danger');
        header('Location: ?page=dashboard&tab=handover_queue');
        exit;
    }

    // 1. Create the handover record (already confirmed)
    $newHovId = $store->nextId('cash_handovers.json');
    $_hovRecord = $store->appendWithId('cash_handovers.json', [
        'from_id'        => $agentId,
        'from_name'      => $agentName,
        'to_id'          => (int)$retailer['id'],
        'to_name'        => $retailer['name'],
        'amount'         => $amount,
        'currency'       => $currency,
        'notes'          => $notes,
        'status'         => 'confirmed',
        'submitted_at'   => date('Y-m-d H:i:s'),
        'confirmed_by'   => $retailer['name'] . ' (admin-recorded)',
        'confirmed_at'   => date('Y-m-d H:i:s'),
        'confirm_notes'  => 'Admin recorded on behalf of agent',
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    // Dual-write: staff_ledger
    require_once dirname(__DIR__, 2) . '/lib/StaffLedgerWriter.php';
    StaffLedgerWriter::onHandoverConfirmed($store->getPdo(), $_hovRecord);

    // 2. Credit agent wallet
    $wallet->credit($agentId, $amount,
        'Cash handover recorded by ' . $retailer['name'] . ' (admin)',
        $retailer['name'], 'HOV-' . $newHovId, 'handover_credit');

    // NOTE: No cashbook entry here — individual payments are already posted
    // to cashbook via crm_webhook / catchup_sync. The handover only tracks
    // the physical cash movement from agent → admin, not new revenue.

    // 3. Link unlinked collections from this agent
    try {
        $allCols = $store->load('payment_collections.json') ?? [];
        $updCount = 0;
        foreach ($allCols as $idx => $col) {
            if ((int)($col['retailer_id'] ?? 0) !== $agentId) continue;
            if (!empty($col['handover_id'])) continue;
            if (empty($col['crm_payment_id'])) continue;
            if (($col['status'] ?? '') === 'voided') continue;

            $allCols[$idx]['handover_id']      = $newHovId;
            $allCols[$idx]['handover_by']       = $retailer['name'];
            $allCols[$idx]['handover_at']       = date('Y-m-d H:i:s');
            $updCount++;
        }
        if ($updCount > 0) $store->save('payment_collections.json', $allCols);
    } catch (\Throwable $e) {}

    logActivity($dataDir, 'admin_handover_recorded', 'Admin recorded handover',
        '$' . number_format($amount, 2) . ' from ' . $agentName . ' by ' . $retailer['name']);

    // 4. Notify agent via WhatsApp
    try {
        $agR = $store->findOne('retailers.json', 'id', $agentId);
        if ($agR && !empty($agR['phone'])) {
            $notify->sendRaw($agR['phone'],
                "✅ Cash handover of \$" . number_format($amount, 2) . " has been recorded by " . $retailer['name'] . ".\n\n"
                . "Receipt: HOV-{$newHovId}\n"
                . "_DishNet Africa_",
                'admin_handover_recorded');
        }
    } catch (\Throwable $e) {}

    $amtDisp = $currency === 'SSP' ? number_format($amount) . ' SSP' : '$' . number_format($amount, 2);
    flash("✅ Handover of {$amtDisp} from {$agentName} recorded and confirmed.", 'success');
    header('Location: ?page=dashboard&tab=handover_queue');
    exit;
}

// ── Admin: Nudge agent to submit handover (WhatsApp) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'nudge_handover') {
    $agentId   = (int)($_POST['agent_id'] ?? 0);
    $agentName = trim($_POST['agent_name'] ?? '');
    $cashAmt   = (float)($_POST['cash_amount'] ?? 0);

    if ($agentId > 0) {
        try {
            $agR = $store->findOne('retailers.json', 'id', $agentId);
            if ($agR && !empty($agR['phone'])) {
                $firstName    = explode(' ', $agentName)[0];
                $holdingSince = trim($_POST['holding_since'] ?? '');
                $holdingLine  = $holdingSince ? " (holding for {$holdingSince})" : '';
                $notify->sendRaw($agR['phone'],
                    "📢 *Cash Handover Reminder*\n\n"
                    . "Hi {$firstName}, you have *\$" . number_format($cashAmt, 2) . "*{$holdingLine} in cash that needs to be handed over.\n\n"
                    . "Please bring it to the office or submit in the DishNet app:\n"
                    . "My Wallet → Submit Handover\n\n"
                    . "Requested by: " . ($retailer['name'] ?? 'Rupesh') . "\n"
                    . "_DishNet Africa_",
                    'handover_nudge');
                flash("📩 WhatsApp nudge sent to {$agentName}.", 'success');
            } else {
                flash("No phone number found for {$agentName}.", 'danger');
            }
        } catch (\Throwable $e) {
            flash("Failed to send nudge: " . $e->getMessage(), 'danger');
        }
    }
    header('Location: ?page=dashboard&tab=handover_queue');
    exit;
}

// ── Load data ────────────────────────────────────────────────────────────
$allHov       = array_reverse($store->load('cash_handovers.json') ?? []);
$filterStatus = $_GET['hq_status'] ?? 'pending';
$filterAgent  = trim($_GET['hq_agent'] ?? '');

// All agent names (for filter dropdown)
$agentNames = [];
foreach ($allHov as $h) {
    $fn = $h['from_name'] ?? '';
    if ($fn && !in_array($fn, $agentNames)) $agentNames[] = $fn;
}

// Filter
$filtered = $allHov;
if ($filterStatus !== 'all') {
    $filtered = array_values(array_filter($filtered, fn($h) => ($h['status'] ?? '') === $filterStatus));
}
if ($filterAgent !== '') {
    $filtered = array_values(array_filter($filtered, fn($h) => ($h['from_name'] ?? '') === $filterAgent));
}
$pendingCount = count(array_filter($allHov, fn($h) => ($h['status'] ?? '') === 'pending'));

// ── Agent summary cards ───────────────────────────────────────────────────
$allColsAll  = $store->load('payment_collections.json') ?? [];
$_hqAllExpsRaw = $store->load('cash_expenses.json') ?? [];
$allExpsAll     = array_filter($_hqAllExpsRaw, fn($e) => ($e['status'] ?? '') === 'approved');

// ── Load expenses for Rupesh's approval queue ─────────────────────────────
require_once __DIR__ . '/../../lib/CashbookService.php';
$_cbHq          = new CashbookService($store, $dataDir);
$_hqRate        = $_cbHq->getExchangeRate(); // current system rate
$_hqExcCtx     = $_cbHq->getLastExchangeContext($store->load('cash_ins.json') ?: []);
$_hqLastRate   = (int)($_hqExcCtx['last_rate'] ?? 0);
$_hqRatePre    = $_hqLastRate > 0 ? $_hqLastRate : (int)$_hqRate; // pre-fill for modal
$allExpenses    = array_reverse($_hqAllExpsRaw); // newest first
$expFilterSt    = $_GET['hq_exp_status'] ?? 'pending';
$filteredExps   = array_values(array_filter($allExpenses,
    fn($e) => ($expFilterSt === 'all') || ($e['status'] ?? '') === $expFilterSt));
$pendingExpCount = count(array_filter($allExpenses, fn($e) => ($e['status']??'') === 'pending'));
$allHovConf  = array_filter($allHov,                                     fn($h) => ($h['status'] ?? '') === 'confirmed');

// ── Staff Payments (from field_accountant) ─────────────────────────────────
$allStaffPays = array_values(array_filter($_hqAllExpsRaw,
    fn($e) => !empty($e['is_staff_payment']) || !empty($e['staff_name'])));
$allStaffPays = array_reverse($allStaffPays);
$staffPayStatus   = $_GET['hq_sp_status'] ?? 'pending';
$filteredStaff    = array_values(array_filter($allStaffPays,
    fn($e) => $staffPayStatus === 'all' || ($e['status'] ?? '') === $staffPayStatus));
$pendingStaffCount = count(array_filter($allStaffPays, fn($e) => ($e['status']??'') === 'pending'));

// Group by staff name for monthly batch view
$staffGrouped = [];
foreach ($allStaffPays as $sp) {
    $sn = $sp['staff_name'] ?? 'Unknown';
    if (!isset($staffGrouped[$sn])) $staffGrouped[$sn] = ['name'=>$sn,'entries'=>[],'pending_total'=>0.0,'all_total'=>0.0];
    $staffGrouped[$sn]['entries'][] = $sp;
    $staffGrouped[$sn]['all_total'] += (float)($sp['amount']??0);
    if (($sp['status']??'') === 'pending') $staffGrouped[$sn]['pending_total'] += (float)($sp['amount']??0);
}

// ── Active section (top-level tab) ─────────────────────────────────────────
$hqSection = $_GET['hq_section'] ?? 'handovers'; // handovers | expenses | staff
$allRetailers = $store->load('retailers.json') ?? [];
$salesAgents  = array_filter($allRetailers, fn($r) =>
    !empty($r['is_active']) && !in_array($r['role'] ?? '', ['accountant', 'admin'], true) && empty($r['is_admin']));

// Unified cash position — real ledger data (v4.11.3)
$_hqAllCols = $allColsAll;
$_hqAllExps = $store->load('cash_expenses.json') ?: [];
$_hqAllHovsAll = $store->load('cash_handovers.json') ?: [];

// v4.11.3 FIX: Direct JSON calculation — same as ledger (proven correct by CSV)
if (!class_exists('ExpenseGateway')) require_once __DIR__ . '/../../lib/ExpenseGateway.php';
$_hqExpGw = new ExpenseGateway($store);
$_hqAllExpsUnified = $_hqExpGw->getAll(['exclude_voided' => true]);

$agentSummary = [];
$today = date('Y-m-d');
// ── Use DualReadCashPosition for balances ──
require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
$_hqJsonSvc = new StaffCashPositionService($store, $store->getPdo());
$_hqAllPos  = $_hqJsonSvc->getAllPositions(); // v4.11.38: JSON source, not staff_ledger
foreach ($salesAgents as $ag) {
    $aid   = (int)$ag['id'];
    $cols  = array_filter($allColsAll, fn($c) => (int)($c['retailer_id'] ?? 0) === $aid);
    $pend  = array_filter($allHov,     fn($h) => (int)($h['from_id'] ?? 0) === $aid && ($h['status'] ?? '') === 'pending');
    $todayC= array_filter($cols,       fn($c) => str_starts_with($c['collected_at'] ?? $c['created_at'] ?? '', $today));

    // Use ledger-based position if available, else 0
    $_hqPos = $_hqAllPos[$aid] ?? null;
    $cih = $_hqPos ? round((float)$_hqPos['cash_exposure'], 2) : 0;
    $_hqUsdIn = $_hqPos ? (float)$_hqPos['collections'] : 0;
    $_hqUsdExp = $_hqPos ? (float)$_hqPos['expenses'] : 0;

    if ($_hqUsdIn > 0 || count($pend) > 0) {
        $agentSummary[$aid] = [
            'id'             => $aid,
            'name'           => $ag['name'] ?? '',
            'phone'          => $ag['phone'] ?? '',
            'cash_in_hand'   => $cih,
            'collections'    => $_hqUsdIn,
            'advance_balance'=> (float)($_hqPos['advance_balance'] ?? 0),
            'expenses'       => $_hqUsdExp,
            'today_total'    => round(array_sum(array_column(array_values($todayC),'amount')), 2),
            'today_count'    => count($todayC),
            'pending_amt'    => round(array_sum(array_map(fn($h) => (float)($h['amount']??0), $pend)), 2),
            'pending_cnt'    => count($pend),
        ];
        // Time since last confirmed handover
        $_lhConf = array_filter($allHov, fn($h) => (int)($h['from_id']??0)===$aid && ($h['status']??'')==='confirmed');
        if (!empty($_lhConf)) {
            usort($_lhConf, fn($a,$b) => strcmp($b['confirmed_at']??'',$a['confirmed_at']??''));
            $_lhArr = array_values($_lhConf);
            $agentSummary[$aid]['last_handover_at'] = $_lhArr[0]['confirmed_at'] ?? '';
        } else {
            $agentSummary[$aid]['last_handover_at'] = '';
        }
    }
}

// Avatar color helper
$_avPairs = [
    ['#FEF3C7','#92400E'],['#DBEAFE','#1E40AF'],['#DCFCE7','#15803D'],
    ['#EDE9FE','#5B21B6'],['#FFE4E6','#9F1239'],['#E0F2FE','#0369A1'],
];
function hqAvColor(int $id, array $pairs): array {
    return $pairs[abs($id) % count($pairs)];
}
function hqTimeAgo(string $ts): string {
    if (!$ts) return '';
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return floor($d/60).'m ago';
    if ($d < 86400) return floor($d/3600).'h ago';
    return floor($d/86400).'d ago';
}
function hqGetTodayCols(array $all, int $fromId): array {
    $t = date('Y-m-d');
    return array_values(array_filter($all, fn($c) =>
        (int)($c['retailer_id']??0)===$fromId && str_starts_with($c['collected_at']??$c['created_at']??'',$t)));
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap');
.hq{font-family:'DM Sans',-apple-system,sans-serif;padding-bottom:40px;}

/* flash */
.hq-flash{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:14px;margin-bottom:14px;font-size:13px;font-weight:600;}
.hq-flash.ok{background:#DCFCE7;color:#15803D;border:1px solid #86EFAC;}
.hq-flash.er{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;}

/* header */
.hq-hd{margin-bottom:18px;}
.hq-hd h2{font-size:22px;font-weight:900;color:#0f0f0f;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.hq-hd-sub{font-size:12px;color:#94a3b8;}
.hq-badge{background:#D41C1C;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:800;}

/* Summary stats strip */
.hq-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;}
.hq-stat{background:#f8fafc;border-radius:12px;border:1px solid #e8edf2;padding:14px 16px;text-align:center;}
.hq-stat-v{font-size:24px;font-weight:900;color:#0f172a;line-height:1;}
.hq-stat-l{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-top:5px;}

/* agent cards */
.hq-agents{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:20px;}
.hq-ac{background:#fff;border-radius:16px;border:1.5px solid #e8e8e8;padding:16px;transition:.15s;position:relative;}
.hq-ac:hover{border-color:#cbd5e1;box-shadow:0 2px 12px rgba(0,0,0,.06);}
.hq-ac.has-pending{border-color:#FDE68A;background:#FFFBEB;}
.hq-ac-link{text-decoration:none;display:block;color:inherit;margin-bottom:12px;}
.hq-ac-top{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.hq-ac-av{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;flex-shrink:0;}
.hq-ac-name{font-size:13px;font-weight:700;color:#1e293b;line-height:1.2;}
.hq-ac-role{font-size:10px;color:#94a3b8;font-weight:500;}
.hq-ac-bal{font-size:26px;font-weight:900;letter-spacing:-.5px;margin:0 0 4px;}
.hq-ac-meta{font-size:10px;color:#94a3b8;line-height:1.5;}
.hq-ac-chip{display:inline-flex;align-items:center;gap:3px;background:#FEF3C7;color:#B45309;border-radius:6px;padding:2px 8px;font-size:9px;font-weight:800;margin-top:5px;}
.hq-ac-neg-badge{display:inline-flex;align-items:center;gap:4px;background:#FEE2E2;color:#991B1B;border-radius:6px;padding:3px 8px;font-size:10px;font-weight:700;margin-bottom:6px;}
.hq-ac-neg-note{font-size:10px;color:#B91C1C;background:#FEF2F2;border-radius:8px;padding:7px 10px;margin-top:8px;line-height:1.5;border:1px solid #FECACA;}
.hq-ac-actions{display:flex;align-items:center;gap:6px;padding-top:10px;border-top:1px solid #f1f5f9;}
.hq-ac-actions.nudge-only{display:flex;}
.hq-amount-row{display:flex;align-items:center;gap:4px;}
.hq-amount-row span{font-size:12px;font-weight:700;color:#64748b;}
.hq-amount-row input{width:80px;padding:5px 7px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:700;text-align:right;background:#f8fafc;color:#1e293b;}
.hq-amount-row input:focus{outline:none;border-color:#94a3b8;background:#fff;}
.hq-ac-btn{padding:6px 10px;border-radius:8px;border:1.5px solid #e2e8f0;font-size:11px;font-weight:700;cursor:pointer;transition:.15s;text-align:center;background:#fff;color:#374151;white-space:nowrap;}
.hq-ac-btn.record{background:#F0FDF4;color:#15803D;border-color:#BBF7D0;flex:1;}
.hq-ac-btn.record:hover{background:#DCFCE7;border-color:#4ADE80;}
.hq-ac-btn.nudge{background:#F0F9FF;color:#0369A1;border-color:#BAE6FD;}
.hq-ac-btn.nudge:hover{background:#E0F2FE;border-color:#38BDF8;}

/* filter bar */
.hq-filters{display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
.hq-ftab{padding:7px 14px;border-radius:10px;border:1.5px solid #ececec;background:#fff;font-size:12px;font-weight:700;color:#374151;cursor:pointer;text-decoration:none;white-space:nowrap;transition:.1s;}
.hq-ftab.on{background:#0f0f0f;color:#fff;border-color:#0f0f0f;}
.hq-ftab.on.red{background:#D41C1C;border-color:#D41C1C;}
.hq-fsel{padding:7px 11px;border:1.5px solid #ececec;border-radius:10px;font-size:12px;font-weight:600;background:#fff;color:#374151;cursor:pointer;outline:none;font-family:'DM Sans',sans-serif;}

/* table container */
.hq-box{background:#fff;border-radius:16px;border:1px solid #ececec;overflow:hidden;}
.hq-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.hq-tbl thead th{padding:10px 14px;text-align:left;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#b0b0b0;background:#fafafa;border-bottom:1px solid #ececec;white-space:nowrap;}
.hq-tbl tbody tr{border-bottom:1px solid #f8f8f5;}
.hq-tbl tbody tr:last-child{border:none;}
.hq-tbl tbody tr:hover{background:#fafaf8;}
.hq-tbl td{padding:12px 14px;vertical-align:middle;}
.hq-tbl th:last-child,.hq-tbl td:last-child{position:sticky;right:0;background:#fff;box-shadow:-4px 0 8px rgba(0,0,0,.04);z-index:1;}
.hq-tbl thead th:last-child{background:#fafafa;}
.hq-agent-cell{display:flex;align-items:center;gap:10px;}
.hq-av-sm{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0;}
.hq-anm{font-size:13px;font-weight:700;color:#0f0f0f;}
.hq-anote{font-size:11px;color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:1px;}
.hq-amt{font-size:18px;font-weight:900;color:#059669;}
.hq-colcount{font-size:10px;color:#b0b0b0;margin-top:2px;}
.hq-time{font-size:12px;color:#374151;font-weight:600;}
.hq-ago{font-size:10px;color:#b0b0b0;margin-top:1px;}
.hq-sbadge{border-radius:20px;padding:3px 10px;font-size:11px;font-weight:700;white-space:nowrap;}
.hq-sbadge.pending{background:#FEF3C7;color:#B45309;}
.hq-sbadge.confirmed{background:#DCFCE7;color:#15803D;}
.hq-sbadge.rejected{background:#FEE2E2;color:#DC2626;}
.hq-sbadge.reverted{background:#FEF3C7;color:#B45309;}
.hq-acts{display:flex;gap:6px;align-items:center;}
.hq-btn-eye{background:#f8fafc;color:#374151;border:1px solid #ececec;border-radius:9px;padding:7px 10px;font-size:14px;cursor:pointer;line-height:1;}
.hq-btn-ok{background:#059669;color:#fff;border:none;border-radius:9px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.hq-btn-ok:hover{background:#047857;}
.hq-btn-rej{background:#fff1f2;color:#dc2626;border:1.5px solid #fecaca;border-radius:9px;padding:8px 11px;font-size:12px;font-weight:700;cursor:pointer;}
.hq-btn-rej:hover{background:#fee2e2;}

/* empty */
.hq-empty{padding:64px 20px;text-align:center;background:#fafaf8;}
.hq-empty-ic{font-size:48px;margin-bottom:12px;}
.hq-empty-msg{font-size:16px;font-weight:700;color:#374151;}
.hq-empty-sub{font-size:12px;color:#9ca3af;margin-top:5px;}

/* total footer */
.hq-footer{padding:11px 14px;text-align:right;font-size:12px;color:#64748b;border-top:1px solid #f1f5f9;}

/* ── Expense section ── */
.hq-exp-section{margin-top:28px;}
.hq-section-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.hq-section-hd h3{font-size:17px;font-weight:900;color:#0f0f0f;margin:0;display:flex;align-items:center;gap:8px;}
.hq-exp-badge{background:#F59E0B;color:#fff;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:800;}
.hq-ssp-pill{display:inline-flex;align-items:center;background:#FEF3C7;color:#B45309;border-radius:8px;padding:2px 8px;font-size:10px;font-weight:800;gap:3px;}
.hq-photo-link{font-size:11px;color:#7C3AED;font-weight:700;text-decoration:none;}
.hq-photo-link:hover{text-decoration:underline;}

/* SSP approve modal */
.hqe-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-end;justify-content:center;}
.hqe-ov.open{display:flex;}
.hqe{background:#fff;border-radius:24px 24px 0 0;padding:0 0 28px;width:100%;max-width:480px;}
.hqe-handle{width:36px;height:4px;background:#e2e8f0;border-radius:4px;margin:14px auto 0;}
.hqe-hd{padding:16px 20px 12px;border-bottom:1px solid #f1f5f9;}
.hqe-title{font-size:17px;font-weight:900;}
.hqe-sub{font-size:12px;color:#94a3b8;margin-top:2px;}
.hqe-body{padding:16px 20px;}
.hqe-info{background:#f8fafc;border-radius:12px;padding:12px 14px;margin-bottom:14px;}
.hqe-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;}
.hqe-row:last-child{margin:0;}
.hqe-lbl{color:#64748b;}
.hqe-val{font-weight:700;}
.hqe-rate-block{background:#FFFBEB;border:1.5px solid #FDE68A;border-radius:14px;padding:14px;margin-bottom:14px;}
.hqe-rate-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#92400E;margin-bottom:8px;}
.hqe-rate-input{width:100%;padding:12px 14px;border:1.5px solid #FDE68A;border-radius:11px;font-size:20px;font-weight:800;font-family:'DM Sans',sans-serif;outline:none;box-sizing:border-box;color:#92400E;background:#fff;}
.hqe-rate-input:focus{border-color:#F59E0B;}
.hqe-usd-preview{text-align:center;font-size:13px;color:#92400E;margin-top:8px;font-weight:600;}
.hqe-reason-wrap label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;display:block;margin-bottom:6px;}
.hqe-reason-wrap textarea{width:100%;border:1.5px solid #e8e8e8;border-radius:12px;padding:10px 13px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;resize:none;height:80px;box-sizing:border-box;}
.hqe-btns{display:flex;gap:8px;margin-top:14px;}
.hqe-cancel{flex:0 0 auto;background:#f1f5f9;color:#374151;border:none;border-radius:12px;padding:13px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
.hqe-approve{flex:1;background:#059669;color:#fff;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
.hqe-reject{flex:0 0 auto;background:#fff1f2;color:#dc2626;border:1.5px solid #fecaca;border-radius:12px;padding:13px 16px;font-size:13px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}

/* ── Reject Modal ── */
.hqm-ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-end;justify-content:center;}
.hqm-ov.open{display:flex;}
.hqm{background:#fff;border-radius:24px 24px 0 0;padding:0 0 24px;width:100%;max-width:480px;}
.hqm-handle{width:36px;height:4px;background:#e2e8f0;border-radius:4px;margin:14px auto 0;}
.hqm-hd{padding:16px 20px 12px;border-bottom:1px solid #f1f5f9;}
.hqm-title{font-size:17px;font-weight:900;}
.hqm-sub{font-size:12px;color:#94a3b8;margin-top:2px;}
.hqm-body{padding:16px 20px;}
.hqm-info{background:#f8fafc;border-radius:12px;padding:12px 14px;margin-bottom:14px;}
.hqm-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;}
.hqm-row:last-child{margin:0;}
.hqm-lbl{color:#64748b;}
.hqm-val{font-weight:700;}
.hqm label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;display:block;margin-bottom:6px;}
.hqm textarea{width:100%;border:1.5px solid #e8e8e8;border-radius:12px;padding:10px 13px;font-size:13px;font-family:'DM Sans',sans-serif;outline:none;resize:none;height:80px;box-sizing:border-box;}
.hqm textarea:focus{border-color:#dc2626;}
.hqm-btns{display:flex;gap:8px;margin-top:14px;}
.hqm-cancel{flex:1;background:#f1f5f9;color:#374151;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}
.hqm-submit{flex:1;background:#dc2626;color:#fff;border:none;border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer;font-family:'DM Sans',sans-serif;}

/* ── Collections drawer ── */
</style>

<div class="hq">

<?php
$_hqFlash = $_SESSION['hq_flash'] ?? null; unset($_SESSION['hq_flash']);
if ($_hqFlash): ?>
<div class="hq-flash <?= $_hqFlash['type']==='success'?'ok':'er' ?>">
  <?= $_hqFlash['type']==='success'?'✅':'❌' ?> <?= htmlspecialchars($_hqFlash['msg']) ?>
</div>
<?php endif; ?>

<!-- Top-level section tabs -->
<div style="background:#fff;border-bottom:1px solid #e8e8e3;">
  <div style="display:flex;gap:0;">
    <?php foreach ([
      'handovers' => ['💵 Cash Handovers', $pendingCount,   'Diko hands over collected cash to you'],
      'expenses'  => ['🧾 Field Expenses',  $pendingExpCount,'Diko fuel, food & transport costs'],
      'staff'     => ['👤 Staff Payments',  $pendingStaffCount,'Salaries & advances Diko paid to staff'],
    ] as $sec => [$secLbl, $secBadge, $secHint]): ?>
    <a href="?page=dashboard&tab=handover_queue&hq_section=<?= $sec ?>"
       style="padding:12px 14px;font-size:13px;font-weight:700;text-decoration:none;
              color:<?= $hqSection===$sec?'#D41C1C':'#64748b' ?>;
              border-bottom:3px solid <?= $hqSection===$sec?'#D41C1C':'transparent' ?>;
              white-space:nowrap;display:flex;align-items:center;gap:6px;">
      <?= $secLbl ?>
      <?php if ($secBadge > 0): ?>
      <span style="background:#D41C1C;color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:800;"><?= $secBadge ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <!-- Active section description -->
  <?php
  $sectionHints = [
    'handovers' => '💡 When Diko physically gives you collected cash, she submits a handover. Tap her card → review collections → <strong>Confirm</strong> to update cash positions and refill her wallet.',
    'expenses'  => '💡 These are costs Diko paid from her cash (fuel, food, transport). <strong>Approve</strong> to deduct from her holding. For SSP expenses, enter today\'s rate.',
    'staff'     => '💡 Salaries and advances Diko paid to field staff. Review by person, then <strong>Approve</strong> individually or use <strong>Batch Approve</strong> at month end.',
  ];
  ?>
  <div style="padding:10px 16px;background:#f8fafc;font-size:12px;color:#475569;border-top:1px solid #f1f5f9;">
    <?= $sectionHints[$hqSection] ?? '' ?>
  </div>
</div>

<?php if ($hqSection === 'handovers'): ?>

<!-- Header -->
<div class="hq-hd">
  <h2>
    💵 Handover Queue
    <?php if ($pendingCount > 0): ?>
    <span class="hq-badge"><?= $pendingCount ?> pending</span>
    <?php endif; ?>
  </h2>
  <div class="hq-hd-sub">Diko submits cash → you count it → tap agent card → Confirm. Cash position updates, wallet refilled.</div>
</div>

<!-- Stats strip -->
<?php
$totalPendingAmt   = array_sum(array_map(fn($h)=>(float)($h['amount']??0), array_filter($allHov, fn($h)=>($h['status']??'')==='pending')));
$totalConfirmedAmt = array_sum(array_map(fn($h)=>(float)($h['amount']??0), array_filter($allHov, fn($h)=>($h['status']??'')==='confirmed')));
$totalAgentCash    = array_sum(array_column(array_values($agentSummary), 'cash_in_hand'));
?>
<div class="hq-stats">
  <div class="hq-stat">
    <div class="hq-stat-v" style="color:#D41C1C;"><?= $pendingCount ?></div>
    <div class="hq-stat-l">Waiting for You</div>
  </div>
  <div class="hq-stat">
    <div class="hq-stat-v" style="color:#D41C1C;">$<?= number_format($totalPendingAmt,0) ?></div>
    <div class="hq-stat-l">Cash Coming In</div>
  </div>
  <div class="hq-stat">
    <div class="hq-stat-v" style="color:#059669;">$<?= number_format($totalAgentCash,0) ?></div>
    <div class="hq-stat-l">Diko Holding</div>
  </div>
</div>

<!-- Agent summary cards -->
<?php if (!empty($agentSummary)): ?>
<div class="hq-agents">
<?php foreach ($agentSummary as $aid => $s):
    [$avBg, $avFg] = hqAvColor($aid, $_avPairs);
    $init = strtoupper(substr($s['name'], 0, 1));
    $hasPend = $s['pending_cnt'] > 0;
    $hasCash = $s['cash_in_hand'] > 0.01;
?>
<?php
    $isNeg = $s['cash_in_hand'] < -0.01;
    // Holding since — time since last confirmed handover
    $_holdingSince = '';
    $_lastHovAt = $s['last_handover_at'] ?? '';
    if ($hasCash && !$isNeg && $_lastHovAt) {
        $_diffMins = max(0, (int)floor((time() - strtotime($_lastHovAt)) / 60));
        if ($_diffMins < 60)       $_holdingSince = $_diffMins . 'm';
        elseif ($_diffMins < 1440) $_holdingSince = round($_diffMins/60) . 'h';
        else                       $_holdingSince = round($_diffMins/1440) . 'd';
    }
?>
  <div class="hq-ac <?= $hasPend ? 'has-pending' : '' ?>" style="<?= $isNeg ? 'border-color:#FCA5A5;background:#FFF5F5;' : '' ?>">
    <a class="hq-ac-link" href="?page=dashboard&tab=handover_queue&hq_agent=<?= urlencode($s['name']) ?>&hq_status=<?= $hasPend?'pending':'all' ?>">
      <div class="hq-ac-top">
        <div class="hq-ac-av" style="background:<?= $isNeg?'#FEE2E2':h($avBg) ?>;color:<?= $isNeg?'#991B1B':h($avFg) ?>;"><?= $init ?></div>
        <div>
          <div class="hq-ac-name"><?= h($s['name']) ?></div>
          <?php if ($_holdingSince): ?>
          <div class="hq-ac-role" style="color:#D97706;font-weight:700;"><?= h($_holdingSince) ?> holding</div>
          <?php else: ?>
          <div class="hq-ac-role">Today: <?= $s['today_count'] ?> col<?= $s['today_count']!==1?'s':'' ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($isNeg): ?>
      <div class="hq-ac-neg-badge">⚠ Company owes <?= h(explode(' ',$s['name'])[0]) ?></div>
      <?php endif; ?>
      <div class="hq-ac-bal" style="color:<?= $isNeg?'#DC2626':($s['cash_in_hand']>0.01?'#D41C1C':'#94a3b8') ?>;">
        $<?= number_format(abs($s['cash_in_hand']),2) ?>
      </div>
      <div class="hq-ac-meta">
        <?php if ($s['collections'] > 0): ?>Col $<?= number_format($s['collections'],0) ?><?php endif; ?>
        <?php if ($s['expenses'] > 0): ?> · Exp $<?= number_format($s['expenses'],0) ?><?php endif; ?>
        <?php if ($s['advance_balance'] > 0): ?> · Adv $<?= number_format($s['advance_balance'],0) ?><?php endif; ?>
      </div>
      <?php if ($hasPend): ?>
      <div class="hq-ac-chip">⏳ $<?= number_format($s['pending_amt'],2) ?> pending</div>
      <?php endif; ?>
      <?php if ($isNeg): ?>
      <div class="hq-ac-neg-note">Reimbursement outstanding — check with Rupesh</div>
      <?php endif; ?>
    </a>
    <?php if (!$isNeg && ($hasCash || $hasPend)): ?>
    <?php if ($hasCash && !$hasPend): ?>
    <div class="hq-ac-actions">
      <form method="POST" id="hof_<?= $aid ?>" style="flex:1;display:flex;align-items:center;gap:4px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="admin_record_handover">
        <input type="hidden" name="agent_id" value="<?= $aid ?>">
        <input type="hidden" name="agent_name" value="<?= h($s['name']) ?>">
        <span style="font-size:12px;font-weight:700;color:#64748b;">$</span>
        <input type="number" name="amount" value="<?= $s['cash_in_hand'] ?>" step="0.01" min="0.01" max="<?= $s['cash_in_hand'] ?>"
          style="flex:1;min-width:0;padding:5px 7px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:700;text-align:right;background:#f8fafc;color:#1e293b;">
        <button type="button" onclick="hqConfirmRecord('hof_<?= $aid ?>','<?= h(addslashes($s['name'])) ?>')"
          class="hq-ac-btn record" style="flex:0 0 auto;">📥 Record</button>
      </form>
      <form method="POST" id="hnf_<?= $aid ?>" style="flex:0 0 auto;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="nudge_handover">
        <input type="hidden" name="agent_id" value="<?= $aid ?>">
        <input type="hidden" name="agent_name" value="<?= h($s['name']) ?>">
        <input type="hidden" name="cash_amount" value="<?= $s['cash_in_hand'] ?>">
        <input type="hidden" name="holding_since" value="<?= h($_holdingSince) ?>">
        <button type="button" onclick="hqConfirmNudge('hnf_<?= $aid ?>','<?= h(addslashes($s['name'])) ?>','<?= number_format(abs($s['cash_in_hand']),2) ?>','<?= h($_holdingSince) ?>')"
          class="hq-ac-btn nudge">📩</button>
      </form>
    </div>
    <?php elseif ($hasCash): ?>
    <form method="POST" id="hnf_<?= $aid ?>">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="nudge_handover">
      <input type="hidden" name="agent_id" value="<?= $aid ?>">
      <input type="hidden" name="agent_name" value="<?= h($s['name']) ?>">
      <input type="hidden" name="cash_amount" value="<?= $s['cash_in_hand'] ?>">
      <input type="hidden" name="holding_since" value="<?= h($_holdingSince) ?>">
      <div class="hq-ac-actions nudge-only" style="padding-top:10px;border-top:1px solid #f1f5f9;">
        <button type="button" onclick="hqConfirmNudge('hnf_<?= $aid ?>','<?= h(addslashes($s['name'])) ?>','<?= number_format(abs($s['cash_in_hand']),2) ?>','<?= h($_holdingSince) ?>')"
          class="hq-ac-btn nudge">📩 Send Reminder</button>
      </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="hq-filters">
  <?php foreach (['pending'=>'⏳ Pending','confirmed'=>'✅ Confirmed','reverted'=>'↩ Reverted','rejected'=>'✕ Rejected','all'=>'All'] as $sv => $sl):
    $isCurrent = $filterStatus === $sv; ?>
  <a href="?page=dashboard&tab=handover_queue&hq_status=<?= $sv ?><?= $filterAgent ? '&hq_agent='.urlencode($filterAgent) : '' ?>"
     class="hq-ftab <?= $isCurrent ? 'on'.($sv==='pending'?' red':'') : '' ?>">
    <?= $sl ?>
    <?php if ($sv==='pending' && $pendingCount>0): ?>
    <span style="background:<?= $isCurrent?'rgba(255,255,255,.35)':'#D41C1C' ?>;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px;"><?= $pendingCount ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
  <?php if (!empty($agentNames)): ?>
  <select class="hq-fsel"
    onchange="location.href='?page=dashboard&tab=handover_queue&hq_status=<?= urlencode($filterStatus) ?>&hq_agent='+encodeURIComponent(this.value)">
    <option value="">All agents</option>
    <?php foreach ($agentNames as $an): ?>
    <option value="<?= htmlspecialchars($an) ?>" <?= $filterAgent===$an?'selected':'' ?>><?= htmlspecialchars($an) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php if ($filterAgent || $filterStatus !== 'pending'): ?>
  <a href="?page=dashboard&tab=handover_queue" style="font-size:12px;color:#9ca3af;text-decoration:none;padding:6px 10px;">✕ Clear</a>
  <?php endif; ?>
</div>

<!-- Table -->
<div class="hq-box">
<?php if (empty($filtered)): ?>
<div class="hq-empty">
  <div class="hq-empty-ic"><?= $filterStatus==='pending'?'🎉':'📭' ?></div>
  <div style="padding:32px 20px;text-align:center;">
    <div style="font-size:32px;margin-bottom:10px;"><?= $filterStatus==='pending'?'✅':'🔍' ?></div>
    <div style="font-size:14px;font-weight:800;color:#0f0f0f;margin-bottom:6px;">
      <?= $filterStatus==='pending'?'No pending handovers':'No records found' ?>
    </div>
    <div style="font-size:12px;color:#94a3b8;max-width:280px;margin:0 auto;">
      <?= $filterStatus==='pending'
        ? 'When Diko physically gives you collected cash and submits a Handover from her Register, it will appear here.'
        : 'Try selecting a different status filter above.' ?>
    </div>
  </div>
  <div class="hq-empty-sub"><?= $filterStatus==='pending'?'All agents are fully settled.':'Try a different filter.' ?></div>
</div>
<?php else: ?>
<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:10px;">
<table class="hq-tbl">
  <thead>
    <tr>
      <th>#</th><th>Agent</th><th>Amount</th><th>Submitted</th><th>Status</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($filtered as $h):
    $hid    = (int)($h['id'] ?? 0);
    $fromId = (int)($h['from_id'] ?? 0);
    $fname  = htmlspecialchars($h['from_name'] ?? '');
    $amount = (float)($h['amount'] ?? 0);
    $notes  = htmlspecialchars($h['notes'] ?? '');
    $status = $h['status'] ?? 'pending';
    $subAt  = $h['submitted_at'] ?? $h['created_at'] ?? '';
    $confBy = htmlspecialchars($h['confirmed_by'] ?? '');
    $confAt = $h['confirmed_at'] ?? '';
    [$avBg2, $avFg2] = hqAvColor($fromId, $_avPairs);
    $init2  = strtoupper(substr($h['from_name'] ?? 'A', 0, 1));
    $todayCols2     = hqGetTodayCols($allColsAll, $fromId);
    $todayCols2Json = htmlspecialchars(json_encode(array_values($todayCols2)), ENT_QUOTES);
  ?>
  <tr>
    <td style="color:#b0b0b0;font-size:11px;font-weight:700;">#<?= $hid ?></td>
    <td>
      <div class="hq-agent-cell">
        <div class="hq-av-sm" style="background:<?= $avBg2 ?>;color:<?= $avFg2 ?>;<?= ($h['type']??'')==='relay'?'border:2px solid #6366f1;':'' ?>"><?= $init2 ?></div>
        <div>
          <div class="hq-anm">
            <?= $fname ?>
            <?php if (($h['type'] ?? '') === 'relay'): ?>
              <span style="font-size:9px;font-weight:800;background:#ede9fe;color:#6d28d9;border-radius:4px;padding:1px 5px;margin-left:4px;vertical-align:middle;">&#x26D3; RELAY</span>
            <?php endif; ?>
          </div>
          <?php if ($notes): ?><div class="hq-anote"><?= $notes ?></div><?php endif; ?>
          <?php
            $_srcIds = array_map('intval', (array)($h['source_handover_ids'] ?? []));
            if (($h['type'] ?? '') === 'relay' && !empty($_srcIds)):
                $_srcSummary = [];
                foreach ($allHov as $_sh2) {
                    if (in_array((int)($_sh2['id'] ?? 0), $_srcIds, true)) {
                        $_srcSummary[] = ($_sh2['from_name'] ?? 'Staff') . ' $' . number_format((float)($_sh2['amount'] ?? 0), 0);
                    }
                }
          ?>
            <div style="margin-top:4px;line-height:1.6;">
              <span style="font-size:9px;color:#64748b;font-weight:700;">Chain: </span>
              <?php if (!empty($_srcSummary)): foreach ($_srcSummary as $_ss): ?>
              <span style="font-size:9px;background:#eff6ff;color:#1d4ed8;border-radius:3px;padding:1px 5px;display:inline-block;margin-right:2px;"><?= h($_ss) ?></span>
              <?php endforeach; else: foreach ($_srcIds as $_sid): ?>
              <span style="font-size:9px;background:#f1f5f9;color:#475569;border-radius:3px;padding:1px 5px;display:inline-block;margin-right:2px;">HOV-<?= $_sid ?></span>
              <?php endforeach; endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </td>
    <td>
      <div class="hq-amt">$<?= number_format($amount,2) ?></div>
      <?php if ($todayCols2): ?><div class="hq-colcount"><?= count($todayCols2) ?> collections today</div><?php endif; ?>
    </td>
    <td>
      <div class="hq-time"><?= $subAt ? date('d M y H:i', strtotime($subAt)) : '—' ?></div>
      <div class="hq-ago"><?= hqTimeAgo($subAt) ?></div>
    </td>
    <td>
      <?php if ($status==='pending'): ?>
        <span class="hq-sbadge pending">⏳ Pending</span>
      <?php elseif ($status==='confirmed'): ?>
        <span class="hq-sbadge confirmed">✅ Confirmed</span>
        <?php if ($confBy): ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">by <?= $confBy ?><?= $confAt ? ' · '.date('d M H:i',strtotime($confAt)) : '' ?></div><?php endif; ?>
      <?php elseif ($status==='reverted'): ?>
        <span class="hq-sbadge reverted">↩ Reverted</span>
        <?php if (!empty($h['reverted_by'])): ?><div style="font-size:10px;color:#d97706;margin-top:2px;">by <?= htmlspecialchars($h['reverted_by']) ?><?= !empty($h['reverted_at']) ? ' · '.date('d M H:i',strtotime($h['reverted_at'])) : '' ?></div><?php endif; ?>
        <?php if (!empty($h['revert_reason'])): ?>
        <div style="font-size:10px;color:#d97706;margin-top:2px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
             title="<?= htmlspecialchars($h['revert_reason']) ?>"><?= htmlspecialchars($h['revert_reason']) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <span class="hq-sbadge rejected">✕ Rejected</span>
        <?php if (!empty($h['reject_reason'])): ?>
        <div style="font-size:10px;color:#dc2626;margin-top:2px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
             title="<?= htmlspecialchars($h['reject_reason']) ?>"><?= htmlspecialchars($h['reject_reason']) ?></div>
        <?php endif; ?>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($status==='pending'): ?>
      <div class="hq-acts">
        <!-- View collections drawer -->
        <button class="hq-btn-eye" title="View today's collections"
          onclick="openDrawer(<?= $hid ?>,<?= htmlspecialchars(json_encode($h['from_name']??''),ENT_QUOTES) ?>,<?= $amount ?>,<?= $todayCols2Json ?>)">👁</button>
        <!-- Quick Confirm -->
        <form method="POST" style="display:inline;"
          onsubmit="return confirm('Confirm receipt of $<?= number_format($amount,2) ?> from <?= $fname ?>?\nThis will confirm receipt and refill their wallet.')">
          <input type="hidden" name="action" value="confirm_handover">
          <input type="hidden" name="handover_id" value="<?= $hid ?>">
          <?= csrfField() ?>
          <button type="submit" class="hq-btn-ok">✓ Confirm</button>
        </form>
        <!-- Reject -->
        <button class="hq-btn-rej"
          onclick="openReject(<?= $hid ?>,<?= htmlspecialchars(json_encode($h['from_name']??''),ENT_QUOTES) ?>,<?= $amount ?>)">✕</button>
      </div>
      <?php else: ?>
        <span style="font-size:12px;color:#b0b0b0;">—</span>
      <?php endif; ?>
      <?php if ($status==='confirmed' && ($retailer['is_admin'] ?? false)): ?>
        <button class="hq-btn-rej" style="margin-top:4px;font-size:11px;" title="Revert this confirmed handover"
          onclick="openRevert(<?= $hid ?>,<?= htmlspecialchars(json_encode($h['from_name']??''),ENT_QUOTES) ?>,<?= $amount ?>)">↩ Revert</button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div><!-- /overflow-x:auto -->
<?php $gt = array_sum(array_map(fn($h)=>(float)($h['amount']??0), $filtered)); ?>
<div class="hq-footer">
  <?= count($filtered) ?> record<?= count($filtered)!==1?'s':'' ?> &nbsp;·&nbsp;
  <strong style="color:#0f0f0f;">Total: $<?= number_format($gt,2) ?></strong>
</div>
<?php endif; ?>
</div>

<!-- ══ Expense Approval Section ═════════════════════════════════════ -->
<div class="hq-exp-section">
  <div class="hq-section-hd">
    <h3>
      🧾 Expense Approvals
      <?php if ($pendingExpCount > 0): ?>
      <span class="hq-exp-badge"><?= $pendingExpCount ?> pending</span>
      <?php endif; ?>
    </h3>
    <div style="display:flex;gap:7px;">
      <?php foreach (['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'✕ Rejected','all'=>'All'] as $esv => $esl):
        $eCur = $expFilterSt === $esv; ?>
      <a href="?page=dashboard&tab=handover_queue&hq_status=<?= urlencode($filterStatus) ?>&hq_exp_status=<?= $esv ?>"
         class="hq-ftab <?= $eCur ? 'on'.($esv==='pending'?' red':'') : '' ?>" style="font-size:11px;padding:5px 11px;">
        <?= $esl ?>
        <?php if ($esv==='pending' && $pendingExpCount>0): ?>
        <span style="background:<?= $eCur?'rgba(255,255,255,.35)':'#F59E0B' ?>;color:#fff;border-radius:10px;padding:0 5px;font-size:9px;margin-left:2px;"><?= $pendingExpCount ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="hq-box">
  <?php if (empty($filteredExps)): ?>
  <div class="hq-empty">
    <div class="hq-empty-ic"><?= $expFilterSt==='pending'?'👌':'📭' ?></div>
    <div style="padding:32px 20px;text-align:center;">
      <div style="font-size:32px;margin-bottom:10px;"><?= $expFilterSt==='pending'?'✅':'🔍' ?></div>
      <div style="font-size:14px;font-weight:800;color:#0f0f0f;margin-bottom:6px;">
        <?= $expFilterSt==='pending'?'No pending expenses':'No records found' ?>
      </div>
      <div style="font-size:12px;color:#94a3b8;max-width:280px;margin:0 auto;">
        <?= $expFilterSt==='pending'
          ? 'When Diko submits a field expense (fuel, food, transport), it will appear here for your approval.'
          : 'Try selecting a different status filter above.' ?>
      </div>
    </div>
    <div class="hq-empty-sub"><?= $expFilterSt==='pending'?'Nothing to approve right now.':'Try a different filter.' ?></div>
  </div>
  <?php else: ?>
  <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:10px;">
  <table class="hq-tbl">
    <thead>
      <tr>
        <th>#</th><th>Agent</th><th>Amount</th><th>Category</th><th>Submitted</th><th>Status</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($filteredExps as $exp):
      $eid      = (int)($exp['id'] ?? 0);
      $ecur     = $exp['currency'] ?? 'USD';
      $eAgt     = htmlspecialchars($exp['collector_name'] ?? '');
      $eAgtId   = (int)($exp['collector_id'] ?? 0);
      $eStat    = $exp['status'] ?? 'pending';
      $eCat     = htmlspecialchars($exp['category'] ?? '');
      $eDesc    = htmlspecialchars($exp['description'] ?? '');
      $eAmt     = (float)($exp['amount'] ?? 0);
      $eSSP     = (float)($exp['ssp_amount'] ?? 0);
      $eRate    = (float)($exp['ssp_rate'] ?? 0);
      $ePhoto   = $exp['photo'] ?? '';
      $eSubAt   = $exp['submitted_at'] ?? $exp['created_at'] ?? '';
      $eBy      = htmlspecialchars($exp['approved_by'] ?? $exp['rejected_by'] ?? '');
      // Phase 2: exchange batch link
      $eExcRef  = (string)($exp['exchange_ref'] ?? '');
      $eExcRate = !empty($exp['exchange_rate']) ? (float)$exp['exchange_rate'] : 0.0;
      // Use batch rate if available — actual rate staff got at money changer
      $eApproveRate = $eExcRate > 0 ? (int)$eExcRate : $_hqRatePre;
      [$avBgE, $avFgE] = hqAvColor($eAgtId, $_avPairs);
      $initE   = strtoupper(substr($exp['collector_name'] ?? 'A', 0, 1));

      // Display amount
      $eAmtDisplay = $ecur === 'SSP'
          ? 'SSP '.number_format($eSSP, 0)
          : '$'.number_format($eAmt, 2);
      // USD equivalent for approved SSP (rate known after approval)
      $eUsdLabel = ($ecur==='SSP' && $eRate > 0 && $eStat==='approved')
          ? ' = $'.number_format($eAmt,2).' @'.number_format($eRate,0)
          : '';
    ?>
    <tr>
      <td style="color:#b0b0b0;font-size:11px;font-weight:700;">#<?= $eid ?></td>
      <td>
        <div class="hq-agent-cell">
          <div class="hq-av-sm" style="background:<?= $avBgE ?>;color:<?= $avFgE ?>;"><?= $initE ?></div>
          <div>
            <div class="hq-anm"><?= $eAgt ?></div>
            <?php if ($eDesc): ?><div class="hq-anote"><?= $eDesc ?></div><?php endif; ?>
          </div>
        </div>
      </td>
      <td>
        <div class="hq-amt" style="color:<?= $ecur==='SSP'?'#B45309':'#D41C1C'; ?>; font-size:15px;">
          <?= $eAmtDisplay ?>
        </div>
        <?php if ($eUsdLabel): ?><div class="hq-colcount"><?= $eUsdLabel ?></div><?php endif; ?>
        <?php if ($ePhoto): ?><a class="hq-photo-link" href="javascript:void(0)" onclick="dnLbOpen('?page=kyc_photo&f=<?= htmlspecialchars($ePhoto) ?>')" style="cursor:pointer;">📎 Photo</a><?php endif; ?>
      </td>
      <td>
        <div style="font-size:13px;font-weight:700;color:#111;"><?= $eCat ?></div>
        <?php if ($ecur==='SSP'): ?><span class="hq-ssp-pill">🟡 SSP</span><?php endif; ?>
      </td>
      <td>
        <div class="hq-time"><?= $eSubAt ? date('d M y H:i',strtotime($eSubAt)) : '—' ?></div>
        <div class="hq-ago"><?= hqTimeAgo($eSubAt) ?></div>
      </td>
      <td>
        <?php if ($eStat==='pending'): ?>
          <span class="hq-sbadge pending">⏳ Pending</span>
        <?php elseif ($eStat==='approved'): ?>
          <?php if (!empty($exp["auto_approved"])): ?><span class="hq-sbadge" style="background:#e0f2fe;color:#0369a1;">⚡ Auto</span><?php else: ?><span class="hq-sbadge confirmed">✅ Approved</span><?php endif; ?>
          <?php if ($eBy): ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">by <?= $eBy ?></div><?php endif; ?>
        <?php else: ?>
          <span class="hq-sbadge rejected">✕ Rejected</span>
          <?php if (!empty($exp['reject_reason'])): ?>
          <div style="font-size:10px;color:#dc2626;margin-top:2px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
               title="<?= htmlspecialchars($exp['reject_reason']??'') ?>"><?= htmlspecialchars($exp['reject_reason']??'') ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($eStat==='pending'): ?>
        <div class="hq-acts">
          <button class="hq-btn-ok" style="background:#F59E0B;"
            onclick="openExpApprove(<?= $eid ?>,<?= htmlspecialchars(json_encode($exp['collector_name']??''),ENT_QUOTES) ?>,<?= $eSSP > 0 ? 'null' : $eAmt ?>,<?= $eSSP ?: 'null' ?>,<?= htmlspecialchars(json_encode($eCat),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($ecur),ENT_QUOTES) ?>,<?= $eApproveRate ?>,<?= $_hqLastRate ?>,<?= htmlspecialchars(json_encode($eExcRef),ENT_QUOTES) ?>)">
            ✓ Approve
          </button>
          <button class="hq-btn-rej"
            onclick="openExpReject(<?= $eid ?>,<?= htmlspecialchars(json_encode($exp['collector_name']??''),ENT_QUOTES) ?>,<?= $eSSP?:$eAmt ?>,<?= htmlspecialchars(json_encode($ecur),ENT_QUOTES) ?>)">✕</button>
        </div>
        <?php else: ?><span style="font-size:12px;color:#b0b0b0;">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div><!-- /overflow-x:auto -->
  <?php $egt = count($filteredExps); ?>
  <div class="hq-footer"><?= $egt ?> expense<?= $egt!==1?'s':'' ?></div>
  <?php endif; ?>
  </div><!-- /hq-box -->
</div><!-- /hq-exp-section -->

<?php endif; // hqSection === handovers ?>

<?php if ($hqSection === 'expenses'): ?>
<!-- Expenses section — show the same table as in handovers but standalone -->
<div class="hq-hd">
  <h2>🧾 Field Expenses
    <?php if ($pendingExpCount > 0): ?><span class="hq-badge"><?= $pendingExpCount ?> pending</span><?php endif; ?>
  </h2>
  <div class="hq-hd-sub">Field expenses submitted by agents for your approval</div>
</div>

<!-- Status filter pills -->
<div style="display:flex;gap:6px;padding:0 16px 12px;flex-wrap:wrap;">
  <?php foreach (['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected','all'=>'All'] as $fv=>$fl): ?>
  <a href="?page=dashboard&tab=handover_queue&hq_section=expenses&hq_exp_status=<?= $fv ?>"
     style="padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;
            background:<?= $expFilterSt===$fv?'#0f0f0f':'#f1f5f9' ?>;color:<?= $expFilterSt===$fv?'#fff':'#64748b' ?>;">
    <?= $fl ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="hq-box">
<?php if (empty($filteredExps)): ?>
  <div style="padding:32px 20px;text-align:center;">
    <div style="font-size:32px;margin-bottom:10px;"><?= $expFilterSt==='pending'?'✅':'🔍' ?></div>
    <div style="font-size:14px;font-weight:800;color:#0f0f0f;margin-bottom:6px;">
      <?= $expFilterSt==='pending'?'No pending expenses':'No records found' ?>
    </div>
  </div>
<?php else: ?>
  <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:10px;">
  <table class="hq-tbl">
    <thead><tr><th>#</th><th>Agent</th><th>Amount</th><th>Category</th><th>Submitted</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($filteredExps as $exp):
      $eid      = (int)($exp['id'] ?? 0);
      $ecur     = $exp['currency'] ?? 'USD';
      $eAgt     = htmlspecialchars($exp['collector_name'] ?? '');
      $eAgtId   = (int)($exp['collector_id'] ?? 0);
      $eStat    = $exp['status'] ?? 'pending';
      $eCat     = htmlspecialchars($exp['category'] ?? '');
      $eDesc    = htmlspecialchars($exp['description'] ?? '');
      $eAmt     = (float)($exp['amount'] ?? 0);
      $eSSP     = (float)($exp['ssp_amount'] ?? 0);
      $eRate    = (float)($exp['ssp_rate'] ?? 0);
      $ePhoto   = $exp['photo'] ?? '';
      $eSubAt   = $exp['submitted_at'] ?? $exp['created_at'] ?? '';
      $eBy      = htmlspecialchars($exp['approved_by'] ?? $exp['rejected_by'] ?? '');
      // Phase 2: exchange batch link
      $eExcRef  = (string)($exp['exchange_ref'] ?? '');
      $eExcRate = !empty($exp['exchange_rate']) ? (float)$exp['exchange_rate'] : 0.0;
      // Use batch rate if available — actual rate staff got at money changer
      $eApproveRate = $eExcRate > 0 ? (int)$eExcRate : $_hqRatePre;
      [$avBgE2, $avFgE2] = hqAvColor($eAgtId, $_avPairs);
      $initE2  = strtoupper(substr($exp['collector_name'] ?? 'A', 0, 1));
      $eAmtDisplay = $ecur === 'SSP' ? 'SSP '.number_format($eSSP, 0) : '$'.number_format($eAmt, 2);
      $eUsdLabel = ($ecur==='SSP' && $eRate > 0 && $eStat==='approved') ? ' = $'.number_format($eAmt,2).' @'.number_format($eRate,0) : '';
    ?>
    <tr>
      <td style="color:#b0b0b0;font-size:11px;font-weight:700;">#<?= $eid ?></td>
      <td>
        <div class="hq-agent-cell">
          <div class="hq-av-sm" style="background:<?= $avBgE2 ?>;color:<?= $avFgE2 ?>;"><?= $initE2 ?></div>
          <div>
            <div class="hq-anm"><?= $eAgt ?></div>
            <?php if ($eDesc): ?><div class="hq-anote"><?= $eDesc ?></div><?php endif; ?>
          </div>
        </div>
      </td>
      <td>
        <div class="hq-amt" style="color:<?= $ecur==='SSP'?'#B45309':'#D41C1C'; ?>; font-size:15px;"><?= $eAmtDisplay ?></div>
        <?php if ($eUsdLabel): ?><div class="hq-colcount"><?= $eUsdLabel ?></div><?php endif; ?>
        <?php if ($ePhoto): ?><a class="hq-photo-link" href="javascript:void(0)" onclick="dnLbOpen('?page=kyc_photo&f=<?= htmlspecialchars($ePhoto) ?>')" style="cursor:pointer;">📎 Photo</a><?php endif; ?>
      </td>
      <td>
        <div style="font-size:13px;font-weight:700;color:#111;"><?= $eCat ?></div>
        <?php if ($ecur==='SSP'): ?><span class="hq-ssp-pill">🟡 SSP</span><?php endif; ?>
      </td>
      <td>
        <div class="hq-time"><?= $eSubAt ? date('d M y H:i',strtotime($eSubAt)) : '—' ?></div>
        <div class="hq-ago"><?= hqTimeAgo($eSubAt) ?></div>
      </td>
      <td>
        <?php if ($eStat==='pending'): ?>
          <span class="hq-sbadge pending">⏳ Pending</span>
        <?php elseif ($eStat==='approved'): ?>
          <?php if (!empty($exp["auto_approved"])): ?><span class="hq-sbadge" style="background:#e0f2fe;color:#0369a1;">⚡ Auto</span><?php else: ?><span class="hq-sbadge confirmed">✅ Approved</span><?php endif; ?>
          <?php if ($eBy): ?><div style="font-size:10px;color:#94a3b8;margin-top:2px;">by <?= $eBy ?></div><?php endif; ?>
        <?php else: ?>
          <span class="hq-sbadge rejected">✕ Rejected</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($eStat==='pending'): ?>
        <div class="hq-acts">
          <button class="hq-btn-ok" style="background:#F59E0B;"
            onclick="openExpApprove(<?= $eid ?>,<?= htmlspecialchars(json_encode($exp['collector_name']??''),ENT_QUOTES) ?>,<?= $eSSP > 0 ? 'null' : $eAmt ?>,<?= $eSSP ?: 'null' ?>,<?= htmlspecialchars(json_encode($eCat),ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($ecur),ENT_QUOTES) ?>,<?= $eApproveRate ?>,<?= $_hqLastRate ?>,<?= htmlspecialchars(json_encode($eExcRef),ENT_QUOTES) ?>)">
            ✓ Approve
          </button>
          <button class="hq-btn-rej"
            onclick="openExpReject(<?= $eid ?>,<?= htmlspecialchars(json_encode($exp['collector_name']??''),ENT_QUOTES) ?>,<?= $eSSP?:$eAmt ?>,<?= htmlspecialchars(json_encode($ecur),ENT_QUOTES) ?>)">✕</button>
        </div>
        <?php else: ?><span style="font-size:12px;color:#b0b0b0;">—</span><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php $egt2 = count($filteredExps); ?>
  <div class="hq-footer"><?= $egt2 ?> expense<?= $egt2!==1?'s':'' ?></div>
<?php endif; ?>
</div>
<?php endif; // expenses ?>

<?php if ($hqSection === 'staff'): ?>
<!-- ── Staff Payments Section ─────────────────────────────────────────── -->
<div class="hq-hd">
  <h2>👤 Staff Payments
    <?php if ($pendingStaffCount > 0): ?><span class="hq-badge"><?= $pendingStaffCount ?> pending</span><?php endif; ?>
  </h2>
  <div class="hq-hd-sub">Salaries & advances paid by Diko to field staff · Approve monthly</div>
</div>

<!-- Status filter -->
<div class="hq-filters" style="margin-bottom:0;">
  <?php foreach (['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'✕ Rejected','all'=>'All'] as $sv=>$sl): ?>
  <a href="?page=dashboard&tab=handover_queue&hq_section=staff&hq_sp_status=<?= $sv ?>"
     class="hq-ftab <?= $staffPayStatus===$sv?'on'.($sv==='pending'?' red':''):'' ?>">
    <?= $sl ?>
    <?php if ($sv==='pending' && $pendingStaffCount>0): ?>
    <span style="background:<?= $staffPayStatus==='pending'?'rgba(255,255,255,.35)':'#D41C1C' ?>;color:#fff;border-radius:10px;padding:0 6px;font-size:10px;margin-left:2px;"><?= $pendingStaffCount ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<?php if (empty($filteredStaff)): ?>
<div class="hq-empty-msg" style="padding:40px;text-align:center;color:#94a3b8;font-size:14px;">
  ✅ No <?= $staffPayStatus === 'pending' ? 'pending' : '' ?> staff payments.
</div>
<?php else: ?>

<!-- Batch approve button (pending only) -->
<?php if ($staffPayStatus === 'pending' && $pendingStaffCount > 0):
  $batchTotal = array_sum(array_map(fn($e)=>(float)($e['amount']??0), array_filter($filteredStaff, fn($e)=>($e['status']??'')==='pending')));
?>
<div style="padding:12px 16px;background:#fef3c7;border-left:4px solid #d97706;margin:12px 0;border-radius:0 10px 10px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
  <div>
    <div style="font-size:13px;font-weight:800;color:#92400e;">⏳ <?= $pendingStaffCount ?> payments pending — $<?= number_format($batchTotal,2) ?> total</div>
    <div style="font-size:11px;color:#b45309;margin-top:2px;">Review entries below then batch-approve or approve individually</div>
  </div>
  <form method="POST" action="?page=dashboard&tab=handover_queue&hq_section=staff" onsubmit="return confirm('Approve ALL <?= $pendingStaffCount ?> pending staff payments ($<?= number_format($batchTotal,2) ?>)? This will post them to Rupesh's cashbook.');">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="batch_approve_staff">
    <button type="submit" style="background:#059669;color:#fff;border:none;border-radius:10px;padding:10px 20px;font-size:13px;font-weight:800;cursor:pointer;">
      ✅ Batch Approve All ($<?= number_format($batchTotal,2) ?>)
    </button>
  </form>
</div>
<?php endif; ?>

<!-- Grouped by staff member -->
<?php foreach ($staffGrouped as $sn => $sg):
  $sgPending = array_filter($sg['entries'], fn($e)=>($e['status']??'')==='pending');
  $sgVisible = array_filter($sg['entries'], fn($e)=> $staffPayStatus==='all' || ($e['status']??'')===$staffPayStatus);
  if (empty($sgVisible)) continue;
?>
<div style="background:#fff;border-radius:14px;border:1px solid #e8e8e3;margin:10px 0;overflow:hidden;">
  <!-- Staff person header -->
  <div style="background:#f8fafc;padding:12px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #e8e8e3;">
    <div style="width:38px;height:38px;background:#1e40af;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:16px;">
      <?= strtoupper(substr($sn,0,1)) ?>
    </div>
    <div style="flex:1;">
      <div style="font-size:14px;font-weight:800;color:#0f0f0f;"><?= htmlspecialchars($sn) ?></div>
      <div style="font-size:11px;color:#64748b;"><?= count($sg['entries']) ?> entries · $<?= number_format($sg['all_total'],2) ?> total</div>
    </div>
    <?php if (!empty($sgPending)): ?>
    <span style="background:#fef3c7;color:#92400e;border-radius:8px;padding:4px 10px;font-size:11px;font-weight:800;">
      ⏳ $<?= number_format($sg['pending_total'],2) ?> pending
    </span>
    <?php endif; ?>
  </div>
  <!-- Entries for this person -->
  <?php foreach ($sgVisible as $sp):
    $spAmt = (float)($sp['amount']??0);
    $spCur = $sp['currency'] ?? 'USD';
    $spSsp = (float)($sp['ssp_amount']??0);
    $dispAmt = $spCur==='SSP' ? number_format($spSsp,0).' SSP' : '$'.number_format($spAmt,2);
    $spStatus = $sp['status'] ?? 'pending';
    $spType   = $sp['staff_payment_type'] ?? ($sp['expense_type'] ?? $sp['category'] ?? 'Payment');
    $spDate   = substr($sp['submitted_at'] ?? $sp['created_at'] ?? '',0,10);
    $spBy     = $sp['collector_name'] ?? 'Diko';
    $statusColors = ['pending'=>['#fef3c7','#92400e'],'approved'=>['#dcfce7','#15803d'],'rejected'=>['#fef2f2','#dc2626']];
    [$sBg,$sFg] = $statusColors[$spStatus] ?? ['#f3f4f6','#374151'];
  ?>
  <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;">
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:700;color:#0f0f0f;"><?= htmlspecialchars($spType) ?> — <?= $dispAmt ?></div>
      <div style="font-size:11px;color:#64748b;margin-top:2px;">
        <?= htmlspecialchars($sp['description'] ?? '') ?> · <?= $spDate ?> by <?= htmlspecialchars($spBy) ?>
      </div>
    </div>
    <span style="background:<?= $sBg ?>;color:<?= $sFg ?>;border-radius:8px;padding:3px 9px;font-size:10px;font-weight:800;white-space:nowrap;">
      <?= ucfirst($spStatus) ?>
    </span>
    <?php if ($spStatus === 'pending'): ?>
    <form method="POST" action="?page=dashboard&tab=handover_queue&hq_section=staff&hq_sp_status=pending" style="display:inline;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="approve_staff_payment">
      <input type="hidden" name="expense_id" value="<?= (int)($sp['id']??0) ?>">
      <?php if ($spCur === 'SSP'): ?>
      <input type="number" name="ssp_rate" placeholder="Rate" step="1" min="1"
             style="width:80px;border:1px solid #e8e8e3;border-radius:8px;padding:6px 8px;font-size:12px;text-align:center;"
             title="SSP rate for converting to USD">
      <?php endif; ?>
      <button type="submit" style="background:#059669;color:#fff;border:none;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">✓ Approve</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; // empty filteredStaff ?>

<?php endif; // hqSection === staff ?>

</div><!-- /hq -->

<!-- ══ Reject Modal ═══════════════════════════════════════════════ -->
<div class="hqm-ov" id="hqmOv">
  <div class="hqm">
    <div class="hqm-handle"></div>
    <div class="hqm-hd">
      <div class="hqm-title">✕ Reject Handover</div>
      <div class="hqm-sub" id="hqmSub"></div>
    </div>
    <div class="hqm-body">
      <div class="hqm-info" id="hqmInfo"></div>
      <form method="POST" onsubmit="return hqmValidate()">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reject_handover">
        <input type="hidden" name="handover_id" id="hqmHovId">
        <label>Reason for rejection *</label>
        <textarea name="reject_reason" id="hqmReason"
          placeholder="e.g. Amount received was $180, not $200 — please resubmit."></textarea>
        <div class="hqm-btns">
          <button type="button" class="hqm-cancel" onclick="closeReject()">Cancel</button>
          <button type="submit" class="hqm-submit">Confirm Reject</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ Revert Modal (admin only) ══════════════════════════════════ -->
<div class="hqm-ov" id="hqmRevOv">
  <div class="hqm">
    <div class="hqm-handle"></div>
    <div class="hqm-hd">
      <div class="hqm-title" style="color:#B45309;">↩ Revert Confirmed Handover</div>
      <div class="hqm-sub" id="hqmRevSub"></div>
    </div>
    <div class="hqm-body">
      <div style="background:#FEF3C7;border:1px solid #F59E0B;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400E;">
        ⚠️ This will <strong>debit the agent's wallet</strong> by the handover amount and unlink all associated collections. Use only to correct genuine mistakes.
      </div>
      <div class="hqm-info" id="hqmRevInfo"></div>
      <form method="POST" onsubmit="return hqmRevValidate()">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="revert_handover">
        <input type="hidden" name="handover_id" id="hqmRevHovId">
        <label>Reason for reverting *</label>
        <textarea name="revert_reason" id="hqmRevReason"
          placeholder="e.g. Confirmed by mistake — agent had not actually handed over cash."></textarea>
        <div class="hqm-btns">
          <button type="button" class="hqm-cancel" onclick="closeRevert()">Cancel</button>
          <button type="submit" class="hqm-submit" style="background:#B45309;">Confirm Revert</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Collections inline expand panel (replaces drawer) -->
<div id="hqColPanel" style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.5);" onclick="if(event.target===this)closeColPanel()">
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:18px;width:min(480px,94vw);max-height:80vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:15px;font-weight:900;color:#0f0f0f;" id="hqCpTitle"></div>
        <div style="font-size:12px;color:#94a3b8;margin-top:2px;" id="hqCpSub"></div>
      </div>
      <button onclick="closeColPanel()" style="background:#f1f5f9;border:none;border-radius:50%;width:32px;height:32px;font-size:18px;cursor:pointer;color:#64748b;line-height:1;">×</button>
    </div>
    <div style="overflow-y:auto;padding:0 20px;flex:1;" id="hqCpBody"></div>
    <div style="padding:14px 20px;border-top:1px solid #f1f5f9;font-size:12px;color:#94a3b8;text-align:center;">
      Use the <strong>✓ Confirm</strong> and <strong>✕</strong> buttons on the handover row to take action.
    </div>
  </div>
</div>

<script>
// ── Reject modal ──────────────────────────────────────────────────
function openReject(rid, name, amount) {
  document.getElementById('hqmHovId').value = rid;
  document.getElementById('hqmSub').textContent = 'Handover #' + rid + ' from ' + name;
  document.getElementById('hqmInfo').innerHTML =
    '<div class="hqm-row"><span class="hqm-lbl">Agent</span><span class="hqm-val">' + esc(name) + '</span></div>' +
    '<div class="hqm-row"><span class="hqm-lbl">Amount</span><span class="hqm-val" style="color:#D41C1C;">$' + parseFloat(amount).toFixed(2) + '</span></div>';
  document.getElementById('hqmReason').value = '';
  document.getElementById('hqmOv').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeReject() {
  document.getElementById('hqmOv').classList.remove('open');
  document.body.style.overflow = '';
}
function hqmValidate() {
  if (!document.getElementById('hqmReason').value.trim()) {
    alert('Please enter a rejection reason.'); return false;
  }
  return true;
}
// ── Revert modal ─────────────────────────────────────────────────
function openRevert(rid, name, amount) {
  document.getElementById('hqmRevHovId').value = rid;
  document.getElementById('hqmRevSub').textContent = 'HOV-' + rid + ' from ' + name;
  document.getElementById('hqmRevInfo').innerHTML =
    '<div class="hqm-row"><span class="hqm-lbl">Agent</span><span class="hqm-val">' + esc(name) + '</span></div>' +
    '<div class="hqm-row"><span class="hqm-lbl">Amount</span><span class="hqm-val" style="color:#B45309;">$' + parseFloat(amount).toFixed(2) + '</span></div>' +
    '<div class="hqm-row"><span class="hqm-lbl">Action</span><span class="hqm-val">Wallet will be debited $' + parseFloat(amount).toFixed(2) + '</span></div>';
  document.getElementById('hqmRevReason').value = '';
  document.getElementById('hqmRevOv').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeRevert() {
  document.getElementById('hqmRevOv').classList.remove('open');
  document.body.style.overflow = '';
}
function hqmRevValidate() {
  if (!document.getElementById('hqmRevReason').value.trim()) {
    alert('Please enter a reason for reverting.'); return false;
  }
  return confirm("Are you sure you want to revert this handover?\nThe agent's wallet will be debited.");
}
document.getElementById('hqmOv').addEventListener('click', function(e){ if(e.target===this) closeReject(); });

// ── Collections panel (view only — confirm/reject on row) ─────────
function openDrawer(rid, name, amount, cols) {
  document.getElementById('hqCpTitle').textContent = name + "'s Collections";
  document.getElementById('hqCpSub').textContent = 'Handover #' + rid + ' · $' + parseFloat(amount).toFixed(2) + ' submitted';
  var html = '';
  if (!cols || cols.length === 0) {
    html = '<div style="padding:32px 0;text-align:center;color:#94a3b8;font-size:13px;">' +
      '<div style="font-size:28px;margin-bottom:8px;">📋</div>' +
      'No individual collections logged.<br><small>Collections may be in the CRM payment system.</small></div>';
  } else {
    var total = 0;
    cols.forEach(function(col) {
      total += parseFloat(col.amount || 0);
      html += '<div style="display:flex;align-items:center;padding:10px 0;border-bottom:1px solid #f8f8f5;gap:10px;">' +
        '<div style="width:7px;height:7px;border-radius:50%;background:#dcfce7;border:2px solid #059669;flex-shrink:0;"></div>' +
        '<div style="flex:1;min-width:0;">' +
          '<div style="font-size:13px;font-weight:600;color:#0f0f0f;">' + esc(col.customer_name || 'Customer') + '</div>' +
          '<div style="font-size:11px;color:#94a3b8;">' + esc(col.service_type || col.payment_method || '') +
            (col.crm_customer_id ? ' · CRM #' + col.crm_customer_id : '') + '</div>' +
        '</div>' +
        '<div style="font-size:14px;font-weight:800;color:#059669;">+$' + parseFloat(col.amount).toFixed(2) + '</div>' +
      '</div>';
    });
    html += '<div style="display:flex;justify-content:space-between;padding:12px 0;font-size:13px;font-weight:700;border-top:2px solid #f1f5f9;margin-top:4px;">' +
      '<span style="color:#64748b;">' + cols.length + ' collection' + (cols.length!==1?'s':'') + '</span>' +
      '<span style="color:#059669;">Total $' + total.toFixed(2) + '</span>' +
    '</div>';
  }
  document.getElementById('hqCpBody').innerHTML = html;
  document.getElementById('hqColPanel').style.display = 'block';
  document.body.style.overflow = 'hidden';
}
function closeColPanel() {
  document.getElementById('hqColPanel').style.display = 'none';
  document.body.style.overflow = '';
}
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Expense Approve modal ─────────────────────────────────────────
var _eId=0, _eName='', _eUsd=0, _eSSP=0, _eCat='', _eCur='USD', _eRate=0, _eLastRate=0;
function openExpApprove(id, name, usd, ssp, cat, cur, prefillRate, lastRate, excRef) {
  _eId=id; _eName=name; _eUsd=usd; _eSSP=ssp; _eCat=cat; _eCur=cur;
  _eRate=prefillRate||lastRate||5800; _eLastRate=lastRate||0;
  document.getElementById('hqeExpId').value = id;
  document.getElementById('hqeTitle').textContent = cur==='SSP' ? '🟡 Approve SSP Expense' : '✅ Approve Expense';
  document.getElementById('hqeSub').textContent   = 'Expense #'+id+' from '+name;
  var iHtml =
    '<div class="hqe-row"><span class="hqe-lbl">Agent</span><span class="hqe-val">'+esc(name)+'</span></div>' +
    '<div class="hqe-row"><span class="hqe-lbl">Category</span><span class="hqe-val">'+esc(cat)+'</span></div>';
  if (cur==='SSP') {
    iHtml += '<div class="hqe-row"><span class="hqe-lbl">SSP Amount</span><span class="hqe-val" style="color:#B45309;">SSP '+parseFloat(ssp).toLocaleString()+'</span></div>';
    if (excRef) {
      iHtml += '<div class="hqe-row"><span class="hqe-lbl">Exchange batch</span><span class="hqe-val" style="color:#7c3aed;font-size:11px;">'+esc(excRef)+'</span></div>';
    }
  } else {
    iHtml += '<div class="hqe-row"><span class="hqe-lbl">Amount</span><span class="hqe-val" style="color:#059669;">$'+parseFloat(usd).toFixed(2)+'</span></div>';
  }
  document.getElementById('hqeInfo').innerHTML = iHtml;

  var rateBlock = document.getElementById('hqeRateBlock');
  var rateInput = document.getElementById('hqeRate');
  if (cur==='SSP') {
    rateBlock.style.display = '';
    // If expense is linked to a batch, the prefillRate IS that batch's actual rate
    rateInput.value = prefillRate || lastRate || 5800;
    if (excRef && prefillRate) {
      document.querySelector('.hqe-rate-lbl').textContent = '🟡 SSP Rate (from linked exchange batch)';
    } else {
      document.querySelector('.hqe-rate-lbl').textContent = '🟡 SSP Rate (pre-filled from last actual exchange)';
    }
    hqeUpdateUSD();
  } else {
    rateBlock.style.display = 'none';
  }
  document.getElementById('hqeOv').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeExpApprove() {
  document.getElementById('hqeOv').classList.remove('open');
  document.body.style.overflow = '';
}
function hqeUpdateUSD() {
  var r = parseFloat(document.getElementById('hqeRate').value) || 0;
  var usd = r > 0 ? (_eSSP / r) : 0;
  document.getElementById('hqeUSDPreview').textContent =
    r > 0 ? 'SSP '+parseFloat(_eSSP).toLocaleString()+' ÷ '+r.toLocaleString()+' = $'+usd.toFixed(2)+' USD' : '—';

  // Rate comparison banner
  var banner = document.getElementById('hqeRateBanner');
  if (banner && _eLastRate > 0 && r > 0) {
    var diff = Math.round(r - _eLastRate);
    var absDiff = Math.abs(diff);
    var pct = ((absDiff / _eLastRate) * 100).toFixed(1);
    var bText, bBg, bClr;
    if (absDiff / _eLastRate > 0.30) {
      bText = '⚠ Rate ' + Math.round(r).toLocaleString() + ' differs >30% from last (' + _eLastRate.toLocaleString() + ') — check for typo';
      bBg = '#fef2f2'; bClr = '#dc2626';
    } else if (absDiff < 10) {
      bText = 'Same as last market rate (' + _eLastRate.toLocaleString() + ' SSP/$)';
      bBg = '#fef3c7'; bClr = '#92400e';
    } else if (diff > 0) {
      bText = '▲ Higher than last rate by ' + absDiff.toLocaleString() + ' SSP/$ (+' + pct + '%)';
      bBg = '#f0fdf4'; bClr = '#16a34a';
    } else {
      bText = '▼ Lower than last rate by ' + absDiff.toLocaleString() + ' SSP/$ (−' + pct + '%) — check with staff';
      bBg = '#fef9ec'; bClr = '#d97706';
    }
    banner.style.display = 'block';
    banner.style.background = bBg; banner.style.color = bClr;
    banner.textContent = bText;
  } else if (banner) { banner.style.display = 'none'; }
}
document.getElementById('hqeOv').addEventListener('click', function(e){ if(e.target===this) closeExpApprove(); });

// ── Expense Reject modal ──────────────────────────────────────────
function openExpReject(id, name, amt, cur) {
  document.getElementById('hqerExpId').value = id;
  document.getElementById('hqerSub').textContent = 'Expense #'+id+' from '+name;
  var disp = cur==='SSP' ? 'SSP '+parseFloat(amt).toLocaleString() : '$'+parseFloat(amt).toFixed(2);
  document.getElementById('hqerInfo').innerHTML =
    '<div class="hqm-row"><span class="hqm-lbl">Agent</span><span class="hqm-val">'+esc(name)+'</span></div>' +
    '<div class="hqm-row"><span class="hqm-lbl">Amount</span><span class="hqm-val" style="color:#D41C1C;">'+disp+'</span></div>';
  document.getElementById('hqerReason').value = '';
  document.getElementById('hqerOv').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeExpReject() {
  document.getElementById('hqerOv').classList.remove('open');
  document.body.style.overflow = '';
}
function hqerValidate() {
  if (!document.getElementById('hqerReason').value.trim()) {
    alert('Please enter a rejection reason.'); return false;
  }
  return true;
}
document.getElementById('hqerOv').addEventListener('click', function(e){ if(e.target===this) closeExpReject(); });

function hqConfirmRecord(formId, name) {
    var form = document.getElementById(formId);
    var amt  = form.querySelector('[name=amount]').value;
    if (!amt || parseFloat(amt) <= 0) return;
    if (confirm('Record handover of $' + parseFloat(amt).toFixed(2) + ' from ' + name + '?\n\nThis confirms receipt and refills their wallet.')) {
        form.submit();
    }
}
function hqConfirmNudge(formId, name, amt, holdingSince) {
    var first = name.split(' ')[0];
    var preview = 'Hi ' + first + ', you have $' + amt + ' in cash';
    if (holdingSince) preview += ' (holding for ' + holdingSince + ')';
    preview += '. Please hand over to the office today.';
    if (confirm('Send WhatsApp reminder to ' + first + '?\n\nPreview:\n"' + preview + '"')) {
        document.getElementById(formId).submit();
    }
}
</script>

<!-- ══ Expense Approve Modal ════════════════════════════════════════ -->
<div class="hqe-ov" id="hqeOv">
  <div class="hqe">
    <div class="hqe-handle"></div>
    <div class="hqe-hd">
      <div class="hqe-title" id="hqeTitle">Approve Expense</div>
      <div class="hqe-sub" id="hqeSub"></div>
    </div>
    <div class="hqe-body">
      <div class="hqe-info" id="hqeInfo"></div>
      <!-- SSP rate block (shown only for SSP expenses) -->
      <div class="hqe-rate-block" id="hqeRateBlock" style="display:none;">
        <div class="hqe-rate-lbl">🟡 SSP Rate (pre-filled from last actual exchange)</div>
        <input class="hqe-rate-input" id="hqeRate" type="number" step="1" min="1"
               placeholder="e.g. 5800" oninput="hqeUpdateUSD()">
        <?php
          $_hqlb = htmlspecialchars($_hqExcCtx['last_by'] ?? '');
          $_hqlm = (int)($_hqExcCtx['last_minutes_ago'] ?? -1);
          $_hqmi = (int)($_hqExcCtx['min_7day'] ?? 0);
          $_hqmx = (int)($_hqExcCtx['max_7day'] ?? 0);
          if ($_hqLastRate > 0 && $_hqlm >= 0 && $_hqlm < 1440) {
              $t = $_hqlm < 1 ? 'just now' : ($_hqlm < 60 ? $_hqlm.'m ago' : round($_hqlm/60).'h ago');
              echo '<div style="font-size:11px;color:#92400e;margin-top:3px;">Last: <strong>'.number_format($_hqLastRate,0).'</strong> SSP/$ by '.$_hqlb.' · '.$t;
              if ($_hqmi > 0 && $_hqmi !== $_hqmx) echo ' &nbsp;·&nbsp; 7d: '.number_format($_hqmi,0).'â'.number_format($_hqmx,0);
              echo '</div>';
          }
        ?>
        <div id="hqeRateBanner" style="display:none;margin-top:5px;border-radius:6px;padding:5px 8px;font-size:11px;font-weight:600;"></div>
        <div class="hqe-usd-preview" id="hqeUSDPreview">—</div>
      </div>
      <form method="POST" action="?page=dashboard&tab=handover_queue" id="hqeForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="approve_expense">
        <input type="hidden" name="expense_id" id="hqeExpId">
        <input type="hidden" name="ssp_rate" id="hqeRateHidden" value="">
        <div class="hqe-btns">
          <button type="button" class="hqe-cancel" onclick="closeExpApprove()">Cancel</button>
          <button type="submit" class="hqe-approve"
            onclick="document.getElementById('hqeRateHidden').value=document.getElementById('hqeRate').value||0;">
            ✓ Approve & Post to Cashbook
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ Expense Reject Modal ══════════════════════════════════════════ -->
<div class="hqm-ov" id="hqerOv">
  <div class="hqm">
    <div class="hqm-handle"></div>
    <div class="hqm-hd">
      <div class="hqm-title">✕ Reject Expense</div>
      <div class="hqm-sub" id="hqerSub"></div>
    </div>
    <div class="hqm-body">
      <div class="hqm-info" id="hqerInfo"></div>
      <form method="POST" action="?page=dashboard&tab=handover_queue" onsubmit="return hqerValidate()">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reject_expense">
        <input type="hidden" name="expense_id" id="hqerExpId">
        <label>Reason for rejection *</label>
        <textarea name="reject_reason" id="hqerReason"
          placeholder="e.g. Receipt photo missing — please resubmit with photo."></textarea>
        <div class="hqm-btns">
          <button type="button" class="hqm-cancel" onclick="closeExpReject()">Cancel</button>
          <button type="submit" class="hqm-submit">Confirm Reject</button>
        </div>
      </form>
    </div>
  </div>
</div>
