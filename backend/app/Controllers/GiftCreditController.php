<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Order\Application\OrderAdminService;
use App\Modules\Promotion\Application\GiftCreditService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Gift cards + store credit (spec: shared money loop). Issue/redeem run on an
 * idempotent ledger; checkout tenders gift codes like coupons.
 */
final class GiftCreditController extends Controller
{
    public function __construct(
        private readonly GiftCreditService $gifts,
        private readonly OrderAdminService $orders,
    ) {
    }

    public function issue(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'amount_minor' => 'required|integer',
            'currency' => 'nullable|string',
            'owner_customer_id' => 'nullable|string',
            'code' => 'nullable|string',
            'type' => 'nullable|string',
            'expires_at' => 'nullable|string',
        ]);
        $key = $this->key($request);
        if ($key === null) return $this->error('A valid Idempotency-Key is required', 422);
        try {
            $row = $this->gifts->issueCredit(
                Arr::str($data, 'code') !== '' ? Arr::str($data, 'code') : null,
                Arr::str($data, 'owner_customer_id') !== '' ? Arr::str($data, 'owner_customer_id') : null,
                Arr::str($data, 'type', 'gift') !== '' ? Arr::str($data, 'type', 'gift') : 'gift',
                Arr::requiredInt($data, 'amount_minor'),
                Arr::str($data, 'currency', 'VND') !== '' ? Arr::str($data, 'currency', 'VND') : 'VND',
                $key,
                Arr::str($data, 'expires_at') !== '' ? Arr::str($data, 'expires_at') : null,
                null
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
        return $this->created($this->public($row), 'Credit issued');
    }

    public function index(Request $request): Response
    {
        $owner = $request->queryString('customer_id', '');
        $type = $request->queryString('type', '');
        return $this->success(array_map([$this, 'public'], $this->gifts->list($owner !== '' ? $owner : null, $type !== '' ? $type : null)), 'Credits');
    }

    public function void(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        if (!is_string($id) || $id === '') return $this->error('Invalid credit', 422);
        if ($this->key($request) === null) return $this->error('A valid Idempotency-Key is required', 422);
        try {
            return $this->success($this->public($this->gifts->void($id)), 'Credit voided');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    public function wallet(Request $request): Response
    {
        $user = $request->user();
        if (!is_array($user)) return $this->error('Unauthorized', 401);
        // User ids are integers in storage; Arr::str() only passes strings
        // through, so cast here or every wallet would read as anonymous.
        $raw = $user['customer_id'] ?? $user['id'] ?? '';
        $customerId = is_scalar($raw) ? (string) $raw : '';
        if ($customerId === '') return $this->success([], 'Wallet');
        return $this->success(array_map([$this, 'public'], $this->gifts->list($customerId, null)), 'Wallet');
    }

    public function preview(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['code' => 'required|string', 'subtotal_minor' => 'nullable|integer', 'currency' => 'nullable|string']);
        $user = $request->user();
        $owner = is_array($user) && isset($user['id']) && is_scalar($user['id']) ? (string) $user['id'] : null;
        $row = $this->gifts->balance(Arr::requiredStr($data, 'code'), $owner);
        if ($row === null) return $this->error('Gift code is unknown, bound, or inactive', 422);
        $currency = Arr::str($data, 'currency', (string) ($row['currency'] ?? 'VND'));
        if (strtoupper($currency) !== strtoupper((string) ($row['currency'] ?? ''))) return $this->error('Gift currency mismatch', 422);
        $usable = min((int) ($row['balance_minor'] ?? 0), max(0, (int) ($data['subtotal_minor'] ?? PHP_INT_MAX)));
        if ($usable <= 0) return $this->error('Gift balance is empty', 422);
        return $this->success(['code' => $row['code'], 'balance_minor' => $row['balance_minor'], 'usable_minor' => $usable, 'currency' => $row['currency'], 'state' => $row['state']], 'Gift preview');
    }

    public function refundToCredit(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['reason' => 'nullable|string']);
        $orderId = Arr::requiredParam($request, 'id');
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 400);
        }
        $key = $this->key($request);
        if ($key === null) return $this->error('A valid Idempotency-Key is required', 422);
        $user = $request->user();
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        try {
            $result = $this->orders->refundToCredit($orderId, $actor, Arr::str($data, 'reason'), $key);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), str_contains($e->getMessage(), 'Idempotency key conflicts') ? 409 : 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success($result, 'Refunded to store credit');
    }

    /** @return array<string, mixed> */
    private function public(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
            'owner_customer_id' => isset($row['owner_customer_id']) && is_string($row['owner_customer_id']) ? $row['owner_customer_id'] : null,
            'type' => (string) ($row['type'] ?? 'gift'),
            'amount_minor' => (int) ($row['amount_minor'] ?? 0),
            'balance_minor' => (int) ($row['balance_minor'] ?? 0),
            'currency' => (string) ($row['currency'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'expires_at' => isset($row['expires_at']) && is_string($row['expires_at']) ? $row['expires_at'] : null,
        ];
    }

    private function key(Request $request): ?string
    {
        $key = $request->header('idempotency-key', '');
        return is_string($key) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) === 1 ? $key : null;
    }
}
