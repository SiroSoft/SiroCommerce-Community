<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application;

use App\Modules\Customer\Contracts\CustomerReader;
use App\Modules\Fulfillment\Contracts\ReturnStore;
use App\Modules\Order\Application\OrderQueryService;
use App\Models\OrderNotificationDelivery;
use App\Models\FulfillmentOrder;
use App\Models\ReturnRequest;
use App\Support\Arr;
use App\Support\CommerceScope;
use Siro\Core\Database;

final class CustomerCommerceService
{
    public function __construct(private readonly CustomerReader $customers, private readonly OrderQueryService $orders, private readonly ReturnStore $returns) {}

    /** @return array<string, mixed>|null */
    public function customerForEmail(string $email): ?array { return $this->customers->findByEmail($email); }

    /**
     * Every order id the account may own. Member-placed orders are stamped
     * with the auth user id (PlaceOrderService member attribution — the same
     * identity loyalty snapshots and gift ownership key on), while older rows
     * carry the customer-profile id. Both resolve to this account via email.
     * @return list<string>
     */
    public static function orderIdentities(array $customer, mixed $user): array
    {
        $ids = [];
        $profile = Arr::str($customer, 'id');
        if ($profile !== '') $ids[] = $profile;
        $raw = is_array($user) ? ($user['id'] ?? '') : '';
        $userId = is_scalar($raw) ? (string) $raw : '';
        if ($userId !== '' && !in_array($userId, $ids, true)) $ids[] = $userId;
        return $ids;
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function orders(string $customerId, int $page, int $perPage, ?CommerceScope $scope = null, array $aliasIds = []): array { return $this->orders->paginateForCustomer($customerId, $page, $perPage, $scope, $aliasIds); }

    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function returns(string $customerId, int $page, int $perPage, array $aliasIds = []): array { return $this->returns->paginateForCustomer($customerId, $page, $perPage, $aliasIds); }

    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function orderReturns(string $orderId, int $page, int $perPage): array { return $this->returns->paginateForOrder($orderId, $page, $perPage); }

    public function ownsOrder(string $customerId, string $orderId, array $aliasIds = []): bool
    {
        $order = $this->orders->show($orderId);
        return $order !== null && in_array(Arr::str($order, 'customer_id'), array_merge([$customerId], $aliasIds), true);
    }

    /** @return array<string, mixed>|null */
    public function return(string $orderId, string $id): ?array { return $this->returns->findForOrder($orderId, $id); }

    /** @return array<string, mixed>|null */
    public function returnForCustomer(string $customerId, string $id, array $aliasIds = []): ?array { return $this->returns->findForCustomer($customerId, $id, $aliasIds); }

    /** @return array<int, array<string, mixed>> */
    public function notificationsForOrder(string $customerId, string $orderId, array $aliasIds = []): array
    {
        $order = $this->orders->show($orderId);
        if ($order === null || !in_array(Arr::str($order, 'customer_id'), array_merge([$customerId], $aliasIds), true)) return [];
        $aggregateIds = [$orderId];
        foreach (FulfillmentOrder::query()->where('order_id', $orderId)->get() as $fulfillment) $aggregateIds[] = Arr::str($this->row($fulfillment), 'id');
        foreach (ReturnRequest::query()->where('order_id', $orderId)->get() as $return) $aggregateIds[] = Arr::str($this->row($return), 'id');
        $rows = [];
        foreach (OrderNotificationDelivery::query()->whereIn('aggregate_id', array_values(array_filter($aggregateIds)))->orderBy('created_at', 'desc')->get() as $delivery) {
            $row = $this->row($delivery);
            $state = Arr::str($row, 'state', 'unknown');
            $rows[] = ['event' => $this->notificationEvent(Arr::str($row, 'event_name')), 'state' => $state, 'attempts' => Arr::int($row, 'attempts'), 'issue' => in_array($state, ['failed', 'sending'], true) ? 'delivery_attention_required' : null, 'sent_at' => Arr::nullableStr($row, 'sent_at'), 'created_at' => Arr::nullableStr($row, 'created_at')];
        }
        return $rows;
    }

    /** Customer-safe history: no payloads, recipient data, provider errors, or attempt counts. */
    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function notificationHistory(string $customerId, CommerceScope $scope, int $page, int $perPage, array $aliasIds = []): array
    {
        $page = max(1, $page); $perPage = min(50, max(1, $perPage));
        $ids = array_values(array_unique(array_merge([$customerId], $aliasIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $where = "o.customer_id IN ({$placeholders}) AND o.store_id = ? AND o.channel_id = ?";
        $db = Database::connection();
        $count = $db->prepare('SELECT COUNT(*) FROM order_notification_deliveries d INNER JOIN orders o ON (o.id = d.aggregate_id OR EXISTS (SELECT 1 FROM fulfillment_orders f WHERE f.id = d.aggregate_id AND f.order_id = o.id) OR EXISTS (SELECT 1 FROM return_requests r WHERE r.id = d.aggregate_id AND r.order_id = o.id)) WHERE ' . $where);
        $count->execute(array_merge($ids, [$scope->storeId, $scope->channelId]));
        $total = (int) $count->fetchColumn();
        $statement = $db->prepare('SELECT d.event_name, d.state, d.sent_at, d.created_at FROM order_notification_deliveries d INNER JOIN orders o ON (o.id = d.aggregate_id OR EXISTS (SELECT 1 FROM fulfillment_orders f WHERE f.id = d.aggregate_id AND f.order_id = o.id) OR EXISTS (SELECT 1 FROM return_requests r WHERE r.id = d.aggregate_id AND r.order_id = o.id)) WHERE ' . $where . ' ORDER BY d.created_at DESC, d.id DESC LIMIT ? OFFSET ?');
        $statement->execute(array_merge($ids, [$scope->storeId, $scope->channelId, $perPage, ($page - 1) * $perPage]));
        $data = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $raw) {
            if (!is_array($raw)) continue;
            $state = is_string($raw['state'] ?? null) ? $raw['state'] : 'unknown';
            $event = is_string($raw['event_name'] ?? null) ? $raw['event_name'] : '';
            $data[] = ['event' => $this->notificationEvent($event), 'status' => in_array($state, ['sent', 'delivered'], true) ? 'sent' : (in_array($state, ['queued', 'sending'], true) ? 'processing' : 'not_delivered'), 'sent_at' => $raw['sent_at'] ?? null, 'created_at' => $raw['created_at'] ?? null];
        }
        return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }

    private function notificationEvent(string $event): string
    {
        return match ($event) {
            'order.placed' => 'order_placed',
            'payment.captured' => 'payment_captured',
            'payment.failed' => 'payment_failed',
            'fulfillment.shipped' => 'fulfillment_shipped',
            'fulfillment.delivered' => 'fulfillment_delivered',
            'fulfillment.ready_for_pickup' => 'pickup_ready',
            'fulfillment.picked_up' => 'pickup_collected',
            'refund.completed', 'order.refunded' => 'refund_completed',
            default => 'unknown',
        };
    }

    /** @return array<string, mixed> */
    private function row(mixed $value): array
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
