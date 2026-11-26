# Frontend Contract Gaps

Status: `ACTIVE IMPLEMENTATION BACKLOG`

This document records backend contract gaps discovered while implementing the
frontend. FE must not invent fields, state transitions or authorization rules
to hide an item listed here.

## Rules

- `BLOCKED_BY_CONTRACT` means FE implementation stops at the boundary and shows
  an explicit unavailable state.
- `WORKAROUND_ALLOWED` is allowed only for presentation and must not mutate or
  infer backend state.
- Every resolved item requires an OpenAPI update, generated SDK regeneration,
  a backend contract test and an FE fixture.
- Live payment, tax and shipping provider certification remains excluded from
  FE completion, but provider-neutral pending, failed, unknown and retry states
  remain required.

## Active Gaps

| ID | Area | Gap | Status | Backend action |
|---|---|---|---|---|
| CG-001 | Returns | Missing complete list/read/state enum contract for some return and fulfillment views | BLOCKED_BY_CONTRACT | Publish typed list/read responses and state enums |
| CG-002 | Refunds | Refund timeout/reconciliation and partial-refund state contract needs authoritative read fields | BLOCKED_BY_CONTRACT | Publish refund status and reconciliation response schema |
| CG-003 | B2B | Company/member/catalog list/read coverage and complete quote state/action enum are incomplete | BLOCKED_BY_CONTRACT | Add typed resources and operation-specific actions; avoid generic `{action}` |
| CG-004 | CMS | Diff/merge, autosave, media and scheduling semantics are not fully contracted | BLOCKED_BY_CONTRACT | Publish revision conflict, autosave and scheduling contracts |
| CG-005 | Reporting | Export/report capability metadata and scope parameters must remain aligned between route middleware and OpenAPI | VERIFY_BEFORE_RELEASE | Regenerate OpenAPI and assert capability/scope parity |
| CG-006 | Session | Browser refresh-token storage, CSRF and refresh rotation transport are not finalized | BLOCKED_BY_CONTRACT | Decide HttpOnly-cookie or documented secure alternative |
| CG-007 | Error envelope | FE implementation must use `meta.error_code` and `meta.errors`; legacy top-level `errors` is unsupported | FE_FIXTURE_REQUIRED | Add shared FE normalizer and contract fixtures |
| CG-008 | Transport | Generated SDK defaults and FE coordinator must support versioned Accept, scope, cart token, correlation id and response metadata | FE_IMPLEMENTATION | Add coordinator without hand-editing generated output |
| CG-009 | Provider | Live provider certification and provider-specific payload behavior | SKIP_BY_POLICY | Keep provider-neutral state machine and no fabricated provider behavior |

## Golden Journey Scope

The first implementation milestone is not blocked by CG-001 through CG-004. It
uses only the currently typed and verified flow:

```text
Guest browse
  -> product detail
  -> create cart
  -> add line
  -> quote
  -> place checkout with idempotency key
  -> order result
```

The merchant slice runs in parallel:

```text
Merchant login
  -> Action Center
  -> order detail
  -> named order transition
  -> fulfillment action
```

Both slices must exercise success, `401`, `403`, `409`, `422`, `429`, `500` and
unknown-outcome/reconcile behavior where the operation is a mutation.

## Resolution Evidence

For each resolved gap, link all of the following in the PR or release record:

1. Backend route and OpenAPI operation id.
2. Request/response schema diff.
3. Stable error codes and state enum.
4. Backend contract/integration test.
5. FE fixture and screen acceptance test.
6. Scope, role and idempotency behavior.

## Ownership

- Backend contract owner: update routes, services, OpenAPI and contract tests.
- FE platform owner: transport, auth/session, scope context and error model.
- FE domain owner: screen state machine, fixtures and acceptance tests.
- QA owner: cross-role, cross-scope, responsive and failure evidence.
