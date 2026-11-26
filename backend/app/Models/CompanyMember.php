<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyMember extends Model { protected string $table = 'company_members'; protected array $fillable = ['id', 'company_id', 'user_id', 'role', 'status']; protected array $casts = ['user_id' => 'int']; }
