<?php

declare(strict_types=1);

namespace App\Requests\Fulfillment;

use Siro\Core\FormRequest;

final class AllocateFulfillmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|string',
            'lines' => 'required|array',
            'lines.*.variant_id' => 'required|string',
            'lines.*.qty' => 'required|integer',
        ];
    }
}
