<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

interface PaymentOperationStore
{
    /** @return array<string, mixed>|null */
    public function findByIdempotency(string $key): ?array;

    /** @param array<string, mixed> $operation */
    public function create(array $operation): void;

    /** @param array<string, mixed> $result */
    public function complete(string $id, array $result): void;

}
