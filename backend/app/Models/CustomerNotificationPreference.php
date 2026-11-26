<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CustomerNotificationPreference extends Model
{
    protected string $table = 'customer_notification_preferences';
    protected string $primaryKey = 'customer_id';
    protected bool $timestamps = false;
    protected array $casts = ['transactional_email' => 'int', 'marketing_email' => 'int'];
    protected array $fillable = ['customer_id', 'transactional_email', 'marketing_email', 'idempotency_key', 'request_fingerprint', 'updated_at'];
}
