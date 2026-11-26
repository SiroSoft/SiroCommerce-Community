> LEGACY (point-in-time audit record; not current capability evidence). See [docs/README.md](../README.md).

# Variant and Shipping Audit

Audit scope: V2-14 variant pricing and DHL shipping round (`760f09b`).

## Findings

| ID | Severity | Finding | Resolution | Status |
|---|---|---|---|---|
| VS-01 | High | DHL sandbox transport was the default binding, which could silently issue fake shipment results outside local development. | Provider selection now fails closed when production is not configured; sandbox is selected only for non-production environments or explicit `DHL_MODE=sandbox`. | Fixed |
| VS-02 | High | Shipment creation had no durable idempotency evidence even though the evidence table existed. | Added shipping evidence store, replay-safe create responses and webhook event deduplication. | Fixed |
| VS-03 | Critical | DHL webhook accepted unsigned payloads and did not require an event ID. | Added `DHL_WEBHOOK_SECRET` HMAC verification and required `event_id`; duplicate events replay stored evidence. | Fixed |
| VS-04 | High | Variant option values were not checked against the product owning the variant. | Added product ownership validation before variant creation. | Fixed |
| VS-05 | Medium | Variant combination comparison depended on database/input ordering. | Combination sets are normalized before comparison. | Fixed |
| VS-06 | Medium | Inactive variants could still resolve a price, and negative/invalid variant prices were not rejected. | Price resolution now ignores inactive variants and rejects negative price or invalid currency data. | Fixed |

## Residual Scope

- A real DHL HTTP transport using production credentials is not configured in this repository; production mode fails closed until one is supplied.
- Shipment records are provider-evidence-backed but are not yet linked to a fulfillment/order aggregate.
- UPS, FedEx and local carrier adapters remain future work behind the existing carrier contract.
- DHL signature verification currently requires the configured `x-dhl-signature` HMAC format; provider-specific signature formats must be mapped when live credentials are enabled.

## Evidence

- `105 tests, 398 assertions`.
- `commerce:check --continue --json`: all checks PASS.
- API coverage: 86 route declarations have test references.
