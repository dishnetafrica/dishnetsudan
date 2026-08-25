<?php
// Tab: recharge_requests
// Extracted from public.php on 2026-03-15
    $allRecharges  = $recharge->getAll();
    $cntPending    = count(array_filter($allRecharges, fn($r)=>$r['status']==='pending'));
    $cntApproved   = count(array_filter($allRecharges, fn($r)=>$r['status']==='approved'));
    $cntRejected   = count(array_filter($allRecharges, fn($r)=>$r['status']==='rejected'));
    $totalApproved = array_sum(array_map(fn($r)=>$r['status']==='approved'?(float)$r['amount']:0, $allRecharges));
    $missingInvoice = array_filter($allRecharges, fn($r)=>
        $r['status']==='approved'
        && empty($r['crm_invoice_id'])
        && ($r['invoice_status'] ?? 'legacy') === 'failed'
    );
?>
<?php if (!empty($missingInvoice)): ?>
<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:flex-start;gap:12px;">
    <span style="font-size:22px;flex-shrink:0;">⚠️</span>
    <div>
        <div style="font-weight:800;font-size:13px;color:#856404;margin-bottom:4px;"><?= count($missingInvoice) ?> approved recharge(s) have no UCRM invoice</div>
        <div style="font-size:12px;color:#856404;">
            These may be old records (pre-invoice tracking) or recharges where UCRM was temporarily unreachable.
            The cron will auto-retry any with <strong>invoice_status = failed</strong>.
            For the rest, create the invoice manually in <strong>UCRM Org-7 → Billing → New Invoice</strong>.
            <strong style="color:#c0392b;">Do NOT re-approve — the wallet was already credited.</strong>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="stat-grid">
    <div class="stat-card orange"><div class="stat-label">Pending</div><div class="stat-value"><?= $cntPending ?></div><div class="stat-sub">Awaiting review</div></div>
    <div class="stat-card green"><div class="stat-label">Approved</div><div class="stat-value"><?= $cntApproved ?></div></div>
    <div class="stat-card red"><div class="stat-label">Rejected</div><div class="stat-value"><?= $cntRejected ?></div></div>
    <div class="stat-card teal"><div class="stat-label">Total Approved $</div><div class="stat-value" style="font-size:20px;">$<?= number_format($totalApproved, 0) ?></div></div>
</div>
<div class="kyc-card">
    <div class="kyc-card-header"><i class="bi bi-cash-coin"></i>Wallet Recharge Requests (<?= count($allRecharges) ?>)</div>
    <?php if (empty($allRecharges)): ?>
    <div style="padding:40px;text-align:center;color:#aaa;">No recharge requests yet.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="kyc-table">
        <thead><tr>
            <th>ID</th><th>Customer Name</th><th>Email</th><th>Amount</th>
            <th>Payment Proof</th><th>Note</th><th>Status</th><th>CRM Invoice</th><th>Date</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($allRecharges as $r): ?>
        <tr>
            <td>#<?= $r['id'] ?></td>
            <td>
                <strong><?= h($r['retailer_name']) ?></strong>
                <?php if($r['status']==='approved'): ?>
                <div style="font-size:11px;color:#888;">By: <?= h($r['approved_by']??'') ?> &bull; <?= h(substr($r['approved_at']??'',0,16)) ?></div>
                <?php endif; ?>
            </td>
            <td style="font-size:11px;color:#666;"><?= h($r['retailer_email']??'') ?></td>
            <td><strong style="font-size:15px;color:#1a7a3e;">$<?= number_format($r['amount'],2) ?></strong></td>
            <td>
                <?php if($r['payment_proof']): ?>
                    <?php $ext=strtolower(pathinfo($r['payment_proof'],PATHINFO_EXTENSION)); ?>
                    <?php if(in_array($ext,['jpg','jpeg','png','gif','webp'])): ?>
                    <img src="?page=serve_proof&id=<?= (int)$r['id'] ?>"
                         class="proof-thumb" alt="proof" onclick="this.requestFullscreen?this.requestFullscreen():window.open(this.src)"
                         title="Click to view full size">
                    <?php else: ?>
                    <a href="?file=<?= urlencode($r['payment_proof']) ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-pdf"></i> PDF</a>
                    <?php endif; ?>
                <?php else: ?>
                <span style="color:#ccc;font-size:11px;">No proof</span>
                <?php endif; ?>
            </td>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($r['note']??'') ?>">
                <?= h($r['note']??'—') ?>
            </td>
            <td>
                <span class="badge-<?= h($r['status']) ?>">
                    <?= $r['status']==='pending'?'&#9203;':($r['status']==='approved'?'&#9989;':'&#10060;') ?>
                    <?= ucfirst($r['status']) ?>
                </span>
                <?php if($r['status']==='rejected'&&$r['rejection_reason']): ?>
                <div style="font-size:11px;color:#dc3545;margin-top:3px;max-width:150px;"><?= h($r['rejection_reason']) ?></div>
                <?php endif; ?>
            </td>
            <td><?= h(substr($r['created_at']??'',0,16)) ?></td>
            <td style="min-width:120px;">
                <?php if ($r['status'] === 'approved'): ?>
                    <?php if (!empty($r['crm_invoice_id'])): ?>
                    <span style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700;display:inline-block;">
                        💳✓ #<?= h($r['crm_invoice_id']) ?>
                        <?php if (!empty($r['crm_invoice_number'])): ?>
                        <div style="font-size:10px;opacity:.8;"><?= h($r['crm_invoice_number']) ?></div>
                        <?php endif; ?>
                    </span>
                    <?php elseif (isset($r['crm_invoice_synced']) && $r['crm_invoice_synced'] === false): ?>
                    <span style="background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700;display:inline-block;">
                        💳✗ Failed
                        <div style="font-size:10px;">Add manually in CRM Org-7</div>
                    </span>
                    <?php else: ?>
                    <span style="background:#fff3cd;color:#856404;padding:3px 8px;border-radius:8px;font-size:11px;font-weight:700;display:inline-block;">
                        ⚠ No CRM link
                        <div style="font-size:10px;">Retailer has no ftth_crm_client_id</div>
                    </span>
                    <?php endif; ?>
                <?php else: ?>
                <span style="color:#ccc;font-size:11px;">—</span>
                <?php endif; ?>
            </td>
            <td style="min-width:130px;">
                <?php if ($r['status'] === 'pending'): ?>
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <form method="POST" onsubmit="return confirm('Approve $<?= number_format($r['amount'],2) ?> for <?= h(addslashes($r['retailer_name'])) ?>?')">
                    <?= csrfField() ?>
                        <input type="hidden" name="action" value="approve_recharge">
                        <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-success btn-block">
                            <i class="bi bi-check-circle-fill"></i> Approve
                        </button>
                    </form>
                    <button type="button" class="btn btn-sm btn-danger btn-block" onclick="toggleRejectForm(<?= $r['id'] ?>)">
                        <i class="bi bi-x-circle-fill"></i> Reject
                    </button>
                    <div id="reject-form-<?= $r['id'] ?>" style="display:none;margin-top:4px;">
                        <form method="POST">
                        <?= csrfField() ?>
                            <input type="hidden" name="action" value="reject_recharge">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <div class="input-group input-group-sm">
                                <input type="text" name="rejection_reason" class="form-control"
                                       placeholder="Rejection reason *" required maxlength="250">
                                <div class="input-group-append">
                                    <button type="submit" class="btn btn-sm btn-danger">Send</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <span style="color:#ccc;font-size:11px;">Processed</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
