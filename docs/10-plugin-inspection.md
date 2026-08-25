# Inspection: DishNet Hybrid Telecom plugin v4.21.116

Source: `dishnethybridtelecomv4_21_116.zip` (12 MB, 314 PHP files, 61 SQL
migrations). This is a UCRM/UISP plugin (`manifest.json`, `ucrmVersionCompliability.min = 2.14.0`).

Unlike `docs/01`, **this page is observed, not described.** Every claim cites
`file:line` so you can verify it.

## Headline

The plugin is already a working WhatsApp AI customer platform wired into uCRM.
It is not a starting point — it is roughly the platform described in the brief,
missing one of three channels and pointed at the wrong product source.

## The WhatsApp/AI stack that already exists

| Component | File | Size |
| --- | --- | --- |
| Evolution API v2 client | `lib/EvolutionApiClient.php` | 9 KB |
| Channel-aware bot | `lib/WaAutoReplyService.php` | 76 KB |
| Claude client + CRM-context prompt | `lib/ClaudeWaClient.php` | 36 KB |
| OpenAI client (alternative) | `lib/GptWaClient.php` | 26 KB |
| Conversation store | `lib/ConversationService.php` | 29 KB |
| uCRM API client | `lib/CrmApiClient.php` | 28 KB |
| Outbound WhatsApp sender | `lib/NotificationService.php` | 119 KB |
| Admin inbox UI | `tabs/engage/wa_inbox.php`, `whatsapp.php` | 33 + 169 KB |
| Inbound webhooks | `evo_webhook.php`, `wa_webhook.php` | 22 + 15 KB |
| Async worker | `workers/WhatsAppWorker.php` | — |

`EvolutionApiClient` is complete and matches the brief's requirements:
instances (`/instance/fetchInstances`, `/instance/connectionState/{i}`), chats,
messages, `sendText`, `sendMedia` (document/image/video/audio), and
`setWebhook`/`findWebhook`. The instance name is a constructor argument, so
multiple numbers are already possible — one client object per instance.

## Channels: two of three exist

Dispatch is a two-way branch — `lib/WaAutoReplyService.php:169-175`:

```php
if ($channel === 'accounts') {
    return $this->handleAccountsChannel(...);
} else {
    return $this->handleSupportChannel(...);
}
```

| Brief | In code | State |
| --- | --- | --- |
| SUPPORT | `handleSupportChannel()` `:178` | Built |
| ACCOUNT | `handleAccountsChannel()` `:412` | Built |
| SALES | — | **Missing** |

There is a third channel name, `marketing`, set in `evo_webhook.php:68`. It
captures leads (`evo_webhook.php:103-140`, two-message minimum, dedup via
`wa_conversations.lead_id`) but has **no handler** — it falls through the
`else` above into the support flow. The AI client is likewise channel-blind to
it: `ClaudeWaClient.php:35` documents `'support' or 'accounts'`, and its only
channel branch is `:353` for accounts.

So sales messages currently land in a support conversation and get a support
answer.

## Account/billing lookups already work

`WaAutoReplyService` reads live uCRM data:

| Function | Line | uCRM call |
| --- | --- | --- |
| `lookupCrmClient()` | 640 | `clients?phone=` / `clients?search=` |
| `getClientServices()` | 694 | `clients/{id}/services` |
| `getLastPayment()` | 703 | `payments?clientId={id}&limit=1` |
| `getLatestInvoice()` | 713 | `invoices?clientId={id}&statuses[]=1&statuses[]=2` |
| `accountsBalance()` | 521 | via the above |
| `accountsInvoiceInfo()` | 589 | via the above |

`lib/CrmApiClient.php:50-80` auto-configures from the UCRM plugin's own
`ucrm.json` (`ucrmLocalUrl`, `pluginAppKey`, header `X-Auth-App-Key`) against
**API v2.1**, with a manual `crm_base_url`/`crm_auth_token` override.

Resources used plugin-wide (call counts): `clients/` 109, `payments` 25,
`scheduling/jobs/` 24, `invoices/` 21, `clients/services` 11, `products` 10,
`billing/quotes/` 16, `networks/` 18, `service-plans` 2.

## Human handover already exists

`wa_conversations.state = 'human_active'` suppresses the bot for a 24-hour
cooldown (`WaAutoReplyService.php:103-115`), then auto-resumes. Escalation
helpers: `getEscalationMessage()` `:1053`, `alertStaff()` `:1406`,
`createSupportTicket()` `:1384`, `createCrmJobForBidal()` `:1432`. States are
enumerated in `migrations/046_wa_autoreply_state.sql`.

There is also a prompt-injection / data-extraction guard
(`WaAutoReplyService.php:119-142`) that flags the conversation
`category='security_flag'`, alerts staff, and refuses.

## Conversation schema

`lib/ConversationService.php:91-113` — `wa_conversations` with `phone`,
`channel`, `crm_client_id`, `crm_client_name`, `state`, `status`, `category`,
`tags`, counts, timestamps; plus `wa_messages` `:118`.

```sql
CREATE UNIQUE INDEX idx_wa_conv_phone_channel ON wa_conversations(phone, channel);
```

Storage is SQLite (`lib/SqliteStore.php`) plus a JSON file store
(`lib/JsonStore.php`) — no MySQL/Postgres. PHP 7.4, pure curl, no Composer.

## Two WhatsApp providers, not one

| Path | Provider | Auth | Channels |
| --- | --- | --- | --- |
| `wa_webhook.php` | **WASender** | `app_key` `:253-256` | support, accounts |
| `evo_webhook.php` | **Evolution API** | instance name `:68-79` | marketing, accounts |

Outbound sending is WASender-only. `NotificationService` picks the key purely
by channel — `:1474`, `:1561`, `:1691`:

```php
$useAppKey = ($sender === self::ACCOUNTS) ? $this->accountsAppKey : $this->appKey;
```

Only two sender constants exist: `SUPPORT` and `ACCOUNTS` (`:33-34`). The
phone-number config fields are labels only and do not route — stated in the
plugin's own v4.21.114 release note.

## Other integrations present

`SplynxApiClient` + `SplynxTicketService` (fiber, with live line status fed
into the AI prompt — `WaAutoReplyService.php:1236`), `MagmaApiClient` (LTE),
`StarlinkBlockService`, `GoogleDriveBackup`, `RbacService`, `HrmService`,
`PayrollService`, `CashbookService`, `FiberFinanceEngine`.

## Notes for later

- `lib/EvolutionApiClient.php:213` sets `CURLOPT_SSL_VERIFYPEER => false`.
  Acceptable for a host-local hop, not for a call across a network.
- `ClaudeWaClient.php:5` says `claude-haiku-3-5`; `:21` uses `claude-haiku-4-5`.
  The comment is stale, the code is current.
