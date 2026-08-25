<?php
// ══════════════════════════════════════════════════════════════════════
// Field Expenses — Mobile-first for Support Leader (Bidal)
// Submit expenses, upload receipts, track advances
// ══════════════════════════════════════════════════════════════════════

require_once dirname(__DIR__, 2) . '/lib/ExpenseAdvanceService.php';
$expAdv = new ExpenseAdvanceService($store, $dataDir);
$staffId = (int)$retailer['id'];

// ── POST handlers ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['fe_action'])) {
    $act = $_POST['fe_action'];

    if ($act === 'submit_expense') {
        $result = $expAdv->submitExpense($_POST, $_FILES['receipt'] ?? null, $retailer);
        if ($result['ok']) {
            flash('Expense submitted. Waiting for approval.', 'success');
        } else {
            flash($result['error'] ?? 'Failed to submit expense.', 'danger');
        }
        redirect('?page=dashboard&tab=field_expenses');
    }

    if ($act === 'request_advance') {
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $purpose = trim($_POST['purpose'] ?? '');
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        if (!in_array($currency, ['USD', 'SSP'])) $currency = 'USD';
        $amtDisplay = $currency === 'SSP' ? number_format($amount) . ' SSP' : '$' . number_format($amount, 2);
        if ($amount > 0 && $purpose) {
            $store->appendWithId('activity_log.json', [
                'event'   => 'advance_request',
                'actor'   => $retailer['name'],
                'action'  => 'REQUEST',
                'detail'  => "Cash advance request: {$amtDisplay} — {$purpose}",
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            try {
                $notify = svc('notify');
                $adminPhone = $config['whatsapp_admin_phone'] ?? '';
                if ($adminPhone) {
                    $notify->sendVia('support', $adminPhone, "💸 *Cash Advance Request*\n\nFrom: {$retailer['name']}\nAmount: {$amtDisplay}\nPurpose: {$purpose}\n\nApprove in DishNet → Cash Advances tab.", 'cash_advance_request');
                }
            } catch (Throwable $e) {}
            flash("Advance request for {$amtDisplay} submitted. Admin will be notified.", 'success');
        } else {
            flash('Enter amount and purpose.', 'danger');
        }
        redirect('?page=dashboard&tab=field_expenses');
    }
}

// ── Load data ───────────────────────────────────────────────────────
$summary  = $expAdv->getStaffSummary($staffId);
$advances = $expAdv->getAdvances(['recipient_id' => $staffId, 'status' => 'active', 'limit' => 10]);

$expenses = $expAdv->getExpenses(['staff_id' => $staffId, 'limit' => 20]);
$categories = ['fuel' => '⛽ Fuel', 'parts' => '🔧 Parts', 'transport' => '🚗 Transport', 'allowance' => '💰 Allowance', 'food' => '🍽 Food', 'other' => '📦 Other'];
?>

<style>
.fe-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:16px;margin-bottom:14px;}
.fe-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:14px;}
.fe-stat{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:12px;text-align:center;}
.fe-stat-label{font-size:9px;font-weight:700;color:#6b7280;text-transform:uppercase;}
.fe-stat-value{font-size:22px;font-weight:900;line-height:1.2;}
.fe-btn{border:none;border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;width:100%;}
.fe-input{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;font-family:inherit;box-sizing:border-box;margin-bottom:8px;}
.fe-select{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;background:#fff;font-family:inherit;box-sizing:border-box;margin-bottom:8px;}
.fe-label{font-size:11px;font-weight:700;color:#374151;margin-bottom:4px;display:block;}
.fe-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;}
.fe-tab-bar{display:flex;gap:4px;margin-bottom:14px;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.fe-tab{padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;}
@media(min-width:768px){.fe-stats{grid-template-columns:repeat(4,1fr);}}
</style>

<?php $feView = $_GET['fv'] ?? 'submit'; ?>

<!-- Header -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
    <div style="font-size:24px;">💸</div>
    <div>
        <div style="font-size:17px;font-weight:800;color:#1e293b;">Field Expenses</div>
        <div style="font-size:11px;color:#6b7280;"><?= h($retailer['name']) ?> · Submit expenses & track advances</div>
    </div>
</div>

<!-- Stats -->
<?php $cur = $summary['by_currency'] ?? ['USD'=>['balance'=>0,'pending'=>0],'SSP'=>['balance'=>0,'pending'=>0]]; ?>
<div class="fe-stats" style="grid-template-columns:repeat(2,1fr);">
    <div class="fe-stat" style="background:#f0fdf4;border-color:#bbf7d0;">
        <div class="fe-stat-label">💵 USD Balance</div>
        <div class="fe-stat-value" style="color:<?= ($cur['USD']['balance'] ?? 0) > 0 ? '#15803d' : '#991b1b' ?>;">$<?= number_format($cur['USD']['balance'] ?? 0, 2) ?></div>
        <?php if (($cur['USD']['pending'] ?? 0) > 0): ?>
        <div style="font-size:10px;color:#f59e0b;">$<?= number_format($cur['USD']['pending'], 2) ?> pending</div>
        <?php endif; ?>
    </div>
    <div class="fe-stat" style="background:#eff6ff;border-color:#bfdbfe;">
        <div class="fe-stat-label">🇸🇸 SSP Balance</div>
        <div class="fe-stat-value" style="color:<?= ($cur['SSP']['balance'] ?? 0) > 0 ? '#1d4ed8' : '#991b1b' ?>;"><?= number_format($cur['SSP']['balance'] ?? 0, 0) ?> SSP</div>
        <?php if (($cur['SSP']['pending'] ?? 0) > 0): ?>
        <div style="font-size:10px;color:#f59e0b;"><?= number_format($cur['SSP']['pending'], 0) ?> SSP pending</div>
        <?php endif; ?>
    </div>
</div>
<div class="fe-stats" style="grid-template-columns:repeat(2,1fr);margin-top:-6px;">
    <div class="fe-stat">
        <div class="fe-stat-label">Active Advances</div>
        <div class="fe-stat-value" style="color:#1d4ed8;"><?= $summary['active_advances'] ?></div>
    </div>
    <div class="fe-stat">
        <div class="fe-stat-label">Pending Expenses</div>
        <div class="fe-stat-value" style="color:#f59e0b;"><?= $summary['pending_expenses'] ?></div>
    </div>
</div>

<!-- Tab bar -->
<div class="fe-tab-bar">
    <a href="?page=dashboard&tab=field_expenses&fv=submit" class="fe-tab" style="background:<?= $feView==='submit'?'#1e293b':'#f1f5f9' ?>;color:<?= $feView==='submit'?'#fff':'#374151' ?>;">📝 New Expense</a>
    <a href="?page=dashboard&tab=field_expenses&fv=history" class="fe-tab" style="background:<?= $feView==='history'?'#1e293b':'#f1f5f9' ?>;color:<?= $feView==='history'?'#fff':'#374151' ?>;">📋 History</a>
    <a href="?page=dashboard&tab=field_expenses&fv=advance" class="fe-tab" style="background:<?= $feView==='advance'?'#1e293b':'#f1f5f9' ?>;color:<?= $feView==='advance'?'#fff':'#374151' ?>;">💰 Request Advance</a>
</div>

<?php if ($feView === 'submit'): ?>
<!-- ═══ SUBMIT EXPENSE ═══ -->
<div class="fe-card">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:12px;">Log an Expense</div>
    <form method="POST" action="?page=dashboard&tab=field_expenses" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="fe_action" value="submit_expense">
        <input type="hidden" name="submitted_via" value="web">

        <!-- Currency toggle -->
        <div style="display:flex;gap:0;margin-bottom:12px;border-radius:10px;overflow:hidden;border:2px solid #e2e8f0;">
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:#f0fdf4;color:#15803d;" id="cur_usd_label">
                <input type="radio" name="currency" value="USD" checked style="display:none;" onchange="toggleCur('USD')"> 💵 USD
            </label>
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:#f8fafc;color:#9ca3af;" id="cur_ssp_label">
                <input type="radio" name="currency" value="SSP" style="display:none;" onchange="toggleCur('SSP')"> 🇸🇸 SSP
            </label>
        </div>
        <script>
        function toggleCur(c) {
            var u = document.getElementById('cur_usd_label'), s = document.getElementById('cur_ssp_label');
            if (c==='USD') { u.style.background='#f0fdf4'; u.style.color='#15803d'; s.style.background='#f8fafc'; s.style.color='#9ca3af'; }
            else { s.style.background='#eff6ff'; s.style.color='#1d4ed8'; u.style.background='#f8fafc'; u.style.color='#9ca3af'; }
        }
        </script>

        <label class="fe-label">Amount</label>
        <input type="number" name="amount" class="fe-input" placeholder="0.00" step="0.01" min="0.01" required style="font-size:20px;font-weight:800;text-align:center;">

        <label class="fe-label">Category</label>
        <select name="category" class="fe-select" required>
            <?php foreach ($categories as $k => $v): ?>
            <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
        </select>

        <label class="fe-label">Description</label>
        <input type="text" name="description" class="fe-input" placeholder="What was this expense for?" required>

        <label class="fe-label">Date</label>
        <input type="date" name="expense_date" id="feExpDate" class="fe-input"
               value="<?= date('Y-m-d') ?>"
               max="<?= date('Y-m-d') ?>"
               min="<?= date('Y-m-d', strtotime('-2 days')) ?>">
        <div id="feExpDateWarn" style="display:none;font-size:11px;font-weight:700;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:6px 10px;margin-top:-4px;margin-bottom:6px;">
          ⚠️ Backdating is restricted to 2 days. Contact Rupesh for older entries.
        </div>
        <script>
        document.getElementById('feExpDate').addEventListener('change', function() {
          var val = this.value;
          var today = '<?= date('Y-m-d') ?>';
          var minDate = '<?= date('Y-m-d', strtotime('-2 days')) ?>';
          var warn = document.getElementById('feExpDateWarn');
          if (val > today) { this.value = today; }
          if (val < minDate) {
            warn.style.display = 'block';
            this.value = minDate;
          } else {
            warn.style.display = 'none';
          }
        });
        </script>

        <?php if (!empty($advances)): ?>
        <label class="fe-label">Link to Advance (optional)</label>
        <select name="advance_id" class="fe-select">
            <option value="">— No advance —</option>
            <?php foreach ($advances as $adv): ?>
            <option value="<?= $adv['id'] ?>"><?= h($adv['advance_no']) ?> — $<?= number_format($adv['balance'], 2) ?> remaining</option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <label class="fe-label">Receipt Photo</label>
        <label style="background:#f8fafc;border:2px dashed #d1d5db;border-radius:12px;padding:16px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;margin-bottom:12px;" onclick="this.querySelector('input').click()">
            <span style="font-size:24px;">📸</span>
            <span style="font-size:12px;font-weight:700;color:#374151;" id="receiptName">Tap to take photo or upload</span>
            <input type="file" name="receipt" accept="image/*" capture="environment" style="display:none;" onchange="document.getElementById('receiptName').textContent=this.files[0]?.name||'Tap to upload'">
        </label>

        <button type="submit" class="fe-btn" style="background:#15803d;color:#fff;">📤 Submit Expense</button>
    </form>
</div>

<?php elseif ($feView === 'history'): ?>
<!-- ═══ EXPENSE HISTORY ═══ -->
<div class="fe-card">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:12px;">Recent Expenses</div>
    <?php if (empty($expenses)): ?>
    <div style="text-align:center;color:#9ca3af;padding:20px;">No expenses submitted yet.</div>
    <?php else: ?>
    <?php foreach ($expenses as $exp):
        $statusColors = ['pending' => ['#fef3c7','#92400e'], 'approved' => ['#dcfce7','#15803d'], 'rejected' => ['#fee2e2','#991b1b']];
        $sc = $statusColors[$exp['status']] ?? ['#f1f5f9','#374151'];
        $catIcons = ['fuel'=>'⛽','parts'=>'🔧','transport'=>'🚗','allowance'=>'💰','food'=>'🍽','other'=>'📦'];
    ?>
    <div class="fe-row">
        <div>
            <div style="font-size:13px;font-weight:700;color:#1e293b;">
                <?= $catIcons[$exp['category']] ?? '📦' ?>
                <?php $ec = strtoupper($exp['currency'] ?? 'USD'); ?>
                <?= $ec === 'SSP' ? number_format((float)$exp['amount']) . ' SSP' : '$' . number_format((float)$exp['amount'], 2) ?>
                <span style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>;padding:1px 6px;border-radius:6px;font-size:9px;font-weight:800;margin-left:4px;"><?= ucfirst($exp['status']) ?></span>
            </div>
            <div style="font-size:11px;color:#6b7280;"><?= h($exp['description'] ?? '') ?></div>
            <div style="font-size:10px;color:#9ca3af;"><?= h(substr($exp['expense_date'] ?? $exp['submitted_at'] ?? '', 0, 10)) ?> · <?= h($exp['category'] ?? '') ?></div>
        </div>
        <?php if (!empty($exp['receipt_path'])): ?>
        <div style="font-size:18px;">🧾</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Active Advances -->
<?php if (!empty($advances)): ?>
<div class="fe-card">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:12px;">Active Advances</div>
    <?php foreach ($advances as $adv): ?>
    <div class="fe-row">
        <div>
            <div style="font-size:13px;font-weight:700;color:#1e293b;"><?= h($adv['advance_no']) ?></div>
            <div style="font-size:11px;color:#6b7280;"><?= h($adv['purpose'] ?? '') ?></div>
            <div style="font-size:10px;color:#9ca3af;">Issued: <?= h(substr($adv['issued_at'] ?? '', 0, 10)) ?></div>
        </div>
        <div style="text-align:right;">
            <?php $ac = strtoupper($adv['currency'] ?? 'USD'); ?>
            <div style="font-size:15px;font-weight:900;color:<?= $adv['balance'] > 0 ? '#15803d' : '#991b1b' ?>;"><?= $ac === 'SSP' ? number_format($adv['balance']) . ' SSP' : '$' . number_format($adv['balance'], 2) ?></div>
            <div style="font-size:10px;color:#9ca3af;">of <?= $ac === 'SSP' ? number_format((float)$adv['amount']) . ' SSP' : '$' . number_format((float)$adv['amount'], 2) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif ($feView === 'advance'): ?>
<!-- ═══ REQUEST ADVANCE ═══ -->
<div class="fe-card">
    <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:12px;">Request Cash Advance</div>
    <div style="font-size:12px;color:#6b7280;margin-bottom:14px;">Submit a request to admin for field operation funds. You'll be notified when approved.</div>
    <form method="POST" action="?page=dashboard&tab=field_expenses">
        <?= csrfField() ?>
        <input type="hidden" name="fe_action" value="request_advance">

        <!-- Currency toggle -->
        <div style="display:flex;gap:0;margin-bottom:12px;border-radius:10px;overflow:hidden;border:2px solid #e2e8f0;">
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:#f0fdf4;color:#15803d;" id="adv_usd_label">
                <input type="radio" name="currency" value="USD" checked style="display:none;" onchange="toggleAdvCur('USD')"> 💵 USD
            </label>
            <label style="flex:1;text-align:center;padding:10px;font-size:14px;font-weight:800;cursor:pointer;background:#f8fafc;color:#9ca3af;" id="adv_ssp_label">
                <input type="radio" name="currency" value="SSP" style="display:none;" onchange="toggleAdvCur('SSP')"> 🇸🇸 SSP
            </label>
        </div>
        <script>
        function toggleAdvCur(c) {
            var u = document.getElementById('adv_usd_label'), s = document.getElementById('adv_ssp_label');
            if (c==='USD') { u.style.background='#f0fdf4'; u.style.color='#15803d'; s.style.background='#f8fafc'; s.style.color='#9ca3af'; }
            else { s.style.background='#eff6ff'; s.style.color='#1d4ed8'; u.style.background='#f8fafc'; u.style.color='#9ca3af'; }
        }
        </script>

        <label class="fe-label">Amount Needed</label>
        <input type="number" name="amount" class="fe-input" placeholder="0.00" step="0.01" min="1" required style="font-size:20px;font-weight:800;text-align:center;">

        <label class="fe-label">Purpose</label>
        <textarea name="purpose" class="fe-input" rows="3" placeholder="What is this advance for? (e.g., Fiber installation supplies, transport for 3 field visits, router procurement)" required style="resize:vertical;"></textarea>

        <button type="submit" class="fe-btn" style="background:#1d4ed8;color:#fff;">💰 Request Advance</button>
    </form>
</div>
<?php endif; ?>
