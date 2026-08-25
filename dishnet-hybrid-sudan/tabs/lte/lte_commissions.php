<?php
// Tab: lte_commissions
// Extracted from public.php on 2026-03-15
?>
<?php
$apiTok2 = h($retailer['api_token'] ?? "");
$slRate  = (float)($config['starlink_commission_rate'] ?? $config['commission_rate'] ?? 5);
$fbRate  = (float)($config['fiber_commission_rate']    ?? $config['commission_rate'] ?? 5);
$lteRate = (float)($config['lte_commission_rate']      ?? $config['commission_rate'] ?? 5);
?>
<style>
.comm-card{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:14px;}
.svc-pill{display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:2px 8px;font-size:10px;font-weight:800;text-transform:uppercase;}
.svc-starlink{background:#fff0f0;color:#1D4ED8;}
.svc-fiber{background:#DCFCE7;color:#15803D;}
.svc-lte{background:#F3E8FF;color:#7C3AED;}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:18px;font-weight:900;color:var(--text);">Unified Agent Commissions</div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">
            Rates: <strong>Starlink <?= $slRate ?>%</strong> · <strong>Fiber <?= $fbRate ?>%</strong> · <strong>LTE <?= $lteRate ?>%</strong>
            · <a href="?page=dashboard&tab=settings" style="color:var(--primary);font-size:11px;">Change in Settings →</a>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="date" id="comm-from" value="<?= date('Y-m-01') ?>" onchange="loadComm()">
        <input type="date" id="comm-to"   value="<?= date('Y-m-t') ?>"  onchange="loadComm()">
        <button onclick="loadComm()" class="lte-btn primary sm"><i class="bi bi-search"></i> Filter</button>
    </div>
</div>

<!-- Summary tiles -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#D1FAE5;"><i class="bi bi-arrow-repeat" style="color:#059669;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#059669;" id="comm-tot-txn">—</div><div class="hub-tile-lbl">Transactions</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#fff0f0;"><i class="bi bi-cash-stack" style="color:#D41C1C;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#D41C1C;" id="comm-tot-rev">—</div><div class="hub-tile-lbl">Total Collected</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#F3E8FF;"><i class="bi bi-award" style="color:#7C3AED;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#7C3AED;" id="comm-tot-comm">—</div><div class="hub-tile-lbl">Agent Commissions</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#DCFCE7;"><i class="bi bi-building" style="color:#059669;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#059669;" id="comm-tot-net">—</div><div class="hub-tile-lbl">Net to DishNet</div></div></div>
</div>

<!-- Agent table -->
<div class="comm-card">
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;">
        <span style="font-size:13px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:6px;"><i class="bi bi-people-fill" style="color:var(--primary);"></i>Per-Agent Breakdown</span>
        <button onclick="exportCommCSV()" class="lte-btn ghost sm"><i class="bi bi-download"></i> Export CSV</button>
    </div>
    <div id="comm-body"><div style="padding:32px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<!-- Agent detail modal -->
<div class="lte-modal-bg" id="comm-detail-modal">
<div class="lte-modal" style="max-width:780px;">
    <div class="lte-modal-hd">
        <h3 id="comm-detail-title">Agent Detail</h3>
        <button onclick="lteHideModal('comm-detail-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button>
    </div>
    <div class="lte-modal-bd" id="comm-detail-body"></div>
</div>
</div>

<script>
(function(){
var TK='<?= $apiTok2 ?>';
var commData=null;
function money(v){return '$'+parseFloat(v||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmt(d){return d?d.substring(0,10):'';}
function svcPill(t){
    var cls={starlink:'svc-starlink',fiber:'svc-fiber',lte:'svc-lte'}[t]||'svc-starlink';
    var ic={starlink:'bi-broadcast',fiber:'bi-ethernet',lte:'bi-reception-4'}[t]||'bi-wifi';
    return '<span class="svc-pill '+cls+'"><i class="bi '+ic+'"></i>'+esc(t)+'</span>';
}

window.loadComm = function(){
    var from=document.getElementById('comm-from').value;
    var to  =document.getElementById('comm-to').value;
    var body=document.getElementById('comm-body');
    body.innerHTML='<div style="padding:24px;text-align:center;color:var(--text-3);"><i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;font-size:20px;"></i></div>';
    fetch('?page=api&action=lte_commission_summary&from='+from+'&to='+to,{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        if(d.status!=='success')return;
        commData=d.data;
        var t=d.data.totals, agents=d.data.agents||[];
        document.getElementById('comm-tot-txn').textContent=t.transactions;
        document.getElementById('comm-tot-rev').textContent=money(t.revenue);
        document.getElementById('comm-tot-comm').textContent=money(t.commission);
        document.getElementById('comm-tot-net').textContent=money(t.net_to_dishnet);
        if(!agents.length){body.innerHTML='<div style="padding:32px;text-align:center;color:var(--text-3);">No transactions in this period</div>';return;}
        var h='<div style="overflow-x:auto;">';
        h+='<table class="lte-tbl"><thead><tr>'
            +'<th>Agent</th>'
            +'<th style="text-align:center;">📡 Starlink</th><th style="text-align:right;">Commission</th>'
            +'<th style="text-align:center;">🔌 Fiber</th><th style="text-align:right;">Commission</th>'
            +'<th style="text-align:center;">📶 LTE</th><th style="text-align:right;">Commission</th>'
            +'<th style="text-align:right;background:#F0FDF4;">Total Collected</th>'
            +'<th style="text-align:right;background:#F3E8FF;">Commission Kept</th>'
            +'<th style="text-align:right;background:#EFF6FF;">Net to DishNet</th>'
            +'<th></th>'
            +'</tr></thead><tbody>';
        agents.forEach(function(a){
            var sl=a.starlink, fb=a.fiber, lte=a.lte, tot=a.total;
            h+='<tr>';
            h+='<td><div style="font-weight:700;">'+esc(a.agent_name)+'</div><div style="font-size:10px;color:var(--text-3);">#'+a.agent_id+'</div></td>';
            // Starlink
            h+='<td style="text-align:center;">'+(sl.collections>0?'<div style="font-weight:600;">'+money(sl.revenue)+'</div><div style="font-size:10px;color:var(--text-3);">'+sl.collections+' coll · '+sl.rate+'%</div>':'<span style="color:var(--text-3);opacity:.4;">—</span>')+'</td>';
            h+='<td style="text-align:right;color:#7C3AED;font-weight:700;">'+(sl.commission>0?money(sl.commission):'—')+'</td>';
            // Fiber
            h+='<td style="text-align:center;">'+(fb.collections>0?'<div style="font-weight:600;">'+money(fb.revenue)+'</div><div style="font-size:10px;color:var(--text-3);">'+fb.collections+' coll · '+fb.rate+'%</div>':'<span style="color:var(--text-3);opacity:.4;">—</span>')+'</td>';
            h+='<td style="text-align:right;color:#7C3AED;font-weight:700;">'+(fb.commission>0?money(fb.commission):'—')+'</td>';
            // LTE
            h+='<td style="text-align:center;">'+(lte.renewals>0?'<div style="font-weight:600;">'+money(lte.revenue)+'</div><div style="font-size:10px;color:var(--text-3);">'+lte.renewals+' ren · '+lte.rate+'%</div>':'<span style="color:var(--text-3);opacity:.4;">—</span>')+'</td>';
            h+='<td style="text-align:right;color:#7C3AED;font-weight:700;">'+(lte.commission>0?money(lte.commission):'—')+'</td>';
            // Totals
            h+='<td style="text-align:right;font-weight:800;background:#F0FDF4;">'+money(tot.revenue)+'</td>';
            h+='<td style="text-align:right;font-weight:900;color:#7C3AED;background:#F3E8FF;">'+money(tot.commission)+'</td>';
            h+='<td style="text-align:right;font-weight:800;color:#059669;background:#EFF6FF;">'+money(tot.net_to_dishnet)+'</td>';
            h+='<td><button onclick="showCommDetail('+a.agent_id+',\''+esc(a.agent_name)+'\')" class="lte-btn ghost sm">Detail</button></td>';
            h+='</tr>';
        });
        h+='</tbody></table></div>';
        body.innerHTML=h;
    }).catch(function(){body.innerHTML='<div style="padding:24px;color:var(--red);">Failed to load</div>';});
};

window.showCommDetail = function(agentId, agentName){
    var from=document.getElementById('comm-from').value;
    var to  =document.getElementById('comm-to').value;
    document.getElementById('comm-detail-title').textContent=agentName+' — Full Transaction Detail';
    document.getElementById('comm-detail-body').innerHTML='<div style="padding:24px;text-align:center;"><i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;font-size:20px;"></i></div>';
    lteShowModal('comm-detail-modal');
    fetch('?page=api&action=lte_commission_detail&agent_id='+agentId+'&from='+from+'&to='+to,{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        if(d.status!=='success')return;
        var rows=d.data.rows||[],tot=d.data.totals,rates=d.data.rates;
        var h='<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;">';
        h+='<div style="background:var(--surface);border-radius:8px;padding:10px;text-align:center;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Transactions</div><div style="font-size:20px;font-weight:900;">'+tot.transactions+'</div></div>';
        h+='<div style="background:var(--surface);border-radius:8px;padding:10px;text-align:center;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Collected</div><div style="font-size:18px;font-weight:900;color:var(--primary);">'+money(tot.revenue)+'</div></div>';
        h+='<div style="background:#F3E8FF;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:10px;color:#7C3AED;font-weight:700;text-transform:uppercase;">Commission Kept</div><div style="font-size:18px;font-weight:900;color:#7C3AED;">'+money(tot.commission)+'</div></div>';
        h+='<div style="background:#DCFCE7;border-radius:8px;padding:10px;text-align:center;"><div style="font-size:10px;color:#059669;font-weight:700;text-transform:uppercase;">Net to DishNet</div><div style="font-size:18px;font-weight:900;color:#059669;">'+money(tot.net_to_dishnet)+'</div></div>';
        h+='</div>';
        h+='<div style="font-size:11px;color:var(--text-3);margin-bottom:8px;">Rates: Starlink '+rates.starlink+'% · Fiber '+rates.fiber+'% · LTE '+rates.lte+'%</div>';
        h+='<div style="overflow-x:auto;max-height:380px;overflow-y:auto;">';
        h+='<table class="lte-tbl" style="font-size:11px;"><thead><tr><th>Date</th><th>Service</th><th>Description</th><th>Method</th><th style="text-align:right;">Amount</th><th style="text-align:right;">Commission</th><th style="text-align:right;">Net</th></tr></thead><tbody>';
        rows.forEach(function(r){
            h+='<tr>';
            h+='<td>'+fmt(r.date)+'</td>';
            h+='<td>'+svcPill(r.type)+'</td>';
            h+='<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(r.description)+'">'+esc(r.description)+'</td>';
            h+='<td><span style="background:var(--primary-lt);color:var(--primary);border-radius:4px;padding:1px 6px;font-size:10px;font-weight:700;">'+esc(r.method)+'</span></td>';
            h+='<td style="text-align:right;font-weight:700;">'+money(r.amount)+'</td>';
            h+='<td style="text-align:right;font-weight:700;color:#7C3AED;">'+money(r.commission)+'</td>';
            h+='<td style="text-align:right;font-weight:700;color:#059669;">'+money(r.net)+'</td>';
            h+='</tr>';
        });
        h+='</tbody></table></div>';
        document.getElementById('comm-detail-body').innerHTML=h;
    });
};

window.exportCommCSV = function(){
    if(!commData) return;
    var from=document.getElementById('comm-from').value;
    var to  =document.getElementById('comm-to').value;
    var rows=['Agent,Starlink Collections,Starlink Revenue,Starlink Comm,Fiber Collections,Fiber Revenue,Fiber Comm,LTE Renewals,LTE Revenue,LTE Comm,Total Revenue,Total Commission,Net to DishNet'];
    commData.agents.forEach(function(a){
        rows.push([
            a.agent_name,
            a.starlink.collections,a.starlink.revenue.toFixed(2),a.starlink.commission.toFixed(2),
            a.fiber.collections,a.fiber.revenue.toFixed(2),a.fiber.commission.toFixed(2),
            a.lte.renewals,a.lte.revenue.toFixed(2),a.lte.commission.toFixed(2),
            a.total.revenue.toFixed(2),a.total.commission.toFixed(2),a.total.net_to_dishnet.toFixed(2)
        ].join(','));
    });
    var blob=new Blob([rows.join('\n')],{type:'text/csv'});
    var a=document.createElement('a');a.href=URL.createObjectURL(blob);
    a.download='commissions_'+from+'_'+to+'.csv';a.click();
};
loadComm();
})();
</script>

