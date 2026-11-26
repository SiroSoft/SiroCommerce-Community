<?php

declare(strict_types=1);

namespace App\Modules\Payment\Contracts;

use App\Modules\Payment\Domain\PaymentOperation;

interface PaymentProvider
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function execute(PaymentOperation $operation, array $context): array;

    /** @param array<string, string> $headers */
    public function verifyWebhook(string $rawBody, array $headers): bool;
}
