<?php

declare(strict_types=1);

namespace Tests\Unit;

use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class AdvancedReportingRouteTest extends TestCase
{
    public function testExactAdvancedReportingRouteRequiresReportsCapabilityBoundary(): void
    {
        $router = new Router(); $app = new \stdClass(); $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        try { $response = $router->dispatch(new Request('GET', '/api/v2/admin/reports/advanced', [], [], [])); }
        catch (ValidationException $e) { $response = $e->toResponse(); }
        self::assertSame(401, $response->statusCode());
    }
}
