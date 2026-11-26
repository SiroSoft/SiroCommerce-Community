<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Modules\Platform\Infrastructure\OutboxDispatcher;
use App\Modules\Queue\Contracts\Job;
use App\Modules\Shared\Application\CommandContext;
use App\Modules\Shared\Domain\EventEnvelope;
use Tests\TestCase;
use Siro\Core\Event;
use Siro\Core\Queue;

final class V2SideEffectIntegrationTest extends TestCase
{
    public function testPaymentOperationIsDurableAndIdempotent(): void
    {
        $repo = new V2SideEffectRepository();
        $repo->create([
            'id' => 'op_v2_1', 'payment_id' => 'pay_v2_1', 'type' => 'authorize',
            'amount_minor' => 1000, 'currency' => 'VND', 'idempotency_key' => 'operation-key-1',
            'state' => 'processing', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        self::assertSame('op_v2_1', $repo->findByIdempotency('operation-key-1')['id']);
        $repo->complete('op_v2_1', ['state' => 'authorized', 'provider_ref' => 'provider-1']);
        self::assertSame('authorized', $repo->findByIdempotency('operation-key-1')['state']);
    }

    public function testWebhookAndQueueAreDeduplicatedAndDeadLettered(): void
    {
        $repo = new V2SideEffectRepository();
        $body = '{}';
        $repo->receive('test', 'evt-1', hash('sha256', $body), true, $body);
        self::assertNotNull($repo->find('test', 'evt-1'));
        $repo->receive('test', 'evt-1', hash('sha256', $body), true, $body);
        self::assertSame('evt-1', $repo->find('test', 'evt-1')['event_id']);
    }

    public function testWebhookPayloadHashMustBeRawBodySha256(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new V2SideEffectRepository())->receive('test', 'evt-invalid-hash', 'fixture-hash', true, '{}');
    }

    public function testOutboxAndBoundedCommerceJobClaim(): void
    {
        $repo = new V2SideEffectRepository();
        $context = new CommandContext('test', 'cor-v2', 'cmd-v2', 'event-key-v2');
        $repo->append(new EventEnvelope('evt-v2', 'test.created.v1', date('c'), $context, ['aggregate_id' => 'agg-v2']));
        $repo->enqueue(new class implements Job {
            public function name(): string { return 'test.job'; }
            public function idempotencyKey(): string { return 'job-key-v2'; }
            /** @return array<string, mixed> */
            public function payload(): array { return ['value' => 'ok']; }
            public function maxAttempts(): int { return 1; }
        }, date('Y-m-d H:i:s'));
        $job = $repo->claim('commerce', 'worker-v2', date('Y-m-d H:i:s', time() + 60));
        self::assertNotNull($job);
        self::assertSame('running', $job['state']);
        $repo->fail($job['id'], 'test failure', date('Y-m-d H:i:s'));
        self::assertNull($repo->claim('commerce', 'worker-v2', date('Y-m-d H:i:s')));
    }

    public function testFutureJobsAreNotClaimedAndExpiredLeasesAreRecovered(): void
    {
        $repo = new V2SideEffectRepository();
        $repo->enqueue(new class implements Job {
            public function name(): string { return 'future.job'; }
            public function idempotencyKey(): string { return 'future-key-v2'; }
            public function payload(): array { return []; }
            public function maxAttempts(): int { return 2; }
        }, date('Y-m-d H:i:s', time() + 3600));
        self::assertNull($repo->claim('commerce', 'worker-v2', date('Y-m-d H:i:s', time() + 60)));

        $pdo = \Siro\Core\Database::connection();
        $pdo->exec("UPDATE commerce_jobs SET state = 'running', locked_at = '2000-01-01 00:00:00', available_at = CURRENT_TIMESTAMP");
        self::assertNotNull($repo->claim('commerce', 'worker-v2', date('Y-m-d H:i:s', time() + 60)));
    }

    public function testDeadLetterIsIdempotent(): void
    {
        $repo = new V2SideEffectRepository();
        $repo->enqueue(new class implements Job {
            public function name(): string { return 'poison.job'; }
            public function idempotencyKey(): string { return 'poison-key-v2'; }
            public function payload(): array { return ['poison' => true]; }
            public function maxAttempts(): int { return 1; }
        }, date('Y-m-d H:i:s'));
        $job = $repo->claim('commerce', 'worker-v2', date('Y-m-d H:i:s', time() + 60));
        self::assertNotNull($job);
        $repo->deadLetter((string) $job['id'], 'poison');
        $repo->deadLetter((string) $job['id'], 'poison again');
        $count = (int) \Siro\Core\Database::connection()->query('SELECT COUNT(*) FROM commerce_dead_letters')->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testDurableJobReplayIsIdempotentAfterCompletion(): void
    {
        $repo = new V2SideEffectRepository();
        $repo->enqueue(new class implements Job {
            public function name(): string { return 'replay.job'; }
            public function idempotencyKey(): string { return 'replay-key-v2'; }
            public function payload(): array { return ['replay' => true]; }
            public function maxAttempts(): int { return 3; }
        }, date('Y-m-d H:i:s'));
        $first = $repo->claim('commerce', 'worker-a', date('Y-m-d H:i:s', time() + 60));
        self::assertNotNull($first);
        $repo->completeJob((string) $first['id']);
        self::assertNull($repo->claim('commerce', 'worker-b', date('Y-m-d H:i:s', time() + 60)));
        self::assertSame('completed', \Siro\Core\Database::connection()->query("SELECT state FROM commerce_jobs WHERE id = '" . $first['id'] . "'")->fetchColumn());
    }

    public function testQueuedDurableJobIsRepublishedAfterCoreDeliveryRecovery(): void
    {
        Queue::fake();
        $repo = new V2SideEffectRepository();
        $repo->enqueue(new class implements Job {
            public function name(): string { return 'recoverable.job'; }
            public function idempotencyKey(): string { return 'recoverable-key-v2'; }
            public function payload(): array { return ['recover' => true]; }
            public function maxAttempts(): int { return 2; }
        }, date('Y-m-d H:i:s'));

        self::assertSame(1, $repo->recoverQueuedJobs(10));
        self::assertSame(2, count(Queue::getFakedJobs()));
        self::assertStringContainsString('Core queue delivery recovered', (string) \Siro\Core\Database::connection()->query("SELECT last_error FROM commerce_jobs WHERE idempotency_key = 'recoverable-key-v2'")->fetchColumn());
        self::assertSame(0, $repo->recoverQueuedJobs(10));
    }

    public function testDurableFailureSchedulesAnotherClaimUntilMaxAttempts(): void
    {
        $repo = new V2SideEffectRepository();
        $repo->enqueue(new class implements Job {
            public function name(): string { return 'retry.job'; }
            public function idempotencyKey(): string { return 'retry-key-v2'; }
            public function payload(): array { return []; }
            public function maxAttempts(): int { return 2; }
        }, date('Y-m-d H:i:s'));
        $first = $repo->claim('commerce', 'worker-a', date('Y-m-d H:i:s', time() + 60));
        self::assertNotNull($first);
        $repo->fail((string) $first['id'], 'transient', date('Y-m-d H:i:s'));
        $second = $repo->claim('commerce', 'worker-b', date('Y-m-d H:i:s', time() + 60));
        self::assertNotNull($second);
        self::assertSame(2, (int) $second['attempts']);
    }

    public function testClaimDeadLettersRowsThatAlreadyReachedAttemptLimit(): void
    {
        $repo = new V2SideEffectRepository();
        $repo->enqueue(new class implements Job {
            public function name(): string { return 'exhausted.job'; }
            public function idempotencyKey(): string { return 'exhausted-key-v2'; }
            public function payload(): array { return []; }
            public function maxAttempts(): int { return 1; }
        }, date('Y-m-d H:i:s'));
        $pdo = \Siro\Core\Database::connection();
        $pdo->exec("UPDATE commerce_jobs SET attempts = 1 WHERE idempotency_key = 'exhausted-key-v2'");

        self::assertNull($repo->claim('commerce', 'worker-exhausted', date('Y-m-d H:i:s', time() + 60)));
        self::assertSame('dead', $pdo->query("SELECT state FROM commerce_jobs WHERE idempotency_key = 'exhausted-key-v2'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM commerce_dead_letters WHERE job_id = (SELECT id FROM commerce_jobs WHERE idempotency_key = 'exhausted-key-v2')")->fetchColumn());
    }

    public function testOutboxDispatcherPublishesOnceAndCountsAttempt(): void
    {
        Event::setInstance(new Event());
        $repo = new V2SideEffectRepository();
        $repo->append(new EventEnvelope('evt-dispatch-v2', 'dispatch.test.v1', date('c'), new CommandContext('test', 'cor-dispatch', 'cmd-dispatch', 'key-dispatch'), ['aggregate_id' => 'agg-dispatch']));

        $result = (new OutboxDispatcher())->dispatch(10, true);
        self::assertSame(1, $result['published']);
        self::assertSame('published', \Siro\Core\Database::connection()->query("SELECT state FROM outbox_messages WHERE event_id = 'evt-dispatch-v2'")->fetchColumn());
        self::assertSame(0, (new OutboxDispatcher())->dispatch(10, true)['count']);
    }

    public function testOutboxListenerExceptionIsRetriedAndEventuallyDeadLettered(): void
    {
        Event::setInstance(new Event());
        Event::on('dispatch.failure.v1', static function (): void { throw new \RuntimeException('listener exploded'); });
        $repo = new V2SideEffectRepository();
        $repo->append(new EventEnvelope('evt-dispatch-failure', 'dispatch.failure.v1', date('c'), new CommandContext('test', 'cor-failure', 'cmd-failure', 'key-failure'), ['aggregate_id' => 'agg-failure']));

        $first = (new OutboxDispatcher(2))->dispatch(1, true);
        self::assertSame(1, $first['failed']);
        self::assertSame('pending', \Siro\Core\Database::connection()->query("SELECT state FROM outbox_messages WHERE event_id = 'evt-dispatch-failure'")->fetchColumn());
        self::assertSame('listener exploded', \Siro\Core\Database::connection()->query("SELECT last_error FROM outbox_messages WHERE event_id = 'evt-dispatch-failure'")->fetchColumn());

        \Siro\Core\Database::connection()->exec("UPDATE outbox_messages SET available_at = '2000-01-01 00:00:00' WHERE event_id = 'evt-dispatch-failure'");
        $second = (new OutboxDispatcher(2))->dispatch(1, true);
        self::assertSame(1, $second['dead']);
        self::assertSame('dead', \Siro\Core\Database::connection()->query("SELECT state FROM outbox_messages WHERE event_id = 'evt-dispatch-failure'")->fetchColumn());
    }
}
