<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Response;

final class BackendSecurityBoundaryTest extends TestCase
{
    public function testCookieAndCsrfHeadersCannotSubstituteForBearerAuthentication(): void
    {
        $request = new Request(
            'POST',
            '/api/auth/logout',
            [],
            ['Cookie' => 'access_token=forged', 'X-CSRF-Token' => 'present'],
            [],
            '127.0.0.1'
        );

        $response = (new AuthMiddleware())->handle($request, static fn (): Response => Response::success());

        self::assertSame(401, $response->statusCode());
        self::assertSame('UNAUTHORIZED', $response->payload()['meta']['error_code']);
        self::assertStringNotContainsString('forged', json_encode($response->payload(), JSON_THROW_ON_ERROR));
    }
}
