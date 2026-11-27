<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Application\AuthService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/** Batch 1 Platform §2.7: review media images_json (Platform part). */
final class Batch1ReviewMediaTest extends TestCase
{
    private Router $router;
    /** @var array<string, string> */
    private array $headers = [];
    private string $customerId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $email = 'reviewer-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'reviewer', 'email' => $email, 'password' => 'secret123', 'role' => 'customer']);
        $tokens = $auth->login($email, 'secret123');
        $this->headers = ['Authorization' => 'Bearer ' . $tokens->access];
        $row = Database::table('users')->where('email', $email)->first();
        $this->customerId = is_array($row) ? (string) ($row['id'] ?? '') : '';
        $this->assertNotSame('', $this->customerId);

        Database::table('media_assets')->insert([
            'id' => Ids::new(), 'url' => '/storage/uploads/rev-1.jpg', 'storage_path' => 'uploads/rev-1.jpg',
            'type' => 'image', 'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, json: mixed}
     */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $request = new Request($method, $path, [], $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            $response = $exception->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        return ['status' => $response->statusCode(), 'json' => json_decode($output, true)];
    }

    /** @return array{order_id: string, line_id: string} A fresh delivered line for product p-rev-1. */
    private function deliveredLine(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $orderId = Ids::new();
        $lineId = Ids::new();
        Database::table('product_variants')->insert([
            'id' => 'v-rev-' . $suffix, 'product_id' => 'p-rev-1', 'sku' => 'REV-' . strtoupper($suffix), 'price_minor' => 10000,
        ]);
        Database::table('orders')->insert([
            'id' => $orderId, 'number' => 'REV-' . strtoupper($suffix), 'customer_id' => $this->customerId,
            'currency' => 'VND', 'subtotal_minor' => 10000, 'grand_total_minor' => 10000, 'state' => 'delivered',
            'idempotency_key' => 'rev-' . $suffix, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Database::table('order_lines')->insert([
            'id' => $lineId, 'order_id' => $orderId, 'variant_id' => 'v-rev-' . $suffix,
            'sku' => 'REV-' . strtoupper($suffix), 'name' => 'Review product', 'qty' => 1, 'unit_price_minor' => 10000,
        ]);
        return ['order_id' => $orderId, 'line_id' => $lineId];
    }

    /** @param array<string, mixed> $extra */
    private function submit(array $line, array $extra = []): array
    {
        return $this->call('POST', '/api/products/p-rev-1/reviews', array_merge([
            'order_id' => $line['order_id'], 'order_line_id' => $line['line_id'],
            'rating' => 5, 'title' => 'Great', 'body' => 'Loved it',
        ], $extra), $this->headers);
    }

    public function testValidUploadedMediaUrlIsSaved(): void
    {
        $res = $this->submit($this->deliveredLine(), ['images' => ['/storage/uploads/rev-1.jpg']]);
        self::assertSame(201, $res['status'], (string) json_encode($res['json']));
        $row = Database::table('product_reviews')->where('order_line_id', $res['json']['data']['order_line_id'] ?? '')->first();
        self::assertIsArray($row);
        self::assertSame('["\/storage\/uploads\/rev-1.jpg"]', $row['images_json'] ?? null);
        self::assertSame('["\/storage\/uploads\/rev-1.jpg"]', $res['json']['data']['images_json'] ?? null);
    }

    public function testCdnFormOfUploadedMediaUrlIsAccepted(): void
    {
        $res = $this->submit($this->deliveredLine(), ['images' => ['https://cdn.example.com/storage/uploads/rev-1.jpg']]);
        self::assertSame(201, $res['status'], (string) json_encode($res['json']));
    }

    public function testMoreThanFiveImagesIsRejected(): void
    {
        $images = array_map(static fn (int $i): string => '/storage/uploads/rev-1.jpg', range(1, 6));
        $res = $this->submit($this->deliveredLine(), ['images' => $images]);
        self::assertSame(422, $res['status'], (string) json_encode($res['json']));
        self::assertStringContainsString('at most 5', (string) ($res['json']['message'] ?? ''));
    }

    public function testExternalUrlIsRejected(): void
    {
        $res = $this->submit($this->deliveredLine(), ['images' => ['https://evil.example.com/photo.jpg']]);
        self::assertSame(422, $res['status'], (string) json_encode($res['json']));
        self::assertStringContainsString('uploaded media', (string) ($res['json']['message'] ?? ''));
    }

    public function testReviewWithoutImagesStoresNull(): void
    {
        $res = $this->submit($this->deliveredLine());
        self::assertSame(201, $res['status'], (string) json_encode($res['json']));
        $row = Database::table('product_reviews')->where('order_line_id', $res['json']['data']['order_line_id'] ?? '')->first();
        self::assertIsArray($row);
        self::assertArrayHasKey('images_json', $row);
        self::assertNull($row['images_json']);
    }
}
