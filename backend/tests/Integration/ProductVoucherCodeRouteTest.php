<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ProductVoucherCode;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Catalog\Application\ProductLifecycleService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\TestCase;

final class ProductVoucherCodeRouteTest extends TestCase
{
    public function testVoucherCodeReadReturnsScopedMaskedRows(): void
    {
        $product = (new ProductRepository())->store(['id' => 'integration-voucher-prod', 'name' => 'Integration voucher', 'slug' => 'integration-voucher', 'type' => 'voucher', 'status' => 'draft', 'store_id' => 'default', 'channel_id' => 'default', 'locale' => 'en', 'currency' => 'VND']);
        (new ProductLifecycleService())->addVoucher($product['id'], ['code' => 'INTEGRATION-1'], 'integration-replay-key');
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'catalog-admin', 'email' => 'catalog-admin@test', 'password' => 'secret123', 'role' => 'admin']);
        $token = $auth->login('catalog-admin@test', 'secret123')->access;
        $router = new Router(); $app = new \stdClass(); $app->router = $router; require dirname(__DIR__, 2) . '/routes/api.php';
        $response = $router->dispatch(new Request('GET', '/api/admin/products/' . $product['id'] . '/voucher-codes', [], ['Authorization' => 'Bearer ' . $token, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'], [], '127.0.0.1'));
        ob_start(); $response->send(); $json = json_decode((string) ob_get_clean(), true);

        self::assertSame(200, $response->statusCode());
        self::assertSame('IN*********-1', $json['data'][0]['code_mask']);
        self::assertArrayNotHasKey('code_hash', $json['data'][0]);
        self::assertArrayNotHasKey('idempotency_key', $json['data'][0]);
        self::assertSame(1, ProductVoucherCode::query()->where('product_id', $product['id'])->count());
    }
}
