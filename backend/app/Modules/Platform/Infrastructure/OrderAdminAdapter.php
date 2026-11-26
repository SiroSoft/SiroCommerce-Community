<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;

use App\Modules\Order\Contracts\OrderAdminPort;
use App\Repositories\OrderRepository;

final class OrderAdminAdapter implements OrderAdminPort
{
    public function __construct(private readonly OrderRepository $orders) {}
    /** @param array<string, string> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function latest(int $page, int $perPage, ?\App\Support\CommerceScope $scope = null, array $filters = []): array { return $this->orders->paginateLatest($page, $perPage, $scope, $filters); }
    public function find(string $id): ?array { return $this->orders->find($id); }
    public function findInScope(string $id, \App\Support\CommerceScope $scope): ?array { return $this->orders->findInScope($id, $scope); }
    public function lines(string $id): array { return $this->orders->lines($id); }
    public function payments(string $id): array { return $this->orders->payments($id); }
    public function markPaymentsCaptured(string $id): void { $this->orders->markPaymentsCaptured($id); }
    public function transitions(string $id): array { return $this->orders->transitions($id); }
    public function refundedTotal(string $id): int { return $this->orders->refundedTotal($id); }
    public function createRefund(array $data): void { $this->orders->createRefund($data); }
    public function findRefundByIdempotency(string $key): ?array { return $this->orders->findRefundByIdempotency($key); }
    public function findTransitionByIdempotency(string $orderId, string $key): ?array { return $this->orders->findTransitionByIdempotency($orderId, $key); }
    public function logTransition(array $data): void { $this->orders->logTransitionRecord($data); }
}
