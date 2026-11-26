<?php
declare(strict_types=1);
namespace App\Modules\Promotion\Contracts;
interface PromotionUsageWriter
{
    public function record(string $couponId, string $orderId, int $discountMinor): void;
}
