# Shipping and Tax Integration Operations

Shipping and tax adapters are evidence boundaries. Sandbox fixtures are valid for local tests only and are never live-provider certification.

## Production configuration

Production requires `APP_ENV=production`, `DHL_MODE=api`, an HTTPS `DHL_API_URL`, `DHL_API_KEY`, `DHL_API_SECRET`, and `DHL_WEBHOOK_SECRET`. The container refuses the sandbox transport in production. Missing credentials remain `BLOCKED_EXTERNAL`; no synthetic pass is emitted.

Tax calculation fails closed in production when the destination country or an active matching tax rule is absent. The default zero-tax adapter is local/test-only.

## Failure behavior

DHL retries only timeouts, transport failures, 408/425/429, and 5xx responses. Definitive provider rejections are not retried. Repeated transient failures open the in-process circuit and return reconciliation-required evidence with an error class. Shipment creation remains idempotent and queued operations may be retried safely.

Tracking and webhook statuses are normalized to the internal shipping vocabulary. Unknown provider statuses remain `unknown`; they are not promoted to a successful delivery state. Webhooks require the provider HMAC signature and event ID for replay-safe evidence.
