# siro/social-share

Free module that adds a product share bar to the storefront PDP. Follows
`docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`; it does not modify core.

## What it does

- Keeps one settings row per scope in `ext_social_share`, always filtered by
  `store_id` and `channel_id` (`UNIQUE (store_id, channel_id)`).
- `networks` is a JSON array allowlist: `facebook`, `x`, `whatsapp`, `telegram`,
  `email`, `copy`. Duplicates are rejected; at most 6 entries.
- `label` is the share-bar heading (1-60 characters).
- `enabled` toggles storefront rendering; disabled scopes expose no networks.
- The storefront plugin renders only enabled networks and hides itself when the
  extension is disabled, the network list is empty, or the public request fails.

## Routes

Mounted under `/api/ext/siro/social-share/`:

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `admin/settings` | `social-share:manage` | Read scoped settings + admin table |
| `PUT` | `admin/settings` | `social-share:manage` | Save scoped settings (requires `Idempotency-Key`) |
| `GET` | `public` | none, throttled | Active flag, enabled networks and label |

`PUT admin/settings` accepts:

```json
{
  "enabled": true,
  "networks": ["facebook", "x", "whatsapp", "telegram", "email", "copy"],
  "label": "Share this product"
}
```

Missing fields keep the current value, so replaying the same request returns the
same canonical row. Validation rejects an unsupported or duplicate network, a
non-array `networks` value, a label shorter than 1 or longer than 60 characters
and a non-boolean `enabled` with HTTP 422.

`GET public` requires the commerce scope (`X-Store-Id`, `X-Channel-Id`,
`X-Locale`, `X-Currency` headers or matching query parameters) and returns
`{enabled, networks, label}`. A disabled scope returns `enabled: false` and an
empty `networks` array.

## Config defaults

| Key | Default | Meaning |
|---|---|---|
| `default_label` | `Share this product` | Suggested share-bar label |
| `default_enabled` | `false` | New scopes start disabled |
| `slot` | `pdp` | Storefront slot declared by the manifest |

No secret, license key or remote endpoint is required (`license.required=false`).

## Storefront slot

The manifest declares the `pdp` storefront slot. Register it explicitly during
app bootstrap; importing the plugin has no side effects:

```tsx
import { registerSocialShare } from "@/plugins/social-share/register";

registerSocialShare();
```

The component reads
`${NEXT_PUBLIC_STOREFRONT_API_BASE_URL}/api/ext/siro/social-share/public` with
`cache: "no-store"`, reads `window.location.href` and `document.title` inside
effects/handlers only (SSR-safe), and builds Facebook, X, WhatsApp, Telegram,
email and copy-link actions. External links use `rel="noopener noreferrer"` and
every action has an accessible label.

## Uninstall behavior

- **Disable**: unmounts the extension routes, keeps all share settings.
- **Uninstall keep data**: registry row is disabled, `ext_social_share` stays
  intact and a reinstall reuses it.
- **Uninstall remove data**: migration `down()` drops `ext_social_share`; core
  tables are untouched.
