<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application;

/** Context propagated through every command and side effect. */
final readonly class CommandContext
{
    public function __construct(
        public string $actor,
        public string $correlationId,
        public string $causationId,
        public string $idempotencyKey,
        public string $source = 'api',
    ) {
        if ($this->correlationId === '' || $this->causationId === '') {
            throw new \InvalidArgumentException('Correlation and causation IDs are required');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'actor' => $this->actor,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'idempotency_key' => $this->idempotencyKey,
            'source' => $this->source,
        ];
    }
}
