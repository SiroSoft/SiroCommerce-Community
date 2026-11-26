# Release Audit Checklist

Release: `REL-YYYYMMDD-NNN`  Commit: ``  Reviewer: ``  Date: ``

## Required Gates

- [ ] Spec/version/change record approved.
- [ ] Module dependency and architecture checks pass.
- [ ] PHP lint, PHPStan max, PHPUnit, coverage threshold pass.
- [ ] MySQL integration and separate-connection race tests pass.
- [ ] Payment, webhook, outbox, queue duplicate/retry tests pass.
- [ ] Catalog/customer/pricing/tax/shipping/fulfillment snapshot tests pass.
- [ ] Security audit, dependency audit, auth/RBAC and redaction review pass.
- [ ] OpenAPI generated and public artifact synchronized.
- [ ] Dataset load/soak/spike evidence meets p95/query/error budgets.
- [ ] Backup checksum and restore rehearsal pass.
- [ ] Upgrade/rollback and F01-F11 failure drills pass.
- [ ] No unexpired critical/high exception without approved mitigation.
- [ ] Doctor/Recovery evidence is complete and safe.
- [ ] Release approver signed `08_SIGNOFF.md`.
- [ ] Commerce compatibility matrix reviewed against WooCommerce, Bagisto, and Magento.
- [ ] Every `PARTIAL`/`MISSING` capability has an action, explicit V2 scope, or time-bound exception.

## Blocking Rule

Any unchecked critical gate is `FAIL`, not `PARTIAL`. `PARTIAL` requires an
exception record and cannot be used for a production release by default.
