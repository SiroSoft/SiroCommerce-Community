<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

/** Application-facing typed hook execution contract. */
final readonly class HookExecution
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string> $decisions
     */
    public function __construct(
        public HookName $hook,
        public array $payload,
        public array $decisions,
        public int $durationMicros,
    ) {
    }
}
