<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

/** Executes only catalogued typed hooks and records bounded local evidence. */
final class TypedHookExecutor
{
    public function __construct(private readonly HookBus $bus)
    {
    }

    /** @param array<string, mixed> $payload */
    public function execute(HookName $hook, array $payload): HookExecution
    {
        $started = hrtime(true);
        $result = $this->bus->dispatchTyped($hook, $payload);
        $elapsed = (int) ((hrtime(true) - $started) / 1000);
        return new HookExecution($hook, $result['payload'], $result['decisions'], $elapsed);
    }
}
