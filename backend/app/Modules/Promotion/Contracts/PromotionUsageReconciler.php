<?php
declare(strict_types=1);

namespace App\Modules\Promotion\Contracts;

interface PromotionUsageReconciler
{
    /** @return array<string, mixed> */
    public function reconcile(string $orderId, int $amountMinor, int $orderTotalMinor, string $currency, string $idempotencyKey, string $reason = ''): array;
}
