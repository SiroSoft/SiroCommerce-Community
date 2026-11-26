# TEST-1 — Test Strategy chuẩn 100% (bám sát spec docs/09, 10, 11, 27, 28)

> Định nghĩa "chuẩn 100%": đủ 4 bộ + gates xanh mới được merge/release. Không có ngoại lệ "bỏ qua test flaky".

## 1. Ma trận 4 bộ
| Bộ | Scope (spec ref) | Tool | Gate 100% |
|---|---|---|---|
| Unit BE | Money VO, pricing, promo rules, transitions (02), contracts (07), validators, SEO helpers | PHPUnit suite `unit` | 0 fail, coverage ≥80% domain, `assertSame` strict |
| Integration BE | cart→checkout→order→payment→shipment E2E thật; idempotency (AC-A03/A04); webhook HMAC; race inventory (AC-D03); drills F01–F11 | PHPUnit suites `feature/integration`, MySQL 8 `sirocommerce_test`, JWT_COST=4 | 0 fail; không oversell/double-charge; ledger khớp |
| E2E / UI FE | UAT-01→UAT-12: login→product→cart→checkout→refund; demo-readonly; turnstile; mapping-smoke; UX targets đo giờ (AC-U01→U07); a11y + dark-mode + mobile | Vitest (unit/comp) + Playwright `--workers=1` | 0 fail/flaky; UAT 100% sign-off |
| Perf / Load | Budgets docs/09 trên dataset 10k/50k/100k: p95, query count, N+1, cache hit, memory; soak + spike checkout | k6 (load) + `php scripts/bench.php` (budget assert) + Lighthouse | p95 trong budget; 0 N+1; vượt budget = block release |

## 2. Quy tắc cứng (từ ERP + core, áp nguyên)
- BE chạy PHPUnit **trực tiếp** binary (tránh .bat wrapper mất `-d max_execution_time=0`); DB test MySQL (cấm SQLite cho integration); migrations thật trong TestCase (cấm DDL inline mới); stub mirror schema thật.
- Mọi test mock external (gateway/ship/mail) — cấm gọi API thật. `tearDown` dọn sạch. Tiền assert bằng minor INT.
- FE: `userEvent` (cấm fireEvent), `findBy*/waitFor` cho async, store reset trước mỗi test, schema via `.safeParse`, E2E creds qua env + skip khi thiếu.
- Perf: mỗi benchmark ghi dataset size + query count + cache hit + memory; regression phải kèm EXPLAIN + fix index.

## 3. Gates trước merge / release
Merge: `php -l` + PHPStan max No errors + `composer audit` 0 + PHPUnit 0 fail + `tsc` + vitest 0 fail + i18n:check + mapping scripts exit 0.
Release: + Playwright 0 fail + k6/bench trong budget + SEO regression pass + UAT 100% + backup/rollback sẵn (docs/12).
