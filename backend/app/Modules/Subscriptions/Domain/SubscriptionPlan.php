<?php
declare(strict_types=1);
namespace App\Modules\Subscriptions\Domain;
use App\Modules\Shared\Domain\Money;
final readonly class SubscriptionPlan
{
    public function __construct(public string $id, public Money $price, public string $interval, public int $intervalCount = 1)
    {
        if (!in_array($this->interval, ['day', 'week', 'month', 'year'], true) || $this->intervalCount < 1) throw new \InvalidArgumentException('Invalid billing interval');
    }
}
