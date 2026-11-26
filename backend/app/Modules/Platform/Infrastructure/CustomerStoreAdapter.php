<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\Address;
use App\Models\Customer;
use App\Modules\Customer\Contracts\CustomerStore;
use App\Repositories\Concerns\CreatesModels;

final class CustomerStoreAdapter implements CustomerStore
{
    use CreatesModels;

    public function find(string $id): ?array { return $this->row(Customer::find($id)); }
    public function findByEmail(string $email): ?array { return $this->row(Customer::query()->where('email', strtolower(trim($email)))->first()); }
    public function address(string $customerId, string $addressId): ?array { return $this->row(Address::query()->where('id', $addressId)->where('customer_id', $customerId)->first()); }
    public function create(array $data): array { $result = self::createRow(Customer::class, $data); return $this->map($result); }
    public function createAddress(array $data): array { $result = self::createRow(Address::class, $data); return $this->map($result); }
    public function addresses(string $customerId): array
    {
        $rows = [];
        foreach (Address::query()->where('customer_id', $customerId)->get() as $model) { $row = $this->row($model); if ($row !== null) $rows[] = $row; }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function row(mixed $model): ?array { return is_object($model) && method_exists($model, 'toArray') ? $this->map($model->toArray()) : null; }
    /** @return array<string, mixed> */
    private function map(mixed $value): array { $result = []; if (is_array($value)) foreach ($value as $key => $item) if (is_string($key)) $result[$key] = $item; return $result; }
}
