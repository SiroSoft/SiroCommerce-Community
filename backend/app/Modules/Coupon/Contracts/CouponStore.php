<?php
declare(strict_types=1);
namespace App\Modules\Coupon\Contracts;
interface CouponStore
{
    /** @return array<int, array<string, mixed>> */ public function all(): array;
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;
    public function disable(string $id): bool;
    /**
     * @param array<string, mixed> $data
     */
    public function update(string $id, array $data): bool;
}
