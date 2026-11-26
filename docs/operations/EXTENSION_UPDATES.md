# Extension and Theme Updates, Versioning and Rollback

Status: CURRENT (verified against code 2026-11-22). Audience: extension
developers and operators. This is the update/rollback runbook; packaging rules
stay in [`EXTENSION_ECOSYSTEM_SPEC.md`](../contracts/EXTENSION_ECOSYSTEM_SPEC.md)
and the hands-on build path in
[`EXTENSION_BUILD_GUIDE.md`](../engineering/EXTENSION_BUILD_GUIDE.md).

## 0. What exists today and what is planned

Do not describe planned behavior as shipped. The current release has no update
server, no manifest feed, no admin "update available" page, and no license
issuance portal.

| Capability | Status today | Notes |
|---|---|---|
| Zip verify / install / `--force` backup | CURRENT | `extension:verify`, `extension:install`, admin upload/verify/install |
| Extension migrations | CURRENT | `extension:migrate` (enabled extensions) and `enable()` |
| Enable / disable / uninstall keep-data / remove-data | CURRENT | Admin/API lifecycle, idempotency required |
| Admin upgrade (manifest/version refresh) | CURRENT | Does not run migrations by itself |
| Composer private package discovery | CURRENT | Vendor package + `extra.siro.extension` |
| QIT-lite CLI | CURRENT (P1-lite) | `extension:qit` |
| Offline license verification | CURRENT | `extension:license`, local signed JSON |
| Signed update manifest feed | PLANNED (P1/P2) | No server or endpoint exists |
| Admin update/rollback page | PLANNED (P1/P2) | EXT-003 in the 4-dev plan |
| License issuance / vendor portal | PARTIAL | Issuance CLI + offline verify shipped; vendor portal PLANNED. See [issuance doc](../operations/EXTENSION_LICENSE_ISSUANCE.md) |
| Marketplace billing/payouts | PLANNED (P2) | Not an update mechanism today |

The operator path today is: signed zip or Composer package, manual backup,
manual verify/install, then enable/upgrade.

## 1. Version surfaces and SemVer policy

### 1.1 Which version is which

| Surface | Where | Meaning |
|---|---|---|
| Package version | `manifest.json` `version`, and `Extension.php::manifest()['version']` | Release version of the extension. SemVer `MAJOR.MINOR.PATCH`. |
| Manifest schema | `manifest.json` `schema` | Envelope format, currently `siro.extension/2`. It versions the format, not the extension release. |
| Core requirement | `requires.core` | SiroPHP core constraint the extension supports. |
| Commerce requirement | `requires.commerce` | SiroCommerce constraint the extension supports. |
| Theme package version | `siro.theme/1` package `version` | Theme release; themes are data, not PHP. |
| License identity | License `license_id`, `kid`, expiry/grace claims | Not an extension version; see the license doc. |

Keep `manifest.json` and `Extension.php::manifest()` aligned. `manifest.json` is
required by pack/verify/install; runtime discovery and enable/upgrade use the
`manifest()` array when the class provides one. A mismatch is a release bug.

### 1.2 SemVer rules

Current validators accept only numeric `MAJOR.MINOR.PATCH` for the package
version (`^\d+\.\d+\.\d+$`). Pre-release tags (`1.2.0-beta.1`) and build metadata
(`1.2.0+build`) are not accepted today; do not publish them.

Choose the increment by compatibility, not by effort:

| Increment | Use when | Examples |
|---|---|---|
| MAJOR | Any breaking change for merchants or other extensions. | Remove/rename a public route, capability, config key, hook, event, slot, or admin panel; change an `ext_*` table/column contract; change data semantics; drop a supported `requires.core`/`requires.commerce` line; require a new PHP extension/module. |
| MINOR | Backward-compatible capability addition. | New route, admin panel, capability/permission, config key with a default, hook/event listener, additive `ext_*` migration, new slot usage, new provider integration. |
| PATCH | Backward-compatible fix only. | Bug fix, security fix, copy/label fix, docs, internal refactor, performance fix with no public contract or data-semantic change. Additive index or data backfill is acceptable only if it is safe for the previous release. |

Rules:

- A published version is immutable. Never rebuild or re-sign the same version
  with different bytes; publish a new patch.
- Never use a version to mean "same code, different channel". Channels are a
  feed concept (planned), not a version suffix.
- Do not bump `requires.core`/`requires.commerce` in a patch unless the previous
  patch was already broken on that core; a compatibility bump is at least minor.
- The manifest schema version is independent. A `siro.extension/3` migration
  needs its own schema-change note even if the package version is only minor.
- Downgrade is not an in-place SemVer operation. See section 4.

### 1.3 Core/commerce constraints

Current runtime constraints support exact versions (`1.3.0`) and caret ranges
(`^1.3`, `^0.1`) only. Tilde, `>=`, `||`, wildcards and hyphen ranges are not
implemented in `VersionConstraint`; a non-caret non-exact string is treated as an
exact match and will fail closed. `requires` keys are restricted to `core` and
`commerce`; an unknown key is rejected.

As of this verification: core is the `1.x` line (installed `1.3.0`) and commerce
is `0.1.x` (`App\CommerceVersion::VERSION = '0.1.0'`). Author manifests against
those lines, for example:

```json
"requires": { "core": "^1.3", "commerce": "^0.1" }
```

A `requires` mismatch must block enable/upgrade. `extension:check` reports the
mismatch; `enable()`/`upgrade()` validate the manifest before changing state.
Verify and install do not auto-enable, so a placed-but-incompatible package must
not be enabled.

## 2. Zip install and upgrade (CURRENT)

### 2.1 Preconditions

- A signed package whose `manifest.json` and `Extension.php::manifest()` agree.
- A tested update in staging, with the upgrade note and config/secret changes.
- A database backup and a record of the current version and package sha256.
- Operator access to the host and admin/owner access to Extensions.

Back up before touching files or migrations. A successful `install --force`
backs up only the extension directory; it does not back up the database.

### 2.2 Vendor side (release build)

```bash
php siro extension:pack app/Extensions/Siro/Affiliate --out=dist/siro-affiliate-1.2.0.zip --key=key-1
php siro extension:verify dist/siro-affiliate-1.2.0.zip --require-signature
php siro extension:qit dist/siro-affiliate-1.2.0.zip --require-signature
```

`extension:pack` requires `manifest.json`; signing needs the vendor private key
(`EXTENSION_SIGNING_PRIVATE_KEY`) and a key id. The verifier reads public keys
from `EXTENSION_SIGNING_PUBLIC_KEYS` (JSON map) or a single
`EXTENSION_SIGNING_PUBLIC_KEY`. Unsigned packages verify with a warning; use
`--require-signature` for paid/commercial packages. Private keys never leave
vendor CI and never go into the package.

### 2.3 Operator upgrade sequence

1. **Back up the database.** Use the release backup or
   `php siro db:backup --compress`, and verify the dump is non-empty. The
   release pipeline keeps `backups/pre-<timestamp>.sql`; manual procedure is in
   [`RUNBOOK.md`](../engineering/RUNBOOK.md#2-backup).
2. **Verify the new package without changing state.**
   `php siro extension:verify <package.zip> --require-signature`
   (or admin upload -> verify). Confirm id, version, type, signature, checksum
   and manifest.
3. **Install with replacement and directory backup.**
   `php siro extension:install <package.zip> --force`
   (or admin install with `force=true`). The installer extracts to a temp
   directory, moves the existing `app/Extensions/<Vendor>/<Name>` to
   `storage/extensions-backups/<vendor-name>-<YmdHis>`, then moves the new
   version into place. On failure the previous directory is restored.
4. **Check discovery and compatibility.**
   `php siro extension:check`. Resolve any id/version/`requires` error before
   enabling. `php siro migrate` is core-only; use `php siro extension:migrate`
   for extensions (it only processes extensions already in `enabled` state).
5. **Run migrations, then enable or upgrade.**
   - Already enabled: `php siro extension:migrate`, then Admin -> Extensions ->
     `<id>` -> Upgrade (or `POST /api/admin/extensions/upgrade` with an
     Idempotency-Key). Upgrade refreshes the stored manifest/version and leaves
     the state `enabled`; it does not run migrations itself.
   - Not enabled: enable the extension. `enable()` runs pending migrations,
     registers and boots the provider. If migration or boot throws, state becomes
     `failed` and core keeps serving.
6. **Health and smoke.** Restart/roll out backend workers (and clear opcache if
   enabled) so long-lived PHP processes load the new files. Call the health
   endpoint/Admin health action, then smoke the extension routes and core
   checkout/admin read paths. Record the result (section 7).

For a lower-risk window, disable the extension before step 3, back up the DB,
install `--force`, then enable (enable runs the new migrations). The extension is
unavailable during that window; this avoids new code serving requests against an
old schema.

### 2.4 Update caveats

- `install --force` replaces files even when the extension is enabled. If the
  new version has migrations, run `extension:migrate` immediately; otherwise new
  code can serve against the old schema.
- The current lifecycle does not enforce that the new version is greater than the
  installed one. The operator/release process must enforce monotonic SemVer; the
  planned update feed will carry this check.
- `upgrade` does not verify package bytes, does not run migrations and does not
  call health. Verification, migration and health are separate, explicit steps.
- Keep the backup directory until the update has passed smoke and a rollback
  window has expired. Do not prune `storage/extensions-backups/` during an
  incident.

## 3. Composer private package update (CURRENT)

A vendor can publish a Composer package instead of a zip. The package entry class
is declared in `composer.json`:

```json
{
  "name": "acme/shipping-fees",
  "autoload": { "psr-4": { "Acme\\ShippingFees\\": "src/" } },
  "extra": { "siro": { "extension": "Acme\\ShippingFees\\Extension" } }
}
```

The class implements `App\Contracts\ExtensionInterface`; migrations and routes
resolve relative to the class file, so the same manifest, scope, auth and QIT
rules apply. The installer never runs Composer itself; the operator does.

Update flow:

```bash
composer update acme/shipping-fees   # or a constrained composer require
php siro extension:check             # discovery + manifest/requires validation
php siro extension:migrate           # only for already-enabled extensions
# Admin -> Extensions -> acme/shipping-fees -> Upgrade (or Enable if disabled)
```

Then run health and smoke. `composer update` mutates `composer.lock` and
`vendor/`; do it in staging/release, not on a live production tree. Deploy the
tested lock with the normal CI/CD path.

Composer rollback:

1. Reinstate the previous lock (`composer install` from the prior lock) or
   `composer require acme/shipping-fees:<old-version>`, then `extension:check`.
2. Restore the pre-update database backup if the new version ran an
   incompatible migration. `extension:migrate` has no downgrade command.
3. Re-run health/smoke. Keep data unless the operator explicitly chooses
   `remove_data=true`.

Constraints to state in vendor docs: the package's Composer version/constraints,
the extension `manifest()` version/`requires`, PHP extension requirements, and
whether its migrations are additive or destructive.

## 4. Rollback and downgrade

### 4.1 Decision order

1. Disable the extension if it is serving errors; data is kept.
2. If the failure is code-only and the schema is still compatible, restore the
   previous extension directory.
3. If the new code migrated data/schema in a way the old code cannot read,
   restore the pre-update database backup. Do not guess at down migrations.
4. If the intent is a permanent downgrade, uninstall with data kept and install
   the older signed package. Never silently delete merchant data.

### 4.2 Restore the previous extension directory

The `--force` install moved the old directory to
`storage/extensions-backups/<vendor-name>-<YmdHis>`. To roll back:

1. Disable the extension.
2. Move the failed/current directory aside (preserve it as evidence).
3. Move the backup directory back to
   `app/Extensions/<Vendor>/<Name>`.
4. Run `php siro extension:check` and restore the previous DB backup only if the
   old code needs the old schema.
5. Enable (or verify the state) and run health/smoke.

Restoring a directory does not undo migrations. If the old release cannot run
against the new schema, the DB restore is mandatory.

### 4.3 Database rollback

Use the pre-update dump described in
[`RUNBOOK.md`](../engineering/RUNBOOK.md#2-backup). Restore into an isolated
database first where possible, verify readiness/row counts, and only then restore
in place. Record the dump path, start/end times and verification output.

Do not run `php siro migrate:rollback` expecting it to revert `ext_*`
migrations: the core rollback command resolves files under
`database/migrations`, while extension migrations are tracked as
`ext::<id>::<file>`. Extension down() is invoked by the lifecycle when
`remove_data=true` is explicitly requested.

### 4.4 Downgrade rules

- In-place downgrade is unsupported. The supported path is uninstall keeping
  data, then install the older signed version (or restore the directory + DB
  backup for an incident rollback). This only works when the older code can read
  the current schema; otherwise restore the pre-update DB backup first.
- Keep data by default. `remove_data=true` (lifecycle `removeData`) is
  destructive, must be a typed confirmation, and is audited.
- A schema change is forward-only in normal operation. Prefer additive
  migrations so a code rollback can still read the data.
- Theme downgrade uses the storefront config revision history, not extension
  uninstall/install. Importing an older theme package is a draft until an
  operator publishes it; see [`THEME_PACKAGE_SPEC.md`](../contracts/THEME_PACKAGE_SPEC.md).

## 5. Data compatibility and migrations

### 5.1 Migration contract

- Every `ext_*` table includes `store_id` and `channel_id` unless the manifest
  declares a reviewed `global: true`; reads/writes use the commerce scope.
- Table names start with `ext_`; foreign keys may reference only core tables with
  uninstall-safe behavior (`SET NULL`/documented `CASCADE`).
- Provide both `up()` and `down()`. `up()` is required to migrate. `down()` is
  required for `remove_data=true`; if it is missing, the rollback aborts, data is
  kept and the extension must be handled manually.
- Migrations are ordered by filename and tracked in the shared `migrations`
  table as `ext::<id>::<file>`.
- Prefer additive changes (new nullable column, new table, new index). Avoid
  destructive changes in a minor/patch release; a destructive change is a major
  release with an explicit backup/upgrade note.
- Never edit an already-released migration. Add a new migration.
- `extension:migrate` only touches extensions in `enabled` state. `enable()`
  runs migrations for that extension. `remove_data=true` runs `down()` in reverse
  order and deletes the registry row.

### 5.2 Backups and rollback compatibility

- Take a DB backup before any release with migrations, even additive ones.
- A code rollback is safe only when the old code tolerates the new schema. If in
  doubt, restore the DB backup.
- A backup is not proven until restored in a drill; an unmeasured restore is
  `NOT_PROVEN` (see [`RUNBOOK.md`](../engineering/RUNBOOK.md) and
  [`BACKUP_DRILL.md`](../engineering/BACKUP_DRILL.md)).
- Secrets stay in env/secret manager. A database restore must not reintroduce
  stale secrets from a dump; re-apply current secret-manager values.

## 6. Version gate and compatibility boundary

### 6.1 Gate behavior

- `manifest.json` requires `schema`, `id`, `type`, `version`, `requires` and
  `provider`; `requires` must declare both `core` and `commerce`.
- At enable/upgrade, the lifecycle checks `requires.core` against the core
  version and `requires.commerce` against `CommerceVersion::VERSION`. A mismatch
  throws before state changes, migrations or boot.
- `extension:check` reports manifest/version/requires errors as JSON and exits
  non-zero when any extension is invalid. Use it in CI and after every install.
- A package that fails verification never installs. A package that verifies but
  is incompatible remains non-enabled until the operator resolves the version
  mismatch.
- License-gated extensions (`license.required = true`) additionally require a
  valid Pro/Enterprise license listing the extension at enable. The license check
  is offline; failures never delete data.

### 6.2 Hooks and events are append-only

Within a commerce major, published hook names and core event names are stable.
Never rename or remove an existing hook/event in a minor or patch release. To
change a hook contract, publish a new hook name and keep the old one for the
supported major line; document the migration in the extension upgrade note. The
manifest schema is versioned separately and follows the same append-only rule.

## 7. Update runbook checklist

### 7.1 Before the window

- [ ] Change ticket/approval, owner and rollback owner identified.
- [ ] Staging update passed with the same package sha256 and DB engine.
- [ ] Current extension version, package sha256 and previous backup directory
      recorded.
- [ ] Fresh database backup completed and verified non-empty; restore drill
      result is current enough for this risk.
- [ ] New package signed and verified; `extension:qit` passed in CI.
- [ ] `manifest.json` and `Extension.php::manifest()` agree; SemVer increment is
      correct; `requires.core`/`requires.commerce` match the target deployment.
- [ ] Migration plan reviewed: additive, `down()` present, no core tables, scope
      columns present.
- [ ] Config/secret changes and license entitlement confirmed.
- [ ] Disk space for package, backups and DB dump; maintenance/communication
      decided for schema-changing updates.

### 7.2 During the window

- [ ] Back up DB immediately before migration (not hours earlier).
- [ ] Verify package, then `install --force`; confirm the backup path printed by
      the command.
- [ ] `extension:check` clean.
- [ ] Run `extension:migrate` (enabled) or enable (runs migrations).
- [ ] Upgrade/enable the new manifest; confirm state `enabled`.
- [ ] Health check returns ok; do not proceed on `failed`.

### 7.3 After the window

- [ ] Extension routes smoke: authorized read, mutation with Idempotency-Key,
      401 without auth, 403 without capability.
- [ ] Core smoke: `/health/live`, `/health/ready`, `/health/providers`,
      checkout/quote or the storefront critical path, admin login/read.
- [ ] Logs and audit trail reviewed for extension errors, failed jobs, outbox or
      queue backlog.
- [ ] Storefront/admin smoke for the affected surface; theme draft not
      auto-published by an extension update.
- [ ] Evidence recorded: package sha256, versions before/after, commands, backup
      path, migration names, health and smoke output, timestamps.
- [ ] Rollback window and backup retention agreed; do not prune the backup yet.

### 7.4 Incident rollback triggers

Roll back when the extension fails health, blocks core traffic, corrupts/reads
data outside scope, cannot complete migrations, or the release cannot pass the
post-update smoke. Follow
[`INCIDENT_RUNBOOK.md`](../engineering/INCIDENT_RUNBOOK.md) sections 4.3
(deploy fail/rollback), 4.4 (migration fail) and 4.11 (data corruption/restore).
Sequence: disable -> preserve logs/evidence -> restore previous directory and/or
pre-update DB backup -> health/smoke -> communicate -> write the incident record.
Never "fix forward" by editing the database by hand unless the incident runbook
explicitly authorizes it.

## 8. Update channels (PLANNED P1/P2)

There is currently no signed manifest feed server, no channel endpoint, and no
admin update-available/apply page. Treat any such URL as a future contract, not a
shipped feature.

Planned design constraints, if/when a feed is implemented:

- The feed is a signed manifest list, not a code-execution endpoint. Each entry
  carries id, version, `requires`, channel, changelog, package URL, package
  sha256 and a detached signature. Clients verify the feed signature and the
  package signature with the same key policy as `extension:verify`.
- Channels are explicit (`stable`, `beta`, pinned/off) and never encoded in the
  package version. An operator opts in; a feed must not silently upgrade.
- The update action must call the existing verify/install/lifecycle services, not
  duplicate install logic in a controller.
- Entitlement/grace checks may gate an update offer, but expired entitlement must
  never delete data and must never block the already-installed module from
  serving.
- Downgrades remain explicit reinstall/restore operations; a feed does not make
  in-place downgrade safe.
- No phone-home is required for the current offline license or install path.

Track the update channel/admin page as EXT-003 in
[`ECOSYSTEM_TEAM_PLAN_4DEVS.md`](../product/ECOSYSTEM_TEAM_PLAN_4DEVS.md). Do not
claim a feed or marketplace exists until its endpoint and signed test fixtures
are in the repository.

## 9. Theme update notes

Theme packages (`siro.theme/1`) are presentation data, not executable modules.
They have no PHP migrations and no extension lifecycle row. Update by importing
the newer package as a draft, previewing, then saving/publishing; rollback by
republishing a previous storefront config revision. Package `version` is SemVer,
but theme dependency `requires` and signed commercial themes remain P2. See
[`THEME_PACKAGE_SPEC.md`](../contracts/THEME_PACKAGE_SPEC.md).

## 10. References

| Document | Use |
|---|---|
| [`EXTENSION_ECOSYSTEM_SPEC.md`](../contracts/EXTENSION_ECOSYSTEM_SPEC.md) | Package format, manifest v2, install/update rules, license, roadmap. |
| [`EXTENSION_PACKAGING_SPEC.md`](../contracts/EXTENSION_PACKAGING_SPEC.md) | Runtime anatomy, lifecycle, migrations, hooks, version gate. |
| [`EXTENSION_BUILD_GUIDE.md`](../engineering/EXTENSION_BUILD_GUIDE.md) | Scaffold, pack/verify/install commands, publish checklist. |
| [`THEME_PACKAGE_SPEC.md`](../contracts/THEME_PACKAGE_SPEC.md) | Theme package import/export and rollback via revisions. |
| [`EXTENSION_LICENSE_ISSUANCE.md`](../operations/EXTENSION_LICENSE_ISSUANCE.md) | Offline license issuance, key custody and rotation. |
| [`EXTENSION_SCOPING.md`](../engineering/EXTENSION_SCOPING.md) | Required store/channel scope for `ext_*` data. |
| [`RUNBOOK.md`](../engineering/RUNBOOK.md) | DB backup/restore, release rollback, restore drills. |
| [`INCIDENT_RUNBOOK.md`](../engineering/INCIDENT_RUNBOOK.md) | Incident severity, migration/data-corruption playbooks. |
| [`COMMERCE_CLI.md`](../engineering/COMMERCE_CLI.md) | CLI control plane and recovery commands. |
