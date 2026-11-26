# Auth Security Notes

The current frontend contract returns bearer access and refresh tokens to JavaScript, so the admin stores them in browser storage. The auth coordinator now validates persisted access JWT shape and expiry, clears corrupted state, shares refresh work, and prevents refresh responses from crossing logout or login generations.

Residual live-only risks:

- Browser storage is readable by same-origin JavaScript. Moving tokens to `httpOnly`, `Secure`, `SameSite` cookies requires a backend session/cookie contract and CSRF policy that this frontend does not currently have.
- The refresh proxy allowlist and rate-limit identity depend on deployment configuration and a trusted reverse proxy. `x-forwarded-for` must not be accepted from untrusted clients in production.
- The storefront purchase contract has no payment-status operation for an ambiguous guest payment. The UI reconciles by idempotency key where the existing order endpoint permits it, but an unknown provider result still requires backend/payment-provider reconciliation before a safe retry can be guaranteed.
