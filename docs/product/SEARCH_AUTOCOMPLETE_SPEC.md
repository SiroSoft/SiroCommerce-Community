# Search Autocomplete Spec (P2.5 — as-you-type suggestions)

Pluggable provider behind one contract; native ships first, Meili/ES later
through the existing `HttpSearchTransport`. Query-log counting feeds ranking.

## Contract (`SuggestProvider`)

`App\Modules\Smart\Contracts\SuggestProvider::suggest(string $q, int $limit): array{queries: list, products: list}`.
`NativeSuggestProvider` (default):

- Normalize via `SmartQuery::normalize` (lowercase, collapse ws, ≤64, PII
  stripped). Empty/invalid → `{[], []}`.
- Queries: `smart_search_terms` prefix match first (`q%`), then contains
  (`%q%`) excluding prefix hits, each `ORDER BY (clicks*3+submits) DESC`,
  total ≤ 8. Typo tolerance = normalized contains (no edit-distance in v1).
- Products: active, non-archived products with `LOWER(name) LIKE %q%`,
  tier-gated by viewer tier (`min_tier`, 404-semantics = hidden), `LIMIT 4`,
  each `{product_id, variant_id, name, slug, price_minor, currency, image}`.
- Fail-closed: any throw → `{[], []}` (same as cold start).

## Endpoint

`GET /api/search/suggest?q=` keeps `data: queries[]` shape (backward
compatible with `SearchSuggest.tsx`) and adds `products: [...]` alongside.
`q` length 1..64 (server accepts 1 char; UI still debounces at ≥2).
`$fe` middleware (public throttled read). Adds `OptionalAuthMiddleware` so
signed-in members get tier-gated product suggestions.

## Query-log counting

- `smart:aggregate` runs on the scheduler (hourly, `withoutOverlapping`)
  alongside `commerce:scheduled` so `smart_search_terms` stays fresh without
  manual runs.
- `POST /api/analytics/track` already logs `search_submit/search_click`;
  `SearchSuggest` keeps firing both (impression = submit on Enter, click =
  click with position). No per-keystroke logging (debounce + min-2 chars
  bound the write rate).

## Storefront / Admin

- `SearchSuggest.tsx`: renders product cards (image, name, price) under query
  suggestions; keyboard nav covers both lists; click tracks `search_click`.
- Admin search-seo Terms tab unchanged (`search_terms` table). No new admin
  page in v1 (insights reuse existing).

## Non-goals

No Meili/Algolia/Typesense wiring, no FTS index, no edit-distance fuzzy, no
per-store/channel scoping of `smart_search_terms`, no synonyms in suggest.
