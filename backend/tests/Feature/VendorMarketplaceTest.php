<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class VendorMarketplaceTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $stub = new \stdClass();
        $stub->router = $this->router;
        $app = $stub;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        if (str_contains($path, '?')) {
            $parts = explode('?', $path, 2);
            $path = $parts[0];
            parse_str($parts[1] ?? '', $query);
        }
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        /** @var array{status: int, json: mixed} $out */
        $out = ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
        return $out;
    }

    /** @return array<string, string> */
    private function loginAs(string $email, string $role): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'USD'];
    }

    /** @return array<string, mixed> */
    private function createVendor(array $admin, string $name = 'Acme Studio', array $overrides = []): array
    {
        $created = $this->call('POST', '/api/admin/vendors', ['name' => $name, 'commission_bps' => 500, 'contact_email' => 'team@acme.test', 'description' => 'Independent studio'] + $overrides, $admin);
        $this->assertSame(201, $created['status']);
        return $created['json']['data'];
    }

    /** @return array<string, mixed> */
    private function createProduct(array $admin, string $name, string $slug, ?string $vendorId = null): array
    {
        $body = ['name' => $name, 'slug' => $slug, 'price_minor' => 2500, 'stock' => 10, 'sku' => strtoupper($slug) . '-1'];
        if ($vendorId !== null) $body['vendor_id'] = $vendorId;
        $created = $this->call('POST', '/api/admin/products', $body, $admin);
        $this->assertSame(201, $created['status']);
        return $created['json']['data'];
    }

    public function testAdminCrudValidationSlugConflictAndStatus(): void
    {
        $admin = $this->loginAs('vendor-owner@shop.test', 'owner');
        $vendor = $this->createVendor($admin);
        $this->assertSame('acme-studio', $vendor['slug']);
        $this->assertSame('active', $vendor['status']);
        $this->assertSame(500, $vendor['commission_bps']);
        $this->assertSame(0, $vendor['products_count']);

        $list = $this->call('GET', '/api/admin/vendors?q=acme&status=active', [], $admin);
        $this->assertSame(200, $list['status']);
        $this->assertCount(1, $list['json']['data']);

        $badSlug = $this->call('POST', '/api/admin/vendors', ['name' => 'Bad', 'slug' => 'Bad Slug'], $admin);
        $this->assertSame(422, $badSlug['status']);

        $badCommission = $this->call('POST', '/api/admin/vendors', ['name' => 'Bad Commission', 'commission_bps' => 10001], $admin);
        $this->assertSame(422, $badCommission['status']);

        $conflict = $this->call('POST', '/api/admin/vendors', ['name' => 'Acme Clone', 'slug' => 'acme-studio'], $admin);
        $this->assertSame(422, $conflict['status']);
        $this->assertArrayHasKey('slug', $conflict['json']['meta']['errors'] ?? []);

        $other = $this->createVendor($admin, 'Other Studio');
        $updateConflict = $this->call('PUT', '/api/admin/vendors/' . $other['id'], ['slug' => 'acme-studio'], $admin);
        $this->assertSame(422, $updateConflict['status']);

        $suspended = $this->call('PUT', '/api/admin/vendors/' . $vendor['id'], ['status' => 'suspended'], $admin);
        $this->assertSame(200, $suspended['status']);
        $this->assertSame('suspended', $suspended['json']['data']['status']);
    }

    public function testProductAssignmentPublicVendorFilterAndDeleteGuard(): void
    {
        $admin = $this->loginAs('vendor-owner2@shop.test', 'owner');
        $vendor = $this->createVendor($admin, 'North Loom');
        $vendorId = (string) $vendor['id'];

        $product = $this->createProduct($admin, 'Linen Scarf', 'linen-scarf', $vendorId);
        $productId = (string) $product['id'];
        $this->assertSame($vendorId, $product['vendor_id']);

        $house = $this->createProduct($admin, 'House Tote', 'house-tote');
        $houseId = (string) $house['id'];

        $activate = $this->call('PUT', '/api/admin/products/' . $productId, ['status' => 'active'], $admin);
        $this->assertSame(200, $activate['status']);
        $this->assertSame(200, $this->call('PUT', '/api/admin/products/' . $houseId, ['status' => 'active'], $admin)['status']);

        $detail = $this->call('GET', '/api/products/linen-scarf');
        $this->assertSame(200, $detail['status']);
        $this->assertSame(['id' => $vendorId, 'name' => 'North Loom', 'slug' => 'north-loom'], $detail['json']['data']['vendor']);

        $houseDetail = $this->call('GET', '/api/products/house-tote');
        $this->assertSame(200, $houseDetail['status']);
        $this->assertNull($houseDetail['json']['data']['vendor']);

        $profile = $this->call('GET', '/api/vendors/north-loom');
        $this->assertSame(200, $profile['status']);
        $this->assertFalse($profile['json']['data']['suspended']);
        $this->assertSame(1, $profile['json']['data']['product_count']);

        $filtered = $this->call('GET', '/api/products?vendor=north-loom');
        $this->assertSame(200, $filtered['status']);
        $this->assertCount(1, $filtered['json']['data']);
        $this->assertSame('linen-scarf', $filtered['json']['data'][0]['slug']);

        $blocked = $this->call('DELETE', '/api/admin/vendors/' . $vendorId, [], $admin);
        $this->assertSame(409, $blocked['status']);

        $this->assertSame(200, $this->call('PUT', '/api/admin/vendors/' . $vendorId, ['status' => 'suspended'], $admin)['status']);
        $suspendedProfile = $this->call('GET', '/api/vendors/north-loom');
        $this->assertSame(200, $suspendedProfile['status']);
        $this->assertTrue($suspendedProfile['json']['data']['suspended']);
        $this->assertSame(0, $suspendedProfile['json']['data']['product_count']);
        $suspendedFilter = $this->call('GET', '/api/products?vendor=north-loom');
        $this->assertSame(200, $suspendedFilter['status']);
        $this->assertCount(0, $suspendedFilter['json']['data']);

        $unknown = $this->call('GET', '/api/vendors/missing-vendor');
        $this->assertSame(404, $unknown['status']);

        $this->assertSame(204, $this->call('DELETE', '/api/admin/products/' . $productId, [], $admin)['status']);
        $this->assertSame(204, $this->call('DELETE', '/api/admin/vendors/' . $vendorId, [], $admin)['status']);
    }

    public function testProductAssignmentRejectsUnknownVendor(): void
    {
        $admin = $this->loginAs('vendor-owner3@shop.test', 'owner');
        $product = $this->createProduct($admin, 'Unknown Vendor Item', 'unknown-vendor-item');
        $bad = $this->call('PUT', '/api/admin/products/' . $product['id'], ['vendor_id' => 'does-not-exist'], $admin);
        $this->assertSame(422, $bad['status']);
    }
}
