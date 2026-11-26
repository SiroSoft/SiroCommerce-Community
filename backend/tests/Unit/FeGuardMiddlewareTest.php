<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\FeGuardMiddleware;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;

final class FeGuardMiddlewareTest extends TestCase
{
    /** @var array<string, array{0: bool, 1: mixed, 2: string|false}> */
    private array $envBackup = [];

    /** @var array<int, string> */
    private array $envKeys = ['APP_ENV', 'FE_SHARED_SECRET', 'FE_ALLOWED_ORIGINS'];

    protected function setUp(): void
    {
        foreach ($this->envKeys as $key) {
            $this->envBackup[$key] = [array_key_exists($key, $_ENV), $_ENV[$key] ?? null, getenv($key)];
        }

        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('FE_SHARED_SECRET', 'expected-secret');
        $this->setEnv('FE_ALLOWED_ORIGINS', 'https://ecommerce.sirophp.com,http://localhost:3000');
    }

    protected function tearDown(): void
    {
        foreach ($this->envKeys as $key) {
            [$existed, $value, $processValue] = $this->envBackup[$key];
            if ($existed) {
                $_ENV[$key] = $value;
            } else {
                unset($_ENV[$key]);
            }
            if ($processValue === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $processValue);
            }
        }
        $this->envBackup = [];
    }

    public function testValidTokenPasses(): void
    {
        $request = new Request('GET', '/api/admin/orders', [], ['X-Siro-FE' => 'expected-secret'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(200, $response->statusCode());
    }

    public function testAllowedOriginPassesWithoutToken(): void
    {
        $request = new Request('GET', '/api/products', [], ['Origin' => 'https://ecommerce.sirophp.com'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(200, $response->statusCode());
    }

    public function testAllowedRefererPassesWithoutToken(): void
    {
        $request = new Request('GET', '/api/products', [], ['Referer' => 'https://ecommerce.sirophp.com/products/widget'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(200, $response->statusCode());
    }

    public function testDefaultAllowlistIncludesProductionOrigin(): void
    {
        unset($_ENV['FE_ALLOWED_ORIGINS']);
        putenv('FE_ALLOWED_ORIGINS');
        $request = new Request('GET', '/api/products', [], ['Origin' => 'https://ecommerce.sirophp.com'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(200, $response->statusCode());
    }

    public function testUnknownOriginIsForbidden(): void
    {
        $request = new Request('GET', '/api/products', [], ['Origin' => 'https://evil.example'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(403, $response->statusCode());
        self::assertSame('FORBIDDEN', $response->payload()['meta']['error_code']);
    }

    public function testWrongTokenIsForbidden(): void
    {
        $request = new Request('GET', '/api/products', [], ['X-Siro-FE' => 'wrong'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(403, $response->statusCode());
    }

    public function testWrongTokenIsForbiddenEvenWithAllowedOrigin(): void
    {
        $request = new Request('GET', '/api/products', [], [
            'X-Siro-FE' => 'wrong',
            'Origin' => 'https://ecommerce.sirophp.com',
        ], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(403, $response->statusCode());
    }

    public function testMissingOriginAndTokenIsForbidden(): void
    {
        $request = new Request('GET', '/api/products', [], [], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(403, $response->statusCode());
    }

    public function testAdminPathDoesNotUseOriginFallback(): void
    {
        $request = new Request('GET', '/api/v2/admin/orders', [], ['Origin' => 'https://ecommerce.sirophp.com'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(403, $response->statusCode());
    }

    public function testProductionAdminWithoutSecretFailsClosed(): void
    {
        $this->setEnv('APP_ENV', 'production');
        $this->setEnv('FE_SHARED_SECRET', '');
        $request = new Request('GET', '/api/admin/orders', [], ['Origin' => 'https://ecommerce.sirophp.com'], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(503, $response->statusCode());
        self::assertSame('TRUST_CONFIG_MISSING', $response->payload()['meta']['error_code']);
    }

    public function testOptionsPreflightAlwaysPasses(): void
    {
        $request = new Request('OPTIONS', '/api/products', [], [], [], '127.0.0.1');

        $response = (new FeGuardMiddleware())->handle($request, $this->next());

        self::assertSame(200, $response->statusCode());
    }

    private function next(): callable
    {
        return static fn (): Response => Response::success();
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}
