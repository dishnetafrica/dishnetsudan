<?php
// PHP 7.4 compatible
$retailer = $auth->requireLogin();
$isAdmin  = ($retailer['role'] ?? '') === 'admin';
?>
<style>
.pm-wrap { max-width:1100px; margin:0 auto; padding:16px; }
.pm-card { background:#fff; border-radius:10px; border:1px solid #e2e8f0; margin-bottom:20px; overflow:hidden; }
.pm-card-hd { padding:14px 18px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.pm-card-hd h3 { margin:0; font-size:15px; font-weight:600; color:#1e293b; }
.pm-badge { font-size:11px; padding:2px 8px; border-radius:20px; font-weight:600; }
.pm-badge.mapped   { background:#dcfce7; color:#166534; }
.pm-badge.unmapped { background:#fef3c7; color:#92400e; }
.pm-badge.partial  { background:#dbeafe; color:#1e40af; }
.pm-table { width:100%; border-collapse:collapse; font-size:13px; }
.pm-table th { background:#f1f5f9; padding:8px 12px; text-align:left; font-weight:600; color:#475569; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.pm-table td { padding:9px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.pm-table tr:last-child td { border-bottom:none; }
.pm-table tr:hover td { background:#fafafa; }
.pm-id    { font-family:monospace; font-size:12px; background:#f1f5f9; padding:2px 6px; border-radius:4px; color:#1e40af; }
.pm-none  { color:#94a3b8; font-style:italic; font-size:12px; }
.pm-price { color:#047857; font-weight:600; }
.pm-actions { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.pm-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:7px; font-size:13px; font-weight:500; cursor:pointer; border:none; text-decoration:none; }
.pm-btn-primary { background:#2563eb; color:#fff; }
.pm-btn-primary:hover { background:#1d4ed8; color:#fff; }
.pm-btn-success { background:#16a34a; color:#fff; }
.pm-btn-success:hover { background:#15803d; color:#fff; }
.pm-btn-warning { background:#d97706; color:#fff; }
.pm-btn-warning:hover { background:#b45309; color:#fff; }
.pm-btn-outline { background:#fff; color:#374151; border:1px solid #d1d5db; }
.pm-btn-outline:hover { background:#f9fafb; }
.pm-alert { padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:12px; }
.pm-alert-info    { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
.pm-alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
.pm-alert-warn    { background:#fffbeb; color:#92400e; border:1px solid #fde68a; }
.pm-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px; }
.pm-stat { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 18px; }
.pm-stat-val { font-size:24px; font-weight:700; color:#1e293b; }
.pm-stat-lbl { font-size:12px; color:#64748b; margin-top:2px; }
.pm-stat.ok .pm-stat-val  { color:#16a34a; }
.pm-stat.warn .pm-stat-val { color:#d97706; }
.pm-spinner { display:none; width:16px; height:16px; border:2px solid #e2e8f0; border-top-color:#2563eb; border-radius:50%; animation:spin .6s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.pm-log  { background:#0f172a; color:#94a3b8; font-family:monospace; font-size:12px; padding:12px; border-radius:8px; max-height:260px; overflow-y:auto; white-space:pre-wrap; display:none; margin-top:10px; }
</style>

<div class="pm-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
      <h2 style="margin:0;font-size:18px;font-weight:700;color:#1e293b;">🔗 UCRM Product Mapping</h2>
      <p style="margin:4px 0 0;font-size:13px;color:#64748b;">Link plugin plans to UCRM products so quotes reference real billing items.</p>
    </div>
    <div class="pm-actions" style="margin:0;">
      <button class="pm-btn pm-btn-outline" onclick="loadMapping()">🔄 Refresh</button>
      <?php if ($isAdmin): ?>
      <button class="pm-btn pm-btn-primary" onclick="pushProducts(1)">👁 Dry Run Push</button>
      <button class="pm-btn pm-btn-success" onclick="pushProducts(0)">⬆️ Push to UCRM</button>
      <button class="pm-btn pm-btn-warning" onclick="autoMap(0)">🤖 Auto-Map</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="pm-alert pm-alert-info" id="pm-notice">
    Loading mapping status… <span class="pm-spinner" id="pm-spin" style="display:inline-block;vertical-align:middle;"></span>
  </div>

  <div id="pm-summary" class="pm-summary" style="display:none;"></div>
  <div id="pm-tables"></div>
  <div class="pm-log" id="pm-log"></div>
</div>

<script>
const TK = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';
const API = '?page=api&action=';

function apiGet(action) {
  return fetch(API + action, {credentials:'same-origin', headers: { 'Authorization': 'Bearer ' + TK } }).then(r => r.json());
}

function showNotice(msg, type) {
  var el = document.getElementById('pm-notice');
  el.className = 'pm-alert pm-alert-' + (type || 'info');
  el.innerHTML = msg;
}

function log(txt) {
  var el = document.getElementById('pm-log');
  el.style.display = 'block';
  el.textContent += txt + '\n';
  el.scrollTop = el.scrollHeight;
}

function loadMapping() {
  document.getElementById('pm-spin').style.display = 'inline-block';
  showNotice('Loading mapping status…');

  Promise.all([
    apiGet('quote_setup_debug'),
    apiGet('ucrm_products')
  ]).then(function(results) {
    var debug    = results[0];
    var products = results[1];
    document.getElementById('pm-spin').style.display = 'none';

    if (!debug || debug.status === 'error') {
      showNotice('⚠️ ' + (debug && debug.message ? debug.message : 'Failed to load mapping.'), 'warn');
      return;
    }

    var d = debug.data || debug;
    var ucrmTotal = (products && products.data) ? (products.data.total || products.data.products && products.data.products.length || 0) : 0;

    // Summary cards
    var summaryHtml = '';
    var catalogs = [
      { key: 'subscription_plans', label: 'Starlink/Fiber Plans' },
      { key: 'kyc_packages',       label: 'Legacy Packages' },
      { key: 'kyc_devices',        label: 'Hardware / Devices' },
      { key: 'lte_packages',       label: 'LTE Packages' },
    ];
    var totalMapped = 0, totalActive = 0;
    catalogs.forEach(function(c) {
      var cat = d[c.key] || {};
      var active  = cat.active || cat.total || 0;
      var mapped  = cat.mapped_to_ucrm || 0;
      totalActive += active;
      totalMapped += mapped;
      var cls = (mapped >= active && active > 0) ? 'ok' : (mapped > 0 ? '' : 'warn');
      summaryHtml += '<div class="pm-stat ' + cls + '"><div class="pm-stat-val">' + mapped + ' / ' + active + '</div><div class="pm-stat-lbl">' + c.label + '</div></div>';
    });
    summaryHtml += '<div class="pm-stat ' + (totalMapped >= totalActive && totalActive > 0 ? 'ok' : 'warn') + '"><div class="pm-stat-val">' + ucrmTotal + '</div><div class="pm-stat-lbl">UCRM Products Total</div></div>';

    var summaryEl = document.getElementById('pm-summary');
    summaryEl.innerHTML = summaryHtml;
    summaryEl.style.display = 'grid';

    var allMapped = totalMapped >= totalActive && totalActive > 0;
    showNotice(allMapped
      ? '✅ All active plans are mapped to UCRM products. Quotes will reference real products.'
      : '⚠️ ' + (totalActive - totalMapped) + ' plan(s) not yet mapped. Run <strong>Push to UCRM</strong> or <strong>Auto-Map</strong> to link them.',
      allMapped ? 'success' : 'warn');

    // Render tables
    var html = '';
    catalogs.forEach(function(c) {
      var cat = d[c.key] || {};
      var items = cat.sample || cat.items || [];
      if (!items.length) return;
      html += buildTable(c.label, items);
    });

    // Also show UCRM products list
    if (products && products.data && products.data.products) {
      html += buildUcrmTable(products.data.products);
    }

    document.getElementById('pm-tables').innerHTML = html;
  }).catch(function(e) {
    document.getElementById('pm-spin').style.display = 'none';
    showNotice('❌ Error: ' + e.message, 'warn');
  });
}

function buildTable(title, items) {
  var allMapped = items.every(function(i) { return i.ucrm_product_id; });
  var badge = allMapped
    ? '<span class="pm-badge mapped">✅ All Mapped</span>'
    : '<span class="pm-badge unmapped">⚠️ Needs Mapping</span>';
  var rows = items.map(function(item) {
    var mapped = item.ucrm_product_id
      ? '<span class="pm-id">#' + item.ucrm_product_id + '</span>'
      : '<span class="pm-none">— not mapped</span>';
    var price = (item.customer_price || item.amount || item.price)
      ? '<span class="pm-price">$' + parseFloat(item.customer_price || item.amount || item.price || 0).toFixed(2) + '</span>'
      : '<span class="pm-none">—</span>';
    var active = (item.is_active !== undefined)
      ? (item.is_active ? '<span style="color:#16a34a;">●</span>' : '<span style="color:#94a3b8;">●</span>')
      : '';
    return '<tr><td>' + (item.id || '—') + '</td><td>' + (item.name || item.title || '—') + '</td><td>' + price + '</td><td>' + mapped + '</td><td>' + active + '</td></tr>';
  }).join('');
  return '<div class="pm-card"><div class="pm-card-hd"><h3>' + title + '</h3>' + badge + '</div>'
    + '<table class="pm-table"><thead><tr><th>Local ID</th><th>Name</th><th>Price</th><th>UCRM Product ID</th><th>Active</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function buildUcrmTable(products) {
  if (!products || !products.length) return '';
  var rows = products.slice(0, 30).map(function(p) {
    return '<tr><td><span class="pm-id">#' + p.id + '</span></td><td>' + (p.name || '—') + '</td>'
      + '<td><span class="pm-price">$' + parseFloat(p.price || 0).toFixed(2) + '</span></td>'
      + '<td>' + (p.unit || '—') + '</td></tr>';
  }).join('');
  return '<div class="pm-card"><div class="pm-card-hd"><h3>📦 UCRM Products (live)</h3>'
    + '<span class="pm-badge partial">' + products.length + ' products</span></div>'
    + '<table class="pm-table"><thead><tr><th>UCRM ID</th><th>Name</th><th>Price</th><th>Unit</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
}

function pushProducts(dryRun) {
  if (!dryRun && !confirm('This will create products in UCRM for all unmapped plans. Continue?')) return;
  showNotice((dryRun ? '👁 Dry-running' : '⬆️ Pushing') + ' products to UCRM…');
  document.getElementById('pm-log').textContent = '';
  apiGet('push_products_to_ucrm&dry_run=' + dryRun + '&force=0').then(function(res) {
    var d = res.data || res;
    var s = d.summary || {};
    log('--- ' + (dryRun ? 'DRY RUN' : 'LIVE PUSH') + ' RESULT ---');
    log('Created : ' + (s.total_created || 0));
    log('Skipped : ' + (s.total_skipped || 0) + ' (already mapped)');
    log('Failed  : ' + (s.total_failed || 0));
    if (d.details) {
      ['subscription_plans','kyc_packages','kyc_devices','lte_packages'].forEach(function(k) {
        var cat = d.details[k] || {};
        if ((cat.items || []).length) {
          log('\n[' + k + ']');
          (cat.items || []).forEach(function(i) {
            var r = i.result || {};
            log('  ' + i.name + ' ($' + i.price + ') → ' + (r.dry_run ? 'would create' : (r.id ? 'ID #' + r.id : 'FAILED: ' + JSON.stringify(r.error))));
          });
        }
      });
    }
    if (!dryRun) loadMapping();
    showNotice(dryRun
      ? '👁 Dry run complete — ' + (s.total_created || 0) + ' would be created. Check log below. Run <strong>Push to UCRM</strong> to apply.'
      : '✅ Push complete — ' + (s.total_created || 0) + ' created, ' + (s.total_skipped || 0) + ' skipped.',
      dryRun ? 'info' : 'success');
  }).catch(function(e) { showNotice('❌ ' + e.message, 'warn'); });
}

function autoMap(dryRun) {
  showNotice('🤖 Auto-mapping by name similarity…');
  apiGet('auto_map_products&dry_run=' + dryRun).then(function(res) {
    var d = res.data || res;
    log('--- AUTO-MAP ' + (dryRun ? 'DRY RUN' : 'APPLIED') + ' ---');
    log('Mappings found: ' + (d.mappings_found || 0));
    (d.mappings || []).forEach(function(m) {
      log('  [' + m.match_type + '] ' + m.local_name + ' → UCRM #' + m.ucrm_product_id + ' (' + m.ucrm_name + ')');
    });
    if (!dryRun) loadMapping();
    showNotice('🤖 Auto-map found ' + (d.mappings_found || 0) + ' matches (' + (dryRun ? 'dry run — not saved' : 'saved!') + ')', 'success');
  }).catch(function(e) { showNotice('❌ ' + e.message, 'warn'); });
}

// Load on page open
loadMapping();
</script>
