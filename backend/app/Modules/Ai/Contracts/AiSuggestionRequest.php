<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Support\CommerceScope;

final readonly class AiSuggestionRequest
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $task,
        public array $input,
        public CommerceScope $scope,
        public string $actorId,
        public int $maxCostMinor = 0,
    ) {
        if (!preg_match('/^[a-z0-9._-]{1,64}$/', $this->task)) throw new \InvalidArgumentException('Invalid AI suggestion task');
        if ($this->scope->tenantId === null) throw new \InvalidArgumentException('AI suggestions require an explicit tenant scope');
        if ($this->maxCostMinor < 0) throw new \InvalidArgumentException('AI suggestion budget cannot be negative');
    }
}
