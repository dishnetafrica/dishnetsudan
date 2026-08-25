# Combined architecture

Your principle — *ShopBot provides intelligence, Hybrid provides business
capability, uCRM is the source of truth, Evolution is connectivity* — is right,
and this design follows it. Three things in the brief need adjusting first,
because the code does not support them as written.

## Constraint 1 — one plugin is not achievable, one platform is

ShopBot needs PHP ≥8.2, Composer, Redis and persistent queue workers. The
Hybrid plugin is PHP 7.4, dependency-free, and runs inside the UCRM plugin
sandbox on a 5-second execution period. Laravel 11 cannot run there, and the
Hybrid plugin cannot leave without losing its uCRM integration — which is the
thing that makes it valuable.

So "one DishNet plugin" has to mean what you said one line later: *"The
underlying components can remain modular internally, but from the DishNet
perspective it should be one platform."* That is achievable in full — one
customer experience, one identity, one admin surface, one set of business
rules. Two runtimes behind it, which no user or staff member ever sees.

I would not try to force a single runtime. It buys nothing a customer can feel
and costs the uCRM integration.

## Constraint 2 — ShopBot has no tools to plug into

Your diagram has the AI calling `dishnet.getProducts()`. ShopBot cannot do
that: it has no tool-calling, anywhere (see
[12-shopbot-inspection.md](12-shopbot-inspection.md#there-is-no-llm-tool-calling)).

What it has instead is **context injection** — the catalogue is rendered into
the prompt with strict no-invention rules — plus **output-side action markers**
(`<<QUOTE>>`, `<<ORDER>>`, `<<SEND>>`) that let the AI trigger an effect after
it answers.

The Hybrid plugin independently arrived at the same pattern: it injects the
identified customer's CRM context into the prompt before the AI replies.

Both codebases already do context injection. Neither does tool-calling. And for
your three named use cases, injection is the better fit:

| Use case | Injection | Tool-calling |
| --- | --- | --- |
| "What packages do you have?" | Inject ~30 plans once. One LLM call | Two calls, and the model can hallucinate arguments |
| "My internet is down" | Inject that customer's services + live line status | Two calls to fetch what you already know from their number |
| "How much do I owe?" | Inject their balance and latest invoice | Same |

In all three, **you already know what to fetch before the AI speaks** — the
channel tells you the domain and the phone number tells you the customer. There
is nothing for the model to decide.

**Recommendation: build the tool layer, call it deterministically, inject the
result.** You get your architecture — AI understands and communicates, DishNet
retrieves and changes — without a second round trip or a hallucinated argument.
The tools are real, callable, testable functions either way; what changes is
who invokes them.

Move to genuine tool-calling when the AI must *choose* an action with
consequences — booking an installation, raising a ticket with a category,
applying a plan change. That is a Phase 4 concern, and the tool layer built here
is exactly what it would call. Nothing is wasted.

If you would rather have model-driven tool-calling from day one, say so — it is
a legitimate choice, it is roughly two weeks more work in ShopBot, and the
endpoint layer below is unchanged.

## Constraint 3 — both systems contain an ERP

ShopBot carries HR, Manufacturing, Ledger, Money, Billing, Invoicing,
Procurement, Tender, Logistics — 231 migrations, 144 models. Hybrid carries
Cashbook (86 KB), HRM, Payroll, Staff Ledger, Fiber Finance, Stock.

Adopting ShopBot wholesale gives DishNet two payroll systems, two ledgers and
two invoicing engines. Nobody wants that, and the second one is never the one
staff actually use.

**Take ShopBot's bot layer, not ShopBot.** See
[what not to merge](#what-not-to-merge).

## The architecture

```
   WhatsApp  ──  Sales #1      Support #2      Account #3
                    │              │               │
                    └──────────────┴───────────────┘
                                   │
                        Evolution API  (EasyPanel, already installed)
                                   │   one instance per number
                                   ▼
              ┌────────────────────────────────────────┐
              │        DISHNET AI PLATFORM             │   Laravel, EasyPanel app
              │  (ShopBot bot layer, DishNet-shaped)   │
              │                                        │
              │  WhatsAppGateway ── Evolution driver   │
              │  Tenant          ── UG / SS / SD       │
              │  Channel role    ── sales|support|acct │
              │  AiBrain         ── DishNet persona    │
              │  Identity        ── uCRM client id     │
              └────────────────────┬───────────────────┘
                                   │  HTTPS + JWT
                                   ▼
              ┌────────────────────────────────────────┐
              │      DISHNET BUSINESS API              │   Hybrid plugin, api/v2
              │  products · customer · services        │
              │  account · tickets · payments          │
              └────────────────────┬───────────────────┘
                                   ▼
                        UISP / uCRM   ── source of truth
```

Evolution stays connectivity only. uCRM stays source of truth. The AI never
learns what a uCRM endpoint is; the business layer never learns what a prompt
is.

### Channel role, not three bots

One brain. The Evolution instance a message arrives on sets a **role**, and the
role decides which context gets injected and which behaviours are enabled:

| Role | Injected context | Enabled actions |
| --- | --- | --- |
| `sales` | Product catalogue for the tenant's country | Quote, capture lead, book survey |
| `support` | Customer services, live line status, open tickets | Troubleshoot, raise ticket, escalate |
| `account` | Balance, latest invoice, last payment | Send invoice, payment instructions, escalate |

Same `AiBrain`, same identity, same tools, same escalation. This is exactly
your point 8, and it falls out naturally — the role is a parameter, not a fork.

### Shared identity

One `dishnet_customer` keyed on **uCRM client id**, resolved once from the
phone number and cached on the conversation. A prospect who becomes a customer
gets linked on their next message from any of the three numbers.

Both codebases already have the pieces: Hybrid resolves phone → uCRM client and
stores `crm_client_id` on the conversation; ShopBot has per-tenant customer
records. The work is one resolver, used by all three roles.

Note the security defect in Hybrid's phone matching
([11-architecture-assessment.md](11-architecture-assessment.md), Finding 4)
must be fixed *before* this becomes shared — a bad match currently leaks one
customer's billing, and sharing identity across three channels widens that.

## What comes from where

### From ShopBot — reuse as-is

| Asset | Why |
| --- | --- |
| `App\Contracts\WhatsAppGateway` + `EvolutionGateway` | The transport abstraction Hybrid lacks; already multi-instance |
| `Tenant` model + per-tenant settings | Your multi-country requirement, already solved |
| `AiBrain` prompt discipline | No-invented-prices, no-haggling, ground-every-recommendation — battle-tested |
| Advisor posture (`AiBrain:2025`) | "Customers describe a need, not a product" is the DishNet sales case exactly |
| Action-marker protocol | `<<QUOTE>>` maps directly onto DishNet quoting |
| Signal Engine (alerts fire before the AI) | A lead survives an AI outage |
| Media stack | Vision, photo matching, voice transcription |
| Filament admin | The unified inbox surface |

### From ShopBot — refactor

- **Intent taxonomy.** Replace the retail set with `sales / support / account`
  plus telecoms sub-intents. `IntentClassifier` is static and self-contained,
  so this is a rewrite of one class, not a refactor of the brain.
- **Product model.** ShopBot's `Product` is a retail SKU. DishNet needs a
  service plan: speed, billing period, recurring charge, installation fee,
  currency. Shape this from the **live uCRM response**, not from either
  codebase's assumptions.

### From ShopBot — replace

- **Language support.** English, Swahili, Hindi and Gujarati are the wrong four
  for Sudan and South Sudan. **Arabic is missing and is not optional.** Budget
  this as real work, not a translation file.

### From Hybrid — expose as tools

The plugin already has a production-grade API at
`/crm/_plugins/dishnet-hybrid-telecom/api/v2/` with JWT auth, RBAC, rate
limiting, idempotency and CORS. Existing routes: `auth`, `health`, `tickets`,
`install`, `wallet`, `noc`.

**The plumbing is built; the business endpoints the AI needs are not.** That is
the single most tractable piece of work in this project — the logic already
exists as internal PHP, it just is not reachable over HTTP.

| Endpoint to add | Backed by (exists today) |
| --- | --- |
| `GET products` | `CrmApiClient` → uCRM `service-plans` / `products` |
| `GET customer?phone=` | `WaAutoReplyService::lookupCrmClient()` `:640` |
| `GET customer/{id}/services` | `getClientServices()` `:694` |
| `GET customer/{id}/account` | `getLatestInvoice()` `:713`, `getLastPayment()` `:703` |
| `GET customer/{id}/line-status` | `getFiberSplynxContext()` `:1236` — live Splynx |
| `POST tickets` | already routed |

That Splynx line-status endpoint is worth calling out: it tells support whether
the line is *actually* up before the AI says anything. Neither ShopBot nor uCRM
can do that. It is Hybrid's most valuable unique asset.

### From Hybrid — leave exactly where it is

Cashbook, HRM, Payroll, Staff Ledger, Fiber Finance, Stock, Retailer PWA, and
60+ cron jobs. None of it belongs in the AI platform. Hybrid keeps running as
the uCRM plugin it is.

## What not to merge

| Do not merge | Reason |
| --- | --- |
| ShopBot's HR / Manufacturing / Procurement / Tender | DishNet does not do these; Hybrid already covers HR and payroll |
| ShopBot's Ledger / Money / Billing / Invoicing | uCRM is the billing source of truth. A second invoicing engine will drift |
| Hybrid's WhatsApp bot (`WaAutoReplyService`) | Superseded by `AiBrain`. Retire it when the channel moves — do not run both |
| Hybrid's cashbook / payroll into ShopBot | Working, staff-trained, uCRM-adjacent. Moving it buys nothing |
| Evolution API into either | It is connectivity. Keep it dumb |

The retirement of `WaAutoReplyService` is the one deletion I would actually
plan for. Running two bots on the same numbers during cutover will double-reply
to customers — see the sequencing below.

## Sequencing

**Phase 0 — probe uCRM.** `GET service-plans`, `products`, `organizations`.
Record real fields. Everything downstream depends on this and **nothing else
should start first.** Still blocked on server access.

**Phase 1 — business API.** Add the endpoints above to Hybrid's existing
`api/v2` router. No new framework, no new auth, no schema change. Testable with
curl, independent of any AI work.

**Phase 2 — DishNet AI Platform.** Deploy the ShopBot bot layer as an EasyPanel
app. Add a `DishNetClient` service that calls Phase 1. Replace the intent
taxonomy. Write the DishNet persona.

**Phase 3 — Sales channel end to end.** One Evolution instance, `sales` role,
injected live catalogue. This is your first milestone and it proves the whole
chain. Sales is the right one to go first: no billing data, no existing bot on
that number, so the blast radius is a lead.

**Phase 4 — Support and Account.** Move one number at a time. **Disable
`WaAutoReplyService` for a number in the same change that enables the new
platform for it** — never both. Fix the phone-matching defect before Account
moves.

**Phase 5 — Arabic and multi-country tenants.** UG / SS / SD as tenants, each
with its own products, currency, numbers and language.

Phases 1 and 2 are independent and can run in parallel once Phase 0 lands.

## Decisions I need from you

1. **Tool-calling now, or deterministic calls with injection?** I recommend
   injection first (Constraint 2). Your call — it changes Phase 2's size.
2. **Full ShopBot deployment, or extract the bot layer?** Deploying whole is
   faster to Phase 3; extracting avoids carrying an ERP you will not use. I
   lean to deploying whole for Phase 3, then extracting once it works — but
   only if you accept the interim duplication.
3. **Infrastructure.** ShopBot needs MySQL or Postgres plus Redis on a box
   already running UISP's Postgres and SiriDB. Check headroom before Phase 2 —
   `scripts/verify-uisp-health.sh` flags low disk, and UISP degrades badly when
   the disk fills.
4. **Product source of truth** — still open from
   [11-architecture-assessment.md](11-architecture-assessment.md), Finding 3.
   Phase 1 cannot be finished without it.
