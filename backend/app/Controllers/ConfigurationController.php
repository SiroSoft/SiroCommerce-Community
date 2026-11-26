<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Configuration\Domain\ConfigurationRegistry;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

final class ConfigurationController extends Controller
{
    public function index(Request $request): Response
    {
        $groups = ConfigurationRegistry::groups();
        $values = $this->allValues($request);
        return $this->success(['groups' => $groups, 'values' => $values], 'Configuration');
    }

    public function publicConfig(Request $request): Response
    {
        // Public read for storefront: only returns custom.promo_banner when enabled (presentation-only)
        $storeId = $this->storeId($request);
        $channelId = $this->channelId($request);
        $groupKey = 'custom';
        $sectionKey = 'promo_banner';
        $values = $this->valuesForSection($request, $groupKey, $sectionKey);
        $found = ConfigurationRegistry::find($groupKey, $sectionKey);
        if ($found === null) return $this->success(null, 'No promo banner');
        $merged = [];
        foreach ($found['section']['fields'] as $f) {
            $k = $groupKey . '.' . $sectionKey . '.' . $f['key'];
            $merged[$f['key']] = $values[$k] ?? ($f['default'] ?? null);
        }
        $enabled = $merged['enabled'] ?? false;
        if (!$enabled) return $this->success(null, 'Promo banner disabled');
        // sanitize before public
        if (is_string($merged['title'] ?? null) && preg_match('/(?:javascript|data|<script)/i', (string) $merged['title']) === 1) $merged['title'] = '';
        if (is_string($merged['image'] ?? null) && $merged['image'] !== '' && !str_starts_with((string) $merged['image'], '/uploads/') && filter_var((string) $merged['image'], FILTER_VALIDATE_URL) === false) $merged['image'] = '';
        return $this->success(['promo_banner' => $merged], 'Promo banner');
    }

    public function showGroup(Request $request): Response
    {
        $groupKey = Arr::requiredParam($request, 'group');
        $groups = ConfigurationRegistry::groups();
        foreach ($groups as $g) if ($g['key'] === $groupKey) {
            $values = $this->allValues($request);
            // filter values for group
            $filtered = [];
            foreach ($values as $k => $v) if (str_starts_with($k, $groupKey . '.')) $filtered[$k] = $v;
            return $this->success(['group' => $g, 'values' => $filtered], 'Configuration group');
        }
        return $this->error('Group not found', 404);
    }

    public function showSection(Request $request): Response
    {
        $groupKey = Arr::requiredParam($request, 'group');
        $sectionKey = Arr::requiredParam($request, 'section');
        $found = ConfigurationRegistry::find($groupKey, $sectionKey);
        if ($found === null) return $this->error('Section not found', 404);
        $values = $this->valuesForSection($request, $groupKey, $sectionKey);
        $section = $found['section'];
        // merge defaults
        $merged = [];
        foreach ($section['fields'] as $f) {
            $k = $groupKey . '.' . $sectionKey . '.' . $f['key'];
            $merged[$f['key']] = $values[$k] ?? ($f['default'] ?? null);
        }
        $version = $this->sectionVersion($request, $groupKey, $sectionKey);
        return $this->success(['group' => $found['group'], 'section' => $section, 'values' => $merged, 'version' => $version], 'Configuration section');
    }

    public function update(Request $request): Response
    {
        $groupKey = Arr::requiredParam($request, 'group');
        $sectionKey = Arr::requiredParam($request, 'section');
        $found = ConfigurationRegistry::find($groupKey, $sectionKey);
        if ($found === null) return $this->error('Section not found', 404);
        $section = $found['section'];
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'values' => 'required|array',
            'expected_version' => 'nullable|integer',
        ]);
        $values = $data['values'] ?? [];
        if (!is_array($values)) return $this->error('Values must be an object', 422);
        $expected = Arr::int($data, 'expected_version', -1);
        $currentVersion = $this->sectionVersion($request, $groupKey, $sectionKey);
        if ($expected !== -1 && $expected !== $currentVersion) return $this->error('Configuration version is stale', 409, ['expected_version' => [$currentVersion]]);
        // Validate each field
        $fieldMap = [];
        foreach ($section['fields'] as $f) $fieldMap[$f['key']] = $f;
        foreach ($values as $k => $v) {
            if (!isset($fieldMap[$k])) return $this->error("Unknown field: $k", 422, ['values' => ["Unknown field $k"]]);
            $field = $fieldMap[$k];
            $err = $this->validateField($field, $v);
            if ($err !== null) return $this->error($err, 422, ['values' => [$err]]);
        }
        // Idempotency
        $idempotency = (string) $request->header('Idempotency-Key', '');
        $hasIdempotency = $idempotency !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $idempotency) === 1;
        $storeId = $this->storeId($request);
        $channelId = $this->channelId($request);
        $scopeKey = $storeId . ':' . $channelId . ':' . $groupKey . ':' . $sectionKey;
        if ($hasIdempotency) {
            try {
                $existing = Database::table('configuration_values')->where('id', hash('sha256', $idempotency))->first();
                if ($existing !== null) {
                    $vals = $this->valuesForSection($request, $groupKey, $sectionKey);
                    $ver = $this->sectionVersion($request, $groupKey, $sectionKey);
                    return $this->success(['values' => $vals, 'version' => $ver], 'Configuration saved (idempotent)');
                }
            } catch (\Throwable $e) {}
        }
        $now = date('Y-m-d H:i:s');
        $user = $request->user();
        $actor = is_array($user) ? Arr::str($user, 'id', 'system') : 'system';
        $nextVersion = $currentVersion + 1;
        foreach ($values as $k => $v) {
            $field = $fieldMap[$k];
            $type = $field['type'] ?? 'text';
            $stored = $this->encodeValue($type, $v);
            // upsert
            $existingRow = null;
            try { $existingRow = Database::table('configuration_values')->where('group_key', $groupKey)->where('section_key', $sectionKey)->where('field_key', $k)->where('store_id', $storeId)->where('channel_id', $channelId)->first(); } catch (\Throwable $e) {}
            if ($existingRow !== null) {
                $id = Arr::str(Arr::row($existingRow), 'id');
                Database::table('configuration_values')->where('id', $id)->update(['value' => $stored, 'version' => $nextVersion, 'updated_by' => $actor, 'updated_at' => $now]);
            } else {
                Database::table('configuration_values')->insert([
                    'id' => \App\Support\Ids::new(),
                    'group_key' => $groupKey,
                    'section_key' => $sectionKey,
                    'field_key' => $k,
                    'store_id' => $storeId,
                    'channel_id' => $channelId,
                    'value' => $stored,
                    'version' => $nextVersion,
                    'updated_by' => $actor,
                    'updated_at' => $now,
                ]);
            }
        }
        // If has idempotency, store a marker row to make replay idempotent (optional)
        // We already check via hash, but we can store a separate idempotency marker if needed
        $vals = $this->valuesForSection($request, $groupKey, $sectionKey);
        $ver = $this->sectionVersion($request, $groupKey, $sectionKey);
        return $this->success(['values' => $vals, 'version' => $ver], 'Configuration saved');
    }

    private function validateField(array $field, mixed $value): ?string
    {
        $type = $field['type'] ?? 'text';
        $max = (int) ($field['max'] ?? 512);
        $label = $field['label'] ?? $field['key'];
        if ($type === 'boolean') {
            if (!is_bool($value) && $value !== 0 && $value !== 1 && $value !== '0' && $value !== '1') return "$label must be boolean";
            return null;
        }
        if ($type === 'number') {
            if (!is_int($value) && !is_numeric($value)) return "$label must be a number";
            return null;
        }
        if ($type === 'select') {
            $options = $field['options'] ?? [];
            if (!in_array($value, $options, true)) return "$label must be one of " . implode(', ', $options);
            return null;
        }
        if (!is_string($value) && !is_numeric($value) && $value !== null) return "$label must be a string";
        $str = (string) $value;
        if (isset($field['required']) && $field['required'] && trim($str) === '') return "$label is required";
        if (strlen($str) > $max) return "$label is too long (max $max)";
        if (preg_match('/(?:javascript|data|<script|<style)/i', $str) === 1) return "$label contains forbidden content";
        if ($type === 'image' || $type === 'url') {
            if ($str !== '' && strlen($str) > $max) return "$label is too long";
            if ($str !== '') {
                $isUpload = str_starts_with($str, '/uploads/');
                $isHttp = filter_var($str, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $str) === 1;
                if (!$isUpload && !$isHttp) return "$label must be http(s) or /uploads/...";
            }
        }
        if ($type === 'color') {
            if ($str !== '' && preg_match('/^#[0-9A-Fa-f]{6}$/', $str) !== 1) return "$label must be a hex color #RRGGBB";
        }
        return null;
    }

    private function encodeValue(string $type, mixed $value): string
    {
        if ($type === 'boolean') return $value ? '1' : '0';
        if (is_bool($value)) return $value ? '1' : '0';
        return (string) $value;
    }

    private function decodeValue(string $type, string $raw): mixed
    {
        if ($type === 'boolean') return $raw === '1' || $raw === 'true';
        if ($type === 'number') return is_numeric($raw) ? (int) $raw : $raw;
        return $raw;
    }

    /** @return array<string, mixed> */
    private function allValues(Request $request): array
    {
        $storeId = $this->storeId($request);
        $channelId = $this->channelId($request);
        $out = [];
        try {
            foreach (Database::table('configuration_values')->where('store_id', $storeId)->where('channel_id', $channelId)->get() as $row) {
                $r = Arr::row($row);
                $k = Arr::str($r, 'group_key') . '.' . Arr::str($r, 'section_key') . '.' . Arr::str($r, 'field_key');
                $type = $this->fieldType($r);
                $out[$k] = $this->decodeValue($type, Arr::str($r, 'value'));
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /** @return array<string, mixed> */
    private function valuesForSection(Request $request, string $groupKey, string $sectionKey): array
    {
        $storeId = $this->storeId($request);
        $channelId = $this->channelId($request);
        $out = [];
        try {
            foreach (Database::table('configuration_values')->where('group_key', $groupKey)->where('section_key', $sectionKey)->where('store_id', $storeId)->where('channel_id', $channelId)->get() as $row) {
                $r = Arr::row($row);
                $k = $groupKey . '.' . $sectionKey . '.' . Arr::str($r, 'field_key');
                $type = $this->fieldType($r);
                $out[$k] = $this->decodeValue($type, Arr::str($r, 'value'));
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    private function fieldType(array $row): string
    {
        $gk = Arr::str($row, 'group_key');
        $sk = Arr::str($row, 'section_key');
        $fk = Arr::str($row, 'field_key');
        $found = ConfigurationRegistry::find($gk, $sk);
        if ($found === null) return 'text';
        foreach ($found['section']['fields'] as $f) if ($f['key'] === $fk) return $f['type'] ?? 'text';
        return 'text';
    }

    private function sectionVersion(Request $request, string $groupKey, string $sectionKey): int
    {
        $storeId = $this->storeId($request);
        $channelId = $this->channelId($request);
        try {
            $row = Database::table('configuration_values')->where('group_key', $groupKey)->where('section_key', $sectionKey)->where('store_id', $storeId)->where('channel_id', $channelId)->orderBy('version', 'desc')->first();
            if ($row !== null) return Arr::int(Arr::row($row), 'version', 0);
        } catch (\Throwable $e) {}
        return 0;
    }

    private function storeId(Request $request): string
    {
        $v = trim((string) $request->header('X-Store-Id', 'default'));
        return $v !== '' ? $v : 'default';
    }

    private function channelId(Request $request): string
    {
        $v = trim((string) $request->header('X-Channel-Id', 'web'));
        return $v !== '' ? $v : 'web';
    }
}
