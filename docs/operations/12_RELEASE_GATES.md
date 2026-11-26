> SUPERSEDED by [B2C_PRODUCTION_RELEASE_GATE.md](./B2C_PRODUCTION_RELEASE_GATE.md) and [04_RELEASE_AUDIT_CHECKLIST.md](../audit/04_RELEASE_AUDIT_CHECKLIST.md) — kept for history.

# 12 — Delivery Roadmap & Release Gates

## Roadmap
| Phase | Scope | Exit |
|---|---|---|
| 0 Constitution & Arch | rules, ERD, event catalog, state tables, contracts, wireframe, SEO matrix, budgets, acceptance+failure plan | architecture review pass mới được code |
| 1 Foundation | catalog/customer/inventory/cart/checkout/order | E2E mua hàng thật chạy tin cậy |
| 2 Merchant Admin | products/orders/customers/inventory/onboarding/search/roles | đạt UX targets (04) |
| 3 Sell & Grow | SEO engine, promo, analytics, perf gates | storefront SEO/perf ready, funnel đo được |
| 4 Smart | Action Center, Order Rescue, inventory/SEO/conversion intel | surface action hữu ích thật |
| 5 Dev superpowers | Flight Recorder, Doctor, Safe Recovery | diagnose+recover 1 failed tx xuyên suốt |
| 6 Ecosystem | SDK + example extensions + payment/shipping integrations | extension third-party-style không sửa core |
| 7 Production Proof | scale/load/security/disaster/upgrade + dogfood store thật | qua hết gates dưới |

## Release gates (tối thiểu)
Functional (workflows + transitions) • Data Integrity (money/inventory/snapshot/concurrency/idempotency) • Security (auth/RBAC/input/webhook/secrets/CSRF/deps) • Performance (budgets) • SEO (canonical/schema/sitemap/redirect regression) • Admin UX (targets) • Recovery (drills pass).

## Pre-code deliverables (làm ngay, theo doc gốc §18)
1. Freeze planning v0.1 + spec v0.2 này. 2. ERD + aggregate boundaries. 3. Event catalog + transition tables. 4. Extension contracts + lifecycle. 5. Wireframe Home/Product/Order/ActionCenter/Health. 6. SEO URL/indexability matrix. 7. Perf budgets số hóa. 8. Acceptance matrix + failure-injection plan. Xong mới vào Phase 1.
