<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\ProductVariant;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use Tests\TestCase;

final class SearchRelevanceTest extends TestCase
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

    /** @param array<string, mixed> $filters
     *  @return list<string> */
    private function ids(ProductRepository $repository, array $filters, ?CommerceScope $scope = null): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            $repository->paginateActive(1, 20, $scope, $filters)['data']
        ));
    }

    public function testFullQueryNameMatchOutranksDescriptionOnlyMatch(): void
    {
        $this->product('relevance-name', 'Linen shirt');
        $this->product('relevance-description', 'Cotton tee', ['description' => 'Lightweight linen shirt for summer']);

        $ids = $this->ids(new ProductRepository(), ['search' => 'linen shirt']);

        self::assertSame('relevance-name', $ids[0] ?? null);
        self::assertContains('relevance-description', $ids);
    }

    public function testEveryTokenMustMatchAtLeastOneField(): void
    {
        $this->product('and-both', 'Linen shirt');
        $this->product('and-linen-only', 'Linen pants');
        $this->product('and-shirt-only', 'Cotton shirt');

        $ids = $this->ids(new ProductRepository(), ['search' => 'linen shirt']);

        self::assertSame(['and-both'], $ids);
    }

    public function testTypoFallbackFindsTransposedAndExtraLetterTokens(): void
    {
        $this->product('typo-linen', 'Linen shirt');

        $repository = new ProductRepository();
        self::assertSame(['typo-linen'], $this->ids($repository, ['search' => 'linne shirt']));
        self::assertSame(['typo-linen'], $this->ids($repository, ['search' => 'linnen shirt']));
    }

    public function testUnaccentedVietnameseQueryMatchesAccentedName(): void
    {
        $this->product('vi-product', 'Vải đẹp');

        self::assertSame(['vi-product'], $this->ids(new ProductRepository(), ['search' => 'vai dep']));
    }

    public function testExplicitPriceSortOverridesRelevance(): void
    {
        $this->product('sort-relevant', 'Linen shirt');
        $this->variant('sort-relevant-v', 'sort-relevant', 5000);
        $this->product('sort-cheap', 'Cheap linen shirt');
        $this->variant('sort-cheap-v', 'sort-cheap', 1000);

        $ids = $this->ids(new ProductRepository(), ['search' => 'linen shirt', 'sort' => 'price-asc']);

        self::assertSame('sort-cheap', $ids[0] ?? null);
    }

    public function testScopeAndTierGatingStillApplyToSearch(): void
    {
        $scope = new CommerceScope('store-a', 'web', 'en', 'USD');
        $this->product('scope-public', 'Linen shirt', ['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD']);
        $this->product('scope-gold', 'Linen gold shirt', ['store_id' => 'store-a', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD', 'min_tier' => 'gold']);
        $this->product('scope-other', 'Linen other store', ['store_id' => 'store-b', 'channel_id' => 'web', 'locale' => 'en', 'currency' => 'USD']);

        $repository = new ProductRepository();

        self::assertSame(['scope-public'], $this->ids($repository, ['search' => 'linen'], $scope));
        self::assertSame(['scope-gold', 'scope-public'], $this->ids($repository, ['search' => 'linen', 'viewer_tier' => 'gold'], $scope));
        self::assertSame(['scope-public'], $this->ids($repository, ['search' => 'linen', 'viewer_tier' => 'silver'], $scope));
    }

    public function testNoMatchesReturnsEmptyResult(): void
    {
        $this->product('empty-catalog', 'Linen shirt');

        $result = (new ProductRepository())->paginateActive(1, 20, null, ['search' => 'zzzznotfound']);

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['meta']['total']);
    }
}
