<?php
/**
 * Stock Management Dashboard — Admin/Accountant/Support Leader
 * DishNet Hybrid v4.10.0
 *
 * Internal sub-tabs: dashboard, catalog, inventory, receive, movements, holdings
 * All data loaded via AJAX from api_stock.php endpoints.
 */
$stockTab = $_GET['stock_tab'] ?? 'dashboard';
?>
<style>
.stk-wrap{max-width:1280px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
.stk-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;}
.stk-header h2{margin:0;font-size:20px;font-weight:800;color:var(--text-1,#1E293B);}
.stk-tabs{display:flex;gap:4px;background:#F1F5F9;border-radius:10px;padding:3px;flex-wrap:wrap;}
.stk-tab{padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;color:#64748B;cursor:pointer;border:none;background:none;white-space:nowrap;transition:all .15s;}
.stk-tab:hover{color:#334155;background:#E2E8F0;}
.stk-tab.active{background:#fff;color:var(--primary,#2563EB);box-shadow:0 1px 3px rgba(0,0,0,.1);}

/* KPI Cards */
.stk-kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;}
.stk-kpi{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:16px;text-align:center;}
.stk-kpi .num{font-size:28px;font-weight:800;color:var(--primary,#2563EB);line-height:1.1;}
.stk-kpi .num.warn{color:#F59E0B;}
.stk-kpi .num.danger{color:#DC2626;}
.stk-kpi .num.ok{color:#059669;}
.stk-kpi .lbl{font-size:11px;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.5px;margin-top:4px;}
.stk-kpi .sub{font-size:11px;color:#64748B;margin-top:2px;}

/* Tables */
.stk-tbl-wrap{overflow-x:auto;background:#fff;border:1px solid #E2E8F0;border-radius:12px;}
.stk-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.stk-tbl th{background:#F8FAFC;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #E2E8F0;white-space:nowrap;}
.stk-tbl td{padding:10px 12px;border-bottom:1px solid #F1F5F9;color:#334155;vertical-align:middle;}
.stk-tbl tr:hover td{background:#F8FAFC;}
.stk-tbl tr:last-child td{border-bottom:none;}

/* Pills */
.stk-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;}
.stk-pill.in_stock,.stk-pill.inbound,.stk-pill.received{background:#ECFDF5;color:#059669;}
.stk-pill.checked_out,.stk-pill.checkout{background:#FFF7ED;color:#D97706;}
.stk-pill.installed,.stk-pill.install{background:#EFF6FF;color:#2563EB;}
.stk-pill.returned,.stk-pill.checkin,.stk-pill.return{background:#F0FDF4;color:#16A34A;}
.stk-pill.damaged,.stk-pill.write_off{background:#FEF2F2;color:#DC2626;}
.stk-pill.written_off{background:#F1F5F9;color:#64748B;}
.stk-pill.transfer{background:#F5F3FF;color:#7C3AED;}
.stk-pill.adjust{background:#FFF7ED;color:#EA580C;}
.stk-pill.starlink{background:#1E293B;color:#F8FAFC;}
.stk-pill.fiber{background:#7C3AED;color:#fff;}
.stk-pill.lte{background:#0EA5E9;color:#fff;}
.stk-pill.general{background:#E2E8F0;color:#475569;}

/* Forms */
.stk-form{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.stk-form.cols3{grid-template-columns:1fr 1fr 1fr;}
.stk-field{display:flex;flex-direction:column;gap:4px;}
.stk-field.full{grid-column:1/-1;}
.stk-field label{font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.4px;}
.stk-field input,.stk-field select,.stk-field textarea{padding:8px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;color:#1E293B;outline:none;transition:border .15s;}
.stk-field input:focus,.stk-field select:focus,.stk-field textarea:focus{border-color:var(--primary,#2563EB);box-shadow:0 0 0 3px rgba(37,99,235,.1);}

/* Buttons */
.stk-btn{padding:8px 18px;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;}
.stk-btn.primary{background:var(--primary,#2563EB);color:#fff;}
.stk-btn.primary:hover{background:#1D4ED8;}
.stk-btn.secondary{background:#F1F5F9;color:#475569;border:1px solid #D1D5DB;}
.stk-btn.secondary:hover{background:#E2E8F0;}
.stk-btn.danger{background:#FEF2F2;color:#DC2626;border:1px solid #FECACA;}
.stk-btn.sm{padding:5px 12px;font-size:11px;}

/* Toolbar */
.stk-toolbar{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;}
.stk-toolbar input[type=text],.stk-toolbar select{padding:7px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:13px;background:#fff;}
.stk-toolbar input[type=text]{min-width:200px;}

/* Panel */
.stk-panel{background:#fff;border:1px solid #E2E8F0;border-radius:12px;padding:20px;margin-bottom:16px;}
.stk-panel h3{margin:0 0 12px;font-size:16px;font-weight:700;color:#1E293B;}

/* Alerts */
.stk-alert{padding:12px 16px;border-radius:10px;margin-bottom:12px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px;}
.stk-alert.warn{background:#FFF7ED;color:#92400E;border:1px solid #FED7AA;}
.stk-alert.info{background:#EFF6FF;color:#1E40AF;border:1px solid #BFDBFE;}
.stk-alert.ok{background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0;}

/* Loading */
.stk-loading{text-align:center;padding:40px;color:#94A3B8;font-size:14px;}

/* Modal */
.stk-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;}
.stk-modal-bg.open{display:flex;}
.stk-modal{background:#fff;border-radius:16px;padding:24px;max-width:600px;width:95%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.stk-modal h3{margin:0 0 16px;font-size:18px;font-weight:800;color:#1E293B;}

/* Timeline */
.stk-timeline{position:relative;padding-left:24px;}
.stk-timeline::before{content:'';position:absolute;left:8px;top:4px;bottom:4px;width:2px;background:#E2E8F0;}
.stk-tl-item{position:relative;margin-bottom:16px;padding-bottom:4px;}
.stk-tl-item::before{content:'';position:absolute;left:-20px;top:4px;width:12px;height:12px;border-radius:50%;background:var(--primary,#2563EB);border:2px solid #fff;box-shadow:0 0 0 2px #E2E8F0;}
.stk-tl-item .tl-date{font-size:11px;color:#94A3B8;font-weight:600;}
.stk-tl-item .tl-body{font-size:13px;color:#334155;margin-top:2px;}

@media(max-width:768px){
    .stk-form,.stk-form.cols3{grid-template-columns:1fr;}
    .stk-kpis{grid-template-columns:repeat(2,1fr);}
    .stk-tabs{flex-wrap:nowrap;overflow-x:auto;}
}
</style>

<div class="stk-wrap">
    <div class="stk-header">
        <h2>📦 Stock Management</h2>
        <div style="font-size:11px;color:#94A3B8;">v4.10.0</div>
    </div>

    <div class="stk-tabs" id="stkTabs">
        <button class="stk-tab active" data-tab="dashboard">📊 Dashboard</button>
        <button class="stk-tab" data-tab="catalog">📦 Catalog</button>
        <button class="stk-tab" data-tab="inventory">📋 Inventory</button>
        <button class="stk-tab" data-tab="receive">📥 Receive Stock</button>
        <button class="stk-tab" data-tab="movements">🔄 Movements</button>
        <button class="stk-tab" data-tab="holdings">👥 Agent Holdings</button>
        <button class="stk-tab" data-tab="scanner">📷 Scanner</button>
        <button class="stk-tab" data-tab="inout">📒 In/Out</button>
    </div>

    <!-- ═══ DASHBOARD ═══ -->
    <div class="stk-pane" id="pane-dashboard">
        <div class="stk-kpis" id="stkKpis"><div class="stk-loading">Loading dashboard...</div></div>
        <div id="stkAlerts"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px;">
            <div class="stk-panel"><h3>Stock by Service</h3><div id="stkByService">—</div></div>
            <div class="stk-panel"><h3>Recent Activity (7 days)</h3><div id="stkRecent">—</div></div>
        </div>
    </div>

    <!-- ═══ CATALOG ═══ -->
    <div class="stk-pane" id="pane-catalog" style="display:none;">
        <div class="stk-toolbar">
            <button class="stk-btn primary" onclick="stkShowCatForm()">+ Add Category</button>
            <button class="stk-btn secondary" onclick="stkSeedCatalog()">🌱 Seed from KYC Devices</button>
            <button class="stk-btn secondary" onclick="stkApplyImages()">🖼️ Apply Product Images</button>
        </div>
        <div class="stk-tbl-wrap"><table class="stk-tbl">
            <thead><tr><th style="width:50px;"></th><th>Title</th><th>SKU</th><th>Service</th><th>Tracking</th><th>Buy $</th><th>Sell $</th><th>Min</th><th>In Stock</th><th>Actions</th></tr></thead>
            <tbody id="stkCatBody"><tr><td colspan="10" class="stk-loading">Loading...</td></tr></tbody>
        </table></div>
    </div>

    <!-- ═══ INVENTORY ═══ -->
    <div class="stk-pane" id="pane-inventory" style="display:none;">
        <div class="stk-toolbar">
            <input type="text" id="stkInvSearch" placeholder="Search serial, notes..." onkeyup="stkDebounce(stkLoadInventory,400)">
            <select id="stkInvService" onchange="stkLoadInventory()"><option value="">All Services</option><option value="starlink">Starlink</option><option value="fiber">Fiber</option><option value="lte">LTE</option><option value="general">General</option></select>
            <select id="stkInvStatus" onchange="stkLoadInventory()"><option value="">All Statuses</option><option value="in_stock">In Stock</option><option value="checked_out">Checked Out</option><option value="installed">Installed</option><option value="returned">Returned</option><option value="damaged">Damaged</option><option value="written_off">Written Off</option></select>
            <button class="stk-btn secondary sm" onclick="stkLoadInventory()">🔄 Refresh</button>
            <button class="stk-btn secondary sm" onclick="stkExportCsv()">📥 CSV</button>
            <button class="stk-btn primary sm" onclick="stkShowUnitForm()">+ Add Unit</button>
        </div>
        <div id="stkInvCount" style="font-size:12px;color:#64748B;margin-bottom:8px;"></div>
        <div class="stk-tbl-wrap"><table class="stk-tbl">
            <thead><tr><th>Serial</th><th>Category</th><th>Service</th><th>Status</th><th>Location</th><th>Condition</th><th>Cost</th><th>Updated</th><th>Actions</th></tr></thead>
            <tbody id="stkInvBody"><tr><td colspan="9" class="stk-loading">Loading...</td></tr></tbody>
        </table></div>
        <div id="stkInvPager" style="margin-top:12px;display:flex;gap:8px;justify-content:center;"></div>
    </div>

    <!-- ═══ RECEIVE STOCK ═══ -->
    <div class="stk-pane" id="pane-receive" style="display:none;">
        <div class="stk-panel">
            <h3>📥 Receive Stock from Supplier</h3>
            <div class="stk-form cols3">
                <div class="stk-field"><label>Supplier</label><input id="rcvSupplier" placeholder="Starlink, TP-Link..."></div>
                <div class="stk-field"><label>Invoice Number</label><input id="rcvInvoice" placeholder="INV-2026-001"></div>
                <div class="stk-field"><label>Purchase Date</label><input id="rcvDate" type="date"></div>
                <div class="stk-field"><label>Total Cost (USD)</label><input id="rcvTotal" type="number" step="0.01" placeholder="0.00"></div>
                <div class="stk-field"><label>Payment Method</label><select id="rcvPayment"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="credit">Credit</option></select></div>
                <div class="stk-field"><label>Notes</label><input id="rcvNotes" placeholder="Optional notes"></div>
            </div>

            <h3 style="margin-top:20px;">Items Received</h3>
            <div id="rcvItems"></div>
            <button class="stk-btn secondary sm" onclick="stkAddRcvItem()" style="margin-top:8px;">+ Add Item</button>

            <div style="margin-top:20px;display:flex;gap:8px;">
                <button class="stk-btn primary" onclick="stkSubmitReceive()">✅ Submit Receipt</button>
            </div>
            <div id="rcvResult" style="margin-top:12px;"></div>
        </div>
    </div>

    <!-- ═══ MOVEMENTS ═══ -->
    <div class="stk-pane" id="pane-movements" style="display:none;">
        <div class="stk-toolbar">
            <select id="stkMovType" onchange="stkLoadMovements()"><option value="">All Types</option><option value="inbound">Inbound</option><option value="checkout">Check Out</option><option value="checkin">Check In</option><option value="install">Install</option><option value="return">Return</option><option value="transfer">Transfer</option><option value="adjust">Adjust</option><option value="write_off">Write Off</option></select>
            <input id="stkMovFrom" type="date" onchange="stkLoadMovements()" title="From date">
            <input id="stkMovTo" type="date" onchange="stkLoadMovements()" title="To date">
            <button class="stk-btn secondary sm" onclick="stkLoadMovements()">🔄 Refresh</button>
        </div>
        <div class="stk-tbl-wrap"><table class="stk-tbl">
            <thead><tr><th>Date</th><th>Type</th><th>Item</th><th>From</th><th>To</th><th>By</th><th>Note</th></tr></thead>
            <tbody id="stkMovBody"><tr><td colspan="7" class="stk-loading">Loading...</td></tr></tbody>
        </table></div>
    </div>

    <!-- ═══ AGENT HOLDINGS ═══ -->
    <div class="stk-pane" id="pane-holdings" style="display:none;">
        <div class="stk-toolbar">
            <button class="stk-btn secondary sm" onclick="stkLoadHoldings()">🔄 Refresh</button>
        </div>
        <div id="stkHoldingsBody"><div class="stk-loading">Loading...</div></div>
    </div>

    <!-- ═══ SCANNER (loaded via include) ═══ -->
    <div class="stk-pane" id="pane-scanner" style="display:none;">
        <?php include __DIR__ . '/stock_scanner.php'; ?>
    </div>

    <!-- ═══ IN/OUT REGISTER ═══ -->
    <div class="stk-pane" id="pane-inout" style="display:none;">
        <?php include __DIR__ . '/stock_inout.php'; ?>
    </div>
</div>

<!-- ═══ MODALS ═══ -->
<div class="stk-modal-bg" id="stkCatModal">
    <div class="stk-modal">
        <h3 id="stkCatModalTitle">Add Category</h3>
        <input type="hidden" id="catEditId" value="0">
        <div class="stk-form">
            <div class="stk-field"><label>Title *</label><input id="catTitle" placeholder="Starlink Mini Kit"></div>
            <div class="stk-field"><label>SKU</label><input id="catSku" placeholder="SL-MINI"></div>
            <div class="stk-field"><label>Service Type</label><select id="catService"><option value="starlink">Starlink</option><option value="fiber">Fiber</option><option value="lte">LTE</option><option value="general">General</option></select></div>
            <div class="stk-field"><label>Tracking Mode</label><select id="catTrack"><option value="serial">Serial Number</option><option value="quantity">Quantity Only</option></select></div>
            <div class="stk-field"><label>Buy Price (USD)</label><input id="catBuy" type="number" step="0.01"></div>
            <div class="stk-field"><label>Sell Price (USD)</label><input id="catSell" type="number" step="0.01"></div>
            <div class="stk-field"><label>Min Stock Alert</label><input id="catMin" type="number" value="2"></div>
            <div class="stk-field"><label>Unit</label><input id="catUnit" value="piece"></div>
            <div class="stk-field full"><label>Image URL</label><div style="display:flex;gap:8px;align-items:center;"><input id="catImage" placeholder="https://... product image URL" style="flex:1;"><div id="catImagePreview" style="width:44px;height:44px;border-radius:8px;border:1px solid #E2E8F0;overflow:hidden;flex-shrink:0;"></div></div></div>
            <div class="stk-field full"><label>Description</label><textarea id="catDesc" rows="2"></textarea></div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
            <button class="stk-btn secondary" onclick="document.getElementById('stkCatModal').classList.remove('open')">Cancel</button>
            <button class="stk-btn primary" onclick="stkSaveCat()">Save</button>
        </div>
    </div>
</div>

<div class="stk-modal-bg" id="stkUnitModal">
    <div class="stk-modal">
        <h3>Add Stock Unit</h3>
        <div class="stk-form">
            <div class="stk-field"><label>Category *</label><select id="unitCat"></select></div>
            <div class="stk-field"><label>Serial Number *</label><input id="unitSerial" placeholder="KIT4M00301849M87"></div>
            <div class="stk-field"><label>Secondary Serial</label><input id="unitSerial2" placeholder="MAC, account no..."></div>
            <div class="stk-field"><label>Condition</label><select id="unitCond"><option value="new">New</option><option value="good">Good</option><option value="fair">Fair</option><option value="damaged">Damaged</option></select></div>
            <div class="stk-field"><label>Location Name</label><input id="unitLoc" value="DishNet UNMISS"></div>
            <div class="stk-field"><label>Purchase Cost (USD)</label><input id="unitCost" type="number" step="0.01"></div>
            <div class="stk-field full"><label>Notes</label><textarea id="unitNotes" rows="2"></textarea></div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
            <button class="stk-btn secondary" onclick="document.getElementById('stkUnitModal').classList.remove('open')">Cancel</button>
            <button class="stk-btn primary" onclick="stkSaveUnit()">Save</button>
        </div>
    </div>
</div>

<div class="stk-modal-bg" id="stkDetailModal">
    <div class="stk-modal" style="max-width:700px;">
        <h3 id="stkDetailTitle">Unit Detail</h3>
        <div id="stkDetailBody"></div>
        <div style="margin-top:16px;text-align:right;">
            <button class="stk-btn secondary" onclick="document.getElementById('stkDetailModal').classList.remove('open')">Close</button>
        </div>
    </div>
</div>

<div class="stk-modal-bg" id="stkActionModal">
    <div class="stk-modal" style="max-width:480px;">
        <h3 id="stkActionTitle">Action</h3>
        <div id="stkActionBody"></div>
    </div>
</div>

<script>
(function(){
var API = '?page=stock_api&action=';
var _dbTimer = null;
var _invPage = 0;
var _catCache = [];

function esc(s){ return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;') : ''; }
function $(id){ return document.getElementById(id); }
function fmt$(n){ return '$' + parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
function fmtDate(d){ if(!d)return '—'; var dt=new Date(d.replace(' ','T')); return isNaN(dt)?d:dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }
function fmtDateTime(d){ if(!d)return '—'; var dt=new Date(d.replace(' ','T')); return isNaN(dt)?d:dt.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
function pill(cls,txt){ return '<span class="stk-pill '+esc(cls)+'">'+esc(txt||cls).replace(/_/g,' ')+'</span>'; }

function api(action, opts, cb){
    opts = opts || {};
    var url = API + action;
    var method = opts.method || 'GET';
    var body = opts.body || null;
    if (method === 'GET' && opts.params) {
        var qs = [];
        for(var k in opts.params) if(opts.params[k] !== '' && opts.params[k] != null) qs.push(k+'='+encodeURIComponent(opts.params[k]));
        if(qs.length) url += '&' + qs.join('&');
    }
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    if(method === 'POST') xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload = function(){
        try{ var r = JSON.parse(xhr.responseText); cb(null, r); }
        catch(e){ cb('Parse error: ' + e.message); }
    };
    xhr.onerror = function(){ cb('Network error'); };
    xhr.send(body ? JSON.stringify(body) : null);
}

// ═══ TAB SWITCHING ═══
var tabs = document.querySelectorAll('.stk-tab');
var panes = document.querySelectorAll('.stk-pane');
tabs.forEach(function(t){
    t.addEventListener('click', function(){
        tabs.forEach(function(x){x.classList.remove('active');});
        panes.forEach(function(x){x.style.display='none';});
        t.classList.add('active');
        var p = $('pane-'+t.dataset.tab);
        if(p) p.style.display='block';
        // Load data on first visit
        var tab = t.dataset.tab;
        if(tab==='dashboard') stkLoadDashboard();
        if(tab==='catalog') stkLoadCatalog();
        if(tab==='inventory') stkLoadInventory();
        if(tab==='movements') stkLoadMovements();
        if(tab==='holdings') stkLoadHoldings();
    });
});

// ═══ DASHBOARD ═══
window.stkLoadDashboard = function(){
    api('stock_report', {}, function(err, r){
        if(err || !r || !r.data){ $('stkKpis').innerHTML = '<div class="stk-alert warn">⚠️ '+esc(err || (r&&r.message) || 'Failed to load')+'</div>'; return; }
        var d = r.data;
        var html = '';
        html += '<div class="stk-kpi"><div class="num">'+d.total_serial+'</div><div class="lbl">Total Units</div></div>';
        html += '<div class="stk-kpi"><div class="num ok">'+d.in_stock+'</div><div class="lbl">In Stock</div></div>';
        html += '<div class="stk-kpi"><div class="num warn">'+d.checked_out+'</div><div class="lbl">Checked Out</div></div>';
        html += '<div class="stk-kpi"><div class="num">'+(d.installed||0)+'</div><div class="lbl">Installed</div></div>';
        html += '<div class="stk-kpi"><div class="num'+(d.damaged>0?' danger':'')+'">'+d.damaged+'</div><div class="lbl">Damaged</div></div>';
        html += '<div class="stk-kpi"><div class="num">'+fmt$(d.stock_value)+'</div><div class="lbl">Stock Value</div></div>';
        html += '<div class="stk-kpi"><div class="num">'+fmt$(d.installed_value)+'</div><div class="lbl">Installed Value</div></div>';
        html += '<div class="stk-kpi"><div class="num">'+(d.total_qty_items||0)+'</div><div class="lbl">Consumable Items</div></div>';
        $('stkKpis').innerHTML = html;

        // Low stock alerts
        var alerts = d.low_stock_alerts || [];
        var ah = '';
        if(alerts.length){
            ah += '<div class="stk-alert warn">⚠️ <strong>'+alerts.length+' low stock alert'+(alerts.length>1?'s':'')+':</strong> ';
            ah += alerts.map(function(a){ return esc(a.title)+' ('+((a.track_mode==='serial'?a.serial_avail:a.qty_avail)||0)+'/'+a.min_stock+')'; }).join(', ');
            ah += '</div>';
        }
        $('stkAlerts').innerHTML = ah;

        // By service
        var svc = d.in_stock_by_service || {};
        var sh = '<div style="display:flex;gap:16px;flex-wrap:wrap;">';
        ['starlink','fiber','lte','general'].forEach(function(s){
            sh += '<div style="text-align:center;">'+pill(s,s)+' <div style="font-size:20px;font-weight:800;margin-top:4px;">'+(svc[s]||0)+'</div></div>';
        });
        sh += '</div>';
        $('stkByService').innerHTML = sh;

        // Recent
        var mv = d.movements_7d || {};
        var rh = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
        ['inbound','checkout','checkin','install','return','transfer','write_off'].forEach(function(t){
            if(mv[t]) rh += '<div>'+pill(t,t)+' <strong>'+mv[t]+'</strong></div>';
        });
        if(!Object.keys(mv).length) rh += '<div style="color:#94A3B8;">No movements yet</div>';
        rh += '</div>';
        $('stkRecent').innerHTML = rh;
    });
};

// ═══ CATALOG ═══
window.stkLoadCatalog = function(){
    api('stock_categories', {}, function(err, r){
        if(err || !r || !r.data){ $('stkCatBody').innerHTML = '<tr><td colspan="10">Error: '+esc(err || (r&&r.message) || 'Unknown error')+'</td></tr>'; return; }
        _catCache = r.data;
        if(!r.data.length){ $('stkCatBody').innerHTML = '<tr><td colspan="10" style="text-align:center;color:#94A3B8;padding:40px;">No categories yet. Click <strong>+ Add Category</strong> or <strong>🌱 Seed from KYC Devices</strong>.</td></tr>'; return; }
        var h = '';
        r.data.forEach(function(c){
            var avail = c.track_mode === 'serial' ? (c.serial_in_stock||0) : (c.qty_total||0);
            var total = c.track_mode === 'serial' ? (c.serial_total||0) : (c.qty_total||0);
            var isLow = c.min_stock > 0 && avail < c.min_stock;
            h += '<tr>';
            h += '<td style="width:50px;padding:4px 8px;">';
            if(c.image_url) h += '<img src="'+esc(c.image_url)+'" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #E2E8F0;" onerror="this.style.display=\'none\'">';
            else h += '<div style="width:44px;height:44px;background:#F1F5F9;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:20px;">'+(c.service_type==='starlink'?'📡':c.service_type==='fiber'?'🌐':c.service_type==='lte'?'📶':'📦')+'</div>';
            h += '</td>';
            h += '<td><strong>'+esc(c.title)+'</strong>'+(c.description?'<br><small style="color:#94A3B8;">'+esc(c.description)+'</small>':'')+'</td>';
            h += '<td><code>'+esc(c.sku||'—')+'</code></td>';
            h += '<td>'+pill(c.service_type, c.service_type)+'</td>';
            h += '<td>'+esc(c.track_mode)+'</td>';
            h += '<td>'+fmt$(c.buy_price)+'</td>';
            h += '<td>'+fmt$(c.sell_price)+'</td>';
            h += '<td>'+c.min_stock+'</td>';
            h += '<td style="'+(isLow?'color:#DC2626;font-weight:800;':'')+'">'+avail+(isLow?' ⚠️':'')+'</td>';
            h += '<td><button class="stk-btn secondary sm" onclick="stkEditCat('+c.id+')">✏️</button></td>';
            h += '</tr>';
        });
        $('stkCatBody').innerHTML = h;
    });
};

window.stkShowCatForm = function(cat){
    cat = cat || {};
    $('stkCatModalTitle').textContent = cat.id ? 'Edit Category' : 'Add Category';
    $('catEditId').value = cat.id || 0;
    $('catTitle').value = cat.title || '';
    $('catSku').value = cat.sku || '';
    $('catService').value = cat.service_type || 'general';
    $('catTrack').value = cat.track_mode || 'serial';
    $('catBuy').value = cat.buy_price || '';
    $('catSell').value = cat.sell_price || '';
    $('catMin').value = cat.min_stock != null ? cat.min_stock : 2;
    $('catUnit').value = cat.unit || 'piece';
    $('catImage').value = cat.image_url || '';
    $('catImagePreview').innerHTML = cat.image_url ? '<img src="'+cat.image_url+'" style="width:44px;height:44px;object-fit:cover;" onerror="this.parentNode.innerHTML=\'\';">' : '';
    $('catDesc').value = cat.description || '';
    $('stkCatModal').classList.add('open');
    // Live preview on URL change
    $('catImage').oninput = function(){ var u=this.value.trim(); $('catImagePreview').innerHTML = u ? '<img src="'+u+'" style="width:44px;height:44px;object-fit:cover;" onerror="this.parentNode.innerHTML=\'No img\';">' : ''; };
};

window.stkEditCat = function(id){
    var cat = _catCache.find(function(c){return c.id===id;});
    if(cat) stkShowCatForm(cat);
};

window.stkSaveCat = function(){
    var data = {
        id: parseInt($('catEditId').value) || 0,
        title: $('catTitle').value,
        sku: $('catSku').value,
        service_type: $('catService').value,
        track_mode: $('catTrack').value,
        buy_price: parseFloat($('catBuy').value) || 0,
        sell_price: parseFloat($('catSell').value) || 0,
        min_stock: parseInt($('catMin').value) || 0,
        unit: $('catUnit').value,
        description: $('catDesc').value,
        image_url: $('catImage').value.trim(),
    };
    api('stock_category_save', {method:'POST', body:data}, function(err, r){
        if(err||!r||!r.data){ alert('Error: '+(r&&r.message||err)); return; }
        $('stkCatModal').classList.remove('open');
        stkLoadCatalog();
    });
};

window.stkSeedCatalog = function(){
    if(!confirm('This will import your existing KYC hardware items as stock categories and apply product images. Continue?')) return;
    api('stock_seed_catalog', {method:'POST', body:{}}, function(err, r){
        if(err){ alert('Error: '+err); return; }
        alert(r.message || 'Done');
        stkLoadCatalog();
    });
};

window.stkApplyImages = function(){
    api('stock_apply_images', {method:'POST', body:{}}, function(err, r){
        if(err){ alert('Error: '+err); return; }
        alert(r.message || 'Done');
        stkLoadCatalog();
    });
};

// ═══ INVENTORY ═══
window.stkLoadInventory = function(){
    var params = {
        search: $('stkInvSearch').value,
        service_type: $('stkInvService').value,
        status: $('stkInvStatus').value,
        limit: 50,
        offset: _invPage * 50,
    };
    api('stock_units', {params:params}, function(err, r){
        if(err||!r||!r.data){ $('stkInvBody').innerHTML = '<tr><td colspan="9">Error: '+esc(err || (r&&r.message) || 'Unknown error')+'</td></tr>'; return; }
        var d = r.data;
        $('stkInvCount').textContent = 'Showing '+ d.items.length +' of '+ d.total +' units';
        if(!d.items.length){ $('stkInvBody').innerHTML = '<tr><td colspan="9" style="text-align:center;color:#94A3B8;padding:40px;">No units found</td></tr>'; return; }
        var h = '';
        d.items.forEach(function(u){
            h += '<tr style="cursor:pointer;" onclick="stkShowDetail('+u.id+')">';
            h += '<td><strong>'+esc(u.serial_number||'—')+'</strong></td>';
            h += '<td>'+esc(u.category_name)+'</td>';
            h += '<td>'+pill(u.service_type, u.service_type)+'</td>';
            h += '<td>'+pill(u.status, u.status)+'</td>';
            h += '<td>'+esc(u.location_name||u.location_type||'—')+'</td>';
            h += '<td>'+esc(u.condition_grade||'—')+'</td>';
            h += '<td>'+fmt$(u.purchase_cost)+'</td>';
            h += '<td>'+fmtDate(u.updated_at)+'</td>';
            h += '<td onclick="event.stopPropagation();">';
            if(u.status==='in_stock') h += '<button class="stk-btn secondary sm" onclick="stkCheckoutPrompt('+u.id+',\''+esc(u.serial_number)+'\')">↗️ Out</button> ';
            if(u.status==='checked_out') h += '<button class="stk-btn secondary sm" onclick="stkCheckinPrompt('+u.id+')">↩️ In</button> ';
            if(u.status==='installed') h += '<button class="stk-btn secondary sm" onclick="stkReturnPrompt('+u.id+')">🔄 Return</button> ';
            if(u.status==='in_stock'||u.status==='returned') h += '<button class="stk-btn secondary sm" onclick="stkEditPrompt('+u.id+',\''+esc(u.serial_number).replace(/'/g,"\\'")+'\','+u.purchase_cost+',\''+esc(u.location_name||'').replace(/'/g,"\\'")+'\',\''+esc(u.notes||'').replace(/'/g,"\\'")+'\')">✏️</button> ';
            if(u.status==='in_stock'||u.status==='returned') h += '<button class="stk-btn sm" style="color:#ef4444;" onclick="stkDeletePrompt('+u.id+',\''+esc(u.serial_number)+'\')">🗑️</button>';
            h += '</td></tr>';
        });
        $('stkInvBody').innerHTML = h;

        // Pager
        var pages = Math.ceil(d.total / 50);
        var ph = '';
        for(var i=0;i<pages&&i<20;i++){
            ph += '<button class="stk-btn sm '+(i===_invPage?'primary':'secondary')+'" onclick="window._invPage='+i+';stkLoadInventory();">'+( i+1)+'</button>';
        }
        $('stkInvPager').innerHTML = ph;
    });
};

window.stkShowUnitForm = function(){
    // Populate category select
    api('stock_categories', {params:{active_only:1}}, function(err, r){
        if(err||!r||!r.data) return;
        var h = '<option value="">Select category...</option>';
        r.data.forEach(function(c){
            if(c.track_mode==='serial') h += '<option value="'+c.id+'" data-cost="'+c.buy_price+'">'+esc(c.title)+' ('+esc(c.sku||'')+')</option>';
        });
        $('unitCat').innerHTML = h;
    });
    $('unitSerial').value = '';
    $('unitSerial2').value = '';
    $('unitCond').value = 'new';
    $('unitLoc').value = 'DishNet UNMISS';
    $('unitCost').value = '';
    $('unitNotes').value = '';
    $('stkUnitModal').classList.add('open');
};

window.stkSaveUnit = function(){
    var catSel = $('unitCat');
    var data = {
        category_id: parseInt(catSel.value) || 0,
        serial_number: $('unitSerial').value,
        secondary_serial: $('unitSerial2').value,
        condition_grade: $('unitCond').value,
        location_name: $('unitLoc').value,
        purchase_cost: parseFloat($('unitCost').value) || 0,
        notes: $('unitNotes').value,
    };
    if(!data.category_id || !data.serial_number){ alert('Category and serial number are required'); return; }
    api('stock_unit_save', {method:'POST', body:data}, function(err, r){
        if(err||!r||r.status==='error'){ alert('Error: '+(r&&r.message||err)); return; }
        $('stkUnitModal').classList.remove('open');
        stkLoadInventory();
    });
};

window.stkShowDetail = function(unitId){
    api('stock_unit_detail', {params:{unit_id:unitId}}, function(err, r){
        if(err||!r||!r.data){ alert('Error loading detail'); return; }
        var u = r.data;
        var h = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">';
        h += '<div><strong>Serial:</strong> '+esc(u.serial_number)+'</div>';
        h += '<div><strong>Category:</strong> '+esc(u.category_name)+'</div>';
        h += '<div><strong>Service:</strong> '+pill(u.service_type, u.service_type)+'</div>';
        h += '<div><strong>Status:</strong> '+pill(u.status, u.status)+'</div>';
        h += '<div><strong>Location:</strong> '+esc(u.location_name||u.location_type)+'</div>';
        h += '<div><strong>Condition:</strong> '+esc(u.condition_grade)+'</div>';
        h += '<div><strong>Cost:</strong> '+fmt$(u.purchase_cost)+'</div>';
        h += '<div><strong>Created:</strong> '+fmtDate(u.created_at)+'</div>';
        if(u.starlink_account) h += '<div><strong>Starlink Account:</strong> '+esc(u.starlink_account)+'</div>';
        if(u.lte_imsi) h += '<div><strong>IMSI:</strong> '+esc(u.lte_imsi)+'</div>';
        if(u.crm_client_id) h += '<div><strong>CRM Client:</strong> #'+u.crm_client_id+'</div>';
        if(u.notes) h += '<div class="full"><strong>Notes:</strong> '+esc(u.notes)+'</div>';
        h += '</div>';

        // Movement timeline
        h += '<h4 style="margin:16px 0 8px;font-size:14px;font-weight:700;">Movement History</h4>';
        var mv = u.movements || [];
        if(!mv.length){ h += '<div style="color:#94A3B8;">No movements recorded</div>'; }
        else {
            h += '<div class="stk-timeline">';
            mv.forEach(function(m){
                h += '<div class="stk-tl-item">';
                h += '<div class="tl-date">'+fmtDateTime(m.created_at)+' — '+pill(m.movement_type, m.movement_type)+'</div>';
                h += '<div class="tl-body">';
                if(m.from_location_name) h += esc(m.from_location_name)+' → ';
                if(m.to_location_name) h += esc(m.to_location_name);
                if(m.performed_by_name) h += ' <small style="color:#94A3B8;">by '+esc(m.performed_by_name)+'</small>';
                if(m.note) h += '<br><small>'+esc(m.note)+'</small>';
                h += '</div></div>';
            });
            h += '</div>';
        }

        $('stkDetailTitle').textContent = u.serial_number || 'Unit #' + u.id;
        $('stkDetailBody').innerHTML = h;
        $('stkDetailModal').classList.add('open');
    });
};

// ═══ ACTIONS (checkout, checkin, return) ═══
window.stkCheckoutPrompt = function(unitId, serial){
    var h = '<div class="stk-form">';
    h += '<div class="stk-field"><label>Agent (Staff Member)</label><select id="actAgent"></select></div>';
    h += '<div class="stk-field"><label>Note</label><input id="actNote" placeholder="Optional"></div>';
    h += '</div>';
    h += '<div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">';
    h += '<button class="stk-btn secondary" onclick="document.getElementById(\'stkActionModal\').classList.remove(\'open\')">Cancel</button>';
    h += '<button class="stk-btn primary" onclick="stkDoCheckout('+unitId+')">Check Out</button>';
    h += '</div>';
    $('stkActionTitle').textContent = '↗️ Check Out: ' + (serial||'');
    $('stkActionBody').innerHTML = h;
    $('stkActionModal').classList.add('open');
    // Load agents
    var xhr = new XMLHttpRequest();
    xhr.open('GET','?page=stock_api&action=staff_list',true);
    xhr.onload = function(){
        try{
            var r = JSON.parse(xhr.responseText);
            var staff = (r.data||r||[]);
            if(!Array.isArray(staff)) staff = [];
            var sel = $('actAgent');
            sel.innerHTML = '<option value="">Select...</option>';
            staff.forEach(function(s){
                if(s.is_active) sel.innerHTML += '<option value="'+s.id+'" data-name="'+esc(s.name||'')+'">'+esc(s.name)+' ('+esc(s.role||'')+')</option>';
            });
        }catch(e){}
    };
    xhr.send();
};

window.stkDoCheckout = function(unitId){
    var sel = $('actAgent');
    var opt = sel.options[sel.selectedIndex];
    if(!sel.value){ alert('Select an agent'); return; }
    api('stock_checkout', {method:'POST', body:{
        unit_id: unitId,
        agent_rid: parseInt(sel.value),
        agent_name: opt.dataset.name || opt.textContent,
        note: $('actNote').value,
    }}, function(err, r){
        if(err||!r||r.status==='error'){ alert('Error: '+(r&&r.message||err)); return; }
        $('stkActionModal').classList.remove('open');
        stkLoadInventory();
    });
};

window.stkCheckinPrompt = function(unitId){
    var h = '<div class="stk-form">';
    h += '<div class="stk-field"><label>Condition</label><select id="actCond"><option value="good">Good</option><option value="fair">Fair</option><option value="damaged">Damaged</option></select></div>';
    h += '<div class="stk-field"><label>Note</label><input id="actNote2" placeholder="Optional"></div>';
    h += '</div>';
    h += '<div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">';
    h += '<button class="stk-btn secondary" onclick="document.getElementById(\'stkActionModal\').classList.remove(\'open\')">Cancel</button>';
    h += '<button class="stk-btn primary" onclick="stkDoCheckin('+unitId+')">Check In</button>';
    h += '</div>';
    $('stkActionTitle').textContent = '↩️ Check In';
    $('stkActionBody').innerHTML = h;
    $('stkActionModal').classList.add('open');
};

window.stkDoCheckin = function(unitId){
    api('stock_checkin', {method:'POST', body:{
        unit_id: unitId,
        condition: $('actCond').value,
        note: $('actNote2').value,
    }}, function(err, r){
        if(err||!r||r.status==='error'){ alert('Error: '+(r&&r.message||err)); return; }
        $('stkActionModal').classList.remove('open');
        stkLoadInventory();
    });
};

window.stkReturnPrompt = function(unitId){
    var h = '<div class="stk-form">';
    h += '<div class="stk-field"><label>Condition</label><select id="actCond3"><option value="good">Good</option><option value="fair">Fair</option><option value="damaged">Damaged</option></select></div>';
    h += '<div class="stk-field"><label>Note</label><input id="actNote3" placeholder="Reason for return"></div>';
    h += '</div>';
    h += '<div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">';
    h += '<button class="stk-btn secondary" onclick="document.getElementById(\'stkActionModal\').classList.remove(\'open\')">Cancel</button>';
    h += '<button class="stk-btn primary" onclick="stkDoReturn('+unitId+')">Return</button>';
    h += '</div>';
    $('stkActionTitle').textContent = '🔄 Customer Return';
    $('stkActionBody').innerHTML = h;
    $('stkActionModal').classList.add('open');
};

window.stkDoReturn = function(unitId){
    api('stock_return', {method:'POST', body:{
        unit_id: unitId,
        condition: $('actCond3').value,
        note: $('actNote3').value,
    }}, function(err, r){
        if(err||!r||r.status==='error'){ alert('Error: '+(r&&r.message||err)); return; }
        $('stkActionModal').classList.remove('open');
        stkLoadInventory();
    });
};

window.stkExportCsv = function(){
    var params = '&service_type='+encodeURIComponent($('stkInvService').value)
               + '&status='+encodeURIComponent($('stkInvStatus').value)
               + '&search='+encodeURIComponent($('stkInvSearch').value);
    window.open(API + 'stock_export_csv' + params, '_blank');
};

// ═══ RECEIVE STOCK ═══
var _rcvItemCount = 0;
window.stkAddRcvItem = function(){
    var idx = _rcvItemCount++;
    var div = document.createElement('div');
    div.className = 'stk-form cols3';
    div.style.marginBottom = '8px';
    div.style.padding = '12px';
    div.style.background = '#F8FAFC';
    div.style.borderRadius = '8px';
    div.innerHTML = '<div class="stk-field"><label>Category</label><select id="rcvCat'+idx+'" class="rcv-cat" onchange="stkRcvCatChange('+idx+')"></select></div>'
        + '<div class="stk-field" id="rcvSerialWrap'+idx+'"><label>Serial Number</label><input id="rcvSerial'+idx+'" placeholder="KIT, S/N..."></div>'
        + '<div class="stk-field" id="rcvQtyWrap'+idx+'" style="display:none;"><label>Quantity</label><input id="rcvQty'+idx+'" type="number" value="1" min="1"></div>'
        + '<div class="stk-field"><label>Unit Cost (USD)</label><input id="rcvCost'+idx+'" type="number" step="0.01"></div>'
        + '<div class="stk-field"><label>Notes</label><input id="rcvItemNote'+idx+'" placeholder="Optional"></div>'
        + '<div class="stk-field" style="justify-content:flex-end;"><button class="stk-btn danger sm" onclick="this.closest(\'.stk-form\').remove()">✕</button></div>';
    $('rcvItems').appendChild(div);
    // Fill category select
    if(_catCache.length){
        var sel = $('rcvCat'+idx);
        sel.innerHTML = '<option value="">Select...</option>';
        _catCache.forEach(function(c){
            sel.innerHTML += '<option value="'+c.id+'" data-mode="'+c.track_mode+'" data-cost="'+c.buy_price+'">'+esc(c.title)+' ('+esc(c.sku||'')+')</option>';
        });
    } else {
        api('stock_categories',{params:{active_only:1}},function(err,r){
            if(!r||!r.data) return;
            _catCache = r.data;
            stkAddRcvItem._fillSel(idx);
        });
    }
};
stkAddRcvItem._fillSel = function(idx){
    var sel = $('rcvCat'+idx);
    if(!sel) return;
    sel.innerHTML = '<option value="">Select...</option>';
    _catCache.forEach(function(c){
        sel.innerHTML += '<option value="'+c.id+'" data-mode="'+c.track_mode+'" data-cost="'+c.buy_price+'">'+esc(c.title)+' ('+esc(c.sku||'')+')</option>';
    });
};

window.stkRcvCatChange = function(idx){
    var sel = $('rcvCat'+idx);
    var opt = sel.options[sel.selectedIndex];
    var mode = opt.dataset.mode || 'serial';
    var cost = opt.dataset.cost || 0;
    $('rcvSerialWrap'+idx).style.display = mode==='serial' ? '' : 'none';
    $('rcvQtyWrap'+idx).style.display = mode==='quantity' ? '' : 'none';
    $('rcvCost'+idx).value = cost;
};

window.stkSubmitReceive = function(){
    var items = [];
    var itemDivs = $('rcvItems').querySelectorAll('.stk-form');
    for(var i=0; i<itemDivs.length; i++){
        var sel = itemDivs[i].querySelector('.rcv-cat');
        if(!sel || !sel.value) continue;
        var opt = sel.options[sel.selectedIndex];
        var mode = opt.dataset.mode || 'serial';
        var item = { category_id: parseInt(sel.value) };
        if(mode === 'serial'){
            item.serial_number = itemDivs[i].querySelector('input[id^="rcvSerial"]').value;
            item.purchase_cost = parseFloat(itemDivs[i].querySelector('input[id^="rcvCost"]').value) || 0;
        } else {
            item.quantity = parseInt(itemDivs[i].querySelector('input[id^="rcvQty"]').value) || 1;
        }
        items.push(item);
    }
    if(!items.length){ alert('Add at least one item'); return; }

    var payload = {
        supplier: $('rcvSupplier').value,
        invoice_number: $('rcvInvoice').value,
        purchase_date: $('rcvDate').value || new Date().toISOString().slice(0,10),
        total_cost: parseFloat($('rcvTotal').value) || 0,
        payment_method: $('rcvPayment').value,
        notes: $('rcvNotes').value,
        items: items,
    };

    api('stock_inbound', {method:'POST', body:payload}, function(err, r){
        if(err||!r||r.status==='error'){ $('rcvResult').innerHTML = '<div class="stk-alert warn">⚠️ '+(r&&r.message||err)+'</div>'; return; }
        $('rcvResult').innerHTML = '<div class="stk-alert ok">✅ '+esc(r.message)+' ('+((r.data||{}).items_created||0)+' items created)</div>';
        // Clear form
        $('rcvItems').innerHTML = '';
        _rcvItemCount = 0;
    });
};

// ═══ MOVEMENTS ═══
window.stkLoadMovements = function(){
    var params = {
        movement_type: $('stkMovType').value,
        date_from: $('stkMovFrom').value,
        date_to: $('stkMovTo').value,
        limit: 100,
    };
    api('stock_movements_log', {params:params}, function(err, r){
        if(err||!r||!r.data){ $('stkMovBody').innerHTML = '<tr><td colspan="7">Error</td></tr>'; return; }
        var items = r.data.items || [];
        if(!items.length){ $('stkMovBody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#94A3B8;padding:40px;">No movements found</td></tr>'; return; }
        var h = '';
        items.forEach(function(m){
            h += '<tr>';
            h += '<td>'+fmtDateTime(m.created_at)+'</td>';
            h += '<td>'+pill(m.movement_type, m.movement_type)+'</td>';
            h += '<td>'+(m.serial_number ? '<strong>'+esc(m.serial_number)+'</strong>' : esc(m.category_name)) + (m.quantity>1?' × '+m.quantity:'')+'</td>';
            h += '<td>'+esc(m.from_location_name||m.from_location_type||'—')+'</td>';
            h += '<td>'+esc(m.to_location_name||m.to_location_type||'—')+'</td>';
            h += '<td>'+esc(m.performed_by_name||'—')+'</td>';
            h += '<td>'+esc(m.note||'—')+'</td>';
            h += '</tr>';
        });
        $('stkMovBody').innerHTML = h;
    });
};

// ═══ AGENT HOLDINGS ═══
window.stkLoadHoldings = function(){
    api('stock_agent_holdings', {}, function(err, r){
        if(err||!r||!r.data){ $('stkHoldingsBody').innerHTML = '<div class="stk-alert warn">Error loading</div>'; return; }
        var agents = r.data;
        if(!agents.length){ $('stkHoldingsBody').innerHTML = '<div style="text-align:center;color:#94A3B8;padding:40px;">No agents currently hold any equipment.</div>'; return; }
        var h = '';
        agents.forEach(function(a){
            h += '<div class="stk-panel" style="margin-bottom:12px;">';
            h += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
            h += '<div><strong style="font-size:16px;">👤 '+esc(a.name)+'</strong> <span style="color:#64748B;font-size:13px;">('+a.items.length+' items, '+fmt$(a.total_value)+')</span></div>';
            h += '</div>';
            h += '<table class="stk-tbl"><thead><tr><th>Serial</th><th>Category</th><th>Service</th><th>Condition</th><th>Cost</th></tr></thead><tbody>';
            a.items.forEach(function(u){
                h += '<tr><td><strong>'+esc(u.serial_number)+'</strong></td>';
                h += '<td>'+esc(u.category_name)+'</td>';
                h += '<td>'+pill(u.service_type, u.service_type)+'</td>';
                h += '<td>'+esc(u.condition_grade||'—')+'</td>';
                h += '<td>'+fmt$(u.purchase_cost)+'</td></tr>';
            });
            h += '</tbody></table></div>';
        });
        $('stkHoldingsBody').innerHTML = h;
    });
};

// ═══ UTILS ═══
window.stkDebounce = function(fn, ms){
    clearTimeout(_dbTimer);
    _dbTimer = setTimeout(fn, ms);
};
window._invPage = _invPage;

// ═══ INIT ═══
$('rcvDate').value = new Date().toISOString().slice(0,10);

// Auto-select sub-tab from URL (?stock_tab=inout)
var _initStockTab = '<?= addslashes($stockTab) ?>';

// ── Edit stock unit ────────────────────────────────────────────────
window.stkEditPrompt = function(id, serial, cost, location, notes) {
    var newSerial = prompt('Serial Number:', serial);
    if (newSerial === null) return;
    var newCost = prompt('Cost ($):', cost);
    if (newCost === null) return;
    var newLoc = prompt('Location:', location);
    if (newLoc === null) return;
    var newNotes = prompt('Notes:', notes || '');
    if (newNotes === null) return;
    api('stock_unit_update', {method:'POST', body:{
        unit_id: id,
        serial_number: newSerial.trim() || serial,
        purchase_cost: parseFloat(newCost) || cost,
        location_name: newLoc.trim() || location,
        notes: newNotes.trim()
    }}, function(err, r) {
        if (!err && r && r.status === 'success') {
            stkLoadInventory();
            stkFlash('Unit updated: ' + (newSerial.trim() || serial));
        } else {
            alert('Update failed: ' + (err || (r && r.message) || 'Unknown error'));
        }
    });
};

// ── Delete stock unit ─────────────────────────────────────────────
window.stkDeletePrompt = function(id, serial) {
    var reason = prompt('Delete "' + serial + '" from stock?\n\nReason (required):', 'Added by mistake');
    if (!reason) return;
    if (!confirm('CONFIRM DELETE:\n\nSerial: ' + serial + '\nReason: ' + reason + '\n\nThis cannot be undone.')) return;
    api('stock_unit_delete', {method:'POST', body:{
        unit_id: id,
        reason: reason.trim()
    }}, function(err, r) {
        if (!err && r && r.status === 'success') {
            stkLoadInventory();
            stkFlash('Deleted: ' + serial, 'warn');
        } else {
            alert('Delete failed: ' + (err || (r && r.message) || 'Unknown error'));
        }
    });
};

function stkFlash(msg, type) {
    var el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;background:' + (type==='warn'?'#f59e0b':'#22c55e') + ';box-shadow:0 4px 12px rgba(0,0,0,0.3);';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function() { el.remove(); }, 3000);
}

if (_initStockTab && _initStockTab !== 'dashboard') {
    var initTabBtn = document.querySelector('.stk-tab[data-tab="'+_initStockTab+'"]');
    if (initTabBtn) { initTabBtn.click(); }
    else { stkLoadDashboard(); }
} else {
    stkLoadDashboard();
}

})();
</script>
