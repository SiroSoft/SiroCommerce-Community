# siro/shipping-fee-rules

Free reference module for store/channel scoped shipping fee and COD rules.
Follows `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md` and
`docs/contracts/EXTENSION_PACKAGING_SPEC.md`; it does not modify core.

## What it does

- Keeps rules in `ext_shipping_fee_rules`, always scoped by `store_id` and
  `channel_id`.
- `method` is one of `cod`, `standard`, `express`.
- `min_subtotal_minor` gates when a rule starts applying.
- `fee_minor` is the shipping fee in minor units.
- `free_over_minor` makes shipping free at/above the threshold; `0` means the
  rule never grants free shipping.
- `priority` (0-1000) picks the winner when several active rules match;
  `feeFor()` orders by priority descending and breaks ties by rule id.

## Routes

Mounted under `/api/ext/siro/shipping-fee-rules/`; all require the manifest
permission `shipping-fee:manage` (owner/admin hold it while enabled).

| Method | Path | Purpose |
|---|---|---|
| `GET` | `rules` | List scoped rules, highest priority first |
| `POST` | `rules` | Create a scoped rule (requires `Idempotency-Key`) |
| `GET` | `admin/overview` | Admin panel stats + table payload |

`POST rules` accepts:

```json
{
  "name": "COD standard",
  "method": "cod",
  "min_subtotal_minor": 0,
  "fee_minor": 30000,
  "free_over_minor": 500000,
  "active": 1,
  "priority": 100
}
```

Replaying the same `Idempotency-Key` returns the existing row. Creating the same
`name` + `method` in the same scope also returns the canonical row.

## Config defaults

| Key | Default | Meaning |
|---|---|---|
| `default_method` | `standard` | Suggested method for admin forms |
| `default_fee_minor` | `0` | Suggested default fee |
| `default_free_over_minor` | `0` | Suggested free-shipping threshold |

No secret, license key or remote endpoint is required (`license.required=false`).

## Service

`ShippingFeeRuleService` exposes `list`, `find`, `create`, `update`, `delete`
and `feeFor(CommerceScope $scope, int $subtotalMinor, string $method): int`.
`feeFor()` never reads across stores/channels and returns `0` when no active
rule matches.

## Uninstall behavior

- **Disable**: unmounts the extension routes, keeps all rule data.
- **Uninstall keep data**: registry row is disabled, `ext_shipping_fee_rules`
  stays intact and a reinstall reuses it.
- **Uninstall remove data**: migration `down()` drops
  `ext_shipping_fee_rules`; core tables are untouched.
