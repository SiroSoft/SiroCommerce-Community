# Split Payments — Spec (P1.2)

One order, several tenders. V1 scope is **multi-gift tendering**: stack up to
`MAX_GIFT_CODES` gift/store-credit codes on one order, applied sequentially
on the shrinking remainder, with the leftover going to the single payment
provider as before. (True multi-provider splits — two cards, card + COD —
stay out of scope until live gateway credentials exist.)

## Tender order (fixed, backend truth)

1. Member discount (member-reduced base)
2. Coupon (on the member-reduced base)
3. Gift codes **in the order given**, each covering
   `min(balance, remaining_base)` on the coupon-reduced remainder
4. Remainder → single payment provider (`cod` default)

## API

- `GET /api/carts/{id}/quote?coupon=X&gift[]=A&gift[]=B` (array syntax) or
  `?gift=A,B` (comma-separated) — max 5. Plain repeated `?gift=A&gift=B`
  does NOT work (PHP `$_GET` keeps only the last). Response keeps
  `gift_discount_minor` (total) and adds
  `gift_tenders: [{code, covered_minor}]`. Legacy single `gift=` keeps
  working; `gift_code` stays in the response for the 1-code case.
- `POST /api/checkout/place` accepts `gift_code` (legacy, 1 code) or
  `gift_codes: [...]` (up to 5). **Both together → 422** (no ambiguity).
  Duplicate codes in one request → 422. Unknown/bound/inactive/currency-mismatch
  on ANY code → 422, nothing redeemed (fail-closed, whole tender aborts).
- A code that covers 0 (balance empty or base already 0) → 422
  `Gift balance is empty` — same fail-closed rule as v1.
- Each redemption writes its own ledger row with key
  `gift:{idempotency}:{CODE}`. Replaying the same place key replays safely
  (per-code idempotency, no double spend). Order placement stays in one DB
  transaction: any failure rolls every code back.
- Promotion evidence lists one entry per code:
  `{gift_code, gift_discount_minor}` — admin/finance read the split from
  the order row, no new tables.

## Limits

- `MAX_GIFT_CODES = 5`. More → 422.
- Codes apply in request order; first code gets first bite. No reordering
  optimization (predictability beats maximal coverage).
- Snapshot matching compares the normalized code SET, order-insensitive.
- Storefront: add up to 5 codes (add/remove list), quote shows per-code
  coverage; checkout carries the set through place.
