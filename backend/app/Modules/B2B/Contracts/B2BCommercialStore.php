<?php

declare(strict_types=1);

namespace App\Modules\B2B\Contracts;

/** Optional enterprise boundary for credit terms and contract approvals. */
interface B2BCommercialStore extends B2BStore
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function creditAccount(array $data): array;

    /** @return array<string, mixed>|null */
    public function creditAccountForCompany(string $companyId): ?array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function contract(array $data): array;

    /** @return array<string, mixed>|null */
    public function contractById(string $contractId): ?array;

    /** @return array<string, mixed>|null */
    public function contractApprovalByKey(string $key): ?array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function contractApproval(array $data): array;

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function activateContract(string $contractId, array $data): array;
}
