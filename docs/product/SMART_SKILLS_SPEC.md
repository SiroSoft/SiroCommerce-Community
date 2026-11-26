# Smart Skills Spec — AI-like behavior, zero models

Status: SPEC. Rules: deterministic, explainable, privacy-safe, measurable.

## 0. Shared foundation

- **Batch, never realtime**: nightly `smart:aggregate` console command recomputes
  all rollups. Storefront/APIs only READ precomputed tables (p95-safe).
- **Tables** (all `smart_*`, migration-owned):
  - `smart_affinities(product_a, product_b, orders, score)` — unordered pair key
  - `smart_search_terms(query normalized, submits, clicks)` — query text logged
  - `smart_review_rollups(product_id, rating_avg, rating_count, pos_count,
    neg_count, aspects_json, excerpts_json, computed_at)`
- **Privacy**: normalized queries only (lowercase, ≤64 chars, reject emails/
  phones/URLs/digits-long); no per-user storage in aggregates; analytics consent
  gate unchanged.
- **Cold start**: every consumer has a non-smart fallback (bestsellers,
  catalog order, plain rating average). Zero-data installs look identical.

## 1. Autocomplete (ship first)

- **Log**: `search_submit` gains optional `query` prop (validated like other
  props; rejected when PII-shaped). New `search_click` event
  `{query, product_id, position}` from the search UI.
- **Rollup**: group by normalized query → submits + clicks.
- **Serve**: `GET /api/search/suggest?q=` returns top 8 by
  `clicks*3 + submits`, prefix match, ≤50ms (single indexed query).
- **UI**: dropdown under search input (keyboard navigable, aria-activedescendant),
  debounced 150ms, abort stale.
- **Measure**: suggest CTR (clicks/impressions via existing events).

## 2. Frequently bought together

- **Rollup**: pairs of distinct variants co-occurring in the same non-cancelled
  order (count once per order). Score = pair orders (min 2 to display).
- **Serve**: `GET /api/products/{id}/affinities` → top 4 with product cards.
- **UI**: PDP section "Frequently bought together" with per-item add +
  "Add all to bag". Explainable subtitle ("X customers bought these together").
- **Measure**: block CTR + attach rate (lines added from block / block views).

## 3. Review summary (lexicon, with guardrails)

- **Lexicon**: `SmartLexicon` — ~200 EN + ~200 VI positive/negative words,
  negation window (not/không + next 3 tokens flips), stored as code (versioned,
  reviewable — never DB-editable at runtime).
- **Rollup per product**: rating avg/count (approved reviews only), pos/neg
  counts, aspect buckets from fixed aspect lists per locale
  (quality/comfort/size/price/delivery…), 1 representative excerpt per side
  (highest |score|, ≥10 chars, no PII).
- **UI**: summary card above review list: stars, counts, aspect chips with
  pos/neg split, excerpts clearly quoted. Never invents text — everything shown
  is a count or a verbatim quote.
- **Moderation**: rollup recomputed only from `approved` reviews; excerpts
  re-validated on read (length, no URLs/emails).
- **Measure**: time-on-PDP, helpful-vote rate (future), CSH deflection (manual).

## 4. Test matrix (mandatory per skill)

| # | Test |
|---|---|
| 1 | Rollup math on fixture orders/reviews/queries (exact counts) |
| 2 | Cold start: empty tables → fallback, no errors |
| 3 | Privacy: PII-shaped queries rejected; excerpts contain no emails/URLs |
| 4 | Idempotent re-rollup (same input → same output, no dup rows) |
| 5 | Serve endpoints: shape, limits (top 4/8), latency budget comment |
| 6 | UI: keyboard + landmarks + locale parity (nested-missing stays 0) |

## 5. Upgrade path (no rewrites)

- Lexicon → PhoBERT microservice later: rollup reads through
  `ReviewScorer` interface; lexicon is the default impl.
- Co-occurrence → Apriori/FP-growth when catalog is large: same
  `smart_affinities` table, only the writer changes.
- Query counting → vector search later: `suggest` keeps its contract.
