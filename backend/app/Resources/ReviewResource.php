<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class ReviewResource extends Resource
{
    public function toArray(): array
    {
        $fields = ['id', 'product_id', 'variant_id', 'rating', 'title', 'body', 'status', 'verified_purchase', 'created_at', 'updated_at'];
        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $this->data)) $result[$field] = $this->data[$field];
        }
        return $result;
    }
}
