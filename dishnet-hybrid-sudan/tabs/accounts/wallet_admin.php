<?php
// Tab: wallet_admin
// Extracted from public.php on 2026-03-15
    $pendingCols = array_values(array_filter(
        $store->load('pending_collections.json') ?? [],
        fn($p) => ($p['status'] ?? '') === 'pending_approval'
    ));
?>

<?php if (!empty($pendingCols)): ?>
<div style="background:#FFF7ED;border:2px solid #F97316;border-radius:14px;padding:0;margin-bottom:20px;overflow:hidden;">
    <div style="background:#F97316;color:#fff;padding:12px 18px;display:flex;align-items:center;gap:10px;">
        <span style="font-size:18px;">🚨</span>
        <strong style="font-size:14px;"><?= count($pendingCols) ?> Large Payment<?= count($pendingCols)>1?'s':'' ?> Awaiting Your Approval</strong>
        <span style="font-size:11px;opacity:.85;margin-left:auto;">Agents are blocked until you act</span>
    </div>
    <?php foreach ($pendingCols as $pc): ?>
    <div style="padding:16px 20px;border-top:1px solid #FED7AA;display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
        <div style="flex:1;min-width:200px;">
            <div style="font-size:15px;font-weight:800;color:#1E293B;"><?= h($pc['customer_name']??'') ?></div>
            <div style="font-size:12px;color:#64748B;margin-top:3px;">
                CRM ID: <strong><?= h($pc['crm_customer_id']??'—') ?></strong>
                &nbsp;·&nbsp; Agent: <strong><?= h($pc['retailer_name']??'') ?></strong>
                &nbsp;·&nbsp; Method: <?= h($pc['method']??'') ?>
                &nbsp;·&nbsp; <?= h(substr($pc['submitted_at']??'',0,16)) ?>
            </div>
            <?php if (!empty($pc['note'])): ?>
            <div style="font-size:11px;color:#92400E;margin-top:4px;">Note: <?= h($pc['note']) ?></div>
            <?php endif; ?>
        </div>
        <div style="font-size:24px;font-weight:900;color:#EA580C;min-width:100px;text-align:right;">
            $<?= number_format((float)($pc['amount']??0), 2) ?>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0;">
            <form method="POST" onsubmit="return confirm('Approve $<?= number_format((float)($pc['amount']??0),2) ?> from <?= h(addslashes($pc['customer_name']??'')) ?>?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve_large_collection">
                <input type="hidden" name="pending_collection_id" value="<?= (int)($pc['id']??0) ?>">
                <button type="submit" style="background:#16A34A;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;">✅ Approve</button>
            </form>
            <form method="POST" onsubmit="return confirm('Reject this payment?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject_large_collection">
                <input type="hidden" name="pending_collection_id" value="<?= (int)($pc['id']??0) ?>">
                <input type="hidden" name="reject_note" value="Rejected by admin">
                <button type="submit" style="background:#DC2626;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;">❌ Reject</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row">
<div class="col-md-4">

<!-- Transaction Limit Setting -->
<div class="kyc-card" style="margin-bottom:16px;">
    <div class="kyc-card-header"><i class="bi bi-shield-lock"></i> Transaction Limit</div>
    <div class="kyc-card-body">
        <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="wa_plugin_url" value="<?= h($config['wa_plugin_url']??'') ?>">
            <input type="hidden" name="wa_app_key"    value="<?= h($config['wa_app_key']??'') ?>">
            <input type="hidden" name="wa_accounts_app_key" value="<?= h($config['wa_accounts_app_key']??'') ?>">
            <input type="hidden" name="wa_auth_key"   value="<?= h($config['wa_auth_key']??'') ?>">
            <input type="hidden" name="wa_support_number"  value="<?= h($config['wa_support_number']??'') ?>">
            <input type="hidden" name="wa_accounts_number" value="<?= h($config['wa_accounts_number']??'') ?>">
<input type="hidden" name="evo_api_url"            value="<?= h($config['evo_api_url']??'') ?>">
<input type="hidden" name="evo_api_key"            value="<?= h($config['evo_api_key']??'') ?>">
<input type="hidden" name="evo_instance_name"      value="<?= h($config['evo_instance_name']??'') ?>">
<input type="hidden" name="evo_channel_name"       value="<?= h($config['evo_channel_name']??'marketing') ?>">
<input type="hidden" name="evo_auto_reply_enabled" value="<?= h((string)($config['evo_auto_reply_enabled']??0)) ?>">
            <input type="hidden" name="whatsapp_admin_phone" value="<?= h($config['whatsapp_admin_phone']??'') ?>">
        <div class="form-group">
            <label class="form-label">Approval required above ($)</label>
            <input type="number" name="large_txn_threshold" class="form-control"
                   value="<?= (float)($config['large_txn_threshold'] ?? 500) ?>"
                   step="50" min="0" placeholder="500">
            <small class="text-muted">Agents collecting above this amount are blocked until an admin approves. Set 0 to disable.</small>
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="font-size:13px;">💾 Save Limit</button>
        </form>
    </div>
</div>

<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-cash-coin"></i>Top-Up Retailer Wallet</div>
    <div class="kyc-card-body">
        <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="topup_wallet">
        <div class="form-group">
            <label class="form-label">Select Retailer</label>
            <select name="retailer_id" class="form-control" required>
                <?php foreach ($allRetailers as $r): ?>
                <option value="<?= $r['id'] ?>"><?= h($r['name']) ?> ($<?= number_format($r['wallet']??0,2) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Amount ($) *</label><input type="number" name="amount" class="form-control" step="0.01" min="1" required></div>
        <div class="form-group"><label class="form-label">Note</label><input type="text" name="note" class="form-control" placeholder="e.g. Monthly credit"></div>
        <button type="submit" class="btn btn-success btn-block">💳 Credit Wallet</button>
        </form>
    </div>
</div>
</div><!-- /col-md-4 -->
<div class="col-md-8">
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-journal-text"></i>All Transactions</div>
    <div style="overflow-x:auto;max-height:500px;overflow-y:auto;">
    <?php 
    $allPb = $wallet->getAllPassbook(200);
    
    // Build lookup map from payment_collections to find matching collection IDs
    // Key: retailer_id|amount|date (YmdHi) -> collection
    $allCols = $store->load('payment_collections.json') ?? [];
    $colLookup = [];
    foreach ($allCols as $col) {
        if (($col['status'] ?? '') === 'voided') continue; // Skip already voided
        $rid = $col['retailer_id'] ?? 0;
        $amt = number_format((float)($col['amount'] ?? 0), 2, '.', '');
        $dt = substr($col['created_at'] ?? '', 0, 16); // YYYY-MM-DD HH:MM
        $key = "{$rid}|{$amt}|{$dt}";
        $colLookup[$key] = $col;
        // Also try minute before/after for timing edge cases
        $ts = strtotime($col['created_at'] ?? 'now');
        $key2 = "{$rid}|{$amt}|" . date('Y-m-d H:i', $ts - 60);
        $key3 = "{$rid}|{$amt}|" . date('Y-m-d H:i', $ts + 60);
        if (!isset($colLookup[$key2])) $colLookup[$key2] = $col;
        if (!isset($colLookup[$key3])) $colLookup[$key3] = $col;
    }
    ?>
    <table class="kyc-table">
        <thead><tr><th>#</th><th>Retailer</th><th>Type</th><th>Amount</th><th>Balance After</th><th>Description</th><th>Date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($allPb as $i => $p):
            $rName = '';
            $rId = $p['retailer_id'] ?? 0;
            foreach ($allRetailers as $r) { if ($r['id'] == $rId) { $rName = $r['name']; break; } }
            
            $pbType = $p['entry_type'] ?? ($p['type'] ?? 'debit');
            $isCredit8 = in_array(strtolower($pbType), ['credit','Credit']);
            $isVoided = strpos($p['description'] ?? '', 'VOID:') !== false;
            $hoursSince = (time() - strtotime($p['created_at'] ?? '2000-01-01')) / 3600;
            
            // Try to find matching collection
            $colId = $p['collection_id'] ?? null;
            $matchedCol = null;
            if (!$colId && !$isCredit8 && !$isVoided) {
                $amt = number_format((float)($p['amount'] ?? 0), 2, '.', '');
                $dt = substr($p['created_at'] ?? '', 0, 16);
                $key = "{$rId}|{$amt}|{$dt}";
                if (isset($colLookup[$key])) {
                    $matchedCol = $colLookup[$key];
                    $colId = $matchedCol['id'] ?? null;
                }
            }
            
            // Check if description indicates a payment collection (KYC or Payment collected)
            $desc = $p['description'] ?? '';
            $isPaymentCollection = (strpos($desc, 'KYC') !== false || strpos($desc, 'Payment collected') !== false || strpos($desc, 'collection') !== false);
            
            $canVoid = !$isCredit8 && $colId && !$isVoided && $hoursSince <= 72 && $isPaymentCollection;
        ?>
        <tr<?= $isVoided ? ' style="opacity:0.5;text-decoration:line-through;"' : '' ?>>
            <td><?= $i+1 ?></td>
            <td><?= h($rName) ?></td>
            <td><span class="badge-<?= $isCredit8?'credit':'debit' ?>"><?= $isCredit8?'Credit':'Debit' ?></span></td>
            <td><?= $isCredit8?'+':'-' ?>$<?= number_format($p['amount']??0,2) ?></td>
            <td>$<?= number_format($p['curr_balance']??0,2) ?></td>
            <td><?= h($p['description']??'') ?></td>
            <td><?= h(substr($p['created_at']??'',0,16)) ?></td>
            <td>
            <?php if ($canVoid): ?>
                <button onclick="voidPayment(<?= (int)$colId ?>, '<?= h(addslashes($p['description'] ?? '')) ?>', <?= (float)($p['amount']??0) ?>)" 
                    style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;padding:4px 8px;font-size:10px;font-weight:700;cursor:pointer;"
                    title="Void this payment">🚫 Void</button>
            <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Void Payment Modal -->
<div id="voidModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:16px;width:90%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,0.3);overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:700;font-size:16px;">🚫 Void Payment</span>
        <button onclick="closeVoidModal()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:20px;">
        <div id="voidDetails" style="background:#fef2f2;border-radius:10px;padding:12px;margin-bottom:16px;font-size:13px;"></div>
        <div style="margin-bottom:12px;">
            <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">Reason for void <span style="color:#dc2626;">*</span></label>
            <input type="text" id="voidReason" placeholder="e.g. Customer didn't pay, entered wrong amount" 
                style="width:100%;padding:10px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;">
        </div>
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:10px;margin-bottom:16px;font-size:11px;color:#9a3412;">
            ⚠️ <strong>This action will:</strong><br>
            • Credit the retailer's wallet back<br>
            • Delete the payment from UCRM (if synced)<br>
            • Mark the collection as voided
        </div>
        <input type="hidden" id="voidColId">
        <button onclick="confirmVoid()" style="width:100%;padding:12px;background:#dc2626;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;">
            Confirm Void
        </button>
    </div>
</div>
</div>

<script>
function voidPayment(colId, desc, amount) {
    document.getElementById('voidColId').value = colId;
    document.getElementById('voidReason').value = '';
    document.getElementById('voidDetails').innerHTML = 
        '<strong>Amount:</strong> $' + amount.toFixed(2) + '<br>' +
        '<strong>Description:</strong> ' + desc;
    document.getElementById('voidModal').style.display = 'flex';
}

function closeVoidModal() {
    document.getElementById('voidModal').style.display = 'none';
}

function confirmVoid() {
    const colId = document.getElementById('voidColId').value;
    const reason = document.getElementById('voidReason').value.trim();
    if (!reason) {
        alert('Please enter a reason for voiding this payment');
        return;
    }
    
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Processing...';
    
    fetch('?page=api&action=void_payment', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({collection_id: parseInt(colId), reason: reason})
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            alert('✅ Payment voided successfully!\n\nWallet credited: $' + (d.data.amount || 0).toFixed(2));
            location.reload();
        } else {
            alert('❌ Error: ' + (d.message || 'Failed to void payment'));
            btn.disabled = false;
            btn.textContent = 'Confirm Void';
        }
    })
    .catch(err => {
        alert('❌ Network error: ' + err.message);
        btn.disabled = false;
        btn.textContent = 'Confirm Void';
    });
}
</script>

</div>
</div><!-- /row -->
