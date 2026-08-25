<?php
// Tab: support_dashboard — clean version with Stock Hub link
// v4.10.1
    $allTickets = $store->load('support_tickets.json') ?: [];
    $myTickets = $isAdmin ? $allTickets : array_filter($allTickets, fn($t) => (int)($t['assigned_to']??0) === $retailerId);
    $openTickets = count(array_filter($myTickets, fn($t) => ($t['status']??'open') === 'open'));
    $inProgress = count(array_filter($myTickets, fn($t) => ($t['status']??'') === 'in_progress'));
    $resolvedToday = count(array_filter($myTickets, fn($t) => ($t['status']??'') === 'resolved' && str_starts_with($t['resolved_at']??'', date('Y-m-d'))));
?>
<style>
.sup-hero{background:linear-gradient(145deg,#D41C1C,#A81515);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;position:relative;overflow:hidden;}
.sup-hero::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.06);}
.sup-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px;}
.sup-stat{background:rgba(255,255,255,.1);border-radius:10px;padding:8px 10px;text-align:center;}
.sup-stat-val{font-size:20px;font-weight:800;}
.sup-stat-label{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.5);font-weight:700;margin-top:2px;}
.sup-section{font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.8px;margin:0 0 10px;}
.sup-quick-btn{display:flex;align-items:center;gap:12px;padding:16px;background:#fff;border-radius:14px;text-decoration:none;color:#1e293b;font-weight:700;font-size:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #f1f5f9;transition:.15s;margin-bottom:10px;}
.sup-quick-btn:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);text-decoration:none;color:#1e293b;}
.sup-quick-btn i{font-size:24px;}
.sup-quick-btn .arrow{margin-left:auto;color:#CBD5E1;font-size:18px;font-weight:800;}
.sup-stock-cta{display:flex;align-items:center;gap:14px;padding:18px;background:linear-gradient(135deg,#059669,#047857);border-radius:16px;text-decoration:none;color:#fff;margin-bottom:16px;transition:.15s;position:relative;overflow:hidden;}
.sup-stock-cta::before{content:'';position:absolute;top:-20px;right:-20px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.08);}
.sup-stock-cta:hover{box-shadow:0 6px 20px rgba(5,150,105,.3);text-decoration:none;color:#fff;}
.sup-stock-cta .ico{font-size:32px;}
.sup-stock-cta h4{margin:0;font-size:16px;font-weight:800;}
.sup-stock-cta p{margin:2px 0 0;font-size:12px;color:rgba(255,255,255,.6);}
.sup-stock-cta .arrow{margin-left:auto;font-size:24px;color:rgba(255,255,255,.4);}
@media(max-width:600px){.sup-stats{grid-template-columns:repeat(2,1fr);}}
</style>

<div class="sup-hero">
    <div style="font-size:11px;color:rgba(255,255,255,.5);font-weight:700;text-transform:uppercase;letter-spacing:1px;"><?= h(ucfirst(str_replace('_',' ',$retailer['role']??'support'))) ?></div>
    <div style="font-size:22px;font-weight:800;margin-top:4px;"><?= h($retailer['name']) ?></div>
    <div class="sup-stats">
        <div class="sup-stat"><div class="sup-stat-val" style="color:#ff8a80;"><?= $openTickets ?></div><div class="sup-stat-label">Open</div></div>
        <div class="sup-stat"><div class="sup-stat-val" style="color:#ffab40;"><?= $inProgress ?></div><div class="sup-stat-label">In Progress</div></div>
        <div class="sup-stat"><div class="sup-stat-val" style="color:#69f0ae;"><?= $resolvedToday ?></div><div class="sup-stat-label">Resolved Today</div></div>
        <div class="sup-stat"><div class="sup-stat-val"><?= count($myTickets) ?></div><div class="sup-stat-label">Total</div></div>
    </div>
</div>

<!-- Stock Hub CTA — prominent, separate -->
<a href="?page=dashboard&tab=stock_hub" class="sup-stock-cta">
    <div class="ico">📦</div>
    <div>
        <h4>Stock Hub</h4>
        <p>Receive, scan, issue & track equipment</p>
    </div>
    <div class="arrow">›</div>
</a>

<div class="sup-section">Quick Actions</div>
<a href="?page=dashboard&tab=customer_lookup" class="sup-quick-btn"><i class="bi bi-search" style="color:#D41C1C;"></i> Customer Lookup <span class="arrow">›</span></a>
<a href="?page=dashboard&tab=service_status" class="sup-quick-btn"><i class="bi bi-broadcast" style="color:#28a745;"></i> Service Status <span class="arrow">›</span></a>
<a href="?page=dashboard&tab=support_tickets" class="sup-quick-btn"><i class="bi bi-headset" style="color:#9C27B0;"></i> Support Tickets <span class="arrow">›</span></a>
<a href="?page=dashboard&tab=knowledge_base" class="sup-quick-btn"><i class="bi bi-book" style="color:#E65100;"></i> Knowledge Base <span class="arrow">›</span></a>

<?php if (!empty($myTickets)): ?>
<div class="sup-section" style="margin-top:16px;">Recent Tickets</div>
<?php
$tkStatusColors = ['open'=>['#FFEBEE','#dc3545'],'in_progress'=>['#FFF3E0','#E65100'],'resolved'=>['#E8F5E9','#28a745'],'closed'=>['#f1f5f9','#6b7280']];
foreach (array_slice(array_reverse($myTickets), 0, 8) as $tk):
    $tsc = $tkStatusColors[$tk['status']??'open'] ?? $tkStatusColors['open'];
?>
<div style="background:#fff;border-radius:14px;padding:12px 14px;margin-bottom:6px;border:1px solid #f1f5f9;display:flex;gap:10px;align-items:center;">
    <div style="width:4px;height:36px;border-radius:3px;background:<?= $tsc[1] ?>;flex-shrink:0;"></div>
    <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:700;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($tk['subject']??'No subject') ?></div>
        <div style="font-size:11px;color:#6b7280;margin-top:1px;"><?= h($tk['customer_name']??'') ?> &middot; <?= h(substr($tk['created_at']??'',0,10)) ?></div>
    </div>
    <span style="background:<?= $tsc[0] ?>;color:<?= $tsc[1] ?>;padding:2px 8px;border-radius:8px;font-size:10px;font-weight:700;flex-shrink:0;"><?= ucfirst(str_replace('_',' ',$tk['status']??'open')) ?></span>
</div>
<?php endforeach; ?>
<?php else: ?>
<div style="text-align:center;padding:24px;color:#9ca3af;font-size:13px;"><i class="bi bi-headset" style="font-size:28px;display:block;margin-bottom:6px;color:#d1d5db;"></i>No tickets yet.</div>
<?php endif; ?>
<div style="height:80px;"></div>