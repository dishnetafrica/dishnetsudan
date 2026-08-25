<?php
declare(strict_types=1);
chdir(__DIR__);
require_once __DIR__ . '/lib/error_handler.php';

// ── Global execution timer: tracks total main.php runtime ────────────────────
$_mainStartTime = microtime(true);

// ══════════════════════════════════════════════════════════════════════════════
// DishNet Hybrid Telecom — main.php
// UISP/UCRM executes this on its plugin schedule (~every 5 minutes).
// Tasks: auto-backup, low-wallet alerts, pending sync alerts, heartbeat log.
// ══════════════════════════════════════════════════════════════════════════════

// Use persistent data directory (survives plugin updates)
require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
$logFile = $dataDir . '/heartbeat.log';

function mainLog(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    // Trim log to last 200 lines
    $lines = file($logFile);
    if (count($lines) > 200) file_put_contents($logFile, implode('', array_slice($lines, -150)));
}

function loadJSON(string $f): array {
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function saveAtomicJSON(string $f, array $data): void {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    $tmp = $f . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) !== false) {
        if (file_exists($f)) @copy($f, $f . '.bak');
        rename($tmp, $f);
    } else @unlink($tmp);
}

function logActivity(string $dataDir, string $action, string $title, string $detail = ''): void {
    $logFile = $dataDir . '/activity_log.json';
    $log = loadJSON($logFile);
    array_unshift($log, ['action'=>$action,'title'=>$title,'detail'=>$detail,
                         'time'=>date('Y-m-d H:i:s'),'date'=>date('Y-m-d')]);
    $log = array_slice($log, 0, 500);
    saveAtomicJSON($logFile, $log);
}

mainLog('Heartbeat OK.');

// ══════════════════════════════════════════════════════════════════════════════
// DATA DIRECTORY INTEGRITY CHECK
// Runs every cycle. Detects wrong data dir before it causes silent data loss.
// ══════════════════════════════════════════════════════════════════════════════
$integrityOk  = true;
$integrityLog = [];

// 1. Verify $dataDir came from getDataDir (contains ucrm.json pluginDataDir)
$ucrmJsonFound = false;
foreach ([__DIR__ . '/ucrm.json', __DIR__ . '/data/ucrm.json'] as $ujPath) {
    if (file_exists($ujPath)) {
        $ujData = @json_decode(file_get_contents($ujPath), true) ?? [];
        if (!empty($ujData['pluginDataDir'])) {
            $expectedDataDir = rtrim($ujData['pluginDataDir'], '/');
            if (rtrim($dataDir, '/') !== $expectedDataDir) {
                $integrityLog[] = "MISMATCH: dataDir={$dataDir} but ucrm.json says {$expectedDataDir}";
                $integrityOk = false;
            } else {
                $integrityLog[] = "OK: dataDir matches pluginDataDir";
            }
            $ucrmJsonFound = true;
        }
        break;
    }
}
if (!$ucrmJsonFound) {
    $integrityLog[] = "WARN: ucrm.json not found — using fallback dataDir";
}

// 2. Verify SQLite exists and is readable
$sqlitePath = $dataDir . '/plugin.sqlite3';
if (!file_exists($sqlitePath)) {
    $integrityLog[] = "WARN: plugin.sqlite3 not found — first boot or data loss";
    $integrityOk = false;
} else {
    try {
        $chkPdo = new PDO('sqlite:' . $sqlitePath);
        $tableCount = (int)$chkPdo->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table'"
        )->fetchColumn();
        $integrityLog[] = "OK: plugin.sqlite3 exists ({$tableCount} tables)";
        unset($chkPdo);
    } catch (Throwable $e) {
        $integrityLog[] = "ERROR: plugin.sqlite3 unreadable: " . $e->getMessage();
        $integrityOk = false;
    }
}

// 3. Write integrity status file (readable via cron_status API)
saveAtomicJSON($dataDir . '/integrity_check.json', [
    'checked_at' => date('Y-m-d H:i:s'),
    'data_dir'   => $dataDir,
    'ok'         => $integrityOk,
    'log'        => $integrityLog,
]);

if (!$integrityOk) {
    mainLog("⚠ DATA INTEGRITY WARNING: " . implode(' | ', $integrityLog));
} else {
    mainLog("Data integrity OK.");
}

// ── DAILY MORNING REPORT (07:00 — cashbook summary + CSV to accounts team) ──
$hour = (int)date('H');
$reportMeta = loadJSON($dataDir . '/daily_report_meta.json');
$lastReportDate = $reportMeta['last_report_date'] ?? '';
$todayDate = date('Y-m-d');

if ($hour >= 7 && $lastReportDate !== $todayDate) {
    mainLog("📊 Daily report triggered for yesterday's cashbook...");
    try {
        require_once __DIR__ . '/lib/DailyReportService.php';
        $cbService = null;
        // Get CashbookService for PDO access
        if (class_exists('CashbookService')) {
            $cbService = new CashbookService($store, $dataDir);
        } else {
            require_once __DIR__ . '/lib/CashbookService.php';
            $cbService = new CashbookService($store, $dataDir);
        }
        $reportSvc = new DailyReportService($cbService, $dataDir, $config);
        $reportResult = $reportSvc->sendDailyReport();

        // Mark as sent for today (even if failed — retry next cron cycle only if we reset)
        $reportMeta['last_report_date'] = $todayDate;
        $reportMeta['last_report_at']   = date('Y-m-d H:i:s');
        $reportMeta['last_result']      = $reportResult;
        saveJSON($dataDir . '/daily_report_meta.json', $reportMeta);

        if ($reportResult['sent']) {
            mainLog("📊 Daily report SENT to " . ($config['daily_report_recipients'] ?? 'accounts@dishnetafrica.com,bhavin@dishnetafrica.com'));
        } else {
            mainLog("📊 Daily report FAILED: " . ($reportResult['error'] ?? 'unknown'));
        }
    } catch (\Throwable $e) {
        mainLog("📊 Daily report ERROR: " . $e->getMessage());
    }
} elseif ($lastReportDate === $todayDate) {
    // Already sent today — skip
} else {
    mainLog("📊 Daily report: waiting for 07:00 (current hour: {$hour})");
}

// ── AUTO-BACKUP (3x daily: 08:00, 14:00, 20:00) ────────────────────────────
$bSettings  = loadJSON($dataDir . '/backup_settings.json');
$freq       = $bSettings['auto_backup_freq'] ?? '3x_daily';
$hour       = (int)date('H');
// PHP 7.4 compat — no match()
switch ($freq) {
    case 'hourly':   $shouldBackup = true; break;
    case '3x_daily': $shouldBackup = in_array($hour, [8, 14, 20]); break;
    case '2x_daily': $shouldBackup = in_array($hour, [8, 20]); break;
    case 'daily':    $shouldBackup = ($hour === 8); break;
    default:         $shouldBackup = false;
}

// Don't double-backup within same hour
$lastBackup = loadJSON($dataDir . '/last_backup_meta.json');
$lastHour   = (int)($lastBackup['hour'] ?? -1);
$lastDay    = $lastBackup['day'] ?? '';

if ($shouldBackup && ($lastHour !== $hour || $lastDay !== date('Y-m-d'))) {
    $backupDir = $dataDir . '/backups';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);
    $timestamp  = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . '/auto-backup-' . $timestamp . '.zip';

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($backupFile, ZipArchive::CREATE) === true) {
            // ── JSON files ───────────────────────────────────────────────
            foreach (glob($dataDir . '/*.json') as $f) {
                $bn = basename($f);
                if (strpos($f, '/backups/') !== false) continue;
                if ($bn === 'gdrive_config.json') continue; // contains OAuth tokens
                $zip->addFile($f, 'data/' . $bn);
            }
            // ── SQLite databases (safe copy via WAL checkpoint) ──────────
            foreach (['plugin.sqlite3', 'dishnet.sqlite'] as $dbName) {
                $dbPath = $dataDir . '/' . $dbName;
                if (!file_exists($dbPath)) continue;
                // Checkpoint WAL before copying to ensure copy is consistent
                try {
                    $tmpPdo = new PDO('sqlite:' . $dbPath);
                    $tmpPdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
                    unset($tmpPdo);
                } catch (Throwable $e) {}
                $tmpCopy = sys_get_temp_dir() . '/dishnet_backup_' . $dbName . '.' . getmypid();
                if (@copy($dbPath, $tmpCopy)) {
                    $zip->addFile($tmpCopy, 'data/' . $dbName);
                    // Clean up after close
                }
            }
            $zip->close();
            // Remove temp SQLite copies
            foreach (['plugin.sqlite3', 'dishnet.sqlite'] as $dbName) {
                $tmpCopy = sys_get_temp_dir() . '/dishnet_backup_' . $dbName . '.' . getmypid();
                @unlink($tmpCopy);
            }
            $sizekb = round(filesize($backupFile) / 1024);
            mainLog("Auto-backup created: auto-backup-{$timestamp}.zip ({$sizekb}KB)");
            logActivity($dataDir, 'backup_created', 'Auto-backup created (cron)', "File: auto-backup-{$timestamp}.zip ({$sizekb}KB)");

            // Rotate: keep last 14 backups
            $zips = glob($backupDir . '/auto-backup-*.zip');
            if ($zips && count($zips) > 14) {
                usort($zips, fn($a,$b) => filemtime($a) - filemtime($b));
                foreach (array_slice($zips, 0, count($zips) - 14) as $old) @unlink($old);
            }
            // Save meta to prevent double-backup
            saveAtomicJSON($dataDir . '/last_backup_meta.json', ['hour'=>$hour,'day'=>date('Y-m-d'),'file'=>basename($backupFile)]);
        } else {
            mainLog("Auto-backup FAILED: ZipArchive could not create $backupFile");
        }
    }
} else {
    mainLog('No backup needed this cycle.');
}

// ── LOW-WALLET ALERTS ────────────────────────────────────────────────────────
$retailers = loadJSON($dataDir . '/retailers.json');
$passbook  = loadJSON($dataDir . '/passbook.json');
$threshold = (float)($bSettings['low_wallet_alert_threshold'] ?? 50);
$lowCount  = 0;
foreach ($retailers as $r) {
    if (empty($r['is_active'])) continue;
    $rId  = (int)($r['id'] ?? 0);
    $cred = array_sum(array_column(array_filter($passbook, fn($t)=>((int)($t['retailer_id']??0))===$rId && ($t['type']??'')==='credit'), 'amount'));
    $deb  = array_sum(array_column(array_filter($passbook, fn($t)=>((int)($t['retailer_id']??0))===$rId && ($t['type']??'')==='debit'),  'amount'));
    $bal  = $cred - $deb;
    if ($bal < $threshold && $bal >= 0) {
        mainLog("LOW WALLET: {$r['name']} — balance \${$bal} (threshold \${$threshold})");
        $lowCount++;
    }
}
if ($lowCount) mainLog("{$lowCount} agents have low wallet balance.");

// ── PENDING SYNC ALERT ────────────────────────────────────────────────────────
$apps = loadJSON($dataDir . '/kyc_applications.json');
$pending = array_filter($apps, fn($a) => in_array($a['status']??'', ['pending','pending_sync']));
if (count($pending) > 0) {
    mainLog(count($pending) . ' applications pending CRM sync.');
} else {
    mainLog('All applications synced to CRM. ✓');
}

mainLog('Heartbeat complete.');

// ── MASTER CRON DISPATCHER ────────────────────────────────────────────────────
// cron/master.php handles all background jobs on their own schedules:
// photo_retry (1min), crm_sync (1min), splynx_sync (5min), lte (5min),
// wa_bot (5min), wallet_sync (configurable), leads (4h), maintenance (2am),
// bidal_summary (7am).
// Isolated try/catch: one failing job never blocks the others.
$masterCronPath = __DIR__ . '/cron/master.php';
if (file_exists($masterCronPath)) {
    try {
        include $masterCronPath;
    } catch (\Throwable $e) {
        mainLog('master.php error: ' . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// CRM ORG-7 WALLET AUTO-SYNC
// Reads each retailer's CRM Org-7 accountBalance and writes to wallet field.
// Runs on every main.php call (~5 min) but respects wallet_sync_interval_minutes
// from kyc_config.json (default 360 = 6 hours). Set to 0 to run every cycle.
// NO server crontab needed — UCRM calls main.php automatically.
//
// NOTE: cron_wallet_sync.php (via master.php) also handles this.
// This inline version is a legacy fallback. It is SKIPPED if main.php has
// already used too much time, to prevent UCRM execution overlap/freeze.
// ══════════════════════════════════════════════════════════════════════════════

$_mainElapsed = microtime(true) - $_mainStartTime;
if ($_mainElapsed > 260) {
    mainLog("⏱ main.php already ran " . round($_mainElapsed) . "s — skipping inline wallet sync + auto-pull (handled by master.php crons).");
} else {

$kycConfig       = loadJSON($dataDir . '/kyc_config.json');
$syncIntervalMin = (int)($kycConfig['wallet_sync_interval_minutes'] ?? 360);
$lastRunFile     = $dataDir . '/wallet_sync_last_run.txt';
$lastRun         = file_exists($lastRunFile) ? (int)file_get_contents($lastRunFile) : 0;
$elapsedMin      = (time() - $lastRun) / 60;

if ($syncIntervalMin > 0 && $elapsedMin < $syncIntervalMin) {
    $nextIn = ceil($syncIntervalMin - $elapsedMin);
    mainLog("Wallet sync: skipping — next in {$nextIn}m (interval: {$syncIntervalMin}m).");
} else {

    // ── Resolve CRM credentials from ucrm.json ──────────────────────────────
    $ucrmJson = [];
    foreach ([$pluginRoot ?? __DIR__, __DIR__] as $base) {
        foreach ([$base . '/ucrm.json', $base . '/data/ucrm.json'] as $p) {
            if (file_exists($p)) { $ucrmJson = json_decode(file_get_contents($p), true) ?? []; break 2; }
        }
    }

    $crmBase  = '';
    $crmToken = '';
    $crmHdr   = '';

    // Manual override takes priority
    if (!empty($kycConfig['crm_base_url']) && !empty($kycConfig['crm_auth_token'])) {
        $crmBase  = rtrim($kycConfig['crm_base_url'], '/');
        $crmToken = $kycConfig['crm_auth_token'];
        $crmHdr   = 'x-auth-token';
    } elseif (!empty($ucrmJson['ucrmLocalUrl']) && !empty($ucrmJson['pluginAppKey'])) {
        $crmBase  = rtrim($ucrmJson['ucrmLocalUrl'], '/') . '/api/v2.1';
        $crmToken = $ucrmJson['pluginAppKey'];
        $crmHdr   = 'X-Auth-App-Key';
    }

    if ($crmBase === '' || $crmToken === '') {
        mainLog('Wallet sync: CRM not configured — skipping.');
    } else {

        mainLog('Wallet sync: starting CRM Org-7 balance pull...');

        // ── Helper: GET from CRM ─────────────────────────────────────────────
        $crmGet = function(string $path) use ($crmBase, $crmToken, $crmHdr): ?array {
            $url = $crmBase . '/' . ltrim($path, '/');
            $ctx = stream_context_create(['http' => [
                'method'  => 'GET',
                'header'  => "{$crmHdr}: {$crmToken}\r\nContent-Type: application/json\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) return null;
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        };

        $retailers   = loadJSON($dataDir . '/retailers.json');
        $passbook    = loadJSON($dataDir . '/passbook.json');
        $report      = [];
        $updated     = 0;
        $unchanged   = 0;
        $failed      = 0;

        foreach ($retailers as &$r) {
            $crmId = (int)($r['ftth_crm_client_id'] ?? 0);
            if (!$crmId) continue;

            $client = $crmGet("clients/{$crmId}");
            if (!$client) {
                mainLog("  ⚠ {$r['name']} CRM #{$crmId}: fetch failed");
                $failed++;
                $report[] = ['id'=>$r['id'],'name'=>$r['name'],'crm_id'=>$crmId,
                    'crm_bal'=>null,'owes_amt'=>0.0,'owes'=>false,'error'=>true,'changed'=>false];
                continue;
            }

            $crmBal  = (float)($client['accountBalance'] ?? 0);
            $owesAmt = $crmBal < 0 ? round(-$crmBal, 2) : 0.0;
            $prevWal = (float)($r['wallet'] ?? 0);
            $changed = abs($prevWal - $owesAmt) > 0.005;

            $report[] = ['id'=>$r['id'],'name'=>$r['name'],'crm_id'=>$crmId,
                'crm_bal'=>$crmBal,'plugin_wal'=>$prevWal,'prev_debt'=>(float)($r['crm_outstanding']??0),
                'owes_amt'=>$owesAmt,'owes'=>$owesAmt>0.005,'changed'=>$changed,'error'=>false];

            if (!$changed) { $unchanged++; continue; }

            // Write wallet + crm_outstanding on retailer
            $r['wallet']             = $owesAmt;
            $r['crm_outstanding']    = $owesAmt;
            $r['crm_outstanding_at'] = date('Y-m-d H:i:s');
            $r['crm_bal_raw']        = $crmBal;

            // Passbook audit entry
            $diff    = $owesAmt - $prevWal;
            $pbEntry = [
                'id'          => count($passbook) + 1 + $updated,
                'retailer_id' => (int)$r['id'],
                'type'        => $diff >= 0 ? 'credit' : 'debit',
                'amount'      => abs($diff),
                'description' => "Auto-sync CRM #{$crmId} — outstanding: \${$owesAmt}",
                'reference'   => 'crm_auto_sync',
                'by'          => 'system',
                'entry_type'  => 'crm_auto_sync',
                'created_at'  => date('Y-m-d H:i:s'),
            ];
            $passbook[] = $pbEntry;

            $arrow = $owesAmt > $prevWal ? '⬆' : '⬇';
            mainLog("  {$arrow} {$r['name']}: \${$prevWal} → \${$owesAmt} (CRM: \${$crmBal})");
            $updated++;
        }
        unset($r);

        // Save updated retailers + passbook atomically
        if ($updated > 0) {
            saveAtomicJSON($dataDir . '/retailers.json', $retailers);
            saveAtomicJSON($dataDir . '/passbook.json',  $passbook);
        }

        // Sort report + save cache for UI panels
        usort($report, fn($a,$b) => ($b['owes_amt']??0) <=> ($a['owes_amt']??0));
        $debtors   = count(array_filter($report, fn($x) => $x['owes'] ?? false));
        $totalOwed = array_sum(array_column(array_filter($report, fn($x) => $x['owes'] ?? false), 'owes_amt'));
        saveAtomicJSON($dataDir . '/org7_crm_balance_cache.json', [
            'checked_at' => date('Y-m-d H:i:s'),
            'checked_by' => 'main_auto',
            'applied'    => true,
            'report'     => $report,
        ]);

        file_put_contents($lastRunFile, time());
        mainLog("Wallet sync done — updated: {$updated}, unchanged: {$unchanged}, failed: {$failed}. Debtors: {$debtors}, total: \${$totalOwed}.");
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// AUTO-PULL UCRM CLIENTS CACHE  (daily — default 03:00 server time)
// Fetches all clients from UCRM org 2 and refreshes ucrm_clients_cache.json
// Also rebuilds sales_person_index.json — maps each retailer name → client IDs
// Runs automatically via UCRM's main.php scheduler. No crontab needed.
// Control: kyc_config.json → ucrm_auto_pull_hour (0-23, default 3)
//          ucrm_auto_pull_enabled (true/false, default true)
// ══════════════════════════════════════════════════════════════════════════════

$autoPullEnabled = ($kycConfig['ucrm_auto_pull_enabled'] ?? true) !== false;
$autoPullHour    = (int)($kycConfig['ucrm_auto_pull_hour'] ?? 3);
$pullLastFile    = $dataDir . '/ucrm_pull_last_run.json';
$pullMeta        = file_exists($pullLastFile) ? (json_decode(file_get_contents($pullLastFile), true) ?? []) : [];
$pullLastDay     = $pullMeta['day'] ?? '';
$pullLastHour    = (int)($pullMeta['hour'] ?? -1);
$currentHour     = (int)date('H');
$currentDay      = date('Y-m-d');

$shouldAutoPull  = $autoPullEnabled
    && $currentHour === $autoPullHour
    && ($pullLastDay !== $currentDay || $pullLastHour !== $currentHour);

if (!$autoPullEnabled) {
    mainLog('UCRM auto-pull: disabled in config.');
} elseif (!$shouldAutoPull) {
    $nextPull = date('Y-m-d') . ' ' . str_pad($autoPullHour, 2, '0', STR_PAD_LEFT) . ':00';
    if ($currentHour >= $autoPullHour) $nextPull = date('Y-m-d', strtotime('+1 day')) . ' ' . str_pad($autoPullHour, 2, '0', STR_PAD_LEFT) . ':00';
    mainLog("UCRM auto-pull: scheduled for {$nextPull}.");
} elseif ($crmBase === '' || $crmToken === '') {
    mainLog('UCRM auto-pull: CRM not configured — skipping.');
} else {
    mainLog('UCRM auto-pull: starting full clients pull from UCRM Org 2...');

    $limit      = 100;
    $offset     = 0;
    $totalPulled = 0;
    $existingCache = [];
    $cacheFile  = $dataDir . '/ucrm_clients_cache.json';

    // Load existing cache as map
    if (file_exists($cacheFile)) {
        $existing = json_decode(file_get_contents($cacheFile), true) ?? [];
        foreach ($existing as $ec) {
            if (!empty($ec['id'])) $existingCache[(int)$ec['id']] = $ec;
        }
    }

    $newMap = $existingCache; // start with existing, overwrite with fresh data
    $pages  = 0;
    $errors = 0;
    $maxPages = 200; // safety cap ~20,000 clients

    while ($pages < $maxPages) {
        $data = $crmGet("clients?limit={$limit}&offset={$offset}");
        if ($data === null) {
            $errors++;
            mainLog("  ⚠ Page " . ($pages+1) . " fetch failed.");
            break;
        }
        $items = is_array($data) ? array_filter($data, 'is_array') : [];
        $count = count($items);
        if ($count === 0) break;

        foreach ($items as $c) {
            if (!empty($c['id'])) $newMap[(int)$c['id']] = $c;
        }
        $totalPulled += $count;
        $pages++;
        $offset += $limit;

        if ($count < $limit) break; // last page
    }

    // Save updated clients cache via SqliteStore — MUST use $dataDir (persistent),
    // NOT __DIR__/data which is the installation folder and gets wiped on every update.
    if (file_exists(__DIR__ . '/lib/SqliteStore.php')) {
        require_once __DIR__ . '/lib/SqliteStore.php';
        require_once __DIR__ . '/lib/StoreInterface.php';
        $mainStore = \DishNet\SqliteStore::create($dataDir);
        $mainStore->save('ucrm_clients_cache.json', array_values($newMap));
    } else {
        file_put_contents($cacheFile, json_encode(array_values($newMap), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    mainLog("UCRM auto-pull: done — {$totalPulled} clients pulled ({$pages} pages), {$errors} errors. Total cached: " . count($newMap));

    // ── REBUILD GLOBAL CLIENT SEARCH INDEX ──────────────────────────────
    // Full shape: id, name, phone, email, bal, plans, username, status, search
    // All plugin consumers load from this — no per-tab rebuilds needed.
    $planNames2 = []; $svcByClient2 = [];
    $mainPlans    = $mainStore->load('ucrm_plans_cache.json')    ?? [];
    $mainServices = $mainStore->load('ucrm_services_cache.json') ?? [];
    foreach ($mainPlans as $p) { if (!empty($p['id'])) $planNames2[(int)$p['id']] = $p['name'] ?? ''; }
    foreach ($mainServices as $s) { $scid=(int)($s['clientId']??$s['_clientId']??0); if($scid) $svcByClient2[$scid][]=($planNames2[(int)($s['servicePlanId']??0)]??''); }
    $searchIdx = [];
    foreach (array_values($newMap) as $c) {
        $cid = (int)($c['id'] ?? 0); if (!$cid) continue;
        $fn = trim($c['firstName'] ?? ''); $ln = trim($c['lastName'] ?? '');
        $nm = trim("$fn $ln"); if (!$nm) $nm = trim($c['companyName'] ?? $c['username'] ?? '');
        $ph = ''; $em = ''; $xn = [];
        foreach ($c['contacts'] ?? [] as $ct) { if (!$ph){if(!empty($ct['phone']))$ph=$ct['phone'];elseif(!empty($ct['phones'][0]['number']))$ph=$ct['phones'][0]['number'];} if(!$em&&!empty($ct['email']))$em=$ct['email']; if(!empty($ct['name']))$xn[]=$ct['name']; }
        if (!$ph) $ph = trim($c['phone'] ?? $c['mobile'] ?? ''); if (!$em) $em = trim($c['email'] ?? '');
        $pl = implode(' ', array_filter($svcByClient2[$cid] ?? [])); $un = trim($c['username'] ?? ''); $co = trim($c['companyName'] ?? '');
        $searchIdx[] = ['id'=>$cid,'name'=>$nm,'phone'=>$ph,'email'=>$em,'bal'=>(float)($c['accountBalance']??0),'plans'=>$pl,'username'=>$un,'status'=>($c['status']??''),'search'=>strtolower("$nm $ph $em $cid $pl ".implode(' ',$xn)." $un $co")];
    }
    $mainStore->save('client_search_index.json', $searchIdx);
    mainLog('Search index rebuilt: ' . count($searchIdx) . ' entries.');
    // Extract customAttribute[1] (Sales Person) from each client
    // Produces: { "Mecklyine": [crmId1, crmId2, ...], "Justus": [...] }
    // Also produces: ucrm_sales_summary.json — per-salesperson stats
    // ATTR IDs: 1=Sales Person, 36=Priority, 41=Package, 43=Ref
    $ATTR_SALES_PERSON = 1;
    $ATTR_PRIORITY     = 36;
    $ATTR_PACKAGE      = 41;
    $ATTR_REF          = 43;

    $spIndex   = []; // salesPerson => [clientId => mini client record]
    $spSummary = []; // salesPerson => {count, active, leads, revenue, packages}

    foreach ($newMap as $c) {
        $cId     = (int)$c['id'];
        $isLead  = (bool)($c['isLead'] ?? false);
        $fname   = trim($c['firstName'] ?? '');
        $lname   = trim($c['lastName']  ?? '');
        $name    = trim("$fname $lname") ?: trim($c['companyName'] ?? $c['username'] ?? '');
        $balance = (float)($c['accountBalance'] ?? 0);
        $regDate = substr($c['registrationDate'] ?? $c['createdAt'] ?? '', 0, 10);
        $username = trim($c['username'] ?? '');

        // Extract custom attributes
        $attrs   = [];
        foreach ($c['attributes'] ?? ($c['customAttributes'] ?? []) as $attr) {
            $attrs[(int)($attr['customAttributeId'] ?? 0)] = trim($attr['value'] ?? '');
        }
        $salesPerson = $attrs[$ATTR_SALES_PERSON] ?? '';
        $package     = $attrs[$ATTR_PACKAGE]      ?? '';
        $priority    = $attrs[$ATTR_PRIORITY]     ?? '';
        $ref         = $attrs[$ATTR_REF]          ?? '';

        if (!$salesPerson) continue; // no sales attribution

        // Phone
        $phone = '';
        foreach ($c['contacts'] ?? [] as $ct) {
            if (!$phone && !empty($ct['phone'])) { $phone = $ct['phone']; break; }
        }
        if (!$phone) $phone = trim($c['phone'] ?? '');

        // Build index entry
        if (!isset($spIndex[$salesPerson])) $spIndex[$salesPerson] = [];
        $spIndex[$salesPerson][$cId] = [
            'id'       => $cId,
            'name'     => $name,
            'username' => $username,
            'phone'    => $phone,
            'is_lead'  => $isLead,
            'balance'  => $balance,
            'package'  => $package,
            'priority' => $priority,
            'ref'      => $ref,
            'reg_date' => $regDate,
        ];

        // Summary stats
        if (!isset($spSummary[$salesPerson])) {
            $spSummary[$salesPerson] = ['count'=>0,'active'=>0,'leads'=>0,'packages'=>[],'months'=>[]];
        }
        $spSummary[$salesPerson]['count']++;
        if ($isLead) $spSummary[$salesPerson]['leads']++;
        else $spSummary[$salesPerson]['active']++;
        if ($package) $spSummary[$salesPerson]['packages'][$package] = ($spSummary[$salesPerson]['packages'][$package] ?? 0) + 1;
        $mo5 = substr($regDate, 0, 7);
        if ($mo5) $spSummary[$salesPerson]['months'][$mo5] = ($spSummary[$salesPerson]['months'][$mo5] ?? 0) + 1;
    }

    // Convert index maps to arrays, sort by reg_date desc
    $spIndexFlat = [];
    foreach ($spIndex as $sp => $clients) {
        $arr = array_values($clients);
        usort($arr, fn($a,$b) => strcmp($b['reg_date']??'', $a['reg_date']??''));
        $spIndexFlat[$sp] = $arr;
    }

    // Sort summary by count desc
    arsort($spSummary);

    // Save via SQLite store if available, fallback to JSON files
    if (isset($mainStore)) {
        $mainStore->save('sp_client_index.json', $spIndexFlat);
        $mainStore->save('sp_summary.json',      $spSummary);
    } else {
        file_put_contents($dataDir . '/sp_client_index.json',
            json_encode($spIndexFlat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($dataDir . '/sp_summary.json',
            json_encode($spSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    $totalAttrib = array_sum(array_map('count', $spIndex));
    mainLog("Sales index built: " . count($spIndex) . " sales persons, {$totalAttrib} attributed clients.");

    // Save pull meta
    $pullMetaData = [
        'day'     => $currentDay,
        'hour'    => $currentHour,
        'ran_at'  => date('Y-m-d H:i:s'),
        'pulled'  => $totalPulled,
        'cached'  => count($newMap),
        'sp_count'=> count($spIndex),
        'errors'  => $errors,
    ];
    if (isset($mainStore)) {
        $mainStore->save('ucrm_pull_last_run.json', $pullMetaData);
    }
    file_put_contents($pullLastFile, json_encode($pullMetaData));
}

} // end time-guard else block

$_mainTotalSec = round(microtime(true) - $_mainStartTime);
mainLog("main.php total execution: {$_mainTotalSec}s");
