# AGENTS.md

Instructions for coding agents working in this repository. Keep this file short;
the linked docs are authoritative for detail.

## Repository map

```text
apps/admin/        Next Admin UI
apps/marketing/    static multilingual marketing site (Cloudflare Pages)
apps/storefront/   Next storefront, unit tests, Playwright E2E
packages/          shared api-client, auth, scope, ui, config
backend/           SiroPHP API (PHP 8.2, PHPUnit, PHPStan)
docs/              product, contracts, engineering, operations, QA, audit
deploy/            release scripts, Dockerfiles, compose (do not edit casually)
.github/workflows/ ci.yml, quality.yml, deploy.yml
```

Root is an npm **workspaces** monorepo. Use the root `package-lock.json`; the
`apps/storefront/package-lock.json` is stale and must not be used for `npm ci`.

## Commands

Run `npm ci` once at the repo root before JS work.

Backend (from `backend/`):
```bash
composer install
composer test                       # php vendor/bin/phpunit --no-coverage
composer test:unit                  # or test:integration / test:api / test:workflow
composer analyse                    # phpstan level max
php -l <file.php>
```

Storefront (from repo root or `apps/storefront/`):
```bash
npm run typecheck --workspace apps/storefront
npm run lint --workspace apps/storefront
npm test --workspace apps/storefront
npm run test:ci --workspace apps/storefront      # coverage gate
npm run build --workspace apps/storefront
npm run test:e2e:ci --workspace apps/storefront  # axe only
npm run qa:visual:update --workspace apps/storefront  # platform-specific snapshots
```

Admin (from `apps/admin/`):
```bash
npx tsc --noEmit
npx vitest run
npm run build
```

Root checks:
```bash
npm run typecheck
npm test
npm run verify
```

## Commit conventions

`feat|fix|refactor|perf|test|docs|chore|security(scope): imperative summary in English`

Never commit secrets, credentials, `.env` files, or generated evidence with
private data. Do not commit or deploy unless the user explicitly asks.

## Scope and source of truth

- The backend is the source of truth for prices, totals, taxes, order state, and
  authorization. Frontends render server decisions; they never re-compute money.
- Change only files in the requested scope. Do not edit `deploy/**`, migrations,
  or unrelated apps to make a test pass.
- Domain boundaries: Controller -> Service -> Repository -> Model. Controllers
  do HTTP only. See [`docs/engineering/10_CODING_STANDARDS.md`](docs/engineering/10_CODING_STANDARDS.md).
- Reuse existing `packages/` transport/session/scope/UI boundaries; do not add a
  second API client or auth layer.
- Extensions follow [`docs/engineering/EXTENSION_BUILD_GUIDE.md`](docs/engineering/EXTENSION_BUILD_GUIDE.md).

## Evidence and data rules

- Never fabricate test results, coverage, metrics, SLO values, timestamps, or
  production evidence. If something was not run or has no data, write
  `NOT_PROVEN` or `BLOCKED`.
- Coverage numbers quoted in docs come from an actual run; record the command and
  date. Current storefront gate is defined in
  [`docs/engineering/CI_QUALITY.md`](docs/engineering/CI_QUALITY.md).
- SLO targets are proposals, not measurements. See
  [`docs/engineering/SLO.md`](docs/engineering/SLO.md).
- Keep secrets out of logs, command arguments, fixtures, and tickets.

## CI gate

> GitHub Actions is currently disabled (minutes exhausted). The workflows below
> are the intended gate but do not run on push/PR. Do not claim CI-gated results;
> run the same commands locally and record the output.

PR/push run [`.github/workflows/quality.yml`](.github/workflows/quality.yml):
backend PHPUnit, storefront typecheck/lint/unit+coverage/build, admin
typecheck/unit/build, and axe E2E. Linux visual baseline capture runs on
`push`/`workflow_dispatch`; verification runs on `workflow_dispatch` or when the
repository variable `VISUAL_VERIFY=1` is set. Do not bypass a red gate; fix the
cause or mark the limitation explicitly. [`ci.yml`](.github/workflows/ci.yml) is
also called by the deploy workflow.

## Deploy and rollback

Production is normally released by CI (`.github/workflows/deploy.yml`) once
Actions is re-enabled. While Actions is disabled, use `deploy/release.sh` with a
clean release tarball (never from a dirty working tree) and record the release
ID/health evidence. Release, backup/restore, rollback, disk prune, provider
outage, and alert webhook procedures live in
[`docs/engineering/RUNBOOK.md`](docs/engineering/RUNBOOK.md) and
[`docs/operations/CI_CD_RUNBOOK.md`](docs/operations/CI_CD_RUNBOOK.md). Incident
severity, on-call roles, and failure playbooks are in
[`docs/engineering/INCIDENT_RUNBOOK.md`](docs/engineering/INCIDENT_RUNBOOK.md).
Do not invent an ad-hoc `docker compose up` deployment.

## Further reading

- [`docs/engineering/CI_QUALITY.md`](docs/engineering/CI_QUALITY.md): jobs, coverage, visual flow.
- [`docs/engineering/SLO.md`](docs/engineering/SLO.md): proposed SLOs and observability signals.
- [`docs/engineering/30_TEST_STRATEGY.md`](docs/engineering/30_TEST_STRATEGY.md): test layers.
- [`docs/operations/PRODUCTION_OPERATIONS.md`](docs/operations/PRODUCTION_OPERATIONS.md): queue and provider operations.
- [`docs/README.md`](docs/README.md): documentation index.
