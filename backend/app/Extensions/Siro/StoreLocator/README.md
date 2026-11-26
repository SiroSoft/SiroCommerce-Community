# siro/store-locator

Free store locator extension. Admins manage a store/channel scoped list of
stores or pickup points, and the storefront exposes an active-location list in
the footer slot. Follows `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`,
`EXTENSION_PACKAGING_SPEC.md`, `EXTENSION_UI_SPEC.md` and
`docs/engineering/EXTENSION_SCOPING.md`; it does not modify core.

## What it does

- Table `ext_store_locations` is scoped by `store_id` + `channel_id` and stores
  `name`, `address`, `city`, optional `phone`, `hours`, `latitude`,
  `longitude`, `active`, `created_at` and `updated_at`.
- Every SELECT/INSERT/UPDATE/DELETE is filtered by `CommerceScope`, so one
  store/channel can never read or mutate another's locations.
- The public storefront endpoint returns active locations only; the admin
  endpoint returns every location in scope, including inactive rows.
- The storefront renders a "Stores" button in the `footer` slot. It opens an
  accessible, closable dialog with name/address/city/phone/hours and a Google
  Maps link (`https://www.google.com/maps?q=lat,lng`) when coordinates exist.
  It renders nothing when the list is empty or the API request fails.

## Routes

Mounted under `/api/ext/siro/store-locator/`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `public` | none (throttled) | Active locations for the current store/channel |
| `GET` | `admin` | `store-locator:manage` | Admin table rows (active + inactive) |
| `POST` | `admin` | `store-locator:manage` | Create a location (idempotent) |
| `PUT` | `admin/{id}` | `store-locator:manage` | Partially update a location (idempotent) |
| `DELETE` | `admin/{id}` | `store-locator:manage` | Delete a location (idempotent) |

Mutations require a valid `Idempotency-Key` (enforced by the extension route
loader). Posting twice with the same key returns the same row. Repeating the
same `PUT` is a no-op, and repeating a `DELETE` succeeds without error. A
`DELETE` from another store/channel never removes the row.

### Create body

```json
{
  "name": "Siro Flagship",
  "address": "123 Nguyen Hue",
  "city": "Ho Chi Minh City",
  "phone": "+84 28 1234 5678",
  "hours": "Mon-Sat 09:00-21:00",
  "latitude": 10.7769,
  "longitude": 106.7009,
  "active": true
}
```

`PUT admin/{id}` accepts any subset of those fields. Missing fields keep their
current value. `active` defaults to `true` on create.

## Validation

Invalid input returns `422`. Rules:

- `name`: 2-120 characters.
- `address`: 5-300 characters.
- `city`: 1-80 characters.
- `phone`: optional, at most 40 characters.
- `hours`: optional, at most 120 characters.
- `latitude`: optional number from -90 to 90.
- `longitude`: optional number from -180 to 180.
- `latitude` and `longitude` must be supplied together (both or neither).
- `active`: boolean (`true`/`false`, `1`/`0`).
- Text fields reject HTML/control characters and `javascript:` payloads. The
  storefront renders text through React, never `dangerouslySetInnerHTML`.

## Install / enable

```bash
php siro extension:check
php siro extension:pack app/Extensions/Siro/StoreLocator --out=dist/siro-store-locator-1.0.0.zip
php siro extension:verify dist/siro-store-locator-1.0.0.zip
php siro extension:qit dist/siro-store-locator-1.0.0.zip
# Admin -> Extensions -> siro/store-locator -> Install -> Enable
# (Enable runs migrations/20261124000003_create_ext_store_locator.php)
```

Disable unmounts all routes and keeps data. Uninstall with "remove data" runs
`down()` and drops `ext_store_locations`; core tables are never touched.

## Storefront

Storefront plugin: `apps/storefront/src/plugins/store-locator/`.
`register.ts` registers slot `footer` with id `siro/store-locator` and priority
80. The component fetches the public endpoint for the active store/channel,
shows a "Stores" button only when there are locations, and lists each location
inside a modal dialog. The dialog closes with its close button, the overlay or
Escape.

## Config

| Key | Default | Meaning |
| --- | --- | --- |
| `default_label` | `Stores` | Storefront button label. |
| `slot` | `footer` | Storefront slot the widget registers into. |

No secrets, license key or remote endpoint is required
(`license.required=false`).
