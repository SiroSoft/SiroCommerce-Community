<?php

declare(strict_types=1);

namespace App\Modules\Ai\Infrastructure;

use App\Modules\Ai\Contracts\AiCostLedger;

final class NativeAiCostLedger implements AiCostLedger
{
    public function remainingMinor(string $tenantId): int
    {
        return 0;
    }

    public function reserveMinor(string $tenantId, int $amountMinor): bool
    {
        return false;
    }
}
