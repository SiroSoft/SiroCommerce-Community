<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\Support\OwnedApiOperationCases;
use Tests\TestCase;

final class OwnedApiHttpUnitTest extends TestCase
{
    #[DataProviderExternal(OwnedApiOperationCases::class, 'all')]
    public function testExactOperationRejectsUnauthorisedOrInvalidRequest(string $method, string $path, string $operation, int $expected): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $response = $router->dispatch(new Request($method, $path, [], [], []));
        self::assertSame($expected, $response->statusCode(), $operation);
    }
}
