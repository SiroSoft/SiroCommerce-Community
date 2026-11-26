# Audit Runbook

## 1. Collect Local Engineering Evidence

Run from `backend/`:

```powershell
php -l app/...
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php vendor/bin/phpunit --no-coverage -c phpunit.xml
php scripts/check-mapping.php
php scripts/check-i18n.php
php scripts/security-audit.php
php scripts/bench.php
```

For the required MySQL integration run, use a local/isolated database user;
never commit its password:

```powershell
$env:TEST_DB_DRIVER='mysql'
$env:TEST_DB_HOST='127.0.0.1'
$env:TEST_DB_PORT='3306'
$env:TEST_DB_DATABASE='sirocommerce_test'
$env:TEST_DB_USERNAME='test_runner'
$env:TEST_DB_PASSWORD='<provided-outside-repository>'
php vendor/bin/phpunit --no-coverage -c phpunit.xml
```

Run from the relevant monorepo workspace (`apps/admin/` or `apps/storefront/`):

```powershell
npm run i18n:check
npm test
npm run typecheck
```

Record exact output, versions, commit, date, and SHA-256 in the evidence
register. These commands do not replace MySQL/load/restore evidence.

## 2. Collect Production Proof

```powershell
php scripts/bench.php --base-url=https://host --dataset=10000 --samples=20
php scripts/verify-backup.php --file=backup.sql --sha256=<64-hex>
```

Load tests must run through the approved k6 profile and store sanitized result
artifacts. Restore must run on an isolated environment and include smoke tests.

## 3. Review Workflow

1. Create change record.
2. Map changed behavior to controls.
3. Implement and test.
4. Collect evidence with immutable IDs.
5. Record findings and risks.
6. Remediate or create time-bound exception.
7. Complete release checklist.
8. Obtain independent sign-off.
