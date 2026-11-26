<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Analytics\Application\AnalyticsService;
use App\Modules\Analytics\Contracts\AnalyticsStore;
use App\Modules\Analytics\Domain\AnalyticsEventSchema;
use Tests\TestCase;

final class AnalyticsIngestionTest extends TestCase
{
    public function testStorefrontPayloadIsAllowlistedAndPrivacySafe(): void
    {
        self::assertSame(['product_id' => 'p-1', 'category' => 'apparel'], AnalyticsEventSchema::validateStorefrontPayload('product_view', ['product_id' => 'p-1', 'category' => 'apparel']));
        $this->expectException(\InvalidArgumentException::class);
        AnalyticsEventSchema::validateStorefrontPayload('product_view', ['product_id' => 'p-1', 'email' => 'buyer@example.test']);
    }

    public function testMalformedAndOutOfBoundsPropertiesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AnalyticsEventSchema::validateStorefrontPayload('search_submit', ['query_length' => 201]);
    }

    public function testRecommendationImpressionsAndClicksUseBoundedContext(): void
    {
        self::assertSame([
            'recommendation_id' => 'product-detail-p-1',
            'product_id' => 'p-2',
            'position' => 1,
            'source' => 'catalog-fallback',
        ], AnalyticsEventSchema::validateStorefrontPayload('recommendation_click', [
            'recommendation_id' => 'product-detail-p-1', 'product_id' => 'p-2', 'position' => 1, 'source' => 'catalog-fallback',
        ]));
        $this->expectException(\InvalidArgumentException::class);
        AnalyticsEventSchema::validateStorefrontPayload('recommendation_impression', ['product_id' => 'p-2']);
    }

    public function testWebVitalsPayloadIsBounded(): void
    {
        self::assertSame(
            ['metric' => 'lcp', 'value_ms' => 1800, 'rating' => 'good', 'page' => '/product/serum'],
            AnalyticsEventSchema::validateStorefrontPayload('web_vitals', ['metric' => 'lcp', 'value_ms' => 1800, 'rating' => 'good', 'page' => '/product/serum'])
        );
        $this->expectException(\InvalidArgumentException::class);
        AnalyticsEventSchema::validateStorefrontPayload('web_vitals', ['metric' => 'fcp', 'value_ms' => 1800, 'rating' => 'good', 'page' => '/']);
    }

    public function testStorefrontEventIdIsRetrySafe(): void
    {
        $store = new class implements AnalyticsStore {
            /** @var array<string, array<string, mixed>> */
            public array $rows = [];
            public function record(array $data): array { $this->rows[(string) $data['event_id']] ??= $data; return $this->rows[(string) $data['event_id']]; }
            public function count(string $event): int { return count($this->rows); }
        };
        $service = new AnalyticsService($store);
        $first = $service->trackStorefront('checkout_started', [], 'evt-1', 'session-123456789012', 'store-1', 'channel-1', 'en', 'USD');
        $second = $service->trackStorefront('checkout_started', [], 'evt-1', 'session-123456789012', 'store-1', 'channel-1', 'en', 'USD');
        self::assertSame($first['event_id'], $second['event_id']);
        self::assertCount(1, $store->rows);
        self::assertSame('en', $first['locale']);
        self::assertSame('USD', $first['currency']);
    }
}
