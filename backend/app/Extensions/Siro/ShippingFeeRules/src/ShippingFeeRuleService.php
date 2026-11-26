<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ShippingFeeRules;

use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped shipping fee rules. Every read and write filters by
 * CommerceScope; feeFor() is deterministic (active match with the highest
 * priority; ties break on id). free_over_minor = 0 means "never free".
 */
final class ShippingFeeRuleService
{
    /** @var list<string> */
    public const METHODS = ['cod', 'standard', 'express'];

    public const MAX_PRIORITY = 1000;

    private const COLUMNS = 'id, store_id, channel_id, name, method, min_subtotal_minor, fee_minor, free_over_minor, active, priority, created_at, updated_at';

    /** @return list<array<string, mixed>> */
    public function list(CommerceScope $scope): array
    {
        return $this->rows(
            'SELECT ' . self::COLUMNS . ' FROM ext_shipping_fee_rules WHERE store_id = ? AND channel_id = ? ORDER BY priority DESC, name ASC, id ASC LIMIT 200',
            [$scope->storeId, $scope->channelId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(CommerceScope $scope, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_shipping_fee_rules WHERE store_id = ? AND channel_id = ? AND id = ? LIMIT 1',
            [$scope->storeId, $scope->channelId, $id]
        );
    }

    /**
     * Create a rule. A replay with the same Idempotency-Key (or the same
     * scope/name/method) returns the canonical row instead of duplicating.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(CommerceScope $scope, array $data, ?string $idempotencyKey = null): array
    {
        $normalized = $this->normalize($data);
        $key = $idempotencyKey !== null ? trim($idempotencyKey) : '';
        $key = $key !== '' ? $key : null;

        if ($key !== null) {
            $existing = $this->findByIdempotencyKey($scope, $key);
            if ($existing !== null) {
                return $existing;
            }
        }
        $existing = $this->findByNameMethod($scope, $normalized['name'], $normalized['method']);
        if ($existing !== null) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'id' => Ids::new(),
            'store_id' => $scope->storeId,
            'channel_id' => $scope->channelId,
            'name' => $normalized['name'],
            'method' => $normalized['method'],
            'min_subtotal_minor' => $normalized['min_subtotal_minor'],
            'fee_minor' => $normalized['fee_minor'],
            'free_over_minor' => $normalized['free_over_minor'],
            'active' => $normalized['active'] ? 1 : 0,
            'priority' => $normalized['priority'],
            'idempotency_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            Database::table('ext_shipping_fee_rules')->insert($row);
        } catch (\Throwable $exception) {
            $winner = ($key !== null ? $this->findByIdempotencyKey($scope, $key) : null)
                ?? $this->findByNameMethod($scope, $normalized['name'], $normalized['method']);
            if ($winner !== null) {
                return $winner;
            }
            throw new \RuntimeException('Shipping fee rule could not be created', 0, $exception);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(CommerceScope $scope, string $id, array $data): array
    {
        $existing = $this->find($scope, $id);
        if ($existing === null) {
            throw new \InvalidArgumentException('Shipping fee rule not found');
        }
        $merged = $existing;
        foreach ($data as $field => $value) {
            $merged[$field] = $value;
        }
        $normalized = $this->normalize($merged);
        $stmt = Database::connection()->prepare(
            'UPDATE ext_shipping_fee_rules SET name = ?, method = ?, min_subtotal_minor = ?, fee_minor = ?, free_over_minor = ?, active = ?, priority = ?, updated_at = ? WHERE store_id = ? AND channel_id = ? AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Shipping fee rule could not be updated');
        }
        try {
            $stmt->execute([
                $normalized['name'],
                $normalized['method'],
                $normalized['min_subtotal_minor'],
                $normalized['fee_minor'],
                $normalized['free_over_minor'],
                $normalized['active'] ? 1 : 0,
                $normalized['priority'],
                date('Y-m-d H:i:s'),
                $scope->storeId,
                $scope->channelId,
                $id,
            ]);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Shipping fee rule conflicts with an existing rule', 0, $exception);
        }
        $updated = $this->find($scope, $id);
        if ($updated === null) {
            throw new \RuntimeException('Shipping fee rule could not be updated');
        }
        return $updated;
    }

    public function delete(CommerceScope $scope, string $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM ext_shipping_fee_rules WHERE store_id = ? AND channel_id = ? AND id = ?');
        if (!$stmt instanceof \PDOStatement) {
            return false;
        }
        $stmt->execute([$scope->storeId, $scope->channelId, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Deterministic fee for a cart subtotal and shipping method. Returns 0
     * when no active rule matches, when the winning rule has free_over_minor
     * greater than 0 and the subtotal reaches it, or when fee_minor is 0.
     */
    public function feeFor(CommerceScope $scope, int $subtotalMinor, string $method): int
    {
        $method = strtolower(trim($method));
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Unsupported shipping method');
        }
        if ($subtotalMinor < 0) {
            throw new \InvalidArgumentException('Subtotal must be a non-negative integer');
        }
        $rule = $this->first(
            'SELECT fee_minor, free_over_minor FROM ext_shipping_fee_rules WHERE store_id = ? AND channel_id = ? AND method = ? AND active = 1 AND min_subtotal_minor <= ? ORDER BY priority DESC, id ASC LIMIT 1',
            [$scope->storeId, $scope->channelId, $method, $subtotalMinor]
        );
        if ($rule === null) {
            return 0;
        }
        $fee = max(0, $this->intFromRow($rule, 'fee_minor'));
        $freeOver = max(0, $this->intFromRow($rule, 'free_over_minor'));
        if ($freeOver > 0 && $subtotalMinor >= $freeOver) {
            return 0;
        }
        return $fee;
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotencyKey(CommerceScope $scope, string $key): ?array
    {
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_shipping_fee_rules WHERE store_id = ? AND channel_id = ? AND idempotency_key = ? LIMIT 1',
            [$scope->storeId, $scope->channelId, $key]
        );
    }

    /** @return array<string, mixed>|null */
    private function findByNameMethod(CommerceScope $scope, string $name, string $method): ?array
    {
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_shipping_fee_rules WHERE store_id = ? AND channel_id = ? AND name = ? AND method = ? LIMIT 1',
            [$scope->storeId, $scope->channelId, $name, $method]
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{name: string, method: string, min_subtotal_minor: int, fee_minor: int, free_over_minor: int, active: bool, priority: int}
     */
    private function normalize(array $data): array
    {
        $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Rule name must be between 2 and 120 characters');
        }
        $methodRaw = isset($data['method']) && is_string($data['method']) ? strtolower(trim($data['method'])) : '';
        if (!in_array($methodRaw, self::METHODS, true)) {
            throw new \InvalidArgumentException('Method must be one of: cod, standard, express');
        }
        $minSubtotal = $this->nonNegativeInt($data, 'min_subtotal_minor', 0);
        $fee = $this->nonNegativeInt($data, 'fee_minor', 0);
        $freeOver = $this->nonNegativeInt($data, 'free_over_minor', 0);
        $priority = $this->priority($data);
        $activeValue = $this->intValue($data['active'] ?? 1);
        if ($activeValue === null || !in_array($activeValue, [0, 1], true)) {
            throw new \InvalidArgumentException('Active must be 0 or 1');
        }
        return [
            'name' => $name,
            'method' => $methodRaw,
            'min_subtotal_minor' => $minSubtotal,
            'fee_minor' => $fee,
            'free_over_minor' => $freeOver,
            'active' => $activeValue === 1,
            'priority' => $priority,
        ];
    }

    /** @param array<string, mixed> $data */
    private function nonNegativeInt(array $data, string $key, int $default): int
    {
        $value = array_key_exists($key, $data) ? $data[$key] : $default;
        $int = $this->intValue($value);
        if ($int === null || $int < 0) {
            throw new \InvalidArgumentException($key . ' must be a non-negative integer');
        }
        return $int;
    }

    /** @param array<string, mixed> $data */
    private function priority(array $data): int
    {
        $value = array_key_exists('priority', $data) ? $data['priority'] : 100;
        $int = $this->intValue($value);
        if ($int === null || $int < 0 || $int > self::MAX_PRIORITY) {
            throw new \InvalidArgumentException('Priority must be between 0 and ' . self::MAX_PRIORITY);
        }
        return $int;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        return null;
    }

    /** @param array<string, mixed> $row */
    private function intFromRow(array $row, string $key): int
    {
        $int = $this->intValue($row[$key] ?? null);
        return $int ?? 0;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = Database::connection()->prepare($sql);
        if (!$stmt instanceof \PDOStatement) {
            return [];
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $clean = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $clean[$key] = $value;
                    }
                }
                $result[] = $clean;
            }
        }
        return $result;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function first(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);
        return $rows[0] ?? null;
    }
}
