# DishNet AI WhatsApp Platform — install

A uCRM/UISP plugin. Sales, Support and Accounts on one AI brain, answering from
live uCRM data.

Verified against this server: uCRM `4.5.33`, UISP `3.0.159`, PHP `8.1.34`.

## 1. Install

UISP → **Settings → Plugins → Add plugin** → upload `dishnet-ai.zip` → **Enable**.

That is the whole install. No shell access, no crontab, no other service.

## 2. Configure

UISP → Plugins → DishNet AI → **Settings**. Leave *Answer customers
automatically* **OFF** for now.

| Field | Value |
| --- | --- |
| Evolution API URL | `https://evo-evolution-api.<your>.easypanel.host` — HTTPS, no trailing slash |
| Evolution API key | from Evolution |
| Webhook secret | generate: `openssl rand -hex 32` |
| Sales / Support / Accounts instance | your Evolution instance names |
| AI provider | Claude or OpenAI |
| Provider API key | the matching key |

Values are stored in `data/config.json` in the plugin's data directory — not in
the plugin tree, not in git. **Nothing here is ever shown on the plugin page or
written to a log**; secrets display as "set" or "not set".

## 3. Point Evolution at the plugin

For each instance:

```bash
curl -X POST "$EVO_URL/webhook/set/dishnet_sales" \
  -H "apikey: $EVO_KEY" -H 'Content-Type: application/json' \
  -d '{"webhook":{"enabled":true,
       "url":"https://<your-uisp-host>/crm/_plugins/dishnet-ai/evo_webhook.php?token=<webhook secret>",
       "byEvents":false,"base64":false,
       "events":["MESSAGES_UPSERT","MESSAGES_UPDATE","CONNECTION_UPDATE"]}}'
```

**HTTPS is required.** Evolution v2 does not sign its webhooks, so that token is
the only thing proving a request is genuine. If your build supports
`webhook.headers`, send it as `X-DishNet-Token` and drop the query string — the
plugin accepts either.

## 4. Check

Open the **DishNet AI** page in UISP. Every setup row should read `ok`. If any
reads `fix`, the page says what is wrong.

Then confirm the plugin can see your real products:

```bash
curl -s -H "Authorization: Bearer <ai_tools_token>" \
  "https://<your-uisp-host>/crm/_plugins/dishnet-ai/ai_tools.php?tool=describe_product_schema"
```

That reports which fields your uCRM actually returns for service plans. Send me
the output and I will finish the product mapping against real field names
instead of inference.

## 5. Go live

Turn on **Answer customers automatically**. Message the sales number from your
own phone and ask what plans are available.

Start with sales only — leave the other two instances blank until sales behaves.
Sales carries no billing data, so a mistake there costs a lead, not a customer's
private information.

## How it runs

```
WhatsApp → Evolution API → evo_webhook.php   validates, stores, queues, returns
                                  ↓
                          run_worker.php     spawned immediately
                          main.php           every minute, guaranteed
                                  ↓
                          AI brain + DishNet tools → uCRM
                                  ↓
                          Evolution API → WhatsApp
```

The webhook never waits for the AI. It queues and returns in milliseconds, then
kicks a worker. If `exec()` is blocked in your container the spawn is skipped and
`main.php` picks the work up within a minute — slower, but nothing is lost.

## What it will not do

- **It never invents commercial information.** Prices, speeds, balances and
  invoices come from uCRM at the moment of the conversation. If a value is
  missing, the AI says it will check and hands over rather than guessing.
- **It will not discuss an account it cannot confidently identify.** Phone
  matching requires 9 digits to agree. If two customers match it discloses
  nothing and asks a verifying question.
- **It hands over instead of guessing** — asked for a human, unsure, or the AI
  provider is down.
- **It cannot change anything.** Every tool is read-only. It cannot create or
  edit a customer, take a payment, or alter an invoice.

## Differences from the DishNet Hybrid plugin

This is a separate, minimal plugin — 350 KB against 12 MB. It shares ten library
files with Hybrid (`CrmApiClient`, `SqliteStore`, `EventBus`,
`ConversationService` and friends) but carries none of the cashbook, HRM,
payroll, LTE, Starlink or retailer machinery, and none of its 60+ cron jobs.

Two deliberate changes to the shared `SqliteStore`:

1. **`config.json` and `ucrm.json` are excluded from the JSON→SQLite import.**
   uCRM owns those files and rewrites `config.json` every time an admin saves the
   settings form. Without this exclusion the store renames it to
   `config.json.migrated` on first boot, and the plugin appears to lose its
   configuration on every save. Found by testing, not by reading.
2. **The BlueCard/LTE seed block is removed** — this plugin has no LTE feature,
   and the missing directory was logged once a minute forever.

Both remain compatible with Hybrid; neither changes stored data.

## If something goes wrong

| Symptom | Look at |
| --- | --- |
| No replies | Plugin page → Queue. Waiting > 0 means the worker is not running |
| "Gave up" count rising | `ai_platform.log` in the plugin data directory |
| Webhook rejected | Token mismatch, or the instance name is not in Settings |
| Wrong customer identified | Turn OFF *Use loose phone matching* if it was ever enabled |
| AI said something wrong | Extra instructions field, then the plugin page for what data it had |

To stop it immediately: turn off **Answer customers automatically**. Messages are
still received and stored; nothing is sent.

## Tests

```bash
php tests/validate_environment.php     # environment go/no-go
./tests/run.sh                         # 85 assertions, no network needed
```
