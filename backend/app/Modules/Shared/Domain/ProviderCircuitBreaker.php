<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

/** In-process circuit guard for one adapter instance; it never fabricates success. */
final class ProviderCircuitBreaker
{
    private int $failures = 0;
    private int $openedUntil = 0;

    public function __construct(private readonly int $threshold = 3, private readonly int $cooldownSeconds = 30) {}
    public function allows(int $now = 0): bool { return $this->openedUntil === 0 || ($now > 0 ? $now : time()) >= $this->openedUntil; }
    public function success(): void { $this->failures = 0; $this->openedUntil = 0; }
    public function failure(int $now = 0): void { $now = $now > 0 ? $now : time(); $this->failures++; if ($this->failures >= max(1, $this->threshold)) $this->openedUntil = $now + max(1, $this->cooldownSeconds); }
}
