<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UploadController;
use PHPUnit\Framework\TestCase;
use Siro\Core\Request;
use Siro\Core\Storage;
use Siro\Core\UploadedFile;

final class UploadHardeningTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private ?string $previousMaxBytes = null;
    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->previousMaxBytes = $_ENV['UPLOAD_MAX_BYTES'] ?? null;
        $_ENV['UPLOAD_MAX_BYTES'] = '8388608';
    }

    protected function tearDown(): void
    {
        Storage::reset();
        if ($this->previousMaxBytes === null) {
            unset($_ENV['UPLOAD_MAX_BYTES']);
        } else {
            $_ENV['UPLOAD_MAX_BYTES'] = $this->previousMaxBytes;
        }
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testValidPngIsStoredUnderARandomName(): void
    {
        $path = $this->tempFile((string) base64_decode(self::PNG_1X1, true));

        $response = (new UploadController())->store($this->requestWithFile([
            'name' => 'my-avatar.png',
            'tmp_name' => $path,
            'type' => 'image/png',
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK,
        ]));

        self::assertSame(201, $response->statusCode());
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));

        $payload = $response->payload();
        $url = (string) ($payload['data']['url'] ?? '');
        self::assertMatchesRegularExpression('#^/storage/uploads/[a-f0-9]{32}\.png$#', $url);
        self::assertStringNotContainsString('my-avatar', $url);

        $stored = ltrim((string) preg_replace('#^/storage/#', '', $url), '/');
        Storage::assertExists($stored);
    }

    public function testSvgIsRejected(): void
    {
        $path = $this->tempFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $response = (new UploadController())->avatar($this->requestWithFile([
            'name' => 'logo.svg',
            'tmp_name' => $path,
            'type' => 'image/svg+xml',
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK,
        ], 'avatar'));

        self::assertSame(422, $response->statusCode());
        $message = (string) ($response->payload()['message'] ?? '');
        self::assertStringContainsString('not allowed', $message);
        self::assertStringNotContainsString('<svg', $message);
        self::assertStringNotContainsString('alert', $message);
    }

    public function testOversizeFileIsRejectedBeforeStorage(): void
    {
        $_ENV['UPLOAD_MAX_BYTES'] = '16';
        $path = $this->tempFile((string) base64_decode(self::PNG_1X1, true));

        $response = (new UploadController())->store($this->requestWithFile([
            'name' => 'large.png',
            'tmp_name' => $path,
            'type' => 'image/png',
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK,
        ]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('too large', (string) ($response->payload()['message'] ?? ''));
    }

    public function testTextContentWithJpgExtensionIsRejected(): void
    {
        $path = $this->tempFile('<?php echo "not an image";');

        $response = (new UploadController())->store($this->requestWithFile([
            'name' => 'payload.jpg',
            'tmp_name' => $path,
            'type' => 'image/jpeg',
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK,
        ]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('not allowed', (string) ($response->payload()['message'] ?? ''));
    }

    public function testContentMismatchingExtensionIsRejected(): void
    {
        $path = $this->tempFile((string) base64_decode(self::PNG_1X1, true));

        $response = (new UploadController())->store($this->requestWithFile([
            'name' => 'actually-a-png.jpg',
            'tmp_name' => $path,
            'type' => 'image/jpeg',
            'size' => (int) filesize($path),
            'error' => UPLOAD_ERR_OK,
        ]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('does not match', (string) ($response->payload()['message'] ?? ''));
    }

    /** @param array<string, string|int> $file */
    private function requestWithFile(array $file, string $key = 'file'): Request
    {
        $request = new Request('POST', '/api/upload');
        $property = new \ReflectionProperty(Request::class, 'uploadedFiles');
        $property->setAccessible(true);
        $property->setValue($request, [$key => new UploadedFile($file)]);
        return $request;
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'siro-upload-test-');
        if ($path === false) {
            self::fail('Could not create a temporary upload file.');
        }
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }
}
