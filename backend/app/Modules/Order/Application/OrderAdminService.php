<?php
declare(strict_types=1);
namespace App\Modules\Order\Application;

use App\Modules\Order\Contracts\OrderAdminPort;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Shared\Domain\Money;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Audit;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Metrics;
use App\Modules\Platform\Infrastructure\CustomerEngagementService;
use App\Modules\Vendor\Application\VendorPayoutService;

final class OrderAdminService
{
    public function __construct(private readonly OrderAdminPort $orders, private readonly OrderManager $state, private readonly PaymentOperationService $payments, private readonly PaymentProvider $provider, private readonly CustomerEngagementService $engagement, private readonly ?VendorPayoutService $vendorPayouts = null) {}
    /** @param array<string, string> $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function list(int $page, int $perPage, ?\App\Support\CommerceScope $scope = null, array $filters = []): array { return $this->orders->latest($page, $perPage, $scope, $filters); }
    /** @return array<string, mixed>|null */
    public function show(string $id, ?\App\Support\CommerceScope $scope = null): ?array
    {
        $order = $scope === null ? $this->orders->find($id) : $this->orders->findInScope($id, $scope); if ($order === null) return null;
        $order['lines'] = $this->orders->lines($id); $order['payments'] = $this->orders->payments($id); $order['transitions'] = $this->orders->transitions($id); return $order;
    }
    /** @return array<string, mixed> */
    public function transition(string $id, string $to, string $actor, string $reason = '', string $idempotencyKey = ''): array
    {
        if ($idempotencyKey !== '') {
            $existing = $this->orders->findTransitionByIdempotency($id, $idempotencyKey);
            if ($existing !== null) {
                if (Arr::str($existing, 'to_state') !== $to || Arr::str($existing, 'reason') !== $reason) throw new \InvalidArgumentException('Idempotency key conflicts with the transition request');
                return $this->orders->find($id) ?? throw new \RuntimeException('Order not found');
            }
        }
        $before = $this->orders->find($id) ?? throw new \RuntimeException('Order not found');
        $from = Arr::str($before, 'state');
        $result = $this->state->transition($id, $to, $actor, $reason);
        if ($to === 'paid') {
            $this->orders->markPaymentsCaptured($id);
            // Commission snapshot -> ledger is best effort: a ledger
            // misconfiguration must never roll back a paid order.
            try { $this->vendorLedger()->recordSalesForOrder($id); } catch (\Throwable $e) { error_log('[vendor-ledger] sale recording failed: ' . $e->getMessage()); }
        }
        if ($idempotencyKey !== '') $this->orders->logTransition(['id' => Ids::new(), 'order_id' => $id, 'from_state' => $from, 'to_state' => $to, 'actor' => $actor, 'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'created_at' => date('Y-m-d H:i:s')]);
        return $result;
    }
    /** Refund whatever is left (grand total minus refunded): one click for staff. */
    /** @return array<string, mixed> */
    public function refundRemaining(string $id, string $actor, string $reason, string $idempotencyKey): array
    {
        $order = $this->orders->find($id);
        if ($order === null) throw new \RuntimeException('Order not found');
        $remaining = Arr::int($order, 'grand_total_minor') - $this->orders->refundedTotal($id);
        if ($remaining <= 0) throw new \InvalidArgumentException('Nothing left to refund');
        return $this->refund($id, $remaining, $actor, $reason, $idempotencyKey);
    }

    /**
     * Refund-to-credit: remaining value becomes customer store credit instead
     * of a provider refund. State machine and idempotency mirror refund().
     * @return array{order: array<string, mixed>, credit: array<string, mixed>}
     */
    public function refundToCredit(string $id, string $actor, string $reason, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $order = $this->orders->find($id);
        if ($order === null) throw new \RuntimeException('Order not found');
        $customerId = Arr::nullableStr($order, 'customer_id');
        if ($customerId === null || $customerId === '') throw new \InvalidArgumentException('Only customer orders can refund to credit');
        $remaining = Arr::int($order, 'grand_total_minor') - $this->orders->refundedTotal($id);
        if ($remaining <= 0) throw new \InvalidArgumentException('Nothing left to refund');
        $gifts = new \App\Modules\Promotion\Application\GiftCreditService();
        $replay = $gifts->findByKey('credit-refund:' . $idempotencyKey);
        if ($replay !== null) return ['order' => $order, 'credit' => $replay];
        return Database::transaction(function () use ($gifts, $id, $customerId, $remaining, $order, $idempotencyKey, $actor, $reason): array {
            $credit = $gifts->issueCredit(null, $customerId, 'store_credit', $remaining, Arr::str($order, 'currency', 'VND'), 'credit-refund:' . $idempotencyKey, null, $id);
            $updated = $this->state->transition($id, $remaining === Arr::int($order, 'grand_total_minor') ? 'refunded' : 'partially_refunded', $actor, $reason !== '' ? $reason : 'refund to store credit');
            return ['order' => $updated, 'credit' => $credit];
        });
    }

    /** @param array<int, array<string, mixed>> $allocation
     * @return array<string, mixed>
     */
    public function refund(string $id, int $amount, string $actor, string $reason = '', string $idempotencyKey = '', array $allocation = []): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $existingRefund = $this->orders->findRefundByIdempotency($idempotencyKey);
        if ($existingRefund !== null) {
            if (Arr::str($existingRefund, 'order_id') !== $id || Arr::int($existingRefund, 'amount_minor') !== $amount || Arr::str($existingRefund, 'reason') !== $reason) throw new \InvalidArgumentException('Idempotency key conflicts with the refund request');
            try { $this->vendorLedger()->recordRefund($id, $amount, $idempotencyKey, $allocation); } catch (\Throwable $e) { error_log('[vendor-ledger] refund replay recording failed: ' . $e->getMessage()); }
            return $this->orders->find($id) ?? throw new \RuntimeException('Order not found');
        }
        $order = $this->orders->find($id); if ($order === null) throw new \RuntimeException('Order not found');
        $payment = $this->orders->payments($id)[0] ?? null; if (!is_array($payment)) throw new \RuntimeException('No payment to refund');
        $remaining = Arr::int($order, 'grand_total_minor') - $this->orders->refundedTotal($id);
        if ($amount <= 0 || $amount > $remaining) throw new \InvalidArgumentException('Invalid refund amount');
        \App\Modules\Shared\Hooks\Hooks::fire(\App\Modules\Shared\Hooks\HookName::RefundBeforeIssue, ['order_id' => $id, 'amount_minor' => $amount, 'actor' => $actor, 'reason' => $reason]);
        $paymentId = Arr::requiredStr($payment, 'id');
        $operation = new PaymentOperation(Ids::new(), $paymentId, 'refund', new Money($amount, Arr::str($order, 'currency', 'VND')), 'refund:' . $idempotencyKey);
        $result = $this->payments->execute($operation, $this->provider, ['order_id' => $id, 'actor' => $actor, 'reason' => $reason]);
        if (!$result->ok) throw new \RuntimeException($result->message ?? 'Payment provider refund failed');
        Database::transaction(function () use ($id, $paymentId, $amount, $reason, $remaining, $actor, $idempotencyKey, $order, $allocation): void {
            $this->orders->createRefund(['id' => Ids::new(), 'order_id' => $id, 'payment_id' => $paymentId, 'amount_minor' => $amount, 'reason' => $reason, 'state' => 'done', 'idempotency_key' => $idempotencyKey, 'evidence_json' => $allocation === [] ? null : json_encode(['lines' => $allocation], JSON_THROW_ON_ERROR)]);
            $this->state->transition($id, $amount === $remaining ? 'refunded' : 'partially_refunded', $actor, $reason);
            $customerId = Arr::nullableStr($order, 'customer_id');
            if ($customerId !== null) $this->engagement->reverseForRefund($customerId, $id, $amount, Arr::int($order, 'grand_total_minor'), 'loyalty-refund:' . $idempotencyKey);
        });
        try { $this->vendorLedger()->recordRefund($id, $amount, $idempotencyKey, $allocation); } catch (\Throwable $e) { error_log('[vendor-ledger] refund recording failed: ' . $e->getMessage()); }
        Audit::log('commerce.order.refund', ['order_id' => $id, 'payment_id' => $paymentId, 'amount_minor' => $amount, 'actor' => $actor]);
        Metrics::counter('commerce_refunds_total'); Event::emit('order.refunded', ['order_id' => $id, 'amount_minor' => $amount, 'idempotency_key' => $idempotencyKey, 'allocation' => $allocation]);
        $updated = $this->orders->find($id); if ($updated === null) throw new \RuntimeException('Order lost after refund'); return $updated;
    }

    /** @param array<int, array<string, mixed>> $lines */
    public function refundLines(string $id, array $lines, string $actor, string $reason, string $key): array
    {
        if ($lines === []) throw new \InvalidArgumentException('Refund lines are required');
        $orderLines = [];
        foreach ($this->orders->lines($id) as $line) $orderLines[Arr::requiredStr($line, 'id')] = $line;
        $allocation = [];
        $amount = 0;
        foreach ($lines as $line) {
            $lineId = Arr::requiredStr($line, 'order_line_id'); $qty = Arr::requiredInt($line, 'qty');
            if ($qty <= 0 || !isset($orderLines[$lineId]) || $qty > Arr::requiredInt($orderLines[$lineId], 'qty')) throw new \InvalidArgumentException('Invalid refund line quantity');
            $allocation[] = ['order_line_id' => $lineId, 'qty' => $qty];
            $amount += $qty * Arr::requiredInt($orderLines[$lineId], 'unit_price_minor');
        }
        return $this->refund($id, $amount, $actor, $reason, $key, $allocation);
    }

    private function vendorLedger(): VendorPayoutService
    {
        return $this->vendorPayouts ?? new VendorPayoutService();
    }
}
