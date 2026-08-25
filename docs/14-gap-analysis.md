# Gap analysis

Your §15 table, filled in from the source. "Partial" means the capability
exists but not in a form the AI platform can use.

| Capability | Status | Evidence / what's missing |
| --- | --- | --- |
| ShopBot AI brain | **Exists** | `AiBrain.php` 171 KB, OpenAI, per-tenant |
| Conversation engine | **Exists ×2** | ShopBot `Conversation`/`Message` models; Hybrid `wa_conversations`/`wa_messages`. **Pick one** |
| Evolution connection | **Partial** | `EvolutionApiClient.php` works but is single-instance, no retry, no redaction |
| Evolution webhook | **Exists, insecure** | `evo_webhook.php` has **no authentication at all** |
| Evolution send message | **Partial** | Client can send; nothing routes by channel. Outbound still goes via WASender |
| 3 WhatsApp instances | **Missing** | Config has `evo_instance_name` + `evo_accounts_instance_name` only |
| Customer lookup | **Exists, defective** | `lookupCrmClient()` can match the wrong customer |
| Product lookup | **Missing** | `service-plans` called in 2 admin files; nothing in the WhatsApp path |
| Service lookup | **Exists** | `clients/{id}/services` |
| Billing lookup | **Exists** | balance, last payment |
| Invoice retrieval | **Exists** | `getLatestInvoice()`, PDF via `getRawContent()` |
| Support functionality | **Exists** | `createSupportTicket()`, `support_tickets` table |
| Human handover | **Exists** | `state='human_active'`, 24h cooldown |
| **AI tools** | **Missing** | No business-level tool layer. This is the core gap |
| Channel routing | **Partial** | Two channels hard-coded; no sales |
| Customer identity | **Partial** | Per-`(phone, channel)`; no shared view |
| Admin configuration | **Exists** | `kyc_config.json` in `pluginDataDir` |
| Logging | **Exists** | `activity_log`, `EventBus` |
| Security | **Mixed** | JWT/RBAC/rate-limit/idempotency on `api/v2`; **zero on the Evolution webhook** |
| Async processing | **Exists** | `EventBus` + `events` table: retries, backoff, locking, dead letters |

## The five real gaps

1. **AI tool layer** — nothing exposes business capability at business level.
2. **Multi-instance Evolution routing** — one number's worth of config.
3. **Webhook security** — `evo_webhook.php` trusts every request.
4. **Product lookup** — never reachable from a conversation.
5. **Safe customer identification** — current matching can leak billing data.

Everything else exists and should be reused, not rebuilt.

## What already exists and must not be rebuilt

- **`EventBus`** (`lib/EventBus.php` + `migrations/001_event_queue.sql`) — async
  queue with priority, exponential backoff, worker locking, dead letters and
  replay. This is exactly what your §12 describes. Reuse it.
- **`api/v2` router** — JWT, RBAC, rate limiting, idempotency, CORS.
- **`ConversationService`** — conversations, messages, CRM linking, Evo import.
- **`IdempotencyGuard`**, `RbacService`, `JwtAuth`, `LoginRateLimiter`.

## Evolution API — what I could and couldn't verify

Your paste confirms: base URL, **v2.3.7**, `clientName evolution_exchange`,
manager URL. I could not reach the host from this environment (blocked by the
egress allowlist), so **instance names, connection status and existing webhook
configuration remain unverified.** The code below reads them from config and
logs what it finds on first run.

One thing to be plain about: **Evolution API v2 does not sign webhook
payloads.** There is no HMAC to verify. The available controls are a shared
secret you place in the webhook URL, an instance allowlist, an event allowlist,
and a replay window — all of which the guard below implements. Some 2.x builds
support `webhook.headers` for a custom header; the guard accepts a header token
too, so if 2.3.7 supports it you can switch without a code change.

## On conversation storage

Both systems have one. Running both means two histories for one customer.
Recommendation: **Hybrid's `wa_conversations` stays the system of record** —
it already carries `crm_client_id`, handover state and the admin inbox — and
ShopBot receives history as context rather than persisting its own. That keeps
identity in one place, next to the CRM link.
