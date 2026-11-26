<?php
declare(strict_types=1);

namespace App\Modules\Promotion\Contracts;

/** Future boundary; it does not imply a balance or ownership implementation. */
interface GiftStoreCreditContract
{
    /** @return array<string, mixed> */
    public function issue(string $ownerId, int $amountMinor, string $currency, string $idempotencyKey): array;
    /** @return array<string, mixed> */
    public function redeem(string $creditId, string $ownerId, int $amountMinor, string $currency, string $idempotencyKey): array;
    /** @return array<string, mixed> */
    public function rollback(string $idempotencyKey): array;
    /** @return array<string, mixed> */
    public function expire(string $creditId, string $idempotencyKey): array;
}
