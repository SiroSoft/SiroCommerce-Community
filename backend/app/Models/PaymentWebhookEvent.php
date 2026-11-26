<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class PaymentWebhookEvent extends Model
{
    protected string $table = 'payment_webhook_events';
    protected bool $timestamps = false;
    protected array $casts = ['signature_ok' => 'int'];
    protected array $fillable = ['id', 'provider', 'event_id', 'payload_hash', 'signature_ok', 'state', 'payload_json', 'last_error', 'received_at', 'processed_at'];
}
