# Ecosystem Catalog Management Plan (Plugins & Themes, Free + Pro)

Status: ACTIVE. Scope: Siro-native plugins and themes only. This plan turns the
existing package/QIT/license foundation into a manageable catalog with clear
free/Pro boundaries, admin surfaces, and a release/update model.

Implementation update 2026-11-24: P0 catalog, P1 static feed + bundled
install/update + notifications, and P2 admin license portal + Affiliate enable
gate + private Pro feed + premium theme packages are implemented. Marketplace,
billing, customer license delivery, remote Pro artifacts and auto-update remain
out of scope.

Related docs:
- [`contracts/EXTENSION_ECOSYSTEM_SPEC.md`](../contracts/EXTENSION_ECOSYSTEM_SPEC.md)
- [`contracts/THEME_PACKAGE_SPEC.md`](../contracts/THEME_PACKAGE_SPEC.md)
- [`contracts/EXTENSION_PACKAGING_SPEC.md`](../contracts/EXTENSION_PACKAGING_SPEC.md)
- [`product/PRO_EDITION_PLAN.md`](./PRO_EDITION_PLAN.md)
- [`product/ECOSYSTEM_TEAM_PLAN_4DEVS.md`](./ECOSYSTEM_TEAM_PLAN_4DEVS.md)

## 1. Decision

Use **one registry, two channels**:

```text
Catalog (Free + Pro metadata)
        |
        v
Verify -> Install -> Enable -> Configure -> Update -> Disable -> Uninstall
        |
        v
Installed registry (extensions table / storefront theme config)
        |
        v
Entitlement (license for Pro; no license for Free)
```

Free and Pro packages share the same manifest, QIT, install, migration, scope and
uninstall rules. They differ only in distribution, license enforcement and
support policy.

Non-goals for this phase:
- Public marketplace billing, vendor onboarding, payouts, reviews.
- Remote catalog fetching inside the admin request (avoid SSRF and availability
  coupling).
- Selling Pro while the license/update pipeline is not production-ready.
- Running PHP/HTML/JS from themes.

## 2. Current inventory (baseline 2026-11-24)

Free plugins shipped:
- `siro/shipping-fee-rules`
- `siro/free-shipping-banner`
- `siro/product-qa`
- `siro/contact-widget`
- `siro/social-share`
- `siro/size-guide`
- `siro/store-locator`

Pro plugin code present but not GA:
- `siro/affiliate` (catalog edition Pro; license metadata still needs the final
  commercial flip before it is sold as Pro).

Free themes:
- 18 built-in vertical presets.
- 3 portable example packages: `siro/fashion`, `siro/pharmacy`, `siro/fnb`.

Implemented foundation:
- Manifest v2, pack/verify/install/backup, QIT-lite, private Composer discovery.
- Store/channel scope helper and slot runtime.
- Offline RSA-SHA256 license verify/enforce/issue.
- Admin Extensions page (installed lifecycle, package upload, license card).
- Theme Builder with JSON/zip import/export and draft/publish flow.
- Public theme preview gallery.

Missing for catalog management:
- Catalog read model and admin catalog/updates surfaces.
- Theme catalog/updates surfaces.
- Static release feed and update-check contract.
- Commercial license delivery/portal and signed Pro artifact pipeline.
- Marketplace/billing/vendor portal (P3).

## 3. Free vs Pro policy

| Area | Free | Pro |
|---|---|---|
| Discovery | Public catalog metadata | Catalog metadata; download may be license-gated |
| Package | Zip; checksum; signature optional | Zip; signed; license required |
| Install | Admin upload or catalog install | Same, after entitlement check |
| Enable | Migration + boot + scope | Same + license check |
| Update | Public feed; opt-in auto-update | Feed requires valid license and support window |
| Expiry | Not applicable | Soft gate by default; 30-day grace; strict optional |
| Data | Never held hostage | Never held hostage; expiry disables Pro features only |
| Support | Community/docs | SLA by plan |
| Refund | Not applicable | Commercial policy, default 30 days |

Rules:
- Core data is never deleted or locked when a Pro license expires.
- A Pro package must never be labeled "Free" in catalog metadata.
- A Pro package must not be installable/enableable without entitlement once the
  commercial flag is enabled.
- Themes are presentation data only. Paid themes are signed packages, not code.

## 4. Admin information architecture

### Extensions

Tabs on `/extensions`:

| Tab | Purpose |
|---|---|
| Installed | Current lifecycle, health, config, uninstall (existing content) |
| Catalog | Free/Pro metadata, search, install/enable status, link to details |
| Updates | Installed items with newer catalog version, compatibility, changelog |
| Licenses | Edition, SKU, expiry/grace, feature list, offline import (read-only first) |

The existing upload/verify/QIT panel stays in the Installed tab. Destructive
actions keep confirmation and idempotency.

### Themes

New page `/storefront/themes` (or a catalog panel linked from Theme Builder):

| Tab | Purpose |
|---|---|
| Catalog | Free/Pro theme packages, preview, version, import as draft |
| Updates | Installed/active theme version vs latest, changelog |
| Import/Export | Existing builder actions; unchanged |

Theme Builder remains the editor/publisher. Catalog import must create a draft,
never auto-publish.

## 5. Catalog data contract

Source of truth:
1. Installed/runtime state: existing extension discovery + `extensions` table,
   and active storefront theme config.
2. Distribution metadata: checked-in `catalog` file. No network fetch in P1.

Checked-in overlay: `backend/resources/ecosystem/catalog.json`

```json
{
  "schema": "siro.ecosystem-catalog/1",
  "generated_at": "2026-11-24T00:00:00Z",
  "items": [
    {
      "id": "siro/product-qa",
      "type": "plugin",
      "name": "Product Q&A",
      "description": "Customer questions and moderated answers on product pages.",
      "vendor": "Siro",
      "edition": "free",
      "status": "published",
      "latest_version": "1.0.0",
      "min_core": "^1.0",
      "min_commerce": "^0.1",
      "license_required": false,
      "license_sku": null,
      "preview_url": null,
      "changelog_url": null,
      "artifact_url": null,
      "sha256": null,
      "published_at": "2026-11-24",
      "tags": ["pdp", "support"]
    }
  ]
}
```

Validation:
- `schema` must be `siro.ecosystem-catalog/1`.
- `type` is `plugin|theme`; `edition` is `free|pro`; `status` is
  `published|planned|deprecated`.
- `id` follows the package id rules (optional `vendor/` prefix for built-in
  packages such as `flat-rate`); no duplicates.
- `license_required` must be true when `edition=pro`.
- URLs must be `https://` or root-relative; no `javascript:`, `data:`, or
  credentials in URLs.
- `sha256` is 64 hex chars when present; artifact/signature required for Pro
  before status becomes `published`.
- Unknown fields are ignored; invalid items fail catalog load with a clear error.

Merge result returned to admin:

```json
{
  "id": "siro/product-qa",
  "type": "plugin",
  "name": "Product Q&A",
  "description": "...",
  "vendor": "Siro",
  "edition": "free",
  "status": "published",
  "latest_version": "1.0.0",
  "installed_version": "1.0.0",
  "installed_state": "enabled",
  "update_available": false,
  "compatible": true,
  "min_core": "^1.0",
  "min_commerce": "^0.1",
  "license_required": false,
  "license_sku": null,
  "license_status": "not_required",
  "artifact_url": null,
  "sha256": null,
  "preview_url": null,
  "changelog_url": null,
  "tags": ["pdp", "support"]
}
```

`installed_state` is an extensions state or `available` when discovered but not
installed. `license_status` is `not_required|valid|missing|expired|grace`.
`compatible` uses core/commerce version constraints. `update_available` uses
semver comparison only; it does not imply an artifact exists.

## 6. API contract (P1)

All routes are admin/owner only and use the existing extension read capability.

| Method | Path | Response `data` |
|---|---|---|
| GET | `/api/admin/ecosystem/catalog?type=plugin\|theme\|all&edition=free\|pro\|all&q=` | `{schema, generated_at, items: CatalogItem[]}` |
| GET | `/api/admin/ecosystem/updates` | `{items: CatalogItem[]}` (only `update_available=true`) |

Rules:
- No mutation endpoints in P1. Install/update continue through existing
  extension lifecycle or theme import endpoints.
- Filters are allowlisted; invalid values return 422.
- Empty catalog is a valid 200 response with `items: []`.
- A malformed catalog file returns 500 with a safe message and no partial data;
  never leak filesystem paths.
- Never return license secrets, private keys, raw allowlists or file paths.

## 7. Delivery phases

### P0 - Contracts and read-only catalog (1 week)

- Freeze this plan, catalog schema and admin tab contract.
- Add checked-in catalog metadata for current free plugins, Affiliate Pro and
  the 3 theme packages.
- Implement backend catalog merge + updates endpoints with tests.
- Implement Extensions tabs: Catalog, Updates, Licenses; theme catalog page.
- No billing, no remote feed, no auto-update writes.

Exit: admin can see free/Pro items, installed state, compatibility, update
availability and license status; all existing lifecycle actions still work.

### P1 - Distribution and update checks (2-3 weeks)

- Generate catalog from manifests/theme packages in release tooling; verify
  checksums and signatures.
- Add static feed JSON and a signed Pro artifact pipeline.
- Add "install from catalog" for free packages with the existing package service.
- Add update notifications and changelog links.

Exit: a free package can be selected from catalog, installed, enabled and updated
without manual file upload; Pro item correctly reports missing license.

### P2 - Pro licensing and premium themes (3-4 weeks)

- License delivery portal and offline license import UI.
- Flip Affiliate to commercial license metadata and gate its enable path.
- Private Pro feed, renewal/grace UX, update entitlement checks.
- Signed commercial themes and per-store assignment.

Exit: Pro cannot be enabled without a valid license; expiry keeps data and core
running; updates require valid entitlement.

### P3 - Marketplace (after P2 and CI restoration)

- Vendor onboarding, submission/review, billing, revenue share, payouts,
  reviews, SBOM/sandbox, theme marketplace.
- Only start after CI, security review, legal/refund policy and support SLA are
  operational.

## 8. Workstream split

### A - Backend catalog read model

Own:
- `backend/app/Modules/Extension/Application/EcosystemCatalogService.php`
- `backend/app/Controllers/EcosystemCatalogController.php`
- `backend/resources/ecosystem/catalog.json`
- `backend/tests/Feature/EcosystemCatalogTest.php`
- `backend/routes/api.php` (only the two catalog routes)

Deliver:
- Catalog loader/validator, semver update check, compatibility, license status.
- Merge plugin state from `ExtensionDiscovery`/lifecycle and themes from catalog.
- GET catalog + updates endpoints with auth/capability, filters, safe errors.
- Tests: schema, duplicate/invalid entries, filters, compatibility, updates,
  scope isolation, no secrets, 401/403.

### B - Admin Extensions catalog/updates/licenses

Own:
- `apps/admin/src/services/extensions.service.ts`
- `apps/admin/src/app/(dashboard)/extensions/page.tsx`
- `apps/admin/src/__tests__/extensions-catalog.test.tsx`

Deliver:
- Types and service methods for catalog/updates.
- Tabs Installed / Catalog / Updates / Licenses; keep existing lifecycle UI.
- Free/Pro badges, installed state, update/compatibility indicators, license
  summary; no fake install buttons for Pro without entitlement.
- Tests: loading/empty/error, filter, tabs, update badge, license status,
  existing lifecycle actions still work.

### C - Admin Theme catalog

Own:
- `apps/admin/src/services/storefront-theme.service.ts`
- `apps/admin/src/app/(dashboard)/storefront/themes/page.tsx`
- `apps/admin/src/layouts/sidebar.tsx`
- `apps/admin/src/layouts/mobile-sidebar.tsx`
- `apps/admin/src/__tests__/storefront-theme-catalog.test.tsx`

Deliver:
- Theme catalog/updates UI using the catalog API (type=theme).
- Preview link, edition/version/compatibility, import as draft where supported.
- Sidebar entry "Theme catalog"; no auto-publish.
- Tests: empty/error/catalog, Free/Pro badge, import action, sidebar permission.

### D - Release feed, docs and operations

Own:
- `deploy/catalog/**` or `scripts/ecosystem-catalog/**`
- `docs/engineering/ECOSYSTEM_OPERATIONS.md`
- `docs/README.md` links
- `CHANGELOG.md` (only when implementation ships)

Deliver:
- Static catalog feed build/validate script (no secrets, deterministic output).
- Operator runbook: free release, Pro signed release, update/rollback, license
  import/rotation, QIT gate, deprecation/revocation.
- Docs index link and catalog schema example.

## 9. Definition of Done

- [ ] Catalog schema validated; current free/Pro/theme items listed.
- [ ] Admin can distinguish Free vs Pro and see installed/update/license state.
- [ ] No Pro item is presented as installable/enableable without entitlement.
- [ ] Existing extension install/enable/disable/uninstall and theme
      import/export/publish flows unchanged and tested.
- [ ] Backend: targeted + full PHPUnit, PHPStan level max, `extension:check`.
- [ ] Admin: targeted + full Vitest, typecheck, build.
- [ ] No secrets, private keys, filesystem paths or raw license data in API/UI.
- [ ] Docs and CHANGELOG updated; deployment verified after release.

## 10. P1 execution decisions (2026-11-24)

These decisions narrow P1 so it ships without a marketplace or remote download
service:

- **Static public feed**: build a public-safe feed at
  `apps/storefront/public/ecosystem/catalog.json` from the checked-in overlay.
  It contains catalog metadata only (no installed state, license data, secrets or
  filesystem paths). The release pipeline must regenerate and drift-check it.
- **Bundled install/update only**: P1 catalog actions target plugins whose code
  already ships in `app/Extensions`. `POST /api/admin/ecosystem/install` installs
  a discovered free plugin; `POST /api/admin/ecosystem/update` upgrades the
  registry version from the deployed manifest. No remote artifact download yet;
  remote/offline artifacts stay P1.1/P2.
- **Catalog fields**: add `changelog` (plain text, max 1000 characters) to the
  overlay and merged item, plus `installable`, `can_update` and `action_reason`.
  Pro items are never installable/updateable in P1 without entitlement.
- **Notifications**: Extensions shows an update-count notice and changelog for
  available updates; theme catalog shows changelog too.
- **No auto-update/auto-enable**: install leaves the plugin installed; the
  operator enables it explicitly. Update never runs migrations silently.
- **No Pro sales**: Affiliate remains `planned` until P2 license/artifact gates
  are complete.

## 11. P2 execution decisions (2026-11-24)

- **License portal is admin-only**: import/remove a signed license document.
  Store it under `storage/licenses/active.json` (0600, outside public) when
  `EXTENSION_LICENSE_FILE` is not set; env path remains the override. No customer
  account portal, billing or self-service purchase.
- **Affiliate commercial flip**: set `license.required = true` with SKU
  `siro-affiliate`; enable is denied without a valid Pro/Enterprise license that
  lists the extension. Community install may remain possible; enable is gated.
- **Private Pro feed** is an authenticated admin endpoint gated by a valid or
  in-grace license. It returns Pro metadata/artifacts only to entitled
  deployments; missing license returns 403. It is not a public marketplace.
- **Premium themes**: add Siro-native theme packages marked `edition=pro` and
  `status=planned` until signed artifacts and license delivery exist. Admin shows
  Pro/license gating; no download URL is fabricated.
- **Still out of scope**: marketplace billing, vendor onboarding, payouts,
  reviews, refund automation, CI restoration.

## 12. Metrics and governance

Track: catalog views, install/enable conversion, update adoption, health
failures, uninstall rate, Free-to-Pro conversion, license expiry/grace count,
refund rate, time-to-publish a package, QIT pass rate.

Governance:
- QIT is mandatory for every package.
- Version immutability and signed Pro artifacts.
- Quarterly compatibility review and EOL/deprecation policy.
- Security disclosure and support contact per catalog item.
- CI must be restored before paid marketplace launch.
