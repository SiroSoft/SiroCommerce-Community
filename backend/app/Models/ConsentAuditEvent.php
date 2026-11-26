<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class ConsentAuditEvent extends Model
{
    protected string $table = 'consent_audit_events';
    protected bool $timestamps = false;
    protected array $fillable = ['id','customer_id','purpose','granted','version','source','occurred_at'];
}
