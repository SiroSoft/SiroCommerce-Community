# Contributing to SiroCommerce

## Source model

SiroCommerce uses an internal private source of truth. The public
`SiroCommerce-Community` repository is a **release mirror** for the free/core
edition.

- Internal repo: development, reviews, Pro work and releases.
- Community: public read-only mirror; do not treat it as the primary codebase.
- Pro: private commercial mirror.

External code changes are accepted as proposals. Maintainers port accepted
changes into the internal repository, run the release gates and publish them in a
later Community release. Opening a PR does not guarantee it will be merged
directly into the mirror.

## Before you start

- Read [`docs/engineering/TEAM_ONBOARDING.md`](docs/engineering/TEAM_ONBOARDING.md).
- Read [`docs/engineering/RELEASE_MODEL.md`](docs/engineering/RELEASE_MODEL.md).
- For a security issue, follow [`SECURITY.md`](SECURITY.md); do not open a public
  issue.

## Issues

Include:

- version/tag, environment and browser/runtime;
- steps to reproduce;
- expected vs actual behavior;
- logs or screenshots with secrets and customer data removed;
- whether the issue affects Free, Pro or both.

## Pull requests

- Keep changes scoped; one concern per PR.
- Do not add secrets, `.env`, license files, customer data, build output or
  `node_modules`.
- Do not add Pro source, pricing, license issuance or private runbooks to the
  public repository.
- Update tests and docs with behavior changes.
- Update `CHANGELOG.md` for user-visible changes.
- Extension/theme changes must pass QIT and keep store/channel scope.
- Use the PR template and include local verification evidence; CI may be
  unavailable.

## Local development

```bash
cd backend
composer install
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
php vendor/bin/phpunit --no-coverage
cd ..

npm ci
npm run typecheck
npm test --workspace apps/storefront
npm test --workspace apps/admin
npm run build --workspace apps/storefront
npm run build --workspace apps/admin
```

Docs-only changes may skip backend/frontend runs, but state that explicitly.

## Extension and theme contributions

- One package per bounded context.
- Extension tables use `ext_*` plus `store_id` and `channel_id`.
- Routes live under `/api/ext/<vendor>/<name>` and use auth/capability and
  idempotency where required.
- Themes are data-only: no PHP, JS, HTML or SVG execution.
- Include README, tests, manifest/version and QIT evidence.

## License

By contributing, you agree that your contribution is licensed under the MIT
License for the Community edition. Pro contributions are governed by the private
commercial agreement.
