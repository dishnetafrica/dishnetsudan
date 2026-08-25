<?php
// ── Access gate: accountant or admin only ──
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}
// Tab: accounts_collections
// Extracted from public.php on 2026-03-15
    $allCols = $store->load('payment_collections.json');
    $filterDate = $_GET['acc_date'] ?? date('Y-m-d');
    $filterMode = $_GET['acc_mode'] ?? 'day';
    if ($filterMode === 'month') {
        $filteredCols = array_filter($allCols, fn($c2) => str_starts_with($c2['created_at']??'', substr($filterDate,0,7)));
    } else {
        $filteredCols = array_filter($allCols, fn($c2) => str_starts_with($c2['created_at']??'', $filterDate));
    }
    $filteredCols = array_reverse($filteredCols);
    $fTotal = array_sum(array_map(fn($c2) => $c2['amount']??0, $filteredCols));
    $fComm = array_sum(array_map(fn($c2) => $c2['commission']??0, $filteredCols));
    ?>

<div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:14px;"><i class="bi bi-cash-coin" style="color:#28a745;margin-right:6px;"></i>All Collections</div>

<div class="acc-card">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="accounts_collections">
        <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Date</label><input type="date" name="acc_date" value="<?= h($filterDate) ?>" style="padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"></div>
        <div><label style="font-size:11px;font-weight:700;color:#6b7280;">Range</label><select name="acc_mode" style="padding:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="day" <?= $filterMode==='day'?'selected':'' ?>>Day</option>
            <option value="month" <?= $filterMode==='month'?'selected':'' ?>>Month</option>
        </select></div>
        <button type="submit" style="padding:8px 16px;background:#E65100;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">Filter</button>
    </form>
</div>

<!-- Summary row + CSV export -->
<div style="display:flex;gap:8px;margin-bottom:12px;align-items:stretch;flex-wrap:wrap;">
    <div style="flex:1;min-width:80px;background:#E8F5E9;border-radius:10px;padding:10px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#2E7D32;">Total</div>
        <div style="font-size:18px;font-weight:800;color:#2E7D32;">$<?= number_format($fTotal,2) ?></div>
        <div style="font-size:10px;color:#888;"><?= count($filteredCols) ?> payments</div>
    </div>
    <div style="flex:1;min-width:80px;background:#FFF3E0;border-radius:10px;padding:10px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#E65100;">Commission</div>
        <div style="font-size:18px;font-weight:800;color:#E65100;">$<?= number_format($fComm,2) ?></div>
    </div>
    <div style="flex:1;min-width:80px;background:#E3F2FD;border-radius:10px;padding:10px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:#D41C1C;">Net</div>
        <div style="font-size:18px;font-weight:800;color:#D41C1C;">$<?= number_format($fTotal-$fComm,2) ?></div>
    </div>
    <form method="POST" style="display:flex;align-items:center;">
        <?= csrfField() ?>
        <input type="hidden" name="action"      value="export_csv">
        <input type="hidden" name="export_type" value="collections">
        <input type="hidden" name="exp_date"    value="<?= h($filterDate) ?>">
        <input type="hidden" name="exp_mode"    value="<?= h($filterMode) ?>">
        <button type="submit" style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:10px;padding:10px 14px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;height:100%;">
            📥 Export CSV
        </button>
    </form>
</div>

<!-- Transaction table -->
<?php if (!empty($filteredCols)): ?>
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:10px;">
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;font-size:12px;">
<thead>
<tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
    <th style="padding:10px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;">Date / Time</th>
    <th style="padding:10px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Customer</th>
    <th style="padding:10px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Agent</th>
    <th style="padding:10px 12px;text-align:left;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Method</th>
    <th style="padding:10px 12px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Amount</th>
    <th style="padding:10px 12px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Comm.</th>
    <th style="padding:10px 12px;text-align:right;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Net</th>
    <th style="padding:10px 4px;text-align:center;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">CRM</th>
    <th style="padding:10px 8px;text-align:center;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Handover</th>
</tr>
</thead>
<tbody>
<?php foreach ($filteredCols as $col):
    $mth = $col['method']??'Cash';
    $mBadge = $mth==='Cash' ? ['#E8F5E9','#2E7D32','💵'] : ($mth==='Mobile Money' ? ['#E3F2FD','#1565C0','📱'] : ['#FFF3E0','#E65100','🏦']);
    $net = (float)($col['amount']??0) - (float)($col['commission']??0);
    $isSynced = $col['crm_synced']??false;
?>
<tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
    <td style="padding:9px 12px;white-space:nowrap;color:#64748b;font-size:11px;">
        <div style="font-weight:700;color:#1e293b;"><?= h(substr($col['created_at']??'',0,10)) ?></div>
        <div><?= h(substr($col['created_at']??'',11,5)) ?></div>
    </td>
    <td style="padding:9px 12px;">
        <div style="font-weight:700;color:#1e293b;"><?= h($col['customer_name']??'—') ?></div>
        <?php if($col['invoice_id']??''): ?><div style="font-size:10px;color:#9ca3af;">Inv #<?= h($col['invoice_id']) ?></div><?php endif; ?>
    </td>
    <td style="padding:9px 12px;color:#374151;font-weight:600;"><?= h($col['retailer_name']??'—') ?></td>
    <td style="padding:9px 12px;">
        <span style="background:<?= $mBadge[0] ?>;color:<?= $mBadge[1] ?>;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;"><?= $mBadge[2] ?> <?= h($mth) ?></span>
    </td>
    <td style="padding:9px 12px;text-align:right;font-weight:800;color:#16a34a;font-size:13px;">$<?= number_format($col['amount']??0,2) ?></td>
    <td style="padding:9px 12px;text-align:right;color:#E65100;font-size:12px;font-weight:700;"><?= ($col['commission']??0)>0 ? '-$'.number_format($col['commission'],2) : '—' ?></td>
    <td style="padding:9px 12px;text-align:right;font-weight:800;color:#D41C1C;">$<?= number_format($net,2) ?></td>
    <td style="padding:9px 4px;text-align:center;font-size:11px;"><?= $isSynced ? '<span style="color:#16a34a;">✓</span>' : '<span style="color:#f59e0b;">⏳</span>' ?></td>
    <td style="padding:9px 8px;text-align:center;font-size:10px;">
        <?php if (($col['status'] ?? '') === 'voided'): ?>
            <span style="color:#ef4444;font-weight:700;" title="Voided on <?= h($col['voided_at'] ?? '') ?>">🚫 VOID</span>
        <?php elseif (!empty($col['handover_id'])): ?>
            <span style="background:#dcfce7;color:#16a34a;padding:2px 6px;border-radius:4px;font-weight:700;cursor:pointer;" 
                  title="Receipt: <?= h($col['handover_receipt'] ?? 'HOV-'.$col['handover_id']) ?>&#10;By: <?= h($col['handover_by'] ?? '—') ?>&#10;At: <?= h($col['handover_at'] ?? '—') ?>">
                ✓ <?= h($col['handover_receipt'] ?? 'HOV-'.$col['handover_id']) ?>
            </span>
        <?php elseif ($isSynced): ?>
            <span style="color:#f59e0b;font-weight:600;" title="Synced to CRM but not yet handed over">⏳ Pending</span>
        <?php else: ?>
            <span style="color:#9ca3af;">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr style="background:#f8fafc;border-top:2px solid #e2e8f0;font-weight:800;">
    <td colspan="4" style="padding:10px 12px;font-size:12px;color:#374151;">Totals (<?= count($filteredCols) ?> records)</td>
    <td style="padding:10px 12px;text-align:right;color:#16a34a;">$<?= number_format($fTotal,2) ?></td>
    <td style="padding:10px 12px;text-align:right;color:#E65100;">-$<?= number_format($fComm,2) ?></td>
    <td style="padding:10px 12px;text-align:right;color:#D41C1C;">$<?= number_format($fTotal-$fComm,2) ?></td>
    <td></td>
    <td></td>
</tr>
</tfoot>
</table>
</div>
</div>
<?php else: ?>
<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="bi bi-cash-coin" style="font-size:36px;display:block;margin-bottom:8px;color:#d1d5db;"></i>No collections for this period</div>
<?php endif; ?>
<div style="height:80px;"></div>


