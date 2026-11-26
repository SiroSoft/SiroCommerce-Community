# Compliance Control Mapping

This is a baseline mapping, not a certification claim. The applicable legal,
contractual, and auditor-specific control set must be added per deployment.

| Control family | SiroCommerce controls | Primary evidence |
|---|---|---|
| Governance | GOV-001..003, change records, sign-off | `07_CHANGE_RECORD`, `08_SIGNOFF` |
| Access control | SEC-001, access reviews, break-glass | access review/export, auth tests |
| Secrets/crypto | SEC-002/003, secret rotation | scan, rotation record |
| Change management | GOV-003, REL-001, rollback | build provenance, release checklist |
| Availability | OPS-001..005, SLO/DR | dashboard, restore/drill reports |
| Financial integrity | DATA-001..006, PAY-001..005 | reconciliation and race tests |
| Privacy | privacy data map, retention, export/delete | data inventory and request evidence |
| Auditability | OBS-001/002, immutable audit log | trace, audit export, hash |
| Vendor risk | EXT-001..003, provider inventory | vendor review and compat tests |
| Incident response | incident record and postmortem | incident timeline/actions |

Framework-specific mappings such as PCI DSS, SOC 2, ISO 27001, GDPR, or local
law must identify the exact requirement, applicability decision, control owner,
test frequency, evidence, and auditor sign-off.
