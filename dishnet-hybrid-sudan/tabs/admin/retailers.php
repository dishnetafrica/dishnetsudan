<?php
// Tab: retailers
// Extracted from public.php on 2026-03-15
?>

<!-- ══ Edit Modal ══════════════════════════════════════════════════════════ -->
<div id="editRetailerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:500px;margin:auto;box-shadow:0 8px 40px rgba(0,0,0,.25);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;border-radius:12px 12px 0 0;">
      <strong><i class="bi bi-pencil-square"></i> Edit Staff</strong>
      <button onclick="closeEditModal()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:0;">
      <form method="POST" id="editRetailerForm">
      <?= csrfField() ?>
        <input type="hidden" name="action" value="edit_retailer">
        <input type="hidden" name="retailer_id" id="edit_retailer_id">
        <input type="hidden" name="role" id="edit_role" value="dealer">

        <!-- Tab bar -->
        <div style="display:flex;border-bottom:2px solid #e2e8f0;padding:0 20px;gap:0;overflow-x:auto;-webkit-overflow-scrolling:touch;">
          <button type="button" class="re-tab active" data-tab="re-basic" onclick="reTab(this)">👤 Basic</button>
          <button type="button" class="re-tab" data-tab="re-role" onclick="reTab(this)">🔐 Role</button>
          <button type="button" class="re-tab" data-tab="re-cash" onclick="reTab(this)">💰 Cash</button>
          <button type="button" class="re-tab" data-tab="re-crm" onclick="reTab(this)">🔗 CRM</button>
        </div>

        <!-- Tab: Basic Info -->
        <div class="re-pane active" id="re-basic" style="padding:20px;">
          <div class="row">
            <div class="col-md-6">
              <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" id="edit_phone" class="form-control">
              </div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input type="email" name="email" id="edit_email" class="form-control" required>
          </div>
          <div class="form-group">
            <label class="form-label">New Password <small style="color:#999;font-weight:400;">(leave blank to keep current)</small></label>
            <div style="position:relative;">
              <input type="password" name="password" id="edit_password" class="form-control" placeholder="Enter new password…" style="padding-right:40px;">
              <button type="button" onclick="togglePw('edit_password',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#666;font-size:15px;">👁</button>
            </div>
          </div>
        </div>

        <!-- Tab: Role & Permissions -->
        <div class="re-pane" id="re-role" style="padding:20px;">
          <div style="margin-bottom:14px;">
            <label style="font-size:12px;font-weight:700;margin-bottom:4px;display:block;">Role</label>
            <?php 
            $_editRoles = [];
            try {
                $_editRoles = $rbac->getAllRoles(true);
            } catch (Throwable $e) {
                $_editRoles = [
                    ['id' => 0, 'slug' => 'dealer', 'name' => 'Dealer', 'is_staff' => 0],
                    ['id' => 0, 'slug' => 'sales_staff', 'name' => 'Sales Staff', 'is_staff' => 1],
                    ['id' => 0, 'slug' => 'support', 'name' => 'Support', 'is_staff' => 1],
                    ['id' => 0, 'slug' => 'support_leader', 'name' => 'Support Leader', 'is_staff' => 1],
                    ['id' => 0, 'slug' => 'accountant', 'name' => 'Accountant', 'is_staff' => 1],
                    ['id' => 0, 'slug' => 'admin', 'name' => 'Admin', 'is_staff' => 1],
                ];
            }
            ?>
            <select name="role_id" id="edit_role_id" style="width:100%;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;" onchange="updateRoleSlug(this)">
                <?php foreach ($_editRoles as $_eRole): ?>
                <option value="<?= $_eRole['id'] ?>" data-slug="<?= h($_eRole['slug']) ?>" data-is-staff="<?= $_eRole['is_staff'] ?>">
                    <?= $_eRole['icon'] ?? '👤' ?> <?= h($_eRole['name']) ?>
                    <?= $_eRole['is_staff'] ? '(Staff)' : '(Retailer)' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small style="font-size:10px;color:#666;margin-top:4px;display:block;">
                <span id="edit_role_type_label">🏪 Retailer</span> — 
                <a href="?page=dashboard&tab=roles" target="_blank" style="color:#3b82f6;">Manage Roles</a>
            </small>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:14px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
              <input type="checkbox" name="is_admin" id="edit_is_admin" style="width:16px;height:16px;"> <strong>Admin</strong>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
              <input type="checkbox" name="is_active" id="edit_is_active" style="width:16px;height:16px;accent-color:#28a745;"> <strong>Active</strong>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
              <input type="checkbox" name="on_leave" id="edit_on_leave" style="width:16px;height:16px;accent-color:#f59e0b;"> <strong>🏖 On Leave</strong>
            </label>
          </div>
          <div style="padding:12px;background:#FFF3E0;border-radius:10px;">
            <label style="font-size:11px;font-weight:800;color:#E65100;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:8px;">🏢 Projects</label>
            <div style="display:flex;flex-direction:column;gap:6px;">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;padding:6px 10px;background:#fff;border-radius:8px;border:1.5px solid #e2e8f0;">
                <input type="checkbox" name="projects[]" value="dishnet" class="re-proj-chk" style="width:16px;height:16px;accent-color:#1565C0;"> <span style="color:#1565C0;font-weight:700;">DishNet Fiber & Starlink</span>
              </label>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;padding:6px 10px;background:#fff;border-radius:8px;border:1.5px solid #e2e8f0;">
                <input type="checkbox" name="projects[]" value="4g" class="re-proj-chk" style="width:16px;height:16px;accent-color:#E65100;"> <span style="color:#E65100;font-weight:700;">DishNet 4G</span>
              </label>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;padding:6px 10px;background:#fff;border-radius:8px;border:1.5px solid #e2e8f0;">
                <input type="checkbox" name="projects[]" value="bluecard" class="re-proj-chk" style="width:16px;height:16px;accent-color:#2E7D32;"> <span style="color:#2E7D32;font-weight:700;">BlueCARD (UNMISS)</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Tab: Cash Settings -->
        <div class="re-pane" id="re-cash" style="padding:20px;">
          <div style="padding:14px;background:#FEF2F2;border-radius:12px;margin-bottom:14px;">
            <label style="font-size:12px;font-weight:800;color:#991B1B;display:block;margin-bottom:6px;">💰 Cash Carry Limit (USD)</label>
            <input type="number" name="carry_limit" id="edit_carry_limit" class="form-control" placeholder="Default: <?= number_format((float)($config['advance_carry_limit'] ?? 100), 0) ?>" min="0" step="1" style="background:#fff;font-size:18px;font-weight:700;text-align:center;">
            <small style="color:#991B1B;font-size:11px;margin-top:6px;display:block;">Max cash before collection is blocked. Empty = global default ($<?= number_format((float)($config['advance_carry_limit'] ?? 100), 0) ?>). Set higher for remote agents.</small>
          </div>
          <div style="background:#f8fafc;border-radius:12px;padding:14px;">
            <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;">How it works:</div>
            <div style="font-size:11px;color:#64748b;line-height:1.8;">
              🔴 Agent over limit → <strong>red banner on every page</strong><br>
              ⛔ Collection tab → <strong>blocked until handover</strong><br>
              📱 Daily 8 AM → <strong>WhatsApp reminder sent</strong><br>
              📋 Admin → <strong>notified when agent crosses limit</strong>
            </div>
          </div>
        </div>

        <!-- Tab: CRM Integrations -->
        <div class="re-pane" id="re-crm" style="padding:20px;">
          <div style="padding:14px;background:#EDE7F6;border-radius:12px;margin-bottom:14px;">
            <label style="font-size:12px;font-weight:800;color:#7B1FA2;display:block;margin-bottom:6px;">📋 UCRM User ID <small style="font-weight:400;">(for job notifications)</small></label>
            <input type="number" name="ucrm_user_id" id="edit_ucrm_user_id" class="form-control" placeholder="e.g. 12" min="1" style="background:#fff;">
            <small style="color:#7B1FA2;font-size:10px;margin-top:4px;display:block;">UCRM → System → Users → click user → ID in URL</small>
          </div>
          <div style="padding:14px;background:#E3F2FD;border-radius:12px;">
            <label style="font-size:12px;font-weight:800;color:#1565C0;display:block;margin-bottom:6px;">🔑 UCRM App Key <small style="font-weight:400;">(for payment attribution)</small></label>
            <input type="text" name="ucrm_app_key" id="edit_ucrm_app_key" class="form-control" placeholder="Paste app key" style="background:#fff;font-family:monospace;font-size:11px;">
            <small style="color:#1565C0;font-size:10px;margin-top:4px;display:block;">UCRM → System → Users → App Keys → Generate</small>
          </div>
        </div>

        <!-- Save button (always visible) -->
        <div style="display:flex;gap:10px;padding:16px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;">
          <button type="submit" class="btn btn-primary" style="flex:1;background:var(--primary);border-color:var(--primary);font-weight:600;">
            <i class="bi bi-check-lg"></i> Save Changes
          </button>
          <button type="button" onclick="closeEditModal()" class="btn btn-secondary" style="flex:0 0 auto;">Cancel</button>
        </div>
      </form>
    </div>

<style>
.re-tab{padding:12px 16px;border:none;background:none;font-size:12px;font-weight:700;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:.15s}
.re-tab:hover{color:#1e293b}
.re-tab.active{color:#D41C1C;border-bottom-color:#D41C1C}
.re-pane{display:none}
.re-pane.active{display:block}
</style>
<script>
function reTab(btn){
  document.querySelectorAll('.re-tab').forEach(function(t){t.classList.remove('active')});
  document.querySelectorAll('.re-pane').forEach(function(p){p.classList.remove('active')});
  btn.classList.add('active');
  document.getElementById(btn.dataset.tab).classList.add('active');
}
</script>
  </div>
</div>


<!-- ══ Module Permissions Modal ══════════════════════════════════════════════ -->
<div id="permModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:flex-start;justify-content:center;overflow-y:auto;padding:20px 12px;">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:600px;margin:auto;box-shadow:0 12px 48px rgba(0,0,0,.3);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
      <strong style="font-size:15px;"><span style="margin-right:6px;">🔐</span><span id="permModalTitle">Module Access</span></strong>
      <button onclick="closePermModal()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:20px;">
      <div id="permDefaultHint" style="display:none;background:#FFF8E1;border:1px solid #FFE082;border-radius:10px;padding:12px 14px;font-size:13px;color:#E65100;margin-bottom:16px;">
        <strong>⚠️ Using role defaults.</strong> No custom modules set yet. Check boxes below to override with specific access for this person.
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
        <button type="button" onclick="permSelectAll(true)" style="background:#E8F5E9;color:#2E7D32;border:1px solid #A5D6A7;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">✅ Select All</button>
        <button type="button" onclick="permSelectAll(false)" style="background:#FFEBEE;color:#C62828;border:1px solid #FFCDD2;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">🚫 Clear All</button>
        <span style="flex:1;"></span>
        <button type="button" onclick="permSelectGroup('Sales',true)" style="background:#fff5f5;color:#D41C1C;border:1px solid #BBDEFB;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:600;cursor:pointer;">Sales ✓</button>
        <button type="button" onclick="permSelectGroup('Support',true)" style="background:#F3E5F5;color:#6A1B9A;border:1px solid #CE93D8;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:600;cursor:pointer;">Support ✓</button>
        <button type="button" onclick="permSelectGroup('Accounts',true)" style="background:#FFF3E0;color:#E65100;border:1px solid #FFCC80;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:600;cursor:pointer;">Accounts ✓</button>
        <button type="button" onclick="permSelectGroup('Admin',true)" style="background:#FAFAFA;color:#263238;border:1px solid #CFD8DC;border-radius:8px;padding:5px 12px;font-size:11px;font-weight:600;cursor:pointer;">Admin ✓</button>
      </div>
      <form method="POST" id="permForm">
      <?= csrfField() ?>
        <input type="hidden" name="action" value="save_modules">
        <input type="hidden" name="retailer_id" id="perm_retailer_id">
<?php
$groupColors = [
    'Sales'    => ['bg'=>'#fff5f5','border'=>'rgba(212,28,28,.2)','head'=>'#D41C1C','check'=>'#A81515'],
    'Support'  => ['bg'=>'#F3E5F5','border'=>'#CE93D8','head'=>'#6A1B9A','check'=>'#7B1FA2'],
    'Accounts' => ['bg'=>'#FFF3E0','border'=>'#FFCC80','head'=>'#E65100','check'=>'#F57C00'],
    'Admin'    => ['bg'=>'#F5F5F5','border'=>'#E0E0E0','head'=>'#263238','check'=>'#37474F'],
];
$grouped = [];
foreach ($ALL_MODULES as $m) { $grouped[$m['group']][] = $m; }
foreach ($grouped as $grp => $mods):
    $gc = $groupColors[$grp] ?? $groupColors['Admin'];
?>
        <div style="margin-bottom:16px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <div style="font-size:12px;font-weight:800;color:<?= $gc['head'] ?>;letter-spacing:.5px;text-transform:uppercase;"><?= $grp ?> Modules</div>
            <div style="display:flex;gap:6px;">
              <button type="button" onclick="permSelectGroup('<?= $grp ?>',true)" style="background:<?= $gc['bg'] ?>;color:<?= $gc['head'] ?>;border:1px solid <?= $gc['border'] ?>;border-radius:6px;padding:2px 8px;font-size:11px;cursor:pointer;">All</button>
              <button type="button" onclick="permSelectGroup('<?= $grp ?>',false)" style="background:#fff;color:#999;border:1px solid #e0e0e0;border-radius:6px;padding:2px 8px;font-size:11px;cursor:pointer;">None</button>
            </div>
          </div>
          <div style="background:<?= $gc['bg'] ?>;border:1px solid <?= $gc['border'] ?>;border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
<?php foreach ($mods as $m): ?>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:6px 8px;background:rgba(255,255,255,.7);border-radius:8px;font-size:13px;font-weight:500;color:#374151;">
              <input type="checkbox" name="modules[]" value="<?= h($m['id']) ?>" data-group="<?= h($grp) ?>" style="width:16px;height:16px;accent-color:<?= $gc['check'] ?>;flex-shrink:0;">
              <span><?= $m['icon'] ?> <?= h($m['label']) ?></span>
            </label>
<?php endforeach; ?>
          </div>
        </div>
<?php endforeach; ?>
        <div style="border-top:1px solid #e0e0e0;padding-top:16px;margin-top:4px;display:flex;gap:10px;">
          <button type="submit" style="flex:1;background:#D41C1C;color:#fff;border:none;border-radius:10px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">
            🔐 Save Module Access
          </button>
          <button type="button" onclick="closePermModal()" style="flex:0 0 auto;background:#f1f5f9;color:#374151;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ Delete Confirm Modal ════════════════════════════════════════════════ -->
<div id="deleteRetailerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:400px;margin:auto;box-shadow:0 8px 40px rgba(0,0,0,.25);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#dc3545,#c82333);color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
      <strong><i class="bi bi-trash3-fill"></i> Delete Retailer</strong>
      <button onclick="closeDeleteModal()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:24px;text-align:center;">
      <div style="font-size:48px;margin-bottom:12px;">⚠️</div>
      <p style="font-size:15px;margin-bottom:6px;">Are you sure you want to delete</p>
      <p style="font-size:17px;font-weight:700;color:#dc3545;" id="delete_retailer_name"></p>
      <p style="font-size:12px;color:#999;margin-bottom:20px;">This action cannot be undone. Wallet balance and all data will be removed.</p>
      <form method="POST" id="deleteRetailerForm">
      <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_retailer">
        <input type="hidden" name="retailer_id" id="delete_retailer_id">
        <div style="display:flex;gap:10px;">
          <button type="submit" class="btn btn-danger" style="flex:1;font-weight:600;"><i class="bi bi-trash3"></i> Yes, Delete</button>
          <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary" style="flex:1;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Reassign Leads Modal (for agents on leave) ─────────────────────────── -->
<div id="reassignModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)closeReassignModal()">
  <div style="background:#fff;border-radius:16px;width:100%;max-width:480px;margin:20px;box-shadow:0 8px 40px rgba(0,0,0,.25);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
      <strong>🔄 Reassign Leads — Agent on Leave</strong>
      <button onclick="closeReassignModal()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:20px;">
      <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:10px 14px;font-size:12px;color:#92400E;margin-bottom:16px;">
        All open leads assigned to <strong id="reassignFromName"></strong> will be transferred to the selected agent. Their lead history stays intact — only the assignment changes.
      </div>
      <form method="POST" id="reassignForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="reassign_agent_leads">
        <input type="hidden" name="from_retailer_id" id="reassignFromId">
        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:5px;">Reassign to:</label>
          <select name="to_retailer_id" id="reassignToSelect" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-family:inherit;">
            <option value="">— Select agent —</option>
            <?php foreach(array_filter($allRetailers, fn($r) => ($r['is_active']??true) && empty($r['on_leave'])) as $rr): ?>
            <option value="<?= (int)$rr['id'] ?>"><?= h($rr['name']) ?> (<?= h($rr['role']??'sales') ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="font-size:11px;color:#6b7280;margin-bottom:14px;">
          Only open leads are reassigned (not won/lost leads). Admin sees all leads regardless.
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" style="flex:1;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:10px;padding:11px;font-size:13px;font-weight:800;cursor:pointer;">
            🔄 Reassign All Open Leads
          </button>
          <button type="button" onclick="closeReassignModal()" style="flex:1;background:#f1f5f9;color:#374151;border:none;border-radius:10px;padding:11px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function openReassignModal(id, name) {
  document.getElementById('reassignFromId').value = id;
  document.getElementById('reassignFromName').textContent = name;
  document.getElementById('reassignToSelect').value = '';
  document.getElementById('reassignModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeReassignModal() {
  document.getElementById('reassignModal').style.display = 'none';
  document.body.style.overflow = '';
}
</script>
<?php
$activeCount   = count(array_filter($allRetailers, fn($r) => $r['is_active'] ?? true));
$adminCount    = count(array_filter($allRetailers, fn($r) => $r['is_admin']  ?? false));
$fieldCount    = count(array_filter($allRetailers, fn($r) => ($r['role'] ?? '') === 'field_agent'));
$totalWallet   = array_sum(array_column($allRetailers, 'wallet'));
$org7Cache     = $store->load('org7_crm_cache.json');
$org7Clients   = $org7Cache['clients']    ?? [];
$org7FetchAt   = $org7Cache['fetched_at'] ?? null;
$org7OrgId     = (int)($config['crm_ftth_org_id'] ?? 7);
$syncStatus    = $ftthCrm->getSyncStatus();
$pluginByEmail = [];
$pluginById    = [];
foreach ($allRetailers as $r) {
    $pluginByEmail[strtolower($r['email'] ?? '')] = $r;
    $pluginById[(int)$r['id']] = $r;
}
$unlinkedCrm = array_values(array_filter($org7Clients, fn($c) => empty($c['linked_plugin_id'])));
$me = $auth->currentRetailer();
?>

<style>
/* ── Retailers Tab ──────────────────────────────────────────────────── */
.rt-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:12px; margin-bottom:20px; }
.rt-stat  { background:#fff; border:1px solid #e8ecf0; border-radius:12px; padding:14px 16px; display:flex; flex-direction:column; gap:4px; }
.rt-stat-val  { font-size:22px; font-weight:800; line-height:1; }
.rt-stat-lbl  { font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.4px; }

.rt-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
.rt-search  { flex:1; min-width:200px; max-width:340px; position:relative; }
.rt-search input { width:100%; padding:9px 12px 9px 36px; border:1.5px solid #e2e8f0; border-radius:9px; font-size:13px; background:#fff; outline:none; }
.rt-search input:focus { border-color:#E65100; }
.rt-search .si { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#aaa; font-size:14px; }
.rt-filter  { padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:9px; font-size:12px; font-weight:600; background:#fff; cursor:pointer; color:#555; }
.rt-filter:focus { outline:none; border-color:#E65100; }
.rt-btn { padding:8px 14px; border:none; border-radius:9px; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap; transition:opacity .15s; }
.rt-btn:hover { opacity:.85; }
.rt-btn-primary   { background:#E65100; color:#fff; }
.rt-btn-secondary { background:#D41C1C; color:#fff; }
.rt-btn-green     { background:#2E7D32; color:#fff; }
.rt-btn-outline   { background:#fff; border:1.5px solid #e2e8f0; color:#555; }

/* Card grid for team members */
.rt-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; }
.rt-card  { background:#fff; border:1.5px solid #e8ecf0; border-radius:14px; overflow:hidden; transition:box-shadow .15s,border-color .15s; }
.rt-card:hover { box-shadow:0 4px 18px rgba(0,0,0,.09); border-color:#d0d5dd; }
.rt-card.self   { border-color:#E65100; box-shadow:0 0 0 3px rgba(230,81,0,.08); }
.rt-card.inactive { opacity:.6; }
.rt-card-top  { padding:14px 16px 10px; display:flex; align-items:flex-start; gap:12px; }
.rt-avatar    { width:42px; height:42px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:17px; font-weight:800; flex-shrink:0; }
.rt-card-meta { flex:1; min-width:0; }
.rt-card-name { font-weight:700; font-size:14px; color:#1a1a2e; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.rt-card-email{ font-size:11px; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
.rt-card-body { padding:0 16px 12px; }
.rt-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; border-radius:20px; padding:2px 8px; margin-right:3px; }
.rt-card-footer { border-top:1px solid #f0f2f5; padding:10px 12px; display:flex; gap:6px; align-items:center; background:#fafbfc; }
.rt-card-footer .rt-btn { padding:5px 11px; font-size:11px; border-radius:7px; }

/* Pagination */
.rt-pagination { display:flex; gap:4px; align-items:center; justify-content:flex-end; margin-top:16px; }
.rt-page-btn   { width:32px; height:32px; border:1.5px solid #e2e8f0; border-radius:7px; background:#fff; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; color:#555; }
.rt-page-btn:hover  { border-color:#E65100; color:#E65100; }
.rt-page-btn.active { background:#E65100; border-color:#E65100; color:#fff; }
.rt-page-info { font-size:12px; color:#888; margin-right:6px; }

/* Add / CRM drawer */
.rt-drawer { background:#fff; border:1.5px solid #e8ecf0; border-radius:14px; margin-bottom:16px; overflow:hidden; }
.rt-drawer-hdr { padding:12px 18px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; user-select:none; }
.rt-drawer-hdr:hover { background:#fafafa; }
.rt-drawer-body { padding:18px; border-top:1px solid #f0f2f5; display:none; }
.rt-drawer-body.open { display:block; }

/* Section header */
.rt-section-hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:2px solid #f0f2f5; padding-bottom:10px; }
.rt-section-title { font-size:15px; font-weight:800; color:#1a1a2e; display:flex; align-items:center; gap:8px; }
</style>

<!-- ── Stats row ──────────────────────────────────────────────────────────── -->
<div class="rt-stats">
  <div class="rt-stat">
    <div class="rt-stat-val" style="color:#E65100;"><?= count($allRetailers) ?></div>
    <div class="rt-stat-lbl">Total Staff</div>
  </div>
  <div class="rt-stat">
    <div class="rt-stat-val" style="color:#2E7D32;"><?= $activeCount ?></div>
    <div class="rt-stat-lbl">Active</div>
  </div>
  <div class="rt-stat">
    <div class="rt-stat-val" style="color:#D41C1C;"><?= $adminCount ?></div>
    <div class="rt-stat-lbl">Admins</div>
  </div>
  <div class="rt-stat">
    <div class="rt-stat-val" style="color:#6A1B9A;"><?= $fieldCount ?></div>
    <div class="rt-stat-lbl">Field Agents</div>
  </div>
  <div class="rt-stat">
    <div class="rt-stat-val" style="color:#00838F; font-size:17px;">$<?= number_format($totalWallet, 0) ?></div>
    <div class="rt-stat-lbl">Total Wallet</div>
  </div>
  <div class="rt-stat">
    <div class="rt-stat-val" style="color:<?= $syncStatus['unsynced'] > 0 ? '#BF360C' : '#2E7D32'; ?>;"><?= $syncStatus['synced'] ?>/<?= $syncStatus['total'] ?></div>
    <div class="rt-stat-lbl">CRM Linked</div>
  </div>
</div>

<!-- ── Collapsible: Add New Staff ─────────────────────────────────────────── -->
<div class="rt-drawer" id="drawerAdd">
  <div class="rt-drawer-hdr" onclick="toggleDrawer('drawerAdd')">
    <span style="font-weight:700;font-size:13px;">＋ Add New Staff Account</span>
    <span id="drawerAddArrow" style="color:#E65100;font-size:16px;transition:transform .2s;">▾</span>
  </div>
  <div class="rt-drawer-body" id="drawerAddBody">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
      <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:12px;">Full Name *</label>
        <input type="text" name="name" form="createRetailerForm" class="form-control" required>
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:12px;">Email *</label>
        <input type="email" name="email" form="createRetailerForm" class="form-control" required>
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:12px;">Phone</label>
        <input type="text" name="phone" form="createRetailerForm" class="form-control">
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:12px;">Password <span style="color:#9ca3af;font-weight:400;">(default: 123456)</span></label>
        <div style="position:relative;">
          <input type="password" name="password" id="create_password" form="createRetailerForm" class="form-control" placeholder="Leave blank for 123456" style="padding-right:38px;">
          <button type="button" onclick="togglePw('create_password',this)" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#888;font-size:14px;">👁</button>
        </div>
        <div style="font-size:11px;color:#92400e;background:#FEF3C7;border-radius:6px;padding:4px 8px;margin-top:4px;">
          🔐 Staff will be prompted to change password on first login
        </div>
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:12px;">Role</label>
        <select name="role" form="createRetailerForm" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
          <option value="sales">Sales / Retailer</option>
          <option value="field_agent">Field Agent</option>
          <option value="collection">Collection Agent</option>
          <option value="field_accountant">🧾 Field Accountant</option>
          <option value="support">Support Engineer</option>
          <option value="support_leader">Support Leader</option>
          <option value="accountant">Accountant</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:12px;">Staff Type</label>
        <select name="is_employee" id="crIsEmployee" form="createRetailerForm"
          style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;"
          onchange="crToggleComm(this.value)">
          <option value="1" selected>👔 Employee (Company Payroll — no commission)</option>
          <option value="0">🤝 External Agent (Commission-based)</option>
        </select>
      </div>
      <div id="crCommSection" style="display:none;margin:0;">
        <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:12px;display:flex;gap:10px;align-items:flex-end;">
          <div style="flex:1;">
            <label class="form-label" style="font-size:12px;">Commission Type</label>
            <select name="commission_type" form="createRetailerForm" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
              <option value="percent">% of transaction</option>
              <option value="flat">Flat $ per transaction</option>
            </select>
          </div>
          <div style="flex:1;">
            <label class="form-label" style="font-size:12px;">Rate (% or $)</label>
            <input type="number" name="commission_rate" form="createRetailerForm" class="form-control" value="5" step="0.1" min="0">
          </div>
        </div>
      </div>
      <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:12px;">Starting Wallet ($)</label>
        <input type="number" name="wallet" form="createRetailerForm" class="form-control" value="0" step="0.01" min="0">
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:16px;margin-top:12px;">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;font-weight:600;">
        <input type="checkbox" name="is_admin" form="createRetailerForm" style="width:15px;height:15px;">
        Admin access
      </label>
      <form id="createRetailerForm" method="POST" style="margin:0;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_retailer">
        <button type="submit" class="rt-btn rt-btn-primary">
          <i class="bi bi-person-plus"></i> Create Account
        </button>
      </form>
    </div>
  </div>
</div>

<!-- ── Collapsible: Import from CRM Search ────────────────────────────────── -->
<div class="rt-drawer" id="drawerCRM">
  <div class="rt-drawer-hdr" onclick="toggleDrawer('drawerCRM')">
    <span style="font-weight:700;font-size:13px;">🔍 Import from CRM (any organization)</span>
    <span id="drawerCRMArrow" style="color:#D41C1C;font-size:16px;transition:transform .2s;">▾</span>
  </div>
  <div class="rt-drawer-body" id="drawerCRMBody">
    <p style="font-size:12px;color:#6b7280;margin:0 0 10px;">Search UCRM clients by name or email to import as plugin staff.</p>
    <div style="display:flex;gap:8px;">
      <input type="text" id="crmStaffSearch" class="form-control" placeholder="Search name or email…" style="font-size:13px;" onkeydown="if(event.key==='Enter')searchCrmStaff()">
      <button type="button" onclick="searchCrmStaff()" class="rt-btn rt-btn-secondary"><i class="bi bi-search"></i> Search</button>
    </div>
    <div id="crmStaffResults" style="margin-top:10px;max-height:280px;overflow-y:auto;border:1px solid #f0f2f5;border-radius:8px;"></div>
  </div>
</div>

<!-- ── Toolbar ────────────────────────────────────────────────────────────── -->
<div class="rt-toolbar">
  <div class="rt-search">
    <span class="si bi bi-search"></span>
    <input type="text" id="rtSearch" placeholder="Search name, email, phone…" oninput="rtFilter()">
  </div>
  <select id="rtRoleFilter" class="rt-filter" onchange="rtFilter()">
    <option value="">All Roles</option>
    <option value="admin">Admin</option>
    <option value="sales">Sales</option>
    <option value="field_agent">Field Agent</option>
    <option value="field_accountant">🧾 Field Accountant</option>
    <option value="accountant">Accountant</option>
    <option value="support">Support</option>
    <option value="support_leader">Support Leader</option>
  </select>
  <select id="rtStatusFilter" class="rt-filter" onchange="rtFilter()">
    <option value="">All Status</option>
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
  </select>
  <select id="rtCrmFilter" class="rt-filter" onchange="rtFilter()">
    <option value="">CRM: All</option>
    <option value="linked">CRM Linked</option>
    <option value="unlinked">Not Linked</option>
  </select>
  <div style="margin-left:auto;display:flex;gap:6px;align-items:center;">
    <span id="rtCount" style="font-size:12px;color:#888;"></span>
    <a href="?page=dashboard&export=retailers" class="rt-btn rt-btn-outline"><i class="bi bi-download"></i> Export</a>
    <a href="?page=install" target="_blank" class="rt-btn" style="background:linear-gradient(135deg,#128C7E,#25D366);color:#fff;font-weight:700;gap:6px;">
      <i class="bi bi-phone"></i> 📲 App Install Link
    </a>
  </div>
</div>

<!-- ── Team Cards Grid ────────────────────────────────────────────────────── -->
<?php
$roleColors = [
  'admin'          => ['bg'=>'#E3F2FD','color'=>'#1565C0','avatar'=>'#1565C0','label'=>'Admin 🔑'],
  'sales'          => ['bg'=>'#E8F5E9','color'=>'#2E7D32','avatar'=>'#2E7D32','label'=>'Sales'],
  'field_agent'    => ['bg'=>'#FFF3E0','color'=>'#E65100','avatar'=>'#E65100','label'=>'Field Agent'],
  'accountant'     => ['bg'=>'#FFF8E1','color'=>'#F57F17','avatar'=>'#F57F17','label'=>'Accountant'],
  'support'        => ['bg'=>'#F3E5F5','color'=>'#7B1FA2','avatar'=>'#7B1FA2','label'=>'Support'],
  'support_leader' => ['bg'=>'#EDE7F6','color'=>'#4A148C','avatar'=>'#4A148C','label'=>'Sup. Leader'],
];
?>
<div class="rt-grid" id="rtGrid">
<?php foreach ($allRetailers as $r):
  $rid    = (int)$r['id'];
  $role   = $r['role'] ?? ($r['is_admin'] ? 'admin' : 'sales');
  $rc     = $roleColors[$role] ?? $roleColors['sales'];
  $isSelf = ($me && $me['id'] === $rid);
  $isActive = $r['is_active'] ?? true;
  $initials = strtoupper(substr($r['name'] ?? '?', 0, 1) . (strpos($r['name'],' ') !== false ? substr(strrchr($r['name'],' '),1,1) : ''));
  $hasCrm   = !empty($r['ftth_crm_client_id']);
  $crmUrl   = $hasCrm ? h(rtrim(preg_replace('#(/crm)?/api/v[^/]*/?$#','',rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')), '/').'/crm/client/'.(int)$r['ftth_crm_client_id']) : '';
  $dataAttrs = 'data-name="'.h(strtolower($r['name']??'')).'" data-email="'.h(strtolower($r['email']??'')).'" data-phone="'.h(strtolower($r['phone']??'')).'" data-role="'.h($role).'" data-status="'.($isActive?'active':'inactive').'" data-crm="'.($hasCrm?'linked':'unlinked').'"';
?>
<div class="rt-card <?= $isSelf?'self':'' ?> <?= !$isActive?'inactive':'' ?>" <?= $dataAttrs ?>>
  <div class="rt-card-top">
    <div class="rt-avatar" style="background:<?= $rc['avatar'] ?>1a;color:<?= $rc['avatar'] ?>;">
      <?= h($initials) ?>
    </div>
    <div class="rt-card-meta">
      <div class="rt-card-name">
        <?= h($r['name']) ?>
        <?php if ($isSelf): ?><span style="font-size:9px;background:#E65100;color:#fff;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;">YOU</span><?php endif; ?>
        <?php if (!$isActive): ?><span style="font-size:9px;background:#dc3545;color:#fff;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;">INACTIVE</span><?php endif; ?>
        <?php if (!empty($r['on_leave'])): ?><span style="font-size:9px;background:#f59e0b;color:#fff;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;">🏖 ON LEAVE</span><?php endif; ?>
        <?php if (!empty($r['must_change_pwd'])): ?><span title="Must change password on next login" style="font-size:9px;background:#FEF3C7;color:#92400E;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;">🔐 PWD</span><?php endif; ?>
        <?php if (!($r['is_employee']??true)): ?><span title="Commission-based agent" style="font-size:9px;background:#EDE9FE;color:#5B21B6;padding:1px 5px;border-radius:3px;margin-left:4px;vertical-align:middle;">🤝 AGENT</span><?php endif; ?>
      </div>
      <div class="rt-card-email" title="<?= h($r['email']) ?>"><?= h($r['email']) ?></div>
      <?php if (!empty($r['phone'])): ?><div style="font-size:11px;color:#aaa;margin-top:1px;"><?= h($r['phone']) ?></div><?php endif; ?>
    </div>
    <div style="flex-shrink:0;">
      <span class="rt-badge" style="background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;"><?= $rc['label'] ?></span>
    </div>
  </div>

  <div class="rt-card-body">
    <div style="display:flex;flex-wrap:wrap;gap:5px;align-items:center;">
      <!-- Wallet -->
      <span style="font-size:12px;font-weight:700;color:var(--primary);">💰 $<?= number_format($r['wallet']??0,2) ?></span>
      <?php
        $rProjs = $r['projects'] ?? (!empty($r['project']) ? [$r['project']] : ['dishnet']);
        if (!is_array($rProjs)) $rProjs = [$rProjs];
        $projStyles = ['dishnet'=>['Fiber&SL','#E3F2FD','#1565C0'],'4g'=>['4G','#FFF3E0','#E65100'],'bluecard'=>['BlueCARD','#E8F5E9','#2E7D32']];
        foreach ($rProjs as $_rp):
            $_pS = $projStyles[$_rp] ?? $projStyles['dishnet'];
      ?>
      <span style="font-size:10px;font-weight:700;background:<?= $_pS[1] ?>;color:<?= $_pS[2] ?>;border-radius:4px;padding:1px 7px;"><?= $_pS[0] ?></span>
      <?php endforeach; ?>
      <!-- CRM link -->
      <?php if ($hasCrm): ?>
      <a href="<?= $crmUrl ?>" target="_blank" style="font-size:10px;font-weight:700;background:#E8F5E9;color:#1B5E20;border-radius:4px;padding:1px 7px;text-decoration:none;">🔗 CRM #<?= (int)$r['ftth_crm_client_id'] ?></a>
      <?php else: ?>
      <span style="font-size:10px;font-weight:600;background:#FFF3E0;color:#BF360C;border-radius:4px;padding:1px 7px;">⚠ No CRM link</span>
      <?php endif; ?>
      <!-- UCRM User ID mapping status -->
      <?php if (in_array($role, ['support','support_leader','admin'])): ?>
        <?php if (!empty($r['ucrm_user_id'])): ?>
        <span title="UCRM User ID mapped — Jobs tab will work" style="font-size:10px;font-weight:700;background:#E8F5E9;color:#1B5E20;border-radius:4px;padding:1px 7px;">🔗 UCRM #<?= (int)$r['ucrm_user_id'] ?></span>
        <?php else: ?>
        <span title="No UCRM User ID — Jobs tab will show Not Linked" onclick="openEditModal(<?= $rid ?>)" style="font-size:10px;font-weight:700;background:#FFF3E0;color:#BF360C;border-radius:4px;padding:1px 7px;cursor:pointer;">⚠ Set UCRM ID</span>
        <?php endif; ?>
      <?php endif; ?>
      <!-- Modules -->
      <?php if (!empty($r['modules']) && is_array($r['modules'])): ?>
        <?php $sh=array_slice($r['modules'],0,2); $ex=count($r['modules'])-count($sh); ?>
        <?php foreach($sh as $mid): ?><span style="background:#fff5f5;color:#D41C1C;border-radius:20px;padding:1px 6px;font-size:10px;font-weight:600;"><?= h($mid) ?></span><?php endforeach; ?>
        <?php if($ex>0): ?><span style="background:#f1f5f9;color:#64748b;border-radius:20px;padding:1px 6px;font-size:10px;">+<?= $ex ?></span><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if (!empty($r['ftth_crm_synced_at'])): ?>
    <div style="font-size:10px;color:#bbb;margin-top:5px;">Synced <?= substr($r['ftth_crm_synced_at'],0,10) ?></div>
    <?php endif; ?>
  </div>

  <div class="rt-card-footer">
    <button onclick="openEditModal(<?= $rid ?>)" class="rt-btn rt-btn-primary" title="Edit">
      <i class="bi bi-pencil"></i> Edit
    </button>
    <?php if (!($r['is_admin']??false)): ?>
    <button onclick="openPermModal(<?= $rid ?>)" class="rt-btn" style="background:#fff5f5;color:#D41C1C;border:1px solid #BBDEFB;font-size:11px;padding:5px 10px;border-radius:7px;font-weight:700;" title="Module Permissions">
      🔐 Modules
    </button>
    <?php endif; ?>
    <?php if (!empty($r['on_leave'])): ?>
    <button onclick="openReassignModal(<?= $rid ?>,'<?= h(addslashes($r['name'])) ?>')" class="rt-btn" style="background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;font-size:11px;padding:5px 10px;border-radius:7px;font-weight:700;" title="Reassign leads while on leave">
      🔄 Reassign Leads
    </button>
    <?php endif; ?>
    <?php if (!$isSelf): ?>
    <button onclick="openDeleteModal(<?= $rid ?>)" class="rt-btn" style="background:#fff;color:#dc3545;border:1.5px solid #f5c6cb;font-size:11px;padding:5px 10px;border-radius:7px;font-weight:700;margin-left:auto;" title="Delete">
      <i class="bi bi-trash3"></i>
    </button>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Empty state -->
<div id="rtEmpty" style="display:none;text-align:center;padding:48px 20px;color:#bbb;">
  <div style="font-size:40px;margin-bottom:10px;">🔍</div>
  <strong style="font-size:14px;color:#999;">No staff match your filters</strong>
  <div style="font-size:12px;margin-top:4px;">Try adjusting your search or filters</div>
</div>

<!-- ── Pagination controls ─────────────────────────────────────────────────── -->
<div id="rtPaginationWrap" class="rt-pagination"></div>

<script>
(function() {
  var PER_PAGE = 12;
  var currentPage = 1;
  var filtered = [];

  function getCards() {
    return Array.from(document.querySelectorAll('#rtGrid .rt-card'));
  }

  window.rtFilter = function() {
    var q      = document.getElementById('rtSearch').value.toLowerCase().trim();
    var role   = document.getElementById('rtRoleFilter').value;
    var status = document.getElementById('rtStatusFilter').value;
    var crm    = document.getElementById('rtCrmFilter').value;

    filtered = getCards().filter(function(c) {
      var name  = c.getAttribute('data-name')   || '';
      var email = c.getAttribute('data-email')  || '';
      var phone = c.getAttribute('data-phone')  || '';
      var r     = c.getAttribute('data-role')   || '';
      var s     = c.getAttribute('data-status') || '';
      var crmS  = c.getAttribute('data-crm')   || '';

      if (q && name.indexOf(q) === -1 && email.indexOf(q) === -1 && phone.indexOf(q) === -1) return false;
      if (role   && r    !== role)   return false;
      if (status && s    !== status) return false;
      if (crm    && crmS !== crm)    return false;
      return true;
    });

    currentPage = 1;
    render();
  };

  function render() {
    var all     = getCards();
    var total   = filtered.length;
    var pages   = Math.ceil(total / PER_PAGE) || 1;
    var start   = (currentPage - 1) * PER_PAGE;
    var visible = filtered.slice(start, start + PER_PAGE);
    var visSet  = new Set(visible);

    all.forEach(function(c) { c.style.display = 'none'; });
    visible.forEach(function(c) { c.style.display = ''; });

    document.getElementById('rtEmpty').style.display = (total === 0) ? '' : 'none';
    document.getElementById('rtCount').textContent = total + ' of ' + all.length + ' staff';

    // Pagination
    var wrap = document.getElementById('rtPaginationWrap');
    wrap.innerHTML = '';
    if (pages <= 1) return;

    var info = document.createElement('span');
    info.className = 'rt-page-info';
    info.textContent = 'Page ' + currentPage + ' of ' + pages;
    wrap.appendChild(info);

    function mkBtn(label, page, active) {
      var b = document.createElement('button');
      b.className = 'rt-page-btn' + (active ? ' active' : '');
      b.innerHTML = label;
      b.disabled  = active;
      b.onclick   = function() { currentPage = page; render(); window.scrollTo({top:0,behavior:'smooth'}); };
      return b;
    }

    wrap.appendChild(mkBtn('‹', Math.max(1,currentPage-1), currentPage===1));

    var lo = Math.max(1, currentPage-2);
    var hi = Math.min(pages, currentPage+2);
    if (lo > 1) { wrap.appendChild(mkBtn('1',1,false)); if(lo>2) { var d=document.createElement('span'); d.className='rt-page-info'; d.textContent='…'; wrap.appendChild(d); } }
    for (var p = lo; p <= hi; p++) wrap.appendChild(mkBtn(p, p, p===currentPage));
    if (hi < pages) { if(hi<pages-1){ var d2=document.createElement('span'); d2.className='rt-page-info'; d2.textContent='…'; wrap.appendChild(d2); } wrap.appendChild(mkBtn(pages,pages,false)); }

    wrap.appendChild(mkBtn('›', Math.min(pages,currentPage+1), currentPage===pages));
  }

  // Init on load — show all
  filtered = getCards();
  render();
})();
</script>

<script>
// ── Retailer data map — used by all modals ─────────────────────────────────
var RETAILER_DATA = <?php
  $rdMap = [];
  foreach ($allRetailers as $r) {
    $rdMap[(int)$r['id']] = [
      'id'           => (int)$r['id'],
      'name'         => $r['name']         ?? '',
      'email'        => $r['email']        ?? '',
      'phone'        => $r['phone']        ?? '',
      'role'         => $r['role']         ?? 'sales',
      'is_admin'     => (bool)($r['is_admin']  ?? false),
      'is_active'    => (bool)($r['is_active'] ?? true),
      'on_leave'     => (bool)($r['on_leave']  ?? false),
      'ucrm_user_id' => $r['ucrm_user_id'] ?? null,
      'ucrm_app_key' => $r['ucrm_app_key'] ?? null,
      'project'      => $r['project']      ?? 'dishnet',
      'projects'     => $r['projects']     ?? (!empty($r['project']) ? [$r['project']] : ['dishnet']),
      'carry_limit'  => $r['carry_limit']  ?? null,
      'role_id'      => $r['role_id']      ?? null,
      'modules'      => $r['modules']      ?? [],
    ];
  }
  echo json_encode($rdMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
?>;

function openEditModal(id) {
  var d = RETAILER_DATA[id] || {};
  document.getElementById('edit_retailer_id').value  = id;
  document.getElementById('edit_ucrm_user_id').value = d.ucrm_user_id || '';
  document.getElementById('edit_ucrm_app_key').value = d.ucrm_app_key || '';
  document.getElementById('edit_carry_limit').value = d.carry_limit || '';
  // Set project checkboxes
  var projArr = d.projects || (d.project ? [d.project] : ['dishnet']);
  document.querySelectorAll('.re-proj-chk').forEach(function(c){ c.checked = projArr.indexOf(c.value) !== -1; });
  document.getElementById('edit_name').value         = d.name    || '';
  document.getElementById('edit_email').value        = d.email   || '';
  document.getElementById('edit_phone').value        = d.phone   || '';
  document.getElementById('edit_password').value     = '';
  document.getElementById('edit_is_admin').checked   = d.is_admin  || false;
  document.getElementById('edit_is_active').checked  = d.is_active !== undefined ? d.is_active : true;
  document.getElementById('edit_on_leave').checked   = d.on_leave  || false;
  
  // Set role_id dropdown (new RBAC system)
  var roleIdSel = document.getElementById('edit_role_id');
  var roleSel = document.getElementById('edit_role');
  var roleTypeLabel = document.getElementById('edit_role_type_label');
  
  if (roleIdSel) {
    var roleIdToSelect = d.role_id || 0;
    var legacyRole = d.role || 'sales';
    var foundById = false;
    
    // Try to match by role_id first
    for (var i = 0; i < roleIdSel.options.length; i++) {
      if (roleIdToSelect && parseInt(roleIdSel.options[i].value) === parseInt(roleIdToSelect)) {
        roleIdSel.options[i].selected = true;
        foundById = true;
        break;
      }
    }
    
    // Fall back to matching by slug (legacy role)
    if (!foundById) {
      var slugMap = {'sales':'dealer', 'field_agent':'dealer', 'collection':'dealer', 'field_accountant':'accountant'};
      var mappedSlug = slugMap[legacyRole] || legacyRole;
      for (var i = 0; i < roleIdSel.options.length; i++) {
        if (roleIdSel.options[i].dataset.slug === mappedSlug) {
          roleIdSel.options[i].selected = true;
          break;
        }
      }
    }
    
    // Update the hidden role field and type label
    updateRoleSlug(roleIdSel);
  } else if (roleSel) {
    // Fallback for legacy dropdown
    for (var i = 0; i < roleSel.options.length; i++)
      roleSel.options[i].selected = (roleSel.options[i].value === (d.role || 'sales'));
  }
  
  var m = document.getElementById('editRetailerModal');
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function updateRoleSlug(sel) {
  var opt = sel.options[sel.selectedIndex];
  var slug = opt.dataset.slug || 'dealer';
  var isStaff = opt.dataset.isStaff === '1';
  
  document.getElementById('edit_role').value = slug;
  
  var label = document.getElementById('edit_role_type_label');
  if (label) {
    label.innerHTML = isStaff ? '🏢 Staff' : '🏪 Retailer';
    label.style.color = isStaff ? '#166534' : '#1e40af';
  }
}

function closeEditModal() {
  document.getElementById('editRetailerModal').style.display = 'none';
  document.body.style.overflow = '';
}
function openDeleteModal(id) {
  var d = RETAILER_DATA[id] || {};
  document.getElementById('delete_retailer_id').value         = id;
  document.getElementById('delete_retailer_name').textContent = d.name || 'ID #' + id;
  var m = document.getElementById('deleteRetailerModal');
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeDeleteModal() {
  document.getElementById('deleteRetailerModal').style.display = 'none';
  document.body.style.overflow = '';
}
function openPermModal(id) {
  var d = RETAILER_DATA[id] || {};
  var mods = d.modules || [];
  document.getElementById('perm_retailer_id').value = id;
  document.getElementById('permModalTitle').textContent = 'Module Access — ' + (d.name || '#' + id);
  document.querySelectorAll('#permForm input[type=checkbox]').forEach(function(cb) {
    cb.checked = mods.length > 0 && mods.indexOf(cb.value) !== -1;
  });
  var hint = document.getElementById('permDefaultHint');
  if (hint) hint.style.display = (mods.length === 0) ? 'block' : 'none';
  document.getElementById('permModal').style.display = 'flex';
}
function closePermModal() {
  document.getElementById('permModal').style.display = 'none';
}
function permSelectGroup(g, checked) {
  document.querySelectorAll('#permForm input[data-group="' + g + '"]').forEach(function(cb){ cb.checked = checked; });
}
function permSelectAll(checked) {
  document.querySelectorAll('#permForm input[type=checkbox]').forEach(function(cb){ cb.checked = checked; });
  var hint = document.getElementById('permDefaultHint');
  if (hint) hint.style.display = 'none';
}
function togglePw(inputId, btn) {
  var inp = document.getElementById(inputId);
  if (inp.type === 'password') { inp.type = 'text';     btn.textContent = '🙈'; }
  else                         { inp.type = 'password'; btn.textContent = '👁'; }
}
function crToggleComm(val) {
  var s = document.getElementById('crCommSection');
  if (s) s.style.display = val === '0' ? 'block' : 'none';
}
// Close modals on backdrop click
['editRetailerModal','deleteRetailerModal','permModal'].forEach(function(id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('click', function(e) {
    if (e.target === this) { this.style.display = 'none'; document.body.style.overflow = ''; }
  });
});
</script>

<!-- ════════════════════════════════════════════════════════════════════════
     CRM ORG-7 ACCOUNTS PANEL
     ════════════════════════════════════════════════════════════════════ -->
<div style="margin-top:28px;">
<div class="rt-section-hdr">
  <div class="rt-section-title">
    🔗 CRM Org-<?= $org7OrgId ?> Accounts
    <span style="font-size:12px;font-weight:400;color:#888;">Organization <?= $org7OrgId ?> — FTTH Project / Retailer Accounts</span>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php if ($org7FetchAt): ?><span style="font-size:11px;color:#aaa;">Pulled <?= h($org7FetchAt) ?></span><?php endif; ?>
    <?php if ($crm->isConfigured()): ?>
    <form method="POST" style="margin:0;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Syncing…';">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="sync_org7_retailers">
      <button type="submit" class="rt-btn rt-btn-green">🔄 Sync Now</button>
    </form>
    <button onclick="document.getElementById('walletSyncPanel').style.display=document.getElementById('walletSyncPanel').style.display==='none'?'block':'none'"
      class="rt-btn" style="background:#4A148C;color:#fff;border:none;font-size:12px;padding:8px 14px;border-radius:9px;font-weight:700;cursor:pointer;">
      💰 Sync Wallets
    </button>
    <?php else: ?>
    <a href="?page=dashboard&tab=settings" class="rt-btn rt-btn-outline">⚙ Configure CRM</a>
    <?php endif; ?>
  </div>
</div>

<!-- CRM Balance Sync Panel -->
<?php $_hasWalletReport = !empty($_SESSION['org7_wallet_report']); ?>
<div id="walletSyncPanel" style="display:<?= $_hasWalletReport ? 'block' : 'none' ?>;background:#F3E5F5;border:1.5px solid #CE93D8;border-radius:12px;padding:16px 18px;margin-bottom:16px;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;margin-bottom:6px;">
    <strong style="color:#4A148C;font-size:13px;">📊 CRM Outstanding Balance Sync</strong>
    <?php
      $_wsLast = file_exists($dataDir.'/wallet_sync_last_run.txt')
          ? date('M j, H:i', (int)file_get_contents($dataDir.'/wallet_sync_last_run.txt'))
          : null;
    ?>
    <span style="font-size:11px;color:#888;">
      <?php if ($_wsLast): ?>
        ✅ Auto-synced: <?= h($_wsLast) ?>
      <?php else: ?>
        ⚠ Not yet auto-synced
      <?php endif; ?>
    </span>
  </div>
  <p style="font-size:12px;color:#6A1B9A;margin:6px 0 10px;">
    Reads each retailer's <strong>CRM Org-<?= $org7OrgId ?> account balance</strong> and writes it directly to their <strong>plugin wallet</strong>.<br>
    CRM <code>-$8,595</code> → wallet becomes <code>$8,595</code> (outstanding debt DishNet must recover).<br>
    <strong>Apply updates wallet immediately.</strong> Auto-sync runs via UCRM's main.php schedule — no server setup needed.
  </p>
  <?php
  $walletReport = $_SESSION['org7_wallet_report'] ?? null;
  unset($_SESSION['org7_wallet_report']);
  if ($walletReport):
    $debtRows   = array_filter($walletReport, fn($r) => ($r['owes'] ?? false) && !($r['error'] ?? false));
    $clearRows  = array_filter($walletReport, fn($r) => !($r['owes'] ?? false) && !($r['error'] ?? false));
    $errorRows  = array_filter($walletReport, fn($r) => ($r['error'] ?? false));
    $totalOwed  = array_sum(array_column($debtRows, 'owes_amt'));
  ?>

  <?php if (!empty($debtRows)): ?>
  <!-- Debtors summary pill -->
  <div style="background:#FFEBEE;border:1px solid #FFCDD2;border-radius:8px;padding:10px 14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
    <span style="font-size:13px;font-weight:700;color:#c0392b;">⚠ <?= count($debtRows) ?> retailer<?= count($debtRows)>1?'s':'' ?> have unpaid invoices</span>
    <span style="font-size:16px;font-weight:800;color:#c0392b;">$<?= number_format($totalOwed,2) ?> total</span>
  </div>
  <?php endif; ?>

  <div style="background:#fff;border-radius:10px;overflow:hidden;margin-bottom:10px;border:1px solid #E1BEE7;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead>
        <tr style="background:#EDE7F6;">
          <th style="padding:8px 12px;text-align:left;">Retailer</th>
          <th style="padding:8px 12px;text-align:right;">CRM #</th>
          <th style="padding:8px 12px;text-align:right;">CRM Balance</th>
          <th style="padding:8px 12px;text-align:right;">Owes DishNet</th>
          <th style="padding:8px 12px;text-align:right;">Previously Saved</th>
          <th style="padding:8px 12px;text-align:center;">Change?</th>
          <th style="padding:8px 12px;text-align:center;">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($walletReport as $wr): ?>
      <?php
        $isErr  = $wr['error'] ?? false;
        $owes   = !$isErr && ($wr['owes'] ?? false);
        $rowBg  = $isErr ? '#fff8e1' : ($owes ? '#FFF3E0' : '#F9FBE7');
      ?>
      <?php $changed = !$isErr && ($wr['changed'] ?? false); ?>
      <tr style="border-top:1px solid #F3E5F5;background:<?= $rowBg ?>;">
        <td style="padding:7px 12px;font-weight:600;"><?= h($wr['name']) ?></td>
        <td style="padding:7px 12px;text-align:right;font-family:monospace;color:#7B1FA2;"><?= $isErr ? '?' : '#'.(int)$wr['crm_id'] ?></td>
        <td style="padding:7px 12px;text-align:right;font-weight:700;color:<?= $isErr?'#aaa':($wr['crm_bal']<0?'#c0392b':'#2E7D32') ?>;">
          <?= $isErr ? '— fetch failed' : ('$'.number_format($wr['crm_bal'],2)) ?>
        </td>
        <td style="padding:7px 12px;text-align:right;font-weight:800;color:<?= $owes?'#c0392b':'#aaa' ?>;">
          <?= $owes ? '$'.number_format($wr['owes_amt'],2) : '—' ?>
        </td>
        <td style="padding:7px 12px;text-align:right;color:#888;font-size:11px;">
          $<?= number_format($wr['prev_debt'] ?? 0, 2) ?>
        </td>
        <td style="padding:7px 12px;text-align:center;font-size:11px;font-weight:700;">
          <?php if ($isErr): ?>
            <span style="color:#F57F17;">—</span>
          <?php elseif ($changed): ?>
            <span style="color:#c0392b;">⬆ YES</span>
          <?php else: ?>
            <span style="color:#aaa;">—</span>
          <?php endif; ?>
        </td>
        <td style="padding:7px 12px;text-align:center;font-size:11px;font-weight:700;">
          <?php if ($isErr): ?>
            <span style="color:#F57F17;">⚠ Error</span>
          <?php elseif ($owes): ?>
            <span style="color:#c0392b;">🔴 Unpaid</span>
          <?php else: ?>
            <span style="color:#2E7D32;">✅ Clear</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#EDE7F6;font-weight:700;font-size:11px;">
          <td colspan="3" style="padding:8px 12px;">
            <?= count($debtRows) ?> unpaid &nbsp;·&nbsp; <?= count($clearRows) ?> clear &nbsp;·&nbsp; <?= count($errorRows) ?> errors
            &nbsp;·&nbsp; <?= count(array_filter($walletReport, fn($x) => $x['changed']??false)) ?> changed
          </td>
          <td style="padding:8px 12px;text-align:right;color:#c0392b;font-size:13px;">$<?= number_format($totalOwed??0,2) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Apply button -->
  <?php
    $nChanged = count(array_filter($walletReport, fn($x) => ($x['changed']??false) && !($x['error']??false)));
  ?>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
    <form method="POST" onsubmit="return confirm('Apply CRM balances to <?= $nChanged ?> retailer(s)? This saves their outstanding debt amount — wallet float is NOT affected.');">
      <?= csrfField() ?>
      <input type="hidden" name="action"    value="sync_org7_wallets">
      <input type="hidden" name="sync_mode" value="apply">
      <button type="submit"
        style="background:#4A148C;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-weight:700;font-size:13px;cursor:pointer;<?= $nChanged===0?'opacity:.45;pointer-events:none;':'' ?>">
        ✅ Apply<?= $nChanged > 0 ? " — Update {$nChanged} Retailer".($nChanged>1?'s':'') : ' (no changes)' ?>
      </button>
    </form>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action"    value="sync_org7_wallets">
      <input type="hidden" name="sync_mode" value="preview">
      <button type="submit" style="background:#7B1FA2;color:#fff;border:none;border-radius:8px;padding:10px 16px;font-weight:600;font-size:12px;cursor:pointer;">
        🔄 Re-check
      </button>
    </form>
  </div>
  <?php if (!empty($debtRows)): ?>
  <div style="font-size:12px;color:#6A1B9A;background:#EDE7F6;border-radius:8px;padding:10px 14px;">
    💡 After applying, the accountant's <strong>Outstanding Debts</strong> tab will reflect these amounts.
    Wallet float is untouched — it stays as the agent's credit balance for registering customers.
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- No preview yet — show the Preview button -->
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action"    value="sync_org7_wallets">
    <input type="hidden" name="sync_mode" value="preview">
    <button type="submit" style="background:#7B1FA2;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-weight:700;font-size:13px;cursor:pointer;">
      🔍 Preview CRM Balances
    </button>
    <span style="font-size:11px;color:#888;margin-left:10px;">See what will change before applying</span>
  </form>
  <?php endif; ?>
</div>

<!-- Status pills -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
  <span style="background:#E8F5E9;color:#2E7D32;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;">✅ <?= $syncStatus['synced'] ?> linked</span>
  <?php if ($syncStatus['unsynced'] > 0): ?>
  <span style="background:#FFF3E0;color:#BF360C;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;">⚠ <?= $syncStatus['unsynced'] ?> not linked</span>
  <?php endif; ?>
  <span style="background:#fff5f5;color:#D41C1C;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;">🏢 <?= count($org7Clients) ?> CRM clients pulled</span>
  <?php if (count($unlinkedCrm) > 0): ?>
  <span style="background:#E8EAF6;color:#283593;border-radius:20px;padding:4px 12px;font-size:12px;font-weight:700;">📥 <?= count($unlinkedCrm) ?> importable</span>
  <?php endif; ?>
</div>

<?php if (empty($org7Clients)): ?>
<div style="background:#fff;border:1.5px solid #e8ecf0;border-radius:14px;padding:48px;text-align:center;color:#bbb;">
  <?php if (!$crm->isConfigured()): ?>
  <div style="font-size:36px;margin-bottom:10px;">⚙️</div>
  <strong style="color:#999;">CRM not configured</strong><br>
  <a href="?page=dashboard&tab=settings" style="color:#E65100;font-size:13px;">Go to Settings →</a>
  <?php else: ?>
  <div style="font-size:36px;margin-bottom:10px;">📭</div>
  <strong style="color:#999;">No CRM clients cached yet</strong><br>
  <span style="font-size:13px;">Click <strong>Sync Now</strong> above to pull Org-<?= $org7OrgId ?> accounts</span>
  <?php endif; ?>
</div>
<?php else: ?>

<!-- Toolbar -->
<div class="rt-toolbar" style="margin-bottom:12px;">
  <div class="rt-search">
    <span class="si bi bi-search"></span>
    <input type="text" id="org7Search" placeholder="Search CRM clients…" oninput="filterOrg7(this.value)">
  </div>
  <select id="org7StatusFilter" class="rt-filter" onchange="filterOrg7Combo()">
    <option value="">All Status</option>
    <option value="active">Active</option>
    <option value="inactive">Inactive</option>
  </select>
  <select id="org7LinkFilter" class="rt-filter" onchange="filterOrg7Combo()">
    <option value="">All Link Status</option>
    <option value="linked">Linked</option>
    <option value="unlinked">Not Linked</option>
    <option value="match">Email Match</option>
  </select>
  <span id="org7Count" style="font-size:12px;color:#888;margin-left:auto;"></span>
</div>

<!-- Table -->
<div style="background:#fff;border:1.5px solid #e8ecf0;border-radius:14px;overflow:hidden;">
<div style="overflow-x:auto;">
<table class="kyc-table" id="org7Table" style="margin:0;">
  <thead>
    <tr style="background:#F8F9FA;">
      <th style="width:70px;">CRM ID</th>
      <th>Name</th>
      <th>Email / Phone</th>
      <th style="width:100px;">Balance</th>
      <th style="width:80px;">Status</th>
      <th>Plugin Account</th>
      <th style="width:140px;text-align:right;">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($org7Clients as $c7):
    $isLinked   = !empty($c7['linked_plugin_id']);
    $crmUrl     = h(rtrim(preg_replace('#(/crm)?/api/v[^/]*/?$#','',rtrim($config['crm_base_url']??'https://crm.dishnetafrica.com','/')), '/').'/crm/client/' . $c7['id']);
    $matchKey   = strtolower($c7['email'] ?? '');
    $emailMatch = $matchKey ? ($pluginByEmail[$matchKey] ?? null) : null;
    $linkStatus = $isLinked ? 'linked' : ($emailMatch ? 'match' : 'unlinked');
    $statusStr  = (!($c7['is_active'] ?? true)) ? 'inactive' : ($c7['is_lead'] ?? false ? 'lead' : 'active');
    $c7js = json_encode([
      'id'=>(int)$c7['id'],'name'=>$c7['name'],'email'=>$c7['email'],'phone'=>$c7['phone'],
      'match_id'=>$emailMatch?(int)$emailMatch['id']:null,'match_name'=>$emailMatch?$emailMatch['name']:null,
    ], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT);
  ?>
  <tr data-search="<?= h(strtolower($c7['name'].' '.$c7['email'].' '.$c7['phone'].' '.$c7['id'].' '.($c7['linked_plugin_name']??''))) ?>"
      data-status="<?= $statusStr ?>" data-link="<?= $linkStatus ?>">
    <td>
      <a href="<?= $crmUrl ?>" target="_blank" style="font-weight:700;color:#1B5E20;text-decoration:none;font-family:monospace;font-size:12px;">#<?= (int)$c7['id'] ?></a>
    </td>
    <td>
      <strong style="font-size:13px;"><?= h($c7['name'] ?: $c7['company']) ?></strong>
      <?php if ($c7['company'] && $c7['name']): ?><div style="font-size:11px;color:#888;"><?= h($c7['company']) ?></div><?php endif; ?>
    </td>
    <td style="font-size:12px;">
      <?php if ($c7['email']): ?><div><?= h($c7['email']) ?></div><?php endif; ?>
      <?php if ($c7['phone']): ?><div style="color:#888;"><?= h($c7['phone']) ?></div><?php endif; ?>
    </td>
    <td>
      <strong style="font-size:13px;color:<?= $c7['balance'] < 0 ? '#c0392b' : ($c7['balance'] > 0 ? '#1B5E20' : '#555') ?>;">
        $<?= number_format($c7['balance'], 2) ?>
      </strong>
    </td>
    <td>
      <?php if ($statusStr === 'inactive'): ?>
      <span class="rt-badge" style="background:#FFEBEE;color:#B71C1C;">Inactive</span>
      <?php elseif ($statusStr === 'lead'): ?>
      <span class="rt-badge" style="background:#FFF3E0;color:#E65100;">Lead</span>
      <?php else: ?>
      <span class="rt-badge" style="background:#E8F5E9;color:#1B5E20;">Active</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($isLinked):
        $lp = $pluginById[(int)$c7['linked_plugin_id']] ?? null;
        $lrc = $roleColors[$lp['role'] ?? 'sales'] ?? $roleColors['sales'];
      ?>
      <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
        <span style="font-size:11px;font-weight:700;color:#1B5E20;">✅ #<?= (int)$c7['linked_plugin_id'] ?></span>
        <strong style="font-size:12px;"><?= h($c7['linked_plugin_name']) ?></strong>
        <?php if ($lp): ?>
        <span class="rt-badge" style="background:<?= $lrc['bg'] ?>;color:<?= $lrc['color'] ?>;"><?= h($lp['role'] ?? 'sales') ?><?= $lp['is_admin']?' 🔑':'' ?></span>
        <?php endif; ?>
      </div>
      <?php elseif ($emailMatch): ?>
      <div>
        <span class="rt-badge" style="background:#FFF3E0;color:#E65100;">📧 Email match → #<?= (int)$emailMatch['id'] ?> <?= h($emailMatch['name']) ?></span>
        <div style="font-size:10px;color:#aaa;margin-top:2px;">Not formally linked — click Link</div>
      </div>
      <?php else: ?>
      <span style="color:#ccc;font-size:12px;font-style:italic;">No plugin account</span>
      <?php endif; ?>
    </td>
    <td style="text-align:right;">
      <div style="display:flex;gap:4px;justify-content:flex-end;align-items:center;">
      <?php if (!$isLinked): ?>
        <?php if ($emailMatch): ?>
        <button onclick="openLinkModal(<?= $c7js ?>)" class="rt-btn" style="background:#FF8F00;color:#fff;border:none;font-size:11px;padding:4px 9px;border-radius:6px;font-weight:700;cursor:pointer;">🔗 Link</button>
        <?php endif; ?>
        <button onclick="openImportModal(<?= $c7js ?>)" class="rt-btn" style="background:#D41C1C;color:#fff;border:none;font-size:11px;padding:4px 9px;border-radius:6px;font-weight:700;cursor:pointer;">＋ Import</button>
      <?php else: ?>
        <button onclick="openRoleModal(<?= (int)$c7['linked_plugin_id'] ?>, '<?= h(addslashes($c7['linked_plugin_name']??'')) ?>')" class="rt-btn" style="background:#7B1FA2;color:#fff;border:none;font-size:11px;padding:4px 9px;border-radius:6px;font-weight:700;cursor:pointer;">✏ Role</button>
      <?php endif; ?>
      <a href="<?= $crmUrl ?>" target="_blank" class="rt-btn" style="background:#E8F5E9;color:#1B5E20;border:none;font-size:11px;padding:4px 9px;border-radius:6px;font-weight:600;text-decoration:none;">CRM ↗</a>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>

<!-- Org7 Pagination -->
<div id="org7PaginationWrap" class="rt-pagination" style="margin-top:12px;"></div>

<?php endif; ?>

<!-- Bulk Import bar -->
<?php if (count($unlinkedCrm) > 0): ?>
<div style="background:#E8EAF6;border:1.5px solid #9FA8DA;border-radius:12px;padding:14px 18px;margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
  <div style="flex:1;min-width:180px;">
    <strong style="color:#283593;font-size:13px;">📥 <?= count($unlinkedCrm) ?> CRM client<?= count($unlinkedCrm)>1?'s':'' ?> not yet in plugin</strong>
    <div style="font-size:11px;color:#5C6BC0;margin-top:2px;">Import all at once, or use individual buttons above</div>
  </div>
  <form method="POST" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" onsubmit="return confirm('Import <?= count($unlinkedCrm) ?> CRM clients? Each gets a temp password.');">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_import_org7">
    <select name="default_role" style="padding:7px 10px;border:1.5px solid #9FA8DA;border-radius:7px;font-size:13px;font-weight:600;background:#fff;">
      <option value="sales">Sales / Retailer</option>
      <option value="field_agent">Field Agent</option>
      <option value="field_accountant">🧾 Field Accountant</option>
      <option value="accountant">Accountant</option>
      <option value="support">Support Engineer</option>
      <option value="admin">Admin</option>
    </select>
    <label style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px;cursor:pointer;white-space:nowrap;">
      <input type="checkbox" name="default_is_admin"> Admin flag
    </label>
    <button type="submit" class="rt-btn rt-btn-secondary">📥 Import All (<?= count($unlinkedCrm) ?>)</button>
  </form>
</div>
<?php endif; ?>
</div><!-- end CRM section -->

<!-- ══ Import Modal ══════════════════════════════════════════════════════ -->
<div id="org7ImportModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:480px;margin:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#D41C1C,#A81515);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
      <strong style="color:#fff;font-size:15px;">＋ Import from CRM</strong>
      <button onclick="closeImportModal()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:22px;">
      <div id="importCrmInfo" style="background:#E3F2FD;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;"></div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action"    value="import_org7_client">
        <input type="hidden" name="mode"      value="create">
        <input type="hidden" name="crm_id"    id="import_crm_id">
        <input type="hidden" name="crm_name"  id="import_crm_name">
        <input type="hidden" name="crm_email" id="import_crm_email_h">
        <input type="hidden" name="crm_phone" id="import_crm_phone_h">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div>
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Plugin Role *</label>
            <select name="role" id="import_role" style="width:100%;padding:9px;border:1.5px solid #D41C1C;border-radius:8px;font-size:13px;font-weight:600;">
              <option value="sales">Sales / Retailer</option>
              <option value="field_agent">Field Agent</option>
              <option value="accountant">Accountant</option>
              <option value="support">Support Engineer</option>
              <option value="support_leader">Support Leader</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Admin Access</label>
            <label style="display:flex;align-items:center;gap:8px;padding:9px 0;cursor:pointer;">
              <input type="checkbox" name="is_admin" id="import_is_admin" style="width:16px;height:16px;">
              <span style="font-size:13px;">Grant admin flag</span>
            </label>
          </div>
        </div>
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Plugin Password *</label>
          <input type="text" name="password" id="import_password" style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:monospace;">
          <div style="font-size:11px;color:#888;margin-top:4px;">⚠ Plugin-only password — separate from UCRM. Share securely.</div>
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" style="flex:1;background:#D41C1C;color:#fff;border:none;border-radius:8px;padding:11px;font-weight:700;font-size:14px;cursor:pointer;">＋ Create Account</button>
          <button type="button" onclick="closeImportModal()" style="background:#f1f5f9;color:#475569;border:none;border-radius:8px;padding:11px 18px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ Link Modal ════════════════════════════════════════════════════════ -->
<div id="org7LinkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:420px;margin:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#E65100,#F57C00);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
      <strong style="color:#fff;font-size:15px;">🔗 Link to Plugin Account</strong>
      <button onclick="closeLinkModal()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:22px;">
      <div id="linkCrmInfo" style="background:#FFF3E0;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;"></div>
      <p style="font-size:13px;color:#555;margin-bottom:16px;">Links the CRM client to the matching plugin account. No new account is created.</p>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action"            value="import_org7_client">
        <input type="hidden" name="mode"              value="link">
        <input type="hidden" name="crm_id"            id="link_crm_id">
        <input type="hidden" name="link_to_plugin_id" id="link_plugin_id">
        <input type="hidden" name="crm_name"          id="link_crm_name">
        <div style="display:flex;gap:10px;">
          <button type="submit" style="flex:1;background:#E65100;color:#fff;border:none;border-radius:8px;padding:11px;font-weight:700;font-size:14px;cursor:pointer;">🔗 Confirm Link</button>
          <button type="button" onclick="closeLinkModal()" style="background:#f1f5f9;color:#475569;border:none;border-radius:8px;padding:11px 18px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ Role Modal ════════════════════════════════════════════════════════ -->
<div id="org7RoleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:400px;margin:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);overflow:hidden;">
    <div style="background:linear-gradient(135deg,#4A148C,#7B1FA2);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
      <strong style="color:#fff;font-size:15px;" id="roleModalTitle">✏ Change Role</strong>
      <button onclick="closeRoleModal()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:22px;">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action"      value="edit_retailer">
        <input type="hidden" name="retailer_id" id="role_retailer_id">
        <div style="margin-bottom:14px;">
          <label style="font-size:12px;font-weight:700;display:block;margin-bottom:6px;">New Role</label>
          <select name="role" id="role_select" style="width:100%;padding:10px;border:1.5px solid #7B1FA2;border-radius:8px;font-size:14px;font-weight:600;">
            <option value="sales">Sales / Retailer</option>
            <option value="employee">👔 Employee (DishNet Staff)</option>
            <option value="field_agent">Field Agent</option>
            <option value="field_accountant">🧾 Field Accountant</option>
            <option value="accountant">Accountant</option>
            <option value="support">Support Engineer</option>
            <option value="support_leader">Support Leader</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div style="margin-bottom:16px;">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
            <input type="checkbox" name="is_admin" id="role_is_admin" style="width:16px;height:16px;">
            <span>Grant admin access (full dashboard)</span>
          </label>
        </div>
        <div style="display:flex;gap:10px;">
          <button type="submit" style="flex:1;background:#7B1FA2;color:#fff;border:none;border-radius:8px;padding:11px;font-weight:700;font-size:14px;cursor:pointer;">✏ Save Role</button>
          <button type="button" onclick="closeRoleModal()" style="background:#f1f5f9;color:#475569;border:none;border-radius:8px;padding:11px 18px;font-weight:600;cursor:pointer;">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Drawer toggle ─────────────────────────────────────────────────────────
function toggleDrawer(id) {
  var body  = document.getElementById(id + 'Body');
  var arrow = document.getElementById(id + 'Arrow');
  var open  = body.classList.toggle('open');
  if (arrow) arrow.style.transform = open ? 'rotate(180deg)' : '';
}

// ── CRM Staff search ──────────────────────────────────────────────────────
function searchCrmStaff() {
  var q = document.getElementById('crmStaffSearch').value.trim();
  if (!q || q.length < 2) return;
  var box = document.getElementById('crmStaffResults');
  box.innerHTML = '<div style="text-align:center;padding:14px;color:#888;font-size:12px;">Searching CRM…</div>';
  fetch('?page=api&action=crm_search_customer&q=' + encodeURIComponent(q), {
    headers: {'Authorization':'Bearer <?= h($retailer['api_token'] ?? "") ?>'}
  }).then(function(r){return r.json();}).then(function(d) {
    if (d.status !== 'success' || !d.data || !d.data.length) {
      box.innerHTML = '<div style="text-align:center;padding:14px;color:#bbb;font-size:12px;">No results</div>'; return;
    }
    var html = '';
    d.data.forEach(function(c) {
      var name  = ((c.firstName||'') + ' ' + (c.lastName||'')).trim();
      var email = (c.contacts && c.contacts[0]) ? (c.contacts[0].email||'') : '';
      var phone = (c.contacts && c.contacts[0]) ? (c.contacts[0].phone||'') : '';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;padding:9px 12px;border-bottom:1px solid #f5f5f5;">';
      html += '<div><strong style="font-size:13px;">' + escHtml(name) + '</strong>';
      html += '<div style="font-size:11px;color:#888;">CRM #' + c.id + (email?' · '+escHtml(email):'') + '</div></div>';
      html += '<form method="POST" style="display:flex;gap:5px;align-items:center;" onsubmit="return confirm(\'Import '+escHtml(name)+' as staff?\');">';
      html += '<input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">';
      html += '<input type="hidden" name="action" value="import_crm_staff">';
      html += '<input type="hidden" name="crm_name" value="' + escAttr(name) + '">';
      html += '<input type="hidden" name="crm_email" value="' + escAttr(email) + '">';
      html += '<input type="hidden" name="crm_phone" value="' + escAttr(phone) + '">';
      html += '<input type="hidden" name="crm_id" value="' + escAttr(String(c.id)) + '">';
      html += '<select name="import_role" style="font-size:11px;padding:4px 6px;border:1.5px solid #e2e8f0;border-radius:6px;">';
      html += '<option value="sales">Sales</option><option value="field_agent">Field Agent</option><option value="support">Support</option><option value="accountant">Accountant</option><option value="admin">Admin</option></select>';
      html += '<button type="submit" style="background:#2E7D32;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;">Import</button>';
      html += '</form></div>';
    });
    box.innerHTML = html;
  }).catch(function() { box.innerHTML = '<div style="color:#dc3545;font-size:12px;padding:10px;">CRM search failed</div>'; });
}
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

// ── Org7 table filter + pagination ────────────────────────────────────────
(function() {
  var PER_PAGE = 15, page = 1, rows = [];

  function getRows() { return Array.from(document.querySelectorAll('#org7Table tbody tr')); }

  function applyFilters() {
    var q    = (document.getElementById('org7Search')?.value || '').toLowerCase().trim();
    var st   = document.getElementById('org7StatusFilter')?.value || '';
    var lk   = document.getElementById('org7LinkFilter')?.value || '';
    rows = getRows().filter(function(tr) {
      var hay  = tr.getAttribute('data-search') || '';
      var stat = tr.getAttribute('data-status')  || '';
      var link = tr.getAttribute('data-link')    || '';
      if (q  && hay.indexOf(q) === -1) return false;
      if (st && stat !== st) return false;
      if (lk && link !== lk) return false;
      return true;
    });
    page = 1;
    render();
  }

  function render() {
    var all   = getRows();
    var total = rows.length;
    var pages = Math.ceil(total / PER_PAGE) || 1;
    var vis   = new Set(rows.slice((page-1)*PER_PAGE, page*PER_PAGE));
    all.forEach(function(tr) { tr.style.display = vis.has(tr) ? '' : 'none'; });
    var cnt = document.getElementById('org7Count');
    if (cnt) cnt.textContent = total + ' clients';

    var wrap = document.getElementById('org7PaginationWrap');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (pages <= 1) return;

    function mkBtn(label, p, active) {
      var b = document.createElement('button');
      b.className = 'rt-page-btn' + (active?' active':'');
      b.innerHTML = label; b.disabled = active;
      b.onclick = function() { page = p; render(); };
      return b;
    }
    var info = document.createElement('span'); info.className='rt-page-info'; info.textContent='Page '+page+' of '+pages; wrap.appendChild(info);
    wrap.appendChild(mkBtn('‹', Math.max(1,page-1), page===1));
    var lo=Math.max(1,page-2), hi=Math.min(pages,page+2);
    for (var p2=lo; p2<=hi; p2++) wrap.appendChild(mkBtn(p2,p2,p2===page));
    wrap.appendChild(mkBtn('›', Math.min(pages,page+1), page===pages));
  }

  window.filterOrg7 = applyFilters;
  window.filterOrg7Combo = applyFilters;

  rows = getRows();
  render();
})();

// ── Import / Link / Role modals ───────────────────────────────────────────
function openImportModal(c7) {
  document.getElementById('import_crm_id').value      = c7.id;
  document.getElementById('import_crm_name').value    = c7.name;
  document.getElementById('import_crm_email_h').value = c7.email;
  document.getElementById('import_crm_phone_h').value = c7.phone;
  document.getElementById('import_password').value    = 'DishNet' + c7.id + '@2026';
  document.getElementById('import_is_admin').checked  = false;
  document.getElementById('import_role').value        = 'sales';
  document.getElementById('importCrmInfo').innerHTML  =
    '<strong>CRM #' + c7.id + '</strong> — ' + (c7.name||'(no name)') +
    (c7.email ? '<br>📧 ' + c7.email : '') + (c7.phone ? '  📞 ' + c7.phone : '');
  document.getElementById('org7ImportModal').style.display = 'flex';
}
function closeImportModal() { document.getElementById('org7ImportModal').style.display = 'none'; }
function openLinkModal(c7) {
  document.getElementById('link_crm_id').value    = c7.id;
  document.getElementById('link_crm_name').value  = c7.name;
  document.getElementById('link_plugin_id').value = c7.match_id || '';
  document.getElementById('linkCrmInfo').innerHTML =
    '<strong>CRM #' + c7.id + ' — ' + (c7.name||'') + '</strong>' +
    (c7.email ? '<br>Email: ' + c7.email : '') +
    '<br><br>→ <strong>Plugin #' + (c7.match_id||'?') + ' — ' + (c7.match_name||'?') + '</strong>';
  document.getElementById('org7LinkModal').style.display = 'flex';
}
function closeLinkModal() { document.getElementById('org7LinkModal').style.display = 'none'; }
function openRoleModal(pluginId, name) {
  var d = RETAILER_DATA[pluginId] || {};
  document.getElementById('role_retailer_id').value = pluginId;
  document.getElementById('roleModalTitle').textContent = '✏ Role — ' + (name || d.name || '#' + pluginId);
  document.getElementById('role_is_admin').checked = d.is_admin || false;
  var sel = document.getElementById('role_select');
  for (var i=0; i<sel.options.length; i++) sel.options[i].selected = sel.options[i].value===(d.role||'sales');
  document.getElementById('org7RoleModal').style.display = 'flex';
}
function closeRoleModal() { document.getElementById('org7RoleModal').style.display = 'none'; }
['org7ImportModal','org7LinkModal','org7RoleModal'].forEach(function(id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('click', function(e) { if(e.target===this) this.style.display='none'; });
});
</script>


<!-- ════════════════════════════════════════════════════════════════════════
     ADMIN TAB: WALLET ADMIN
     ════════════════════════════════════════════════════════════════════ -->
