<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Contracts\PaymentTransportResponse;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Domain\PaymentProviderConfig;
use App\Modules\Payment\Infrastructure\MomoPaymentProvider;
use App\Modules\Payment\Infrastructure\PaymentProviderFactory;
use App\Modules\Payment\Infrastructure\VnPayPaymentProvider;
use App\Modules\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class VnPaymentProviderTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_ENV', 'PAYMENT_MODE', 'VNPAY_TMN_CODE', 'VNPAY_HASH_SECRET', 'MOMO_PARTNER_CODE', 'MOMO_ACCESS_KEY', 'MOMO_SECRET_KEY', 'MOMO_API_URL'] as $key) {
            $this->env[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    public function testVnPayCreatesSignedPaymentUrlAndRejectsTampering(): void
    {
        $provider = new VnPayPaymentProvider(new PaymentProviderConfig('vnpay', 'sandbox', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html', [
            'tmn_code' => 'TMNCODE',
            'hash_secret' => 'hash-secret',
        ]));
        $result = $provider->execute(
            new PaymentOperation('op-vnpay', 'pay-vnpay', 'authorize', new Money(100000, 'VND'), 'idem-vnpay'),
            ['return_url' => 'https://shop.test/payment/return', 'ip_addr' => '203.0.113.10', 'create_date' => '20260101120000', 'order_info' => 'Order pay-vnpay'],
        );

        self::assertSame('pay-vnpay', $result['provider_ref']);
        self::assertSame('pending', $result['status']);
        self::assertStringStartsWith('https://sandbox.vnpayment.vn/paymentv2/vpcpay.html?', (string) $result['redirect_url']);
        $query = (string) parse_url((string) $result['redirect_url'], PHP_URL_QUERY);
        $params = [];
        parse_str($query, $params);
        self::assertSame('TMNCODE', $params['vnp_TmnCode'] ?? null);
        self::assertSame('10000000', $params['vnp_Amount'] ?? null);
        self::assertSame('pay-vnpay', $params['vnp_TxnRef'] ?? null);
        self::assertSame('20260101120000', $params['vnp_CreateDate'] ?? null);

        $given = (string) ($params['vnp_SecureHash'] ?? '');
        unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);
        ksort($params);
        self::assertSame($this->hashVnPay($params, 'hash-secret'), $given);
        self::assertTrue($provider->verifyWebhook($query, []));
        self::assertFalse($provider->verifyWebhook(str_replace('vnp_Amount=10000000', 'vnp_Amount=1', $query), []));
    }

    public function testVnPayMissingCredentialsFailClearlyWithoutLeakingSecrets(): void
    {
        $this->setEnv([
            'APP_ENV' => 'local',
            'PAYMENT_MODE' => 'sandbox',
            'VNPAY_TMN_CODE' => '',
            'VNPAY_HASH_SECRET' => '',
        ]);

        try {
            PaymentProviderFactory::fromEnvironment('vnpay', new FakeVnPaymentTransport());
            self::fail('Missing VNPay credentials must fail');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('vnpay.tmn_code', $error->getMessage());
            self::assertStringNotContainsString('hash-secret', $error->getMessage());
        }
    }

    public function testMomoCreatesSignedPaymentRequestAndVerifiesSignature(): void
    {
        $transport = new FakeVnPaymentTransport(new PaymentTransportResponse(200, '{"resultCode":0,"message":"Success","payUrl":"https://test-payment.momo.vn/pay/abc","orderId":"pay-momo"}'));
        $provider = new MomoPaymentProvider(new PaymentProviderConfig('momo', 'sandbox', 'https://test-payment.momo.vn', [
            'partner_code' => 'PARTNER',
            'access_key' => 'ACCESS',
            'secret_key' => 'SECRET',
        ]), $transport);

        $result = $provider->execute(
            new PaymentOperation('op-momo', 'pay-momo', 'authorize', new Money(75000, 'VND'), 'idem-momo'),
            ['redirect_url' => 'https://shop.test/payment/return', 'ipn_url' => 'https://shop.test/payment/ipn'],
        );

        self::assertSame('pay-momo', $result['provider_ref']);
        self::assertSame('pending', $result['status']);
        self::assertSame('https://test-payment.momo.vn/pay/abc', $result['redirect_url']);
        self::assertSame('https://test-payment.momo.vn/v2/gateway/api/create', $transport->url);
        $payload = json_decode($transport->body, true);
        self::assertIsArray($payload);
        $given = (string) ($payload['signature'] ?? '');
        unset($payload['signature']);
        ksort($payload);
        $parts = [];
        foreach ($payload as $key => $value) $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : '');
        self::assertSame(hash_hmac('sha256', implode('&', $parts), 'SECRET'), $given);

        $webhook = [
            'partnerCode' => 'PARTNER',
            'accessKey' => 'ACCESS',
            'orderId' => 'pay-momo',
            'requestId' => 'op-momo',
            'amount' => '75000',
            'resultCode' => '0',
            'message' => 'Success',
            'orderInfo' => 'Payment pay-momo',
            'orderType' => 'momo_wallet',
            'payType' => 'qr',
            'responseTime' => '1767225600000',
            'transId' => '123456',
            'extraData' => '',
        ];
        ksort($webhook);
        $parts = [];
        foreach ($webhook as $key => $value) $parts[] = $key . '=' . $value;
        $webhook['signature'] = hash_hmac('sha256', implode('&', $parts), 'SECRET');
        self::assertTrue($provider->verifyWebhook((string) json_encode($webhook), []));
        $webhook['amount'] = '1';
        self::assertFalse($provider->verifyWebhook((string) json_encode($webhook), []));
    }

    public function testMomoMissingCredentialsFailClearlyWithoutLeakingSecrets(): void
    {
        $this->setEnv([
            'APP_ENV' => 'local',
            'PAYMENT_MODE' => 'sandbox',
            'MOMO_PARTNER_CODE' => '',
            'MOMO_ACCESS_KEY' => '',
            'MOMO_SECRET_KEY' => '',
        ]);

        try {
            PaymentProviderFactory::fromEnvironment('momo', new FakeVnPaymentTransport());
            self::fail('Missing MoMo credentials must fail');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('momo.partner_code', $error->getMessage());
            self::assertStringNotContainsString('SECRET', $error->getMessage());
        }
    }

    public function testFactoryResolvesVnProvidersWhenConfigured(): void
    {
        $this->setEnv([
            'APP_ENV' => 'local',
            'PAYMENT_MODE' => 'sandbox',
            'VNPAY_TMN_CODE' => 'TMN',
            'VNPAY_HASH_SECRET' => 'VN_SECRET',
            'MOMO_PARTNER_CODE' => 'MOMO',
            'MOMO_ACCESS_KEY' => 'MOMO_ACCESS',
            'MOMO_SECRET_KEY' => 'MOMO_SECRET',
            'MOMO_API_URL' => 'https://test-payment.momo.vn',
        ]);
        $transport = new FakeVnPaymentTransport();

        self::assertInstanceOf(VnPayPaymentProvider::class, PaymentProviderFactory::fromEnvironment('vnpay', $transport));
        self::assertInstanceOf(MomoPaymentProvider::class, PaymentProviderFactory::fromEnvironment('momo', $transport));
    }

    /** @param array<string, string> $params */
    private function hashVnPay(array $params, string $secret): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) $parts[] = urlencode($key) . '=' . urlencode($value);
        return hash_hmac('sha512', implode('&', $parts), $secret);
    }

    /** @param array<string, string> $values */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

final class FakeVnPaymentTransport implements PaymentTransport
{
    public string $method = '';
    public string $url = '';
    /** @var array<string, string> */
    public array $headers = [];
    public string $body = '';

    public function __construct(private readonly ?PaymentTransportResponse $response = null)
    {
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
    {
        $this->method = $method;
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        return $this->response ?? new PaymentTransportResponse(200, '{}');
    }
}
