# ShopBot → Hybrid: what to take and what to leave

Every ShopBot component, classified. The test is not "is it good" — it is
"does DishNet need it, and is Hybrid's version already better".

Of ShopBot's 1,897 PHP files, **about 2,400 lines are worth porting.** Almost
everything else is either an ERP DishNet does not run, or a capability Hybrid
already has.

## The AI layer

| Component | Verdict | Why |
| --- | --- | --- |
| `AiBrain` prompt architecture | **PORT** ✅ done | Grounding rules, advisor posture, no-haggling. Now `lib/DishNetAiBrain.php` |
| Action-marker protocol | **PORT** ✅ done | `<<QUOTE>>`, `<<ESCALATE>>`. Ported with unknown markers stripped too, so nothing leaks to a customer |
| Provider calls | **REWRITE** ✅ done | `OpenAI::` facade → curl. Hybrid also gets Claude, which ShopBot lacks |
| `Cache::` / `Log::` / `Str::` | **REWRITE** ✅ done | 43 call sites → `SqliteStore`, `error_log()`, plain PHP |
| Conversation history window | **PORT** ✅ done | Bounded to 10 turns |
| `IntentClassifier` | **REPLACE** | Retail taxonomy (cart, checkout, catalog). Replaced by channel role + the model's own reading — a static keyword matcher is a worse intent detector than the LLM already in the loop |
| `BotBrain` (162 KB) | **NOT REQUIRED** | The scripted non-LLM brain. Hybrid's `WaAutoReplyService` is the same idea and already tuned to DishNet |
| `SalesAssistantBrain` | **NOT REQUIRED** | Retail upsell/cart logic. The sales role in `DishNetAiBrain` covers the DishNet case |
| `CatalogueMatcher`, `ShoppingParser`, `ComboEngine`, `ThaliMenu`, `BulkOrderParser` | **NOT REQUIRED** | Retail SKU matching. DishNet sells ~30 plans — the whole list fits in the prompt |
| `GujlishDictionary`, `LocationDictionary`, `FaqDictionary` | **NOT REQUIRED** | Gujarati/English and Indian locations |
| Multilingual handling | **REWRITE** ⚠️ partial | ShopBot has no Arabic. Handled for now by instructing the model to mirror the customer's language — the model already speaks Arabic. Real Arabic support (templates, RTL in the admin inbox, Juba Arabic idiom) is still outstanding |
| Vision / `PhotoCatalogueMatch` / `VoiceTranscriber` | **NOT REQUIRED YET** | Genuinely useful later — a customer photographing their router. Not needed for the first three channels |
| `QuotationService` | **EVALUATE** | Hybrid already has `QuotationService`, `QuotePdfService`, `PluginQuotePdf`. Compare before porting anything |

## Infrastructure

| Component | Verdict | Why |
| --- | --- | --- |
| `WhatsAppGateway` + `EvolutionGateway` | **REPLACE** ✅ done | Superseded by `EvolutionApiService`, which adds channel↔instance mapping ShopBot never needed |
| `CloudApiGateway` (Meta) | **NOT REQUIRED** | DishNet uses Evolution |
| Laravel queues / Horizon / Redis | **REPLACE** ✅ | `EventBus` already has priority, backoff, locking, dead letters |
| Eloquent / migrations | **REPLACE** ✅ | PDO + SQLite, already in use |
| Filament admin | **NOT REQUIRED** | Hybrid has its own admin and inbox (`tabs/engage/`) |
| `Tenant` model | **EVALUATE** | The multi-country answer, but it assumes Eloquent. For three countries, config keyed by organisation is likely enough. Decide at Phase 5, not now |
| Conversation/Message models | **NOT REQUIRED** | `wa_conversations` / `wa_messages` are the system of record and carry `crm_client_id` |
| ERP: HR, Manufacturing, Ledger, Money, Billing, Invoicing, Procurement, Tender, Logistics | **NOT REQUIRED** | 231 migrations, 144 models. uCRM is the billing source of truth and Hybrid already has cashbook, HRM and payroll |
| `cloudbss-kernel` | **NOT REQUIRED** | A separate platform kernel |

## Where Hybrid already wins

Kept as-is, no ShopBot equivalent adopted:

- **Live line status via Splynx.** Tells support whether the line is actually
  up. Neither ShopBot nor uCRM can answer that.
- **uCRM integration** — `CrmApiClient`, auto-configured from `ucrm.json`.
- **Handover state machine** — `human_active` with a 24h cooldown.
- **Prompt-injection guard** — flags the conversation, alerts staff, refuses.
- **Escalation plumbing** — tickets, staff alerts, CRM jobs.
- **`EventBus`** — better than bolting on a second queue.

## Status

| Phase | State |
| --- | --- |
| 1. Environment validation | **Blocked on you** — `tests/validate_environment.php` |
| 2. Gap analysis | Done — this page and `docs/14` |
| 3. Final architecture | Done — `docs/15` |
| 4. Evolution adapter | Done — `EvolutionApiService` |
| 5. AI brain in Hybrid | Done — `DishNetAiBrain`, 45 tests |
| 6. DishNet tools | Mostly done. Write tools (`createCustomer`, `updateCustomer`) still open — see below |
| 7. Three instances | Code done; needs the real instance names |
| 8–10. Conversation tests, handover, docs | Needs a live environment |

## Deliberately not built yet

**Write tools.** `createCustomer()` and `updateCustomer()` are on your list, and
I have not built them, on purpose. Every tool shipped so far is read-only, which
means the worst an AI mistake can do is say something wrong. A write tool lets a
mistake create or alter a customer record in your billing system.

Those should come after the read path has run against real conversations for a
while, and they should be built with a confirmation step rather than direct
execution. Say the word and I will build them — I would just rather they were a
deliberate decision than an item that slipped in with everything else.

**Currency.** `getProducts()` returns a price but does not name a currency,
because uCRM's per-organisation currency field is unverified. The prompt
instructs the model to give the number without naming a currency when unsure.
`describe_product_schema` resolves this.
