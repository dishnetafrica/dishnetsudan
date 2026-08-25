<div style="padding:4px 0;">
    <div style="font-size:16px;font-weight:800;color:#1e293b;margin-bottom:16px;">Menu</div>

    <?php if ($isSales || $isAdmin): ?>
    <!-- Sales Quick Actions -->
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Quick Actions</div>
    <div class="resp-grid-2-menu" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=collect_payment" class="mwh-btn" style="flex-direction:column;text-align:center;padding:20px 10px;border-radius:16px;">
            <i class="bi bi-cash-coin" style="font-size:28px;color:#28a745;"></i><span style="font-size:13px;margin-top:6px;font-weight:700;">Collect Payment</span></a>
        <a href="?page=dashboard&tab=form" class="mwh-btn" style="flex-direction:column;text-align:center;padding:20px 10px;border-radius:16px;">
            <i class="bi bi-plus-circle" style="font-size:28px;color:#D41C1C;"></i><span style="font-size:13px;margin-top:6px;font-weight:700;">New KYC</span></a>
    </div>

    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Management</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=leads" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border-radius:14px;text-decoration:none;color:#92400e;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #fde68a;">
            <i class="bi bi-people-fill" style="font-size:20px;color:#f39c12;width:24px;text-align:center;"></i>
            <div style="flex:1;">📞 All Leads<?php $lc=count(array_filter($store->load('leads.json'),function($l){return !in_array($l['status']??'',['won','lost']);})); if($lc>0): ?> <span style="background:#f39c12;color:#fff;padding:1px 7px;border-radius:10px;font-size:10px;margin-left:3px;"><?= $lc ?></span><?php endif; ?></div>
            <i class="bi bi-chevron-right" style="color:#f59e0b;"></i></a>
        <a href="?page=dashboard&tab=applications" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-folder" style="font-size:20px;color:#D41C1C;width:24px;text-align:center;"></i>
            <div style="flex:1;">My Orders</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=wallet" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-wallet2" style="font-size:20px;color:#7B1FA2;width:24px;text-align:center;"></i>
            <div style="flex:1;">Wallet & History</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=my_account" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#1e293b,#334155);border-radius:14px;text-decoration:none;color:#fff;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.15);">
            <i class="bi bi-wallet2" style="font-size:20px;color:#86efac;width:24px;text-align:center;"></i>
            <div style="flex:1;">
                <div>💰 My Cash Account</div>
                <div style="font-size:10px;font-weight:600;color:#94a3b8;margin-top:1px;">Advances · Collections · Expenses</div>
            </div>
            <i class="bi bi-chevron-right" style="color:#64748b;"></i></a>
        <a href="?page=dashboard&tab=wallet_recharge" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-cash-stack" style="font-size:20px;color:#28a745;width:24px;text-align:center;"></i>
            <div style="flex:1;">Load Money</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=cashbook" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:14px;text-decoration:none;color:#15803d;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #bbf7d0;">
            <i class="bi bi-journal-bookmark-fill" style="font-size:20px;color:#15803d;width:24px;text-align:center;"></i>
            <div style="flex:1;">
                <div>💰 Cashbook</div>
                <div style="font-size:10px;font-weight:600;color:#4ade80;margin-top:1px;">USD &amp; SSP ledger</div>
            </div>
            <i class="bi bi-chevron-right" style="color:#86efac;"></i></a>
        <a href="?page=dashboard&tab=scheduling" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-calendar-check" style="font-size:20px;color:#1565C0;width:24px;text-align:center;"></i>
            <div style="flex:1;">My Jobs / Schedule</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=customer_lookup" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-search" style="font-size:20px;color:#0891b2;width:24px;text-align:center;"></i>
            <div style="flex:1;">Customer 360°</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>
    <?php endif; ?>

    <?php if ($userRole === 'field_agent'): ?>
    <!-- Field Agent Tools -->
    <div style="font-size:10px;font-weight:700;color:#E65100;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;display:flex;align-items:center;gap:6px;"><i class="bi bi-geo-alt-fill"></i> Field Tools</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=my_account" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#1e293b,#334155);border-radius:14px;text-decoration:none;color:#fff;font-weight:700;box-shadow:0 2px 8px rgba(0,0,0,.15);">
            <i class="bi bi-wallet2" style="font-size:20px;color:#86efac;width:24px;text-align:center;"></i>
            <div style="flex:1;"><div>💰 My Cash</div><div style="font-size:10px;font-weight:600;color:#94a3b8;margin-top:1px;">Expenses · Handovers · Advances</div></div>
            <i class="bi bi-chevron-right" style="color:#64748b;"></i></a>
        <a href="?page=dashboard&tab=cashbook" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:14px;text-decoration:none;color:#15803d;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #bbf7d0;">
            <i class="bi bi-journal-bookmark-fill" style="font-size:20px;color:#15803d;width:24px;text-align:center;"></i>
            <div style="flex:1;">💰 Cashbook</div>
            <i class="bi bi-chevron-right" style="color:#86efac;"></i></a>
        <a href="?page=dashboard&tab=leads" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#fffbeb,#fef3c7);border-radius:14px;text-decoration:none;color:#92400e;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #fde68a;">
            <i class="bi bi-people-fill" style="font-size:20px;color:#f39c12;width:24px;text-align:center;"></i>
            <div style="flex:1;">📞 All Leads</div>
            <i class="bi bi-chevron-right" style="color:#f59e0b;"></i></a>
        <a href="?page=dashboard&tab=scheduling" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-calendar-check" style="font-size:20px;color:#1565C0;width:24px;text-align:center;"></i>
            <div style="flex:1;">My Jobs / Schedule</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=cash_declaration" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#fef2f2,#fee2e2);border-radius:14px;text-decoration:none;color:#991b1b;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #fecaca;">
            <i class="bi bi-clipboard-check" style="font-size:20px;color:#dc2626;width:24px;text-align:center;"></i>
            <div style="flex:1;">📋 End-of-Day Cash Count</div>
            <i class="bi bi-chevron-right" style="color:#f87171;"></i></a>
    </div>
    <?php endif; ?>

    <?php if ($isSupport || $isAdmin): ?>
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Support Tools</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=support_dashboard" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-speedometer2" style="font-size:20px;color:#9C27B0;width:24px;text-align:center;"></i>
            <div style="flex:1;">Support Dashboard</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=customer_lookup" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-search" style="font-size:20px;color:#D41C1C;width:24px;text-align:center;"></i>
            <div style="flex:1;">Customer Lookup</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=support_tickets" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-headset" style="font-size:20px;color:#E65100;width:24px;text-align:center;"></i>
            <div style="flex:1;">Support Tickets</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <?php if ($can('splynx_noc')): ?>
        <a href="?page=dashboard&tab=splynx_noc" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-hdd-network-fill" style="font-size:20px;color:#0D47A1;width:24px;text-align:center;"></i>
            <div style="flex:1;">Splynx NOC Dashboard</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <?php endif; ?>
        <?php if ($can('bulk_dispatch')): ?>
        <a href="?page=dashboard&tab=bulk_dispatch" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-send-fill" style="font-size:20px;color:#4527A0;width:24px;text-align:center;"></i>
            <div style="flex:1;">Bulk Dispatch</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <?php endif; ?>
        <a href="?page=dashboard&tab=my_account&v=expense" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-cash-coin" style="font-size:20px;color:#d97706;width:24px;text-align:center;"></i>
            <div style="flex:1;">Submit Expense</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=collect_payment" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:14px;text-decoration:none;color:#15803d;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #bbf7d0;">
            <i class="bi bi-cash-coin" style="font-size:20px;color:#16a34a;width:24px;text-align:center;"></i>
            <div style="flex:1;">💵 Collect Payment</div>
            <i class="bi bi-chevron-right" style="color:#86efac;"></i></a>
        <a href="?page=dashboard&tab=wallet_recharge" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-cash-stack" style="font-size:20px;color:#0891b2;width:24px;text-align:center;"></i>
            <div style="flex:1;">💳 Load Money</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=cash_declaration" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-clipboard-check" style="font-size:20px;color:#dc2626;width:24px;text-align:center;"></i>
            <div style="flex:1;">End-of-Day Cash Count</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>
    <?php endif; ?>

    <?php if ($userRole === 'support_leader'): ?>
    <!-- Support Leader Tools — items not on bottom nav -->
    <div style="font-size:10px;font-weight:700;color:#7C3AED;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;display:flex;align-items:center;gap:6px;"><i class="bi bi-shield-fill-check"></i> Leader Tools</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=customer_lookup" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-search" style="font-size:20px;color:#D41C1C;width:24px;text-align:center;"></i>
            <div style="flex:1;">Customer Lookup</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=service_status" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-wifi" style="font-size:20px;color:#059669;width:24px;text-align:center;"></i>
            <div style="flex:1;">Service Status Check</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=lte_subscribers" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-people-fill" style="font-size:20px;color:#0D47A1;width:24px;text-align:center;"></i>
            <div style="flex:1;">LTE Subscribers</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=lte_renewal" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-arrow-repeat" style="font-size:20px;color:#f59e0b;width:24px;text-align:center;"></i>
            <div style="flex:1;">LTE Renewal Queue</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=live_map" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-pin-map-fill" style="font-size:20px;color:#1d4ed8;width:24px;text-align:center;"></i>
            <div style="flex:1;">Live Staff Map</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=support_leader_manual" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#f5f3ff,#ede9fe);border-radius:14px;text-decoration:none;color:#5b21b6;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #ddd6fe;">
            <i class="bi bi-book-fill" style="font-size:20px;color:#7C3AED;width:24px;text-align:center;"></i>
            <div style="flex:1;">📖 My User Manual</div>
            <i class="bi bi-chevron-right" style="color:#c4b5fd;"></i></a>
        <a href="?page=dashboard&tab=my_account&v=expense" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:14px;text-decoration:none;color:#92400e;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #fcd34d;">
            <i class="bi bi-cash-coin" style="font-size:20px;color:#d97706;width:24px;text-align:center;"></i>
            <div style="flex:1;">💸 Submit Expense</div>
            <i class="bi bi-chevron-right" style="color:#f59e0b;"></i></a>
        <a href="?page=dashboard&tab=collect_payment" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:14px;text-decoration:none;color:#15803d;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #bbf7d0;">
            <i class="bi bi-cash-coin" style="font-size:20px;color:#16a34a;width:24px;text-align:center;"></i>
            <div style="flex:1;">💵 Collect Payment</div>
            <i class="bi bi-chevron-right" style="color:#86efac;"></i></a>
        <a href="?page=dashboard&tab=wallet_recharge" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-cash-stack" style="font-size:20px;color:#0891b2;width:24px;text-align:center;"></i>
            <div style="flex:1;">💳 Load Money</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>
    <?php endif; ?>

    <?php if ($isAccountant || $isAdmin): ?>
    <?php
    // ── Pending counts for operations badges ─────────────────────────
    $_mmHqPend = 0; $_mmExpPend = 0; $_mmIjPend = 0; $_mmAdvActive = 0;
    try {
        static $_mmFaSvc;
        if (!$_mmFaSvc) { require_once dirname(__DIR__).'/../lib/FieldAgentService.php'; $_mmFaSvc = new FieldAgentService($store); }
        $_mmHqPend = count($_mmFaSvc->getRemittances(0, true, 'pending'));
    } catch (Throwable $_e) {}
    try {
        static $_mmExpSvc;
        if (!$_mmExpSvc) { require_once dirname(__DIR__).'/../lib/ExpenseAdvanceService.php'; $_mmExpSvc = new ExpenseAdvanceService($store, $dataDir); }
        $_mmExpPend = $_mmExpSvc->countPending();
        $_mmAdvActive = (int)$store->getPdo()->query("SELECT COUNT(*) FROM cash_advances WHERE status IN ('active','partial') AND (parent_advance_id IS NULL OR parent_advance_id = 0)")->fetchColumn();
    } catch (Throwable $_e) {}
    try {
        $_mmIjAll  = $store->load('job_invoice_queue.json') ?? [];
        $_mmIjPend = count(array_filter($_mmIjAll, fn($x) => ($x['status'] ?? 'pending') === 'pending'));
    } catch (Throwable $_e) {}
    ?>
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Operations</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=handover_queue" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#fef2f2,#fee2e2);border-radius:14px;text-decoration:none;color:#991b1b;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #fecaca;">
            <i class="bi bi-cash-stack" style="font-size:20px;color:#D41C1C;width:24px;text-align:center;"></i>
            <div style="flex:1;">
                <div>💵 Handover Queue</div>
                <div style="font-size:10px;font-weight:600;color:#f87171;margin-top:1px;">Confirm/reject agent cash handovers</div>
            </div>
            <?php if ($_mmHqPend > 0): ?><span style="background:#D41C1C;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:800;flex-shrink:0;"><?= $_mmHqPend ?></span><?php endif; ?>
            <i class="bi bi-chevron-right" style="color:#fca5a5;"></i></a>
        <a href="?page=dashboard&tab=expense_approvals" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);position:relative;">
            <i class="bi bi-receipt" style="font-size:20px;color:#d97706;width:24px;text-align:center;"></i>
            <div style="flex:1;">Expense Approvals</div>
            <?php if ($_mmExpPend > 0): ?><span style="background:#f59e0b;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:800;flex-shrink:0;"><?= $_mmExpPend ?></span><?php endif; ?>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=cash_advances" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-cash-coin" style="font-size:20px;color:#7c3aed;width:24px;text-align:center;"></i>
            <div style="flex:1;">Cash Advances</div>
            <?php if ($_mmAdvActive > 0): ?><span style="background:#7c3aed;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:800;flex-shrink:0;"><?= $_mmAdvActive ?> active</span><?php endif; ?>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=invoice_queue" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-file-earmark-text" style="font-size:20px;color:#0284c7;width:24px;text-align:center;"></i>
            <div style="flex:1;">Invoice Queue</div>
            <?php if ($_mmIjPend > 0): ?><span style="background:#0284c7;color:#fff;border-radius:10px;padding:2px 8px;font-size:11px;font-weight:800;flex-shrink:0;"><?= $_mmIjPend ?></span><?php endif; ?>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=staff_transfers" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-arrow-left-right" style="font-size:20px;color:#059669;width:24px;text-align:center;"></i>
            <div style="flex:1;">Staff Transfers</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=staff_cashbooks" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-journal-check" style="font-size:20px;color:#475569;width:24px;text-align:center;"></i>
            <div style="flex:1;">Staff Cashbooks</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=balance_identity" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#eff6ff,#dbeafe);border-radius:14px;text-decoration:none;color:#1e40af;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #bfdbfe;">
            <i class="bi bi-shield-check" style="font-size:20px;color:#1d4ed8;width:24px;text-align:center;"></i>
            <div style="flex:1;">
                <div>⚖️ Balance Identity</div>
                <div style="font-size:10px;font-weight:600;color:#60a5fa;margin-top:1px;">Vault + Field + Advances = Total</div>
            </div>
            <i class="bi bi-chevron-right" style="color:#93c5fd;"></i></a>
        <a href="?page=dashboard&tab=send_quote" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-file-earmark-plus" style="font-size:20px;color:#E65100;width:24px;text-align:center;"></i>
            <div style="flex:1;">Send Quotation</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=quote_logs" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-clock-history" style="font-size:20px;color:#94a3b8;width:24px;text-align:center;"></i>
            <div style="flex:1;">Quote History</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>

    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Reports</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=cashbook" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:14px;text-decoration:none;color:#15803d;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,.04);border:1.5px solid #bbf7d0;">
            <i class="bi bi-journal-bookmark-fill" style="font-size:20px;color:#15803d;width:24px;text-align:center;"></i>
            <div style="flex:1;">
                <div>💰 Cashbook</div>
                <div style="font-size:10px;font-weight:600;color:#4ade80;margin-top:1px;">USD &amp; SSP dual ledger</div>
            </div>
            <i class="bi bi-chevron-right" style="color:#86efac;"></i></a>
        <a href="?page=dashboard&tab=accounts_dashboard" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-bar-chart" style="font-size:20px;color:#E65100;width:24px;text-align:center;"></i>
            <div style="flex:1;">Accounts Dashboard</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=accounts_ledger" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-journal-text" style="font-size:20px;color:#D41C1C;width:24px;text-align:center;"></i>
            <div style="flex:1;">Retailer Ledger</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=accounts_settlement" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-clipboard-data" style="font-size:20px;color:#6A1B9A;width:24px;text-align:center;"></i>
            <div style="flex:1;">Daily Settlement</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=accounts_collections" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-cash-coin" style="font-size:20px;color:#28a745;width:24px;text-align:center;"></i>
            <div style="flex:1;">All Collections</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=accounts_wallets" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-wallet2" style="font-size:20px;color:#D41C1C;width:24px;text-align:center;"></i>
            <div style="flex:1;">Wallet Balances</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=accounts_commissions" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-star" style="font-size:20px;color:#FF9800;width:24px;text-align:center;"></i>
            <div style="flex:1;">Commission Report</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>
    <?php endif; ?>

    <!-- Stock Management -->
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Stock Management</div>
    <div style="margin-bottom:20px;">
        <a href="?page=dashboard&tab=stock_hub" style="display:flex;align-items:center;gap:14px;padding:16px;background:linear-gradient(135deg,#059669,#047857);border-radius:16px;text-decoration:none;color:#fff;margin-bottom:8px;box-shadow:0 4px 16px rgba(5,150,105,.3);">
            <span style="font-size:28px;">📦</span>
            <div style="flex:1;">
                <div style="font-size:15px;font-weight:800;">Stock Hub</div>
                <div style="font-size:11px;opacity:.85;margin-top:1px;">Receive, scan, issue &amp; track equipment</div>
            </div>
            <i class="bi bi-chevron-right" style="opacity:.7;"></i>
        </a>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <a href="?page=dashboard&tab=stock_hub&stock_view=inout" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#fff;border-radius:12px;text-decoration:none;color:#059669;font-weight:700;font-size:13px;border:1.5px solid #BBF7D0;">
                <span style="font-size:18px;">📥</span> In/Out</a>
            <a href="?page=dashboard&tab=stock_hub&stock_view=equipment" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:#fff;border-radius:12px;text-decoration:none;color:#2563EB;font-weight:700;font-size:13px;border:1.5px solid #BFDBFE;">
                <span style="font-size:18px;">🧰</span> My Gear</a>
        </div>
    </div>

    <!-- Training & Help -->
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Training & Help</div>
    <div style="margin-bottom:12px;">
        <!-- Training Hub — featured card -->
        <a href="?page=dashboard&tab=training" style="display:flex;align-items:center;gap:14px;padding:16px;background:linear-gradient(135deg,#7C3AED,#5B21B6);border-radius:16px;text-decoration:none;color:#fff;margin-bottom:8px;box-shadow:0 4px 16px rgba(124,58,237,.3);">
            <span style="font-size:28px;">🎓</span>
            <div style="flex:1;">
                <div style="font-size:15px;font-weight:800;">Training Hub</div>
                <div style="font-size:11px;opacity:.85;margin-top:1px;">Role-specific lessons, scenarios &amp; cheat sheets</div>
            </div>
            <i class="bi bi-chevron-right" style="opacity:.7;"></i>
        </a>
    </div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=knowledge_base" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-book" style="font-size:20px;color:#D41C1C;width:24px;text-align:center;"></i>
            <div style="flex:1;">Help Guide</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=faq" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-question-circle" style="font-size:20px;color:#E65100;width:24px;text-align:center;"></i>
            <div style="flex:1;">FAQ</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>

    <!-- HR Self-Service (all staff) v4.11.0 -->
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">HR Self-Service</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=hrm_dashboard" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <span style="font-size:20px;width:24px;text-align:center;">💼</span>
            <div style="flex:1;">My Payslips</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=hrm_leave" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <span style="font-size:20px;width:24px;text-align:center;">🗓️</span>
            <div style="flex:1;">Leave Requests</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>

    <?php if ($isAccountant || $isAdmin): ?>
    <!-- HR Management (admin/accountant only) v4.11.0 -->
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">HR Management</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="?page=dashboard&tab=hrm_dashboard" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <span style="font-size:20px;width:24px;text-align:center;">👥</span>
            <div style="flex:1;">HR Dashboard</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=hrm_employees" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <span style="font-size:20px;width:24px;text-align:center;">🪪</span>
            <div style="flex:1;">Employees</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=hrm_payroll" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <span style="font-size:20px;width:24px;text-align:center;">💰</span>
            <div style="flex:1;">Payroll</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
        <a href="?page=dashboard&tab=hrm_leave" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <span style="font-size:20px;width:24px;text-align:center;">🗓️</span>
            <div style="flex:1;">Leave Management</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>
    <?php endif; ?>

    <!-- Account -->
    <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Account</div>
    <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:20px;">
        <a href="#" onclick="document.getElementById('cpwdModal').style.display='flex';return false;" style="display:flex;align-items:center;gap:14px;padding:14px 16px;background:#fff;border-radius:14px;text-decoration:none;color:#1E293B;font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.04);">
            <i class="bi bi-key" style="font-size:20px;color:#7B1FA2;width:24px;text-align:center;"></i>
            <div style="flex:1;">Change Password</div>
            <i class="bi bi-chevron-right" style="color:#d1d5db;"></i></a>
    </div>

    <!-- Logout -->
    <a href="?page=logout" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;background:#fef2f2;border-radius:14px;text-decoration:none;color:#dc3545;font-weight:700;font-size:14px;margin-bottom:80px;">
        <i class="bi bi-box-arrow-right"></i> Sign Out
    </a>
</div>

<!-- Change Password Modal -->
<div id="cpwdModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:20px;">
  <div style="background:#fff;border-radius:20px;width:100%;max-width:380px;overflow:hidden;">
    <div style="padding:20px 20px 0;text-align:center;">
      <div style="width:56px;height:56px;border-radius:50%;background:#EDE7F6;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
        <i class="bi bi-key-fill" style="font-size:24px;color:#7B1FA2;"></i>
      </div>
      <div style="font-size:18px;font-weight:800;color:#1e293b;">Change Password</div>
      <div style="font-size:12px;color:#64748b;margin-top:4px;">Min 8 characters</div>
    </div>
    <div style="padding:20px;">
      <div id="cpwdMsg" style="display:none;padding:10px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:12px;"></div>
      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Current Password</label>
      <input type="password" id="cpwdOld" placeholder="Enter current password" style="width:100%;padding:12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;margin-bottom:12px;box-sizing:border-box;">
      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">New Password</label>
      <input type="password" id="cpwdNew" placeholder="Min 8 characters" style="width:100%;padding:12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;margin-bottom:12px;box-sizing:border-box;">
      <label style="font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px;">Confirm New Password</label>
      <input type="password" id="cpwdConf" placeholder="Repeat new password" style="width:100%;padding:12px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:14px;margin-bottom:16px;box-sizing:border-box;">
      <button onclick="cpwdSubmit()" style="width:100%;padding:14px;background:#D41C1C;color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:800;cursor:pointer;">Update Password</button>
      <button onclick="document.getElementById('cpwdModal').style.display='none'" style="width:100%;padding:12px;background:transparent;color:#64748b;border:none;font-size:13px;font-weight:600;cursor:pointer;margin-top:6px;">Cancel</button>
    </div>
  </div>
</div>
<script>
function cpwdSubmit(){
  var o=document.getElementById('cpwdOld').value;
  var n=document.getElementById('cpwdNew').value;
  var c=document.getElementById('cpwdConf').value;
  var msg=document.getElementById('cpwdMsg');
  if(!o){msg.style.display='block';msg.style.background='#fef2f2';msg.style.color='#dc2626';msg.textContent='Enter current password.';return;}
  if(n.length<8){msg.style.display='block';msg.style.background='#fef2f2';msg.style.color='#dc2626';msg.textContent='New password must be at least 8 characters.';return;}
  if(n!==c){msg.style.display='block';msg.style.background='#fef2f2';msg.style.color='#dc2626';msg.textContent='Passwords do not match.';return;}
  msg.style.display='block';msg.style.background='#f0fdf4';msg.style.color='#16a34a';msg.textContent='Updating...';
  fetch('?page=api&action=change_password',{
          credentials:'same-origin',
          method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer <?= h($retailer['api_token']??'') ?>'},
    body:JSON.stringify({current_password:o,new_password:n,confirm_password:c})
  }).then(function(r){return r.json()}).then(function(d){
    if(d.status==='error'||d.error){msg.style.background='#fef2f2';msg.style.color='#dc2626';msg.textContent=d.message||d.error||'Failed';}
    else{msg.style.background='#f0fdf4';msg.style.color='#16a34a';msg.textContent='Password changed successfully!';
      document.getElementById('cpwdOld').value='';document.getElementById('cpwdNew').value='';document.getElementById('cpwdConf').value='';
      setTimeout(function(){document.getElementById('cpwdModal').style.display='none';msg.style.display='none';},1500);
    }
  }).catch(function(e){msg.style.background='#fef2f2';msg.style.color='#dc2626';msg.textContent='Error: '+e.message;});
}
</script>

