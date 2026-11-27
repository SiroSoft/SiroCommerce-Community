<?php

declare(strict_types=1);

namespace App\Extensions\Siro\StickyAddToCart;

use App\Modules\Extension\Application\ExtensionScope;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped sticky Add to Cart settings. Every read and write
 * filters by CommerceScope through ExtensionScope (never a hand-written
 * scope fragment). A scope owns at most one settings row (unique
 * store_id + channel_id), so saving is naturally idempotent.
 */
final class StickyAddToCartService
{
    public const MAX_LABEL_LENGTH = 40;
    public const DEFAULT_BUTTON_LABEL = 'Add to Cart';
    public const DEFAULT_SHOW_PRICE = true;
    public const DEFAULT_ACTIVE = false;

    private const COLUMNS = 'id, store_id, channel_id, button_label, show_price, active, created_at, updated_at';

    /** @return array<string, mixed> */
    public function settings(CommerceScope $scope): array
    {
        $row = $this->find($scope);
        if ($row !== null) {
            return $this->present($row);
        }
        return [
            'id' => null,
            'store_id' => $scope->storeId,
            'channel_id' => $scope->channelId,
            'button_label' => self::DEFAULT_BUTTON_LABEL,
            'show_price' => self::DEFAULT_SHOW_PRICE,
            'active' => self::DEFAULT_ACTIVE,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * Public payload for storefront rendering. Never reads across scopes.
     *
     * @return array{active: bool, button_label: string, show_price: bool}
     */
    public function publicSettings(CommerceScope $scope): array
    {
        $settings = $this->settings($scope);
        $active = $settings['active'] === true;
        return [
            'active' => $active,
            'button_label' => $active ? $this->stringValue($settings['button_label'] ?? '') : '',
            'show_price' => $settings['show_price'] === true,
        ];
    }

    /**
     * Upsert the settings for the scope. Replays with the same payload return
     * the same canonical row; a concurrent insert race falls back to an update.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(CommerceScope $scope, array $data): array
    {
        $normalized = $this->normalize($data);
        $existing = $this->find($scope);
        $now = date('Y-m-d H:i:s');

        if ($existing !== null) {
            [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
            $stmt = Database::connection()->prepare(
                'UPDATE ext_sticky_atc_settings SET button_label = ?, show_price = ?, active = ?, updated_at = ? WHERE ' . $where . ' AND id = ?'
            );
            if (!$stmt instanceof \PDOStatement) {
                throw new \RuntimeException('Sticky Add to Cart settings could not be saved');
            }
            $stmt->execute([
                $normalized['button_label'],
                $normalized['show_price'] ? 1 : 0,
                $normalized['active'] ? 1 : 0,
                $now,
                ...$params,
                $this->stringValue($existing['id'] ?? ''),
            ]);
            $saved = $this->find($scope);
            if ($saved === null) {
                throw new \RuntimeException('Sticky Add to Cart settings could not be saved');
            }
            return $this->present($saved);
        }

        $id = Ids::new();
        $payload = ExtensionScope::merge([
            'id' => $id,
            'button_label' => $normalized['button_label'],
            'show_price' => $normalized['show_price'] ? 1 : 0,
            'active' => $normalized['active'] ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $scope);
        try {
            Database::table('ext_sticky_atc_settings')->insert($payload);
        } catch (\Throwable $exception) {
            $winner = $this->find($scope);
            if ($winner !== null) {
                return $this->present($winner);
            }
            throw new \RuntimeException('Sticky Add to Cart settings could not be saved', 0, $exception);
        }

        $saved = $this->find($scope);
        if ($saved === null) {
            throw new \RuntimeException('Sticky Add to Cart settings could not be saved');
        }
        return $this->present($saved);
    }

    /** @return array<string, mixed>|null */
    public function find(CommerceScope $scope): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ext_sticky_atc_settings WHERE ' . $where . ' LIMIT 1'
        );
        if (!$stmt instanceof \PDOStatement) {
            return null;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $clean = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $clean[$key] = $value;
            }
        }
        ExtensionScope::assertScoped($clean, $scope);
        return $clean;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => $this->stringValue($row['id'] ?? null),
            'store_id' => $this->stringValue($row['store_id'] ?? null),
            'channel_id' => $this->stringValue($row['channel_id'] ?? null),
            'button_label' => $this->stringValue($row['button_label'] ?? null) !== '' ? $this->stringValue($row['button_label'] ?? null) : self::DEFAULT_BUTTON_LABEL,
            'show_price' => $this->boolValue($row['show_price'] ?? null) ?? self::DEFAULT_SHOW_PRICE,
            'active' => $this->boolValue($row['active'] ?? null) ?? self::DEFAULT_ACTIVE,
            'created_at' => $this->stringValue($row['created_at'] ?? null),
            'updated_at' => $this->stringValue($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{button_label: string, show_price: bool, active: bool}
     */
    private function normalize(array $data): array
    {
        $label = isset($data['button_label']) && is_string($data['button_label']) ? trim($data['button_label']) : '';
        $length = mb_strlen($label);
        if ($length < 2 || $length > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException('button_label must be between 2 and ' . self::MAX_LABEL_LENGTH . ' characters');
        }

        $showPrice = $this->boolValue($data['show_price'] ?? null);
        if ($showPrice === null) {
            throw new \InvalidArgumentException('show_price must be a boolean');
        }

        $active = $this->boolValue($data['active'] ?? null);
        if ($active === null) {
            throw new \InvalidArgumentException('active must be a boolean');
        }

        return [
            'button_label' => $label,
            'show_price' => $showPrice,
            'active' => $active,
        ];
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private function boolValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
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
        return null;
    }
}
