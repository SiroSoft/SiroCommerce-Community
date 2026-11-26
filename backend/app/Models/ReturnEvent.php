<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class ReturnEvent extends Model
{
    protected string $table = 'return_events';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'return_id', 'event_key', 'state', 'payload_json', 'created_at'];
}
