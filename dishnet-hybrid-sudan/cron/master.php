#!/usr/bin/env php
<?php
// Note: No strict_types here — this file is included from public.php piggyback cron
date_default_timezone_set('Africa/Juba');
require_once dirname(__DIR__) . '/lib/error_handler.php';

/**
 * cron/master.php — DishNet Hybrid Telecom Master Scheduler
 *
 * Purpose:
 *   Single entry point for ALL background jobs.
 *   Replaces 6 separate crontab entries with one.
 *   Each job runs on its own schedule, enforced here via last-run timestamps.
 *   Jobs are isolated: one failing job does NOT stop others.
 *
 * Crontab — only this ONE entry needed:
 *   * * * * * php /path/to/plugin/cron/master.php >> /var/log/dishnet_master.log 2>&1
 *
 * Also called by main.php (UCRM heartbeat) as fallback.
 *
 * Job Schedule:
 *   photo_retry     — every 1 minute  (upload retry for failed KYC photos)
 *   crm_sync        — every 1 minute  (KYC → UCRM client creation queue)
 *   splynx_sync     — every 5 minutes (Splynx ticket + billing sync)
 *   lte_cron        — every 5 minutes (LTE suspend/reactivate/remind)
 *   lte_sync        — every 5 minutes (BlueCard MySQL → SQLite bridge sync)
 *   lte_usage       — every 5 minutes (Prometheus data-cap usage sync + WhatsApp warnings)
 *   job_assign      — every 5 minutes (new UCRM job assigned → WhatsApp tech instantly)
 *   wa_bot          — every 5 minutes (WhatsApp inbox polling + auto-reply)
 *   wallet_sync     — configurable    (Org-7 → retailer wallet balance, default 6h)
 *   leads           — every 4 hours   (lead auto-distribution + follow-up)
 *   maintenance     — daily at 02:00  (log pruning, integrity checks, cleanup)
 *   bidal_summary   — daily at 07:00  (morning WA summary to support leader)
 *   staff_jobs      — daily at 07:00  (per-staff WA job brief)
 *   cashbook_summary— daily at 18:00  (evening cashbook P&L to admin/accountant)
 *   gdrive_backup  — configurable    (Google Drive cloud backup, default daily at 03:00)
 *
 * Architecture:
 *   Uses data/master_schedule.json to track last run timestamps.
 *   Each job runs in its own try/catch — failures are logged, not propagated.
 *   Uses flock to prevent overlapping master runs.
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/bootstrap_data.php';

// Use persistent data directory (survives plugin updates)
$pluginRoot = dirname(__DIR__);
$dataDir = getDataDir($pluginRoot);

// ── CRITICAL: Use prefixed variables because included cron scripts
// share this scope and overwrite $store, $config, $jobs, $now etc.
// Without prefixes, $store gets clobbered by every included script
// and the final save() uses the wrong PDO connection.
$_m_store   = SqliteStore::create($dataDir);
$_m_config  = $_m_store->load('kyc_config.json') ?? [];

// ── Single-instance lock for master with stale self-healing ──────────────────
// If lock file is >30 min old the previous run was killed hard (SIGKILL from
// server restart). register_shutdown_function does not fire on SIGKILL.
// In that case delete the stale file and re-acquire instead of skipping.
// 30 min is safe — even slow runs (LTE sync) finish under 5 min.
$lockFile      = $dataDir . '/cron_master.lock';
$LOCK_MAX_SECS = 1800;

if (file_exists($lockFile) && (time() - filemtime($lockFile)) > $LOCK_MAX_SECS) {
    error_log('[cron_master] Stale cron_master.lock detected (>30min) — auto-clearing');
    @unlink($lockFile);
}

$lockFp = fopen($lockFile, 'w+');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    fclose($lockFp);
    return; // another instance running — normal, skip
}

// Shutdown handler — fires on fatal/timeout/normal exit. NOT on SIGKILL (handled above).
register_shutdown_function(function() use ($lockFp, $lockFile) {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
    @touch($lockFile); // reset mtime
});

// ── Load schedule state ───────────────────────────────────────────────────────
$_m_schedule = $_m_store->load('master_schedule.json') ?? [];
$_m_now      = time();
$_m_hour     = (int)date('G'); // 0-23
$_m_minute   = (int)date('i');

// ── Global execution budget ──────────────────────────────────────────────────
// UCRM calls main.php every 5 minutes. main.php does heartbeat + backup THEN
// includes us. We get at most ~240s before the next UCRM cycle.
// After the budget expires, stop dispatching new jobs to prevent overlap/freeze.
$_m_budget_sec = (int)($_m_config['master_budget_seconds'] ?? 300);
$_m_deadline   = $_m_now + $_m_budget_sec;

// ── Seed missing job entries so they are tracked from first run ───────────────
// Prevents cron_job:null after data loss wiped master_schedule.
// last_run=1 means "never ran" — elapsed will be huge → will trigger on next cycle.
foreach (['gdrive_backup','maintenance','bidal_summary','staff_jobs',
          'cash_carry_remind','cashbook_summary','cashbook_reconcile',
          'wallet_sync','leads'] as $_m_seedJob) {
    if (!isset($_m_schedule[$_m_seedJob])) {
        $_m_schedule[$_m_seedJob] = ['last_run' => 1, 'last_run_at' => 'never', 'duration_ms' => 0];
    }
}

// ── Job definitions ───────────────────────────────────────────────────────────
$_m_walletInterval = max(60, (int)($_m_config['wallet_sync_interval_minutes'] ?? 360) * 60);

$_m_jobs = [
    // ── FAST & FREQUENT (run every cycle) ────────────────────────────────
    'event_processor'=> ['interval' => 30,                  'script' => __DIR__ . '/event_processor.php'],
    'wa_sync'       => ['interval' => 60,                  'script' => dirname(__DIR__) . '/cron_wa_sync.php'],
    'crm_sync'      => ['interval' => 60,                  'script' => dirname(__DIR__) . '/cron_sync.php'],
    // v4.20.0 — Time-based access expiry checker. LAZY-POLL pattern: only
    // hits dr_wifi_get_status for routers that have at least one active
    // grant in hotspot_paid_access. Most customers will have zero rows in
    // this table → cron exits in milliseconds. Customers actively using
    // time-based controls get their devices auto-paused at expiry.
    'paid_access'   => ['interval' => 60,                  'script' => dirname(__DIR__) . '/cron_paid_access.php'],

    // v4.21.93: quote_wa promoted to FAST tier so customer-facing PDF receipts/
    // quotes/invoices never starve from budget exhaustion. Job is cheap when
    // queue is empty (single SELECT) and naturally rate-limited internally.
    'quote_wa'      => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_quote_wa.php'],

    // ── MEDIUM (every 5 minutes) ─────────────────────────────────────────
    'crm_pay_retry' => ['interval' => 300,                 'script' => __DIR__ . '/crm_payment_retry.php'],
    'kyc_crm_sync'  => ['interval' => 300,                 'script' => __DIR__ . '/kyc_crm_sync.php'],
    'pay_catchup'   => ['interval' => 300,                 'script' => __DIR__ . '/payment_catchup_sync.php'],
    'crm_pay_gap'   => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_crm_payment_gap.php'],
    'splynx_sync'   => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_splynx_sync.php'],
    // 'job_assign' disabled — UCRM sends its own job assignment WhatsApp natively.
    // Plugin duplicate was sending a second message to technicians. Re-enable here if
    // UCRM native notifications are turned off in UCRM → Settings → Notifications.
    // 'job_assign' => ['interval' => 300, 'script' => __DIR__ . '/job_assignment_notify.php'],
    // ── AI WhatsApp replies (Sudan edition) ──────────────────────────────
    // Drains the ai.reply queue. This is the SAFETY NET: evo_webhook.php spawns
    // a worker the moment a message arrives, so replies are normally immediate.
    // This catches anything stranded when that spawn is unavailable.
    'ai_reply'      => ['interval' => 60,                  'script' => dirname(__DIR__) . '/run_worker.php'],

    // ── Website chat retention ───────────────────────────────────────────
    // Deletes leads and transcripts past web_chat_retention_days (default 90).
    // Daily is often enough for a retention period measured in months, and the
    // normal outcome is that it deletes nothing.
    'web_chat_retention' => ['interval' => 86400,          'script' => __DIR__ . '/web_chat_retention.php'],

    // ── Unanswered-customer watchdog ─────────────────────────────────────
    // Ported from the South Sudan bot. Every 15 minutes in business hours:
    // if the last word in any conversation is the customer's and it has sat
    // longer than the patience window, the alert number hears about it once.
    'wa_watchdog'   => ['interval' => 900,                 'script' => __DIR__ . '/wa_watchdog.php'],

    // 'wa_bot' disabled in the Sudan edition — superseded by the AI brain.
    // cron_wa_bot.php polls the WASender inbox and auto-replies through
    // WaAutoReplyService. Running it alongside the AI would answer the same
    // customer twice. It is inert here anyway (WASender is not configured),
    // but leaving it registered invites exactly that mistake later.
    // 'wa_bot'     => ['interval' => 60, 'script' => dirname(__DIR__) . '/cron_wa_bot.php'],
    'evo_sync'      => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_evo_sync.php'],
    'jobs_cache'    => ['interval' => 600,                 'script' => __DIR__ . '/jobs_cache.php'],
    'photo_retry'   => ['interval' => 600,                 'script' => __DIR__ . '/photo_retry.php'],
    'inv_notify'    => ['interval' => 900,                 'script' => dirname(__DIR__) . '/cron_invoice_notify.php'],
    'overdue_email' => ['interval' => 86400, 'run_hour' => 9, 'run_dow' => 1, 'script' => dirname(__DIR__) . '/cron_overdue_email.php'],

    // ── DAILY BACKUP — placed BEFORE LTE/heavy jobs so budget is never exhausted first ──
    // interval=3600 so master.php offers it every hour; cron_gdrive_backup.php itself
    // owns the once-per-day logic (20-hour gap check + schedule config). This replaces
    // the old run_hour=3 single-window gate that LTE jobs always ate the budget for.
    'gdrive_backup'      => ['interval' => 3600,                   'script' => dirname(__DIR__) . '/cron_gdrive_backup.php'],

    // ── SLOW (LTE sync can take 10s+ — run LAST so it doesn't block others)
    'lte_sync'      => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_lte_sync.php'],
    'lte_usage'     => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_lte_usage.php'],
    'lte_cron'      => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_lte.php'],

    // ── DAILY (hour-gated) ──────────────────────────────────────────────────
    'bidal_summary'      => ['interval' => 86400, 'run_hour' => 7,  'script' => __DIR__ . '/bidal_summary.php'],
    'staff_jobs'         => ['interval' => 86400, 'run_hour' => 7,  'script' => __DIR__ . '/staff_jobs_summary.php'],
    'cash_carry_remind'  => ['interval' => 86400, 'run_hour' => 8,  'script' => __DIR__ . '/cash_carry_reminder.php'],
    'cashbook_summary'   => ['interval' => 86400, 'run_hour' => 18, 'script' => __DIR__ . '/cashbook_summary.php'],
    'staff_ssp_report'   => ['interval' => 86400, 'run_hour' => 18, 'script' => __DIR__ . '/staff_ssp_report.php'],
    'cashbook_reconcile' => ['interval' => 86400, 'run_hour' => 23, 'script' => __DIR__ . '/cashbook_reconcile.php'],
    'maintenance'        => ['interval' => 86400, 'run_hour' => 2,  'script' => dirname(__DIR__) . '/cron_maintenance.php'],

    // ── SLOW (these can take 30s+ — run LAST) ────────────────────────────
    'fiber_sync'    => ['interval' => max(300, (int)($_m_config['fiber_sync_interval_minutes'] ?? 60) * 60), 'script' => dirname(__DIR__) . '/cron_fiber_sync.php'],

    // ── INFREQUENT ───────────────────────────────────────────────────────
    'wallet_sync'   => ['interval' => $_m_walletInterval,  'script' => dirname(__DIR__) . '/cron_wallet_sync.php'],
    'leads'         => ['interval' => 14400,               'script' => dirname(__DIR__) . '/cron_leads.php'],
    'lead_alerts'   => ['interval' => 300,                 'script' => dirname(__DIR__) . '/cron_lead_alerts.php'],

    // ── v4.21.0: Starlink auto-block retry queue ────────────────────────
    // Picks up rows in suspending/partial_suspend_failed/restoring state
    // and retries from the last successful checkpoint. Cheap when queue
    // is empty (single SELECT). After 5 attempts, alerts admin via WA.
    'sl_block_retry' => ['interval' => 600,                'script' => dirname(__DIR__) . '/cron_starlink_block_retry.php'],

    // v4.21.41: Removed sl_extend_pauses + sl_full_reconcile crons.
    // Both moved into data-report's cron.php as Phase 4 (block+unblock+
    // extend reconciliation). Owner of Starlink/dish state now lives
    // entirely in data-report; Hybrid just receives webhook notifications
    // and handles app-cache refresh. Single source of truth, no cross-
    // plugin HTTP gymnastics.
];

$_m_ran = [];

foreach ($_m_jobs as $_m_name => $_m_job) {
    // ── Budget guard: stop dispatching if we've exceeded the time budget ──
    if (time() > $_m_deadline) {
        master_log("BUDGET EXCEEDED after " . (time() - $_m_now) . "s — deferring remaining jobs");
        break;
    }

    $_m_scriptPath = $_m_job['script'];

    // Skip if script doesn't exist
    if (!file_exists($_m_scriptPath)) {
        master_log("SKIP {$_m_name} — script not found: {$_m_scriptPath}");
        continue;
    }

    $_m_lastRun  = (int)($_m_schedule[$_m_name]['last_run'] ?? 0);
    $_m_elapsed  = (int)$_m_now - (int)$_m_lastRun;
    $_m_interval = (int)$_m_job['interval'];

    // Hour-restricted jobs (maintenance, bidal_summary) — only run at their hour
    if (isset($_m_job['run_hour'])) {
        $_m_targetHour = (int)$_m_job['run_hour'];
        if ($_m_hour !== $_m_targetHour) continue;
        // Day-of-week gate (0=Sunday, 1=Monday ... 6=Saturday)
        if (isset($_m_job['run_dow']) && (int)date('w') !== (int)$_m_job['run_dow']) continue;
        if ($_m_elapsed < 3600) continue;
    } else {
        if ($_m_elapsed < $_m_interval) continue;
    }

    master_log("RUN {$_m_name}");
    $_m_start = microtime(true);

    // Per-job PHP time limit — prevents any single job from hanging forever.
    // Heavy jobs get 120s, gdrive_backup gets 600s (36MB upload over Juba), others 60s.
    $_m_heavy = in_array($_m_name, ['lte_sync','lte_cron','lte_usage','evo_sync','maintenance','fiber_sync','cashbook_reconcile','gdrive_backup']);
    $_m_tlimit = ($_m_name === 'gdrive_backup') ? 600 : ($_m_heavy ? 120 : 60);
    @set_time_limit($_m_tlimit);

    try {
        include $_m_scriptPath;
    } catch (\Throwable $e) {
        master_log("ERROR {$_m_name}: " . $e->getMessage());
    }

    $_m_elapsed_ms = round((microtime(true) - $_m_start) * 1000);
    master_log("DONE {$_m_name} in {$_m_elapsed_ms}ms");

    // Warn on slow jobs (>60s) — helps identify what's eating the budget
    if ($_m_elapsed_ms > 60000) {
        master_log("⚠ SLOW JOB: {$_m_name} took " . round($_m_elapsed_ms/1000) . "s — consider increasing interval or optimizing");
    }

    $_m_schedule[$_m_name] = [
        'last_run'    => $_m_now,
        'last_run_at' => date('Y-m-d H:i:s'),
        'duration_ms' => $_m_elapsed_ms,
    ];
    $_m_ran[] = $_m_name;

    // Save after EACH job — protects against later script crashing or clobbering $store
    $_m_store->save('master_schedule.json', $_m_schedule);
}

// ── Release lock ──────────────────────────────────────────────────────────────
flock($lockFp, LOCK_UN);
fclose($lockFp);

if (!empty($_m_ran)) {
    $_m_total_sec = round(time() - $_m_now);
    master_log("Cycle complete in {$_m_total_sec}s. Jobs run: " . implode(', ', $_m_ran));
}

function master_log(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [master] ' . $msg . "\n";
}
