<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

interface PaymentTransport
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers, string $body): PaymentTransportResponse;
}
