<?php

declare(strict_types=1);

namespace Tests\Workflow;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\Support\DataFinanceOperationCases;
use Tests\TestCase;

final class DataFinanceOwnedRouteTest extends TestCase
{
    #[DataProviderExternal(DataFinanceOperationCases::class, 'all')]
    public function testExactDataFinanceOperationFailsClosedBeforeMutation(string $method, string $path, int $expected): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        try {
            $response = $router->dispatch(new Request($method, $path, [], [], []));
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        self::assertSame($expected, $response->statusCode(), $method . ' ' . $path);
        self::assertSame(false, $response->payload()['success'], $method . ' ' . $path);
    }
}
