# Customer Engagement

Customer notification preferences default to transactional email enabled and marketing email disabled. Account updates preserve omitted values and accept an optional `Idempotency-Key`; a reused key must have the same request and customer. Preference resources never expose customer contact fields.

The loyalty account is customer-owned. Points can be earned only once for a delivered order belonging to that customer, at one point per 100 minor currency units. Redeem and expiry operations are atomic, customer-scoped, and idempotent. Earned lots expire after one year; expiry consumes only points still available after earlier redemption or expiry ledger entries.

Order notification jobs resolve preferences at execution time and skip transactional delivery when the customer has opted out. Guest orders remain outside the account loyalty boundary.

Authenticated customers can read `GET /api/account/subscriptions` and `GET /api/account/subscriptions/{id}`. Both routes enforce ownership before returning data, paginate the collection, and omit provider and payment-attempt fields. Subscription start, transition, and renewal remain idempotent local lifecycle operations; no live provider is called.
