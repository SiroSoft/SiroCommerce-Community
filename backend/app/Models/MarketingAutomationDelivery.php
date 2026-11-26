<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class MarketingAutomationDelivery extends Model
{
    protected string $table = 'marketing_automation_deliveries';
    protected bool $timestamps = false;
    protected array $fillable = [
        'id', 'campaign_key', 'signal', 'customer_id', 'cart_id', 'product_id',
        'state', 'scheduled_at', 'sent_at', 'suppressed_at', 'created_at',
    ];
}
