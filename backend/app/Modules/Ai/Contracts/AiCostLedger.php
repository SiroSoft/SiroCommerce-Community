<?php

declare(strict_types=1);

namespace App\Modules\Ai\Contracts;

interface AiCostLedger
{
    public function remainingMinor(string $tenantId): int;

    public function reserveMinor(string $tenantId, int $amountMinor): bool;
}
