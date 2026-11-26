# B2B Quote Lifecycle

B2B quotes are company-scoped commercial snapshots. Every quote starts in `draft`; its negotiated unit prices and totals are copied into revision 1 and never recalculated during the lifecycle.

## Transitions

`draft -> submitted -> approved -> accepted -> converted`

`submitted` and `approved` may expire. Expiry releases a reserved company-credit amount. Submit reserves credit under a transaction and account lock; conversion consumes the reservation and creates the order from the accepted snapshot without starting payment or finance-document workflows.

All write endpoints require `Idempotency-Key`. Repeating a key returns the original transition evidence. Company membership is checked for every operation, and approval additionally requires the `quote.approve` company scope.

## Endpoints

- `POST /api/v2/b2b/companies/{company}/quotes`
- `POST /api/v2/b2b/quotes/{quote}/{action}`, where action is `submit`, `approve`, `accept`, or `expire`
- `POST /api/v2/b2b/quotes/{quote}/convert`

The quote response exposes `revision`, `lines`, `totals`, and the negotiated `unit_price_minor` snapshot. Catalog membership and an active negotiated price are required when the quote is created.
