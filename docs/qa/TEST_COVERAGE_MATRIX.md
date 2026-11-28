# Test Coverage Matrix — 13 loại (R1-R9)

| # | Loại | Backend | Storefront | Admin | File tiêu chuẩn | Status |
|---|---|---|---|---|---|---|
| 1 | Unit Test | `tests/Unit/*` 88 tests `CatalogCrudTest` etc | `src/__tests__/money.test.ts` `catalog.test.ts` | `src/__tests__/auth-store.test.ts` `hooks.test.ts` | `426/426` | PASS |
| 2 | Integration / Feature | `tests/Feature/*` 15 `FullCoverageTest` 7, `tests/Integration/*` 45 | `src/__tests__/purchase.test.ts` | `src/__tests__/admin-state-consistency.test.tsx` | `1197/1197` | PASS |
| 3 | API Test | `tests/Api/*` 13 `CommerceOwnedHttpSemanticTest` + `api-handoff` | `src/__tests__/catalog-api.test.ts` `purchase-api.test.ts` | `src/__tests__/contract-regressions.test.ts` `api-types-regression` | `1197` | PASS |
| 4 | E2E Test | `—` (via API) | `e2e/checkout.spec.ts` `discovery-foundation.spec.ts` 8 files, `playwright.config.ts` | `e2e/admin-journey.spec.ts` `crud.spec.ts` `b2b.spec.ts` 18 files | `store 8 e2e, admin 18 e2e` | PASS |
| 5 | UI / Browser Test | — | `product-decision.test.tsx` `header.test.tsx` `deep-ux.test.tsx` `ui-tokens.test.tsx` | `deep-ux.test.tsx` `parity-coverage.test.ts` `write-safety-flows.test.tsx` | `279/43` `237/37` | PASS |
| 6 | Regression Test | `tests/Contract/*` 12 `CatalogTransferContractTest` | `src/__tests__/advanced-parity.test.ts` | `src/__tests__/contract-regressions.test.ts` `admin-qc.test.tsx` | PASS | PASS |
| 7 | Smoke Test | `tests/Workflow/*` 11 `HealthTest` `LaunchReadinessTest` | `e2e/launch-readiness.spec.ts` `e2e/quality.spec.ts` | `e2e/live-ops.spec.ts` `live-storefront-theme.spec.ts` | health `/health/live|ready` | PASS |
| 8 | Performance / Load | `vendor/sirosoft/core/scripts/loadtest-k6.js` + `tests/evidence/10` k6 `2842 req p95 761ms` `throttle:3000/min` | `motion-loading.test.tsx` reduced-motion | `tests/evidence` | k6 | PASS |
| 9 | Security Test | `tests/Security/SecurityTest.php` (new) XSS `javascript|data|<script` `DemoGuard` 107 `HMAC` `hash_equals` | `src/__tests__/deep-links.test.ts` tamper `X-Order-Token` | `src/__tests__/api-headers.test.ts` `X-Store-Id` `X-Siro-FE` | new | PASS |
| 10 | Visual Regression | — | `e2e/visual.spec.ts` (new) `toHaveScreenshot` `hero/card/product` | `e2e/visual.spec.ts` (new) `parity` `finance` screenshots | Playwright | PASS |
| 11 | Accessibility | — | `e2e/accessibility-performance.spec.ts` `storefront-accessibility.test.tsx` `a11y` `landmark/h1` | `e2e/accessibility.spec.ts` `Axe` | Axe | PASS |
| 12 | Contract Test | `tests/Contract/*` + `ApiContractTest` `03_API_SPEC` `OpenAPI` | `src/__tests__/envelope.test.ts` `purchase.test.ts` | `src/__tests__/admin-workbench-contract.test.ts` `reporting-contract.test.ts` | PASS | PASS |
| 13 | Database Test | `tests/Integration/CatalogCompletenessTest` `tests/Database/MigrationTest.php` (new) 105 migrations `erp_webhook_idempotency` `booking_slots` | — | `src/__tests__/product-media-variants.test.tsx` `media` FK | new | PASS |

Suite totals from local runs (2026-11-27; not a CI claim): internal backend **1,959**, admin **616**; Community backend **1,895**, admin **612**; storefront **657**. The per-layer counts above are a historical snapshot and do not reconcile to these suite totals. 13/13 types covered, `docs/qa/TEST_RULES.md` R1-R9 gate.

## Media library + product video (2026-10, Bagisto parity)
- `tests/Feature/MediaLibraryTest.php` (2): library index/filter-422/no-file-422/no-key-422/missing-404, product video URL validation (javascript: 422, youtube-as-image 422, youtube 201, mp4 autodetect video 201).
- FE: `gallery-video.test.tsx` (mp4 player + youtube embed allowlist + evil host rejected), `media-library.test.ts` (paths + idempotency).
- Upload mp4/webm product đã có từ trước (`CatalogCompletenessService::addUploadedMedia`); mới thêm: thư viện trung tâm `media_assets`, `type` image|video|youtube cho product media, gallery player + embed sandbox.

## Edge cases (2026-10, wave 1+2)
- `tests/Feature/EdgeCaseTest.php` (12): flash hết hạn/draft/tương lai ẩn, cap 0 unlimited, cap tràn, window ngược, combo max_sets/quà thiếu/hết, member giá cao hơn/currency lệch/tier sai, cart qty 0/-1/100/1M → 422, booking thiếu slot → 422.
- `tests/Feature/EdgeCase2Test.php` (4): flash áp giá ở place + hết suất mua giá thường, sale hết hạn giữa add-place → 422, update qty 0/100 → 422, bulk 201 dòng → 422 (đã fix `lines: required|array` trong B2BController — trước đó array bị validator chặn).
- FE: parser FK/malformed → undefined, countdown invalid → ended, ẩn section khi không có deal.

## Hasaki parity (2026-10)
- Flash-sale: `tests/Feature/HasakiParityTest.php::testFlashSaleCrudAndCap` (CRUD 201/409, cap sold-out, oversell rejected) + `FlashSaleService::recordSale` lock.
- Combo: `testComboBuyXGetY` (2 mua + 2 quà = 10000, thiếu quà báo lỗi).
- Member: `testMemberPriceValidation` (tier sai 422, lưu gold 201, không snapshot = 0).
- Store stock: `testInventorySources` (default 10 + store-hcm-01 3).
- FE: `apps/storefront/src/__tests__/hasaki-parity.test.tsx` (parser + badge/countdown + member/store UI), `apps/admin/src/__tests__/flash-sales.test.ts` (paths + idempotency).
