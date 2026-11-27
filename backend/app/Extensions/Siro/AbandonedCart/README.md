# Abandoned Cart (Free) — `siro/abandoned-cart` v1.0.0

Public Community extension (MIT). Captures abandoned carts, stores a totals
snapshot, and sends **one** consent-gated reminder email with a recovery
coupon. No Pro code lives in this package.

## How it works

1. The platform scheduler (`commerce:scheduled`) scans stale open carts,
   calls `MarketingAutomationService::abandonedCart()` (consent + 7-day
   frequency cap + purchase-suppressed), then emits `cart.abandoned`
   (`cart_id`, `customer_id`, `totals_minor` snapshot, `at`).
2. This extension's `boot()` listener persists the snapshot into
   `ext_abandoned_cart_snapshots`, re-checks marketing consent via
   `ConsentService::hasMarketingConsent(customerId)`, resolves the customer
   email, creates (or reuses) a deterministic recovery coupon
   (`AC-RECOVER-<8 hex of cart id>`, 10% off, 1 use, valid 7 days) via
   `CouponService::create()`, and sends the `abandoned_cart` template via
   `MailTemplateRegistry::send('cart.abandoned', …)`.
3. The send is recorded in `ext_abandoned_cart_deliveries` under the scoped
   idempotency key `abandoned_cart:<cart_id>:step-1`. A re-emitted event for
   the same cart is a no-op — the reminder is sent at most once per cart.
4. No consent, no customer row, or no valid email → snapshot is still saved,
   but no mail is sent and no delivery row is written. The listener never
   throws, so a failure here cannot break the core scheduler flow.

## Scope note

Core `carts` rows carry no `store_id`/`channel_id`, so snapshots are
recorded under the platform default scope (`default` / `web` — the same
fallback `CartService` uses). Admin endpoints take the explicit request
scope (`X-Store-Id` / `X-Channel-Id` headers) and only show rows for that
scope. True multi-store scoping needs core cart scope columns; until then
this is the documented behaviour, not a silent cross-store leak.

## Config keys (manifest `config`)

| Key | Default | Meaning |
|---|---|---|
| `coupon_percent_off` | `10` | Percent off the recovery coupon |
| `coupon_valid_days` | `7` | Recovery coupon validity |
| `coupon_usage_limit` | `1` | Max uses of the recovery coupon |

## Routes (`/api/ext/siro/abandoned-cart/`)

| Method | Path | Cap | Notes |
|---|---|---|---|
| GET | `admin/overview` | `abandoned-cart:manage` | Stats panel: abandoned / sent / recovered / revenue |
| GET | `admin/snapshots` | `abandoned-cart:manage` | Table panel: latest 50 snapshots |
| GET | `admin/deliveries` | `abandoned-cart:manage` | Latest 50 delivery rows |
| POST | `coupons` | `coupon:create` | Create a coupon (body: `code?`, `cart_id?`, `rules?` JSON). Same code twice returns the existing coupon — replay-safe. Requires `Idempotency-Key`. |

## Tables

- `ext_abandoned_cart_snapshots` — `id`, `store_id`, `channel_id`,
  `cart_id`, `customer_id` (nullable), `snapshot_json`, `abandoned_at`.
  Unique `(store_id, channel_id, cart_id)`.
- `ext_abandoned_cart_deliveries` — `id`, `store_id`, `channel_id`,
  `cart_id`, `signal`, `step`, `sent_at`, `idempotency_key`.
  Unique `(store_id, channel_id, idempotency_key)`.

## Install / enable / disable / uninstall

- Install → enable runs the two migrations; the `cart.abandoned`
  listener is registered on enable and removed on disable.
- Disable keeps all data (snapshots + deliveries stay, nothing is sent
  while disabled).
- Uninstall with keep-data → state `disabled`, data kept; reinstall
  reuses the data.
- Uninstall with remove-data → drops both `ext_abandoned_cart_*` tables;
  core tables are untouched. Uninstall is blocked (422) while
  `siro/abandoned-cart-pro` is enabled (dependency guard, Wave 0).

## Free vs Pro

| Feature | Free | Pro (`siro/abandoned-cart-pro`) |
|---|---|---|
| Cart capture + snapshot | ✅ | ✅ (reads Free tables) |
| 1 reminder email (consent-gated) | ✅ | ✅ |
| Recovery coupon | ✅ | ✅ |
| Multi-step reminders (step 2/3) | — | ✅ |
| SMS steps | — | ✅ (prepared outbox; see Pro README — no SMS provider in core yet) |
| AI copy drafts (review → approve → use) | — | ✅ |
| Reporting + segmentation | — | ✅ |

## Secrets

None. No credentials are stored or read by this extension.
