<?php
// ── Staff Transfers Tab — DishNet Hybrid v4.4.26 ───────────────────────────
// Rupesh sees all staff-to-staff cash transfers. Auto-approved, so this is
// a log/audit view. He can void same-day transfers if entered incorrectly.
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

require_once __DIR__ . '/../../lib/StaffTransferService.php';
$trfSvc    = new StaffTransferService($store->getPdo(), $store);
$isAcctMgr = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);

if (!$isAcctMgr) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>';
    return;
}

// POST: void transfer
$voidMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['trf_action'] ?? '') === 'void') {
    $res = $trfSvc->void((int)($_POST['transfer_id'] ?? 0), trim($_POST['void_reason'] ?? ''), $retailer);
    $voidMsg = $res['ok'] ? 'ok' : ($res['error'] ?? 'Error');
}

// Load today's transfers + all positions
$today     = date('Y-m-d');
$transfers = $trfSvc->todayAll();
$positions = $trfSvc->allPositions();
$posMap    = [];
foreach ($positions as $p) { $posMap[(int)$p['staff_id']] = $p; }

// Summary stats
$totalMoved = array_sum(array_column(
    array_filter($transfers, fn($t) => $t['status'] === 'approved'), 'amount'
));
$countToday = count(array_filter($transfers, fn($t) => $t['status'] === 'approved'));
?>
<div style="padding:24px;max-width:900px;">

<?php if ($voidMsg === 'ok'): ?>
  <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#065f46;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;">
    ✅ Transfer voided successfully.
  </div>
<?php elseif ($voidMsg): ?>
  <div style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;">
    ⚠️ <?= htmlspecialchars($voidMsg) ?>
  </div>
<?php endif; ?>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;">
  <div>
    <h2 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 4px;">Staff Cash Transfers</h2>
    <p style="font-size:13px;color:#64748b;margin:0;">
      When agents give physical cash to each other. Auto-recorded and auto-approved.
    </p>
  </div>
  <div style="text-align:right;">
    <div style="font-size:28px;font-weight:900;color:#0f172a;font-family:monospace;"><?= '$' . number_format($totalMoved, 2) ?></div>
    <div style="font-size:11px;color:#64748b;"><?= $countToday ?> transfer<?= $countToday !== 1 ? 's' : '' ?> today</div>
  </div>
</div>

<!-- How this works — for Rupesh's reference -->
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:16px;margin-bottom:20px;font-size:13px;color:#78350f;line-height:1.7;">
  <strong>How it works:</strong> When Diko taps <em>"Give Cash"</em> in the app, the system checks his live
  cash balance, auto-detects the source (collections first, advance covers the rest), runs two fraud checks,
  and records it instantly. <strong>No approval needed from you.</strong> Diko's exposure goes down, the
  recipient's goes up by the same amount. Total field cash is unchanged.
  <br><br>
  <strong>You can only void</strong> same-day transfers if an amount was entered incorrectly.
  Yesterday's transfers are locked — contact Aida to adjust manually.
</div>

<!-- Agent positions table -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:20px;">
  <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;">
    Current Agent Positions — Field Cash
  </div>
  <?php if (empty($positions)): ?>
    <div style="padding:24px;text-align:center;color:#94a3b8;font-size:13px;">No active field agents found.</div>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr style="background:#f8fafc;">
          <th style="padding:10px 16px;text-align:left;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Agent</th>
          <th style="padding:10px 8px;text-align:right;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Collections</th>
          <th style="padding:10px 8px;text-align:right;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Advance</th>
          <th style="padding:10px 8px;text-align:right;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Given Out</th>
          <th style="padding:10px 8px;text-align:right;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Received</th>
          <th style="padding:10px 8px;text-align:right;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Expenses</th>
          <th style="padding:10px 16px;text-align:right;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Holding</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $carryLimit = (float)(($store->findOne('plugin_settings.json','key','advance_carry_limit') ?? [])['value'] ?? 100);
        foreach ($positions as $p):
            $exp = (float)($p['cash_exposure'] ?? 0);
            $expColor = $exp <= 0 ? '#10b981' : ($exp > $carryLimit ? '#dc2626' : '#f59e0b');
        ?>
        <tr style="border-top:1px solid #f1f5f9;">
          <td style="padding:12px 16px;">
            <div style="font-weight:700;font-size:13px;color:#0f172a;"><?= htmlspecialchars($p['staff_name'] ?? '') ?></div>
          </td>
          <td style="padding:12px 8px;text-align:right;font-family:monospace;font-size:13px;color:#065f46;">
            $<?= number_format((float)($p['collections'] ?? 0), 2) ?>
          </td>
          <td style="padding:12px 8px;text-align:right;font-family:monospace;font-size:13px;color:#b45309;">
            $<?= number_format((float)($p['advance_balance'] ?? 0), 2) ?>
          </td>
          <td style="padding:12px 8px;text-align:right;font-family:monospace;font-size:13px;color:#dc2626;">
            <?= (float)($p['transfers_sent'] ?? 0) > 0 ? '−$' . number_format((float)$p['transfers_sent'], 2) : '—' ?>
          </td>
          <td style="padding:12px 8px;text-align:right;font-family:monospace;font-size:13px;color:#059669;">
            <?= (float)($p['transfers_received'] ?? 0) > 0 ? '+$' . number_format((float)$p['transfers_received'], 2) : '—' ?>
          </td>
          <td style="padding:12px 8px;text-align:right;font-family:monospace;font-size:13px;color:#64748b;">
            $<?= number_format((float)($p['expenses'] ?? 0), 2) ?>
          </td>
          <td style="padding:12px 16px;text-align:right;">
            <span style="font-family:monospace;font-size:15px;font-weight:800;color:<?= $expColor ?>;">
              $<?= number_format($exp, 2) ?>
            </span>
            <?php if ($exp > $carryLimit): ?>
              <div style="font-size:10px;color:#dc2626;font-weight:700;">OVER LIMIT</div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:2px solid #e2e8f0;background:#f8fafc;">
          <td colspan="6" style="padding:12px 16px;font-size:12px;font-weight:700;color:#475569;text-align:right;">
            Total field cash (outside vault):
          </td>
          <td style="padding:12px 16px;text-align:right;font-family:monospace;font-size:16px;font-weight:900;color:#0f172a;">
            $<?= number_format(array_sum(array_map(fn($p) => max(0, (float)($p['cash_exposure']??0)), $positions)), 2) ?>
          </td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>
</div>

<!-- Today's transfer log -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
  <div style="padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b;">
    Today's Transfers — <?= date('d M Y') ?>
  </div>

  <?php if (empty($transfers)): ?>
    <div style="padding:40px;text-align:center;color:#94a3b8;font-size:13px;">
      No transfers recorded today.
    </div>
  <?php else: ?>
    <?php foreach ($transfers as $t):
        $isApproved = $t['status'] === 'approved';
        $isSameDay  = substr((string)($t['submitted_at']??''), 0, 10) === $today;
        $fromCol = round((float)($t['from_collections']??0), 2);
        $fromAdv = round((float)($t['from_advance']    ??0), 2);
        if ($fromAdv > 0 && $fromCol > 0) {
            $sourceTag = "Split: \${$fromCol} col + \${$fromAdv} adv";
            $tagColor  = '#7c3aed';
        } elseif ($fromAdv > 0) {
            $sourceTag = "From advance";
            $tagColor  = '#b45309';
        } else {
            $sourceTag = "From collections";
            $tagColor  = '#065f46';
        }
    ?>
    <div style="padding:16px 18px;border-bottom:1px solid #f8fafc;<?= !$isApproved ? 'opacity:.6;' : '' ?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
        <div>
          <span style="font-family:monospace;font-size:11px;color:#f59e0b;font-weight:700;">
            <?= htmlspecialchars($t['transfer_no']) ?>
          </span>
          <?php if ($t['status'] === 'voided'): ?>
            <span style="margin-left:8px;background:#fef2f2;color:#dc2626;font-size:9px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;">VOIDED</span>
          <?php endif; ?>
        </div>
        <div style="font-family:monospace;font-size:18px;font-weight:800;color:<?= $isApproved ? '#0f172a' : '#94a3b8' ?>;">
          $<?= number_format((float)($t['amount']??0), 2) ?>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
        <div style="background:#e0f2fe;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:700;color:#0369a1;">
          <?= htmlspecialchars($t['from_name']) ?>
        </div>
        <div style="color:#94a3b8;font-size:16px;">→</div>
        <div style="background:#dcfce7;border-radius:8px;padding:6px 10px;font-size:12px;font-weight:700;color:#065f46;">
          <?= htmlspecialchars($t['to_name']) ?>
        </div>
        <div style="margin-left:auto;background:<?= $tagColor ?>22;color:<?= $tagColor ?>;font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;">
          <?= $sourceTag ?>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:11px;color:#94a3b8;">
        <div>
          <span style="color:#64748b;">Purpose: </span>
          <?= htmlspecialchars(ucfirst(str_replace('_',' ',$t['purpose']??''))) ?>
        </div>
        <div>
          <span style="color:#64748b;">Before (sender): </span>
          $<?= number_format((float)($t['sender_exposure_before']??0), 2) ?>
        </div>
        <div>
          <span style="color:#64748b;">Before (receiver): </span>
          $<?= number_format((float)($t['receiver_exposure_before']??0), 2) ?>
        </div>
        <?php if ($t['description']): ?>
        <div style="grid-column:1/-1;color:#475569;font-style:italic;">
          "<?= htmlspecialchars($t['description']) ?>"
        </div>
        <?php endif; ?>
        <?php if ($t['status'] === 'voided'): ?>
        <div style="grid-column:1/-1;color:#dc2626;">
          Voided by <?= htmlspecialchars($t['voided_by']) ?> — "<?= htmlspecialchars($t['void_reason']) ?>"
        </div>
        <?php endif; ?>
      </div>

      <?php if ($isApproved && $isSameDay): ?>
      <details style="margin-top:10px;">
        <summary style="font-size:11px;color:#94a3b8;cursor:pointer;">Void this transfer</summary>
        <form method="POST" style="margin-top:8px;display:flex;gap:8px;align-items:center;">
          <?= csrfField() ?>
          <input type="hidden" name="trf_action" value="void">
          <input type="hidden" name="transfer_id" value="<?= (int)$t['id'] ?>">
          <input type="text" name="void_reason" placeholder="Reason for void (required)" required
            style="flex:1;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:12px;">
          <button type="submit"
            style="background:#dc2626;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer;">
            Void
          </button>
        </form>
      </details>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

</div>
