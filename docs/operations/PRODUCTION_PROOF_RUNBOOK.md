# Production Proof Runbook

Run from `backend/` in an isolated evidence workspace:

```powershell
php scripts/production-proof.php --out=storage/evidence/<run-id> --backup-created-at=2026-09-20T12:00:00Z
```

The command is non-destructive. It reads the database, uploads, config,
migration files, and version metadata, then writes a manifest and a staged
SQLite database below `--out`. It never overwrites an application database,
executes migrations, calls providers, or starts workers. `PASS` means the local
SQLite rehearsal and supplied RPO measurement passed. `BLOCKED` means a required
dependency or artifact was unavailable. A missing backup age is `NOT_PROVEN`,
not a passing RPO result.

The manifest includes SHA-256 and byte count for every discovered artifact,
database table row counts, inventory invariants, measured rehearsal duration,
and explicit `NOT_RUN` records for F01-F11. Provider, queue, MySQL concurrency,
and upgrade rollback drills require a separately isolated environment; their
results must be recorded in `docs/audit/05_FAILURE_DRILL_RECORD.md` and must not
be inferred from this offline command.

## Backup/restore preflight

For certification evidence, use the stricter manifest-driven rehearsal:

```powershell
php scripts/backup-restore-preflight.php --manifest=storage/evidence/<run-id>/backup-manifest.json --out=storage/evidence/<run-id>/restore-preflight
```

The input manifest must contain `created_at` in UTC (`Z`), a `database` record
with `path`, `bytes`, and SHA-256, and an `uploads` record with `path` and an
exact `files` list of relative paths, byte counts, and SHA-256 values. The
command rejects missing, extra, changed, or path-traversing upload files. It
copies verified inputs below `--out/staged`, then runs all migration `up`
methods, reverse `down` methods, and `up` methods again against a new SQLite
database. It never writes to the input database or uploads directory.

The generated manifest reports measured RPO/RTO and local upgrade/rollback
results. `PASS` is only a local isolated rehearsal; it is not live MySQL,
object-storage, encryption, immutability, retention, or production restore
evidence. Missing infrastructure evidence remains `BLOCKED`/`NOT_PROVEN`.

The static gate also rejects duplicate migration timestamps, migrations without
both `up` and `down` methods, missing retention policy, and unbounded load-proof
inputs. See `docs/operations/PRODUCTION_EVIDENCE_POLICY.md` for retention and storage
boundaries. The policy values are declarations, not infrastructure evidence.

Migration filenames are immutable deployment identities. The Wave 1 sequence
keeps CMS mutation idempotency at `20260921000057`, then assigns the new finance
and B2B migrations `20260921000058` and `20260921000059`; every migration must
use a unique later timestamp rather than reusing an applied identity.

Recovery order is database, application/schema/version metadata, uploads and
config, queue/outbox, providers, then read paths. After a real restore, run
application smoke tests and reconcile orders, payments, inventory, shipments,
and outbox rows before traffic is restored. This repository does not claim a
live restore when MySQL, object storage, secrets, or provider dependencies are
unavailable.

## Provider contract

Run `php scripts/provider-preflight.php` first. If isolated provider-owned
authenticated health and webhook fixtures are available, run
`PROVIDER_LIVE_SMOKE=1 php scripts/provider-live-smoke.php` and retain its JSON
beside the release evidence. `BLOCKED`, `NOT_PROVEN`, and `NOT_APPLICABLE` are
not release passes for an applicable contract.
## Evidence Sign-Off

Before attaching any manifest to a release, set `RELEASE_COMMIT` and
`RELEASE_ACTOR` (or equivalent CI variables), then run
`php scripts/check-evidence.php --manifest=/path/evidence/manifest.json`.
The freshness gate rejects missing identity and evidence older than 24 hours.

## Certification Manifest

The release decision is recorded separately from individual tool output in an
auditable certification manifest. Start from
`docs/operations/PRODUCTION_CERTIFICATION_MANIFEST.example.json`, replace the
run identity and timestamps, attach references to retained manifests, and
validate it without contacting production:

```powershell
php scripts/check-certification.php --manifest=/path/evidence/certification.json
```

Every checklist item uses exactly `PASS`, `FAIL`, `BLOCKED_EXTERNAL`, or
`NOT_RUN`. A `PASS` requires evidence. `BLOCKED_EXTERNAL` and `NOT_RUN` require
an owner-facing reason. Required controls include offline production proof,
provider preflight and live smoke, backup/restore, infrastructure backup
controls, post-restore reconciliation, and static config checks. Live provider,
production restore, immutable storage, encryption, retention, and
reconciliation are not simulated; when unavailable they remain blocked and
must never be promoted to `PASS`.

The manifest includes run ID, commit, actor, UTC freshness timestamps, and an
explicit redaction declaration. Store references and hashes, not credentials,
authorization headers, raw provider payloads, customer data, or backup
contents.
