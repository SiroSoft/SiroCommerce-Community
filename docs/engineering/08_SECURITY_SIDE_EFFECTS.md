# 08 — Security & Side-Effects

## 1. Auth / RBAC
- JWT access ngắn + refresh xoay vòng + token version (đá user khi cần). Secret ≥32 ký tự.
- Roles tối thiểu: `owner, admin, marketer, warehouse, support, viewer`. Capability check ở Service, không chỉ ẩn nút FE.
- DemoGuard cho demo store: chặn write của demo user (403 demo.readonly).

## 2. Input / output
- Mọi write endpoint `->validate(...)` trước Service. Mass assignment 2 lớp: controller whitelist + service `array_intersect_key` khớp `$fillable`.
- SQL binding tuyệt đối; sort/order qua allowlist. Output HTML `htmlspecialchars`. Upload validate MIME+ext+size.
- Log redact: password, token, secret, card, cvv. CSP + security headers mọi response.

## 3. Webhook / payment safety
- Verify HMAC với raw body trước mọi xử lý. Thiếu/invalid signature → 4xx + log, không queue.
- Idempotency key UNIQUE cho payment capture/refund + checkout place.
- Recovery policy: dry-run trước replay/retry có side-effect; guard already-captured/committed/duplicate shipment/notification; ghi actor/reason/before-after + verification result.

## 4. Flight Recorder / Doctor
- Recorder correlate: Checkout→Pricing→Inventory→Payment→Webhook→Order→Event→Queue→Shipping→Noti (duration, queries, events, jobs, outbound HTTP, transitions, exceptions, redacted payload meta).
- Doctor checks: app/db/migrations/queue/scheduler/cache/mail; catalog-inventory consistency; order/payment/webhook/shipping health; SEO/security/perf. Hỗ trợ scoped `--seo/--payment/order:{id}`.
