<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ParityContractTest extends TestCase
{
    public function testParityRoutesAndGeneratedClientStayPresent(): void
    {
        $base = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($base . '/routes/api.php');
        $openapi = json_decode((string) file_get_contents($base . '/docs/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $sdk = (string) file_get_contents($base . '/generated/sirocommerce.ts');
        foreach (['/api/v2/admin/jobs', '/api/v2/admin/warehouses/transfers', '/api/v2/admin/audit-logs', '/api/v2/admin/notification-events', '/api/v2/admin/vendors/{id}'] as $route) {
            self::assertStringContainsString($route, $routes);
            self::assertArrayHasKey($route, $openapi['paths']);
            self::assertStringContainsString(ltrim(str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], $route), '_'), $sdk);
        }
    }
}
