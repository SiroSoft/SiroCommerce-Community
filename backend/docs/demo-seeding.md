# Demo Data Seeding

## B2C catalog

For local or server deployment, seed only the default B2C catalog with the
production-shaped English storefront copy below:

```text
php scripts/seed-b2c.php
```

The equivalent application command is `php siro commerce:seed-b2c`. It creates or
updates only the `default` store, `web` channel, `vi` locale, and `VND` catalog.
The `vi` locale is retained for the existing schema scope; names, descriptions,
tags, metadata, and media alt text are English default storefront copy:
four categories, eight published products, variants, retail prices, stock,
product tags, and product media. It creates no customers, orders, companies,
B2B records, vendors, seller orders, or marketplace data. The stable `b2c-`
identifiers and database upserts make reruns safe; existing rows outside those
identifiers are not changed. Run migrations before seeding.

Set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and
`DB_PASSWORD` for the target database. The script intentionally requires MySQL
and does not perform implicit schema migrations.

Use the repeatable demo seeder for a local MySQL test database:

```text
php scripts/seed-demo.php --count=50
php scripts/seed-demo.php --reset --count=50
```

It requires `DB_CONNECTION=mysql` and the backend `DB_*` variables. For the
default local MySQL installation:

```powershell
$env:DB_CONNECTION = "mysql"
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_DATABASE = "sirocommerce_demo"
$env:DB_USERNAME = "root"
$env:DB_PASSWORD = "123123@"
php scripts/seed-demo.php --reset --count=50
```

The script creates no live provider calls. Rows use the `demo-` prefix and
duplicate inserts are ignored, so rerunning the command is safe.

The dataset covers buyers, staff roles, products/product types, variants,
inventory sources/items/adjustments/transfers/ledger, companies, company
catalogs/terms/contracts/approvals/negotiated prices/orders/workflows/credit
reservations, B2B quotes/revisions/transitions/POs/price lists/terms/audit
events, orders, lines, payment states/webhooks, invoices/lines/taxes, credit
memos, finance evidence, fulfillment/packages/shipments/tracking, returns and
postings, carts/reservations, pricing lists/snapshots/currency rates,
coupons/usages/promotion allocations, subscriptions/renewals, CMS
pages/blocks/menus/redirects/SEO, digital assets/downloads, loyalty
ledger, analytics/customer/search events, jobs/outbox/exports/imports,
tax rules, privacy/consents, wishlist items and reviews. On `--reset` the
seeder first best-effort syncs missing tables from `database/migrations`
(skipping already-applied alters) so long-lived demo databases gain newer
tables, then reseeds every `demo-` row idempotently.

Demo credentials use `demo-buyer-N@example.test` with password
`DemoPassword!123`. Do not use these credentials outside local/test data.

## Local ports

Default local ports (`:3000` admin, `:8080` API) collide easily with
zombies from previous runs or other projects. The conflict-free local
setup is backend `:8090` + admin `:3005`:

```powershell
# backend
$env:DB_CONNECTION = "mysql"
# ... (DB_* as above)
$env:JWT_SECRET = "<at least 32 chars>"
$env:CACHE_DRIVER = "file"
$env:APP_ENV = "local"
$env:CORS_ALLOWED_ORIGINS = "http://localhost:3005"
php -S localhost:8090 -t public

# admin (rebuild after changing NEXT_PUBLIC_API_URL)
$env:NEXT_PUBLIC_API_URL = "http://localhost:8090"
npm run build
node ../../node_modules/next/dist/bin/next start --hostname localhost --port 3005
```

Live admin E2E against that setup:

```powershell
$env:PLAYWRIGHT_BASE_URL = "http://localhost:3005"
$env:LIVE_BACKEND = "1"
$env:E2E_API_URL = "http://localhost:8090"
npx playwright test e2e/live-console.spec.ts e2e/live-ops.spec.ts
```
