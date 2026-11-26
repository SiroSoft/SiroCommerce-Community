<?php
declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Modules\Promotion\Contracts\GiftStoreCreditContract;

/** Rejects financial operations until an evidence-backed ledger exists. */
final class FailClosedGiftStoreCredit implements GiftStoreCreditContract
{
    /** @return array<string, mixed> */
    public function issue(string $ownerId, int $amountMinor, string $currency, string $idempotencyKey): array { throw new \RuntimeException('Gift/store-credit issuance is unsupported'); }
    /** @return array<string, mixed> */
    public function redeem(string $creditId, string $ownerId, int $amountMinor, string $currency, string $idempotencyKey): array { throw new \RuntimeException('Gift/store-credit redemption is unsupported'); }
    /** @return array<string, mixed> */
    public function rollback(string $idempotencyKey): array { throw new \RuntimeException('Gift/store-credit rollback is unsupported'); }
    /** @return array<string, mixed> */
    public function expire(string $creditId, string $idempotencyKey): array { throw new \RuntimeException('Gift/store-credit expiry is unsupported'); }
}
