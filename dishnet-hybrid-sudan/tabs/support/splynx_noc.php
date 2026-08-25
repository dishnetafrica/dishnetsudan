<?php
// Tab: splynx_noc
// Extracted from public.php on 2026-03-15
        $splynxConfigured = $splynx->isConfigured();
        $nocCache  = $store->load('splynx_dashboard_cache.json') ?: [];
        $nocAreas  = ($store->load('splynx_area_stats.json') ?: [])['areas'] ?? [];
        // Load staff/retailers for engineer dropdown
        $nocStaff = $store->load('retailers.json') ?? [];
        $nocEngineers = [];
        foreach ($nocStaff as $s) {
            if (!empty($s['is_active']) && in_array($s['role'] ?? '', ['support_leader','engineer','field_agent','admin','technician'], true)) {
                $nocEngineers[] = ['id' => $s['id'] ?? '', 'name' => $s['name'] ?? ''];
            }
        }
    ?>

<style>
.noc-hero{background:linear-gradient(135deg,#0D47A1,#1565C0);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;}
.noc-card{background:#fff;border-radius:16px;padding:16px;margin-bottom:14px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #f1f5f9;}
.noc-title{font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;}
.noc-stat{text-align:center;padding:12px 8px;}
.noc-stat-val{font-size:28px;font-weight:900;line-height:1;}
.noc-stat-lbl{font-size:10px;color:#6b7280;margin-top:4px;font-weight:600;text-transform:uppercase;}
.noc-badge{display:inline-block;border-radius:8px;padding:2px 8px;font-size:11px;font-weight:700;}
.noc-badge.open{background:#fef3c7;color:#92400e;}
.noc-badge.new{background:#ede9fe;color:#5b21b6;}
.noc-badge.survey{background:#dbeafe;color:#1d4ed8;}
.noc-badge.deploying{background:#fce7f3;color:#9d174d;}
.noc-badge.completed{background:#d1fae5;color:#065f46;}
.noc-badge.urgent{background:#fef2f2;color:#991b1b;}
.noc-ticket{background:#f8fafc;border-radius:14px;padding:12px;margin-bottom:8px;border:2px solid #e2e8f0;transition:.15s;}
.noc-ticket.complete{border-color:#bbf7d0;opacity:.6;}
.noc-ticket.urgent-border{border-color:#fca5a5;}
.noc-btn{background:linear-gradient(135deg,#1565C0,#0D47A1);color:#fff;border:none;border-radius:12px;padding:9px 16px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
.noc-btn:disabled{opacity:.5;cursor:not-allowed;}
.noc-btn.secondary{background:#f1f5f9;color:#374151;}
.noc-btn.danger{background:linear-gradient(135deg,#dc2626,#b91c1c);}
.noc-btn.success{background:linear-gradient(135deg,#059669,#047857);}
.noc-btn.sm{padding:5px 10px;font-size:11px;border-radius:8px;}
.noc-filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.noc-filter-btn{background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:10px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer;color:#374151;transition:.15s;}
.noc-filter-btn.active{background:#EFF6FF;border-color:#3b82f6;color:#1d4ed8;}
.noc-cfg-warn{background:#fef3c7;border:1.5px solid #fde68a;border-radius:14px;padding:14px;margin-bottom:14px;color:#92400e;font-size:13px;}
.noc-eng-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f8fafc;}
/* Area Grid */
.noc-area-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px;}
.noc-area-cell{background:#f8fafc;border:2px solid #e2e8f0;border-radius:14px;padding:12px;text-align:center;cursor:pointer;transition:.15s;}
.noc-area-cell:hover{border-color:#3b82f6;background:#eff6ff;transform:translateY(-1px);}
.noc-area-cell.sel{border-color:#1d4ed8;background:#dbeafe;}
.noc-area-cell.has-urgent{border-color:#fca5a5;background:#fef2f2;}
.noc-area-cell .area-name{font-size:13px;font-weight:700;color:#1e293b;margin-bottom:4px;}
.noc-area-cell .area-count{font-size:24px;font-weight:900;color:#1d4ed8;}
.noc-area-cell .area-breakdown{font-size:10px;color:#6b7280;margin-top:2px;}
.noc-area-cell.zero{opacity:.35;cursor:default;}
.noc-area-cell.zero:hover{border-color:#e2e8f0;background:#f8fafc;transform:none;}
/* Batch assign bar */
.noc-batch-bar{background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:14px;padding:12px;margin-bottom:14px;display:none;align-items:center;gap:10px;flex-wrap:wrap;}
.noc-batch-bar.show{display:flex;}
</style>

<div class="noc-hero">
    <div style="font-size:22px;font-weight:800;margin:4px 0;">🌐 Splynx NOC Dashboard</div>
    <div style="font-size:13px;opacity:.85;margin-top:4px;">Fiber Installation Tracking &amp; Area Dispatch</div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <button class="noc-btn" id="nocRefreshBtn" onclick="nocRefresh()">↺ Refresh</button>
        <button class="noc-btn secondary" onclick="nocRunEnrich()">🔗 Enrich from CRM</button>
        <?php if ($isAdmin): ?>
        <button class="noc-btn secondary" onclick="nocRunSync()">🔄 Run Sync Now</button>
        <a href="?page=dashboard&tab=settings&section=splynx" class="noc-btn secondary">⚙️ Settings</a>
        <?php endif; ?>
        <?php if ($userRole === 'support_leader'): ?>
        <a href="?page=dashboard&tab=support_leader_manual" class="noc-btn secondary" style="background:rgba(124,58,237,.25);border-color:rgba(124,58,237,.5);">📖 My Manual</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$splynxConfigured): ?>
<div id="splynxWarn" style="background:#fffbeb;border:1.5px solid #fcd34d;border-radius:12px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;">
    <span style="font-size:18px;flex-shrink:0;">⚠️</span>
    <div style="flex:1;font-size:12px;color:#92400e;">
        <b>Splynx not configured.</b> <a href="?page=dashboard&tab=settings#settings-splynx" style="color:#1d4ed8;">Settings → Splynx ISP</a>
    </div>
    <button onclick="document.getElementById('splynxWarn').style.display='none'" style="background:none;border:none;color:#92400e;font-size:18px;cursor:pointer;padding:0;line-height:1;">✕</button>
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="noc-card" id="nocSummaryCard">
    <div class="noc-title">Installation Overview</div>
    <div style="display:flex;overflow-x:auto;gap:4px;padding-bottom:4px;-webkit-overflow-scrolling:touch;scrollbar-width:none;" id="nocStats">
        <div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#8b5cf6;"><?= (int)($nocCache['new'] ?? 0) ?></div><div class="noc-stat-lbl">New</div></div>
        <div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#f59e0b;"><?= (int)($nocCache['survey_done'] ?? 0) ?></div><div class="noc-stat-lbl">Surveyed</div></div>
        <div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#3b82f6;"><?= (int)($nocCache['deploying'] ?? 0) ?></div><div class="noc-stat-lbl">Deploying</div></div>
        <div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#7c3aed;"><?= (int)($nocCache['ready_onu'] ?? 0) ?></div><div class="noc-stat-lbl">ONU Ready</div></div>
        <div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#f97316;"><?= (int)($nocCache['waiting'] ?? 0) ?></div><div class="noc-stat-lbl">Waiting</div></div>
        <div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#10b981;"><?= (int)($nocCache['completed'] ?? 0) ?></div><div class="noc-stat-lbl">Done</div></div>
    </div>
    <div style="display:flex;overflow-x:auto;gap:4px;padding-top:4px;-webkit-overflow-scrolling:touch;scrollbar-width:none;">
        <div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#dc2626;font-weight:900;"><?= (int)($nocCache['total_pending'] ?? 0) ?></div><div class="noc-stat-lbl">Pending</div></div>
        <div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#94a3b8;"><?= (int)($nocCache['total_blocked'] ?? 0) ?></div><div class="noc-stat-lbl">Blocked</div></div>
        <div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#ef4444;"><?= (int)($nocCache['cancelled'] ?? 0) ?></div><div class="noc-stat-lbl">Cancelled</div></div>
        <div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#6b7280;"><?= (int)($nocCache['total'] ?? 0) ?></div><div class="noc-stat-lbl">Total</div></div>
    </div>
    <?php if (!empty($nocCache['updated_at'])): ?>
    <div style="font-size:10px;color:#9ca3af;text-align:center;margin-top:8px;">Last synced: <?= h($nocCache['updated_at']) ?></div>
    <?php endif; ?>
</div>

<!-- Area Dispatch Grid -->
<div class="noc-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div class="noc-title" style="margin:0;">📍 Area Dispatch — Open Tickets by Area</div>
        <button class="noc-btn sm secondary" onclick="nocClearAreaFilter()">Show All</button>
    </div>
    <div class="noc-area-grid" id="nocAreaGrid">
        <div style="text-align:center;color:#9ca3af;padding:20px;grid-column:1/-1;">Loading areas…</div>
    </div>
</div>

<!-- Batch Assign Bar (appears when area selected) -->
<div class="noc-batch-bar" id="nocBatchBar">
    <span style="font-weight:700;font-size:13px;">📍 <span id="nocBatchAreaName">—</span></span>
    <span style="font-size:12px;color:#6b7280;"><span id="nocBatchCount">0</span> unassigned tickets</span>
    <select id="nocBatchEngineer" style="border:1.5px solid #bfdbfe;border-radius:8px;padding:6px 10px;font-size:12px;">
        <option value="">Select Engineer…</option>
        <?php foreach ($nocEngineers as $eng): ?>
        <option value="<?= h($eng['name']) ?>"><?= h($eng['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="noc-btn sm success" onclick="nocBatchAssign()">🚀 Assign All</button>
</div>

<!-- Engineer Workload -->
<div class="noc-card" id="nocEngineerCard" style="display:none;">
    <div class="noc-title">Engineer Workload (Active Jobs)</div>
    <div id="nocEngineerList"></div>
</div>

<!-- Ticket List -->
<div class="noc-card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <div class="noc-title" style="margin:0;">Installation Jobs</div>
        <span id="nocTicketCount" style="font-size:12px;color:#6b7280;font-weight:600;"></span>
    </div>
    <div class="noc-filter-bar">
        <button class="noc-filter-btn active" data-filter="open"       onclick="nocFilter(this,'open')">🔥 Open</button>
        <button class="noc-filter-btn"        data-filter="all"        onclick="nocFilter(this,'all')">All</button>
        <button class="noc-filter-btn"        data-filter="unassigned" onclick="nocFilter(this,'unassigned')">⚠️ Unassigned</button>
        <button class="noc-filter-btn"        data-filter="completed"  onclick="nocFilter(this,'completed')">✅ Completed</button>
    </div>
    <div id="nocTicketList"><div style="text-align:center;color:#9ca3af;padding:20px;">Loading…</div></div>
</div>

<script>
(function(){
var _nocFilter = 'open';
var _nocAreaFilter = '';
var _nocTickets = [];
var _nocAreaDispatch = [];
var API_TOKEN = '<?= h($retailer['api_token'] ?? "") ?>';

function nocRenderAreaGrid(areas) {
    var grid = document.getElementById('nocAreaGrid');
    if (!grid) return;
    if (!areas || !areas.length) {
        grid.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:20px;grid-column:1/-1;">No area data. Run Sync first.</div>';
        return;
    }
    var html = '';
    areas.forEach(function(a) {
        var n = a.open_count || 0;
        var cls = n === 0 ? 'zero' : (a.urgent > 0 ? 'has-urgent' : '');
        if (_nocAreaFilter === a.area) cls += ' sel';
        html += '<div class="noc-area-cell ' + cls + '" onclick="' + (n > 0 ? "nocSelectArea('" + a.area.replace(/'/g,"\\'") + "')" : '') + '">';
        html += '<div class="area-name">' + a.area + '</div>';
        html += '<div class="area-count">' + n + '</div>';
        var parts = [];
        if (a.new > 0) parts.push(a.new + ' new');
        if (a.survey > 0) parts.push(a.survey + ' surveyed');
        if (a.deploying > 0) parts.push(a.deploying + ' deploying');
        if (a.urgent > 0) parts.push('<span style="color:#dc2626;">' + a.urgent + ' urgent</span>');
        html += '<div class="area-breakdown">' + (parts.join(' · ') || '—') + '</div>';
        html += '</div>';
    });
    grid.innerHTML = html;
}

function nocRenderEngineers(engineers) {
    var card = document.getElementById('nocEngineerCard');
    var list = document.getElementById('nocEngineerList');
    if (!engineers || !Object.keys(engineers).length) { card.style.display = 'none'; return; }
    card.style.display = '';
    var html = '';
    for (var eng in engineers) {
        html += '<div class="noc-eng-row"><span style="font-weight:600;font-size:14px;">' + eng + '</span>'
             + '<span style="background:#dbeafe;color:#1d4ed8;border-radius:8px;padding:2px 10px;font-weight:700;font-size:13px;">' + engineers[eng] + ' job' + (engineers[eng] !== 1 ? 's' : '') + '</span></div>';
    }
    list.innerHTML = html;
}

function nocRenderTickets(tickets) {
    var list = document.getElementById('nocTicketList');
    var countEl = document.getElementById('nocTicketCount');
    if (!list) return;
    if (countEl) countEl.textContent = tickets.length + ' ticket' + (tickets.length !== 1 ? 's' : '');
    if (!tickets.length) {
        list.innerHTML = '<div style="text-align:center;color:#9ca3af;padding:20px;font-size:13px;">No tickets match this filter.</div>';
        return;
    }
    var html = '';
    tickets.forEach(function(t) {
        var complete = t.install_complete;
        var status   = t.status_label || 'unknown';
        var area     = t.area || 'Unknown';
        var engName  = t.assigned_engineer_name || t.engineer || '';
        var phone    = t.phone || '';
        var ftth     = t.ftth_number || '';
        var createdDays = Math.floor((Date.now() - new Date(t.created_at || Date.now()).getTime()) / 86400000);
        var isUrgent = createdDays > 3 && !engName && !complete;
        var cls      = complete ? 'complete' : (isUrgent ? 'urgent-border' : '');
        var dateStr  = (t.created_at || '').split(' ')[0];

        var badgeCls = 'open';
        if (complete) badgeCls = 'completed';
        else if (status === 'new') badgeCls = 'new';
        else if (status === 'survey done') badgeCls = 'survey';
        else if (status.indexOf('deploy') >= 0) badgeCls = 'deploying';

        var curStatus = t.status || 0;
        html += '<div class="noc-ticket ' + cls + '">'
             + '<div style="display:flex;justify-content:space-between;align-items:flex-start;">'
             +   '<div style="flex:1;min-width:0;">'
             +     '<div style="font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + (t.customer_name || 'Unknown') + '</div>'
             +     '<div style="font-size:12px;color:#6b7280;margin-top:2px;">'
             +       '📍 ' + (t.address || '<span style="color:#ef4444;">No address</span>')
             +       (area !== 'Unknown' ? ' <span style="background:#ede9fe;color:#5b21b6;border-radius:6px;padding:1px 6px;font-size:10px;font-weight:700;">' + area + '</span>' : '')
             +     '</div>'
             +     '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">'
             +       (engName ? '👷 ' + engName + ' · ' : '')
             +       '📅 ' + dateStr
             +       (ftth ? ' · ' + ftth : '')
             +       (isUrgent ? ' · <span style="color:#dc2626;font-weight:700;">⏰ ' + createdDays + 'd old</span>' : '')
             +     '</div>'
             +   '</div>'
             +   '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">'
             +     '<span class="noc-badge ' + badgeCls + '">' + status + '</span>'
             +   '</div>'
             + '</div>'
             // ── Action row: Phone + Assign + Status ──
             + '<div style="margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">'
             +   (phone
                   ? '<a href="tel:' + phone + '" style="display:inline-flex;align-items:center;gap:4px;background:#059669;color:#fff;border-radius:10px;padding:6px 12px;font-size:12px;font-weight:700;text-decoration:none;">📞 Call</a>'
                   : '<span style="display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#991b1b;border-radius:8px;padding:5px 10px;font-size:10px;font-weight:700;">⚠️ No phone</span>')
             +   (!engName && !complete
                   ? '<select onchange="nocAssignOne(' + t.id + ',this.value)" style="font-size:11px;border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 8px;background:#f8fafc;">'
                   +   '<option value="">👷 Assign…</option>'
                   +   <?= json_encode(implode('', array_map(function($e) { return '<option value=\"' . htmlspecialchars($e['name'], ENT_QUOTES) . '\">' . htmlspecialchars($e['name'], ENT_QUOTES) . '</option>'; }, $nocEngineers))) ?>
                   + '</select>'
                   : '')
             +   '<select onchange="nocChangeStatus(' + t.id + ',this.value)" style="font-size:11px;border:1.5px solid #dbeafe;border-radius:8px;padding:5px 8px;background:#eff6ff;color:#1d4ed8;font-weight:600;">'
             +     '<option value="">⚡ Status…</option>'
             +     '<option value="1"' + (curStatus==1?' selected':'') + '>🆕 New</option>'
             +     '<option value="2"' + (curStatus==2?' selected':'') + '>🔧 Work in Progress</option>'
             +     '<option value="7"' + (curStatus==7?' selected':'') + '>📋 Survey Done</option>'
             +     '<option value="8"' + (curStatus==8?' selected':'') + '>🚧 Fiber Deployment</option>'
             +     '<option value="9"' + (curStatus==9?' selected':'') + '>✅ Ready / ONU Mapped</option>'
             +     '<option value="3"' + (curStatus==3?' selected':'') + '>🏁 Resolved (Complete)</option>'
             +     '<option value="12"' + (curStatus==12?' selected':'') + '>⏸️ Client Not Ready</option>'
             +     '<option value="10"' + (curStatus==10?' selected':'') + '>❌ Cancel by Customer</option>'
             +     '<option value="11"' + (curStatus==11?' selected':'') + '>🚫 Fiber Not Available</option>'
             +   '</select>'
             + '</div>'
             + '</div>';
    });
    list.innerHTML = html;
}

function nocApplyFilter() {
    var f = _nocFilter;
    var filtered = _nocTickets.filter(function(t) {
        var done = t.install_complete;
        var status = t.status_label || '';
        // Area filter
        if (_nocAreaFilter && (t.area || 'Unknown') !== _nocAreaFilter) return false;
        // Status filter
        if (f === 'open')       return !done;
        if (f === 'completed')  return !!done;
        if (f === 'unassigned') return !done && !(t.assigned_engineer_name || t.engineer);
        return true; // 'all'
    });
    nocRenderTickets(filtered);
}

window.nocFilter = function(btn, filter) {
    document.querySelectorAll('.noc-filter-btn').forEach(function(b){ b.classList.remove('active'); });
    btn.classList.add('active');
    _nocFilter = filter;
    nocApplyFilter();
};

window.nocSelectArea = function(area) {
    if (_nocAreaFilter === area) { nocClearAreaFilter(); return; } // toggle off
    _nocAreaFilter = area;
    _nocFilter = 'open'; // reset to open when selecting area
    document.querySelectorAll('.noc-filter-btn').forEach(function(b){ b.classList.remove('active'); });
    document.querySelector('.noc-filter-btn[data-filter="open"]').classList.add('active');
    nocRenderAreaGrid(_nocAreaDispatch);
    nocApplyFilter();
    // Show batch bar
    var unassigned = _nocTickets.filter(function(t) {
        return !t.install_complete && (t.area || 'Unknown') === area && !(t.assigned_engineer_name || t.engineer);
    }).length;
    var bar = document.getElementById('nocBatchBar');
    document.getElementById('nocBatchAreaName').textContent = area;
    document.getElementById('nocBatchCount').textContent = unassigned;
    bar.classList.toggle('show', unassigned > 0);
    // Auto-scroll to ticket list so Bidal sees filtered results immediately
    setTimeout(function(){ var el = document.getElementById('nocTicketList'); if(el) el.scrollIntoView({behavior:'smooth', block:'start'}); }, 150);
};

window.nocClearAreaFilter = function() {
    _nocAreaFilter = '';
    document.getElementById('nocBatchBar').classList.remove('show');
    nocRenderAreaGrid(_nocAreaDispatch);
    nocApplyFilter();
};

window.nocBatchAssign = function() {
    var area = _nocAreaFilter;
    var eng  = document.getElementById('nocBatchEngineer').value;
    if (!area || !eng) { alert('Select an area and engineer.'); return; }
    if (!confirm('Assign ALL unassigned tickets in ' + area + ' to ' + eng + '?')) return;
    fetch('?page=api&action=splynx_batch_assign_area', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer ' + API_TOKEN},
        body: JSON.stringify({area: area, engineer_name: eng})
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.status === 'success') {
            alert('✅ Assigned ' + d.data.assigned + ' tickets in ' + area + ' to ' + eng);
            nocRefresh();
        } else alert('Error: ' + (d.message || '?'));
    }).catch(function(e){ alert('Network error: ' + e.message); });
};

window.nocChangeStatus = function(ticketId, statusId) {
    if (!statusId) return;
    var statusNames = {1:'New',2:'Work in Progress',3:'Resolved (Complete)',7:'Survey Done',8:'Fiber Deployment',9:'Ready / ONU Mapped',10:'Cancel by Customer',11:'Fiber Not Available',12:'Client Not Ready'};
    var label = statusNames[statusId] || 'Status ' + statusId;
    if (!confirm('Change ticket #' + ticketId + ' to "' + label + '"?\nThis will also update Splynx.')) {
        // re-render to reset the dropdown visually
        nocApplyFilter();
        return;
    }
    // Fix #10: optimistic local update before API returns
    _nocTickets = _nocTickets.map(function(t) {
        if (t.id === ticketId) {
            return Object.assign({}, t, {
                status: parseInt(statusId),
                status_label: label.toLowerCase(),
                install_complete: (parseInt(statusId) === 3)
            });
        }
        return t;
    });
    nocApplyFilter(); // instant re-render with new status
    fetch('?page=api&action=noc_update_status', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer ' + API_TOKEN},
        body: JSON.stringify({ticket_id: ticketId, status_id: parseInt(statusId)})
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.status === 'success') {
            var synced = d.data && d.data.splynx_synced;
            var msg = '✅ Status → "' + label + '"' + (synced ? ' — synced to Splynx' : ' — local only');
            showToast(msg, 'success');
            nocRefresh(); // background refresh for final state
        } else {
            showToast('Error: ' + (d.message || '?'), 'error');
            nocRefresh(); // rollback via refresh
        }
    }).catch(function(e){ showToast('Network error: ' + e.message, 'error'); nocRefresh(); });
};

window.nocAssignOne = function(ticketId, engName) {
    if (!engName) return;
    fetch('?page=api&action=noc_assign_engineer', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer ' + API_TOKEN},
        body: JSON.stringify({ticket_id: ticketId, engineer_name: engName})
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.status === 'success') {
            showToast('✅ Assigned to ' + engName, 'success');
            nocRefresh();
        } else {
            showToast('Error: ' + (d.message || '?'), 'error');
            nocRefresh(); // reset dropdown
        }
    }).catch(function(e){ showToast('Network error', 'error'); });
};
window.nocRefresh = function() {
    var btn = document.getElementById('nocRefreshBtn');
    if (btn) btn.disabled = true;
    var url = '?page=api&action=splynx_dashboard&filter=all';
    fetch(url, {credentials:'same-origin', headers: {'Authorization': 'Bearer ' + API_TOKEN} })
    .then(function(r){ return r.json(); }).then(function(d) {
        if (d.status !== 'success') { alert('Error: ' + (d.message || '?')); return; }
        var s = d.data.summary || {};
        // Update stats
        var sg = document.getElementById('nocStats');
        if (sg) {
            sg.innerHTML =
                '<div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#8b5cf6;">' + (s.new||0) + '</div><div class="noc-stat-lbl">New</div></div>' +
                '<div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#f59e0b;">' + (s.survey_done||0) + '</div><div class="noc-stat-lbl">Surveyed</div></div>' +
                '<div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#3b82f6;">' + (s.deploying||0) + '</div><div class="noc-stat-lbl">Deploying</div></div>' +
                '<div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#7c3aed;">' + (s.ready_onu||0) + '</div><div class="noc-stat-lbl">ONU Ready</div></div>' +
                '<div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#f97316;">' + (s.waiting||0) + '</div><div class="noc-stat-lbl">Waiting</div></div>' +
                '<div class="noc-stat" style="flex:0 0 15%;min-width:56px;"><div class="noc-stat-val" style="color:#10b981;">' + (s.completed||0) + '</div><div class="noc-stat-lbl">Done</div></div>';
        }
        // Second row stats
        var sg2 = document.getElementById('nocStats');
        if (sg2 && sg2.nextElementSibling) {
            sg2.nextElementSibling.innerHTML =
                '<div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#dc2626;font-weight:900;">' + (s.total_pending||0) + '</div><div class="noc-stat-lbl">Pending</div></div>' +
                '<div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#94a3b8;">' + (s.total_blocked||0) + '</div><div class="noc-stat-lbl">Blocked</div></div>' +
                '<div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#ef4444;">' + (s.cancelled||0) + '</div><div class="noc-stat-lbl">Cancelled</div></div>' +
                '<div class="noc-stat" style="flex:0 0 22%;min-width:62px;"><div class="noc-stat-val" style="color:#6b7280;">' + (s.total||0) + '</div><div class="noc-stat-lbl">Total</div></div>';
        }
        // Area dispatch grid
        _nocAreaDispatch = d.data.area_dispatch || [];
        nocRenderAreaGrid(_nocAreaDispatch);
        // Engineers
        nocRenderEngineers(s.engineers || {});
        // Tickets (unfiltered — we filter client-side)
        _nocTickets = d.data.tickets || [];
        nocApplyFilter();
    }).catch(function(e){ alert('Network error: ' + e.message); })
      .finally(function(){ if (btn) btn.disabled = false; });
};

window.nocRunSync = function() {
    if (!confirm('Run Splynx sync now? This also enriches tickets from CRM.')) return;
    var btn = document.getElementById('nocRefreshBtn');
    if (btn) btn.disabled = true;
    fetch('?page=api&action=splynx_run_sync', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer ' + API_TOKEN},
        body: '{}'
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (d.status !== 'success') { alert('Sync error: ' + (d.message || '?')); return; }
        var r = d.data;
        var msg = 'Sync complete!\n'
            + 'Tickets synced: ' + (r.tickets ? r.tickets.synced||0 : 0) + '\n'
            + 'New imported: ' + (r.tickets ? r.tickets.imported||0 : 0) + '\n'
            + 'CRM enriched: ' + (r.enrichment ? r.enrichment.enriched||0 : 0) + '\n'
            + 'Total: ' + (r.tickets ? r.tickets.total||0 : 0);
        alert(msg);
        nocRefresh();
    }).catch(function(e){ alert('Network error: ' + e.message); })
      .finally(function(){ if (btn) btn.disabled = false; });
};

window.nocRunEnrich = function() {
    if (!confirm('Enrich all tickets from CRM? This looks up addresses and assigns areas.')) return;
    fetch('?page=api&action=splynx_crm_enrich', {
          credentials:'same-origin',
          method: 'POST',
        headers: {'Content-Type':'application/json','Authorization':'Bearer ' + API_TOKEN},
        body: '{}'
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (d.status === 'success') {
            var r = d.data;
            var ci = r.cache_info || {};
            var msg = 'CRM Enrichment Results:\n'
                + '───────────────────\n'
                + 'Enriched: ' + r.enriched + '\n'
                + 'Failed: ' + r.failed + '\n'
                + 'Skipped: ' + r.skipped + '\n'
                + '───────────────────\n'
                + 'CRM Cache Diagnostics:\n'
                + '  Clients in CRM: ' + (ci.client_count||0) + '\n'
                + '  TicketID→CRM matches: ' + (ci.ticket_id_matches||0) + '\n'
                + '  CRM username index: ' + (ci.username_index_count||0) + '\n'
                + '  Name index entries: ' + (ci.name_index_count||0) + '\n';
            if (ci.ticket_id_samples && ci.ticket_id_samples.length) {
                msg += '  Match samples:\n';
                ci.ticket_id_samples.forEach(function(s) {
                    msg += '    ' + s + '\n';
                });
            }
            alert(msg);
            nocRefresh();
        } else alert('Error: ' + (d.message || '?'));
    }).catch(function(e){ alert('Network error: ' + e.message); });
};

// Auto-load on mount
nocRefresh();
// Auto-refresh every 5 minutes
setInterval(nocRefresh, 300000);
})();
</script>


<!-- ── Fiber Install Log — delivery note + ticket auto-close status ── -->
<?php include __DIR__ . '/../../includes/widgets/fiber_install_log.php'; ?>
