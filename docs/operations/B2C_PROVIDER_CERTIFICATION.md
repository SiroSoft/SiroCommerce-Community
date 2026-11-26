# B2C Provider Certification

Scope: shipping, tax, fulfillment, and transactional messaging only.

## Local certification

Run `composer certify:providers --working-dir backend`. The command uses the deterministic DHL sandbox transport and redacted in-process fixtures for quote, tax evidence, label/tracking, delivery update, order notification, and retry/dead-letter behavior.

`LOCAL_PASS` proves the application contract only. The result always carries `certification: BLOCKED_EXTERNAL`; it is not a live provider claim.

## Release gates

- `APP_ENV=production` rejects sandbox DHL mode and incomplete DHL HTTPS credentials.
- `TAX_PROVIDER=native`, a supported destination, matching currency, active tax rule, and `evidence_hash` are required for production tax calculation.
- Shipping rates and tax lines must use the order currency; malformed, unknown, or provider-regressed statuses fail closed.
- Shipping webhook requests require the provider HMAC over the raw body. The event ID and payload hash are durable; a changed replay is rejected.
- Label creation and provider operations require an idempotency key. Tracking transitions cannot regress delivery state.
- Transactional delivery retries five times, stores only a redacted provider error, and transitions to `dead` with a dead-letter timestamp.
- Health readiness includes provider configuration and transactional message mode. Public health responses do not expose secrets or provider response bodies.

## Live evidence

Live certification is external and must be captured separately with provider-issued test references, UTC timestamps, endpoint identity, and redacted responses. Never put credentials, bearer tokens, signed payloads, raw customer data, or provider response bodies in the report. A missing live endpoint, credential, webhook secret, or independent evidence remains `BLOCKED_EXTERNAL`.
