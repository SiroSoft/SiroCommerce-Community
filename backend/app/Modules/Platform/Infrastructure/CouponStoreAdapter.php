<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Coupon\Contracts\CouponStore;
use App\Repositories\CouponRepository;
final class CouponStoreAdapter implements CouponStore
{
    public function __construct(private readonly CouponRepository $coupons) {}
    public function all(): array { return $this->coupons->all(); }
    /** @return array<string, mixed> */ public function create(array $data): array { return $this->coupons->store($data); }
    public function disable(string $id): bool { return $this->coupons->setState($id, 'disabled'); }
    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): bool { return $this->coupons->update($id, $data); }
}
