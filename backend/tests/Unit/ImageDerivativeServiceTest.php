<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Resources\ProductResource;
use App\Support\ImageDerivativeService;
use PHPUnit\Framework\TestCase;
use Siro\Core\Storage;

final class ImageDerivativeServiceTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempFiles = [];

    /** @var array{0: mixed, 1: string|false} */
    private array $cdnRestore = [null, false];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->cdnRestore = [$_ENV['MEDIA_CDN_URL'] ?? null, getenv('MEDIA_CDN_URL')];
    }

    protected function tearDown(): void
    {
        $this->restoreCdn();
        Storage::reset();
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) @unlink($file);
        }
        parent::tearDown();
    }

    public function testGeneratesWebpAndAvifVariantsAndLqipFromPng(): void
    {
        $source = $this->pngFixture(1200, 800);
        $storagePath = 'products/p-1/images/original.png';
        $hash = hash_file('sha256', $source);
        $supportsAvif = ImageDerivativeService::supportsAvif();

        $result = (new ImageDerivativeService())->generate($source, $storagePath);

        self::assertNotNull($result);
        self::assertCount($supportsAvif ? 6 : 3, $result['variants']);
        self::assertSame(
            $supportsAvif ? [320, 320, 640, 640, 960, 960] : [320, 640, 960],
            array_column($result['variants'], 'w'),
        );
        self::assertSame(
            $supportsAvif ? ['webp', 'avif', 'webp', 'avif', 'webp', 'avif'] : ['webp', 'webp', 'webp'],
            array_column($result['variants'], 'format'),
        );

        foreach ($result['variants'] as $variant) {
            $path = 'products/p-1/images/' . $hash . '_' . $variant['w'] . '.' . $variant['format'];
            self::assertTrue(Storage::exists($path));
            self::assertSame('/storage/' . $path, $variant['url']);

            $bytes = Storage::get($path);
            self::assertNotNull($bytes);
            $info = getimagesizefromstring($bytes);
            self::assertIsArray($info);
            self::assertSame($variant['w'], $info[0]);
            self::assertSame($variant['format'] === 'avif' ? IMAGETYPE_AVIF : IMAGETYPE_WEBP, $info[2]);
            self::assertSame((int) round($variant['w'] * 800 / 1200), $info[1]);
        }

        self::assertStringStartsWith('data:image/webp;base64,', $result['placeholder']);
        $lqip = base64_decode(substr($result['placeholder'], strlen('data:image/webp;base64,')), true);
        self::assertIsString($lqip);
        $lqipInfo = getimagesizefromstring($lqip);
        self::assertIsArray($lqipInfo);
        self::assertSame(32, $lqipInfo[0]);
        self::assertSame(IMAGETYPE_WEBP, $lqipInfo[2]);
    }

    public function testAvifLayoutIsContentHashedBesideWebpWhenSupported(): void
    {
        if (!ImageDerivativeService::supportsAvif()) {
            self::assertTrue(true);
            return;
        }

        $source = $this->pngFixture(640, 640);
        $storagePath = 'uploads/original.png';
        $hash = hash_file('sha256', $source);

        $result = (new ImageDerivativeService())->generate($source, $storagePath);

        self::assertNotNull($result);
        foreach (ImageDerivativeService::WIDTHS as $width) {
            self::assertTrue(Storage::exists('uploads/' . $hash . '_' . $width . '.webp'));
            self::assertTrue(Storage::exists('uploads/' . $hash . '_' . $width . '.avif'));
        }
        self::assertContains('avif', array_column($result['variants'], 'format'));
        self::assertContains('webp', array_column($result['variants'], 'format'));
    }

    public function testGenerationIsIdempotentBySourceHash(): void
    {
        $source = $this->pngFixture(1200, 800);
        $storagePath = 'uploads/original.png';
        $hash = hash_file('sha256', $source);
        $service = new ImageDerivativeService();

        $first = $service->generate($source, $storagePath);
        self::assertNotNull($first);

        $cachedPath = 'uploads/' . $hash . '_320.webp';
        Storage::put($cachedPath, 'cached-bytes');

        $second = $service->generate($source, $storagePath);

        self::assertSame($first, $second);
        self::assertSame('cached-bytes', Storage::get($cachedPath));
    }

    public function testInvalidImageKeepsOriginalAndReturnsNull(): void
    {
        Storage::put('uploads/original.png', 'original-bytes');
        $source = $this->tempFile('not-an-image.png', 'definitely not an image');

        $result = (new ImageDerivativeService())->generate($source, 'uploads/original.png');

        self::assertNull($result);
        self::assertTrue(Storage::exists('uploads/original.png'));
        self::assertSame('original-bytes', Storage::get('uploads/original.png'));
    }

    public function testProductResourceAddsVariantsAndPlaceholderWithoutBreakingShape(): void
    {
        $variants = [
            ['w' => 320, 'url' => '/storage/uploads/a_320.webp', 'format' => 'webp'],
            ['w' => 640, 'url' => '/storage/uploads/a_640.webp', 'format' => 'webp'],
        ];
        $resource = ProductResource::make([
            'id' => 'p-1',
            'name' => 'Photo',
            'images' => [[
                'url' => '/storage/uploads/a.png',
                'alt' => 'Front',
                'role' => 'gallery',
                'variants_json' => json_encode($variants),
                'placeholder' => 'data:image/webp;base64,abc',
            ]],
        ]);

        self::assertSame('/storage/uploads/a.png', $resource['images'][0]['url']);
        self::assertSame('Front', $resource['images'][0]['alt']);
        self::assertSame('gallery', $resource['images'][0]['role']);
        self::assertSame($variants, $resource['images'][0]['variants']);
        self::assertSame('data:image/webp;base64,abc', $resource['images'][0]['placeholder']);
        self::assertArrayNotHasKey('variants_json', $resource['images'][0]);
    }

    public function testCdnRewriteAppliesOnlyToStoragePaths(): void
    {
        $this->withCdn('https://cdn.example.test');

        self::assertSame(
            'https://cdn.example.test/storage/uploads/a.png',
            ImageDerivativeService::publicUrl('/storage/uploads/a.png'),
        );
        self::assertSame(
            'https://cdn.example.test/storage/uploads/a_320.avif',
            ImageDerivativeService::publicUrl('/storage/uploads/a_320.avif'),
        );
        self::assertSame('https://other.example.test/a.png', ImageDerivativeService::publicUrl('https://other.example.test/a.png'));
        self::assertSame('/catalog/b2c/a.png', ImageDerivativeService::publicUrl('/catalog/b2c/a.png'));
        self::assertSame(
            [
                ['w' => 320, 'url' => 'https://cdn.example.test/storage/a_320.webp', 'format' => 'webp'],
                ['w' => 320, 'url' => 'https://cdn.example.test/storage/a_320.avif', 'format' => 'avif'],
            ],
            ImageDerivativeService::publicVariants([
                ['w' => 320, 'url' => '/storage/a_320.webp', 'format' => 'webp'],
                ['w' => 320, 'url' => '/storage/a_320.avif', 'format' => 'avif'],
            ]),
        );
    }

    public function testCdnRewriteIsDisabledWhenUnset(): void
    {
        $this->withCdn(null);

        self::assertSame('/storage/uploads/a.png', ImageDerivativeService::publicUrl('/storage/uploads/a.png'));
        self::assertSame(
            [['w' => 640, 'url' => '/storage/a_640.webp', 'format' => 'webp']],
            ImageDerivativeService::publicVariants([['w' => 640, 'url' => '/storage/a_640.webp', 'format' => 'webp']]),
        );
    }

    public function testImmutableCacheHeaderHelper(): void
    {
        self::assertSame(
            ['Cache-Control' => 'public, max-age=31536000, immutable'],
            ImageDerivativeService::immutableCacheHeaders(),
        );
    }

    public function testProductResourceRewritesOriginalAndVariantsWithCdn(): void
    {
        $this->withCdn('https://cdn.example.test');
        $resource = ProductResource::make([
            'id' => 'p-1',
            'name' => 'Photo',
            'image' => '/storage/uploads/a.png',
            'images' => [[
                'url' => '/storage/uploads/a.png',
                'alt' => 'Front',
                'variants' => [
                    ['w' => 320, 'url' => '/storage/uploads/a_320.webp', 'format' => 'webp'],
                    ['w' => 320, 'url' => '/storage/uploads/a_320.avif', 'format' => 'avif'],
                ],
            ]],
        ]);

        self::assertSame('https://cdn.example.test/storage/uploads/a.png', $resource['image']);
        self::assertSame('https://cdn.example.test/storage/uploads/a.png', $resource['images'][0]['url']);
        self::assertSame('https://cdn.example.test/storage/uploads/a_320.webp', $resource['images'][0]['variants'][0]['url']);
        self::assertSame('https://cdn.example.test/storage/uploads/a_320.avif', $resource['images'][0]['variants'][1]['url']);
    }

    private function withCdn(?string $value): void
    {
        $previousEnv = $_ENV['MEDIA_CDN_URL'] ?? null;
        $previousGetenv = getenv('MEDIA_CDN_URL');
        if ($value === null) {
            unset($_ENV['MEDIA_CDN_URL']);
            putenv('MEDIA_CDN_URL');
        } else {
            $_ENV['MEDIA_CDN_URL'] = $value;
            putenv('MEDIA_CDN_URL=' . $value);
        }
        $this->cdnRestore = [$previousEnv, $previousGetenv];
    }

    protected function restoreCdn(): void
    {
        [$previousEnv, $previousGetenv] = $this->cdnRestore;
        if ($previousEnv === null) {
            unset($_ENV['MEDIA_CDN_URL']);
        } else {
            $_ENV['MEDIA_CDN_URL'] = $previousEnv;
        }
        if ($previousGetenv === false) {
            putenv('MEDIA_CDN_URL');
        } else {
            putenv('MEDIA_CDN_URL=' . $previousGetenv);
        }
    }

    private function pngFixture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        $fill = imagecolorallocate($image, 40, 120, 200);
        self::assertIsInt($fill);
        imagefilledrectangle($image, 0, 0, $width, $height, $fill);
        $path = sys_get_temp_dir() . '/siro-derivative-' . bin2hex(random_bytes(8)) . '.png';
        self::assertTrue(imagepng($image, $path));
        imagedestroy($image);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function tempFile(string $suffix, string $contents): string
    {
        $path = sys_get_temp_dir() . '/siro-derivative-' . bin2hex(random_bytes(8)) . '-' . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }
}
