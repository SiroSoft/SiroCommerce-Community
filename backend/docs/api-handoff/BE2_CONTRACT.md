# BE 2 Contract Handoff

## Scope boundary

`CommerceScope` canonicalizes the required site dimensions `store_id`,
`channel_id`, `locale`, and `currency`. `tenant_id` is an optional additive
boundary for existing v1 callers and is included in the canonical key when
present. Header/query disagreement is rejected. `ScopeAuthorizer` rejects a
principal whose tenant, store, or channel claims do not include the requested
scope.

## Durable operations

- Inventory allocation is multi-source, locks source rows in deterministic
  order, records source evidence, and uses an idempotency fingerprint.
- Release, commit, and transfer are named state changes; replay is a no-op or
  returns the original evidence, while a changed payload conflicts.
- Import/export/index/cleanup consumers use `JobStatus`; terminal external work
  is `blocked_external`, never synthetic success.
- `EventEnvelope` requires versioned names (`name.v1`) and carries correlation,
  causation, actor, payload, and redaction metadata. Order audit and customer
  notification factories publish typed envelopes.

## Provider boundary

Payment, shipping, ERP, and provider live evidence is classified
`BLOCKED_EXTERNAL` until real independently captured evidence exists. Sandbox,
mock, or configured credentials do not become `PASS`.

## Contract artifacts

- OpenAPI: `docs/openapi.json` (`info.x-be2-contract`)
- TypeScript SDK: `generated/sirocommerce.ts`
- Runtime metadata: `GET /api/version`
- Focused tests: scope authorization, delivery rules/cost, payment transitions,
  event schema, replay/conflict, and external evidence classification.
