<?php
// ── Cash Advances Tab — DishNet Hybrid v4.5 ──────────────────────────────────
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

require_once __DIR__ . '/../../lib/ExpenseAdvanceService.php';

$expAdv    = new ExpenseAdvanceService($store, $dataDir);
$isAcctMgr = $isAdmin || in_array($userRole ?? '', ['accountant','admin']);

// ── Gate: accountant or admin only ───────────────────────────────────────────
if (!$isAcctMgr) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>';
    return;
}

// ── POST: create advance ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['adv_action'] ?? '') === 'create') {
    $result = $expAdv->createAdvance($_POST, $retailer);
    if ($result['ok']) {
        $advNo = htmlspecialchars($result['advance_no']);
        echo "<script>window.location='?page=dashboard&tab=cash_advances&created={$advNo}';</script>";
    } else {
        $createError = htmlspecialchars($result['error'] ?? 'Unknown error');
    }
}

// ── POST: settle advance ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['adv_action'] ?? '') === 'settle') {
    $result = $expAdv->settleAdvance(
        (int)$_POST['advance_id'],
        (float)$_POST['return_amount'],
        trim($_POST['settle_note'] ?? ''),
        $retailer
    );
    $settleMsg = $result['ok'] ? 'Advance settled.' : ($result['error'] ?? 'Error');
    $settleOk  = $result['ok'];
}

// ── POST: cancel advance ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['adv_action'] ?? '') === 'cancel') {
    $expAdv->cancelAdvance((int)$_POST['advance_id'], $retailer);
}

// ── Filters ───────────────────────────────────────────────────────────────────
$fStatus  = in_array($_GET['adv_s'] ?? '', ['active','partial','settled','cancelled','']) ? ($_GET['adv_s'] ?? '') : '';
$fRecip   = (int)($_GET['adv_r'] ?? 0);
$fFrom    = $_GET['adv_from'] ?? '';
$fTo      = $_GET['adv_to']   ?? '';

$advances  = $expAdv->getAdvances(array_filter([
    'status'       => $fStatus,
    'recipient_id' => $fRecip ?: null,
    'date_from'    => $fFrom,
    'date_to'      => $fTo,
    'limit'        => 200,
]));
$overview  = $expAdv->getActiveStaffSummary();
$allStaff  = $store->load('retailers.json');

// ── Helper ────────────────────────────────────────────────────────────────────
function advStatusBadge(string $s): string {
    $map = [
        'active'    => ['🟢','#166534','#dcfce7'],
        'partial'   => ['🟡','#92400e','#fef9c3'],
        'settled'   => ['✅','#1e40af','#dbeafe'],
        'cancelled' => ['⛔','#6b7280','#f3f4f6'],
    ];
    [$ic,$tc,$bg] = $map[$s] ?? ['⚪','#374151','#f9fafb'];
    return "<span style=\"background:{$bg};color:{$tc};padding:2px 10px;border-radius:20px;font-size:11px;font-weight:800;\">{$ic} ".ucfirst($s)."</span>";
}
?>
<div style="padding:0 0 60px;">

<?php if (!empty($_GET['created'])): ?>
<div style="background:#dcfce7;border:1px solid #86efac;border-radius:12px;padding:14px 18px;margin:16px;font-size:14px;font-weight:700;color:#166534;">
  ✅ Advance <?= htmlspecialchars($_GET['created']) ?> created and posted to cashbook.
</div>
<?php endif; ?>

<?php if (isset($createError)): ?>
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:12px;padding:14px 18px;margin:16px;font-size:14px;color:#dc2626;">⚠️ <?= $createError ?></div>
<?php endif; ?>

<!-- ══ Header ══════════════════════════════════════════════════════════════ -->
<div style="padding:20px 20px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
  <div>
    <div style="font-size:22px;font-weight:900;color:#0f0f0f;">💸 Cash Advances</div>
    <div style="font-size:12px;color:#64748b;margin-top:2px;">Issue and track field cash advances</div>
  </div>
  <button onclick="document.getElementById('advCreateModal').style.display='flex'"
          style="background:#0f0f0f;color:#fff;border:none;border-radius:12px;padding:11px 22px;font-size:14px;font-weight:700;cursor:pointer;">
    ＋ New Advance
  </button>
</div>

<!-- ══ Overview cards ═══════════════════════════════════════════════════════ -->
<?php if ($overview): ?>
<div style="padding:16px 20px 0;">
  <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;">Live Staff Balances</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
    <?php foreach ($overview as $s): ?>
    <div style="background:#fff;border:1.5px solid <?= (float)$s['balance'] > 50 ? '#fde68a' : '#e2e8f0' ?>;border-radius:14px;padding:14px;">
      <div style="font-size:13px;font-weight:800;color:#0f0f0f;"><?= htmlspecialchars($s['staff_name']) ?></div>
      <div style="font-size:11px;color:#64748b;margin-top:2px;"><?= (int)$s['advance_count'] ?> advance<?= $s['advance_count'] != 1 ? 's' : '' ?></div>
      <div style="font-size:20px;font-weight:900;color:<?= (float)$s['balance'] > 0 ? '#d97706' : '#22c55e' ?>;margin-top:8px;">$<?= number_format((float)$s['balance'], 2) ?></div>
      <div style="font-size:10px;color:#94a3b8;margin-top:2px;">remaining / $<?= number_format((float)$s['total_advanced'], 2) ?> total</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ══ Filter bar ══════════════════════════════════════════════════════════ -->
<form method="GET" style="padding:16px 20px 0;display:flex;gap:8px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab"  value="cash_advances">
  <select name="adv_s" style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;background:#fff;">
    <option value="">All statuses</option>
    <?php foreach (['active','partial','settled','cancelled'] as $st): ?>
    <option value="<?= $st ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="adv_r" style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;background:#fff;">
    <option value="">All staff</option>
    <?php foreach ($allStaff as $s): ?>
    <option value="<?= (int)$s['id'] ?>" <?= $fRecip === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" name="adv_from" value="<?= h($fFrom) ?>" style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;">
  <input type="date" name="adv_to"   value="<?= h($fTo) ?>"   style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;">
  <button type="submit" style="background:#0f0f0f;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;">Filter</button>
</form>

<!-- ══ Advances table ══════════════════════════════════════════════════════ -->
<div style="padding:16px 20px;">
<?php if (empty($advances)): ?>
<div style="background:#f8f8f5;border-radius:14px;padding:40px;text-align:center;color:#94a3b8;font-size:14px;">
  No advances found.
</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;font-size:13px;">
  <thead>
    <tr style="background:#f8f8f5;border-radius:10px;">
      <th style="padding:10px 14px;text-align:left;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Advance No.</th>
      <th style="padding:10px 14px;text-align:left;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Recipient</th>
      <th style="padding:10px 14px;text-align:left;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Purpose</th>
      <th style="padding:10px 14px;text-align:right;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Amount</th>
      <th style="padding:10px 14px;text-align:right;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Spent</th>
      <th style="padding:10px 14px;text-align:right;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Balance</th>
      <th style="padding:10px 14px;text-align:left;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Status</th>
      <th style="padding:10px 14px;text-align:left;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Issued</th>
      <th style="padding:10px 14px;text-align:left;font-weight:800;color:#374151;border-bottom:2px solid #e5e5e0;">Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($advances as $adv): ?>
    <?php
      $balance   = (float)$adv['balance'];
      $overspent = $balance < -0.01;
      $rowBg     = $overspent ? '#fff7f7' : ($adv['status'] === 'settled' ? '#f8f8f5' : '#fff');
    ?>
    <tr style="background:<?= $rowBg ?>;border-bottom:1px solid #f1f1ee;">
      <td style="padding:10px 14px;font-weight:800;color:#0f0f0f;font-family:monospace;"><?= htmlspecialchars($adv['advance_no']) ?></td>
      <td style="padding:10px 14px;font-weight:600;"><?= htmlspecialchars($adv['recipient_name']) ?></td>
      <td style="padding:10px 14px;">
        <span style="background:#f1f5f9;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;text-transform:capitalize;"><?= htmlspecialchars($adv['purpose']) ?></span>
        <?php if ($adv['description']): ?>
        <div style="font-size:11px;color:#64748b;margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($adv['description'], 0, 40, '…')) ?></div>
        <?php endif; ?>
      </td>
      <td style="padding:10px 14px;text-align:right;font-weight:700;">$<?= number_format((float)$adv['amount'], 2) ?></td>
      <td style="padding:10px 14px;text-align:right;color:#dc2626;">$<?= number_format((float)$adv['amount_spent'], 2) ?></td>
      <td style="padding:10px 14px;text-align:right;font-weight:800;color:<?= $overspent ? '#dc2626' : ($balance > 0 ? '#d97706' : '#22c55e') ?>;">
        <?= $overspent ? '<span title="Overspent">⚠️ ' : '' ?>$<?= number_format(abs($balance), 2) ?><?= $overspent ? '</span>' : '' ?>
      </td>
      <td style="padding:10px 14px;"><?= advStatusBadge($adv['status']) ?></td>
      <td style="padding:10px 14px;color:#64748b;font-size:12px;"><?= date('d M', strtotime($adv['issued_at'])) ?><br><span style="color:#94a3b8;"><?= htmlspecialchars($adv['issued_by_name']) ?></span></td>
      <td style="padding:10px 14px;">
        <?php if (in_array($adv['status'], ['active','partial'])): ?>
        <button onclick="openSettle(<?= (int)$adv['id'] ?>, '<?= addslashes($adv['advance_no']) ?>', <?= (float)$balance ?>)"
                style="background:#f0fdf4;border:1px solid #86efac;color:#166534;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;margin-right:4px;">
          Settle
        </button>
        <?php if ((float)$adv['amount_spent'] == 0): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this advance?')">
          <?= csrfField() ?>
          <input type="hidden" name="adv_action"  value="cancel">
          <input type="hidden" name="advance_id"  value="<?= (int)$adv['id'] ?>">
          <button type="submit" style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:8px;padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;">Cancel</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <a href="?page=dashboard&tab=expense_approvals&adv_id=<?= (int)$adv['id'] ?>"
           style="display:inline-block;color:#4f46e5;font-size:12px;font-weight:700;text-decoration:none;padding:5px 0;">
          Expenses →
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</div>

<!-- ══ Create Advance Modal ════════════════════════════════════════════════ -->
<div id="advCreateModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:20px;padding:28px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;margin:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;">
      <div style="font-size:18px;font-weight:900;color:#0f0f0f;">💸 New Cash Advance</div>
      <button onclick="document.getElementById('advCreateModal').style.display='none'"
              style="background:none;border:none;font-size:24px;cursor:pointer;color:#64748b;">×</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="adv_action" value="create">
      <div style="display:grid;gap:14px;">
        <div>
          <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Recipient *</label>
          <!-- Hidden inputs that get submitted -->
          <input type="hidden" name="recipient_id"   id="advRecipId">
          <input type="hidden" name="recipient_name" id="advRecipName">
          <!-- Searchable staff picker -->
          <div id="recipPicker" style="position:relative;">
            <!-- Trigger button -->
            <div id="recipTrigger"
                 tabindex="0"
                 onclick="toggleRecipDropdown()"
                 onkeydown="if(event.key==='Enter'||event.key===' '){toggleRecipDropdown();event.preventDefault();}"
                 style="display:flex;align-items:center;justify-content:space-between;width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;background:#fff;cursor:pointer;user-select:none;gap:8px;box-sizing:border-box;">
              <span id="recipTriggerLabel" style="color:#9ca3af;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Select staff member…</span>
              <svg id="recipChevron" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;transition:transform .2s;"><polyline points="5 8 10 13 15 8"/></svg>
            </div>
            <!-- Dropdown panel -->
            <div id="recipDropdown"
                 style="display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:10001;overflow:hidden;">
              <!-- Search input -->
              <div style="padding:10px 12px;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:7px 10px;">
                  <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="9" r="6"/><line x1="14" y1="14" x2="18" y2="18"/></svg>
                  <input id="recipSearch" type="text" placeholder="Search by name or role…"
                         oninput="filterRecip(this.value)"
                         style="border:none;background:transparent;outline:none;font-size:13px;font-family:inherit;color:#0f172a;width:100%;">
                </div>
              </div>
              <!-- List -->
              <div id="recipList" style="max-height:220px;overflow-y:auto;">
                <?php
                $roleColors = [
                  'admin'           => ['bg'=>'#fef3c7','color'=>'#92400e'],
                  'accountant'      => ['bg'=>'#dbeafe','color'=>'#1e40af'],
                  'support_leader'  => ['bg'=>'#ede9fe','color'=>'#5b21b6'],
                  'support'         => ['bg'=>'#e0f2fe','color'=>'#075985'],
                  'support_engineer'=> ['bg'=>'#e0f2fe','color'=>'#075985'],
                  'sales'           => ['bg'=>'#dcfce7','color'=>'#166534'],
                  'field_agent'     => ['bg'=>'#ffedd5','color'=>'#9a3412'],
                  'collection'      => ['bg'=>'#fce7f3','color'=>'#9d174d'],
                ];
                foreach ($allStaff as $s):
                  if (empty($s['is_active'])) continue;
                  $role = $s['role'] ?? 'staff';
                  $rc   = $roleColors[$role] ?? ['bg'=>'#f1f5f9','color'=>'#475569'];
                  $initials = strtoupper(implode('', array_map(fn($w)=>$w[0]??'', array_slice(explode(' ', trim($s['name'])), 0, 2))));
                ?>
                <div class="recip-opt"
                     data-id="<?= (int)$s['id'] ?>"
                     data-name="<?= htmlspecialchars($s['name']) ?>"
                     data-role="<?= htmlspecialchars($role) ?>"
                     data-search="<?= htmlspecialchars(strtolower($s['name'].' '.$role)) ?>"
                     onclick="selectRecip(this)"
                     style="display:flex;align-items:center;gap:10px;padding:9px 14px;cursor:pointer;transition:background .12s;"
                     onmouseenter="this.style.background='#f8fafc'"
                     onmouseleave="this.style.background=''">
                  <!-- Avatar -->
                  <div style="width:32px;height:32px;border-radius:50%;background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;">
                    <?= htmlspecialchars($initials) ?>
                  </div>
                  <!-- Name + role -->
                  <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($s['name']) ?></div>
                    <div style="margin-top:2px;">
                      <span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:<?= $rc['bg'] ?>;color:<?= $rc['color'] ?>;"><?= htmlspecialchars($role) ?></span>
                    </div>
                  </div>
                  <!-- Check mark (shown when selected) -->
                  <svg class="recip-check" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" style="display:none;flex-shrink:0;"><polyline points="4 10 8 14 16 6"/></svg>
                </div>
                <?php endforeach; ?>
              </div>
              <!-- Empty state -->
              <div id="recipEmpty" style="display:none;padding:20px;text-align:center;color:#9ca3af;font-size:13px;">No staff found</div>
            </div>
          </div>
          <!-- Validation hint -->
          <div id="recipRequired" style="display:none;font-size:11px;color:#dc2626;margin-top:4px;">⚠ Please select a recipient</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Amount *</label>
            <input type="number" name="amount" step="0.01" min="0.01" max="5000"
                   style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;box-sizing:border-box;" placeholder="0.00" required>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Currency</label>
            <select name="currency" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;">
              <option value="USD">USD</option>
              <option value="SSP">SSP</option>
            </select>
          </div>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Purpose *</label>
          <select name="purpose" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;" required>
            <?php foreach (['fuel','parts','transport','allowance','food','misc'] as $p): ?>
            <option value="<?= $p ?>"><?= ucfirst($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Description</label>
          <input type="text" name="description" maxlength="200"
                 style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;box-sizing:border-box;" placeholder="e.g. Fuel for Juba East sites">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Project</label>
            <select name="project" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;">
              <option value="dishnet">DishNet Fiber & Starlink</option>
              <option value="4g">DishNet 4G</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Expected settle by</label>
            <input type="date" name="expected_settle_at" min="<?= date('Y-m-d') ?>"
                   style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;box-sizing:border-box;">
          </div>
        </div>
        <button type="submit" style="background:#0f0f0f;color:#fff;border:none;border-radius:12px;padding:14px;font-size:15px;font-weight:800;cursor:pointer;margin-top:6px;">
          Issue Advance &amp; Post to Cashbook
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ══ Settle Modal ════════════════════════════════════════════════════════ -->
<div id="advSettleModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:20px;padding:28px;width:100%;max-width:400px;margin:16px;">
    <div style="font-size:18px;font-weight:900;color:#0f0f0f;margin-bottom:18px;">✅ Settle Advance</div>
    <form method="POST" id="settleForm">
<?= csrfField() ?>
      <input type="hidden" name="adv_action" value="settle">
      <input type="hidden" name="advance_id" id="settleAdvId">
      <div style="display:grid;gap:14px;">
        <div style="background:#f8f8f5;border-radius:10px;padding:14px;">
          <div style="font-size:12px;color:#64748b;">Advance <strong id="settleAdvNo"></strong></div>
          <div style="font-size:12px;color:#64748b;margin-top:4px;">Remaining balance: <strong id="settleBalance" style="color:#d97706;"></strong></div>
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Cash returned by staff ($)</label>
          <input type="number" name="return_amount" id="settleReturnAmt" step="0.01" min="0" value="0"
                 style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;box-sizing:border-box;">
        </div>
        <div>
          <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Settlement note</label>
          <input type="text" name="settle_note" maxlength="200"
                 style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;box-sizing:border-box;" placeholder="e.g. All receipts submitted">
        </div>
        <div style="display:flex;gap:10px;">
          <button type="button" onclick="document.getElementById('advSettleModal').style.display='none'"
                  style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">Cancel</button>
          <button type="submit"
                  style="flex:2;background:#166534;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">Confirm Settle</button>
        </div>
      </div>
    </form>
  </div>
</div>

</div><!-- /wrap -->

<script>
/* ── Searchable Recipient Picker ──────────────────────────────────── */
var _recipOpen = false;
var _recipSelected = null;

function toggleRecipDropdown() {
    var dd = document.getElementById('recipDropdown');
    var ch = document.getElementById('recipChevron');
    var tr = document.getElementById('recipTrigger');
    if (_recipOpen) {
        closeRecipDropdown();
    } else {
        dd.style.display = 'block';
        ch.style.transform = 'rotate(180deg)';
        tr.style.borderColor = '#6366f1';
        tr.style.boxShadow = '0 0 0 3px rgba(99,102,241,.12)';
        _recipOpen = true;
        setTimeout(function(){ document.getElementById('recipSearch').focus(); }, 50);
    }
}

function closeRecipDropdown() {
    document.getElementById('recipDropdown').style.display = 'none';
    document.getElementById('recipChevron').style.transform = '';
    var tr = document.getElementById('recipTrigger');
    tr.style.borderColor = _recipSelected ? '#6366f1' : '#e2e8f0';
    tr.style.boxShadow = '';
    _recipOpen = false;
}

function filterRecip(q) {
    var items = document.querySelectorAll('.recip-opt');
    var lower = q.toLowerCase().trim();
    var visible = 0;
    items.forEach(function(el) {
        var match = !lower || el.dataset.search.indexOf(lower) > -1;
        el.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    });
    document.getElementById('recipEmpty').style.display = visible === 0 ? 'block' : 'none';
}

function selectRecip(el) {
    // Clear previous check
    document.querySelectorAll('.recip-check').forEach(function(c){ c.style.display = 'none'; });
    document.querySelectorAll('.recip-opt').forEach(function(o){ o.style.fontWeight = ''; });

    // Set values
    var id   = el.dataset.id;
    var name = el.dataset.name;
    document.getElementById('advRecipId').value   = id;
    document.getElementById('advRecipName').value  = name;
    document.getElementById('recipRequired').style.display = 'none';

    // Update trigger label
    var label = document.getElementById('recipTriggerLabel');
    label.textContent = name + ' (' + el.dataset.role + ')';
    label.style.color = '#0f172a';

    // Show check on selected item
    el.querySelector('.recip-check').style.display = 'block';
    _recipSelected = id;

    // Update trigger border to indicate selection
    document.getElementById('recipTrigger').style.borderColor = '#059669';
    document.getElementById('recipTrigger').style.background = '#f0fdf4';

    closeRecipDropdown();
    document.getElementById('recipSearch').value = '';
    filterRecip('');
}

// Close on outside click
document.addEventListener('click', function(e) {
    if (_recipOpen && !document.getElementById('recipPicker').contains(e.target)) {
        closeRecipDropdown();
    }
});

// Override form submit to validate recipient
document.querySelector('form[method="POST"] button[type="submit"]').addEventListener('click', function(e) {
    if (!document.getElementById('advRecipId').value) {
        document.getElementById('recipRequired').style.display = 'block';
        document.getElementById('recipTrigger').style.borderColor = '#dc2626';
        e.preventDefault();
    }
});

/* ── Settle Modal ─────────────────────────────────────────────────── */
function openSettle(id, no, balance) {
    document.getElementById('settleAdvId').value = id;
    document.getElementById('settleAdvNo').textContent = no;
    document.getElementById('settleBalance').textContent = '$' + balance.toFixed(2);
    document.getElementById('settleReturnAmt').value = balance > 0 ? balance.toFixed(2) : '0.00';
    document.getElementById('settleReturnAmt').max = balance > 0 ? balance.toFixed(2) : '0.00';
    document.getElementById('advSettleModal').style.display = 'flex';
}
</script>
