<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class ReturnInventoryPosting extends Model
{
    protected string $table = 'return_inventory_postings';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'return_id', 'return_line_id', 'variant_id', 'qty', 'disposition', 'source_id', 'created_at'];
}
