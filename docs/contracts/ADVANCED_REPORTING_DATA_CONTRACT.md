# Advanced Reporting Data Contract

`GET /api/v2/admin/reports/advanced` is a capability-gated, read-only reporting contract. It accepts `dimension` values `profitability`, `cohorts`, `retention`, `repeat-purchase`, `attribution`, `sla`, `inventory-turnover`, and `widgets`, plus inclusive `from`, `to`, `store_id`, and `channel_id` parameters.

- Windows are limited to 366 days, reads are capped at 10,000 source rows, and all responses carry `schema_version`, scope, window, and deterministic snapshot/version evidence.
- Responses are private-cacheable for 30 seconds and include `ETag`, `X-Report-Schema`, and `X-Report-Snapshot`; matching `If-None-Match` returns `304`.
- Customer identifiers are aggregated only; no names, emails, addresses, tokens, provider references, or raw payloads are returned.
- COGS, margin, LTV, retention, churn, source attribution, and SLA are returned as explicit unavailable dimensions when their evidence is not persisted. No financial fact is inferred from sale price, inventory quantity, or event type.
- Widget output is registry-based and fixed to persisted evidence. Unknown or future widgets must remain unavailable rather than trigger arbitrary query execution.
