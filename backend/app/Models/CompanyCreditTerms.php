<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;
final class CompanyCreditTerms extends Model { protected string $table = 'company_credit_terms'; protected array $fillable = ['id','company_id','payment_terms_days','evidence_json']; protected array $casts = ['payment_terms_days'=>'int']; }
