<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class LoyaltyResource extends Resource
{
    public function toArray(): array
    {
        return array_intersect_key($this->data, array_flip(['id', 'balance_points', 'state', 'created_at', 'updated_at']));
    }
}
