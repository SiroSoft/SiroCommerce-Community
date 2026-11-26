<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\TestCase;

final class ProductVoucherCodeApiComponentTest extends TestCase
{
    public function testVoucherCodeReadHasExactAuthenticationAndScopeBoundary(): void
    {
        $router = new Router(); $app = new \stdClass(); $app->router = $router; require dirname(__DIR__, 2) . '/routes/api.php';
        $unauthorized = $router->dispatch(new Request('GET', '/api/admin/products/api-voucher-product/voucher-codes', [], [], [], '127.0.0.1'));
        self::assertSame(401, $unauthorized->statusCode());
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'api-admin', 'email' => 'api-admin@test', 'password' => 'secret123', 'role' => 'admin']);
        $token = $auth->login('api-admin@test', 'secret123')->access;
        $headers = ['Authorization' => 'Bearer ' . $token, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
        $missingScope = $router->dispatch(new Request('GET', '/api/admin/products/api-voucher-product/voucher-codes', [], ['Authorization' => 'Bearer ' . $token], [], '127.0.0.1'));
        self::assertSame(400, $missingScope->statusCode());
        self::assertSame('default', (new ProductRepository())->store(['id' => 'api-voucher-product', 'name' => 'API voucher', 'slug' => 'api-voucher', 'type' => 'voucher', 'status' => 'draft', 'store_id' => 'default', 'channel_id' => 'default', 'locale' => 'en', 'currency' => 'VND'])['store_id']);
        self::assertSame(200, $router->dispatch(new Request('GET', '/api/admin/products/api-voucher-product/voucher-codes', [], $headers, [], '127.0.0.1'))->statusCode());
    }
}
