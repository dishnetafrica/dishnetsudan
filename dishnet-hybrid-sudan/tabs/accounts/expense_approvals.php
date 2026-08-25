<?php
// ── Expense Approvals Tab — DishNet Hybrid v4.5 ───────────────────────────────
// Actors: Accountant (Rupesh) and Admin
// Requires: $store, $dataDir, $retailer, $isAdmin, $userRole (from public.php)

if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }

require_once __DIR__ . '/../../lib/ExpenseAdvanceService.php';

$isAcctMgr = $isAdmin || in_array($userRole ?? '', ['accountant', 'admin']);
if (!$isAcctMgr) {
    echo '<div style="padding:40px;text-align:center;color:#dc2626;">⛔ Access denied.</div>';
    return;
}

$expAdv = new ExpenseAdvanceService($store, $dataDir);

// ── POST: approve / reject expense ────────────────────────────────────────────
$actionMsg = '';
$actionOk  = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expAction = $_POST['exp_action'] ?? '';
    $expId     = (int)($_POST['expense_id'] ?? 0);

    if ($expAction === 'approve' && $expId) {
        $r = $expAdv->approveExpense($expId, $retailer);
        $actionOk  = $r['ok'];
        if ($r['ok']) {
            // v4.20.3: cashbook_entry_id = -1 means imprest-suppressed (advance-linked SSP).
            // Don't show a fake cb_ledger #-1 link.
            $cbeId = (int)($r['cashbook_entry_id'] ?? 0);
            $actionMsg = ($cbeId === -1)
                ? "✅ Expense approved — settled against the staff advance (no extra cashbook entry, per imprest model)."
                : "✅ Expense approved and posted to cashbook (cb_ledger #{$cbeId}).";
        } else {
            $actionMsg = '❌ ' . ($r['error'] ?? 'Error approving expense.');
        }
    } elseif ($expAction === 'reject' && $expId) {
        $reason = trim($_POST['reject_reason'] ?? '');
        $r = $expAdv->rejectExpense($expId, $reason, $retailer);
        $actionOk  = $r['ok'];
        $actionMsg = $r['ok']
            ? '✅ Expense rejected and staff notified.'
            : ('❌ ' . ($r['error'] ?? 'Error rejecting expense.'));
    } elseif ($expAction === 'approve_all') {
        // Bulk approve all pending (no flags)
        $pending = $expAdv->getExpenses(['status' => 'pending', 'limit' => 200]);
        $approved = 0;
        foreach ($pending as $e) {
            if (!$e['flag_duplicate'] && !$e['flag_overspend']) {
                $r2 = $expAdv->approveExpense((int)$e['id'], $retailer);
                if ($r2['ok']) $approved++;
            }
        }
        $actionOk  = true;
        $actionMsg = "✅ Bulk approved {$approved} clean expense(s).";
    }
}

// ── Filters ────────────────────────────────────────────────────────────────────
$fStatus   = in_array($_GET['ea_s'] ?? '', ['pending','approved','rejected','']) ? ($_GET['ea_s'] ?? 'pending') : 'pending';
$fStaff    = (int)($_GET['ea_st'] ?? 0);
$fCat      = in_array($_GET['ea_cat'] ?? '', array_merge([''], ExpenseAdvanceService::CATEGORIES)) ? ($_GET['ea_cat'] ?? '') : '';
$fFrom     = $_GET['ea_from'] ?? '';
$fTo       = $_GET['ea_to'] ?? '';
$fFlagged  = !empty($_GET['ea_flag']);
$fAdvId    = (int)($_GET['adv_id'] ?? 0);

$filters = array_filter([
    'status'      => $fStatus,
    'staff_id'    => $fStaff ?: null,
    'category'    => $fCat,
    'date_from'   => $fFrom,
    'date_to'     => $fTo,
    'flagged'     => $fFlagged ?: null,
    'advance_id'  => $fAdvId ?: null,
    'limit'       => 200,
]);
$expenses   = $expAdv->getExpenses($filters);
$pendCount  = $expAdv->countPending();

// Fraud report for pending items
$fraudItems = [];
if ($fStatus === 'pending') {
    $fraud = $expAdv->getFraudReport();
    foreach ($fraud as $fi) {
        $fraudItems[(int)$fi['expense']['id']] = $fi['flags'];
    }
}

function expCatIcon(string $cat): string {
    return ['fuel'=>'⛽','parts'=>'🔧','transport'=>'🚌','allowance'=>'💵','food'=>'🍽️','other'=>'📋'][$cat] ?? '📋';
}
if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); } }
?>

<div style="padding:0 0 80px;">

<?php if ($actionMsg): ?>
<div style="background:<?= $actionOk ? '#dcfce7' : '#fee2e2' ?>;border:1px solid <?= $actionOk ? '#86efac' : '#fca5a5' ?>;
     border-radius:12px;padding:14px 18px;margin:16px;font-size:14px;font-weight:700;
     color:<?= $actionOk ? '#166534' : '#dc2626' ?>;">
  <?= h($actionMsg) ?>
</div>
<?php endif; ?>

<!-- ══ Header ════════════════════════════════════════════════════════════════ -->
<div style="padding:20px 20px 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
  <div>
    <div style="font-size:22px;font-weight:900;color:#0f0f0f;">🧾 Expense Approvals</div>
    <div style="font-size:12px;color:#64748b;margin-top:2px;">Review and approve field staff expenses</div>
  </div>
  <?php if ($pendCount > 0): ?>
  <div style="display:flex;gap:8px;align-items:center;">
    <span style="background:#fef9c3;border:1.5px solid #fde68a;color:#92400e;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:800;">
      ⏳ <?= $pendCount ?> pending
    </span>
    <?php if ($pendCount > 1): ?>
    <form method="POST" onsubmit="return confirm('Approve all clean (unflagged) pending expenses?')">
      <?= csrfField() ?>
      <input type="hidden" name="exp_action" value="approve_all">
      <button type="submit" style="background:#166534;color:#fff;border:none;border-radius:10px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;">
        ✅ Bulk Approve Clean
      </button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ Filter bar ════════════════════════════════════════════════════════════ -->
<form method="GET" style="padding:12px 20px;display:flex;gap:8px;flex-wrap:wrap;">
  <input type="hidden" name="page" value="dashboard">
  <input type="hidden" name="tab"  value="expense_approvals">
  <?php if ($fAdvId): ?><input type="hidden" name="adv_id" value="<?= $fAdvId ?>"><?php endif; ?>

  <select name="ea_s" style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;background:#fff;">
    <?php foreach (['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected',''=>'All statuses'] as $v=>$l): ?>
    <option value="<?= h($v) ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= h($l) ?></option>
    <?php endforeach; ?>
  </select>

  <select name="ea_cat" style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;background:#fff;">
    <option value="">All categories</option>
    <?php foreach (ExpenseAdvanceService::CATEGORIES as $c): ?>
    <option value="<?= h($c) ?>" <?= $fCat === $c ? 'selected' : '' ?>><?= expCatIcon($c) . ' ' . ucfirst($c) ?></option>
    <?php endforeach; ?>
  </select>

  <input type="date" name="ea_from" value="<?= h($fFrom) ?>"
         style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;">
  <input type="date" name="ea_to"   value="<?= h($fTo) ?>"
         style="border:1.5px solid #e2e8f0;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;">

  <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;border:1.5px solid <?= $fFlagged ? '#dc2626' : '#e2e8f0' ?>;border-radius:10px;padding:8px 12px;background:<?= $fFlagged ? '#fee2e2' : '#fff' ?>;">
    <input type="checkbox" name="ea_flag" value="1" <?= $fFlagged ? 'checked' : '' ?> onchange="this.form.submit()">
    🚩 Flagged only
  </label>

  <button type="submit" style="background:#0f0f0f;color:#fff;border:none;border-radius:10px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;">Filter</button>
  <?php if ($fStatus !== 'pending' || $fCat || $fFrom || $fTo || $fFlagged || $fAdvId): ?>
  <a href="?page=dashboard&tab=expense_approvals"
     style="border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 14px;font-size:13px;font-weight:600;text-decoration:none;color:#64748b;background:#fff;">
    Clear
  </a>
  <?php endif; ?>
</form>

<?php if ($fAdvId): ?>
<div style="padding:0 20px 8px;">
  <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:700;color:#0369a1;">
    🔗 Filtered to Advance ADV#<?= $fAdvId ?> &nbsp;
    <a href="?page=dashboard&tab=expense_approvals" style="color:#0369a1;font-weight:600;">Clear filter</a>
  </div>
</div>
<?php endif; ?>

<!-- ══ Expense cards ══════════════════════════════════════════════════════════ -->
<div style="padding:0 16px;">
<?php if (empty($expenses)): ?>
<div style="background:#f8f8f5;border-radius:14px;padding:48px;text-align:center;color:#94a3b8;font-size:14px;">
  <?= $fStatus === 'pending' ? '🎉 No pending expenses — all clear!' : 'No expenses found for these filters.' ?>
</div>

<?php else: ?>
  <?php foreach ($expenses as $exp):
    $flags   = $fraudItems[(int)$exp['id']] ?? [];
    $hasFraud = !empty($flags);
    $noRcpt  = (int)$exp['flag_no_receipt'];
    $isDup   = (int)$exp['flag_duplicate'];
    $isOver  = (int)$exp['flag_overspend'];
    $cardBg  = $hasFraud ? '#fff7f7' : ($exp['status'] === 'approved' ? '#f0fdf4' : '#fff');
    $borderC = $hasFraud ? '#fca5a5' : ($exp['status'] === 'approved' ? '#86efac' : '#e2e8f0');
  ?>
  <div style="background:<?= $cardBg ?>;border:1.5px solid <?= $borderC ?>;border-radius:16px;padding:16px 18px;margin-bottom:12px;">

    <!-- Top row: expense number, staff, amount, status -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
      <div>
        <div style="font-size:13px;font-weight:900;color:#0f0f0f;font-family:monospace;"><?= h($exp['expense_no']) ?></div>
        <div style="font-size:12px;color:#374151;margin-top:3px;">
          <strong><?= h($exp['staff_name']) ?></strong>
          &nbsp;·&nbsp; <?= expCatIcon($exp['category']) ?> <strong><?= ucfirst(h($exp['category'])) ?></strong>
          &nbsp;·&nbsp; <?= h(date('d M Y', strtotime($exp['expense_date']))) ?>
        </div>
        <?php if ($exp['advance_id']): ?>
        <div style="font-size:11px;color:#4f46e5;margin-top:2px;">
          🔗 Linked to advance: <a href="?page=dashboard&tab=cash_advances" style="color:#4f46e5;font-weight:700;">ADV#<?= (int)$exp['advance_id'] ?></a>
        </div>
        <?php endif; ?>
      </div>
      <div style="text-align:right;">
        <div style="font-size:22px;font-weight:900;color:<?= $exp['direction'] ?? 'out' ? '#dc2626' : '#059669' ?>;">
          <?php if (($exp['currency'] ?? 'USD') === 'SSP'): ?>
            <?php $_sspDisp = (float)($exp['ssp_amount'] ?? 0) > 0 ? (float)$exp['ssp_amount'] : (float)($exp['amount'] ?? 0); ?>
            <?= number_format($_sspDisp, 0) ?> <span style="font-size:12px;font-weight:600;color:#64748b;">SSP</span>
          <?php else: ?>
            $<?= number_format((float)$exp['amount'], 2) ?> <span style="font-size:12px;font-weight:600;color:#64748b;">USD</span>
          <?php endif; ?>
        </div>
        <div style="margin-top:4px;">
          <?php
          $stMap = [
            'pending'  => ['#fef9c3','#92400e','⏳ Pending'],
            'approved' => ['#dcfce7','#166534','✅ Approved'],
            'rejected' => ['#fee2e2','#dc2626','❌ Rejected'],
          ];
          [$sBg,$sFg,$sLabel] = $stMap[$exp['status']] ?? ['#f3f4f6','#6b7280','?'];
          if (!empty($exp['auto_approved'])) { $sBg='#e0f2fe'; $sFg='#0369a1'; $sLabel='⚡ Auto-approved'; }
          ?>
          <span style="background:<?= $sBg ?>;color:<?= $sFg ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;"><?= $sLabel ?></span>
        </div>
      </div>
    </div>

    <!-- Description -->
    <?php if ($exp['description']): ?>
    <div style="font-size:13px;color:#374151;margin-top:10px;padding:10px 14px;background:rgba(0,0,0,.03);border-radius:8px;">
      <?= h($exp['description']) ?>
    </div>
    <?php endif; ?>

    <!-- Fraud flags -->
    <?php if ($hasFraud): ?>
    <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
      <?php foreach ($flags as $fl): ?>
      <span style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">
        🚩 <?= h($fl['detail']) ?>
      </span>
      <?php endforeach; ?>
    </div>
    <?php elseif ($noRcpt || $isDup || $isOver): ?>
    <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
      <?php if ($noRcpt): ?><span style="background:#fef9c3;color:#92400e;border:1px solid #fde68a;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">⚠️ No receipt</span><?php endif; ?>
      <?php if ($isDup):  ?><span style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">🔁 Duplicate receipt</span><?php endif; ?>
      <?php if ($isOver): ?><span style="background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">💸 Overspend</span><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Receipt photo preview -->
    <?php if ($exp['receipt_path']): ?>
    <div style="margin-top:12px;">
      <div style="font-size:11px;font-weight:700;color:#374151;margin-bottom:6px;">📎 Receipt</div>
      <a href="javascript:void(0)" onclick="dnLbOpen('?page=api&action=expense_photo&id=<?= (int)$exp['id'] ?>')"
         target="_blank"
         style="display:inline-block;border:2px solid #e2e8f0;border-radius:10px;overflow:hidden;max-width:180px;">
        <?php
        $rcptFull = $dataDir . '/' . $exp['receipt_path'];
        // Fallback: try with uploads/ prefix if path doesn't include it
        if (!file_exists($rcptFull) && strpos($exp['receipt_path'], 'uploads/') !== 0) {
            $rcptFull = $dataDir . '/uploads/' . $exp['receipt_path'];
        }
        $ext = strtolower(pathinfo($exp['receipt_path'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png']) && file_exists($rcptFull)):
        ?>
        <img src="?page=api&action=expense_photo&id=<?= (int)$exp['id'] ?>&thumb=1"
             style="width:180px;height:120px;object-fit:cover;display:block;"
             alt="Receipt" loading="lazy">
        <?php elseif ($ext === 'pdf'): ?>
        <div style="width:180px;height:80px;display:flex;align-items:center;justify-content:center;background:#f8f8f5;font-size:24px;">📄</div>
        <?php else: ?>
        <div style="width:180px;height:80px;display:flex;align-items:center;justify-content:center;background:#f8f8f5;font-size:12px;color:#94a3b8;">Receipt file</div>
        <?php endif; ?>
      </a>
    </div>
    <?php else: ?>
    <div style="margin-top:10px;font-size:12px;color:#94a3b8;font-style:italic;">No receipt photo uploaded</div>
    <?php endif; ?>

    <!-- Approval / rejection info for processed items -->
    <?php if ($exp['status'] !== 'pending'): ?>
    <div style="margin-top:10px;font-size:12px;color:#64748b;background:#f8f8f5;border-radius:8px;padding:8px 12px;">
      <?= $exp['status'] === 'approved' ? '✅ Approved' : '❌ Rejected' ?>
      <?php if (!empty($exp['reviewed_by'])): ?>
      by <strong><?= h($exp['reviewed_by']) ?></strong>
      <?php endif; ?>
      <?php if (!empty($exp['reviewed_at'])): ?>
      on <?= h(date('d M Y H:i', strtotime($exp['reviewed_at']))) ?>
      <?php endif; ?>
      <?php if ($exp['status'] === 'rejected' && $exp['reject_reason']): ?>
      <br><span style="color:#dc2626;">Reason: <?= h($exp['reject_reason']) ?></span>
      <?php endif; ?>
      <?php if ($exp['status'] === 'approved' && (int)$exp['cashbook_entry_id'] > 0): ?>
      <br><span style="color:#059669;">Cashbook entry: cb_ledger #<?= (int)$exp['cashbook_entry_id'] ?></span>
      <?php elseif ($exp['status'] === 'approved' && (int)$exp['cashbook_entry_id'] === -1): ?>
      <br><span style="color:#0891b2;" title="No separate cb_ledger row — the SSP advance already recorded the cash leaving the till">Settled against staff advance (imprest model · v4.20.3)</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Approve / Reject actions (pending only) -->
    <?php if ($exp['status'] === 'pending'): ?>
    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">

      <!-- Approve -->
      <form method="POST" style="display:contents;" onsubmit="return confirm('Approve this expense and post to cashbook?')">
        <?= csrfField() ?>
        <input type="hidden" name="exp_action"  value="approve">
        <input type="hidden" name="expense_id"  value="<?= (int)$exp['id'] ?>">
        <button type="submit"
                style="flex:1;min-width:120px;background:<?= $hasFraud ? '#f0fdf4' : '#166534' ?>;color:<?= $hasFraud ? '#166534' : '#fff' ?>;border:<?= $hasFraud ? '2px solid #86efac' : 'none' ?>;
                       border-radius:12px;padding:11px 18px;font-size:14px;font-weight:800;cursor:pointer;">
          ✅ Approve<?= $hasFraud ? ' (flagged)' : '' ?>
        </button>
      </form>

      <!-- Reject -->
      <button onclick="openReject(<?= (int)$exp['id'] ?>, '<?= addslashes(h($exp['expense_no'])) ?>')"
              style="flex:1;min-width:120px;background:#fff;border:2px solid #fca5a5;color:#dc2626;
                     border-radius:12px;padding:11px 18px;font-size:14px;font-weight:800;cursor:pointer;">
        ❌ Reject
      </button>
    </div>
    <?php endif; ?>

    <!-- Submitted at / via -->
    <div style="margin-top:8px;font-size:11px;color:#94a3b8;">
      Submitted <?= h(date('d M Y H:i', strtotime($exp['submitted_at']))) ?>
      via <span style="background:#f1f5f9;padding:1px 6px;border-radius:6px;font-weight:700;"><?= h($exp['submitted_via']) ?></span>
    </div>

  </div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ══ Reject Modal ═══════════════════════════════════════════════════════════ -->
<div id="rejectModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:20px;padding:28px;width:100%;max-width:420px;margin:16px;">
    <div style="font-size:18px;font-weight:900;color:#dc2626;margin-bottom:6px;">❌ Reject Expense</div>
    <div style="font-size:13px;color:#64748b;margin-bottom:20px;" id="rejectExpNo"></div>
    <form method="POST">
<?= csrfField() ?>
      <input type="hidden" name="exp_action" value="reject">
      <input type="hidden" name="expense_id" id="rejectExpId">
      <div style="margin-bottom:14px;">
        <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:6px;">
          Rejection reason (required — sent to staff) *
        </label>
        <textarea name="reject_reason" rows="3" required
                  style="width:100%;border:1.5px solid #fca5a5;border-radius:10px;padding:11px 14px;font-size:14px;font-family:inherit;box-sizing:border-box;resize:vertical;"
                  placeholder="e.g. Receipt not legible — please resubmit with clearer photo…"></textarea>
      </div>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="document.getElementById('rejectModal').style.display='none'"
                style="flex:1;background:#f8f8f5;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;">
          Cancel
        </button>
        <button type="submit"
                style="flex:2;background:#dc2626;color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:800;cursor:pointer;">
          Confirm Reject
        </button>
      </div>
    </form>
  </div>
</div>

</div><!-- /wrap -->

<script>
function openReject(id, no) {
    document.getElementById('rejectExpId').value = id;
    document.getElementById('rejectExpNo').textContent = no;
    document.getElementById('rejectModal').style.display = 'flex';
}
</script>
