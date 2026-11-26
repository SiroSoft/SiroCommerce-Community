# Extension & Theme Ecosystem Spec (Siro-native packages)

Status: P0 + P1 foundation implemented (packages, QIT, license verification/
enforcement/issuance, scope helper, storefront slots, private Composer). P2
marketplace/billing, update feed, vendor portal and sandbox/SBOM remain PLANNED.
Bagisto/Woo are reference-only and out of the active roadmap.

## 0. Decision: Siro-native ecosystem only

Scope decision: plugins and themes are built **for SiroCommerce only**. Bagisto
and WooCommerce are reference models for UX/QA, not runtime targets. The
Bagisto analyzer/commands remain an optional offline migration tool, outside the
active roadmap. Do not market third-party Bagisto/Woo compatibility.

## 0.1 Compatibility boundary (reference only)

Bagisto extensions and themes are **Laravel/Blade packages**; SiroCommerce runs a
SiroPHP backend and a Next.js storefront. They cannot execute inside Siro without
a Laravel runtime.

Therefore compatibility is defined as:

1. **Packaging-compatible**: Siro understands Bagisto package metadata and can
   produce a Siro package/report from it.
2. **UX-compatible**: install, enable, update, license, marketplace and admin
   navigation follow the same merchant mental model as Bagisto/Woo.
3. **Runtime-ported**: PHP providers, Eloquent models, Blade views, DataGrids and
   Laravel service-container bindings must be ported to Siro contracts or
   replaced by an adapter. The importer never pretends otherwise.

What we do **not** promise: running a purchased Bagisto extension binary
unchanged, or rendering Bagisto Blade themes unchanged. Marketing must not claim
"install Bagisto plugins directly".

## 1. Goals / non-goals

Goals:
- Vendor can package once (`.zip`), sign it, sell it, and have a merchant install,
  enable, update and uninstall it without editing core or redeploying by hand.
- License and update entitlement work offline; support/update access is gated,
  data is never held hostage.
- Bagisto extension/theme owners get a deterministic compatibility report and a
  generated Siro manifest skeleton, so migration effort is scoped.
- Multi-store/channel isolation is enforced for extension data by default.

Non-goals (P0):
- Running Laravel packages, Blade templates, Eloquent models or DataGrids.
- A public marketplace with payouts and vendor onboarding (P1).
- Arbitrary storefront JavaScript injection from extensions (never).
- Sandboxing PHP extensions in a separate process (P2; P0 relies on review,
  signatures and capability gates).

## 2. Package types

| Type | Extension | Example |
|---|---|---|
| `module` | Backend feature with routes/migrations/admin panels | Affiliate, loyalty, ERP connector |
| `integration` | Module that registers a payment/shipping/ERP provider | MoMo, GHN |
| `theme` | Tokens, assets, homepage/theme config and storefront section mapping | Fashion theme |
| `bridge` | Thin adapter that maps an external package/API into Siro contracts | Bagisto bridge |

One package may vendor a theme plus a module, but each package id is one bounded
context. Theme packages never execute PHP.

## 3. Package format (`.zip`)

```text
manifest.json               # schema siro.extension/2
checksums.json              # { "relative/path": "sha256", ... }
signature.json              # optional; required by marketplace/paid
Extension.php               # module/integration entry (implements ExtensionInterface)
migrations/                 # ext_* tables only
routes.php                  # relative routes, mounted under /api/ext/<id>
src/                        # PSR-4 classes, namespace App\Extensions\<Vendor>\<Name>
assets/                     # theme images/fonts/CSS; copied to storage, never executed
theme/                      # theme config draft (tokens, sections, layout)
README.md                   # config keys, license, uninstall/data retention
LICENSE                     # vendor license (proprietary or OSS)
sbom.json                   # optional; required for paid marketplace
```

Rules:
- Max archive 20 MB, max 5,000 entries, no symlinks, no absolute paths, no `..`,
  no `__MACOSX`, no dotfiles outside the allowlist.
- `id` matches `^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$`.
- Target directory is derived from id: `app/Extensions/<Vendor>/<Name>/`.
- Checksums cover every file except `checksums.json` and `signature.json`.
- Signature payload: `sha256(manifest.json bytes)` + `"\n"` +
  `sha256(checksums.json bytes)`, Ed25519 detached signature, base64 in
  `signature.json`.
- Public keys are configured out of band:
  `EXTENSION_SIGNING_PUBLIC_KEYS={"key-1":"base64..."}`. Private keys never leave
  the vendor's CI. `EXTENSION_REQUIRE_SIGNATURE=1` is mandatory for paid packages.

## 4. Manifest v2

Existing `Extension.php::manifest()` stays valid (backward compatible). A
package-level `manifest.json` adds distribution metadata:

```json
{
  "schema": "siro.extension/2",
  "id": "siro/affiliate",
  "type": "module",
  "name": "Affiliate",
  "description": "Referral attribution, commission ledger and payouts.",
  "version": "1.0.0",
  "requires": { "core": "^1.0", "commerce": "^0.1" },
  "runtime": { "php": ">=8.2" },
  "provider": "App\\Extensions\\Siro\\Affiliate\\Extension",
  "capabilities": ["affiliate:track", "affiliate:manage"],
  "permissions": ["affiliate:manage"],
  "migrations": ["migrations/20261007000001_create_ext_affiliate_tables.php"],
  "routes": ["routes.php"],
  "slots": {
    "admin": { "label": "Affiliates", "section": "Growth" },
    "storefront": ["my-account.referrals", "checkout.attribution"]
  },
  "config": { "commission_rate": 0.05 },
  "license": { "required": true, "sku": "siro-affiliate", "features": ["affiliate"] },
  "bagisto": {
    "compatible": "port-required",
    "source": "webkul/affiliate",
    "kind": "extension"
  }
}
```

Validation:
- `schema`, `id`, `type`, `version`, `requires`, `provider` required.
- Unknown slot names, capabilities or permission formats are rejected.
- `requires` is checked at verify/install/enable/upgrade; mismatch never
  half-installs.
- Data tables must be prefixed `ext_` and contain `store_id` + `channel_id`
  unless the manifest declares `"global": true` and the reviewer approves it.

## 5. Install / update / remove

Vendor:
```bash
php siro extension:pack app/Extensions/Siro/Affiliate --out=dist --key=key-1
```

Merchant:
```bash
php siro extension:verify dist/siro-affiliate-1.0.0.siro.zip
php siro extension:install dist/siro-affiliate-1.0.0.siro.zip
php siro migrate
# Admin -> Extensions -> siro/affiliate -> Enable
```

Rules:
- Verify first: manifest, checksums, signature, path safety, id/version,
  compatibility. No state changes.
- Install is atomic: extract to a temp dir, validate, back up an existing version,
  then move into place. Failure leaves the previous version untouched.
- `--force` is required to replace an existing directory; backups go to
  `storage/extensions-backups/`.
- Install never auto-enables. Enable runs migrations, boots the provider and flips
  state; failure marks `failed` and keeps core serving.
- Update: verify → install new version → `php siro migrate`; rollback restores the
  backup directory and DB backup taken before migration.
- Uninstall keeps data unless `removeData=true`; that path rolls back `ext_*`
  migrations and removes config keys.
- Admin upload/update UI is P1 but must call the same application service. Never
  duplicate install logic in a controller.

## 6. License / entitlement

- Offline signed JSON envelope ({payload, signature}) with RSA-SHA256 in the current implementation; Ed25519/JWT remains a future option. Claims:
  `license_id`, `customer_id`, `edition`, `extensions[]`, `features[]`,
  `limits`, `issued_at`, `not_before`, `expires_at`, `kid`.
- License is per deployment and per extension SKU. Verification is offline; no
  phone-home required.
- Soft gate (Woo model): the module keeps running after expiry, but updates and
  marketplace support require a valid license. A hard gate is available per
  vendor via manifest `license.enforcement = "strict"`; even then core data is
  never deleted.
- Grace period: 30 days. Expired + grace over → update endpoint refuses; admin
  shows the reason.
- No secret, private key or raw allowlist in API/logs/admin. Only
  `license_id`, `customer_id`, `edition`, `kid`, status.

## 7. Marketplace and QA gate (P1)

Merchant UX mirrors Bagisto/Woo: catalog → product page → install/update →
licenses → support.

Vendor submission:
1. Upload `.zip`, product metadata, docs, support policy.
2. Automated **Siro QIT-lite**:
   - manifest/schema/version/compatibility
   - activation: install → enable → migrate → smoke routes → disable
   - auth matrix: 401 without auth, 403 without capability
   - idempotency on mutations
   - migration up/down on a disposable DB
   - static analysis (PHPStan), coding standard, secret scan, dependency audit,
     malware/file-type scan
   - scope check: `ext_*` tables have `store_id`/`channel_id`
   - accessibility/check for any admin panel assets
3. Human review: code, UX, data handling, support, license terms.
4. Publish; versions immutable, signed, with changelog and compatibility range.

Commercial defaults: revenue share, 30-day refund, support SLA, at least
quarterly compatibility updates, security fix SLA. Do not copy vendor-specific
terms; publish our own.

## 8. Data scope (mandatory)

Every extension table starts with `ext_` and includes:
```sql
store_id VARCHAR(26) NOT NULL,
channel_id VARCHAR(26) NOT NULL
```
- Writes use the resolved `CommerceScope`; reads always filter by scope.
- Cross-store reads are forbidden unless the extension declares a reviewed
  `global` capability.
- Admin panels expose only the active scope; exports include scope columns.
- The reference Affiliate extension is being migrated to this rule.
- `extension:check` (and QIT-lite) fails a manifest whose tables omit scope.

## 9. Slots and UI

Admin:
- Declarative panels only: `stats`, `table`, `form`, `custom` (allowlisted
  endpoint, no arbitrary HTML/JS). No top-level menus for modules.
- Theme/branding packages may declare admin tokens but not execute JS.

Storefront:
- Named slots, versioned: `header`, `footer`, `pdp`, `cart`, `checkout`,
  `my-account`, `email`, `order-status`.
- Extension renders through host components or a signed theme section; no raw
  script injection.
- Theme packages map Bagisto views to Siro sections/components; unsupported
  Blade is reported, not silently dropped.

## 10. Dependencies

- Preferred: vendor publishes a Composer package and the merchant installs from a
  private repository; the zip path remains for offline/self-host customers.
- A package may declare `composer` requirements; the installer verifies they are
  already installed or instructs the operator. It never runs `composer` itself.
- Vendor dependencies must be locked, included in `sbom.json`, and free of known
  critical vulnerabilities.
- No package edits core, `vendor/`, or another extension's files.
- Public API boundary: only documented Siro contracts/events/hooks; internal
  namespaces are unsupported and may break.

## 11. Bagisto compatibility (optional migration aid)

### 11.1 Extension report

`php siro extension:compat <package-dir> --json` reads the package without
executing it and returns:

```json
{
  "source": { "name": "webkul/affiliate", "version": "1.0.0", "kind": "extension" },
  "level": "port-required",
  "score": 35,
  "supported": ["admin menu metadata", "ACL keys", "system config", "payment capability"],
  "port_required": ["Laravel service provider", "Eloquent models", "controllers", "routes"],
  "unsupported": ["Blade admin views", "DataGrid", "Concord models"],
  "migrations": { "tables": ["affiliate_referrers"], "action": "rename ext_* + scope columns" },
  "siro_manifest": { "id": "webkul/affiliate", "type": "module", "version": "0.1.0" }
}
```

Mapping:
| Bagisto | Siro |
|---|---|
| `composer.json` name/version/requires | manifest id/version/requires + report |
| `src/Config/menu.php` / `admin-menu.php` | admin label/section/panels |
| `src/Config/acl.php` | manifest permissions |
| `src/Config/system.php` | namespaced config keys |
| `src/Config/payment-methods.php` | `integration` + payment capability |
| `src/Config/carriers.php` | `integration` + shipping capability |
| `src/Routes/*.php` | `routes.php` (rewrite handlers) |
| `src/Database/Migrations` | `migrations/*` with `ext_` + scope |
| Eloquent models / repositories | Siro repositories/contracts |
| Controllers / DataGrids / Blade | port to Siro controllers + admin panels/Next UI |

The analyzer generates `manifest.siro.draft.json` and never overwrites package
files.

### 11.2 Theme report

Bagisto themes are Blade + assets. P0 `extension:compat` detects theme packages,
counts assets and Blade templates, and extracts palette/font candidates from CSS
into the report. Full `theme:compat` (P1) will:
- extract palette/fonts/assets into a Siro theme draft;
- map homepage sections to Siro section types;
- list Blade templates that need component work;
- report unsupported JS/checkout overrides.

A purchased Bagisto theme is imported locally from files the customer owns; Siro
does not redistribute paid themes.

### 11.3 What "easy" means

For an extension owner: **packaging and merchant UX are familiar; code port is
scoped by a generated report**. For a merchant: **one zip, verify, install,
enable, update, license** — no manual `composer.json`/provider edits like the
Bagisto zip flow.

## 12. Admin UX

Pages:
- `/extensions`: discovered/installed/enabled/failed, license state, update badge.
- `/extensions/[id]`: manifest, compatibility, permissions/config, panels, logs.
- `/extensions/install`: upload, verify report, install, enable.
- `/extensions/updates`: available updates, changelog, rollback.
- `/extensions/licenses`: activation, expiry, grace, seats/limits.
- `/themes`: theme packages, Bagisto import report, preview/publish.

Every destructive action requires typed confirmation and is audit-logged.

## 13. Security / threat model

- Supply chain: signed packages, checksums, key rotation, revocation list.
- Runtime: extensions are trusted PHP in-process; no sandbox in P0. Capability
  gates, review and signatures are the controls. Roadmap: worker isolation.
- Path traversal, zip bombs, symlinks, executable assets: rejected at verify.
- Secrets never in manifest/config/rows; env or secret manager only.
- Audit: install/update/enable/disable/uninstall/license changes.
- Kill switch: platform can disable any extension without deleting data.

## 14. Versioning

- Package semver; manifest schema versioned (`siro.extension/2`).
- `requires.core` / `requires.commerce` checked at every transition.
- Hooks/events are append-only within a commerce major; breaking changes ship a
  migration note and, for majors, a new hook name.
- Downgrade = uninstall (keep data) + install older signed version.

## 15. Roadmap and acceptance

### P0 (this implementation)
- Full spec.
- `extension:verify`, `extension:install`, `extension:pack` with checksums,
  optional Ed25519 signature, path/bomb protections, atomic install + backup.
  Code: `app/Modules/Extension/Application/ExtensionPackageInstaller.php` and
  `app/Console/Commands/Extension{Pack,Verify,Install}Command.php`.
- `extension:compat` Bagisto analyzer + generated manifest draft; theme assets,
  palette/font and Blade counts are included. This is an optional migration aid,
  not part of the Siro-native ecosystem roadmap.
  Code: `app/Modules/Extension/Application/BagistoCompatibilityAnalyzer.php` and
  `app/Console/Commands/ExtensionCompatCommand.php`.
- Tests for verify/install/traversal/checksum/signature and Bagisto fixture.
- Existing lifecycle/health/routes/migrations unchanged and green.

Acceptance: a valid zip verifies and installs into the correct directory; corrupt
or unsafe zips fail without touching the existing version; a Bagisto package
produces a deterministic compatibility report; PHPUnit/PHPStan green.

### P1
- QIT-lite v1 IMPLEMENTED: `extension:qit` checks package/signature,
  store/channel scope, mutating-route auth, secret scan and PHP syntax.
  Code: `app/Modules/Extension/Application/ExtensionQualityGate.php` and
  `app/Console/Commands/ExtensionQitCommand.php`.
- Native Siro theme package (`siro.theme/1`) import/export IMPLEMENTED; see
  [`THEME_PACKAGE_SPEC.md`](./THEME_PACKAGE_SPEC.md).
- Admin upload/update/license pages; QIT-lite CI wiring; private Composer install;
  scope helper + Affiliate migration; storefront slots.

### P2
- Marketplace catalog/billing, vendor portal, reviews, update channels, SLAs.
- Per-extension worker isolation/sandbox, SBOM enforcement, revocation service.

## 16. Affiliate reference migration checklist

- Move manifest to v2 with `type: module`, license SKU, storefront slots.
- Add `store_id`/`channel_id` to all `ext_affiliate_*` tables and queries.
- Add storefront referral capture (`?ref`) and my-account dashboard.
- Add refund clawback listener (`order.refunded` / `refund.succeeded`).
- Add attribution window, fraud checks, payout provider and exports.
- Package/sign, run QIT-lite, publish as the first paid reference module.
