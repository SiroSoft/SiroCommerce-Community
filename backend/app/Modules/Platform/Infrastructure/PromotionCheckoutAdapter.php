<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Checkout\Contracts\PromotionPort;
use App\Modules\Promotion\Application\PromotionService;
final class PromotionCheckoutAdapter implements PromotionPort
{
    public function __construct(private readonly PromotionService $promotions) {}
    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function preview(string $code, int $subtotalMinor, string $currency = 'VND', array $lines = [], array $context = []): array { return $this->promotions->preview($code, $subtotalMinor, $currency, $lines, $context); }
    public function recordUsage(string $couponId, string $orderId, int $discountMinor): void { $this->promotions->recordUsage($couponId, $orderId, $discountMinor); }
    public function reserveUsage(string $couponId, string $reservationKey, int $discountMinor): void { $this->promotions->reserveUsage($couponId, $reservationKey, $discountMinor); }
    public function commitUsage(string $reservationKey): void { $this->promotions->commitUsage($reservationKey); }
    public function rollbackUsage(string $reservationKey): void { $this->promotions->rollbackUsage($reservationKey); }
}
