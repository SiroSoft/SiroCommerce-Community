<?php

declare(strict_types=1);

namespace App\Modules\Headless\Infrastructure;

final class HeadlessSchema
{
    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            'query' => ['product', 'products', 'page', 'menu', 'order'],
            'product' => ['id', 'slug', 'name', 'status', 'type', 'options', 'variants', 'components', 'digitalAssets'],
            'products' => ['nodes', 'page', 'perPage', 'total', 'lastPage'],
            'page' => ['id', 'slug', 'locale', 'title', 'body', 'blocks', 'version', 'canonical', 'seo', 'media'],
            'order' => ['id', 'number', 'state', 'currency', 'totals', 'lines', 'createdAt'],
            'menu' => ['key', 'locale', 'items'],
        ];
    }
}
