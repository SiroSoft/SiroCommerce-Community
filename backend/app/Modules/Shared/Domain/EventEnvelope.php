<?php
declare(strict_types=1);
namespace App\Modules\Shared\Domain;

use App\Modules\Shared\Application\CommandContext;

final readonly class EventEnvelope implements DomainEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $redaction
     * @param string|array<string, mixed> $causationId
     */
    public function __construct(
        public string $eventId,
        public string $name,
        public string $occurredAt,
        string|CommandContext $correlationId,
        string|array $causationId,
        ?string $actor = null,
        ?array $payload = null,
        public int $schemaVersion = 1,
        public array $redaction = [],
    )
    {
        if ($correlationId instanceof CommandContext) {
            $this->commandContext = $correlationId;
            $this->correlationId = $correlationId->correlationId;
            $this->causationId = $correlationId->causationId;
            $this->actor = $correlationId->actor;
            /** @var array<string, mixed> $legacyPayload */
            $legacyPayload = $causationId;
            $this->payload = $legacyPayload;
        } else {
            $this->commandContext = new CommandContext($actor ?? 'system', $correlationId, is_string($causationId) && $causationId !== '' ? $causationId : $eventId, $eventId, 'event');
            $this->correlationId = $correlationId;
            $this->causationId = is_array($causationId) ? '' : $causationId;
            $this->actor = $actor ?? 'system';
            $this->payload = $payload ?? [];
        }
        if ($actor !== null && !preg_match('/^[a-z0-9_.-]+\.v[0-9]+$/', $name)) throw new \InvalidArgumentException('Versioned event name is required.');
    }

    private string $correlationId;
    private string $causationId;
    private string $actor;
    private CommandContext $commandContext;
    /** @var array<string, mixed> */
    private array $payload;

    public function eventId(): string { return $this->eventId; }
    public function name(): string { return $this->name; }
    public function occurredAt(): string { return $this->occurredAt; }
    public function context(): CommandContext { return $this->commandContext; }
    /** @return array<string, mixed> */
    public function payload(): array { return $this->payload; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['event_id' => $this->eventId, 'name' => $this->name, 'occurred_at' => $this->occurredAt, 'context' => $this->commandContext->toArray(), 'correlation_id' => $this->correlationId, 'causation_id' => $this->causationId, 'actor' => $this->actor, 'payload' => $this->payload, 'schema_version' => $this->schemaVersion, 'redaction' => $this->redaction];
    }
}
