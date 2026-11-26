# Marketplace Contract Handoff

Marketplace capabilities are contract-safe and scoped by `X-Tenant-Id`, `X-Seller-Id`, `X-Store-Id`, `X-Channel-Id`, `X-Locale`, and `X-Currency`. Query/header disagreements are rejected. Administrative mutations require `Idempotency-Key`; request replay with a different fingerprint returns `409 CONFLICT`.

Implemented surfaces:

- Vendor onboarding, profile optimistic versioning, status transitions, and profile audit events.
- Vendor catalog ownership and `draft|visible|hidden` visibility per store/channel.
- Minor-unit commission ledger with rate basis points, immutable request fingerprint, and tenant/vendor isolation.
- Seller-order split read model with idempotent replay and scoped pagination.
- Payout metadata state machine: `pending -> eligible|held`; processing and paid remain `503 BLOCKED_EXTERNAL` because no live payout provider is configured.
- Existing vendor audit and JSON export endpoints remain scope-filtered; marketplace mutations append audit events.

Provider payout/payment execution is `BLOCKED_EXTERNAL`, not simulated. Required external prerequisite: provider credentials, webhook verification, and reconciliation implementation. Suggested gate: `php scripts/provider-preflight.php`.
