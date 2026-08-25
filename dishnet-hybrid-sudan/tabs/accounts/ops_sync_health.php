<?php
// Tab: ops_sync_health
// Extracted from public.php on 2026-03-15
?>
<?php $apiTok2 = h($retailer['api_token'] ?? ""); ?>


<!-- ── SUSPENSION-STYLE ALERT QUEUE (Starlink pattern) ─────────────────── -->
<?php
$allPending = array_filter($store->load('kyc_applications.json'),
    fn($a) => in_array($a['status']??'', ['pending','pending_sync']));
$now = time();
$overdueQueue = [];
foreach ($allPending as $a) {
    $created = strtotime($a['created_at'] ?? 'now');
    $ageHours = ($now - $created) / 3600;
    $overdueQueue[] = array_merge($a, [
        'age_hours'   => round($ageHours, 1),
        'age_label'   => $ageHours < 1 ? 'just now' :
                         ($ageHours < 24 ? round($ageHours).'h ago' :
                         round($ageHours/24).'d ago'),
        'urgency'     => $ageHours > 48 ? 'critical' : ($ageHours > 12 ? 'warning' : 'info'),
    ]);
}
usort($overdueQueue, fn($a,$b) => $b['age_hours'] <=> $a['age_hours']);
$criticalCount = count(array_filter($overdueQueue, fn($a)=>$a['urgency']==='critical'));
$warningCount  = count(array_filter($overdueQueue, fn($a)=>$a['urgency']==='warning'));
?>
<?php if (!empty($overdueQueue)): ?>
<div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;margin-bottom:16px;">
    <div style="padding:14px 18px;background:<?= $criticalCount>0?'linear-gradient(135deg,#DC2626,#b91c1c)':'linear-gradient(135deg,#D97706,#b45309)' ?>;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div>
            <div style="font-size:15px;font-weight:800;">⚠️ Pending Sync Queue</div>
            <div style="font-size:11px;opacity:.85;margin-top:2px;">
                <?= count($overdueQueue) ?> applications waiting
                <?php if ($criticalCount): ?> · <strong><?= $criticalCount ?> critical (&gt;48h)</strong><?php endif; ?>
                <?php if ($warningCount):  ?> · <strong><?= $warningCount  ?> overdue (&gt;12h)</strong><?php endif; ?>
            </div>
        </div>
        <button onclick="loadHeal()" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;">
            🔄 Retry All Sync
        </button>
    </div>
    <?php foreach (array_slice($overdueQueue,0,15) as $pq):
        $urgColor = $pq['urgency']==='critical'?'#DC2626':($pq['urgency']==='warning'?'#D97706':'#2563EB');
        $name = trim(($pq['firstname']??'').' '.($pq['lastname']??'')) ?: 'Application #'.$pq['id'];
    ?>
    <div style="display:flex;align-items:center;gap:14px;padding:12px 18px;border-bottom:1px solid #F1F5F9;">
        <div style="width:8px;height:8px;border-radius:50%;background:<?= h($urgColor) ?>;flex-shrink:0;"></div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:700;"><?= h($name) ?></div>
            <div style="font-size:11px;color:#64748B;margin-top:2px;">
                <?= h($pq['connectivity_type']??'') ?> · <?= h($pq['mobile']??'') ?>
                · Agent: <?= h($pq['retailer_name']??'—') ?>
            </div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:12px;font-weight:700;color:<?= h($urgColor) ?>;"><?= h($pq['age_label']) ?></div>
            <div style="font-size:10px;color:#94A3B8;"><?= h($pq['status']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (count($overdueQueue)>15): ?>
    <div style="padding:10px 18px;font-size:12px;color:#64748B;text-align:center;">
        + <?= count($overdueQueue)-15 ?> more — <a href="?page=dashboard&tab=applications&filter=pending">View all</a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:18px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:8px;"><i class="bi bi-activity" style="color:#DC2626;"></i>UCRM Sync Health</div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Failed jobs, stuck applications, unsynced collections, cron status.</div>
    </div>
    <button onclick="loadHealth()" class="lte-btn primary sm" id="health-btn"><i class="bi bi-arrow-repeat"></i> Refresh</button>
</div>

<!-- Status tiles -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
    <div class="hub-tile" id="ht-failed"><div class="hub-tile-icon" style="background:#FEE2E2;"><i class="bi bi-x-circle" style="color:#DC2626;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#DC2626;" id="ht-failed-val">—</div><div class="hub-tile-lbl">Failed Jobs</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#FEF3C7;"><i class="bi bi-hourglass-split" style="color:#D97706;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#D97706;" id="ht-stuck-val">—</div><div class="hub-tile-lbl">Stuck Apps (&gt;30m)</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#fff0f0;"><i class="bi bi-cloud-slash" style="color:#D41C1C;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#D41C1C;" id="ht-coll-val">—</div><div class="hub-tile-lbl">Unsynced Payments</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#D1FAE5;"><i class="bi bi-clock-history" style="color:#059669;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#059669;font-size:13px;" id="ht-cron-val">—</div><div class="hub-tile-lbl">Last Cron Run</div></div></div>
</div>

<!-- Failed jobs -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:12px;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;display:flex;align-items:center;gap:6px;"><i class="bi bi-x-circle" style="color:#DC2626;"></i>Failed CRM Queue Jobs</div>
    <div id="ht-failed-jobs"><div style="padding:20px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<!-- Stuck apps -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:12px;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;display:flex;align-items:center;gap:6px;"><i class="bi bi-hourglass-split" style="color:#D97706;"></i>Stuck Applications (&gt;30 min without CRM sync)</div>
    <div id="ht-stuck-apps"><div style="padding:20px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<!-- Recent errors -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;display:flex;align-items:center;gap:6px;"><i class="bi bi-journal-x" style="color:#DC2626;"></i>Recent Activity Log Errors</div>
    <div id="ht-errors"><div style="padding:20px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<script>
(function(){
var TK='<?= $apiTok2 ?>';
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmt(d){return d?d.substring(0,16).replace('T',' '):'';}
function timeSince(d){if(!d)return'—';var s=Math.floor((Date.now()-new Date(d))/1000);if(s<60)return s+'s ago';if(s<3600)return Math.floor(s/60)+'m ago';return Math.floor(s/3600)+'h ago';}

window.loadHealth = function(){
    var btn=document.getElementById('health-btn');
    btn.disabled=true;
    fetch('?page=api&action=ucrm_sync_health',{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        btn.disabled=false;
        if(d.status!=='success') return;
        var h=d.data;
        document.getElementById('ht-failed-val').textContent=h.queue.failed;
        document.getElementById('ht-stuck-val').textContent=h.applications.stuck;
        document.getElementById('ht-coll-val').textContent=h.collections.unsynced;
        document.getElementById('ht-cron-val').textContent=h.cron.sync_last_run?timeSince(h.cron.sync_last_run):'Never';
        document.getElementById('ht-failed-val').style.color=h.queue.failed>0?'var(--red)':'var(--green)';
        document.getElementById('ht-stuck-val').style.color=h.applications.stuck>0?'#D97706':'var(--green)';

        // Failed jobs
        var fj=document.getElementById('ht-failed-jobs');
        if(!h.queue.failed_jobs.length){fj.innerHTML='<div style="padding:16px;text-align:center;color:var(--green);font-weight:700;">✓ No failed jobs</div>';
        } else {
            var fh='<table class="lte-tbl" style="font-size:11px;"><thead><tr><th>App ID</th><th>Customer</th><th>Agent</th><th>Error</th><th>Attempts</th><th>Created</th></tr></thead><tbody>';
            h.queue.failed_jobs.forEach(function(j){
                fh+='<tr><td style="font-family:monospace;">'+esc(j.app_id||j.id||'')+'</td><td>'+esc(j.customer_name||'')+'</td><td>'+esc(j.retailer_name||'')+'</td><td style="color:var(--red);max-width:200px;overflow:hidden;text-overflow:ellipsis;" title="'+esc(j.last_error||'')+'">'+esc((j.last_error||'').substring(0,60))+'</td><td>'+((j.attempts)||0)+'</td><td>'+fmt(j.created_at)+'</td></tr>';
            });
            fj.innerHTML=fh+'</tbody></table>';
        }

        // Stuck apps
        var sa=document.getElementById('ht-stuck-apps');
        if(!h.applications.stuck_apps.length){sa.innerHTML='<div style="padding:16px;text-align:center;color:var(--green);font-weight:700;">✓ No stuck applications</div>';
        } else {
            var sh='<table class="lte-tbl" style="font-size:11px;"><thead><tr><th>App ID</th><th>Customer</th><th>Agent</th><th>Service</th><th>Status</th><th>Submitted</th><th>Waiting</th></tr></thead><tbody>';
            h.applications.stuck_apps.forEach(function(a){
                var mins=Math.floor((Date.now()-new Date(a.created_at))/60000);
                sh+='<tr><td style="font-family:monospace;">'+esc(a.id||'')+'</td><td style="font-weight:600;">'+esc((a.firstname||'')+' '+(a.lastname||''))+'</td><td>'+esc(a.retailer_name||'')+'</td><td>'+esc(a.customer_type||'')+'</td><td><span style="background:#FEF3C7;color:#92400E;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:700;">'+esc(a.status||'')+'</span></td><td>'+fmt(a.created_at)+'</td><td style="color:#D97706;font-weight:700;">'+mins+'m</td></tr>';
            });
            sa.innerHTML=sh+'</tbody></table>';
        }

        // Recent errors
        var er=document.getElementById('ht-errors');
        if(!h.recent_errors.length){er.innerHTML='<div style="padding:16px;text-align:center;color:var(--green);font-weight:700;">✓ No recent errors in activity log</div>';
        } else {
            var eh='<table class="lte-tbl" style="font-size:11px;"><thead><tr><th>Time</th><th>Event</th><th>Actor</th><th>Detail</th></tr></thead><tbody>';
            h.recent_errors.forEach(function(e){
                eh+='<tr><td>'+fmt(e.created_at)+'</td><td style="color:var(--red);">'+esc(e.event||'')+'</td><td>'+esc(e.actor||'')+'</td><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;" title="'+esc(e.detail||'')+'">'+esc((e.detail||'').substring(0,80))+'</td></tr>';
            });
            er.innerHTML=eh+'</tbody></table>';
        }
    }).catch(function(){btn.disabled=false;});
};
loadHealth();
})();
</script>

