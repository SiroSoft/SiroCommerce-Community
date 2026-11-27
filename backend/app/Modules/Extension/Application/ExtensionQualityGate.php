<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use ZipArchive;

/**
 * P1 Siro QIT-lite: deterministic pre-publish checks for extension archives.
 * It never executes extension code; php -l only parses extracted files.
 */
final class ExtensionQualityGate
{
    private const TEXT_EXTENSIONS = ['php', 'json', 'md', 'txt', 'js', 'ts', 'tsx', 'css', 'yml', 'yaml'];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return array{ok: bool, checks: list<array{name: string, ok: bool, details: list<string>}>}
     */
    public function run(string $zipPath, bool $requireSignature = false): array
    {
        /** @var list<array{name: string, ok: bool, details: list<string>}> $checks */
        $checks = [];
        $installer = new ExtensionPackageInstaller($this->basePath);
        $report = $installer->verify($zipPath, $requireSignature);
        $checks[] = ['name' => 'package', 'ok' => $report['ok'] === true, 'details' => $this->strings($report['errors'] ?? [])];
        $checks[] = ['name' => 'signature', 'ok' => !$requireSignature || ($report['signed'] ?? false) === true, 'details' => $this->strings($report['warnings'] ?? [])];
        if ($report['ok'] !== true) return ['ok' => false, 'checks' => $checks];
        $manifest = is_array($report['manifest'] ?? null) ? $report['manifest'] : [];
        $global = ($manifest['global'] ?? false) === true;

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $checks[] = ['name' => 'archive', 'ok' => false, 'details' => ['Archive cannot be opened']];
            return ['ok' => false, 'checks' => $checks];
        }
        try {
            $entries = $this->entries($zip);
            $checks[] = $this->migrationScope($zip, $entries, $global);
            $checks[] = $this->routeAuth($zip, $entries);
            $checks[] = $this->secrets($zip, $entries);
            $checks[] = $this->lint($zip, $entries);
        } finally {
            $zip->close();
        }
        $ok = true;
        foreach ($checks as $check) if (!$check['ok']) $ok = false;
        return ['ok' => $ok, 'checks' => $checks];
    }

    /** @return list<string> */
    private function entries(ZipArchive $zip): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) continue;
            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) continue;
            $entries[] = $name;
        }
        return $entries;
    }

    /**
     * @param list<string> $entries
     * @return array{name: string, ok: bool, details: list<string>}
     */
    private function migrationScope(ZipArchive $zip, array $entries, bool $global): array
    {
        $details = [];
        $ok = true;
        foreach ($entries as $entry) {
            if (!str_contains($entry, 'migrations/') || !str_ends_with($entry, '.php')) continue;
            $raw = $zip->getFromName($entry);
            if (!is_string($raw)) continue;
            if (preg_match_all("/Schema::create\\s*\\(\\s*['\"]([^'\"]+)['\"]/", $raw, $matches) > 0) {
                foreach ($matches[1] as $table) {
                    if (!str_starts_with($table, 'ext_')) {
                        $ok = false;
                        $details[] = 'Table is not prefixed ext_: ' . $table;
                    }
                }
            }
            if (!$global && preg_match_all("/Schema::create\\s*\\(/", $raw) > 0) {
                if (!str_contains($raw, 'store_id') || !str_contains($raw, 'channel_id')) {
                    $ok = false;
                    $details[] = 'Create migration must include store_id and channel_id (or declare global=true): ' . $entry;
                }
            }
        }
        if ($details === []) $details[] = 'Scope checks passed';
        return ['name' => 'store-scope', 'ok' => $ok, 'details' => $details];
    }

    /**
     * Mutating routes must carry auth/cap middleware — or an explicit
     * 'public' marker. 'public' is a deliberate opt-in for routes that must
     * serve anonymous guests (e.g. a guest subscribe endpoint): the loader
     * mounts them without auth middleware, and QIT accepts the marker
     * instead of auth/cap. It must never be combined with 'auth'/'cap:'
     * (auth wins at runtime, so the combination would be a lie).
     * Tokens are matched exactly in the middleware list, not as
     * substrings of the row line (see lineHasRouteAuth).
     * @param list<string> $entries
     * @return array{name: string, ok: bool, details: list<string>}
     */
    private function routeAuth(ZipArchive $zip, array $entries): array
    {
        $details = [];
        $ok = true;
        foreach ($entries as $entry) {
            if (basename($entry) !== 'routes.php') continue;
            $raw = $zip->getFromName($entry);
            if (!is_string($raw)) continue;
            foreach (preg_split('/\R/', $raw) ?: [] as $line) {
                if (preg_match("/\\['(POST|PUT|PATCH|DELETE)'/", $line) !== 1) continue;
                if (!self::lineHasRouteAuth($line)) {
                    $ok = false;
                    $details[] = 'Mutating route in ' . $entry . ' is missing auth/cap middleware (or an explicit \'public\' marker)';
                }
            }
        }
        if ($details === []) $details[] = 'Route middleware checks passed';
        return ['name' => 'route-auth', 'ok' => $ok, 'details' => $details];
    }

    /**
     * Tokens are matched EXACTLY in the middleware list (the last bracket
     * group without nested brackets on the row line, e.g.
     * ['auth', 'throttle:20,1']), not as substrings of the whole line —
     * so a handler named e.g. 'publicData' cannot satisfy the check.
     */
    private static function lineHasRouteAuth(string $line): bool
    {
        if (preg_match_all('/\[([^\[\]]*)\]/', $line, $matches) === 0) {
            return false;
        }
        /** @var list<string> $groups */
        $groups = $matches[1];
        $last = (string) end($groups);
        foreach (explode(',', $last) as $token) {
            $token = trim($token, " \t\n\r\0\x0B'\"");
            if ($token === 'auth' || $token === 'public' || str_starts_with($token, 'cap:')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $entries
     * @return array{name: string, ok: bool, details: list<string>}
     */
    private function secrets(ZipArchive $zip, array $entries): array
    {
        $details = [];
        foreach ($entries as $entry) {
            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($extension, self::TEXT_EXTENSIONS, true)) continue;
            if (in_array($extension, ['md', 'txt'], true)) continue;
            if (str_starts_with($entry, 'tests/') || str_contains($entry, '/tests/')) continue;
            $raw = $zip->getFromName($entry);
            if (!is_string($raw)) continue;
            if (preg_match('/(api[_-]?key|client[_-]?secret|password|private[_-]?key|access[_-]?token)\s*[:=]\s*["\']([^"\']{12,})["\']/i', $raw) === 1) {
                $details[] = 'Possible hard-coded secret in ' . $entry;
            }
        }
        return ['name' => 'secret-scan', 'ok' => $details === [], 'details' => $details === [] ? ['No hard-coded secrets found'] : $details];
    }

    /**
     * @param list<string> $entries
     * @return array{name: string, ok: bool, details: list<string>}
     */
    private function lint(ZipArchive $zip, array $entries): array
    {
        $temp = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'ext-qit-' . bin2hex(random_bytes(6));
        $details = [];
        $ok = true;
        if (!function_exists('exec')) return ['name' => 'php-lint', 'ok' => true, 'details' => ['exec is disabled; lint skipped']];
        if (!mkdir($temp, 0775, true) && !is_dir($temp)) return ['name' => 'php-lint', 'ok' => false, 'details' => ['Could not create QIT temp directory']];
        try {
            foreach ($entries as $entry) {
                if (!str_ends_with($entry, '.php') || str_contains($entry, '..')) continue;
                $raw = $zip->getFromName($entry);
                if (!is_string($raw)) continue;
                $file = $temp . DIRECTORY_SEPARATOR . 'file-' . md5($entry) . '.php';
                file_put_contents($file, $raw);
                $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1';
                $output = [];
                $code = 0;
                exec($command, $output, $code);
                if ($code !== 0) {
                    $ok = false;
                    $details[] = 'PHP syntax error in ' . $entry . ': ' . implode(' ', array_slice($output, 0, 2));
                }
            }
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                if (!$item instanceof \SplFileInfo) continue;
                if ($item->isDir()) @rmdir($item->getPathname());
                else @unlink($item->getPathname());
            }
            @rmdir($temp);
        }
        return ['name' => 'php-lint', 'ok' => $ok, 'details' => $details === [] ? ['No syntax errors'] : $details];
    }

    /** @param mixed $values
     * @return list<string> */
    private function strings(mixed $values): array
    {
        if (!is_array($values)) return [];
        $result = [];
        foreach ($values as $value) if (is_string($value)) $result[] = $value;
        return $result;
    }
}
