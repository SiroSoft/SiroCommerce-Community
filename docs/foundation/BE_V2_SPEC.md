# SiroCommerce BE_V2 Specification

Status: proposed implementation baseline. This is the gate before building the
real storefront/admin frontend.

## 0. Goal And Non-Goals

BE_V2 turns the current commerce foundation into a production backend with
complete payment, shipping, pricing, catalog, customer, fulfillment, queue,
webhook, extension, concurrency, and restore behavior.

The design is a **modular monolith**, not microservices. Modules communicate
through typed contracts and domain events. A module can later be extracted
because its ports, data ownership, commands, events, and side effects are
explicit.

BE_V2 does not start frontend work until the exit gates in section 15 pass.

## 1. Design Rules

1. SiroPHP Core remains the framework. Do not introduce Laravel/Symfony
   containers, facades, queues, or event systems.
2. Each module owns its tables and invariants. Other modules use repositories,
   ports, commands, and events, never another module's ORM internals.
3. Controllers translate HTTP to commands. They do not calculate prices,
   mutate state, call providers, or contain authorization policy.
4. Services orchestrate use cases. Domain policies are pure and deterministic.
5. Provider adapters are replaceable and are never called directly by domain
   code. All external effects have idempotency, timeout, audit, and retry data.
6. Money is integer minor units with an explicit currency and rounding mode.
   Floats are forbidden in application and persistence code.
7. State changes use named transitions. Direct state updates are forbidden
   outside the owning transition service.
8. Every command is safe to retry or explicitly declares why it is not.
9. Hooks can extend behavior, but cannot bypass authorization or mutate another
   module's tables.
10. Every public contract has a contract test, failure test, and compatibility
    version.

## 2. Module Map

```text
App\Modules\
  Shared       money, ids, clock, result, errors, authorization, audit
  Catalog      products, variants, attributes, categories, brands, media
  Customer     identity, guest access, addresses, consent
  Pricing      price lists, currency, promotions, snapshots
  Cart         cart aggregate, lines, cart pricing snapshot
  Inventory    items, reservations, ledger, adjustments, availability
  Checkout     quote/place orchestration and idempotency
  Payment      intents, attempts, capture, refund, webhook reconciliation
  Shipping     rates, shipments, packages, tracking, fulfillment allocation
  Fulfillment  allocation, picking, packing, returns, reverse logistics
  Tax          tax zones, classes, rates, calculation evidence
  Order        immutable order snapshot and order transitions
  Queue        jobs, attempts, backoff, dead letters, worker health
  Webhook      raw events, signature verification, dedupe, dispatch
  Notification outbox consumers, templates, delivery attempts
  Search       indexing and query adapters
  Extension    manifest, lifecycle, hooks, capabilities, compatibility
  Platform     migrations, config, health, OpenAPI, observability
```

### 2.1 Dependency Direction

```text
Shared <- Catalog, Customer, Tax
Shared <- Pricing <- Cart <- Checkout
Shared <- Inventory <- Checkout
Shared <- Payment <- Checkout
Shared <- Shipping <- Checkout, Fulfillment
Shared <- Order <- Checkout, Payment, Shipping
Queue/Webhook/Notification consume events from all domains
Extension depends on published contracts only
```

No module may import another module's Models or private repository. Shared must
not import a business module.

### 2.2 Filesystem

```text
backend/app/Modules/<Module>/
  Domain/                 entities, value objects, policies, states
  Application/            commands, handlers, DTOs, results
  Contracts/              ports exposed to other modules/extensions
  Infrastructure/         models, repositories, provider adapters
  Http/                    controllers, requests, resources
  Providers/               module boot and route registration
  Tests/                   unit, contract, integration
backend/app/Platform/
  Bus/ Events/ Hooks/ Outbox/ Idempotency/ Audit/ Locking/
```

New code must follow this layout. Compatibility facades at old entry points are
allowed only during migration and must have a removal issue/version.

## 3. Ports, Commands, Events

### 3.1 Ports

Ports return typed DTOs or `Result`, never ORM models. Example:

```php
interface PaymentProvider
{
    public function authorize(PaymentAuthorization $request): ProviderResult;
    public function capture(PaymentCapture $request): ProviderResult;
    public function refund(PaymentRefund $request): ProviderResult;
    public function void(PaymentVoid $request): ProviderResult;
    public function verifyWebhook(RawWebhook $webhook): VerifiedWebhook;
}
```

Every provider request contains `operation_id`, `idempotency_key`,
`timeout_ms`, `currency`, and `amount_minor` where applicable. Every result
contains `provider`, `provider_reference`, `state`, and `retryable`.

### 3.2 Commands

Commands are imperative use cases with one handler:

```text
CreateQuote, PlaceOrder, AuthorizePayment, CapturePayment, RefundPayment
ReceiveWebhook, ReserveInventory, ReleaseReservation, CommitReservation
CreateShipment, CancelShipment, MarkShipped, MarkDelivered
```

Commands carry `actor`, `correlation_id`, `causation_id`, and
`idempotency_key`.

### 3.3 Events

Events are immutable facts, versioned as `name.v1`, and contain:

```json
{
  "event_id": "evt_...",
  "name": "payment.authorized.v1",
  "occurred_at": "2026-01-01T00:00:00Z",
  "correlation_id": "cor_...",
  "causation_id": "cmd_...",
  "actor": "system",
  "payload": {},
  "schema_version": 1,
  "redaction": []
}
```

Events are published only after the transaction commits through the outbox.

## 4. Hooks And Extension Points

SiroCommerce may have Bagisto-like extensibility without copying Bagisto's
implementation. Use typed hooks plus durable events.

### 4.1 Hook Types

| Type | Timing | Use | Side effects |
|---|---|---|---|
| `filter` | synchronous | change a validated DTO, e.g. price/rates | no external I/O |
| `before` | synchronous | veto or enrich a command | no commit/charge |
| `after` | after aggregate save, before commit | local derived data | same transaction only |
| `event` | after commit | notifications, indexing, integrations | queued |
| `provider` | runtime port | payment/shipping/tax/search adapter | timeout + idempotency |

Hook names are constants, not free-form strings in business code. Handlers have
`id`, `priority`, `module`, `version`, and declared capabilities. Execution is
deterministic: priority ascending, then handler id. A handler may return
`continue`, `stop`, or `reject(reason)`; silent mutation is forbidden.

### 4.2 Safety Rules For Hooks

- Core invariants run before and after hooks.
- A hook cannot change currency, order identity, inventory ownership, or an
  already committed payment amount.
- A hook timeout/failure is isolated and recorded. Required hooks fail closed;
  optional hooks are disabled for the current operation.
- Hook input/output is a versioned DTO and is redacted in trace data.
- Every hook invocation is observable with duration and result.
- No hook may perform an HTTP call from a database transaction.

### 4.3 Published Hook Catalog

```text
catalog.product.validating
catalog.product.saved
pricing.before_quote
pricing.after_quote
checkout.before_place
checkout.after_reservation
checkout.before_payment
payment.before_authorize
payment.after_result
order.before_transition
order.after_transition
fulfillment.before_allocate
fulfillment.after_shipment
webhook.verified
notification.before_send
```

Adding a hook is a contract change: update the catalog, DTO schema, contract
test, and extension compatibility version.

## 5. Payment, Webhook, Outbox, Queue

### 5.1 Payment Domain

Entities:

```text
payment_intents(id, order_id, amount_minor, currency, state, provider,
  idempotency_key UNIQUE, created_at)
payment_attempts(id, intent_id, operation, attempt_no, state, provider_ref,
  request_hash, error_code, retryable, started_at, finished_at)
payment_operations(id, payment_id, type, idempotency_key UNIQUE, state,
  amount_minor, provider_ref, response_hash, created_at)
payment_webhook_events(id, provider, event_id, signature_ok, payload_hash,
  state, received_at, processed_at, UNIQUE(provider,event_id))
```

Payment states:

```text
intent: pending -> authorized -> captured -> partially_refunded -> refunded
                         |-> failed       |-> voided
operation: received -> processing -> succeeded | failed | ignored
```

Rules:

1. `authorize`, `capture`, `void`, and `refund` each have their own operation
   idempotency key. A retry returns the stored result.
2. A capture cannot exceed the authorized amount. Cumulative refunds cannot
   exceed captured amount.
3. Provider calls occur outside the database transaction. The operation is
   persisted first, then executed by a job, then reconciled transactionally.
4. Provider timeout means `unknown`, not `failed`. Reconciliation is required
   before retrying a charge.
5. Raw provider payloads are encrypted or redacted; logs contain hashes and
   references only.

### 5.2 Webhook Flow

```text
HTTP receive raw body
  -> size/rate limit
  -> resolve provider adapter
  -> verify signature on raw bytes
  -> persist payment_webhook_events UNIQUE(provider,event_id)
  -> return 200 accepted/already_processed quickly
  -> enqueue ProcessWebhook(event_id)
  -> lock payment operation
  -> apply named transition
  -> append audit + outbox events
```

Invalid signature is `401/403`, is never queued, and is audit logged without
the payload. Duplicate event returns `200 already_processed` and has no side
effect. Unknown event type is stored as `ignored`, not silently discarded.

### 5.3 Outbox

```text
outbox_messages(id, event_id UNIQUE, topic, aggregate_type, aggregate_id,
  payload_json, state, attempts, available_at, locked_at, last_error,
  created_at, published_at)
```

The aggregate write and outbox insert are one DB transaction. A dispatcher
claims rows with a lease, publishes to the queue, and marks them published.
Lease expiry makes delivery retryable. Consumers are idempotent by `event_id`.

### 5.4 Queue

```text
jobs(id, queue, name, payload_json, idempotency_key UNIQUE, state,
  attempts, max_attempts, available_at, locked_at, last_error)
dead_letters(id, job_id, reason, payload_json, failed_at, resolved_at)
```

Required behavior: exponential backoff, jitter, max attempts, lease timeout,
dead-letter state, manual replay command with dry-run, queue depth health, and
graceful shutdown. Jobs never contain card data, passwords, or raw secrets.

## 6. Shipping, Tax, Pricing Snapshot

### 6.1 Pricing Pipeline

```text
catalog price -> customer price list -> currency conversion
  -> promotion rules -> shipping quote -> tax calculation -> totals
```

The pipeline returns a `Quote` containing line-level evidence, rule ids,
rounding decisions, provider versions, and an expiry timestamp. Repricing is
explicit; order placement never silently changes a displayed quote.

### 6.2 Immutable Snapshots

`cart_quotes` and `order_pricing_snapshots` store:

```text
currency, locale, price_list_id, exchange_rate, lines[],
subtotal_minor, discount_minor, shipping_minor, tax_minor,
grand_total_minor, applied_rules[], shipping_rate_id, tax_breakdown[],
calculated_at, expires_at, engine_version
```

Order lines additionally snapshot SKU, name, option values, unit price,
discount allocation, tax class, and weight. Catalog edits never rewrite these
values.

### 6.3 Shipping

Ports:

```text
RateProvider::quote(ShipmentContext): RateQuote[]
Carrier::create(CreateShipment): ShipmentResult
Carrier::cancel(CancelShipment): ShipmentResult
Carrier::track(TrackingRequest): TrackingResult
```

Shipment states:

```text
pending -> allocated -> label_created -> shipped -> delivered
                  |-> failed       |-> cancelled
```

Duplicate shipment creation is blocked by `(order_id, package_key)` and
provider operation idempotency. Partial shipment and split packages are first
class, not encoded as one order state.

### 6.4 Tax

Tax is a module and provider port, not an integer field guessed by checkout.
Rules select by destination, origin, tax class, customer exemption, and date.
The result stores jurisdiction, rate, taxable base, rounding mode, and rule
version. Tax failure blocks placement unless the store explicitly enables a
safe zero-tax policy with an audit warning.

## 7. Catalog And Customer Completeness

### 7.1 Catalog

Required aggregates/tables:

```text
products, product_variants, product_options, option_values,
categories, category_product, brands, attributes, attribute_values,
product_attribute_values, product_images, price_lists, product_prices,
product_slug_history
```

Required behavior: unique SKU/slug, variant validation, category tree cycle
prevention, bulk import with row-level errors, media metadata, soft archive,
slug redirect history, search index event, and optimistic version on edits.

Catalog never owns stock. Product deletion is archive-only when referenced by a
cart/order.

### 7.2 Customer

```text
customers(id, email_normalized UNIQUE, name, status, verified_at,
  marketing_consent, version)
customer_addresses(id, customer_id, type, snapshot fields, is_default)
guest_order_access(id, order_id UNIQUE, token_hash, expires_at, used_at)
customer_events(id, customer_id, type, consent_version, occurred_at)
```

Email is normalized before uniqueness. Passwords/tokens are never returned.
Guest access stores only a hash, is scoped to one order, expires, can be
revoked, and is rate limited. Customer-owned reads require customer identity;
admin reads require capability.

## 8. Order Fulfillment

Order is an immutable commercial snapshot. Fulfillment is a separate aggregate.

```text
orders, order_lines, order_adjustments, order_transitions
fulfillment_orders, fulfillment_lines, packages, package_lines
shipments, shipment_events, returns, return_lines
```

Order states describe commercial state. Fulfillment states describe physical
state. They must not be conflated.

Required commands: cancel before fulfillment, allocate, pick, pack, ship,
deliver, create return, approve return, receive return, refund return. Each
command has capability checks, transition guards, audit actor/reason, and
idempotency.

## 9. Extension Lifecycle

An extension is a versioned package with a manifest:

```json
{
  "id": "vendor/module",
  "version": "1.2.0",
  "requires": {"core": "^1.0", "commerce": "^0.2"},
  "capabilities": ["payment.provider", "hook.pricing.before_quote"],
  "permissions": ["network:provider.example", "config:payment"]
}
```

Lifecycle:

```text
discover -> validate manifest -> compatibility check -> install migrations
  -> register contracts/hooks -> enable -> health check
disable -> drain jobs -> unregister runtime -> keep data
uninstall -> export/confirm data policy -> rollback extension migrations
upgrade -> backup -> migrate -> compatibility test -> enable new version
```

Install/upgrade/disable/uninstall are CLI operations, never hidden in HTTP
requests. Every extension has migration ownership, config schema, health check,
contract tests, timeout policy, and uninstall data policy.

Extensions can add provider adapters, hooks, event consumers, admin slots, and
CLI commands through allowlisted registries. They cannot add arbitrary routes,
top-level navigation, raw SQL against another module, or unrestricted jobs.

## 10. MySQL Concurrency And Integrity Proof

Production uses MySQL 8. SQLite is unit-only. Integration tests use a dedicated
MySQL database and real migrations.

Required proofs:

- Two concurrent reservations cannot oversell one variant.
- Two `PlaceOrder` calls with the same key produce one order.
- Two captures/refunds with the same key produce one provider operation.
- Two webhook deliveries produce one applied event.
- Two shipment retries produce one package/label.
- Deadlock retry never duplicates ledger/payment/outbox rows.

Use transactions, `SELECT ... FOR UPDATE` where ownership changes, unique
constraints as the final race guard, and bounded deadlock retry. Every race
test uses separate DB connections and records final ledger invariants.

## 11. Load, Restore, And Disaster Proof

Datasets: 10k, 50k, and 100k products; 50k customers; 100k orders. Record p95,
p99, query count, memory, cache hit, error rate, and queue lag.

Scenarios: browse/search, quote, checkout spike, payment webhook burst, admin
order list, outbox drain, and 30-minute soak. Budgets are release blockers.

Backup proof must include database plus uploads/config metadata, encrypted
storage, checksum, retention, and a restore rehearsal. Restore is verified by
row counts, checksums, latest order/payment/ledger consistency, and a rollback
smoke test. A backup file existing is not proof of restore.

Failure drills required: provider timeout/unknown, duplicate webhook, shipping
timeout, worker outage, cache outage, mail outage, DB deadlock, duplicate
checkout, inventory race, extension failure, upgrade/rollback.

## 12. API V2 Contract

Keep `/api` compatibility during migration. New complete contracts live under
`Accept: application/vnd.siro.v2+json` and are generated into OpenAPI.

Minimum endpoints:

```text
GET/POST/PUT/DELETE /api/v2/catalog/products
GET/POST             /api/v2/catalog/categories
POST                 /api/v2/checkout/quote
POST                 /api/v2/checkout/place
POST                 /api/v2/payments/{id}/capture|refund|void
POST                 /api/v2/webhooks/{provider}
POST                 /api/v2/orders/{id}/cancel
POST                 /api/v2/fulfillment/{id}/allocate|ship|deliver
GET/POST/PUT         /api/v2/customers/{id}/addresses
GET                  /api/v2/health/queue|payments|inventory|outbox
```

Every write documents validation, capability, idempotency, state transition,
events, and error codes. OpenAPI is generated from the route/DTO contract; hand
edited frontend types are forbidden.

## 13. Observability

Every command has `correlation_id` and `causation_id`. Flight Recorder spans
checkout, pricing, inventory, payment, webhook, order, queue, shipment, and
notification. Logs redact credentials, tokens, payment data, and personal data
according to retention policy.

Doctor checks database connectivity/migrations, queue depth/dead letters,
outbox lag, payment unknowns, webhook failures, inventory mismatch, shipment
stalls, catalog index lag, and restore timestamp.

## 14. Delivery Plan

| Step | Scope | Exit evidence |
|---|---|---|
| V2-0 | module skeleton, shared contracts, bus/hooks, migration policy | dependency check + contract tests |
| V2-1 | payment operations, webhook, outbox, queue | duplicate/timeout/unknown drills |
| V2-2 | pricing, tax, shipping, immutable snapshots | quote/order snapshot tests |
| V2-3 | catalog/customer completeness | CRUD/import/ownership tests |
| V2-4 | fulfillment, returns, partial shipment | state and retry tests |
| V2-5 | extension lifecycle and hooks | install/upgrade/disable/compat tests |
| V2-6 | MySQL concurrency/load/restore | measured artifacts and restore rehearsal |
| V2-7 | API v2/OpenAPI/security audit | generated spec and API gates |
| V2-8 | Doctor/Recovery promotion | evidence-linked action UX/API |

Each step is a separate branch/commit group. No step hides a missing provider,
queue, or proof behind a stub marked production-ready.

## 15. Backend Definition Of Done

- All module boundaries and dependency checks pass.
- All V2 commands have unit, integration, idempotency, failure, and authorization tests.
- MySQL integration and concurrency tests pass on separate connections.
- Payment/webhook/outbox/queue have no unbounded retry or unknown-charge retry.
- Order pricing, tax, shipping, product, and customer snapshots are immutable.
- Catalog, customer, fulfillment, returns, and provider contracts are complete.
- OpenAPI is generated and API compatibility is tested.
- Load, restore, rollback, and failure-drill artifacts exist and meet budgets.
- Security audit, PHPStan max, PHPUnit, mapping, i18n, and dependency audit pass.
- Only after all above: begin frontend implementation.

## 16. Doctor/Recovery Positioning

Doctor and Recovery are not marketed as the primary feature until the backend
guarantees the data they diagnose: durable operations, outbox events, queue
leases, provider reconciliation, shipment idempotency, and immutable snapshots.
After V2-0 through V2-7 pass, they become the differentiator: evidence-linked
diagnosis plus dry-run, safe recovery, and post-action verification.
