<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentCapabilities;
use App\Modules\Payment\Contracts\PaymentProviderRegistry as PaymentProviderRegistryContract;
use App\Modules\Payment\Contracts\PaymentReconciliationProvider;
use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Domain\PaymentOperation;

final class PaymentProviderRegistry implements PaymentProviderRegistryContract, PaymentReconciliationProvider
{
    /** @var array<string, PaymentProvider> */
    private array $providers = [];

    public function __construct(private readonly PaymentTransport $transport)
    {
    }

    public function provider(string $id): PaymentProvider
    {
        $canonical = self::canonical($id);
        if (!isset($this->providers[$canonical])) {
            if ($canonical === 'cod') {
                $this->providers[$canonical] = new \App\Modules\Platform\Infrastructure\CodPaymentProvider(new \App\Contracts\CodPaymentGateway());
            } elseif ($canonical === 'bank_transfer') {
                $this->providers[$canonical] = new \App\Modules\Platform\Infrastructure\BankTransferPaymentProvider(new \App\Contracts\BankTransferGateway());
            } else {
                try {
                    $this->providers[$canonical] = PaymentProviderFactory::fromEnvironment($canonical, $this->transport);
                } catch (\RuntimeException $error) {
                    $rawEnvironment = getenv('APP_ENV');
                    $rawMode = getenv('PAYMENT_MODE');
                    $environment = strtolower(trim(is_string($rawEnvironment) && $rawEnvironment !== '' ? $rawEnvironment : (string) \Siro\Core\Env::get('APP_ENV', 'local')));
                    $mode = strtolower(trim(is_string($rawMode) && $rawMode !== '' ? $rawMode : (string) \Siro\Core\Env::get('PAYMENT_MODE', 'sandbox')));
                    if ($environment === 'production' && $mode !== 'production') throw $error;
                    if (!in_array($mode, ['sandbox', 'production'], true)) throw $error;
                    $this->providers[$canonical] = $mode === 'sandbox'
                        ? new SandboxPaymentProvider($canonical)
                        : new UnavailablePaymentProvider($canonical, $error->getMessage());
                }
            }
        }
        return $this->providers[$canonical];
    }

    public function execute(PaymentOperation $operation, array $context): array
    {
        return $this->provider(is_string($context['provider'] ?? null) ? $context['provider'] : 'cod')->execute($operation, $context);
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $provider = '';
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'x-payment-provider' && is_string($value)) {
                $provider = trim($value);
                break;
            }
        }
        return $provider !== '' && $this->provider($provider)->verifyWebhook($rawBody, $headers);
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    public function reconcile(array $operation): array
    {
        $provider = $operation['provider'] ?? 'cod';
        return $this->provider(is_string($provider) ? $provider : 'cod') instanceof PaymentReconciliationProvider
            ? $this->provider(is_string($provider) ? $provider : 'cod')->reconcile($operation)
            : throw new \RuntimeException('Payment provider cannot reconcile');
    }

    /** @return array<int, string> */
    public function providers(): array
    {
        return ['cod', 'bank_transfer', 'stripe', 'paypal', 'adyen', 'authorize_net', 'square', 'vnpay', 'momo'];
    }

    /**
     * Configuration-only status for checkout/admin surfaces. It deliberately
     * exposes no credential values and never probes a live provider.
     *
     * @return array<string, mixed>
     */
    public function readiness(string $id): array
    {
        $canonical = self::canonical($id);
        $provider = $this->provider($canonical);
        $available = !($provider instanceof UnavailablePaymentProvider);
        $mode = $provider instanceof SandboxPaymentProvider ? 'sandbox' : (in_array($canonical, ['cod', 'bank_transfer'], true) ? 'local' : 'production');
        return [
            'provider' => $canonical,
            'available' => $available,
            'mode' => $mode,
            'capabilities' => $provider instanceof PaymentCapabilities ? $provider->capabilities() : [],
            'reason' => $provider instanceof UnavailablePaymentProvider ? 'provider.unavailable' : '',
        ];
    }

    public static function canonical(string $id): string
    {
        $normalized = strtolower(trim(str_replace(['-', '.', ' '], '_', $id)));
        return match ($normalized) {
            'authorize', 'authorizenet', 'authorize_net' => 'authorize_net',
            'paypal' => 'paypal',
            'stripe' => 'stripe',
            'adyen' => 'adyen',
            'square' => 'square',
            'vnpay' => 'vnpay',
            'momo' => 'momo',
            'cod' => 'cod',
            'bank_transfer', 'banktransfer' => 'bank_transfer',
            default => throw new \InvalidArgumentException('Unsupported payment provider: ' . $id),
        };
    }
}
