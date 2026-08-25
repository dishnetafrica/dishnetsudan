<?php
// Tab: hardware
// Extracted from public.php on 2026-03-15
        $allHW = $store->load('kyc_devices.json');
        $editHW = null;
        $hwEditId = (int)($_GET['edit_hw'] ?? 0);
        if ($hwEditId) { foreach ($allHW as $h2) { if ((int)($h2['id']??0)===$hwEditId) { $editHW=$h2; break; } } }
        $hwByType = ['starlink'=>[],'fiber'=>[],'general'=>[]];
        foreach ($allHW as $h2) { $ht = strtolower($h2['type']??'starlink'); $hwByType[$ht][] = $h2; }
    ?>

<div class="stat-grid">
    <div class="stat-card teal"><div class="stat-label">Total Hardware</div><div class="stat-value"><?= count($allHW) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Starlink Kits</div><div class="stat-value"><?= count($hwByType['starlink']??[]) ?></div></div>
    <div class="stat-card green"><div class="stat-label">Fiber Equipment</div><div class="stat-value"><?= count($hwByType['fiber']??[]) ?></div></div>
    <div class="stat-card orange"><div class="stat-label">General / Accessories</div><div class="stat-value"><?= count($hwByType['general']??[]) ?></div></div>
</div>

<!-- Add/Edit Hardware -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-plus-circle"></i> <?= $editHW ? 'Edit Hardware' : 'Add Hardware' ?></div>
    <div class="kyc-card-body">
        <form method="POST">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="save_hardware">
            <?php if ($editHW): ?><input type="hidden" name="hw_id" value="<?= $editHW['id'] ?>"><?php endif; ?>
            <div class="resp-grid-6" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 80px;gap:12px;align-items:end;">
                <div>
                    <label class="form-label">Equipment Name *</label>
                    <input type="text" name="hw_title" class="form-control" value="<?= h($editHW['title'] ?? '') ?>" required placeholder="e.g. WiFi ONU, Starlink Mini Kit">
                </div>
                <div>
                    <label class="form-label">Type</label>
                    <select name="hw_type" class="form-control">
                        <option value="starlink" <?= ($editHW['type']??'')==='starlink'?'selected':'' ?>>Starlink</option>
                        <option value="fiber" <?= ($editHW['type']??'')==='fiber'?'selected':'' ?>>Fiber</option>
                        <option value="general" <?= ($editHW['type']??'')==='general'?'selected':'' ?>>General / Accessories</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Buy Price ($)</label>
                    <input type="number" name="hw_buy" class="form-control" step="0.01" min="0" value="<?= $editHW['buy_price'] ?? '' ?>" placeholder="0.00">
                </div>
                <div>
                    <label class="form-label">Sell Price ($) *</label>
                    <input type="number" name="hw_sell" class="form-control" step="0.01" min="0" value="<?= $editHW['sell_price'] ?? '' ?>" required placeholder="0.00">
                </div>
                <div style="display:flex;align-items:center;padding-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;font-weight:600;">
                        <input type="checkbox" name="hw_active" <?= ($editHW['is_active'] ?? true) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:#28a745;">
                        Active
                    </label>
                </div>
                <div>
                    <button type="submit" class="btn-kyc-submit" style="padding:10px 14px;font-size:13px;width:100%;">
                        <?= $editHW ? 'Update' : 'Add' ?>
                    </button>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:10px;">
                <div>
                    <label class="form-label">SKU / Product Code</label>
                    <input type="text" name="hw_sku" class="form-control" value="<?= h($editHW['sku'] ?? '') ?>" placeholder="e.g. GN-233, SL-MINI">
                </div>
                <div>
                    <label class="form-label">UCRM Product ID</label>
                    <input type="number" name="hw_ucrm_product_id" class="form-control" min="0" value="<?= (int)($editHW['ucrm_product_id'] ?? 0) ?>" placeholder="0" style="font-family:monospace;">
                </div>
                <div>
                    <label class="form-label">Description</label>
                    <input type="text" name="hw_description" class="form-control" value="<?= h($editHW['description'] ?? '') ?>" placeholder="Short product description">
                </div>
            </div>
            <?php if ($editHW): ?><div style="margin-top:6px;"><a href="?page=dashboard&tab=hardware" style="color:#6b7280;font-size:12px;">Cancel</a></div><?php endif; ?>
        </form>
    </div>
</div>

<!-- Starlink Hardware -->
<div class="kyc-card">
    <div class="kyc-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span><i class="bi bi-broadcast"></i> Starlink Hardware</span>
        <?php $hwUnmapped = count(array_filter($allHW, fn($h2) => empty($h2['ucrm_product_id']) && !empty($h2['is_active']))); ?>
        <div style="display:flex;align-items:center;gap:8px;">
            <?php if ($hwUnmapped > 0): ?>
            <span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">⚠️ <?= $hwUnmapped ?> not synced</span>
            <?php else: ?>
            <span style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">✅ All synced</span>
            <?php endif; ?>
            <button onclick="bulkSyncHardware()" style="background:#2563eb;color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;">🔄 Sync All to UCRM</button>
        </div>
    </div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="text-align:center;">UCRM</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($hwByType['starlink']??[] as $hw):
                $hwProfit = ($hw['sell_price']??0) - ($hw['buy_price']??0);
                $hwMargin = ($hw['sell_price']??0) > 0 ? round($hwProfit / $hw['sell_price'] * 100, 1) : 0;
            ?>
            <tr>
                <td style="font-weight:700;"><?= h($hw['title']) ?><?php if(!empty($hw['sku'])): ?> <span style="font-size:10px;background:#e8f4fd;color:#1565c0;padding:1px 5px;border-radius:3px;font-family:monospace;"><?= h($hw['sku']) ?></span><?php endif; ?><br><span style="font-size:11px;color:#6b7280;"><?= h($hw['price']??'') ?><?php if(!empty($hw['description'])): ?> — <?= h($hw['description']) ?><?php endif; ?></span></td>
                <td style="text-align:right;color:#dc3545;">$<?= number_format($hw['buy_price']??0, 2) ?></td>
                <td style="text-align:right;color:#28a745;font-weight:700;">$<?= number_format($hw['sell_price']??0, 2) ?></td>
                <td style="text-align:right;font-weight:600;">$<?= number_format($hwProfit, 2) ?> <span style="font-size:10px;color:#6b7280;">(<?= $hwMargin ?>%)</span></td>
                <td style="text-align:center;">
                    <?php if (!empty($hw['ucrm_product_id'])): ?>
                        <a href="https://crm.dishnetafrica.com/crm/system/items/products/<?= (int)$hw['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$hw['ucrm_product_id'] ?> 🔗</a>
                    <?php else: ?>
                        <button onclick="syncHw(<?= (int)$hw['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                    <?php endif; ?>
                </td>
                <td><?= !empty($hw['is_active']) ? '<span class="badge-approved">Active</span>' : '<span class="badge-rejected">Inactive</span>' ?></td>
                <td>
                    <a href="?page=dashboard&tab=hardware&edit_hw=<?= $hw['id'] ?>" style="color:#D41C1C;font-size:11px;font-weight:600;text-decoration:none;margin-right:8px;"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_hardware"><input type="hidden" name="hw_id" value="<?= $hw['id'] ?>"><button type="submit" style="background:none;border:none;color:#dc3545;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i></button></form>
                    <?= csrfField() ?>
                </td>
            </tr>
            <?php endforeach; if(empty($hwByType['starlink']??[])): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;">No Starlink hardware.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Fiber Installation Fee (separate config — auto-added to every Fiber quote) -->
<div class="kyc-card" style="border-left:4px solid #059669;">
    <div class="kyc-card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span><i class="bi bi-tools"></i> Fiber Installation Fee <span style="font-size:11px;color:#059669;font-weight:600;">(auto-added to every Fiber KYC quote)</span></span>
    </div>
    <div class="kyc-card-body">
        <form method="POST" style="display:flex;flex-wrap:wrap;gap:14px;align-items:end;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_install_fee">
            <div>
                <label class="form-label">Installation Fee ($)</label>
                <input type="number" name="fiber_install_fee" class="form-control" step="0.01" min="0" value="<?= (float)($config['fiber_install_fee'] ?? 100) ?>" style="width:140px;font-size:18px;font-weight:900;">
            </div>
            <div>
                <label class="form-label">UCRM Product ID <small style="color:#64748b;">(links to CRM product catalog)</small></label>
                <input type="number" name="fiber_install_product_id" class="form-control" min="0" value="<?= (int)($config['fiber_install_product_id'] ?? 244) ?>" style="width:140px;font-family:monospace;">
            </div>
            <div>
                <?php $_instProdId = (int)($config['fiber_install_product_id'] ?? 244); ?>
                <?php if ($_instProdId > 0): ?>
                <a href="https://crm.dishnetafrica.com/crm/system/items/products/<?= $_instProdId ?>" target="_blank" style="font-family:monospace;font-size:12px;background:#eff6ff;color:#1d4ed8;padding:6px 12px;border-radius:8px;font-weight:700;text-decoration:none;display:inline-block;">
                    #<?= $_instProdId ?> 🔗 View in CRM
                </a>
                <?php endif; ?>
            </div>
            <div>
                <button type="submit" class="btn-kyc-submit" style="padding:10px 18px;font-size:13px;">💾 Save</button>
            </div>
        </form>
        <div style="font-size:11px;color:#64748b;margin-top:8px;">
            Currently: <strong>$<?= number_format((float)($config['fiber_install_fee'] ?? 100), 2) ?></strong> added to every Fiber quote as "Installation Fee" line item.
            UCRM Product #<?= (int)($config['fiber_install_product_id'] ?? 244) ?> is used for inventory/invoice linking.
        </div>
    </div>
</div>

<!-- Fiber Hardware -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-ethernet"></i> Fiber Equipment</div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="text-align:center;">UCRM</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($hwByType['fiber']??[] as $hw):
                $hwProfit = ($hw['sell_price']??0) - ($hw['buy_price']??0);
                $hwMargin = ($hw['sell_price']??0) > 0 ? round($hwProfit / $hw['sell_price'] * 100, 1) : 0;
            ?>
            <tr>
                <td style="font-weight:700;"><?= h($hw['title']) ?><?php if(!empty($hw['sku'])): ?> <span style="font-size:10px;background:#e8f4fd;color:#1565c0;padding:1px 5px;border-radius:3px;font-family:monospace;"><?= h($hw['sku']) ?></span><?php endif; ?><br><span style="font-size:11px;color:#6b7280;"><?= h($hw['price']??'') ?><?php if(!empty($hw['description'])): ?> — <?= h($hw['description']) ?><?php endif; ?></span></td>
                <td style="text-align:right;color:#dc3545;">$<?= number_format($hw['buy_price']??0, 2) ?></td>
                <td style="text-align:right;color:#28a745;font-weight:700;">$<?= number_format($hw['sell_price']??0, 2) ?></td>
                <td style="text-align:right;font-weight:600;">$<?= number_format($hwProfit, 2) ?> <span style="font-size:10px;color:#6b7280;">(<?= $hwMargin ?>%)</span></td>
                <td style="text-align:center;">
                    <?php if (!empty($hw['ucrm_product_id'])): ?>
                        <a href="https://crm.dishnetafrica.com/crm/system/items/products/<?= (int)$hw['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$hw['ucrm_product_id'] ?> 🔗</a>
                    <?php else: ?>
                        <button onclick="syncHw(<?= (int)$hw['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                    <?php endif; ?>
                </td>
                <td><?= !empty($hw['is_active']) ? '<span class="badge-approved">Active</span>' : '<span class="badge-rejected">Inactive</span>' ?></td>
                <td>
                    <a href="?page=dashboard&tab=hardware&edit_hw=<?= $hw['id'] ?>" style="color:#D41C1C;font-size:11px;font-weight:600;text-decoration:none;margin-right:8px;"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_hardware"><input type="hidden" name="hw_id" value="<?= $hw['id'] ?>"><button type="submit" style="background:none;border:none;color:#dc3545;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i></button></form>
                    <?= csrfField() ?>
                </td>
            </tr>
            <?php endforeach; if(empty($hwByType['fiber']??[])): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;">No Fiber equipment.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div><!-- General / Accessories -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-tools"></i> General Equipment & Accessories</div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead><tr><th>Equipment</th><th style="text-align:right;">Buy Price</th><th style="text-align:right;">Sell Price</th><th style="text-align:right;">Margin</th><th style="text-align:center;">UCRM</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($hwByType['general']??[] as $hw):
                $hwProfit = ($hw['sell_price']??0) - ($hw['buy_price']??0);
                $hwMargin = ($hw['sell_price']??0) > 0 ? round($hwProfit / $hw['sell_price'] * 100, 1) : 0;
            ?>
            <tr>
                <td style="font-weight:700;"><?= h($hw['title']) ?><?php if(!empty($hw['sku'])): ?> <span style="font-size:10px;background:#e8f4fd;color:#1565c0;padding:1px 5px;border-radius:3px;font-family:monospace;"><?= h($hw['sku']) ?></span><?php endif; ?><br><span style="font-size:11px;color:#6b7280;"><?= h($hw['price']??'') ?><?php if(!empty($hw['description'])): ?> — <?= h($hw['description']) ?><?php endif; ?></span></td>
                <td style="text-align:right;color:#dc3545;">$<?= number_format($hw['buy_price']??0, 2) ?></td>
                <td style="text-align:right;color:#28a745;font-weight:700;">$<?= number_format($hw['sell_price']??0, 2) ?></td>
                <td style="text-align:right;font-weight:600;">$<?= number_format($hwProfit, 2) ?> <span style="font-size:10px;color:#6b7280;">(<?= $hwMargin ?>%)</span></td>
                <td style="text-align:center;">
                    <?php if (!empty($hw['ucrm_product_id'])): ?>
                        <a href="https://crm.dishnetafrica.com/crm/system/items/products/<?= (int)$hw['ucrm_product_id'] ?>" target="_blank" title="View in UCRM" style="font-family:monospace;font-size:11px;background:#eff6ff;color:#1d4ed8;padding:2px 7px;border-radius:5px;font-weight:700;text-decoration:none;">#<?= (int)$hw['ucrm_product_id'] ?> 🔗</a>
                    <?php else: ?>
                        <button onclick="syncHw(<?= (int)$hw['id'] ?>,this)" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:5px;padding:2px 8px;font-size:11px;cursor:pointer;font-weight:600;">Sync</button>
                    <?php endif; ?>
                </td>
                <td><?= !empty($hw['is_active']) ? '<span class="badge-approved">Active</span>' : '<span class="badge-rejected">Inactive</span>' ?></td>
                <td>
                    <a href="?page=dashboard&tab=hardware&edit_hw=<?= $hw['id'] ?>" style="color:#D41C1C;font-size:11px;font-weight:600;text-decoration:none;margin-right:8px;"><i class="bi bi-pencil"></i> Edit</a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_hardware"><input type="hidden" name="hw_id" value="<?= $hw['id'] ?>"><button type="submit" style="background:none;border:none;color:#dc3545;font-size:11px;font-weight:600;cursor:pointer;"><i class="bi bi-trash"></i></button></form>
                    <?= csrfField() ?>
                </td>
            </tr>
            <?php endforeach; if(empty($hwByType['general']??[])): ?><tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:20px;">No general equipment.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const _HW_TK = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';
function syncHw(hwId, btn) {
    btn.disabled = true; btn.textContent = '⏳';
    fetch('?page=api&action=sync_hardware_to_ucrm', {
          credentials:'same-origin',
          method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + _HW_TK },
        body: JSON.stringify({ hw_id: hwId })
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
function bulkSyncHardware() {
    if (!confirm('Sync all unmapped hardware to UCRM products now?')) return;
    fetch('?page=api&action=push_products_to_ucrm&dry_run=0', {
        headers: { 'Authorization': 'Bearer ' + _HW_TK }
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        var s = (d.data||d).summary || {};
        alert('✅ Done!\nCreated: ' + (s.total_created||0) + '\nSkipped: ' + (s.total_skipped||0) + '\nFailed: ' + (s.total_failed||0) + '\n\nRefreshing…');
        window.location.reload();
    }).catch(function(e){ alert('Error: ' + e.message); });
}
</script>





