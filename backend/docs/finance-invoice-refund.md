# Invoice and Refund Evidence

Invoices snapshot the order totals, order lines, tax evidence, and linked payment evidence at creation. The snapshot is redacted before persistence and is never rebuilt from mutable catalog data.

Credit memos are linked to the originating invoice, payment, and refund. Refund capacity is reserved while the invoice is locked, so concurrent partial refunds cannot exceed the invoice total. Each refund and credit memo uses the request idempotency key; replaying a completed operation returns the original record without another provider operation.

Invoice and credit memo line/tax records are append-only evidence rows. Lifecycle transitions are fail-closed: invoices can only move from `draft` to `issued`, then payment reconciliation may move them to `paid`, `partially_refunded`, or `refunded`; unknown provider refund results do not issue a credit memo.

Promotion reconciliation releases the immutable checkout discount proportionally for refunds and cancellations. It is serialized by the order lock, records currency and minor-unit evidence, and is idempotent by the refund or cancellation key. Reusing a key with a different order, amount, currency, or evidence shape fails closed; the final partial refund releases only the exact remaining discount.

Gift/store credit is not implemented. `GiftStoreCreditContract` is an integration boundary only, and `FailClosedGiftStoreCredit` rejects issuance, redemption, rollback, and expiry because no evidence-backed balance, owner, or lifecycle ledger exists yet.
