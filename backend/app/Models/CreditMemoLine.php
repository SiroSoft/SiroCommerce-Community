<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CreditMemoLine extends Model
{
    protected string $table = 'credit_memo_lines';
    protected bool $timestamps = false;
    protected array $casts = ['amount_minor' => 'int', 'tax_minor' => 'int'];
    protected array $fillable = ['id','credit_memo_id','source_line_id','sku','name','amount_minor','tax_minor'];

    public function save(): bool
    {
        $id = $this->getAttribute('id');
        if ((is_string($id) || is_int($id)) && self::find($id) !== null) {
            throw new \RuntimeException('Credit memo evidence lines are append-only');
        }

        return parent::save();
    }

    public function delete(): bool
    {
        throw new \RuntimeException('Credit memo evidence lines are append-only');
    }
}
