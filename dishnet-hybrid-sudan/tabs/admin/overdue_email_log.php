<?php
// ── Admin: Overdue Email Log ──────────────────────────────────────────────────
if (!$isAdmin && ($retailer['role'] ?? '') !== 'accountant') {
    echo '<div style="color:red;padding:20px;">Access denied.</div>'; return;
}

$pdo = $store->getPdo();

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS overdue_email_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL, client_id INTEGER NOT NULL DEFAULT 0,
        client_name TEXT NOT NULL DEFAULT '', client_email TEXT NOT NULL DEFAULT '',
        stage INTEGER NOT NULL DEFAULT 1, stage_label TEXT NOT NULL DEFAULT '',
        amount_due REAL NOT NULL DEFAULT 0, days_overdue INTEGER NOT NULL DEFAULT 0,
        sent_at TEXT NOT NULL DEFAULT (datetime('now')), sent_by TEXT NOT NULL DEFAULT 'cron',
        success INTEGER NOT NULL DEFAULT 1, error TEXT
    )");
} catch (\Throwable $e) {}

// ── Status file path (single source of truth for cron run state) ────────────
// Persisted JSON: {started_at, finished_at?, output: "...", running: bool, run_id}
$cronStatusFile = $dataDir . '/overdue_cron_status.json';

// ── Manual send trigger (BACKGROUND) ─────────────────────────────────────────
// v4.21.73: cron runs in BACKGROUND via fastcgi_finish_request + ignore_user_abort
// (same pattern webhook.php uses for slow PDF fetches). The HTTP response is
// flushed back to the browser immediately, the cron continues server-side, and
// the page polls overdue_cron_status.json every few seconds to show live
// progress. No more 3-minute UI freeze on a 500-invoice batch.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_run'])) {
    // Reject overlapping runs — if one is already in flight, just redirect.
    $existing = file_exists($cronStatusFile)
        ? (json_decode((string)@file_get_contents($cronStatusFile), true) ?: [])
        : [];
    $alreadyRunning = !empty($existing['running'])
        && !empty($existing['started_at'])
        && (time() - (int)$existing['started_at']) < 1800; // 30min watchdog
    if ($alreadyRunning) {
        header('Location: ?page=dashboard&tab=overdue_email_log&ran=existing');
        exit;
    }

    $runId = uniqid('owe_', true);
    $startTs = time();

    // Initial status file — page renders "Starting…" immediately
    @file_put_contents($cronStatusFile, json_encode([
        'run_id'      => $runId,
        'started_at'  => $startTs,
        'finished_at' => null,
        'running'     => true,
        'output'      => "[" . date('H:i:s') . "] Starting cron in background...\n",
        'sent'        => 0,
        'errors'      => 0,
    ], JSON_PRETTY_PRINT));

    // Flush "redirect" response to the browser, then keep running.
    ignore_user_abort(true);
    @set_time_limit(1800); // 30 minutes — long enough for 500-row batch
    http_response_code(302);
    header('Location: ?page=dashboard&tab=overdue_email_log&ran=1&run=' . urlencode($runId));
    header('Connection: close');
    header('Content-Length: 0');
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Fallback for non-FPM: explicit flush
        @ob_end_flush();
        @flush();
    }

    // From here on, the user is gone. Run the cron and capture output.
    // Tag the run_id into GLOBALS so the cron's olog() can stamp status writes.
    $GLOBALS['_overdue_cron_run_id'] = $runId;
    ob_start();
    $cronError = null;
    try {
        include dirname(__DIR__, 2) . '/cron_overdue_email.php';
    } catch (\Throwable $e) {
        $cronError = $e->getMessage() . "\n  at " . $e->getFile() . ':' . $e->getLine();
    }
    $cronOutput = ob_get_clean();
    if ($cronError) $cronOutput .= "\n\n=== EXCEPTION ===\n" . $cronError;

    // Final status — page polling will pick this up on the next tick.
    @file_put_contents($cronStatusFile, json_encode([
        'run_id'      => $runId,
        'started_at'  => $startTs,
        'finished_at' => time(),
        'running'     => false,
        'output'      => $cronOutput,
        'duration_s'  => time() - $startTs,
    ], JSON_PRETTY_PRINT));
    exit;
}

// ── Status poll endpoint ─────────────────────────────────────────────────────
// JS calls this every 3s while a run is in progress to refresh the panel.
if (isset($_GET['cron_status']) && $_GET['cron_status'] === '1') {
    header('Content-Type: application/json');
    if (file_exists($cronStatusFile)) {
        echo (string)@file_get_contents($cronStatusFile);
    } else {
        echo json_encode(['running' => false, 'output' => '', 'never_run' => true]);
    }
    exit;
}

// Load latest status for inline render on initial page load
$cronStatus = file_exists($cronStatusFile)
    ? (json_decode((string)@file_get_contents($cronStatusFile), true) ?: [])
    : [];
$cronOutput = (string)($cronStatus['output'] ?? '');
$cronRunning = !empty($cronStatus['running']);
$cronRunId   = (string)($cronStatus['run_id'] ?? '');

// ── Load log ──────────────────────────────────────────────────────────────────
try {
    $log = $pdo->query(
        "SELECT * FROM overdue_email_log ORDER BY sent_at DESC LIMIT 200"
    )->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $log = []; }

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalSent    = count(array_filter($log, fn($r) => $r['success']));
$totalFailed  = count(array_filter($log, fn($r) => !$r['success']));
$totalRevenue = array_sum(array_column(array_filter($log, fn($r) => $r['success']), 'amount_due'));

$stageColors = ['','#2563eb','#2563eb','#0891b2','#d97706','#d97706','#dc2626','#dc2626','#7f1d1d'];
$stageLabels = [
    '',
    'Email #1 — Nudge (14d)',
    'Email #2 — Follow-up (31d)',
    'WhatsApp — Mid-point (45d)',
    'Email #3 — Firm (61d)',
    'WhatsApp — Urgent (75d)',
    'Email #4 — Final (90d)',
    'Email #5 — Suspended (120d)',
    'Email #6 — Last contact (180d)',
];
?>

<div style="max-width:1000px;margin:0 auto;color:#0f172a;">

<?php if ($cronRunning): ?>
<div id="cronRunningBanner" style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#92400e;font-weight:700;display:flex;align-items:center;gap:10px;">
    <span style="display:inline-block;width:14px;height:14px;border:2.5px solid #92400e;border-right-color:transparent;border-radius:50%;animation:owbSpin 0.8s linear infinite;"></span>
    <span>⏳ Cron running in background — sent: <span id="liveSent">0</span> · errors: <span id="liveErrors">0</span> · skipped: <span id="liveSkipped">0</span> <span id="liveDuration" style="color:#a16207;font-weight:500;"></span></span>
</div>
<style>@keyframes owbSpin { to { transform: rotate(360deg); } }</style>
<?php elseif (!empty($_GET['ran']) && $_GET['ran'] === '1'): ?>
<div id="cronFinishedBanner" style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#166534;font-weight:700;">
    ✅ Cron finished. Sent: <?= (int)($cronStatus['sent'] ?? 0) ?> · Errors: <?= (int)($cronStatus['errors'] ?? 0) ?> · Duration: <?= (int)($cronStatus['duration_s'] ?? 0) ?>s.
</div>
<?php elseif (!empty($_GET['ran']) && $_GET['ran'] === 'existing'): ?>
<div style="background:#fef3c7;border:1.5px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#92400e;font-weight:700;">
    ℹ️ A cron run is already in progress. Watching that one.
</div>
<?php endif; ?>

<?php if ($cronOutput): ?>
<details <?= $cronRunning ? 'open' : '' ?> id="cronOutputBlock" style="background:#0f172a;border-radius:10px;padding:0;margin-bottom:16px;color:#e2e8f0;font-family:ui-monospace,'SF Mono',Consolas,monospace;font-size:12px;">
    <summary style="padding:10px 16px;cursor:pointer;font-weight:700;color:#cbd5e1;border-bottom:1px solid #1e293b;">
        📜 Cron output (<span id="cronLineCount"><?= substr_count($cronOutput, "\n") ?></span> lines) — <span id="cronStateLabel"><?= $cronRunning ? 'live' : 'click to collapse' ?></span>
    </summary>
    <pre id="cronOutputPre" style="padding:14px 16px;margin:0;white-space:pre-wrap;word-break:break-word;line-height:1.5;max-height:400px;overflow-y:auto;color:#e2e8f0;"><?= htmlspecialchars($cronOutput) ?></pre>
</details>
<?php endif; ?>

<?php if ($cronRunning): ?>
<script>
(function () {
    const startedAt = <?= (int)($cronStatus['started_at'] ?? time()) ?>;
    let lastLines = <?= (int)substr_count($cronOutput, "\n") ?>;

    function fmtDuration(s) {
        if (s < 60) return s + 's';
        const m = Math.floor(s / 60);
        const r = s % 60;
        return m + 'm ' + r + 's';
    }

    function tick() {
        const dur = Math.max(0, Math.floor(Date.now() / 1000) - startedAt);
        const dEl = document.getElementById('liveDuration');
        if (dEl) dEl.textContent = '· running ' + fmtDuration(dur);
    }
    tick();
    const tickIv = setInterval(tick, 1000);

    async function poll() {
        try {
            const r = await fetch('?page=dashboard&tab=overdue_email_log&cron_status=1&_=' + Date.now(), { cache: 'no-store' });
            const j = await r.json();

            // Update live counters
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val ?? 0; };
            setText('liveSent',    j.sent ?? 0);
            setText('liveErrors',  j.errors ?? 0);
            setText('liveSkipped', j.skipped ?? 0);

            // Update output pre + line count
            const pre = document.getElementById('cronOutputPre');
            if (pre && j.output) {
                if (pre.textContent !== j.output) {
                    pre.textContent = j.output;
                    pre.scrollTop = pre.scrollHeight;
                    const lines = (j.output.match(/\n/g) || []).length;
                    const lc = document.getElementById('cronLineCount');
                    if (lc) lc.textContent = lines;
                }
            }

            if (!j.running) {
                // Reload the page to flip into the "finished" state and refresh stats
                clearInterval(tickIv);
                clearInterval(pollIv);
                window.location.href = '?page=dashboard&tab=overdue_email_log&ran=1';
            }
        } catch (e) {
            console.warn('cron status poll failed:', e);
        }
    }
    const pollIv = setInterval(poll, 3000);
    poll(); // immediate first call
})();
</script>
<?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
    <div>
        <h2 style="margin:0;font-size:20px;font-weight:800;color:#0f172a;">📧 Overdue Invoice Emails</h2>
        <div style="font-size:13px;color:#475569;margin-top:4px;font-weight:500;">Runs every Monday at 9:00 AM · Full chain Day 14 → Day 210+ monthly</div>
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="manual_run" value="1">
        <button type="submit" style="background:#1d4ed8;color:#fff;border:none;border-radius:8px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;">
            ▶ Run Now
        </button>
    </form>
</div>

<!-- Stats row -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:20px;">
    <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:16px;">
        <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Emails Sent</div>
        <div style="font-size:28px;font-weight:900;color:#059669;margin-top:4px;"><?= $totalSent ?></div>
    </div>
    <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:16px;">
        <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Failed</div>
        <div style="font-size:28px;font-weight:900;color:<?= $totalFailed > 0 ? '#dc2626' : '#94a3b8' ?>;margin-top:4px;"><?= $totalFailed ?></div>
    </div>
    <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:16px;">
        <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Total Chased</div>
        <div style="font-size:28px;font-weight:900;color:#0f172a;margin-top:4px;">$<?= number_format($totalRevenue, 0) ?></div>
    </div>
</div>

<!-- Full chain legend -->
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:16px;font-size:12px;">
    <div style="font-weight:700;color:#0f172a;margin-bottom:8px;font-size:13px;">📋 Full follow-up chain (UCRM handles Day 1 & 7)</div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
        <span style="background:#e2e8f0;color:#334155;border-radius:5px;padding:3px 9px;font-weight:600;">Day 1: UCRM email ✅</span>
        <span style="background:#e2e8f0;color:#334155;border-radius:5px;padding:3px 9px;font-weight:600;">Day 7: WhatsApp ✅</span>
        <?php
        $chainStages = [
            [1,'#2563eb','Day 14: Email #1'],
            [2,'#2563eb','Day 31: Email #2'],
            [3,'#0891b2','Day 45: WhatsApp'],
            [4,'#d97706','Day 61: Email #3'],
            [5,'#d97706','Day 75: WhatsApp'],
            [6,'#dc2626','Day 90: Email #4'],
            [7,'#dc2626','Day 120: Email #5'],
            [8,'#7f1d1d','Day 180: Email #6'],
            [9,'#475569','Day 210+: Monthly'],
        ];
        foreach ($chainStages as [$sid, $col, $lbl]):
        ?>
        <span style="background:<?= $col ?>15;color:<?= $col ?>;border:1px solid <?= $col ?>40;border-radius:5px;padding:3px 9px;font-weight:700;"><?= $lbl ?></span>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($log)): ?>
<div style="text-align:center;padding:60px;background:#fff;border-radius:16px;border:1px solid #e2e8f0;">
    <div style="font-size:48px;">📧</div>
    <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:12px;">No emails sent yet</div>
    <div style="font-size:13px;color:#475569;margin-top:4px;">Runs automatically every Monday at 9 AM, or click Run Now above.</div>
</div>
<?php else: ?>
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;">
<table style="width:100%;border-collapse:collapse;font-size:13px;">
<thead>
<tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
    <th style="padding:10px 14px;text-align:left;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Sent</th>
    <th style="padding:10px 14px;text-align:left;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Client</th>
    <th style="padding:10px 14px;text-align:left;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Invoice</th>
    <th style="padding:10px 14px;text-align:right;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Amount</th>
    <th style="padding:10px 14px;text-align:center;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Stage</th>
    <th style="padding:10px 14px;text-align:center;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Days</th>
    <th style="padding:10px 14px;text-align:center;font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Status</th>
</tr>
</thead>
<tbody>
<?php foreach ($log as $r): ?>
<tr style="border-bottom:1px solid #f1f5f9;">
    <td style="padding:10px 14px;color:#64748b;font-size:12px;"><?= htmlspecialchars(substr($r['sent_at'],0,16)) ?></td>
    <td style="padding:10px 14px;">
        <div style="font-weight:700;color:#1e293b;"><?= htmlspecialchars($r['client_name']) ?></div>
        <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($r['client_email']) ?></div>
    </td>
    <td style="padding:10px 14px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($r['invoice_number']) ?></td>
    <td style="padding:10px 14px;text-align:right;font-weight:700;color:#dc2626;">$<?= number_format($r['amount_due'],2) ?></td>
    <td style="padding:10px 14px;text-align:center;">
        <span style="background:<?= $stageColors[$r['stage']] ?? '#94a3b8' ?>20;color:<?= $stageColors[$r['stage']] ?? '#94a3b8' ?>;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:700;">
            Stage <?= (int)$r['stage'] ?>
        </span>
    </td>
    <td style="padding:10px 14px;text-align:center;color:#64748b;"><?= (int)$r['days_overdue'] ?>d</td>
    <td style="padding:10px 14px;text-align:center;">
        <?php if ($r['success']): ?>
            <span style="color:#059669;font-weight:700;">✅ Sent</span>
        <?php else: ?>
            <span style="color:#dc2626;font-weight:700;" title="<?= htmlspecialchars($r['error']??'') ?>">❌ Failed</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
