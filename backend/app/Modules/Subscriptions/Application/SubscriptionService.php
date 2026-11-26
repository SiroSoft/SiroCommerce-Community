<?php
declare(strict_types=1);
namespace App\Modules\Subscriptions\Application;

use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Queue\Contracts\JobStore;
use App\Modules\Subscriptions\Contracts\SubscriptionStore;
use App\Modules\Subscriptions\Domain\Subscription;
use App\Modules\Shared\Domain\Money;
use App\Support\Arr;
use App\Support\Ids;
use App\Jobs\SubscriptionRenewalJob;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use Siro\Core\Audit;
use Siro\Core\Event;

final class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionStore $subscriptions,
        private readonly JobStore $jobs,
        private readonly PaymentOperationService $payments,
        private readonly PaymentProvider $provider,
    ) {}

    /** @return array<string, mixed> */
    public function start(string $customerId, string $planId, string $key, string $actorId, string $actorRole = ''): array
    {
        $this->authorize($customerId, $actorId, $actorRole);
        if ($key === '') throw new \InvalidArgumentException('Idempotency key is required');
        return Arr::row($this->subscriptions->transaction(function () use ($customerId, $planId, $key): array {
            $existing = $this->subscriptions->attemptByKey('subscription:' . $key);
            if ($existing !== null) {
                $existingSubscription = $this->subscriptions->subscription(Arr::str($existing, 'subscription_id'));
                if ($existingSubscription === null || Arr::str($existingSubscription, 'customer_id') !== $customerId || Arr::str($existingSubscription, 'plan_id') !== $planId) throw new \InvalidArgumentException('Idempotency key already used');
                return $this->attemptResult($existing);
            }
            $plan = $this->subscriptions->plan($planId);
            if ($plan === null || Arr::str($plan, 'state', 'active') !== 'active') throw new \InvalidArgumentException('Subscription plan is unavailable');
            $interval = Arr::str($plan, 'interval'); $count = Arr::int($plan, 'interval_count', 1);
            new Money(Arr::int($plan, 'amount_minor'), strtoupper(Arr::str($plan, 'currency')));
            $trialPeriod = Arr::str($plan, 'trial_period'); $trialCount = Arr::int($plan, 'trial_period_count');
            $now = date('Y-m-d H:i:s');
            $next = $trialPeriod !== '' && $trialCount > 0 ? $this->advance($now, $trialPeriod, $trialCount) : $this->advance($now, $interval, $count); $id = Ids::new();
            $snapshot = ['plan_id' => $planId, 'name' => Arr::str($plan, 'name'), 'amount_minor' => Arr::int($plan, 'amount_minor'), 'currency' => strtoupper(Arr::str($plan, 'currency')), 'interval' => $interval, 'interval_count' => $count, 'trial_period' => $trialPeriod === '' ? null : $trialPeriod, 'trial_period_count' => $trialCount, 'provider' => Arr::str($plan, 'provider', 'cod')];
            $this->subscriptions->createSubscription(['id' => $id, 'customer_id' => $customerId, 'plan_id' => $planId, 'provider' => $snapshot['provider'], 'currency' => $snapshot['currency'], 'amount_minor' => $snapshot['amount_minor'], 'interval' => $interval, 'interval_count' => $count, 'state' => 'active', 'plan_snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'started_at' => $now, 'next_billing_at' => $next, 'created_at' => $now, 'updated_at' => $now]);
            $result = ['id' => $id, 'state' => 'active', 'next_billing_at' => $next];
            $this->subscriptions->createAttempt(['id' => Ids::new(), 'subscription_id' => $id, 'idempotency_key' => 'subscription:' . $key, 'state' => 'succeeded', 'amount_minor' => Arr::int($plan, 'amount_minor'), 'currency' => strtoupper(Arr::str($plan, 'currency')), 'provider' => Arr::str($plan, 'provider', 'cod'), 'evidence_json' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => $now, 'completed_at' => $now]);
            Audit::log('commerce.subscription.started', ['subscription_id' => $id, 'customer_id' => $customerId, 'plan_id' => $planId, 'price_snapshot' => $snapshot]);
            return $result;
        }));
    }

    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function listForCustomer(string $customerId, int $page, int $perPage, string $actorId, string $actorRole = ''): array
    {
        $this->authorize($customerId, $actorId, $actorRole);
        return $this->subscriptions->subscriptionsForCustomer($customerId, $page, $perPage);
    }

    /** @return array<string, mixed>|null */
    public function findForCustomer(string $id, string $actorId, string $actorRole = ''): ?array
    {
        $subscription = $this->subscriptions->subscription($id);
        if ($subscription === null) return null;
        $this->authorize(Arr::str($subscription, 'customer_id'), $actorId, $actorRole);
        return $subscription;
    }

    /** @return array<string, mixed> */
    public function transition(string $id, string $action, string $key, string $actorId, string $actorRole = ''): array
    {
        if ($key === '') throw new \InvalidArgumentException('Idempotency key is required');
        $row = $this->subscriptions->subscription($id);
        if ($row === null) throw new \RuntimeException('Subscription not found');
        $this->authorize(Arr::str($row, 'customer_id'), $actorId, $actorRole);
        $existing = $this->subscriptions->attemptByKey('subscription:' . $key);
        if ($existing !== null) {
            if (Arr::str($existing, 'subscription_id') !== $id) throw new \InvalidArgumentException('Idempotency key already used');
            $decodedEvidence = json_decode(Arr::str($existing, 'evidence_json', '{}'), true);
            $evidence = $decodedEvidence === null ? [] : Arr::row($decodedEvidence);
            if (Arr::str($evidence, 'action') !== $action) throw new \InvalidArgumentException('Idempotency key was reused for a different action');
            return $this->attemptResult($existing);
        }
        $state = (new Subscription($id, Arr::str($row, 'customer_id'), Arr::str($row, 'state')))->transition($action);
        $now = date('Y-m-d H:i:s');
        $changes = ['state' => $state, 'updated_at' => $now];
        if ($state === 'paused') $changes['paused_at'] = $now;
        if ($state === 'cancel_pending') $changes['cancellation_effective_at'] = Arr::str($row, 'next_billing_at', $now);
        if ($state === 'cancelled') { $changes['cancelled_at'] = $now; $changes['cancellation_effective_at'] = $now; }
        $result = Arr::row($this->subscriptions->transaction(function () use ($id, $changes, $row, $key, $state, $action, $now): array {
            $this->subscriptions->updateSubscription($id, $changes);
            $result = ['subscription_id' => $id, 'state' => $state, 'action' => $action];
            $this->subscriptions->createAttempt(['id' => Ids::new(), 'subscription_id' => $id, 'idempotency_key' => 'subscription:' . $key, 'state' => 'succeeded', 'amount_minor' => Arr::int($row, 'amount_minor'), 'currency' => Arr::str($row, 'currency'), 'provider' => Arr::str($row, 'provider'), 'evidence_json' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => $now, 'completed_at' => $now]);
            return $result;
        }));
        Event::emit('subscription.' . ($state === 'cancel_pending' ? 'cancelled' : $state), ['subscription_id' => $id, 'state' => $state]);
        return $result;
    }

    /** @return array{queued: int, skipped: int} */
    public function scheduleRenewals(string $now, int $limit): array
    {
        if ($limit < 1) throw new \InvalidArgumentException('Renewal limit must be positive');
        $queued = 0; $skipped = 0;
        foreach ($this->subscriptions->due($now, $limit) as $row) {
            $id = Arr::str($row, 'id'); $key = 'renewal:' . $id . ':' . Arr::str($row, 'next_billing_at');
            $existing = $this->subscriptions->attemptByKey($key);
            if ($id === '' || ($existing !== null && Arr::str($existing, 'state') !== 'retryable')) { $skipped++; continue; }
            try {
                if ($existing === null) $this->subscriptions->createAttempt(['id' => Ids::new(), 'subscription_id' => $id, 'idempotency_key' => $key, 'state' => 'pending', 'amount_minor' => Arr::int($row, 'amount_minor'), 'currency' => Arr::str($row, 'currency'), 'provider' => Arr::str($row, 'provider'), 'evidence_json' => json_encode(['expected_next_billing_at' => Arr::str($row, 'next_billing_at')], JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
                $this->jobs->enqueue(new SubscriptionRenewalJob($id, $key), date('Y-m-d H:i:s')); $queued++;
            } catch (\Throwable) {
                $attempt = $this->subscriptions->attemptByKey($key);
                if ($attempt !== null && Arr::str($attempt, 'state') === 'pending') $this->subscriptions->updateAttempt(Arr::str($attempt, 'id'), ['state' => 'retryable', 'last_error' => 'Renewal job could not be queued']);
                $skipped++;
            }
        }
        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /** @return array<string, mixed> Queue one renewal without performing payment in the request. */
    public function renew(string $id, string $key, string $actorId, string $actorRole = ''): array
    {
        $row = $this->subscriptions->subscription($id);
        if ($row === null) throw new \RuntimeException('Subscription not found');
        $this->authorize(Arr::str($row, 'customer_id'), $actorId, $actorRole);
        if (!in_array(Arr::str($row, 'state'), ['active', 'past_due', 'dunning'], true)) throw new \InvalidArgumentException('Subscription is not billable');
        if ($key === '') throw new \InvalidArgumentException('Idempotency key is required');
        // Dunning/retention modules may veto or pause the renewal cycle here.
        Hooks::fire(HookName::SubscriptionBeforeRenew, ['subscription_id' => $id, 'actor_id' => $actorId]);
        $existing = $this->subscriptions->attemptByKey($key);
        if ($existing !== null) {
            if (Arr::str($existing, 'subscription_id') !== $id) throw new \InvalidArgumentException('Idempotency key already used');
            return $this->attemptResult($existing);
        }
        $this->subscriptions->createAttempt(['id' => Ids::new(), 'subscription_id' => $id, 'idempotency_key' => $key, 'state' => 'pending', 'amount_minor' => Arr::int($row, 'amount_minor'), 'currency' => Arr::str($row, 'currency'), 'provider' => Arr::str($row, 'provider'), 'evidence_json' => json_encode(['expected_next_billing_at' => Arr::str($row, 'next_billing_at')], JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
        try { $this->jobs->enqueue(new SubscriptionRenewalJob($id, $key), date('Y-m-d H:i:s')); }
        catch (\Throwable $e) { $attempt = $this->subscriptions->attemptByKey($key); if ($attempt !== null) $this->subscriptions->updateAttempt(Arr::str($attempt, 'id'), ['state' => 'retryable', 'last_error' => $e->getMessage()]); throw $e; }
        return ['subscription_id' => $id, 'state' => 'pending', 'idempotency_key' => $key];
    }

    /** @return array<string, mixed> */
    public function processRenewal(string $subscriptionId, string $key): array
    {
        $attempt = $this->subscriptions->attemptByKey($key);
        $subscription = $this->subscriptions->subscription($subscriptionId);
        if ($attempt === null || $subscription === null) throw new \RuntimeException('Renewal evidence not found');
        if (Arr::str($attempt, 'state') === 'succeeded') return $this->attemptResult($attempt);
        if (Arr::str($attempt, 'state') === 'failed') return $this->attemptResult($attempt);
        if (Arr::str($subscription, 'state') === 'cancel_pending' && strtotime(Arr::str($subscription, 'cancellation_effective_at')) !== false && strtotime(Arr::str($subscription, 'cancellation_effective_at')) <= time()) {
            $this->subscriptions->updateSubscription($subscriptionId, ['state' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $this->subscriptions->updateAttempt(Arr::str($attempt, 'id'), ['state' => 'failed', 'last_error' => 'Cancellation effective before renewal']);
            throw new \InvalidArgumentException('Subscription is cancelled');
        }
        if (!in_array(Arr::str($subscription, 'state'), ['active', 'cancel_pending', 'past_due', 'dunning'], true)) {
            $this->subscriptions->updateAttempt(Arr::str($attempt, 'id'), ['state' => 'failed', 'last_error' => 'Subscription is not active']);
            throw new \InvalidArgumentException('Only active subscriptions can renew');
        }
        if (Arr::str($subscription, 'currency') !== Arr::str($attempt, 'currency') || Arr::int($subscription, 'amount_minor') !== Arr::int($attempt, 'amount_minor')) {
            $this->subscriptions->updateAttempt(Arr::str($attempt, 'id'), ['state' => 'failed', 'last_error' => 'Subscription amount or currency changed']);
            throw new \InvalidArgumentException('Subscription amount or currency changed');
        }
        $operation = new PaymentOperation(Ids::new(), Arr::str($attempt, 'id'), 'authorize', new Money(Arr::int($attempt, 'amount_minor'), Arr::str($attempt, 'currency')), $key);
        $result = $this->payments->execute($operation, $this->provider, ['provider' => Arr::str($attempt, 'provider'), 'subscription_id' => $subscriptionId, 'renewal_period' => Arr::str($subscription, 'next_billing_at')]);
        $data = is_array($result->value) ? Arr::row($result->value) : [];
        $operationResult = $this->payments->existing($key);
        $operationState = is_array($operationResult) ? Arr::str($operationResult, 'state') : '';
        $providerState = Arr::str($data, 'state', $operationState);
        $state = $providerState === 'unknown' ? 'retryable' : (($result->ok || $providerState === 'authorized') ? 'succeeded' : 'failed');
        if ($state !== 'succeeded' && $data === []) $data = ['state' => $state, 'error' => ($result->message ?? 'Payment operation failed')];
        $this->subscriptions->transaction(function () use ($attempt, $subscription, $subscriptionId, $state, $operation, $data): void {
            $this->subscriptions->updateAttempt(Arr::str($attempt, 'id'), ['state' => $state, 'payment_operation_id' => $operation->id, 'evidence_json' => json_encode($data, JSON_THROW_ON_ERROR), 'last_error' => Arr::nullableStr($data, 'error'), 'completed_at' => $state === 'succeeded' ? date('Y-m-d H:i:s') : null]);
            if ($state === 'succeeded') {
                $next = $this->advance(Arr::str($subscription, 'next_billing_at'), Arr::str($subscription, 'interval', 'month'), Arr::int($subscription, 'interval_count', 1));
                $changes = ['next_billing_at' => $next, 'updated_at' => date('Y-m-d H:i:s')];
                if (Arr::str($subscription, 'state') === 'past_due' || Arr::str($subscription, 'state') === 'dunning') $changes['state'] = 'active';
                $this->subscriptions->updateSubscription($subscriptionId, $changes);
            } elseif ($state === 'failed') {
                $this->subscriptions->updateSubscription($subscriptionId, ['state' => 'past_due', 'dunning_until' => $this->advance(date('Y-m-d H:i:s'), 'day', 7), 'updated_at' => date('Y-m-d H:i:s')]);
            } elseif ($state === 'retryable') {
                $this->subscriptions->updateSubscription($subscriptionId, ['state' => 'dunning', 'updated_at' => date('Y-m-d H:i:s')]);
            }
        });
        if ($state === 'retryable') throw new \RuntimeException('Renewal payment is retryable');
        return ['state' => $state, 'attempt_id' => Arr::str($attempt, 'id'), 'payment_operation_id' => $operation->id];
    }

    /** Apply verified provider state without accepting provider-supplied money values. */
    public function reconcileWebhook(string $subscriptionId, string $providerState, string $eventId): void
    {
        if ($subscriptionId === '' || $eventId === '') throw new \InvalidArgumentException('Subscription webhook evidence is incomplete');
        if ($this->subscriptions->subscription($subscriptionId) === null) throw new \RuntimeException('Subscription not found for webhook');
        $state = match ($providerState) {
            'authorized', 'captured', 'succeeded', 'paid' => 'active',
            'failed', 'declined' => 'past_due',
            default => null,
        };
        if ($state === null) return;
        $this->subscriptions->updateSubscription($subscriptionId, ['state' => $state, 'updated_at' => date('Y-m-d H:i:s')]);
        Audit::log('commerce.subscription.webhook_reconciled', ['subscription_id' => $subscriptionId, 'provider_state' => $providerState, 'event_id' => $eventId, 'result_state' => $state]);
    }

    /**
     * @param array<string, mixed> $attempt
     * @return array<string, mixed>
     */
    private function attemptResult(array $attempt): array
    {
        $evidence = json_decode(Arr::str($attempt, 'evidence_json'), true);
        $result = is_array($evidence) ? Arr::row($evidence) : [];
        foreach (['id', 'subscription_id', 'state', 'next_billing_at', 'idempotency_key'] as $key) {
            if (!array_key_exists($key, $result) && array_key_exists($key, $attempt)) $result[$key] = $attempt[$key];
        }
        $safe = [];
        foreach (['id', 'subscription_id', 'state', 'next_billing_at', 'idempotency_key'] as $key) {
            if (array_key_exists($key, $result)) $safe[$key] = $result[$key];
        }
        return $safe;
    }

    private function authorize(string $customerId, string $actorId, string $role): void
    { if ($customerId === '' || ($customerId !== $actorId && !in_array($role, ['admin', 'owner', 'finance'], true))) throw new \RuntimeException('Forbidden'); }

    private function advance(string $from, string $interval, int $count): string
    {
        $unit = match ($interval) {
            'weekly', 'week' => 'week',
            'monthly', 'month' => 'month',
            'yearly', 'year' => 'year',
            'daily', 'day' => 'day',
            default => throw new \InvalidArgumentException('Invalid billing interval'),
        };
        $value = '+' . $count . ' ' . $unit . ($count === 1 ? '' : 's');
        $next = strtotime($from . ' ' . $value);
        if ($next === false) throw new \InvalidArgumentException('Invalid billing interval');
        return date('Y-m-d H:i:s', $next);
    }
}
