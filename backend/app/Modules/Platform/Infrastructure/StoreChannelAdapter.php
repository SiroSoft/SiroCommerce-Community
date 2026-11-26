<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Models\Channel; use App\Models\Store; use App\Modules\StoreChannel\Contracts\StoreChannelStore; use App\Support\Ids; use App\Repositories\Concerns\CreatesModels;
final class StoreChannelAdapter implements StoreChannelStore { use CreatesModels;
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function createStore(array $data): array { $data['id'] = Ids::new(); return self::createRow(Store::class, $data); }
 /** @param array<string,mixed> $data
  * @return array<string,mixed> */ public function createChannel(array $data): array { $data['id'] = Ids::new(); return self::createRow(Channel::class, $data); }
 /** @return array<int,array<string,mixed>> */ public function stores(): array { return $this->rows(Store::query()->get()); }
 /** @return array<int,array<string,mixed>> */ public function channels(string $storeId): array { return $this->rows(Channel::query()->where('store_id', $storeId)->get()); }
 /** @param iterable<int,mixed> $models
  * @return array<int,array<string,mixed>> */ private function rows(iterable $models): array { $rows=[]; foreach ($models as $model) if (is_object($model) && method_exists($model, 'toArray')) { $value=$model->toArray(); if (is_array($value)) { $row=[]; foreach ($value as $key=>$item) if (is_string($key)) $row[$key]=$item; $rows[]=$row; } } return $rows; }
}
