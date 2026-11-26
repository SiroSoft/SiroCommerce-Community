<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\CommerceDeadLetter;
use App\Models\CommerceJob;
use App\Models\OutboxMessage;
use App\Models\PaymentOperation;
use App\Models\PaymentWebhookEvent;
use App\Models\Payment;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentReconciliationStore;
use App\Modules\Payment\Contracts\PaymentRefundStore;
use App\Modules\Payment\Contracts\PaymentEvidenceStore;
use App\Modules\Payment\Contracts\PaymentRefundStateStore;
use App\Modules\Queue\Contracts\Job;
use App\Modules\Queue\Contracts\JobStore;
use App\Modules\Shared\Contracts\OutboxWriter;
use App\Modules\Shared\Domain\DomainEvent;
use App\Modules\Webhook\Contracts\WebhookEventStore;
use App\Repositories\Concerns\CreatesModels;
use App\Support\Arr;
use App\Support\Ids;
use App\Support\ProviderEvidence;
use Siro\Core\Database;
use Siro\Core\Model;
use Siro\Core\Queue;
use App\Jobs\CommerceQueueJob;
use App\Modules\Shared\Contracts\TransactionRunner;

/** DB adapter for V2 side effects. Business modules depend on its ports only. */
final class V2SideEffectRepository implements PaymentOperationStore, PaymentReconciliationStore, PaymentRefundStore, PaymentEvidenceStore, PaymentRefundStateStore, WebhookEventStore, OutboxWriter, JobStore, TransactionRunner
{
    use CreatesModels;

    /** @return array<string, mixed>|null */
    public function findByIdempotency(string $key): ?array
    {
        $row = PaymentOperation::query()->where('idempotency_key', $key)->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @param array<string, mixed> $operation */
    public function create(array $operation): void
    {
        Database::transaction(function () use ($operation): void {
            if (Arr::str($operation, 'type') === 'refund') {
                $payment = Payment::query()->where('id', Arr::str($operation, 'payment_id'))->lockForUpdate()->first();
                if ($payment === null) throw new \RuntimeException('Payment not found');
                $reserved = 0;
                foreach (PaymentOperation::query()->where('payment_id', Arr::str($operation, 'payment_id'))->where('type', 'refund')->get() as $row) {
                    if (in_array(Arr::str(Arr::row($row), 'state'), ['processing', 'succeeded', 'refunded'], true)) $reserved += Arr::int(Arr::row($row), 'amount_minor');
                }
                $paymentData = Arr::row($payment);
                $captured = Arr::int($paymentData, 'captured_amount_minor');
                if ($captured === 0 && in_array(Arr::str($paymentData, 'state'), ['captured', 'partially_refunded', 'refunded'], true)) $captured = Arr::int($paymentData, 'amount_minor');
                $refunded = Arr::int($paymentData, 'refunded_amount_minor');
                // The payment counter includes webhook and completed command
                // refunds, while operation rows include in-flight commands.
                if (max($reserved, $refunded) + Arr::int($operation, 'amount_minor') > $captured) {
                    throw new \InvalidArgumentException('Refund amount exceeds the remaining captured amount');
                }
            }
            self::createRow(PaymentOperation::class, $operation);
        });
    }

    /** @param array<string, mixed> $result */
    public function complete(string $id, array $result): void
    {
        $row = PaymentOperation::find($id);
        if ($row === null) {
            throw new \RuntimeException('Payment operation not found');
        }
        $this->saveChanges($row, [
            'state' => Arr::str($result, 'state', 'succeeded'),
            'provider_ref' => Arr::nullableStr($result, 'provider_ref'),
            'result_json' => json_encode(ProviderEvidence::redact($result), JSON_THROW_ON_ERROR),
            'last_error' => Arr::nullableStr($result, 'error'),
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function refundedAmount(string $paymentId): int
    {
        $total = 0;
        foreach (PaymentOperation::query()->where('payment_id', $paymentId)->where('type', 'refund')->get() as $row) {
            $data = Arr::row($row);
            if (in_array(Arr::str($data, 'state'), ['succeeded', 'refunded'], true)) {
                $total += Arr::int($data, 'amount_minor');
            }
        }
        return $total;
    }

    public function applyRefund(string $paymentId, int $amountMinor, ?string $providerRef = null): string
    {
        return (new PaymentStoreAdapter())->applyRefund($paymentId, $amountMinor, $providerRef);
    }

    public function recordEvidence(string $id, array $evidence): void
    {
        $row = PaymentOperation::find($id);
        if ($row === null) return;
        $this->saveChanges($row, [
            'result_json' => json_encode(ProviderEvidence::redact($evidence), JSON_THROW_ON_ERROR),
            'last_error' => Arr::nullableStr($evidence, 'error'),
        ]);
    }

    public function unresolved(): array
    {
        $rows = PaymentOperation::query()->where('state', 'unknown')->get();
        return array_map(static fn (Model $row): array => Arr::row($row), $rows);
    }

    public function reconcile(string $id, array $result): void
    {
        $this->complete($id, $result);
    }

    /** @return array<string, mixed>|null */
    public function find(string $provider, string $eventId): ?array
    {
        $row = PaymentWebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->first();
        return $row === null ? null : Arr::row($row);
    }

    public function receive(string $provider, string $eventId, string $payloadHash, bool $signatureOk, ?string $payload = null): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $payloadHash) !== 1) {
            throw new \InvalidArgumentException('Webhook payload hash must be a SHA-256 hex digest');
        }
        Database::transaction(function () use ($provider, $eventId, $payloadHash, $signatureOk, $payload): void {
            $existing = PaymentWebhookEvent::query()
                ->where('provider', $provider)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) return;
            self::createRow(PaymentWebhookEvent::class, [
                'id' => Ids::new(), 'provider' => $provider, 'event_id' => $eventId,
                'payload_hash' => $payloadHash, 'signature_ok' => $signatureOk ? 1 : 0,
                'state' => 'received', 'payload_json' => $payload, 'received_at' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    public function markQueued(string $provider, string $eventId): void
    {
        $this->updateWebhook($provider, $eventId, ['state' => 'queued']);
    }

    public function claimWebhook(string $provider, string $eventId): bool
    {
        return PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->whereIn('state', ['received', 'queued'])
            ->update(['state' => 'processing']) === 1;
    }

    public function markProcessed(string $provider, string $eventId, string $result): void
    {
        $this->updateWebhook($provider, $eventId, ['state' => $result, 'processed_at' => date('Y-m-d H:i:s')]);
    }

    public function append(DomainEvent $event): void
    {
        $payload = $event->payload();
        self::createRow(OutboxMessage::class, [
            'id' => Ids::new(), 'event_id' => $event->eventId(), 'topic' => $event->name(),
            'aggregate_type' => Arr::str($payload, 'aggregate_type', 'unknown'),
            'aggregate_id' => Arr::str($payload, 'aggregate_id', ''),
            'payload_json' => json_encode($event instanceof \App\Modules\Shared\Domain\EventEnvelope ? $event->toArray() : $payload, JSON_THROW_ON_ERROR),
            'state' => 'pending', 'attempts' => 0,
            'available_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function enqueue(Job $job, string $availableAt): void
    {
        $delay = max(0, strtotime($availableAt) - time());
        $jobId = null;
        Database::transaction(function () use ($job, $availableAt): void {
            $existing = CommerceJob::query()->where('idempotency_key', $job->idempotencyKey())->lockForUpdate()->first();
            if ($existing === null) self::createRow(CommerceJob::class, [
                'id' => Ids::new(), 'queue' => 'commerce', 'name' => $job->name(),
                'idempotency_key' => $job->idempotencyKey(),
                'payload_json' => json_encode($job->payload(), JSON_THROW_ON_ERROR),
                'state' => 'queued', 'attempts' => 0, 'max_attempts' => $job->maxAttempts(),
                'available_at' => $availableAt,
            ]);
        });
        $stored = CommerceJob::query()->where('idempotency_key', $job->idempotencyKey())->first();
        if ($stored === null) throw new \RuntimeException('Durable commerce job was not persisted');
        $jobId = Arr::str($stored->toArray(), 'id');
        // The durable commerce_jobs row is written first. A worker/retry scan can
        // recover a process crash between persistence and the core queue push.
        Queue::push(CommerceQueueJob::class, ['job_id' => $jobId, 'name' => $job->name(), 'payload' => $job->payload()], $delay, 0, $job->maxAttempts());
    }

    /** Re-publish rows persisted before a process died during the core queue push. */
    public function recoverQueuedJobs(int $limit): int
    {
        $recovered = 0;
        $now = date('Y-m-d H:i:s');
        foreach (CommerceJob::query()->where('state', 'queued')->whereNull('last_error')->whereRaw('(available_at IS NULL OR available_at <= :now)', ['now' => $now])->limit($limit)->get() as $model) {
            $data = Arr::row($model);
            $jobId = Arr::str($data, 'id');
            $payload = json_decode(Arr::str($data, 'payload_json', '{}'), true);
            if ($jobId === '' || !is_array($payload)) continue;
            Queue::push(CommerceQueueJob::class, ['job_id' => $jobId, 'name' => Arr::str($data, 'name'), 'payload' => $payload], 0, 0, 1);
            $stored = CommerceJob::find($jobId);
            if ($stored !== null) $this->saveChanges($stored, ['last_error' => 'Core queue delivery recovered at ' . $now]);
            $recovered++;
        }
        return $recovered;
    }

    public function transaction(callable $callback): mixed
    {
        return Database::transaction($callback);
    }

    /** @return array<string, mixed>|null */
    public function claim(string $queue, string $workerId, string $leaseUntil): ?array
    {
        $claimed = Database::transaction(function () use ($queue, $workerId, $leaseUntil): ?array {
            $now = date('Y-m-d H:i:s');
            foreach (CommerceJob::query()->where('queue', $queue)->where('state', 'running')->whereRaw('locked_at IS NOT NULL AND locked_at <= :lease_now', ['lease_now' => $now])->get() as $staleItem) {
                $this->recoverLease($staleItem);
            }
            /** @var \Siro\Core\Model|null $row */
            $row = CommerceJob::query()->where('queue', $queue)->where('state', 'queued')->whereRaw('(available_at IS NULL OR available_at <= :available_now)', ['available_now' => $now])->orderBy('available_at')->lockForUpdate()->first();
            if ($row === null) {
                return null;
            }
            $data = $row->toArray();
            if (Arr::int($data, 'attempts') >= Arr::int($data, 'max_attempts', 5)) {
                $this->deadLetter(Arr::requiredStr($data, 'id'), Arr::str($data, 'last_error', 'Maximum attempts exceeded'));
                return null;
            }
            $this->saveChanges($row, ['state' => 'running', 'worker_id' => $workerId, 'locked_at' => $leaseUntil, 'attempts' => Arr::int($data, 'attempts') + 1]);
            return Arr::row($row);
        });
        /** @var array<string, mixed>|null $claimed */
        return is_array($claimed) ? $claimed : null;
    }

    public function completeJob(string $jobId): void
    {
        $row = CommerceJob::find($jobId);
        if ($row !== null) {
            $this->saveChanges($row, ['state' => 'completed', 'locked_at' => null, 'worker_id' => null, 'last_error' => null, 'completed_at' => date('Y-m-d H:i:s')]);
        }
    }

    /** @return array<string, mixed>|null Claim the durable row represented by a core queue delivery. */
    public function claimJob(string $jobId, string $workerId, string $leaseUntil): ?array
    {
        /** @var array<string, mixed>|null $claimed */
        $claimed = Database::transaction(function () use ($jobId, $workerId, $leaseUntil): ?array {
            /** @var Model|null $row */
            $row = CommerceJob::query()->where('id', $jobId)->lockForUpdate()->first();
            if ($row === null) return null;
            $data = $row->toArray();
            $now = time();
            if (Arr::str($data, 'state') === 'completed') return null;
            if (Arr::str($data, 'state') === 'running' && strtotime(Arr::str($data, 'locked_at')) > $now) return null;
            if (Arr::str($data, 'state') !== 'queued' && Arr::str($data, 'state') !== 'running') return null;
            if (Arr::int($data, 'attempts') >= Arr::int($data, 'max_attempts', 5)) {
                $this->deadLetter($jobId, Arr::str($data, 'last_error', 'Maximum attempts exceeded'));
                return null;
            }
            if (($data['available_at'] ?? null) !== null && strtotime(Arr::str($data, 'available_at')) > $now) return null;
            $this->saveChanges($row, ['state' => 'running', 'worker_id' => $workerId, 'locked_at' => $leaseUntil, 'attempts' => Arr::int($data, 'attempts') + 1]);
            $data['state'] = 'running';
            $data['worker_id'] = $workerId;
            return $data;
        });
        return $claimed;
    }

    public function fail(string $jobId, string $error, string $retryAt): void
    {
        Database::transaction(function () use ($jobId, $error, $retryAt): void {
            /** @var Model|null $row */
            $row = CommerceJob::query()->where('id', $jobId)->lockForUpdate()->first();
            if ($row === null) return;
            $data = $row->toArray();
            if (Arr::str($data, 'state') !== 'running') return;
            if (Arr::int($data, 'attempts') >= Arr::int($data, 'max_attempts', 5)) {
                $this->deadLetter($jobId, $error);
                return;
            }
            $this->saveChanges($row, ['state' => 'queued', 'available_at' => $retryAt, 'locked_at' => null, 'worker_id' => null, 'last_error' => $error]);
        });
    }

    public function deadLetter(string $jobId, string $reason): void
    {
        Database::transaction(function () use ($jobId, $reason): void {
            /** @var Model|null $job */
            $job = CommerceJob::query()->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null) return;
            $existing = CommerceDeadLetter::query()->where('job_id', $jobId)->whereNull('resolved_at')->first();
            if ($existing === null) {
                $data = $job->toArray();
                self::createRow(CommerceDeadLetter::class, [
                    'id' => Ids::new(), 'job_id' => $jobId, 'reason' => $reason,
                    'payload_json' => Arr::str($data, 'payload_json'), 'failed_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $this->saveChanges($job, ['state' => 'dead', 'locked_at' => null, 'worker_id' => null, 'last_error' => $reason]);
        });
    }

    public function cancel(string $jobId, string $reason): void
    {
        Database::transaction(function () use ($jobId, $reason): void {
            /** @var Model|null $job */
            $job = CommerceJob::query()->where('id', $jobId)->lockForUpdate()->first();
            if ($job === null) return;
            $state = Arr::str($job->toArray(), 'state');
            if (in_array($state, ['completed', 'dead', 'cancelled'], true)) return;
            $this->saveChanges($job, ['state' => 'cancelled', 'locked_at' => null, 'worker_id' => null, 'last_error' => $reason]);
        });
    }

    /** Remove only completed rows after their retention window. */
    public function pruneCompleted(int $retentionSeconds = 604800, int $limit = 500): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(3600, $retentionSeconds));
        $removed = 0;
        foreach (CommerceJob::query()->where('state', 'completed')->where('completed_at', '<', $cutoff)->limit(max(1, min($limit, 5000)))->get() as $job) {
            if ($job->delete()) $removed++;
        }
        return $removed;
    }

    public function isQueued(string $jobId): bool
    {
        $row = CommerceJob::find($jobId);
        return $row !== null && Arr::str($row->toArray(), 'state') === 'queued';
    }

    /** @param array<string, mixed> $changes */
    private function updateWebhook(string $provider, string $eventId, array $changes): void
    {
        $row = PaymentWebhookEvent::query()->where('provider', $provider)->where('event_id', $eventId)->first();
        if ($row !== null) $this->saveChanges($row, $changes);
    }

    /** @param array<string, mixed> $changes */
    private function saveChanges(Model $row, array $changes): void
    {
        foreach ($changes as $key => $value) {
            $row->setAttribute($key, $value);
        }
        $row->save();
    }

    private function recoverLease(mixed $item): void
    {
        $row = is_array($item) ? CommerceJob::find($this->idOf($item)) : ($item instanceof Model ? $item : null);
        if ($row !== null) $this->saveChanges($row, ['state' => 'queued', 'locked_at' => null, 'worker_id' => null, 'available_at' => date('Y-m-d H:i:s')]);
    }

    /** @param array<mixed, mixed> $item */
    private function idOf(array $item): string
    {
        $map = [];
        foreach ($item as $key => $value) if (is_string($key)) $map[$key] = $value;
        return Arr::str($map, 'id');
    }
}
