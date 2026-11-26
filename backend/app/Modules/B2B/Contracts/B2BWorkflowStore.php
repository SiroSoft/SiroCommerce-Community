<?php
declare(strict_types=1);
namespace App\Modules\B2B\Contracts;
interface B2BWorkflowStore extends B2BCommercialStore {
    /** @return array<string, mixed>|null */
    public function workflow(string $orderId): ?array;
    /** @return array<string, mixed>|null */
    public function transitionByKey(string $key): ?array;
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function transitionOrder(array $data): array;
    /** @return array<string,mixed> */ public function reserveCredit(string $companyId, string $orderId, int $amount, string $currency, string $key): array;
    /** @return array<string,mixed> */ public function releaseCredit(string $orderId, string $key): array;
    /** @return array<string,mixed> */ public function creditEvidence(string $companyId): array;
}
