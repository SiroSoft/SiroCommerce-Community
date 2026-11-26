<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class FinanceEvidenceRecord extends Model
{
    protected string $table = 'finance_evidence_records';
    protected bool $timestamps = false;
    protected array $fillable = ['id','aggregate_type','aggregate_id','kind','payload_json','payload_hash','retention_until','legal_hold','created_at'];
}
