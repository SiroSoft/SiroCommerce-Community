# Staff Assistant

Read-only AI assistant for admin and staff.

- Route: `POST /api/ext/siro/staff-assistant/admin/ask`
- Optional context: `order_id`, `product_id`, `customer_id`
- Context is gated by `StaffAssistantPolicy`: owners have broad read-only
  access, other roles follow the existing `orders`, `products` and `customers`
  capabilities. Customer PII is owner-only; other roles get a masked name and no
  email.
- Only scoped order/product facts and safe customer facts are sent to the
  existing AI gateway. Payment, token and password fields are never included.
- No refunds, price changes, inventory writes, or order transitions.
- Admin surface: Extensions -> Staff Assistant -> chat panel + provider status.