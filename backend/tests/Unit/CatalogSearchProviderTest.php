<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Search\Application\CatalogIndexDocumentBuilder;
use App\Modules\Search\Application\CatalogIndexSchema;
use App\Modules\Search\Infrastructure\CatalogSearchRequestBuilder;
use App\Modules\Search\Infrastructure\MeiliCatalogSearchProvider;
use PHPUnit\Framework\TestCase;
use Siro\Core\HttpResponse;

final class CatalogSearchProviderTest extends TestCase
{
    public function testProviderMapsHitsAndEstimatedTotal(): void
    {
        $captured = [];
        $provider = new MeiliCatalogSearchProvider(
            function (string $url, string $body, array $headers) use (&$captured): HttpResponse {
                $captured = [$url, json_decode($body, true), $headers];
                return new HttpResponse(200, (string) json_encode([
                    'hits' => [['id' => 'p2'], ['id' => 'p1']],
                    'estimatedTotalHits' => 7,
                ]), '');
            },
            'http://meilisearch:7700',
            'master-key'
        );

        $result = $provider->search('linen', ['store_id' => 'store-a', 'viewer_tier' => 'silver', 'sort' => 'price-asc', 'in_stock' => true], 20, 40);

        self::assertSame(['ids' => ['p2', 'p1'], 'total' => 7], $result);
        self::assertSame('/indexes/siro_products/search', parse_url((string) $captured[0], PHP_URL_PATH));
        self::assertSame('linen', $captured[1]['q']);
        self::assertSame(20, $captured[1]['limit']);
        self::assertSame(40, $captured[1]['offset']);
        self::assertSame(['price_minor:asc'], $captured[1]['sort']);
        self::assertContains('status = "active"', $captured[1]['filter']);
        self::assertContains('archived = false', $captured[1]['filter']);
        self::assertContains('store_id = "store-a"', $captured[1]['filter']);
        self::assertContains('in_stock = true', $captured[1]['filter']);
        self::assertContains('(min_tier IS NULL OR min_tier IN ["bronze", "silver"])', $captured[1]['filter']);
        self::assertSame('Bearer master-key', $captured[2]['Authorization']);
    }

    public function testProviderTimeoutReturnsNull(): void
    {
        $provider = new MeiliCatalogSearchProvider(
            static fn (): HttpResponse => throw new \RuntimeException('timeout'),
            'http://meilisearch:7700',
            'master-key'
        );

        self::assertNull($provider->search('linen', [], 20, 0));
    }

    public function testProviderErrorStatusReturnsNull(): void
    {
        $provider = new MeiliCatalogSearchProvider(
            static fn (): HttpResponse => new HttpResponse(500, '{"message":"boom"}', ''),
            'http://meilisearch:7700',
            'master-key'
        );

        self::assertNull($provider->search('linen', [], 20, 0));
    }

    public function testProviderWithoutConfigurationReturnsNull(): void
    {
        self::assertNull((new MeiliCatalogSearchProvider(null, '', ''))->search('linen', [], 20, 0));
    }

    public function testRequestBuilderAddsScopePriceAndTierGuards(): void
    {
        $body = (new CatalogSearchRequestBuilder())->build('linen', [
            'store_id' => 's1',
            'channel_id' => 'web',
            'locale' => 'vi',
            'currency' => 'VND',
            'categories' => ['Shirts', 'Shoes'],
            'brands' => ['BRAND-A'],
            'min_price' => 1000,
            'max_price' => 5000,
            'in_stock' => true,
        ], 10, 0);

        self::assertSame(['id'], $body['attributesToRetrieve']);
        self::assertContains('channel_id = "web"', $body['filter']);
        self::assertContains('category IN ["shirts", "shoes"]', $body['filter']);
        self::assertContains('brand IN ["brand-a"]', $body['filter']);
        self::assertContains('price_minor >= 1000', $body['filter']);
        self::assertContains('price_minor <= 5000', $body['filter']);
        self::assertContains('min_tier IS NULL', $body['filter']);
    }

    public function testIndexSettingsAndDocumentShape(): void
    {
        self::assertSame(['name', 'description', 'tags'], CatalogIndexSchema::settings()['searchableAttributes']);
        self::assertContains('in_stock', CatalogIndexSchema::settings()['filterableAttributes']);
        self::assertSame(['price_minor', 'created_at'], CatalogIndexSchema::settings()['sortableAttributes']);

        $document = (new CatalogIndexDocumentBuilder())->build([
            'id' => 'p1',
            'name' => 'Linen Shirt',
            'description' => 'Summer shirt',
            'tags_json' => '["Linen","Summer","linen"]',
            'brand_id' => 'ACME',
            'status' => 'active',
            'min_tier' => 'GOLD',
            'store_id' => 's1',
            'channel_id' => 'web',
            'locale' => 'vi',
            'currency' => 'VND',
            'created_at' => '2026-01-01 00:00:00',
            'slug' => 'linen-shirt',
        ], [
            ['id' => 'v1', 'price_minor' => 2000],
            ['id' => 'v2', 'price_minor' => 1500],
        ], 'Shirts', true);

        self::assertSame('p1', $document['id']);
        self::assertSame(['linen', 'summer'], $document['tags']);
        self::assertSame('acme', $document['brand']);
        self::assertSame('shirts', $document['category']);
        self::assertSame('gold', $document['min_tier']);
        self::assertSame(1500, $document['price_minor']);
        self::assertTrue($document['in_stock']);
        self::assertFalse($document['archived']);
    }
}
