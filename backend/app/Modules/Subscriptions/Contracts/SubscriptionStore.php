<?php
declare(strict_types=1);
namespace App\Modules\Subscriptions\Contracts;
interface SubscriptionStore
{
    public function transaction(callable $callback): mixed;
    /** @return array<string, mixed>|null */ public function plan(string $id): ?array;
    /** @param array<string, mixed> $data */ public function createPlan(array $data): void;
    /** @return array<string, mixed>|null */ public function subscription(string $id): ?array;
    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */ public function subscriptionsForCustomer(string $customerId, int $page, int $perPage): array;
    /** @param array<string, mixed> $data */ public function createSubscription(array $data): void;
    /** @param array<string, mixed> $changes */ public function updateSubscription(string $id, array $changes): void;
    /** @return array<string, mixed>|null */ public function attemptByKey(string $key): ?array;
    /** @param array<string, mixed> $data */ public function createAttempt(array $data): void;
    /** @param array<string, mixed> $changes */ public function updateAttempt(string $id, array $changes): void;
    /** @return array<int, array<string, mixed>> */ public function due(string $now, int $limit): array;
}
