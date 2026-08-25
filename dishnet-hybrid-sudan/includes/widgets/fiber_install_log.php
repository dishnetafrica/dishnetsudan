<?php
/**
 * Fiber Install Log Widget — DishNet Hybrid v4.11.24
 *
 * Shows recent fiber installs with live status for every automated step:
 *   ✅ Collection job created
 *   ✅/❌ Delivery note sent to customer WhatsApp
 *   ✅/❌ Splynx ticket closed
 *
 * Shown on: Accounts dashboard (Rupesh/Admin) + Support dashboard (Bidal)
 */

$_filLimit = 20;
$_filJobs  = [];
$_filError = '';

try {
    $pdo = $store->getPdo();

    // Check columns exist (migration 050 + 051)
    $pdo->query("SELECT delivery_note_sent, ticket_closed FROM fiber_collection_jobs LIMIT 0");

    $stmt = $pdo->prepare("
        SELECT
            id, customer_name, phone, area, plan_name, amount, currency,
            ticket_id, kyc_app_id, crm_client_id,
            status, created_at,
            wa_sent_accountant,
            delivery_note_sent,  delivery_note_sent_at,
            ticket_closed,       ticket_closed_at
        FROM fiber_collection_jobs
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$_filLimit]);
    $_filJobs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $_filError = $e->getMessage();
}
?>

<div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;margin-bottom:20px;">
  <div style="padding:14px 18px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between;">
    <div style="font-size:15px;font-weight:800;color:#1E293B;">🔧 Fiber Install Log</div>
    <div style="font-size:11px;color:#94A3B8;">Last <?= $_filLimit ?> installs · auto-refreshes on page load</div>
  </div>

<?php if ($_filError): ?>
  <div style="padding:20px;color:#DC2626;font-size:13px;">
    ⚠️ Could not load — migrations 050/051 may be pending: <?= htmlspecialchars($_filError) ?>
  </div>
<?php elseif (empty($_filJobs)): ?>
  <div style="padding:30px;text-align:center;color:#94A3B8;font-size:13px;">No fiber installs recorded yet.</div>
<?php else: ?>

  <!-- Legend -->
  <div style="padding:8px 18px;background:#F8FAFC;border-bottom:1px solid #F1F5F9;display:flex;gap:16px;flex-wrap:wrap;">
    <span style="font-size:11px;color:#64748B;">✅ Done &nbsp;·&nbsp; ❌ Failed / not sent &nbsp;·&nbsp; ⏳ Pending</span>
  </div>

  <div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead>
      <tr style="background:#F8FAFC;">
        <th style="padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">Date</th>
        <th style="padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;">Customer</th>
        <th style="padding:9px 14px;text-align:left;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">Area · Plan</th>
        <th style="padding:9px 14px;text-align:center;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">Job</th>
        <th style="padding:9px 14px;text-align:center;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">Rupesh WA</th>
        <th style="padding:9px 14px;text-align:center;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">Delivery Note</th>
        <th style="padding:9px 14px;text-align:center;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">Ticket Closed</th>
        <th style="padding:9px 14px;text-align:center;font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;">Invoice</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($_filJobs as $j):
        $dt = substr($j['created_at'] ?? '', 0, 16);

        // ── Step statuses ──
        $jobOk  = true; // always created if row exists
        $waOk   = !empty($j['wa_sent_accountant']);
        $dnOk   = !empty($j['delivery_note_sent']);
        $tkOk   = !empty($j['ticket_closed']);
        $invOk  = $j['status'] === 'invoiced';
        $invPend = $j['status'] === 'pending';

        // Ticket: ⏳ if no ticket_id linked, ❌ if linked but not closed
        $tkId   = (int)($j['ticket_id'] ?? 0);
        $tkHtml = $tkId === 0
            ? '<span title="No ticket linked" style="color:#94A3B8;">—</span>'
            : ($tkOk
                ? '<span title="Closed '.htmlspecialchars($j['ticket_closed_at'] ?? '').'" style="color:#059669;font-size:15px;">✅</span>'
                : '<span title="Ticket #'.$tkId.' not yet closed" style="color:#DC2626;font-size:15px;">❌</span>');

        $dnTime = $j['delivery_note_sent_at'] ? ' · ' . substr($j['delivery_note_sent_at'], 11, 5) : '';
        $tkTime = $j['ticket_closed_at']      ? ' · ' . substr($j['ticket_closed_at'],      11, 5) : '';

        // Row highlight if any step failed
        $hasIssue = ($tkId > 0 && !$tkOk) || !$dnOk || !$waOk;
        $rowBg    = $hasIssue ? 'background:#FFFBEB;' : '';
    ?>
      <tr style="border-bottom:1px solid #F1F5F9;<?= $rowBg ?>">
        <td style="padding:9px 14px;white-space:nowrap;color:#64748B;"><?= htmlspecialchars($dt) ?></td>
        <td style="padding:9px 14px;">
          <div style="font-weight:700;color:#1E293B;"><?= htmlspecialchars($j['customer_name']) ?></div>
          <div style="color:#94A3B8;font-size:11px;"><?= htmlspecialchars($j['phone']) ?></div>
        </td>
        <td style="padding:9px 14px;">
          <div style="color:#475569;"><?= htmlspecialchars($j['area']) ?></div>
          <div style="color:#94A3B8;font-size:11px;"><?= htmlspecialchars($j['plan_name']) ?></div>
        </td>
        <!-- Job created -->
        <td style="padding:9px 14px;text-align:center;">
          <span style="color:#059669;font-size:15px;" title="Collection job #<?= $j['id'] ?> created">✅</span>
        </td>
        <!-- Rupesh WA notified -->
        <td style="padding:9px 14px;text-align:center;">
          <?php if ($waOk): ?>
            <span style="color:#059669;font-size:15px;" title="Rupesh notified via WhatsApp">✅</span>
          <?php else: ?>
            <span style="color:#DC2626;font-size:15px;" title="WA to Rupesh not sent">❌</span>
          <?php endif; ?>
        </td>
        <!-- Delivery note to customer -->
        <td style="padding:9px 14px;text-align:center;">
          <?php if ($dnOk): ?>
            <span style="color:#059669;font-size:15px;" title="PDF sent<?= $dnTime ?>">✅</span>
            <div style="font-size:10px;color:#94A3B8;"><?= ltrim($dnTime, ' · ') ?></div>
          <?php elseif ((int)($j['kyc_app_id'] ?? 0) === 0): ?>
            <span style="color:#F59E0B;font-size:13px;" title="No KYC record linked — sent manually?">⚠️</span>
            <div style="font-size:10px;color:#94A3B8;">No KYC</div>
          <?php else: ?>
            <span style="color:#DC2626;font-size:15px;" title="Delivery note not sent">❌</span>
          <?php endif; ?>
        </td>
        <!-- Ticket closed -->
        <td style="padding:9px 14px;text-align:center;">
          <?= $tkHtml ?>
          <?php if ($tkOk && $tkTime): ?>
            <div style="font-size:10px;color:#94A3B8;"><?= ltrim($tkTime, ' · ') ?></div>
          <?php elseif ($tkId > 0 && !$tkOk): ?>
            <div style="font-size:10px;color:#DC2626;">#<?= $tkId ?></div>
          <?php endif; ?>
        </td>
        <!-- Invoice status -->
        <td style="padding:9px 14px;text-align:center;">
          <?php if ($invOk): ?>
            <span style="background:#ECFDF5;color:#059669;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;">Invoiced</span>
          <?php elseif ($invPend): ?>
            <span style="background:#FFF7ED;color:#D97706;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;">Pending</span>
          <?php else: ?>
            <span style="background:#F1F5F9;color:#64748B;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;"><?= htmlspecialchars($j['status']) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- Summary bar -->
  <?php
  $total   = count($_filJobs);
  $dnDone  = count(array_filter($_filJobs, fn($j) => !empty($j['delivery_note_sent'])));
  $tkDone  = count(array_filter($_filJobs, fn($j) => !empty($j['ticket_closed'])));
  $invDone = count(array_filter($_filJobs, fn($j) => $j['status'] === 'invoiced'));
  $noKyc   = count(array_filter($_filJobs, fn($j) => empty($j['kyc_app_id']) && empty($j['delivery_note_sent'])));
  ?>
  <div style="padding:10px 18px;background:#F8FAFC;border-top:1px solid #F1F5F9;display:flex;gap:20px;flex-wrap:wrap;font-size:11px;color:#64748B;">
    <span>📋 Total: <strong><?= $total ?></strong></span>
    <span>📄 Delivery notes sent: <strong style="color:<?= $dnDone===$total?'#059669':'#D97706' ?>"><?= $dnDone ?>/<?= $total ?></strong></span>
    <span>🎫 Tickets closed: <strong style="color:<?= $tkDone>0?'#059669':'#94A3B8' ?>"><?= $tkDone ?>/<?= $total ?></strong></span>
    <span>💰 Invoiced: <strong style="color:<?= $invDone>0?'#059669':'#D97706' ?>"><?= $invDone ?>/<?= $total ?></strong></span>
    <?php if ($noKyc > 0): ?>
    <span style="color:#D97706;">⚠️ No KYC linked: <strong><?= $noKyc ?></strong> — delivery notes need manual send</span>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
