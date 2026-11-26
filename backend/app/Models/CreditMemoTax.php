<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CreditMemoTax extends Model
{
    protected string $table = 'credit_memo_taxes';
    protected bool $timestamps = false;
    protected array $casts = ['taxable_minor' => 'int', 'tax_minor' => 'int'];
    protected array $fillable = ['id','credit_memo_id','code','rate','taxable_minor','tax_minor','evidence_json'];

    public function save(): bool
    {
        $id = $this->getAttribute('id');
        if ((is_string($id) || is_int($id)) && self::find($id) !== null) throw new \RuntimeException('Credit memo tax evidence is append-only');
        return parent::save();
    }

    public function delete(): bool { throw new \RuntimeException('Credit memo tax evidence is append-only'); }
}
