<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure;

use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Domain\PaymentProviderConfig;
use Siro\Core\Env;

final class PaymentProviderFactory
{
    public static function fromEnvironment(string $provider, PaymentTransport $transport): PaymentProvider
    {
        $environment = strtolower((string) Env::get('APP_ENV', 'local'));
        $mode = strtolower(trim((string) Env::get('PAYMENT_MODE', $environment === 'production' ? 'production' : 'sandbox')));
        if ($environment === 'production' && $mode !== 'production') {
            throw new \RuntimeException('Sandbox payment mode is forbidden in production');
        }
        if (!in_array($mode, ['sandbox', 'production'], true)) throw new \RuntimeException('Payment mode must be sandbox or production');
        $momoUrl = trim((string) Env::get('MOMO_API_URL', ''));
        if ($momoUrl === '') $momoUrl = $mode === 'production' ? 'https://payment.momo.vn' : 'https://test-payment.momo.vn';
        /** @var array<string, array{0: class-string, 1: string, 2: array<string, array<int, string>>}> $definitions */
        $definitions = [
            'stripe' => [StripePaymentProvider::class, 'https://api.stripe.com', ['secret_key' => ['STRIPE_SECRET_KEY', 'PAYMENT_STRIPE_SECRET_KEY'], 'webhook_secret' => ['STRIPE_WEBHOOK_SECRET', 'PAYMENT_STRIPE_WEBHOOK_SECRET']]],
            'paypal' => [PayPalPaymentProvider::class, $mode === 'production' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com', ['access_token' => ['PAYPAL_ACCESS_TOKEN', 'PAYMENT_PAYPAL_ACCESS_TOKEN'], 'webhook_secret' => ['PAYPAL_WEBHOOK_SECRET', 'PAYMENT_PAYPAL_WEBHOOK_SECRET']]],
            'adyen' => [AdyenPaymentProvider::class, $mode === 'production' ? 'https://checkout-live.adyen.com' : 'https://checkout-test.adyen.com', ['api_key' => ['ADYEN_API_KEY', 'PAYMENT_ADYEN_API_KEY'], 'webhook_secret' => ['ADYEN_WEBHOOK_SECRET', 'PAYMENT_ADYEN_WEBHOOK_SECRET']]],
            'authorize_net' => [AuthorizeNetPaymentProvider::class, $mode === 'production' ? 'https://api2.authorize.net/xml/v1/request.api' : 'https://apitest.authorize.net/xml/v1/request.api', ['api_key' => ['AUTHORIZENET_TRANSACTION_KEY', 'PAYMENT_AUTHORIZENET_API_KEY'], 'webhook_secret' => ['AUTHORIZENET_WEBHOOK_SECRET', 'PAYMENT_AUTHORIZENET_WEBHOOK_SECRET']]],
            'square' => [SquarePaymentProvider::class, $mode === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com', ['access_token' => ['SQUARE_ACCESS_TOKEN', 'PAYMENT_SQUARE_ACCESS_TOKEN'], 'webhook_secret' => ['SQUARE_WEBHOOK_SIGNATURE_KEY', 'PAYMENT_SQUARE_WEBHOOK_SECRET']]],
            'vnpay' => [VnPayPaymentProvider::class, $mode === 'production' ? 'https://pay.vnpay.vn/vpcpay.html' : 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html', ['tmn_code' => ['VNPAY_TMN_CODE'], 'hash_secret' => ['VNPAY_HASH_SECRET']]],
            'momo' => [MomoPaymentProvider::class, $momoUrl, ['partner_code' => ['MOMO_PARTNER_CODE'], 'access_key' => ['MOMO_ACCESS_KEY'], 'secret_key' => ['MOMO_SECRET_KEY']]],
        ];
        if (!isset($definitions[$provider])) {
            throw new \InvalidArgumentException('Unsupported payment provider: ' . $provider);
        }
        [$class, $baseUrl, $names] = $definitions[$provider];
        $credentials = [];
        foreach ($names as $name => $environmentName) {
            $credentials[$name] = '';
            foreach ($environmentName as $candidate) {
                $value = trim((string) Env::get($candidate, ''));
                if ($value !== '') { $credentials[$name] = $value; break; }
            }
        }
        $config = new PaymentProviderConfig($provider, $mode, $baseUrl, $credentials);
        foreach (array_keys($names) as $name) {
            $config->credential($name);
        }
        /** @var class-string<PaymentProvider> $class */
        if ($provider === 'vnpay') return new VnPayPaymentProvider($config);
        return new $class($config, $transport);
    }
}
