# Architecture assessment

Requested: inspect first, assess, then design. This is the assessment.

## What I could actually inspect

| # | Phase 1 item | Status |
| --- | --- | --- |
| 1 | ShopBot codebase | **Not provided** — no zip, no repo, not on disk |
| 2 | Evolution API installation | **No access** — cannot reach the server |
| 3 | UISP/uCRM installation | **No access** |
| 4 | UISP/uCRM API capabilities | **Partially** — inferred from plugin calls, not probed live |
| 5 | DishNet Hybrid plugin | **Done** — see [10-plugin-inspection.md](10-plugin-inspection.md) |
| 6 | Server/network configuration | **No access** — tooling ready in [02](02-inspection-runbook.md) |

One of six inspected fully. The brief's critical rule is "do not invent APIs or
database fields", so where I could not inspect, I have marked the gap rather
than filled it.

## Finding 1 — you have already built most of this platform

The plugin is not raw material for the platform in the brief. It largely **is**
that platform: Evolution API client, channel-aware AI bot, uCRM-backed customer
identification, live balance/invoice/payment lookups, conversation store,
human-handover state machine, admin inbox, prompt-injection guard.

Measured against the brief's own feature list:

| Requirement | Status |
| --- | --- |
| Evolution API as WhatsApp layer | Client exists; used for one channel only |
| AI intent → business logic | Exists, keyword + AI hybrid |
| Customer identification from phone | Exists (with a defect — Finding 4) |
| uCRM customer/service lookup | Exists |
| uCRM billing/invoice lookup | Exists |
| Human escalation with history | Exists |
| Unified admin inbox | Exists |
| SUPPORT channel | Exists |
| ACCOUNT channel | Exists |
| **SALES channel** | **Missing** |
| **Product lookup from uCRM** | **Missing — and actively contradicted** |
| Shared identity across channels | Partial — data is there, join is not |
| Multi-country | Not present |

**Recommendation: extend the plugin rather than port to ShopBot.** Rebuilding
on ShopBot means re-implementing everything above, then re-earning the
production hardening the plugin already carries (dedup windows, staff-phone
skip, 24h cooldowns, security flagging, idempotency guards).

Two honest caveats. First, I have never seen ShopBot — if it has a materially
better conversation engine or admin UI, that changes the trade-off, and I can
only judge once you send it. Second, the plugin is large and its `manifest.json`
release notes describe a fast-moving, incident-driven codebase; extending it
means accepting that history. My recommendation is a starting position, not a
conclusion you should accept without the ShopBot comparison.

## Finding 2 — the product requirement is contradicted in code today

The brief is explicit: product answers must come from uCRM, not hard-coded
plans. Right now the opposite is true. `lib/ClaudeWaClient.php:522-524` puts
the full price list in the AI system prompt:

```
Q: What plans do you have?
A: Starlink: $65 (50GB), $80 (100GB), $112 (150GB), $189 (Unlimited Standard), $218 (Unlimited Priority).
Fiber: $50, $75, $100/month.
LTE SIM: $25 (Silver), $40 (Gold), $80 (Platinum), $110–$120 (Diamond), $200–$250 (Enterprise).
```

Data caps and prices are repeated at `:472-473`. And `:519` deflects the sales
case to a human: *"Our sales team handles new connections — call or WhatsApp
them on +211 923 400 000."*

There is **no product lookup anywhere in the WhatsApp path.** `service-plans`
is called in exactly two places, both admin/diagnostic
(`includes/api/api_crm_misc.php:188`, `includes/api/api_notifications.php:263`).

So today a price change in uCRM does not reach the bot, and the bot will
confidently quote a stale number. This is the single highest-value fix, and it
is also the brief's chosen first milestone.

## Finding 3 — product source-of-truth is currently inverted

The brief wants uCRM as source of truth. The plugin makes uCRM the *downstream
copy*. `includes/post/post_sync.php:467-476` pushes the plugin's own
`subscription_plans.json` into uCRM:

```php
$crmSync->patch("products/{$existingUcrmId}", $productPayload);   // update
$resp = $crmSync->post('products', $productPayload);              // create
```

The payload it writes (`:459-465`) is the whole of what uCRM `products` then
holds:

```php
['name', 'invoiceLabel', 'unit' => 'Month', 'price', 'taxable' => false]
```

Two consequences worth being precise about:

1. **The plugin's JSON is master.** `subscription_plans.json` also carries
   `starlink_cost`, `profit`, `margin` — commercial fields uCRM never sees. A
   naive "read products from uCRM" would silently drop them.
2. **uCRM `products` cannot answer the brief's sales questions.** That payload
   has no speed/bandwidth, no billing period beyond `unit`, no installation
   fee, no currency, no availability. "What is the 50 Mbps package?" is not
   answerable from those five fields.

`service-plans` is the richer uCRM resource and the likelier home for
speed/period data — but it is barely used here, and **I will not guess its
field names.** That is the first thing to probe live.

There is also a local cache, `ucrm_products.json`
(`includes/api/api_products_admin.php:259`), which is a third copy.

**This needs your decision before code.** Options:

| | Approach | Cost |
| --- | --- | --- |
| A | uCRM becomes true master; plugin reads it; commercial fields move to a side table keyed by `ucrm_product_id` | Cleanest, matches the brief; requires migrating plan data |
| B | Keep the plugin as master; bot reads `subscription_plans.json` directly | Least work; contradicts the brief |
| C | uCRM master for customer-facing fields, plugin master for margin | Pragmatic; two writers, needs a clear field-ownership rule |

I lean to **A** because it is what you asked for and it removes the stale-price
class of bug. But it is a data migration, not a code change, so it is your call.

## Finding 4 — customer identification has a security defect

`lib/WaAutoReplyService.php:648-651` matches phone numbers by suffix, in both
directions:

```php
if ($cPhone && (str_ends_with($cPhone, $phone) || str_ends_with($phone, $cPhone))) {
```

The second clause is the problem. If any uCRM contact has a short or truncated
phone value — say `912345` — then **every** incoming number ending in those
digits matches that customer. The same pattern repeats at `:682` against a
9-digit suffix.

The match then gates disclosure: the ACCOUNT channel returns balance, last
payment and invoice details with no further verification. The brief asks to be
careful "especially before revealing billing information", so this is worth
fixing before the ACCOUNT channel scales.

Suggested, in order of effort:

1. Require a minimum matched length (say 9 digits) and drop the reverse clause.
2. On multiple matches, disclose nothing and escalate to a human.
3. Add a verification step before financial disclosure — confirm a name or a
   recent invoice number. Balance is arguably fine; a full invoice is not.

I would not change this without your sign-off: tightening the match will cause
some currently-recognised customers to stop being recognised, and that is a
visible service change.

## Finding 5 — shared identity is close, but not there

`ConversationService.php:113`:

```sql
CREATE UNIQUE INDEX idx_wa_conv_phone_channel ON wa_conversations(phone, channel);
```

One row per (phone, channel) means the same customer on SALES then SUPPORT is
two rows with two histories — the brief's "three separate customer databases"
worry, in miniature.

The good news: `crm_client_id` is already on every conversation row and is
populated automatically (`WaAutoReplyService.php:88-101`), and there is already
an index on it. So unified identity is a **join, not a migration**: a
customer-level view over `wa_conversations` grouped by `crm_client_id`, falling
back to `phone` when uCRM has no match. The schema does not need to change.

## Finding 6 — two WhatsApp providers is the largest hidden cost

Support and Accounts send through **WASender**; only the marketing number uses
**Evolution API**. The brief says Evolution API is the WhatsApp layer, so these
must converge.

The cost sits in `lib/NotificationService.php` — 119 KB, the most heavily
patched file in the plugin, and the path for every invoice, receipt and dunning
message. Its send routing is a hard-coded two-way branch on channel
(`:1474`, `:1561`, `:1691`) with only `SUPPORT` and `ACCOUNTS` constants.

Do **not** rewrite it. Introduce a small transport interface with WASender and
Evolution implementations, add a `SALES` constant, and move channels across one
at a time — starting with SALES, which has no billing traffic and therefore no
blast radius. Billing notifications move last, or never.

## Finding 7 — multi-country needs real work, but not yet

Currency handling is USD/SSP only (499 `'USD'` literals, 432 `'SSP'`; no UGX,
no SDG). `'country'` appears 14 times against 392 for `'currency'`. The AI
prompt hard-codes a South Sudan context, the Juba office address, EAT office
hours and +211 escalation numbers (`ClaudeWaClient.php:500-525`).

This is a v2 concern and I would not let it slow the first milestone. The one
thing worth doing *now* is cheap: when you build the product lookup, key it by
organisation/currency from the start rather than assuming USD. Retrofitting a
currency dimension later is far more expensive than including it.

## Proposed first milestone

The brief's own choice, and the right one:

```
WhatsApp → Evolution API → plugin → AI → uCRM product API → real prices → reply
```

Concretely, and in this order:

1. **Probe the live uCRM API** — `GET service-plans`, `GET products`,
   `GET organizations`. Record the real fields. Everything below depends on
   this and nothing below should be written before it.
2. **Decide the source-of-truth question** (Finding 3).
3. **Add a product service** — one class, reads uCRM, caches briefly, returns a
   typed list. No new database.
4. **Add the SALES channel** — a `handleSalesChannel()` beside the two existing
   handlers, plus `'sales'` in the dispatch at `:169`.
5. **Delete the hard-coded price block** from both AI clients and replace it
   with injected live product context, exactly as customer data is injected today.
6. **Point one Evolution instance at it** and test end to end.

Steps 3–5 are additive: no existing handler changes, so Support and Accounts
keep working throughout. That matters — these are live customer channels.

## What I need from you

1. **The ShopBot codebase.** Phase 1 item 1 is a real gap and it directly
   affects the build-vs-extend recommendation.
2. **Server access, or the inspection output** — `scripts/inspect-server.sh`
   from [02](02-inspection-runbook.md), plus the Evolution API URL, instance
   names and webhook configuration.
3. **A decision on Finding 3.**
4. **Confirmation on Finding 4** before I tighten phone matching.

Items 1 and 2 are the blocking ones. Everything in this assessment that touches
uCRM product structure stays provisional until the live API is probed.
