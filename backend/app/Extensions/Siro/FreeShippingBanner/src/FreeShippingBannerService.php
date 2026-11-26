<?php

declare(strict_types=1);

namespace App\Extensions\Siro\FreeShippingBanner;

use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped free-shipping banner settings. Every read and write
 * filters by CommerceScope. A scope owns at most one banner row (unique
 * store_id + channel_id), so saving is naturally idempotent.
 */
final class FreeShippingBannerService
{
    public const MAX_MESSAGE_LENGTH = 280;
    public const DEFAULT_MESSAGE = 'Free shipping on qualifying orders';
    public const DEFAULT_THRESHOLD_MINOR = 0;
    public const DEFAULT_CURRENCY = 'VND';

    private const COLUMNS = 'id, store_id, channel_id, threshold_minor, message, active, currency, created_at, updated_at';

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
            'threshold_minor' => self::DEFAULT_THRESHOLD_MINOR,
            'message' => self::DEFAULT_MESSAGE,
            'active' => false,
            'currency' => $scope->currency !== '' ? $scope->currency : self::DEFAULT_CURRENCY,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * Public payload for storefront rendering. Never reads across scopes.
     *
     * @return array{active: bool, message: string, threshold: int, threshold_minor: int, currency: string}
     */
    public function publicSettings(CommerceScope $scope): array
    {
        $settings = $this->settings($scope);
        $active = $settings['active'] === true;
        $threshold = $this->intValue($settings['threshold_minor'] ?? null) ?? 0;
        return [
            'active' => $active,
            'message' => $active ? $this->stringValue($settings['message'] ?? '') : '',
            'threshold' => $threshold,
            'threshold_minor' => $threshold,
            'currency' => $this->stringValue($settings['currency'] ?? self::DEFAULT_CURRENCY),
        ];
    }

    /**
     * Upsert the banner for the scope. Replays with the same payload return the
     * same canonical row; a concurrent insert race falls back to an update.
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
            $stmt = Database::connection()->prepare(
                'UPDATE ext_free_shipping_banner SET threshold_minor = ?, message = ?, active = ?, currency = ?, updated_at = ? WHERE store_id = ? AND channel_id = ? AND id = ?'
            );
            if (!$stmt instanceof \PDOStatement) {
                throw new \RuntimeException('Free shipping banner could not be saved');
            }
            $stmt->execute([
                $normalized['threshold_minor'],
                $normalized['message'],
                $normalized['active'] ? 1 : 0,
                $normalized['currency'],
                $now,
                $scope->storeId,
                $scope->channelId,
                $this->stringValue($existing['id'] ?? ''),
            ]);
            $saved = $this->find($scope);
            if ($saved === null) {
                throw new \RuntimeException('Free shipping banner could not be saved');
            }
            return $this->present($saved);
        }

        $id = Ids::new();
        try {
            Database::table('ext_free_shipping_banner')->insert([
                'id' => $id,
                'store_id' => $scope->storeId,
                'channel_id' => $scope->channelId,
                'threshold_minor' => $normalized['threshold_minor'],
                'message' => $normalized['message'],
                'active' => $normalized['active'] ? 1 : 0,
                'currency' => $normalized['currency'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            $winner = $this->find($scope);
            if ($winner !== null) {
                return $this->present($winner);
            }
            throw new \RuntimeException('Free shipping banner could not be saved', 0, $exception);
        }

        $saved = $this->find($scope);
        if ($saved === null) {
            throw new \RuntimeException('Free shipping banner could not be saved');
        }
        return $this->present($saved);
    }

    /** @return array<string, mixed>|null */
    public function find(CommerceScope $scope): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ext_free_shipping_banner WHERE store_id = ? AND channel_id = ? LIMIT 1'
        );
        if (!$stmt instanceof \PDOStatement) {
            return null;
        }
        $stmt->execute([$scope->storeId, $scope->channelId]);
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
        return $clean;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{threshold_minor: int, message: string, active: bool, currency: string}
     */
    private function normalize(array $data): array
    {
        $threshold = $this->intValue($data['threshold_minor'] ?? null);
        if ($threshold === null || $threshold < 0) {
            throw new \InvalidArgumentException('threshold_minor must be a non-negative integer');
        }

        $message = isset($data['message']) && is_string($data['message']) ? trim($data['message']) : '';
        $length = mb_strlen($message);
        if ($length < 2 || $length > self::MAX_MESSAGE_LENGTH) {
            throw new \InvalidArgumentException('message must be between 2 and ' . self::MAX_MESSAGE_LENGTH . ' characters');
        }

        $currency = isset($data['currency']) && is_string($data['currency']) ? strtoupper(trim($data['currency'])) : '';
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('currency must be a 3-letter code');
        }

        $active = $this->boolValue($data['active'] ?? null);
        if ($active === null) {
            throw new \InvalidArgumentException('active must be a boolean');
        }

        return [
            'threshold_minor' => $threshold,
            'message' => $message,
            'active' => $active,
            'currency' => $currency,
        ];
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        return null;
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $active = $this->boolValue($row['active'] ?? null) ?? false;
        return [
            'id' => $this->stringValue($row['id'] ?? ''),
            'store_id' => $this->stringValue($row['store_id'] ?? ''),
            'channel_id' => $this->stringValue($row['channel_id'] ?? ''),
            'threshold_minor' => $this->intValue($row['threshold_minor'] ?? null) ?? 0,
            'message' => $this->stringValue($row['message'] ?? ''),
            'active' => $active,
            'currency' => $this->stringValue($row['currency'] ?? self::DEFAULT_CURRENCY),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return '';
    }
}
