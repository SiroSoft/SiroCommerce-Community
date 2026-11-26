# SiroCommerce Community

MIT-licensed SiroCommerce source for the free/core edition.

**Status: repository created, source migration in progress.** This repository is
the intended public home for the Community edition. It is not yet a complete
release; do not deploy from this repository until the migration commit lands.

## Scope

Planned contents:

- `backend/` SiroPHP commerce API.
- `apps/storefront/` Next.js storefront.
- `apps/admin/` owner/admin console.
- `packages/` shared API-client, auth, scope, config and UI packages.
- Free first-party plugins (shipping rules, banners, Q&A, contact, social share,
  size guide, store locator).
- Free theme packages (Fashion, Pharmacy, F&B) and the 18 built-in presets.
- Free docs and operations runbooks.

## Not in this repository

- Affiliate Pro.
- Premium themes (`Atelier Noir`, `Apothecary Calm`).
- Future Pro/Enterprise modules.
- Private signing keys, license private keys, production `.env` files or
  deployment credentials.
- Customer license delivery/billing portal.

Pro source lives in the private `SiroSoft/SiroCommerce-Pro` repository. Pro
metadata may appear in the public catalog feed, but paid source and signed
artifacts do not.

## License

MIT. See [`LICENSE`](LICENSE). Third-party dependencies keep their own licenses.

## Migration rules

The current private `SiroSoft/SiroCommerce` repository is the migration source.
Do not flip it public: its history contains Pro source. The Community repository
must be built from a clean export with:

1. no Pro extension source or premium theme source;
2. no `.env`, private keys, signed license documents or storage data;
3. no secrets in git history;
4. free-only tests adjusted and passing;
5. a single clean initial commit or a vetted filtered history.

## Links

- Product site: https://ecommerce.sirophp.com
- Public catalog feed: https://ecommerce.sirophp.com/ecosystem/catalog.json
