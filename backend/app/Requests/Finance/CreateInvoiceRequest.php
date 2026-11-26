<?php

declare(strict_types=1);

namespace App\Requests\Finance;

use Siro\Core\FormRequest;

final class CreateInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|string',
            'payment_id' => 'required|string',
        ];
    }
}
