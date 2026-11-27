<?php
declare(strict_types=1);
namespace App\Modules\Coupon\Application;
use App\Modules\Coupon\Contracts\CouponStore;
use App\Support\Arr;

/**
 * Public extension SDK contract (Batch 1 Platform §2.6): extensions create
 * coupons through this service via the container — never by calling the
 * admin API with an admin token. The capability gate is `coupon:create`
 * (declare it in the manifest `permissions`; enforce with the
 * `cap:coupon:create` route middleware — owner/admin hold every permission
 * an enabled extension declares, spec RULE-E7).
 */
final class CouponService
{
    public function __construct(private readonly CouponStore $store) {}
    /** @return array<int, array<string, mixed>> */ public function all(): array { return $this->store->all(); }
    /**
     * Create a coupon. Validation mirrors CouponController::store(): the code
     * must be 3-64 letters, numbers, hyphens or underscores, and `rules`
     * must be a JSON object (string or array). Violations throw
     * InvalidArgumentException so extension routes can map them to 422.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $code = Arr::str($data, 'code');
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{2,63}\z/', $code) !== 1) throw new \InvalidArgumentException('Coupon code must be 3-64 letters, numbers, hyphens, or underscores');
        $rules = $data['rules'] ?? '{}';
        if (is_array($rules)) {
            if (array_is_list($rules) && $rules !== []) throw new \InvalidArgumentException('Coupon rules must be a JSON object');
            $decoded = $rules;
        } elseif (is_string($rules)) {
            $decoded = json_decode($rules, true);
            // An empty list decodes from both '{}' and '[]'; the controller
            // itself re-encodes '{}' as '[]' before calling create(), so an
            // empty list is the canonical "no rules" form — accept it.
            if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) throw new \InvalidArgumentException('Coupon rules must be a JSON object');
        } else {
            throw new \InvalidArgumentException('Coupon rules must be a JSON object');
        }
        $data['code'] = strtoupper($code);
        $data['rules'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        if (!isset($data['state'])) $data['state'] = 'active';
        return $this->store->create($data);
    }
    public function disable(string $id): bool { return $this->store->disable($id); }
    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): bool { return $this->store->update($id, $data); }
}
