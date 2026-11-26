<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\ExtensionLicenseStore;
use PHPUnit\Framework\TestCase;

final class ExtensionLicenseStoreTest extends TestCase
{
    private string $tempDir;

    private string $storeFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-store-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0775, true);
        $this->storeFile = $this->tempDir . DIRECTORY_SEPARATOR . 'active.json';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '.*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
    }

    public function testWriteReadAndRemoveArePrivateAndAtomic(): void
    {
        $store = new ExtensionLicenseStore(null, $this->storeFile);
        $document = '{"payload":{"license_id":"lic_1"},"signature":"abc"}';

        self::assertFalse($store->exists());
        $store->write($document);

        self::assertTrue($store->exists());
        self::assertSame($document, $store->read());
        self::assertSame($document, (string) file_get_contents($this->storeFile));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, fileperms($this->storeFile) & 0777);
        }

        $temporary = array_filter(
            scandir($this->tempDir) ?: [],
            static fn (string $name): bool => str_ends_with($name, '.tmp')
        );
        self::assertSame([], array_values($temporary));

        self::assertTrue($store->remove());
        self::assertFalse($store->exists());
        self::assertFalse($store->remove());
    }

    public function testWriteRejectsNonObjectJsonWithoutTouchingStore(): void
    {
        $store = new ExtensionLicenseStore(null, $this->storeFile);

        foreach (['[]', '"string"', '123', 'null'] as $document) {
            try {
                $store->write($document);
                self::fail('Non-object JSON must be rejected: ' . $document);
            } catch (\InvalidArgumentException) {
                self::assertFalse($store->exists());
            }
        }
    }

    public function testWriteRejectsMalformedAndOversizedDocuments(): void
    {
        $store = new ExtensionLicenseStore(null, $this->storeFile);
        $oversized = '{"payload":"' . str_repeat('a', ExtensionLicenseStore::MAX_BYTES) . '"}';

        foreach (['{"payload":', $oversized] as $document) {
            try {
                $store->write($document);
                self::fail('Invalid license document must be rejected');
            } catch (\InvalidArgumentException) {
                self::assertFalse($store->exists());
            }
        }
    }

    public function testReadReturnsNullForCorruptStoreFile(): void
    {
        self::assertNotFalse(file_put_contents($this->storeFile, 'not-json'));
        $store = new ExtensionLicenseStore(null, $this->storeFile);

        self::assertTrue($store->exists());
        self::assertNull($store->read());
    }
}
