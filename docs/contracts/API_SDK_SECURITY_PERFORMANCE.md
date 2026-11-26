# API SDK, Performance, And Security Contract

## Compatibility metadata

`GET /api/version` is the discovery endpoint. It reports the commerce/core versions, contract schema version, current `v1` API, supported `v1` and `v2` APIs, page pagination, and the existing response-envelope error format. The endpoint is additive and does not alter existing resource payloads.

`backend/scripts/generate-openapi.php` derives route evidence into `backend/docs/openapi.json` and `backend/public/openapi.json`. `backend/scripts/generate-sdk.php` then generates the client-safe TypeScript request client at `backend/generated/sirocommerce.ts`; generated artifacts are never hand-edited. `backend/scripts/check-api-contract.php` verifies route/spec parity, public-doc synchronization, required compatibility metadata, and SDK presence.

Each operation includes `x-api-version`, `x-client-contract`, deprecation status, detected auth/capability gates, rate-limit metadata, path parameters, and bounded `page`/`per_page` query parameters. Search advertises its supported type facet and 20-second eventual index-lag budget. Reporting/export reads advertise explicit `store_id`/`channel_id` scope. Errors use the stable envelope and `meta.error_code`/`meta.errors` fields; GraphQL errors remain the documented `{errors:[{code,message}]}` contract. Validation failures use `VALIDATION_ERROR`; authentication, authorization, not-found, conflict, and unexpected failures use `UNAUTHORIZED`, `FORBIDDEN`, `NOT_FOUND`, `CONFLICT`, and `INTERNAL_ERROR` respectively. Version metadata identifies the generated TypeScript client and OpenAPI artifacts.

Run `php scripts/generate-openapi.php` and then `php scripts/generate-sdk.php` from `backend` whenever routes or schemas change. Run `php scripts/check-api-contract.php` in the release gate. Generated documents must be reviewed as compatibility artifacts, not treated as permission configuration.

## Security boundaries

- JWT requests require an active user and matching live `token_version`; role and capability middleware remain fail-closed.
- Storefront and admin routes retain frontend and endpoint-specific throttles. GraphQL is independently bounded at 15 requests/minute, with an 8-level depth and complexity limit.
- Customer search returns masked email addresses. Resources and headless selections do not expose storage paths, access keys, or digital-asset origin URLs.
- Media URLs are restricted to HTTP(S), and download access uses issued tokens. Webhooks verify the raw body signature before parsing and are idempotent.

## Performance evidence

Catalog, search, reporting, headless, and checkout reads must use bounded pages/windows and be checked with the existing PHPUnit integration suite and `perf/k6` scenarios. Existing reporting/search cache TTLs and indexes are retained. No new index, cache, or eager-load is added without a query-plan or response-compatibility test. The owned regression gate is `php vendor/bin/phpunit --testsuite unit --filter PhaseV6HardeningTest --no-coverage`, followed by focused integration/API suites and `php vendor/bin/phpstan analyse --level=max --no-progress`. k6 output is production evidence only when captured against a real provider-backed environment; local/unit results do not substitute for that evidence.

## Explicitly unsupported in this phase

- GraphQL mutations and arbitrary GraphQL introspection are unsupported; `/api/headless/graphql` is a bounded read-only allowlist.
- A generated SDK for languages other than TypeScript is unsupported; consumers can use the generated OpenAPI document.
- Strong read-after-write guarantees for cached/search read models are unsupported; the contract is eventual consistency with a documented search-index lag budget.
