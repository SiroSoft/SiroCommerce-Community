<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Domain\ExtensionManifest;
use App\Support\ExtensionScaffolder;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/** Batch 1 Platform §2.2: manifest-driven slot registry (backend side). */
final class ExtensionManifestSlotsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-ext-slots-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    /** @return array<string, mixed> */
    private function baseManifest(): array
    {
        return [
            'id' => 'siro/demo',
            'version' => '1.0.0',
            'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
            'capabilities' => [],
            'permissions' => [],
            'migrations' => [],
            'routes' => [],
        ];
    }

    public function testFromArrayReadsValidSlotsIncludingNewBatch1Names(): void
    {
        $data = $this->baseManifest();
        $data['slots'] = ['admin' => ['label' => 'Demo', 'section' => 'Commerce'], 'storefront' => ['pdp', 'product-card', 'pdp.title', 'pdp.below-atc', 'pdp.below-gallery']];
        $manifest = ExtensionManifest::fromArray($data);
        self::assertSame(['pdp', 'product-card', 'pdp.title', 'pdp.below-atc', 'pdp.below-gallery'], $manifest->storefrontSlots());
        self::assertSame($data['slots'], $manifest->toArray()['slots']);
    }

    public function testFromArrayDefaultsToNoSlots(): void
    {
        $manifest = ExtensionManifest::fromArray($this->baseManifest());
        self::assertSame([], $manifest->storefrontSlots());
        self::assertSame([], $manifest->toArray()['slots']);
    }

    public function testFromArrayRejectsUnknownSlotName(): void
    {
        $data = $this->baseManifest();
        $data['slots'] = ['storefront' => ['pdp', 'no-such-slot']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown slot "no-such-slot"/');
        ExtensionManifest::fromArray($data);
    }

    public function testFromArrayRejectsNonArraySlots(): void
    {
        $data = $this->baseManifest();
        $data['slots'] = 'pdp';
        $this->expectException(\InvalidArgumentException::class);
        ExtensionManifest::fromArray($data);

        $data['slots'] = ['storefront' => 'pdp'];
        $this->expectException(\InvalidArgumentException::class);
        ExtensionManifest::fromArray($data);
    }

    public function testInstallerVerifyRejectsUnknownSlotName(): void
    {
        $manifest = $this->baseManifest();
        $manifest['schema'] = 'siro.extension/2';
        $manifest['type'] = 'module';
        $manifest['name'] = 'Demo';
        $manifest['provider'] = 'App\\Extensions\\Siro\\Demo\\Extension';
        $manifest['slots'] = ['storefront' => ['checkout', 'bogus-slot']];
        $zip = $this->package($manifest);
        $report = (new ExtensionPackageInstaller($this->root))->verify($zip);
        self::assertFalse($report['ok']);
        self::assertStringContainsString('unknown slot "bogus-slot"', implode('; ', $report['errors']));
    }

    public function testInstallerVerifyAcceptsNewBatch1SlotNames(): void
    {
        $manifest = $this->baseManifest();
        $manifest['schema'] = 'siro.extension/2';
        $manifest['type'] = 'module';
        $manifest['name'] = 'Demo';
        $manifest['provider'] = 'App\\Extensions\\Siro\\Demo\\Extension';
        $manifest['slots'] = ['storefront' => ['product-card', 'pdp.below-atc']];
        $zip = $this->package($manifest);
        $report = (new ExtensionPackageInstaller($this->root))->verify($zip);
        self::assertTrue($report['ok'], implode('; ', $report['errors']));
    }

    public function testScaffoldEmitsManifestSchemaV2(): void
    {
        ExtensionScaffolder::create('Acme', 'Widget', $this->root);
        $dir = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Extensions' . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'Widget';
        $json = json_decode((string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('siro.extension/2', $json['schema'] ?? null);
        // The scaffolded manifest must still parse through the registry validator.
        $manifest = ExtensionManifest::fromArray($json);
        self::assertSame([], $manifest->storefrontSlots());
    }

    /** @param array<string, mixed> $manifest */
    private function package(array $manifest): string
    {
        $extensionPhp = "<?php\n\nnamespace App\\Extensions\\Siro\\Demo;\n\nfinal class Extension\n{\n}\n";
        $files = [
            'manifest.json' => (string) json_encode($manifest, JSON_THROW_ON_ERROR),
            'Extension.php' => $extensionPhp,
            'README.md' => '# Demo',
            'checksums.json' => (string) json_encode([
                'Extension.php' => hash('sha256', $extensionPhp),
                'README.md' => hash('sha256', '# Demo'),
            ], JSON_THROW_ON_ERROR),
        ];
        $path = $this->root . DIRECTORY_SEPARATOR . 'pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();
        return $path;
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) continue;
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
