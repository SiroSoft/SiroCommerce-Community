# Marketplace Lite Spec (free tier)

Adds vendor ownership without payment splitting. Deliberately small so free
tier stops losing to Aimeos on “multi-vendor” while Pro owns payouts.

## Data

`vendors`: `id`, `name`, `slug` (unique), `status` (`active|suspended`),
`commission_bps` (0..10000, default 0), `contact_email` nullable,
`description` nullable, timestamps.

`products.vendor_id` nullable (NULL = house brand). No change to carts,
orders, or payments in v1.

## API

Admin (admin/owner):
- `GET /api/admin/vendors` (list, `?status=&q=`)
- `POST /api/admin/vendors` (`name`, optional `slug`, `commission_bps`,
  `contact_email`, `description`)
- `PUT /api/admin/vendors/{id}` (same fields + `status`)
- `DELETE /api/admin/vendors/{id}` — only when no products reference it (409
  otherwise)
- `PUT /api/admin/products/{id}` accepts `vendor_id` (string or null)

Public (storefront scope headers):
- `GET /api/vendors/{slug}` → vendor profile + product count
- `GET /api/products?vendor=<slug>` filters catalog (facets unchanged)

## Storefront

- `/vendor/[slug]`: vendor header (name, description) + product grid, 404 for
  unknown/suspended vendors.
- Product detail shows “Sold by <vendor>” linking to the vendor page when set.
- Product card: no vendor badge by default (theme can enable later).

## Admin UI

`/vendors`: table (name, slug, status, products count, commission), create/edit
dialog, suspend/activate, delete guarded by ConfirmDialog. Product form gains a
vendor select (optional, loaded from `/api/admin/vendors`).

## Rules

- Vendor slug is lowercase path-safe (`^[a-z0-9][a-z0-9-]{0,79}$`).
- Suspended vendor products stay visible but vendor page shows a notice; the
  catalog filter excludes suspended vendors.
- No commission ledger, payouts, per-vendor shipping, or split payments — Pro.

## Tests

CRUD validation (slug conflict, invalid commission), delete blocked while
products exist, product assignment, public vendor route (active vs suspended),
catalog vendor filter, suspended vendor filter exclusion.
