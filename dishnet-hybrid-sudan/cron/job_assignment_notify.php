#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * cron/job_assignment_notify.php — DishNet Hybrid Telecom
 *
 * Polls UCRM scheduling jobs every 5 minutes. Sends WhatsApp notification
 * to a technician when a job is newly assigned or reassigned to them.
 *
 * Dual-pool identity:
 *   assignedUserId      → matched against ucrm_user_id       (CRM admin user pool)
 *   assignees[].userId  → matched against ftth_crm_client_id (CRM client pool)
 *
 * Runs at: every 5 minutes via cron/master.php
 */

chdir(dirname(__DIR__));

require_once __DIR__ . '/../lib/StoreInterface.php';
require_once __DIR__ . '/../lib/JsonStore.php';
require_once __DIR__ . '/../lib/SqliteStore.php';
require_once __DIR__ . '/../lib/NotificationService.php';
require_once __DIR__ . '/../lib/CrmApiClient.php';

require_once __DIR__ . '/../lib/bootstrap_data.php';
$dataDir = getDataDir(dirname(__DIR__));

// ── Lock file to prevent concurrent runs ─────────────────────────────────
$lockFile = $dataDir . '/job_assignment_notify.lock';
$lockFp = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another instance is running — skip silently
    fclose($lockFp);
    return;
}
// Lock acquired — continue

$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);
$crm     = CrmApiClient::fromUcrm(dirname(__DIR__), $config);

if (!$crm->isConfigured()) {
    log_ja('CRM not configured — skipping.');
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}

// ── Build dual identity maps from live retailers.json ────────────────────
// adminMap  : int ucrm_user_id       => retailer  (assignedUserId pool)
// clientMap : int ftth_crm_client_id => retailer  (assignees[].userId pool)
$allRetailers = $store->load('retailers.json') ?? [];
$adminMap     = [];
$clientMap    = [];

foreach ($allRetailers as $r) {
    if (empty($r['is_active']) || empty($r['phone'])) continue;

    $adminId  = (int)($r['ucrm_user_id']       ?? 0);
    $clientId = (int)($r['ftth_crm_client_id'] ?? 0);

    if ($adminId  > 0) $adminMap[$adminId]   = $r;
    if ($clientId > 0) $clientMap[$clientId] = $r;
}

if (empty($adminMap) && empty($clientMap)) {
    log_ja('No staff with ucrm_user_id or ftth_crm_client_id mapped — skipping.');
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}

// ── Load seen snapshot ────────────────────────────────────────────────────
$seen = $store->load('job_assignments_seen.json') ?? [];

// ── Fetch ALL jobs in one request — UCRM API ignores all assignee filter params ──
$today     = date('Y-m-d');
$lookAhead = date('Y-m-d', strtotime('+30 days'));
$qs        = "?limit=500&statuses[]=0&statuses[]=1&dateFrom={$today}&dateTo={$lookAhead}";
$rawJobs   = $crm->get('scheduling/jobs' . $qs);

if (!is_array($rawJobs)) {
    log_ja('CRM API error: ' . json_encode($crm->getLastError()));
    flock($lockFp, LOCK_UN); fclose($lockFp);
    return;
}

// Index by job id
$allJobs = [];
foreach ($rawJobs as $j) {
    $jid = (int)($j['id'] ?? 0);
    if ($jid) $allJobs[$jid] = $j;
}

// ── resolveAssignee — match job assignedUserId against staffMap ──────────
function resolveAssignee(array $job, array $adminMap, array $clientMap): ?array
{
    // UCRM only returns assignedUserId — no assignees[] array on list endpoint
    $uid = (int)($job['assignedUserId'] ?? 0);
    if ($uid > 0 && isset($adminMap[$uid])) {
        return ['retailer' => $adminMap[$uid], 'type' => 'admin', 'id' => $uid];
    }
    return null; // not our staff — skip
}

// ── Process jobs ──────────────────────────────────────────────────────────
$newNotifications = 0;

foreach ($allJobs as $jobId => $job) {
    $resolved = resolveAssignee($job, $adminMap, $clientMap);
    if (!$resolved) continue;

    $retailer   = $resolved['retailer'];
    $name       = $retailer['name'] ?? 'Technician';
    $phone      = preg_replace('/[^0-9+]/', '', $retailer['phone'] ?? '');
    if (empty($phone)) continue;

    $jobTitle   = $job['title']  ?? "Job #{$jobId}";
    $jobDate    = substr($job['date'] ?? '', 0, 10);
    $jobStatus  = (int)($job['status'] ?? 0); // 0=draft, 1=open/accepted
    $clientName = trim(($job['client']['firstName'] ?? '') . ' ' . ($job['client']['lastName'] ?? ''));
    $clientId   = (int)($job['client']['id'] ?? 0);
    $address    = trim($job['address'] ?? '');

    $seenKey    = (string)$jobId;
    $prevId     = (int)($seen[$seenKey]['assignee_id']   ?? 0);
    $prevType   = (string)($seen[$seenKey]['assignee_type'] ?? '');
    $prevStatus = (int)($seen[$seenKey]['status'] ?? -1);

    $isNew        = !isset($seen[$seenKey]);
    $isReassigned = !$isNew && ($prevId !== $resolved['id'] || $prevType !== $resolved['type']);
    $isAccepted   = !$isNew && $prevStatus === 0 && $jobStatus === 1; // status changed from draft to open

    // Skip if nothing changed
    if (!$isNew && !$isReassigned && !$isAccepted) continue;

    $firstName  = explode(' ', $name)[0];
    $dateLabel  = ($jobDate === $today) ? 'Today' : date('D d M', strtotime($jobDate ?: 'today'));
    
    // UCRM job action links
    // Use configured CRM base URL (not hardcoded ucrm.dishnetafrica.com)
    $_crmRaw = rtrim($config['crm_base_url'] ?? 'https://crm.dishnetafrica.com', '/');
    $_crmRaw = preg_replace('#/api/v[0-9.]+$#', '', $_crmRaw);
    $ucrmBase = rtrim($_crmRaw, '/crm') ?: 'https://crm.dishnetafrica.com';
    $ucrmBase = rtrim($ucrmBase, '/');
    $acceptLink    = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
    $completeLink  = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
    $reschedLink   = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
    $commentsLink  = "{$ucrmBase}/crm/scheduling/job/{$jobId}";
    $clientPhone   = $job['clientPhone'] ?? ($job['client']['phone'] ?? '');
    if ($clientId && empty($clientPhone)) {
        // Try to get phone from client data
        $clientPhone = $job['client']['contacts'][0]['phone'] ?? '';
    }

    if ($isAccepted) {
        // Job was accepted (status changed from 0 to 1) → Send completion links
        $msg  = "Hi *{$firstName}*. This is DishNet Africa.\n\n";
        $msg .= "*Thank you for accepting the job!* ✅\n\n";
        $msg .= "*Job Details:*\n";
        $msg .= "🔧 Job Type: *{$jobTitle}*\n";
        $msg .= "📅 Date: *{$dateLabel}*" . ($job['startTime'] ?? '' ? " {$job['startTime']}" : "") . "\n";
        if ($clientName) $msg .= "👤 Client: {$clientName}" . ($clientId ? "(ID:{$clientId})" : "") . "\n";
        if ($clientPhone) $msg .= "📞 Mobile: {$clientPhone}\n";
        if ($address) $msg .= "📍 Address: {$address}\n";
        $msg .= "\n*Quick Actions - Tap Below:*\n\n";
        $msg .= "✅ *JOB COMPLETED:*\n{$completeLink}\n\n";
        $msg .= "🔄 *RESCHEDULE APPOINTMENT:*\n{$reschedLink}\n\n";
        $msg .= "💬 *ADD COMMENTS:*\n{$commentsLink}\n\n";
        $msg .= "---\n";
        $msg .= "*Instructions:*\n";
        $msg .= "• Use RESCHEDULE if customer wants different date/time\n";
        $msg .= "• Use ADD COMMENTS to report any issues or updates\n";
        $msg .= "• Use JOB COMPLETED when work is finished\n\n";
        $msg .= "---\n";
        $msg .= "For urgent support during installation:\n";
        $msg .= "📞 +211 921 443 002\n";
        $msg .= "\n— DishNet Africa Team";
        $notify->sendRaw($phone, $msg, 'ops_scheduling_job_accepted');
        log_ja("Job #{$jobId} accepted → sent completion links to {$name} ({$phone})");
    } elseif ($isNew || $isReassigned) {
        // New or reassigned job → Send ACCEPT link
        $taskCount = count($job['tasks'] ?? []);
        $msg  = "Hi *{$firstName}*. This is DishNet Africa.\n\n";
        $msg .= $isReassigned ? "*Job Has Been Reassigned to You*\n\n" : "*New Job Has Been Assigned to You*\n\n";
        $msg .= "*{$jobTitle}*\n";
        $msg .= "📅 Date: *{$dateLabel}*" . ($job['startTime'] ?? '' ? " {$job['startTime']}" : "") . "\n";
        if ($clientName) $msg .= "👤 Client: {$clientName}" . ($clientId ? "(ID:{$clientId})" : "") . "\n";
        if ($clientPhone) $msg .= "📞 Mobile: {$clientPhone}\n";
        if ($address) $msg .= "📍 Address: {$address}\n";
        if ($job['duration'] ?? '') $msg .= "⏱ Duration: {$job['duration']} mins\n";
        if ($taskCount) $msg .= "📋 Tasks: {$taskCount} task" . ($taskCount > 1 ? 's' : '') . " to complete\n";
        $msg .= "\n---\n";
        $msg .= "*Please click the link below to accept this job:*\n\n";
        $msg .= "✅ *ACCEPT JOB:*\n{$acceptLink}\n\n";
        $msg .= "Once you accept, we will send you the completion links.\n";
        $msg .= "\nFor any questions, just reach out here.\n";
        $msg .= "📞 +211 921 443 002\n";
        $msg .= "🌐 dishnetafrica.com";
        $notify->sendRaw($phone, $msg, $isReassigned ? 'ops_scheduling_job_reassigned' : 'ops_scheduling_job_assigned');
        log_ja(($isReassigned ? "Reassigned" : "New") . " job #{$jobId} → notified {$name} ({$phone}) [{$resolved['type']}:{$resolved['id']}]");
    }

    $seen[$seenKey] = [
        'assignee_id'   => $resolved['id'],
        'assignee_type' => $resolved['type'],  // 'admin' | 'client'
        'title'         => $jobTitle,
        'job_date'      => $jobDate,
        'notified_at'   => date('Y-m-d H:i:s'),
        'status'        => $jobStatus,
    ];

    // ── Notify CUSTOMER that installation is scheduled ────────────────
    // Only for new jobs (not reassignment or status changes)
    // and only if the job has a client with a phone number
    if ($isNew && $clientId > 0) {
        $custPhone = '';
        // Try phone from job payload first
        $custPhone = preg_replace('/[^0-9+]/', '', $clientPhone ?? '');
        // Fallback: fetch from CRM
        if (empty($custPhone)) {
            $custClient = $crm->get("clients/{$clientId}");
            if ($custClient) {
                foreach (($custClient['contacts'] ?? []) as $_cc) {
                    if (!empty($_cc['phone'])) { $custPhone = preg_replace('/[^0-9+]/', '', $_cc['phone']); break; }
                }
            }
        }

        // Check dedup — don't re-notify customer if we already sent for this job
        $custNotifyKey = "cust_job_{$jobId}";
        if ($custPhone && !isset($seen[$custNotifyKey])) {
            $custName = $clientName ?: 'Valued Customer';
            $techFirstName = explode(' ', $name)[0];
            // Detect service type from job title
            $svcName = '';
            $titleLower = strtolower($jobTitle);
            if (strpos($titleLower, 'starlink') !== false) $svcName = 'Starlink';
            elseif (strpos($titleLower, 'fiber') !== false || strpos($titleLower, 'ftth') !== false) $svcName = 'Fiber';
            elseif (strpos($titleLower, 'lte') !== false || strpos($titleLower, '4g') !== false) $svcName = '4G LTE';

            $notify->installationScheduled(
                $custPhone,
                $custName,
                $name,
                $dateLabel,
                $svcName,
                "JOB-{$jobId}"
            );
            $seen[$custNotifyKey] = ['sent_at' => date('Y-m-d H:i:s'), 'job_id' => $jobId];
            log_ja("Customer notified: {$custName} ({$custPhone}) — installation JOB-{$jobId} by {$name} on {$dateLabel}");
        }
    }

    // Save immediately after each notification to prevent duplicates on crash/restart
    $store->save('job_assignments_seen.json', $seen);
    $newNotifications++;
}

// ── Prune old entries (> 60 days) ─────────────────────────────────────────
$cutoff = date('Y-m-d', strtotime('-60 days'));
$pruned = 0;
foreach ($seen as $jobId => $entry) {
    if (($entry['job_date'] ?? '9999') < $cutoff) {
        unset($seen[$jobId]);
        $pruned++;
    }
}

// Save only if pruned (individual notifications already saved immediately)
if ($pruned > 0) {
    $store->save('job_assignments_seen.json', $seen);
}

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);

if ($newNotifications > 0 || $pruned > 0) {
    log_ja("Done — {$newNotifications} notifications sent, {$pruned} old entries pruned.");
} else {
    log_ja('Done — no new assignments.');
}

function log_ja(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] [job_assign_notify] ' . $msg . "\n";
}
