<?php

declare(strict_types=1);

namespace App\Requests\Fulfillment;

use Siro\Core\FormRequest;

final class InspectReturnRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'disposition' => 'required|string',
            'note' => 'nullable|string',
        ];
    }
}
