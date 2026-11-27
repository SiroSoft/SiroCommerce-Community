# Extension Build Guide — From Zero To Enabled (affiliate walkthrough)

Companion to `docs/contracts/EXTENSION_PACKAGING_SPEC.md` (rules) — this is
the hands-on path. Reference implementation: `app/Extensions/Siro/Affiliate/`.

## 1. Scaffold (10 minutes)

```text
app/Extensions/Siro/Affiliate/
  Extension.php        # id siro/affiliate, version 1.0.0, requires core/commerce
  routes.php           # POST /referrals/attribute, GET /referrals, GET /ledger
  migrations/20261007000001_create_ext_affiliate_tables.php
  src/AffiliateService.php      # codes, attribution, commission accrual
  src/AffiliateController.php   # thin HTTP layer (validate → service)
  tests/AffiliateTest.php       # RULE-E10 matrix rows 2-6 minimum
  README.md
```

`Extension.php` essentials:

```php
public static function id(): string { return 'siro/affiliate'; }
public static function compatibleCore(): string { return '^1.0'; }
public static function compatibleCommerce(): string { return '^1.0'; }
public function manifest(): array {
  return ['id' => self::id(), 'version' => '1.0.0',
    'requires' => ['core' => self::compatibleCore(), 'commerce' => self::compatibleCommerce()], // today: core ^1.0, commerce ^0.1
    'capabilities' => ['affiliate:track', 'affiliate:manage'],
    'permissions' => ['affiliate:manage'],
    'migrations' => ['migrations/20261007000001_create_ext_affiliate_tables.php'],
    'routes' => ['routes.php'],
    'config' => ['commission_rate' => 0.05]];
}
public function config(): array { return ['commission_rate' => 0.05]; }
public function paymentGateways(): array { return []; }
public function shippingProviders(): array { return []; }
```

Tables MUST be `ext_*`: `ext_affiliate_referrers(code, customer_id, rate, state)`,
`ext_affiliate_attributions(order_id unique, code, attributed_at)`,
`ext_affiliate_ledger(id, order_id, code, amount_minor, currency, state)`.

## 2. Accrue commission without touching core (RULE-E6)

```php
AffiliateService::listen(true); // shutdown() flips it back to false
\Siro\Core\Event::on('payment.captured', [AffiliateService::class, 'onPaymentCaptured']);
```

TRAP: never call `Event::off('payment.captured')` in `shutdown()` — it removes
CORE listeners too (analytics, marketing automation). Keep the listener
registered and self-gate on the enabled flag instead.

Register the listener in `Extension::boot()` (called once when the extension is
enabled; `shutdown()` flips the flag). Accrual is idempotent on `order_id`
(unique key) so replays and retries are safe. Commission = `rate ×
grand_total_minor`, rounded down, ledger state `pending` → finance confirms
payout (out of scope for the skeleton).

Referral capture: storefront appends `?ref=CODE`; checkout calls
`POST /api/ext/siro/affiliate/referrals/attribute {order_id, code}` (auth +
idempotency required). Attribution binds first-touch per order (unique
`order_id`, later touches ignored).

## 3. Routes (RULE-E4)

`routes.php` returns rows the loader mounts under `/api/ext/siro/affiliate/`:

```php
return [
  ['POST', 'referrals/attribute', [AffiliateController::class, 'attribute'], ['auth', 'throttle:20,1']],
  ['GET', 'referrals', [AffiliateController::class, 'referrals'], ['auth', 'cap:affiliate:manage']],
  ['GET', 'ledger', [AffiliateController::class, 'ledger'], ['auth', 'cap:affiliate:manage']],
];
```

Loader adds scope/throttle/idempotency/audit automatically; collisions with
core fail the enable with a message.

**Public routes:** a mutating route that must serve anonymous guests (e.g.
a guest subscribe endpoint) carries the explicit `'public'` token instead
of `'auth'`/`'cap:…'`:

```php
['POST', 'subscribe', [SubscribeController::class, 'subscribe'], ['throttle:20,1', 'public']],
```

The loader mounts it without auth middleware, and QIT `route-auth`
accepts the marker. Never combine `'public'` with `'auth'`/`'cap:…'`
(auth would win at runtime, so the combination would be a lie).
Core precedent: `POST /api/carts`, `POST /api/checkout/place` are
guest-capable mutations.

## 4. Enable / operate / remove

Private Composer install is supported without editing core. The vendor package
declares its entry class:

```json
{
  "name": "acme/shipping-fees",
  "autoload": { "psr-4": { "Acme\\ShippingFees\\": "src/" } },
  "extra": { "siro": { "extension": "Acme\\ShippingFees\\Extension" } }
}
```

```bash
composer require acme/shipping-fees
php siro extension:check   # discovered alongside app/Extensions packages
```

The class must implement `App\Contracts\ExtensionInterface`; migrations/routes
resolve relative to the class file, so packages keep the same manifest and QIT
rules as zip packages.

```bash
php siro extension:pack app/Extensions/Siro/Affiliate --out=dist/siro-affiliate-1.0.0.zip
php siro extension:verify dist/siro-affiliate-1.0.0.zip
php siro extension:qit dist/siro-affiliate-1.0.0.zip
php siro extension:install dist/siro-affiliate-1.0.0.zip
php siro extension:check            # validate manifests
php siro migrate                    # runs ext_* migrations after core
# admin → Extensions → siro/affiliate → Install → Enable
# ... use it ...
# Disable (code off, data kept) → Uninstall keep data → reinstall reuses it
# Uninstall remove data → ext_affiliate_* dropped, core untouched
```

Bagisto packages are Laravel/Blade and cannot run unchanged. Get a deterministic
port report plus a Siro manifest draft first:

```bash
php siro extension:compat /path/to/bagisto-package
```

See [`EXTENSION_ECOSYSTEM_SPEC.md`](../contracts/EXTENSION_ECOSYSTEM_SPEC.md) for
signing, checksums, license and the Bagisto compatibility matrix.

Health is checked on enable and on demand; `failed` never blocks core traffic.

For SemVer rules, upgrade/rollback procedures and the operator checklist, see
[`EXTENSION_UPDATES.md`](../operations/EXTENSION_UPDATES.md).

## 5. Admin UI (RULE-E8)

One nav entry under **Extensions** → page lists referrers + ledger with the
standard `PageHeader`/`ApiState`/capability-gate primitives. No top-level menu.

## 6. Long-lived runtime rules (php-server/FrankenPHP, static-state audit)

`boot()` runs on EVERY request — it must be idempotent:

- Guard listener registration with a static `$booted` flag (registering twice
  piles up duplicate listeners).
- Never call `Event::off($event)` — it removes core listeners; self-gate on an
  enabled flag flipped by `boot()`/`shutdown()` instead.
- Mutable statics (`$listening`, registries) must have a reset path used by
  tests (`tearDown`) — audit with: `grep -rn "private static \$" app/`.
- Registry re-registration must be a no-op when already registered
  (`ExtensionRegistry::extension($id) !== null` guard in boot paths).

## 7. Publish checklist (spec §10-11)

- [ ] RULE-E10 rows 1-8 green inside the main suites
- [ ] `extension:check` clean on a fresh checkout
- [ ] Enable → smoke the three routes → disable → routes 404, core green
- [ ] Uninstall remove-data on staging leaves zero `ext_*` tables
- [ ] README documents config keys + uninstall behavior
- [ ] No secrets in manifest/config rows; inputs validated like core
