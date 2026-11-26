# siro/free-shipping-banner

Free module for a store/channel scoped free-shipping threshold banner.
Follows `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`; it does not modify core.

## What it does

- Keeps one banner row per scope in `ext_free_shipping_banner`, always filtered
  by `store_id` and `channel_id` (`UNIQUE (store_id, channel_id)`).
- `threshold_minor` is the free-shipping threshold in minor units.
- `message` is the banner copy (2-280 characters).
- `active` toggles storefront rendering.
- `currency` is the 3-letter ISO code used with the threshold.
- The storefront plugin renders the message only when `active` is true and the
  message is non-empty.

## Routes

Mounted under `/api/ext/siro/free-shipping-banner/`:

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `admin/settings` | `free-shipping:manage` | Read scoped settings + admin table |
| `PUT` | `admin/settings` | `free-shipping:manage` | Save scoped settings (requires `Idempotency-Key`) |
| `GET` | `public` | none, throttled | Active flag, message, threshold and currency |

`PUT admin/settings` accepts:

```json
{
  "threshold_minor": 500000,
  "message": "Free shipping on orders over 500,000₫",
  "currency": "VND",
  "active": true
}
```

Saving is an upsert on the scope, so replaying the same request returns the
same canonical row. Validation rejects a message shorter than 2 or longer than
280 characters, a negative/non-integer threshold, a non-3-letter currency and a
non-boolean `active` with HTTP 422.

`GET public` requires the full commerce scope (`X-Store-Id`, `X-Channel-Id`,
`X-Locale` and `X-Currency` headers or the matching query parameters) and
returns `{active, message, threshold, threshold_minor, currency}` (the two
threshold keys carry the same value for convenience). Inactive banners return
`active: false` and an empty message.

## Config defaults

| Key | Default | Meaning |
|---|---|---|
| `default_threshold_minor` | `0` | Suggested threshold for admin forms |
| `default_message` | `Free shipping on qualifying orders` | Suggested banner copy |
| `default_active` | `false` | New scopes start inactive |
| `default_currency` | `VND` | Suggested currency |

No secret, license key or remote endpoint is required (`license.required=false`).

## Storefront slot

The manifest declares the `cart` storefront slot. Register it explicitly during
app bootstrap; importing the plugin has no side effects:

```tsx
import { registerFreeShippingBannerSlot } from "@/plugins/free-shipping-banner/register";

registerFreeShippingBannerSlot();
```

The component reads `${NEXT_PUBLIC_STOREFRONT_API_BASE_URL}/api/ext/siro/free-shipping-banner/public`
with `cache: "no-store"`, renders `role="status"` only for an active non-empty
message, and renders nothing on empty/error responses.

## Uninstall behavior

- **Disable**: unmounts the extension routes, keeps all banner data.
- **Uninstall keep data**: registry row is disabled, `ext_free_shipping_banner`
  stays intact and a reinstall reuses it.
- **Uninstall remove data**: migration `down()` drops
  `ext_free_shipping_banner`; core tables are untouched.
