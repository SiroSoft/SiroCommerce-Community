<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Extension\Application\ExtensionLicenseService;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/**
 * Inspect the offline extension license and its entitlement summary.
 *
 * Usage: php siro extension:license [file] [--json]
 *
 * Exit codes: 0 when community or a valid/in-grace license is active, 1 when a
 * configured license is invalid, not yet valid, or expired beyond grace.
 */
final class ExtensionLicenseCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:license';
    public string $description = 'Show offline extension license status and entitlements';

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $json = in_array('--json', $args, true);
        $file = '';
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--') && $file === '') {
                $file = $arg;
            }
        }

        $status = (new ExtensionLicenseService())->status($file !== '' ? $file : null);
        $summary = $this->summary($status);

        if ($json) {
            $this->write((string) json_encode(
                ['license' => $status, 'entitlements' => $summary],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            return $status['ok'] ? 0 : 1;
        }

        $this->highlight('Extension license');
        $this->write('  Edition:     ' . $status['edition']);
        $this->write('  License:     ' . ($status['license_id'] ?? '-'));
        $this->write('  Status:      ' . ($status['ok'] ? 'ok' : 'invalid') . ' (' . $status['reason'] . ')');
        $this->write('  Expires:     ' . ($status['expires_at'] ?? '-'));
        $this->write('  Grace until: ' . ($status['grace_until'] ?? '-'));
        $this->write('  Extensions:  ' . $this->join($summary['extensions']));
        $this->write('  Features:    ' . $this->join($summary['features']));

        return $status['ok'] ? 0 : 1;
    }

    /**
     * @param array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string} $status
     * @return array{edition: string, all_extensions: bool, extensions: array<int, string>, features: array<int, string>}
     */
    private function summary(array $status): array
    {
        return [
            'edition' => $status['edition'],
            'all_extensions' => in_array('*', $status['extensions'], true),
            'extensions' => $status['extensions'],
            'features' => $status['features'],
        ];
    }

    /** @param array<int, string> $values */
    private function join(array $values): string
    {
        if ($values === []) {
            return '-';
        }
        if (in_array('*', $values, true)) {
            return '* (all)';
        }

        return implode(', ', $values);
    }
}
