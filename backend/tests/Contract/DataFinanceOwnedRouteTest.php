<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\Support\DataFinanceOperationCases;
use Tests\TestCase;

final class DataFinanceOwnedRouteTest extends TestCase
{
    #[DataProviderExternal(DataFinanceOperationCases::class, 'all')]
    public function testExactDataFinanceOperationReturnsStableErrorEnvelope(string $method, string $path, int $expected): void
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
        $payload = $response->payload();
        self::assertSame($expected, $response->statusCode(), $method . ' ' . $path);
        self::assertArrayHasKey('success', $payload, $method . ' ' . $path);
        self::assertArrayHasKey('message', $payload, $method . ' ' . $path);
        self::assertIsString($payload['message'], $method . ' ' . $path);
        self::assertFalse($payload['success'], $method . ' ' . $path);
    }
}
