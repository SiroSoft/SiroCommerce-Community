<?php
declare(strict_types=1);
namespace App\Resources;
use Siro\Core\Resource;
final class B2BOrderResource extends Resource {
    public function toArray(): array
    {
        $safe = [];
        foreach (['id', 'number', 'company_id', 'channel_id', 'currency', 'grand_total_minor', 'state'] as $field) {
            if (array_key_exists($field, $this->data)) $safe[$field] = $this->data[$field];
        }
        return $safe;
    }
}
