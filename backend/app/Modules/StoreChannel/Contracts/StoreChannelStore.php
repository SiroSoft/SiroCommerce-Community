<?php
declare(strict_types=1);
namespace App\Modules\StoreChannel\Contracts;
interface StoreChannelStore {
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function createStore(array $data): array;
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function createChannel(array $data): array;
 /** @return array<int,array<string,mixed>> */ public function stores(): array;
 /** @return array<int,array<string,mixed>> */ public function channels(string $storeId): array;
}
