<?php
declare(strict_types=1);
namespace App\Modules\Coupon\Application;
use App\Modules\Coupon\Contracts\CouponStore;
final class CouponService
{
    public function __construct(private readonly CouponStore $store) {}
    /** @return array<int, array<string, mixed>> */ public function all(): array { return $this->store->all(); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array { return $this->store->create($data); }
    public function disable(string $id): bool { return $this->store->disable($id); }
    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): bool { return $this->store->update($id, $data); }
}
