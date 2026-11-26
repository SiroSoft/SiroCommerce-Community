<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ContactWidget;

use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;

/**
 * Store/channel scoped floating contact widget settings. Exactly one row per
 * (store_id, channel_id); every read and write filters by CommerceScope.
 * Links are optional; only https URLs (or empty) are accepted.
 */
final class ContactWidgetService
{
    public const DEFAULT_LABEL = 'Contact us';
    public const MAX_LABEL = 40;
    public const MAX_URL = 2048;
    public const MAX_EMAIL = 254;

    private const COLUMNS = 'id, store_id, channel_id, enabled, zalo_url, whatsapp_url, email, label, created_at, updated_at';

    /**
     * Scoped settings, or defaults (disabled) when the scope has no row yet.
     *
     * @return array<string, mixed>
     */
    public function settings(CommerceScope $scope): array
    {
        return $this->present($this->find($scope));
    }

    /**
     * Public storefront payload. Only `enabled`, `links` and `label` are
     * exposed; disabled scopes return enabled=false with null links.
     *
     * @return array<string, mixed>
     */
    public function publicData(CommerceScope $scope): array
    {
        $present = $this->present($this->find($scope));
        $enabled = $present['enabled'] === true;
        return [
            'enabled' => $enabled,
            'links' => [
                'zalo' => $enabled && $present['zalo_url'] !== '' ? $present['zalo_url'] : null,
                'whatsapp' => $enabled && $present['whatsapp_url'] !== '' ? $present['whatsapp_url'] : null,
                'email' => $enabled && $present['email'] !== '' ? $present['email'] : null,
            ],
            'label' => $present['label'],
        ];
    }

    /**
     * Idempotent upsert for the scope. Missing fields keep the current value
     * (or the default), so replaying the same body returns the same settings.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(CommerceScope $scope, array $data): array
    {
        $current = $this->find($scope);
        $present = $this->present($current);

        $normalized = [
            'enabled' => $this->normalizeEnabled(array_key_exists('enabled', $data) ? $data['enabled'] : $present['enabled']),
            'zalo_url' => $this->normalizeUrl(array_key_exists('zalo_url', $data) ? $data['zalo_url'] : $present['zalo_url'], 'Zalo URL'),
            'whatsapp_url' => $this->normalizeUrl(array_key_exists('whatsapp_url', $data) ? $data['whatsapp_url'] : $present['whatsapp_url'], 'WhatsApp URL'),
            'email' => $this->normalizeEmail(array_key_exists('email', $data) ? $data['email'] : $present['email']),
            'label' => $this->normalizeLabel(array_key_exists('label', $data) ? $data['label'] : $present['label']),
        ];

        $now = date('Y-m-d H:i:s');

        if ($current === null) {
            $row = [
                'id' => Ids::new(),
                'store_id' => $scope->storeId,
                'channel_id' => $scope->channelId,
                'enabled' => $normalized['enabled'] ? 1 : 0,
                'zalo_url' => $normalized['zalo_url'],
                'whatsapp_url' => $normalized['whatsapp_url'],
                'email' => $normalized['email'],
                'label' => $normalized['label'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
            try {
                Database::table('ext_contact_widget')->insert($row);
            } catch (\Throwable $exception) {
                $winner = $this->find($scope);
                if ($winner === null) {
                    throw new \RuntimeException('Contact widget could not be saved', 0, $exception);
                }
                return $this->updateExisting($scope, $winner, $normalized, $now);
            }
            $saved = $this->find($scope);
            if ($saved === null) {
                throw new \RuntimeException('Contact widget could not be saved');
            }
            return $this->present($saved);
        }

        return $this->updateExisting($scope, $current, $normalized, $now);
    }

    /**
     * @param array<string, mixed> $current
     * @param array{enabled: bool, zalo_url: string, whatsapp_url: string, email: string, label: string} $normalized
     * @return array<string, mixed>
     */
    private function updateExisting(CommerceScope $scope, array $current, array $normalized, string $now): array
    {
        $id = is_string($current['id'] ?? null) ? $current['id'] : '';
        if ($id === '') {
            throw new \RuntimeException('Contact widget could not be saved');
        }
        $stmt = Database::connection()->prepare(
            'UPDATE ext_contact_widget SET enabled = ?, zalo_url = ?, whatsapp_url = ?, email = ?, label = ?, updated_at = ? WHERE store_id = ? AND channel_id = ? AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Contact widget could not be saved');
        }
        $stmt->execute([
            $normalized['enabled'] ? 1 : 0,
            $normalized['zalo_url'],
            $normalized['whatsapp_url'],
            $normalized['email'],
            $normalized['label'],
            $now,
            $scope->storeId,
            $scope->channelId,
            $id,
        ]);
        $saved = $this->find($scope);
        if ($saved === null) {
            throw new \RuntimeException('Contact widget could not be saved');
        }
        return $this->present($saved);
    }

    /** @return array<string, mixed>|null */
    private function find(CommerceScope $scope): ?array
    {
        return $this->first(
            'SELECT ' . self::COLUMNS . ' FROM ext_contact_widget WHERE store_id = ? AND channel_id = ? LIMIT 1',
            [$scope->storeId, $scope->channelId]
        );
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function present(?array $row): array
    {
        if ($row === null) {
            return [
                'id' => '',
                'store_id' => '',
                'channel_id' => '',
                'enabled' => false,
                'zalo_url' => '',
                'whatsapp_url' => '',
                'email' => '',
                'label' => self::DEFAULT_LABEL,
                'updated_at' => null,
            ];
        }

        $label = $this->strFromRow($row, 'label');
        return [
            'id' => $this->strFromRow($row, 'id'),
            'store_id' => $this->strFromRow($row, 'store_id'),
            'channel_id' => $this->strFromRow($row, 'channel_id'),
            'enabled' => $this->boolFromRow($row['enabled'] ?? null),
            'zalo_url' => $this->strFromRow($row, 'zalo_url'),
            'whatsapp_url' => $this->strFromRow($row, 'whatsapp_url'),
            'email' => $this->strFromRow($row, 'email'),
            'label' => $label !== '' ? $label : self::DEFAULT_LABEL,
            'updated_at' => is_string($row['updated_at'] ?? null) ? $row['updated_at'] : null,
        ];
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
        throw new \InvalidArgumentException('Enabled must be a boolean (true/false)');
    }

    private function normalizeUrl(mixed $value, string $field): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a string');
        }
        $url = trim($value);
        if ($url === '') {
            return '';
        }
        if (mb_strlen($url) > self::MAX_URL) {
            throw new \InvalidArgumentException($field . ' must be at most ' . self::MAX_URL . ' characters');
        }
        if (preg_match('/\s/', $url) === 1) {
            throw new \InvalidArgumentException($field . ' must be a valid URL');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException($field . ' must be an https URL');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException($field . ' must be a valid URL');
        }
        return $url;
    }

    private function normalizeEmail(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Email must be a string');
        }
        $email = trim($value);
        if ($email === '') {
            return '';
        }
        if (mb_strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Email must be a valid email address');
        }
        return $email;
    }

    private function normalizeLabel(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Label must be a string');
        }
        $label = trim($value);
        $length = mb_strlen($label);
        if ($length < 1 || $length > self::MAX_LABEL) {
            throw new \InvalidArgumentException('Label must be between 1 and ' . self::MAX_LABEL . ' characters');
        }
        return $label;
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
