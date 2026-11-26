# Enterprise Audit System

This folder is the audit control plane for SiroCommerce BE_V2. It is separate
from implementation documentation so an auditor can assess design, evidence,
exceptions, and release approval without reading the entire codebase first.

## Audit Principles

- Every requirement has a stable control ID.
- A passing test is evidence only when its scope, environment, commit, and
  result are recorded.
- "Not tested" is not "passed".
- Exceptions require owner, risk acceptance, expiry, and compensating control.
- Production proof must be reproducible from committed scripts and artifacts.
- Evidence contains no secrets, payment data, passwords, or unnecessary PII.
- Audit records are append-only after sign-off; corrections create a new record.

## Required Files

| File | Purpose |
|---|---|
| `00_AUDIT_CHARTER.md` | scope, roles, independence, audit cycle |
| `01_CONTROL_MATRIX.md` | control objectives, tests, evidence, owner, status |
| `02_EVIDENCE_REGISTER.md` | immutable evidence index and retention |
| `03_RISK_REGISTER.md` | risk scoring and treatment |
| `04_RELEASE_AUDIT_CHECKLIST.md` | release-by-release gate |
| `05_FAILURE_DRILL_RECORD.md` | F01-F11 drill evidence template |
| `06_EXCEPTION_REGISTER.md` | approved deviations and expiry |
| `07_CHANGE_RECORD.md` | change impact and approval |
| `08_SIGNOFF.md` | engineering/security/operations approval |
| `09_AUDIT_RUNBOOK.md` | commands and collection workflow |
| `10_COMPLIANCE_MAPPING.md` | control-family mapping without certification claim |

## Status Vocabulary

`PASS`, `FAIL`, `PARTIAL`, `NOT_TESTED`, `NOT_APPLICABLE`, `EXCEPTION`.

`EXCEPTION` is never a pass and blocks release unless the release approver
explicitly accepts the recorded residual risk.

## Audit Cycle

```text
Plan -> Map controls -> Implement -> Test -> Collect evidence
  -> Review findings -> Remediate/accept exception -> Sign off -> Retain
```
