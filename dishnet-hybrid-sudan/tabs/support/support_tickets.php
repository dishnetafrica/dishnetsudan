<?php
// Tab: support_tickets
// Extracted from public.php on 2026-03-15
    $allTickets = $store->load('support_tickets.json') ?: [];
    $myTk = $isAdmin ? $allTickets : array_filter($allTickets, fn($t) => (int)($t['assigned_to']??0) === $retailerId);
    $tkPriorityColors = ['urgent'=>'#dc3545','high'=>'#FF9800','medium'=>'#2196F3','low'=>'#6b7280'];
    $tkStatusColors = ['open'=>['#FFEBEE','#dc3545'],'in_progress'=>['#FFF3E0','#E65100'],'resolved'=>['#E8F5E9','#28a745'],'closed'=>['#f1f5f9','#6b7280']];
    ?>

<div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:14px;"><i class="bi bi-headset" style="color:#9C27B0;margin-right:6px;"></i>Support Tickets</div>

<!-- Quick Create -->
<div class="kyc-card" style="margin-bottom:14px;">
    <div class="kyc-card-header"><i class="bi bi-plus-circle"></i> Create Ticket</div>
    <div class="kyc-card-body">
        <form method="POST" onsubmit="return confirm('Create this support ticket?')">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="create_support_ticket">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;" class="resp-grid-2">
                <div class="wiz-field"><label>Customer Name *</label><input type="text" name="tk_customer_name" required placeholder="Customer name"></div>
                <div class="wiz-field"><label>CRM ID (optional)</label><input type="text" name="tk_customer_id" placeholder="CRM ID"></div>
            </div>
            <div class="wiz-field"><label>Subject *</label><input type="text" name="tk_subject" required placeholder="Brief issue description"></div>
            <div class="wiz-field"><label>Description</label><textarea name="tk_description" rows="3" placeholder="Detailed issue..."></textarea></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;" class="resp-grid-2">
                <div class="wiz-field"><label>Priority</label>
                    <select name="tk_priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="urgent">Urgent</option></select>
                </div>
                <div class="wiz-field"><label>Category</label>
                    <select name="tk_category"><option value="billing">Billing</option><option value="connectivity" selected>Connectivity</option><option value="hardware">Hardware</option><option value="installation">Installation</option><option value="other">Other</option></select>
                </div>
            </div>
            <button type="submit" class="btn-kyc-submit" style="width:100%;padding:12px;"><i class="bi bi-headset"></i> Create Ticket</button>
        </form>
    </div>
</div>

<!-- Ticket List -->
<?php foreach (array_reverse($myTk) as $tk):
    $tsc = $tkStatusColors[$tk['status']??'open'] ?? $tkStatusColors['open'];
    $tpc = $tkPriorityColors[$tk['priority']??'medium'] ?? '#6b7280';
?>
<div style="background:#fff;border-radius:14px;padding:14px 16px;margin-bottom:8px;box-shadow:0 1px 6px rgba(0,0,0,.04);border:1px solid #f1f5f9;">
    <div style="display:flex;justify-content:space-between;align-items:start;">
        <div style="flex:1;min-width:0;">
            <div style="font-size:14px;font-weight:800;color:#1e293b;"><?= h($tk['subject']??'') ?></div>
            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h($tk['customer_name']??'') ?>
                <?php if($tk['customer_id']??''): ?> &middot; CRM: <?= h($tk['customer_id']) ?><?php endif; ?>
                &middot; <?= h($tk['category']??'') ?>
            </div>
        </div>
        <div style="display:flex;gap:4px;flex-shrink:0;">
            <span style="color:<?= $tpc ?>;font-size:10px;font-weight:700;padding:2px 6px;"><?= ucfirst($tk['priority']??'medium') ?></span>
            <span style="background:<?= $tsc[0] ?>;color:<?= $tsc[1] ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;"><?= ucfirst(str_replace('_',' ',$tk['status']??'open')) ?></span>
        </div>
    </div>
    <?php if ($tk['description']??''): ?>
    <div style="font-size:12px;color:#6b7280;margin-top:6px;padding:8px 10px;background:#f8fafc;border-radius:8px;"><?= h(substr($tk['description']??'',0,200)) ?></div>
    <?php endif; ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:8px;border-top:1px solid #f8fafc;">
        <form method="POST" style="display:inline;" onsubmit="return confirm('Change ticket status?')">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="update_ticket_status">
            <input type="hidden" name="tk_id" value="<?= $tk['id'] ?>">
            <select name="tk_status" onchange="this.form.submit()" style="font-size:11px;padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;">
                <option value="">Move to...</option>
                <option value="open" <?= ($tk['status']??'')==='open'?'disabled':'' ?>>Open</option>
                <option value="in_progress" <?= ($tk['status']??'')==='in_progress'?'disabled':'' ?>>In Progress</option>
                <option value="resolved" <?= ($tk['status']??'')==='resolved'?'disabled':'' ?>>Resolved</option>
                <option value="closed" <?= ($tk['status']??'')==='closed'?'disabled':'' ?>>Closed</option>
            </select>
        </form>
        <span style="font-size:10px;color:#9ca3af;">#<?= $tk['id'] ?> &middot; <?= h(substr($tk['created_at']??'',0,16)) ?></span>
    </div>
</div>
<?php endforeach; ?>
<?php if(empty($myTk)): ?>
<div style="text-align:center;padding:30px;color:#9ca3af;"><i class="bi bi-headset" style="font-size:36px;display:block;margin-bottom:8px;color:#d1d5db;"></i>No tickets yet</div>
<?php endif; ?>
<div style="height:80px;"></div>



