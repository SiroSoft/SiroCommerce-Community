# Siro Platform Roadmap (broad platform, not single-vertical)

Status: LIVING. Last verified against code on 2026-11-22. Order by
shared-platform value, not by industry.

Legend: **SHIPPED** means migrations, backend application code, routes, and admin
or storefront surfaces exist in this repo. It does not imply every future edge
case or provider certification is complete.

## Commercial editions (planned)

Packaging plans for the paid Pro and Enterprise editions live in
[PRO_EDITION_PLAN.md](./PRO_EDITION_PLAN.md). Status: **foundation shipped,
commercial GA not yet**. Shipped: feature flags, offline license verification/
enforcement/issuance, extension packages/QIT/slots/scope, private Composer.
Planned: marketplace billing/vendor portal, packaged multi-store theming,
SSO/SAML, SLA/support, managed AI quotas. Do not market planned items as
existing capabilities.

## Shipped platform capabilities (verified 2026-11-22)

| Capability | Status | Code evidence |
|---|---|---|
| Native Siro theme packages | SHIPPED | `ThemePackage`, `ThemePackageArchiveInstaller/Exporter`, Admin import/export, `examples/themes/*` |
| Extension ecosystem (packages/QIT/license) | SHIPPED foundation | `ExtensionPackageInstaller`, `ExtensionQualityGate`, `ExtensionLicenseService/Issuer`, deploy/QIT commands |
| Storefront extension slots | SHIPPED | `apps/storefront/src/slots/*`, layout/page integration |
| Vendor payout reconciliation | SHIPPED | `VendorPayoutReconciliationService`, failed/returned states, admin CSV import |
| Private Composer extensions | SHIPPED | `ExtensionDiscovery` reads `extra.siro.extension` |
| Gift cards + store credit | SHIPPED | `backend/app/Modules/Promotion/Application/GiftCreditService.php`, `/api/admin/gift-cards`, `/api/admin/store-credits`, admin `apps/admin/src/app/(dashboard)/gift-cards/page.tsx` |
| Split tender (multi-gift) | SHIPPED | `PlaceOrderService` / `CartService` apply up to `MAX_GIFT_CODES=5` gift/credit codes in order; remainder goes to one payment provider |
| Click & Collect / pickup points | SHIPPED | `PickupPointController`, `pickup_points` migration, pickup quote/checkout/ready/collect flow, admin `pickup-points/page.tsx` |
| Member-only lifecycle deals | SHIPPED | `MemberDealService`, `member_deals` migration, `GET/POST/PUT /api/admin/member-deals`, quote discount evidence |
| Product notifications | SHIPPED | `StockAlertService` supports `back_in_stock`, `price_drop`, `preorder`; subscribe/unsubscribe/admin routes |
| Search autocomplete | SHIPPED | `GET /api/search/suggest`, `NativeSuggestProvider` query-log ranking |
| Extension dashboard UI | SHIPPED | `/api/admin/extensions/ui`, admin `/extensions` and `/extensions/[id]` embedded views |
| LLM gateway | SHIPPED | `OpenAiCompatibleGateway`, `OllamaGateway`, `PersistentAiCostLedger`, `DatabaseAiAuditWriter`, PII redaction in `LlmPrompt` |
| Merchant copilot queue | SHIPPED (queue + review only) | `AiCopilotService`, `ai_review_queue`, `/api/v2/admin/ai/copilot/*`, admin `/ai-copilot` approve/reject |
| Replenishment hints | SHIPPED | `SmartAggregator::aggregateReplenishment`, `replenishment_hints`, admin inventory card |
| Anomaly alerts | SHIPPED | `SmartAggregator::aggregateAnomalies`, `ops_anomaly_alerts`, ack route, admin Operations card, optional `OPS_ALERT_WEBHOOK_URL` |
| Shipping estimate (PDP) | SHIPPED | `POST /api/storefront/shipping-estimate`, `ShippingEstimateService`, deterministic native fallback with provider-unavailable disclaimer |

## P1 — Core money loops (every vertical reuses)

1. **Gift cards + store credit — ✅ SHIPPED (2026-11-15).** Shared money ledger:
   issue/redeem/track, expiry, partial redeem, refund-to-credit, and admin
   issue/void. Checkout accepts `gift_code` (legacy single code) or
   `gift_codes` (up to five). Remaining balance goes to the configured payment
   provider. Code: `GiftCreditService`, `PlaceOrderService`, `OrderAdminService`.
2. **Split payments — ✅ SHIPPED for multi-gift tender (2026-11-15).** Gift/credit
   codes stack in request order on the shrinking remainder; per-code ledger keys
   keep retries idempotent and the whole tender fails closed. True multi-provider
   tendering (two cards, card + COD) remains out of scope until live gateway
   credentials exist, per `docs/product/SPLIT_PAYMENTS_SPEC.md`.
3. **Click & Collect / pickup points — ✅ SHIPPED (2026-11-15).** Point CRUD,
   public pickup list, cart quote validation, checkout `pickup_point_code`,
   ready/collect fulfillment states, and admin management all exist. Same rails
   serve F&B pickup, pharmacy counters, and B2B will-call.

## P2 — Demand & discovery

4. **Back-in-stock / price-drop / preorder notify — ✅ SHIPPED (2026-11-15).**
   Subscriptions share the `stock_alerts` table with unsubscribe tokens, dedupe,
   best-effort email, and an admin visibility route. Restock and price-drop
   triggers are wired into catalog/inventory paths.
5. **Search autocomplete + suggestions — ✅ SHIPPED (2026-11-15).** Native
   provider ranks the query log with prefix-first matching and returns product
   suggestions plus viewer-tier data. External providers remain pluggable.
6. **Member-only visibility + lifecycle deals — ✅ SHIPPED (2026-11-15).**
   Birthday and tier-window deals are persisted and applied to cart quotes;
   disabled deals stop discounting immediately. Admin CRUD is live.
7. **Shipping estimate (PDP) — ✅ SHIPPED (2026-11-15).** Public deterministic
   estimate with domestic/international rules; when DHL is configured and
   reachable it returns a live quote, otherwise it falls back to native rules and
   says `estimated=true` / `fallback_reason=provider_unavailable`. Never claims a
   live carrier rate it did not receive.

## P3 — Platform surface

8. **Extension dashboard UI — ✅ SHIPPED (2026-11-15).** Saleor-style embedded
   app views are served by `/api/admin/extensions/ui` and rendered at
   `/extensions/[id]`; manifests, enable/disable/uninstall, health, and upgrade
   endpoints exist.
9. ~~**POS/mPOS lite**~~ — REMOVED 2026-10-10: ERP Lite owns POS (no overlap).
   Commerce stays headless for counter sales: ERP POS ingests via the existing
   `POST /api/webhooks/erp/pos` (spec ERP_LITE_API_SPEC §3, idempotent,
   price-from-ERP verified, `source=pos`). Commerce MUST NOT build its own POS UI;
   its POS-side duties are only: keep the ingest webhook plus cart/inventory/
   idempotent-place APIs healthy, and expose pickup-ready states
   (`ready_for_pickup`/`picked_up`) that counter staff (ERP POS or admin) can
   drive.

## P4 — AI and ops intelligence

10. **LLM gateway impls — ✅ SHIPPED (2026-11-15).** `openai`-compatible and
    `ollama` gateways sit behind `AiSuggestionGateway`, with a per-tenant
    `ai_budgets` ledger, `ai_audits`, PII redaction, an 8KB cap, and fail-closed
    transport errors. `AI_PROVIDER=unavailable` is the default closed state.
11. **Merchant copilots — ✅ SHIPPED queue + review (2026-11-15).**
    `ai_review_queue` supports `product_description`, `review_draft`, and
    `catalog_enrichment` tasks; admin `/ai-copilot` can approve/reject. Publishing
    the approved suggestion remains a manual follow-up.
12. **Shopper assistant — PARTIAL.** `BuyerAssistant` exists. Grounding it on
    live catalog + order state and adding conversational search
    (Typesense/Meili + LLM rerank) remain open.
13. **Ops intelligence — ✅ SHIPPED (2026-11-15).** Demand forecast via
    `replenishment_hints` (hourly `smart:aggregate`, 14-day cover target, admin
    inventory card); review sentiment via `SmartLexiconScorer`; anomaly alerts on
    refund/payment/fulfillment failures versus a 7-day baseline with ack flow and
    optional webhook. Remaining work is tuning thresholds, not building the rails.

## References (no-model AI skills, vetted 2026-10)

- FBT co-purchase: `benbogart/frequently_bought_together` (Apriori → static
  lookup table, recomputed periodically), `tectonic-technologies/smart-intent-reco`
  (Smart Intent weighted scoring + FBT + trending — architecture to copy).
- Review mining without LLM: `laddhashreya2000/Review_Analysis` (frequent-feature
  extraction + VADER lexicon scoring per feature). VADER is English-only — Siro
  needs its own VI/EN lexicon (~200 words) for phase 1.
- VI sentiment upgrade path (still local, 0.1B params, MIT):
  `sanvo/vietnamese-sentiment-phobert` (85% acc on VI product reviews),
  `CaoRIV/V-Fashion-Insight` (aspect-based: material/design/size/price/service,
  TF-IDF baselines included). Self-host behind the existing gateway when the
  lexicon hits its ceiling — no cloud dependency required.
- Autocomplete: standard query-log counting (implemented natively); external
  search providers remain optional.

## Non-goals (explicit)

AR/VR try-on, blockchain/NFT commerce, multi-tenant SaaS billing — revisit only
with a paying vertical behind them.

## AI scan (2026-10, repos: Saleor/Bagisto/Medusa)

- Saleor: agentic commerce (ACP/AP2 protocols), Ink AI conversational search
  grounded on inventory/pricing, MCP server, agent skills for devs, Typesense.
- Bagisto: Magic AI (descriptions, reviews, image gen, background remover),
  image-based search, support chatbot, behavior recommendations, demand/inventory
  prediction, sentiment analysis.
- Medusa: loyalty/gift-card/store-credit plugin (open-sourced v2.14), metadata
  attributes as extension seam, subscriber/job patterns for lifecycle deals.
- Local-LLM angle: Ollama-compatible gateways keep data in-house with ~zero
  marginal cost; fits Siro's budget-ledger design (`max_cost_minor` per task).
  Qwen/Llama Vietnamese quality is usable for descriptions/chat with review
  queues; keep cloud fallback for quality-critical tasks.
- Already shipped without relying on external AI: smart autocomplete, rules-based
  replenishment hints, sentiment rollup via lexicon, and anomaly alerts. Next
  no-model wins are threshold tuning and surfacing evidence in the admin, not new
  provider integrations.
