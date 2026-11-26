<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class AnalyticsEvent extends Model
{
    protected string $table = 'analytics_events';

    protected bool $timestamps = false;

    protected array $casts = [
        'value_minor' => 'int',
        'schema_version' => 'int',
        'created_at' => 'datetime',
    ];

    protected array $fillable = [
        'id',
        'event_id',
        'schema_version',
        'payload_json',
        'event',
        'entity_type',
        'entity_id',
        'session_key',
        'value_minor',
        'created_at',
        'store_id',
        'channel_id',
        'locale',
        'currency',
    ];
}
