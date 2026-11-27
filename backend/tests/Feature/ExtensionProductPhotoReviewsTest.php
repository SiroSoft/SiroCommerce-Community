<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Extensions\Siro\ProductPhotoReviews\Extension as PhotoReviewsExtension;
use App\Extensions\Siro\ProductPhotoReviews\PhotoReviewsService;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Application\ExtensionQualityGate;
use App\Modules\Extension\Application\ExtensionScope;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

final class ExtensionProductPhotoReviewsTest extends TestCase
{
    private Router $router;

    /** @var list<array<string, mixed>> */
    private array $seenApproved = [];

    /** @var array<string, string> */
    private array $customerHeaders = [];

    private string $customerId = '';

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(PhotoReviewsExtension::class);
        ExtensionRegistry::reset();
        Event::flush();
        Event::on('review.approved', function (mixed $payload): void {
            if (is_array($payload)) {
                $this->seenApproved[] = $payload;
            }
        });
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $email = 'photo-reviewer-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'reviewer', 'email' => $email, 'password' => 'secret123', 'role' => 'customer']);
        $tokens = $auth->login($email, 'secret123');
        $this->customerHeaders = ['Authorization' => 'Bearer ' . $tokens->access];
        $row = Database::table('users')->where('email', $email)->first();
        $this->customerId = is_array($row) ? (string) ($row['id'] ?? '') : '';
        $this->assertNotSame('', $this->customerId);

        foreach (['rev-1.jpg', 'rev-2.jpg'] as $file) {
            Database::table('media_assets')->insert([
                'id' => Ids::new(), 'url' => '/storage/uploads/' . $file, 'storage_path' => 'uploads/' . $file,
                'type' => 'image', 'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Event::flush();
        ExtensionRegistry::reset();
        parent::tearDown();
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $query
     * @return array{status: int, json: mixed}
     */
    private function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        $request = new Request($method, $path, $query, $headers, $body, '127.0.0.1');
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

    private function lifecycle(): ExtensionLifecycleService
    {
        return new ExtensionLifecycleService(ExtensionDiscovery::classes($this->basePath()));
    }

    /** @return array{access: string, role: string} */
    private function token(string $role = 'viewer'): array
    {
        $email = 'photo-' . bin2hex(random_bytes(4)) . '@test.local';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => 'photo', 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');
        return ['access' => $tokens->access, 'role' => $role];
    }

    /** @return array<string, string> */
    private function headers(?string $access = null, string $store = 'default', string $channel = 'web', ?string $key = null): array
    {
        $headers = [
            'X-Store-Id' => $store,
            'X-Channel-Id' => $channel,
            'X-Locale' => 'en',
            'X-Currency' => 'VND',
            'Idempotency-Key' => $key ?? ('photo-' . bin2hex(random_bytes(6))),
        ];
        if ($access !== null && $access !== '') {
            $headers['Authorization'] = 'Bearer ' . $access;
        }
        return $headers;
    }

    private function enablePhotoReviews(): void
    {
        $service = $this->lifecycle();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new PhotoReviewsExtension())->manifest()));
        $service->enable(PhotoReviewsExtension::id());
        $this->remountRoutes();
    }

    private function remountRoutes(): void
    {
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require $this->basePath() . '/routes/api.php';
    }

    /** @return array{order_id: string, line_id: string} A fresh delivered line for product p-rev-1. */
    private function deliveredLine(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $orderId = Ids::new();
        $lineId = Ids::new();
        Database::table('product_variants')->insert([
            'id' => 'v-photo-' . $suffix, 'product_id' => 'p-rev-1', 'sku' => 'PHOTO-' . strtoupper($suffix), 'price_minor' => 10000,
        ]);
        Database::table('orders')->insert([
            'id' => $orderId, 'number' => 'PHOTO-' . strtoupper($suffix), 'customer_id' => $this->customerId,
            'currency' => 'VND', 'subtotal_minor' => 10000, 'grand_total_minor' => 10000, 'state' => 'delivered',
            'idempotency_key' => 'photo-' . $suffix, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        Database::table('order_lines')->insert([
            'id' => $lineId, 'order_id' => $orderId, 'variant_id' => 'v-photo-' . $suffix,
            'sku' => 'PHOTO-' . strtoupper($suffix), 'name' => 'Review product', 'qty' => 1, 'unit_price_minor' => 10000,
        ]);
        return ['order_id' => $orderId, 'line_id' => $lineId];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{status: int, json: mixed}
     */
    private function submitReview(array $line, array $extra = []): array
    {
        return $this->call('POST', '/api/products/p-rev-1/reviews', array_merge([
            'order_id' => $line['order_id'], 'order_line_id' => $line['line_id'],
            'rating' => 5, 'title' => 'Great', 'body' => 'Loved it',
        ], $extra), $this->customerHeaders);
    }

    /** @return array{status: int, json: mixed} */
    private function submitReviewWithPhotos(): array
    {
        return $this->submitReview($this->deliveredLine(), ['images' => ['/storage/uploads/rev-1.jpg', '/storage/uploads/rev-2.jpg']]);
    }

    /** @param array<string, mixed> $row */
    private function rowId(array $row): string
    {
        $id = $row['id'] ?? null;
        self::assertIsString($id);
        self::assertNotSame('', $id);
        return $id;
    }

    /** @return array<string, mixed> */
    private function approveReviewViaRoute(string $reviewId, string $access): array
    {
        $res = $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/reviews/' . $reviewId, ['status' => 'approved'], $this->headers($access));
        self::assertSame(200, $res['status'], (string) json_encode($res['json']));
        self::assertIsArray($res['json']['data'] ?? null);
        return $res['json']['data'];
    }

    public function testManifestAndDiscoveryDeclareScopedPhotoReviews(): void
    {
        self::assertContains(PhotoReviewsExtension::class, ExtensionDiscovery::classes($this->basePath()));
        $manifest = (new PhotoReviewsExtension())->manifest();

        self::assertSame('siro/product-photo-reviews', $manifest['id']);
        self::assertSame('1.0.0', $manifest['version']);
        self::assertSame(['photo-reviews:read', 'photo-reviews:moderate'], $manifest['capabilities']);
        self::assertSame(['photo-reviews:moderate'], $manifest['permissions']);
        self::assertSame(['pdp.below-gallery'], $manifest['slots']['storefront']);
        self::assertSame('migrations/20261126000031_create_ext_product_review_media.php', $manifest['migrations'][0]);
        self::assertSame('admin/overview', $manifest['admin']['panels'][0]['endpoint']);
        self::assertSame('admin', $manifest['admin']['panels'][1]['endpoint']);
        self::assertSame('stats', $manifest['admin']['panels'][0]['type']);
        self::assertSame('table', $manifest['admin']['panels'][1]['type']);

        $parsed = ExtensionManifest::fromArray($manifest);
        self::assertSame('siro/product-photo-reviews', $parsed->id);
    }

    public function testPublicGalleryEmptyBeforeApprovalShowsAfterApproval(): void
    {
        $this->enablePhotoReviews();
        $created = $this->submitReviewWithPhotos();
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $reviewId = $this->rowId($created['json']['data']);

        $pending = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1']);
        self::assertSame(200, $pending['status']);
        self::assertSame([], $pending['json']['data']);

        $admin = $this->token('admin');
        $this->approveReviewViaRoute($reviewId, $admin['access']);

        self::assertCount(1, $this->seenApproved);
        self::assertSame($reviewId, $this->seenApproved[0]['review_id'] ?? null);
        self::assertSame('p-rev-1', $this->seenApproved[0]['product_id'] ?? null);

        $gallery = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1']);
        self::assertSame(200, $gallery['status']);
        self::assertIsArray($gallery['json']);
        self::assertCount(2, $gallery['json']['data']);
        self::assertSame('/storage/uploads/rev-1.jpg', $gallery['json']['data'][0]['url']);
        self::assertSame('/storage/uploads/rev-2.jpg', $gallery['json']['data'][1]['url']);
        self::assertSame(5, $gallery['json']['data'][0]['rating']);
        self::assertSame('Great', $gallery['json']['data'][0]['title']);
        self::assertSame($reviewId, $gallery['json']['data'][0]['review_id']);

        $missing = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers());
        self::assertSame(422, $missing['status']);

        $tooLong = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => str_repeat('p', 65)]);
        self::assertSame(422, $tooLong['status']);

        $otherProduct = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-other']);
        self::assertSame(200, $otherProduct['status']);
        self::assertSame([], $otherProduct['json']['data']);
    }

    public function testAdminRejectHidesOnePhotoKeepsOther(): void
    {
        $this->enablePhotoReviews();
        $created = $this->submitReviewWithPhotos();
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $reviewId = $this->rowId($created['json']['data']);
        $admin = $this->token('admin');
        $this->approveReviewViaRoute($reviewId, $admin['access']);

        $queue = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], $this->headers($admin['access']), ['state' => 'all']);
        self::assertSame(200, $queue['status']);
        self::assertCount(2, $queue['json']['data']);
        // Select rows by URL, never by position: the queue is newest-first,
        // and same-second rows must not be assumed to follow gallery order.
        $byUrl = [];
        foreach ($queue['json']['data'] as $row) {
            if (is_array($row) && isset($row['url']) && is_string($row['url'])) {
                $byUrl[$row['url']] = $row;
            }
        }
        self::assertArrayHasKey('/storage/uploads/rev-1.jpg', $byUrl);
        self::assertArrayHasKey('/storage/uploads/rev-2.jpg', $byUrl);
        $firstId = $this->rowId($byUrl['/storage/uploads/rev-1.jpg']);
        $secondId = $this->rowId($byUrl['/storage/uploads/rev-2.jpg']);

        $rejected = $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $firstId, ['status' => 'rejected', 'moderation_note' => 'Blurry'], $this->headers($admin['access']));
        self::assertSame(200, $rejected['status']);
        self::assertSame('rejected', $rejected['json']['data']['status']);
        self::assertSame('Blurry', $rejected['json']['data']['moderation_note']);

        $gallery = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1']);
        self::assertCount(1, $gallery['json']['data']);
        self::assertSame('/storage/uploads/rev-2.jpg', $gallery['json']['data'][0]['url']);

        // Idempotent replay of the same moderation.
        $replay = $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $firstId, ['status' => 'rejected', 'moderation_note' => 'Blurry'], $this->headers($admin['access']));
        self::assertSame(200, $replay['status']);
        self::assertSame($rejected['json']['data']['updated_at'], $replay['json']['data']['updated_at']);

        // Approving the photo again brings it back to the gallery.
        $approved = $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $firstId, ['status' => 'approved'], $this->headers($admin['access']));
        self::assertSame(200, $approved['status']);
        $galleryAgain = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1']);
        self::assertCount(2, $galleryAgain['json']['data']);

        // Cross-scope moderation must not see the row.
        $crossStore = $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $secondId, ['status' => 'rejected'], $this->headers($admin['access'], 'store-b', 'web'));
        self::assertSame(404, $crossStore['status']);

        // Invalid inputs.
        self::assertSame(422, $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $secondId, ['status' => 'hacked'], $this->headers($admin['access']))['status']);
        self::assertSame(422, $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $secondId, ['status' => 'rejected', 'moderation_note' => '<b>no</b>'], $this->headers($admin['access']))['status']);
        self::assertSame(404, $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . Ids::new(), ['status' => 'rejected'], $this->headers($admin['access']))['status']);
        self::assertSame(404, $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/reviews/' . Ids::new(), ['status' => 'approved'], $this->headers($admin['access']))['status']);
    }

    public function testAdminRoutesRequireAuthAndManageCapability(): void
    {
        $this->enablePhotoReviews();

        $anonymous = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web']);
        self::assertSame(401, $anonymous['status']);

        $viewer = $this->token('viewer');
        $denied = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], $this->headers($viewer['access']));
        self::assertSame(403, $denied['status']);
        $deniedOverview = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin/overview', [], $this->headers($viewer['access']));
        self::assertSame(403, $deniedOverview['status']);

        $admin = $this->token('admin');
        $allowed = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], $this->headers($admin['access']));
        self::assertSame(200, $allowed['status']);
        $overview = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin/overview', [], $this->headers($admin['access']));
        self::assertSame(200, $overview['status']);
        self::assertIsArray($overview['json']['data']['stats'] ?? null);
        self::assertIsArray($overview['json']['data']['table']['rows'] ?? null);
    }

    public function testModerationDecisionsAreScopedPerStore(): void
    {
        $this->enablePhotoReviews();
        $created = $this->submitReviewWithPhotos();
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $reviewId = $this->rowId($created['json']['data']);
        $admin = $this->token('admin');
        $this->approveReviewViaRoute($reviewId, $admin['access']);

        // Reject the first photo in the default store only.
        $queue = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], $this->headers($admin['access']), ['state' => 'all']);
        $firstId = $this->rowId($queue['json']['data'][0]);
        self::assertSame(200, $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $firstId, ['status' => 'rejected'], $this->headers($admin['access']))['status']);

        $defaultGallery = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1']);
        self::assertCount(1, $defaultGallery['json']['data']);

        // Another store has its own moderation rows: nothing rejected there.
        $otherGallery = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(null, 'store-b', 'web'), ['product_id' => 'p-rev-1']);
        self::assertSame(200, $otherGallery['status']);
        self::assertCount(2, $otherGallery['json']['data']);

        // A row read with a foreign scope fails closed via ExtensionScope.
        $scope = new CommerceScope('default', 'web', 'en', 'VND');
        $service = new PhotoReviewsService();
        $rows = $service->adminQueue($scope, 'all');
        self::assertNotSame([], $rows);
        $foreign = $rows[0];
        $foreign['store_id'] = 'store-b';
        $this->expectException(CommerceScopeException::class);
        ExtensionScope::assertScoped($foreign, $scope);
    }

    public function testPutWithoutIdempotencyKeyIsRejected(): void
    {
        $this->enablePhotoReviews();
        $created = $this->submitReviewWithPhotos();
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $reviewId = $this->rowId($created['json']['data']);
        $admin = $this->token('admin');
        $this->approveReviewViaRoute($reviewId, $admin['access']);

        $queue = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], $this->headers($admin['access']), ['state' => 'all']);
        $firstId = $this->rowId($queue['json']['data'][0]);

        $withoutKey = ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'Authorization' => 'Bearer ' . $admin['access']];
        self::assertSame(422, $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/media/' . $firstId, ['status' => 'rejected'], $withoutKey)['status']);
    }

    public function testThrowingReviewApprovedListenerDoesNotBreakModeration(): void
    {
        $this->enablePhotoReviews();
        Event::on('review.approved', static function (): void {
            throw new \RuntimeException('listener boom');
        });
        $created = $this->submitReviewWithPhotos();
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $reviewId = $this->rowId($created['json']['data']);
        $admin = $this->token('admin');
        $res = $this->call('PUT', '/api/ext/siro/product-photo-reviews/admin/reviews/' . $reviewId, ['status' => 'approved'], $this->headers($admin['access']));
        self::assertSame(200, $res['status'], (string) json_encode($res['json']));
        self::assertSame('approved', $res['json']['data']['status']);
    }

    public function testAdminOverviewCountsPhotos(): void
    {
        $this->enablePhotoReviews();
        $created = $this->submitReviewWithPhotos();
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $reviewId = $this->rowId($created['json']['data']);
        $admin = $this->token('admin');

        $before = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin/overview', [], $this->headers($admin['access']));
        self::assertSame(200, $before['status']);
        $stats = [];
        foreach ($before['json']['data']['stats'] as $stat) {
            $stats[$stat['label']] = $stat['value'];
        }
        self::assertSame('2', $stats['Photos pending'] ?? null);

        $this->approveReviewViaRoute($reviewId, $admin['access']);
        // Gallery ensure flips observed photos of approved reviews to approved.
        $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1']);
        $after = $this->call('GET', '/api/ext/siro/product-photo-reviews/admin/overview', [], $this->headers($admin['access']));
        $statsAfter = [];
        foreach ($after['json']['data']['stats'] as $stat) {
            $statsAfter[$stat['label']] = $stat['value'];
        }
        self::assertSame('0', $statsAfter['Photos pending'] ?? null);
        self::assertSame('2', $statsAfter['Photos approved'] ?? null);
        self::assertSame('1', $statsAfter['Products with photos'] ?? null);
    }

    public function testDisableUnplugsRoutesAndUninstallKeepDataReinstallReuses(): void
    {
        $this->enablePhotoReviews();
        $created = $this->submitReviewWithPhotos();
        self::assertSame(201, $created['status'], (string) json_encode($created['json']));
        $reviewId = $this->rowId($created['json']['data']);
        $admin = $this->token('admin');
        $this->approveReviewViaRoute($reviewId, $admin['access']);
        self::assertSame(200, $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1'])['status']);
        self::assertSame(200, $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], $this->headers($admin['access']))['status']);

        $lifecycle = $this->lifecycle();
        $lifecycle->disable(PhotoReviewsExtension::id());
        $this->remountRoutes();

        self::assertSame(404, $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1'])['status']);
        self::assertSame(404, $this->call('GET', '/api/ext/siro/product-photo-reviews/admin', [], $this->headers($admin['access']))['status']);
        self::assertSame(200, $this->call('GET', '/health/live')['status']);

        // Uninstall keeping data: rows survive and reinstall reuses them.
        $lifecycle->uninstall(PhotoReviewsExtension::id(), false);
        $kept = Database::connection()->query("SELECT COUNT(*) FROM ext_product_review_media")->fetchColumn();
        self::assertSame(2, (int) $kept);

        $service = $this->lifecycle();
        $service->discover();
        $service->install(ExtensionManifest::fromArray((new PhotoReviewsExtension())->manifest()));
        $service->enable(PhotoReviewsExtension::id());
        $this->remountRoutes();
        $gallery = $this->call('GET', '/api/ext/siro/product-photo-reviews/public', [], $this->headers(), ['product_id' => 'p-rev-1']);
        self::assertSame(200, $gallery['status']);
        self::assertCount(2, $gallery['json']['data']);

        // Uninstall removing data: ext table drops, core stays intact.
        $lifecycle->uninstall(PhotoReviewsExtension::id(), true);
        $tables = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_product_review_media'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
        $products = Database::connection()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['products'], $products);
        $reviews = Database::connection()->query("SELECT COUNT(*) FROM product_reviews")->fetchColumn();
        self::assertSame(1, (int) $reviews);
    }

    public function testExtensionPackagePassesQualityGate(): void
    {
        $output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-product-photo-reviews-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $source = $this->basePath() . '/app/Extensions/Siro/ProductPhotoReviews';
            $packed = (new ExtensionPackageInstaller($this->basePath()))->pack($source, $output);
            self::assertTrue($packed['ok'], (string) json_encode($packed));
            self::assertFileExists($output);

            $report = (new ExtensionQualityGate($this->basePath()))->run($output);
            self::assertTrue($report['ok'], (string) json_encode($report));
            $names = array_map(static fn (array $check): string => (string) $check['name'], $report['checks']);
            foreach (['package', 'signature', 'store-scope', 'route-auth', 'secret-scan', 'php-lint'] as $name) {
                self::assertContains($name, $names);
            }
        } finally {
            @unlink($output);
        }
    }
}
