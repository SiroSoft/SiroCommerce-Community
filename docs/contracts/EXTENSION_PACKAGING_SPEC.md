# Extension Packaging Spec — Plug-and-Play Modules (Bagisto parity)

Commercial distribution (signed zips, install/update, license, Bagisto import) is
specified in [`EXTENSION_ECOSYSTEM_SPEC.md`](./EXTENSION_ECOSYSTEM_SPEC.md).

Status: SPEC. Code that disagrees with this spec is rejected in review.
Supersedes the lifecycle sketch in `07_EXTENSION_SDK.md` (kept for contracts).

## 0. Goal / non-goals

- Goal: drop a module into `app/Extensions/<vendor>/<name>/`, run one command,
  enable from admin, remove it later without residue. Same bar as Bagisto
  packages, plus version gates and health that Bagisto lacks.
- Non-goals: micro-frontends, storefront widgets owned by extensions (use theme
  sections), disabling core `app/Modules/*` (they are not extensions).

## 1. Anatomy (RULE-E1)

```text
app/Extensions/<vendor>/<name>/
  Extension.php            # implements App\Contracts\ExtensionInterface
  manifest.json            # optional; manifest() method wins when both exist
  routes.php               # returns list of [method, path, handler, middleware]
  migrations/              # 20261007_000001_create_ext_<name>_*.php
  src/                     # services, controllers, resources (PSR-4)
  tests/                   # Feature + Unit, run inside the main suites
  README.md                # what it does, config keys, uninstall behavior
```

- Namespace: `App\Extensions\<Vendor>\<Name>` (no composer change needed).
- Extension id: `vendor/name`, lowercase, regex
  `^[a-z0-9][a-z0-9._-]*(\/[a-z0-9][a-z0-9._-]*)?$` (enforced).
- One extension = one bounded context. No cross-extension calls except through
  core contracts (`Siro\Core\Container`) and events.

### 1.1 Scaffold (CLI)

```bash
php siro make:extension <Vendor> <Name>          # positional form
php siro make:extension --vendor=<Vendor> --name=<Name> [--force]
```

`Vendor` and `Name` are PascalCase identifiers (`^[A-Z][A-Za-z0-9]*$`); the id
is the lowercase `vendor/name`. Path separators and `..` are rejected before
anything is written. The command refuses to touch an existing package
directory unless `--force` is passed.

Generated structure (templates only, no `eval`, no arbitrary input in code):

```text
app/Extensions/<Vendor>/<Name>/
  Extension.php          # implements App\Contracts\ExtensionInterface
  manifest.json          # id vendor/name, version 0.1.0, permissions []
  routes.php             # [GET, status] row, read-only
  StatusController.php   # thin sample handler
  README.md              # enable + local test steps
```

The manifest starts permission-free, so no `admin` block is emitted: an admin
panel permission must be listed in `permissions` or `ExtensionManifest`
rejects it. Add both together when the extension needs admin UI.

Next steps:

```bash
php siro extension:check       # validate the new manifest
php siro extension:migrate     # run ext_* migrations, if any
# admin → Extensions → <vendor>/<name> → Install → Enable (refresh list first)
curl -H "Authorization: Bearer <token>" \
  http://localhost:8080/api/ext/<vendor>/<name>/status
php vendor/bin/phpunit tests/Unit/ExtensionScaffolderTest.php
```

## 2. Manifest (RULE-E2)

Required: `id`, `version` (semver `major.minor.patch`), `requires`
(`core` + `commerce` constraints, e.g. `^1.0`). Optional lists:
`capabilities`, `permissions`, `migrations`, `routes`, `config` (default keys),
`slots` (`admin-nav`, `my-account` — see §8).

Validation (already in `ExtensionLifecycleService::validate`, keep in sync):
bad id/version, unknown runtime, unsatisfied constraint, illegal characters,
path traversal (`..`) in migrations/routes → reject with reasons, never
partial state.

## 3. Discovery (RULE-E3)

- Single source: directory scan of `app/Extensions/*/*/Extension.php`.
  No hardcoded class lists, no config file to edit.
- `extension:check` (CLI) + `GET /api/admin/extensions` report discovered vs
  installed, with validation errors inline.
- States in `extensions` table: `discovered → installed → enabled ⇆ disabled`,
  `failed` (health), `uninstalled` removes the row only with `removeData=true`.
- Transitions allowed: install (discovered→installed), enable
  (installed|disabled→enabled), disable (enabled→disabled), uninstall
  (any→gone), upgrade (any→enabled on new version). Anything else → 422.

## 4. Route mounting (RULE-E4)

- Loader mounts each enabled extension's `routes.php` under
  `/api/ext/<vendor>/<name>/…` on every boot (cached per deploy, rebuilt on
  enable/disable — never per request scanning in production).
- Mandatory middleware stack: commerce scope, `auth` where the manifest says
  so, capability gate from manifest `permissions`, throttle, idempotency-key on
  POST/PUT/PATCH/DELETE, audit log. Extension code never declares its own auth.
- Path rules: relative paths only, no `..`, no collisions with core `/api/*`
  (loader throws on collision at boot, extension stays `failed`).
- OpenAPI: extension routes appear in the generated document, tagged
  `extension:<id>`, covered by the breaking-change gate.

## 5. Migrations (RULE-E5)

- Extension migrations run inside `siro migrate` after core, ordered by
  `(installed_at, filename)`. Table names MUST start with `ext_` and foreign
  keys may only reference core tables with `ON DELETE` behavior that keeps
  uninstall safe (prefer `SET NULL` / `CASCADE` documented per table).
- `uninstall(removeData=true)` runs the extension's `down()` in reverse order,
  then deletes config keys and the registry row. `removeData=false` keeps data
  and only unregisters code (re-install reuses it).
- Failed migration → extension `failed`, core boot unaffected.

## 6. Events & hooks (RULE-E6)

- Prefer the raw event bus (`Event::on('order.paid', …)`) for reactions
  (notify, accrue, sync). Prefer typed `HookBus` hooks when the extension must
  *change* a core decision (`pricing.before_quote`,
  `order.before_transition`, `catalog.product.saved`, `payment.before_capture`).
- Listener rules: no blocking I/O inside listeners — enqueue a job; catch and
  record, never let an extension exception break core flow (except an explicit
  typed-hook `reject`, which is a deliberate veto with reason).
- Typed hooks (`Hooks::fire`): payload schemas enforced fail-closed
  (`HookSchemas`); a crashing handler is recorded as `handler:error` and
  skipped (core continues); `reject` vetoes with reason → 422.
  `Hooks::notify` is the post-commit variant: rejects are logged and swallowed
  because the state change already happened.
- Priority convention: core handlers 0–99, extensions 100+ (ascending runs
  first). Shared bus: dispatch sites and extension `boot()` meet on
  `HookBus::shared()`; `new HookBus()` stays isolated for tests.
- New hook process: add `HookName` case + required keys in `HookSchemas` +
  dispatch call + catalog test + document in §9 table. Hooks are append-only;
  never rename. Current catalog (10): `pricing.before_quote`,
  `order.before_transition`, `catalog.product.saved`, `payment.before_capture`,
  `order.delivered`, `customer.registered`, `checkout.before_place`,
  `refund.before_issue`, `subscription.before_renew`, `inventory.before_reserve`.

Core event catalog for extensions (stable, versioned with commerce):

```text
order.placed / order.transitioned / order.refunded
payment.captured / payment.failed / refund.created / refund.succeeded
fulfillment.allocated / shipped / delivered / cancelled / failed
subscription.started / paused / resumed / cancelled / renewed / payment_failed
promotion.applied / promotion.rejected
b2b.order.submitted / approved / rejected
```

## 7. Capabilities, permissions, config (RULE-E7)

- Manifest `permissions` (e.g. `affiliate:manage`) map to admin Role
  capabilities; route middleware enforces them. Unknown permission → 422 at
  install.
- Manifest `config` defaults seed `ConfigurationRegistry` namespaced
  `<id>.<key>` (e.g. `siro/affiliate.commission_rate`). Typed values only
  (bool/int/string/url); admin edits through the existing config surface.
- Extension services resolve core dependencies ONLY via container contracts
  (repositories/stores), never by instantiating core models across the
  boundary, never by raw SQL into core tables (reads via contract queries ok).

## 8. UI slots (RULE-E8, v1 scope)

- Admin: exactly one slot — **Extensions section nav**, one entry per enabled
  extension (`label`, `href` → its admin page under `/extensions/<id>`).
  No top-level menus, no dashboard widgets in v1.
- Storefront/my-account: NO extension-owned UI in v1. Extensions expose data
  via their API; merchandisers surface it with theme sections + experiments.
- Extension admin pages reuse admin primitives (`PageHeader`, `ApiState`,
  capability gates) and the shared scope/auth boundary.

## 9. Versioning & compatibility (RULE-E9)

- Semver mandatory. `requires: {core, commerce}` checked at
  register/enable/upgrade — mismatch blocks with message, never half-installs.
- Upgrade runs new migrations, then flips state to `enabled`. Downgrade is not
  supported: uninstall (keep data) + install older version.
- Core guarantees: event names in §6 and manifest schema are stable within a
  commerce major. Breaking core changes ship an upgrade note per extension.

## 10. Test matrix per extension (RULE-E10, mandatory)

| # | Test | Where |
|---|---|---|
| 1 | Manifest valid; bad id/version/requires rejected | Unit |
| 2 | Install → enable → disable → uninstall(keep data) → reinstall reuses data | Feature |
| 3 | Uninstall(removeData=true) drops `ext_*` tables, keeps core intact | Feature |
| 4 | Routes mounted under `/api/ext/<id>`, 401 without auth, 403 without capability | Feature |
| 5 | Mutations require Idempotency-Key; replay is safe | Feature |
| 6 | Listener failure does not break core flow (order still places) | Feature |
| 7 | Upgrade runs new migrations, state ends `enabled` | Feature |
| 8 | Version mismatch blocks enable with message | Unit |

## 11. Failure & security rules (RULE-E11)

- `health(<id>)` runs on enable and on demand; unhealthy → `failed` + admin
  alert, core keeps serving.
- Extension input validation = core rules (length caps, `javascript:`/`data:`
  rejection, XSS scan on stored JSON). Extension output escapes at render.
- Secrets live in env, never in manifest/config rows. No `eval`, no dynamic
  includes from request data, no `..` paths (validated).

## 12. Definition of done for the system

- Loader (§4) + migration runner (§5) + directory discovery (§3) + admin
  Extensions UI (§8) + reference module (affiliate skeleton) + this spec +
  build guide, all green: backend / storefront / admin suites, `next build`,
  deploy, live smoke (enable → call ext route → disable → 404, core green).
