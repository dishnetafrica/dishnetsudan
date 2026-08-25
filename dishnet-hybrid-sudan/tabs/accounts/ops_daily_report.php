<?php
// Tab: ops_daily_report
// Extracted from public.php on 2026-03-15
?>
<?php $apiTok2 = h($retailer['api_token'] ?? ""); ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:18px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:8px;"><i class="bi bi-bar-chart-fill" style="color:#D41C1C;"></i>Daily Ops Reports</div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Auto-generated nightly by cron. 90-day archive.</div>
    </div>
    <button onclick="loadTodayReport()" class="lte-btn primary" id="today-btn"><i class="bi bi-lightning"></i> Live Today Snapshot</button>
</div>

<!-- Today live card (hidden until loaded) -->
<div id="today-report-card" style="display:none;margin-bottom:16px;"></div>

<!-- Archive list -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:6px;"><i class="bi bi-archive" style="color:var(--primary);"></i>Report Archive</span>
        <button onclick="loadReports()" class="lte-btn ghost sm"><i class="bi bi-arrow-repeat"></i></button>
    </div>
    <div id="report-list"><div style="padding:24px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<!-- Report detail modal -->
<div class="lte-modal-bg" id="report-modal">
<div class="lte-modal" style="max-width:700px;">
    <div class="lte-modal-hd"><h3 id="report-modal-title">Daily Report</h3>
        <button onclick="lteHideModal('report-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button></div>
    <div class="lte-modal-bd" id="report-modal-body"></div>
    <div style="padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;">
        <button onclick="printReport()" class="lte-btn ghost"><i class="bi bi-printer"></i> Print</button>
        <button onclick="waReport()" class="lte-btn success"><i class="bi bi-whatsapp"></i> Share via WhatsApp</button>
    </div>
</div>
</div>

<script>
(function(){
var TK='<?= $apiTok2 ?>';
var currentReport=null;
function money(v){return '$'+parseFloat(v||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

function renderReport(r, container){
    var liveTag=r.is_live?'<span style="background:#DCFCE7;color:#15803D;border-radius:4px;padding:2px 7px;font-size:10px;font-weight:800;margin-left:6px;">LIVE</span>':'';
    var h='<div style="font-size:14px;font-weight:900;margin-bottom:12px;">'+esc(r.date)+liveTag+'</div>';
    h+='<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;">';
    var tiles=[
        ['#7C3AED','#F3E8FF','bi-reception-4',r.lte?.renewals||0,'LTE Renewals'],
        ['#2563EB','#DBEAFE','bi-cash-stack',money(r.total_revenue||0),'Total Revenue'],
        ['#059669','#D1FAE5','bi-person-plus',r.lte?.new_subs||0,'New LTE Subs'],
        ['#D97706','#FEF3C7','bi-file-earmark-text',r.kyc?.applications||0,'KYC Apps'],
    ];
    tiles.forEach(function(t){
        h+='<div style="background:'+t[1]+';border-radius:10px;padding:12px;text-align:center;">';
        h+='<i class="bi '+t[2]+'" style="color:'+t[0]+';font-size:18px;"></i>';
        h+='<div style="font-size:18px;font-weight:900;color:'+t[0]+';margin-top:4px;">'+t[3]+'</div>';
        h+='<div style="font-size:10px;font-weight:700;color:'+t[0]+';opacity:.7;text-transform:uppercase;">'+t[4]+'</div></div>';
    });
    h+='</div>';
    h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">';
    h+='<div style="background:var(--surface);border-radius:8px;padding:12px;">';
    h+='<div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;margin-bottom:8px;">📶 DishNet 4G</div>';
    h+='<div style="display:grid;gap:5px;font-size:12px;">';
    h+='<div style="display:flex;justify-content:space-between;"><span>Revenue</span><strong>'+money(r.lte?.revenue||0)+'</strong></div>';
    h+='<div style="display:flex;justify-content:space-between;"><span>Active</span><strong>'+((r.lte?.active)||'—')+'</strong></div>';
    h+='<div style="display:flex;justify-content:space-between;"><span>Suspended</span><strong style="color:var(--red);">'+((r.lte?.suspended)||0)+'</strong></div>';
    if(r.lte?.auto_suspended) h+='<div style="display:flex;justify-content:space-between;"><span>Auto-suspended</span><strong style="color:#D97706;">'+r.lte.auto_suspended+'</strong></div>';
    h+='</div></div>';
    h+='<div style="background:var(--surface);border-radius:8px;padding:12px;">';
    h+='<div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;margin-bottom:8px;">💵 Collections</div>';
    h+='<div style="display:grid;gap:5px;font-size:12px;">';
    h+='<div style="display:flex;justify-content:space-between;"><span>Revenue</span><strong>'+money(r.collections?.revenue||0)+'</strong></div>';
    h+='<div style="display:flex;justify-content:space-between;"><span>Count</span><strong>'+((r.collections?.count)||0)+'</strong></div>';
    h+='</div></div></div>';
    if(r.top_agent?.name&&r.top_agent.name!=='—'){
        h+='<div style="background:linear-gradient(135deg,#FEF3C7,#FDE68A);border-radius:8px;padding:10px 14px;display:flex;align-items:center;gap:10px;">';
        h+='<i class="bi bi-trophy-fill" style="color:#D97706;font-size:20px;"></i>';
        h+='<div><div style="font-size:11px;font-weight:700;color:#92400E;text-transform:uppercase;">Top Agent</div>';
        h+='<div style="font-size:15px;font-weight:900;color:#78350F;">'+esc(r.top_agent.name)+'</div></div>';
        h+='<div style="margin-left:auto;font-size:18px;font-weight:900;color:#D97706;">'+money(r.top_agent.revenue)+'</div></div>';
    }
    document.getElementById(container).innerHTML=h;
}

window.loadTodayReport = function(){
    var btn=document.getElementById('today-btn');
    btn.disabled=true; btn.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i>';
    fetch('?page=api&action=lte_report_today',{
          credentials:'same-origin',
          method:'POST',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        btn.disabled=false; btn.innerHTML='<i class="bi bi-lightning"></i> Live Today Snapshot';
        if(d.status!=='success') return;
        currentReport=d.data;
        var card=document.getElementById('today-report-card');
        card.style.display='';
        card.innerHTML='<div style="background:#fff;border-radius:14px;border:2px solid #7C3AED;overflow:hidden;"><div style="background:linear-gradient(135deg,#7C3AED,#4F46E5);padding:12px 16px;color:#fff;font-size:13px;font-weight:800;">Today\'s Live Snapshot — '+d.data.date+'</div><div style="padding:14px 16px;" id="today-report-inner"></div></div>';
        renderReport(d.data,'today-report-inner');
    }).catch(function(){btn.disabled=false;btn.innerHTML='<i class="bi bi-lightning"></i> Live Today Snapshot';});
};

window.loadReports = function(){
    var body=document.getElementById('report-list');
    fetch('?page=api&action=lte_daily_reports',{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        if(d.status!=='success'||!d.data.length){body.innerHTML='<div style="padding:24px;text-align:center;color:var(--text-3);">No archived reports yet. Cron generates one nightly.</div>';return;}
        var h='<table class="lte-tbl"><thead><tr><th>Date</th><th>LTE Renewals</th><th>LTE Revenue</th><th>Collections</th><th>Total Revenue</th><th>New Subs</th><th>Top Agent</th><th></th></tr></thead><tbody>';
        d.data.forEach(function(r){
            h+='<tr>';
            h+='<td style="font-weight:700;">'+esc(r.date)+'</td>';
            h+='<td>'+((r.lte?.renewals)||0)+'</td>';
            h+='<td style="color:#7C3AED;font-weight:700;">'+money(r.lte?.revenue||0)+'</td>';
            h+='<td>'+money(r.collections?.revenue||0)+' ('+((r.collections?.count)||0)+')</td>';
            h+='<td style="font-weight:800;color:var(--green);">'+money(r.total_revenue||0)+'</td>';
            h+='<td>'+((r.lte?.new_subs)||0)+'</td>';
            h+='<td style="font-size:11px;">'+esc(r.top_agent?.name||'—')+'</td>';
            h+='<td><button onclick="showReport('+JSON.stringify(r).replace(/"/g,"&quot;")+')" class="lte-btn ghost sm">View</button></td>';
            h+='</tr>';
        });
        body.innerHTML=h+'</tbody></table>';
    });
};

window.showReport = function(r){
    currentReport=r;
    document.getElementById('report-modal-title').textContent='Daily Report — '+r.date;
    document.getElementById('report-modal-body').innerHTML='<div id="report-modal-inner" style="padding:4px 0;"></div>';
    lteShowModal('report-modal');
    renderReport(r,'report-modal-inner');
};

window.printReport = function(){
    if(!currentReport) return;
    var r=currentReport;
    function money(v){return '$'+parseFloat(v||0).toLocaleString('en',{minimumFractionDigits:2});}
    var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>DishNet Daily Report '+r.date+'</title>'
        +'<style>body{font-family:Arial,sans-serif;font-size:13px;padding:32px 40px;}h2{color:#0F172A;}table{width:100%;border-collapse:collapse;margin:12px 0;}th{background:#0F172A;color:#fff;padding:6px 10px;text-align:left;}td{padding:6px 10px;border-bottom:1px solid #e5e7eb;}.sect{font-weight:700;color:#2563EB;margin:14px 0 6px;font-size:12px;text-transform:uppercase;}.total{font-size:18px;font-weight:900;color:#059669;}</style>'
        +'<scr'+'ipt>window.onload=function(){window.print();}<\/script></head><body>'
        +'<div style="display:flex;justify-content:space-between;border-bottom:2px solid #0F172A;padding-bottom:12px;margin-bottom:16px;">'
        +'<div><h2 style="margin:0;">DishNet Africa — Daily Report</h2><div style="color:#666;font-size:12px;margin-top:4px;">Date: <strong>'+r.date+'</strong> · Generated: '+r.generated_at+'</div></div>'
        +'<div style="text-align:right;"><div class="total">'+money(r.total_revenue)+'</div><div style="font-size:11px;color:#666;">Total Revenue</div></div></div>'
        +'<div class="sect">DishNet 4G</div>'
        +'<table><tr><th>Renewals</th><th>Revenue</th><th>New Subscribers</th><th>Active</th><th>Suspended</th><th>Auto-Suspended</th></tr>'
        +'<tr><td>'+((r.lte?.renewals)||0)+'</td><td>'+money(r.lte?.revenue||0)+'</td><td>'+((r.lte?.new_subs)||0)+'</td><td>'+((r.lte?.active)||0)+'</td><td>'+((r.lte?.suspended)||0)+'</td><td>'+((r.lte?.auto_suspended)||0)+'</td></tr></table>'
        +'<div class="sect">Collections (Starlink/Fiber)</div>'
        +'<table><tr><th>Count</th><th>Revenue</th></tr><tr><td>'+((r.collections?.count)||0)+'</td><td>'+money(r.collections?.revenue||0)+'</td></tr></table>'
        +(r.top_agent?.name&&r.top_agent.name!=='—'?'<div class="sect">Top Agent</div><table><tr><th>Name</th><th>Revenue</th></tr><tr><td>'+r.top_agent.name+'</td><td>'+money(r.top_agent.revenue)+'</td></tr></table>':'')
        +'<div style="margin-top:20px;font-size:10px;color:#999;text-align:center;">DishNet Africa Limited · Operations Hub</div>'
        +'</body></html>';
    var w=window.open('','_blank','width=800,height=600');
    w.document.write(html); w.document.close();
};

window.waReport = function(){
    if(!currentReport) return;
    var r=currentReport;
    function money(v){return '$'+parseFloat(v||0).toLocaleString('en',{minimumFractionDigits:2});}
    var msg='📊 *DishNet Daily Report — '+r.date+'*\n\n'
        +'📶 *LTE* — '+((r.lte?.renewals)||0)+' renewals · '+money(r.lte?.revenue||0)+'\n'
        +'💵 *Collections* — '+((r.collections?.count)||0)+' · '+money(r.collections?.revenue||0)+'\n'
        +'👤 *New Subs* — '+((r.lte?.new_subs)||0)+'\n'
        +'📋 *KYC Apps* — '+((r.kyc?.applications)||0)+'\n'
        +(r.top_agent?.name&&r.top_agent.name!=='—'?'🏆 *Top Agent* — '+r.top_agent.name+' ('+money(r.top_agent.revenue)+')\n':'')
        +'\n💰 *Total: '+money(r.total_revenue)+'*\n_DishNet Africa_';
    var url='https://wa.me/?text='+encodeURIComponent(msg);
    window.open(url,'_blank');
};

loadReports();
})();
</script>

