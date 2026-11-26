<?php
declare(strict_types=1);
namespace App\Modules\Promotion\Application;

use App\Models\MemberDeal;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Scheduled + lifecycle member deals (spec MEMBER_DEALS_SPEC): tier windows
 * and birthday percent-off, auto-applied, best single deal wins.
 * Tier truth is the loyalty snapshot (user-id space, like member pricing).
 */
final class MemberDealService
{
    public const KINDS = ['tier_window', 'birthday'];

    /**
     * @return array{deal: array<string, mixed>|null, discount_minor: int}
     */
    public function discountFor(string $memberId, int $baseMinor, ?int $now = null): array
    {
        $now ??= time();
        if ($memberId === '' || $baseMinor <= 0) return ['deal' => null, 'discount_minor' => 0];
        $tier = (new MemberPriceService())->tierFor($memberId);
        if ($tier === null) return ['deal' => null, 'discount_minor' => 0];
        $birth = $this->birthdateFor($memberId);
        $best = null;
        try {
            $rows = MemberDeal::query()->where('state', 'active')->get();
        } catch (\Throwable $e) {
            return ['deal' => null, 'discount_minor' => 0];
        }
        foreach ($rows as $m) {
            $row = Arr::row($m);
            $kind = Arr::str($row, 'kind');
            if (!in_array($kind, self::KINDS, true)) continue;
            $min = MemberPriceService::normalizeTier(Arr::str($row, 'min_tier'));
            if ($min !== null && MemberPriceService::rankOf($tier) < MemberPriceService::rankOf($min)) continue;
            if ($kind === 'tier_window') {
                $start = strtotime(Arr::str($row, 'starts_at'));
                $end = strtotime(Arr::str($row, 'ends_at'));
                if ($start === false || $end === false || $start > $now || $end < $now) continue;
            } else {
                if ($birth === null || substr($birth, 5) !== date('m-d', $now)) continue;
            }
            $percent = Arr::int($row, 'percent_off');
            if ($percent < 1 || $percent > 90) continue;
            if ($best === null || $percent > Arr::int($best, 'percent_off')) $best = $row;
        }
        if ($best === null) return ['deal' => null, 'discount_minor' => 0];
        return ['deal' => $best, 'discount_minor' => (int) floor($baseMinor * Arr::int($best, 'percent_off') / 100)];
    }

    public function birthdateFor(string $memberId): ?string
    {
        if ($memberId === '') return null;
        try {
            $rows = Database::table('users')->where('id', $memberId)->limit(1)->get();
            $list = is_array($rows) ? $rows : iterator_to_array($rows);
            $birth = Arr::str(Arr::row($list[0] ?? []), 'birthdate');
            return self::normalizeBirthdate($birth) ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function list(): array
    {
        $out = [];
        foreach (MemberDeal::query()->orderBy('created_at', 'desc')->get() as $m) $out[] = Arr::row($m);
        return $out;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $clean = $this->clean($data, true);
        $clean['id'] = Ids::new();
        $clean['state'] = 'active';
        $clean['created_at'] = date('Y-m-d H:i:s');
        MemberDeal::create($clean);
        $row = MemberDeal::query()->where('id', $clean['id'])->first();
        return $row === null ? $clean : Arr::row($row);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(string $id, array $data): array
    {
        $row = MemberDeal::query()->where('id', $id)->first();
        if ($row === null) throw new \RuntimeException('Member deal not found');
        $clean = $this->clean($data, false);
        foreach ($clean as $key => $value) $row->setAttribute($key, $value);
        $row->save();
        return Arr::row($row);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function clean(array $data, bool $isCreate): array
    {
        $out = [];
        if ($isCreate || array_key_exists('name', $data)) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 128) throw new \InvalidArgumentException('Deal name is invalid');
            $out['name'] = $name;
        }
        if ($isCreate || array_key_exists('kind', $data)) {
            $kind = trim((string) ($data['kind'] ?? 'tier_window'));
            if (!in_array($kind, self::KINDS, true)) throw new \InvalidArgumentException('Deal kind is invalid');
            $out['kind'] = $kind;
        }
        if (array_key_exists('min_tier', $data)) {
            $raw = trim((string) ($data['min_tier'] ?? ''));
            if ($raw !== '' && MemberPriceService::normalizeTier($raw) === null) throw new \InvalidArgumentException('min_tier must be bronze|silver|gold|platinum');
            $out['min_tier'] = $raw !== '' ? strtolower($raw) : null;
        }
        if ($isCreate || array_key_exists('percent_off', $data)) {
            $percent = is_int($data['percent_off'] ?? null) ? $data['percent_off'] : (ctype_digit((string) ($data['percent_off'] ?? '')) ? (int) $data['percent_off'] : -1);
            if ($percent < 1 || $percent > 90) throw new \InvalidArgumentException('percent_off must be 1..90');
            $out['percent_off'] = $percent;
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (!array_key_exists($field, $data)) continue;
            $raw = trim((string) ($data[$field] ?? ''));
            if ($raw === '') {
                $out[$field] = null;
                continue;
            }
            if (strtotime($raw) === false) throw new \InvalidArgumentException("Deal {$field} is invalid");
            $out[$field] = date('Y-m-d H:i:s', strtotime($raw));
        }
        if (array_key_exists('state', $data)) {
            $state = trim((string) $data['state']);
            if (!in_array($state, ['active', 'disabled'], true)) throw new \InvalidArgumentException('Deal state is invalid');
            $out['state'] = $state;
        }
        return $out;
    }

    public static function normalizeBirthdate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return null;
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;
        if ($value > date('Y-m-d')) return null;
        return $value;
    }
}
