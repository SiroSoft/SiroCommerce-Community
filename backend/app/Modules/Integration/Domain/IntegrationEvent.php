<?php

declare(strict_types=1);

namespace App\Modules\Integration\Domain;

/** Immutable external event boundary. Payloads are already redacted before construction. */
final readonly class IntegrationEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $id,
        public string $name,
        public int $schemaVersion,
        public string $occurredAt,
        public string $correlationId,
        public array $payload,
        public string $scopeKey,
    ) {
        if (preg_match('/^[a-z0-9_.-]+\.v[0-9]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('Integration events must use a versioned name.');
        }
        if ($schemaVersion < 1 || $id === '' || $scopeKey === '') {
            throw new \InvalidArgumentException('Integration event identity and schema are required.');
        }
    }

    /** @return array<string,mixed> */
    public function envelope(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt,
            'correlation_id' => $this->correlationId,
            'scope' => $this->scopeKey,
            'payload' => $this->payload,
        ];
    }
}
