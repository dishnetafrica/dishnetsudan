# DishNet AI WhatsApp Platform — install

A uCRM/UISP plugin. Sales, Support and Accounts on one AI brain, answering from
live uCRM data.

Verified against this server: uCRM `4.5.33`, UISP `3.0.159`, PHP `8.1.34`.

## 1. Install

UISP → **Settings → Plugins → Add plugin** → upload `dishnet-ai.zip` → **Enable**.

That is the whole install. No shell access, no crontab, no other service.

If you rebuild the zip yourself, use `./build-zip.sh`. uCRM looks for
`manifest.json` at the **root** of the archive — zipping the containing folder
instead of its contents gives "Plugin manifest could not be found in the ZIP
archive". The script gets that right and verifies it before finishing.

## 2. Enter the secrets — once, on the Configuration screen

UISP → Plugins → DishNet AI → **Settings**. Only five fields matter here:

| Field | Value |
| --- | --- |
| Evolution API URL | `https://evo-evolution-api.<your>.easypanel.host` — HTTPS, no trailing slash |
| Evolution API key | from Evolution |
| Webhook secret | generate: `openssl rand -hex 32` |
| AI provider + key | Claude or OpenAI, and the matching key |
| **Setup tab unlock token** | generate: `openssl rand -hex 24` |

Leave the three instance names and *Answer customers automatically* alone —
you will set those on the plugin page, where you can see the result.

**Why secrets live here and not on the plugin page.** uCRM does not authenticate
plugin pages; its own documentation says a plugin's public URL is reachable
"without any authentication". This screen is behind your UISP login, so it is
the right place for keys. The unlock token you set here is what opens the
plugin page's Setup tab.

Values are stored in `data/config.json` in the plugin's data directory — not in
the plugin tree, not in git. **No secret is ever rendered on the plugin page or
written to a log**; they display as "set" or "not set".

## 3. Do the rest on the plugin page

Open **DishNet AI** in the UISP menu → **Setup** tab → enter the unlock token.
Five wrong attempts locks it for fifteen minutes.

Then, in order:

1. **WhatsApp numbers.** The plugin reads your instance list from Evolution and
   offers it as a dropdown, with each instance's connection state. Pick which
   one is Sales, Support and Accounts. Leave a number blank if it is not in use.
2. **Register webhook.** One button per number. The plugin sends Evolution the
   correct URL with the secret already in it, so you never handle the secret
   yourself.
3. **Read plan & product fields.** Reports what your uCRM actually returns for
   service plans and products, so the AI quotes real fields rather than assumed
   ones. Send that list to your developer to finish the product mapping.
4. **Start answering.** The on/off switch. Leave it off until the Status tab is
   all green.

If the instance dropdown is empty, the plugin could not reach Evolution — check
the API URL and key on the Configuration screen. You can still type names by
hand.

## 4. Check

The **Status** tab is read-only and safe to leave open. Every row should read
`ok` before you go live.

## 5. Go live

Setup tab → **Start answering**. Message the sales number from your own phone
and ask what plans are available.

Start with sales only — leave the other two numbers unassigned until sales
behaves. Sales carries no billing data, so a mistake there costs a lead, not a
customer's private information.

To stop instantly at any time: Setup tab → **Stop answering**. Messages are
still received and stored; nothing is sent.

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
./tests/run.sh                         # 120 assertions, no network needed
```
