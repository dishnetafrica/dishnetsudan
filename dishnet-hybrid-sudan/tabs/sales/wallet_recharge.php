<?php
$_rchView = $_GET['rv'] ?? 'request'; // 'request' or 'history'
?>
<style>
.rch-tabs{display:flex;background:#f1f5f9;border-radius:14px;padding:4px;gap:4px;margin-bottom:16px;}
.rch-tab{flex:1;padding:10px 6px;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;background:transparent;color:#64748b;transition:.15s;}
.rch-tab.active{background:#fff;color:#D41C1C;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.rch-tab i{display:block;font-size:20px;margin-bottom:2px;}

/* History cards */
.rch-card{background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:10px;box-shadow:0 1px 6px rgba(0,0,0,.05);border:1px solid #f1f5f9;}
.rch-card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.rch-card-amt{font-size:22px;font-weight:900;color:#1e293b;}
.rch-card-date{font-size:11px;color:#94a3b8;}
.rch-card-row{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#64748b;margin-top:4px;}
.rch-card-row b{color:#1e293b;}
.badge-pending{background:#fff3cd;color:#856404;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-approved{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-rejected{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.rch-inv-ok{color:#166534;font-weight:700;font-size:11px;}
.rch-inv-fail{color:#c0392b;font-size:11px;}
.rch-inv-none{color:#bbb;font-size:11px;}
</style>

<!-- Tab switcher -->
<div class="rch-tabs">
    <button class="rch-tab <?= $_rchView==='request'?'active':'' ?>"
            onclick="location.href='?page=dashboard&tab=wallet_recharge&rv=request'">
        <i class="bi bi-plus-circle-fill"></i>Request
    </button>
    <button class="rch-tab <?= $_rchView==='history'?'active':'' ?>"
            onclick="location.href='?page=dashboard&tab=wallet_recharge&rv=history'">
        <i class="bi bi-clock-history"></i>History
        <?php if (!empty($myRecharges)): ?>
        <span style="font-size:10px;font-weight:700;color:#64748b;">(<?= count($myRecharges) ?>)</span>
        <?php endif; ?>
    </button>
</div>

<?php if ($_rchView === 'request'): ?>

<!-- Balance box -->
<div class="recharge-balance-box" style="margin-bottom:14px;">
    <div class="rbb-label"><i class="bi bi-wallet2 mr-1"></i> Current Wallet Balance</div>
    <div class="rbb-value">$<?= number_format($myWallet['balance'], 2) ?></div>
    <div style="font-size:12px;color:#2d6a4f;margin-top:6px;">Balance updates after admin approval.</div>
</div>

<!-- Request form -->
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-plus-circle-fill"></i>Load Money to Wallet</div>
    <div class="kyc-card-body">
        <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_recharge">
            <div class="form-group">
                <label class="form-label">Load Amount (USD) *</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                    <input type="number" name="amount" class="form-control" step="0.01" min="1" max="100000"
                           placeholder="0.00" required>
                </div>
                <div class="form-hint">Min: $1.00 | Max: $100,000.00</div>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Proof <small style="color:#999;font-weight:400;">(Optional — JPG/PNG/PDF, max 5MB)</small></label>
                <div class="file-row">
                    <input type="file" name="payment_proof" accept="image/*,.pdf" onchange="previewProof(this)">
                    <img id="proofPreview" class="file-preview" alt="">
                </div>
                <div class="form-hint">Upload a screenshot or receipt of your payment</div>
            </div>
            <div class="form-group">
                <label class="form-label">Payment Reference Note</label>
                <textarea name="note" class="form-control" rows="3" style="height:auto;"
                          placeholder="e.g. GTBank transfer ref #TX-20250115..." maxlength="500"></textarea>
                <div class="form-hint">Include bank reference numbers, transfer details, etc.</div>
            </div>
            <div class="kyc-alert info" style="font-weight:400;margin-bottom:14px;">
                &#9432; Your wallet balance will <strong>not</strong> increase until an admin approves this request.
            </div>
            <button type="submit" class="btn-kyc-submit w-100">
                <i class="bi bi-send-fill mr-1"></i> Submit Load Request
            </button>
        </form>
    </div>
</div>

<?php else: ?>

<!-- History — mobile cards -->
<?php if (empty($myRecharges)): ?>
<div style="text-align:center;padding:60px 20px;color:#94a3b8;">
    <i class="bi bi-clock-history" style="font-size:48px;display:block;margin-bottom:12px;color:#d1d5db;"></i>
    No recharge requests yet.<br>
    <a href="?page=dashboard&tab=wallet_recharge&rv=request" style="color:#D41C1C;font-weight:700;margin-top:10px;display:inline-block;">+ Make your first request</a>
</div>
<?php else: ?>
<?php foreach ($myRecharges as $r): ?>
<div class="rch-card">
    <div class="rch-card-top">
        <div class="rch-card-amt">$<?= number_format($r['amount'],2) ?></div>
        <?php if($r['status']==='pending'): ?>
        <span class="badge-pending">⏳ Pending</span>
        <?php elseif($r['status']==='approved'): ?>
        <span class="badge-approved">✅ Approved</span>
        <?php else: ?>
        <span class="badge-rejected">❌ Rejected</span>
        <?php endif; ?>
    </div>

    <?php if (!empty($r['note'])): ?>
    <div style="font-size:12px;color:#64748b;margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        📝 <?= h($r['note']) ?>
    </div>
    <?php endif; ?>

    <div class="rch-card-row">
        <span>🧾 Invoice</span>
        <?php if (!empty($r['crm_invoice_id'])): ?>
            <span class="rch-inv-ok">
                ✓ #<?= (int)$r['crm_invoice_id'] ?>
                <?php if (!empty($r['crm_invoice_number'])): ?> — <?= h($r['crm_invoice_number']) ?><?php endif; ?>
            </span>
        <?php elseif (isset($r['crm_invoice_synced']) && $r['crm_invoice_synced'] === false): ?>
            <span class="rch-inv-fail" title="<?= h($r['crm_invoice_err']??'') ?>">⚠ Failed (admin notified)</span>
        <?php else: ?>
            <span class="rch-inv-none">—</span>
        <?php endif; ?>
    </div>

    <?php if ($r['status']==='approved' && !empty($r['approved_by'])): ?>
    <div class="rch-card-row"><span>👤 Approved by</span><b><?= h($r['approved_by']) ?></b></div>
    <?php endif; ?>

    <?php if ($r['status']==='rejected' && !empty($r['rejection_reason'])): ?>
    <div style="background:#fee2e2;border-radius:8px;padding:8px 10px;font-size:12px;color:#991b1b;margin-top:6px;">
        ❌ Reason: <?= h($r['rejection_reason']) ?>
    </div>
    <?php endif; ?>

    <div class="rch-card-row" style="margin-top:8px;border-top:1px solid #f1f5f9;padding-top:8px;">
        <span style="color:#94a3b8;"><?= h(substr($r['created_at']??'',0,16)) ?></span>
        <?php if ($r['payment_proof']): ?>
            <?php $ext=strtolower(pathinfo($r['payment_proof'],PATHINFO_EXTENSION)); ?>
            <?php if(in_array($ext,['jpg','jpeg','png','gif','webp'])): ?>
            <img src="?page=serve_proof&id=<?= (int)$r['id'] ?>"
                 class="proof-thumb" alt="proof"
                 onclick="this.requestFullscreen?this.requestFullscreen():window.open(this.src)"
                 style="width:36px;height:36px;object-fit:cover;border-radius:8px;cursor:pointer;">
            <?php else: ?>
            <a href="?file=<?= urlencode($r['payment_proof']) ?>" target="_blank"
               style="font-size:11px;color:#D41C1C;">📄 PDF</a>
            <?php endif; ?>
        <?php else: ?>
        <span style="color:#d1d5db;font-size:11px;">No proof</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php endif; // end rv check ?>

