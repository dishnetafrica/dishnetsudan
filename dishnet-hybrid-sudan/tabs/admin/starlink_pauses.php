<?php
// ─────────────────────────────────────────────────────────────────────────────
// Tab: starlink_pauses   (admin + accountant + support_leader)  →  rendered as "Starlink Block Manager"
// Roles: admin = full access (block + unblock + bulk actions)
//        accountant / support_leader = view + unblock only (no block buttons)
// Added in v4.21.26, expanded to full operator UI in v4.21.27.
//
// Purpose: full operator UI for the data-report-backed Starlink block flow.
// Replaces the Chrome console workflow with point-and-click for:
//   - Auditing UCRM-suspended Starlink customers
//   - Blocking individual or all blockable customers
//   - Viewing currently-paused dishes (live from data-report)
//   - Unblocking customers (when they pay)
//   - Listing customers needing KIT serial added to UCRM service title
//   - Retrying offline dishes from a previous block attempt
//
// All API calls happen in the admin's browser session via JavaScript fetches:
//   - Hybrid endpoint sl_audit_suspended (calls UCRM live + extracts KITs)
//   - Data Report endpoints dr_wifi_lookup_by_kit, dr_wifi_test_block,
//     dr_wifi_test_unblock, dr_wifi_test_block_status, dr_wifi_lookup
//
// Server-side PHP just renders the HTML/CSS/JS shell. No PDO writes here.
// Cache-busting + cache:'no-store' on every cross-plugin fetch (UCRM service
// worker workaround — see SAFETY v4.21.26 lesson).
//
// REQUIRES:
//   - DishNet Hybrid v4.21.24+ (sl_audit_suspended endpoint)
//   - DishNet Data Report v2.8.57+ (dr_wifi_lookup_by_kit endpoint)
//
// SAFETY:
//   - Read-only by default; every action requires a confirm modal.
//   - Admin-only — gated upstream.
//   - VIP guard runs server-side in the audit endpoint AND inside data-
//     report's test_block (defense in depth).
// ─────────────────────────────────────────────────────────────────────────────

// Role-aware access: admin = full; accountant/support_leader = view+unblock only
$_sbmRole      = $retailer['role'] ?? '';
$_sbmCanBlock  = $isAdmin; // only admin can trigger new blocks / bulk actions
$_sbmCanView   = $isAdmin || in_array($_sbmRole, ['accountant', 'support_leader'], true);

if (!$_sbmCanView) {
    echo '<div style="padding:14px;border-radius:10px;background:#FFEBEE;color:#c0392b;font-weight:600;">Access restricted — contact admin.</div>';
    return;
}
?>
<style>
.sbm-wrap { padding: 16px 20px; max-width: 1400px; }
.sbm-h1 { font-size: 20px; font-weight: 800; margin: 0 0 4px; color: #1e293b; }
.sbm-sub { font-size: 12px; color: #64748b; margin-bottom: 16px; }
.sbm-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; }
.sbm-card-h { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px; }
.sbm-card-t { font-size: 14px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px; }
.sbm-card-c { font-size: 12px; color: #64748b; }
.sbm-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
.sbm-pill-blockable { background: #FFEBEE; color: #c0392b; }
.sbm-pill-vip { background: #E8F5E9; color: #166534; }
.sbm-pill-noki { background: #F1F5F9; color: #64748b; }
.sbm-pill-paused { background: #FFF3E0; color: #E65100; }
.sbm-pill-rename { background: #FFFBEB; color: #B45309; }
.sbm-pill-full   { background: #FEE2E2; color: #991B1B; }
.sbm-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.sbm-btn { padding: 7px 14px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; cursor: pointer; font-weight: 600; font-size: 12px; color: #1e293b; }
.sbm-btn:hover:not(:disabled) { background: #f1f5f9; }
.sbm-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.sbm-btn-primary { background: #1565C0; color: #fff; border-color: #1565C0; }
.sbm-btn-primary:hover:not(:disabled) { background: #0d47a1; }
.sbm-btn-danger { background: #D41C1C; color: #fff; border-color: #D41C1C; }
.sbm-btn-danger:hover:not(:disabled) { background: #b71c1c; }
.sbm-btn-warn { background: #fff; color: #c0392b; border-color: #f5b7b1; }
.sbm-btn-warn:hover:not(:disabled) { background: #FFEBEE; }
.sbm-btn-sm { padding: 5px 10px; font-size: 11px; }
.sbm-summary { display: flex; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.sbm-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; min-width: 140px; }
.sbm-stat-l { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.sbm-stat-v { font-size: 22px; font-weight: 800; color: #1e293b; margin-top: 2px; }
.sbm-stat-blockable .sbm-stat-v { color: #D41C1C; }
.sbm-stat-paused .sbm-stat-v { color: #E65100; }
.sbm-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.sbm-tbl th { background: #f8fafc; padding: 9px 10px; text-align: left; font-weight: 700; color: #475569; border-bottom: 1px solid #e2e8f0; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.4px; }
.sbm-tbl td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.sbm-tbl tr:last-child td { border-bottom: none; }
.sbm-tbl tr:hover td { background: #fafbfc; }
.sbm-tbl tr.sbm-row-blockable td:first-child { border-left: 3px solid #D41C1C; }
.sbm-tbl tr.sbm-row-vip td:first-child { border-left: 3px solid #16a34a; }
.sbm-tbl tr.sbm-row-noki td:first-child { border-left: 3px solid #cbd5e1; }
.sbm-tbl tr.sbm-row-blocked td:first-child { border-left: 3px solid #E65100; }
.sbm-tbl tr.sbm-progress-row { background: #FFF8E1 !important; }
.sbm-router { font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 10.5px; color: #64748b; word-break: break-all; }
.sbm-mono { font-family: 'SF Mono', Monaco, Consolas, monospace; font-size: 11px; }
.sbm-name { font-weight: 600; color: #1e293b; }
.sbm-name-sub { font-size: 11px; color: #64748b; margin-top: 2px; }
.sbm-bal-neg { color: #c0392b; font-weight: 600; font-family: 'SF Mono', Monaco, Consolas, monospace; }
.sbm-empty { padding: 32px; text-align: center; color: #64748b; background: #f8fafc; border-radius: 10px; border: 1px dashed #cbd5e1; }
.sbm-loading { padding: 24px; text-align: center; color: #64748b; }
.sbm-banner { padding: 10px 14px; border-radius: 8px; margin-bottom: 12px; font-size: 12px; font-weight: 600; }
.sbm-banner-info { background: #E3F2FD; color: #1565C0; }
.sbm-banner-warn { background: #FFF3E0; color: #E65100; }
.sbm-banner-error { background: #FFEBEE; color: #c0392b; }
.sbm-banner-ok { background: #E8F5E9; color: #166534; }
.sbm-checkbox-cell { width: 28px; }
.sbm-status-cell { width: 130px; }
.sbm-action-cell { width: 100px; text-align: right; }
.sbm-section-toggle { cursor: pointer; user-select: none; color: #1565C0; font-weight: 600; font-size: 12px; }
.sbm-section-toggle:hover { text-decoration: underline; }
.sbm-collapsed { display: none; }
.sbm-modal-back { position: fixed; inset: 0; background: rgba(15,23,42,.6); display: none; align-items: center; justify-content: center; z-index: 9999; }
.sbm-modal { background: #fff; border-radius: 14px; padding: 24px; max-width: 540px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,.3); max-height: 90vh; overflow-y: auto; }
.sbm-modal-h { font-size: 18px; font-weight: 800; margin: 0 0 10px; color: #1e293b; }
.sbm-modal-body { font-size: 13px; color: #475569; line-height: 1.55; margin-bottom: 18px; }
.sbm-modal-list { background: #f8fafc; border-radius: 8px; padding: 10px 14px; max-height: 280px; overflow-y: auto; font-size: 12px; margin: 12px 0; }
.sbm-modal-list ul { margin: 0; padding-left: 18px; }
.sbm-modal-actions { display: flex; gap: 8px; justify-content: flex-end; }
</style>

<div class="sbm-wrap">
  <div class="sbm-h1">Starlink Block Manager</div>
  <div class="sbm-sub"><strong>View-only.</strong> Auto-block runs every 2 hours from Data Report — blocks suspended Starlink customers (mode: full) and unblocks ones who paid. Failed blocks (offline dish) auto-retry next cycle. Manual <strong>Unblock</strong> still available below for immediate restore on payment confirmation.</div>

  <div id="sbmBanner"></div>

  <div class="sbm-summary" id="sbmSummary"></div>

  <!-- Card 1: Suspended Customers (audit + block) -->
  <div class="sbm-card">
    <div class="sbm-card-h">
      <div class="sbm-card-t"><span>🛑</span> Suspended Customers (UCRM Live)</div>
      <div class="sbm-toolbar">
        <button class="sbm-btn" id="sbmRefreshAudit">⟳ Refresh</button>
        <!-- v4.21.100: bulk block buttons removed - auto-block handles this from Data Report.
             View-only display. Per-row Unblock + Test buttons remain for manual override. -->
        <span style="font-size:12px;color:#64748b;font-style:italic;padding:6px 10px;">
          🤖 Auto-block runs every 2h from Data Report
        </span>
      </div>
    </div>
    <div class="sbm-card-c" id="sbmAuditMeta">Loading…</div>
    <div id="sbmAuditTable" style="margin-top:12px;">
      <div class="sbm-loading">Fetching live UCRM data…</div>
    </div>
  </div>

  <!-- Card 2: Currently Paused (data-report state) -->
  <div class="sbm-card">
    <div class="sbm-card-h">
      <div class="sbm-card-t"><span>⏸</span> Currently Paused Dishes (Data Report Live)</div>
      <div class="sbm-toolbar">
        <button class="sbm-btn" id="sbmRefreshPaused">⟳ Refresh</button>
        <button class="sbm-btn" id="sbmEnrichPaused">Enrich with customer info</button>
      </div>
    </div>
    <div class="sbm-card-c">Source of truth — actual gRPC pause state on each dish, regardless of whether the pause was initiated here or via Data Report's WiFi tab.</div>
    <div id="sbmPausedTable" style="margin-top:12px;">
      <div class="sbm-loading">Fetching paused dishes from data-report…</div>
    </div>
  </div>

  <!-- Card 2.5: Auto-Pause Cron Health — is the every-10-min cron actually running? -->
  <div class="sbm-card" id="sbmCronCard">
    <div class="sbm-card-h">
      <div class="sbm-card-t"><span>⏱</span> Auto-Pause Cron Health</div>
      <div class="sbm-toolbar">
        <button class="sbm-btn" id="sbmRunCronNow" title="Manually fire the extension cron logic now (same as data-report's Extend Pauses button) — useful to verify the cron path works end-to-end without waiting 10 minutes.">▶ Run Now</button>
        <button class="sbm-btn" id="sbmRefreshCron">⟳ Refresh</button>
      </div>
    </div>
    <div class="sbm-card-c">Pause-only blocks are leaky — only devices connected at block time get paused. Data Report's <code>cron_test_block_extend.php</code> runs every 10 minutes to catch new devices that connect later and pause them. This panel shows whether that cron is actually firing on schedule.</div>
    <div id="sbmCronTable" style="margin-top:12px;">
      <div class="sbm-loading">Loading cron health…</div>
    </div>
  </div>

  <!-- Card 2.6: Full Reconciliation — runs as Phase 4 inside data-report's force-run -->
  <div class="sbm-card" id="sbmReconcileCard">
    <div class="sbm-card-h">
      <div class="sbm-card-t"><span>🔁</span> Full Reconciliation (in Force Sync)</div>
      <div class="sbm-toolbar">
        <a class="sbm-btn" id="sbmForceSyncOpen" href="#" target="_top">▶ Open Force Sync</a>
      </div>
    </div>
    <div class="sbm-card-c">Reconciliation runs automatically inside data-report's force-sync cron (Phase 4): extends pauses, blocks every UCRM-suspended Starlink customer not yet paused, restores every paused customer now active in UCRM. Same single source of truth as everything else dish-related. Open the Force Sync page to watch live or fire it manually — Phase 4 runs at the end after the Starlink/usage/invoice phases finish.</div>
  </div>

  <!-- Card 3: Cleanup needed (KIT not in title) — appears only if there's something to clean up -->
  <div class="sbm-card" id="sbmCleanupCard" style="display:none;">
    <div class="sbm-card-h">
      <div class="sbm-card-t" id="sbmCleanupTitle"><span>🛠</span> Cleanup Needed</div>
      <div class="sbm-toolbar">
        <span class="sbm-section-toggle" id="sbmCleanupToggle">Show</span>
      </div>
    </div>
    <div class="sbm-card-c">These customers are suspended in UCRM but their service title doesn't contain a KIT serial, so the audit can't find their dish to block. Update each UCRM service title to the format <span class="sbm-mono">"Site : Customer Name (KITxxxxxxxx) : ..."</span> like the others, then refresh this page.</div>
    <div id="sbmCleanupTable" class="sbm-collapsed" style="margin-top:12px;"></div>
  </div>

  <!-- Card 4: Stuck Payments — paid but still paused (auto-restore failed/missed) -->
  <div class="sbm-card" id="sbmStuckCard">
    <div class="sbm-card-h">
      <div class="sbm-card-t" id="sbmStuckTitle"><span>⚠️</span> Stuck Payments — Paid but Still Paused</div>
      <div class="sbm-toolbar">
        <select class="sbm-btn" id="sbmStuckLookback" style="padding:6px 10px;">
          <option value="24">Last 24h</option>
          <option value="48">Last 48h</option>
          <option value="168" selected>Last 7 days</option>
        </select>
        <button class="sbm-btn" id="sbmRefreshStuck">⟳ Refresh</button>
      </div>
    </div>
    <div class="sbm-card-c">Customers who made a payment in the selected window but whose dish is still paused. If this list is empty, every recent payment auto-restored cleanly. If it has entries, those customers need a manual unblock — click "Unblock" on each row.</div>
    <div id="sbmStuckTable" style="margin-top:12px;">
      <div class="sbm-loading">Click Refresh to check for stuck payments…</div>
    </div>
  </div>

  <!-- Card 5: Bridge Activity Log — every auto-block / auto-restore the bridge has done -->
  <div class="sbm-card" id="sbmBridgeCard">
    <div class="sbm-card-h">
      <div class="sbm-card-t"><span>📋</span> Bridge Activity Log</div>
      <div class="sbm-toolbar">
        <select class="sbm-btn" id="sbmBridgeFilterKind" style="padding:6px 10px;">
          <option value="">All kinds</option>
          <option value="suspend">Suspends only</option>
          <option value="restore">Restores only</option>
        </select>
        <select class="sbm-btn" id="sbmBridgeFilterStatus" style="padding:6px 10px;">
          <option value="">All outcomes</option>
          <option value="failed">Failed only</option>
        </select>
        <button class="sbm-btn" id="sbmRefreshBridge">⟳ Refresh</button>
      </div>
    </div>
    <div class="sbm-card-c">Every webhook-triggered auto-block / auto-restore the bridge has executed. Latest first. Tells you exactly when UCRM payment events fired and what the bridge did about each.</div>
    <div id="sbmBridgeTable" style="margin-top:12px;">
      <div class="sbm-loading">Click Refresh to load bridge activity…</div>
    </div>
  </div>

</div>

<!-- Confirm modal -->
<div class="sbm-modal-back" id="sbmModalBack">
  <div class="sbm-modal">
    <div class="sbm-modal-h" id="sbmModalH">Confirm</div>
    <div class="sbm-modal-body" id="sbmModalBody"></div>
    <div class="sbm-modal-actions">
      <button class="sbm-btn" id="sbmModalCancel">Cancel</button>
      <button class="sbm-btn sbm-btn-danger" id="sbmModalOk">Confirm</button>
    </div>
  </div>
</div>

<script>
(function () {
  const HYB_API = '<?= htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/crm/_plugins/dishnet-hybrid-telecom/public.php') ?>?page=api&action=';
  const DR_BASE  = '/crm/_plugins/dishnet-data-report/public.php?action=';
  const canBlock = <?= $_sbmCanBlock ? 'true' : 'false' ?>; // false for accountant/support_leader

  const S = {
    audit: null,
    paused: [],
    pausedEnrichment: {},
    selected: new Set(),
    lastBlockResults: [],
  };

  // ── Helpers ──
  async function hybGet(action) {
    const r = await fetch(HYB_API + action + '&_cb=' + Date.now(), {
      method: 'GET', credentials: 'include', cache: 'no-store',
      headers: { 'Accept': 'application/json' },
    });
    if (!r.ok) throw new Error('Hybrid ' + action + ' HTTP ' + r.status);
    const j = await r.json();
    if (j.status !== 'success') throw new Error(j.message || 'API error');
    return j.data;
  }

  async function drGet(action, params) {
    let url = DR_BASE + action + '&_cb=' + Date.now();
    if (params) for (const [k, v] of Object.entries(params)) {
      url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
    }
    let r;
    try {
      r = await fetch(url, { method: 'GET', credentials: 'include', cache: 'no-store',
                             headers: { 'Accept': 'application/json' } });
    } catch (e) {
      throw new Error('Data Report network error: ' + e.message + ' (is data-report v2.8.57+ deployed?)');
    }
    const txt = await r.text();
    let j; try { j = JSON.parse(txt); } catch (_) { j = null; }
    if (!j) throw new Error('Data Report ' + action + ': non-JSON (HTTP ' + r.status + ')');
    return j;
  }

  async function drPost(action, body) {
    let r;
    try {
      r = await fetch(DR_BASE + action + '&_cb=' + Date.now(), {
        method: 'POST', credentials: 'include', cache: 'no-store',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
      });
    } catch (e) {
      throw new Error('Data Report network error: ' + e.message);
    }
    const txt = await r.text();
    let j; try { j = JSON.parse(txt); } catch (_) { j = null; }
    if (!j) throw new Error('Data Report ' + action + ': non-JSON (HTTP ' + r.status + ')');
    return j;
  }

  function sleep(ms) { return new Promise((res) => setTimeout(res, ms)); }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function fmtBal(n) { const v = Number(n) || 0; return (v < 0 ? '-' : '') + '$' + Math.abs(v).toFixed(2); }
  function fmtRelative(ts) {
    if (!ts) return '';
    const t = new Date(ts).getTime();
    if (isNaN(t)) return ts;
    const sec = Math.round((Date.now() - t) / 1000);
    if (sec < 60) return sec + 's ago';
    if (sec < 3600) return Math.round(sec / 60) + 'm ago';
    if (sec < 86400) return Math.round(sec / 3600) + 'h ago';
    return Math.round(sec / 86400) + 'd ago';
  }

  function setBanner(kind, msg, ttl) {
    const el = document.getElementById('sbmBanner');
    if (!msg) { el.innerHTML = ''; return; }
    el.innerHTML = '<div class="sbm-banner sbm-banner-' + kind + '">' + msg + '</div>';
    if (ttl) setTimeout(() => { if (el.innerHTML.indexOf(msg) >= 0) el.innerHTML = ''; }, ttl);
  }

  function modalConfirm(opts) {
    return new Promise((resolve) => {
      const back = document.getElementById('sbmModalBack');
      document.getElementById('sbmModalH').textContent = opts.title || 'Confirm';
      document.getElementById('sbmModalBody').innerHTML = opts.body || '';
      const okBtn = document.getElementById('sbmModalOk');
      const cancelBtn = document.getElementById('sbmModalCancel');
      okBtn.textContent = opts.okLabel || 'Confirm';
      okBtn.className = 'sbm-btn ' + (opts.danger === false ? 'sbm-btn-primary' : 'sbm-btn-danger');
      back.style.display = 'flex';
      const cleanup = () => {
        back.style.display = 'none';
        okBtn.removeEventListener('click', onOk);
        cancelBtn.removeEventListener('click', onCancel);
      };
      const onOk = () => { cleanup(); resolve(true); };
      const onCancel = () => { cleanup(); resolve(false); };
      okBtn.addEventListener('click', onOk);
      cancelBtn.addEventListener('click', onCancel);
    });
  }

  // ── Summary stats ──
  function renderSummary() {
    const a = S.audit ? S.audit.totals : null;
    const pausedCount = S.paused ? S.paused.length : 0;
    const stats = [];
    if (a) {
      stats.push(['Suspended Starlink', a.suspended_total, '']);
      stats.push(['✅ Blockable', a.blockable, 'sbm-stat-blockable']);
      stats.push(['🛡 VIP-protected', a.vip_skip, '']);
      stats.push(['Already paused', a.already_blocked, '']);
      // v4.21.59 — surfaces customers Starlink will cut within 7 days
      if (a.sl_cliff_imminent && a.sl_cliff_imminent > 0) {
        stats.push(['⏰ Cliff ≤7 days', a.sl_cliff_imminent, 'sbm-stat-blockable']);
      }
      if (a.non_starlink_skipped > 0) {
        stats.push(['Non-Starlink (FTTH/LTE) skipped', a.non_starlink_skipped, '']);
      }
    }
    stats.push(['⏸ Paused dishes', pausedCount, 'sbm-stat-paused']);
    document.getElementById('sbmSummary').innerHTML = stats.map(function (row) {
      const l = row[0], v = row[1], cls = row[2];
      return '<div class="sbm-stat ' + (cls || '') + '"><div class="sbm-stat-l">' + l + '</div><div class="sbm-stat-v">' + v + '</div></div>';
    }).join('');
  }

  // ── Audit panel ──
  async function loadAudit() {
    document.getElementById('sbmAuditTable').innerHTML = '<div class="sbm-loading">Fetching live UCRM data…</div>';
    document.getElementById('sbmAuditMeta').textContent = 'Loading…';
    try {
      const data = await hybGet('sl_audit_suspended');
      S.audit = data;
      S.selected.clear();
      renderSummary();
      renderAudit();
      renderCleanup();
      updateButtons();
      const t = data.totals;
      const nonStarlinkNote = t.non_starlink_skipped > 0
        ? ' · ' + t.non_starlink_skipped + ' FTTH/LTE skipped'
        : '';
      // v4.21.59 — flag if SL svc cache is missing or stale
      let cacheNote = '';
      if (data.sl_svc_cache) {
        if (!data.sl_svc_cache.loaded) {
          cacheNote = ' · ⚠️ SL cliff data unavailable (data-report sl_svc_cache.json missing)';
        } else if (data.sl_svc_cache.stale) {
          cacheNote = ' · ⚠️ SL cliff data >2h old';
        }
      }
      document.getElementById('sbmAuditMeta').textContent =
        t.suspended_total + ' Starlink customers suspended in UCRM · ' + t.blockable + ' ready to block · ' +
        t.vip_skip + ' VIP-protected · ' + t.already_blocked + ' already paused' + nonStarlinkNote + cacheNote + '.';
    } catch (e) {
      setBanner('error', '❌ Failed to load audit: ' + esc(e.message));
      document.getElementById('sbmAuditTable').innerHTML =
        '<div class="sbm-empty">Audit failed. ' + esc(e.message) + '</div>';
    }
  }

  function renderAudit() {
    const host = document.getElementById('sbmAuditTable');
    const rows = (S.audit && S.audit.rows) ? S.audit.rows : [];
    if (rows.length === 0) {
      host.innerHTML = '<div class="sbm-empty">✓ No suspended customers in UCRM right now.</div>';
      return;
    }
    let html = '<table class="sbm-tbl"><thead><tr>'
      + '<th class="sbm-checkbox-cell"><input type="checkbox" id="sbmSelectAll"></th>'
      + '<th>Customer</th>'
      + '<th>Phone</th>'
      + '<th>Plan</th>'
      + '<th>Balance</th>'
      + '<th>KIT</th>'
      + '<th title="Days until Starlink terminates service. From subscription endDate in data-report cache.">SL Cliff</th>'
      + '<th class="sbm-status-cell">Status</th>'
      + '<th class="sbm-action-cell">Action</th>'
      + '</tr></thead><tbody>';
    for (const r of rows) {
      let rowClass = '';
      let statusPill = '';
      if (r.blockable) { rowClass = 'sbm-row-blockable'; statusPill = '<span class="sbm-pill sbm-pill-blockable">Ready to block</span>'; }
      else if (r.already_blocked) { rowClass = 'sbm-row-blocked'; statusPill = '<span class="sbm-pill sbm-pill-paused">Already paused</span>'; }
      else if (r.is_vip) { rowClass = 'sbm-row-vip'; statusPill = '<span class="sbm-pill sbm-pill-vip">🛡 VIP</span>'; }
      else { rowClass = 'sbm-row-noki'; statusPill = '<span class="sbm-pill sbm-pill-noki">No KIT in title</span>'; }

      // ── v4.21.59 — SL cliff column ─────────────────────────────────────
      // Days-remaining bucket drives both color and label:
      //   ≤0          → red    "Ended (X days ago)"  — Starlink already cut
      //   1–7         → red    "X days"              — phone calls today
      //   8–14        → amber  "X days"              — schedule outreach
      //   15+         → grey   "X days"              — informational
      //   no data     → "—"                          — no SL cache row
      let cliffCell = '<span style="color:#cbd5e1;">—</span>';
      if (r.sl_days_until_cliff !== null && r.sl_days_until_cliff !== undefined) {
        const d = r.sl_days_until_cliff;
        const dt = (r.sl_end_date || '').slice(0, 10);
        let color = '#94a3b8'; let weight = '500';
        let label = d + ' days';
        if (d <= 0)        { color = '#b91c1c'; weight = '700'; label = 'Ended ' + Math.abs(d) + 'd ago'; }
        else if (d <= 7)   { color = '#dc2626'; weight = '700'; label = d + ' day' + (d === 1 ? '' : 's'); }
        else if (d <= 14)  { color = '#d97706'; weight = '700'; label = d + ' days'; }
        cliffCell = '<div style="color:' + color + ';font-weight:' + weight + ';font-size:13px;">' + label + '</div>'
                  + '<div style="color:#94a3b8;font-size:10px;">' + dt + '</div>';
      }

      const checkbox = r.blockable
        ? '<input type="checkbox" class="sbm-row-check" data-cid="' + r.client_id + '"' + (S.selected.has(r.client_id) ? ' checked' : '') + '>'
        : '';
      const kitCell = (r.kit_serials && r.kit_serials.length)
        ? '<span class="sbm-mono">' + esc(r.kit_serials[0]) + '</span>' + (r.kit_serials.length > 1 ? ' <span style="color:#94a3b8;">+' + (r.kit_serials.length - 1) + '</span>' : '')
        : '<span style="color:#cbd5e1;">—</span>';
      // Primary action button (Block / Unblock based on state) + always-available
      // "🧪 Test" link that fires the bridge synchronously to verify it works.
      // v4.21.100: per-row Block button removed - auto-block handles this from Data Report.
      // Unblock button stays for manual override (admin can unblock immediately on payment).
      const primaryBtn = r.blockable
        ? '<span style="font-size:11px;color:#92400E;background:#FEF3C7;padding:3px 8px;border-radius:4px;">⏳ Auto-block due</span>'
        : (r.already_blocked
          ? '<button class="sbm-btn sbm-btn-sm sbm-btn-warn sbm-unblock-one" data-cid="' + r.client_id + '">Unblock</button>'
          : '');
      const testBtn = (r.kit_count > 0 && !r.is_vip)
        ? '<button class="sbm-btn sbm-btn-sm sbm-test-bridge" data-cid="' + r.client_id + '" data-kind="' + (r.already_blocked ? 'restore' : 'suspend') + '" '
          + 'title="Synthetic bridge call — same code path as a real webhook. Verifies the auto-' + (r.already_blocked ? 'restore' : 'block') + ' chain works.">🧪 Test</button>'
        : '';
      const actionCell = primaryBtn + (primaryBtn && testBtn ? ' ' : '') + testBtn;

      html += '<tr class="' + rowClass + '" data-cid="' + r.client_id + '">'
        + '<td class="sbm-checkbox-cell">' + checkbox + '</td>'
        + '<td><div class="sbm-name">' + esc(r.name) + '</div><div class="sbm-name-sub">CRM #' + r.client_id + '</div></td>'
        + '<td><span class="sbm-mono">' + esc(r.phone || '—') + '</span></td>'
        + '<td>' + esc(r.plans || '—') + '</td>'
        + '<td><span class="sbm-bal-neg">' + esc(fmtBal(r.balance)) + '</span></td>'
        + '<td>' + kitCell + '</td>'
        + '<td>' + cliffCell + '</td>'
        + '<td>' + statusPill + '</td>'
        + '<td class="sbm-action-cell">' + actionCell + '</td>'
        + '</tr>';
    }
    html += '</tbody></table>';
    host.innerHTML = html;

    document.getElementById('sbmSelectAll').addEventListener('change', (e) => {
      const checked = e.target.checked;
      document.querySelectorAll('.sbm-row-check').forEach((cb) => {
        cb.checked = checked;
        const cid = parseInt(cb.dataset.cid, 10);
        if (checked) S.selected.add(cid); else S.selected.delete(cid);
      });
      updateButtons();
    });
    document.querySelectorAll('.sbm-row-check').forEach((cb) => {
      cb.addEventListener('change', () => {
        const cid = parseInt(cb.dataset.cid, 10);
        if (cb.checked) S.selected.add(cid); else S.selected.delete(cid);
        updateButtons();
      });
    });
    document.querySelectorAll('.sbm-block-one').forEach((btn) => {
      btn.addEventListener('click', () => blockOne(parseInt(btn.dataset.cid, 10), btn));
    });
    document.querySelectorAll('.sbm-unblock-one').forEach((btn) => {
      btn.addEventListener('click', () => unblockClient(parseInt(btn.dataset.cid, 10), btn));
    });
    document.querySelectorAll('.sbm-test-bridge').forEach((btn) => {
      btn.addEventListener('click', () => bridgeTest(parseInt(btn.dataset.cid, 10), btn.dataset.kind, btn));
    });
  }

  // ── Cleanup card ──
  function renderCleanup() {
    if (!S.audit) return;
    const noKit = (S.audit.rows || []).filter((r) => !r.is_vip && !r.already_blocked && r.kit_count === 0);
    const card = document.getElementById('sbmCleanupCard');
    if (noKit.length === 0) {
      card.style.display = 'none';
      return;
    }
    card.style.display = 'block';
    document.getElementById('sbmCleanupTitle').innerHTML =
      '<span>🛠</span> Cleanup Needed — ' + noKit.length + ' customer(s) without KIT in UCRM service title';
    let html = '<table class="sbm-tbl"><thead><tr>'
      + '<th>Customer</th><th>Phone</th><th>Balance</th><th>Current UCRM service title</th>'
      + '</tr></thead><tbody>';
    for (const r of noKit) {
      const svcTitle = (r.suspended_services || []).map((s) => s.name).join(' | ');
      html += '<tr>'
        + '<td><div class="sbm-name">' + esc(r.name) + '</div><div class="sbm-name-sub">CRM #' + r.client_id + '</div></td>'
        + '<td><span class="sbm-mono">' + esc(r.phone || '—') + '</span></td>'
        + '<td><span class="sbm-bal-neg">' + esc(fmtBal(r.balance)) + '</span></td>'
        + '<td><span class="sbm-router">' + esc(svcTitle) + '</span></td>'
        + '</tr>';
    }
    html += '</tbody></table>';
    document.getElementById('sbmCleanupTable').innerHTML = html;
  }

  document.getElementById('sbmCleanupToggle').addEventListener('click', (e) => {
    const t = document.getElementById('sbmCleanupTable');
    if (t.classList.contains('sbm-collapsed')) {
      t.classList.remove('sbm-collapsed');
      e.target.textContent = 'Hide';
    } else {
      t.classList.add('sbm-collapsed');
      e.target.textContent = 'Show';
    }
  });

  // ── Paused panel ──
  async function loadPaused() {
    document.getElementById('sbmPausedTable').innerHTML = '<div class="sbm-loading">Fetching paused dishes from data-report…</div>';
    try {
      const resp = await drGet('dr_wifi_test_block_status');
      if (!resp || !resp.ok) throw new Error('bad response');
      S.paused = resp.blocked || [];
      renderSummary();
      renderPaused();
    } catch (e) {
      setBanner('error', '❌ Failed to load paused list: ' + esc(e.message));
      document.getElementById('sbmPausedTable').innerHTML =
        '<div class="sbm-empty">Failed to load paused list. ' + esc(e.message) + '</div>';
    }
  }

  function renderPaused() {
    const host = document.getElementById('sbmPausedTable');
    if (!S.paused || S.paused.length === 0) {
      host.innerHTML = '<div class="sbm-empty">✓ No dishes currently paused.</div>';
      return;
    }
    let html = '<table class="sbm-tbl"><thead><tr>'
      + '<th>Customer / Router</th>'
      + '<th>Mode</th>'
      + '<th>Devices paused</th>'
      + '<th>Blocked</th>'
      + '<th>By</th>'
      + '<th class="sbm-action-cell">Action</th>'
      + '</tr></thead><tbody>';
    for (const r of S.paused) {
      const enr = S.pausedEnrichment[r.router_id] || {};
      const cust = enr.customer_name || enr.sl_nickname || '';
      const kit = enr.kit_serial || '';
      const acc = enr.account_number || '';
      const custCell = cust
        ? '<div class="sbm-name">' + esc(cust) + '</div>'
          + (kit ? '<div class="sbm-name-sub"><span class="sbm-mono">' + esc(kit) + '</span>' + (acc ? ' · ' + esc(acc) : '') + '</div>' : '')
          + '<div class="sbm-router">' + esc(r.router_id) + '</div>'
        : '<div class="sbm-router">' + esc(r.router_id) + '</div><div class="sbm-name-sub" style="color:#cbd5e1;">click "Enrich" to identify</div>';
      html += '<tr data-router="' + esc(r.router_id) + '">'
        + '<td>' + custCell + '</td>'
        + '<td><span class="sbm-pill ' + (r.mode === 'rename_only' ? 'sbm-pill-rename' : (r.mode === 'full' ? 'sbm-pill-full' : 'sbm-pill-paused')) + '">' + esc(r.mode || 'pause_only') + '</span></td>'
        + '<td>' + (r.paused_count || 0) + '</td>'
        + '<td>' + esc(fmtRelative(r.blocked_at)) + '<br><span style="color:#94a3b8;font-size:10px;">' + esc(r.blocked_at || '') + '</span></td>'
        + '<td><span style="font-size:11px;color:#64748b;">' + esc(r.blocked_by || '') + '</span></td>'
        + '<td class="sbm-action-cell">'
        + '<button class="sbm-btn sbm-btn-sm sbm-btn-warn sbm-unblock-router" data-router="' + esc(r.router_id) + '">Unblock</button>'
        + '</td></tr>';
    }
    html += '</tbody></table>';
    host.innerHTML = html;
    document.querySelectorAll('.sbm-unblock-router').forEach((btn) => {
      btn.addEventListener('click', () => unblockRouter(btn.dataset.router, btn));
    });
  }

  async function enrichPaused() {
    if (!S.paused || S.paused.length === 0) return;
    setBanner('info', 'Enriching ' + S.paused.length + ' router(s)…');
    let done = 0;
    const batchSize = 4;
    for (let i = 0; i < S.paused.length; i += batchSize) {
      const batch = S.paused.slice(i, i + batchSize);
      await Promise.all(batch.map(async (r) => {
        if (S.pausedEnrichment[r.router_id]) return;
        try {
          const j = await drGet('dr_wifi_lookup', { router_id: r.router_id });
          if (j && j.ok && j.found) {
            S.pausedEnrichment[r.router_id] = {
              customer_name: j.customer_name || '',
              sl_nickname: j.sl_nickname || '',
              kit_serial: j.kit_serial || '',
              account_number: j.account_number || '',
            };
          }
        } catch (e) { /* best effort */ }
        done++;
      }));
      renderPaused();
      setBanner('info', 'Enriching ' + done + '/' + S.paused.length + '…');
    }
    setBanner('ok', '✓ Enrichment complete.', 2000);
  }

  // ── Block / unblock ──
  async function blockClientImpl(row) {
    if (!row.kit_serials || row.kit_serials.length === 0) {
      return { ok: false, client_id: row.client_id, name: row.name, error: 'no KIT' };
    }
    try {
      let router = null;
      for (const kit of row.kit_serials) {
        const j = await drGet('dr_wifi_lookup_by_kit', { kit_serial: kit });
        if (j && j.ok && j.found) { router = j; break; }
      }
      if (!router) return { ok: false, client_id: row.client_id, name: row.name, error: 'KIT not in router map' };
      if (!router.session_active) return { ok: false, client_id: row.client_id, name: row.name, router_id: router.router_id, error: 'session inactive (cookies dead)' };
      const blockResp = await drPost('dr_wifi_test_block', {
        router_id: router.router_id, mode: 'rename_only', by: 'admin_block_manager_tab',
      });
      if (blockResp && blockResp.ok) {
        return { ok: true, client_id: row.client_id, name: row.name, router_id: router.router_id, kit_serial: router.kit_serial };
      }
      return { ok: false, client_id: row.client_id, name: row.name, router_id: router.router_id,
               error: (blockResp && blockResp.error) ? blockResp.error : 'unknown' };
    } catch (e) {
      return { ok: false, client_id: row.client_id, name: row.name, error: e.message };
    }
  }

  async function blockOne(clientId, btn) {
    const row = (S.audit.rows || []).find((r) => r.client_id === clientId);
    if (!row) return setBanner('error', '❌ Client not in current audit.');

    // v4.21.59 — cliff awareness in single-customer modal
    const d = row.sl_days_until_cliff;
    let cliffNote = '';
    if (d === null || d === undefined) {
      cliffNote = '<div style="background:#f1f5f9;border-radius:8px;padding:8px;font-size:12px;color:#475569;margin:10px 0;">No Starlink subscription cache row for this customer\'s KIT. Cliff timing unknown.</div>';
    } else if (d <= 0) {
      cliffNote = '<div style="background:#fef2f2;border-radius:8px;padding:8px;font-size:12px;color:#991b1b;margin:10px 0;"><b>⏹ Subscription already ended</b> ' + Math.abs(d) + ' day(s) ago. Block is safe — no paid time wasted.</div>';
    } else if (d <= 7) {
      cliffNote = '<div style="background:#f0fdf4;border-radius:8px;padding:8px;font-size:12px;color:#15803d;margin:10px 0;"><b>🟢 ' + d + ' day(s) left</b> on Starlink subscription. Cliff is imminent — good time to block.</div>';
    } else if (d <= 14) {
      cliffNote = '<div style="background:#fffbeb;border-radius:8px;padding:8px;font-size:12px;color:#92400e;margin:10px 0;"><b>🟡 ' + d + ' day(s) left</b> on Starlink subscription. Consider waiting until closer to cliff.</div>';
    } else {
      cliffNote = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;font-size:13px;color:#991b1b;margin:10px 0;">'
        + '<b>🔴 ' + d + ' day(s) left on Starlink subscription</b><br>'
        + 'You\'ve already paid Starlink for service through ' + ((row.sl_end_date || '').slice(0, 10)) + '.<br>'
        + 'Blocking now does not refund anything — just stops the customer\'s service early. Consider scheduling for closer to that date.'
        + '</div>';
    }

    const ok = await modalConfirm({
      title: 'Block ' + row.name + '?',
      body: 'This will pause Starlink internet for <b>' + esc(row.name) + '</b> (CRM #' + clientId + ', balance ' + fmtBal(row.balance) + ').<br>'
          + 'KIT: <span class="sbm-mono">' + esc((row.kit_serials || []).join(', ')) + '</span>'
          + cliffNote
          + 'Calls Data Report\'s test_block via gRPC. Dish must be online for the block to succeed.',
      okLabel: 'Block now',
    });
    if (!ok) return;
    btn.disabled = true; btn.textContent = '...';
    const result = await blockClientImpl(row);
    btn.disabled = false; btn.textContent = 'Block';
    if (result.ok) {
      setBanner('ok', '✓ ' + esc(row.name) + ' paused (router ' + esc(result.router_id) + ').', 4000);
      await loadPaused();
      await loadAudit();
    } else {
      setBanner('error', '❌ Block failed for ' + esc(row.name) + ': ' + esc(result.error));
    }
  }

  async function unblockClient(clientId, btn) {
    const row = (S.audit.rows || []).find((r) => r.client_id === clientId);
    if (!row) return setBanner('error', '❌ Client not in current audit.');
    const ok = await modalConfirm({
      title: 'Unblock ' + row.name + '?',
      body: 'This will restore Starlink internet for <b>' + esc(row.name) + '</b>.<br><br>'
          + '⚠️ Note: this only restores the dish. You still need to manually un-suspend the service in UCRM admin (set service status to active) for billing to resume.',
      okLabel: 'Unblock now', danger: false,
    });
    if (!ok) return;
    btn.disabled = true; btn.textContent = '...';
    try {
      let router = null;
      for (const kit of (row.kit_serials || [])) {
        const j = await drGet('dr_wifi_lookup_by_kit', { kit_serial: kit });
        if (j && j.ok && j.found) { router = j; break; }
      }
      if (!router) {
        setBanner('error', '❌ Router not found for ' + esc(row.name));
        btn.disabled = false; btn.textContent = 'Unblock';
        return;
      }
      const r = await drPost('dr_wifi_test_unblock', { router_id: router.router_id, by: 'admin_block_manager_tab' });
      if (r && r.ok) {
        setBanner('ok', '✓ Unblocked ' + esc(row.name), 4000);
        await loadPaused();
        await loadAudit();
      } else {
        setBanner('error', '❌ Unblock failed: ' + esc((r && r.error) || 'no response'));
        btn.disabled = false; btn.textContent = 'Unblock';
      }
    } catch (e) {
      setBanner('error', '❌ Unblock crashed: ' + esc(e.message));
      btn.disabled = false; btn.textContent = 'Unblock';
    }
  }

  // ── Bridge test: synthetically fire suspend or restore for one customer ──
  // Hits sl_bridge_test_suspend / sl_bridge_test_restore which call the same
  // StarlinkBlockBridge methods that webhook.php uses for service.suspend /
  // payment.add. Lets you verify the auto-block / auto-restore chain works
  // end-to-end on a specific customer WITHOUT a real UCRM event.
  async function bridgeTest(clientId, kind, btn) {
    const row = (S.audit.rows || []).find((r) => r.client_id === clientId);
    if (!row) return setBanner('error', '❌ Client not in current audit.');
    const action = kind === 'restore' ? 'sl_bridge_test_restore' : 'sl_bridge_test_suspend';
    const verbCap = kind === 'restore' ? 'Restore' : 'Suspend';
    const verb = verbCap.toLowerCase();

    const ok = await modalConfirm({
      title: '🧪 Test bridge ' + verb + '?',
      body: 'This will fire <code>' + esc(action) + '</code> for <b>' + esc(row.name) + '</b> '
        + '(CRM #' + clientId + ').<br><br>'
        + '<b>Same code path as the real webhook</b> — calls <code>StarlinkBlockBridge::'
        + (kind === 'restore' ? 'restoreClient' : 'suspendClient') + '()</code> with a synthetic '
        + 'trigger tag (<code>ui:manual_test_' + verb + '</code>). Result is shown inline; the call '
        + 'is also logged to the Bridge Activity panel below.<br><br>'
        + (kind === 'restore'
            ? 'Use this to verify auto-restore-on-payment works without making a real payment in UCRM.'
            : 'Use this to verify auto-block-on-suspend works without actually suspending the customer.'),
      okLabel: 'Fire ' + verb + ' now',
      danger: kind !== 'restore',
    });
    if (!ok) return;
    btn.disabled = true;
    const origText = btn.textContent;
    btn.textContent = '...';

    try {
      const resp = await fetch(HYB_API + action + '&_cb=' + Date.now(), {
        method: 'POST', credentials: 'include', cache: 'no-store',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ client_id: clientId }),
      });
      const j = await resp.json();
      if (!resp.ok || j.status !== 'success') {
        const errMsg = (j && j.message) || ('HTTP ' + resp.status);
        showBridgeResultModal(false, kind, row, { error: errMsg });
      } else {
        showBridgeResultModal(true, kind, row, j.data);
        // Refresh audit + paused to pick up state change
        setTimeout(() => { loadAudit(); loadPaused(); loadBridge(); }, 800);
      }
    } catch (e) {
      showBridgeResultModal(false, kind, row, { error: e.message });
    } finally {
      btn.disabled = false;
      btn.textContent = origText;
    }
  }

  // Render the bridge-test result in the same confirm-modal style for visibility.
  function showBridgeResultModal(ok, kind, row, data) {
    const back = document.getElementById('sbmModalBack');
    const okSym = ok && data.ok ? '✅' : '❌';
    const verbCap = kind === 'restore' ? 'Restore' : 'Suspend';
    document.getElementById('sbmModalH').textContent = okSym + ' Bridge ' + verbCap.toLowerCase() + ' result';

    let body = '<b>' + esc(row.name) + '</b> · CRM #' + row.client_id + '<br>'
      + '<span style="font-size:11px;color:#64748b;">Trigger: <code>' + esc(data.triggered_by || '') + '</code></span><br><br>';

    if (data.error) {
      body += '<div class="sbm-banner sbm-banner-error" style="margin:8px 0;">'
        + '<b>Error:</b> ' + esc(data.error) + '</div>';
    } else if (kind === 'restore') {
      body += 'Routers restored: <b>' + (data.routers_restored || 0) + '</b><br>'
        + 'Routers failed: <b>' + (data.routers_failed || 0) + '</b><br>';
      if (data.note) body += '<span style="color:#64748b;font-size:11px;">Note: ' + esc(data.note) + '</span><br>';
    } else {
      body += 'Routers blocked: <b>' + (data.routers_processed || 0) + '</b><br>'
        + 'Routers failed: <b>' + (data.routers_failed || 0) + '</b><br>';
      if (data.skipped_reason) body += '<span style="color:#64748b;font-size:11px;">Skipped reason: ' + esc(data.skipped_reason) + '</span><br>';
    }

    if (data.attempts && data.attempts.length) {
      body += '<div class="sbm-modal-list"><b>Per-router attempts:</b><ul style="margin-top:6px;">';
      for (const a of data.attempts) {
        const sym = a.ok ? '✅' : '❌';
        const router = a.router_id ? ' <span class="sbm-router">' + esc(a.router_id) + '</span>' : '';
        const kit = a.kit ? ' KIT <span class="sbm-mono">' + esc(a.kit) + '</span>' : '';
        const err = a.error ? ' — <span style="color:#c0392b;">' + esc(a.error) + '</span>' : '';
        const note = a.note ? ' <span style="color:#64748b;font-size:11px;">(' + esc(a.note) + ')</span>' : '';
        body += '<li>' + sym + kit + router + err + note + '</li>';
      }
      body += '</ul></div>';
    }

    // v4.21.35: when no_kits, show the diagnostic trace so we can see WHICH source failed and WHY.
    const isNoKits = (data.skipped_reason === 'no_kits') || (data.note === 'no_kits');
    if (isNoKits && data.resolve_diag) {
      const d = data.resolve_diag;
      body += '<div class="sbm-modal-list" style="background:#FEF3C7;"><b>🔍 KIT resolution diagnostic:</b><br>'
        + '<table style="font-size:11px;width:100%;margin-top:6px;">'
        + '<tr><td><b>Source A (sl_kits.json):</b></td><td>'
          + (d.src_a_present ? 'found at ' + esc(d.src_a_path || '?') + ' · ' + (d.src_a_entries || 0) + ' entries · ' + (d.src_a_matched || 0) + ' matched this client'
                              : 'file NOT found at expected paths')
        + '</td></tr>'
        + '<tr><td><b>Source B (UCRM regex):</b></td><td>'
          + (d.src_b_attempted
              ? (d.src_b_error
                  ? '<span style="color:#c0392b;">ERROR: ' + esc(d.src_b_error) + '</span>'
                    + (d.src_b_endpoint_tried ? '<br><span style="font-size:10px;color:#94a3b8;">Last endpoint tried: ' + esc(d.src_b_endpoint_tried) + '</span>' : '')
                  : 'URL: <span class="sbm-mono">' + esc(d.src_b_url || '(empty)') + '</span> · '
                    + 'AppKey: ' + (d.src_b_appkey_len ? d.src_b_appkey_len + ' chars' : '<span style="color:#c0392b;">EMPTY</span>') + ' · '
                    + (d.src_b_services_count || 0) + ' services · '
                    + (d.src_b_kits_extracted || 0) + ' KITs extracted via regex'
                    + (d.src_b_endpoint_tried ? '<br><span style="font-size:10px;color:#94a3b8;">Endpoint: ' + esc(d.src_b_endpoint_tried) + '</span>' : ''))
              : '<span style="color:#16a34a;">not attempted (Source A succeeded)</span>')
        + '</td></tr>'
        + '</table>'
        + '<div style="font-size:11px;color:#64748b;margin-top:8px;">'
        + 'If Source B says "URL empty" or "AppKey EMPTY," ucrm.json is missing or malformed. '
        + 'If it says "non-array (auth failure)," the plugin app key in ucrm.json doesn\'t work — regenerate via UCRM admin.'
        + '</div></div>';
    }

    if (data.plugin_version) {
      body += '<div style="font-size:10px;color:#cbd5e1;margin-top:6px;">Plugin version: v' + esc(data.plugin_version) + '</div>';
    }

    body += '<div style="font-size:11px;color:#64748b;margin-top:10px;">'
      + 'Same code path as <code>webhook.php</code> case '
      + (kind === 'restore' ? '<code>payment.add</code>' : '<code>service.suspend</code>') + '. '
      + 'This event was also written to the Bridge Activity panel.</div>';

    document.getElementById('sbmModalBody').innerHTML = body;
    const okBtn = document.getElementById('sbmModalOk');
    const cancelBtn = document.getElementById('sbmModalCancel');
    okBtn.textContent = 'Close';
    okBtn.className = 'sbm-btn sbm-btn-primary';
    cancelBtn.style.display = 'none';
    back.style.display = 'flex';
    const cleanup = () => {
      back.style.display = 'none';
      cancelBtn.style.display = '';
      okBtn.removeEventListener('click', onClose);
    };
    const onClose = () => cleanup();
    okBtn.addEventListener('click', onClose);
  }

  async function unblockRouter(routerId, btn) {
    const enr = S.pausedEnrichment[routerId] || {};
    const cust = enr.customer_name || routerId;
    const ok = await modalConfirm({
      title: 'Unblock dish?',
      body: 'Restore WiFi for <b>' + esc(cust) + '</b><br><span class="sbm-router">' + esc(routerId) + '</span>',
      okLabel: 'Unblock now', danger: false,
    });
    if (!ok) return;
    btn.disabled = true; btn.textContent = '...';
    try {
      const r = await drPost('dr_wifi_test_unblock', { router_id: routerId, by: 'admin_block_manager_tab' });
      if (r && r.ok) {
        setBanner('ok', '✓ Unblocked ' + esc(routerId), 4000);
        S.paused = S.paused.filter((p) => p.router_id !== routerId);
        renderSummary();
        renderPaused();
      } else {
        setBanner('error', '❌ Unblock failed: ' + esc((r && r.error) || 'no response'));
        btn.disabled = false; btn.textContent = 'Unblock';
      }
    } catch (e) {
      setBanner('error', '❌ Unblock crashed: ' + esc(e.message));
      btn.disabled = false; btn.textContent = 'Unblock';
    }
  }

  async function blockMany(rows, label) {
    if (rows.length === 0) return;

    // ── v4.21.59 — Cliff-aware confirmation ───────────────────────────────
    // Show days-until-cliff per row so admin knows which blocks would waste
    // paid Starlink time. Bucket counts at top: how many would be wasteful
    // (>7 days left) vs which ones are at the right time (≤7 days). If ANY
    // customer in the batch has >14 days left, modal turns red and asks for
    // re-confirmation — almost certainly not worth blocking that customer
    // today; queue them for a date close to their cliff instead.
    let bucketWasteful = 0;  // >14 days left — likely paid full month, lots of wasted time
    let bucketSoon     = 0;  // 8-14 days left — schedule outreach, not block today
    let bucketCliff    = 0;  // 1-7 days left — block today / phone now
    let bucketEnded    = 0;  // ≤0 days — Starlink already cut
    let bucketUnknown  = 0;  // no SL cache row
    let mostWastefulDays = 0;
    rows.forEach(r => {
      const d = r.sl_days_until_cliff;
      if (d === null || d === undefined)      bucketUnknown++;
      else if (d <= 0)                        bucketEnded++;
      else if (d <= 7)                        bucketCliff++;
      else if (d <= 14)                       bucketSoon++;
      else { bucketWasteful++; if (d > mostWastefulDays) mostWastefulDays = d; }
    });

    // Per-row list, sorted: ended first, then cliff (1-7), then soon, then
    // wasteful, then unknown. Wasteful ones get a 🔴 marker; soon ones 🟡.
    const sortedRows = rows.slice().sort((a, b) => {
      const da = a.sl_days_until_cliff;
      const db = b.sl_days_until_cliff;
      const rank = (d) => {
        if (d === null || d === undefined) return 4;     // unknown last
        if (d <= 0)                        return 0;     // ended first
        if (d <= 7)                        return 1;     // cliff next
        if (d <= 14)                       return 2;     // soon
        return 3;                                        // wasteful
      };
      return rank(da) - rank(db) || ((da || 999) - (db || 999));
    });

    const list = sortedRows.slice(0, 12).map(r => {
      const d = r.sl_days_until_cliff;
      let badge = '';
      if (d === null || d === undefined)   badge = '<span style="color:#94a3b8;">no SL data</span>';
      else if (d <= 0)                     badge = '<span style="color:#b91c1c;font-weight:700;">⏹ ended ' + Math.abs(d) + 'd ago</span>';
      else if (d <= 7)                     badge = '<span style="color:#dc2626;font-weight:700;">🟢 ' + d + 'd left — block now</span>';
      else if (d <= 14)                    badge = '<span style="color:#d97706;font-weight:700;">🟡 ' + d + 'd left — schedule</span>';
      else                                 badge = '<span style="color:#b91c1c;font-weight:700;">🔴 ' + d + 'd left — WASTEFUL</span>';
      return '<li>' + esc(r.name) + ' <span style="color:#94a3b8;">' + esc(fmtBal(r.balance)) + '</span> · ' + badge + '</li>';
    }).join('') + (sortedRows.length > 12 ? '<li><em>… and ' + (sortedRows.length - 12) + ' more</em></li>' : '');

    // Banner above the list summarizing buckets
    let banner = '';
    if (bucketWasteful > 0) {
      banner = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;margin-bottom:10px;color:#991b1b;font-size:13px;">'
             + '<b>⚠ Blocking ' + bucketWasteful + ' customer(s) with 15+ days left on their Starlink subscription.</b><br>'
             + 'They\'ve already been billed for service through then. Blocking now wastes the paid time. '
             + 'Consider scheduling these for closer to their cliff date instead.'
             + '</div>';
    } else if (bucketSoon > 0) {
      banner = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;margin-bottom:10px;color:#92400e;font-size:13px;">'
             + '⏰ ' + bucketSoon + ' customer(s) have 8–14 days remaining — consider waiting a few more days unless customer must be cut now.'
             + '</div>';
    } else if (bucketCliff > 0 && bucketWasteful === 0 && bucketSoon === 0) {
      banner = '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px;margin-bottom:10px;color:#15803d;font-size:13px;">'
             + '✓ All ' + bucketCliff + ' customer(s) are within their cliff window (≤7 days). Good time to block.'
             + '</div>';
    }

    // Bucket summary line
    const bucketSummary = '<div style="font-size:12px;color:#475569;margin-bottom:8px;">'
      + (bucketEnded    ? bucketEnded    + ' ended · ' : '')
      + (bucketCliff    ? bucketCliff    + ' at cliff · ' : '')
      + (bucketSoon     ? bucketSoon     + ' soon · '   : '')
      + (bucketWasteful ? bucketWasteful + ' wasteful · ' : '')
      + (bucketUnknown  ? bucketUnknown  + ' unknown · ' : '')
      + 'total ' + rows.length + '</div>';

    const ok = await modalConfirm({
      title: 'Block ' + rows.length + ' customer(s)?',
      body: 'This will pause Starlink for the following customers via Data Report\'s gRPC test_block:'
          + bucketSummary
          + banner
          + '<div class="sbm-modal-list"><ul>' + list + '</ul></div>'
          + 'Each customer\'s dish must be online for the block to succeed. Offline dishes can be retried later via "↻ Retry Offline".',
      okLabel: 'Block ' + rows.length + ' now',
    });
    if (!ok) return;

    // Second-confirm gate: if there are wasteful entries, require explicit
    // re-confirm with a stronger label so admin can't tap through reflexively.
    if (bucketWasteful > 0) {
      const doubleOk = await modalConfirm({
        title: '⚠ Confirm wasteful blocks',
        body: '<b>' + bucketWasteful + ' of these customers have 15+ days remaining on Starlink.</b><br><br>'
            + 'Their longest-paid one has <b>' + mostWastefulDays + ' days</b> left.<br><br>'
            + 'You\'ve already paid Starlink for that time. Blocking them today does not refund anything — '
            + 'it just stops their service early. Consider scheduling these blocks for closer to their cliff date.<br><br>'
            + 'Continue anyway?',
        okLabel: 'Yes, block them now',
      });
      if (!doubleOk) return;
    }
    // ── End cliff-aware confirmation ──────────────────────────────────────

    setBanner('info', 'Blocking ' + rows.length + ' customer(s)…');
    document.getElementById('sbmBlockSelected').disabled = true;
    document.getElementById('sbmBlockAll').disabled = true;

    const results = [];
    for (let i = 0; i < rows.length; i++) {
      const c = rows[i];
      const tr = document.querySelector('#sbmAuditTable tr[data-cid="' + c.client_id + '"]');
      if (tr) tr.classList.add('sbm-progress-row');
      setBanner('info', 'Blocking ' + (i + 1) + '/' + rows.length + ': ' + esc(c.name) + '…');
      const r = await blockClientImpl(c);
      results.push(r);
      if (tr) tr.classList.remove('sbm-progress-row');
      if (i < rows.length - 1) await sleep(2000);
    }

    S.lastBlockResults = results;
    document.getElementById('sbmRetryOffline').disabled = false;
    const okCount = results.filter((r) => r.ok).length;
    const failCount = results.length - okCount;
    setBanner(failCount === 0 ? 'ok' : 'warn',
      'Block ' + label + ' complete: ' + okCount + ' paused, ' + failCount + ' failed.'
      + (failCount > 0 ? ' Click "↻ Retry Offline" later for offline dishes.' : ''));
    await loadPaused();
    await loadAudit();
    document.getElementById('sbmBlockSelected').disabled = false;
    document.getElementById('sbmBlockAll').disabled = (S.audit.totals.blockable === 0);
  }

  async function retryOffline() {
    if (!S.lastBlockResults || S.lastBlockResults.length === 0) {
      return setBanner('warn', 'No previous block sweep to retry.');
    }
    const offline = S.lastBlockResults.filter((r) =>
      !r.ok && /(offline|unreachable|DEVICE_NOT_CONNECTED)/i.test(r.error || ''));
    if (offline.length === 0) {
      return setBanner('ok', '✓ No offline dishes to retry.', 3000);
    }
    await loadAudit();
    const toRetry = [];
    for (const o of offline) {
      const fresh = (S.audit.rows || []).find((r) => r.client_id === o.client_id);
      if (fresh && fresh.blockable) toRetry.push(fresh);
    }
    if (toRetry.length === 0) {
      return setBanner('warn', 'None of the offline customers are still blockable.');
    }
    await blockMany(toRetry, 'retry');
  }

  function updateButtons() {
    // v4.21.101: bulk Block buttons removed from DOM in v4.21.100. Null-guard
    // the lookups so the rest of the render function still runs (fixes the
    // table never loading after the removal).
    const blockable = (S.audit && S.audit.rows) ? S.audit.rows.filter((r) => r.blockable) : [];
    const sel = blockable.filter((r) => S.selected.has(r.client_id));
    const bs = document.getElementById('sbmBlockSelected');
    const ba = document.getElementById('sbmBlockAll');
    if (bs) { bs.textContent = 'Block Selected (' + sel.length + ')'; bs.disabled = sel.length === 0; }
    if (ba) { ba.textContent = 'Block All Blockable (' + blockable.length + ')'; ba.disabled = blockable.length === 0; }
  }

  document.getElementById('sbmRefreshAudit').addEventListener('click', loadAudit);
  document.getElementById('sbmRefreshPaused').addEventListener('click', loadPaused);
  const _enrichBtn = document.getElementById('sbmEnrichPaused');
  if (_enrichBtn) _enrichBtn.addEventListener('click', enrichPaused);
  // v4.21.101: bulk block listeners only attach if the buttons still exist
  const _bsEl = document.getElementById('sbmBlockSelected');
  if (_bsEl) _bsEl.addEventListener('click', () => {
    const blockable = (S.audit.rows || []).filter((r) => r.blockable && S.selected.has(r.client_id));
    blockMany(blockable, 'selected');
  });
  const _baEl = document.getElementById('sbmBlockAll');
  if (_baEl) _baEl.addEventListener('click', () => {
    const blockable = (S.audit.rows || []).filter((r) => r.blockable);
    blockMany(blockable, 'all');
  });
  const _roEl = document.getElementById('sbmRetryOffline');
  if (_roEl) _roEl.addEventListener('click', retryOffline);

  // ── Auto-Pause Cron Health panel ──────────────────────────────────────────
  // v2.8.62 (data-report) self-triggers the extension on every admin page
  // load (rate-limited to once per 10 min). So this panel reads data-report's
  // health endpoint, which counts ALL extension runs regardless of trigger
  // source — Hybrid master.php cron, data-report self-trigger, manual button.
  async function loadCron() {
    const host = document.getElementById('sbmCronTable');
    host.innerHTML = '<div class="sbm-loading">Checking cron run history…</div>';
    let data;
    try {
      data = await drGet('dr_wifi_test_block_extend_health');
    } catch (e) {
      host.innerHTML = '<div class="sbm-empty">Failed to load cron health. ' + esc(e.message) + '</div>';
      return;
    }
    if (!data || !data.ok) {
      host.innerHTML = '<div class="sbm-empty">Bad response from data-report.</div>';
      return;
    }

    const status = data.last_run_status || 'never';
    const ago = data.last_run_seconds_ago;
    const heartbeatAgo = data.heartbeat_seconds_ago;
    const heartbeatPresent = !!data.heartbeat_present;
    const last24h = data.last_24h || {};
    const recent = data.recent_runs || [];

    let banner;
    if (status === 'never') {
      banner = '<div class="sbm-banner sbm-banner-warn">⏳ <b>Extension has not run yet.</b> '
        + 'data-report v2.8.62+ self-triggers on admin page loads (rate-limited to once per 10 min). '
        + 'Visit any data-report tab (Health, WiFi, Sessions) and the extension will fire in the background. '
        + 'Or click "▶ Run Now" above to fire it manually.</div>';
    } else if (status === 'healthy') {
      const since = heartbeatAgo !== null ? heartbeatAgo : ago;
      banner = '<div class="sbm-banner sbm-banner-ok">✅ <b>Extension is running.</b> '
        + 'Last fired ' + esc(fmtDuration(since)) + ' ago. New devices joining paused customers will be caught.</div>';
    } else if (status === 'stale') {
      const since = heartbeatAgo !== null ? heartbeatAgo : ago;
      banner = '<div class="sbm-banner sbm-banner-warn">⚠️ <b>Extension is one tick late.</b> '
        + 'Last fired ' + esc(fmtDuration(since)) + ' ago. Visit any data-report tab to retrigger.</div>';
    } else {
      const since = heartbeatAgo !== null ? heartbeatAgo : ago;
      banner = '<div class="sbm-banner sbm-banner-error">❌ <b>Extension hasn\'t run recently.</b> '
        + 'Last fired ' + esc(fmtDuration(since)) + ' ago. Click "▶ Run Now" to recover; '
        + 'visit data-report tabs to keep self-trigger active.</div>';
    }

    const stats =
      '<div class="sbm-summary" style="margin:12px 0 0;">'
      + '<div class="sbm-stat"><div class="sbm-stat-l">Runs (24h)</div><div class="sbm-stat-v">' + (last24h.total_runs || 0) + '</div></div>'
      + '<div class="sbm-stat"><div class="sbm-stat-l">New devices paused</div><div class="sbm-stat-v">' + (last24h.total_newly_paused || 0) + '</div></div>'
      + '<div class="sbm-stat"><div class="sbm-stat-l">Pause failures</div><div class="sbm-stat-v">' + (last24h.total_failures || 0) + '</div></div>'
      + '<div class="sbm-stat"><div class="sbm-stat-l">Expected (24h)</div><div class="sbm-stat-v" style="color:#94a3b8;">~144</div></div>'
      + '</div>';

    let runsTable = '';
    if (recent.length === 0) {
      runsTable = '<div class="sbm-empty" style="margin-top:12px;">No extension run history yet. Click ▶ Run Now or visit data-report.</div>';
    } else {
      runsTable = '<table class="sbm-tbl" style="margin-top:14px;"><thead><tr>'
        + '<th>When</th><th>Routers</th><th>Processed</th><th>Newly paused</th><th>Failures</th><th>Skipped</th><th>Duration</th>'
        + '</tr></thead><tbody>';
      for (const r of recent) {
        const skipped = (r.skipped_full_mode || 0) + (r.skipped_recent || 0) + (r.skipped_opt_out || 0)
                      + (r.skipped_throttled || 0) + (r.skipped_no_session || 0) + (r.skipped_fetch_fail || 0);
        const failPill = r.pause_failures > 0 ? '<span style="color:#c0392b;font-weight:700;">' + r.pause_failures + '</span>' : (r.pause_failures || 0);
        const newlyPill = r.newly_paused > 0 ? '<span style="color:#E65100;font-weight:700;">' + r.newly_paused + '</span>' : '0';
        runsTable += '<tr>'
          + '<td><span class="sbm-when">' + esc(fmtRelative(r.ts)) + '</span><br><span style="color:#94a3b8;font-size:10px;">' + esc(r.ts || '') + '</span></td>'
          + '<td>' + (r.total_routers || 0) + '</td>'
          + '<td>' + (r.processed || 0) + '</td>'
          + '<td>' + newlyPill + '</td>'
          + '<td>' + failPill + '</td>'
          + '<td><span style="color:#64748b;">' + skipped + '</span></td>'
          + '<td><span style="color:#64748b;">' + (r.duration_s || 0) + 's</span></td>'
          + '</tr>';
      }
      runsTable += '</tbody></table>';
    }

    host.innerHTML = banner + stats + runsTable;
  }

  function fmtDuration(seconds) {
    if (seconds === null || seconds === undefined) return '?';
    if (seconds < 60) return seconds + 's';
    if (seconds < 3600) return Math.round(seconds / 60) + ' min';
    return Math.round(seconds / 3600) + 'h ' + Math.round((seconds % 3600) / 60) + 'm';
  }

  document.getElementById('sbmRefreshCron').addEventListener('click', loadCron);

  // ── Full Reconciliation card — link to data-report's force-sync page ──
  // v4.21.41: reconcile logic moved into data-report's cron.php as Phase 4.
  // No more cross-plugin orchestration — just send the operator there.
  const FORCE_SYNC_URL = '/crm/_plugins/dishnet-data-report/public.php?action=dr_cron_force_run';
  document.getElementById('sbmForceSyncOpen').setAttribute('href', FORCE_SYNC_URL);

  // ── "Run Now" — manually fire the extension cron logic ──
  document.getElementById('sbmRunCronNow').addEventListener('click', async () => {
    const btn = document.getElementById('sbmRunCronNow');
    const ok = await modalConfirm({
      title: 'Run extension cron now?',
      body: 'Manually fires <code>dr_wifi_test_block_extend_now</code> in data-report. This is the same '
        + 'logic the cron runs every 10 minutes — walks every paused router, lists currently-connected '
        + 'devices, pauses any new MACs.<br><br>'
        + 'Useful to verify the path works without waiting for the next cron tick. After this fires, the '
        + 'cron health log should show a fresh entry.',
      okLabel: 'Run now', danger: false,
    });
    if (!ok) return;
    btn.disabled = true;
    btn.textContent = '⏳ Running…';
    try {
      const resp = await drGet('dr_wifi_test_block_extend_now');
      if (resp && resp.ok) {
        setBanner('ok', '✓ Extension cron ran. Newly paused: ' + (resp.total_newly_paused || 0)
          + ', failures: ' + (resp.total_failures || 0)
          + '. Refreshing health…', 4000);
        await loadCron();
      } else {
        setBanner('error', '❌ Extension cron returned bad response: ' + esc(JSON.stringify(resp || {}).substring(0, 200)));
      }
    } catch (e) {
      setBanner('error', '❌ Failed to run extension cron: ' + esc(e.message));
    } finally {
      btn.disabled = false;
      btn.textContent = '▶ Run Now';
    }
  });

  // ── Stuck Payments panel ───────────────────────────────────────────────────
  async function loadStuck() {
    const lookback = document.getElementById('sbmStuckLookback').value || 24;
    const host = document.getElementById('sbmStuckTable');
    host.innerHTML = '<div class="sbm-loading">Cross-checking recent UCRM payments against currently-paused dishes…</div>';
    try {
      const data = await hybGet('sl_payment_restore_audit&lookback=' + encodeURIComponent(lookback));
      const stuck = data.stuck || [];
      const titleEl = document.getElementById('sbmStuckTitle');
      if (stuck.length === 0) {
        const checked = data.recent_payments_total || 0;
        titleEl.innerHTML = '<span>✅</span> Stuck Payments — All Recent Payments Auto-Restored Cleanly';
        const note = data.note === 'no_paused_dishes' ? 'No dishes are currently paused.'
                   : data.note === 'no_recent_payments' ? 'No payments in the selected window.'
                   : 'Checked ' + checked + ' recent payment(s) against ' + (data.currently_paused_total || 0) + ' currently-paused dish(es). All paid customers have been restored.';
        host.innerHTML = '<div class="sbm-empty" style="background:#E8F5E9;border-color:#a7d8b1;color:#166534;">✓ ' + esc(note) + '</div>';
        return;
      }
      titleEl.innerHTML = '<span>⚠️</span> Stuck Payments — ' + stuck.length + ' customer(s) paid but still paused';
      let html = '<table class="sbm-tbl"><thead><tr>'
        + '<th>Customer</th>'
        + '<th>Paid</th>'
        + '<th>Amount</th>'
        + '<th>Paused since</th>'
        + '<th>Paused by</th>'
        + '<th>KIT / Router</th>'
        + '<th class="sbm-action-cell">Action</th>'
        + '</tr></thead><tbody>';
      for (const r of stuck) {
        html += '<tr data-router="' + esc(r.router_id) + '" data-cid="' + r.client_id + '">'
          + '<td><div class="sbm-name">' + esc(r.name || ('CRM #' + r.client_id)) + '</div><div class="sbm-name-sub">CRM #' + r.client_id + '</div></td>'
          + '<td>' + esc(fmtRelative(r.paid_at)) + '<br><span style="color:#94a3b8;font-size:10px;">' + esc(r.paid_at) + '</span></td>'
          + '<td><span class="sbm-mono" style="color:#16a34a;font-weight:600;">$' + (Number(r.last_amount) || 0).toFixed(2) + '</span><br><span style="color:#94a3b8;font-size:10px;">' + esc(r.paid_method || '') + '</span></td>'
          + '<td>' + esc(fmtRelative(r.paused_since)) + '<br><span style="color:#94a3b8;font-size:10px;">' + esc(r.paused_since) + '</span></td>'
          + '<td><span style="font-size:11px;color:#64748b;">' + esc(r.paused_by) + '</span></td>'
          + '<td><span class="sbm-mono">' + esc(r.kit) + '</span><br><span class="sbm-router">' + esc(r.router_id) + '</span></td>'
          + '<td class="sbm-action-cell"><button class="sbm-btn sbm-btn-sm sbm-btn-warn sbm-stuck-unblock" data-router="' + esc(r.router_id) + '" data-name="' + esc(r.name || '') + '">Unblock</button></td>'
          + '</tr>';
      }
      html += '</tbody></table>';
      host.innerHTML = html;
      document.querySelectorAll('.sbm-stuck-unblock').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const ok = await modalConfirm({
            title: 'Unblock ' + (btn.dataset.name || btn.dataset.router) + '?',
            body: 'This customer paid but their dish is still paused. Unblock now?<br><br><span class="sbm-router">' + esc(btn.dataset.router) + '</span>',
            okLabel: 'Unblock now', danger: false,
          });
          if (!ok) return;
          btn.disabled = true; btn.textContent = '...';
          try {
            const r = await drPost('dr_wifi_test_unblock', { router_id: btn.dataset.router, by: 'admin_stuck_payment_recovery' });
            if (r && r.ok) {
              setBanner('ok', '✓ Unblocked ' + esc(btn.dataset.name || btn.dataset.router), 4000);
              loadStuck();
              loadPaused();
            } else {
              setBanner('error', '❌ Unblock failed: ' + esc((r && r.error) || 'no response'));
              btn.disabled = false; btn.textContent = 'Unblock';
            }
          } catch (e) {
            setBanner('error', '❌ Unblock crashed: ' + esc(e.message));
            btn.disabled = false; btn.textContent = 'Unblock';
          }
        });
      });
    } catch (e) {
      host.innerHTML = '<div class="sbm-empty">Failed to load stuck-payment audit. ' + esc(e.message) + '</div>';
    }
  }

  document.getElementById('sbmRefreshStuck').addEventListener('click', loadStuck);
  document.getElementById('sbmStuckLookback').addEventListener('change', loadStuck);

  // ── Bridge Activity Log panel ──────────────────────────────────────────────
  async function loadBridge() {
    const kind = document.getElementById('sbmBridgeFilterKind').value;
    const onlyFailed = document.getElementById('sbmBridgeFilterStatus').value === 'failed';
    let q = 'sl_bridge_events&limit=100';
    if (kind) q += '&kind=' + encodeURIComponent(kind);
    if (onlyFailed) q += '&only_failed=1';
    const host = document.getElementById('sbmBridgeTable');
    host.innerHTML = '<div class="sbm-loading">Loading bridge activity…</div>';
    try {
      const data = await hybGet(q);
      const events = data.events || [];
      if (events.length === 0) {
        host.innerHTML = '<div class="sbm-empty">No bridge events yet. Events appear here whenever UCRM fires a service.suspend, payment.add, service.unsuspend, or service.postpone webhook.</div>';
        return;
      }
      let html = '<table class="sbm-tbl"><thead><tr>'
        + '<th>When</th>'
        + '<th>Kind</th>'
        + '<th>Customer</th>'
        + '<th>Trigger</th>'
        + '<th>Outcome</th>'
        + '<th>Routers</th>'
        + '<th>Detail</th>'
        + '</tr></thead><tbody>';
      for (const ev of events) {
        const ok = !!ev.ok;
        const kindCell = ev.kind === 'suspend'
          ? '<span class="sbm-pill sbm-pill-blockable">SUSPEND</span>'
          : '<span class="sbm-pill" style="background:#E3F2FD;color:#1565C0;">RESTORE</span>';
        const outcomeCell = ok
          ? (ev.skipped_reason
              ? '<span class="sbm-pill" style="background:#F1F5F9;color:#64748b;">skipped: ' + esc(ev.skipped_reason) + '</span>'
              : (ev.note === 'nothing_paused' || ev.note === 'client_not_in_paused' || ev.note === 'no_kits'
                  ? '<span class="sbm-pill" style="background:#F1F5F9;color:#64748b;">' + esc(ev.note) + '</span>'
                  : '<span class="sbm-pill" style="background:#E8F5E9;color:#166534;">✓ OK</span>'))
          : '<span class="sbm-pill" style="background:#FFEBEE;color:#c0392b;">❌ FAILED</span>';
        const routersCell = (ev.kind === 'suspend')
          ? (ev.routers_processed || 0) + ' processed' + ((ev.routers_failed || 0) > 0 ? ', ' + ev.routers_failed + ' failed' : '')
          : (ev.routers_processed || 0) + ' restored' + ((ev.routers_failed || 0) > 0 ? ', ' + ev.routers_failed + ' failed' : '');
        const detailParts = [];
        for (const a of (ev.attempts || [])) {
          if (a.error) detailParts.push((a.kit || a.router_id || '?') + ': ' + a.error);
          else if (a.note) detailParts.push((a.kit || a.router_id || '?') + ': ' + a.note);
        }
        const detail = detailParts.length > 0 ? esc(detailParts.join(' · ')) : '<span style="color:#cbd5e1;">—</span>';
        html += '<tr>'
          + '<td>' + esc(fmtRelative(ev.ts)) + '<br><span style="color:#94a3b8;font-size:10px;">' + esc(ev.ts || '') + '</span></td>'
          + '<td>' + kindCell + '</td>'
          + '<td><div class="sbm-name">' + esc(ev.client_name || ('CRM #' + ev.client_id)) + '</div><div class="sbm-name-sub">CRM #' + ev.client_id + '</div></td>'
          + '<td><span style="font-size:11px;color:#64748b;">' + esc(ev.triggered_by) + '</span></td>'
          + '<td>' + outcomeCell + '</td>'
          + '<td><span style="font-size:11px;">' + esc(routersCell) + '</span></td>'
          + '<td><span style="font-size:11px;color:#64748b;">' + detail + '</span></td>'
          + '</tr>';
      }
      html += '</tbody></table>';
      host.innerHTML = html;
    } catch (e) {
      host.innerHTML = '<div class="sbm-empty">Failed to load bridge events. ' + esc(e.message) + '</div>';
    }
  }
  document.getElementById('sbmRefreshBridge').addEventListener('click', loadBridge);
  document.getElementById('sbmBridgeFilterKind').addEventListener('change', loadBridge);
  document.getElementById('sbmBridgeFilterStatus').addEventListener('change', loadBridge);

  // ── Initial load ──
  Promise.all([loadAudit(), loadPaused(), loadCron()]);
  // Stuck-payments + bridge log lazy-load on first refresh click
  // (don't block initial render with extra UCRM API calls)
})();
</script>
