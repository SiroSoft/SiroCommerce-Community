<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Queue\Contracts\Job;
use App\Modules\Queue\Contracts\JobStore;
use App\Modules\Webhook\Application\WebhookReceiver;
use App\Modules\Webhook\Contracts\WebhookEventStore;
use PHPUnit\Framework\TestCase;

final class PlatformOperationsTest extends TestCase
{
    public function testVerifiedWebhookIsQueuedOnceAndRetriesAreBounded(): void
    {
        $events = new class implements WebhookEventStore {
            /** @var array<string, array<string, mixed>> */
            public array $rows = [];
            public function find(string $provider, string $eventId): ?array { return $this->rows[$provider . ':' . $eventId] ?? null; }
            public function receive(string $provider, string $eventId, string $payloadHash, bool $signatureOk, ?string $payload = null): void { $this->rows[$provider . ':' . $eventId] = ['state' => 'received', 'payload_hash' => $payloadHash, 'payload_json' => $payload]; }
            public function markQueued(string $provider, string $eventId): void { $this->rows[$provider . ':' . $eventId]['state'] = 'queued'; }
            public function claimWebhook(string $provider, string $eventId): bool { $key = $provider . ':' . $eventId; if (($this->rows[$key]['state'] ?? null) !== 'queued') return false; $this->rows[$key]['state'] = 'processing'; return true; }
            public function markProcessed(string $provider, string $eventId, string $result): void {}
        };
        $jobs = new class implements JobStore {
            /** @var list<Job> */
            public array $jobs = [];
            public function enqueue(Job $job, string $availableAt): void { $this->jobs[] = $job; }
            public function claim(string $queue, string $workerId, string $leaseUntil): ?array { return null; }
            public function completeJob(string $jobId): void {}
            public function fail(string $jobId, string $error, string $retryAt): void {}
            public function deadLetter(string $jobId, string $reason): void {}
        };
        $receiver = new WebhookReceiver($events, $jobs);
        $raw = '{"event_id":"evt-1"}';

        self::assertSame(['accepted' => true, 'duplicate' => false, 'event_id' => 'evt-1'], $receiver->receive('stripe', 'evt-1', $raw, true));
        self::assertSame(['accepted' => true, 'duplicate' => true, 'event_id' => 'evt-1'], $receiver->receive('stripe', 'evt-1', $raw, true));
        self::assertCount(1, $jobs->jobs);
        self::assertSame('webhook:stripe:evt-1', $jobs->jobs[0]->idempotencyKey());
        self::assertSame(5, $jobs->jobs[0]->maxAttempts());
    }

    public function testWebhookReplayRequiresTheSameRawBodyAndStoresRedactedEvidence(): void
    {
        $events = new class implements WebhookEventStore {
            /** @var array<string, array<string, mixed>> */
            public array $rows = [];
            public function find(string $provider, string $eventId): ?array { return $this->rows[$provider . ':' . $eventId] ?? null; }
            public function receive(string $provider, string $eventId, string $payloadHash, bool $signatureOk, ?string $payload = null): void { $this->rows[$provider . ':' . $eventId] = ['state' => 'received', 'payload_hash' => $payloadHash, 'payload_json' => $payload]; }
            public function markQueued(string $provider, string $eventId): void { $this->rows[$provider . ':' . $eventId]['state'] = 'queued'; }
            public function claimWebhook(string $provider, string $eventId): bool { return false; }
            public function markProcessed(string $provider, string $eventId, string $result): void {}
        };
        $jobs = $this->createMock(JobStore::class);
        $jobs->expects(self::once())->method('enqueue');
        $receiver = new WebhookReceiver($events, $jobs);
        $raw = '{"event_id":"evt-2","token":"do-not-store"}';

        $receiver->receive('stripe', 'evt-2', $raw, true);
        self::assertSame(hash('sha256', $raw), $events->rows['stripe:evt-2']['payload_hash']);
        self::assertStringNotContainsString('do-not-store', (string) $events->rows['stripe:evt-2']['payload_json']);
        $this->expectException(
            \InvalidArgumentException::class
        );
        $receiver->receive('stripe', 'evt-2', '{"event_id":"evt-2","token":"changed"}', true);
    }
}
