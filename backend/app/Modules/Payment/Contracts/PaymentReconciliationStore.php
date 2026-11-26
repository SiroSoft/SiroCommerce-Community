<?php
declare(strict_types=1);
namespace App\Modules\Payment\Contracts;
interface PaymentReconciliationStore
{
    /** @return array<int, array<string, mixed>> */ public function unresolved(): array;
    /** @param array<string, mixed> $result */ public function reconcile(string $id, array $result): void;
}
