<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ProductRepository;
use Siro\Core\Cache;
use Tests\TestCase;

final class CatalogTagFacetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function product(string $id, string $tagsJson): void
    {
        (new ProductRepository())->store([
            'id' => $id,
            'name' => $id,
            'slug' => $id,
            'status' => 'active',
            'store_id' => 'default',
            'channel_id' => 'default',
            'locale' => 'en',
            'currency' => 'VND',
            'tags_json' => $tagsJson,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function tagCount(array $result, string $tag): ?int
    {
        foreach ((array) ($result['meta']['facets']['tag'] ?? []) as $facet) {
            if (is_array($facet) && ($facet['value'] ?? null) === $tag) return (int) ($facet['count'] ?? 0);
        }
        return null;
    }

    public function testTagFacetCountsTheWholeFilteredSetAcrossPages(): void
    {
        // Featured sort is created_at desc, id desc: p3 (page 1), p2 (page 2), p1.
        $this->product('tag-p1', '["beta"]');
        $this->product('tag-p2', '["beta"]');
        $this->product('tag-p3', '["alpha"]');

        $products = new ProductRepository();
        $pageOne = $products->paginateActive(1, 1);
        // Force a fresh facet computation for page two instead of reusing page one's cache.
        Cache::flush('catalog:active:tag_facet:');
        $pageTwo = $products->paginateActive(2, 1);

        self::assertSame('tag-p3', $pageOne['data'][0]['id']);
        self::assertSame('tag-p2', $pageTwo['data'][0]['id']);
        self::assertSame(['alpha'], $pageOne['data'][0]['tags']);
        self::assertSame(2, $this->tagCount($pageOne, 'beta'));
        self::assertSame(2, $this->tagCount($pageTwo, 'beta'));
        self::assertSame(1, $this->tagCount($pageTwo, 'alpha'));
    }
}
