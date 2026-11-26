<?php
declare(strict_types=1);
namespace App\Modules\Finance\Contracts;
interface InvoiceStore {
    public function transaction(callable $callback): mixed;
    /** @return array<string,mixed>|null */ public function byIdempotency(string $key): ?array;
    /** @return array<string,mixed>|null */ public function find(string $id): ?array;
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */ public function create(array $data): array;
    public function setState(string $id, string $state, ?string $issuedAt = null): void;
    public function creditedAmount(string $id): int;
    /** @return array<string,mixed> */ public function evidence(string $orderId, string $paymentId): array;
    /** @param array<string, mixed> $evidence */
    public function reconcilePayment(string $paymentId, string $paymentState, ?string $providerRef, array $evidence = []): void;
}
