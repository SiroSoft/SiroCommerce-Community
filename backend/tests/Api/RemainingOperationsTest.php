<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;

final class RemainingOperationsTest extends TestCase
{
    public function testSensitiveOperationsHaveExactRouteBoundaries(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        self::assertStringContainsString("post('/api/admin/orders/{id}/cancel'", $routes);
        self::assertStringContainsString("post('/api/orders/recover'", $routes);
        self::assertStringContainsString("post('/api/admin/payments/reconcile'", $routes);
        self::assertStringContainsString("'auth:admin,owner,support'", $routes);
        self::assertStringContainsString("'auth:admin,owner,finance'", $routes);
    }
}
