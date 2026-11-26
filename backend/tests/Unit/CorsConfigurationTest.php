<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siro\Core\Middleware\CorsMiddleware;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * CORS_ALLOWED_ORIGINS contract: wildcard keeps legacy behaviour, a whitelist
 * reflects only known origins, and credentials are never sent with `*`.
 */
final class CorsConfigurationTest extends TestCase
{
    private mixed $previousEnv = null;
    private mixed $previousGetenv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
        $this->previousGetenv = getenv('CORS_ALLOWED_ORIGINS');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === null) {
            unset($_ENV['CORS_ALLOWED_ORIGINS']);
        } else {
            $_ENV['CORS_ALLOWED_ORIGINS'] = $this->previousEnv;
        }
        if ($this->previousGetenv === false) {
            putenv('CORS_ALLOWED_ORIGINS');
        } else {
            putenv('CORS_ALLOWED_ORIGINS=' . $this->previousGetenv);
        }
        parent::tearDown();
    }

    private function configure(string $origins): void
    {
        putenv('CORS_ALLOWED_ORIGINS=' . $origins);
        $_ENV['CORS_ALLOWED_ORIGINS'] = $origins;
    }

    private function response(string $method, string $origin): Response
    {
        $middleware = new CorsMiddleware();
        $request = new Request($method, '/api/auth/me', [], ['origin' => $origin]);
        $result = $middleware->handle($request, static fn (): Response => Response::success(['ok' => true]));
        self::assertInstanceOf(Response::class, $result);

        return $result;
    }

    public function testWildcardPreservesLegacyBehaviour(): void
    {
        $this->configure('*');
        $response = $this->response('GET', 'https://any.example.com');
        self::assertSame('*', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('false', $response->getHeader('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->getHeader('Vary'));
    }

    public function testWhitelistedOriginIsReflected(): void
    {
        $this->configure('https://app.example.com,https://admin.example.com');
        $response = $this->response('GET', 'https://admin.example.com');
        self::assertSame('https://admin.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->getHeader('Vary'));
    }

    public function testUnknownOriginIsBlocked(): void
    {
        $this->configure('https://app.example.com');
        $response = $this->response('GET', 'https://evil.example.com');
        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeader('Vary'));
    }

    public function testPreflightWhitelistedOrigin(): void
    {
        $this->configure('https://app.example.com');
        $response = $this->response('OPTIONS', 'https://app.example.com');
        self::assertSame(204, $response->statusCode());
        self::assertSame('https://app.example.com', $response->getHeader('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeader('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->getHeader('Vary'));
    }

    public function testPreflightUnknownOriginIsBlocked(): void
    {
        $this->configure('https://app.example.com');
        $response = $this->response('OPTIONS', 'https://evil.example.com');
        self::assertSame(204, $response->statusCode());
        self::assertNull($response->getHeader('Access-Control-Allow-Origin'));
    }
}
