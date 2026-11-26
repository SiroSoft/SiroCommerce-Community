<?php

declare(strict_types=1);

namespace App\Modules\Order\Application;

use App\Modules\Order\Contracts\OrderReader;
use App\Models\Payment;
use App\Models\FulfillmentOrder;
use App\Models\Package;
use App\Models\OrderNotificationDelivery;
use App\Models\ReturnRequest;
use App\Support\Arr;
use App\Support\CommerceScope;

final class OrderQueryService
{
    public function __construct(private readonly OrderReader $orders)
    {
    }

    /** @return array<string, mixed>|null */
    public function show(string $id): ?array
    {
        $order = $this->orders->find($id);
        if ($order === null) return null;
        $order['lines'] = $this->orders->lines($id);
        $order['transitions'] = $this->orders->transitions($id);
        return $this->lifecycle($order);
    }

    /** @return array<string, mixed>|null */
    public function showInScope(string $id, CommerceScope $scope): ?array
    {
        $order = $this->orders->findInScope($id, $scope);
        if ($order === null) return null;
        $order['lines'] = $this->orders->lines($id);
        $order['transitions'] = $this->orders->transitions($id);
        return $this->lifecycle($order);
    }

    /** @param list<string> $aliasIds
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function paginateForCustomer(string $customerId, int $page, int $perPage, ?CommerceScope $scope = null, array $aliasIds = []): array
    {
        $result = $this->orders->paginateForCustomer($customerId, $page, $perPage, $scope, $aliasIds);
        /** @var array<int, array<string, mixed>> $data */
        $data = [];
        foreach ($result['data'] as $order) $data[] = self::summary($this->lifecycle($order));
        return ['data' => $data, 'meta' => $result['meta']];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private static function summary(array $order): array
    {
        unset($order['guest_access_token'], $order['guest_email']);
        return $order;
    }

    public function guestTokenIsValid(string $id, string $token): bool { return $this->orders->guestTokenIsValid($id, $token); }

    public function revokeGuestToken(string $id): void { $this->orders->revokeGuestToken($id); }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function lifecycle(array $order): array
    {
        $id = Arr::str($order, 'id');
        if ($id === '') return $order;
        $payment = Payment::query()->where('order_id', $id)->orderBy('id')->first();
        if ($payment !== null) {
            $row = self::row($payment);
            $order['payment'] = ['id' => Arr::str($row, 'id'), 'status' => Arr::str($row, 'state', 'unknown'), 'method' => Arr::str($row, 'provider', 'card'), 'failure_reason' => Arr::nullableStr($row, 'last_error')];
        }
        $fulfillment = FulfillmentOrder::query()->where('order_id', $id)->orderBy('created_at', 'desc')->first();
        if ($fulfillment !== null) {
            $f = $fulfillment->toArray();
            $package = Package::query()->where('fulfillment_id', Arr::str($f, 'id'))->orderBy('id', 'desc')->first();
            $p = $package?->toArray() ?? [];
            $order['delivery'] = ['mode' => 'physical', 'state' => Arr::str($f, 'state', 'unknown'), 'carrier' => Arr::nullableStr($p, 'carrier'), 'tracking' => Arr::nullableStr($p, 'tracking')];
        }
        $aggregateIds = [$id];
        foreach (FulfillmentOrder::query()->where('order_id', $id)->get() as $row) $aggregateIds[] = Arr::str(self::row($row), 'id');
        foreach (ReturnRequest::query()->where('order_id', $id)->get() as $row) $aggregateIds[] = Arr::str(self::row($row), 'id');
        $notifications = [];
        foreach (OrderNotificationDelivery::query()->whereIn('aggregate_id', array_values(array_filter($aggregateIds)))->orderBy('created_at', 'desc')->get() as $notification) {
            $row = self::row($notification);
            $state = Arr::str($row, 'state', 'unknown');
            $notifications[] = ['event' => Arr::str($row, 'event_name'), 'state' => $state, 'attempts' => Arr::int($row, 'attempts'), 'issue' => in_array($state, ['failed', 'sending'], true) ? 'delivery_attention_required' : null];
        }
        if ($notifications !== []) $order['notifications'] = $notifications;
        $returns = [];
        foreach (ReturnRequest::query()->where('order_id', $id)->orderBy('created_at', 'desc')->get() as $return) { $row = self::row($return); $returns[] = ['id' => Arr::str($row, 'id'), 'state' => Arr::str($row, 'state'), 'refund_state' => Arr::nullableStr($row, 'refund_state')]; }
        if ($returns !== []) $order['returns'] = $returns;
        return $order;
    }

    /** @return array<string, mixed> */
    private static function row(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $row = $value->toArray();
            if (!is_array($row)) return [];
            $result = [];
            foreach ($row as $key => $item) if (is_string($key)) $result[$key] = $item;
            return $result;
        }
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item;
        return $result;
    }
}
