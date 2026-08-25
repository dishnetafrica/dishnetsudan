<?php
/**
 * cron_lead_alerts.php — DishNet Hybrid Lead Alert Engine
 *
 * Runs every 5 minutes via master.php.
 *
 * THREE tasks per run:
 *
 * TASK A — IMMEDIATE ASSIGNMENT ALERT
 *   New leads assigned but not yet WA-notified (wa_assigned_notified = 0)
 *   → Send individual WA to agent instantly
 *   → "🔴 New lead: Ahmed, Gudele — call within 1 hour"
 *
 * TASK B — 45-MINUTE WARNING
 *   Leads assigned 45+ min ago, not called, not yet warned
 *   → WA to assigned agent: "⚠️ 15 minutes left to call Ahmed"
 *
 * TASK C — 60-MINUTE SUPERVISOR ESCALATION
 *   Leads assigned 60+ min ago, not called, not yet escalated
 *   → WA to admin/supervisor: "🔴 Ahmed not called — John, 1hr passed"
 *
 * Config (kyc_config.json):
 *   lead_call_deadline_minutes (int, default 60) — minutes before escalation
 *   lead_alert_enabled (bool, default true)
 *   lead_supervisor_phone (string) — override for escalation recipient
 */

date_default_timezone_set('Africa/Juba');

require_once __DIR__ . '/lib/error_handler.php';
require_once __DIR__ . '/lib/bootstrap_data.php';
$dataDir = getDataDir(__DIR__);
require_once __DIR__ . '/lib/StoreInterface.php';
require_once __DIR__ . '/lib/SqliteStore.php';
require_once __DIR__ . '/lib/NotificationService.php';

$store  = SqliteStore::create($dataDir);
$config = $store->load('kyc_config.json') ?? [];

// ── Config ────────────────────────────────────────────────────────────────
$enabled         = ($config['lead_alert_enabled'] ?? true) !== false;
$deadlineMinutes = max(15, (int)($config['lead_call_deadline_minutes'] ?? 60));
$warnMinutes     = max(10, $deadlineMinutes - 15); // warn 15 min before deadline
$supervisorPhone = trim($config['lead_supervisor_phone'] ?? '');

if (!$enabled) {
    echo "[" . date('H:i:s') . "] Lead alerts disabled in config\n";
    return;
}

$notify = new NotificationService($store, $config);

// ── Load data ─────────────────────────────────────────────────────────────
$leads    = $store->load('leads.json') ?? [];
$retailers = $store->load('retailers.json') ?? [];

// Build retailer index for fast lookup
$agentIdx = [];
foreach ($retailers as $r) {
    $agentIdx[(int)($r['id'] ?? 0)] = $r;
}

// Find admin/supervisor for escalation
$supervisor = null;
foreach ($retailers as $r) {
    if (!empty($r['is_admin']) && !empty($r['phone'])) {
        $supervisor = $r;
        break;
    }
}
// Override with config phone if set
if ($supervisorPhone) {
    $supervisor = ['name' => 'Admin', 'phone' => $supervisorPhone];
}

$now        = time();
$needsSave  = false;
$taskA = $taskB = $taskC = 0;

foreach ($leads as &$lead) {
    // Only active leads (not won/lost/dead)
    $status = $lead['status'] ?? 'open';
    if (in_array($status, ['won', 'lost', 'dead'], true)) continue;

    // Must be assigned
    $assignedTo = (int)($lead['assigned_to'] ?? 0);
    if (!$assignedTo) continue;

    // Get the assigned agent
    $agent = $agentIdx[$assignedTo] ?? null;
    if (!$agent || empty($agent['phone'])) continue;

    $assignedAt  = $lead['assigned_at'] ?? $lead['created_at'] ?? '';
    if (!$assignedAt) continue;

    $assignedTs  = strtotime($assignedAt);
    $minutesAgo  = ($now - $assignedTs) / 60;

    // Already called today? — don't alert
    $lastCallAt  = $lead['last_call_at'] ?? '';
    $calledToday = $lastCallAt && substr($lastCallAt, 0, 10) === date('Y-m-d');
    if ($calledToday) continue;

    // Lead name for messages
    $leadName = trim($lead['customer_name'] ?? '');
    $leadArea = trim($lead['address'] ?? $lead['area'] ?? '');
    $leadSvc  = ucfirst($lead['service_type'] ?? '');
    $leadId   = $lead['id'] ?? '';

    // ── TASK A: Immediate assignment WA ───────────────────────────────────
    // Fires for any assigned lead not yet notified, regardless of time
    if (empty($lead['wa_assigned_notified'])) {
        try {
            $msg = "🔴 *New Lead Assigned to You*\n\n"
                 . "👤 *{$leadName}*"
                 . ($leadArea ? "\n📍 {$leadArea}" : '')
                 . ($leadSvc  ? "\n📶 {$leadSvc}"  : '')
                 . ($lead['phone'] ? "\n📱 {$lead['phone']}" : '')
                 . "\n\n⏰ Call within *{$deadlineMinutes} minutes*\n\n"
                 . "Open Plugin → Leads tab → Call Now\n"
                 . ($leadId ? "Lead #{$leadId}" : '');

            $notify->sendRaw($agent['phone'], $msg, 'ops_lead_assigned_immediate');

            $lead['wa_assigned_notified'] = 1;
            $needsSave = true;
            $taskA++;
            echo "[" . date('H:i:s') . "] TASK A: Notified {$agent['name']} → {$leadName}\n";
        } catch (\Throwable $e) {
            echo "[" . date('H:i:s') . "] TASK A ERROR: " . $e->getMessage() . "\n";
        }
        continue; // Don't check B/C on same run — let the agent see their assignment first
    }

    // ── TASK B: 45-minute warning to agent ────────────────────────────────
    if (
        $minutesAgo >= $warnMinutes &&
        $minutesAgo <  $deadlineMinutes &&
        empty($lead['wa_45min_sent'])
    ) {
        $remaining = (int)($deadlineMinutes - $minutesAgo);
        try {
            $msg = "⚠️ *Call Reminder — {$remaining} minutes left*\n\n"
                 . "You have *{$remaining} minutes* to call:\n"
                 . "👤 *{$leadName}*"
                 . ($leadArea ? "\n📍 {$leadArea}" : '')
                 . ($lead['phone'] ? "\n📱 {$lead['phone']}" : '')
                 . "\n\nLead #{$leadId} · Open Plugin → Leads → Call Now";

            $notify->sendRaw($agent['phone'], $msg, 'ops_lead_45min_warning');

            $lead['wa_45min_sent'] = 1;
            $needsSave = true;
            $taskB++;
            echo "[" . date('H:i:s') . "] TASK B: 45-min warning to {$agent['name']} → {$leadName}\n";
        } catch (\Throwable $e) {
            echo "[" . date('H:i:s') . "] TASK B ERROR: " . $e->getMessage() . "\n";
        }
    }

    // ── TASK C: 60-minute supervisor escalation ───────────────────────────
    if (
        $minutesAgo >= $deadlineMinutes &&
        empty($lead['wa_60min_sent']) &&
        $supervisor
    ) {
        $overBy = (int)($minutesAgo - $deadlineMinutes);
        try {
            $msg = "🔴 *Lead Not Called — Action Needed*\n\n"
                 . "Lead assigned to *{$agent['name']}* was not called within {$deadlineMinutes} minutes.\n\n"
                 . "👤 *{$leadName}*"
                 . ($leadArea ? "\n📍 {$leadArea}" : '')
                 . ($lead['phone'] ? "\n📱 {$lead['phone']}" : '')
                 . "\n\n⏰ Assigned: " . date('H:i', $assignedTs)
                 . " · Overdue by: {$overBy} min"
                 . "\n\nLead #{$leadId}";

            $notify->sendRaw($supervisor['phone'], $msg, 'ops_lead_60min_escalation');

            $lead['wa_60min_sent'] = 1;
            $needsSave = true;
            $taskC++;
            echo "[" . date('H:i:s') . "] TASK C: Escalated {$leadName} ({$agent['name']}) to supervisor\n";
        } catch (\Throwable $e) {
            echo "[" . date('H:i:s') . "] TASK C ERROR: " . $e->getMessage() . "\n";
        }
    }
}
unset($lead);

if ($needsSave) {
    $store->save('leads.json', $leads);
}

echo "[" . date('H:i:s') . "] Done — A:{$taskA} immediate, B:{$taskB} warnings, C:{$taskC} escalations\n";
