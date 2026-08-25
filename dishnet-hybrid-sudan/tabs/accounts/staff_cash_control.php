<?php
// ── Staff Cash Control — Rupesh / Admin view ─────────────────────────────
// One screen that shows exactly how much cash every field agent is holding,
// combining customer collections + advance balances − expenses − handovers.
// Powered by the staff_cash_position SQL VIEW (migration 008).
// -------------------------------------------------------------------------
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}

require_once __DIR__ . '/../../lib/StaffCashPositionService.php';

$svc         = new StaffCashPositionService($store, $store->getPdo()); // v4.11.38: JSON source
$positions   = $svc->getAllPositions();
$globalCarryLimit  = (float)($config['advance_carry_limit'] ?? 100);
$floatWarn   = (float)($config['agent_float_warn_threshold'] ?? 50);

// ── Per-agent carry limits from retailers.json ──
$_agentLimits = [];
foreach ($store->load('retailers.json') ?? [] as $_alr) {
    if (!empty($_alr['carry_limit'])) $_agentLimits[(int)$_alr['id']] = (float)$_alr['carry_limit'];
}
$getLimit = function($agentId) use ($_agentLimits, $globalCarryLimit) {
    return $_agentLimits[(int)$agentId] ?? $globalCarryLimit;
};

// Sort by cash_exposure descending — highest risk first
uasort($positions, fn($a, $b) => $b['cash_exposure'] <=> $a['cash_exposure']);

$totalExposure     = array_sum(array_column($positions, 'cash_exposure'));
$totalCollections  = array_sum(array_column($positions, 'collections'));
$totalAdvances     = array_sum(array_column($positions, 'advance_balance'));
$overLimitCount    = count(array_filter($positions, function($p) use ($getLimit) { return $p['cash_exposure'] > $getLimit($p['agent_id'] ?? 0); }));
$floatLowCount     = count(array_filter($positions, fn($p) => $p['float_balance'] < $floatWarn));
$refreshedAt       = date('H:i:s');
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800;900&display=swap');
.scc{font-family:'DM Sans',-apple-system,sans-serif;padding-bottom:40px;}
.scc-hd{margin-bottom:20px;}
.scc-hd h2{font-size:22px;font-weight:900;color:#0f0f0f;margin:0 0 3px;display:flex;align-items:center;gap:10px;}
.scc-hd-sub{font-size:12px;color:#94a3b8;}
.scc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:22px;}
.scc-stat{background:#fff;border-radius:14px;border:1.5px solid #ececec;padding:14px 16px;}
.scc-stat-v{font-size:26px;font-weight:900;color:#0f0f0f;line-height:1;}
.scc-stat-l{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#b0b0b0;margin-top:5px;}
.scc-stat.warn .scc-stat-v{color:#D97706;}
.scc-stat.danger .scc-stat-v{color:#DC2626;}
/* table */
.scc-table{width:100%;border-collapse:collapse;background:#fff;border-radius:16px;overflow:hidden;border:1.5px solid #ececec;}
.scc-table th{background:#f8f8f8;padding:9px 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;text-align:right;border-bottom:1.5px solid #ececec;}
.scc-table th:first-child,.scc-table td:first-child{text-align:left;}
.scc-table td{padding:11px 12px;font-size:13px;border-bottom:1px solid #f3f4f6;text-align:right;vertical-align:middle;}
.scc-table tr:last-child td{border-bottom:none;}
.scc-table tr:hover td{background:#fafafa;}
.scc-name{font-weight:700;color:#0f0f0f;font-size:13px;}
.scc-sub{font-size:10px;color:#94a3b8;margin-top:2px;}
/* exposure chips */
.scc-exp{font-weight:900;font-size:16px;padding:4px 10px;border-radius:8px;display:inline-block;letter-spacing:-.3px;}
.scc-exp.ok{background:#DCFCE7;color:#15803D;}
.scc-exp.warn{background:#FEF3C7;color:#B45309;}
.scc-exp.danger{background:#FEE2E2;color:#DC2626;}
/* float chip */
.scc-float{font-size:11px;font-weight:700;padding:3px 8px;border-radius:6px;display:inline-block;}
.scc-float.ok{background:#F0FDF4;color:#166534;}
.scc-float.low{background:#FEF3C7;color:#92400E;}
/* bar */
.scc-bar-wrap{display:flex;gap:2px;height:6px;border-radius:3px;overflow:hidden;min-width:80px;}
.scc-bar-col{background:#3B82F6;border-radius:3px 0 0 3px;}
.scc-bar-adv{background:#8B5CF6;}
.scc-bar-exp{background:#F59E0B;}
.scc-bar-hov{background:#10B981;}
/* formula legend */
.scc-legend{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;font-size:11px;}
.scc-leg-dot{width:10px;height:10px;border-radius:3px;display:inline-block;margin-right:4px;vertical-align:middle;}
.scc-empty{text-align:center;padding:60px;color:#94a3b8;font-size:14px;}
@media(max-width:640px){
  .scc-stats{grid-template-columns:repeat(2,1fr);}
  .scc-table{display:block;overflow-x:auto;}
}
</style>

<div class="scc">
  <!-- Header -->
  <div class="scc-hd">
    <h2>👁️ Staff Cash Control</h2>
    <div class="scc-hd-sub">How much company cash is currently outside the vault · Refreshed <?= $refreshedAt ?> EAT</div>
  </div>

  <!-- Summary stats -->
  <div class="scc-stats">
    <div class="scc-stat<?= $overLimitCount > 0 ? ' danger' : '' ?>">
      <div class="scc-stat-v">$<?= number_format($totalExposure, 2) ?></div>
      <div class="scc-stat-l">Total Field Cash Exposure</div>
    </div>
    <div class="scc-stat">
      <div class="scc-stat-v">$<?= number_format($totalCollections, 2) ?></div>
      <div class="scc-stat-l">Customer Collections Held</div>
    </div>
    <div class="scc-stat">
      <div class="scc-stat-v">$<?= number_format($totalAdvances, 2) ?></div>
      <div class="scc-stat-l">Advance Cash in Field</div>
    </div>
    <div class="scc-stat<?= $overLimitCount > 0 ? ' danger' : ($floatLowCount > 0 ? ' warn' : '') ?>">
      <div class="scc-stat-v"><?= $overLimitCount ?> / <?= count($positions) ?></div>
      <div class="scc-stat-l">Agents Over Carry Limit<?= $floatLowCount > 0 ? ' · '.$floatLowCount.' Low Float' : '' ?></div>
    </div>
  </div>

  <!-- Legend -->
  <div class="scc-legend">
    <span><span class="scc-leg-dot" style="background:#3B82F6;"></span> Collections</span>
    <span><span class="scc-leg-dot" style="background:#8B5CF6;"></span> Advance balance</span>
    <span><span class="scc-leg-dot" style="background:#F59E0B;"></span> Expenses deducted</span>
    <span><span class="scc-leg-dot" style="background:#10B981;"></span> Handovers returned</span>
    <span style="color:#94a3b8;">Cash Exposure = Collections + Advances − Expenses − Handovers</span>
  </div>

  <?php if (empty($positions)): ?>
  <div class="scc-empty">
    <div style="font-size:32px;margin-bottom:12px;">🏦</div>
    No active field agents with cash activity.<br>
    <span style="font-size:12px;">Positions appear once agents collect or receive advances.</span>
  </div>
  <?php else: ?>

  <table class="scc-table">
    <thead>
      <tr>
        <th>Agent</th>
        <th>Float</th>
        <th>Collections</th>
        <th>Adv Balance</th>
        <th>Expenses</th>
        <th>Handovers</th>
        <th>Cash Exposure</th>
        <th>Breakdown</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($positions as $pos):
        $exp   = $pos['cash_exposure'];
        $cih   = max(0, $exp);
        $total = max(0.01, $pos['collections'] + $pos['advance_balance']);
        $colPct = min(100, round($pos['collections']     / $total * 100));
        $advPct = min(100 - $colPct, round($pos['advance_balance'] / $total * 100));
        $expPct = min(100, round($pos['expenses']  / max(0.01,$total) * 100));
        $hovPct = min(100, round($pos['handovers'] / max(0.01,$total) * 100));
        $_posLimit = $getLimit($pos['agent_id'] ?? 0);

        if ($exp > $_posLimit)           $chip = 'danger';
        elseif ($exp > 0)            $chip = 'warn';
        else                         $chip = 'ok';

        $floatClass = $pos['float_balance'] < $floatWarn ? 'low' : 'ok';
    ?>
      <tr>
        <td>
          <div class="scc-name"><?= htmlspecialchars($pos['staff_name']) ?></div>
          <?php if ($exp > $_posLimit): ?>
          <div class="scc-sub" style="color:#DC2626;font-weight:700;">⚠ $<?= number_format($exp - $_posLimit, 2) ?> over limit<?= $_posLimit !== $globalCarryLimit ? ' (limit $'.number_format($_posLimit,0).')' : '' ?></div>
          <?php elseif ($pos['float_balance'] < $floatWarn): ?>
          <div class="scc-sub" style="color:#B45309;">🔋 Float low</div>
          <?php else: ?>
          <div class="scc-sub">All good</div>
          <?php endif; ?>
        </td>
        <td>
          <span class="scc-float <?= $floatClass ?>">$<?= number_format($pos['float_balance'], 2) ?></span>
        </td>
        <td style="color:#3B82F6;font-weight:700;">$<?= number_format($pos['collections'], 2) ?></td>
        <td style="color:#8B5CF6;font-weight:700;">$<?= number_format($pos['advance_balance'], 2) ?></td>
        <td style="color:#F59E0B;font-weight:600;">−$<?= number_format($pos['expenses'], 2) ?></td>
        <td style="color:#10B981;font-weight:600;">−$<?= number_format($pos['handovers'], 2) ?></td>
        <td>
          <span class="scc-exp <?= $chip ?>">$<?= number_format($cih, 2) ?></span>
        </td>
        <td>
          <div class="scc-bar-wrap" title="Blue=collections Purple=advances Amber=exp Green=handovers">
            <?php if ($colPct > 0): ?><div class="scc-bar-col" style="width:<?= $colPct ?>%;"></div><?php endif; ?>
            <?php if ($advPct > 0): ?><div class="scc-bar-adv" style="width:<?= $advPct ?>%;"></div><?php endif; ?>
            <?php if ($expPct > 0): ?><div class="scc-bar-exp" style="width:<?= min(30,$expPct) ?>%;"></div><?php endif; ?>
            <?php if ($hovPct > 0): ?><div class="scc-bar-hov" style="width:<?= min(30,$hovPct) ?>%;"></div><?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <!-- Totals row -->
    <tfoot>
      <tr style="background:#f8f8f8;font-weight:800;">
        <td>Total</td>
        <td>—</td>
        <td style="color:#3B82F6;">$<?= number_format($totalCollections, 2) ?></td>
        <td style="color:#8B5CF6;">$<?= number_format($totalAdvances, 2) ?></td>
        <td style="color:#F59E0B;">−$<?= number_format(array_sum(array_column($positions,'expenses')), 2) ?></td>
        <td style="color:#10B981;">−$<?= number_format(array_sum(array_column($positions,'handovers')), 2) ?></td>
        <td><span class="scc-exp <?= $totalExposure > ($globalCarryLimit * count($positions)) ? 'danger' : ($totalExposure > 0 ? 'warn' : 'ok') ?>">$<?= number_format(max(0,$totalExposure), 2) ?></span></td>
        <td>—</td>
      </tr>
    </tfoot>
  </table>

  <div style="margin-top:12px;font-size:11px;color:#94a3b8;text-align:right;">
    Default carry limit: $<?= number_format($globalCarryLimit, 2) ?> (per-agent overrides apply) · Float warn threshold: $<?= number_format($floatWarn, 2) ?> ·
    <a href="?page=dashboard&tab=settings" style="color:#94a3b8;">Change in Settings</a>
  </div>

  <?php endif; ?>
</div>
