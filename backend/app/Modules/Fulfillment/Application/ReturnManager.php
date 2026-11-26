<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Application;

use App\Modules\Fulfillment\Contracts\ReturnService;
use App\Modules\Fulfillment\Contracts\ReturnStore;
use App\Modules\Fulfillment\Contracts\FulfillmentStore;
use App\Modules\Inventory\Contracts\InventoryStore;
use App\Modules\Fulfillment\Contracts\ReturnLabelProvider;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Event;
use Siro\Core\Audit;
use Siro\Core\Metrics;
use Siro\Core\Queue;
use App\Jobs\ReturnLabelJob;
use App\Modules\Shared\Contracts\OutboxWriter;
use App\Modules\Shared\Domain\EventEnvelope;
use App\Modules\Shared\Application\CommandContext;
use App\Modules\Payment\Application\PaymentCommandService;

final class ReturnManager implements ReturnService
{
    public function __construct(
        private readonly ReturnStore $store,
        private readonly FulfillmentStore $fulfillment,
        private readonly ?InventoryStore $inventory = null,
        private readonly ?ReturnLabelProvider $labels = null,
        private readonly ?OutboxWriter $outbox = null,
        private readonly ?PaymentCommandService $payments = null,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function request(string $orderId, string $fulfillmentId, array $lines, string $reason, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $existing = $this->store->byIdempotency($idempotencyKey);
        if ($existing !== null) return $existing;
        $fulfillment = $this->fulfillment->find($fulfillmentId);
        if ($fulfillment === null || Arr::str($fulfillment, 'order_id') !== $orderId) throw new \InvalidArgumentException('Fulfillment does not belong to order');
        if (Arr::str($fulfillment, 'state') !== 'delivered') throw new \InvalidArgumentException('Only delivered fulfillment can be returned');
        $deliveredAt = Arr::str($fulfillment, 'delivered_at');
        $windowDays = (int) (getenv('COMMERCE_RETURN_WINDOW_DAYS') ?: 30);
        if ($deliveredAt !== '' && $windowDays >= 0 && strtotime($deliveredAt) + ($windowDays * 86400) < time()) {
            throw new \InvalidArgumentException('Return window has expired');
        }
        if ($lines === []) throw new \InvalidArgumentException('Return lines are required');

        $result = $this->map(Database::transaction(function () use ($orderId, $fulfillmentId, $lines, $reason, $idempotencyKey): array {
            $existing = $this->store->byIdempotency($idempotencyKey);
            if ($existing !== null) return $existing;
            /** @var array<string, int> $requested */
            $requested = [];
            /** @var array<string, array<string,mixed>> $lineMetadata */
            $lineMetadata = [];
            foreach ($lines as $rawLine) {
                $line = $this->map($rawLine);
                $lineId = Arr::requiredStr($line, 'order_line_id');
                $qty = Arr::requiredInt($line, 'qty');
                if ($qty <= 0) throw new \InvalidArgumentException('Return quantity must be positive');
                $disposition = Arr::str($line, 'disposition', 'restock');
                if (!in_array($disposition, ['restock', 'damaged'], true)) throw new \InvalidArgumentException('Invalid return disposition');
                $requested[$lineId] = ($requested[$lineId] ?? 0) + $qty;
                $lineMetadata[$lineId] = $line;
            }
            foreach ($requested as $lineId => $qty) {
                $this->fulfillment->lockForReturn($fulfillmentId);
                $delivered = $this->fulfillment->deliveredQty($fulfillmentId, $lineId);
                if ($delivered === 0 || $qty + $this->store->returnedQty($lineId) > $delivered) {
                    throw new \InvalidArgumentException('Return quantity exceeds delivered quantity');
                }
            }
            $created = $this->store->create(['id' => Ids::new(), 'order_id' => $orderId, 'fulfillment_id' => $fulfillmentId, 'state' => 'requested', 'idempotency_key' => $idempotencyKey, 'reason' => $reason, 'disposition' => 'refund', 'created_at' => date('Y-m-d H:i:s')]);
            foreach ($requested as $lineId => $qty) { $line = $lineMetadata[$lineId] ?? []; $this->store->addLine(['id' => Ids::new(), 'return_id' => Arr::requiredStr($created, 'id'), 'order_line_id' => $lineId, 'qty' => $qty, 'reason' => Arr::str($line, 'reason', $reason), 'disposition' => Arr::str($line, 'disposition', 'restock'), 'condition' => Arr::str($line, 'condition', 'good')]); }
            $this->emit('fulfillment.return_requested', ['return_id' => Arr::requiredStr($created, 'id'), 'order_id' => $orderId], $idempotencyKey);
            return $created;
        }));
        Metrics::counter('commerce_rma_mutations_total', 1, ['operation' => 'request']);
        return $result;
    }

    public function complete(string $returnId, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $return = $this->store->find($returnId);
        if ($return === null) throw new \RuntimeException('Return not found');
        if (Arr::str($return, 'state') === 'completed') return $return;
        if (Arr::str($return, 'state') !== 'approved_refund') throw new \InvalidArgumentException('Return must be approved, inspected, and refund-approved before completion');
        Database::transaction(function () use ($returnId, $return, $idempotencyKey): void {
            $locked = $this->store->findForUpdate($returnId);
            if ($locked === null) throw new \RuntimeException('Return not found');
            if (Arr::str($locked, 'state') === 'completed') return;
            if (Arr::str($locked, 'state') !== 'approved_refund') throw new \InvalidArgumentException('Return must be approved, inspected, and refund-approved before completion');
            $return = $locked;
            foreach ($this->store->lines($returnId) as $line) {
                $lineId = Arr::requiredStr($line, 'id');
                if ($this->store->inventoryPosted($returnId, $lineId)) continue;
                if ($this->inventory === null) throw new \RuntimeException('RMA inventory integration is not configured');
                $orderLine = $this->fulfillment->orderLine(Arr::requiredStr($return, 'order_id'), Arr::requiredStr($line, 'order_line_id'));
                if ($orderLine === null) throw new \RuntimeException('Return order line evidence is missing');
                $variantId = Arr::requiredStr($orderLine, 'variant_id');
                $disposition = Arr::str($line, 'disposition', 'restock');
                $qty = Arr::requiredInt($line, 'qty');
                $this->inventory->receiveReturn($variantId, $qty, $disposition, $returnId, $lineId, 'rma');
                $this->store->recordInventoryPosting(['id' => Ids::new(), 'return_id' => $returnId, 'return_line_id' => $lineId, 'variant_id' => $variantId, 'qty' => $qty, 'disposition' => $disposition, 'source_id' => 'default', 'created_at' => date('Y-m-d H:i:s')]);
            }
            $this->store->setState($returnId, 'completed', date('Y-m-d H:i:s'), []);
            $this->store->recordEvent(['id' => Ids::new(), 'return_id' => $returnId, 'event_key' => 'completed:' . $idempotencyKey, 'state' => 'completed', 'payload_json' => json_encode(['idempotency_key' => $idempotencyKey], JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
            $this->emit('fulfillment.return_completed', ['return_id' => $returnId, 'idempotency_key' => $idempotencyKey], $idempotencyKey);
        });
        $updated = $this->store->find($returnId);
        if ($updated === null) throw new \RuntimeException('Return lost after completion');
        Metrics::counter('commerce_rma_mutations_total', 1, ['operation' => 'complete']);
        return $updated;
    }

    /** @return array<string,mixed> */
    public function approve(string $id, string $key): array { return $this->transition($id, 'approved', $key, ['requested']); }
    /** @return array<string,mixed> */
    public function inspect(string $id, string $key, string $disposition, string $note = ''): array { if (!in_array($disposition, ['restock','damaged'], true)) throw new \InvalidArgumentException('Invalid return disposition'); $result = $this->transition($id, 'inspected', $key, ['approved'], ['disposition' => $disposition, 'inspection_note' => $note]); $this->store->setLineDisposition($id, $disposition); return $result; }
    /** @return array<string,mixed> */
    public function approveRefund(string $id, string $key): array
    {
        if ($key === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $return = $this->store->find($id);
        if ($return === null) throw new \RuntimeException('Return not found');
        if (Arr::str($return, 'state') === 'approved_refund' || Arr::str($return, 'state') === 'completed') return $return;
        if (Arr::str($return, 'state') !== 'inspected') throw new \InvalidArgumentException('Return must be inspected before refund approval');

        // A return without a linked payment is retained for legacy COD workflows.
        // Card/provider-backed payments must cross the payment command boundary.
        $payment = $this->store->paymentForOrder(Arr::requiredStr($return, 'order_id'));
        if ($payment !== null && $this->payments !== null) {
            $amount = $this->refundAmount($return);
            $paymentId = Arr::requiredStr($payment, 'id');
            // The return state is the outer idempotency fence. A new command key
            // is allowed only while the prior provider attempt failed.
            $refundKey = 'return-refund:' . $id . ':' . $key;
            try {
                $this->payments->execute($paymentId, 'refund', $amount, Arr::str($payment, 'currency', 'VND'), $refundKey, ['order_id' => Arr::requiredStr($return, 'order_id'), 'actor' => 'return:' . $id, 'source' => 'return']);
                $this->store->setState($id, 'approved_refund', null, ['refund_payment_id' => $paymentId, 'refund_amount_minor' => $amount, 'refund_currency' => Arr::str($payment, 'currency', 'VND'), 'refund_state' => 'succeeded', 'refund_idempotency_key' => $refundKey, 'refund_error' => null]);
                $this->store->recordEvent(['id' => Ids::new(), 'return_id' => $id, 'event_key' => 'refund:succeeded:' . $refundKey, 'state' => 'approved_refund', 'payload_json' => json_encode(['payment_id' => $paymentId, 'amount_minor' => $amount], JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
                $this->emit('refund.completed', ['return_id' => $id, 'order_id' => Arr::requiredStr($return, 'order_id'), 'payment_id' => $paymentId, 'amount_minor' => $amount], $refundKey);
            } catch (\Throwable $e) {
                $this->store->setState($id, 'inspected', null, ['refund_payment_id' => $paymentId, 'refund_amount_minor' => $amount, 'refund_currency' => Arr::str($payment, 'currency', 'VND'), 'refund_state' => 'failed', 'refund_idempotency_key' => $refundKey, 'refund_error' => substr($e->getMessage(), 0, 1000)]);
                throw $e;
            }
            $updated = $this->store->find($id);
            if ($updated === null) throw new \RuntimeException('Return lost after refund');
            return $updated;
        }
        return $this->transition($id, 'approved_refund', $key, ['inspected']);
    }

    /** @param array<string,mixed> $return */
    private function refundAmount(array $return): int
    {
        $total = 0;
        foreach ($this->store->lines(Arr::requiredStr($return, 'id')) as $line) {
            $orderLine = $this->fulfillment->orderLine(Arr::requiredStr($return, 'order_id'), Arr::requiredStr($line, 'order_line_id'));
            if ($orderLine === null) throw new \RuntimeException('Return order line evidence is missing');
            $total += Arr::requiredInt($line, 'qty') * Arr::int($orderLine, 'unit_price_minor');
        }
        if ($total <= 0) throw new \InvalidArgumentException('Refund amount must be positive');
        return $total;
    }
    /** @return array<string,mixed> */
    public function reject(string $id, string $key, string $note = ''): array { return $this->transition($id, 'rejected', $key, ['requested','approved','inspected'], ['inspection_note' => $note]); }
    /**
     * @param array<int,string> $from
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function transition(string $id, string $state, string $key, array $from, array $metadata = []): array { if ($key === '') throw new \InvalidArgumentException('Idempotency-Key is required'); $row=$this->store->find($id); if($row===null)throw new \RuntimeException('Return not found'); if(Arr::str($row,'state')===$state)return $row; $changed = Database::transaction(function () use ($id, $state, $key, $from, $metadata): bool { if (!$this->store->transition($id, $from, $state, null, $metadata)) return false; $this->store->recordEvent(['id'=>Ids::new(),'return_id'=>$id,'event_key'=>$state.':'.$key,'state'=>$state,'payload_json'=>json_encode($metadata,JSON_THROW_ON_ERROR),'created_at'=>date('Y-m-d H:i:s')]); $this->emit('fulfillment.return_'.$state, ['return_id' => $id, 'idempotency_key' => $key], $key); return true; }); if (!$changed) throw new \InvalidArgumentException('Return is not ready for this transition'); $out=$this->store->find($id); if($out===null)throw new \RuntimeException('Return lost after transition'); Metrics::counter('commerce_rma_mutations_total', 1, ['operation' => $state]); Audit::log('commerce.rma.'.$state,['return_id'=>$id,'idempotency_key'=>$key]); return $out; }

    /**
     * @param array<string, mixed> $shipping
     * @return array<string, mixed>
     */
    public function createLabel(string $returnId, array $shipping, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $existing = $this->store->byLabelIdempotency($idempotencyKey);
        if ($existing !== null) {
            if (Arr::str($existing, 'id') !== $returnId) throw new \InvalidArgumentException('Label idempotency key was already used');
            if (Arr::str($existing, 'label_state') === 'label_created') return $existing;
            return $existing;
        }
        $return = $this->store->find($returnId);
        if ($return === null) throw new \RuntimeException('Return not found');
        if (!in_array(Arr::str($return, 'state'), ['requested', 'approved'], true)) throw new \InvalidArgumentException('Return is not ready for a label');
        if ($this->labels === null) throw new \RuntimeException('Return-label integration is not configured');
        Database::transaction(function () use ($returnId, $idempotencyKey): void {
            $this->store->queueLabel($returnId, $idempotencyKey);
        });
        Queue::push(ReturnLabelJob::class, ['return_id' => $returnId, 'shipping' => $shipping, 'idempotency_key' => $idempotencyKey], 0, 0, 5);
        $pending = $this->store->find($returnId);
        if ($pending === null) throw new \RuntimeException('Return lost after label enqueue');
        return $pending;
    }

    /**
     * @param array<string, mixed> $shipping
     * @return array<string, mixed>
     */
    public function processLabel(string $returnId, array $shipping, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $return = $this->store->find($returnId);
        if ($return === null) throw new \RuntimeException('Return not found');
        if (Arr::str($return, 'label_state') === 'label_created') return $return;
        if ($this->labels === null) throw new \RuntimeException('Return-label integration is not configured');
        $claimed = Database::transaction(fn (): bool => $this->store->claimLabel($returnId, $idempotencyKey));
        if (!$claimed) {
            $current = $this->store->find($returnId);
            if ($current !== null && Arr::str($current, 'label_state') === 'label_created') return $current;
            throw new \RuntimeException('Return label operation is already in progress or uses another key');
        }
        Metrics::counter('commerce_return_label_attempts_total');
        try { $rawResult = $this->labels->create(['return_id' => $returnId, 'order_id' => Arr::requiredStr($return, 'order_id'), 'shipping' => $shipping, 'lines' => $this->store->lines($returnId)], $idempotencyKey); }
        catch (\Throwable $e) { $this->store->failLabel($returnId, $e->getMessage()); Metrics::counter('commerce_return_label_failures_total'); throw $e; }
        $result = $rawResult;
        Database::transaction(function () use ($returnId, $idempotencyKey, $result): void {
            $this->store->completeLabel($returnId, ['label_idempotency_key' => $idempotencyKey, 'label_provider' => Arr::str($result, 'carrier', 'default'), 'label_provider_ref' => Arr::requiredStr($result, 'provider_ref'), 'label_tracking' => Arr::nullableStr($result, 'tracking'), 'label_url' => Arr::nullableStr($result, 'label_url')]);
            $this->store->recordEvent(['id' => Ids::new(), 'return_id' => $returnId, 'event_key' => 'label:' . $idempotencyKey, 'state' => 'label_created', 'payload_json' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
            $this->emit('fulfillment.return_label_created', ['return_id' => $returnId, 'provider_ref' => Arr::requiredStr($result, 'provider_ref')], $idempotencyKey);
        });
        $out = $this->store->find($returnId);
        if ($out === null) throw new \RuntimeException('Return lost after label creation');
        return $out;
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $name, array $payload, string $key): void
    {
        if ($this->outbox !== null) {
            $eventPayload = $payload;
            $eventPayload['aggregate_type'] = 'return';
            $eventPayload['aggregate_id'] = Arr::str($payload, 'return_id');
            $this->outbox->append(new EventEnvelope(Ids::new(), $name, date(DATE_ATOM), new CommandContext('system', Ids::new(), Ids::new(), $key), $eventPayload));
            return;
        }
        Event::emit($name, $payload);
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (!is_array($value)) throw new \InvalidArgumentException('Fulfillment data must be an object');
        $result = [];
        foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item;
        return $result;
    }
}
