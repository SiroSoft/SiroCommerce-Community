<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\MediaLibraryController;
use Siro\Core\Request;
use Siro\Core\Storage;
use Siro\Core\UploadedFile;
use Tests\TestCase;

final class MediaLibraryCoverageTest extends TestCase
{
    /** @var array{0: mixed, 1: string|false} */
    private array $cdnRestore = [null, false];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->cdnRestore = [$_ENV['MEDIA_CDN_URL'] ?? null, getenv('MEDIA_CDN_URL')];
        $_ENV['MEDIA_CDN_URL'] = 'https://cdn.example.test';
        putenv('MEDIA_CDN_URL=https://cdn.example.test');
    }

    protected function tearDown(): void
    {
        [$env, $getenv] = $this->cdnRestore;
        if ($env === null) {
            unset($_ENV['MEDIA_CDN_URL']);
        } else {
            $_ENV['MEDIA_CDN_URL'] = $env;
        }
        if ($getenv === false) {
            putenv('MEDIA_CDN_URL');
        } else {
            putenv('MEDIA_CDN_URL=' . $getenv);
        }
        Storage::reset();
        parent::tearDown();
    }

    public function testIndexRejectsUnknownType(): void
    {
        $response = (new MediaLibraryController())->index($this->request('GET', ['type' => 'exe']));

        self::assertSame(422, $response->statusCode());
        self::assertSame('Invalid type filter', $response->payload()['message'] ?? null);
    }

    public function testIndexFiltersAndRewritesCdnUrlsAndVariants(): void
    {
        $this->insertAsset([
            'id' => 'media-image-1',
            'url' => '/storage/uploads/a.png',
            'storage_path' => 'uploads/a.png',
            'type' => 'image',
            'title' => 'Hero Banner',
            'alt' => 'hero alt',
            'variants_json' => json_encode([
                ['w' => 320, 'url' => '/storage/uploads/a_320.webp', 'format' => 'webp'],
            ], JSON_THROW_ON_ERROR),
            'placeholder' => 'data:image/webp;base64,abc',
        ]);
        $this->insertAsset([
            'id' => 'media-video-1',
            'url' => 'https://video.example.test/clip.mp4',
            'storage_path' => 'uploads/clip.mp4',
            'type' => 'video',
            'title' => 'Launch clip',
            'alt' => null,
            'variants_json' => null,
        ]);
        $this->insertAsset([
            'id' => 'media-file-1',
            'url' => '/storage/uploads/doc.pdf',
            'storage_path' => 'uploads/doc.pdf',
            'type' => 'file',
            'title' => 'Manual',
            'alt' => null,
            'variants_json' => 'not-json',
        ]);

        $all = (new MediaLibraryController())->index($this->request('GET', ['per_page' => '2']));
        self::assertSame(200, $all->statusCode());
        $payload = $all->payload();
        self::assertSame(3, $payload['data']['meta']['total']);
        self::assertCount(2, $payload['data']['data']);

        $images = (new MediaLibraryController())->index($this->request('GET', ['type' => 'image']));
        $image = $images->payload()['data']['data'][0] ?? [];
        self::assertSame('media-image-1', $image['id'] ?? null);
        self::assertSame('https://cdn.example.test/storage/uploads/a.png', $image['url'] ?? null);
        self::assertSame('https://cdn.example.test/storage/uploads/a_320.webp', $image['variants'][0]['url'] ?? null);
        self::assertSame('data:image/webp;base64,abc', $image['placeholder'] ?? null);

        $search = (new MediaLibraryController())->index($this->request('GET', ['q' => 'launch']));
        $rows = $search->payload()['data']['data'] ?? [];
        self::assertCount(1, $rows);
        self::assertSame('media-video-1', $rows[0]['id'] ?? null);
        self::assertSame('https://video.example.test/clip.mp4', $rows[0]['url'] ?? null);
    }

    public function testStoreRequiresKeyAndFileAndRejectsInvalidUpload(): void
    {
        $controller = new MediaLibraryController();

        $noKey = $controller->store($this->request('POST', [], [], []));
        self::assertSame(422, $noKey->statusCode());
        self::assertStringContainsString('Idempotency-Key', (string) ($noKey->payload()['message'] ?? ''));

        $noFile = $controller->store($this->request('POST', [], ['Idempotency-Key' => 'media-key-1'], []));
        self::assertSame(422, $noFile->statusCode());
        self::assertSame('A file is required', $noFile->payload()['message'] ?? null);

        $invalidFile = $controller->store($this->request('POST', [], ['Idempotency-Key' => 'media-key-2'], [
            'file' => new UploadedFile(['name' => 'x.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]),
        ]));
        self::assertSame(422, $invalidFile->statusCode());
        self::assertSame('Upload failed', $invalidFile->payload()['message'] ?? null);
    }

    public function testDestroyRemovesAssetAndFileOrReportsMissing(): void
    {
        Storage::put('uploads/keep.txt', 'bytes');
        $this->insertAsset([
            'id' => 'media-delete-1',
            'url' => '/storage/uploads/keep.txt',
            'storage_path' => 'uploads/keep.txt',
            'type' => 'file',
            'title' => 'Delete me',
            'alt' => null,
            'variants_json' => null,
        ]);

        $controller = new MediaLibraryController();
        $request = $this->request('DELETE', []);
        $request->setParams(['id' => 'media-delete-1']);
        $deleted = $controller->destroy($request);
        self::assertSame(200, $deleted->statusCode());
        self::assertFalse(Storage::exists('uploads/keep.txt'));

        $missing = $this->request('DELETE', []);
        $missing->setParams(['id' => 'media-missing']);
        self::assertSame(404, $controller->destroy($missing)->statusCode());
    }

    /** @param array<string, string|int|null> $query */
    private function request(string $method, array $query = [], array $headers = [], array $files = []): Request
    {
        $request = new Request($method, '/api/admin/media-library', $query, $headers, []);
        if ($files !== []) {
            $property = new \ReflectionProperty(Request::class, 'uploadedFiles');
            $property->setAccessible(true);
            $property->setValue($request, $files);
        }
        return $request;
    }

    /** @param array<string, mixed> $row */
    private function insertAsset(array $row): void
    {
        \Siro\Core\Database::table('media_assets')->insert(array_merge([
            'mime_type' => null,
            'size_bytes' => 10,
            'width' => null,
            'height' => null,
            'created_by' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'placeholder' => null,
        ], $row));
    }
}
