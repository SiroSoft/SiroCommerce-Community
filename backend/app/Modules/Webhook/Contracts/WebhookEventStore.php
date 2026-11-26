<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Contracts;

interface WebhookEventStore
{
    /** @return array<string, mixed>|null */
    public function find(string $provider, string $eventId): ?array;

    /**
     * $payloadHash is the SHA-256 hex digest of the exact verified raw body.
     * $payload is safe evidence and may be redacted; it is not hashed.
     */
    public function receive(string $provider, string $eventId, string $payloadHash, bool $signatureOk, ?string $payload = null): void;

    public function markQueued(string $provider, string $eventId): void;

    public function claimWebhook(string $provider, string $eventId): bool;

    public function markProcessed(string $provider, string $eventId, string $result): void;
}
