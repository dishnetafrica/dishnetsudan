<!-- ══════════════════════════════════════════════════════════════════
     ONBOARDING MODAL — shown on first login per user+role.
     Replaced the old single-screen modal with a 3-step onboarding
     flow that actually teaches the person before they start working.
     ═══════════════════════════════════════════════════════════════ -->
<div id="trnWelcomeOverlay" style="position:fixed;inset:0;background:rgba(15,23,42,.75);z-index:9500;display:none;align-items:flex-end;justify-content:center;backdrop-filter:blur(4px);">
    <div id="trnWelcomeModal" style="background:#fff;border-radius:24px 24px 0 0;width:100%;max-width:520px;max-height:92vh;overflow-y:auto;animation:slideUp .35s cubic-bezier(.34,1.56,.64,1);position:relative;">

        <!-- Step dots -->
        <div style="display:flex;justify-content:center;gap:6px;padding:16px 24px 0;" id="trnStepDots">
            <div class="ob-dot ob-dot-active" id="obDot0"></div>
            <div class="ob-dot" id="obDot1"></div>
            <div class="ob-dot" id="obDot2"></div>
        </div>

        <!-- Step 0: Welcome + role identity -->
        <div class="ob-step" id="obStep0" style="padding:24px 24px 28px;">
            <div id="trnWelcomeIcon" style="font-size:52px;text-align:center;margin-bottom:14px;"></div>
            <h2 id="trnWelcomeTitle" style="text-align:center;margin:0 0 8px;font-size:21px;font-weight:800;color:#1e293b;"></h2>
            <p id="trnWelcomeText" style="text-align:center;font-size:13px;color:#64748b;line-height:1.6;margin:0 0 20px;"></p>
            <div id="obRoleBadge" style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;"></div>
            <button onclick="obNext(1)" style="width:100%;background:#1e293b;color:#fff;border:none;border-radius:14px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;">Continue →</button>
            <button onclick="trnDismissWelcome()" style="width:100%;margin-top:8px;background:none;border:none;color:#94a3b8;font-size:12px;cursor:pointer;padding:6px;">Skip onboarding</button>
        </div>

        <!-- Step 1: Your 3 most important things + navigation guide -->
        <div class="ob-step" id="obStep1" style="display:none;padding:24px 24px 28px;">
            <div style="font-size:28px;text-align:center;margin-bottom:8px;">🗺️</div>
            <h3 style="text-align:center;margin:0 0 16px;font-size:17px;font-weight:800;color:#1e293b;">How this app is organised</h3>
            <div id="obNavMap" style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;"></div>
            <button onclick="obNext(2)" style="width:100%;background:#1e293b;color:#fff;border:none;border-radius:14px;padding:14px;font-size:15px;font-weight:700;cursor:pointer;">Got it →</button>
            <button onclick="obNext(0)" style="width:100%;margin-top:8px;background:none;border:none;color:#94a3b8;font-size:12px;cursor:pointer;padding:6px;">← Back</button>
        </div>

        <!-- Step 2: Day 1 checklist + CTA -->
        <div class="ob-step" id="obStep2" style="display:none;padding:24px 24px 28px;">
            <div style="font-size:28px;text-align:center;margin-bottom:8px;">✅</div>
            <h3 style="text-align:center;margin:0 0 6px;font-size:17px;font-weight:800;color:#1e293b;">Your Day 1 checklist</h3>
            <p style="text-align:center;font-size:12px;color:#94a3b8;margin:0 0 16px;">Complete these before you start working</p>
            <div id="obChecklist" style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;"></div>
            <a href="?page=dashboard&tab=training" onclick="trnDismissWelcome()" style="display:block;width:100%;box-sizing:border-box;background:#7C3AED;color:#fff;text-align:center;border-radius:14px;padding:14px;font-size:15px;font-weight:700;text-decoration:none;">🎓 Open Training Hub</a>
            <button onclick="trnDismissWelcome()" style="width:100%;margin-top:8px;background:none;border:none;color:#94a3b8;font-size:12px;cursor:pointer;padding:6px;">Start working (I'll train later)</button>
        </div>
    </div>
</div>
<style>
.ob-dot{width:8px;height:8px;border-radius:50%;background:#e2e8f0;transition:all .3s;}
.ob-dot-active{background:#1e293b;width:24px;border-radius:4px;}
.ob-nav-row{display:flex;align-items:flex-start;gap:12px;background:#f8fafc;border-radius:12px;padding:12px 14px;}
.ob-nav-icon{font-size:22px;width:32px;text-align:center;flex-shrink:0;}
.ob-nav-name{font-size:13px;font-weight:700;color:#1e293b;display:block;margin-bottom:2px;}
.ob-nav-desc{font-size:12px;color:#64748b;line-height:1.4;}
.ob-checklist-item{display:flex;align-items:center;gap:12px;background:#f8fafc;border-radius:12px;padding:12px 14px;}
.ob-check-circle{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;background:#e2e8f0;}
.ob-check-text{font-size:13px;color:#374151;font-weight:600;}
.ob-role-row{display:flex;align-items:center;gap:12px;border-radius:12px;padding:12px 14px;}
.ob-role-can{background:#f0fdf4;border:1px solid #bbf7d0;}
.ob-role-cant{background:#fef2f2;border:1px solid #fecaca;}
.ob-role-icon{font-size:16px;flex-shrink:0;}
.ob-role-text{font-size:12px;color:#374151;font-weight:600;}
</style>
<script>
(function(){
// Ensure escHtml is available in this scope
if(typeof escHtml === 'undefined'){
    window.escHtml = function(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
}
var userId   = <?= (int)$retailerId ?>;
var userRole = '<?= h($userRole) ?>';
var storageKey = 'dishnet_onboarding_v2_' + userId + '_' + userRole;

// ── Role definitions ─────────────────────────────────────────
var roleData = {
    'sales': {
        icon:'🧑‍💼', color:'#2E7D32',
        title:'Welcome to DishNet Sales Hub!',
        subtitle:'You are a Sales Agent',
        text:'Your job is to bring new customers to DishNet, register them, and collect payments. This app is your complete tool for everything.',
        canDo: ['Add leads and track prospects','Register new customers (KYC)','Collect monthly payments','Track your wallet and commissions','Recharge your wallet balance'],
        cantDo: ['Approve recharge requests (Admin only)','Access financial reports (Accountant)','Modify system settings'],
        navMap: [
            ['📋','New KYC','Register a new Starlink/Fiber/LTE customer','?page=dashboard&tab=form'],
            ['📂','My Applications','Track customers you registered and their sync status','?page=dashboard&tab=applications'],
            ['💵','Collect Payment','Receive monthly payment from an existing customer','?page=dashboard&tab=collect_payment'],
            ['💰','My Wallet','Your balance, history, commission earnings','?page=dashboard&tab=wallet'],
            ['🎯','My Leads','Track prospects before they subscribe','?page=dashboard&tab=leads'],
        ],
        checklist: [
            ['📖','Complete Training Hub','Learn KYC, wallet, leads, and collections step by step','?page=dashboard&tab=training'],
            ['💰','Check your wallet balance','Make sure you have enough float to start working','?page=dashboard&tab=wallet'],
            ['📋','Try adding a lead','Practice with a real prospect from your list','?page=dashboard&tab=leads'],
            ['❓','Read the FAQ','Quickly learn answers to common questions','?page=dashboard&tab=faq'],
        ],
    },
    'support': {
        icon:'🎧', color:'#7B1FA2',
        title:'Welcome to DishNet Support!',
        subtitle:'You are Support Staff',
        text:'Your job is to help existing customers when they have issues. You look up accounts, check service status, and log tickets.',
        canDo: ['Look up any customer by name/phone/CRM ID','Check if a service is active or suspended','Create and manage support tickets','View support dashboard and open cases'],
        cantDo: ['Process payments or create subscriptions (Sales)','Approve wallets or recharges (Admin)','View financial reports (Accountant)'],
        navMap: [
            ['🔍','Customer Lookup','Search any customer — name, phone, or CRM ID','?page=dashboard&tab=customer_lookup'],
            ['📡','Service Status','Check if internet is active/suspended/cancelled','?page=dashboard&tab=service_status'],
            ['🎫','Support Tickets','Log and track customer complaints','?page=dashboard&tab=support_tickets'],
            ['📊','Dashboard','Today\'s open cases and activity summary','?page=dashboard&tab=support_dashboard'],
        ],
        checklist: [
            ['📖','Complete Support Training','Learn customer lookup, service status, and ticketing','?page=dashboard&tab=training'],
            ['🔍','Try a customer search','Search for any customer to see how it works','?page=dashboard&tab=customer_lookup'],
            ['📡','Check a service status','Practice looking up service state','?page=dashboard&tab=service_status'],
            ['❓','Read the FAQ','Common support scenarios answered','?page=dashboard&tab=faq'],
        ],
    },
    'accountant': {
        icon:'📊', color:'#E65100',
        title:'Welcome to DishNet Accounts!',
        subtitle:'You are the Accountant',
        text:'Your job is to track all money movement: collections, wallet balances, commissions, settlements, and daily financial reports.',
        canDo: ['View all collections by any agent','Run daily settlement reports','Check wallet balances and ledger','Calculate commissions earned','Monitor pending recharge requests'],
        cantDo: ['Approve or reject recharges (Admin only)','Process payments or KYC (Sales)','Change system settings'],
        navMap: [
            ['📊','Accounts Dashboard','Daily snapshot of all financial activity','?page=dashboard&tab=accounts_dashboard'],
            ['💵','All Collections','Every payment processed by every agent','?page=dashboard&tab=accounts_collections'],
            ['📋','Retailer Ledger','Per-agent statement and reconciliation','?page=dashboard&tab=accounts_ledger'],
            ['💹','Daily Settlement','End-of-day settlement calculation','?page=dashboard&tab=accounts_settlement'],
            ['⭐','Commissions','Agent commission earnings report','?page=dashboard&tab=accounts_commissions'],
        ],
        checklist: [
            ['📖','Complete Accounts Training','Learn dashboards, ledger, and settlement workflow','?page=dashboard&tab=training'],
            ['📊','Check today\'s dashboard','See the current financial snapshot','?page=dashboard&tab=accounts_dashboard'],
            ['💵','Review collections','See what has been collected today','?page=dashboard&tab=accounts_collections'],
            ['❓','Read the FAQ','Common accounting scenarios answered','?page=dashboard&tab=faq'],
        ],
    },
    'support_leader': {
        icon:'🛡', color:'#7C3AED',
        title:'Welcome, Support Leader!',
        subtitle:'You manage field engineers and fiber installs',
        text:'Your job is to assign engineers to fiber installation tickets, approve completed jobs, monitor the live staff map, and keep the LTE network healthy. The NOC Dashboard is your home screen.',
        canDo: ['Assign engineers to FTTH install tickets','Approve and commission completed installations','Reject incomplete jobs with a reason','Batch-assign all tickets in an area','Monitor engineers on Live Staff Map','Plan routes for field staff','Manage LTE subscribers and renewals'],
        cantDo: ['Process payments or KYC registration (Sales only)','Approve wallet recharges (Admin only)','View financial reports (Accountant only)'],
        navMap: [
            ['🌐','NOC Dashboard','Your main screen — all fiber install tickets by area','?page=dashboard&tab=splynx_noc'],
            ['🔧','My Install Jobs','Tickets assigned to you personally','?page=dashboard&tab=splynx_my_jobs'],
            ['📍','Live Staff Map','Real-time location of all field engineers','?page=dashboard&tab=live_map'],
            ['🛣','Route Manager','Plan and assign job sequences to engineers','?page=dashboard&tab=route_manager'],
            ['📡','LTE Dashboard','LTE network health and subscriber overview','?page=dashboard&tab=lte_dashboard'],
        ],
        checklist: [
            ['🌐','Open the NOC Dashboard','Check for unassigned and urgent (red) tickets','?page=dashboard&tab=splynx_noc'],
            ['📍','Check the Live Map','See where engineers are before assigning','?page=dashboard&tab=live_map'],
            ['📖','Read your User Manual','Full guide to every feature in your role','?page=dashboard&tab=support_leader_manual'],
            ['📡','Check LTE Renewal Queue','Process any overdue LTE renewals','?page=dashboard&tab=lte_renewal'],
        ],
    },
    'admin': {
        icon:'🛡️', color:'#D41C1C',
        title:'Welcome, Admin!',
        subtitle:'You have full system access',
        text:'You control the entire system: retailers, approvals, CRM settings, reports, and configuration. Read the admin setup checklist carefully.',
        canDo: ['Approve/reject recharge requests','Top up agent wallets directly','Qualify leads for agent conversion','Access all reports and logs','Configure CRM, plans, hardware, settings'],
        cantDo: [],
        navMap: [
            ['📈','Daily Report','Yesterday\'s summary — check every morning','?page=dashboard&tab=daily_report'],
            ['⚡','Recharge Requests','Approve agent wallet recharges','?page=dashboard&tab=recharge_requests'],
            ['🎯','All Leads','Qualify leads so agents can convert them','?page=dashboard&tab=all_leads'],
            ['🔄','Sync Queue','Monitor CRM sync status and failures','?page=dashboard&tab=sync_queue'],
            ['⚙️','Settings','Configure CRM token, plans, hardware','?page=dashboard&tab=settings'],
        ],
        checklist: [
            ['⚙️','Set CRM URL and Token','REQUIRED — without this, no KYC will sync to CRM','?page=dashboard&tab=settings'],
            ['📦','Add Subscription Plans','Agents need plans to register customers','?page=dashboard&tab=subscription_plans'],
            ['💻','Add Hardware Catalog','Ensure Starlink kits and routers are listed','?page=dashboard&tab=hardware'],
            ['👥','Create Staff Accounts','Add Sales Agents, Support, Accountant','?page=dashboard&tab=retailers'],
            ['📖','Complete Admin Training','Learn daily tasks and system management','?page=dashboard&tab=training'],
        ],
    },
};

var rd = roleData[userRole] || roleData['sales'];

// ── Dismiss function ──────────────────────────────────────────
window.trnDismissWelcome = function(){
    try { localStorage.setItem(storageKey, '1'); } catch(e){}
    var el = document.getElementById('trnWelcomeOverlay');
    if(el){ el.style.display = 'none'; document.body.style.overflow = ''; }
};

// ── Step navigation ──────────────────────────────────────────
window.obNext = function(step){
    [0,1,2].forEach(function(i){
        var s = document.getElementById('obStep'+i);
        if(s) s.style.display = i===step ? '' : 'none';
        var d = document.getElementById('obDot'+i);
        if(d){ d.classList.toggle('ob-dot-active', i===step); d.style.width = i===step?'24px':'8px'; }
    });
};

// ── Render onboarding content ────────────────────────────────
function renderOnboarding(){
    // Step 0 — identity
    var ico = document.getElementById('trnWelcomeIcon');
    var ttl = document.getElementById('trnWelcomeTitle');
    var txt = document.getElementById('trnWelcomeText');
    var bdg = document.getElementById('obRoleBadge');
    if(ico) ico.textContent = rd.icon;
    if(ttl) ttl.textContent = rd.title;
    if(txt) txt.textContent = rd.text;
    if(bdg){
        var html = '';
        rd.canDo.forEach(function(c){
            html += '<div class="ob-role-row ob-role-can"><span class="ob-role-icon">✅</span><span class="ob-role-text">'+escHtml(c)+'</span></div>';
        });
        if(rd.cantDo.length){
            rd.cantDo.forEach(function(c){
                html += '<div class="ob-role-row ob-role-cant"><span class="ob-role-icon">🚫</span><span class="ob-role-text">'+escHtml(c)+'</span></div>';
            });
        }
        bdg.innerHTML = html;
    }

    // Step 1 — nav map
    var nm = document.getElementById('obNavMap');
    if(nm){
        var html = '';
        rd.navMap.forEach(function(n){
            html += '<a href="'+n[3]+'" onclick="trnDismissWelcome()" class="ob-nav-row" style="text-decoration:none;color:inherit;">' +
                '<span class="ob-nav-icon">'+n[0]+'</span>' +
                '<div><span class="ob-nav-name">'+escHtml(n[1])+'</span><span class="ob-nav-desc">'+escHtml(n[2])+'</span></div>' +
                '</a>';
        });
        nm.innerHTML = html;
    }

    // Step 2 — checklist
    var cl = document.getElementById('obChecklist');
    if(cl){
        var html = '';
        rd.checklist.forEach(function(c){
            html += '<a href="'+c[3]+'" onclick="trnDismissWelcome()" class="ob-checklist-item" style="text-decoration:none;color:inherit;">' +
                '<div class="ob-check-circle">'+c[0]+'</div>' +
                '<div><div class="ob-check-text">'+escHtml(c[1])+'</div><div style="font-size:11px;color:#94a3b8;margin-top:1px;">'+escHtml(c[2])+'</div></div>' +
                '</a>';
        });
        cl.innerHTML = html;
    }
}

// ── Show on first login ──────────────────────────────────────
var seen = false;
try { seen = !!localStorage.getItem(storageKey); } catch(e){}
if(!seen){
    var el = document.getElementById('trnWelcomeOverlay');
    if(el){
        renderOnboarding();
        el.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}
})();
</script>

<!-- ══════════════════════════════════════════════════════
     CONTEXTUAL HELP BUTTON — floating ? on every page
     Shows a mini cheat sheet for the current tab
     ══════════════════════════════════════════════════════ -->
<div id="ctxHelpBtn" onclick="ctxToggleHelp()" style="position:fixed;bottom:80px;right:16px;width:42px;height:42px;background:#1e293b;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.3);z-index:8000;user-select:none;">?</div>
<div id="ctxHelpPanel" style="position:fixed;bottom:132px;right:16px;width:280px;background:#1e293b;border-radius:16px;padding:14px;z-index:8000;display:none;box-shadow:0 8px 32px rgba(0,0,0,.4);max-height:60vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <span style="font-size:13px;font-weight:700;color:#fff;" id="ctxHelpTitle">Quick Help</span>
        <button onclick="ctxToggleHelp()" style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:16px;">✕</button>
    </div>
    <div id="ctxHelpContent" style="font-size:12px;color:#cbd5e1;line-height:1.6;"></div>
    <a id="ctxHelpTraining" href="?page=dashboard&tab=training" style="display:block;margin-top:10px;background:#7C3AED;color:#fff;text-align:center;padding:8px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;">🎓 Open Full Training</a>
</div>
<script>
(function(){
var currentTab = '<?= h($tab) ?>';
var userRole   = '<?= h($userRole) ?>';

var tabHelp = {
    'form':['📋 Add Customer / KYC',
        ['Always collect cash/transfer BEFORE submitting','Upload both customer photo AND ID document','GPS location helps field technicians locate the customer','Credit payment = no wallet deduction. Cash = immediate deduction','After submit, check My Applications to see sync status']],
    'leads':['🎯 My Leads',
        ['Add a lead to track prospects before they are ready to subscribe','Move stages: New → Contacted → Interested → Quoted (then wait for admin to Qualify)','Once admin qualifies, the green KYC button unlocks for conversion','Add notes after every call so you remember the conversation','Set follow-up date so hot leads - don\'t let them go cold']],
    'collect_payment':['💵 Collect Payment',
        ['Your wallet must have enough balance BEFORE you collect','Search by name or CRM ID — customer must already exist in CRM','After collecting, show the customer the transaction reference','If CRM sync fails, the collection is still recorded locally','Check My Wallet → passbook to see all collected payments']],
    'wallet':['💰 My Wallet',
        ['Balance shown here is YOUR float — not company money','Every KYC and collection you process debits this balance','Low balance? Go to Recharge Wallet and upload payment proof','Commission entries appear as COMMISSION credits','Contact admin if a reversal does not appear within 30 minutes']],
    'wallet_recharge':['💳 Recharge Wallet',
        ['Transfer money to DishNet bank account FIRST, then submit here','Upload a clear screenshot of the transfer confirmation','Your request will be reviewed by Admin — usually 15-60 minutes','You will receive a WhatsApp notification when approved','Never submit a recharge for money you have not actually transferred']],
    'applications':['📂 My Applications',
        ['pending_sync = being sent to CRM, usually takes 1-2 minutes','synced = successfully registered in CRM, customer is live','failed = CRM rejected the data, wallet was automatically refunded','Contact admin if any application stays in pending_sync for over 30 min','Click on an application to see full details and CRM client ID']],
    'support_dashboard':['🎧 Support Dashboard',
        ['Start every shift here to see open cases','Priority cases appear at the top — handle those first','Use Customer Lookup to search BEFORE asking the customer for details','Always log a ticket even if you solve the issue immediately — for records','Update ticket status when resolved so your team knows']],
    'customer_lookup':['🔍 Customer Lookup',
        ['Search by name, phone, or CRM ID','Status field tells you if service is active or suspended','Suspended = usually unpaid bill. Direct to sales agent for payment','Active but no internet = technical issue. Log a ticket and escalate','Always verify identity before sharing account details']],
    'service_status':['📡 Service Status',
        ['ACTIVE = service running, no billing issue','SUSPENDED = check for unpaid invoices in CRM','CANCELLED = subscription ended, cannot be reactivated here','PREPARED = registered but not yet activated by admin','Refresh the page if status seems wrong — CRM updates every few minutes']],
    'support_tickets':['🎫 Support Tickets',
        ['Always include the CRM client ID in the ticket description','Set priority honestly: High only for complete outages','Update status as you work — IN_PROGRESS means you are actively handling it','RESOLVED means fixed, CLOSED means customer confirmed it works','Add internal notes for escalation details (field team name, visit time)']],
    'accounts_dashboard':['📊 Accounts Dashboard',
        ['This refreshes on every page load — data is live','Compare today\'s collections against yesterday\'s for anomalies','High wallet balance with no recent recharge = agent holding cash, follow up','Pending recharges = check proof and approve or reject today','Use this as your morning briefing before starting work']],
    'accounts_collections':['💵 All Collections',
        ['Filter by date to get any period you need','Filter by agent to see one person\'s collections','Export by copying the table for your Excel report','Collection with no CRM reference = API not configured or sync failed','Cross-check totals with agent WhatsApp reports']],
    'accounts_ledger':['📋 Retailer Ledger',
        ['Select agent first, then date range','CREDIT entries = wallet was loaded (top-up/recharge/reversal)','DEBIT entries = agent processed a transaction (KYC/collection/LTE)','Net balance at bottom = what they currently hold in their wallet','Use this for end-of-month agent reconciliation']],
    'accounts_settlement':['💹 Daily Settlement',
        ['Run this at end of day for each agent','Settlement = Total collected - Approved recharges = Net cash due to company','Agent with zero balance = fully settled for the period','Save/screenshot the settlement for your records','Discrepancy? Check for pending CRM syncs or un-approved recharges']],
    'accounts_commissions':['⭐ Commission Report',
        ['DishNet sales staff are on company payroll — commission is OFF by default','Commission Report is available if you add external/partner agents in the future','Employee accounts show zero commission automatically — no config needed','External agent accounts (commission-based) can be created from Retailers tab → Staff Type = External Agent','For payroll staff: use Settlement report for end-of-day reconciliation instead']],
    'retailers':['👥 Retailers & Staff',
        ['Every person using this system needs a Retailer account','Role determines what they can see and do','Staff Type = Employee (payroll, no commission) or External Agent (commission %)','Default password is 123456 — staff must change it on first login','Deactivate (not delete) departing staff to preserve their history','Admin role = full access including approvals and settings']],
    'wallet_admin':['💳 Wallet Admin',
        ['Top up here when agent has confirmed cash deposit','Always add a note (e.g. "Cash deposit 04-Mar, Ref 12345")','Agent gets WhatsApp notification instantly on top-up','This also syncs balance to CRM Org 7 automatically','Never top up without confirming receipt of actual funds']],
    'recharge_requests':['⚡ Recharge Requests',
        ['Click eye icon to view payment proof image','Verify amount in proof matches submitted amount','Approve = wallet credited instantly + WhatsApp sent','Reject = add reason so agent knows what to fix','Process all pending requests within business hours — agents need float to work']],
    'all_leads':['🎯 All Leads (Admin)',
        ['Agents cannot convert leads until YOU qualify them','Review lead details before qualifying — check NID and contact info is real','Source breakdown shows your best acquisition channels','Leads need qualification = agents are waiting, act quickly','Won leads = successfully converted to KYC submissions']],
    'daily_report':['📈 Daily Report',
        ['Best viewed end of day — captures the full day\'s activity','Screenshot this for WhatsApp management report','Top performer is highlighted — use for motivation/recognition','Compare week-over-week to spot trends','Export/print for your physical records']],
    'sync_queue':['🔄 Sync Queue',
        ['Pending = waiting for cron to pick up (max 1 minute wait)','Processing = being sent to CRM right now','Failed = rejected by CRM after 3 tries, wallet was refunded','Most failures = duplicate phone/email or CRM token expired','If all jobs fail at once = check CRM Auth Token in Settings']],
    'settings':['⚙️ Settings',
        ['CRM Base URL and Token MUST be set before KYC will sync','Get CRM token from UCRM: User Profile → API Token','WhatsApp Webhook URL = your n8n automation endpoint','Commission rate applies to ALL agents (individual rates coming soon)','Save settings before navigating away — changes are NOT auto-saved']],
};

var helpData = tabHelp[currentTab];

window.ctxToggleHelp = function(){
    var panel = document.getElementById('ctxHelpPanel');
    var btn   = document.getElementById('ctxHelpBtn');
    if(!panel || !btn) return;
    var isOpen = panel.style.display !== 'none';
    if(isOpen){
        panel.style.display = 'none';
        btn.textContent = '?';
    } else {
        // Populate
        var title   = document.getElementById('ctxHelpTitle');
        var ctxBody = document.getElementById('ctxHelpContent');
        if(helpData){
            title.textContent = helpData[0];
            var html = '<ul style="margin:0;padding-left:14px;">';
            helpData[1].forEach(function(tip){
                html += '<li style="margin-bottom:5px;">' + tip + '</li>';
            });
            html += '</ul>';
            ctxBody.innerHTML = html;
        } else {
            title.textContent = 'Quick Help';
            ctxBody.innerHTML = '<p>Tap any item in the sidebar to navigate. Need more detail? Open the Training Hub from the menu.</p>';
        }
        panel.style.display = 'block';
        btn.textContent = '✕';
    }
};

// Hide on outside click
document.addEventListener('click', function(e){
    var panel = document.getElementById('ctxHelpPanel');
    var btn   = document.getElementById('ctxHelpBtn');
    if(!panel || panel.style.display === 'none') return;
    if(!panel.contains(e.target) && e.target !== btn && !btn.contains(e.target)){
        panel.style.display = 'none';
        btn.textContent = '?';
    }
});
})();
</script>
<!-- HEADER -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@700;800;900&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── DishNet Brand Header ── */
.dn-hdr-logo{display:inline-flex;align-items:flex-end;gap:0;}
.dn-hdr-wm{
  font-family:'Barlow Condensed',sans-serif;font-weight:900;
  color:#FFFFFF;font-size:22px;letter-spacing:-.3px;line-height:1;
  position:relative;cursor:pointer;
}
.dn-hdr-wm::after{
  content:'';position:absolute;bottom:-3px;left:0;right:0;height:2.5px;
  background:linear-gradient(110deg,#D41C1C 0%,#E8521A 60%,#FF7A35 100%);
  border-radius:2px;
}
/* Nav active uses brand red */
.kyc-tab.active{background:var(--nav-active)!important;color:#fff!important;}
.kyc-tab.active .nav-icon{opacity:1;}
/* Ops Hub special button */
a.ops-hub-btn{
  background:linear-gradient(135deg,#D41C1C,#A81515)!important;
  color:#fff!important;margin-bottom:6px;border-radius:10px;
}
a.ops-hub-btn:hover{background:linear-gradient(135deg,#E82020,#C01818)!important;}
</style>

<div class="kyc-header">
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="?page=dashboard" style="text-decoration:none;">
            <div class="dn-hdr-logo">
                <span class="dn-hdr-wm">DishNet</span>
            </div>
        </a>
        <div style="font-size:9px;color:#888;font-weight:600;letter-spacing:.5px;text-transform:uppercase;padding-top:2px;"><?php echo $GLOBALS['_PLUGIN_VER']; ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:6px;min-width:0;">
        <!-- PWA Install Button -->
        <button id="pwaInstallBtn" onclick="pwaInstall()"
          style="display:none;align-items:center;gap:5px;background:linear-gradient(135deg,#D41C1C,#A81515);color:#fff;border:none;border-radius:8px;padding:5px 10px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;font-family:'Barlow Condensed',sans-serif;letter-spacing:.3px;">
          📲 Install
        </button>
        <?php if(!$isSupport&&!$isAccountant): ?>
        <div class="wallet-badge" style="background:rgba(212,28,28,.15);border:1px solid rgba(212,28,28,.25);color:#FF6B6B;">
            <i class="bi bi-wallet2"></i><span class="wlabel">&nbsp;$<?= number_format($myWallet['balance'], 2) ?></span>
        </div>
        <?php endif; ?>
        <div class="user-badge" style="min-width:0;">
            <span style="width:26px;height:26px;background:var(--primary);border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#fff;flex-shrink:0;font-family:'Barlow Condensed',sans-serif;"><?= strtoupper(substr(trim($retailer['name']),0,1)) ?></span>
            <span class="uname" style="color:#CCCCCC;font-family:'Barlow',sans-serif;"><?= h($retailer['name']) ?></span>
            <?php
            $hbColors = ['admin'=>'#D41C1C','support'=>'#8B5CF6','support_leader'=>'#7C3AED','accountant'=>'#F59E0B','sales'=>'#1a7a4a'];
            $hbColor  = $hbColors[$userRole] ?? '#555';
            $hbLabel  = ucfirst(str_replace('_',' ',$userRole));
            ?>
            <span style="background:<?= $hbColor ?>22;color:<?= $hbColor ?>;border:1px solid <?= $hbColor ?>44;font-size:9px;font-weight:800;padding:2px 7px;border-radius:6px;text-transform:uppercase;letter-spacing:.5px;font-family:'Barlow Condensed',sans-serif;white-space:nowrap;"><?= $hbLabel ?></span>
        </div>
        <a href="?page=logout" style="background:rgba(212,28,28,.1);border:1px solid rgba(212,28,28,.2);color:#FF8080;border-radius:8px;padding:7px 10px;font-size:14px;text-decoration:none;display:flex;align-items:center;gap:4px;flex-shrink:0;" title="Logout">
            <i class="bi bi-box-arrow-right"></i><span class="hide-xs" style="font-size:11px;font-weight:600;">Logout</span>
        </a>
    </div>
</div>
