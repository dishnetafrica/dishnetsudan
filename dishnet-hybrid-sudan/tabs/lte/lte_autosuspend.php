<?php
// Tab: lte_autosuspend
// Extracted from public.php on 2026-03-15
?>
<?php $apiTok2 = h($retailer['api_token'] ?? ""); $graceDays = (int)($config['lte_suspend_grace_days'] ?? 0); ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <div style="font-size:18px;font-weight:900;color:var(--text);display:flex;align-items:center;gap:8px;"><i class="bi bi-robot" style="color:#7C3AED;"></i>Auto-Suspend Engine</div>
        <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Grace period: <strong><?= $graceDays ?> day(s)</strong> after expiry before suspension · <a href="?page=dashboard&tab=settings" style="color:var(--primary);">Change in Settings →</a></div>
    </div>
    <div style="display:flex;gap:8px;">
        <button onclick="runCron()" class="lte-btn primary" id="cron-btn"><i class="bi bi-play-circle"></i> Run Now</button>
    </div>
</div>

<!-- Cron command -->
<?php $lteCronCmd = '*/5 * * * * php ' . h($config['lte_cron_path'] ?? __DIR__.'/cron_lte.php') . ' >> /tmp/lte_cron.log 2>&1'; ?>
<div style="background:#0F172A;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-family:monospace;font-size:12px;color:#94A3B8;">
    <div style="color:#64748B;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Crontab entry — runs every 5 minutes</div>
    <code style="color:#7DD3FC;"><?= h($lteCronCmd) ?></code>
    <button onclick="navigator.clipboard.writeText(this.dataset.cmd)" data-cmd="<?= h($lteCronCmd) ?>" style="float:right;background:#1E293B;border:1px solid #334155;color:#94A3B8;border-radius:5px;padding:2px 8px;cursor:pointer;font-size:11px;">Copy</button>
</div>

<!-- Stats tiles -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;" id="as-tiles">
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#FEE2E2;"><i class="bi bi-pause-circle" style="color:#DC2626;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#DC2626;" id="as-suspended-today">—</div><div class="hub-tile-lbl">Suspended (log)</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#D1FAE5;"><i class="bi bi-play-circle" style="color:#059669;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#059669;" id="as-reactivated-today">—</div><div class="hub-tile-lbl">Reactivated (log)</div></div></div>
    <div class="hub-tile"><div class="hub-tile-icon" style="background:#F3E8FF;"><i class="bi bi-shield-check" style="color:#7C3AED;font-size:18px;"></i></div><div><div class="hub-tile-val" style="color:#7C3AED;" id="as-grace">Grace <?= $graceDays ?>d</div><div class="hub-tile-lbl">After Expiry</div></div></div>
</div>

<!-- Cron output -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:14px;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:6px;"><i class="bi bi-terminal" style="color:var(--primary);"></i>Last Run Output</div>
    <div id="cron-output" style="padding:14px 16px;font-family:monospace;font-size:11px;color:var(--text-2);background:#F8FAFC;min-height:60px;white-space:pre-wrap;">Click "Run Now" to execute and see output.</div>
</div>

<!-- Suspension log -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;margin-bottom:14px;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:space-between;"><span style="display:flex;align-items:center;gap:6px;"><i class="bi bi-pause-circle" style="color:#DC2626;"></i>Auto-Suspend Log</span><button onclick="loadSuspendLog()" class="lte-btn ghost sm"><i class="bi bi-arrow-repeat"></i></button></div>
    <div id="as-suspend-log"><div style="padding:20px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<!-- Reactivation log -->
<div style="background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;">
    <div style="padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:800;display:flex;align-items:center;gap:6px;"><i class="bi bi-play-circle" style="color:#059669;"></i>Auto-Reactivation Log</div>
    <div id="as-reactivate-log"><div style="padding:20px;text-align:center;color:var(--text-3);">Loading…</div></div>
</div>

<script>
(function(){
var TK='<?= $apiTok2 ?>';
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmt(d){return d?d.substring(0,16).replace('T',' '):'';}

window.runCron = function(){
    var btn=document.getElementById('cron-btn');
    btn.disabled=true; btn.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i> Running…';
    document.getElementById('cron-output').textContent='Running cron_lte.php…';
    fetch('?page=api&action=lte_run_cron',{
          credentials:'same-origin',
          method:'POST',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        btn.disabled=false; btn.innerHTML='<i class="bi bi-play-circle"></i> Run Now';
        if(d.status==='success'){
            document.getElementById('cron-output').textContent=d.data.output||'(no output)';
            loadSuspendLog();
        } else {
            document.getElementById('cron-output').textContent='Error: '+(d.message||'Unknown');
        }
    }).catch(function(){btn.disabled=false;btn.innerHTML='<i class="bi bi-play-circle"></i> Run Now';});
};

window.loadSuspendLog = function(){
    fetch('?page=api&action=lte_auto_suspend_log',{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        if(d.status!=='success') return;
        var sl=d.data.suspended||[], rl=d.data.reactivated||[];
        document.getElementById('as-suspended-today').textContent=sl.length;
        document.getElementById('as-reactivated-today').textContent=rl.length;
        // Suspend log
        var sb=document.getElementById('as-suspend-log');
        if(!sl.length){sb.innerHTML='<div style="padding:16px;text-align:center;color:var(--text-3);">No auto-suspensions yet</div>';return;}
        var h='<table class="lte-tbl" style="font-size:12px;"><thead><tr><th>Date</th><th>Subscriber</th><th>Phone</th><th>IMSI</th><th>Expired</th><th>Magma</th></tr></thead><tbody>';
        sl.forEach(function(r){
            h+='<tr><td>'+fmt(r.suspended_at)+'</td><td style="font-weight:600;">'+esc(r.name)+'</td><td style="font-family:monospace;font-size:11px;">'+esc(r.phone)+'</td><td style="font-family:monospace;font-size:10px;">'+esc(r.imsi||'—')+'</td><td>'+esc(r.expires_at||'')+'</td><td>'+(r.magma_synced?'<span style="color:var(--green);">✓</span>':'<span style="color:var(--text-3);">—</span>')+'</td></tr>';
        });
        sb.innerHTML=h+'</tbody></table>';
        // Reactivate log
        var rb=document.getElementById('as-reactivate-log');
        if(!rl.length){rb.innerHTML='<div style="padding:16px;text-align:center;color:var(--text-3);">No auto-reactivations yet</div>';return;}
        h='<table class="lte-tbl" style="font-size:12px;"><thead><tr><th>Date</th><th>Subscriber</th><th>IMSI</th><th>Valid Until</th><th>Magma</th></tr></thead><tbody>';
        rl.forEach(function(r){
            h+='<tr><td>'+fmt(r.reactivated_at)+'</td><td style="font-weight:600;">'+esc(r.name)+'</td><td style="font-family:monospace;font-size:10px;">'+esc(r.imsi||'—')+'</td><td>'+esc(r.expires_at||'')+'</td><td>'+(r.magma_synced?'<span style="color:var(--green);">✓</span>':'<span style="color:var(--text-3);">—</span>')+'</td></tr>';
        });
        rb.innerHTML=h+'</tbody></table>';
    });
};
loadSuspendLog();
})();
</script>

