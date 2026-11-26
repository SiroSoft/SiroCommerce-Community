<?php

declare(strict_types=1);

namespace App\Modules\Queue\Contracts;

interface Job
{
    public function name(): string;

    public function idempotencyKey(): string;

    /** @return array<string, mixed> */
    public function payload(): array;

    public function maxAttempts(): int;
}
