<?php

declare(strict_types=1);

namespace Tests\Contract;

use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class AdvancedReportingRouteTest extends TestCase
{
    public function testExactAdvancedReportingRouteReturnsStableErrorEnvelope(): void
    {
        $router = new Router(); $app = new \stdClass(); $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        try { $response = $router->dispatch(new Request('GET', '/api/v2/admin/reports/advanced', [], [], [])); }
        catch (ValidationException $e) { $response = $e->toResponse(); }
        $payload = $response->payload();
        self::assertSame(401, $response->statusCode());
        self::assertFalse($payload['success']);
        self::assertIsString($payload['message']);
    }
}
