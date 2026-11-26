<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain;

use App\Modules\Shared\Domain\Money;

final readonly class PaymentOperation
{
    public function __construct(
        public string $id,
        public string $paymentId,
        public string $type,
        public Money $amount,
        public string $idempotencyKey,
        public string $state = 'received',
    ) {
        if (!in_array($this->type, ['authorize', 'capture', 'void', 'refund'], true)) {
            throw new \InvalidArgumentException('Unsupported payment operation');
        }
        if ($this->idempotencyKey === '') {
            throw new \InvalidArgumentException('Payment operation idempotency key is required');
        }
    }
}
