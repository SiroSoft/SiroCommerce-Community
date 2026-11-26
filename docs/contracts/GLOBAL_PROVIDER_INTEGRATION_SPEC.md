# Global Payment and Shipping Provider Specification

Version: `V1.0`
Status: implementation baseline
Scope: global payment and parcel shipping integrations for SiroCommerce.

## 1. Goals

- Support five global payment providers through one payment contract.
- Support five international shipping providers through one shipping contract.
- Keep provider SDKs, credentials, status names and failure behavior outside
  the commerce domain.
- Make every external operation retry-safe and auditable.
- Never expose sandbox behavior as production behavior.
- Preserve the current integer-money, immutable-snapshot and idempotency rules.

## 2. Provider Matrix

### 2.1 Payment Providers

| Provider | Primary markets | Required capabilities | Initial mode |
|---|---|---|---|
| Stripe | Global | PaymentIntent, capture, refund, 3DS/SCA, disputes, webhooks | Sandbox then production |
| PayPal | Global | Order create, approve, capture, void, refund, webhooks | Sandbox then production |
| Adyen | Global/enterprise | Authorize, capture, cancel, refund, 3DS, risk result, webhooks | Test then production |
| Authorize.Net | North America | Authorize/capture, refund, void, customer profile, webhooks | Sandbox then production |
| Square | US/CA/AU/JP/GB | Payment, refund, idempotency, webhooks | Sandbox then production |

`Authorize.Net` is a payment provider, not a shipping carrier.

### 2.2 Shipping Providers

| Provider | Primary markets | Required capabilities | Initial mode |
|---|---|---|---|
| DHL Express | Global/international | Rates, label, cancel, track, customs, webhook | Sandbox then production |
| UPS | Global | Rates, label, cancel, track, customs, webhook | Sandbox then production |
| FedEx | Global | Rates, label, cancel, track, customs, webhook | Sandbox then production |
| USPS | US | Rates, label, cancel, track, webhook | Sandbox then production |
| Aramex | Middle East/APAC/global | Rates, label, cancel, track, customs, webhook/polling | Sandbox then production |

For Vietnam-local operations, add `Viettel Post` or `GHN` as a separate
adapter. It must not change the global shipping contract.

## 3. Architecture

```text
Checkout / Admin / Fulfillment
          |
PaymentService       ShippingService
          |                 |
PaymentProviderRegistry  ShippingProviderRegistry
          |                 |
Provider adapters       Provider adapters
          |                 |
External API / SDK / HTTP transport
```

Rules:

- Application services depend only on contracts.
- Provider adapters map external request/response/status formats.
- HTTP, SDK, authentication and retry code stay in infrastructure.
- Provider-specific fields are stored as redacted evidence, not domain state.
- A provider is selected by explicit `provider` configuration or request input.
- Unknown or disabled providers fail with a stable configuration error.
- Production never falls back to sandbox.

## 4. Payment Contract

### 4.1 Operations

```php
interface PaymentProvider
{
    public function authorize(PaymentRequest $request, string $key): PaymentResult;
    public function capture(string $providerPaymentId, int $amountMinor, string $key): PaymentResult;
    public function void(string $providerPaymentId, string $key): PaymentResult;
    public function refund(string $providerPaymentId, int $amountMinor, string $key): PaymentResult;
    public function verifyWebhook(string $rawBody, array $headers): bool;
    public function normalizeWebhook(array $payload): PaymentWebhook;
}
```

Required request fields:

- `payment_id`
- `order_id`
- `amount_minor`
- `currency`
- `customer_id` or guest contact
- `return_url` when redirect approval is required
- billing address
- shipping address when risk checks need it
- payment method token, never raw card data

Required result fields:

- `provider`
- `provider_payment_id`
- `state`
- `authorized_amount_minor`
- `captured_amount_minor`
- `currency`
- `requires_action`
- `action_url` when applicable
- `risk_state`
- `provider_code`
- `evidence_id`

### 4.2 Canonical Payment States

```text
created
requires_action
authorized
partially_captured
captured
partially_refunded
refunded
voided
failed
disputed
cancelled
unknown
```

State rules:

- A capture cannot exceed the authorized amount.
- A refund cannot exceed cumulative captured amount minus cumulative refunds.
- A void is valid only before capture.
- A provider webhook cannot move a payment backward without an explicit
  reconciliation event.
- Unknown provider events are stored as evidence and do not mutate payment
  state.

### 4.3 Payment Webhooks

Requirements:

- Verify the raw request body before JSON parsing.
- Require provider event ID.
- Deduplicate by `(provider, event_id)`.
- Store signature result, payload hash, redacted payload and processing state.
- Return success for already-processed events.
- Queue business processing after durable receipt.
- Preserve unknown event types for later mapping.

### 4.4 Payment Reconciliation

Scheduled reconciliation must:

- Find local operations in `pending`, `unknown` or `requires_action`.
- Query provider by provider payment ID.
- Compare amount, currency and state.
- Write a reconciliation evidence record.
- Move to `reconciled`, `mismatch` or `manual_review`.
- Never silently overwrite a local captured/refunded amount.

## 5. Shipping Contract

### 5.1 Operations

```php
interface ShippingProvider
{
    public function rates(ShippingRateRequest $request): array;
    public function create(ShipmentRequest $request, string $key): ShipmentResult;
    public function cancel(string $providerShipmentId, string $key): ShipmentResult;
    public function label(string $providerShipmentId): LabelResult;
    public function track(string $providerShipmentId): TrackingResult;
    public function verifyWebhook(string $rawBody, array $headers): bool;
    public function normalizeWebhook(array $payload): TrackingEvent;
}
```

Required rate request fields:

- origin address
- destination address
- package list
- package weight in grams
- package dimensions in millimeters
- declared value and currency
- customs lines for cross-border shipments
- requested service level

Required shipment request fields:

- order ID
- fulfillment ID
- provider
- origin and destination
- packages
- customs declaration
- shipping service code
- recipient contact

### 5.2 Canonical Shipment States

```text
draft
rate_selected
label_pending
label_created
picked_up
in_transit
out_for_delivery
delivered
exception
cancelled
returned
lost
manual_review
```

Provider-specific states must remain in `provider_status`. Domain workflows use
only canonical states.

### 5.3 Package Rules

- One shipment may contain multiple packages.
- Each package has independent weight, dimensions and tracking number.
- A split shipment cannot reduce the reserved quantity twice.
- Label creation is idempotent by shipment key and package key.
- A shipment can be cancelled only before carrier pickup unless the provider
  explicitly supports cancellation after pickup.
- Tracking events are immutable and deduplicated by provider event ID.

### 5.4 Customs

International shipments require:

- HS code per line.
- Country of origin.
- Declared unit value and currency.
- Quantity and net weight.
- Export reason.
- Incoterms, defaulting to an explicit merchant setting.
- Tax ID/EORI where required.

Missing customs data must block international label creation, not silently
create an incomplete label.

## 6. Persistence Model

### Payment

`payment_operations`

- `id`
- `payment_id`
- `provider`
- `operation`
- `idempotency_key`
- `provider_operation_id`
- `amount_minor`
- `currency`
- `state`
- `request_json`
- `response_json`
- timestamps

`payment_webhook_events`

- provider
- event ID
- payload hash
- signature result
- processing state
- redacted payload
- error/evidence fields

### Shipping

`shipping_shipments`

- provider
- provider reference
- order ID
- fulfillment ID
- canonical state
- idempotency key
- payload/evidence reference

`shipping_packages`

- shipment ID
- package number
- weight/dimensions
- tracking number
- label reference
- state

`shipping_tracking_events`

- shipment/package ID
- provider event ID
- provider status
- canonical state
- occurred-at
- payload hash
- redacted payload

All external payloads must be redacted before persistence. Never store PAN,
CVV, payment secrets or provider access tokens.

## 7. Idempotency and Retry

Key format:

```text
{merchant}:{aggregate}:{operation}:{client_key}
```

Rules:

- Same key plus same request hash returns the original result.
- Same key plus a different request hash returns `409 IDEMPOTENCY_CONFLICT`.
- Provider calls use exponential backoff with bounded attempts.
- A timeout creates `unknown`, not `failed`, until reconciliation completes.
- Retryable provider responses: timeout, connection reset, 429 and selected 5xx.
- Non-retryable responses: invalid credentials, validation errors and permanent
  carrier/payment rejection.

## 8. Security and Secrets

Environment/configuration keys:

```text
PAYMENT_MODE=sandbox|production
PAYMENT_DEFAULT_PROVIDER=stripe
STRIPE_SECRET_KEY
STRIPE_WEBHOOK_SECRET
PAYPAL_CLIENT_ID
PAYPAL_CLIENT_SECRET
ADYEN_API_KEY
ADYEN_MERCHANT_ACCOUNT
AUTHORIZENET_API_LOGIN_ID
AUTHORIZENET_TRANSACTION_KEY
SQUARE_ACCESS_TOKEN
SQUARE_WEBHOOK_SIGNATURE_KEY

SHIPPING_MODE=sandbox|production
SHIPPING_DEFAULT_PROVIDER=dhl
DHL_API_KEY
DHL_API_SECRET
DHL_WEBHOOK_SECRET
UPS_CLIENT_ID
UPS_CLIENT_SECRET
FEDEX_API_KEY
FEDEX_SECRET_KEY
USPS_CLIENT_ID
ARAMEX_USERNAME
ARAMEX_PASSWORD
```

Rules:

- Production mode fails closed when required credentials are absent.
- Sandbox credentials cannot be accepted in production mode.
- Secrets are never returned by admin APIs or evidence endpoints.
- Logs contain provider IDs and hashes, not raw credentials or card data.
- Webhook endpoints are rate-limited and signature-verified.

## 9. API Surface

### Payment

```text
POST /api/v2/payments/authorize
POST /api/v2/payments/{id}/capture
POST /api/v2/payments/{id}/void
POST /api/v2/payments/{id}/refund
GET  /api/v2/payments/{id}
POST /api/webhooks/payments/{provider}
POST /api/admin/payments/{id}/reconcile
```

### Shipping

```text
POST /api/v2/shipping/rates
POST /api/v2/shipments
POST /api/v2/shipments/{id}/label
POST /api/v2/shipments/{id}/cancel
GET  /api/v2/shipments/{id}
GET  /api/v2/shipments/{id}/tracking
POST /api/webhooks/shipping/{provider}
```

Every write endpoint requires `Idempotency-Key`.

## 10. Test Matrix

Each provider must have:

- Contract tests against the common interface.
- Sandbox integration tests.
- Signature verification tests.
- Duplicate webhook tests.
- Timeout/retry tests.
- Provider 4xx/5xx mapping tests.
- Idempotency replay tests.
- Amount/currency mismatch tests.
- Reconciliation tests.
- Redaction tests.

Global commerce tests must also cover:

- Payment succeeds but label creation fails.
- Label succeeds but response times out.
- Duplicate capture/refund/create-label requests.
- Partial shipment and multiple packages.
- Cross-border customs validation.
- Provider outage and manual recovery.

## 11. Rollout

### Stage 1: Contracts and evidence

- Finalize registries and canonical DTOs.
- Complete provider evidence and idempotency storage.
- Keep sandbox adapters only.

### Stage 2: Payment production

- Enable Stripe first.
- Run shadow reconciliation.
- Enable PayPal and Adyen.
- Add Authorize.Net and Square by market demand.

### Stage 3: Shipping production

- Enable DHL first.
- Add UPS and FedEx.
- Add USPS for US delivery.
- Add Aramex or Viettel Post based on target market.

### Stage 4: Operational hardening

- Run provider outage drills.
- Run duplicate/retry drills.
- Verify reconciliation dashboards.
- Verify secret rotation and webhook replay controls.

## 12. Definition of Done

An integration is `DONE` only when:

- Sandbox and production transports are separate.
- Credentials are configured through secret management.
- All required operations have a working adapter.
- Webhooks are signature-verified and deduplicated.
- Idempotency replay and conflict behavior are tested.
- Provider failures map to stable domain errors.
- Reconciliation is operationally available.
- Redacted evidence is persisted.
- Contract, integration and failure tests pass.
- The provider is enabled explicitly in configuration.

An adapter contract or sandbox stub alone is `PARTIAL`, not `DONE`.
