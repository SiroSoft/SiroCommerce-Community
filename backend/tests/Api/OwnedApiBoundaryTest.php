<?php

declare(strict_types=1);

namespace Tests\Api;

use Siro\Core\Request;
use Siro\Core\Router;
use Tests\TestCase;
use Tests\Support\ApiAssertions;

final class OwnedApiBoundaryTest extends TestCase
{
    public function testGuestCannotReachPrivateOwnedSurfacesAndPublicReviewsKeepEnvelope(): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        self::assertSame(401, $router->dispatch(new Request('GET', '/api/account/addresses'))->statusCode());
        self::assertSame(401, $router->dispatch(new Request('GET', '/api/wishlist'))->statusCode());
        self::assertSame(401, $router->dispatch(new Request('POST', '/api/v2/b2b/companies/company-1/quotes'))->statusCode());
        self::assertSame(401, $router->dispatch(new Request('GET', '/api/admin/inventory/variant-1'))->statusCode());
        self::assertSame(200, $router->dispatch(new Request('GET', '/api/products/product-1/reviews'))->statusCode());

        $response = $router->dispatch(new Request('GET', '/api/products/product-1/reviews'));
        ApiAssertions::successEnvelope($response->payload());
    }
}
