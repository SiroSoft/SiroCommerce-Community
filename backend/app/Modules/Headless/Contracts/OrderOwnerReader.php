<?php

declare(strict_types=1);

namespace App\Modules\Headless\Contracts;

interface OrderOwnerReader
{
    /** @param array<string, mixed> $user */
    public function owns(string $customerId, array $user): bool;
}
