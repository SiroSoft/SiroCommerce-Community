# 02 — Domain Model & Integrity

## 1. ERD rút gọn (bảng chính)
- `products(id, slug, name, brand_id, status, created_at)`; `product_variants(id, product_id, sku UNIQUE, price_minor INT, compare_price_minor, tax_class, weight_g)`; `product_images`, `product_attributes`, `categories`, `brands`.
- `inventory_items(id, variant_id UNIQUE, on_hand INT, reserved INT, low_threshold)`; `stock_ledger(id, variant_id, delta, reason, ref_type/ref_id, actor, at)`; `reservations(id, variant_id, cart_id/order_id, qty, expires_at, state)`.
- `carts(id, customer_id NULL, currency, state)`; `cart_lines(id, cart_id, variant_id, qty, unit_price_minor SNAPSHOT)`; `cart_promotions`.
- `orders(id, number UNIQUE, customer_id NULL, guest_email, currency, subtotal_minor, discount_minor, shipping_minor, tax_minor, grand_total_minor SNAPSHOT, state)`; `order_lines(id, order_id, variant_id, sku, name, qty, unit_price_minor SNAPSHOT)`; `payments(id, order_id, provider, amount_minor, state, provider_ref, idempotency_key UNIQUE)`; `shipments(id, order_id, provider, tracking, state)`; `refunds(id, order_id, payment_id, amount_minor, reason, state)`.
- `customers(id, email UNIQUE, name)`, `addresses`, `coupons(code UNIQUE, rules JSON, state)`, `seo_meta(entity_type, entity_id, title, desc, canonical, robots, og)`, `redirects(from UNIQUE, to, code)`.

## 2. Order state machine (duy nhất được dùng)
`pending → awaiting_payment → paid → processing → ready_to_ship → shipped → delivered`
Nhánh: `failed`, `cancelled`, `refunded`, `partially_refunded`.
- Chỉ chuyển qua **named transitions** (`place(), markPaid(), ship(), deliver(), cancel(reason), refund(...)`). Cấm `update(['state'=>...])` trực tiếp ngoài transition class.
- Mỗi transition ghi `order_transitions(order_id, from, to, actor, reason, at)`.

## 3. Integrity rules (QA gate cứng)
1. Order totals là snapshot bất biến; đổi giá catalog không viết lại đơn cũ.
2. Tiền = **INT minor units** hoặc Money VO. Cấm float. Test: `0.1+0.2` case + rounding per-line vs per-order phải khai báo.
3. Inventory mutation trong transaction + lock phù hợp driver; **race-test bắt buộc** (concurrent purchase cùng variant).
4. Payment/webhook **idempotent** theo `idempotency_key UNIQUE`; duplicate webhook → `200 already_processed`, không charge lần 2.
5. Không external side-effect nào không log được để retry/recovery.
