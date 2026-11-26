# Payment Provider Foundation

The payment registry uses canonical IDs: `cod`, `stripe`, `paypal`, `adyen`, `authorize_net`, and `square`. Aliases such as `authorize.net` are normalized before selection.

External providers are fail-closed. `PAYMENT_MODE` must be `sandbox` or `production`; every selected external provider requires its mode-specific credential and webhook secret. No missing or failed external provider request is converted to COD or another provider.

`PaymentTransport` is the test seam for provider HTTP calls. Adapters use a stable idempotency key, redact durable evidence, normalize provider status/reference fields, and classify rejected versus unconfirmed transport outcomes. An unconfirmed operation is persisted as `unknown` and must be reconciled before retrying; it is never treated as a successful payment.

Reconciliation records the provider source, operation ID, check timestamp, normalized state, provider status, reference, and amount/currency comparison result. Missing credentials remain `BLOCKED_EXTERNAL`; local fixtures and transport doubles do not certify live provider connectivity.
