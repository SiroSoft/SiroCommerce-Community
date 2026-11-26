<?php
declare(strict_types=1);
namespace App\Modules\B2B\Contracts;
interface B2BStore {
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function createCompany(array $data): array;
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function addMember(array $data): array;
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function addScope(array $data): array;
 /** @return array<string,mixed>|null */ public function order(string $orderId): ?array;
 /** @return array<string,mixed>|null */ public function member(string $companyId, int $userId): ?array;
 /** @return array<string,mixed>|null */ public function scope(string $companyId, string $scope, ?string $channelId): ?array;
 /** @return array<string,mixed>|null */ public function approvalByKey(string $key): ?array;
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function approval(array $data): array;
}
