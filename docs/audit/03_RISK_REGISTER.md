# Risk Register

Risk IDs are immutable: `RISK-NNN`. Score likelihood and impact from 1 to 5.
Score = likelihood x impact.

| ID | Risk | Cause | L | I | Score | Treatment | Owner | Due | Residual | Status |
|---|---|---|---:|---:|---:|---|---|---|---:|---|
| RISK-001 | Duplicate charge | provider timeout ambiguity | 3 | 5 | 15 | mitigate with idempotency keys, provider reconciliation, immutable payment attempts and manual-review state; no blind retry | Payment owner | Before provider certification and any payment release | 10 after reconciliation and manual-review controls | OPEN |
| RISK-002 | Release status is split across overview, phase, deep-audit and control-matrix documents | Multiple documents claim different readiness levels | 4 | 5 | 20 | avoid by making audit sign-off the only release authority; scope overview claims to local gates | Release governance | Before release | 20 until sign-off | OPEN |
| RISK-003 | Admin UI route/role/scope rules can drift across IA, screen, workflow and QA documents | Repeated matrices with overlapping ownership | 4 | 4 | 16 | mitigate with explicit document ownership and generated OpenAPI as contract authority | Admin FE | Before FE release | 12 | OPEN |
| RISK-004 | Legacy API/error/idempotency text conflicts with generated contract | V1 reference documents predate current operation metadata | 4 | 4 | 16 | avoid using generated OpenAPI and handoff as normative; mark legacy specs as reference | API governance | Before FE implementation expands | 8 | OPEN |
| RISK-005 | Production proof and audit evidence are not current sign-off evidence | Local tests and phase claims exceed reproducible evidence | 4 | 5 | 20 | mitigate through evidence register, named owners and explicit NOT_RUN/BLOCKED status | QA / Audit | Before production | 20 until sign-off | OPEN |
| RISK-006 | Provider and advanced workflow contracts are incomplete | Payment/shipping, B2B, returns, exports and CMS state/read schemas have gaps | 3 | 5 | 15 | accept only for scoped FE slices; track in `docs/frontend/FE_CONTRACT_GAPS.md` and block dependent screens | BE / FE platform | Per vertical slice | 10 | OPEN |

## Severity

- 1-4: low
- 5-9: medium
- 10-16: high, release approval required
- 17-25: critical, release blocked

## Treatment Vocabulary

`avoid`, `mitigate`, `transfer`, `accept`, `monitor`.

Acceptance requires named approver, expiry date, compensating control, residual
score, and explicit release scope. Permanent acceptance is not allowed.
