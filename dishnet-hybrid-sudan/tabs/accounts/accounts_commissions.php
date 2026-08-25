<?php
// ── Access gate: accountant or admin only ──
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}
// Tab: accounts_commissions
// Extracted from public.php on 2026-03-15
    $allCols = $store->load('payment_collections.json');
    $allRetailers2 = $auth->getAllRetailers();
    $filterMonth = $_GET['comm_month'] ?? date('Y-m');
    $monthCols = array_filter($allCols, fn($c2) => str_starts_with($c2['created_at']??'', $filterMonth));
    $totalComm = array_sum(array_map(fn($c2) => $c2['commission']??0, $monthCols));
    ?>

<div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:14px;"><i class="bi bi-star" style="color:#FF9800;margin-right:6px;"></i>Commission Report</div>

<div class="acc-card">
    <form method="GET" style="display:flex;gap:8px;align-items:end;">
        <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="accounts_commissions">
        <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Month</label><input type="month" name="comm_month" value="<?= h($filterMonth) ?>" style="padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></div>
        <button type="submit" style="padding:8px 16px;background:#FF9800;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">View</button>
    </form>
</div>

<div style="background:#FFF3E0;border-radius:12px;padding:14px;text-align:center;margin-bottom:14px;">
    <div style="font-size:10px;font-weight:700;color:#E65100;text-transform:uppercase;">Total Commission — <?= date('M Y', strtotime($filterMonth.'-01')) ?></div>
    <div style="font-size:28px;font-weight:800;color:#E65100;">$<?= number_format($totalComm,2) ?></div>
</div>

<?php
foreach ($allRetailers2 as $r):
    if ($r['is_admin']??false) continue;
    $rId = (int)$r['id'];
    $rCols = array_filter($monthCols, fn($c2) => (int)($c2['retailer_id']??0)===$rId);
    $rColAmt = array_sum(array_map(fn($c2) => $c2['amount']??0, $rCols));
    $rComm = array_sum(array_map(fn($c2) => $c2['commission']??0, $rCols));
    if (count($rCols) === 0) continue;
?>
<div style="background:#fff;border-radius:12px;padding:14px;margin-bottom:8px;box-shadow:0 1px 4px rgba(0,0,0,.03);border:1px solid #f1f5f9;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div style="font-weight:700;font-size:14px;"><?= h($r['name']) ?></div>
        <div style="font-size:18px;font-weight:800;color:#E65100;">$<?= number_format($rComm,2) ?></div>
    </div>
    <div style="display:flex;gap:8px;font-size:11px;color:#6b7280;">
        <span>Collected: <strong style="color:#28a745;">$<?= number_format($rColAmt,2) ?></strong></span>
        <span>Payments: <strong><?= count($rCols) ?></strong></span>
        <span>Rate: <strong><?= $config['commission_rate']??5 ?>%</strong></span>
    </div>
</div>
<?php endforeach; ?>
<div style="height:80px;"></div>


