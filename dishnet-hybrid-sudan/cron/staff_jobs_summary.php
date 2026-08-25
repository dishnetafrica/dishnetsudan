#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/staff_jobs_summary.php — DishNet Hybrid Telecom
 *
 * Purpose:
 *   Sends each active DishNet staff member a personalised WhatsApp morning
 *   brief listing their UCRM scheduling jobs for today (and any overdue
 *   open/pending jobs from previous days).
 *
 *   Replaces the manual "Hi Rupesh, here are your jobs" daily message.
 *
 * Runs at: 07:30 EAT daily (30 min after Bidal's summary)
 * Called by: cron/master.php
 * Or manually: php cron/staff_jobs_summary.php
 *
 * Requirements:
 *   - retailer must have: role=support|support_leader|admin, is_active=true,
 *     phone set, ucrm_user_id set
 *   - CRM API must be configured (crm_base_url + crm_app_key in kyc_config)
 *   - WA sender must be configured (wa_plugin_url etc)
 *
 * If a staff member has no ucrm_user_id, a single admin alert is sent
 * listing all unmapped staff (so admin knows to fix it).
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';

require_once dirname(__DIR__) . '/lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));
$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);

// ── CRM client ────────────────────────────────────────────────────────────
$crm = new CrmApiClient($config);
if (!$crm->isConfigured()) {
    log_msg('CRM not configured — cannot fetch scheduling jobs. Exiting.');
    return;
}

$todayStr = date('Y-m-d');
$nowTs    = time();

// ── Load all retailers ────────────────────────────────────────────────────
$allRetailers = $store->load('retailers.json') ?? [];

// Staff eligible for job notifications
$staff = array_filter($allRetailers, fn($r) =>
    in_array($r['role'] ?? 'sales', ['support', 'support_leader', 'admin']) &&
    !empty($r['is_active']) &&
    empty($r['on_leave']) &&
    !empty($r['phone'])
);

if (empty($staff)) {
    log_msg('No active support staff found — exiting.');
    return;
}

// ── Track unmapped staff for admin alert ─────────────────────────────────
$unmapped = [];

// ── Send each staff member their jobs ────────────────────────────────────
foreach ($staff as $person) {
    $name        = $person['name'] ?? 'Staff';
    $phone       = preg_replace('/[^0-9+]/', '', $person['phone'] ?? '');
    $ucrmUserId  = (int)($person['ucrm_user_id'] ?? 0);

    if (!$ucrmUserId) {
        $unmapped[] = $name;
        log_msg("Skipping {$name} — no ucrm_user_id set");
        continue;
    }

    // Fetch today's jobs assigned to this user from UCRM
    // Also fetch overdue open/pending from last 7 days
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    // UCRM status codes: 0=Pending, 1=Open, 2=Closed
    $qs = "?assigneeId={$ucrmUserId}&limit=100"
        . "&statuses[]=0&statuses[]=1"
        . "&dateFrom={$sevenDaysAgo}&dateTo={$todayStr}";

    $jobs = $crm->get('scheduling/jobs' . $qs);

    if ($jobs === null) {
        log_msg("CRM API error for {$name}: " . json_encode($crm->getLastError()));
        continue;
    }

    if (!is_array($jobs)) $jobs = [];

    // Split: today vs overdue
    $todayJobs   = [];
    $overdueJobs = [];

    foreach ($jobs as $j) {
        $jDate = substr($j['date'] ?? '', 0, 10);
        $jStatus = (int)($j['status'] ?? 1);
        if ($jDate === $todayStr) {
            $todayJobs[] = $j;
        } elseif ($jDate < $todayStr && $jStatus !== 2) {
            $overdueJobs[] = $j;
        }
    }

    // Sort today's jobs by time
    usort($todayJobs, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));

    $countToday   = count($todayJobs);
    $countOverdue = count($overdueJobs);
    // 0=Pending, 1=Open, 2=Closed
    $countPending = count(array_filter($todayJobs, fn($j) => (int)($j['status'] ?? 1) === 0));
    $countOpen    = count(array_filter($todayJobs, fn($j) => (int)($j['status'] ?? 1) === 1));
    $countInProg  = 0; // covered by pending/open

    $date = date('D d M Y');
    $firstName = explode(' ', $name)[0];

    // Build message
    $msg  = "📋 *Daily Jobs Update*\n";
    $msg .= "Hi {$firstName} DishNet,\n";
    $msg .= "Here are your jobs for today ({$date}):\n\n";

    if ($countToday === 0 && $countOverdue === 0) {
        $msg .= "✅ No jobs scheduled for today.\n";
        $msg .= "Have a great day! 😊\n";
    } else {
        if ($countPending > 0) $msg .= "⏳ Pending Jobs: {$countPending}\n";
        if ($countOpen    > 0) $msg .= "🟡 New/Open Jobs: {$countOpen}\n";
        // In Progress no longer a separate status in UCRM numeric codes
        $msg .= "📦 Total Today: {$countToday}\n";

        if ($countOverdue > 0) {
            $msg .= "⚠️ Overdue (not closed): {$countOverdue}\n";
        }

        // List today's jobs (max 8, keep it readable on WhatsApp)
        if ($countToday > 0) {
            $msg .= "\n*Today's Schedule:*\n";
            $shown = array_slice($todayJobs, 0, 8);
            foreach ($shown as $j) {
                $time    = date('h:i A', strtotime($j['date'] ?? ''));
                $title   = $j['title'] ?? 'Job';
                $client  = $j['client']['name'] ?? '';
                $status  = ucfirst(strtolower($j['status'] ?? 'open'));
                $statusCode = (int)($j['status'] ?? 1);
                switch ($statusCode) {
                    case 0:  $statusEmoji = '⏳'; break;
                    case 1:  $statusEmoji = '🟡'; break;
                    case 2:  $statusEmoji = '✅'; break;
                    default: $statusEmoji = '📌';
                }
                $msg .= "{$statusEmoji} {$time} — {$title}";
                if ($client) $msg .= " ({$client})";
                $msg .= "\n";
            }
            if ($countToday > 8) {
                $msg .= "  _(+ " . ($countToday - 8) . " more — see full list below)_\n";
            }
        }

        if ($countOverdue > 0) {
            $msg .= "\n⚠️ *Overdue jobs needing attention:*\n";
            foreach (array_slice($overdueJobs, 0, 3) as $j) {
                $jDate  = date('d M', strtotime($j['date'] ?? ''));
                $title  = $j['title'] ?? 'Job';
                $client = $j['client']['name'] ?? '';
                $msg .= "  • {$jDate}: {$title}";
                if ($client) $msg .= " ({$client})";
                $msg .= "\n";
            }
            if ($countOverdue > 3) {
                $msg .= "  _(+ " . ($countOverdue - 3) . " more overdue)_\n";
            }
        }

        $msg .= "\n🔍 *Full details & updates:*\n";
        $msg .= "🔗 https://crm.dishnetafrica.com/_plugins/dishnet-hybrid-telecom/public.php?tab=scheduling\n";
        $msg .= "\nPlease start with pending jobs and work through your list systematically.\n";
        $msg .= "Have a productive day! 🛠\n";
        $msg .= "Need support? 📞 +211 921 443 002\n";
        $msg .= "– DishNET Operations Team";
    }

    // Send via WASender (support channel)
    $notify->sendRaw($phone, $msg, 'staff_jobs_summary');

    log_msg("Sent to {$name} ({$phone}) — today:{$countToday} overdue:{$countOverdue}");

    // Small delay between sends to avoid rate limiting
    usleep(500000); // 0.5 seconds
}

// ── Alert admin about unmapped staff ─────────────────────────────────────
if (!empty($unmapped)) {
    $names = implode(', ', $unmapped);
    $adminMsg = "⚠️ *Staff Jobs Summary — Mapping Alert*\n\n"
        . "The following staff members have no UCRM User ID set and "
        . "did NOT receive today's job summary:\n\n"
        . implode("\n", array_map(fn($n) => "  • {$n}", $unmapped)) . "\n\n"
        . "Fix: Plugin → Manage Retailers → Edit each person → set UCRM User ID.\n"
        . "Find IDs at: crm.dishnetafrica.com/nms/settings/users";
    $notify->sendAdmin($adminMsg, 'staff_jobs_unmapped_alert');
    log_msg("Admin alert sent — unmapped staff: {$names}");
}

log_msg('Staff jobs summary cron complete.');

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [staff_jobs_summary] ' . $msg . "\n";
}
