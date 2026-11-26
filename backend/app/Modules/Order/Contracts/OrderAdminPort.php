<?php
declare(strict_types=1);
namespace App\Modules\Order\Contracts;

interface OrderAdminPort
{
    /** @param array<string, string> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function latest(int $page, int $perPage, ?\App\Support\CommerceScope $scope = null, array $filters = []): array;
    /** @return array<string, mixed>|null */ public function find(string $id): ?array;
    /** @return array<string, mixed>|null */ public function findInScope(string $id, \App\Support\CommerceScope $scope): ?array;
    /** @return array<int, array<string, mixed>> */ public function lines(string $id): array;
    /** @return array<int, array<string, mixed>> */ public function payments(string $id): array;
    public function markPaymentsCaptured(string $id): void;
    /** @return array<int, array<string, mixed>> */ public function transitions(string $id): array;
    public function refundedTotal(string $id): int;
    /** @param array<string, mixed> $data */ public function createRefund(array $data): void;
    /** @return array<string, mixed>|null */ public function findRefundByIdempotency(string $key): ?array;
    /** @return array<string, mixed>|null */ public function findTransitionByIdempotency(string $orderId, string $key): ?array;
    /** @param array<string, mixed> $data */ public function logTransition(array $data): void;
}
