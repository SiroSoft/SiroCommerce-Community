<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Domain\ExtensionManifest;
use App\Support\ExtensionScaffolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionScaffolderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-extension-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Could not create scaffold test directory.');
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testCreateWritesExpectedFilesAndValidManifest(): void
    {
        $result = ExtensionScaffolder::create('Acme', 'Widget', $this->root);

        self::assertCount(5, $result['created']);
        self::assertSame([], $result['skipped']);

        $dir = $this->extensionDir('Acme', 'Widget');
        foreach (['Extension.php', 'manifest.json', 'routes.php', 'StatusController.php', 'README.md'] as $file) {
            self::assertFileExists($dir . DIRECTORY_SEPARATOR . $file);
        }

        $json = json_decode((string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('acme/widget', $json['id'] ?? null);
        self::assertSame('Acme Widget', $json['name'] ?? null);
        self::assertSame('0.1.0', $json['version'] ?? null);
        self::assertSame(['widget:manage'], $json['permissions'] ?? null);
        self::assertSame(['routes.php'], $json['routes'] ?? null);
        self::assertSame([], $json['migrations'] ?? null);
        self::assertSame([], $json['config'] ?? null);

        $manifest = ExtensionManifest::fromArray($json);
        self::assertSame('acme/widget', $manifest->id);
        self::assertSame('0.1.0', $manifest->version);
        self::assertArrayNotHasKey('admin', $json);

        $extension = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'Extension.php');
        self::assertStringContainsString("return 'acme/widget';", $extension);
        self::assertStringContainsString("'version' => '0.1.0'", $extension);
        self::assertStringContainsString("'permissions' => ['widget:manage']", $extension);
        self::assertStringContainsString("'slots' =>", $extension);
        self::assertStringContainsString("'storefront' => []", $extension);
        self::assertStringNotContainsString('eval(', $extension);

        $routes = require $dir . DIRECTORY_SEPARATOR . 'routes.php';
        self::assertIsArray($routes);
        self::assertCount(1, $routes);
        self::assertSame(['GET', 'status'], [$routes[0][0] ?? null, $routes[0][1] ?? null]);

        $readme = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'README.md');
        self::assertStringContainsString('/api/ext/acme/widget/status', $readme);
    }

    public function testManifestDeclaresAdminAndStorefrontSlots(): void
    {
        ExtensionScaffolder::create('Acme', 'Widget', $this->root);

        $json = json_decode((string) file_get_contents($this->extensionDir('Acme', 'Widget') . DIRECTORY_SEPARATOR . 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        $slots = $json['slots'] ?? null;
        self::assertIsArray($slots);
        self::assertSame([], $slots['storefront'] ?? null);

        $admin = $slots['admin'] ?? null;
        self::assertIsArray($admin);
        self::assertSame('Acme Widget', $admin['label'] ?? null);
        self::assertSame('Extensions', $admin['section'] ?? null);

        $panels = $admin['panels'] ?? null;
        self::assertIsArray($panels);
        self::assertCount(2, $panels);
        self::assertSame(['stats', 'table'], array_map(static fn (array $panel): string => $panel['type'], $panels));
        self::assertSame(['overview', 'records'], array_map(static fn (array $panel): string => $panel['key'], $panels));

        foreach ($panels as $panel) {
            self::assertSame('widget:manage', $panel['permission']);
            self::assertContains($panel['permission'], $json['permissions']);
            self::assertStringNotContainsString('://', $panel['endpoint']);
            self::assertStringStartsNotWith('/', $panel['endpoint']);
            self::assertStringNotContainsString('..', $panel['endpoint']);
        }
    }

    public function testReadmeDocumentsSlotGuidance(): void
    {
        ExtensionScaffolder::create('Acme', 'Widget', $this->root);

        $readme = (string) file_get_contents($this->extensionDir('Acme', 'Widget') . DIRECTORY_SEPARATOR . 'README.md');

        self::assertStringContainsString('## Slots', $readme);
        self::assertStringContainsString('slots.admin', $readme);
        self::assertStringContainsString('"storefront": []', $readme);
        foreach (['header', 'footer', 'pdp', 'cart', 'checkout', 'my-account', 'email', 'order-status'] as $slot) {
            self::assertStringContainsString('`' . $slot . '`', $readme);
        }
        self::assertStringContainsString('apps/storefront', $readme);
        self::assertStringContainsString('raw', $readme);
        foreach (['extension:verify', 'extension:qit', 'extension:install', 'Enable'] as $step) {
            self::assertStringContainsString($step, $readme);
        }
    }

    public function testRerunDoesNotOverwriteExistingFiles(): void
    {
        ExtensionScaffolder::create('Acme', 'Widget', $this->root);

        $file = $this->extensionDir('Acme', 'Widget') . DIRECTORY_SEPARATOR . 'Extension.php';
        file_put_contents($file, "// local sentinel\n", FILE_APPEND);

        $result = ExtensionScaffolder::create('Acme', 'Widget', $this->root);

        self::assertSame([], $result['created']);
        self::assertCount(5, $result['skipped']);
        self::assertStringContainsString('// local sentinel', (string) file_get_contents($file));
    }

    public function testForceOverwritesGeneratedFiles(): void
    {
        ExtensionScaffolder::create('Acme', 'Widget', $this->root);

        $file = $this->extensionDir('Acme', 'Widget') . DIRECTORY_SEPARATOR . 'Extension.php';
        file_put_contents($file, "// local sentinel\n", FILE_APPEND);

        $result = ExtensionScaffolder::create('Acme', 'Widget', $this->root, true);

        self::assertCount(5, $result['created']);
        self::assertSame([], $result['skipped']);
        self::assertStringNotContainsString('// local sentinel', (string) file_get_contents($file));
    }

    public function testIdIsLowercaseVendorSlashName(): void
    {
        ExtensionScaffolder::create('Siro', 'Affiliate2', $this->root);

        $json = json_decode((string) file_get_contents($this->extensionDir('Siro', 'Affiliate2') . DIRECTORY_SEPARATOR . 'manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('siro/affiliate2', $json['id'] ?? null);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidSegments(): array
    {
        return [
            'traversal vendor' => ['../Evil', 'Widget'],
            'slash vendor' => ['Acme/Evil', 'Widget'],
            'backslash vendor' => ['Acme\\Evil', 'Widget'],
            'lowercase vendor' => ['acme', 'Widget'],
            'empty vendor' => ['', 'Widget'],
            'space name' => ['Acme', 'Bad Name'],
            'unicode name' => ['Acme', 'Wídget'],
            'traversal name' => ['Acme', '..'],
            'numeric name' => ['Acme', '2Widget'],
        ];
    }

    #[DataProvider('invalidSegments')]
    public function testRejectsInvalidVendorOrName(string $vendor, string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtensionScaffolder::create($vendor, $name, $this->root);
    }

    private function extensionDir(string $vendor, string $name): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Extensions'
            . DIRECTORY_SEPARATOR . $vendor . DIRECTORY_SEPARATOR . $name;
    }

    private function remove(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
