<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Contracts;

interface ReturnService
{
    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public function request(string $orderId, string $fulfillmentId, array $lines, string $reason, string $idempotencyKey): array;

    /** @return array<string, mixed> */
    public function complete(string $returnId, string $idempotencyKey): array;
    /** @return array<string, mixed> */ public function approve(string $returnId, string $idempotencyKey): array;
    /** @return array<string, mixed> */ public function inspect(string $returnId, string $idempotencyKey, string $disposition, string $note = ''): array;
    /** @return array<string, mixed> */ public function approveRefund(string $returnId, string $idempotencyKey): array;
    /** @return array<string, mixed> */ public function reject(string $returnId, string $idempotencyKey, string $note = ''): array;
    /**
     * @param array<string, mixed> $shipping
     * @return array<string, mixed>
     */
    public function createLabel(string $returnId, array $shipping, string $idempotencyKey): array;
}
