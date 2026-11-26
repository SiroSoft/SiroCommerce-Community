<?php
declare(strict_types=1);
namespace App\Modules\Finance\Contracts;
interface CreditMemoStore {
    /** @return array<string,mixed>|null */ public function byIdempotency(string $key): ?array;
    /** @return array<string,mixed>|null */ public function find(string $id): ?array;
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */ public function create(array $data): array;
    /** @param array<string,mixed> $data */ public function createRefund(array $data): void;
    public function setRefundState(string $id, string $state): void;
}
