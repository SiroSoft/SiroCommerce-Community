# Control Matrix

Status is `NOT_TESTED` until evidence is linked in the evidence register.

| ID | Control objective | Required evidence | Owner | Status |
|---|---|---|---|---|
| GOV-001 | Versioned spec and change approval | spec commit + change record | Engineering | NOT_TESTED |
| GOV-002 | Module dependency boundaries enforced | dependency scan + review | Engineering | NOT_TESTED |
| GOV-003 | Production changes are reproducible | commit, lockfile, build artifact | Release | NOT_TESTED |
| DATA-001 | All money uses integer minor units | static scan + Money tests | Data | NOT_TESTED |
| DATA-002 | Order pricing is immutable snapshot | migration + mutation test | Data | NOT_TESTED |
| DATA-003 | Inventory cannot oversell under race | MySQL two-connection test | Data | NOT_TESTED |
| DATA-004 | Payment/refund cumulative limits hold | integration evidence | Data | NOT_TESTED |
| DATA-005 | Customer/order access is scoped | ownership/RBAC tests | Security | NOT_TESTED |
| DATA-006 | State changes use named transitions | static scan + transition tests | Engineering | NOT_TESTED |
| PAY-001 | Provider calls have idempotency | provider contract tests | Engineering | NOT_TESTED |
| PAY-002 | Unknown payment result is reconciled | timeout drill + reconciliation log | Operations | NOT_TESTED |
| PAY-003 | Webhooks verify raw-body signature | valid/invalid HMAC evidence | Security | NOT_TESTED |
| PAY-004 | Duplicate webhook has no side effect | duplicate event test + DB key | Engineering | NOT_TESTED |
| PAY-005 | Capture/refund cannot exceed limits | cumulative operation test | Data | NOT_TESTED |
| OPS-001 | Transactional outbox is durable | rollback/commit test | Operations | NOT_TESTED |
| OPS-002 | Queue retries are bounded | worker/backoff/dead-letter evidence | Operations | NOT_TESTED |
| OPS-003 | Jobs and consumers are idempotent | replay test | Engineering | NOT_TESTED |
| OPS-004 | Backup can actually restore | restore rehearsal report | Operations | NOT_TESTED |
| OPS-005 | Rollback preserves business data | upgrade/down migration drill | Release | NOT_TESTED |
| FUL-001 | Shipment creation is idempotent | duplicate shipment test | Fulfillment | NOT_TESTED |
| FUL-002 | Partial shipment and return states are valid | state matrix tests | Fulfillment | NOT_TESTED |
| EXT-001 | Extension manifest compatibility is enforced | install/compat test | Engineering | NOT_TESTED |
| EXT-002 | Extension failure is isolated | timeout/failure drill | Engineering | NOT_TESTED |
| EXT-003 | Hooks cannot bypass invariants | hook safety tests | Security | NOT_TESTED |
| SEC-001 | RBAC is enforced in services | capability denial tests | Security | NOT_TESTED |
| SEC-002 | Secrets and PII are redacted | log scan + manual review | Security | NOT_TESTED |
| SEC-003 | Input/query/output controls hold | validation/static/dependency scan | Security | NOT_TESTED |
| PERF-001 | API budgets meet p95/query limits | dataset benchmark artifact | Engineering | NOT_TESTED |
| PERF-002 | No N+1 on critical surfaces | query trace/EXPLAIN artifact | Engineering | NOT_TESTED |
| OBS-001 | Correlation/causation is end-to-end | Flight Recorder trace | Operations | NOT_TESTED |
| OBS-002 | Doctor findings contain evidence/fix | scoped Doctor output | Operations | NOT_TESTED |
| REL-001 | All release gates pass | signed release checklist | Release | NOT_TESTED |
| GOV-004 | Decision rights and segregation of duties exist | charter + sign-off | Governance | NOT_TESTED |
| GOV-005 | Material changes have impact assessment | change records | Engineering | NOT_TESTED |
| GOV-006 | Exceptions are time-bound and approved | exception register | Release | NOT_TESTED |
| SEC-004 | Privileged access is least privilege and reviewed | access review | Security | NOT_TESTED |
| SEC-005 | Secrets are rotatable without code changes | rotation rehearsal | Security | NOT_TESTED |
| SEC-006 | Trust boundaries validate external input | threat model + tests | Security | NOT_TESTED |
| SEC-007 | Payment card data is outside storage boundary | data-flow review | Payment | NOT_TESTED |
| SEC-008 | Vulnerabilities have severity SLAs | dependency report | Security | NOT_TESTED |
| PRIV-001 | Data inventory has owner/class/retention | data register | Privacy | NOT_TESTED |
| PRIV-002 | Export/delete requests are scoped and audited | request test | Privacy | NOT_TESTED |
| PRIV-003 | Vendor data sharing is documented | vendor register | Privacy | NOT_TESTED |
| REL-002 | Release artifact is reproducible and traceable | SBOM/provenance | Release | NOT_TESTED |
| REL-003 | Emergency changes receive retrospective review | incident/change record | Release | NOT_TESTED |
| OPS-006 | SLOs have measurement, alert, and owner | dashboard/runbook | Operations | NOT_TESTED |
| OPS-007 | Incident severity and escalation are defined | incident record | Operations | NOT_TESTED |
| OPS-008 | Restore meets approved RPO/RTO | restore rehearsal | Operations | NOT_TESTED |
| OPS-009 | Audit logs are append-only and retained | log export/retention | Operations | NOT_TESTED |
| FIN-001 | Payment/order/inventory reconcile | reconciliation report | Finance | NOT_TESTED |
| FIN-002 | Manual adjustments require reason and before/after | audit event | Finance | NOT_TESTED |
| VEND-001 | Critical vendors have owner/SLA/exit plan | vendor inventory | Operations | NOT_TESTED |
| VEND-002 | Critical extensions can be disabled safely | lifecycle drill | Engineering | NOT_TESTED |
| BCP-001 | Degraded-mode runbooks exist for dependencies | runbook/drill | Operations | NOT_TESTED |

## Control Assessment Format

For each row, record: assessor, date, commit, environment, procedure, result,
evidence IDs, finding IDs, and remediation due date. A green CI job without an
artifact or reproducible command is insufficient evidence for L2/L3.
