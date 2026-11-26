# SiroCommerce Product Criteria

Version: `V1.0`
Status: product north-star
Scope: criteria for building, reviewing and releasing SiroCommerce.

## 1. Product Position

SiroCommerce is a fast, modular and reliable commerce platform for merchants,
developers and enterprise operators. It must combine the usability of
WooCommerce, the Laravel friendliness of Bagisto, and the operational depth of
Magento/Adobe Commerce without copying their unnecessary complexity.

SiroCommerce must be judged by both feature breadth and operational guarantees.
A feature is not complete because a route or contract exists. It is complete
when it works in production, has failure behavior, tests, documentation and a
usable admin workflow.

## 2. Maturity Levels

### Level 0: Missing

No supported implementation or contract exists.

### Level 1: Foundation

The domain model, contract and basic tests exist. Not production-ready.

### Level 2: Operational

The feature works locally and in integration tests, has persistence,
idempotency, authorization and failure handling.

### Level 3: Production

The feature has real provider/infrastructure support, monitoring, recovery,
security review, performance evidence and admin/user workflows.

### Level 4: Enterprise

The feature supports scale, multi-tenant or multi-channel operation, audit,
compliance, migration, advanced permissions and operational ownership.

No feature may be advertised as production-ready below Level 3.

## 3. Core Commerce Criteria

### C01. Product Catalog

- Support simple, configurable, grouped, bundle, virtual and downloadable
  products.
- Support attributes, variants, media, categories, brands and catalog rules.
- Support product versioning, archive behavior and slug history.
- Support bulk import/export with row-level errors and replay safety.
- Support product type-specific pricing, stock and fulfillment semantics.

### C02. Pricing and Promotions

- Support variant prices, price lists, customer groups, channels and currencies.
- Support quantity breaks, catalog rules, cart rules and coupon rules.
- Preserve immutable price snapshots on quote and order.
- Explain why a price was selected.
- Prevent negative, ambiguous or currency-mismatched calculations.

### C03. Cart and Checkout

- Support guest and authenticated checkout.
- Enforce ownership, expiry and idempotency.
- Support product type-specific cart lines.
- Recalculate price, tax, shipping and inventory before order placement.
- Handle payment success, timeout, retry and partial failure safely.

### C04. Orders

- Provide explicit order state transitions.
- Support cancellation, partial fulfillment, partial shipment and split order.
- Keep payment, inventory, shipment, invoice and return references.
- Provide an order timeline with actor and evidence.
- Make all administrative mutations auditable.

### C05. Inventory Pro

- Maintain an immutable stock ledger.
- Support on-hand, reserved, available-to-sell and committed quantities.
- Support multiple warehouses and inventory sources.
- Support allocation, transfers, receiving, cycle count and adjustments.
- Support bundle/BOM component reservation and rollback.
- Support backorder, oversell policy, low-stock and reorder thresholds.
- Support batch, lot, expiry and serial tracking where applicable.
- Prove concurrent checkout cannot create negative stock.

### C06. Payments

- Support Stripe, PayPal, Adyen, Authorize.Net and Square through adapters.
- Support authorize, capture, void, refund and partial refund.
- Support 3DS/SCA, tokenized payment methods and risk results.
- Verify and deduplicate provider webhooks.
- Reconcile unknown, pending and mismatched provider operations.
- Never store raw card data, PAN or CVV.

### C07. Shipping and Fulfillment

- Support DHL, UPS, FedEx, USPS and Aramex through adapters.
- Keep a provider-neutral shipment and package model.
- Support rate, label, cancel, tracking, webhook and return label.
- Support multi-package, split shipment and carrier exceptions.
- Support customs, HS code, country of origin and Incoterms.
- Persist provider evidence and canonical tracking events.
- Never use a sandbox provider in production by fallback.

### C08. Invoices, Refunds and RMA

- Generate immutable invoices with tax and currency evidence.
- Support credit memo, partial refund and refund reconciliation.
- Support return reason, return lines, approval, inspection and disposition.
- Support restock, damaged and replacement outcomes.
- Link invoice, payment, fulfillment and RMA records.

### C09. Customer and B2B

- Support customer account, addresses, guest access and privacy lifecycle.
- Support wishlist, reviews, ratings and moderation.
- Support companies, members, roles, credit limits and approval policies.
- Support shared catalogs, negotiated pricing and purchase order workflows.

### C10. CMS, SEO and Content

- Support pages, blocks, menus, landing pages and content versions.
- Support title, description, canonical, robots, Open Graph and structured data.
- Support image alt text, sitemap, redirects and broken-link checks.
- Support translated content and locale-specific SEO.
- Provide deterministic SEO audit without requiring AI.

## 4. Developer Criteria

### D01. Developer Onboarding

- A new developer can run the project in under 10 minutes.
- Provide `.env.example`, seed data, demo account and sample store.
- Provide one-command migration, test and quality checks.

### D02. Architecture

- Keep `Controller -> Application -> Contract -> Infrastructure` boundaries.
- Keep provider SDKs outside the domain.
- Use typed DTOs and stable contracts for public extension points.
- Avoid hidden global state and implicit framework behavior.

### D03. Extension SDK

- Provide module/provider generator commands.
- Support manifest, version, config, migrations, permissions and health check.
- Support hooks, events, admin routes, API routes and tests.
- Provide an example payment, shipping and product-type extension.

### D04. API and Headless

- Provide versioned REST/OpenAPI contracts.
- Provide GraphQL/headless read contracts where useful.
- Provide PHP and TypeScript client SDKs for stable public APIs.
- Use stable error codes, pagination, filtering and idempotency headers.
- Define deprecation and backward-compatibility policy.

### D05. Documentation

- Document concepts, API, extension points and operational procedures.
- Include copyable examples for common integrations.
- Include upgrade, rollback, migration and failure-recovery guides.
- Keep docs and generated OpenAPI synchronized in CI.

### D06. Testing and Release

- Require unit, integration, API, contract, E2E and provider sandbox tests.
- Include failure, retry, timeout, duplicate and concurrency tests.
- Run PHPStan, security, mapping, i18n and architecture gates in CI.
- Use SemVer, changelog, migration review and deprecation windows.

## 5. User and Enterprise Criteria

### E01. Performance

- Define p95 latency budgets per API class.
- Define query count, memory and payload budgets.
- Support cache invalidation and queue-based heavy work.
- Measure catalog, search, checkout and admin performance under load.
- Support horizontal scaling without shared process state.

### E02. Reliability and Recovery

- Define RPO/RTO targets per deployment tier.
- Provide backup checksum and restore rehearsal.
- Provide queue retry, dead-letter, replay and safe recovery.
- Provide provider outage and manual-review workflows.
- Never silently lose an external operation after timeout.

### E03. Security and Compliance

- Support RBAC/ABAC, MFA and least-privilege provider credentials.
- Provide audit logs and redaction for sensitive data.
- Apply OWASP controls, dependency audit and security headers.
- Define GDPR/privacy retention and deletion behavior.
- Define PCI scope and never handle raw card data unnecessarily.

### E04. Observability and Support

- Provide structured logs, metrics, traces and correlation IDs.
- Provide health, readiness, dependency and queue checks.
- Provide Doctor and Recovery evidence.
- Provide operational dashboards for payment, shipping, inventory and errors.
- Make support diagnosis possible without direct database guessing.

### E05. International Commerce

- Support locale, timezone, currency, tax and regional address formats.
- Support translated catalog, content, email and SEO fields.
- Support customs, international shipping and document formats.
- Support regional payment and carrier configuration.

### E06. Accessibility and UX

- Target WCAG 2.2 AA for admin and storefront surfaces.
- Support keyboard navigation, screen readers and sufficient contrast.
- Make admin workflows fast for bulk operations.
- Provide clear validation, error recovery and empty states.
- Keep storefront responsive and usable on low-end mobile devices.

### E07. Data Ownership and Interoperability

- Merchants can export products, customers, orders, inventory and evidence.
- Import/export is versioned and validates before mutation.
- Provide ERP, POS, marketplace, tax and carrier integration contracts.
- Avoid irreversible vendor lock-in.

### E08. Analytics and Business Operations

- Provide sales, margin, conversion, cohort and customer value reports.
- Provide inventory valuation, movement, aging and shrinkage reports.
- Provide payment, shipping, refund and operational exception reports.
- Support CSV/API export and scheduled reports.

## 6. AI Criteria

### A01. AI Agent Friendly

- Expose typed tools instead of unrestricted database access.
- Require permission, scope, confirmation and idempotency for mutations.
- Record agent identity, prompt reference, tool call and result evidence.
- Provide machine-readable schemas and stable error codes.
- Make dangerous actions require human confirmation.

### A02. AI-Independent Quality

The following must work deterministically without AI:

- Security audit.
- SEO audit.
- Broken link and redirect audit.
- Product data completeness.
- Inventory anomaly detection.
- Payment/shipping reconciliation.
- Queue health and recovery.
- Dependency and configuration checks.

AI may explain, summarize or recommend. AI must not be required for correctness,
security or core transaction execution.

## 7. Killer Features

SiroCommerce should differentiate through guarantees that are useful to both
developers and merchants:

- Evidence-first commerce: every external operation has replayable evidence.
- Deterministic Commerce Doctor: security, SEO, inventory, payment and config
  checks without AI dependency.
- Safe Recovery: retry, reconcile, replay and rollback with explicit state.
- Typed Extension System: extensions are testable, permissioned and observable.
- AI-safe Commerce: agents use audited, typed tools with human approval.
- Product and inventory invariants that are stronger than typical plugin stacks.

## 8. Definition of Done

A capability is `DONE` only when all conditions pass:

- Domain behavior is implemented.
- Persistence and migration are safe.
- API/admin workflow exists where users need it.
- Authorization and audit are implemented.
- Idempotency and failure behavior are specified.
- Unit and integration tests exist.
- Performance impact is measured.
- Security and redaction are reviewed.
- Documentation and OpenAPI are updated.
- Recovery and operational ownership are defined.
- No hidden sandbox or mock fallback exists in production.

## 9. Release Gate

SiroCommerce may call a release enterprise-ready only when:

- No critical capability is below Level 3.
- All high-risk integrations have reconciliation.
- Backup/restore and failure drills pass.
- Security and dependency audits pass.
- Public APIs and extension contracts are versioned.
- Performance budgets are measured and within target.
- Compatibility matrix is reviewed against WooCommerce, Bagisto and
  Magento/Adobe Commerce.
- Residual gaps are explicitly marked `PARTIAL` or `OUT_OF_SCOPE`.
