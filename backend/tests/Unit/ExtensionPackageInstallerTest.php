<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\ExtensionPackageInstaller;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ExtensionPackageInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-ext-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testVerifiesAndInstallsAChecksummedPackage(): void
    {
        $zip = $this->package();
        $installer = new ExtensionPackageInstaller($this->root);
        $report = $installer->verify($zip);
        self::assertTrue($report['ok'], implode('; ', $report['errors']));
        self::assertSame('siro/demo', $report['id']);
        self::assertSame('1.0.0', $report['version']);
        self::assertTrue($report['warnings'] !== []);

        $result = $installer->install($zip);
        self::assertSame('siro/demo', $result['id']);
        self::assertNull($result['backup']);
        self::assertFileExists($this->root . '/app/Extensions/Siro/Demo/Extension.php');
        self::assertStringContainsString('class Extension', (string) file_get_contents($this->root . '/app/Extensions/Siro/Demo/Extension.php'));
    }

    public function testPackProducesAnInstallableArchive(): void
    {
        $source = $this->root . '/source';
        mkdir($source, 0775, true);
        file_put_contents($source . '/manifest.json', $this->manifest());
        file_put_contents($source . '/Extension.php', $this->extensionPhp());
        file_put_contents($source . '/README.md', '# Demo');
        $installer = new ExtensionPackageInstaller($this->root);
        $packed = $installer->pack($source);
        self::assertTrue($packed['ok']);
        self::assertSame(3, $packed['files']);
        $report = $installer->verify($packed['path']);
        self::assertTrue($report['ok'], implode('; ', $report['errors']));
        $installer->install($packed['path']);
        self::assertFileExists($this->root . '/app/Extensions/Siro/Demo/Extension.php');
    }

    public function testInstallWithoutForceRefusesToOverwrite(): void
    {
        $zip = $this->package();
        $installer = new ExtensionPackageInstaller($this->root);
        $installer->install($zip);
        $this->expectException(\RuntimeException::class);
        $installer->install($zip);
    }

    public function testForceInstallBacksUpThePreviousVersion(): void
    {
        $zip = $this->package();
        $installer = new ExtensionPackageInstaller($this->root);
        $installer->install($zip);
        $result = $installer->install($zip, true);
        self::assertNotNull($result['backup']);
        self::assertDirectoryExists((string) $result['backup']);
    }

    public function testRejectsTraversalPaths(): void
    {
        $zip = $this->zip([
            'manifest.json' => $this->manifest(),
            'Extension.php' => $this->extensionPhp(),
            'checksums.json' => json_encode(['Extension.php' => hash('sha256', $this->extensionPhp())], JSON_THROW_ON_ERROR),
            '../evil.php' => '<?php echo 1;',
        ]);
        $report = (new ExtensionPackageInstaller($this->root))->verify($zip);
        self::assertFalse($report['ok']);
        self::assertStringContainsString('unsafe path', implode('; ', $report['errors']));
        self::assertDirectoryDoesNotExist($this->root . '/app/Extensions/Siro/Demo');
    }

    public function testRejectsChecksumMismatch(): void
    {
        $zip = $this->zip([
            'manifest.json' => $this->manifest(),
            'Extension.php' => $this->extensionPhp(),
            'checksums.json' => json_encode(['Extension.php' => str_repeat('0', 64)], JSON_THROW_ON_ERROR),
        ]);
        $report = (new ExtensionPackageInstaller($this->root))->verify($zip);
        self::assertFalse($report['ok']);
        self::assertStringContainsString('Checksum mismatch', implode('; ', $report['errors']));
    }

    public function testSignatureIsRequiredWhenRequested(): void
    {
        $zip = $this->package();
        $report = (new ExtensionPackageInstaller($this->root))->verify($zip, true);
        self::assertFalse($report['ok']);
        self::assertStringContainsString('signature.json is required', implode('; ', $report['errors']));
    }

    public function testMapsHyphenatedIdToAPhpNamespace(): void
    {
        $manifest = json_decode($this->manifest('siro/my-demo'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['provider'] = 'App\\Extensions\\Siro\\MyDemo\\Extension';
        $zip = $this->zip([
            'manifest.json' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'Extension.php' => $this->extensionPhp(),
            'checksums.json' => json_encode(['Extension.php' => hash('sha256', $this->extensionPhp())], JSON_THROW_ON_ERROR),
        ]);
        $installer = new ExtensionPackageInstaller($this->root);
        $report = $installer->verify($zip);
        self::assertTrue($report['ok'], implode('; ', $report['errors']));
        $installer->install($zip);
        self::assertFileExists($this->root . '/app/Extensions/Siro/MyDemo/Extension.php');
    }

    private function package(): string
    {
        return $this->zip([
            'manifest.json' => $this->manifest(),
            'Extension.php' => $this->extensionPhp(),
            'README.md' => '# Demo',
            'checksums.json' => json_encode([
                'Extension.php' => hash('sha256', $this->extensionPhp()),
                'README.md' => hash('sha256', '# Demo'),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function manifest(string $id = 'siro/demo'): string
    {
        return (string) json_encode([
            'schema' => 'siro.extension/2',
            'id' => $id,
            'type' => 'module',
            'name' => 'Demo',
            'version' => '1.0.0',
            'requires' => ['core' => '^1.0', 'commerce' => '^0.1'],
            'provider' => 'App\\Extensions\\Siro\\Demo\\Extension',
            'capabilities' => [],
            'permissions' => [],
            'migrations' => [],
            'routes' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function extensionPhp(): string
    {
        return "<?php\n\nnamespace App\\Extensions\\Siro\\Demo;\n\nfinal class Extension\n{\n}\n";
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
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
