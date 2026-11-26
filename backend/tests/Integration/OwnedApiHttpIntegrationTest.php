<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\Support\OwnedApiOperationCases;
use Tests\TestCase;

final class OwnedApiHttpIntegrationTest extends TestCase
{
    #[DataProviderExternal(OwnedApiOperationCases::class, 'all')]
    public function testExactOperationPreservesTheBoundaryInTheApplication(string $method, string $path, string $operation, int $expected): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $response = $router->dispatch(new Request($method, $path, [], [], []));
        self::assertSame($expected, $response->statusCode(), $operation);
        self::assertIsArray($response->payload(), $operation);
    }
}
