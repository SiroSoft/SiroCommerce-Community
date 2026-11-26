# FE Reporting, Analytics, and BI Exports

Status: `FE workflow contract`

## Screens and access

| Screen | Roles/capabilities | Data rule |
|---|---|---|
| Overview dashboard | `admin,owner,finance` + `reports` | Scope and snapshot shown |
| Advanced reports | `admin,owner,finance` + `reports` | Aggregated identifiers only |
| Failure report | `admin,owner,finance` + `reports` | Redacted operational evidence |
| Analytics event explorer/funnel | `admin,owner,marketer` + `analytics` | Paginated/event contract only |
| Export builder/jobs | `admin,owner,finance` + `exports` | Dataset, format, inclusive window, scope |
| Export download | Same authorized export scope | Only completed job storage URL |

## Route mapping

Mapped operation count: **13**.

| Capability | Route | operationId |
|---|---|---|
| Analytics events/export/funnel | `GET /api/admin/analytics/events`, `GET /api/admin/analytics/export`, `GET /api/admin/analytics/funnel` | `get_api_admin_analytics_events`, `get_api_admin_analytics_export`, `get_api_admin_analytics_funnel` |
| Overview/advanced/failures | `GET /api/v2/admin/reports/overview`, `/advanced`, `/failures` | `get_api_v2_admin_reports_overview`, `get_api_v2_admin_reports_advanced`, `get_api_v2_admin_reports_failures` |
| Export job lifecycle | `POST /api/v2/admin/exports`, `GET /api/v2/admin/exports/{id}`, `GET /api/v2/admin/exports/{id}/download`, `POST /api/v2/admin/exports/{id}/retry` | `post_api_v2_admin_exports`, `get_api_v2_admin_exports_id`, `get_api_v2_admin_exports_id_download`, `post_api_v2_admin_exports_id_retry` |
| Search/reindex status | `GET /api/v2/admin/search`, `POST /api/v2/admin/search/reindex`, `GET /api/v2/admin/search/status` | `get_api_v2_admin_search`, `post_api_v2_admin_search_reindex`, `get_api_v2_admin_search_status` |

## Reporting contract

Advanced reports accept dimensions `profitability`, `cohorts`, `retention`,
`repeat-purchase`, `attribution`, `sla`, `inventory-turnover`, and `widgets`,
with inclusive `from/to`, `store_id`, and `channel_id`. Windows are limited to
366 days and source rows to 10,000. Render `schema_version`, scope, window, and
snapshot/version evidence. Honor `ETag`; `304` keeps the current result and
timestamp rather than showing a blank dashboard.

Unavailable dimensions are explicit unavailable data, not zero. Do not infer
COGS, margin, LTV, retention, churn, attribution, or SLA from other metrics.

## Export state machine

UI states: `idle -> submitting -> queued -> processing -> completed`, with
`failed`, `retrying`, `expired`, `forbidden`, `conflict`, and `cancelled` display
branches. The data contract says jobs process at most 100 rows per batch, persist
the last date/id cursor, capture a snapshot timestamp at queue time, and retain
the cursor/file when replayed after failure.

Use `jsonl` or `csv`, schema version `1`, and an inclusive date window of at most
366 days. Poll `GET /api/v2/admin/exports/{id}` with bounded backoff only while
the status is non-terminal. The contract does not define a polling interval;
honor `Retry-After`. Call download only after completed status and treat absent
storage URL as not-ready. The FE may display metadata but never storage paths,
failure internals, PII, provider references, tokens, credentials, raw evidence,
or coupon codes.

The export create/retry operations are marked optional idempotency in OpenAPI.
Use a stable key for the user action where supported, but do not promise replay
semantics beyond the contract. A retry is available only for a failed job and
must reconcile job status first.

## Acceptance tests

1. A dashboard request includes the selected scope and renders snapshot evidence.
2. `304` retains the previous valid report without a loading/error flash.
3. Unsupported dimensions render `unavailable`, never a fabricated zero.
4. A 366-day boundary succeeds and an oversized window renders validation.
5. Export progress polls only the status endpoint, stops at completion/failure,
   and downloads only a completed job.
6. Failed export retry preserves the job's cursor/file semantics and does not
   create duplicate downloads or expose failure details.
7. `403`, `429`, schema-version mismatch, expired job, and scope mismatch each
   have explicit recovery UI.

## Unresolved gaps

- Export/report/search capability metadata is generated from route middleware
  variables and is covered by the API contract parity gate. A future route
  middleware change must regenerate OpenAPI before FE authorization metadata is
  considered current.
- Exact report response schemas, export status enum, expiration policy, and
  authorization ownership rules are not fully published.
- Analytics export semantics and search reindex progress/repair behavior are not
  defined; the FE must not invent them.
