<?php

declare(strict_types=1);

namespace App\Modules\Consent\Domain;

/**
 * Loads the operator-editable consent policy from `config/consent.php`.
 *
 * The file is the single source of truth for the policy version that the
 * storefront stores decisions against and that every consent record echoes.
 */
final class ConsentPolicy
{
    public const DEFAULT_VERSION = '1';

    /** @return array{version: string, updated_at: string, sections: array<int, array{id: string, title: string, body: string}>} */
    public static function all(): array
    {
        $path = dirname(__DIR__, 4) . '/config/consent.php';
        $raw = is_file($path) ? require $path : [];
        /** @var array<string, mixed> $data */
        $data = is_array($raw) ? $raw : [];

        $versionValue = $data['version'] ?? self::DEFAULT_VERSION;
        $version = is_string($versionValue) && trim($versionValue) !== '' ? trim($versionValue) : self::DEFAULT_VERSION;
        $updatedValue = $data['updated_at'] ?? '';
        $updatedAt = is_string($updatedValue) ? $updatedValue : '';

        $sections = [];
        $rawSections = $data['sections'] ?? [];
        if (is_array($rawSections)) {
            foreach ($rawSections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                /** @var array<string, mixed> $section */
                $id = $section['id'] ?? null;
                $title = $section['title'] ?? null;
                $body = $section['body'] ?? null;
                if (!is_string($id) || trim($id) === '' || !is_string($title) || !is_string($body)) {
                    continue;
                }
                $sections[] = ['id' => $id, 'title' => $title, 'body' => $body];
            }
        }

        return ['version' => $version, 'updated_at' => $updatedAt, 'sections' => $sections];
    }

    public static function version(): string
    {
        return self::all()['version'];
    }
}
