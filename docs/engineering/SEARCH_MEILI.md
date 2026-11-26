# Meilisearch catalog search

Storefront catalog search can run on Meilisearch while admin search and
reporting keep their existing `SearchAdapter`/`HttpSearchTransport` path. The
native SQL search remains the default and the safety net.

## Enable

Backend environment:

```dotenv
CATALOG_SEARCH_PROVIDER=meili
SEARCH_URL=http://meilisearch:7700
SEARCH_API_KEY=<MEILI_MASTER_KEY>
```

Leave `CATALOG_SEARCH_PROVIDER=native` (default) to keep SQL search. If
`CATALOG_SEARCH_PROVIDER=meili` is set but `SEARCH_URL` or `SEARCH_API_KEY` is
missing, the provider reports itself unavailable and search silently stays
native.

In `deploy/docker-compose.yml` the `meilisearch` service uses
`getmeili/meilisearch:v1.12`, a `meilisearch-data` volume, and the
`MEILI_MASTER_KEY` host variable (falling back to a development key). The
backend receives `SEARCH_URL=http://meilisearch:7700` and
`SEARCH_API_KEY=$MEILI_MASTER_KEY`. Port `7700` is bound to `127.0.0.1` only.

## First index

Run inside the backend container or release host:

```bash
php siro search:index-products --fresh
```

`--fresh` deletes and recreates `siro_products` (waiting for Meilisearch
tasks), then applies:

- searchable: `name`, `description`, `tags`
- filterable: `status`, `archived`, `category`, `brand`, `min_tier`,
  `store_id`, `channel_id`, `locale`, `currency`, `price_minor`, `in_stock`
- sortable: `price_minor`, `created_at`

Without `--fresh` the command upserts documents in batches of 500
(`--batch=N` up to 1000) and prints a JSON summary with
`documents_indexed`, `documents_failed`, `batches`, `errors`, and
`settings_applied`. When `CATALOG_SEARCH_PROVIDER` is not `meili` and neither
`--fresh` nor `--force` is passed, the command prints `status: SKIP` and exits 0
so the daily schedule stays quiet on native installs. `--fresh` always runs
when `SEARCH_URL`/`SEARCH_API_KEY` are configured.

## Reindex when the schema changes

Change the document shape or settings in
`app/Modules/Search/Application/CatalogIndexDocumentBuilder.php` /
`CatalogIndexSchema.php`, then run:

```bash
php siro search:index-products --fresh
```

A non-`--fresh` run is an upsert; it does not remove documents for products
that no longer exist and does not apply new settings.

## Freshness and fallback

`routes/schedule.php` runs `search:index-products` daily at 03:23. Product
create/update/delete is **not** hooked synchronously: a search-index HTTP call
must never delay a catalog write. Expect up to one day of staleness unless you
run the command after bulk imports or major catalog edits.

At read time:

- Provider returns ids + estimated total: products are hydrated from SQL by
  those ids, preserving Meilisearch order. Scope, tier, category, price, and
  stock guards are re-applied in SQL; `total` stays the provider total.
- Provider times out (1.5 s), errors, or is unconfigured (`null`): the existing
  native SQL search (including relevance and typo fallback) is used.
- Provider returns a real empty result (`ids: []`): the response is empty. Typo
  fallback is intentionally **not** run so an authoritative provider miss stays
  authoritative.

Facets are computed in SQL over the ids returned for the current page, not over
the full matching set. This is a deliberate phase-1 trade-off; the provider
interface only exposes `ids`/`total`.

## Operations

- **Disk**: the Meilisearch volume holds the engine data plus task history. Watch
  `docker system df` and the volume; prune old tasks if disk pressure appears.
- **Memory**: Meilisearch keeps its index in memory-mapped files. Size the host
  for roughly the index plus headroom (often ~1–2× document text size). Avoid
  hard container memory caps that force OOM restarts.
- **Health**: compose probes `curl -fsS http://127.0.0.1:7700/health`.
- **Startup ordering**: backend waits for the Meilisearch healthcheck, but
  search still degrades to native if Meilisearch disappears at runtime.

## Key security

- Use a long random `MEILI_MASTER_KEY`; the compose default is for local
  development only.
- Keep the key server-side. Never expose it to storefront/admin bundles, logs,
  or tickets.
- Prefer Meilisearch API keys scoped to search/documents for production instead
  of reusing the master key in application env. The master key is required by
  `search:index-products`, so run indexing from a trusted release/ops context.
- Port `7700` must stay on the internal compose network or host loopback. Do
  not publish it through the public reverse proxy.
