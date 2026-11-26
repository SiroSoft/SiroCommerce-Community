<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StoreLocator;

use App\Modules\Extension\Application\ExtensionScope;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped store locator rows. Every read and write goes through
 * ExtensionScope so one store/channel can never observe or mutate another's
 * locations. Public reads expose active rows only; admin reads expose all.
 */
final class StoreLocatorService
{
    public const MIN_NAME = 2;
    public const MAX_NAME = 120;
    public const MIN_ADDRESS = 5;
    public const MAX_ADDRESS = 300;
    public const MIN_CITY = 1;
    public const MAX_CITY = 80;
    public const MAX_PHONE = 40;
    public const MAX_HOURS = 120;
    public const MAX_IDEMPOTENCY_KEY = 128;

    private const COLUMNS = 'id, store_id, channel_id, name, address, city, phone, hours, latitude, longitude, active, created_at, updated_at';

    /**
     * Active locations for the storefront footer, sorted deterministically.
     *
     * @return list<array<string, mixed>>
     */
    public function listActive(CommerceScope $scope): array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->presentAll($this->rows(
            'SELECT ' . self::COLUMNS . ' FROM ext_store_locations WHERE ' . $where
            . ' AND active = ? ORDER BY name ASC, city ASC, id ASC',
            array_merge($params, [1])
        ));
    }

    /**
     * Admin table rows, including inactive locations.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(CommerceScope $scope): array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->presentAll($this->rows(
            'SELECT ' . self::COLUMNS . ' FROM ext_store_locations WHERE ' . $where
            . ' ORDER BY active DESC, name ASC, city ASC, id ASC',
            $params
        ));
    }

    /**
     * Create a location. When an Idempotency-Key is supplied the row id is
     * deterministic for the scope so a replay returns the canonical row.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(CommerceScope $scope, array $data, ?string $idempotencyKey = null): array
    {
        $key = $this->cleanIdempotencyKey($idempotencyKey);
        $id = $key !== null ? $this->idempotentId($scope, $key) : Ids::new();

        if ($key !== null) {
            $existing = $this->find($scope, $id);
            if ($existing !== null) {
                return $this->present($existing);
            }
        }

        $normalized = $this->normalize($data, null);
        $now = date('Y-m-d H:i:s');
        $row = ExtensionScope::merge([
            'id' => $id,
            'name' => $normalized['name'],
            'address' => $normalized['address'],
            'city' => $normalized['city'],
            'phone' => $normalized['phone'],
            'hours' => $normalized['hours'],
            'latitude' => $normalized['latitude'],
            'longitude' => $normalized['longitude'],
            'active' => $normalized['active'] ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $scope);

        try {
            Database::table('ext_store_locations')->insert($row);
        } catch (\Throwable $exception) {
            $winner = $this->find($scope, $id);
            if ($winner !== null) {
                return $this->present($winner);
            }
            throw new \RuntimeException('Store location could not be created', 0, $exception);
        }

        $saved = $this->find($scope, $id);
        if ($saved === null) {
            throw new \RuntimeException('Store location could not be created');
        }
        return $this->present($saved);
    }

    /**
     * Partially update a scoped location. Missing fields keep their current
     * value; repeating the same update is a no-op returning the same row.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null Null when the id is not visible in scope.
     */
    public function update(CommerceScope $scope, string $id, array $data): ?array
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Store location id is required');
        }
        $current = $this->find($scope, $id);
        if ($current === null) {
            return null;
        }
        $present = $this->present($current);
        $normalized = $this->normalize($data, $present);
        if ($this->unchanged($present, $normalized)) {
            return $present;
        }

        $now = date('Y-m-d H:i:s');
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'UPDATE ext_store_locations SET name = ?, address = ?, city = ?, phone = ?, hours = ?, latitude = ?, longitude = ?, active = ?, updated_at = ? WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Store location could not be updated');
        }
        $stmt->execute(array_merge([
            $normalized['name'],
            $normalized['address'],
            $normalized['city'],
            $normalized['phone'],
            $normalized['hours'],
            $normalized['latitude'],
            $normalized['longitude'],
            $normalized['active'] ? 1 : 0,
            $now,
        ], $params, [$id]));

        $updated = $this->find($scope, $id);
        if ($updated === null) {
            throw new \RuntimeException('Store location could not be updated');
        }
        return $this->present($updated);
    }

    /**
     * Delete a scoped location. Repeated deletes are safe (idempotent); ids
     * belonging to another store/channel are never deleted.
     */
    public function delete(CommerceScope $scope, string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('Store location id is required');
        }
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'DELETE FROM ext_store_locations WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Store location could not be deleted');
        }
        $stmt->execute(array_merge($params, [$id]));
        return $stmt->rowCount() > 0;
    }

    /** @return array<string, mixed>|null */
    private function find(CommerceScope $scope, string $id): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_store_locations WHERE ' . $where . ' AND id = ? LIMIT 1',
            array_merge($params, [$id])
        );
    }

    private function idempotentId(CommerceScope $scope, string $key): string
    {
        return substr(hash('sha256', $scope->storeId . "\0" . $scope->channelId . "\0" . $key), 0, 26);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     * @return array{name: string, address: string, city: string, phone: ?string, hours: ?string, latitude: ?float, longitude: ?float, active: bool}
     */
    private function normalize(array $data, ?array $current): array
    {
        $latitude = array_key_exists('latitude', $data)
            ? $this->normalizeCoordinate($data['latitude'], 'latitude', -90.0, 90.0)
            : $this->currentCoordinate($current, 'latitude');
        $longitude = array_key_exists('longitude', $data)
            ? $this->normalizeCoordinate($data['longitude'], 'longitude', -180.0, 180.0)
            : $this->currentCoordinate($current, 'longitude');

        if (($latitude === null) !== ($longitude === null)) {
            throw new \InvalidArgumentException('latitude and longitude must be provided together');
        }

        $active = array_key_exists('active', $data)
            ? $this->normalizeActive($data['active'])
            : ($current !== null ? $this->boolFromRow($current['active'] ?? null) : true);

        return [
            'name' => $this->textField($data, 'name', $current, self::MIN_NAME, self::MAX_NAME, true),
            'address' => $this->textField($data, 'address', $current, self::MIN_ADDRESS, self::MAX_ADDRESS, true),
            'city' => $this->textField($data, 'city', $current, self::MIN_CITY, self::MAX_CITY, true),
            'phone' => $this->optionalTextField($data, 'phone', $current, self::MAX_PHONE),
            'hours' => $this->optionalTextField($data, 'hours', $current, self::MAX_HOURS),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'active' => $active,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     */
    private function textField(array $data, string $key, ?array $current, int $min, int $max, bool $required): string
    {
        if (array_key_exists($key, $data)) {
            return $this->cleanText($data[$key], $key, $min, $max);
        }
        if ($current !== null) {
            $value = $current[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        if ($required) {
            throw new \InvalidArgumentException($key . ' is required');
        }
        throw new \InvalidArgumentException($key . ' is invalid');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     */
    private function optionalTextField(array $data, string $key, ?array $current, int $max): ?string
    {
        if (array_key_exists($key, $data)) {
            return $this->cleanOptionalText($data[$key], $key, $max);
        }
        if ($current !== null) {
            $value = $current[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function cleanText(mixed $value, string $field, int $min, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a string');
        }
        $text = trim($value);
        $this->assertPlainText($text, $field);
        $length = mb_strlen($text);
        if ($length < $min || $length > $max) {
            throw new \InvalidArgumentException($field . ' must be between ' . $min . ' and ' . $max . ' characters');
        }
        return $text;
    }

    private function cleanOptionalText(mixed $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a string');
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        $this->assertPlainText($text, $field);
        if (mb_strlen($text) > $max) {
            throw new \InvalidArgumentException($field . ' must be at most ' . $max . ' characters');
        }
        return $text;
    }

    private function normalizeCoordinate(mixed $value, string $field, float $min, float $max): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
        }
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a number');
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($field . ' must be a number');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < $min || $number > $max) {
            throw new \InvalidArgumentException($field . ' must be between ' . $min . ' and ' . $max);
        }
        return $number;
    }

    /** @param array<string, mixed>|null $current */
    private function currentCoordinate(?array $current, string $field): ?float
    {
        if ($current === null) {
            return null;
        }
        $value = $current[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        return null;
    }

    private function normalizeActive(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false'], true)) {
                return false;
            }
        }
        throw new \InvalidArgumentException('active must be a boolean (true/false)');
    }

    /** @param array<string, mixed> $current
     * @param array{name: string, address: string, city: string, phone: ?string, hours: ?string, latitude: ?float, longitude: ?float, active: bool} $normalized */
    private function unchanged(array $current, array $normalized): bool
    {
        return ($current['name'] ?? null) === $normalized['name']
            && ($current['address'] ?? null) === $normalized['address']
            && ($current['city'] ?? null) === $normalized['city']
            && ($current['phone'] ?? null) === $normalized['phone']
            && ($current['hours'] ?? null) === $normalized['hours']
            && $this->sameCoordinate($current['latitude'] ?? null, $normalized['latitude'])
            && $this->sameCoordinate($current['longitude'] ?? null, $normalized['longitude'])
            && $this->boolFromRow($current['active'] ?? null) === $normalized['active'];
    }

    private function sameCoordinate(mixed $current, ?float $next): bool
    {
        if ($next === null) {
            return $this->currentCoordinate(['value' => $current], 'value') === null;
        }
        $value = $this->currentCoordinate(['value' => $current], 'value');
        return $value !== null && abs($value - $next) < 0.0000001;
    }

    private function cleanIdempotencyKey(?string $key): ?string
    {
        $key = $key !== null ? trim($key) : '';
        if ($key === '') {
            return null;
        }
        if (strlen($key) > self::MAX_IDEMPOTENCY_KEY || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Idempotency-Key is invalid');
        }
        return $key;
    }

    private function assertPlainText(string $value, string $field): void
    {
        if (preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new \InvalidArgumentException($field . ' must not contain HTML or control characters');
        }
        if (preg_match('/javascript\s*:/i', $value) === 1) {
            throw new \InvalidArgumentException($field . ' must not contain scripts');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentAll(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->present($row);
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => $this->strFromRow($row, 'id'),
            'store_id' => $this->strFromRow($row, 'store_id'),
            'channel_id' => $this->strFromRow($row, 'channel_id'),
            'name' => $this->strFromRow($row, 'name'),
            'address' => $this->strFromRow($row, 'address'),
            'city' => $this->strFromRow($row, 'city'),
            'phone' => $this->nullableStrFromRow($row, 'phone'),
            'hours' => $this->nullableStrFromRow($row, 'hours'),
            'latitude' => $this->currentCoordinate($row, 'latitude'),
            'longitude' => $this->currentCoordinate($row, 'longitude'),
            'active' => $this->boolFromRow($row['active'] ?? null),
            'created_at' => $this->nullableStrFromRow($row, 'created_at'),
            'updated_at' => $this->nullableStrFromRow($row, 'updated_at'),
        ];
    }

    private function boolFromRow(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    /** @param array<string, mixed> $row */
    private function strFromRow(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $row */
    private function nullableStrFromRow(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $text = is_scalar($value) ? trim((string) $value) : '';
        return $text !== '' ? $text : null;
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
