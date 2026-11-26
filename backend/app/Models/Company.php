<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Company extends Model { protected string $table = 'companies'; protected array $fillable = ['id', 'name', 'status']; }
