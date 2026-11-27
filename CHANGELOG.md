# Changelog

All notable changes to this project are documented here. Dates are UTC.

## [2026-11-27] v0.3.2 Next.js security upgrade

### Security
- Upgraded Next.js **16.3.5 → 16.4.6** in admin, storefront and marketing,
  removing the critical/high advisories fixed in 16.4.6 (image optimization
  RCE, `use cache` RCE, SSRF and DoS issues).
- `npm audit --omit=dev` no longer reports a critical vulnerability. Remaining
  high/moderate findings in zod/source-map/postcss are pre-existing and tracked
  separately; they are not introduced by this patch.

### Verification
- Admin 614 tests; storefront 653 tests; typecheck and production builds pass for
  admin, storefront and marketing.
- Backend suite and PHPStan re-run after the version bump.
- No API/catalog behavior change; catalog remains 21 items.

## [2026-11-27] v0.3.1 plugin batch 1 and 21-item catalog

### Added
- Plugin batch 1: Sticky Add to Cart, Product Labels, Email Capture Popup,
  Product Photo Reviews, and Abandoned Cart Recovery (free + Pro), six packages
  across five plugins.
- Extension manifest dependency guard (`requires_extensions`), storefront slot
  declarations, explicit `public` route opt-in, product review media, consent
  and coupon integration, and the mail template registry.
- Catalog now has 21 items: 16 plugins and 5 themes; 4 Pro (Affiliate, both
  premium themes, and planned/private Abandoned Cart Pro).
- Abandoned Cart Pro is private to the Pro mirror, requires
  `siro/abandoned-cart`, and uses SKU `siro-abandoned-cart-pro`; it is not
  published until a signed artifact exists.

### Fixed
- Community mirror excludes Abandoned Cart Pro source and its Pro-only test;
  Pro mirror carries the ten source files.
- OpenAPI generator maps the `/api/consent/marketing` request body, restoring
  API contract checks.

### Verification
- Backend 1,928 PHPUnit tests / 17,435 assertions, 1 skipped; PHPStan 0.
- Admin 614 tests; storefront 653 tests; Community backend 1,895 / 16,742,
  2 skipped; Community admin 612.
- Catalog 21 items; build/public drift and selftest pass; mirrors dry-run
  Community 2,883 / Pro 32 with no Pro source in Community.
- Extension check 16/16; pack → verify → QIT 6/6 for six packages.
- API contract PASS (469 operations / 406 paths); handoff 469 routes / 2,345
  layer records, readiness `NOT_READY_BLOCKED`.

## [2026-11-26] v0.3.0 signed Pro themes and theme artifact feed

### Added
- Signed Pro theme artifact builder (`package-themes.php`) for `siro/atelier-noir`
  and `siro/apothecary-calm`: theme.json, assets, checksums and Ed25519 signature.
- Both premium themes published in the catalog with artifact URL, SHA-256 and
  signature; public feed still strips artifact/signature metadata.
- Admin Pro feed can download theme artifacts through the license-gated private
  download token; theme rows no longer expose plugin install/update actions.
- OpenAPI/SDK/handoff regenerated for 468 operations / 405 paths; API contract
  now passes.

### Fixed
- Isolated loyalty idempotency keys in tests to remove a cross-test flake.
- Forced LF for shell/deploy files so Windows archives do not break `release.sh`.

### Verification
- Backend 1,787 PHPUnit tests with sodium, 1 skipped; PHPStan 0.
- Admin 614 tests; typecheck/build pass.
- Catalog 15 items; build/public drift and selftest pass.
- API contract PASS; handoff 468 routes / 2,340 layer records.

## [2026-11-26] v0.2.1 signed Affiliate Pro artifact published

### Changed
- Published `siro/affiliate` 1.0.0 with a signed Pro artifact, SHA-256 and
  detached Ed25519 signature. Premium themes remain planned.
- Commerce version 0.2.1.

### Verification
- Signed artifact verified with `--require-signature`.
- Catalog 15 items; build/public drift and selftest pass.
- Backend full suite and admin suite pass; Pro feed/license gates unchanged.

## [2026-11-26] v0.2.0 Pro theme, artifact, license portal and catalog gates

### Added
- Pro theme licensing: `license.required/sku` in `siro.theme/1`, server-side
  import/publish gate, checksums and Ed25519 signed theme archives.
- Pro artifact pipeline: signed package build, private feed download tokens,
  checksum/signature verification, install/update with backup and no auto-enable.
- License delivery portal extension (`siro/license-portal`): scoped deliveries,
  one-time customer download links, revoke/rotate and `order.paid` foundation.
- Catalog Pro publish gate: `signature`/`signature_url`, `publish-pro.php`,
  public-feed stripping and planned→published validation.
- Affiliate store/channel scope migration, scoped service queries and QIT
  `store-scope` pass.

### Changed
- Commerce version 0.2.0.
- Catalog now has 15 items: 10 plugins (including License Portal), 5 themes;
  Pro items remain planned until real signed artifacts exist.

### Verification
- Backend 1,784 PHPUnit tests with sodium, 1 skipped; PHPStan 0.
- Admin 611 tests, typecheck/build pass.
- Catalog 15 items, draft/check and selftest pass.
- Affiliate and License Portal QIT pass store-scope/route-auth/secret/php-lint.

## [2026-11-26] v0.1.1 Community docs and private Pro documentation

### Changed
- Pro pricing/license documentation is private from v0.1.1. Community keeps only
  public stubs for `PRO_EDITION_PLAN.md` and `EXTENSION_LICENSE_ISSUANCE.md`.
- Added `SECURITY.md`, `CONTRIBUTING.md`, team onboarding reading list, release
  model and self-hosted CI guidance.
- Added `LAUNCH_READINESS_SPEC.md` to the documentation index.
- Corrected reading-list statuses: `THEME_PACKAGE_SPEC` is **P1**;
  `ECOSYSTEM_CATALOG_MANAGEMENT_PLAN` is **ACTIVE**.
- Removed the nonexistent `docs/admin/affiliate/README.md` link; Pro Affiliate
  admin is documented in the private Pro repository (`admin/affiliate/README.md`).

### Fixed
- CI workflow no longer has the duplicate `if` key in `e2e-visual-linux`.

## [2026-11-24] Free plugin batch, ecosystem catalog management

### Added
- Ecosystem catalog read model (`siro.ecosystem-catalog/1`), checked-in overlay
  for 9 plugins and 5 theme packages (3 Pro/planned), and admin APIs
  `GET /api/admin/ecosystem/catalog` + `/updates` + `/pro-feed`.
- Admin Extensions tabs Installed/Catalog/Updates/Licenses with Free/Pro,
  installed state, compatibility, update availability, changelog and license
  status; bundled free-plugin install/update actions with update notice.
- Admin Theme catalog at `/storefront/themes` with Free/Pro filters, updates,
  changelog, preview links, premium-theme gating and Theme Builder handoff.
- Offline catalog feed build/validate tool (`scripts/ecosystem-catalog/`) with
  deterministic output, schema/URL/checksum/duplicate checks, public-feed
  projection/drift check and self-tests. Static public feed is served at
  `/ecosystem/catalog.json`.
- Admin license portal: import/remove a signed license envelope, stored at
  `storage/licenses/active.json` (0600) with `EXTENSION_LICENSE_FILE` override.
- Affiliate commercial flip: enable requires a valid Pro/Enterprise license
  listing `siro/affiliate`. Private Pro feed returns Pro metadata only to
  entitled deployments.
- Premium theme packages `siro/atelier-noir` and `siro/apothecary-calm` with
  complete local assets (logo, hero, banners), unique preview images and a
  pharmacy banner-grid section. Still Pro/planned until signed artifacts and
  license delivery exist.
- Theme diversity pass: 18 presets now use five layout archetypes (editorial,
  luxury, clinical, technical, lifestyle) with distinct section recipes; the
  preview gallery labels each archetype. Premium themes no longer clone the
  source theme's header/footer/presentation/section order.
- Theme preview product media is consistent across product cards, campaign hero
  and the PDP gallery. Published stores keep their real catalog images; only
  preview/demo uses the theme product image set.
- `docs/product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md` and
  `docs/engineering/ECOSYSTEM_OPERATIONS.md` (free/Pro release, license and
  rollback runbook).
- `siro/free-shipping-banner`: store/channel scoped cart banner with a public
  threshold endpoint and cart slot.
- `siro/product-qa`: PDP questions with moderation, answer flow, public submit
  and approved-list endpoints, scoped by store/channel.
- `siro/contact-widget`: footer Zalo/WhatsApp/email links from scoped settings.
- `siro/social-share`: PDP share bar for Facebook/X/WhatsApp/Telegram/email/copy
  with scoped network settings.
- `siro/size-guide`: per-category size charts managed in admin and rendered as an
  accessible PDP dialog.
- `siro/store-locator`: store/channel location CRUD and a footer store dialog
  with optional Google Maps links.

### Fixed
- Extension route mounting now tracks mounted routes in a `WeakMap` per router
  instance, preventing skipped routes after PHP reuses `spl_object_id` values.
- Product Q&A migration uses short index names (MySQL 64-character limit).
- Slot bootstrap registers all six free plugins; PDP slot data includes the
  product category for size guides.

### Verification
- Backend: 1,731 PHPUnit tests (1 skipped), PHPStan level max 0 errors.
- Storefront: 599 tests, typecheck/build pass.
- Admin: 608 tests, typecheck/build/lint pass.
- Catalog feed: `build.php --check` valid (14 items); public feed drift check and
  self-tests pass.
- `extension:check` and QIT pass for all free plugins.

## [2026-11-22] Free ecosystem foundation, theme packages, payout reconciliation

### Added
- Native Siro theme packages (`siro.theme/1`):
  JSON + `.zip` import with local assets (`png/jpg/webp/avif/woff/woff2/ttf/otf`),
  checksum/size/path checks, storage rewrite to public URLs, JSON/zip export,
  and Admin Theme Builder import/export controls.
- First-party theme packages with real assets:
  `examples/themes/fashion`, `pharmacy`, `fnb` (logo/hero/banner, no SVG/JS).
- Extension ecosystem P0/P1:
  `siro.extension/2` manifest, pack/verify/install/backup, checksums and optional
  Ed25519 signature, QIT-lite (`extension:qit`), admin package verify/install/QIT,
  private Composer discovery (`extra.siro.extension`).
- License/entitlement: offline RSA-SHA256 license verify, grace period,
  enforcement on `enable()` for `license.required`, issuance CLI
  `extension:license:issue`, docs for key custody/rotation.
- Extension scope helper (`ExtensionScope`) and scoping checklist; QIT blocks
  unscoped `ext_*` tables and unauthenticated mutations.
- Storefront extension slots (`header/footer/pdp/cart/checkout/my-account/email/
  order-status`) with runtime registration, isolated error boundaries, SSR-safe
  registry; wired into layout/cart/checkout/PDP/account/order-result.
- `make:extension` scaffolds admin/storefront slot declarations and README.
- Shipping fee rules free plugin (`siro/shipping-fee-rules`): store/channel
  scoped rules, admin panels, and a core `ShippingFeeAdjuster` registry applied
  to cart/checkout quotes (pickup stays zero).
- Vendor payout settlement: bank CSV reconciliation, `failed`/`returned` states,
  provider reference/reason, ledger entries released on failure/return.
- Per-store `purchase.quote_mode` (`required|auto|off`) with Admin control,
  checkout fallback, and `/quote` redirect when disabled.
- 18 vertical theme presets (fashion, beauty, pharmacy, F&B, electronics, B2B,
  grocery, cosmetics, footwear, jewelry, furniture, sports, pets, baby, books,
  flowers, supplements, auto parts).
- Static multilingual marketing app (`en/vi/de/ja/zh`, SEO/GEO/AIO, `llms.txt`,
  Cloudflare Pages config) committed separately to
  `thiennhant95/sirocommerce-landingpage`.
- Public theme demo: `/preview` gallery, cookie-scoped preview of the 18
  presets, noindex banner, checkout redirect, and an allowlisted public preset
  endpoint; published config is never changed.
- AI-generated product/lifestyle photography for the three example theme
  packages (pharmacy/fashion/F&B), optimized under 300 KB each; SenseNova API key
  is not stored in the repository.

### Fixed
- Extension installer now maps hyphenated ids to PHP namespaces
  (`shipping-fee-rules` → `ShippingFeeRules`).
- `php siro extension:check` validates manifests without requiring a database.
- Cart quote now passes store/channel scope and `shipping_method` to the fee
  registry, so extension fees apply to API quotes as well as checkout.
- Theme archive importer rejects SVG/PHP/JS/HTML, traversal, MIME mismatches and
  oversized assets; exporter bundles only local `/storage` assets.
- Payout reconciliation is state-safe and idempotent (paid/failed/returned).

### Verification
- Backend: 1,641 PHPUnit tests, PHPStan level max 0 errors.
- Storefront: 598 tests, typecheck/lint/build pass.
- Admin: 574 tests, typecheck/build pass.
- Deploy: `20261122170000` (backend/storefront/admin) healthy;
  `/health/ready`, `/cart`, `/checkout`, `/account` return 200.
- Earlier releases in this cycle: `20261016140000` (backend presets),
  `20261016150000` (18 presets), `20261016161000` (quote mode),
  `20261122090000`/`20261122110000` (theme packages), `20261122130000`
  (4-agent batch), `20261122140000` (license admin), `20261122150000`
  (slots/license/shipping integration), `20261122160000` (private Composer).

### Docs
- `docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md`, `THEME_PACKAGE_SPEC.md`,
  `EXTENSION_SCOPING.md`, `EXTENSION_UPDATES.md`,
  `EXTENSION_LICENSE_ISSUANCE.md`, `docs/product/ECOSYSTEM_TEAM_PLAN_4DEVS.md`,
  marketing README at `apps/marketing/README.md`.

### Known limits
- Provider credentials/certification (VNPay, MoMo, GHN, Viettel Post, Stripe,
  PayPal, DHL) still require real merchant credentials and sandbox/live proof.
- GitHub Actions is disabled (minutes exhausted); workflows exist but do not run.
- HA/scale is single-node; 200 RPS at c=50 is not proven.
- Affiliate remains a Pro plugin and is blocked by missing scope/storefront/refund
  work until Pro packaging ships.
- Marketplace/billing, vendor portal, update feed, sandbox/SBOM and theme
  marketplace are P2 and not shipped.

## [2026-10-05] CI/CD automation (paused)

- GitHub Actions workflows exist for backend/storefront/admin quality and deploy.
- Automation is currently **disabled** because GitHub Actions minutes are
  exhausted; releases in this cycle were executed manually with the same
  `deploy/release.sh` flow. Do not claim CI-gated pushes until re-enabled.
- Docs: `docs/operations/CI_CD_RUNBOOK.md` (secrets, first run, manual fallback,
  rollback). An `extension-qit.yml` workflow was added on 2026-11-22 but is also
  inactive while Actions is disabled.

### Storefront UX (planned)
- Carousel previous/next controls restyled to the control scale.

### Product roadmap
- Booking product type (date/time slots, per-slot capacity, slot-aware cart lines).

## [2026-10-04] Admin workspaces completed with live data

### Round 2 audit fixes
- Product detail page now manages media gallery (add/reorder/delete) and
  variants (list/create); removed stale "unavailable" notes from the product
  form and the order timeline note.

### Added
- Catalog: fixed dead tag sorting, live-only inventory (adjust/transfer with
  guards), validated campaign automation limits.
- Orders & fulfillment: order timeline/refund/return/download panels, full
  fulfillment workspace (allocate, track, returns lifecycle, shipments),
  operations job retry + payment reconcile, action-center rescue execution,
  workbench approvals/exports, commerce vendors/audit/jobs/reservations.
- Platform: real users CRUD, reporting exports with polling/retry/download,
  finance invoice workspace, B2B price lists/terms/vendors/commissions/
  payouts, subscription states, working auth flow.

### Evidence
- Admin: 190 tests passing. All 21 admin routes return 200.

## [2026-10-04] Cart UX, account completion, and auth reliability

### Added
- Real cart lines: product imagery, per-line quantity steppers, and line
  removal with optimistic updates and revert on failure.
- `PATCH`/`DELETE /api/carts/{id}/lines/{line}` with ownership checks.
- Add-to-bag confirmation notice, pending state on add buttons, live header
  bag badge (cookie + event, bump animation), styled pagination controls.
- My Account: real wishlist, reviews, loyalty, and returns sections backed by
  the account APIs; legacy fixture panels removed from the account page.
- Session persistence: refresh tokens stored at login/register, automatic
  session restore on reload/navigation, account panels refetch on restore.
- Customer profile auto-provisioned at registration and on first account use.

### Fixed
- Sign-in never survived a navigation (memory-only tokens, no restore).
- Any load failure signed the customer out; only 401 does now.
- `GET /api/carts/{id}` 500 with lines (missing model import).
- New accounts got 404 on every account endpoint (no customer row).

### Evidence
- Backend: 1189 tests passing. Storefront: 265 tests passing.
- Live signed-in journey verified: register, wishlist, account sections.

### Added
- Seven purchasable product types in the English sample catalog (USD + VND scopes):
  `simple`, `configurable` (Size/Color, 6 variants, option surcharges),
  `bundle` (Morning Ritual Gift Set), `grouped` (Linen Starter Set),
  `downloadable` (Home Brew Guide eBook + download limit),
  `virtual` (Gift Wrapping Service), plus `backorder` and `out_of_stock` states.
- Product detail gallery: 3 frames per product with thumbnails and a live
  frame counter, translated into 5 locales.
- Volume pricing tiers with per-tier pills and automatic tier pricing.
- Product detail API now publishes `options`, `variant_options`,
  `quantity_pricing`, `option_pricing`, `bundle_components`, `grouped_items`,
  and `delivery`; parent products advertise the cheapest variant price.
- `GET /api/carts/{id}` for server-rendered bag reads with ownership checks.
- `/health/providers` rollout gate; `/health/ready` reports service readiness
  plus `release_ready`; health probes are never throttled.
- Backup/restore rehearsal names the failing migration and replays
  up -> down -> up; ten migration rollbacks made replay-safe.

### Fixed
- `RequestCoordinator` invoked the global fetch with a foreign receiver, which
  Chrome rejected as an illegal invocation and silently broke every
  browser-side API call.
- `products` table had no primary key, letting a slug reshuffle duplicate
  product ids; the table now has one and the seeder wipes-then-rebuilds.
- `Arr::int()` dropped boolean-cast columns; `allow_backorder` always read
  false. Booleans now read as 0/1.
- Storefront read throttle raised (600 to 3000/min, env-overridable);
  verified clean with k6 at 50 VUs (2842 requests, 0 errors, p95 761 ms).
- Header navigation localized per UI locale (en/vi/ja/de/zh) with real
  category destinations; header overlap fixed at 320-1600 px.
- Cart is POST-only: no price, name, or currency travels in the URL.

### Evidence
- Backend: 1186 tests passing. Storefront: 232 tests passing.
- Live e2e (desktop + mobile-320): passing.
- k6 storefront load test: 100% checks at 50 VUs, 0% errors.

## [2026-10-03] Service readiness contract

- Split service readiness (`/health/ready`) from the provider release gate
  (`/health/providers`, `release_ready` flag). Providers stay blocking for
  release: Stripe and DHL production credentials are still required and are
  not fabricated here.

## [2026-10-02] POST-only bag and cart redesign

- `POST /cart/add` route handler; price, name, and currency are resolved
  server-side from the variant id; bag state lives in httpOnly cookies.
- One primary CTA per step; itemized cart lines with subtotals; no duplicate
  headings; no `default · default` leakage.
