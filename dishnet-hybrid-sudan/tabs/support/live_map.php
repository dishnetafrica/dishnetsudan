<?php
// Tab: live_map
// Extracted from public.php on 2026-03-15
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
.lm-hero{background:linear-gradient(135deg,#1B5E20,#2E7D32);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;}
.lm-card{background:#fff;border-radius:16px;padding:14px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);border:1px solid #f1f5f9;}
.lm-staff-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;}
.lm-staff-row:last-child{border:none;}
.lm-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
.lm-dot.online{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2);}
.lm-dot.offline{background:#9ca3af;}
.lm-badge{background:#e8f5e9;color:#2e7d32;border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;}
.lm-badge.working{background:#fff3e0;color:#e65100;}
.lm-nav-btn{background:linear-gradient(135deg,#1976D2,#0D47A1);color:#fff;border:none;border-radius:10px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;}
.lm-trail-btn{background:#f3e8ff;color:#7c3aed;border:none;border-radius:10px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;}
#lm-map{width:100%;height:400px;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;background:#f8f9fa;}
.lm-checkin-row{display:flex;align-items:flex-start;gap:10px;padding:10px;background:#f8fafc;border-radius:10px;margin-bottom:8px;border-left:3px solid #22c55e;}
.lm-checkin-row.active{border-left-color:#f59e0b;background:#fffbeb;}
</style>

<div class="lm-hero">
    <div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:.8px;">Live Tracking</div>
    <div style="font-size:22px;font-weight:800;margin:4px 0;">📍 Live Staff Map</div>
    <div style="font-size:12px;opacity:.8;">Real-time field staff locations · Updates every 60s</div>
    <div style="display:flex;gap:8px;margin-top:12px;">
        <button onclick="lmRefresh()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:10px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;">🔄 Refresh</button>
        <span id="lm-last-refresh" style="font-size:11px;opacity:.7;align-self:center;"></span>
    </div>
</div>

<!-- Leaflet Map -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div id="lm-map"></div>

<div class="lm-card" style="margin-top:12px;">
    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">👥 Field Staff Status</div>
    <div id="lm-staff-list"><div style="text-align:center;padding:20px;color:#9ca3af;">Loading...</div></div>
</div>

<div class="lm-card">
    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">
        📋 Today's Check-ins
        <span style="float:right;">
            <input type="date" id="lm-date-filter" value="<?= date('Y-m-d') ?>" onchange="lmLoadCheckins()"
                style="border:1px solid #e2e8f0;border-radius:8px;padding:3px 8px;font-size:12px;">
        </span>
    </div>
    <div id="lm-stats-bar" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px;"></div>
    <div id="lm-checkins-list"><div style="text-align:center;padding:20px;color:#9ca3af;">Loading...</div></div>
</div>

<script>
var LM_TOKEN = '<?= $apiToken ?>';
var LM_API   = '?page=api';
var lmMap, lmMarkers = {}, lmTrailLayer = null;

// Init Leaflet map centered on Juba, South Sudan
lmMap = L.map('lm-map').setView([4.85, 31.58], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap', maxZoom: 18
}).addTo(lmMap);

function lmFetch(action, params) {
    var qs = Object.entries(Object.assign({action}, params)).map(([k,v])=>k+'='+encodeURIComponent(v)).join('&');
    return fetch(LM_API + '&' + qs, {credentials:'same-origin',headers: {'Authorization': 'Bearer ' + LM_TOKEN}})
        .then(r => r.json());
}

function lmRefresh() {
    lmLoadMap();
    lmLoadCheckins();
    document.getElementById('lm-last-refresh').textContent = 'Updated ' + new Date().toLocaleTimeString();
}

function lmLoadMap() {
    lmFetch('staff_live_map').then(function(res) {
        if (!res.data) return;
        var staff = res.data.staff || [];
        var listHtml = '';

        staff.forEach(function(s) {
            var online = s.is_online;
            var dotClass = online ? 'online' : 'offline';
            var badgeTxt = s.active_job ? '🔧 Job #' + s.active_job : (online ? '✅ Online' : '⚫ Offline');
            var badgeCls = s.active_job ? 'working' : '';

            // Update/add map marker
            var icon = L.divIcon({
                html: '<div style="background:' + (online?'#22c55e':'#9ca3af') + ';border-radius:50%;width:14px;height:14px;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.3);"></div>',
                className: '', iconSize: [14,14], iconAnchor: [7,7]
            });

            if (lmMarkers[s.agent_id]) {
                lmMarkers[s.agent_id].setLatLng([s.lat, s.lon]);
                lmMarkers[s.agent_id].setIcon(icon);
                lmMarkers[s.agent_id].setPopupContent('<b>' + s.agent_name + '</b><br>' + badgeTxt + '<br>🔋 ' + (s.battery >= 0 ? s.battery + '%' : 'N/A') + '<br><small>Last seen: ' + s.last_seen_min + 'm ago</small>');
            } else if (s.lat && s.lon) {
                lmMarkers[s.agent_id] = L.marker([s.lat, s.lon], {icon: icon})
                    .addTo(lmMap)
                    .bindPopup('<b>' + s.agent_name + '</b><br>' + badgeTxt + '<br>🔋 ' + (s.battery >= 0 ? s.battery + '%' : 'N/A') + '<br><small>Last seen: ' + s.last_seen_min + 'm ago</small>');
            }

            listHtml += '<div class="lm-staff-row">';
            listHtml += '<div class="lm-dot ' + dotClass + '"></div>';
            listHtml += '<div style="flex:1;">';
            listHtml += '<div style="font-size:14px;font-weight:700;">' + s.agent_name + '</div>';
            listHtml += '<div style="font-size:11px;color:#6b7280;">Jobs today: ' + s.jobs_today + ' · Last ping: ' + s.last_seen_min + 'm ago' + (s.battery>=0 ? ' · 🔋' + s.battery + '%' : '') + '</div>';
            listHtml += '</div>';
            listHtml += '<span class="lm-badge ' + badgeCls + '">' + badgeTxt + '</span>';

            if (s.lat && s.lon) {
                listHtml += '<button class="lm-trail-btn" onclick="lmShowTrail(' + s.agent_id + ',\'' + s.agent_name + '\')">📍 Trail</button>';
                listHtml += '<a class="lm-nav-btn" href="https://maps.google.com/?q=' + s.lat + ',' + s.lon + '" target="_blank">View</a>';
            }
            listHtml += '</div>';
        });

        if (!staff.length) listHtml = '<div style="text-align:center;padding:20px;color:#9ca3af;">No staff location data yet.<br><small>Engineers share location automatically when they check in to a job in the My Jobs tab.</small></div>';
        document.getElementById('lm-staff-list').innerHTML = listHtml;

        // Fit map to markers
        if (Object.keys(lmMarkers).length > 0) {
            var bounds = Object.values(lmMarkers).map(m => m.getLatLng());
            if (bounds.length) lmMap.fitBounds(bounds.map(ll => [ll.lat, ll.lng]), {padding: [30,30], maxZoom: 14});
        }
    }).catch(function() {
        document.getElementById('lm-staff-list').innerHTML = '<div style="color:#ef4444;padding:12px;">Failed to load staff locations</div>';
    });
}

function lmShowTrail(agentId, agentName) {
    if (lmTrailLayer) { lmMap.removeLayer(lmTrailLayer); lmTrailLayer = null; }
    lmFetch('staff_trail', {agent_id: agentId}).then(function(res) {
        var trail = (res.data || {}).trail || [];
        if (!trail.length) { alert('No trail data for today yet.'); return; }
        var points = trail.map(p => [p.lat, p.lon]);
        lmTrailLayer = L.polyline(points, {color:'#1976D2', weight:3, opacity:.7}).addTo(lmMap);
        lmMap.fitBounds(lmTrailLayer.getBounds(), {padding:[20,20]});

        // Start and end markers
        L.circleMarker(points[0], {radius:8, color:'#22c55e', fillColor:'#22c55e', fillOpacity:1})
            .addTo(lmMap).bindPopup('▶ Start: ' + trail[0].at);
        L.circleMarker(points[points.length-1], {radius:8, color:'#ef4444', fillColor:'#ef4444', fillOpacity:1})
            .addTo(lmMap).bindPopup('🏁 Last ping: ' + trail[trail.length-1].at);
    });
}

function lmLoadCheckins() {
    var date = document.getElementById('lm-date-filter').value;
    lmFetch('job_checkins_today', {date: date}).then(function(res) {
        var d = res.data || {};
        var stats = d.stats || {};
        var checkins = d.checkins || [];

        document.getElementById('lm-stats-bar').innerHTML =
            '<div style="background:#e8f5e9;border-radius:10px;padding:10px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#2e7d32;">' + (stats.total_checkins||0) + '</div><div style="font-size:11px;color:#6b7280;">Total Check-ins</div></div>' +
            '<div style="background:#fff3e0;border-radius:10px;padding:10px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#e65100;">' + (stats.in_progress||0) + '</div><div style="font-size:11px;color:#6b7280;">In Progress</div></div>' +
            '<div style="background:#f3e8ff;border-radius:10px;padding:10px;text-align:center;"><div style="font-size:22px;font-weight:800;color:#7c3aed;">' + (stats.avg_duration||0) + 'm</div><div style="font-size:11px;color:#6b7280;">Avg Duration</div></div>';

        var html = '';
        checkins.forEach(function(c) {
            var active = c.status === 'checked_in';
            var dur = c.duration_min ? c.duration_min + ' min' : (active ? '⏱ In progress' : '—');
            html += '<div class="lm-checkin-row' + (active?' active':'') + '">';
            html += '<div style="font-size:24px;">' + (active ? '🔧' : '✅') + '</div>';
            html += '<div style="flex:1;">';
            html += '<div style="font-size:13px;font-weight:700;">' + c.agent_name + ' — Job #' + c.job_id + '</div>';
            html += '<div style="font-size:11px;color:#6b7280;">In: ' + (c.checkin_at||'').substring(11,16) + (c.checkout_at ? ' · Out: ' + c.checkout_at.substring(11,16) : '') + ' · ' + dur + '</div>';
            if (c.note) html += '<div style="font-size:11px;color:#4b5563;margin-top:2px;">📝 ' + c.note + '</div>';
            if (c.checkin_lat) html += '<a href="https://maps.google.com/?q=' + c.checkin_lat + ',' + c.checkin_lon + '" target="_blank" style="font-size:10px;color:#1976d2;">📍 View location</a>';
            html += '</div></div>';
        });
        if (!html) html = '<div style="text-align:center;padding:20px;color:#9ca3af;">No check-ins for this date.</div>';
        document.getElementById('lm-checkins-list').innerHTML = html;
    });
}

// Auto-refresh every 60s
lmRefresh();
setInterval(lmRefresh, 60000);
</script>

