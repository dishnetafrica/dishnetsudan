<?php
// Tab: route_manager
// Extracted from public.php on 2026-03-15
        // Auto-provision API token for web-only users (support_leader logs in via PHP session, not PWA)
        if (empty($retailer['api_token'])) {
            $newTok = bin2hex(random_bytes(32));
            $store->updateOne('retailers.json', 'id', (int)$retailer['id'], [
                'api_token'       => $newTok,
                'token_issued_at' => time(),
            ]);
            $retailer['api_token'] = $newTok;
        }
        $apiToken = h($retailer['api_token'] ?? "");
    ?>

<style>
.rm-hero{background:linear-gradient(135deg,#1A237E,#283593);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;}
.rm-card{background:#fff;border-radius:16px;padding:14px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #f1f5f9;}
.rm-agent-card{background:#f8fafc;border-radius:14px;padding:12px;margin-bottom:10px;border:2px solid #e2e8f0;cursor:pointer;transition:.15s;}
.rm-agent-card.sel{border-color:#1976d2;background:#e3f2fd;}
.rm-job-item{display:flex;align-items:center;gap:10px;padding:10px;background:#f0f7ff;border-radius:10px;margin-bottom:6px;border:1px solid #bfdbfe;}
.rm-job-item.done{background:#f0fdf4;border-color:#86efac;}
.rm-drag-handle{font-size:18px;cursor:grab;color:#9ca3af;}
.rm-route-step{display:flex;align-items:flex-start;gap:12px;padding:12px;background:#fff;border-radius:12px;margin-bottom:8px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.rm-step-num{width:28px;height:28px;border-radius:50%;background:#1976d2;color:#fff;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.rm-step-num.done{background:#22c55e;}
.rm-assign-btn{background:linear-gradient(135deg,#1976D2,#0D47A1);color:#fff;border:none;border-radius:12px;padding:12px 20px;font-size:14px;font-weight:700;cursor:pointer;width:100%;}
.rm-progress{background:#e2e8f0;border-radius:6px;height:8px;overflow:hidden;margin-top:6px;}
.rm-progress-bar{height:100%;background:#22c55e;border-radius:6px;transition:.5s;}
</style>

<div class="rm-hero">
    <div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:.8px;">Field Operations</div>
    <div style="font-size:22px;font-weight:800;margin:4px 0;">🛣 Route Manager</div>
    <div style="font-size:12px;opacity:.8;">Assign job routes to field staff · Track progress in real time</div>
</div>

<div class="rm-card">
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
        <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;flex:1;">📅 Route Date</div>
        <input type="date" id="rm-date" value="<?= date('Y-m-d') ?>"
            style="border:1.5px solid #e2e8f0;border-radius:10px;padding:7px 12px;font-size:14px;">
    </div>

    <!-- Step 1: Pick Agent -->
    <div style="font-size:13px;font-weight:800;color:#1976d2;margin-bottom:10px;">① Select Field Agent</div>
    <div id="rm-agent-list">Loading agents...</div>
</div>

<div class="rm-card" id="rm-job-picker" style="display:none;">
    <div style="font-size:13px;font-weight:800;color:#1976d2;margin-bottom:10px;">② Add Jobs to Route</div>
    <div style="background:#f8fafc;border-radius:12px;padding:10px;margin-bottom:10px;">
        <input id="rm-job-search" type="text" placeholder="Search jobs by title or #ID..."
            style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 12px;font-size:14px;box-sizing:border-box;"
            oninput="rmSearchJobs(this.value)">
    </div>
    <div id="rm-job-results"></div>

    <!-- Selected jobs (orderable) -->
    <div style="font-size:13px;font-weight:800;color:#1976d2;margin:12px 0 8px;">③ Route Order
        <span id="rm-job-count" style="font-size:11px;color:#9ca3af;font-weight:600;"></span>
    </div>
    <div id="rm-route-list" style="min-height:60px;background:#f8fafc;border-radius:12px;padding:8px;"></div>

    <div style="margin-top:10px;">
        <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">📝 Note to agent (optional)</div>
        <textarea id="rm-note" rows="2" placeholder="E.g. Start from Juba Bridge side, call customer before arrival..."
            style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:9px;font-size:13px;box-sizing:border-box;resize:none;"></textarea>
    </div>

    <button class="rm-assign-btn" onclick="rmAssignRoute()">
        🚀 Assign Route &amp; Notify Agent via WhatsApp
    </button>
</div>

<!-- Today's Active Routes -->
<div class="rm-card">
    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">
        📊 Active Routes Today
        <button onclick="rmLoadActiveRoutes()" style="float:right;background:#e8f5e9;color:#2e7d32;border:none;border-radius:8px;padding:3px 10px;font-size:11px;cursor:pointer;">🔄 Refresh</button>
    </div>
    <div id="rm-active-routes">Loading...</div>
</div>

<script>
var RM_TOKEN  = '<?= $apiToken ?>';
var RM_API    = '?page=api';
var rmSelectedAgent = null;
var rmRouteJobs = [];  // [{id, title, address, gps_lat, gps_lon}]
var rmAllJobs = [];    // from CRM scheduling

function rmFetch(action, params, method, body) {
    var url = RM_API + '&action=' + action + (params ? '&' + new URLSearchParams(params).toString() : '');
    var opts = {headers: {'Authorization': 'Bearer ' + RM_TOKEN}};
    if (method === 'POST') {
        opts.method = 'POST';
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body || {});
    }
    return fetch(url, opts).then(r => r.json());
}

// Load agents
rmFetch('support_engineers').then(function(res) {
    var agents = (res.data || {}).agents || [];
    var html = '';
    agents.forEach(function(a) {
        html += '<div class="rm-agent-card" id="rm-a-' + a.id + '" onclick="rmSelectAgent(' + a.id + ',\'' + a.name.replace(/'/g,"\\'") + '\')">';
        html += '<div style="font-weight:700;">' + a.name + '</div>';
        html += '<div style="font-size:11px;color:#6b7280;">' + (a.role||'Support') + ' · ' + (a.phone||'') + '</div>';
        html += '</div>';
    });
    document.getElementById('rm-agent-list').innerHTML = html || '<div style="color:#9ca3af;">No agents found.</div>';
});

function rmSelectAgent(id, name) {
    rmSelectedAgent = {id: id, name: name};
    document.querySelectorAll('.rm-agent-card').forEach(c => c.classList.remove('sel'));
    document.getElementById('rm-a-' + id).classList.add('sel');
    document.getElementById('rm-job-picker').style.display = 'block';
    rmLoadAgentJobs(id);
}

function rmLoadAgentJobs(agentId) {
    var date = document.getElementById('rm-date').value;
    // Load UCRM jobs for this agent on the selected date
    rmFetch('scheduling_jobs', {date: date, agent_id: agentId}).then(function(res) {
        rmAllJobs = (res.data || {}).jobs || [];
        rmRenderJobResults(rmAllJobs);
    }).catch(function() {
        rmFetch('scheduling_jobs', {date: date}).then(function(res) {
            rmAllJobs = (res.data || {}).jobs || [];
            rmRenderJobResults(rmAllJobs);
        });
    });
}

function rmSearchJobs(q) {
    if (!q) { rmRenderJobResults(rmAllJobs); return; }
    q = q.toLowerCase();
    rmRenderJobResults(rmAllJobs.filter(j =>
        (j.title||'').toLowerCase().includes(q) ||
        String(j.id).includes(q) ||
        (j.address||'').toLowerCase().includes(q)
    ));
}

function rmRenderJobResults(jobs) {
    var inRoute = rmRouteJobs.map(j => j.id);
    var html = '';
    jobs.slice(0, 20).forEach(function(j) {
        var added = inRoute.includes(j.id);
        html += '<div class="rm-job-item' + (added?' done':'') + '">';
        html += '<div style="flex:1;">';
        html += '<div style="font-size:13px;font-weight:700;">#' + j.id + ' — ' + (j.title||j.address||'Job') + '</div>';
        html += '<div style="font-size:11px;color:#6b7280;">' + (j.address||'') + (j.date?' · '+j.date:'') + '</div>';
        html += '</div>';
        if (!added) {
            html += '<button onclick="rmAddJob(' + j.id + ',\'' + (j.title||'Job #'+j.id).replace(/'/g,"\\'") + '\',\'' + (j.address||'').replace(/'/g,"\\'") + '\',' + (j.gpsLat||0) + ',' + (j.gpsLon||0) + ')" style="background:#1976d2;color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:12px;cursor:pointer;">+ Add</button>';
        } else {
            html += '<span style="color:#22c55e;font-weight:700;font-size:12px;">✅ Added</span>';
        }
        html += '</div>';
    });
    document.getElementById('rm-job-results').innerHTML = html || '<div style="color:#9ca3af;padding:12px;text-align:center;">No jobs found. Jobs come from UCRM scheduling.</div>';
}

function rmAddJob(id, title, address, lat, lon) {
    if (rmRouteJobs.find(j => j.id === id)) return;
    rmRouteJobs.push({id: id, title: title, address: address, gps_lat: lat, gps_lon: lon});
    rmRenderRoute();
    rmRenderJobResults(rmAllJobs);
}

function rmRemoveJob(id) {
    rmRouteJobs = rmRouteJobs.filter(j => j.id !== id);
    rmRenderRoute();
    rmRenderJobResults(rmAllJobs);
}

function rmMoveJob(idx, dir) {
    var newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= rmRouteJobs.length) return;
    var tmp = rmRouteJobs[idx]; rmRouteJobs[idx] = rmRouteJobs[newIdx]; rmRouteJobs[newIdx] = tmp;
    rmRenderRoute();
}

function rmRenderRoute() {
    var html = '';
    document.getElementById('rm-job-count').textContent = '(' + rmRouteJobs.length + ' jobs)';
    rmRouteJobs.forEach(function(j, i) {
        var mapsUrl = (j.gps_lat && j.gps_lon)
            ? 'https://maps.google.com/?q=' + j.gps_lat + ',' + j.gps_lon
            : 'https://maps.google.com/?q=' + encodeURIComponent(j.address||j.title);
        html += '<div class="rm-route-step">';
        html += '<div class="rm-step-num">' + (i+1) + '</div>';
        html += '<div style="flex:1;">';
        html += '<div style="font-size:13px;font-weight:700;">#' + j.id + ' — ' + j.title + '</div>';
        html += '<div style="font-size:11px;color:#6b7280;">' + (j.address||'No address') + '</div>';
        html += '<a href="' + mapsUrl + '" target="_blank" style="font-size:10px;color:#1976d2;">🗺 Preview on Maps</a>';
        html += '</div>';
        html += '<div style="display:flex;flex-direction:column;gap:3px;">';
        if (i > 0) html += '<button onclick="rmMoveJob(' + i + ',-1)" style="background:#e8f5e9;border:none;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:12px;">▲</button>';
        if (i < rmRouteJobs.length-1) html += '<button onclick="rmMoveJob(' + i + ',1)" style="background:#fff3e0;border:none;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:12px;">▼</button>';
        html += '<button onclick="rmRemoveJob(' + j.id + ')" style="background:#fee2e2;color:#dc2626;border:none;border-radius:6px;padding:3px 8px;cursor:pointer;font-size:12px;">✕</button>';
        html += '</div></div>';
    });
    if (!rmRouteJobs.length) html = '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:12px;">No jobs added yet. Search and add jobs above.</div>';
    document.getElementById('rm-route-list').innerHTML = html;
}

function rmAssignRoute() {
    if (!rmSelectedAgent) { alert('Select an agent first.'); return; }
    if (!rmRouteJobs.length) { alert('Add at least one job to the route.'); return; }
    var date = document.getElementById('rm-date').value;
    var note = document.getElementById('rm-note').value;
    rmFetch('assign_route', null, 'POST', {
        agent_id: rmSelectedAgent.id,
        job_ids: rmRouteJobs.map(j => j.id),
        date: date,
        note: note
    }).then(function(res) {
        if (res.code === 200) {
            alert('✅ Route assigned to ' + rmSelectedAgent.name + '!\nWhatsApp notification sent.');
            rmRouteJobs = [];
            rmRenderRoute();
            rmLoadActiveRoutes();
        } else {
            alert('Error: ' + (res.message || 'Unknown error'));
        }
    });
}

function rmLoadActiveRoutes() {
    var date = document.getElementById('rm-date').value;
    rmFetch('staff_routes_admin', {date: date}).then(function(res) {
        var routes = (res.data || {}).routes || [];
        var html = '';
        routes.forEach(function(r) {
            var pct = r.jobs_total ? Math.round(r.jobs_done / r.jobs_total * 100) : 0;
            var loc = r.live_location;
            html += '<div style="background:#f8fafc;border-radius:14px;padding:12px;margin-bottom:10px;border:1px solid #e2e8f0;">';
            html += '<div style="display:flex;justify-content:space-between;align-items:center;">';
            html += '<div style="font-weight:700;">' + r.agent_name + '</div>';
            html += '<div style="font-size:12px;color:#6b7280;">' + r.jobs_done + '/' + r.jobs_total + ' jobs</div>';
            html += '</div>';
            html += '<div class="rm-progress"><div class="rm-progress-bar" style="width:' + pct + '%;"></div></div>';
            html += '<div style="display:flex;justify-content:space-between;margin-top:6px;font-size:11px;color:#9ca3af;">';
            html += '<span>Progress: ' + pct + '%</span>';
            if (loc && loc.is_online) {
                html += '<a href="https://maps.google.com/?q=' + loc.lat + ',' + loc.lon + '" target="_blank" style="color:#1976d2;text-decoration:none;">📍 Live Location</a>';
            } else {
                html += '<span>📍 Offline</span>';
            }
            html += '</div>';
            if (r.note) html += '<div style="font-size:11px;color:#4b5563;margin-top:4px;">📝 ' + r.note + '</div>';
            html += '</div>';
        });
        document.getElementById('rm-active-routes').innerHTML = html || '<div style="color:#9ca3af;text-align:center;padding:16px;">No routes assigned for this date.</div>';
    });
}

rmRenderRoute();
rmLoadActiveRoutes();
</script>

rmLoadActiveRoutes();
</script>

