<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Customer;
use App\Models\CustomerNotificationPreference;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;
use App\Models\ConsentAuditEvent;

/** Customer-owned preferences and the bounded, order-backed points ledger. */
final class CustomerEngagementService
{
    private const MAX_POINTS = 2147483647;

    /** @return array<string, mixed> */
    public function preferences(string $customerId): array
    {
        $this->assertCustomer($customerId);
        $row = CustomerNotificationPreference::query()->where('customer_id', $customerId)->first();
        return $row === null ? $this->defaultPreferences() : Arr::row($row);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updatePreferences(string $customerId, array $data, string $key = ''): array
    {
        $key = trim($key);
        if (strlen($key) > 128) throw new \InvalidArgumentException('Idempotency-Key is too long');
        $fingerprint = $this->fingerprint(['operation' => 'preferences', 'customer_id' => $customerId, 'data' => $data]);
        return $this->transactionArray(function () use ($customerId, $data, $key, $fingerprint): array {
            $this->assertCustomer($customerId);
            if ($key !== '') {
                /** @var CustomerNotificationPreference|null $keyRow */
                $keyRow = CustomerNotificationPreference::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($keyRow !== null) {
                    $keyData = Arr::row($keyRow);
                    if (Arr::str($keyData, 'customer_id') !== $customerId) throw new \RuntimeException('Idempotency key belongs to another customer');
                    if (Arr::str($keyData, 'request_fingerprint') !== $fingerprint) throw new \InvalidArgumentException('Idempotency key was reused for a different request');
                    return $keyData;
                }
            }
            /** @var CustomerNotificationPreference|null $row */
            $row = CustomerNotificationPreference::query()->where('customer_id', $customerId)->lockForUpdate()->first();
            $current = $row === null ? $this->defaultPreferences() : Arr::row($row);
            $values = [
                'transactional_email' => $this->flag($data, 'transactional_email', Arr::int($current, 'transactional_email', 1)),
                'marketing_email' => $this->flag($data, 'marketing_email', Arr::int($current, 'marketing_email', 0)),
                'idempotency_key' => $key === '' ? null : $key,
                'request_fingerprint' => $key === '' ? null : $fingerprint,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($row === null) {
                $row = new CustomerNotificationPreference();
                $row->fill(['customer_id' => $customerId] + $values);
                $row->save();
            } else {
                $row->fill($values);
                $row->save();
            }
            foreach (['transactional_email' => 'transactional_email', 'marketing_email' => 'marketing_email'] as $purpose => $field) {
                if (array_key_exists($field, $data) && Arr::int($current, $field) !== $values[$field]) {
                    $consent = new ConsentAuditEvent();
                    $consent->fill(['id' => Ids::new(), 'customer_id' => $customerId, 'purpose' => $purpose, 'granted' => $values[$field], 'version' => 'notification-preferences-v1', 'source' => 'account', 'occurred_at' => date('Y-m-d H:i:s')]);
                    $consent->save();
                }
            }
            return Arr::row($row);
        });
    }

    /** @return array<string, mixed> */
    public function loyalty(string $customerId): array
    {
        $this->assertCustomer($customerId);
        $account = LoyaltyAccount::query()->where('customer_id', $customerId)->first();
        return $account === null ? ['id' => null, 'balance_points' => 0, 'state' => 'active'] : Arr::row($account);
    }

    /** @return array<string, mixed> */
    public function earn(string $customerId, string $orderId, string $key): array
    {
        $key = $this->key($key);
        $fingerprint = $this->fingerprint(['operation' => 'earn', 'order_id' => $orderId]);
        return $this->transactionArray(function () use ($customerId, $orderId, $key, $fingerprint): array {
            $this->assertCustomer($customerId, true);
            $existing = LoyaltyLedger::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) return $this->ownedLedger($existing, $customerId, $this->fingerprint(['operation' => 'earn', 'order_id' => $orderId]));
            $order = Order::query()->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null || Arr::str(Arr::row($order), 'customer_id') !== $customerId) throw new \RuntimeException('Order not found');
            $orderData = Arr::row($order);
            if (!in_array(Arr::str($orderData, 'state'), ['paid', 'processing', 'fulfilled', 'completed', 'delivered'], true)) throw new \InvalidArgumentException('Points are earned only for settled orders');
            $payment = \App\Models\Payment::query()->where('order_id', $orderId)->whereIn('state', ['captured', 'settled'])->first();
            if ($payment === null) throw new \InvalidArgumentException('Points are earned only after payment settlement');
            $points = intdiv(max(0, Arr::int($orderData, 'grand_total_minor')), 100);
            if ($points < 1) throw new \InvalidArgumentException('Order total does not support points');
            if ($points > self::MAX_POINTS) throw new \InvalidArgumentException('Order total produces too many points');
            $account = $this->accountForUpdate($customerId);
            if (Arr::int(Arr::row($account), 'balance_points') > self::MAX_POINTS - $points) throw new \InvalidArgumentException('Points balance overflow');
            $ledger = new LoyaltyLedger();
            $ledger->fill([
                'id' => Ids::new(), 'account_id' => Arr::requiredStr(Arr::row($account), 'id'), 'customer_id' => $customerId,
                'operation' => 'earn', 'points' => $points, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'order_id' => $orderId,
                'expires_at' => date('Y-m-d H:i:s', time() + 31536000), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $ledger->save();
            LoyaltyAccount::query()->where('id', Arr::requiredStr(Arr::row($account), 'id'))->update([
                'balance_points' => Arr::int(Arr::row($account), 'balance_points') + $points,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return Arr::row($ledger);
        });
    }

    /** Reverse earned points proportionally when a settled order is refunded. */
    public function reverseForRefund(string $customerId, string $orderId, int $refundMinor, int $orderTotalMinor, string $key): array
    {
        $key = $this->key($key);
        if ($refundMinor < 1 || $orderTotalMinor < 1 || $refundMinor > $orderTotalMinor) throw new \InvalidArgumentException('Invalid loyalty refund amount');
        $fingerprint = $this->fingerprint(['operation' => 'refund', 'order_id' => $orderId, 'refund_minor' => $refundMinor, 'order_total_minor' => $orderTotalMinor]);
        return $this->transactionArray(function () use ($customerId, $orderId, $refundMinor, $orderTotalMinor, $key, $fingerprint): array {
            $this->assertCustomer($customerId, true);
            $existing = LoyaltyLedger::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) return $this->ownedLedger($existing, $customerId, $fingerprint);
            $earned = LoyaltyLedger::query()->where('customer_id', $customerId)->where('order_id', $orderId)->where('operation', 'earn')->first();
            if ($earned === null) return ['customer_id' => $customerId, 'order_id' => $orderId, 'points' => 0, 'idempotency_key' => $key];
            $earnedData = Arr::row($earned);
            $account = $this->accountForUpdate($customerId);
            $reversal = LoyaltyLedger::query()->where('customer_id', $customerId)->where('order_id', $orderId)->where('operation', 'refund')->lockForUpdate()->first();
            $alreadyReversed = $reversal === null ? 0 : max(0, -Arr::int(Arr::row($reversal), 'points'));
            $target = intdiv(max(0, Arr::int($earnedData, 'points')) * $refundMinor, $orderTotalMinor);
            $delta = max(0, $target - $alreadyReversed);
            if ($delta === 0) return $reversal === null ? ['customer_id' => $customerId, 'order_id' => $orderId, 'points' => 0, 'idempotency_key' => $key] : Arr::row($reversal);
            $balance = Arr::int(Arr::row($account), 'balance_points');
            $delta = min($delta, $balance);
            if ($reversal === null) {
                $reversal = new LoyaltyLedger();
                $reversal->fill(['id' => Ids::new(), 'account_id' => Arr::requiredStr(Arr::row($account), 'id'), 'customer_id' => $customerId, 'operation' => 'refund', 'points' => -$delta, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'order_id' => $orderId, 'created_at' => date('Y-m-d H:i:s')]);
            } else {
                $reversal->fill(['points' => -($alreadyReversed + $delta)]);
            }
            $reversal->save();
            LoyaltyAccount::query()->where('id', Arr::requiredStr(Arr::row($account), 'id'))->update(['balance_points' => $balance - $delta, 'updated_at' => date('Y-m-d H:i:s')]);
            return Arr::row($reversal);
        });
    }

    /** @return array<string, mixed> */
    public function redeem(string $customerId, int $points, string $key): array
    {
        $key = $this->key($key);
        if ($points < 1) throw new \InvalidArgumentException('Points must be positive');
        if ($points > self::MAX_POINTS) throw new \InvalidArgumentException('Points exceed the supported maximum');
        $fingerprint = $this->fingerprint(['operation' => 'redeem', 'points' => $points]);
        return $this->transactionArray(function () use ($customerId, $points, $key, $fingerprint): array {
            $this->assertCustomer($customerId, true);
            $existing = LoyaltyLedger::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) return $this->ownedLedger($existing, $customerId, $this->fingerprint(['operation' => 'redeem', 'points' => $points]));
            $account = $this->accountForUpdate($customerId);
            $balance = Arr::int(Arr::row($account), 'balance_points');
            if ($balance < $points) throw new \InvalidArgumentException('Insufficient points');
            $ledger = $this->ledger($account, $customerId, 'redeem', -$points, $key, null, $fingerprint);
            LoyaltyAccount::query()->where('id', Arr::requiredStr(Arr::row($account), 'id'))->update([
                'balance_points' => $balance - $points,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return $ledger;
        });
    }

    /** @return array<string, mixed> */
    public function expire(string $customerId, string $before, string $key): array
    {
        $key = $this->key($key);
        $cutoff = strtotime($before);
        if ($cutoff === false) throw new \InvalidArgumentException('A valid expiry cutoff is required');
        // Idempotency is based on the effective cutoff, not timestamp formatting.
        $fingerprint = $this->fingerprint(['operation' => 'expire', 'cutoff' => $cutoff]);
        return $this->transactionArray(function () use ($customerId, $before, $cutoff, $key, $fingerprint): array {
            $this->assertCustomer($customerId, true);
            $existing = LoyaltyLedger::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) return $this->ownedLedger($existing, $customerId, $fingerprint);
            $account = $this->accountForUpdate($customerId);
            $balance = Arr::int(Arr::row($account), 'balance_points');
            $expired = 0;
            /** @var array<int, LoyaltyLedger> $entries */
            $entries = LoyaltyLedger::query()->where('customer_id', $customerId)->where('operation', 'earn')->whereNull('expired_at')->get();
            /** Negative ledger entries consume earned lots oldest-first. */
            $debit = 0;
            foreach (LoyaltyLedger::query()->where('customer_id', $customerId)->whereIn('operation', ['redeem', 'expire'])->orderBy('created_at', 'asc')->get() as $debitRow) {
                $debit += max(0, -Arr::int(Arr::row($debitRow), 'points'));
            }
            foreach ($entries as $row) {
                $data = Arr::row($row);
                $expiresAt = Arr::nullableStr($data, 'expires_at');
                $earned = max(0, Arr::int($data, 'points'));
                $consumed = min($earned, $debit);
                $debit -= $consumed;
                $available = $earned - $consumed;
                if ($expiresAt === null || strtotime($expiresAt) === false || strtotime($expiresAt) > $cutoff || $available < 1) continue;
                $row->setAttribute('expired_at', date('Y-m-d H:i:s'));
                $row->save();
                $expired += $available;
            }
            $expired = min($expired, $balance);
            if ($expired < 1) throw new \InvalidArgumentException('No points are eligible to expire');
            $ledger = $this->ledger($account, $customerId, 'expire', -$expired, $key, null, $fingerprint);
            LoyaltyAccount::query()->where('id', Arr::requiredStr(Arr::row($account), 'id'))->update([
                'balance_points' => $balance - $expired,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return $ledger + ['expires_before' => $before];
        });
    }

    /** @return array<string, mixed> */
    private function defaultPreferences(): array { return ['transactional_email' => 1, 'marketing_email' => 0, 'updated_at' => null]; }

    private function assertCustomer(string $customerId, bool $lock = false): void
    {
        $query = Customer::query()->where('id', $customerId);
        if ($lock) $query->lockForUpdate();
        if ($query->first() === null) throw new \RuntimeException('Customer not found');
    }

    /** @param array<string, mixed> $data */
    private function flag(array $data, string $key, int $default): int
    {
        if (!array_key_exists($key, $data)) return $default;
        $value = $data[$key];
        if (is_bool($value)) return $value ? 1 : 0;
        if ($value === 0 || $value === 1) return $value;
        throw new \InvalidArgumentException("{$key} must be boolean");
    }

    private function accountForUpdate(string $customerId): LoyaltyAccount
    {
        /** @var LoyaltyAccount|null $account */
        $account = LoyaltyAccount::query()->where('customer_id', $customerId)->lockForUpdate()->first();
        if ($account !== null) {
            if (Arr::str(Arr::row($account), 'state') !== 'active') throw new \RuntimeException('Loyalty account is inactive');
            return $account;
        }
        $account = new LoyaltyAccount();
        $id = Ids::new();
        $account->fill(['id' => $id, 'customer_id' => $customerId, 'balance_points' => 0, 'state' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
        $account->save();
        $account->setAttribute('id', $id);
        return $account;
    }

    /** @return array<string, mixed> */
    private function ledger(LoyaltyAccount $account, string $customerId, string $operation, int $points, string $key, ?string $orderId, string $fingerprint): array
    {
        $ledger = new LoyaltyLedger();
        $id = Ids::new();
        $ledger->fill(['id' => $id, 'account_id' => Arr::requiredStr(Arr::row($account), 'id'), 'customer_id' => $customerId, 'operation' => $operation, 'points' => $points, 'idempotency_key' => $key, 'request_fingerprint' => $fingerprint, 'order_id' => $orderId, 'created_at' => date('Y-m-d H:i:s')]);
        $ledger->save();
        $ledger->setAttribute('id', $id);
        return Arr::row($ledger);
    }

    /** @return array<string, mixed> */
    private function ownedLedger(mixed $row, string $customerId, string $fingerprint): array
    {
        $data = Arr::row($row);
        if (Arr::str($data, 'customer_id') !== $customerId) throw new \RuntimeException('Idempotency key belongs to another customer');
        if (Arr::str($data, 'request_fingerprint') !== $fingerprint) throw new \InvalidArgumentException('Idempotency key was reused for a different request');
        return $data;
    }

    private function key(string $key): string
    {
        $key = trim($key);
        if ($key === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        if (strlen($key) > 128) throw new \InvalidArgumentException('Idempotency-Key is too long');
        return $key;
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function transactionArray(callable $callback): array
    {
        $result = Database::transaction($callback);
        if (!is_array($result)) throw new \RuntimeException('Customer mutation did not return a result');
        return Arr::row($result);
    }
}
