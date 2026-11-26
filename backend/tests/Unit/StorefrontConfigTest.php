<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\StorefrontConfigController;
use App\Modules\StorefrontConfig\Application\StorefrontConfigService;
use App\Modules\StorefrontConfig\Contracts\StorefrontConfigStore;
use App\Modules\StorefrontConfig\Validation\StorefrontConfigValidator;
use App\Support\CommerceScope;
use PHPUnit\Framework\TestCase;
use Siro\Core\Cache;
use Siro\Core\Request;

final class StorefrontConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Cache::reset();
    }

    public function testPublicReadUsesEnvelopeHeadersAndOnlyPublishedData(): void
    {
        $config = ['scope' => [], 'theme' => ['key' => 'editorial'], 'vertical' => ['key' => 'general'], 'brand' => [], 'navigation' => [], 'homepage' => ['sections' => []], 'presentation' => [], 'policies' => [], 'version' => 1];
        $service = new StorefrontConfigService(new class($config) implements StorefrontConfigStore {
            public function __construct(private array $config) {}
            public function published(CommerceScope $scope): ?array { return ['config' => $this->config, 'version' => 7]; }
        });
        $response = (new StorefrontConfigController($service))->show(new Request('GET', '/api/storefront/config', [], [
            'X-Store-Id' => 'store-a', 'X-Channel-Id' => 'web', 'X-Locale' => 'en', 'X-Currency' => 'USD',
        ]));

        self::assertSame(200, $response->statusCode());
        self::assertTrue($response->payload()['success']);
        self::assertSame('store-a', $response->payload()['data']['scope']['store_id']);
        self::assertSame('public, max-age=60', $response->headers()['Cache-Control']);
        self::assertSame('store-a:web:en:USD', $response->headers()['X-Commerce-Scope']);
        self::assertSame('7', $response->headers()['X-Storefront-Config-Version']);
        self::assertStringStartsWith('"', (string) $response->headers()['ETag']);
    }

    public function testInvalidSectionsAreRejected(): void
    {
        $validator = new StorefrontConfigValidator();
        $config = ['scope' => [], 'theme' => [], 'vertical' => [], 'brand' => [], 'navigation' => [], 'homepage' => ['sections' => [['id' => 'x', 'type' => 'react_component', 'order' => 1]]], 'presentation' => [], 'policies' => [], 'version' => 1];
        self::assertNull($validator->publicConfig($config));
    }

    public function testMalformedStoredConfigFallsBackToSafeDefaults(): void
    {
        $service = new StorefrontConfigService(new class implements StorefrontConfigStore {
            public function published(CommerceScope $scope): ?array { return ['config' => ['draft_only' => true], 'version' => 99]; }
        });
        $config = $service->published(new CommerceScope('store-b', 'web', 'en', 'USD'));
        self::assertTrue($config->fallback);
        self::assertSame('editorial', $config->config['theme']['key']);
        self::assertSame('store-b', $config->config['scope']['store_id']);
    }

    public function testWriteValidationRejectsExecutableAndUnknownPresentationData(): void
    {
        $validator = new StorefrontConfigValidator();
        $config = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $config['homepage']['sections'] = [['id' => 'hero', 'type' => 'product_collection', 'enabled' => true, 'order' => 1, 'props' => ['source' => 'javascript:alert(1)']]];
        self::assertNull($validator->writeConfig($config));
    }

    public function testImportShapedPayloadSavesAsDraft(): void
    {
        $saved = null;
        $store = new class($saved) implements \App\Modules\StorefrontConfig\Contracts\AdminStorefrontConfigStore {
            /** @var array<string, mixed>|null */
            public ?array $saved = null;
            public function published(\App\Support\CommerceScope $scope): ?array { return null; }
            public function draft(\App\Support\CommerceScope $scope): ?array { return ['id' => 1, 'status' => 'draft', 'version' => 3, 'config' => []]; }
            public function saveDraft(\App\Support\CommerceScope $scope, array $config, int $expectedVersion, string $actor, string $key): array
            {
                $this->saved = $config;
                return ['version' => 4, 'config' => $config];
            }
            public function publish(\App\Support\CommerceScope $scope, int $expectedVersion, string $actor, string $key): array { return []; }
            public function restore(\App\Support\CommerceScope $scope, string $revision, int $expectedVersion, string $actor, string $key): array { return []; }
            public function revisions(\App\Support\CommerceScope $scope): array { return []; }
            public function diff(\App\Support\CommerceScope $scope, string $from, string $to): array { return []; }
            public function themes(): array { return []; }
            public function presets(): array { return []; }
        };
        $service = new StorefrontConfigService($store);
        $scope = new \App\Support\CommerceScope('store-a', 'web', 'en', 'USD');
        $exported = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $exported['theme']['key'] = 'tet-holiday';
        $result = $service->update($scope, ['expected_version' => 3, 'theme_key' => 'tet-holiday', 'theme_extends' => 'seasonal', 'tokens' => $exported['theme']['tokens'], 'header' => ['layout' => 'split', 'announcement' => true, 'sticky' => false]], ['id' => 1, 'email' => 'admin@shop.test'], 'import-key-1');
        self::assertSame(4, $result['version']);
        self::assertSame('tet-holiday', $result['config']['theme']['key']);
        self::assertSame('split', $result['config']['header']['layout']);
    }

    public function testCustomChildThemeWithHeaderFooterAndTypographyPasses(): void
    {
        $validator = new StorefrontConfigValidator();
        $config = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $config['theme']['key'] = 'tet-holiday';
        $config['theme']['extends'] = 'seasonal';
        $config['theme']['tokens']['typography'] = ['display' => 'festive', 'body' => 'system', 'density' => 'spacious', 'display_stack' => '"Be Vietnam Pro", Arial, sans-serif', 'body_stack' => '"Be Vietnam Pro", Arial, sans-serif', 'base_size' => 17];
        $config['header'] = ['announcement' => false, 'layout' => 'centered', 'sticky' => true];
        $config['footer'] = ['layout' => 'minimal', 'show_help' => true, 'show_policies' => false, 'columns' => [['title' => 'Tet', 'links' => [['label' => 'Sale', 'href' => '/sale']]]]];
        $valid = $validator->writeConfig($config);
        self::assertNotNull($valid);
        self::assertSame('tet-holiday', $valid['theme']['key']);
        self::assertSame('centered', $valid['header']['layout']);
        self::assertSame('minimal', $valid['footer']['layout']);
        self::assertSame(17, $valid['theme']['tokens']['typography']['base_size']);
    }

    public function testDangerousTypographyAndLayoutsAreRejected(): void
    {
        $validator = new StorefrontConfigValidator();
        $base = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $bad = $base;
        $bad['theme']['tokens']['typography']['display_stack'] = 'x; background: url(evil)';
        self::assertNull($validator->writeConfig($bad));
        $bad = $base;
        $bad['theme']['tokens']['typography']['base_size'] = 99;
        self::assertNull($validator->writeConfig($bad));
        $bad = $base;
        $bad['header'] = ['layout' => 'floating'];
        self::assertNull($validator->writeConfig($bad));
        $bad = $base;
        $bad['footer'] = ['columns' => [['title' => 'x', 'links' => [['label' => 'y', 'href' => 'javascript:alert(1)']]]]];
        self::assertNull($validator->writeConfig($bad));
        $bad = $base;
        $bad['presentation'] = ['product_card_variant' => 'hologram', 'product_detail_variant' => 'editorial', 'purchase_variant' => 'standard'];
        self::assertNull($validator->writeConfig($bad));
    }

    public function testLegacyConfigWithoutNewSectionTypesStillLoads(): void
    {
        $validator = new StorefrontConfigValidator();
        $config = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $config['homepage']['sections'] = [
            ['id' => 'campaign', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => []],
            ['id' => 'collections', 'type' => 'collection_directory', 'enabled' => true, 'order' => 20, 'props' => []],
            ['id' => 'story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 30, 'props' => []],
            ['id' => 'new-edit', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'tag:new']],
            ['id' => 'favorites', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured']],
            ['id' => 'proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 60, 'props' => []],
            ['id' => 'trust', 'type' => 'trust_strip', 'enabled' => true, 'order' => 65, 'props' => []],
            ['id' => 'categories', 'type' => 'category_links', 'enabled' => true, 'order' => 70, 'props' => []],
        ];
        self::assertNotNull($validator->publicConfig($config));
    }

    public function testMultiVerticalSectionTypesValidateTheirProps(): void
    {
        $validator = new StorefrontConfigValidator();
        $config = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $config['homepage']['sections'] = [
            ['id' => 'banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 10, 'props' => ['banners' => [
                ['title' => 'One', 'href' => '/one', 'image' => '/media/one.jpg', 'cta' => ['label' => 'Shop', 'href' => '/one']],
                ['title' => 'Two', 'subtitle' => 'Second banner', 'href' => '/two'],
            ]]],
            ['id' => 'usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 20, 'props' => ['items' => [
                ['icon' => 'truck', 'title' => 'Fast delivery', 'copy' => 'Tracked shipping.'],
                ['icon' => 'shield', 'title' => 'Secure', 'copy' => 'Safe checkout.'],
                ['icon' => 'refresh', 'title' => 'Returns', 'copy' => '30-day returns.'],
            ]]],
            ['id' => 'faq', 'type' => 'faq', 'enabled' => true, 'order' => 30, 'props' => ['items' => [
                ['question' => 'When does it ship?', 'answer' => 'Within one business day.'],
            ]]],
        ];
        $valid = $validator->writeConfig($config);
        self::assertNotNull($valid);
        self::assertSame('banner_grid', $valid['homepage']['sections'][0]['type']);

        $bad = $config;
        $bad['homepage']['sections'][0]['props']['banners'] = [['title' => 'Only one', 'href' => '/one']];
        self::assertNull($validator->writeConfig($bad));

        $bad = $config;
        $bad['homepage']['sections'][0]['props']['banners'][0]['href'] = 'javascript:alert(1)';
        self::assertNull($validator->writeConfig($bad));
    }

    public function testBatchOneLayoutsPassAndUnknownKeysFail(): void
    {
        $validator = new StorefrontConfigValidator();
        $config = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        $config['header'] = ['layout' => 'split', 'announcement' => true, 'sticky' => true];
        $config['footer'] = ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => []];
        $config['presentation'] = ['product_card_variant' => 'minimal', 'product_detail_variant' => 'page-gallery-left', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'compact'];
        $valid = $validator->writeConfig($config);
        self::assertNotNull($valid);
        self::assertSame('split', $valid['header']['layout']);
        self::assertSame('mega', $valid['footer']['layout']);
        self::assertSame('list-grid-wide', $valid['presentation']['product_list_variant']);
        $bad = $config;
        $bad['header'] = ['layout' => 'header-hologram'];
        self::assertNull($validator->writeConfig($bad));
        $bad = $config;
        $bad['footer'] = ['layout' => 'footer-maxi'];
        self::assertNull($validator->writeConfig($bad));
        $bad = $config;
        $bad['presentation']['product_list_variant'] = 'list-hologram';
        self::assertNull($validator->writeConfig($bad));
    }

    public function testQuoteModeDefaultsValidatesAndPersists(): void
    {
        $validator = new StorefrontConfigValidator();
        $config = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        self::assertSame('required', $config['purchase']['quote_mode']);
        self::assertSame('required', $validator->writeConfig($config)['purchase']['quote_mode']);

        foreach (['auto', 'off'] as $mode) {
            $config['purchase'] = ['quote_mode' => $mode];
            $valid = $validator->writeConfig($config);
            self::assertNotNull($valid);
            self::assertSame($mode, $valid['purchase']['quote_mode']);
        }

        $config['purchase'] = ['quote_mode' => 'sometimes'];
        self::assertNull($validator->writeConfig($config));

        $legacy = \App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults::config('store-a', 'web', 'en', 'USD');
        unset($legacy['purchase']);
        self::assertSame('required', $validator->publicConfig($legacy)['purchase']['quote_mode']);

        $saved = null;
        $store = new class($saved) implements \App\Modules\StorefrontConfig\Contracts\AdminStorefrontConfigStore {
            public ?array $saved = null;
            public function published(\App\Support\CommerceScope $scope): ?array { return null; }
            public function draft(\App\Support\CommerceScope $scope): ?array { return ['id' => 1, 'status' => 'draft', 'version' => 1, 'config' => []]; }
            public function saveDraft(\App\Support\CommerceScope $scope, array $config, int $expectedVersion, string $actor, string $key): array { $this->saved = $config; return ['version' => 2, 'config' => $config]; }
            public function publish(\App\Support\CommerceScope $scope, int $expectedVersion, string $actor, string $key): array { return []; }
            public function restore(\App\Support\CommerceScope $scope, string $revision, int $expectedVersion, string $actor, string $key): array { return []; }
            public function revisions(\App\Support\CommerceScope $scope): array { return []; }
            public function diff(\App\Support\CommerceScope $scope, string $from, string $to): array { return []; }
            public function themes(): array { return []; }
            public function presets(): array { return []; }
        };
        $service = new StorefrontConfigService($store);
        $scope = new \App\Support\CommerceScope('store-a', 'web', 'en', 'USD');
        $result = $service->update($scope, ['expected_version' => 1, 'theme_key' => 'editorial', 'vertical_key' => 'general', 'purchase' => ['quote_mode' => 'off']], ['id' => 1], 'quote-mode-1');
        self::assertSame('off', $result['config']['purchase']['quote_mode']);
    }
    public function testPublicThemePresetsExposeSafePresentationForDemos(): void
    {
        $controller = new StorefrontConfigController();
        $response = $controller->publicPresets(new Request('GET', '/api/storefront/theme-presets', [], [], []));
        self::assertSame(200, $response->statusCode());
        $data = $response->payload()['data'] ?? [];
        self::assertCount(18, $data);
        self::assertSame('beauty', $data[0]['key'] ?? null);
        self::assertArrayHasKey('tokens', $data[0]['theme'] ?? []);
        self::assertArrayHasKey('sections', $data[0]);
        self::assertSame('public, max-age=300', $response->headers()['Cache-Control'] ?? null);
    }
}