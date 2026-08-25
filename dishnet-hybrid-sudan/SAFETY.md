# DishNet Hybrid — SAFETY RULES FOR CLAUDE
# ════════════════════════════════════════════
# READ THIS BEFORE EDITING ANY FILE. Updated: 2026-04-28 v4.21.9
#
# These rules exist because code has been broken by careless edits.
# Every rule here was learned the hard way.

## ⛔ RULE 0: READ BEFORE WRITE

Before editing ANY file:
1. Read MANIFEST.md (architecture + data flows)
2. Read THIS file (safety rules)
3. Read the FULL file you're about to edit — not just the function
4. Check the IMPACT MAP below to see what else uses this file
5. After editing, verify the file has no syntax errors: `php -l filename.php`

## ⛔ RULE 1: NEVER DELETE OR RENAME EXISTING FUNCTIONS

If a public method exists, other files depend on it. Search before removing:
```
grep -rn 'methodName' --include='*.php' .
```
If you need to change a method signature, keep the old signature working
with default parameters.

## ⛔ RULE 2: NEVER CHANGE SQL COLUMN NAMES OR TABLE SCHEMAS

cb_ledger, cash_advances, staff_expenses, staff_transfers, wa_conversations,
wa_messages, staff_cash_snapshots, staff_ledger — these have live data. If you need new
columns, add a NEW migration file (next number in migrations/).
NEVER ALTER or DROP existing columns.

## ⛔ RULE 3: NEVER CHANGE JSON FILE KEY NAMES

retailers.json, passbook.json, payment_collections.json, cash_handovers.json,
cash_expenses.json, cash_ins.json, kyc_applications.json — these are live
data on the server. If you rename a key, all existing records become unreadable.
ADD new keys; never rename or remove existing ones.

## ⛔ RULE 4: SAVE JSON BEFORE SNAPSHOT REBUILD

SnapshotService::rebuild() reads from DISK, not memory.
Pattern: modify data in memory → $store->save('file.json') → rebuild()
WRONG: modify data → rebuild() → save (rebuild sees stale data)

## ⛔ RULE 5: PHP 7.4 ONLY

FORBIDDEN syntax (will crash production):
- match() expressions
- Named arguments fn(name: $val)
- str_contains() / str_starts_with() / str_ends_with() WITHOUT polyfill
- Enum types
- Readonly properties
- Union types with & (intersection)
- Fibers
- First class callable syntax $fn(...)
- Array unpacking with string keys [...$assoc]

Every service file MUST have the polyfill block at the top if it uses
str_contains/str_starts_with/str_ends_with:
```php
if (!function_exists('str_contains')) { function str_contains(string $h, string $n): bool { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('str_starts_with')) { function str_starts_with(string $h, string $n): bool { return $n===''||strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('str_ends_with')) { function str_ends_with(string $h, string $n): bool { return $n===''||substr($h,-strlen($n))===$n; } }
```

## ⛔ RULE 6: NEVER CHANGE THESE ENTRY POINTS

These URLs/paths are hardcoded in the PWA, in UCRM, and in cron jobs:
- public.php?page=api&action=... (NOT api/index.php)
- webhook.php
- wa_webhook.php
- evo_webhook.php
- main.php → cron/master.php
- manifest.json → "version": "1" (always string "1", never the plugin version)

## ⛔ RULE 7: PAYMENT UUIDs ARE SACRED

Cash: 6efe0fa8-36b2-4dd1-b049-427bffc7d369
Bank Transfer: 4145b5f5-3bbc-45e3-8fc5-9cda970c62fb
Mobile Money → Cash UUID
PaymentUuids::resolve() is the ONLY method for UUID lookup.
NEVER hardcode UUIDs outside PaymentUuids.php.

## ⛔ RULE 8: CASHBOOK AMOUNT IS ALWAYS USD

cb_ledger.amount = USD. SSP amounts converted at entry time.
Original SSP stored in ssp_amount + ssp_rate columns.
NEVER store SSP in the amount column.

## ⛔ RULE 9: HANDOVER DOES NOT TOUCH CASHBOOK

Since v4.9.8, confirm_handover and admin_record_handover must NEVER
call CashbookService::addEntry(). Revenue is posted to cb_ledger by
webhook or nightly sync. Handover only updates wallet + snapshot.

## ⛔ RULE 10: ZIP BUILD MUST BE FROM INSIDE FOLDER

```bash
cd dishnet-hybrid-telecom && zip -r ../v4.x.x.zip .
```
Files MUST be at ZIP root. NOT inside a subdirectory.
manifest.json must be at root level of ZIP.

## ⛔ RULE 11: ALWAYS OUTPUT COMPLETE FILES

When editing a file, output the COMPLETE file — never partial snippets
that need manual merging. If the file is too large, use str_replace on
specific sections, but verify with `php -l` afterward.

## ⛔ RULE 12: CASHBOOK DEDUP FORMAT

validation_ref format: PAY-{crmId} (since v4.9.10)
NEVER use the old CRM-PAY-{id} format.
Always dedup-check: SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1

## ⛔ RULE 13: ob_end_clean() BEFORE CSV HEADERS

Any CSV export in routes.php MUST call ob_end_clean() before
header('Content-Type: text/csv') to prevent HTML-in-CSV bug.

## ⛔ RULE 14: IDEMPOTENCY KEY FORMAT

Collections:   COL-{id}
Handover:      HOV-{id}
Revert:        REVHOV-{id}
Transfer:      TRF-{id}
Expense:       EXP-{id}
Wallet credit: WALCR-{ref}
NEVER reuse an idempotency key format for a different flow.


## ⛔ RULE 15: NEVER HARDCODE DATA PATHS WITH __DIR__

The plugin data directory is NOT inside the plugin folder.
UCRM stores it at /data/ucrm/data/plugins/dishnet-hybrid-telecom/data/
which is set in ucrm.json → pluginDataDir and resolved by getDataDir().

FORBIDDEN — these paths point to the installation folder, wiped on every update:
- __DIR__ . '/data/...'
- __DIR__ . '/../data/...'
- dirname(__DIR__) . '/data/...'
- dirname(__DIR__, 2) . '/data/...'

REQUIRED — always use $dataDir (resolved via getDataDir()):
- Every entry point (public.php, api/index.php, api/v2/router.php, webhook.php,
  cron_*.php, cron/*.php) MUST bootstrap with:
    require_once .../lib/bootstrap_data.php;
    $dataDir = getDataDir(<plugin_root>);
- Tab files and lib files inherit $dataDir from their entry point.
- Never derive a path from __DIR__ for anything stored between requests.

IF YOU SEE __DIR__ . '/data/' ANYWHERE OUTSIDE lib/bootstrap_data.php — IT IS A BUG.

## ⛔ RULE 16: SSP IMPREST — ADVANCE-LINKED EXPENSES NEVER POST TO cb_ledger

Added v4.20.3 after a real production bug.

When an SSP advance is issued to a staff member (e.g. Rupesh gives Aida 600,000 SSP):
- cb_ledger gets ONE OUT entry (category "SSP Advance" / "Staff Advance"). This
  represents physical SSP cash leaving the main till. Cash is gone, period.
- cb_ssp_register gets a corresponding IN entry on the staff's side.

When that staff member later spends from this advance, the expense is approved:
- cb_ssp_register gets an OUT entry (per-category: Fuel, Misc, Water, etc.)
- staff_expenses row is marked 'approved'
- cash_advances.amount_spent is incremented
- staff_ledger gets a row
- cb_ledger MUST NOT GET ANOTHER OUT. The cash already left at advance time.

If you post another OUT to cb_ledger here, you double-count. Every approved expense
inflates cb_ledger SSP outflow above physical cash gone. This was the root cause of
the "system cash != physical cash" complaint that triggered this rule.

Code paths that MUST stay imprest-suppressed for SSP advance-linked expenses:
- ExpenseAdvanceService::approveExpense() — gated by `currency='SSP' AND advance_id > 0`
  → sets staff_expenses.cashbook_entry_id = -1 (sentinel)
- SspAdvanceService::mergeExpenseToLedger() — never posts to cb_ledger; only flips
  cb_ssp_register.merged_to_cb=1 / status='merged'
- workers/CashbookReconcileWorker.php Step 3 (orphan repost) — must skip
  `currency='SSP' AND advance_id IS NOT NULL` and cashbook_entry_id = -1 rows,
  otherwise the nightly worker undoes the suppression

If you ever add a NEW path that approves an SSP expense, gate the cb_ledger write
the same way.

NOT covered by this rule (still post to cb_ledger normally):
- Free-standing SSP expenses (no advance_id) — staff member pays from own pocket,
  reimbursement IS the cb_ledger OUT
- USD expenses (any) — USD imprest chain is separate and unchanged in v4.20.3
- SSP returns (staff gives leftover SSP back to office) — genuine cash IN to cb_ledger
- Salary, exchange, customer refund, and all other categories

Sentinel value reference:
- staff_expenses.cashbook_entry_id =  N (>0) — normal posted expense, cb_ledger row #N
- staff_expenses.cashbook_entry_id =  0      — orphaned/legacy/pre-fix; reconcile worker
                                                will check and post if needed
- staff_expenses.cashbook_entry_id = -1      — intentionally suppressed (imprest); leave alone

---

# 🗺️ IMPACT MAP — What Breaks What

## CRITICAL PATH: Money touches these files
If you edit ANY of these, money-related features can break:

```
lib/CashbookService.php
  ├── USED BY: api_cashbook.php, post_cashbook.php, post_field.php,
  │            api_cron_debug.php, api_retailer.php, webhook.php,
  │            cron_crm_payment_reconcile.php
  │
  ├── IF YOU BREAK addEntry() or addEntryRaw():
  │   → Cashbook stops recording
  │   → Webhook payments won't post
  │   → Field expenses won't record
  │
  └── IF YOU BREAK getBalance() or getSummary():
      → Dashboard shows wrong numbers
      → Daily report emails wrong data
      → Reconciliation breaks

lib/WalletService.php
  ├── USED BY: api_payments_admin.php, api_crm_sync.php,
  │            post_sales.php (via KYC), post_field.php,
  │            webhook.php, handover_queue.php
  │
  └── IF YOU BREAK credit()/debit():
      → Agent wallets go out of sync
      → KYC auto-payment fails
      → Handover confirmation fails

lib/SnapshotService.php
  ├── USED BY: post_field.php, webhook.php, handover_queue.php
  │
  └── IF YOU BREAK rebuild() or computeFromSource():
      → Field cash positions show wrong numbers
      → Rupesh sees wrong agent balances
      → Staff cash control dashboard breaks

lib/ExpenseAdvanceService.php
  ├── USED BY: post_field.php, post_cashbook.php,
  │            api_field_ops.php, staff_cashbooks.php
  │
  ├── IF YOU BREAK createAdvance()/submitExpense()/approveExpense():
  │   → Staff can't submit expenses
  │   → Advances can't be given
  │   → Approval flow breaks
  │
  └── v4.20.3 — RULE 16 GATE on approveExpense():
      → SSP + advance_id > 0  ⇒  cashbook_entry_id = -1, NO cb_ledger write
      → If you remove the gate, SSP cashbook double-counts every approval
      → If you change the sentinel value, update CashbookReconcileWorker.php Step 3

lib/SspAdvanceService.php
  ├── USED BY: post_cashbook.php, staff_cashbooks.php (quick_approve)
  │
  └── v4.20.3 — RULE 16: mergeExpenseToLedger() NEVER writes to cb_ledger.
      It only flips cb_ssp_register.merged_to_cb=1 / status='merged'. Reverting
      this re-introduces the double-count bug fixed in v4.20.3.

workers/CashbookReconcileWorker.php (Step 3 orphan repost)
  └── v4.20.3 — RULE 16: skips currency='SSP' AND advance_id IS NOT NULL rows.
      Reverting this breaks the v4.20.3 fix on the next cron run.

lib/SspImprestReportService.php (v4.20.4, READ-ONLY)
  ├── USED BY: tabs/accounts/ssp_imprest.php
  │
  ├── Methods: companyTotals(), imprestHolders(), holderHistory(), pAndLByCategory(), cashFlowSummary()
  │
  └── This service NEVER writes — it's a pure read layer over staff_ledger,
      cb_ledger, and staff_expenses. If you ever add a write method here,
      you've turned a reporting tab into a money path. Don't.

lib/FieldAgentService.php
  ├── USED BY: api_field_ops.php, post_field.php,
  │            tabs/sales/collect_payment.php
  │
  └── IF YOU BREAK logCollection()/submitRemittance():
      → Field collections stop working
      → Handover chain breaks

lib/StaffLedgerService.php + lib/StaffLedgerWriter.php
  ├── USED BY: api_staff_ledger.php, backfill_staff_ledger.php
  │   DUAL-WRITE FROM: KycService, post_sales, webhook, ExpenseAdvanceService,
  │                     StaffTransferService, post_field, handover_queue, staff_cashbooks
  │
  ├── IF YOU BREAK record()/voidByKey():
  │   → New ledger entries stop being written
  │   → Balance queries return stale data
  │   → BUT: primary write paths continue (dual-write is fail-safe)
  │   → Nightly backfill catches any gaps
  │
  └── Table: staff_ledger (migration 045)
      Idempotency keys: COL-/ADV-/ADVRET-/EXP-/FEXP-/HOV-/TRFOUT-/TRFIN-
      NEVER ALTER existing columns — use new migrations
```

## CRITICAL PATH: Authentication
```
lib/RetailerAuth.php
  ├── USED BY: public.php, api_handlers.php, post_handlers.php
  │
  └── IF YOU BREAK verify()/login():
      → NOBODY can log in
      → ALL API calls fail
      → Entire plugin is dead

lib/JwtAuth.php
  ├── USED BY: api_handlers.php (Bearer token check)
  │
  └── IF YOU BREAK:
      → PWA stops working
      → All authenticated API calls fail
```

## CRITICAL PATH: Starlink Auto-Block (v4.21.0+)
```
lib/StarlinkBlockService.php
  ├── USED BY: webhook.php (service.suspend, service.unsuspend, payment.add),
  │            cron_starlink_block_retry.php
  │
  ├── READS (cross-plugin, read-only):
  │   → ../dishnet-starlink-finance/data/sl_kits.json
  │   → ../dishnet-data-report/data/wifi_router_map.json
  │   → data/ucrm_clients_cache.json (for VIP tag check)
  │
  ├── CALLS (loopback HTTP):
  │   → dishnet-data-report/public.php?action=dr_wifi_get_config
  │   → dishnet-data-report/public.php?action=dr_wifi_get_status
  │   → dishnet-data-report/public.php?action=dr_wifi_pause_client
  │   → dishnet-data-report/public.php?action=dr_wifi_unpause_client
  │   → dishnet-data-report/public.php?action=dr_wifi_change_password
  │
  ├── IF YOU BREAK suspend()/restore():
  │   → Customers don't get blocked on suspension
  │   → OR worse: customers stay blocked after paying (more dangerous)
  │   → Inverse failure mode: erroneous block on a paying customer
  │     (ALWAYS test the dedup/recent-payment guard before deploying)
  │
  ├── IF YOU BREAK isVipClient():
  │   → IOM/UN/embassy customers get auto-blocked when their service
  │     suspends — diplomatic incident potential
  │   → Failure mode bias: prefer false positive (block VIP) only on
  │     cache load failure; never on logic error
  │
  └── Tables: sl_suspension_state, sl_suspension_log (migration 057)
      State machine values are referenced in code as constants —
      never change the string values without grep-checking all callers
```

**Starlink-specific safety rules:**

- **VIP suspension is silent for the customer (v4.21.2+)**: webhook.php's
  service.suspend case checks isVipClient() BEFORE sending any WA/FCM/block.
  If VIP, single admin alert + whResp(200), customer sees nothing. NEVER
  re-add customer-facing notifications inside the VIP path without
  explicit business sign-off — embassies/UN expect zero noise.

- The HTTP path to dishnet-data-report assumes session-less calls fall
  through to the action handlers (verified in dr_wifi_change.php line
  1778). If data-report adds auth gates to dr_wifi_* actions, this
  service breaks silently. Re-test after any data-report release.
- Every gRPC call goes through Starlink's cloud API. Rate limits exist —
  drWifiGrpcCall throttles starlink.com on 429/503. Don't loop blindly.
- Password restore depends on `original_pass_24` saved at suspend time.
  If that field is empty (gRPC failed during save_state), restore writes
  nothing useful — alertAdmin fires. Don't silently "succeed" with blank
  passwords.
- VIP guard checks ucrm_clients_cache.json. If cache is stale (no
  cron_sync ran for 24h), a NEW VIP client added today won't be in the
  cache and the tag check returns false (= no VIP protection). Always
  set the explicit starlink_block_vip_clients config list as a backstop.
- VIP tag matches on TAG ID first (config: starlink_block_vip_tag_id,
  default 84 = NO_AUTO_BLOCK on production CRM). Falls back to tag
  NAME (config: starlink_block_vip_tag_name, default 'NO_AUTO_BLOCK')
  if ID is missing. ID matching survives renames; name matching
  survives delete-and-recreate. If the tag is reorganized in UCRM,
  update both config keys.
- The SSID change is visible to anyone scanning WiFi — chosen value
  ('DishNet-PAY-NOW') is intentional. Don't change to anything that
  could be embarrassing if a journalist scans for networks near a UN
  compound.
- **Manual force-restore (v4.21.16+)**: Admin → Starlink Suspensions
  tab calls StarlinkBlockService::restore($cid, 'manual:<name>')
  directly. Idempotent (same as webhook path). If you change the
  signature of restore() or the side effects, audit
  tabs/admin/starlink_suspensions.php at the same time — the form
  passes only client_id and triggeredBy.

## CRITICAL PATH: WhatsApp
```
lib/WaBotService.php + lib/ConversationService.php
  ├── USED BY: wa_webhook.php, evo_webhook.php, cron_wa_bot.php,
  │            api_whatsapp.php
  │
  └── IF YOU BREAK handleIncoming():
      → Customer messages go unanswered
      → Bot stops responding
      → Inbox stops updating

lib/NotificationService.php
  ├── USED BY: webhook.php, post_field.php, cron_lte.php,
  │            api_crm_sync.php, many tabs
  │
  └── IF YOU BREAK send()/sendWhatsApp():
      → ALL WhatsApp notifications stop
      → Payment receipts not sent
      → Alerts not delivered
```

## CRITICAL PATH: KYC / Customer Onboarding
```
lib/KycService.php
  ├── USED BY: includes/post/post_kyc.php, api/index.php (POST /v1/kyc),
  │            cron_sync.php (CRM retry queue), includes/post/post_admin.php
  │
  ├── handleNew()       → creates new UCRM client + quote + (optional) cash payment
  ├── handleExisting()  → existing-customer additional service flow
  │                       creates a UCRM scheduling job assigned to Bidal
  │                       (v4.20.2+) — failure here is non-fatal, quote stands
  │
  ├── IF YOU BREAK process() or handleExisting()/handleNew():
  │   → Agents cannot register customers
  │   → Wallet debits stop firing for cash sales
  │   → No CRM client/quote creation
  │
  └── Returned data shape contract:
      Both handlers MUST return ['success'=>bool,'message'=>string,'data'=>array].
      'data' MUST include: crm_client_id, application_id, id (alias), quote_id,
      quote_ref, quote_created, amount_charged, wallet_debited.
      handleExisting() ADDITIONALLY includes (v4.20.2+):
        is_additional_service=true, crm_client_type='existing',
        crm_job_id, new_address, new_service_address, firstname, lastname,
        mobile, customer_type, connectivity_type, sales_type
      → post_kyc.php and api/index.php BRANCH on is_additional_service to
        route to NotificationService::kycAdditionalService() instead of
        kycSubmitted()+kycCrmCreated(). Removing the flag will silently
        revert to the misleading "New Registration" alert for existing
        customers and Bidal will lose the dedicated brief.
```

## CRITICAL PATH: LTE
```
lib/LteService.php + lib/MagmaApiClient.php
  ├── USED BY: cron_lte.php, api_lte.php, api_lte_admin.php
  │
  └── IF YOU BREAK:
      → LTE auto-suspend/reactivate stops
      → Subscribers can't be managed
      → LTE dashboard shows nothing
```

## UI TABS: Lower risk but visible
```
tabs/accounts/*.php  → Rupesh's screens (cashbook, handover, settlement)
tabs/sales/*.php     → Agent screens (collect, KYC, wallet)
tabs/admin/*.php     → Admin screens (settings, backup, updater, stock management)
tabs/support/*.php   → Bidal's screens (scheduling, dispatch, NOC, my equipment)
tabs/engage/*.php    → WhatsApp screens
tabs/lte/*.php       → LTE module screens
```

## CRITICAL PATH: Stock Management
```
lib/StockService.php
  ├── USED BY: api_stock.php, stock_dashboard.php, my_equipment.php
  │            (Phase 3: KycService, api_scheduling.php)
  │            (Phase 5: cron_lte_sync.php)
  │
  ├── IF YOU BREAK createUnit()/checkout()/install():
  │   → Stock tracking stops
  │   → Agent equipment assignments fail
  │   → Inventory counts go wrong
  │
  └── Tables: stock_categories, stock_units, stock_quantities,
      stock_movements (immutable), stock_purchases
      NEVER UPDATE/DELETE stock_movements — insert-only audit log
```
UI tabs are relatively safe to edit — they mostly READ data.
But if you change the HTML structure of a form, check that
the corresponding post_handler or API endpoint still receives
the same field names.

---

# ✅ PRE-EDIT CHECKLIST (copy-paste this before every edit)

```
□ Read the FULL file I'm about to edit
□ Searched for all callers: grep -rn 'functionName' --include='*.php' .
□ Confirmed PHP 7.4 compatible (no match/enum/named args)
□ Confirmed polyfills present if using str_contains etc.
□ Confirmed I'm not changing any method signature that has callers
□ Confirmed I'm not changing any JSON key names
□ Confirmed I'm not changing any SQL column names
□ If touching money flow: checked save-before-rebuild order
□ Noted the exact line range I'm changing for easy revert
```

# ✅ POST-EDIT VERIFICATION

```
□ php -l on every changed file (syntax check)
□ Searched for any new str_contains/str_starts_with usage → added polyfill
□ If new migration: confirmed next sequential number
□ If new API endpoint: confirmed it's registered in api_handlers.php
□ If new POST action: confirmed it's registered in post_handlers.php
□ If new cron: confirmed it's registered in cron/master.php
□ If new tab: confirmed it's registered in navigation.php
□ Test suite passes (if available): php run_tests.php
```

---

# 🔖 VERSION LOG (append after every session)

## v4.21.114 (2026-06-19) — Emergency "Route all via Accounts" toggle

### Why
Support WhatsApp number got blocked in production. Needed a one-click
way to push ALL notifications (Support + Accounts channels) out via the
Accounts number until Support recovers — without editing code or
copy-pasting app keys around.

### Key fact (document so future code stops guessing)
WhatsApp sender routing is decided **purely by WASender app_key**, not by
the wa_support_number / wa_accounts_number fields. Those number fields are
labels/recipients only. `sendVia()`, `sendDocument()`, `sendImage()` all
pick the key with the same ternary:
`($sender === ACCOUNTS) ? accountsAppKey : appKey`.

### What
- New config flag `wa_force_accounts` (bool). Saved in
  `includes/post/post_admin.php` alongside the other wa_* keys.
- `lib/NotificationService.php` constructor: reads the flag; when ON,
  collapses `$this->appKey = $this->accountsAppKey`. Because all three
  send paths key off `$this->appKey` for the SUPPORT branch, this single
  override reroutes text + PDF + image sends to Accounts at once.
  `accountsAppKey` already falls back to `appKey` when unset, so the
  override is safe even with one key configured.
- `tabs/engage/whatsapp.php`: amber toggle under the App Key fields,
  mirrors the existing PDF-toggle markup (hidden value=0 + checkbox
  value=1). Shows an ACTIVE warning banner when ON.

### Caveats
- The Accounts App Key must be bound to a WORKING (non-blocked) WASender
  app/number. If both keys point to the same blocked app, the toggle does
  nothing — provision a fresh number first.
- Doubling all traffic onto one number risks getting it flagged too.
  Turn OFF once Support recovers; this is an emergency stopgap.

### SAFETY
- Additive only. No method signatures changed, no schema/JSON keys
  changed, no migration. PHP 7.4 compatible.
- When flag is OFF (default/absent), behaviour is identical to v4.21.113.


## v4.21.34 (2026-04-29) — Critical bridge KIT-resolution bug fix

### Why
Bhavin's screenshot revealed a serious bug in v4.21.29-33: every
bridge call (real webhook or synthetic test) was returning
`no_kits` / `0 restored` even for customers whose service names
clearly contained KIT serials.

Specifically:
- Ruach Diu Top Billiu (KIT4M03895652B2D)
- African Guest House (KIT4M01903618JZH)
- Archbishop Elias Taban Parangi (KIT4M0373843829H)
- Maroun Deng Morwel
- All others on the audit list

The audit endpoint correctly extracted these same KITs and showed
"Ready to block" status. But the bridge couldn't see them.

### Root cause
`StarlinkBlockBridge::resolveClientKits()` Source B (UCRM service-
name regex fallback) was constructing CrmApiClient like this:

```php
$baseUrl = $this->config['crm_base_url'] ?? '';
$token   = $this->config['crm_auth_token'] ?? $this->config['crm_app_key'] ?? '';
if ($baseUrl !== '' && $token !== '') { ... call UCRM ... }
```

But this plugin doesn't store CRM config under keys
`crm_base_url` / `crm_auth_token`. Those are placeholder names
copied from a different plugin. Real config comes from
`ucrm.json` (auto-generated by UCRM at plugin install time).

So the if-branch was never entered. UCRM was never called.
service.name regex never ran. Source A (sl_kits.json, incomplete)
was the only source. Result: empty kits for almost every customer.

The audit endpoint dodged this bug because it has its own UCRM
client wired up earlier in the request — not via this same code
path.

### Fix
Use `CrmApiClient::fromUcrm($pluginRoot, $config)` factory, which:
1. Tries manual config override first (config['crm_base_url'] +
   config['crm_auth_token']) for compatibility
2. Falls back to ucrm.json (the actual production source —
   ucrmLocalUrl + pluginAppKey)
3. Returns a properly-configured client with the right auth header

Also: exception in this path now logs to
`data/sl_block_bridge.log` instead of being swallowed silently.

### Impact
After v4.21.34 deploy:
- Every webhook (`payment.add`, `service.suspend`, `unsuspend`,
  `postpone`) for a customer with a KIT in their service name
  will correctly resolve KITs and call data-report.
- The Block Manager 🧪 Test buttons will succeed for those
  customers (instead of returning no_kits).
- Stuck Payments audit will detect actual restore failures (not
  spurious ones from the resolution bug).

This is the bug that was making the whole v4.21.29 zero-click
flow look broken in production. It's a one-line root cause.

### REQUIRES
- No data-report change (existing v2.8.61 fine)

## v4.21.33 (2026-04-29) — Master.php triggers pause-extension every 10 min

### Why
After v4.21.32 + data-report v2.8.60 deployed, Cron Health panel
still showed "Cron has never run" — meaning UCRM's plugin scheduler
is not picking up data-report's `cron_test_block_extend.php` at all,
despite being registered in `dishnet-data-report/manifest.json`
crons[]. Root cause never confirmed (UCRM scheduler config, plugin
permissions, host cron daemon — could be any of them).

Bhavin's suggestion: "push in data-report simple logic which we using
fetching Connected Devices means who is under pause like human doing
button press every 10 min." That button already exists in
data-report — `dr_wifi_test_block_extend_now`, used by the WiFi
tab's "Extend Pauses (N)" button. So we don't need any new logic;
we need a different trigger.

Hybrid's `master.php` cron IS firing reliably (proven by webhooks
processing, wa_sync running, `sl_block_retry` running). Solution:
ride that path.

### What
**New: `cron_starlink_extend_pauses.php`** at Hybrid root. Each
run:
1. Reads shared internal-auth secret from `_dishnet_shared/internal_auth.json`
2. Resolves data-report URL from `ucrm.json`
3. cURL POST to `dr_wifi_test_block_extend_now` with
   `X-DishNet-Internal-Auth: <secret>` header
4. Logs result to `data/sl_extend_cron.log.json` (last 200)

**Registered in `cron/master.php`** at interval 600, right after
`sl_block_retry`:
```php
'sl_extend_pauses' => ['interval' => 600, 'script' => dirname(__DIR__) . '/cron_starlink_extend_pauses.php'],
```

**New endpoint `sl_extend_cron_health`** reads the new log file
and exposes the same shape data-report's health endpoint did.
Block Manager UI's Cron Health panel now reads from this Hybrid
endpoint.

**Banner copy refined** for "never ran" state — was "❌ broken,"
now "⏳ This is expected right after deploy; first tick within 10
min."

### What we didn't change
- data-report's `cron_test_block_extend.php` is still registered.
  If UCRM ever picks it up, both crons fire — second one no-ops
  via file lock. Idempotent.
- The actual extension logic on data-report side. Same code path
  WiFi tab's "Extend Pauses" button uses, same code path the
  registered cron would use. Just triggered from a path that
  works.

### REQUIRES
- data-report v2.8.61+ (extend_now whitelisted for internal-auth)

## v4.21.32 (2026-04-29) — Cron health: Run Now + heartbeat-aware banner

### Why
v4.21.31 deployed; Bhavin's screenshot showed "Cron has never run"
even though data-report's WiFi tab listed `Extend Pauses (9)` —
meaning the cron logic exists and there are 9 paused routers
needing extension. Root cause was on data-report side: cron
silently exits when state file is empty/missing without logging
anything. So the log was empty for the whole window before blocks
happened, even though cron was firing 144 times/day. data-report
v2.8.60 fixes that with a heartbeat file + always-log on early
exits.

### What
**▶ Run Now button** on the Cron Health card. Calls
`dr_wifi_test_block_extend_now` (same as data-report's "Extend
Pauses" button on the WiFi tab) — manually fires the cron logic.
Lets operator verify the path works without waiting 10 minutes.
After it runs, Cron Health auto-refreshes.

**Heartbeat-aware banner.** Reads both run log and heartbeat
file. New state: "Cron is firing but the run log is stale" —
fired when heartbeat is fresh but log is stale (means cron
starts but crashes partway through). Distinguishes "scheduler
not running" from "scheduler running, code broken."

**"Never ran" banner now lists 3 likely causes**:
1. Cron not registered (manifest crons array)
2. Plugin scheduler not running on host
3. Plugin installed but never reloaded

### REQUIRES
- data-report v2.8.60+

## v4.21.31 (2026-04-29) — Auto-pause cron health panel

### Why
Bhavin's question after v4.21.30: "how do we know that cron really
run after 10 min or not?" The block-extend cron (data-report) is
what makes pause-only mode actually work for new devices joining
after the initial block. If it stops, leakiness gets unbounded.

### What
**New card in Block Manager: Auto-Pause Cron Health.**
Sits between Currently Paused Dishes and Cleanup Needed. Shows:

- **Status banner** — green if cron fired <12 min ago, yellow if
  12–25 min (one tick missed), red if >25 min (broken). Banner
  text explains operationally what's happening.
- **4 KPIs** — runs in 24h (expected ~144), total new devices
  paused, total pause failures, expected.
- **Last 20 cron run table** — per run: when (relative + absolute),
  routers seen, processed, newly_paused (orange if >0), failures
  (red if >0), skipped count, duration.

Auto-loads on tab open. Refresh button for manual recheck.

### Endpoint
`dr_wifi_test_block_extend_health` (added in data-report v2.8.59).
Returns last_run_ts, status, 24h aggregates, and last 20 runs.
Read-only, no Starlink calls, cheap to poll.

### How to use
Open Block Manager. Scroll to the new card. If green: cron is
running on schedule, leak window is bounded to ~10 min. If yellow
on first load, refresh in 2-3 min — should turn green when the
next tick fires. If red persistently: check UCRM plugin manager
for the cron registration on dishnet-data-report.

### REQUIRES
- data-report v2.8.59+

## v4.21.30 (2026-04-29) — Verification tooling for the auto-restore bridge

### Why
v4.21.29 wired up the bridge for zero-click auto-block / auto-
restore. Bhavin's reasonable next question: "how do we verify
that if someone paid, system auto-unblocked?" Without visibility,
silent failures could pile up — a customer pays, dish stays
paused, support gets called.

### What

**Panel: Stuck Payments — Paid but Still Paused.**
For each customer who paid in the selected window (24h / 48h /
7d), check if their dish is still in data-report's
`wifi_test_block_state.json`. If yes, auto-restore didn't fire or
failed — they need manual intervention. Empty list = green ✓
banner showing "All Recent Payments Auto-Restored Cleanly". Any
rows = per-row Unblock button. New endpoint
`sl_payment_restore_audit`.

**Panel: Bridge Activity Log.**
Viewable history of every webhook-triggered bridge call. Each row:
when, kind (SUSPEND/RESTORE pill), customer + CRM #, trigger
(e.g. `webhook:payment.add`), outcome (OK / skipped / failed),
routers processed/failed, per-attempt detail (errors, notes). Two
filters: kind (suspend/restore/all), status (all/failed-only).
New endpoint `sl_bridge_events`.

**Structured event logging in `StarlinkBlockBridge`.**
Every `suspendClient` / `restoreClient` call writes a JSON event
to `data/sl_block_bridge_events.json`:
```
{ts, kind, client_id, triggered_by, ok, routers_processed,
 routers_failed, skipped_reason, note, attempts[]}
```
All return paths instrumented including early-return cases
(VIP-skipped, no-kits, nothing-paused, client-not-in-paused).
Auto-rotates at 2 MB (keeps last 2500 events).

### How to verify auto-restore worked
1. Process a payment in UCRM for a customer whose dish is
   currently paused.
2. Open Admin → Starlink Block Manager.
3. Scroll to **Bridge Activity Log**, click ⟳ Refresh.
4. Top row should be `RESTORE` / `webhook:payment.add` / `✓ OK` /
   `1 restored` for that customer's CRM ID.
5. Scroll to **Currently Paused Dishes**, click ⟳ Refresh —
   that customer's router should be gone.

### How to audit daily
1. Open **Stuck Payments** panel, lookback = 24h, click Refresh.
2. If empty: every payment auto-restored, system working.
3. If anyone listed: manual unblock via the row button. Then
   investigate via Bridge Activity Log filtering `client_id`.

### SAFETY
- All new endpoints admin-only (gated upstream).
- Read-only audit; no writes to money tables, no schema changes.
- Logging wrapped in try/catch — never breaks the bridge call.
- Log file capped at 2500 entries; old entries dropped.

### Addendum (same session) — synthetic bridge-test trigger

The audit + log panels above show what HAS happened. Bhavin pushed
back with a sharper version of the question: "how do we know that
what we built for add payment will restore the services or not?"
i.e. how do we verify the bridge works for a SPECIFIC customer
without waiting for a real payment?

Added two POST endpoints:
- `sl_bridge_test_restore` (POST {client_id}) — calls
  `StarlinkBlockBridge::restoreClient()` synchronously. Same code
  path as `webhook.php` case `payment.add`. Returns full attempts
  array with per-router success/error.
- `sl_bridge_test_suspend` (POST {client_id}) — mirror for the
  suspend path (same as webhook case `service.suspend`).

Both tag the event `triggered_by=ui:manual_test_<verb>:<admin>` so
synthetic tests are distinguishable from real webhook events in
the Bridge Activity Log.

UI: each row in the Suspended Customers table now shows a small
🧪 Test button (only when client has resolvable KIT and isn't VIP).
Click → confirm modal → synchronous bridge call → result modal
showing per-router attempts → audit/paused/bridge panels auto-
refresh after 800ms.

Successful test confirms the entire chain works: Hybrid endpoint →
StarlinkBlockBridge → KIT resolution → cURL with X-DishNet-Internal-
Auth header → data-report v2.8.58 auth gate → dr_wifi_lookup_by_kit
→ dr_wifi_test_block / test_unblock → gRPC to dish.

Files: `includes/api/api_crm_misc.php` (+2 endpoints),
`tabs/admin/starlink_pauses.php` (+1 button per row, +1 confirm
modal, +1 result modal).

## v4.21.29 (2026-04-29) — Auto-block on suspend, auto-restore on payment/postpone (zero-click)

### Why
Bhavin's ask: "if CRM gets payment it auto-unblock and also if we
postpone suspension it also auto-unblock for the duration we have
and future event of suspense it auto-block."

The webhook handlers (`service.suspend`, `payment.add`,
`service.unsuspend`, `service.postpone`) have always called
`StarlinkBlockService::suspend()` / `restore()` — but those methods
read from sl_kits.json + wifi_router_map.json directly and the
local file is incomplete in production. So the webhooks have
been firing but doing nothing for most customers since v4.21.0.

### What
**New: `lib/StarlinkBlockBridge.php`** (~370 lines) — thin server-
side wrapper around data-report's `dr_wifi_test_block` /
`dr_wifi_test_unblock`, called from PHP via cURL with an internal-
auth header. Same proven path the Block Manager UI uses, just
hitting it from the webhook handler instead of the browser.

**Methods:**
- `suspendClient(clientId, freshClient?, triggeredBy)` —
  VIP guard → resolve KITs (sl_kits.json + UCRM service.name regex)
  → for each KIT, GET `dr_wifi_lookup_by_kit` → POST
  `dr_wifi_test_block`. Idempotent: detects "already in test-block"
  error string from data-report and treats as success.

- `restoreClient(clientId, triggeredBy)` —
  Resolve client KITs → read data-report's
  `wifi_test_block_state.json` + `wifi_router_map.json` directly
  (server-side file reads, no auth needed) → for each router
  belonging to this client that's currently paused, POST
  `dr_wifi_test_unblock`. Idempotent: returns ok with 0 routers
  if nothing was paused.

**Webhook integrations** in `webhook.php`:
- `payment.add` → `restoreClient()` after existing instant-restore
  call. Bridge picks up customers blocked via Block Manager UI /
  WiFi tab (which write to data-report state, not Hybrid's table).
- `service.suspend` → `suspendClient()` after existing
  `StarlinkBlockService::suspend()` call. Bridge actually does the
  work via data-report.
- `service.unsuspend` / `activate` / `suspend_cancel` →
  `restoreClient()` after existing restore call. Bridge picks up
  any leftover paused routers.
- `service.postpone` → `restoreClient()` after existing restore
  call. Customer's dish unpauses for the postpone duration; if
  service.suspend fires again later (postpone expires), the
  suspend hook re-blocks.

All bridge calls wrapped in try/catch — failure logged via whLog
but webhook response is never broken.

### Server-to-server auth
Hybrid runs webhook handlers without an admin browser session, so
data-report's existing PHPSESSID/nms-session cookie gate would
reject server-to-server cURL. New mechanism:

- Shared secret file at `/data/.../plugins/_dishnet_shared/
  internal_auth.json` (sibling dir both plugins reach via dirname)
- Bridge auto-generates 24-byte hex secret on first use, writes
  atomically with LOCK_EX, chmod 0640
- Bridge sends `X-DishNet-Internal-Auth: <secret>` on every cURL
- Data-report v2.8.58 accepts the header as session-equivalent for
  5 whitelisted internal actions (test_block, test_unblock,
  test_block_status, lookup, lookup_by_kit)
- `hash_equals()` for timing-safe verification

Trust model: file lives on the same disk as both plugins; anyone
with file access already has root-equivalent control of the host.
Secret guards against accidental exposure via misconfigured proxy.

### What this unlocks (zero-click flows)
1. Customer doesn't pay → UCRM auto-suspends → `service.suspend`
   webhook → bridge → dish paused. (Was: silent fail since v4.21.0.)
2. Customer pays → `payment.add` webhook → bridge → dish restored.
   (Was: only worked for customers in `sl_suspension_state`.)
3. Admin postpones → `service.postpone` webhook → bridge → dish
   restored for postpone duration. When postpone expires and
   service.suspend fires, dish re-blocks automatically.

### REQUIRES
- `dishnet-data-report` v2.8.58+ (accepts `X-DishNet-Internal-Auth`)

### SAFETY
- Original `StarlinkBlockService` calls preserved alongside new
  bridge calls — bridge is additive, not a replacement. If
  StarlinkBlockService ever starts working again, both fire
  (idempotency on data-report side ensures no double-block).
- All bridge code wrapped in try/catch; webhook responses unchanged.
- VIP guard runs FIRST in `suspendClient()` — VIP customers never
  see a block call go out to data-report, even if everything else
  is misconfigured.
- Auth gate on data-report side is additive (header OR session) —
  no regression for any non-bridge flow.

## v4.21.28 (2026-04-29) — Starlink-only filter + cross-plugin already-paused

### What was wrong
Production deploy of v4.21.27 surfaced two issues:

1. **FTTH/LTE customers listed as "No KIT in title"**.
   The Cleanup Needed list had 9 customers but 7 were FTTH:
   - Achok Jiel (000239) — `Service Plan FTTH100`
   - Alexandar Taban James (000240) — `FTTH50`
   - GEBRESLASSIE OGBAZGHI (000086) — `FTTH100`
   - Just in Time — `FTTH-50` (×2 services)
   - Maroun Deng Morwel (000136) — `FTTH100`
   - Simon Mangong Bol (000089) — `FTTH75`
   - Tedros leke (000097) — `FTTH50`

   These should never appear in a Starlink tool — they're on
   different infrastructure (Splynx/MikroTik for FTTH; Magma for
   LTE). Only 2 were genuine Starlink cleanup cases:
   Mr. Ocheng Kenneth Kaunda, Relief Pact.

2. **Customers paused via Block Manager / WiFi tab showed as "Ready
   to block"** instead of "Already paused". The audit's
   already_blocked check only consulted `sl_suspension_state`
   (Hybrid's table). Block Manager writes through data-report's
   `wifi_test_block_state.json` instead, so Hybrid never sees it.

### Fix
**Filter (audit endpoint):** `$rawSuspendedServices` is filtered to
Starlink-only before grouping. A service is included if its name
contains a KIT regex match (`\bKIT[A-Z0-9]{8,}\b/i`) OR its
`servicePlanName` contains "starlink" (case-insensitive). Non-
matching services counted in new `totals.non_starlink_skipped`.
UI shows this as a stat tile when relevant.

**Cross-plugin already-paused (audit endpoint):** After computing
KITs by client, the audit now reads:
- `dishnet-data-report/data/wifi_test_block_state.json` (which
  router_ids are currently paused)
- `dishnet-data-report/data/wifi_router_map.json` (router_id →
  kit_serial)

Builds the set of currently-paused KITs, then for each suspended
client, if any of their KITs is in that set, marks `already_blocked`.
Result: customers paused via WiFi tab manually (or Block Manager)
correctly classify as "Already paused" with the Unblock action,
not "Ready to block".

### UI label updates
- Stats tile "Suspended (UCRM)" → "Suspended Starlink"
- New stat tile (only shown when > 0): "Non-Starlink (FTTH/LTE)
  skipped"
- Meta line under audit toolbar updated to mention skipped count.

### What's still v4.22 plan
Webhook auto-flow (`service.suspend`, `payment.add`) still uses
the broken `StarlinkBlockService::suspend()` /
`resolveClientRouters()` chain. v4.22 rewires those service
methods to delegate to data-report HTTP. Once shipped: blocks
fire automatically on UCRM suspend, restores fire on payment.

## v4.21.27 (2026-04-29) — Full Starlink Block Manager UI

### Why
v4.21.26 added a read-only "Pauses (Live)" tab plus more console
commands. Bhavin pushed back: console workflow doesn't scale to the
team. Whoever runs accounts (Aida, Rupesh, Bidal) needs a UI they
can use without DevTools.

### What
**Rewrote `tabs/admin/starlink_pauses.php`** (now labelled "Starlink
Block Manager" in the sidebar) into a full operator UI with three
integrated panels:

1. **Suspended Customers (UCRM Live)** — checkbox-selectable table
   driven by `sl_audit_suspended`. Per-row "Block" button, plus
   toolbar buttons: "Block Selected (N)", "Block All Blockable",
   "↻ Retry Offline". Color-coded status pills (Ready / Already
   paused / VIP / No KIT in title). Modal confirm with customer
   list preview before bulk action. Live progress indicator
   highlights current row in yellow during sweep. 2-second gRPC
   throttle between calls.

2. **Currently Paused Dishes (Data Report Live)** — table driven
   by `dr_wifi_test_block_status`. Each row has Unblock button.
   Optional "Enrich with customer info" parallel-fetches
   `dr_wifi_lookup` per router (batched 4 at a time) to populate
   customer name, KIT, account.

3. **Cleanup Needed** (collapsible, auto-shown only when relevant)
   — list of customers whose UCRM service titles don't contain
   a KIT serial, with their current title for copy-paste sharing
   with whoever updates UCRM.

Plus a summary stats bar with 5 KPIs at the top.

### Endpoints used (all unchanged — UI only)
- Hybrid: `sl_audit_suspended` (GET, admin)
- Data Report: `dr_wifi_lookup_by_kit`, `dr_wifi_test_block`,
  `dr_wifi_test_unblock`, `dr_wifi_test_block_status`,
  `dr_wifi_lookup` (GET/POST as appropriate)

### Console script kept
`sl_manual_suspend.console.js` from v4.21.26 still works for
power-users / debugging. UI is the primary path; console is the
fallback.

### What's still v4.22 plan
Webhook auto-flow (`service.suspend`, `payment.add`) still uses the
broken `StarlinkBlockService` + local `wifi_router_map.json` chain.
Next session: rewrite those service methods to delegate to
data-report HTTP, then auto-pause-on-suspend and auto-restore-on-
payment will work without operator intervention.

### SAFETY
- Read-only by default; every action requires modal confirm.
- Admin-only.
- Same cache-busting + cache:'no-store' pattern from v4.21.26 so
  cross-plugin fetches get past UCRM's service worker.
- Tab registered in all three required places (per the standing
  rule): `$_tabFiles`, `$ALL_MODULES`, `includes/navigation.php`.

## v4.21.26 (2026-04-29) — Operator UI + console helpers

### Why
v4.21.25 got the manual-block flow working end-to-end via data-report
(8 of 16 dishes paused tonight, 5 offline-and-retryable, 3 already-
blocked, 9 with no KIT in service title). Bhavin asked for:

1. A way to retry the offline ones without re-running the full sweep
2. A clean-up list for the 9 customers whose UCRM service titles
   need a KIT serial added
3. A UI to view paused dishes and unblock individual ones (instead
   of having to switch to the Data Report plugin every time)

### What
**New tab: `tabs/admin/starlink_pauses.php`** (Admin → Starlink Pauses).
Read-only table showing dishes currently paused via data-report.
One-click unblock per row. JS-driven (browser fetches from
data-report directly via PHPSESSID, same pattern as the console
script). Cache-busting + cache:'no-store' bypasses UCRM's service
worker. Registered in all three required places: `$ALL_MODULES`,
`$_tabFiles`, `navigation.php`.

**Distinct from `starlink_suspensions` tab** — that one shows
Hybrid's own `sl_suspension_state` table (Hybrid's state machine).
This new tab shows data-report's `wifi_test_block_state.json` (the
actual gRPC pause state on each dish). Until v4.22's webhook
rewire, these can drift; this tab makes the data-report side
visible inside the same UI.

**Three new console commands** in `sl_manual_suspend.console.js`:

- `slListBlocked()` — calls `dr_wifi_test_block_status` for a
  quick listing of all currently paused routers.
- `slRetryOffline()` — re-runs just the customers from the last
  sweep that failed with `DEVICE_NOT_CONNECTED` / offline /
  unreachable. Reads `window.__slLastSweep`. Updates the persisted
  state with new outcomes so chained retries only target still-
  failing ones.
- `slKitCleanup()` — prints a copy-pasteable table of customers
  whose UCRM service titles don't contain a KIT serial. Suggests
  the title-format fix so future audits will catch them.

### What's NOT done yet (v4.22 plan)
- Webhook auto-flow still uses the broken `StarlinkBlockService`
  → `loadRouterMap()` chain. v4.22 will rewrite
  `StarlinkBlockService::suspend()` and `restore()` to delegate
  to data-report HTTP endpoints (same path the console + UI
  already use). Then `service.suspend` and `payment.add` webhooks
  will work correctly without code changes elsewhere.

### SAFETY
- New tab is read-only by default; unblock requires explicit click
  + JS confirm dialog.
- Admin-only.
- Per the standing rule, the new tab is wired in `$_tabFiles`,
  `$ALL_MODULES`, AND `includes/navigation.php` (sidebar) — three
  places, all updated.
- Cache-busting param + `cache:'no-store'` on every cross-plugin
  fetch — same workaround for UCRM's service worker as the console
  script.

### Lesson for SAFETY
- UCRM has a service worker (`public.php?page=app_sw`) that
  intercepts in-iframe fetches. Cross-plugin URLs trigger
  `Failed to fetch` / `ERR_FAILED` from inside the plugin iframe
  unless `cache:'no-store'` is set. Same URL works fine in a top-
  level browser tab.

## v4.21.25 (2026-04-29) — Console script: use data-report directly

### Why
v4.21.24 fixed KIT serial extraction but `slBlockOne(589)` still
returned `skipped_reason='no_routers'`. The third layer of the chain
(KIT → router_id) was failing because Hybrid's
`wifi_router_map.json` lookup didn't have entries for the suspended
customers — that map lives in `dishnet-data-report`, not Hybrid.

User pointed out the data-report WiFi tab already does end-to-end
blocking flawlessly via `dr_wifi_test_block`. Right architectural
question: don't recreate router lookup in Hybrid, call data-report's
existing endpoints.

### What changed
**Hybrid:** only the Chrome console script. PHP code unchanged.

`sl_manual_suspend.console.js` rewritten to:
- Audit via Hybrid `sl_audit_suspended` (unchanged)
- For each blockable customer:
  1. Call **`dr_wifi_lookup_by_kit`** (new in data-report v2.8.57)
     to translate kit_serial → router_id
  2. Call **`dr_wifi_test_block`** (existing in data-report) to
     actually pause via gRPC — same path as manual WiFi tab use
- New command **`slUnblockOne(clientId)`** — calls data-report
  `dr_wifi_test_unblock` for the resolved router

**Data Report (separate plugin):** ships v2.8.57 with new endpoint
`dr_wifi_lookup_by_kit`. Single new admin action ~80 lines, mirror
of existing `dr_wifi_lookup` but takes kit_serial instead of
router_id. Whitelisted in `$adminActions`.

### What didn't change
- `StarlinkBlockService` — kept as-is. Webhook auto-block path still
  uses it; if/when `wifi_router_map.json` is properly populated, it
  works. Manual sweep just bypasses it.
- Hybrid `sl_manual_suspend` endpoint — kept (still callable) but
  the new script doesn't use it.
- `sl_suspension_state` table — not written by the new flow. Manual
  blocks tracked only in data-report's test_block state. When
  customer pays, operator unblocks via data-report (or
  `slUnblockOne`); `payment.add` webhook's restore-on-payment path
  is irrelevant for these customers.

### Trade-off accepted
No automatic restore-on-payment for manually-blocked customers —
operator must unblock when payment received. Acceptable for the
26 backlog customers; long-term auto-restore needs `wifi_router_map`
populated for these KITs (separate work in data-report).

### Lesson for SAFETY
- When two plugins both touch the same data file, the plugin that
  writes it owns the endpoints around it. Don't duplicate-read
  across plugin boundaries.
- Admin session cookie (PHPSESSID) auto-flows between sibling
  plugins on same host — server-to-server HTTP works without
  passing tokens.

## v4.21.24 (2026-04-29) — KIT resolution multi-source

### What was wrong
v4.21.23 fixed the suspended-clients detection (26 found correctly via
live UCRM API + status codes 3/4). But every single one showed
`kit_count=0` and was skipped with action='skip (no KIT)'. Diagnosis:

- `sl_kits.json` doesn't have entries for these customers
- `getClientKitSerials()` only reads sl_kits.json
- → block code path returns `skipped_reason='no_routers'` even when
  the audit thinks they're blockable

So even when auto-block was technically working, it was failing
silently for the customers in production.

### Fix
Union of two KIT sources:

1. **sl_kits.json** with expanded field-name detection. UCRM JSON
   schemas vary — now also tries: `clientId`, `crmClientId`,
   `customer_id`, `customerId`, `assigned_client_id`, `assigned_to`,
   `kitSerial`. Plus the existing `client_id`/`crm_client_id`/etc.

2. **UCRM service.name regex.** Pattern: `/\bKIT[A-Z0-9]{8,}\b/i`.
   Picks up KITs embedded in service titles like
   `Site : ACME (KIT401723651PG7) : Service Plan Starlink...` —
   which is how DishNet's UCRM is populated.

   Service-name extraction only runs if sl_kits.json came up empty
   for the client → no extra round-trip when the JSON has the data.

### API changes
- Audit response rows now include `kit_source` field
  (`json` / `service_name` / `both` / `none`).
- Debug mode (`?debug=1`) reports:
  - `kits_json_loaded`, `kits_json_total` — is the JSON file
    even there, how many entries
  - `kits_by_source` — counts per source for the suspended set
  - `kits_sample_per_client` — first 8 client→KITs mappings
  - `router_map_path`, `router_map_total` — wifi_router_map.json
    in dishnet-data-report
  - `router_map_kits_matched_for_suspended` — how many suspended
    customers have at least one KIT that resolves to a router in
    data-report. **This is the real "blockable" count.** If a KIT
    isn't in the router map, the gRPC block call has nothing to
    target.

### Same fix in StarlinkBlockService
`getClientKitSerials()` in lib/StarlinkBlockService.php gets the same
multi-source treatment so the actual block code path matches what
the audit endpoint sees. Audit and block stay consistent.

### Tested
Regex tested against the 8 sample suspended service names from
production debug output: 6/8 matched. The 2 non-matches were
correct skips (one Starlink service whose title doesn't contain
the KIT — needs sl_kits.json fix or service title update; one
FTTH customer with no KIT, correctly excluded).

### Lesson for SAFETY
- Multi-source data resolution beats single-source brittleness.
  Anywhere code does `if ($X['client_id'] === ...)` against a JSON
  file UCRM didn't write itself, expect the schema to drift.
- UCRM service titles are the operator-controlled source of truth
  for KIT assignment in this install. Use them.

## v4.21.23 (2026-04-29) — sl_audit_suspended: live UCRM API call

### Why v4.21.22's fix wasn't enough
Production debug output revealed three problems:

1. **`client_search_index.json` is broken for status.** All 637
   clients show `status=''` — the index builder isn't capturing
   `client.status` from UCRM at all. This isn't a bug in v4.21.22,
   it's pre-existing.
2. **`ucrm_services_cache.json` is severely stale.** Showed only
   100 services with IDs 4-17 — old clients. Recently-active
   customers (1100-1300 range) absent entirely.
3. **My multi-signal filter included status=2** (which I assumed
   meant suspended). UCRM official codes are: 0=Prepared, 1=Active,
   **2=Ended**, **3=Suspended (admin)**, **4=Suspended (no payment)**,
   5=Quoted, 6=Inactive, 7=Cancelled, 8=Obsolete. Status 2 is
   "ended" services. So my filter was including all 100 stale ended
   services, mistakenly counting them as suspended → suspended_set
   had 44 fake entries → 0 blockable (since none of those old
   ended services have current KITs).

### Fix
Audit endpoint now calls UCRM API directly:
```
$crm->get('clients/services?statuses[]=3&statuses[]=4&limit=500')
```

- `statuses[]=3` = admin-suspended services
- `statuses[]=4` = no-payment-suspended services
- Same filter pattern as `cron_invoice_notify.php:226`
- Live data — no dependency on stale caches for the suspended set
- Falls back to fetch-all + local filter if the `statuses[]` filter
  syntax is rejected by the API
- Returns 502 with detailed error if UCRM is unreachable

Client metadata (name, phone, balance, tags) still comes from
`ucrm_clients_cache.json` for performance, with a per-client live
fetch fallback when the cache misses (e.g. brand-new client whose
cache hasn't refreshed).

### Debug mode now shows live data
- `api_strategy` used (filter syntax that worked)
- `raw_suspended_services` count
- `distinct_suspended_clients` count
- `service_status_histogram` (3 vs 4 split)
- 8 sample suspended services

### Authoritative reference
UCRM service status codes (per UISP REST API spec):
| Code | Meaning              |
|------|---------------------|
| 0    | Prepared            |
| 1    | Active              |
| 2    | Ended               |
| 3    | Suspended (admin)   |
| 4    | Suspended (no pay)  |
| 5    | Quoted              |
| 6    | Inactive            |
| 7    | Cancelled           |
| 8    | Obsolete            |

Documented here so future code stops guessing. Anywhere the codebase
uses different codes for "suspended", it's wrong (or pre-dates this
release and should be reviewed).

### Lesson for SAFETY
- **Don't trust local caches for "is currently suspended"** — they
  can be stale, partial, or never refreshed. Hit UCRM directly for
  authoritative status.
- The UCRM service status codes are FIXED — don't guess from sample
  data, use the table above.

## v4.21.22 (2026-04-29) — sl_audit_suspended: fix all-zero result

### What was wrong
v4.21.21's audit endpoint returned 0 across the board even when UCRM
had 28 suspended Starlink clients in its admin UI. Root cause:
`client_search_index.json` stores client.status as the raw value from
the UCRM API. `_buildClientSearchIndex()` defaults to `''` on
missing. UCRM's `client.status` field varies between integer
(5/6 for suspended in some installs), string (`'suspended'`,
`'active'`, `'lead'`), or empty/missing.

The old filter used `(int)$status === 5 || === 6` which casts both
`''` and `'suspended'` to `0` → silently filters everyone out.

### Fix
Audit now uses a UNION of three signal sources:

1. **Services cache** — any client with a service in
   `status ∈ [2, 4, 5]`. This is the same signal
   `accounts_dashboard.php:37` and `api_lte.php:24` use, and
   matches UCRM's web "Suspended clients" filter.
2. **Client status integer** — `status ∈ [5, 6]`.
3. **Client status string** — `status ∈ ['suspended', 'inactive',
   'archived']` (case-insensitive).

A client matched by ANY signal lands in the suspended set. Each row
carries `suspend_signals` (array) showing which signal(s) flagged
it. Totals expose `flagged_by_service` and `flagged_by_client`
sub-counts so data-quality issues are visible.

### Debug mode
`GET ?page=api&action=sl_audit_suspended&debug=1` returns:
- Cache sizes (index / clients / services / suspended set)
- `client_status_histogram` (raw values → counts)
- `service_status_histogram` (raw values → counts)
- 5 sample rows from each cache (with `gettype(status)`)

The Chrome script's new `slDebug()` calls this and pretty-prints.
Useful when audit returns zero — shows exactly what status shapes
are actually in the cache.

### Lesson for SAFETY
- `client_search_index.json`'s `status` field is **not** reliably
  numeric. Anywhere code does `(int)$idx['status'] === 5`, it's
  brittle.
- Prefer **service-status-based** detection where possible — that
  field IS reliably numeric in the services cache and is the
  authoritative signal for "is this customer's service suspended".

## v4.21.21 (2026-04-29) — Manual Starlink suspend tooling

### Why
- v4.21.17 confirmed auto-block was silently broken from v4.21.0
  through v4.21.16. Customers UCRM marked as suspended kept their
  internet.
- Manually pausing in dishnet-data-report bypasses
  StarlinkBlockService entirely, so `sl_suspension_state` doesn't get
  a row → `payment.add` webhook calls `restore()` which finds nothing
  → customer stays blocked even after paying.
- Need a way to retroactively block UCRM-suspended-but-not-actually-
  blocked customers via the same code path the webhook uses, so the
  state machine and restore-on-payment work for them.

### What
- Two admin-only endpoints appended to
  `includes/api/api_crm_misc.php`:
  - `GET  ?page=api&action=sl_audit_suspended` — read-only.
    Returns `{totals, rows, config}`. Each row has `client_id, name,
    phone, balance, plans, client_status, is_vip, vip_reason,
    kit_count, kit_serials, already_blocked, blockable`. Sorted
    blockable-first.
  - `POST ?page=api&action=sl_manual_suspend` — body
    `{client_id, mode}`. Loads StarlinkBlockService, calls
    `suspend()` with `triggeredBy='manual:<admin_name>'` and
    `eventType='admin.manual_suspend'`. Same code path as the
    webhook → state row, audit log, retry queue, restore-on-payment
    all work automatically.
- New file at plugin root: `sl_manual_suspend.console.js`. Operator
  pastes into Chrome DevTools Console while on the plugin page.
  Defines `slAudit() / slBlockAll() / slBlockOne() / slConfirm()`
  globals. `slAudit()` is read-only and pretty-prints
  `console.table`. `slBlockAll()` requires explicit
  `slConfirm("STARLINK_PROCEED")` to fire. 2-second delay between
  calls.

### Safety
- Audit endpoint READ-ONLY — no side effects.
- Suspend endpoint admin-only (`$isAdmin` guard).
- VIP guard runs server-side inside `StarlinkBlockService::suspend()`
  even though the audit also pre-checks — defense in depth.
- Idempotent (suspend code skips if client already in suspended
  state, returning `skipped_reason='already_suspended'`).
- Chrome script needs explicit `STARLINK_PROCEED` token to fire.
- `triggered_by_event='admin.manual_suspend'` and
  `suspended_by='manual:<name>'` make manual blocks distinguishable
  from webhook-driven blocks in the audit log.

## v4.21.20 (2026-04-29) — Real client IP capture (was proxy IP)

### What was wrong
- Customer App Logins dashboard (v4.21.19) showed every audit row with
  IP `172.18.251.6` — that's UCRM's internal Docker reverse-proxy IP,
  not the customer's actual IP.
- `REMOTE_ADDR` is always the proxy IP when running behind UCRM's
  reverse proxy. The real client IP arrives in `X-Forwarded-For`
  (chain — first entry is original client) or `X-Real-IP` (single).
- `RetailerAuth::webLogin()` had been doing this correctly for
  staff successful logins all along. Other call sites were naive.

### Fix
- New helper `getClientIp()` in `lib/bootstrap_data.php` (loaded from
  every entry point: public.php, api/index.php, main.php, webhook.php).
- Same fallback chain RetailerAuth uses; comma-handling for forwarded
  chains; idempotent via `if (!function_exists(...))` guard.
- Call sites updated:
  - `ca_audit()` in `includes/api/api_customer_app.php` —
    all customer app audit rows
  - `app_tos_consent` INSERT — legal consent records
  - `includes/post/post_auth.php` — failed staff login logging
    (successful path was already correct via RetailerAuth)
  - `includes/post/post_kyc.php` — KYC edit + refund audit_log
- Existing rows with IP=172.18.251.6 stay as-is — can't reconstruct
  historical IPs. New rows from v4.21.20 onward will be correct.

### Intentionally NOT touched
- `RetailerAuth::webLogin()` — already correct, inline pattern,
  zero risk if left alone.
- `LoginRateLimiter` — rate-limit identifier (kept as REMOTE_ADDR
  is fine for now; would need separate review).
- `EventBus`, other backend audit paths — low value, can sweep later.

### Lesson for SAFETY
- Anywhere you log a "user's IP", use `getClientIp()` not
  `$_SERVER['REMOTE_ADDR']`. The naked `REMOTE_ADDR` will always
  resolve to UCRM's internal proxy IP and is essentially useless
  for forensics, abuse detection, or legal evidence.

## v4.21.19 (2026-04-29) — Customer App Logins admin tab

- Mirrors the staff Access Log idea for the customer-facing app.
- Reads existing `app_audit_log` (written by `ca_audit()` in
  `includes/api/api_customer_app.php` since OTP login was introduced).
  No new schema needed.
- Self-heals `app_audit_log` + creates 3 indexes
  (`idx_app_audit_at`, `idx_app_audit_action_at`, `idx_app_audit_client`)
  for fast dashboard queries on busy installs.
- KPIs: logins today / 7d / 30d, unique users 30d, failures 7d,
  channel mix (phone vs email).
- "Who's Using The App" table per `crm_client_id` with name
  enrichment from `ucrm_clients_cache.json`, channel split, failure
  count, last seen, last IP.
- Anonymous failures (OTP attempts where the lookup failed before
  associating a `crm_client_id`) bucket separately so admin can spot
  abuse patterns.
- "Recent Failures" compact table with friendly reason labels.
- Channel detection: reads `login_mode` from details JSON (v4.21.7+);
  falls back to `@`-in-identifier heuristic for older rows.
- Wired in all three places (apply the v4.21.18 lesson):
  - `$_tabFiles` (router): `'app_logins' => 'tabs/admin/app_logins.php'`
  - `$ALL_MODULES` (permission map): admin-only
  - `includes/navigation.php`: link in Admin section after Access Log

## v4.21.18 (2026-04-29) — Starlink Suspensions nav link

- v4.21.16/17 missed that `$ALL_MODULES` in public.php is NOT what
  renders the side-nav. The actual nav is hand-coded in
  `includes/navigation.php`.
- Added the Starlink Suspensions link in the Admin section, after
  Access Log (line ~414), admin-only.
- Direct URL ?page=dashboard&tab=starlink_suspensions already worked
  in v4.21.17 — this release just makes the nav entry discoverable.

### Lesson for SAFETY
- When adding a tab, three places need updates, not two:
  1. `$_tabFiles` in public.php (router)
  2. `$ALL_MODULES` + `$_tabPerms` in public.php (permission/access map)
  3. **`includes/navigation.php`** (the actual rendered side-nav)
- `$ALL_MODULES` is permission metadata, not nav rendering.

## v4.21.17 (2026-04-29) — Self-heal sl_suspension_state + diagnosis of silent failure

### Critical operational finding
- v4.21.16 surfaced that `sl_suspension_state` and `sl_suspension_log` did
  not exist in production plugin.sqlite3 — despite migration 057 being
  recorded in `_migrations`.
- Implication: every UCRM `service.suspend` webhook since v4.21.0 was
  silently failing the auto-block step. The try/catch at webhook.php:1173
  swallowed "no such table" errors and returned 200 — so customers got
  the suspension WhatsApp, but no devices were ever paused, no SSID
  swapped. Suspended customers stayed online.
- Likely root cause: 057 ran while the DB was already in a partially-bad
  state. MigrationRunner catches "safe" errors and continues
  (lib/MigrationRunner.php:130–133), then marks the file as applied
  regardless of outcome (lines 139–143). 057 is recorded as applied;
  tables don't exist; runner never retries.

### Fix
- tabs/admin/starlink_suspensions.php now self-heals on render.
- Same pattern as duplicate_log.php uses for migration 054.
- `CREATE TABLE IF NOT EXISTS` for both tables with the full v4.21.5
  schema (including block_mode, bypass_event_count, last_bypass_at,
  bypass_alerted_at — from 058+059, which would have errored with
  "no such table" if 057's parent table wasn't there).
- Defensive ALTERs in try/catch handle the case where 057 ran but
  058/059 didn't.
- Green confirmation banner appears once when tables are first created.
- After this tab loads ONCE, future service.suspend webhooks will
  work end-to-end. The webhook code path was never broken — only the
  SQL backing was missing.

### Lesson for SAFETY
- "Migration applied" in `_migrations` ≠ "schema is correct".
- For any future feature that requires a new table, also add a
  defensive CREATE TABLE IF NOT EXISTS at the top of the consuming
  tab/service. Cheap, idempotent, makes the code robust to
  migration-runner edge cases.

## v4.21.16 (2026-04-29) — emergency_repair fix + Starlink Suspensions tab

### emergency_repair endpoint fix
- v4.21.15-and-earlier had the handler at public.php line 128, BEFORE
  `$dataDir = getDataDir(__DIR__)` ran on line 200. Result: PHP warned
  "Undefined variable $dataDir" and the DB path resolved to bare
  '/plugin.sqlite3' which couldn't be opened — masquerading as a real
  corruption error.
- Fixed: handler relocated to AFTER bootstrap_data.php require (now
  lines 126/149).
- Diagnostic output rewritten:
  - Full PRAGMA integrity_check (all rows, not integrity_check(1))
  - Per-table read probe loop — pinpoints exactly which tables fail
  - Auto-generated drop_table URLs per bad table found
- Four explicit actions: diagnose (default, read-only), clear_wal,
  drop_table&t=NAME&confirm=YES_DROP_NAME, vacuum.
- drop_table BLACKLISTS money/customer tables — refuses to touch
  cb_ledger, cb_ssp_register, staff_ledger, staff_expenses,
  wallet_topups, cash_handovers, payment_collections, payments,
  kyc_applications, leads, lte_subscribers, fiber_purchases,
  fiber_invoices, sl_kits, duplicate_confirmations.
- The endpoint is gated by data/emergency_repair_key.txt (default
  'DISHNET_REPAIR'). Drop also requires &confirm=YES_DROP_<name>.

### tabs/admin/starlink_suspensions.php (NEW)
- Admin-only tab giving operational visibility into the v4.21.0+
  Starlink auto-block subsystem.
- List view: KPI counters per state, filter chips, table of all
  rows in sl_suspension_state with customer name (from
  ucrm_clients_cache.json), router_id, KIT, mode (full/pause/bypass),
  retry count, bypass count, last error.
- Detail view (?client_id=N): per-router state breakdown, paused
  MAC lists, pre-existing-paused MACs, last 200 audit log entries
  from sl_suspension_log.
- Force Restore button (admin only, CSRF-protected) calls
  StarlinkBlockService::restore() with triggeredBy='manual:<name>'
  — same idempotent code path as service.unsuspend webhook.
- Defensive: graceful "migration 057 not run" message if tables
  are missing.
- Table is registered in $_tabFiles (key 'starlink_suspensions') and
  in the Admin menu group, between access_log and sync_queue.

## v4.21.7 (2026-04-28) — Email login option for customer portal
- Customer login (?page=customer_login) now has Phone | Email tabs.
- Email mode: looks up CRM client by email (ca_find_clients_by_email),
  sends OTP via SMTP (ca_send_otp_email), same TTL + rate limit as phone.
- Schema unchanged: app_otp_pending.phone column repurposed as generic
  identifier (emails contain @, phones don't, no collision possible).
- Both app_send_otp and app_verify_otp accept either field.
- SMTP uses existing DailyReportService config keys with UCRM fallback.

## v4.21.9 (2026-04-28) — Cashbook running balance: voided exclusion fix
- Two voided statuses exist in cb_ledger: 'voided' and 'voided_reconcile'.
- Dashboard balance always used status='approved' (correct, excludes both).
- CSV/ledger running balance used status != 'voided_reconcile' (WRONG —
  was counting 'voided' rows). Caused $50 drift visible to admins.
- Fixed: getEntries() and getLedger() now use
  status NOT IN ('voided','voided_reconcile') for running-balance math.
- Voided rows REMAIN visible in row list (audit trail preserved).
- buildWhere() unchanged — voided rows still listed.
- api_cron_debug.php hov-entries query unchanged (debug endpoint, not balance).

## v4.21.8 (2026-04-28) — SMTP centralized + diagnostic tab
- New lib/MailService.php — single source of truth for outbound email.
  Reads only UCRM mailer settings (no plugin-level smtp_* config keys).
- New lib/OtpEmailTemplate.php — branded HTML+text OTP email matching
  the design demo. Replaces plain-text v4.21.7 version.
- New tab smtp_diagnostic — admin-only tool to test TCP/TLS/AUTH and
  send real test emails using the OTP template.
- ca_send_otp_email is now a thin wrapper. ~150 LOC of duplicated
  SMTP plumbing removed.

## v4.21.7 (2026-04-28) — Email login alongside WhatsApp
- Customer login (?page=customer_login) gets a Phone/Email tab switcher.
- Phone tab is default (preserves existing flow). Email tab for customers
  whose registered phone doesn't have WhatsApp.
- Email lookup uses client_search_index.json (already has email field).
- OTP via raw SMTP (smtp_* config, falls back to UCRM mailerHost etc).
- app_otp_pending.phone column reused as generic identifier (emails contain
  @, can't collide with phone strings).
- Rate limit 10/hour per identifier — both channels share the limit.
- 'Email not found' error guides customer to Phone tab (option 3).

## v4.21.6 (2026-04-28) — VIP cache freshness fix
- isVipClient() now accepts ?array $freshClient parameter. Webhook
  passes the just-fetched UCRM client object (existing API call,
  zero added latency) so tag check uses live data, not the stale
  daily-refreshed cache.
- suspend() entry point also accepts ?array $freshClient.
- client.edit webhook now refreshes ucrm_clients_cache.json for that
  one client immediately, so other read paths see fresh tags too.
- Net effect: customer tagged NO_AUTO_BLOCK at 9am is protected on the
  next suspend webhook (was previously: ~18 hour stale window until
  next 03:00 cache refresh).

## v4.21.5 (2026-04-28) — Staff-bypass detection
- processExtensionQueue() now distinguishes three classes of unpaused MAC:
  NEW DEVICE (pause), BYPASS (we paused it but staff unpaused via app —
  re-pause + alert), PRE-EXISTING (customer's own pause, leave alone).
- Bypass-event threshold = 3 events. Cooldown = 6h between admin alerts.
- Migration 059 adds bypass_event_count, last_bypass_at, bypass_alerted_at.
- WA template starlink_bypass_alert sends to whatsapp_admin_phone with
  customer name, count, device list. Cooldown prevents admin spam.

## v4.21.4 (2026-04-28) — Pause-only is production default
- New default block_mode = 'pause_only' (was 'full' in v4.21.0–v4.21.3).
- Pause-only skips SSID/password change — customer's existing WiFi password
  keeps working when service is restored. No support call to share new pass.
- Tradeoff: leaky for new devices joining after block. Mitigated by new
  processExtensionQueue() called every 10 min from existing
  cron_starlink_block_retry.php — re-pauses any new MAC on SUSPENDED +
  pause_only rows.
- Migration 058 adds block_mode column. Existing rows default to 'full'
  for backward compat (their original lifecycle completes correctly).
- Override via config: starlink_block_default_mode = 'full' if you want
  the old full-block behavior back.
- restore() only adds creds to WA payload for full-mode rows.

## v4.21.3 (2026-04-28) — Postpone lifecycle handling
- service.postpone webhook now does work (was previously no-op).
- New WA template '⏰ Service Temporarily Restored' explicitly warns
  customer their bill is still pending. Different from the regular
  '✅ Service Restored' (which implies they paid).
- Calls StarlinkBlockService::restore() to unpause devices, same code
  path as service.unsuspend.
- Writes data/recent_postpone_<clientId>.marker (3-min validity) so
  any concurrent service.unsuspend event suppresses its own WA to
  avoid customer getting two contradictory messages.
- Lifecycle is now: Active → Suspended → Postponed → Active again.
  All four states have distinct customer-facing messages.

## v4.21.2 (2026-04-28) — VIP suspension fully silent
- VIP early-skip moved into webhook.php (was only inside StarlinkBlockService).
- NO_AUTO_BLOCK clients now receive ZERO customer-facing signals on
  service.suspend: no WhatsApp, no FCM push, no device block.
- Single private admin WA ('🛡️ VIP Suspension Intercepted') is the only
  signal. Includes client name, CRM ID, service name, outstanding $.
- Internal VIP check inside StarlinkBlockService::suspend() remains as
  defense-in-depth for direct callers (manual override buttons, future API).
- VIP guard failure falls through to standard suspend (non-VIP behavior
  preserved). Logged for review.

## v4.21.1 (2026-04-28) — VIP tag matching ID-first
- isVipClient() now matches UCRM tag by ID first (config: 
  starlink_block_vip_tag_id, default 84), name fallback (default
  'NO_AUTO_BLOCK'). ID match survives rename, name match survives
  delete-and-recreate.

## v4.21.0 (2026-04-28) — Starlink auto-block on suspension
- New module: lib/StarlinkBlockService.php orchestrates per-router
  device pause + SSID/password change when UCRM fires service.suspend.
- Cross-plugin reads: sl_kits.json (Finance) → wifi_router_map.json (Data Report).
- gRPC bridged via loopback HTTP to dishnet-data-report's dr_wifi_* actions.
- New tables (migration 057): sl_suspension_state (state machine + saved
  original credentials + paused MAC list), sl_suspension_log (audit).
- Three webhook hooks: service.suspend (block), service.unsuspend
  (restore + WA enriched with original SSID/password), payment.add
  (instant restore, no wait for UCRM's unsuspend event).
- Cron retry: cron_starlink_block_retry.php every 10 min, 5-attempt cap,
  WA admin alert on exhaustion (state=error_manual_required).
- VIP guard: NO_AUTO_BLOCK tag in UCRM client OR config key
  starlink_block_vip_clients (CSV/array of client IDs) skips block,
  alerts admin instead. Critical for embassy/IOM/UN customers.
- Bypass-mode dishes (is_bypassed=true): live MACs paused but SSID/
  password change skipped (no Starlink WiFi to modify).
- Idempotent: duplicate webhook fires are no-ops once state is
  suspended/restored. State machine prevents partial-block races.

## v4.11.3 — Phase 1 SSP expense fix (2026-03-30)
- ROOT CAUSE: approveExpense() passed raw SSP value (e.g. 30,000) as `amount` to
  addEntryRaw() instead of the USD equivalent, AND never passed `ssp_amount` at all.
  This caused 12 expense_sync cb_ledger rows to show Payment SSP = 0, overstating
  company SSP balance by at least 627,000 SSP (Diko alone, one month).
  quick_approve in staff_cashbooks.php had NO cb_ledger write at all.
- FIX 1 (lib/ExpenseAdvanceService.php): approveExpense() now computes
  _cbAmount = ssp_amount / rate for SSP expenses, passes ssp_amount + ssp_rate.
  SAFETY RULE 8 now enforced: cb_ledger.amount is always USD.
- FIX 2 (tabs/accounts/staff_cashbooks.php): quick_approve now writes to cb_ledger
  via addEntryRaw() (validation_ref='CEXP-{id}', source='expense_sync'). Non-fatal
  try/catch so approval never blocks on cashbook failure.
- BACKFILL (fix_expense_ssp_amounts.php): one-time idempotent script updates
  existing broken rows. Dry run default; &dry_run=0 to apply.
  Action: ?page=api&action=fix_expense_ssp_amounts
- DEPLOYMENT: Run fix_expense_ssp_amounts with dry_run=0 immediately after upload.


- ROOT CAUSE: Cash balances diverged across 5 data sources (payment_collections.json,
  cash_expenses.json, staff_expenses SQLite, cash_handovers.json, cash_advances SQLite,
  staff_transfers SQLite). StaffCashPositionService, SnapshotService, and SQLite VIEW
  all computed slightly different numbers. Caused $45K bug (Bidal), $734 mismatch (Diko),
  $0 vs $210 bug.
- FIX: New `staff_ledger` SQLite table — one row per cash movement, one balance query.
  Balance = SUM(in) - SUM(out) WHERE status NOT IN ('voided','cancelled')
- Migration 045: staff_ledger table with 8 indexes (idempotency, staff+currency, source,
  date, category, CRM payment, balance covering). UNIQUE index on idempotency_key.
- StaffLedgerService.php: record(), voidByKey(), voidBySource(), voidByCrmPayment(),
  balance(), position(), allPositions(), entries(), monthlySummary(), reconcileVsOld()
- StaffLedgerWriter.php: fail-safe dual-write helper — NEVER blocks primary write path.
  Methods: onCollection, onCollectionVoided, onCrmPaymentDeleted, onAdvanceIssued,
  onAdvanceReturn, onAdvanceCancelled, onExpenseApproved, onExpenseVoided,
  onHandoverConfirmed, onHandoverReverted, onTransferCreated, onTransferVoided
- backfill_staff_ledger.php: one-time idempotent import from all 6 sources. Detects
  unified expenses (Phase 3) to avoid double-counting. Transaction-wrapped.
- Dual-write integrated at 12 write points:
  KycService, post_sales, webhook payment.delete, ExpenseAdvanceService (4 methods),
  StaffTransferService (create+void), post_field confirm_handover,
  handover_queue (admin_record + revert), staff_cashbooks (quick_approve + void)
- API: 8 endpoints in api_staff_ledger.php (backfill, balance, position, positions,
  entries, reconcile, summary, stats)
- Idempotency keys: COL-{id}, ADV-{id}, ADVRET-{id}, EXP-{id}, FEXP-{id}, HOV-{id},
  TRFOUT-{id}, TRFIN-{id}
- SAFETY: staff_ledger is financial data — NEVER alter columns. Use new migrations.
  Dual-writes are fail-safe (try/catch, nightly backfill catches gaps).
- DEPLOYMENT: After upload, call API action=staff_ledger_backfill to populate from
  existing data. Then action=staff_ledger_reconcile to verify vs old sources.
  Phase 5 (switch reads) deferred to next session after reconciliation confirms match.
- DUAL-READ MODE (v4.11.3b): DualReadCashPosition replaces StaffCashPositionService at
  all 13 read points. Reads from BOTH staff_ledger AND old JSON/SQLite, compares, logs
  mismatches to data/ledger_mismatches.json. Returns ledger value. Admin sees red banner
  on mismatch. ROLLBACK: set ledger_enabled=false in config → instant revert to JSON.
- SSP FIX: cash_ins.json was missing from backfill (SOURCE 7). Added CIN-{id} idempotency
  keys. DualReadCashPosition::getSSPBalance() replaces 5 broken inline SSP calculations
  that were double-counting (JSON+SQLite) or undercounting (broken ssp_amount). Affects:
  staff_cashbooks hero, staff_cashbooks grid, my_account SSP balance, SSP expense guards.
- PARTIAL HANDOVER: Handover Queue "Record" button now has editable amount field (was
  hidden field locked to full balance). Backend validates amount <= cash position.
- Ledger Health tab: Settings → Ledger Health shows comparison table, mismatch log,
  Rebuild/Reconcile/Toggle buttons. Admin only.
- New idempotency key: CIN-{id} for cash_ins.json entries (SSP Received, Exchange, USD Received)

## v4.11.0 (2026-03-24) — HRM Module Phase 1A
- HRM Module: employee profiles, salary structures, payroll engine, leave management
- Migration 041: 8 new SQLite tables + payroll_ref on cb_ledger
- Personal pay guard: Salary, Transport Allowance, Food Allowance, Bonus, Employee Benefit no longer auto-link to cash_ins.json
- Payroll disbursements auto-post to cb_ledger with source='payroll'
- WhatsApp payslip notifications replace misleading "Cash Received" for salary
- 3 new services: HrmService, PayrollService, LeaveService
- 4 new tabs: HRM Dashboard, Employees, Payroll, Leave Management
- API: 25+ endpoints in api_hrm.php
- SAFETY: HRM tables have financial data — NEVER alter columns in hrm_disbursements or hrm_payroll_lines

## v4.10.3 (2026-03-20) — Fiber Finance Engine (P&L + Leakage + Sync + Import)
- FiberFinanceEngine.php: full P&L engine mirroring Fiber Finance plugin logic
  Revenue - Cost - Partner Share = Profit per plan, 3 profit modes (fixed/revenue_share/profit_share)
  Margin %, true margin (profit/(cost+partner)), revenue at risk, supplier payable
  Configurable status map via config['fiber_status_map']
- Splynx sync: pulls all customers + internet services, caches in SQLite,
  auto-maps customers to CRM by email → phone → name, enriches CRM status
  Status change logging on every transition (prune at 2000), churn analytics
- Leakage: Splynx active + CRM not active = cost exposure. Profit anomaly = reverse
- Auto-fix reconciliation with 30% mass change guard (force override available)
- Event audit log: emitEvent() to rotating file (5000 entries)
- Sync health: healthy (<2h) / stale (<6h) / critical (>6h)
- Profit leak plan flagging: is_leak if profit<0 or margin<threshold
- DATA IMPORT: lib/fiber_data_import.php reads Fiber Finance JSON backup
  Tested: 198 services, 154 customers, 4 plans, 7 invoices, 225 status changes
  Production P&L: $8,425/mo revenue, $5,224/mo cost, $3,202/mo profit (38% margin)
- BACKGROUND CRON: cron_fiber_sync.php registered in master.php
  Splynx sync on configurable interval (default 60min), monthly snapshot on 1st,
  missing invoice alert on 10th, daily leakage + low margin WA alerts
- PERFORMANCE: calculateExpected() uses local SQLite cache (was Splynx API),
  lazy loading per sub-tab, single-pass buildKpiCache on Dashboard,
  getTrend batch queries (2 instead of 12), getDashboardStatsLight for badges
  Result: ~100x faster (5-15s → 10-50ms per page load)
- Migration 039: fiber_services_cache, fiber_customer_map, fiber_sync_log,
  fiber_status_changes, expanded fiber_plan_costs with revenue/partner/profit_mode
- 8 sub-tabs: Dashboard, Finance, Invoices, Reconcile, Leakage, Services, Customers, Plan Costs
- data/fiber_backup/ included with production data for one-click import

## v4.10.2 (2026-03-20) — Fiber Purchasing Reconciliation (All Phases)
- New module: Fiber Costs — supplier invoice tracking + reconciliation
  3 new SQLite tables: fiber_supplier_invoices, fiber_plan_costs, fiber_cost_snapshots
  (migration 038_fiber_purchases.sql)
- FiberPurchaseService.php: invoice CRUD, lifecycle (received→verified→approved→paid→posted),
  expected cost calculation from Splynx active services × plan costs, per-plan line-item
  reconciliation, plan cost history tracking, price change detection, leakage detection,
  monthly snapshots, missing invoice check, post-to-cashbook integration, dashboard stats, trend data
- api_fiber_purchase.php: 20 REST endpoints — invoices, reconciliation, plan costs, trend,
  snapshots, price changes, leakage, missing invoice check, post-to-cashbook
- tabs/accounts/fiber_costs.php: 4 sub-tabs (Dashboard, Invoices, Reconcile, Plan Costs)
  Dashboard: hero cards, alert banners (missing invoice, price changes), 6-month CSS bar chart,
  expected by-plan table, latest invoice summary
  Invoices: record with line items, status workflow buttons, variance column, post-to-cashbook
  Reconcile: period picker, expected vs actual side-by-side, per-plan drill-down with qty/cost diffs
  Plan Costs: CRUD with effective dates, supplier/plan history
- NotificationService: 5 new fiber methods — fiberInvoiceRecorded, fiberVarianceAlert,
  fiberPriceChangeAlert, fiberMissingInvoice, fiberInvoicePosted
- WA triggers: invoice recorded → admin, variance >5% → admin, post-to-cashbook → admin
- Navigation: "Fiber Costs" under Accounts section

## v4.10.1 (2026-03-20) — WhatsApp Notification Bugfixes + Failed Queue
- FIX: cash_declaration.php sendVia() had wrong argument order — sender/phone/message were swapped.
  Cash discrepancy alerts to admin were silently failing (message treated as phone → stripped → empty → skipped).
- FIX: cron_lte_usage.php sendAdmin() had event name as message, actual message buried in array.
  LTE safety guard alert was sending garbled text ("lte_safety_alert") instead of the real warning.
- NEW: Notification Queue — failed WA sends now auto-queue in SQLite (notification_queue table)
  with full message body preserved. Admin can view, retry (single/bulk), dismiss, and purge old items.
  Migration: 037_notification_queue.sql. UI: tabs/engage/failed_queue.php.
  API: notification_queue, notification_retry, notification_retry_bulk, notification_dismiss,
  notification_dismiss_all, notification_purge (all in api_notifications.php).
  sendVia() and sendDocument() both queue failures; retry detects document type and re-sends via
  sendDocument() for PDFs. Retry mode flag prevents re-queueing during retry attempts.

## v4.10.0 (2026-03-20) — Stock Management Phase 1
- New module: unified Stock Management across Starlink, Fiber, LTE, General equipment
- 5 new SQLite tables: stock_categories, stock_units, stock_quantities, stock_movements, stock_purchases
  (migration 036_stock_tables.sql)
- StockService.php: catalog CRUD, unit lifecycle (inbound/checkout/checkin/install/return/transfer/write_off),
  quantity tracking, purchases, dashboard stats, agent holdings, movement log, CSV export
- api_stock.php: 18 REST endpoints with role-based access control
- stock_dashboard.php: admin UI with 6 sub-tabs (Dashboard, Catalog, Inventory, Receive Stock, Movements, Holdings)
- my_equipment.php: field agent PWA view of checked-out items with check-in/install actions
- Seed catalog from existing kyc_devices.json on first run
- Invoice queue filter: only Fiber/Starlink installations notify accountant (not General Work jobs)
- routes.php: added PHP 7.4 polyfills, removed dead api-docs.html route

## v4.9.21 (2026-03-19)
- Cash chain auto-link: all 6 approval paths now create cash_ins.json for receiving staff
  (admin cashbook, API cashbook, approve_expense, approve_staff_payment, batch_approve_staff, quick_approve)
- WhatsApp: staffCashReceived() notification on all 6 paths via ACCOUNTS sender
- Delivery PDF: DeliveryPdfService generates T&C PDF on cash KYC submit, sends via WASender
  (templates/delivery_starlink.html, templates/delivery_fiber.html)
- kycCrmCreated() skips customer welcome when delivery PDF already sent (cash sales)
- serve_delivery_pdf endpoint for permanent HMAC-protected legal document serving
- Staff Cashbooks: SSP balance on tiles, list view toggle, filter (with balance), search, totals bar
- SSP hero card for support roles (support_leader, support) — shows SSP as primary balance
- Receipt photo link added to Diko's wallet ledger entries
- NotificationService: new staffCashReceived() method
- SSP Overview: new read-only tab (tabs/accounts/ssp_overview.php) — company-wide SSP position
  dashboard showing: summary cards (purchased/spent/circulation/pool/rate), per-person breakdown
  table with balance + % of total, chronological movement log with type filters, auto-link
  mismatch warnings. Registered in public.php + navigation.php.

## v4.9.20 (2026-03-19)
- Multi-contact KYC registration (up to 5 contacts per customer)
- PDF kill-switch: wa_send_pdf config flag disables all 6 PDF-via-WhatsApp flows
- SAFETY.md + MANIFEST.md guardrail system added

## v4.9.19 (2026-03-19)
- Currency separation: SSP excluded from USD running balance
- Separate CSV export per currency
- Dual-currency All view
