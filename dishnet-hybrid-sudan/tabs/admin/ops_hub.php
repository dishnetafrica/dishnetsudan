<?php
// Tab: ops_hub
// Extracted from public.php on 2026-03-15
?>
<?php $apiTok2 = h($retailer['api_token'] ?? ""); $lteCommRate = (float)($config['lte_commission_rate'] ?? $config['commission_rate'] ?? 5); ?>
<style>
.hub-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.hub-grid-3{grid-template-columns:repeat(3,1fr);}
@media(max-width:1100px){.hub-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.hub-grid{grid-template-columns:1fr;}}
.hub-tile{background:#fff;border-radius:14px;border:1px solid var(--border);padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-sm);transition:.15s;cursor:default;}
.hub-tile:hover{box-shadow:var(--shadow);transform:translateY(-1px);}
.hub-tile-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.hub-tile-val{font-size:24px;font-weight:900;line-height:1;color:var(--text);}
.hub-tile-lbl{font-size:11px;color:var(--text-3);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:3px;}
.hub-tile-sub{font-size:11px;color:var(--text-3);margin-top:2px;}
.hub-section{font-size:11px;font-weight:800;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;margin:20px 0 10px;display:flex;align-items:center;gap:8px;}
.hub-section::after{content:'';flex:1;height:1px;background:var(--border);}
.hub-alert{display:flex;align-items:center;gap:10px;padding:12px 16px;border-radius:10px;margin-bottom:8px;font-size:13px;}
.hub-alert-red{background:#FEE2E2;border:1px solid #FECACA;}
.hub-alert-orange{background:#FEF3C7;border:1px solid #FDE68A;}
.hub-alert-green{background:#D1FAE5;border:1px solid #A7F3D0;}
.hub-rev-card{background:linear-gradient(135deg,#0F172A,#1E3A5F);border-radius:14px;padding:20px 24px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:20px;font-weight:900;color:var(--text);">Operations Hub</div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Live snapshot — Starlink · Fiber · DishNet 4G · <span id="hub-month"></span></div>
    </div>
    <button onclick="loadHub()" class="lte-btn ghost sm" id="hub-refresh-btn"><i class="bi bi-arrow-repeat"></i> Refresh</button>
</div>

<!-- Revenue banner -->
<div class="hub-rev-card" id="hub-rev-banner" style="margin-bottom:20px;">
    <div>
        <div style="font-size:11px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.6px;font-weight:700;">Total Revenue This Month</div>
        <div style="font-size:34px;font-weight:900;margin-top:4px;" id="hub-total-rev">—</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;text-align:center;">
        <div><div style="font-size:10px;color:rgba(255,255,255,.5);text-transform:uppercase;font-weight:700;">LTE</div><div style="font-size:18px;font-weight:800;" id="hub-lte-rev">—</div></div>
        <div><div style="font-size:10px;color:rgba(255,255,255,.5);text-transform:uppercase;font-weight:700;">UCRM</div><div style="font-size:18px;font-weight:800;" id="hub-ucrm-rev">—</div></div>
        <div><div style="font-size:10px;color:rgba(255,255,255,.5);text-transform:uppercase;font-weight:700;">Collections</div><div style="font-size:18px;font-weight:800;" id="hub-colls-rev">—</div></div>
    </div>
</div>

<!-- Alerts -->
<div id="hub-alerts"></div>

<!-- LTE tiles -->
<div class="hub-section"><i class="bi bi-reception-4" style="color:#7C3AED;"></i>DishNet 4G</div>
<div class="hub-grid" id="hub-lte-tiles">
    <?php for($i=0;$i<4;$i++): ?><div class="hub-tile"><div class="hub-tile-icon" style="background:#F3E8FF;"><i class="bi bi-reception-4" style="color:#7C3AED;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#7C3AED;">—</div><div class="hub-tile-lbl">Loading…</div></div></div><?php endfor; ?>
</div>

<!-- UCRM tiles -->
<div class="hub-section"><i class="bi bi-wifi" style="color:#D41C1C;"></i>Starlink &amp; Fiber (UCRM)</div>
<div class="hub-grid" id="hub-ucrm-tiles">
    <?php for($i=0;$i<4;$i++): ?><div class="hub-tile"><div class="hub-tile-icon" style="background:#fff0f0;"><i class="bi bi-wifi" style="color:#D41C1C;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#D41C1C;">—</div><div class="hub-tile-lbl">Loading…</div></div></div><?php endfor; ?>
</div>

<!-- Ops tiles -->
<div class="hub-section"><i class="bi bi-clipboard-data" style="color:#059669;"></i>Operations</div>
<div class="hub-grid hub-grid-3" id="hub-ops-tiles">
    <?php for($i=0;$i<3;$i++): ?><div class="hub-tile"><div class="hub-tile-icon" style="background:#D1FAE5;"><i class="bi bi-graph-up" style="color:#059669;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#059669;">—</div><div class="hub-tile-lbl">Loading…</div></div></div><?php endfor; ?>
</div>

<!-- Quick actions -->
<div class="hub-section"><i class="bi bi-lightning-fill" style="color:#D97706;"></i>Quick Actions</div>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;">
    <a href="?page=dashboard&tab=lte_dashboard" class="lte-btn ghost" style="justify-content:center;text-decoration:none;"><i class="bi bi-reception-4"></i> LTE Dashboard</a>
    <a href="?page=dashboard&tab=lte_dashboard" onclick="setTimeout(function(){lteTab('queue')},400)" class="lte-btn ghost" style="justify-content:center;text-decoration:none;"><i class="bi bi-arrow-clockwise"></i> Renewal Queue</a>
    <a href="?page=dashboard&tab=lte_commissions" class="lte-btn ghost" style="justify-content:center;text-decoration:none;"><i class="bi bi-award"></i> Commissions</a>
    <a href="?page=dashboard&tab=lte_reminders" class="lte-btn ghost" style="justify-content:center;text-decoration:none;"><i class="bi bi-whatsapp" style="color:#25D366;"></i> Reminders</a>
</div>

<script>
(function(){
var TK='<?= $apiTok2 ?>';
function money(v){return '$'+parseFloat(v||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

window.loadHub = function(){
    var btn=document.getElementById('hub-refresh-btn');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i>';
    fetch('?page=api&action=unified_stats',{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        btn.disabled=false;btn.innerHTML='<i class="bi bi-arrow-repeat"></i> Refresh';
        if(d.status!=='success')return;
        var s=d.data, lte=s.lte, u=s.ucrm, ops=s.ops;
        var mth=s.month||'';
        document.getElementById('hub-month').textContent=mth;

        // Revenue banner
        var totalRev=(lte.mth_revenue||0)+(u.mth_revenue||0)+(ops.collections_mth||0);
        document.getElementById('hub-total-rev').textContent=money(totalRev);
        document.getElementById('hub-lte-rev').textContent=money(lte.mth_revenue);
        document.getElementById('hub-ucrm-rev').textContent=money(u.mth_revenue);
        document.getElementById('hub-colls-rev').textContent=money(ops.collections_mth);

        // Alerts
        var alerts='';
        if(lte.expired>0) alerts+='<div class="hub-alert hub-alert-red"><i class="bi bi-exclamation-circle-fill" style="color:#DC2626;font-size:18px;flex-shrink:0;"></i><div><strong>'+lte.expired+' LTE subscriber'+(lte.expired>1?'s':'')+' expired</strong> — renewal required. <a href="?page=dashboard&tab=lte_dashboard" style="color:#DC2626;font-weight:700;">View Queue →</a></div></div>';
        if(lte.urgent>0) alerts+='<div class="hub-alert hub-alert-orange"><i class="bi bi-clock-fill" style="color:#D97706;font-size:18px;flex-shrink:0;"></i><div><strong>'+lte.urgent+' subscriber'+(lte.urgent>1?'s':'')+' expiring in ≤3 days</strong> — send reminders now. <a href="?page=dashboard&tab=lte_reminders" style="color:#D97706;font-weight:700;">Send Reminders →</a></div></div>';
        if(u.overdue_inv>0) alerts+='<div class="hub-alert hub-alert-orange"><i class="bi bi-receipt" style="color:#D97706;font-size:18px;flex-shrink:0;"></i><div><strong>'+u.overdue_inv+' overdue invoice'+(u.overdue_inv>1?'s':'')+' in UCRM</strong></div></div>';
        if(ops.tickets_open>0) alerts+='<div class="hub-alert hub-alert-orange"><i class="bi bi-ticket-perforated" style="color:#D97706;font-size:18px;flex-shrink:0;"></i><div><strong>'+ops.tickets_open+' open support ticket'+(ops.tickets_open>1?'s':'')+' waiting</strong></div></div>';
        if(!alerts) alerts='<div class="hub-alert hub-alert-green"><i class="bi bi-check-circle-fill" style="color:#059669;font-size:18px;flex-shrink:0;"></i><strong>All clear — no urgent items</strong></div>';
        document.getElementById('hub-alerts').innerHTML=alerts;

        // LTE tiles
        var lteT=[
            ['#7C3AED','#F3E8FF','bi-people-fill',lte.total,'Total Subscribers',''],
            ['#16A34A','#DCFCE7','bi-wifi',lte.active,'Active','on Magma'],
            ['#DC2626','#FEE2E2','bi-arrow-clockwise',lte.expired+(lte.urgent||0),'Need Renewal','expired + expiring soon'],
            ['#2563EB','#DBEAFE','bi-currency-dollar',money(lte.mth_revenue),'LTE Revenue','this month'],
        ];
        document.getElementById('hub-lte-tiles').innerHTML=lteT.map(function(t){
            return '<div class="hub-tile"><div class="hub-tile-icon" style="background:'+t[1]+';"><i class="bi '+t[2]+'" style="color:'+t[0]+';font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:'+t[0]+';">'+t[3]+'</div><div class="hub-tile-lbl">'+t[4]+'</div>'+(t[5]?'<div class="hub-tile-sub">'+t[5]+'</div>':'')+'</div></div>';
        }).join('');

        // UCRM tiles
        var ucrmT=[
            ['#2563EB','#DBEAFE','bi-people',u.clients,'Total Clients','in UCRM'],
            ['#16A34A','#DCFCE7','bi-broadcast',u.active,'Active Services',''],
            ['#DC2626','#FEE2E2','bi-pause-circle',u.suspended,'Suspended','services'],
            ['#D97706','#FEF3C7','bi-receipt',u.overdue_inv,'Overdue Invoices','awaiting payment'],
        ];
        document.getElementById('hub-ucrm-tiles').innerHTML=ucrmT.map(function(t){
            return '<div class="hub-tile"><div class="hub-tile-icon" style="background:'+t[1]+';"><i class="bi '+t[2]+'" style="color:'+t[0]+';font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:'+t[0]+';">'+t[3]+'</div><div class="hub-tile-lbl">'+t[4]+'</div>'+(t[5]?'<div class="hub-tile-sub">'+t[5]+'</div>':'')+'</div></div>';
        }).join('');

        // Ops tiles
        var opsT=[
            ['#059669','#D1FAE5','bi-file-earmark-person',ops.apps_pending,'Pending Applications','awaiting activation'],
            ['#0891B2','#CFFAFE','bi-megaphone',ops.leads_open,'Open Leads','in pipeline'],
            ['#7C3AED','#F3E8FF','bi-headset',ops.tickets_open,'Open Tickets','support queue'],
        ];
        document.getElementById('hub-ops-tiles').innerHTML=opsT.map(function(t){
            return '<div class="hub-tile"><div class="hub-tile-icon" style="background:'+t[1]+';"><i class="bi '+t[2]+'" style="color:'+t[0]+';font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:'+t[0]+';">'+t[3]+'</div><div class="hub-tile-lbl">'+t[4]+'</div>'+(t[5]?'<div class="hub-tile-sub">'+t[5]+'</div>':'')+'</div></div>';
        }).join('');
    }).catch(function(){btn.disabled=false;btn.innerHTML='<i class="bi bi-arrow-repeat"></i> Refresh';});
};
loadHub();
setInterval(loadHub, 120000); // auto-refresh every 2 minutes
})();
</script>

