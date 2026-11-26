<?php

declare(strict_types=1);

namespace App\Requests\Fulfillment;

use Siro\Core\FormRequest;

final class CreateReturnLabelRequest extends FormRequest
{
    public function rules(): array
    {
        return ['shipping' => 'required|array'];
    }
}
