# Production Evidence Policy

This repository defines the minimum evidence that a release record must retain;
it does not prove the storage provider enforced these settings.

| Evidence | Minimum retention | Required properties |
|---|---:|---|
| Encrypted database and upload backup | 35 days | SHA-256, byte count, source version, restore result |
| Production-proof manifest and failure-drill record | 90 days | run ID, commit, UTC timestamp, actor, command, result |
| Audit and reconciliation exports | Per legal/financial policy | immutable export, access record, legal hold respected |
| Certification manifest/checklist | 90 days | four-state item status, run ID, commit, actor, freshness, redaction, evidence references |

Release evidence must be stored outside the application filesystem in access-
controlled, immutable storage. The local scripts can check manifest shape,
checksums, timestamps, and declared policy only. They cannot prove encryption,
immutability, provider retention, deletion behavior, or a live MySQL/object-store
restore. Those require infrastructure evidence and must remain `BLOCKED` or
`NOT_PROVEN` until attached to the release record.
## Freshness Gate

Proof manifests are release-scoped: `release.run_id`, `release.commit`, and
`release.actor` are required, and `generated_at` must be UTC and no older than
24 hours when attached to a release. Validate a manifest before sign-off:

```text
php scripts/check-evidence.php --manifest=/path/evidence/manifest.json
```

Validate the release certification decision as a separate fail-closed gate:

```text
php scripts/check-certification.php --manifest=/path/evidence/certification.json
```

The certification contract requires provider, backup/restore, backup
infrastructure, reconciliation, offline proof, and static configuration items.
Only `PASS` is positive evidence. `BLOCKED_EXTERNAL` explicitly records an
unavailable provider or production dependency; `NOT_RUN` records work that was
not attempted, and neither state can be treated as a release pass. The
manifest is redacted-by-contract and must contain references or hashes rather
than credentials, tokens, raw provider responses, customer data, or backup
contents.
