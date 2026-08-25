<?php
// Tab: changelog
// Extracted from public.php on 2026-03-15
?>
<div style="max-width:700px;margin:0 auto;padding:0 4px;">
    <div style="font-size:20px;font-weight:800;color:#1E293B;margin-bottom:16px;">📋 Changelog</div>
    <?php
    $changelog = [
        ['ver'=>'v4.21.116','date'=>'2026-07','tag'=>'feature','changes'=>[
            '🤖 <b>Customer Context API for the n8n sales bot (re-applied)</b>: pre-auth read-only action <code>customer_context</code> in <code>api_public.php</code> — <code>GET ?page=api&action=customer_context&phone=&lt;digits&gt;&key=&lt;webhook_secret&gt;</code>. Returns found/name/plan/status/balance/open-ticket-count resolved from <code>client_search_index</code> (SQLite O(1) fast path, JSON fallback) so the sales bot recognises existing customers and stops pitching them. Auth via <code>webhook_secret</code> (hash_equals). Fully read-only, every enrichment try/caught, never 500s. One file changed. Pairs with DishNet AI Bot v2 n8n workflow (per-message lookup + 6h Redis cache + graceful fallback).',
        ]],
        ['ver'=>'v4.11.0','date'=>'2026-03','tag'=>'major','changes'=>[
            '👥 <b>HRM Module Phase 1A</b>: Full HR management integrated into Hybrid — employee profiles, salary structures, monthly payroll engine, leave management with 7 configurable leave types.',
            '💰 <b>Payroll Engine</b>: Create monthly periods → auto-calculate from salary structures → approve → disburse (partial payments supported — advance mid-month + balance at month-end). Auto-posts to cashbook with <code>source=\'payroll\'</code>.',
            '🔧 <b>Salary Auto-Link Fix</b>: Salary, Transport Allowance, Food Allowance, Bonus, and Employee Benefit payments no longer create cash_ins.json entries. Staff field register balance is no longer inflated by personal pay.',
            '📱 <b>WhatsApp Payslips</b>: Staff receive proper payslip notifications with earnings breakdown, deductions, payment history — replaces misleading "💰 Cash Received" message.',
            '🗓️ <b>Leave Management</b>: South Sudan standard leave types (Annual 21d, Sick 14d, Emergency 5d, Maternity 90d, etc.). Staff request → admin approve/reject → WhatsApp notification → auto-balance tracking.',
            '📊 <b>HR Dashboard</b>: Headcount, department breakdown, monthly payroll estimate, latest payroll status, pending leave count.',
            '🌱 <b>Data Seeding</b>: One-click import of existing employees from retailer list with salary estimation from cashbook history.',
        ]],
        ['ver'=>'v4.8.4','date'=>'2026-03','tag'=>'security','changes'=>[
            '🛡️ <b>PHANTOM REVENUE GUARD — Prevents double-payment</b>: When customer pays online while field agent is collecting, the plugin now checks invoice balance before posting to CRM. If invoice is already paid, collection is rejected and wallet refunded automatically. Prevents inflated MRR from duplicate payments creating credit balances.',
            '⚠️ <b>Overpayment protection</b>: If collected amount exceeds invoice balance, payment is rejected with clear error message. Agent must reduce amount or leave invoice blank (applies as customer credit).',
            '📋 <b>Zero-balance warning logged</b>: When collecting from a customer with zero/credit balance (no specific invoice), transaction proceeds but is logged for Rupesh to review under Activity Log → "credit_payment_warning".',
            '🔧 Both collection paths protected: Admin form (post_handlers.php) and PWA API (api_handlers.php) have identical guards.',
        ]],
        ['ver'=>'v3.6.1','date'=>'2026-03','tag'=>'fix','changes'=>[
            '🐛 <b>CRITICAL FIX — KYC photos not uploading to UCRM</b>: Root cause was wrong API endpoint. <code>CrmApiClient::upload()</code> was posting to <code>/documents</code> (template-based docs endpoint) instead of the correct <code>/clients/{id}/documents</code> (client file upload endpoint). All KYC photos and ID proofs since go-live were silently failing — UCRM returned 400/404 but the error was swallowed. Fixed endpoint, clientId now passed in URL path. Also fixed: document name was showing PHP temp filename (e.g. "phpXxYzAb") instead of "Customer Photo.jpg".',
            '📤 <b>Re-upload failed documents without re-registering</b>: If photo/ID upload fails, agent sees an inline re-upload form on the success screen and in the Applications list. Tap 📤 Re-upload → choose file → submits directly to UCRM against the existing CRM client ID. No new registration, no wallet debit.',
            '👁 <b>Document upload status visible on Applications list</b>: Each KYC card now shows 📷✓/📷✗ and 🪪✓/🪪✗ badges. Failed uploads show red badges and a Re-upload button. Admins can see at a glance which customers are missing docs.',
            '🔍 <b>Upload errors now logged</b>: <code>uploadFileToCrm()</code> now logs every failure to PHP error_log with the UCRM HTTP status code and response — admin can check <code>/var/log/php*.log</code> to diagnose API issues.',
            '🐛 <b>CALC BUG FIXED — Month Recharges KPI always showed $0.00</b>: Variable <code>$monthRecharge</code> was undefined; corrected to <code>$monthRechargeAmt</code>.',
            '🐛 <b>CALC BUG FIXED — KYC cash payment posting failed silently</b>: (1) Wrong endpoint <code>billing/payments</code> → correct UCRM endpoint is <code>payments</code>. (2) Method sent as string "Cash" instead of integer 2. Cash registrations now post correctly.',
            '🔒 <b>SECURITY FIX — Proof upload MIME validation</b>: RechargeService was trusting browser Content-Type. Now uses PHP <code>finfo</code> server-side detection.',
            '🐛 <b>FAQ duplicate section fixed</b>: "Wallet & Balance" section header appeared twice.',
            '🔧 <b>Version string corrected</b>: Browser tab title and nav header now show v3.6.1.',
            '💬 <b>WhatsApp tab</b>: Dedicated sidebar tab with pre-filled credentials, status indicator, message log, events table, and webhook receiver. Removed from Settings page.',
        ]],
        ['ver'=>'v3.6.0','date'=>'2026-03','tag'=>'major','changes'=>[
            '🗄️ <b>SqliteStore as primary store everywhere</b>: api/, cron workers, and public.php all use SqliteStore. StoreInterface added for clean abstraction.',
            '📡 <b>LteService</b>: Magma/Baicells private LTE — subscribers, SIMs, hardware, packages, subscriptions, usage cache. O(n²) subscriber loop fixed to O(n).',
            '🔐 <b>MagmaApiClient</b>: Mutual TLS to Orc8r :9443/magma/v1. Client cert/key paths configurable in Settings.',
            '💰 <b>FieldAgentService</b>: Diko cash collection and remittance workflow. <code>cash_balance = collections − approved_remittances</code>. Full audit trail.',
            '🔄 <b>CrmQueue</b>: Background KYC sync queue with 3 retries and exponential backoff.',
            '🛡️ <b>IdempotencyGuard</b>: X-Idempotency-Key header, 24h TTL — prevents duplicate API calls on retry.',
            '🚫 <b>LoginRateLimiter</b>: 5 failures / 10 min → locked for 15 min. SHA256 email|ip key.',
            '⏰ <b>cron_lte.php</b>: Every 5 min — auto-suspend/reactivate LTE subs on Magma, daily WhatsApp report, settlement snapshot.',
            '⏰ <b>cron_wallet_sync.php</b>: Every 6h — sync CRM Org-7 accountBalance to retailer wallet. Negative CRM balance = debt owed to DishNet.',
            '🛒 <b>Retailer PWA</b>: React 18, installable, service worker. Scheduling/My Jobs fetches UCRM jobs via ucrm_user_id.',
        ]],
        ['ver'=>'v3.4.2','date'=>'2026-03','tag'=>'feature','changes'=>[
            '💵 <b>Cash Custody Workflow</b>: Full Diko→Rupesh cash management system. Three new data stores: cash_expenses.json, cash_handovers.json.',
            '🧾 <b>Diko — Log Expense</b>: Diko logs petty cash expenses (category, description, photo receipt) directly from Wallet tab. Expense stays <em>pending</em> until Rupesh approves — wallet only debited on approval.',
            '💼 <b>Diko — Submit Handover</b>: Diko submits physical cash handover to Rupesh with amount + note. System validates amount ≤ cash-in-hand (collected − approved expenses − prior handovers). Stays pending until Rupesh confirms.',
            '📊 <b>Diko — Cash-in-Hand Panel</b>: Real-time orange panel on Wallet tab showing: Total Collected, Total Expensed, Total Handed Over, and live Cash-in-Hand balance. Pending items shown with ⏳ badge.',
            '✅ <b>Rupesh — Expense Approval Queue</b>: Settlement tab shows all pending expenses with one-click Approve (triggers wallet debit) or Reject (with reason). Receipt photo link shown if uploaded.',
            '✅ <b>Rupesh — Handover Confirmation Queue</b>: Settlement tab shows pending handovers. Rupesh adds confirmation note and clicks Confirm Receipt — triggers wallet credit for collector automatically.',
            '📋 <b>Cash Custody Summary Table</b>: Settlement tab shows all-time per-collector breakdown: Collected / Expenses / Handed Over / Cash in Hand. Red when collector still holds cash, green when cleared.',
        ]],
        ['ver'=>'v3.4.1','date'=>'2026-03','date'=>'2026-03','tag'=>'feature','changes'=>[
            '📥 <b>CSV export on all 3 accountant tabs</b>: Collections (per day/month → Date, Customer, Agent, Method, Amount, Commission, Net, CRM Synced, Invoice), Ledger (per agent per month → Date, Trx#, Description, Credit, Debit, Balance), Settlement (per day/month → Date, Agent, Customer, Method, Amount, Commission, Net). All exports stream directly to browser — no server storage.',
            '📦 <b>Hardware Revenue panel on Accounts Dashboard</b>: Pulls hw_cart_json from all KYC applications this month. Shows HW revenue total, units sold, avg per unit, breakdown by service type (Starlink / Fiber / SIM), top-6 items table with units + revenue + avg price. CSV export button included.',
            '📊 <b>Collections tab — row-by-row table</b>: Replaced card list with a proper table. Columns: Date/Time, Customer, Agent, Method (colour-coded badge), Amount, Commission, Net, CRM status. Footer row shows period totals. Sortable by eye, exportable to CSV.',
            '📒 <b>Ledger tab — debit/credit/balance table</b>: Replaced card-based passbook entries with a double-entry style table. Columns: Date, Description, Trx#, Credit (+green), Debit (-red), Running Balance. Footer shows period totals. Balance column turns red when negative.',
        ]],
        ['ver'=>'v3.4.0','date'=>'2026-03','date'=>'2026-03','tag'=>'fix','changes'=>[
            '🐛 <b>Step 5 Review was completely blank</b> — <code>wizBuildReview()</code> was called on every wizard advance to Step 5 but the function was never defined. Agents were submitting KYC applications blind with no way to verify customer name, phone, hardware, plan, or pricing before hitting Submit.',
            '✅ <b>Full review panel built</b>: Service type banner (colour-coded: Starlink blue, Fiber green, SIM orange, LTE purple), Customer card (name, mobile, email, address, existing CRM ID), Hardware card (line items with qty × price, kit serial/tracking), Plan card (plan name + monthly fee), Sales card (agent name, payment type, referral source), Order Summary box (hardware subtotal + first month + today total + recurring note).',
            '📋 <b>Review reads live state</b>: Pulls directly from <code>hwCart[]</code>, <code>selPlan</code>, <code>curType</code> JS globals and DOM field values — always reflects the actual order being submitted.',
            '🟢 <b>Terms checkbox upgraded</b>: Green confirmation box — "I confirm all details above are correct and the customer agrees to DishNet Africa Terms &amp; Conditions".',
            '✂️ <b>Quotation generator removed</b> — Step 5 is now purely Review + Submit. Clean, fast, no extra steps for field agents.',
        ]],
        ['ver'=>'v3.3.9','date'=>'2026-03','date'=>'2026-03','tag'=>'fix','changes'=>[
            '🐛 <b>KycService crash fixed</b> — Fatal TypeError in <code>KycService.php:297</code>: <code>preg_replace()</code> received an integer instead of a string when reading hardware cart prices. Smart Order Builder (v3.3.4) stores price as a numeric float in <code>hw_cart_json</code>; KycService now casts to <code>(string)</code> before regex. All KYC submissions with hardware now process correctly.',
        ]],
        ['ver'=>'v3.3.8','date'=>'2026-03','tag'=>'major','changes'=>[
            '🗄️ <b>SQLite migration complete</b> (SqliteStore v2.0). All flat-JSON storage replaced with <code>plugin.sqlite3</code>. Single-file embedded database, no server required, instant drop-in replacement.',
            '⚡ <b>O(1) writes</b>: <code>append()</code> and <code>appendWithId()</code> do a real SQL <code>INSERT</code> — no more full-table rewrite on every passbook or activity_log entry.',
            '🔍 <b>O(log n) lookups</b>: <code>findOne()</code> / <code>findAll()</code> use <code>json_extract()</code> with 30 performance indexes across all hot lookup fields.',
            '✂️ <b>Surgical updates</b>: <code>withLock()</code> issues per-row <code>UPDATE</code> for changed rows only — no more <code>DELETE * + re-INSERT</code> entire tables.',
            '📊 <b>Admin stats panel</b> on Maintenance tab: DB size, WAL mode, SQLite version, per-table row counts, Export to JSON backup, VACUUM button.',
            '🔄 <b>install_leads.php</b>: Auto-detects active backend (SQLite or JSON).',
            '📦 <b>WAL + mmap</b>: Write-ahead log, 16MB page cache, 128MB memory-mapped I/O.',
        ]],
        ['ver'=>'v3.3.6','date'=>'2026-03','tag'=>'feature','changes'=>[
            '🌐 <b>Fiber combo — generic Wi-Fi Router</b>: Merged D-Link Router (FB-DLINK) and TP-Link Router (FB-TPLINK) into a single <b>Wi-Fi Router</b> entry (SKU: FB-ROUTER, $50). Quotation shows brand-neutral name; field team supplies D-Link or TP-Link based on stock.',
            '📦 <b>Fiber smart default auto-adds ONU + Wi-Fi Router</b>: Two-pass default system — primary (ONU) and secondary autoAdd (router). Both appear in cart immediately when agent selects Fiber. Agent removes router if customer already has one.',
            '🔄 <b>Migration</b>: Existing FB-DLINK / FB-TPLINK records renamed to generic FB-ROUTER on first load. Dedup pass removes duplicate entries.',
        ]],
        ['ver'=>'v3.3.5','date'=>'2026-03','tag'=>'feature','changes'=>[
            '👥 <b>Lead Assignment system</b>: Admin can assign any lead to any agent. Quick-assign buttons for Aida & Mecklyne appear in All Leads header and per-row actions.',
            '☑️ <b>Bulk select & assign</b>: Check multiple leads → blue toolbar slides in with assign buttons for default staff + dropdown for any other agent.',
            '📊 <b>Assignee breakdown bar</b>: Clickable pills showing active lead count per person. Unassigned count shown in red.',
            '🔍 <b>Leads filters</b>: Filter by assignee (including "Unassigned"), status, service type, and free-text search.',
            '⚙️ <b>Configurable defaults</b>: Admin can change default assignee names via Settings panel (saved to kyc_config.json). Ships pre-set to Aida, Mecklyne.',
            '📌 <b>Agent view</b>: Agents see leads assigned to them alongside leads they created. "Assigned to you" badge (blue) distinguishes admin-assigned leads.',
        ]],
        ['ver'=>'v3.3.4','date'=>'2026-03','tag'=>'major','changes'=>[
            '🛒 <b>Smart Order Builder v2</b> — Step 3 completely rebuilt with sticky order summary bar, hardware catalogue with tap-to-add, cart with qty +/- controls.',
            '⚡ <b>Intelligent defaults per service</b>: Starlink → Mini Kit + Residential; Fiber → ONU + plan; SIM → first combo. Two-pass default system (primary + autoAdd).',
            '💰 <b>Live order summary</b>: Today Total (hardware + first month) and monthly recurring note update in real-time as agent builds order.',
            '🔄 <b>wizTypeChange rebuilt</b>: Single authoritative function — filters hw catalogue, plan list, shows/hides SIM/LTE sections, and applies smart defaults atomically.',
        ]],
        ['ver'=>'v3.3.3','date'=>'2026-03','tag'=>'feature','changes'=>[
            '🔐 Default password 123456 with forced change modal on first login',
            '👷 Employee vs commission-based agent types in retailer management',
        ]],
        ['ver'=>'v3.3.2','date'=>'2026-03','tag'=>'major','date'=>'2026-03','tag'=>'major','changes'=>[
            '🛒 Multi-item hardware cart — add multiple products (Starlink kits, routers, cable) with qty +/- in a single KYC',
            '📅 Date filters on All Collections (admin) and My Collections (mobile) — filter by date range, agent, and customer name',
            '📅 Date + status + agent filters on All Applications — CSV export respects applied filters',
        ]],
        ['ver'=>'v3.3.1','date'=>'2026-03','tag'=>'feature','changes'=>[
            '🧾 Auto-Quote on KYC — UCRM proforma quote created and emailed to customer on every registration',
            'Quote line items include: service package fee, hardware/kit price (per item), installation fee (Fiber)',
            'Toggle on/off in Settings → Auto-Quote; configurable validity days (default 7)',
            'Quote ID saved to application record; agent sees "Quote #X sent" green banner on success',
        ]],
        ['ver'=>'v3.3.0','date'=>'2026-03','tag'=>'fix','changes'=>[
            '🔧 CRITICAL: KycService::saveApplication() fatal error fixed — withLock callback now returns correct [records, result] structure',
            '🔒 Sales Person field locked to logged-in agent — cannot be changed manually, always matches submitting account',
            '🔒 Default password for new staff accounts set to 123456 with forced change on first login',
            '👔 Employee vs Agent type — create staff as employee (payroll, no commission) or external agent (commission-based)',
            '🔐 Must-change-password modal on first login — blocks all navigation until new password is set (min 8 chars)',
            'One-time migration: all existing retailers tagged as employees with commission disabled',
        ]],
        ['ver'=>'v3.2.9','date'=>'2026-03','tag'=>'fix','changes'=>[
            'Passbook Credit/Debit badge fixed — reads entry_type field correctly (was reading type which does not exist)',
            'CRM URL double-path bug fixed — strips /crm/api/vX.X fully from crm_base_url before appending client path',
            'SQLite store integration in main.php — auto-pull writes to same DB the plugin reads from',
        ]],
        ['ver'=>'v3.2.8','date'=>'2026-03','tag'=>'feature','changes'=>[
            'Manual pull also builds Sales Person index — Rebuild Index button available without re-pulling',
            'Index build progress shown in UI with auto page-reload on completion',
            'Admin Ledger Customers tab — dual view: Plugin Registrations + UCRM CRM Attributed clients',
        ]],
        ['ver'=>'v3.2.7','date'=>'2026-03','tag'=>'feature','changes'=>[
            'UCRM auto-pull runs nightly (3:00 AM, configurable) via main.php — pulls all 12,500+ clients',
            'Sales Person Attribution Index — groups all UCRM clients by Sales Person custom attribute',
            'Data Sync tab shows auto-pull status + attribution table with links to agent ledgers',
        ]],
        ['ver'=>'v3.1.0','date'=>'2026-03','tag'=>'major','changes'=>[
            'Ported 16 production features from Starlink Finance v7.0.5',
            'Toast notification system (showToast) — no more page refreshes for feedback',
            'Activity log upgraded to icon+color timeline with CSV export',
            'Auto-backup schedule with cron (3x daily, rotation to 14 copies)',
            'Daily summary email via SMTP or UCRM built-in mailer',
            'sendSmtpEmail() — full raw SMTP stack, no PHPMailer dependency',
            'CSV export for Applications, Collections, Activity Log',
            'KPI card grid (.kpi-card) on accounts dashboard',
            'Chart.js 30-day revenue trend on accounts dashboard',
            'Data Health Scanner with dry-run and auto-fix on maintenance tab',
            'Pending Sync Alert Queue (Starlink suspension queue pattern)',
            'Auto-backup settings card in backup tab',
            'Email notification settings card with test + daily summary button',
            'main.php rewritten: auto-backup, low wallet alerts, pending sync alerts',
            'Instant customer search (Starlink usFilter pattern) — oninput, no button',
            'Fixed $isAdm typo → $isAdmin causing JS crash on customer lookup tab',
        ]],
        ['ver'=>'v3.0.0','date'=>'2026-02','tag'=>'major','changes'=>[
            'KYC + Lead Manager merged into unified Sales Hub PWA',
            'CrmApiClient::fromUcrm() — auto-detect credentials from ucrm.json',
            'Three-tier credential priority: manual override → auto-detect → unconfigured',
            'Bulk services sync: single clients/services endpoint (was per-client loop)',
            'Retailer PWA: offline-capable service worker, install prompt',
        ]],
        ['ver'=>'v2.5.x','date'=>'2026-01','tag'=>'fix','changes'=>[
            'JsonStore v2.2: flock() locking on all writes prevents concurrent corruption',
            'AtomicWithId: single-lock append eliminates race condition on duplicate IDs',
            'Wallet integrity checks with automated mismatch detection',
            'HMAC webhook signature verification for n8n notifications',
        ]],
    ];
    foreach ($changelog as $release):
        $tagColor = $release['tag']==='major'?'#2563EB':($release['tag']==='fix'?'#16a34a':'#7c3aed');
    ?>
    <div style="background:#fff;border:1px solid #E2E8F0;border-radius:14px;overflow:hidden;margin-bottom:16px;">
        <div style="padding:14px 18px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;gap:12px;">
            <span style="font-size:16px;font-weight:800;color:#1E293B;"><?= h($release['ver']) ?></span>
            <span style="background:<?= $tagColor ?>18;color:<?= $tagColor ?>;border-radius:20px;padding:2px 10px;font-size:10px;font-weight:700;text-transform:uppercase;"><?= h($release['tag']) ?></span>
            <span style="font-size:11px;color:#94A3B8;margin-left:auto;"><?= h($release['date']) ?></span>
        </div>
        <ul style="margin:0;padding:14px 18px 14px 34px;list-style:disc;">
            <?php foreach ($release['changes'] as $ch): ?>
            <li style="font-size:13px;color:#374151;padding:3px 0;"><?= h($ch) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>
</div>

