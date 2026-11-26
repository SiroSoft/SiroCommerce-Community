<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Order\Contracts\OrderReader;
use App\Repositories\OrderRepository;

final class LegacyOrderReader implements OrderReader
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function find(string $id): ?array { return $this->orders->find($id); }
    public function findInScope(string $id, \App\Support\CommerceScope $scope): ?array { return $this->orders->findInScope($id, $scope); }
    public function paginateForCustomer(string $customerId, int $page, int $perPage, ?\App\Support\CommerceScope $scope = null, array $aliasIds = []): array { return $this->orders->paginateForCustomer($customerId, $page, $perPage, $scope, $aliasIds); }
    public function lines(string $id): array { return $this->orders->lines($id); }
    public function transitions(string $id): array { return $this->orders->transitions($id); }
    public function guestTokenIsValid(string $id, string $token): bool { return $this->orders->guestTokenIsValid($id, $token); }
    public function revokeGuestToken(string $id): void { $this->orders->revokeGuestToken($id); }
}
