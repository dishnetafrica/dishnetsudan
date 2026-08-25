<?php
// Tab: ops_settlement
// Extracted from public.php on 2026-03-15
?>
<?php $apiTok2 = h($retailer['api_token'] ?? ""); ?>
<style>
.settled-tag{display:inline-flex;align-items:center;gap:3px;background:#D1FAE5;color:#065F46;border-radius:5px;padding:2px 7px;font-size:10px;font-weight:800;}
.unsettled-tag{display:inline-flex;align-items:center;gap:3px;background:#FEE2E2;color:#991B1B;border-radius:5px;padding:2px 7px;font-size:10px;font-weight:800;}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:18px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:8px;"><i class="bi bi-cash-coin" style="color:#059669;"></i>Agent Settlement</div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Daily per-agent net-to-DishNet after commission deduction. Mark settled when cash collected.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="date" id="settle-date" value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
        <button onclick="generateSettlement()" class="lte-btn ghost sm"><i class="bi bi-gear"></i> Generate</button>
        <button onclick="loadSettlements()" class="lte-btn primary sm"><i class="bi bi-arrow-repeat"></i> Refresh</button>
    </div>
</div>

<!-- Summary tiles -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;" id="settle-tiles">
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#fff0f0;"><i class="bi bi-cash-stack" style="color:#D41C1C;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#D41C1C;" id="settle-tot-coll">—</div><div class="hub-tile-lbl">Total Collected</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#F3E8FF;"><i class="bi bi-award" style="color:#7C3AED;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#7C3AED;" id="settle-tot-comm">—</div><div class="hub-tile-lbl">Commissions Kept</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#D1FAE5;"><i class="bi bi-building" style="color:#059669;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#059669;" id="settle-tot-net">—</div><div class="hub-tile-lbl">Net to DishNet</div></div></div>
</div>

<!-- Settlement list (by date) -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;" id="settle-body">
    <div style="padding:24px;text-align:center;color:var(--text-3);">Loading…</div>
</div>

<!-- Mark settled modal -->
<div class="lte-modal-bg" id="settle-modal">
<div class="lte-modal" style="max-width:500px;">
    <div class="lte-modal-hd"><h3>Mark Settlement</h3>
        <button onclick="lteHideModal('settle-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button></div>
    <div class="lte-modal-bd">
        <div id="settle-modal-info" style="margin-bottom:12px;font-size:13px;"></div>
        <div class="lte-field"><label>Settlement Note (optional)</label><input id="settle-note" type="text" placeholder="e.g. Cash received, bank ref #12345"></div>
        <button onclick="confirmSettle()" class="lte-btn success" style="width:100%;justify-content:center;margin-top:8px;"><i class="bi bi-check-circle"></i> Confirm Settlement</button>
    </div>
</div>
</div>

<script>
(function(){
var TK='<?= $apiTok2 ?>';
var pendingSettle=null;
function money(v){return '$'+parseFloat(v||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmt(d){return d?d.substring(0,10):'';}

window.loadSettlements = function(){
    var body=document.getElementById('settle-body');
    fetch('?page=api&action=lte_settlements',{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        if(d.status!=='success'||!d.data.length){
            body.innerHTML='<div style="padding:24px;text-align:center;color:var(--text-3);">No settlement snapshots yet. Generate one using the button above.</div>';
            return;
        }
        // Update totals from latest (today/yesterday)
        var latest=d.data[0]||{};
        document.getElementById('settle-tot-coll').textContent=money(latest.totals?.collected||0);
        document.getElementById('settle-tot-comm').textContent=money(latest.totals?.commission||0);
        document.getElementById('settle-tot-net').textContent=money(latest.totals?.net||0);

        var h='';
        d.data.forEach(function(snap){
            var allSettled=(snap.agents||[]).every(function(a){return a.settled;});
            h+='<div style="border-bottom:1px solid var(--border);">';
            // Date header
            h+='<div style="padding:10px 14px;display:flex;align-items:center;justify-content:space-between;background:var(--surface);">';
            h+='<div style="font-weight:800;font-size:13px;">'+esc(snap.date)
                +' <span style="font-size:11px;font-weight:400;color:var(--text-3);">'+(snap.agents||[]).length+' agent(s)</span></div>';
            h+='<div style="display:flex;align-items:center;gap:8px;">';
            h+='<span>'+(allSettled?'<span class="settled-tag"><i class="bi bi-check-circle"></i> All Settled</span>':'<span class="unsettled-tag"><i class="bi bi-clock"></i> Pending</span>')+'</span>';
            if(!allSettled) h+='<button onclick="markAllSettled(\''+esc(snap.date)+'\')" class="lte-btn success sm"><i class="bi bi-check-all"></i> Settle All</button>';
            h+='</div></div>';
            // Agent rows
            h+='<table class="lte-tbl" style="font-size:12px;margin:0;border-radius:0;"><thead><tr><th>Agent</th><th>Transactions</th><th>Collected</th><th>Commission Kept</th><th style="background:#DCFCE7;">Net to DishNet</th><th>Status</th><th></th></tr></thead><tbody>';
            (snap.agents||[]).forEach(function(ag){
                var agSettled=ag.settled||false;
                h+='<tr>';
                h+='<td style="font-weight:700;">'+esc(ag.name)+'</td>';
                h+='<td>'+ag.transactions+'</td>';
                h+='<td>'+money(ag.collected)+'</td>';
                h+='<td style="color:#7C3AED;font-weight:700;">'+money(ag.commission)+'</td>';
                h+='<td style="font-weight:900;color:#059669;background:#F0FDF4;">'+money(ag.net)+'</td>';
                h+='<td>'+(agSettled?'<span class="settled-tag"><i class="bi bi-check-circle"></i> Settled</span>'+(ag.settle_note?'<div style="font-size:10px;color:var(--text-3);margin-top:2px;">'+esc(ag.settle_note)+'</div>':''):'<span class="unsettled-tag">Pending</span>')+'</td>';
                if(!agSettled) h+='<td><button onclick="markAgentSettled(\''+esc(snap.date)+'\','+ag.id+',\''+esc(ag.name)+'\')" class="lte-btn ghost sm"><i class="bi bi-check"></i> Settle</button></td>';
                else h+='<td style="font-size:10px;color:var(--text-3);">'+fmt(ag.settled_at||'')+'</td>';
                h+='</tr>';
            });
            h+='</tbody></table></div>';
        });
        body.innerHTML=h;
    });
};

window.generateSettlement = function(){
    var date=document.getElementById('settle-date').value;
    if(!date){alert('Pick a date');return;}
    if(!confirm('Generate settlement snapshot for '+date+'?')) return;
    fetch('?page=api&action=lte_settlement_generate',{
          credentials:'same-origin',
          method:'POST',
        headers:{'Content-Type':'application/json','Authorization':'Bearer '+TK},
        body:JSON.stringify({date:date})
    }).then(r=>r.json()).then(function(d){
        if(d.status==='success') loadSettlements();
        else alert('Error: '+(d.message||'Failed'));
    });
};

window.markAllSettled = function(date){
    pendingSettle={date:date,agent_id:0,label:'all agents for '+date};
    document.getElementById('settle-modal-info').innerHTML='Mark <strong>all agents</strong> settled for <strong>'+esc(date)+'</strong>.<br><div style="font-size:11px;color:var(--text-3);margin-top:4px;">Total net to collect and confirm.</div>';
    document.getElementById('settle-note').value='';
    lteShowModal('settle-modal');
};

window.markAgentSettled = function(date,agentId,agentName){
    pendingSettle={date:date,agent_id:agentId,label:agentName+' on '+date};
    document.getElementById('settle-modal-info').innerHTML='Mark <strong>'+esc(agentName)+'</strong> settled for <strong>'+esc(date)+'</strong>.';
    document.getElementById('settle-note').value='';
    lteShowModal('settle-modal');
};

window.confirmSettle = function(){
    if(!pendingSettle) return;
    var note=document.getElementById('settle-note').value;
    fetch('?page=api&action=lte_mark_settled',{
          credentials:'same-origin',
          method:'POST',
        headers:{'Content-Type':'application/json','Authorization':'Bearer '+TK},
        body:JSON.stringify({date:pendingSettle.date,agent_id:pendingSettle.agent_id,note:note})
    }).then(r=>r.json()).then(function(d){
        lteHideModal('settle-modal');
        if(d.status==='success') loadSettlements();
        else alert('Error: '+(d.message||'Failed'));
    });
};
loadSettlements();
})();
</script>

