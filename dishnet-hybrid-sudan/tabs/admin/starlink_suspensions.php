<?php
// ─────────────────────────────────────────────────────────────────────────────
// Tab: starlink_suspensions   (admin only)
// Added in v4.21.16
//
// Purpose: visibility into the Starlink auto-block subsystem (v4.21.0+).
// Answers two operational questions Bhavin keeps asking:
//   1. Is auto-suspend actually working when UCRM fires service.suspend?
//   2. Which customers are currently in the suspended/restoring/error state,
//      and what was the last gRPC call we made for each?
//
// Read-only by default. Admin can also force a manual restore on a row
// (e.g. customer paid via cash, webhook didn't fire, devices still blocked).
//
// Tables read:
//   sl_suspension_state  (one row per (client_id, router_id) currently
//                         in suspending/suspended/restoring/error state)
//   sl_suspension_log    (append-only audit trail of every gRPC call)
//
// SAFETY:
//   - Money/customer tables NEVER touched here.
//   - Manual restore goes through StarlinkBlockService::restore() — same
//     code path as service.unsuspend webhook + payment.add. Idempotent.
//   - CSRF check on the force-restore POST.
//   - Admin-only (no accountant / support_leader access — gRPC calls go
//     to Starlink cloud and shouldn't be triggered casually).
// ─────────────────────────────────────────────────────────────────────────────

if (!$isAdmin) {
    echo '<div class="kyc-alert danger" style="padding:14px;border-radius:10px;background:#FFEBEE;color:#c0392b;font-weight:600;">Admin access only.</div>';
    return;
}

// StarlinkBlockService isn't autoloaded — webhook.php require_once's it directly,
// so we do the same here. class_exists guard keeps repeat-includes safe.
if (!class_exists('StarlinkBlockService')) {
    @require_once __DIR__ . '/../../lib/StarlinkBlockService.php';
}

$pdo = $store->getPdo();

// ── Auto-create tables if missing (v4.21.17+) ────────────────────────────────
// Migration 057 normally creates these on plugin boot, but if it was marked
// applied while the DB was in a bad state, the runner won't retry. Same
// self-healing pattern as tabs/admin/duplicate_log.php uses for migration 054.
// IF NOT EXISTS — safe to run on every render. Schema mirrors migration 057
// (and 058+059 ALTERs which add block_mode + bypass tracking columns).
$tablesCreated = false;
try {
    $exists = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sl_suspension_state'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sl_suspension_state (
            id                          INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id                   INTEGER NOT NULL,
            crm_service_id              INTEGER NOT NULL DEFAULT 0,
            kit_serial                  TEXT    NOT NULL DEFAULT '',
            router_id                   TEXT    NOT NULL,
            account_number              TEXT    NOT NULL DEFAULT '',
            original_ssid_24            TEXT    NOT NULL DEFAULT '',
            original_ssid_5             TEXT    NOT NULL DEFAULT '',
            original_pass_24            TEXT    NOT NULL DEFAULT '',
            original_pass_5             TEXT    NOT NULL DEFAULT '',
            original_auth_type          TEXT    NOT NULL DEFAULT 'wpa2',
            suspension_ssid             TEXT    NOT NULL DEFAULT 'DishNet-PAY-NOW',
            suspension_pass             TEXT    NOT NULL DEFAULT '',
            paused_macs_json            TEXT    NOT NULL DEFAULT '[]',
            pre_existing_paused_json    TEXT    NOT NULL DEFAULT '[]',
            state                       TEXT    NOT NULL DEFAULT 'suspending',
            is_bypass_mode              INTEGER NOT NULL DEFAULT 0,
            block_mode                  TEXT    NOT NULL DEFAULT 'full',
            bypass_event_count          INTEGER NOT NULL DEFAULT 0,
            last_bypass_at              TEXT    NULL,
            bypass_alerted_at           TEXT    NULL,
            suspended_at                TEXT    NOT NULL DEFAULT (datetime('now')),
            suspended_by                TEXT    NOT NULL DEFAULT 'webhook',
            triggered_by_event          TEXT    NOT NULL DEFAULT '',
            last_attempt_at             TEXT    NOT NULL DEFAULT (datetime('now')),
            attempt_count               INTEGER NOT NULL DEFAULT 1,
            last_error                  TEXT    NOT NULL DEFAULT '',
            restore_started_at          TEXT,
            restore_triggered_by        TEXT,
            UNIQUE(client_id, router_id)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sl_susp_state_state    ON sl_suspension_state(state, last_attempt_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sl_susp_state_client   ON sl_suspension_state(client_id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sl_suspension_log (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            ts              TEXT    NOT NULL DEFAULT (datetime('now')),
            client_id       INTEGER NOT NULL,
            router_id       TEXT    NOT NULL DEFAULT '',
            action          TEXT    NOT NULL,
            success         INTEGER NOT NULL DEFAULT 0,
            grpc_status     INTEGER,
            grpc_message    TEXT    NOT NULL DEFAULT '',
            detail          TEXT    NOT NULL DEFAULT '',
            attempt_number  INTEGER NOT NULL DEFAULT 1
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sl_susp_log_client_ts  ON sl_suspension_log(client_id, ts DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sl_susp_log_router_ts  ON sl_suspension_log(router_id, ts DESC)");
        $tablesCreated = true;
    }
    // Defensive ALTERs in case 057 ran but 058/059 didn't add the new columns.
    // 'duplicate column' error is caught and ignored (idempotent).
    foreach ([
        "ALTER TABLE sl_suspension_state ADD COLUMN block_mode TEXT NOT NULL DEFAULT 'full'",
        "ALTER TABLE sl_suspension_state ADD COLUMN bypass_event_count INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE sl_suspension_state ADD COLUMN last_bypass_at TEXT NULL",
        "ALTER TABLE sl_suspension_state ADD COLUMN bypass_alerted_at TEXT NULL",
    ] as $alter) {
        try { $pdo->exec($alter); } catch (\Throwable $_) { /* duplicate column, fine */ }
    }
} catch (\Throwable $e) {
    echo '<div style="padding:18px;background:#FFEBEE;border:1px solid #EF9A9A;border-radius:10px;color:#c0392b;">';
    echo '<strong>Failed to ensure sl_suspension_state schema.</strong><br>';
    echo htmlspecialchars($e->getMessage()) . '<br><br>';
    echo 'If this persists, the database may be partly corrupt. Try ';
    echo '<a href="public.php?page=emergency_repair&key=DISHNET_REPAIR" style="color:#D41C1C;font-weight:600;">Emergency Repair</a>.';
    echo '</div>';
    return;
}

// One-time banner if we just created the tables — explains the empty state
if ($tablesCreated) {
    echo '<div style="padding:11px 14px;border-radius:9px;border:1px solid #A5D6A7;background:#E8F5E9;color:#2E7D32;font-size:12px;font-weight:600;margin-bottom:14px;">';
    echo '<i class="bi bi-check-circle-fill"></i> ';
    echo 'Created sl_suspension_state and sl_suspension_log tables (migration 057 had not been applied). ';
    echo 'They will populate as customers are auto-suspended/restored going forward.';
    echo '</div>';
}

// ── State constants (mirror lib/StarlinkBlockService.php) ────────────────────
$STATE_LABELS = [
    'suspending'             => ['label' => 'Suspending',          'color' => '#FF9800', 'bg' => '#FFF3E0', 'icon' => 'bi-hourglass-split'],
    'suspended'              => ['label' => 'Suspended',           'color' => '#c0392b', 'bg' => '#FFEBEE', 'icon' => 'bi-slash-circle-fill'],
    'partial_suspend_failed' => ['label' => 'Partial / Failed',    'color' => '#E65100', 'bg' => '#FFF3E0', 'icon' => 'bi-exclamation-triangle-fill'],
    'restoring'              => ['label' => 'Restoring',           'color' => '#1565C0', 'bg' => '#E3F2FD', 'icon' => 'bi-arrow-clockwise'],
    'error_manual_required'  => ['label' => 'Manual Action Needed','color' => '#6A1B9A', 'bg' => '#F3E5F5', 'icon' => 'bi-tools'],
];

// Quick state pill renderer
$pill = function(string $state) use ($STATE_LABELS): string {
    $info = $STATE_LABELS[$state] ?? ['label' => $state, 'color' => '#475569', 'bg' => '#F1F5F9', 'icon' => 'bi-question-circle'];
    return '<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:11px;background:'
        . $info['bg'] . ';color:' . $info['color']
        . ';font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;">'
        . '<i class="bi ' . $info['icon'] . '"></i>'
        . htmlspecialchars($info['label']) . '</span>';
};

// ── Action handler: force manual restore ─────────────────────────────────────
// POST + CSRF + admin (already checked above). Calls StarlinkBlockService::restore()
// with triggeredBy='manual:<retailer_name>' so the audit log shows who did it.
$flashMsg  = '';
$flashKind = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sl_force_restore') {
    if (!csrfCheck()) {
        $flashMsg  = 'Security token mismatch — please reload the page and try again.';
        $flashKind = 'danger';
    } else {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId <= 0) {
            $flashMsg  = 'Invalid client id.';
            $flashKind = 'danger';
        } else {
            try {
                $cfg = $store->load('plugin_config.json') ?? [];
                $localConfig = is_array($config ?? null) ? $config : (is_array($cfg) ? $cfg : []);
                $blockSvc = new \StarlinkBlockService($pdo, $store, $localConfig, $dataDir, $notify);
                $triggeredBy = 'manual:' . ($retailer['name'] ?? 'admin');
                $result = $blockSvc->restore($clientId, $triggeredBy);
                $okCount    = (int)($result['routers_restored'] ?? 0);
                $failCount  = (int)($result['routers_failed'] ?? 0);
                $defCount   = (int)($result['routers_deferred'] ?? 0);
                if (!empty($result['ok']) && $okCount > 0) {
                    $flashMsg  = "Manual restore OK: {$okCount} router(s) restored"
                        . ($defCount > 0 ? ", {$defCount} deferred to cron" : '') . '.';
                    $flashKind = 'success';
                } elseif (!empty($result['ok']) && $okCount === 0) {
                    $flashMsg  = 'No active suspension state for this client — nothing to restore.';
                    $flashKind = 'info';
                } else {
                    $flashMsg  = "Restore had issues: {$okCount} ok, {$failCount} failed"
                        . ($defCount > 0 ? ", {$defCount} deferred" : '')
                        . '. Check the audit log below for details.';
                    $flashKind = 'warning';
                }
                logActivity($dataDir, 'sl_force_restore', 'Starlink manual restore',
                    "client_id={$clientId} ok={$okCount} failed={$failCount} by=" . ($retailer['name'] ?? 'admin'));
            } catch (\Throwable $e) {
                $flashMsg  = 'Force-restore threw: ' . htmlspecialchars($e->getMessage());
                $flashKind = 'danger';
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// JSON ENDPOINTS for the bulk-block Chrome console script (v4.21.21+)
// Both are admin-only (gated above), CSRF-protected for the suspend action,
// and respond as JSON. Designed to be driven by a small JS snippet pasted
// into the Chrome console — see the snippet in tabs/admin/starlink_suspensions.php
// "Bulk-block tools" section below.
// ─────────────────────────────────────────────────────────────────────────────

// ── ENDPOINT 1: audit (GET) ──────────────────────────────────────────────────
// Returns the list of suspended Starlink customers and flags VIP / already-blocked.
// Read-only — safe to call repeatedly.
if (($_GET['sl_action'] ?? '') === 'audit_suspended_starlink') {
    header('Content-Type: application/json; charset=UTF-8');

    // Load the data we need
    $clients  = $store->load('ucrm_clients_cache.json') ?? [];
    $services = $store->load('ucrm_services_cache.json') ?? [];

    // Read sl_kits.json from sibling Starlink Finance plugin
    // (same paths StarlinkBlockService::loadKitsForClient uses)
    $kitsByClient = [];
    foreach ([
        dirname(__DIR__, 3) . '/dishnet-starlink-finance/data/sl_kits.json',
        dirname(__DIR__, 4) . '/dishnet-starlink-finance/data/sl_kits.json',
    ] as $path) {
        if (file_exists($path)) {
            $raw = @json_decode(file_get_contents($path), true);
            if (is_array($raw)) {
                foreach ($raw as $row) {
                    $cid = (int)($row['client_id'] ?? $row['crm_client_id'] ?? $row['ucrm_client_id'] ?? 0);
                    if ($cid > 0) {
                        if (!isset($kitsByClient[$cid])) $kitsByClient[$cid] = [];
                        $kitsByClient[$cid][] = (string)($row['kit_serial'] ?? $row['serial'] ?? '');
                    }
                }
                break;
            }
        }
    }

    // VIP detection (mirrors StarlinkBlockService::isVipClient)
    $vipTagId   = (int)($config['starlink_block_vip_tag_id']   ?? 84);
    $vipTagName = (string)($config['starlink_block_vip_tag_name'] ?? 'NO_AUTO_BLOCK');
    $vipExplicit = $config['starlink_block_vip_clients'] ?? '';
    if (is_string($vipExplicit)) {
        $vipExplicit = array_filter(array_map('intval', array_map('trim', explode(',', $vipExplicit))));
    } elseif (!is_array($vipExplicit)) {
        $vipExplicit = [];
    }
    $vipExplicit = array_flip(array_map('intval', $vipExplicit));

    $isVip = function(array $client) use ($vipTagId, $vipTagName, $vipExplicit): bool {
        $cid = (int)($client['id'] ?? 0);
        if (isset($vipExplicit[$cid])) return true;
        $tags = $client['attributes'] ?? $client['clientTags'] ?? $client['tags'] ?? [];
        if (!is_array($tags)) return false;
        foreach ($tags as $t) {
            $tid = (int)($t['id'] ?? $t['tagId'] ?? 0);
            if ($tid > 0 && $tid === $vipTagId) return true;
            $tname = (string)($t['name'] ?? $t['tagName'] ?? '');
            if ($tname !== '' && strcasecmp($tname, $vipTagName) === 0) return true;
        }
        return false;
    };

    // Index existing sl_suspension_state rows (clients we've already blocked)
    $alreadyBlocked = [];
    try {
        $stmt = $pdo->query("SELECT DISTINCT client_id FROM sl_suspension_state WHERE state IN ('suspending','suspended','partial_suspend_failed','restoring','error_manual_required')");
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $cid) $alreadyBlocked[(int)$cid] = true;
    } catch (\Throwable $e) {}

    // Index services by client_id
    $servicesByClient = [];
    foreach ($services as $svc) {
        $cid = (int)($svc['clientId'] ?? 0);
        if ($cid > 0) {
            if (!isset($servicesByClient[$cid])) $servicesByClient[$cid] = [];
            $servicesByClient[$cid][] = $svc;
        }
    }

    // Build candidate list — Starlink customers (have a KIT) whose service is
    // suspended (status != 1) and who are NOT VIP.
    $candidates = [];
    $vipSkipped = 0;
    $alreadyDoneSkipped = 0;
    $totalSuspendedStarlink = 0;

    foreach ($clients as $client) {
        $cid = (int)($client['id'] ?? 0);
        if ($cid <= 0) continue;

        // Must have a Starlink KIT
        $kits = $kitsByClient[$cid] ?? [];
        if (empty($kits)) continue;

        // Must have at least one suspended service (status != 1)
        $svcs = $servicesByClient[$cid] ?? [];
        $hasSuspended = false;
        $primarySvc = null;
        foreach ($svcs as $svc) {
            $st = (int)($svc['status'] ?? 0);
            if ($st !== 1) {
                $hasSuspended = true;
                $primarySvc = $primarySvc ?: $svc;
            }
        }
        if (!$hasSuspended) continue;

        $totalSuspendedStarlink++;

        $name = trim((string)($client['companyName'] ?? '') ?: trim((string)($client['firstName'] ?? '') . ' ' . (string)($client['lastName'] ?? '')));
        $vip  = $isVip($client);
        $blocked = !empty($alreadyBlocked[$cid]);

        if ($vip)     { $vipSkipped++; continue; }
        if ($blocked) { $alreadyDoneSkipped++; continue; }

        $candidates[] = [
            'client_id'   => $cid,
            'name'        => $name !== '' ? $name : ('#' . $cid),
            'kit_serials' => array_values(array_unique(array_filter($kits))),
            'service_id'  => (int)($primarySvc['id'] ?? 0),
            'service_name'=> (string)($primarySvc['name'] ?? ''),
            'balance'     => (float)($client['accountBalance'] ?? 0),
        ];
    }

    echo json_encode([
        'ok' => true,
        'summary' => [
            'total_suspended_starlink_clients' => $totalSuspendedStarlink,
            'vip_skipped'                      => $vipSkipped,
            'already_blocked_skipped'          => $alreadyDoneSkipped,
            'candidates_to_block'              => count($candidates),
        ],
        'candidates' => $candidates,
        'csrf_token' => csrfToken(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── ENDPOINT 2: manual suspend one client (POST) ────────────────────────────
// Calls StarlinkBlockService::suspend() on a single client_id. Mirrors the
// webhook code path, including VIP guard inside the service. CSRF-protected.
// Designed to be called once per client by the bulk console script.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sl_manual_suspend') {
    header('Content-Type: application/json; charset=UTF-8');

    if (!csrfCheck()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'csrf_mismatch']);
        exit;
    }

    $clientId  = (int)($_POST['client_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_client_id']);
        exit;
    }

    try {
        $cfg = $store->load('plugin_config.json') ?? [];
        $localConfig = is_array($config ?? null) ? $config : (is_array($cfg) ? $cfg : []);
        $blockSvc = new \StarlinkBlockService($pdo, $store, $localConfig, $dataDir, $notify);
        $triggeredBy = 'manual:bulk:' . ($retailer['name'] ?? 'admin');
        // eventType='manual.bulk' makes it easy to distinguish from webhook fires
        // when reading sl_suspension_log later. Pass null for $freshClient so the
        // service uses cache (we don't have a fresh UCRM API response here).
        $result = $blockSvc->suspend($clientId, $serviceId, $triggeredBy, 'manual.bulk', null);

        logActivity($dataDir, 'sl_manual_suspend', 'Starlink manual bulk-suspend',
            "client_id={$clientId} ok=" . (!empty($result['ok']) ? 'yes' : 'no') .
            " routers=" . ($result['routers_processed'] ?? 0) .
            " skip=" . ($result['skipped_reason'] ?? '-') .
            " by=" . ($retailer['name'] ?? 'admin'));

        echo json_encode(['ok' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Sub-view router ──────────────────────────────────────────────────────────
$drillClientId = (int)($_GET['client_id'] ?? 0);

// ── Load UCRM client cache once for name enrichment ──────────────────────────
$ucrmClients = $store->load('ucrm_clients_cache.json') ?? [];
$clientById  = [];
foreach ($ucrmClients as $c) {
    $cid = (int)($c['id'] ?? 0);
    if ($cid > 0) $clientById[$cid] = $c;
}
$clientLabel = function(int $cid) use ($clientById): string {
    $c = $clientById[$cid] ?? null;
    if (!$c) return '#' . $cid;
    $name = trim((string)($c['companyName'] ?? '') ?: trim((string)($c['firstName'] ?? '') . ' ' . (string)($c['lastName'] ?? '')));
    if ($name === '') $name = '#' . $cid;
    return $name . ' <span style="color:#94a3b8;font-weight:500;">#' . $cid . '</span>';
};

// ── Time-since helper ────────────────────────────────────────────────────────
$timeSince = function(?string $ts): string {
    if (!$ts) return '—';
    $t = strtotime($ts);
    if (!$t) return $ts;
    $diff = time() - $t;
    if ($diff < 60)        return $diff . 's ago';
    if ($diff < 3600)      return floor($diff / 60) . 'm ago';
    if ($diff < 86400)     return floor($diff / 3600) . 'h ago';
    if ($diff < 86400 * 7) return floor($diff / 86400) . 'd ago';
    return substr($ts, 0, 10);
};

// ═════════════════════════════════════════════════════════════════════════════
// LIST VIEW (default)
// ═════════════════════════════════════════════════════════════════════════════
if ($drillClientId === 0):

    // Load all rows ordered by most recently active first
    try {
        $rows = $pdo->query(
            "SELECT * FROM sl_suspension_state
             ORDER BY last_attempt_at DESC, id DESC
             LIMIT 500"
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $rows = [];
        $flashMsg  = 'Failed to read sl_suspension_state: ' . htmlspecialchars($e->getMessage());
        $flashKind = 'danger';
    }

    // Optional state filter
    $filterState = (string)($_GET['state'] ?? '');
    if ($filterState !== '' && isset($STATE_LABELS[$filterState])) {
        $rows = array_values(array_filter($rows, fn($r) => ($r['state'] ?? '') === $filterState));
    }

    // KPIs — count per state across ALL rows (not just filtered)
    $stateCounts = [];
    foreach ($STATE_LABELS as $k => $_) $stateCounts[$k] = 0;
    try {
        $st = $pdo->query("SELECT state, COUNT(*) c FROM sl_suspension_state GROUP BY state")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($st as $s) $stateCounts[$s['state']] = (int)$s['c'];
    } catch (\Throwable $e) {}
    $totalActive = array_sum($stateCounts);

    // Recent activity from log: how many gRPC calls in last 24h?
    $logRecentCount = 0;
    try {
        $logRecentCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM sl_suspension_log WHERE ts >= datetime('now','-1 day')"
        )->fetchColumn();
    } catch (\Throwable $e) {}

    // Bypass events count
    $totalBypassEvents = 0;
    try {
        $totalBypassEvents = (int)$pdo->query(
            "SELECT COALESCE(SUM(bypass_event_count),0) FROM sl_suspension_state"
        )->fetchColumn();
    } catch (\Throwable $e) {}
?>

<!-- ─── Page header ─── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <div>
    <h2 style="margin:0;font-size:18px;font-weight:800;color:#1e293b;">
      <i class="bi bi-shield-slash-fill" style="color:#D41C1C;margin-right:6px;"></i>
      Starlink Auto-Suspensions
    </h2>
    <div style="font-size:11px;color:#64748b;margin-top:2px;">
      Live state of automated device-blocking. Driven by webhook.php → StarlinkBlockService.
    </div>
  </div>
  <div style="font-size:11px;color:#64748b;">
    <a href="?page=dashboard&tab=starlink_suspensions" style="text-decoration:none;color:#D41C1C;font-weight:600;">
      <i class="bi bi-arrow-clockwise"></i> Refresh
    </a>
  </div>
</div>

<?php if ($flashMsg): ?>
  <?php
    $flashStyles = [
        'success' => 'background:#E8F5E9;color:#2E7D32;border-color:#A5D6A7;',
        'warning' => 'background:#FFF3E0;color:#E65100;border-color:#FFB74D;',
        'danger'  => 'background:#FFEBEE;color:#c0392b;border-color:#EF9A9A;',
        'info'    => 'background:#E3F2FD;color:#1565C0;border-color:#90CAF9;',
    ];
    $fs = $flashStyles[$flashKind] ?? $flashStyles['info'];
  ?>
  <div style="padding:11px 14px;border-radius:9px;border:1px solid;font-size:13px;font-weight:600;margin-bottom:14px;<?= $fs ?>">
    <?= $flashMsg ?>
  </div>
<?php endif; ?>

<!-- ─── KPI row ─── -->
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:16px;">
  <div style="background:<?= $STATE_LABELS['suspended']['bg'] ?>;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:<?= $STATE_LABELS['suspended']['color'] ?>;">
      <?= $stateCounts['suspended'] ?? 0 ?>
    </div>
    <div style="font-size:10px;font-weight:700;color:<?= $STATE_LABELS['suspended']['color'] ?>;text-transform:uppercase;letter-spacing:.5px;">
      Suspended
    </div>
  </div>
  <div style="background:<?= $STATE_LABELS['suspending']['bg'] ?>;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:<?= $STATE_LABELS['suspending']['color'] ?>;">
      <?= $stateCounts['suspending'] ?? 0 ?>
    </div>
    <div style="font-size:10px;font-weight:700;color:<?= $STATE_LABELS['suspending']['color'] ?>;text-transform:uppercase;letter-spacing:.5px;">
      Suspending
    </div>
  </div>
  <div style="background:<?= $STATE_LABELS['restoring']['bg'] ?>;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:<?= $STATE_LABELS['restoring']['color'] ?>;">
      <?= $stateCounts['restoring'] ?? 0 ?>
    </div>
    <div style="font-size:10px;font-weight:700;color:<?= $STATE_LABELS['restoring']['color'] ?>;text-transform:uppercase;letter-spacing:.5px;">
      Restoring
    </div>
  </div>
  <div style="background:<?= $STATE_LABELS['partial_suspend_failed']['bg'] ?>;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:<?= $STATE_LABELS['partial_suspend_failed']['color'] ?>;">
      <?= $stateCounts['partial_suspend_failed'] ?? 0 ?>
    </div>
    <div style="font-size:10px;font-weight:700;color:<?= $STATE_LABELS['partial_suspend_failed']['color'] ?>;text-transform:uppercase;letter-spacing:.5px;">
      Partial / Failed
    </div>
  </div>
  <div style="background:<?= $STATE_LABELS['error_manual_required']['bg'] ?>;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:<?= $STATE_LABELS['error_manual_required']['color'] ?>;">
      <?= $stateCounts['error_manual_required'] ?? 0 ?>
    </div>
    <div style="font-size:10px;font-weight:700;color:<?= $STATE_LABELS['error_manual_required']['color'] ?>;text-transform:uppercase;letter-spacing:.5px;">
      Manual Needed
    </div>
  </div>
  <div style="background:#F1F5F9;border-radius:12px;padding:12px;text-align:center;">
    <div style="font-size:22px;font-weight:900;color:#475569;">
      <?= $logRecentCount ?>
    </div>
    <div style="font-size:10px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.5px;">
      gRPC calls / 24h
    </div>
  </div>
</div>

<!-- ─── Bypass alert (if any staff are unpausing manually) ─── -->
<?php if ($totalBypassEvents > 0): ?>
<div style="padding:11px 14px;border-radius:10px;background:#FFF3E0;border:1px solid #FFB74D;color:#E65100;font-size:12px;font-weight:600;margin-bottom:14px;">
  <i class="bi bi-shield-exclamation"></i>
  <strong><?= $totalBypassEvents ?></strong> bypass event(s) recorded across all suspensions
  (someone with Starlink credentials manually unpaused devices we'd blocked).
  Review per-row counts below.
</div>
<?php endif; ?>

<!-- ─── State filter chips ─── -->
<div style="display:flex;gap:6px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
  <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Filter:</span>
  <a href="?page=dashboard&tab=starlink_suspensions" style="padding:4px 10px;border-radius:14px;font-size:11px;font-weight:700;text-decoration:none;<?= $filterState === '' ? 'background:#1e293b;color:#fff;' : 'background:#F1F5F9;color:#475569;' ?>">
    All (<?= $totalActive ?>)
  </a>
  <?php foreach ($STATE_LABELS as $k => $info): ?>
    <a href="?page=dashboard&tab=starlink_suspensions&state=<?= $k ?>"
       style="padding:4px 10px;border-radius:14px;font-size:11px;font-weight:700;text-decoration:none;<?= $filterState === $k ? 'background:' . $info['color'] . ';color:#fff;' : 'background:' . $info['bg'] . ';color:' . $info['color'] . ';' ?>">
      <i class="bi <?= $info['icon'] ?>"></i> <?= $info['label'] ?> (<?= $stateCounts[$k] ?? 0 ?>)
    </a>
  <?php endforeach; ?>
</div>

<!-- ─── Main table ─── -->
<?php if (empty($rows)): ?>
  <div style="padding:32px;background:#F8FAFC;border-radius:12px;text-align:center;color:#64748b;font-size:13px;">
    <i class="bi bi-check2-circle" style="font-size:28px;color:#16a34a;display:block;margin-bottom:6px;"></i>
    No suspension rows<?= $filterState !== '' ? ' for state "' . htmlspecialchars($filterState) . '"' : '' ?>.<br>
    <span style="font-size:11px;">When UCRM fires service.suspend for a Starlink customer, a row will appear here.</span>
  </div>
<?php else: ?>
  <div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead>
        <tr style="background:#F8FAFC;border-bottom:1px solid #e2e8f0;">
          <th style="text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Customer</th>
          <th style="text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Router / KIT</th>
          <th style="text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">State</th>
          <th style="text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Mode</th>
          <th style="text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Suspended</th>
          <th style="text-align:left;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Last Try</th>
          <th style="text-align:center;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Tries</th>
          <th style="text-align:center;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Bypass</th>
          <th style="text-align:right;padding:9px 12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $cid       = (int)($r['client_id'] ?? 0);
        $routerId  = (string)($r['router_id'] ?? '');
        $kit       = (string)($r['kit_serial'] ?? '');
        $state     = (string)($r['state'] ?? '');
        $mode      = (string)($r['block_mode'] ?? 'full');
        $isBypass  = (int)($r['is_bypass_mode'] ?? 0) === 1;
        $tries     = (int)($r['attempt_count'] ?? 0);
        $bypassCnt = (int)($r['bypass_event_count'] ?? 0);
        $err       = trim((string)($r['last_error'] ?? ''));
        $pausedMacs = json_decode((string)($r['paused_macs_json'] ?? '[]'), true) ?: [];
        $pausedN    = count($pausedMacs);
      ?>
        <tr style="border-top:1px solid #f1f5f9;">
          <td style="padding:10px 12px;vertical-align:top;">
            <div style="font-weight:700;color:#1e293b;"><?= $clientLabel($cid) ?></div>
            <?php if ($err): ?>
              <div style="font-size:10px;color:#c0392b;margin-top:2px;" title="<?= htmlspecialchars($err) ?>">
                <i class="bi bi-exclamation-triangle"></i>
                <?= htmlspecialchars(substr($err, 0, 70)) ?><?= strlen($err) > 70 ? '…' : '' ?>
              </div>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px;vertical-align:top;font-family:'JetBrains Mono','Courier New',monospace;font-size:11px;color:#475569;">
            <div><?= htmlspecialchars($routerId) ?></div>
            <?php if ($kit): ?>
              <div style="color:#94a3b8;font-size:10px;">KIT <?= htmlspecialchars($kit) ?></div>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px;vertical-align:top;">
            <?= $pill($state) ?>
            <?php if ($pausedN > 0): ?>
              <div style="font-size:10px;color:#64748b;margin-top:3px;">
                <?= $pausedN ?> device(s) paused
              </div>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px;vertical-align:top;">
            <?php if ($isBypass): ?>
              <span style="display:inline-block;padding:2px 7px;border-radius:9px;background:#FFF3E0;color:#E65100;font-size:10px;font-weight:700;">
                BYPASS
              </span>
            <?php elseif ($mode === 'pause_only'): ?>
              <span style="display:inline-block;padding:2px 7px;border-radius:9px;background:#E3F2FD;color:#1565C0;font-size:10px;font-weight:700;">
                PAUSE-ONLY
              </span>
            <?php else: ?>
              <span style="display:inline-block;padding:2px 7px;border-radius:9px;background:#FFEBEE;color:#c0392b;font-size:10px;font-weight:700;">
                FULL
              </span>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px;vertical-align:top;font-size:11px;color:#475569;">
            <div><?= htmlspecialchars(substr((string)($r['suspended_at'] ?? ''), 0, 16)) ?></div>
            <div style="color:#94a3b8;font-size:10px;"><?= $timeSince($r['suspended_at'] ?? null) ?></div>
          </td>
          <td style="padding:10px 12px;vertical-align:top;font-size:11px;color:#475569;">
            <?= $timeSince($r['last_attempt_at'] ?? null) ?>
          </td>
          <td style="padding:10px 12px;vertical-align:top;text-align:center;font-weight:700;color:<?= $tries >= 5 ? '#c0392b' : ($tries >= 3 ? '#E65100' : '#475569') ?>;">
            <?= $tries ?>
          </td>
          <td style="padding:10px 12px;vertical-align:top;text-align:center;">
            <?php if ($bypassCnt > 0): ?>
              <span style="display:inline-block;padding:2px 7px;border-radius:9px;background:#FFF3E0;color:#E65100;font-weight:700;font-size:11px;" title="<?= htmlspecialchars((string)($r['last_bypass_at'] ?? '')) ?>">
                <?= $bypassCnt ?>×
              </span>
            <?php else: ?>
              <span style="color:#cbd5e1;">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px;vertical-align:top;text-align:right;white-space:nowrap;">
            <a href="?page=dashboard&tab=starlink_suspensions&client_id=<?= $cid ?>"
               style="display:inline-block;padding:5px 10px;border-radius:7px;background:#F1F5F9;color:#1e293b;text-decoration:none;font-size:11px;font-weight:700;margin-right:4px;">
              <i class="bi bi-search"></i> Detail
            </a>
            <?php if (in_array($state, ['suspended', 'partial_suspend_failed', 'error_manual_required', 'restoring'], true)): ?>
              <form method="post" style="display:inline;" onsubmit="return confirm('Force-restore this client? This calls Starlink gRPC to unpause devices and restore the original SSID/password. Idempotent — safe to retry.');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sl_force_restore">
                <input type="hidden" name="client_id" value="<?= $cid ?>">
                <button type="submit"
                        style="padding:5px 10px;border-radius:7px;background:#16a34a;color:#fff;border:0;font-size:11px;font-weight:700;cursor:pointer;">
                  <i class="bi bi-arrow-counterclockwise"></i> Restore
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<!-- ─── Bulk-block tools (collapsible) ─── -->
<details style="margin-top:18px;background:#FFF8E1;border:1px solid #FFCA28;border-radius:12px;padding:14px;">
  <summary style="cursor:pointer;font-size:13px;font-weight:800;color:#E65100;list-style:none;">
    <i class="bi bi-tools"></i>
    Bulk-block tools (Chrome console script)
    <span style="font-weight:500;color:#94a3b8;font-size:11px;margin-left:6px;">— for backfilling missed blocks</span>
  </summary>

  <div style="margin-top:12px;font-size:12px;color:#475569;line-height:1.5;">
    <p style="margin:0 0 10px 0;">
      Use this when auto-block has been failing (e.g. v4.21.0–v4.21.16, before the table self-heal landed)
      and you have a backlog of suspended-but-not-actually-blocked customers. The script:
    </p>
    <ol style="margin:0 0 10px 18px;padding:0;">
      <li>Calls the audit endpoint to find suspended Starlink customers who are <em>not</em> VIP and <em>not</em> already blocked.</li>
      <li>Shows you the full list with names + balances. Asks for confirmation.</li>
      <li>Calls <code>StarlinkBlockService::suspend()</code> for each — same code path as the webhook, including the in-service VIP guard. 2-second pause between each to be gentle on Starlink's gRPC API.</li>
      <li>Logs progress in the console with ✓ / ✗ markers.</li>
    </ol>
    <p style="margin:0 0 10px 0;">
      <strong>How to run:</strong> right-click anywhere on this page → Inspect → Console tab → paste the snippet → Enter.
      You'll be asked to confirm before any blocks fire.
    </p>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-family:'JetBrains Mono','Courier New',monospace;font-size:11px;color:#1e293b;white-space:pre;overflow-x:auto;line-height:1.5;">(async () => {
  const base = location.pathname.replace(/[^/]*$/, '') + 'public.php';
  const r = await fetch(base + '?page=dashboard&tab=starlink_suspensions&sl_action=audit_suspended_starlink', {credentials:'same-origin'});
  const audit = await r.json();
  if (!audit.ok) { console.error('Audit failed', audit); return; }

  const s = audit.summary;
  console.log('%c=== DishNet Bulk-Block Audit ===', 'font-weight:bold;color:#D41C1C;');
  console.log('Total suspended Starlink customers:', s.total_suspended_starlink_clients);
  console.log('  → VIP (skipped):', s.vip_skipped);
  console.log('  → Already blocked (skipped):', s.already_blocked_skipped);
  console.log('  → Candidates to block now:', s.candidates_to_block);
  console.table(audit.candidates.map(c => ({client_id: c.client_id, name: c.name, balance: c.balance, kits: c.kit_serials.join(',')})));

  if (audit.candidates.length === 0) { console.log('Nothing to do.'); return; }
  if (!confirm(`Block ${audit.candidates.length} non-VIP suspended Starlink customers now?\n\nThis pauses devices via Starlink gRPC. Pause-only mode by default (existing WiFi password preserved).\n\nProceed?`)) { console.log('Cancelled.'); return; }

  let ok = 0, fail = 0;
  for (const c of audit.candidates) {
    const fd = new FormData();
    fd.append('_csrf', audit.csrf_token);
    fd.append('action', 'sl_manual_suspend');
    fd.append('client_id', c.client_id);
    fd.append('service_id', c.service_id);
    try {
      const resp = await fetch(base + '?page=dashboard&tab=starlink_suspensions', {method:'POST', body: fd, credentials:'same-origin'});
      const j = await resp.json();
      if (j.ok && j.result && j.result.ok) {
        ok++;
        console.log(`%c✓ ${c.name} (#${c.client_id}) — routers=${j.result.routers_processed||0} skip=${j.result.skipped_reason||'-'}`, 'color:#16a34a');
      } else {
        fail++;
        console.warn(`✗ ${c.name} (#${c.client_id}) —`, j.error || j.result);
      }
    } catch (e) { fail++; console.error(`✗ ${c.name} (#${c.client_id}) —`, e); }
    await new Promise(r => setTimeout(r, 2000)); // 2s gap between calls
  }
  console.log(`%c=== Done: ${ok} blocked, ${fail} failed ===`, 'font-weight:bold;color:#D41C1C;');
})();</div>

    <p style="margin:10px 0 0 0;font-size:11px;color:#94a3b8;">
      <i class="bi bi-shield-check"></i>
      <strong>Safety:</strong> uses the same <code>suspend()</code> path as the webhook, so the in-service VIP guard fires (defense-in-depth — even if the audit's VIP detection misses someone). Audit endpoint is read-only. Suspend endpoint is CSRF-protected and admin-only. Each block is recorded in <code>sl_suspension_state</code> and <code>sl_suspension_log</code> with <code>triggered_by_event='manual.bulk'</code> for traceability.
    </p>
  </div>
</details>

<!-- ─── Footnote ─── -->
<div style="margin-top:12px;font-size:10px;color:#94a3b8;line-height:1.5;">
  <i class="bi bi-info-circle"></i>
  Rows are deleted automatically when a restore completes successfully (state machine: suspending → suspended → restoring → row removed).
  Rows in <strong>error_manual_required</strong> have failed retry 5+ times — investigate manually before forcing restore.
  See <code>SAFETY.md → CRITICAL PATH: Starlink Auto-Block</code> for impact map.
</div>

<?php
// ═════════════════════════════════════════════════════════════════════════════
// DETAIL VIEW (per-client)
// ═════════════════════════════════════════════════════════════════════════════
else:

    // Load all state rows for this client (multi-router safe)
    try {
        $stmt = $pdo->prepare("SELECT * FROM sl_suspension_state WHERE client_id = ? ORDER BY id ASC");
        $stmt->execute([$drillClientId]);
        $stateRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $stateRows = [];
    }

    // Load audit log for this client
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM sl_suspension_log
             WHERE client_id = ?
             ORDER BY id DESC
             LIMIT 200"
        );
        $stmt->execute([$drillClientId]);
        $logRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $logRows = [];
    }

    $clientName = $clientLabel($drillClientId);
?>

<!-- ─── Detail header ─── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
  <div>
    <div style="font-size:11px;color:#64748b;margin-bottom:2px;">
      <a href="?page=dashboard&tab=starlink_suspensions" style="color:#D41C1C;text-decoration:none;">
        <i class="bi bi-arrow-left"></i> Back to all suspensions
      </a>
    </div>
    <h2 style="margin:0;font-size:18px;font-weight:800;color:#1e293b;">
      <i class="bi bi-shield-slash" style="color:#D41C1C;margin-right:6px;"></i>
      <?= $clientName ?>
    </h2>
  </div>
</div>

<?php if ($flashMsg): ?>
  <?php
    $flashStyles = [
        'success' => 'background:#E8F5E9;color:#2E7D32;border-color:#A5D6A7;',
        'warning' => 'background:#FFF3E0;color:#E65100;border-color:#FFB74D;',
        'danger'  => 'background:#FFEBEE;color:#c0392b;border-color:#EF9A9A;',
        'info'    => 'background:#E3F2FD;color:#1565C0;border-color:#90CAF9;',
    ];
    $fs = $flashStyles[$flashKind] ?? $flashStyles['info'];
  ?>
  <div style="padding:11px 14px;border-radius:9px;border:1px solid;font-size:13px;font-weight:600;margin-bottom:14px;<?= $fs ?>">
    <?= $flashMsg ?>
  </div>
<?php endif; ?>

<!-- ─── State rows for this client ─── -->
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:14px;margin-bottom:16px;">
  <h3 style="margin:0 0 10px 0;font-size:13px;font-weight:800;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;">
    Suspension State (<?= count($stateRows) ?>)
  </h3>
  <?php if (empty($stateRows)): ?>
    <div style="padding:18px;text-align:center;color:#64748b;font-size:12px;">
      No active suspension state for this client. The audit log below may still show prior activity.
    </div>
  <?php else: ?>
    <?php foreach ($stateRows as $r):
      $cid       = (int)($r['client_id'] ?? 0);
      $routerId  = (string)($r['router_id'] ?? '');
      $state     = (string)($r['state'] ?? '');
      $mode      = (string)($r['block_mode'] ?? 'full');
      $isBypass  = (int)($r['is_bypass_mode'] ?? 0) === 1;
      $pausedMacs = json_decode((string)($r['paused_macs_json'] ?? '[]'), true) ?: [];
      $preExisting = json_decode((string)($r['pre_existing_paused_json'] ?? '[]'), true) ?: [];
    ?>
      <div style="border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:10px;background:#FAFBFC;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:9px;">
          <div>
            <div style="font-family:'JetBrains Mono','Courier New',monospace;font-size:13px;font-weight:700;color:#1e293b;">
              <?= htmlspecialchars($routerId) ?>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">
              <?= $pill($state) ?>
              <?php if ($isBypass): ?>
                <span style="display:inline-block;padding:2px 7px;border-radius:9px;background:#FFF3E0;color:#E65100;font-size:10px;font-weight:700;margin-left:4px;">BYPASS-MODE</span>
              <?php endif; ?>
              <span style="display:inline-block;padding:2px 7px;border-radius:9px;background:#F1F5F9;color:#475569;font-size:10px;font-weight:700;margin-left:4px;">
                <?= htmlspecialchars(strtoupper($mode)) ?>
              </span>
            </div>
          </div>
          <?php if (in_array($state, ['suspended', 'partial_suspend_failed', 'error_manual_required', 'restoring'], true)): ?>
            <form method="post" onsubmit="return confirm('Force-restore this client? Idempotent.');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="sl_force_restore">
              <input type="hidden" name="client_id" value="<?= $cid ?>">
              <button type="submit"
                      style="padding:6px 12px;border-radius:7px;background:#16a34a;color:#fff;border:0;font-size:12px;font-weight:700;cursor:pointer;">
                <i class="bi bi-arrow-counterclockwise"></i> Force Restore
              </button>
            </form>
          <?php endif; ?>
        </div>

        <!-- Detail grid -->
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;font-size:11px;">
          <div><span style="color:#94a3b8;">KIT:</span> <code><?= htmlspecialchars((string)($r['kit_serial'] ?? '—')) ?></code></div>
          <div><span style="color:#94a3b8;">Account:</span> <code><?= htmlspecialchars((string)($r['account_number'] ?? '—')) ?></code></div>
          <div><span style="color:#94a3b8;">CRM Service:</span> #<?= (int)($r['crm_service_id'] ?? 0) ?></div>
          <div><span style="color:#94a3b8;">Triggered by:</span> <?= htmlspecialchars((string)($r['suspended_by'] ?? '—')) ?> / <?= htmlspecialchars((string)($r['triggered_by_event'] ?? '—')) ?></div>
          <div><span style="color:#94a3b8;">Suspended at:</span> <?= htmlspecialchars((string)($r['suspended_at'] ?? '—')) ?></div>
          <div><span style="color:#94a3b8;">Last attempt:</span> <?= htmlspecialchars((string)($r['last_attempt_at'] ?? '—')) ?> (try <?= (int)($r['attempt_count'] ?? 0) ?>)</div>
          <?php if (!empty($r['restore_started_at'])): ?>
            <div><span style="color:#94a3b8;">Restore started:</span> <?= htmlspecialchars((string)$r['restore_started_at']) ?></div>
            <div><span style="color:#94a3b8;">Restore by:</span> <?= htmlspecialchars((string)($r['restore_triggered_by'] ?? '—')) ?></div>
          <?php endif; ?>
          <?php if ($mode === 'full'): ?>
            <div><span style="color:#94a3b8;">Original SSID:</span> <code><?= htmlspecialchars((string)($r['original_ssid_24'] ?? '—')) ?></code></div>
            <div><span style="color:#94a3b8;">Suspension SSID:</span> <code><?= htmlspecialchars((string)($r['suspension_ssid'] ?? '—')) ?></code></div>
          <?php endif; ?>
          <?php if ((int)($r['bypass_event_count'] ?? 0) > 0): ?>
            <div style="grid-column:1/-1;padding:7px 9px;background:#FFF3E0;border-radius:7px;color:#E65100;font-weight:600;">
              <i class="bi bi-shield-exclamation"></i>
              <?= (int)$r['bypass_event_count'] ?> bypass event(s).
              Last: <?= htmlspecialchars((string)($r['last_bypass_at'] ?? '—')) ?>.
              <?php if (!empty($r['bypass_alerted_at'])): ?>
                Admin alerted: <?= htmlspecialchars((string)$r['bypass_alerted_at']) ?>.
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($err = trim((string)($r['last_error'] ?? ''))): ?>
          <div style="margin-top:9px;padding:8px 10px;background:#FFEBEE;border-radius:7px;color:#c0392b;font-size:11px;font-family:'JetBrains Mono','Courier New',monospace;">
            <strong>Last error:</strong> <?= htmlspecialchars($err) ?>
          </div>
        <?php endif; ?>

        <!-- Paused MACs -->
        <?php if (!empty($pausedMacs) || !empty($preExisting)): ?>
          <div style="margin-top:9px;font-size:11px;">
            <?php if (!empty($pausedMacs)): ?>
              <div><span style="color:#94a3b8;">Paused by us (<?= count($pausedMacs) ?>):</span>
                <code style="font-size:10px;"><?= htmlspecialchars(implode(', ', array_slice($pausedMacs, 0, 8))) ?><?= count($pausedMacs) > 8 ? ' +' . (count($pausedMacs) - 8) . ' more' : '' ?></code>
              </div>
            <?php endif; ?>
            <?php if (!empty($preExisting)): ?>
              <div style="margin-top:3px;"><span style="color:#94a3b8;">Pre-existing paused (left alone, <?= count($preExisting) ?>):</span>
                <code style="font-size:10px;"><?= htmlspecialchars(implode(', ', array_slice($preExisting, 0, 8))) ?></code>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ─── Audit log ─── -->
<div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:14px;">
  <h3 style="margin:0 0 10px 0;font-size:13px;font-weight:800;color:#1e293b;text-transform:uppercase;letter-spacing:.5px;">
    Audit Log (<?= count($logRows) ?> most recent)
  </h3>
  <?php if (empty($logRows)): ?>
    <div style="padding:18px;text-align:center;color:#64748b;font-size:12px;">No audit log entries.</div>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:11px;">
      <thead>
        <tr style="background:#F8FAFC;">
          <th style="text-align:left;padding:7px 9px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">When</th>
          <th style="text-align:left;padding:7px 9px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Router</th>
          <th style="text-align:left;padding:7px 9px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Action</th>
          <th style="text-align:center;padding:7px 9px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Result</th>
          <th style="text-align:center;padding:7px 9px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">gRPC</th>
          <th style="text-align:left;padding:7px 9px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.4px;font-size:10px;">Detail</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logRows as $lr):
        $success = (int)($lr['success'] ?? 0) === 1;
        $action  = (string)($lr['action'] ?? '');
      ?>
        <tr style="border-top:1px solid #f1f5f9;">
          <td style="padding:6px 9px;color:#64748b;white-space:nowrap;font-family:'JetBrains Mono','Courier New',monospace;font-size:10px;">
            <?= htmlspecialchars(substr((string)($lr['ts'] ?? ''), 0, 19)) ?>
          </td>
          <td style="padding:6px 9px;font-family:'JetBrains Mono','Courier New',monospace;font-size:10px;color:#475569;">
            <?= htmlspecialchars((string)($lr['router_id'] ?? '')) ?>
          </td>
          <td style="padding:6px 9px;font-weight:600;color:#1e293b;">
            <?= htmlspecialchars($action) ?>
            <?php if ((int)($lr['attempt_number'] ?? 1) > 1): ?>
              <span style="color:#94a3b8;font-weight:500;font-size:10px;">(try <?= (int)$lr['attempt_number'] ?>)</span>
            <?php endif; ?>
          </td>
          <td style="padding:6px 9px;text-align:center;">
            <?php if ($success): ?>
              <i class="bi bi-check-circle-fill" style="color:#16a34a;"></i>
            <?php else: ?>
              <i class="bi bi-x-circle-fill" style="color:#c0392b;"></i>
            <?php endif; ?>
          </td>
          <td style="padding:6px 9px;text-align:center;font-family:'JetBrains Mono','Courier New',monospace;font-size:10px;color:#64748b;">
            <?php
              $gs = $lr['grpc_status'];
              if ($gs === null || $gs === '') echo '—';
              else echo (int)$gs;
            ?>
          </td>
          <td style="padding:6px 9px;color:#475569;font-size:11px;">
            <?php
              $det = trim((string)($lr['detail'] ?? ''));
              $msg = trim((string)($lr['grpc_message'] ?? ''));
              $combined = $det;
              if ($msg !== '' && $msg !== $det) $combined .= ($combined !== '' ? ' — ' : '') . $msg;
              if ($combined === '') echo '<span style="color:#cbd5e1;">—</span>';
              else echo htmlspecialchars(substr($combined, 0, 110)) . (strlen($combined) > 110 ? '…' : '');
            ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php endif; // end detail view ?>
