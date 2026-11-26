# FE I18N, Finance, Locale, and Timezone Controls

Status: `RELEASE STANDARD`

This document defines frontend non-functional controls for locale, currency,
timezone, and financial display. The API contract remains authoritative for
wire values. The frontend must not infer business meaning from formatted text.

## Sources and scope

- API contract: `backend/docs/openapi.json` and `backend/public/openapi.json`.
- Runtime handoff: `backend/docs/api-handoff/FE_HANDOFF.md`.
- Scope rules: `docs/contracts/COMMERCE_SCOPE.md`.
- Domain money rules: `docs/foundation/02_DOMAIN_MODEL.md`, `docs/qa/27_ACCEPTANCE_MATRIX.md`.
- Current locale fixture set: `apps/storefront/src/locales/{en,vi,ja,de,zh}/common.json`.
- Current formatter baseline: `apps/storefront/src/lib/money.ts` and `money.test.ts`.

## Release invariants

1. API amounts are integer minor units and always travel with an explicit ISO
   4217 currency. No FE calculation may use binary floating point.
2. Order, invoice, payment, refund, credit memo, cart, and quote displays use
   the currency supplied by the resource or centrally selected commerce scope;
   they never use the browser's default currency.
3. A display formatter is the only boundary that turns money into text. The
   formatted string is never parsed back into a number.
4. Translations are keyed, versioned, and complete. User-visible labels,
   errors, toasts, dates, numbers, units, and accessibility names are not
   hardcoded in components.
5. Instants are stored and exchanged as ISO 8601 values with an offset or `Z`.
   Rendered time uses the selected store/account timezone, with the timezone
   abbreviation or UTC offset available for ambiguous business events.
6. Date-only business values (for example an accounting date) are rendered as
   dates, not converted through a local timestamp.
7. Locale, currency, timezone, store, and channel are selected in one scope
   context. Components may consume the context but may not override it.

## Supported baseline

The current automated parity test requires exactly `en`, `vi`, `ja`, `de`, and
`zh`. New locales require translated fixtures, plural/select rules, date/number
goldens, accessibility review, and a documented rollout flag. `en` is the
fallback only for missing development content; missing production translations
are a release blocker.

Currency support must be an explicit allowlist owned by the commerce contract.
For each currency record minor-unit scale, rounding mode, symbol/display name,
negative pattern, and whether conversion is allowed. Do not assume all
currencies have two decimals. VND is currently formatted with zero decimals by
the FE baseline; this must remain consistent with the API minor-unit contract.

## Required fixtures

Use deterministic fixtures in unit, component, and E2E tests:

| Fixture | Required assertion |
|---|---|
| `VND`, `100000` | no decimal places; same currency in list, cart, checkout, order, invoice |
| `USD`, `12345` | `123.45` major units; no float drift |
| `JPY`, `1234` | currency-specific zero-decimal behavior if enabled |
| negative refund `-1250` | minus sign and currency remain unambiguous |
| zero amount | renders a real zero, never empty or `N/A` |
| `2026-09-22T23:30:00Z` | deterministic output in UTC, `Asia/Ho_Chi_Minh`, and `America/New_York` |
| DST boundary instant | no duplicated or skipped local event without an explicit offset |
| missing translation key | test failure; no raw key in production UI |
| Arabic-shaped long value / German long label | no clipping or overlapping at supported widths |

## Acceptance tests and gates

| ID | Test | Gate |
|---|---|---|
| I18N-01 | `npm run i18n:check`; every locale equals the reference key set and has no empty value | 0 errors |
| I18N-02 | Render the fixture matrix in all five locales; compare approved snapshots | 100% pass |
| I18N-03 | Switch locale and scope without a full page reload; preserve current route and form state | 100% pass |
| I18N-04 | Long translated labels, plural zero/one/many, validation errors, and RTL-ready layout smoke | 0 overflow or raw keys |
| FIN-01 | `0.1 + 0.2` equivalent fixture is represented and asserted as integer minor units | no float or parse-format round trip |
| FIN-02 | Currency mismatch between cart/order resource and selected scope is blocked or clearly surfaced | no silent conversion |
| FIN-03 | Order totals remain the API snapshot after catalog price changes | matches `AC-D01` |
| TIME-01 | Same instant renders predictably in UTC and two named IANA zones | exact golden output |
| TIME-02 | Date-only values do not move to the prior/next day across zones | 0 date shifts |
| TIME-03 | Invoice/payment/refund event displays include zone context where business meaning depends on time | 100% sampled screens |

Release gates are: five-locale parity, zero untranslated production strings,
zero currency mismatch, zero float money assertions, and all fixture tests
passing in CI. A locale or finance regression blocks rollout.

## API operation mapping

Evidence must name the exact generated operation id, not only a URL. Initial
examples include `get_api_products`, `get_api_products_slug`,
`post_api_carts`, `post_api_checkout_place`, `get_api_admin_invoices_id`,
`get_api_admin_invoices_id_evidence`, and `post_api_account_privacy_anonymize`.
The operation's OpenAPI response and `x-*` metadata must be attached to the
test evidence when a fixture depends on it.

## Open decisions

- The documented locale baseline is inconsistent: coding standards mention four
  locales, while FE tests and files require five including `zh`.
- `docs/foundation/03_API_SPEC.md` describes field errors at `errors`, while the handoff
  and generated contract describe `meta.errors`. FE release evidence follows
  the generated contract until the API documentation is reconciled.
- The API artifacts do not define a canonical timezone preference or currency
  conversion endpoint. FE must not invent either; deployment must provide the
  approved scope/preference contract before those journeys are enabled.
