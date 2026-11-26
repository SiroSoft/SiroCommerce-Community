<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

use App\Modules\Shared\Application\CommandContext;

interface DomainEvent
{
    public function eventId(): string;

    public function name(): string;

    public function occurredAt(): string;

    public function context(): CommandContext;

    /** @return array<string, mixed> */
    public function payload(): array;
}
