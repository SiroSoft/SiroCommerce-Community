<?php

declare(strict_types=1);

namespace App\Modules\Order\Contracts;

interface OrderReader
{
    /** @param list<string> $aliasIds extra order-owner ids (member user id alongside the profile id)
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function paginateForCustomer(string $customerId, int $page, int $perPage, ?\App\Support\CommerceScope $scope = null, array $aliasIds = []): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findInScope(string $id, \App\Support\CommerceScope $scope): ?array;

    /** @return array<int, array<string, mixed>> */
    public function lines(string $id): array;

    /** @return array<int, array<string, mixed>> */
    public function transitions(string $id): array;

    public function guestTokenIsValid(string $id, string $token): bool;

    public function revokeGuestToken(string $id): void;
}
