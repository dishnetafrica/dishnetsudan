<?php
// Tab: maintenance
// Extracted from public.php on 2026-03-15
        $maintLog        = $store->load('maintenance_log.json');
        $lastMaint       = !empty($maintLog) ? end($maintLog) : null;
        $integrityLog    = $store->load('wallet_integrity_log.json');
        $alertFile       = $dataDir . '/WALLET_INTEGRITY_ALERT.txt';
        $hasAlert        = file_exists($alertFile);
        $recentDiscrepancies = array_filter($integrityLog, fn($e) => ($e['detected_at'] ?? '') >= date('Y-m-d', strtotime('-7 days')));
        $backupBase      = $dataDir . '/_backups';
        $dailyBackups    = glob($backupBase . '/daily_*', GLOB_ONLYDIR) ?: [];
        $weeklyBackups   = glob($backupBase . '/weekly_*', GLOB_ONLYDIR) ?: [];
        $monthlyBackups  = glob($backupBase . '/monthly_*', GLOB_ONLYDIR) ?: [];
        rsort($dailyBackups); rsort($weeklyBackups); rsort($monthlyBackups);
    ?>


// ── Auto Data Health Fix (Starlink Finance pattern) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto_data_fix') {
    $auth->requireAdmin();
    $apps    = $store->load('kyc_applications.json');
    $passbook= $store->load('passbook.json');
    $dryRun  = !empty($_POST['dry_run']);
    $fixes   = [];

    // Fix 1: Applications missing created_at
    $fixed_dates = 0;
    foreach ($apps as &$a) {
        if (empty($a['created_at'])) {
            $a['created_at'] = date('Y-m-d H:i:s');
            $fixed_dates++;
        }
    }
    unset($a);
    if ($fixed_dates) $fixes[] = "Fixed {$fixed_dates} applications missing created_at";

    // Fix 2: Applications with status 'approved' but no crm_client_id → reset to pending_sync
    $fixed_status = 0;
    foreach ($apps as &$a) {
        if (($a['status']??'') === 'approved' && empty($a['crm_client_id'])) {
            $a['status'] = 'pending_sync';
            $fixed_status++;
        }
    }
    unset($a);
    if ($fixed_status) $fixes[] = "Reset {$fixed_status} orphaned 'approved' → 'pending_sync'";

    // Fix 3: Orphaned passbook entries with no retailer_id
    $fixed_passbook = 0;
    foreach ($passbook as $t) {
        if (empty($t['retailer_id'])) $fixed_passbook++;
    }
    if ($fixed_passbook) $fixes[] = "Found {$fixed_passbook} passbook entries with no retailer_id (manual review needed)";

    // Fix 4: Applications with phone numbers containing spaces/dashes
    $fixed_phones = 0;
    foreach ($apps as &$a) {
        $clean = preg_replace('/[\s\-\(\)]/', '', $a['mobile'] ?? '');
        if ($clean !== ($a['mobile'] ?? '')) { $a['mobile'] = $clean; $fixed_phones++; }
    }
    unset($a);
    if ($fixed_phones) $fixes[] = "Normalised {$fixed_phones} phone numbers (removed spaces/dashes)";

    if (!$dryRun && !empty($fixes)) {
        $store->save('kyc_applications.json', $apps);
        logActivity($dataDir, 'data_fix', 'Auto data health fix applied', implode('; ', $fixes));
    }

    $msg = empty($fixes) ? '✅ No issues found — data looks healthy!' : implode("\n", array_map(fn($f)=>"• {$f}", $fixes));
    if ($dryRun) $msg = "[DRY RUN — no changes saved]\n" . $msg;
    flash($msg, empty($fixes) ? 'success' : 'warning');
    redirect('?page=dashboard&tab=maintenance');
}
?>

<!-- ── SQLITE DATABASE STATS ─────────────────────────────────────────── -->
<?php
$dbStats   = ($store instanceof SqliteStore) ? $store->stats() : null;
$dbSizeHuman = $dbStats['__db_size_human'] ?? '—';
$dbWal     = $dbStats['__wal_mode']      ?? '—';
$dbVer     = $dbStats['__sqlite_version'] ?? '—';
$dbPath    = $dataDir . '/plugin.sqlite3';
$isJson    = !($store instanceof SqliteStore);
// Table counts — exclude meta keys
$tableCounts = [];
if ($dbStats) {
    foreach ($dbStats as $k => $v) {
        if (!str_starts_with($k, '__')) $tableCounts[$k] = (int)$v;
    }
    arsort($tableCounts);
}
?>
<div class="kyc-card" style="margin-bottom:1.5rem;border:1px solid <?= $isJson ? '#fbbf24' : '#a7f3d0' ?>;">
  <div class="kyc-card-header" style="background:linear-gradient(135deg,<?= $isJson ? '#92400e,#b45309' : '#065f46,#047857' ?>);color:#fff;display:flex;align-items:center;justify-content:space-between;">
    <span><i class="bi bi-database-fill"></i> Database Engine</span>
    <span style="background:rgba(255,255,255,.2);padding:2px 12px;border-radius:20px;font-size:11px;font-weight:700;">
      <?= $isJson ? '⚠️ JSON Files (slow at scale)' : '✅ SQLite v' . h($dbVer) ?>
    </span>
  </div>
  <div class="kyc-card-body">
    <?php if ($isJson): ?>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
      <div style="font-weight:800;color:#92400e;margin-bottom:6px;">⚠️ Running on flat JSON files</div>
      <p style="font-size:12px;color:#78350f;margin:0;">JSON works fine under ~2,000 records per file. Above that, every read loads the entire file into memory.
      The plugin is already configured for SQLite — it will activate automatically on next deploy or when <code>plugin.sqlite3</code> is present in the data directory.
      The first boot migration imports all JSON data automatically.</p>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;">
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:12px;text-align:center;">
        <div style="font-size:22px;font-weight:900;color:#065f46;"><?= h($dbSizeHuman) ?></div>
        <div style="font-size:10px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">DB Size</div>
      </div>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px;text-align:center;">
        <div style="font-size:22px;font-weight:900;color:#1d4ed8;"><?= count($tableCounts) ?></div>
        <div style="font-size:10px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Tables</div>
      </div>
      <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:12px;padding:12px;text-align:center;">
        <div style="font-size:22px;font-weight:900;color:#6d28d9;"><?= array_sum($tableCounts) ?></div>
        <div style="font-size:10px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total Rows</div>
      </div>
      <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:12px;text-align:center;">
        <div style="font-size:18px;font-weight:900;color:#c2410c;"><?= strtoupper(h($dbWal)) ?></div>
        <div style="font-size:10px;color:#6b7280;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Journal Mode</div>
      </div>
    </div>
    <!-- Per-table row counts -->
    <?php if (!empty($tableCounts)): ?>
    <div style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;">Row Counts by Table</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">
      <?php foreach ($tableCounts as $tbl => $cnt):
        $heat = $cnt > 1000 ? '#dc2626' : ($cnt > 200 ? '#d97706' : '#16a34a');
      ?>
      <span style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:4px 10px;font-size:11px;display:inline-flex;align-items:center;gap:5px;">
        <span style="font-weight:600;color:#374151;"><?= h($tbl) ?></span>
        <span style="font-weight:900;color:<?= $heat ?>;"><?= number_format($cnt) ?></span>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <!-- Export + vacuum actions -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <form method="POST" style="display:inline;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="sqlite_export_json">
        <button type="submit" style="background:#fff5f5;border:1.5px solid #D41C1C;color:#D41C1C;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;"
          onclick="return confirm('Export all SQLite tables to JSON backup files in the data directory?')">
          📤 Export to JSON Backup
        </button>
      </form>
      <form method="POST" style="display:inline;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="sqlite_vacuum">
        <button type="submit" style="background:#f0fdf4;border:1.5px solid #22c55e;color:#15803d;padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;"
          onclick="return confirm('Run VACUUM to compact the SQLite database? Safe to run anytime.')">
          🧹 VACUUM (Compact DB)
        </button>
      </form>
      <span style="font-size:11px;color:#9ca3af;">📁 <?= h(basename($dataDir)) ?>/plugin.sqlite3</span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── AUTO DATA HEALTH FIX (Starlink Finance pattern) ────────────────── -->
<?php
$healthFixResults = null;
$appsForHealth   = $store->load('kyc_applications.json');
$healthStats = [
    'missing_dates'   => count(array_filter($appsForHealth, fn($a)=>empty($a['created_at']))),
    'orphaned_approved'=> count(array_filter($appsForHealth, fn($a)=>($a['status']??'')==='approved'&&empty($a['crm_client_id']))),
    'no_mobile'       => count(array_filter($appsForHealth, fn($a)=>empty($a['mobile']))),
    'total'           => count($appsForHealth),
];
$healthIssues = $healthStats['missing_dates'] + $healthStats['orphaned_approved'];
?>
<div class="kyc-card" style="margin-bottom:1.5rem;border:1px solid <?= $healthIssues>0?'#fbbf24':'#d1fae5' ?>;">
    <div class="kyc-card-header" style="background:linear-gradient(135deg,<?= $healthIssues>0?'#d97706,#b45309':'#059669,#047857' ?>);color:#fff;display:flex;align-items:center;justify-content:space-between;">
        <span><i class="bi bi-heart-pulse-fill"></i> Data Health Scanner</span>
        <span style="background:rgba(255,255,255,.2);padding:2px 12px;border-radius:20px;font-size:11px;font-weight:700;">
            <?= $healthIssues > 0 ? $healthIssues.' issue'.($healthIssues>1?'s':'').' found' : '✓ Healthy' ?>
        </span>
    </div>
    <div class="kyc-card-body">
        <div class="kpi-grid" style="margin-bottom:16px;">
            <div class="kpi-card blue"><div class="kpi-label">Total Apps</div><div class="kpi-value"><?= $healthStats['total'] ?></div></div>
            <div class="kpi-card <?= $healthStats['missing_dates']>0?'orange':'green' ?>">
                <div class="kpi-label">Missing Dates</div>
                <div class="kpi-value"><?= $healthStats['missing_dates'] ?></div>
                <div class="kpi-sub">apps with no created_at</div>
            </div>
            <div class="kpi-card <?= $healthStats['orphaned_approved']>0?'red':'green' ?>">
                <div class="kpi-label">Orphaned Approved</div>
                <div class="kpi-value"><?= $healthStats['orphaned_approved'] ?></div>
                <div class="kpi-sub">approved but no CRM ID</div>
            </div>
            <div class="kpi-card <?= $healthStats['no_mobile']>0?'orange':'green' ?>">
                <div class="kpi-label">No Mobile</div>
                <div class="kpi-value"><?= $healthStats['no_mobile'] ?></div>
                <div class="kpi-sub">apps missing phone</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="auto_data_fix">
                <input type="hidden" name="dry_run" value="1">
                <button type="submit" style="background:#fff;border:1.5px solid #D41C1C;color:#2563EB;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;">
                    🔍 Dry Run (preview only)
                </button>
            </form>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Apply all data fixes? This will modify kyc_applications.json.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="auto_data_fix">
                <button type="submit" style="background:<?= $healthIssues>0?'#d97706':'#059669' ?>;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;">
                    🔧 <?= $healthIssues > 0 ? 'Fix '.$healthIssues.' Issue'.($healthIssues>1?'s':'') : 'Run Health Check' ?>
                </button>
            </form>
        </div>
        <div style="font-size:11px;color:#9ca3af;margin-top:10px;">
            Fixes: missing timestamps, orphaned approved status, phone number normalisation. Always dry-run first.
        </div>
    </div>
</div>

<!-- Maintenance Hero -->
<div style="background:linear-gradient(135deg,#1e3a5f,#0f2640);border-radius:20px;padding:20px;color:#fff;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:11px;opacity:.6;font-weight:700;text-transform:uppercase;letter-spacing:1px;">System Health</div>
            <div style="font-size:22px;font-weight:800;margin-top:4px;">🛡️ System Maintenance</div>
            <div style="font-size:12px;opacity:.75;margin-top:4px;">
                <?php if ($lastMaint): ?>
                    Last run: <strong><?= h($lastMaint['started_at']) ?></strong>
                    <span style="background:rgba(16,185,129,.3);padding:1px 8px;border-radius:20px;font-size:10px;margin-left:6px;">✓ Completed</span>
                <?php else: ?>
                    <span style="opacity:.6;">Never run — schedule cron_maintenance.php in crontab</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php
            $dbPath  = $dataDir . '/plugin.sqlite3';
            $hasSqlite = file_exists($dbPath);
            $dbSizeKb  = $hasSqlite ? round(filesize($dbPath) / 1024) : 0;
            ?>
            <div style="background:rgba(<?= $hasSqlite ? '99,102,241' : '107,114,128' ?>,.25);border:1px solid rgba(<?= $hasSqlite ? '99,102,241' : '107,114,128' ?>,.5);border-radius:12px;padding:8px 16px;font-size:12px;font-weight:700;color:<?= $hasSqlite ? '#c7d2fe' : '#d1d5db' ?>;">
                <?= $hasSqlite ? "🗄️ SQLite Active ({$dbSizeKb}KB)" : "📁 JSON Mode" ?>
            </div>
            <?php if ($hasAlert): ?>
                <div style="background:rgba(239,68,68,.25);border:1px solid rgba(239,68,68,.5);border-radius:12px;padding:8px 16px;font-size:12px;font-weight:700;color:#fca5a5;">
                    ⚠ WALLET INTEGRITY ALERT
                </div>
            <?php else: ?>
                <div style="background:rgba(16,185,129,.2);border:1px solid rgba(16,185,129,.4);border-radius:12px;padding:8px 16px;font-size:12px;font-weight:700;color:#6ee7b7;">
                    ✓ Wallets Balanced
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Status Cards Row -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
    <?php
    $walletOk   = !$hasAlert;
    $lastResult = $lastMaint['results'] ?? [];
    $archInfo   = $lastResult['archival'] ?? [];
    $backupInfo = $lastResult['backup'] ?? [];
    $integInfo  = $lastResult['wallet_check'] ?? [];

    $cards = [
        ['🏦','Wallet Integrity', $walletOk ? 'OK' : 'ALERT', $walletOk ? '#10b981' : '#ef4444',
            count($recentDiscrepancies) . ' discrepancy(ies) in last 7 days'],
        ['📦','Last Archival', isset($archInfo['crm_queue']) ? 'Ran' : 'Pending', '#3b82f6',
            'CRM queue: ' . ($archInfo['crm_queue']['archived'] ?? 0) . ' archived'],
        ['💾','Daily Backups', count($dailyBackups) . '/7', '#8b5cf6',
            empty($dailyBackups) ? 'None yet' : 'Latest: ' . basename(end($dailyBackups))],
        ['🗓️','Weekly Backups', count($weeklyBackups) . '/4', '#f59e0b', ''],
        ['📅','Monthly Backups', count($monthlyBackups) . '/3', '#06b6d4', ''],
    ];
    foreach ($cards as [$icon, $label, $val, $color, $sub]):
    ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px;border-top:3px solid <?= $color ?>;">
        <div style="font-size:20px;margin-bottom:6px;"><?= $icon ?></div>
        <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:.5px;"><?= h($label) ?></div>
        <div style="font-size:20px;font-weight:800;color:<?= $color ?>;margin:4px 0;"><?= h($val) ?></div>
        <?php if ($sub): ?><div style="font-size:10px;color:#94a3b8;"><?= h($sub) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Wallet Integrity Alert Banner -->
<?php if ($hasAlert): ?>
<div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:14px;padding:16px;margin-bottom:16px;">
    <div style="font-weight:800;font-size:13px;color:#dc2626;margin-bottom:8px;">⚠ Wallet Integrity Discrepancies Detected</div>
    <pre style="font-size:11px;color:#7f1d1d;white-space:pre-wrap;margin:0;"><?= h(file_get_contents($alertFile)) ?></pre>
    <div style="margin-top:12px;font-size:11px;color:#b91c1c;">
        Check <code>wallet_integrity_log.json</code> for full history.
        Investigate each retailer's passbook to find the missing transaction, then reconcile manually.
    </div>
</div>
<?php endif; ?>

<!-- SQLite Status Panel -->
<div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
        <div style="font-weight:800;font-size:13px;color:#1e293b;margin-bottom:4px;">🗄️ Database Engine</div>
        <?php if ($hasSqlite): ?>
            <div style="font-size:12px;color:#4b5563;">
                <strong style="color:#6366f1;">SQLite active</strong> —
                <code>data/plugin.sqlite3</code> (<?= $dbSizeKb ?>KB)
                &nbsp;·&nbsp; 38 collections &nbsp;·&nbsp; WAL mode &nbsp;·&nbsp; 25 indexes
            </div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px;">
                All data is in one file. Backup = <code>cp plugin.sqlite3 backup.sqlite3</code>
            </div>
        <?php else: ?>
            <div style="font-size:12px;color:#4b5563;">
                <strong style="color:#f59e0b;">JSON flat files</strong> — SQLite not yet activated or migrated.
            </div>
        <?php endif; ?>
    </div>
    <?php if ($hasSqlite): ?>
    <form method="POST" onsubmit="return confirm('Export all SQLite data to JSON files? This is for emergency rollback only.');">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="export_sqlite_to_json">
        <button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;cursor:pointer;">
            ⬇ Export SQLite → JSON
        </button>
    </form>
    <?php endif; ?>
</div>

<!-- Two column layout: Last Run Details + Backups -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">

    <!-- Last Maintenance Run -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;">
        <div style="font-weight:800;font-size:13px;color:#1e293b;margin-bottom:12px;">🔧 Last Maintenance Run</div>
        <?php if ($lastMaint): ?>
            <table style="width:100%;font-size:12px;border-collapse:collapse;">
                <tr><td style="color:#64748b;padding:4px 0;">Started</td><td style="font-weight:600;"><?= h($lastMaint['started_at']) ?></td></tr>
                <tr><td style="color:#64748b;padding:4px 0;">Ended</td><td style="font-weight:600;"><?= h($lastMaint['ended_at'] ?? '—') ?></td></tr>
                <tr><td style="color:#64748b;padding:4px 0;">Wallets checked</td><td style="font-weight:600;"><?= (int)($integInfo['checked'] ?? 0) ?></td></tr>
                <tr><td style="color:#64748b;padding:4px 0;">Discrepancies</td>
                    <td style="font-weight:700;color:<?= (int)($integInfo['discrepancies']??0) > 0 ? '#ef4444' : '#10b981' ?>;">
                        <?= (int)($integInfo['discrepancies'] ?? 0) ?>
                    </td>
                </tr>
                <tr><td style="color:#64748b;padding:4px 0;">CRM jobs archived</td><td style="font-weight:600;"><?= (int)($archInfo['crm_queue']['archived'] ?? 0) ?></td></tr>
                <tr><td style="color:#64748b;padding:4px 0;">LTE subs archived</td><td style="font-weight:600;"><?= (int)($archInfo['lte_subscriptions']['archived'] ?? 0) ?></td></tr>
                <tr><td style="color:#64748b;padding:4px 0;">KYC apps archived</td><td style="font-weight:600;"><?= (int)($archInfo['kyc_applications']['archived'] ?? 0) ?></td></tr>
                <tr><td style="color:#64748b;padding:4px 0;">Backups pruned</td><td style="font-weight:600;"><?= (int)($backupInfo['pruned'] ?? 0) ?></td></tr>
            </table>
            <div style="margin-top:10px;font-size:11px;color:#94a3b8;">
                Run history: <?= count($maintLog) ?> entries stored in maintenance_log.json
            </div>
        <?php else: ?>
            <div style="color:#94a3b8;font-size:12px;padding:20px 0;text-align:center;">
                No maintenance run recorded yet.<br>
                Add <code>cron_maintenance.php</code> to your server crontab.
            </div>
        <?php endif; ?>
    </div>

    <!-- Backup Inventory -->
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;">
        <div style="font-weight:800;font-size:13px;color:#1e293b;margin-bottom:12px;">💾 Backup Inventory</div>
        <?php foreach ([['Daily (7 kept)', $dailyBackups], ['Weekly (4 kept)', $weeklyBackups], ['Monthly (3 kept)', $monthlyBackups]] as [$label, $dirs]): ?>
        <div style="margin-bottom:10px;">
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;"><?= $label ?></div>
            <?php if (empty($dirs)): ?>
                <div style="font-size:11px;color:#cbd5e1;">None yet</div>
            <?php else: ?>
                <?php foreach ($dirs as $dir):
                    $size = 0;
                    // Primary: SQLite db file
                    $dbFile = $dir . '/plugin.sqlite3';
                    if (file_exists($dbFile)) $size += filesize($dbFile);
                    // Fallback: sum any remaining JSON files
                    foreach (glob($dir . '/*.json') ?: [] as $f) $size += filesize($f);
                    $sizeKb = round($size / 1024);
                ?>
                <div style="display:flex;justify-content:space-between;font-size:11px;padding:2px 0;border-bottom:1px solid #f1f5f9;">
                    <span style="color:#374151;"><?= h(basename($dir)) ?></span>
                    <span style="color:#94a3b8;"><?= $sizeKb ?>KB</span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Crontab Reference -->
<div style="background:#1e293b;border-radius:14px;padding:16px;color:#e2e8f0;">
    <div style="font-weight:800;font-size:12px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;">📋 Recommended Crontab (EAT / Africa/Juba)</div>
    <pre style="font-size:11px;color:#a5f3fc;margin:0;line-height:1.8;"><?php
        $syncPath  = h($config['cron_sync_path']        ?? __DIR__.'/cron_sync.php');
        $ltePath   = h($config['lte_cron_path']         ?? __DIR__.'/cron_lte.php');
        $maintPath = h($config['cron_maintenance_path'] ?? __DIR__.'/cron_maintenance.php');
        echo "# CRM Sync — every minute\n";
        echo "* * * * * php {$syncPath} >> /tmp/dishnet_sync.log 2>&1\n\n";
        echo "# LTE Auto-suspend — every 5 minutes\n";
        echo "*/5 * * * * php {$ltePath} >> /tmp/dishnet_lte.log 2>&1\n\n";
        echo "# Daily Maintenance (wallet check + archival + backups) — 02:00 EAT\n";
        echo "0 2 * * * php {$maintPath} >> /tmp/dishnet_maintenance.log 2>&1";
    ?></pre>
</div>

