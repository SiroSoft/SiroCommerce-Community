<?php
declare(strict_types=1);
namespace App\Resources;
use Siro\Core\Resource;
final class SubscriptionResource extends Resource
{
    public function toArray(): array
    { return array_intersect_key($this->data, array_flip(['id', 'customer_id', 'plan_id', 'state', 'amount_minor', 'currency', 'next_billing_at', 'started_at', 'paused_at', 'cancelled_at'])); }
}
