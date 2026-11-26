<?php
declare(strict_types=1);
namespace App\Modules\Fulfillment\Contracts;
interface ReturnLabelProvider
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, string $idempotencyKey): array;
}
