# Production Operations

SiroCommerce uses SiroPHP's database queue for slow external work. Return-label requests persist `label_state=queued` and return immediately; the worker changes it to `processing` before calling the carrier with the original idempotency key. Provider failures are retried by SiroPHP up to five attempts, then remain visible as `failed` for operational retry.

## Queue worker

From `backend/`, configure the documented database queue settings:

```env
QUEUE_DRIVER=database
QUEUE_RETRY_AFTER=60
QUEUE_MAX_ATTEMPTS=5
```

Run one job while validating a release, or supervise the daemon in production:

```bash
php siro queue:work
php siro queue:work --daemon
php siro queue:status
php siro queue:retry <id>
```

The handler is idempotent: a completed label is never sent again, and carrier calls use the durable `Idempotency-Key` value.

## Optimization checks

Use SiroPHP's documented production checks after changing environment values:

```bash
php siro optimize
php siro env:check
php siro config:cache
```

Do not run `config:cache` until production secrets and queue settings are present. Clear the cache with `php siro config:clear` after changing them.

## Provider preflight and smoke safety

From `backend/`, run the configuration-only preflight before enabling traffic:

```bash
php scripts/provider-preflight.php
php scripts/provider-preflight.php > provider-preflight.json
```

It prints only provider names, modes, readiness, and missing variable names. It never prints secret values and never performs a live provider request. Production fails closed unless payment production mode, selected-provider runtime credentials, DHL API mode, an HTTPS DHL endpoint, and webhook secrets are present. The credential names are the names consumed by the adapters: PayPal uses `PAYPAL_ACCESS_TOKEN`, Adyen uses `ADYEN_API_KEY`, and Authorize.Net uses `AUTHORIZENET_TRANSACTION_KEY` (the `PAYMENT_*` aliases are also accepted). Live smoke tests must use disposable provider-issued credentials in an isolated merchant account; never put credentials in command arguments, CI logs, evidence, or fixtures. A preflight pass does not prove provider account, webhook, or reconciliation health.

For an opt-in provider contract check, configure provider-owned HTTPS health
URLs in `PAYMENT_LIVE_SMOKE_URL`, `TAX_LIVE_SMOKE_URL`,
`SHIPPING_LIVE_SMOKE_URL`, and `SEARCH_LIVE_SMOKE_URL`, plus inbound webhook
URLs in `PAYMENT_LIVE_SMOKE_WEBHOOK_URL`, `TAX_LIVE_SMOKE_WEBHOOK_URL`, and
`SHIPPING_LIVE_SMOKE_WEBHOOK_URL`, then run
`PROVIDER_LIVE_SMOKE=1 php scripts/provider-live-smoke.php`. Missing URLs are
`BLOCKED`; disabled or missing-credential smoke is `NOT_PROVEN`. Health fixtures
must contain the configured `provider`, `protocol: health`, `status: healthy`,
and `authenticated: true`. Webhook fixtures must contain the configured
`provider`, `protocol: webhook`, a non-empty `event_id`, `accepted: true`, and
`authenticated: true`. Requests use bearer credentials and signed webhook
fixtures; a generic HTTP 2xx response is never a provider PASS. Search has no
inbound webhook contract and is reported `NOT_APPLICABLE`. The result records
only the endpoint host, contract provider, status, latency, and body size.
Response bodies and credentials are never retained.

`/health/ready` includes database, queue, outbox, unknown-payment, and provider readiness. Production configuration failures return HTTP 503 and list missing variable names only.

## API observability contract

Every routed API and health response carries `X-Correlation-ID`. A caller may
provide a value matching `^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$`; unsafe or oversized
values are replaced with a generated `cor_<32 hex>` value. The same ID is used
for request-scoped evidence. `X-Request-Id` and `X-Siro-Trace-Id` are framework
request/trace identifiers and are not substitutes for the correlation ID.

API errors remain the standard `{success:false,message,meta}` envelope. Internal
failures expose only a stable public message and error code; dependency details,
credentials, tokens, signatures, and provider response bodies are not returned.
Readiness is `200` only when all dependency probes pass; a missing table,
unavailable database, stale search index, unresolved operational failure, or
missing production provider configuration returns `503`. Readiness evidence
contains timestamps and probe labels only, never secret values.

## Admin Reporting And Search

V4 reporting is read-only and bounded. Use `/api/v2/admin/reports/overview` for
orders, sales, refunds, inventory and failure aggregates, and
`/api/v2/admin/reports/failures` for a paginated failure list. Use
`/api/v2/admin/search` with `type=catalog|orders|customers`; pages are capped at
100 results and customer email values are masked. These endpoints require the
`reports` or `search` capability and the normal bearer token and FE gate.

## Backup and restore proof

Run the non-destructive rehearsal from `backend/`:

```powershell
php scripts/production-proof.php --out=storage/evidence/<run-id> --backup-created-at=<UTC ISO-8601>
```

Review `manifest.json`. It contains checksums for uploads, config, schema, and
version metadata; database row counts and invariants; and measured RPO/RTO
status. `BLOCKED` or `NOT_PROVEN` is an operational failure, not a successful
restore. The command never overwrites the live database or calls a provider.
MySQL restore, object-storage restore, and F01-F11 injection drills must run in
isolated environments and be recorded with their actual evidence. See
`docs/operations/PRODUCTION_PROOF_RUNBOOK.md`.
