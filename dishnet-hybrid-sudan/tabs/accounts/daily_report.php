<?php
// Tab: daily_report
// Extracted from public.php on 2026-03-15
    $reportDate = $_GET['report_date'] ?? date('Y-m-d');
    $allCols = $store->load('payment_collections.json');
    $allApps = $store->load('kyc_applications.json');
    $allRetailers = $store->load('retailers.json');
    // $pendingColCount already defined early (before nav) — no need to redefine
    $dayCols = array_filter($allCols, fn($c2) => str_starts_with($c2['created_at']??'', $reportDate));
    $dayApps = array_filter($allApps, fn($a) => str_starts_with($a['created_at']??'', $reportDate));
    $dayColTotal = array_sum(array_map(fn($c2) => $c2['amount']??0, $dayCols));
    $dayComm = array_sum(array_map(fn($c2) => $c2['commission']??0, $dayCols));
?>

<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-bar-chart"></i> Daily Report — <?= h($reportDate) ?></div>
    <div class="kyc-card-body">
        <form method="GET" style="display:flex;gap:8px;align-items:end;margin-bottom:16px;">
            <input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="daily_report">
            <div><label class="form-label">Date</label><input type="date" name="report_date" class="form-control" value="<?= h($reportDate) ?>"></div>
            <button type="submit" class="btn-kyc-submit" style="padding:10px 16px;font-size:12px;">View Report</button>
        </form>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card teal"><div class="stat-label">Collections</div><div class="stat-value">$<?= number_format($dayColTotal, 2) ?></div><div style="font-size:10px;color:#6b7280;"><?= count($dayCols) ?> payments</div></div>
    <div class="stat-card green"><div class="stat-label">New KYCs</div><div class="stat-value"><?= count($dayApps) ?></div></div>
    <div class="stat-card orange"><div class="stat-label">Commission Paid</div><div class="stat-value">$<?= number_format($dayComm, 2) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Net Revenue</div><div class="stat-value">$<?= number_format($dayColTotal - $dayComm, 2) ?></div></div>
</div>

<!-- Per-Retailer Breakdown -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-people"></i> Retailer Performance</div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead><tr><th>Retailer</th><th style="text-align:right;">Collections</th><th style="text-align:center;">Payments</th><th style="text-align:center;">KYCs</th><th style="text-align:right;">Commission</th><th>Target Progress</th></tr></thead>
            <tbody>
            <?php
            foreach ($allRetailers as $r):
                if (!empty($r['is_admin'])) continue;
                $rId = (int)$r['id'];
                $rCols = array_filter($dayCols, fn($c2) => (int)($c2['retailer_id']??0)===$rId);
                $rApps = array_filter($dayApps, fn($a) => (int)($a['retailer_id']??0)===$rId);
                $rColTotal = array_sum(array_map(fn($c2) => $c2['amount']??0, $rCols));
                $rComm = array_sum(array_map(fn($c2) => $c2['commission']??0, $rCols));
                // Monthly progress
                $monthCols = array_filter($allCols, fn($c2) => (int)($c2['retailer_id']??0)===$rId && str_starts_with($c2['created_at']??'', date('Y-m')));
                $monthTotal = array_sum(array_map(fn($c2) => $c2['amount']??0, $monthCols));
                $target = (float)($config['retailer_targets'][$rId] ?? ($config['retailer_targets']['default'] ?? 0));
                $pct = $target > 0 ? min(100, round($monthTotal / $target * 100)) : 0;
            ?>
            <tr>
                <td style="font-weight:700;"><?= h($r['name']??'') ?></td>
                <td style="text-align:right;font-weight:800;color:#28a745;">$<?= number_format($rColTotal, 2) ?></td>
                <td style="text-align:center;"><?= count($rCols) ?></td>
                <td style="text-align:center;"><?= count($rApps) ?></td>
                <td style="text-align:right;color:#E65100;">$<?= number_format($rComm, 2) ?></td>
                <td>
                    <?php if ($target > 0): ?>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div style="flex:1;background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;">
                            <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct>=100?'#28a745':'#D41C1C' ?>;border-radius:4px;"></div>
                        </div>
                        <span style="font-size:11px;font-weight:700;<?= $pct>=100?'color:#28a745;':'' ?>"><?= $pct ?>%</span>
                    </div>
                    <div style="font-size:10px;color:#9ca3af;">$<?= number_format($monthTotal,0) ?> / $<?= number_format($target,0) ?></div>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;">No target set</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Day's Transactions -->
<div class="kyc-card" style="margin-bottom:80px;">
    <div class="kyc-card-header"><i class="bi bi-list-ul"></i> Transactions — <?= h($reportDate) ?></div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead><tr><th>Time</th><th>Retailer</th><th>Customer</th><th style="text-align:right;">Amount</th><th>Type</th></tr></thead>
            <tbody>
            <?php
            // Merge collections and KYCs for timeline
            $dayEvents = [];
            foreach ($dayCols as $dc) $dayEvents[] = ['time'=>$dc['created_at']??'','retailer'=>$dc['retailer_name']??'','customer'=>$dc['customer_name']??'','amount'=>$dc['amount']??0,'type'=>'Collection'];
            foreach ($dayApps as $da) $dayEvents[] = ['time'=>$da['created_at']??'','retailer'=>$da['retailer_name']??'','customer'=>($da['firstname']??'').' '.($da['lastname']??''),'amount'=>0,'type'=>'KYC '.$da['customer_type']??''];
            usort($dayEvents, fn($a,$b)=>strcmp($b['time'],$a['time']));
            foreach (array_slice($dayEvents, 0, 50) as $ev): ?>
            <tr>
                <td style="font-size:11px;color:#6b7280;white-space:nowrap;"><?= substr($ev['time'],11,5) ?></td>
                <td style="font-size:12px;"><?= h($ev['retailer']) ?></td>
                <td style="font-weight:600;"><?= h($ev['customer']) ?></td>
                <td style="text-align:right;font-weight:700;color:#28a745;"><?= $ev['amount']>0 ? '$'.number_format($ev['amount'],2) : '-' ?></td>
                <td><span style="background:<?= str_starts_with($ev['type'],'KYC')?'#fff5f5':'#d4edda' ?>;color:<?= str_starts_with($ev['type'],'KYC')?'#D41C1C':'#28a745' ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><?= h($ev['type']) ?></span></td>
            </tr>
            <?php endforeach; if(empty($dayEvents)): ?><tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:20px;">No activity on this day.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

