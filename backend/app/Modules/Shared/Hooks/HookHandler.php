<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

interface HookHandler
{
    public function id(): string;

    public function priority(): int;

    /** @param array<string, mixed> $payload */
    public function handle(string $hook, array &$payload): HookDecision;
}
