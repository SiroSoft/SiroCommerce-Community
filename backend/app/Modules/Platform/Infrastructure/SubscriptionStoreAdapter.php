<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalAttempt;
use App\Modules\Subscriptions\Contracts\SubscriptionStore;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Model;

final class SubscriptionStoreAdapter implements SubscriptionStore
{
    use CreatesModels;
    public function transaction(callable $callback): mixed { return Database::transaction($callback); }
    public function plan(string $id): ?array { $row = SubscriptionPlan::query()->where('id', $id)->first(); return $row === null ? null : Arr::row($row); }
    public function createPlan(array $data): void { self::createRow(SubscriptionPlan::class, $data + ['id' => Ids::new(), 'created_at' => date('Y-m-d H:i:s')]); }
    public function subscription(string $id): ?array { $row = Subscription::query()->where('id', $id)->first(); return $row === null ? null : Arr::row($row); }
    public function subscriptionsForCustomer(string $customerId, int $page, int $perPage): array
    {
        $page = max(1, $page); $perPage = min(max(1, $perPage), 100);
        $query = Subscription::query()->where('customer_id', $customerId);
        $total = (int) $query->count();
        $rows = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit($perPage)->offset(($page - 1) * $perPage)->get();
        return ['data' => array_map(static fn (Model $row): array => Arr::row($row), $rows), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }
    public function createSubscription(array $data): void { Database::transaction(fn (): array => self::createRow(Subscription::class, $data)); }
    public function updateSubscription(string $id, array $changes): void { $row = Subscription::find($id); if ($row === null) throw new \RuntimeException('Subscription not found'); foreach ($changes as $key => $value) $row->setAttribute($key, $value); $row->save(); }
    public function attemptByKey(string $key): ?array { $row = SubscriptionRenewalAttempt::query()->where('idempotency_key', $key)->first(); return $row === null ? null : Arr::row($row); }
    public function createAttempt(array $data): void { Database::transaction(fn (): array => self::createRow(SubscriptionRenewalAttempt::class, $data)); }
    public function updateAttempt(string $id, array $changes): void { $row = SubscriptionRenewalAttempt::find($id); if ($row === null) throw new \RuntimeException('Renewal attempt not found'); foreach ($changes as $key => $value) $row->setAttribute($key, $value); $row->save(); }
    public function due(string $now, int $limit): array
    {
        $rows = Subscription::query()->whereIn('state', ['active', 'past_due', 'dunning', 'cancel_pending'])->where('next_billing_at', '<=', $now)->orderBy('next_billing_at', 'asc')->orderBy('id', 'asc')->limit($limit)->get();
        return array_map(static fn ($row): array => Arr::row($row), $rows);
    }
}
