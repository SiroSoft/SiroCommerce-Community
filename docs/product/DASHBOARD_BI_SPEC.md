# Admin Dashboard BI Spec (free tier)

The dashboard already has basic stat cards. Add actionable commerce signals
using existing reporting/read models — no new data warehouse.

## API

`GET /api/v2/admin/dashboard/bi?range=7d|30d|90d` (admin/owner/finance) returns:
- `sales`: `{gross_minor, orders, aov_minor, refunds_minor, by_day: [{date, gross_minor, orders}]}` (currency-scoped; mixed currencies summed per currency and returned as a list).
- `funnel`: `{sessions, product_views, add_to_cart, checkout_started, orders}` from analytics events (best-effort, zeros allowed).
- `top_products`: top 5 by qty/revenue `{product_id,name,slug,qty,revenue_minor}`.
- `low_stock`: top 10 `{variant_id,name,sku,available}` where available <= 5.
- `ops`: `{failed_payments, pending_refunds, queue_dead_letters, open_ops_alerts}`.
- Cache 60s, fail-closed to zeros instead of 500.

## Admin UI

Dashboard `/` gains:
- Range selector (7/30/90) and refresh.
- Sales trend mini bar chart (CSS, no new chart dependency), AOV/refund cards.
- Funnel bars with conversion % (checkout→order highlighted).
- Top products and low stock tables with links.
- Ops row linking to Operations/Alerts.
- Empty/error states; numbers always formatted with the store currency.

## Tests

Aggregation math (range boundaries, cancelled/failed excluded, refunds),
currency grouping, funnel mapping, low-stock threshold, cache key by range,
endpoint auth, dashboard render with mocked data and failure fallback.

## Non-goals

Cohorts, attribution, forecasting, external BI export (existing exports stay).
