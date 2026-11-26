<?php
declare(strict_types=1);
namespace App\Modules\Checkout\Contracts;
interface PromotionPort
{
    /**
     * @param array<int, array<string, mixed>> $lines
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function preview(string $code, int $subtotalMinor, string $currency = 'VND', array $lines = [], array $context = []): array;
    public function recordUsage(string $couponId, string $orderId, int $discountMinor): void;
    public function reserveUsage(string $couponId, string $reservationKey, int $discountMinor): void;
    public function commitUsage(string $reservationKey): void;
    public function rollbackUsage(string $reservationKey): void;
}
