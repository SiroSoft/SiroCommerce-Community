<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TaxRule;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin CRUD for native tax rules (spec docs/product/TAX_ADMIN_SPEC.md).
 * Routes are gated by admin/owner/finance; the engine ignores missing rules.
 */
final class AdminTaxRuleController extends Controller
{
    public function index(Request $request): Response
    {
        $query = TaxRule::query();
        $state = strtolower(trim($request->queryString('state', '')));
        if ($state !== '') $query->where('state', '=', $state);
        $country = strtoupper(trim($request->queryString('country', '')));
        if ($country !== '') $query->where('destination_country', '=', $country);
        $taxClass = trim($request->queryString('tax_class', ''));
        if ($taxClass !== '') $query->where('tax_class', '=', $taxClass);
        $rows = $query->orderBy('priority', 'asc')->orderBy('code', 'asc')->get();
        $out = [];
        foreach ($rows as $row) $out[] = Arr::row($row);
        return $this->success($out, 'Tax rules');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(false));
        try {
            $payload = $this->payload($data, null);
        } catch (\InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }
        if ($this->codeExists(Arr::str($payload, 'code'), null)) return $this->error('Tax rule code already exists', 409, ['code' => ['Tax rule code already exists']], 'CONFLICT');
        $error = $this->validateRule($payload);
        if ($error !== null) return $this->error($error, 422);
        $id = Ids::new();
        try {
            $rule = TaxRule::create(['id' => $id] + $payload + ['created_at' => date('Y-m-d H:i:s')]);
        } catch (\PDOException $error) {
            if ($this->isDuplicate($error)) return $this->error('Tax rule code already exists', 409, ['code' => ['Tax rule code already exists']], 'CONFLICT');
            throw $error;
        }
        $row = Arr::row($rule);
        $this->audit($request, 'tax.rule.created', null, $row);
        return $this->created($row, 'Tax rule created');
    }

    public function update(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $rule = TaxRule::query()->where('id', $id)->first();
        if ($rule === null) return $this->error('Tax rule not found', 404);
        $before = Arr::row($rule);
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(true));
        if ($data === []) return $this->error('No changes', 422);
        try {
            $payload = $this->payload($data, $before);
        } catch (\InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        }
        $effective = array_merge($before, $payload);
        $error = $this->validateRule($effective);
        if ($error !== null) return $this->error($error, 422);
        if ($this->codeExists(Arr::str($effective, 'code'), $id)) return $this->error('Tax rule code already exists', 409, ['code' => ['Tax rule code already exists']], 'CONFLICT');
        $rule->fill($payload);
        try {
            $rule->save();
        } catch (\PDOException $error) {
            if ($this->isDuplicate($error)) return $this->error('Tax rule code already exists', 409, ['code' => ['Tax rule code already exists']], 'CONFLICT');
            throw $error;
        }
        $after = Arr::row($rule);
        $this->audit($request, 'tax.rule.updated', $before, $after);
        return $this->success($after, 'Tax rule updated');
    }

    public function destroy(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        $rule = TaxRule::query()->where('id', $id)->first();
        if ($rule === null) return $this->error('Tax rule not found', 404);
        $before = Arr::row($rule);
        if (!$rule->delete()) return $this->error('Tax rule not found', 404);
        $this->audit($request, 'tax.rule.deleted', $before, null);
        return $this->success(null, 'Tax rule deleted');
    }

    /** @return array<string, string> */
    private function rules(bool $partial): array
    {
        return [
            'code' => $partial ? 'nullable|string' : 'required|string',
            'destination_country' => 'nullable|string',
            'destination_region' => 'nullable|string',
            'destination_postal_code' => 'nullable|string',
            'tax_class' => 'nullable|string',
            'rate_basis_points' => $partial ? 'nullable|integer' : 'required|integer',
            'priority' => 'nullable|integer',
            'state' => 'nullable|string',
            'compound' => 'nullable',
            'effective_at' => 'nullable|string',
            'expires_at' => 'nullable|string',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function payload(array $data, ?array $existing): array
    {
        $payload = [];
        if (array_key_exists('code', $data)) $payload['code'] = trim($this->stringValue($data['code']));
        if (array_key_exists('destination_country', $data)) $payload['destination_country'] = $this->nullableUpper($data['destination_country']);
        if (array_key_exists('destination_region', $data)) $payload['destination_region'] = $this->nullableString($data['destination_region']);
        if (array_key_exists('destination_postal_code', $data)) $payload['destination_postal_code'] = $this->nullableString($data['destination_postal_code']);
        if (array_key_exists('tax_class', $data)) {
            $taxClass = trim($this->stringValue($data['tax_class']));
            $payload['tax_class'] = $taxClass !== '' ? $taxClass : 'standard';
        }
        if (array_key_exists('rate_basis_points', $data)) $payload['rate_basis_points'] = Arr::int($data, 'rate_basis_points');
        if (array_key_exists('priority', $data)) $payload['priority'] = Arr::int($data, 'priority');
        if (array_key_exists('state', $data)) $payload['state'] = strtolower(trim($this->stringValue($data['state'], 'active')));
        if (array_key_exists('compound', $data)) $payload['compound'] = $this->booleanInt($data['compound']);
        if (array_key_exists('effective_at', $data)) $payload['effective_at'] = $this->nullableDateTime($data['effective_at']);
        if (array_key_exists('expires_at', $data)) $payload['expires_at'] = $this->nullableDateTime($data['expires_at']);

        if ($existing === null) {
            $payload += [
                'destination_country' => null,
                'destination_region' => null,
                'destination_postal_code' => null,
                'tax_class' => 'standard',
                'priority' => 0,
                'state' => 'active',
                'compound' => 0,
                'effective_at' => null,
                'expires_at' => null,
            ];
        }
        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validateRule(array $payload): ?string
    {
        $code = Arr::str($payload, 'code');
        if ($code === '') return 'Tax rule code is required';
        if (mb_strlen($code) > 64) return 'Tax rule code must be at most 64 characters';
        $country = $payload['destination_country'] ?? null;
        if ($country !== null && (!is_string($country) || preg_match('/^[A-Z]{2}$/', $country) !== 1)) {
            return 'Tax rule destination country must be an ISO2 code';
        }
        if (mb_strlen(Arr::str($payload, 'destination_region')) > 64) return 'Tax rule destination region must be at most 64 characters';
        if (mb_strlen(Arr::str($payload, 'destination_postal_code')) > 32) return 'Tax rule destination postal code must be at most 32 characters';
        $taxClass = Arr::str($payload, 'tax_class');
        if ($taxClass === '' || mb_strlen($taxClass) > 32) return 'Tax rule tax class is invalid';
        $rate = Arr::int($payload, 'rate_basis_points', -1);
        if ($rate < 0 || $rate > 10000) return 'Tax rule rate must be between 0 and 10000 basis points';
        if (!in_array(strtolower(Arr::str($payload, 'state', 'active')), ['active', 'disabled'], true)) {
            return 'Tax rule state must be active or disabled';
        }
        $compound = Arr::int($payload, 'compound', 0);
        if ($compound !== 0 && $compound !== 1) return 'Tax rule compound must be a boolean';
        $effectiveAt = Arr::nullableStr($payload, 'effective_at');
        $expiresAt = Arr::nullableStr($payload, 'expires_at');
        if ($effectiveAt !== null && $expiresAt !== null && $effectiveAt !== '' && $expiresAt !== '') {
            $start = strtotime($effectiveAt);
            $end = strtotime($expiresAt);
            if ($start === false || $end === false) return 'Tax rule effective/expires window is invalid';
            if ($start >= $end) return 'Tax rule effective date must be before the expires date';
        }
        return null;
    }

    private function codeExists(string $code, ?string $exceptId): bool
    {
        $query = TaxRule::query()->where('code', '=', $code);
        $rows = $query->get();
        foreach ($rows as $row) {
            $id = Arr::str(Arr::row($row), 'id');
            if ($exceptId === null || $id !== $exceptId) return true;
        }
        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $trimmed = trim((string) $value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        if (is_string($value)) return $value;
        if (is_int($value) || is_float($value) || is_bool($value)) return (string) $value;
        return $default;
    }

    private function nullableUpper(mixed $value): ?string
    {
        $trimmed = $this->nullableString($value);
        return $trimmed === null ? null : strtoupper($trimmed);
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value)) throw new \InvalidArgumentException('Tax rule date is invalid');
        $raw = trim((string) $value);
        if ($raw === '') return null;
        $timestamp = strtotime($raw);
        if ($timestamp === false) throw new \InvalidArgumentException('Tax rule date is invalid');
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function booleanInt(mixed $value): int
    {
        if ($value === null) return 0;
        if (is_bool($value)) return $value ? 1 : 0;
        if (is_int($value)) {
            if ($value === 0 || $value === 1) return $value;
            throw new \InvalidArgumentException('Tax rule compound must be a boolean');
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) return 1;
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) return 0;
        }
        throw new \InvalidArgumentException('Tax rule compound must be a boolean');
    }

    private function isDuplicate(\PDOException $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'unique') || str_contains($message, 'duplicate') || (string) $error->getCode() === '23000';
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function audit(Request $request, string $action, ?array $before, ?array $after): void
    {
        try {
            $user = $request->user();
            $actor = is_array($user) && is_scalar($user['email'] ?? null) ? (string) $user['email'] : 'system';
            Database::connection()->prepare(
                'INSERT INTO commerce_audit_events (id, action, actor, payload_json, tenant_id, seller_id, site_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                Ids::new(),
                $action,
                $actor,
                json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR),
                $this->optionalHeader($request, 'X-Tenant-Id'),
                $this->optionalHeader($request, 'X-Seller-Id'),
                $this->optionalHeader($request, 'X-Site-Id'),
                $this->optionalHeader($request, 'X-Store-Id') ?? 'default',
                $this->optionalHeader($request, 'X-Channel-Id') ?? 'default',
                date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }

    private function optionalHeader(Request $request, string $name): ?string
    {
        $value = trim((string) $request->header($name, ''));
        return $value !== '' ? $value : null;
    }
}
