<?php
declare(strict_types=1);
namespace App\Modules\Checkout\Contracts;
interface OrderPort
{
    /** @return array<string, mixed>|null */ public function byIdempotency(string $key): ?array;
    /** @param array<string, mixed> $data */ public function create(array $data): void;
    /** @param array<string, mixed> $data */ public function addLine(array $data): void;
    /** @param array<string, mixed> $data */ public function payment(array $data): void;
    public function updatePayment(string $id, string $state, ?string $providerRef = null): void;
    /** @return array<string, mixed>|null */ public function find(string $id): ?array;
}
