<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

final readonly class PaymentTransportResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
