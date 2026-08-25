<?php
// Tab: ucrm_data
// Extracted from public.php on 2026-03-15
        $apiToken = h($retailer['api_token'] ?? "");
        $crmConfigured = $crm->isConfigured();
        $clientsCache  = count($store->load('ucrm_clients_cache.json')  ?? []);
        $plansCache    = count($store->load('ucrm_plans_cache.json')     ?? []);
        $servicesCache = count($store->load('ucrm_services_cache.json')  ?? []);
        $invoicesCache = count($store->load('ucrm_invoices_cache.json')  ?? []);
        $syncMeta      = $store->load('ucrm_sync_meta.json') ?? [];
    ?>

<style>
.usync-hero{background:linear-gradient(135deg,#1A1A1A,#2A2A2A);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;}
.usync-card{background:#fff;border-radius:16px;padding:16px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.05);border:1px solid #f1f5f9;}
.usync-entity{display:flex;align-items:center;gap:14px;padding:14px 16px;border-radius:14px;background:#f8fafc;border:1.5px solid #e2e8f0;margin-bottom:8px;transition:.15s;}
.usync-entity.running{border-color:#D41C1C;background:#fff5f5;}
.usync-entity.done{border-color:#28a745;background:#F0FDF4;}
.usync-entity.error{border-color:#dc3545;background:#FFF5F5;}
.usync-ico{font-size:28px;flex-shrink:0;width:44px;text-align:center;}
.usync-bar-wrap{background:#e2e8f0;border-radius:6px;height:6px;margin-top:6px;overflow:hidden;}
.usync-bar{height:100%;border-radius:6px;background:#D41C1C;transition:width .4s;}
.usync-btn{width:100%;padding:16px;background:linear-gradient(135deg,#D41C1C,#A81515);color:#fff;border:none;border-radius:14px;font-size:15px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;box-shadow:0 4px 16px rgba(212,28,28,.35);margin-bottom:8px;}
.usync-btn:disabled{opacity:.5;cursor:not-allowed;}
.usync-btn.push{background:linear-gradient(135deg,#1B5E20,#2E7D32);box-shadow:0 4px 16px rgba(27,94,32,.35);}
</style>

<div class="usync-hero">
    <div style="font-size:11px;opacity:.6;font-weight:700;text-transform:uppercase;letter-spacing:1px;">Admin</div>
    <div style="font-size:22px;font-weight:800;margin-top:4px;">🔄 UCRM Data Sync</div>
    <div style="font-size:12px;opacity:.75;margin-top:4px;">Pull latest data from UCRM into the plugin &amp; push new registrations out</div>
    <?php if ($syncMeta['last_sync'] ?? null): ?>
    <div style="margin-top:10px;background:rgba(255,255,255,.15);border-radius:10px;padding:8px 14px;font-size:12px;">
        ✓ Last full sync: <strong><?= h($syncMeta['last_sync']) ?></strong> by <?= h($syncMeta['by'] ?? 'Admin') ?>
    </div>
    <?php endif; ?>
    <?php if (!$crmConfigured): ?>
    <div style="margin-top:10px;background:#FFEBEE;border-radius:10px;padding:10px 14px;font-size:12px;color:#C62828;font-weight:700;">
        ⚠ UCRM API not configured — go to <a href="?page=dashboard&tab=settings" style="color:#C62828;">Settings</a> and set the CRM Base URL &amp; Auth Token first.
    </div>
    <?php endif; ?>
    <?php
    $pullMeta2 = $store->load('ucrm_pull_last_run.json') ?? [];
    $spSumm2   = $store->load('sp_summary.json') ?? [];
    $spTotal   = array_sum(array_map(fn($s)=>$s['count']??0, $spSumm2));
    ?>
    <div style="margin-top:10px;background:rgba(255,255,255,.12);border-radius:10px;padding:10px 14px;font-size:12px;">
      <?php if ($pullMeta2['ran_at'] ?? null): ?>
        ✅ Last auto-pull: <strong><?= h($pullMeta2['ran_at']) ?></strong>
        · <?= number_format($pullMeta2['cached']??0) ?> clients cached
        · <?= number_format($pullMeta2['sp_count']??0) ?> sales persons indexed
        · <?= number_format($spTotal) ?> attributed customers
      <?php else: ?>
        ⚠ <strong>Auto-pull has not run yet.</strong> Do a manual "Pull All Data" below — after that, nightly auto-pull takes over at 3:00 AM.
      <?php endif; ?>
    </div>
</div>

<!-- ── CACHED DATA COUNTS ─────────────────────────────────────── -->
<div class="usync-card">
    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">Currently Cached from UCRM</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
        <?php foreach([
            ['👥','Clients',  $clientsCache,  '#E3F2FD','#1565C0'],
            ['📋','Svc Plans',$plansCache,    '#F3E5F5','#7B1FA2'],
            ['📡','Services', $servicesCache, '#E8F5E9','#2E7D32'],
            ['🧾','Invoices', $invoicesCache, '#FFF3E0','#E65100'],
        ] as [$ico,$lbl,$cnt,$bg,$col]): ?>
        <div style="background:<?= $bg ?>;border-radius:12px;padding:12px;text-align:center;">
            <div style="font-size:24px;"><?= $ico ?></div>
            <div style="font-size:22px;font-weight:800;color:<?= $col ?>;"><?= number_format($cnt) ?></div>
            <div style="font-size:11px;color:<?= $col ?>;font-weight:700;"><?= $lbl ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── PULL FROM UCRM ─────────────────────────────────────────── -->
<div class="usync-card">
    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">⬇ Pull Data FROM UCRM</div>

    <?php foreach([
        ['clients',  '👥','Clients',      'All customer accounts',                 $clientsCache],
        ['plans',    '📋','Service Plans', 'Starlink, fiber, data packages',         $plansCache],
        ['services', '📡','Services',      'Active/suspended services per client',   $servicesCache],
        ['invoices', '🧾','Open Invoices', 'Unpaid &amp; partially paid invoices',   $invoicesCache],
    ] as [$key, $ico, $lbl, $desc, $cnt]): ?>
    <div class="usync-entity" id="usyncEnt_<?= $key ?>">
        <div class="usync-ico"><?= $ico ?></div>
        <div style="flex:1;min-width:0;">
            <div style="font-size:14px;font-weight:800;color:#1e293b;"><?= $lbl ?></div>
            <div style="font-size:11px;color:#6b7280;"><?= $desc ?></div>
            <div class="usync-bar-wrap"><div class="usync-bar" id="usyncBar_<?= $key ?>" style="width:<?= $cnt>0?'100%':'0%' ?>;<?= $cnt>0?'background:#28a745;':'' ?>"></div></div>
        </div>
        <div style="text-align:right;flex-shrink:0;">
            <div id="usyncCount_<?= $key ?>" style="font-size:15px;font-weight:800;color:#D41C1C;"><?= number_format($cnt) ?></div>
            <div id="usyncStatus_<?= $key ?>" style="font-size:10px;color:#9ca3af;"><?= $cnt>0?'cached':'not synced' ?></div>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="margin-top:4px;margin-bottom:12px;">
        <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:#374151;cursor:pointer;">
            <input type="checkbox" id="usyncClearFirst" style="width:16px;height:16px;">
            Clear existing cache before sync (full re-import)
        </label>
    </div>

    <button id="usyncPullBtn" class="usync-btn" onclick="usyncPullAll()" <?= !$crmConfigured?'disabled':'' ?>>
        <span style="font-size:20px;">⬇</span> Pull All Data from UCRM
    </button>
    <div id="usyncPullLog" style="font-family:monospace;font-size:11px;color:#6b7280;background:#f8fafc;border-radius:10px;padding:10px;min-height:40px;max-height:160px;overflow-y:auto;display:none;margin-top:6px;"></div>
</div>

<!-- ── SALES PERSON INDEX ───────────────────────────────────── -->
<?php
  $spSumm3 = $store->load('sp_summary.json') ?? [];
  arsort($spSumm3); // sort by count desc — already sorted but re-sort for safety
?>
<div class="usync-card">
    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">🔗 Sales Person Attribution Index</div>
    <?php if (empty($spSumm3)): ?>
    <div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">
        No index yet — pull clients data above first. Auto-pull builds this nightly.
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead><tr style="background:#f8fafc;">
        <th style="padding:7px 12px;text-align:left;color:#6b7280;">Sales Person</th>
        <th style="padding:7px 8px;text-align:center;color:#D41C1C;">Total</th>
        <th style="padding:7px 8px;text-align:center;color:#2E7D32;">Active</th>
        <th style="padding:7px 8px;text-align:center;color:#E65100;">Leads</th>
        <th style="padding:7px 8px;text-align:left;color:#6b7280;">Top Package</th>
        <th style="padding:7px 8px;text-align:center;color:#6b7280;">View</th>
      </tr></thead>
      <tbody>
      <?php foreach (array_slice($spSumm3,0,20,true) as $_spN => $_spS): ?>
      <?php
        $topPkg = '';
        if (!empty($_spS['packages'])) {
            arsort($_spS['packages']);
            $topPkg = array_key_first($_spS['packages']);
        }
      ?>
      <tr style="border-top:1px solid #f8fafc;">
        <td style="padding:7px 12px;font-weight:700;"><?= h($_spN) ?></td>
        <td style="padding:7px 8px;text-align:center;"><span style="background:#fff0f0;color:#1565C0;border-radius:20px;padding:2px 8px;font-weight:700;"><?= $_spS['count']??0 ?></span></td>
        <td style="padding:7px 8px;text-align:center;color:#2E7D32;font-weight:700;"><?= $_spS['active']??0 ?></td>
        <td style="padding:7px 8px;text-align:center;color:#E65100;font-weight:700;"><?= $_spS['leads']??0 ?></td>
        <td style="padding:7px 8px;font-size:11px;color:#6b7280;"><?= h($topPkg) ?></td>
        <td style="padding:7px 8px;text-align:center;">
          <?php
            // Find matching retailer
            $matchRid = 0;
            foreach ($allRetailers as $_mr) {
                $mrn = $_mr['name']??'';
                if (strcasecmp($mrn,$_spN)===0 || stripos($mrn,$_spN)===0 || stripos($_spN,$mrn)===0) {
                    $matchRid = (int)$_mr['id']; break;
                }
            }
          ?>
          <?php if ($matchRid): ?>
          <a href="?page=dashboard&tab=accounts_ledger&rid=<?= $matchRid ?>&lv=customers"
             style="font-size:10px;color:#D41C1C;font-weight:700;text-decoration:none;padding:2px 8px;background:#EFF6FF;border-radius:6px;">
            Ledger ↗
          </a>
          <?php else: ?>
          <span style="font-size:10px;color:#9ca3af;">no match</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Rebuild index button -->
<div style="margin-top:-4px;margin-bottom:12px;">
  <button onclick="rebuildSpIndex()" id="rebuildSpBtn"
    style="width:100%;padding:10px;background:linear-gradient(135deg,#6A1B9A,#7B1FA2);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
    🔗 Rebuild Sales Index from Current Cache
  </button>
  <div id="rebuildSpLog" style="font-size:11px;color:#6b7280;margin-top:6px;display:none;padding:8px 12px;background:#f8fafc;border-radius:8px;"></div>
</div>
<script>
window.rebuildSpIndex = async function() {
  var btn = document.getElementById('rebuildSpBtn');
  var log = document.getElementById('rebuildSpLog');
  btn.disabled = true;
  btn.innerHTML = '⏳ Building index…';
  log.style.display = 'block';
  log.textContent = 'Reading ' + <?= $clientsCache ?> + ' cached clients…';
  try {
    var res = await fetch('?page=api&action=ucrm_sync_done', {
          credentials:'same-origin',
          method:'POST',
      headers:{'Authorization':'Bearer <?= $apiToken ?>','Content-Type':'application/json'},
      body:'{}'
    }).then(r=>r.json());
    log.innerHTML = res.message
      ? '<strong style="color:#6A1B9A;">✅ ' + res.message + '</strong>'
      : '✅ Done — reload page to see results';
    btn.innerHTML = '✅ Done — reloading…';
    setTimeout(function(){ location.reload(); }, 1500);
  } catch(e) {
    log.textContent = '❌ Error: ' + e.message;
    btn.disabled = false;
    btn.innerHTML = '🔗 Rebuild Sales Index from Current Cache';
  }
};
</script>

<!-- ── PUSH TO UCRM (KYC sync) ───────────────────────────────── -->
<div class="usync-card">
    <div style="font-size:11px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">⬆ Push Data TO UCRM</div>
    <?php
        $qSummary = $queue->getSummary();
        $pendingQ = (int)($qSummary['pending'] ?? 0);
        $failedQ  = (int)($qSummary['failed']  ?? 0);
        $syncedQ  = (int)($qSummary['completed'] ?? 0);
        $lastRunData = $store->load('sync_last_run.json');
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px;">
        <div style="text-align:center;background:#FFF3E0;border-radius:12px;padding:10px;">
            <div style="font-size:20px;font-weight:800;color:#E65100;"><?= $pendingQ ?></div>
            <div style="font-size:10px;color:#9ca3af;font-weight:700;">Pending</div>
        </div>
        <div style="text-align:center;background:#E8F5E9;border-radius:12px;padding:10px;">
            <div style="font-size:20px;font-weight:800;color:#2E7D32;"><?= $syncedQ ?></div>
            <div style="font-size:10px;color:#9ca3af;font-weight:700;">Synced</div>
        </div>
        <div style="text-align:center;background:#FFEBEE;border-radius:12px;padding:10px;">
            <div style="font-size:20px;font-weight:800;color:#C62828;"><?= $failedQ ?></div>
            <div style="font-size:10px;color:#9ca3af;font-weight:700;">Failed</div>
        </div>
    </div>
    <?php if ($lastRunData): ?>
    <div style="background:#f8fafc;border-radius:10px;padding:8px 12px;font-size:11px;color:#6b7280;margin-bottom:10px;">
        Last push: <strong><?= h($lastRunData['ran_at'] ?? '') ?></strong>
        · ✅ <?= (int)($lastRunData['success']??0) ?> synced
        · ❌ <?= (int)($lastRunData['failed']??0) ?> failed
    </div>
    <?php endif; ?>
    <button id="usyncPushBtn" class="usync-btn push" onclick="usyncPushNow()" <?= !$crmConfigured?'disabled':'' ?>>
        <span style="font-size:20px;">⚡</span> Sync Now — Push <?= $pendingQ ?> Pending to UCRM
    </button>
    <div id="usyncPushLog" style="font-family:monospace;font-size:11px;color:#94a3b8;background:#0f172a;border-radius:10px;padding:10px;min-height:40px;max-height:180px;overflow-y:auto;display:none;margin-top:6px;line-height:1.8;"></div>
</div>

<div style="height:80px;"></div>

<script>
(function(){
var TOKEN   = '<?= $apiToken ?>';
var headers = { 'Authorization': 'Bearer ' + TOKEN, 'Content-Type': 'application/json' };
function apiGet(act, qs){return fetch('?page=api&action='+act+(qs||''),{credentials:'same-origin',headers:headers}).then(function(r){ return r.text().then(function(t){ try{ return JSON.parse(t); } catch(e){ return {status:'error',message:'Bad JSON: '+t.substring(0,300)}; } }); }); }
function apiPost(act, body){ return fetch('?page=api&action='+act,{
          credentials:'same-origin',
          method:'POST',headers:headers,body:JSON.stringify(body)}).then(function(r){ return r.text().then(function(t){ try{ return JSON.parse(t); } catch(e){ return {status:'error',message:'Bad JSON: '+t.substring(0,300)}; } }); }); }

// ── PULL ALL ──────────────────────────────────────────────────
window.usyncPullAll = async function() {
    var btn = document.getElementById('usyncPullBtn');
    var log = document.getElementById('usyncPullLog');
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;font-size:20px;">↻</span> Pulling from UCRM…';
    log.style.display = 'block';
    log.innerHTML = '';

    function addLog(msg, color) {
        var line = document.createElement('div');
        line.style.color = color || '#94a3b8';
        line.textContent = new Date().toLocaleTimeString() + '  ' + msg;
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;
    }

    // ── Toast system (Starlink Finance pattern) ────────────────────────────────
function showToast(message, type) {
    type = type || 'info';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'dn-toast ' + type;
    var icon = type==='success'?'✅':type==='error'?'❌':type==='warning'?'⚠️':'ℹ️';
    toast.innerHTML = icon + ' ' + message;
    container.appendChild(toast);
    setTimeout(function() {
        toast.style.animation = 'dnToastOut .3s ease forwards';
        setTimeout(function() { if(toast.parentNode) toast.parentNode.removeChild(toast); }, 320);
    }, 4500);
}
// auto-show PHP flash as toast
(function(){
    var fm = <?= json_encode($flash ? strip_tags($flash['msg']) : null) ?>;
    var ft = <?= json_encode($flash ? $flash['type'] : null) ?>;
    if(fm){document.addEventListener('DOMContentLoaded',function(){
        showToast(fm, ft==='danger'?'error':ft==='success'?'success':ft==='warning'?'warning':'info');
    });}
})();
var entities = ['plans','clients','services','invoices'];
    var allOk = true;

    for (var i = 0; i < entities.length; i++) {
        var ent = entities[i];
        var entEl    = document.getElementById('usyncEnt_'+ent);
        var barEl    = document.getElementById('usyncBar_'+ent);
        var countEl  = document.getElementById('usyncCount_'+ent);
        var statusEl = document.getElementById('usyncStatus_'+ent);
        if (entEl)    entEl.className    = 'usync-entity running';
        if (barEl)    barEl.style.width  = '30%';
        if (statusEl) statusEl.textContent = 'syncing…';
        addLog('Fetching ' + ent + '…');

        var page = 1;
        var totalCached = 0;
        var hasMore = true;
        while (hasMore) {
            try {
                var d = await apiGet('ucrm_pull_sync', '&entity='+ent+'&pg='+page);
                if (d.status !== 'success') {
                    addLog('  ⚠ Error: ' + (d.message||''), '#f87171');
                    if (entEl) entEl.className = 'usync-entity error';
                    allOk = false;
                    hasMore = false;
                } else {
                    var dd = d.data;
                    if (dd.warning) { addLog('  ⚠ ' + dd.warning, '#fb923c'); hasMore = false; break; }
                    totalCached = dd.total_cached || 0;
                    hasMore = dd.has_more && (dd.fetched > 0 || dd.clients_processed > 0);
                    if (barEl) barEl.style.width = hasMore ? '60%' : '100%';
                    if (countEl) countEl.textContent = totalCached.toLocaleString();
                    var detail = ent === 'services'
                        ? '  Page '+page+': '+dd.fetched+' services fetched' + (dd.errors ? ', '+dd.errors+' errors' : '') + ' ('+totalCached+' total)'
                        : '  Page '+page+': '+dd.fetched+' fetched' + (dd.unpaid_cached ? ', '+dd.unpaid_cached+' unpaid' : '') + ', '+totalCached+' total cached';
                    addLog(detail, '#a3e635');
                    page++;
                    if (page > 200) { hasMore = false; addLog('  (all pages fetched)', '#a3e635'); }
                }
            } catch(e) {
                addLog('  ⚠ ' + (e.message || 'Network error'), '#f87171');
                if (entEl) entEl.className = 'usync-entity error';
                allOk = false;
                hasMore = false;
            }
        }
        if (entEl && allOk) entEl.className = 'usync-entity done';
        if (barEl)          { barEl.style.width='100%'; if(allOk) barEl.style.background='#28a745'; }
        if (statusEl)       statusEl.textContent = allOk ? '✓ synced' : '⚠ error';
    }

    if (allOk) {
        addLog('⏳ Building Sales Person index…', '#94a3b8');
        var doneRes = await apiPost('ucrm_sync_done', {});
        addLog('✅ Full sync complete!', '#a3e635');
        if (doneRes && doneRes.message) {
            addLog('🔗 ' + doneRes.message, '#c4b5fd');
        }
        // Reload page after 2s so Sales Person Index table refreshes
        setTimeout(function() { location.reload(); }, 2000);
    }

    btn.disabled = false;
    btn.innerHTML = '<span style="font-size:20px;">⬇</span> Pull All Data from UCRM';
};

// ── PUSH (Sync Now) ───────────────────────────────────────────
window.usyncPushNow = function() {
    var btn = document.getElementById('usyncPushBtn');
    var log = document.getElementById('usyncPushLog');
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;font-size:20px;">↻</span> Syncing to UCRM…';
    log.style.display = 'block';
    log.innerHTML = '<div style="color:#475569;">Starting push sync…</div>';

    fetch('?page=api&action=sync_now_ajax', { headers: headers, credentials: 'same-origin' })
        .then(r => r.json())
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<span style="font-size:20px;">⚡</span> Sync Now — Push Pending to UCRM';
            if (d.status !== 'success') {
                log.innerHTML += '<div style="color:#f87171;">⚠ ' + (d.message||'Failed') + '</div>';
                return;
            }
            var lines = d.data.log || [];
            log.innerHTML = '';
            lines.forEach(function(l) {
                var c = l.indexOf('✓') !== -1 || l.indexOf('🎉') !== -1 ? '#a3e635'
                      : l.indexOf('✗') !== -1 ? '#f87171'
                      : l.indexOf('↩') !== -1 ? '#fb923c' : '#94a3b8';
                var div = document.createElement('div');
                div.style.color = c;
                div.textContent = l;
                log.appendChild(div);
            });
            log.scrollTop = log.scrollHeight;
            var s = d.data.summary || {};
            log.innerHTML += '<div style="color:#a3e635;margin-top:6px;font-weight:700;">✅ Done — ' + (s.success||0) + ' synced, ' + (s.failed||0) + ' failed</div>';
            // Reload push stats
            setTimeout(function(){ location.reload(); }, 2000);
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<span style="font-size:20px;">⚡</span> Sync Now';
            log.innerHTML += '<div style="color:#f87171;">⚠ Network error</div>';
        });
};
})();
</script>
<style>@keyframes spin{to{transform:rotate(360deg);}}</style>

