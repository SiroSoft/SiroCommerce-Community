<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Extension\ExtensionRegistry;
use App\Models\Extension;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Extension\Application\EcosystemCatalogService;
use App\Modules\Extension\Application\ExtensionDiscovery;
use App\Modules\Extension\Application\ExtensionLifecycleService;
use App\Modules\Platform\Infrastructure\UserStoreAdapter;
use App\Repositories\UserRepository;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Router;
use Siro\Core\ValidationException;
use Tests\TestCase;

/**
 * P1 ecosystem catalog read model: schema validation, filters, runtime merge,
 * update detection, compatibility, license status and admin HTTP surface.
 */
final class EcosystemCatalogTest extends TestCase
{
    private Router $router;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionRegistry::reset();
        $this->router = new Router();
        $app = new \stdClass();
        $app->router = $this->router;
        require dirname(__DIR__, 2) . '/routes/api.php';
    }

    protected function tearDown(): void
    {
        ExtensionRegistry::reset();
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testCatalogLoadsSchemaAndRequiredItems(): void
    {
        $service = new EcosystemCatalogService($this->base());
        $catalog = $service->catalog();

        self::assertSame(EcosystemCatalogService::SCHEMA, $catalog['schema']);
        self::assertNotSame('', $catalog['generated_at']);
        self::assertCount(15, $catalog['items']);

        $ids = array_column($catalog['items'], 'id');
        foreach ([
            'flat-rate',
            'siro/affiliate',
            'siro/license-portal',
            'siro/contact-widget',
            'siro/free-shipping-banner',
            'siro/product-qa',
            'siro/shipping-fee-rules',
            'siro/size-guide',
            'siro/social-share',
            'siro/store-locator',
            'siro/fashion',
            'siro/pharmacy',
            'siro/fnb',
            'siro/atelier-noir',
            'siro/apothecary-calm',
        ] as $id) {
            self::assertContains($id, $ids);
        }

        $affiliate = $this->item($catalog['items'], 'siro/affiliate');
        self::assertNotNull($affiliate);
        self::assertSame('plugin', $affiliate['type']);
        self::assertSame('pro', $affiliate['edition']);
        self::assertTrue($affiliate['license_required']);
        self::assertSame('siro-affiliate', $affiliate['license_sku']);
        self::assertSame('planned', $affiliate['status']);
        self::assertSame('1.0.0', $affiliate['latest_version']);
        self::assertIsString($affiliate['changelog']);
        self::assertNotSame('', $affiliate['changelog']);
        self::assertFalse($affiliate['installable']);
        self::assertFalse($affiliate['can_update']);
        self::assertSame('Pro license and signed artifact required', $affiliate['action_reason']);

        $qa = $this->item($catalog['items'], 'siro/product-qa');
        self::assertNotNull($qa);
        self::assertIsString($qa['changelog']);
        self::assertLessThanOrEqual(1000, mb_strlen($qa['changelog']));
        self::assertTrue($qa['installable']);
        self::assertFalse($qa['can_update']);
        self::assertNull($qa['action_reason']);

        $theme = $this->item($catalog['items'], 'siro/fashion');
        self::assertNotNull($theme);
        self::assertSame('theme', $theme['type']);
        self::assertSame('free', $theme['edition']);
        self::assertSame('available', $theme['installed_state']);
        self::assertFalse($theme['update_available']);
        self::assertSame('not_required', $theme['license_status']);
        self::assertFalse($theme['installable']);
        self::assertFalse($theme['can_update']);

        foreach ([
            'siro/atelier-noir' => 'siro-theme-atelier-noir',
            'siro/apothecary-calm' => 'siro-theme-apothecary-calm',
        ] as $id => $sku) {
            $premium = $this->item($catalog['items'], $id);
            self::assertNotNull($premium);
            self::assertSame('theme', $premium['type']);
            self::assertSame('pro', $premium['edition']);
            self::assertSame('planned', $premium['status']);
            self::assertTrue($premium['license_required']);
            self::assertSame($sku, $premium['license_sku']);
            self::assertSame('1.0.0', $premium['latest_version']);
            self::assertSame('available', $premium['installed_state']);
            self::assertFalse($premium['update_available']);
            self::assertIsString($premium['changelog']);
            self::assertNotSame('', $premium['changelog']);
            self::assertNull($premium['artifact_url']);
            self::assertNull($premium['sha256']);
            self::assertSame('missing', $premium['license_status']);
            self::assertFalse($premium['installable']);
            self::assertFalse($premium['can_update']);
            self::assertSame('Pro license and signed artifact required', $premium['action_reason']);
        }
    }

    public function testLoadRejectsDuplicateAndInvalidEntries(): void
    {
        $valid = [
            'id' => 'siro/sample',
            'type' => 'plugin',
            'name' => 'Sample',
            'description' => 'Sample item',
            'vendor' => 'Siro',
            'edition' => 'free',
            'status' => 'published',
            'latest_version' => '1.0.0',
            'min_core' => '^1.0',
            'min_commerce' => '^0.2',
            'license_required' => false,
            'license_sku' => null,
            'preview_url' => null,
            'changelog_url' => null,
            'artifact_url' => null,
            'sha256' => null,
            'published_at' => '2026-11-24',
            'tags' => [],
        ];

        $cases = [
            'duplicate' => [[$valid, $valid], 'duplicate'],
            'invalid type' => [[['type' => 'module'] + $valid], 'type'],
            'invalid edition' => [[['edition' => 'enterprise'] + $valid], 'edition'],
            'invalid status' => [[['status' => 'live'] + $valid], 'status'],
            'invalid id' => [[['id' => 'Bad/Id'] + $valid], 'id'],
            'invalid url' => [[['preview_url' => 'javascript:alert(1)'] + $valid], 'preview_url'],
            'invalid sha' => [[['sha256' => 'abc'] + $valid], 'sha256'],
            'changelog too long' => [[['changelog' => str_repeat('a', 1001)] + $valid], 'changelog'],
            'changelog control char' => [[['changelog' => "line one\nline two\x07"] + $valid], 'changelog'],
            'changelog carriage return' => [[['changelog' => "line one\rline two"] + $valid], 'changelog'],
            'changelog html' => [[['changelog' => '<script>alert(1)</script>'] + $valid], 'changelog'],
            'changelog not string' => [[['changelog' => 123] + $valid], 'changelog'],
            'pro without license flag' => [[['edition' => 'pro', 'license_required' => false] + $valid], 'license_required'],
            'pro missing license flag' => [[['edition' => 'pro'] + array_diff_key($valid, ['license_required' => true])], 'license_required'],
        ];

        foreach ($cases as $name => [$items, $needle]) {
            $path = $this->tempCatalog($this->payload($items));
            try {
                (new EcosystemCatalogService($this->base(), $path))->load();
                self::fail($name . ' should fail');
            } catch (\RuntimeException $e) {
                self::assertStringContainsStringIgnoringCase((string) $needle, $e->getMessage(), $name);
                self::assertStringNotContainsString($path, $e->getMessage(), $name);
                self::assertStringNotContainsString($this->base(), $e->getMessage(), $name);
            }
        }

        $path = $this->tempCatalog(['schema' => 'siro.other/1', 'generated_at' => '2026-11-24T00:00:00Z', 'items' => []]);
        try {
            (new EcosystemCatalogService($this->base(), $path))->load();
            self::fail('invalid schema should fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('schema', $e->getMessage());
            self::assertStringNotContainsString($path, $e->getMessage());
        }
    }

    public function testCatalogFiltersAndInvalidFilterValues(): void
    {
        $service = new EcosystemCatalogService($this->base());

        self::assertCount(10, $service->catalog('plugin')['items']);
        self::assertCount(5, $service->catalog('theme')['items']);

        $pro = $service->catalog('all', 'pro');
        self::assertCount(3, $pro['items']);
        $proIds = array_column($pro['items'], 'id');
        foreach (['siro/affiliate', 'siro/atelier-noir', 'siro/apothecary-calm'] as $id) {
            self::assertContains($id, $proIds);
        }

        self::assertCount(12, $service->catalog('all', 'free')['items']);

        $search = $service->catalog('all', 'all', '  AFFILIATE  ');
        self::assertCount(1, $search['items']);
        self::assertSame('siro/affiliate', $search['items'][0]['id']);

        $this->assertInvalidArgument(static fn () => $service->catalog('bad'));
        $this->assertInvalidArgument(static fn () => $service->catalog('all', 'bad'));
        $this->assertInvalidArgument(static fn () => $service->catalog('all', 'all', str_repeat('a', 81)));
    }

    public function testMergeInstalledStateUpdateDetectionAndCompatibility(): void
    {
        $lifecycle = new ExtensionLifecycleService(ExtensionDiscovery::classes($this->base()));
        $target = null;
        foreach ($lifecycle->discover() as $manifest) {
            if ($manifest->id === 'siro/product-qa') {
                $target = $manifest;
                break;
            }
        }
        self::assertNotNull($target);
        $lifecycle->install($target);

        $payload = $this->baseCatalog();
        foreach ($payload['items'] as &$item) {
            if (($item['id'] ?? null) === 'siro/product-qa') {
                $item['latest_version'] = '1.1.0';
                $item['min_core'] = '^9.0';
            }
        }
        unset($item);

        $service = new EcosystemCatalogService($this->base(), $this->tempCatalog($payload));
        $items = $service->catalog('plugin')['items'];

        $qa = $this->item($items, 'siro/product-qa');
        self::assertNotNull($qa);
        self::assertSame('installed', $qa['installed_state']);
        self::assertSame('1.0.0', $qa['installed_version']);
        self::assertTrue($qa['update_available']);
        self::assertFalse($qa['compatible']);

        $contact = $this->item($items, 'siro/contact-widget');
        self::assertNotNull($contact);
        self::assertSame('available', $contact['installed_state']);
        self::assertNull($contact['installed_version']);
        self::assertFalse($contact['update_available']);
        self::assertTrue($contact['compatible']);
    }

    public function testLicenseStatusNotRequiredAndMissing(): void
    {
        $previous = getenv('EXTENSION_LICENSE_FILE');
        $previousEnv = $_ENV['EXTENSION_LICENSE_FILE'] ?? null;
        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'siro-missing-license-' . bin2hex(random_bytes(4)) . '.json';
        putenv('EXTENSION_LICENSE_FILE=' . $missing);
        $_ENV['EXTENSION_LICENSE_FILE'] = $missing;

        try {
            $items = (new EcosystemCatalogService($this->base()))->catalog()['items'];

            $qa = $this->item($items, 'siro/product-qa');
            self::assertNotNull($qa);
            self::assertSame('not_required', $qa['license_status']);

            $affiliate = $this->item($items, 'siro/affiliate');
            self::assertNotNull($affiliate);
            self::assertSame('missing', $affiliate['license_status']);

            $theme = $this->item($items, 'siro/fnb');
            self::assertNotNull($theme);
            self::assertSame('not_required', $theme['license_status']);

            $premium = $this->item($items, 'siro/atelier-noir');
            self::assertNotNull($premium);
            self::assertSame('missing', $premium['license_status']);
        } finally {
            if ($previous === false) {
                putenv('EXTENSION_LICENSE_FILE');
            } else {
                putenv('EXTENSION_LICENSE_FILE=' . $previous);
            }
            if ($previousEnv === null) {
                unset($_ENV['EXTENSION_LICENSE_FILE']);
            } else {
                $_ENV['EXTENSION_LICENSE_FILE'] = $previousEnv;
            }
        }
    }

    public function testFallbackDiscoveredPluginsAppearWithoutCatalogEntry(): void
    {
        $service = new EcosystemCatalogService(
            $this->base(),
            $this->tempCatalog($this->payload([]))
        );

        $items = $service->catalog('plugin')['items'];
        self::assertNotEmpty($items);

        $qa = $this->item($items, 'siro/product-qa');
        self::assertNotNull($qa);
        self::assertSame('plugin', $qa['type']);
        self::assertSame('siro/product-qa', $qa['name']);
        self::assertSame('free', $qa['edition']);
        self::assertSame('published', $qa['status']);
        self::assertSame('1.0.0', $qa['latest_version']);
        self::assertSame('not_required', $qa['license_status']);

        $flatRate = $this->item($items, 'flat-rate');
        self::assertNotNull($flatRate);
        self::assertSame('flat-rate', $flatRate['name']);

        self::assertSame([], $service->catalog('theme')['items']);
    }

    public function testUpdatesContainOnlyNewerInstalledItems(): void
    {
        $lifecycle = new ExtensionLifecycleService(ExtensionDiscovery::classes($this->base()));
        foreach ($lifecycle->discover() as $manifest) {
            if ($manifest->id === 'siro/product-qa') {
                $lifecycle->install($manifest);
            }
        }

        $payload = $this->baseCatalog();
        foreach ($payload['items'] as &$item) {
            if (($item['id'] ?? null) === 'siro/product-qa') {
                $item['latest_version'] = '1.2.0';
            }
        }
        unset($item);

        $updates = (new EcosystemCatalogService($this->base(), $this->tempCatalog($payload)))->updates();
        self::assertCount(1, $updates['items']);
        self::assertSame('siro/product-qa', $updates['items'][0]['id']);
        self::assertTrue($updates['items'][0]['update_available']);
    }

    public function testHttpEndpointsAuthFiltersAndShapes(): void
    {
        self::assertSame(401, $this->call('GET', '/api/admin/ecosystem/catalog')['status']);
        self::assertSame(403, $this->call('GET', '/api/admin/ecosystem/catalog', [], $this->headers('viewer'))['status']);

        $admin = $this->headers('admin');
        $catalog = $this->call('GET', '/api/admin/ecosystem/catalog', [], $admin);
        self::assertSame(200, $catalog['status']);
        self::assertSame(EcosystemCatalogService::SCHEMA, $catalog['json']['data']['schema']);
        self::assertCount(15, $catalog['json']['data']['items']);
        self::assertStringNotContainsString('resources', (string) json_encode($catalog['json']));

        $theme = $this->call('GET', '/api/admin/ecosystem/catalog?type=theme', [], $admin);
        self::assertSame(200, $theme['status']);
        self::assertCount(5, $theme['json']['data']['items']);
        foreach ($theme['json']['data']['items'] as $item) {
            self::assertSame('theme', $item['type']);
        }

        $pro = $this->call('GET', '/api/admin/ecosystem/catalog?edition=pro', [], $admin);
        self::assertSame(200, $pro['status']);
        self::assertCount(3, $pro['json']['data']['items']);
        $proIds = array_column($pro['json']['data']['items'], 'id');
        foreach (['siro/affiliate', 'siro/atelier-noir', 'siro/apothecary-calm'] as $id) {
            self::assertContains($id, $proIds);
        }

        $search = $this->call('GET', '/api/admin/ecosystem/catalog?q=affiliate', [], $admin);
        self::assertSame(200, $search['status']);
        self::assertCount(1, $search['json']['data']['items']);

        self::assertSame(422, $this->call('GET', '/api/admin/ecosystem/catalog?type=bad', [], $admin)['status']);
        self::assertSame(422, $this->call('GET', '/api/admin/ecosystem/catalog?edition=bad', [], $admin)['status']);
        self::assertSame(422, $this->call('GET', '/api/admin/ecosystem/catalog?q=' . str_repeat('a', 81), [], $admin)['status']);

        $updates = $this->call('GET', '/api/admin/ecosystem/updates', [], $admin);
        self::assertSame(200, $updates['status']);
        self::assertSame([], $updates['json']['data']['items']);
        self::assertSame(401, $this->call('GET', '/api/admin/ecosystem/updates')['status']);
    }

    public function testMalformedCatalogReturnsSafeError(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'siro-catalog-bad-');
        self::assertIsString($path);
        $this->tempFiles[] = $path;
        file_put_contents($path, '{"schema":');

        try {
            (new EcosystemCatalogService($this->base(), $path))->load();
            self::fail('malformed catalog should fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('malformed', $e->getMessage());
            self::assertStringNotContainsString($path, $e->getMessage());
            self::assertStringNotContainsString(dirname($path), $e->getMessage());
        }
    }

    public function testChangelogAcceptsPlainTextWithNewlinesUpToLimit(): void
    {
        $item = [
            'id' => 'siro/sample',
            'type' => 'plugin',
            'name' => 'Sample',
            'description' => 'Sample item',
            'vendor' => 'Siro',
            'edition' => 'free',
            'status' => 'published',
            'latest_version' => '1.0.0',
            'min_core' => '^1.0',
            'min_commerce' => '^0.2',
            'license_required' => false,
            'license_sku' => null,
            'preview_url' => null,
            'changelog_url' => null,
            'changelog' => "First line\nSecond line\nThird line",
            'artifact_url' => null,
            'sha256' => null,
            'published_at' => '2026-11-24',
            'tags' => [],
        ];

        $loaded = (new EcosystemCatalogService($this->base(), $this->tempCatalog($this->payload([$item]))))->load();
        self::assertCount(1, $loaded['items']);
        self::assertSame($item['changelog'], $loaded['items'][0]['changelog']);

        $item['changelog'] = str_repeat('a', 1000);
        $loaded = (new EcosystemCatalogService($this->base(), $this->tempCatalog($this->payload([$item]))))->load();
        self::assertSame(1000, mb_strlen((string) $loaded['items'][0]['changelog']));
    }

    public function testBundledInstallAddsInstalledStateWithoutEnabling(): void
    {
        $headers = $this->headers('admin') + ['Idempotency-Key' => 'install-' . bin2hex(random_bytes(4))];
        $response = $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/product-qa'], $headers);

        self::assertSame(200, $response['status']);
        self::assertSame('siro/product-qa', $response['json']['data']['id']);
        self::assertSame('installed', $response['json']['data']['state']);
        self::assertSame('1.0.0', $response['json']['data']['version']);

        $row = Extension::query()->where('id', 'siro/product-qa')->first();
        self::assertInstanceOf(Extension::class, $row);
        self::assertSame('installed', $row->state);
        self::assertSame('1.0.0', $row->version);
        self::assertNull(ExtensionRegistry::extension('siro/product-qa'));
    }

    public function testInstallRejectsDuplicateProAndUnknownItems(): void
    {
        $admin = $this->headers('admin');
        $first = $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/product-qa'], $admin + ['Idempotency-Key' => 'dup-1']);
        self::assertSame(200, $first['status']);

        $duplicate = $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/product-qa'], $admin + ['Idempotency-Key' => 'dup-2']);
        self::assertSame(409, $duplicate['status']);

        $pro = $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/affiliate'], $admin + ['Idempotency-Key' => 'pro-1']);
        self::assertSame(422, $pro['status']);
        self::assertStringContainsString('Pro license and signed artifact required', (string) json_encode($pro['json']));

        $unknown = $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/not-real'], $admin + ['Idempotency-Key' => 'unknown-1']);
        self::assertSame(404, $unknown['status']);
    }

    public function testInstallUpdateRequireAuthAndIdempotencyKey(): void
    {
        self::assertSame(401, $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/product-qa'])['status']);
        self::assertSame(403, $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/product-qa'], $this->headers('viewer') + ['Idempotency-Key' => 'viewer-1'])['status']);

        $admin = $this->headers('admin');
        self::assertSame(422, $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/product-qa'], $admin)['status']);
        self::assertSame(422, $this->call('POST', '/api/admin/ecosystem/update', ['id' => 'siro/product-qa'], $admin)['status']);
    }

    public function testUpdateWithoutNewerVersionReturns409(): void
    {
        $admin = $this->headers('admin');
        $install = $this->call('POST', '/api/admin/ecosystem/install', ['id' => 'siro/product-qa'], $admin + ['Idempotency-Key' => 'up-install']);
        self::assertSame(200, $install['status']);

        $update = $this->call('POST', '/api/admin/ecosystem/update', ['id' => 'siro/product-qa'], $admin + ['Idempotency-Key' => 'up-none']);
        self::assertSame(409, $update['status']);
    }

    public function testUpdatePreservesDisabledStateAndNeverMigrates(): void
    {
        $lifecycle = new ExtensionLifecycleService(ExtensionDiscovery::classes($this->base()));
        $target = null;
        foreach ($lifecycle->discover() as $manifest) {
            if ($manifest->id === 'siro/product-qa') {
                $target = $manifest;
                break;
            }
        }
        self::assertNotNull($target);
        $lifecycle->install($target);
        $lifecycle->disable('siro/product-qa');

        $payload = $this->baseCatalog();
        foreach ($payload['items'] as &$item) {
            if (($item['id'] ?? null) === 'siro/product-qa') {
                $item['latest_version'] = '1.1.0';
            }
        }
        unset($item);

        $result = (new EcosystemCatalogService($this->base(), $this->tempCatalog($payload)))
            ->updateBundled('siro/product-qa');

        self::assertSame('siro/product-qa', $result['id']);
        self::assertSame('1.0.0', $result['from']);
        self::assertSame('1.0.0', $result['to']);
        self::assertSame('disabled', $result['state']);

        $row = Extension::query()->where('id', 'siro/product-qa')->first();
        self::assertInstanceOf(Extension::class, $row);
        self::assertSame('disabled', $row->state);
        self::assertSame('1.0.0', $row->version);

        $tables = Database::connection()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ext_product_qa'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $tables);
    }

    public function testCatalogProItemsRemainPlannedWithoutArtifactOrSignature(): void
    {
        $raw = $this->baseCatalog();
        foreach ($raw['items'] as $item) {
            self::assertArrayHasKey('signature', $item);
            self::assertArrayHasKey('signature_url', $item);
        }

        $mergedItems = (new EcosystemCatalogService($this->base()))->catalog()['items'];
        foreach (['siro/affiliate', 'siro/atelier-noir', 'siro/apothecary-calm'] as $id) {
            $catalogItem = $this->item($raw['items'], $id);
            self::assertNotNull($catalogItem);
            self::assertSame('pro', $catalogItem['edition']);
            self::assertSame('planned', $catalogItem['status']);
            self::assertNull($catalogItem['artifact_url']);
            self::assertNull($catalogItem['sha256']);
            self::assertNull($catalogItem['signature']);
            self::assertNull($catalogItem['signature_url']);

            $merged = $this->item($mergedItems, $id);
            self::assertNotNull($merged);
            self::assertSame('planned', $merged['status']);
            self::assertNull($merged['artifact_url']);
            self::assertNull($merged['sha256']);
        }
    }

    public function testBuildScriptRequiresSignatureForPublishedProAndStripsItFromPublicFeed(): void
    {
        $repoRoot = dirname($this->base());
        $builder = $repoRoot . '/scripts/ecosystem-catalog/build.php';

        $payload = $this->baseCatalog();
        foreach ($payload['items'] as &$item) {
            if (($item['id'] ?? null) === 'siro/affiliate') {
                $item['status'] = 'published';
                $item['artifact_url'] = 'https://cdn.example.com/artifacts/siro-affiliate-1.0.0.siro.zip';
                $item['sha256'] = str_repeat('a', 64);
                $item['signature'] = null;
            }
        }
        unset($item);

        $missingPath = $this->tempCatalog($payload);
        $missing = $this->runPhp([PHP_BINARY, $builder, '--in=' . $missingPath, '--check']);
        self::assertSame(4, $missing['code']);
        self::assertStringContainsString('signature', $missing['stderr']);

        foreach ($payload['items'] as &$item) {
            if (($item['id'] ?? null) === 'siro/affiliate') {
                $item['signature'] = str_repeat('S', 64);
                $item['signature_url'] = 'https://cdn.example.com/artifacts/siro-affiliate-1.0.0.sig';
            }
        }
        unset($item);

        $validPath = $this->tempCatalog($payload);
        $valid = $this->runPhp([PHP_BINARY, $builder, '--in=' . $validPath, '--check']);
        self::assertSame(0, $valid['code'], $valid['stderr']);

        $feedOne = $this->tempFile();
        $feedTwo = $this->tempFile();
        self::assertSame(0, $this->runPhp([PHP_BINARY, $builder, '--in=' . $validPath, '--out=' . $feedOne])['code']);
        self::assertSame(0, $this->runPhp([PHP_BINARY, $builder, '--in=' . $validPath, '--out=' . $feedTwo])['code']);

        $bytesOne = (string) file_get_contents($feedOne);
        self::assertSame($bytesOne, (string) file_get_contents($feedTwo));

        $decoded = json_decode($bytesOne, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        foreach ($decoded['items'] as $publicItem) {
            self::assertArrayNotHasKey('artifact_url', $publicItem);
            self::assertArrayNotHasKey('sha256', $publicItem);
            self::assertArrayNotHasKey('signature', $publicItem);
            self::assertArrayNotHasKey('signature_url', $publicItem);
        }
    }

    public function testPublishProCommandPublishesVerifiedArtifactAndRefusesIncompleteInput(): void
    {
        $repoRoot = dirname($this->base());
        $publisher = $repoRoot . '/scripts/ecosystem-catalog/publish-pro.php';
        $builder = $repoRoot . '/scripts/ecosystem-catalog/build.php';

        $artifact = $this->tempFile();
        file_put_contents($artifact, 'artifact-bytes');
        $sha = hash('sha256', 'artifact-bytes');
        $signature = str_repeat('S', 64);
        $url = 'https://cdn.example.com/artifacts/siro-affiliate-1.0.0.siro.zip';

        $catalog = $this->baseCatalog();
        $path = $this->tempCatalog($catalog);

        $publish = $this->runPhp([
            PHP_BINARY,
            $publisher,
            '--catalog=' . $path,
            '--id=siro/affiliate',
            '--version=1.0.0',
            '--artifact=' . $artifact,
            '--artifact-url=' . $url,
            '--sha256=' . $sha,
            '--signature=' . $signature,
        ]);
        self::assertSame(0, $publish['code'], $publish['stderr']);

        $raw = file_get_contents($path);
        self::assertIsString($raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $item = $this->item($data['items'], 'siro/affiliate');
        self::assertNotNull($item);
        self::assertSame('published', $item['status']);
        self::assertSame($url, $item['artifact_url']);
        self::assertSame($sha, $item['sha256']);
        self::assertSame($signature, $item['signature']);

        self::assertSame(0, $this->runPhp([PHP_BINARY, $builder, '--in=' . $path, '--check'])['code']);

        $second = $this->tempCatalog($catalog);
        $before = (string) file_get_contents($second);
        $missing = $this->runPhp([
            PHP_BINARY,
            $publisher,
            '--catalog=' . $second,
            '--id=siro/affiliate',
            '--version=1.0.0',
            '--artifact=' . $artifact,
            '--artifact-url=' . $url,
            '--sha256=' . $sha,
        ]);
        self::assertNotSame(0, $missing['code']);
        self::assertSame($before, (string) file_get_contents($second));

        $third = $this->tempCatalog($catalog);
        $beforeMismatch = (string) file_get_contents($third);
        $mismatch = $this->runPhp([
            PHP_BINARY,
            $publisher,
            '--catalog=' . $third,
            '--id=siro/affiliate',
            '--version=1.0.0',
            '--artifact=' . $artifact,
            '--artifact-url=' . $url,
            '--sha256=' . str_repeat('b', 64),
            '--signature=' . $signature,
        ]);
        self::assertSame(4, $mismatch['code']);
        self::assertSame($beforeMismatch, (string) file_get_contents($third));
    }

    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseCatalog(): array
    {
        $raw = file_get_contents($this->base() . '/resources/ecosystem/catalog.json');
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function payload(array $items): array
    {
        return [
            'schema' => EcosystemCatalogService::SCHEMA,
            'generated_at' => '2026-11-24T00:00:00Z',
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tempCatalog(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'siro-catalog-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'siro-catalog-script-');
        self::assertIsString($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param list<string> $command
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPhp(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, dirname($this->base()));
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'code' => $code,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function item(array $items, string $id): ?array
    {
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    private function assertInvalidArgument(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $role): array
    {
        $email = 'catalog-' . $role . '-' . bin2hex(random_bytes(4)) . '@shop.test';
        $auth = new AuthService(new UserStoreAdapter(new UserRepository()));
        $auth->register(['name' => $role, 'email' => $email, 'password' => 'secret123', 'role' => $role]);
        $tokens = $auth->login($email, 'secret123');

        return ['Authorization' => 'Bearer ' . $tokens->access, 'X-Store-Id' => 'default', 'X-Channel-Id' => 'default', 'X-Locale' => 'en', 'X-Currency' => 'VND'];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array{status: int, json: array<string, mixed>}
     */
    private function call(string $method, string $path, array $body = [], array $headers = []): array
    {
        $query = [];
        $route = $path;
        if (str_contains($path, '?')) {
            [$route, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }

        $request = new Request($method, $route, $query, $headers, $body, '127.0.0.1');
        try {
            $response = $this->router->dispatch($request);
        } catch (ValidationException $e) {
            $response = $e->toResponse();
        }
        ob_start();
        $response->send();
        $output = (string) ob_get_clean();
        $decoded = json_decode($output, true);

        return ['status' => $response->statusCode(), 'json' => is_array($decoded) ? $decoded : []];
    }
}
