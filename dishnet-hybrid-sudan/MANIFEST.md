# DishNet Hybrid Telecom — Code Map (MANIFEST.md)
# ══════════════════════════════════════════════════
# READ THIS FIRST in every session. Updated: 2026-03-18 v4.9.16
#
# This file maps every file, every data flow, and every gotcha.
# Claude: read this before editing anything.
# Updated: 2026-04-28 v4.21.2

## Architecture Overview

```
public.php (router + shell)  ← UCRM serves this
  ├── includes/api_handlers.php  ← API router (114 lines) → includes/api/*.php
  │   ├── PRE-AUTH (4 files — no Bearer/session needed):
  │   │   api_public.php, api_payments_admin.php,
  │   │   api_products_admin.php, api_cron_debug.php
  │   │   ─── AUTH GUARD (Bearer token / session) ───
  │   └── POST-AUTH (13 files):
  │       api_retailer.php, api_scheduling.php, api_splynx.php,
  │       api_crm_misc.php, api_lte.php, api_whatsapp.php,
  │       api_support.php, api_field_ops.php, api_leads.php,
  │       api_cashbook.php, api_crm_sync.php, api_lte_admin.php,
  │       api_notifications.php
  │
  ├── includes/post_handlers.php ← POST router (250 lines) → includes/post/*.php
  │   post_auth.php, post_kyc.php, post_sales.php, post_admin.php,
  │   post_sync.php, post_leads.php, post_cashbook.php, post_field.php
  │
  ├── tabs/accounts/*.php        ← Accountant screens
  ├── tabs/sales/*.php           ← Sales agent screens
  ├── tabs/admin/*.php           ← Admin screens
  ├── tabs/support/*.php         ← Support screens
  ├── tabs/engage/*.php          ← WhatsApp/comms screens
  └── tabs/lte/*.php             ← LTE module screens

webhook.php     ← UCRM event webhooks (payment.add, service.suspend, etc.)
wa_webhook.php  ← WhatsML incoming message webhook
evo_webhook.php ← Evolution API incoming message webhook
main.php        ← UCRM cron entry → dispatches to cron/master.php
```

## Entry Points

| URL | File | Purpose |
|-----|------|---------|
| `public.php?page=dashboard&tab=X` | public.php → tabs/ | Main UI |
| `public.php?page=api&action=X` | public.php → api_handlers.php | REST API |
| `public.php?page=login` | public.php | Login form |
| `webhook.php` | webhook.php | UCRM event hooks |
| `wa_webhook.php` | wa_webhook.php | WhatsML incoming |
| `evo_webhook.php` | evo_webhook.php | Evolution API incoming |
| `main.php` | main.php → cron/master.php | Background jobs |

## Data Storage

### SQLite Tables (via lib/SqliteStore.php → data/plugin.sqlite3)
| Table | Service | Purpose |
|-------|---------|---------|
| `cb_ledger` | CashbookService | Cashbook entries (direction, amount, category, person) |
| `cash_advances` | ExpenseAdvanceService | Staff cash advances |
| `staff_expenses` | ExpenseAdvanceService | Staff expense claims |
| `staff_transfers` | StaffTransferService | Inter-staff cash transfers |
| `wa_conversations` | ConversationService | WhatsApp conversation threads |
| `wa_messages` | ConversationService | WhatsApp message history |
| `staff_cash_snapshots` | SnapshotService | Pre-computed field cash positions |
| `hrm_employees` | HrmService | Employee HR profiles (extends retailers.json) |
| `hrm_salary_structures` | HrmService | Per-employee salary components with history |
| `hrm_payroll_periods` | PayrollService | Monthly payroll cycles (draft→calculated→approved→closed) |
| `hrm_payroll_lines` | PayrollService | Per-employee payroll for a period |
| `hrm_disbursements` | PayrollService | Individual salary payments (supports partial) |
| `hrm_leave_types` | LeaveService | Configurable leave categories (7 defaults) |
| `hrm_leave_balances` | LeaveService | Per-employee per-year leave balances |
| `hrm_leave_requests` | LeaveService | Leave applications with approval flow |
| `staff_ledger` | StaffLedgerService | Unified staff cash ledger — single source of truth for all cash movements |

### JSON Files (via SqliteStore virtual tables → backed by JSON)
| File | Purpose | Key Fields |
|------|---------|------------|
| `retailers.json` | Staff/agent accounts | id, name, role, phone, is_active |
| `passbook.json` | Wallet transactions | retailer_id, type(credit/debit), amount |
| `kyc_applications.json` | KYC registrations | crm_client_id, status, firstname, lastname |
| `payment_collections.json` | Cash collections | retailer_id, amount, crm_payment_id, status |
| `cash_handovers.json` | Handovers to Rupesh | from_id, amount, status(pending/confirmed) |
| `cash_expenses.json` | Legacy expenses | collector_id, amount, status, currency |
| `cash_ins.json` | SSP received / exchange | collector_id, ssp_amount, category |
| `kyc_config.json` | Plugin configuration | CRM creds, WA keys, feature flags |
| `subscription_plans.json` | Service packages | name, customer_price, type |
| `kyc_devices.json` | Hardware inventory | title, price, ucrm_product_id |

## Key Data Flows

### Flow 1: Customer Registration (KYC)
```
Agent taps Submit
  → post_handlers.php (action=kyc_submit)
  → KycService::process()
    → handleNew() or handleExisting()
    → POST /clients to UCRM CRM API
    → Generate quote (POST /clients/{id}/quotes)
    → Auto-create payment (POST /payments) if cash sale
    → Debit agent wallet (WalletService::debit)
    → Append to payment_collections.json
    → SnapshotService::rebuild(agentId)
    → WhatsApp notification (quote PDF)
    → Save to kyc_applications.json
```

### Flow 1b: Additional Service for Existing Customer (v4.20.2+)
```
Agent enters CRM ID in KYC form, picks plan + new site address
  → post_handlers.php (action=kyc_submit) OR api/index.php (POST /v1/kyc)
  → KycService::process()
    → customer_id non-empty → handleExisting()
      → POST /clients/{crmId}/quotes (UCRM quote on EXISTING client)
      → createCrmJobForBidal()
        → POST /scheduling/jobs with descriptive title:
          "Additional Service — {name} ({crmId}) — New Site: {area}"
          + assignedUserId = Bidal (support_leader.ucrm_user_id)
          + clientId       = existing CRM client
          + address/gpsLat/gpsLon from form
          + status = Open, scheduled tomorrow 09:00
        → POST /scheduling/jobs/{id}/job-tasks (checklist)
      → Wallet debit (Cash sales only)
      → returns data with is_additional_service=true, crm_job_id
  → post_kyc.php / api/index.php branches on is_additional_service:
    → NotificationService::kycAdditionalService($retailer, $app)
      → WhatsApp to Bidal  (full operational brief, support_leader phone)
      → WhatsApp to admin  (visibility)
      → WhatsApp to agent  (short confirmation)
    → SKIP kycSubmitted() and kycCrmCreated() (would mislabel as "New Registration")

Key flags persisted on kyc_applications.json:
  is_additional_service=true, crm_client_type='existing',
  new_service_address, crm_job_id, crm_job_created_at

Failure modes (all non-fatal):
  Quote fails → app saved with quote_error, agent told to escalate manually
  CRM job fails → quote stands, error_log only, no crm_job_id persisted
  Bidal phone missing → admin still notified; falls back to bidal_ucrm_user_id
                        config key for job assignment, then unassigned
```

### Flow 2: Cash Collection
```
Agent taps Collect Payment
  → post_handlers.php (action=collect_payment)
  → POST /payments to UCRM CRM API
  → Append to payment_collections.json (appendWithId → writes to disk)
  → SnapshotService::rebuild(agentId) ← reads from disk, must be AFTER save
  → WhatsApp receipt to customer
```

### Flow 3: Cash Handover
```
Agent submits handover
  → post_handlers.php (action=submit_handover)
  → Append to cash_handovers.json (status=pending)

Rupesh confirms handover
  → post_handlers.php (action=confirm_handover)
  → Set status=confirmed IN MEMORY
  → wallet->credit(agent) — replenishes agent float
  → ⚠️ MUST save JSON BEFORE rebuild (race condition fix v4.8.97)
  → $store->save('cash_handovers.json')
  → SnapshotService::rebuild(from_id) — reduces agent exposure
  → ⚠️ NO cashbook entry (v4.9.8+) — revenue already in cb_ledger via webhook
  → PATCH UCRM payment notes
  → WhatsApp notification to agent

Admin reverts confirmed handover (v4.9.10)
  → handover_queue.php (action=revert_handover) — admin only
  → wallet->debit(agent) — claws back the credit (idempotency: REVHOV-{id})
  → Unlink collections (clear handover_id/handover_by/handover_at)
  → Set status=reverted + revert_reason
  → Save JSON BEFORE rebuild
  → SnapshotService::rebuild(from_id) — agent exposure goes back up
  → WhatsApp notification to agent
```

### Flow 4: Cashbook Entry
```
Rupesh taps Save in modal
  → post_handlers.php (cb_action=add_entry)
  → Reads: amount, currency, ssp_rate, category, person, description,
           validation_ref, validation_status, inv_ref, rcpt_ref, pay_month
  → For SSP: usdAmount = rawAmount / sspRate
  → inv_ref → merged into validation_ref as "INV:xxx"
  → pay_month → appended to description as "[2026-03]"
  → CashbookService::addEntry()
  → INSERT into cb_ledger (18 columns, 18 placeholders)
```

### Flow 5: CRM Payment Deleted
```
UCRM fires payment.delete webhook
  → webhook.php case 'payment.delete'
  → Find cb_ledger entry by crm_payment_id → post Cash OUT reversal
  → Find payment_collections entry → set status=voided, ev=0
  → SnapshotService::rebuild(agentId)
  → WhatsApp alert to Rupesh
```

### Flow 6: Field Cash Snapshot
```
SnapshotService::computeFromSource(agentId):
  exposure = advance_balance
           + collections (ev=1 only, excludes voided)
           - approved_expenses
           - confirmed_handovers
           - transfers_sent
           + transfers_received
```

### Flow 7: Payroll Disbursement (v4.11.0)
```
Admin clicks "Pay" on Payroll tab
  → PayrollService::disburse($lineId, $amount, $opts)
    → INSERT hrm_disbursements (tracks partial payments)
    → CashbookService::addEntryRaw() with source='payroll', payroll_ref='PR-YYYY-MM'
    → UPDATE hrm_payroll_lines (total_disbursed, balance_due, status)
    → UPDATE hrm_payroll_periods (total_disbursed aggregate)
    → WhatsApp: payrollDisbursement() — proper salary notification
    → Does NOT create cash_ins.json (personal pay guard)
    → Does NOT inflate field register balance
```

### Flow 8: Leave Request (v4.11.0)
```
Staff submits leave via PWA or admin tab
  → LeaveService::submitRequest()
    → Check leave balance (sufficient days?)
    → Check overlapping requests
    → INSERT hrm_leave_requests (status=pending)
    → Update hrm_leave_balances (pending += days)
    → WhatsApp: notify admin of pending request

Admin approves/rejects
  → LeaveService::approveRequest() or rejectRequest()
    → Recalculate balance (taken/available)
    → WhatsApp: leaveApproved() or leaveRejected() to staff
```

### Flow 9: Staff Ledger Dual-Write (v4.11.3)
```
Any cash movement affecting a field agent:
  → Primary write (JSON append / SQLite INSERT / status update)
  → StaffLedgerWriter::onXxx($pdo, $record)
    → StaffLedgerService::record() with idempotency_key
    → INSERT OR IGNORE into staff_ledger (UNIQUE key prevents duplicates)
    → If ledger write fails: error_log only, primary write is NOT blocked
    → Nightly backfill_staff_ledger.php catches any missed entries

Balance query (replaces 5 sources):
  → StaffLedgerService::balance($staffId, 'USD')
  → SELECT SUM(CASE direction) FROM staff_ledger
      WHERE staff_id=? AND currency=? AND status NOT IN ('voided','cancelled')
```

### Flow 10: SSP Imprest Model (v4.20.3) — see SAFETY Rule 16
```
Step 1: Rupesh issues SSP advance to staff (e.g. 600,000 SSP to Aida)
  → post_cashbook.php (cb_action=add_entry, category="SSP Advance")
    → CashbookService::addEntry()       → cb_ledger OUT (CB-2768)   ← physical cash leaves
    → SspAdvanceService::registerAdvanceIssue() → cb_ssp_register IN  ← Aida's imprest balance
    → cash_ins.json + StaffLedgerWriter::onCashIn  ← staff field-register sync
    → WhatsApp staffCashReceived()      ← notify Aida

Step 2: Field staff submits SSP expense (with advance_id link)
  → submitExpense() → INSERT staff_expenses (status=pending, advance_id=N)
  → If autoApprove (field_accountant) → approveExpense()

Step 3: Expense approval (THE FIX)
  → ExpenseAdvanceService::approveExpense()
    → IF currency='SSP' AND advance_id > 0:    ← imprest gate
        cb_ledger:                NOT touched   (cash already left at Step 1)
        cashbook_entry_id = -1    (sentinel: imprest-suppressed)
    → ELSE (USD, or free-standing SSP):
        cb_ledger OUT (source='expense_sync')   ← reimbursement / direct pay
    → ALWAYS: UPDATE cash_advances.amount_spent
    → ALWAYS: SnapshotService::rebuild()
    → ALWAYS: StaffLedgerWriter::onExpenseApproved()
  → SspAdvanceService::mergeExpenseToLedger()
    → Flips cb_ssp_register.merged_to_cb=1, status='merged'
    → NEVER writes to cb_ledger (v4.20.3+)

Step 4: Staff returns leftover SSP (if any)
  → SspAdvanceService::recordReturn()
    → cb_ssp_register OUT (source='return')
    → cb_ledger IN  ← genuine cash returning to till, MUST post

cb_ledger source taxonomy (after v4.20.3):
  manual            — Rupesh-typed entries (incl. SSP Advance issues)
  expense_sync      — non-imprest expense reimbursements (USD, or SSP w/o advance)
  field_merge       — DEPRECATED, no new rows post-v4.20.3 (legacy data preserved)
  payroll           — salary / allowance disbursements
  exchange / EXCH-* — currency exchange dual-entries
  webhook           — UCRM payment webhook
  cron / sync       — nightly reconcile / CRM payment gap fill
```

### Flow 11: SSP Imprest Reporting (v4.20.4)
```
Read-only reports over the v4.20.3 imprest data model.
Service: lib/SspImprestReportService.php (no writes, no schema changes)
Tab:     tabs/accounts/ssp_imprest.php (three sub-views, ?ssp_view=...)

Q1. "How much SSP does the company hold right now?"
  → companyTotals()
    → main_till_balance  = SUM(cb_ledger.ssp_amount IN-OUT, currency=SSP, !voided)
    → in_imprest         = SUM(staff_ledger ssp_amount IN-OUT, currency=SSP, !voided/cancelled, per-staff > 0)
    → total_company_ssp  = main_till + in_imprest
    → today flow: advances_issued, direct_expenses, imprest_expenses, returns_received

Q2. "Who is holding company SSP and is anyone sitting on it too long?"
  → imprestHolders()
    → Per-staff aggregate from staff_ledger
    → Status: fresh (<14d) | stale (14-30d) | overdue (>30d) | overdrawn (<0) | zero
    → Drill-down: holderHistory(staffId) for forensic audit

Q3. "How much did the company spend on each category this period?"
  → pAndLByCategory(from, to)
    → Imprest channel: SUM(staff_expenses.ssp_amount, status=approved, currency=SSP)
    → Direct channel:  SUM(cb_ledger.ssp_amount, OUT, expense categories, !imprest issue)
    → Both channels merged by canonical label (e.g. 'Travel & Field' + 'fuel' → 'Fuel & Transport')
    → Total column = the figure to post to Tally for each category
```

### Flow: Starlink auto-block on suspension (v4.21.0+, refined v4.21.2)

```
UCRM service.suspend → webhook.php case 'service.suspend':
  1. Build name/phone, dedup-skip if recent payment / no balance
  2. v4.21.2: VIP early-skip — if isVipClient(): admin WA alert ONLY,
     return 200. No customer WA. No FCM. No device block. Customer
     experiences nothing — UCRM still shows them suspended internally.
  3. If not VIP: existing 🚫 Service Suspended WA + FCM push
  4. v4.21.0: $blockSvc->suspend(clientId, ...) — orchestration
     (which ALSO checks isVipClient() internally as defense-in-depth
     for any future direct callers; webhook path will already have
     returned by here for VIPs).
       → isVipClient(clientId)? → if yes: log skip_vip + WA admin alert + return
       → resolveClientRouters(clientId):
           clientId → ../dishnet-starlink-finance/data/sl_kits.json (KIT serials)
           kit_serial → ../dishnet-data-report/data/wifi_router_map.json
                        (router_id, account_number, is_bypassed)
       → for each router:
           - INSERT into sl_suspension_state, state='suspending'
           - drGetWifiConfig(routerId) → save original SSID + password
                (skipped if is_bypassed)
           - drGetWifiStatus(routerId) → list connected MACs, separate
                pre_existing_paused (don't unpause on restore) from live
           - drPauseClient(routerId, mac, fingerprint) for each live MAC
           - drChangePassword(routerId, 'DishNet-PAY-NOW',
                              'DishNet-Suspended-NNNN')
                (skipped if is_bypassed)
           - state='suspended' if all OK, else 'partial_suspend_failed'
       → returns {ok, routers_processed, routers_failed}
  5. whResp(200)

UCRM service.unsuspend / service.activate → webhook.php:
  1. Build phone, check recent_payment_*.marker dedup
  2. NEW: $blockSvc->restore(clientId, 'webhook')
       → for each row in sl_suspension_state for this client:
           - state='restoring'
           - drChangePassword(router, original_ssid_24, original_pass_24)
           - drUnpauseClient for each MAC in paused_macs_json
                (skip MACs in pre_existing_paused_json)
           - DELETE the state row on success
       → returns {ok, routers_restored, restored_credentials: [...]}
  3. Existing WA send, NOW enriched with restored_credentials so customer
     gets their original SSID + password to copy-paste

UCRM service.postpone → webhook.php (v4.21.3+):
  1. Resolve client_id, name, phone, outstanding balance, postpone-to date
  2. $blockSvc->restore(clientId, 'service.postpone') — unpause devices
  3. Write data/recent_postpone_<clientId>.marker (3-min validity)
     → suppresses any concurrent service.unsuspend WA so customer
       doesn't get a wrong "back online" message
  4. Send '⏰ Service Temporarily Restored' WA: explicitly says bill is
     still pending, includes outstanding $, includes postpone deadline
     if UCRM provides one (servicePostponedTo field varies by version)
  5. FCM push so customer sees it in the app too

UCRM payment.add → webhook.php:
  1. Existing payment processing
  2. NEW: $blockSvc->restore(clientId, 'payment.add')
       → idempotent: no-op if no sl_suspension_state row
       → instant restore — doesn't wait for UCRM's service.unsuspend
         which may take minutes or never fire (manual suspensions)

cron_starlink_block_retry.php (every 10 min via master.php):
  → processRetryQueue():
      SELECT rows where state IN ('suspending','partial_suspend_failed','restoring')
        AND last_attempt_at < now-5min AND attempt_count < 5
      → resume from last checkpoint
      → after 5 attempts: state='error_manual_required' + WA admin alert
```

**External plugin dependencies (read-only):**
- `dishnet-starlink-finance/data/sl_kits.json` — must contain client_id + kit_serial
- `dishnet-data-report/data/wifi_router_map.json` — must contain kit_serial + router_id_full

**Loopback HTTP target:** `dishnet-data-report/public.php?action=dr_wifi_*` (no auth required for these actions when called without UCRM session cookies — verified in dr_wifi_change.php)



1. **NEVER conflate status fields**: `status` (approved/rejected) vs `validation_status` (pending/voucher/done)
2. **JSON save BEFORE rebuild**: Always save JSON files to disk before calling SnapshotService::rebuild() — it reads from disk, not memory
3. **cb_ledger.amount is always USD**: SSP amounts are converted at entry time. Original SSP stored in ssp_amount/ssp_rate columns.
4. **PHP 7.4 hard constraints**: No match(), no named arguments, no str_starts_with/str_contains/str_ends_with — use polyfills
5. **SqliteStore**: Always use $store->query(), never $store->fetchAll(). Instantiate via SqliteStore::create($dataDir)
6. **manifest.json version must be string "1"** — not integer, not the plugin version
7. **Collections with status=voided have ev=0** — excluded from snapshot, field register, CSV export
8. **Role check === 'support_leader'** gates special access in several flows
9. **ZIP build**: must be from inside plugin folder: `cd dishnet-hybrid-telecom && zip -r ../v4.x.x.zip .`
10. **Cashbook dedup format is PAY-{crmId}** (v4.9.10): webhook + nightly sync both use `PAY-{id}` as `validation_ref`. Nightly sync checks `source IN ('crm_sync','crm_api_sync','collect_payment','crm_webhook')`. Never use the old `CRM-PAY-{id}` format.
11. **Handover does NOT touch cashbook** (v4.9.8+): Revenue is posted to cb_ledger by webhook/nightly sync. Handover only updates wallet + snapshot. confirm_handover and admin_record_handover must NEVER addEntry to cb_ledger.
12. **Cashbook writes need `validation_ref`** (v4.9.10): Use `addEntryRaw` with `validation_ref` (not `reference` — that key is silently ignored). Always dedup-check before insert: `SELECT id FROM cb_ledger WHERE validation_ref = ? LIMIT 1`.
13. **Personal pay categories NEVER auto-link** (v4.11.0): Salary, Transport Allowance, Food Allowance, Bonus, Employee Benefit are `$personalCats` — they MUST NOT create cash_ins.json entries. This guard exists in post_cashbook.php (2 paths) and post_field.php (3 paths). If adding new auto-link logic, check `$personalCats` first.
14. **HRM disbursements are financial records** (v4.11.0): NEVER ALTER columns in hrm_disbursements or hrm_payroll_lines. Use new migrations for new columns. No hard deletes — use status='cancelled'.
13. **Handover statuses**: pending → confirmed / rejected / reverted. Revert is admin-only, debits wallet, unlinks collections.
15. **Staff ledger idempotency keys** (v4.11.3): COL-{id}, ADV-{id}, ADVRET-{id}, EXP-{id}, FEXP-{id}, HOV-{id}, TRFOUT-{id}, TRFIN-{id}. NEVER reuse a prefix for a different flow. UNIQUE index enforces dedup.
16. **Staff ledger dual-writes are fail-safe** (v4.11.3): StaffLedgerWriter wraps every call in try/catch. If ledger INSERT fails, the primary write path (JSON/SQLite) continues unaffected. backfill_staff_ledger.php re-run catches gaps. NEVER make dual-writes blocking.

## Roles & Permissions

| Role | Modules |
|------|---------|
| admin | Everything |
| accountant | Cashbook, Handover Queue, Expense Approvals, Settlement, Wallet Admin |
| support_leader | Ops Hub, Scheduling, Dispatch, Support Dashboard |
| sales | Collect Payment, KYC Form, Field Register, Leads |
| field_agent | Collect Payment, KYC, Field Register, Give Advance |
| field_accountant | Collect Payment, KYC, Field Register, Staff Salary/Advance |
| collection | Collect Payment, Field Register |

## Services (lib/)

| Class | File | Purpose |
|-------|------|---------|
| CashbookService | lib/CashbookService.php | cb_ledger CRUD, balance, summary, staff position |
| KycService | lib/KycService.php | Customer registration, CRM sync, quotes, payments |
| WalletService | lib/WalletService.php | Wallet balance, credit, debit |
| RetailerAuth | lib/RetailerAuth.php | Login, session, role check |
| NotificationService | lib/NotificationService.php | WhatsApp via WASender/Evolution |
| SnapshotService | lib/SnapshotService.php | Pre-computed field cash positions |
| ConversationService | lib/ConversationService.php | WhatsApp inbox threads |
| ExpenseAdvanceService | lib/ExpenseAdvanceService.php | Advances, expenses, approvals |
| StaffTransferService | lib/StaffTransferService.php | Inter-staff transfers |
| CrmApiClient | lib/CrmApiClient.php | UCRM REST API wrapper |
| QuotationService | lib/QuotationService.php | Quote generation + WA sending |
| StaffLedgerService | lib/StaffLedgerService.php | Unified staff cash ledger — one balance query replaces 5 sources |
| StaffLedgerWriter | lib/StaffLedgerWriter.php | Fail-safe dual-write helper for staff_ledger |
| StarlinkBlockService | lib/StarlinkBlockService.php | Auto-block Starlink devices on UCRM service.suspend (v4.21.0+). Reads sl_kits.json + wifi_router_map.json, calls dishnet-data-report's gRPC bridge over loopback HTTP. State in sl_suspension_state, audit in sl_suspension_log. VIP guard via NO_AUTO_BLOCK tag or starlink_block_vip_clients config. |

## External APIs

| API | Base URL | Auth | Used For |
|-----|----------|------|----------|
| UCRM/UISP | /api/v2.1 or /crm/api/v2.1 | x-auth-token or X-Auth-App-Key | Clients, invoices, payments, quotes, jobs |
| WhatsML (WASender) | wa.dishnetafrica.com | app_key + auth_key | Send WhatsApp messages |
| Evolution API | (configurable) | Bearer token | Alternative WhatsApp channel |
| OpenStreetMap Nominatim | nominatim.openstreetmap.org | User-Agent header | GPS reverse geocoding |
| Magma Orc8r | api.dishnetss.com:9443 | mTLS certificates | LTE subscriber management |

## WhatsApp Channels

| Channel | Phone | app_key | Session ID |
|---------|-------|---------|------------|
| Support | 211921443002 | 2313..186b | 7cc8d42a..42cfc |
| Accounts | 211921443009 | 6b93..acb5 | 1d327e8a..0ef1e |
| Auth key (shared) | — | OCshy4xuvHETHyAI6YvY6YikqPwdpsvM2 | — |

## Admin Tooling (v4.21.16+)

| Surface | URL | Purpose |
|---------|-----|---------|
| Emergency Repair | `public.php?page=emergency_repair&key=<KEY>` | Pre-bootstrap diagnostic + repair endpoint. Plain text. Runs after $dataDir is set but before SqliteStore::create() — usable when the plugin is broken. Actions: `diagnose` (default, read-only integrity_check + per-table probe), `clear_wal` (delete stale -wal/-shm), `drop_table&t=NAME&confirm=YES_DROP_NAME` (destructive, with hardcoded blacklist of money/customer tables), `vacuum`. Key in `data/emergency_repair_key.txt`. |
| Starlink Suspensions tab | `?page=dashboard&tab=starlink_suspensions` | Admin-only view of sl_suspension_state + sl_suspension_log. Force-restore button calls StarlinkBlockService::restore() with `triggeredBy='manual:<name>'`. CSRF-protected. |

## Recent Changes (v4.21.79 → v4.21.82) — 2026-05-04

### Root Cause: UISP API v1.0 → v2.1 upgrade (v4.21.82)
Around 2026-04-23, the UISP server upgraded its REST API from v1.0 to v2.1.
The PDF endpoint (/payments/{id}/pdf) only works on v2.1. Several other endpoints
also stopped returning data on v1.0 (clients, etc.). This silently broke:
- New registration WA (client.add webhook fetched client via v1.0 → null → no phone)
- Job accepted WA (same pattern)
- USD collection WA (same)
- USD handover WA (same)
- Receipt PDF cron (cron_quote_wa.php built URL with v1.0 → 404 on PDF fetch)

**Files changed:**
- `lib/CrmApiClient.php` — fromUcrm() changed /api/v1.0 → /api/v2.1
- `main.php` — ucrmLocalUrl base changed /api/v1.0 → /api/v2.1
- `includes/routes.php` — crm_base_url display changed /api/v1.0 → /api/v2.1
- `cron_quote_wa.php` — receipt PDF URL explicitly strips and reattaches /api/v2.1;
  v1.0 fallback kept for backward compat with older installs

**External APIs table updated:** UCRM/UISP now /api/v2.1

### Cron budget fix (v4.21.81)
- `cron/master.php` — quote_wa moved BEFORE evo_sync (evo_sync is heavy/120s,
  was consuming budget before queue_wa could fire)
- `cron/master.php` — default master_budget_seconds 240 → 300

### App Sites plan name fix (v4.21.79)
- `tabs/customer_app/portal_data.php` line 563:
  `'plan' => $portalPlanName ?: ($kit['plan_name'] ?? '')`
  Was: `$kit['plan_name']` (Starlink internal: "Residential Lite")
  Now: UCRM billing plan name ("Starlink Residential : Period") with sl_kits fallback

### manifest.json version bump rule (IMPORTANT)
manifest.json top-level "version" MUST always be "1" (string) per RULE 6.
Only information.version holds the plugin version (e.g. "4.21.82").
UISP uses information.version to detect updates. If not bumped, UISP
silently ignores the upload and keeps running old code.

### UISP Execution Period (operational note)
Plugin execution period is a per-server UISP setting — NOT controlled by manifest.
If it shows "don't execute automatically", go to CRM → Plugins → DishNet Hybrid Telecom
→ Settings → set Execution Period to 5 minutes. Without this, master.php never fires
and no background jobs run (receipts, cron, etc.).
