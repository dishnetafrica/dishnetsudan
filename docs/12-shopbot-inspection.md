# Inspection: ShopBot (`shopping-main`)

48 MB, 1,897 PHP files, 231 migrations, 144 models. Observed, with `file:line`.

## Runtime

| | ShopBot | DishNet Hybrid |
| --- | --- | --- |
| Framework | Laravel 11 | none (raw PHP) |
| PHP | `^8.2` | 7.4 |
| Dependencies | Composer: Filament 3, Horizon, predis, openai-php, dompdf | none |
| Data | RDBMS + Redis | JSON files + SQLite |
| Runs as | containerised web app + queue workers | UCRM plugin, 5s execution period |

From `composer.json`. These two do not share a runtime — see
[13-combined-architecture.md](13-combined-architecture.md#constraint-1).

## The AI layer

| File | Size | What it is |
| --- | --- | --- |
| `app/Services/Bot/AiBrain.php` | 171 KB | The LLM conversational brain |
| `app/Services/Bot/BotBrain.php` | 162 KB | Deterministic/scripted brain |
| `app/Services/Bot/SalesAssistantBrain.php` | 38 KB | Sales specialisation |
| `app/Services/Bot/IntentClassifier.php` | 28 KB | Static keyword/regex classifier |
| `app/Services/Bot/CatalogueMatcher.php` | 28 KB | Product matching |
| `app/Jobs/ProcessIncomingMessage.php` | 82 KB | Inbound pipeline |

`AiBrain` calls OpenAI chat completions, default `gpt-4o-mini`, vision-capable,
per-tenant API key (`:119-125`).

### There is no LLM tool-calling

Searched `app/` for `tools`, `tool_calls`, `tool_choice`, `function_call`:
**zero occurrences.** The AI+Tools pattern does not exist in ShopBot.

Two mechanisms stand in for it:

**1. Context injection.** The catalogue is rendered into the system prompt —
`catalogueLines()` `:2214`, injected at `:2149` under a `PRODUCTS` heading
described as "source of truth for quoting". The prompt forbids invention
(`:2025`, `:2038`):

> Ground every recommendation in a real catalogue item — never invent a spec,
> model, rating or price.

> OUR PRICES ARE FIXED from the catalogue. If a customer proposes … their OWN
> price, you must NEVER accept, confirm, agree to, or repeat their number.

**2. Output-side action markers.** The model emits hidden blocks that PHP then
parses and executes:

| Marker | Uses |
| --- | --- |
| `<<QUOTE …>>` | 20 |
| `<<ORDER {json}>>` | 6 |
| `<<SOURCING>>` | 5 |
| `<<SEND {"doc":"price_list"}>>` | 5 |
| `<<FOLLOWUP>>` / `<<ACCEPT_QUOTE>>` / `<<SYS>>` | 3 each |

`ORDER_PROTOCOL_VERSION = 2` (`AiBrain.php:27`). Note the direction: markers let
the AI **cause an effect after answering**; they do not let it **fetch data
before answering**. That gap is what context injection fills.

### Intent classification is not AI

`IntentClassifier::classify()` `:53` is static keyword and regex matching. Its
taxonomy is retail (`:18-34`): `shopping, greeting, feedback, thanks, question,
price, shop_start, cancel, decline, human_agent, checkout, cart, catalog,
category, business, location, unknown`.

There is no sales/support/account axis and no telecoms concept anywhere in it.

The LLM is used elsewhere only at the edges: `RelationshipExtractor:201`,
`GoalEngine:184`, `LexiconLearner:77`, `Vision/PartIdentifier:84`,
`TenderAnalyzer:217`, `TenderOcr:140`, `ProductEnrichmentService:163`.

## The genuinely reusable assets

**WhatsApp transport abstraction** — the thing Hybrid lacks entirely:

```
App\Contracts\WhatsAppGateway          (interface)
  ├── EvolutionGateway.php    18 KB    sendText, sendImage, sendDocument,
  │                                    sendLocation, markRead, fetchContacts,
  │                                    connectionState — all per instance
  ├── CloudApiGateway.php      4 KB    Meta Cloud API
  └── WhatsAppManager::forTenant()     resolves driver per tenant
```

**Multi-tenancy, first-class.** `Tenant` model with `$tenant->setting(...)`,
per-tenant AI persona, brand knowledge, FAQ, OpenAI key, `whatsapp_driver`.
This is the multi-country answer.

**Advisor behaviour** (`AiBrain.php:2025`) — the sales posture DishNet needs:

> MANY CUSTOMERS DESCRIBE A PROBLEM OR A NEED, NOT A PRODUCT NAME … act as a
> knowledgeable advisor … then RECOMMEND suitable product(s) from the catalogue
> and offer to quote.

**Resilience.** A deterministic Signal Engine fires staff alerts *before* the AI
runs, so a lead survives an AI outage (`AiBrain.php:19-20`).

**Media.** `PhotoCatalogueMatch`, `ProductImageResponder`, `VoiceTranscriber`,
vision-capable model fallback.

**Quote-first flow.** `QUOTE_FIRST = true` `:33` — a confirmed AI order is
captured as a quotation for staff to convert, not booked directly.

## Gaps against DishNet

| Gap | Detail |
| --- | --- |
| **No Arabic** | Languages found: English, Swahili, Hindi, Gujarati (`GujlishDictionary.php`). Nothing for Sudan or South Sudan |
| Retail product model | `Product` = SKU with price. No speed, billing period, recurring charge, installation fee |
| No uCRM knowledge | Nothing in the codebase touches UISP or uCRM |
| Retail intent taxonomy | See above — no support or account axis |

## It is an ERP, not a chatbot

`modules/HR`, `modules/Manufacturing`, `src/Ledger`, `src/Money`,
`app/Services/{Billing,Finance,Invoicing,Ledger,Money,Procurement}`,
`cloudbss-kernel/`, plus Tender, Logistics, Warranty, Vision, Marketplace.
231 migrations, 144 models.

DishNet Hybrid also contains an ERP: `CashbookService` 86 KB, `HrmService`
38 KB, `PayrollService` 33 KB, `StaffLedgerService`, `FiberFinanceEngine`,
`StockService`. **Adopting ShopBot wholesale means running two.** This is the
main hidden cost in the merge, and it is addressed in
[13-combined-architecture.md](13-combined-architecture.md#what-not-to-merge).
