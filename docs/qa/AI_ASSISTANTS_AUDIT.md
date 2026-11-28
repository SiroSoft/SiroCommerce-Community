# AI Assistants Audit / Dogfood

Date: 2026-11-27. Branch: `feature/ai-assistants` (PR #4). Status: **not ready
for live release**.

## Scope

`siro/staff-assistant`, `siro/shopper-assistant`, shared
`App\Modules\Ai\Application\AssistantService`, `optional-auth` extension route
support, admin chat panel, storefront widget, catalog entries 23, and the
language-pivot pipeline.

## Method

- Static review of route auth, role/capability gates, context whitelists,
  translation path, error handling, and prompt injection surface.
- `composer audit --locked`: **no advisories**.
- `npm audit --omit=dev`: findings below.
- QIT `route-auth`, `store-scope`, `secret-scan`, `php-lint`; extension
  `pack`/`qit` for both plugins.
- Full backend PHPUnit, PHPStan max, admin/storefront tests, typecheck/build.

## Findings

| ID | Severity | Finding | Status |
|---|---|---|---|
| AUD-01 | **P0** | `next@16.3.5` had critical/high advisories (image optimization RCE, `use cache` RCE, SSRF/DoS). | **FIXED in v0.3.2**: Next 16.4.6 live in release `20261127153143`; `npm audit` critical = 0. |
| AUD-02 | P1 | Free-text question values were sent before value-level PII redaction (key-based redactor only). | **FIXED**: email/phone/32+ hex token redaction before translation/model; test added. |
| AUD-03 | P1 | Owner “ask anything” currently has only order/product/customer tools; revenue, inventory, extensions/system, aggregate reports are not exposed yet. | OPEN — product gap, not a security bypass. |
| AUD-04 | P1 | Provider/JWT dogfood is local; no real staging UI. | PARTIAL: SenseNova passed core vi/en and real-JWT owner/staff + customer own-order API; browser UI/staging still blocked. |
| AUD-05 | P2 | Language target comes from request/scope locale; there is no independent language detection from question text. A user typing a different language than `X-Locale` gets the locale pipeline. | OPEN — document/UX: language selector or detection. |
| AUD-06 | P2 | Multilingual path can make three model calls (translate in, answer, translate out); no translation cache. Budget reservation is zero for translation calls. | OPEN — cost/latency hardening. |
| AUD-07 | P2 | Public shopper route is IP-throttled (20/min). With a configured provider, a public endpoint can still consume AI spend; default `AI_BUDGET_MINOR=0` does not block zero-cost reservations. | OPEN — operator must set provider budget/kill switch before exposing. |
| AUD-08 | P2 | Staff customer profiles are global in the current schema; owner-only PII and capability gate are enforced, but cross-store customer scoping is not native. | OPEN — existing schema behavior; document for multi-store. |
| AUD-09 | P3 | Audit rows store task/status/provider/model/usage only, not prompts/PII. Verified no secrets in scan. | PASS (metadata-only design). |
| AUD-10 | P3 | No full real-JWT HTTP dispatch test; loader token behavior and ownership logic are covered separately. | OPEN — add dogfood/E2E. |

## Dogfood matrix

| Surface | Evidence | Result |
|---|---|---|
| Staff owner/limited roles | `StaffAssistantAuthorizationTest` | PASS |
| Staff context read-only + customer masking | unit/feature tests | PASS |
| Staff admin chat panel | `staff-assistant-panel.test.tsx` | PASS (2 tests) |
| Shopper anonymous product Q&A | unit/component tests | PASS |
| Shopper signed-in own order | `ShopperAssistantContextTest` | PASS |
| Shopper guest token / invalid / cross-customer | `ShopperAssistantContextTest` | PASS |
| Multilingual en passthrough / vi pivot / failure fallback | `AssistantMultilingualTest` | PASS |
| PII value redaction | `AssistantMultilingualTest` | PASS |
| Real provider core pipeline (vi/en) | SenseNova dogfood | **PASS** |
| Real JWT API: owner staff + customer own order | Local staging SQLite + SenseNova | **PASS** |
| Real browser UI: shopper widget | Production demo + Playwright | **PASS** |
| Admin staff UI | Production `/admin` + Playwright | **PASS** |
| Browser mobile/a11y | not run | **BLOCKED** |
| Live/staging deployment | not done | **BLOCKED** |

## Real-provider dogfood (2026-11-27)

Provider: SenseNova `sensenova-6.8-flash-lite` via the OpenAI-compatible
`/v1/chat/completions` endpoint. No key is recorded in this document.

| Case | Result |
|---|---|
| Vietnamese shopper question -> English pivot -> Vietnamese answer | PASS: `"Vâng, trạng thái sản phẩm là hoạt động, vì vậy áo sơ mi vải lanh còn hàng."` |
| English staff order summary | PASS: `"Order 1001 is paid, with a grand total of 250000 minor units."` |

Important provider behavior: this reasoning model consumes output tokens for its
reasoning field. `AI_MAX_OUTPUT_TOKENS=512` (old default) produced no content;
2048 also failed for the Vietnamese translation path. The working configuration
is **4096 tokens, 120s timeout**, now the gateway default and documented in
`.env.example`. The assistant also accepts an explicit `answer` contract and
falls back to grounded scalar fields if the model returns an unknown JSON shape.

Production demo dogfood passed with real JWT + SenseNova: owner staff product
context via API and the shopper widget in a real browser. Admin UI could not be
reached because the admin origin is not configured; mobile/a11y browser checks
remain blocked.

## Gate results

- Backend: **1,959 tests / 17,677 assertions, 1 skipped** after the redaction
  test; PHPStan 0.
- Admin: **616 tests**; storefront: **657 tests**; typecheck/build pass.
- `extension:check`: 18/18; both AI packages pack + QIT pass.
- Catalog: 23 items; public feed drift/selftest pass.

## Release decision

AUD-01 is fixed and deployed in v0.3.2. AI assistants are still **not ready for
live** because AUD-03/04/05/06/07/08/10 remain. Next steps:

1. Configure a non-production provider and staging env.
2. Dogfood owner/limited staff/signed-in/guest in vi + en (then ja if enabled).
3. Re-run this audit and record real provider/JWT evidence.
4. Merge/release only after the staging dogfood is green.
