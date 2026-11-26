<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Customer;
use App\Modules\Headless\Contracts\OrderOwnerReader;

final class CustomerOrderOwnerReaderAdapter implements OrderOwnerReader
{
    /** @param array<string, mixed> $user */
    public function owns(string $customerId, array $user): bool
    {
        $email = $user['email'] ?? null;
        if (!is_string($email) || $email === '') return false;
        return Customer::query()->where('id', $customerId)->where('email', $email)->first() !== null;
    }
}
