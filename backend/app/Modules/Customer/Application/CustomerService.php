<?php

declare(strict_types=1);

namespace App\Modules\Customer\Application;

use App\Modules\Customer\Contracts\CustomerStore;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use App\Support\Arr;
use App\Support\Ids;
use App\Modules\Commerce\Domain\RegionalRules;

final class CustomerService
{
    public function __construct(private readonly CustomerStore $store) {}

    /** @return array<string, mixed> */
    public function register(string $email, string $name = ''): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('A valid email is required');
        $created = $this->store->create(['id' => Ids::new(), 'email' => $email, 'name' => trim($name), 'created_at' => date('Y-m-d H:i:s')]);
        Hooks::notify(HookName::CustomerRegistered, ['customer_id' => Arr::requiredStr($created, 'id'), 'email' => $email, 'name' => trim($name)]);
        return $created;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array { return $this->store->find($id); }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addAddress(string $customerId, array $data): array
    {
        if ($this->store->find($customerId) === null) throw new \RuntimeException('Customer not found');
        foreach (['line1', 'city', 'phone'] as $field) if (trim(Arr::str($data, $field)) === '') throw new \InvalidArgumentException("{$field} is required");
        if (mb_strlen(trim(Arr::requiredStr($data, 'line1'))) > 255) throw new \InvalidArgumentException('line1 is too long');
        if (mb_strlen(trim(Arr::requiredStr($data, 'city'))) > 128) throw new \InvalidArgumentException('city is too long');
        $regional = RegionalRules::address(['country' => Arr::str($data, 'country', 'VN'), 'region' => Arr::str($data, 'region'), 'postal_code' => Arr::str($data, 'postal_code')]);
        $phone = preg_replace('/[^0-9+(). -]/', '', Arr::requiredStr($data, 'phone'));
        if (!is_string($phone) || strlen((string) preg_replace('/\D/', '', $phone)) < 7) throw new \InvalidArgumentException('phone is invalid');
        return $this->store->createAddress(['id' => Ids::new(), 'customer_id' => $customerId, 'label' => trim(Arr::str($data, 'label', 'home')), 'line1' => trim(Arr::requiredStr($data, 'line1')), 'city' => trim(Arr::requiredStr($data, 'city')), 'phone' => trim($phone), 'country' => $regional['country'], 'region' => $regional['region'] ?: null, 'postal_code' => $regional['postal_code'] ?: null]);
    }

    /** @return array<int, array<string, mixed>> */
    public function addresses(string $customerId): array
    {
        if ($this->store->find($customerId) === null) throw new \RuntimeException('Customer not found');
        return $this->store->addresses($customerId);
    }
}
