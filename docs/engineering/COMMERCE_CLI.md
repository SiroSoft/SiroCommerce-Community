# SiroCommerce CLI

The backend CLI is the operational control plane for SiroCommerce. Run commands from `backend/`.

## Quality Gates

```powershell
php siro commerce:check --quick
php siro commerce:check --continue --json
```

`commerce:check` runs PHPStan, mapping, i18n, audit, API coverage, and PHPUnit. The full form uses the configured test database through `TEST_DB_*` environment variables. It returns a non-zero exit code on the first failure unless `--continue` is supplied.

## Native Siro Operations

```powershell
php siro doctor --prod
php siro env:check
php siro db:health
php siro db:check
php siro migrate:status
php siro queue:status
php siro queue:work --daemon
php siro queue:retry all
php siro audit:verify
php siro trace:list --limit=20
php siro rate:status
```

## API and Recovery

```powershell
php siro route:list
php siro route:rules
php siro api:contract --strict
php siro api:test POST /api/v2/checkout/quote
php siro api:why POST /api/v2/checkout/quote
php siro log:replay <trace-id> --safe --dry-run
php siro test:regression --fail
```

## Commerce Control Plane

All mutating operations are explicit. Inventory and order recovery are dry-run by default.

```powershell
php siro commerce:ops outbox status
php siro commerce:ops outbox dispatch --limit=50
php siro commerce:ops outbox dispatch --publish --limit=50
php siro commerce:ops outbox replay --id=<outbox-id>
php siro commerce:ops jobs status
php siro commerce:ops jobs retry --id=<job-id>
php siro commerce:ops payments reconcile
php siro commerce:ops inventory repair --actor=operator
php siro commerce:ops inventory repair --apply --actor=operator
php siro commerce:ops webhooks replay
php siro commerce:ops orders recover --id=<order-id> --actor=operator
php siro commerce:ops orders recover --id=<order-id> --apply --actor=operator
php siro commerce:ops metrics
php siro commerce:ops readiness
php siro commerce:ops dr
```

Payment and webhook commands report records requiring a real provider adapter; they do not claim external provider state has been reconciled or replayed. Outbox publishing emits through `Siro\\Core\\Event`, and every mutation is appended to the Siro tamper-evident audit chain.

## Database and Deployment

```powershell
php siro db:stats
php siro db:backup --compress
php siro db:optimize
php siro config:cache
php siro optimize
php siro doctor --prod
```

`commerce:check --json` is the CI/deploy contract. Business-specific commands should be added under `app/Console/Commands` and implement `CommandInterface`; Siro Core auto-discovers them without modifying the framework registry.
