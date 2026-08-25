<?php
// Tab: lte_dashboard
// Extracted from public.php on 2026-03-15
?>
<?php
$apiTok = h($retailer['api_token'] ?? "");
$isAdm  = $isAdmin ? 'true' : 'false';
?>
<style>
/* ── LTE Design System ── */
.lte-grid{display:grid;gap:14px;}
.lte-grid-4{grid-template-columns:repeat(4,1fr);}
.lte-grid-3{grid-template-columns:repeat(3,1fr);}
.lte-grid-2{grid-template-columns:1fr 1fr;}
@media(max-width:1100px){.lte-grid-4{grid-template-columns:1fr 1fr;}.lte-grid-3{grid-template-columns:1fr 1fr;}}
@media(max-width:700px){.lte-grid-4,.lte-grid-3,.lte-grid-2{grid-template-columns:1fr;}}
.lte-card{background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);}
.lte-card-hd{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.lte-card-hd-title{font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.lte-card-hd-title i{color:var(--primary);}
.lte-card-bd{padding:16px 18px;}
/* Stat tiles */
.lte-stat{background:#fff;border:1px solid var(--border);border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;transition:.15s;}
.lte-stat:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateY(-1px);}
.lte-stat-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.lte-stat-val{font-size:22px;font-weight:800;letter-spacing:-.5px;line-height:1;}
.lte-stat-lbl{font-size:11px;color:var(--text-3);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:3px;}
/* Status pills */
.lte-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
.lte-pill.active  {background:#DCFCE7;color:#15803D;}
.lte-pill.suspended{background:#FEE2E2;color:#DC2626;}
.lte-pill.expired {background:#FEE2E2;color:#DC2626;}
.lte-pill.warning {background:#FEF3C7;color:#D97706;}
.lte-pill.critical{background:#FEE2E2;color:#DC2626;}
.lte-pill.ok      {background:#DCFCE7;color:#15803D;}
.lte-pill.no_plan {background:#F1F5F9;color:#64748B;}
.lte-pill.stock   {background:#fff0f0;color:#1D4ED8;}
.lte-pill.warehouse{background:#EDE9FE;color:#6D28D9;}
.lte-pill.magma-on{background:#DCFCE7;color:#15803D;}
.lte-pill.magma-off{background:#F1F5F9;color:#64748B;}
/* Table */
.lte-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.lte-tbl th{padding:10px 16px;font-size:10px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.6px;background:#F8FAFC;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
.lte-tbl td{padding:11px 16px;border-bottom:1px solid #F8FAFC;vertical-align:middle;}
.lte-tbl tr:last-child td{border-bottom:none;}
.lte-tbl tr:hover td{background:#FAFBFF;cursor:pointer;}
/* Urgency bar */
.lte-urg{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border);}
.lte-urg:last-child{border-bottom:none;}
.lte-urg-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
/* Renewal queue row */
.lte-qrow{display:grid;grid-template-columns:1fr 130px 90px 100px;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #F8FAFC;}
.lte-qrow:hover{background:#FAFBFF;}
.lte-qrow:last-child{border-bottom:none;}
/* Search bar */
.lte-search{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;}
.lte-search input,.lte-search select{border:1.5px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px;font-family:inherit;outline:none;background:#fff;transition:.15s;}
.lte-search input{flex:1;min-width:180px;}
.lte-search input:focus,.lte-search select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.08);}
/* Form fields */
.lte-field{margin-bottom:12px;}
.lte-field label{font-size:11px;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:5px;}
.lte-field input,.lte-field select,.lte-field textarea{width:100%;border:1.5px solid var(--border);border-radius:10px;padding:10px 13px;font-size:13px;font-family:inherit;outline:none;background:#FAFAFA;transition:.15s;}
.lte-field input:focus,.lte-field select:focus,.lte-field textarea:focus{border-color:var(--primary);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.08);}
/* Tabs inside LTE */
.lte-nav{display:flex;border-bottom:2px solid var(--border);margin-bottom:0;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.lte-nav::-webkit-scrollbar{height:0;}
.lte-nav-btn{flex-shrink:0;padding:12px 20px;font-size:13px;font-weight:600;color:var(--text-3);border:none;background:transparent;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;display:flex;align-items:center;gap:7px;white-space:nowrap;}
.lte-nav-btn:hover{color:var(--text-2);}
.lte-nav-btn.on{color:var(--primary);border-bottom-color:var(--primary);}
.lte-nav-btn .nb{background:var(--border);border-radius:20px;padding:1px 7px;font-size:10px;font-weight:700;color:var(--text-2);}
.lte-nav-btn.on .nb{background:rgba(37,99,235,.15);color:var(--primary);}
.lte-pane{display:none;}
.lte-pane.on{display:block;}
/* Progress bar */
.lte-prog{height:6px;background:var(--border);border-radius:3px;overflow:hidden;}
.lte-prog-fill{height:100%;border-radius:3px;transition:width .3s;}
/* Subscriber profile */
.lte-profile-grid{display:grid;grid-template-columns:300px 1fr;gap:16px;margin-top:0;}
@media(max-width:900px){.lte-profile-grid{grid-template-columns:1fr;}}
/* Expiry countdown */
.lte-countdown{display:inline-flex;align-items:center;gap:5px;font-weight:700;font-size:12px;}
/* Action buttons */
.lte-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-size:12px;font-weight:700;border:none;cursor:pointer;transition:.15s;text-decoration:none;}
.lte-btn:hover{filter:brightness(.93);text-decoration:none;}
.lte-btn.primary{background:var(--primary);color:#fff;}
.lte-btn.danger {background:#DC2626;color:#fff;}
.lte-btn.success{background:#16A34A;color:#fff;}
.lte-btn.ghost  {background:#fff;border:1.5px solid var(--border);color:var(--text-2);}
.lte-btn.sm     {padding:5px 12px;font-size:11px;}
/* Modal overlay */
.lte-modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:500;display:none;align-items:flex-start;justify-content:center;padding:max(16px,calc(env(safe-area-inset-top)+8px)) 16px 16px;overflow-y:auto;}
.lte-modal-bg.open{display:flex;}
.lte-modal{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.lte-modal-hd{padding:18px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.lte-modal-hd h3{font-size:15px;font-weight:800;margin:0;}
.lte-modal-bd{padding:20px;}
.lte-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:500px){.lte-form-row{grid-template-columns:1fr;}}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <div>
        <h2 style="font-size:18px;font-weight:800;color:var(--text);margin:0;display:flex;align-items:center;gap:8px;"><i class="bi bi-reception-4" style="color:var(--primary);"></i> DishNet 4G</h2>
        <p style="font-size:12px;color:var(--text-3);margin:3px 0 0;">Magma Core + Baicells — Subscriber Management</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <span id="lte-magma-chip" class="lte-pill magma-off" style="font-size:12px;padding:5px 12px;"><i class="bi bi-router"></i> Checking Magma…</span>
        <button onclick="lteShowModal('lte-new-sub-modal')" class="lte-btn primary"><i class="bi bi-person-plus-fill"></i> Register Subscriber</button>
    </div>
</div>

<!-- Sub-navigation -->
<div class="lte-nav" style="margin-bottom:16px;">
    <button class="lte-nav-btn on" onclick="lteTab('dash')"    id="ltenb_dash">  <i class="bi bi-speedometer2"></i>  Dashboard</button>
    <button class="lte-nav-btn"    onclick="lteTab('subs')"    id="ltenb_subs">  <i class="bi bi-people-fill"></i>   Subscribers <span class="nb" id="lte-sub-cnt">—</span></button>
    <button class="lte-nav-btn"    onclick="lteTab('queue')"   id="ltenb_queue"> <i class="bi bi-lightning-charge-fill"></i> Renewal Queue <span class="nb" id="lte-q-cnt">—</span></button>
    <button class="lte-nav-btn"    onclick="lteTab('sims')"    id="ltenb_sims">  <i class="bi bi-sim"></i>           SIM Inventory</button>
    <button class="lte-nav-btn"    onclick="lteTab('hw')"      id="ltenb_hw">    <i class="bi bi-router-fill"></i>   Hardware</button>
    <button class="lte-nav-btn"    onclick="lteTab('pkgs')"    id="ltenb_pkgs">  <i class="bi bi-layers-fill"></i>   Packages</button>
    <button class="lte-nav-btn"    onclick="lteTab('usage')"   id="ltenb_usage"> <i class="bi bi-activity"></i>      Usage Monitor</button>
    <button class="lte-nav-btn"    onclick="lteTab('infra')"   id="ltenb_infra"> <i class="bi bi-diagram-3-fill"></i> Infrastructure</button>
    <?php if($isAdmin): ?>
    <button class="lte-nav-btn"    onclick="lteTab('import')"  id="ltenb_import"><i class="bi bi-upload"></i>          Bulk Import</button>
    <?php endif; ?>
</div>

<!-- ═══════════════ DASHBOARD PANE ═══════════════ -->
<div class="lte-pane on" id="ltep_dash">
    <!-- Stat row -->
    <div class="lte-grid lte-grid-4" style="margin-bottom:14px;">
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#fff0f0;"><i class="bi bi-people-fill" style="color:#1D4ED8;"></i></div><div><div class="lte-stat-val" id="ds-total">—</div><div class="lte-stat-lbl">Total Subscribers</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#DCFCE7;"><i class="bi bi-wifi" style="color:#16A34A;"></i></div><div><div class="lte-stat-val" style="color:var(--green);" id="ds-active">—</div><div class="lte-stat-lbl">Active</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#FEE2E2;"><i class="bi bi-pause-circle-fill" style="color:#DC2626;"></i></div><div><div class="lte-stat-val" style="color:var(--red);" id="ds-suspended">—</div><div class="lte-stat-lbl">Suspended</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#FEF3C7;"><i class="bi bi-cash-stack" style="color:#D97706;"></i></div><div><div class="lte-stat-val" style="color:var(--orange);" id="ds-revenue">—</div><div class="lte-stat-lbl">This Month</div></div></div>
    </div>
    <div class="lte-grid lte-grid-3" style="margin-bottom:14px;">
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#FEE2E2;"><i class="bi bi-exclamation-circle-fill" style="color:#DC2626;"></i></div><div><div class="lte-stat-val" style="color:var(--red);" id="ds-expired">—</div><div class="lte-stat-lbl">Expired</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#FEF3C7;"><i class="bi bi-clock-fill" style="color:#D97706;"></i></div><div><div class="lte-stat-val" style="color:var(--orange);" id="ds-urgent">—</div><div class="lte-stat-lbl">Expiring ≤ 3 Days</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#EDE9FE;"><i class="bi bi-calendar-check" style="color:#7C3AED;"></i></div><div><div class="lte-stat-val" id="ds-today-ren">—</div><div class="lte-stat-lbl">Renewals Today</div></div></div>
    </div>

    <!-- Two columns: Expiry breakdown + Quick renewal -->
    <div class="lte-grid lte-grid-2">
        <div class="lte-card">
            <div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-bar-chart-fill"></i> Inventory Status</span></div>
            <div id="ds-inv-body" style="padding:8px 0;">
                <div style="text-align:center;padding:28px;color:var(--text-3);">Loading…</div>
            </div>
        </div>
        <div class="lte-card">
            <div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-lightning-charge-fill"></i> Needs Attention</span><a href="#" onclick="lteTab('queue');return false;" style="font-size:12px;color:var(--primary);font-weight:600;">View All →</a></div>
            <div id="ds-queue-preview" style="max-height:260px;overflow-y:auto;">
                <div style="text-align:center;padding:28px;color:var(--text-3);">Loading…</div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ SUBSCRIBERS PANE ═══════════════ -->
<div class="lte-pane" id="ltep_subs">
    <div class="lte-search">
        <input id="sub-q" type="text" placeholder="Name, phone, IMSI…" onkeydown="if(event.key==='Enter')loadSubs()">
        <select id="sub-status" onchange="loadSubs()">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="inactive">Inactive</option>
        </select>
        <button onclick="loadSubs()" class="lte-btn primary"><i class="bi bi-search"></i> Search</button>
    </div>
    <div class="lte-card" style="overflow:hidden;">
        <div id="subs-body">
            <div style="text-align:center;padding:40px;color:var(--text-3);"><i class="bi bi-arrow-repeat" style="font-size:28px;display:block;margin-bottom:10px;animation:spin 1s linear infinite;"></i>Loading…</div>
        </div>
    </div>
</div>

<!-- ═══════════════ RENEWAL QUEUE PANE ═══════════════ -->
<div class="lte-pane" id="ltep_queue">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <div style="font-size:13px;color:var(--text-3);">Subscribers expiring within 7 days or already expired — sorted by urgency</div>
        <button onclick="loadQueue()" class="lte-btn ghost sm"><i class="bi bi-arrow-repeat"></i> Refresh</button>
    </div>
    <div class="lte-card" style="overflow:hidden;">
        <div id="queue-body">
            <div style="text-align:center;padding:40px;color:var(--text-3);"><i class="bi bi-arrow-repeat" style="font-size:28px;display:block;margin-bottom:10px;animation:spin 1s linear infinite;"></i>Loading…</div>
        </div>
    </div>
</div>

<!-- ═══════════════ SIM INVENTORY PANE ═══════════════ -->
<div class="lte-pane" id="ltep_sims">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <div class="lte-search" style="margin:0;flex:1;">
            <input id="sim-q" type="text" placeholder="IMSI, MSISDN, ICCID, batch…" onkeydown="if(event.key==='Enter')loadSims()">
            <select id="sim-status" onchange="loadSims()">
                <option value="">All Status</option>
                <option value="stock">In Stock</option>
                <option value="assigned">Assigned</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="retired">Retired</option>
            </select>
            <button onclick="loadSims()" class="lte-btn primary sm"><i class="bi bi-search"></i></button>
        </div>
        <?php if($isAdmin): ?><button onclick="lteShowModal('lte-new-sim-modal')" class="lte-btn primary"><i class="bi bi-plus-circle"></i> Add SIM</button><?php endif; ?>
    </div>
    <div class="lte-card" style="overflow:hidden;">
        <div id="sim-counts" style="display:flex;gap:1px;background:var(--border);border-bottom:1px solid var(--border);">
            <div style="flex:1;background:#fff;padding:12px 16px;text-align:center;"><div style="font-size:16px;font-weight:800;" id="sc-total">—</div><div style="font-size:10px;color:var(--text-3);font-weight:600;text-transform:uppercase;">Total</div></div>
            <div style="flex:1;background:#fff;padding:12px 16px;text-align:center;"><div style="font-size:16px;font-weight:800;color:var(--primary);" id="sc-stock">—</div><div style="font-size:10px;color:var(--text-3);font-weight:600;text-transform:uppercase;">In Stock</div></div>
            <div style="flex:1;background:#fff;padding:12px 16px;text-align:center;"><div style="font-size:16px;font-weight:800;color:var(--green);" id="sc-active">—</div><div style="font-size:10px;color:var(--text-3);font-weight:600;text-transform:uppercase;">Active</div></div>
            <div style="flex:1;background:#fff;padding:12px 16px;text-align:center;"><div style="font-size:16px;font-weight:800;color:var(--red);" id="sc-suspended">—</div><div style="font-size:10px;color:var(--text-3);font-weight:600;text-transform:uppercase;">Suspended</div></div>
        </div>
        <div id="sims-body"></div>
    </div>
</div>

<!-- ═══════════════ HARDWARE PANE ═══════════════ -->
<div class="lte-pane" id="ltep_hw">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
        <div class="lte-search" style="margin:0;flex:1;">
            <input id="hw-q" type="text" placeholder="Serial, model…" onkeydown="if(event.key==='Enter')loadHw()">
            <select id="hw-type" onchange="loadHw()">
                <option value="">All Types</option>
                <option value="mifi">MiFi / Indoor</option>
                <option value="outdoor_cpe">Outdoor CPE</option>
            </select>
            <select id="hw-status" onchange="loadHw()">
                <option value="">All Status</option>
                <option value="warehouse">Warehouse</option>
                <option value="deployed">Deployed</option>
                <option value="faulty">Faulty</option>
                <option value="returned">Returned</option>
            </select>
            <button onclick="loadHw()" class="lte-btn primary sm"><i class="bi bi-search"></i></button>
        </div>
        <?php if($isAdmin): ?><button onclick="lteShowModal('lte-new-hw-modal')" class="lte-btn primary"><i class="bi bi-plus-circle"></i> Add Device</button><?php endif; ?>
    </div>
    <div class="lte-card" style="overflow:hidden;">
        <div id="hw-body"></div>
    </div>
</div>

<!-- ═══════════════ PACKAGES PANE ═══════════════ -->
<div class="lte-pane" id="ltep_pkgs">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <div style="font-size:13px;color:var(--text-3);">Data plans offered on DishNet 4G network</div>
        <?php if($isAdmin): ?><button onclick="lteShowModal('lte-new-pkg-modal')" class="lte-btn primary"><i class="bi bi-plus-circle"></i> Add Package</button><?php endif; ?>
    </div>
    <div id="pkgs-body" class="lte-grid lte-grid-3"></div>
</div>

<!-- ═══════════════ USAGE MONITOR PANE ═══════════════ -->
<div class="lte-pane" id="ltep_usage">
    <!-- Top bar: cache status + refresh buttons -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span style="font-size:13px;font-weight:700;color:var(--text);">Subscriber Usage Monitor</span>
            <span id="um-cache-age" style="font-size:11px;color:var(--text-3);background:var(--surface);border:1px solid var(--border);border-radius:6px;padding:3px 10px;">Cache: not loaded</span>
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="doRefreshHealth()" class="lte-btn ghost sm" id="um-refresh-health-btn"><i class="bi bi-diagram-3"></i> Refresh Infrastructure</button>
            <button onclick="doRefreshUsage()" class="lte-btn primary sm" id="um-refresh-btn"><i class="bi bi-arrow-repeat"></i> Pull from Magma</button>
        </div>
    </div>

    <!-- Alert: Magma not configured -->
    <div id="um-magma-warn" style="display:none;background:#FEF3C7;border:1px solid #FDE68A;border-radius:12px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:10px;">
        <i class="bi bi-exclamation-triangle-fill" style="color:#D97706;font-size:18px;flex-shrink:0;"></i>
        <div><div style="font-size:13px;font-weight:700;color:#92400E;">Magma not configured</div>
        <div style="font-size:12px;color:#92400E;">Go to Settings → Magma DishNet 4G Configuration to connect your Orchestrator. Usage data will come from Magma once connected.</div></div>
    </div>

    <!-- Stats row -->
    <div class="lte-grid lte-grid-4" style="margin-bottom:14px;" id="um-stats-row">
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#DCFCE7;"><i class="bi bi-wifi" style="color:#16A34A;"></i></div><div><div class="lte-stat-val" style="color:var(--green);" id="um-active">—</div><div class="lte-stat-lbl">Active in Magma</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#FEE2E2;"><i class="bi bi-wifi-off" style="color:#DC2626;"></i></div><div><div class="lte-stat-val" style="color:var(--red);" id="um-inactive">—</div><div class="lte-stat-lbl">Inactive in Magma</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#FEF3C7;"><i class="bi bi-exclamation-triangle-fill" style="color:#D97706;"></i></div><div><div class="lte-stat-val" style="color:var(--orange);" id="um-alerts">—</div><div class="lte-stat-lbl">Needs Attention</div></div></div>
        <div class="lte-stat"><div class="lte-stat-icon" style="background:#EDE9FE;"><i class="bi bi-speedometer" style="color:#7C3AED;"></i></div><div><div class="lte-stat-val" id="um-avg-lat">—</div><div class="lte-stat-lbl">Avg Latency (ms)</div></div></div>
    </div>

    <!-- Filters -->
    <div class="lte-search" style="margin-bottom:12px;">
        <input id="um-q" type="text" placeholder="Name, IMSI, MSISDN…" onkeydown="if(event.key==='Enter')loadUsage()">
        <select id="um-filter" onchange="loadUsage()">
            <option value="">All Subscribers</option>
            <option value="active">Magma Active</option>
            <option value="inactive">Magma Inactive</option>
            <option value="mismatch">State Mismatch</option>
            <option value="unreachable">Unreachable (0% probes)</option>
            <option value="no_imsi">No IMSI Assigned</option>
        </select>
        <button onclick="loadUsage()" class="lte-btn primary sm"><i class="bi bi-search"></i></button>
    </div>

    <!-- Table -->
    <div class="lte-card" style="overflow:hidden;">
        <div id="um-body">
            <div style="text-align:center;padding:48px;color:var(--text-3);">
                <i class="bi bi-activity" style="font-size:40px;display:block;margin-bottom:12px;opacity:.2;"></i>
                <div style="font-size:14px;font-weight:600;">Click "Pull from Magma" to load live usage data</div>
                <div style="font-size:12px;margin-top:4px;">Or view cached data from the last sync</div>
                <button onclick="loadUsage()" class="lte-btn ghost" style="margin-top:16px;"><i class="bi bi-database"></i> Load Cached Data</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ INFRASTRUCTURE PANE ═══════════════ -->
<div class="lte-pane" id="ltep_infra">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <div>
            <div style="font-size:15px;font-weight:800;color:var(--text);">Network Infrastructure</div>
            <div style="font-size:12px;color:var(--text-3);margin-top:2px;">Magma Access Gateways + Baicells eNodeBs</div>
        </div>
        <div style="display:flex;gap:8px;">
            <button onclick="doRefreshHealth()" class="lte-btn primary sm" id="infra-refresh-btn"><i class="bi bi-arrow-repeat"></i> Refresh</button>
        </div>
    </div>

    <div id="infra-cached-at" style="font-size:11px;color:var(--text-3);margin-bottom:12px;"></div>

    <!-- Gateways section -->
    <div style="font-size:11px;font-weight:800;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">
        <i class="bi bi-server" style="margin-right:5px;"></i>Access Gateways (AGW)
    </div>
    <div id="infra-gw-body" class="lte-grid lte-grid-3" style="margin-bottom:18px;">
        <div style="text-align:center;padding:32px;color:var(--text-3);grid-column:1/-1;">
            <i class="bi bi-server" style="font-size:32px;display:block;margin-bottom:8px;opacity:.2;"></i>
            Click Refresh to load gateway status
        </div>
    </div>

    <!-- eNodeBs section -->
    <div style="font-size:11px;font-weight:800;color:var(--text-3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">
        <i class="bi bi-broadcast-pin" style="margin-right:5px;"></i>Baicells eNodeBs (Base Stations)
    </div>
    <div class="lte-card" style="overflow:hidden;">
        <div id="infra-enb-body">
            <div style="text-align:center;padding:32px;color:var(--text-3);">
                Click Refresh to load eNodeB status
            </div>
        </div>
    </div>

    <!-- ── DB & Migrations Diagnostic Card ─────────────────────────── -->
    <div class="lte-card" style="margin-top:14px;">
        <div class="lte-card-hd">
            <span class="lte-card-hd-title"><i class="bi bi-database-gear"></i> Database & Migrations</span>
            <div style="display:flex;gap:8px;">
                <button onclick="lteRunDiag()" class="lte-btn ghost sm"><i class="bi bi-search"></i> Run Diag</button>
                <button onclick="lteRunMigrations()" class="lte-btn primary sm" id="lte-migr-btn"><i class="bi bi-play-circle"></i> Run Migrations</button>
                <button onclick="lteReseed()" class="lte-btn sm" style="background:var(--purple);color:#fff;" id="lte-reseed-btn"><i class="bi bi-database-fill-add"></i> Seed BlueCard Data</button>
            </div>
        </div>
        <div class="lte-card-bd" id="lte-diag-result">
            <div style="font-size:12px;color:var(--text-3);">Click Run Diag to check database status and table health.</div>
        </div>
    </div>

    <!-- ── Reset & Fresh Sync Card ────────────────────────────────── -->
    <div class="lte-card" style="margin-top:14px;border:2px solid #DC2626;">
        <div class="lte-card-hd" style="background:#FEF2F2;">
            <span class="lte-card-hd-title"><i class="bi bi-arrow-counterclockwise" style="color:#DC2626;"></i> <span style="color:#DC2626;">Reset & Fresh Sync</span></span>
        </div>
        <div class="lte-card-bd">
            <p style="font-size:13px;color:var(--text-2);margin:0 0 12px;">Wipe all synced LTE data (subscribers, SIMs, renewals, packages, usages) and reset sync cursors. The next <code>cron_lte_sync</code> run will pull everything fresh from BlueCard.</p>
            <div style="background:#FEF3C7;border:1px solid #F59E0B;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400E;">
                <strong>Warning:</strong> This clears all LTE data in the plugin. Manually-created subscribers not synced from BlueCard will be lost. The fresh sync takes 2-5 minutes.
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <button onclick="lteResetSync()" class="lte-btn danger" id="lte-reset-btn"><i class="bi bi-arrow-counterclockwise"></i> Reset & Fresh Sync</button>
                <span id="lte-reset-status" style="font-size:12px;color:var(--text-3);"></span>
            </div>
        </div>
    </div>

</div>

<!-- ═══════════════ BULK IMPORT PANE ═══════════════ -->
<?php if($isAdmin): ?>
<div class="lte-pane" id="ltep_import">

    <!-- ══ BlueCard SQL Upload Section ══════════════════════════════════════ -->
    <div class="lte-card" style="margin-bottom:18px;border:2px solid #6d28d9;">
      <div class="lte-card-hd" style="background:linear-gradient(135deg,#1e1b4b,#312e81);">
        <span class="lte-card-hd-title" style="color:#fff;font-size:14px;">
          <i class="bi bi-database-fill-up" style="color:#a78bfa;"></i>
          BlueCard MySQL Dump — Upload &amp; Import
        </span>
        <span style="font-size:11px;color:#a5b4fc;font-weight:600;">Admin only · Step 1 of 3</span>
      </div>
      <div class="lte-card-bd">

        <!-- Step indicators -->
        <div style="display:flex;gap:0;margin-bottom:18px;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;">
          <div id="bc-step1-ind" style="flex:1;padding:10px;text-align:center;font-size:12px;font-weight:700;background:#312e81;color:#fff;">1. Upload SQL</div>
          <div id="bc-step2-ind" style="flex:1;padding:10px;text-align:center;font-size:12px;font-weight:600;background:#f8fafc;color:#94a3b8;">2. Parse to Staging</div>
          <div id="bc-step3-ind" style="flex:1;padding:10px;text-align:center;font-size:12px;font-weight:600;background:#f8fafc;color:#94a3b8;">3. Migrate to LTE</div>
        </div>

        <!-- STEP 1: File upload -->
        <div id="bc-step1">
          <div id="bc-dropzone"
            onclick="document.getElementById('bc-file-input').click()"
            ondragover="event.preventDefault();this.style.borderColor='#7c3aed';this.style.background='#f5f3ff';"
            ondragleave="this.style.borderColor='#c4b5fd';this.style.background='#faf5ff';"
            ondrop="event.preventDefault();this.style.borderColor='#c4b5fd';this.style.background='#faf5ff';bcHandleDrop(event.dataTransfer.files[0]);"
            style="border:2px dashed #c4b5fd;border-radius:12px;padding:36px 24px;text-align:center;cursor:pointer;background:#faf5ff;transition:.2s;margin-bottom:14px;">
            <i class="bi bi-file-earmark-code" style="font-size:40px;color:#7c3aed;display:block;margin-bottom:10px;"></i>
            <div style="font-size:15px;font-weight:800;color:#1e293b;">Drop BlueCard SQL dump here</div>
            <div style="font-size:12px;color:#64748b;margin-top:4px;">or click to browse — supports files up to 500MB</div>
            <div style="font-size:11px;color:#7c3aed;margin-top:8px;font-weight:600;" id="bc-filename-lbl"></div>
          </div>
          <input type="file" id="bc-file-input" accept=".sql,.gz,.zip" style="display:none;" onchange="bcHandleDrop(this.files[0])">

          <!-- Progress bar -->
          <div id="bc-progress-wrap" style="display:none;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:#374151;margin-bottom:6px;">
              <span id="bc-progress-label">Uploading…</span>
              <span id="bc-progress-pct">0%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:99px;height:10px;">
              <div id="bc-progress-bar" style="height:100%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:99px;width:0%;transition:width .2s;"></div>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;" id="bc-progress-detail"></div>
          </div>

          <!-- Already uploaded files -->
          <div id="bc-uploaded-files" style="margin-bottom:14px;"></div>

          <button onclick="bcLoadUploads()" class="lte-btn ghost sm" style="margin-bottom:8px;">
            <i class="bi bi-arrow-clockwise"></i> Check existing uploads
          </button>
        </div>

        <!-- STEP 2: Parse to staging -->
        <div id="bc-step2" style="display:none;">
          <div style="background:#ede9fe;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
            <div style="font-size:13px;font-weight:700;color:#4c1d95;margin-bottom:4px;">
              📦 File ready: <span id="bc-ready-filename" style="color:#7c3aed;"></span>
            </div>
            <div style="font-size:12px;color:#6d28d9;">
              Next: parse the SQL dump and load it into staging tables for review before migration.
            </div>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button onclick="bcRunStaging()" class="lte-btn primary" id="bc-stage-btn">
              <i class="bi bi-play-circle-fill"></i> Parse SQL &amp; Load Staging
            </button>
            <button onclick="bcStep(1)" class="lte-btn ghost sm">← Back</button>
          </div>
          <div id="bc-stage-result" style="margin-top:12px;"></div>
        </div>

        <!-- STEP 3: Review + migrate -->
        <div id="bc-step3" style="display:none;">
          <div id="bc-staging-summary" style="margin-bottom:14px;"></div>
          <div style="background:#f0fdf4;border-radius:10px;padding:14px 16px;margin-bottom:14px;border:1px solid #bbf7d0;">
            <div style="font-size:13px;font-weight:700;color:#065f46;margin-bottom:6px;">✅ Staging loaded — ready to migrate</div>
            <div style="font-size:12px;color:#047857;">
              Review the counts above. When ready, click Migrate to copy subscribers, SIMs, packages and subscriptions into the live LTE tables.
              <strong>Existing records will not be overwritten.</strong>
            </div>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button onclick="bcRunMigrate()" class="lte-btn primary" id="bc-migrate-btn">
              <i class="bi bi-arrow-right-circle-fill"></i> Migrate to LTE Tables
            </button>
            <button onclick="bcStep(2)" class="lte-btn ghost sm">← Back</button>
          </div>
          <div id="bc-migrate-result" style="margin-top:12px;"></div>
        </div>

      </div>
    </div>

    <script>
    (function(){
      const TK = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1]||'';
      const API = '?page=api&action=';
      const CHUNK_SIZE = 1 * 1024 * 1024; // 1MB per chunk
      let bcCurrentFile = null;

      window.bcHandleDrop = function(file) {
        if (!file) return;
        bcCurrentFile = file;
        const mb = (file.size / 1048576).toFixed(1);
        document.getElementById('bc-filename-lbl').textContent = file.name + ' (' + mb + ' MB)';
        // Auto-start upload
        bcUploadFile(file);
      };

      window.bcUploadFile = async function(file) {
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const mb = (file.size / 1048576).toFixed(1);

        // Show upload UI immediately
        const dropzone = document.getElementById('bc-dropzone');
        dropzone.style.background = '#f0f9ff';
        dropzone.style.borderColor = '#3b82f6';
        dropzone.style.cursor = 'default';

        // Build inline progress UI inside dropzone
        dropzone.innerHTML = `
          <div style="text-align:center;padding:8px 0;">
            <div style="font-size:28px;margin-bottom:10px;">📤</div>
            <div style="font-size:14px;font-weight:800;color:#1e293b;margin-bottom:4px;" id="bc-up-file">${file.name}</div>
            <div style="font-size:12px;color:#64748b;margin-bottom:16px;">${mb} MB · ${totalChunks} chunks</div>
            <div style="background:#e2e8f0;border-radius:99px;height:14px;margin:0 auto 10px;max-width:420px;overflow:hidden;">
              <div id="bc-bar" style="height:100%;background:linear-gradient(90deg,#3b82f6,#6366f1);border-radius:99px;width:0%;transition:width .15s;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;max-width:420px;margin:0 auto 12px;font-size:12px;font-weight:700;">
              <span id="bc-pct" style="color:#3b82f6;">0%</span>
              <span id="bc-speed" style="color:#64748b;">Starting…</span>
              <span id="bc-eta"  style="color:#64748b;"></span>
            </div>
            <div style="font-size:11px;color:#94a3b8;" id="bc-chunk-lbl">Chunk 0 / ${totalChunks}</div>
          </div>`;

        const bar   = document.getElementById('bc-bar');
        const pct   = document.getElementById('bc-pct');
        const speed = document.getElementById('bc-speed');
        const eta   = document.getElementById('bc-eta');
        const clbl  = document.getElementById('bc-chunk-lbl');

        const startTime = Date.now();
        let lastResult = null;

        for (let i = 0; i < totalChunks; i++) {
          const chunkStart = i * CHUNK_SIZE;
          const chunk = file.slice(chunkStart, chunkStart + CHUNK_SIZE);
          const bytes = await chunk.arrayBuffer();

          // base64 encode in batches to avoid stack overflow on large chunks
          const uint8 = new Uint8Array(bytes);
          let b64 = '';
          for (let j = 0; j < uint8.length; j += 8192) {
            b64 += btoa(String.fromCharCode(...uint8.subarray(j, j + 8192)));
          }

          const form = new FormData();
          form.append('filename',     file.name);
          form.append('chunk_index',  i);
          form.append('total_chunks', totalChunks);
          form.append('chunk_data',   b64);

          let resp, data;
          try {
            resp = await fetch(API + 'lte_upload_chunk', {
          credentials:'same-origin',
          method: 'POST',
              headers: { 'Authorization': 'Bearer ' + TK },
              body: form,
            });
            data = await resp.json();
          } catch(fetchErr) {
            dropzone.innerHTML = '<div style="padding:20px;text-align:center;color:#dc2626;font-weight:700;">❌ Network error on chunk ' + (i+1) + ': ' + fetchErr.message + '<br><small style="font-weight:400;color:#94a3b8;">Check your connection and try again.</small></div>';
            return;
          }

          const progress = Math.round(((i + 1) / totalChunks) * 100);
          bar.style.width = progress + '%';
          pct.textContent = progress + '%';

          const elapsed  = (Date.now() - startTime) / 1000 || 0.1;
          const mbDone   = ((i + 1) * CHUNK_SIZE / 1048576);
          const spd      = (mbDone / elapsed).toFixed(1);
          const remSecs  = Math.max(0, Math.round((totalChunks - i - 1) * CHUNK_SIZE / 1048576 / spd));
          speed.textContent = spd + ' MB/s';
          eta.textContent   = remSecs > 0 ? '~' + (remSecs < 60 ? remSecs + 's' : Math.ceil(remSecs/60) + 'm') + ' left' : '';
          clbl.textContent  = 'Chunk ' + (i+1) + ' / ' + totalChunks;

          if (data.status === 'error') {
            dropzone.innerHTML = '<div style="padding:20px;text-align:center;color:#dc2626;font-weight:700;">❌ Server error: ' + data.message + '</div>';
            return;
          }
          if (data.data && data.data.complete) { lastResult = data.data; break; }
        }

        // Done
        dropzone.innerHTML = `
          <div style="text-align:center;padding:16px 0;">
            <div style="font-size:36px;margin-bottom:8px;">✅</div>
            <div style="font-size:15px;font-weight:800;color:#15803d;">Upload Complete!</div>
            <div style="font-size:12px;color:#64748b;margin-top:4px;">${lastResult ? lastResult.filename + ' · ' + lastResult.size_mb + ' MB' : file.name}</div>
          </div>`;

        setTimeout(() => {
          document.getElementById('bc-ready-filename').textContent = lastResult ? lastResult.filename : file.name;
          window._bcUploadedFilename = lastResult ? lastResult.filename : file.name;
          bcStep(2);
        }, 1000);
      };

      window.bcLoadUploads = async function() {
        const resp = await fetch(API + 'lte_list_uploads', {credentials:'same-origin', headers: {'Authorization':'Bearer '+TK} });
        const data = await resp.json();
        const container = document.getElementById('bc-uploaded-files');
        const files = (data.data || {}).files || [];
        const staging = (data.data || {}).staging || {};

        if (!files.length && !Object.values(staging).some(v => v > 0)) {
          container.innerHTML = '<div style="font-size:12px;color:#94a3b8;padding:8px 0;">No uploaded SQL files found.</div>';
          return;
        }

        let html = '';

        // Show staging counts if populated
        const stagingTotal = Object.values(staging).reduce((a,b) => a + (b||0), 0);
        if (stagingTotal > 0) {
          html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;margin-bottom:10px;">';
          html += '<div style="font-size:12px;font-weight:700;color:#065f46;margin-bottom:8px;">📦 Staging tables already loaded:</div>';
          html += '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
          for (const [tbl, cnt] of Object.entries(staging)) {
            if (cnt !== null) {
              const label = tbl.replace('bluecard_','').replace('_',' ');
              html += '<div style="background:#fff;border-radius:8px;padding:6px 12px;font-size:12px;">';
              html += '<div style="font-weight:700;color:#0f766e;">' + cnt.toLocaleString() + '</div>';
              html += '<div style="color:#64748b;font-size:10px;text-transform:capitalize;">' + label + '</div></div>';
            }
          }
          html += '</div>';
          html += '<button onclick="bcStep(3)" class="lte-btn primary sm" style="margin-top:10px;"><i class="bi bi-arrow-right-circle"></i> Skip to Migration →</button>';
          html += '</div>';
        }

        if (files.length) {
          html += '<div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;">Uploaded files:</div>';
          files.forEach(f => {
            html += '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:6px;">';
            html += '<i class="bi bi-file-earmark-code" style="color:#7c3aed;font-size:18px;flex-shrink:0;"></i>';
            html += '<div style="flex:1;"><div style="font-size:13px;font-weight:700;color:#1e293b;">' + f.filename + '</div>';
            html += '<div style="font-size:11px;color:#64748b;">' + f.size_mb + ' MB · ' + f.modified + '</div></div>';
            html += '<button onclick="bcSelectFile('' + f.filename + '')" class="lte-btn primary sm">Use this file</button>';
            html += '<button onclick="bcDeleteFile('' + f.filename + '')" class="lte-btn ghost sm" style="color:#dc2626;"><i class="bi bi-trash"></i></button>';
            html += '</div>';
          });
        }
        container.innerHTML = html;
      };

      window.bcSelectFile = function(filename) {
        window._bcUploadedFilename = filename;
        document.getElementById('bc-ready-filename').textContent = filename;
        bcStep(2);
      };

      window.bcDeleteFile = async function(filename) {
        if (!confirm('Delete ' + filename + '?')) return;
        await fetch(API + 'lte_delete_upload', {
          credentials:'same-origin',
          method: 'POST',
          headers: {'Authorization':'Bearer '+TK,'Content-Type':'application/json'},
          body: JSON.stringify({filename})
        });
        bcLoadUploads();
      };

      window.bcRunStaging = async function() {
        const filename = window._bcUploadedFilename;
        if (!filename) { alert('No file selected'); return; }
        const btn = document.getElementById('bc-stage-btn');
        btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Parsing SQL… (may take 1-2 min)';
        const result = document.getElementById('bc-stage-result');
        result.innerHTML = '<div style="font-size:12px;color:#7c3aed;">⏳ Parsing SQL dump and loading staging tables…</div>';
        try {
          const resp = await fetch(API + 'lte_import_to_staging', {
          credentials:'same-origin',
          method: 'POST',
            headers: {'Authorization':'Bearer '+TK,'Content-Type':'application/json'},
            body: JSON.stringify({filename})
          });
          const data = await resp.json();
          if (data.status === 'error') {
            result.innerHTML = '<div style="background:#fef2f2;color:#dc2626;padding:12px;border-radius:8px;font-size:13px;">❌ ' + data.message + '</div>';
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-play-circle-fill"></i> Parse SQL & Load Staging';
            return;
          }
          const d = data.data || {};
          let summary = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-top:8px;">';
          for (const [k,v] of Object.entries(d)) {
            if (typeof v === 'number') {
              summary += '<div style="background:#ede9fe;border-radius:8px;padding:10px;text-align:center;">';
              summary += '<div style="font-size:18px;font-weight:800;color:#6d28d9;">' + v.toLocaleString() + '</div>';
              summary += '<div style="font-size:10px;color:#7c3aed;font-weight:600;text-transform:capitalize;">' + k.replace(/_/g,' ') + '</div></div>';
            }
          }
          summary += '</div>';
          document.getElementById('bc-staging-summary').innerHTML = '<div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:8px;">Staging loaded successfully:</div>' + summary;
          result.innerHTML = '';
          bcStep(3);
        } catch(e) {
          result.innerHTML = '<div style="background:#fef2f2;color:#dc2626;padding:12px;border-radius:8px;font-size:13px;">❌ ' + e.message + '</div>';
          btn.disabled = false; btn.innerHTML = '<i class="bi bi-play-circle-fill"></i> Parse SQL & Load Staging';
        }
      };

      window.bcRunMigrate = async function() {
        if (!confirm('Migrate BlueCard data to LTE tables? Existing subscribers will NOT be overwritten.')) return;
        const btn = document.getElementById('bc-migrate-btn');
        btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Migrating…';
        try {
          const resp = await fetch(API + 'lte_migrate_from_staging', {
          credentials:'same-origin',
          method: 'POST',
            headers: {'Authorization':'Bearer '+TK,'Content-Type':'application/json'},
            body: JSON.stringify({dry_run: false})
          });
          const data = await resp.json();
          const result = document.getElementById('bc-migrate-result');
          if (data.status === 'error') {
            result.innerHTML = '<div style="background:#fef2f2;color:#dc2626;padding:12px;border-radius:8px;font-size:13px;">❌ ' + data.message + '</div>';
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-right-circle-fill"></i> Migrate to LTE Tables';
            return;
          }
          const d = data.data || {};
          let html = '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px;">';
          html += '<div style="font-size:14px;font-weight:800;color:#065f46;margin-bottom:10px;">✅ Migration complete!</div>';
          html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;">';
          for (const [k,v] of Object.entries(d)) {
            if (typeof v === 'number') {
              html += '<div style="background:#fff;border-radius:8px;padding:10px;text-align:center;">';
              html += '<div style="font-size:18px;font-weight:800;color:#059669;">' + v.toLocaleString() + '</div>';
              html += '<div style="font-size:10px;color:#047857;font-weight:600;text-transform:capitalize;">' + k.replace(/_/g,' ') + '</div></div>';
            }
          }
          html += '</div></div>';
          result.innerHTML = html;
          btn.innerHTML = '✅ Migration done!';
        } catch(e) {
          document.getElementById('bc-migrate-result').innerHTML = '<div style="background:#fef2f2;color:#dc2626;padding:12px;border-radius:8px;font-size:13px;">❌ ' + e.message + '</div>';
          btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-right-circle-fill"></i> Migrate to LTE Tables';
        }
      };

      window.bcStep = function(n) {
        [1,2,3].forEach(i => {
          document.getElementById('bc-step'+i).style.display = (i===n) ? 'block' : 'none';
          const ind = document.getElementById('bc-step'+i+'-ind');
          if (ind) {
            ind.style.background = i === n ? '#312e81' : (i < n ? '#059669' : '#f8fafc');
            ind.style.color      = i <= n ? '#fff' : '#94a3b8';
            if (i < n) ind.textContent = '✓ ' + ind.textContent.replace('✓ ','');
          }
        });
      };

      // Auto-load existing uploads on panel open
      document.addEventListener('DOMContentLoaded', function() {
        bcLoadUploads();
      });
      // Also trigger when lte import tab is shown
      const origLteTab = window.lteTab;
      if (origLteTab) {
        window.lteTab = function(id) {
          origLteTab(id);
          if (id === 'import') setTimeout(bcLoadUploads, 100);
        };
      }
    })();
    </script>
    <!-- ══ End BlueCard SQL Upload ══════════════════════════════════════════ -->

    <div style="display:grid;grid-template-columns:1fr 360px;gap:16px;align-items:start;">

        <!-- Left: upload + preview -->
        <div>
            <div class="lte-card" style="margin-bottom:14px;">
                <div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-upload"></i> Upload CSV</span></div>
                <div class="lte-card-bd">
                    <p style="font-size:13px;color:var(--text-2);margin:0 0 12px;">Upload a CSV file with your existing LTE subscribers. Required columns: <code>name</code>, <code>phone</code>. Optional: <code>imsi</code>, <code>msisdn</code>, <code>iccid</code>, <code>email</code>, <code>address</code>, <code>id_type</code>, <code>id_number</code>, <code>package</code>, <code>expires_at</code>, <code>started_at</code>, <code>amount_paid</code>, <code>payment_method</code>, <code>lat</code>, <code>lon</code>, <code>notes</code>.</p>
                    <div style="border:2px dashed var(--border);border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:.15s;background:var(--surface);" id="imp-dropzone" onclick="document.getElementById('imp-file').click()">
                        <i class="bi bi-file-earmark-spreadsheet" style="font-size:36px;color:var(--primary);display:block;margin-bottom:8px;"></i>
                        <div style="font-size:14px;font-weight:700;color:var(--text);">Click to select CSV file</div>
                        <div style="font-size:12px;color:var(--text-3);margin-top:4px;">or drag and drop here</div>
                        <div id="imp-filename" style="font-size:12px;color:var(--primary);margin-top:8px;font-weight:600;"></div>
                    </div>
                    <input type="file" id="imp-file" accept=".csv,.txt" style="display:none;" onchange="impLoadFile(this)">
                </div>
            </div>

            <!-- Preview table -->
            <div class="lte-card" id="imp-preview-card" style="display:none;overflow:hidden;">
                <div class="lte-card-hd">
                    <span class="lte-card-hd-title"><i class="bi bi-table"></i> Preview <span id="imp-row-count" style="font-weight:400;color:var(--text-3);"></span></span>
                    <div style="display:flex;gap:8px;">
                        <button onclick="impClear()" class="lte-btn ghost sm"><i class="bi bi-x"></i> Clear</button>
                        <button onclick="impRun()" class="lte-btn primary sm" id="imp-run-btn"><i class="bi bi-upload"></i> Import All</button>
                    </div>
                </div>
                <div id="imp-preview-body" style="overflow-x:auto;max-height:400px;overflow-y:auto;"></div>
            </div>

            <!-- Results -->
            <div id="imp-results" style="margin-top:12px;"></div>
        </div>

        <!-- Right: instructions + template download -->
        <div>
            <div class="lte-card" style="margin-bottom:12px;">
                <div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-info-circle-fill"></i> How to Use</span></div>
                <div class="lte-card-bd" style="font-size:12px;color:var(--text-2);line-height:1.7;">
                    <div style="font-weight:700;color:var(--text);margin-bottom:6px;">Step 1 — Prepare your CSV</div>
                    <p style="margin:0 0 10px;">Export your existing subscriber list from your current system. The first row must be column headers.</p>
                    <div style="font-weight:700;color:var(--text);margin-bottom:6px;">Step 2 — Upload & Preview</div>
                    <p style="margin:0 0 10px;">Upload the file. You'll see a preview of parsed data before importing.</p>
                    <div style="font-weight:700;color:var(--text);margin-bottom:6px;">Step 3 — Import</div>
                    <p style="margin:0 0 10px;">Click Import. Duplicates (same phone, IMSI, or MSISDN) are automatically skipped.</p>
                    <div style="background:#FEF3C7;border-radius:8px;padding:10px;font-size:11px;color:#92400E;">
                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>Duplicate detection:</strong> rows with matching phone, IMSI, or MSISDN to existing subscribers are skipped — not overwritten.
                    </div>
                </div>
            </div>

            <div class="lte-card">
                <div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-download"></i> CSV Template</span></div>
                <div class="lte-card-bd">
                    <p style="font-size:12px;color:var(--text-2);margin:0 0 12px;">Download a template with all supported columns pre-filled with example data.</p>
                    <button onclick="impDownloadTemplate()" class="lte-btn ghost" style="width:100%;justify-content:center;"><i class="bi bi-file-earmark-arrow-down"></i> Download Template CSV</button>
                </div>
            </div>

            <div class="lte-card" style="margin-top:12px;">
                <div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-layers-fill"></i> Your Packages</span></div>
                <div class="lte-card-bd" style="font-size:12px;">
                    <p style="color:var(--text-3);margin:0 0 8px;">Use these exact names in the <code>package</code> column:</p>
                    <div id="imp-pkg-list" style="color:var(--text-2);font-family:monospace;line-height:1.8;">Loading…</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════ SUBSCRIBER PROFILE (overlay) ═══════════════ -->
<div id="lte-sub-profile" style="display:none;margin-top:0;"></div>

<!-- ══════════════════════════════════════════
     MODALS
══════════════════════════════════════════ -->

<!-- New Subscriber Modal -->
<div class="lte-modal-bg" id="lte-new-sub-modal">
<div class="lte-modal">
    <div class="lte-modal-hd">
        <h3><i class="bi bi-person-plus-fill" style="color:var(--primary);margin-right:6px;"></i>Register LTE Subscriber</h3>
        <button onclick="lteHideModal('lte-new-sub-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button>
    </div>
    <div class="lte-modal-bd">
        <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border);">Personal Details</div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Full Name *</label><input id="ns-name" type="text" placeholder="Customer full name"></div>
            <div class="lte-field"><label>Phone *</label><input id="ns-phone" type="tel" placeholder="+211…"></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Email</label><input id="ns-email" type="email" placeholder="Optional"></div>
            <div class="lte-field"><label>ID Type</label><select id="ns-idtype"><option value="">Select…</option><option>National ID</option><option>Passport</option><option>Alien Card</option><option>Employee ID</option></select></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>ID Number</label><input id="ns-idnum" placeholder="ID reference"></div>
            <div class="lte-field"><label>Address / Location</label><input id="ns-addr" placeholder="Area or street"></div>
        </div>
        <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin:16px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--border);">SIM / Network</div>
        <div class="lte-form-row">
            <div class="lte-field"><label>IMSI</label><input id="ns-imsi" type="text" placeholder="e.g. IMSI001010000000001" style="font-family:monospace;"></div>
            <div class="lte-field"><label>MSISDN (Phone on SIM)</label><input id="ns-msisdn" type="text" placeholder="+211…" style="font-family:monospace;"></div>
        </div>
        <div style="font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin:16px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--border);">First Subscription (optional)</div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Data Package</label><select id="ns-pkg"><option value="">No package yet</option></select></div>
            <div class="lte-field"><label>Amount Collected</label><input id="ns-amt" type="number" placeholder="0.00" step="0.01"></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Payment Method</label><select id="ns-pmeth"><option value="cash">Cash</option></select></div>
            <div class="lte-field"><label>GPS Lat</label><input id="ns-lat" type="number" step="0.000001" placeholder="optional"></div>
        </div>
        <div class="lte-field"><label>Notes</label><textarea id="ns-notes" rows="2" placeholder="Any additional notes…"></textarea></div>
        <div id="ns-status" style="min-height:18px;font-size:12px;color:var(--text-3);margin-bottom:10px;"></div>
        <button onclick="doCreateSubscriber()" class="lte-btn primary" style="width:100%;justify-content:center;padding:12px;"><i class="bi bi-check-circle-fill"></i> Register Subscriber</button>
    </div>
</div>
</div>

<!-- Renew Modal -->
<div class="lte-modal-bg" id="lte-renew-modal">
<div class="lte-modal">
    <div class="lte-modal-hd">
        <h3><i class="bi bi-arrow-repeat" style="color:var(--green);margin-right:6px;"></i>Renew Subscription</h3>
        <button onclick="lteHideModal('lte-renew-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button>
    </div>
    <div class="lte-modal-bd">
        <div id="renew-sub-name" style="font-size:15px;font-weight:700;margin-bottom:14px;color:var(--text);"></div>
        <input type="hidden" id="renew-sub-id">
        <div class="lte-field"><label>Data Package *</label><select id="renew-pkg"></select></div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Amount Collected (USD) *</label><input id="renew-amt" type="number" step="0.01" placeholder="0.00"></div>
            <div class="lte-field"><label>Payment Method</label><select id="renew-pmeth"><option value="cash">Cash</option></select></div>
        </div>
        <div id="renew-status" style="min-height:18px;font-size:12px;color:var(--text-3);margin-bottom:10px;"></div>
        <button onclick="doRenew()" class="lte-btn success" style="width:100%;justify-content:center;padding:12px;"><i class="bi bi-check-circle-fill"></i> Confirm Renewal</button>
    </div>
</div>
</div>

<!-- Add SIM Modal -->
<div class="lte-modal-bg" id="lte-new-sim-modal">
<div class="lte-modal">
    <div class="lte-modal-hd">
        <h3><i class="bi bi-sim" style="color:var(--primary);margin-right:6px;"></i>Add SIM Card</h3>
        <button onclick="lteHideModal('lte-new-sim-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button>
    </div>
    <div class="lte-modal-bd">
        <div class="lte-form-row">
            <div class="lte-field"><label>IMSI *</label><input id="sim-imsi" type="text" placeholder="IMSI001010000000001" style="font-family:monospace;"></div>
            <div class="lte-field"><label>MSISDN *</label><input id="sim-msisdn" type="text" placeholder="+211…" style="font-family:monospace;"></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>ICCID</label><input id="sim-iccid" type="text" placeholder="20-digit SIM serial" style="font-family:monospace;"></div>
            <div class="lte-field"><label>Batch / Purchase Ref</label><input id="sim-batch" type="text" placeholder="e.g. BATCH-2024-01"></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Auth Key (Ki) — hex</label><input id="sim-key" type="text" placeholder="32 hex chars" style="font-family:monospace;"></div>
            <div class="lte-field"><label>Auth OPc — hex</label><input id="sim-opc" type="text" placeholder="32 hex chars" style="font-family:monospace;"></div>
        </div>
        <div class="lte-field"><label>Notes</label><input id="sim-notes" type="text" placeholder="Optional"></div>
        <div id="sim-status" style="min-height:18px;font-size:12px;margin-bottom:10px;"></div>
        <button onclick="doCreateSim()" class="lte-btn primary" style="width:100%;justify-content:center;padding:12px;"><i class="bi bi-plus-circle"></i> Add SIM to Inventory</button>
    </div>
</div>
</div>

<!-- Add Hardware Modal -->
<div class="lte-modal-bg" id="lte-new-hw-modal">
<div class="lte-modal">
    <div class="lte-modal-hd">
        <h3><i class="bi bi-router-fill" style="color:var(--primary);margin-right:6px;"></i>Add Hardware Device</h3>
        <button onclick="lteHideModal('lte-new-hw-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button>
    </div>
    <div class="lte-modal-bd">
        <div class="lte-form-row">
            <div class="lte-field"><label>Type *</label><select id="hw-type-new"><option value="mifi">MiFi / Indoor Router</option><option value="outdoor_cpe">Outdoor CPE</option></select></div>
            <div class="lte-field"><label>Brand</label><input id="hw-brand" type="text" value="Baicells"></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Model</label><input id="hw-model" type="text" placeholder="e.g. Nova 227"></div>
            <div class="lte-field"><label>Serial Number *</label><input id="hw-serial" type="text" placeholder="Device serial" style="font-family:monospace;"></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>MAC Address</label><input id="hw-mac" type="text" placeholder="AA:BB:CC:DD:EE:FF" style="font-family:monospace;"></div>
            <div class="lte-field"><label>Purchase Cost (USD)</label><input id="hw-cost" type="number" step="0.01" placeholder="0.00"></div>
        </div>
        <div class="lte-field"><label>Notes</label><input id="hw-notes" type="text" placeholder="Optional"></div>
        <div id="hw-new-status" style="min-height:18px;font-size:12px;margin-bottom:10px;"></div>
        <button onclick="doCreateHw()" class="lte-btn primary" style="width:100%;justify-content:center;padding:12px;"><i class="bi bi-plus-circle"></i> Add to Inventory</button>
    </div>
</div>
</div>

<!-- Add Package Modal -->
<div class="lte-modal-bg" id="lte-new-pkg-modal">
<div class="lte-modal">
    <div class="lte-modal-hd">
        <h3><i class="bi bi-layers-fill" style="color:var(--primary);margin-right:6px;"></i>Create Data Package</h3>
        <button onclick="lteHideModal('lte-new-pkg-modal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-3);">×</button>
    </div>
    <div class="lte-modal-bd">
        <div class="lte-form-row">
            <div class="lte-field"><label>Package Name *</label><input id="pk-name" type="text" placeholder="e.g. Monthly 20GB"></div>
            <div class="lte-field"><label>Type</label><select id="pk-type"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option><option value="unlimited">Unlimited</option><option value="corporate">Corporate</option></select></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Duration (Days) *</label><input id="pk-days" type="number" value="30"></div>
            <div class="lte-field"><label>Data (GB, 0=unlimited)</label><input id="pk-gb" type="number" step="0.1" value="0"></div>
        </div>
        <div class="lte-form-row">
            <div class="lte-field"><label>Speed Cap (Mbps, 0=uncapped)</label><input id="pk-speed" type="number" step="0.1" value="0"></div>
            <div class="lte-field"><label>Price (USD) *</label><input id="pk-price" type="number" step="0.01" value="0"></div>
        </div>
        <div class="lte-field"><label>Magma sub_profile name</label><input id="pk-profile" type="text" placeholder="e.g. monthly_20gb" style="font-family:monospace;"></div>
        <div class="lte-field"><label>Description</label><textarea id="pk-desc" rows="2" placeholder="Optional description shown to agents"></textarea></div>
        <div id="pk-status" style="min-height:18px;font-size:12px;margin-bottom:10px;"></div>
        <button onclick="doCreatePackage()" class="lte-btn primary" style="width:100%;justify-content:center;padding:12px;"><i class="bi bi-plus-circle"></i> Create Package</button>
    </div>
</div>
</div>

<style>@keyframes spin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}</style>

<script>
(function(){
var TK  = (document.cookie.match(/hybrid_token=([^;]+)/)||[])[1] || '<?= $apiTok ?>';
var ADM = <?= $isAdmin ? 'true' : 'false' ?>;
var HDR = {'Authorization':'Bearer '+TK,'Content-Type':'application/json','Accept':'application/json'};
function ap(u,q){return fetch('?page=api&action='+u+(q||''),{credentials:'same-origin',headers:HDR}).then(function(r){
    if(!r.ok||!(r.headers.get('content-type')||'').includes('json')){throw new Error('Server returned '+r.status+' (not JSON). Please refresh the page.');}
    return r.json();
});}
function pp(u,b){return fetch('?page=api&action='+u,{
          credentials:'same-origin',
          method:'POST',headers:HDR,body:JSON.stringify(b)}).then(function(r){
    if(!r.ok||!(r.headers.get('content-type')||'').includes('json')){throw new Error('Server returned '+r.status+' (not JSON). Please refresh the page.');}
    return r.json();
});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function money(v){return '$'+parseFloat(v||0).toFixed(2);}
function fmt(d){return d?String(d).substring(0,10):'—';}

/* ── TAB ── */

/* ── DB Diagnostics & Migration Runner ────────────────── */
window.lteRunDiag = async function() {
    const el = document.getElementById('lte-diag-result');
    el.innerHTML = '<div style="font-size:12px;color:var(--text-3);">⏳ Checking…</div>';
    try {
        const d = await ap('lte_diag');
        let html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-bottom:10px;">';
        // LTE table status
        for (const [tbl, val] of Object.entries(d.lte_tables || {})) {
            const ok = typeof val === 'number';
            html += '<div style="background:' + (ok?'#f0fdf4':'#fef2f2') + ';border-radius:8px;padding:8px 10px;">';
            html += '<div style="font-size:11px;font-weight:700;color:' + (ok?'#15803d':'#dc2626') + ';">' + (ok ? '✅' : '❌') + ' ' + tbl.replace('lte_','') + '</div>';
            html += '<div style="font-size:10px;color:#64748b;">' + (ok ? val + ' rows' : 'MISSING') + '</div></div>';
        }
        html += '</div>';
        // Migration stats
        const applied = (d.migrations_applied || []).length;
        const total   = d.migration_files || 0;
        html += '<div style="font-size:11px;color:var(--text-3);margin-bottom:6px;">Migrations: ' + applied + ' / ' + total + ' applied · Dir: ' + (d.migrations_dir_exists ? '✅' : '❌') + ' ' + (d.migrations_dir || '') + '</div>';
        if (d.errors && d.errors.length) {
            html += '<div style="background:#fef2f2;border-radius:8px;padding:8px 10px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#dc2626;margin-bottom:4px;">⚠️ Errors:</div>';
            d.errors.forEach(e => { html += '<div style="font-size:11px;color:#dc2626;">' + e + '</div>'; });
            html += '</div>';
        }
        el.innerHTML = html;
    } catch(e) {
        el.innerHTML = '<div style="color:#dc2626;font-size:12px;">❌ Diag failed: ' + e.message + '</div>';
    }
};

window.lteRunMigrations = async function() {
    if (!confirm('Run all pending database migrations now? This is safe and idempotent.')) return;
    const btn = document.getElementById('lte-migr-btn');
    const el  = document.getElementById('lte-diag-result');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Running…';
    el.innerHTML  = '<div style="font-size:12px;color:var(--text-3);">⏳ Running migrations…</div>';
    try {
        const data = await pp('lte_run_migrations', {});
        const results = (data.data || {}).results || [];
        let html = '<div style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:8px;">Migration results:</div>';
        results.forEach(r => {
            const ok = r.status === 'ok';
            const sk = r.status === 'skipped';
            const col = ok ? '#15803d' : sk ? '#92400e' : '#dc2626';
            const bg  = ok ? '#f0fdf4' : sk ? '#fefce8' : '#fef2f2';
            const ico = ok ? '✅' : sk ? '⚠️' : '❌';
            html += '<div style="background:' + bg + ';border-radius:6px;padding:6px 10px;margin-bottom:4px;font-size:11px;color:' + col + ';">';
            html += ico + ' <strong>' + r.file + '</strong>';
            if (r.error) html += ' — ' + r.error;
            if (r.duration_ms) html += ' (' + r.duration_ms + 'ms)';
            html += '</div>';
        });
        if (!results.length) html += '<div style="font-size:12px;color:#15803d;">✅ All migrations already applied — nothing to run.</div>';
        el.innerHTML = html;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Done — Run Diag';
        btn.onclick = lteRunDiag;
    } catch(e) {
        el.innerHTML = '<div style="color:#dc2626;font-size:12px;">❌ Failed: ' + e.message + '</div>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle"></i> Run Migrations';
    }
};

window.lteReseed = async function() {
    if (!confirm('Seed BlueCard data into LTE tables?\n\nThis will load:\n• 19 packages\n• 7,376 SIMs\n• 6,624 subscribers\n• 6,624 subscriptions\n• 6 renewals\n\nExisting data will NOT be overwritten.')) return;
    var btn = document.getElementById('lte-reseed-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Seeding…';
    var el = document.getElementById('lte-diag-result');
    if(el) el.innerHTML = '<div style="font-size:12px;color:var(--purple);padding:8px;">⏳ Loading BlueCard data… this may take 10-20 seconds…</div>';
    try {
        var d = await pp('lte_reseed', {});
        var html = '<div style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:10px;padding:14px;margin-top:8px;">';
        html += '<div style="font-size:13px;font-weight:800;color:#6d28d9;margin-bottom:8px;">🎉 Seed Results</div>';
        html += '<div style="display:grid;gap:4px;">';
        if (d.status === 'success') {
            Object.entries(d.data).forEach(function([file, result]) {
                var resultStr = String(result);
                var ok   = resultStr.indexOf('seeded') !== -1;
                var skip = resultStr.indexOf('skipped') !== -1;
                var icon = ok ? '✅' : (skip ? '⏭' : (file.startsWith('_') ? 'ℹ️' : '❌'));
                html += '<div style="font-size:12px;">' + icon + ' <strong>' + file + '</strong>: ' + esc(resultStr) + '</div>';
            });
        } else {
            html += '<div style="color:#dc2626;">❌ ' + esc(d.message||'Error') + '</div>';
        }
        html += '</div></div>';
        if(el) el.innerHTML = html;
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Done — Run Diag';
        btn.onclick = lteRunDiag;
        window._subLoaded = false; // force subscriber list reload
    } catch(e) {
        if(el) el.innerHTML = '<div style="color:#dc2626;font-size:12px;">❌ ' + e.message + '</div>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-database-fill-add"></i> Seed BlueCard Data';
    }
};

window.lteResetSync = async function() {
    if (!confirm('⚠️ RESET ALL LTE DATA?\n\nThis will:\n• Delete ALL subscribers, SIMs, renewals, packages, usages from plugin\n• Reset sync cursors to zero\n• Clear all JSON export files\n\nThe next cron_lte_sync run (within 5 min) will pull everything fresh from BlueCard.\n\nAre you sure?')) return;
    if (!confirm('FINAL CONFIRMATION\n\nType OK to proceed with the full LTE data reset.')) return;
    var btn = document.getElementById('lte-reset-btn');
    var status = document.getElementById('lte-reset-status');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Resetting…';
    status.textContent = 'Clearing all LTE tables…';
    status.style.color = 'var(--orange)';
    try {
        var d = await pp('lte_reset_sync', {});
        if (d.status === 'success') {
            var r = d.data || {};
            var msg = '✅ Reset complete! Tables cleared: ';
            var cleared = r.tables_cleared || {};
            var parts = [];
            for (var t in cleared) { parts.push(t.replace('lte_','') + ' (' + cleared[t] + ')'); }
            msg += parts.join(', ');
            status.innerHTML = '<span style="color:var(--green);font-weight:700;">' + msg + '</span><br><span style="color:var(--text-3);">Fresh sync will start within 5 minutes via cron.</span>';
            btn.innerHTML = '<i class="bi bi-check-circle"></i> Reset Done';
            btn.style.background = 'var(--green)';
            // Force reload of all tabs
            window._subLoaded = false;
            window._qLoaded = false;
            window._simLoaded = false;
            window._pkgLoaded = false;
            window._usageLoaded = false;
            window._infraLoaded = false;
            // Refresh dashboard stats
            setTimeout(function(){ loadDash(); }, 1000);
        } else {
            status.innerHTML = '<span style="color:var(--red);">❌ ' + esc(d.message || 'Error') + '</span>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Reset & Fresh Sync';
        }
    } catch(e) {
        status.innerHTML = '<span style="color:var(--red);">❌ ' + e.message + '</span>';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Reset & Fresh Sync';
    }
};

window.lteTab = function(id){
    document.querySelectorAll('.lte-nav-btn').forEach(b=>b.className='lte-nav-btn');
    document.querySelectorAll('.lte-pane').forEach(p=>p.className='lte-pane');
    var nb=document.getElementById('ltenb_'+id), pn=document.getElementById('ltep_'+id);
    if(nb)nb.className='lte-nav-btn on';
    if(pn)pn.className='lte-pane on';
    if(id==='subs'  && !window._subLoaded)  {loadSubs();window._subLoaded=true;}
    if(id==='queue' && !window._qLoaded)    {loadQueue();window._qLoaded=true;}
    if(id==='sims'  && !window._simLoaded)  {loadSims();window._simLoaded=true;}
    if(id==='hw'    && !window._hwLoaded)   {loadHw();window._hwLoaded=true;}
    if(id==='pkgs'  && !window._pkgLoaded)  {loadPackages();window._pkgLoaded=true;}
    if(id==='usage' && !window._usageLoaded){loadUsage();window._usageLoaded=true;}
    if(id==='infra' && !window._infraLoaded){loadInfra();window._infraLoaded=true;}
    if(id==='import'){} // no auto-load needed
    // Hide profile if switching away
    document.getElementById('lte-sub-profile').style.display='none';
};

/* ── MODAL ── */
window.lteShowModal = function(id){document.getElementById(id).className='lte-modal-bg open'; if(id==='lte-new-sub-modal')loadPkgsIntoSelect('ns-pkg'); if(id==='lte-renew-modal')loadPkgsIntoSelect('renew-pkg');}
window.lteHideModal = function(id){document.getElementById(id).className='lte-modal-bg';}

/* ── EXPIRY PILL ── */
function expiryPill(status, days){
    var map={expired:['Expired','expired'],today:['Expires Today','critical'],critical:['Critical','critical'],warning:['Warning','warning'],ok:['Active','ok'],no_plan:['No Plan','no_plan']};
    var m=map[status]||['Unknown','no_plan'];
    var label=m[0]; if(status==='ok'&&days!==null&&days!==undefined) label=days+'d left';
    return '<span class="lte-pill '+m[1]+'">'+label+'</span>';
}

/* ── DASHBOARD STATS ── */
function loadDash(){
    ap('lte_stats').then(function(d){
        if(d.status!=='success'){
            var chip=document.getElementById('lte-magma-chip');
            chip.className='lte-pill magma-off';
            chip.innerHTML='<i class="bi bi-router"></i> Magma Unknown';
            return;
        }
        var s=d.data;
        document.getElementById('ds-total').textContent   = s.total_subscribers;
        document.getElementById('ds-active').textContent  = s.active;
        document.getElementById('ds-suspended').textContent= s.suspended;
        document.getElementById('ds-revenue').textContent = money(s.month_revenue);
        document.getElementById('ds-expired').textContent = s.expired;
        document.getElementById('ds-urgent').textContent  = s.expiring_urgent;
        document.getElementById('ds-today-ren').textContent= s.today_renewals;
        document.getElementById('lte-sub-cnt').textContent = s.total_subscribers;

        // Magma chip
        var chip=document.getElementById('lte-magma-chip');
        chip.className='lte-pill '+(s.magma_connected?'magma-on':'magma-off');
        chip.innerHTML='<i class="bi bi-router'+(s.magma_connected?'-fill':'')+'"></i> Magma '+(s.magma_connected?'Connected':'Offline');

        // Inventory card
        var inv='';
        var rows=[
            ['bi-sim','SIM in Stock',s.sim_stock,'var(--primary)'],
            ['bi-router-fill','Hardware in Warehouse',s.hw_warehouse,'var(--purple)'],
            ['bi-people-fill','No SIM Assigned',s.no_sim,'var(--orange)'],
            ['bi-reception-4','Active Subscribers',s.active,'var(--green)'],
            ['bi-layers-fill','Data Packages',s.total_packages,'var(--text-2)'],
        ];
        rows.forEach(function(r){
            inv+='<div class="lte-urg"><div class="lte-urg-dot" style="background:'+r[3]+';"></div>';
            inv+='<i class="bi '+r[0]+'" style="color:'+r[3]+';width:18px;"></i>';
            inv+='<div style="flex:1;font-size:13px;font-weight:500;">'+r[1]+'</div>';
            inv+='<div style="font-size:16px;font-weight:800;color:'+r[3]+';">'+r[2]+'</div></div>';
        });
        document.getElementById('ds-inv-body').innerHTML=inv;
    });

    // Load renewal queue preview (first 5)
    ap('lte_renewal_queue','&days=7').then(function(d){
        if(d.status!=='success')return;
        var q=d.data||[];
        document.getElementById('lte-q-cnt').textContent=q.length;
        if(!q.length){document.getElementById('ds-queue-preview').innerHTML='<div style="text-align:center;padding:28px;color:var(--green);"><i class="bi bi-check-circle-fill" style="font-size:28px;display:block;margin-bottom:8px;"></i>No urgent renewals</div>';return;}
        var h='';
        q.slice(0,6).forEach(function(s){
            h+='<div class="lte-qrow" style="cursor:pointer;" onclick="showSubProfile('+s.id+')">';
            h+='<div><div style="font-size:13px;font-weight:700;">'+esc(s.name)+'</div>';
            h+='<div style="font-size:11px;color:var(--text-3);">'+esc(s.msisdn||s.phone||'')+'</div></div>';
            h+=expiryPill(s._expiry_status, s._days_remaining);
            h+='<div style="font-size:12px;color:var(--text-3);">'+fmt(s._subscription?s._subscription.expires_at:'')+'</div>';
            h+='<button onclick="event.stopPropagation();openRenew('+s.id+',\''+esc(s.name)+'\')" class="lte-btn success sm"><i class="bi bi-arrow-repeat"></i> Renew</button>';
            h+='</div>';
        });
        document.getElementById('ds-queue-preview').innerHTML=h;
    }).catch(function(){
        var chip=document.getElementById('lte-magma-chip');
        chip.className='lte-pill magma-off';
        chip.innerHTML='<i class="bi bi-router"></i> Magma Unknown';
    });
}

/* ── SUBSCRIBER LIST ── */
(function(){
    var _subPage = 1;
    var _subPerPage = 50;

    window._subPageReset = function(){ _subPage = 1; };

    function renderSubPager(total, page, pages) {
        var start = (page - 1) * _subPerPage + 1;
        var end   = Math.min(page * _subPerPage, total);
        var h = '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid var(--border);background:#FAFBFF;flex-wrap:wrap;gap:8px;">';
        h += '<div style="font-size:12px;color:var(--text-3);">Showing <strong>' + start + '–' + end + '</strong> of <strong>' + total + '</strong> subscribers</div>';
        h += '<div style="display:flex;gap:6px;align-items:center;">';
        h += '<button onclick="window._subGo('+Math.max(1,page-1)+')" class="lte-btn ghost sm" '+(page<=1?'disabled':'')+'>‹ Prev</button>';
        for (var p = Math.max(1, page-2); p <= Math.min(pages, page+2); p++) {
            h += '<button onclick="window._subGo('+p+')" class="lte-btn sm" style="'+(p===page?'background:var(--primary);color:#fff;':'background:#fff;border:1.5px solid var(--border);color:var(--text-2);')+' min-width:34px;">'+p+'</button>';
        }
        h += '<button onclick="window._subGo('+Math.min(pages,page+1)+')" class="lte-btn ghost sm" '+(page>=pages?'disabled':'')+'>Next ›</button>';
        h += '</div></div>';
        return h;
    }

    window._subGo = function(p){ _subPage = p; _doLoadSubs(); };

    function _doLoadSubs() {
        var q  = document.getElementById('sub-q').value;
        var st = document.getElementById('sub-status').value;
        var qs = '&search=' + encodeURIComponent(q) + '&status=' + encodeURIComponent(st)
               + '&page=' + _subPage + '&per_page=' + _subPerPage;
        document.getElementById('subs-body').innerHTML = '<div style="text-align:center;padding:36px;color:var(--text-3);"><i class="bi bi-arrow-repeat" style="font-size:24px;display:block;margin-bottom:8px;animation:spin 1s linear infinite;"></i>Loading…</div>';
        ap('lte_subscribers', qs).then(function(d) {
            if (d.status !== 'success') { document.getElementById('subs-body').innerHTML = '<div style="padding:24px;color:var(--red);">Error loading subscribers</div>'; return; }
            var rows  = d.data.data || [];
            var total = d.data.total || 0;
            var pages = d.data.pages || 1;
            var page  = d.data.page  || 1;
            // Only update nav badge with total when no filter active (otherwise shows filtered count which is misleading)
            var isFiltered = (document.getElementById('sub-status').value !== '') || (document.getElementById('sub-search') && document.getElementById('sub-search').value.trim() !== '');
            if (!isFiltered) document.getElementById('lte-sub-cnt').textContent = total;
            if (!rows.length) { document.getElementById('subs-body').innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-3);"><i class="bi bi-people" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3;"></i>No subscribers found</div>'; return; }
            var h = '<table class="lte-tbl"><thead><tr><th>Subscriber</th><th>MSISDN</th><th>Plan</th><th>Expiry</th><th>Status</th><th></th></tr></thead><tbody>';
            rows.forEach(function(s) {
                var pkg = s._package ? s._package.name : 'No plan';
                h += '<tr onclick="showSubProfile(' + s.id + ')">';
                h += '<td><div style="font-weight:700;">' + esc(s.name) + '</div><div style="font-size:11px;color:var(--text-3);font-family:monospace;">' + esc(s.imsi || 'No IMSI') + '</div></td>';
                h += '<td style="font-family:monospace;font-size:12px;">' + esc(s.msisdn || '—') + '</td>';
                h += '<td style="font-size:12px;">' + esc(pkg) + '</td>';
                h += '<td style="font-size:12px;">' + fmt(s._subscription ? s._subscription.expires_at : '') + '</td>';
                h += '<td>' + expiryPill(s._expiry_status, s._days_remaining) + '</td>';
                h += '<td><button onclick="event.stopPropagation();openRenew(' + s.id + ',\'' + esc(s.name) + '\')" class="lte-btn success sm"><i class="bi bi-arrow-repeat"></i> Renew</button></td>';
                h += '</tr>';
            });
            h += '</tbody></table>';
            if (pages > 1) { h += renderSubPager(total, page, pages); }
            document.getElementById('subs-body').innerHTML = h;
        });
    }

    window.loadSubs = function() { _subPage = 1; _doLoadSubs(); };
})();

/* ── SUBSCRIBER PROFILE ── */
window.showSubProfile = function(id){
    var profDiv=document.getElementById('lte-sub-profile');
    profDiv.style.display='block';
    profDiv.innerHTML='<div style="padding:40px;text-align:center;color:var(--text-3);"><i class="bi bi-arrow-repeat" style="font-size:28px;display:block;margin-bottom:10px;animation:spin 1s linear infinite;"></i>Loading profile…</div>';
    window.scrollTo({top:profDiv.offsetTop-80,behavior:'smooth'});
    ap('lte_subscriber','&id='+id).then(function(d){
        if(d.status!=='success'){profDiv.innerHTML='<div style="padding:24px;color:var(--red);">Error: '+(d.message||'Failed')+'</div>';return;}
        var s=d.data;
        var sub=s._subscription||{};
        var pkg=s._package||{};
        var mag=s._magma_state;
        var renewals=s._renewals||[];
        var h='';
        h+='<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">';
        h+='<div style="font-size:15px;font-weight:800;color:var(--text);display:flex;align-items:center;gap:8px;"><i class="bi bi-person-badge-fill" style="color:var(--primary);"></i>'+esc(s.name)+'</div>';
        h+='<button onclick="document.getElementById(\'lte-sub-profile\').style.display=\'none\'" class="lte-btn ghost sm"><i class="bi bi-x"></i> Close</button>';
        h+='</div>';

        h+='<div class="lte-profile-grid">';

        // LEFT: client card
        h+='<div style="display:flex;flex-direction:column;gap:12px;">';
        h+='<div class="lte-card">';
        h+='<div style="background:linear-gradient(160deg,#0F172A,#1E3A5F);padding:18px;border-radius:14px 14px 0 0;color:#fff;">';
        h+='<div style="width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;margin-bottom:10px;">'+esc(s.name.substring(0,1).toUpperCase())+'</div>';
        h+='<div style="font-size:16px;font-weight:800;">'+esc(s.name)+'</div>';
        h+='<div style="font-size:11px;color:rgba(255,255,255,.5);margin-top:3px;">Subscriber #'+s.id+'</div>';
        h+='<div style="margin-top:10px;">'+expiryPill(s._expiry_status,s._days_remaining)+'</div>';
        h+='</div>';

        var infoRows=[
            ['bi-telephone','Phone',s.phone,'<a href="tel:'+esc(s.phone)+'" style="color:var(--primary);">'+esc(s.phone)+'</a>'],
            ['bi-sim','MSISDN',s.msisdn,'<span style="font-family:monospace;">'+esc(s.msisdn||'—')+'</span>'],
            ['bi-cpu','IMSI',s.imsi,'<span style="font-family:monospace;font-size:11px;">'+esc(s.imsi||'Not assigned')+'</span>'],
            ['bi-geo-alt','Address',s.address,esc(s.address||'—')],
            ['bi-calendar3','Registered','',fmt(s.created_at)],
        ];
        if(mag) infoRows.push(['bi-router-fill','Magma State',mag.state,'<span class="lte-pill '+(mag.state==='ACTIVE'?'active':'suspended')+'">'+esc(mag.state)+'</span>']);

        infoRows.forEach(function(r){
            h+='<div style="display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--border);">';
            h+='<div style="width:28px;height:28px;border-radius:7px;background:var(--primary-lt);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi '+r[0]+'" style="color:var(--primary);font-size:13px;"></i></div>';
            h+='<div style="min-width:0;"><div style="font-size:10px;color:var(--text-3);font-weight:600;text-transform:uppercase;">'+r[1]+'</div><div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+r[3]+'</div></div>';
            h+='</div>';
        });

        // Actions
        h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px;">';
        if(s.phone){
            h+='<a href="tel:'+esc(s.phone)+'" class="lte-btn ghost sm" style="justify-content:center;"><i class="bi bi-telephone-fill"></i> Call</a>';
            h+='<a href="https://wa.me/'+esc(s.phone.replace(/[^0-9]/g,''))+'" target="_blank" class="lte-btn ghost sm" style="justify-content:center;"><i class="bi bi-whatsapp" style="color:#25D366;"></i> WhatsApp</a>';
        }
        h+='<button onclick="openRenew('+s.id+',\''+esc(s.name)+'\')" class="lte-btn success sm" style="grid-column:1/-1;justify-content:center;"><i class="bi bi-arrow-repeat"></i> Renew Subscription</button>';
        if(ADM){
            if(s.status==='active'){
                h+='<button onclick="doSuspend('+s.id+')" class="lte-btn danger sm" style="grid-column:1/-1;justify-content:center;"><i class="bi bi-pause-circle"></i> Suspend</button>';
            } else if(s.status==='suspended'){
                h+='<button onclick="doReactivate('+s.id+')" class="lte-btn success sm" style="grid-column:1/-1;justify-content:center;"><i class="bi bi-play-circle"></i> Reactivate</button>';
            }
        }
        h+='<button onclick="printStatement('+s.id+')" class="lte-btn ghost sm" style="grid-column:1/-1;justify-content:center;"><i class="bi bi-printer-fill"></i> Print Statement</button>';
        h+='</div></div></div>'; // end client card + left

        // RIGHT: subscription + renewal history
        h+='<div style="display:flex;flex-direction:column;gap:12px;">';

        // Current subscription
        h+='<div class="lte-card">';
        h+='<div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-layers-fill"></i> Current Subscription</span></div>';
        if(sub&&sub.package_name){
            var daysLeft=s._days_remaining;
            var pct=daysLeft!==null&&pkg.duration_days?Math.max(0,Math.min(100,Math.round(daysLeft/pkg.duration_days*100))):0;
            var barC=pct<20?'var(--red)':(pct<40?'var(--orange)':'var(--green)');
            h+='<div class="lte-card-bd">';
            h+='<div style="font-size:16px;font-weight:800;margin-bottom:4px;">'+esc(sub.package_name)+'</div>';
            h+='<div style="font-size:12px;color:var(--text-3);margin-bottom:12px;">'+money(sub.amount_paid)+' · '+esc(sub.payment_method||'cash')+' · Agent: '+esc(sub.agent_name||'')+'</div>';
            h+='<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px;">';
            h+='<div style="text-align:center;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Started</div><div style="font-weight:700;font-size:13px;">'+fmt(sub.started_at)+'</div></div>';
            h+='<div style="text-align:center;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Expires</div><div style="font-weight:700;font-size:13px;">'+fmt(sub.expires_at)+'</div></div>';
            h+='<div style="text-align:center;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Days Left</div><div style="font-weight:800;font-size:16px;color:'+(daysLeft<0?'var(--red)':'var(--text)')+';">'+(daysLeft!==null?daysLeft:'—')+'</div></div>';
            h+='</div>';
            h+='<div style="margin-bottom:6px;"><div class="lte-prog"><div class="lte-prog-fill" style="width:'+pct+'%;background:'+barC+';"></div></div></div>';
            if(pkg.data_gb) h+='<div style="font-size:11px;color:var(--text-3);">'+pkg.data_gb+'GB · '+(pkg.speed_mbps||'Uncapped')+' Mbps · Magma: <span style="font-family:monospace;">'+esc(sub.magma_profile||'default')+'</span></div>';
            h+='</div>';
        } else {
            h+='<div style="padding:24px;text-align:center;color:var(--text-3);"><i class="bi bi-layers" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>No active subscription<br><small>Renew to assign a plan</small></div>';
        }
        h+='</div>';

        // Renewal history
        h+='<div class="lte-card">';
        h+='<div class="lte-card-hd"><span class="lte-card-hd-title"><i class="bi bi-clock-history"></i> Renewal History</span><span style="font-size:11px;color:var(--text-3);">Last 20</span></div>';
        if(!renewals.length){
            h+='<div style="padding:20px;text-align:center;color:var(--text-3);font-size:12px;">No renewals yet</div>';
        } else {
            h+='<table class="lte-tbl"><thead><tr><th>Date</th><th>Package</th><th>Amount</th><th>Method</th><th>Expires</th><th>Magma</th></tr></thead><tbody>';
            renewals.forEach(function(r){
                h+='<tr>';
                h+='<td style="font-size:11px;color:var(--text-3);">'+fmt(r.created_at)+'</td>';
                h+='<td style="font-size:12px;font-weight:600;">'+esc(r.package_name||'')+'</td>';
                h+='<td style="font-weight:700;color:var(--green);">'+money(r.amount_paid)+'</td>';
                h+='<td style="font-size:11px;"><span style="background:var(--primary-lt);color:var(--primary);border-radius:6px;padding:2px 8px;font-weight:700;">'+esc(r.payment_method||'cash')+'</span></td>';
                h+='<td style="font-size:11px;">'+fmt(r.expires_at)+'</td>';
                h+='<td>'+(r.magma_synced?'<span style="color:var(--green);font-size:16px;">✓</span>':'<span style="color:var(--text-3);font-size:16px;">–</span>')+'</td>';
                h+='</tr>';
            });
            h+='</tbody></table>';
        }
        h+='</div>';
        h+='</div>'; // right column
        h+='</div>'; // profile grid

        profDiv.innerHTML=h;
    });
};

/* ── RENEWAL QUEUE ── */
window.loadQueue = function(){
    document.getElementById('queue-body').innerHTML='<div style="text-align:center;padding:36px;color:var(--text-3);"><i class="bi bi-arrow-repeat" style="font-size:24px;display:block;margin-bottom:8px;animation:spin 1s linear infinite;"></i>Loading…</div>';
    ap('lte_renewal_queue','&days=7').then(function(d){
        if(d.status!=='success')return;
        var q=d.data||[];
        document.getElementById('lte-q-cnt').textContent=q.length;
        if(!q.length){document.getElementById('queue-body').innerHTML='<div style="text-align:center;padding:40px;color:var(--green);"><i class="bi bi-check-circle-fill" style="font-size:36px;display:block;margin-bottom:12px;"></i><div style="font-size:15px;font-weight:700;">All subscribers are up to date!</div></div>';return;}
        var h='<table class="lte-tbl"><thead><tr><th>Subscriber</th><th>Phone</th><th>Plan</th><th>Status</th><th>Expires</th><th>Agent</th><th>Action</th></tr></thead><tbody>';
        q.forEach(function(s){
            h+='<tr onclick="showSubProfile('+s.id+')">';
            h+='<td style="font-weight:700;">'+esc(s.name)+'</td>';
            h+='<td style="font-size:12px;"><a href="tel:'+esc(s.phone)+'" onclick="event.stopPropagation();" style="color:var(--primary);">'+esc(s.phone||'—')+'</a></td>';
            h+='<td style="font-size:12px;">'+esc(s._package?s._package.name:'No plan')+'</td>';
            h+='<td>'+expiryPill(s._expiry_status,s._days_remaining)+'</td>';
            h+='<td style="font-size:12px;color:var(--text-2);">'+fmt(s._subscription?s._subscription.expires_at:'')+'</td>';
            h+='<td style="font-size:12px;color:var(--text-3);">'+esc(s.agent_name||'—')+'</td>';
            h+='<td><button onclick="event.stopPropagation();openRenew('+s.id+',\''+esc(s.name)+'\')" class="lte-btn success sm"><i class="bi bi-arrow-repeat"></i> Renew</button></td>';
            h+='</tr>';
        });
        h+='</tbody></table>';
        document.getElementById('queue-body').innerHTML=h;
    });
};

/* ── SIM INVENTORY ── */
(function(){
    var _simPage = 1;
    var _simPerPage = 50;

    function renderSimPager(total, page, pages) {
        var start = (page - 1) * _simPerPage + 1;
        var end   = Math.min(page * _simPerPage, total);
        var h = '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid var(--border);background:#FAFBFF;flex-wrap:wrap;gap:8px;">';
        h += '<div style="font-size:12px;color:var(--text-3);">Showing <strong>' + start + '–' + end + '</strong> of <strong>' + total + '</strong> SIMs</div>';
        h += '<div style="display:flex;gap:6px;align-items:center;">';
        h += '<button onclick="window._simGo('+Math.max(1,page-1)+')" class="lte-btn ghost sm" '+(page<=1?'disabled':'')+'>‹ Prev</button>';
        for (var p = Math.max(1, page-2); p <= Math.min(pages, page+2); p++) {
            h += '<button onclick="window._simGo('+p+')" class="lte-btn sm" style="'+(p===page?'background:var(--primary);color:#fff;':'background:#fff;border:1.5px solid var(--border);color:var(--text-2);')+' min-width:34px;">'+p+'</button>';
        }
        h += '<button onclick="window._simGo('+Math.min(pages,page+1)+')" class="lte-btn ghost sm" '+(page>=pages?'disabled':'')+'>Next ›</button>';
        h += '</div></div>';
        return h;
    }

    window._simGo = function(p){ _simPage = p; _doLoadSims(); };

    function _doLoadSims() {
        var q  = document.getElementById('sim-q').value;
        var st = document.getElementById('sim-status').value;
        ap('lte_sims', '&search=' + encodeURIComponent(q) + '&status=' + encodeURIComponent(st)
            + '&page=' + _simPage + '&per_page=' + _simPerPage).then(function(d) {
            if (d.status !== 'success') return;
            var c = d.data.counts || {};
            document.getElementById('sc-total').textContent     = c.total     || 0;
            document.getElementById('sc-stock').textContent     = c.stock     || 0;
            document.getElementById('sc-active').textContent    = c.active    || 0;
            document.getElementById('sc-suspended').textContent = c.suspended || 0;
            var sims  = d.data.sims  || [];
            var total = d.data.total || 0;
            var pages = d.data.pages || 1;
            var page  = d.data.page  || 1;
            if (!sims.length) { document.getElementById('sims-body').innerHTML = '<div style="text-align:center;padding:36px;color:var(--text-3);"><i class="bi bi-sim" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3;"></i>No SIM cards found</div>'; return; }
            var STC = {'stock':'stock','assigned':'stock','active':'active','suspended':'suspended','retired':'voided'};
            var h = '<table class="lte-tbl"><thead><tr><th>IMSI</th><th>MSISDN</th><th>ICCID</th><th>Batch</th><th>Status</th><th>Assigned</th><th>Added</th></tr></thead><tbody>';
            sims.forEach(function(s) {
                var stc = STC[s.status || 'stock'] || 'stock';
                h += '<tr>';
                h += '<td style="font-family:monospace;font-size:11px;">' + esc(s.imsi || '—') + '</td>';
                h += '<td style="font-family:monospace;font-size:12px;">' + esc(s.msisdn || '—') + '</td>';
                h += '<td style="font-family:monospace;font-size:11px;color:var(--text-3);">' + esc(s.iccid || '—') + '</td>';
                h += '<td style="font-size:12px;">' + esc(s.batch || '—') + '</td>';
                h += '<td><span class="lte-pill ' + stc + '">' + esc(s.status || 'stock') + '</span></td>';
                h += '<td style="font-size:12px;color:var(--text-2);">' + (s.subscriber_id ? 'Sub #' + s.subscriber_id : '—') + '</td>';
                h += '<td style="font-size:11px;color:var(--text-3);">' + fmt(s.created_at) + '</td>';
                h += '</tr>';
            });
            h += '</tbody></table>';
            if (pages > 1) { h += renderSimPager(total, page, pages); }
            document.getElementById('sims-body').innerHTML = h;
        });
    }

    window.loadSims = function() { _simPage = 1; _doLoadSims(); };
})();

/* ── HARDWARE ── */
window.loadHw = function(){
    var q=document.getElementById('hw-q').value;
    var ty=document.getElementById('hw-type').value;
    var st=document.getElementById('hw-status').value;
    ap('lte_hardware','&search='+encodeURIComponent(q)+'&type='+encodeURIComponent(ty)+'&status='+encodeURIComponent(st)).then(function(d){
        if(d.status!=='success')return;
        var hw=d.data||[];
        if(!hw.length){document.getElementById('hw-body').innerHTML='<div style="text-align:center;padding:36px;color:var(--text-3);"><i class="bi bi-router" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3;"></i>No devices found</div>';return;}
        var HTC={'warehouse':'warehouse','deployed':'active','faulty':'suspended','returned':'no_plan'};
        var h='<table class="lte-tbl"><thead><tr><th>Type</th><th>Brand / Model</th><th>Serial</th><th>MAC</th><th>Status</th><th>Assigned</th><th>Cost</th></tr></thead><tbody>';
        hw.forEach(function(h2){
            var stc=HTC[h2.status||'warehouse']||'no_plan';
            h+='<tr>';
            h+='<td><span style="background:var(--primary-lt);color:var(--primary);border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;">'+(h2.type==='outdoor_cpe'?'Outdoor CPE':'MiFi')+'</span></td>';
            h+='<td style="font-weight:600;">'+esc(h2.brand||'')+'<span style="color:var(--text-3);font-weight:400;"> '+esc(h2.model||'')+'</span></td>';
            h+='<td style="font-family:monospace;font-size:11px;">'+esc(h2.serial||'—')+'</td>';
            h+='<td style="font-family:monospace;font-size:11px;color:var(--text-3);">'+esc(h2.mac||'—')+'</td>';
            h+='<td><span class="lte-pill '+stc+'">'+esc(h2.status||'warehouse')+'</span></td>';
            h+='<td style="font-size:12px;color:var(--text-2);">'+(h2.subscriber_id?'Sub #'+h2.subscriber_id:'—')+'</td>';
            h+='<td style="font-weight:700;">'+(h2.purchase_cost?money(h2.purchase_cost):'—')+'</td>';
            h+='</tr>';
        });
        h+='</tbody></table>';
        document.getElementById('hw-body').innerHTML=h;
    });
};

/* ── PACKAGES ── */
window.loadPackages = function(){
    ap('lte_packages').then(function(d){
        if(d.status!=='success')return;
        var pkgs=d.data||[];
        var typeLabels={daily:'Daily',weekly:'Weekly',monthly:'Monthly',unlimited:'Unlimited',corporate:'Corporate'};
        var typeColors={daily:'var(--orange)',weekly:'var(--purple)',monthly:'var(--primary)',unlimited:'var(--green)',corporate:'var(--text-2)'};
        var h='';
        pkgs.forEach(function(p){
            var c=typeColors[p.type||'monthly']||'var(--primary)';
            h+='<div class="lte-card" style="border-top:3px solid '+c+';">';
            h+='<div class="lte-card-bd">';
            h+='<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;">';
            h+='<div><div style="font-size:15px;font-weight:800;color:var(--text);">'+esc(p.name)+'</div>';
            h+='<span style="background:'+c+'22;color:'+c+';border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;text-transform:uppercase;margin-top:4px;display:inline-block;">'+esc(typeLabels[p.type||'monthly'])+'</span></div>';
            h+='<div style="font-size:22px;font-weight:900;color:'+c+';">$'+parseFloat(p.price||0).toFixed(0)+'</div>';
            h+='</div>';
            h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:12px;font-size:12px;">';
            h+='<div style="background:var(--surface);border-radius:8px;padding:8px;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Duration</div><div style="font-weight:700;">'+p.duration_days+'d</div></div>';
            h+='<div style="background:var(--surface);border-radius:8px;padding:8px;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Data</div><div style="font-weight:700;">'+(p.data_gb?p.data_gb+'GB':'Unlimited')+'</div></div>';
            h+='<div style="background:var(--surface);border-radius:8px;padding:8px;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Speed</div><div style="font-weight:700;">'+(p.speed_mbps?p.speed_mbps+' Mbps':'Uncapped')+'</div></div>';
            h+='<div style="background:var(--surface);border-radius:8px;padding:8px;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Subscribers</div><div style="font-weight:700;">'+(p._subscriber_count||0)+'</div></div>';
            h+='</div>';
            if(p.magma_profile) h+='<div style="font-size:10px;color:var(--text-3);font-family:monospace;margin-bottom:8px;">Magma: '+esc(p.magma_profile)+'</div>';
            if(ADM) h+='<button onclick="togglePkg('+p.id+',this)" class="lte-btn '+(p.active?'ghost':'success')+' sm" style="width:100%;justify-content:center;">'+(p.active?'Deactivate':'Activate')+'</button>';
            h+='</div></div>';
        });
        if(!h) h='<div class="lte-card"><div class="lte-card-bd" style="text-align:center;padding:40px;color:var(--text-3);"><i class="bi bi-layers" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>No packages yet — add your first data plan</div></div>';
        document.getElementById('pkgs-body').innerHTML=h;
    });
};

function loadPkgsIntoSelect(selId){
    ap('lte_packages').then(function(d){
        var pkgs=(d.data||[]).filter(p=>p.active!==false);
        var sel=document.getElementById(selId);
        if(!sel)return;
        var def=sel.options[0]?sel.options[0].text:'';
        sel.innerHTML='<option value="">'+def+'</option>';
        pkgs.forEach(function(p){sel.innerHTML+='<option value="'+p.id+'">'+esc(p.name)+' — $'+parseFloat(p.price).toFixed(2)+'/'+p.duration_days+'d</option>';});
    });
}

/* ── RENEW ── */
window.openRenew = function(id,name){
    document.getElementById('renew-sub-id').value=id;
    document.getElementById('renew-sub-name').textContent='Renewing: '+name;
    document.getElementById('renew-status').textContent='';
    document.getElementById('renew-amt').value='';
    lteShowModal('lte-renew-modal');
    loadPkgsIntoSelect('renew-pkg');
};

window.doRenew = function(){
    var id=parseInt(document.getElementById('renew-sub-id').value);
    var pkg=parseInt(document.getElementById('renew-pkg').value);
    var amt=parseFloat(document.getElementById('renew-amt').value||'0');
    var meth=document.getElementById('renew-pmeth').value;
    var st=document.getElementById('renew-status');
    if(!pkg){st.style.color='var(--red)';st.textContent='Select a package';return;}
    if(amt<=0){st.style.color='var(--red)';st.textContent='Enter amount collected';return;}
    st.style.color='var(--text-3)';st.textContent='Processing…';
    pp('lte_renew',{subscriber_id:id,package_id:pkg,amount_paid:amt,payment_method:meth}).then(function(d){
        if(d.status==='success'){
            st.style.color='var(--green)';
            st.textContent='✓ Renewed! Expires '+d.data._expires+(d.data._magma&&d.data._magma.success?' · Magma ✓':'');
            setTimeout(function(){lteHideModal('lte-renew-modal');loadDash();window._qLoaded=false;window._subLoaded=false;},2000);
        } else {
            st.style.color='var(--red)';st.textContent='⚠ '+(d.message||'Failed');
        }
    });
};

/* ── SUSPEND / REACTIVATE ── */
window.doSuspend = function(id){
    if(!confirm('Suspend this subscriber? They will be blocked from the LTE network immediately.'))return;
    pp('lte_suspend',{subscriber_id:id,reason:'manual'}).then(function(d){
        if(d.status==='success')showSubProfile(id);
    });
};
window.doReactivate = function(id){
    pp('lte_reactivate',{subscriber_id:id}).then(function(d){
        if(d.status==='success')showSubProfile(id);
    });
};

/* ── CREATE SUBSCRIBER ── */
window.doCreateSubscriber = function(){
    var st=document.getElementById('ns-status');
    var body={
        name:document.getElementById('ns-name').value,
        phone:document.getElementById('ns-phone').value,
        email:document.getElementById('ns-email').value,
        id_type:document.getElementById('ns-idtype').value,
        id_number:document.getElementById('ns-idnum').value,
        address:document.getElementById('ns-addr').value,
        imsi:document.getElementById('ns-imsi').value,
        msisdn:document.getElementById('ns-msisdn').value,
        package_id:parseInt(document.getElementById('ns-pkg').value)||0,
        amount_paid:parseFloat(document.getElementById('ns-amt').value||'0'),
        payment_method:document.getElementById('ns-pmeth').value,
        gps_lat:parseFloat(document.getElementById('ns-lat').value||'0')||null,
        notes:document.getElementById('ns-notes').value,
    };
    if(!body.name){st.style.color='var(--red)';st.textContent='Name required';return;}
    if(!body.phone){st.style.color='var(--red)';st.textContent='Phone required';return;}
    st.style.color='var(--text-3)';st.textContent='Registering…';
    pp('lte_create_subscriber',body).then(function(d){
        if(d.status==='success'){
            st.style.color='var(--green)';st.textContent='✓ Subscriber #'+d.data.id+' registered!';
            setTimeout(function(){lteHideModal('lte-new-sub-modal');loadDash();window._subLoaded=false;},2000);
        } else {st.style.color='var(--red)';st.textContent='⚠ '+(d.message||'Failed');}
    });
};

/* ── CREATE SIM ── */
window.doCreateSim = function(){
    var st=document.getElementById('sim-status');
    var body={imsi:document.getElementById('sim-imsi').value,msisdn:document.getElementById('sim-msisdn').value,iccid:document.getElementById('sim-iccid').value,batch:document.getElementById('sim-batch').value,auth_key:document.getElementById('sim-key').value,auth_opc:document.getElementById('sim-opc').value,notes:document.getElementById('sim-notes').value};
    if(!body.imsi||!body.msisdn){st.style.color='var(--red)';st.textContent='IMSI and MSISDN required';return;}
    st.style.color='var(--text-3)';st.textContent='Adding…';
    pp('lte_create_sim',body).then(function(d){
        if(d.status==='success'){st.style.color='var(--green)';st.textContent='✓ SIM added';setTimeout(function(){lteHideModal('lte-new-sim-modal');loadSims();},1500);}
        else{st.style.color='var(--red)';st.textContent='⚠ '+(d.message||'Failed');}
    });
};

/* ── CREATE HARDWARE ── */
window.doCreateHw = function(){
    var st=document.getElementById('hw-new-status');
    var body={type:document.getElementById('hw-type-new').value,brand:document.getElementById('hw-brand').value,model:document.getElementById('hw-model').value,serial:document.getElementById('hw-serial').value,mac:document.getElementById('hw-mac').value,purchase_cost:parseFloat(document.getElementById('hw-cost').value||'0'),notes:document.getElementById('hw-notes').value};
    if(!body.serial){st.style.color='var(--red)';st.textContent='Serial number required';return;}
    st.style.color='var(--text-3)';st.textContent='Adding…';
    pp('lte_create_hardware',body).then(function(d){
        if(d.status==='success'){st.style.color='var(--green)';st.textContent='✓ Device added';setTimeout(function(){lteHideModal('lte-new-hw-modal');loadHw();},1500);}
        else{st.style.color='var(--red)';st.textContent='⚠ '+(d.message||'Failed');}
    });
};

/* ── CREATE PACKAGE ── */
window.doCreatePackage = function(){
    var st=document.getElementById('pk-status');
    var body={name:document.getElementById('pk-name').value,type:document.getElementById('pk-type').value,duration_days:parseInt(document.getElementById('pk-days').value||'30'),data_gb:parseFloat(document.getElementById('pk-gb').value||'0'),speed_mbps:parseFloat(document.getElementById('pk-speed').value||'0'),price:parseFloat(document.getElementById('pk-price').value||'0'),magma_profile:document.getElementById('pk-profile').value,description:document.getElementById('pk-desc').value};
    if(!body.name){st.style.color='var(--red)';st.textContent='Package name required';return;}
    st.style.color='var(--text-3)';st.textContent='Creating…';
    pp('lte_create_package',body).then(function(d){
        if(d.status==='success'){st.style.color='var(--green)';st.textContent='✓ Package created';setTimeout(function(){lteHideModal('lte-new-pkg-modal');loadPackages();},1500);}
        else{st.style.color='var(--red)';st.textContent='⚠ '+(d.message||'Failed');}
    });
};

/* ── TOGGLE PACKAGE ── */
window.togglePkg = function(id,btn){
    pp('lte_toggle_package',{id:id}).then(function(d){if(d.status==='success')loadPackages();});
};

/* ── USAGE MONITOR ── */
window.loadUsage = function(autoLoad){
    var q  = document.getElementById('um-q').value;
    var st = document.getElementById('um-filter').value;
    var body = document.getElementById('um-body');
    body.innerHTML = '<div style="text-align:center;padding:36px;color:var(--text-3);"><i class="bi bi-arrow-repeat" style="font-size:24px;display:block;margin-bottom:8px;animation:spin 1s linear infinite;"></i>Loading usage data…</div>';
    ap('lte_usage_summary','&search='+encodeURIComponent(q)+'&state='+encodeURIComponent(st)).then(function(d){
        if(d.status!=='success'){body.innerHTML='<div style="padding:24px;color:var(--red);">Failed to load</div>';return;}
        var s=d.data.stats, rows=d.data.subscribers||[];
        // Update stat tiles
        document.getElementById('um-active').textContent   = s.active;
        document.getElementById('um-inactive').textContent = s.inactive;
        document.getElementById('um-alerts').textContent   = s.unreachable+s.mismatch+s.no_imsi;
        document.getElementById('um-avg-lat').textContent  = s.avg_lat_ms!==null ? s.avg_lat_ms+'ms' : '—';
        // Cache age
        if(s.cached_at){
            var age=Math.round((Date.now()-new Date(s.cached_at))/60000);
            document.getElementById('um-cache-age').textContent='Cache: '+(age<2?'just now':age+' min ago')+' ('+s.cached_at.substring(0,16)+')';
        } else {
            document.getElementById('um-cache-age').textContent='Cache: empty — pull from Magma';
        }
        if(!rows.length){body.innerHTML='<div style="text-align:center;padding:40px;color:var(--text-3);"><i class="bi bi-activity" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3;"></i>No data found</div>';return;}
        var h='<table class="lte-tbl"><thead><tr>';
        h+='<th>Subscriber</th><th>IMSI</th><th>Magma State</th><th>Local Status</th><th>Plan / Profile</th>';
        h+='<th>Latency</th><th>Probe Success</th><th>Expires</th><th>Alerts</th>';
        h+='</tr></thead><tbody>';
        rows.forEach(function(r){
            var alerts='';
            if(r._no_imsi)       alerts+='<span class="lte-pill suspended" style="font-size:9px;margin:1px;">No IMSI</span>';
            if(r._magma_mismatch)alerts+='<span class="lte-pill warning"   style="font-size:9px;margin:1px;">State Mismatch</span>';
            if(r._zero_reach)    alerts+='<span class="lte-pill suspended" style="font-size:9px;margin:1px;">Unreachable</span>';
            if(r._high_latency)  alerts+='<span class="lte-pill warning"   style="font-size:9px;margin:1px;">High Latency</span>';
            var msC=r.magma_state==='ACTIVE'?'active':(r.magma_state==='INACTIVE'?'suspended':'no_plan');
            var lsC=r.local_status==='active'?'active':(r.local_status==='suspended'?'suspended':'no_plan');
            var reachDisp='—';
            if(r.probes_sent>0){
                var rPct=r.reach_pct;
                var rC=rPct>=90?'var(--green)':(rPct>=50?'var(--orange)':'var(--red)');
                reachDisp='<span style="color:'+rC+';font-weight:700;">'+rPct+'%</span><span style="font-size:10px;color:var(--text-3);"> ('+r.probes_sent+' sent)</span>';
            }
            var latDisp='—';
            if(r.latency_ms!==null){
                var lC=r.latency_ms<50?'var(--green)':(r.latency_ms<200?'var(--orange)':'var(--red)');
                latDisp='<span style="color:'+lC+';font-weight:700;">'+r.latency_ms+'ms</span>';
            }
            h+='<tr style="'+(alerts?'background:#FFFBEB;':'')+'" onclick="showSubProfile('+r.id+')">';
            h+='<td><div style="font-weight:700;">'+esc(r.name)+'</div><div style="font-size:11px;color:var(--text-3);">'+esc(r.phone||'')+'</div></td>';
            h+='<td style="font-family:monospace;font-size:11px;color:var(--text-3);">'+esc(r.imsi||'—')+'</td>';
            h+='<td><span class="lte-pill '+msC+'">'+esc(r.magma_state||'UNKNOWN')+'</span></td>';
            h+='<td><span class="lte-pill '+lsC+'">'+esc(r.local_status)+'</span></td>';
            h+='<td style="font-size:12px;"><div>'+esc(r.plan_name||'No plan')+'</div><div style="font-size:10px;font-family:monospace;color:var(--text-3);">'+esc(r.sub_profile||'')+'</div></td>';
            h+='<td>'+latDisp+'</td>';
            h+='<td>'+reachDisp+'</td>';
            h+='<td style="font-size:11px;color:var(--text-2);">'+fmt(r.expires_at)+'</td>';
            h+='<td>'+(alerts||'<span style="color:var(--text-3);font-size:12px;">—</span>')+'</td>';
            h+='</tr>';
        });
        h+='</tbody></table>';
        body.innerHTML=h;
    }).catch(function(){body.innerHTML='<div style="padding:24px;color:var(--red);">Network error</div>';});
};

window.doRefreshUsage = function(){
    var btn=document.getElementById('um-refresh-btn');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i> Pulling from Magma…';
    ap('lte_refresh_usage').then(function(d){
        btn.disabled=false;btn.innerHTML='<i class="bi bi-arrow-repeat"></i> Pull from Magma';
        if(d.status==='success'){
            var r=d.data;
            document.getElementById('um-cache-age').textContent='Pulled: '+r.refreshed+' subscribers in '+(r.pages||1)+' page(s) at '+r.at;
            loadUsage();
        } else {
            alert('Magma pull failed: '+(d.message||'Unknown error'));
        }
    }).catch(function(){btn.disabled=false;btn.innerHTML='<i class="bi bi-arrow-repeat"></i> Pull from Magma';alert('Network error');});
};

/* ── INFRASTRUCTURE ── */
window.doRefreshHealth = function(){
    var btns=[document.getElementById('um-refresh-health-btn'),document.getElementById('infra-refresh-btn')];
    btns.forEach(function(b){if(b){b.disabled=true;b.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i> Refreshing…';}});
    ap('lte_refresh_health').then(function(d){
        btns.forEach(function(b){if(b){b.disabled=false;b.innerHTML='<i class="bi bi-arrow-repeat"></i> Refresh';}});
        if(d.status==='success'&&!d.data.skipped) loadInfra();
        else if(d.data&&d.data.skipped) alert('Magma not configured. Go to Settings to connect your Orchestrator.');
    }).catch(function(){btns.forEach(function(b){if(b){b.disabled=false;}});});
};

window.loadInfra = function(){
    ap('lte_network_health').then(function(d){
        if(d.status!=='success')return;
        var cfg=d.data.configured, h=d.data.health||{};
        var gws=h.gateways||[], enbs=h.enodebs||[];
        if(h.refreshed_at) document.getElementById('infra-cached-at').textContent='Last refreshed: '+h.refreshed_at;

        // Gateways
        var gwH='';
        if(!gws.length){
            gwH='<div class="lte-card" style="grid-column:1/-1;"><div class="lte-card-bd" style="text-align:center;padding:28px;color:var(--text-3);">';
            gwH+=cfg?'<i class="bi bi-server" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>No gateways found — click Refresh':'<i class="bi bi-wifi-off" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>Magma not configured';
            gwH+='</div></div>';
        } else {
            gws.forEach(function(gw){
                var online=gw.online;
                gwH+='<div class="lte-card" style="border-top:3px solid '+(online?'var(--green)':'var(--red)')+';">';
                gwH+='<div class="lte-card-bd">';
                gwH+='<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">';
                gwH+='<div style="font-size:14px;font-weight:800;">'+esc(gw.name||gw.id)+'</div>';
                gwH+='<span class="lte-pill '+(online?'active':'suspended')+'"><i class="bi bi-circle-fill" style="font-size:6px;"></i>'+(online?'Online':'Offline')+'</span>';
                gwH+='</div>';
                gwH+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;">';
                gwH+='<div style="background:var(--surface);border-radius:8px;padding:8px;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">eNodeBs</div><div style="font-weight:800;font-size:18px;">'+gw.enodeb_count+'</div></div>';
                gwH+='<div style="background:var(--surface);border-radius:8px;padding:8px;"><div style="font-size:10px;color:var(--text-3);font-weight:700;text-transform:uppercase;">Gateway ID</div><div style="font-weight:600;font-size:11px;font-family:monospace;overflow:hidden;text-overflow:ellipsis;">'+esc(gw.id)+'</div></div>';
                gwH+='</div></div></div>';
            });
        }
        document.getElementById('infra-gw-body').innerHTML=gwH;

        // eNodeBs
        var enbH='';
        if(!enbs.length){
            enbH='<div style="text-align:center;padding:32px;color:var(--text-3);">';
            enbH+=cfg?'No eNodeBs found — click Refresh':'Magma not configured';
            enbH+='</div>';
        } else {
            enbH='<table class="lte-tbl"><thead><tr><th>Serial</th><th>Name</th><th>TAC</th><th>Band</th><th>Cell ID</th><th>Connected</th><th>RF TX</th><th>Op State</th><th>GPS</th></tr></thead><tbody>';
            enbs.forEach(function(e){
                enbH+='<tr>';
                enbH+='<td style="font-family:monospace;font-size:11px;">'+esc(e.serial)+'</td>';
                enbH+='<td style="font-weight:600;">'+esc(e.name||e.serial)+'</td>';
                enbH+='<td style="font-size:12px;">'+esc(e.tac!==null?e.tac:'—')+'</td>';
                enbH+='<td style="font-size:12px;">'+(e.band?e.band+'MHz':'—')+'</td>';
                enbH+='<td style="font-size:12px;font-family:monospace;">'+esc(e.cell_id!==null?e.cell_id:'—')+'</td>';
                enbH+='<td><span class="lte-pill '+(e.connected?'active':'suspended')+'">'+(e.connected?'Yes':'No')+'</span></td>';
                enbH+='<td><span class="lte-pill '+(e.rf_tx?'active':'suspended')+'">'+(e.rf_tx?'On':'Off')+'</span></td>';
                enbH+='<td><span class="lte-pill '+(e.opstate?'active':'suspended')+'">'+(e.opstate?'Enabled':'Disabled')+'</span></td>';
                enbH+='<td>'+(e.gps_connected?'<span class="lte-pill ok">GPS</span>':'<span class="lte-pill no_plan">No GPS</span>')+'</td>';
                enbH+='</tr>';
            });
            enbH+='</tbody></table>';
        }
        document.getElementById('infra-enb-body').innerHTML=enbH;
    });
};

/* ── BULK IMPORT ── */
var impRows = [];
window.impLoadFile = function(input){
    var file = input.files[0];
    if(!file) return;
    document.getElementById('imp-filename').textContent = file.name;
    var reader = new FileReader();
    reader.onload = function(e){ impParseCSV(e.target.result); };
    reader.readAsText(file);
};

// Proper RFC-4180 CSV parser — handles quoted fields with commas inside
function parseCSVLine(line) {
    var result = [], cur = '', inQ = false;
    for (var i = 0; i < line.length; i++) {
        var c = line[i];
        if (inQ) {
            if (c === '"' && line[i+1] === '"') { cur += '"'; i++; }
            else if (c === '"') { inQ = false; }
            else { cur += c; }
        } else {
            if (c === '"') { inQ = true; }
            else if (c === ',') { result.push(cur.trim()); cur = ''; }
            else { cur += c; }
        }
    }
    result.push(cur.trim());
    return result;
}

function impParseCSV(text){
    var lines = text.replace(/\r\n/g,'\n').replace(/\r/g,'\n').split('\n').filter(function(l){return l.trim();});
    if(lines.length < 2){alert('CSV must have a header row and at least one data row.');return;}
    var rawHeaders = parseCSVLine(lines[0]).map(function(h){return h.replace(/"/g,'').toLowerCase().trim();});

    // Column mapping — handles both native Hybrid format AND BlueCard export format
    var colMap = {
        // ── Customers CSV ──────────────────────────────
        'firstname':           'firstname',
        'lastname':            'lastname',
        'bluecard_id':         'bluecard_id',
        'mobile':              'phone',
        'whatsapp_number':     'whatsapp',
        // ── SIMs CSV ───────────────────────────────────
        'bluecard_sim_id':     'bluecard_sim_id',
        'auth_key':            'auth_key',
        'auth_opc':            'auth_opc',
        'opc_value':           'auth_opc',
        'algo':                'algo',
        // ── Subscriptions CSV ──────────────────────────
        'bluecard_svc_id':     'bluecard_svc_id',
        'bluecard_user_id':    'bluecard_user_id',
        'offer_id':            'offer_id',
        'package_name':        'package_name',
        'price_usd':           'price_usd',
        'state':               'state',
        // ── Data usage CSV ─────────────────────────────
        'bytes_total':         'bytes_total',
        'bytes_used':          'bytes_used',
        'bytes_allowed':       'bytes_allowed',
        'start_date':          'start_date',
        'end_date':            'end_date',
        'plan_type':           'plan_type',
        // ── Recharge CSV ───────────────────────────────
        'bluecard_topup_id':   'bluecard_topup_id',
        'is_addon':            'is_addon',
        // ── Packages CSV ───────────────────────────────
        'bytes_display':       'bytes_display',
        'speed_profile':       'speed_profile',
        'magma_profile':       'magma_profile',
        'active_apns':         'active_apns',
        'lifecycle_status':    'lifecycle_status',
        // ── Native Hybrid format ───────────────────────
        'name':                'name',
        'phone':               'phone',
        'imsi':                'imsi',
        'msisdn':              'msisdn',
        'iccid':               'iccid',
        'email':               'email',
        'address':             'address',
        'area':                'area',
        'package':             'package',
        'expires_at':          'expires_at',
        'started_at':          'started_at',
        'amount_paid':         'amount_paid',
        'payment_method':      'payment_method',
        'lat':                 'lat',
        'lon':                 'lon',
        'notes':               'notes',
        'days':                'days',
        'is_active':           'is_active',
        'created_at':          'created_at',
    };

    impRows = [];
    for(var i = 1; i < lines.length; i++){
        if(!lines[i].trim()) continue;
        var vals = parseCSVLine(lines[i]);
        var raw = {};
        rawHeaders.forEach(function(h,j){ raw[h] = (vals[j] || '').replace(/^"|"$/g,''); });

        // Build normalised row
        var row = {};
        rawHeaders.forEach(function(h) {
            var mapped = colMap[h] || h;
            row[mapped] = raw[h] || '';
        });

        // Build full name from firstname+lastname if no 'name' field
        if(!row.name && (row.firstname || row.lastname)) {
            row.name = ((row.firstname||'') + ' ' + (row.lastname||'')).trim();
        }
        // phone from mobile if needed
        if(!row.phone && raw.mobile) row.phone = raw.mobile;

        // Accept any row that has at least one non-empty value
        var hasData = Object.values(row).some(function(v){ return v && v.toString().trim() !== ''; });
        if(hasData) impRows.push(row);
    }
    document.getElementById('imp-row-count').textContent = '— ' + impRows.length + ' rows';
    renderImpPreview();
}

function renderImpPreview(){
    var card = document.getElementById('imp-preview-card');
    var body = document.getElementById('imp-preview-body');
    if(!impRows.length){card.style.display='none';return;}
    card.style.display='';

    // Detect CSV type and show relevant columns
    var sample = impRows[0] || {};
    var allCols;
    if(sample.bytes_total !== undefined || sample.speed_profile !== undefined || sample.price_usd !== undefined) {
        // Packages CSV
        allCols = ['name','price_usd','bytes_total','days','plan_type','speed_profile','bluecard_id'];
    } else if(sample.auth_key !== undefined && sample.firstname === undefined) {
        // SIMs CSV
        allCols = ['imsi','msisdn','auth_key','auth_opc','algo'];
    } else if(sample.bytes_used !== undefined) {
        // Usage CSV
        allCols = ['imsi','bytes_total','bytes_used','start_date','end_date'];
    } else if(sample.is_addon !== undefined) {
        // Recharge CSV
        allCols = ['bluecard_user_id','package_name','price_usd','end_date','is_addon'];
    } else if(sample.offer_id !== undefined) {
        // Subscriptions CSV
        allCols = ['bluecard_user_id','imsi','package_name','price_usd','state'];
    } else {
        // Subscribers CSV (default)
        allCols = ['name','phone','imsi','msisdn','package','expires_at','amount_paid','address'];
    }
    if(impRows[0] && impRows[0].bluecard_id && allCols.indexOf('bluecard_id')===-1) allCols.unshift('bluecard_id');

    var h='<table class="lte-tbl"><thead><tr>'+allCols.map(function(c){return '<th>'+c+'</th>';}).join('')+'</tr></thead><tbody>';
    impRows.slice(0,50).forEach(function(r){
        h+='<tr>';
        allCols.forEach(function(c){
            var val=r[c]||'';
            var style=(!val&&(c==='name'||c==='phone'))?'color:var(--red);font-style:italic;':'';
            h+='<td style="font-size:11px;'+style+'">'+(val||'<span style="opacity:.3">—</span>')+'</td>';
        });
        h+='</tr>';
    });
    if(impRows.length>50) h+='<tr><td colspan="'+allCols.length+'" style="text-align:center;color:var(--text-3);padding:10px;font-size:12px;">… and '+(impRows.length-50)+' more rows</td></tr>';
    h+='</tbody></table>';
    body.innerHTML=h;
}

window.impClear = function(){
    impRows=[];
    document.getElementById('imp-preview-card').style.display='none';
    document.getElementById('imp-results').innerHTML='';
    document.getElementById('imp-filename').textContent='';
    document.getElementById('imp-file').value='';
};

window.impRun = function(){
    if(!impRows.length){alert('No rows to import');return;}
    var btn=document.getElementById('imp-run-btn');
    btn.disabled=true;btn.innerHTML='<i class="bi bi-arrow-repeat" style="animation:spin 1s linear infinite;"></i> Importing…';
    var resDiv=document.getElementById('imp-results');
    resDiv.innerHTML='';

    // Auto-detect CSV type from first row's columns and route to correct endpoint
    var sample = impRows[0] || {};
    var cols = Object.keys(sample);
    var impAction = 'lte_bulk_import'; // default = subscribers
    if (cols.indexOf('bytes_total') !== -1 || cols.indexOf('speed_profile') !== -1 || cols.indexOf('price_usd') !== -1) {
        impAction = 'lte_import_packages';
    } else if (cols.indexOf('auth_key') !== -1 && cols.indexOf('imsi') !== -1 && cols.indexOf('firstname') === -1) {
        impAction = 'lte_import_sims_csv';
    } else if (cols.indexOf('bytes_used') !== -1 && cols.indexOf('start_date') !== -1) {
        impAction = 'lte_import_usage_csv';
    } else if (cols.indexOf('is_addon') !== -1) {
        impAction = 'lte_import_recharge_csv';
    } else if (cols.indexOf('bluecard_user_id') !== -1 && cols.indexOf('offer_id') !== -1) {
        impAction = 'lte_import_subscriptions_csv';
    }

    // Send in batches of 100
    var batches=[], bs=100;
    for(var i=0;i<impRows.length;i+=bs) batches.push(impRows.slice(i,i+bs));

    var imported=0,skipped=0,errors=[];
    function runBatch(idx){
        if(idx>=batches.length){
            btn.disabled=false;btn.innerHTML='<i class="bi bi-upload"></i> Import All';
            var color=errors.length?'var(--orange)':'var(--green)';
            resDiv.innerHTML='<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:12px;padding:16px 18px;">'
                +'<div style="font-size:15px;font-weight:800;color:var(--green);margin-bottom:4px;"><i class="bi bi-check-circle-fill"></i> Import Complete</div>'
                +'<div style="font-size:13px;color:var(--text-2);">✅ Imported: <strong>'+imported+'</strong> &nbsp;·&nbsp; ⏭ Skipped (duplicates): <strong>'+skipped+'</strong>'
                +(errors.length?'&nbsp;·&nbsp; ⚠ Errors: <strong>'+errors.length+'</strong>':'')+'</div>'
                +(errors.length?'<div style="margin-top:8px;font-size:11px;color:var(--red);">'+errors.slice(0,5).map(function(e){return esc(e);}).join('<br>')+'</div>':'')
                +'</div>';
            window._subLoaded=false; // force reload on next view
            return;
        }
        resDiv.innerHTML='<div style="font-size:13px;color:var(--text-3);padding:8px;">Importing batch '+(idx+1)+'/'+batches.length+'…</div>';
        pp(impAction,{rows:batches[idx]}).then(function(d){
            if(d.status==='success'){
                imported+=d.data.imported||0;
                skipped +=d.data.skipped||0;
                errors   =errors.concat(d.data.errors||[]);
            }
            runBatch(idx+1);
        }).catch(function(){
            errors.push('Batch '+(idx+1)+' network error');
            runBatch(idx+1);
        });
    }
    runBatch(0);
};

window.impDownloadTemplate = function(){
    var headers='name,phone,email,imsi,msisdn,iccid,address,id_type,id_number,package,expires_at,started_at,amount_paid,payment_method,lat,lon,notes';
    var example='John Doe,+211912345678,john@example.com,IMSI001010000000001,+211912345678,8988116666000020001,Juba Central,National ID,SS-123456,Monthly 20GB,2025-12-31,2025-12-01,25,cash,4.85166,31.58247,Test import';
    var csv=headers+'\n'+example;
    var blob=new Blob([csv],{type:'text/csv'});
    var a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download='lte_subscribers_template.csv';
    a.click();
};

// Load package names into import helper
ap('lte_packages').then(function(d){
    var el=document.getElementById('imp-pkg-list');
    if(!el||d.status!=='success')return;
    var pkgs=d.data||[];
    if(!pkgs.length){el.textContent='No packages yet — add packages first';return;}
    el.innerHTML=pkgs.map(function(p){return '<div style="padding:2px 0;">'+esc(p.name)+'</div>';}).join('');
});

/* ── PDF STATEMENT ── */
window.printStatement = function(id){
    var TK=document.querySelector('meta[name="api-token"]')?.content||'';
    fetch('?page=api&action=lte_statement&id='+id,{credentials:'same-origin',headers:{'Authorization':'Bearer '+TK}})
    .then(r=>r.json()).then(function(d){
        if(d.status!=='success'){alert('Failed to load statement');return;}
        var s=d.data.subscriber,ren=d.data.renewals,co=d.data.company,tot=d.data.total_paid;
        function money(v){return '$'+parseFloat(v||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2});}
        function esc(x){return String(x||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
        function fmt(x){return x?x.substring(0,10):'';}
        var rows=ren.map(function(r,i){
            return '<tr>'
                +'<td>'+(i+1)+'</td>'
                +'<td>'+fmt(r.created_at)+'</td>'
                +'<td>'+esc(r.package_name||'')+'</td>'
                +'<td>'+fmt(r.expires_at)+'</td>'
                +'<td>'+esc(r.payment_method||'cash')+'</td>'
                +'<td style="text-align:right;font-weight:700;">'+money(r.amount_paid)+'</td>'
                +'</tr>';
        }).join('');
        var activeS=ren.find(function(r){return r.status==='active';})||ren[0]||{};
        var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Statement — '+esc(s.name)+'</title>'
            +'<style>*{margin:0;padding:0;box-sizing:border-box;}body{font-family:Arial,sans-serif;font-size:12px;color:#111;padding:32px 40px;}'
            +'h1{font-size:20px;font-weight:900;}.label{font-size:10px;color:#666;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:2px;}'
            +'.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0;}'
            +'.info-box{background:#f8f9fa;border-radius:6px;padding:12px 14px;}'
            +'table{width:100%;border-collapse:collapse;margin-top:16px;}'
            +'th{background:#0F172A;color:#fff;padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;}'
            +'td{padding:8px 10px;border-bottom:1px solid #e5e7eb;}'
            +'tr:nth-child(even) td{background:#f9fafb;}'
            +'.total-row td{border-top:2px solid #0F172A;font-weight:900;font-size:13px;background:#f0f4ff !important;}'
            +'.badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;}'
            +'.active{background:#d1fae5;color:#065f46;}.expired{background:#fee2e2;color:#991b1b;}'
            +'@media print{body{padding:10px 14px;}.no-print{display:none;}}</style>'
            +'<scr'+'ipt>window.onload=function(){window.print();}<\/script>'
            +'</head><body>'
            // Header
            +'<div style="display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:16px;border-bottom:2px solid #0F172A;margin-bottom:20px;">'
            +'<div><div style="font-size:22px;font-weight:900;color:#0F172A;">'+esc(co.name)+'</div>'
            +'<div style="font-size:11px;color:#666;margin-top:4px;">'+esc(co.address)+(co.phone?' · '+esc(co.phone):'')+'</div></div>'
            +'<div style="text-align:right;"><div style="font-size:16px;font-weight:800;color:#D41C1C;">ACCOUNT STATEMENT</div>'
            +'<div style="font-size:11px;color:#666;margin-top:4px;">Generated: '+d.data.generated.substring(0,10)+'</div></div>'
            +'</div>'
            // Subscriber + Plan info
            +'<div class="info-grid">'
            +'<div class="info-box"><div class="label">Subscriber</div>'
            +'<div style="font-size:15px;font-weight:800;margin-top:4px;">'+esc(s.name)+'</div>'
            +'<div style="margin-top:6px;display:grid;gap:4px;">'
            +(s.phone?'<div>📞 '+esc(s.phone)+'</div>':'')
            +(s.msisdn?'<div>📱 MSISDN: <span style="font-family:monospace;">'+esc(s.msisdn)+'</span></div>':'')
            +(s.imsi?'<div>🔑 IMSI: <span style="font-family:monospace;font-size:10px;">'+esc(s.imsi)+'</span></div>':'')
            +(s.address?'<div>📍 '+esc(s.address)+'</div>':'')
            +'</div></div>'
            +'<div class="info-box"><div class="label">Current Plan</div>'
            +'<div style="font-size:15px;font-weight:800;margin-top:4px;">'+esc(activeS.package_name||'No active plan')+'</div>'
            +'<div style="margin-top:6px;display:grid;gap:4px;">'
            +(activeS.expires_at?'<div>📅 Expires: <strong>'+fmt(activeS.expires_at)+'</strong></div>':'')
            +(activeS.status?'<div>Status: <span class="badge '+esc(activeS.status)+'">'+esc(activeS.status.toUpperCase())+'</span></div>':'')
            +'<div style="margin-top:8px;font-size:18px;font-weight:900;color:#D41C1C;">Total Paid: '+money(tot)+'</div>'
            +'</div></div>'
            +'</div>'
            // Renewal table
            +'<div class="label" style="margin-top:16px;">Renewal History ('+ren.length+' record'+(ren.length!==1?'s':'')+')</div>'
            +'<table><thead><tr><th>#</th><th>Date</th><th>Package</th><th>Valid Until</th><th>Method</th><th style="text-align:right;">Amount</th></tr></thead>'
            +'<tbody>'+rows+'<tr class="total-row"><td colspan="5">TOTAL PAID</td><td style="text-align:right;">'+money(tot)+'</td></tr></tbody>'
            +'</table>'
            +'<div style="margin-top:20px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:10px;color:#666;display:flex;justify-content:space-between;">'
            +'<span>Thank you for choosing '+esc(co.name)+'</span>'
            +'<span>Generated '+d.data.generated+' · LTE Subscriber #'+s.id+'</span>'
            +'</div>'
            +'</body></html>';
        var w=window.open('','_blank','width=900,height=700');
        w.document.write(html);w.document.close();
    }).catch(function(){alert('Network error');});
};

/* ── INIT ── */
loadDash();
// Auto-load cached usage on tab open
document.getElementById('ltenb_usage').addEventListener('click',function(){
    if(!window._usageLoaded){window._usageLoaded=true;loadUsage();}
});
document.getElementById('ltenb_infra').addEventListener('click',function(){
    if(!window._infraLoaded){window._infraLoaded=true;loadInfra();}
});
})();
</script>

