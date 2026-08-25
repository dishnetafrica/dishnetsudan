<?php
/**
 * Stock In/Out Register — Logbook-style with enforced chain rules
 * DishNet Hybrid v4.10.1
 *
 * Chain rules enforced:
 *   IN:  receive (new stock), return (from customer), checkin (from agent)
 *   OUT: checkout (to agent), direct_install (to customer), write_off
 *
 * Valid transitions:
 *   in_stock    → checkout | direct_install | write_off
 *   checked_out → checkin | install | transfer
 *   installed   → return
 *   returned    → checkout | direct_install | write_off (re-deploy)
 *   damaged     → write_off | repair (→ in_stock)
 *
 * Bidal (support_leader) is primary operator.
 */
?>
<style>
.sio-wrap{max-width:1100px;margin:0 auto;}

/* Quick Action Cards */
.sio-actions{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;}
.sio-action-group{background:#fff;border:1px solid #E2E8F0;border-radius:14px;padding:20px;}
.sio-action-group h3{margin:0 0 14px;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px;}
.sio-action-group h3 .badge{font-size:11px;padding:2px 10px;border-radius:20px;font-weight:700;}
.sio-action-group h3 .badge.in{background:#ECFDF5;color:#059669;}
.sio-action-group h3 .badge.out{background:#FFF7ED;color:#D97706;}
.sio-action-btns{display:flex;flex-wrap:wrap;gap:8px;}
.sio-action-btn{padding:10px 16px;border-radius:10px;font-size:13px;font-weight:700;border:2px solid transparent;cursor:pointer;display:flex;align-items:center;gap:6px;transition:all .15s;}
.sio-action-btn.in{background:#F0FDF4;color:#059669;border-color:#BBF7D0;}
.sio-action-btn.in:hover{background:#DCFCE7;border-color:#86EFAC;}
.sio-action-btn.out{background:#FFF7ED;color:#D97706;border-color:#FED7AA;}
.sio-action-btn.out:hover{background:#FFEDD5;border-color:#FDBA74;}
.sio-action-btn.danger{background:#FEF2F2;color:#DC2626;border-color:#FECACA;}
.sio-action-btn.danger:hover{background:#FEE2E2;}

/* Logbook */
.sio-logbook{background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;}
.sio-logbook-header{padding:16px 20px;border-bottom:2px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.sio-logbook-header h3{margin:0;font-size:16px;font-weight:800;color:#1E293B;}
.sio-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
.sio-filters select,.sio-filters input{padding:6px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:12px;}

.sio-log-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.sio-log-tbl th{background:#F8FAFC;padding:10px 14px;text-align:left;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid #E2E8F0;white-space:nowrap;}
.sio-log-tbl td{padding:10px 14px;border-bottom:1px solid #F1F5F9;vertical-align:middle;}
.sio-log-tbl tr:hover td{background:#F8FAFC;}

/* Direction badges */
.sio-dir{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;}
.sio-dir.in{background:#ECFDF5;color:#059669;}
.sio-dir.out{background:#FFF7ED;color:#D97706;}
.sio-dir.internal{background:#EFF6FF;color:#2563EB;}

/* Type pill */
.sio-type{font-size:11px;font-weight:700;color:#475569;background:#F1F5F9;padding:2px 8px;border-radius:6px;}

/* Arrow chain */
.sio-chain{display:flex;align-items:center;gap:4px;font-size:12px;color:#64748B;}
.sio-chain .loc{max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sio-chain .arrow{color:#94A3B8;font-weight:800;}

/* Modal */
.sio-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
.sio-modal-bg.open{display:flex;}
.sio-modal{background:#fff;border-radius:16px;padding:24px;max-width:560px;width:95%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.sio-modal h3{margin:0 0 16px;font-size:18px;font-weight:800;color:#1E293B;}
.sio-field{margin-bottom:14px;}
.sio-field label{display:block;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;}
.sio-field input,.sio-field select,.sio-field textarea{width:100%;padding:9px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;box-sizing:border-box;}
.sio-field input:focus,.sio-field select:focus{border-color:var(--primary,#2563EB);outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.sio-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.sio-btn{padding:10px 20px;border-radius:10px;font-size:14px;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.sio-btn.primary{background:var(--primary,#2563EB);color:#fff;}
.sio-btn.primary:hover{background:#1D4ED8;}
.sio-btn.success{background:#059669;color:#fff;}
.sio-btn.success:hover{background:#047857;}
.sio-btn.secondary{background:#F1F5F9;color:#475569;border:1px solid #D1D5DB;}
.sio-btn:disabled{opacity:.5;cursor:not-allowed;}
.sio-btn-row{display:flex;gap:8px;margin-top:20px;justify-content:flex-end;}

/* Chain info box */
.sio-chain-info{background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#1E40AF;}
.sio-chain-info strong{display:block;margin-bottom:2px;}

/* Unit search result */
.sio-unit-card{background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px;margin-top:8px;font-size:13px;}
.sio-unit-card .serial{font-weight:800;font-size:15px;font-family:'Courier New',monospace;color:#1E293B;}
.sio-unit-card .meta{color:#64748B;margin-top:4px;font-size:12px;}

.sio-empty{text-align:center;padding:40px;color:#94A3B8;font-size:14px;}

@media(max-width:768px){
    .sio-actions{grid-template-columns:1fr;}
    .sio-row{grid-template-columns:1fr;}
}
</style>

<div class="sio-wrap">
    <!-- ═══ QUICK ACTIONS ═══ -->
    <div class="sio-actions">
        <div class="sio-action-group">
            <h3>📥 Stock IN <span class="badge in">RECEIVE</span></h3>
            <div class="sio-action-btns">
                <button class="sio-action-btn in" onclick="sioOpen('receive')">📦 Receive New Stock</button>
                <button class="sio-action-btn in" onclick="sioOpen('checkin')">↩️ Agent Return</button>
                <button class="sio-action-btn in" onclick="sioOpen('customer_return')">🔄 Customer Return</button>
            </div>
        </div>
        <div class="sio-action-group">
            <h3>📤 Stock OUT <span class="badge out">DEPLOY</span></h3>
            <div class="sio-action-btns">
                <button class="sio-action-btn out" onclick="sioOpen('direct_install')" style="background:linear-gradient(135deg,#059669,#047857);font-weight:700;">📦 Deploy to Client</button>
                <button class="sio-action-btn out" onclick="sioOpen('checkout')" style="opacity:0.8;font-size:12px;">↗️ Issue to Technician</button>
                <button class="sio-action-btn danger" onclick="sioOpen('write_off')">🗑️ Write Off</button>
            </div>
            <button onclick="window.open(API+'stock_export_deployed','_blank')" style="margin-top:8px;width:100%;padding:10px;border:2px solid #059669;border-radius:10px;background:#F0FDF4;color:#059669;font-weight:700;font-size:13px;cursor:pointer;">📥 Download Deployed Equipment (Excel)</button>
        </div>
    </div>

    <!-- ═══ LOGBOOK ═══ -->
    <div class="sio-logbook">
        <div class="sio-logbook-header">
            <h3>📒 Stock Register</h3>
            <div class="sio-filters">
                <input id="sioSearch" placeholder="🔍 Serial / customer..." oninput="sioLoadLog()" style="min-width:160px;">
                <select id="sioDir" onchange="sioLoadLog()"><option value="">All</option><option value="in">IN only</option><option value="out">OUT only</option></select>
                <select id="sioType" onchange="sioLoadLog()"><option value="">All Types</option><option value="inbound">Receive</option><option value="checkout">Check Out</option><option value="checkin">Check In</option><option value="install">Install</option><option value="return">Return</option><option value="transfer">Transfer</option><option value="write_off">Write Off</option></select>
                <input type="date" id="sioFrom" onchange="sioLoadLog()">
                <input type="date" id="sioTo" onchange="sioLoadLog()">
                <button class="sio-btn secondary" style="padding:6px 12px;font-size:12px;" onclick="sioLoadLog()">🔄</button>
            </div>
        </div>
        <table class="sio-log-tbl">
            <thead><tr>
                <th>Date/Time</th><th>Dir</th><th>Type</th><th>Item</th><th>Chain</th><th>By</th><th>Note</th>
            </tr></thead>
            <tbody id="sioLogBody"><tr><td colspan="7" class="sio-empty">Loading...</td></tr></tbody>
        </table>
    </div>
</div>

<!-- ═══ ACTION MODAL ═══ -->
<div class="sio-modal-bg" id="sioModal">
    <div class="sio-modal">
        <h3 id="sioModalTitle">Action</h3>
        <div id="sioChainInfo"></div>
        <div id="sioModalBody"></div>
        <div id="sioModalResult" style="margin-top:12px;"></div>
    </div>
</div>

<script>
/* Stock InOut global stubs — defined before IIFE so modal onclicks never fail */
var _sioReady = false;
var _sioUnitId = null;
function sioOpen(a)               { if(_sioReady) return window._sioOpenImpl(a); }
function sioFilterAgent(q)        { if(_sioReady) return window.sioFilterAgent(q); }
function sioPickAgent(id,nm)      { if(_sioReady) return window.sioPickAgent(id,nm); }
function sioPickCoUnit(id,sn,cat) { if(_sioReady) return window.sioPickCoUnit(id,sn,cat); }
function sioDoCheckout()          { if(_sioReady) return window.sioDoCheckout(); }
function closeModal()             { if(_sioReady) return window._closeModalImpl(); }
function sioRcvCatChange()        { if(_sioReady) return window.sioRcvCatChange(); }
function sioDoReceive()           { if(_sioReady) return window.sioDoReceive(); }
function sioSearchUnit(a,s)       { if(_sioReady) return window.sioSearchUnit(a,s); }
function sioActOnUnit(u,a,sn)     { if(_sioReady) return window.sioActOnUnit(u,a,sn); }
function sioSearchForCheckout()   { if(_sioReady) return window.sioSearchForCheckout(); }
function sioSearchForDirect()     { if(_sioReady) return window.sioSearchForDirect(); }
function sioPickDiUnit(id,sn,cat) { if(_sioReady) return window.sioPickDiUnit(id,sn,cat); }
function sioDoDirectInstall()     { if(_sioReady) return window.sioDoDirectInstall(); }
function sioCrmSearch(q)          { if(_sioReady) return window.sioCrmSearch(q); }
function sioPickCrmClient(id,nm)  { if(_sioReady) return window.sioPickCrmClient(id,nm); }
function sioLoadLog()             { if(_sioReady) return window.sioLoadLog(); }
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

// Movement direction mapping
var DIR_MAP = {
    inbound:'in', checkout:'out', checkin:'in', install:'out',
    'return':'in', transfer:'internal', adjust:'internal', write_off:'out'
};
var DIR_LABEL = {in:'📥 IN', out:'📤 OUT', internal:'🔄'};
var TYPE_LABELS = {
    inbound:'Receive', checkout:'Check Out', checkin:'Check In',
    install:'Install', 'return':'Return', transfer:'Transfer',
    adjust:'Adjust', write_off:'Write Off'
};
var CHAIN_RULES = {
    in_stock:    ['checkout','direct_install','transfer','write_off'],
    returned:    ['checkout','direct_install','transfer','write_off'],
    checked_out: ['checkin','install','transfer'],
    installed:   ['customer_return'],
    damaged:     ['write_off','repair'],
};

// ═══ LOGBOOK ═══
window.sioLoadLog = function(){
    var dir  = $('sioDir').value;
    var type = $('sioType').value;
    var q    = $('sioSearch') ? $('sioSearch').value.trim() : '';

    var params = {
        movement_type: type,
        date_from: $('sioFrom').value,
        date_to:   $('sioTo').value,
        limit: 150
    };
    if(q) params.search = q;
    $('sioLogBody').innerHTML = '<tr><td colspan="7" class="sio-empty" style="padding:20px;">Loading...</td></tr>';
    api('stock_movements_log',{params:params},function(err,r){
        if(err||!r||!r.data){$('sioLogBody').innerHTML='<tr><td colspan="7" class="sio-empty">Error: '+esc(err||(r&&r.message)||'')+'</td></tr>';return;}
        var items = r.data.items||[];
        if(dir){ items = items.filter(function(m){return DIR_MAP[m.movement_type]===dir;}); }
        if(!items.length){$('sioLogBody').innerHTML='<tr><td colspan="7" class="sio-empty">No movements found</td></tr>';return;}
        var h='';
        items.forEach(function(m){
            var d = DIR_MAP[m.movement_type]||'internal';
            var dt = m.created_at||'';
            if(dt.length>16) dt = dt.slice(0,16).replace('T',' ');
            h += '<tr>';
            h += '<td style="white-space:nowrap;font-size:12px;">'+esc(dt)+'</td>';
            h += '<td><span class="sio-dir '+d+'">'+DIR_LABEL[d]+'</span></td>';
            h += '<td><span class="sio-type">'+(TYPE_LABELS[m.movement_type]||m.movement_type)+'</span></td>';
            h += '<td><strong>'+esc(m.serial_number||m.category_name||'—')+'</strong>'+(m.quantity>1?' ×'+m.quantity:'')+'</td>';
            h += '<td><div class="sio-chain">';
            if(m.from_location_name) h += '<span class="loc" title="'+esc(m.from_location_name)+'">'+esc(m.from_location_name)+'</span><span class="arrow">→</span>';
            h += '<span class="loc" title="'+esc(m.to_location_name||'')+'">'+esc(m.to_location_name||'—')+'</span>';
            h += '</div></td>';
            h += '<td>'+esc(m.performed_by_name||'—')+'</td>';
            h += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(m.note||'')+'">'+esc(m.note||'—')+'</td>';
            h += '</tr>';
        });
        $('sioLogBody').innerHTML = h;
    });
};
        if(err||!r||!r.data){$('sioLogBody').innerHTML='<tr><td colspan="7" class="sio-empty">Error: '+esc(err||(r&&r.message)||'')+'</td></tr>';return;}
        var items = r.data.items||[];
        // Client-side direction filter
        if(dir){
            items = items.filter(function(m){return DIR_MAP[m.movement_type]===dir;});
        }
        if(!items.length){$('sioLogBody').innerHTML='<tr><td colspan="7" class="sio-empty">No movements found</td></tr>';return;}
        var h='';
        items.forEach(function(m){
            var d = DIR_MAP[m.movement_type]||'internal';
            var dt = m.created_at||'';
            if(dt.length>16) dt = dt.slice(0,16).replace('T',' ');
            h += '<tr>';
            h += '<td style="white-space:nowrap;font-size:12px;">'+esc(dt)+'</td>';
            h += '<td><span class="sio-dir '+d+'">'+DIR_LABEL[d]+'</span></td>';
            h += '<td><span class="sio-type">'+(TYPE_LABELS[m.movement_type]||m.movement_type)+'</span></td>';
            h += '<td><strong>'+esc(m.serial_number||m.category_name||'—')+'</strong>'+(m.quantity>1?' ×'+m.quantity:'')+'</td>';
            h += '<td><div class="sio-chain">';
            if(m.from_location_name) h += '<span class="loc" title="'+esc(m.from_location_name)+'">'+esc(m.from_location_name)+'</span><span class="arrow">→</span>';
            h += '<span class="loc" title="'+esc(m.to_location_name||'')+'">'+esc(m.to_location_name||'—')+'</span>';
            h += '</div></td>';
            h += '<td>'+esc(m.performed_by_name||'—')+'</td>';
            h += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(m.note||'')+'">'+esc(m.note||'—')+'</td>';
            h += '</tr>';
        });
        $('sioLogBody').innerHTML = h;
    });
};

// ═══ ACTION MODALS ═══
window._sioOpenImpl = function(action){
    $('sioModalResult').innerHTML = '';
    var chain = CHAIN_RULES;
    var h = '';
    var title = '';
    var chainInfo = '';

    switch(action){
    case 'receive':
        title = '📥 Receive New Stock';
        chainInfo = '<strong>Chain: Supplier → Warehouse</strong> New items entering the system for the first time.';
        h = buildReceiveForm();
        break;
    case 'checkin':
        title = '↩️ Agent Return (Check In)';
        chainInfo = '<strong>Chain: Field Agent → Warehouse</strong> Agent returning unused equipment. Only items currently checked out to an agent.';
        h = buildUnitSearchForm('checkin', 'checked_out', 'Search by serial number (agent\'s items)...');
        break;
    case 'customer_return':
        title = '🔄 Customer Return';
        chainInfo = '<strong>Chain: Customer → Warehouse</strong> Equipment retrieved from a customer site. Only items currently installed.';
        h = buildUnitSearchForm('customer_return', 'installed', 'Search by serial or customer name...');
        break;
    case 'checkout':
        title = '↗️ Issue to Technician';
        chainInfo = '<strong>Chain: Warehouse → Technician</strong> Equipment given to a technician for later installation.';
        h = buildCheckoutForm();
        break;
    case 'direct_install':
        title = '📦 Deploy to Client';
        chainInfo = '<strong>Chain: Warehouse → Client</strong> Equipment deployed directly to a client site. Search by client name or CRM ID.';
        h = buildDirectInstallForm();
        break;
    case 'write_off':
        title = '🗑️ Write Off';
        chainInfo = '<strong>Chain: Any → Written Off</strong> Equipment that is lost, stolen, or damaged beyond repair.';
        h = buildUnitSearchForm('write_off', '', 'Search by serial number...');
        break;
    }

    $('sioModalTitle').textContent = title;
    $('sioChainInfo').innerHTML = chainInfo ? '<div class="sio-chain-info">'+chainInfo+'</div>' : '';
    $('sioModalBody').innerHTML = h;
    $('sioModal').classList.add('open');
};

function closeModal(){window._closeModalImpl();}
window._closeModalImpl = function(){
    $('sioModal').classList.remove('open');
    // Reset agent/unit state when modal closes
    _sioAgentId=0; _sioAgentName=''; _coUnitId=null; _sioUnitId=null; _diUnitId=null;
};

// Click outside modal to close
document.getElementById('sioModal').addEventListener('click', function(e){
    if(e.target === this) window._closeModalImpl();
});

// ── RECEIVE FORM ──
function buildReceiveForm(){
    return '<div class="sio-field"><label>Category *</label><select id="sioRcvCat" onchange="sioRcvCatChange()"></select></div>'
        + '<div id="sioRcvSerialWrap"><div class="sio-field"><label>Serial Number *</label><input id="sioRcvSerial" placeholder="Scan or type serial..."></div></div>'
        + '<div id="sioRcvQtyWrap" style="display:none;"><div class="sio-field"><label>Quantity *</label><input id="sioRcvQty" type="number" value="1" min="1"></div></div>'
        + '<div class="sio-row">'
        + '<div class="sio-field"><label>Cost (USD)</label><input id="sioRcvCost" type="number" step="0.01"></div>'
        + '<div class="sio-field"><label>Condition</label><select id="sioRcvCond"><option value="new">New</option><option value="good">Good</option></select></div>'
        + '</div>'
        + '<div class="sio-field"><label>Supplier / Source</label><input id="sioRcvSupplier" placeholder="Starlink, TP-Link..."></div>'
        + '<div class="sio-field"><label>Note</label><input id="sioRcvNote" placeholder="Invoice ref, reason..."></div>'
        + '<div class="sio-btn-row"><button class="sio-btn secondary" onclick="closeModal()">Cancel</button>'
        + '<button class="sio-btn success" onclick="sioDoReceive()">📥 Receive</button></div>';
}

// ── UNIT SEARCH FORM (reused for checkin, return, write_off) ──
function buildUnitSearchForm(action, statusFilter, placeholder){
    return '<div class="sio-field"><label>Find Unit</label>'
        + '<input id="sioSearchInput" placeholder="'+(placeholder||'Search serial...')+'" '
        + 'onkeyup="sioSearchUnit(\''+action+'\',\''+statusFilter+'\')" data-action="'+action+'" data-status="'+statusFilter+'">'
        + '</div>'
        + '<div id="sioSearchResults"></div>';
}

// ── CHECKOUT FORM ──
function buildCheckoutForm(){
    return '<div class="sio-field"><label>Find Unit (in stock)</label>'
        + '<input id="sioCoSerial" placeholder="Search serial number..." onkeyup="sioSearchForCheckout()" onkeydown="if(event.key===\'Enter\')sioSearchForCheckout()">'
        + '</div>'
        + '<div id="sioCoUnitCard"></div>'
        + '<div class="sio-field"><label>Issue To (Agent) *</label>'
        + '<input id="sioCoAgentSearch" placeholder="Tap here to see all agents..." '
        +   'oninput="sioFilterAgent(this.value)" onfocus="sioFilterAgent(this.value)" '
        +   'autocomplete="off" style="border-radius:10px;">'
        + '<div id="sioCoAgentList" style="max-height:220px;overflow-y:auto;border:1px solid #E2E8F0;border-radius:10px;display:none;box-shadow:0 4px 12px rgba(0,0,0,.06);background:#fff;margin-top:4px;"></div>'
        + '<div id="sioCoAgentPicked" style="display:none;padding:10px 12px;margin-top:6px;border:2px solid #059669;border-radius:10px;background:#F0FDF4;font-weight:700;font-size:14px;"></div>'
        + '</div>'
        + '<div class="sio-field"><label>Note</label><input id="sioCoNote" placeholder="Job ref, reason..."></div>'
        + '<div class="sio-btn-row"><button class="sio-btn secondary" onclick="closeModal()">Cancel</button>'
        + '<button class="sio-btn primary" id="sioCoBtn" onclick="sioDoCheckout()" disabled>↗️ Check Out</button></div>';
}

// ── DIRECT INSTALL FORM ──
function buildDirectInstallForm(){
    return '<div class="sio-field"><label>Find Unit (in stock)</label>'
        + '<input id="sioDiSerial" placeholder="Search serial number..." onkeyup="sioSearchForDirect()" onkeydown="if(event.key===\'Enter\')sioSearchForDirect()">'
        + '</div>'
        + '<div id="sioDiUnitCard"></div>'
        + '<div class="sio-field"><label>Deploy To (Client) *</label>'
        + '<input id="sioDiCrmSearch" placeholder="Type client name, FTTH ID, or CRM ID..." '
        +   'oninput="sioCrmSearch(this.value)" autocomplete="off">'
        + '<div id="sioDiCrmList" style="max-height:200px;overflow-y:auto;border:1px solid #E2E8F0;border-radius:10px;display:none;box-shadow:0 4px 12px rgba(0,0,0,.06);background:#fff;margin-top:4px;"></div>'
        + '<div id="sioDiCrmPicked" style="display:none;padding:10px 12px;margin-top:6px;border:2px solid #2563EB;border-radius:10px;background:#EFF6FF;font-weight:700;font-size:14px;"></div>'
        + '</div>'
        + '<div class="sio-field"><label>Note</label><input id="sioDiNote" placeholder="Job ref, site location..."></div>'
        + '<div class="sio-btn-row"><button class="sio-btn secondary" onclick="closeModal()">Cancel</button>'
        + '<button class="sio-btn primary" id="sioDiBtn" onclick="sioDoDirectInstall()" disabled>📦 Deploy</button></div>';
}

// ═══ API CALLS ═══

// Load categories for receive form
var _catLoadTimer = null;
window.sioRcvCatChange = function(){
    var sel = $('sioRcvCat');
    var opt = sel.options[sel.selectedIndex];
    var mode = opt.dataset.mode||'serial';
    $('sioRcvSerialWrap').style.display = mode==='serial'?'':'none';
    $('sioRcvQtyWrap').style.display = mode==='quantity'?'':'none';
    $('sioRcvCost').value = opt.dataset.cost||'';
};

function loadCatsIntoSelect(selId, cb){
    api('stock_categories',{params:{active_only:1}},function(err,r){
        if(err||!r||!r.data)return;
        var sel=$(selId); if(!sel)return;
        sel.innerHTML='<option value="">Select category...</option>';
        (r.data||[]).forEach(function(c){
            sel.innerHTML+='<option value="'+c.id+'" data-mode="'+c.track_mode+'" data-cost="'+(c.buy_price||0)+'">'+esc(c.title)+' ('+esc(c.sku||'')+')</option>';
        });
        if(cb)cb();
    });
}

function loadStaffIntoSelect(selId){
    // Legacy fallback — kept for any non-checkout dropdowns
    api('staff_list',{},function(err,r){
        if(err||!r||!r.data)return;
        var sel=$(selId); if(!sel)return;
        sel.innerHTML='<option value="">Select agent...</option>';
        (r.data||[]).filter(function(s){return s.is_active;}).forEach(function(s){
            sel.innerHTML+='<option value="'+s.id+'" data-name="'+esc(s.name)+'">'+esc(s.name)+' ('+esc(s.role||'')+')</option>';
        });
    });
}

// ── Searchable agent picker for checkout ──────────────────────
var _sioStaff = [], _sioAgentId = 0, _sioAgentName = '';

function sioLoadStaff(cb){
    if(_sioStaff.length){ if(cb)cb(); return; }
    api('staff_list',{},function(err,r){
        if(!r||!r.data)return;
        _sioStaff = (r.data||[]).filter(function(s){return s.is_active;});
        if(cb)cb();
    });
}

window.sioFilterAgent = function(q){
    var list = $('sioCoAgentList'); if(!list)return;
    if(!_sioStaff.length){
        sioLoadStaff(function(){ sioFilterAgent(q); });
        return;
    }
    var ql = q.toLowerCase().trim();
    var filtered = ql.length < 1
        ? _sioStaff
        : _sioStaff.filter(function(s){
            return (s.name||'').toLowerCase().indexOf(ql)!==-1
                || (s.role||'').toLowerCase().indexOf(ql)!==-1;
          });
    if(!filtered.length){
        list.innerHTML='<div style="padding:10px 14px;font-size:13px;color:#94A3B8;">No match</div>';
        list.style.display='block'; return;
    }
    var h='';
    filtered.forEach(function(s){
        h+='<div onclick="sioPickAgent('+s.id+',\''+esc(s.name)+'\')" '
          +'style="padding:12px 14px;border-bottom:1px solid #F1F5F9;cursor:pointer;display:flex;align-items:center;justify-content:space-between;" '
          +'onmouseover="this.style.background=\'#F8FAFC\'" onmouseout="this.style.background=\'\'">'
          +'<span style="font-size:14px;font-weight:700;color:#1E293B;">'+esc(s.name)+'</span>'
          +'<span style="font-size:11px;color:#94A3B8;background:#F1F5F9;padding:2px 8px;border-radius:20px;">'+esc(s.role||'')+'</span>'
          +'</div>';
    });
    list.innerHTML=h;
    list.style.display='block';
};

window.sioPickAgent = function(id, name){
    _sioAgentId   = id;
    _sioAgentName = name;
    var inp = $('sioCoAgentSearch'); if(inp) inp.value = name;
    var list = $('sioCoAgentList'); if(list) list.style.display='none';
    var picked = $('sioCoAgentPicked');
    if(picked){
        picked.innerHTML = '👤 <span style="font-size:15px;font-weight:800;">'+esc(name)+'</span>&nbsp;<span style="font-size:11px;color:#059669;">selected ✓</span>';
        picked.style.display = 'flex';
    }
    // Enable checkout button only if unit also selected
    var btn = $('sioCoBtn');
    if(btn && _sioUnitId) btn.disabled = false;
};

// Receive new stock
window.sioDoReceive = function(){
    var catSel = $('sioRcvCat');
    var catId = parseInt(catSel.value)||0;
    if(!catId){alert('Select a category');return;}
    var opt = catSel.options[catSel.selectedIndex];
    var mode = opt.dataset.mode||'serial';

    if(mode === 'serial'){
        var serial = ($('sioRcvSerial').value||'').trim();
        if(!serial){alert('Serial number required');return;}
        api('stock_unit_save',{method:'POST',body:{
            category_id:catId, serial_number:serial,
            purchase_cost:parseFloat($('sioRcvCost').value)||0,
            condition_grade:$('sioRcvCond').value,
            location_name:'DishNet UNMISS',
            notes:[$('sioRcvSupplier').value,$('sioRcvNote').value].filter(Boolean).join(' — '),
        }},function(err,r){
            if(err||!r||r.status==='error'){$('sioModalResult').innerHTML='<div style="color:#DC2626;">❌ '+(r&&r.message||err)+'</div>';return;}
            $('sioModalResult').innerHTML='<div style="color:#059669;">✅ Received: '+esc(serial)+'</div>';
            $('sioRcvSerial').value='';
            $('sioRcvSerial').focus();
            sioLoadLog();
        });
    } else {
        var qty = parseInt($('sioRcvQty').value)||0;
        if(qty<1){alert('Quantity must be at least 1');return;}
        api('stock_qty_adjust',{method:'POST',body:{
            category_id:catId, delta:qty,
            location_type:'warehouse', location_ref:'main', location_name:'DishNet UNMISS',
            note:[$('sioRcvSupplier').value,$('sioRcvNote').value].filter(Boolean).join(' — '),
        }},function(err,r){
            if(err||!r||r.status==='error'){$('sioModalResult').innerHTML='<div style="color:#DC2626;">❌ '+(r&&r.message||err)+'</div>';return;}
            $('sioModalResult').innerHTML='<div style="color:#059669;">✅ Received '+qty+' units</div>';
            sioLoadLog();
        });
    }
};

// Unit search (debounced)
var _searchTimer = null;
window.sioSearchUnit = function(action, statusFilter){
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(function(){
        var q = ($('sioSearchInput').value||'').trim();
        if(q.length<2){$('sioSearchResults').innerHTML='';return;}
        var params = {search:q, limit:10};
        if(statusFilter) params.status = statusFilter;
        api('stock_units',{params:params},function(err,r){
            if(err||!r||!r.data){$('sioSearchResults').innerHTML='';return;}
            var items = r.data.items||[];
            if(!items.length){$('sioSearchResults').innerHTML='<div style="color:#94A3B8;padding:12px;font-size:13px;">No matching units found</div>';return;}
            var h = '';
            items.forEach(function(u){
                var allowed = CHAIN_RULES[u.status]||[];
                var canAct = action==='write_off' || allowed.indexOf(action)!==-1;
                h += '<div class="sio-unit-card" style="cursor:'+(canAct?'pointer':'not-allowed')+';opacity:'+(canAct?'1':'.5')+'" '
                    +(canAct?'onclick="sioActOnUnit('+u.id+',\''+action+'\',\''+esc(u.serial_number)+'\')"':'')+'>';
                h += '<div class="serial">'+esc(u.serial_number)+'</div>';
                h += '<div class="meta">'+esc(u.category_name)+' · <strong>'+esc(u.status).replace(/_/g,' ')+'</strong>';
                if(u.location_name) h += ' · '+esc(u.location_name);
                if(!canAct) h += ' · <span style="color:#DC2626;">Cannot '+action.replace(/_/g,' ')+' from '+u.status+'</span>';
                h += '</div></div>';
            });
            $('sioSearchResults').innerHTML = h;
        });
    }, 300);
};

window.sioActOnUnit = function(unitId, action, serial){
    var actionMap = {
        checkin: {endpoint:'stock_checkin', body:{unit_id:unitId, condition:'good', note:'Returned by agent'}},
        customer_return: {endpoint:'stock_return', body:{unit_id:unitId, condition:'good', note:'Retrieved from customer'}},
        write_off: {endpoint:'stock_write_off', body:{unit_id:unitId, reason:''}},
    };
    var spec = actionMap[action];
    if(!spec){alert('Unknown action');return;}

    // Condition prompt for returns
    if(action==='checkin'||action==='customer_return'){
        var cond = prompt('Condition? (new / good / fair / damaged)', 'good');
        if(!cond) return;
        spec.body.condition = cond;
    }
    if(action==='write_off'){
        var reason = prompt('Reason for write-off:', '');
        if(reason===null) return;
        spec.body.reason = reason;
    }

    api(spec.endpoint,{method:'POST',body:spec.body},function(err,r){
        if(err||!r||r.status==='error'){$('sioModalResult').innerHTML='<div style="color:#DC2626;">❌ '+(r&&r.message||err)+'</div>';return;}
        $('sioModalResult').innerHTML='<div style="color:#059669;">✅ Done: '+esc(serial)+'</div>';
        $('sioSearchInput').value = '';
        $('sioSearchResults').innerHTML = '';
        sioLoadLog();
    });
};

// Checkout: search + select
var _coUnitId = null;
window.sioSearchForCheckout = function(){
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(function(){
        var q = ($('sioCoSerial').value||'').trim();
        if(q.length<2){$('sioCoUnitCard').innerHTML='';_coUnitId=null;if($('sioCoBtn'))$('sioCoBtn').disabled=true;return;}
        api('stock_units',{params:{search:q, limit:8}},function(err,r){
            if(err||!r||!r.data) return;
            var items = (r.data.items||[]).filter(function(u){return u.status==='in_stock'||u.status==='returned';});
            if(!items.length){$('sioCoUnitCard').innerHTML='<div style="color:#94A3B8;font-size:13px;padding:8px 0;">No in-stock units found</div>';_coUnitId=null;if($('sioCoBtn'))$('sioCoBtn').disabled=true;return;}
            var h='';
            items.slice(0,5).forEach(function(u){
                h += '<div class="sio-unit-card" style="cursor:pointer;" onclick="sioPickCoUnit('+u.id+',\''+esc(u.serial_number)+'\',\''+esc(u.category_name)+'\')">'+
                     '<div class="serial">'+esc(u.serial_number)+'</div>'+
                     '<div class="meta">'+esc(u.category_name)+' · '+esc(u.status).replace(/_/g,' ')+'</div></div>';
            });
            $('sioCoUnitCard').innerHTML = h;
        });
    }, 300);
};

window.sioPickCoUnit = function(id, serial, catName){
    _coUnitId = id;
    _sioUnitId = id; // alias for agent picker's button-enable check
    $('sioCoUnitCard').innerHTML = '<div class="sio-unit-card" style="border-color:#059669;"><div class="serial">✅ '+esc(serial)+'</div><div class="meta">'+esc(catName)+'</div></div>';
    // Enable checkout only if agent also selected
    if(_sioAgentId && $('sioCoBtn')) $('sioCoBtn').disabled = false;
};

window.sioDoCheckout = function(){
    if(!_coUnitId){alert('Select a unit first');return;}
    if(!_sioAgentId){alert('Select an agent');return;}
    api('stock_checkout',{method:'POST',body:{
        unit_id:_coUnitId,
        agent_rid:_sioAgentId,
        agent_name:_sioAgentName,
        note:$('sioCoNote')?$('sioCoNote').value:'',
    }},function(err,r){
        if(err||!r||r.status==='error'){$('sioModalResult').innerHTML='<div style="color:#DC2626;">❌ '+(r&&r.message||err)+'</div>';return;}
        $('sioModalResult').innerHTML='<div style="color:#059669;">✅ Checked out to '+esc(_sioAgentName)+'</div>';
        _coUnitId=null; _sioUnitId=null; _sioAgentId=0; _sioAgentName='';
        if($('sioCoSerial'))$('sioCoSerial').value='';
        if($('sioCoUnitCard'))$('sioCoUnitCard').innerHTML='';
        if($('sioCoBtn'))$('sioCoBtn').disabled=true;
        sioLoadLog();
    });
};

// ── CRM customer picker for Direct Install ─────────────────────
var _diCrmId = 0, _diCrmName = '';

window.sioCrmSearch = function(q){
    var list = $('sioDiCrmList'); if(!list) return;
    q = q.trim();
    if(q.length < 1){ list.style.display='none'; return; }

    // Search the client_search_index loaded by collect_payment, or fetch it
    var idx = window.CRM_IDX || [];
    if(!idx.length){
        // Fetch index once and cache globally
        var xhr = new XMLHttpRequest();
        xhr.open('GET','?page=api&action=client_search_index',true);
        xhr.onload = function(){
            try{
                var r = JSON.parse(xhr.responseText);
                if(r && Array.isArray(r.data)) window.CRM_IDX = r.data;
                sioCrmSearch(q);
            }catch(e){}
        };
        xhr.send();
        list.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:#94A3B8;">Loading customers...</div>';
        list.style.display = 'block';
        return;
    }

    var ql = q.toLowerCase();
    var matches = idx.filter(function(c){ return c.search && c.search.indexOf(ql) !== -1; }).slice(0,8);
    if(!matches.length){
        list.innerHTML='<div style="padding:10px 14px;font-size:13px;color:#94A3B8;">No match for "'+esc(q)+'"</div>';
        list.style.display='block'; return;
    }
    var h='';
    matches.forEach(function(c){
        var svcBadge = '';
        var pl = (c.plans||'').toLowerCase();
        if(pl.indexOf('starlink')!==-1) svcBadge='<span style="font-size:9px;font-weight:800;background:#EDE7F6;color:#7B1FA2;padding:1px 6px;border-radius:4px;margin-left:4px;">Starlink</span>';
        else if(pl.indexOf('fiber')!==-1||pl.indexOf('fibre')!==-1) svcBadge='<span style="font-size:9px;font-weight:800;background:#E3F2FD;color:#1565C0;padding:1px 6px;border-radius:4px;margin-left:4px;">Fiber</span>';
        h += '<div onclick="sioPickCrmClient(\''+esc(String(c.id))+'\',\''+esc(c.name)+'\')" '
           + 'style="padding:10px 14px;border-bottom:1px solid #F1F5F9;cursor:pointer;display:flex;align-items:center;justify-content:space-between;" '
           + 'onmouseover="this.style.background=\'#F8FAFC\'" onmouseout="this.style.background=\'\'">'
           + '<div><span style="font-size:14px;font-weight:700;color:#1E293B;">'+esc(c.name)+'</span>'+svcBadge
           + '<div style="font-size:11px;color:#64748B;margin-top:1px;">ID: '+esc(String(c.id))+(c.phone?' · '+esc(c.phone):'')+'</div></div>'
           + '</div>';
    });
    list.innerHTML = h;
    list.style.display = 'block';
};

window.sioPickCrmClient = function(id, name){
    _diCrmId   = parseInt(id)||0;
    _diCrmName = name;
    var inp = $('sioDiCrmSearch'); if(inp) inp.value = name;
    var list = $('sioDiCrmList'); if(list) list.style.display='none';
    var picked = $('sioDiCrmPicked');
    if(picked){
        picked.innerHTML = '👤 <span style="font-size:15px;font-weight:800;">'+esc(name)+'</span>'
            + (_diCrmId ? '&nbsp;<span style="font-size:11px;color:#2563EB;">CRM #'+_diCrmId+' ✓</span>' : '');
        picked.style.display = 'flex';
    }
    if(_diUnitId && $('sioDiBtn')) $('sioDiBtn').disabled = false;
};
window.sioSearchForDirect = function(){
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(function(){
        var q = ($('sioDiSerial').value||'').trim();
        if(q.length<2){$('sioDiUnitCard').innerHTML='';_diUnitId=null;if($('sioDiBtn'))$('sioDiBtn').disabled=true;return;}
        api('stock_units',{params:{search:q, limit:8}},function(err,r){
            if(err||!r||!r.data) return;
            var items = (r.data.items||[]).filter(function(u){return u.status==='in_stock'||u.status==='returned';});
            if(!items.length){$('sioDiUnitCard').innerHTML='<div style="color:#94A3B8;font-size:13px;padding:8px 0;">No available units</div>';_diUnitId=null;if($('sioDiBtn'))$('sioDiBtn').disabled=true;return;}
            var h='';
            items.slice(0,5).forEach(function(u){
                h += '<div class="sio-unit-card" style="cursor:pointer;" onclick="sioPickDiUnit('+u.id+',\''+esc(u.serial_number)+'\',\''+esc(u.category_name)+'\')">'+
                     '<div class="serial">'+esc(u.serial_number)+'</div>'+
                     '<div class="meta">'+esc(u.category_name)+' · '+esc(u.status).replace(/_/g,' ')+'</div></div>';
            });
            $('sioDiUnitCard').innerHTML = h;
        });
    }, 300);
};

window.sioPickDiUnit = function(id, serial, catName){
    _diUnitId = id;
    $('sioDiUnitCard').innerHTML = '<div class="sio-unit-card" style="border-color:#059669;"><div class="serial">✅ '+esc(serial)+'</div><div class="meta">'+esc(catName)+'</div></div>';
    if(_diCrmName && $('sioDiBtn')) $('sioDiBtn').disabled = false;
};

window.sioDoDirectInstall = function(){
    if(!_diUnitId){alert('Select a unit first');return;}
    if(!_diCrmName){alert('Select a customer');return;}
    api('stock_install',{method:'POST',body:{
        unit_id:_diUnitId, crm_client_id:_diCrmId||0, client_name:_diCrmName,
        note:$('sioDiNote')?($('sioDiNote').value||'Direct install'):'Direct install',
    }},function(err,r){
        if(err||!r||r.status==='error'){$('sioModalResult').innerHTML='<div style="color:#DC2626;">❌ '+(r&&r.message||err)+'</div>';return;}
        $('sioModalResult').innerHTML='<div style="color:#059669;">✅ Installed at '+esc(_diCrmName)+'</div>';
        _diUnitId=null; _diCrmId=0; _diCrmName=''; sioLoadLog();
    });
};

// ═══ INIT ═══
// Set date filters to today
var today = new Date().toISOString().slice(0,10);
$('sioTo').value = today;
// Load log
sioLoadLog();
// Pre-load categories and staff for modals
setTimeout(function(){
    // These will be re-loaded when modals open, but pre-cache helps
    var _origOpen = window._sioOpenImpl;
    window._sioOpenImpl = function(action){
        _origOpen(action);
        // Load dropdowns after modal renders
        setTimeout(function(){
            if($('sioRcvCat')) loadCatsIntoSelect('sioRcvCat');
            // Agent search picker: pre-load staff data silently
            if($('sioCoAgentSearch')) sioLoadStaff();
        }, 50);
    };
}, 100);

_sioReady = true; // activate global stubs

})();
</script>
