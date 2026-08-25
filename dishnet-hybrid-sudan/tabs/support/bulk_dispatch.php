<?php
// Tab: bulk_dispatch
// Extracted from public.php on 2026-03-15
        $apiToken = h($retailer['api_token'] ?? "");
    ?>

<style>
.bd-hero{background:linear-gradient(135deg,#4527A0,#6A1B9A);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;}
.bd-section{background:#fff;border-radius:16px;padding:16px;margin-bottom:14px;box-shadow:0 2px 8px rgba(0,0,0,.05);border:1px solid #f1f5f9;}
.bd-section-title{font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;}
.bd-search-bar{display:flex;gap:8px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:4px;transition:.2s;}
.bd-search-bar:focus-within{border-color:#7C3AED;box-shadow:0 0 0 3px rgba(124,58,237,.08);}
.bd-search-bar input{flex:1;border:none;background:transparent;padding:10px 12px;font-size:15px;outline:none;}
.bd-cust-result{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-radius:10px;background:#f8fafc;margin-bottom:6px;cursor:pointer;transition:.15s;border:1.5px solid transparent;}
.bd-cust-result:hover{border-color:#7C3AED;background:#F5F3FF;}
.bd-batch-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:12px;background:#F5F3FF;border:1px solid #DDD6FE;margin-bottom:6px;}
.bd-batch-num{width:24px;height:24px;border-radius:50%;background:#6D28D9;color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;}
.bd-remove{background:none;border:none;color:#9ca3af;cursor:pointer;font-size:16px;padding:2px 6px;border-radius:6px;flex-shrink:0;}
.bd-remove:hover{color:#dc3545;background:#FFEBEE;}
.bd-task-tag{display:inline-flex;align-items:center;gap:4px;background:#EDE7F6;color:#4527A0;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;margin:3px;}
.bd-task-tag button{background:none;border:none;color:#7B1FA2;cursor:pointer;padding:0;line-height:1;font-size:14px;}
.bd-fire-btn{width:100%;background:linear-gradient(135deg,#4527A0,#7B1FA2);color:#fff;border:none;border-radius:14px;padding:16px;font-size:15px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 4px 16px rgba(69,39,160,.4);}
.bd-fire-btn:disabled{opacity:.5;cursor:not-allowed;}
.bd-result-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:10px;margin-bottom:4px;}
.bd-result-row.created{background:#E8F5E9;}
.bd-result-row.failed{background:#FFEBEE;}
/* Route map */
.bd-route-card{background:#fff;border-radius:14px;padding:12px 14px;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1px solid #f1f5f9;display:flex;gap:12px;align-items:flex-start;}
.bd-route-num{width:28px;height:28px;border-radius:50%;background:#4527A0;color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
</style>

<div class="bd-hero">
    <div style="font-size:11px;opacity:.6;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Support Leader · Bidal</div>
    <div style="font-size:22px;font-weight:800;margin-top:4px;">🔧 Fiber Deployment Dispatch</div>
    <div style="font-size:12px;opacity:.75;margin-top:4px;">Create field jobs for DishNet engineers joining the fiber partner on-site — one job per customer, auto-synced to UCRM</div>
    <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;">
        <div id="bdBatchCount" style="background:rgba(255,255,255,.2);border-radius:10px;padding:6px 14px;font-size:13px;font-weight:700;">0 customers in batch</div>
        <button onclick="bdShowRoute()" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:10px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">📍 View Route</button>
    </div>
</div>

<!-- ── View toggle: Dispatch | Route | Today's Jobs | Batch History ── -->
<div style="display:flex;gap:4px;margin-bottom:14px;background:#f1f5f9;border-radius:12px;padding:4px;">
    <button id="bdTabDispatch" onclick="bdSwitchTab('dispatch')" style="flex:1;padding:8px;border-radius:9px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:#fff;color:#4527A0;box-shadow:0 1px 4px rgba(0,0,0,.08);">⚡ Dispatch</button>
    <button id="bdTabRoute" onclick="bdSwitchTab('route')" style="flex:1;padding:8px;border-radius:9px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#6b7280;">🗺 Route</button>
    <button id="bdTabHistory" onclick="bdSwitchTab('history')" style="flex:1;padding:8px;border-radius:9px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#6b7280;">📋 Today</button>
    <button id="bdTabBatches" onclick="bdSwitchTab('batches')" style="flex:1;padding:8px;border-radius:9px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#6b7280;">📦 Batches</button>
</div>

<!-- ═══════════════════════ DISPATCH TAB ═══════════════════════ -->
<div id="bdPaneDispatch">

<!-- STEP 1: Deployment Info -->
<div class="bd-section">
    <div class="bd-section-title">① Deployment Info</div>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Batch Name * <span style="font-weight:400;color:#9ca3af;">(e.g. Thong Ping St — March 6)</span></label>
                <input type="text" id="bdBatchName" class="form-control" placeholder="e.g. Gudele Block C Fiber Rollout">
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Fiber Partner Name <span style="font-weight:400;color:#9ca3af;">(external installer)</span></label>
                <input type="text" id="bdFiberPartner" class="form-control" placeholder="e.g. SudaFiber Ltd, AfricaNet, etc.">
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Job Title in UCRM *</label>
                <input type="text" id="bdJobTitle" class="form-control" placeholder="e.g. Fiber Installation — After-Sales Support">
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Job Type</label>
                <select id="bdJobType" class="form-control">
                    <option value="installation" selected>Installation</option>
                    <option value="other">General Visit</option>
                    <option value="repair">Repair</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Duration (min)</label>
                <select id="bdDuration" class="form-control">
                    <option value="30">30 min</option>
                    <option value="60">1 hour</option>
                    <option value="90" selected>1.5 hours</option>
                    <option value="120">2 hours</option>
                    <option value="180">3 hours</option>
                </select>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Deployment Date *</label>
                <input type="date" id="bdJobDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Default Start Time</label>
                <input type="time" id="bdJobTime" class="form-control" value="09:00">
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label class="form-label" style="font-size:12px;font-weight:700;">Default Engineer <span style="font-weight:400;color:#9ca3af;">(can override per customer)</span></label>
                <select id="bdAssignee" class="form-control">
                    <option value="">Loading staff…</option>
                </select>
            </div>
        </div>
    </div>
    <div class="form-group">
        <label class="form-label" style="font-size:12px;font-weight:700;">Default Job Notes / Instructions</label>
        <textarea id="bdNoteTemplate" class="form-control" rows="2" placeholder="e.g. Configure ONT, take before/after photos, collect first payment, get signature"></textarea>
    </div>
</div>

<!-- STEP 2: Task Checklist -->
<div class="bd-section">
    <div class="bd-section-title">② Checklist Tasks (optional)</div>
    <div style="display:flex;gap:8px;margin-bottom:10px;">
        <input type="text" id="bdTaskInput" class="form-control" placeholder="e.g. Take GPS photo, Check signal strength, Fill survey form" onkeydown="if(event.key==='Enter'){event.preventDefault();bdAddTask();}">
        <button onclick="bdAddTask()" style="background:#6D28D9;color:#fff;border:none;border-radius:10px;padding:8px 16px;font-weight:700;cursor:pointer;white-space:nowrap;">+ Add</button>
    </div>
    <div id="bdTaskList" style="min-height:24px;"></div>
    <div style="font-size:11px;color:#9ca3af;margin-top:6px;">These tasks appear as a checklist on each field agent's job card. They can tick them off on-site.</div>
    <!-- Quick task templates -->
    <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;">
        <div style="font-size:11px;color:#6b7280;width:100%;margin-bottom:4px;">Quick add:</div>
        <?php
        $quickTasks = ['Take GPS photo','Check cable condition','Measure signal strength','Fill survey form','Confirm customer details','Test internet speed','Photograph installation'];
        ?>
        <script>
        var QUICK_TASKS = <?= json_encode($quickTasks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        </script>
        <div id="bdQuickTaskBtns"></div>
        <script>
        (function(){
            var c = document.getElementById('bdQuickTaskBtns');
            if (!c) return;
            QUICK_TASKS.forEach(function(t, i) {
                var btn = document.createElement('button');
                btn.textContent = t;
                btn.style.cssText = 'background:#F5F3FF;color:#6D28D9;border:1px solid #DDD6FE;border-radius:20px;padding:4px 12px;font-size:11px;font-weight:600;cursor:pointer;margin:3px;';
                btn.onclick = function(){ bdAddTaskVal(t); };
                c.appendChild(btn);
            });
        })();
        </script>
    </div>
</div>

<!-- STEP 3: Customer Batch -->
<div class="bd-section">
    <div class="bd-section-title">③ Add Customers to Batch</div>
    <div class="bd-search-bar" style="margin-bottom:10px;">
        <input type="text" id="bdSearchInput" placeholder="Search customer by name, phone, CRM ID…" onkeyup="bdSearchDebounce()" oninput="bdSearchDebounce()">
        <button onclick="bdSearchCustomers()" style="background:#7C3AED;color:#fff;border:none;border-radius:9px;padding:8px 16px;font-weight:700;cursor:pointer;">Search</button>
    </div>
    <div id="bdSearchResults" style="margin-bottom:14px;"></div>

    <!-- Batch list -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div style="font-size:12px;font-weight:800;color:#4527A0;">Batch (<span id="bdBatchNum">0</span> customers)</div>
        <button onclick="bdClearBatch()" style="background:none;border:none;color:#9ca3af;font-size:12px;cursor:pointer;">Clear all</button>
    </div>
    <div id="bdBatchList"><div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">Search and add customers above ↑</div></div>
</div>

<!-- STEP 4: Fire! -->
<div class="bd-section" style="background:linear-gradient(135deg,#F5F3FF,#EDE7F6);">
    <div class="bd-section-title">④ Launch Jobs</div>
    <div id="bdSummaryPreview" style="margin-bottom:14px;font-size:13px;color:#4527A0;font-weight:600;"></div>
    <button id="bdFireBtn" class="bd-fire-btn" onclick="bdFire()">
        <span style="font-size:20px;">🚀</span> Create Jobs in UCRM for All Customers
    </button>
    <div style="font-size:11px;color:#7b3fed;text-align:center;margin-top:8px;">One UCRM scheduling job will be created per customer. Field agents will see them in their My Jobs tab immediately.</div>
</div>

<!-- Results panel -->
<div id="bdResultsPanel" style="display:none;"></div>

</div><!-- end dispatch pane -->

<!-- ═══════════════════════ ROUTE OVERVIEW PANE ═══════════════════════ -->
<div id="bdPaneRoute" style="display:none;">
<div class="bd-section">
    <div class="bd-section-title">Route Overview — Current Batch</div>
    <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">Customers sorted by GPS proximity — optimal field visit order.</div>
    <div id="bdRouteList"><div style="text-align:center;padding:30px;color:#9ca3af;">Add customers to batch first, then view their route here.</div></div>
    <a id="bdOpenMapsRoute" href="#" target="_blank" style="display:none;margin-top:12px;background:#1B5E20;color:#fff;border-radius:12px;padding:12px 20px;font-size:13px;font-weight:700;text-align:center;text-decoration:none;display:block;">🗺 Open Full Route in Google Maps</a>
</div>
</div>

<!-- ═══════════════════════ TODAY'S JOBS PANE ═══════════════════════ -->
<div id="bdPaneHistory" style="display:none;">
<div class="bd-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div class="bd-section-title" style="margin-bottom:0;">All Jobs — <input type="date" id="bdHistDate" value="<?= date('Y-m-d') ?>" onchange="bdLoadHistory()" style="border:1.5px solid #DDD6FE;border-radius:8px;padding:3px 8px;font-size:12px;color:#4527A0;font-weight:700;"></div>
        <button onclick="bdLoadHistory()" style="background:#EDE7F6;color:#4527A0;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;">↻ Refresh</button>
    </div>
    <div id="bdHistoryList"><div style="text-align:center;padding:30px;color:#9ca3af;">Select a date to view all scheduled jobs.</div></div>
</div>
</div>

<!-- ═══════════════════════ BATCH HISTORY PANE ═══════════════════════ -->
<div id="bdPaneBatches" style="display:none;">
<div class="bd-section">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div>
            <div class="bd-section-title" style="margin-bottom:2px;">📦 Deployment Batches</div>
            <div style="font-size:11px;color:#9ca3af;">All fiber deployment batches dispatched by you</div>
        </div>
        <button onclick="bdLoadBatches()" style="background:#EDE7F6;color:#4527A0;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;">↻ Refresh</button>
    </div>
    <div id="bdBatchesList"><div style="text-align:center;padding:30px;color:#9ca3af;">Loading batches…</div></div>
</div>
</div>

<div style="height:80px;"></div>

<script>
(function(){
var TOKEN   = '<?= $apiToken ?>';
var headers = { 'Authorization': 'Bearer ' + TOKEN, 'Content-Type': 'application/json' };
var batch   = [];   // [{crm_id, name, phone, address, gps, note, assignee_id, assignee_name, job_time}]

var tasks   = [];   // task name strings
var searchTimer = null;

function apiGet(act, qs){return fetch('?page=api&action='+act+(qs||''),{credentials:'same-origin',headers:headers}).then(function(r){ return r.text().then(function(t){ try{ return JSON.parse(t); } catch(e){ return {status:'error',message:'Bad JSON: '+t.substring(0,300)}; } }); }); }
function apiPost(act, body){ return fetch('?page=api&action='+act,{
          credentials:'same-origin',
          method:'POST',headers:headers,body:JSON.stringify(body)}).then(function(r){ return r.text().then(function(t){ try{ return JSON.parse(t); } catch(e){ return {status:'error',message:'Bad JSON: '+t.substring(0,300)}; } }); }); }
function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmt(s){ return s ? String(s).substring(0,40) : ''; }

// ── Tab switching ─────────────────────────────────────────────
window.bdSwitchTab = function(tab) {
    ['dispatch','route','history','batches'].forEach(function(t) {
        var pane = document.getElementById('bdPane'+t.charAt(0).toUpperCase()+t.slice(1));
        var btn  = document.getElementById('bdTab'+t.charAt(0).toUpperCase()+t.slice(1));
        if (pane) pane.style.display = t===tab ? '' : 'none';
        if (btn) { btn.style.background = t===tab ? '#fff' : 'transparent'; btn.style.color = t===tab ? '#4527A0' : '#6b7280'; btn.style.boxShadow = t===tab ? '0 1px 4px rgba(0,0,0,.08)' : 'none'; }
    });
    if (tab === 'history') bdLoadHistory();
    if (tab === 'route')   bdShowRoute();
    if (tab === 'batches') bdLoadBatches();
};

// ── Load support staff into assignee dropdown ─────────────────
apiGet('get_support_staff').then(function(d) {
    var sel = document.getElementById('bdAssignee');
    if (!sel) return;
    if (d.status !== 'success' || !d.data.length) {
        sel.innerHTML = '<option value="">No staff with UCRM ID linked</option>';
        window._bdStaffOptions = '';
        return;
    }
    var opts = '<option value="">— Unassigned —</option>';
    d.data.forEach(function(s) {
        opts += '<option value="' + s.ucrm_user_id + '">' + escHtml(s.name) + ' (' + s.role.replace('_',' ') + ')</option>';
    });
    sel.innerHTML = opts;
    window._bdStaffOptions = opts; // cache for per-customer selects
});

// ── Customer search with debounce ─────────────────────────────
window.bdSearchDebounce = function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(bdSearchCustomers, 500);
};

window.bdSearchCustomers = function() {
    var q = document.getElementById('bdSearchInput').value.trim();
    if (q.length < 2) return;
    var box = document.getElementById('bdSearchResults');
    box.innerHTML = '<div style="color:#9ca3af;font-size:12px;padding:8px;">Searching…</div>';

    apiGet('crm_search_customer', '&q=' + encodeURIComponent(q)).then(function(d) {
        if (d.status !== 'success' || !d.data.length) {
            box.innerHTML = '<div style="color:#9ca3af;font-size:12px;padding:8px;">No results</div>';
            return;
        }
        var customers = Array.isArray(d.data) ? d.data : (d.data.items || d.data);
        var html = '';
        customers.slice(0, 8).forEach(function(c) {
            var name   = escHtml((c.firstName||'') + ' ' + (c.lastName||''));
            var phone  = c.contacts && c.contacts[0] ? c.contacts[0].phone || '' : '';
            var addr   = escHtml((c.street1||'') + (c.city ? ', '+c.city : ''));
            var inBatch = batch.some(function(b){ return b.crm_id === c.id; });
            html += '<div class="bd-cust-result" onclick="bdAddCustomer('+JSON.stringify(c)+')" style="'+(inBatch?'border-color:#6D28D9;background:#F5F3FF;':'') +'">';
            html += '<div style="flex:1;min-width:0;">';
            html += '<div style="font-size:13px;font-weight:700;color:#1e293b;">'+name+(inBatch?' <span style="color:#6D28D9;font-size:10px;">✓ In batch</span>':'')+'</div>';
            html += '<div style="font-size:11px;color:#6b7280;">#'+c.id+(phone?' · '+escHtml(phone):'')+(addr?' · '+addr:'')+'</div>';
            html += '</div>';
            html += '<span style="font-size:18px;color:'+(inBatch?'#6D28D9':'#d1d5db')+';">'+(inBatch?'✓':'＋')+'</span>';
            html += '</div>';
        });
        box.innerHTML = html;
    }).catch(function() {
        box.innerHTML = '<div style="color:#dc3545;font-size:12px;padding:8px;">Search failed</div>';
    });
};

window.bdAddCustomer = function(c) {
    if (batch.some(function(b){ return b.crm_id === c.id; })) return; // already in batch
    var phone = c.contacts && c.contacts[0] ? c.contacts[0].phone || '' : '';
    batch.push({
        crm_id      : c.id,
        name        : ((c.firstName||'') + ' ' + (c.lastName||'')).trim(),
        phone       : phone,
        address     : ((c.street1||'') + (c.city ? ', '+c.city : '')).trim(),
        gps         : (c.gpsLat && c.gpsLon) ? {lat: parseFloat(c.gpsLat), lon: parseFloat(c.gpsLon)} : null,
        note        : '',
        assignee_id : 0,
        assignee_name: '',
        job_time    : document.getElementById('bdJobTime').value || '09:00',
    });
    bdRenderBatch();
    bdSearchCustomers(); // refresh search to show checkmark
    document.getElementById('bdSearchInput').focus();
};

window.bdRemoveCustomer = function(idx) {
    batch.splice(idx, 1);
    bdRenderBatch();
};

function bdRenderBatch() {
    var list = document.getElementById('bdBatchList');
    var countEl = document.getElementById('bdBatchNum');
    var heroEl  = document.getElementById('bdBatchCount');
    if (countEl) countEl.textContent = batch.length;
    if (heroEl)  heroEl.textContent  = batch.length + ' customer' + (batch.length===1?'':'s') + ' in batch';

    if (!batch.length) {
        list.innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">Search and add customers above ↑</div>';
        bdUpdateSummary();
        return;
    }
    var html = '';
    batch.forEach(function(c, idx) {
        html += '<div class="bd-batch-item" style="flex-direction:column;gap:8px;">';
        // Row 1: number + name + remove
        html += '<div style="display:flex;align-items:flex-start;gap:10px;">';
        html += '<div class="bd-batch-num">'+(idx+1)+'</div>';
        html += '<div style="flex:1;min-width:0;">';
        html += '<div style="font-size:13px;font-weight:700;color:#1e293b;">'+escHtml(c.name)+'</div>';
        html += '<div style="font-size:11px;color:#6b7280;">#'+c.crm_id+(c.phone?' · '+escHtml(c.phone):'')+(c.address?' · '+escHtml(c.address):'')+'</div>';
        html += '</div>';
        html += '<button class="bd-remove" onclick="bdRemoveCustomer('+idx+')">✕</button>';
        html += '</div>';
        // Row 2: engineer assignment + time slot
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;padding-left:34px;">';
        html += '<div>';
        html += '<div style="font-size:10px;font-weight:700;color:#7C3AED;margin-bottom:3px;">👷 Assign Engineer</div>';
        html += '<select onchange="bdUpdateAssignee('+idx+',this.value,this.options[this.selectedIndex].text)" style="width:100%;border:1.5px solid #DDD6FE;border-radius:8px;padding:6px 8px;font-size:12px;background:#fff;" id="bdEngSel_'+idx+'">';
        html += '<option value="">— Unassigned —</option>';
        // staff options injected by JS below
        html += '</select>';
        html += '</div>';
        html += '<div>';
        html += '<div style="font-size:10px;font-weight:700;color:#7C3AED;margin-bottom:3px;">🕐 Time Slot</div>';
        html += '<input type="time" value="'+(c.job_time||document.getElementById('bdJobTime').value||'09:00')+'" onchange="bdUpdateTime('+idx+',this.value)" style="width:100%;border:1.5px solid #DDD6FE;border-radius:8px;padding:6px 8px;font-size:12px;">';
        html += '</div>';
        html += '</div>';
        // Row 3: note
        html += '<div style="padding-left:34px;">';
        html += '<input type="text" placeholder="Per-customer note (optional)" value="'+escHtml(c.note)+'" oninput="bdUpdateNote('+idx+',this.value)" style="width:100%;border:1px solid #DDD6FE;border-radius:8px;padding:5px 10px;font-size:11px;background:#fff;">';
        html += '</div>';
        html += '</div>';
    });
    list.innerHTML = html;
    // Populate engineer selects with current staff list
    batch.forEach(function(c, idx) {
        var sel = document.getElementById('bdEngSel_'+idx);
        if (!sel || !window._bdStaffOptions) return;
        sel.innerHTML = '<option value="">— Unassigned —</option>' + window._bdStaffOptions;
        if (c.assignee_id) sel.value = String(c.assignee_id);
    });
    bdUpdateSummary();
}

window.bdUpdateNote = function(idx, val) {
    if (batch[idx]) batch[idx].note = val;
};
window.bdUpdateAssignee = function(idx, val, name) {
    if (batch[idx]) { batch[idx].assignee_id = parseInt(val)||0; batch[idx].assignee_name = name||''; }
    bdUpdateSummary();
};
window.bdUpdateTime = function(idx, val) {
    if (batch[idx]) batch[idx].job_time = val;
};

function bdUpdateSummary() {
    var el = document.getElementById('bdSummaryPreview');
    var title = (document.getElementById('bdJobTitle').value||'').trim();
    var date  = document.getElementById('bdJobDate').value;
    var time  = document.getElementById('bdJobTime').value;
    var sel   = document.getElementById('bdAssignee');
    var agent = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '—';
    if (!el) return;
    if (!batch.length) { el.innerHTML = 'Add customers to the batch to begin.'; return; }
    el.innerHTML = '⚡ Ready to create <strong>'+batch.length+' job'+(batch.length===1?'':'s')+'</strong>'
        + (title ? ' titled "<strong>'+escHtml(title)+'</strong>"' : '')
        + ' on <strong>'+(date||'—')+'</strong> at <strong>'+(time||'—')+'</strong>'
        + (sel.value ? ' assigned to <strong>'+escHtml(agent)+'</strong>' : ', unassigned')
        + (tasks.length ? ' with <strong>'+tasks.length+' task'+(tasks.length===1?'':'s')+'</strong>' : '')
        + '.';
}

// ── Task management ───────────────────────────────────────────
window.bdAddTask = function() {
    var inp = document.getElementById('bdTaskInput');
    var val = inp.value.trim();
    if (!val || tasks.includes(val)) { inp.value=''; return; }
    tasks.push(val);
    inp.value = '';
    bdRenderTasks();
};
window.bdAddTaskVal = function(val) {
    if (!tasks.includes(val)) { tasks.push(val); bdRenderTasks(); }
};
window.bdRemoveTask = function(idx) { tasks.splice(idx,1); bdRenderTasks(); };
function bdRenderTasks() {
    var el = document.getElementById('bdTaskList');
    if (!tasks.length) { el.innerHTML = '<div style="color:#9ca3af;font-size:12px;">No tasks added</div>'; return; }
    el.innerHTML = tasks.map(function(t,i){ return '<span class="bd-task-tag">'+escHtml(t)+'<button onclick="bdRemoveTask('+i+')">×</button></span>'; }).join('');
}

// Listen to form field changes to update summary
['bdJobTitle','bdJobDate','bdJobTime','bdAssignee'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', bdUpdateSummary);
    if (el) el.addEventListener('change', bdUpdateSummary);
});

// ── FIRE ─────────────────────────────────────────────────────
window.bdFire = function() {
    var title = (document.getElementById('bdJobTitle').value||'').trim();
    var date  = document.getElementById('bdJobDate').value;
    if (!title) { alert('Please enter a job title first.'); document.getElementById('bdJobTitle').focus(); return; }
    if (!date)  { alert('Please select a date.'); return; }
    if (!batch.length) { alert('Add at least one customer to the batch.'); return; }

    var n = batch.length;
    if (!confirm('Create ' + n + ' job' + (n===1?'':'s') + ' in UCRM for "' + title + '" on ' + date + '?\n\nThis will create individual scheduling jobs for each customer.')) return;

    var btn = document.getElementById('bdFireBtn');
    btn.disabled = true;
    btn.innerHTML = '<span style="font-size:20px;animation:spin 1s linear infinite;display:inline-block;">↻</span> Creating ' + n + ' jobs in UCRM…';

    var payload = {
        batch_name    : (document.getElementById('bdBatchName').value||'').trim() || title,
        job_title     : title,
        job_type      : document.getElementById('bdJobType').value,
        job_date      : date,
        job_time      : document.getElementById('bdJobTime').value || '09:00',
        duration      : parseInt(document.getElementById('bdDuration').value) || 90,
        assignee_id   : parseInt(document.getElementById('bdAssignee').value) || 0,
        fiber_partner : (document.getElementById('bdFiberPartner').value||'').trim(),
        note_template : document.getElementById('bdNoteTemplate').value,
        tasks         : tasks,
        customers     : batch.map(function(c){ return {
            crm_id        : c.crm_id,
            note          : c.note,
            assignee_id   : c.assignee_id || 0,
            assignee_name : c.assignee_name || '',
            job_time      : c.job_time || '',
        }; }),
    };

    apiPost('bulk_create_jobs', payload).then(function(d) {
        btn.disabled = false;
        btn.innerHTML = '<span style="font-size:20px;">🚀</span> Create Jobs in UCRM for All Customers';

        if (d.status !== 'success') {
            document.getElementById('bdResultsPanel').style.display = 'block';
            document.getElementById('bdResultsPanel').innerHTML = '<div style="background:#FFEBEE;border-radius:14px;padding:16px;color:#C62828;">⚠ Failed: '+(d.message||'Unknown error')+'</div>';
            return;
        }
        var res    = d.data;
        var panel  = document.getElementById('bdResultsPanel');
        panel.style.display = 'block';
        var html = '<div style="background:#fff;border-radius:16px;padding:16px;box-shadow:0 2px 10px rgba(0,0,0,.06);border:1px solid #f1f5f9;">';
        html += '<div style="font-size:15px;font-weight:800;color:#1e293b;margin-bottom:12px;">📋 Dispatch Results</div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px;">';
        html += '<div style="text-align:center;background:#E8F5E9;border-radius:10px;padding:10px;"><div style="font-size:22px;font-weight:800;color:#2E7D32;">'+res.created+'</div><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Created</div></div>';
        html += '<div style="text-align:center;background:#FFEBEE;border-radius:10px;padding:10px;"><div style="font-size:22px;font-weight:800;color:#C62828;">'+res.failed+'</div><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Failed</div></div>';
        html += '<div style="text-align:center;background:#f1f5f9;border-radius:10px;padding:10px;"><div style="font-size:22px;font-weight:800;color:#374151;">'+res.total+'</div><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Total</div></div>';
        html += '</div>';
        (res.results||[]).forEach(function(r) {
            html += '<div class="bd-result-row '+r.status+'">';
            html += '<span style="font-size:16px;">'+(r.status==='created'?'✅':'❌')+'</span>';
            html += '<div style="flex:1;"><div style="font-size:13px;font-weight:700;">'+escHtml(r.name||'Client #'+r.crm_id)+'</div>';
            if (r.job_id) html += '<div style="font-size:11px;color:#6b7280;">UCRM Job #'+r.job_id+'</div>';
            if (r.error)  html += '<div style="font-size:11px;color:#dc3545;">'+escHtml(r.error)+'</div>';
            html += '</div></div>';
        });
        if (res.created > 0) {
            html += '<div style="margin-top:12px;font-size:12px;color:#2E7D32;font-weight:700;">✓ Field agents will see these jobs in their My Jobs tab immediately.</div>';
            // Clear batch after successful dispatch
            batch = [];
            bdRenderBatch();
        }
        html += '</div>';
        panel.innerHTML = html;
        panel.scrollIntoView({behavior:'smooth'});
    }).catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<span style="font-size:20px;">🚀</span> Create Jobs in UCRM for All Customers';
        document.getElementById('bdResultsPanel').style.display = 'block';
        document.getElementById('bdResultsPanel').innerHTML = '<div style="background:#FFEBEE;border-radius:14px;padding:16px;color:#C62828;">⚠ Network error — check connection</div>';
    });
};

// ── Route overview ────────────────────────────────────────────
window.bdShowRoute = function() {
    bdSwitchTab('route');
    var list = document.getElementById('bdRouteList');
    if (!batch.length) {
        list.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;">Add customers to batch first.</div>';
        return;
    }

    // Sort by GPS proximity if available (nearest-first from Juba city center as fallback origin)
    var origin = {lat: 4.8594, lon: 31.5713}; // Juba, South Sudan
    var sorted = batch.map(function(c, origIdx) {
        var dist = 99999;
        if (c.gps) {
            var dlat = c.gps.lat - origin.lat;
            var dlon = c.gps.lon - origin.lon;
            dist = Math.sqrt(dlat*dlat + dlon*dlon);
        }
        return Object.assign({}, c, {_dist: dist, _origIdx: origIdx});
    }).sort(function(a,b){ return a._dist - b._dist; });

    var html = '';
    var mapsWaypoints = [];
    sorted.forEach(function(c, i) {
        var hasGps = !!c.gps;
        var mapsUrl = hasGps
            ? 'https://www.google.com/maps/dir/?api=1&destination='+c.gps.lat+','+c.gps.lon
            : 'https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(c.address||c.name);
        if (hasGps) mapsWaypoints.push(c.gps.lat+','+c.gps.lon);
        html += '<div class="bd-route-card">';
        html += '<div class="bd-route-num">'+(i+1)+'</div>';
        html += '<div style="flex:1;min-width:0;">';
        html += '<div style="font-size:14px;font-weight:700;color:#1e293b;">'+escHtml(c.name)+'</div>';
        if (c.address) html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;">📍 '+escHtml(c.address)+'</div>';
        html += hasGps
            ? '<div style="font-size:10px;color:#6D28D9;margin-top:2px;">📡 GPS: '+c.gps.lat.toFixed(4)+', '+c.gps.lon.toFixed(4)+'</div>'
            : '<div style="font-size:10px;color:#FF9800;margin-top:2px;">⚠ No GPS — using address</div>';
        if (c.phone) html += '<div style="font-size:11px;color:#374151;margin-top:2px;">📞 '+escHtml(c.phone)+'</div>';
        html += '</div>';
        html += '<a href="'+mapsUrl+'" target="_blank" style="background:#E8F5E9;color:#2E7D32;border:none;border-radius:10px;padding:8px 10px;font-size:12px;font-weight:700;text-decoration:none;flex-shrink:0;">🗺 Nav</a>';
        html += '</div>';
    });

    list.innerHTML = html || '<div style="color:#9ca3af;padding:20px;text-align:center;">No customers with location data.</div>';

    // Build Google Maps multi-stop route link
    var mapsBtn = document.getElementById('bdOpenMapsRoute');
    if (mapsWaypoints.length >= 2) {
        var dest = mapsWaypoints.pop();
        var wps  = mapsWaypoints.join('|');
        var url  = 'https://www.google.com/maps/dir/?api=1&destination='+dest+(wps?'&waypoints='+encodeURIComponent(wps):'');
        mapsBtn.href = url;
        mapsBtn.style.display = 'block';
    } else if (mapsWaypoints.length === 1) {
        mapsBtn.href = 'https://www.google.com/maps/dir/?api=1&destination='+mapsWaypoints[0];
        mapsBtn.style.display = 'block';
    } else {
        mapsBtn.style.display = 'none';
    }
};

// ── Today's jobs (leader overview) ───────────────────────────
window.bdLoadHistory = function() {
    var date = document.getElementById('bdHistDate').value;
    var list = document.getElementById('bdHistoryList');
    list.innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;">Loading…</div>';
    apiGet('scheduling_jobs_all', '&date='+date).then(function(d) {
        if (d.status !== 'success') { list.innerHTML = '<div style="color:#dc3545;padding:12px;">'+escHtml(d.message||'Failed')+'</div>'; return; }
        var jobs = d.data.jobs || [];
        if (!jobs.length) { list.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;">No jobs scheduled for '+date+'</div>'; return; }

        // Group by assignee
        var byAssignee = {};
        jobs.forEach(function(j) {
            var aName = (j.assignees && j.assignees[0]) ? (j.assignees[0].user.firstName||'') + ' ' + (j.assignees[0].user.lastName||'') : 'Unassigned';
            aName = aName.trim() || 'Unassigned';
            if (!byAssignee[aName]) byAssignee[aName] = [];
            byAssignee[aName].push(j);
        });

        var STATUS_COLOR = {open:'#1565C0',pending:'#E65100',closed:'#2E7D32'};
        var STATUS_LABEL = {open:'Open',pending:'In Progress',closed:'Done'};
        var html = '<div style="font-size:12px;color:#6b7280;margin-bottom:12px;">'+jobs.length+' job'+(jobs.length===1?'':'s')+' on '+date+'</div>';

        Object.keys(byAssignee).sort().forEach(function(agent) {
            var agentJobs = byAssignee[agent];
            var done = agentJobs.filter(function(j){ return j.status==='closed'; }).length;
            html += '<div style="margin-bottom:14px;">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
            html += '<div style="font-size:12px;font-weight:800;color:#4527A0;">👤 '+escHtml(agent)+'</div>';
            html += '<div style="font-size:11px;color:#9ca3af;">'+done+'/'+agentJobs.length+' done</div>';
            html += '</div>';
            agentJobs.forEach(function(j) {
                var st    = j.status || 'open';
                var color = STATUS_COLOR[st] || '#9ca3af';
                var label = STATUS_LABEL[st] || st;
                var cName = j.client ? ((j.client.firstName||'')+(j.client.lastName?' '+j.client.lastName:'')) : '—';
                html += '<div style="background:#fff;border-radius:10px;padding:10px 12px;margin-bottom:4px;border-left:3px solid '+color+';display:flex;justify-content:space-between;align-items:center;">';
                html += '<div><div style="font-size:13px;font-weight:700;color:#1e293b;">'+escHtml(j.title||'Job #'+j.id)+'</div>';
                html += '<div style="font-size:11px;color:#6b7280;">'+escHtml(cName)+' · Job #'+j.id+'</div></div>';
                html += '<span style="font-size:10px;font-weight:800;color:'+color+';background:'+color+'22;padding:3px 8px;border-radius:20px;">'+label+'</span>';
                html += '</div>';
            });
            html += '</div>';
        });
        list.innerHTML = html;
    }).catch(function() { list.innerHTML = '<div style="color:#dc3545;padding:12px;">Network error</div>'; });
};

// ── Batch History ─────────────────────────────────────────────
window.bdLoadBatches = function() {
    var el = document.getElementById('bdBatchesList');
    if (!el) return;
    el.innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">Loading…</div>';
    apiGet('fiber_batches').then(function(d) {
        if (d.status !== 'success' || !d.data.batches.length) {
            el.innerHTML = '<div style="text-align:center;padding:30px;color:#9ca3af;">No batches yet. Dispatch your first batch using the ⚡ Dispatch tab.</div>';
            return;
        }
        var html = '';
        d.data.batches.forEach(function(b) {
            var successCount = (b.jobs||[]).filter(function(j){ return j.status === 'created'; }).length;
            var failCount    = (b.jobs||[]).filter(function(j){ return j.status === 'failed';  }).length;
            // Per-engineer summary
            var engMap = {};
            (b.jobs||[]).forEach(function(j) {
                var eng = j.assignee_name || 'Unassigned';
                if (!engMap[eng]) engMap[eng] = {assigned:0, jobs:[]};
                engMap[eng].assigned++;
                engMap[eng].jobs.push(j);
            });
            html += '<div style="background:#fff;border:1px solid #EDE9FE;border-radius:14px;padding:14px 16px;margin-bottom:10px;">';
            // Header
            html += '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;">';
            html += '<div style="flex:1;">';
            html += '<div style="font-size:14px;font-weight:800;color:#1e293b;">'+escHtml(b.batch_name||b.job_title)+'</div>';
            html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;">';
            html += b.job_date + (b.fiber_partner ? ' · Partner: <strong>'+escHtml(b.fiber_partner)+'</strong>' : '');
            html += ' · Created by '+escHtml(b.created_by||'')+'</div>';
            html += '</div>';
            html += '<div style="display:flex;gap:6px;flex-shrink:0;margin-left:8px;">';
            html += '<span style="background:#E8F5E9;color:#2E7D32;font-size:11px;font-weight:800;padding:3px 10px;border-radius:20px;">✅ '+successCount+'</span>';
            if (failCount) html += '<span style="background:#FFEBEE;color:#C62828;font-size:11px;font-weight:800;padding:3px 10px;border-radius:20px;">❌ '+failCount+'</span>';
            html += '</div>';
            html += '</div>';
            // Per-engineer summary
            html += '<div style="font-size:10px;font-weight:700;color:#7C3AED;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">👷 Engineer Breakdown</div>';
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">';
            Object.keys(engMap).forEach(function(eng) {
                html += '<div style="background:#F5F3FF;border:1px solid #DDD6FE;border-radius:8px;padding:5px 10px;font-size:11px;">';
                html += '<span style="font-weight:700;color:#4527A0;">'+escHtml(eng)+'</span>';
                html += '<span style="color:#7C3AED;"> — '+engMap[eng].assigned+' job'+(engMap[eng].assigned===1?'':'s')+'</span>';
                html += '</div>';
            });
            html += '</div>';
            // Job list (collapsed, expand on tap)
            html += '<details><summary style="font-size:11px;font-weight:700;color:#6b7280;cursor:pointer;list-style:none;padding:4px 0;">▶ View '+b.total+' customer jobs</summary>';
            html += '<div style="margin-top:8px;">';
            (b.jobs||[]).forEach(function(j) {
                var ico = j.status === 'created' ? '✅' : '❌';
                html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f8f9fa;font-size:12px;">';
                html += '<span>'+ico+'</span>';
                html += '<div style="flex:1;"><div style="font-weight:600;">'+escHtml(j.name||'Client #'+j.crm_id)+'</div>';
                if (j.assignee_name) html += '<div style="font-size:10px;color:#7C3AED;">👷 '+escHtml(j.assignee_name)+(j.job_time?' · 🕐 '+j.job_time:'')+'</div>';
                if (j.job_id) html += '<div style="font-size:10px;color:#9ca3af;">UCRM Job #'+j.job_id+'</div>';
                html += '</div></div>';
            });
            html += '</div></details>';
            html += '</div>';
        });
        el.innerHTML = html;
    }).catch(function() {
        el.innerHTML = '<div style="color:#dc3545;padding:12px;">Failed to load batches</div>';
    });
};

// Initial render
bdRenderTasks();
bdUpdateSummary();
})();
</script>
<style>@keyframes spin{to{transform:rotate(360deg);}}</style>

