<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class VendorPayoutResource extends Resource
{
    public function toArray(): array
    {
        $fields = ['id', 'vendor_id', 'amount_minor', 'currency', 'state', 'period_start', 'period_end', 'entries_count', 'created_at', 'paid_at', 'failed_at', 'returned_at', 'cancelled_at', 'provider_reference', 'failure_reason'];
        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $this->data)) $result[$field] = $this->data[$field];
        }
        return $result;
    }
}
