<?php
/**
 * Stock Hub — Standalone stock management screen
 * Same clean card style as support_dashboard.
 * Internal sub-screens via JS (no page reload).
 * DishNet Hybrid v4.10.1
 */
$_stockSubTab = $_GET['stock_view'] ?? 'home';
?>
<style>
.sh-wrap{max-width:600px;margin:0 auto;}

/* Hero */
.sh-hero{background:linear-gradient(145deg,#059669,#047857);border-radius:20px;padding:20px;color:#fff;margin-bottom:20px;position:relative;overflow:hidden;}
.sh-hero::before{content:'';position:absolute;top:-40px;right:-40px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.06);}
.sh-hero-title{font-size:22px;font-weight:800;margin-top:4px;}
.sh-hero-sub{font-size:11px;color:rgba(255,255,255,.5);font-weight:700;text-transform:uppercase;letter-spacing:1px;}
.sh-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px;}
.sh-kpi{background:rgba(255,255,255,.12);border-radius:12px;padding:10px 8px;text-align:center;}
.sh-kpi-val{font-size:22px;font-weight:900;}
.sh-kpi-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.5);font-weight:700;margin-top:2px;}

/* Section labels */
.sh-section{font-size:11px;font-weight:800;color:#94A3B8;text-transform:uppercase;letter-spacing:.8px;margin:0 0 10px;}

/* Action cards — same style as support dashboard */
.sh-card{display:flex;align-items:center;gap:14px;padding:18px 16px;background:#fff;border-radius:16px;margin-bottom:10px;border:1px solid #f1f5f9;cursor:pointer;transition:.15s;text-decoration:none;color:#1E293B;}
.sh-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.06);text-decoration:none;color:#1E293B;}
.sh-card:active{transform:scale(0.98);}
.sh-card .ico{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;}
.sh-card .txt h4{margin:0;font-size:15px;font-weight:800;color:#1E293B;}
.sh-card .txt p{margin:2px 0 0;font-size:12px;color:#94A3B8;font-weight:500;}
.sh-card .arrow{margin-left:auto;color:#CBD5E1;font-size:18px;font-weight:800;flex-shrink:0;}

/* Sub-screen container */
.sh-screen{display:none;}
.sh-screen.active{display:block;}

/* Back button */
.sh-back{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:#F1F5F9;border-radius:10px;border:none;font-size:13px;font-weight:700;color:#475569;cursor:pointer;margin-bottom:16px;}
.sh-back:hover{background:#E2E8F0;}

/* Alert banner */
.sh-alert{padding:12px 16px;border-radius:12px;margin-bottom:14px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;}
.sh-alert.warn{background:#FFF7ED;color:#92400E;border:1px solid #FED7AA;}

/* ── In/Out action grid ── */
.sh-io-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;}
.sh-io-btn{display:flex;flex-direction:column;align-items:center;gap:6px;padding:18px 12px;border-radius:14px;border:2px solid;cursor:pointer;text-decoration:none;transition:.15s;}
.sh-io-btn:active{transform:scale(0.97);}
.sh-io-btn .ico{font-size:28px;}
.sh-io-btn .lbl{font-size:12px;font-weight:700;text-align:center;}
.sh-io-btn.in{background:#F0FDF4;border-color:#BBF7D0;color:#059669;}
.sh-io-btn.out{background:#FFF7ED;border-color:#FED7AA;color:#D97706;}
.sh-io-btn.ret{background:#EFF6FF;border-color:#BFDBFE;color:#2563EB;}
.sh-io-btn.danger{background:#FEF2F2;border-color:#FECACA;color:#DC2626;}

/* ── Equipment cards ── */
.sh-eq-card{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:16px;margin-bottom:10px;}
.sh-eq-serial{font-family:'Courier New',monospace;font-size:16px;font-weight:800;color:#1E293B;}
.sh-eq-meta{font-size:12px;color:#64748B;margin-top:2px;}
.sh-eq-actions{display:flex;gap:8px;margin-top:12px;}
.sh-eq-btn{flex:1;padding:10px;border-radius:10px;border:none;font-size:13px;font-weight:700;cursor:pointer;text-align:center;}
.sh-eq-btn.checkin{background:#F0FDF4;color:#059669;}
.sh-eq-btn.install{background:#EFF6FF;color:#2563EB;}

/* ── Movement log ── */
.sh-log-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #F1F5F9;}
.sh-log-dir{padding:2px 8px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;min-width:30px;text-align:center;}
.sh-log-dir.in{background:#ECFDF5;color:#059669;}
.sh-log-dir.out{background:#FFF7ED;color:#D97706;}
.sh-log-dir.int{background:#EFF6FF;color:#2563EB;}

/* ── Forms ── */
.sh-field{margin-bottom:12px;}
.sh-field label{display:block;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.sh-field input,.sh-field select{width:100%;padding:10px 14px;border:1px solid #D1D5DB;border-radius:10px;font-size:14px;box-sizing:border-box;}
.sh-field input:focus,.sh-field select:focus{border-color:#059669;outline:none;box-shadow:0 0 0 3px rgba(5,150,105,.1);}
.sh-submit{width:100%;padding:14px;border-radius:12px;border:none;font-size:15px;font-weight:800;cursor:pointer;color:#fff;background:#059669;}
.sh-submit:disabled{background:#94A3B8;}
.sh-msg{margin-top:12px;padding:10px;border-radius:8px;font-size:13px;font-weight:600;}
.sh-msg.ok{background:#ECFDF5;color:#059669;}
.sh-msg.err{background:#FEF2F2;color:#DC2626;}

/* Holdings */
.sh-hold{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:16px;margin-bottom:10px;}
.sh-hold-name{font-size:16px;font-weight:800;color:#1E293B;}
.sh-hold-meta{font-size:12px;color:#64748B;margin-top:2px;}
.sh-hold-item{font-size:12px;padding:6px 0;border-top:1px solid #F1F5F9;display:flex;justify-content:space-between;}

/* Empty state */
.sh-empty{text-align:center;padding:40px 20px;color:#94A3B8;}
.sh-empty .big{font-size:48px;margin-bottom:10px;}

@media(max-width:400px){
    .sh-kpis{grid-template-columns:repeat(2,1fr);}
}
</style>

<div class="sh-wrap">

    <!-- ═══ HOME SCREEN ═══ -->
    <div class="sh-screen active" id="shHome">
        <div class="sh-hero">
            <div class="sh-hero-sub">Stock Management</div>
            <div class="sh-hero-title">📦 Stock Hub</div>
            <div class="sh-kpis">
                <div class="sh-kpi"><div class="sh-kpi-val" id="shInStock">—</div><div class="sh-kpi-lbl">In Stock</div></div>
                <div class="sh-kpi"><div class="sh-kpi-val" style="color:#fbbf24;" id="shOut">—</div><div class="sh-kpi-lbl">Checked Out</div></div>
                <div class="sh-kpi"><div class="sh-kpi-val" style="color:#93c5fd;" id="shInst">—</div><div class="sh-kpi-lbl">Installed</div></div>
                <div class="sh-kpi"><div class="sh-kpi-val" style="color:#6ee7b7;" id="shVal">—</div><div class="sh-kpi-lbl">Value</div></div>
            </div>
        </div>
        <div id="shAlerts"></div>

        <div class="sh-section">Actions</div>

        <div class="sh-card" onclick="shGo('inout')">
            <div class="ico" style="background:#ECFDF5;">📥</div>
            <div class="txt"><h4>Stock In / Out</h4><p>Receive, issue, return equipment</p></div>
            <div class="arrow">›</div>
        </div>
        <div class="sh-card" onclick="shGo('scanner')">
            <div class="ico" style="background:#F5F3FF;">📷</div>
            <div class="txt"><h4>Scan Stock In</h4><p>Camera barcode scanning</p></div>
            <div class="arrow">›</div>
        </div>
        <div class="sh-card" onclick="shGo('equipment')">
            <div class="ico" style="background:#EFF6FF;">🧰</div>
            <div class="txt"><h4>My Equipment</h4><p>Items checked out to you</p></div>
            <div class="arrow">›</div>
        </div>
        <div class="sh-card" onclick="shGo('holdings')">
            <div class="ico" style="background:#FFF7ED;">👥</div>
            <div class="txt"><h4>Agent Holdings</h4><p>Who holds what equipment</p></div>
            <div class="arrow">›</div>
        </div>
        <div class="sh-card" onclick="shGo('log')">
            <div class="ico" style="background:#F1F5F9;">📒</div>
            <div class="txt"><h4>Movement Log</h4><p>Full audit trail</p></div>
            <div class="arrow">›</div>
        </div>
    </div>

    <!-- ═══ IN/OUT SCREEN ═══ -->
    <div class="sh-screen" id="shInout">
        <button class="sh-back" onclick="shGo('home')">← Stock Hub</button>
        <h3 style="margin:0 0 16px;font-size:18px;font-weight:800;">📥 Stock In / Out</h3>
        <div class="sh-io-grid">
            <div class="sh-io-btn in" onclick="shOpenAction('receive')"><div class="ico">📦</div><div class="lbl">Receive<br>New Stock</div></div>
            <div class="sh-io-btn out" onclick="shOpenAction('deploy')" style="background:linear-gradient(135deg,#D1FAE5,#A7F3D0);border-color:#059669;"><div class="ico">📦</div><div class="lbl" style="color:#059669;font-weight:800;">Deploy to<br>Client</div></div>
            <div class="sh-io-btn out" onclick="shOpenAction('checkout')"><div class="ico">↗️</div><div class="lbl">Issue to<br>Technician</div></div>
            <div class="sh-io-btn in" onclick="shOpenAction('checkin')"><div class="ico">↩️</div><div class="lbl">Agent<br>Return</div></div>
            <div class="sh-io-btn ret" onclick="shOpenAction('customer_return')"><div class="ico">🔄</div><div class="lbl">Customer<br>Return</div></div>
        </div>
        <button onclick="window.open(API+'stock_export_deployed','_blank')" style="margin:10px 0 0;width:100%;padding:12px;border:2px solid #059669;border-radius:12px;background:#F0FDF4;color:#059669;font-weight:700;font-size:13px;cursor:pointer;">📥 Download Deployed Equipment (CSV)</button>
        <div id="shActionForm"></div>
        <div class="sh-section">Recent Movements</div>
        <div id="shRecentLog"><div class="sh-empty">Loading...</div></div>
    </div>

    <!-- ═══ SCANNER SCREEN ═══ -->
    <div class="sh-screen" id="shScanner">
        <button class="sh-back" onclick="shGo('home')">← Stock Hub</button>
        <?php include __DIR__ . '/../admin/stock_scanner.php'; ?>
    </div>

    <!-- ═══ EQUIPMENT SCREEN ═══ -->
    <div class="sh-screen" id="shEquipment">
        <button class="sh-back" onclick="shGo('home')">← Stock Hub</button>
        <h3 style="margin:0 0 16px;font-size:18px;font-weight:800;">🧰 My Equipment</h3>
        <div id="shEqList"><div class="sh-empty">Loading...</div></div>
    </div>

    <!-- ═══ HOLDINGS SCREEN ═══ -->
    <div class="sh-screen" id="shHoldings">
        <button class="sh-back" onclick="shGo('home')">← Stock Hub</button>
        <h3 style="margin:0 0 16px;font-size:18px;font-weight:800;">👥 Agent Holdings</h3>
        <div id="shHoldList"><div class="sh-empty">Loading...</div></div>
    </div>

    <!-- ═══ MOVEMENT LOG SCREEN ═══ -->
    <div class="sh-screen" id="shLog">
        <button class="sh-back" onclick="shGo('home')">← Stock Hub</button>
        <h3 style="margin:0 0 16px;font-size:18px;font-weight:800;">📒 Movement Log</h3>
        <div id="shFullLog"><div class="sh-empty">Loading...</div></div>
    </div>

</div>

<script>
/* ── Stock Hub global stubs — defined BEFORE the IIFE so onclick attrs never
   get a ReferenceError even if the IIFE is slow or throws during init. ── */
var _shReady = false;
function shGo(s)         { if(_shReady) return window._shGoImpl(s); }
function shOpenAction(s) { if(_shReady) return window._shOpenActionImpl(s); }
function shDoReceive()   { if(_shReady) return window._shDoReceiveImpl(); }
function shDoCheckout()  { if(_shReady) return window._shDoCheckoutImpl(); }
function shEqAction(u,a,sn){ if(_shReady) return window._shEqActionImpl(u,a,sn); }
function shPickUnit(u,sn,t){ if(_shReady) return window._shPickUnitImpl(u,sn,t); }
function shSmartScan()   { if(_shReady) return window._shSmartScanImpl(); }
function shRcvCatChange(){ if(_shReady) return window._shRcvCatChangeImpl(); }
function shSearchUnit(q,sf,tid){ if(_shReady) return window._shSearchUnitImpl(q,sf,tid); }
function shFilterAgent(q){ if(_shReady) return window._shFilterAgentImpl(q); }
function shPickAgent(id,name){ if(_shReady) return window._shPickAgentImpl(id,name); }
</script>

<script>
(function(){
var API = '?page=stock_api&action=';
function esc(s){return s?String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'):''; }
function $(id){return document.getElementById(id);}
function api(action,opts,cb){
    var url=API+action,method=(opts||{}).method||'GET';
    if(method==='GET'&&opts&&opts.params){var qs=[];for(var k in opts.params)if(opts.params[k]!=null&&opts.params[k]!=='')qs.push(k+'='+encodeURIComponent(opts.params[k]));if(qs.length)url+='&'+qs.join('&');}
    var xhr=new XMLHttpRequest();xhr.open(method,url,true);
    if(method==='POST')xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload=function(){try{cb(null,JSON.parse(xhr.responseText));}catch(e){cb(e.message);}};
    xhr.onerror=function(){cb('Network error');};
    xhr.send(opts&&opts.body?JSON.stringify(opts.body):null);
}

var dirMap={inbound:'in',checkout:'out',checkin:'in',install:'out',deploy:'out','return':'in',transfer:'int',write_off:'out',adjust:'int'};
var typeLabels={inbound:'Receive',checkout:'Out→Technician',checkin:'In←Agent',install:'Deploy→Client',deploy:'Deploy→Client','return':'Return',transfer:'Transfer',write_off:'Write Off'};

// ═══ NAVIGATION ═══
window._shGoImpl = function(screen){
    document.querySelectorAll('.sh-screen').forEach(function(s){s.classList.remove('active');});
    var el = $('sh' + screen.charAt(0).toUpperCase() + screen.slice(1));
    if(!el) el = $('shHome');
    el.classList.add('active');
    window.scrollTo(0,0);
    // Load data
    if(screen==='home') loadDashboard();
    if(screen==='inout') loadRecentLog();
    if(screen==='scanner' && typeof window.scnInit === 'function') window.scnInit();
    if(screen==='equipment') loadEquipment();
    if(screen==='holdings') loadHoldings();
    if(screen==='log') loadFullLog();
};

// ═══ DASHBOARD ═══
function loadDashboard(){
    api('stock_report',{},function(err,r){
        if(err||!r||!r.data) {
            $('shInStock').textContent = '—';
            $('shOut').textContent = '—';
            $('shInst').textContent = '—';
            $('shVal').textContent = '—';
            if(err) $('shAlerts').innerHTML = '<div class="sh-alert warn">⚠️ Could not load stock data. Tap a section below to continue.</div>';
            return;
        }
        var d=r.data;
        $('shInStock').textContent = d.in_stock||0;
        $('shOut').textContent = d.checked_out||0;
        $('shInst').textContent = d.installed||0;
        $('shVal').textContent = '$'+ Math.round(d.stock_value||0).toLocaleString();
        // Alerts
        var alerts = d.low_stock_alerts||[];
        if(alerts.length){
            $('shAlerts').innerHTML = '<div class="sh-alert warn">⚠️ Low stock: '+alerts.map(function(a){return esc(a.title);}).join(', ')+'</div>';
        } else { $('shAlerts').innerHTML=''; }
    });
}

// ═══ IN/OUT ═══
function loadRecentLog(){
    api('stock_movements_log',{params:{limit:20}},function(err,r){
        if(err||!r||!r.data){$('shRecentLog').innerHTML='<div class="sh-empty">Error loading</div>';return;}
        var items=r.data.items||[];
        if(!items.length){$('shRecentLog').innerHTML='<div style="color:#94A3B8;font-size:13px;text-align:center;padding:20px;">No movements yet</div>';return;}
        renderLog(items,'shRecentLog');
    });
}

function renderLog(items,targetId){
    var h='';
    items.forEach(function(m){
        var d=dirMap[m.movement_type]||'int';
        var dc=d==='in'?'in':d==='out'?'out':'int';
        var dl=d==='in'?'IN':d==='out'?'OUT':'INT';
        h+='<div class="sh-log-row">';
        h+='<div class="sh-log-dir '+dc+'">'+dl+'</div>';
        h+='<div style="flex:1;min-width:0;">';
        h+='<div style="font-size:13px;font-weight:700;color:#1E293B;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+esc(m.serial_number||m.category_name||'—')+'</div>';
        h+='<div style="font-size:11px;color:#94A3B8;">'+(typeLabels[m.movement_type]||m.movement_type)+' · '+esc(m.performed_by_name||'')+'</div>';
        h+='</div>';
        h+='<div style="font-size:10px;color:#94A3B8;white-space:nowrap;">'+esc((m.created_at||'').slice(5,16))+'</div>';
        h+='</div>';
    });
    $(targetId).innerHTML=h;
}

// ── Action forms ──
var _cats=[], _staff=[];
window._shOpenActionImpl = function(action){
    var h='';
    if(action==='receive'){
        h+='<h4 style="font-size:15px;font-weight:800;margin:0 0 12px;">📦 Receive New Stock</h4>';
        h+='<div class="sh-field"><label>Category</label><select id="shRcvCat" onchange="shRcvCatChange()"></select></div>';
        h+='<div id="shRcvSerialWrap"><div class="sh-field"><label>Serial Number</label><div style="display:flex;gap:6px;"><input id="shRcvSerial" placeholder="Type or scan..." onkeydown="if(event.key===\'Enter\')shDoReceive()" style="flex:1;"><button type="button" onclick="shSmartScan()" style="background:#D41C1C;color:#fff;border:none;border-radius:8px;padding:0 14px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">⊟ Scan</button></div></div></div>';
        h+='<div id="shRcvQtyWrap" style="display:none;"><div class="sh-field"><label>Quantity</label><input id="shRcvQty" type="number" value="1" min="1"></div></div>';
        h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
        h+='<div class="sh-field"><label>Cost (USD)</label><input id="shRcvCost" type="number" step="0.01"></div>';
        h+='<div class="sh-field"><label>Location</label><select id="shRcvLoc"><option value="DishNet UNMISS">DishNet UNMISS</option><option value="DishNet Kololo Office">DishNet Kololo Office</option></select></div>';
        h+='</div>';
        h+='<div class="sh-field"><label>Note</label><input id="shRcvNote" placeholder="Supplier, invoice..."></div>';
        h+='<div id="shRcvCount" style="font-size:13px;color:#059669;font-weight:700;margin-bottom:8px;"></div>';
        h+='<button class="sh-submit" onclick="shDoReceive()">📥 Receive & Next</button>';
        h+='<div id="shRcvMsg"></div>';
        h+='<button class="sh-back" onclick="$(\'shActionForm\').innerHTML=\'\'" style="margin-top:12px;">✕ Close</button>';
        $('shActionForm').innerHTML=h;
        loadCats('shRcvCat');
    }
    else if(action==='deploy'){
        h+='<h4 style="font-size:15px;font-weight:800;margin:0 0 14px;">📦 Deploy to Client</h4>';
        // Serial number search
        h+='<div class="sh-field">';
        h+='<label>Serial Number <span style="font-size:11px;color:#94A3B8;font-weight:400;">(type to search)</span></label>';
        h+='<input id="shDpSearch" placeholder="e.g. SN123, router serial..." oninput="shSearchUnit(this.value,\'in_stock\',\'shDpResults\')" autocomplete="off" style="font-family:monospace;">';
        h+='</div>';
        h+='<div id="shDpResults"></div>';
        h+='<div id="shDpUnitPicked" style="display:none;padding:10px 12px;margin-bottom:10px;border:2px solid #059669;border-radius:10px;background:#F0FDF4;font-size:13px;font-weight:700;"></div>';
        // Client search
        h+='<div class="sh-field" style="margin-top:4px;">';
        h+='<label>Client <span style="font-size:11px;color:#94A3B8;font-weight:400;">(name, FTTH ID, or CRM ID)</span></label>';
        h+='<input id="shDpClientSearch" placeholder="Type client name or FTTH ID..." oninput="shCrmSearch(this.value)" autocomplete="off">';
        h+='<div id="shDpClientList" style="max-height:220px;overflow-y:auto;border:1px solid #E2E8F0;border-radius:10px;display:none;box-shadow:0 4px 12px rgba(0,0,0,.06);background:#fff;margin-top:4px;"></div>';
        h+='<div id="shDpClientPicked" style="display:none;padding:10px 12px;margin-top:6px;border:2px solid #2563EB;border-radius:10px;background:#EFF6FF;font-weight:700;font-size:14px;"></div>';
        h+='</div>';
        h+='<div class="sh-field"><label>Note</label><input id="shDpNote" placeholder="Job ref, site location..."></div>';
        h+='<button class="sh-submit" id="shDpBtn" onclick="shDoDeploy()" disabled style="margin-top:8px;background:#059669;">📦 Deploy to Client</button>';
        h+='<div id="shDpMsg" style="margin-top:10px;"></div>';
        h+='<button class="sh-back" onclick="$(\'shActionForm\').innerHTML=\'\'" style="margin-top:12px;">✕ Cancel</button>';
        $('shActionForm').innerHTML=h;
        // Dismiss client dropdown on outside click
        setTimeout(function(){
            document.addEventListener('click', function _dismissCl(e){
                var list=$('shDpClientList');
                var inp=$('shDpClientSearch');
                if(list&&inp&&!list.contains(e.target)&&e.target!==inp){
                    list.style.display='none';
                }
                if(!$('shDpClientList')){ document.removeEventListener('click',_dismissCl); }
            });
            var sf=$('shDpSearch'); if(sf) sf.focus();
        },50);
    }
    else if(action==='checkout'){
        h+='<h4 style="font-size:15px;font-weight:800;margin:0 0 14px;">↗️ Issue to Technician</h4>';
        // Serial number search
        h+='<div class="sh-field">';
        h+='<label>Serial Number <span style="font-size:11px;color:#94A3B8;font-weight:400;">(type to search)</span></label>';
        h+='<input id="shCoSearch" placeholder="e.g. SN123, EQ..." oninput="shSearchUnit(this.value,\'in_stock\',\'shCoResults\')" autocomplete="off" style="font-family:monospace;">';
        h+='</div>';
        h+='<div id="shCoResults"></div>';
        h+='<div id="shCoUnitPicked" style="display:none;padding:10px 12px;margin-bottom:10px;border:2px solid #059669;border-radius:10px;background:#F0FDF4;font-size:13px;font-weight:700;"></div>';
        // Agent search
        h+='<div class="sh-field" style="margin-top:4px;">';
        h+='<label>Agent <span style="font-size:11px;color:#94A3B8;font-weight:400;">(tap to browse, or type to filter)</span></label>';
        h+='<input id="shCoAgentSearch" placeholder="Tap here to see all agents..." oninput="shFilterAgent(this.value)" onfocus="shFilterAgent(this.value)" autocomplete="off">';
        h+='<div id="shCoAgentList" style="max-height:220px;overflow-y:auto;border:1px solid #E2E8F0;border-radius:10px;display:none;box-shadow:0 4px 12px rgba(0,0,0,.06);background:#fff;margin-top:4px;"></div>';
        h+='<div id="shCoAgentPicked" style="display:none;padding:10px 12px;margin-top:6px;border:2px solid #059669;border-radius:10px;background:#F0FDF4;font-weight:700;font-size:14px;"></div>';
        h+='</div>';
        h+='<button class="sh-submit" id="shCoBtn" onclick="shDoCheckout()" disabled style="margin-top:8px;">↗️ Issue to Technician</button>';
        h+='<div id="shCoMsg" style="margin-top:10px;"></div>';
        h+='<button class="sh-back" onclick="$(\'shActionForm\').innerHTML=\'\'" style="margin-top:12px;">✕ Cancel</button>';
        $('shActionForm').innerHTML=h;
        loadStaffData(); // pre-load staff list silently for instant show on focus
        // Dismiss agent dropdown when tapping outside
        setTimeout(function(){
            document.addEventListener('click', function _dismiss(e){
                var list=$('shCoAgentList');
                var inp=$('shCoAgentSearch');
                if(list&&inp&&!list.contains(e.target)&&e.target!==inp){
                    list.style.display='none';
                }
                if(!$('shCoAgentList')){ document.removeEventListener('click',_dismiss); }
            });
            // Auto-focus serial field
            var sf=$('shCoSearch'); if(sf) sf.focus();
        },50);
    }
    else if(action==='checkin'||action==='customer_return'){
        var label=action==='checkin'?'↩️ Agent Return':'🔄 Customer Return';
        var status=action==='checkin'?'checked_out':'installed';
        var endpoint=action==='checkin'?'stock_checkin':'stock_return';
        h+='<h4 style="font-size:15px;font-weight:800;margin:0 0 12px;">'+label+'</h4>';
        h+='<div class="sh-field"><label>Search Unit <span style="font-size:11px;color:#94A3B8;font-weight:400;">(serial or category)</span></label><input id="shRetSearch" placeholder="Start typing serial number..." oninput="shSearchUnit(this.value,\''+status+'\',\'shRetResults\')" autocomplete="off" style="font-family:monospace;"></div>';
        h+='<div id="shRetResults"></div>';
        h+='<div id="shRetMsg"></div>';
        h+='<button class="sh-back" onclick="$(\'shActionForm\').innerHTML=\'\'" style="margin-top:12px;">✕ Close</button>';
        $('shActionForm').innerHTML=h;
        // Store endpoint for when user clicks a result
        $('shActionForm').dataset.endpoint=endpoint;
    }
};

function loadCats(selId){
    api('stock_categories',{params:{active_only:1}},function(err,r){
        if(!r||!r.data)return;
        var sel=$(selId);if(!sel)return;
        sel.innerHTML='<option value="">Select...</option>';
        (r.data||[]).forEach(function(c){
            sel.innerHTML+='<option value="'+c.id+'" data-mode="'+c.track_mode+'" data-cost="'+(c.buy_price||0)+'">'+esc(c.title)+' ('+esc(c.sku||c.track_mode)+')</option>';
        });
        _cats=r.data;
    });
}
function loadStaff(selId){
    api('staff_list',{},function(err,r){
        if(!r||!r.data)return;
        var sel=$(selId);if(!sel)return;
        sel.innerHTML='<option value="">Select agent...</option>';
        (r.data||[]).filter(function(s){return s.is_active;}).forEach(function(s){
            sel.innerHTML+='<option value="'+s.id+'" data-name="'+esc(s.name)+'">'+esc(s.name)+' ('+esc(s.role||'')+')</option>';
        });
        _staff=r.data;
    });
}

// ── Agent search (replaces dropdown) ────────────────────────────────────────
function loadStaffData(cb){
    if(_staff&&_staff.length){ if(cb)cb(); return; }
    api('staff_list',{},function(err,r){
        if(!r||!r.data)return;
        _staff=r.data;
        if(cb)cb();
    });
}
window._shFilterAgentImpl=function(q){
    var list=$('shCoAgentList');if(!list)return;
    var ql=q.toLowerCase().trim();
    var all=(_staff||[]).filter(function(s){return s.is_active;});

    // If staff not loaded yet, load and retry
    if(!all.length){
        loadStaffData(function(){ shFilterAgent(q); });
        return;
    }

    // Show all when empty (on focus), filter when typing
    var filtered = ql.length < 1
        ? all
        : all.filter(function(s){
            return (s.name||'').toLowerCase().indexOf(ql)!==-1
                || (s.role||'').toLowerCase().indexOf(ql)!==-1;
          });

    if(!filtered.length){
        list.innerHTML='<div style="padding:10px 14px;font-size:13px;color:#94A3B8;">No match for "'+esc(q)+'"</div>';
        list.style.display='block';return;
    }
    var h='';
    filtered.forEach(function(s){
        h+='<div onclick="shPickAgent('+s.id+',\''+esc(s.name)+'\')" style="padding:12px 14px;border-bottom:1px solid #F1F5F9;cursor:pointer;display:flex;align-items:center;justify-content:space-between;" onmouseover="this.style.background=\'#F8FAFC\'" onmouseout="this.style.background=\'\'">';
        h+='<span style="font-size:14px;font-weight:700;color:#1E293B;">'+esc(s.name)+'</span>';
        h+='<span style="font-size:11px;color:#94A3B8;background:#F1F5F9;padding:2px 8px;border-radius:20px;">'+esc(s.role||'')+'</span>';
        h+='</div>';
    });
    list.innerHTML=h;
    list.style.display='block';
};
window._shPickAgentImpl=function(id,name){
    _coAgentId=id;_coAgentName=name;
    $('shCoAgentSearch').value=name;
    $('shCoAgentList').style.display='none';
    var hint=$('shCoAgentHint');if(hint)hint.style.display='none';
    var picked=$('shCoAgentPicked');
    if(picked){
        picked.innerHTML='👤 <span style="font-size:15px;font-weight:800;">'+esc(name)+'</span>&nbsp;<span style="font-size:11px;color:#059669;">selected ✓</span>';
        picked.style.display='flex';
    }
    if(_coUnitId&&$('shCoBtn'))$('shCoBtn').disabled=false;
};

window._shDoCheckoutImpl=function(){
    if(!_coUnitId){shMsg('shCoMsg','err','Select a unit first');return;}
    if(!_coAgentId){shMsg('shCoMsg','err','Select an agent first');return;}
    var btn=$('shCoBtn');
    if(btn){btn.disabled=true;btn.textContent='Issuing...';}
    api('stock_checkout',{method:'POST',body:{
        unit_id:_coUnitId,agent_rid:_coAgentId,
        agent_name:_coAgentName,note:'Via Stock Hub',
    }},function(err,r){
        if(err||!r||r.status==='error'){
            shMsg('shCoMsg','err',r&&r.message||err||'Failed');
            if(btn){btn.disabled=false;btn.textContent='↗️ Issue to Technician';}
            return;
        }
        shMsg('shCoMsg','ok','✅ '+esc(_coUnitSerial)+' issued to '+esc(_coAgentName));
        _coUnitId=null;_coUnitSerial='';_coAgentId=null;_coAgentName='';
        if($('shCoSearch'))$('shCoSearch').value='';
        if($('shCoResults'))$('shCoResults').innerHTML='';
        var up=$('shCoUnitPicked');if(up){up.innerHTML='';up.style.display='none';}
        if($('shCoAgentSearch'))$('shCoAgentSearch').value='';
        if($('shCoAgentList'))$('shCoAgentList').style.display='none';
        var ap=$('shCoAgentPicked');if(ap){ap.innerHTML='';ap.style.display='none';}
        var hint=$('shCoAgentHint');if(hint)hint.style.display='block';
        if(btn){btn.disabled=true;btn.textContent='↗️ Issue to Technician';}
        loadRecentLog();
    });
};

window._shRcvCatChangeImpl=function(){
    var sel=$('shRcvCat'),opt=sel.options[sel.selectedIndex];
    var mode=opt.dataset.mode||'serial';
    $('shRcvSerialWrap').style.display=mode==='serial'?'':'none';
    $('shRcvQtyWrap').style.display=mode==='quantity'?'':'none';
    $('shRcvCost').value=opt.dataset.cost||'';
};

var _rcvCount=0;
window._shDoReceiveImpl=function(){
    var sel=$('shRcvCat'),catId=parseInt(sel.value)||0;
    if(!catId){shMsg('shRcvMsg','err','Select a category');return;}
    var opt=sel.options[sel.selectedIndex], mode=opt.dataset.mode||'serial';
    if(mode==='serial'){
        var serial=($('shRcvSerial').value||'').trim();
        if(!serial){shMsg('shRcvMsg','err','Serial required');return;}
        api('stock_unit_save',{method:'POST',body:{
            category_id:catId,serial_number:serial,
            purchase_cost:parseFloat($('shRcvCost').value)||0,
            condition_grade:'new',
            location_name:$('shRcvLoc').value||'DishNet UNMISS',
            notes:$('shRcvNote').value||'',
        }},function(err,r){
            if(err||!r||r.status==='error'){shMsg('shRcvMsg','err',r&&r.message||err);return;}
            _rcvCount++;
            $('shRcvCount').textContent='✅ '+_rcvCount+' item'+ (_rcvCount>1?'s':'')+' received this session';
            shMsg('shRcvMsg','ok','✅ Received: '+serial);
            $('shRcvSerial').value='';
            $('shRcvSerial').focus();
            loadRecentLog();
        });
    } else {
        var qty=parseInt($('shRcvQty').value)||0;
        if(qty<1){shMsg('shRcvMsg','err','Quantity required');return;}
        api('stock_qty_adjust',{method:'POST',body:{
            category_id:catId,delta:qty,
            location_type:'warehouse',location_ref:'main',
            location_name:$('shRcvLoc').value||'DishNet UNMISS',
            note:$('shRcvNote').value||'',
        }},function(err,r){
            if(err||!r||r.status==='error'){shMsg('shRcvMsg','err',r&&r.message||err);return;}
            shMsg('shRcvMsg','ok','✅ Received '+qty+' units');
            loadRecentLog();
        });
    }
};

var _coUnitId=null;
var _coUnitSerial='';
var _searchTimer=null;
var _coAgentId=null, _coAgentName='';
// Deploy to client state
var _dpUnitId=null, _dpUnitSerial='';
var _dpCrmId=0, _dpCrmName='';
var _dpCrmIdx=[];
window._shSearchUnitImpl=function(q,statusFilter,targetId){
    clearTimeout(_searchTimer);
    _searchTimer=setTimeout(function(){
        if(q.length<1){$(targetId).innerHTML='';return;}
        api('stock_units',{params:{search:q,status:statusFilter,limit:10}},function(err,r){
            if(!r||!r.data){$(targetId).innerHTML='';return;}
            var items=r.data.items||[];
            if(!items.length){
                $(targetId).innerHTML='<div style="color:#94A3B8;font-size:13px;padding:10px 0;">No items found for "'+esc(q)+'"</div>';
                return;
            }
            var h='<div style="margin-bottom:8px;">';
            items.forEach(function(u){
                h+='<div style="padding:12px 14px;margin-bottom:6px;border:1px solid #E2E8F0;border-radius:12px;cursor:pointer;background:#fff;display:flex;align-items:center;justify-content:space-between;" onclick="shPickUnit('+u.id+',\''+esc(u.serial_number)+'\',\''+targetId+'\')" onmouseover="this.style.background=\'#F8FAFC\'" onmouseout="this.style.background=\'#fff\'">';
                h+='<div>';
                h+='<div style="font-family:monospace;font-weight:800;font-size:15px;color:#1E293B;">'+esc(u.serial_number)+'</div>';
                h+='<div style="font-size:11px;color:#64748B;margin-top:2px;">'+esc(u.category_name)+'</div>';
                h+='</div>';
                h+='<span style="font-size:10px;font-weight:700;padding:3px 10px;border-radius:20px;background:#ECFDF5;color:#059669;">IN STOCK</span>';
                h+='</div>';
            });
            h+='</div>';
            $(targetId).innerHTML=h;
        });
    },250);
};

window._shPickUnitImpl=function(unitId,serial,targetId){
    if(targetId==='shCoResults'){
        _coUnitId=unitId; _coUnitSerial=serial;
        $(targetId).innerHTML=''; // clear search results
        $('shCoSearch').value=serial; // fill search box with picked serial
        var picked=$('shCoUnitPicked');
        if(picked){picked.innerHTML='📦 '+esc(serial);picked.style.display='block';}
        // Enable checkout button only if agent is also picked
        if(_coAgentId&&$('shCoBtn'))$('shCoBtn').disabled=false;
    } else if(targetId==='shDpResults'){
        _dpUnitId=unitId; _dpUnitSerial=serial;
        $(targetId).innerHTML='';
        $('shDpSearch').value=serial;
        var picked=$('shDpUnitPicked');
        if(picked){picked.innerHTML='📦 '+esc(serial);picked.style.display='block';}
        if(_dpCrmId&&$('shDpBtn'))$('shDpBtn').disabled=false;
    } else if(targetId==='shRetResults'){
        // Direct action for return/checkin
        var endpoint=$('shActionForm').dataset.endpoint||'stock_checkin';
        var cond=prompt('Condition? (good / fair / damaged)','good');
        if(!cond)return;
        api(endpoint,{method:'POST',body:{unit_id:unitId,condition:cond}},function(err,r){
            if(err||!r||r.status==='error'){shMsg('shRetMsg','err',r&&r.message||err);return;}
            shMsg('shRetMsg','ok','✅ Done: '+serial);
            $('shRetSearch').value='';
            $(targetId).innerHTML='';
            loadRecentLog();
        });
    }
};

// ═══ DEPLOY TO CLIENT — CRM search + pick + submit ═══
window.shCrmSearch = function(q){
    var list=$('shDpClientList'); if(!list)return;
    q=q.trim();
    if(q.length<1){list.style.display='none';return;}

    if(!_dpCrmIdx.length){
        var xhr=new XMLHttpRequest();
        xhr.open('GET','?page=api&action=client_search_index',true);
        xhr.onload=function(){
            try{var r=JSON.parse(xhr.responseText);if(r&&Array.isArray(r.data))_dpCrmIdx=r.data;shCrmSearch(q);}catch(e){}
        };
        xhr.send();
        list.innerHTML='<div style="padding:10px 14px;font-size:13px;color:#94A3B8;">Loading clients...</div>';
        list.style.display='block';
        return;
    }

    var ql=q.toLowerCase();
    var matches=_dpCrmIdx.filter(function(c){return c.search&&c.search.indexOf(ql)!==-1;}).slice(0,8);
    if(!matches.length){
        list.innerHTML='<div style="padding:10px 14px;font-size:13px;color:#94A3B8;">No match for "'+esc(q)+'"</div>';
        list.style.display='block';return;
    }
    var h='';
    matches.forEach(function(c){
        var badge='';
        var pl=(c.plans||'').toLowerCase();
        if(pl.indexOf('starlink')!==-1) badge='<span style="font-size:9px;font-weight:800;background:#EDE7F6;color:#7B1FA2;padding:1px 6px;border-radius:4px;margin-left:4px;">Starlink</span>';
        else if(pl.indexOf('fiber')!==-1||pl.indexOf('fibre')!==-1) badge='<span style="font-size:9px;font-weight:800;background:#E3F2FD;color:#1565C0;padding:1px 6px;border-radius:4px;margin-left:4px;">Fiber</span>';
        h+='<div onclick="shPickClient(\''+esc(String(c.id))+'\',\''+esc(c.name)+'\')" '
          +'style="padding:10px 14px;border-bottom:1px solid #F1F5F9;cursor:pointer;" '
          +'onmouseover="this.style.background=\'#F8FAFC\'" onmouseout="this.style.background=\'\'">'
          +'<span style="font-size:14px;font-weight:700;color:#1E293B;">'+esc(c.name)+'</span>'+badge
          +'<div style="font-size:11px;color:#64748B;margin-top:1px;">ID: '+esc(String(c.id))+(c.phone?' · '+esc(c.phone):'')+'</div>'
          +'</div>';
    });
    list.innerHTML=h;
    list.style.display='block';
};

window.shPickClient = function(id,name){
    _dpCrmId=parseInt(id)||0;
    _dpCrmName=name;
    var inp=$('shDpClientSearch');if(inp)inp.value=name;
    var list=$('shDpClientList');if(list)list.style.display='none';
    var picked=$('shDpClientPicked');
    if(picked){
        picked.innerHTML='👤 <span style="font-size:15px;font-weight:800;">'+esc(name)+'</span>'
            +(_dpCrmId?'&nbsp;<span style="font-size:11px;color:#2563EB;">CRM #'+_dpCrmId+' ✓</span>':'');
        picked.style.display='block';
    }
    if(_dpUnitId&&$('shDpBtn'))$('shDpBtn').disabled=false;
};

window.shDoDeploy = function(){
    if(!_dpUnitId){shMsg('shDpMsg','err','Select a unit first');return;}
    if(!_dpCrmName){shMsg('shDpMsg','err','Select a client first');return;}
    var btn=$('shDpBtn');
    if(btn){btn.disabled=true;btn.textContent='Deploying...';}
    api('stock_install',{method:'POST',body:{
        unit_id:_dpUnitId,
        crm_client_id:_dpCrmId||0,
        client_name:_dpCrmName,
        note:($('shDpNote')?$('shDpNote').value:'')||'Deployed via Stock Hub',
    }},function(err,r){
        if(err||!r||r.status==='error'){
            shMsg('shDpMsg','err',r&&r.message||err);
            if(btn){btn.disabled=false;btn.textContent='📦 Deploy to Client';}
            return;
        }
        shMsg('shDpMsg','ok','✅ '+esc(_dpUnitSerial)+' deployed to '+esc(_dpCrmName));
        _dpUnitId=null;_dpUnitSerial='';_dpCrmId=0;_dpCrmName='';
        // Clear form for next deploy
        if($('shDpSearch'))$('shDpSearch').value='';
        if($('shDpUnitPicked'))$('shDpUnitPicked').style.display='none';
        if($('shDpClientSearch'))$('shDpClientSearch').value='';
        if($('shDpClientPicked'))$('shDpClientPicked').style.display='none';
        if($('shDpNote'))$('shDpNote').value='';
        if(btn){btn.disabled=true;btn.textContent='📦 Deploy to Client';}
        loadRecentLog();
    });
};

// ═══ MY EQUIPMENT ═══
function loadEquipment(){
    api('stock_units',{params:{status:'checked_out',limit:200}},function(err,r){
        if(err||!r||!r.data){$('shEqList').innerHTML='<div class="sh-empty">Error</div>';return;}
        var items=r.data.items||[];
        if(!items.length){$('shEqList').innerHTML='<div class="sh-empty"><div class="big">✅</div><b>No equipment checked out</b><br>All clear!</div>';return;}
        var h='';
        items.forEach(function(u){
            h+='<div class="sh-eq-card">';
            h+='<div class="sh-eq-serial">'+esc(u.serial_number||'—')+'</div>';
            h+='<div class="sh-eq-meta">'+esc(u.category_name)+' · $'+parseFloat(u.purchase_cost||0).toFixed(2)+'</div>';
            h+='<div class="sh-eq-actions">';
            h+='<button class="sh-eq-btn checkin" onclick="shEqAction('+u.id+',\'checkin\',\''+esc(u.serial_number)+'\')">↩️ Return</button>';
            h+='<button class="sh-eq-btn install" onclick="shEqAction('+u.id+',\'install\',\''+esc(u.serial_number)+'\')">🔧 Install</button>';
            h+='</div></div>';
        });
        $('shEqList').innerHTML=h;
    });
}
window._shEqActionImpl=function(unitId,action,serial){
    if(action==='checkin'){
        var cond=prompt('Condition? (good/fair/damaged)','good');
        if(!cond)return;
        api('stock_checkin',{method:'POST',body:{unit_id:unitId,condition:cond}},function(err,r){
            if(err||!r||r.status==='error'){alert(r&&r.message||err);return;}
            loadEquipment();
        });
    } else {
        var client=prompt('Customer name:');if(!client)return;
        var crmId=prompt('CRM ID (optional):','');
        api('stock_install',{method:'POST',body:{unit_id:unitId,client_name:client,crm_client_id:parseInt(crmId)||0}},function(err,r){
            if(err||!r||r.status==='error'){alert(r&&r.message||err);return;}
            loadEquipment();
        });
    }
};

// ═══ AGENT HOLDINGS ═══
function loadHoldings(){
    api('stock_agent_holdings',{},function(err,r){
        if(err||!r||!r.data){$('shHoldList').innerHTML='<div class="sh-empty">Error</div>';return;}
        var agents=r.data||[];
        if(!agents.length){$('shHoldList').innerHTML='<div class="sh-empty"><div class="big">📭</div>No agents hold equipment</div>';return;}
        var h='';
        agents.forEach(function(a){
            h+='<div class="sh-hold">';
            h+='<div class="sh-hold-name">👤 '+esc(a.name)+'</div>';
            h+='<div class="sh-hold-meta">'+a.items.length+' items · $'+a.total_value.toFixed(0)+'</div>';
            a.items.forEach(function(u){
                h+='<div class="sh-hold-item"><span style="font-family:monospace;font-weight:700;">'+esc(u.serial_number)+'</span><span>'+esc(u.category_name)+'</span></div>';
            });
            h+='</div>';
        });
        $('shHoldList').innerHTML=h;
    });
}

// ═══ FULL LOG ═══
function loadFullLog(){
    api('stock_movements_log',{params:{limit:100}},function(err,r){
        if(err||!r||!r.data){$('shFullLog').innerHTML='<div class="sh-empty">Error</div>';return;}
        var items=r.data.items||[];
        if(!items.length){$('shFullLog').innerHTML='<div style="color:#94A3B8;text-align:center;padding:30px;">No movements yet</div>';return;}
        renderLog(items,'shFullLog');
    });
}

// ═══ HELPERS ═══
function shMsg(id,type,text){
    $(id).innerHTML='<div class="sh-msg '+type+'">'+esc(text)+'</div>';
}

// ═══ Smart Scan — native barcode if available, else focus input ═══
window._shSmartScanImpl=function(){
    var launched = window.dishnetScan && window.dishnetScan('stock_receive', function(value, format, id){
        if(!value || format === 'CANCELLED') return;
        var inp = document.getElementById('shRcvSerial');
        if(inp){ inp.value = value; inp.style.borderColor = '#059669'; }
    });
    if(!launched){
        var inp = document.getElementById('shRcvSerial');
        if(inp) inp.focus();
    }
};

// ═══ INIT ═══
_shReady = true; // activate global stubs
var _initView='<?= addslashes($_stockSubTab) ?>';
if(_initView&&_initView!=='home') shGo(_initView); else loadDashboard();

})();
</script>
