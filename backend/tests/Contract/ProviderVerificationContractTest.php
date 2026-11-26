<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;

final class ProviderVerificationContractTest extends TestCase
{
    public function testProviderVerificationIsPublishedWithoutSecretFields(): void
    {
        $base = dirname(__DIR__, 2);
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);

        $operation = $openapi['paths']['/api/admin/providers/verify']['get'];
        self::assertSame('#/components/schemas/ProviderVerificationEnvelope', $operation['x-response-envelope']);
        $check = $openapi['components']['schemas']['ProviderVerificationCheck'];
        self::assertSame(['name', 'type', 'configured', 'reachable', 'detail'], $check['required']);
        self::assertArrayNotHasKey('secret', $check['properties']);
        self::assertArrayNotHasKey('api_key', $check['properties']);
    }
}
