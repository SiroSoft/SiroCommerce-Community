<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\StorefrontConfigController;
use App\Modules\StorefrontConfig\Application\StorefrontConfigService;
use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use App\Modules\StorefrontConfig\Domain\StorefrontVerticalPresetCatalog;
use App\Modules\StorefrontConfig\Infrastructure\StorefrontConfigSeeder;
use App\Modules\StorefrontConfig\Infrastructure\StorefrontConfigStoreAdapter;
use App\Support\CommerceScope;
use Siro\Core\Database;
use Siro\Core\Request;
use Tests\TestCase;

final class StorefrontVerticalPresetsTest extends TestCase
{
    /** @var list<string> */
    private const VERTICAL_PRESETS = ['beauty', 'pharmacy', 'fashion', 'fnb', 'electronics', 'b2b', 'grocery', 'cosmetics', 'footwear', 'jewelry', 'furniture', 'sports', 'pets', 'baby', 'books', 'flowers', 'supplements', 'auto'];

    /** @var list<string> */
    private const NEW_PRESETS = ['grocery', 'cosmetics', 'footwear', 'jewelry', 'furniture', 'sports', 'pets', 'baby', 'books', 'flowers', 'supplements', 'auto'];

    protected function setUp(): void
    {
        parent::setUp();
        StorefrontConfigSeeder::seed(Database::connection());
    }

    public function testPresetListExposesNewVerticalsWithMetadata(): void
    {
        self::assertSame(self::VERTICAL_PRESETS, array_keys(StorefrontVerticalPresetCatalog::all()));
        self::assertCount(18, StorefrontVerticalPresetCatalog::all());

        $presets = (new StorefrontConfigStoreAdapter())->presets();
        $byKey = [];
        foreach ($presets as $preset) $byKey[(string) $preset['key']] = $preset;

        self::assertArrayHasKey('general', $byKey);

        foreach (self::VERTICAL_PRESETS as $key) {
            self::assertArrayHasKey($key, $byKey);
            $preset = $byKey[$key];
            self::assertSame($key, $preset['theme_defaults']['theme']['key']);
            self::assertSame('general', $preset['theme_defaults']['theme']['extends']);
            self::assertNotSame('', $preset['description'] ?? '');
            self::assertNotSame('', $preset['preview'] ?? '');
            self::assertArrayHasKey('product_card_variant', $preset['presentation_defaults']);
            self::assertContains($preset['purchase_defaults']['quote_mode'] ?? null, ['required', 'auto', 'off']);
            self::assertNotEmpty($preset['homepage_defaults']['sections'] ?? []);
            foreach ($preset['homepage_defaults']['sections'] as $section) {
                self::assertContains($section['type'], StorefrontConfigDefaults::allowedSections());
            }
            self::assertArrayHasKey('header', $preset['theme_defaults']);
            self::assertArrayHasKey('footer', $preset['theme_defaults']);
            self::assertArrayHasKey('policies', $preset['theme_defaults']);
            self::assertArrayHasKey('brand', $preset['theme_defaults']);
            self::assertNotSame('SiroCommerce', $preset['theme_defaults']['brand']['name']);
            self::assertStringStartsWith('/brand/', $preset['theme_defaults']['brand']['logo_url']);
        }
    }

    public function testPresetsUseDistinctLayoutArchetypesAndSectionRecipes(): void
    {
        $presets = StorefrontVerticalPresetCatalog::all();
        $archetypes = [];
        $signatures = [];

        foreach ($presets as $key => $preset) {
            $archetype = StorefrontVerticalPresetCatalog::archetype($key);
            self::assertIsString($archetype, $key . ' layout archetype');
            $archetypes[$archetype] = true;

            $signature = implode('>', array_map(static fn (array $section): string => (string) $section['type'], $preset['sections']));
            $signatures[$signature] = ($signatures[$signature] ?? 0) + 1;
        }

        self::assertCount(5, $archetypes);
        self::assertGreaterThanOrEqual(10, count($signatures));
        self::assertLessThanOrEqual(3, max($signatures));
        self::assertSame('campaign_hero', $presets['beauty']['sections'][0]['type']);
    }

    public function testApplyingEachPresetCreatesAValidPublishedShape(): void
    {
        self::assertCount(12, self::NEW_PRESETS);
        self::assertSame([], array_values(array_diff(self::NEW_PRESETS, self::VERTICAL_PRESETS)));

        $scope = new CommerceScope('default', 'default', 'en', 'USD');
        $service = new StorefrontConfigService(new StorefrontConfigStoreAdapter());
        $version = 1;
        $seenNewTypes = [];

        foreach (self::VERTICAL_PRESETS as $key) {
            $result = $service->applyPreset($scope, ['expected_version' => $version, 'preset_key' => $key], ['id' => 1], 'apply-' . $key . '-1');
            self::assertSame($version + 1, $result['version']);
            self::assertGreaterThan($version, $result['version']);

            $config = $result['config'];
            self::assertSame($key, $config['vertical']['key']);
            self::assertSame($key, $config['theme']['key']);
            self::assertContains($config['purchase']['quote_mode'] ?? null, ['required', 'auto', 'off']);
            self::assertSame($key === 'b2b' ? 'required' : 'auto', $config['purchase']['quote_mode']);
            self::assertSame('general', $config['theme']['extends'] ?? null);
            self::assertNotEmpty($config['homepage']['sections']);
            self::assertNotEmpty($config['theme']['tokens']['colors']);
            self::assertArrayHasKey('header', $config);
            self::assertArrayHasKey('footer', $config);
            self::assertNotSame('SiroCommerce', $config['brand']['name']);
            self::assertStringStartsWith('/brand/', $config['brand']['logo_url']);

            foreach ($config['homepage']['sections'] as $section) {
                self::assertContains($section['type'], StorefrontConfigDefaults::allowedSections());
                if (in_array($section['type'], ['banner_grid', 'usp_strip', 'faq'], true)) $seenNewTypes[$section['type']] = true;
            }

            $version = $result['version'];
        }

        self::assertArrayHasKey('banner_grid', $seenNewTypes);
        self::assertArrayHasKey('usp_strip', $seenNewTypes);
        self::assertArrayHasKey('faq', $seenNewTypes);
    }

    public function testUnknownPresetKeyIsRejected(): void
    {
        $service = new StorefrontConfigService(new StorefrontConfigStoreAdapter());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown vertical preset');
        $service->applyPreset(new CommerceScope('default', 'default', 'en', 'USD'), ['expected_version' => 1, 'preset_key' => 'not-a-vertical'], ['id' => 1], 'unknown-preset-1');
    }

    public function testUnknownSectionTypeIsRejectedWith422(): void
    {
        $controller = new StorefrontConfigController(new StorefrontConfigService(new StorefrontConfigStoreAdapter()));
        $request = new Request('PUT', '/api/admin/storefront/config', [], [
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'USD',
            'Idempotency-Key' => 'bad-section-type-1',
        ], [
            'expected_version' => 1,
            'theme_key' => 'editorial',
            'vertical_key' => 'general',
            'homepage' => ['sections' => [
                ['id' => 'bad', 'type' => 'react_component', 'enabled' => true, 'order' => 1, 'props' => []],
            ]],
        ]);

        $response = $controller->update($request);
        self::assertSame(422, $response->statusCode());
        self::assertSame('VALIDATION_ERROR', $response->payload()['meta']['error_code'] ?? '');
    }
    public function testNativeThemePackageImportsAsDraft(): void
    {
        $controller = new StorefrontConfigController(new StorefrontConfigService(new StorefrontConfigStoreAdapter()));
        $request = new Request('POST', '/api/admin/storefront/config/import-theme', [], [
            'X-Store-Id' => 'default',
            'X-Channel-Id' => 'default',
            'X-Locale' => 'en',
            'X-Currency' => 'USD',
            'Idempotency-Key' => 'theme-package-1',
        ], [
            'expected_version' => 1,
            'theme' => [
                'schema' => 'siro.theme/1',
                'id' => 'acme/editorial-pharmacy',
                'name' => 'Editorial Pharmacy',
                'version' => '1.0.0',
                'extends' => 'general',
                'vertical' => 'pharmacy',
                'brand' => ['name' => 'Northfield Pharmacy'],
                'homepage' => ['sections' => [
                    ['id' => 'campaign', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => []],
                ]],
            ],
        ]);
        $response = $controller->importTheme($request);
        self::assertSame(200, $response->statusCode());
        $data = $response->payload()['data'] ?? [];
        self::assertSame('editorial-pharmacy', $data['config']['theme']['key'] ?? null);
        self::assertSame('Northfield Pharmacy', $data['config']['brand']['name'] ?? null);
        self::assertSame('default', $data['config']['scope']['store_id'] ?? null);
    }
}