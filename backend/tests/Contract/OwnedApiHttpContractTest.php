<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\Support\OwnedApiOperationCases;
use Tests\TestCase;

final class OwnedApiHttpContractTest extends TestCase
{
    #[DataProviderExternal(OwnedApiOperationCases::class, 'all')]
    public function testExactOperationReturnsTheVersionedResponseContract(string $method, string $path, string $operation, int $expected): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $response = $router->dispatch(new Request($method, $path, [], [], []));
        self::assertSame($expected, $response->statusCode(), $operation);
        $payload = $response->payload();
        self::assertArrayHasKey('success', $payload, $operation);
        self::assertArrayHasKey('message', $payload, $operation);
        self::assertIsString($payload['message'], $operation);
        if ($expected >= 400) self::assertFalse($payload['success'], $operation);
    }
}
