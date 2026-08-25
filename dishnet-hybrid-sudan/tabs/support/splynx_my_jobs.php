<?php
// Tab: splynx_my_jobs
// Extracted from public.php on 2026-03-15
        if (empty($retailer['api_token'])) {
            $newTok = bin2hex(random_bytes(32));
            $store->updateOne('retailers.json', 'id', (int)$retailer['id'], [
                'api_token' => $newTok, 'token_issued_at' => time(),
            ]);
            $retailer['api_token'] = $newTok;
        }
        $apiToken    = h($retailer['api_token'] ?? "");
        $myRole      = $retailer['role'] ?? 'support';
        $isLeader    = ($retailer['is_admin'] ?? false) || $myRole === 'support_leader';
        $myName      = h($retailer['name'] ?? 'Engineer');
        // Pre-load staff list for assignment picker
        $allRetailers = $store->load('retailers.json') ?? [];
        $supportStaff = array_values(array_filter($allRetailers, fn($r) =>
            in_array($r['role'] ?? '', ['support', 'support_leader', 'support_engineer']) &&
            !empty($r['name'])
        ));
    ?>

<style>
/* ── BIDAL FTTH COMMAND CENTER ────────────────────────────────────────── */
:root{--f-red:#D41C1C;--f-red-dk:#A81515;--f-dark:#141414;--f-ink:#1e293b;--f-muted:#64748b;--f-border:#e2e8f0;--f-bg:#f8fafc;}
.ftth-hero{background:linear-gradient(110deg,#D41C1C,#E8521A,#c0392b);border-radius:20px;padding:20px 22px;color:#fff;margin-bottom:16px;position:relative;overflow:hidden;}
.ftth-hero::before{content:'';position:absolute;right:-30px;top:-30px;width:180px;height:180px;background:rgba(255,255,255,.07);border-radius:50%;}
.ftth-tabs{display:flex;gap:4px;background:#f1f5f9;border-radius:12px;padding:4px;margin-bottom:16px;}
.ftth-tab{flex:1;padding:8px 4px;border-radius:9px;border:none;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#6b7280;transition:.15s;text-align:center;}
.ftth-tab.active{background:#fff;color:var(--f-red);box-shadow:0 1px 4px rgba(0,0,0,.1);}
.ftth-pane{display:none;}
.ftth-pane.active{display:block;}
.ftth-card{background:#fff;border-radius:16px;padding:14px 16px;margin-bottom:10px;box-shadow:0 2px 8px rgba(0,0,0,.05);border:1px solid var(--f-border);position:relative;overflow:hidden;}
.ftth-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;}
.ftth-card.status-pending::before{background:#f59e0b;}
.ftth-card.status-assigned::before{background:#3b82f6;}
.ftth-card.status-ready::before{background:#8b5cf6;}
.ftth-card.status-approved,.ftth-card.status-complete::before{background:#10b981;}
.ftth-card.status-rejected::before{background:#ef4444;}
.ftth-cname{font-size:16px;font-weight:800;color:var(--f-ink);line-height:1.2;}
.ftth-addr{font-size:12px;color:var(--f-muted);margin-top:3px;}
.ftth-meta{display:flex;flex-wrap:wrap;gap:5px;margin:8px 0;}
.ftth-chip{display:inline-flex;align-items:center;gap:4px;background:#f1f5f9;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:600;color:var(--f-muted);}
.ftth-chip.eng{background:#eff6ff;color:#1d4ed8;}
.ftth-chip.signal-ok{background:#f0fdf4;color:#166534;}
.ftth-chip.signal-warn{background:#fef3c7;color:#92400e;}
.ftth-chip.signal-bad{background:#fef2f2;color:#991b1b;}
.ftth-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;}
.ftth-badge.pending{background:#fef3c7;color:#92400e;}
.ftth-badge.assigned{background:#dbeafe;color:#1d4ed8;}
.ftth-badge.ready{background:#ede9fe;color:#5b21b6;}
.ftth-badge.approved{background:#dcfce7;color:#166534;}
.ftth-badge.rejected{background:#fee2e2;color:#991b1b;}
.ftth-badge.complete{background:#dcfce7;color:#166534;}
.ftth-actions{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;}
.ftth-btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border-radius:10px;border:none;font-size:12px;font-weight:700;cursor:pointer;transition:.15s;font-family:inherit;}
.ftth-btn:active{transform:scale(.97);}
.ftth-btn.primary{background:var(--f-red);color:#fff;}
.ftth-btn.green{background:#059669;color:#fff;}
.ftth-btn.purple{background:#7c3aed;color:#fff;}
.ftth-btn.ghost{background:#f1f5f9;color:#374151;border:1.5px solid var(--f-border);}
.ftth-btn.danger{background:#ef4444;color:#fff;}
.ftth-btn:disabled{opacity:.45;cursor:not-allowed;}
/* stats strip */
.ftth-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px;}
@media(min-width:600px){.ftth-stats{grid-template-columns:repeat(6,1fr);}}
.ftth-stat{background:#fff;border-radius:14px;padding:12px 10px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.05);border:1px solid var(--f-border);}
.ftth-stat-val{font-size:28px;font-weight:900;line-height:1;font-family:'Barlow Condensed',sans-serif;}
.ftth-stat-lbl{font-size:9px;color:var(--f-muted);margin-top:3px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
/* overlay */
#ftthOverlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:4000;overflow-y:auto;}
#ftthPanel{background:#fff;max-width:520px;margin:20px auto;border-radius:20px;overflow:hidden;}
@media(max-width:560px){
  #ftthPanel{margin:0;border-radius:24px 24px 0 0;position:fixed;bottom:0;left:0;right:0;max-height:88vh;overflow-y:auto;padding-bottom:env(safe-area-inset-bottom,16px);}
  #ftthOverlay{align-items:flex-end !important;}
}
.ftth-panel-head{background:linear-gradient(110deg,var(--f-red),#E8521A);color:#fff;padding:20px 20px 18px;display:flex;justify-content:space-between;align-items:center;}
.ftth-panel-body{padding:20px 20px;}
.ftth-field-label{font-size:11px;font-weight:700;color:var(--f-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px;margin-top:12px;}
.ftth-input{width:100%;border:1.5px solid var(--f-border);border-radius:10px;padding:10px 12px;font-size:14px;outline:none;transition:.15s;box-sizing:border-box;}
.ftth-input:focus{border-color:var(--f-red);}
.ftth-photo-row{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:6px;}
.ftth-photo-btn{background:#f8fafc;border:2px dashed var(--f-border);border-radius:10px;padding:10px 4px;text-align:center;font-size:11px;font-weight:600;color:var(--f-muted);cursor:pointer;transition:.15s;}
.ftth-photo-btn:hover{border-color:var(--f-red);color:var(--f-red);}
.ftth-photo-btn.done{border-style:solid;border-color:#10b981;background:#f0fdf4;color:#166534;}
/* mobile-friendly search */
.ftth-search{display:flex;gap:6px;margin-bottom:14px;}
.ftth-search input{flex:1;border:1.5px solid var(--f-border);border-radius:10px;padding:10px 12px;font-size:14px;outline:none;}
.ftth-search input:focus{border-color:var(--f-red);}
.ftth-empty{text-align:center;padding:40px 20px;color:var(--f-muted);}
.ftth-section-head{font-size:11px;font-weight:800;color:var(--f-muted);text-transform:uppercase;letter-spacing:.8px;margin:14px 0 8px;display:flex;align-items:center;gap:6px;}
.ftth-section-head::after{content:'';flex:1;height:1px;background:var(--f-border);}
/* Area map specific */
.leaflet-popup-content{font-family:'Barlow Condensed','Inter',sans-serif!important;font-size:13px;line-height:1.5;}
.leaflet-popup-content b{font-size:14px;color:var(--f-ink);}
</style>

<div class="ftth-hero">
    <div style="font-size:11px;opacity:.65;font-weight:700;text-transform:uppercase;letter-spacing:1px;">FTTH Installation Command Center</div>
    <div style="font-size:22px;font-weight:800;margin:4px 0;position:relative;z-index:1;">
        📡 <?= $myName ?> — <?= $isLeader ? 'Support Leader' : 'Field Support' ?>
    </div>
    <div style="font-size:12px;opacity:.8;margin-top:2px;">Assign engineers · Track installs · Commission completions</div>
    <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;position:relative;z-index:1;">
        <div id="ftthStatPending" style="background:rgba(255,255,255,.18);border-radius:10px;padding:6px 14px;font-size:13px;font-weight:700;">⏳ —</div>
        <div id="ftthStatTesting" style="background:rgba(255,255,255,.18);border-radius:10px;padding:6px 14px;font-size:13px;font-weight:700;">🔬 —</div>
        <button onclick="ftthLoadAll()" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:10px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">↻ Refresh</button>
        <?php if ($isLeader): ?>
        <button onclick="ftthRunSync()" id="ftthSyncBtn" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);border-radius:10px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">🔄 Sync Splynx</button>
        <button onclick="ftthDiagnose()" style="background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;">🔧 Diagnose</button>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Strip -->
<div class="ftth-stats" id="ftthStats">
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#f59e0b;" id="ssPending">—</div><div class="ftth-stat-lbl">New</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#4d96ff;" id="ssSurvey">—</div><div class="ftth-stat-lbl">Surveyed</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#3b82f6;" id="ssProgress">—</div><div class="ftth-stat-lbl">Deploying</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#8b5cf6;" id="ssOnu">—</div><div class="ftth-stat-lbl">ONU Ready</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#f97316;" id="ssWaiting">—</div><div class="ftth-stat-lbl">Waiting</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#10b981;" id="ssDone">—</div><div class="ftth-stat-lbl">Resolved</div></div>
</div>
<div class="ftth-stats" id="ftthStats2" style="margin-top:4px;">
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#dc2626;" id="ssTotalPend">—</div><div class="ftth-stat-lbl">Pending</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#94a3b8;" id="ssBlocked">—</div><div class="ftth-stat-lbl">Blocked</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#ef4444;" id="ssCancelled">—</div><div class="ftth-stat-lbl">Cancelled</div></div>
    <div class="ftth-stat"><div class="ftth-stat-val" style="color:#6b7280;" id="ssTotal">—</div><div class="ftth-stat-lbl">Total</div></div>
</div>

<!-- Tabs -->
<div class="ftth-tabs">
    <?php if ($isLeader): ?>
    <button class="ftth-tab active" id="ftthTabQueue"    onclick="ftthSwitch('queue')">📋 Queue</button>
    <button class="ftth-tab"        id="ftthTabTesting"  onclick="ftthSwitch('testing')">🔬 Testing <span id="testingBadge"></span></button>
    <button class="ftth-tab"        id="ftthTabDone"     onclick="ftthSwitch('done')">✅ Done</button>
    <button class="ftth-tab"        id="ftthTabAreas"    onclick="ftthSwitch('areas')">🗺 Areas</button>
    <?php else: ?>
    <button class="ftth-tab active" id="ftthTabMyJobs"   onclick="ftthSwitch('myjobs')">🔧 My Jobs</button>
    <button class="ftth-tab"        id="ftthTabDone"     onclick="ftthSwitch('done')">✅ Done</button>
    <?php endif; ?>
</div>

<?php if ($isLeader): ?>
<!-- Area Filter Bar (shown on Queue tab) -->
<div id="ftthAreaFilterBar" style="margin-bottom:12px;">
    <select id="ftthAreaSelect" onchange="ftthFilter()" style="width:100%;border:1.5px solid var(--f-border);border-radius:10px;padding:10px 12px;font-size:14px;outline:none;background:#fff;color:var(--f-ink);font-weight:600;">
        <option value="">📍 All Areas</option>
        <?php foreach (\SplynxTicketService::getJubaAreas() as $area): ?>
        <option value="<?= h($area) ?>"><?= h($area) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

<!-- Queue Pane (support_leader: all pending tickets) -->
<div class="ftth-pane active" id="ftthPaneQueue">
    <div class="ftth-search">
        <input type="search" id="ftthSearchQ" placeholder="🔍 Search customer, area, engineer…" oninput="ftthFilter()">
    </div>
    <div id="ftthQueueList"><div class="ftth-empty">⏳ Loading…</div></div>
</div>

<!-- Testing Queue Pane -->
<div class="ftth-pane" id="ftthPaneTesting">
    <div id="ftthTestingList"><div class="ftth-empty">Loading…</div></div>
</div>

<!-- My Jobs Pane (engineer) -->
<div class="ftth-pane" id="ftthPaneMyJobs">
    <div id="ftthMyJobsList"><div class="ftth-empty">Loading…</div></div>
</div>

<!-- Done Pane -->
<div class="ftth-pane" id="ftthPaneDone">
    <div id="ftthDoneList"><div class="ftth-empty">Loading…</div></div>
</div>

<!-- Area Map Pane -->
<?php if ($isLeader): ?>
<div class="ftth-pane" id="ftthPaneAreas">
    <div id="ftthAreaMap" style="height:400px;border-radius:16px;overflow:hidden;margin-bottom:16px;border:1px solid var(--f-border);background:#e8e8e8;">
        <div class="ftth-empty" id="ftthMapPlaceholder">🗺 Loading area map…</div>
    </div>
    <div id="ftthAreaGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;">
        <div class="ftth-empty">Loading area data…</div>
    </div>
</div>
<?php endif; ?>

<!-- ══ DETAIL / ACTION OVERLAY ══════════════════════════════════════════════ -->
<div id="ftthOverlay" onclick="if(event.target===this)ftthClosePanel()">
  <div id="ftthPanel">
    <div class="ftth-panel-head">
        <div>
            <div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:1px;" id="panelLabel">Job Detail</div>
            <div style="font-size:17px;font-weight:800;" id="panelTitle">—</div>
        </div>
        <button onclick="ftthClosePanel()" style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:10px;padding:6px 12px;font-size:13px;font-weight:700;cursor:pointer;">✕</button>
    </div>
    <div class="ftth-panel-body" id="panelBody">Loading…</div>
  </div>
</div>

<script>
(function() {
var TOKEN   = '<?= $apiToken ?>';
var IS_LEADER = <?= $isLeader ? 'true' : 'false' ?>;
var MY_NAME   = '<?= addslashes($retailer['name'] ?? 'Engineer') ?>';
var MY_ID     = '<?= (string)($retailer["id"] ?? "") ?>';
var MY_LOWER  = MY_NAME.toLowerCase().trim();
var headers   = { 'Authorization': 'Bearer ' + TOKEN, 'Content-Type': 'application/json' };

var queueData   = [];
var testingData = [];
var doneData    = [];
var myJobsData  = [];
var activePane  = IS_LEADER ? 'queue' : 'myjobs';

// ── Staff for assignment ──────────────────────────────────────────────────
var staffList = <?= json_encode(array_map(fn($r) => ['id' => $r['id'] ?? '', 'name' => $r['name']], $supportStaff)) ?>;

// ── API helpers ───────────────────────────────────────────────────────────
function api(action, qs) {
    return fetch('?page=api&action=' + action + (qs || ''), {credentials:'same-origin', headers: headers })
        .then(function(r) {
            if (!r.ok) return r.text().then(function(t) { throw new Error('HTTP '+r.status+': '+t.substring(0,80)); });
            return r.json();
        });
}
function apiPost(action, body) {
    return fetch('?page=api&action=' + action, {
          credentials:'same-origin',
          method: 'POST', headers: headers, body: JSON.stringify(body) }).then(function(r) { return r.json(); });
}

// ── Helpers ───────────────────────────────────────────────────────────────
function badge(status) {
    var labels = { pending:'⏳ Pending', assigned:'🔧 Assigned', ready:'🔬 Ready', approved:'✅ Approved', rejected:'❌ Rejected', complete:'✅ Done' };
    return '<span class="ftth-badge '+(status||'pending')+'">'+(labels[status]||status)+'</span>';
}
function chipSignal(db) {
    if (db === null || db === undefined || db === '') return '';
    var cls = db >= -20 ? 'signal-ok' : (db >= -27 ? 'signal-warn' : 'signal-bad');
    return '<span class="ftth-chip '+cls+'">📶 '+db+' dBm</span>';
}
function ago(ts) {
    if (!ts) return '';
    var diff = Math.floor((Date.now() - new Date(ts)) / 60000);
    if (diff < 2)   return 'Just now';
    if (diff < 60)  return diff + 'm ago';
    if (diff < 1440) return Math.floor(diff/60) + 'h ago';
    return Math.floor(diff/1440) + 'd ago';
}
function esc(s) { var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

// ── Render a ticket card ──────────────────────────────────────────────────
function renderCard(t, context) {
    var status = t.testing_status || 'pending';
    if (t.install_complete) status = 'complete';
    else if (t.assigned_engineer_name && status === 'pending') status = 'assigned';

    var engChip = (t.assigned_engineer_name || t.engineer)
        ? '<span class="ftth-chip eng">👤 '+ esc(t.assigned_engineer_name || t.engineer)+'</span>' : '';
    var areaChip = t.area ? '<span style="display:inline-flex;align-items:center;gap:4px;background:#D41C1C;color:#fff;font-size:11px;font-weight:800;padding:3px 10px;border-radius:20px;letter-spacing:.2px;">📍 '+esc(t.area)+'</span>' : '';
    var onuChip = t.onu_serial   ? '<span class="ftth-chip">📦 ONU: '+esc(t.onu_serial)+'</span>' : '';
    var portChip= t.olt_port     ? '<span class="ftth-chip">🔌 OLT: '+esc(t.olt_port)+'</span>' : '';
    var sigChip = chipSignal(t.signal_db);
    var photoCount = (t.photos || []).length;
    var photoChip = photoCount > 0 ? '<span class="ftth-chip">📷 '+photoCount+' photo'+(photoCount>1?'s':'')+'</span>' : '';
    var createdAgo = ago(t.created_at);

    // Fix #12: age-based urgency indicator
    var createdDays = t.created_at ? Math.floor((Date.now() - new Date(t.created_at).getTime()) / 86400000) : 0;
    var urgencyChip = '';
    if (!t.install_complete && createdDays >= 5) {
        urgencyChip = '<span class="ftth-chip" style="background:#fee2e2;color:#991b1b;">⏰ '+createdDays+'d old</span>';
    } else if (!t.install_complete && createdDays >= 3) {
        urgencyChip = '<span class="ftth-chip" style="background:#fef3c7;color:#92400e;">⏰ '+createdDays+'d old</span>';
    }

    var actionBtns = '';
    if (context === 'queue' && IS_LEADER) {
        if (!t.install_complete) {
            // Fix #14: always show assign/reassign
            var assignLabel = (t.assigned_engineer_name || t.engineer) ? '🔄 Reassign' : '👤 Assign';
            actionBtns += '<button class="ftth-btn primary" onclick="ftthOpenAssign('+t.id+')">'+assignLabel+'</button>';
            if (status === 'ready')
                actionBtns += '<button class="ftth-btn green" onclick="ftthOpenCommission('+t.id+')">✅ Commission</button>';
        }
        // Fix #7: phone call button
        if (t.phone) actionBtns += '<a href="tel:'+esc(t.phone)+'" class="ftth-btn" style="background:#059669;color:#fff;text-decoration:none;">📞 Call</a>';
        actionBtns += '<button class="ftth-btn ghost" onclick="ftthOpenDetail('+t.id+')">View</button>';
    } else if (context === 'myjobs') {
        if (!t.install_complete) {
            actionBtns += '<button class="ftth-btn primary" onclick="ftthOpenSubmit('+t.id+')">📋 Submit Data</button>';
            if (status !== 'ready')
                actionBtns += '<button class="ftth-btn purple" onclick="ftthMarkReady('+t.id+')">🔬 Mark Ready</button>';
        }
        if (t.phone) actionBtns += '<a href="tel:'+esc(t.phone)+'" class="ftth-btn" style="background:#059669;color:#fff;text-decoration:none;">📞 Call</a>';
        actionBtns += '<button class="ftth-btn ghost" onclick="ftthOpenDetail('+t.id+')">View</button>';
    } else if (context === 'testing') {
        actionBtns += '<button class="ftth-btn green" onclick="ftthOpenCommission('+t.id+')">✅ Approve</button>';
        actionBtns += '<button class="ftth-btn danger" onclick="ftthOpenReject('+t.id+')">✕ Reject</button>';
        if (t.phone) actionBtns += '<a href="tel:'+esc(t.phone)+'" class="ftth-btn" style="background:#059669;color:#fff;text-decoration:none;">📞 Call</a>';
        actionBtns += '<button class="ftth-btn ghost" onclick="ftthOpenDetail('+t.id+')">View</button>';
    }

    return '<div class="ftth-card status-'+status+'">'
        + '<div style="display:flex;justify-content:space-between;align-items:flex-start;">'
        +   '<div><div class="ftth-cname">'+esc(t.customer_name||'#'+t.id)+'</div>'
        +   '<div class="ftth-addr">📍 '+esc(t.address||'No address')+'</div></div>'
        +   badge(status)
        + '</div>'
        + '<div class="ftth-meta">'+engChip+areaChip+onuChip+portChip+sigChip+photoChip+urgencyChip+'</div>'
        + (createdAgo ? '<div style="font-size:10px;color:#9ca3af;margin-bottom:6px;">Ticket #'+t.id+' · '+createdAgo+'</div>' : '')
        + (actionBtns ? '<div class="ftth-actions">'+actionBtns+'</div>' : '')
        + '</div>';
}

// ── Load data ─────────────────────────────────────────────────────────────
window.ftthLoadAll = function() {
    // Stats
    api('install_stats').then(function(d) {
        if (!d || !d.data) return;
        var s = d.data;
        document.getElementById('ssPending').textContent  = s.new         || 0;
        var surveyEl = document.getElementById('ssSurvey');
        if (surveyEl) surveyEl.textContent = s.survey_done || 0;
        document.getElementById('ssProgress').textContent = s.deploying    || 0;
        var onuEl = document.getElementById('ssOnu');
        if (onuEl) onuEl.textContent = s.ready_onu || 0;
        var waitEl = document.getElementById('ssWaiting');
        if (waitEl) waitEl.textContent = s.waiting || 0;
        document.getElementById('ssDone').textContent     = s.completed    || 0;
        var totalEl = document.getElementById('ssTotal');
        if (totalEl) totalEl.textContent = s.total || 0;
        var tpEl = document.getElementById('ssTotalPend');
        if (tpEl) tpEl.textContent = s.total_pending || 0;
        var blEl = document.getElementById('ssBlocked');
        if (blEl) blEl.textContent = s.total_blocked || 0;
        var caEl = document.getElementById('ssCancelled');
        if (caEl) caEl.textContent = s.cancelled || 0;
        document.getElementById('ftthStatPending').textContent = '⏳ '+(s.total_pending||0)+' pending';
        document.getElementById('ftthStatTesting').textContent = '🔬 '+(s.testing_queue||0)+' ready';
        var tb = document.getElementById('testingBadge');
        if (tb) tb.textContent = s.testing_queue > 0 ? ' ('+s.testing_queue+')' : '';
    }).catch(function(){});

    if (activePane === 'queue')   loadQueue();
    if (activePane === 'testing') loadTesting();
    if (activePane === 'done')    loadDone();
    if (activePane === 'myjobs')  loadMyJobs();
};

function loadQueue() {
    var el = document.getElementById('ftthQueueList');
    el.innerHTML = '<div class="ftth-empty">Loading…</div>';
    api('install_queue&filter=all').then(function(d) {
        if (!d || !d.data) { el.innerHTML = '<div class="ftth-empty">⚠ Failed to load</div>'; return; }
        queueData = (d.data.tickets || []).filter(function(t) { return !t.install_complete; });
        renderQueueList();
    });
}
function loadTesting() {
    var el = document.getElementById('ftthTestingList');
    el.innerHTML = '<div class="ftth-empty">Loading…</div>';
    api('install_testing_queue').then(function(d) {
        if (!d || !d.data) { el.innerHTML = '<div class="ftth-empty">⚠ Failed</div>'; return; }
        testingData = d.data.queue || [];
        if (!testingData.length) {
            el.innerHTML = '<div class="ftth-empty"><div style="font-size:32px;margin-bottom:8px;">🎉</div>No installs waiting for your approval</div>';
            return;
        }
        el.innerHTML = testingData.map(function(t) { return renderCard(t, 'testing'); }).join('');
    });
}
function loadDone() {
    var el = document.getElementById('ftthDoneList');
    el.innerHTML = '<div class="ftth-empty">Loading…</div>';
    api('install_queue&filter=completed').then(function(d) {
        if (!d || !d.data) { el.innerHTML = '<div class="ftth-empty">⚠ Failed</div>'; return; }
        doneData = d.data.tickets || [];
        if (!doneData.length) { el.innerHTML = '<div class="ftth-empty">No completed installs yet</div>'; return; }
        el.innerHTML = doneData.map(function(t) { return renderCard(t, 'done'); })  .join('');
    });
}
function loadMyJobs() {
    var el = document.getElementById('ftthMyJobsList');
    if (!el) return;
    el.innerHTML = '<div class="ftth-empty">Loading…</div>';
    api('install_queue&filter=all').then(function(d) {
        if (!d || !d.data) { el.innerHTML = '<div class="ftth-empty">⚠ Failed</div>'; return; }
        var allMine = (d.data.tickets||[]).filter(function(t) {
            var nameMatch = (t.assigned_engineer_name||'').toLowerCase().trim() === MY_LOWER
                         || (t.engineer||'').toLowerCase().trim() === MY_LOWER;
            var idMatch   = MY_ID && (String(t.assigned_engineer_id||'') === MY_ID);
            return nameMatch || idMatch;
        });
        // Active jobs (not complete, not rejected)
        myJobsData = allMine.filter(function(t) {
            return !t.install_complete && (t.testing_status || 'pending') !== 'rejected';
        });
        // Rejected jobs needing re-work
        var rejectedJobs = allMine.filter(function(t) {
            return !t.install_complete && t.testing_status === 'rejected';
        });
        var html = '';
        if (rejectedJobs.length) {
            html += '<div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:12px;padding:10px 14px;margin-bottom:12px;"><div style="font-weight:800;font-size:12px;color:#991b1b;margin-bottom:8px;">❌ Rejected — Action Required ('+rejectedJobs.length+')</div>';
            html += rejectedJobs.map(function(t) {
                var reason = t.rejection_notes || t.rejection_reason || t.reject_reason || '';
                return '<div class="ftth-card status-rejected" style="margin-bottom:8px;">'
                    + '<div class="ftth-cname">'+esc(t.customer_name||'#'+t.id)+'</div>'
                    + '<div class="ftth-addr">📍 '+esc(t.address||'No address')+'</div>'
                    + (reason ? '<div style="background:#fee2e2;border-radius:8px;padding:8px 10px;margin:8px 0;font-size:12px;color:#991b1b;"><strong>Rejection reason:</strong> '+esc(reason)+'</div>' : '')
                    + '<div class="ftth-actions">'
                    + '<button class="ftth-btn primary" onclick="ftthOpenSubmit('+t.id+')">♻️ Re-submit Data</button>'
                    + '<button class="ftth-btn ghost" onclick="ftthOpenDetail('+t.id+')">View</button>'
                    + '</div></div>';
            }).join('');
            html += '</div>';
        }
        if (!myJobsData.length && !rejectedJobs.length) {
            el.innerHTML = '<div class="ftth-empty"><div style="font-size:32px;">🛠</div>No jobs assigned to you yet.<br><small>Ask your support leader to assign you.</small></div>';
            return;
        }
        if (myJobsData.length) html += myJobsData.map(function(t) { return renderCard(t, 'myjobs'); }).join('');
        el.innerHTML = html;
    });
}

function renderQueueList() {
    var el = document.getElementById('ftthQueueList');
    var q = document.getElementById('ftthSearchQ').value.toLowerCase();
    var areaSelect = document.getElementById('ftthAreaSelect');
    var areaVal = areaSelect ? areaSelect.value.toLowerCase() : '';
    var filtered = queueData.filter(function(t) {
        var matchSearch = !q || (t.customer_name||'').toLowerCase().includes(q) ||
              (t.address||'').toLowerCase().includes(q) ||
              (t.assigned_engineer_name||'').toLowerCase().includes(q);
        var matchArea = !areaVal || (t.area||'').toLowerCase() === areaVal || (t.address||'').toLowerCase().includes(areaVal);
        return matchSearch && matchArea;
    });
    if (!filtered.length) {
        var hint = q ? '🔍 No results for "'+esc(q)+'"' : (areaVal ? '📍 No jobs in '+esc(areaSelect.value) : '<div style="font-size:32px;">📭</div>No pending installations');
        el.innerHTML = '<div class="ftth-empty">'+hint+'</div>';
        return;
    }
    // Group: unassigned first, then by engineer
    var unassigned = filtered.filter(function(t) { return !t.assigned_engineer_name && !t.engineer; });
    var assigned   = filtered.filter(function(t) { return  t.assigned_engineer_name ||  t.engineer; });
    var html = '';
    if (unassigned.length) {
        html += '<div class="ftth-section-head">🆕 Unassigned ('+unassigned.length+')</div>';
        html += unassigned.map(function(t) { return renderCard(t, 'queue'); }).join('');
    }
    if (assigned.length) {
        html += '<div class="ftth-section-head">🔧 Assigned ('+assigned.length+')</div>';
        html += assigned.map(function(t) { return renderCard(t, 'queue'); }).join('');
    }
    el.innerHTML = html;
}

window.ftthFilter = function() { renderQueueList(); };

// ── Area Map ──────────────────────────────────────────────────────────────
var _areaMapLoaded = false;
var _areaData      = [];
// Approximate GPS coordinates for Juba City areas (centroid-level)
var JUBA_AREA_COORDS = {
    'Juba Town':        [4.8500, 31.6100],
    'Hai Jerusalem':    [4.8515, 31.6060],
    'Hai Mayo':         [4.8310, 31.6200],
    'Hai Gonyo':        [4.8380, 31.6250],
    'Hai Tarawa':       [4.8540, 31.5950],
    'Hai Darussalam':   [4.8570, 31.5900],
    'Hai Referendum':   [4.8550, 31.5980],
    'Hai Mauna':        [4.8430, 31.6020],
    'St Kizito':        [4.8390, 31.6080],
    'Munuki Libya':     [4.8450, 31.5980],
    'Munuki Melissa':   [4.8440, 31.5950],
    'New Site':         [4.8350, 31.6050],
    'Mangaten':         [4.8600, 31.6200],
    'Thongping':        [4.8580, 31.5870],
    'Kololo':           [4.8630, 31.5840],
    'Hai Amarat':       [4.8520, 31.6130],
    'Hai Jalaba':       [4.8460, 31.6150],
    'Hai Cinema':       [4.8490, 31.6110],
    'Hai Seminary':     [4.8530, 31.6000],
    'Hai Malakal':      [4.8510, 31.6040],
    'Buluk':            [4.8505, 31.6060],
    'Hai Thoura':       [4.8545, 31.5940],
    'Custom':           [4.8560, 31.6160],
    'Nyakuron West':    [4.8480, 31.5880],
    'Nyakuron East':    [4.8480, 31.5930],
    'Rock City':        [4.8370, 31.5910],
    'Gudele 1':         [4.8350, 31.5790],
    'Gudele 2':         [4.8310, 31.5750],
    'Jebel Yesua':      [4.8280, 31.6000],
    'Jebel':            [4.8270, 31.5990],
    'Gurei':            [4.8300, 31.5880],
    'Konyokonyo':       [4.8485, 31.6080],
    'Hai Kuwait':       [4.8420, 31.6110],
    'Mia Saba':         [4.8400, 31.6130],
    'Lologo':           [4.8470, 31.6150],
    'Kor William':      [4.8440, 31.6180],
    'Kator':            [4.8460, 31.6200],
    'Atlabara':         [4.8380, 31.6170],
    'Melikia':          [4.8360, 31.6150],
    'Hai Neem':         [4.8340, 31.6090],
    'Gumbo Market':     [4.8200, 31.6100],
    'Gumbo Shirkat':    [4.8210, 31.6130],
    'Hai Jaborona':     [4.8230, 31.6050],
    'Hai Nimra Talata': [4.8250, 31.6010],
    'Hai Gabat':        [4.8290, 31.6050],
    'Jondoru':          [4.8150, 31.6080],
    'Kasire':           [4.8170, 31.5970],
    'Gbongoroki':       [4.8320, 31.5830],
    'Hai Game':         [4.8410, 31.5860],
    'Joppa':            [4.8260, 31.5920]
};

function loadAreaMap() {
    if (_areaMapLoaded && _areaData.length) { renderAreaGrid(_areaData); return; }
    // Load stats (includes area breakdown)
    api('install_stats').then(function(d) {
        if (!d || !d.data) return;
        _areaData = d.data.areas || [];
        _areaMapLoaded = true;
        renderAreaGrid(_areaData);
        initLeafletMap(_areaData);
    }).catch(function() {
        var g = document.getElementById('ftthAreaGrid');
        if (g) g.innerHTML = '<div class="ftth-empty">Failed to load area data</div>';
    });
}

function renderAreaGrid(areas) {
    var el = document.getElementById('ftthAreaGrid');
    if (!el) return;
    if (!areas || !areas.length) {
        el.innerHTML = '<div class="ftth-empty">No area data available yet. Run Sync to populate.</div>';
        return;
    }
    var html = '';
    areas.forEach(function(a) {
        var total = (a.active||0) + (a.installing||0) + (a.completed||0);
        var color = a.installing > 0 ? '#f59e0b' : (a.active > 0 ? '#10b981' : '#e2e8f0');
        html += '<div style="background:#fff;border-radius:12px;padding:12px;border:2px solid '+color+';cursor:pointer;transition:.15s;" '
             + 'onclick="ftthAreaClick(\''+esc(a.area)+'\')" '
             + 'onmouseover="this.style.transform=\'scale(1.03)\'" onmouseout="this.style.transform=\'scale(1)\'">'
             + '<div style="font-weight:800;font-size:13px;color:var(--f-ink);margin-bottom:6px;">📍 '+esc(a.area)+'</div>'
             + '<div style="display:flex;gap:4px;flex-wrap:wrap;">'
             + (a.active > 0 ? '<span style="background:#dcfce7;color:#166534;border-radius:6px;padding:2px 6px;font-size:10px;font-weight:700;">'+a.active+' active</span>' : '')
             + (a.installing > 0 ? '<span style="background:#fef3c7;color:#92400e;border-radius:6px;padding:2px 6px;font-size:10px;font-weight:700;">'+a.installing+' installing</span>' : '')
             + (a.completed > 0 ? '<span style="background:#dbeafe;color:#1d4ed8;border-radius:6px;padding:2px 6px;font-size:10px;font-weight:700;">'+a.completed+' done</span>' : '')
             + (total === 0 ? '<span style="background:#f1f5f9;color:#9ca3af;border-radius:6px;padding:2px 6px;font-size:10px;font-weight:700;">No activity</span>' : '')
             + '</div></div>';
    });
    el.innerHTML = html;
}

window.ftthAreaClick = function(areaName) {
    // Fix #8: set area filter and switch to Queue tab so filter is applied immediately
    var areaSelect = document.getElementById('ftthAreaSelect');
    if (areaSelect) areaSelect.value = areaName;
    ftthSwitch('queue');
    // ftthSwitch calls loadQueue which calls renderQueueList which reads ftthAreaSelect
};

function initLeafletMap(areas) {
    var container = document.getElementById('ftthAreaMap');
    if (!container) return;
    // Load Leaflet CSS + JS dynamically
    if (!document.getElementById('leaflet-css')) {
        var lnk = document.createElement('link');
        lnk.id = 'leaflet-css'; lnk.rel = 'stylesheet';
        lnk.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(lnk);
    }
    function _buildMap() {
        container.innerHTML = '';
        var map = L.map(container).setView([4.845, 31.600], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap', maxZoom: 18
        }).addTo(map);

        (areas || []).forEach(function(a) {
            var c = JUBA_AREA_COORDS[a.area];
            if (!c) return;
            var total = (a.active||0) + (a.installing||0);
            var color = a.installing > 0 ? '#f59e0b' : (a.active > 0 ? '#10b981' : '#94a3b8');
            var radius = Math.max(180, Math.min(600, total * 60));
            var circle = L.circle(c, {
                radius: radius, color: color, fillColor: color, fillOpacity: 0.35, weight: 2
            }).addTo(map);
            circle.bindPopup(
                '<b>' + a.area + '</b><br>' +
                '🟢 Active: ' + (a.active||0) + '<br>' +
                '🟡 Installing: ' + (a.installing||0) + '<br>' +
                '✅ Completed: ' + (a.completed||0)
            );
            L.marker(c, {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="background:'+color+';color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);">'+total+'</div>',
                    iconSize: [24, 24], iconAnchor: [12, 12]
                })
            }).addTo(map).bindPopup('<b>'+a.area+'</b><br>Active: '+(a.active||0)+' | Installing: '+(a.installing||0));
        });

        setTimeout(function(){ map.invalidateSize(); }, 300);
    }
    if (window.L) { _buildMap(); return; }
    var scr = document.createElement('script');
    scr.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    scr.onload = _buildMap;
    document.head.appendChild(scr);
}

// ── Tab switching ─────────────────────────────────────────────────────────
window.ftthSwitch = function(pane) {
    activePane = pane;
    document.querySelectorAll('.ftth-tab').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.ftth-pane').forEach(function(p) { p.classList.remove('active'); });
    var tab = document.getElementById('ftthTab' + pane.charAt(0).toUpperCase() + pane.slice(1));
    var pan = document.getElementById('ftthPane' + pane.charAt(0).toUpperCase() + pane.slice(1));
    if (tab) tab.classList.add('active');
    if (pan) pan.classList.add('active');
    // Show/hide area filter bar only on Queue and Done tabs
    var afb = document.getElementById('ftthAreaFilterBar');
    if (afb) afb.style.display = (pane === 'queue' || pane === 'done') ? 'block' : 'none';
    if (pane === 'queue')   loadQueue();
    if (pane === 'testing') loadTesting();
    if (pane === 'done')    loadDone();
    if (pane === 'myjobs')  loadMyJobs();
    if (pane === 'areas')   loadAreaMap();
};

// ── Panel helpers ─────────────────────────────────────────────────────────
function openPanel(label, title, bodyHtml) {
    document.getElementById('panelLabel').textContent = label;
    document.getElementById('panelTitle').textContent = title;
    document.getElementById('panelBody').innerHTML    = bodyHtml;
    document.getElementById('ftthOverlay').style.display = 'block';
    document.body.style.overflow = 'hidden';
}
window.ftthClosePanel = function() {
    document.getElementById('ftthOverlay').style.display = 'none';
    document.body.style.overflow = '';
};

// ── Assign Engineer ───────────────────────────────────────────────────────
window.ftthOpenAssign = function(ticketId) {
    var body = '<div style="font-size:13px;color:#6b7280;margin-bottom:16px;">Tap an engineer to assign Ticket #' + ticketId + '</div>';

    if (!staffList || !staffList.length) {
        body += '<div style="color:#dc2626;font-size:13px;">No engineers available. Check staff list.</div>';
    } else {
        body += '<div id="assignEngCards" style="display:flex;flex-direction:column;gap:10px;">';
        staffList.forEach(function(s) {
            var initials = (s.name || 'E').split(' ').map(function(w){ return w[0]; }).join('').substring(0,2).toUpperCase();
            var colors = ['#1976D2','#059669','#7c3aed','#d97706','#dc2626','#0891b2'];
            var color  = colors[(s.name||'').charCodeAt(0) % colors.length];
            body += '<button onclick="doAssign(' + ticketId + ',\'' + esc(s.name) + '\',\'' + esc(s.id||'') + '\')" '
                + 'style="display:flex;align-items:center;gap:14px;background:#f8fafc;border:2px solid #e2e8f0;border-radius:14px;padding:14px 16px;cursor:pointer;text-align:left;width:100%;transition:.15s;font-family:inherit;" '
                + 'onmousedown="this.style.background=\'#eff6ff\';this.style.borderColor=\'' + color + '\'" '
                + 'ontouchstart="this.style.background=\'#eff6ff\';this.style.borderColor=\'' + color + '\'">'
                + '<div style="width:44px;height:44px;border-radius:50%;background:' + color + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;flex-shrink:0;">' + initials + '</div>'
                + '<div style="flex:1;min-width:0;">'
                + '<div style="font-size:15px;font-weight:700;color:#111827;">' + esc(s.name) + '</div>'
                + '<div style="font-size:12px;color:#6b7280;text-transform:capitalize;">' + esc(s.role||'Engineer') + '</div>'
                + '</div>'
                + '<div style="color:' + color + ';font-size:20px;">→</div>'
                + '</button>';
        });
        body += '</div>';
    }
    body += '<div id="assignError" style="color:#dc2626;font-size:13px;margin-top:10px;display:none;"></div>';
    openPanel('Ticket #' + ticketId, 'Assign Engineer', body);
};

window.doAssign = function(ticketId, name, id) {
    // Mark all cards as loading
    var cards = document.querySelectorAll('#assignEngCards button');
    cards.forEach(function(c) { c.disabled = true; c.style.opacity = '.5'; });

    apiPost('install_assign', { ticket_id: ticketId, engineer_name: name, engineer_id: id })
        .then(function(d) {
            if (d.status !== 'success') {
                cards.forEach(function(c) { c.disabled = false; c.style.opacity = '1'; });
                var errEl = document.getElementById('assignError');
                if (errEl) { errEl.textContent = 'Error: ' + (d.message || 'Unknown error'); errEl.style.display = 'block'; }
                return;
            }
            ftthClosePanel();
            showToast('✅ Assigned to ' + name, 'success');
            ftthLoadAll();
        })
        .catch(function() {
            cards.forEach(function(c) { c.disabled = false; c.style.opacity = '1'; });
            showToast('Network error — please retry', 'error');
        });
};

// ── Submit Install Data ───────────────────────────────────────────────────
window.ftthOpenSubmit = function(ticketId) {
    var body = '<div style="font-size:12px;color:#6b7280;margin-bottom:14px;">Enter installation technical details. Fill what you have, submit when ready.</div>'
        + '<div class="ftth-field-label">ONU Serial Number</div>'
        + '<input class="ftth-input" id="subOnu" placeholder="e.g. HW-ABC123456789">'
        + '<div class="ftth-field-label">OLT Port</div>'
        + '<input class="ftth-input" id="subOlt" placeholder="e.g. 1/1/3">'
        + '<div class="ftth-field-label">Signal Strength (dBm)</div>'
        + '<input class="ftth-input" id="subSignal" type="number" step="0.1" placeholder="e.g. -18.5 (good: above -20)">'
        + '<div class="ftth-field-label">Notes</div>'
        + '<textarea class="ftth-input" id="subNotes" rows="2" placeholder="e.g. ONT mounted above door, customer briefed on usage"></textarea>'
        + '<div class="ftth-field-label">Photos</div>'
        + '<div class="ftth-photo-row">'
        + photoBtn(ticketId,'site_before','📷 Before')
        + photoBtn(ticketId,'equipment','🔌 Equipment')
        + photoBtn(ticketId,'fiber','🧵 Fiber')
        + photoBtn(ticketId,'onu','📦 ONU')
        + photoBtn(ticketId,'testing','📶 Signal')
        + photoBtn(ticketId,'after','🏠 After')
        + '</div>'
        + '<div style="margin-top:16px;display:flex;gap:8px;">'
        + '<button class="ftth-btn primary" style="flex:1;" id="subSaveBtn" onclick="doSubmitData('+ticketId+')">💾 Save Data</button>'
        + '<button class="ftth-btn ghost" onclick="ftthClosePanel()">Cancel</button></div>';
    openPanel('Ticket #'+ticketId, 'Submit Installation Data', body);
};

function photoBtn(ticketId, type, label) {
    return '<label class="ftth-photo-btn" id="pbtn_'+type+'">'
        + label
        + '<input type="file" accept="image/*" capture="environment" style="display:none;" onchange="uploadPhoto('+ticketId+',\''+type+'\',this)">'
        + '</label>';
}

window.uploadPhoto = function(ticketId, type, input) {
    var file = input.files[0];
    if (!file) return;
    // Fix #11: show uploading state immediately
    var btn = document.getElementById('pbtn_'+type);
    if (btn) {
        btn.classList.remove('done');
        btn.style.opacity = '0.6';
        btn.style.pointerEvents = 'none';
        var oldHtml = btn.innerHTML;
        btn.innerHTML = '<span style="font-size:10px;">⏳ Uploading…</span><input type="file" accept="image/*" capture="environment" style="display:none;" onchange="uploadPhoto('+ticketId+',' + JSON.stringify(type) + ',this)">';
    }
    var reader = new FileReader();
    reader.onload = function(e) {
        apiPost('install_upload_photo', { ticket_id: ticketId, photo_type: type, image_data: e.target.result })
            .then(function(d) {
                var b = document.getElementById('pbtn_'+type);
                if (b) {
                    b.style.opacity = '';
                    b.style.pointerEvents = '';
                    if (d.status === 'success') {
                        b.classList.add('done');
                        b.innerHTML = '<span style="font-size:10px;">✅ Done</span><input type="file" accept="image/*" capture="environment" style="display:none;" onchange="uploadPhoto('+ticketId+',' + JSON.stringify(type) + ',this)">';
                    } else {
                        b.innerHTML = '<span style="font-size:10px;color:#dc2626;">❌ Retry</span><input type="file" accept="image/*" capture="environment" style="display:none;" onchange="uploadPhoto('+ticketId+',' + JSON.stringify(type) + ',this)">';
                    }
                }
            })
            .catch(function() {
                var b = document.getElementById('pbtn_'+type);
                if (b) { b.style.opacity=''; b.style.pointerEvents=''; b.innerHTML='<span style="font-size:10px;color:#dc2626;">❌ Retry</span><input type="file" accept="image/*" capture="environment" style="display:none;" onchange="uploadPhoto('+ticketId+',' + JSON.stringify(type) + ',this)">'; }
            });
    };
    reader.readAsDataURL(file);
};

window.doSubmitData = function(ticketId) {
    var onu    = document.getElementById('subOnu').value.trim();
    var olt    = document.getElementById('subOlt').value.trim();
    var signal = document.getElementById('subSignal').value;
    var notes  = document.getElementById('subNotes').value.trim();
    if (!onu) { alert('ONU serial is required.'); return; }
    var btn = document.getElementById('subSaveBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Saving…'; }
    apiPost('install_submit_data', {
        ticket_id: ticketId, onu_serial: onu, olt_port: olt,
        signal_db: signal ? parseFloat(signal) : null,
        notes: notes, testing_status: 'pending'
    }).then(function(d) {
        if (d.status !== 'success') {
            if (btn) { btn.disabled = false; btn.textContent = '💾 Save Data'; }
            showToast('Error: ' + (d.message || 'Failed to save'), 'error');
            return;
        }
        // Fix #5: prompt to mark ready immediately
        showToast('✅ Data saved', 'success');
        var panelBody = document.getElementById('panelBody');
        if (panelBody) {
            panelBody.innerHTML = '<div style="text-align:center;padding:20px;">'
                + '<div style="font-size:36px;margin-bottom:12px;">✅</div>'
                + '<div style="font-size:16px;font-weight:800;margin-bottom:8px;">Data saved!</div>'
                + '<div style="font-size:13px;color:#6b7280;margin-bottom:20px;">Mark this job as ready for Bidal\'s review?</div>'
                + '<div style="display:flex;gap:8px;">'
                + '<button class="ftth-btn green" style="flex:1;" onclick="doMarkReadyNow('+ticketId+')">🔬 Yes, Mark Ready</button>'
                + '<button class="ftth-btn ghost" style="flex:1;" onclick="ftthClosePanel();ftthLoadAll();">Not yet</button>'
                + '</div></div>';
        }
    }).catch(function() {
        if (btn) { btn.disabled = false; btn.textContent = '💾 Save Data'; }
        showToast('Network error — please retry', 'error');
    });
};
window.doMarkReadyNow = function(ticketId) {
    apiPost('install_ready', { ticket_id: ticketId }).then(function(d) {
        ftthClosePanel();
        showToast('🔬 Job marked ready for review', 'success');
        ftthLoadAll();
    }).catch(function() { ftthClosePanel(); ftthLoadAll(); });
};

// ── Mark Ready for Bidal ──────────────────────────────────────────────────
window.ftthMarkReady = function(ticketId) {
    if (!confirm('Mark this installation as ready for commissioning review?')) return;
    apiPost('install_ready', { ticket_id: ticketId })
        .then(function(d) {
            if (d.status !== 'success') { showToast('Error: ' + (d.message || 'Failed'), 'error'); return; }
            showToast('🔬 Marked ready for review', 'success');
            ftthLoadAll();
        })
        .catch(function() { showToast('Network error — please retry', 'error'); });
};

// ── Commission ────────────────────────────────────────────────────────────
window.ftthOpenCommission = function(ticketId) {
    api('install_ticket&ticket_id='+ticketId).then(function(d) {
        _commTicket = (d && d.data && d.data.ticket) ? d.data.ticket : {};
        var t = _commTicket;
        var sigOk = (t.signal_db !== undefined && t.signal_db !== null) ? (t.signal_db >= -27 ? '✅' : '⚠') : '❓';
        var onuOk = t.onu_serial ? '✅' : '❌';
        var photoOk = (t.photos||[]).length >= 2 ? '✅' : '⚠';
        var onuMissing = !t.onu_serial;

        var body = '<div style="background:'+(onuMissing?'#fef2f2':'#f0fdf4')+';border:1px solid '+(onuMissing?'#fecaca':'#86efac')+';border-radius:10px;padding:12px;margin-bottom:14px;">'
            + '<div style="font-weight:700;font-size:13px;color:'+(onuMissing?'#991b1b':'#166534')+';margin-bottom:8px;">Commissioning Checklist</div>'
            + '<div>'+onuOk+' ONU Serial: '+(t.onu_serial||'<span style="color:#ef4444;font-weight:700;">MISSING — cannot commission</span>')+'</div>'
            + '<div>'+(t.olt_port?'✅':'⚠')+' OLT Port: '+(t.olt_port||'Not set')+'</div>'
            + '<div>'+sigOk+' Signal: '+(t.signal_db !== undefined && t.signal_db !== null ? t.signal_db+' dBm' : 'Not measured')+'</div>'
            + '<div>'+photoOk+' Photos: '+((t.photos||[]).length)+' uploaded</div>'
            + '</div>'
            + (t.crm_client_id ? '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px;margin-bottom:12px;font-size:12px;">📋 CRM Client: <strong>'+esc(t.customer_name||'—')+'</strong> · Account #'+esc(String(t.crm_client_id||'—'))+'</div>' : '')
            + '<div class="ftth-field-label">Commission Notes</div>'
            + '<textarea class="ftth-input" id="commNotes" rows="2" placeholder="e.g. Service activated, customer briefed"></textarea>'
            + '<div id="commError" style="color:#dc2626;font-size:12px;margin-top:8px;"></div>'
            + '<div style="margin-top:16px;display:flex;gap:8px;">'
            + '<button class="ftth-btn green" id="commBtn" style="flex:1;"'+(onuMissing?' disabled title="ONU serial required"':'')+' onclick="doCommission('+ticketId+')">✅ Approve &amp; Commission</button>'
            + '<button class="ftth-btn ghost" onclick="ftthClosePanel()">Cancel</button></div>';
        openPanel('Ticket #'+ticketId, 'Commission: '+esc(t.customer_name||'Customer'), body);
    });
};

var _commTicket = null;
window.doCommission = function(ticketId) {
    if (!_commTicket || !_commTicket.onu_serial) {
        var errEl = document.getElementById('commError');
        if (errEl) errEl.textContent = '❌ ONU serial is required. Ask the engineer to re-submit data.';
        return;
    }
    var notes = (document.getElementById('commNotes')||{}).value || '';
    var btn = document.getElementById('commBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Commissioning…'; }
    apiPost('install_commission', { ticket_id: ticketId, notes: notes })
        .then(function(d) {
            if (d.status !== 'success') {
                if (btn) { btn.disabled = false; btn.textContent = '✅ Approve & Commission'; }
                var errEl = document.getElementById('commError');
                if (errEl) errEl.textContent = 'Error: ' + (d.message || 'Failed');
                return;
            }
            ftthClosePanel();
            showToast('✅ Commissioned! Service activated.', 'success');
            var tb = document.getElementById('testingBadge');
            if (tb && tb.textContent) { var n=parseInt(tb.textContent.replace(/[^0-9]/g,''))||0; tb.textContent = n>1?' ('+(n-1)+')':''; }
            ftthLoadAll();
        })
        .catch(function() {
            if (btn) { btn.disabled = false; btn.textContent = '✅ Approve & Commission'; }
            showToast('Network error — please retry', 'error');
        });
};
// ── Reject ────────────────────────────────────────────────────────────────
window.ftthOpenReject = function(ticketId) {
    var body = '<div class="ftth-field-label">Reason for Rejection</div>'
        + '<textarea class="ftth-input" id="rejectReason" rows="3" placeholder="e.g. ONU serial missing, signal too weak — please re-measure"></textarea>'
        + '<div id="rejectError" style="color:#dc2626;font-size:12px;margin-top:8px;"></div>'
        + '<div style="margin-top:16px;display:flex;gap:8px;">'
        + '<button class="ftth-btn danger" id="rejectBtn" style="flex:1;" onclick="doReject('+ticketId+')">✕ Reject & Send Back</button>'
        + '<button class="ftth-btn ghost" onclick="ftthClosePanel()">Cancel</button></div>';
    openPanel('Ticket #'+ticketId, 'Reject Installation', body);
};

window.doReject = function(ticketId) {
    var reason = (document.getElementById('rejectReason')||{}).value.trim() || '';
    if (!reason) {
        var errEl = document.getElementById('rejectError');
        if (errEl) errEl.textContent = 'Please enter a reason so the engineer knows what to fix.';
        return;
    }
    var btn = document.getElementById('rejectBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Rejecting…'; }
    apiPost('install_reject', { ticket_id: ticketId, reason: reason })
        .then(function(d) {
            if (d.status !== 'success') {
                if (btn) { btn.disabled = false; btn.textContent = '✕ Reject & Send Back'; }
                var errEl = document.getElementById('rejectError');
                if (errEl) errEl.textContent = 'Error: ' + (d.message || 'Failed');
                return;
            }
            ftthClosePanel();
            showToast('↩️ Job sent back to engineer', 'warning');
            var tb = document.getElementById('testingBadge');
            if (tb && tb.textContent) { var n=parseInt(tb.textContent.replace(/[^0-9]/g,''))||0; tb.textContent = n>1?' ('+(n-1)+')':''; }
            ftthLoadAll();
        })
        .catch(function() {
            if (btn) { btn.disabled = false; btn.textContent = '✕ Reject & Send Back'; }
            showToast('Network error — please retry', 'error');
        });
};

// ── View Detail ───────────────────────────────────────────────────────────
window.ftthOpenDetail = function(ticketId) {
    openPanel('Ticket #'+ticketId, 'Loading…', '<div class="ftth-empty">⏳ Loading…</div>');
    api('install_ticket&ticket_id='+ticketId).then(function(d) {
        if (!d || !d.data || !d.data.ticket) {
            document.getElementById('panelBody').innerHTML = '<div class="ftth-empty">Ticket not found.</div>';
            return;
        }
        var t = d.data.ticket;
        document.getElementById('panelTitle').textContent = t.customer_name || 'Ticket #'+ticketId;
        var photos = (t.photos||[]).map(function(p) {
            return '<div style="background:#f1f5f9;border-radius:8px;padding:8px 10px;margin-bottom:4px;font-size:12px;">'
                + '📷 '+esc(p.type)+' — '+esc(p.at||'')+'</div>';
        }).join('');
        // Fix #13: show ops notes log + add note form
        var notesList = (t.ops_notes || []).map(function(n) {
            return '<div style="background:#f8fafc;border-radius:8px;padding:8px 10px;margin-bottom:4px;font-size:12px;">'
                + '<span style="font-weight:700;color:#374151;">'+esc(n.author||'')+'</span>'
                + '<span style="color:#9ca3af;font-size:10px;margin-left:6px;">'+esc(n.at||'')+'</span>'
                + '<div style="margin-top:2px;">'+esc(n.note||'')+'</div></div>';
        }).join('');
        var phone = t.phone || '';
        document.getElementById('panelBody').innerHTML =
            '<div class="ftth-chip" style="margin-bottom:8px;">Ticket #'+t.id+'</div>'
            + (phone ? '<a href="tel:'+esc(phone)+'" style="display:inline-flex;align-items:center;gap:6px;background:#059669;color:#fff;border-radius:10px;padding:7px 14px;font-size:12px;font-weight:700;text-decoration:none;margin-bottom:10px;">📞 '+esc(phone)+'</a>' : '')
            + '<div style="font-size:13px;margin-bottom:4px;">📍 '+esc(t.address||'—')+'</div>'
            + '<div style="font-size:12px;color:#6b7280;margin-bottom:12px;">Created: '+esc(t.created_at||'—')+'</div>'
            + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;">'
            + field('ONU Serial', t.onu_serial) + field('OLT Port', t.olt_port)
            + field('Signal', t.signal_db !== null && t.signal_db !== undefined ? t.signal_db+' dBm' : '—')
            + field('Engineer', t.assigned_engineer_name||t.engineer||'Unassigned')
            + '</div>'
            + (t.install_notes ? '<div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:12px;margin-bottom:12px;"><b>Install Notes:</b> '+esc(t.install_notes)+'</div>' : '')
            + (t.commission_notes ? '<div style="background:#f0fdf4;border-radius:8px;padding:10px;font-size:12px;margin-bottom:10px;"><b>Commission notes:</b> '+esc(t.commission_notes)+'</div>' : '')
            + (photos ? '<div style="font-size:11px;font-weight:700;color:#6b7280;margin-bottom:6px;">PHOTOS</div>'+photos : '')
            // Fix #13: ops notes
            + '<div style="font-size:11px;font-weight:700;color:#6b7280;margin:12px 0 6px;">📝 NOTES</div>'
            + (notesList || '<div style="font-size:12px;color:#9ca3af;margin-bottom:8px;">No notes yet.</div>')
            + '<div style="display:flex;gap:6px;margin-top:8px;">'
            + '<input id="detailNoteInput" class="ftth-input" style="flex:1;padding:8px 10px;font-size:13px;" placeholder="Add a note… (e.g. customer available Friday)">'
            + '<button class="ftth-btn primary" onclick="doAddNote('+ticketId+')">Add</button>'
            + '</div>'
            + '<div id="noteError" style="color:#dc2626;font-size:11px;margin-top:4px;"></div>';
    }).catch(function(err) {
        document.getElementById('panelBody').innerHTML = '<div class="ftth-empty" style="color:#dc2626;">⚠️ Error: '+err.message+'<br><small>Check login session or reload the page.</small></div>';
    });
};

window.doAddNote = function(ticketId) {
    var inp = document.getElementById('detailNoteInput');
    var note = inp ? inp.value.trim() : '';
    if (!note) { var e = document.getElementById('noteError'); if(e) e.textContent='Please enter a note.'; return; }
    apiPost('install_add_note', { ticket_id: ticketId, note: note })
        .then(function(d) {
            if (d.status !== 'success') { showToast('Failed to save note', 'error'); return; }
            showToast('📝 Note saved', 'success');
            ftthOpenDetail(ticketId); // re-open to show updated notes
        })
        .catch(function() { showToast('Network error', 'error'); });
};

// ── Splynx sync ───────────────────────────────────────────────────────────
window.ftthRunSync = function() {
    var btn = document.getElementById('ftthSyncBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Syncing…'; }
    fetch('?page=api&action=splynx_run_sync', {
          credentials:'same-origin',
          method: 'POST', headers: headers })
        .then(function(r) {
            if (!r.ok) {
                return r.text().then(function(t) {
                    throw new Error('HTTP ' + r.status + ': ' + t.substring(0, 200));
                });
            }
            return r.json();
        })
        .then(function(d) {
            if (btn) { btn.disabled = false; btn.textContent = '🔄 Sync Splynx'; }
            if (!d || !d.data) {
                showToast('⚠ Sync returned unexpected response', 'warning');
                console.error('Sync response:', d);
                return;
            }
            var t = d.data.tickets || {};
            var e = d.data.enrichment || {};
            var synced   = t.synced   || 0;
            var imported = t.imported || 0;
            var errors   = t.errors   || 0;
            var enriched = e.enriched || 0;
            var failed   = e.failed   || 0;

            if (errors > 0 || (synced === 0 && imported === 0)) {
                // Something went wrong - show detail
                var errMsg = t.error || t.last_error || 'No tickets returned from Splynx';
                showToast('⚠ Sync issues: ' + errMsg, 'warning');
                // Also open a detail panel
                openPanel('Sync Result', 'Splynx Sync Report',
                    '<div style="font-size:13px;line-height:1.8;">'
                    + '<div>📥 Tickets synced: <b>' + synced + '</b></div>'
                    + '<div>🆕 New imported: <b>' + imported + '</b></div>'
                    + '<div>❌ Errors: <b style="color:#dc2626;">' + errors + '</b></div>'
                    + '<div>🔗 CRM enriched: <b>' + enriched + '</b></div>'
                    + '<div>⚠ Enrich failed: <b>' + failed + '</b></div>'
                    + (t.error ? '<div style="margin-top:10px;background:#fef2f2;border-radius:8px;padding:10px;color:#991b1b;font-size:12px;"><b>Error:</b> ' + esc(t.error) + '</div>' : '')
                    + (t.api_error ? '<div style="margin-top:8px;background:#fef2f2;border-radius:8px;padding:10px;color:#991b1b;font-size:12px;"><b>API Error:</b> ' + esc(JSON.stringify(t.api_error)) + '</div>' : '')
                    + '<div style="margin-top:14px;"><a href="?page=dashboard&tab=settings" style="color:#D41C1C;font-weight:700;">⚙️ Check Splynx settings →</a></div>'
                    + '</div>'
                );
            } else {
                showToast('✅ Sync done: ' + imported + ' new, ' + synced + ' updated, ' + enriched + ' enriched', 'success');
            }
            ftthLoadAll();
        })
        .catch(function(err) {
            if (btn) { btn.disabled = false; btn.textContent = '🔄 Sync Splynx'; }
            showToast('❌ Sync failed: ' + err.message, 'error');
            console.error('Sync error:', err);
        });
};

// ── Diagnose Splynx connection ───────────────────────────────────────────
window.ftthDiagnose = function() {
    openPanel('Splynx Diagnostics', 'Running checks…', '<div class="ftth-empty">⏳ Testing Splynx connection…</div>');
    // Step 1: connection test
    fetch('?page=api&action=splynx_test', {credentials:'same-origin', headers: headers })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var t = d.data || {};
            var connOk = t.ok === true;
            var html = '<div style="font-size:13px;line-height:2;">'
                + '<div>' + (connOk ? '✅' : '❌') + ' Splynx connection: <b>' + (connOk ? 'OK' : (t.error || 'FAILED')) + '</b></div>';
            if (!connOk) {
                html += '<div style="margin:10px 0;background:#fef2f2;border-radius:8px;padding:10px;color:#991b1b;font-size:12px;">'
                    + 'Check Settings → Splynx URL / API Key / Secret</div>';
                html += '<a href="?page=dashboard&tab=settings" style="display:block;background:#D41C1C;color:#fff;border-radius:8px;padding:10px;text-align:center;font-weight:700;text-decoration:none;margin-top:10px;">⚙️ Open Settings</a>';
                html += '</div>';
                document.getElementById('panelBody').innerHTML = html;
                document.getElementById('panelTitle').textContent = 'Splynx Diagnostics';
                return;
            }
            // Step 2: probe ticket endpoints
            fetch('?page=api&action=splynx_debug_tickets', {credentials:'same-origin', headers: headers })
                .then(function(r) { return r.json(); })
                .then(function(d2) {
                    var probes = (d2.data || {}).probes || {};
                    html += '<div style="margin-top:10px;font-weight:800;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">API Endpoint Probes</div>';
                    Object.keys(probes).forEach(function(path) {
                        var p = probes[path];
                        var ok = (p.status || '').startsWith('OK');
                        html += '<div style="background:' + (ok?'#f0fdf4':'#fef2f2') + ';border-radius:8px;padding:8px 10px;margin-bottom:6px;font-size:12px;">'
                            + '<div style="font-weight:700;color:' + (ok?'#166534':'#991b1b') + ';">' + (ok?'✅':'❌') + ' ' + path + '</div>'
                            + '<div style="color:#64748b;">' + esc(p.status) + '</div>'
                            + (p.error ? '<div style="color:#dc2626;font-size:11px;margin-top:2px;">' + esc(p.error) + '</div>' : '')
                            + (p.sample && p.sample.length ? '<div style="color:#6b7280;font-size:10px;">Fields: ' + p.sample.join(', ') + '</div>' : '')
                            + '</div>';
                    });
                    // Step 3: local ticket count
                    fetch('?page=api&action=install_stats', {credentials:'same-origin', headers: headers })
                        .then(function(r) { return r.json(); })
                        .then(function(d3) {
                            var s = (d3.data || {});
                            html += '<div style="margin-top:10px;font-weight:800;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Local Store</div>';
                            html += '<div style="background:#f8fafc;border-radius:8px;padding:10px;font-size:12px;">'
                                + '📦 Total tickets in store: <b>' + (s.total || 0) + '</b><br>'
                                + '🆕 New: <b>' + (s.new || 0) + '</b> · '
                                + '🔧 Deploying: <b>' + (s.deploying || 0) + '</b> · '
                                + '✅ Completed: <b>' + (s.completed || 0) + '</b>'
                                + '</div>';
                            html += '</div>';
                            document.getElementById('panelBody').innerHTML = html;
                            document.getElementById('panelTitle').textContent = 'Splynx Diagnostics';
                        }).catch(function() {
                            html += '</div>';
                            document.getElementById('panelBody').innerHTML = html;
                        });
                }).catch(function() {
                    html += '<div style="color:#dc2626;">Failed to run endpoint probes.</div></div>';
                    document.getElementById('panelBody').innerHTML = html;
                });
        })
        .catch(function(err) {
            document.getElementById('panelBody').innerHTML = '<div class="ftth-empty" style="color:#dc2626;">❌ Request failed: ' + esc(err.message) + '</div>';
        });
};

// ── Init ──────────────────────────────────────────────────────────────────
ftthLoadAll();

})();
</script>

