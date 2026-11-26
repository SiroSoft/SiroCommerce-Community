<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class WishlistResource extends Resource
{
    public function toArray(): array
    {
        return array_intersect_key($this->data, array_flip(['id', 'product_id', 'variant_id', 'created_at']));
    }
}
