# Deploying the AI platform layer

New files for the DishNet Hybrid plugin. **Nothing here modifies an existing
file**, so the current WhatsApp bot keeps running while you cut over one number
at a time.

## Files

| Copy this | To |
| --- | --- |
| `lib/EvolutionApiService.php` | `lib/` |
| `lib/EvoWebhookGuard.php` | `lib/` |
| `lib/DishNetTools.php` | `lib/` |
| `workers/AiReplyWorker.php` | `workers/` |
| `evo_webhook_v2.php` | plugin root |
| `ai_tools.php` | plugin root |
| `migrations/062_ai_platform.sql` | `migrations/` |

Then run the plugin's migration runner. 062 is additive — no table is altered.

## Configuration

All of it goes in `kyc_config.json`, which lives in UCRM's `pluginDataDir` —
outside the plugin tree and outside git. That is the plugin's configuration
mechanism; a UCRM plugin gets no `.env`.

**Never commit these values. Never log them. Never return them from an API.**

```
evo_api_url             https://evo-evolution-api.<host>      no trailing slash
evo_api_key             <the Evolution key you hold>
evo_instance_sales      dishnet_sales
evo_instance_support    dishnet_support
evo_instance_account    dishnet_account
evo_webhook_secret      <generate: openssl rand -hex 32>
ai_tools_token          <generate: openssl rand -hex 32>
shopbot_ai_url          https://<shopbot-host>/api/dishnet/reply
shopbot_ai_token        <generate: openssl rand -hex 32>
```

`evo_instance_support` falls back to the existing `evo_instance_name`, and
`evo_instance_account` to `evo_accounts_instance_name`, so a partial config
still works during migration.

## Point Evolution at the new webhook

For each instance, one call — replacing `<secret>` with `evo_webhook_secret`:

```bash
curl -X POST "$EVO_URL/webhook/set/dishnet_sales" \
  -H "apikey: $EVO_KEY" -H 'Content-Type: application/json' \
  -d '{"webhook":{"enabled":true,
       "url":"https://crm.dishnetafrica.com/_plugins/dishnet-hybrid-telecom/evo_webhook_v2.php?token=<secret>",
       "byEvents":false,"base64":false,
       "events":["MESSAGES_UPSERT","MESSAGES_UPDATE","CONNECTION_UPDATE"]}}'
```

`EvolutionApiService::setWebhook()` does the same thing from PHP.

The endpoint **must** be HTTPS — the token travels in the URL because
Evolution v2 does not sign webhook payloads. If your build supports
`webhook.headers`, send the token as `X-DishNet-Token` instead and drop the
query string; the guard accepts either.

## Register the worker

Add `AiReplyWorker` wherever `WhatsAppWorker` is already invoked (the worker
cron). It consumes `ai.reply` from the existing `EventBus` — **no new queue.**

```php
require_once __DIR__ . '/workers/AiReplyWorker.php';
(new AiReplyWorker($store, $config))->run();
```

Run it at least every 10 seconds. Slower than that and customers feel the lag.

## The ShopBot endpoint you need to build

`AiReplyWorker` posts to `shopbot_ai_url`. That endpoint does not exist yet —
it is a thin Laravel controller wrapping `AiBrain`, and it is the only new code
required on the ShopBot side.

Request:

```json
{
  "channel": "account",
  "whatsapp_instance": "dishnet_account",
  "customer_phone": "211912345678",
  "message": "How much do I owe?",
  "push_name": "John",
  "conversation_id": 4821,
  "customer": { "id": 123, "name": "John Deng", "is_lead": false, "balance": 45.0 },
  "account":  { "balance": 45.0, "owes": true, "invoice": { }, "last_payment": { } },
  "history":  [ { "role": "customer", "text": "..." } ]
}
```

Response:

```json
{ "reply": "Hi John, your balance is $45.00…", "escalate": false, "escalate_reason": "" }
```

Context is scoped by channel: `sales` gets `products`, `support` gets
`services` and `line_status`, `account` gets `account`. The sales number never
receives a balance.

`identity_ambiguous: true` means several customers share this number's last
digits — the AI must ask a verifying question and disclose nothing.

## Verify before sending real traffic

```bash
TOK=<ai_tools_token>
BASE=https://crm.dishnetafrica.com/_plugins/dishnet-hybrid-telecom

# Run this FIRST — it reports what UCRM really returns for plans and products.
curl -s -H "Authorization: Bearer $TOK" "$BASE/ai_tools.php?tool=describe_product_schema"

curl -s -H "Authorization: Bearer $TOK" "$BASE/ai_tools.php?tool=products"
curl -s -H "Authorization: Bearer $TOK" "$BASE/ai_tools.php?tool=identify_customer&phone=211912345678"

# Must return 401.
curl -s -o /dev/null -w '%{http_code}\n' "$BASE/ai_tools.php?tool=products"
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/evo_webhook_v2.php"
```

`describe_product_schema` is the Phase 0 probe. Send me its output and I will
finalise `getProducts()` against the real field names instead of inference.

## Behaviour change to be aware of

`DishNetTools::identifyCustomerByPhone()` matches phone numbers **more strictly
than the current bot.** The existing `WaAutoReplyService::lookupCrmClient()`
matches with:

```php
str_ends_with($storedPhone, $incoming) || str_ends_with($incoming, $storedPhone)
```

The second clause means a short stored number such as `912345` matches every
incoming number ending in those digits — and that match currently gates balance
and invoice disclosure. The new version requires 9 digits of agreement, drops
the reverse clause, and returns `ambiguous` instead of guessing when several
customers match.

**Expect some customers who are recognised today to stop being recognised.**
That is the correct trade against disclosing the wrong person's billing, but it
is a visible change. To revert just this behaviour, set
`tools_legacy_phone_match: true` in `kyc_config.json` — no code change, effective
on the next message.

## Cutover, one number at a time

1. Deploy the files, run 062, set config. Nothing changes yet — no instance
   points at the new webhook.
2. Probe with `describe_product_schema` and confirm the tools return real data.
3. Point **sales** at `evo_webhook_v2.php`. Sales has no existing bot and no
   billing data, so the blast radius is a lead.
4. Watch for a day. Then support, then account.
5. As each number moves, disable the old path for it in the **same change** —
   `wa_accounts_autoreply_enabled: false` for accounts, and repoint the
   instance webhook away from `evo_webhook.php`. Never let both run on one
   number: the customer gets two replies.
6. Delete `evo_webhook.php` once all three have moved.

## Rollback

| Step | Undo |
| --- | --- |
| Whole layer | Repoint the instance webhook back to `evo_webhook.php`. The new files become inert |
| Strict phone matching | `tools_legacy_phone_match: true` |
| Tool API | Clear `ai_tools_token` — the endpoint then returns 503 |
| Migration 062 | Additive; nothing to undo. Drop `evo_webhook_seen` only if you truly want to |

Rollback is a config change, not a deployment, in every case except deleting
`evo_webhook.php`. Leave that until last.

## Tests

Dependency-free, matching the plugin's convention. No PHPUnit needed.

```bash
./tests/run.sh
```

40 assertions covering channel routing both ways, unknown-instance rejection,
legacy config fallback, API-key non-disclosure, webhook auth failing closed,
event and replay filtering, idempotent claim, and the phone-matching fix
including the leak case it closes.

These caught a real bug during development: SQLite rejects `datetime("now")` as
a column DEFAULT because double quotes denote an identifier. The dedup table
was silently never created, `claim()` always returned false, and **every message
would have been dropped as a duplicate** — a bot that never replies. Lint could
not see it; running the code did.
