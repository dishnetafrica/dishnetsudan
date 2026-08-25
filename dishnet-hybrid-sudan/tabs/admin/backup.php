<?php
// Tab: backup
// Extracted from public.php on 2026-03-15

require_once __DIR__ . '/../../lib/GoogleDriveBackup.php';
$_gdrive = new GoogleDriveBackup($dataDir);
$_gdriveConfig = $_gdrive->getConfig();

// ── Google Drive POST handlers ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gdrive_action'])) {
    if (!csrfCheck()) { flash('Security error.', 'danger'); redirect('?page=dashboard&tab=backup'); }

    $gact = $_POST['gdrive_action'];

    if ($gact === 'save_credentials') {
        $clientId     = trim($_POST['gdrive_client_id'] ?? '');
        $clientSecret = trim($_POST['gdrive_client_secret'] ?? '');
        $folderName   = trim($_POST['gdrive_folder_name'] ?? 'DishNet Backups');
        $schedule     = in_array($_POST['gdrive_schedule'] ?? '', ['daily','twice_daily','weekly']) ? $_POST['gdrive_schedule'] : 'daily';
        $retention    = max(3, min(30, (int)($_POST['gdrive_retention'] ?? 7)));
        if (!$clientId || !$clientSecret) { flash('Client ID and Secret are required.', 'danger'); redirect('?page=dashboard&tab=backup'); }
        $_gdrive->saveConfig(array_merge($_gdriveConfig, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'folder_name'   => $folderName,
            'schedule'      => $schedule,
            'retention_count' => $retention,
            'enabled'       => true,
        ]));
        flash('✅ Credentials saved. Now click "Authorize Google Drive" to connect.', 'success');
        redirect('?page=dashboard&tab=backup');
    }

    if ($gact === 'exchange_code') {
        $redirectUrl = trim($_POST['gdrive_redirect_url'] ?? '');
        if (!$redirectUrl) { flash('Paste the full redirect URL from Google.', 'danger'); redirect('?page=dashboard&tab=backup'); }
        $result = $_gdrive->exchangeCode($redirectUrl);
        if ($result['ok']) {
            flash('✅ Google Drive authorized! Backups will start at 6 AM Juba time.', 'success');
        } else {
            flash('Authorization failed: ' . ($result['error'] ?? 'unknown'), 'danger');
        }
        redirect('?page=dashboard&tab=backup');
    }

    if ($gact === 'run_now') {
        $result = $_gdrive->runBackup();
        if ($result['ok']) {
            flash('✅ Backup uploaded to Google Drive: ' . ($result['file'] ?? '') . ' (' . ($result['size_kb'] ?? '?') . ' KB)', 'success');
        } else {
            flash('Backup failed: ' . ($result['error'] ?? 'unknown'), 'danger');
        }
        redirect('?page=dashboard&tab=backup');
    }

    if ($gact === 'disconnect') {
        $_gdrive->disconnect();
        flash('Google Drive disconnected.', 'success');
        redirect('?page=dashboard&tab=backup');
    }
}

// Reload config after possible save
$_gdrive       = new GoogleDriveBackup($dataDir);
$_gdriveConfig = $_gdrive->getConfig();
$_gIsConfigured = $_gdrive->isConfigured();
$_gIsAuthorized = $_gdrive->isAuthorized();
$_gLogs        = $_gdrive->getRecentLogs(15);
$_gLastBackup  = $_gdriveConfig['last_backup'] ?? null;
$_gStatus      = $_gdrive->getStatus();

    // Gather backup file info
    $backupDataFiles = [
        'kyc_applications.json'         => 'KYC Applications',
        'retailers.json'                => 'Retailer Accounts',
        'passbook.json'                 => 'Wallet Passbook',
        'kyc_config.json'               => 'Plugin Settings',
        'kyc_devices.json'              => 'Devices / Hardware',
        'kyc_packages.json'             => 'Packages List',
        'wallet_recharge_requests.json' => 'Recharge Requests',
        'activity_log.json'             => 'Activity Log',
        'payment_collections.json'      => 'Payment Collections',
        'cash_expenses.json'            => 'Cash Expenses',
        'cash_handovers.json'           => 'Cash Handovers',
        'cashbook_meta.json'            => 'Cashbook Settings',
        'cashbook_entries.json'         => 'Cashbook Ledger (USD+SSP)',
        'login_sessions.json'           => 'Login Sessions',
        'support_tickets.json'          => 'Support Tickets',
        'leads.json'                    => 'Sales Leads',
        'subscription_plans.json'       => 'Subscription Plans',
        'sim_cards.json'                => 'SIM Inventory',
        'sim_movements.json'            => 'SIM Movements',
        'crm_queue.json'               => 'CRM Sync Queue',
    ];
    $totalSize = 0;
    foreach ($backupDataFiles as $filename => $label) {
        $fp = $dataDir . '/' . $filename;
        if (file_exists($fp)) $totalSize += filesize($fp);
    }
    // Count existing auto-backup zips in data/
    $autoBackups = glob($dataDir . '/pre-restore-auto-backup-*.zip') ?: [];
    usort($autoBackups, fn($a, $b) => filemtime($b) - filemtime($a));
?>
<!-- ══════════════════════════════════════════════════════
     BACKUP & RESTORE
══════════════════════════════════════════════════════ -->
<style>
.backup-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;}
@media(max-width:768px){.backup-grid{grid-template-columns:1fr;}}
.backup-card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden;}
.backup-card-head{padding:14px 20px;font-weight:700;font-size:14px;display:flex;align-items:center;gap:9px;letter-spacing:.1px;}
.backup-card-body{padding:20px;}
.file-list{margin:0;padding:0;list-style:none;}
.file-list li{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f1f3f5;font-size:13px;}
.file-list li:last-child{border-bottom:none;}
.file-list .fname{color:#2c3e50;font-weight:600;}
.file-list .fmeta{color:#aaa;font-size:11px;font-family:monospace;}
.file-exists{color:#28a745;}
.file-missing{color:#ccc;}
.restore-zone{border:2.5px dashed #2196F3;border-radius:12px;padding:32px 20px;text-align:center;background:#f0fdfc;cursor:pointer;transition:all .25s;position:relative;}
.restore-zone:hover,.restore-zone.drag-over{background:#d4f5f1;border-color:#1976D2;}
.restore-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.restore-zone .rz-icon{font-size:44px;line-height:1;margin-bottom:10px;}
.restore-zone .rz-title{font-size:15px;font-weight:700;color:#2c3e50;margin-bottom:4px;}
.restore-zone .rz-sub{font-size:12px;color:#888;}
.restore-zone .rz-file{font-size:13px;font-weight:700;color:#D41C1C;margin-top:8px;display:none;}
.mode-toggle{display:flex;gap:10px;margin-bottom:16px;}
.mode-btn{flex:1;padding:12px 8px;border-radius:10px;border:2px solid #dee2e6;background:#fff;font-size:13px;font-weight:600;cursor:pointer;text-align:center;transition:.2s;color:#555;}
.mode-btn.active{border-color:#D41C1C;background:#f0fdfc;color:#1976D2;}
.mode-btn input{display:none;}
.mode-btn .mode-icon{font-size:22px;display:block;margin-bottom:4px;}
.warn-box{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 14px;font-size:12px;color:#856404;margin-top:12px;display:none;}
.warn-box.show{display:flex;gap:8px;align-items:flex-start;}
.auto-backup-list{max-height:180px;overflow-y:auto;}
.auto-backup-item{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f1f3f5;font-size:12px;}
.auto-backup-item:last-child{border-bottom:none;}
.btn-dl-auto{padding:3px 12px;border-radius:6px;background:var(--primary);color:#fff;border:none;font-size:11px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap;}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
    <div>
        <h4 style="margin:0;font-weight:800;font-size:18px;">💾 Backup &amp; Restore</h4>
        <p style="margin:4px 0 0;color:#888;font-size:13px;">Download a full backup of all plugin data, or restore from a previous backup ZIP.</p>
    </div>
    <span style="background:#e9f7f5;color:#D41C1C;font-size:12px;font-weight:700;padding:5px 14px;border-radius:20px;">
        Total data size: <?= number_format($totalSize / 1024, 1) ?> KB
    </span>
</div>

<div class="backup-grid">

    <!-- ── LEFT: DOWNLOAD BACKUP ───────────────────────────────────────── -->
    <div class="backup-card">
        <div class="backup-card-head" style="background:linear-gradient(135deg,#2196F3,#0052cc);color:#fff;">
            <span>📥</span> Download Backup
        </div>
        <div class="backup-card-body">
            <p style="font-size:13px;color:#555;margin-bottom:14px;">
                Creates a <strong>.zip</strong> file containing all your plugin data. Store it somewhere safe — you can use it to restore data on any installation.
            </p>

            <ul class="file-list" style="margin-bottom:18px;">
                <?php foreach ($backupDataFiles as $filename => $label):
                    $fp = $dataDir . '/' . $filename;
                    $exists = file_exists($fp);
                    $count  = 0;
                    if ($exists) {
                        $d = json_decode(file_get_contents($fp), true);
                        $count = is_array($d) ? (isset($d[0]) ? count($d) : 1) : 0;
                    }
                ?>
                <li>
                    <span class="fname <?= $exists ? 'file-exists' : 'file-missing' ?>">
                        <?= $exists ? '✓' : '○' ?> <?= h($label) ?>
                    </span>
                    <span class="fmeta">
                        <?php if ($exists): ?>
                            <?= $count > 1 ? $count . ' records' : 'config' ?>
                            · <?= number_format(filesize($fp) / 1024, 1) ?> KB
                        <?php else: ?>
                            not yet created
                        <?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
                <li>
                    <span class="fname file-exists">📁 Uploads folder (proof images)</span>
                    <span class="fmeta">
                        <?php
                            $uploadFiles = is_dir($dataDir.'/uploads') ? count(glob($dataDir.'/uploads/*')) : 0;
                            echo $uploadFiles . ' file' . ($uploadFiles !== 1 ? 's' : '');
                        ?>
                    </span>
                </li>
            </ul>

            <a href="?page=dashboard&action=download_backup"
               class="btn btn-block"
               style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;background:linear-gradient(135deg,#2196F3,#0052cc);color:#fff;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;border:none;cursor:pointer;">
                <span style="font-size:20px;">⬇️</span>
                Download Full Backup
                <span style="font-size:11px;opacity:.85;font-weight:500;">(kyc-backup-<?= date('Y-m-d') ?>.zip)</span>
            </a>

            <p style="font-size:11px;color:#aaa;text-align:center;margin-top:10px;">
                Includes all JSON data + uploaded proof files
            </p>

            <?php if (!empty($autoBackups)): ?>
            <div style="margin-top:18px;border-top:2px solid #f1f3f5;padding-top:14px;">
                <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:8px;">
                    🗄️ Auto-Backups (created before restores)
                </div>
                <div class="auto-backup-list">
                    <?php foreach ($autoBackups as $abPath):
                        $abName = basename($abPath);
                        $abDate = date('M j Y, H:i', filemtime($abPath));
                        $abSize = number_format(filesize($abPath) / 1024, 1);
                    ?>
                    <div class="auto-backup-item">
                        <span style="color:#555;">📦 <?= h(str_replace('pre-restore-auto-backup-', '', str_replace('.zip', '', $abName))) ?></span>
                        <span style="color:#aaa;font-size:10px;"><?= $abSize ?> KB</span>
                        <a href="?page=dashboard&action=download_auto_backup&file=<?= urlencode($abName) ?>"
                           class="btn-dl-auto">⬇ Download</a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── RIGHT: RESTORE BACKUP ──────────────────────────────────────── -->
    <div class="backup-card">
        <div class="backup-card-head" style="background:linear-gradient(135deg,#f39c12,var(--primary));color:#fff;">
            <span>📤</span> Restore from Backup
        </div>
        <div class="backup-card-body">
            <p style="font-size:13px;color:#555;margin-bottom:14px;">
                Upload a backup ZIP to restore your data. Accepts: <strong>plugin backups</strong>, <strong>Google Drive DATA zips</strong>, or <strong>pre-restore auto-backups</strong>.<br>
                <em>Merge</em> = adds missing JSON records, skips SQLite. <em>Overwrite</em> = full replace including SQLite databases.
            </p>

            <form method="POST" enctype="multipart/form-data" id="restoreForm" onsubmit="return confirm('WARNING: This will overwrite ALL current data.\nAre you absolutely sure?')">
            <?= csrfField() ?>
                <input type="hidden" name="action" value="restore_backup">

                <!-- Restore Mode -->
                <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:8px;">Restore Mode:</div>
                <div class="mode-toggle">
                    <label class="mode-btn active" id="modeMergeLbl" onclick="setMode('merge')">
                        <input type="radio" name="restore_mode" value="merge" checked>
                        <span class="mode-icon">🔀</span>
                        <strong>Merge</strong>
                        <div style="font-size:11px;font-weight:400;color:#777;margin-top:3px;">Adds missing records.<br>Keeps existing data safe.</div>
                    </label>
                    <label class="mode-btn" id="modeOverwriteLbl" onclick="setMode('overwrite')">
                        <input type="radio" name="restore_mode" value="overwrite">
                        <span class="mode-icon">🔄</span>
                        <strong>Overwrite</strong>
                        <div style="font-size:11px;font-weight:400;color:#777;margin-top:3px;">Replaces all data + SQLite.<br>Current data auto-backed up.</div>
                    </label>
                </div>

                <div class="warn-box" id="overwriteWarn">
                    <span style="font-size:16px;">⚠️</span>
                    <span><strong>Overwrite mode</strong> replaces all JSON data AND SQLite databases (cashbook, ledger, HRM, stock, WA). Your current data will be automatically saved as a pre-restore backup first.</span>
                </div>

                <!-- Drop Zone -->
                <div class="restore-zone" id="restoreZone" style="margin-top:14px;">
                    <input type="file" name="backup_zip" accept=".zip" required
                           onchange="onFileSelect(this)" id="backupFileInput">
                    <div class="rz-icon">📂</div>
                    <div class="rz-title">Click or drag &amp; drop your backup ZIP</div>
                    <div class="rz-sub">Accepts: plugin backup, Google Drive DATA zip, or pre-restore backup</div>
                    <div class="rz-file" id="selectedFileName"></div>
                </div>

                <!-- File Info Preview -->
                <div id="fileInfoBox" style="display:none;margin-top:10px;background:#f8f9fa;border-radius:8px;padding:10px 14px;font-size:12px;color:#555;border:1px solid #dee2e6;">
                    <div style="display:flex;justify-content:space-between;">
                        <span id="fileInfoName" style="font-weight:700;"></span>
                        <span id="fileInfoSize" style="color:#888;"></span>
                    </div>
                    <div id="fileInfoNote" style="color:#D41C1C;margin-top:3px;font-weight:600;"></div>
                </div>

                <button type="submit" class="btn btn-block" id="restoreBtn"
                        style="display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;background:linear-gradient(135deg,#f39c12,var(--primary));color:#fff;border-radius:10px;font-weight:700;font-size:15px;margin-top:14px;border:none;cursor:pointer;width:100%;"
                        onclick="return confirmRestore()">
                    <span style="font-size:18px;">🔁</span>
                    Restore Backup
                </button>
            </form>

            <div style="margin-top:18px;border-top:2px solid #f1f3f5;padding-top:12px;">
                <div style="font-size:11px;color:#aaa;line-height:1.8;">
                    <div>✅ <strong>Safe by design</strong> — your current data is auto-backed up before any restore</div>
                    <div>✅ <strong>Accepts all formats</strong> — plugin backup, Google Drive DATA zip, or pre-restore backup</div>
                    <div>✅ <strong>Full restore</strong> — JSON data, SQLite databases (overwrite mode), uploads, and UCRM export</div>
                    <div>✅ <strong>SQLite validated</strong> — integrity check before replacing live database</div>
                    <div>✅ <strong>Logged</strong> — every restore is recorded in the Activity Log</div>
                </div>
            </div>

<?php if (!empty($_gdrive) && $_gdrive->isAuthorized()): ?>
<?php
    $gConf = $_gdrive->getConfig();
    $lastDataFile = $gConf['last_backup']['data_file'] ?? '';
    $lastDataFileId = $gConf['last_backup']['data_file_id'] ?? '';
    $lastDataSizeKb = $gConf['last_backup']['data_size_kb'] ?? 0;
    $lastBackupTime = $gConf['last_backup']['time'] ?? '';
?>
<?php if ($lastDataFileId): ?>
            <div style="margin-top:14px;border-top:2px solid #f1f3f5;padding-top:14px;">
                <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:8px;">☁️ Restore from Google Drive</div>
                <div style="font-size:12px;color:#777;margin-bottom:10px;">
                    Last backup: <strong><?= h($lastDataFile) ?></strong><br>
                    <?= h($lastBackupTime) ?> · <?= number_format($lastDataSizeKb) ?> KB
                </div>
                <form method="POST" onsubmit="return confirm('This will download the latest DATA backup from Google Drive and OVERWRITE all current plugin data (JSON + SQLite).\n\nYour current data will be auto-backed up first.\n\nContinue?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="restore_from_gdrive">
                    <button type="submit" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px;background:linear-gradient(135deg,#4285F4,#34A853);color:#fff;border-radius:10px;font-weight:700;font-size:14px;border:none;cursor:pointer;width:100%;">
                        <span style="font-size:16px;">☁️</span>
                        Restore Latest from Google Drive
                    </button>
                </form>
            </div>
<?php endif; ?>
<?php endif; ?>
        </div>
    </div>
</div>


<!-- ── AUTO-BACKUP SCHEDULE (Starlink Finance pattern) ────────────────── -->
<?php
// Handle save_backup_settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_backup_settings') {
    $bNew = [
        'auto_backup_freq'          => $_POST['auto_backup_freq'] ?? '3x_daily',
        'low_wallet_alert_threshold'=> (float)($_POST['low_wallet_threshold'] ?? 50),
    ];
    $store->save('backup_settings.json', $bNew);
    logActivity($dataDir, 'settings_saved', 'Backup schedule settings updated', 'Frequency: '.$bNew['auto_backup_freq']);
    flash('⏰ Backup schedule saved.', 'success'); redirect('?page=dashboard&tab=backup');
}
$bSettings = $store->load('backup_settings.json');
$cronAutoBackups = array_reverse(glob($dataDir.'/backups/auto-backup-*.zip') ?: []);
$lastMeta = $store->load('last_backup_meta.json');
?>
<div class="kyc-card" style="margin-bottom:1.5rem;">
    <div class="kyc-card-header" style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;">
        <i class="bi bi-clock-history"></i> Automatic Backup Schedule
    </div>
    <div class="kyc-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap;">
            <div>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_backup_settings">
                    <div class="form-group">
                        <label class="form-label"><strong>Auto-backup frequency</strong></label>
                        <select name="auto_backup_freq" class="form-control">
                            <?php foreach(['hourly'=>'Hourly','3x_daily'=>'3x Daily (08:00, 14:00, 20:00)','2x_daily'=>'2x Daily (08:00, 20:00)','daily'=>'Once Daily (08:00)','disabled'=>'Disabled'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= ($bSettings['auto_backup_freq']??'3x_daily')===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Backups are created by the UCRM cron (main.php). Keeps last 14 auto-backups.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Low wallet alert threshold ($)</label>
                        <input type="number" name="low_wallet_threshold" class="form-control" min="0" step="5"
                               value="<?= h((string)($bSettings['low_wallet_alert_threshold']??50)) ?>">
                        <small class="text-muted">Agents below this balance are flagged in the heartbeat log.</small>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Schedule</button>
                </form>
            </div>
            <div>
                <div style="font-size:12px;font-weight:700;color:#555;margin-bottom:10px;">🗄️ Scheduled Auto-Backups (last 14)</div>
                <?php if (!$lastMeta): ?>
                <div style="font-size:12px;color:#aaa;padding:20px;text-align:center;">No auto-backups yet — UCRM cron will create them automatically.</div>
                <?php else: ?>
                <div style="font-size:12px;color:#6b7280;margin-bottom:8px;">Last backup: <strong><?= h($lastMeta['file']??'—') ?></strong> at <?= h($lastMeta['day']??'—') ?> <?= h(str_pad((string)($lastMeta['hour']??0),2,'0',STR_PAD_LEFT)).':00' ?></div>
                <?php endif; ?>
                <?php if ($cronAutoBackups): ?>
                <div style="max-height:200px;overflow-y:auto;">
                    <?php foreach (array_slice($cronAutoBackups,0,14) as $ab):
                        $abName = basename($ab); $abKB = round(filesize($ab)/1024);
                        $abDate = date('M j H:i', filemtime($ab)); ?>
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px;">
                        <span style="color:#374151;">📦 <?= h($abDate) ?></span>
                        <span style="color:#9ca3af;"><?= $abKB ?> KB</span>
                        <a href="?page=dashboard&action=download_auto_backup&file=<?= urlencode($abName) ?>"
                           style="color:#2563EB;font-weight:600;">⬇ Get</a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div style="font-size:12px;color:#aaa;padding:10px 0;">No scheduled backups yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── HOW IT WORKS guide ─────────────────────────────────────────────── -->
<div class="kyc-card" style="margin-bottom:0;">
    <div class="kyc-card-header"><i class="bi bi-info-circle-fill"></i> How Backup &amp; Restore Works</div>
    <div class="kyc-card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
            <div style="background:#f0fdfc;border-radius:10px;padding:14px;">
                <div style="font-size:22px;margin-bottom:6px;">1️⃣</div>
                <div style="font-weight:700;font-size:13px;margin-bottom:4px;">Download Backup</div>
                <div style="font-size:12px;color:#555;">Click <em>Download Full Backup</em>. A <code>.zip</code> file is generated instantly with all your JSON data and uploaded files.</div>
            </div>
            <div style="background:#fff3cd;border-radius:10px;padding:14px;">
                <div style="font-size:22px;margin-bottom:6px;">2️⃣</div>
                <div style="font-weight:700;font-size:13px;margin-bottom:4px;">Store It Safely</div>
                <div style="font-size:12px;color:#555;">Save the backup to your local drive, Google Drive, or any secure location. We recommend doing this regularly.</div>
            </div>
            <div style="background:#fdecea;border-radius:10px;padding:14px;">
                <div style="font-size:22px;margin-bottom:6px;">3️⃣</div>
                <div style="font-weight:700;font-size:13px;margin-bottom:4px;">Restore When Needed</div>
                <div style="font-size:12px;color:#555;">Upload your <code>.zip</code> on any KYC plugin installation. Current data is <strong>auto-backed up</strong> first — you can always go back.</div>
            </div>
            <div style="background:#e8f5e9;border-radius:10px;padding:14px;">
                <div style="font-size:22px;margin-bottom:6px;">4️⃣</div>
                <div style="font-weight:700;font-size:13px;margin-bottom:4px;">Migration Ready</div>
                <div style="font-size:12px;color:#555;">Moving to a new server? Download backup → install plugin fresh → restore backup. All your retailers, applications, and wallet data return instantly.</div>
            </div>
        </div>
    </div>
</div>

<script>

// ── Backup & Restore JS ──────────────────────────────────────────────────────
function setMode(mode) {
    document.querySelector('input[name=restore_mode][value='+mode+']').checked = true;
    document.getElementById('modeMergeLbl').classList.toggle('active', mode === 'merge');
    document.getElementById('modeOverwriteLbl').classList.toggle('active', mode === 'overwrite');
    const warn = document.getElementById('overwriteWarn');
    if (warn) warn.classList.toggle('show', mode === 'overwrite');
}

function onFileSelect(input) {
    const file = input.files[0];
    const nameEl = document.getElementById('selectedFileName');
    const infoBox = document.getElementById('fileInfoBox');
    const infoName = document.getElementById('fileInfoName');
    const infoSize = document.getElementById('fileInfoSize');
    const infoNote = document.getElementById('fileInfoNote');
    const zone = document.getElementById('restoreZone');

    if (file) {
        if (nameEl) { nameEl.textContent = '📦 ' + file.name; nameEl.style.display = 'block'; }
        if (infoBox) {
            infoName.textContent = file.name;
            infoSize.textContent = (file.size / 1024).toFixed(1) + ' KB';
            const isValid = file.name.includes('kyc-backup') || file.name.includes('pre-restore');
            infoNote.textContent = isValid ? '✅ Looks like a valid KYC backup' : '⚠️ Filename not recognised — make sure this is a KYC backup';
            infoNote.style.color = isValid ? '#28a745' : '#e67e22';
            infoBox.style.display = 'block';
        }
        if (zone) zone.style.borderColor = '#28a745';
    } else {
        if (nameEl) nameEl.style.display = 'none';
        if (infoBox) infoBox.style.display = 'none';
        if (zone) zone.style.borderColor = '#2196F3';
    }
}

function confirmRestore() {
    const file = document.getElementById('backupFileInput');
    if (!file || !file.files.length) { alert('Please select a backup ZIP file first.'); return false; }
    const mode = document.querySelector('input[name=restore_mode]:checked')?.value || 'merge';
    const msg = mode === 'overwrite'
        ? '⚠️ OVERWRITE MODE\n\nThis will replace ALL current data with the backup.\nYour current data will be auto-backed up first.\n\nContinue?'
        : '🔀 MERGE MODE\n\nThis will add any missing records from the backup.\nExisting data will not be deleted.\n\nContinue?';
    return confirm(msg);
}

// Drag & drop for restore zone
(function() {
    const zone = document.getElementById('restoreZone');
    if (!zone) return;
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('drag-over'); });
    zone.addEventListener('drop', function(e) {
        e.preventDefault();
        zone.classList.remove('drag-over');
        const files = e.dataTransfer.files;
        if (files.length) {
            const input = document.getElementById('backupFileInput');
            if (input) {
                // Create a new DataTransfer to assign files
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                input.files = dt.files;
                onFileSelect(input);
            }
        }
    });
})();
</script>


<!-- ══════════════════════════════════════════════════════
     GOOGLE DRIVE AUTO-BACKUP
══════════════════════════════════════════════════════ -->
<div style="margin-top:28px;">
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
    <div style="font-size:28px;">☁️</div>
    <div>
        <div style="font-size:16px;font-weight:800;color:#1e293b;">Google Drive Auto-Backup</div>
        <div style="font-size:12px;color:#64748b;margin-top:2px;">Daily encrypted ZIP of all plugin data uploaded to your Google Drive</div>
    </div>
    <?php if ($_gIsAuthorized): ?>
    <span style="margin-left:auto;background:#dcfce7;color:#166534;font-size:11px;font-weight:800;padding:4px 12px;border-radius:20px;">✅ CONNECTED</span>
    <?php elseif ($_gIsConfigured): ?>
    <span style="margin-left:auto;background:#fef3c7;color:#92400e;font-size:11px;font-weight:800;padding:4px 12px;border-radius:20px;">⚠️ NEEDS AUTH</span>
    <?php else: ?>
    <span style="margin-left:auto;background:#f1f5f9;color:#64748b;font-size:11px;font-weight:800;padding:4px 12px;border-radius:20px;">NOT SET UP</span>
    <?php endif; ?>
</div>

<?php if ($_gIsAuthorized): ?>
<!-- ── AUTHORIZED STATE ─────────────────────────────────────────────────── -->
<div class="backup-grid" style="grid-template-columns:1fr 1fr;">

    <!-- Status card -->
    <div class="backup-card">
        <div class="backup-card-head" style="background:#f0fdf4;">☁️ Backup Status</div>
        <div class="backup-card-body">
            <?php if ($_gLastBackup): ?>
            <div style="font-size:13px;margin-bottom:8px;">
                <span style="font-weight:700;">Last backup:</span><br>
                <span style="color:#166534;font-weight:800;"><?= htmlspecialchars($_gLastBackup['time'] ?? '') ?></span><br>
                <span style="font-size:11px;color:#64748b;"><?= htmlspecialchars($_gLastBackup['file'] ?? '') ?> — <?= htmlspecialchars($_gLastBackup['size'] ?? '') ?></span>
            </div>
            <?php else: ?>
            <div style="font-size:12px;color:#64748b;margin-bottom:12px;">No backups yet — will run at 6 AM Juba time or click Run Now.</div>
            <?php endif; ?>
            <div style="font-size:12px;color:#374151;margin-bottom:12px;">
                <div>📂 Folder: <strong><?= htmlspecialchars($_gdriveConfig['folder_name'] ?? 'DishNet Backups') ?></strong></div>
                <div>🗓 Schedule: <strong><?= htmlspecialchars($_gdriveConfig['schedule'] ?? 'daily') ?></strong></div>
                <div>🗂 Keep last: <strong><?= (int)($_gdriveConfig['retention_count'] ?? 7) ?> backups</strong></div>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="gdrive_action" value="run_now">
                <button type="submit" style="background:#1a73e8;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-size:12px;font-weight:700;cursor:pointer;width:100%;margin-bottom:8px;">⬆️ Run Backup Now</button>
            </form>
            <form method="POST" onsubmit="return confirm('Disconnect Google Drive? Scheduled backups will stop.')">
                <?= csrfField() ?>
                <input type="hidden" name="gdrive_action" value="disconnect">
                <button type="submit" style="background:#fff;color:#dc2626;border:1.5px solid #fecaca;border-radius:8px;padding:7px 18px;font-size:11px;font-weight:700;cursor:pointer;width:100%;">Disconnect</button>
            </form>
        </div>
    </div>

    <!-- Google Drive Status Banner -->
    <?php
    $gStatusColor = '#dcfce7'; $gStatusText = '#166534'; $gStatusIcon = '✅';
    $gStatusMsg = 'Google Drive backup is active.';
    if (!$_gStatus['authorized']) {
        $gStatusColor = '#fef3c7'; $gStatusText = '#92400e'; $gStatusIcon = '⚠️';
        $gStatusMsg = 'Google Drive not authorized. Go to Admin → Backup → authorize.';
    } elseif (!$_gStatus['enabled']) {
        $gStatusColor = '#f1f5f9'; $gStatusText = '#475569'; $gStatusIcon = '⏸️';
        $gStatusMsg = 'Google Drive backup is disabled.';
    } elseif ($_gStatus['last_error']) {
        $gStatusColor = '#fee2e2'; $gStatusText = '#991b1b'; $gStatusIcon = '❌';
        $gStatusMsg = 'Last error: ' . htmlspecialchars($_gStatus['last_error']['msg'] ?? '');
    } elseif (!$_gStatus['last_backup']) {
        $gStatusColor = '#eff6ff'; $gStatusText = '#1e40af'; $gStatusIcon = '🕐';
        $gStatusMsg = 'Authorized but no backup run yet — next run at 06:00 Juba time.';
    } elseif ($_gStatus['last_run_ago']) {
        $gStatusMsg = 'Last backup: ' . htmlspecialchars($_gStatus['last_backup']['file'] ?? '') . ' — ' . $_gStatus['last_run_ago'];
    }
    ?>
    <div style="background:<?= $gStatusColor ?>;border-radius:10px;padding:10px 16px;margin-bottom:10px;display:flex;align-items:center;gap:10px;font-size:12px;color:<?= $gStatusText ?>;">
        <span style="font-size:16px;"><?= $gStatusIcon ?></span>
        <span><?= $gStatusMsg ?></span>
        <?php if ($_gStatus['last_backup']): ?>
        <span style="margin-left:auto;font-size:11px;opacity:0.7;">
            <?= number_format($_gStatus['last_backup']['size_kb'] ?? 0) ?> KB &nbsp;|&nbsp;
            <?= htmlspecialchars($_gStatus['last_backup']['version'] ?? '') ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- Recent log card -->
    <div class="backup-card">
        <div class="backup-card-head" style="background:#f8fafc;display:flex;justify-content:space-between;align-items:center;">
            <span>📋 Recent Activity</span>
            <?php if ($_gStatus['log_size_kb'] > 0): ?>
            <span style="font-size:10px;font-weight:400;color:#94a3b8;">Log: <?= $_gStatus['log_size_kb'] ?> KB</span>
            <?php endif; ?>
        </div>
        <div class="backup-card-body" style="padding:0;max-height:320px;overflow-y:auto;">
            <?php if (empty($_gLogs)): ?>
            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">
                <?php if (!$_gStatus['log_exists']): ?>
                No log file yet — backup hasn't run. Check cron is active via <strong>cron_status</strong>.
                <?php else: ?>
                Log file exists but is empty.
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php foreach ($_gLogs as $glog): ?>
            <?php
                $rowColor  = 'transparent';
                $textColor = '#374151';
                if (($glog['level'] ?? '') === 'error') { $rowColor = '#fff1f2'; $textColor = '#991b1b'; }
                elseif (($glog['level'] ?? '') === 'ok') { $textColor = '#166534'; }
            ?>
            <div style="padding:7px 16px;border-bottom:1px solid #f1f5f9;font-size:11px;background:<?= $rowColor ?>;display:flex;gap:10px;">
                <span style="color:#94a3b8;white-space:nowrap;flex-shrink:0;"><?= substr($glog['time'] ?? '', 0, 16) ?></span>
                <span style="color:<?= $textColor ?>;"><?= htmlspecialchars($glog['msg'] ?? '') ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($_gIsConfigured): ?>
<!-- ── CONFIGURED BUT NEEDS AUTH ───────────────────────────────────────── -->
<div class="backup-card" style="max-width:600px;">
    <div class="backup-card-head" style="background:#fef3c7;">⚠️ Step 2 — Authorize Google Drive</div>
    <div class="backup-card-body">
        <p style="font-size:13px;color:#374151;margin-bottom:16px;">Credentials saved. Now you need to grant access to your Google Drive.</p>

        <!-- Step 2a: Open auth URL -->
        <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:16px;">
            <div style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:8px;">1. Open this link in your browser and authorize:</div>
            <a href="<?= htmlspecialchars($_gdrive->getAuthUrl()) ?>" target="_blank"
               style="display:block;background:#1a73e8;color:#fff;border-radius:8px;padding:10px 16px;font-size:12px;font-weight:700;text-decoration:none;text-align:center;">
                🔑 Open Google Authorization Page →
            </a>
        </div>

        <!-- Step 2b: Paste redirect URL -->
        <div style="background:#f8fafc;border-radius:8px;padding:12px;">
            <div style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:4px;">2. After authorizing, you'll see a "page not found" — copy the <strong>full URL</strong> from your browser and paste it here:</div>
            <div style="font-size:11px;color:#64748b;margin-bottom:10px;">The URL will look like: <code>http://localhost/?code=4/0AX4XfWh...</code></div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="gdrive_action" value="exchange_code">
                <input type="text" name="gdrive_redirect_url" placeholder="http://localhost/?code=..." required
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:12px;box-sizing:border-box;margin-bottom:10px;">
                <button type="submit" style="background:#166534;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;width:100%;">✅ Complete Authorization</button>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── NOT CONFIGURED ──────────────────────────────────────────────────── -->
<div class="backup-grid" style="grid-template-columns:1fr 1fr;">

    <!-- Setup instructions -->
    <div class="backup-card">
        <div class="backup-card-head" style="background:#eff6ff;">📋 How to Set Up (5 min)</div>
        <div class="backup-card-body" style="font-size:12px;color:#374151;line-height:1.7;">
            <div style="margin-bottom:10px;"><strong>1.</strong> Go to <a href="https://console.cloud.google.com" target="_blank" style="color:#1a73e8;">console.cloud.google.com</a></div>
            <div style="margin-bottom:10px;"><strong>2.</strong> Create a new project (e.g. "DishNet Backup")</div>
            <div style="margin-bottom:10px;"><strong>3.</strong> Enable the <strong>Google Drive API</strong></div>
            <div style="margin-bottom:10px;"><strong>4.</strong> Go to <strong>Credentials → Create OAuth 2.0 Client</strong></div>
            <div style="margin-bottom:10px;"><strong>5.</strong> Type: <strong>Desktop App</strong></div>
            <div style="margin-bottom:10px;"><strong>6.</strong> Copy the Client ID and Client Secret</div>
            <div style="margin-bottom:10px;"><strong>7.</strong> Paste them in the form →</div>
            <div style="background:#fef3c7;border-radius:6px;padding:8px 10px;margin-top:8px;color:#92400e;font-size:11px;">
                💡 Your data never leaves your own Google account — the plugin uploads directly to your Drive.
            </div>
        </div>
    </div>

    <!-- Credentials form -->
    <div class="backup-card">
        <div class="backup-card-head" style="background:#f0fdf4;">Step 1 — Enter Credentials</div>
        <div class="backup-card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="gdrive_action" value="save_credentials">

                <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Client ID</label>
                <input type="text" name="gdrive_client_id" placeholder="123456789-xxx.apps.googleusercontent.com" required
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:12px;box-sizing:border-box;margin-bottom:10px;">

                <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Client Secret</label>
                <input type="password" name="gdrive_client_secret" placeholder="GOCSPX-..." required
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:12px;box-sizing:border-box;margin-bottom:10px;">

                <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Drive Folder Name</label>
                <input type="text" name="gdrive_folder_name" value="DishNet Backups" required
                       style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:12px;box-sizing:border-box;margin-bottom:10px;">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
                    <div>
                        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Schedule</label>
                        <select name="gdrive_schedule" style="width:100%;padding:9px;border:1.5px solid #d1d5db;border-radius:8px;font-size:12px;">
                            <option value="daily">Daily (6 AM)</option>
                            <option value="twice_daily">Twice daily (6 AM &amp; 6 PM)</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Keep backups</label>
                        <select name="gdrive_retention" style="width:100%;padding:9px;border:1.5px solid #d1d5db;border-radius:8px;font-size:12px;">
                            <option value="7" selected>Last 7</option>
                            <option value="14">Last 14</option>
                            <option value="30">Last 30</option>
                        </select>
                    </div>
                </div>

                <button type="submit" style="background:#1a73e8;color:#fff;border:none;border-radius:8px;padding:11px 20px;font-size:13px;font-weight:700;cursor:pointer;width:100%;">Save Credentials →</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
</div>
