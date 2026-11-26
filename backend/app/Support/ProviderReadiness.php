<?php

declare(strict_types=1);

namespace App\Support;

use Siro\Core\Env;

/** Configuration-only readiness checks. Values and secrets are never returned. */
final class ProviderReadiness
{
    /** @return array<string, mixed> */
    public static function check(): array
    {
        $configuredEnvironment = getenv('APP_ENV');
        $environment = strtolower(trim(is_string($configuredEnvironment) && $configuredEnvironment !== '' ? $configuredEnvironment : (string) Env::get('APP_ENV', 'local')));
        $production = $environment === 'production';
        $paymentMode = strtolower(trim((string) Env::get('PAYMENT_MODE', $production ? 'production' : 'sandbox')));
        $shippingProvider = strtolower(trim((string) Env::get('SHIPPING_DEFAULT_PROVIDER', 'dhl')));
        $shippingMode = match ($shippingProvider) {
            'ghn' => strtolower(trim((string) Env::get('GHN_ENV', $production ? 'production' : 'sandbox'))),
            'viettelpost' => str_contains(strtolower((string) Env::get('VIETTELPOST_API_URL', '')), 'sandbox') ? 'sandbox' : 'production',
            default => strtolower(trim((string) Env::get('DHL_MODE', $production ? 'api' : 'sandbox'))),
        };
        $paymentProvider = strtolower(trim((string) Env::get('PAYMENT_DEFAULT_PROVIDER', 'stripe')));
        $taxProvider = strtolower(trim((string) Env::get('TAX_PROVIDER', 'native')));
        $messageProvider = strtolower(trim((string) Env::get('TRANSACTIONAL_MESSAGE_PROVIDER', 'native')));
        $messageMode = strtolower(trim((string) Env::get('TRANSACTIONAL_MESSAGE_MODE', $production ? 'production' : 'sandbox')));
        $missing = [];
        if (!in_array($paymentMode, ['sandbox', 'production'], true)) $missing[] = 'PAYMENT_MODE=sandbox|production';
        if (!in_array($shippingProvider, ['dhl', 'ghn', 'viettelpost'], true)) $missing[] = 'SHIPPING_DEFAULT_PROVIDER=dhl';
        if ($shippingProvider === 'dhl' && !in_array($shippingMode, ['sandbox', 'api'], true)) $missing[] = 'DHL_MODE=sandbox|api';
        if ($shippingProvider === 'ghn' && !in_array($shippingMode, ['sandbox', 'production'], true)) $missing[] = 'GHN_ENV=sandbox|production';
        if (!in_array($paymentProvider, ['cod', 'stripe', 'paypal', 'adyen', 'authorize_net', 'square', 'vnpay', 'momo'], true)) $missing[] = 'PAYMENT_DEFAULT_PROVIDER';
        if (!in_array($taxProvider, ['native'], true)) $missing[] = 'TAX_PROVIDER=native';
        if ($messageProvider !== 'native') $missing[] = 'TRANSACTIONAL_MESSAGE_PROVIDER=native';
        if (!in_array($messageMode, ['sandbox', 'production'], true)) $missing[] = 'TRANSACTIONAL_MESSAGE_MODE=sandbox|production';
        if ($production && $messageMode !== 'production') $missing[] = 'TRANSACTIONAL_MESSAGE_MODE=production';
        if ($production && $paymentMode !== 'production') $missing[] = 'PAYMENT_MODE=production';
        if ($production && $paymentProvider !== 'cod') foreach (self::paymentCredentials($paymentProvider) as $keys) {
            $configured = false;
            foreach ($keys as $key) if (trim((string) Env::get($key, '')) !== '') { $configured = true; break; }
            if (!$configured) $missing[] = $keys[0];
        }
        if ($production && $shippingProvider === 'dhl') {
            if ($shippingMode !== 'api') $missing[] = 'DHL_MODE=api';
            $shippingUrl = trim((string) Env::get('DHL_API_URL', ''));
            if ($shippingUrl === '' || filter_var($shippingUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($shippingUrl, 'https://')) $missing[] = 'DHL_API_URL=https://...';
            foreach (['DHL_API_KEY', 'DHL_API_SECRET', 'DHL_WEBHOOK_SECRET'] as $key) if (trim((string) Env::get($key, '')) === '') $missing[] = $key;
        }
        if ($production && $shippingProvider === 'ghn') {
            if ($shippingMode !== 'production') $missing[] = 'GHN_ENV=production';
            foreach (['GHN_TOKEN', 'GHN_SHOP_ID'] as $key) if (trim((string) Env::get($key, '')) === '') $missing[] = $key;
        }
        if ($production && $shippingProvider === 'viettelpost') {
            foreach (['VIETTELPOST_USERNAME', 'VIETTELPOST_PASSWORD'] as $key) if (trim((string) Env::get($key, '')) === '') $missing[] = $key;
        }
        $missing = array_values(array_unique($missing));
        // Boolean only — never a secret. Lets ops see at /health/providers
        // whether password-reset/verification emails can actually be sent.
        $result = ['environment' => $environment, 'payment' => ['provider' => $paymentProvider, 'mode' => $paymentMode], 'shipping' => ['provider' => $shippingProvider, 'mode' => $shippingMode], 'tax' => ['provider' => $taxProvider], 'transactional_messaging' => ['provider' => $messageProvider, 'mode' => $messageMode], 'transactional_email' => ['configured' => Mailer::isConfigured()], 'missing' => $missing, 'ready' => $missing === [], 'config_ready' => $missing === [], 'status' => 'BLOCKED_EXTERNAL'];
        $prerequisite = $missing === [] ? 'Independently captured live provider evidence' : implode(', ', $missing);
        $result['blocker'] = EvidenceStatus::blockedExternal('Provider live calls are not release evidence and remain blocked until independently verified.', 'Provider Operations', $prerequisite, 'PROVIDER_LIVE_SMOKE=1 php scripts/provider-live-smoke.php');
        return $result;
    }

    /** @return array<int, array<int, string>> */
    private static function paymentCredentials(string $provider): array
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
