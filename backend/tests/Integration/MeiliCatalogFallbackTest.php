<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ProductVariant;
use App\Modules\Search\Infrastructure\MeiliCatalogSearchProvider;
use App\Repositories\ProductRepository;
use Siro\Core\HttpResponse;
use Tests\TestCase;

/**
 * Meilisearch is an optional accelerator: a timeout/transport error must fall
 * back to native SQL, while an authoritative empty provider result must stay
 * empty (no typo fallback that would resurrect unrelated rows).
 */
final class MeiliCatalogFallbackTest extends TestCase
{
    private function product(string $id, string $name): void
    {
        (new ProductRepository())->store([
            'id' => $id,
            'name' => $name,
            'slug' => $id,
            'status' => 'active',
            'store_id' => 'default',
            'channel_id' => 'default',
            'locale' => 'en',
            'currency' => 'VND',
        ]);
    }

    private function variant(string $id, string $productId, int $price): void
    {
        ProductVariant::create([
            'id' => $id,
            'product_id' => $productId,
            'sku' => strtoupper($id),
            'price_minor' => $price,
            'currency' => 'VND',
        ]);
    }

    /** @param array<string, mixed> $result @return list<string> */
    private function ids(array $result): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            $result['data']
        ));
    }

    public function testProviderTimeoutFallsBackToNativeSearch(): void
    {
        $this->product('meili-fallback-linen', 'Linen shirt');
        $this->variant('meili-fallback-linen-v', 'meili-fallback-linen', 5000);

        $provider = new MeiliCatalogSearchProvider(
            static function (): HttpResponse {
                throw new \RuntimeException('cURL error 28: Operation timed out');
            },
            'http://127.0.0.1:7700',
            'test-master-key'
        );
        self::assertNull($provider->search('linne shirt', [], 20, 0));

        $result = (new ProductRepository($provider))->paginateActive(1, 20, null, ['search' => 'linne shirt']);
        self::assertSame(['meili-fallback-linen'], $this->ids($result));
    }

    public function testAuthoritativeEmptyProviderResultDoesNotRunTypoFallback(): void
    {
        $this->product('meili-empty-linen', 'Linen shirt');
        $calls = 0;
        $provider = new MeiliCatalogSearchProvider(
            static function () use (&$calls): HttpResponse {
                $calls++;
                return new HttpResponse(200, json_encode(['hits' => [], 'estimatedTotalHits' => 0], JSON_THROW_ON_ERROR), '');
            },
            'http://127.0.0.1:7700',
            'test-master-key'
        );

        $result = (new ProductRepository($provider))->paginateActive(1, 20, null, ['search' => 'linne shirt']);
        self::assertSame([], $this->ids($result));
        self::assertSame(0, (int) $result['meta']['total']);
        self::assertSame(1, $calls);
    }
}
