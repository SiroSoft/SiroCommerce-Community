<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Subscription extends Model
{
    protected string $table = 'subscriptions';
    protected bool $timestamps = false;
    protected array $casts = ['amount_minor' => 'int', 'interval_count' => 'int'];
    protected array $fillable = ['id', 'customer_id', 'plan_id', 'provider', 'currency', 'amount_minor', 'interval', 'interval_count', 'state', 'plan_snapshot_json', 'started_at', 'paused_at', 'cancelled_at', 'cancellation_effective_at', 'next_billing_at', 'dunning_until', 'created_at', 'updated_at'];
}
