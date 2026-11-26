<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class CommerceDeadLetter extends Model
{
    protected string $table = 'commerce_dead_letters';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'job_id', 'reason', 'payload_json', 'failed_at', 'resolved_at'];
}
