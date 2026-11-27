# siro/email-capture-popup

Free module for an exit-intent / time-delayed email capture popup with a
welcome coupon. Follows `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`; it does
not modify core.

## What it does

- Stores one subscriber row per scope in `ext_email_capture_subscribers`,
  always filtered by `store_id` and `channel_id`
  (`UNIQUE (store_id, channel_id, email)`).
- The storefront popup posts the opt-in to core `POST /api/consent/marketing`
  first, then posts the email here. Core never stores the email — this
  extension owns it in its own `ext_*` table.
- The welcome mail (template `welcome`, variables `customer_name`,
  `coupon_code`) is sent through `MailTemplateRegistry` only when
  `ConsentService::hasMarketingConsent()` is true for the subscriber
  (customer preference when a customer row exists for the email, session
  ledger otherwise). Subscribing without consent stores the row with
  `consented_at = NULL` and sends nothing.
- A consented subscriber gets a one-off welcome coupon (`WELCOME-XXXXXXXX`,
  10% off, created via `CouponService::create()` on the extension route
  `POST /api/ext/siro/email-capture-popup/coupons` with `cap:coupon:create` —
  never via the admin API with an admin token).
- Re-subscribing the same email in the same scope returns the existing row
  (`already_subscribed: true`) and never sends a second welcome mail.

## Routes

Mounted under `/api/ext/siro/email-capture-popup/`:

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `public` | none, throttled | Popup config (`active`, `delay_ms`, `coupon_enabled`, `coupon_percent`) |
| `POST` | `subscribe` | none, throttled, `Idempotency-Key` | Subscribe an email (201 new, 200 replay) |
| `GET` | `admin/stats` | `email-capture:manage` | Subscriber stats panel |
| `GET` | `admin/subscribers` | `email-capture:manage` | Subscriber table panel (paginated) |
| `DELETE` | `admin/subscribers/{id}` | `email-capture:manage`, `Idempotency-Key` | Delete one subscriber (scoped, audit-logged) |
| `POST` | `coupons` | `coupon:create`, `Idempotency-Key` | Create a coupon |

`POST subscribe` accepts:

```json
{
  "email": "shopper@example.com",
  "source": "popup",
  "session_id": "<same session id sent to POST /api/consent/marketing>"
}
```

Validation rejects an invalid email, an over-long email (>254) or a source
outside `[A-Za-z0-9_-]` with HTTP 422. A missing commerce scope is HTTP 400.
Admin mutations are audit-logged (`ext.email-capture-popup.coupon.created`,
`ext.email-capture-popup.subscriber.deleted`).

## Config defaults

| Key | Default | Meaning |
|---|---|---|
| `default_active` | `true` | Popup renders when the extension is enabled |
| `default_delay_ms` | `8000` | Time-delay trigger (ms) before the popup shows |
| `default_coupon_enabled` | `true` | Consented subscribers get a welcome coupon |
| `default_coupon_percent` | `10` | Welcome coupon discount percent |

No secret, license key or remote endpoint is required (`license.required=false`).

## Storefront slot

The manifest declares the `footer` storefront slot (rendered on every page
via the layout). Register it explicitly during app bootstrap; importing the
plugin has no side effects:

```tsx
import { registerEmailCapturePopup } from "@/plugins/email-capture-popup/register";

registerEmailCapturePopup();
```

The component reads `${NEXT_PUBLIC_STOREFRONT_API_BASE_URL}/api/ext/siro/email-capture-popup/public`
with `cache: "no-store"`, renders nothing on empty/error responses, and only
shows the popup after the time-delay / exit-intent / scroll trigger, once per
session. It is a minimal temporary implementation (`TODO-UX-PRIMITIVE`) until
the shared modal/focus-trap primitives from the Experience lane land.

## Uninstall behavior

- **Disable**: unmounts the extension routes, keeps all subscriber data.
- **Uninstall keep data**: registry row is disabled,
  `ext_email_capture_subscribers` stays intact and a reinstall reuses it.
- **Uninstall remove data**: migration `down()` drops
  `ext_email_capture_subscribers`; core tables are untouched.
