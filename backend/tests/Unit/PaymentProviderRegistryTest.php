<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Payment\Contracts\PaymentTransport;
use App\Modules\Payment\Contracts\PaymentTransportResponse;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Payment\Domain\PaymentProviderConfig;
use App\Modules\Payment\Infrastructure\PaymentProviderRegistry;
use App\Modules\Payment\Infrastructure\StripePaymentProvider;
use App\Modules\Payment\Infrastructure\UnavailablePaymentProvider;
use App\Modules\Payment\Infrastructure\SandboxPaymentProvider;
use App\Modules\Payment\Application\PaymentCommandService;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentStore;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class PaymentProviderRegistryTest extends TestCase
{
    private string|false $previousMode = false;
    private string|false $previousEnvironment = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousMode = getenv('PAYMENT_MODE');
        $this->previousEnvironment = getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        if ($this->previousMode === false) {
            putenv('PAYMENT_MODE');
        } else {
            putenv('PAYMENT_MODE=' . $this->previousMode);
        }
        if ($this->previousEnvironment === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnvironment);
        }
        putenv('PAYMENT_STRIPE_SECRET_KEY');
        putenv('PAYMENT_STRIPE_WEBHOOK_SECRET');
        parent::tearDown();
    }

    public function testProviderAliasesResolveToCanonicalIds(): void
    {
        $registry = new PaymentProviderRegistry(new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(200, '{"state":"authorized","provider_ref":"ref"}');
            }
        });
        self::assertSame('authorize_net', PaymentProviderRegistry::canonical('Authorize.Net'));
        self::assertSame('bank_transfer', PaymentProviderRegistry::canonical('bank-transfer'));
        self::assertSame(['cod', 'bank_transfer', 'stripe', 'paypal', 'adyen', 'authorize_net', 'square', 'vnpay', 'momo'], $registry->providers());
    }

    public function testConfiguredAdapterUsesTransportAndStableOperationContract(): void
    {
        $transport = new class implements PaymentTransport {
            public string $url = '';
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                $this->url = $url;
                \PHPUnit\Framework\Assert::assertSame('k-1', $headers['Idempotency-Key']);
                return new PaymentTransportResponse(200, '{"state":"authorized","provider_ref":"stripe-1"}');
            }
        };
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', [
            'secret_key' => 'secret', 'webhook_secret' => 'hook',
        ]), $transport);
        $result = $provider->execute(new PaymentOperation('op-1', 'pay-1', 'authorize', new Money(100, 'USD'), 'k-1'), []);
        self::assertSame('authorized', $result['state']);
        self::assertSame('https://stripe.test/payments/authorize', $transport->url);
    }

    public function testExternalProviderSelectionReturnsUnavailableCapabilityWithoutCredentials(): void
    {
        putenv('PAYMENT_MODE=production');
        putenv('PAYMENT_STRIPE_SECRET_KEY');
        putenv('PAYMENT_STRIPE_WEBHOOK_SECRET');
        $registry = new PaymentProviderRegistry(new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                throw new \LogicException('transport must not be reached');
            }
        });
        self::assertInstanceOf(UnavailablePaymentProvider::class, $registry->provider('stripe'));
    }

    public function testProductionRejectsSandboxModeBeforeProviderConstruction(): void
    {
        putenv('APP_ENV=production');
        putenv('PAYMENT_MODE=sandbox');
        $registry = new PaymentProviderRegistry(new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                throw new \LogicException('transport must not be reached');
            }
        });
        $this->expectException(\RuntimeException::class);
        $registry->provider('stripe');
    }

    public function testSandboxWithoutCredentialsUsesDeterministicFixtureOnlyInSandboxMode(): void
    {
        putenv('APP_ENV=local');
        putenv('PAYMENT_MODE=sandbox');
        putenv('PAYMENT_STRIPE_SECRET_KEY');
        putenv('PAYMENT_STRIPE_WEBHOOK_SECRET');
        $registry = new PaymentProviderRegistry(new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse { throw new \LogicException('sandbox fixture must not call transport'); }
        });

        self::assertInstanceOf(SandboxPaymentProvider::class, $registry->provider('stripe'));
    }

    public function testSandboxContractPreservesMoneyAndRequiresItsOwnSignature(): void
    {
        $provider = new SandboxPaymentProvider('stripe');
        $operation = new PaymentOperation('sandbox-op', 'sandbox-pay', 'capture', new Money(125, 'USD'), 'sandbox-key');
        $result = $provider->execute($operation, []);
        self::assertSame(125, $result['amount_minor']);
        self::assertSame('USD', $result['currency']);
        $body = '{"type":"payment.captured"}';
        $signature = hash_hmac('sha256', $body, 'sandbox:stripe');
        self::assertTrue($provider->verifyWebhook($body, ['X-Payment-Signature' => 'sha256=' . $signature]));
        self::assertFalse($provider->verifyWebhook($body, ['X-Payment-Signature' => 'sha256=' . hash_hmac('sha256', $body, 'wrong')]));
    }

    public function testRegistryReadinessExposesCapabilitiesWithoutSecrets(): void
    {
        putenv('APP_ENV=local');
        putenv('PAYMENT_MODE=sandbox');
        putenv('PAYMENT_STRIPE_SECRET_KEY');
        putenv('PAYMENT_STRIPE_WEBHOOK_SECRET');
        $registry = new PaymentProviderRegistry(new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse { throw new \LogicException('sandbox must not call transport'); }
        });
        $readiness = $registry->readiness('stripe');
        self::assertTrue($readiness['available']);
        self::assertSame('sandbox', $readiness['mode']);
        self::assertContains('capture', $readiness['capabilities']);
        self::assertArrayNotHasKey('secret_key', $readiness);
    }

    public function testWebhookUsesRawBodyHmacAndNeverAcceptsTheSecretAsSignature(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'production', 'https://stripe.test', [
            'secret_key' => 'secret', 'webhook_secret' => 'webhook-secret',
        ]), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(200, '{}');
            }
        });
        $body = '{"event":"payment.succeeded"}';
        $signature = hash_hmac('sha256', $body, 'webhook-secret');
        self::assertTrue($provider->verifyWebhook($body, ['x-payment-signature' => 'sha256=' . $signature]));
        self::assertFalse($provider->verifyWebhook($body, ['x-payment-signature' => 'webhook-secret']));
    }

    public function testProviderEvidenceRedactsNestedAndCamelCaseSecrets(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', [
            'secret_key' => 'secret', 'webhook_secret' => 'hook',
        ]), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(200, '{"state":"authorized","apiKey":"do-not-persist","nested":{"access-token":"also-secret"}}');
            }
        });

        $result = $provider->execute(new PaymentOperation('op-redact', 'pay-redact', 'authorize', new Money(100, 'USD'), 'redact-key'), []);
        self::assertSame('[redacted]', $result['apiKey']);
        self::assertSame('[redacted]', $result['nested']['access-token']);
    }

    public function testRetryableHttpResponsesRemainUnknownForReconciliation(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => 'secret', 'webhook_secret' => 'hook']), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(429, '{}');
            }
        });
        $result = $provider->execute(new PaymentOperation('op-429', 'pay-429', 'capture', new Money(100, 'USD'), 'key-429'), []);
        self::assertSame('unknown', $result['state']);
    }

    public function testValidationHttpResponsesAreDefinitiveFailures(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => 'secret', 'webhook_secret' => 'hook']), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(422, '{}');
            }
        });
        $result = $provider->execute(new PaymentOperation('op-422', 'pay-422', 'capture', new Money(100, 'USD'), 'key-422'), []);
        self::assertSame('failed', $result['state']);
    }

    public function testProviderStatusAndReferenceFieldsAreNormalized(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => 'secret', 'webhook_secret' => 'hook']), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(200, '{"status":"succeeded","id":"pi_normalized"}');
            }
        });

        $result = $provider->execute(new PaymentOperation('op-normalized', 'pay-normalized', 'authorize', new Money(100, 'USD'), 'key-normalized'), []);

        self::assertSame('captured', $result['state']);
        self::assertSame('succeeded', $result['provider_status']);
        self::assertSame('pi_normalized', $result['provider_ref']);
    }

    public function testTransportFailureIsClassifiedWithoutLeakingProviderError(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => 'secret', 'webhook_secret' => 'hook']), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                throw new \RuntimeException('connection timed out with secret');
            }
        });

        $result = $provider->execute(new PaymentOperation('op-timeout', 'pay-timeout', 'capture', new Money(100, 'USD'), 'key-timeout'), []);

        self::assertSame('unknown', $result['state']);
        self::assertSame('provider.timeout', $result['error_code']);
        self::assertStringNotContainsString('secret', (string) ($result['error'] ?? ''));
    }

    public function testProviderCredentialsRejectControlCharacters(): void
    {
        $config = new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => "secret\nvalue"]);

        $this->expectException(\RuntimeException::class);
        $config->credential('secret_key');
    }

    public function testUnsupportedProviderStateFailsClosed(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => 'secret', 'webhook_secret' => 'hook']), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(200, '{"state":"paid"}');
            }
        });

        self::assertSame('unknown', $provider->execute(new PaymentOperation('op-invalid-state', 'pay-invalid-state', 'capture', new Money(100, 'USD'), 'key-invalid-state'), [])['state']);
    }

    public function testMalformedSuccessfulResponseIsReconciliationSafe(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => 'secret', 'webhook_secret' => 'hook']), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse { return new PaymentTransportResponse(200, '{not-json'); }
        });

        $result = $provider->execute(new PaymentOperation('op-malformed', 'pay-malformed', 'capture', new Money(100, 'USD'), 'key-malformed'), []);

        self::assertSame('unknown', $result['state']);
        self::assertSame('provider.invalid_response', $result['error_code']);
    }

    public function testProviderMoneyMismatchFailsClosed(): void
    {
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'sandbox', 'https://stripe.test', ['secret_key' => 'secret', 'webhook_secret' => 'hook']), new class implements PaymentTransport {
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                return new PaymentTransportResponse(200, '{"state":"succeeded","amount_minor":101,"currency":"USD"}');
            }
        });
        $result = $provider->execute(new PaymentOperation('op-money-mismatch', 'pay-money-mismatch', 'capture', new Money(100, 'USD'), 'key-money-mismatch'), []);
        self::assertSame('unknown', $result['state']);
        self::assertSame('provider.amount_mismatch', $result['error_code']);
    }

    public function testCommandPassesPersistedProviderToRegistry(): void
    {
        $store = new class implements PaymentStore {
            public function find(string $id): ?array { return ['id' => $id, 'provider' => 'stripe', 'amount_minor' => 100, 'captured_amount_minor' => 0, 'currency' => 'USD', 'state' => 'authorized']; }
            public function setState(string $id, string $state, ?string $providerRef = null): void {}
            public function setCapturedAmount(string $id, int $capturedAmountMinor): void {}
        };
        $operations = new class implements PaymentOperationStore {
            public function findByIdempotency(string $key): ?array { return null; }
            public function create(array $operation): void {}
            public function complete(string $id, array $result): void {}
        };
        $provider = new class implements \App\Modules\Payment\Contracts\PaymentProvider {
            /** @var array<string, mixed> */ public array $context = [];
            public function execute(PaymentOperation $operation, array $context): array { $this->context = $context; return ['state' => 'captured']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };

        (new PaymentCommandService($store, new PaymentOperationService($operations), $provider))->execute('pay-provider-map', 'capture', 100, 'USD', 'map-key');
        self::assertSame('stripe', $provider->context['provider']);
    }

    public function testReconciliationUsesProviderReferenceAndRedactsResponse(): void
    {
        $transport = new class implements PaymentTransport {
            public string $method = '';
            public string $url = '';
            public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse
            {
                $this->method = $method;
                $this->url = $url;
                return new PaymentTransportResponse(200, '{"state":"captured","amount_minor":100,"currency":"USD","token":"must-not-persist"}');
            }
        };
        $provider = new StripePaymentProvider(new PaymentProviderConfig('stripe', 'production', 'https://stripe.test', [
            'secret_key' => 'secret', 'webhook_secret' => 'hook',
        ]), $transport);

        $result = $provider->reconcile(['provider_ref' => 'pi_123']);

        self::assertSame('GET', $transport->method);
        self::assertSame('https://stripe.test/payments/pi_123', $transport->url);
        self::assertSame('captured', $result['state']);
        self::assertSame('[redacted]', $result['token']);
    }
}
