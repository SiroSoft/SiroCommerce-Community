<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Models\Extension;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Extension\Application\ExtensionPackageInstaller;
use App\Modules\Extension\Domain\ExtensionManifest;
use Siro\Core\Database;
use Tests\TestCase;
use ZipArchive;

/**
 * Batch 1 Platform §2.2 dependency guard (Wave 0 supplement, user decision
 * 26 Nov 2026 ~18:47 ICT — Abandoned Cart ships as 2 packages, free +
 * Pro). The guard is generic: any extension declaring `requires_extensions`
 * is enforced the same way; Cart free/Pro is only the first user.
 */
final class Batch1ExtensionDependencyGuardTest extends TestCase
{
    private const FREE = 'siro/test-cart-free';
    private const PRO = 'siro/test-cart-pro';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::reset();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-guard-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        $this->removeDir($this->root);
        parent::tearDown();
    }

    private function service(): ExtensionLifecycleService
    {
        // No implementation classes: enable() then only flips state (no
        // migrations/boot), which is all the guard logic needs.
        return new ExtensionLifecycleService([]);
    }

    /** @param list<string> $requiresExtensions */
    private function manifest(string $id, array $requiresExtensions = []): ExtensionManifest
    {
        return ExtensionManifest::fromArray([
            'id' => $id,
            'version' => '1.0.0',
            'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
            'capabilities' => [],
            'permissions' => [],
            'requires_extensions' => $requiresExtensions,
        ]);
    }

    private function installBoth(): void
    {
        $service = $this->service();
        $service->install($this->manifest(self::FREE));
        $service->install($this->manifest(self::PRO, [self::FREE]));
    }

    private function state(string $id): ?string
    {
        $row = Extension::find($id);
        return $row === null ? null : ($row->toArray()['state'] ?? null);
    }

    public function testEnableProBlockedWhenFreeNotEnabled(): void
    {
        $this->installBoth();
        try {
            $this->service()->enable(self::PRO);
            self::fail('Enable must be blocked while the dependency is not enabled');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(self::FREE, $e->getMessage());
            self::assertStringContainsString('to be enabled', $e->getMessage());
        }
        self::assertSame('installed', $this->state(self::PRO));
    }

    public function testEnableProBlockedWhenFreeNotInstalled(): void
    {
        $service = $this->service();
        $service->install($this->manifest(self::PRO, [self::FREE]));
        try {
            $service->enable(self::PRO);
            self::fail('Enable must be blocked while the dependency is not installed');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(self::FREE, $e->getMessage());
            self::assertStringContainsString('to be installed and enabled', $e->getMessage());
        }
    }

    public function testEnableProSucceedsWhenFreeEnabled(): void
    {
        $this->installBoth();
        $service = $this->service();
        $service->enable(self::FREE);
        $service->enable(self::PRO);
        self::assertSame('enabled', $this->state(self::FREE));
        self::assertSame('enabled', $this->state(self::PRO));
    }

    public function testDisableFreeBlockedWhenProEnabled(): void
    {
        $this->installBoth();
        $service = $this->service();
        $service->enable(self::FREE);
        $service->enable(self::PRO);
        try {
            $service->disable(self::FREE);
            self::fail('Disable must be blocked while an enabled extension requires it');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(self::PRO, $e->getMessage());
            self::assertStringContainsString('require it', $e->getMessage());
        }
        self::assertSame('enabled', $this->state(self::FREE));
        self::assertSame('enabled', $this->state(self::PRO));
    }

    public function testUninstallFreeBlockedWhenProEnabled(): void
    {
        $this->installBoth();
        $service = $this->service();
        $service->enable(self::FREE);
        $service->enable(self::PRO);
        foreach ([false, true] as $removeData) {
            try {
                $service->uninstall(self::FREE, $removeData);
                self::fail('Uninstall must be blocked while an enabled extension requires it');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString(self::PRO, $e->getMessage());
            }
        }
        self::assertSame('enabled', $this->state(self::FREE));
    }

    public function testDisableProKeepsProDataAndFreesDependency(): void
    {
        $this->installBoth();
        $service = $this->service();
        $service->enable(self::FREE);
        $service->enable(self::PRO);
        // Stand-in for ext_abandoned_cart_pro_* tables: Pro owns its data.
        Database::connection()->exec('CREATE TABLE ext_test_pro_data (id TEXT PRIMARY KEY, note TEXT)');
        Database::connection()->exec("INSERT INTO ext_test_pro_data (id, note) VALUES ('row-1', 'pro segment')");
        $service->disable(self::PRO);
        self::assertSame('disabled', $this->state(self::PRO));
        $rows = Database::connection()->query("SELECT note FROM ext_test_pro_data WHERE id = 'row-1'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['pro segment'], $rows);
        // With Pro disabled, the free package can be disabled again.
        $service->disable(self::FREE);
        self::assertSame('disabled', $this->state(self::FREE));
    }

    public function testManifestRejectsInvalidRequiresExtensions(): void
    {
        foreach ([
            ['BAD ID'],
            ['siro/test-cart-pro', 'siro/test-cart-pro'],
            ['siro/ok', 'siro/ok'],
        ] as $bad) {
            try {
                ExtensionManifest::fromArray([
                    'id' => 'siro/test-cart-pro', 'version' => '1.0.0',
                    'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
                    'requires_extensions' => $bad,
                ]);
                self::fail('Expected InvalidArgumentException for requires_extensions ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            ExtensionManifest::fromArray([
                'id' => 'siro/test-cart-pro', 'version' => '1.0.0',
                'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
                'requires_extensions' => ['siro/test-cart-pro'],
            ]);
            self::fail('Self-dependency must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('itself', $e->getMessage());
        }
        $ok = $this->manifest(self::PRO, [self::FREE]);
        self::assertSame([self::FREE], $ok->requiresExtensions);
        self::assertSame([self::FREE], $ok->toArray()['requires_extensions']);
    }

    public function testInstallerVerifyRejectsInvalidRequiresExtensions(): void
    {
        $manifest = [
            'schema' => 'siro.extension/2', 'id' => 'siro/demo', 'type' => 'module', 'name' => 'Demo',
            'version' => '1.0.0', 'provider' => 'App\\Extensions\\Siro\\Demo\\Extension',
            'requires' => ['core' => '^1.0', 'commerce' => '^0.3'],
            'requires_extensions' => ['BAD ID'],
        ];
        $report = (new ExtensionPackageInstaller($this->root))->verify($this->package($manifest));
        self::assertFalse($report['ok']);
        self::assertStringContainsString('requires_extensions', implode('; ', $report['errors']));
        $manifest['requires_extensions'] = [self::FREE];
        $report = (new ExtensionPackageInstaller($this->root))->verify($this->package($manifest));
        self::assertTrue($report['ok'], implode('; ', $report['errors']));
    }

    /** @param array<string, mixed> $manifest */
    private function package(array $manifest): string
    {
        $extensionPhp = "<?php\n\nnamespace App\\Extensions\\Siro\\Demo;\n\nfinal class Extension\n{\n}\n";
        $files = [
            'manifest.json' => (string) json_encode($manifest, JSON_THROW_ON_ERROR),
            'Extension.php' => $extensionPhp,
        ];
        $path = $this->root . DIRECTORY_SEPARATOR . 'demo-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) $zip->addFromString($name, $content);
        $checksums = [];
        foreach ($files as $name => $content) $checksums[$name] = hash('sha256', $content);
        $zip->addFromString('checksums.json', (string) json_encode($checksums, JSON_THROW_ON_ERROR));
        $zip->close();
        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
