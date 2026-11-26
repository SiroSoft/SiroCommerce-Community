<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class Channel extends Model { protected string $table = 'channels'; protected array $fillable = ['id', 'store_id', 'code', 'name', 'status']; }
