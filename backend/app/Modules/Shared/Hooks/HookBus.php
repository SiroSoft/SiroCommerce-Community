<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

/** Deterministic typed hook dispatcher; external I/O belongs in events/jobs. */
final class HookBus
{
    private static ?self $shared = null;

    /**
     * Process-wide bus: dispatch sites and extension boot() meet here.
     * Ad-hoc `new HookBus()` instances stay isolated (tests, dry runs).
     */
    public static function shared(): self
    {
        if (self::$shared === null) self::$shared = new self();
        return self::$shared;
    }

    public static function resetShared(): void
    {
        self::$shared = null;
    }

    /** @var array<string, array<int, HookHandler>> */
    private array $handlers = [];

    public function register(string|HookName $hook, HookHandler $handler): void
    {
        $hook = self::key($hook);
        if (!HookCatalog::contains($hook)) {
            throw new \InvalidArgumentException('Unknown hook: ' . $hook);
        }
        $this->handlers[$hook][] = $handler;
        usort($this->handlers[$hook], static function (HookHandler $a, HookHandler $b): int {
            return [$a->priority(), $a->id()] <=> [$b->priority(), $b->id()];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, decisions: list<string>}
     */
    public function dispatch(string|HookName $hook, array $payload): array
    {
        $hook = self::key($hook);
        if (!HookCatalog::contains($hook)) {
            throw new \InvalidArgumentException('Unknown hook: ' . $hook);
        }
        $decisions = [];
        foreach ($this->handlers[$hook] ?? [] as $handler) {
            // Failure isolation: a crashing handler is recorded and skipped so
            // one bad module can never break core flow. Only an explicit
            // reject vetoes the operation.
            try {
                $decision = $handler->handle($hook, $payload);
            } catch (\Throwable $e) {
                $decisions[] = $handler->id() . ':error';
                error_log('[hooks] handler ' . $handler->id() . ' failed on ' . $hook . ': ' . $e->getMessage());
                continue;
            }
            $decisions[] = $handler->id() . ':' . $decision->type;
            if ($decision->type === 'reject') {
                throw new \RuntimeException('Hook rejected: ' . ($decision->reason ?? 'unspecified'));
            }
            if ($decision->type === 'stop') {
                break;
            }
        }
        return ['payload' => $payload, 'decisions' => $decisions];
    }

    /** Typed entry point for application code; string dispatch remains for extensions. */
    /** @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, decisions: list<string>} */
    public function dispatchTyped(HookName $hook, array $payload): array
    {
        return $this->dispatch($hook, $payload);
    }

    /** @return array<string, int> */
    public function handlerCounts(): array
    {
        $counts = [];
        foreach (HookCatalog::names() as $hook) {
            $counts[$hook] = count($this->handlers[$hook] ?? []);
        }
        return $counts;
    }

    private static function key(string|HookName $hook): string
    {
        return $hook instanceof HookName ? $hook->value : $hook;
    }
}
