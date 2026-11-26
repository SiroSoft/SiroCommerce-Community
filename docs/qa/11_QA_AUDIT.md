# 11 — QA & Audit (test matrix + gates + failure drills)

## 1. Test matrix
| Tầng | Scope | Tool | Gate |
|---|---|---|---|
| Unit BE | Services/Repos/Transitions/Money/Pricing/Promo/SEO helpers | PHPUnit | 0 fail, coverage ≥80% domain |
| Integration BE | cart→checkout→order→payment→shipment E2E thật (MySQL 8 `sirocommerce_test`, JWT_COST=4) | PHPUnit | 0 fail |
| Concurrency | inventory race (parallel purchase), duplicate webhook, duplicate checkout key | PHPUnit + script | không oversell, không double-charge |
| Contract | openapi.json vs FE types vs form fields (`check-mapping` + `check-field-mapping`) | scripts | exit 0 |
| Unit FE | schemas/utils/stores/api-mapping/error unwrap | Vitest | 0 fail |
| E2E FE | login→product→cart→checkout→order→refund; demo-readonly; turnstile; mapping-smoke | Playwright `--workers=1` | 0 fail/flaky |
| UAT | `docs/qa/UAT.md` UAT-01… map 1:1 e2e, gồm UX targets (04) | manual sign-off | 100% pass |
| Perf/SEO | budgets (09) + SEO regression (canonical/sitemap/redirect/schema) | lighthouse + suite | pass |

Lưu ý từ ERP: chạy PHPUnit trực tiếp (tránh .bat wrapper mất `-d`), stub repo phải mirror schema thật, migration thật trong TestCase (cấm DDL inline mới).

## 2. Mandatory failure drills (10, và vẫn phải pass mới release)
Payment timeout; duplicate webhook; shipping timeout; queue/worker outage; cache outage; SMTP outage; DB tx failure; duplicate checkout; inventory race; extension failure; upgrade/rollback.

## 3. Audit checklist (mỗi PR/release đánh dấu)
**BE:** `DB::table` ngoài Models = 0; Controller import Repository = 0; write nào cũng validate + allowlist; transaction ở Service; money INT; transition hợp lệ; webhook verify HMAC; idempotency key UNIQUE; log redact; throttle auth/payment.
**FE:** không `any`; key i18n đủ 4 locales; field-error từ `meta.errors`; không hardcode toast; query key đúng factory; a11y + dark-mode + mobile check.
**Data:** snapshot order bất biến; ledger khớp `on_hand/reserved`; earning report không claim causation.
**Release:** openapi regenerate + sync public; lockfile commit; backup DB+uploads; rollback artifact sẵn.

## 4. Production proof
Dataset 10k/50k/100k như (09); benchmark Home/Category/Product/Search/Cart/Checkout/API (query count, N+1, cache, memory). Theo dõi 15–30' sau deploy: health, error rate, auth, Turnstile, CORS.
