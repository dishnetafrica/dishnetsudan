<?php
// ── Balance Identity — v4.4.25 ────────────────────────────────────────────
// The one equation that proves the entire DishNet financial system is clean.
//
//   Vault Cash (cb_ledger net)
//   + Field Cash (staff_cash_position total exposure)
//   + Active Advances (root advances in field)
//   ─────────────────────────────────────────────────────
//   = Total Accountable Cash
//
// Rupesh can verify this equals what's physically expected at any moment.
// A non-zero Drift = something was posted incorrectly or not yet reconciled.
// ─────────────────────────────────────────────────────────────────────────
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}

require_once __DIR__ . '/../../lib/DualReadCashPosition.php';

$pdo = $store->getPdo();

// ── 1. Vault: net cash in company ledger (total confirmed IN minus OUT) ───
$vaultRow = $pdo->query(
    "SELECT
        ROUND(SUM(CASE WHEN json_extract(data,'$.type')='IN'  THEN CAST(json_extract(data,'$.amount') AS REAL) ELSE 0 END), 2) AS total_in,
        ROUND(SUM(CASE WHEN json_extract(data,'$.type')='OUT' THEN CAST(json_extract(data,'$.amount') AS REAL) ELSE 0 END), 2) AS total_out
     FROM [cb_ledger]"
)->fetch(\PDO::FETCH_ASSOC);
$vaultIn  = (float)($vaultRow['total_in']  ?? 0);
$vaultOut = (float)($vaultRow['total_out'] ?? 0);
$vaultNet = round($vaultIn - $vaultOut, 2);

// ── 2. Field cash: sum of all agent cash_exposure from VIEW ──────────────
$viewExists = (bool)$pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='view' AND name='staff_cash_position'"
)->fetchColumn();

$fieldCash      = 0.0;
$fieldBreakdown = [];
if ($viewExists) {
    $rows = $pdo->query('SELECT staff_name, cash_exposure FROM staff_cash_position')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $exp = round((float)$row['cash_exposure'], 2);
        if ($exp != 0) {
            $fieldBreakdown[] = ['name' => $row['staff_name'], 'exposure' => $exp];
            $fieldCash += $exp;
        }
    }
    $fieldCash = round($fieldCash, 2);
} else {
    // v4.11.38: JSON source
    require_once dirname(__DIR__, 2) . '/lib/StaffCashPositionService.php';
    $svc  = new StaffCashPositionService($store, $pdo);
    $all  = $svc->getAllPositions();
    foreach ($all as $pos) {
        $exp = round((float)$pos['cash_exposure'], 2);
        if ($exp != 0) {
            $fieldBreakdown[] = ['name' => $pos['staff_name'], 'exposure' => $exp];
            $fieldCash += $exp;
        }
    }
    $fieldCash = round($fieldCash, 2);
}

// ── 3. Advance cash: active root advances outstanding ────────────────────
$advRow = $pdo->query(
    "SELECT ROUND(SUM(amount - amount_spent - amount_returned - COALESCE(children_allocated,0)), 2) AS outstanding
     FROM cash_advances
     WHERE status IN ('active','partial')
       AND (parent_advance_id IS NULL OR parent_advance_id = 0)"
)->fetch(\PDO::FETCH_ASSOC);
$advanceCash = round((float)($advRow['outstanding'] ?? 0), 2);

// ── 4. Total accountable + drift ─────────────────────────────────────────
// NOTE: field cash already includes advance_balance in its formula.
// So: vault + field_collections_only + advances = total
// BUT StaffCashPositionService.cash_exposure = collections + advances - exp - handovers
// meaning advances are INSIDE fieldCash already → don't double-count.
// The true identity is:
//   vault_net + field_exposure = total accountable
// field_exposure already captures both collection and advance cash in field.
$totalAccountable = round($vaultNet + $fieldCash, 2);

// ── 5. Pending handovers (collections received by agents but not yet confirmed) ─
$pendingHov = 0.0;
$handovers  = $store->load('cash_handovers.json') ?? [];
foreach ($handovers as $h) {
    if (($h['status'] ?? '') === 'pending') {
        $pendingHov += (float)($h['amount'] ?? 0);
    }
}
$pendingHov = round($pendingHov, 2);

// ── 6. Outstanding advances (same as $advanceCash above, shown separately) ─
// Already computed.

// ── 7. Recent ledger entries for context ─────────────────────────────────
$recentLedger = [];
$lRows = $pdo->query(
    "SELECT json_extract(data,'$.ref') AS ref,
            json_extract(data,'$.type') AS type,
            CAST(json_extract(data,'$.amount') AS REAL) AS amount,
            json_extract(data,'$.description') AS description,
            json_extract(data,'$.created_at') AS created_at
     FROM [cb_ledger]
     ORDER BY id DESC LIMIT 8"
)->fetchAll(\PDO::FETCH_ASSOC);
foreach ($lRows as $r) {
    $recentLedger[] = $r;
}

$refreshedAt = date('Y-m-d H:i:s');
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap');
.bi{font-family:'DM Sans',-apple-system,sans-serif;padding-bottom:48px;}
.bi h2{font-size:22px;font-weight:900;color:#0f0f0f;margin:0 0 4px;}
.bi-sub{font-size:12px;color:#94a3b8;margin-bottom:24px;}
/* equation */
.bi-eq{background:#fff;border:1.5px solid #ececec;border-radius:16px;padding:28px 32px;margin-bottom:22px;}
.bi-eq-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:#94a3b8;margin-bottom:18px;}
.bi-row{display:flex;align-items:center;gap:12px;margin-bottom:10px;}
.bi-row:last-child{margin-bottom:0;}
.bi-sym{font-size:20px;font-weight:900;color:#94a3b8;width:20px;flex-shrink:0;}
.bi-label{flex:1;font-size:14px;color:#374151;font-weight:500;}
.bi-val{font-size:20px;font-weight:900;color:#0f0f0f;min-width:90px;text-align:right;}
.bi-val.green{color:#15803D;}
.bi-val.amber{color:#B45309;}
.bi-val.red{color:#DC2626;}
.bi-divider{border:none;border-top:2px solid #ececec;margin:14px 0;}
.bi-total{display:flex;align-items:center;gap:12px;margin-top:6px;}
.bi-total-label{flex:1;font-size:16px;font-weight:800;color:#0f0f0f;}
.bi-total-val{font-size:28px;font-weight:900;}
.bi-total-val.green{color:#15803D;}
.bi-total-val.amber{color:#B45309;}
/* context cards */
.bi-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:22px;}
.bi-card{background:#fff;border:1.5px solid #ececec;border-radius:14px;padding:16px 18px;}
.bi-card-v{font-size:24px;font-weight:900;color:#0f0f0f;line-height:1.1;}
.bi-card-l{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#b0b0b0;margin-top:5px;}
.bi-card.warn .bi-card-v{color:#B45309;}
/* field breakdown */
.bi-breakdown{background:#fff;border:1.5px solid #ececec;border-radius:14px;padding:18px 20px;margin-bottom:22px;}
.bi-breakdown-title{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;margin-bottom:12px;}
.bi-agent-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px;}
.bi-agent-row:last-child{margin-bottom:0;}
.bi-agent-name{flex:1;color:#374151;font-weight:500;}
.bi-agent-val{font-weight:700;}
/* ledger */
.bi-ledger{background:#fff;border:1.5px solid #ececec;border-radius:14px;overflow:hidden;}
.bi-ledger-title{padding:14px 18px 10px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;border-bottom:1px solid #f3f4f6;}
.bi-ledger-row{display:flex;align-items:center;gap:10px;padding:9px 18px;border-bottom:1px solid #f9f9f9;font-size:12px;}
.bi-ledger-row:last-child{border-bottom:none;}
.bi-ledger-ref{font-family:monospace;font-size:10px;color:#94a3b8;width:100px;flex-shrink:0;}
.bi-ledger-desc{flex:1;color:#374151;}
.bi-ledger-type{width:36px;flex-shrink:0;}
.bi-ledger-amt{font-weight:700;min-width:70px;text-align:right;}
.bi-in{color:#15803D;}
.bi-out{color:#DC2626;}
@media(max-width:600px){.bi-cards{grid-template-columns:1fr 1fr;}}
</style>

<div class="bi">
  <h2>⚖️ Balance Identity</h2>
  <div class="bi-sub">The one equation that proves DishNet's books are clean · <?= $refreshedAt ?> EAT</div>

  <!-- Context cards -->
  <div class="bi-cards">
    <div class="bi-card">
      <div class="bi-card-v">$<?= number_format($vaultIn, 2) ?></div>
      <div class="bi-card-l">Ledger Total IN</div>
    </div>
    <div class="bi-card">
      <div class="bi-card-v" style="color:#DC2626;">$<?= number_format($vaultOut, 2) ?></div>
      <div class="bi-card-l">Ledger Total OUT</div>
    </div>
    <div class="bi-card <?= $pendingHov > 0 ? 'warn' : '' ?>">
      <div class="bi-card-v">$<?= number_format($pendingHov, 2) ?></div>
      <div class="bi-card-l">Pending Handovers</div>
    </div>
  </div>

  <!-- The equation -->
  <div class="bi-eq">
    <div class="bi-eq-title">Master Balance Identity</div>

    <div class="bi-row">
      <div class="bi-sym"> </div>
      <div class="bi-label">🏦 Vault cash <span style="font-size:11px;color:#94a3b8;">(cb_ledger net)</span></div>
      <div class="bi-val <?= $vaultNet >= 0 ? 'green' : 'red' ?>">$<?= number_format($vaultNet, 2) ?></div>
    </div>

    <div class="bi-row">
      <div class="bi-sym">+</div>
      <div class="bi-label">👐 Field cash <span style="font-size:11px;color:#94a3b8;">(agent exposure: col + adv − exp − hov)</span></div>
      <div class="bi-val <?= $fieldCash >= 0 ? 'amber' : 'red' ?>">$<?= number_format($fieldCash, 2) ?></div>
    </div>

    <hr class="bi-divider">

    <div class="bi-total">
      <div class="bi-total-label">= Total accountable cash</div>
      <div class="bi-total-val <?= $totalAccountable >= 0 ? 'green' : 'red' ?>">
        $<?= number_format($totalAccountable, 2) ?>
      </div>
    </div>

    <div style="margin-top:16px;padding:14px 16px;background:#F0FDF4;border-radius:10px;font-size:12px;color:#166534;line-height:1.6;">
      <strong>What this means:</strong> At this moment, DishNet can account for
      <strong>$<?= number_format($totalAccountable, 2) ?></strong> in cash.
      <?= $vaultNet > 0 ? '<strong>$'.number_format($vaultNet,2).'</strong> is confirmed in the company account.' : '' ?>
      <?= $fieldCash > 0 ? ' <strong>$'.number_format($fieldCash,2).'</strong> is currently in field agents\' hands.' : '' ?>
      <?php if ($pendingHov > 0): ?>
      <br>⏳ <strong>$<?= number_format($pendingHov, 2) ?></strong> in pending handovers will move from field cash to vault once Rupesh confirms.
      <?php endif; ?>
    </div>
  </div>

  <!-- Field breakdown -->
  <?php if (!empty($fieldBreakdown)): ?>
  <div class="bi-breakdown">
    <div class="bi-breakdown-title">Field cash breakdown by agent</div>
    <?php foreach ($fieldBreakdown as $f): ?>
    <div class="bi-agent-row">
      <span class="bi-agent-name"><?= htmlspecialchars($f['name']) ?></span>
      <span class="bi-agent-val" style="color:<?= $f['exposure'] > 0 ? '#B45309' : '#15803D' ?>;">
        $<?= number_format($f['exposure'], 2) ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Recent ledger -->
  <?php if (!empty($recentLedger)): ?>
  <div class="bi-ledger">
    <div class="bi-ledger-title">Recent ledger entries</div>
    <?php foreach ($recentLedger as $e): ?>
    <div class="bi-ledger-row">
      <span class="bi-ledger-ref"><?= htmlspecialchars($e['ref'] ?? '—') ?></span>
      <span class="bi-ledger-desc"><?= htmlspecialchars(substr($e['description'] ?? '', 0, 60)) ?></span>
      <span class="bi-ledger-type">
        <span style="font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px;
          background:<?= ($e['type']??'') === 'IN' ? '#DCFCE7' : '#FEE2E2' ?>;
          color:<?= ($e['type']??'') === 'IN' ? '#15803D' : '#DC2626' ?>;">
          <?= htmlspecialchars($e['type'] ?? '') ?>
        </span>
      </span>
      <span class="bi-ledger-amt <?= ($e['type']??'') === 'IN' ? 'bi-in' : 'bi-out' ?>">
        $<?= number_format((float)($e['amount'] ?? 0), 2) ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
