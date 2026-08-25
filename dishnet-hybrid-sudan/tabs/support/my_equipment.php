<?php
/**
 * My Equipment — Field Agent view of their checked-out stock items
 * DishNet Hybrid v4.10.0
 * PWA-friendly. Shows only items assigned to the logged-in user.
 */
?>
<style>
.meq-wrap{max-width:800px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
.meq-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.meq-header h2{margin:0;font-size:20px;font-weight:800;color:var(--text-1,#1E293B);}
.meq-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;}
.meq-stat{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:14px;text-align:center;}
.meq-stat .num{font-size:24px;font-weight:800;color:var(--primary,#2563EB);}
.meq-stat .lbl{font-size:11px;color:#94A3B8;text-transform:uppercase;font-weight:700;letter-spacing:.4px;margin-top:2px;}
.meq-card{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px;margin-bottom:10px;}
.meq-card .serial{font-size:15px;font-weight:800;color:#1E293B;margin-bottom:4px;}
.meq-card .cat{font-size:13px;color:#64748B;}
.meq-card .meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#94A3B8;}
.meq-card .meta span{display:flex;align-items:center;gap:4px;}
.meq-actions{display:flex;gap:8px;margin-top:12px;}
.meq-btn{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;}
.meq-btn.checkin{background:#ECFDF5;color:#059669;}
.meq-btn.checkin:hover{background:#D1FAE5;}
.meq-btn.install{background:#EFF6FF;color:#2563EB;}
.meq-btn.install:hover{background:#DBEAFE;}
.meq-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.meq-pill.starlink{background:#1E293B;color:#F8FAFC;}
.meq-pill.fiber{background:#7C3AED;color:#fff;}
.meq-pill.lte{background:#0EA5E9;color:#fff;}
.meq-pill.general{background:#E2E8F0;color:#475569;}
.meq-empty{text-align:center;padding:60px 20px;color:#94A3B8;}
.meq-empty .icon{font-size:48px;margin-bottom:12px;}
.meq-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
.meq-modal-bg.open{display:flex;}
.meq-modal{background:#fff;border-radius:16px;padding:24px;max-width:450px;width:95%;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.meq-modal h3{margin:0 0 16px;font-size:18px;font-weight:800;}
.meq-field{margin-bottom:12px;}
.meq-field label{display:block;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;margin-bottom:4px;}
.meq-field input,.meq-field select{width:100%;padding:8px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;}
</style>

<div class="meq-wrap">
    <div class="meq-header">
        <h2>🧰 My Equipment</h2>
        <button class="meq-btn checkin" onclick="meqRefresh()">🔄 Refresh</button>
    </div>
    <div class="meq-stats" id="meqStats"></div>
    <div id="meqList"><div style="text-align:center;padding:40px;color:#94A3B8;">Loading...</div></div>
</div>

<div class="meq-modal-bg" id="meqModal">
    <div class="meq-modal">
        <h3 id="meqModalTitle">Action</h3>
        <div id="meqModalBody"></div>
    </div>
</div>

<script>
(function(){
var API = '?page=stock_api&action=';
function esc(s){ return s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'):''; }
function $(id){ return document.getElementById(id); }

function api(action, opts, cb){
    var url = API + action;
    var method = (opts||{}).method || 'GET';
    if(method==='GET'&&opts&&opts.params){var qs=[];for(var k in opts.params)if(opts.params[k]!=null&&opts.params[k]!=='')qs.push(k+'='+encodeURIComponent(opts.params[k]));if(qs.length)url+='&'+qs.join('&');}
    var xhr=new XMLHttpRequest();
    xhr.open(method,url,true);
    if(method==='POST')xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload=function(){try{cb(null,JSON.parse(xhr.responseText));}catch(e){cb(e.message);}};
    xhr.onerror=function(){cb('Network error');};
    xhr.send(opts&&opts.body?JSON.stringify(opts.body):null);
}

window.meqRefresh = function(){
    api('stock_units', {params:{status:'checked_out',limit:200}}, function(err, r){
        if(err||!r||!r.data){$('meqList').innerHTML='<div class="meq-empty"><div class="icon">⚠️</div>Failed to load</div>';return;}
        var items = (r.data.items||[]);
        var totalVal = 0;
        items.forEach(function(u){ totalVal += parseFloat(u.purchase_cost||0); });

        $('meqStats').innerHTML = '<div class="meq-stat"><div class="num">'+items.length+'</div><div class="lbl">Items Held</div></div>'
            + '<div class="meq-stat"><div class="num">$'+totalVal.toFixed(2)+'</div><div class="lbl">Total Value</div></div>';

        if(!items.length){
            $('meqList').innerHTML = '<div class="meq-empty"><div class="icon">✅</div><strong>No equipment checked out</strong><br>All clear! You have no items assigned to you.</div>';
            return;
        }
        var h = '';
        items.forEach(function(u){
            h += '<div class="meq-card">';
            h += '<div class="serial">'+esc(u.serial_number||'—')+'</div>';
            h += '<div class="cat">'+esc(u.category_name)+' <span class="meq-pill '+esc(u.service_type)+'">'+esc(u.service_type)+'</span></div>';
            h += '<div class="meta">';
            h += '<span>📦 '+esc(u.condition_grade||'good')+'</span>';
            h += '<span>💰 $'+parseFloat(u.purchase_cost||0).toFixed(2)+'</span>';
            if(u.updated_at) h += '<span>📅 '+esc(u.updated_at.slice(0,10))+'</span>';
            h += '</div>';
            h += '<div class="meq-actions">';
            h += '<button class="meq-btn checkin" onclick="meqCheckin('+u.id+',\''+esc(u.serial_number)+'\')">↩️ Return to Warehouse</button>';
            h += '<button class="meq-btn install" onclick="meqInstall('+u.id+',\''+esc(u.serial_number)+'\')">🔧 Mark Installed</button>';
            h += '</div></div>';
        });
        $('meqList').innerHTML = h;
    });
};

window.meqCheckin = function(unitId, serial){
    var h = '<div class="meq-field"><label>Condition</label><select id="meqCond"><option value="good">Good</option><option value="fair">Fair</option><option value="damaged">Damaged</option></select></div>';
    h += '<div class="meq-field"><label>Note</label><input id="meqNote" placeholder="Optional"></div>';
    h += '<div style="display:flex;gap:8px;margin-top:16px;">';
    h += '<button class="meq-btn checkin" onclick="meqDoCheckin('+unitId+')">✅ Confirm Return</button>';
    h += '<button class="meq-btn" style="background:#F1F5F9;color:#475569;" onclick="document.getElementById(\'meqModal\').classList.remove(\'open\')">Cancel</button>';
    h += '</div>';
    $('meqModalTitle').textContent = '↩️ Return: ' + serial;
    $('meqModalBody').innerHTML = h;
    $('meqModal').classList.add('open');
};

window.meqDoCheckin = function(unitId){
    api('stock_checkin', {method:'POST', body:{unit_id:unitId, condition:$('meqCond').value, note:$('meqNote').value}}, function(err, r){
        if(err||!r||r.status==='error'){alert('Error: '+(r&&r.message||err));return;}
        $('meqModal').classList.remove('open');
        meqRefresh();
    });
};

window.meqInstall = function(unitId, serial){
    var h = '<div class="meq-field"><label>Customer CRM ID</label><input id="meqCrmId" type="number" placeholder="e.g. 851"></div>';
    h += '<div class="meq-field"><label>Customer Name</label><input id="meqClient" placeholder="Customer name"></div>';
    h += '<div class="meq-field"><label>Job ID (optional)</label><input id="meqJob" type="number" placeholder="From scheduling"></div>';
    h += '<div class="meq-field"><label>Note</label><input id="meqInstNote" placeholder="Installation notes"></div>';
    h += '<div style="display:flex;gap:8px;margin-top:16px;">';
    h += '<button class="meq-btn install" onclick="meqDoInstall('+unitId+')">✅ Confirm Install</button>';
    h += '<button class="meq-btn" style="background:#F1F5F9;color:#475569;" onclick="document.getElementById(\'meqModal\').classList.remove(\'open\')">Cancel</button>';
    h += '</div>';
    $('meqModalTitle').textContent = '🔧 Install: ' + serial;
    $('meqModalBody').innerHTML = h;
    $('meqModal').classList.add('open');
};

window.meqDoInstall = function(unitId){
    api('stock_install', {method:'POST', body:{
        unit_id: unitId,
        crm_client_id: parseInt($('meqCrmId').value) || 0,
        client_name: $('meqClient').value,
        job_id: parseInt($('meqJob').value) || 0,
        note: $('meqInstNote').value,
    }}, function(err, r){
        if(err||!r||r.status==='error'){alert('Error: '+(r&&r.message||err));return;}
        $('meqModal').classList.remove('open');
        meqRefresh();
    });
};

meqRefresh();
})();
</script>
