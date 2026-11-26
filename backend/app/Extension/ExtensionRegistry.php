<?php

declare(strict_types=1);

namespace App\Extension;

use App\Contracts\ExtensionInterface;
use App\Contracts\PaymentGatewayInterface;
use App\Contracts\ShippingProviderInterface;
use App\Contracts\ProviderHealthInterface;
use App\CommerceVersion;
use Siro\Core\Console;

/**
 * Third-party-style extension host (spec docs/07): extensions register
 * without modifying core. Version constraints enforced at registration.
 */
final class ExtensionRegistry
{
    /** @var array<string, ExtensionInterface> */
    private static array $extensions = [];

    /** @var array<string, PaymentGatewayInterface> */
    private static array $payments = [];

    /** @var array<string, ShippingProviderInterface> */
    private static array $shippings = [];

    /** @var array<string, array<string, ExtensionInterface>> */
    private static array $capabilities = [];

    /** @var array<string, array<string, true>> manifest permissions per extension */
    private static array $permissions = [];

    public static function reset(): void
    {
        self::$extensions = [];
        self::$payments = [];
        self::$shippings = [];
        self::$capabilities = [];
        self::$permissions = [];
    }

    public static function register(ExtensionInterface $ext): void
    {
        $coreVersion = Console::VERSION;
        if (!VersionConstraint::satisfies($coreVersion, $ext::compatibleCore())) {
            throw new \RuntimeException(
                'Extension ' . $ext::id() . ' requires core ' . $ext::compatibleCore() . ', have ' . $coreVersion
            );
        }
        if (!VersionConstraint::satisfies(CommerceVersion::VERSION, $ext::compatibleCommerce())) {
            throw new \RuntimeException(
                'Extension ' . $ext::id() . ' requires commerce ' . $ext::compatibleCommerce() . ', have ' . CommerceVersion::VERSION
            );
        }
        if (isset(self::$extensions[$ext::id()])) {
            throw new \RuntimeException('Extension already registered: ' . $ext::id());
        }
        $payments = $ext->paymentGateways();
        $shippings = $ext->shippingProviders();
        // Validate every collision before mutating any registry. A failed install
        // must not leave a half-registered extension or provider.
        foreach ($payments as $id => $_) if (isset(self::$payments[$id])) {
            throw new \RuntimeException('Payment gateway already registered: ' . $id);
        }
        foreach ($shippings as $id => $_) if (isset(self::$shippings[$id])) {
            throw new \RuntimeException('Shipping provider already registered: ' . $id);
        }
        self::$extensions[$ext::id()] = $ext;
        foreach ($payments as $id => $gateway) {
            self::$payments[$id] = $gateway;
        }
        foreach ($shippings as $id => $provider) {
            self::$shippings[$id] = $provider;
        }
        foreach (self::declaredCapabilities($payments, $shippings) as $capability) {
            self::$capabilities[$capability][$ext::id()] = $ext;
        }
        // Manifest permissions (spec RULE-E7): owner/admin hold every enabled
        // extension's permissions; other roles never do.
        if (method_exists($ext, 'manifest')) {
            try {
                $manifest = $ext->manifest();
                $permissions = is_array($manifest) ? ($manifest['permissions'] ?? []) : [];
                foreach (is_array($permissions) ? $permissions : [] as $permission) {
                    if (is_string($permission) && $permission !== '') self::$permissions[$permission][$ext::id()] = true;
                }
            } catch (\Throwable) {
                // Manifest validation already ran at install/enable time.
            }
        }
    }

    /**
     * Extension permission grant (spec RULE-E7): owner and admin hold every
     * permission declared by an enabled extension. Staff roles keep core caps.
     */
    public static function grants(string $role, string $capability): bool
    {
        if ($role !== 'owner' && $role !== 'admin') return false;
        return isset(self::$permissions[$capability]) && self::$permissions[$capability] !== [];
    }

    public static function extension(string $id): ?ExtensionInterface
    {
        return self::$extensions[$id] ?? null;
    }

    public static function unregister(string $id): void
    {
        $extension = self::$extensions[$id] ?? null;
        if ($extension === null) {
            return;
        }
        unset(self::$extensions[$id]);
        foreach (self::$capabilities as $capability => $extensions) {
            unset(self::$capabilities[$capability][$id]);
            if (self::$capabilities[$capability] === []) unset(self::$capabilities[$capability]);
        }
        foreach (self::$permissions as $permission => $extensions) {
            unset(self::$permissions[$permission][$id]);
            if (self::$permissions[$permission] === []) unset(self::$permissions[$permission]);
        }
        foreach ($extension->paymentGateways() as $gatewayId => $gateway) {
            if ((self::$payments[$gatewayId] ?? null) === $gateway) {
                unset(self::$payments[$gatewayId]);
            }
        }
        foreach ($extension->shippingProviders() as $providerId => $provider) {
            if ((self::$shippings[$providerId] ?? null) === $provider) {
                unset(self::$shippings[$providerId]);
            }
        }
    }

    /** @param array<int, class-string<ExtensionInterface>> $classes */
    public static function bootFromConfig(array $classes): void
    {
        foreach ($classes as $class) {
            $ext = new $class();
            if (!isset(self::$extensions[$ext::id()])) {
                self::register($ext);
            }
        }
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_keys(self::$extensions);
    }

    public static function payment(string $id): ?PaymentGatewayInterface
    {
        return self::$payments[$id] ?? null;
    }

    /** @return array<string, PaymentGatewayInterface> */
    public static function payments(): array
    {
        return self::$payments;
    }

    /** @return array<string, ShippingProviderInterface> */
    public static function shippings(): array
    {
        return self::$shippings;
    }

    /** @return array<string, ExtensionInterface> */
    public static function forCapability(string $capability): array
    {
        return self::$capabilities[$capability] ?? [];
    }

    public static function hasCapability(string $extensionId, string $capability): bool
    {
        return isset(self::$capabilities[$capability][$extensionId]);
    }

    /** @return array<string, PaymentGatewayInterface|ShippingProviderInterface> */
    public static function providers(string $capability): array
    {
        return match ($capability) {
            'payment.provider' => self::$payments,
            'shipping.provider' => self::$shippings,
            default => [],
        };
    }

    /** @return array<string, array{ok: bool, message: string, details: array<string, mixed>}> */
    public static function providerHealth(string $capability): array
    {
        $result = [];
        foreach (self::providers($capability) as $id => $provider) {
            $result[$id] = $provider instanceof ProviderHealthInterface
                ? $provider->health()
                : ['ok' => true, 'message' => 'Provider is registered', 'details' => []];
        }
        return $result;
    }

    /** @return list<string> */
    /** @param array<string, PaymentGatewayInterface> $payments
     * @param array<string, ShippingProviderInterface> $shippings
     * @return list<string>
     */
    private static function declaredCapabilities(array $payments, array $shippings): array
    {
        $capabilities = ['extension'];
        if ($payments !== []) $capabilities[] = 'payment.provider';
        if ($shippings !== []) $capabilities[] = 'shipping.provider';
        return $capabilities;
    }
}
