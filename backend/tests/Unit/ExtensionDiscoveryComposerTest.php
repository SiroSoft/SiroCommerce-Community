<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ExtensionInterface;
use App\Modules\Extension\Application\ExtensionDiscovery;
use PHPUnit\Framework\TestCase;

final class ComposerDiscoveredExtension implements ExtensionInterface
{
    public static function id(): string { return 'acme/composer'; }
    public static function compatibleCore(): string { return '^1.0'; }
    public static function compatibleCommerce(): string { return '^0.2'; }
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return ['id' => self::id(), 'version' => '1.0.0', 'requires' => ['core' => '^1.0', 'commerce' => '^0.2'], 'capabilities' => [], 'permissions' => [], 'migrations' => [], 'routes' => []];
    }
    /** @return array<string, int|string> */
    public function config(): array { return []; }
    /** @return array<string, \App\Contracts\PaymentGatewayInterface> */
    public function paymentGateways(): array { return []; }
    /** @return array<string, \App\Contracts\ShippingProviderInterface> */
    public function shippingProviders(): array { return []; }
    public function boot(): void {}
    public function shutdown(): void {}
}

final class ExtensionDiscoveryComposerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-discovery-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/vendor/composer', 0775, true);
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

    public function testDiscoversComposerPackagesDeclaringSiroExtension(): void
    {
        file_put_contents($this->root . '/vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/composer', 'extra' => ['siro' => ['extension' => ComposerDiscoveredExtension::class]]],
            ['name' => 'acme/ignored', 'extra' => ['siro' => ['extension' => 'Acme\\Ignored\\Extension']]],
            ['name' => 'acme/not-siro', 'extra' => []],
        ]], JSON_THROW_ON_ERROR));
        $classes = ExtensionDiscovery::classes($this->root);
        self::assertContains(ComposerDiscoveredExtension::class, $classes);
        self::assertNotContains('Acme\\Ignored\\Extension', $classes);
    }

    public function testMalformedManifestDoesNotBreakDiscovery(): void
    {
        file_put_contents($this->root . '/vendor/composer/installed.json', '{not json');
        self::assertSame([], ExtensionDiscovery::classes($this->root));
    }
}
