<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Modules\Cms\Application\CmsService;
use App\Modules\Platform\Infrastructure\CmsStoreAdapter;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\TestCase;
use Tests\Support\OwnedContentRoutes;

final class ContentApiReadinessTest extends TestCase
{
    public function testOwnedContentOperationsAreExact(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        foreach (OwnedContentRoutes::all() as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            self::assertStringContainsString("->router->" . strtolower($method) . "('" . $path . "'", $source, $operation);
        }
    }

    public function testPublicReadOperationsReturnStableSuccessEnvelopes(): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        foreach ([
            'GET /api/products/reviews-product/reviews',
            'GET /api/v2/catalog/brands',
            'GET /api/v2/catalog/categories',
        ] as $operation) {
            [$method, $path] = explode(' ', $operation, 2);
            $response = $router->dispatch(new Request($method, $path));
            self::assertSame(200, $response->statusCode(), $operation);
            self::assertTrue($response->payload()['success'] ?? false, $operation);
            self::assertArrayHasKey('data', $response->payload(), $operation);
        }
    }

    public function testPublishedPageHonorsEtagAndKeepsPreviewPrivate(): void
    {
        $page = (new CmsService(new CmsStoreAdapter()))->create([
            'slug' => 'etag-page', 'title' => 'ETag page', 'status' => 'published', 'store_id' => 'default', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'VND',
        ]);
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        $scopeHeaders = ['X-Store-Id' => 'default', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
        $first = $router->dispatch(new Request('GET', '/api/pages/etag-page', [], $scopeHeaders));
        self::assertSame(200, $first->statusCode());
        self::assertArrayHasKey('ETag', $first->headers());
        $cached = $router->dispatch(new Request('GET', '/api/pages/etag-page', [], $scopeHeaders + ['If-None-Match' => $first->headers()['ETag']]));
        self::assertSame(304, $cached->statusCode());
        self::assertSame($first->headers()['ETag'], $cached->headers()['ETag']);

        $preview = $router->dispatch(new Request('GET', '/api/admin/pages/' . $page['id'] . '/preview'));
        self::assertSame(401, $preview->statusCode());
    }
}
