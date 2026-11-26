> SUPERSEDED by [COMMERCE_COMPATIBILITY_MATRIX.md](./COMMERCE_COMPATIBILITY_MATRIX.md) — kept for history.

# Full Commerce Capability Comparison

Audit date: 2026-09-20  Baseline: `7cf0fdf`.

This comparison uses the practical full-product baseline: WooCommerce plus its
common first-party and production extensions, Bagisto core plus its normal
official packages, and Magento Open Source/Adobe Commerce capabilities where
the product line differs. It is not a claim that every marketplace plugin is
identical across vendors.

## Executive Result

SiroCommerce has a strong native commerce kernel with better explicit
idempotency, evidence and module boundaries than a default WooCommerce
installation. It is not yet feature-complete against the full product surface
of Bagisto or Magento/Adobe Commerce. The largest gaps are product types,
production shipping/payment integrations, customer experience features, CMS,
reporting, and enterprise operations.

## Capability Audit

Legend: `DONE` means production-capable native behavior, `PARTIAL` means a
working baseline but important full-product behavior is absent, `MISSING` means
no equivalent capability, and `EXTENSION` means the competitor normally
depends on an extension/package rather than base runtime.

| Capability | SiroCommerce | WooCommerce full | Bagisto full | Magento/Adobe full | Audit conclusion |
|---|---|---|---|---|---|
| Simple/configurable products | PARTIAL | DONE | DONE | DONE | Siro supports basic variants/options, not all product types. |
| Bundle/grouped/composite products | MISSING | EXTENSION | DONE | DONE | No component pricing, inventory or fulfillment model. |
| Downloadable/virtual products | MISSING | DONE | DONE | DONE | No protected file delivery or download lifecycle. |
| Subscription/recurring products | MISSING | EXTENSION | EXTENSION | EXTENSION/Adobe | No recurring billing, renewal or retry state machine. |
| Attributes/options/media/import | DONE | DONE | DONE | DONE | Basic variant option mapping is covered. |
| Catalog taxonomy/brand/archive | DONE | DONE/EXTENSION | DONE | DONE | Native taxonomy baseline is adequate. |
| Price lists/customer groups | PARTIAL | EXTENSION | DONE | DONE | Missing full quantity breaks, catalog rules and pricing UI breadth. |
| Currency/localization | PARTIAL | EXTENSION | DONE | DONE | Conversion snapshot exists; locale/storefront breadth is smaller. |
| Cart/guest checkout | DONE | DONE | DONE | DONE | Core quote, guest access and idempotency are covered. |
| Tax compliance | PARTIAL | EXTENSION | EXTENSION | DONE/Adobe | Provider/compliance coverage is not equivalent to platform ecosystem breadth. |
| Payment gateways | PARTIAL | EXTENSION | EXTENSION | EXTENSION | Contracts and COD baseline exist; live global gateway breadth is absent. |
| Refunds/invoices/credit memos | PARTIAL | EXTENSION | PARTIAL | DONE | Refund operations exist; invoice/document lifecycle is incomplete. |
| Shipping rates/carriers | PARTIAL | EXTENSION | EXTENSION | EXTENSION | DHL sandbox/provider contract exists; no live carrier fleet yet. |
| Shipment/fulfillment/partial shipment | PARTIAL | EXTENSION | PARTIAL | DONE | Persistence exists, but warehouse/carrier orchestration is not full parity. |
| Tracking/returns/RMA | PARTIAL | EXTENSION | PARTIAL | DONE/Adobe | Tracking baseline exists; full RMA rules and carrier return labels remain. |
| Inventory reservation/ledger | DONE | EXTENSION | DONE | DONE | Strong native invariant and race-test evidence. |
| Multi-source inventory/warehouses | PARTIAL | EXTENSION | PARTIAL | DONE | Source-aware baseline exists; full sourcing/ATP/warehouse workflows do not. |
| Coupons/promotions/catalog rules | PARTIAL | DONE/EXTENSION | DONE | DONE | Basic preview/rules exist; rule matrix and merchandising breadth are smaller. |
| Customer accounts/addresses | DONE | DONE | DONE | DONE | Core identity and address lifecycle is covered. |
| Wishlist/reviews/ratings | MISSING | EXTENSION | EXTENSION | DONE/EXTENSION | No customer wishlist or review moderation model. |
| Search/facets/indexing | PARTIAL | EXTENSION | DONE | DONE | Native indexing contract exists; no mature external search ecosystem. |
| CMS/pages/menus/content blocks | MISSING | EXTENSION | DONE | DONE | No Page Builder/CMS content model. |
| SEO | PARTIAL | EXTENSION | EXTENSION | DONE | Metadata/sitemap baseline exists; content SEO tooling is smaller. |
| Analytics/reports/dashboards | PARTIAL | EXTENSION | EXTENSION | DONE | Funnel and ops metrics exist, not full commercial reporting. |
| Webhooks/outbox/queue | DONE | EXTENSION/CORE | CORE | DONE | Durable queue and evidence are explicit native seams. |
| Plugin/extension lifecycle | PARTIAL | DONE ecosystem | DONE packages | DONE modules | Lifecycle contract exists; ecosystem and marketplace are not comparable. |
| Hooks/events | DONE | DONE ecosystem | DONE | DONE | Typed hooks provide stronger safety but fewer integrations. |
| Marketplace/multi-vendor | OUT_OF_SCOPE | EXTENSION | EXTENSION | EXTENSION | Intentionally excluded: SiroCommerce targets single-merchant commerce, not a marketplace platform. |
| Admin operations | PARTIAL | EXTENSION | DONE | DONE | Admin API/ops baseline exists; UI and workflow breadth is smaller. |
| REST/OpenAPI | PARTIAL | DONE | DONE | DONE | Route/spec coverage exists; SDK and compatibility surface is smaller. |
| GraphQL/headless SDKs | MISSING | EXTENSION | DONE/EXTENSION | DONE | No GraphQL contract or official client SDK surface. |
| Multi-store/channel | PARTIAL | EXTENSION | DONE | DONE | Store/channel persistence exists; full catalog/content isolation remains. |
| B2B/company/approval/credit | PARTIAL | EXTENSION | EXTENSION | DONE/Adobe | Company, credit and approval baseline exists; shared catalogs/quoting are incomplete. |
| Audit/observability/recovery | DONE | EXTENSION/tools | EXTENSION | DONE/enterprise | Siro has unusually explicit operational evidence. |
| Security/auth/RBAC | PARTIAL | EXTENSION | DONE | DONE | Auth/RBAC baseline exists; full admin security and compliance surface remains. |

## Position By Competitor

### WooCommerce

- Siro is competitive on kernel invariants, typed module boundaries, idempotency
  and operational evidence.
- WooCommerce has a much larger practical extension ecosystem for payments,
  shipping, subscriptions, memberships, reviews, marketplace and CMS.
- Siro should not claim WooCommerce parity until live gateway/carrier adapters,
  subscriptions, reviews, wishlist and CMS are addressed or explicitly out of
  scope. Marketplace/multi-vendor is intentionally out of scope.

### Bagisto

- Bagisto is the closest Laravel-native comparison and has broader catalog
  types, channels, locales, currencies, CMS and admin functionality.
- Siro currently has stronger explicit failure evidence and a more deliberate
  modular kernel, but less storefront/admin breadth.
- Product types, content management, search, warehouse workflows and live
  shipping are the main parity gaps.

### Magento/Adobe Commerce

- Magento/Adobe remains materially ahead in configurable/bundle/downloadable
  products, MSI, B2B shared catalogs, GraphQL, CMS/Page Builder, promotions,
  invoices/credit memos, reporting and enterprise integrations.
- Siro should compare itself to Magento's kernel guarantees selectively, not
  advertise feature parity based only on REST route coverage.
- Achieving broad Magento parity would be a multi-phase product program, not a
  single shipping or catalog round.

## Recommended Next Phases

| Priority | Phase | Why |
|---|---|---|
| P0 | Production integrations | Real DHL transport, payment gateways, tax/shipping credentials, retry and live webhook contracts. |
| P1 | Complete product types | Bundle/grouped/downloadable/virtual products with inventory, pricing and fulfillment semantics. |
| P1 | Commercial customer features | Reviews, wishlist, invoices, credit memos, richer promotions and customer self-service. |
| P1 | CMS and storefront breadth | Pages, blocks, menus, SEO content, GraphQL/headless contract and generated client types. |
| P2 | Enterprise inventory/B2B | ATP/source selection, shared catalogs, negotiated quotes, purchase orders and approval limits. |
| P2 | Ecosystem and reporting | Extension SDK, commercial reports, dashboards and data exports; provider marketplace is optional, while seller marketplace remains out of scope. |

## Scope Rule

Until these gaps are resolved, the accurate product statement is:

> SiroCommerce provides a production-oriented native commerce kernel with
> strong invariants and a growing API surface. It is not yet a full feature
> replacement for WooCommerce plus ecosystem, Bagisto, or Magento/Adobe
> Commerce.
