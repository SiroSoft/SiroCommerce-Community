<?php
declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Support\CommerceScope;
use Siro\Core\Database;

/** Bounded, scope-owned controls. This does not send messages or contain recipient data. */
final class CampaignAutomationService
{
    private const DEFAULTS = ['enabled' => false, 'max_messages_per_day' => 1000, 'quiet_hours_start' => 21, 'quiet_hours_end' => 8, 'allowed_channels' => ['email']];

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function get(CommerceScope $scope): array
    {
        $statement = Database::connection()->prepare('SELECT enabled, max_messages_per_day, quiet_hours_start, quiet_hours_end, allowed_channels_json, updated_at FROM campaign_automation_settings WHERE scope_key = ?');
        $statement->execute([$scope->key()]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) return self::DEFAULTS + ['scope' => $scope->metadata(), 'updated_at' => null];
        $channels = json_decode(is_string($row['allowed_channels_json'] ?? null) ? $row['allowed_channels_json'] : '[]', true);
        return ['enabled' => $this->intValue($row['enabled'] ?? null) === 1, 'max_messages_per_day' => $this->intValue($row['max_messages_per_day'] ?? null, 1000), 'quiet_hours_start' => $this->intValue($row['quiet_hours_start'] ?? null, 21), 'quiet_hours_end' => $this->intValue($row['quiet_hours_end'] ?? null, 8), 'allowed_channels' => is_array($channels) ? array_filter($channels, 'is_string') : ['email'], 'scope' => $scope->metadata(), 'updated_at' => $row['updated_at'] ?? null];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function update(CommerceScope $scope, array $data, string $actor): array
    {
        $current = $this->get($scope);
        $enabled = $this->flag($data, 'enabled', (bool) $current['enabled']);
        $limit = array_key_exists('max_messages_per_day', $data) ? $this->intValue($data['max_messages_per_day']) : \App\Support\Arr::int($current, 'max_messages_per_day', 1000);
        $start = array_key_exists('quiet_hours_start', $data) ? $this->intValue($data['quiet_hours_start']) : \App\Support\Arr::int($current, 'quiet_hours_start', 21);
        $end = array_key_exists('quiet_hours_end', $data) ? $this->intValue($data['quiet_hours_end']) : \App\Support\Arr::int($current, 'quiet_hours_end', 8);
        $channels = array_key_exists('allowed_channels', $data) ? $data['allowed_channels'] : $current['allowed_channels'];
        if ($limit < 0 || $limit > 100000) throw new \InvalidArgumentException('max_messages_per_day must be between 0 and 100000');
        if ($start < 0 || $start > 23 || $end < 0 || $end > 23) throw new \InvalidArgumentException('Quiet hours must be whole hours from 0 to 23');
        $channels = is_array($channels) ? array_values(array_filter($channels, 'is_string')) : [];
        if ($channels === [] || count(array_diff($channels, ['email', 'sms'])) > 0) throw new \InvalidArgumentException('Only email and sms channels are supported');
        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO campaign_automation_settings (scope_key, enabled, max_messages_per_day, quiet_hours_start, quiet_hours_end, allowed_channels_json, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(scope_key) DO UPDATE SET enabled=excluded.enabled, max_messages_per_day=excluded.max_messages_per_day, quiet_hours_start=excluded.quiet_hours_start, quiet_hours_end=excluded.quiet_hours_end, allowed_channels_json=excluded.allowed_channels_json, updated_at=excluded.updated_at, updated_by=excluded.updated_by';
        $driver = Database::connection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (is_string($driver) && strtolower($driver) === 'mysql') $sql = 'INSERT INTO campaign_automation_settings (scope_key, enabled, max_messages_per_day, quiet_hours_start, quiet_hours_end, allowed_channels_json, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), max_messages_per_day=VALUES(max_messages_per_day), quiet_hours_start=VALUES(quiet_hours_start), quiet_hours_end=VALUES(quiet_hours_end), allowed_channels_json=VALUES(allowed_channels_json), updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)';
        Database::connection()->prepare($sql)->execute([$scope->key(), $enabled ? 1 : 0, $limit, $start, $end, json_encode($channels, JSON_THROW_ON_ERROR), $now, substr($actor, 0, 255)]);
        return $this->get($scope);
    }

    /** @param array<string, mixed> $data */
    private function flag(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) return $default;
        if (!is_bool($data[$key])) throw new \InvalidArgumentException($key . ' must be boolean');
        return $data[$key];
    }

    private function intValue(mixed $value, int $default = 0): int { return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default); }
}
