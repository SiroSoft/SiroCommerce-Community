<?php
declare(strict_types=1);
namespace App\Modules\Payment\Contracts;
interface PaymentReconciliationProvider
{
    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function reconcile(array $operation): array;
}
