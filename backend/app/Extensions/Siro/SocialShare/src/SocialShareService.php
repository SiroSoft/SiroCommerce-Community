<?php

declare(strict_types=1);

namespace App\Extensions\Siro\SocialShare;

use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped PDP social-share settings. A scope owns at most one row
 * (UNIQUE store_id + channel_id), and every read/write filters by CommerceScope.
 * Networks are stored as a JSON array limited to the allowlist below.
 */
final class SocialShareService
{
    /** @var list<string> */
    public const NETWORKS = ['facebook', 'x', 'whatsapp', 'telegram', 'email', 'copy'];

    /** @var list<string> */
    public const DEFAULT_NETWORKS = self::NETWORKS;

    public const DEFAULT_LABEL = 'Share this product';
    public const MAX_LABEL = 60;
    public const MAX_NETWORKS = 6;

    private const COLUMNS = 'id, store_id, channel_id, enabled, networks, label, created_at, updated_at';

    /** @return array<string, mixed> */
    public function settings(CommerceScope $scope): array
    {
        $row = $this->find($scope);
        if ($row !== null) {
            return $this->present($row);
        }
        return $this->defaults($scope);
    }

    /**
     * Public storefront payload. Disabled scopes expose no networks.
     *
     * @return array{enabled: bool, networks: list<string>, label: string}
     */
    public function publicData(CommerceScope $scope): array
    {
        $settings = $this->settings($scope);
        $enabled = ($settings['enabled'] ?? false) === true;
        return [
            'enabled' => $enabled,
            'networks' => $enabled ? $this->stringList($settings['networks'] ?? null) : [],
            'label' => is_string($settings['label'] ?? null) ? $settings['label'] : self::DEFAULT_LABEL,
        ];
    }

    /**
     * Idempotent upsert for the scope. Missing fields keep the current value (or
     * the default), so replaying the same body returns the same canonical row.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(CommerceScope $scope, array $data): array
    {
        $current = $this->find($scope);
        $present = $current !== null ? $this->present($current) : $this->defaults($scope);

        $currentEnabled = ($present['enabled'] ?? false) === true;
        $currentNetworks = $this->stringList($present['networks'] ?? null);
        $currentLabel = is_string($present['label'] ?? null) ? $present['label'] : self::DEFAULT_LABEL;

        /** @var array{enabled: bool, networks: list<string>, label: string} $normalized */
        $normalized = [
            'enabled' => array_key_exists('enabled', $data) ? $this->normalizeEnabled($data['enabled']) : $currentEnabled,
            'networks' => array_key_exists('networks', $data) ? $this->normalizeNetworks($data['networks']) : $currentNetworks,
            'label' => array_key_exists('label', $data) ? $this->normalizeLabel($data['label']) : $currentLabel,
        ];

        $networksJson = json_encode($normalized['networks'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = date('Y-m-d H:i:s');

        if ($current !== null) {
            return $this->updateExisting($scope, $current, $normalized, $networksJson, $now);
        }

        try {
            Database::table('ext_social_share')->insert([
                'id' => Ids::new(),
                'store_id' => $scope->storeId,
                'channel_id' => $scope->channelId,
                'enabled' => $normalized['enabled'] ? 1 : 0,
                'networks' => $networksJson,
                'label' => $normalized['label'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $exception) {
            $winner = $this->find($scope);
            if ($winner === null) {
                throw new \RuntimeException('Social share settings could not be saved', 0, $exception);
            }
            return $this->updateExisting($scope, $winner, $normalized, $networksJson, $now);
        }

        $saved = $this->find($scope);
        if ($saved === null) {
            throw new \RuntimeException('Social share settings could not be saved');
        }
        return $this->present($saved);
    }

    /**
     * @param array<string, mixed> $current
     * @param array{enabled: bool, networks: list<string>, label: string} $normalized
     * @return array<string, mixed>
     */
    private function updateExisting(CommerceScope $scope, array $current, array $normalized, string $networksJson, string $now): array
    {
        $id = is_string($current['id'] ?? null) ? $current['id'] : '';
        if ($id === '') {
            throw new \RuntimeException('Social share settings could not be saved');
        }
        $stmt = Database::connection()->prepare(
            'UPDATE ext_social_share SET enabled = ?, networks = ?, label = ?, updated_at = ? WHERE store_id = ? AND channel_id = ? AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Social share settings could not be saved');
        }
        $stmt->execute([
            $normalized['enabled'] ? 1 : 0,
            $networksJson,
            $normalized['label'],
            $now,
            $scope->storeId,
            $scope->channelId,
            $id,
        ]);

        $saved = $this->find($scope);
        if ($saved === null) {
            throw new \RuntimeException('Social share settings could not be saved');
        }
        return $this->present($saved);
    }

    /** @return array<string, mixed>|null */
    private function find(CommerceScope $scope): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ext_social_share WHERE store_id = ? AND channel_id = ? LIMIT 1'
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

    /** @return array<string, mixed> */
    private function defaults(CommerceScope $scope): array
    {
        return [
            'id' => null,
            'store_id' => $scope->storeId,
            'channel_id' => $scope->channelId,
            'enabled' => false,
            'networks' => self::DEFAULT_NETWORKS,
            'label' => self::DEFAULT_LABEL,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $label = $this->stringValue($row['label'] ?? '');
        return [
            'id' => $this->stringValue($row['id'] ?? ''),
            'store_id' => $this->stringValue($row['store_id'] ?? ''),
            'channel_id' => $this->stringValue($row['channel_id'] ?? ''),
            'enabled' => $this->boolValue($row['enabled'] ?? null),
            'networks' => $this->decodeNetworks($row['networks'] ?? null),
            'label' => $label !== '' ? $label : self::DEFAULT_LABEL,
            'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : null,
            'updated_at' => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
        ];
    }

    /** @return list<string> */
    private function decodeNetworks(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            $value = $decoded;
        }
        return $this->stringList($value);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $networks = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $network = strtolower(trim($item));
            if (!in_array($network, self::NETWORKS, true) || in_array($network, $networks, true)) {
                continue;
            }
            $networks[] = $network;
        }
        return $networks;
    }

    /** @return list<string> */
    private function normalizeNetworks(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('networks must be a JSON array of network names');
        }
        $networks = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('networks must contain only strings');
            }
            $network = strtolower(trim($item));
            if (!in_array($network, self::NETWORKS, true)) {
                throw new \InvalidArgumentException('networks contains an unsupported network');
            }
            if (in_array($network, $networks, true)) {
                throw new \InvalidArgumentException('networks must not contain duplicates');
            }
            $networks[] = $network;
        }
        if (count($networks) > self::MAX_NETWORKS) {
            throw new \InvalidArgumentException('networks may contain at most ' . self::MAX_NETWORKS . ' entries');
        }
        return $networks;
    }

    private function normalizeEnabled(mixed $value): bool
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
        throw new \InvalidArgumentException('enabled must be a boolean (true/false)');
    }

    private function normalizeLabel(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('label must be a string');
        }
        $label = trim($value);
        $length = mb_strlen($label);
        if ($length < 1 || $length > self::MAX_LABEL) {
            throw new \InvalidArgumentException('label must be between 1 and ' . self::MAX_LABEL . ' characters');
        }
        return $label;
    }

    private function boolValue(mixed $value): bool
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
