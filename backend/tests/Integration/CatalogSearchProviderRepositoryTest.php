<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ProductVariant;
use App\Modules\Search\Contracts\CatalogSearchProvider;
use App\Repositories\ProductRepository;
use Tests\TestCase;

final class CatalogSearchProviderRepositoryTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function product(string $id, string $name, array $extra = []): void
    {
        (new ProductRepository())->store(array_merge([
            'id' => $id,
            'name' => $name,
            'slug' => $id,
            'status' => 'active',
            'store_id' => 'default',
            'channel_id' => 'default',
            'locale' => 'en',
            'currency' => 'VND',
        ], $extra));
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

    /** @return list<string> */
    private function ids(array $result): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            $result['data']
        ));
    }

    public function testProviderIdsKeepOrderAndRemainingFiltersStillApply(): void
    {
        $this->product('provider-public', 'Linen shirt');
        $this->variant('provider-public-v', 'provider-public', 2500);
        $this->product('provider-gold', 'Linen gold shirt', ['min_tier' => 'gold']);

        $provider = new FakeCatalogSearchProvider([
            'ids' => ['provider-gold', 'provider-public'],
            'total' => 2,
        ]);
        $result = (new ProductRepository($provider))->paginateActive(1, 20, null, ['search' => 'linen', 'viewer_tier' => 'silver']);

        self::assertSame(['provider-public'], $this->ids($result));
        self::assertSame(2, $result['meta']['total']);
        self::assertTrue($provider->called);
    }

    public function testProviderOrderIsPreservedEvenWhenPriceSortIsRequested(): void
    {
        $this->product('order-expensive', 'Linen expensive');
        $this->variant('order-expensive-v', 'order-expensive', 9000);
        $this->product('order-cheap', 'Linen cheap');
        $this->variant('order-cheap-v', 'order-cheap', 1000);

        $provider = new FakeCatalogSearchProvider([
            'ids' => ['order-expensive', 'order-cheap'],
            'total' => 2,
        ]);
        $result = (new ProductRepository($provider))->paginateActive(1, 20, null, ['search' => 'linen', 'sort' => 'price-asc']);

        self::assertSame(['order-expensive', 'order-cheap'], $this->ids($result));
    }

    public function testNullProviderResultFallsBackToNativeTypoSearch(): void
    {
        $this->product('fallback-linen', 'Linen shirt');
        $provider = new FakeCatalogSearchProvider(null);
        $result = (new ProductRepository($provider))->paginateActive(1, 20, null, ['search' => 'linne shirt']);

        self::assertSame(['fallback-linen'], $this->ids($result));
        self::assertTrue($provider->called);
    }

    public function testEmptyProviderResultDoesNotRunTypoFallback(): void
    {
        $this->product('empty-linen', 'Linen shirt');
        $provider = new FakeCatalogSearchProvider(['ids' => [], 'total' => 0]);
        $result = (new ProductRepository($provider))->paginateActive(1, 20, null, ['search' => 'linne shirt']);

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['meta']['total']);
        self::assertTrue($provider->called);
    }

    public function testNativePathStaysUnchangedWithoutProvider(): void
    {
        $this->product('native-linen', 'Linen shirt');

        $result = (new ProductRepository())->paginateActive(1, 20, null, ['search' => 'linne shirt']);

        self::assertSame(['native-linen'], $this->ids($result));
    }
}

final class FakeCatalogSearchProvider implements CatalogSearchProvider
{
    public bool $called = false;

    /** @param array{ids: list<string>, total: int}|null $result */
    public function __construct(public ?array $result = null) {}

    /**
     * @param array<string, mixed> $filters
     * @return array{ids: list<string>, total: int}|null
     */
    public function search(string $q, array $filters, int $limit, int $offset): ?array
    {
        $this->called = true;
        return $this->result;
    }
}
