# SiroCommerce Community

MIT-licensed SiroCommerce source for the free/core edition.

**Status: v0.1.1 Community source release.** Generated from the private internal
monorepo with Pro source removed. Free-only backend, storefront and admin tests
pass; see `SECURITY.md` and `CONTRIBUTING.md` before reporting or contributing.

## Scope

- `backend/` SiroPHP commerce API.
- `apps/storefront/` Next.js storefront.
- `apps/admin/` owner/admin console.
- `apps/marketing/` static marketing site.
- `packages/` shared API-client, auth, scope, config and UI packages.
- Free first-party plugins: shipping fee rules, free-shipping banner, product
  Q&A, contact widget, social share, size guide, store locator.
- Free themes: Fashion, Pharmacy, F&B; 18 built-in vertical presets.
- Ecosystem catalog, theme package pipeline, extension packaging/QIT, scope and
  license verification foundation.
- Free docs and operations runbooks.

## Not in this repository

- Affiliate Pro source (private `SiroSoft/SiroCommerce-Pro`).
- Premium themes `Atelier Noir` and `Apothecary Calm` source (private Pro repo).
- Future Pro/Enterprise modules.
- Private signing keys, license private keys, production `.env`, signed license
  files, customer data or storage data.
- Customer license purchase/delivery portal and marketplace billing.

The public catalog feed may list Pro metadata and preview images, but paid source
and signed artifacts live only in the private Pro repository.

## Requirements

- PHP 8.2+
- Node.js 20+
- Composer
- MySQL 8 production / SQLite for local tests

Backend dependencies are not committed:

```bash
cd backend
composer install
cp .env.example .env   # if present; otherwise configure DB_* manually
php siro migrate
php vendor/bin/phpunit --no-coverage
```

Frontend:

```bash
npm ci
npm run typecheck
npm test
npm run build:admin
```

## License

MIT. See [`LICENSE`](LICENSE). Third-party dependencies keep their own licenses.

## Links

- Product site: https://ecommerce.sirophp.com
- Public catalog feed: https://ecommerce.sirophp.com/ecosystem/catalog.json
- Pro source: private `SiroSoft/SiroCommerce-Pro`
