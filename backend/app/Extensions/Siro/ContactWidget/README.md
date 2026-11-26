# siro/contact-widget

Free reference module: a store/channel scoped floating contact widget for the
storefront footer slot. Follows `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`,
`EXTENSION_PACKAGING_SPEC.md` and `EXTENSION_UI_SPEC.md`; it does not modify
core.

## What it does

- Keeps one row per `(store_id, channel_id)` in `ext_contact_widget`.
- `enabled` toggles the widget; `zalo_url`, `whatsapp_url` and `email` are the
  contact targets; `label` is the accessible widget/CTA label.
- Every read and write is filtered by the resolved `CommerceScope`, so one
  store/channel cannot see another's settings.
- The storefront renders the widget through the `footer` slot. It is hidden
  when disabled, when every link is empty, or when the fetch fails.

## Routes

Mounted under `/api/ext/siro/contact-widget/`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `admin/settings` | `contact-widget:manage` | Read scoped settings + panel stats |
| `PUT` | `admin/settings` | `contact-widget:manage` | Idempotent scoped upsert |
| `GET` | `public` | none (throttled) | Storefront payload: `enabled`, `links`, `label` |

`PUT admin/settings` accepts any subset of:

```json
{
  "enabled": true,
  "zalo_url": "https://zalo.me/123456789",
  "whatsapp_url": "https://wa.me/84123456789",
  "email": "support@example.com",
  "label": "Need help?"
}
```

Validation (422 on failure):

- `zalo_url` / `whatsapp_url`: empty or `https://` URL only (max 2048).
- `email`: empty or a valid email address (max 254).
- `label`: 1-40 characters.
- `enabled`: boolean/`0`/`1`/`true`/`false`.

Replaying the same `PUT` (or an `Idempotency-Key` replay) leaves a single row;
missing fields keep the current value.

`GET public` returns:

```json
{
  "enabled": true,
  "links": {
    "zalo": "https://zalo.me/123456789",
    "whatsapp": "https://wa.me/84123456789",
    "email": "support@example.com"
  },
  "label": "Need help?"
}
```

Disabled scopes return `enabled: false` with `null` links.

## Config defaults

| Key | Default | Meaning |
|---|---|---|
| `default_label` | `Contact us` | Label used before a scope is configured |
| `slot` | `footer` | Storefront slot the widget registers into |

No secret, license key or remote endpoint is required (`license.required=false`).

## Storefront plugin

`apps/storefront/src/plugins/contact-widget/index.tsx` renders the fixed
bottom-right widget; `register.ts` exposes `registerContactWidget()`, which
registers it on the `footer` slot with id `siro/contact-widget` and priority 90.
Importing the module does not register anything; wire it during bootstrap when
the extension is enabled.

## Uninstall behavior

- **Disable**: unmounts the extension routes, keeps all settings.
- **Uninstall keep data**: the registry row is disabled, `ext_contact_widget`
  stays intact and a reinstall reuses it.
- **Uninstall remove data**: migration `down()` drops `ext_contact_widget`;
  core tables are untouched.
