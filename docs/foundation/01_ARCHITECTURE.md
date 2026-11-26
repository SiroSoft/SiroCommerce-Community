# 01 — Architecture (modular monolith on SiroPHP Core)

```
Storefront (Next.js) ──┐
Admin (Next.js) ───────┼──► SiroPHP API ──► Commerce Core ──► Providers (via contracts)
                        │      │                │
                        │      ├── SELL: pricing, promo, payment, shipping, noti
                        │      ├── GROW: SEO engine, analytics, perf budgets
                        │      ├── SMART: action center, recommend, anomaly, search
                        │      └── SAFE: health, flight recorder, doctor, recovery, audit
                        └── Siro Core sẵn có: Router, ORM, JWT, Queue, Cache, Events, CLI, Trace
```

## Aggregate boundaries (không lấn sân)
- **Catalog:** Product, Variant/SKU, Category, Brand, Attribute, Image, Price. Không giữ stock.
- **Inventory:** InventoryItem + StockLedger + Reservation. Nguồn sự thật tồn kho duy nhất.
- **Cart:** Cart + lines + pricing snapshot + promotion applications. Không trừ kho.
- **Checkout:** orchestration: validate cart → pricing → reserve → payment intent → create order.
- **Order:** immutable snapshot + payments + shipments + refunds. Giá lịch sử bất biến.
- **Customer:** identity + addresses + order relation. Guest checkout bắt buộc hỗ trợ.

## Event catalog (tối thiểu)
`catalog.product_created/updated`, `inventory.reserved/released/committed/adjusted/low_stock`,
`cart.priced`, `checkout.started/failed`, `order.placed/paid/cancelled/refunded`,
`payment.authorized/captured/failed/webhook_received`, `shipment.created/shipped/delivered`,
`promo.applied/rejected`. Mọi event mang `order_id/cart_id + idempotency_key + actor + at`.

## Side-effect policy
- Mọi side-effect ngoài (charge, trừ kho, gửi mail, bắn webhook, tạo shipment) phải **identifiable** (idempotency key) + **auditable** (log actor/reason/before-after).
- Retry chỉ qua named transition / recovery tool, cấm update status tay.
