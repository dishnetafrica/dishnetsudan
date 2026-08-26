# Study: the South Sudan n8n bot (v3.7) vs the Sudan plugin

Source: `DishNet_AI_Bot_v3.7_natural_product_tone.json` — an n8n workflow,
140 nodes, running DishNet South Sudan's WhatsApp AI. Read node by node on
26 Aug 2026. This is the system the owner kept asking about when n8n came up:
South Sudan IS n8n.

## What it is

One inbound pipeline plus five scheduled jobs, on n8n + Postgres + Redis +
Evolution API + OpenAI:

```
WhatsApp → webhook → fromMe? → parse → filter groups → log → mark read
  → route by type: text / AUDIO (Whisper transcribe) / IMAGE (vision analyse)
                   / DOCUMENT (transcribe) / location / unsupported
  → unify → Redis queue → wait (debounce) → last message? → concatenate
  → takeover check (human_takeover flag → stop)
  → price list from Postgres (Redis-cached)
  → contact context (customer lookup, nightly uCRM sync into dn_customers)
  → AI Agent (OpenAI, Postgres chat memory, jumia_price_search tool)
  → Extract Markers  [LEAD] [TICKET] [ORDER] [IMAGE]
  → send text (+ typing indicator first, + product image when asked)
  → deterministic Signal Engine → conversation state machine → staff alerts
  → log outbound, mark replied
```

Scheduled: 15-min **watchdog** (unanswered messages in business hours →
WhatsApp alert to staff), **daily digest** 18:00, **QA judge** daily 08:00
(AI grades yesterday's conversations, report 08:10) + hourly criticals,
**uCRM customer sync** 03:00.

## The honest comparison

The Sudan plugin already has, sometimes better: webhook auth (SS's webhook has
none visible), live uCRM catalogue injection, human takeover (`human_active`,
now configurable cooldown), fromMe/group filtering, dedupe, price caching,
conversation storage + Inbox UI, escalation marker, retention/privacy,
currency statement, 485 tests and a preflight. n8n itself is not worth
porting — the plugin is that pipeline in PHP.

**One SS practice Sudan must NOT copy:** prices hard-coded in the prompt
(Mini $299, Standard $550, Residential $80/mo, 1 USD = 8200 SSP, fiber
tiers). That is exactly where the wrong "$299 / $550" figures that leaked
onto the Sudan website came from. Sudan's live-from-uCRM design is the fix
for a failure South Sudan still has.

## What South Sudan has that Sudan lacks — ranked

1. **Watchdog (15 min): unanswered customers page a human.** Sudan just
   lived this failure — 11 messages sat queued for hours and nobody knew.
   The scheduler is fixed, but only an alert catches the next silent break.
   Cheap: one cron + one query over wa_conversations + Evolution send.

2. **Staff alerts to WhatsApp.** SS pushes HOT leads to sales phones,
   tickets to support, payment signals to accounts — with per-signal
   cooldowns (callback 4h, payment 24h…) so staff aren't spammed. Sudan's
   `needs_human` only changes a tab colour in a screen nobody may be
   watching. Needs from owner: which numbers get which lane.

3. **Existing-customer mode.** A CONTACT CONTEXT line flips the bot into
   support mode for known customers: no pitching, no "home or business?",
   mandatory ticket emission for any reported problem, upgrades only if the
   customer asks. Sudan already looks customers up (`identifyCustomerByPhone`)
   but the sales channel treats everyone as a prospect.

4. **Message debouncing.** People send "hi" / "price?" / "for home" as three
   messages. SS queues in Redis, waits, answers once. Sudan answers each —
   three model calls, three overlapping replies. Portable with SQLite +
   a short wait in the worker; no Redis needed.

5. **Deterministic Signal Engine + funnel state.** Regex scoring (not the
   model) drives PROSPECT → LEAD → HOT_LEAD → CALLBACK → PAYMENT_SENT →
   INSTALLATION → ACTIVE_CUSTOMER, with cooldown re-alerts, and a
   deterministic ticket fallback when the model forgets to emit one.
   Philosophy matches the plugin exactly: never trust the model with money
   or routing. Ports cleanly to a PHP class + tests.

6. **Voice notes, images, documents.** SS transcribes audio with Whisper and
   analyses images; Sudan drops media to staff without AI. In this market
   voice notes are how many customers actually talk. Medium effort:
   media download + one provider call, behind the existing worker.

7. **QA judge.** An AI grades conversations nightly, criticals hourly, report
   to a WhatsApp number. This is the reviewer's "watch what the AI tells
   real customers" — automated. Port after the alert plumbing exists.

8. **Order flow.** [ORDER] marker → order number → insert → notify staff.
   Sudan has quotes but no order record from chat.

9. **Small touches:** typing indicator before replying; [IMAGE] marker sends
   a product photo (Sudan now has real kit photos to send); marker vocabulary
   [LEAD]/[TICKET]/[ORDER]/[IMAGE] richer than Sudan's ESCALATE/QUOTE;
   security/probing section of the prompt is more developed and worth
   merging (staff names, metrics, wholesale costs, probing patterns).

## Two decisions only the owner can make

- **AI disclosure.** SS instructs: never claim to be an AI unless directly
  asked. Sudan's website chat says "Automated assistant" openly, and the new
  privacy policy names the AI processors. Recommend Sudan keeps honest
  disclosure on both channels; if the SS tone is wanted anyway, that is a
  policy choice to make explicitly, not inherit silently.
- **Staff alert numbers per lane** (sales / support / accounts) for Sudan —
  the alert work is blocked without them.

## Recommended order

Phase A (fast, high value): watchdog → staff alerts with cooldowns →
existing-customer mode → prompt security/tone merge.
Phase B: debouncing → Signal Engine port with tests → order flow.
Phase C: voice/image handling → QA judge → digest → typing/product images.
