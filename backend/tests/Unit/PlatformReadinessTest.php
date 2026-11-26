<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ApiContract;
use App\Support\ProviderReadiness;
use PHPUnit\Framework\TestCase;

final class PlatformReadinessTest extends TestCase
{
    public function testReadinessIsFailClosedAndDoesNotExposeSecrets(): void
    {
        $prevReal = getenv('APP_ENV');
        $prevEnv = $_ENV['APP_ENV'] ?? null;
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        putenv('STRIPE_SECRET_KEY');
        unset($_ENV['STRIPE_SECRET_KEY']);
        try {
            $result = ProviderReadiness::check();
        } finally {
            $prevReal === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $prevReal);
            if ($prevEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $prevEnv;
            }
        }

        self::assertFalse($result['ready']);
        self::assertContains('STRIPE_SECRET_KEY', $result['missing']);
        self::assertArrayNotHasKey('value', $result);
        self::assertArrayNotHasKey('secret', $result);
    }

    public function testServiceReadinessExcludesProviderConfigurationButStillReportsReleaseGate(): void
    {
        $controller = new \App\Controllers\HealthController();
        $method = new \ReflectionMethod($controller, 'readinessChecks');
        $method->setAccessible(true);
        /** @var array<string, mixed> $checks */
        $checks = $method->invoke($controller);

        foreach (['database', 'migrations', 'queue', 'outbox', 'dead_letters', 'payments', 'search'] as $service) {
            self::assertArrayHasKey($service, $checks);
        }
        self::assertArrayHasKey('providers', $checks, 'provider rollout stays visible as a release gate');
        self::assertArrayHasKey('provider_missing', $checks);
    }

    public function testApiContractPublishesStableVersionAndErrorSemantics(): void
    {
        $metadata = ApiContract::metadata();

        self::assertSame('v1', $metadata['current_api']);
        self::assertSame(['v1', 'v2'], $metadata['supported_apis']);
        self::assertSame('envelope', $metadata['error_format']);
        self::assertSame('Idempotency-Key', $metadata['idempotency']['header']);
    }
}
