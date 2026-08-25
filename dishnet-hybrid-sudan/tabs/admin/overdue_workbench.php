<?php
// ═══════════════════════════════════════════════════════════════════════════
// Tab: overdue_workbench  (admin + accountant + field_accountant)
//   →  "Overdue Workbench"   v4.21.66
//
// Operator-side counterpart to the automated dunning chain
// (cron_overdue_email.php). Pairs with lib/OverdueWorkbenchService.php which
// provides the data layer; this tab is the UI shell.
//
// Endpoints called (all admin/accountant gated):
//   GET  ?action=owb_list           — main feed with filters
//   GET  ?action=owb_detail         — full history for one invoice
//   GET  ?action=owb_smtp_check     — live SMTP health probe (TCP+TLS+AUTH)
//   GET  ?action=owb_export_csv     — CSV download of current filtered list
//   POST ?action=owb_note           — add a note (free text)
//   POST ?action=owb_promise        — record promise to pay
//   POST ?action=owb_clear_promise  — clear promise
//   POST ?action=owb_status         — change status
//   POST ?action=owb_assign         — assign / unassign retailer
//   POST ?action=owb_bulk_assign    — bulk assign N invoices
//   POST ?action=owb_bulk_status    — bulk status change
//
// Service returns rows with fields:
//   invoice_number, invoice_id, client_id, client_name, first_name, phone,
//   email, amount_due, amount_total, due_date, due_date_fmt, days_overdue,
//   bucket (1-14 / 15-30 / 31-60 / 61-90 / 91-180 / 180+), status,
//   assigned_to, promised_pay_date, promised_amount, pause_until, last_note,
//   last_action_by, last_action_at, contact_attempts, last_contact_at,
//   last_email_stage, last_email_label, last_email_at, last_email_success,
//   has_workbench_row, closed_at, promise_status (none/pending/due_today/broken)
// ═══════════════════════════════════════════════════════════════════════════

if (!isset($retailer)) $retailer = $auth->requireLogin();
$isAdmin = !empty($retailer['is_admin']);
$role    = (string)($retailer['role'] ?? '');
if (!$isAdmin && !in_array($role, ['accountant', 'field_accountant'], true)) {
    echo '<div style="padding:40px;text-align:center;color:#666">Workbench access requires admin or accountant role.</div>';
    return;
}

// ── Self-heal schema (defensive, idempotent — same pattern as starlink_suspensions) ──
$pdo = $store->getPdo();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS overdue_workbench (
        invoice_number TEXT PRIMARY KEY,
        client_id INTEGER NOT NULL DEFAULT 0,
        client_name TEXT NOT NULL DEFAULT '',
        amount_due REAL NOT NULL DEFAULT 0,
        days_overdue INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'open',
        promised_pay_date TEXT, promised_amount REAL,
        assigned_to TEXT, pause_until TEXT,
        last_note TEXT, last_action_by TEXT, last_action_at TEXT,
        contact_attempts INTEGER NOT NULL DEFAULT 0, last_contact_at TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        closed_at TEXT, close_reason TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS overdue_workbench_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL, client_id INTEGER NOT NULL DEFAULT 0,
        action TEXT NOT NULL, detail TEXT, old_value TEXT, new_value TEXT,
        by_retailer TEXT NOT NULL DEFAULT '', by_retailer_id INTEGER,
        at_iso TEXT NOT NULL DEFAULT (datetime('now'))
    )");
} catch (\Throwable $e) {}

// ── Server-side SMTP config presence check (file only, no network) ──
// Live TCP/TLS/AUTH probe is at owb_smtp_check endpoint, fired from JS.
$smtpFile = $dataDir . '/email_settings.json';
$smtpHasConfig = false;
$smtpHint = '';
if (file_exists($smtpFile)) {
    $smtpCfg = json_decode((string)file_get_contents($smtpFile), true) ?: [];
    $hHost = (string)($smtpCfg['smtp_host'] ?? '');
    $hUser = (string)($smtpCfg['smtp_user'] ?? '');
    $hPass = (string)($smtpCfg['smtp_pass'] ?? '');
    if ($hHost !== '' && $hUser !== '' && $hPass !== '') {
        $smtpHasConfig = true;
        $smtpHint = "{$hUser} via {$hHost}";
    } else {
        $smtpHint = 'partial config — host/user/password missing';
    }
} else {
    $smtpHint = 'no email_settings.json found';
}

// ── Retailer list for assignee picker (active retailers only) ──
$retailers = $store->load('retailers.json') ?? [];
$assignees = [];
foreach ($retailers as $r) {
    if (!empty($r['name']) && empty($r['archived']) && empty($r['suspended'])) {
        $assignees[] = (string)$r['name'];
    }
}
sort($assignees);

// ── Plugin version (footer badge) ──
$pluginVersion = '4.21.66';
try {
    $mf = json_decode((string)@file_get_contents(dirname(__DIR__, 2) . '/manifest.json'), true);
    if (!empty($mf['information']['version'])) $pluginVersion = (string)$mf['information']['version'];
} catch (\Throwable $e) {}

?>
<style>
.owb-wrap { max-width: 1500px; margin: 0 auto; padding: 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937; }
.owb-h { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:10px; }
.owb-h h2 { margin:0; font-size:22px; font-weight:800; color:#111827; }
.owb-h .sub { font-size:13px; color:#6b7280; margin-top:2px; }

.owb-banner { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; line-height:1.5; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.owb-banner.red    { background:#fef2f2; border:1.5px solid #fecaca; color:#991b1b; }
.owb-banner.amber  { background:#fffbeb; border:1.5px solid #fde68a; color:#92400e; }
.owb-banner.green  { background:#f0fdf4; border:1.5px solid #bbf7d0; color:#166534; }
.owb-banner.gray   { background:#f9fafb; border:1.5px solid #e5e7eb; color:#475569; }
.owb-banner b { color: inherit; }
.owb-banner a { color:inherit; text-decoration:underline; font-weight:700; }
.owb-banner .grow { flex:1; min-width:200px; }
.owb-banner button { background:rgba(255,255,255,.6); border:1px solid currentColor; padding:4px 12px; border-radius:6px; cursor:pointer; font-size:12px; color:inherit; font-weight:700; }

.owb-kpis { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:14px; }
.owb-kpi { background:#fff; border:1.5px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
.owb-kpi .lbl { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; font-weight:700; }
.owb-kpi .val { font-size:22px; font-weight:800; color:#111827; margin-top:4px; }
.owb-kpi .sub { font-size:11px; color:#9ca3af; margin-top:2px; }
.owb-kpi.danger  { background:#fef2f2; border-color:#fecaca; }
.owb-kpi.danger .val { color:#991b1b; }
.owb-kpi.warn    { background:#fffbeb; border-color:#fde68a; }
.owb-kpi.warn .val { color:#92400e; }

.owb-bucket-row { display:grid; grid-template-columns: repeat(6, 1fr); gap:6px; margin-bottom:14px; }
.owb-bucket { background:#fff; border:1.5px solid #e5e7eb; border-radius:8px; padding:10px 8px; text-align:center; cursor:pointer; transition:all .15s; }
.owb-bucket:hover { border-color:#3b82f6; }
.owb-bucket.active { border-color:#3b82f6; background:#eff6ff; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
.owb-bucket .name { font-size:11px; color:#6b7280; font-weight:600; }
.owb-bucket .count { font-size:18px; font-weight:800; color:#111827; margin:2px 0; }
.owb-bucket .amt { font-size:11px; color:#6b7280; }
.owb-bucket.b1 .count { color:#0891b2; }
.owb-bucket.b2 .count { color:#d97706; }
.owb-bucket.b3 .count { color:#ea580c; }
.owb-bucket.b4 .count { color:#dc2626; }
.owb-bucket.b5 .count { color:#7f1d1d; }
.owb-bucket.b6 .count { color:#475569; }

.owb-filter { background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.owb-filter label { font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.owb-filter select, .owb-filter input { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff; }
.owb-filter input[type=number] { width:90px; }
.owb-filter input[type=text] { width:220px; }
.owb-filter .clear-btn,
.owb-filter .csv-btn { background:#fff; border:1px solid #d1d5db; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:13px; color:#6b7280; text-decoration:none; }
.owb-filter .csv-btn { color:#1d4ed8; border-color:#bfdbfe; }
.owb-filter .toggle-row { display:flex; gap:8px; flex-wrap:wrap; }
.owb-filter .toggle { font-size:12px; padding:5px 10px; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; user-select:none; color:#6b7280; }
.owb-filter .toggle.on { background:#fef3c7; border-color:#f59e0b; color:#92400e; font-weight:700; }

.owb-bulkbar { background:#fffbeb; border:1.5px solid #fde68a; border-radius:10px; padding:10px 14px; margin-bottom:12px; display:none; align-items:center; gap:10px; flex-wrap:wrap; }
.owb-bulkbar.show { display:flex; }
.owb-bulkbar .picked { font-weight:700; color:#92400e; }
.owb-bulkbar select, .owb-bulkbar input { padding:6px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; background:#fff; }
.owb-bulkbar button { padding:6px 14px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:600; }
.owb-bulkbar .b-apply { background:#2563eb; color:#fff; }
.owb-bulkbar .b-clear { background:#fff; color:#6b7280; border:1px solid #d1d5db; }

.owb-tbl-wrap { background:#fff; border:1.5px solid #e5e7eb; border-radius:10px; overflow:hidden; }
.owb-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.owb-tbl thead { background:#f9fafb; }
.owb-tbl th { text-align:left; padding:10px 8px; font-size:11px; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.4px; border-bottom:1.5px solid #e5e7eb; }
.owb-tbl td { padding:10px 8px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
.owb-tbl tr:hover { background:#fafafa; }
.owb-tbl tr.row-broken { background:#fef2f2; }
.owb-tbl tr.row-broken:hover { background:#fee2e2; }
.owb-tbl tr.row-promised { background:#fefce8; }
.owb-tbl tr.row-due-today { background:#fef3c7; }
.owb-tbl .inv { font-weight:700; color:#1f2937; font-family:monospace; font-size:12px; }
.owb-tbl .nm { color:#1f2937; font-weight:600; }
.owb-tbl .ph { font-size:11px; color:#9ca3af; font-family:monospace; }
.owb-tbl .amt { color:#dc2626; font-weight:700; text-align:right; font-variant-numeric: tabular-nums; }
.owb-tbl .age { font-weight:700; }
.owb-tbl .age.b1 { color:#0891b2; } .owb-tbl .age.b2 { color:#d97706; }
.owb-tbl .age.b3 { color:#ea580c; } .owb-tbl .age.b4 { color:#dc2626; }
.owb-tbl .age.b5 { color:#7f1d1d; } .owb-tbl .age.b6 { color:#475569; }
.owb-tbl .pill { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.owb-tbl .pill.open      { background:#e5e7eb; color:#374151; }
.owb-tbl .pill.promised  { background:#fef3c7; color:#92400e; }
.owb-tbl .pill.in_field  { background:#dbeafe; color:#1d4ed8; }
.owb-tbl .pill.disputed  { background:#fee2e2; color:#991b1b; }
.owb-tbl .pill.unreachable { background:#f3e8ff; color:#6b21a8; }
.owb-tbl .pill.write_off_req { background:#fef2f2; color:#991b1b; }
.owb-tbl .pill.paused_followup { background:#e0e7ff; color:#3730a3; }
.owb-tbl .stage-pill { font-size:10px; padding:2px 6px; background:#f3f4f6; color:#6b7280; border-radius:8px; display:inline-block; }
.owb-tbl .stage-pill.s9 { background:#fef3c7; color:#92400e; }
.owb-tbl .stage-pill.fail { background:#fee2e2; color:#991b1b; }
.owb-tbl .note { color:#6b7280; font-size:11px; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.owb-tbl .actions { white-space:nowrap; }
.owb-tbl .actions a { display:inline-block; padding:3px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:11px; color:#374151; text-decoration:none; margin-left:3px; cursor:pointer; }
.owb-tbl .actions a:hover { background:#f3f4f6; border-color:#9ca3af; }
.owb-tbl .actions a.primary { background:#2563eb; color:#fff; border-color:#2563eb; }
.owb-tbl .actions a.primary:hover { background:#1d4ed8; }

.owb-pagination { padding:12px 14px; display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e5e7eb; background:#f9fafb; gap:10px; flex-wrap:wrap; }
.owb-pagination button { padding:6px 12px; border:1px solid #d1d5db; background:#fff; border-radius:6px; cursor:pointer; font-size:13px; }
.owb-pagination button:disabled { opacity:.4; cursor:not-allowed; }
.owb-pagination .info { font-size:12px; color:#6b7280; }
.owb-pagination select { padding:5px 8px; border:1px solid #d1d5db; border-radius:5px; font-size:12px; }

.owb-loading { padding:60px; text-align:center; color:#9ca3af; }
.owb-empty   { padding:60px; text-align:center; color:#9ca3af; }

.owb-modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:9999; padding:20px; }
.owb-modal-bg.show { display:flex; }
.owb-modal { background:#fff; border-radius:14px; max-width:640px; width:100%; max-height:85vh; overflow:auto; }
.owb-modal-h { padding:18px 22px; border-bottom:1.5px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
.owb-modal-h h3 { margin:0; font-size:17px; font-weight:800; }
.owb-modal-h .close { background:none; border:none; font-size:22px; cursor:pointer; color:#9ca3af; }
.owb-modal-b { padding:18px 22px; }
.owb-modal-f { padding:14px 22px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:8px; }
.owb-modal-f button { padding:8px 16px; border-radius:7px; border:none; cursor:pointer; font-size:13px; font-weight:700; }
.owb-modal-f .b-cancel { background:#fff; color:#6b7280; border:1px solid #d1d5db; }
.owb-modal-f .b-ok { background:#2563eb; color:#fff; }
.owb-modal-b label { display:block; font-size:11px; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.4px; margin-top:12px; margin-bottom:4px; }
.owb-modal-b input, .owb-modal-b select, .owb-modal-b textarea { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; box-sizing:border-box; }
.owb-modal-b textarea { min-height:80px; resize:vertical; }
.owb-modal-b .ctx { background:#f9fafb; padding:10px 12px; border-radius:8px; font-size:12px; color:#6b7280; margin-bottom:6px; }
.owb-modal-b .ctx b { color:#1f2937; }

.owb-history-row { padding:10px 0; border-bottom:1px solid #f3f4f6; }
.owb-history-row:last-child { border-bottom:none; }
.owb-history-row .meta { font-size:11px; color:#9ca3af; margin-bottom:3px; }
.owb-history-row .body { font-size:13px; color:#1f2937; }
.owb-history-row.auto .meta::before { content:'🤖 '; }
.owb-history-row.human .meta::before { content:'👤 '; }
</style>

<div class="owb-wrap">
  <div class="owb-h">
    <div>
      <h2>Overdue Workbench</h2>
      <div class="sub">Operator queue for working overdue invoices · paired with the auto-dunning chain</div>
    </div>
    <div style="font-size:11px;color:#9ca3af;">v<?= htmlspecialchars($pluginVersion) ?></div>
  </div>

  <?php if (!$smtpHasConfig): ?>
  <div class="owb-banner red">
    <span>⚠️</span>
    <div class="grow">
      <b>SMTP not configured</b> · <?= htmlspecialchars($smtpHint) ?>.
      Auto-dunning emails (Stages 1, 2, 4, 6, 7, 8, 9) will <b>not be delivered</b>.
      WhatsApp stages (3, 5, 9) and field/phone follow-up still work.
      Fix at <a href="?page=dashboard&tab=smtp_diagnostic">SMTP Diagnostic</a>.
    </div>
  </div>
  <?php else: ?>
  <div class="owb-banner gray" id="smtpBanner">
    <span>📨</span>
    <div class="grow">Plugin SMTP: <?= htmlspecialchars($smtpHint) ?>. Click <b>Run live test</b> to verify TCP+TLS+AUTH actually work today.</div>
    <button onclick="owbSmtpCheck()">Run live test</button>
  </div>
  <?php endif; ?>

  <div class="owb-kpis">
    <div class="owb-kpi"><div class="lbl">Total overdue</div><div class="val" id="kpiCount">…</div><div class="sub" id="kpiAmount">$ —</div></div>
    <div class="owb-kpi warn"><div class="lbl">Promises today</div><div class="val" id="kpiPromised">…</div><div class="sub">customers said pay today</div></div>
    <div class="owb-kpi danger"><div class="lbl">Promises broken</div><div class="val" id="kpiBroken">…</div><div class="sub">past promise date, still unpaid</div></div>
    <div class="owb-kpi"><div class="lbl">Unassigned</div><div class="val" id="kpiUnassigned">…</div><div class="sub">no follow-up owner</div></div>
    <div class="owb-kpi"><div class="lbl">Untouched 30d</div><div class="val" id="kpiStale">…</div><div class="sub">no auto or human action</div></div>
  </div>

  <div class="owb-bucket-row" id="owbBuckets">
    <div class="owb-bucket b1" data-bucket="1-14"><div class="name">1-14 days</div><div class="count">—</div><div class="amt">—</div></div>
    <div class="owb-bucket b2" data-bucket="15-30"><div class="name">15-30 days</div><div class="count">—</div><div class="amt">—</div></div>
    <div class="owb-bucket b3" data-bucket="31-60"><div class="name">31-60 days</div><div class="count">—</div><div class="amt">—</div></div>
    <div class="owb-bucket b4" data-bucket="61-90"><div class="name">61-90 days</div><div class="count">—</div><div class="amt">—</div></div>
    <div class="owb-bucket b5" data-bucket="91-180"><div class="name">91-180 days</div><div class="count">—</div><div class="amt">—</div></div>
    <div class="owb-bucket b6" data-bucket="180+"><div class="name">180+ days</div><div class="count">—</div><div class="amt">—</div></div>
  </div>

  <div class="owb-filter">
    <label>Status</label>
    <select id="fStatus">
      <option value="">All open</option>
      <option value="open">Open (untouched)</option>
      <option value="promised">Promised</option>
      <option value="in_field">In field</option>
      <option value="disputed">Disputed</option>
      <option value="unreachable">Unreachable</option>
      <option value="write_off_req">Write-off requested</option>
      <option value="paused_followup">Paused follow-up</option>
    </select>
    <label>Assignee</label>
    <select id="fAssignee">
      <option value="">— All —</option>
      <?php foreach ($assignees as $a): ?>
        <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Min $</label>
    <input type="number" id="fMin" placeholder="0" min="0" step="10">
    <label>Search</label>
    <input type="text" id="fSearch" placeholder="name, phone, INV#, note">
    <div class="toggle-row">
      <span class="toggle" id="tBroken"   onclick="owbToggle(this,'broken')">Broken promises</span>
      <span class="toggle" id="tDueToday" onclick="owbToggle(this,'due_today')">Promises today</span>
      <span class="toggle" id="tUnassign" onclick="owbToggle(this,'unassigned')">Unassigned</span>
    </div>
    <button class="clear-btn" onclick="owbClearFilters()">Clear</button>
    <a class="csv-btn" href="#" id="csvLink" onclick="return owbDownloadCsv()">Export CSV</a>
  </div>

  <div class="owb-bulkbar" id="owbBulk">
    <span class="picked"><span id="bulkCount">0</span> selected</span>
    <select id="bulkAction">
      <option value="">— Bulk action —</option>
      <option value="send_messages">📧 Send messages (email + WhatsApp)</option>
      <option value="assign">Assign to…</option>
      <option value="status_in_field">Mark "in field"</option>
      <option value="status_unreachable">Mark "unreachable"</option>
      <option value="status_disputed">Mark "disputed"</option>
      <option value="status_write_off_req">Mark "write-off requested"</option>
      <option value="status_open">Reopen (back to "open")</option>
    </select>
    <select id="bulkAssignee" style="display:none;">
      <option value="">— Pick retailer —</option>
      <option value="__UNASSIGN__">— Unassign —</option>
      <?php foreach ($assignees as $a): ?>
        <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" id="bulkNote" placeholder="optional note" style="flex:1;min-width:200px;">
    <button class="b-apply" onclick="owbApplyBulk()">Apply</button>
    <button class="b-clear" onclick="owbClearSelection()">Clear</button>
  </div>

  <div class="owb-tbl-wrap">
    <table class="owb-tbl">
      <thead>
        <tr>
          <th style="width:30px;"><input type="checkbox" id="selAll" onchange="owbToggleAll(this)"></th>
          <th>Invoice</th>
          <th>Client</th>
          <th>Amount</th>
          <th>Days</th>
          <th>Last auto-touch</th>
          <th>Status</th>
          <th>Assigned</th>
          <th>Promise</th>
          <th>Last note</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="owbBody"><tr><td colspan="11" class="owb-loading">Loading…</td></tr></tbody>
    </table>
    <div class="owb-pagination">
      <div>
        <button id="pagPrev" onclick="owbPage(-1)">‹ Prev</button>
        <button id="pagNext" onclick="owbPage(1)">Next ›</button>
        <span class="info" id="pagInfo" style="margin-left:10px;">—</span>
      </div>
      <div>
        <label style="font-size:12px;color:#6b7280;margin-right:6px;">Page size</label>
        <select id="pagSize" onchange="owbResize()">
          <option value="50">50</option>
          <option value="100" selected>100</option>
          <option value="200">200</option>
          <option value="500">500</option>
        </select>
      </div>
    </div>
  </div>
</div>

<div class="owb-modal-bg" id="owbModalBg" onclick="if(event.target===this)owbCloseModal()">
  <div class="owb-modal">
    <div class="owb-modal-h">
      <h3 id="owbModalTitle">—</h3>
      <button class="close" onclick="owbCloseModal()">×</button>
    </div>
    <div class="owb-modal-b" id="owbModalBody">—</div>
    <div class="owb-modal-f" id="owbModalFoot">
      <button class="b-cancel" onclick="owbCloseModal()">Cancel</button>
      <button class="b-ok" id="owbModalOk">Save</button>
    </div>
  </div>
</div>

<script>
const OWB = {
  filters: {
    bucket: 'all', status: '', assigned_to: '', min_amount: '', q: '',
    only_broken: false, only_promises_due: false, unassigned_only: false, include_paused: false,
  },
  rows: [], summary: {}, selected: new Set(), page: 0, pageSize: 100,
};
const ASSIGNEES = <?= json_encode($assignees) ?>;

function owbApiUrl(action, params = {}) {
  const u = new URLSearchParams({ action, ...params });
  return `?page=api&${u.toString()}&_=${Date.now()}`;
}
async function owbFetchJson(url, opts = {}) {
  opts.cache = 'no-store';
  if (!opts.headers) opts.headers = {};
  if (opts.method === 'POST' && opts.body && typeof opts.body !== 'string') {
    opts.body = JSON.stringify(opts.body);
    opts.headers['Content-Type'] = 'application/json';
  }
  const r = await fetch(url, opts);
  if (!r.ok) {
    let msg = `HTTP ${r.status}`;
    try { const j = await r.json(); if (j.message) msg = j.message; } catch(e) {}
    throw new Error(msg);
  }
  return r.json();
}
function owbFmtMoney(n) { return '$' + (Math.round((n||0)*100)/100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function owbFmtDate(s) { if (!s) return '—'; return s.length > 10 ? s.substring(0, 16).replace('T', ' ') : s; }
function owbBucketClass(b) { return { '1-14':'b1','15-30':'b2','31-60':'b3','61-90':'b4','91-180':'b5','180+':'b6' }[b] || ''; }
function owbStageLabel(s, lbl) {
  const labels = {1:'Email #1 nudge',2:'Email #2 followup',3:'WA midpoint',4:'Email #3 firm',5:'WA urgent',6:'Email #4 final',7:'Email #5 long',8:'Email #6 last',9:'Monthly recurring'};
  return labels[s] || lbl || `Stage ${s}`;
}
function escHtml(s) { if (s === null || s === undefined) return ''; return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

function owbBuildParams() {
  const p = {};
  if (OWB.filters.bucket && OWB.filters.bucket !== 'all') p.bucket = OWB.filters.bucket;
  if (OWB.filters.status) p.status = OWB.filters.status;
  if (OWB.filters.assigned_to) p.assigned_to = OWB.filters.assigned_to;
  if (OWB.filters.min_amount) p.min_amount = OWB.filters.min_amount;
  if (OWB.filters.q) p.q = OWB.filters.q;
  if (OWB.filters.only_broken) p.only_broken = 1;
  if (OWB.filters.only_promises_due) p.only_promises_due = 1;
  if (OWB.filters.unassigned_only) p.unassigned_only = 1;
  if (OWB.filters.include_paused) p.include_paused = 1;
  return p;
}

async function owbLoadList() {
  document.getElementById('owbBody').innerHTML = '<tr><td colspan="11" class="owb-loading">Loading…</td></tr>';
  try {
    const j = await owbFetchJson(owbApiUrl('owb_list', owbBuildParams()));
    const d = j.data || j;
    OWB.rows = d.rows || [];
    OWB.summary = d.summary || {};
    OWB.page = 0;
    owbRenderSummary();
    owbRenderTable();
  } catch (e) {
    document.getElementById('owbBody').innerHTML = `<tr><td colspan="11" class="owb-empty">Error: ${escHtml(e.message)}</td></tr>`;
  }
}

function owbRenderSummary() {
  const s = OWB.summary || {};
  document.getElementById('kpiCount').textContent     = (s.count || 0).toLocaleString();
  document.getElementById('kpiAmount').textContent    = owbFmtMoney(s.total_due || 0);
  document.getElementById('kpiPromised').textContent  = s.promises_today || 0;
  document.getElementById('kpiBroken').textContent    = s.broken_promises || 0;
  document.getElementById('kpiUnassigned').textContent= s.unassigned_count || 0;
  document.getElementById('kpiStale').textContent     = s.untouched_30d || 0;
  document.querySelectorAll('.owb-bucket').forEach(el => {
    const b = el.dataset.bucket;
    const cnt = (s.by_bucket || {})[b] || 0;
    const amt = (s.amt_bucket || {})[b] || 0;
    el.querySelector('.count').textContent = cnt.toLocaleString();
    el.querySelector('.amt').textContent = owbFmtMoney(amt);
  });
}

function owbRenderTable() {
  const tbody = document.getElementById('owbBody');
  if (OWB.rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="11" class="owb-empty">No invoices match these filters.</td></tr>';
    owbRenderPagination();
    return;
  }
  const start = OWB.page * OWB.pageSize;
  const end = Math.min(start + OWB.pageSize, OWB.rows.length);
  const slice = OWB.rows.slice(start, end);
  tbody.innerHTML = slice.map(r => {
    const checked = OWB.selected.has(r.invoice_number) ? 'checked' : '';
    let rowCls = '';
    if (r.promise_status === 'broken') rowCls = 'row-broken';
    else if (r.promise_status === 'due_today') rowCls = 'row-due-today';
    else if (r.status === 'promised') rowCls = 'row-promised';
    const lastTouch = r.last_email_at
      ? `<div style="font-size:11px;line-height:1.4;">
          <span class="stage-pill s${r.last_email_stage} ${r.last_email_success===0?'fail':''}">${escHtml(owbStageLabel(r.last_email_stage, r.last_email_label))}</span>
          <div style="font-size:10px;color:#9ca3af;margin-top:2px;">${owbFmtDate(r.last_email_at)}${r.last_email_success===0?' ⚠️':''}</div>
        </div>`
      : '<span style="color:#d1d5db;">never</span>';
    let promise = '<span style="color:#d1d5db;">—</span>';
    if (r.promised_pay_date) {
      const cls = r.promise_status === 'broken' ? 'color:#dc2626;font-weight:700' :
                  r.promise_status === 'due_today' ? 'color:#92400e;font-weight:700' :
                  'color:#374151';
      promise = `<div style="font-size:12px;${cls}">${escHtml(r.promised_pay_date)}${
        r.promised_amount ? '<br><span style="font-size:10px;color:#9ca3af;font-weight:normal;">'+owbFmtMoney(r.promised_amount)+'</span>' : ''
      }</div>`;
    }
    const phone = r.phone ? `<div class="ph">${escHtml(r.phone)}</div>` : '';
    return `
      <tr class="${rowCls}">
        <td><input type="checkbox" data-inv="${escHtml(r.invoice_number)}" onchange="owbToggleOne(${JSON.stringify(r.invoice_number)}, this.checked)" ${checked}></td>
        <td><span class="inv">${escHtml(r.invoice_number)}</span><div style="font-size:10px;color:#9ca3af;">#${r.client_id}</div></td>
        <td><div class="nm">${escHtml(r.client_name)}</div>${phone}</td>
        <td class="amt">${owbFmtMoney(r.amount_due)}</td>
        <td class="age ${owbBucketClass(r.bucket)}">${r.days_overdue}d</td>
        <td>${lastTouch}</td>
        <td><span class="pill ${escHtml(r.status)}">${escHtml((r.status||'open').replace(/_/g,' '))}</span>
          ${r.contact_attempts > 0 ? `<div style="font-size:10px;color:#9ca3af;margin-top:2px;">${r.contact_attempts} attempt${r.contact_attempts>1?'s':''}</div>` : ''}
        </td>
        <td>${escHtml(r.assigned_to || '—')}</td>
        <td>${promise}</td>
        <td><div class="note" title="${escHtml(r.last_note || '')}">${escHtml(r.last_note || '')}</div>
          ${r.last_action_by ? `<div style="font-size:10px;color:#9ca3af;">by ${escHtml(r.last_action_by)}</div>` : ''}</td>
        <td class="actions">
          <a class="primary" onclick='owbOpenContact(${JSON.stringify(r.invoice_number)})'>Log call</a>
          <a onclick='owbOpenPromise(${JSON.stringify(r.invoice_number)})'>Promise</a>
          <a onclick='owbOpenAssign(${JSON.stringify(r.invoice_number)})'>Assign</a>
          <a onclick='owbOpenStatus(${JSON.stringify(r.invoice_number)})'>Status</a>
          <a onclick='owbOpenHistory(${JSON.stringify(r.invoice_number)})'>History</a>
        </td>
      </tr>`;
  }).join('');
  owbRenderPagination();
  owbUpdateBulkBar();
}

function owbRenderPagination() {
  const total = OWB.rows.length;
  const start = OWB.page * OWB.pageSize + (total > 0 ? 1 : 0);
  const end = Math.min((OWB.page + 1) * OWB.pageSize, total);
  document.getElementById('pagInfo').textContent = total === 0 ? '—' : `${start}-${end} of ${total.toLocaleString()}`;
  document.getElementById('pagPrev').disabled = OWB.page === 0;
  document.getElementById('pagNext').disabled = end >= total;
}
function owbPage(dir) {
  const newPage = OWB.page + dir;
  const maxPage = Math.max(0, Math.ceil(OWB.rows.length / OWB.pageSize) - 1);
  OWB.page = Math.max(0, Math.min(newPage, maxPage));
  owbRenderTable();
}
function owbResize() {
  OWB.pageSize = parseInt(document.getElementById('pagSize').value, 10) || 100;
  OWB.page = 0;
  owbRenderTable();
}
function owbReadFilters() {
  OWB.filters.status      = document.getElementById('fStatus').value;
  OWB.filters.assigned_to = document.getElementById('fAssignee').value;
  OWB.filters.min_amount  = document.getElementById('fMin').value;
  OWB.filters.q           = document.getElementById('fSearch').value;
}
function owbClearFilters() {
  document.getElementById('fStatus').value = '';
  document.getElementById('fAssignee').value = '';
  document.getElementById('fMin').value = '';
  document.getElementById('fSearch').value = '';
  OWB.filters.bucket = 'all';
  OWB.filters.only_broken = false;
  OWB.filters.only_promises_due = false;
  OWB.filters.unassigned_only = false;
  document.querySelectorAll('.owb-bucket').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.toggle').forEach(el => el.classList.remove('on'));
  owbReadFilters();
  owbLoadList();
}
function owbToggle(el, kind) {
  el.classList.toggle('on');
  const on = el.classList.contains('on');
  if (kind === 'broken')    OWB.filters.only_broken = on;
  if (kind === 'due_today') OWB.filters.only_promises_due = on;
  if (kind === 'unassigned')OWB.filters.unassigned_only = on;
  owbLoadList();
}
document.querySelectorAll('.owb-bucket').forEach(el => {
  el.addEventListener('click', () => {
    const b = el.dataset.bucket;
    if (OWB.filters.bucket === b) {
      OWB.filters.bucket = 'all';
      el.classList.remove('active');
    } else {
      OWB.filters.bucket = b;
      document.querySelectorAll('.owb-bucket').forEach(e => e.classList.remove('active'));
      el.classList.add('active');
    }
    owbLoadList();
  });
});
let _searchT;
['fStatus','fAssignee','fMin'].forEach(id => {
  document.getElementById(id).addEventListener('change', () => { owbReadFilters(); owbLoadList(); });
});
document.getElementById('fSearch').addEventListener('input', () => {
  clearTimeout(_searchT);
  _searchT = setTimeout(() => { owbReadFilters(); owbLoadList(); }, 350);
});

function owbToggleOne(invNum, on) {
  if (on) OWB.selected.add(invNum); else OWB.selected.delete(invNum);
  owbUpdateBulkBar();
}
function owbToggleAll(cb) {
  const start = OWB.page * OWB.pageSize;
  const end = Math.min(start + OWB.pageSize, OWB.rows.length);
  const slice = OWB.rows.slice(start, end);
  if (cb.checked) slice.forEach(r => OWB.selected.add(r.invoice_number));
  else slice.forEach(r => OWB.selected.delete(r.invoice_number));
  document.querySelectorAll('#owbBody input[type=checkbox]').forEach(c => { c.checked = cb.checked; });
  owbUpdateBulkBar();
}
function owbClearSelection() {
  OWB.selected.clear();
  document.getElementById('selAll').checked = false;
  document.querySelectorAll('#owbBody input[type=checkbox]').forEach(c => { c.checked = false; });
  owbUpdateBulkBar();
}
function owbUpdateBulkBar() {
  const n = OWB.selected.size;
  document.getElementById('bulkCount').textContent = n;
  document.getElementById('owbBulk').classList.toggle('show', n > 0);
}
document.getElementById('bulkAction').addEventListener('change', e => {
  document.getElementById('bulkAssignee').style.display = e.target.value === 'assign' ? '' : 'none';
});

async function owbApplyBulk() {
  const action = document.getElementById('bulkAction').value;
  if (!action) { alert('Pick a bulk action.'); return; }
  const note = document.getElementById('bulkNote').value.trim();
  const invNums = Array.from(OWB.selected);
  if (invNums.length === 0) return;

  // v4.21.67: Bulk-send opens a confirmation modal with channel/template/throttle
  // controls instead of a one-line confirm — too consequential for a quick OK.
  if (action === 'send_messages') {
    owbOpenBulkSendModal(invNums);
    return;
  }

  if (!confirm(`Apply "${action}" to ${invNums.length} invoice(s)?`)) return;
  try {
    let body, url;
    if (action === 'assign') {
      let assignee = document.getElementById('bulkAssignee').value;
      if (!assignee) { alert('Pick a retailer (or "Unassign").'); return; }
      if (assignee === '__UNASSIGN__') assignee = null;
      url = owbApiUrl('owb_bulk_assign');
      body = { invoice_numbers: invNums, assigned_to: assignee, note };
    } else if (action.startsWith('status_')) {
      const newStatus = action.replace('status_', '');
      url = owbApiUrl('owb_bulk_status');
      body = { invoice_numbers: invNums, status: newStatus, note };
    }
    const j = await owbFetchJson(url, { method: 'POST', body });
    const d = j.data || j;
    const errCount = d.errors ? Object.keys(d.errors).length : 0;
    alert(`Done. Updated: ${d.updated || 0}${errCount ? ` · Errors: ${errCount}` : ''}`);
    owbClearSelection();
    owbLoadList();
  } catch (e) {
    alert('Bulk action failed: ' + e.message);
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Bulk-send modal — v4.21.67. Channel + template-override + throttle picker
// with cost summary and an explicit confirm step. Single big "Send Now" press
// fires owb_bulk_send and shows progress + final results inline.
// ─────────────────────────────────────────────────────────────────────────
function owbOpenBulkSendModal(invNums) {
  // Estimate by-stage breakdown so operator sees what they're about to send
  const stageBreakdown = {};
  let totalDue = 0;
  invNums.forEach(n => {
    const r = OWB.rows.find(x => x.invoice_number === n);
    if (!r) return;
    const stg = owbStageForDays(r.days_overdue);
    stageBreakdown[stg] = (stageBreakdown[stg] || 0) + 1;
    totalDue += parseFloat(r.amount_due) || 0;
  });
  const stageRows = Object.keys(stageBreakdown).sort((a,b)=>+a-+b).map(s =>
    `<tr><td>Stage ${s}</td><td>${owbStageLabel(parseInt(s))}</td><td style="text-align:right;">${stageBreakdown[s]}</td></tr>`
  ).join('');

  const html = `
    <div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:13px;color:#92400e;">
      You are about to send messages to <b>${invNums.length} invoices</b> totaling <b>${owbFmtMoney(totalDue)}</b>.
      Each customer will receive ONE email and/or ONE WhatsApp.
    </div>

    <label>Channels</label>
    <div style="display:flex;gap:14px;align-items:center;margin:6px 0 10px;">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;font-weight:500;text-transform:none;letter-spacing:0;margin:0;">
        <input type="checkbox" id="bsEmail" checked> Email
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;color:#374151;font-weight:500;text-transform:none;letter-spacing:0;margin:0;">
        <input type="checkbox" id="bsWa" checked> WhatsApp
      </label>
    </div>

    <label>Template</label>
    <select id="bsStage">
      <option value="">Auto — pick stage by days overdue (recommended)</option>
      <option value="1">Force Stage 1 — Polite nudge (Day 14-30)</option>
      <option value="2">Force Stage 2 — Gentle followup (Day 31-44)</option>
      <option value="3">Force Stage 3 — WhatsApp midpoint (Day 45-60)</option>
      <option value="4">Force Stage 4 — Firm but respectful (Day 61-74)</option>
      <option value="5">Force Stage 5 — WhatsApp urgent (Day 75-89)</option>
      <option value="6">Force Stage 6 — Final notice (Day 90-119)</option>
      <option value="7">Force Stage 7 — Long unpaid (Day 120-179)</option>
      <option value="8">Force Stage 8 — Last formal contact (Day 180-209)</option>
      <option value="9">Force Stage 9 — Monthly soft reminder (Day 210+)</option>
    </select>

    <label style="margin-top:12px;">Sending speed</label>
    <select id="bsThrottle">
      <option value="2000" selected>Slow — 1 every 2 seconds (safest, recommended)</option>
      <option value="500">Medium — ~2 per second</option>
      <option value="200">Fast — ~5 per second (may trip rate limits)</option>
    </select>
    <div id="bsTimeEst" style="font-size:11px;color:#9ca3af;margin-top:4px;"></div>

    <label style="margin-top:12px;">
      <input type="checkbox" id="bsRespectDedup" checked style="width:auto;margin-right:6px;vertical-align:middle;">
      Skip invoices already sent this stage (recommended — prevents spam)
    </label>

    ${invNums.length > 0 && stageRows ? `
    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;margin-top:14px;">
      <div style="font-size:11px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;">Auto-stage breakdown</div>
      <table style="width:100%;font-size:12px;">${stageRows}</table>
    </div>
    ` : ''}
  `;

  owbOpenModal('📧 Bulk send messages', html, () => owbDoBulkSend(invNums), 'Send Now');

  // Wire time estimate
  const updateEst = () => {
    const ms = parseInt(document.getElementById('bsThrottle').value, 10);
    const totalSec = (invNums.length * ms) / 1000;
    const m = Math.floor(totalSec / 60);
    const s = Math.round(totalSec % 60);
    document.getElementById('bsTimeEst').textContent =
      `Estimated time: ~${m > 0 ? m + 'm ' : ''}${s}s for ${invNums.length} invoices`;
  };
  document.getElementById('bsThrottle').addEventListener('change', updateEst);
  updateEst();
}

async function owbDoBulkSend(invNums) {
  const channels = [];
  if (document.getElementById('bsEmail').checked) channels.push('email');
  if (document.getElementById('bsWa').checked)    channels.push('whatsapp');
  if (channels.length === 0) { alert('Pick at least one channel.'); return; }

  const stageOv  = document.getElementById('bsStage').value || null;
  const throttle = parseInt(document.getElementById('bsThrottle').value, 10) || 2000;
  const respectDedup = document.getElementById('bsRespectDedup').checked;

  // Replace modal content with progress while we wait
  const totalSec = Math.round((invNums.length * throttle) / 1000);
  document.getElementById('owbModalBody').innerHTML = `
    <div style="text-align:center;padding:24px 10px;">
      <div style="font-size:18px;font-weight:700;color:#1f2937;margin-bottom:8px;">Queueing…</div>
      <div style="color:#6b7280;font-size:13px;margin-bottom:20px;">
        ${invNums.length} invoices · ${channels.join(' + ')} · est ~${totalSec}s
      </div>
      <div style="background:#f3f4f6;border-radius:999px;height:10px;overflow:hidden;width:80%;margin:0 auto;">
        <div id="bsProgress" style="background:#2563eb;height:100%;width:0%;transition:width 0.5s linear;"></div>
      </div>
      <div id="bsLiveCounters" style="font-size:12px;color:#6b7280;margin-top:14px;font-family:ui-monospace,monospace;">starting…</div>
      <div style="font-size:11px;color:#9ca3af;margin-top:10px;">Sending runs in the background. You can close this modal — the batch keeps going.</div>
    </div>`;
  document.getElementById('owbModalOk').style.display = '';
  document.getElementById('owbModalOk').textContent = 'Close (sending continues)';
  document.getElementById('owbModalOk').onclick = () => {
    if (window.__owbBulkPoll) clearInterval(window.__owbBulkPoll);
    owbCloseModal();
    owbClearSelection();
    owbLoadList();
    if (typeof owbLoadSummary === 'function') owbLoadSummary();
  };

  // Step 1 — kick off the background job. Server flushes response immediately.
  let jobId;
  try {
    const j = await owbFetchJson(owbApiUrl('owb_bulk_send'), {
      method: 'POST',
      body: {
        invoice_numbers: invNums,
        channels: channels,
        stage_override: stageOv ? parseInt(stageOv, 10) : null,
        throttle_ms: throttle,
        respect_dedup: respectDedup,
      },
    });
    const d = j.data || j;
    jobId = d.job_id;
    if (!jobId) throw new Error('Server did not return a job_id');
  } catch (e) {
    document.getElementById('owbModalBody').innerHTML = `
      <div style="text-align:center;padding:30px 10px;">
        <div style="font-size:36px;margin-bottom:6px;">❌</div>
        <div style="font-size:16px;color:#991b1b;font-weight:700;margin-bottom:8px;">Could not queue batch</div>
        <div style="color:#6b7280;font-size:13px;font-family:monospace;">${escHtml(e.message)}</div>
      </div>`;
    return;
  }

  // Step 2 — poll the job status every 3s until running=false
  const pollUrl = owbApiUrl('owb_bulk_send_status') + '&job_id=' + encodeURIComponent(jobId);

  async function poll() {
    try {
      const r = await fetch(pollUrl, { credentials: 'same-origin', cache: 'no-store' });
      const j = await r.json();
      const d = j.data || j;

      const progressPct = d.attempted_total > 0
        ? Math.min(100, (d.progress / d.attempted_total) * 100)
        : 0;
      const bar = document.getElementById('bsProgress');
      if (bar) bar.style.width = progressPct.toFixed(1) + '%';

      const lc = document.getElementById('bsLiveCounters');
      if (lc) {
        lc.innerHTML =
          `<b>${d.progress || 0}</b>/${d.attempted_total} · ` +
          `📧 ${d.sent_email || 0} · 💬 ${d.sent_wa || 0} · ` +
          `⏭ ${d.skipped_dedup || 0} · ❌ ${d.errors_count || 0}`;
      }

      if (d.running === false) {
        clearInterval(window.__owbBulkPoll);
        window.__owbBulkPoll = null;
        renderFinalResults(d);
      }
    } catch (e) {
      console.warn('bulk-send poll failed:', e);
      // Don't tear down — transient network issues shouldn't kill the modal
    }
  }

  // Stop any prior poller, start a fresh one
  if (window.__owbBulkPoll) clearInterval(window.__owbBulkPoll);
  window.__owbBulkPoll = setInterval(poll, 3000);
  poll(); // immediate first call

  function renderFinalResults(d) {
    const errList = (d.errors || []).slice(0, 20).map(e =>
      `<div style="font-size:11px;color:#991b1b;font-family:monospace;">${escHtml(e.invoice_number)}: ${escHtml(e.error)}</div>`
    ).join('');
    const moreErrors = (d.errors || []).length > 20 ? `<div style="font-size:11px;color:#9ca3af;">…and ${d.errors.length - 20} more</div>` : '';

    document.getElementById('owbModalBody').innerHTML = `
      <div style="text-align:center;padding:14px 10px;">
        <div style="font-size:36px;margin-bottom:6px;">${d.errors_count > 0 ? '⚠️' : '✅'}</div>
        <div style="font-size:18px;font-weight:700;color:#1f2937;margin-bottom:6px;">Send complete</div>
        <div style="color:#6b7280;font-size:13px;margin-bottom:14px;">
          ${d.elapsed_seconds || 0}s · ${d.attempted_total} attempted
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px;">
        <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:10px;text-align:center;">
          <div style="font-size:22px;font-weight:800;color:#166534;">${d.sent_email || 0}</div>
          <div style="font-size:11px;color:#166534;text-transform:uppercase;letter-spacing:.4px;">Emails sent</div>
        </div>
        <div style="background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;padding:10px;text-align:center;">
          <div style="font-size:22px;font-weight:800;color:#166534;">${d.sent_wa || 0}</div>
          <div style="font-size:11px;color:#166534;text-transform:uppercase;letter-spacing:.4px;">WhatsApps sent</div>
        </div>
        <div style="background:#fefce8;border:1.5px solid #fde68a;border-radius:8px;padding:10px;text-align:center;">
          <div style="font-size:22px;font-weight:800;color:#92400e;">${d.skipped_dedup || 0}</div>
          <div style="font-size:11px;color:#92400e;text-transform:uppercase;letter-spacing:.4px;">Skipped (dedup)</div>
        </div>
        <div style="background:${d.errors_count>0?'#fef2f2':'#f9fafb'};border:1.5px solid ${d.errors_count>0?'#fecaca':'#e5e7eb'};border-radius:8px;padding:10px;text-align:center;">
          <div style="font-size:22px;font-weight:800;color:${d.errors_count>0?'#991b1b':'#9ca3af'};">${d.errors_count || 0}</div>
          <div style="font-size:11px;color:${d.errors_count>0?'#991b1b':'#9ca3af'};text-transform:uppercase;letter-spacing:.4px;">Errors</div>
        </div>
      </div>
      ${d.smtp_aborted_mid_run ? '<div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:8px;padding:10px;font-size:12px;color:#991b1b;margin-bottom:10px;">⚠️ SMTP failed early — email side aborted after first failure to save remaining attempts. WhatsApp continued. Fix at Settings → SMTP Diagnostic.</div>' : ''}
      ${d.email_disabled_reason ? '<div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:8px;padding:10px;font-size:12px;color:#991b1b;margin-bottom:10px;">📧 Email channel disabled: ' + escHtml(d.email_disabled_reason) + '</div>' : ''}
      ${errList ? '<details style="margin-top:10px;"><summary style="cursor:pointer;font-size:13px;color:#374151;font-weight:600;">View errors (' + d.errors.length + ')</summary><div style="max-height:200px;overflow:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px;margin-top:6px;">' + errList + moreErrors + '</div></details>' : ''}
    `;
    document.getElementById('owbModalOk').textContent = 'Close';
    document.getElementById('owbModalOk').onclick = () => {
      owbCloseModal();
      owbClearSelection();
      owbLoadList();
      if (typeof owbLoadSummary === 'function') owbLoadSummary();
    };
  }
}

// Map days overdue to stage (mirrors lib/OverdueDunningHelpers.php _stageForDays)
function owbStageForDays(days) {
  if (days < 14)   return 0;
  if (days <= 30)  return 1;
  if (days <= 44)  return 2;
  if (days <= 60)  return 3;
  if (days <= 74)  return 4;
  if (days <= 89)  return 5;
  if (days <= 119) return 6;
  if (days <= 179) return 7;
  if (days <= 209) return 8;
  return 9;
}

function owbOpenModal(title, bodyHtml, onSave, okLabel = 'Save') {
  document.getElementById('owbModalTitle').textContent = title;
  document.getElementById('owbModalBody').innerHTML = bodyHtml;
  const okBtn = document.getElementById('owbModalOk');
  okBtn.textContent = okLabel;
  okBtn.style.display = onSave ? '' : 'none';
  okBtn.onclick = onSave;
  document.getElementById('owbModalBg').classList.add('show');
}
function owbCloseModal() { document.getElementById('owbModalBg').classList.remove('show'); }

function owbCtxRow(invNum) {
  const r = OWB.rows.find(x => x.invoice_number === invNum);
  if (!r) return '';
  return `<div class="ctx"><b>${escHtml(r.client_name)}</b> · ${escHtml(r.invoice_number)} · ${owbFmtMoney(r.amount_due)} · ${r.days_overdue}d overdue${r.phone?' · '+escHtml(r.phone):''}</div>`;
}

function owbOpenContact(invNum) {
  const ctx = owbCtxRow(invNum);
  owbOpenModal('Log call / contact', `
    ${ctx}
    <label>Channel</label>
    <select id="mChannel">
      <option value="call">Phone call</option>
      <option value="whatsapp">WhatsApp</option>
      <option value="visit">Field visit</option>
      <option value="email">Email reply</option>
      <option value="other">Other</option>
    </select>
    <label>What happened?</label>
    <textarea id="mNote" placeholder="e.g. Called Aisha — said she'll pay Friday"></textarea>
    <p style="font-size:11px;color:#9ca3af;margin-top:8px;">Logs the contact and increments the attempts counter.</p>
  `, async () => {
    const note = document.getElementById('mNote').value.trim();
    const channel = document.getElementById('mChannel').value;
    if (!note) { alert('Note required'); return; }
    try {
      await owbFetchJson(owbApiUrl('owb_note'), { method:'POST', body: { invoice_number: invNum, note, contact_with: channel } });
      owbCloseModal(); owbLoadList();
    } catch (e) { alert('Failed: ' + e.message); }
  });
}

function owbOpenPromise(invNum) {
  const ctx = owbCtxRow(invNum);
  const r = OWB.rows.find(x => x.invoice_number === invNum);
  const today = new Date(); today.setDate(today.getDate() + 7);
  const defaultDate = (r && r.promised_pay_date) || today.toISOString().substring(0, 10);
  const defaultAmt = (r && r.promised_amount) || '';
  const hasExisting = r && r.promised_pay_date;
  owbOpenModal('Record promise to pay', `
    ${ctx}
    <label>Promised pay date</label>
    <input type="date" id="mDate" value="${escHtml(defaultDate)}">
    <label>Promised amount (optional — partial promise)</label>
    <input type="number" id="mAmt" placeholder="full balance if blank" min="0" step="0.01" value="${escHtml(defaultAmt)}">
    <label>Note (optional)</label>
    <textarea id="mNote" placeholder="any context"></textarea>
    ${hasExisting ? '<button type="button" id="mClearPromise" style="margin-top:14px;background:#fff;border:1px solid #fecaca;color:#dc2626;padding:6px 10px;border-radius:6px;font-size:12px;cursor:pointer;">Clear existing promise</button>' : ''}
  `, async () => {
    const date   = document.getElementById('mDate').value;
    const amount = parseFloat(document.getElementById('mAmt').value) || null;
    const note   = document.getElementById('mNote').value.trim();
    if (!date) { alert('Pick a date'); return; }
    try {
      await owbFetchJson(owbApiUrl('owb_promise'), { method:'POST',
        body: { invoice_number: invNum, promised_pay_date: date, promised_amount: amount, note } });
      owbCloseModal(); owbLoadList();
    } catch (e) { alert('Failed: ' + e.message); }
  });
  if (hasExisting) {
    setTimeout(() => {
      const btn = document.getElementById('mClearPromise');
      if (btn) btn.onclick = () => owbClearPromise(invNum);
    }, 0);
  }
}

async function owbClearPromise(invNum) {
  if (!confirm('Clear the promise on ' + invNum + '?')) return;
  try {
    await owbFetchJson(owbApiUrl('owb_clear_promise'), { method:'POST',
      body: { invoice_number: invNum, note: 'Promise cleared from workbench' } });
    owbCloseModal(); owbLoadList();
  } catch (e) { alert('Failed: ' + e.message); }
}

function owbOpenAssign(invNum) {
  const ctx = owbCtxRow(invNum);
  const r = OWB.rows.find(x => x.invoice_number === invNum);
  const opts = ASSIGNEES.map(a =>
    `<option value="${escHtml(a)}" ${r && r.assigned_to===a?'selected':''}>${escHtml(a)}</option>`).join('');
  owbOpenModal('Assign follow-up', `
    ${ctx}
    <label>Assign to</label>
    <select id="mAssign"><option value="">— Unassign —</option>${opts}</select>
    <label>Note (optional)</label>
    <textarea id="mNote" placeholder="brief context"></textarea>
  `, async () => {
    const assigned_to = document.getElementById('mAssign').value;
    const note = document.getElementById('mNote').value.trim();
    try {
      await owbFetchJson(owbApiUrl('owb_assign'), { method:'POST',
        body: { invoice_number: invNum, assigned_to: assigned_to || null, note } });
      owbCloseModal(); owbLoadList();
    } catch (e) { alert('Failed: ' + e.message); }
  });
}

function owbOpenStatus(invNum) {
  const ctx = owbCtxRow(invNum);
  const r = OWB.rows.find(x => x.invoice_number === invNum);
  const cur = r ? r.status : 'open';
  owbOpenModal('Change status', `
    ${ctx}
    <label>New status</label>
    <select id="mStatus" onchange="document.getElementById('pauseRow').style.display=this.value==='paused_followup'?'block':'none';">
      <option value="open" ${cur==='open'?'selected':''}>Open</option>
      <option value="in_field" ${cur==='in_field'?'selected':''}>In field</option>
      <option value="disputed" ${cur==='disputed'?'selected':''}>Disputed</option>
      <option value="unreachable" ${cur==='unreachable'?'selected':''}>Unreachable</option>
      <option value="write_off_req" ${cur==='write_off_req'?'selected':''}>Write-off requested</option>
      <option value="paused_followup" ${cur==='paused_followup'?'selected':''}>Paused follow-up</option>
    </select>
    <div id="pauseRow" style="display:${cur==='paused_followup'?'block':'none'};">
      <label>Resume on</label>
      <input type="date" id="mPause" value="${escHtml((r && r.pause_until) || '')}">
    </div>
    <label>Note (optional)</label>
    <textarea id="mNote" placeholder="why this change?"></textarea>
  `, async () => {
    const status = document.getElementById('mStatus').value;
    const note = document.getElementById('mNote').value.trim();
    const pause_until = status === 'paused_followup' ? document.getElementById('mPause').value : null;
    try {
      await owbFetchJson(owbApiUrl('owb_status'), { method:'POST',
        body: { invoice_number: invNum, status, note, pause_until } });
      owbCloseModal(); owbLoadList();
    } catch (e) { alert('Failed: ' + e.message); }
  });
}

async function owbOpenHistory(invNum) {
  const ctx = owbCtxRow(invNum);
  owbOpenModal('History — ' + invNum, ctx + '<div class="owb-loading">Loading…</div>', null, 'Close');
  document.getElementById('owbModalOk').style.display = '';
  document.getElementById('owbModalOk').textContent = 'Close';
  document.getElementById('owbModalOk').onclick = owbCloseModal;
  try {
    const j = await owbFetchJson(owbApiUrl('owb_detail', { invoice_number: invNum }));
    const d = j.data || j;
    const wb = (d.audit_log || []).map(r => `
      <div class="owb-history-row human">
        <div class="meta">${escHtml(r.at_iso)} · ${escHtml(r.by_retailer || 'system')} · ${escHtml(r.action)}</div>
        <div class="body">${escHtml(r.detail || '')}${r.old_value || r.new_value ? '<div style="font-size:11px;color:#9ca3af;margin-top:2px;">'+escHtml(r.old_value || '—')+' → '+escHtml(r.new_value || '—')+'</div>':''}</div>
      </div>`).join('');
    const auto = (d.email_log || []).map(r => `
      <div class="owb-history-row auto">
        <div class="meta">${escHtml(r.sent_at)} · stage ${r.stage} (${escHtml(r.stage_label||'')}) · ${r.success?'sent ✓':'failed ⚠️'}</div>
        <div class="body">${escHtml(r.client_email || '')}${r.error?'<div style="color:#dc2626;font-size:11px;margin-top:2px;">'+escHtml(r.error)+'</div>':''}</div>
      </div>`).join('');
    document.getElementById('owbModalBody').innerHTML = ctx +
      '<h4 style="margin:14px 0 6px;font-size:13px;color:#374151;">Workbench actions</h4>' +
      (wb || '<div style="color:#9ca3af;font-size:13px;">No workbench actions yet.</div>') +
      '<h4 style="margin:18px 0 6px;font-size:13px;color:#374151;">Auto-dunning history</h4>' +
      (auto || '<div style="color:#9ca3af;font-size:13px;">Not yet touched by auto-dunning.</div>');
  } catch (e) {
    document.getElementById('owbModalBody').innerHTML = ctx + `<div style="color:#dc2626;">${escHtml(e.message)}</div>`;
  }
}

async function owbSmtpCheck() {
  const banner = document.getElementById('smtpBanner');
  if (!banner) return;
  banner.className = 'owb-banner gray';
  banner.innerHTML = '<span>📨</span><div class="grow">Running SMTP probe…</div>';
  try {
    const j = await owbFetchJson(owbApiUrl('owb_smtp_check'));
    const d = j.data || j;
    if (d.ok) {
      banner.className = 'owb-banner green';
      banner.innerHTML = `<span>✅</span><div class="grow"><b>SMTP healthy</b> — ${escHtml(d.message)}</div>`;
    } else {
      banner.className = 'owb-banner red';
      banner.innerHTML = `<span>❌</span><div class="grow"><b>SMTP failed (${escHtml(d.reason||'unknown')})</b> — ${escHtml(d.message||'')} <a href="?page=dashboard&tab=smtp_diagnostic">Open diagnostic</a></div>`;
    }
  } catch (e) {
    banner.className = 'owb-banner red';
    banner.innerHTML = `<span>❌</span><div class="grow">Probe failed: ${escHtml(e.message)}</div>`;
  }
}

function owbDownloadCsv() {
  const params = new URLSearchParams({ action: 'owb_export_csv', ...owbBuildParams() });
  window.location.href = '?page=api&' + params.toString();
  return false;
}

owbLoadList();
</script>
