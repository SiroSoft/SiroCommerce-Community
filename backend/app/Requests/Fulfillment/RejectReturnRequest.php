<?php

declare(strict_types=1);

namespace App\Requests\Fulfillment;

use Siro\Core\FormRequest;

final class RejectReturnRequest extends FormRequest
{
    public function rules(): array
    {
        return ['note' => 'nullable|string'];
    }
}
