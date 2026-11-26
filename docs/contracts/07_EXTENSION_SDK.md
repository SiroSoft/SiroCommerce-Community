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
`commerce` constraints), and lists of `capabilities`, `permissions`, `migrations`, and `routes`.
The lifecycle service validates these values before discovery, install, enable, or upgrade. Migrations
and routes are metadata only in Wave 1; the host does not execute extension SQL or arbitrary route code.
Unknown runtime requirements, incompatible versions, absolute/traversal paths, and malformed permission
or capability names are rejected. `extension:check` emits deterministic JSON and uses the lifecycle service,
not direct database access.
