<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

final class OrderResource extends Resource
{
    public function toArray(): array
    {
        $result = $this->pick($this->data, [
            'id', 'number', 'currency', 'subtotal_minor', 'customer_ref',
            'discount_minor', 'shipping_minor', 'tax_minor', 'grand_total_minor', 'state', 'created_at', 'payment', 'delivery', 'notifications', 'returns',
            'pickup_point_code',
        ]);
        if (!isset($result['customer_ref'])) {
            $identity = (string) ($this->data['customer_id'] ?? $this->data['guest_email'] ?? '');
            if ($identity !== '') $result['customer_ref'] = substr(hash('sha256', $identity), 0, 12);
        }
        if (isset($this->data['lines']) && is_array($this->data['lines'])) {
            $result['lines'] = array_map(fn (mixed $line): mixed => $this->line($line, ['id', 'order_id', 'variant_id', 'sku', 'name', 'qty', 'unit_price_minor', 'vendor_id', 'vendor_commission_minor']), $this->data['lines']);
        }
        if (isset($this->data['transitions']) && is_array($this->data['transitions'])) {
            $result['transitions'] = array_map(fn (mixed $transition): mixed => $this->line($transition, ['id', 'order_id', 'from_state', 'to_state', 'reason', 'created_at']), $this->data['transitions']);
        }
        if (isset($this->data['payments']) && is_array($this->data['payments'])) {
            $result['payments'] = array_map(fn (mixed $payment): mixed => $this->line($payment, ['id', 'order_id', 'provider', 'amount_minor', 'captured_amount_minor', 'currency', 'state', 'created_at']), $this->data['payments']);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function pick(array $data, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) $result[$field] = $data[$field];
        }
        return $result;
    }

    /** @param array<int, string> $fields */
    private function line(mixed $value, array $fields): mixed
    {
        if (!is_array($value)) return $value;
        $data = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) $data[$key] = $item;
        }
        return $this->pick($data, $fields);
    }
}
