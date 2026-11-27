# 07 — Extension SDK

## 1. Contracts (định sớm, V1 không hard-code provider)
```php
interface PaymentGatewayInterface {
  public function authorize(array $order, array $ctx): array;
  public function capture(string $paymentId, array $ctx): array;
  public function refund(string $paymentId, int $amountMinor, array $ctx): array;
  public function cancel(string $paymentId, array $ctx): array;
  public function verifyWebhook(string $rawBody, array $headers): bool;
}
interface ShippingProviderInterface {
  public function getRates(array $shipment, array $ctx): array;
  public function createShipment(array $shipment, array $ctx): array;
  public function cancelShipment(string $shipmentId, array $ctx): array;
  public function track(string $shipmentId, array $ctx): array;
}
// + Tax, Promotion, Inventory, Search, Storage, Notification, Analytics, AI extension points
```

## 2. Lifecycle
Register services, events/listeners, admin pages (slot cho phép), CLI commands, migrations, config.
Khai báo `sirocommerce` + `siro-core` version constraint. Admin surface tuân thủ nav/UX rules.

## 3. Compat tests (bắt buộc cho published extension)
Install/uninstall sạch, upgrade, webhook verify, idempotency, failure (provider timeout) không vỡ core flow, không thêm menu top-level.

## 4. Wave 1 manifest contract
An extension may expose `manifest(): array` with `id`, semantic `version`, `requires` (`core` and
`commerce` constraints), and lists of `capabilities`, `permissions`, `migrations`, `routes`, and
`slots` (`slots.storefront` names must be in `SLOT_NAMES`; unknown names fail install with 422).
`requires_extensions` (list of extension ids, e.g. `["siro/abandoned-cart"]`) declares that this
extension can only be enabled while every listed extension is enabled (dependency guard, §5).
The lifecycle service validates these values before discovery, install, enable, or upgrade. Migrations
and routes are metadata only in Wave 1; the host does not execute extension SQL or arbitrary route code.
Unknown runtime requirements, incompatible versions, absolute/traversal paths, and malformed permission
or capability names are rejected. `extension:check` emits deterministic JSON and uses the lifecycle service,
not direct database access.

## 5. Batch 1 platform contracts (Wave 0 — Platform implements, Ecosystem uses)

### Coupon creation for extensions
- Capability `coupon:create`: declare it in the manifest `permissions`; enforce it with the
  `cap:coupon:create` route middleware. Owner/admin hold every permission an enabled extension
  declares (RULE-E7); other roles never do.
- Public contract: `App\Modules\Coupon\Application\CouponService::create(array $data)`, resolved
  via the container. Validation (same as `CouponController::store()`): code 3–64 characters
  `[A-Za-z0-9_-]` (uppercased on store), `rules` a JSON object (string or array). Violations
  throw `InvalidArgumentException` — map it to 422 in the extension controller.
- Each plugin declares its own route in `routes.php`, e.g.
  `['POST', 'coupons', [CouponsController::class, 'store'], ['cap:coupon:create', 'throttle:20,1']]`
  mounted as `POST /api/ext/<id>/coupons`. Never call the admin API with an admin token.

### Mail templates for extensions
- `App\Modules\Mail\Application\MailTemplateRegistry::register(string $event, string $templateKey, array $variables)`
  in the extension `boot()`; send with `MailTemplateRegistry::send($event, $to, $variables)` (text-only).
  Built-in templates: `abandoned_cart` (variables: `customer_name`, `cart_url`, `lines`, `coupon_code`;
  bound by default to `cart.abandoned`) and `welcome` (variables: `customer_name`, `coupon_code`).
  Only built-in template keys can be registered in batch 1. Never hardcode a template into
  `OrderNotificationListener` (core).

### Marketing consent for extensions
- `ConsentService::hasMarketingConsent(?string $customerId = null, ?string $sessionHash = null): bool`
  reads `CustomerNotificationPreference.marketing_email` for customers (the single source for
  marketing email). Opt-in endpoint: `POST /api/consent/marketing` (logged-in customer or
  `X-Session-Id` header). Send marketing mail only when it returns true.

### Review media
- `ReviewController::store()` accepts `images` (max 5 URLs already uploaded via the media library);
  stored as `images_json` on `product_reviews`. Never accept raw file uploads at the review endpoint.

### Slots
- `SLOT_NAMES` (storefront `apps/storefront/src/slots/types.ts`) is mirrored by
  `ExtensionManifest::SLOT_NAMES` (backend). Declare storefront slots in `manifest.json`
  `slots.storefront`; unknown names fail install (422). Storefront registry:
  `apps/storefront/src/plugins/registry.ts` (one entry per extension id) — never edit
  `SlotPluginBootstrap.tsx` to add a plugin. New slots in batch 1: `product-card`,
  `pdp.title`, `pdp.below-atc`, `pdp.below-gallery`.

### Extension dependencies (dependency guard)
- Declare dependencies in the manifest as `requires_extensions` (list of extension ids).
  The guard is generic — it applies to every extension that declares the field, never
  hardcoded to one plugin. First user: `siro/abandoned-cart-pro` requires `siro/abandoned-cart`.
- Enable: blocked with 422 when any listed extension is not installed or not enabled
  (`Extension <id> requires <dependency> to be enabled`).
- Disable / uninstall: blocked with 422 while any ENABLED extension requires this one
  (`Cannot disable or uninstall <id>: enabled extension(s) <list> require it`). A
  disabled dependent does not block; re-enabling it re-checks the dependency.
- Disable never deletes data: disabling a dependent (e.g. Pro) falls back to the base
  behaviour and keeps its own `ext_*` tables/rows, so re-enable resumes with data intact.
  Only uninstall with `remove_data` drops the extension's own tables.
- Invalid `requires_extensions` values (bad id format, self-reference, duplicates) fail
  manifest validation at install (`ExtensionManifest::fromArray` / `ExtensionPackageInstaller`).
