<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\StorefrontController;
use Siro\Core\Request;
use PHPUnit\Framework\TestCase;

final class StorefrontContextTest extends TestCase
{
    public function testContextUsesExplicitScopeAndFailsClosedCapabilities(): void
    {
        $response = (new StorefrontController())->context(new Request(
            'GET',
            '/api/storefront/context',
            [],
            [
                'X-Store-Id' => 'store-main',
                'X-Channel-Id' => 'web',
                'X-Locale' => 'en',
                'X-Currency' => 'USD',
            ],
        ));

        self::assertSame(200, $response->statusCode());
        self::assertSame('store-main', $response->payload()['data']['store']['id']);
        self::assertSame('USD', $response->payload()['data']['store']['currency']);
        self::assertFalse($response->payload()['data']['payment']['available']);
        self::assertFalse($response->payload()['data']['credit']['available']);
    }

    public function testContextRejectsMissingScope(): void
    {
        $response = (new StorefrontController())->context(new Request('GET', '/api/storefront/context'));

        self::assertSame(400, $response->statusCode());
        self::assertSame('SCOPE_ERROR', $response->payload()['meta']['error_code'] ?? null);
    }
}
