<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Order\Application\OrderQueryService;
use App\Modules\Customer\Application\CustomerCommerceService;
use App\Resources\ReturnResource;
use App\Resources\OrderResource;
use App\Support\Arr;
use App\Support\Skeleton;
use App\Support\LocaleResolver;
use App\Repositories\OrderRepository;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

final class OrderController extends Controller
{
    public function __construct(private readonly OrderQueryService $orders, private readonly CustomerCommerceService $commerce, private readonly OrderRepository $orderRows, private readonly SkeletonOrderController $console)
    {
    }

    public function recover(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['order_number' => 'required|string', 'email' => 'required|string']);
        $number = trim(Arr::requiredStr($data, 'order_number'));
        $email = strtolower(trim(Arr::requiredStr($data, 'email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($number) > 32) return $this->error('Recovery details are invalid', 422);
        try { $scope = $this->optionalScope($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        $access = $this->orderRows->recoverGuestAccess($number, $email, $scope);
        if ($access === null) return $this->success(['status' => 'recovery_requested'], 'If the order matches, access has been refreshed');
        return $this->success(['status' => 'recovered', 'order_id' => $access['order_id'], 'order_access_token' => $access['token'], 'expires_at' => $access['expires_at']], 'Order access refreshed');
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        $consoleUser = is_array($user) ? $user : Skeleton::optionalUser($request);
        if (Skeleton::isAdminConsole($consoleUser)) return $this->console->show($request);
        Lang::setLocale(LocaleResolver::resolve($request));
        try { $scope = $this->optionalScope($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        if (!is_array($user)) {
            $token = (string) $request->header('x-order-token', '');
            if (!$this->orders->guestTokenIsValid(Arr::requiredParam($request, 'id'), $token, $scope)) {
                return $this->error('Order access token is required', 403);
            }
        }
        $order = $scope === null ? $this->orders->show(Arr::requiredParam($request, 'id')) : $this->orders->showInScope(Arr::requiredParam($request, 'id'), $scope);
        if ($order === null) {
            return $this->error('Order not found', 404);
        }
        if (is_array($user)) {
            $customer = $this->commerce->customerForEmail(is_string($user['email'] ?? null) ? $user['email'] : '');
            if ($customer === null || !in_array($order['customer_id'] ?? null, CustomerCommerceService::orderIdentities($customer, $user), true)) return $this->error('Order not found', 404);
        }
        return $this->success(OrderResource::make($order), Lang::get('commerce.checkout.placed'));
    }

    public function returns(Request $request): Response
    {
        $orderId = Arr::requiredParam($request, 'id');
        $user = $request->user();
        if (is_array($user)) {
            $customer = $this->commerce->customerForEmail(is_string($user['email'] ?? null) ? $user['email'] : '');
            try { $scope = $this->optionalScope($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
            $order = $scope === null ? $this->orders->show($orderId) : $this->orders->showInScope($orderId, $scope);
            if ($order === null || $customer === null || !in_array($order['customer_id'] ?? null, CustomerCommerceService::orderIdentities($customer, $user), true)) return $this->error('Order not found', 404);
        } else {
            try { $scope = $this->optionalScope($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
            if (!$this->orders->guestTokenIsValid($orderId, (string) $request->header('x-order-token', ''), $scope)) return $this->error('Order not found', 404);
        }
        $result = $this->commerce->orderReturns($orderId, max($request->queryInt('page', 1), 1), min(max($request->queryInt('per_page', 20), 1), 100));
        return $this->paginated(array_map(static fn (array $row): array => ReturnResource::make($row), $result['data']), $result['meta'], 'Return history');
    }

    public function timeline(Request $request): Response
    {
        $orderId = Arr::requiredParam($request, 'id');
        $user = $request->user();
        try { $scope = $this->optionalScope($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        $order = $scope === null ? $this->orders->show($orderId) : $this->orders->showInScope($orderId, $scope);
        if ($order === null) return $this->error('Order not found', 404);
        if (is_array($user)) {
            $customer = $this->commerce->customerForEmail(is_string($user['email'] ?? null) ? $user['email'] : '');
            if ($customer === null || !in_array($order['customer_id'] ?? null, CustomerCommerceService::orderIdentities($customer, $user), true)) return $this->error('Order not found', 404);
        } elseif (!$this->orders->guestTokenIsValid($orderId, (string) $request->header('x-order-token', ''), $scope)) {
            return $this->error('Order not found', 404);
        }
        return $this->success($this->orderRows->timeline($orderId, $scope), 'Order timeline');
    }

    private function optionalScope(Request $request): ?CommerceScope
    {
        foreach (['store_id', 'channel_id', 'locale', 'currency'] as $key) {
            $header = match ($key) { 'store_id' => 'X-Store-Id', 'channel_id' => 'X-Channel-Id', 'locale' => 'X-Locale', default => 'X-Currency' };
            if ($request->queryString($key, '') !== '' || $request->header($header, '') !== '') return CommerceScope::fromRequest($request);
        }
        return null;
    }
}
