<?php

declare(strict_types=1);

namespace App\Controllers;

use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Console settings key-value store (skeleton API surface).
 */
final class SettingsController extends Controller
{
    private const KNOWN = ['app_name', 'app_description', 'language', 'timezone', 'currency', 'pagination_per_page', 'maintenance_mode', 'email_notifications'];

    private const DEFAULTS = ['app_name' => 'SiroCommerce', 'app_description' => 'Objects for everyday rituals.', 'language' => 'en', 'timezone' => 'UTC', 'currency' => 'VND', 'pagination_per_page' => 20, 'maintenance_mode' => false, 'email_notifications' => true];

    public function show(Request $request): Response
    {
        return $this->success($this->all(), 'Settings');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'app_name' => 'nullable|string', 'app_description' => 'nullable|string', 'language' => 'nullable|string',
            'timezone' => 'nullable|string', 'currency' => 'nullable|string', 'pagination_per_page' => 'nullable|integer',
            'maintenance_mode' => 'nullable|boolean', 'email_notifications' => 'nullable|boolean',
        ]);
        $pdo = Database::connection();
        $now = date('Y-m-d H:i:s');
        foreach ($data as $key => $value) {
            if (!in_array($key, self::KNOWN, true) || $value === null) continue;
            $stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            $exists = $pdo->prepare('SELECT `key` FROM settings WHERE `key`=?'); $exists->execute([$key]);
            if ($exists->fetchColumn() !== false) {
                $pdo->prepare('UPDATE settings SET value=?,updated_at=? WHERE `key`=?')->execute([$stored, $now, $key]);
            } else {
                $pdo->prepare('INSERT INTO settings (`key`,value,updated_at) VALUES (?,?,?)')->execute([$key, $stored, $now]);
            }
        }
        return $this->success($this->all(), 'Settings updated');
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $out = self::DEFAULTS;
        try {
            foreach (Database::connection()->query('SELECT `key`,value FROM settings')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $key = (string) $row['key'];
                if (!array_key_exists($key, $out)) continue;
                $raw = (string) $row['value'];
                $out[$key] = match ($key) {
                    'pagination_per_page' => (int) $raw,
                    'maintenance_mode', 'email_notifications' => $raw === '1',
                    default => $raw,
                };
            }
        } catch (\Throwable) { }
        return $out;
    }
}
