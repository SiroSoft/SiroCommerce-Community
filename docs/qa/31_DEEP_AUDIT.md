> LEGACY (point-in-time snapshot, 2026-09-19; not current capability evidence). See [docs/README.md](../README.md).

# Deep Audit 0-7

Audit date: 2026-09-19. Scope: `docs/00` through `docs/30`, backend,
frontend, migrations, routes, tests, and release scripts.

## Verified And Fixed In This Audit

- Guest order lookup now requires the per-order access token; an order ID alone
  no longer discloses lines or transitions.
- `FeGuardMiddleware` is attached to storefront, auth, admin, growth, smart,
  and developer API routes. Webhooks remain provider-authenticated, not FE-secret
  authenticated.
- Refund validation now accounts for prior refunds and invokes the selected
  payment gateway before recording the refund. Over-refund regression is tested.
- COD webhook requests are no longer falsely reported as `already_processed`.
  Unsupported providers are rejected; configured extensions must verify raw-body
  signatures and provide an event id before acceptance.
- Frontend i18n gate was missing despite being declared in `package.json`.
  `apps/storefront/scripts/check-i18n.mjs` now enforces recursive key parity.
- Phase 6 extension registry resolves compatibility and provider collisions.
- Phase 7 benchmark now measures repeated samples and p95, validates HTTP status,
  and requires a proof dataset label in live mode.
- Static security audit and backup checksum verification gates are present.

## Current Gate Results

- Backend PHPUnit: `41 tests, 201 assertions`.
- Backend PHPStan max: pass.
- Backend mapping: pass.
- Backend i18n parity: pass for 5 locales.
- Backend security audit: pass.
- Backend budget-table validation: pass.
- Frontend Vitest: `9 tests` pass.
- Frontend TypeScript: pass.
- Frontend i18n parity: pass after adding the missing gate.

## Release-Blocking Findings

These are not marked complete because the current repository does not contain
the implementation or environment needed to prove them:

1. The frontend has no storefront/admin application, API client, checkout UI,
   generated OpenAPI types, or real Playwright checkout. The existing E2E files
   are route-map skeletons.
2. Webhook event persistence, duplicate-event idempotency, and async queue
   processing are not implemented. Signature verification is only available to
   registered extension gateways.
3. Checkout still has zero shipping/tax and order-line product names are not
   snapshotted. Gateway authorization runs inside the DB transaction and there
   is no outbox/compensation workflow.
4. Required MySQL concurrency/race proof is absent. The current TestCase uses
   SQLite and the inventory race test is sequential, not concurrent.
5. Failure drills F01-F11 from `docs/qa/28_FAILURE_INJECTION_PLAN.md` are not all
   automated. In particular timeout, queue/mail/cache outage, deadlock rollback,
   extension failure, and upgrade/rollback drills remain unproven.
6. SEO health is partial: broken/orphan/missing-ALT detection, facet canonical
   rules, redirect execution, Breadcrumb/Organization JSON-LD, and fixes are not
   complete.
7. Extension lifecycle hooks (install/uninstall/upgrade, migrations, events,
   admin slots, CLI) and idempotent shipment creation are not implemented.
8. The 10k/50k/100k production-proof datasets, query counts, N+1 checks, cache
   hit metrics, soak/spike load, Lighthouse, backup restore, and dogfood UAT
   have not run in this workspace.

## Audit Conclusion

The backend foundation and safety fixes pass the available local gates, but the
repository is **not release-ready under docs/12 and docs/30**. The blockers
above must be implemented or evidenced before claiming the spec is complete.
