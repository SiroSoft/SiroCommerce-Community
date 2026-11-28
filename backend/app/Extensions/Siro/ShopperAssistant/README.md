# Shopper Assistant

Public, read-only storefront assistant.

- Route: `POST /api/ext/siro/shopper-assistant/public/ask` (`optional-auth`)
- Optional context: `product_id`, `product_slug`, `order_id` + `order_token`
- Product facts are public; order facts are added only after a signed-in
  customer owns the order or a guest presents the order access token.
- Failed order verification adds no context, so existence is never revealed.
- Order facts are limited to number, state, currency, total, created_at and
  line item names/qty. No payment, address, email, token or customer id.
- Storefront surface: floating widget registered on the `header` slot.