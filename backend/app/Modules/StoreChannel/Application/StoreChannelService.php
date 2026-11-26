<?php
declare(strict_types=1);
namespace App\Modules\StoreChannel\Application;
use App\Modules\StoreChannel\Contracts\StoreChannelStore;
final class StoreChannelService {
    public function __construct(private readonly StoreChannelStore $store) {}
    /** @param array<string,mixed> $data
     * @return array<string,mixed> */ public function createStore(array $data): array { return $this->store->createStore($data); }
    /** @param array<string,mixed> $data
     * @return array<string,mixed> */ public function createChannel(array $data): array { return $this->store->createChannel($data); }
    /** @return array<int,array<string,mixed>> */ public function stores(): array { return $this->store->stores(); }
    /** @return array<int,array<string,mixed>> */ public function channels(string $storeId): array { return $this->store->channels($storeId); }
}
