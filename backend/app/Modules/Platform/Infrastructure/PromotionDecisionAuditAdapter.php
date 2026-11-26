<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Models\PromotionDecisionAudit as PromotionDecisionAuditRecord;
use App\Modules\Promotion\Contracts\PromotionDecisionAudit;
use App\Support\Ids;
use App\Support\Arr;

final class PromotionDecisionAuditAdapter implements PromotionDecisionAudit
{
    public function record(array $decision): void
    {
        PromotionDecisionAuditRecord::create([
            'id' => Ids::new(),
            'coupon_id' => $decision['coupon_id'] ?? null,
            'code_hash' => $decision['code_hash'] ?? '',
            'decision' => $decision['decision'] ?? 'rejected',
            'reason' => $decision['reason'] ?? '',
            'currency' => $decision['currency'] ?? 'VND',
            'subtotal_minor' => Arr::int($decision, 'subtotal_minor'),
            'discount_minor' => Arr::int($decision, 'discount_minor'),
            'actor_hash' => $decision['actor_hash'] ?? null,
            'context_json' => json_encode($decision['context'] ?? [], JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
