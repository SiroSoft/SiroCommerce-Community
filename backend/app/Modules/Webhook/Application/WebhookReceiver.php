<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Application;

use App\Modules\Queue\Contracts\Job;
use App\Modules\Queue\Contracts\JobStore;
use App\Modules\Webhook\Contracts\WebhookEventStore;
use App\Support\Arr;
use App\Modules\Shared\Contracts\TransactionRunner;
use App\Support\ProviderEvidence;

/** Verify-and-queue boundary; business processing happens in a job. */
final class WebhookReceiver
{
    public function __construct(
        private readonly WebhookEventStore $events,
        private readonly JobStore $jobs,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return array{accepted: bool, duplicate: bool, event_id: string}
     */
    public function receive(string $provider, string $eventId, string $rawBody, bool $signatureOk, array $headers = []): array
    {
        if (trim($provider) === '' || trim($eventId) === '') {
            throw new \InvalidArgumentException('Webhook provider and event_id are required');
        }
        if (!$signatureOk) {
            throw new \InvalidArgumentException('Invalid webhook signature');
        }
        $payloadHash = hash('sha256', $rawBody);
        $existing = $this->events->find($provider, $eventId);
        $duplicate = $existing !== null;
        if ($existing !== null && !hash_equals(Arr::str($existing, 'payload_hash'), $payloadHash)) {
            throw new \InvalidArgumentException('Webhook event payload does not match the original event');
        }
        $safeBody = $this->redactBody($rawBody);
        $work = function () use ($provider, $eventId, $safeBody, $payloadHash): void {
            $existing = $this->events->find($provider, $eventId);
            $body = $existing === null ? $safeBody : Arr::str($existing, 'payload_json', $safeBody);
            if ($existing === null) $this->events->receive($provider, $eventId, $payloadHash, true, $safeBody);
            if ($existing === null || in_array(Arr::str($existing, 'state'), ['received', 'failed'], true)) {
                $this->enqueue($provider, $eventId, $body);
                $this->events->markQueued($provider, $eventId);
            }
        };
        if ($this->events instanceof TransactionRunner) $this->events->transaction($work);
        else $work();
        return ['accepted' => true, 'duplicate' => $duplicate, 'event_id' => $eventId];
    }

    private function redactBody(string $rawBody): string
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) return '[redacted-invalid-provider-payload]';
        /** @var array<string, mixed> $decoded */
        return (string) json_encode(ProviderEvidence::redact($decoded), JSON_UNESCAPED_SLASHES);
    }

    private function enqueue(string $provider, string $eventId, string $rawBody): void
    {
        $this->jobs->enqueue(new class($provider, $eventId, $rawBody) implements Job {
            public function __construct(private string $provider, private string $eventId, private string $rawBody) {}
            public function name(): string { return 'webhook.process'; }
            public function idempotencyKey(): string { return 'webhook:' . $this->provider . ':' . $this->eventId; }
            /** @return array<string, mixed> */
            public function payload(): array { return ['provider' => $this->provider, 'event_id' => $this->eventId, 'body' => $this->rawBody]; }
            public function maxAttempts(): int { return 5; }
        }, date('Y-m-d H:i:s'));
    }
}
