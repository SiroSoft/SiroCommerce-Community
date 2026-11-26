<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CommerceJob extends Model
{
    protected string $table = 'commerce_jobs';
    protected bool $timestamps = false;
    protected array $casts = ['attempts' => 'int', 'max_attempts' => 'int'];
    protected array $fillable = ['id', 'queue', 'name', 'idempotency_key', 'payload_json', 'state', 'attempts', 'max_attempts', 'available_at', 'locked_at', 'worker_id', 'last_error', 'completed_at'];
}
