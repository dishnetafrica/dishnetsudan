// ═════════════════════════════════════════════════════════════════════════════
// DishNet Hybrid — Manual Starlink Suspend (Chrome Console Script)
//
// PURPOSE:
//   Find UCRM-suspended Starlink customers and pause their dishes via
//   sibling Data Report plugin's already-working WiFi/gRPC infrastructure.
//
// HOW IT WORKS (v2 — uses Data Report directly):
//   1. slAudit() → Hybrid endpoint sl_audit_suspended → returns customers +
//      KIT serials (UCRM live API + service.name regex extraction).
//   2. For each blockable customer:
//      a. drResolveKitToRouter(kit) → Data Report dr_wifi_lookup_by_kit
//         (added in data-report v2.8.57) → returns router_id.
//      b. dr_wifi_test_block POST {router_id, mode} → actual gRPC pause via
//         the same code path the WiFi tab uses for manual blocks.
//
// HOW TO USE:
//   1. Open https://crm.dishnetafrica.com/crm/_plugins/dishnet-hybrid-telecom/
//      and log in as admin.
//   2. Open Chrome DevTools → Console.
//   3. Paste this entire file and press Enter.
//   4. Commands:
//        slAudit()                — list (READ-ONLY)
//        slDebug()                — raw status histograms / kit-source
//        slBlockOne(clientId)     — block one (testing recommended)
//        slBlockAll()             — block every blockable (asks for confirm)
//        slUnblockOne(clientId)   — unblock one (uses data-report)
//
// SAFETY:
//   - Audit is read-only; run as many times as you want.
//   - slBlockAll requires typing 'STARLINK_PROCEED' to confirm.
//   - Block uses Data Report's dr_wifi_test_block — proven path,
//     dish-reachability probe, idempotency, full audit log built in.
//   - 2-second delay between blocks (Starlink gRPC rate-limit friendly).
//
// REQUIRES:
//   - DishNet Hybrid v4.21.24+ (sl_audit_suspended endpoint)
//   - DishNet Data Report v2.8.57+ (dr_wifi_lookup_by_kit endpoint)
// ═════════════════════════════════════════════════════════════════════════════

(function () {
  const PLUGIN_BASE = '/crm/_plugins/dishnet-hybrid-telecom';
  const API_PATH    = PLUGIN_BASE + '/public.php?page=api&action=';
  const DR_BASE     = '/crm/_plugins/dishnet-data-report';
  const DR_ACTION   = DR_BASE + '/public.php?action=';
  const DELAY_MS    = 2000;  // between blocks

  // ── Helpers ────────────────────────────────────────────────────────────────
  async function apiGet(action) {
    const r = await fetch(`${API_PATH}${action}`, {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
    });
    if (!r.ok) throw new Error(`HTTP ${r.status} on ${action}`);
    const j = await r.json();
    if (j.status !== 'success') throw new Error(j.message || 'API error');
    return j.data;
  }

  async function apiPost(action, body) {
    const r = await fetch(`${API_PATH}${action}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    });
    const j = await r.json().catch(() => ({ status: 'error', message: `HTTP ${r.status}` }));
    return { ok: r.ok, status: j.status, message: j.message, data: j.data };
  }

  // ── Data Report endpoints (sibling plugin) ─────────────────────────────────
  // Same UCRM admin session — auth via PHPSESSID cookie which is already present.
  //
  // cache:'no-store' + a cache-busting param helps when UCRM's service worker
  // tries to handle these cross-plugin URLs and fails (manifests as a fetch
  // rejected with ERR_FAILED before the request ever leaves the browser).
  async function drGet(action, params) {
    let url = DR_ACTION + action + '&_cb=' + Date.now();
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`;
      }
    }
    let r;
    try {
      r = await fetch(url, {
        method: 'GET', credentials: 'include', cache: 'no-store',
        headers: { 'Accept': 'application/json' },
      });
    } catch (e) {
      throw new Error(`Data Report ${action}: network error (${e.message}). Likely causes: (1) v2.8.57 not deployed; (2) service worker blocking cross-plugin fetch — try opening ${DR_ACTION + action} in a new tab to verify the endpoint responds.`);
    }
    const txt = await r.text();
    let j; try { j = JSON.parse(txt); } catch (_) { j = null; }
    if (!j) throw new Error(`Data Report ${action}: non-JSON response (HTTP ${r.status}). First 200 chars: ${txt.substring(0, 200)}`);
    return j;
  }

  async function drPost(action, body) {
    const url = DR_ACTION + action + '&_cb=' + Date.now();
    let r;
    try {
      r = await fetch(url, {
        method: 'POST', credentials: 'include', cache: 'no-store',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
      });
    } catch (e) {
      throw new Error(`Data Report ${action}: network error (${e.message})`);
    }
    const txt = await r.text();
    let j; try { j = JSON.parse(txt); } catch (_) { j = null; }
    if (!j) throw new Error(`Data Report ${action}: non-JSON response (HTTP ${r.status}). First 200 chars: ${txt.substring(0, 200)}`);
    return j;
  }

  // For each KIT serial owned by a customer, look up router_id in data-report.
  // Returns first found {router_id, kit_serial, customer_name, account_number}
  // or null if no kit resolved. Distinguishes "not found in map" from
  // "endpoint failed entirely" — the latter rethrows so the caller surfaces it.
  async function drResolveKitToRouter(kitSerials) {
    let lastNetworkError = null;
    for (const kit of (kitSerials || [])) {
      try {
        const j = await drGet('dr_wifi_lookup_by_kit', { kit_serial: kit });
        if (j && j.ok && j.found) {
          return {
            router_id:       j.router_id,
            kit_serial:      j.kit_serial || kit,
            customer_name:   j.customer_name || '',
            account_number:  j.account_number || '',
            session_active:  !!j.session_active,
            can_change_wifi: !!j.can_change_wifi,
            _raw:            j,
          };
        }
        // ok=false but the request succeeded — KIT just not in map. Keep trying
        // the customer's other KITs (if any).
      } catch (e) {
        // Network/endpoint-level failure — remember it so we can surface a real
        // error instead of pretending the KIT just wasn't in the map.
        lastNetworkError = e;
        console.warn(`  drResolveKitToRouter(${kit}) network error: ${e.message}`);
      }
    }
    if (lastNetworkError) throw lastNetworkError;
    return null;
  }

  const sleep = (ms) => new Promise((res) => setTimeout(res, ms));

  // ── Phase 1: Audit ─────────────────────────────────────────────────────────
  window.slAudit = async function () {
    console.log('%c🔍 Auditing UCRM-suspended Starlink customers…',
                'font-size:14px;font-weight:bold;color:#1565C0');
    let data;
    try {
      data = await apiGet('sl_audit_suspended');
    } catch (e) {
      console.error('❌ Audit failed:', e.message);
      console.error('  Are you on v4.21.22+ and logged in as admin?');
      return;
    }

    const t = data.totals;
    console.log(
      '%c📊 Totals',
      'font-size:13px;font-weight:bold;color:#1e293b'
    );
    console.table([{
      'Suspended (live)':  t.suspended_total,
      'Has Starlink KIT':  t.with_kit,
      'VIP (will skip)':   t.vip_skip,
      'Already blocked':   t.already_blocked,
      '✅ Blockable':      t.blockable,
    }]);
    if (data.api_strategy) {
      console.log(`  (API: ${data.api_strategy})`);
    }

    if (t.suspended_total === 0) {
      console.warn('⚠️  Zero suspended customers detected.');
      console.warn('   If UCRM shows "28 suspended" but this returns 0, run:');
      console.log(
        '%c    slDebug()',
        'font-family:monospace;font-size:13px;background:#1e293b;color:#fff;padding:3px 8px'
      );
      console.warn('   It will show what status values are actually in the cache, which');
      console.warn('   will tell us why the filter missed them.');
    }

    // Render row table — pretty version with all signal
    const tableRows = data.rows.map((r) => ({
      '#':        r.client_id,
      'Name':     (r.name || '').substring(0, 40),
      'Phone':    (r.phone || '').substring(0, 18),
      'Plans':    (r.plans || '').substring(0, 30),
      'Balance':  r.balance.toFixed(2),
      'Svc':      (r.suspended_services || []).length,
      'KITs':     r.kit_count,
      'Src':      r.kit_source || '',
      'VIP':      r.is_vip ? `🛡 ${r.vip_reason}` : '',
      'Blocked':  r.already_blocked ? '⏸' : '',
      'Action':   r.blockable ? '✅ BLOCK' : (r.already_blocked ? 'skip (already)' : (r.is_vip ? 'skip (VIP)' : 'skip (no KIT)')),
    }));
    if (tableRows.length > 0) {
      console.log('%c📋 All suspended customers (sorted: blockable first)',
                  'font-size:13px;font-weight:bold;color:#1e293b');
      console.table(tableRows);
    }

    // Stash result on window so slBlockAll can use it
    window.__slAuditResult = data;

    if (t.blockable === 0) {
      if (t.suspended_total > 0) {
        console.log('%c✓ All suspended Starlink customers are either VIP or already blocked.',
                    'color:#16a34a;font-weight:bold');
      }
    } else {
      console.log(
        '%c→ ' + t.blockable + ' customer(s) ready to block. Run slBlockAll() to proceed.',
        'color:#D41C1C;font-weight:bold;font-size:13px'
      );
    }

    return data;
  };

  // ── Debug: dump raw API response ───────────────────────────────────────────
  window.slDebug = async function () {
    console.log('%c🔬 Debug audit — calling UCRM API directly…',
                'font-size:14px;font-weight:bold;color:#6A1B9A');
    let data;
    try {
      data = await apiGet('sl_audit_suspended&debug=1');
    } catch (e) {
      console.error('❌ Debug audit failed:', e.message);
      return;
    }
    console.log('%c📡 API call', 'font-weight:bold;color:#1e293b');
    console.log('  Strategy used:        ' + data.api_strategy);
    console.log('  Suspended services:   ' + data.raw_suspended_services);
    console.log('  Distinct clients:     ' + data.distinct_suspended_clients);
    console.log('');
    console.log('%c🏷  Service-status histogram', 'font-weight:bold;color:#1e293b');
    console.log('  Format: status_code → count (3=admin suspend, 4=no-payment suspend)');
    console.table(data.service_status_histogram);
    console.log('%c🔌 Sample suspended services (first 8)', 'font-weight:bold;color:#1e293b');
    console.table(data.sample_services);
    console.log('');
    console.log(data.note || '');
    return data;
  };

  // ── Phase 2: Block all blockable ───────────────────────────────────────────
  window.slBlockAll = async function (mode) {
    mode = mode || 'rename_only';
    if (!['pause_only', 'rename_only', 'full'].includes(mode)) {
      console.error('❌ mode must be "pause_only", "rename_only", or "full"');
      return;
    }

    let data = window.__slAuditResult;
    if (!data) {
      console.log('Running audit first…');
      data = await window.slAudit();
      if (!data) return;
    }

    const blockable = data.rows.filter((r) => r.blockable);
    if (blockable.length === 0) {
      console.log('%c✓ Nothing to block.', 'color:#16a34a;font-weight:bold');
      return;
    }

    console.log(
      '%c⚠️  ABOUT TO BLOCK ' + blockable.length + ' CUSTOMER(S)',
      'font-size:14px;font-weight:bold;color:#c0392b;background:#FFEBEE;padding:6px 10px'
    );
    console.log(`  Mode: ${mode}`);
    console.log('  Each call goes through StarlinkBlockService.suspend() server-side.');
    console.log('  State rows will be written → restore on payment will work automatically.');
    console.log('');
    console.log('  To proceed, run:');
    console.log(
      '%c    slConfirm("STARLINK_PROCEED")',
      'font-family:monospace;font-size:13px;background:#1e293b;color:#fff;padding:3px 8px'
    );
    console.log('');
    console.log('  Or to cancel, just do nothing.');

    window.__slPending = { mode, blockable };
  };

  window.slConfirm = async function (token) {
    if (token !== 'STARLINK_PROCEED') {
      console.error('❌ Confirmation token mismatch. Type slConfirm("STARLINK_PROCEED").');
      return;
    }
    const pending = window.__slPending;
    if (!pending) {
      console.error('❌ Nothing pending. Run slBlockAll() first.');
      return;
    }
    delete window.__slPending;

    console.log(
      '%c🚀 Starting block sweep — ' + pending.blockable.length + ' customer(s)',
      'font-size:13px;font-weight:bold;color:#D41C1C'
    );

    const results = [];
    for (let i = 0; i < pending.blockable.length; i++) {
      const c = pending.blockable[i];
      const tag = `[${i + 1}/${pending.blockable.length}]`;
      console.log(`${tag} ⏳ ${c.name} #${c.client_id}…`);

      try {
        // Step 1: kit_serial → router_id via data-report
        const router = await drResolveKitToRouter(c.kit_serials || []);
        if (!router) {
          console.warn(`${tag} ⚠️  ${c.name} #${c.client_id} — no router resolved (KITs ${(c.kit_serials || []).join(',') || 'none'} not in data-report router map)`);
          results.push({ client_id: c.client_id, name: c.name, ok: false, error: 'no_router' });
          if (i < pending.blockable.length - 1) await sleep(500);
          continue;
        }
        if (!router.session_active) {
          console.warn(`${tag} ⚠️  ${c.name} #${c.client_id} — router ${router.router_id} but session not active for account ${router.account_number}`);
          results.push({ client_id: c.client_id, name: c.name, ok: false, router_id: router.router_id, error: 'session_inactive' });
          if (i < pending.blockable.length - 1) await sleep(500);
          continue;
        }

        // Step 2: dr_wifi_test_block — actual pause via gRPC
        const blockResp = await drPost('dr_wifi_test_block', {
          router_id: router.router_id,
          mode:      pending.mode,
          by:        'manual_admin_sweep',
        });
        if (blockResp && blockResp.ok) {
          console.log(`${tag} ✅ ${c.name} #${c.client_id} — paused (router ${router.router_id})`);
          results.push({ client_id: c.client_id, name: c.name, ok: true,
                         router_id: router.router_id, kit_serial: router.kit_serial });
        } else {
          const err = blockResp ? (blockResp.error || 'unknown') : 'no response';
          const errType = blockResp && blockResp.error_type ? ` [${blockResp.error_type}]` : '';
          console.warn(`${tag} ❌ ${c.name} #${c.client_id} — block failed: ${err}${errType}`);
          results.push({ client_id: c.client_id, name: c.name, ok: false,
                         router_id: router.router_id, error: err });
        }
      } catch (e) {
        console.error(`${tag} ❌ ${c.name} #${c.client_id} — ${e.message}`);
        results.push({ client_id: c.client_id, name: c.name, ok: false, error: e.message });
      }

      // Throttle between calls — don't hammer Starlink gRPC
      if (i < pending.blockable.length - 1) await sleep(DELAY_MS);
    }

    const okCount   = results.filter((r) => r.ok).length;
    const failCount = results.length - okCount;
    console.log('');
    console.log(
      '%c📊 Block sweep complete — ' + okCount + ' OK, ' + failCount + ' failed',
      'font-size:13px;font-weight:bold;color:' + (failCount === 0 ? '#16a34a' : '#E65100')
    );
    console.table(results.map((r) => ({
      '#':        r.client_id,
      'Name':     (r.name || '').substring(0, 40),
      'OK':       r.ok ? '✅' : '❌',
      'Router':   r.router_id || '',
      'KIT':      r.kit_serial || '',
      'Error':    r.error || '',
    })));

    // Persist for slRetryOffline / slListBlocked diagnostics
    window.__slLastSweep = { mode: pending.mode, results, ts: new Date().toISOString() };

    const offlineRetryable = results.filter(
      (r) => !r.ok && (r.error || '').includes('DEVICE_NOT_CONNECTED')
                  ||  !r.ok && (r.error || '').toLowerCase().includes('offline'));
    if (offlineRetryable.length > 0) {
      console.log('');
      console.log(
        '%c↻ ' + offlineRetryable.length + ' dishes were offline. Run slRetryOffline() later to retry just those.',
        'color:#E65100'
      );
    }

    console.log('');
    console.log('To unblock when customer pays: open Data Report → WiFi tab → find the router → Test Unblock.');
    console.log('Or run slUnblockOne(clientId) — calls data-report dr_wifi_test_unblock for the router resolved from this client\'s KIT.');
  };

  // ── Retry just the offline customers from the last sweep ───────────────────
  window.slRetryOffline = async function () {
    const last = window.__slLastSweep;
    if (!last || !Array.isArray(last.results) || last.results.length === 0) {
      console.error('❌ No previous sweep found. Run slBlockAll() first.');
      return;
    }
    const offline = last.results.filter((r) =>
      !r.ok && (
        (r.error || '').includes('DEVICE_NOT_CONNECTED') ||
        (r.error || '').toLowerCase().includes('offline') ||
        (r.error || '').toLowerCase().includes('unreachable')
      )
    );
    if (offline.length === 0) {
      console.log('%c✓ No offline customers to retry from last sweep.',
                  'color:#16a34a;font-weight:bold');
      return;
    }
    console.log(
      '%c↻ Retrying ' + offline.length + ' offline customer(s) from sweep at ' + last.ts,
      'font-size:13px;font-weight:bold;color:#1565C0'
    );

    // Re-fetch fresh audit data — KITs may have changed, dishes may have come online
    const data = await window.slAudit();
    if (!data) return;

    const newResults = [];
    for (let i = 0; i < offline.length; i++) {
      const c = offline[i];
      const tag = `[${i + 1}/${offline.length}]`;
      console.log(`${tag} ⏳ ${c.name} #${c.client_id}…`);
      // Look up the row from fresh audit (in case KIT/router data changed)
      const row = (data.rows || []).find((r) => r.client_id === c.client_id);
      if (!row || !row.kit_serials || row.kit_serials.length === 0) {
        console.warn(`${tag} ⚠️  ${c.name} #${c.client_id} — no longer in audit (paid? unsuspended?) or no KIT`);
        newResults.push({ client_id: c.client_id, name: c.name, ok: false, error: 'not_in_audit' });
        continue;
      }
      try {
        const router = await drResolveKitToRouter(row.kit_serials);
        if (!router) {
          console.warn(`${tag} ⚠️  ${c.name} #${c.client_id} — router not in map`);
          newResults.push({ client_id: c.client_id, name: c.name, ok: false, error: 'no_router' });
          continue;
        }
        const blockResp = await drPost('dr_wifi_test_block', {
          router_id: router.router_id, mode: last.mode || 'rename_only', by: 'manual_retry_offline',
        });
        if (blockResp && blockResp.ok) {
          console.log(`${tag} ✅ ${c.name} #${c.client_id} — paused (router ${router.router_id})`);
          newResults.push({ client_id: c.client_id, name: c.name, ok: true,
                            router_id: router.router_id, kit_serial: router.kit_serial });
        } else {
          const err = blockResp ? (blockResp.error || 'unknown') : 'no response';
          console.warn(`${tag} ❌ ${c.name} #${c.client_id} — ${err}`);
          newResults.push({ client_id: c.client_id, name: c.name, ok: false,
                            router_id: router.router_id, error: err });
        }
      } catch (e) {
        console.error(`${tag} ❌ ${c.name} #${c.client_id} — ${e.message}`);
        newResults.push({ client_id: c.client_id, name: c.name, ok: false, error: e.message });
      }
      if (i < offline.length - 1) await sleep(DELAY_MS);
    }

    const okCount = newResults.filter((r) => r.ok).length;
    console.log('');
    console.log(
      '%c📊 Retry complete — ' + okCount + ' of ' + offline.length + ' came online and got paused',
      'font-size:13px;font-weight:bold;color:' + (okCount === offline.length ? '#16a34a' : '#E65100')
    );
    console.table(newResults.map((r) => ({
      '#':      r.client_id,
      'Name':   (r.name || '').substring(0, 40),
      'OK':     r.ok ? '✅' : '❌',
      'Router': r.router_id || '',
      'Error':  r.error || '',
    })));

    // Update sweep state — replace offline entries with new outcomes so the
    // next slRetryOffline only retries those still failing.
    const merged = last.results.map((r) => {
      if (!offline.find((o) => o.client_id === r.client_id)) return r;
      const upd = newResults.find((n) => n.client_id === r.client_id);
      return upd || r;
    });
    window.__slLastSweep = { mode: last.mode, results: merged, ts: new Date().toISOString() };

    return newResults;
  };

  // ── Cleanup helper: list customers without resolvable KITs ─────────────────
  // For each suspended Starlink customer where the regex couldn't extract a KIT
  // from service.name, prints the UCRM service name + suggests fixing the
  // service title (operator updates UCRM admin) so future audits will catch
  // them. Output is copy-pasteable for sharing with Bidal/Aida.
  window.slKitCleanup = async function () {
    const data = window.__slAuditResult || await window.slAudit();
    if (!data) return;
    const noKit = (data.rows || []).filter((r) =>
      !r.is_vip && !r.already_blocked && (r.kit_count === 0 || (r.kit_serials || []).length === 0)
    );
    if (noKit.length === 0) {
      console.log('%c✓ Every blockable customer has a resolvable KIT.',
                  'color:#16a34a;font-weight:bold');
      return;
    }
    console.log(
      '%c🛠  ' + noKit.length + ' customer(s) need their UCRM service title updated to include (KITxxx)',
      'font-size:13px;font-weight:bold;color:#E65100'
    );
    console.log('   Pattern: change the service name in UCRM admin to include the KIT serial in parentheses.');
    console.log('   Example:   "Site : Mr. Ocheng Kenneth Kaunda : Service Plan ..."');
    console.log('              →');
    console.log('              "Site : Mr. Ocheng Kenneth Kaunda (KIT4M0xxxxxxxxx) : Service Plan ..."');
    console.log('   The KIT serial can be found in: dishnet-starlink-finance plugin → KIT inventory, or by physically reading the dish label.');
    console.log('');
    console.table(noKit.map((r) => ({
      '#':       r.client_id,
      'Name':    (r.name || '').substring(0, 40),
      'Phone':   (r.phone || '').substring(0, 18),
      'Plans':   (r.plans || '').substring(0, 30),
      'Balance': r.balance.toFixed(2),
      'Current service title': (r.suspended_services || []).map((s) => s.name).join(' | '),
    })));
    console.log('');
    console.log('Once UCRM service titles are updated, re-run slAudit() — these customers will move to ✅ BLOCK status.');
    return noKit;
  };

  // ── List currently-paused dishes (read-only) ───────────────────────────────
  // Calls data-report dr_wifi_test_block_status (no params) → returns all
  // routers currently in test-block state. Useful to see what's paused right
  // now, including pre-existing pauses from before this session.
  window.slListBlocked = async function () {
    console.log('%c📋 Listing currently-paused dishes from data-report…',
                'font-size:13px;font-weight:bold;color:#1565C0');
    let resp;
    try {
      resp = await drGet('dr_wifi_test_block_status');
    } catch (e) {
      console.error('❌ ' + e.message);
      return;
    }
    if (!resp || !resp.ok) {
      console.error('❌ Bad response: ' + (resp ? JSON.stringify(resp) : 'none'));
      return;
    }
    if (!resp.blocked || resp.blocked.length === 0) {
      console.log('%c✓ No dishes currently paused.', 'color:#16a34a;font-weight:bold');
      return [];
    }
    console.log(`Found ${resp.count} paused dish(es):`);
    console.table(resp.blocked.map((r) => ({
      'Router':       r.router_id,
      'Mode':         r.mode,
      'Blocked at':   r.blocked_at,
      'Blocked by':   r.blocked_by,
      'Devices paused': r.paused_count,
      'Block SSID':   r.block_ssid || '',
    })));
    console.log('');
    console.log('To unblock a specific router: navigate to the Hybrid Admin → Starlink Pauses tab,');
    console.log('or use Data Report → WiFi tab.');
    return resp.blocked;
  };

  // ── Single-customer helper ──────────────────────────────────────────────────
  window.slBlockOne = async function (clientId, mode) {
    mode = mode || 'pause_only';
    if (!Number.isInteger(clientId) || clientId <= 0) {
      console.error('❌ slBlockOne(clientId, mode?) — clientId must be a positive integer');
      return;
    }
    if (!['pause_only', 'full'].includes(mode)) {
      console.error('❌ mode must be "pause_only" or "full"');
      return;
    }

    // Make sure we have audit data so we know this client's KIT serials
    let data = window.__slAuditResult;
    if (!data) {
      console.log('Running audit first to find KIT serial…');
      data = await window.slAudit();
      if (!data) return;
    }
    const row = (data.rows || []).find((r) => r.client_id === clientId);
    if (!row) {
      console.error(`❌ Client #${clientId} not in audit results. Run slAudit() and check the table.`);
      return;
    }
    if (!row.kit_serials || row.kit_serials.length === 0) {
      console.error(`❌ Client #${clientId} (${row.name}) has no KIT serials resolvable from sl_kits.json or service.name. Cannot block via data-report.`);
      return;
    }

    console.log(`⏳ Resolving KIT → router for #${clientId} (${row.name})…`);
    let router;
    try {
      router = await drResolveKitToRouter(row.kit_serials);
    } catch (e) {
      console.error(`❌ ${e.message}`);
      console.log('');
      console.log('Diagnostic step: open this URL in a new browser tab and check the response:');
      console.log(`  ${window.location.origin}${DR_ACTION}dr_wifi_lookup_by_kit&kit_serial=${encodeURIComponent(row.kit_serials[0] || '')}`);
      console.log('  Expected: JSON response (either {"ok":true,...} or {"ok":false,"error":"..."})');
      console.log('  If you see HTML or "Action not found": data-report v2.8.57 not deployed yet.');
      console.log('  If you see a network error in the new tab too: deeper auth/path issue.');
      return null;
    }
    if (!router) {
      console.error(`❌ Data report router map doesn't contain any of: ${row.kit_serials.join(', ')}`);
      console.log('  Hint: open Data Report → WiFi tab → run "Discover Routers" first.');
      return null;
    }
    console.log(`  Router: ${router.router_id}, KIT: ${router.kit_serial}, account: ${router.account_number}, session_active: ${router.session_active}`);
    if (!router.session_active) {
      console.error(`❌ Session not active for account ${router.account_number}. Refresh cookies in Data Report → Cookie Sync.`);
      return null;
    }

    console.log(`⏳ Pausing via data-report dr_wifi_test_block (mode=${mode})…`);
    const blockResp = await drPost('dr_wifi_test_block', {
      router_id: router.router_id,
      mode,
      by: 'manual_slBlockOne',
    });
    if (blockResp && blockResp.ok) {
      console.log(`✅ Done — router ${router.router_id} paused.`);
      console.log('   To unblock: slUnblockOne(' + clientId + ') or use Data Report → WiFi tab.');
      return blockResp;
    } else {
      const err = blockResp ? (blockResp.error || 'unknown') : 'no response';
      console.error(`❌ Block failed: ${err}`);
      if (blockResp && blockResp.error_type) console.error(`   error_type: ${blockResp.error_type}`);
      if (blockResp && blockResp.retry_suggestion) console.error(`   retry: ${blockResp.retry_suggestion}`);
      return null;
    }
  };

  // ── Single-customer unblock helper ──────────────────────────────────────────
  window.slUnblockOne = async function (clientId) {
    if (!Number.isInteger(clientId) || clientId <= 0) {
      console.error('❌ slUnblockOne(clientId) — clientId must be a positive integer');
      return;
    }
    let data = window.__slAuditResult;
    if (!data) {
      console.log('Running audit first to find KIT serial…');
      data = await window.slAudit();
      if (!data) return;
    }
    const row = (data.rows || []).find((r) => r.client_id === clientId);
    if (!row) {
      console.error(`❌ Client #${clientId} not in audit results. Note: once UCRM marks them un-suspended (status 1), they won't appear in the audit list anymore — block lookup needs a different path.`);
      return;
    }
    const router = await drResolveKitToRouter(row.kit_serials || []);
    if (!router) {
      console.error(`❌ Could not resolve router for #${clientId}.`);
      return null;
    }
    console.log(`⏳ Unpausing via data-report dr_wifi_test_unblock for ${router.router_id}…`);
    const r = await drPost('dr_wifi_test_unblock', {
      router_id: router.router_id,
      by: 'manual_slUnblockOne',
    });
    if (r && r.ok) {
      console.log(`✅ Unblocked — router ${router.router_id}.`);
      return r;
    } else {
      console.error(`❌ Unblock failed: ${r ? (r.error || 'unknown') : 'no response'}`);
      return null;
    }
  };

  // ── Splash ─────────────────────────────────────────────────────────────────
  console.log('%c═══ DishNet Manual Starlink Suspend ═══', 'font-size:14px;font-weight:bold;color:#D41C1C');
  console.log('Pauses non-paying Starlink customers via Data Report\'s dr_wifi_test_block.');
  console.log('Available commands:');
  console.log('%c  slAudit()                              ', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— list suspended customers (read-only)');
  console.log('%c  slDebug()                              ', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— raw status histograms / kit-source');
  console.log('%c  slListBlocked()                        ', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— list dishes currently paused');
  console.log('%c  slKitCleanup()                         ', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— customers needing KIT added to service title');
  console.log('%c  slBlockOne(clientId, mode="rename_only")', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— block one (default: rename_only)');
  console.log('%c  slBlockAll(mode="rename_only")           ', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— block all blockable (asks for confirm)');
  console.log('%c  slRetryOffline()                       ', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— retry just dishes that were offline last sweep');
  console.log('%c  slUnblockOne(clientId)                 ', 'font-family:monospace;background:#F1F5F9;padding:2px 6px', '— unblock one (when customer pays)');
  console.log('');
  console.log('Run slAudit() to start.');
})();
