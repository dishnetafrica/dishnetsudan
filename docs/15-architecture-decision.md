# Architecture decision: where the AI runs

**Recommendation: Option A — build it all into the Hybrid plugin. Do not
install ShopBot as a running service on EasyPanel.**

Gated on one 10-minute test (below). If that test fails, Option B becomes
mandatory, not optional.

This reverses the leaning in [13-combined-architecture.md](13-combined-architecture.md),
which assumed ShopBot had to run as a service. Measuring the coupling instead of
assuming it changed the answer.

## The five measurements that decide it

**1. The plugin already runs an LLM inside uCRM. In production. Today.**

`lib/ClaudeWaClient.php` (36 KB) and `lib/GptWaClient.php` (26 KB) call
`api.anthropic.com` and `api.openai.com` with plain curl, and they sit on the
live webhook path — `WaAutoReplyService::getAiReply()` is called at `:217`,
`:290`, `:312`, `:439`, `:465`.

This is the question people usually get stuck on ("can AI processing run in a
uCRM plugin?") and it is already answered by your own running system. Yes.

**2. ShopBot's AiBrain is barely coupled to Laravel.**

2,361 lines. Framework touchpoints: `Cache::` ×21, `Log::` ×20, `Str::` ×2, and
eight Eloquent queries. That is roughly 45 call sites, every one with a
one-line equivalent:

| Laravel | Hybrid equivalent (already exists) |
| --- | --- |
| `Cache::` | `SqliteStore` |
| `Log::` | `error_log()` / `activity_log` |
| `Str::` | plain PHP |
| Eloquent | PDO |
| `$tenant->setting()` ×61 | `kyc_config.json` |

The valuable part of AiBrain is prompt architecture — grounding rules, the
advisor posture, the action-marker protocol. That is portable text and regex,
not framework.

**3. Background spawning already works.** `includes/api/api_whatsapp.php:399`:

```php
@exec('php ' . escapeshellarg($cronScript) . ' > /dev/null 2>&1 &');
```

A non-blocking spawn from a web request, in production. So the webhook can
queue and immediately kick a worker — reply latency in seconds, not minutes.

**4. Everything else the AI needs is already in the plugin.** Async queue with
priority, backoff, locking and dead letters (`EventBus`). Conversation and
message store. Handover state machine. Admin inbox. Escalation. Prompt-injection
guard. Webhook endpoints receiving real traffic.

**5. After last week's commit, ShopBot's remaining unique value is prompt
design.** The Evolution adapter, tool layer, secured webhook and worker now
exist natively in Hybrid. ShopBot's gateway abstraction — the thing I called
out as its best asset — has been superseded.

## What Option A requires, point by point

| Your question | Answer |
| --- | --- |
| What needs porting | AiBrain's prompt builder, catalogue grounding rules, action-marker parser (`<<QUOTE>>`, `<<ORDER>>`, `<<SEND>>`). ~2,400 lines |
| What needs rewriting | Intent taxonomy (retail → sales/support/account). `IntentClassifier` is static and self-contained |
| Dependencies required | **None.** curl and SQLite, both already in use |
| Can the plugin environment run it | Yes — demonstrated, see measurement 1 |
| Can AI processing run there | Yes — same |
| Queues / background workers | Yes — `EventBus` exists and is production-grade |
| Evolution webhooks reliably | Yes — already receiving traffic; hardened in `evo_webhook_v2.php` |
| Three WhatsApp instances | Yes — pure configuration; `EvolutionApiService` already does it |
| Performance / maintenance problems | Real ones. See below |

## What Option A actually costs

I would not choose it without saying these out loud.

**Background processing depends on a host crontab.** `cron/master.php` is
designed for `* * * * * php .../cron/master.php`. The fallbacks — uCRM's
5-minute plugin heartbeat and the `public.php` piggyback (throttled to 300s) —
are far too slow for a chat channel. **This is not a pure "upload the zip"
install.** It needs host access.

**`exec()` availability is not guaranteed.** Your own code guards it with
`function_exists('exec')`, which tells me the authors were not certain. Without
it, replies fall back to the 60-second cron tick.

**Shared failure domain with billing.** The AI runs in the same container as
the plugin that runs your cashbook and CRM sync. A uCRM upgrade that breaks
plugins takes all three WhatsApp numbers offline at the same time.

**You are forking, not integrating.** Porting AiBrain means owning a PHP 7.4
derivative of a PHP 8.2 codebase, with no upstream. That is fine if you accept
it deliberately; it is a slow surprise if you do not.

**Plugin updates replace code.** Data survives in `pluginDataDir`; code does
not. AI prompt iteration becomes plugin-release work.

**SQLite has one writer.** WAL is enabled (`SqliteStore:118`) with a busy
timeout, which handles a lot. But three WhatsApp numbers, AI workers and 60+
cron jobs all writing to one file is a ceiling. Years away at your size, and
Postgres is the exit — but it is a ceiling.

## Why not Option B

Option B is the textbook answer, and on a bigger team I would take it: separate
failure domains, independent deploys, PHP 8.2, Redis, Horizon, no fork.

Against that, for DishNet specifically:

- It adds a **second runtime** — Laravel plus a database plus Redis — on a
  droplet already carrying UISP's Postgres and SiriDB, EasyPanel and Traefik.
- It adds a **network hop inside every customer message**, and a second system
  to be up, monitored, backed up and upgraded.
- It buys capability you no longer need, because Hybrid now has the adapter,
  the tools, the webhook and the worker.
- It contradicts what you have said four times: one platform.

Option B trades a maintenance problem you would feel weekly for a scaling
headroom you will not need for years.

## Option C, considered and rejected

A separate gateway in front of both (your third diagram) is the right shape for
a larger system. It adds a third deployable and a third failure domain to solve
a routing problem that `EvolutionApiService::channelFor()` already solves in
20 lines. Revisit if you ever run more than one AI backend.

## Against your twelve criteria

| | Option A | Option B |
| --- | --- | --- |
| Reliability | Shared failure domain — **worse** | Isolated — **better** |
| Performance | No network hop — **better** | Extra hop per message |
| Security | One surface, existing RBAC — **better** | Two surfaces, service auth |
| Maintainability | One codebase, but a fork — **even** | Two systems, no fork — **even** |
| Upgradeability | Coupled to plugin releases — **worse** | Independent — **better** |
| uCRM plugin limits | The binding constraint | Not applicable |
| ShopBot AI needs | Met after porting | Met natively |
| Evolution needs | Met | Met |
| Three numbers | Met | Met |
| Scaling later | SQLite ceiling — **worse** | **Better** |
| Troubleshooting | One place to look — **better** | Two, plus the hop |
| Three countries | Config work either way — **even** | **even** |

Option B wins reliability, upgradeability and scale. Option A wins performance,
troubleshooting, security surface and — decisively for a team your size —
operational load.

## The test that settles it

Run this in the uCRM plugin container before committing to Option A. Ten
minutes, no changes.

```bash
php -v                                    # is it 7.4, or 8.x?
php -r 'var_dump(function_exists("exec"), function_exists("shell_exec"));'
php -r 'var_dump(ini_get("max_execution_time"), ini_get("memory_limit"));'
crontab -l | grep -c master.php           # is the minute cron installed?
php -r '$c=curl_init("https://api.openai.com/v1/models");
        curl_setopt($c,CURLOPT_RETURNTRANSFER,1);curl_exec($c);
        var_dump(curl_getinfo($c,CURLINFO_HTTP_CODE));'   # 401 = reachable
```

**Go for Option A** if `exec` is available **and** the crontab is installed
**and** outbound HTTPS to the model provider works.

**Option B becomes mandatory** if `exec` is unavailable and no host crontab can
be installed — reply latency would be 5 minutes, which is not a product.

## If Option B is needed: the contract

Hybrid sends ShopBot, per message:

```json
{ "channel": "account", "whatsapp_instance": "dishnet_account",
  "customer_phone": "211912345678", "message": "How much do I owe?",
  "conversation_id": 4821,
  "customer": { "id": 123, "name": "John Deng", "is_lead": false },
  "account":  { "balance": 45.0, "owes": true, "invoice": {}, "last_payment": {} },
  "history":  [ { "role": "customer", "text": "..." } ] }
```

ShopBot returns:

```json
{ "reply": "…", "escalate": false, "escalate_reason": "" }
```

Hybrid owns identity, context assembly, tool execution, conversation storage
and sending. ShopBot only turns context into words — it never calls uCRM and
never calls Evolution. That boundary is what keeps either option reversible.

This contract is already implemented in `workers/AiReplyWorker.php`, so **the
work done so far is not wasted under either option.** Under A, the same context
builder feeds a ported brain in-process instead of over HTTP.

## What I would do

Run the test. Assuming it passes, take Option A, and port AiBrain's prompt
architecture rather than running ShopBot. Keep `AiReplyWorker`'s context
envelope as the internal interface, so if the shared failure domain ever bites,
moving the brain out is a config change and not a redesign.
