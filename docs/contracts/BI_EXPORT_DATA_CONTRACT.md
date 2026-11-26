# BI Export Data Contract

BI exports are capability-gated admin operations under `/api/v2/admin/exports`.

- Datasets: `orders`, `payments`, `refunds`, `subscriptions`, `b2b`, `promotions`, `fulfillment`, `inventory`.
- Formats: `jsonl` and `csv`.
- Schema version: `1`; clients must reject unsupported versions.
- Date windows are inclusive and limited to 366 days. A snapshot timestamp is captured when the export is queued.
- Jobs process at most 100 rows and persist the last date/id cursor before queuing the next batch.
- JSONL records contain `schema_version` and `record`; CSV has a stable header and quoted fields.
- Customer names, email addresses, guest email, addresses, payment provider references, tokens, credentials, raw evidence, and coupon codes are excluded.
- Status and download endpoints expose metadata and a storage URL only after completion. Storage paths and failure details are never exposed.
- Failed jobs can be replayed with `POST /api/v2/admin/exports/{id}/retry`; the stored cursor and file are retained.
