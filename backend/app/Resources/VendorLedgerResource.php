<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class VendorLedgerResource extends Resource
{
    public function toArray(): array
    {
        $fields = ['id', 'vendor_id', 'order_id', 'order_line_id', 'type', 'gross_minor', 'commission_minor', 'net_minor', 'currency', 'state', 'payout_id', 'created_at'];
        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $this->data)) $result[$field] = $this->data[$field];
        }
        return $result;
    }
}
