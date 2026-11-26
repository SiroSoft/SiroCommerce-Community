<?php

declare(strict_types=1);

namespace App\Requests\Finance;

use Siro\Core\FormRequest;

final class CreateCreditMemoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount_minor' => 'required|integer',
            'reason' => 'nullable|string',
        ];
    }
}
