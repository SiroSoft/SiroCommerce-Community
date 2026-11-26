# Vendor Payouts Spec (free tier v1)

Builds on Marketplace Lite (`vendors`, `products.vendor_id`). Money math is
snapshotted at order placement; payouts are manual batches (no bank transfer).

## Snapshots at placement

`order_lines.vendor_id` (nullable) and `order_lines.vendor_commission_minor`
resolved from the product vendor + `commission_bps` at place time. Existing
orders keep NULL (house brand).

## Ledger

`vendor_ledger`: `id`, `vendor_id`, `order_id`, `order_line_id` nullable,
`type` (`sale|refund|adjustment`), `gross_minor`, `commission_minor`,
`net_minor`, `currency`, `state` (`pending|available|paid|void`),
`payout_id` nullable, `created_at`.
- Sale: gross = line total, commission = round(gross * bps / 10000), net =
  gross - commission; created when the order reaches `paid` (event
  `order.transitioned` with to_state paid, or payment captured — pick the
  existing reliable hook).
- Refund: negative gross and commission proportional to refunded amount (v1:
  refund-to-credit/refund events with order line id if available; otherwise
  order-level negative entry).
- Idempotent by (`order_line_id`, `type`) for sales, (`order_id`,`type`,`amount`)
  for refunds.

## API (admin/owner, cap `vendors`)

- `GET /api/admin/vendors/{id}/ledger?state=&from=&to=` → entries + totals
  `{gross,commission,net,pending,available,paid}`.
- `GET /api/admin/vendors/{id}/payouts` / `POST /api/admin/vendors/{id}/payouts`
  (`period_start`, `period_end`, `Idempotency-Key`): moves `available` entries in
  the window into a new payout (`vendor_payouts`: id, vendor_id, amount_minor,
  currency, state `pending|paid|cancelled`, period, created/paid_at).
- `POST /api/admin/vendor-payouts/{id}/mark-paid` / `cancel` (cancel returns
  entries to `available`).
- Launch/vendor page shows pending/available balances.

## Vendor self-service (optional v1)

`GET /api/account/vendor/ledger|payouts` when the signed-in customer is the
vendor owner (`vendors.owner_customer_id` nullable; if NULL, admin-only).
No payout request in v1.

## Tests

Commission rounding, snapshot immutability when bps changes later, sale created
once on replay, refund negative entry, payout batches window + idempotency,
mark-paid/cancel transitions, balances, authorization.

## Non-goals

Automated transfers, tax withholding, multi-currency payout conversion.
