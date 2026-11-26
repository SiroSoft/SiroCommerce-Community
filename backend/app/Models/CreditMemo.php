<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CreditMemo extends Model { protected string $table = 'credit_memos'; protected bool $timestamps = false; protected array $casts = ['amount_minor' => 'int','minor_unit' => 'int','tax_minor' => 'int']; protected array $fillable = ['id','number','invoice_id','order_id','payment_id','refund_id','state','currency','minor_unit','tax_minor','amount_minor','reason','idempotency_key','evidence_json','created_at'];
    public function save(): bool
    {
        $id = $this->getAttribute('id');
        $existing = (is_string($id) || is_int($id)) ? self::find($id) : null;
        if ($existing !== null) foreach (['number','invoice_id','order_id','payment_id','refund_id','currency','minor_unit','tax_minor','amount_minor','reason','idempotency_key','evidence_json','created_at'] as $field) if ($existing->getAttribute($field) !== $this->getAttribute($field)) throw new \RuntimeException('Credit memo document is immutable');
        return parent::save();
    }
}
