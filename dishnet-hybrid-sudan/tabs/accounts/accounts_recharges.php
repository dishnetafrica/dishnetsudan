<?php
// ── Access gate: accountant or admin only ──
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}
// Tab: accounts_recharges
// Extracted from public.php on 2026-03-15
    $allRecharges2 = $store->load('wallet_recharge_requests.json') ?: [];
    $allRecharges2 = array_reverse($allRecharges2);
    $approvedTotal = array_sum(array_map(fn($r) => ($r['status']??'')==='approved'?($r['amount']??0):0, $allRecharges2));
    ?>

<div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:14px;"><i class="bi bi-arrow-clockwise" style="color:#9C27B0;margin-right:6px;"></i>Recharge History</div>

<div style="background:#F3E5F5;border-radius:12px;padding:14px;text-align:center;margin-bottom:14px;">
    <div style="font-size:10px;font-weight:700;color:#7B1FA2;text-transform:uppercase;">Total Approved Recharges</div>
    <div style="font-size:28px;font-weight:800;color:#7B1FA2;">$<?= number_format($approvedTotal,2) ?></div>
</div>

<?php foreach (array_slice($allRecharges2, 0, 50) as $rr):
    $rrSt = $rr['status']??'pending';
    $rrColors = ['pending'=>['#FFF3E0','#E65100'],'approved'=>['#E8F5E9','#28a745'],'rejected'=>['#FFEBEE','#dc3545']];
    $rrC = $rrColors[$rrSt] ?? $rrColors['pending'];
?>
<div style="background:#fff;border-radius:12px;padding:12px 14px;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.03);border:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
    <div>
        <div style="font-size:13px;font-weight:700;"><?= h($rr['retailer_name']??'') ?></div>
        <div style="font-size:10px;color:#9ca3af;"><?= h(substr($rr['note']??'',0,40)) ?> &middot; <?= h(substr($rr['created_at']??'',0,16)) ?></div>
        <?php if($rr['reference']??''): ?><div style="font-size:10px;color:#6b7280;">Ref: <?= h($rr['reference']) ?></div><?php endif; ?>
    </div>
    <div style="text-align:right;">
        <div style="font-size:16px;font-weight:800;color:<?= $rrC[1] ?>;">$<?= number_format($rr['amount']??0,2) ?></div>
        <span style="background:<?= $rrC[0] ?>;color:<?= $rrC[1] ?>;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;"><?= ucfirst($rrSt) ?></span>
    </div>
</div>
<?php endforeach; if(empty($allRecharges2)): ?>
<div style="text-align:center;padding:30px;color:#9ca3af;">No recharge history</div>
<?php endif; ?>
<div style="height:80px;"></div>


