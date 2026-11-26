# CMS Revision Workflow

CMS staff can publish or restore a page through the scoped admin workflow:

- `POST /api/admin/pages/{id}/publish`
- `POST /api/admin/pages/{id}/restore/{revision}`

Both mutations require the CMS capability, an authenticated staff actor, an
`Idempotency-Key`, and all four `CommerceScope` headers: `X-Store-Id`,
`X-Channel-Id`, `X-Locale`, and `X-Currency`.

Page and revision scope must match exactly. A revision records the actor and
idempotency key, and replaying the same key for the same page returns the
existing result without creating another mutation. A key reused for another
page is rejected. Successful mutations emit the standard commerce audit event.
