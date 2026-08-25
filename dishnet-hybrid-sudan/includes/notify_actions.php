<?php
// ══════════════════════════════════════════════════════════════════════════════
// NOTIFICATION ACTIONS — Installation, Outage, Commission, Lead Nudge
// All require admin. CSRF-protected by the global gate above.
// ══════════════════════════════════════════════════════════════════════════════
$notify = svc('notify');

// ── Send Installation Confirmed ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify_install_confirmed') {
    if (!$isAdmin) { flash('Access denied.', 'danger'); redirect('?page=dashboard&tab=notify'); }

    $phone       = preg_replace('/[^0-9+]/', '', trim($_POST['customer_phone'] ?? ''));
    $name        = trim($_POST['customer_name']   ?? '');
    $svcType     = trim($_POST['service_type']    ?? '');
    $date        = trim($_POST['install_date']    ?? '');
    $time        = trim($_POST['install_time']    ?? '');
    $tech        = trim($_POST['tech_name']       ?? '');

    if (!$phone || !$name || !$date) {
        flash('Phone, name and date are required.', 'danger');
    } else {
        $notify->installationConfirmed($phone, $name, $svcType, $date, $time, $tech);
        logActivity($dataDir, 'notify_install', "Install confirmation sent to {$name}", "Phone:{$phone} Date:{$date}");
        flash("✅ Installation confirmation sent to {$name} ({$phone})");
    }
    redirect('?page=dashboard&tab=notify');
}

// ── Send Technician Dispatched ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify_tech_dispatched') {
    if (!$isAdmin) { flash('Access denied.', 'danger'); redirect('?page=dashboard&tab=notify'); }

    $phone     = preg_replace('/[^0-9+]/', '', trim($_POST['customer_phone'] ?? ''));
    $name      = trim($_POST['customer_name'] ?? '');
    $techName  = trim($_POST['tech_name']     ?? '');
    $techPhone = trim($_POST['tech_phone']    ?? '');
    $eta       = trim($_POST['eta']           ?? '30 minutes');

    if (!$phone || !$name || !$techName) {
        flash('Phone, customer name and technician name are required.', 'danger');
    } else {
        $notify->technicianDispatched($phone, $name, $techName, $techPhone, $eta);
        logActivity($dataDir, 'notify_dispatch', "Tech dispatched notification sent to {$name}", "Tech:{$techName} ETA:{$eta}");
        flash("✅ Technician dispatched message sent to {$name}");
    }
    redirect('?page=dashboard&tab=notify');
}

// ── Send Outage Alert (bulk) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify_outage') {
    if (!$isAdmin) { flash('Access denied.', 'danger'); redirect('?page=dashboard&tab=notify'); }

    $rawPhones  = trim($_POST['phone_list']   ?? '');
    $maintDate  = trim($_POST['maint_date']   ?? '');
    $maintStart = trim($_POST['maint_start']  ?? '');
    $maintEnd   = trim($_POST['maint_end']    ?? '');

    // phone_list: one number per line or comma-separated; name optional as "Name|Phone"
    $lines = preg_split('/[\r\n,]+/', $rawPhones);
    $sent  = 0;
    $fail  = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        // Support "Name|Phone" or just "Phone"
        if (strpos($line, '|') !== false) {
            [$custName, $custPhone] = explode('|', $line, 2);
            $custName  = trim($custName);
            $custPhone = preg_replace('/[^0-9+]/', '', trim($custPhone));
        } else {
            $custPhone = preg_replace('/[^0-9+]/', '', $line);
            $custName  = 'Customer';
        }
        if (!$custPhone) { $fail++; continue; }
        $notify->outageAlert($custPhone, $custName, $maintDate, $maintStart, $maintEnd);
        $sent++;
        usleep(200_000); // 0.2s between messages
    }

    logActivity($dataDir, 'notify_outage', "Outage alert sent to {$sent} customers", "Date:{$maintDate} Window:{$maintStart}–{$maintEnd} Sent:{$sent} Failed:{$fail}");
    flash("✅ Outage alert sent to {$sent} customer(s)" . ($fail > 0 ? " ({$fail} skipped — bad phone)" : ""));
    redirect('?page=dashboard&tab=notify');
}

// ── Send Commission Summary to one agent ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify_commission') {
    if (!$isAdmin) { flash('Access denied.', 'danger'); redirect('?page=dashboard&tab=notify'); }

    $agentId    = (int)($_POST['agent_id'] ?? 0);
    $month      = trim($_POST['month']               ?? date('F Y'));
    $newCust    = (int)($_POST['new_customers']       ?? 0);
    $commission = (float)($_POST['commission_amount'] ?? 0);
    $bonus      = (float)($_POST['bonus']             ?? 0);
    $payDate    = trim($_POST['pay_date']             ?? '');

    $agent = $store->findOne('retailers.json', 'id', $agentId);
    if (!$agent) {
        flash('Agent not found.', 'danger');
    } elseif (!($agent['phone'] ?? '')) {
        flash("Agent {$agent['name']} has no phone number configured.", 'danger');
    } else {
        $notify->commissionSummary($agent, $month, $newCust, $commission, $bonus, $payDate);
        logActivity($dataDir, 'notify_commission', "Commission summary sent to {$agent['name']}", "Month:{$month} Total:\$".number_format($commission+$bonus,2));
        flash("✅ Commission summary for {$month} sent to {$agent['name']}");
    }
    redirect('?page=dashboard&tab=notify');
}

// ── Send Lead Nudge to one agent ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'notify_lead_nudge') {
    if (!$isAdmin) { flash('Access denied.', 'danger'); redirect('?page=dashboard&tab=notify'); }

    $agentId      = (int)($_POST['agent_id']      ?? 0);
    $pendingLeads = (int)($_POST['pending_leads']  ?? 0);
    $deadline     = trim($_POST['deadline']        ?? 'Friday');

    $agent = $store->findOne('retailers.json', 'id', $agentId);
    if (!$agent) {
        flash('Agent not found.', 'danger');
    } elseif (!($agent['phone'] ?? '')) {
        flash("Agent {$agent['name']} has no phone number configured.", 'danger');
    } else {
        $notify->agentLeadNudge($agent, $pendingLeads, $deadline);
        logActivity($dataDir, 'notify_lead_nudge', "Lead nudge sent to {$agent['name']}", "Pending:{$pendingLeads} Deadline:{$deadline}");
        flash("✅ Lead nudge sent to {$agent['name']}");
    }
    redirect('?page=dashboard&tab=notify');
}
