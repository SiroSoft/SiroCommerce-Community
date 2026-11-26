<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Invoice extends Model { protected string $table = 'invoices'; protected bool $timestamps = false; protected array $casts = ['amount_minor' => 'int','minor_unit' => 'int','subtotal_minor' => 'int','discount_minor' => 'int','shipping_minor' => 'int','tax_minor' => 'int']; protected array $fillable = ['id','number','order_id','payment_id','state','currency','minor_unit','subtotal_minor','discount_minor','shipping_minor','tax_minor','amount_minor','evidence_json','idempotency_key','issued_at','created_at'];
    public function save(): bool
    {
        $id = $this->getAttribute('id');
        $existing = (is_string($id) || is_int($id)) ? self::find($id) : null;
        if ($existing !== null) foreach (['number','order_id','payment_id','currency','minor_unit','subtotal_minor','discount_minor','shipping_minor','tax_minor','amount_minor','evidence_json','idempotency_key','created_at'] as $field) {
            $before = $existing->getAttribute($field);
            $after = $this->getAttribute($field);
            if ($before === $after) continue;
            // Core >=1.1 registers the in-memory (partial) instance in the
            // identity map: an unset int column reads as null there but as
            // its DB default (0) on hydrated reads. Compare through the int
            // cast so DB defaults do not read as document mutations.
            if (($this->casts[$field] ?? null) === 'int' && (int) $before === (int) $after) continue;
            throw new \RuntimeException('Issued invoice document is immutable');
        }
        return parent::save();
    }
}
