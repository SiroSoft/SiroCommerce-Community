# CI Quality Gate

> Status 2026-11-22: the workflow definitions are present but GitHub Actions is
> disabled (minutes exhausted), so these jobs do not run automatically. Coverage
> numbers below are from the last actual run, before the Nov 2026 ecosystem/theme
> changes; re-run the gates before quoting them as current evidence.

`.github/workflows/quality.yml` runs on `pull_request`, `push` to `main`, and
`workflow_dispatch`. Concurrent runs for the same ref are cancelled. It uses no
secrets and never deploys.

## Jobs

| Job | Runner | Command(s) | Timeout |
| --- | --- | --- | --- |
| `backend` | ubuntu-latest, PHP 8.2 + PCOV | `composer install`, `php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --error-format=github` (gate), informational `composer audit --no-interaction`, `php vendor/bin/phpunit --no-coverage`, then `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text > coverage.txt` + `node ../scripts/check-backend-coverage.mjs coverage.txt --min 80` (coverage gate, SQLite by default) | 30 min |
| `storefront` | ubuntu-latest, Node 20 | `npm ci`, informational `npm audit --omit=dev --audit-level=high`, `tsc --noEmit`, `npm run lint`, `npm run test:ci` (coverage gate), `next build` | 20 min |
| `admin` | ubuntu-latest, Node 20 | `npm ci`, informational `npm audit --omit=dev --audit-level=high`, `tsc --noEmit`, `vitest run --maxWorkers=1`, `next build` | 20 min |
| `e2e-a11y` | ubuntu-latest, Node 20 | Playwright Chromium + `npm run test:e2e:ci` (axe only) | 25 min |
| `e2e-visual-linux` | ubuntu-latest, Node 20 | Playwright Chromium + `playwright test -c playwright.quality.config.ts --grep "visual baseline" --update-snapshots`; uploads `*-linux` snapshots | 30 min |
| `e2e-visual-verify` | ubuntu-latest, Node 20 | downloads `storefront-visual-linux`, reruns the same grep without `--update-snapshots` | 30 min |

`e2e-a11y` depends on `storefront` and uploads `apps/storefront/test-results/`
when it fails. `e2e-visual-verify` depends on `e2e-visual-linux`. Visual jobs are
skipped on pull requests: `e2e-visual-linux` runs on `push`/`workflow_dispatch`;
`e2e-visual-verify` runs on `workflow_dispatch`, or on push when the repository
variable `VISUAL_VERIFY=1` is set. The storefront job uploads the `coverage/`
directory as the `storefront-coverage` artifact.

## Dependency and lockfile assumptions

- This is an npm **workspaces** monorepo. The root `package-lock.json` is the
  source of truth, so CI runs `npm ci` once at the repo root.
- `apps/storefront/package-lock.json` is stale (it predates the workspace
  dependencies) and must not be used for `npm ci`. The app-level `package.json`
  resolves through root `node_modules`.
- Composer dependencies live in `backend/`; `backend/composer.lock` keys the
  Composer cache.
- PHP 8.2 matches existing `ci.yml` and `backend/composer.json` (`php >=8.2`).
- Node 20 matches the existing workflows.

## Visual baselines are platform-specific

Playwright snapshots are stored per platform
(`__screenshots__/<project>-<platform>/...`). Committed baselines cover both the
local **win32** set (`desktop-win32`, `mobile-win32`) and the CI **Linux** set
(`desktop-linux`, `mobile-linux`). Linux CI must not reuse the win32 baselines,
and mixing platforms in one directory fails comparisons.

### Current status (2026-11-20)

- Linux baselines captured by `e2e-visual-linux` (run `44675067855`) and added
  under `apps/storefront/e2e/__screenshots__/desktop-linux/` and
  `mobile-linux/` (`home.png`, `category-apparel.png`, `cart.png`,
  `checkout.png` in each). Two independent CI captures were byte-identical.
- Cost: ~1.0 MB desktop `home.png`, ~0.68 MB mobile `home.png`; the rest are
  ~0.17-0.25 MB each.
- `e2e-visual-verify` is enabled on push through the repository variable
  `VISUAL_VERIFY=1` (set 2026-11-20), in addition to `workflow_dispatch`.
- The same-run dispatch reported `Storefront visual verify (Linux)` **success**
  without `--update-snapshots`.

`e2e-a11y` therefore stays axe-only:

```bash
npm run test:e2e:ci --workspace apps/storefront
# playwright test -c playwright.quality.config.ts \
#   --grep-invert "visual baseline" --reporter=line
```

### Linux capture -> artifact -> commit -> verify

The capture job always uses `--update-snapshots`, so it writes `*-linux/` files
instead of failing when they are absent; it is not `continue-on-error`. The
artifact is the handoff used to refresh the committed baseline.

Regenerate the Linux baseline:

1. Trigger a run from `main` (`gh workflow run quality.yml --ref main`, or push to
   `main`) and wait for `e2e-visual-linux`.
2. Download the artifact (`gh run download <run-id> --name storefront-visual-linux
   --dir <tmp>`). It contains:
   ```text
   desktop-linux/home.png
   desktop-linux/category-apparel.png
   desktop-linux/cart.png
   desktop-linux/checkout.png
   mobile-linux/... (same screens)
   ```
3. Copy the two `*-linux/` directories into
   `apps/storefront/e2e/__screenshots__/`, keeping the template layout
   `{projectName}-{platform}`. Do not overwrite `desktop-win32/` or
   `mobile-win32/`.
4. Commit and push. `e2e-visual-verify` then runs on push because
   `VISUAL_VERIFY=1` is set, or via `workflow_dispatch`. It downloads
   `storefront-visual-linux` and reruns the grep without `--update-snapshots`:
   ```bash
   npx playwright test -c playwright.quality.config.ts --grep "visual baseline"
   ```
   Note: verify compares against the artifact captured in the same run, so it
   proves capture determinism and path/template correctness. It is not a diff
   against the previously committed baseline; compare artifact hashes when you
   need to confirm the committed files are unchanged.

Regenerate locally only on the same platform that owns the baseline:

```bash
# Windows (win32 baselines)
npm run qa:visual:update --workspace apps/storefront
```

Do not run the update command on Windows and commit the result as Linux
baselines. If a baseline file is missing from the artifact, the capture job still
passes; a verification failure means the rendered page changed, not that a
baseline file is absent.

## Coverage gate

`npm run test:ci` runs `vitest run --coverage --maxWorkers=1`.
`apps/storefront/vitest.config.mts` enforces:

| Metric | Threshold | Measured 2026-11-15 (`test:ci`) | Margin |
| --- | --- | --- | --- |
| Statements | 48% | 55.14% | 7.14 |
| Branches | 43% | 51.34% | 8.34 |
| Functions | 48% | 55.94% | 7.94 |
| Lines | 58% | 65.32% | 7.32 |

Measured with `npm run test:ci` in `apps/storefront/` (62 test files, 388
tests). A clean checkout without the local uncommitted files measured higher
(statements 64.44%, branches 59.08%, functions 63.72%, lines 74.79%); thresholds
use the lower, conservative run so committed untested code does not make the gate
flaky. Re-measure on a clean checkout and update this table before changing
thresholds; never raise a threshold to a number that was not measured.

Backend coverage is a **gate** in the `backend` job: `shivammathur/setup-php`
installs PCOV, `backend/phpunit.xml` declares
`<source><include><directory suffix=".php">app</directory></include></source>`,
and CI runs `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text` followed
by `node ../scripts/check-backend-coverage.mjs coverage.txt --min 80`.

Current measured baseline (2026-11-21, full suite, SQLite):

| Metric | Coverage |
| --- | --- |
| Classes | 29.13% (141/484) |
| Methods | 61.04% (1797/2944) |
| Lines | 80.06% (17777/22205) |

The gate tracks `lines` at a minimum of **80%**. Re-measure before raising the
threshold; never set it above a measured run.

## PHPStan gate and baseline policy

PHPStan is a **gate** in the `backend` job: `backend/phpstan.neon` runs at
`level: max` over `app/`, `database/`, `routes/`, and `public/`, with
`backend/phpstan-baseline.neon` included. CI runs
`php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress --error-format=github`
(step timeout 10 min) and the result cache is stored in `/tmp/phpstan`, keyed on
`phpstan.neon`, `phpstan-baseline.neon`, and `composer.lock`. A non-zero exit
fails the job, so new errors cannot merge.

Measured 2026-11-20 with PHPStan 2.2.14 (PHP 8.2.30, `level: max`):

| Item | Value |
| --- | --- |
| Errors before baseline | 1044 across 616 baseline entries (report was table-limited to 1000) |
| Baseline file | `backend/phpstan-baseline.neon`, ~140 KB, 1044 errors |
| Non-baselineable errors | 2, fixed by renaming a private helper (`AdminWorkbenchController::user()` -> `requireUser()`) that shadowed `Siro\Core\Controller::user()` |
| Errors after baseline | 0 (`[OK] No errors`, exit 0) |

Largest error clusters: `app/Repositories/ProductRepository.php` (95),
`app/Controllers/ConfigurationController.php` (45),
`app/Modules/Platform/Application/AdminSubscriptionService.php` (43),
`app/Modules/Finance/Application/FinancialDocumentQueryService.php` (31),
`app/Modules/Checkout/Application/PlaceOrderService.php` (29).

Baseline policy:

- Do **not** add new baseline entries for new code. New or changed lines must be
  clean under `level: max`; fix the underlying type instead of suppressing it.
- The baseline may only shrink. When a file is cleaned up, regenerate the
  baseline so removed errors disappear; never regenerate to hide a regression.
- Do not lower the level or add files to `excludePaths` to go green.
- Do not add `@phpstan-ignore` comments or widen types just to silence errors.
- Regenerate only when intentionally removing baseline errors:

  ```bash
  cd backend
  composer phpstan:baseline
  composer phpstan            # must print "[OK] No errors"
  ```

  Review the diff: entries must be removed, not added. Commit the baseline with
  the code that fixed the errors.

`composer analyse` and `composer phpstan` are equivalent local entry points;
`composer phpstan:baseline` writes `phpstan-baseline.neon`.

## Dependency audit

Audits are **informational** in this workflow (`continue-on-error: true`) so a
new advisory does not block unrelated PRs while the repository is reviewed. No
dependency was upgraded as part of adding these steps.

- Backend: `composer audit --no-interaction` in `backend/`. Measured
  2026-11-20: **0 advisories** (exit 0).
- Frontends: `npm audit --omit=dev --audit-level=high` at the repository root
  (npm workspaces cover `apps/storefront` and `apps/admin`). Both jobs run it
  after `npm ci`. Measured 2026-11-20: **6 vulnerabilities (2 moderate, 3 high,
  1 critical)**, e.g. `next` (critical; fix requires `next@16.4.6`, outside the
  declared range), `sharp`, `source-map-js`, `zod` (high), and
  `postcss-selector-parser` (moderate). `npm audit fix --force` is breaking and
  must not be applied unreviewed.

When an audit starts failing meaningfully, prefer a scoped dependency bump in
its own PR over turning the step into a hard gate or forcing `--force` fixes.

## SLO and observability

Proposed SLOs, measurement sources, and alert-to-runbook mapping live in
[`SLO.md`](SLO.md). That document is the source for availability, latency,
checkout, payment, and backup objectives; do not quote those targets as measured
until the referenced signals have data.

## Reproduce locally

```bash
# Backend
cd backend
composer install
composer phpstan            # PHPStan level max + baseline (must be 0 errors)
composer audit              # informational dependency audit
DB_CONNECTION=sqlite TEST_DB_DRIVER=sqlite php vendor/bin/phpunit --no-coverage
# Coverage gate (requires PCOV or Xdebug coverage mode):
DB_CONNECTION=sqlite TEST_DB_DRIVER=sqlite php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text > coverage.txt
node ../scripts/check-backend-coverage.mjs coverage.txt --min 80

# Storefront
npm ci
npm audit --omit=dev --audit-level=high   # informational
cd apps/storefront
npx tsc --noEmit
npm run lint
npm run test:ci          # coverage gate
npm run build
npx playwright install chromium
npm run test:e2e:ci
# Linux visual capture/verify (run on Linux):
npx playwright test -c playwright.quality.config.ts --grep "visual baseline" --update-snapshots
npx playwright test -c playwright.quality.config.ts --grep "visual baseline"

# Admin
cd apps/admin
npx tsc --noEmit
npx vitest run --maxWorkers=1
npm run build
```
