<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class InvoiceResource extends Resource
{
    public function toArray(): array
    {
        return array_intersect_key($this->data, array_flip([
            'id', 'number', 'order_id', 'payment_id', 'state', 'currency', 'minor_unit', 'subtotal_minor', 'discount_minor', 'shipping_minor', 'tax_minor', 'amount_minor', 'issued_at', 'created_at',
        ]));
    }
}
