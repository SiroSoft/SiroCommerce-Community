<?php
declare(strict_types=1);
namespace App\Modules\Promotion\Contracts;

interface PromotionDecisionAudit
{
    /** @param array<string, mixed> $decision */
    public function record(array $decision): void;
}
