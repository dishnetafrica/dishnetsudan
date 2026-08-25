<?php
// ── Access gate: accountant or admin only ──
if (!($retailer['is_admin'] ?? false) && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="padding:40px;color:#dc2626;font-weight:700;">Access denied.</div>';
    return;
}
// Tab: accounts_wallets
// Extracted from public.php on 2026-03-15
    $allRetailers2  = $auth->getAllRetailers();

    // Reads crm_outstanding field written by Sync Wallets → Apply
    $debtRetailers = array_values(array_filter($allRetailers2,
        fn($r) => ((float)($r['wallet'] ?? 0)) > 0.005
    ));
    usort($debtRetailers, fn($a,$b) =>
        (float)($b['wallet']??0) <=> (float)($a['wallet']??0)
    );
    foreach ($debtRetailers as &$_dr) { $_dr['_crm_debt'] = (float)($_dr['wallet'] ?? 0); }
    unset($_dr);
    $totalDebt2 = array_sum(array_column($debtRetailers, '_crm_debt'));
    $_crmCheckedAt2 = null; // use per-retailer crm_outstanding_at
    ?>

<!-- Header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <div style="font-size:16px;font-weight:800;color:#1e293b;">
    <i class="bi bi-exclamation-triangle-fill" style="color:#c0392b;margin-right:6px;"></i>Outstanding Debts
  </div>
  <span style="font-size:11px;color:#888;"><?= count($debtRetailers) ?> retailer<?= count($debtRetailers)!=1?'s':'' ?> owe money</span>
</div>

<?php if (empty($debtRetailers)): ?>
<div style="background:#E8F5E9;border-radius:12px;padding:30px;text-align:center;color:#2E7D32;">
  <div style="font-size:32px;margin-bottom:8px;">✅</div>
  <strong>All clear — no outstanding debts</strong><br>
  <span style="font-size:12px;color:#888;">
    Either no sync has been run yet, or all debts are cleared.<br>
    Go to <strong>Retailers → CRM Org-7 → 💰 Sync Wallets</strong> to pull latest CRM balances.
  </span>
</div>
<?php else: ?>

<!-- Total + cache info -->
<div style="background:linear-gradient(135deg,#B71C1C,#c0392b);border-radius:12px;padding:14px;text-align:center;margin-bottom:14px;color:#fff;">
  <div style="font-size:10px;font-weight:700;text-transform:uppercase;opacity:.7;">Total Outstanding (from CRM)</div>
  <div style="font-size:28px;font-weight:800;">$<?= number_format($totalDebt2,2) ?></div>
  <div style="font-size:11px;opacity:.7;"><?= count($debtRetailers) ?> retailers need to pay</div>
  <?php if ($_crmCheckedAt2): ?>
  <div style="font-size:10px;opacity:.55;margin-top:4px;">Last CRM check: <?= substr($_crmCheckedAt2,0,16) ?></div>
  <?php endif; ?>
</div>

<?php foreach ($debtRetailers as $r):
    $debt     = (float)($r['_crm_debt'] ?? $r['wallet'] ?? 0); // CRM-sourced
    $roleStr  = $r['role'] ?? 'sales';
    $roleColor = ['admin'=>'#1565C0','support'=>'#7B1FA2','support_leader'=>'#4527A0','accountant'=>'#E65100','sales'=>'#2E7D32','field_agent'=>'#E65100'][$roleStr] ?? '#6b7280';
    $urgency  = $debt >= 5000 ? '#c0392b' : ($debt >= 1000 ? '#E65100' : '#F57F17');
    $crmId    = (int)($r['ftth_crm_client_id'] ?? 0);
    $crmUrl   = $crmId ? h(rtrim(preg_replace('#(/crm)?/api/v[^/]*/?$#','',rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')), '/').'/crm/client/'.$crmId) : '';
    $lastSync = $r['wallet_crm_synced_at'] ?? null;
?>
<div style="background:#fff;border-radius:12px;padding:13px 14px;margin-bottom:8px;box-shadow:0 2px 6px rgba(0,0,0,.04);border-left:4px solid <?= $urgency ?>;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:700;color:#1a1a2e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?= h($r['name']) ?>
      </div>
      <div style="font-size:10px;color:#9ca3af;margin-top:2px;">
        <?= h($r['email']) ?>
        &bull; <span style="color:<?= $roleColor ?>;font-weight:700;"><?= ucfirst(str_replace('_',' ',$roleStr)) ?></span>
        <?php if ($crmId): ?>
        &bull; <a href="<?= $crmUrl ?>" target="_blank" style="color:#1B5E20;font-weight:600;text-decoration:none;">CRM #<?= $crmId ?></a>
        <?php endif; ?>
      </div>
      <?php if ($lastSync): ?>
      <div style="font-size:10px;color:#ddd;margin-top:1px;">synced <?= substr($lastSync,0,10) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right;flex-shrink:0;">
      <div style="font-size:20px;font-weight:800;color:<?= $urgency ?>;">$<?= number_format($debt,2) ?></div>
      <div style="font-size:9px;color:#bbb;text-transform:uppercase;">owes DishNet</div>
    </div>
  </div>
  <!-- Recover button -->
  <div style="margin-top:10px;">
    <button onclick="openDebtRecovery(<?= (int)$r['id'] ?>, '<?= h(addslashes($r['name'])) ?>', <?= $debt ?>)"
      style="width:100%;background:#c0392b;color:#fff;border:none;border-radius:8px;padding:9px;font-weight:700;font-size:12px;cursor:pointer;">
      💵 Record Recovery
    </button>
  </div>
</div>
<?php endforeach; ?>
<div style="height:80px;"></div>
<?php endif; ?>

<!-- Debt Recovery Modal (same as dashboard, re-used) -->
<div id="debtRecoveryModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:400px;margin:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#B71C1C,#c0392b);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
      <strong style="color:#fff;font-size:15px;">💵 Record Debt Recovery</strong>
      <button onclick="closeDebtRecovery()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:22px;">
      <div id="debtRecoveryInfo" style="background:#FFEBEE;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;"></div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action"      value="record_debt_recovery">
        <input type="hidden" name="retailer_id" id="dr_retailer_id">
        <div style="margin-bottom:12px;">
          <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Amount Recovered ($) *</label>
          <input type="number" name="amount" id="dr_amount" step="0.01" min="0.01"
            style="width:100%;padding:10px;border:1.5px solid #c0392b;border-radius:8px;font-size:16px;font-weight:700;text-align:right;"
            placeholder="0.00" required>
        </div>
        <div style="margin-bottom:12px;">
          <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Method</label>
          <select name="recovery_method" style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
            <option value="cash">Cash</option>
            <option value="cheque">Cheque</option>
            <option value="adjustment">Manual Adjustment</option>
          </select>
        </div>
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Note</label>
          <input type="text" name="note" maxlength="200"
            style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"
            placeholder="e.g. Cash at office, ref #TX-123">
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" style="flex:1;background:#c0392b;color:#fff;border:none;border-radius:8px;padding:12px;font-weight:700;font-size:14px;cursor:pointer;">✅ Record</button>
          <button type="button" onclick="closeDebtRecovery()" style="background:#f1f5f9;color:#475569;border:none;border-radius:8px;padding:12px 16px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function openDebtRecovery(id, name, outstanding) {
  document.getElementById('dr_retailer_id').value = id;
  document.getElementById('dr_amount').value      = outstanding.toFixed(2);
  document.getElementById('dr_amount').max        = outstanding.toFixed(2);
  document.getElementById('debtRecoveryInfo').innerHTML =
    '<strong>' + name + '</strong><br>Outstanding: <strong style="color:#c0392b;">$' + outstanding.toFixed(2) + '</strong>';
  document.getElementById('debtRecoveryModal').style.display = 'flex';
}
function closeDebtRecovery() {
  document.getElementById('debtRecoveryModal').style.display = 'none';
}
document.getElementById('debtRecoveryModal')?.addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>


