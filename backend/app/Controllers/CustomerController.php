<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Customer\Application\CustomerService;
use App\Modules\Customer\Application\CustomerCommerceService;
use App\Modules\Platform\Infrastructure\CustomerEngagementService;
use App\Modules\Fulfillment\Contracts\ReturnService;
use App\Resources\AddressResource;
use App\Resources\CustomerNotificationPreferenceResource;
use App\Resources\CustomerResource;
use App\Resources\LoyaltyResource;
use App\Resources\OrderResource;
use App\Resources\ReturnResource;
use App\Resources\CustomerReturnResource;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

final class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers, private readonly CustomerCommerceService $commerce, private readonly CustomerEngagementService $engagement, private readonly ReturnService $returnService) {}

    public function orders(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        try { $scope = $this->optionalScope($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        [$profileId, $aliasIds] = $this->orderScope($request, $customer);
        $result = $this->commerce->orders($profileId, $this->page($request), $this->perPage($request), $scope, $aliasIds);
        return $this->paginated(array_map(static fn (array $row): array => OrderResource::make($row), $result['data']), $result['meta'], 'Order history');
    }

    public function returns(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        [$profileId, $aliasIds] = $this->orderScope($request, $customer);
        $result = $this->commerce->returns($profileId, $this->page($request), $this->perPage($request), $aliasIds);
        return $this->paginated(array_map(static fn (array $row): array => CustomerReturnResource::make($row), $result['data']), $result['meta'], 'Return history');
    }

    public function addressesSelf(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        return $this->success(array_map(static fn (array $row): array => AddressResource::make($row), $this->customers->addresses(Arr::requiredStr($customer, 'id'))), 'Saved addresses');
    }

    public function addAddressSelf(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['label' => 'nullable|string', 'line1' => 'required|string', 'city' => 'required|string', 'phone' => 'required|string']);
        try { return $this->created(AddressResource::make($this->customers->addAddress(Arr::requiredStr($customer, 'id'), $data)), 'Address created'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function preferences(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        return $this->success(CustomerNotificationPreferenceResource::make($this->engagement->preferences(Arr::requiredStr($customer, 'id'))), 'Notification preferences');
    }

    public function updatePreferences(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['transactional_email' => 'nullable|boolean', 'marketing_email' => 'nullable|boolean']);
        try {
            return $this->success(CustomerNotificationPreferenceResource::make($this->engagement->updatePreferences(Arr::requiredStr($customer, 'id'), $data, $this->idempotencyKey($request))), 'Notification preferences updated');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function loyalty(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        return $this->success(LoyaltyResource::make($this->engagement->loyalty(Arr::requiredStr($customer, 'id'))), 'Loyalty account');
    }

    public function redeemPoints(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['points' => 'required|integer|min:1']);
        try {
            return $this->success($this->engagement->redeem(Arr::requiredStr($customer, 'id'), Arr::requiredInt($data, 'points'), $this->idempotencyKey($request)), 'Points redeemed');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function earnPoints(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        try {
            return $this->success($this->engagement->earn(Arr::requiredStr($customer, 'id'), Arr::requiredParam($request, 'order'), $this->idempotencyKey($request)), 'Points earned');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function expirePoints(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['before' => 'required|date']);
        try {
            return $this->success($this->engagement->expire(Arr::requiredStr($customer, 'id'), Arr::requiredStr($data, 'before'), $this->idempotencyKey($request)), 'Points expired');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function returnForAccount(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        [$profileId, $aliasIds] = $this->orderScope($request, $customer);
        $return = $this->commerce->returnForCustomer($profileId, Arr::requiredParam($request, 'id'), $aliasIds);
        return $return === null ? $this->error('Not found', 404) : $this->success(CustomerReturnResource::make($return), 'Return detail');
    }

    public function orderNotifications(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        [$profileId, $aliasIds] = $this->orderScope($request, $customer);
        $notifications = $this->commerce->notificationsForOrder($profileId, Arr::requiredParam($request, 'id'), $aliasIds);
        return $this->success($notifications, 'Order notification status');
    }

    public function messageHistory(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 403); }
        [$profileId, $aliasIds] = $this->orderScope($request, $customer);
        $result = $this->commerce->notificationHistory($profileId, $scope, $this->page($request), min(50, $this->perPage($request)), $aliasIds);
        return $this->paginated($result['data'], $result['meta'], 'Message history');
    }

    public function requestReturn(Request $request): Response
    {
        $customer = $this->accountCustomer($request);
        if ($customer === null) return $this->error('Not found', 404);
        $orderId = Arr::requiredParam($request, 'id');
        [$profileId, $aliasIds] = $this->orderScope($request, $customer);
        if (!$this->commerce->ownsOrder($profileId, $orderId, $aliasIds)) return $this->error('Not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate(['fulfillment_id' => 'required|string', 'lines' => 'required|array', 'reason' => 'nullable|string']);
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $line) {
            if (!is_array($line)) return $this->error('Return lines must be objects', 422);
            $line['order_line_id'] = $line['order_line_id'] ?? $line['line_id'] ?? null;
            if (!is_string($line['order_line_id']) || !is_numeric($line['qty'])) return $this->error('Return line details are invalid', 422);
            $lines[] = array_filter($line, 'is_string', ARRAY_FILTER_USE_KEY);
        }
        try {
            $result = $this->returnService->request($orderId, Arr::requiredStr($data, 'fulfillment_id'), $lines, Arr::str($data, 'reason'), $this->idempotencyKey($request));
            return $this->created(ReturnResource::make($result), 'Return requested');
        } catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function show(Request $request): Response
    {
        $customer = $this->customers->find(Arr::requiredParam($request, 'id'));
        if ($customer === null) return $this->error('Customer not found', 404);
        $user = $request->user();
        $role = is_array($user) && is_string($user['role'] ?? null) ? $user['role'] : '';
        $fields = in_array($role, ['admin', 'owner'], true)
            ? ['id', 'name', 'email', 'phone', 'status', 'created_at', 'updated_at']
            : ['id', 'name', 'status', 'created_at', 'updated_at'];
        return $this->success(CustomerResource::make($customer, $fields), 'Customer detail');
    }

    public function addresses(Request $request): Response
    {
        try { return $this->success($this->customers->addresses(Arr::requiredParam($request, 'id')), 'Customer addresses'); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 404); }
    }

    public function addAddress(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['label' => 'nullable|string', 'line1' => 'required|string', 'city' => 'required|string', 'phone' => 'required|string']);
        try { return $this->created($this->customers->addAddress(Arr::requiredParam($request, 'id'), $data), 'Address created'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    /** @return array<string, mixed>|null */
    private function accountCustomer(Request $request): ?array
    {
        $user = $request->user();
        $email = is_array($user) && is_string($user['email'] ?? null) ? $user['email'] : '';
        if ($email === '') return null;
        $customer = $this->commerce->customerForEmail($email);
        if ($customer !== null) return $customer;
        // Self-healing: accounts created before customer provisioning still get
        // a profile on first account use instead of a permanent 404.
        try {
            $customers = \Siro\Core\Container::getInstance()->make(\App\Modules\Customer\Application\CustomerService::class);
            if ($customers instanceof \App\Modules\Customer\Application\CustomerService) {
                $customers->register($email, is_array($user) && is_string($user['name'] ?? null) ? $user['name'] : '');
                return $this->commerce->customerForEmail($email);
            }
        } catch (\Throwable) {
            // Fall through to null: the endpoint reports not found.
        }
        return null;
    }

    /** @param array<string, mixed> $customer @return array{0: string, 1: list<string>} profile id + alias owner ids */
    private function orderScope(Request $request, array $customer): array
    {
        $ids = \App\Modules\Customer\Application\CustomerCommerceService::orderIdentities($customer, $request->user());
        return [Arr::requiredStr($customer, 'id'), array_values(array_filter($ids, static fn (string $id): bool => $id !== Arr::str($customer, 'id')))];
    }

    private function page(Request $request): int { return max($request->queryInt('page', 1), 1); }
    private function perPage(Request $request): int { return min(max($request->queryInt('per_page', 20), 1), 100); }
    private function optionalScope(Request $request): ?CommerceScope
    {
        foreach (['store_id', 'channel_id', 'locale', 'currency'] as $key) {
            $header = match ($key) { 'store_id' => 'X-Store-Id', 'channel_id' => 'X-Channel-Id', 'locale' => 'X-Locale', default => 'X-Currency' };
            if ($request->queryString($key, '') !== '' || $request->header($header, '') !== '') return CommerceScope::fromRequest($request);
        }
        return null;
    }
    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key', '');
        return is_string($key) ? trim($key) : '';
    }
}
