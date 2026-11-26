# P0-2 — Event Catalog + Transition Tables (bám sát spec 01 + 02 + 08)

> Mọi event mang: `event_id UNIQUE, order_id/cart_id, idempotency_key, actor, at`. Side-effect nào cũng identifiable + auditable (08§3).

## 1. Event catalog (tên đóng băng — code phải dùng đúng)
| Event | Khi bắn | Payload bắt buộc |
|---|---|---|
| `catalog.product_created/updated` | CRUD product/variant | product_id, variant_ids, actor |
| `inventory.reserved` | Checkout lock tồn | variant_id, qty, reservation_id, expires_at |
| `inventory.released` | Hủy/timeout/fail | reservation_id, reason |
| `inventory.committed` | Paid → trừ thật | reservation_id, order_id |
| `inventory.adjusted` | Admin adjust | variant_id, delta, reason, actor |
| `inventory.low_stock` | on_hand-reserved ≤ threshold | variant_id, available |
| `cart.priced` | Quote xong | cart_id, totals_minor snapshot |
| `checkout.started/failed` | Bắt đầu/lỗi place | cart_id, reason (nếu fail) |
| `order.placed/paid/cancelled/refunded` | Transitions | order_id, from→to, actor, reason |
| `payment.authorized/captured/failed/webhook_received` | Gateway/webhook | payment_id, provider, provider_ref/event_id |
| `shipment.created/shipped/delivered` | Ship flow | shipment_id, tracking |
| `promo.applied/rejected` | Áp coupon | cart_id, code, reason (nếu reject) |

## 2. Order transitions (named — cấm update tay, theo 02§2)
| From → To | Method | Guard |
|---|---|---|
| pending → awaiting_payment | `place()` | cart priced + reserve ok + idempotency key mới |
| awaiting_payment → paid | `markPaid()` | payment captured, guard already-captured |
| paid → processing | `process()` | commit reservations |
| processing → ready_to_ship | `pack()` | đủ tồn đã commit |
| ready_to_ship → shipped | `ship()` | shipment created, guard duplicate shipment |
| shipped → delivered | `deliver()` | tracking delivered |
| any → failed | `fail(reason)` | payment fail/timeout/tx fail |
| pending/awaiting_payment → cancelled | `cancel(reason)` | chưa commit hoặc release xong |
| paid/processing → refunded / partially_refunded | `refund(amount,reason)` | payment refunded, idempotent |

## 3. Payment states
`pending → authorized → captured → (refunded|partially_refunded)`; nhánh `failed`, `cancelled`. `verifyWebhook` trước mọi chuyển từ webhook; duplicate event_id → `already_processed`.

## 4. Inventory reservation states
`held → committed | released | expired`. Hết `expires_at` → `expired` + bắn `released`. Mọi mutation qua transaction + ghi `stock_ledger(delta, reason, ref, actor)`.
