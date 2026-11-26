# Evidence Classification

Backend evidence reports use these states:

- `PASS`: the local check ran and proved the stated contract.
- `BLOCKED_EXTERNAL`: a local check is healthy, but an external owner must provide a runtime, credential, endpoint, backup, or authenticated provider fixture. This state is never release evidence and never counts as `PASS`.
- `BLOCKED` or `FAIL`: the check found a local evidence or implementation problem.

Release-proof commands use exit code `0` for `PASS`, `1` for a local failure,
and `2` for `BLOCKED_EXTERNAL`; callers must preserve that distinction.

Every `BLOCKED_EXTERNAL` record includes `owner`, `prerequisite`, and `command`.
Commands are safe to show in reports and never include secret values. Provider and
production smoke output must not be promoted to `PASS` without authenticated
runtime evidence.

Useful local commands from `backend`:

```text
php scripts/check-production-proof.php
php scripts/provider-preflight.php
php scripts/backup-restore-preflight.php --manifest=/path/backup-manifest.json --out=/path/evidence
php scripts/provider-live-smoke.php
php scripts/check-api-handoff.php
```
