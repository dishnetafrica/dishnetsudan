<div class="kyc-tabs">

<?php /*  SALES / GENERAL  */ ?>
<?php if($isAdmin||$can('lte_dashboard')||$can('accounts_dash')): ?>
<a href="?page=dashboard&tab=ops_hub" class="kyc-tab ops-hub-btn <?= $tab==='ops_hub'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-grid-3x3-gap-fill"></i></span>
    <span style="font-weight:800;font-family:'Barlow Condensed',sans-serif;font-size:14px;letter-spacing:.2px;">Operations Hub</span>
</a>
<?php endif; ?>
<?php if($can('scheduling')): ?>
<a href="?page=dashboard&tab=scheduling" class="kyc-tab <?= $tab==='scheduling'?'active':'' ?>" style="border-left:3px solid #1976D2;">
    <span class="nav-icon"><i class="bi bi-calendar-check-fill" style="color:#1976D2;"></i></span> <strong>My Jobs</strong>
</a>
<?php endif; ?>
<div class="nav-section">Sales</div>
<a href="?page=dashboard&tab=form" class="kyc-tab <?= $tab==='form'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-person-plus-fill"></i></span> Add Customer
</a>
<a href="?page=dashboard&tab=applications" class="kyc-tab <?= $tab==='applications'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-folder2-open"></i></span> Orders
    <?php if(($myAppsCount??0)>0): ?><span class="nav-badge"><?= $myAppsCount ?></span><?php endif; ?>
</a>
<a href="?page=dashboard&tab=leads" class="kyc-tab <?= $tab==='leads'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-bullseye"></i></span> My Leads
    <?php // v4.11.3 PERF: Leads badge cached 30s per retailer
    try {
        $_lkKey = 'leads_badge_' . $retailerId;
        $_lkRow = $store->getPdo()->query("SELECT value, updated_at FROM plugin_kv WHERE key='" . $_lkKey . "' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($_lkRow && (time() - strtotime($_lkRow['updated_at'])) < 30) {
            $myLeadCount = (int)$_lkRow['value'];
        } else {
            $myLeadCount = count(array_filter($store->load('leads.json'), fn($l) => (int)($l['retailer_id'] ?? 0) === $retailerId && ($l['status'] ?? '') === 'open'));
            $store->getPdo()->prepare("INSERT OR REPLACE INTO plugin_kv (key,value,updated_at) VALUES (?,?,datetime('now'))")->execute([$_lkKey, $myLeadCount]);
        }
    } catch (\Throwable $_e) { $myLeadCount = 0; }
    if ($myLeadCount > 0): ?><span class="nav-badge orange"><?= $myLeadCount ?></span><?php endif; ?>
</a>
<?php if($can('collect_payment')): ?>
<a href="?page=dashboard&tab=collect_payment" class="kyc-tab <?= $tab==='collect_payment'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-cash-coin"></i></span> Collect Payment
</a>
<?php endif; ?>
<a href="?page=dashboard&tab=wallet" class="kyc-tab <?= in_array($tab,['wallet','wallet_recharge'])?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-wallet2"></i></span> My Wallet
    <?php if(($myPendingRechargesCount??0)>0): ?><span class="nav-badge orange"><?= $myPendingRechargesCount ?></span><?php endif; ?>
</a>

<?php /*  WHATSAPP  */ ?>
<?php if ($isAdmin || $can('support_dash') || $can('customer_lookup')): ?>
<?php
//  WhatsApp badge counts 
$_waUnread = 0; $_waNeedsHuman = 0; $_nqFailed = 0; $_waLeadBadge2 = 0;
// v4.11.3 PERF: Batch nav badges into a single cached read (30s TTL)
// Previously: 4 separate SQL queries on every tab load.
// Now: one kv lookup + one batched query if stale.
$_navBadges = [];
try {
    $store->getPdo()->exec("CREATE TABLE IF NOT EXISTS plugin_kv (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT DEFAULT (datetime('now')))");
    $_nbRow = $store->getPdo()->query("SELECT value, updated_at FROM plugin_kv WHERE key='nav_badges' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    $_nbAge = $_nbRow ? (time() - strtotime($_nbRow['updated_at'])) : 9999;
    if ($_nbAge < 30 && $_nbRow) {
        $_navBadges = json_decode($_nbRow['value'], true) ?? [];
    } else {
        // Cache stale or missing  recompute all badges in one pass
        $_pdo = $store->getPdo();
        try { $_waUnread2    = (int)$_pdo->query("SELECT COALESCE(SUM(unread_count),0) FROM wa_conversations WHERE status != 'closed'")->fetchColumn(); } catch (\Throwable $e) { $_waUnread2 = 0; }
        try { $_waNeedsHuman2= (int)$_pdo->query("SELECT COUNT(*) FROM wa_conversations WHERE state = 'needs_human' AND status != 'closed'")->fetchColumn(); } catch (\Throwable $e) { $_waNeedsHuman2 = 0; }
        try { $_nqFailed2    = (int)$_pdo->query("SELECT COUNT(*) FROM notification_queue WHERE status = 'failed'")->fetchColumn(); } catch (\Throwable $e) { $_nqFailed2 = 0; }
        try { $_waLead2      = (int)$_pdo->query("SELECT COUNT(*) FROM wa_lead_recovery WHERE is_customer = 0 AND status = 'new'")->fetchColumn(); } catch (\Throwable $e) { $_waLead2 = 0; }
        try { $_lcAction2    = (int)$_pdo->query("SELECT COUNT(*) FROM service_lifecycle WHERE needs_action = 1 AND deleted_at IS NULL")->fetchColumn(); } catch (\Throwable $e) { $_lcAction2 = 0; }
        try { $_fibPend2     = (int)$_pdo->query("SELECT COUNT(*) FROM fiber_collection_jobs WHERE status='pending'")->fetchColumn(); } catch (\Throwable $e) { $_fibPend2 = 0; }
        $_navBadges = [
            'wa_unread'    => $_waUnread2,
            'wa_human'     => $_waNeedsHuman2,
            'nq_failed'    => $_nqFailed2,
            'wa_leads'     => $_waLead2,
            'lc_action'    => $_lcAction2,
            'fib_pending'  => $_fibPend2,
        ];
        $_pdo->prepare("INSERT OR REPLACE INTO plugin_kv (key,value,updated_at) VALUES ('nav_badges',?,datetime('now'))")->execute([json_encode($_navBadges)]);
    }
} catch (\Throwable $_e) {}
$_waUnread     = (int)($_navBadges['wa_unread']   ?? 0);
$_waNeedsHuman = (int)($_navBadges['wa_human']    ?? 0);
$_nqFailed     = (int)($_navBadges['nq_failed']   ?? 0);
$_waLeadBadge2 = (int)($_navBadges['wa_leads']    ?? 0);
?>
<div class="nav-section" style="color:#25D366;">WhatsApp</div>
<a href="?page=dashboard&tab=wa_inbox" class="kyc-tab <?= $tab==='wa_inbox'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-chat-dots-fill" style="color:#25D366;"></i></span> Inbox
    <?php if($_waUnread > 0): ?><span class="nav-badge" style="background:#25D366;"><?= $_waUnread > 99 ? '99+' : $_waUnread ?></span><?php endif; ?>
    <?php if($_waNeedsHuman > 0): ?><span style="background:#dc2626;color:#fff;font-size:9px;font-weight:800;padding:1px 4px;border-radius:8px;margin-left:2px;"><?= $_waNeedsHuman ?></span><?php endif; ?>
</a>
<a href="?page=dashboard&tab=engage_wa_leads" class="kyc-tab <?= in_array($tab,['engage_wa_leads','wa_leads'])?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-person-plus-fill"></i></span> Leads
    <?php if($_waLeadBadge2 > 0): ?><span class="nav-badge" style="background:#ef4444;"><?= $_waLeadBadge2 > 999 ? '999+' : $_waLeadBadge2 ?></span><?php endif; ?>
</a>
<a href="?page=dashboard&tab=<?= $isAdmin ? 'engage_message_log' : 'engage_failed_queue' ?>" class="kyc-tab <?= in_array($tab,['engage_message_log','engage_failed_queue']) && ($_GET['fqsub']??'') !== 'crm_events' ?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-<?= $_nqFailed > 0 ? 'exclamation-triangle-fill' : 'journal-text' ?>" <?= $_nqFailed > 0 ? 'style="color:#ef4444"' : '' ?>></i></span>
    <?php if ($_nqFailed > 0): ?>
        <span style="color:#ef4444;font-weight:700">Failed (<?= $_nqFailed ?>)</span>
    <?php else: ?>
        <?= $isAdmin ? 'Log' : 'Msg Log' ?>
    <?php endif; ?>
    <?php if($_nqFailed > 0): ?><span class="nav-badge" style="background:#ef4444;animation:pulse 1.5s infinite"><?= $_nqFailed > 99 ? '99+' : $_nqFailed ?></span><?php endif; ?>
</a>
<a href="?page=dashboard&tab=engage_failed_queue&fqsub=crm_events" class="kyc-tab <?= in_array($tab,['engage_message_log','engage_failed_queue']) && ($_GET['fqsub']??'') === 'crm_events' ? 'active' : '' ?>">
    <span class="nav-icon"><i class="bi bi-diagram-3"></i></span> WA Events
    <?php
    // Count today's delivered CRM webhook events from the JSON log
    $_waCrmDelivered = 0;
    $_waCrmFailed    = 0;
    try {
        $_whLogFile = $dataDir . '/webhook_log.json';
        if (file_exists($_whLogFile)) {
            $_whLog = json_decode(file_get_contents($_whLogFile), true) ?? [];
            $today = date('Y-m-d');
            foreach ($_whLog as $_e) {
                // Only count today's events
                if (substr($_e['timestamp'] ?? $_e['created_at'] ?? '', 0, 10) !== $today) continue;
                $_msg = strtolower($_e['message'] ?? '');
                if (str_contains($_msg,'sent ') || str_contains($_msg,'sent to') || str_contains($_msg,'notification ')) $_waCrmDelivered++;
                elseif (str_contains($_msg,'failed') || str_contains($_msg,'error')) $_waCrmFailed++;
            }
        }
    } catch (Throwable $_e) {}
    if ($_waCrmFailed > 0): ?>
        <span class="nav-badge" style="background:#ef4444;"><?= $_waCrmFailed ?> fail</span>
    <?php elseif ($_waCrmDelivered > 0): ?>
        <span class="nav-badge" style="background:#22c55e;"><?= $_waCrmDelivered > 99 ? '99+' : $_waCrmDelivered ?></span>
    <?php endif; ?>
</a>
<?php if($isAdmin): ?>
<a href="?page=dashboard&tab=whatsapp&subtab=config" class="kyc-tab <?= $tab==='whatsapp'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-gear-fill"></i></span> Settings
    <?php if(empty($config['wa_app_key'])||empty($config['wa_auth_key'])): ?>
    <span style="background:#ef4444;color:#fff;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:2px;">SETUP</span>
    <?php endif; ?>
</a>
<a href="?page=dashboard&tab=wa_ai_setup" class="kyc-tab <?= $tab==='wa_ai_setup'?'active':'' ?>">
    <span>WhatsApp AI</span>
    <span style="background:#0b6b5b;color:#fff;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:2px;">AI</span>
</a>
<?php endif; ?>

<a href="?page=dashboard&tab=lifecycle" class="kyc-tab <?= $tab==='lifecycle'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-diagram-3-fill"></i></span> Customer Lifecycle
    <?php
    $_lcNeedsAction2 = 0;
    $_lcNeedsAction2 = (int)($_navBadges['lc_action'] ?? 0); // v4.11.3: from nav_badges cache
    if($_lcNeedsAction2 > 0): ?><span class="nav-badge" style="background:#ef4444;"><?= $_lcNeedsAction2 > 99 ? '99+' : $_lcNeedsAction2 ?></span><?php endif; ?>
</a>
<?php endif; ?>

<?php if ($can('support_dash') || $can('customer_lookup') || $can('service_status') || $can('tickets')): ?>
<?php /*  SUPPORT  */ ?>
<div class="nav-section">Support</div>
<a href="?page=dashboard&tab=support_dashboard" class="kyc-tab <?= $tab==='support_dashboard'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-speedometer2"></i></span> Dashboard
</a>
<a href="?page=dashboard&tab=customer_lookup" class="kyc-tab <?= $tab==='customer_lookup'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-person-lines-fill"></i></span> Customers
</a>
<a href="?page=dashboard&tab=service_status" class="kyc-tab <?= $tab==='service_status'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-broadcast"></i></span> Service Status
</a>
<a href="?page=dashboard&tab=support_tickets" class="kyc-tab <?= $tab==='support_tickets'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-headset"></i></span> Tickets
</a>
<?php if(false && $can('bulk_dispatch')): ?>
<a href="?page=dashboard&tab=bulk_dispatch" class="kyc-tab <?= $tab==='bulk_dispatch'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-send-fill"></i></span> Bulk Dispatch
</a>
<?php endif; ?>
<?php if($can('splynx_noc')): ?>
<a href="?page=dashboard&tab=splynx_noc" class="kyc-tab <?= $tab==='splynx_noc'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-hdd-network-fill"></i></span> Splynx NOC
</a>
<?php endif; ?>
<?php if($can('fiber_pipeline')): ?>
<a href="?page=dashboard&tab=fiber_pipeline" class="kyc-tab <?= $tab==='fiber_pipeline'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-diagram-3-fill" style="color:#d41c1c;"></i></span> Fiber Pipeline
    <?php if(($kpiPipeline??0)>0): ?><span class="nav-badge" style="background:#d41c1c;"><?= $kpiPipeline??'' ?></span><?php endif; ?>
</a>
<?php endif; ?>
<?php if($can('splynx_my_jobs')): ?>
<a href="?page=dashboard&tab=splynx_my_jobs" class="kyc-tab <?= $tab==='splynx_my_jobs'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-tools"></i></span> My Install Jobs
</a>
<?php endif; ?>
<?php if($can('live_map')): ?>
<a href="?page=dashboard&tab=live_map" class="kyc-tab <?= $tab==='live_map'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-pin-map-fill"></i></span> Live Staff Map
</a>
<?php endif; ?>
<?php if($can('route_manager')): ?>
<a href="?page=dashboard&tab=route_manager" class="kyc-tab <?= $tab==='route_manager'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-signpost-split-fill"></i></span> Route Manager
</a>
<?php endif; ?>
<?php if($userRole === 'support_leader'): ?>
<a href="?page=dashboard&tab=support_leader_manual" class="kyc-tab <?= $tab==='support_leader_manual'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-book-fill"></i></span> My Manual
</a>
<?php endif; ?>
<?php if($can('field_expenses')): ?>
<a href="?page=dashboard&tab=field_expenses" class="kyc-tab <?= $tab==='field_expenses'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-cash-coin"></i></span> Field Expenses
</a>
<?php endif; ?>
<?php endif; ?>

<?php /*  STOCK  */ ?>
<div class="nav-section">Stock</div>
<a href="?page=dashboard&tab=stock_hub" class="kyc-tab <?= $tab==='stock_hub'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-box-seam-fill"></i></span> Stock Hub
</a>
<?php if($isAdmin || $can('accounts_dash') || $can('support_dash')): ?>
<a href="?page=dashboard&tab=stock_dashboard" class="kyc-tab <?= $tab==='stock_dashboard'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-clipboard-data-fill"></i></span> Stock Admin
</a>
<?php endif; ?>

<?php if ($can('accounts_dash') || $can('collections') || $can('ledger') || $can('settlement') || $can('commissions')): ?>
<?php /*  ACCOUNTS  */ ?>
<?php
//  Badge counts (computed once, used below) 
$_hqPend = 0;
try {
    static $_faNavInst;
    if (!$_faNavInst) { require_once dirname(__DIR__).'/lib/FieldAgentService.php'; $_faNavInst = new FieldAgentService($store); }
    $_hqPend = count($_faNavInst->getRemittances(0, true, 'pending'));
} catch (Throwable $_e) {}
// v4.11.3 PERF: invoice queue count - use nav_badges cache (already computed above)
$_ijPend = 0;
try {
    // UCRM pending jobs + fiber pending jobs combined
    $_ucrmPend = 0;
    try {
        $_ijRowCached = $store->getPdo()->query("SELECT value, updated_at FROM plugin_kv WHERE key='ij_pending_count' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($_ijRowCached && (time() - strtotime($_ijRowCached['updated_at'])) < 30) {
            $_ucrmPend = (int)$_ijRowCached['value'];
        } else {
            $_ijAll  = $store->load('job_invoice_queue.json') ?? [];
            $_ucrmPend = count(array_filter($_ijAll, fn($x) => ($x['status'] ?? 'pending') === 'pending'));
            $store->getPdo()->prepare("INSERT OR REPLACE INTO plugin_kv (key,value,updated_at) VALUES ('ij_pending_count',?,datetime('now'))")->execute([$_ucrmPend]);
        }
    } catch (\Throwable $e) {}
    $_ijPend = $_ucrmPend + (int)($_navBadges['fib_pending'] ?? 0);
} catch (\Throwable $_e) {}
$_expPend = 0;
try {
    static $_expAdvNav;
    if (!$_expAdvNav) { require_once dirname(__DIR__).'/lib/ExpenseAdvanceService.php'; $_expAdvNav = new ExpenseAdvanceService($store, $dataDir); }
    $_expPend = $_expAdvNav->countPending();
} catch (Throwable $_e) {}
?>
<div class="nav-section">Accounts</div>

<?php /*  Company Books  */ ?>
<div class="nav-sub" style="border-color:#7c3aed;">Company Books</div>
<a href="?page=dashboard&tab=accounts_dashboard" class="kyc-tab <?= $tab==='accounts_dashboard'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-bar-chart-fill"></i></span> Accounts Home
</a>
<a href="?page=dashboard&tab=cashbook" class="kyc-tab <?= $tab==='cashbook'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-journal-bookmark-fill"></i></span> Cashbook
</a>
<a href="?page=dashboard&tab=accounts_ledger" class="kyc-tab <?= $tab==='accounts_ledger'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-journal-text"></i></span> Revenue Ledger
</a>
<a href="?page=dashboard&tab=ssp_overview" class="kyc-tab <?= $tab==='ssp_overview'?'active':'' ?>">
    <span class="nav-icon"></span> SSP Overview
</a>
<a href="?page=dashboard&tab=ssp_cashbook" class="kyc-tab <?= $tab==='ssp_cashbook'?'active':'' ?>">
    <span class="nav-icon"></span> SSP Cashbook
</a>
<a href="?page=dashboard&tab=accounts_settlement" class="kyc-tab <?= $tab==='accounts_settlement'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-clipboard-data"></i></span> Settlement
</a>
<a href="?page=dashboard&tab=collection_reconcile" class="kyc-tab <?= $tab==='collection_reconcile'?'active':'' ?>">
    <span class="nav-icon"></span> Reconcile
</a>

<?php /*  Cash Operations  */ ?>
<div class="nav-sub" style="border-color:#16a34a;">Cash Operations</div>
<a href="?page=dashboard&tab=accounts_collections" class="kyc-tab <?= $tab==='accounts_collections'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-cash-stack"></i></span> Collections
</a>
<a href="?page=dashboard&tab=handover_queue" class="kyc-tab <?= $tab==='handover_queue'?'active':'' ?>" style="position:relative;">
    <span class="nav-icon"></span> Handover Queue
    <?php if ($_hqPend > 0): ?>
    <span style="background:#D41C1C;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:800;margin-left:4px;"><?= $_hqPend ?></span>
    <?php endif; ?>
</a>
<a href="?page=dashboard&tab=invoice_queue" class="kyc-tab <?= $tab==='invoice_queue'?'active':'' ?>" style="position:relative;">
    <span class="nav-icon"></span> Invoice Queue
    <?php if ($_ijPend > 0): ?>
    <span style="background:#D41C1C;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:800;margin-left:4px;"><?= $_ijPend ?></span>
    <?php endif; ?>
</a>

<?php /*  Staff Cash  */ ?>
<div class="nav-sub" style="border-color:#2563eb;">Staff Cash</div>
<a href="?page=dashboard&tab=staff_cash_control" class="kyc-tab <?= $tab==='staff_cash_control'?'active':'' ?>">
    <span class="nav-icon"></span> Staff Positions
</a>
<a href="?page=dashboard&tab=staff_cashbooks" class="kyc-tab <?= $tab==='staff_cashbooks'?'active':'' ?>">
    <span class="nav-icon"></span> Staff Cashbooks
</a>
<a href="?page=dashboard&tab=cash_advances" class="kyc-tab <?= $tab==='cash_advances'?'active':'' ?>">
    <span class="nav-icon"></span> Cash Advances
</a>
<a href="?page=dashboard&tab=expense_approvals" class="kyc-tab <?= $tab==='expense_approvals'?'active':'' ?>" style="position:relative;">
    <span class="nav-icon"></span> Expenses
    <?php if ($_expPend > 0): ?>
    <span style="background:#D41C1C;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;font-weight:800;margin-left:4px;"><?= $_expPend ?></span>
    <?php endif; ?>
</a>

<?php /*  Agent Wallets  */ ?>
<div class="nav-sub" style="border-color:#d97706;">Agent Wallets</div>
<a href="?page=dashboard&tab=accounts_wallets" class="kyc-tab <?= $tab==='accounts_wallets'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-credit-card-2-front"></i></span> Wallets
</a>
<a href="?page=dashboard&tab=accounts_commissions" class="kyc-tab <?= $tab==='accounts_commissions'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-stars"></i></span> Commissions
</a>
<a href="?page=dashboard&tab=accounts_recharges" class="kyc-tab <?= $tab==='accounts_recharges'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-arrow-repeat"></i></span> Recharge History
</a>
<a href="?page=dashboard&tab=fiber_costs" class="kyc-tab <?= $tab==='fiber_costs'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-diagram-3-fill"></i></span> Fiber Costs
</a>

<?php /*  Tools (admin only)  */ ?>
<?php if($isAdmin): ?>
<div class="nav-sub" style="border-color:#64748b;">Tools</div>
<a href="?page=dashboard&tab=commission_cleanup" class="kyc-tab <?= $tab==='commission_cleanup'?'active':'' ?>">
    <span class="nav-icon"></span> Commission Fix
</a>
<?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin || $can('accounts_dash')): ?>
<?php /*  HRM (v4.11.0)  */ ?>
<div class="nav-section">Human Resources</div>
<a href="?page=dashboard&tab=hrm_dashboard" class="kyc-tab <?= $tab==='hrm_dashboard'?'active':'' ?>">
    <span class="nav-icon"></span> HR Dashboard
</a>
<a href="?page=dashboard&tab=hrm_employees" class="kyc-tab <?= $tab==='hrm_employees'?'active':'' ?>">
    <span class="nav-icon"></span> Employees
</a>
<a href="?page=dashboard&tab=hrm_payroll" class="kyc-tab <?= $tab==='hrm_payroll'?'active':'' ?>">
    <span class="nav-icon"></span> Payroll
</a>
<a href="?page=dashboard&tab=hrm_leave" class="kyc-tab <?= $tab==='hrm_leave'?'active':'' ?>">
    <span class="nav-icon"></span> Leave Management
</a>
<?php endif; ?>

<?php if ($isAdmin || $can('all_leads') || $can('recharge_req') || $can('retailers_mgmt') || $can('wallet_admin') || $can('daily_report') || $can('settings') || $can('starlink_pauses') || $can('duplicate_log') || $can('overdue_email_log') || $can('overdue_workbench')): ?>
<?php /*  ADMIN  */ ?>
<div class="nav-section">Admin</div>
<?php if($isAdmin): ?>
<a href="?page=dashboard&tab=ceo_dashboard" class="kyc-tab <?= $tab==='ceo_dashboard'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-speedometer2"></i></span> CEO Dashboard
</a>
<?php endif; ?>
<?php if($can('retailers_mgmt')): ?>
<a href="?page=dashboard&tab=retailers" class="kyc-tab <?= $tab==='retailers'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-people-fill"></i></span> Staff &amp; Retailers
</a>
<?php if ($isAdmin): ?>
<a href="?page=dashboard&tab=system_health" class="kyc-tab <?= $tab==='system_health'?'active':'' ?>">
  <span>System Health</span>
</a>
<?php endif; ?>
<a href="?page=dashboard&tab=roles" class="kyc-tab <?= $tab==='roles'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-shield-lock-fill"></i></span> Roles &amp; Permissions
</a>
<?php endif; ?>
<?php if($can('wallet_admin')): ?>
<a href="?page=dashboard&tab=wallet_admin" class="kyc-tab <?= $tab==='wallet_admin'?'active':'' ?>" style="position:relative;">
    <span class="nav-icon"><i class="bi bi-safe2"></i></span> Wallet Admin<?php if($pendingColCount>0): ?><span style="position:absolute;top:6px;right:6px;background:#DC2626;color:#fff;border-radius:10px;font-size:10px;font-weight:800;min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;padding:0 4px;"><?= $pendingColCount ?></span><?php endif; ?>
</a>
<?php endif; ?>
<?php if($can('recharge_req')): ?>
<a href="?page=dashboard&tab=recharge_requests" class="kyc-tab <?= $tab==='recharge_requests'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-lightning-charge-fill"></i></span> Recharge Requests
    <?php if($pendingCount>0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
</a>
<?php endif; ?>
<?php if($can('all_leads')): ?>
<a href="?page=dashboard&tab=all_leads" class="kyc-tab <?= $tab==='all_leads'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-crosshair"></i></span> All Leads
</a>
<?php endif; ?>
<?php if($can('all_apps')): ?>
<a href="?page=dashboard&tab=all_apps" class="kyc-tab <?= $tab==='all_apps'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-grid-1x2-fill"></i></span> All Orders
</a>
<?php endif; ?>
<?php if($can('all_collections')): ?>
<a href="?page=dashboard&tab=all_collections" class="kyc-tab <?= $tab==='all_collections'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-bank2"></i></span> All Collections
</a>
<?php endif; ?>
<?php if($can('daily_report')): ?>
<a href="?page=dashboard&tab=daily_report" class="kyc-tab <?= $tab==='daily_report'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-graph-up-arrow"></i></span> Daily Report
</a>
<?php endif; ?>
<?php if($can('activity_log')): ?>
<a href="?page=dashboard&tab=activity_log" class="kyc-tab <?= $tab==='activity_log'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-clock-history"></i></span> Activity Log
</a>
<?php endif; ?>
<?php if($can('access_log')): ?>
<a href="?page=dashboard&tab=access_log" class="kyc-tab <?= $tab==='access_log'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-shield-lock"></i></span> Access Log
</a>
<?php endif; ?>
<?php if($isAdmin): ?>
<a href="?page=dashboard&tab=app_logins" class="kyc-tab <?= $tab==='app_logins'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-phone-fill" style="color:#1565C0;"></i></span> Customer App Logins
</a>
<a href="?page=dashboard&tab=starlink_suspensions" class="kyc-tab <?= $tab==='starlink_suspensions'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-shield-slash-fill" style="color:#D41C1C;"></i></span> Starlink Suspensions
</a>
<?php endif; ?>
<?php if($isAdmin || $can('starlink_pauses')): ?>
<a href="?page=dashboard&tab=starlink_pauses" class="kyc-tab <?= $tab==='starlink_pauses'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-pause-circle-fill" style="color:#E65100;"></i></span> Starlink Block Manager
</a>
<?php endif; ?>
<?php /* SIM Cards nav hidden  handled by DishNet 4G */ ?>
<?php if($can('plans')): ?>
<a href="?page=dashboard&tab=subscription_plans" class="kyc-tab <?= $tab==='subscription_plans'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-layers-fill"></i></span> Service Plans
</a>
<?php endif; ?>
<?php if($can('hardware')): ?>
<a href="?page=dashboard&tab=hardware" class="kyc-tab <?= $tab==='hardware'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-router-fill"></i></span> Hardware
</a>
<?php endif; ?>
<?php if($isAdmin): ?>
<a href="?page=dashboard&tab=photo_manager" class="kyc-tab <?= $tab==='photo_manager'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-images"></i></span> Photo Manager
</a>
<a href="?page=dashboard&tab=duplicate_log" class="kyc-tab <?= $tab==='duplicate_log'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-person-fill-exclamation"></i></span> Duplicate Review
</a>
<a href="?page=dashboard&tab=overdue_email_log" class="kyc-tab <?= in_array($tab,['overdue_email_log','overdue_email_tpl'])?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-envelope-exclamation-fill" style="color:#d97706;"></i></span> Overdue Emails
</a>
<a href="?page=dashboard&tab=overdue_email_tpl" class="kyc-tab <?= $tab==='overdue_email_tpl'?'active':'' ?>" style="padding-left:36px;font-size:12px;">
    <span class="nav-icon"><i class="bi bi-pencil-fill" style="color:#94a3b8;font-size:11px;"></i></span> <span style="color:#64748b;">Edit Templates</span>
</a>
<?php endif; ?>
<?php if($isAdmin || in_array(($retailer['role']??''),['accountant','field_accountant'],true)): ?>
<a href="?page=dashboard&tab=overdue_workbench" class="kyc-tab <?= $tab==='overdue_workbench'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-clipboard-data-fill" style="color:#dc2626;"></i></span> Overdue Workbench
</a>
<?php endif; ?>

<?php /*  DISHNET 4G  */ ?>
<?php /*  UCRM SYNC  */ ?>
<div class="nav-section">UCRM</div>
<?php if($can('ucrm_data')): ?>
<a href="?page=dashboard&tab=ucrm_data" class="kyc-tab <?= $tab==='ucrm_data'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-cloud-download-fill"></i></span> Data Sync
</a>
<?php endif; ?>
<?php if($can('sync_queue')): ?>
<a href="?page=dashboard&tab=sync_queue" class="kyc-tab <?= $tab==='sync_queue'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-arrow-up-circle-fill"></i></span> Push Queue
</a>
<?php endif; ?>
<?php endif; ?>

<?php /*  LTE PRIVATE NETWORK  */ ?>
<?php if($can('lte_dashboard')||$can('lte_subscribers')||$can('lte_renewal')||$can('lte_sims')||$isAdmin):
    $lteStats = svc('lte')->getDashboardStats();
    $lteUrgent = ($lteStats['expired']??0) + ($lteStats['expiring_urgent']??0);
?>
<div class="nav-section">LTE Network</div>
<a href="?page=dashboard&tab=lte_dashboard" class="kyc-tab <?= str_starts_with($tab,'lte_')?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-reception-4"></i></span> DishNet 4G
    <?php if($lteUrgent>0): ?><span class="nav-badge"><?= $lteUrgent ?></span><?php endif; ?>
</a>
<?php if($isAdmin||$can('commissions')): ?>
<a href="?page=dashboard&tab=lte_commissions" class="kyc-tab <?= $tab==='lte_commissions'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-award"></i></span> Commissions
</a>
<?php endif; ?>
<?php if($isAdmin||$can('lte_renewal')): ?>
<a href="?page=dashboard&tab=lte_reminders" class="kyc-tab <?= $tab==='lte_reminders'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-whatsapp"></i></span> WA Reminders
</a>
<?php endif; ?>
<?php if($isAdmin): ?>
<a href="?page=dashboard&tab=lte_autosuspend" class="kyc-tab <?= $tab==='lte_autosuspend'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-robot"></i></span> Auto-Suspend
</a>
<?php if($isAdmin || $can('lte_bluecard')): ?>
<a href="?page=dashboard&tab=lte_bluecard" class="kyc-tab <?= $tab==='lte_bluecard'?'active':'' ?>" style="border-left:3px solid #1D4ED8;">
    <span class="nav-icon"><i class="bi bi-sim-fill" style="color:#1D4ED8;"></i></span> BlueCard (4G)
</a>
<?php endif; ?>
<?php if($isAdmin || $can('bc_my_retailers')): ?>
<a href="?page=dashboard&tab=bc_my_retailers" class="kyc-tab <?= $tab==='bc_my_retailers'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-people-fill"></i></span> My Retailers
</a>
<?php endif; ?>
<?php if($isAdmin): ?>
<a href="?page=bc_portal" class="kyc-tab" style="border-left:3px solid #7C3AED;" target="_blank">
    <span class="nav-icon"><i class="bi bi-box-arrow-up-right" style="color:#7C3AED;"></i></span> BC Agent Portal
</a>
<?php endif; ?>
<a href="?page=dashboard&tab=ops_daily_report" class="kyc-tab <?= $tab==='ops_daily_report'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-bar-chart-fill"></i></span> Daily Report
</a>
<a href="?page=dashboard&tab=ops_settlement" class="kyc-tab <?= $tab==='ops_settlement'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-cash-coin"></i></span> Settlement
</a>
<a href="?page=dashboard&tab=ops_sync_health" class="kyc-tab <?= $tab==='ops_sync_health'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-activity"></i></span> Sync Health
</a>
<?php endif; ?>
<?php endif; ?>

<?php /*  SYSTEM  */ ?>
<div class="nav-section">System</div>
<a href="?page=dashboard&tab=training" class="kyc-tab <?= $tab==='training'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-mortarboard-fill"></i></span> Training Hub
</a>
<a href="?page=dashboard&tab=knowledge_base" class="kyc-tab <?= $tab==='knowledge_base'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-book-fill"></i></span> Knowledge Base
</a>
<?php if(!empty($retailer['is_admin'])): ?>
<a href="?page=dashboard&tab=runbook" class="kyc-tab <?= $tab==='runbook'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-clipboard2-pulse-fill"></i></span> Runbook
</a>
<?php endif; ?>
<?php if($can('settings')): ?>
<a href="?page=dashboard&tab=settings" class="kyc-tab <?= $tab==='settings'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-gear-fill"></i></span> Settings
</a>
<?php endif; ?>
<?php if($isAdmin): ?>
<a href="?page=ai_handover" target="_blank" class="kyc-tab" style="color:#7c3aed;" title="AI Developer Handover Package">
    <span class="nav-icon"></span> AI Context
</a>
<?php endif; ?>
<?php if($isAdmin): ?>
<a href="?page=dashboard&tab=notify" class="kyc-tab <?= $tab==='notify'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-send-fill"></i></span> Notify
</a>
<?php endif; ?>
<?php if($can('backup')): ?>
<a href="?page=dashboard&tab=updater" class="kyc-tab <?= $tab==='updater'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-cloud-arrow-up-fill"></i></span> Updater
</a>
<a href="?page=dashboard&tab=backup" class="kyc-tab <?= $tab==='backup'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-safe-fill"></i></span> Backup &amp; Restore
</a>
<?php endif; ?>
<?php if($can('android_app')): ?>
<a href="?page=dashboard&tab=android_app" class="kyc-tab <?= $tab==='android_app'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-android2"></i></span> Android App
    <?php
    $_apkNavMeta = $store->load('android_app_meta.json') ?? [];
    $_apkNavFile = !empty($_apkNavMeta['stored_filename']) ? $dataDir.'/'.$_apkNavMeta['stored_filename'] : '';
    // No fallback to __DIR__  only serve APKs that were explicitly uploaded to data/
    if (file_exists($_apkNavFile)):
        $apkMeta = $_apkNavMeta;
        $apkVer  = $apkMeta['version'] ?? '?';
    ?>
    <span style="background:#22c55e;color:#fff;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:2px;">v<?= h($apkVer) ?></span>
    <?php else: ?>
    <span style="background:#ef4444;color:#fff;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:2px;">NO APK</span>
    <?php endif; ?>
</a>
<?php endif; ?>
<?php if(!empty($retailer['is_admin'])): ?>
<a href="?page=dashboard&tab=api_docs" class="kyc-tab <?= $tab==='api_docs'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-code-square"></i></span> API Docs
</a>
<a href="?page=dashboard&tab=ledger_health" class="kyc-tab <?= $tab==='ledger_health'?'active':'' ?>">
    <span class="nav-icon"><i class="bi bi-heart-pulse-fill"></i></span> Ledger Health
    <?php // v4.11.3 PERF: Ledger Health mismatch badge - cached 60s in plugin_kv
    try {
        $_lhMm = 0;
        $_lhRow = $store->getPdo()->query("SELECT value, updated_at FROM plugin_kv WHERE key='ledger_mismatch_count' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        if ($_lhRow && (time() - strtotime($_lhRow['updated_at'])) < 60) {
            $_lhMm = (int)$_lhRow['value'];
        } else {
            require_once dirname(__DIR__) . '/lib/DualReadCashPosition.php';
            $_lhDual = new DualReadCashPosition($store, $store->getPdo(), $dataDir ?? '');
            $_lhMm   = $_lhDual->countActiveMismatches();
            $store->getPdo()->prepare("INSERT OR REPLACE INTO plugin_kv (key,value,updated_at) VALUES ('ledger_mismatch_count',?,datetime('now'))")->execute([$_lhMm]);
        }
        if ($_lhMm > 0): ?>
        <span style="background:#dc2626;color:#fff;font-size:9px;font-weight:800;padding:1px 5px;border-radius:8px;margin-left:2px;"><?= $_lhMm ?></span>
        <?php endif;
    } catch (\Throwable $_e) {} ?>
</a>
<?php endif; ?>

</div><!-- end kyc-tabs -->
