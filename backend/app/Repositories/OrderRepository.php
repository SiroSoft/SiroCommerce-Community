<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderTransitionRecord;
use App\Models\GuestOrderAccess;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\OrderTimelineEvent;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Ids;
use App\Support\CommerceScope;
use App\Support\PaginationBounds;
use App\Support\Arr;
use Siro\Core\Database;

final class OrderRepository
{
    use CreatesModels;

    /** @var array<string, string> Tokens issued during this process, never persisted. */
    private array $issuedGuestTokens = [];

    /** @return array<string, mixed>|null */
    public function findByIdempotency(string $key): ?array
    {
        $row = Order::query()->where('idempotency_key', $key)->first();
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        unset($out['guest_access_token']);
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $row = Order::find($id);
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        unset($out['guest_access_token']);
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function findInScope(string $id, CommerceScope $scope): ?array
    {
        $query = Order::query()->where('id', $id);
        $this->applyScope($query, $scope);
        $row = $query->first();
        if ($row === null) return null;
        $out = $row->toArray(); unset($out['guest_access_token']); return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createOrder(array $data): array
    {
        $token = isset($data['guest_access_token']) && is_string($data['guest_access_token']) ? $data['guest_access_token'] : null;
        unset($data['guest_access_token']);
        $order = self::createRow(Order::class, $data);
        if ($token !== null && $token !== '') {
            $orderId = is_string($order['id'] ?? null) ? $order['id'] : throw new \RuntimeException('Order ID missing after create');
            $this->issuedGuestTokens[$orderId] = $token;
            self::createRow(GuestOrderAccess::class, [
                'id' => Ids::new(),
                'order_id' => $orderId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
                'revoked_at' => null,
            ]);
            $order['guest_access_token'] = $token;
        }
        return $order;
    }

    public function issuedGuestToken(string $orderId): ?string { return $this->issuedGuestTokens[$orderId] ?? null; }

    public function guestTokenIsValid(string $orderId, string $token, ?CommerceScope $scope = null): bool
    {
        if ($token === '') return false;
        $access = GuestOrderAccess::query()->where('order_id', $orderId)->first();
        if ($access === null) return false;
        if ($scope !== null && $this->findInScope($orderId, $scope) === null) return false;
        $row = $access->toArray();
        $hash = is_string($row['token_hash'] ?? null) ? $row['token_hash'] : '';
        $expiresAt = is_string($row['expires_at'] ?? null) ? $row['expires_at'] : null;
        return $hash !== '' && hash_equals($hash, hash('sha256', $token))
            && ($row['revoked_at'] ?? null) === null
            && ($expiresAt === null || strtotime($expiresAt) > time());
    }

    public function revokeGuestToken(string $orderId): void
    {
        $access = GuestOrderAccess::query()->where('order_id', $orderId)->first();
        if ($access !== null) {
            $access->setAttribute('revoked_at', date('Y-m-d H:i:s'));
            $access->save();
        }
    }

    /** @return array{order_id: string, token: string, expires_at: string}|null */
    public function recoverGuestAccess(string $number, string $email, ?CommerceScope $scope = null): ?array
    {
        /** @var array{order_id: string, token: string, expires_at: string}|null $result */
        $result = Database::transaction(function () use ($number, $email, $scope): ?array {
            $query = Order::query()->where('number', strtoupper(trim($number)))->where('guest_email', strtolower(trim($email)))->whereNull('customer_id');
            if ($scope !== null) $this->applyScope($query, $scope);
            $order = $query->first();
            $orderId = Arr::str(Arr::row($order), 'id');
            if ($orderId === '') return null;
            $access = GuestOrderAccess::query()->where('order_id', $orderId)->lockForUpdate()->first();
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400);
            if ($access !== null) {
                GuestOrderAccess::query()->where('order_id', $orderId)->update(['token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'revoked_at' => null]);
            } else {
                self::createRow(GuestOrderAccess::class, ['id' => Ids::new(), 'order_id' => $orderId, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'revoked_at' => null]);
            }
            return ['order_id' => $orderId, 'token' => $token, 'expires_at' => date('c', time() + 86400)];
        });
        return $result;
    }

    /** @return array<string, mixed>|null */
    public function findTransitionByIdempotency(string $orderId, string $key): ?array
    {
        $row = OrderTransitionRecord::query()->where('order_id', $orderId)->where('idempotency_key', $key)->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @param array<string, mixed> $data */
    public function logTransitionRecord(array $data): void { self::createRow(OrderTransitionRecord::class, $data); }

    /** @param array<string, mixed> $data */
    public function addLine(array $data): void
    {
        self::createRow(OrderLine::class, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createPayment(array $data): array
    {
        return self::createRow(Payment::class, $data);
    }

    public function setPaymentState(string $id, string $state, ?string $providerRef = null): void
    {
        $row = Payment::find($id);
        if ($row !== null) {
            $row->setAttribute('state', $state);
            if ($providerRef !== null) $row->setAttribute('provider_ref', $providerRef);
            $row->save();
        }
    }

    public function markPaymentsCaptured(string $orderId): void
    {
        foreach (Payment::query()->where('order_id', $orderId)->get() as $payment) {
            $data = $payment->toArray();
            $state = is_string($data['state'] ?? null) ? $data['state'] : '';
            if (!in_array($state, ['pending', 'authorized'], true)) continue;
            $payment->setAttribute('state', 'captured');
            $payment->setAttribute('captured_amount_minor', is_numeric($data['amount_minor'] ?? null) ? (int) $data['amount_minor'] : 0);
            $payment->save();
        }
    }

    public function setState(string $id, string $state): void
    {
        $row = Order::find($id);
        if ($row !== null) {
            $row->setAttribute('state', $state);
            $row->save();
        }
    }

    public function logTransition(string $orderId, string $from, string $to, string $actor, string $reason = ''): void
    {
        self::createRow(OrderTransitionRecord::class, [
            'id' => bin2hex(random_bytes(13)),
            'order_id' => $orderId,
            'from_state' => $from,
            'to_state' => $to,
            'actor' => $actor,
            'reason' => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function transitions(string $orderId): array
    {
        $rows = OrderTransitionRecord::query()->where('order_id', $orderId)->get();
        return array_map(static function ($m): array {
            /** @var array<string, mixed> $out */
            $out = $m->toArray();
            return $out;
        }, $rows);
    }

    /** @param array<string, mixed> $data */
    public function addTimelineEvent(array $data): void { self::createRow(OrderTimelineEvent::class, $data); }

    /** @return array<int, array<string, mixed>> */
    public function timeline(string $orderId, ?CommerceScope $scope = null): array
    {
        $query = OrderTimelineEvent::query()->where('order_id', $orderId)->orderBy('created_at', 'asc')->orderBy('id', 'asc');
        if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId);
        return array_map(static fn ($row): array => $row->toArray(), $query->get());
    }

    /** @param array<string, string> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginateLatest(int $page, int $perPage, ?CommerceScope $scope = null, array $filters = []): array
    {
        $page = PaginationBounds::page($page);
        $perPage = PaginationBounds::perPage($perPage);
        $query = Order::query(); if ($scope !== null) $this->applyScope($query, $scope);
        $status = is_string($filters['status'] ?? null) ? trim($filters['status']) : '';
        $customerId = is_string($filters['customer_id'] ?? null) ? trim($filters['customer_id']) : '';
        $from = is_string($filters['from'] ?? null) ? trim($filters['from']) : '';
        $to = is_string($filters['to'] ?? null) ? trim($filters['to']) : '';
        $search = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';
        if ($status !== '') $query->where('state', $status);
        if ($customerId !== '') $query->where('customer_id', $customerId);
        if ($from !== '') $query->whereDate('created_at', '>=', $from);
        if ($to !== '') $query->whereDate('created_at', '<=', $to);
        if ($search !== '') $query->whereRaw('(number LIKE :order_search OR guest_email LIKE :order_search_email)', ['order_search' => '%' . $search . '%', 'order_search_email' => '%' . $search . '%']);
        $result = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage, $page);
        $data = [];
        foreach ($result['data'] as $m) {
            /** @var array<string, mixed> $row */
            $row = $m->toArray();
            unset($row['guest_access_token']);
            $identity = (string) ($row['customer_id'] ?? $row['guest_email'] ?? '');
            $row['customer_ref'] = $identity === '' ? null : substr(hash('sha256', $identity), 0, 12);
            unset($row['guest_email'], $row['customer_email'], $row['user_name']);
            $data[] = $row;
        }
        /** @var array<string, mixed> $meta */
        $meta = $result['meta'];
        return [
            'data' => $data,
            'meta' => [
                'page' => \App\Support\Arr::int($meta, 'page', $page),
                'per_page' => \App\Support\Arr::int($meta, 'per_page', $perPage),
                'total' => \App\Support\Arr::int($meta, 'total'),
                'last_page' => \App\Support\Arr::int($meta, 'last_page', 1),
            ],
        ];
    }

    /** @param list<string> $aliasIds
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function paginateForCustomer(string $customerId, int $page, int $perPage, ?CommerceScope $scope = null, array $aliasIds = []): array
    {
        $page = PaginationBounds::page($page);
        $perPage = PaginationBounds::perPage($perPage);
        $ids = array_values(array_unique(array_merge([$customerId], $aliasIds)));
        $query = Order::query()->whereIn('customer_id', $ids); if ($scope !== null) $this->applyScope($query, $scope);
        $result = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->paginate($perPage, $page);
        $data = [];
        foreach ($result['data'] as $model) {
            /** @var array<string, mixed> $row */
            $row = $model->toArray();
            unset($row['guest_access_token']);
            $data[] = $row;
        }
        /** @var array<string, mixed> $meta */
        $meta = $result['meta'];
        return ['data' => $data, 'meta' => [
            'page' => \App\Support\Arr::int($meta, 'page', $page),
            'per_page' => \App\Support\Arr::int($meta, 'per_page', $perPage),
            'total' => \App\Support\Arr::int($meta, 'total'),
            'last_page' => \App\Support\Arr::int($meta, 'last_page', 1),
        ]];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createRefund(array $data): array
    {
        $data['id'] = \App\Support\Arr::str($data, 'id', Ids::new());
        return self::createRow(Refund::class, $data);
    }

    /** @return array<string, mixed>|null */
    public function findRefundByIdempotency(string $key): ?array
    {
        $row = Refund::query()->where('idempotency_key', $key)->first();
        return $row === null ? null : $row->toArray();
    }

    public function refundedTotal(string $orderId): int
    {
        $total = 0;
        foreach (Refund::query()->where('order_id', $orderId)->get() as $row) {
            /** @var array<string, mixed> $refund */
            $refund = $row->toArray();
            $total += \App\Support\Arr::int($refund, 'amount_minor');
        }
        return $total;
    }

    /** @return array<int, array<string, mixed>> */
    public function payments(string $orderId): array
    {
        $rows = Payment::query()->where('order_id', $orderId)->get();
        return array_map(static function ($m): array {
            /** @var array<string, mixed> $out */
            $out = $m->toArray();
            return $out;
        }, $rows);
    }

    private function applyScope(mixed $query, CommerceScope $scope): void
    {
        $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId);
        foreach (['tenant_id' => $scope->tenantId, 'seller_id' => $scope->sellerId, 'site_id' => $scope->siteId] as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function lines(string $orderId): array
    {
        $rows = OrderLine::query()->where('order_id', $orderId)->get();
        return array_map(static function ($m): array {
            /** @var array<string, mixed> $out */
            $out = $m->toArray();
            return $out;
        }, $rows);
    }
}
