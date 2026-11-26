# SiroCommerce Documentation

This is the single documentation index. Documents are grouped by purpose and
carry a status:

- **CURRENT** — authoritative for its concern; keep it updated with code.
- **LEGACY** — historical/point-in-time record. Useful for history, not current
  capability or release evidence.
- **SUPERSEDED** — replaced by another path; the file is kept only for history
  and starts with a `> SUPERSEDED by ...` banner.

Generated API artifacts remain under `backend/docs/` (`openapi.json`,
`api-handoff/`, `generated/sirocommerce.ts`) and are not duplicated here. If a
document and code disagree, the backend code/OpenAPI and the current
engineering/ops docs win; file a docs correction.

## Canonical facts (verified 2026-11-22)

- Stack: SiroPHP Core (`sirosoft/core`), PHP 8.2+, MySQL 8 production / SQLite
  dev, **Next.js 16.3.5** for storefront and admin.
- Backend inventory: **457 route declarations** in `backend/routes/api.php` and
  **457 OpenAPI operations across 395 paths** in `backend/docs/openapi.json`.
  The checked-in handoff snapshot may lag (`448` route records,
  `NOT_READY_BLOCKED`); rerun `npm run api:handoff` before quoting evidence.
- Operational endpoints: `/health/live`, `/health/ready`, `/health/providers`,
  `/metrics`, `/api/version`, `/api/redirects/resolve`, `/sitemap.xml`,
  `/api/admin/features` (admin/owner), `/api/admin/ops/metrics` (dev/admin).
- Shipped surfaces include gift cards/store credit, split tender, pickup /
  click & collect, member deals, product notifications, search autocomplete,
  shipping estimate, Meilisearch with native SQL fallback, CMS pages/revisions,
  sitemap + redirects, CSV product import/export, native theme packages,
  extension packages/QIT, storefront slots, payout reconciliation, and the
  **Pro license verification/issuance foundation** (packaging, marketplace and
  entitlement quotas remain planned).
- Incident response starts at
  [`engineering/INCIDENT_RUNBOOK.md`](engineering/INCIDENT_RUNBOOK.md); routine
  operations are in [`engineering/RUNBOOK.md`](engineering/RUNBOOK.md).

## Product specs

### Strategy, criteria, and comparisons

| Document | Description | Status |
|---|---|---|
| [`product/SIRO_ECOMMERCE_PRODUCT_CRITERIA.md`](product/SIRO_ECOMMERCE_PRODUCT_CRITERIA.md) | Product constitution, pillars, and acceptance criteria. | CURRENT |
| [`product/PLATFORM_ROADMAP.md`](product/PLATFORM_ROADMAP.md) | Living shipped/planned platform capability roadmap. | CURRENT |
| [`product/PRO_EDITION_PLAN.md`](product/PRO_EDITION_PLAN.md) | Pro/Enterprise packaging plan; flags exist, licensing does not. | CURRENT |
| [`product/ECOSYSTEM_TEAM_PLAN_4DEVS.md`](product/ECOSYSTEM_TEAM_PLAN_4DEVS.md) | 4-developer plan, backlog and DoD for Siro-native plugins/themes. | ACTIVE |
| [`product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md`](product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md) | Free/Pro plugin and theme catalog, admin surfaces, release/update model. | ACTIVE |
| [`product/COMMERCE_COMPATIBILITY_MATRIX.md`](product/COMMERCE_COMPATIBILITY_MATRIX.md) | Living parity matrix and audit log versus commerce baselines. | CURRENT |
| [`product/COMMERCE_FULL_COMPARISON.md`](product/COMMERCE_FULL_COMPARISON.md) | Dated full-product comparison snapshot (2026-09-20). | SUPERSEDED |

### Shipped feature specs

| Document | Description | Status |
|---|---|---|
| [`product/GIFT_CREDIT_SPEC.md`](product/GIFT_CREDIT_SPEC.md) | Gift cards and store-credit wallet, issue/redeem/refund. | CURRENT |
| [`product/SPLIT_PAYMENTS_SPEC.md`](product/SPLIT_PAYMENTS_SPEC.md) | Split tender with multiple gift/credit codes. | CURRENT |
| [`product/CLICK_COLLECT_SPEC.md`](product/CLICK_COLLECT_SPEC.md) | Pickup points, quote/place/ready/collect flow. | CURRENT |
| [`product/MEMBER_DEALS_SPEC.md`](product/MEMBER_DEALS_SPEC.md) | Member-only lifecycle deals and tier visibility. | CURRENT |
| [`product/PRODUCT_NOTIFICATIONS_SPEC.md`](product/PRODUCT_NOTIFICATIONS_SPEC.md) | Back-in-stock, price-drop, and preorder notifications. | CURRENT |
| [`product/SEARCH_AUTOCOMPLETE_SPEC.md`](product/SEARCH_AUTOCOMPLETE_SPEC.md) | As-you-type suggestions and query-log ranking. | CURRENT |
| [`product/SMART_SKILLS_SPEC.md`](product/SMART_SKILLS_SPEC.md) | Deterministic "AI-like" smart skills without a model dependency. | CURRENT |
| [`product/LLM_GATEWAY_SPEC.md`](product/LLM_GATEWAY_SPEC.md) | Optional LLM gateway, PII redaction, cost/audit ledger. | CURRENT |
| [`product/MEDIA_PIPELINE.md`](product/MEDIA_PIPELINE.md) | Media upload/derivative pipeline and metadata rules. | CURRENT |
| [`product/B2C_CATALOG_PRODUCT_TYPES.md`](product/B2C_CATALOG_PRODUCT_TYPES.md) | Catalog product-type payloads and storefront behavior. | CURRENT |
| [`product/VN_SHIPPING_PAYMENT_SPEC.md`](product/VN_SHIPPING_PAYMENT_SPEC.md) | GHN/ViettelPost shipping and VNPay/MoMo payment providers. | CURRENT |

### Admin UI

| Document | Description | Status |
|---|---|---|
| [`admin/ADMIN_UI_IA_SPEC.md`](admin/ADMIN_UI_IA_SPEC.md) | Admin information architecture and navigation. | CURRENT |
| [`admin/ADMIN_UI_DESIGN_SYSTEM.md`](admin/ADMIN_UI_DESIGN_SYSTEM.md) | Admin primitives, visual rules, and interaction contracts. | CURRENT |
| [`admin/ADMIN_UI_SCREEN_SPECS.md`](admin/ADMIN_UI_SCREEN_SPECS.md) | Screen inventory and operation mapping. | CURRENT |
| [`admin/ADMIN_UI_WORKFLOWS.md`](admin/ADMIN_UI_WORKFLOWS.md) | Domain workflows, states, and recovery behavior. | CURRENT |
| [`admin/ADMIN_UI_ROLE_DATA_QA.md`](admin/ADMIN_UI_ROLE_DATA_QA.md) | Roles, data ownership, and QA handoff. | CURRENT |
| [`admin/ADMIN_UI_PARALLEL_WORKSTREAMS.md`](admin/ADMIN_UI_PARALLEL_WORKSTREAMS.md) | Early workstream split and gate plan; evidence rows are dated. | LEGACY |
| [`admin/04_ADMIN_UX_SPEC.md`](admin/04_ADMIN_UX_SPEC.md) | Original admin UX baseline and UX targets. | CURRENT |
| [`admin/06_SMART_SPEC.md`](admin/06_SMART_SPEC.md) | Action Center and smart-operations intent. | CURRENT |

### Frontend

| Document | Description | Status |
|---|---|---|
| [`frontend/FE_IMPLEMENTATION_PLAN.md`](frontend/FE_IMPLEMENTATION_PLAN.md) | Frontend implementation sequence and boundaries. | CURRENT |
| [`frontend/FE_CAPABILITY_MATRIX.md`](frontend/FE_CAPABILITY_MATRIX.md) | Operation-level authorization and FE capability matrix. | CURRENT |
| [`frontend/FE_SCOPE_SWITCHING.md`](frontend/FE_SCOPE_SWITCHING.md) | CommerceScope switching and cache isolation. | CURRENT |
| [`frontend/FE_SECURITY_PRIVACY.md`](frontend/FE_SECURITY_PRIVACY.md) | Browser security, privacy, tokens, CSRF, CSP rules. | CURRENT |
| [`frontend/FE_CONTRACT_GAPS.md`](frontend/FE_CONTRACT_GAPS.md) | Active/past backend contract gaps seen by FE. | CURRENT |
| [`frontend/FE_FAILURE_RECOVERY.md`](frontend/FE_FAILURE_RECOVERY.md) | Failure, retry, reconciliation, and recovery states. | CURRENT |
| [`frontend/FE_RELEASE_EVIDENCE.md`](frontend/FE_RELEASE_EVIDENCE.md) | Release evidence standard and traceability keys. | CURRENT |
| [`frontend/FE_ACCESSIBILITY_ACCEPTANCE.md`](frontend/FE_ACCESSIBILITY_ACCEPTANCE.md) | WCAG 2.2 AA acceptance criteria. | CURRENT |
| [`frontend/FE_PERFORMANCE_BUDGETS.md`](frontend/FE_PERFORMANCE_BUDGETS.md) | Core Web Vitals and route budget gates. | CURRENT |
| [`frontend/FE_I18N_FINANCE.md`](frontend/FE_I18N_FINANCE.md) | Locale, currency, timezone, and finance display rules. | CURRENT |
| [`frontend/FE_ROLLOUT_FEATURE_FLAGS.md`](frontend/FE_ROLLOUT_FEATURE_FLAGS.md) | Rollout, flags, and rollback expectations. | CURRENT |
| [`frontend/FE_B2B_WORKFLOWS.md`](frontend/FE_B2B_WORKFLOWS.md) | B2B company, quote, approval, and credit flows. | CURRENT |
| [`frontend/FE_CMS_EDITOR_WORKFLOW.md`](frontend/FE_CMS_EDITOR_WORKFLOW.md) | CMS page/block/revision editor workflow. | CURRENT |
| [`frontend/FE_REPORTING_EXPORTS.md`](frontend/FE_REPORTING_EXPORTS.md) | Reporting, analytics, search, and BI export UI. | CURRENT |
| [`frontend/FE_RETURNS_REFUNDS_WORKFLOW.md`](frontend/FE_RETURNS_REFUNDS_WORKFLOW.md) | Returns, refunds, fulfillment, inventory, privacy. | CURRENT |
| [`frontend/FE_TEST_COVERAGE.md`](frontend/FE_TEST_COVERAGE.md) | Frontend test layers and coverage expectations. | CURRENT |
| [`frontend/FE_THEME_EXTENSION.md`](frontend/FE_THEME_EXTENSION.md) | Admin-driven child theme/extension guide. | CURRENT |
| [`frontend/FE_THEME_LAYOUT_SPEC.md`](frontend/FE_THEME_LAYOUT_SPEC.md) | Header/footer/list/page layout slot rules. | CURRENT |
| [`frontend/FE_THEME_VERTICAL_IMPLEMENTATION.md`](frontend/FE_THEME_VERTICAL_IMPLEMENTATION.md) | Theme/vertical implementation plan and evidence. | CURRENT |

### QA

| Document | Description | Status |
|---|---|---|
| [`qa/TEST_RULES.md`](qa/TEST_RULES.md) | R1-R9 test rules. | CURRENT |
| [`qa/TEST_COVERAGE_MATRIX.md`](qa/TEST_COVERAGE_MATRIX.md) | 13-layer test coverage matrix. | CURRENT |
| [`qa/27_ACCEPTANCE_MATRIX.md`](qa/27_ACCEPTANCE_MATRIX.md) | Acceptance matrix mapped to product criteria. | CURRENT |
| [`qa/28_FAILURE_INJECTION_PLAN.md`](qa/28_FAILURE_INJECTION_PLAN.md) | Required failure-injection drills. | CURRENT |
| [`qa/11_QA_AUDIT.md`](qa/11_QA_AUDIT.md) | QA/audit test matrix and gates. | CURRENT |
| [`qa/UAT.md`](qa/UAT.md) | UAT skeleton mapped to E2E journeys. | CURRENT |
| [`qa/31_DEEP_AUDIT.md`](qa/31_DEEP_AUDIT.md) | Dated deep-audit findings (2026-09-19). | LEGACY |

## Contracts

| Document | Description | Status |
|---|---|---|
| [`contracts/COMMERCE_SCOPE.md`](contracts/COMMERCE_SCOPE.md) | Store/channel/locale/currency scope contract. | CURRENT |
| [`contracts/CONFIGURATION_SPEC.md`](contracts/CONFIGURATION_SPEC.md) | Configuration registry and custom sections. | CURRENT |
| [`contracts/API_SDK_SECURITY_PERFORMANCE.md`](contracts/API_SDK_SECURITY_PERFORMANCE.md) | API/SDK versioning, security, and performance contract. | CURRENT |
| [`contracts/API_BREAKING_CHANGE_GATE.md`](contracts/API_BREAKING_CHANGE_GATE.md) | OpenAPI breaking-change gate. | CURRENT |
| [`contracts/GLOBAL_PROVIDER_INTEGRATION_SPEC.md`](contracts/GLOBAL_PROVIDER_INTEGRATION_SPEC.md) | Payment/shipping provider lifecycle and evidence. | CURRENT |
| [`contracts/ERP_LITE_API_SPEC.md`](contracts/ERP_LITE_API_SPEC.md) | ERP Lite loyalty/subscription/POS/marketplace boundary. | CURRENT |
| [`contracts/SEARCH_SEO_SPEC.md`](contracts/SEARCH_SEO_SPEC.md) | URL rewrites, search terms/synonyms, sitemaps. | CURRENT |
| [`contracts/05_SEO_GROWTH_SPEC.md`](contracts/05_SEO_GROWTH_SPEC.md) | SEO architecture, URL/indexability matrix, growth principles. | CURRENT |
| [`contracts/CMS_REVISION_WORKFLOW.md`](contracts/CMS_REVISION_WORKFLOW.md) | CMS revisions, autosave, merge, publish/restore. | CURRENT |
| [`contracts/CATALOG_TRANSFER_CONTRACT.md`](contracts/CATALOG_TRANSFER_CONTRACT.md) | JSONL/CSV catalog import/export contract. | CURRENT |
| [`contracts/BI_EXPORT_DATA_CONTRACT.md`](contracts/BI_EXPORT_DATA_CONTRACT.md) | BI export jobs, schemas, retries. | CURRENT |
| [`contracts/ADVANCED_REPORTING_DATA_CONTRACT.md`](contracts/ADVANCED_REPORTING_DATA_CONTRACT.md) | Advanced reporting dimensions and scope. | CURRENT |
| [`contracts/ANALYTICS_INTEGRATION_CONTRACT.md`](contracts/ANALYTICS_INTEGRATION_CONTRACT.md) | Analytics read models and export envelope. | CURRENT |
| [`contracts/HEADLESS_GRAPHQL.md`](contracts/HEADLESS_GRAPHQL.md) | Read-only allowlisted headless GraphQL API. | CURRENT |
| [`contracts/EXTENSION_PACKAGING_SPEC.md`](contracts/EXTENSION_PACKAGING_SPEC.md) | Extension runtime packaging, mounting, and lifecycle. | CURRENT |
| [`contracts/EXTENSION_ECOSYSTEM_SPEC.md`](contracts/EXTENSION_ECOSYSTEM_SPEC.md) | Siro-native commercial ecosystem: packages, QIT, license, slots, scoping. | CURRENT |
| [`contracts/EXTENSION_UI_SPEC.md`](contracts/EXTENSION_UI_SPEC.md) | Extension admin UI/slot contract. | CURRENT |
| [`contracts/07_EXTENSION_SDK.md`](contracts/07_EXTENSION_SDK.md) | Stable extension SDK interfaces and versioning. | CURRENT |
| [`contracts/STOREFRONT_THEME_VERTICAL_SPEC.md`](contracts/STOREFRONT_THEME_VERTICAL_SPEC.md) | Theme/vertical publish, preview, and revision contract. | CURRENT |
| [`contracts/THEME_PACKAGE_SPEC.md`](contracts/THEME_PACKAGE_SPEC.md) | Portable Siro theme package (`siro.theme/1`) import/export. | P1 |
| [`contracts/ORDER_OPERATIONS_MEDIA_SCHEDULER_SPEC.md`](contracts/ORDER_OPERATIONS_MEDIA_SCHEDULER_SPEC.md) | Order ops, media, and scheduler contracts. | CURRENT |

## Engineering and operations

### Engineering

| Document | Description | Status |
|---|---|---|
| [`engineering/INCIDENT_RUNBOOK.md`](engineering/INCIDENT_RUNBOOK.md) | SEV1-SEV4, on-call roles, detection, and failure playbooks. | CURRENT |
| [`engineering/OWNER_CONSOLE.md`](engineering/OWNER_CONSOLE.md) | Owner console domain, DNS/TLS, CORS, access control and rollback. | CURRENT |
| [`engineering/RUNBOOK.md`](engineering/RUNBOOK.md) | Release, backup/restore, rollback, prune, provider outage. | CURRENT |
| [`engineering/SLO.md`](engineering/SLO.md) | Proposed SLOs, observability signals, thresholds. | CURRENT |
| [`engineering/CI_QUALITY.md`](engineering/CI_QUALITY.md) | CI jobs, coverage gate, visual baseline flow. | CURRENT |
| [`engineering/DEPLOY_SAFETY.md`](engineering/DEPLOY_SAFETY.md) | What `deploy/release.sh` does and how to verify it. | CURRENT |
| [`engineering/SEARCH_MEILI.md`](engineering/SEARCH_MEILI.md) | Optional Meilisearch indexing, fallback, and ops. | CURRENT |
| [`engineering/SECURITY_NOTES.md`](engineering/SECURITY_NOTES.md) | CSP, FE trust guard, non-root containers, residual risk. | CURRENT |
| [`engineering/08_SECURITY_SIDE_EFFECTS.md`](engineering/08_SECURITY_SIDE_EFFECTS.md) | Auth/RBAC, input safety, webhook/payment safety rules. | CURRENT |
| [`engineering/09_PERFORMANCE_BUDGETS.md`](engineering/09_PERFORMANCE_BUDGETS.md) | Server/API performance budgets and proof dataset. | CURRENT |
| [`engineering/10_CODING_STANDARDS.md`](engineering/10_CODING_STANDARDS.md) | Mandatory backend/frontend coding standards. | CURRENT |
| [`engineering/30_TEST_STRATEGY.md`](engineering/30_TEST_STRATEGY.md) | Test layers and gate strategy. | CURRENT |
| [`engineering/COMMERCE_CLI.md`](engineering/COMMERCE_CLI.md) | Backend CLI control plane and recovery commands. | CURRENT |
| [`engineering/EXTENSION_BUILD_GUIDE.md`](engineering/EXTENSION_BUILD_GUIDE.md) | Build an extension from scaffold to enabled. | CURRENT |
| [`engineering/ECOSYSTEM_OPERATIONS.md`](engineering/ECOSYSTEM_OPERATIONS.md) | Catalog feed build/validate, Free/Pro releases, license, QIT and safe-mode. | CURRENT |
| [`engineering/EXTENSION_SCOPING.md`](engineering/EXTENSION_SCOPING.md) | Store/channel scope rules and helper for extension tables. | CURRENT |
| [`engineering/FRAMEWORK_ISSUES_UPSTREAM.md`](engineering/FRAMEWORK_ISSUES_UPSTREAM.md) | Historical upstream `sirosoft/core` issue log. | LEGACY |

### Operations

| Document | Description | Status |
|---|---|---|
| [`operations/CI_CD_RUNBOOK.md`](operations/CI_CD_RUNBOOK.md) | Supported CI/CD deploy, secrets, first run, rollback. | CURRENT |
| [`operations/EXTENSION_UPDATES.md`](operations/EXTENSION_UPDATES.md) | Extension/theme SemVer, install/upgrade/rollback runbook and checklists. | CURRENT |
| [`operations/EXTENSION_LICENSE_ISSUANCE.md`](operations/EXTENSION_LICENSE_ISSUANCE.md) | Offline license issuance, key custody, rotation and revocation. | CURRENT |
| [`operations/PRODUCTION_OPERATIONS.md`](operations/PRODUCTION_OPERATIONS.md) | Queue, preflight, live smoke, reporting/search ops. | CURRENT |
| [`operations/PRODUCTION_PROOF_RUNBOOK.md`](operations/PRODUCTION_PROOF_RUNBOOK.md) | Non-destructive proof, backup/restore preflight, evidence. | CURRENT |
| [`operations/PRODUCTION_EVIDENCE_POLICY.md`](operations/PRODUCTION_EVIDENCE_POLICY.md) | Evidence retention, redaction, and freshness policy. | CURRENT |
| [`operations/B2C_PRODUCTION_RELEASE_GATE.md`](operations/B2C_PRODUCTION_RELEASE_GATE.md) | Current B2C production release gate and status vocabulary. | CURRENT |
| [`operations/B2C_PROVIDER_CERTIFICATION.md`](operations/B2C_PROVIDER_CERTIFICATION.md) | Local provider certification and live-evidence rules. | CURRENT |
| [`operations/B2C_PROVIDER_CERTIFICATION_REPORT.md`](operations/B2C_PROVIDER_CERTIFICATION_REPORT.md) | Dated certification report (2026-09-30). | LEGACY |
| [`operations/PAYMENT_LIVE_CERTIFICATION.md`](operations/PAYMENT_LIVE_CERTIFICATION.md) | Payment certification command and exit codes. | CURRENT |
| [`operations/PRODUCTION_CERTIFICATION_MANIFEST.example.json`](operations/PRODUCTION_CERTIFICATION_MANIFEST.example.json) | Example certification manifest schema. | CURRENT |
| [`operations/V7_MYSQL_CONCURRENCY_EVIDENCE.md`](operations/V7_MYSQL_CONCURRENCY_EVIDENCE.md) | Historical MySQL concurrency proof instructions. | LEGACY |
| [`operations/B2C_SERVER_DEPLOYMENT_RUNBOOK.md`](operations/B2C_SERVER_DEPLOYMENT_RUNBOOK.md) | Manual server deployment (retired in favor of CI/CD). | SUPERSEDED |
| [`operations/12_RELEASE_GATES.md`](operations/12_RELEASE_GATES.md) | Early delivery roadmap and release-gate definitions. | SUPERSEDED |

### Audit and control plane

| Document | Description | Status |
|---|---|---|
| [`audit/README.md`](audit/README.md) | Audit principles, required files, status vocabulary. | CURRENT |
| [`audit/DOCS_AUDIT_2026-11-22.md`](audit/DOCS_AUDIT_2026-11-22.md) | Full docs audit: links, stale CI/license/API claims and residual follow-ups. | CURRENT |
| [`audit/00_AUDIT_CHARTER.md`](audit/00_AUDIT_CHARTER.md) | Audit scope, roles, independence, cycle. | CURRENT |
| [`audit/01_CONTROL_MATRIX.md`](audit/01_CONTROL_MATRIX.md) | Control objectives, evidence, owners, status. | CURRENT |
| [`audit/02_EVIDENCE_REGISTER.md`](audit/02_EVIDENCE_REGISTER.md) | Immutable evidence index and retention. | CURRENT |
| [`audit/03_RISK_REGISTER.md`](audit/03_RISK_REGISTER.md) | Risk scoring and treatment. | CURRENT |
| [`audit/04_RELEASE_AUDIT_CHECKLIST.md`](audit/04_RELEASE_AUDIT_CHECKLIST.md) | Release-by-release audit gate. | CURRENT |
| [`audit/05_FAILURE_DRILL_RECORD.md`](audit/05_FAILURE_DRILL_RECORD.md) | Failure drill evidence template (F01-F11). | CURRENT |
| [`audit/06_EXCEPTION_REGISTER.md`](audit/06_EXCEPTION_REGISTER.md) | Approved deviations and expiry. | CURRENT |
| [`audit/07_CHANGE_RECORD.md`](audit/07_CHANGE_RECORD.md) | Change impact and approval. | CURRENT |
| [`audit/08_SIGNOFF.md`](audit/08_SIGNOFF.md) | Engineering/security/operations sign-off. | CURRENT |
| [`audit/09_AUDIT_RUNBOOK.md`](audit/09_AUDIT_RUNBOOK.md) | Audit commands and collection workflow. | CURRENT |
| [`audit/10_COMPLIANCE_MAPPING.md`](audit/10_COMPLIANCE_MAPPING.md) | Control-family mapping without certification claim. | CURRENT |
| [`audit/09_VARIANT_SHIPPING_AUDIT.md`](audit/09_VARIANT_SHIPPING_AUDIT.md) | Point-in-time variant/shipping audit. | LEGACY |
| [`audit/10_PRODUCT_TYPE_RUNTIME_AUDIT.md`](audit/10_PRODUCT_TYPE_RUNTIME_AUDIT.md) | Point-in-time product-type runtime audit. | LEGACY |

## Foundation and legacy

### Foundation

| Document | Description | Status |
|---|---|---|
| [`foundation/00_PRODUCT_CONSTITUTION.md`](foundation/00_PRODUCT_CONSTITUTION.md) | Product promise, principles, personas, decision filter. | CURRENT |
| [`foundation/01_ARCHITECTURE.md`](foundation/01_ARCHITECTURE.md) | Modular monolith and module boundaries. | CURRENT |
| [`foundation/02_DOMAIN_MODEL.md`](foundation/02_DOMAIN_MODEL.md) | Domain model, aggregates, invariants. | CURRENT |
| [`foundation/03_API_SPEC.md`](foundation/03_API_SPEC.md) | API envelope/auth/webhook baseline. | CURRENT |
| [`foundation/20_ERD.md`](foundation/20_ERD.md) | ERD and aggregate boundaries. | CURRENT |
| [`foundation/21_EVENT_CATALOG.md`](foundation/21_EVENT_CATALOG.md) | Domain events and transition tables. | CURRENT |
| [`foundation/BE_OVERVIEW.md`](foundation/BE_OVERVIEW.md) | Backend entry point and contract summary. | CURRENT |
| [`foundation/BE_V2_SPEC.md`](foundation/BE_V2_SPEC.md) | Backend implementation baseline. | CURRENT |
| [`foundation/ENTERPRISE_BE_SPEC.md`](foundation/ENTERPRISE_BE_SPEC.md) | Enterprise governance and controls. | CURRENT |

### Historical records and phases

| Document | Description | Status |
|---|---|---|
| [`CG-004.md`](CG-004.md) | Closed CMS content-guarantee contract-gap record. | LEGACY |
| [`CG-005-008.md`](CG-005-008.md) | Closed reporting/session/error/transport gap records. | LEGACY |
| [`phases/PHASE_REMAINING_ROADMAP_SPEC.md`](phases/PHASE_REMAINING_ROADMAP_SPEC.md) | Remaining implementation roadmap (five tracks). | CURRENT |
| [`phases/PHASE_V4_ENTERPRISE_OPERATIONS_SPEC.md`](phases/PHASE_V4_ENTERPRISE_OPERATIONS_SPEC.md) | Phase V4 enterprise operations spec. | LEGACY |
| [`phases/PHASE_V5_GROWTH_ENTERPRISE_SPEC.md`](phases/PHASE_V5_GROWTH_ENTERPRISE_SPEC.md) | Phase V5 growth/enterprise spec. | LEGACY |
| [`phases/PHASE_V6_PRODUCTION_INTEGRATIONS_SPEC.md`](phases/PHASE_V6_PRODUCTION_INTEGRATIONS_SPEC.md) | Phase V6 integrations/ecosystem spec. | LEGACY |
| [`phases/PHASE_V7_PRODUCTION_PROOF.md`](phases/PHASE_V7_PRODUCTION_PROOF.md) | Phase V7 production proof pack. | LEGACY |

## Maintenance rules

1. Add or move a document through this index in the same change; an unlinked
   document is an orphan.
2. When a document is replaced, add the `> SUPERSEDED by <path> — kept for
   history.` banner instead of deleting it, unless it is a 100% duplicate with no
   inbound links; record any deletion in the change report.
3. Never mark a plan, evidence file, or proposal as a measured PASS. Use
   `NOT_PROVEN`, `BLOCKED`, or `LEGACY` explicitly.
4. Keep production IPs, hostnames, usernames, and secrets out of docs; use
   `<deploy-host>`, `<deploy-user>`, `<db-admin-user>`, and secret-manager
   references.
