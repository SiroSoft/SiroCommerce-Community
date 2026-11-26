> LEGACY (point-in-time audit record; not current capability evidence). See [docs/README.md](../README.md).

# Product Type Runtime Audit

Baseline: V8 backend capability completion working tree.

## Implemented

- Product type metadata: simple, configurable, grouped, bundle, virtual and
  downloadable.
- Bundle component pricing expands component quantities into an immutable quote
  line snapshot.
- Bundle inventory reservation can reserve every component and rolls back
  earlier reservations when a later component is unavailable.
- Download delivery tokens are random, hashed at rest, expiring and optionally
  limited by download count.
- Checkout detects bundle cart lines, snapshots component pricing, and reserves
  every component quantity before committing the order.
- Fixed bundle pricing is explicit and remains in integer minor units.
- Digital asset expiry and asset-level download limits are inherited by issued
  order tokens.
- Download slot consumption has an atomic compare-and-increment repository
  operation to prevent concurrent limit bypass.
- Delivery requires an explicit `malware_scan_status=clean` and scan timestamp;
  pending, missing, revoked or expired assets fail closed.
- Ranges are strict `bytes=start-end` requests and require a provider implementing
  `SignedDigitalDeliveryTransport`; invalid provider range metadata is rejected.
- Signed provider URLs are accepted only over HTTPS with a bounded future expiry.
- Download evidence retains an append-only attempt list with token/order/asset
  identifiers and consumption time; token material is never recorded.

## Residuals

- Bundle substitutions and warehouse-aware component allocation are not yet
  implemented; unsupported substitutions fail closed.
- Promotions on fixed-price bundles use the existing order-level promotion
  contract; component-level promotion allocation remains unsupported.
- Protected byte delivery is intentionally fail-closed. The API never returns
  the configured origin URL; a signed URL/stream transport is still required
  before downloadable products are advertised as delivery-ready.
- Bundle component substitutions and warehouse-aware component allocation are
  not yet implemented.

## Evidence

- Focused product-type and checkout tests pass, including fixed-price bundles,
  bundle reservation paths, rollback coverage, and fail-closed digital delivery.
- API coverage: 91 route declarations have test references.
- All `commerce:check` gates PASS.
