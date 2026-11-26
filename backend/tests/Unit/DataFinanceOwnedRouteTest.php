<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\Support\DataFinanceOperationCases;
use Tests\TestCase;

final class DataFinanceOwnedRouteTest extends TestCase
{
    #[DataProviderExternal(DataFinanceOperationCases::class, 'all')]
    public function testExactDataFinanceOperationHasExpectedBoundary(string $method, string $path, int $expected): void
    {
        $router = $this->router();
        try {
            $response = $router->dispatch(new Request($method, $path, [], [], []));
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        self::assertSame($expected, $response->statusCode(), $method . ' ' . $path);
    }

    private function router(): Router
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';
        return $router;
    }
}
