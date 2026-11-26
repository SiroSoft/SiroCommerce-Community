> LEGACY (point-in-time evidence, 2026-09-30; not current certification). See [B2C_PROVIDER_CERTIFICATION.md](./B2C_PROVIDER_CERTIFICATION.md).

# B2C Provider Certification Report

**As of:** 2026-09-30 UTC  
**Scope:** shipping, tax, fulfillment, transactional messaging  
**Live certification status:** `BLOCKED_EXTERNAL`

## Local evidence

`composer certify:providers --working-dir backend` returned `LOCAL_PASS` for:

- quote currency and positive rate schema
- tax evidence hash and currency consistency
- deterministic label creation and idempotency
- signed delivery update and replay-safe event identity
- redacted order notification template delivery
- five-attempt retry and dead-letter transition

## Verification evidence

- Backend focused provider/notification tests: 25 passing, 88 assertions.
- Backend fulfillment, notification, API exact-operation, and API contract tests: 183 passing, 578 assertions.
- API contract check: pass, 263 paths.
- Admin/storefront typechecks: pass.
- Admin/storefront focused UI tests: pass, 34 tests each.
- Storefront i18n parity: pass, 5 locales.

## Open gates

- No live shipping, tax, or messaging endpoint evidence was supplied; no live-pass claim is made.
- Full Vitest runs are resource-constrained in this environment; admin hit worker OOM, and storefront also has an unrelated subscription retry assertion failure.
- API handoff has seven unrelated SDK operation gaps: financial documents, reconciliation, and subscriptions.

## Redaction

This report contains no credentials, bearer tokens, webhook signatures, raw provider payloads, customer data, or live response bodies. Live evidence must be attached separately only after redaction and independent provider verification.
