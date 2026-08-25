<?php
// Tab: subscription_plans
// Extracted from public.php on 2026-03-15
        $allPlans = $store->load('subscription_plans.json');
        $editPlan = null;
        $editId = (int)($_GET['edit_plan'] ?? 0);
        if ($editId) { foreach ($allPlans as $ep) { if ((int)($ep['id']??0)===$editId) { $editPlan=$ep; break; } } }
        // Group by type
        $byType = ['starlink'=>[],'fiber'=>[],'sim'=>[]];
        foreach ($allPlans as $p) { $t = strtolower($p['type']??'starlink'); $byType[$t][] = $p; }
        $filterType = $_GET['plan_filter'] ?? 'all';
        $filteredPlans = $filterType === 'all' ? $allPlans : ($byType[$filterType] ?? []);
        $totalProfit = array_sum(array_map(fn($p)=>$p['profit']??0, array_filter($allPlans, fn($p)=>!empty($p['is_active']))));
        $activePlans = count(array_filter($allPlans, fn($p)=>!empty($p['is_active'])));
        $typeLabels = ['starlink'=>['Starlink','Starlink charges you','#E3F2FD','#1565C0'],'fiber'=>['Fiber','Supplier cost','#E8F5E9','#2E7D32'],'sim'=>['SIM','SIM network','#FFF3E0','#E65100']/* hidden */];
    ?>

<!-- Stats per type -->
<div class="stat-grid">
    <div class="stat-card teal">
        <div class="stat-label">Starlink Plans</div>
        <div class="stat-value"><?= count($byType['starlink']) ?></div>
        <div style="font-size:11px;color:#64748b;margin-top:4px;">$<?= number_format(array_sum(array_map(fn($p)=>$p['profit']??0,$byType['starlink'])),2) ?> profit/mo</div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Fiber Plans</div>
        <div class="stat-value"><?= count($byType['fiber']) ?></div>
        <div style="font-size:11px;color:#64748b;margin-top:4px;">$<?= number_format(array_sum(array_map(fn($p)=>$p['profit']??0,$byType['fiber'])),2) ?> profit/mo</div>
    </div>
    <div class="stat-card orange">
        <?php /* SIM Plans stat hidden */ ?>
        <div style="font-size:11px;color:#64748b;margin-top:4px;">$<?= number_format(array_sum(array_map(fn($p)=>$p['profit']??0,$byType['sim'])),2) ?> profit/mo</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Total Monthly Profit</div>
        <div class="stat-value">$<?= number_format($totalProfit, 2) ?></div>
        <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= $activePlans ?> active plans • <?php $mgs=array_filter(array_map(fn($p)=>$p['margin']??0,$allPlans)); echo $mgs ? round(array_sum($mgs)/count($mgs),1) : 0; ?>% avg margin</div>
    </div>
</div>

<!-- Add/Edit Plan Form -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-plus-circle"></i> <?= $editPlan ? 'Edit Plan #'.$editPlan['id'] : 'Add New Plan' ?></div>
    <div class="kyc-card-body">
        <form method="POST" id="planForm">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="save_plan">
            <?php if ($editPlan): ?><input type="hidden" name="plan_id" value="<?= $editPlan['id'] ?>"><?php endif; ?>
            <div class="resp-grid-5" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label class="form-label">Plan Name *</label>
                    <input type="text" name="plan_name" class="form-control" value="<?= h($editPlan['name'] ?? '') ?>" required placeholder="e.g. FIBER:100 - 60Mbps">
                </div>
                <div>
                    <label class="form-label">Service Type</label>
                    <select name="plan_type" class="form-control" id="planTypeSelect" onchange="planTypeChanged()">
                        <option value="starlink" <?= ($editPlan['type']??'')==='starlink'?'selected':'' ?>>Starlink</option>
                        <option value="fiber" <?= ($editPlan['type']??'')==='fiber'?'selected':'' ?>>Fiber</option>
                
                    </select>
                </div>
                <div>
                    <label class="form-label" id="costLabel">Your Cost *</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:9px;color:#6b7280;font-weight:700;">$</span>
                        <input type="number" name="starlink_cost" class="form-control" step="0.01" min="0" style="padding-left:24px;"
                               value="<?= $editPlan['starlink_cost'] ?? '' ?>" required placeholder="0.00" oninput="calcPlanProfit()">
                    </div>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;" id="costHint">What Starlink charges you</div>
                </div>
                <div>
                    <label class="form-label">Customer Price *</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:9px;color:#6b7280;font-weight:700;">$</span>
                        <input type="number" name="customer_price" class="form-control" step="0.01" min="0" style="padding-left:24px;"
                               value="<?= $editPlan['customer_price'] ?? '' ?>" required placeholder="0.00" oninput="calcPlanProfit()">
                    </div>
                    <div style="font-size:10px;color:#9ca3af;margin-top:2px;">What you charge customer</div>
                </div>
                <div>
                    <label class="form-label">Speed</label>
                    <input type="text" name="plan_speed" class="form-control" value="<?= h($editPlan['speed'] ?? '') ?>" placeholder="e.g. 60Mbps">
                </div>
            </div>
            <div class="resp-grid-5b" style="display:grid;grid-template-columns:1fr 1fr 60px 80px 100px;gap:12px;align-items:end;">
                <div>
                    <label class="form-label">Supplier / Provider</label>
                    <input type="text" name="plan_supplier" class="form-control" value="<?= h($editPlan['supplier'] ?? '') ?>" placeholder="e.g. 4G Telecom, Starlink">
                </div>
                <div id="planProfitPreview" style="padding:10px 14px;background:#f8fafc;border-radius:8px;font-size:13px;font-weight:600;">
                    Profit: <span id="ppProfit" style="color:#28a745;">$0.00</span> /mo
                    &nbsp;|&nbsp; Margin: <span id="ppMargin" style="color:#D41C1C;">0%</span>
                </div>
                <div>
                    <label class="form-label">Color</label>
                    <input type="color" name="plan_color" value="<?= h($editPlan['color'] ?? '#2196F3') ?>" style="width:100%;height:38px;border:1.5px solid #dee2e6;border-radius:6px;padding:2px;cursor:pointer;">
                </div>
                <div style="display:flex;align-items:center;padding-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:600;">
                        <input type="checkbox" name="plan_active" <?= ($editPlan['is_active'] ?? true) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#28a745;">
                        Active
                    </label>
                </div>
                <div>
                    <button type="submit" class="btn-kyc-submit" style="padding:10px 18px;font-size:13px;width:100%;">
                        <i class="bi bi-<?= $editPlan ? 'pencil' : 'plus-circle' ?>"></i> <?= $editPlan ? 'Update' : 'Add' ?>
                    </button>
                </div>
            </div>
            <?php if ($editPlan): ?>
            <div style="margin-top:8px;"><a href="?page=dashboard&tab=subscription_plans" style="color:#6b7280;font-size:12px;">Cancel editing</a></div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Filter Tabs -->
<div class="kyc-card">
    <div style="display:flex;align-items:center;gap:0;border-bottom:2px solid #f1f5f9;">
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=all"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='all'?'#2196F3':'transparent' ?>;color:<?= $filterType==='all'?'#2196F3':'#6b7280' ?>;margin-bottom:-2px;">
            All Plans <span style="background:#f1f5f9;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($allPlans) ?></span>
        </a>
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=starlink"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='starlink'?'#2196F3':'transparent' ?>;color:<?= $filterType==='starlink'?'#2196F3':'#6b7280' ?>;margin-bottom:-2px;">
            Starlink <span style="background:#fff5f5;color:#D41C1C;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($byType['starlink']) ?></span>
        </a>
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=fiber"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='fiber'?'#2E7D32':'transparent' ?>;color:<?= $filterType==='fiber'?'#2E7D32':'#6b7280' ?>;margin-bottom:-2px;">
            Fiber <span style="background:#E8F5E9;color:#2E7D32;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($byType['fiber']) ?></span>
        </a>
        <a href="?page=dashboard&tab=subscription_plans&plan_filter=sim"
           style="padding:12px 20px;font-size:13px;font-weight:700;text-decoration:none;border-bottom:2px solid <?= $filterType==='sim'?'#E65100':'transparent' ?>;color:<?= $filterType==='sim'?'#E65100':'#6b7280' ?>;margin-bottom:-2px;">
            SIM <span style="background:#FFF3E0;color:#E65100;padding:1px 8px;border-radius:10px;font-size:11px;"><?= count($byType['sim']) ?></span>
        </a>
        <?php
        $unmappedCount = count(array_filter($allPlans, fn($p) => empty($p['ucrm_product_id']) && !empty($p['is_active'])));
        ?>
        <div style="margin-left:auto;padding:8px 14px;display:flex;align-items:center;gap:8px;">
            <?php if ($unmappedCount > 0): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">
                ⚠️ <?= $unmappedCount ?> not synced to UCRM
            </span>
            <?php else: ?>
            <span style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">
                ✅ All synced to UCRM
            </span>
            <?php endif; ?>
            <button onclick="bulkSyncPlans()" style="background:#2563eb;color:#fff;border:none;border-radius:7px;padding:6px 13px;font-size:12px;font-weight:600;cursor:pointer;">
                🔄 Sync All to UCRM
            </button>
        </div>
    </div>
    <div style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead>
                <tr style="background:#fafbfc;">
                    <th style="width:6px;"></th>
                    <th>Plan Name</th>
                    <th>Type</th>
                    <th>Speed</th>
                    <th>Supplier</th>
                    <th style="text-align:right;color:#dc3545;">Cost</th>
                    <th style="text-align:right;color:#28a745;">Revenue</th>
                    <th style="text-align:right;">Profit</th>
                    <th style="text-align:center;">Margin</th>
                    <th style="text-align:center;">Subs</th>
                    <th style="text-align:center;">UCRM</th>
                    <th>Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($filteredPlans as $pl):
                $profit = $pl['profit'] ?? (($pl['customer_price']??0) - ($pl['starlink_cost']??0));
                $margin = $pl['margin'] ?? (($pl['customer_price']??0) > 0 ? round(($profit / $pl['customer_price']) * 100, 1) : 0);
                $pType = strtolower($pl['type']??'starlink');
                $tl = $typeLabels[$pType] ?? $typeLabels['starlink'];
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td><div style="width:5px;height:32px;border-radius:3px;background:<?= h($pl['color'] ?? '#2196F3') ?>;"></div></td>
                    <td style="font-weight:700;font-size:13px;"><?= h($pl['name']) ?></td>
                    <td>
                        <span style="background:<?= $tl[2] ?>;color:<?= $tl[3] ?>;padding:3px 10px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;"><?= $tl[0] ?></span>
                    </td>
                    <td style="font-size:12px;font-weight:600;"><?= h($pl['speed'] ?? '-') ?></td>
                    <td style="font-size:12px;color:#6b7280;"><?= h($pl['supplier'] ?? '-') ?></td>
                    <td style="text-align:right;font-weight:600;<?= ($pl['starlink_cost']??0) > 0 ? 'color:#dc3545;background:#fef2f2;' : 'color:#d1d5db;' ?>"><?= ($pl['starlink_cost']??0) > 0 ? '$'.number_format($pl['starlink_cost'],2) : '-' ?></td>
                    <td style="text-align:right;font-weight:600;color:#28a745;background:#f0fdf4;">$<?= number_format($pl['customer_price'] ?? 0, 2) ?></td>
                    <td style="text-align:right;font-weight:800;color:<?= $profit >= 0 ? '#28a745' : '#dc3545' ?>;">$<?= number_format($profit, 2) ?></td>
                    <td style="text-align:center;">
                        <div style="position:relative;display:inline-block;">
                            <span style="font-size:12px;font-weight:700;"><?= $margin ?>%</span>
                            <div style="width:40px;height:3px;background:#e5e7eb;border-radius:2px;margin-top:3px;">
                                <div style="width:<?= min(100, max(0, $margin)) ?>%;height:100%;background:<?= $profit >= 0 ? '#28a745' : '#dc3545' ?>;border-radius:2px;"></div>
                            </div>
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <?php if (!empty($pl['active_subs'])): ?>
                            <span style="background:#d4edda;color:#28a745;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?= $pl['active_subs'] ?></span>
                        <?php else: ?>
                            <span style="color:#d1d5db;">-</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if (!empty($pl['ucrm_product_id'])): ?>
                            <a href="https://crm.dishnetafrica.com/crm/system/items/products/<?= (int)$pl['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$pl['ucrm_product_id'] ?> 🔗</a>
                        <?php else: ?>
                            <button onclick="syncPlan(<?= (int)$pl['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($pl['is_active'])): ?>
                            <span class="badge-approved">Active</span>
                        <?php else: ?>
                            <span class="badge-rejected">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;white-space:nowrap;">
                        <a href="?page=dashboard&tab=subscription_plans&edit_plan=<?= $pl['id'] ?>&plan_filter=<?= h($filterType) ?>" style="color:#D41C1C;font-weight:600;font-size:11px;text-decoration:none;margin-right:6px;">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this plan?');">
                        <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_plan">
                            <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
                            <button type="submit" style="background:none;border:none;color:#dc3545;font-weight:600;font-size:11px;cursor:pointer;">
                                <i class="bi bi-trash"></i> Del
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($filteredPlans)): ?>
                <tr><td colspan="12" style="text-align:center;color:#9ca3af;padding:30px;">
                    No <?= $filterType !== 'all' ? $filterType : '' ?> plans configured yet.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function planTypeChanged() {
    const t = document.getElementById('planTypeSelect').value;
    const labels = {starlink:['Starlink Cost','What Starlink charges you'],fiber:['Supplier Cost','What your fiber supplier charges'],sim:['Network Cost','SIM network cost (if any)']};
    const l = labels[t] || labels.starlink;
    document.getElementById('costLabel').textContent = l[0] + ' *';
    document.getElementById('costHint').textContent = l[1];
}
function calcPlanProfit() {
    const cost = parseFloat(document.querySelector('input[name="starlink_cost"]')?.value) || 0;
    const price = parseFloat(document.querySelector('input[name="customer_price"]')?.value) || 0;
    const profit = price - cost;
    const margin = price > 0 ? ((profit / price) * 100).toFixed(1) : 0;
    document.getElementById('ppProfit').textContent = '$' + profit.toFixed(2);
    document.getElementById('ppProfit').style.color = profit >= 0 ? '#28a745' : '#dc3545';
    document.getElementById('ppMargin').textContent = margin + '%';
    document.getElementById('ppMargin').style.color = profit >= 0 ? '#1565C0' : '#dc3545';
}
planTypeChanged();
<?php if ($editPlan): ?>
calcPlanProfit();
<?php endif; ?>

const _TK = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';

function syncPlan(planId, btn) {
    btn.disabled = true; btn.textContent = '⏳';
    fetch('?page=api&action=sync_plan_to_ucrm', {
          credentials:'same-origin',
          method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _TK },
        body: JSON.stringify({ plan_id: planId })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d.data && d.data.ucrm_product_id) {
            btn.outerHTML = '<a href="https://crm.dishnetafrica.com/crm/system/items/products/' + d.data.ucrm_product_id + '" target="_blank" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#' + d.data.ucrm_product_id + ' 🔗</a>';
        } else {
            btn.textContent = '❌'; btn.disabled = false;
            alert('Sync failed: ' + (d.message || 'Unknown error'));
        }
    }).catch(function(){ btn.textContent = '❌'; btn.disabled = false; });
}

function bulkSyncPlans() {
    if (!confirm('Sync all unmapped plans to UCRM products now?')) return;
    fetch('?page=api&action=push_products_to_ucrm&dry_run=0', {
        headers: { 'Authorization': 'Bearer ' + _TK }
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        var s = (d.data||d).summary || {};
        alert('✅ Done!\nCreated: ' + (s.total_created||0) + '\nSkipped (already mapped): ' + (s.total_skipped||0) + '\nFailed: ' + (s.total_failed||0) + '\n\nRefreshing page…');
        window.location.reload();
    }).catch(function(e){ alert('Error: ' + e.message); });
}
</script>

