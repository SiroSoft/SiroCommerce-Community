# Siro ERP Lite — API Spec v0.1 (Loyalty Tier · Subscription · POS/Marketplace)

> Commerce (`SiroCommerce` BE) là **checkout/catalog truth**, ERP Lite là **ledger/settlement truth**. Mọi write qua ERP phải idempotent, scope-aware (`X-Store-Id/Channel/Locale/Currency` + `X-Siro-ERP` signature), presentation-only (không HTML/CSS/JS tùy ý).

Base: `ERP_LITE_URL` env (`https://erp-lite.siro.internal`) — commerce gọi outbound, ERP gọi inbound webhooks.

## 1) Loyalty Tier

### Commerce → ERP: evaluate tier
```
POST {ERP_LITE_URL}/api/v2/loyalty/tiers/evaluate
Headers: X-Store-Id, X-Channel-Id, X-Siro-ERP: sha256(hmac body, ERP_WEBHOOK_SECRET), Idempotency-Key: ^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$
Body: { customer_id: string(26), order_id: string(26), order_total_minor: int>=0, currency: string(3), store_id: string, channel_id: string }
200: { tier: "bronze"|"silver"|"gold"|"platinum", points_earned: int>=0, rate_bps: int 0..10000, reason?: string }
422: invalid scope/currency, 401: bad signature, 409: stale idempotency
```
Commerce cache `customer_loyalty_snapshot` keyed by `order_id` (idempotent), cron `commerce:scheduled` không tự tính tier — chỉ gọi ERP.

### ERP → Commerce: tier applied (optional webhook)
```
POST /api/webhooks/erp/loyalty
Headers: X-ERP-Signature: sha256(hmac body, ERP_WEBHOOK_SECRET), X-Correlation-Id
Body: { customer_id, order_id, tier, points_earned, applied_at: ISO8601 }
200: { success: true }
401: bad signature
```
Commerce handler `ErpWebhookController::loyalty` upsert snapshot + emit `loyalty.tier_applied`.

## 2) Subscription Engine (Billing)

### Commerce → ERP: renew
```
POST {ERP_LITE_URL}/api/v2/billing/subscriptions/{id}/renew
Headers: X-Siro-ERP, Idempotency-Key: sub-{id}-{period}
Body: { period: "2026-10", amount_minor: int, currency: string }
200: { subscription_id, state: "active"|"past_due", next_billing_at: ISO8601 }
422/409 as above
```
Commerce local `subscriptions` chỉ projection, không tự renew.

### ERP → Commerce: subscription state
```
POST /api/webhooks/erp/subscription
Headers: X-ERP-Signature
Body: { subscription_id: string, state: "active"|"past_due"|"cancelled", next_billing_at?: ISO8601, period: string }
200: { success: true }
```
Handler `ErpWebhookController::subscription` update `subscriptions.state` + `next_billing_at`.

## 3) POS / Marketplace

### POS ingest (ERP POS → Commerce)
```
POST /api/webhooks/erp/pos
Headers: X-ERP-Signature
Body: { pos_order_id: string(26), store_id, channel_id, lines: [{ sku: string, qty: int>0, price_minor: int }], total_minor: int, currency: string, idempotency_key: string }
200: { order_id: string, state: "placed" }
409: duplicate idempotency_key → return existing order_id
```
Handler `ErpWebhookController::pos` creates order via `OrderRepository` with `source=pos`, no price invention (price from ERP payload verified via `CommerceScope`).

### Marketplace seller split
```
POST {ERP_LITE_URL}/api/v2/marketplace/orders/{id}/seller-split
Body: { splits: [{ seller_id, amount_minor, currency }] }
→ webhook
POST /api/webhooks/erp/marketplace
Body: { order_id, splits, settled_at: ISO8601, payout_ids: string[] }
```
Commerce handler `ErpWebhookController::marketplace` upserts `seller_orders` projection, leaves `commissions/payouts` ledger to ERP.

### Existing commerce parity routes stay:
- `GET /api/v2/admin/seller-orders` (poll), `POST /api/v2/admin/orders/{id}/seller-split` (manual), `POST /api/v2/admin/commissions` etc. — ERP Lite is source for settlement.

## Auth & scope

- Outbound commerce→ERP: `X-Siro-ERP: sha256(hmac(JSON.stringify(body), env('ERP_WEBHOOK_SECRET')))` + `X-Store-Id/Channel/Locale/Currency` from `ScopeCoordinator`.
- Inbound ERP→commerce: `X-ERP-Signature` same scheme, verified in `ErpWebhookController` before JSON parse. `Idempotency-Key` unique per `order_id`/`subscription_id+period` (store in `erp_webhook_idempotency` table, 90d retention).
- No `settings` mutation, no HTML: `safeText/safeUrl` only, `tokens.colors` never from ERP.

## Errors

- 401 bad signature, 409 stale idempotency (returns prior result), 422 invalid `currency`/scope, envelope `{success:false, message, meta:{error_code}}` consistent with `ApiContract`.

## Rollout

1. Add `ERP_LITE_URL`, `ERP_WEBHOOK_SECRET` to `backend/.env.example` + `deploy/.env` template.
2. `ErpLiteClient.php` (outbound) + `ErpWebhookController.php` (inbound) + `routes/api.php` 3 webhooks.
3. Parity diagnostics tab `parity/page.tsx` tests live ERP with dry-run.
4. No DB fork: only `erp_webhook_idempotency` additive migration; commerce keeps `subscriptions`/`customer_loyalty_snapshot` as projections.
