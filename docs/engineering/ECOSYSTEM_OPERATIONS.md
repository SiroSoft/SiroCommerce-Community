# Ecosystem Catalog Release Operations

Status: CURRENT (tooling and operator runbook). Audience: release engineers and
operators who build, validate and publish the Siro ecosystem catalog feed and
extension/theme artifacts.

This runbook is the operational companion to
[`product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md`](../product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md)
and the package/license contracts:
[`contracts/EXTENSION_ECOSYSTEM_SPEC.md`](../contracts/EXTENSION_ECOSYSTEM_SPEC.md)
and [`contracts/THEME_PACKAGE_SPEC.md`](../contracts/THEME_PACKAGE_SPEC.md).

## 0. Current state (baseline 2026-11-24)

Do not describe planned capability as shipped.

| Capability | Status today | Notes |
|---|---|---|
| Checked-in catalog overlay | CURRENT (P0/P2) | Source path is `backend/resources/ecosystem/catalog.json`; 15 items (10 plugins, 5 themes; 3 Pro). Affiliate is published with a signed artifact; the two premium themes remain planned until signed theme artifacts exist. The build tool exits `3` with a clear message when absent. |
| Catalog feed build/validate tool | CURRENT | `scripts/ecosystem-catalog/build.php`; offline, deterministic, no network. |
| Catalog read model / admin tabs | CURRENT (P0/P1) | `GET /api/admin/ecosystem/catalog` and `/updates`; Extensions tabs Installed/Catalog/Updates/Licenses; `/storefront/themes` catalog. Bundled free install/update actions exist; read-only metadata otherwise. |
| Static public feed | CURRENT (P1) | Public-safe feed generated at `apps/storefront/public/ecosystem/catalog.json` and served at `/ecosystem/catalog.json`. Regenerate with `npm run ecosystem:catalog`, drift-check with `npm run ecosystem:catalog:check`. No remote feed server or artifact download endpoint exists. |
| Private Pro feed | CURRENT (P2 foundation) | `GET /api/admin/ecosystem/pro-feed` requires a valid/in-grace Pro/Enterprise license; 403 otherwise. Admin-only, no public marketplace. |
| Marketplace | NOT IMPLEMENTED | No vendor onboarding, submission/review queue, billing, payouts or reviews. |
| Billing | NOT IMPLEMENTED | Pro must not be sold. |
| Vendor / license portal | PARTIAL (P2) | Admin can import/remove a signed license (`/api/admin/extensions/license/import`); customer self-service purchase/delivery portal does not exist. |
| Offline license verify/enforce/issue CLI | CURRENT foundation | See [`operations/EXTENSION_LICENSE_ISSUANCE.md`](../operations/EXTENSION_LICENSE_ISSUANCE.md). |
| Pro entitlement gating of enable/update | PARTIAL (P2) | Affiliate enable is license-gated. Remote update/download entitlement is not implemented. |
| Update channel / auto-update | PARTIAL (P1) | Bundled free plugins can be updated from catalog; remote artifacts and auto-update stay manual/planned. |
| GitHub Actions CI | DISABLED | Minutes are exhausted. The workflows are the intended gate but do not run on push/PR. Run the same commands locally and record the output. Do not claim a CI-gated result. |

Rules:

- Do not sell Pro, publish a public Pro artifact URL, or imply a marketplace or
  portal exists until the P2 exit criteria in the plan and a security review are
  met.
- Do not claim CI ran. Record the local command, date, revision and output for
  every release.
- The build tool never fetches URLs, never reads environment secrets and never
  stores secret material.

## 1. Catalog sources

There are three sources, merged by the backend read model (P0):

1. **Distribution metadata**: the checked-in overlay at
   `backend/resources/ecosystem/catalog.json`. This is the only source the release
   tool reads.
2. **Package metadata**: each extension `manifest.json` (`siro.extension/2`) and
   each theme package (`siro.theme/1`). These are the upstream facts for id, type,
   version, compatibility and license requirements.
3. **Runtime state**: installed extensions in the `extensions` table and the
   active storefront theme config. This is not stored in the feed.

The catalog is metadata, not executable content. The feed contains no private
keys, license documents, customer ids, secrets, filesystem paths or raw
allowlists. Unknown fields are ignored; invalid items fail the whole build.

Install and update still use the existing extension lifecycle and theme
import/publish flows. The catalog never installs or enables anything by itself.

## 2. Build and validate the static feed

### 2.1 Commands

Validate the checked-in overlay only:

```bash
php scripts/ecosystem-catalog/build.php --check
```

Regenerate the committed public feed (the tracked artifact at
`apps/storefront/public/ecosystem/catalog.json`):

```bash
npm run ecosystem:catalog
# equivalent: php scripts/ecosystem-catalog/build.php \
#   --out=apps/storefront/public/ecosystem/catalog.json
```

Verify that the committed public feed matches the overlay without writing:

```bash
npm run ecosystem:catalog:check
# equivalent: php scripts/ecosystem-catalog/build.php \
#   --check-public=apps/storefront/public/ecosystem/catalog.json
```

`--check-public` exits `4` when the file drifted or is missing, so a release
pipeline can fail closed before publishing a stale catalog.

Use `--in=path` only for staging or temporary fixtures (for example a release
candidate overlay). The default is always
`backend/resources/ecosystem/catalog.json`:

```bash
php scripts/ecosystem-catalog/build.php --in=/tmp/candidate-catalog.json --check
```

Publish one Pro item safely with the offline helper after the artifact and its
detached signature exist:

```bash
php scripts/ecosystem-catalog/publish-pro.php \
  --id=siro/affiliate \
  --version=1.0.0 \
  --artifact=dist/siro-affiliate-1.0.0.siro.zip \
  --artifact-url=https://cdn.example.com/siro-affiliate-1.0.0.siro.zip \
  --sha256=<64 hex characters> \
  --signature=<detached signature token> \
  --signature-url=https://cdn.example.com/siro-affiliate-1.0.0.sig
```

`publish-pro.php` is offline and CLI-only. It refuses unknown or non-Pro items
and any missing required field, verifies the SHA-256 of a local `--artifact`
before using it, never stores a filesystem path, validates the whole overlay with
`build.php`, and then replaces the overlay through a same-directory temporary
file. Use `--catalog=path` only for a reviewed staging copy and `--dry-run` to
validate without replacing. It does not change `published_at`.

The public feed is the only feed this tool produces: `--out` writes the
public-safe projection described in section 2.6. The committed file is the
reviewed artifact; do not hand-edit it, and do not keep a second untracked copy.

### 2.2 Self-test

The self-test creates fixtures under the OS temp directory, runs the builder and
removes the fixtures. No fixture is committed:

```bash
php scripts/ecosystem-catalog/selftest.php
```

It covers `php -l`, a valid fixture, deterministic output, sorted items, trailing
newline, `changelog` forwarding and validation (control characters, the 1000
character cap, wrong type), `--check-public` pass/drift/missing, duplicate ids,
invalid type/edition/status, unsafe URLs, invalid `sha256`, the Pro
`license_required` rule, planned Pro without artifact/sha256/signature, published
Pro missing any of those three fields, secret-like fields, a missing source and a
usage error. It also covers `publish-pro.php`: a verified local artifact,
determinism, unknown/non-Pro items, a missing signature, a checksum mismatch and
`--dry-run` leaving the overlay unchanged.

### 2.3 Determinism contract

The tool writes `siro.ecosystem-catalog/1` with:

- items sorted ascending by `id`;
- the public-safe projection only (see section 2.6), so license data and
  artifact URLs/checksums are omitted;
- canonical known fields only (unknown fields are not copied);
- `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`;
- exactly one trailing newline;
- `changelog` forwarded verbatim (`null` when absent);
- the source `generated_at` preserved verbatim (validated as RFC3339).

The same source always produces byte-identical output. `generated_at` is never
invented or replaced with the current time by this tool; update it in the source
overlay when the catalog is intentionally regenerated.

### 2.4 Validation rules

The build fails closed when any of these is violated:

- `schema` is exactly `siro.ecosystem-catalog/1`;
- `generated_at` is RFC3339;
- `items` is a JSON array (an empty array is valid);
- `id` matches `^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$` and is unique;
- `type` is `plugin` or `theme`;
- `edition` is `free` or `pro`;
- `status` is `published`, `planned` or `deprecated`;
- `name` and numeric SemVer `latest_version` are present;
- `license_required` is a boolean; Pro items must set `true` and Free items must
  set `false`;
- `preview_url`, `changelog_url`, `artifact_url` and `signature_url` are `null`,
  `https://` URLs or root-relative paths; `javascript:`, `data:`, `http:`,
  protocol-relative (`//`) and credential-bearing URLs are rejected;
- `sha256` is `null` or exactly 64 hexadecimal characters;
- `signature` is `null` or a single non-empty token with no whitespace or
  control characters (detached signature/reference, never key material);
- `changelog` is `null` or plain text of at most 1000 characters with no control
  characters other than newlines;
- a published Pro item has `artifact_url`, `sha256` and `signature`; a planned Pro
  item may leave all three `null`;
- any item with `artifact_url` has a `sha256`, and any item with `signature` has
  an `artifact_url`;
- private key PEM blocks and secret-like fields (`private_key`, `secret`,
  `password`, `api_key`, `authorization`, and similar) are rejected.

### 2.5 Exit codes

| Exit | Meaning | Operator action |
|---|---|---|
| `0` | Valid; feed written when `--out` was used, or matching when `--check-public` was used | Publish the artifact or continue the release. |
| `2` | Usage error | Fix the command; see `--help`. |
| `3` | Source missing or unreadable | Confirm the checked-in overlay exists and the path is correct. There is no network fallback. |
| `4` | Invalid catalog, or public feed drift/missing | Read every `items[n]` error or regenerate with `npm run ecosystem:catalog`; do not publish a stale feed. |
| `5` | Output write failure | Fix permissions/disk/path; do not publish a partial feed. |

A malformed feed is never partially published. Keep the previous known-good feed
in place.

### 2.6 Public static feed (P1)

P1 ships a static public feed instead of a feed server:

- **URL path**: `https://<storefront-host>/ecosystem/catalog.json`. The file is a
  Next.js static asset under `apps/storefront/public/ecosystem/`, so the host/CDN
  serves it directly; there is no API route or remote download service.
- **Content**: `schema: siro.ecosystem-catalog/1` with `generated_at` and items
  projected from the overlay. Each item exposes only `id`, `type`, `name`,
  `description`, `vendor`, `edition`, `status`, `latest_version`, `min_core`,
  `min_commerce`, `preview_url`, `changelog_url`, `changelog`, `published_at`
  and `tags`. License data (`license_required`, `license_sku`, license status),
  installed/update runtime state, artifact URLs, `sha256`, `signature`,
  `signature_url`, secrets and filesystem paths are **not** in the public feed.
- **`changelog`**: optional plain text, at most 1000 characters, newlines
  allowed; other control characters are rejected. It is forwarded verbatim, and
  `null` when the overlay omits it, so the tool works before and after the P1-A
  catalog fields land.
- **Regenerate + check** (run both):
  ```bash
  npm run ecosystem:catalog
  npm run ecosystem:catalog:check
  ```
- **Release step**: regenerate the feed only after the overlay is final for the
  release. Review the diff, run `ecosystem:catalog:check`, then commit the
  regenerated `apps/storefront/public/ecosystem/catalog.json` with the release.
  If the catalog overlay changed in the same release, the feed must be produced
  after that change; a later overlay edit requires regenerating again.
- **Cache**: serve with a short TTL or ETag/`Last-Modified` revalidation so a new
  release is visible promptly. Do not use an immutable long-lived cache for a
  mutating path.
- **Security**: serve as `application/json` with `X-Content-Type-Options:
  nosniff`; the feed is public, read-only metadata and must never contain
  credentials, license documents, private keys, customer data or filesystem
  paths. No feed signing is implemented; the builder only validates metadata and
  emits deterministic JSON. The Pro `signature` field is a detached artifact
  signature used to gate publication, not a signature over the feed.

## 3. Free release flow

Free packages share the same manifest, QIT, install, migration, scope and
uninstall rules as Pro. They differ in distribution, license enforcement and
support policy.

1. **Prepare the package.**
   - Extension: `manifest.json` schema `siro.extension/2` with `id`, `type`,
     numeric SemVer `version`, `requires.core`/`requires.commerce`, `provider`.
   - Theme: `siro.theme/1` JSON or zip with `id`, `name`, numeric SemVer
     `version`, at least one homepage section and no scope/secrets/executable
     content.
   - Keep `manifest.json` and `Extension.php::manifest()` aligned.
2. **Run the quality gate.**
   ```bash
   php siro extension:qit dist/siro-product-qa-1.0.0.siro.zip
   ```
   Free signatures are optional, but if the package is signed add
   `--require-signature`. QIT is mandatory either way.
3. **Verify the artifact.**
   ```bash
   php siro extension:verify dist/siro-product-qa-1.0.0.siro.zip
   ```
4. **Compute the checksum.** Publish lowercase SHA-256:
   ```bash
   sha256sum dist/siro-product-qa-1.0.0.siro.zip          # Linux
   Get-FileHash -Algorithm SHA256 dist/siro-product-qa-1.0.0.siro.zip  # Windows
   ```
5. **Update the catalog item** in `backend/resources/ecosystem/catalog.json`:
   - `type` matches the package (`plugin` or `theme`);
   - `edition` is `free` and `license_required` is `false`;
   - `latest_version` matches the artifact version;
   - `artifact_url` is `https://` or root-relative and `sha256` is the value from
     step 4;
   - `preview_url`/`changelog_url` are HTTPS or root-relative;
   - `status` stays `planned` until the package passes staging, then becomes
     `published`.
6. **Validate and build the feed.**
   ```bash
   php scripts/ecosystem-catalog/build.php --check
   npm run ecosystem:catalog
   npm run ecosystem:catalog:check
   ```
7. **Stage install and smoke.**
   ```bash
   php siro extension:install dist/siro-product-qa-1.0.0.siro.zip
   php siro extension:check
   php siro extension:migrate
   ```
   Enable from Admin -> Extensions, then smoke authorized reads, 401 without
   auth, 403 without the capability, mutation idempotency and uninstall keeping
   data. Catalog install does not exist yet; use the existing upload/lifecycle
   flow.
8. **Publish the catalog entry.** Change `status` to `published`, regenerate the
   public feed (`npm run ecosystem:catalog`), run `ecosystem:catalog:check` and
   commit the regenerated feed with the release. Publishing metadata never enables
   an installed extension; admins still choose Enable.
9. **Record evidence.** Package `sha256`, versions, QIT/verify output, catalog
   diff, feed hash, staging smoke output, timestamp and operator. Mark anything
   not run as `NOT_PROVEN`.

## 4. Pro signed release flow

### 4.1 Preconditions

A Pro release is blocked until all of these are true:

- `edition=pro`, `license_required=true`, and a stable `license_sku` exist in the
  catalog item;
- the package is signed and verified with `--require-signature`;
- an entitlement check exists for enable (Affiliate is gated) and for update
  (remote update entitlement is still pending);
- the admin license import path and support policy exist (customer purchase
  portal is NOT implemented);
- QIT, security review and a rollback drill have passed.

Until then, keep the item `status=planned`, keep `artifact_url` out of any public
feed, and do not sell it.

### 4.2 Build and sign

Private keys stay in vendor/issuance CI, never in the repository, feed, catalog,
admin UI, logs, tickets or command history visible to merchants.

```bash
php siro extension:pack app/Extensions/Siro/Affiliate \
  --out=dist/siro-affiliate-1.0.0.siro.zip --key=key-1
php siro extension:verify dist/siro-affiliate-1.0.0.siro.zip --require-signature
php siro extension:qit dist/siro-affiliate-1.0.0.siro.zip --require-signature
```

Public keys are configured out of band with `EXTENSION_SIGNING_PUBLIC_KEYS`;
private keys never leave vendor CI. Commercial packages require
`EXTENSION_REQUIRE_SIGNATURE=1`.

### 4.3 Catalog metadata

For a Pro item:

- `edition=pro`, `license_required=true`, non-empty `license_sku`;
- `artifact_url` must be HTTPS and reachable only through the entitlement path
  once it exists; do not publish a paid artifact URL in the public catalog before
  licensing is enforced;
- `sha256` is the lowercase checksum of the exact artifact bytes;
- `signature` is the detached artifact signature token required before a Pro item
  can be `published`; `signature_url` is optional and points at the detached
  signature file when one is published. A planned Pro item may leave all three
  (`artifact_url`, `sha256`, `signature`) null;
- `status` remains `planned` until the P2 gates pass; then `published`;
- the package also carries its signature in `signature.json`. The catalog stores
  the detached signature token and optional URL, never private keys or key
  material.

Publish the reviewed entry with the guarded helper instead of hand-editing the
overlay:

```bash
php scripts/ecosystem-catalog/publish-pro.php \
  --id=siro/affiliate \
  --version=1.0.0 \
  --artifact=dist/siro-affiliate-1.0.0.siro.zip \
  --artifact-url=https://cdn.example.com/siro-affiliate-1.0.0.siro.zip \
  --sha256=<64 hex characters> \
  --signature=<detached signature token> \
  --signature-url=https://cdn.example.com/siro-affiliate-1.0.0.sig
```

It refuses missing fields, unknown/non-Pro items and a local artifact whose
SHA-256 does not match, then validates the whole overlay with `build.php` before
an atomic replace. Use `--dry-run` to validate without writing.

Then run:

```bash
php scripts/ecosystem-catalog/build.php --check
npm run ecosystem:catalog
npm run ecosystem:catalog:check
```

The current tool does not sign the feed. Feed signing, private Pro feed delivery
and update-entitlement checks are P1/P2 work. Do not claim they exist.

### 4.4 License requirement at runtime

- A Pro package must not be enableable without a valid license once the
  commercial flag is enabled.
- Expiry uses the soft gate by default: the module keeps running, updates and
  support require a valid license, and core merchant data is never deleted or
  locked.
- The 30-day grace applies to updates/support, not to data access.
- A hard gate is opt-in per vendor in the manifest; even then, data is retained.

## 5. Update and rollback

The feed reports that a newer version exists. P1 adds bundled free-plugin
actions: `POST /api/admin/ecosystem/install` installs a discovered free plugin
without enabling it, and `POST /api/admin/ecosystem/update` syncs the registry
version from the deployed manifest while preserving state. Remote artifact
download/apply and auto-update are not implemented.

### 5.1 Update sequence (manual)

1. Back up the database and record the current package version and `sha256`.
2. Download/pull the artifact and verify its checksum against the catalog.
3. `php siro extension:verify <zip> --require-signature` for Pro; run QIT.
4. `php siro extension:install <zip> --force` (backs up the old directory) or run
   the tested Composer update path.
5. `php siro extension:check`.
6. `php siro extension:migrate` for already-enabled extensions, then upgrade in
   Admin (or enable if it was disabled).
7. Restart workers/opcache as needed, run health and smoke, then record evidence.

Full detail, including Composer packages and caveats, is in
[`operations/EXTENSION_UPDATES.md`](../operations/EXTENSION_UPDATES.md).

### 5.2 Rollback

1. Disable the failing extension first; data is kept.
2. If the schema is still compatible, restore the previous extension directory
   from `storage/extensions-backups/`.
3. If the new code migrated data incompatibly, restore the pre-update database
   backup. Do not guess at down migrations.
4. For a permanent downgrade, uninstall keeping data and install the older signed
   version, or restore directory plus database backup for an incident rollback.
5. Re-run health and smoke, then record what was restored.

Theme rollback uses storefront config revisions, not extension uninstall. An
imported older theme is a draft until an operator publishes it.

### 5.3 Update availability

`update_available` is computed by the backend from SemVer and catalog metadata;
it does not mean an artifact is available or entitled. A feed entry must never
cause an automatic upgrade. Downgrades remain explicit reinstall/restore
operations.

## 6. License import, rotation and grace

The offline license is a signed JSON envelope (`payload` and `signature`) with
RSA-SHA256 in the current implementation. Verification is local; merchant
deployments do not call home. See
[`operations/EXTENSION_LICENSE_ISSUANCE.md`](../operations/EXTENSION_LICENSE_ISSUANCE.md)
for the full document contract.

### 6.1 Import

Current merchant path:

```bash
php siro extension:license /etc/siro/extension-license.json --json
php siro extension:license --json   # uses EXTENSION_LICENSE_FILE
```

- Exit `0`: community edition or a valid/in-grace Pro/Enterprise license.
- Exit `1`: invalid, not yet valid, or expired beyond grace.
- The JSON result contains edition, ids, features, expiry and reason; it never
  contains key material.
- Admin can import/remove a signed license in Extensions -> Licenses:
  `POST /api/admin/extensions/license/import` and
  `DELETE /api/admin/extensions/license`. The portal stores the envelope at
  `storage/licenses/active.json` (0600, outside public). `EXTENSION_LICENSE_FILE`
  remains the override and env-managed licenses cannot be removed from the UI.
- Customer self-service purchase/delivery portal, billing and refunds are still
  NOT implemented.
- Never place a license private key, signing key or customer secret in the
  catalog, feed, admin response, log or repository.

### 6.2 Rotation

- Generate a new key pair on the trusted issuance host; keep the private key
  there (0600 or HSM/secret manager).
- Reissue active licenses under the new `kid` and distribute the new **public**
  key with `EXTENSION_LICENSE_PUBLIC_KEY`/`_FILE`.
- Switch the public key only when replacement licenses are ready.
- Retain the old private key only for the reissue window, then destroy it per key
  custody policy.
- Record `license_id`, `customer_id`, `edition`, `kid` and expiry in the license
  ledger.

### 6.3 Grace and expiry

- Before `not_before`: not yet valid.
- Between `not_before` and `expires_at`: valid.
- After expiry, before `grace_until` (`expires_at + grace_days`): grace.
- After grace: expired; update/support paths refuse, core data stays intact.
- `grace_days` defaults to 30.

### 6.4 Revocation in the offline model

There is no online revocation list. Revoke by issuing a replacement license that
removes the extension/feature, or by stopping distribution of updates. Short
expiry windows make revocation controllable. Keep `license_id` for audit. A
leaked signing key requires immediate rotation and reissue, not a database edit.

## 7. QIT gate

QIT is mandatory for every package, Free or Pro, before any catalog publish or
update.

```bash
php siro extension:qit dist/<package>.siro.zip
php siro extension:qit dist/<package>.siro.zip --require-signature   # signed/Pro
```

It checks the package/signature, manifest/schema/compatibility, store/channel
scope for `ext_*` tables, mutating-route auth expectations, secret scan and PHP
syntax. A package that fails QIT is not published, regardless of catalog
metadata.

Because GitHub Actions is disabled:

- run QIT locally on the exact artifact that will be published;
- archive the command, artifact `sha256`, revision and output;
- write `NOT_PROVEN` for anything not actually run;
- fix red gates; do not bypass or weaken them.

QIT is necessary but not sufficient: human review, license/terms review and a
staging install are still required.

## 8. Deprecation and revocation

### 8.1 Deprecation

- Set catalog `status=deprecated` in a new validated feed. Keep the item for
  history and audit.
- Stop offering new installs/updates for the deprecated item unless a security
  fix is explicitly released.
- Installed copies keep running; data is not deleted and no automatic uninstall
  occurs.
- Announce the replacement, EOL date and support boundary.
- Never delete or rewrite a published version to mark it deprecated; publish new
  metadata.

### 8.2 Security revocation

When a version or signing key is compromised:

1. Freeze feed publication and stop advertising the affected artifact/update.
2. Disable the affected extension on impacted stores (keeps data).
3. Mark the item deprecated or remove the artifact/update offer in a new feed;
   publish a security advisory with affected versions.
4. If a key is compromised, rotate the signing/license key and reissue affected
   licenses; replace public keys on deployments.
5. Roll back the extension directory/database backup when needed.
6. Preserve logs, artifact `sha256`, signatures and versions for the incident
   record.
7. Never "fix" the issue by deleting merchant data by hand.

There is no online revocation service. Removal from the feed plus short license
expiry is the current control; marketplace-grade revocation remains P2/P3.

## 9. Artifact checksum and signature

- `sha256` is exactly 64 hex characters; the build tool lowercases it.
- The catalog stores `artifact_url`, `sha256`, the detached `signature` token and
  an optional `signature_url`. It never stores private keys, license documents or
  signing secrets. A published Pro item requires all of `artifact_url`, `sha256`
  and `signature`; a public feed strips them.
- `publish-pro.php` verifies a local artifact's SHA-256 and refuses missing
  fields before it replaces the overlay.
- Package signature: `signature.json` inside the `.zip`, verified with
  `extension:verify --require-signature` and public keys from
  `EXTENSION_SIGNING_PUBLIC_KEYS`. Ed25519 is the package-signature contract in
  the ecosystem spec.
- License signature: RSA-SHA256 over the canonical payload, verified offline by
  `extension:license`.
- Verify checksums on the exact downloaded bytes before install:
  `sha256sum <file>` (Linux) or `Get-FileHash -Algorithm SHA256 <file>` (Windows).
- A checksum from the same untrusted source is not authenticity. For Pro, require
  a valid signature and a trusted public key.
- Feed signing and private Pro feed delivery are NOT implemented; the builder
  validates metadata and produces deterministic JSON only.

## 10. Incident safe-mode

Safe-mode means: core commerce keeps serving, suspect or nonessential extensions
are disabled, catalog/update publication is frozen at a known-good feed, and no
merchant data is deleted.

### 10.1 Safe-mode steps

1. **Freeze publication.** Stop running `build.php --out` against the release
   path and stop copying feeds. Keep the last validated feed file and its hash.
2. **Disable the suspect extension.** Use Admin -> Extensions -> Disable (state
   becomes disabled) or the lifecycle API. `failed` state also keeps core
   serving.
3. **Block update offers.** Do not publish a feed that advertises the affected
   version. After triage, publish a new validated feed that deprecates/removes
   the artifact.
4. **Preserve evidence.** Capture logs, release id, package `sha256`, version,
   signature verification output, feed hash and timestamps before changing files.
5. **Roll back.** Restore the previous extension directory and/or pre-update
   database backup as described in section 5.2 and
   [`operations/EXTENSION_UPDATES.md`](../operations/EXTENSION_UPDATES.md).
6. **Health checks.** Verify `/health/live`, `/health/ready`,
   `/health/providers` and the core storefront/admin critical paths.
7. **Key/license action.** If supply chain or license compromise is suspected,
   rotate keys and reissue licenses (section 6.2).
8. **Communicate and record.** Write the incident record, customer impact,
   affected versions, actions and prevention. Follow
   [`engineering/INCIDENT_RUNBOOK.md`](INCIDENT_RUNBOOK.md).
9. **Exit safe-mode deliberately.** Re-enable only after verify/QIT, checksum and
   staging smoke pass; publish a new validated feed with the corrected version.

### 10.2 Malformed catalog feed

- The build tool fails closed with exit `4` and writes nothing.
- Do not publish a partially valid feed or hand-edit JSON in production.
- Keep the previous known-good feed serving.
- When the backend catalog read model is implemented, a malformed source must
  return a safe error with no partial data and no filesystem paths; the feed tool
  and backend must agree on the same validation rules.

### 10.3 When not to use safe-mode

Safe-mode does not replace database restore for corruption, provider outage
procedures, or a core deploy rollback. Use the main
[`engineering/RUNBOOK.md`](RUNBOOK.md) and
[`engineering/INCIDENT_RUNBOOK.md`](INCIDENT_RUNBOOK.md) for those.

## 11. Release checklist

### 11.1 Before publishing

- [ ] Change approval, release owner and rollback owner identified.
- [ ] Catalog overlay exists at `backend/resources/ecosystem/catalog.json`, or a
      reviewed staging override is used with `--in`.
- [ ] Artifact built from the intended revision; `manifest.json` and
      `Extension.php::manifest()` agree; SemVer increment is correct.
- [ ] Artifact `sha256` computed from the exact publishable bytes and recorded.
- [ ] Free: QIT passed (signature optional unless signed).
- [ ] Pro: signed, `extension:verify --require-signature` passed, QIT passed,
      `license_required=true`, `license_sku` set, entitlement path ready, and
      `publish-pro.php` has set `artifact_url`, `sha256` and `signature`.
- [ ] URLs are HTTPS/root-relative; no `javascript:`/`data:`/credentials.
- [ ] No secrets, private keys, license documents or filesystem paths in the
      catalog/feed.
- [ ] `php scripts/ecosystem-catalog/build.php --check` passes.
- [ ] Staging install/update, migrations, health and smoke passed.
- [ ] Database backup taken and restore drill is current enough for the risk.

### 11.2 During publication

- [ ] Rebuild the feed with `npm run ecosystem:catalog` from the reviewed source.
- [ ] Compare the new feed hash with the previous published feed; confirm only
      intended items changed.
- [ ] Run `npm run ecosystem:catalog:check` and commit the regenerated
      `apps/storefront/public/ecosystem/catalog.json` with the release.
- [ ] Publish catalog status `published` only after staging and QIT.
- [ ] Do not enable, auto-update or auto-rollback anything as a side effect of
      publishing metadata.

### 11.3 After publication

- [ ] Install/update path smoke: authorized read, 401 without auth, 403 without
      capability, mutation idempotency, disable/uninstall keeping data.
- [ ] Core smoke: `/health/live`, `/health/ready`, `/health/providers`, checkout
      or quote critical path, admin login/read.
- [ ] Logs/audit reviewed for extension errors, failed jobs and queue backlog.
- [ ] Theme imports remain drafts; no auto-publish.
- [ ] Evidence recorded: feed hash, package `sha256`, versions before/after,
      commands, outputs, backup path, timestamps.
- [ ] Rollback window and backup retention agreed; do not prune backups during
      the window.
- [ ] CHANGELOG update is owned by the release/main stream, not this runbook.

## 12. References

| Document | Use |
|---|---|
| [`product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md`](../product/ECOSYSTEM_CATALOG_MANAGEMENT_PLAN.md) | Catalog contract, phases, workstream split, DoD. |
| [`contracts/EXTENSION_ECOSYSTEM_SPEC.md`](../contracts/EXTENSION_ECOSYSTEM_SPEC.md) | Package format, manifest v2, install/update, license, QIT, roadmap. |
| [`contracts/EXTENSION_PACKAGING_SPEC.md`](../contracts/EXTENSION_PACKAGING_SPEC.md) | Runtime anatomy, lifecycle, migrations, version gate. |
| [`contracts/THEME_PACKAGE_SPEC.md`](../contracts/THEME_PACKAGE_SPEC.md) | Theme package import/export and rollback via revisions. |
| [`operations/EXTENSION_UPDATES.md`](../operations/EXTENSION_UPDATES.md) | Detailed update, rollback and Composer runbook. |
| [`operations/EXTENSION_LICENSE_ISSUANCE.md`](../operations/EXTENSION_LICENSE_ISSUANCE.md) | License document, key custody, rotation and grace. |
| [`engineering/EXTENSION_BUILD_GUIDE.md`](EXTENSION_BUILD_GUIDE.md) | Scaffold, pack/verify/install commands, publish checklist. |
| [`engineering/EXTENSION_SCOPING.md`](EXTENSION_SCOPING.md) | Required store/channel scope for extension data. |
| [`engineering/RUNBOOK.md`](RUNBOOK.md) | Database backup/restore and core release rollback. |
| [`engineering/INCIDENT_RUNBOOK.md`](INCIDENT_RUNBOOK.md) | Incident severity, on-call and failure playbooks. |
| [`engineering/CI_QUALITY.md`](CI_QUALITY.md) | Intended CI gate; currently disabled, run locally. |
