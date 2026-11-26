# Edge Cases — storefront and admin

Boundary behaviour that the CI unit suites pin down today. Rows link to the
owning test; "Known limitation" records what the code deliberately does **not**
guarantee. Coverage floors live in `apps/storefront/vitest.config.mts` and
`apps/admin/vitest.config.mts`; SLO targets are not repeated here.

## Storefront

| Scenario | Expected behaviour | Tested | Known limitation |
| --- | --- | --- | --- |
| Redirect target is the current path, protocol-relative, or external | No redirect; request continues with 200. Open redirects and loops are impossible. | [middleware-redirect-edges.test.ts](../../apps/storefront/src/__tests__/middleware-redirect-edges.test.ts), [middleware.test.ts](../../apps/storefront/src/__tests__/middleware.test.ts) | Redirect lookups are cached in-process per instance (60s); a negative lookup is not shared across replicas. |
| Redirect backend returns 5xx or times out | Pass-through 200; the storefront still renders. | [middleware-redirect-edges.test.ts](../../apps/storefront/src/__tests__/middleware-redirect-edges.test.ts) | Lookup timeout is 1.5s; a slow backend silently loses the redirect until the next request. |
| Sitemap catalog or CMS API fails | Static home, search, category and CMS slug entries still ship. | [sitemap.test.ts](../../apps/storefront/src/__tests__/sitemap.test.ts) | Sitemap is cached with `revalidate = 3600`; deleted products can linger for an hour. |
| Catalog has more products than the sitemap cap | Pages are fetched 100 at a time, duplicate slugs collapse, product URLs stop at 5000. | [sitemap-overflow.test.ts](../../apps/storefront/src/__tests__/sitemap-overflow.test.ts) | Products beyond 5000 are omitted; a sitemap index is not generated. |
| CMS page has no blocks | Falls back to the page `body`; the page still renders. | [cms-page-degraded.test.ts](../../apps/storefront/src/__tests__/cms-page-degraded.test.ts), [cms-blocks.test.tsx](../../apps/storefront/src/__tests__/cms-blocks.test.tsx) | No authoring warning is surfaced to the storefront. |
| CMS block content is malformed JSON | Parsing never throws or executes markup; unsafe media/link targets are dropped. | [cms-page-degraded.test.ts](../../apps/storefront/src/__tests__/cms-page-degraded.test.ts) | Malformed hero JSON is rendered as literal text rather than the fallback title. |
| CMS slug is unknown | Route calls `notFound()`. | [cms-page-route.test.tsx](../../apps/storefront/src/__tests__/cms-page-route.test.tsx) | A streamed response may report HTTP 200 even when the body is the 404 UI; status alone is not a reliable signal. |
| Checkout source is missing in production | `checkout-unavailable` state, disabled Place order, no prefilled buyer or invented order number. | [checkout-fail-closed.test.tsx](../../apps/storefront/src/__tests__/checkout-fail-closed.test.tsx) | The fixture path remains available only when explicitly allowed. |
| Add-to-cart line write returns 5xx | Error surfaces; no replacement cart is created and the cookie is unchanged. | [cart-server-failures.test.ts](../../apps/storefront/src/__tests__/cart-server-failures.test.ts) | Only 404/410 replace a stale cart; a network timeout surfaces to the buyer. |
| Search suggest request is slow or stale | Debounced requests abort after 2s; a late response from an older query is ignored. | [search-suggest-abort.test.tsx](../../apps/storefront/src/__tests__/search-suggest-abort.test.tsx) | Suggestions are best-effort; failures render nothing. |
| VND/JPY money formatting and rounding | Zero-decimal currencies are not multiplied by 100; non-integer/negative minor units throw. | [money.test.ts](../../apps/storefront/src/__tests__/money.test.ts), [money-minor-roundtrip.test.ts](../../apps/storefront/src/__tests__/money-minor-roundtrip.test.ts) | Display rounding is currency-exponent aware; promotional math stays server-side. |
| Facet counts in fixture mode | Counts describe the current filtered result set. | [catalog.test.ts](../../apps/storefront/src/__tests__/catalog.test.ts) | API mode trusts `meta.facets`; counts can be page/selection-scoped depending on the backend provider. |
| Catalog responses are repeated | API catalog result is cached in-process. | [catalog-api.test.ts](../../apps/storefront/src/__tests__/catalog-api.test.ts) | Default TTL is 30s; prices/stock can lag. nginx microcache adds ~10s for public HTML. |

## Admin

| Scenario | Expected behaviour | Tested | Known limitation |
| --- | --- | --- | --- |
| Product CSV is larger than 2MB | Rejected locally with an alert; no import API call. | [import-dialog-edges.test.tsx](../../apps/admin/src/__tests__/import-dialog-edges.test.tsx) | File MIME/extension is not validated client-side; the backend must enforce the CSV contract. |
| Confirm dialog Esc, Cancel, backdrop | Closes without invoking `onConfirm`; focus returns to the trigger. | [confirm-dialog.test.tsx](../../apps/admin/src/__tests__/confirm-dialog.test.tsx) | Pending destructive actions disable Cancel/Esc in the component; callers must pass `isPending`. |
| Theme import file with corrupt JSON | Alert "Import file is not valid JSON."; draft and save mutation untouched. | [theme-import-edges.test.tsx](../../apps/admin/src/__tests__/theme-import-edges.test.tsx) | Validation is shape-only; imported config keeps the current draft version and still needs an explicit save/publish. |
| Product/page save returns 409 | Conflict state is shown; no silent overwrite. | [pages-builder.test.tsx](../../apps/admin/src/__tests__/pages-builder.test.tsx), [admin-qc.test.tsx](../../apps/admin/src/__tests__/admin-qc.test.tsx) | The editor must reload to get the latest version; there is no automatic merge. |
| Pagination `page > last_page` | Clamped to `last_page`; next is disabled; page buttons stay in range. | [pagination-edges.test.tsx](../../apps/admin/src/__tests__/pagination-edges.test.tsx) | Clamping is display-only; queries still request the URL page until the caller updates it. |
| Access token returns 401 | Refresh runs once, the request replays once with the rotated token, and the new refresh token is persisted. | [auth-refresh.test.ts](../../apps/admin/src/__tests__/auth-refresh.test.ts) | If another tab rotates the refresh token first, this tab logs out instead of racing the rotation. |
