<?php
// ─────────────────────────────────────────────────────────────────────────────
// Tab: app_logins   (admin only)
// Added in v4.21.19
//
// Purpose: visibility into customer-app usage. The staff side has Access Log
// (login_sessions.json + tabs/admin/access_log.php). The customer-facing app
// has been writing to app_audit_log since the OTP system was introduced, but
// nothing has surfaced that data — until now.
//
// Reads:
//   app_audit_log         (created lazily in includes/api/api_customer_app.php)
//   ucrm_clients_cache.json (for name enrichment)
//
// Read-only. No money tables touched. PHP 7.4 compatible.
// Mirrors the visual language of access_log.php (KPI cards, per-user summary,
// data table, status chips). Same scoped style — admin-only because customer
// PII is here.
// ─────────────────────────────────────────────────────────────────────────────

if (!$isAdmin) {
    echo '<div style="padding:14px;border-radius:10px;background:#FFEBEE;color:#c0392b;font-weight:600;">Admin access only.</div>';
    return;
}

$pdo = $store->getPdo();

// ── Self-heal: ensure schema + helpful indexes ───────────────────────────────
// app_audit_log is created lazily by api_customer_app.php on first OTP send,
// so on a fresh install (or if the customer app endpoints have never been
// hit) this tab would error with "no such table". CREATE IF NOT EXISTS guards
// against that. Indexes are a free perf win — no migration runner involved.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        crm_client_id INTEGER,
        action TEXT NOT NULL,
        phone TEXT,
        ip TEXT,
        details TEXT,
        at INTEGER NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_audit_at ON app_audit_log(at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_audit_action_at ON app_audit_log(action, at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_app_audit_client ON app_audit_log(crm_client_id, at DESC)");
} catch (\Throwable $e) {
    echo '<div style="padding:14px;border-radius:10px;background:#FFEBEE;color:#c0392b;">Schema error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    return;
}

// ── Time windows ─────────────────────────────────────────────────────────────
$now    = time();
$today0 = strtotime('today 00:00:00');
$d7     = $now - 7 * 86400;
$d30    = $now - 30 * 86400;

// ── Filters ──────────────────────────────────────────────────────────────────
$range  = $_GET['range'] ?? '7d';            // today | 7d | 30d | all
$mode   = $_GET['mode']  ?? '';              // '' | phone | email
$search = trim($_GET['q'] ?? '');            // free-text on phone/email/name

$rangeCutoff = match ($range) {
    'today' => $today0,
    '30d'   => $d30,
    'all'   => 0,
    default => $d7,
};

// ── KPIs (count distinct success vs fail, all-time numbers up top) ───────────
$k = ['logins_today' => 0, 'logins_7d' => 0, 'logins_30d' => 0, 'unique_30d' => 0,
      'failures_7d' => 0, 'phone_logins_7d' => 0, 'email_logins_7d' => 0];
try {
    $row = $pdo->query("
        SELECT
          SUM(CASE WHEN action='login_success' AND at >= {$today0} THEN 1 ELSE 0 END) AS logins_today,
          SUM(CASE WHEN action='login_success' AND at >= {$d7}     THEN 1 ELSE 0 END) AS logins_7d,
          SUM(CASE WHEN action='login_success' AND at >= {$d30}    THEN 1 ELSE 0 END) AS logins_30d,
          SUM(CASE WHEN action IN ('otp_no_account','otp_no_account_email','otp_wrong_code','otp_lockout','otp_verify_expired','otp_rate_limit','otp_send_failed') AND at >= {$d7} THEN 1 ELSE 0 END) AS failures_7d
        FROM app_audit_log
    ")->fetch(\PDO::FETCH_ASSOC);
    foreach ((array)$row as $kk => $vv) $k[$kk] = (int)$vv;

    // Unique customers in last 30 days
    $k['unique_30d'] = (int)$pdo->query("
        SELECT COUNT(DISTINCT crm_client_id)
        FROM app_audit_log
        WHERE action='login_success' AND crm_client_id IS NOT NULL AND at >= {$d30}
    ")->fetchColumn();

    // Channel split — pull details JSON for last 7d login_success rows.
    // login_mode wasn't in the audit details until v4.21.7; older rows fall
    // back to phone/email heuristic on the identifier itself.
    $stmt = $pdo->query("SELECT phone, details FROM app_audit_log WHERE action='login_success' AND at >= {$d7}");
    while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $det = $r['details'] ? json_decode($r['details'], true) : null;
        $m = is_array($det) ? ($det['login_mode'] ?? '') : '';
        if ($m === 'email') $k['email_logins_7d']++;
        elseif ($m === 'phone') $k['phone_logins_7d']++;
        else {
            // Fallback: emails contain @
            if (strpos((string)$r['phone'], '@') !== false) $k['email_logins_7d']++;
            else $k['phone_logins_7d']++;
        }
    }
} catch (\Throwable $e) {
    // Non-fatal — KPIs stay 0
}

// ── Per-customer "Who's been active" summary (within the chosen range) ──────
// One row per crm_client_id with: last_seen, login count, channel mix,
// last IP, last failure (if any). Then sort desc by last_seen.
$active = [];
try {
    $sql = "
        SELECT crm_client_id, action, phone, ip, details, at
        FROM app_audit_log
        WHERE at >= ?
          AND (action='login_success' OR action LIKE 'otp_%')
        ORDER BY at DESC
        LIMIT 5000
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$rangeCutoff]);
    while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
        $cid = (int)($r['crm_client_id'] ?? 0);
        if ($cid <= 0) {
            // Anonymous failures (e.g. otp_no_account) — bucket by identifier
            $cid = 'anon:' . ($r['phone'] ?? '');
        }
        if (!isset($active[$cid])) {
            $active[$cid] = [
                'crm_client_id' => is_int($cid) ? $cid : 0,
                'last_seen'     => 0,
                'last_ip'       => '',
                'last_phone'    => '',
                'login_count'   => 0,
                'fail_count'    => 0,
                'phone_logins'  => 0,
                'email_logins'  => 0,
                'last_action'   => '',
                'last_mode'     => '',
            ];
        }
        $a = &$active[$cid];
        if ((int)$r['at'] > $a['last_seen']) {
            $a['last_seen']  = (int)$r['at'];
            $a['last_ip']    = $r['ip']   ?? '';
            $a['last_phone'] = $r['phone'] ?? '';
            $a['last_action']= $r['action'];
        }
        if ($r['action'] === 'login_success') {
            $a['login_count']++;
            $det = $r['details'] ? json_decode($r['details'], true) : null;
            $m = is_array($det) ? ($det['login_mode'] ?? '') : '';
            if ($m === '' && strpos((string)$r['phone'], '@') !== false) $m = 'email';
            elseif ($m === '') $m = 'phone';
            $a['last_mode'] = $m;
            if ($m === 'email') $a['email_logins']++;
            else $a['phone_logins']++;
        } else {
            $a['fail_count']++;
        }
        unset($a);
    }
} catch (\Throwable $e) {}

// Drop entries that have ZERO logins in the window AND aren't admin-interesting
// (e.g. only 'otp_send_failed' with no client). Keep one row per anon failure
// bucket so admin can spot abuse.
usort($active, function($a, $b) {
    return $b['last_seen'] <=> $a['last_seen'];
});

// Apply mode filter
if ($mode === 'email') $active = array_values(array_filter($active, fn($a) => $a['email_logins'] > 0));
elseif ($mode === 'phone') $active = array_values(array_filter($active, fn($a) => $a['phone_logins'] > 0));

// ── Enrich with UCRM client cache ────────────────────────────────────────────
$clientCache = $store->load('ucrm_clients_cache.json') ?? [];
$clientById  = [];
foreach ($clientCache as $c) {
    $cid = (int)($c['id'] ?? 0);
    if ($cid > 0) $clientById[$cid] = $c;
}
$resolveName = function($cid) use ($clientById) {
    if (!is_int($cid) || $cid <= 0) return null;
    $c = $clientById[$cid] ?? null;
    if (!$c) return null;
    return trim((string)($c['companyName'] ?? '') ?: trim(((string)($c['firstName'] ?? '')) . ' ' . ((string)($c['lastName'] ?? ''))));
};

// Apply free-text search filter (after enrichment so we can search names)
if ($search !== '') {
    $needle = mb_strtolower($search);
    $active = array_values(array_filter($active, function($a) use ($needle, $resolveName) {
        $name = $resolveName($a['crm_client_id']) ?? '';
        $hay  = mb_strtolower($name . ' ' . ($a['last_phone'] ?? '') . ' ' . ($a['last_ip'] ?? ''));
        return strpos($hay, $needle) !== false;
    }));
}

// Limit to 200 active rows for render perf
$activeTotal  = count($active);
$activeRender = array_slice($active, 0, 200);

// ── Recent failures table ────────────────────────────────────────────────────
$failures = [];
try {
    $st = $pdo->prepare("
        SELECT crm_client_id, action, phone, ip, details, at
        FROM app_audit_log
        WHERE at >= ?
          AND action IN ('otp_no_account','otp_no_account_email','otp_wrong_code','otp_lockout','otp_verify_expired','otp_rate_limit','otp_send_failed')
        ORDER BY at DESC
        LIMIT 50
    ");
    $st->execute([$rangeCutoff]);
    $failures = $st->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// ── Helpers ──────────────────────────────────────────────────────────────────
$timeAgo = function(int $ts): string {
    if ($ts <= 0) return '—';
    $d = time() - $ts;
    if ($d < 60)        return $d . 's ago';
    if ($d < 3600)      return floor($d / 60) . 'm ago';
    if ($d < 86400)     return floor($d / 3600) . 'h ago';
    if ($d < 86400 * 7) return floor($d / 86400) . 'd ago';
    return gmdate('M j', $ts);
};

$failureLabel = [
    'otp_no_account'        => 'Phone not registered',
    'otp_no_account_email'  => 'Email not registered',
    'otp_wrong_code'        => 'Wrong OTP code',
    'otp_lockout'           => 'Too many wrong tries',
    'otp_verify_expired'    => 'OTP expired',
    'otp_rate_limit'        => 'Rate limited',
    'otp_send_failed'       => 'OTP send failed',
];
?>

<!-- ─── Page header ─── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:800;color:#1e293b;">
      <i class="bi bi-phone-fill" style="color:#D41C1C;margin-right:6px;"></i>
      Customer App Logins
    </h2>
    <div style="font-size:11px;color:#64748b;margin-top:2px;">
      Who is using the DishNet customer app — same idea as the staff Access Log.
    </div>
  </div>
  <div style="font-size:11px;color:#64748b;">
    <a href="?page=dashboard&tab=app_logins" style="text-decoration:none;color:#D41C1C;font-weight:600;">
      <i class="bi bi-arrow-clockwise"></i> Refresh
    </a>
  </div>
</div>

<!-- ─── KPI row (all-time numbers up top — never affected by filters) ─── -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:16px;">
  <div style="background:#E8F5E9;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#2E7D32;"><?= $k['logins_today'] ?></div>
    <div style="font-size:10px;font-weight:700;color:#2E7D32;text-transform:uppercase;letter-spacing:.5px;">Logins today</div>
  </div>
  <div style="background:#E3F2FD;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#1565C0;"><?= $k['logins_7d'] ?></div>
    <div style="font-size:10px;font-weight:700;color:#1565C0;text-transform:uppercase;letter-spacing:.5px;">Last 7d</div>
  </div>
  <div style="background:#F3E5F5;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#6A1B9A;"><?= $k['logins_30d'] ?></div>
    <div style="font-size:10px;font-weight:700;color:#6A1B9A;text-transform:uppercase;letter-spacing:.5px;">Last 30d</div>
  </div>
  <div style="background:#FFF3E0;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#E65100;"><?= $k['unique_30d'] ?></div>
    <div style="font-size:10px;font-weight:700;color:#E65100;text-transform:uppercase;letter-spacing:.5px;">Unique users 30d</div>
  </div>
  <div style="background:#FFEBEE;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#c0392b;"><?= $k['failures_7d'] ?></div>
    <div style="font-size:10px;font-weight:700;color:#c0392b;text-transform:uppercase;letter-spacing:.5px;">Failures 7d</div>
  </div>
  <div style="background:#F1F5F9;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:14px;font-weight:900;color:#475569;line-height:1.2;">
      <?= $k['phone_logins_7d'] ?> <span style="font-size:10px;color:#94a3b8;font-weight:600;">📞</span>
      &nbsp;·&nbsp;
      <?= $k['email_logins_7d'] ?> <span style="font-size:10px;color:#94a3b8;font-weight:600;">✉️</span>
    </div>
    <div style="font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;margin-top:6px;">Channel mix 7d</div>
  </div>
</div>

<!-- ─── Filter chips ─── -->
<div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap;">
  <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Range:</span>
  <?php foreach (['today'=>'Today','7d'=>'7 days','30d'=>'30 days','all'=>'All time'] as $r => $lab): ?>
    <a href="?page=dashboard&tab=app_logins&range=<?= $r ?><?= $mode ? '&mode='.urlencode($mode) : '' ?><?= $search !== '' ? '&q='.urlencode($search) : '' ?>"
       style="padding:4px 11px;border-radius:14px;font-size:11px;font-weight:700;text-decoration:none;<?= $range === $r ? 'background:#1e293b;color:#fff;' : 'background:#F1F5F9;color:#475569;' ?>">
      <?= htmlspecialchars($lab) ?>
    </a>
  <?php endforeach; ?>

  <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-left:12px;">Channel:</span>
  <?php foreach (['' => 'Both', 'phone' => '📞 Phone', 'email' => '✉️ Email'] as $m => $lab): ?>
    <a href="?page=dashboard&tab=app_logins&range=<?= urlencode($range) ?><?= $m ? '&mode='.$m : '' ?><?= $search !== '' ? '&q='.urlencode($search) : '' ?>"
       style="padding:4px 11px;border-radius:14px;font-size:11px;font-weight:700;text-decoration:none;<?= $mode === $m ? 'background:#D41C1C;color:#fff;' : 'background:#F1F5F9;color:#475569;' ?>">
      <?= htmlspecialchars($lab) ?>
    </a>
  <?php endforeach; ?>

  <form method="get" style="display:inline-flex;gap:6px;margin-left:auto;">
    <input type="hidden" name="page" value="dashboard">
    <input type="hidden" name="tab" value="app_logins">
    <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
    <?php if ($mode): ?><input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name / phone / email / IP"
           style="padding:5px 10px;border-radius:7px;border:1px solid #cbd5e1;font-size:12px;width:240px;">
    <button type="submit" style="padding:5px 12px;border-radius:7px;background:#1e293b;color:#fff;border:0;font-size:11px;font-weight:700;cursor:pointer;">
      Search
    </button>
  </form>
</div>

<!-- ─── Active customers table ─── -->
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:16px;margin-bottom:16px;">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
    <div style="font-size:13px;font-weight:800;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;">
      <i class="bi bi-people-fill" style="color:#D41C1C;margin-right:4px;"></i>
      Who's Using The App
      <span style="font-weight:600;color:#94a3b8;font-size:11px;">
        (<?= $activeTotal ?> in <?= $range === 'all' ? 'all time' : 'last ' . htmlspecialchars($range) ?><?= $activeTotal > 200 ? ', showing top 200' : '' ?>)
      </span>
    </div>
  </div>

  <?php if (empty($activeRender)): ?>
    <div style="padding:32px;text-align:center;color:#64748b;font-size:13px;">
      <i class="bi bi-inbox" style="font-size:28px;color:#cbd5e1;display:block;margin-bottom:6px;"></i>
      No customer activity in this window.
    </div>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead>
        <tr style="background:#F8FAFC;">
          <th style="text-align:left;padding:8px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Customer</th>
          <th style="text-align:left;padding:8px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Identifier</th>
          <th style="text-align:center;padding:8px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Logins</th>
          <th style="text-align:center;padding:8px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Channels</th>
          <th style="text-align:center;padding:8px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Failures</th>
          <th style="text-align:left;padding:8px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Last Seen</th>
          <th style="text-align:left;padding:8px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Last IP</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($activeRender as $a):
        $cid       = (int)$a['crm_client_id'];
        $name      = $cid > 0 ? ($resolveName($cid) ?: '') : '';
        $isAnon    = $cid <= 0;
      ?>
        <tr style="border-top:1px solid #f1f5f9;">
          <td style="padding:9px 10px;vertical-align:top;">
            <?php if ($isAnon): ?>
              <span style="color:#94a3b8;font-style:italic;">(unknown — failed lookups)</span>
            <?php elseif ($name === ''): ?>
              <span style="color:#94a3b8;">#<?= $cid ?></span>
            <?php else: ?>
              <span style="font-weight:700;color:#1e293b;"><?= htmlspecialchars($name) ?></span>
              <span style="color:#94a3b8;font-weight:500;">#<?= $cid ?></span>
            <?php endif; ?>
          </td>
          <td style="padding:9px 10px;vertical-align:top;font-family:'JetBrains Mono','Courier New',monospace;font-size:11px;color:#475569;">
            <?= htmlspecialchars((string)($a['last_phone'] ?? '')) ?>
          </td>
          <td style="padding:9px 10px;vertical-align:top;text-align:center;font-weight:700;color:#1e293b;">
            <?= $a['login_count'] ?>
          </td>
          <td style="padding:9px 10px;vertical-align:top;text-align:center;font-size:11px;color:#475569;">
            <?php if ($a['phone_logins'] > 0): ?><span title="Phone logins">📞 <?= $a['phone_logins'] ?></span><?php endif; ?>
            <?php if ($a['phone_logins'] > 0 && $a['email_logins'] > 0): ?>&nbsp;·&nbsp;<?php endif; ?>
            <?php if ($a['email_logins'] > 0): ?><span title="Email logins">✉️ <?= $a['email_logins'] ?></span><?php endif; ?>
            <?php if ($a['phone_logins'] === 0 && $a['email_logins'] === 0): ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
          </td>
          <td style="padding:9px 10px;vertical-align:top;text-align:center;">
            <?php if ($a['fail_count'] > 0): ?>
              <span style="display:inline-block;padding:2px 7px;border-radius:9px;background:#FFEBEE;color:#c0392b;font-weight:700;font-size:11px;"><?= $a['fail_count'] ?></span>
            <?php else: ?>
              <span style="color:#cbd5e1;">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:9px 10px;vertical-align:top;font-size:11px;color:#475569;">
            <div><?= htmlspecialchars($timeAgo((int)$a['last_seen'])) ?></div>
            <div style="font-size:10px;color:#94a3b8;"><?= htmlspecialchars(gmdate('Y-m-d H:i', (int)$a['last_seen'])) ?></div>
          </td>
          <td style="padding:9px 10px;vertical-align:top;font-family:'JetBrains Mono','Courier New',monospace;font-size:10px;color:#94a3b8;">
            <?= htmlspecialchars((string)($a['last_ip'] ?? '')) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ─── Recent failures (compact, surface-level) ─── -->
<?php if (!empty($failures)): ?>
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:16px;margin-bottom:16px;">
  <div style="font-size:13px;font-weight:800;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
    <i class="bi bi-exclamation-triangle-fill" style="color:#c0392b;margin-right:4px;"></i>
    Recent Failures
    <span style="font-weight:600;color:#94a3b8;font-size:11px;">(last 50, in <?= htmlspecialchars($range) ?>)</span>
  </div>
  <table style="width:100%;border-collapse:collapse;font-size:12px;">
    <thead>
      <tr style="background:#F8FAFC;">
        <th style="text-align:left;padding:7px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">When</th>
        <th style="text-align:left;padding:7px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Reason</th>
        <th style="text-align:left;padding:7px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Identifier</th>
        <th style="text-align:left;padding:7px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Customer (if known)</th>
        <th style="text-align:left;padding:7px 10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">IP</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($failures as $f):
      $cid  = (int)($f['crm_client_id'] ?? 0);
      $name = $cid > 0 ? ($resolveName($cid) ?: '') : '';
    ?>
      <tr style="border-top:1px solid #f1f5f9;">
        <td style="padding:6px 10px;font-size:11px;color:#64748b;font-family:'JetBrains Mono','Courier New',monospace;white-space:nowrap;">
          <?= htmlspecialchars(gmdate('M j H:i', (int)$f['at'])) ?>
        </td>
        <td style="padding:6px 10px;">
          <span style="display:inline-block;padding:2px 8px;border-radius:9px;background:#FFEBEE;color:#c0392b;font-size:10px;font-weight:700;">
            <?= htmlspecialchars($failureLabel[$f['action']] ?? $f['action']) ?>
          </span>
        </td>
        <td style="padding:6px 10px;font-family:'JetBrains Mono','Courier New',monospace;font-size:11px;color:#475569;">
          <?= htmlspecialchars((string)($f['phone'] ?? '')) ?>
        </td>
        <td style="padding:6px 10px;font-size:11px;">
          <?php if ($name): ?>
            <span style="font-weight:600;color:#1e293b;"><?= htmlspecialchars($name) ?></span>
            <span style="color:#94a3b8;">#<?= $cid ?></span>
          <?php elseif ($cid > 0): ?>
            <span style="color:#94a3b8;">#<?= $cid ?></span>
          <?php else: ?>
            <span style="color:#cbd5e1;">—</span>
          <?php endif; ?>
        </td>
        <td style="padding:6px 10px;font-family:'JetBrains Mono','Courier New',monospace;font-size:10px;color:#94a3b8;">
          <?= htmlspecialchars((string)($f['ip'] ?? '')) ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ─── Footnote ─── -->
<div style="margin-top:8px;font-size:10px;color:#94a3b8;line-height:1.5;">
  <i class="bi bi-info-circle"></i>
  Data source: <code>app_audit_log</code> (written by <code>includes/api/api_customer_app.php</code>).
  Tracks OTP send, OTP verify (success/failure), and login outcomes for the customer-facing
  app (web portal at <code>?page=customer_login</code> + Android app — both share the same backend).
  Customer name resolved via <code>ucrm_clients_cache.json</code>; rows where the OTP failed
  before account lookup show as "(unknown — failed lookups)".
</div>
