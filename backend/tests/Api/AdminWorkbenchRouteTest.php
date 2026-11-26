<?php
declare(strict_types=1);

namespace Tests\Api;

use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class AdminWorkbenchRouteTest extends TestCase
{
    public function testWorkbenchRequiresAuthenticatedScopedAdmin(): void
    {
        $router = new Router(); $app = new \stdClass(); $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        try { $response = $router->dispatch(new Request('GET', '/api/v2/admin/workbench', [], [], [], '127.0.0.1')); }
        catch (ValidationException $e) { $response = $e->toResponse(); }
        self::assertSame(401, $response->statusCode());
    }

    public function testWorkbenchRoutesRemainScopedAndAuditable(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        self::assertStringContainsString("/api/v2/admin/workbench/bulk-orders", $source);
        self::assertStringContainsString("'cap:admin_workbench'", $source);
        $service = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Platform/Application/AdminWorkbenchService.php');
        self::assertStringContainsString("Audit::log('commerce.admin.", $service);
        self::assertStringContainsString('store_id=? AND channel_id=?', $service);
    }
}
