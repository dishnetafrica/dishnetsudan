<?php
// ── Field Handover — Diko's Cash Chain Interface ──────────────────────────────
// Shows: (A) pending handovers FROM sales staff → Diko, with confirm buttons
//        (B) Diko's current cash position (what she holds)
//        (C) Relay bundle to Rupesh (with source_handover_ids chain link)
// Access: field_accountant (Diko), accountant (Rupesh), admin
// ─────────────────────────────────────────────────────────────────────────────

$_faRole = $retailer['role'] ?? '';
$_faId   = (int)($retailer['id'] ?? 0);
$_faName = $retailer['name'] ?? 'Field Accountant';

$_canViewAll  = ($retailer['is_admin'] ?? false) || $_faRole === 'accountant';
$_isFieldAcct = $_faRole === 'field_accountant';

if (!$_isFieldAcct && !$_canViewAll) {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied — field accountant, accountant, or admin only.</div>';
    return;
}

// ── Handle: relay handover to Rupesh (POST from this tab) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['fa_action'] ?? '') === 'relay_to_rupesh') {
    // CSRF checked in public.php before tab include — no need to re-check here
    $relayAmount = round((float)($_POST['relay_amount'] ?? 0), 2);
    $relayNote   = trim($_POST['relay_note'] ?? '');
    $toId        = (int)($_POST['relay_to_id'] ?? 0);
    $toName      = trim($_POST['relay_to_name'] ?? '');
    $srcIds      = array_map('intval', explode(',', trim($_POST['source_handover_ids'] ?? '')));
    $srcIds      = array_values(array_filter($srcIds));

    if ($relayAmount <= 0) {
        flash('Enter relay amount.', 'danger');
        redirect('?page=dashboard&tab=field_handover');
    }
    if (!$toId || !$toName) {
        // Auto-find main accountant (Rupesh)
        foreach ($auth->getAllRetailers() as $_r2) {
            if (($_r2['role'] ?? '') === 'accountant' || !empty($_r2['is_admin'])) {
                $toId = (int)$_r2['id']; $toName = $_r2['name']; break;
            }
        }
    }
    if (!$toId) {
        flash('Could not find main accountant to relay to.', 'danger');
        redirect('?page=dashboard&tab=field_handover');
    }

    $fromId   = $_isFieldAcct ? $_faId : (int)($_POST['relay_from_id'] ?? $_faId);
    $fromName = $_isFieldAcct ? $_faName : ($_POST['relay_from_name'] ?? $_faName);

    $hov = [
        'from_id'             => $fromId,
        'from_name'           => $fromName,
        'to_id'               => $toId,
        'to_name'             => $toName,
        'amount'              => $relayAmount,
        'project'             => trim($_POST['relay_project'] ?? 'dishnet'),
        'note'                => $relayNote,
        'status'              => 'pending',
        'type'                => 'relay',                   // marks this as a relay hop
        'source_handover_ids' => $srcIds,                   // chain: which sales-staff HOVs are included
        'created_at'          => date('Y-m-d H:i:s'),
    ];
    $store->appendWithId('cash_handovers.json', $hov);

    // Notify Rupesh that a relay is coming
    try {
        if (!isset($notify)) $notify = svc('notify');
        $_relayRecip = $store->findOne('retailers.json', 'id', $toId);
        if ($_relayRecip && !empty($_relayRecip['phone'])) {
            $_srcNames = [];
            if (!empty($srcIds)) {
                $allHovForNotify = $store->load('cash_handovers.json') ?? [];
                foreach ($allHovForNotify as $_hn) {
                    if (in_array((int)($_hn['id'] ?? 0), $srcIds, true)) {
                        $_srcNames[] = ($_hn['from_name'] ?? 'Staff') . ' $' . number_format((float)($_hn['amount'] ?? 0), 0);
                    }
                }
            }
            $_relayMsg = "🔗 *Field Cash Relay*\n\n"
                       . "👤 From: *{$fromName}* (Field Accountant)\n"
                       . "💰 Amount: *\${$relayAmount}*\n"
                       . ($relayNote ? "📝 {$relayNote}\n" : '')
                       . (!empty($_srcNames) ? "⛓ Includes: " . implode(', ', $_srcNames) . "\n" : '')
                       . "⏰ " . date('M j, g:i A') . "\n\n"
                       . "👉 Open *Handover Queue* to confirm receipt.";
            $notify->sendVia('support', $_relayRecip['phone'], $_relayMsg,
                'field_relay_incoming',
                ['from' => $fromName, 'amount' => (string)$relayAmount]
            );
        }
    } catch (\Throwable $_relayNotifyErr) { /* non-fatal */ }

    // Update snapshot
    try {
        if (!class_exists('SnapshotService')) require_once __DIR__ . '/../../lib/SnapshotService.php';
        (new SnapshotService($store->getPdo(), $store))->rebuild($fromId, 'relay_handover', 'RELAY-' . date('ymdHis'));
    } catch (\Throwable $e) {}

    logActivity($dataDir, 'relay_handover_submitted', 'Field accountant relayed cash to main accountant',
        "\${$relayAmount} from {$fromName} → {$toName}. Sources: [" . implode(',', $srcIds) . ']');
    flash("Relay of \${$relayAmount} submitted to {$toName}. Waiting confirmation.", 'success');
    redirect('?page=dashboard&tab=field_handover');
}

// ── Load data ────────────────────────────────────────────────────────────────
$allHov = $store->load('cash_handovers.json') ?? [];

// For field_accountant: scope to their ID. For admin/accountant: show all.
$myIncomingId = $_isFieldAcct ? $_faId : (int)($_GET['fa_id'] ?? 0);

// A) Incoming from sales staff (to_id = Diko, status = pending)
$pendingIncoming = array_values(array_filter($allHov, function ($h) use ($myIncomingId) {
    return (int)($h['to_id'] ?? 0) === $myIncomingId
        && ($h['status'] ?? '') === 'pending'
        && ($h['type'] ?? '') !== 'relay';   // exclude relay handovers from own queue
}));

// B) Confirmed incoming (to_id = Diko, status = confirmed) — what Diko received
$confirmedIncoming = array_values(array_filter($allHov, function ($h) use ($myIncomingId) {
    return (int)($h['to_id'] ?? 0) === $myIncomingId
        && ($h['status'] ?? '') === 'confirmed';
}));

// C) Outgoing from Diko (from_id = Diko) — relayed to Rupesh
$myRelays = array_values(array_filter($allHov, function ($h) use ($myIncomingId) {
    return (int)($h['from_id'] ?? 0) === $myIncomingId
        && ($h['type'] ?? '') === 'relay';
}));
$myRelaysPending   = array_filter($myRelays, fn($h) => ($h['status'] ?? '') === 'pending');
$myRelaysConfirmed = array_filter($myRelays, fn($h) => ($h['status'] ?? '') === 'confirmed');

// Cash Diko holds = sum of confirmed receipts – sum of relays (any status because pending = en route)
$totalReceived = array_sum(array_map(fn($h) => (float)($h['amount'] ?? 0), $confirmedIncoming));
$totalRelayed  = array_sum(array_map(fn($h) => (float)($h['amount'] ?? 0), $myRelays));
$dikoHolding   = max(0, $totalReceived - $totalRelayed);

// Confirmed incoming not yet relayed — helps pre-fill source_handover_ids
$confirmedNotRelayed = array_filter($confirmedIncoming, function ($h) use ($myRelays) {
    $allSrcIds = [];
    foreach ($myRelays as $r) {
        foreach ((array)($r['source_handover_ids'] ?? []) as $sid) {
            $allSrcIds[] = (int)$sid;
        }
    }
    return !in_array((int)($h['id'] ?? 0), $allSrcIds, true);
});
$confirmedNotRelayedIds = implode(',', array_map(fn($h) => (int)$h['id'], $confirmedNotRelayed));

// Find main accountant for relay target
$mainAcct = null;
foreach ($auth->getAllRetailers() as $_r3) {
    if (($_r3['role'] ?? '') === 'accountant' || !empty($_r3['is_admin'])) {
        $mainAcct = $_r3; break;
    }
}

// Field accountant list for admin view
$allFieldAccts = array_values(array_filter($auth->getAllRetailers(), fn($r) => ($r['role'] ?? '') === 'field_accountant'));
?>

<style>
/* ── Mobile-first: field_handover ───────────────────────────────── */
.fhq-page   { max-width: 860px; margin: 0 auto; padding: 0 14px 90px; }
.fhq-title  { font-size: 18px; font-weight: 900; color: #1e293b; margin: 0 0 4px; }
.fhq-sub    { font-size: 12px; color: #64748b; margin-bottom: 18px; }

/* Stats grid — 2-col always, single col below 380px */
.fhq-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
@media(max-width:380px){ .fhq-grid{ grid-template-columns:1fr; } }
.fhq-stat   { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 14px 16px; }
.fhq-stat-val { font-size: 24px; font-weight: 900; letter-spacing: -1px; }
.fhq-stat-lbl { font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }

/* Cards */
.fhq-section { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 16px; padding: 16px; margin-bottom: 14px; }
.fhq-sec-title { font-size: 13px; font-weight: 800; color: #1e293b; margin-bottom: 12px;
                 display: flex; flex-wrap: wrap; gap: 6px; justify-content: space-between; align-items: center; }
.fhq-badge  { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; white-space: nowrap; }
.fhq-badge-warn  { background: #fef3c7; color: #92400e; }
.fhq-badge-ok    { background: #dcfce7; color: #065f46; }
.fhq-badge-blue  { background: #dbeafe; color: #1e40af; }

/* ── Handover row — wraps on mobile ── */
.fhq-row    { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 10px;
              padding: 13px 0; border-bottom: 1px solid #f1f5f9; }
.fhq-row:last-child { border-bottom: none; }
.fhq-row-left  { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.fhq-row-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.fhq-row-bottom { width: 100%; }  /* confirm button full-width row on mobile */

.fhq-avatar { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center;
              justify-content: center; font-weight: 800; font-size: 16px; flex-shrink: 0; }
.fhq-row-name  { font-size: 13px; font-weight: 700; color: #1e293b; word-break: break-word; }
.fhq-row-meta  { font-size: 11px; color: #94a3b8; margin-top: 2px; line-height: 1.4; }
.fhq-row-amt   { font-size: 16px; font-weight: 900; color: #1e293b; white-space: nowrap; }
.fhq-row-status { font-size: 10px; color: #94a3b8; }

/* ── Confirm button — full width, 48px tall (fat finger safe) ── */
.fhq-confirm-btn {
    display: block; width: 100%;
    background: #16a34a; color: #fff; border: none; border-radius: 12px;
    padding: 13px 16px; font-size: 14px; font-weight: 800; cursor: pointer;
    min-height: 48px; touch-action: manipulation; -webkit-tap-highlight-color: transparent;
    margin-top: 4px;
}
.fhq-confirm-btn:active { background: #15803d; transform: scale(.98); }

/* ── Form inputs — 16px prevents iOS auto-zoom ── */
.fhq-input {
    width: 100%; box-sizing: border-box;
    border: 1.5px solid #e2e8f0; border-radius: 12px;
    padding: 12px 14px; font-size: 16px; font-weight: 700; outline: none;
    -webkit-appearance: none; appearance: none;
}
.fhq-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
input[type=number].fhq-input { font-size: 22px; text-align: center; font-weight: 900; letter-spacing: -1px; }

/* ── Relay button ── */
.fhq-relay-btn {
    display: block; width: 100%;
    background: linear-gradient(135deg,#6366f1,#4f46e5); color: #fff;
    border: none; border-radius: 14px; padding: 16px;
    font-size: 16px; font-weight: 800; cursor: pointer; margin-top: 14px;
    min-height: 52px; touch-action: manipulation; -webkit-tap-highlight-color: transparent;
    box-shadow: 0 4px 14px rgba(99,102,241,.3);
}
.fhq-relay-btn:active { opacity: .9; transform: scale(.99); }

/* Project radio pills */
.fhq-proj-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.fhq-proj-pill  { flex: 1; min-width: 80px; }
.fhq-proj-pill label {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 11px 10px; border-radius: 12px; cursor: pointer;
    font-size: 13px; font-weight: 700; text-align: center;
    border: 2px solid transparent; min-height: 46px;
    touch-action: manipulation;
}

.fhq-empty  { text-align: center; padding: 28px 16px; color: #94a3b8; font-size: 13px; line-height: 1.6; }
.fhq-chain  { font-size: 10px; color: #94a3b8; margin-top: 4px; }
.fhq-chain span { background: #f1f5f9; border-radius: 4px; padding: 2px 6px; margin-right: 3px;
                  display: inline-block; margin-bottom: 2px; }
</style>

<div class="fhq-page">

<div class="fhq-title">💼 Field Cash Handover<?= $_isFieldAcct ? '' : ' Chain' ?></div>
<div class="fhq-sub">
    <?php if ($_isFieldAcct): ?>
        Confirm cash received from sales staff · then relay to Rupesh
    <?php elseif ($myIncomingId && $mainAcct): ?>
        Viewing chain for: <strong><?= h(array_values(array_filter($allFieldAccts, fn($r) => (int)$r['id'] === $myIncomingId))[0]['name'] ?? 'Field Staff') ?></strong>
    <?php else: ?>
        Full chain overview — all field accountants
    <?php endif; ?>
</div>

<?php if ($_canViewAll && $allFieldAccts): ?>
<div class="fhq-section" style="padding:12px 18px;margin-bottom:16px;">
    <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px;">Filter by field accountant</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="?page=dashboard&tab=field_handover" style="font-size:12px;font-weight:700;padding:6px 14px;border-radius:8px;text-decoration:none;background:<?= !$myIncomingId ? '#6366f1' : '#f1f5f9' ?>;color:<?= !$myIncomingId ? '#fff' : '#475569' ?>;">All</a>
        <?php foreach ($allFieldAccts as $_fa2): ?>
        <a href="?page=dashboard&tab=field_handover&fa_id=<?= (int)$_fa2['id'] ?>"
           style="font-size:12px;font-weight:700;padding:6px 14px;border-radius:8px;text-decoration:none;background:<?= $myIncomingId===(int)$_fa2['id']?'#6366f1':'#f1f5f9' ?>;color:<?= $myIncomingId===(int)$_fa2['id']?'#fff':'#475569' ?>;">
            <?= h($_fa2['name']) ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Stats ────────────────────────────────────────────────────────────── -->
<div class="fhq-grid">
    <div class="fhq-stat">
        <div class="fhq-stat-val" style="color:#f59e0b;"><?= count($pendingIncoming) ?></div>
        <div class="fhq-stat-lbl">⏳ Pending Incoming</div>
    </div>
    <div class="fhq-stat">
        <div class="fhq-stat-val" style="color:#16a34a;">$<?= number_format($totalReceived, 2) ?></div>
        <div class="fhq-stat-lbl">✅ Total Received</div>
    </div>
    <div class="fhq-stat">
        <div class="fhq-stat-val" style="color:#6366f1;">$<?= number_format($totalRelayed, 2) ?></div>
        <div class="fhq-stat-lbl">📤 Relayed to Office</div>
    </div>
    <div class="fhq-stat" style="border-color:<?= $dikoHolding > 0 ? '#fde68a' : '#bbf7d0' ?>;background:<?= $dikoHolding > 0 ? '#fffbeb' : '#f0fdf4' ?>;">
        <div class="fhq-stat-val" style="color:<?= $dikoHolding > 0 ? '#92400e' : '#15803d' ?>;">$<?= number_format($dikoHolding, 2) ?></div>
        <div class="fhq-stat-lbl">💼 Currently Holding</div>
    </div>
</div>

<!-- ── A) Pending Incoming from Sales Staff ──────────────────────────────── -->
<div class="fhq-section">
    <div class="fhq-sec-title">
        📥 Incoming from Sales Staff
        <?php if (count($pendingIncoming)): ?>
            <span class="fhq-badge fhq-badge-warn"><?= count($pendingIncoming) ?> pending</span>
        <?php else: ?>
            <span class="fhq-badge fhq-badge-ok">All clear</span>
        <?php endif; ?>
    </div>

    <?php if (empty($pendingIncoming)): ?>
        <div class="fhq-empty">🎉 No pending handovers waiting for confirmation.</div>
    <?php else: ?>
        <?php foreach ($pendingIncoming as $ph): ?>
        <?php
            $phAmt  = (float)($ph['amount'] ?? 0);
            $phSsp  = (float)($ph['ssp_amount'] ?? 0);
            $phDate = substr($ph['created_at'] ?? '', 0, 16);
            $phInit = mb_substr($ph['from_name'] ?? '?', 0, 1);
            $phProj = $ph['project'] ?? 'dishnet';
            $phProjColors = ['dishnet' => '#1565C0', '4g' => '#E65100', 'bluecard' => '#2E7D32'];
            $phColor = $phProjColors[$phProj] ?? '#64748b';
        ?>
        <div class="fhq-row">
            <!-- Top: avatar + info on left, amount on right -->
            <div class="fhq-row-left">
                <div class="fhq-avatar" style="background:<?= $phColor ?>22;color:<?= $phColor ?>;"><?= h($phInit) ?></div>
                <div style="min-width:0;">
                    <div class="fhq-row-name"><?= h($ph['from_name'] ?? 'Unknown') ?></div>
                    <div class="fhq-row-meta">
                        <?= strtoupper($phProj) ?> · <?= $phDate ?>
                        <?php if ($ph['note'] ?? ''): ?><br>"<?= h($ph['note']) ?>"<?php endif; ?>
                        <?php if ($phSsp > 0): ?><br>SSP <?= number_format($phSsp, 0) ?> incl.<?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="fhq-row-right">
                <div class="fhq-row-amt">$<?= number_format($phAmt, 2) ?></div>
                <div class="fhq-row-status">HOV-<?= (int)($ph['id'] ?? 0) ?></div>
            </div>
            <!-- Confirm button — full width below row on mobile -->
            <div class="fhq-row-bottom">
            <form method="POST" action="?page=dashboard&tab=field_handover" style="margin:0;"
                  onsubmit="return confirm('Confirm receipt of $<?= number_format($phAmt, 2) ?> from <?= addslashes($ph['from_name'] ?? '') ?>?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="confirm_handover">
                <input type="hidden" name="handover_id" value="<?= (int)($ph['id'] ?? 0) ?>">
                <input type="hidden" name="confirm_notes" value="Confirmed by <?= h($_faName) ?>">
                <button type="submit" class="fhq-confirm-btn">✅ Confirm Receipt · $<?= number_format($phAmt, 2) ?></button>
            </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── B) Confirmed Incoming (what Diko has received) ───────────────────── -->
<?php if (!empty($confirmedIncoming)): ?>
<div class="fhq-section">
    <div class="fhq-sec-title">
        ✅ Received from Field (Confirmed)
        <span class="fhq-badge fhq-badge-ok"><?= count($confirmedIncoming) ?> items · $<?= number_format($totalReceived, 2) ?></span>
    </div>
    <?php foreach (array_slice(array_reverse($confirmedIncoming), 0, 20) as $ch): ?>
    <?php
        $chId   = (int)($ch['id'] ?? 0);
        $chAmt  = (float)($ch['amount'] ?? 0);
        $chDate = substr($ch['confirmed_at'] ?? $ch['created_at'] ?? '', 0, 10);
        $chProj = $ch['project'] ?? 'dishnet';
        $chProjColors = ['dishnet' => '#1565C0', '4g' => '#E65100', 'bluecard' => '#2E7D32'];
        $chColor = $chProjColors[$chProj] ?? '#64748b';
        $chInit  = mb_substr($ch['from_name'] ?? '?', 0, 1);
        // Check if already relayed
        $chRelayed = false;
        foreach ($myRelays as $rel) {
            if (in_array($chId, (array)($rel['source_handover_ids'] ?? []), true)) {
                $chRelayed = true; break;
            }
        }
    ?>
    <div class="fhq-row" style="<?= $chRelayed ? 'opacity:.55;' : '' ?>">
        <div class="fhq-avatar" style="background:<?= $chColor ?>22;color:<?= $chColor ?>;"><?= h($chInit) ?></div>
        <div style="flex:1;min-width:0;">
            <div class="fhq-row-name"><?= h($ch['from_name'] ?? 'Unknown') ?></div>
            <div class="fhq-row-meta"><?= strtoupper($chProj) ?> · <?= $chDate ?></div>
        </div>
        <div style="text-align:right;">
            <div class="fhq-row-amt">$<?= number_format($chAmt, 2) ?></div>
            <div class="fhq-row-status"><?= $chRelayed ? '📤 Relayed' : '💼 Holding' ?> · HOV-<?= $chId ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── C) Relay to Rupesh ────────────────────────────────────────────────── -->
<div class="fhq-section" style="border-color:#6366f1;<?= $dikoHolding <= 0 ? 'opacity:.7;' : '' ?>">
    <div class="fhq-sec-title">
        📤 Relay Cash to<?= $mainAcct ? ' ' . h($mainAcct['name']) : ' Main Accountant' ?>
        <?php if (count($myRelaysPending)): ?>
            <span class="fhq-badge fhq-badge-warn"><?= count($myRelaysPending) ?> pending relay</span>
        <?php endif; ?>
    </div>

    <?php if ($dikoHolding > 0 || ($retailer['is_admin'] ?? false)): ?>
    <div style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#475569;">
        💡 You are holding <strong>$<?= number_format($dikoHolding, 2) ?></strong> in confirmed receipts not yet relayed.
        <?php if ($confirmedNotRelayedIds): ?>
        This relay will automatically link to <?= count($confirmedNotRelayed) ?> confirmed handover(s): 
        <?php foreach ($confirmedNotRelayed as $nr): ?>
            <span style="background:#eff6ff;color:#1d4ed8;border-radius:4px;padding:1px 6px;font-size:11px;margin-right:2px;display:inline-block;">HOV-<?= (int)$nr['id'] ?> (<?= h($nr['from_name'] ?? '') ?>)</span>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <form method="POST" action="?page=dashboard&tab=field_handover" onsubmit="return confirm('Relay $' + document.getElementById('relayAmt').value + ' to <?= addslashes($mainAcct['name'] ?? 'Main Accountant') ?>?');">
        <?= csrfField() ?>
        <input type="hidden" name="fa_action" value="relay_to_rupesh">
        <input type="hidden" name="relay_to_id"   value="<?= (int)($mainAcct['id'] ?? 0) ?>">
        <input type="hidden" name="relay_to_name"  value="<?= h($mainAcct['name'] ?? '') ?>">
        <input type="hidden" name="source_handover_ids" value="<?= h($confirmedNotRelayedIds) ?>">
        <?php if ($_canViewAll && $allFieldAccts): ?>
        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">RELAYING ON BEHALF OF</label>
        <select name="relay_from_id" class="fhq-input" style="margin-bottom:12px;font-weight:600;">
            <?php foreach ($allFieldAccts as $_fa3): ?>
            <option value="<?= (int)$_fa3['id'] ?>" data-name="<?= h($_fa3['name']) ?>" <?= (int)$_fa3['id']===$myIncomingId?'selected':'' ?>><?= h($_fa3['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="relay_from_name" id="relayFromNameHidden" value="">
        <script>
        document.querySelector('select[name="relay_from_id"]').addEventListener('change', function(){
            var opt = this.options[this.selectedIndex];
            document.getElementById('relayFromNameHidden').value = opt.dataset.name;
        });
        document.addEventListener('DOMContentLoaded',function(){
            var sel=document.querySelector('select[name="relay_from_id"]');
            if(sel){ document.getElementById('relayFromNameHidden').value=sel.options[sel.selectedIndex]?.dataset?.name||''; }
        });
        </script>
        <?php endif; ?>

        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">RELAY AMOUNT (USD)</label>
        <input type="number" id="relayAmt" name="relay_amount" class="fhq-input" step="0.01" min="0.01"
               value="<?= number_format($dikoHolding, 2, '.', '') ?>"
               placeholder="<?= number_format($dikoHolding, 2) ?>" required
               style="font-size:22px;font-weight:900;text-align:center;margin-bottom:10px;">

        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:6px;">PROJECT</label>
        <div class="fhq-proj-pills">
            <?php foreach (['dishnet'=>['DishNet','#1565C0','#E3F2FD'],'4g'=>['4G LTE','#E65100','#FFF3E0'],'bluecard'=>['BlueCARD','#2E7D32','#E8F5E9']] as $pk=>$pv): ?>
            <div class="fhq-proj-pill">
              <input type="radio" name="relay_project" id="rproj_<?= $pk ?>" value="<?= $pk ?>"
                     <?= $pk==='dishnet'?'checked':'' ?> style="display:none;"
                     onchange="fhqUpdatePills()">
              <label for="rproj_<?= $pk ?>" class="fhq-proj-pill-lbl"
                     style="border-color:<?= $pv[2] ?>;color:<?= $pv[1] ?>;background:<?= $pv[2] ?>;"
                     data-active-bg="<?= $pv[1] ?>" data-base-bg="<?= $pv[2] ?>" data-color="<?= $pv[1] ?>">
                <?= $pv[0] ?>
              </label>
            </div>
            <?php endforeach; ?>
        </div>
        <script>
        function fhqUpdatePills(){
          document.querySelectorAll('input[name="relay_project"]').forEach(function(r){
            var lbl = document.querySelector('label[for="'+r.id+'"]');
            if(!lbl) return;
            if(r.checked){
              lbl.style.background = lbl.dataset.activeBg;
              lbl.style.color = '#fff';
              lbl.style.borderColor = lbl.dataset.activeBg;
            } else {
              lbl.style.background = lbl.dataset.baseBg;
              lbl.style.color = lbl.dataset.color;
              lbl.style.borderColor = lbl.dataset.baseBg;
            }
          });
        }
        document.addEventListener('DOMContentLoaded', fhqUpdatePills);
        </script>

        <label style="font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:4px;">NOTE (optional)</label>
        <input type="text" name="relay_note" class="fhq-input" placeholder="e.g. Day collection Juba Market..." style="margin-bottom:0;">

        <button type="submit" class="fhq-relay-btn">📤 Submit Relay to <?= h($mainAcct['name'] ?? 'Main Accountant') ?></button>
    </form>
    <?php else: ?>
    <div class="fhq-empty">
        Nothing to relay yet.<br>
        <span style="font-size:12px;">Confirm incoming handovers first, then relay the total to <?= h($mainAcct['name'] ?? 'Main Accountant') ?>.</span>
    </div>
    <?php endif; ?>
</div>

<!-- ── D) My Relay History ───────────────────────────────────────────────── -->
<?php if (!empty($myRelays)): ?>
<div class="fhq-section">
    <div class="fhq-sec-title">
        📋 My Relay History
        <span class="fhq-badge fhq-badge-blue"><?= count($myRelays) ?> relays</span>
    </div>
    <?php foreach (array_slice(array_reverse($myRelays), 0, 15) as $rel): ?>
    <?php
        $relId     = (int)($rel['id'] ?? 0);
        $relAmt    = (float)($rel['amount'] ?? 0);
        $relDate   = substr($rel['created_at'] ?? '', 0, 16);
        $relStatus = $rel['status'] ?? 'pending';
        $relSrcIds = (array)($rel['source_handover_ids'] ?? []);
        $statusColors = ['pending'=>'#fef3c7|#92400e','confirmed'=>'#dcfce7|#065f46','rejected'=>'#fee2e2|#991b1b'];
        [$scBg,$scTxt] = explode('|', $statusColors[$relStatus] ?? '#f1f5f9|#475569');
    ?>
    <div class="fhq-row">
        <div class="fhq-avatar" style="background:#ede9fe;color:#7c3aed;">📤</div>
        <div style="flex:1;min-width:0;">
            <div class="fhq-row-name">To <?= h($rel['to_name'] ?? 'Main Accountant') ?></div>
            <div class="fhq-row-meta"><?= $relDate ?><?= $rel['note'] ? ' · "' . h($rel['note']) . '"' : '' ?></div>
            <?php if ($relSrcIds): ?>
            <div class="fhq-chain">
                Includes:
                <?php foreach ($relSrcIds as $sid): ?>
                <span>HOV-<?= (int)$sid ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;">
            <div class="fhq-row-amt">$<?= number_format($relAmt, 2) ?></div>
            <div><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:<?= $scBg ?>;color:<?= $scTxt ?>;"><?= strtoupper($relStatus) ?></span></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /fhq-page -->
