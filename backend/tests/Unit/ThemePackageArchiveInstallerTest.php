<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\StorefrontConfig\Application\ThemePackageArchiveInstaller;
use PHPUnit\Framework\TestCase;
use Siro\Core\Storage;
use ZipArchive;

final class ThemePackageArchiveInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-theme-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        Storage::fake();
    }

    protected function tearDown(): void
    {
        Storage::reset();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) continue;
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testImportsThemeJsonAndRewritesLocalAssets(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $package = [
            'schema' => 'siro.theme/1',
            'id' => 'acme/pharmacy',
            'name' => 'Pharmacy',
            'version' => '1.0.0',
            'brand' => ['name' => 'Northfield', 'logo_url' => 'assets/logo.png'],
            'homepage' => ['sections' => [['id' => 'hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['image' => 'assets/hero.png']]]],
        ];
        $zip = $this->zip([
            'theme.json' => (string) json_encode($package, JSON_THROW_ON_ERROR),
            'assets/logo.png' => $png,
            'assets/hero.png' => $png,
        ]);
        $result = (new ThemePackageArchiveInstaller())->install($zip);
        self::assertSame('pharmacy', $result['theme_id']);
        self::assertStringStartsWith('/storage/themes/pharmacy/', (string) $result['package']['brand']['logo_url']);
        self::assertStringStartsWith('/storage/themes/pharmacy/', (string) $result['package']['homepage']['sections'][0]['props']['image']);
        self::assertCount(1, $result['assets']);
        $path = 'themes/pharmacy/' . hash('sha256', $png) . '.png';
        Storage::assertExists($path);
    }

    public function testRejectsExecutableAndUnsafeAssets(): void
    {
        $package = [
            'schema' => 'siro.theme/1',
            'id' => 'acme/bad',
            'name' => 'Bad',
            'version' => '1.0.0',
            'homepage' => ['sections' => [['id' => 'hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => []]]],
        ];
        $zip = $this->zip([
            'theme.json' => (string) json_encode($package, JSON_THROW_ON_ERROR),
            'assets/shell.php' => '<?php echo 1;',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        (new ThemePackageArchiveInstaller())->install($zip);
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'theme.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
        return $path;
    }
}
