<?php
// ── Quote History Tab — DishNet Hybrid v4.4.20 ───────────────────────────────
// Shows all quotations sent via QuotationService across all flows.

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
require_once __DIR__ . '/../../lib/QuotationService.php';

$allowedRoles = ['accountant','admin','sales','sales_staff'];
if (!$isAdmin && !in_array($userRole ?? '', $allowedRoles)) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>';
    return;
}

$cfg     = $store->load('kyc_config.json') ?: [];
$quotSvc = new QuotationService($store, $dataDir, $cfg);

$filterType = $_GET['qt'] ?? '';
$searchQ    = trim($_GET['qs'] ?? '');
$allQuotes  = $quotSvc->getQuotes(['type' => $filterType, 'limit' => 200]);

// Client-side search
if ($searchQ) {
    $lq = strtolower($searchQ);
    $allQuotes = array_values(array_filter($allQuotes, function($q) use ($lq) {
        return str_contains(strtolower($q['customer_name'] ?? ''), $lq)
            || str_contains(strtolower($q['quote_ref'] ?? ''), $lq)
            || str_contains(strtolower($q['customer_phone'] ?? ''), $lq);
    }));
}

// Counts by type
$allRaw = $store->load(QuotationService::LOG_FILE) ?? [];
$typeCounts = ['kyc'=>0,'lead'=>0,'cash'=>0,'manual'=>0];
foreach ($allRaw as $q) {
    $t = $q['type'] ?? '';
    if (isset($typeCounts[$t])) $typeCounts[$t]++;
}

function qTypeBadge(string $t): string {
    switch ($t) {
        case 'kyc':    return '<span style="background:#dbeafe;color:#1e40af;padding:1px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">KYC</span>';
        case 'lead':   return '<span style="background:#fef3c7;color:#92400e;padding:1px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Lead</span>';
        case 'cash':   return '<span style="background:#d1fae5;color:#065f46;padding:1px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Cash</span>';
        case 'manual': return '<span style="background:#ede9fe;color:#5b21b6;padding:1px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">Manual</span>';
        default:       return '<span style="background:#f3f4f6;color:#374151;padding:1px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;">' . htmlspecialchars($t) . '</span>';
    }
}
?>
<style>
.ql-table { width:100%; border-collapse:collapse; font-size:0.86rem; }
.ql-table th { background:#f9fafb; font-weight:700; color:#374151; padding:10px 12px; text-align:left; border-bottom:2px solid #e5e7eb; }
.ql-table td { padding:10px 12px; border-bottom:1px solid #f3f4f6; color:#111827; vertical-align:top; }
.ql-table tr:hover td { background:#fafafa; }
.ql-sent { display:inline-block;width:10px;height:10px;border-radius:50%; }
.ql-pill { display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:24px;font-size:0.85rem;font-weight:600;text-decoration:none;border:2px solid transparent; }
</style>

<div style="padding:24px 20px;">

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 style="margin:0;font-size:1.3rem;font-weight:700;color:#111827;">📋 Quote History</h2>
      <p style="margin:4px 0 0;font-size:0.85rem;color:#6b7280;">All quotations sent across all flows — KYC, Lead, Cash, Manual.</p>
    </div>
    <a href="?page=dashboard&tab=send_quote" class="ql-pill" style="background:#6366f1;color:#fff;">+ Send New Quote</a>
  </div>

  <!-- Filter pills -->
  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
    <?php
    $pillDefs = [
      '' => ['All', '#f3f4f6', '#374151', array_sum($typeCounts)],
      'kyc'    => ['KYC', '#dbeafe', '#1e40af', $typeCounts['kyc']],
      'lead'   => ['Lead', '#fef3c7', '#92400e', $typeCounts['lead']],
      'cash'   => ['Cash', '#d1fae5', '#065f46', $typeCounts['cash']],
      'manual' => ['Manual', '#ede9fe', '#5b21b6', $typeCounts['manual']],
    ];
    foreach ($pillDefs as $key => [$label, $bg, $col, $cnt]):
        $active = $filterType === $key ? "border-color:#6366f1;" : '';
    ?>
    <a href="?page=dashboard&tab=quote_logs&qt=<?= $key ?>&qs=<?= urlencode($searchQ) ?>"
       style="background:<?= $bg ?>;color:<?= $col ?>;<?= $active ?>display:inline-flex;gap:6px;align-items:center;padding:7px 14px;border-radius:24px;font-size:0.85rem;font-weight:600;text-decoration:none;border:2px solid <?= $filterType===$key ? '#6366f1' : 'transparent' ?>;">
      <?= $label ?> <span><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Search -->
  <div style="margin-bottom:16px;">
    <form method="get" style="display:flex;gap:8px;">
      <input type="hidden" name="page" value="dashboard">
      <input type="hidden" name="tab" value="quote_logs">
      <input type="hidden" name="qt" value="<?= htmlspecialchars($filterType) ?>">
      <input type="text" name="qs" value="<?= htmlspecialchars($searchQ) ?>"
             placeholder="Search name, phone, ref…"
             style="flex:1;max-width:320px;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
      <button type="submit" style="padding:8px 16px;background:#6366f1;color:#fff;border:none;border-radius:8px;cursor:pointer;">Search</button>
      <?php if ($searchQ): ?><a href="?page=dashboard&tab=quote_logs&qt=<?= $filterType ?>" style="padding:8px 10px;color:#6b7280;text-decoration:none;">✕</a><?php endif; ?>
    </form>
  </div>

  <!-- Table -->
  <?php if (empty($allQuotes)): ?>
    <div style="text-align:center;padding:60px;color:#9ca3af;">
      <div style="font-size:2.5rem;">📭</div>
      <p style="margin-top:8px;">No quotations found<?= $searchQ ? ' matching "' . htmlspecialchars($searchQ) . '"' : '' ?>.</p>
    </div>
  <?php else: ?>
    <p style="font-size:0.83rem;color:#9ca3af;margin-bottom:10px;">Showing <?= count($allQuotes) ?> quote<?= count($allQuotes)!==1?'s':'' ?></p>
    <div style="overflow-x:auto;">
    <table class="ql-table">
      <thead>
        <tr>
          <th>Ref</th>
          <th>Type</th>
          <th>Customer</th>
          <th>Phone</th>
          <th>Total</th>
          <th>Channels</th>
          <th>By</th>
          <th>Valid Until</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allQuotes as $q):
            $waSent  = !empty($q['sent_via_wa']);
            $crmSent = !empty($q['sent_via_crm']);
        ?>
        <tr>
          <td style="font-family:monospace;font-size:0.8rem;color:#6366f1;"><?= htmlspecialchars($q['quote_ref'] ?? '—') ?></td>
          <td><?= qTypeBadge($q['type'] ?? '') ?></td>
          <td style="font-weight:600;"><?= htmlspecialchars($q['customer_name'] ?? '—') ?></td>
          <td style="color:#6b7280;"><?= htmlspecialchars($q['customer_phone'] ?? '—') ?></td>
          <td style="font-weight:700;color:#059669;">$<?= number_format((float)($q['total'] ?? 0), 2) ?></td>
          <td>
            <span title="WhatsApp" style="margin-right:4px;font-size:1rem;"><?= $waSent  ? '✅' : '❌' ?> WA</span>
            <span title="UCRM email"><?= $crmSent ? '✅' : '➖' ?> CRM</span>
          </td>
          <td style="color:#6b7280;font-size:0.82rem;"><?= htmlspecialchars($q['sent_by'] ?? '—') ?></td>
          <td style="color:#6b7280;font-size:0.82rem;"><?= htmlspecialchars($q['valid_until'] ?? '—') ?></td>
          <td style="color:#9ca3af;font-size:0.8rem;"><?= htmlspecialchars(substr($q['created_at'] ?? '', 0, 16)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

</div>
