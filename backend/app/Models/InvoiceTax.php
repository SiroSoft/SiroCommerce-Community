<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class InvoiceTax extends Model
{
    protected string $table = 'invoice_taxes';
    protected bool $timestamps = false;
    protected array $casts = ['taxable_minor' => 'int', 'tax_minor' => 'int'];
    protected array $fillable = ['id','invoice_id','code','rate','taxable_minor','tax_minor','evidence_json'];

    public function save(): bool
    {
        $id = $this->getAttribute('id');
        if ((is_string($id) || is_int($id)) && self::find($id) !== null) throw new \RuntimeException('Invoice tax evidence is append-only');
        return parent::save();
    }

    public function delete(): bool { throw new \RuntimeException('Invoice tax evidence is append-only'); }
}
