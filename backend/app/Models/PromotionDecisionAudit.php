<?php
declare(strict_types=1);
namespace App\Models;
use Siro\Core\Model;

final class PromotionDecisionAudit extends Model
{
    protected string $table = 'promotion_decision_audits';
    protected bool $timestamps = false;
    protected array $fillable = ['id', 'coupon_id', 'code_hash', 'decision', 'reason', 'currency', 'subtotal_minor', 'discount_minor', 'actor_hash', 'context_json', 'created_at'];

    public function save(): bool
    {
        $existing = self::query()->where('id', $this->getAttribute('id'))->first();
        if ($existing !== null) {
            foreach ($this->fillable as $field) if ($existing->getAttribute($field) !== $this->getAttribute($field)) throw new \RuntimeException('Promotion decision audit is immutable');
        }
        return parent::save();
    }
}
