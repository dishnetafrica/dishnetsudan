<?php
/**
 * Roles & Permissions Management
 * 
 * RBAC (Role-Based Access Control) administration interface.
 * Allows creating/editing roles with granular module permissions.
 * 
 * @package DishNet Hybrid Telecom
 * @since v4.8.56
 */

// ── Access check ─────────────────────────────────────────────────────────────
if (!$isAdmin) {
    echo '<div class="kyc-alert error">Access denied</div>';
    return;
}

// ── Ensure RBAC tables exist ─────────────────────────────────────────────────
try {
    $rbac->getAllRoles();
} catch (Throwable $e) {
    echo '<div class="kyc-alert warning">RBAC system initializing... Please refresh the page.</div>';
    return;
}

// ── Handle form submissions ──────────────────────────────────────────────────
$flashMsg = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['rbac_action'] ?? '';
    
    if ($action === 'create_role') {
        $name = trim($_POST['role_name'] ?? '');
        $description = trim($_POST['role_description'] ?? '');
        $isStaffVal = (int)($_POST['is_staff'] ?? 0);
        $color = $_POST['role_color'] ?? '#6b7280';
        $icon = $_POST['role_icon'] ?? '👤';
        $permissions = $_POST['permissions'] ?? [];
        
        if (empty($name)) {
            $flashMsg = 'Role name is required';
            $flashType = 'error';
        } else {
            $roleId = $rbac->createRole([
                'name' => $name,
                'description' => $description,
                'is_staff' => $isStaffVal,
                'color' => $color,
                'icon' => $icon,
                'permissions' => $permissions,
                'created_by' => $retailer['name'] ?? 'Admin',
            ]);
            
            if ($roleId) {
                $flashMsg = "Role '{$name}' created successfully!";
            } else {
                $flashMsg = 'Failed to create role';
                $flashType = 'error';
            }
        }
    }
    
    if ($action === 'update_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $name = trim($_POST['role_name'] ?? '');
        $description = trim($_POST['role_description'] ?? '');
        $isStaffVal = (int)($_POST['is_staff'] ?? 0);
        $color = $_POST['role_color'] ?? '#6b7280';
        $icon = $_POST['role_icon'] ?? '👤';
        $permissions = $_POST['permissions'] ?? [];
        
        if ($roleId && $name) {
            $success = $rbac->updateRole($roleId, [
                'name' => $name,
                'description' => $description,
                'is_staff' => $isStaffVal,
                'color' => $color,
                'icon' => $icon,
                'permissions' => $permissions,
            ]);
            
            if ($success) {
                $flashMsg = "Role '{$name}' updated successfully!";
            } else {
                $flashMsg = 'Failed to update role';
                $flashType = 'error';
            }
        }
    }
    
    if ($action === 'delete_role') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $role = $rbac->getRole($roleId);
        
        if ($role && !$role['is_system']) {
            $success = $rbac->deleteRole($roleId);
            if ($success) {
                $flashMsg = "Role '{$role['name']}' deleted!";
            } else {
                $flashMsg = 'Failed to delete role';
                $flashType = 'error';
            }
        } else {
            $flashMsg = 'Cannot delete system roles';
            $flashType = 'error';
        }
    }
}

// ── Get data ─────────────────────────────────────────────────────────────────
$allRoles = $rbac->getAllRoles(false);
$allPermissions = $rbac->getAllPermissions();
$moduleLabels = $rbac->getModuleLabels();
$userCounts = $rbac->getUserCountsByRole($store);

// ── View mode ────────────────────────────────────────────────────────────────
$editRoleId = (int)($_GET['edit'] ?? 0);
$editRole = $editRoleId ? $rbac->getRole($editRoleId) : null;
$editRolePerms = $editRole ? $rbac->getPermissions($editRoleId) : [];
$showCreate = isset($_GET['create']);

// ── Icon options ─────────────────────────────────────────────────────────────
$iconOptions = ['👤', '🛡️', '👑', '🎧', '💼', '🏪', '📊', '🔧', '📡', '💰', '🚀', '⭐'];
$colorOptions = ['#D41C1C', '#22c55e', '#3b82f6', '#7c3aed', '#8b5cf6', '#f59e0b', '#6b7280', '#ec4899', '#14b8a6'];
?>

<style>
/* ── Roles Management Styles ────────────────────────────────────────────────── */
.rb-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.rb-title { font-size:22px; font-weight:800; color:var(--c-text1); display:flex; align-items:center; gap:10px; }
.rb-title i { color:#D41C1C; }

.rb-btn {
    padding:10px 20px; border-radius:10px; font-size:13px; font-weight:700;
    border:none; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;
    transition:all .15s;
}
.rb-btn.primary { background:#D41C1C; color:#fff; }
.rb-btn.primary:hover { background:#b91c1c; }
.rb-btn.secondary { background:var(--c-card); color:var(--c-text1); border:1px solid var(--c-border); }
.rb-btn.secondary:hover { background:var(--c-hover); }
.rb-btn.danger { background:#ef4444; color:#fff; }
.rb-btn.danger:hover { background:#dc2626; }

.rb-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:16px; margin-bottom:24px; }

.rb-card {
    background:var(--c-card); border:1px solid var(--c-border); border-radius:14px;
    padding:20px; position:relative; transition:all .15s;
}
.rb-card:hover { border-color:#D41C1C; box-shadow:0 4px 12px rgba(0,0,0,.08); }
.rb-card.system { border-left:4px solid #f59e0b; }

.rb-card-header { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.rb-card-icon {
    width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center;
    font-size:24px;
}
.rb-card-title { font-size:16px; font-weight:800; color:var(--c-text1); }
.rb-card-slug { font-size:11px; color:var(--c-text3); font-family:monospace; }
.rb-card-badge {
    position:absolute; top:12px; right:12px; padding:3px 10px; border-radius:6px;
    font-size:10px; font-weight:700; text-transform:uppercase;
}
.rb-card-badge.system { background:#fef3c7; color:#92400e; }
.rb-card-badge.staff { background:#dcfce7; color:#166534; }
.rb-card-badge.retailer { background:#dbeafe; color:#1e40af; }

.rb-card-desc { font-size:13px; color:var(--c-text2); margin-bottom:12px; line-height:1.5; }

.rb-card-stats { display:flex; gap:16px; margin-bottom:16px; }
.rb-card-stat { text-align:center; }
.rb-card-stat-value { font-size:20px; font-weight:800; color:var(--c-text1); }
.rb-card-stat-label { font-size:10px; color:var(--c-text3); text-transform:uppercase; }

.rb-card-perms { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:16px; }
.rb-card-perm {
    padding:3px 8px; border-radius:4px; font-size:10px; font-weight:600;
    background:var(--c-bg); color:var(--c-text2);
}

.rb-card-actions { display:flex; gap:8px; }
.rb-card-actions .rb-btn { padding:8px 14px; font-size:12px; }

/* ── Form Styles ────────────────────────────────────────────────────────────── */
.rb-form { background:var(--c-card); border:1px solid var(--c-border); border-radius:14px; padding:24px; margin-bottom:24px; }
.rb-form-title { font-size:18px; font-weight:800; color:var(--c-text1); margin-bottom:20px; display:flex; align-items:center; gap:10px; }

.rb-form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px; margin-bottom:20px; }

.rb-field { margin-bottom:16px; }
.rb-field label { display:block; font-size:12px; font-weight:700; color:var(--c-text2); margin-bottom:6px; }
.rb-field input, .rb-field textarea, .rb-field select {
    width:100%; padding:10px 14px; border:1px solid var(--c-border); border-radius:8px;
    background:var(--c-bg); color:var(--c-text1); font-size:14px;
}
.rb-field input:focus, .rb-field textarea:focus, .rb-field select:focus {
    outline:none; border-color:#D41C1C;
}
.rb-field textarea { resize:vertical; min-height:80px; }
.rb-field-hint { font-size:11px; color:var(--c-text3); margin-top:4px; }

.rb-switch-row { display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--c-bg); border-radius:10px; margin-bottom:16px; }
.rb-switch-row label { font-size:14px; font-weight:600; color:var(--c-text1); flex:1; }
.rb-switch-row .switch { position:relative; width:48px; height:26px; }
.rb-switch-row .switch input { opacity:0; width:0; height:0; }
.rb-switch-row .slider {
    position:absolute; inset:0; background:#ccc; border-radius:26px; cursor:pointer; transition:.3s;
}
.rb-switch-row .slider:before {
    position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px;
    background:#fff; border-radius:50%; transition:.3s;
}
.rb-switch-row input:checked + .slider { background:#22c55e; }
.rb-switch-row input:checked + .slider:before { transform:translateX(22px); }

/* ── Permissions Grid ───────────────────────────────────────────────────────── */
.rb-perms-section { margin-bottom:24px; }
.rb-perms-title { font-size:14px; font-weight:800; color:var(--c-text1); margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.rb-perms-title span { font-size:18px; }

.rb-module-group { margin-bottom:20px; }
.rb-module-header {
    display:flex; align-items:center; gap:10px; padding:10px 14px;
    background:var(--c-bg); border-radius:10px 10px 0 0; border:1px solid var(--c-border); border-bottom:none;
}
.rb-module-icon { font-size:18px; }
.rb-module-name { font-weight:700; font-size:14px; color:var(--c-text1); flex:1; }
.rb-module-toggle { font-size:11px; color:#3b82f6; cursor:pointer; text-decoration:underline; }

.rb-perms-list {
    border:1px solid var(--c-border); border-radius:0 0 10px 10px; background:var(--c-card);
}
.rb-perm-item {
    display:flex; align-items:center; gap:12px; padding:10px 14px;
    border-bottom:1px solid var(--c-border);
}
.rb-perm-item:last-child { border-bottom:none; }
.rb-perm-item input[type="checkbox"] { width:18px; height:18px; accent-color:#D41C1C; cursor:pointer; }
.rb-perm-item label { flex:1; font-size:13px; color:var(--c-text1); cursor:pointer; }
.rb-perm-item .rb-perm-desc { font-size:11px; color:var(--c-text3); }

/* ── Icon/Color Picker ──────────────────────────────────────────────────────── */
.rb-picker { display:flex; flex-wrap:wrap; gap:8px; }
.rb-picker-item {
    width:40px; height:40px; border:2px solid var(--c-border); border-radius:10px;
    display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all .15s;
}
.rb-picker-item:hover { border-color:#D41C1C; }
.rb-picker-item.selected { border-color:#D41C1C; box-shadow:0 0 0 3px rgba(212,28,28,.2); }
.rb-picker-item.icon { font-size:20px; background:var(--c-bg); }
.rb-picker-item.color { border-radius:50%; }

/* ── Flash Messages ─────────────────────────────────────────────────────────── */
.rb-flash {
    padding:14px 20px; border-radius:10px; margin-bottom:20px; font-size:14px; font-weight:600;
    display:flex; align-items:center; gap:10px;
}
.rb-flash.success { background:#dcfce7; color:#166534; }
.rb-flash.error { background:#fee2e2; color:#991b1b; }

/* ── Responsive ─────────────────────────────────────────────────────────────── */
@media (max-width:768px) {
    .rb-grid { grid-template-columns:1fr; }
    .rb-form-grid { grid-template-columns:1fr; }
}
</style>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- HEADER                                                                      -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="rb-header">
    <div class="rb-title">
        <i class="bi bi-shield-lock-fill"></i>
        Roles & Permissions
    </div>
    
    <?php if (!$showCreate && !$editRole): ?>
    <a href="?page=dashboard&tab=roles&create=1" class="rb-btn primary">
        <i class="bi bi-plus-lg"></i> Create Role
    </a>
    <?php else: ?>
    <a href="?page=dashboard&tab=roles" class="rb-btn secondary">
        <i class="bi bi-arrow-left"></i> Back to List
    </a>
    <?php endif; ?>
</div>

<?php if ($flashMsg): ?>
<div class="rb-flash <?= $flashType ?>">
    <?= $flashType === 'success' ? '✅' : '❌' ?>
    <?= htmlspecialchars($flashMsg) ?>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- CREATE / EDIT FORM                                                          -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<?php if ($showCreate || $editRole): ?>
<form method="POST" class="rb-form">
    <?= csrfField() ?>
    <input type="hidden" name="rbac_action" value="<?= $editRole ? 'update_role' : 'create_role' ?>">
    <?php if ($editRole): ?>
    <input type="hidden" name="role_id" value="<?= $editRole['id'] ?>">
    <?php endif; ?>
    
    <div class="rb-form-title">
        <?= $editRole ? '✏️ Edit Role: ' . htmlspecialchars($editRole['name']) : '➕ Create New Role' ?>
        <?php if ($editRole && $editRole['is_system']): ?>
        <span style="background:#fef3c7;color:#92400e;padding:4px 12px;border-radius:6px;font-size:11px;font-weight:700;">SYSTEM ROLE</span>
        <?php endif; ?>
    </div>
    
    <div class="rb-form-grid">
        <div>
            <div class="rb-field">
                <label>Role Name *</label>
                <input type="text" name="role_name" value="<?= htmlspecialchars($editRole['name'] ?? '') ?>" required placeholder="e.g., Sales Staff">
            </div>
            
            <div class="rb-field">
                <label>Description</label>
                <textarea name="role_description" placeholder="Brief description of this role's responsibilities"><?= htmlspecialchars($editRole['description'] ?? '') ?></textarea>
            </div>
            
            <div class="rb-switch-row">
                <label>
                    <strong>🏢 Is Staff (Company Employee)?</strong><br>
                    <span style="font-size:12px;color:var(--c-text3);">Staff roles create cashbook entries when collecting cash from customers</span>
                </label>
                <label class="switch">
                    <input type="checkbox" name="is_staff" value="1" <?= ($editRole['is_staff'] ?? 0) ? 'checked' : '' ?>>
                    <span class="slider"></span>
                </label>
            </div>
        </div>
        
        <div>
            <div class="rb-field">
                <label>Icon</label>
                <div class="rb-picker">
                    <?php foreach ($iconOptions as $ico): ?>
                    <div class="rb-picker-item icon <?= ($editRole['icon'] ?? '👤') === $ico ? 'selected' : '' ?>" 
                         onclick="selectIcon(this, '<?= $ico ?>')">
                        <?= $ico ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="role_icon" id="roleIcon" value="<?= htmlspecialchars($editRole['icon'] ?? '👤') ?>">
            </div>
            
            <div class="rb-field">
                <label>Color</label>
                <div class="rb-picker">
                    <?php foreach ($colorOptions as $clr): ?>
                    <div class="rb-picker-item color <?= ($editRole['color'] ?? '#6b7280') === $clr ? 'selected' : '' ?>" 
                         style="background:<?= $clr ?>"
                         onclick="selectColor(this, '<?= $clr ?>')">
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="role_color" id="roleColor" value="<?= htmlspecialchars($editRole['color'] ?? '#6b7280') ?>">
            </div>
        </div>
    </div>
    
    <!-- Permissions Section -->
    <div class="rb-perms-section">
        <div class="rb-perms-title">
            <span>🔐</span> Permissions
        </div>
        
        <?php foreach ($allPermissions as $module => $perms): 
            $modMeta = $moduleLabels[$module] ?? ['label' => ucfirst($module), 'icon' => '📋', 'color' => '#6b7280'];
        ?>
        <div class="rb-module-group">
            <div class="rb-module-header">
                <span class="rb-module-icon"><?= $modMeta['icon'] ?></span>
                <span class="rb-module-name"><?= htmlspecialchars($modMeta['label']) ?></span>
                <span class="rb-module-toggle" onclick="toggleModule('<?= $module ?>')">Select All</span>
            </div>
            <div class="rb-perms-list" id="perms-<?= $module ?>">
                <?php foreach ($perms as $perm): 
                    $isChecked = isset($editRolePerms[$module][$perm['action']]);
                ?>
                <div class="rb-perm-item">
                    <input type="checkbox" name="permissions[<?= $module ?>][]" value="<?= htmlspecialchars($perm['action']) ?>" 
                           id="perm-<?= $perm['id'] ?>" <?= $isChecked ? 'checked' : '' ?>>
                    <label for="perm-<?= $perm['id'] ?>">
                        <?= htmlspecialchars($perm['label']) ?>
                        <?php if ($perm['description']): ?>
                        <div class="rb-perm-desc"><?= htmlspecialchars($perm['description']) ?></div>
                        <?php endif; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div style="display:flex;gap:12px;justify-content:flex-end;">
        <a href="?page=dashboard&tab=roles" class="rb-btn secondary">Cancel</a>
        <button type="submit" class="rb-btn primary">
            <?= $editRole ? 'Update Role' : 'Create Role' ?>
        </button>
    </div>
</form>

<?php else: ?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ROLES LIST                                                                  -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div class="rb-grid">
    <?php foreach ($allRoles as $role): 
        $rolePerms = $rbac->getPermissions($role['id']);
        $permCount = array_sum(array_map('count', $rolePerms));
        $userCount = $userCounts[$role['id']]['count'] ?? 0;
    ?>
    <div class="rb-card <?= $role['is_system'] ? 'system' : '' ?>">
        <?php if ($role['is_system']): ?>
        <span class="rb-card-badge system">System</span>
        <?php endif; ?>
        
        <span class="rb-card-badge <?= $role['is_staff'] ? 'staff' : 'retailer' ?>" style="top:<?= $role['is_system'] ? '38px' : '12px' ?>;">
            <?= $role['is_staff'] ? '🏢 Staff' : '🏪 Retailer' ?>
        </span>
        
        <div class="rb-card-header">
            <div class="rb-card-icon" style="background:<?= htmlspecialchars($role['color']) ?>20;color:<?= htmlspecialchars($role['color']) ?>;">
                <?= $role['icon'] ?>
            </div>
            <div>
                <div class="rb-card-title"><?= htmlspecialchars($role['name']) ?></div>
                <div class="rb-card-slug"><?= htmlspecialchars($role['slug']) ?></div>
            </div>
        </div>
        
        <div class="rb-card-desc">
            <?= htmlspecialchars($role['description'] ?: 'No description') ?>
        </div>
        
        <div class="rb-card-stats">
            <div class="rb-card-stat">
                <div class="rb-card-stat-value"><?= $userCount ?></div>
                <div class="rb-card-stat-label">Users</div>
            </div>
            <div class="rb-card-stat">
                <div class="rb-card-stat-value"><?= $permCount ?></div>
                <div class="rb-card-stat-label">Permissions</div>
            </div>
        </div>
        
        <div class="rb-card-perms">
            <?php 
            $displayModules = array_slice(array_keys($rolePerms), 0, 4);
            foreach ($displayModules as $mod): 
                $modMeta = $moduleLabels[$mod] ?? ['icon' => '📋'];
            ?>
            <span class="rb-card-perm"><?= $modMeta['icon'] ?> <?= ucfirst($mod) ?></span>
            <?php endforeach; ?>
            <?php if (count($rolePerms) > 4): ?>
            <span class="rb-card-perm">+<?= count($rolePerms) - 4 ?> more</span>
            <?php endif; ?>
        </div>
        
        <div class="rb-card-actions">
            <a href="?page=dashboard&tab=roles&edit=<?= $role['id'] ?>" class="rb-btn secondary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            <?php if (!$role['is_system']): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this role? Users with this role will need reassignment.')">
                <?= csrfField() ?>
                <input type="hidden" name="rbac_action" value="delete_role">
                <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                <button type="submit" class="rb-btn danger">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- EXPLANATION                                                                 -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div style="background:var(--c-card);border:1px solid var(--c-border);border-radius:14px;padding:20px;margin-top:24px;">
    <h3 style="font-size:16px;font-weight:800;color:var(--c-text1);margin:0 0 12px;display:flex;align-items:center;gap:8px;">
        💡 Understanding Staff vs Retailer
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">
        <div style="background:#dcfce7;border-radius:10px;padding:16px;">
            <div style="font-weight:700;color:#166534;margin-bottom:8px;">🏢 Staff (is_staff = Yes)</div>
            <ul style="margin:0;padding-left:20px;font-size:13px;color:#166534;line-height:1.6;">
                <li>Company employees (Justus, Aida, etc.)</li>
                <li>Wallet funded by company</li>
                <li><strong>Cash KYC creates cashbook entry</strong></li>
                <li>Must handover cash to accounts</li>
            </ul>
        </div>
        <div style="background:#dbeafe;border-radius:10px;padding:16px;">
            <div style="font-weight:700;color:#1e40af;margin-bottom:8px;">🏪 Retailer (is_staff = No)</div>
            <ul style="margin:0;padding-left:20px;font-size:13px;color:#1e40af;line-height:1.6;">
                <li>Independent dealers/shops</li>
                <li>Self-funded wallet (they paid DishNet)</li>
                <li><strong>No cashbook entry needed</strong></li>
                <li>Money already with company</li>
            </ul>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ROLE ASSIGNMENT GUIDE                                                       -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->

<div style="background:var(--c-card);border:1px solid var(--c-border);border-radius:14px;padding:20px;margin-top:16px;">
    <h3 style="font-size:16px;font-weight:800;color:var(--c-text1);margin:0 0 16px;display:flex;align-items:center;gap:8px;">
        📋 Role Assignment Guide
    </h3>
    
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="background:var(--c-bg);text-align:left;">
                <th style="padding:10px 12px;border-bottom:2px solid var(--c-border);font-weight:700;">Role</th>
                <th style="padding:10px 12px;border-bottom:2px solid var(--c-border);font-weight:700;">Type</th>
                <th style="padding:10px 12px;border-bottom:2px solid var(--c-border);font-weight:700;">Assign To</th>
                <th style="padding:10px 12px;border-bottom:2px solid var(--c-border);font-weight:700;">Cash Handling</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">🛡️ <strong>Admin</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">System administrators, owners</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">Creates cashbook</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">💼 <strong>Sales Staff</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><strong>Justus, Aida, Diko</strong> — DishNet employees who sell</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">✅ Creates cashbook → must handover</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">🏪 <strong>Dealer</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Retailer</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><strong>Bidal</strong> — Independent shop owners, resellers</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">❌ No cashbook (self-funded)</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">🚗 <strong>Field Agent</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">Field sales reps who visit customers</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">✅ Creates cashbook → must handover</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">💵 <strong>Collection Agent</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">Staff who collect payments only</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">✅ Creates cashbook → must handover</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">📋 <strong>Field Accountant</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">Finance staff in the field</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">✅ Creates cashbook → must handover</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">🎧 <strong>Support</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">Support engineers, installers</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">✅ Creates cashbook (if they collect)</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">👑 <strong>Support Leader</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">NOC supervisor, team leads</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">✅ Creates cashbook (if they collect)</td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">📊 <strong>Accountant</strong></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);"><span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;">Staff</span></td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">Finance team, accounts dept</td>
                <td style="padding:10px 12px;border-bottom:1px solid var(--c-border);">Full access to all cashbooks</td>
            </tr>
        </tbody>
    </table>
    
    <div style="margin-top:16px;padding:12px 16px;background:#fef3c7;border-radius:8px;border-left:4px solid #f59e0b;">
        <strong style="color:#92400e;">⚡ Quick Decision:</strong>
        <span style="color:#78350f;font-size:13px;">
            Does DishNet pay their salary? → <strong>Sales Staff</strong> &nbsp;|&nbsp; 
            Do they run their own shop? → <strong>Dealer</strong>
        </span>
    </div>
</div>

<?php endif; ?>

<script>
function selectIcon(el, icon) {
    document.querySelectorAll('.rb-picker-item.icon').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('roleIcon').value = icon;
}

function selectColor(el, color) {
    document.querySelectorAll('.rb-picker-item.color').forEach(e => e.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('roleColor').value = color;
}

function toggleModule(module) {
    const container = document.getElementById('perms-' + module);
    const checkboxes = container.querySelectorAll('input[type="checkbox"]');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    checkboxes.forEach(cb => cb.checked = !allChecked);
}
</script>
