<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

final readonly class AiSuggestionResult
{
    /**
     * @param array<string, mixed> $suggestion
     * @param array<string, mixed> $usage
     */
    public function __construct(
        public string $status,
        public array $suggestion = [],
        public array $usage = [],
        public ?string $reason = null,
    ) {
        if (!in_array($this->status, ['available', 'provider_unavailable', 'budget_exceeded', 'denied'], true)) throw new \InvalidArgumentException('Invalid AI suggestion status');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'suggestion' => $this->suggestion, 'usage' => $this->usage, 'reason' => $this->reason, 'autonomous_mutation' => false];
    }
}
