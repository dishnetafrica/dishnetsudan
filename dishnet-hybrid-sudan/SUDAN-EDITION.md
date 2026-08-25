# DishNet Hybrid — Sudan Edition

The complete Hybrid platform, data-free, with the AI WhatsApp brain built in.

Built from the South Sudan plugin v4.21.116. Verified against uCRM 4.5.33,
UISP 3.0.159, PHP 8.1.34.

## Module checklist

Every module in the mature plugin, and what happened to it.

| Module | Screens | Status |
| --- | --- | --- |
| Sales / KYC / leads / quotes / wallet | 19 | **Preserved** |
| Accounts / cashbook / ledger / collections | 30 | **Preserved** |
| Admin / settings / roles / stock / backup | 28 + 2 new | **Preserved + added** |
| Support / field ops / scheduling / NOC | 15 | **Preserved** |
| LTE / BlueCard / retailer portal | 7 | **Preserved** |
| HRM / payroll / leave | 4 | **Preserved** |
| Customer portal / end-user PWA | 4 | **Preserved** |
| Engage / WhatsApp inbox / lifecycle | 4 + 1 new | **Preserved + added** |
| Help / knowledge base / training | 4 | **Preserved** |
| **Total UI screens** | **115 → 117** | nothing removed |

| Layer | Before | After | Status |
| --- | --- | --- | --- |
| `lib/` services | 82 | 88 | Preserved, 6 added |
| `includes/api/` modules | 22 | 22 | Preserved |
| API entry points | 6 | 6 | Preserved (one replaced, below) |
| Migrations | 61 | 62 | Preserved, 1 added |
| Cron jobs | 35 | 35 | Preserved, 1 disabled, 1 added |
| Workers | 13 | 14 | Preserved, 1 added |
| Splynx / Starlink / Magma integrations | all | all | Preserved |
| RBAC, JWT, retailer auth, idempotency | all | all | Preserved |

**Nothing was removed from the functional surface.** Every removal below is
data, a credential, or a spent one-off script.

## Removed — data, not software

| Removed | Why |
| --- | --- |
| `bk_employee_seed.json` | 904 South Sudan accounting records: employee names, amounts, vouchers, debit/credit accounts |
| Auto-import in `hrm_dashboard.php` | Imported those 904 records into an empty `hrm_financial_history` the first time anyone **opened the page**. Nobody chose it. `HrmService::seedFromBakedJson()` is retained — the manual re-import button still works |
| Seeded catalogues in `post_handlers.php` | Real commercial data: supplier names (4G Telecom), cost prices, margins, and live subscriber counts (`active_subs: 69, 35, 8, 3`) |
| 14 `fix_*` / `backfill_*` / `migrate_*` scripts | One-off repairs for specific South Sudan incidents — named after individuals, a specific voucher, a specific exchange rate. Already applied there; running them on Sudan data would corrupt it |

## Removed — credentials

The South Sudan build created **four accounts** in `post_handlers.php` with
their passwords written into the file. uCRM serves `public.php` without
authentication, so a password in source is a password on the internet. One
branch also **reset those passwords back to the known values** whenever an
administrator changed them.

All four are gone. There is no default password in this edition.

## Added

| Added | What it does |
| --- | --- |
| First-run setup | `public.php` creates the first administrator. Gated on your UISP session — uCRM does not authenticate plugin pages, so "whoever arrives first" would let a stranger claim the install |
| `tabs/admin/system_health.php` | One page: database, uCRM, worker, scheduler, AI provider, reply queue, Evolution, and each WhatsApp number. Reports the real error and names the likely cause — TLS, DNS, timeout, authentication, endpoint — rather than "could not connect" |
| `tabs/engage/wa_ai_setup.php` | Instance assignment from a live Evolution list, **QR pairing inside the plugin**, webhook registration, and the answering on/off switch |
| `lib/DishNetAiBrain.php` | The conversational brain. Grounding rules ported from ShopBot: never invent a price, never accept a customer's proposed price, advise on the need rather than wait for a product name |
| `lib/DishNetTools.php` | Business tools over uCRM — customer, services, account, invoices, products, line status. Read-only |
| `lib/EvolutionApiService.php` | Multi-instance Evolution adapter with channel↔instance mapping, QR, webhooks |
| `lib/EvoWebhookGuard.php` | Webhook authentication, replay window, idempotent claim |
| `lib/UcrmUser.php` | Identifies the signed-in UISP user, so setup needs no separate login |
| `workers/AiReplyWorker.php` | Builds role-scoped context, calls the brain, sends via Evolution |
| `migrations/062_ai_platform.sql` | Additive only |

## Replaced

**`evo_webhook.php`.** The original authenticated nothing — any POST from
anyone was accepted. The replacement validates a shared secret, checks the
instance and event, rejects replays, claims each message id atomically so a
customer is never answered twice, queues to the existing `EventBus`, and
returns in milliseconds. The original is kept as
`evo_webhook_legacy.php.disabled` (inert) for reference.

**`cron_wa_bot.php` disabled.** Superseded by the AI brain. Running both would
answer the same customer twice. It is inert here anyway — WASender is not
configured — but leaving it registered invites that mistake later.

## Also fixed

`SqliteStore`'s first-boot JSON import renamed uCRM's `config.json` to
`config.json.migrated`. Since uCRM rewrites that file whenever an admin saves
the settings form, the plugin would have appeared to lose its configuration on
every save. `config.json` and `ucrm.json` are now excluded from the import.

## Install

1. UISP → Settings → Plugins → Add plugin → upload → **Enable**.
2. **Configuration screen**: Evolution API URL, Evolution API key, AI provider
   and its key. Three fields. The webhook secret is generated by the plugin.
3. Open **DishNet Sudan** from the UISP menu. It shows a setup page — create
   the first administrator. You must be signed into UISP as staff.
4. Log in with the account you just created.
5. **Admin → System Health** — work through anything that says `fix`.
6. **Engage → WhatsApp AI** — create or pick an Evolution instance per number,
   scan the QR, register the webhook, then **Start answering**.

Start with the sales number only. It carries no billing data, so a mistake
costs a lead rather than a customer's private information.

## Known limits

**Country configuration is partial.** The AI layer is country-neutral and reads
products, currency and customer data live from your Sudan uCRM. But the
accounting modules still carry South Sudan assumptions in code — 432 `'SSP'`
literals, 206 `+211` numbers, `Africa/Juba` in 34 files. Those are defaults and
labels, not data, and they do not import anything. Making the ledger fully
multi-currency is a separate piece of work; say the word and I will scope it.

**Background processing needs the cron entry.** `cron/master.php` expects
`* * * * * php /path/to/plugin/cron/master.php`. Without it, uCRM's 5-minute
plugin schedule is the fallback — fine for most jobs, slow for chat. The
webhook also spawns a worker directly, which is what makes replies immediate;
System Health tells you whether that is available.

## Tests

```bash
./tests/run.sh          # 137 assertions, no network required
```

Covers the AI layer: grounding rules, marker stripping, channel separation,
webhook authentication and replay, idempotency, phone-matching safety, uCRM
identity, and the refusal to ever write a secret from a plugin page.
