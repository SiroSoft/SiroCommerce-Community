<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class CustomerNotificationPreferenceResource extends Resource
{
    public function toArray(): array
    {
        return array_intersect_key($this->data, array_flip(['transactional_email', 'marketing_email', 'updated_at']));
    }
}
