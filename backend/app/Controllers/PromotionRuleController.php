<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Modules\Promotion\Application\PromotionRuleService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin CRUD for price rules (spec PROMOTION_RULES_SPEC).
 * Routes gated by `auth:admin,owner,marketer` + DemoGuard.
 */
final class PromotionRuleController extends Controller
{
    public function __construct(private readonly PromotionRuleService $rules)
    {
    }

    public function index(Request $request): Response
    {
        $all = $this->rules->all();
        $q = strtolower(trim($request->queryString('q', '')));
        $state = strtolower(trim($request->queryString('state', '')));
        $filtered = array_values(array_filter($all, static function (array $row) use ($q, $state): bool {
            if ($state !== '' && strtolower(Arr::str($row, 'state')) !== $state) return false;
            if ($q !== '' && stripos(Arr::str($row, 'name'), $q) === false) return false;
            return true;
        }));
        return $this->success($filtered, 'Promotion rules');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string',
            'kind' => 'required|string',
            'value' => 'required|integer',
            'min_subtotal_minor' => 'nullable|integer',
            'category_slugs' => 'nullable|array',
            'category_slugs_json' => 'nullable|string',
            'starts_at' => 'nullable|string',
            'ends_at' => 'nullable|string',
            'max_uses' => 'nullable|integer',
            'state' => 'nullable|string',
        ]);
        $error = $this->validateRule($data, true);
        if ($error !== null) return $this->error($error, 422);
        return $this->created($this->rules->create($this->payload($data)), 'Promotion rule created');
    }

    public function update(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $existing = $this->rules->find($id);
        if ($existing === null) return $this->error('Promotion rule not found', 404);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string',
            'kind' => 'nullable|string',
            'value' => 'nullable|integer',
            'min_subtotal_minor' => 'nullable|integer',
            'category_slugs' => 'nullable|array',
            'category_slugs_json' => 'nullable|string',
            'starts_at' => 'nullable|string',
            'ends_at' => 'nullable|string',
            'max_uses' => 'nullable|integer',
            'state' => 'nullable|string',
        ]);
        $effective = $existing;
        foreach ($this->payload($data) as $key => $value) {
            $effective[$key] = $value;
        }
        $error = $this->validateRule($effective, false);
        if ($error !== null) return $this->error($error, 422);
        $updated = $this->rules->update($id, $this->payload($data));
        return $this->success($updated, 'Promotion rule updated');
    }

    public function destroy(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        if (!$this->rules->delete($id)) return $this->error('Promotion rule not found', 404);
        return $this->success(null, 'Promotion rule deleted');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $out = [];
        foreach (['name', 'kind', 'state', 'value', 'min_subtotal_minor', 'max_uses', 'starts_at', 'ends_at'] as $key) {
            if (array_key_exists($key, $data)) $out[$key] = $data[$key];
        }
        if (array_key_exists('category_slugs', $data)) {
            $out['category_slugs'] = $this->stringValues($data['category_slugs']);
        } elseif (array_key_exists('category_slugs_json', $data) && is_string($data['category_slugs_json'])) {
            $decoded = json_decode($data['category_slugs_json'], true);
            $out['category_slugs'] = is_array($decoded) ? $this->stringValues($decoded) : [];
        }
        return $out;
    }

    /** @return array<int, string> */
    private function stringValues(mixed $value): array
    {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) if (is_string($item)) $out[] = $item;
        return $out;
    }

    /** @param array<string, mixed> $rule */
    private function validateRule(array $rule, bool $creating): ?string
    {
        $name = trim(Arr::str($rule, 'name'));
        if ($creating && $name === '') return 'Promotion rule name is required';
        if ($name !== '' && mb_strlen($name) > 160) return 'Promotion rule name must be at most 160 characters';
        $kind = Arr::str($rule, 'kind');
        if (!in_array($kind, PromotionRuleService::KINDS, true)) return 'Promotion rule kind is invalid';
        $value = Arr::int($rule, 'value', -1);
        if ($value < 0) return 'Promotion rule value is invalid';
        if ($kind === 'cart_fixed') {
            if ($value < 1 || $value > 1000000000) return 'Promotion rule fixed value must be between 1 and 1000000000 minor';
        } elseif ($value < 1 || $value > 90) {
            return 'Promotion rule percent must be between 1 and 90';
        }
        if (array_key_exists('min_subtotal_minor', $rule) && $rule['min_subtotal_minor'] !== null && Arr::int($rule, 'min_subtotal_minor', -1) < 0) {
            return 'Promotion rule minimum subtotal must be non-negative';
        }
        if (array_key_exists('max_uses', $rule) && $rule['max_uses'] !== null && Arr::int($rule, 'max_uses', -1) < 1) {
            return 'Promotion rule max uses must be at least 1';
        }
        $state = strtolower(Arr::str($rule, 'state', 'active'));
        if (!in_array($state, ['active', 'disabled'], true)) return 'Promotion rule state must be active or disabled';
        $starts = Arr::nullableStr($rule, 'starts_at');
        $ends = Arr::nullableStr($rule, 'ends_at');
        $startTs = ($starts !== null && $starts !== '') ? strtotime($starts) : null;
        $endTs = ($ends !== null && $ends !== '') ? strtotime($ends) : null;
        if ($startTs === false) return 'Promotion rule start date is invalid';
        if ($endTs === false) return 'Promotion rule end date is invalid';
        if (is_int($startTs) && is_int($endTs) && $startTs >= $endTs) return 'Promotion rule start date must be before the end date';
        $slugs = $this->categorySlugs($rule);
        if ($slugs !== []) {
            $found = 0;
            foreach (Category::query()->whereIn('slug', $slugs)->where('is_active', 1)->whereNull('archived_at')->get() as $category) {
                if (Arr::str(Arr::row($category), 'slug') !== '') $found++;
            }
            if ($found !== count(array_unique($slugs))) return 'Promotion rule category slug does not exist';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<int, string>
     */
    private function categorySlugs(array $rule): array
    {
        $raw = $rule['category_slugs'] ?? [];
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $slug) if (is_string($slug) && trim($slug) !== '') $out[] = strtolower(trim($slug));
        return array_values(array_unique($out));
    }
}
