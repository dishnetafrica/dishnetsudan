#!/usr/bin/env php
<?php
// Note: No strict_types - included from master.php
date_default_timezone_set('Africa/Juba');

/**
 * DishNet Hybrid Telecom — Lead Auto-Reassignment Cron
 *
 * Recommended schedule (crontab):
 *   0 (every 4h) php /path/to/cron_leads.php >> /var/log/dishnet_leads.log 2>&1
 *
 * What it does every run:
 *
 * TASK 1 — DRIP ASSIGN (pool → agents)
 *   Looks at all unassigned open leads in the pool.
 *   For each active, non-leave agent that has < 5 active assigned leads,
 *   it top-up their queue to 5 from the pool (round-robin by agent load).
 *   ONE batch WhatsApp summary per agent (not per lead).
 *
 * TASK 2 — STALE REASSIGNMENT
 *   Any lead that has been assigned but NOT updated in 48h gets flagged.
 *   After 72h of no update → reassigned to the next lightest-loaded agent.
 *   Original agent gets a WhatsApp: "X lead(s) reassigned — no update in 72h."
 *   New agent gets the batch summary.
 *
 * TASK 3 — FOLLOW-UP REMINDERS
 *   Leads with follow_up_date = today that haven't been called today
 *   → WhatsApp reminder to assigned agent.
 *
 * Config keys (in kyc_config.json):
 *   lead_drip_size          (int, default 5)  — target active leads per agent
 *   lead_stale_flag_hours   (int, default 48) — hours before flagging
 *   lead_stale_reassign_hours (int, default 72) — hours before reassigning
 *   lead_auto_assign_enabled  (bool, default true)
 */

chdir(__DIR__);

require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/JsonStore.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/NotificationService.php';
require_once __DIR__ . '/lib/bootstrap_data.php';

// ── Single-instance lock ──────────────────────────────────────────────────────
$dataDir  = getDataDir(__DIR__);  // Use UCRM's persistent data directory
$lockFile = $dataDir . '/cron_leads.lock';
$lockFp   = fopen($lockFile, 'w+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) fclose($lockFp);
    return;
}

$store   = SqliteStore::create($dataDir);
$config  = $store->load('kyc_config.json') ?? [];
$notify  = new NotificationService($store, $config);
$started = date('Y-m-d H:i:s');
$now     = time();

clog("══════════════════════════════════════════════");
clog("DishNet Lead Cron — {$started}");
clog("══════════════════════════════════════════════");

// ── Config ────────────────────────────────────────────────────────────────────
$dripSize         = (int)($config['lead_drip_size']            ?? 5);
$flagHours        = (int)($config['lead_stale_flag_hours']     ?? 48);
$reassignHours    = (int)($config['lead_stale_reassign_hours'] ?? 72);
$autoEnabled      = ($config['lead_auto_assign_enabled']       ?? true);

if (!$autoEnabled) {
    clog("Lead auto-assign disabled in config — exiting.");
    flock($lockFp, LOCK_UN); fclose($lockFp); return;
}

$leads     = $store->load('leads.json')     ?? [];
$retailers = $store->load('retailers.json') ?? [];

// ── Active sales agents — respects lead_assignee_ids whitelist ───────────────
$_allowedAssigneeIds = array_filter(array_map('intval', explode(',', $config['lead_assignee_ids'] ?? '')));
$agents = array_values(array_filter($retailers, function($r) use ($_allowedAssigneeIds) {
    if (!($r['is_active'] ?? true)) return false;
    if (!empty($r['on_leave'])) return false;
    if ($r['is_admin'] ?? false) return false;
    if (!in_array($r['role'] ?? 'sales', ['sales','field_agent','support_leader'], true)) return false;
    if (!empty($_allowedAssigneeIds) && !in_array((int)$r['id'], $_allowedAssigneeIds, true)) return false;
    return true;
}));

if (empty($agents)) {
    clog("No active agents found — exiting.");
    flock($lockFp, LOCK_UN); fclose($lockFp); return;
}

$openStatuses = ['open','contacted','interested','quoted','qualified'];

// Per-agent active lead count
$agentLoad = [];
foreach ($agents as $ag) $agentLoad[(int)$ag['id']] = 0;
foreach ($leads as $l) {
    $aid = (int)($l['assigned_to'] ?? 0);
    if (isset($agentLoad[$aid]) && in_array($l['status'] ?? '', $openStatuses))
        $agentLoad[$aid]++;
}

// Unassigned pool (open, not won/lost, not assigned)
$pool = array_values(array_filter($leads, fn($l) =>
    in_array($l['status'] ?? '', $openStatuses) &&
    empty($l['assigned_to'])
));

// Sort pool: high priority first, then oldest
usort($pool, function($a, $b) {
    $pm = ['high'=>0,'medium'=>1,'low'=>2];
    $pa = $pm[$a['priority'] ?? 'medium'] ?? 1;
    $pb = $pm[$b['priority'] ?? 'medium'] ?? 1;
    return $pa !== $pb ? $pa - $pb : strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
});

$runLog = ['started_at' => $started, 'drip' => [], 'stale' => [], 'reminders' => 0];

// =============================================================================
// TASK 1 — DRIP ASSIGN
// =============================================================================
clog("\n[1/3] Drip Assignment (pool size: " . count($pool) . ", drip target: {$dripSize})");

// Sort agents: lightest load first for fair filling
usort($agents, fn($a, $b) => ($agentLoad[(int)$a['id']] ?? 0) - ($agentLoad[(int)$b['id']] ?? 0));

$dripAssignments = []; // agentId => [leads]
foreach ($agents as $ag) $dripAssignments[(int)$ag['id']] = [];

$poolIdx   = 0;
$agentIdx  = 0;
$totalAgents = count($agents);

while ($poolIdx < count($pool)) {
    $assigned = false;
    for ($t = 0; $t < $totalAgents; $t++) {
        $ag  = $agents[($agentIdx + $t) % $totalAgents];
        $aid = (int)$ag['id'];
        $canTake = max(0, $dripSize - $agentLoad[$aid]);
        if ($canTake > 0) {
            $dripAssignments[$aid][] = $pool[$poolIdx];
            $agentLoad[$aid]++;
            $agentIdx = ($agentIdx + $t + 1) % $totalAgents;
            $assigned = true;
            break;
        }
    }
    if (!$assigned) break;
    $poolIdx++;
}

// Apply drip assignments to leads array
$assignedNow = date('Y-m-d H:i:s');
$agentById   = array_combine(array_map(fn($a) => (int)$a['id'], $agents), $agents);
$dripTotal   = 0;

foreach ($leads as &$l) {
    $lid = (int)($l['id'] ?? 0);
    foreach ($dripAssignments as $aid => $aLeads) {
        foreach ($aLeads as $al) {
            if ((int)($al['id'] ?? 0) === $lid) {
                $ag = $agentById[$aid];
                $l['assigned_to']   = $aid;
                $l['assigned_name'] = $ag['name'];
                $l['assigned_at']   = $assignedNow;
                $l['assigned_by']   = 'cron:drip';
                if (!isset($l['history'])) $l['history'] = [];
                $l['history'][] = [
                    'status' => $l['status'] ?? 'open',
                    'by'     => 'Auto-Assign Cron',
                    'at'     => $assignedNow,
                    'note'   => "Auto-assigned (drip, target={$dripSize})",
                ];
                $dripTotal++;
            }
        }
    }
}
unset($l);

// Send ONE WhatsApp summary per agent
foreach ($agents as $ag) {
    $aid   = (int)$ag['id'];
    $aLeads = $dripAssignments[$aid];
    if (empty($aLeads)) continue;
    clog("  Assigned " . count($aLeads) . " leads → {$ag['name']}");
    try {
        $notify->leadBatchAssigned($ag, $aLeads, "Auto-assigned by system · Call within 24h");
    } catch (Throwable $e) {
        clog("  WA error for {$ag['name']}: " . $e->getMessage());
    }
    $runLog['drip'][] = ['agent' => $ag['name'], 'count' => count($aLeads)];
}
clog("  Drip total: {$dripTotal} leads assigned");

// =============================================================================
// TASK 2 — STALE REASSIGNMENT
// =============================================================================
clog("\n[2/3] Stale Lead Reassignment (flag={$flagHours}h, reassign={$reassignHours}h)");

$staleReassigned = []; // agentId => [lead, ...]
$staleFlagged    = []; // agentId => [lead, ...]
foreach ($agents as $ag) { $staleReassigned[(int)$ag['id']] = []; $staleFlagged[(int)$ag['id']] = []; }

foreach ($leads as &$l) {
    if (!in_array($l['status'] ?? '', $openStatuses)) continue;
    if (empty($l['assigned_to'])) continue;
    if (!empty($l['stale_reassigned'])) continue; // already reassigned once

    $aid = (int)$l['assigned_to'];

    // Last activity = most recent history entry or assigned_at
    $lastActivity = $l['assigned_at'] ?? $l['created_at'] ?? '';
    if (!empty($l['history'])) {
        $latest = end($l['history']);
        if (!empty($latest['at']) && $latest['at'] > $lastActivity)
            $lastActivity = $latest['at'];
    }
    if (!$lastActivity) continue;

    $hoursIdle = ($now - strtotime($lastActivity)) / 3600;

    if ($hoursIdle >= $reassignHours) {
        // Find lightest loaded agent (not the current one)
        $candidates = array_filter($agents, fn($a) => (int)$a['id'] !== $aid);
        if (empty($candidates)) continue;
        usort($candidates, fn($a, $b) => ($agentLoad[(int)$a['id']] ?? 0) - ($agentLoad[(int)$b['id']] ?? 0));
        $newAg  = reset($candidates);
        $newAid = (int)$newAg['id'];

        $oldAgName = $agentById[$aid]['name'] ?? "Agent#{$aid}";
        $l['prev_assigned_to']   = $aid;
        $l['prev_assigned_name'] = $oldAgName;
        $l['assigned_to']        = $newAid;
        $l['assigned_name']      = $newAg['name'];
        $l['assigned_at']        = date('Y-m-d H:i:s');
        $l['assigned_by']        = 'cron:stale_reassign';
        $l['stale_reassigned']   = true;
        // Reset alert flags so new agent gets immediate WA notification
        unset($l['wa_assigned_notified'], $l['wa_45min_sent'], $l['wa_60min_sent']);
        $l['history'][] = [
            'status' => $l['status'] ?? 'open',
            'by'     => 'Auto-Reassign Cron',
            'at'     => date('Y-m-d H:i:s'),
            'note'   => "Reassigned from {$oldAgName} — no update in " . round($hoursIdle) . "h",
        ];
        $agentLoad[$newAid]++;
        $agentLoad[$aid] = max(0, ($agentLoad[$aid] ?? 0) - 1);
        $staleReassigned[$newAid][] = $l;

        clog("  ⟲ Lead #{$l['id']} ({$l['customer_name']}) reassigned from {$oldAgName} → {$newAg['name']} ({$hoursIdle}h idle)");

    } elseif ($hoursIdle >= $flagHours && empty($l['stale_flagged'])) {
        // Just flag — don't reassign yet
        $l['stale_flagged'] = true;
        $l['history'][] = [
            'status' => $l['status'] ?? 'open',
            'by'     => 'Auto-Flag Cron',
            'at'     => date('Y-m-d H:i:s'),
            'note'   => "⚠ No update in " . round($hoursIdle) . "h — will reassign if no action",
        ];
        $staleFlagged[$aid][] = $l;
        clog("  ⚠ Lead #{$l['id']} ({$l['customer_name']}) flagged stale ({$hoursIdle}h idle)");
    }
}
unset($l);

// Notify original agents about reassigned leads
$staleByPrev = [];
foreach ($leads as $l) {
    if (!empty($l['stale_reassigned']) && !empty($l['prev_assigned_to'])) {
        $staleByPrev[(int)$l['prev_assigned_to']][] = $l;
    }
}
foreach ($staleByPrev as $prevAid => $staleLeads) {
    $prevAg = $agentById[$prevAid] ?? null;
    if (!$prevAg) continue;
    $cnt = count($staleLeads);
    try {
        $prevMsg = "⚠️ *Lead Reassignment Notice*\nHi {$prevAg['name']},\n\n{$cnt} lead(s) were reassigned away from you because they had no updates for {$reassignHours}+ hours.\n\nLeads: " .
                implode(', ', array_map(fn($l) => ($l['customer_name'] ?? 'Lead'), array_slice($staleLeads, 0, 5))) .
                (count($staleLeads) > 5 ? ' …and ' . (count($staleLeads)-5) . ' more' : '') .
                "\n\nPlease keep leads updated daily to retain them. 📱";
        $notify->sendRaw($prevAg['phone'] ?? '', $prevMsg, 'ops_lead_reassigned_notice');
    } catch (Throwable $e) {}
    clog("  WA sent to {$prevAg['name']} — {$cnt} leads reassigned away");
}

// Notify stale warning (flagged, not yet reassigned)
foreach ($staleFlagged as $aid => $flaggedLeads) {
    if (empty($flaggedLeads)) continue;
    $ag = $agentById[$aid] ?? null;
    if (!$ag) continue;
    $cnt = count($flaggedLeads);
    try {
        $msg = "⏰ *Follow-Up Reminder*\nHi {$ag['name']},\n\n{$cnt} lead(s) have had no updates for {$flagHours}+ hours:\n\n" .
            implode("\n", array_map(fn($l) => "• " . ($l['customer_name'] ?? 'Lead') . " — " . ($l['phone'] ?? ''), array_slice($flaggedLeads, 0, 5))) .
            "\n\nUpdate them now or they will be auto-reassigned in " . ($reassignHours - $flagHours) . "h. 🚨";
        $notify->sendRaw($ag['phone'] ?? '', $msg, 'ops_lead_stale_warning');
    } catch (Throwable $e) {}
    clog("  WA warning sent to {$ag['name']} — {$cnt} leads flagged");
}

// Notify receiving agents about newly reassigned leads
foreach ($agents as $ag) {
    $aid   = (int)$ag['id'];
    $rLeads = $staleReassigned[$aid];
    if (empty($rLeads)) continue;
    try {
        $notify->leadBatchAssigned($ag, $rLeads, "Reassigned — previous agent inactive");
    } catch (Throwable $e) {}
    $runLog['stale'][] = ['agent' => $ag['name'], 'count' => count($rLeads)];
}

// =============================================================================
// TASK 3 — FOLLOW-UP REMINDERS
// =============================================================================
clog("\n[3/3] Follow-Up Reminders");

$todayStr = date('Y-m-d');
$remCount = 0;
$remByAgent = [];

foreach ($leads as $l) {
    if (empty($l['follow_up_date'])) continue;
    if ($l['follow_up_date'] !== $todayStr) continue;
    if (!in_array($l['status'] ?? '', $openStatuses)) continue;

    $aid = (int)($l['assigned_to'] ?? $l['retailer_id'] ?? 0);
    if (!$aid) continue;

    // Check if called today already (look for today in call_log)
    $calledToday = false;
    foreach ($l['call_log'] ?? [] as $cl) {
        if (strpos($cl['at'] ?? '', $todayStr) === 0) { $calledToday = true; break; }
    }
    if ($calledToday) continue;

    $remByAgent[$aid][] = $l;
    $remCount++;
}

foreach ($remByAgent as $aid => $remLeads) {
    $ag = $agentById[$aid] ?? null;
    if (!$ag) { // may be admin
        foreach ($retailers as $r) { if ((int)($r['id']??0) === $aid) { $ag = $r; break; } }
    }
    if (!$ag) continue;
    $cnt = count($remLeads);
    try {
        $followMsg = "📅 *Follow-Up Reminder — Today*\nHi {$ag['name']},\n\nYou have {$cnt} lead(s) due for follow-up today:\n\n" .
                implode("\n", array_map(fn($l) => "📞 " . ($l['customer_name'] ?? 'Lead') . " — " . ($l['phone'] ?? ''), $remLeads)) .
                "\n\nOpen Operations Hub → My Leads to call them. 🎯";
        $notify->sendRaw($ag['phone'] ?? '', $followMsg, 'ops_followup_reminder');
    } catch (Throwable $e) {}
    clog("  Reminder sent to {$ag['name']} — {$cnt} leads due today");
}

$runLog['reminders'] = $remCount;

// =============================================================================
// SAVE & LOG
// =============================================================================
$store->save('leads.json', $leads);

$runLog['ended_at']    = date('Y-m-d H:i:s');
$runLog['drip_total']  = $dripTotal;

$logData = $store->load('lead_cron_log.json') ?? [];
$logData[] = $runLog;
if (count($logData) > 200) $logData = array_slice($logData, -200);
$store->save('lead_cron_log.json', $logData);

clog("\n✓ Done — drip:{$dripTotal} stale:" . array_sum(array_map(fn($x)=>$x['count'], $runLog['stale'])) . " reminders:{$remCount}");
clog("══════════════════════════════════════════════\n");

flock($lockFp, LOCK_UN);
fclose($lockFp);
return;

function clog(string $msg): void { echo "[" . date('H:i:s') . "] {$msg}\n"; }
