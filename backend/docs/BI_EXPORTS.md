# BI Exports

The admin export surface is provider-neutral and reads only persisted commerce records. Supported datasets are `orders`, `payments`, `refunds`, `subscriptions`, `b2b`, `promotions`, `fulfillment`, and `inventory`.

Exports accept a bounded inclusive date window of at most 366 days and are queued through the existing `BiExportJob`. Each export stores a schema version, snapshot time, row count, and `(cursor_at, cursor_id)` checkpoint. A batch is ordered by its persisted timestamp and stable identifier, so a worker can resume without offset pagination.

JSONL records contain `schema_version` and `record`; CSV emits an allowlisted header and escaped values. The select lists intentionally exclude customer identifiers, email addresses, payment references, shipping tracking, and other direct contact or provider-secret fields. No provider-specific payload is exported.

Only admin, owner, and finance roles with the `exports` capability can create, inspect, download, or retry an export. Status metadata never exposes storage paths or failure details. Download metadata is returned only after completion.

When a storage write succeeds before its checkpoint update, replay recognizes the already-written batch and advances the checkpoint without appending duplicate records. Failed exports can be retried; completed, failed, and running jobs are not processed as new work.
