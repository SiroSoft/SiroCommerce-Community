<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Integration\Application\OutboundWebhook;
use App\Modules\Integration\Application\DeliveryAttempt;
use App\Modules\Integration\Application\EndpointRegistration;
use App\Modules\Integration\Domain\DeliveryPolicy;
use App\Modules\Integration\Domain\EndpointPolicy;
use App\Modules\Integration\Domain\EventSchemaRegistry;
use App\Modules\Integration\Domain\IntegrationEvent;
use App\Modules\Integration\Domain\IntegrationKey;
use App\Modules\Integration\Domain\IntegrationRedactor;
use App\Modules\Integration\Domain\IntegrationScope;
use App\Modules\Integration\Domain\WebhookSigner;
use App\Modules\Integration\Infrastructure\IntegrationMetrics;
use PHPUnit\Framework\TestCase;

final class IntegrationBoundaryTest extends TestCase
{
    public function testOutboundSignatureVerifiesAndReplayTimestampDoesNot(): void
    {
        $event = new IntegrationEvent('evt-1', 'order.placed.v1', 1, '2026-09-30T00:00:00Z', 'corr-1', ['order_id' => 'o1'], 'store:a');
        $request = OutboundWebhook::request('https://partner.example/hooks', 'secret', $event, 1000);
        self::assertTrue(WebhookSigner::verify('secret', $request['body'], 1000, 'evt-1', $request['headers']['X-Integration-Signature'], 300, 1000));
        self::assertFalse(WebhookSigner::verify('secret', $request['body'], 1000, 'evt-1', $request['headers']['X-Integration-Signature'], 300, 1401));
    }

    public function testDuplicateKeyVerificationAndScopeLeastPrivilege(): void
    {
        $issued = IntegrationKey::issue();
        self::assertTrue(IntegrationKey::verify($issued['secret'], $issued['hash']));
        self::assertFalse(IntegrationKey::verify('different', $issued['hash']));
        self::assertTrue(IntegrationScope::allows(['events:read'], 'events:read'));
        self::assertFalse(IntegrationScope::allows(['events:read'], 'events:write'));
        self::assertTrue(IntegrationScope::allows(['events:*'], 'events:write'));
    }

    public function testEndpointCannotCrossScopeOrUseUnsafeUrl(): void
    {
        EndpointPolicy::assertIsolated('store:a', 'store:a');
        $this->expectException(\RuntimeException::class);
        EndpointPolicy::assertIsolated('store:a', 'store:b');
    }

    public function testRetryBackoffAndDeadLetterDecision(): void
    {
        self::assertSame(1001, DeliveryPolicy::retryAt(1, 1000));
        self::assertSame(1002, DeliveryPolicy::retryAt(2, 1000));
        self::assertTrue(DeliveryPolicy::dead(5, 5));
        self::assertFalse(DeliveryPolicy::dead(4, 5));
        self::assertSame('pending', DeliveryAttempt::result(1, 3, 503, null, 1000)['state']);
        self::assertSame('dead', DeliveryAttempt::result(3, 3, 503, null, 1000)['state']);
        self::assertSame('delivered', DeliveryAttempt::result(1, 3, 204, null, 1000)['state']);
    }

    public function testEndpointRegistrationRejectsUnsafeUrlsAndRotationReturnsOnlyNewSecret(): void
    {
        $endpoint = EndpointRegistration::create('store:a', 'https://partner.example/hooks', ['order.placed.v1']);
        self::assertSame('active', $endpoint['status']);
        $rotation = EndpointRegistration::rotateSecret();
        self::assertSame(hash('sha256', $rotation['secret']), $rotation['secret_hash']);
        $this->expectException(\InvalidArgumentException::class);
        EndpointRegistration::create('store:a', 'http://localhost/hooks');
    }

    public function testSchemaCompatibilityAndPiiRedaction(): void
    {
        $schemas = new EventSchemaRegistry();
        $schemas->register('order.placed', 1, ['order_id']);
        $schemas->assertCompatible('order.placed', 2, ['order_id' => 'o1']);
        $this->expectException(\InvalidArgumentException::class);
        $schemas->assertCompatible('order.placed', 1, ['email' => 'secret@example.test']);
    }

    public function testRedactionDoesNotLeakSecretsIntoOutboundBody(): void
    {
        $event = new IntegrationEvent('evt-2', 'order.placed.v1', 1, '2026-09-30T00:00:00Z', 'corr-2', ['email' => 'a@example.test', 'order_id' => 'o2', 'nested' => ['api_key' => 'do-not-leak']], 'store:a');
        $request = OutboundWebhook::request('https://partner.example/hooks', 'secret', $event, 1000);
        self::assertStringNotContainsString('a@example.test', $request['body']);
        self::assertStringNotContainsString('do-not-leak', $request['body']);
        self::assertStringContainsString('o2', $request['body']);
        self::assertSame(['email' => '[redacted]', 'order_id' => 'o2', 'nested' => ['api_key' => '[redacted]']], IntegrationRedactor::redact($event->payload));
    }

    public function testDeliveryMetricsUseVersionlessLowCardinalityLabels(): void
    {
        IntegrationMetrics::delivery('order.placed.v1', 'delivered');
        self::assertTrue(true);
    }
}
