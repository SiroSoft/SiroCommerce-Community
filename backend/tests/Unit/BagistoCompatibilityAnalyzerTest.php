<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\BagistoCompatibilityAnalyzer;
use PHPUnit\Framework\TestCase;

final class BagistoCompatibilityAnalyzerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bagisto-ext-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) continue;
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($this->root);
    }

    public function testReportsPortWorkAndBuildsAManifestDraft(): void
    {
        $this->write('composer.json', (string) json_encode([
            'name' => 'acme/affiliate',
            'version' => '2.1.0',
            'type' => 'library',
            'require' => ['laravel/framework' => '^11.0'],
            'autoload' => ['psr-4' => ['Acme\\Affiliate\\' => 'src/']],
        ], JSON_THROW_ON_ERROR));
        $this->write('src/Providers/AffiliateServiceProvider.php', '<?php class AffiliateServiceProvider {}');
        $this->write('src/Config/menu.php', "<?php return [['key' => 'affiliate']];");
        $this->write('src/Config/acl.php', "<?php return [['key' => 'affiliate.manage']];");
        $this->write('src/Config/system.php', "<?php return [['key' => 'affiliate.settings']];");
        $this->write('src/Config/payment-methods.php', "<?php return [];");
        $this->write('src/Routes/admin-routes.php', '<?php return [];');
        $this->write('src/Database/Migrations/2026_01_01_create_affiliates.php', "<?php Schema::create('affiliates', function () {});");
        $this->write('src/Resources/views/admin/index.blade.php', '<div>Affiliate</div>');
        $this->write('src/DataGrids/AffiliateDataGrid.php', '<?php class AffiliateDataGrid {}');

        $report = (new BagistoCompatibilityAnalyzer())->analyze($this->root);

        self::assertSame('acme/affiliate', $report['source']['name']);
        self::assertSame('extension', $report['source']['kind']);
        self::assertNotSame('packaging-compatible', $report['level']);
        self::assertContains('Laravel service provider bootstrap', $report['port_required']);
        self::assertContains('migrations (rename ext_* + store/channel scope)', $report['port_required']);
        self::assertTrue(count($report['unsupported']) >= 3);
        self::assertSame(['affiliates'], $report['migrations']['tables']);
        self::assertSame('acme/affiliate', $report['siro_manifest']['id']);
        self::assertSame('2.1.0', $report['siro_manifest']['version']);
        self::assertContains('payment.provider', $report['siro_manifest']['capabilities']);
        self::assertContains('affiliate.manage', $report['siro_manifest']['permissions']);
        self::assertSame($report['level'], $report['siro_manifest']['bagisto']['compatible']);
    }

    public function testThemePackageExtractsAssetsAndPalette(): void
    {
        $this->write('composer.json', (string) json_encode(['name' => 'acme/fashion-theme', 'type' => 'bagisto-theme'], JSON_THROW_ON_ERROR));
        $this->write('Resources/views/home.blade.php', '<div>{{ $title }}</div>');
        $this->write('assets/theme.css', ':root{--ink:#123456;--paper:#abcdef} body{font-family:"Be Vietnam Pro",sans-serif;}');
        $this->write('assets/logo.svg', '<svg></svg>');
        $report = (new BagistoCompatibilityAnalyzer())->analyze($this->root);
        self::assertSame('theme', $report['source']['kind']);
        self::assertSame('theme', $report['siro_manifest']['type']);
        self::assertGreaterThanOrEqual(2, $report['theme']['assets']);
        self::assertSame(1, $report['theme']['blade_templates']);
        self::assertContains('#123456', $report['theme']['colors']);
        self::assertNotEmpty($report['theme']['fonts']);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        file_put_contents($path, $contents);
    }
}
