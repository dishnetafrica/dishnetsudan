<?php
// Tab: sync_queue
// Extracted from public.php on 2026-03-15
        $qs       = $queue->getSummary();
        $jobs     = $store->load('crm_queue.json');
        $lastRun  = $store->load('sync_last_run.json');
        $autoInt  = (int)($config['auto_sync_interval'] ?? 60);
        usort($jobs, function($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
        $pendingJobs = array_filter($jobs, fn($j) => ($j['status']??'') === 'pending');
    ?>

<!-- Sync Control Hero -->
<div style="background:linear-gradient(135deg,#1A1A1A,#2A2A2A);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:11px;opacity:.6;font-weight:700;text-transform:uppercase;letter-spacing:1px;">CRM Sync Queue</div>
            <div style="font-size:22px;font-weight:800;margin-top:4px;">DishNet ↔ UCRM</div>
            <div style="font-size:12px;opacity:.75;margin-top:4px;">
                <?php if ($lastRun): ?>
                    Last sync: <strong><?= h($lastRun['ran_at']) ?></strong> by <?= h($lastRun['ran_by']) ?>
                    <?php if (($lastRun['exit_code'] ?? 0) === 0): ?>
                        <span style="background:rgba(16,185,129,.3);padding:1px 8px;border-radius:20px;font-size:10px;margin-left:6px;">✓ OK</span>
                    <?php else: ?>
                        <span style="background:rgba(239,68,68,.3);padding:1px 8px;border-radius:20px;font-size:10px;margin-left:6px;">⚠ Error</span>
                    <?php endif; ?>
                <?php else: ?>
                    Never synced manually — using cron only
                <?php endif; ?>
            </div>
            <div id="autoSyncStatus" style="font-size:11px;opacity:.65;margin-top:4px;">
                <?php if ($autoInt > 0): ?>
                    🔄 Auto-refresh every <strong><?= $autoInt ?>s</strong>
                <?php else: ?>
                    ⏸ Auto-refresh off
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <!-- Sync Now button -->
            <form method="POST" style="margin:0;" id="syncNowForm">
            <?= csrfField() ?>
                <input type="hidden" name="action" value="sync_now">
                <button type="submit" id="syncNowBtn"
                    style="background:#10b981;color:#fff;border:none;border-radius:12px;padding:12px 20px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;box-shadow:0 4px 14px rgba(16,185,129,.4);">
                    <i class="bi bi-lightning-charge-fill"></i> Sync Now
                </button>
            </form>
            <!-- Refresh page -->
            <a href="?page=dashboard&tab=sync_queue"
                style="background:rgba(255,255,255,.15);color:#fff;border:none;border-radius:12px;padding:12px 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:flex;align-items:center;gap:6px;">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </a>
        </div>
    </div>
</div>

<!-- Stats Row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:16px;">
    <div style="text-align:center;padding:14px;background:#fff;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #f1f5f9;">
        <div style="font-size:26px;font-weight:800;color:#374151;"><?= $qs['total'] ?? 0 ?></div>
        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:700;margin-top:2px;">Total</div>
    </div>
    <div style="text-align:center;padding:14px;background:#fefce8;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #fef08a;">
        <div style="font-size:26px;font-weight:800;color:#854d0e;" id="statPending"><?= $qs['pending'] ?? 0 ?></div>
        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:700;margin-top:2px;">Pending</div>
        <?php if (count($pendingJobs) > 0): ?>
        <div style="font-size:9px;background:#fef08a;color:#854d0e;border-radius:20px;padding:1px 8px;margin-top:4px;font-weight:700;">⏳ Waiting</div>
        <?php endif; ?>
    </div>
    <div style="text-align:center;padding:14px;background:#f0fdf4;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #bbf7d0;">
        <div style="font-size:26px;font-weight:800;color:#166534;" id="statCompleted"><?= $qs['completed'] ?? 0 ?></div>
        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:700;margin-top:2px;">Synced</div>
    </div>
    <div style="text-align:center;padding:14px;background:#fef2f2;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #fecaca;">
        <div style="font-size:26px;font-weight:800;color:#991b1b;"><?= ($qs['failed'] ?? 0) + ($qs['exhausted'] ?? 0) ?></div>
        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:700;margin-top:2px;">Failed</div>
    </div>
    <div style="text-align:center;padding:14px;background:#f3f4f6;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);border:1px solid #e5e7eb;">
        <div style="font-size:26px;font-weight:800;color:#6b7280;"><?= $qs['reversed'] ?? 0 ?></div>
        <div style="font-size:10px;color:#9ca3af;text-transform:uppercase;font-weight:700;margin-top:2px;">Reversed</div>
    </div>
</div>

<!-- Live Sync Log (shown during AJAX sync + last run history) -->
<div id="syncLogWrap" style="<?= ($lastRun && !empty($lastRun['lines'])) ? '' : 'display:none;' ?>margin-bottom:16px;">
    <div class="kyc-card">
        <div class="kyc-card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span><i class="bi bi-terminal"></i> Sync Log</span>
            <span id="syncLogMeta" style="font-size:11px;font-weight:400;color:#6b7280;">
                <?php if ($lastRun): ?>
                    Last run: <?= h($lastRun['ran_at']) ?> by <?= h($lastRun['ran_by'] ?? 'Admin') ?>
                    <?php if (!empty($lastRun['success'])): ?> · <span style="color:#10b981;font-weight:700;"><?= (int)$lastRun['success'] ?> synced</span><?php endif; ?>
                    <?php if (!empty($lastRun['failed'])): ?> · <span style="color:#ef4444;font-weight:700;"><?= (int)$lastRun['failed'] ?> failed</span><?php endif; ?>
                <?php endif; ?>
            </span>
        </div>
        <div id="syncLogBox" style="background:#0f172a;border-radius:0 0 12px 12px;padding:14px 16px;font-family:monospace;font-size:12px;color:#94a3b8;max-height:220px;overflow-y:auto;line-height:1.8;">
            <?php if ($lastRun && !empty($lastRun['lines'])): ?>
                <?php foreach ($lastRun['lines'] as $line): ?>
                    <div style="color:<?= (str_contains($line,'✓')||str_contains($line,'🎉'))?'#a3e635':(str_contains($line,'✗')?'#f87171':(str_contains($line,'↩')?'#fb923c':'#94a3b8')) ?>;"><?= h($line) ?></div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#475569;">Click ⚡ Sync Now to push pending applications to UCRM…</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Queue Jobs Table -->
<div class="kyc-card">
    <div class="kyc-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span><i class="bi bi-list-check"></i> Queue Jobs <small style="font-weight:400;color:#6b7280;">(last 50)</small></span>
        <span id="liveIndicator" style="font-size:11px;color:#10b981;font-weight:600;"></span>
    </div>
    <div style="overflow-x:auto;">
    <table class="kyc-table"><thead><tr>
        <th>ID</th><th>Customer</th><th>Status</th><th>Tries</th><th>CRM ID</th><th>Error</th><th>Queued</th>
    </tr></thead><tbody id="queueTbody">
    <?php foreach (array_slice($jobs, 0, 50) as $j):
        $st = $j['status'] ?? '';
        $bc = $st==='completed'?'success':($st==='pending'?'warning':($st==='processing'?'primary':'danger'));
    ?>
    <tr>
        <td><code><?= (int)($j['id'] ?? 0) ?></code></td>
        <td>
            <div style="font-weight:600;"><?= h(($j['firstname'] ?? '') . ' ' . ($j['lastname'] ?? '')) ?></div>
            <div style="font-size:10px;color:#6b7280;"><?= h($j['connectivity_type'] ?? '') ?></div>
        </td>
        <td><span class="kyc-badge <?= $bc ?>"><?= h($st) ?></span></td>
        <td><?= (int)($j['attempts'] ?? 0) ?></td>
        <td><?= $j['crm_client_id'] ? '<strong style="color:#D41C1C;">'.h($j['crm_client_id']).'</strong>' : '<span style="color:#9ca3af;">—</span>' ?></td>
        <td style="max-width:200px;font-size:11px;color:#dc2626;"><?= h($j['last_error'] ?? '') ?></td>
        <td style="font-size:11px;white-space:nowrap;"><?= h($j['created_at'] ?? '') ?></td>
    </tr>
    <?php endforeach; if (empty($jobs)): ?>
    <tr><td colspan="7" style="text-align:center;color:#9ca3af;padding:32px;">
        <div style="font-size:32px;margin-bottom:8px;">✅</div>
        <div style="font-size:14px;font-weight:600;">Queue is empty</div>
        <div style="font-size:12px;margin-top:4px;">All KYC applications are synced to CRM</div>
    </td></tr>
    <?php endif; ?>
    </tbody></table>
    </div>
</div>

<!-- Sync Queue JS -->
<script>
(function(){
var AUTO_INTERVAL = <?= (int)($config['auto_sync_interval'] ?? 60) ?> * 1000;
var timer = null, countdown = 0, syncing = false;

// ── AJAX Sync Now ──────────────────────────────────────────────
var form    = document.getElementById('syncNowForm');
var btn     = document.getElementById('syncNowBtn');
var logBox  = document.getElementById('syncLogBox');
var logWrap = document.getElementById('syncLogWrap');

function updateStats(summary) {
    var map = {statPending: summary.pending, statCompleted: summary.completed};
    Object.keys(map).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = map[id] || 0;
    });
}

function appendLog(lines) {
    if (!logBox || !logWrap) return;
    logWrap.style.display = 'block';
    lines.forEach(function(line) {
        var d = document.createElement('div');
        d.textContent = line;
        if (line.indexOf('✓') !== -1 || line.indexOf('🎉') !== -1) d.style.color = '#a3e635';
        else if (line.indexOf('✗') !== -1) d.style.color = '#f87171';
        else if (line.indexOf('↩') !== -1) d.style.color = '#fb923c';
        else d.style.color = '#94a3b8';
        logBox.appendChild(d);
    });
    logBox.scrollTop = logBox.scrollHeight;
}

if (form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (syncing) return;
        syncing = true;

        // Reset log
        if (logBox)  logBox.innerHTML = '';
        if (logWrap) logWrap.style.display = 'block';
        appendLog(['⏳ Connecting to UCRM…']);

        btn.disabled = true;
        btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;font-size:16px;">↻</span> Syncing…';

        // Stop auto-refresh while syncing
        clearInterval(timer);
        var ind = document.getElementById('liveIndicator');
        if (ind) ind.textContent = '⏳ Syncing…';

        fetch('?page=api&action=sync_now_ajax', {
          credentials:'same-origin',
          method: 'GET',
            credentials: 'same-origin',
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            appendLog(data.log || []);
            if (data.summary) updateStats(data.summary);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lightning-charge-fill"></i> Sync Now';
            syncing = false;
            // Restart auto-refresh
            if (AUTO_INTERVAL > 0) startCountdown();
        })
        .catch(function(err) {
            appendLog(['✗ Request failed: ' + err]);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-lightning-charge-fill"></i> Sync Now';
            syncing = false;
        });
    });
}

// ── Auto-Refresh countdown ─────────────────────────────────────
function startCountdown() {
    clearInterval(timer);
    countdown = AUTO_INTERVAL / 1000;
    var ind = document.getElementById('liveIndicator');
    timer = setInterval(function() {
        countdown--;
        if (ind) ind.textContent = '🔄 Auto-refresh in ' + countdown + 's';
        if (countdown <= 0) {
            clearInterval(timer);
            window.location.reload();
        }
    }, 1000);
}

if (AUTO_INTERVAL > 0) startCountdown();

document.addEventListener('visibilitychange', function() {
    if (document.hidden) { clearInterval(timer); }
    else if (AUTO_INTERVAL > 0 && !syncing) { startCountdown(); }
});
})();
</script>
<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>


    
