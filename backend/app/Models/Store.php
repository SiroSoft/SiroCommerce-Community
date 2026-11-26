<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Store extends Model { protected string $table = 'stores'; protected array $fillable = ['id', 'code', 'name', 'status']; }
