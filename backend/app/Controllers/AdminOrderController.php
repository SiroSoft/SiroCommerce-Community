<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Order\Application\OrderAdminService;
use App\Support\Arr;
use App\Support\LocaleResolver;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Repositories\OrderRepository;
use App\Resources\OrderResource;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

/**
 * Merchant order admin (spec 04: ship common order ≤ 3 actions).
 * State changes go through OrderService named transitions only.
 */
final class AdminOrderController extends Controller
{
    public function __construct(
        private readonly OrderAdminService $orders,
        private readonly OrderRepository $orderRows,
    ) {
    }

    public function index(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $filters = [
            'q' => $request->queryString('q', ''),
            'status' => $request->queryString('status', ''),
            'customer_id' => $request->queryString('customer_id', ''),
            'from' => $request->queryString('from', ''),
            'to' => $request->queryString('to', ''),
        ];
        foreach (['from', 'to'] as $dateKey) {
            if ($filters[$dateKey] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateKey]) !== 1) return $this->error('Invalid ' . $dateKey . ' date', 422, [$dateKey => ['Use YYYY-MM-DD.']]);
        }
        /** @var array{data: array<int, mixed>, meta: array{page: int, per_page: int, total: int, last_page: int}} $result */
        $result = $this->orderRows->paginateLatest($page, $perPage, $scope, $filters);
        return $this->paginated($result['data'], $result['meta'], 'Orders list');
    }

    public function show(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $id = Arr::requiredParam($request, 'id'); if ($this->orderRows->findInScope($id, $scope) === null) return $this->error('Order not found', 404);
        $order = $this->orders->show($id, $scope);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }
        return $this->success(OrderResource::make($order), 'Order detail');
    }

    public function timeline(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = Arr::requiredParam($request, 'id');
            if ($this->orderRows->findInScope($id, $scope) === null) return $this->error('Order not found', 404);
            return $this->success($this->orderRows->timeline($id, $scope), 'Order timeline');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
    }

    public function transition(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'to' => 'required|string',
            'reason' => 'nullable|string',
        ]);
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        try {
            $scope = CommerceScope::fromRequest($request);
            $orderId = Arr::requiredParam($request, 'id');
            if ($this->orderRows->findInScope($orderId, $scope) === null) return $this->error('Order not found', 404);
            $order = $this->orders->transition(
                $orderId,
                Arr::requiredStr($data, 'to'),
                $actor,
                Arr::str($data, 'reason')
            );
            $this->orderRows->addTimelineEvent([
                'id' => \App\Support\Ids::new(), 'order_id' => $orderId, 'event_type' => 'order.transitioned',
                'state' => Arr::str($order, 'state'), 'actor' => $actor,
                'payload_json' => json_encode(['to' => Arr::requiredStr($data, 'to'), 'reason' => Arr::str($data, 'reason')], JSON_THROW_ON_ERROR),
                'store_id' => Arr::nullableStr($order, 'store_id'), 'channel_id' => Arr::nullableStr($order, 'channel_id'), 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['to' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
        return $this->success($order, 'Order updated');
    }

    public function cancel(Request $request): Response
    {
        $data = $request->validate(['reason' => 'nullable|string']);
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) return $this->error('A valid Idempotency-Key is required', 422);
        try {
            $scope = CommerceScope::fromRequest($request);
            $orderId = Arr::requiredParam($request, 'id');
            if ($this->orderRows->findInScope($orderId, $scope) === null) return $this->error('Order not found', 404);
            $user = $request->user();
            /** @var array<string, mixed>|null $user */
            $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
            return $this->success($this->orders->transition($orderId, 'cancelled', $actor, Arr::str($data, 'reason'), $key), 'Order cancelled');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 409); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 404); }
    }

    public function refund(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'amount_minor' => 'required|integer',
            'reason' => 'nullable|string',
        ]);
        $orderId = Arr::requiredParam($request, 'id');
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        if ($this->orderRows->findInScope($orderId, $scope) === null) return $this->error('Order not found', 404);
        $amount = Arr::requiredInt($data, 'amount_minor');
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || $key === '') $key = 'legacy-refund:' . $orderId . ':' . $amount . ':' . hash('sha256', Arr::str($data, 'reason'));
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        try {
            $updated = $this->orders->refund($orderId, $amount, $actor, Arr::str($data, 'reason'), $key);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), str_contains($e->getMessage(), 'Idempotency key conflicts') ? 409 : 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success($updated, Lang::get('commerce.order.refunded'));
    }

    public function refundRemaining(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['reason' => 'nullable|string']);
        $orderId = Arr::requiredParam($request, 'id');
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        if ($this->orderRows->findInScope($orderId, $scope) === null) return $this->error('Order not found', 404);
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || $key === '') $key = 'legacy-refund-remaining:' . $orderId . ':' . hash('sha256', Arr::str($data, 'reason'));
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        try {
            $updated = $this->orders->refundRemaining($orderId, $actor, Arr::str($data, 'reason'), $key);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), str_contains($e->getMessage(), 'Idempotency key conflicts') ? 409 : 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success($updated, Lang::get('commerce.order.refunded'));
    }

    public function refundLines(Request $request): Response
    {
        $data = $request->validate(['lines' => 'required|array', 'reason' => 'nullable|string']);
        $key = $request->header('idempotency-key', '');
        if (!is_string($key) || $key === '') return $this->error('A valid Idempotency-Key is required', 422);
        try {
            $scope = CommerceScope::fromRequest($request); $id = Arr::requiredParam($request, 'id');
            if ($this->orderRows->findInScope($id, $scope) === null) return $this->error('Order not found', 404);
            $actorData = $request->user(); $actor = is_array($actorData) ? Arr::str($actorData, 'email', 'admin') : 'admin';
            $lines = array_values(array_filter($data['lines'], 'is_array'));
            return $this->success($this->orders->refundLines($id, $lines, $actor, Arr::str($data, 'reason'), $key), 'Order lines refunded');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 409); }
    }
}
