<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Extension\Application\ExtensionLicenseIssuer;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;

/**
 * Issue an offline, signed extension license document.
 *
 * Usage:
 *   php siro extension:license:issue --payload=<payload.json> [--out=<license.json>] [--json] [--force]
 *
 * Without `--out` the license document is written to stdout. `--force` is only
 * required to replace an existing output file. Exit code 1 on validation,
 * signing, or filesystem errors.
 */
final class ExtensionLicenseIssueCommand implements CommandInterface
{
    use CommandSupport;

    public string $signature = 'extension:license:issue';
    public string $description = 'Issue an offline signed extension license (Pro/Enterprise)';

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $json = in_array('--json', $args, true);
        $force = in_array('--force', $args, true);

        $payloadFile = '';
        $out = null;
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, '--payload=')) {
                $payloadFile = substr($arg, strlen('--payload='));
                continue;
            }
            if ($arg === '--payload') {
                $payloadFile = $args[$index + 1] ?? '';
                continue;
            }
            if (str_starts_with($arg, '--out=')) {
                $out = substr($arg, strlen('--out='));
                continue;
            }
            if ($arg === '--out') {
                $out = $args[$index + 1] ?? null;
            }
        }

        if ($payloadFile === '') {
            $this->write('Usage: php siro extension:license:issue --payload=<payload.json> [--out=<license.json>] [--json] [--force]');
            return 1;
        }

        try {
            $payload = $this->readPayload($payloadFile);
            $document = (new ExtensionLicenseIssuer())->issueDocument($payload);
            $license = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return 1;
        }

        $out = $out !== null && trim($out) !== '' ? trim($out) : null;

        if ($out === null) {
            $this->write($license);
            return 0;
        }

        if (file_exists($out) && !$force) {
            $this->error('Refusing to overwrite existing file: ' . $out . ' (use --force)');
            return 1;
        }
        $directory = dirname($out);
        if (!is_dir($directory)) {
            $this->error('Output directory does not exist: ' . $directory);
            return 1;
        }
        if (@file_put_contents($out, $license . PHP_EOL, LOCK_EX) === false) {
            $this->error('Unable to write license file: ' . $out);
            return 1;
        }

        $this->report($document['payload'], $out, $json);

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Payload file not found or unreadable: ' . $path);
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new \RuntimeException('Payload file is empty: ' . $path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Payload file is not valid JSON: ' . $path);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Payload JSON must be an object: ' . $path);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function report(array $payload, string $out, bool $json): void
    {
        $licenseId = is_string($payload['license_id'] ?? null) ? $payload['license_id'] : '';
        $edition = is_string($payload['edition'] ?? null) ? $payload['edition'] : '';
        $expiresAt = is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : '';
        $extensions = $this->stringValues($payload['extensions'] ?? []);

        if ($json) {
            $this->write((string) json_encode([
                'ok' => true,
                'out' => $out,
                'license' => [
                    'license_id' => $licenseId,
                    'customer_id' => is_string($payload['customer_id'] ?? null) ? $payload['customer_id'] : '',
                    'edition' => $edition,
                    'extensions' => $extensions,
                    'features' => $this->stringValues($payload['features'] ?? []),
                    'expires_at' => $expiresAt,
                    'grace_days' => $payload['grace_days'] ?? null,
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return;
        }

        $this->success('Issued license ' . $licenseId . ' (' . $edition . ')');
        $this->write('  File:       ' . $out);
        $this->write('  Expires:    ' . $expiresAt);
        $this->write('  Extensions: ' . $this->join($extensions));
        $this->write('  Verify:     php siro extension:license ' . $out . ' --json');
    }

    /**
     * @return list<string>
     */
    private function stringValues(mixed $value): array
    {
        if ($value === '*') {
            return ['*'];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param list<string> $values
     */
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
