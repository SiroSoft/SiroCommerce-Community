# Evidence Register

Evidence IDs are immutable: `EV-YYYYMMDD-NNN`. Never commit secrets, raw card
data, passwords, access tokens, or unnecessary customer PII.

| ID | Control IDs | Artifact/command | Commit | Environment | Collector | SHA256 | Retention | Status |
|---|---|---|---|---|---|---|---|---|
| EV-YYYYMMDD-001 |  |  |  |  |  |  |  | NOT_TESTED |

## Evidence Requirements

- Test evidence includes command, exact binary/version, configuration profile,
  test count, assertions, and exit code.
- Performance evidence includes dataset size, p50/p95/p99, query count, memory,
  cache hit, error rate, and queue lag.
- Restore evidence includes backup checksum, source version, restore duration,
  row/checksum validation, and post-restore smoke result.
- Security evidence excludes sensitive values and includes scope, tool version,
  findings, triage, and reviewer.
- Screenshots are secondary evidence; machine-readable logs are primary.

## Artifact Naming

```text
evidence/<release>/<control>/<YYYYMMDD>-<short-description>.<ext>
```
