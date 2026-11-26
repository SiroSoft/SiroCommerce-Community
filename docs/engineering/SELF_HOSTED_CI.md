# Self-hosted CI for multi-team development

Status: CURRENT (bootstrap plan). GitHub Actions hosted minutes are exhausted.
Self-hosted runners are the CI path for **private/internal** repositories and
must not be attached to the public Community repository for untrusted PRs.

## 1. Repository model

| Repository | Visibility | CI |
|---|---|---|
| `SiroCommerce` (internal monorepo) | PRIVATE | self-hosted GitHub runner `siro-ci` |
| `SiroCommerce-Pro` | PRIVATE | self-hosted or private CI |
| `SiroCommerce-Community` | PUBLIC | release mirror; GitHub-hosted/manual release verification only |

Never point a self-hosted runner at a public repository. A public PR can execute
arbitrary code on the runner. If public PR CI is needed, use an isolated public
CI service or GitHub-hosted runners.

## 2. Runner requirements

- Dedicated Linux x64 host, not the production commerce host.
- Docker installed; runner service runs as a non-root user.
- 4 vCPU / 8 GB RAM minimum; 8 vCPU / 16 GB recommended for parallel teams.
- Ephemeral jobs: clean workspace and Docker containers after every run.
- Labels: `self-hosted`, `linux`, `x64`, `siro-ci`.
- No production secrets, deploy keys, customer data or license private keys on
  the runner.
- Runner group restricted to selected private repositories in the SiroSoft org.

## 3. Install (private/internal repo only)

1. Create a runner registration token in GitHub:
   `Settings -> Actions -> Runners -> New self-hosted runner`.
2. On the CI host:

```bash
sudo env GITHUB_ORG=SiroSoft \
  GITHUB_REPO=SiroCommerce \
  RUNNER_TOKEN=<registration-token> \
  RUNNER_NAME=siro-ci-01 \
  RUNNER_VERSION=<runner-version> \
  sh deploy/ci/install-github-runner.sh
```

3. Confirm the runner appears with labels `self-hosted`, `linux`, `x64`,
   `siro-ci`.
4. Set the runner group to private repositories only.

## 4. Workflow

Copy `deploy/ci/selfhosted-quality.yml` into the private monorepo as
`.github/workflows/selfhosted-quality.yml`. It runs:

- backend PHPStan + PHPUnit + coverage gate;
- storefront typecheck + tests + build;
- admin typecheck + tests + build;
- path-filtered jobs so teams do not run the whole matrix for docs-only changes.

Do not add the `runs-on: [self-hosted, ...]` workflow to the public Community
repository.

## 5. Branch protection

Protect `main` on internal, Pro and Community repositories:

- pull request required;
- 1 approval;
- CODEOWNERS review;
- conversation resolution;
- no force-push/delete;
- linear history;
- required checks after CI is green:
  - `Backend (PHP 8.2 + PHPUnit)`
  - `Storefront (typecheck, lint, unit, build)`
  - `Admin (typecheck, unit, build)`

Until self-hosted CI is live, do not mark checks as required; use CODEOWNERS
review and manual release gates so teams are not blocked by a red CI that cannot
run.

## 6. Local gate while CI is unavailable

Run before every PR:

```bash
cd backend && composer install && php vendor/bin/phpstan analyse --no-progress && php vendor/bin/phpunit --no-coverage
npm ci
npm run typecheck
npm test --workspace apps/storefront
npm test --workspace apps/admin
npm run build --workspace apps/storefront
npm run build --workspace apps/admin
```

Record the command, revision and result in the PR. Do not claim CI ran when it
did not.
