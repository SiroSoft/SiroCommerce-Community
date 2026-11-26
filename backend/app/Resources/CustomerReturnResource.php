<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

/** Customer-safe return evidence; internal provider diagnostics stay admin-only. */
final class CustomerReturnResource extends Resource
{
    public function toArray(): array
    {
        $result = array_intersect_key($this->data, array_flip(['id', 'order_id', 'fulfillment_id', 'state', 'reason', 'created_at', 'completed_at', 'label_state', 'label_tracking', 'label_url', 'refund_state', 'refund_amount_minor', 'refund_currency']));
        if (isset($this->data['lines']) && is_array($this->data['lines'])) $result['lines'] = array_map(static fn (mixed $line): mixed => is_array($line) ? array_intersect_key($line, array_flip(['id', 'return_id', 'order_line_id', 'qty', 'reason', 'disposition', 'condition'])) : $line, $this->data['lines']);
        return $result;
    }
}
