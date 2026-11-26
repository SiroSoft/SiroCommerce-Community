# 00 — Product Constitution

## 1. Core promise
Commerce that works with you. Simple to run. Smart enough to help. Powerful enough to grow.

## 2. Non-negotiable principles
1. Mạnh bên dưới, đơn giản bề mặt. Task thường phải obvious; nâng cao ẩn đi.
2. Business language cho merchant; technical detail progressive disclosure.
3. Smart chạy được **không cần LLM**. AI là enhancement layer optional.
4. Failure paths là first-class: payment, inventory, queue, webhook, shipping đều có thiết kế + test.
5. Core nhỏ. Minority-need → extension.
6. Mọi feature map vào SELL/GROW/SIMPLE/SMART/SAFE, nếu không thì loại.

## 3. Personas
- **Merchant:** bán, xử lý đơn, tăng traffic, hiểu lỗi → cần vận hành dễ, SEO/convert rõ, action rõ.
- **Agency:** build/customize/maintain/report → cần delivery nhanh, extension có kiểm soát, health/recovery/reporting.
- **Developer:** domain logic, tích hợp provider, debug → cần contracts sạch, traceability, test + recovery an toàn.
- **Warehouse/Ops:** soạn/ship/return/adjust → workspace role-focused, ít UI thừa.
- **Marketing:** catalog/promo/SEO/perf → growth workspace + recommendation actionable.

## 4. Decision filter (áp cho mọi proposal, theo thứ tự)
1. Thuộc pillar nào?
2. Đa số store có cần? Không → extension.
3. Có tăng complexity merchant? Có → redesign (progressive disclosure / role-aware).
4. Demo được bằng workflow thật? Không → chưa làm.
5. Có sinh failure/side-effect mới? Có → phải kèm observability + idempotency + recovery trước khi code.
6. Có phá small-core? Có → reject hoặc ra extension.

## 5. Non-goals V1 (cấm scope-creep)
Marketplace/multi-vendor, POS, CRM, accounting/ERP, multi-warehouse nâng cao, supplier/PO, subscription, complex tax, visual page builder/full CMS, hàng trăm gateway, parity WooCommerce/Bagisto.
