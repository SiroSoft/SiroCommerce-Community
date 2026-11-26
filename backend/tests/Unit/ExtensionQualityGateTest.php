<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Extension\Application\ExtensionQualityGate;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ExtensionQualityGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-qit-' . bin2hex(random_bytes(4));
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

    public function testPassesAScopedAuthenticatedPackage(): void
    {
        $zip = $this->package([
            'migrations/001_create_ext_demo.php' => "<?php\nSchema::create('ext_demo', function (\$table) { \$table->string('store_id'); \$table->string('channel_id'); });\n",
            'routes.php' => "<?php\nreturn [['POST', 'demo', [DemoController::class, 'store'], ['auth', 'throttle:20,1']]];\n",
        ]);
        $report = (new ExtensionQualityGate($this->root))->run($zip);
        self::assertTrue($report['ok'], (string) json_encode($report));
        $names = array_column($report['checks'], 'ok', 'name');
        self::assertTrue($names['package']);
        self::assertTrue($names['store-scope']);
        self::assertTrue($names['route-auth']);
        self::assertTrue($names['secret-scan']);
        self::assertTrue($names['php-lint']);
    }

    public function testFailsUnscopedRoutesSecretsAndSyntax(): void
    {
        $zip = $this->package([
            'migrations/001_create_demo.php' => "<?php\nSchema::create('demo', function (\$table) { });\n",
            'routes.php' => "<?php\nreturn [['POST', 'demo', [DemoController::class, 'store'], ['throttle:20,1']]];\n",
            'src/Config.php' => "<?php\n\$api_key = 'this-is-a-hardcoded-secret';\nfunction broken( {\n",
            'Extension.php' => "<?php\n\nnamespace App\\Extensions\\Siro\\Demo;\n\nfinal class Extension\n{\n}\n",
        ]);
        $report = (new ExtensionQualityGate($this->root))->run($zip);
        self::assertFalse($report['ok']);
        $failed = array_column(array_values(array_filter($report['checks'], static fn (array $check): bool => !$check['ok'])), 'name');
        self::assertContains('store-scope', $failed);
        self::assertContains('route-auth', $failed);
        self::assertContains('secret-scan', $failed);
        self::assertContains('php-lint', $failed);
    }

    /** @param array<string, string> $overrides */
    private function package(array $overrides): string
    {
        $files = array_merge([
            'manifest.json' => (string) json_encode([
                'schema' => 'siro.extension/2',
                'id' => 'siro/demo',
                'type' => 'module',
                'name' => 'Demo',
                'version' => '1.0.0',
                'requires' => ['core' => '^1.0', 'commerce' => '^0.2'],
                'provider' => 'App\\Extensions\\Siro\\Demo\\Extension',
                'capabilities' => [],
                'permissions' => [],
                'migrations' => [],
                'routes' => [],
            ], JSON_THROW_ON_ERROR),
            'Extension.php' => "<?php\n\nnamespace App\\Extensions\\Siro\\Demo;\n\nfinal class Extension\n{\n}\n",
        ], $overrides);
        $checksums = [];
        foreach ($files as $name => $contents) $checksums[$name] = hash('sha256', $contents);
        $files['checksums.json'] = (string) json_encode($checksums, JSON_THROW_ON_ERROR);
        $path = $this->root . DIRECTORY_SEPARATOR . 'pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $contents) $zip->addFromString($name, $contents);
        $zip->close();
        return $path;
    }
}
