<?php

declare(strict_types=1);

namespace App\Modules\Customer\Contracts;

interface CustomerReader
{
    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array;

    /** @return array<string, mixed>|null */
    public function address(string $customerId, string $addressId): ?array;
}
