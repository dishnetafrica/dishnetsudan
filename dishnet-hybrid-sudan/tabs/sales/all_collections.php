<?php
// Tab: all_collections
// Extracted from public.php on 2026-03-15
        // Date filters
        $colFDate  = $_GET['col_from'] ?? '';
        $colTDate  = $_GET['col_to']   ?? '';
        $colSearch = trim($_GET['col_q'] ?? '');
        $colAgent  = trim($_GET['col_agent'] ?? '');
        $allColsRaw = array_reverse($store->load('payment_collections.json'));
        $allCols = array_values(array_filter($allColsRaw, function($c2) use ($colFDate,$colTDate,$colSearch,$colAgent) {
            $d = substr($c2['created_at']??'',0,10);
            if ($colFDate && $d < $colFDate) return false;
            if ($colTDate && $d > $colTDate) return false;
            if ($colSearch && stripos(($c2['customer_name']??'').$c2['crm_customer_id']??'', $colSearch)===false) return false;
            if ($colAgent && (int)($c2['retailer_id']??0) !== (int)$colAgent) return false;
            return true;
        }));
        $todayColTotal = array_sum(array_map(fn($c2) => $c2['amount'] ?? 0, array_filter($allColsRaw, fn($c2) => str_starts_with($c2['created_at']??'', date('Y-m-d')))));
        $filteredTotal = array_sum(array_map(fn($c2) => $c2['amount'] ?? 0, $allCols));
        $syncedCount = count(array_filter($allCols, fn($c2) => !empty($c2['crm_synced'])));
    ?>

<div class="stat-grid">
    <div class="stat-card teal"><div class="stat-label"><?= ($colFDate||$colTDate||$colSearch||$colAgent)?'Filtered Total':'All-Time Total' ?></div><div class="stat-value">$<?= number_format($filteredTotal, 2) ?></div></div>
    <div class="stat-card green"><div class="stat-label">Today</div><div class="stat-value">$<?= number_format($todayColTotal, 2) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">CRM Synced</div><div class="stat-value"><?= $syncedCount ?>/<?= count($allCols) ?></div></div>
</div>
<?php $pendingCount = count($allCols) - $syncedCount; if ($pendingCount > 0 && $isAdmin): ?>
<div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:12px 14px;margin-bottom:10px;">
  <div style="font-size:13px;color:#92400e;font-weight:700;margin-bottom:8px;">⚠ <?= $pendingCount ?> collection<?= $pendingCount>1?'s':'' ?> not yet marked as synced in this system</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <!-- Already entered in CRM manually — just mark synced locally -->
    <form method="POST" onsubmit="return confirm('Mark all <?= $pendingCount ?> as synced?\n\nOnly if they are ALREADY in UCRM (entered manually by admin).')">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="mark_all_synced">
      <button type="submit" style="background:#059669;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;">
        ✅ Already in CRM — Mark Synced
      </button>
    </form>
    <!-- Not in CRM yet — push now -->
    <form method="POST" onsubmit="this.querySelector('button').textContent='Pushing…';this.querySelector('button').disabled=true;return true;">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="push_pending_to_crm">
      <button type="submit" style="background:#d97706;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;">
        🚀 Not in CRM yet — Push Now
      </button>
    </form>
  </div>
  <div style="font-size:11px;color:#b45309;margin-top:8px;">⚠ Don't push if already entered in CRM — will create duplicate payments.</div>
</div>
<?php endif; ?>
<?php if ($isAdmin): ?>
<div style="background:#f0f9ff;border:1.5px solid #bae6fd;border-radius:10px;padding:10px 14px;margin-bottom:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
  <span style="font-size:12px;font-weight:700;color:#0369a1;">📥 Pull Cash payments from CRM → Cashbook</span>
  <form method="POST" style="display:flex;align-items:center;gap:8px;" onsubmit="this.querySelector('button').textContent='Syncing…';this.querySelector('button').disabled=true;return true;">
    <?= csrfField() ?>
    <input type="hidden" name="cb_action" value="crm_sync">
    <input type="hidden" name="redirect_tab" value="all_collections">
    <input type="date" name="sync_from" value="<?= date('Y-m-d', strtotime('-1 day')) ?>" style="padding:6px 10px;border:1.5px solid #bae6fd;border-radius:8px;font-size:12px;">
    <button type="submit" style="background:#0284c7;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;">
      🔄 Sync to Cashbook
    </button>
  </form>
  <span style="font-size:11px;color:#0369a1;">Cash UUID filter · safe to re-run (deduplicates by PAY-id)</span>
  <a href="?page=api&action=debug_cashbook_sync" target="_blank" style="font-size:11px;color:#2563eb;text-decoration:underline;font-weight:700;">🔍 Debug</a>
</div>
<?php endif; ?>

<!-- Filter bar -->
<form method="GET" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;align-items:flex-end;">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="all_collections">
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">FROM</label>
    <input type="date" name="col_from" value="<?= h($colFDate) ?>" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
  </div>
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">TO</label>
    <input type="date" name="col_to" value="<?= h($colTDate) ?>" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
  </div>
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">SEARCH</label>
    <input type="text" name="col_q" value="<?= h($colSearch) ?>" placeholder="Customer / CRM ID" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:150px;">
  </div>
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">AGENT</label>
    <select name="col_agent" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      <option value="">All Agents</option>
      <?php foreach ($allRetailers as $ar): ?>
      <option value="<?= $ar['id'] ?>" <?= (int)$colAgent===(int)$ar['id']?'selected':'' ?>><?= h($ar['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" style="padding:7px 16px;background:#D41C1C;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">🔍 Filter</button>
  <?php if ($colFDate||$colTDate||$colSearch||$colAgent): ?>
  <a href="?page=dashboard&tab=all_collections" style="padding:7px 14px;background:#f1f5f9;color:#64748b;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">✕ Clear</a>
  <?php endif; ?>
  <a href="?page=dashboard&tab=all_collections&col_export=csv<?= $colFDate?'&col_from='.urlencode($colFDate):'' ?><?= $colTDate?'&col_to='.urlencode($colTDate):'' ?><?= $colSearch?'&col_q='.urlencode($colSearch):'' ?><?= $colAgent?'&col_agent='.urlencode($colAgent):'' ?>"
     style="padding:7px 14px;background:#059669;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;">⬇ CSV</a>
  <span style="font-size:12px;color:#6b7280;align-self:center;">Showing <?= count($allCols) ?> of <?= count($allColsRaw) ?></span>
</form>

<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-cash-coin"></i> Payment Collections</div>
    <div class="kyc-card-body" style="padding:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="kyc-table">
            <thead><tr><th>Retailer</th><th>Customer</th><th>CRM ID</th><th style="text-align:right;">Amount</th><th>Method</th><th>Note</th><th>CRM Sync</th><th>Date</th><?php if($isAdmin): ?><th style="text-align:center;width:80px;">Actions</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach (array_slice($allCols, 0, 200) as $col): ?>
            <?php
                $_colEdited = !empty($col['edited_at']);
                $_colDirect = ($col['source']??'') === 'crm_direct';
                $_colKyc    = ($col['source']??'') === 'kyc_cash_sale';
                $_rowBg = $_colDirect ? 'style="background:#f0f9ff;"' : ($_colKyc ? 'style="background:#f0fdf4;"' : ($_colEdited ? 'style="background:#fffbeb;"' : ''));
            ?>
            <tr <?= $_rowBg ?>>
                <td style="font-size:12px;font-weight:600;"><?= h($col['retailer_name']??'') ?>
                    <?php if($_colDirect): ?> <span style="font-size:9px;background:#bae6fd;color:#0369a1;padding:1px 5px;border-radius:4px;font-weight:700;">UCRM</span>
                    <?php elseif($_colKyc): ?> <span style="font-size:9px;background:#dcfce7;color:#166534;padding:1px 5px;border-radius:4px;font-weight:700;">KYC</span>
                    <?php endif; ?>
                </td>
                <td data-label="Customer" style="font-weight:700;"><?= h($col['customer_name']??'') ?></td>
                <td data-label="CRM ID" style="font-size:12px;"><?= h($col['crm_customer_id']??'-') ?></td>
                <td data-label="Amount" style="text-align:right;font-weight:800;color:#28a745;">$<?= number_format($col['amount']??0, 2) ?></td>
                <td style="font-size:12px;"><?= h($col['method']??'Cash') ?><?php if($_colEdited): ?> <span title="Edited by <?= h($col['edited_by']??'') ?> at <?= h($col['edited_at']??'') ?>. <?= h($col['edit_note']??'') ?>" style="color:#f59e0b;font-size:10px;cursor:help;">✏️</span><?php endif; ?></td>
                <td style="font-size:12px;color:#6b7280;max-width:150px;overflow:hidden;text-overflow:ellipsis;">
                    <?php if($_colKyc && !empty($col['kyc_app_id'])): ?>
                        <a href="?page=dashboard&tab=applications&app_id=<?= (int)$col['kyc_app_id'] ?>" style="color:#166534;font-weight:600;font-size:11px;text-decoration:none;">📋 KYC #<?= (int)$col['kyc_app_id'] ?></a>
                        <?php if(!empty($col['note'])): ?><br><span style="font-size:11px;"><?= h($col['note']) ?></span><?php endif; ?>
                    <?php else: ?>
                        <?= h($col['note']??'-') ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($col['crm_synced'])): ?>
                        <span class="badge-approved">Synced</span>
                    <?php elseif (($col['crm_sync_status'] ?? '') === 'failed'): ?>
                        <span style="background:#FEE2E2;color:#DC2626;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;" title="<?= h($col['crm_sync_error'] ?? 'CRM push failed after 5 attempts') ?>">Failed ⚠️</span>
                    <?php else: ?>
                        <span style="background:#FFF3E0;color:#E65100;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">Pending</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:11px;color:#6b7280;white-space:nowrap;"><?= h($col['created_at']??'') ?></td>
                <?php if($isAdmin): ?>
                <td style="text-align:center;white-space:nowrap;">
                    <!-- Edit button -->
                    <button type="button"
                        onclick="openEditCol(<?= (int)$col['id'] ?>, <?= htmlspecialchars(json_encode($col['customer_name']??''), ENT_QUOTES) ?>, <?= (float)($col['amount']??0) ?>, <?= htmlspecialchars(json_encode($col['method']??'Cash'), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($col['note']??''), ENT_QUOTES) ?>, <?= !empty($col['crm_synced']) ? 'true' : 'false' ?>)"
                        style="background:none;border:none;color:#3b82f6;font-size:13px;cursor:pointer;padding:2px 5px;" title="Edit">✏️</button>
                    <!-- Retry button — shown for pending/failed unsynced entries -->
                    <?php if (empty($col['crm_synced']) && (int)($col['crm_customer_id'] ?? 0) > 0): ?>
                    <button type="button"
                        onclick="forceRetryCollection(<?= (int)$col['id'] ?>, <?= htmlspecialchars(json_encode($col['customer_name']??''), ENT_QUOTES) ?>)"
                        style="background:none;border:none;color:#f59e0b;font-size:13px;cursor:pointer;padding:2px 5px;" title="Force retry CRM sync">🔄</button>
                    <?php endif; ?>
                    <!-- Delete button — blocked if synced -->
                    <?php if(empty($col['crm_synced'])): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this collection for <?= h(addslashes($col['customer_name']??'')) ?>? This cannot be undone.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_collection">
                        <input type="hidden" name="collection_id" value="<?= (int)$col['id'] ?>">
                        <button type="submit" style="background:none;border:none;color:#ef4444;font-size:13px;cursor:pointer;padding:2px 5px;" title="Delete">🗑️</button>
                    </form>
                    <?php else: ?>
                    <span style="color:#d1d5db;font-size:11px;" title="Cannot delete — already synced to CRM">🔒</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($allCols)): ?><tr><td colspan="<?= $isAdmin?9:8 ?>" style="text-align:center;color:#9ca3af;padding:20px;">No collections yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if($isAdmin): ?>
<!-- ── Edit Collection Modal ─────────────────────────────────────────── -->
<div id="editColModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:20px;padding:28px 24px;width:100%;max-width:440px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
      <div>
        <div style="font-size:16px;font-weight:800;color:#111827;">✏️ Edit Collection</div>
        <div id="editColCustomer" style="font-size:12px;color:#6b7280;margin-top:2px;"></div>
      </div>
      <button onclick="closeEditCol()" style="background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;">✕</button>
    </div>
    <form method="POST" id="editColForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_collection">
      <input type="hidden" name="collection_id" id="editColId">
      <div id="editColSyncedWarning" style="display:none;background:#FFF3E0;border:1px solid #FB8C00;border-radius:10px;padding:10px 14px;font-size:12px;color:#E65100;margin-bottom:14px;font-weight:600;">
        ⚠️ This collection is already synced to CRM. Editing here will NOT update UCRM — only the local record changes.
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Payment Method</label>
        <select name="method" id="editColMethod" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-weight:600;background:#f8fafc;">
          <option value="Cash">💵 Cash</option>
          <option value="Mobile Money">📱 Mobile Money</option>
          <option value="Bank Transfer">🏦 Bank Transfer</option>
          <option value="Cheque">📝 Cheque</option>
          <option value="Other">🔄 Other</option>
        </select>
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Amount (USD)</label>
        <input type="number" name="amount" id="editColAmount" step="0.01" min="0.01" required style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;font-weight:700;box-sizing:border-box;">
      </div>
      <div style="margin-bottom:20px;">
        <label style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;">Note</label>
        <input type="text" name="note" id="editColNote" placeholder="Optional note..." style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;box-sizing:border-box;">
      </div>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="closeEditCol()" style="flex:1;padding:11px;background:#f1f5f9;color:#374151;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
        <button type="submit" style="flex:2;padding:11px;background:#D41C1C;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;">💾 Save Changes</button>
      </div>
    </form>
  </div>
</div>
<script>
function forceRetryCollection(colId, customerName) {
    if (!confirm('Force retry CRM sync for ' + customerName + '?\n\nThis will immediately attempt to push the payment to UCRM.')) return;
    var btn = event.target;
    btn.textContent = '⏳';
    btn.disabled = true;
    fetch('?page=api&action=force_retry_collection', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({collection_id: colId})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var payload = data.data || data;
        if (data.status === 'success' || payload.synced || payload.already_synced) {
            btn.closest('tr').querySelector('td:nth-child(7)').innerHTML =
                '<span style="background:#D1FAE5;color:#065F46;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;">Synced</span>';
            btn.remove();
            alert('✅ Synced! CRM Payment ID: ' + (payload.crm_payment_id || 'applied'));
        } else {
            btn.textContent = '🔄';
            btn.disabled = false;
            alert('❌ Failed: ' + (data.message || payload.error || 'Unknown error'));
        }
    })
    .catch(function(e) {
        btn.textContent = '🔄';
        btn.disabled = false;
        alert('Network error: ' + e.message);
    });
}

function openEditCol(id, customer, amount, method, note, isSynced) {
    document.getElementById('editColId').value = id;
    document.getElementById('editColCustomer').textContent = 'Customer: ' + customer + '  |  ID #' + id;
    document.getElementById('editColAmount').value = amount;
    document.getElementById('editColNote').value = note;
    var sel = document.getElementById('editColMethod');
    for(var i=0;i<sel.options.length;i++){if(sel.options[i].value===method){sel.selectedIndex=i;break;}}
    document.getElementById('editColSyncedWarning').style.display = isSynced ? 'block' : 'none';
    var modal = document.getElementById('editColModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeEditCol() {
    document.getElementById('editColModal').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('editColModal').addEventListener('click', function(e){
    if(e.target===this) closeEditCol();
});
</script>
<?php endif; ?>

