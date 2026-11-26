<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

/** Meilisearch index contract shared by the indexer command and search provider. */
final class CatalogIndexSchema
{
    public const INDEX = 'siro_products';

    /** @return array<string, array<int, string>> */
    public static function settings(): array
    {
        return [
            'searchableAttributes' => ['name', 'description', 'tags'],
            'filterableAttributes' => [
                'status',
                'archived',
                'category',
                'brand',
                'min_tier',
                'store_id',
                'channel_id',
                'locale',
                'currency',
                'price_minor',
                'in_stock',
            ],
            'sortableAttributes' => ['price_minor', 'created_at'],
        ];
    }
}
