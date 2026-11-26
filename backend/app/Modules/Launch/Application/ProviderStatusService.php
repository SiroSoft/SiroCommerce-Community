<?php

declare(strict_types=1);

namespace App\Modules\Launch\Application;

use App\Support\Mailer;
use Siro\Core\Env;

/**
 * Configuration-only provider status for the launch checklist.
 *
 * Only provider names and booleans leave this service. Credential values are
 * never read into the response, logged, or echoed in hints.
 */
final class ProviderStatusService
{
    /** @return array{
     *   payment: array{provider: string, mode: string, configured: bool},
     *   shipping: array{provider: string, mode: string, configured: bool},
     *   mail: array{provider: string, configured: bool},
     *   search: array{provider: string, url_configured: bool, configured: bool},
     *   ai: array{provider: string, budget_configured: bool, configured: bool}
     * }
     */
    public function status(): array
    {
        $paymentProvider = $this->paymentProvider();
        $paymentMode = $this->paymentMode();

        $shippingProvider = $this->shippingProvider();
        $shippingMode = $this->shippingMode($shippingProvider);

        $searchProvider = strtolower(trim((string) Env::get('CATALOG_SEARCH_PROVIDER', 'native')));
        $searchUrlConfigured = trim((string) Env::get('SEARCH_URL', '')) !== '';

        $aiProvider = strtolower(trim((string) Env::get('AI_PROVIDER', 'unavailable')));
        $aiBudgetConfigured = max(0, (int) Env::get('AI_BUDGET_MINOR', '0')) > 0;

        return [
            'payment' => [
                'provider' => $paymentProvider,
                'mode' => $paymentMode,
                'configured' => $this->paymentConfigured($paymentProvider),
            ],
            'shipping' => [
                'provider' => $shippingProvider,
                'mode' => $shippingMode,
                'configured' => $this->shippingConfigured($shippingProvider),
            ],
            'mail' => [
                'provider' => trim((string) Env::get('MAIL_PROVIDER', '')) ?: 'smtp',
                'configured' => Mailer::isConfigured(),
            ],
            'search' => [
                'provider' => $searchProvider,
                'url_configured' => $searchUrlConfigured,
                'configured' => $this->searchConfigured($searchProvider),
            ],
            'ai' => [
                'provider' => $aiProvider,
                'budget_configured' => $aiBudgetConfigured,
                'configured' => $this->aiConfigured($aiProvider, $aiBudgetConfigured),
            ],
        ];
    }

    public function paymentProvider(): string
    {
        return strtolower(trim((string) Env::get('PAYMENT_DEFAULT_PROVIDER', 'stripe')));
    }

    public function paymentMode(): string
    {
        $environment = strtolower(trim((string) Env::get('APP_ENV', 'local')));
        return strtolower(trim((string) Env::get('PAYMENT_MODE', $environment === 'production' ? 'production' : 'sandbox')));
    }

    public function paymentConfigured(string $provider): bool
    {
        if (in_array($provider, ['cod', 'bank_transfer'], true)) {
            return true;
        }
        if (!in_array($provider, ['stripe', 'paypal', 'adyen', 'authorize_net', 'square', 'vnpay', 'momo'], true)) {
            return false;
        }
        foreach ($this->paymentCredentialKeys($provider) as $keys) {
            $configured = false;
            foreach ($keys as $key) {
                if (trim((string) Env::get($key, '')) !== '') {
                    $configured = true;
                    break;
                }
            }
            if (!$configured) {
                return false;
            }
        }
        return true;
    }

    public function shippingProvider(): string
    {
        $provider = trim((string) Env::get('SHIPPING_PROVIDER', ''));
        if ($provider === '') {
            $provider = trim((string) Env::get('SHIPPING_DEFAULT_PROVIDER', 'native'));
        }
        return strtolower($provider === '' ? 'native' : $provider);
    }

    public function shippingConfigured(string $provider): bool
    {
        if ($provider === 'native') {
            return true;
        }
        if ($provider === 'ghn') {
            return trim((string) Env::get('GHN_TOKEN', '')) !== ''
                && trim((string) Env::get('GHN_SHOP_ID', '')) !== '';
        }
        if ($provider === 'viettelpost') {
            return trim((string) Env::get('VIETTELPOST_USERNAME', '')) !== ''
                && trim((string) Env::get('VIETTELPOST_PASSWORD', '')) !== '';
        }
        if ($provider !== 'dhl') {
            return false;
        }
        $url = trim((string) Env::get('DHL_API_URL', ''));
        return trim((string) Env::get('DHL_API_KEY', '')) !== ''
            && trim((string) Env::get('DHL_API_SECRET', '')) !== ''
            && $url !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function shippingMode(string $provider): string
    {
        return match ($provider) {
            'ghn' => strtolower(trim((string) Env::get('GHN_ENV', 'sandbox'))),
            'viettelpost' => str_contains(strtolower((string) Env::get('VIETTELPOST_API_URL', '')), 'sandbox') ? 'sandbox' : 'production',
            default => strtolower(trim((string) Env::get('DHL_MODE', 'sandbox'))),
        };
    }

    public function searchConfigured(string $provider): bool
    {
        if ($provider === 'native') {
            return true;
        }
        if (!in_array($provider, ['meili', 'meilisearch', 'opensearch', 'elasticsearch'], true)) {
            return false;
        }
        return trim((string) Env::get('SEARCH_URL', '')) !== ''
            && trim((string) Env::get('SEARCH_API_KEY', '')) !== '';
    }

    private function aiConfigured(string $provider, bool $budgetConfigured): bool
    {
        if ($provider === 'unavailable' || $provider === '') {
            return false;
        }
        if (!$budgetConfigured) {
            return false;
        }
        if ($provider === 'ollama') {
            return trim((string) Env::get('AI_OLLAMA_URL', (string) Env::get('AI_BASE_URL', ''))) !== '';
        }
        return trim((string) Env::get('AI_BASE_URL', '')) !== ''
            && trim((string) Env::get('AI_API_KEY', '')) !== '';
    }

    /** @return array<int, array<int, string>> */
    private function paymentCredentialKeys(string $provider): array
    {
        return match ($provider) {
            'stripe' => [['STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY'], ['STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET']],
            'paypal' => [['PAYPAL_ACCESS_TOKEN', 'PAYMENT_PAYPAL_ACCESS_TOKEN'], ['PAYPAL_WEBHOOK_SECRET', 'PAYMENT_PAYPAL_WEBHOOK_SECRET']],
            'adyen' => [['ADYEN_API_KEY', 'PAYMENT_ADYEN_API_KEY'], ['ADYEN_WEBHOOK_SECRET', 'PAYMENT_ADYEN_WEBHOOK_SECRET']],
            'authorize_net' => [['AUTHORIZENET_TRANSACTION_KEY', 'PAYMENT_AUTHORIZENET_API_KEY'], ['AUTHORIZENET_WEBHOOK_SECRET', 'PAYMENT_AUTHORIZENET_WEBHOOK_SECRET']],
            'square' => [['SQUARE_ACCESS_TOKEN', 'PAYMENT_SQUARE_ACCESS_TOKEN'], ['SQUARE_WEBHOOK_SIGNATURE_KEY', 'PAYMENT_SQUARE_WEBHOOK_SECRET']],
            'vnpay' => [['VNPAY_TMN_CODE'], ['VNPAY_HASH_SECRET']],
            'momo' => [['MOMO_PARTNER_CODE'], ['MOMO_ACCESS_KEY'], ['MOMO_SECRET_KEY']],
            default => [['PAYMENT_DEFAULT_PROVIDER']],
        };
    }
}
