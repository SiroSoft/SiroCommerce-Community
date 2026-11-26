<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

final readonly class HookDecision
{
    private function __construct(public string $type, public ?string $reason = null)
    {
    }

    public static function continue(): self { return new self('continue'); }
    public static function stop(): self { return new self('stop'); }
    public static function reject(string $reason): self { return new self('reject', $reason); }
    /** Handler crashed: recorded and skipped, core flow continues. */
    public static function error(string $reason): self { return new self('error', $reason); }
}
