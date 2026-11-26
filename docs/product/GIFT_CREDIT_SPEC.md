# Gift Cards & Store Credit — Spec

One shared money loop: gift cards sold/issued → tendered at checkout like a
coupon → leftovers refundable back as store credit → wallet-visible balance.

## Identity

- Owner is the **account user id** (`owner_customer_id`). The same identity
  member pricing, loyalty snapshots, and order `customer_id` already key on
  (see `PlaceOrderService` member attribution).
- Bearer (unbound) gifts have `owner_customer_id = null` and redeem for
  guests; bound gifts reject other owners with 422.
- Guest orders carry no customer and **cannot** refund to credit (422).

## Backend truth

- Tables: `gift_credits` (balance ledger head), `gift_credit_ledger`
  (append-only, idempotency-keyed). Service: `GiftCreditService`.
- Rules: fail-closed on unknown/bound/inactive/expired/currency-mismatch/
  overdraft. Redeem is compare-and-swap on `balance_minor`; rollback replays
  safely; void freezes the remainder.
- Checkout tenders **last**, on the coupon-reduced base
  (`min(balance, base)`). Redeemed inside the order transaction; a failed
  place rolls the ledger back. Quote previews only (no reservation).
- `POST /api/admin/orders/{id}/refund-to-credit` converts the remaining
  refundable value into `store_credit` for the order's customer and moves the
  order to `refunded`/`partially_refunded`. Replay of the same
  `Idempotency-Key` returns the same credit, no duplicate row.
- Wallet: `GET /api/account/credits`. Public preview (no auth):
  `POST /api/gift/preview` → `{code, balance_minor, usable_minor}`.

## Frontend

- Storefront: gift fieldset beside the voucher on the quote step
  (`previewGift` → usable/balance validation), `gift_code` flows
  quote → checkout → place. Totals validation subtracts `gift_discount`.
- Admin: `/gift-cards` (issue/list/void), order detail gains a
  **Refund to credit** button beside Refund remaining.

## Limits (v1)

- ~~History visibility for member orders is a follow-up~~ — done:
  account surfaces (orders list/detail/timeline, returns, notifications,
  financial documents) resolve both the customer-profile id and the auth
  user id (`CustomerCommerceService::orderIdentities`), so member-placed
  orders appear in history while cross-account reads stay 404.
- No partial gift split across orders in one tender beyond `min(balance, base)`;
  no gift purchase flow (issue is staff-side only).
