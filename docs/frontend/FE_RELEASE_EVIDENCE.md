# FE Release Evidence and Traceability

Status: `RELEASE RECORD STANDARD`

This is the evidence contract for frontend release approval. Evidence is a
verifiable artifact, not a status claim. A release is blocked by missing,
stale, non-reproducible, or unowned evidence.

## Required release record

Each release record contains release id, commit, build artifact digest, source
environment, deployment target, test timestamp, browser/device matrix, flag
snapshot, API artifact versions, approvers, exceptions, and rollback reference.
Artifacts are immutable and retained according to the production evidence
policy.

## Traceability key

Use this key in test names, filenames, dashboards, and screenshots:

`<release>-<screen>-<operationId>-<acceptanceId>-<artifactType>`

Examples: `r42-checkout-post_api_checkout_place-FIN-02-playwright.json` and
`r42-admin-invoice-get_api_admin_invoices_id-A11Y-03-axe.html`.

For screens with no API call, use `operationId=none` and cite the relevant
route/flag. For one screen with multiple operations, create one row per
operation. Never use a hand-maintained URL list as a substitute for generated
operation ids.

## Evidence matrix

| Control | Required artifacts | Minimum gate |
|---|---|---|
| API contract | OpenAPI hash, generated SDK hash, handoff result, operation mapping | contract checks pass; no manual SDK edits |
| I18N/finance | locale parity output, money/time fixture report, screenshots | all `FE_I18N_FINANCE` gates pass |
| Accessibility | axe output, manual keyboard/screen-reader checklist, videos for failures | WCAG 2.2 AA thresholds pass |
| Responsive/browser | Playwright matrix, viewport screenshots, defect report | all critical journeys pass |
| Performance | Lighthouse, RUM canary, bundle sizes, Web Vitals, API trace | all budgets pass |
| Flags | config snapshot, cohort metrics, approvals, rollback drill | rollout gates pass |
| Functional FE | Vitest, typecheck, Playwright, i18n check, route smoke | 0 failures and no unwaived flakes |
| Security/privacy | auth/role smoke, redaction review, no token/PII artifacts | no exposure or boundary failure |

## FE test strategy

The suite is layered and evidence-producing:

- Unit tests cover money, date/time, locale selection, flag evaluation, error
  normalization, and query-key rules with deterministic fixtures.
- Component tests cover translated labels, form errors, loading/empty/error
  states, focus management, and responsive overflow at boundary widths.
- Contract tests validate generated operation ids, request headers, envelopes,
  stable error codes, pagination, idempotency, and scope against OpenAPI.
- Workflow tests cover guest browse-to-order, account/privacy, admin order and
  inventory, reports/export, unauthorized access, retry, and rollback paths.
- Browser tests run the responsive/browser matrix with real keyboard input and
  accessibility scans. Performance tests run Lighthouse, bundle checks, RUM
  canary, and API-correlated readiness measurements.

Merge gates are typecheck, unit/component, i18n parity, contract, and lint
checks. Release gates add browser workflows, accessibility, responsive,
performance, flag rollback, and evidence completeness. A flaky test is a
failure until quarantined with an owner, issue, reproduction, and expiry; it is
not silently retried into a pass.

## Screen and operation mapping

The screen inventory is derived from the FE plan, role matrix, and OpenAPI
artifacts. A row must identify persona/role, route, viewport, locale, currency,
timezone, feature flags, fixture id, primary operation ids, expected statuses,
and evidence links. Typical mappings include:

| Screen | Primary operation ids |
|---|---|
| Storefront catalog | `get_api_products` |
| Product detail | `get_api_products_slug` |
| Cart creation | `post_api_carts` |
| Checkout placement | `post_api_checkout_place` |
| Account consent | `post_api_account_consents` |
| Privacy anonymization | `post_api_account_privacy_anonymize` |
| Admin invoice | `get_api_admin_invoices_id`, `get_api_admin_invoices_id_evidence` |
| Version/health platform | `get_api_version` plus the exact generated health/readiness ids |

The last row intentionally requires lookup in the generated contract rather
than inventing an operation id. Any operation id absent from the current
OpenAPI artifact is a traceability blocker.

## Release acceptance

Approval requires: all required artifact rows present; artifact hashes match the
release; all critical screens have success and documented 401/403/404/409/422/
429/500 evidence where applicable; no P0/P1 defects; no unexplained flaky test;
performance, accessibility, i18n, responsive, and flag gates green; and a
successful rollback or an approved unchanged rollback from the prior release.

An exception records id, control, impact, owner, mitigation, expiry, approver,
and explicit release risk. Expired exceptions are failures, not carry-forward
notes.

## Current baseline findings

- API handoff reports the current route count and backend evidence records from
  `backend/docs/api-handoff/latest-result.json` (the checked-in counts are not a
  substitute for rerunning `npm run api:handoff`),
  but this does not constitute FE screen, accessibility, responsive, or CWV
  evidence. Those artifacts remain required.
- The storefront exposes Vitest, typecheck, i18n, Playwright quality/visual suites, and a bundle budget; the admin exposes Vitest, typecheck, build, and `test:e2e`. There is still no single root release-evidence command covering Lighthouse, RUM, the full browser matrix, and all admin/storefront journeys, so those artifacts remain `NOT RUN` until equivalent controlled jobs exist.
- Locale count and error-envelope documentation conflict as recorded in
  `docs/frontend/FE_I18N_FINANCE.md`.
