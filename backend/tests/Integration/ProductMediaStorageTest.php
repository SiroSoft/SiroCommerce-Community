<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Catalog\Application\CatalogCompletenessService;
use App\Modules\Platform\Infrastructure\CatalogAdminAdapter;
use App\Repositories\ProductRepository;
use Siro\Core\Storage;
use Siro\Core\Request;
use Siro\Core\Router;
use Tests\TestCase;

final class ProductMediaStorageTest extends TestCase
{
    public function testMediaHttpRoutesRequireCatalogAuthorization(): void
    {
        $router = new Router();
        $app = new \stdClass();
        $app->router = $router;
        require dirname(__DIR__, 2) . '/routes/api.php';

        self::assertSame(401, $router->dispatch(new Request('GET', '/api/admin/products/p-1/media'))->statusCode());
        self::assertSame(401, $router->dispatch(new Request('DELETE', '/api/admin/products/p-1/media/m-1'))->statusCode());
    }

    public function testPublicMediaOmitsInternalStoragePathAndSupportsOrdering(): void
    {
        Storage::fake();
        $products = new ProductRepository();
        $products->store(['id' => 'media-product-1', 'name' => 'Media', 'slug' => 'media', 'status' => 'draft']);
        $first = $products->storeImage([
            'product_id' => 'media-product-1', 'url' => '/storage/products/media-product-1/images/a.jpg',
            'storage_path' => 'products/media-product-1/images/a.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg', 'sort' => 0,
        ]);
        $second = $products->storeImage([
            'product_id' => 'media-product-1', 'url' => '/storage/products/media-product-1/videos/b.mp4',
            'storage_path' => 'products/media-product-1/videos/b.mp4', 'type' => 'video', 'mime_type' => 'video/mp4', 'sort' => 1,
        ]);
        Storage::put('products/media-product-1/images/a.jpg', 'a');
        Storage::put('products/media-product-1/videos/b.mp4', 'b');

        $products->reorderImages('media-product-1', [(string) $second['id'], (string) $first['id']]);
        $media = $products->images('media-product-1');
        self::assertSame((string) $second['id'], $media[0]['id']);
        self::assertArrayNotHasKey('storage_path', $media[0]);

        $products->deleteImage('media-product-1', (string) $second['id']);
        Storage::assertMissing('products/media-product-1/videos/b.mp4');
        Storage::reset();
    }

    public function testProductImagesExposeStoredDerivativeVariantsAndPlaceholder(): void
    {
        Storage::fake();
        $products = new ProductRepository();
        $products->store(['id' => 'media-product-3', 'name' => 'Derivatives', 'slug' => 'derivatives', 'status' => 'draft']);
        $variants = [
            ['w' => 320, 'url' => '/storage/products/media-product-3/images/hash_320.webp', 'format' => 'webp'],
            ['w' => 640, 'url' => '/storage/products/media-product-3/images/hash_640.webp', 'format' => 'webp'],
        ];
        $products->storeImage([
            'product_id' => 'media-product-3', 'url' => '/storage/products/media-product-3/images/hash.jpg',
            'storage_path' => 'products/media-product-3/images/hash.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg',
            'variants_json' => json_encode($variants), 'placeholder' => 'data:image/webp;base64,abc',
        ]);

        $media = $products->images('media-product-3');
        self::assertSame($variants, $media[0]['variants']);
        self::assertSame('data:image/webp;base64,abc', $media[0]['placeholder']);
        self::assertArrayNotHasKey('variants_json', $media[0]);

        $detail = $products->findBySlug('derivatives');
        self::assertNotNull($detail);
        self::assertSame($variants, $detail['images'][0]['variants']);
        self::assertSame('data:image/webp;base64,abc', $detail['images'][0]['placeholder']);
        Storage::reset();
    }

    public function testExternalMediaUrlRejectsNonHttpSchemes(): void
    {
        $products = new ProductRepository();
        $products->store(['id' => 'media-product-2', 'name' => 'Media', 'slug' => 'media-two', 'status' => 'draft']);
        $service = new CatalogCompletenessService(new CatalogAdminAdapter($products));

        $this->expectException(\InvalidArgumentException::class);
        $service->addMedia('media-product-2', ['url' => 'javascript:alert(1)']);
    }
}
