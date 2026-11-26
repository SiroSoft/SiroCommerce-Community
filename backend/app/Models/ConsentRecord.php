<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class ConsentRecord extends Model
{
    protected string $table = 'consent_records';
    protected bool $timestamps = false;
    protected array $fillable = [
        'id',
        'session_hash',
        'choices_json',
        'policy_version',
        'ip_hash',
        'user_agent',
        'created_at',
        'updated_at',
    ];
}
