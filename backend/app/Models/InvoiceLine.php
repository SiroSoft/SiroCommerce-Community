<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class InvoiceLine extends Model
{
    protected string $table = 'invoice_lines';
    protected bool $timestamps = false;
    protected array $casts = ['qty' => 'int', 'unit_price_minor' => 'int', 'amount_minor' => 'int', 'tax_minor' => 'int'];
    protected array $fillable = ['id','invoice_id','source_line_id','sku','name','qty','unit_price_minor','amount_minor','tax_minor'];

    public function save(): bool
    {
        $id = $this->getAttribute('id');
        if ((is_string($id) || is_int($id)) && self::find($id) !== null) {
            throw new \RuntimeException('Invoice evidence lines are append-only');
        }

        return parent::save();
    }

    public function delete(): bool
    {
        throw new \RuntimeException('Invoice evidence lines are append-only');
    }
}
