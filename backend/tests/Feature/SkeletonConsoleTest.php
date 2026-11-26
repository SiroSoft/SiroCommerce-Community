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

/**
 * Admin-console skeleton dialect: products, orders, categories, tags, posts.
 */
final class SkeletonConsoleTest extends TestCase
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

    /** @param array<string, mixed> $body @param array<string, string> $headers @param array<int|string, mixed> $query @return array{status: int, json: mixed} */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
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
    private function adminHeaders(): array
    {
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'admin', 'email' => 'skeleton-admin@shop.test', 'password' => 'secret123', 'role' => 'admin']);
        $tokens = $auth->login('skeleton-admin@shop.test', 'secret123');
        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    public function testProductCrudWithNumericIds(): void
    {
        $admin = $this->adminHeaders();
        $created = $this->call('POST', '/api/products', ['name' => 'Skeleton Lamp', 'price' => 129.99, 'stock' => 7, 'sku' => 'SKEL-LAMP-1'], $admin);
        self::assertSame(201, $created['status']);
        self::assertIsInt($created['json']['data']['id']);
        self::assertSame(129.99, $created['json']['data']['price']);
        $id = (int) $created['json']['data']['id'];

        $list = $this->call('GET', '/api/products', [], $admin, ['search' => 'Skeleton Lamp']);
        self::assertSame(200, $list['status']);
        self::assertGreaterThanOrEqual(1, $list['json']['meta']['total']);

        $detail = $this->call('GET', '/api/products/' . $id, [], $admin);
        self::assertSame(200, $detail['status']);
        self::assertSame('Skeleton Lamp', $detail['json']['data']['name']);

        $updated = $this->call('PUT', '/api/products/' . $id, ['price' => 99.5, 'stock' => 3], $admin);
        self::assertSame(200, $updated['status']);
        self::assertSame(99.5, $updated['json']['data']['price']);

        self::assertSame(204, $this->call('DELETE', '/api/products/' . $id, [], $admin)['status']);
        self::assertSame(404, $this->call('GET', '/api/products/' . $id, [], $admin)['status']);

        $public = $this->call('GET', '/api/products', [], [], ['per_page' => 1]);
        self::assertSame(200, $public['status']);
    }

    public function testCategoryTagPostCrud(): void
    {
        $admin = $this->adminHeaders();

        $category = $this->call('POST', '/api/categories', ['name' => 'Skeleton Decor'], $admin);
        self::assertSame(201, $category['status']);
        $categoryId = (int) $category['json']['data']['id'];
        $renamed = $this->call('PUT', '/api/categories/' . $categoryId, ['description' => 'Home decor'], $admin);
        self::assertSame(200, $renamed['status']);
        self::assertSame('Home decor', $renamed['json']['data']['description']);

        $tag = $this->call('POST', '/api/tags', ['name' => 'SkeletonSale'], $admin);
        self::assertSame(201, $tag['status']);
        $tagId = (int) $tag['json']['data']['id'];
        self::assertSame(200, $this->call('GET', '/api/tags/' . $tagId, [], $admin)['status']);

        $post = $this->call('POST', '/api/posts', ['title' => 'Skeleton Guide', 'status' => 'published'], $admin);
        self::assertSame(201, $post['status']);
        self::assertTrue($post['json']['data']['published_at'] !== null);
        $postId = (int) $post['json']['data']['id'];
        $listed = $this->call('GET', '/api/posts', [], $admin, ['search' => 'Skeleton Guide']);
        self::assertGreaterThanOrEqual(1, $listed['json']['meta']['total']);

        self::assertSame(204, $this->call('DELETE', '/api/posts/' . $postId, [], $admin)['status']);
        self::assertSame(204, $this->call('DELETE', '/api/tags/' . $tagId, [], $admin)['status']);
        self::assertSame(204, $this->call('DELETE', '/api/categories/' . $categoryId, [], $admin)['status']);
    }

    public function testOrderListRequiresStaff(): void
    {
        self::assertSame(401, $this->call('GET', '/api/orders')['status']);
        $list = $this->call('GET', '/api/orders', [], $this->adminHeaders(), ['per_page' => 5]);
        self::assertSame(200, $list['status']);
        self::assertArrayHasKey('total', $list['json']['meta']);
    }

    public function testOrderStatusFilterWorksWithoutSearch(): void
    {
        $admin = $this->adminHeaders();
        $orders = new \App\Repositories\OrderRepository();
        $orders->createOrder([
            'id' => 'skel-status-paid', 'number' => 'SKEL-STATUS-PAID', 'customer_id' => 'customer-status-1',
            'guest_email' => 'status-paid@example.test', 'currency' => 'VND',
            'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'paid',
            'idempotency_key' => 'skel-status-paid-key', 'store_id' => 'default', 'channel_id' => 'default',
            'created_at' => '2026-09-20 10:00:00',
        ]);
        $orders->createOrder([
            'id' => 'skel-status-cancelled', 'number' => 'SKEL-STATUS-CANCELLED', 'customer_id' => 'customer-status-2',
            'guest_email' => 'status-cancelled@example.test', 'currency' => 'VND',
            'subtotal_minor' => 1000, 'grand_total_minor' => 1000, 'state' => 'cancelled',
            'idempotency_key' => 'skel-status-cancelled-key', 'store_id' => 'default', 'channel_id' => 'default',
            'created_at' => '2026-09-21 10:00:00',
        ]);

        $filtered = $this->call('GET', '/api/orders', [], $admin, ['status' => 'paid', 'per_page' => 50]);
        self::assertSame(200, $filtered['status']);
        $rows = $filtered['json']['data'] ?? [];
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame('paid', $row['status'] ?? $row['state'] ?? null);
        }
        $numbers = array_column($rows, 'order_number');
        self::assertContains('SKEL-STATUS-PAID', $numbers);
        self::assertNotContains('SKEL-STATUS-CANCELLED', $numbers);
    }
}
