<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class OutboxMessage extends Model
{
    protected string $table = 'outbox_messages';
    protected bool $timestamps = false;
    protected array $casts = ['attempts' => 'int'];
    protected array $fillable = ['id', 'event_id', 'topic', 'aggregate_type', 'aggregate_id', 'payload_json', 'state', 'attempts', 'available_at', 'locked_at', 'published_at', 'last_error'];
}
