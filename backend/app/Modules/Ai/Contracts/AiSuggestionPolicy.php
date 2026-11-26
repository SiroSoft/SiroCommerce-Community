<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

use App\Support\CommerceScope;

final class AiSuggestionPolicy
{
    public static function allows(AiSuggestionRequest $request, int $remainingMinor): bool
    {
        return $request->scope->tenantId !== null && $request->maxCostMinor <= $remainingMinor;
    }

    public static function assertReadOnlyTask(string $task): void
    {
        if (preg_match('/(?:pay|refund|capture|void|cancel|transition|approve|mutat|write|delete)/i', $task) === 1) {
            throw new \InvalidArgumentException('AI suggestions cannot target financial or autonomous mutations');
        }
    }
}
