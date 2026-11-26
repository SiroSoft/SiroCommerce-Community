# 03 — Storefront & Admin API Spec

## 1. Nền (kế thừa Siro Core)
- Envelope: `{success, message, data, meta}` / lỗi `{success:false, message, errors:{field:[msg]}}`.
- Version: header `Accept: application/vnd.siro.v1+json`. Pagination: `{data[], meta:{page,per_page,total,last_page}}`.
- Auth: JWT Bearer (access 3600s) + refresh xoay vòng; admin routes `auth` + RBAC role/capability; storefront cho guest ở cart/checkout read + write scoped.
- Bảo vệ: `throttle` mọi auth/payment/webhook, `FeGuard X-Siro-FE` cho admin API, CORS allowlist (cấm `*` prod), Turnstile cho register/checkout-guest khi bật.

## 2. Endpoints tối thiểu V1
- Storefront: `GET /products, /products/:slug, /categories`, `POST /carts + /carts/:id/lines`, `POST /checkout/quote`, `POST /checkout/place` (idempotency key bắt buộc), `GET /orders/:id` (guest via token), `POST /webhooks/:provider` (raw body, verify HMAC trước validate).
- Admin: CRUD products/variants/categories, inventory adjust, orders transition (`POST /orders/:id/cancel|refund|ship`), coupons, seo-meta, redirects, health.
- Idempotency: client gửi `Idempotency-Key` cho `place checkout`, payment capture, refund. Server lưu key → trả lại response cũ khi trùng.

## 3. Webhook contract
- Giữ raw body để verify signature (ngoại lệ duy nhất được đọc raw). Verify xong mới parse + validate tối thiểu.
- Trả `200` nhanh, xử lý nặng qua Queue. Mọi webhook log `provider, event_id, signature_ok, order_id, result`.

## 4. OpenAPI
- `php siro make:openapi --output=docs/openapi.json` + sync `public/openapi.json`. Mọi endpoint mới/sửa phải regenerate + commit cả 2. FE gen types từ openapi, cấm sync tay.
- HTTP errors are normalized to documented status classes: 400/422 validation, 401 authentication, 403 capability denial, 404 missing resources, 409 optimistic-write conflicts, 429 throttling, and 500 unexpected failures. Admin routes use JWT role gates plus capability middleware where a capability is defined.
