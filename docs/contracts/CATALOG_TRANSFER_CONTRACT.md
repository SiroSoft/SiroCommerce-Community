# Catalog Transfer Contract

Catalog export uses the existing `/api/v2/admin/exports` job surface with `dataset=catalog` and `format=jsonl|csv`. It is schema version `1`, ordered by `updated_at,id`, checkpointed in batches of 100, and contains only `id`, `name`, `slug`, `brand_id`, `status`, `version`, `created_at`, and `updated_at`.

Catalog import is `POST /api/v2/admin/catalog/imports` with `format`, `source`, and optional `validate_only`. JSONL accepts one object per line, or a `{ "schema_version": 1, "record": {...} }` envelope. CSV requires a header. The supported fields are `name`, `slug`, and `status`; unknown fields are row errors.

The parser is streaming and rejects sources over 1,000 rows. Responses include deterministic `rows_seen`, `rows_imported`, row-level `errors`, and a SHA-256 `source_fingerprint`. Validate-only never writes products or a replay record. A repeated non-validation source fingerprint returns the original result with `replayed=true`.
