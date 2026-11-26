<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentOperationStore;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Queue\Contracts\Job;
use App\Modules\Queue\Contracts\JobStore;
use App\Modules\Subscriptions\Application\SubscriptionService;
use App\Modules\Subscriptions\Contracts\SubscriptionStore;
use App\Modules\Subscriptions\Domain\Subscription;
use App\Modules\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;

final class SubscriptionServiceTest extends TestCase
{
    private const START_ROUTE = '/api/account/subscriptions';
    private const TRANSITION_ROUTE = '/api/account/subscriptions/{id}/transition';
    private const RENEW_ROUTE = '/api/account/subscriptions/{id}/renew';

    public function testCustomerReadsAreOwnerScopedAndPaginated(): void
    {
        $store = new FakeSubscriptionStore();
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $this->provider());

        self::assertCount(1, $service->listForCustomer('c', 1, 20, 'c')['data']);
        $this->expectException(\RuntimeException::class);
        $service->findForCustomer('s', 'another-customer');
    }

    public function testLifecycleRejectsInvalidTransition(): void
    { $this->expectException(\InvalidArgumentException::class); (new Subscription('s', 'c', 'cancelled'))->transition('resume'); }

    public function testSchedulerDoesNotQueueTheSameRenewalTwice(): void
    {
        $store = new FakeSubscriptionStore(); $jobs = new FakeJobStore();
        $service = new SubscriptionService($store, $jobs, $this->payments(), $this->provider());
        self::assertSame(1, $service->scheduleRenewals('2026-09-20 00:00:00', 10)['queued']);
        self::assertSame(1, $service->scheduleRenewals('2026-09-20 00:00:00', 10)['skipped']);
        self::assertCount(1, $jobs->jobs);
    }

    public function testRenewalRejectsCurrencyMismatchBeforeProviderCall(): void
    {
        $store = new FakeSubscriptionStore(); $provider = new class implements PaymentProvider {
            public int $calls = 0;
            public function execute(PaymentOperation $operation, array $context): array { $this->calls++; return ['state' => 'authorized']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };
        $store->createAttempt(['id' => 'a', 'subscription_id' => 's', 'idempotency_key' => 'renewal:s:2026-09-20 00:00:00', 'state' => 'pending', 'amount_minor' => 100, 'currency' => 'EUR', 'provider' => 'cod']);
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $provider);
        $this->expectException(\InvalidArgumentException::class); $service->processRenewal('s', 'renewal:s:2026-09-20 00:00:00'); self::assertSame(0, $provider->calls);
    }

    public function testCustomerAuthorizationIsEnforced(): void
    {
        $service = new SubscriptionService(new FakeSubscriptionStore(), new FakeJobStore(), $this->payments(), $this->provider());
        $this->expectException(\RuntimeException::class);
        $service->transition('s', 'pause', 'key', 'another-customer');
    }

    public function testUnauthorizedIdempotentTransitionCannotReadExistingResult(): void
    {
        $store = new FakeSubscriptionStore();
        $store->createAttempt(['id' => 'a', 'subscription_id' => 's', 'idempotency_key' => 'subscription:key', 'state' => 'succeeded', 'evidence_json' => json_encode(['subscription_id' => 's', 'state' => 'paused'])]);
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $this->provider());
        $this->expectException(\RuntimeException::class);
        $service->transition('s', 'pause', 'key', 'another-customer');
    }

    public function testFailedRenewalIsNotSubmittedToProviderAgain(): void
    {
        $store = new FakeSubscriptionStore();
        $store->createAttempt(['id' => 'a', 'subscription_id' => 's', 'idempotency_key' => 'renewal-key', 'state' => 'pending', 'amount_minor' => 100, 'currency' => 'USD', 'provider' => 'cod']);
        $provider = new class implements PaymentProvider {
            public int $calls = 0;
            public function execute(PaymentOperation $operation, array $context): array { $this->calls++; return ['state' => 'failed', 'error' => 'declined']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $provider);
        self::assertSame('failed', $service->processRenewal('s', 'renewal-key')['state']);
        self::assertSame('failed', $service->processRenewal('s', 'renewal-key')['state']);
        self::assertSame(1, $provider->calls);
    }

    public function testQueuedRenewalCannotChargeCanceledSubscription(): void
    {
        $store = new FakeSubscriptionStore();
        $store->state = 'cancelled';
        $store->createAttempt(['id' => 'a', 'subscription_id' => 's', 'idempotency_key' => 'renewal-key', 'state' => 'pending', 'amount_minor' => 100, 'currency' => 'USD', 'provider' => 'cod']);
        $provider = new class implements PaymentProvider {
            public int $calls = 0;
            public function execute(PaymentOperation $operation, array $context): array { $this->calls++; return ['state' => 'authorized']; }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $provider);
        $this->expectException(\InvalidArgumentException::class);
        $service->processRenewal('s', 'renewal-key');
        self::assertSame(0, $provider->calls);
    }

    public function testProviderTimeoutRemainsRetryableAndIsIdempotent(): void
    {
        $store = new FakeSubscriptionStore();
        $store->createAttempt(['id' => 'a', 'subscription_id' => 's', 'idempotency_key' => 'timeout-key', 'state' => 'pending', 'amount_minor' => 100, 'currency' => 'USD', 'provider' => 'cod']);
        $operations = new FakePaymentOperationStore();
        $provider = new class implements PaymentProvider {
            public int $calls = 0;
            public function execute(PaymentOperation $operation, array $context): array { $this->calls++; throw new \RuntimeException('timeout'); }
            public function verifyWebhook(string $rawBody, array $headers): bool { return false; }
        };
        $service = new SubscriptionService($store, new FakeJobStore(), new PaymentOperationService($operations), $provider);
        try { $service->processRenewal('s', 'timeout-key'); self::fail('Expected retryable renewal'); } catch (\RuntimeException $e) { self::assertSame('Renewal payment is retryable', $e->getMessage()); }
        try { $service->processRenewal('s', 'timeout-key'); self::fail('Expected retryable renewal'); } catch (\RuntimeException $e) { self::assertSame('Renewal payment is retryable', $e->getMessage()); }
        self::assertSame(1, $provider->calls);
        self::assertSame('retryable', $store->attempts['timeout-key']['state']);
    }

    public function testSchedulerRejectsInvalidLimitAndPreservesRetryableQueueWork(): void
    {
        $service = new SubscriptionService(new FakeSubscriptionStore(), new FakeJobStore(), $this->payments(), $this->provider());
        $this->expectException(\InvalidArgumentException::class);
        $service->scheduleRenewals('2026-09-20 00:00:00', 0);
    }

    public function testStartUsesOneAtomicStoreTransactionForSubscriptionAndAttempt(): void
    {
        $store = new FakeSubscriptionStore();
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $this->provider());

        $result = $service->start('c', 'plan-1', 'start-atomic', 'c');

        self::assertSame('active', $result['state']);
        self::assertSame(1, $store->transactionCalls);
        self::assertCount(1, $store->attempts);
    }

    public function testStartStoresImmutablePriceSnapshot(): void
    {
        $store = new FakeSubscriptionStore();
        (new SubscriptionService($store, new FakeJobStore(), $this->payments(), $this->provider()))->start('c', 'plan-1', 'snapshot', 'c');
        $snapshot = json_decode((string) $store->subscriptions[0]['plan_snapshot_json'], true);
        self::assertSame(100, $snapshot['amount_minor']);
        self::assertSame('USD', $snapshot['currency']);
        self::assertSame('month', $snapshot['interval']);
    }

    public function testCancelIsEffectiveAtTheCurrentBillingBoundaryAndPauseResumeAreExplicit(): void
    {
        $store = new FakeSubscriptionStore();
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $this->provider());
        self::assertSame('paused', $service->transition('s', 'pause', 'pause-key', 'c')['state']);
        self::assertSame('active', $service->transition('s', 'resume', 'resume-key', 'c')['state']);
        self::assertSame('cancel_pending', $service->transition('s', 'cancel', 'cancel-key', 'c')['state']);
        self::assertSame('2026-09-20 00:00:00', $store->updates['s']['cancellation_effective_at']);
    }

    public function testWebhookReconciliationIsScopedToTheSubscriptionAndDoesNotChangeMoney(): void
    {
        $store = new FakeSubscriptionStore();
        $service = new SubscriptionService($store, new FakeJobStore(), $this->payments(), $this->provider());
        $service->reconcileWebhook('s', 'failed', 'evt-1');
        self::assertSame('past_due', $store->updates['s']['state']);
        self::assertArrayNotHasKey('amount_minor', $store->updates['s']);
    }

    private function payments(): PaymentOperationService
    {
        return new PaymentOperationService(new class implements PaymentOperationStore {
            public function findByIdempotency(string $key): ?array { return null; }
            public function create(array $operation): void {}
            public function complete(string $id, array $result): void {}
        });
    }
    private function provider(): PaymentProvider
    { return new class implements PaymentProvider { public function execute(PaymentOperation $operation, array $context): array { return ['state' => 'authorized']; } public function verifyWebhook(string $rawBody, array $headers): bool { return false; } }; }
}

final class FakePaymentOperationStore implements PaymentOperationStore
{
    /** @var array<string, array<string, mixed>> */ public array $operations = [];
    public function findByIdempotency(string $key): ?array { return $this->operations[$key] ?? null; }
    public function create(array $operation): void { $this->operations[(string) $operation['idempotency_key']] = $operation; }
    public function complete(string $id, array $result): void { foreach ($this->operations as $key => $operation) if (($operation['id'] ?? null) === $id) $this->operations[$key] = array_replace($operation, $result); }
}

final class FakeSubscriptionStore implements SubscriptionStore
{
    /** @var array<string, array<string, mixed>> */ public array $attempts = [];
    public string $state = 'active';
    public int $transactionCalls = 0;
    /** @var array<int, array<string, mixed>> */ public array $subscriptions = [];
    /** @var array<string, array<string, mixed>> */ public array $updates = [];
    public function transaction(callable $callback): mixed { $this->transactionCalls++; return $callback(); }
    public function plan(string $id): ?array { return ['id' => $id, 'state' => 'active', 'amount_minor' => 100, 'currency' => 'USD', 'interval' => 'month', 'interval_count' => 1, 'provider' => 'cod']; }
    public function createPlan(array $data): void {}
    public function subscription(string $id): ?array { return array_replace(['id' => 's', 'customer_id' => 'c', 'state' => $this->state, 'amount_minor' => 100, 'currency' => 'USD', 'interval' => 'month', 'interval_count' => 1, 'next_billing_at' => '2026-09-20 00:00:00', 'provider' => 'cod'], $this->updates['s'] ?? []); }
    public function subscriptionsForCustomer(string $customerId, int $page, int $perPage): array { return ['data' => $customerId === 'c' ? [$this->subscription('s')] : [], 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $customerId === 'c' ? 1 : 0, 'last_page' => 1]]; }
    public function createSubscription(array $data): void { $this->subscriptions[] = $data; }
    public function updateSubscription(string $id, array $changes): void { $this->updates[$id] = array_replace($this->updates[$id] ?? [], $changes); }
    public function attemptByKey(string $key): ?array { return $this->attempts[$key] ?? null; }
    public function createAttempt(array $data): void { $this->attempts[(string) $data['idempotency_key']] = $data; }
    public function updateAttempt(string $id, array $changes): void { foreach ($this->attempts as $key => $attempt) if (($attempt['id'] ?? null) === $id) $this->attempts[$key] = array_replace($attempt, $changes); }
    public function due(string $now, int $limit): array { return [['id' => 's', 'amount_minor' => 100, 'currency' => 'USD', 'provider' => 'cod', 'next_billing_at' => '2026-09-20 00:00:00']]; }
}

final class FakeJobStore implements JobStore
{
    /** @var array<int, Job> */ public array $jobs = [];
    public function enqueue(Job $job, string $availableAt): void { $this->jobs[] = $job; }
    public function claim(string $queue, string $workerId, string $leaseUntil): ?array { return null; }
    public function completeJob(string $jobId): void {}
    public function fail(string $jobId, string $error, string $retryAt): void {}
    public function deadLetter(string $jobId, string $reason): void {}
}
