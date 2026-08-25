#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/bidal_summary.php — DishNet Hybrid Telecom
 *
 * Purpose:
 *   Sends a morning WhatsApp summary to the support leader (Bidal) at 07:00 EAT.
 *   Covers FTTH install pipeline status so Bidal starts the day fully briefed.
 *
 * Message includes:
 *   - Pending jobs (not yet assigned)
 *   - Unassigned jobs (assigned_engineer_id empty)
 *   - In-progress jobs
 *   - Jobs in testing (waiting for Bidal approval)
 *   - Installs completed today
 *   - Installs completed this week
 *
 * Called by: cron/master.php at 07:00 EAT daily
 * Or manually: php cron/bidal_summary.php
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);

// Find support leader (Bidal)
$retailers = $store->load('retailers.json') ?? [];
$leader    = null;
foreach ($retailers as $r) {
    if (($r['role'] ?? '') === 'support_leader' && !empty($r['phone'])) {
        $leader = $r;
        break;
    }
}

if (!$leader) {
    log_msg('No support_leader with phone found — skipping');
    return;
}

// Load FTTH tickets and compute stats
$tickets       = $store->load('splynx_tickets.json') ?? [];
$todayStr      = date('Y-m-d');
$weekAgoStr    = date('Y-m-d', strtotime('-7 days'));

$pending         = 0;
$unassigned      = 0;
$inProgress      = 0;
$testing         = 0;
$completedToday  = 0;
$completedWeek   = 0;

foreach ($tickets as $t) {
    $status  = $t['status']          ?? 'pending';
    $testing_status = $t['testing_status'] ?? '';
    $engId   = $t['assigned_engineer_id'] ?? '';
    $doneAt  = substr($t['completed_at'] ?? '', 0, 10);

    switch ($status) {
        case 'pending':
            $pending++;
            if (!$engId) $unassigned++;
            break;
        case 'in_progress':
            $inProgress++;
            if ($testing_status === 'ready') $testing++;
            break;
        case 'completed':
        case 'approved':
            if ($doneAt === $todayStr)  $completedToday++;
            if ($doneAt >= $weekAgoStr) $completedWeek++;
            break;
    }
}

$notify->bidalMorningSummary($leader, [
    'pending'         => $pending,
    'unassigned'      => $unassigned,
    'in_progress'     => $inProgress,
    'testing'         => $testing,
    'completed_today' => $completedToday,
    'completed_week'  => $completedWeek,
]);

log_msg("Morning summary sent to {$leader['name']} ({$leader['phone']}) — pending:{$pending} testing:{$testing} done_today:{$completedToday}");

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [bidal_summary] ' . $msg . "\n";
}
