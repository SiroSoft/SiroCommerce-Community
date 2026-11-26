<?php

declare(strict_types=1);

namespace App\Modules\Customer\Contracts;

interface CustomerStore extends CustomerReader
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createAddress(array $data): array;

    /** @return array<int, array<string, mixed>> */
    public function addresses(string $customerId): array;
}
