<?php
// Tab: training
// Extracted from public.php on 2026-03-15
?>
<!-- ═══════════════════════════════════════════════════════════════════════
     TRAINING HUB — Role-aware interactive training for all departments
     Works on both mobile and desktop. Progress saved in localStorage.
     ══════════════════════════════════════════════════════════════════════ -->
<?php
// Build role-specific training curriculum
$trainingRole = $userRole;
// Normalize role variants to base roles
$_roleMap = ['support_leader'=>'support','field_accountant'=>'accountant','field_agent'=>'sales','collection'=>'sales'];
if (isset($_roleMap[$trainingRole])) $trainingRole = $_roleMap[$trainingRole];
$roleMeta = [
    'sales'      => ['label'=>'Sales Agent',  'color'=>'#2E7D32', 'bg'=>'#E8F5E9', 'icon'=>'🧑‍💼'],
    'support'    => ['label'=>'Support Staff','color'=>'#7B1FA2', 'bg'=>'#F3E5F5', 'icon'=>'🎧'],
    'accountant' => ['label'=>'Accountant',   'color'=>'#E65100', 'bg'=>'#FFF3E0', 'icon'=>'📊'],
    'admin'      => ['label'=>'Admin',        'color'=>'#1565C0', 'bg'=>'#E3F2FD', 'icon'=>'🛡️'],
];
$rm = $roleMeta[$trainingRole] ?? $roleMeta['sales'];

// Curriculum: lessons per role
$curriculum = [
    'sales' => [
        ['id'=>'s0','icon'=>'🔐','title'=>'First Login — Set Your Password',
         'duration'=>'1 min','link'=>null,
         'steps'=>[
            ['head'=>'Default password is 123456','body'=>'All new accounts are created with the password 123456. The first time you log in, the system will show a full-screen prompt asking you to set a new personal password.'],
            ['head'=>'Choose a strong password','body'=>'Your new password must be at least 8 characters. Use a mix of letters, numbers, or symbols. Do NOT share it with anyone — each person must use their own account.'],
            ['head'=>'After setting password','body'=>'You only need to do this once. From then on, log in with your email and your new password. If you ever forget it, ask your admin to reset it.'],
         ]],
        ['id'=>'s1','icon'=>'🎯','title'=>'Understanding Your Role',
         'duration'=>'3 min','link'=>null,
         'steps'=>[
            ['head'=>'You are a Sales Agent','body'=>'Your job is to bring new customers to DishNet and collect payments from existing customers. You are a DishNet employee — your earnings come through your monthly salary, not transaction commission.'],
            ['head'=>'Your 3 core tasks','body'=>'① Add Leads — capture potential customers before they are ready to subscribe. ② Register Customers (KYC) — when they are ready. ③ Collect Payments — receive money from existing customers.'],
            ['head'=>'Your wallet is your float','body'=>'DishNet gives you a prepaid wallet. Every cash transaction you do debits your wallet. You top it up by submitting a recharge request with proof of payment.'],
            ['head'=>'The golden rule','body'=>'Always collect cash/transfer from the customer BEFORE you process it in the app. The wallet deducts immediately on submit. Never process a payment before receiving the money.'],
            ['head'=>'No commission tracking needed','body'=>'As a DishNet employee on payroll, the commission tab does not apply to you. Focus on your daily collections and registrations — your admin tracks performance separately.'],
         ]],
        ['id'=>'s2','icon'=>'👤','title'=>'Adding a New Lead',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=leads',
         'steps'=>[
            ['head'=>'What is a Lead?','body'=>'A Lead is someone interested in DishNet but not yet registered. You track them here until they are ready — no wallet deducted, no CRM entry yet.'],
            ['head'=>'How to add a lead','body'=>'Go to My Leads → tap "+ Add New Lead". Enter their name, phone, and what service they want (Starlink/Fiber). Optionally fill NID, DOB, address now to save time later.'],
            ['head'=>'Lead stages','body'=>'NEW → CONTACTED → INTERESTED → QUOTED → QUALIFIED (admin approves) → CONVERT TO KYC. Move the lead through these stages as you call them.'],
            ['head'=>'Converting a lead','body'=>'Once admin marks the lead as Qualified, you see a green "✅ KYC" button. Tap it — the KYC form opens pre-filled with everything you already captured. Just upload documents and submit!'],
            ['head'=>'Why use leads?','body'=>'Leads help you never forget a prospect. You can see all your pipeline at a glance, add follow-up notes, and the system reminds you of hot leads.'],
         ]],
        ['id'=>'s3','icon'=>'📋','title'=>'Registering a Customer (KYC)',
         'duration'=>'6 min','link'=>'?page=dashboard&tab=form',
         'steps'=>[
            ['head'=>'KYC = Know Your Customer','body'=>'Before a customer can subscribe to DishNet, you must capture their details: name, phone, address, ID document, and photo. This creates their account in the CRM system.'],
            ['head'=>'Step 1 — Service Type','body'=>'Choose: New Connection, Shifting (moving address), or Ownership Change. Then pick the service: Starlink, Fiber, or DishNet 4G.'],
            ['head'=>'Step 2 — Customer Details','body'=>'Full name, phone (with country code e.g. +211...), email, address. Tap "Detect GPS" to automatically capture the customer\'s location coordinates.'],
            ['head'=>'Step 3 — Plan & Hardware','body'=>'Select the subscription plan. Then on the hardware screen, tap + on each item to add it to your cart. You can add multiple items (e.g. 2 Starlink Mini kits + 1 MikroTik router) with qty controls. The system shows your total wallet deduction in real time.'],
            ['head'=>'Step 4 — KYC Documents','body'=>'Upload customer photo (clear face photo) and National ID / Passport. Also set: sales person, cash or credit payment, referral source.'],
            ['head'=>'Step 5 — Review & Submit','body'=>'Check everything in the summary. If correct, tap Submit. For Cash: your wallet debits immediately. For Credit: no deduction. Customer syncs to CRM in the background.'],
            ['head'=>'After submission','body'=>'You will see the application in My Applications with status "pending_sync". When it turns "synced" (usually within 1 minute), the customer is live in CRM. If it fails 3 times, your wallet is automatically refunded.'],
         ]],
        ['id'=>'s4','icon'=>'💵','title'=>'Collecting a Payment',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=collect_payment',
         'steps'=>[
            ['head'=>'When to collect','body'=>'When an existing customer wants to pay their monthly bill or renew their plan. You collect the cash/transfer from them and process it here.'],
            ['head'=>'Step 1 — Search the customer','body'=>'Go to Collect Payment. Type the customer name or CRM ID in the search box. Results come from the CRM system live.'],
            ['head'=>'Step 2 — Select & enter amount','body'=>'Tap the customer to select them. Use the quick amount buttons ($40, $80, $110...) or type a custom amount. Choose payment method: Cash, Bank Transfer, or Mobile Money.'],
            ['head'=>'Step 3 — Confirm','body'=>'Tap "Collect Payment" and confirm. Your wallet is debited. The payment posts to UCRM automatically and UCRM sends the customer an email receipt. You can also send them a WhatsApp receipt directly from the app.'],
            ['head'=>'Important warning','body'=>'Your wallet MUST have enough balance BEFORE you collect. If the customer pays you $110, you need $110 in your wallet. If your wallet is low, go to Recharge Wallet first.'],
         ]],
        ['id'=>'s5','icon'=>'💰','title'=>'Managing Your Wallet',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=wallet',
         'steps'=>[
            ['head'=>'Check your balance','body'=>'Your current balance is always visible in the top bar. Tap "My Wallet" for full history: every debit and credit with timestamps and references.'],
            ['head'=>'When balance is low','body'=>'Go to Recharge Wallet. Enter the amount, select bank/payment method, upload proof of payment (screenshot or receipt), and submit.'],
            ['head'=>'Recharge process','body'=>'Admin reviews your proof → Approves → Your wallet is credited instantly. You receive a WhatsApp notification when it is approved. This usually takes 15-60 minutes during business hours.'],
            ['head'=>'Wallet is for float only','body'=>'Your wallet holds the float you use to process transactions — it is not your salary. The wallet goes down when you collect/register, and goes back up when admin tops it up. Your salary is paid separately through company payroll.'],
         ]],
        ['id'=>'s6','icon'=>'🏠','title'=>'Sales Dashboard (Home Screen)',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=sales_dashboard',
         'steps'=>[
            ['head'=>'Your new home screen','body'=>'When you log in, you land on the Sales Dashboard. It shows: today\'s collections, cash in hand, monthly total, wallet balance, and your KYC activation funnel.'],
            ['head'=>'Activation funnel','body'=>'Track your KYC customers through: Submitted → In CRM → Active. The activation rate shows what percentage of your registered customers have active internet.'],
            ['head'=>'Needs Follow-up','body'=>'Customers you registered but are not yet activated appear here with Call and WhatsApp buttons. Tap to contact them directly.'],
            ['head'=>'Quick actions','body'=>'Bottom nav: Home (dashboard), Collect (payment), KYC (new registration), My Cash (your money), More (leads, apps, stock).'],
         ]],
        ['id'=>'s7','icon'=>'💰','title'=>'My Cash — Expenses & Handovers',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=my_account',
         'steps'=>[
            ['head'=>'Hero card','body'=>'Shows your SSP bag (if you hold SSP) and USD cash. The numbers reflect real money you physically hold — collections minus expenses minus handovers.'],
            ['head'=>'Cash Out (Expenses)','body'=>'Tap Cash Out to record an expense: select SSP or USD, enter amount, choose category (Fuel, Transport, Parts, etc.), add description, take a receipt photo. The system checks you have enough balance before allowing submission.'],
            ['head'=>'Handover','body'=>'When you bring cash to the office, tap Handover → enter amount → select who you\'re handing to (search by name). Accountant confirms receipt — your cash balance goes down, wallet is refilled. No double entry: the revenue was already recorded when you collected the payment.'],
            ['head'=>'SSP & USD Cashbook','body'=>'Tap the SSP Cashbook or USD Cashbook buttons to see every transaction: who gave you money, what you spent, running balance. Like a bank statement for each currency.'],
            ['head'=>'Receipt photos','body'=>'Always take a photo of receipts when spending. Tap the receipt icon on any expense to view the photo in a popup — no need to leave the page.'],
         ]],
    ],
    'support' => [
        ['id'=>'sp1','icon'=>'🎯','title'=>'Understanding Your Role',
         'duration'=>'3 min','link'=>null,
         'steps'=>[
            ['head'=>'You are Support Staff','body'=>'Your job is to help DishNet customers when they have problems: internet not working, login issues, plan queries, billing questions. You use this system to look up their accounts and log tickets.'],
            ['head'=>'Your 4 core tools','body'=>'① Customer Lookup — search any customer by name/phone/CRM ID. ② Service Status — check if a customer\'s service is active or suspended. ③ Support Tickets — log and track issues. ④ Support Dashboard — see today\'s open cases at a glance.'],
            ['head'=>'What you cannot do','body'=>'You cannot process payments or create new subscriptions — that is Sales. You cannot approve payments or edit wallets — that is Admin. Your role is diagnose and escalate.'],
         ]],
        ['id'=>'sp2','icon'=>'🔍','title'=>'Looking Up a Customer',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=customer_lookup',
         'steps'=>[
            ['head'=>'When a customer calls','body'=>'First thing: identify them. Go to Customer Lookup and search by name, phone number, or CRM client ID. The system searches your CRM in real time.'],
            ['head'=>'What you will see','body'=>'Customer name, CRM ID, service type, plan, subscription status (Active/Suspended/Cancelled), contact details, and address.'],
            ['head'=>'Key fields to check','body'=>'① Status — is their service currently Active? ② Balance — do they have outstanding invoices? ③ Service type — Starlink / Fiber / LTE? ④ Sales agent — who registered them?'],
            ['head'=>'Create a support ticket','body'=>'If you cannot resolve the issue, tap "New Ticket" next to the customer. Select category (Technical/Billing/Other), describe the problem, set priority. The ticket is logged with the customer\'s CRM ID.'],
         ]],
        ['id'=>'sp3','icon'=>'📡','title'=>'Checking Service Status',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=service_status',
         'steps'=>[
            ['head'=>'What Service Status shows','body'=>'This tab lets you search a customer and see their exact service state from the CRM: Active, Suspended, Cancelled, or Prepared.'],
            ['head'=>'Common scenarios','body'=>'"My internet is not working" → Check Status. If Suspended: usually unpaid bill → direct to Sales for payment. If Active: technical issue → escalate to field technician.'],
            ['head'=>'Interpreting statuses','body'=>'ACTIVE = service running normally. SUSPENDED = unpaid invoice or admin action. CANCELLED = subscription ended. PREPARED = registered but not yet activated.'],
            ['head'=>'What to tell the customer','body'=>'SUSPENDED: "Your account is suspended due to an outstanding balance. Please contact your sales agent to pay." ACTIVE but no internet: "Your account is active — this is a technical issue. I am logging a ticket."'],
         ]],
        ['id'=>'sp4','icon'=>'🎫','title'=>'Managing Support Tickets',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=support_tickets',
         'steps'=>[
            ['head'=>'Support Tickets tab','body'=>'This is your ticketing system. Every customer complaint or issue should be logged here for tracking and accountability.'],
            ['head'=>'Creating a ticket','body'=>'Either from Customer Lookup (tap "New Ticket") or from the Support Tickets tab directly. Always include: customer CRM ID, description of the problem, priority (Low/Medium/High).'],
            ['head'=>'Ticket statuses','body'=>'OPEN → IN PROGRESS → RESOLVED → CLOSED. Update the status as you work on the issue. Resolved means you believe it is fixed. Closed means customer confirmed it is working.'],
            ['head'=>'Escalation','body'=>'For technical issues in the field (hardware, cabling, dish alignment): escalate to the field team via WhatsApp and note the ticket number. For billing issues: route to Admin.'],
         ]],
        ['id'=>'sp5','icon'=>'📡','title'=>'FTTH Installation Command Center',
         'duration'=>'5 min','link'=>'?page=dashboard&tab=splynx_my_jobs',
         'steps'=>[
            ['head'=>'Your main tool','body'=>'The Install tab is your command center. It shows every fiber installation ticket from Splynx: New, Surveyed, Deploying, ONU Ready, Waiting, and Resolved.'],
            ['head'=>'Understanding the numbers','body'=>'NEW = just registered. SURVEYED = site checked. DEPLOYING = cable being laid. ONU READY = hardware mapped. WAITING = customer/agent response needed. PENDING (red) = total installations you need to plan for.'],
            ['head'=>'Blocked vs Cancelled','body'=>'BLOCKED = fiber not available or client not ready — these need follow-up. CANCELLED = customer cancelled. Both are shown separately so nothing is hidden.'],
            ['head'=>'Queue tab','body'=>'Shows all pending tickets with customer name, area, and assigned engineer. Use the area dropdown to filter. Tap any ticket to assign an engineer, add notes, or mark status.'],
            ['head'=>'Testing tab','body'=>'After installation, mark as "Ready for Testing". The ticket moves here. Once tested and confirmed, mark as Resolved.'],
            ['head'=>'Done tab','body'=>'All completed installations. When you mark a ticket resolved, the system auto-creates a job for Rupesh to create the CRM invoice.'],
            ['head'=>'Sync with Splynx','body'=>'Tap "Sync Splynx" to pull latest tickets. This happens automatically every 5 minutes, but you can force it anytime.'],
         ]],
        ['id'=>'sp6','icon'=>'💰','title'=>'My Cash — SSP & USD Cashbooks',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=my_account',
         'steps'=>[
            ['head'=>'Dual currency hero','body'=>'Your My Cash page shows SSP Bag (South Sudanese Pounds from office) and USD Cash. Both show real balances: money received minus money spent minus handovers.'],
            ['head'=>'Recording expenses','body'=>'Tap Cash Out → select SSP or USD → enter amount → choose category (Fuel, Parts, Transport) → take receipt photo → submit. Accountants and field accountants are auto-approved.'],
            ['head'=>'SSP & USD Cashbook buttons','body'=>'Tap SSP Cashbook or USD Cashbook to see a full bank-statement view: every transaction with date, description, amount, and running balance.'],
            ['head'=>'Handover','body'=>'When returning cash to office, tap Handover → search for recipient → enter amount. Accountant confirms — your cash balance drops, wallet is refilled. Revenue was already in the system when you collected.'],
         ]],
        ['id'=>'sp7','icon'=>'📦','title'=>'Stock Hub',
         'duration'=>'2 min','link'=>'?page=dashboard&tab=stock_hub',
         'steps'=>[
            ['head'=>'What is Stock Hub','body'=>'View available equipment: routers, cables, ONUs, mounting kits. Check quantities before going to a customer site.'],
            ['head'=>'Barcode scanner','body'=>'If you have a Bluetooth barcode scanner, tap the scan icon to quickly look up any item by its barcode.'],
            ['head'=>'Stock for KYC','body'=>'When you register a KYC with hardware, stock is auto-deducted. The Stock Hub shows what is available in real time.'],
         ]],
    ],
    'accountant' => [
        ['id'=>'a1','icon'=>'🎯','title'=>'Understanding Your Role',
         'duration'=>'3 min','link'=>null,
         'steps'=>[
            ['head'=>'You are the Accountant','body'=>'Your job is to track all money movement: what was collected, what was paid, who owes what, commissions earned, and daily financial summaries.'],
            ['head'=>'Your 6 core reports','body'=>'① Accounts Dashboard — today\'s snapshot. ② All Collections — every payment processed. ③ Retailer Ledger — per-agent statement. ④ Daily Settlement — end-of-day reconciliation. ⑤ Wallet Balances — how much each agent holds. ⑥ Commission Report — earned commissions per agent.'],
            ['head'=>'What you cannot do','body'=>'You cannot process payments or approve recharges — that is Admin. Your role is to monitor, verify, and report. Flag discrepancies to Admin.'],
         ]],
        ['id'=>'a2','icon'=>'📊','title'=>'Daily Accounts Dashboard',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=accounts_dashboard',
         'steps'=>[
            ['head'=>'Start your day here','body'=>'The Accounts Dashboard shows today\'s key numbers: total collected today, pending recharges, total wallets outstanding, and active agents.'],
            ['head'=>'Key metrics to watch','body'=>'① Today\'s Collections — should match your WhatsApp reports from agents. ② Pending Recharges — agents waiting for wallet top-up (review these daily). ③ Outstanding Wallets — agents with high balance who have not settled.'],
            ['head'=>'Red flags','body'=>'Agent with very high wallet balance: they may have collected cash but not settled. Pending recharge with no proof uploaded: follow up. Collection with no CRM match: possible error — investigate.'],
         ]],
        ['id'=>'a3','icon'=>'📋','title'=>'Collections & Ledger',
         'duration'=>'5 min','link'=>'?page=dashboard&tab=accounts_collections',
         'steps'=>[
            ['head'=>'All Collections tab','body'=>'Every payment collected by every agent, with date, amount, customer name, payment method, and agent name. Filter by date range, agent, or payment method.'],
            ['head'=>'Retailer Ledger','body'=>'Select an agent to see their full statement: every wallet credit (top-up, recharge approval) and debit (KYC, payment collected, SIM activation). This is your per-agent reconciliation tool.'],
            ['head'=>'Verifying a collection','body'=>'Cross-check with CRM: if a collection shows in this system but not in CRM, the API sync may have failed. Flag to Admin with the transaction ID.'],
            ['head'=>'Export for reporting','body'=>'Use the date filter to pull a period\'s data. Copy the table for your Excel report. The settlement tab auto-calculates period totals.'],
         ]],
        ['id'=>'a4','icon'=>'💹','title'=>'Daily Settlement',
         'duration'=>'5 min','link'=>'?page=dashboard&tab=accounts_settlement',
         'steps'=>[
            ['head'=>'What is settlement','body'=>'End-of-day settlement reconciles what each agent collected vs what they should hand over. Collections minus approved recharges = net cash due.'],
            ['head'=>'Running the settlement','body'=>'Select date range → select agent (or all) → the system calculates: total collected, wallet balance, recharges approved, commissions earned, and net amount to settle.'],
            ['head'=>'Settlement workflow','body'=>'Agent deposits cash to bank/safe → Admin approves their recharge request → their wallet resets. Settlement report confirms the cycle is complete.'],
            ['head'=>'Discrepancies','body'=>'If collected amount does not match expected: check if any collections are in "pending CRM sync" (not yet posted). Check if agent has multiple outstanding recharges. Escalate to Admin if gap persists.'],
         ]],
        ['id'=>'a5','icon'=>'⭐','title'=>'Commission Reports',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=accounts_commissions',
         'steps'=>[
            ['head'=>'How commissions work','body'=>'Agents earn a percentage commission on every KYC registration and every payment collected. The rate is set by Admin in Settings.'],
            ['head'=>'Reading the commission report','body'=>'Select agent and date range. You see: number of KYCs, KYC commission earned, collections processed, collection commission, and total commission due.'],
            ['head'=>'Paying commissions','body'=>'Commission is tracked here but paid separately (cash or bank transfer by Admin). Use this report to calculate end-of-month commission payments.'],
         ]],
        ['id'=>'a6','icon'=>'💵','title'=>'Handover Queue & Cash Control',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=handover_queue',
         'steps'=>[
            ['head'=>'What is Handover Queue','body'=>'When field staff bring cash to the office, they submit a handover. You see it here with amount, who submitted, and when. Tap "Record" to confirm receipt — their cash balance drops and wallet is refilled. No new cashbook entry is created because the revenue was already recorded when the customer paid.'],
            ['head'=>'Cash Location card','body'=>'The dashboard shows exactly where all cash is: Office (Rupesh), and each field agent with their balance. Red amounts = field staff holding cash.'],
            ['head'=>'Record vs Nudge','body'=>'📥 Record = staff is here, you counted the cash, confirm it. 📲 Nudge = send WhatsApp reminder to bring cash to office.'],
            ['head'=>'Agent cards','body'=>'Each agent shows: cash in hand, today\'s collections, pending handover amount. The numbers come from real ledger data — collections minus expenses minus confirmed handovers.'],
         ]],
        ['id'=>'a7','icon'=>'🧾','title'=>'Expense Approvals',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=expense_approvals',
         'steps'=>[
            ['head'=>'Pending expenses','body'=>'When staff submit expenses (fuel, parts, transport), they appear here for your approval. You see: who submitted, category, amount (SSP or USD), receipt photo, and date.'],
            ['head'=>'Approve or Reject','body'=>'Tap the receipt photo to verify (opens in popup). If valid: Quick Approve. If not: Reject with reason. Auto-approved expenses (field accountants) show as already approved.'],
            ['head'=>'Filters','body'=>'Filter by status (Pending/Approved/Rejected), category, date range, or flagged only (duplicates, no receipt, overspend).'],
            ['head'=>'SSP amounts','body'=>'SSP expenses show the amount in SSP (e.g. 30,000 SSP). The system tracks SSP and USD separately.'],
         ]],
        ['id'=>'a8','icon'=>'📒','title'=>'Staff Cashbooks',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=staff_cashbooks',
         'steps'=>[
            ['head'=>'Per-agent ledger','body'=>'Select any staff member to see their complete cashbook: every collection, expense, handover, advance, and transfer with dates and running balance.'],
            ['head'=>'USD and SSP tabs','body'=>'Each agent\'s cashbook shows both currencies. SSP balance = SSP received from office minus SSP expenses minus SSP handovers. USD balance = collections minus expenses minus handovers.'],
            ['head'=>'Void and Edit','body'=>'You can void incorrect entries (with reason) or edit pending expenses. Voiding cascades: if you void an expense, the matching cash-in for the recipient is also voided automatically.'],
            ['head'=>'CSV Export','body'=>'Tap the download icon to export any agent\'s cashbook as CSV for your records or Tally import.'],
         ]],
        ['id'=>'a9','icon'=>'🔧','title'=>'Fiber Installation Jobs',
         'duration'=>'3 min','link'=>null,
         'steps'=>[
            ['head'=>'How it works','body'=>'When Bidal completes a fiber installation and creates the service in Splynx, the system automatically creates a job for you: "Create CRM invoice for [Customer Name]."'],
            ['head'=>'Dashboard notification','body'=>'You see it in Needs Attention: "🔧 2 fiber installs need CRM invoice $178". Tap to see customer details, plan, and amount.'],
            ['head'=>'Your action','body'=>'Go to CRM → find the customer → create the invoice manually. Then mark the job as done in the plugin. The technician later collects payment using the normal Collect Payment flow.'],
            ['head'=>'WhatsApp alerts','body'=>'You also receive a WhatsApp message with full details: customer name, phone, area, plan, and amount. So you can act even without opening the plugin.'],
         ]],
    ],
    'admin' => [
        ['id'=>'ad1','icon'=>'🎯','title'=>'Understanding Your Role',
         'duration'=>'4 min','link'=>null,
         'steps'=>[
            ['head'=>'You are the Admin','body'=>'You control everything: retailer accounts, wallet top-ups, recharge approvals, settings, CRM connection, daily reports, and system configuration.'],
            ['head'=>'Your daily checklist','body'=>'Morning: ① Check Daily Report for yesterday\'s summary. ② Review pending Recharge Requests and approve/reject. ③ Check Sync Queue for any failed CRM syncs. ④ Qualify any leads flagged for review.'],
            ['head'=>'Your weekly tasks','body'=>'① Review all agents\' wallet balances. ② Run settlement report. ③ Check commission report and pay agents. ④ Review All Applications for any stuck in pending_sync.'],
            ['head'=>'Critical settings to configure first','body'=>'① CRM Base URL + Auth Token (Settings tab) — without this, KYC does not sync. ② WhatsApp Webhook URL — for notifications. ③ Subscription Plans — agents need plans to sell. ④ Hardware catalog — select available kits.'],
         ]],
        ['id'=>'ad2','icon'=>'👥','title'=>'Managing Retailers & Agents',
         'duration'=>'5 min','link'=>'?page=dashboard&tab=retailers',
         'steps'=>[
            ['head'=>'Retailers tab','body'=>'Every person who uses this system has a "Retailer" account. This includes Sales Agents, Support Staff, Accountant, and other Admins.'],
            ['head'=>'Creating an account','body'=>'Tap "+ Add Retailer/Staff". Enter name, email, phone, password. Set Role: sales / support / accountant / admin. Set is_admin only for full admins.'],
            ['head'=>'Roles and access','body'=>'SALES: can do KYC, collect payments, manage their leads. SUPPORT: customer lookup, service status, tickets only. ACCOUNTANT: all financial reports, read-only. ADMIN: everything including approvals.'],
            ['head'=>'Importing from CRM','body'=>'If staff already exist in your CRM, use "Import from CRM Staff" to automatically create accounts without re-entering data. Their CRM credentials link the accounts.'],
            ['head'=>'Deactivating an agent','body'=>'Edit the retailer and uncheck "Active". They immediately lose login access. Their transaction history is preserved for auditing.'],
         ]],
        ['id'=>'ad3','icon'=>'💳','title'=>'Wallet Admin & Recharges',
         'duration'=>'5 min','link'=>'?page=dashboard&tab=wallet_admin',
         'steps'=>[
            ['head'=>'Wallet Admin tab','body'=>'This is where you top up agent wallets directly. Use this when an agent has confirmed cash deposit and you need to credit them immediately.'],
            ['head'=>'Manual top-up','body'=>'Select agent → enter amount → add note (e.g. "Cash deposit 04-Mar") → Top Up. Wallet credits instantly and agent receives WhatsApp notification.'],
            ['head'=>'Recharge Requests tab','body'=>'Agents submit recharge requests with proof of payment (bank screenshot). You review the proof here and Approve or Reject.'],
            ['head'=>'Approving a recharge','body'=>'Click the eye icon to view the proof image. Verify the amount matches. Click Approve → wallet credits instantly → agent notified on WhatsApp. Add a note if rejecting.'],
            ['head'=>'Two-Org CRM sync','body'=>'When you top up an agent\'s wallet, the system also syncs the balance to the FTTH Project org in CRM (Org 7) and creates a CRM invoice automatically.'],
         ]],
        ['id'=>'ad4','icon'=>'🔗','title'=>'CRM Sync & Queue',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=sync_queue',
         'steps'=>[
            ['head'=>'How CRM sync works','body'=>'When an agent submits a KYC, it is queued for background sync. The cron job (runs every minute) picks it up and creates the client in CRM. This usually takes 30-60 seconds.'],
            ['head'=>'Sync Queue tab','body'=>'Shows all pending, processing, and recently synced/failed jobs. Pending = waiting. Processing = in progress. Failed = tried 3 times, gave up (wallet refunded automatically).'],
            ['head'=>'Failed syncs','body'=>'A failed sync means the CRM rejected the data. Common reasons: duplicate phone/email in CRM, invalid plan ID, CRM token expired. Check the error message in the queue.'],
            ['head'=>'Fixing a failed sync','body'=>'Fix the data: go to All Applications → edit the application (fix name/phone/email) → the retry happens automatically OR manually re-trigger from Sync Queue.'],
            ['head'=>'CRM token expired','body'=>'If ALL syncs are failing at once: go to Settings → update the CRM Auth Token. Get the new token from UCRM Admin → User Profile → API Token.'],
         ]],
        ['id'=>'ad5','icon'=>'🏆','title'=>'Leads Management (Admin View)',
         'duration'=>'4 min','link'=>'?page=dashboard&tab=all_leads',
         'steps'=>[
            ['head'=>'All Leads tab','body'=>'You see every lead from every agent. You can see the full pipeline: how many are open, quoted, need qualification, and how many were converted.'],
            ['head'=>'Qualifying leads','body'=>'Before a sales agent can convert a lead to KYC, YOU must qualify it. This prevents random social media contacts being registered without proper vetting.'],
            ['head'=>'Qualification workflow','body'=>'Agent captures lead → moves to QUOTED stage → you see "X need qualification" alert → review lead details → click "🔒 Qualify" → agent can now convert to KYC.'],
            ['head'=>'Source analytics','body'=>'The source breakdown at the top of All Leads shows where your leads come from: Social Media, Cold Call, BBC, etc. Use this to understand which channel produces the most customers.'],
         ]],
        ['id'=>'ad6','icon'=>'📈','title'=>'Daily Report & Activity Log',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=daily_report',
         'steps'=>[
            ['head'=>'Daily Report','body'=>'End-of-day summary: total KYCs, total collections, revenue by service type, active agents, and top performer. Share this with management. Print/screenshot for records.'],
            ['head'=>'Activity Log','body'=>'Every action in the system is logged: who did what, when. Use this for auditing suspicious activity or understanding what happened if something goes wrong.'],
            ['head'=>'Backup & Restore','body'=>'Google Drive auto-backup runs daily at 3 AM. It creates TWO files: CODE zip (uploadable to UCRM Plugins) and DATA zip (for SSH restore). You get a WhatsApp notification when backup completes. To restore on a new server: upload CODE zip to UCRM, SCP DATA zip, unzip, update CRM token.'],
         ]],
        ['id'=>'ad7','icon'=>'👤','title'=>'Customer 360°',
         'duration'=>'3 min','link'=>null,
         'steps'=>[
            ['head'=>'Full customer view','body'=>'Customer 360° shows everything about a customer in one place: service status, invoices, payments, support tickets, KYC details, and equipment. Accessible by all roles.'],
            ['head'=>'Cancellation flow','body'=>'From Customer 360°, tap Cancel → select reason (Too expensive, Moved away, Equipment issue, etc.) → choose equipment status (Returned/Pending/Keeps) → optional refund amount → confirm. CRM service is updated, WhatsApp sent to admin, equipment tracked.'],
            ['head'=>'Plan changes','body'=>'Plan upgrades and downgrades are handled in CRM directly. Customer 360° shows the current plan and status for reference.'],
         ]],
        ['id'=>'ad8','icon'=>'📦','title'=>'Stock Management',
         'duration'=>'3 min','link'=>'?page=dashboard&tab=stock_dashboard',
         'steps'=>[
            ['head'=>'Stock Dashboard','body'=>'View all equipment: routers, kits, cables, ONUs. See quantities, categories, and recent movements. KYC hardware is auto-deducted from stock.'],
            ['head'=>'Stock In/Out','body'=>'Record new stock arrivals or manual deductions. Each movement is logged with date, quantity, reason, and who recorded it.'],
            ['head'=>'Barcode Scanner','body'=>'Connect a Bluetooth barcode scanner for quick item lookup. Scan any equipment barcode and the system shows details and quantity.'],
         ]],
        ['id'=>'ad9','icon'=>'☁️','title'=>'Google Drive Backup',
         'duration'=>'2 min','link'=>'?page=dashboard&tab=whatsapp&subtab=gdrive',
         'steps'=>[
            ['head'=>'Auto backup','body'=>'Runs daily at 3 AM Juba time. Creates two ZIPs: CODE (plugin files, uploadable to UCRM) and DATA (database, JSON, photos). Both uploaded to Google Drive.'],
            ['head'=>'Setup','body'=>'Go to WhatsApp Settings → Backup tab. Enter Google Drive client ID and secret. Click Authorize. Set schedule (daily/twice daily/weekly) and retention (how many backups to keep).'],
            ['head'=>'Manual backup','body'=>'Click "Backup Now" to trigger immediately. You receive a WhatsApp notification with file names and sizes when complete.'],
            ['head'=>'Restore process','body'=>'Download CODE zip from Drive → upload to UCRM Plugins. Download DATA zip → SCP to server → unzip -o → chown 33:33. Update CRM token in Settings. Run Full Sync from UCRM Data tab.'],
         ]],
    ],
];

$lessons = $curriculum[$trainingRole] ?? $curriculum['sales'];
if ($isAdmin) $lessons = $curriculum['admin'];

// Pre-compute dark colour variant for gradient (used in CSS below)
function adjustColor(string $hex): string {
    $hex = ltrim($hex, '#');
    [$r,$g,$b] = [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
    return sprintf('#%02x%02x%02x',max(0,(int)($r*.7)),max(0,(int)($g*.7)),max(0,(int)($b*.7)));
}
$rmDark = adjustColor($rm['color']);
?>

<!-- ── Training Hub Styles ─────────────────────────────── -->
<style>
.trn-hero{background:linear-gradient(135deg,<?= $rm['color'] ?> 0%,<?= $rmDark ?> 100%);border-radius:16px;padding:28px 24px;color:#fff;margin-bottom:16px;position:relative;overflow:hidden;}
.trn-hero::before{content:'';position:absolute;right:-20px;top:-20px;width:120px;height:120px;background:rgba(255,255,255,.1);border-radius:50%;}
.trn-hero::after{content:'';position:absolute;right:30px;bottom:-30px;width:80px;height:80px;background:rgba(255,255,255,.07);border-radius:50%;}
.trn-hero h2{margin:0 0 4px;font-size:20px;font-weight:800;position:relative;}
.trn-hero p{margin:0;font-size:13px;opacity:.85;position:relative;}
.trn-progress-bar{background:rgba(255,255,255,.25);border-radius:20px;height:6px;margin-top:14px;position:relative;}
.trn-progress-fill{background:#fff;border-radius:20px;height:6px;transition:width .5s ease;}
.trn-progress-label{font-size:11px;opacity:.8;margin-top:5px;}

.trn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-bottom:16px;}
.trn-card{background:#fff;border-radius:14px;border:2px solid #e5e7eb;padding:16px;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;}
.trn-card:hover{border-color:<?= $rm['color'] ?>;box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-1px);}
.trn-card.done{border-color:#10b981;background:#f0fdf4;}
.trn-card.done::after{content:'✓';position:absolute;top:10px;right:12px;background:#10b981;color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;}
.trn-card-icon{font-size:28px;margin-bottom:8px;display:block;}
.trn-card-title{font-size:14px;font-weight:700;color:#1e293b;margin-bottom:4px;}
.trn-card-meta{font-size:11px;color:#94a3b8;display:flex;align-items:center;gap:8px;}
.trn-card-meta .dur{background:#f1f5f9;padding:2px 8px;border-radius:10px;}

/* Lesson Modal */
.trn-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9000;display:none;align-items:flex-end;justify-content:center;}
.trn-overlay.show{display:flex;}
.trn-modal{background:#fff;border-radius:24px 24px 0 0;width:100%;max-width:640px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;animation:slideUp .3s cubic-bezier(.34,1.56,.64,1);}
@keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
.trn-modal-header{padding:20px 20px 0;display:flex;justify-content:space-between;align-items:flex-start;flex-shrink:0;}
.trn-modal-title{font-size:17px;font-weight:800;color:#1e293b;}
.trn-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;padding:0;line-height:1;}
.trn-modal-body{padding:16px 20px;overflow-y:auto;flex:1;}
.trn-steps{display:flex;flex-direction:column;gap:0;}
.trn-step{display:flex;gap:12px;padding:14px 0;border-bottom:1px solid #f1f5f9;}
.trn-step:last-child{border-bottom:none;}
.trn-step-num{width:28px;height:28px;border-radius:50%;background:<?= $rm['color'] ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0;margin-top:1px;}
.trn-step-content{}
.trn-step-head{font-size:14px;font-weight:700;color:#1e293b;margin-bottom:3px;}
.trn-step-body{font-size:13px;color:#475569;line-height:1.6;}
.trn-modal-footer{padding:14px 20px;border-top:1px solid #f1f5f9;display:flex;gap:8px;flex-shrink:0;}
.trn-btn-done{flex:1;background:<?= $rm['color'] ?>;color:#fff;border:none;border-radius:10px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;}
.trn-btn-try{background:<?= $rm['bg'] ?>;color:<?= $rm['color'] ?>;border:2px solid <?= $rm['color'] ?>;border-radius:10px;padding:12px 16px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;}

/* Welcome banner */
.trn-welcome{background:<?= $rm['bg'] ?>;border:2px solid <?= $rm['color'] ?>22;border-radius:14px;padding:16px;margin-bottom:16px;display:flex;gap:14px;align-items:center;}
.trn-welcome-icon{font-size:36px;flex-shrink:0;}
.trn-welcome-text h3{margin:0 0 4px;font-size:15px;font-weight:800;color:<?= $rm['color'] ?>;}
.trn-welcome-text p{margin:0;font-size:12px;color:#64748b;line-height:1.5;}

/* Quick reference cards */
.trn-ref-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:16px;}
.trn-ref-card{background:#fff;border-radius:12px;border:1px solid #e5e7eb;padding:14px;}
.trn-ref-card-head{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:8px;}
.trn-ref-item{font-size:12px;color:#374151;padding:3px 0;border-bottom:1px solid #f8fafc;display:flex;justify-content:space-between;}
.trn-ref-item:last-child{border-bottom:none;}
.trn-ref-item strong{color:#1e293b;}

/* Mobile bottom nav label tip */
.trn-nav-guide{background:#1e293b;border-radius:14px;padding:16px;color:#e2e8f0;margin-bottom:16px;}
.trn-nav-guide h4{margin:0 0 10px;font-size:13px;font-weight:700;color:#fff;}
.trn-nav-item{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #334155;}
.trn-nav-item:last-child{border-bottom:none;}
.trn-nav-emoji{font-size:18px;width:28px;text-align:center;}
.trn-nav-text{font-size:12px;}
.trn-nav-text strong{color:#fff;display:block;font-size:13px;}

@media(min-width:640px){
.trn-overlay{align-items:center;}
.trn-modal{border-radius:20px;max-height:80vh;}
}
@media(max-width:420px){
.trn-grid{grid-template-columns:1fr;}
.trn-ref-grid{grid-template-columns:1fr 1fr;}
}
</style>



<!-- ── Hero Banner ──────────────────────────────────────── -->
<div class="trn-hero">
    <div style="position:relative;">
        <div style="font-size:32px;margin-bottom:6px;"><?= $rm['icon'] ?></div>
        <h2><?= h($rm['label']) ?> Training Hub</h2>
        <p>Everything you need to master your role at DishNet Africa</p>
        <div class="trn-progress-bar">
            <div class="trn-progress-fill" id="trnProgressFill" style="width:0%"></div>
        </div>
        <div class="trn-progress-label" id="trnProgressLabel">Loading progress...</div>
    </div>
</div>

<!-- ── Welcome message ─────────────────────────────────── -->
<div class="trn-welcome">
    <div class="trn-welcome-icon">👋</div>
    <div class="trn-welcome-text">
        <h3>Welcome, <?= h($retailer['name']) ?>!</h3>
        <p>Complete all <?= count($lessons) ?> lessons to master your role. Tap any lesson card to start. Your progress is saved automatically on this device.</p>
    </div>
</div>

<!-- ── Lesson cards ─────────────────────────────────────── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:8px;">📚 Lessons</div>
<div class="trn-grid" id="trnLessonGrid">
<?php foreach ($lessons as $idx => $lesson): ?>
<div class="trn-card" id="trnCard_<?= h($lesson['id']) ?>" onclick="trnOpenLesson(<?= $idx ?>)">
    <span class="trn-card-icon"><?= $lesson['icon'] ?></span>
    <div class="trn-card-title"><?= h($lesson['title']) ?></div>
    <div class="trn-card-meta">
        <span class="dur">⏱ <?= h($lesson['duration']) ?></span>
        <span><?= count($lesson['steps']) ?> steps</span>
        <?php if ($lesson['link']): ?>
        <span style="color:<?= $rm['color'] ?>;font-weight:700;">↗ Has shortcut</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Quick Reference (role-specific cheat sheet) ─────── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:16px 0 8px;">⚡ Quick Reference</div>
<div class="trn-ref-grid">
<?php if ($trainingRole === 'sales' || $isAdmin): ?>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">💰 Wallet Rules</div>
    <div class="trn-ref-item"><span>Cash KYC</span><strong>Debits wallet</strong></div>
    <div class="trn-ref-item"><span>Credit KYC</span><strong>No deduction</strong></div>
    <div class="trn-ref-item"><span>Collect Payment</span><strong>Debits wallet</strong></div>
    <div class="trn-ref-item"><span>Admin Top-up</span><strong>Credits wallet</strong></div>
    <div class="trn-ref-item"><span>CRM sync fail</span><strong>Auto-refund</strong></div>
</div>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">📋 KYC Checklist</div>
    <div class="trn-ref-item"><span>Customer photo</span><strong>Required</strong></div>
    <div class="trn-ref-item"><span>NID / Passport</span><strong>Required</strong></div>
    <div class="trn-ref-item"><span>Phone (+211...)</span><strong>Required</strong></div>
    <div class="trn-ref-item"><span>GPS location</span><strong>Recommended</strong></div>
    <div class="trn-ref-item"><span>Sales person</span><strong>Required</strong></div>
</div>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">🎯 Lead Stages</div>
    <div class="trn-ref-item"><span>New</span><strong style="color:#6b7280;">Just added</strong></div>
    <div class="trn-ref-item"><span>Contacted</span><strong style="color:#D41C1C;">Called/messaged</strong></div>
    <div class="trn-ref-item"><span>Interested</span><strong style="color:#7B1FA2;">Wants to subscribe</strong></div>
    <div class="trn-ref-item"><span>Quoted</span><strong style="color:#E65100;">Price shared</strong></div>
    <div class="trn-ref-item"><span>Qualified ✅</span><strong style="color:#2E7D32;">Admin approved</strong></div>
</div>
<?php endif; ?>
<?php if ($trainingRole === 'support' || $isAdmin): ?>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">📡 Service Statuses</div>
    <div class="trn-ref-item"><span>Active</span><strong style="color:#2E7D32;">Running normally</strong></div>
    <div class="trn-ref-item"><span>Suspended</span><strong style="color:#dc3545;">Unpaid invoice</strong></div>
    <div class="trn-ref-item"><span>Cancelled</span><strong style="color:#6b7280;">Subscription ended</strong></div>
    <div class="trn-ref-item"><span>Prepared</span><strong style="color:#D41C1C;">Not activated yet</strong></div>
</div>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">🎫 Ticket Priorities</div>
    <div class="trn-ref-item"><span>Low</span><strong style="color:#6b7280;">General query</strong></div>
    <div class="trn-ref-item"><span>Medium</span><strong style="color:#E65100;">Service degraded</strong></div>
    <div class="trn-ref-item"><span>High</span><strong style="color:#dc3545;">No internet / urgent</strong></div>
    <div class="trn-ref-item"><span>Escalate</span><strong style="color:#7B1FA2;">→ Field team</strong></div>
</div>
<?php endif; ?>
<?php if ($trainingRole === 'accountant' || $isAdmin): ?>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">📊 Daily Checklist</div>
    <div class="trn-ref-item"><span>09:00</span><strong>Check Dashboard</strong></div>
    <div class="trn-ref-item"><span>10:00</span><strong>Review pending recharges</strong></div>
    <div class="trn-ref-item"><span>12:00</span><strong>Mid-day collections check</strong></div>
    <div class="trn-ref-item"><span>17:00</span><strong>Run Daily Settlement</strong></div>
    <div class="trn-ref-item"><span>18:00</span><strong>Export & file report</strong></div>
</div>
<?php endif; ?>
<?php if ($isAdmin): ?>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">🛡️ Admin Daily Tasks</div>
    <div class="trn-ref-item"><span>Morning</span><strong>Daily Report</strong></div>
    <div class="trn-ref-item"><span>Morning</span><strong>Approve Recharges</strong></div>
    <div class="trn-ref-item"><span>Anytime</span><strong>Qualify leads</strong></div>
    <div class="trn-ref-item"><span>Evening</span><strong>Check Sync Queue</strong></div>
    <div class="trn-ref-item"><span>Weekly</span><strong>Backup data</strong></div>
</div>
<div class="trn-ref-card">
    <div class="trn-ref-card-head">⚙️ Key Settings</div>
    <div class="trn-ref-item"><span>CRM URL</span><strong>API connection</strong></div>
    <div class="trn-ref-item"><span>Auth Token</span><strong>From UCRM profile</strong></div>
    <div class="trn-ref-item"><span>Plans</span><strong>Add before selling</strong></div>
    <div class="trn-ref-item"><span>Commission %</span><strong>Agent earnings</strong></div>
    <div class="trn-ref-item"><span>WhatsApp URL</span><strong>Notifications</strong></div>
</div>
<?php endif; ?>
</div>

<!-- ── Mobile Navigation Guide ─────────────────────────── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:16px 0 8px;">📱 Mobile Navigation Guide</div>
<div class="trn-nav-guide">
    <h4>Bottom Bar (mobile) — what each icon does</h4>
    <?php
    $mobileNav = [];
    if ($isSales || $isAdmin) $mobileNav = [
        ['📋','Add Customer','Opens the KYC registration wizard — use this to register a new subscriber'],
        ['📂','My Applications','See all customers you have registered and their sync status'],
        ['💵','Collect Payment','Receive payment from an existing customer — searches CRM live'],
        ['💰','My Wallet','Your balance, top-up history, and commission earnings'],
        ['≡','More','Access Leads, Recharge Wallet, Help Guide, and other tools'],
    ];
    elseif ($isSupport) $mobileNav = [
        ['🔍','Customer Lookup','Search any customer by name, phone, or CRM ID'],
        ['📡','Service Status','Check if a customer\'s internet is active or suspended'],
        ['🎫','Tickets','Log and track support issues for customers'],
        ['📊','Dashboard','Today\'s open cases and activity summary'],
        ['≡','More','Help Guide, FAQ, and settings'],
    ];
    elseif ($isAccountant) $mobileNav = [
        ['📊','Accounts','Dashboard — today\'s financial snapshot'],
        ['💵','Collections','All payments collected today by all agents'],
        ['📋','Ledger','Per-agent wallet statement and reconciliation'],
        ['💹','Settlement','Daily settlement calculation and reports'],
        ['≡','More','Commission report, wallet balances, recharge history'],
    ];
    foreach ($mobileNav as $nav):
    ?>
    <div class="trn-nav-item">
        <span class="trn-nav-emoji"><?= $nav[0] ?></span>
        <div class="trn-nav-text">
            <strong><?= h($nav[1]) ?></strong>
            <?= h($nav[2]) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── System Flow Diagram ─────────────────────────────── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:20px 0 10px;">🗺️ How the System Works — Full Flow</div>
<div style="background:#fff;border-radius:16px;border:1px solid #e5e7eb;padding:16px;margin-bottom:16px;overflow-x:auto;">
    <div style="font-size:12px;color:#64748b;margin-bottom:14px;line-height:1.5;">This diagram shows how a customer journey flows through all departments — from first contact to active subscriber to monthly billing.</div>

    <!-- Flow diagram using pure HTML/CSS boxes and arrows -->
    <div style="min-width:320px;">

    <!-- Row 1: Sales entry points -->
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:6px;text-align:center;">① SALES ENTRY</div>
    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:8px;">
        <div style="background:#E8F5E9;border:2px solid #2E7D32;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700;color:#2E7D32;text-align:center;">
            📞 Cold Call / Tally<br><span style="font-size:10px;font-weight:400;color:#555;">Agent calls prospects</span>
        </div>
        <div style="background:#E8F5E9;border:2px solid #2E7D32;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700;color:#2E7D32;text-align:center;">
            📣 Marketing / BBC<br><span style="font-size:10px;font-weight:400;color:#555;">Radio/social media lead</span>
        </div>
        <div style="background:#E8F5E9;border:2px solid #2E7D32;border-radius:10px;padding:8px 14px;font-size:12px;font-weight:700;color:#2E7D32;text-align:center;">
            🚶 Walk-in<br><span style="font-size:10px;font-weight:400;color:#555;">Customer visits office</span>
        </div>
    </div>

    <!-- Arrow -->
    <div style="text-align:center;font-size:18px;color:#94a3b8;margin:2px 0;">↓</div>

    <!-- Row 2: Lead capture -->
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin:6px 0;text-align:center;">② LEAD PIPELINE (Sales Agent)</div>
    <div style="background:#FFF3E0;border:2px solid #E65100;border-radius:10px;padding:12px;margin-bottom:6px;">
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:4px;">
            <?php foreach (['🆕 New','📞 Contacted','💡 Interested','💰 Quoted'] as $stage): ?>
            <div style="background:#fff;border:1px solid #E65100;border-radius:6px;padding:4px 8px;font-size:11px;font-weight:700;color:#E65100;"><?= $stage ?></div>
            <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:#555;margin-top:6px;">Agent manages lead in <strong>My Leads</strong> tab → Admin qualifies → Agent converts to KYC</div>
    </div>

    <!-- Admin qualification gate -->
    <div style="text-align:center;margin:4px 0;">
        <div style="display:inline-flex;align-items:center;gap:6px;background:#D41C1C;color:#fff;border-radius:8px;padding:6px 14px;font-size:11px;font-weight:700;">
            🛡️ Admin Qualification Gate
        </div>
    </div>
    <div style="text-align:center;font-size:18px;color:#94a3b8;margin:2px 0;">↓</div>

    <!-- Row 3: KYC Registration -->
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin:6px 0;text-align:center;">③ KYC REGISTRATION (Sales Agent)</div>
    <div style="background:#E3F2FD;border:2px solid #1565C0;border-radius:10px;padding:12px;margin-bottom:6px;">
        <div style="font-size:12px;font-weight:700;color:#1565C0;margin-bottom:6px;">Sales Agent fills KYC form:</div>
        <div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px;">
            <?php foreach (['Customer photo','National ID','Phone +211...','Address + GPS','Subscription plan','Cash or Credit'] as $f): ?>
            <span style="background:#fff;border:1px solid #1565C0;border-radius:5px;padding:2px 8px;font-size:10px;font-weight:600;color:#D41C1C;"><?= $f ?></span>
            <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:#555;"><strong>Cash KYC</strong> → Wallet debited immediately &nbsp;|&nbsp; <strong>Credit KYC</strong> → No deduction (lead in CRM)</div>
    </div>

    <!-- Arrow + CRM Sync -->
    <div style="text-align:center;font-size:18px;color:#94a3b8;margin:2px 0;">↓</div>
    <div style="text-align:center;margin:4px 0;">
        <div style="display:inline-flex;align-items:center;gap:6px;background:#263238;color:#fff;border-radius:8px;padding:6px 14px;font-size:11px;font-weight:700;">
            🔄 Background CRM Sync (cron, ~1 min)
        </div>
    </div>
    <div style="font-size:11px;color:#64748b;text-align:center;margin:4px 0;">WhatsApp sent to agent on success/failure</div>
    <div style="text-align:center;font-size:18px;color:#94a3b8;margin:2px 0;">↓</div>

    <!-- Row 4: CRM + Support + Accounts split -->
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin:6px 0;text-align:center;">④ ACTIVE SUBSCRIBER</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;">
        <div style="background:#F3E5F5;border:2px solid #7B1FA2;border-radius:10px;padding:10px;text-align:center;">
            <div style="font-size:18px;">🎧</div>
            <div style="font-size:11px;font-weight:700;color:#7B1FA2;margin:4px 0;">Support</div>
            <div style="font-size:10px;color:#555;">Handles issues, logs tickets</div>
        </div>
        <div style="background:#E3F2FD;border:2px solid #1565C0;border-radius:10px;padding:10px;text-align:center;">
            <div style="font-size:18px;">🛡️</div>
            <div style="font-size:11px;font-weight:700;color:#1565C0;margin:4px 0;">Admin</div>
            <div style="font-size:10px;color:#555;">Manages subscriptions in CRM</div>
        </div>
        <div style="background:#E8F5E9;border:2px solid #2E7D32;border-radius:10px;padding:10px;text-align:center;">
            <div style="font-size:18px;">🧑‍💼</div>
            <div style="font-size:11px;font-weight:700;color:#2E7D32;margin:4px 0;">Sales</div>
            <div style="font-size:10px;color:#555;">Collects monthly payment</div>
        </div>
    </div>

    <!-- Row 5: Monthly billing cycle -->
    <div style="text-align:center;font-size:18px;color:#94a3b8;margin:2px 0;">↓</div>
    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin:6px 0;text-align:center;">⑤ MONTHLY BILLING CYCLE</div>
    <div style="background:#FFF3E0;border:2px solid #E65100;border-radius:10px;padding:12px;">
        <div style="font-size:11px;color:#555;line-height:1.8;">
            1. Agent collects cash from customer → posts to <strong>Collect Payment</strong> (debits wallet)<br>
            2. Agent tops up wallet via <strong>Recharge Request</strong> (uploads bank proof)<br>
            3. Admin approves recharge → wallet refilled → <strong>Accountant</strong> runs settlement<br>
            4. <strong>Accountant</strong> generates commission report → Admin pays agents
        </div>
    </div>
    </div><!-- end min-width wrapper -->
</div>

<!-- ── Scenario Practice ─────────────────────────────────── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:20px 0 10px;">🎮 Scenario Practice — "What do you do when..."</div>
<div id="trnScenarios" style="display:flex;flex-direction:column;gap:10px;margin-bottom:16px;"></div>

<!-- ── Admin: Staff Training Monitor ────────────────────────── -->
<?php if ($isAdmin): ?>
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:20px 0 10px;">📊 Staff Training Monitor</div>
<div style="background:#fff;border-radius:16px;border:1px solid #e5e7eb;padding:16px;margin-bottom:16px;">
    <div style="font-size:12px;color:#64748b;margin-bottom:14px;">Shows each staff member's Training Hub completion. Progress is stored in their browser (localStorage) — this pulls from their last recorded progress when they view this admin panel.</div>
    <?php
    $allRetailers = $store->load('retailers.json');
    $activeStaff  = array_filter($allRetailers, fn($r) => !empty($r['is_active']) && !empty($r['name']));
    $roleLabels   = ['sales'=>'Sales','support'=>'Support','accountant'=>'Accountant','admin'=>'Admin'];
    $roleColors   = ['sales'=>'#2E7D32','support'=>'#7B1FA2','accountant'=>'#E65100','admin'=>'#1565C0'];
    $roleLessonsCount = ['sales'=>8,'support'=>7,'accountant'=>9,'admin'=>9];
    ?>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="background:#f8fafc;font-size:10px;text-transform:uppercase;color:#6b7280;letter-spacing:.5px;">
            <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #e5e7eb;">Staff Member</th>
            <th style="padding:8px 10px;text-align:center;border-bottom:1px solid #e5e7eb;">Role</th>
            <th style="padding:8px 10px;text-align:center;border-bottom:1px solid #e5e7eb;">Lessons</th>
            <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #e5e7eb;">Progress</th>
        </tr></thead>
        <tbody>
        <?php foreach ($activeStaff as $st): ?>
        <?php
            $stRole  = $st['role'] ?? 'sales';
            $stTotal = $roleLessonsCount[$stRole] ?? 5;
            $stColor = $roleColors[$stRole] ?? '#6b7280';
            $stLabel = $roleLabels[$stRole] ?? ucfirst($stRole);
        ?>
        <tr style="border-bottom:1px solid #f1f5f9;" id="staffTrnRow_<?= $st['id'] ?>">
            <td style="padding:10px;font-weight:600;color:#1e293b;"><?= h($st['name']) ?><br><span style="font-size:10px;font-weight:400;color:#94a3b8;"><?= h($st['email']) ?></span></td>
            <td style="padding:10px;text-align:center;"><span style="background:<?= $stColor ?>22;color:<?= $stColor ?>;border-radius:6px;padding:2px 8px;font-weight:700;font-size:10px;"><?= $stLabel ?></span></td>
            <td style="padding:10px;text-align:center;color:#475569;"><?= $stTotal ?></td>
            <td style="padding:10px;">
                <div style="background:#f1f5f9;border-radius:6px;height:8px;width:100%;position:relative;" title="Progress tracked client-side">
                    <div id="staffTrnBar_<?= $st['id'] ?>" style="background:#e2e8f0;border-radius:6px;height:8px;width:0%;transition:width .5s;"></div>
                </div>
                <div id="staffTrnLabel_<?= $st['id'] ?>" style="font-size:10px;color:#94a3b8;margin-top:3px;">Loading...</div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="font-size:11px;color:#94a3b8;margin:12px 0 0;"><em>Note: Training progress is stored in each staff member's browser. This panel shows your own browser's recorded data — progress auto-updates when staff members visit the Training Hub on the same device.</em></p>
</div>
<?php endif; ?>

<!-- ── Print Cheat Sheet ──────────────────────────────────── -->
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin:20px 0 10px;">🖨️ Printable Cheat Sheet</div>
<div style="background:#fff;border-radius:16px;border:1px solid #e5e7eb;padding:16px;margin-bottom:16px;">
    <p style="font-size:13px;color:#475569;margin:0 0 14px;">A one-page summary of everything for your role — print it and keep it at your desk. Works on mobile too (share/save as PDF from your browser).</p>
    <button onclick="trnPrintCheatSheet()" style="background:#1e293b;color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;">
        <span>🖨️</span> Print / Save as PDF
    </button>
</div>

<!-- Print styles -->
<style>
@media print {
    body > *:not(#trnPrintSheet){ display:none !important; }
    #trnPrintSheet{ display:block !important; }
    .kyc-sidebar, .mobile-nav, .kyc-tabs, #ctxHelpBtn, #ctxHelpPanel { display:none !important; }
}
#trnPrintSheet{ display:none; }
</style>
<div id="trnPrintSheet">
    <!-- Filled by JS before printing -->
</div>

<!-- ── Lesson Modal ─────────────────────────────────────── -->
<div class="trn-overlay" id="trnOverlay" onclick="trnCloseOnBg(event)">
    <div class="trn-modal">
        <div class="trn-modal-header">
            <div>
                <div id="trnModalIcon" style="font-size:24px;margin-bottom:2px;"></div>
                <div class="trn-modal-title" id="trnModalTitle"></div>
                <div style="font-size:11px;color:#94a3b8;margin-top:2px;" id="trnModalMeta"></div>
            </div>
            <button class="trn-modal-close" onclick="trnCloseLesson()">✕</button>
        </div>
        <div class="trn-modal-body">
            <div class="trn-steps" id="trnSteps"></div>
        </div>
        <div class="trn-modal-footer">
            <button class="trn-btn-done" onclick="trnMarkDone()">✅ Mark as Done</button>
            <a class="trn-btn-try" id="trnTryBtn" href="#" style="display:none;">Try it →</a>
        </div>
    </div>
</div>

<script>
(function(){
var ROLE = '<?= h($trainingRole) ?>';
var STORAGE_KEY = 'dishnet_training_' + ROLE + '_<?= $retailerId ?>';
var lessons = <?= json_encode($lessons, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var currentIdx = 0;

// Load progress
function getProgress(){
    try{ return JSON.parse(localStorage.getItem(STORAGE_KEY)||'{}'); }catch(e){ return {}; }
}
function saveProgress(p){ try{ localStorage.setItem(STORAGE_KEY, JSON.stringify(p)); }catch(e){} }

function renderProgress(){
    var p = getProgress();
    var done = Object.keys(p).filter(function(k){ return p[k]; }).length;
    var total = lessons.length;
    var pct = total > 0 ? Math.round((done/total)*100) : 0;
    var fill = document.getElementById('trnProgressFill');
    var label = document.getElementById('trnProgressLabel');
    if(fill) fill.style.width = pct + '%';
    if(label) label.textContent = done + ' of ' + total + ' lessons completed (' + pct + '%)';

    lessons.forEach(function(l){
        var card = document.getElementById('trnCard_' + l.id);
        if(!card) return;
        if(p[l.id]){ card.classList.add('done'); }
        else { card.classList.remove('done'); }
    });
}

window.trnOpenLesson = function(idx){
    currentIdx = idx;
    var lesson = lessons[idx];
    if(!lesson) return;
    document.getElementById('trnModalIcon').textContent = lesson.icon;
    document.getElementById('trnModalTitle').textContent = lesson.title;
    document.getElementById('trnModalMeta').textContent = '⏱ ' + lesson.duration + ' · ' + lesson.steps.length + ' steps';

    var stepsHtml = '';
    lesson.steps.forEach(function(s, i){
        stepsHtml += '<div class="trn-step">' +
            '<div class="trn-step-num">' + (i+1) + '</div>' +
            '<div class="trn-step-content">' +
            '<div class="trn-step-head">' + escHtml(s.head) + '</div>' +
            '<div class="trn-step-body">' + escHtml(s.body) + '</div>' +
            '</div></div>';
    });
    document.getElementById('trnSteps').innerHTML = stepsHtml;

    var tryBtn = document.getElementById('trnTryBtn');
    if(lesson.link){
        tryBtn.href = lesson.link;
        tryBtn.style.display = '';
    } else {
        tryBtn.style.display = 'none';
    }

    document.getElementById('trnOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';
};

window.trnCloseLesson = function(){
    document.getElementById('trnOverlay').classList.remove('show');
    document.body.style.overflow = '';
};

window.trnCloseOnBg = function(e){
    if(e.target === document.getElementById('trnOverlay')) trnCloseLesson();
};

window.trnMarkDone = function(){
    var lesson = lessons[currentIdx];
    if(!lesson) return;
    var p = getProgress();
    p[lesson.id] = true;
    saveProgress(p);
    renderProgress();
    trnCloseLesson();

    // Brief celebration toast
    var toast = document.createElement('div');
    toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#10b981;color:#fff;padding:10px 20px;border-radius:30px;font-size:13px;font-weight:700;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.2);';
    toast.textContent = '✅ Lesson complete!';
    document.body.appendChild(toast);
    setTimeout(function(){ toast.style.opacity='0'; toast.style.transition='opacity .5s'; }, 1500);
    setTimeout(function(){ document.body.removeChild(toast); }, 2100);
};


// Init
renderProgress();

// ── Scenarios ──────────────────────────────────────────────────
var scenariosByRole = {
    'sales': [
        {q:'A customer wants to subscribe to Starlink and is ready to pay cash today.',
         steps:['Go to New KYC tab','Select Starlink → New Connection','Fill customer name, phone (+211...), address','Tap Detect GPS for location','Upload customer photo + NID scan','Select plan and hardware kit','Set Sales Type = Cash','Submit — wallet debits automatically']},
        {q:'A customer is interested but says "I need to think about it." You want to track them.',
         steps:['Go to My Leads tab','Tap + Add New Lead','Fill their name and phone','Set service = Starlink/Fiber/SIM (whatever they want)','Set status = Contacted','Set a follow-up date (2-3 days)','Add a note: what they said, their hesitation','They stay in your pipeline — you see them every time you open Leads']},
        {q:'Your wallet balance is too low to process a collection.',
         steps:['Go to Recharge Wallet tab','Enter the amount you need','Select payment method (bank/mobile money)','Transfer the money to DishNet account first','Upload screenshot of the transfer as proof','Submit the request','Admin will approve — you get WhatsApp notification (usually 15-60 min)']},
        {q:'You submitted a KYC but it shows "failed" in My Applications.',
         steps:['The wallet debit has been automatically reversed (check My Wallet)','Do NOT resubmit immediately — check what failed first','Go to My Applications → tap the failed application','Read the error message (phone duplicate? email issue?)','Fix the data: if phone/email wrong, note the correct details','Submit a new KYC with the corrected information','Contact admin if you cannot figure out the error']},
        {q:'A customer paid you cash but you forgot to process it in the app. It is now the next day.',
         steps:['Go to Collect Payment','Search for the customer','Process the payment now with today\'s date','Add a note: Collected [yesterday\'s date], processed today','The wallet deducts from your current balance','If your balance is low, submit a recharge first','Tell admin about the delayed entry so records match']},
    ],
    'support': [
        {q:'A customer calls saying their internet has stopped working.',
         steps:['Ask for their name or phone number','Go to Customer Lookup → search their name/phone','Check their subscription STATUS field','If SUSPENDED: tell them their service is suspended, likely unpaid bill. Direct to their sales agent.','If ACTIVE: this is a technical issue. Say: "Your account is active — this seems technical."','Log a Support Ticket: category=Technical, priority=High','Include CRM client ID in the ticket description','Escalate to field tech team via WhatsApp, note ticket number']},
        {q:'A customer says they were charged twice for the same month.',
         steps:['Ask for their name and CRM ID if they have it','Go to Customer Lookup → find their account','Look at their service type and plan amount','Go to Support Tickets → create a ticket (category=Billing)','Set priority = Medium','Note: "Customer reports duplicate charge for [month]"','Tag it for Admin/Accountant to investigate','Tell customer: "I have logged this as a priority ticket and our accounts team will review within 24 hours."']},
        {q:'You cannot find a customer in the Customer Lookup.',
         steps:['Try searching by phone (include +211 prefix)','Try searching by just part of their name','Ask the customer for their CRM Client ID (on their registration receipt)','If still not found: they may not be registered yet','Direct them to contact their sales agent to register','Or: create a support ticket anyway with the details they gave you and flag for Admin to investigate']},
    ],
    'accountant': [
        {q:'It is 9am. What do you do first?',
         steps:['Open Accounts Dashboard tab','Check Today\'s Collections — note the number','Check "Pending Recharges" — how many agents are waiting?','Look for any agent with unusually high wallet balance (they may have collected but not settled)','Check if any collections show as "pending CRM sync" — these are not posted yet','Record today\'s opening numbers in your daily log']},
        {q:'An agent says they submitted a recharge request 3 hours ago and it is still pending.',
         steps:['Check the Recharge Requests tab (you can view but not approve — that is Admin)','Confirm the request exists and shows the amount + proof','Contact Admin to let them know a request is waiting','You can also check the agent\'s ledger to confirm their current wallet balance','Note: you cannot approve recharges yourself — flag to Admin']},
        {q:'End of month: how do you calculate commissions?',
         steps:['Go to Commission Report tab','Select the agent, set date range to the full month','Note: KYC commission (% of plan value) + Collection commission (% amount collected)','Run this for each active agent','Create a summary in Excel: agent name, KYC count, collection total, total commission due','Share with Admin for payment approval','Admin pays agents separately (bank transfer or cash)']},
    ],
    'admin': [
        {q:'A brand new DishNet office is opening. How do you set up the plugin from scratch?',
         steps:['Go to Settings tab','Set CRM Base URL (e.g. https://crm.dishnetafrica.com/crm/api/v2.1)','Set CRM Auth Token (from UCRM: User Profile → API Token)','Set WhatsApp Webhook URL (your n8n automation endpoint)','Go to Subscription Plans tab — add all plans (Starlink $65, $80, $112, etc.)','Go to Hardware tab — add all kits (Starlink Standard, Flat, etc.)','Go to Retailers tab — create accounts for all staff (set roles correctly)','Test: create a demo KYC submission and verify it appears in CRM Sync Queue']},
        {q:'Three KYC applications have been in "failed" status for 2 days.',
         steps:['Go to Sync Queue tab','Find the 3 failed jobs — read the error messages','Common errors: duplicate phone/email, invalid plan ID, CRM token expired','If token expired: go to Settings → update CRM Auth Token','If duplicate: go to All Applications → find the app → note which field has the duplicate','Fix option 1: ask the agent to submit a new KYC with corrected details','Fix option 2: manually update the application data and re-queue','Confirm wallets were refunded (check agent passbook — they should see reversal entries)']},
        {q:'An agent says they were double-charged — their wallet was debited twice for one KYC.',
         steps:['Go to Retailer Ledger tab → select the agent → set date to today','Find the two debit entries for that KYC','Check if the same application appears twice in All Applications','If duplicate application: it was submitted twice — this is a known prevention system','Check Activity Log for the agent\'s submit timestamps','If genuinely a double-charge: go to Wallet Admin → manual top-up to refund the extra deduction','Add a note: "Refund: double-charge on KYC [app ID] [date]"','WhatsApp notification will be sent automatically to the agent']},
    ],
};

var scenarios = scenariosByRole[ROLE] || scenariosByRole['sales'];
var scenContainer = document.getElementById('trnScenarios');
if(scenContainer && scenarios){
    scenarios.forEach(function(sc, idx){
        var id = 'scen_' + idx;
        var html = '<div style="background:#fff;border-radius:14px;border:2px solid #e5e7eb;overflow:hidden;">' +
            '<div style="padding:14px 16px;cursor:pointer;display:flex;gap:12px;align-items:flex-start;" onclick="toggleScenario(this.nextElementSibling.id)" >' +
            '<span style="font-size:18px;flex-shrink:0;">❓</span>' +
            '<div style="flex:1;">' +
            '<div style="font-size:13px;font-weight:700;color:#1e293b;">'+escHtml(sc.q)+'</div>' +
            '<div style="font-size:11px;color:#94a3b8;margin-top:3px;">Tap to see step-by-step answer ▾</div>' +
            '</div></div>' +
            '<div id="'+id+'" style="display:none;padding:0 16px 14px;">' +
            '<div style="height:1px;background:#f1f5f9;margin-bottom:12px;"></div>';
        sc.steps.forEach(function(step, si){
            html += '<div style="display:flex;gap:10px;margin-bottom:8px;">' +
                '<div style="width:22px;height:22px;border-radius:50%;background:#1e293b;color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;">'+(si+1)+'</div>' +
                '<div style="font-size:12px;color:#374151;line-height:1.5;padding-top:2px;">'+escHtml(step)+'</div></div>';
        });
        html += '</div></div>';
        scenContainer.insertAdjacentHTML('beforeend', html);
    });
}

window.toggleScenario = function(id){
    var el = document.getElementById(id);
    if(el) el.style.display = el.style.display === 'none' ? '' : 'none';
};

// ── Admin Training Monitor (client-side localStorage read) ──────
// Read all staff training keys from localStorage and populate the progress bars
(function(){
    if(ROLE !== 'admin') return;
    var roleLessonIds = {
        'sales':      ['s0','s1','s2','s3','s4','s5','s6','s7'],
        'support':    ['sp1','sp2','sp3','sp4','sp5','sp6','sp7'],
        'accountant': ['a1','a2','a3','a4','a5','a6','a7','a8','a9'],
        'admin':      ['ad1','ad2','ad3','ad4','ad5','ad6','ad7','ad8','ad9'],
    };
    // Try to read each staff member's progress from localStorage
    // Keys are: dishnet_training_ROLE_USERID
    var rows = document.querySelectorAll('[id^="staffTrnRow_"]');
    rows.forEach(function(row){
        var uid = row.id.replace('staffTrnRow_','');
        // Try all roles
        var bestDone = 0, bestTotal = 0;
        ['sales','support','accountant','admin'].forEach(function(role){
            var key = 'dishnet_training_' + role + '_' + uid;
            try {
                var p = JSON.parse(localStorage.getItem(key)||'null');
                if(p){
                    var ids = roleLessonIds[role] || [];
                    var done = ids.filter(function(id){ return p[id]; }).length;
                    if(done > bestDone){ bestDone = done; bestTotal = ids.length; }
                }
            } catch(e){}
        });
        var bar = document.getElementById('staffTrnBar_'+uid);
        var lbl = document.getElementById('staffTrnLabel_'+uid);
        if(bar && lbl){
            if(bestTotal > 0){
                var pct = Math.round((bestDone/bestTotal)*100);
                bar.style.background = pct===100 ? '#10b981' : pct>50 ? '#f59e0b' : '#e2e8f0';
                bar.style.width = pct + '%';
                lbl.textContent = bestDone + '/' + bestTotal + ' lessons (' + pct + '%)';
                lbl.style.color = pct===100 ? '#10b981' : pct>50 ? '#f59e0b' : '#94a3b8';
            } else {
                lbl.textContent = 'Not started yet';
            }
        }
    });
})();

// ── Print Cheat Sheet ───────────────────────────────────────────
var cheatSheetData = {
    'sales': {
        title: 'Sales Agent Cheat Sheet — DishNet Africa',
        color: '#2E7D32',
        sections: [
            {head:'💰 Wallet Rules', items:['Cash KYC → Wallet debits immediately','Credit KYC → No deduction (lead in CRM)','Collect Payment → Wallet debits','Admin Top-up → Wallet credits','CRM sync fail → Auto-refund within 1 min']},
            {head:'📋 KYC Checklist', items:['Customer passport photo (clear face)','National ID or Passport scan','Phone number with +211 prefix','Full address + GPS coordinates','Subscription plan selected','Cash or Credit selected','Sales person filled in']},
            {head:'🎯 Lead Stages', items:['NEW → Just added','CONTACTED → Called or messaged','INTERESTED → Wants to subscribe','QUOTED → Price shared','QUALIFIED ✅ → Admin approved','WON → Converted to KYC']},
            {head:'⚡ Daily Workflow', items:['Check wallet balance at start of day','Process new KYCs with valid documents','Collect payments as requested','Add leads from every prospect contact','Recharge wallet when low (upload proof)','Check My Applications for sync failures']},
            {head:'🆘 Common Problems', items:['Low wallet → Go to Recharge Wallet','KYC failed → Check My Applications for error','Customer not found → Register them first (New KYC)','Application stuck → Contact Admin (check Sync Queue)','Wallet not credited → Check pending recharge requests']},
        ]
    },
    'support': {
        title: 'Support Staff Cheat Sheet — DishNet Africa',
        color: '#7B1FA2',
        sections: [
            {head:'🔍 Customer Lookup First Steps', items:['Search by name, phone (+211...), or CRM ID','Check Status: Active/Suspended/Cancelled/Prepared','Suspended = unpaid bill (→ Sales agent)','Active + no internet = technical issue (→ Log ticket)','Always verify identity before sharing details']},
            {head:'📡 Service Status Guide', items:['ACTIVE = Running normally','SUSPENDED = Unpaid invoice or admin action','CANCELLED = Subscription ended','PREPARED = Registered, not yet activated','Refresh page if status seems outdated']},
            {head:'🎫 Ticket Guide', items:['Always include CRM Client ID','Set priority honestly: High = complete outage','Update status as you work','RESOLVED = Fixed | CLOSED = Customer confirmed','Escalate field issues to tech team via WhatsApp']},
            {head:'📞 What to Tell Customers', items:['Suspended: "Account suspended — outstanding balance. Contact your sales agent to pay."','Active no internet: "Account active — logging technical ticket now."','Not found: "Not in our system — contact your sales agent to register."','Billing dispute: "Logging a priority ticket for accounts team."']},
        ]
    },
    'accountant': {
        title: 'Accountant Cheat Sheet — DishNet Africa',
        color: '#E65100',
        sections: [
            {head:'📅 Daily Schedule', items:['09:00 — Open Accounts Dashboard','10:00 — Check pending recharges (notify Admin)','12:00 — Mid-day collections check','17:00 — Run Daily Settlement','18:00 — Export report and file']},
            {head:'📊 Key Reports', items:['Dashboard → Today\'s snapshot','All Collections → Every payment by every agent','Retailer Ledger → Per-agent statement','Daily Settlement → End-of-day reconciliation','Commission Report → Monthly commission calc']},
            {head:'🚩 Red Flags to Watch', items:['Agent with high wallet balance + no recent recharge','Pending recharge with no proof uploaded','Collection not matched to CRM invoice','Sudden drop in daily collections','Agent with negative wallet balance']},
            {head:'📋 Ledger Reading Guide', items:['CREDIT = wallet loaded (top-up/recharge/reversal)','DEBIT = agent processed a transaction','Net balance = what agent currently holds','Use for end-of-month agent reconciliation','Discrepancy → escalate to Admin']},
        ]
    },
    'admin': {
        title: 'Admin Cheat Sheet — DishNet Africa',
        color: '#1565C0',
        sections: [
            {head:'🌅 Morning Checklist', items:['✓ Check Daily Report (yesterday\'s summary)','✓ Process all pending Recharge Requests','✓ Check Sync Queue for failed CRM syncs','✓ Qualify any leads flagged for review','✓ Check for any stuck "pending_sync" applications']},
            {head:'⚙️ Critical Settings', items:['CRM Base URL → API connection endpoint','Auth Token → From UCRM User Profile','WhatsApp Webhook → n8n automation URL','Commission Rate → Applies to all agents','Plans & Hardware → Must be set before agents can sell']},
            {head:'🔄 When CRM Sync Fails', items:['All failing = CRM token expired → Update in Settings','One failing = duplicate phone/email in CRM','Check error message in Sync Queue tab','Fix data in All Applications tab','Wallet auto-refunded after 3 failed attempts']},
            {head:'💳 Wallet Management', items:['Wallet Admin → Manual top-up (with note)','Recharge Requests → Review proof → Approve/Reject','Approve = instant credit + WhatsApp to agent','Reject = add reason so agent knows what to fix','Two-Org CRM sync happens automatically on top-up']},
        ]
    },
};

window.trnPrintCheatSheet = function(){
    var role = ROLE;
    var data = cheatSheetData[role] || cheatSheetData['sales'];
    var html = '<div style="font-family:Arial,sans-serif;max-width:800px;margin:0 auto;padding:20px;">' +
        '<div style="background:'+data.color+';color:#fff;padding:16px 20px;border-radius:12px;margin-bottom:20px;">' +
        '<h1 style="margin:0;font-size:20px;">'+escHtml(data.title)+'</h1>' +
        '<p style="margin:4px 0 0;font-size:13px;opacity:.85;">DishNet Africa Limited · Quick Reference</p>' +
        '</div>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">';
    data.sections.forEach(function(sec){
        html += '<div style="border:1.5px solid '+data.color+'33;border-radius:10px;padding:12px;">' +
            '<div style="font-size:13px;font-weight:800;color:'+data.color+';margin-bottom:8px;">'+escHtml(sec.head)+'</div>';
        sec.items.forEach(function(item){
            html += '<div style="font-size:11px;padding:4px 0;border-bottom:1px solid #f1f5f9;color:#374151;">'+escHtml(item)+'</div>';
        });
        html += '</div>';
    });
    html += '</div>' +
        '<p style="font-size:10px;color:#94a3b8;text-align:center;margin-top:16px;">dishnetafrica.com · DishNet Sales Hub v3.4.1 · Printed '+new Date().toLocaleDateString()+'</p>' +
        '</div>';

    var sheet = document.getElementById('trnPrintSheet');
    if(sheet){ sheet.innerHTML = html; }
    window.print();
};

// Init
renderProgress();
})();
</script>

