<?php
// Tab: updater
// Extracted from public.php on 2026-03-15
// ══════════════════════════════════════════════════════════════════════════════
// UPDATER TAB — Safe Plugin Updates, Test Suite, Auto-Backup & Rollback
// ══════════════════════════════════════════════════════════════════════════════

// Handle run tests manually
if (($_GET['run_tests'] ?? '') === '1') {
    require_once dirname(__DIR__, 2) . '/lib/DishNetTestSuite.php';
    if (!function_exists('human_time_diff')) {} // already loaded
    $manualTest = new DishNetTestSuite($dataDir);
    $manualTestResult = $manualTest->run();
    file_put_contents($dataDir.'/last_test_run.json', json_encode(array_merge($manualTestResult, [
        'ran_at'    => date('Y-m-d H:i:s'),
        'triggered' => 'manual',
    ]), JSON_PRETTY_PRINT));
}

$curManifest   = json_decode(file_get_contents($GLOBALS['_PLUGIN_ROOT'].'/manifest.json'), true) ?? [];
$curVersion    = $curManifest['information']['version'] ?? 'unknown';
$backupDir     = $dataDir . '/plugin_backups';
$backups       = [];
if (is_dir($backupDir)) {
    foreach (glob($backupDir . '/backup_*.zip') as $bf) {
        $backups[] = ['file' => basename($bf), 'size' => round(filesize($bf)/1024/1024,1).'MB', 'mtime' => date('Y-m-d H:i:s', filemtime($bf))];
    }
    usort($backups, fn($a,$b) => strcmp($b['mtime'], $a['mtime']));
}
$updateLogFile = $dataDir . '/update_log.json';
$updateLog     = file_exists($updateLogFile) ? (json_decode(file_get_contents($updateLogFile), true) ?? []) : [];
?>
<style>
.upd-sv-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;}
.upd-sv-tab{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:#64748b;background:#f1f5f9;border:1px solid transparent;transition:.15s;}
.upd-sv-tab.active{background:#1e293b;color:#fff;}
.upd-sv-tab:hover:not(.active){background:#e2e8f0;text-decoration:none;color:#1e293b;}
.test-section-hdr{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;padding:10px 0 5px;border-bottom:1px solid #f1f5f9;margin:4px 0 6px;}
.test-row{display:flex;align-items:flex-start;gap:10px;padding:5px 0;border-bottom:1px solid #f8fafc;font-size:13px;}
.test-icon-pass{color:#16a34a;font-size:14px;flex-shrink:0;margin-top:1px;}
.test-icon-fail{color:#dc2626;font-size:14px;flex-shrink:0;margin-top:1px;}
.test-icon-skip{color:#94a3b8;font-size:14px;flex-shrink:0;margin-top:1px;}
.test-name-pass{color:#1e293b;}
.test-name-fail{color:#dc2626;font-weight:700;}
.test-name-skip{color:#94a3b8;}
.test-detail{font-size:11px;color:#dc2626;margin-top:2px;}
.upd-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:20px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.04);}
.upd-card h3{font-size:15px;font-weight:800;color:#1e293b;margin:0 0 14px;}
.upd-step{display:flex;align-items:flex-start;gap:12px;margin-bottom:10px;font-size:13px;color:#475569;}
.upd-step .num{width:24px;height:24px;border-radius:50%;background:#1e293b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;flex-shrink:0;}
.upd-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.upd-ver{background:#dbeafe;color:#1e40af;}
.upd-ok{background:#dcfce7;color:#166534;}
.upd-warn{background:#fef3c7;color:#92400e;}
.bk-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:#f8fafc;border-radius:10px;margin-bottom:6px;font-size:13px;}
.log-row{display:flex;gap:10px;padding:9px 12px;border-bottom:1px solid #f1f5f9;font-size:12px;align-items:flex-start;}
</style>

<div id="kyc-content" style="max-width:760px;">

<?php
$updSubView  = $_GET['wsv'] ?? 'update';
$lastTestRun = file_exists($dataDir.'/last_test_run.json') ? json_decode(file_get_contents($dataDir.'/last_test_run.json'), true) : null;
$testBadge   = '';
if ($lastTestRun) {
    $testBadge = $lastTestRun['ok'] ? '✅' : '⛔ '.$lastTestRun['failed'].' FAIL';
}
?>
<div class="upd-sv-tabs">
    <a href="?page=dashboard&tab=updater&wsv=update" class="upd-sv-tab <?= $updSubView==='update'?'active':'' ?>">
        ⬆️ Update
    </a>
    <a href="?page=dashboard&tab=updater&wsv=tests" class="upd-sv-tab <?= $updSubView==='tests'?'active':'' ?>">
        🧪 Test Results <?= $testBadge ?>
    </a>
    <a href="?page=dashboard&tab=updater&wsv=backups" class="upd-sv-tab <?= $updSubView==='backups'?'active':'' ?>">
        💾 Backups & Rollback
    </a>
    <a href="?page=dashboard&tab=updater&wsv=log" class="upd-sv-tab <?= $updSubView==='log'?'active':'' ?>">
        📋 History
    </a>
    <a href="?page=dashboard&tab=updater&run_tests=1&wsv=tests" class="upd-sv-tab" style="margin-left:auto;background:#1e40af;color:#fff;border-color:#1e40af;">
        ▶ Run Tests Now
    </a>
</div>

<?php if ($flash): ?>
<div style="padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:14px;font-weight:600;
  background:<?= $flash['type']==='success'?'#dcfce7':($flash['type']==='warning'?'#fef3c7':'#fee2e2') ?>;
  color:<?= $flash['type']==='success'?'#166534':($flash['type']==='warning'?'#92400e':'#dc2626') ?>;
  border:1px solid <?= $flash['type']==='success'?'#bbf7d0':($flash['type']==='warning'?'#fde68a':'#fecaca') ?>;">
  <?= h($flash['msg']) ?>
</div>
<?php endif; ?>

<?php if ($updSubView === 'tests'): ?>
<!-- ══ TEST RESULTS VIEW ══════════════════════════════════════════════════ -->
<div class="upd-card">
    <h3>🧪 Self-Test Results</h3>
    <?php if (!$lastTestRun): ?>
    <div style="text-align:center;padding:24px;color:#94a3b8;">
        <div style="font-size:2rem;margin-bottom:8px;">🧪</div>
        <div style="font-weight:700;margin-bottom:4px;">No test run yet</div>
        <div style="font-size:12px;margin-bottom:16px;">Tests run automatically before every update. Or run them manually:</div>
        <a href="?page=dashboard&tab=updater&run_tests=1&wsv=tests" style="background:#1e40af;color:#fff;border-radius:10px;padding:10px 20px;text-decoration:none;font-weight:700;font-size:13px;">▶ Run Tests Now</a>
    </div>
    <?php else: ?>
    <?php
    $trPassed  = $lastTestRun['passed'] ?? 0;
    $trFailed  = $lastTestRun['failed'] ?? 0;
    $trSkipped = $lastTestRun['skipped'] ?? 0;
    $trTotal   = $lastTestRun['total'] ?? 0;
    $trOk      = $lastTestRun['ok'] ?? false;
    ?>
    <!-- Summary bar -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
        <div style="background:<?= $trOk?'#dcfce7':'#fee2e2' ?>;border-radius:12px;padding:12px 18px;flex:1;text-align:center;border:1px solid <?= $trOk?'#bbf7d0':'#fecaca' ?>;">
            <div style="font-size:24px;font-weight:900;color:<?= $trOk?'#166534':'#dc2626' ?>;"><?= $trOk ? '✅ PASS' : '⛔ FAIL' ?></div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;"><?= $lastTestRun['ran_at'] ?? '' ?></div>
        </div>
        <div style="background:#f0fdf4;border-radius:12px;padding:12px 18px;text-align:center;border:1px solid #bbf7d0;">
            <div style="font-size:28px;font-weight:800;color:#16a34a;"><?= $trPassed ?></div>
            <div style="font-size:11px;color:#64748b;">Passed</div>
        </div>
        <div style="background:<?= $trFailed>0?'#fee2e2':'#f8fafc' ?>;border-radius:12px;padding:12px 18px;text-align:center;border:1px solid <?= $trFailed>0?'#fecaca':'#e2e8f0' ?>;">
            <div style="font-size:28px;font-weight:800;color:<?= $trFailed>0?'#dc2626':'#94a3b8' ?>;"><?= $trFailed ?></div>
            <div style="font-size:11px;color:#64748b;">Failed</div>
        </div>
        <div style="background:#f8fafc;border-radius:12px;padding:12px 18px;text-align:center;border:1px solid #e2e8f0;">
            <div style="font-size:28px;font-weight:800;color:#94a3b8;"><?= $trSkipped ?></div>
            <div style="font-size:11px;color:#64748b;">Skipped</div>
        </div>
    </div>

    <!-- Show failures first if any -->
    <?php if ($trFailed > 0): ?>
    <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:12px;padding:14px;margin-bottom:14px;">
        <div style="font-weight:800;color:#dc2626;margin-bottom:8px;">⛔ Failed Tests — Fix these before updating:</div>
        <?php foreach (($lastTestRun['results']??[]) as $tr):
            if ($tr['status'] !== 'fail') continue; ?>
        <div class="test-row" style="border-color:#fecaca;">
            <span class="test-icon-fail">✗</span>
            <div>
                <div class="test-name-fail"><?= h($tr['name']) ?></div>
                <?php if ($tr['detail']): ?><div class="test-detail"><?= h($tr['detail']) ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Full results (collapsible) -->
    <details <?= $trFailed===0?'open':'' ?>>
        <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#475569;padding:4px 0;user-select:none;">
            Full test log (<?= $trTotal ?> tests)
        </summary>
        <div style="margin-top:10px;">
        <?php foreach (($lastTestRun['results']??[]) as $tr): ?>
            <?php if ($tr['status']==='section'): ?>
            <div class="test-section-hdr"><?= h($tr['name']) ?></div>
            <?php elseif ($tr['status']==='pass'): ?>
            <div class="test-row">
                <span class="test-icon-pass">✓</span>
                <span class="test-name-pass"><?= h($tr['name']) ?></span>
            </div>
            <?php elseif ($tr['status']==='fail'): ?>
            <div class="test-row" style="background:#fff5f5;border-radius:6px;padding:6px 8px;margin:2px 0;">
                <span class="test-icon-fail">✗</span>
                <div>
                    <div class="test-name-fail"><?= h($tr['name']) ?></div>
                    <?php if ($tr['detail']): ?><div class="test-detail"><?= h($tr['detail']) ?></div><?php endif; ?>
                </div>
            </div>
            <?php elseif ($tr['status']==='skip'): ?>
            <div class="test-row">
                <span class="test-icon-skip">⊘</span>
                <span class="test-name-skip"><?= h($tr['name']) ?> <em style="font-size:11px;"><?= h($tr['detail']) ?></em></span>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </details>

    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
        <a href="?page=dashboard&tab=updater&run_tests=1&wsv=tests" style="background:#1e40af;color:#fff;border-radius:10px;padding:9px 18px;text-decoration:none;font-weight:700;font-size:13px;">🔄 Re-run Tests</a>
        <?php if (!$trOk): ?>
        <span style="font-size:12px;color:#dc2626;align-self:center;">Update is blocked until all tests pass.</span>
        <?php else: ?>
        <span style="font-size:12px;color:#16a34a;align-self:center;">✅ All tests pass — safe to update.</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($updSubView === 'backups'): ?>
<!-- ══ BACKUPS VIEW ═══════════════════════════════════════════════════════ -->
<div class="upd-card">
    <h3>💾 Backups & Rollback</h3>
    <?php if (empty($backups)): ?>
    <div style="text-align:center;padding:16px;color:#94a3b8;font-size:13px;">No backups yet. A backup is created automatically before every update.</div>
    <?php else: ?>
    <?php foreach ($backups as $bk): ?>
    <div class="bk-row">
        <span style="font-size:16px;">💾</span>
        <div style="flex:1;">
            <div style="font-weight:700;font-size:13px;color:#1e293b;"><?= h($bk['file']) ?></div>
            <div style="font-size:11px;color:#94a3b8;"><?= $bk['mtime'] ?> · <?= $bk['size'] ?></div>
        </div>
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="rollback">
            <input type="hidden" name="backup_file" value="<?= h($bk['file']) ?>">
            <button type="submit" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;"
                    onclick="return confirm('Roll back to <?= h($bk['file']) ?>?')">
                ↩️ Restore
            </button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php elseif ($updSubView === 'log'): ?>
<!-- ══ HISTORY VIEW ═══════════════════════════════════════════════════════ -->
<div class="upd-card">
    <h3>📋 Update History</h3>
    <?php if (empty($updateLog)): ?>
    <div style="color:#94a3b8;text-align:center;padding:16px;font-size:13px;">No updates applied yet.</div>
    <?php else: ?>
    <?php foreach ($updateLog as $entry): ?>
    <div class="log-row">
        <span style="font-size:18px;"><?= empty($entry['errors'])?'✅':'⚠️' ?></span>
        <div style="flex:1;">
            <div style="font-weight:700;">v<?= h($entry['from_version']??'?') ?> → v<?= h($entry['to_version']??'?') ?></div>
            <div style="color:#64748b;font-size:11px;"><?= h($entry['applied_at']??'') ?> · by <?= h($entry['applied_by']??'') ?></div>
            <div style="color:#94a3b8;font-size:11px;"><?= h($entry['zip_name']??'') ?></div>
            <?php if (!empty($entry['test_results'])): ?>
            <div style="font-size:11px;color:#16a34a;">🧪 Tests: <?= $entry['test_results']['passed']??0 ?> passed<?= ($entry['test_results']['failed']??0)>0 ? ', '.$entry['test_results']['failed'].' failed' : '' ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php else: // $updSubView === 'update' ?>
<!-- ══ UPDATE VIEW ════════════════════════════════════════════════════════ -->
<div style="padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:14px;font-weight:600;
  background:<?= $flash['type']==='success'?'#dcfce7':($flash['type']==='warning'?'#fef3c7':'#fee2e2') ?>;
  color:<?= $flash['type']==='success'?'#166534':($flash['type']==='warning'?'#92400e':'#dc2626') ?>;
  border:1px solid <?= $flash['type']==='success'?'#bbf7d0':($flash['type']==='warning'?'#fde68a':'#fecaca') ?>;">
  <?= h($flash['msg']) ?>
</div>
<?php endif; ?>

<!-- ── Current Version Card ── -->
<div class="upd-card">
    <h3>📦 Current Plugin Version</h3>
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <div>
            <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:2px;">Running Version</div>
            <span class="upd-badge upd-ver" style="font-size:14px;padding:5px 14px;">v<?= h($curVersion) ?></span>
        </div>
        <div style="flex:1;">
            <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:2px;">Plugin</div>
            <div style="font-size:13px;font-weight:600;color:#1e293b;"><?= h($curManifest['information']['displayName'] ?? 'DishNet Hybrid Telecom') ?></div>
        </div>
        <div>
            <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;margin-bottom:2px;">Backups stored</div>
            <span class="upd-badge <?= count($backups)>0?'upd-ok':'upd-warn' ?>"><?= count($backups) ?> backup<?= count($backups)!==1?'s':'' ?></span>
        </div>
    </div>
</div>

<!-- ── What gets preserved ── -->
<div class="upd-card" style="background:#fffbeb;border-color:#fde68a;">
    <h3>🔒 What Is ALWAYS Preserved During Updates</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
        <div style="display:flex;align-items:center;gap:6px;"><span style="color:#16a34a;font-size:16px;">✅</span> <span>All customer data (KYC, applications)</span></div>
        <div style="display:flex;align-items:center;gap:6px;"><span style="color:#16a34a;font-size:16px;">✅</span> <span>Wallet balances & passbook</span></div>
        <div style="display:flex;align-items:center;gap:6px;"><span style="color:#16a34a;font-size:16px;">✅</span> <span>All settings & API keys</span></div>
        <div style="display:flex;align-items:center;gap:6px;"><span style="color:#16a34a;font-size:16px;">✅</span> <span>Android APK (now stored in data/)</span></div>
        <div style="display:flex;align-items:center;gap=6px;"><span style="color:#16a34a;font-size:16px;">✅</span> <span>WhatsApp conversations & tickets</span></div>
        <div style="display:flex;align-items:center;gap:6px;"><span style="color:#16a34a;font-size:16px;">✅</span> <span>Login sessions & retailer accounts</span></div>
    </div>
    <div style="margin-top:10px;font-size:12px;color:#92400e;background:#fff;border-radius:8px;padding:8px 12px;">
        ⚠️ Everything in <code>data/</code> is <strong>never touched</strong> by an update. Only PHP, JS and HTML files are replaced.
    </div>
</div>

<!-- ── Upload new version ── -->
<div class="upd-card">
    <h3>🚀 Upload & Apply Update</h3>
    <div style="margin-bottom:14px;">
        <div class="upd-step"><div class="num">1</div><div>Updater <strong>automatically backs up</strong> the current plugin code to <code>data/plugin_backups/</code> before applying anything.</div></div>
        <div class="upd-step"><div class="num">2</div><div>The new ZIP is extracted over the plugin folder. The <code>data/</code> directory is <strong>never touched</strong>.</div></div>
        <div class="upd-step"><div class="num">3</div><div>If anything goes wrong, use the <strong>Rollback</strong> section below to instantly restore.</div></div>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="plugin_update">
        <div style="border:2px dashed #cbd5e1;border-radius:12px;padding:20px;text-align:center;margin-bottom:14px;cursor:pointer;"
             onclick="document.getElementById('upd-zip').click();" id="upd-drop">
            <div style="font-size:2rem;margin-bottom:6px;">📦</div>
            <div style="font-weight:700;color:#1e293b;margin-bottom:3px;" id="upd-filename">Click to select plugin ZIP</div>
            <div style="font-size:12px;color:#94a3b8;">e.g. dishnet-hybrid-v3_6_6-new-feature.zip</div>
        </div>
        <input type="file" name="update_zip" id="upd-zip" accept=".zip" required style="display:none;"
               onchange="document.getElementById('upd-filename').textContent = this.files[0]?.name || 'Click to select plugin ZIP';">
        <!-- Test status indicator -->
        <?php
        $lastTest = file_exists($dataDir.'/last_test_run.json') ? json_decode(file_get_contents($dataDir.'/last_test_run.json'), true) : null;
        if ($lastTest && !$lastTest['ok']): ?>
        <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:13px;color:#dc2626;font-weight:600;">
            ⛔ Last test run had <?= $lastTest['failed'] ?> failure(s) on <?= $lastTest['ran_at']??'' ?>.
            Update will be blocked. <a href="?page=dashboard&tab=updater&wsv=tests" style="color:#dc2626;">View failures →</a>
        </div>
        <?php elseif ($lastTest && $lastTest['ok']): ?>
        <div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:13px;color:#166534;font-weight:600;">
            ✅ Tests passed on <?= $lastTest['ran_at']??'' ?> (<?= $lastTest['passed'] ?> tests). Safe to update.
        </div>
        <?php else: ?>
        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:10px;font-size:13px;color:#92400e;font-weight:600;">
            ⚠️ No test run yet. Tests will run automatically before update is applied.
        </div>
        <?php endif; ?>

        <button type="submit" style="width:100%;background:#1e293b;color:#fff;border:none;border-radius:12px;padding:13px;font-size:15px;font-weight:800;cursor:pointer;"
                onclick="return confirm('This will:\n1. Run all self-tests\n2. If tests pass: backup current code\n3. Apply the update\n\nContinue?')">
            🧪 Test → Backup → Apply Update
        </button>
        <details style="margin-top:10px;">
            <summary style="font-size:11px;color:#94a3b8;cursor:pointer;user-select:none;">⚠️ Emergency: skip tests (not recommended)</summary>
            <div style="margin-top:8px;background:#fef3c7;border-radius:8px;padding:10px;font-size:12px;color:#92400e;">
                Only use this if you are absolutely sure the update is safe and tests are broken due to a known environment issue.
                <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-weight:700;cursor:pointer;">
                    <input type="checkbox" name="skip_tests" value="1" style="accent-color:#d97706;">
                    I understand the risks — skip tests and apply directly
                </label>
            </div>
        </details>
    </form>
</div>

<!-- ── Backups & Rollback ── -->
<div class="upd-card">
    <h3>↩️ Rollback to Previous Version</h3>
    <?php if (empty($backups)): ?>
    <div style="text-align:center;padding:16px;color:#94a3b8;font-size:13px;">No backups yet. A backup is created automatically every time you apply an update.</div>
    <?php else: ?>
    <div style="font-size:12px;color:#64748b;margin-bottom:10px;">Backups are stored in <code>data/plugin_backups/</code> and are never deleted by updates.</div>
    <?php foreach ($backups as $bk): ?>
    <div class="bk-row">
        <span style="font-size:16px;">💾</span>
        <div style="flex:1;">
            <div style="font-weight:700;font-size:13px;color:#1e293b;"><?= h($bk['file']) ?></div>
            <div style="font-size:11px;color:#94a3b8;"><?= $bk['mtime'] ?> · <?= $bk['size'] ?></div>
        </div>
        <form method="POST" style="margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="rollback">
            <input type="hidden" name="backup_file" value="<?= h($bk['file']) ?>">
            <button type="submit" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;"
                    onclick="return confirm('Roll back to <?= h($bk['file']) ?>?\n\nCurrent code will be overwritten. Data is not affected.')">
                ↩️ Restore
            </button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── Update log ── -->
<?php if (!empty($updateLog)): ?>
<div class="upd-card">
    <h3>📋 Update History</h3>
    <?php foreach ($updateLog as $entry): ?>
    <div class="log-row">
        <span style="font-size:18px;"><?= empty($entry['errors'])?'✅':'⚠️' ?></span>
        <div style="flex:1;">
            <div style="font-weight:700;">v<?= h($entry['from_version']??'?') ?> → v<?= h($entry['to_version']??'?') ?></div>
            <div style="color:#64748b;font-size:11px;"><?= h($entry['applied_at']??'') ?> · by <?= h($entry['applied_by']??'') ?></div>
            <div style="color:#94a3b8;font-size:11px;">ZIP: <?= h($entry['zip_name']??'') ?> · Backup: <?= h($entry['backup_file']??'') ?></div>
            <?php if (!empty($entry['errors'])): ?>
            <div style="color:#dc2626;font-size:11px;margin-top:3px;">⚠ <?= count($entry['errors']) ?> file(s) failed to write</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Best practice guide ── -->
<div class="upd-card" style="background:#f0f9ff;border-color:#bae6fd;">
    <h3>📖 Recommended Update Workflow</h3>
    <div style="font-size:13px;color:#0c4a6e;line-height:1.8;">
        <strong>Right approach for zero-downtime updates:</strong><br><br>
        <strong>1. Run tests first</strong> — Click <strong>▶ Run Tests Now</strong> above. All <?= $curVersion ? ('v'.$curVersion.' ') : '' ?>tests must pass before updating.<br>
        <strong>2. Upload ZIP here</strong> — Never use UCRM's plugin manager. Use this tab — it runs tests, backs up, then applies.<br>
        <strong>3. Off-peak timing</strong> — Apply updates at night or low-traffic periods (e.g. 2 AM).<br>
        <strong>4. Rollback instantly</strong> — If anything breaks, go to Backups tab and click Restore. Takes 5 seconds.<br>
        <strong>5. APK is safe</strong> — Your Android APK lives in <code>data/</code> permanently and is never touched by updates.<br><br>
        <span style="background:#0ea5e9;color:#fff;border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;">HOW IT WORKS</span>
        Upload ZIP → Tests run → If all pass, backup created → Update applied → Log written.
        If tests fail, update is <strong>blocked</strong> and you see exactly which tests failed.
    </div>
</div>

</div>
