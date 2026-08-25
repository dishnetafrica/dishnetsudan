#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

// PHP 7.4 polyfills
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * DishNet Hybrid Telecom — Maintenance Cron
 *
 * Run daily via cron (recommended: 02:00 EAT, off-peak):
 *   0 2 * * * php /path/to/cron_maintenance.php >> /path/to/maintenance.log 2>&1
 *
 * Tasks performed:
 *   1. R-01  Wallet Integrity Check     — verify every retailer's live wallet
 *                                         balance matches sum(credits) - sum(debits)
 *                                         from passbook. Flags and emails discrepancies.
 *   2. P-02  Data Archival              — move old completed/reversed CRM queue jobs,
 *                                         expired LTE subscriptions, and KYC applications
 *                                         older than retention thresholds into _archive/
 *                                         files. Keeps hot files lean.
 *   3. C-03  Call Recording Retention   — deletes .m4a/.aac files from call_recordings/
 *                                         older than recording_retention_days (default 365).
 *                                         Cleans log entries + detaches lead call_log refs.
 *   4. R-04  Backup Rotation            — copy all data/*.json to dated backup snapshots.
 *                                         Retains 7 daily + 4 weekly + 3 monthly backups.
 *                                         Prunes older snapshots automatically.
 *   5. N-05  Overdue Escalation         — queries UCRM for overdue invoices, sends
 *                                         Day+3 and Day+5 escalation WhatsApp messages
 *                                         via NotificationService. De-duplicated via
 *                                         overdue_escalation_log.json.
 */

chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/CrmApiClient.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

// ─── Single-instance lock ─────────────────────────────────────────────────────
$dataDir  = getDataDir(__DIR__);
$lockFile = $dataDir . '/cron_maintenance.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) fclose($lockFp);
    return; // Another instance is running — skip silently
}

$store   = SqliteStore::create($dataDir); // SQLite — shares same plugin.sqlite3
$config  = $store->load('kyc_config.json');
$crm     = CrmApiClient::fromUcrm(__DIR__, $config);
$notify  = new NotificationService($store, $config);
$started = date('Y-m-d H:i:s');
$results = [];

mlog("══════════════════════════════════════════════");
mlog("DishNet Maintenance Cron — {$started}");
mlog("══════════════════════════════════════════════");

// ═════════════════════════════════════════════════════════════════════════════
// TASK 1 — R-01: WALLET INTEGRITY CHECK
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[1/4] Wallet Integrity Check");

try {
    $retailers = $store->load('retailers.json');
    $passbook  = $store->load('passbook.json');
    $discrepancies = [];
    $checked = 0;

    foreach ($retailers as $retailer) {
        $rid     = (int)($retailer['id'] ?? 0);
        $liveBal = round((float)($retailer['wallet'] ?? 0), 2);

        // Compute expected balance from passbook ledger
        $credits = 0.0;
        $debits  = 0.0;
        foreach ($passbook as $entry) {
            if ((int)($entry['retailer_id'] ?? 0) !== $rid) continue;
            $amt = (float)($entry['amount'] ?? 0);
            if (($entry['entry_type'] ?? '') === 'credit') {
                $credits += $amt;
            } elseif (($entry['entry_type'] ?? '') === 'debit') {
                $debits += $amt;
            }
        }
        $expected = round($credits - $debits, 2);
        $diff     = round($liveBal - $expected, 2);
        $checked++;

        if (abs($diff) >= 0.01) {
            $discrepancies[] = [
                'retailer_id'   => $rid,
                'retailer_name' => $retailer['name'] ?? "Retailer#{$rid}",
                'live_balance'  => $liveBal,
                'ledger_balance'=> $expected,
                'diff'          => $diff,
                'credits_total' => round($credits, 2),
                'debits_total'  => round($debits, 2),
                'detected_at'   => date('Y-m-d H:i:s'),
            ];
            mlog("  ⚠  DISCREPANCY — Retailer #{$rid} ({$retailer['name']}): live=\${$liveBal} ledger=\${$expected} diff=\${$diff}");
        } else {
            mlog("  ✓  Retailer #{$rid} ({$retailer['name']}): \${$liveBal} OK");
        }
    }

    $results['wallet_check'] = [
        'checked'        => $checked,
        'discrepancies'  => count($discrepancies),
        'details'        => $discrepancies,
    ];

    if (!empty($discrepancies)) {
        // Append to persistent discrepancy log for audit trail
        $store->withLock('wallet_integrity_log.json', function (array $log) use ($discrepancies): array {
            foreach ($discrepancies as $d) {
                $maxId   = empty($log) ? 0 : max(array_map(fn($r) => (int)($r['id'] ?? 0), $log));
                $d['id'] = $maxId + 1;
                $log[]   = $d;
            }
            // Keep last 1000 entries
            if (count($log) > 1000) $log = array_slice($log, -1000);
            return ['records' => $log, 'result' => null];
        });

        mlog("  ✗  " . count($discrepancies) . " discrepancy(ies) written to wallet_integrity_log.json");

        // Write a plaintext alert file that a monitoring script/email cron can detect
        $alertFile = $dataDir . '/WALLET_INTEGRITY_ALERT.txt';
        $alertLines = ["DishNet Wallet Integrity Alert — " . date('Y-m-d H:i:s'), ""];
        foreach ($discrepancies as $d) {
            $alertLines[] = "Retailer #{$d['retailer_id']} ({$d['retailer_name']})";
            $alertLines[] = "  Live balance : \${$d['live_balance']}";
            $alertLines[] = "  Ledger total : \${$d['ledger_balance']} (credits \${$d['credits_total']} − debits \${$d['debits_total']})";
            $alertLines[] = "  Difference   : \${$d['diff']}";
            $alertLines[] = "";
        }
        $alertLines[] = "Check wallet_integrity_log.json in the DishNet data directory.";
        file_put_contents($alertFile, implode("\n", $alertLines));
        mlog("  ✗  Alert written to WALLET_INTEGRITY_ALERT.txt");
    } else {
        // Clear any previous alert file
        @unlink($dataDir . '/WALLET_INTEGRITY_ALERT.txt');
        mlog("  ✓  All {$checked} retailers balanced. No discrepancies.");
    }
} catch (Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['wallet_check'] = ['error' => $e->getMessage()];
}

// ═════════════════════════════════════════════════════════════════════════════
// TASK 2 — P-02: DATA ARCHIVAL
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[2/4] Data Archival");

$archiveDir = $dataDir . '/_archive';
if (!is_dir($archiveDir)) mkdir($archiveDir, 0750, true);

$archiveResults = [];

// ── 2a: CRM Queue — archive completed/reversed/exhausted jobs older than 30 days
try {
    $cutoff    = date('Y-m-d', strtotime('-30 days'));
    $archived  = 0;
    $remaining = 0;

    $store->withLock('crm_queue.json', function (array $jobs) use ($cutoff, $archiveDir, &$archived, &$remaining): array {
        $keep    = [];
        $toArchive = [];
        foreach ($jobs as $j) {
            $terminal  = in_array($j['status'] ?? '', ['completed', 'reversed', 'exhausted']);
            $old       = ($j['completed_at'] ?? $j['created_at'] ?? '9999') < $cutoff;
            if ($terminal && $old) {
                $toArchive[] = $j;
                $archived++;
            } else {
                $keep[] = $j;
                $remaining++;
            }
        }
        if (!empty($toArchive)) {
            appendToArchive($archiveDir, 'crm_queue_archive.json', $toArchive);
        }
        return ['records' => $keep, 'result' => null];
    });

    mlog("  crm_queue.json: archived {$archived} old terminal jobs, {$remaining} remain");
    $archiveResults['crm_queue'] = compact('archived', 'remaining');
} catch (Throwable $e) {
    mlog("  crm_queue ERROR: " . $e->getMessage());
}

// ── 2b: LTE Subscriptions — archive expired subs older than 90 days
try {
    $cutoff   = date('Y-m-d', strtotime('-90 days'));
    $archived = 0;
    $remaining = 0;

    $store->withLock('lte_subscriptions.json', function (array $subs) use ($cutoff, $archiveDir, &$archived, &$remaining): array {
        $keep      = [];
        $toArchive = [];
        foreach ($subs as $s) {
            $isExpired = in_array($s['status'] ?? '', ['expired', 'cancelled']);
            $old       = ($s['expired_at'] ?? $s['created_at'] ?? '9999') < $cutoff;
            if ($isExpired && $old) {
                $toArchive[] = $s;
                $archived++;
            } else {
                $keep[] = $s;
                $remaining++;
            }
        }
        if (!empty($toArchive)) {
            appendToArchive($archiveDir, 'lte_subscriptions_archive.json', $toArchive);
        }
        return ['records' => $keep, 'result' => null];
    });

    mlog("  lte_subscriptions.json: archived {$archived} expired subs, {$remaining} remain");
    $archiveResults['lte_subscriptions'] = compact('archived', 'remaining');
} catch (Throwable $e) {
    mlog("  lte_subscriptions ERROR: " . $e->getMessage());
}

// ── 2c: KYC Applications — archive completed/failed apps older than 180 days
try {
    $cutoff   = date('Y-m-d', strtotime('-180 days'));
    $archived = 0;
    $remaining = 0;

    $store->withLock('kyc_applications.json', function (array $apps) use ($cutoff, $archiveDir, &$archived, &$remaining): array {
        $keep      = [];
        $toArchive = [];
        foreach ($apps as $a) {
            $terminal = in_array($a['status'] ?? '', ['completed', 'failed', 'reversed']);
            $old      = ($a['date'] ?? '9999') < $cutoff;
            if ($terminal && $old) {
                $toArchive[] = $a;
                $archived++;
            } else {
                $keep[] = $a;
                $remaining++;
            }
        }
        if (!empty($toArchive)) {
            appendToArchive($archiveDir, 'kyc_applications_archive.json', $toArchive);
        }
        return ['records' => $keep, 'result' => null];
    });

    mlog("  kyc_applications.json: archived {$archived} old apps, {$remaining} remain");
    $archiveResults['kyc_applications'] = compact('archived', 'remaining');
} catch (Throwable $e) {
    mlog("  kyc_applications ERROR: " . $e->getMessage());
}

// ── 2d: LTE Auto-Suspend Log — keep last 1000 entries only
try {
    $logFile = 'lte_auto_suspend_log.json';
    $trimmed = 0;

    $store->withLock($logFile, function (array $entries) use (&$trimmed): array {
        $before = count($entries);
        if ($before > 1000) {
            $entries = array_slice($entries, -1000);
            $trimmed = $before - count($entries);
        }
        return ['records' => $entries, 'result' => null];
    });

    mlog("  lte_auto_suspend_log.json: trimmed {$trimmed} old entries");
    $archiveResults['suspend_log'] = ['trimmed' => $trimmed];
} catch (Throwable $e) {
    mlog("  suspend_log ERROR: " . $e->getMessage());
}

// ── 2e: LTE Renewals Log — keep last 2000 entries
try {
    $trimmed = 0;
    $store->withLock('lte_renewals.json', function (array $entries) use (&$trimmed): array {
        $before = count($entries);
        if ($before > 2000) {
            $entries = array_slice($entries, -2000);
            $trimmed = $before - count($entries);
        }
        return ['records' => $entries, 'result' => null];
    });
    mlog("  lte_renewals.json: trimmed {$trimmed} old entries");
    $archiveResults['renewals'] = ['trimmed' => $trimmed];
} catch (Throwable $e) {
    mlog("  lte_renewals ERROR: " . $e->getMessage());
}

$results['archival'] = $archiveResults;

// =============================================================================
// TASK 3 — C-03: CALL RECORDING RETENTION
// =============================================================================
// Deletes recording files older than $retentionDays from data/call_recordings/.
// Also removes log entries and detaches refs from lead call_log entries.
//
// Configure in kyc_config.json:
//   "recording_retention_days": 365   (default — 1 year)
//   Set to 0 to keep forever (monitor disk space).
// =============================================================================

mlog("\n[3/5] Call Recording Retention");

try {
    $retentionDays = (int)($config['recording_retention_days'] ?? 365);

    if ($retentionDays <= 0) {
        mlog("  SKIP — recording_retention_days = 0 (keep forever)");
        $results['recording_retention'] = ['skipped' => 'keep_forever'];
    } else {
        $recDir   = $dataDir . '/call_recordings';
        $cutoffTs = strtotime("-{$retentionDays} days");
        $cutoffDt = date('Y-m-d H:i:s', $cutoffTs);

        $deletedFiles   = 0;
        $deletedBytes   = 0;
        $missingFiles   = 0;
        $keptFiles      = 0;
        $deletedLogRows = 0;

        mlog("  Retention: {$retentionDays} days | cutoff: {$cutoffDt}");

        // Step 1: scan log, delete expired files, trim log entries
        $toDeleteFiles = [];

        $store->withLock('call_recordings_log.json', function (array $log) use (
            $cutoffDt, $recDir,
            &$toDeleteFiles, &$deletedFiles, &$deletedBytes,
            &$missingFiles, &$keptFiles, &$deletedLogRows
        ): array {
            $keep = [];
            foreach ($log as $r) {
                $recordedAt = $r['recorded_at'] ?? '1970-01-01 00:00:00';
                if ($recordedAt < $cutoffDt) {
                    $basename = basename($r['file'] ?? '');
                    if ($basename) {
                        $fullPath = $recDir . '/' . $basename;
                        if (file_exists($fullPath)) {
                            $deletedBytes += filesize($fullPath);
                            @unlink($fullPath);
                            $deletedFiles++;
                            $toDeleteFiles[$basename] = true;
                        } else {
                            $missingFiles++;
                        }
                    }
                    $deletedLogRows++;
                } else {
                    $keep[] = $r;
                    $keptFiles++;
                }
            }
            return ['records' => $keep, 'result' => null];
        });

        // Step 2: detach recording refs from lead call_log entries
        if (!empty($toDeleteFiles)) {
            $detached = 0;
            $store->withLock('leads.json', function (array $leads) use ($toDeleteFiles, &$detached): array {
                foreach ($leads as &$lead) {
                    if (empty($lead['call_log'])) continue;
                    foreach ($lead['call_log'] as &$entry) {
                        if (empty($entry['recording'])) continue;
                        if (isset($toDeleteFiles[basename($entry['recording'])])) {
                            $entry['recording'] = null;
                            $detached++;
                        }
                    }
                    unset($entry);
                }
                unset($lead);
                return ['records' => $leads, 'result' => null];
            });
            mlog("  Detached recording refs from {$detached} lead call_log entries");
        }

        // Step 3: orphan sweep — files on disk with no log entry, older than 7 days
        $orphansDeleted = 0;
        $orphanBytes    = 0;
        if (is_dir($recDir)) {
            $updatedLog = $store->load('call_recordings_log.json') ?? [];
            $knownFiles = [];
            foreach ($updatedLog as $r) {
                if (!empty($r['file'])) $knownFiles[basename($r['file'])] = true;
            }
            $validExts = ['aac','m4a','mp3','ogg','wav','3gp','opus'];
            foreach (scandir($recDir) as $fn) {
                if ($fn === '.' || $fn === '..') continue;
                if (!in_array(strtolower(pathinfo($fn, PATHINFO_EXTENSION)), $validExts)) continue;
                if (!isset($knownFiles[$fn])) {
                    $fp = $recDir . '/' . $fn;
                    if (filemtime($fp) < strtotime('-7 days')) {
                        $orphanBytes += filesize($fp);
                        @unlink($fp);
                        $orphansDeleted++;
                    }
                }
            }
        }

        $mbFreed = round(($deletedBytes + $orphanBytes) / (1024 * 1024), 2);
        mlog("  Deleted {$deletedFiles} expired + {$orphansDeleted} orphan files = {$mbFreed} MB freed");
        mlog("  Log rows removed: {$deletedLogRows} | kept: {$keptFiles} | missing on disk: {$missingFiles}");

        $results['recording_retention'] = [
            'retention_days'   => $retentionDays,
            'cutoff'           => $cutoffDt,
            'files_deleted'    => $deletedFiles,
            'orphans_deleted'  => $orphansDeleted,
            'mb_freed'         => $mbFreed,
            'log_rows_removed' => $deletedLogRows,
            'log_rows_kept'    => $keptFiles,
        ];
    }
} catch (Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['recording_retention'] = ['error' => $e->getMessage()];
}


// ═════════════════════════════════════════════════════════════════════════════
// TASK 3 — R-03: BACKUP ROTATION
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[4/5] Backup Rotation");

$backupBase = $dataDir . '/_backups';
if (!is_dir($backupBase)) mkdir($backupBase, 0750, true);

try {
    $today     = date('Y-m-d');
    $dow       = (int)date('N');  // 1=Mon … 7=Sun
    $dom       = (int)date('j');  // 1-31
    $backupDir = $backupBase . '/daily_' . $today;

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0750, true);
    }

    // Back up the SQLite database file (single file = all data)
    // Also copy any remaining *.json files (kyc_config, archived seeds, etc.)
    $filesCopied = 0;
    $dbPath = $dataDir . '/plugin.sqlite3';
    if (file_exists($dbPath)) {
        // Use SQLite's online backup API via a raw file copy — safe because
        // WAL mode allows consistent reads while writers are active
        copy($dbPath, $backupDir . '/plugin.sqlite3');
        // Also copy the WAL/SHM companion files if they exist
        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($dbPath . $suffix)) {
                copy($dbPath . $suffix, $backupDir . '/plugin.sqlite3' . $suffix);
            }
        }
        $filesCopied++;
    }
    // Copy any JSON files still present (kyc_config.json, seed files, etc.)
    $jsonFiles = glob($dataDir . '/*.json') ?: [];
    foreach ($jsonFiles as $src) {
        $fname = basename($src);
        if (str_starts_with($fname, '_') || str_ends_with($fname, '.lock')) continue;
        copy($src, $backupDir . '/' . $fname);
        $filesCopied++;
    }
    mlog("  Daily backup: {$backupDir} ({$filesCopied} files)");

    // If Sunday — create a weekly snapshot symlink/copy
    if ($dow === 7) {
        $weeklyDir = $backupBase . '/weekly_' . date('Y-W');
        if (!is_dir($weeklyDir)) {
            // Copy daily snapshot as the weekly
            recursiveCopy($backupDir, $weeklyDir);
            mlog("  Weekly snapshot: {$weeklyDir}");
        }
    }

    // If 1st of month — create monthly snapshot
    if ($dom === 1) {
        $monthlyDir = $backupBase . '/monthly_' . date('Y-m');
        if (!is_dir($monthlyDir)) {
            recursiveCopy($backupDir, $monthlyDir);
            mlog("  Monthly snapshot: {$monthlyDir}");
        }
    }

    // ── Prune old backups ──────────────────────────────────────────────────
    $pruned = pruneBackups($backupBase, 'daily_',   7);   // keep 7 daily
    $pruned += pruneBackups($backupBase, 'weekly_',  4);  // keep 4 weekly
    $pruned += pruneBackups($backupBase, 'monthly_', 3);  // keep 3 monthly

    mlog("  Pruned {$pruned} old backup snapshot(s)");

    $results['backup'] = [
        'snapshot'     => $backupDir,
        'files_copied' => $filesCopied,
        'pruned'       => $pruned,
    ];
} catch (Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['backup'] = ['error' => $e->getMessage()];
}

// ═════════════════════════════════════════════════════════════════════════════
// TASK 3B — EVENT QUEUE PRUNING (Phase 2 v3.8)
// ═════════════════════════════════════════════════════════════════════════════
try {
    mlog("Task 3B: Event queue pruning...");
    require_once __DIR__ . '/lib/EventBus.php';
    $bus = new EventBus($store->getPdo());
    $eventsPruned = $bus->prune(30); // Delete completed events older than 30 days
    mlog("  Pruned {$eventsPruned} completed events (>30 days old)");
    $summary = $bus->getSummary();
    mlog("  Queue status: pending={$summary['pending']} failed={$summary['failed']} dead={$summary['dead']} done={$summary['done']}");
    $results['event_queue'] = ['pruned' => $eventsPruned, 'summary' => $summary];
} catch (Throwable $e) {
    mlog("  Event pruning skipped: " . $e->getMessage());
    $results['event_queue'] = ['error' => $e->getMessage()];
}

// ── Notification audit log + failed queue + dedup housekeeping ─────────────
try {
    mlog("Task 3C: Notification log housekeeping...");
    $auditPurged = $notify->purgeAuditLog(90);
    $queuePurged = $notify->purgeQueue(30);
    $dedupPurged = $notify->purgeDedupLog(45);
    mlog("  Audit log: purged {$auditPurged} (>90d). Queue: purged {$queuePurged} (>30d). Dedup: purged {$dedupPurged} (>45d).");
    $results['notification_housekeeping'] = ['audit_purged' => $auditPurged, 'queue_purged' => $queuePurged, 'dedup_purged' => $dedupPurged];
} catch (Throwable $e) {
    mlog("  Notification housekeeping skipped: " . $e->getMessage());
}

// ═════════════════════════════════════════════════════════════════════════════
// TASK 4a — PRE-DUE INVOICE REMINDERS
// ═════════════════════════════════════════════════════════════════════════════
// Queries UCRM for unpaid invoices, finds ones at exactly 7, 3, and 1 day(s)
// before due date, sends the appropriate WhatsApp reminder.
//
// De-duplication: stores sent keys in pre_due_reminder_log.json
// e.g. "INV012775-d7" => "2026-03-15"
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[4a/5] Pre-Due Invoice Reminders");

try {
    if (!$crm->isConfigured()) {
        mlog("  SKIP — CRM not configured");
        $results['pre_due_reminders'] = ['skipped' => 'CRM not configured'];
    } else {
        $today    = new DateTimeImmutable('today', new DateTimeZone('Africa/Juba'));
        $sentPre7 = 0;
        $sentPre3 = 0;
        $sentPre1 = 0;
        $skippedP = 0;
        $errorsP  = 0;

        // Dedup: uses SQLite notification_dedup table (atomic, race-safe)

        // Fetch unpaid invoices (status 1 = unpaid, 2 = partial — exclude draft:0, paid:3, void:4)
        $invoices = $crm->get('invoices?status[]=1&status[]=2&limit=500') ?? [];

        mlog("  Fetched " . count($invoices) . " unpaid invoices from CRM");

        foreach ($invoices as $inv) {
            $invoiceNum = (string)($inv['number'] ?? ($inv['id'] ?? '?'));
            $invId      = (int)($inv['id'] ?? 0);
            $dueDate    = $inv['dueDate'] ?? '';
            $total      = (float)($inv['amountToPay'] ?? $inv['total'] ?? 0);
            $currency   = $inv['currencyCode'] ?? 'USD';
            $clientId   = (int)($inv['clientId'] ?? 0);

            if (!$dueDate || !$clientId || $total <= 0) { $skippedP++; continue; }

            // ── Hard stop: skip if invoice is paid or void ─────────────────────
            // UCRM statuses: 0=Draft, 1=Unpaid, 2=Partial, 3=Paid, 4=Void
            $invStatus = (int)($inv['status'] ?? -1);
            if (in_array($invStatus, [3, 4], true)) {
                mlog("  SKIP #{$invoiceNum} — already paid/void (status={$invStatus})");
                $skippedP++;
                continue;
            }

            // Parse due date
            $due = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $dueDate)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dueDate)
                ?: DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);

            if (!$due) { $skippedP++; continue; }

            // Calculate days UNTIL due (positive = future, negative = overdue)
            $daysUntilDue = (int)$today->diff($due->setTime(0,0,0))->format('%r%a');

            // We want: 7 days before, 3 days before, 1 day before
            $tier = null;
            if ($daysUntilDue === 7) $tier = 'd7';
            if ($daysUntilDue === 3) $tier = 'd3';
            if ($daysUntilDue === 1) $tier = 'd1';
            if (!$tier) { $skippedP++; continue; }

            $logKey = "{$invoiceNum}-pre-{$tier}";
            if (!$notify->dedupMark($logKey)) {
                $skippedP++;
                continue;
            }

            // Fresh invoice check — customer may have paid since bulk fetch
            if ($invId) {
                $freshInv = $crm->get("invoices/{$invId}");
                if ($freshInv) {
                    $freshStatus = (int)($freshInv['status'] ?? -1);
                    $freshAmt    = (float)($freshInv['amountToPay'] ?? $freshInv['total'] ?? 0);
                    if (in_array($freshStatus, [3, 4], true) || $freshAmt <= 0) {
                        mlog("  SKIP #{$invoiceNum} pre-{$tier} — already paid (fresh check)");
                        $skippedP++; continue;
                    }
                    $total = $freshAmt;
                }
            }

            // Fetch client details for phone + name (Task 4a)
            $client = $crm->get("clients/{$clientId}");
            if (!$client) { $errorsP++; continue; }

            $phone = $client['contacts'][0]['phone']
                  ?? $client['contacts'][0]['phones'][0]['number']
                  ?? '';
            if (!$phone) { $skippedP++; continue; }

            $firstName = $client['firstName'] ?? '';
            $lastName  = $client['lastName']  ?? '';
            $fullName  = trim("{$firstName} {$lastName}") ?: "Customer";

            // Service name for context
            $serviceName = '';
            $services    = $crm->get("clients/services?clientId={$clientId}&status[]=1") ?? [];
            if (!empty($services[0]['name'])) {
                $serviceName = $services[0]['name'];
            }

            // Format due date nicely
            $dueDateFormatted = $due->format('M j, Y');

            mlog("  Sending pre-due tier={$tier} to {$fullName} (#{$clientId}) invoice #{$invoiceNum} due={$dueDateFormatted}");

            if ($tier === 'd7') {
                $notify->invoiceDue7Days($phone, $fullName, $invoiceNum, $total, $currency, $dueDateFormatted, $serviceName);
                $sentPre7++;
            } elseif ($tier === 'd3') {
                $notify->invoiceDue3Days($phone, $fullName, $invoiceNum, $total, $currency, $dueDateFormatted, $serviceName);
                $sentPre3++;
            } else {
                $notify->invoiceDueTomorrow($phone, $fullName, $invoiceNum, $total, $currency, $dueDateFormatted, $serviceName);
                $sentPre1++;
            }

            // dedup already marked atomically by dedupMark() above
            usleep(300000); // 0.3s between messages
        }

        $totalSentPre = $sentPre7 + $sentPre3 + $sentPre1;
        mlog("  Pre-due done: sent={$totalSentPre} (7d={$sentPre7}, 3d={$sentPre3}, 1d={$sentPre1}), skipped={$skippedP}, errors={$errorsP}");
        $results['pre_due_reminders'] = [
            'sent_7day' => $sentPre7, 'sent_3day' => $sentPre3, 'sent_1day' => $sentPre1,
            'skipped' => $skippedP, 'errors' => $errorsP
        ];
    }
} catch (\Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['pre_due_reminders'] = ['error' => $e->getMessage()];
}

// ═════════════════════════════════════════════════════════════════════════════
// TASK 4b — NEW INVOICE NOTIFICATION SCANNER
// ═════════════════════════════════════════════════════════════════════════════
// UCRM auto-generated invoices do NOT trigger webhooks. This scanner catches
// any invoices created in the last 24 hours that weren't notified via webhook.
// De-duplication: stores sent keys in invoice_notify_log.json
// e.g. "INV012775" => "2026-03-15 10:42:00"
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[4b/7] New Invoice Notification Scanner");

try {
    if (!$crm->isConfigured()) {
        mlog("  SKIP — CRM not configured");
        $results['invoice_notify_scanner'] = ['skipped' => 'CRM not configured'];
    } else {
        // Dedup: uses SQLite notification_dedup table (atomic, shared with webhook + cron_invoice_notify)
        $invSent   = 0;
        $invSkipped = 0;
        $invErrors  = 0;

        // Fetch invoices created in last 24h using UCRM date filter
        // Use same endpoint pattern as working overdue scanner (Task 4)
        $yesterdayDate = date('Y-m-d', strtotime('-1 day'));
        $todayDate     = date('Y-m-d');
        
        // Try multiple UCRM API endpoint formats — only unpaid(1) + partial(2)
        $allInvoices = $crm->get("billing/invoices?statuses[]=1&statuses[]=2&createdDateFrom={$yesterdayDate}&createdDateTo={$todayDate}&limit=500") ?? [];
        if (empty($allInvoices)) {
            $allInvoices = $crm->get("invoices?status[]=1&status[]=2&createdDateFrom={$yesterdayDate}&createdDateTo={$todayDate}&limit=500") ?? [];
        }
        if (empty($allInvoices)) {
            // Fallback: fetch all unpaid/partial and filter by date locally
            $allInvoices = $crm->get('invoices?statuses[]=1&statuses[]=2&limit=500') ?? [];
            if (empty($allInvoices)) {
                $allInvoices = $crm->get('invoices?status[]=1&status[]=2&limit=500') ?? [];
            }
        }

        // Filter to only invoices created in last 24 hours (local filter as safety net)
        $cutoffTime = strtotime('-24 hours');
        $recentInvoices = array_filter($allInvoices, function($inv) use ($cutoffTime) {
            $created = $inv['createdDate'] ?? $inv['createdAt'] ?? $inv['created_at'] ?? '';
            if (!$created) return true; // If no date field, include it (let dedup handle it)
            $ts = strtotime($created);
            return $ts && $ts >= $cutoffTime;
        });

        mlog("  Fetched " . count($allInvoices) . " unpaid invoices, " . count($recentInvoices) . " created in last 24h");

        foreach ($recentInvoices as $inv) {
            $invoiceId  = (int)($inv['id'] ?? 0);
            $invoiceNum = (string)($inv['number'] ?? $invoiceId);
            $total      = (float)($inv['amountToPay'] ?? $inv['total'] ?? 0);
            $clientId   = (int)($inv['clientId'] ?? 0);
            $dueDate    = substr($inv['dueDate'] ?? $inv['maturityDate'] ?? '', 0, 10);

            if (!$invoiceId || !$clientId || $total <= 0) { $invSkipped++; continue; }

            // Already notified? (atomic check+mark — shared with webhook + cron_invoice_notify)
            $logKey = "INV{$invoiceNum}";
            if (!$notify->dedupMark($logKey)) { $invSkipped++; continue; }

            // Fresh invoice check — customer may have paid since bulk fetch
            $freshInv = $crm->get("invoices/{$invoiceId}");
            if ($freshInv) {
                $freshAmt = (float)($freshInv['amountToPay'] ?? $freshInv['total'] ?? 0);
                $freshStatus = (int)($freshInv['status'] ?? -1);
                if ($freshAmt <= 0 || in_array($freshStatus, [3, 4], true)) {
                    mlog("  SKIP #{$invoiceNum} — already paid (fresh check)");
                    $invSkipped++; continue;
                }
                $total = $freshAmt;
            }

            // Fetch client for phone + name
            $client = $crm->get("clients/{$clientId}");
            if (!$client) { $invErrors++; continue; }

            $phone = '';
            foreach (($client['contacts'] ?? []) as $c) {
                if (!empty($c['phone'])) { $phone = $c['phone']; break; }
            }
            if (!$phone) { $invSkipped++; continue; }

            $firstName = $client['firstName'] ?? '';
            $lastName  = $client['lastName']  ?? '';
            $fullName  = trim("{$firstName} {$lastName}") ?: 'Customer';

            // Send invoice notification
            $notify->invoiceCreated($phone, $fullName, $invoiceNum, $total, $dueDate ?: 'See invoice');
            mlog("  SENT: Invoice #{$invoiceNum} \${$total} → {$fullName} ({$phone})");

            // Send invoice PDF via WhatsML
            try {
                $pdfRaw = $crm->getRawContent("invoices/{$invoiceId}/pdf");
                if ($pdfRaw) {
                    $tempDir = $dataDir . '/temp_pdf';
                    if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

                    // Clean old temp files (>10 min)
                    foreach (glob($tempDir . '/*.pdf') as $_tf) {
                        if (time() - filemtime($_tf) > 600) { @unlink($_tf); @unlink($_tf . '.meta'); }
                    }

                    $pdfFile  = "inv_{$invoiceId}_" . substr(md5(uniqid()), 0, 8) . '.pdf';
                    $pdfPath  = $tempDir . '/' . $pdfFile;
                    $pdfToken = hash_hmac('sha256', $pdfFile, ($config['webhook_secret'] ?? 'dishnet') . date('Ymd'));

                    file_put_contents($pdfPath, base64_decode($pdfRaw));
                    file_put_contents($pdfPath . '.meta', json_encode([
                        'token'   => $pdfToken,
                        'created' => time(),
                        'invoice' => $invoiceNum,
                    ]));

                    $siteUrl = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
        $siteUrl = preg_replace('#/api/v[0-9.]+$#', '', $siteUrl);
        $siteUrl = preg_replace('#/crm$#', '', $siteUrl);
                    $pdfUrl  = $siteUrl . '/crm/_plugins/dishnet-hybrid-telecom/public.php'
                             . '?page=api&action=serve_temp_pdf'
                             . '&file=' . urlencode($pdfFile)
                             . '&token=' . urlencode($pdfToken);

                    $notify->sendDocument(
                        'accounts',
                        $phone,
                        $pdfUrl,
                        "{$invoiceNum}.pdf",
                        "Invoice #{$invoiceNum} — \${$total} — Due: {$dueDate}\n— DishNet Africa",
                        'ops_invoice_pdf'
                    );
                    mlog("  PDF sent: #{$invoiceNum}");
                }
            } catch (\Throwable $pdfErr) {
                mlog("  PDF error: " . $pdfErr->getMessage());
            }

            // dedup already marked atomically by dedupMark() above
            $invSent++;
            usleep(500000); // 0.5s between messages to avoid rate limiting
        }

        mlog("  Invoice scanner done: sent={$invSent}, skipped={$invSkipped}, errors={$invErrors}");
        $results['invoice_notify_scanner'] = ['sent' => $invSent, 'skipped' => $invSkipped, 'errors' => $invErrors];
    }
} catch (\Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['invoice_notify_scanner'] = ['error' => $e->getMessage()];
}

// ═════════════════════════════════════════════════════════════════════════════
// TASK 4 — N-04: OVERDUE ESCALATION SCANNER
// ═════════════════════════════════════════════════════════════════════════════
// Queries UCRM for all overdue invoices, finds ones at exactly Day+3 and
// Day+5 past due date, sends the appropriate escalation message.
//
// De-duplication: stores sent invoice IDs in overdue_escalation_log.json
// so each escalation tier fires exactly once per invoice, even if this
// cron runs multiple times in a day.
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[5/5] Overdue Escalation Scanner");

try {
    if (!$crm->isConfigured()) {
        mlog("  SKIP — CRM not configured (crm_base_url / ucrm.json missing)");
        $results['overdue_escalation'] = ['skipped' => 'CRM not configured'];
    } else {
        $today       = new DateTimeImmutable('today', new DateTimeZone('Africa/Juba'));
        $sentD1      = 0;
        $sentD3      = 0;
        $sentD5      = 0;
        $sentD7      = 0;
        $skipped     = 0;
        $errors      = 0;

        // Dedup: uses SQLite notification_dedup table (atomic, race-safe)

        // UCRM: Fetch unpaid/partial/overdue invoices (status 1=Unpaid, 2=Partial, 3=Overdue)
        // Exclude: 0=Draft, 4=Void — these must never get overdue reminders
        $invoices = $crm->get('invoices?status[]=1&status[]=2&status[]=3&limit=500') ?? [];

        mlog("  Fetched " . count($invoices) . " unpaid/overdue invoices from CRM");

        foreach ($invoices as $inv) {
            $invoiceNum = (string)($inv['number'] ?? ($inv['id'] ?? '?'));
            $invId      = (int)($inv['id'] ?? 0);
            $dueDate    = $inv['dueDate'] ?? '';
            $total      = (float)($inv['amountToPay'] ?? $inv['total'] ?? 0);
            $currency   = $inv['currencyCode'] ?? 'USD';
            $clientId   = (int)($inv['clientId'] ?? 0);

            if (!$dueDate || !$clientId || $total <= 0) { $skipped++; continue; }

            // Parse due date — UCRM format is 'Y-m-d\TH:i:sO' or 'Y-m-d'
            $due = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $dueDate)
                ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dueDate)
                ?: DateTimeImmutable::createFromFormat('Y-m-d', $dueDate);

            if (!$due) { $skipped++; continue; }

            $daysOverdue = (int)$today->diff($due->setTime(0,0,0))->format('%r%a');
            // $daysOverdue is negative when overdue (today > dueDate)
            // We want: daysOverdue == -3 → Day+3, daysOverdue == -5 → Day+5
            $tier = null;
            if ($daysOverdue === -1) $tier = 'd1';
            if ($daysOverdue === -3) $tier = 'd3';
            if ($daysOverdue === -5) $tier = 'd5';
            if ($daysOverdue === -7) $tier = 'd7';
            if (!$tier) { $skipped++; continue; }

            $logKey = "{$invoiceNum}-{$tier}";
            if (!$notify->dedupMark($logKey)) {
                mlog("  SKIP #{$invoiceNum} tier={$tier} — already sent");
                $skipped++;
                continue;
            }

            // Fresh invoice check — customer may have paid since initial fetch
            $freshInv = $crm->get("invoices/{$invId}");
            if ($freshInv) {
                $freshAmtDue = (float)($freshInv['amountToPay'] ?? $freshInv['total'] ?? 0);
                if ($freshAmtDue <= 0) {
                    mlog("  SKIP #{$invoiceNum} tier={$tier} — already paid (fresh check)");
                    $skipped++; continue;
                }
                $total = $freshAmtDue;
            }

            // Fetch client details for phone + name
            $client = $crm->get("clients/{$clientId}");
            if (!$client) { $errors++; continue; }

            $phone = $client['contacts'][0]['phone']
                  ?? $client['contacts'][0]['phones'][0]['number']
                  ?? '';
            if (!$phone) {
                mlog("  SKIP #{$invoiceNum} — client #{$clientId} has no phone");
                $skipped++;
                continue;
            }

            $firstName = $client['firstName'] ?? '';
            $lastName  = $client['lastName']  ?? '';
            $fullName  = trim("{$firstName} {$lastName}") ?: "Customer";

            // Get active service name for Day+5 message
            $serviceName = 'DishNet Service';
            $services    = $crm->get("clients/services?clientId={$clientId}&status[]=1") ?? [];
            if (!empty($services[0]['name'])) {
                $serviceName = $services[0]['name'];
            }

            mlog("  Sending tier={$tier} to {$fullName} (#{$clientId}) invoice #{$invoiceNum} amount={$total} {$currency}");

            if ($tier === 'd1') {
                $notify->overdueDay1($phone, $fullName, $invoiceNum, $total, $currency, $serviceName);
                $sentD1++;
            } elseif ($tier === 'd3') {
                $notify->overdueDay3($phone, $fullName, $invoiceNum, $total, $currency, $serviceName);
                $sentD3++;
            } elseif ($tier === 'd5') {
                $notify->overdueDay5($phone, $fullName, $invoiceNum, $total, $currency, $serviceName);
                $sentD5++;
            } elseif ($tier === 'd7') {
                $dueFormatted = date('M j, Y', strtotime($dueDate));
                $notify->lowBalanceWarning($phone, $fullName, $total, $dueFormatted, $invoiceNum, $serviceName);
                $sentD7++;
            }

            // dedup already marked atomically by dedupMark() above

            // Micro-delay to avoid hammering the WASender API
            usleep(300_000); // 0.3s between messages
        }

        mlog("  Done — D1 sent: {$sentD1}, D3 sent: {$sentD3}, D5 sent: {$sentD5}, D7 sent: {$sentD7}, skipped: {$skipped}, errors: {$errors}");

        $results['overdue_escalation'] = [
            'invoices_checked' => count($invoices),
            'sent_d1'  => $sentD1,
            'sent_d3'  => $sentD3,
            'sent_d5'  => $sentD5,
            'sent_d7'  => $sentD7,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }
} catch (Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['overdue_escalation'] = ['error' => $e->getMessage()];
}

// ═════════════════════════════════════════════════════════════════════════════
// TASK 5 — HANDOVER AUTO-NUDGE
// ═════════════════════════════════════════════════════════════════════════════
// Auto-sends WhatsApp nudge to agents holding cash > $500 for 24+ hours
// without submitting a handover. One nudge per agent per day.
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[5/7] Handover Auto-Nudge");

try {
    if (!class_exists('StaffCashPositionService')) {
        require_once __DIR__ . '/lib/StaffCashPositionService.php';
    }
    $cpSvc      = new StaffCashPositionService($store, $store->getPdo());
    $retailers  = $store->load('retailers.json') ?? [];
    $agents     = array_filter($retailers, function($r) {
        return in_array($r['role'] ?? '', ['sales', 'field_agent', 'collection']) && !empty($r['is_active']);
    });
    $nudgeLog   = $store->load('handover_nudge_log.json') ?: [];
    $today      = date('Y-m-d');
    $nudged     = 0;
    $threshold  = (float)($config['handover_nudge_threshold'] ?? 500);

    foreach ($agents as $ag) {
        $aid = (int)($ag['id'] ?? 0);
        if (!$aid) continue;

        $pos = $cpSvc->getPosition($aid);
        $cih = (float)($pos['cash_in_hand'] ?? 0);

        if ($cih < $threshold) continue;

        // Check if already nudged today
        $logKey = "{$aid}-{$today}";
        if (isset($nudgeLog[$logKey])) continue;

        $phone = $ag['phone'] ?? '';
        $name  = $ag['name'] ?? 'Agent';
        if (!$phone) continue;

        $notify->sendRaw($phone,
            "📢 *Cash Handover Reminder*\n\n"
            . "Hi {$name}, you are holding *\$" . number_format($cih, 2) . "* in collected cash.\n\n"
            . "Please submit your handover in the DishNet app:\n"
            . "Cashbook → Submit Handover\n\n"
            . "— DishNet Accounts",
            'ops_handover_auto_nudge');

        $nudgeLog[$logKey] = date('Y-m-d H:i:s');
        $nudged++;
        mlog("  Nudged {$name} (#{$aid}) — \${$cih} in hand");
        usleep(300000);
    }

    // Prune old nudge log entries (>7 days)
    $cutoff7 = date('Y-m-d', strtotime('-7 days'));
    foreach ($nudgeLog as $k => $v) {
        if (substr($v, 0, 10) < $cutoff7) unset($nudgeLog[$k]);
    }
    $store->save('handover_nudge_log.json', $nudgeLog);

    mlog("  Handover nudge done: {$nudged} agents nudged (threshold: \${$threshold})");
    $results['handover_nudge'] = ['nudged' => $nudged, 'threshold' => $threshold];
} catch (\Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['handover_nudge'] = ['error' => $e->getMessage()];
}

// ═════════════════════════════════════════════════════════════════════════════
// TASK 6 — CASHBOOK DAILY SUMMARY (WhatsApp to Admin)
// ═════════════════════════════════════════════════════════════════════════════
// Sends an evening summary of today's cashbook to admin/Rupesh via WhatsApp.
// Runs once per day (dedup by date).
// ═════════════════════════════════════════════════════════════════════════════

mlog("\n[6/7] Cashbook Daily Summary");

try {
    $summaryLog = $store->load('cashbook_summary_log.json') ?: [];
    $todayStr2  = date('Y-m-d');
    $nowHour    = (int)date('H');

    // Only send after 5 PM (17:00) and only once per day
    if ($nowHour < 17) {
        mlog("  SKIP — before 5 PM ({$nowHour}:00)");
        $results['cashbook_summary'] = ['skipped' => 'before_5pm'];
    } elseif (isset($summaryLog[$todayStr2])) {
        mlog("  SKIP — already sent today");
        $results['cashbook_summary'] = ['skipped' => 'already_sent'];
    } else {
        require_once __DIR__ . '/lib/CashbookService.php';
        $cbSummary = new CashbookService($store, $dataDir);

        // Get today's entries
        $allEntries = $cbSummary->getEntries(['date_from' => $todayStr2, 'date_to' => $todayStr2, 'limit' => 9999]);
        $totalIn  = 0;
        $totalOut = 0;
        $countIn  = 0;
        $countOut = 0;
        foreach ($allEntries as $e) {
            $amt = (float)($e['amount'] ?? 0);
            $dir = $e['direction'] ?? '';
            if ($dir === 'in')  { $totalIn  += $amt; $countIn++;  }
            if ($dir === 'out') { $totalOut += $amt; $countOut++; }
        }
        $netFlow = $totalIn - $totalOut;

        // Agent-wise collection summary
        if (!class_exists('StaffCashPositionService')) {
            require_once __DIR__ . '/lib/StaffCashPositionService.php';
        }
        $cpSvc2     = new StaffCashPositionService($store, $store->getPdo());
        $retailers2 = $store->load('retailers.json') ?? [];
        $agentLines = [];
        foreach ($retailers2 as $r) {
            if (!in_array($r['role'] ?? '', ['sales', 'field_agent', 'collection'])) continue;
            if (empty($r['is_active'])) continue;
            $pos2 = $cpSvc2->getPosition((int)$r['id']);
            $cih2 = (float)($pos2['cash_in_hand'] ?? 0);
            if ($cih2 > 0.01) {
                $agentLines[] = "  • {$r['name']}: \$" . number_format($cih2, 2);
            }
        }

        // Pending handovers
        $pendingHov = array_filter($store->load('cash_handovers.json') ?? [],
            function($h) { return ($h['status'] ?? '') === 'pending'; });
        $pendAmt = array_sum(array_map(function($h) { return (float)($h['amount'] ?? 0); }, $pendingHov));

        // Build message
        $msg = "📊 *Cashbook Daily Summary — {$todayStr2}*\n\n"
             . "💰 *Cash IN:*  \$" . number_format($totalIn, 2) . " ({$countIn} entries)\n"
             . "💸 *Cash OUT:* \$" . number_format($totalOut, 2) . " ({$countOut} entries)\n"
             . "📈 *Net Flow:* \$" . number_format($netFlow, 2) . "\n";

        if (!empty($agentLines)) {
            $msg .= "\n👥 *Cash Held by Agents:*\n" . implode("\n", $agentLines) . "\n";
        }

        if (count($pendingHov) > 0) {
            $msg .= "\n⏳ *Pending Handovers:* " . count($pendingHov) . " (\$" . number_format($pendAmt, 2) . ")\n";
        }

        $msg .= "\n— DishNet Hybrid";

        // Send to admin phone
        $adminPhone = $config['whatsapp_admin_phone'] ?? '';
        if ($adminPhone) {
            $notify->sendRaw($adminPhone, $msg, 'ops_cashbook_daily_summary');
            $summaryLog[$todayStr2] = date('Y-m-d H:i:s');
            $store->save('cashbook_summary_log.json', $summaryLog);
            mlog("  Sent to {$adminPhone}: IN=\${$totalIn}, OUT=\${$totalOut}");
            $results['cashbook_summary'] = ['sent' => true, 'cash_in' => $totalIn, 'cash_out' => $totalOut];
        } else {
            mlog("  SKIP — whatsapp_admin_phone not configured");
            $results['cashbook_summary'] = ['skipped' => 'no_admin_phone'];
        }

        // Prune old log entries
        foreach ($summaryLog as $k => $v) {
            if ($k < date('Y-m-d', strtotime('-30 days'))) unset($summaryLog[$k]);
        }
        $store->save('cashbook_summary_log.json', $summaryLog);
    }
} catch (\Throwable $e) {
    mlog("  ERROR: " . $e->getMessage());
    $results['cashbook_summary'] = ['error' => $e->getMessage()];
}

// ═════════════════════════════════════════════════════════════════════════════
// WRITE MAINTENANCE RUN LOG
// ═════════════════════════════════════════════════════════════════════════════

try {
    $store->withLock('maintenance_log.json', function (array $log) use ($started, $results): array {
        $maxId    = empty($log) ? 0 : max(array_map(fn($r) => (int)($r['id'] ?? 0), $log));
        $log[]    = [
            'id'         => $maxId + 1,
            'started_at' => $started,
            'ended_at'   => date('Y-m-d H:i:s'),
            'results'    => $results,
        ];
        // Keep last 90 run records (3 months of daily runs)
        if (count($log) > 90) $log = array_slice($log, -90);
        return ['records' => $log, 'result' => null];
    });
    mlog("\n✓ Maintenance run logged to maintenance_log.json");
} catch (Throwable $e) {
    mlog("\nWARN: Could not write maintenance log — " . $e->getMessage());
}

// ══════════════════════════════════════════════════════════════════════════════
// WIN-BACK FOLLOW-UP — 7 days after service ended
// Sends a "we miss you" message to churned customers
// ══════════════════════════════════════════════════════════════════════════════
try {
    mlog("\n── Win-back check ──");
    $winbackLog = $store->load('winback_log.json') ?: [];
    $winbackSent = 0;

    // Fetch ended services (status 3 = ended, 5 = obsolete — varies by UCRM version)
    $endedServices = $crm->get('clients/services?statuses[]=3&limit=500') ?? [];
    if (empty($endedServices)) {
        $endedServices = $crm->get('clients/services?statuses[]=5&limit=500') ?? [];
    }

    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    $tenDaysAgo   = date('Y-m-d', strtotime('-10 days'));

    foreach ($endedServices as $svc) {
        $svcId    = (int)($svc['id'] ?? 0);
        $clientId = (int)($svc['clientId'] ?? 0);
        $svcName  = $svc['name'] ?? $svc['servicePlanName'] ?? 'Internet Service';
        $activeTo = substr($svc['activeTo'] ?? '', 0, 10);

        if (!$svcId || !$clientId || !$activeTo) continue;

        // Only process services that ended 7-10 days ago
        if ($activeTo < $tenDaysAgo || $activeTo > $sevenDaysAgo) continue;

        // Dedup
        $wbKey = "WB{$svcId}";
        if (isset($winbackLog[$wbKey])) continue;

        // Check if client has any OTHER active service — if yes, skip (not actually churned)
        $otherServices = $crm->get("clients/{$clientId}/services") ?? [];
        $hasActive = false;
        foreach ($otherServices as $os) {
            if ((int)($os['id'] ?? 0) !== $svcId && (int)($os['status'] ?? 0) === 1) {
                $hasActive = true;
                break;
            }
        }
        if ($hasActive) {
            $winbackLog[$wbKey] = 'skip_active';
            continue;
        }

        $client = $crm->get("clients/{$clientId}");
        if (!$client) continue;

        $custPhone = '';
        foreach (($client['contacts'] ?? []) as $c) {
            if (!empty($c['phone'])) { $custPhone = $c['phone']; break; }
        }
        if (!$custPhone) continue;

        $custName = trim(($client['firstName'] ?? '') . ' ' . ($client['lastName'] ?? ''))
                  ?: ($client['companyName'] ?? 'Customer');

        $notify->winBackFollowup($custPhone, $custName, $svcName, $activeTo);
        mlog("Win-back sent: {$custName} ({$custPhone}) — {$svcName} ended {$activeTo}");

        $winbackLog[$wbKey] = date('Y-m-d H:i:s');
        $winbackSent++;
        usleep(500000);
    }

    // Prune old entries (>120 days)
    $cutoff120 = date('Y-m-d', strtotime('-120 days'));
    foreach ($winbackLog as $k => $v) {
        if ($k[0] === 'W' && is_string($v) && strlen($v) >= 10 && substr($v, 0, 10) < $cutoff120) {
            unset($winbackLog[$k]);
        }
    }
    $store->save('winback_log.json', $winbackLog);
    mlog("Win-back: {$winbackSent} sent");
} catch (Throwable $wbErr) {
    mlog("Win-back error: " . $wbErr->getMessage());
}

mlog("Done — " . date('Y-m-d H:i:s'));
mlog("══════════════════════════════════════════════\n");

flock($lockFp, LOCK_UN);
fclose($lockFp);
return;

// ═════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═════════════════════════════════════════════════════════════════════════════

function mlog(string $msg): void
{
    $ts = date('H:i:s');
    echo "[{$ts}] {$msg}\n";
}

/**
 * Append an array of records to a growing archive JSON file.
 * Uses a simple append-and-rewrite strategy under PHP file lock.
 */
function appendToArchive(string $archiveDir, string $filename, array $records): void
{
    $path = $archiveDir . '/' . $filename;
    $fp   = fopen($path, file_exists($path) ? 'r+' : 'w+');
    if (!$fp) return;

    flock($fp, LOCK_EX);

    $raw      = stream_get_contents($fp);
    $existing = ($raw !== '' && $raw !== false) ? (json_decode($raw, true) ?? []) : [];
    $merged   = array_merge($existing, $records);

    $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Recursively copy a directory.
 */
function recursiveCopy(string $src, string $dst): void
{
    if (!is_dir($dst)) mkdir($dst, 0750, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        is_dir($s) ? recursiveCopy($s, $d) : copy($s, $d);
    }
}

/**
 * Prune backup directories with a given prefix, keeping only $keep most recent.
 * Returns the number of directories removed.
 */
function pruneBackups(string $baseDir, string $prefix, int $keep): int
{
    $dirs = glob($baseDir . '/' . $prefix . '*', GLOB_ONLYDIR) ?: [];
    sort($dirs); // lexicographic = chronological for YYYY-MM-DD / YYYY-W## / YYYY-MM

    $pruned = 0;
    $toDelete = array_slice($dirs, 0, max(0, count($dirs) - $keep));
    foreach ($toDelete as $dir) {
        recursiveDelete($dir);
        $pruned++;
        mlog("    Pruned: " . basename($dir));
    }
    return $pruned;
}

/**
 * Recursively delete a directory and all its contents.
 */
function recursiveDelete(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? recursiveDelete($path) : unlink($path);
    }
    rmdir($dir);
}
