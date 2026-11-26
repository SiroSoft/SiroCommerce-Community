<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class AddressResource extends Resource
{
    public function toArray(): array
    {
        return array_intersect_key($this->data, array_flip(['id', 'label', 'line1', 'city', 'phone', 'country', 'region', 'postal_code']));
    }
}
