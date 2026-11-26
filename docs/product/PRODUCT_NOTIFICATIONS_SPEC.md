# Product Notifications Spec (P2.4 — back-in-stock / price-drop / preorder notify)

Reuses the money-loop notification rails for every vertical. PDP copy already
promises notify-me; this makes it production-grade.

## Subscription types (`stock_alerts.type`)

- `back_in_stock` (default, legacy rows): variant or any-variant restock.
- `price_drop`: needs `target_price_minor`; fires when the variant's
  `price_minor` falls to `<= target`. Snapshot `price_at_subscribe_minor`.
- `preorder`: only for products with `products.is_preorder = 1`; fires on
  restock with the `preorder_ready` template instead of `back_in_stock`.

One pending row per (product, variant, type, identity). Identity = email
(lowercased) or signed-in customer_id. Variant-aware dedupe: NULL variant and
a concrete variant are different subscriptions.

## API (backend is truth)

- `POST /api/products/{id}/stock-alerts`
  `{variant_id?, email?, type? = back_in_stock, target_price_minor?}` →
  200 `{id, unsubscribe_token, state: pending, type}`.
  `type=price_drop` requires `0 < target_price_minor <= 2^31`.
  `type=preorder` requires the product flagged `is_preorder` (else 422).
  Throttle `20/min` unchanged. 422 invalid, 503 persist failure.
- `POST /api/products/stock-alerts/unsubscribe` `{token}` → 200/404.
- `GET /api/admin/stock-alerts?product_id=&type=` (admin/support) strips
  `unsubscribe_token` + `email` before returning.
- `PUT /api/admin/products/{id}` accepts `is_preorder?: boolean`.

## Firing rules

- Restock: `InventoryAdminService::setOnHand` 0→available wakes
  `back_in_stock` + `preorder` subs via `checkRestock`. Transfer keeps its
  own path (no wake — source move, not new availability).
- Price drop: `SkeletonProductController::update` captures the variant's old
  `price_minor`; when the new price is strictly lower, `checkPriceDrop`
  notifies `price_drop` subs with `target >= new price`.
- Each fired row: `state notified + notified_at` in the same terminal update
  (rowCount guard = idempotent). Email best-effort via `Mailer::send`
  (never throws the request). Subject/body per template, includes product
  name, new price (price-drop), and the unsubscribe token link.
- Respects `customer_notification_preferences.transactional_email = 0` for
  customer-linked rows (skipped rows stay pending).

## Templates (`OrderNotificationMail`)

`back_in_stock`, `price_drop`, `preorder_ready` — subject + build, escaped,
same one-line HTML envelope as order templates.

## Preorder flag

- `products.is_preorder TINYINT DEFAULT 0` (migration). NOT a checkout
  bypass: cart/place still fail closed on OOS. Phase 1 = notify-only so
  shoppers can register interest; purchasable-when-OOS stays out of scope.
- Admin sets via product update; storefront PDP shows preorder badge when
  `is_preorder && stock == 0`.

## Non-goals

No DB template versioning, no SMS/push, no digest batching, no
marketing-consent change (transactional only).
