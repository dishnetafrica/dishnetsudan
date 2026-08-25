<?php
// Tab: all_apps
// Extracted from public.php on 2026-03-15
    $appFDate  = $_GET['app_from']   ?? '';
    $appTDate  = $_GET['app_to']     ?? '';
    $appSearch = trim($_GET['app_q'] ?? '');
    $appAgent  = trim($_GET['app_agent'] ?? '');
    $appStatus = trim($_GET['app_status'] ?? '');
    $allAppsRaw = $kyc->getApplications(0, true);
    $allApps = array_values(array_filter($allAppsRaw, function($a) use ($appFDate,$appTDate,$appSearch,$appAgent,$appStatus) {
        $d = substr($a['submitted_at'] ?? $a['created_at'] ?? '',0,10);
        if ($appFDate && $d < $appFDate) return false;
        if ($appTDate && $d > $appTDate) return false;
        if ($appSearch && stripos(($a['firstname']??'').($a['lastname']??'').($a['mobile']??'').($a['crm_client_id']??''), $appSearch)===false) return false;
        if ($appAgent && (int)($a['retailer_id']??0)!==(int)$appAgent) return false;
        if ($appStatus && ($a['status']??'')!==$appStatus) return false;
        return true;
    }));
?>
<!-- Filter bar -->
<form method="GET" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;align-items:flex-end;">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab" value="all_apps">
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">FROM</label>
    <input type="date" name="app_from" value="<?= h($appFDate) ?>" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
  </div>
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">TO</label>
    <input type="date" name="app_to" value="<?= h($appTDate) ?>" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
  </div>
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">SEARCH</label>
    <input type="text" name="app_q" value="<?= h($appSearch) ?>" placeholder="Name / Phone / CRM ID" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;min-width:160px;">
  </div>
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">AGENT</label>
    <select name="app_agent" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      <option value="">All</option>
      <?php foreach ($allRetailers as $ar): ?>
      <option value="<?= $ar['id'] ?>" <?= (int)$appAgent===(int)$ar['id']?'selected':'' ?>><?= h($ar['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;flex-direction:column;gap:3px;">
    <label style="font-size:10px;font-weight:700;color:#6b7280;">STATUS</label>
    <select name="app_status" style="padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
      <option value="">All</option>
      <?php foreach (['new','pending_sync','updated','crm_failed','pending','exhausted'] as $_st): ?>
      <option value="<?= $_st ?>" <?= $appStatus===$_st?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$_st)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" style="padding:7px 16px;background:#D41C1C;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">🔍 Filter</button>
  <?php if ($appFDate||$appTDate||$appSearch||$appAgent||$appStatus): ?>
  <a href="?page=dashboard&tab=all_apps" style="padding:7px 14px;background:#f1f5f9;color:#64748b;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">✕ Clear</a>
  <?php endif; ?>
  <a href="?page=dashboard&action=export_csv&export_tab=applications<?= $appFDate?"&app_from={$appFDate}":'' ?><?= $appTDate?"&app_to={$appTDate}":'' ?>" style="padding:7px 14px;background:#16a34a;color:#fff;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;margin-left:auto;">⬇ CSV</a>
  <span style="font-size:12px;color:#6b7280;align-self:center;"><?= count($allApps) ?> of <?= count($allAppsRaw) ?></span>
</form>

<div class="kyc-card">
    <div class="kyc-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;"><span><i class="bi bi-grid-3x3-gap-fill"></i>All Applications (<?= count($allApps) ?>)</span></div>
    <div style="overflow-x:auto;">
    <table class="kyc-table">
        <thead><tr>
            <th>#</th><th>Retailer</th><th>CRM ID</th><th>CRM Username</th><th>Name</th><th>Mobile</th>
            <th>Type</th><th>Connectivity</th><th>Sales</th><th>Amount</th><th>Status</th><th>Date</th><th>Edit</th>
        </tr></thead>
        <tbody>
        <?php foreach ($allApps as $i => $a): ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td><?= h($a['retailer_name']??'—') ?></td>
            <td><code><?= h($a['crm_client_id']??'—') ?></code></td>
            <td>
                <code style="color:var(--primary);"><?= h($a['username']??'—') ?></code>
            </td>
            <td><?= h(($a['firstname']??'').' '.($a['lastname']??'')) ?></td>
            <td><?= h($a['mobile']??'—') ?></td>
            <td><?= h($a['customer_type']??'—') ?></td>
            <td><?= h($a['connectivity_type']??'—') ?></td>
            <td><?= h($a['sales_type']??'—') ?></td>
            <td><?= $a['amount_charged']>0 ? '$'.number_format($a['amount_charged'],2) : '—' ?></td>
            <td><span class="badge-<?= h($a['status']??'new') ?>"><?= h($a['status']??'') ?></span></td>
            <td><?= h(substr($a['submitted_at']??'',0,10)) ?></td>
            <td>
                <?php if(($a['status']??'')==='new'): ?>
                <button type="button" class="btn btn-xs btn-outline-warning"
                        style="font-size:11px;padding:2px 8px;"
                        onclick="openUsernameEdit(<?= (int)$a['id'] ?>, '<?= h(addslashes($a['username']??'')) ?>')">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <?php else: ?><span style="color:#ccc;font-size:11px;">Locked</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>


<!-- Username Edit Modal -->
<div id="usernameEditModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;padding:24px;width:360px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:4px;">✏️ Edit CRM Username</div>
    <div style="font-size:12px;color:#64748b;margin-bottom:16px;">Update the UCRM login username for this application.</div>
    <input type="text" id="usernameEditInput"
           style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:10px;font-size:14px;font-family:monospace;box-sizing:border-box;margin-bottom:14px;"
           placeholder="e.g. john.doe">
    <input type="hidden" id="usernameEditAppId">
    <div style="display:flex;gap:8px;">
      <button onclick="doUsernameEdit()"
              style="flex:1;background:#1e293b;color:#fff;border:none;border-radius:10px;padding:11px;font-size:13px;font-weight:700;cursor:pointer;">
        💾 Save
      </button>
      <button onclick="document.getElementById('usernameEditModal').style.display='none'"
              style="flex:1;background:#f1f5f9;color:#374151;border:none;border-radius:10px;padding:11px;font-size:13px;font-weight:700;cursor:pointer;">
        Cancel
      </button>
    </div>
    <div id="usernameEditMsg" style="font-size:12px;margin-top:10px;display:none;"></div>
  </div>
</div>

<script>
function openUsernameEdit(appId, currentUsername) {
  document.getElementById('usernameEditAppId').value = appId;
  document.getElementById('usernameEditInput').value = currentUsername || '';
  document.getElementById('usernameEditMsg').style.display = 'none';
  document.getElementById('usernameEditModal').style.display = 'flex';
  setTimeout(function(){ document.getElementById('usernameEditInput').focus(); }, 100);
}

function doUsernameEdit() {
  var appId    = document.getElementById('usernameEditAppId').value;
  var username = document.getElementById('usernameEditInput').value.trim();
  var msg      = document.getElementById('usernameEditMsg');
  if (!username) { msg.textContent = 'Username cannot be empty.'; msg.style.color='#dc2626'; msg.style.display='block'; return; }
  msg.textContent = 'Saving...'; msg.style.color='#64748b'; msg.style.display='block';
  var tok = (document.querySelector('meta[name="dishnet-token"]') || {}).content || '';
  fetch('?page=api&action=kyc_update_username', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + tok },
    body: JSON.stringify({ app_id: appId, username: username })
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    var r=d&&d.data?d.data:d;
    if (r && r.ok) {
      msg.textContent = '✅ Saved!'; msg.style.color = '#16a34a';
      setTimeout(function(){ location.reload(); }, 800);
    } else {
      msg.textContent = r.error || d.message || 'Failed to save.'; msg.style.color = '#dc2626';
    }
  })
  .catch(function(){ msg.textContent = 'Network error.'; msg.style.color = '#dc2626'; });
}

// Close on backdrop click
document.getElementById('usernameEditModal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
</script>

<!-- ════════════════════════════════════════════════════════════════════════
     ADMIN TAB: API DOCS
     ════════════════════════════════════════════════════════════════════ -->
