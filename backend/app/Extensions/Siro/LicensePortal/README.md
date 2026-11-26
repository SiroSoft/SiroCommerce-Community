# siro/license-portal

Private Pro license delivery foundation. Validates a signed license envelope with
`ExtensionLicenseService`, stores the delivery per `store_id` + `channel_id`, and
serves a single-use download link. Never stores or logs a private key and never
returns a license document from admin endpoints.

## Data

`ext_license_portal_deliveries` is scoped by `store_id` + `channel_id`:

- `customer_id`, `order_id`, `order_line_id`, `sku`, `source_sku`
- `license_id`, `license_document` (signed envelope), `license_reference`
- `license_expires_at`
- `token_hash` (SHA-256 of the bearer token, raw token is never stored)
- `token_expires_at`, `token_used_at`, `revoked_at`
- `state` (`pending`, `issued`, `revoked`), `idempotency_key`, timestamps

Admin list responses expose metadata only: no `license_document`, no
`token_hash`, no idempotency key, no key material and no filesystem paths.

## Routes

Mounted under `/api/ext/siro/license-portal/`:

| Method | Path | Middleware | Purpose |
|---|---|---|---|
| GET | `download/{token}` | throttle | Token-only single-use signed license JSON attachment |
| GET | `admin/deliveries` | cap + throttle | Scoped delivery list |
| GET | `admin/overview` | cap + throttle | Admin panel stats + recent table |
| POST | `admin/deliveries` | cap + throttle + idempotency | Assign a signed license/reference |
| POST | `admin/deliveries/{id}/revoke` | cap + throttle + idempotency | Revoke a link |
| POST | `admin/deliveries/{id}/rotate` | cap + throttle + idempotency | Replace a link token |

All admin routes require the `license-portal:manage` capability. Callers send the
standard commerce scope headers (`X-Store-Id`, `X-Channel-Id`, `X-Locale`,
`X-Currency`); missing or ambiguous scope is a 400.

Body for `POST admin/deliveries`:

```json
{
  "customer_id": "cust_123",
  "order_id": "SC-123",
  "sku": "siro-pro-annual",
  "license": { "payload": {}, "signature": "..." },
  "license_reference": null,
  "email": "buyer@example.com",
  "send_email": true,
  "token_ttl_hours": 168
}
```

`license` may be a JSON object or a raw JSON string. It is validated with the
public key configured for `ExtensionLicenseService`; malformed or invalid
signatures are rejected with 422 and never stored. `license_reference` is
accepted when the document lives outside the portal; it creates a pending row
with no download token.

Responses include the one-time `token` and `download_url` on first assignment and
on rotation. They are deliberately not replayable: an idempotent replay returns
the delivery metadata with `token: null`.

## Download

`GET /api/ext/siro/license-portal/download/{token}` is token-only (no session).
It returns the stored signed envelope as an `application/json` attachment when
the link is unused, unexpired and not revoked. The first successful request sets
`token_used_at`; later requests return 410. Expired and revoked links return 410;
unknown tokens return 404. The route is throttled and does not touch filesystem
paths.

## Billing foundation

`boot()` listens for `order.paid` and for `order.transitioned` where `to=paid`.
The listener resolves the order scope, reads order-line SKUs (`components_json`
may carry `license_sku`/`license.sku`) and the configured SKU map, then creates a
`pending` delivery row per mapped SKU. It is idempotent by
`(store_id, channel_id, order_id, sku)` and by the derived idempotency key. If no
mapping exists it is a safe no-op. It never touches payment/checkout core.

SKU mapping sources, highest priority last:

1. Manifest/extension config `sku_map` (`{"siro-pro-license": "siro-pro"}`) and
   `license_sku_prefixes`.
2. Environment override `LICENSE_PORTAL_SKU_MAP` (JSON object).
3. Order metadata (`metadata_json`, `license_metadata_json`, `licenses_json`)
   with `license_sku`, `license`, `licenses[]` or `license_skus[]`.
4. Order line `components_json` with `license_sku`, `siro_license_sku` or
   `license.sku`.

## Email

When `send_email` is true or an explicit `email` is supplied, the one-time link
is sent through `App\Support\Mailer`. If no address is supplied the listener
falls back to the order guest email and then the customer record. Mail failures
never fail the assignment.

## Operate

```bash
php siro extension:migrate
php siro extension:qit <package.zip>
# Admin -> Extensions -> siro/license-portal -> Install -> Enable
```

Uninstall with `remove_data=false` keeps deliveries and disables routes.
Uninstall with `remove_data=true` runs the migration `down()` and drops
`ext_license_portal_deliveries`.
