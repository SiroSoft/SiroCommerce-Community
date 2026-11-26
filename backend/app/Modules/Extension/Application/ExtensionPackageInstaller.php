<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use ZipArchive;

/**
 * P0 package tooling: verify, install and pack signed extension archives.
 * See docs/contracts/EXTENSION_ECOSYSTEM_SPEC.md sections 3 and 5.
 */
final class ExtensionPackageInstaller
{
    private const MAX_BYTES = 20 * 1024 * 1024;
    private const MAX_FILES = 5000;
    private const METADATA = ['manifest.json', 'checksums.json', 'signature.json'];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $zipPath, bool $requireSignature = false): array
    {
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<string> $warnings */
        $warnings = [];
        $result = [
            'ok' => false, 'errors' => [], 'warnings' => [], 'id' => '', 'version' => '',
            'type' => '', 'provider' => '', 'files' => 0, 'sha256' => '', 'signed' => false, 'manifest' => [],
        ];
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            $errors[] = 'Package archive does not exist or is not readable';
            return $this->report($result, $errors, $warnings);
        }
        $size = filesize($zipPath);
        if ($size === false || $size > self::MAX_BYTES) {
            $errors[] = 'Package archive exceeds the 20 MB limit';
            return $this->report($result, $errors, $warnings);
        }
        $hash = hash_file('sha256', $zipPath);
        $result['sha256'] = is_string($hash) ? $hash : '';

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $errors[] = 'Package archive cannot be opened';
            return $this->report($result, $errors, $warnings);
        }
        try {
            $entries = $this->entries($zip, $errors);
            if ($errors !== []) return $this->report($result, $errors, $warnings);
            $manifestPath = $this->manifestPath($entries);
            if ($manifestPath === null) {
                $errors[] = 'manifest.json is missing from the package root';
                return $this->report($result, $errors, $warnings);
            }
            $prefix = dirname($manifestPath) === '.' ? '' : dirname($manifestPath) . '/';
            $relative = [];
            foreach ($entries as $entry) {
                $name = $prefix === '' ? $entry : (str_starts_with($entry, $prefix) ? substr($entry, strlen($prefix)) : null);
                if ($name === null || $name === '') continue;
                if (!$this->safePath($name)) {
                    $errors[] = 'Package contains an unsafe path: ' . $name;
                    break;
                }
                $relative[$name] = $entry;
            }
            if ($errors !== []) return $this->report($result, $errors, $warnings);
            $manifestRaw = $this->read($zip, $prefix . 'manifest.json', $errors);
            $checksumsRaw = $this->read($zip, $prefix . 'checksums.json', $errors);
            if ($manifestRaw === null || $checksumsRaw === null) return $this->report($result, $errors, $warnings);
            $manifest = $this->manifest($manifestRaw, $errors);
            if ($manifest === null) return $this->report($result, $errors, $warnings);
            $id = $this->stringField($manifest, 'id');
            $version = $this->stringField($manifest, 'version');
            $type = $this->stringField($manifest, 'type');
            $provider = $this->stringField($manifest, 'provider');
            $result['id'] = $id;
            $result['version'] = $version;
            $result['type'] = $type;
            $result['provider'] = $provider;
            $result['manifest'] = $manifest;
            $this->checkTargetDirectory($id, $errors);
            $this->providerFile($relative, $id, $provider, $errors);
            $this->checksums($zip, $relative, $checksumsRaw, $errors);
            $result['signed'] = $this->signature($zip, $prefix, $manifestRaw, $checksumsRaw, $requireSignature, $errors, $warnings);
            $result['files'] = count($relative);
        } finally {
            $zip->close();
        }
        return $this->report($result, $errors, $warnings);
    }

    /** @param array<string, mixed> $result
     * @param list<string> $errors
     * @param list<string> $warnings
     * @return array<string, mixed> */
    private function report(array $result, array $errors, array $warnings): array
    {
        $result['errors'] = $errors;
        $result['warnings'] = $warnings;
        $result['ok'] = $errors === [];
        return $result;
    }

    /** @param array<string, mixed> $data */
    private function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * Install a verified archive. Never enables the extension.
     * @return array{ok: bool, id: string, version: string, path: string, backup: ?string, sha256: string}
     */
    public function install(string $zipPath, bool $force = false, bool $requireSignature = false): array
    {
        $report = $this->verify($zipPath, $requireSignature);
        if ($report['ok'] !== true) {
            $errors = $report['errors'] ?? [];
            $messages = is_array($errors) ? array_values(array_filter($errors, 'is_string')) : [];
            throw new \InvalidArgumentException($messages === [] ? 'Package verification failed' : implode('; ', $messages));
        }
        $id = is_string($report['id'] ?? null) ? $report['id'] : '';
        $target = $this->targetPath($id);
        $backup = '';
        if (is_dir($target)) {
            if (!$force) throw new \RuntimeException('Extension directory already exists; use --force to replace it');
            $backup = $this->backupPath($id);
            if (!@rename($target, $backup)) throw new \RuntimeException('Could not back up the existing extension directory');
        }
        $temp = $this->tempPath();
        try {
            $this->extract($zipPath, $temp);
            if (!is_file($temp . DIRECTORY_SEPARATOR . 'Extension.php')) {
                throw new \RuntimeException('Extension.php is missing from the package root');
            }
            $parent = dirname($target);
            if (!is_dir($parent)) {
                mkdir($parent, 0775, true);
                if (!is_dir($parent)) throw new \RuntimeException('Could not create the extension directory');
            }
            if (!@rename($temp, $target)) throw new \RuntimeException('Could not move the package into app/Extensions');
        } catch (\Throwable $exception) {
            $this->removeTree($temp);
            if ($backup !== '' && !is_dir($target)) @rename($backup, $target);
            throw $exception;
        }
        $version = is_string($report['version'] ?? null) ? $report['version'] : '';
        $sha256 = is_string($report['sha256'] ?? null) ? $report['sha256'] : '';
        return ['ok' => true, 'id' => $id, 'version' => $version, 'path' => $target, 'backup' => $backup === '' ? null : $backup, 'sha256' => $sha256];
    }

    /**
     * Create a checksummed (and optionally signed) package from a directory.
     * @return array{ok: bool, path: string, sha256: string, files: int, signed: bool}
     */
    public function pack(string $sourceDir, ?string $outputPath = null, ?string $keyId = null): array
    {
        $source = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        if (!is_dir($source)) throw new \InvalidArgumentException('Extension source directory does not exist');
        $manifestRaw = @file_get_contents($source . DIRECTORY_SEPARATOR . 'manifest.json');
        if (!is_string($manifestRaw)) throw new \InvalidArgumentException('manifest.json is required to pack an extension');
        $errors = [];
        $manifest = $this->manifest($manifestRaw, $errors);
        if ($manifest === null || $errors !== []) throw new \InvalidArgumentException(implode('; ', $errors));
        $id = $this->stringField($manifest, 'id');
        $version = $this->stringField($manifest, 'version');
        $files = $this->directoryFiles($source, $errors);
        if ($errors !== []) throw new \InvalidArgumentException(implode('; ', $errors));
        $checksums = [];
        foreach ($files as $relative) {
            $hash = hash_file('sha256', $source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if (!is_string($hash)) throw new \RuntimeException('Could not hash ' . $relative);
            $checksums[$relative] = $hash;
        }
        ksort($checksums);
        $checksumsRaw = json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $output = $outputPath ?? ($source . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . str_replace('/', '-', $id) . '-' . $version . '.zip');
        $dir = dirname($output);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('Could not create the output directory');
        $zip = new ZipArchive();
        if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Could not create the package archive');
        try {
            $zip->addFromString('manifest.json', $manifestRaw);
            $zip->addFromString('checksums.json', $checksumsRaw);
            foreach ($files as $relative) {
                $path = $source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $data = file_get_contents($path);
                if (!is_string($data)) throw new \RuntimeException('Could not read ' . $relative);
                $zip->addFromString($relative, $data);
            }
        } finally {
            $zip->close();
        }
        $signed = false;
        $signature = $this->makeSignature($manifestRaw, $checksumsRaw, $keyId);
        if ($signature !== null) {
            $zip = new ZipArchive();
            if ($zip->open($output) === true) {
                $zip->addFromString('signature.json', json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $zip->close();
                $signed = true;
            }
        }
        $hash = hash_file('sha256', $output);
        return ['ok' => true, 'path' => $output, 'sha256' => is_string($hash) ? $hash : '', 'files' => count($files), 'signed' => $signed];
    }

    /** @param list<string> $errors
     * @return list<string> */
    private function entries(ZipArchive $zip, array &$errors): array
    {
        if ($zip->numFiles > self::MAX_FILES) {
            $errors[] = 'Package contains too many files';
            return [];
        }
        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) continue;
            $name = str_replace('\\', '/', (string) $stat['name']);
            if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) continue;
            $total += (int) $stat['size'];
            if ($total > self::MAX_BYTES) {
                $errors[] = 'Uncompressed package exceeds the 20 MB limit';
                return [];
            }
            if (!$this->safePath($name)) {
                $errors[] = 'Package contains an unsafe path: ' . $name;
                return [];
            }
            $entries[] = $name;
        }
        return $entries;
    }

    /** @param list<string> $entries */
    private function manifestPath(array $entries): ?string
    {
        $candidates = array_values(array_filter($entries, static fn (string $name): bool => $name === 'manifest.json' || str_ends_with($name, '/manifest.json')));
        if ($candidates === []) return null;
        usort($candidates, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));
        return $candidates[0];
    }

    /** @param list<string> $errors
     * @return array<string, mixed>|null */
    private function manifest(string $raw, array &$errors): ?array
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors[] = 'manifest.json is not valid JSON';
            return null;
        }
        if (!is_array($data)) {
            $errors[] = 'manifest.json must contain an object';
            return null;
        }
        $schema = $data['schema'] ?? null;
        $id = $data['id'] ?? null;
        $type = $data['type'] ?? null;
        $version = $data['version'] ?? null;
        $provider = $data['provider'] ?? null;
        $requires = $data['requires'] ?? null;
        if ($schema !== 'siro.extension/2') $errors[] = 'manifest.schema must be siro.extension/2';
        if (!is_string($id) || preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#', $id) !== 1) $errors[] = 'manifest.id must be a lowercase vendor/name identifier';
        if (!is_string($type) || !in_array($type, ['module', 'integration', 'theme', 'bridge'], true)) $errors[] = 'manifest.type is invalid';
        if (!is_string($version) || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) $errors[] = 'manifest.version must be major.minor.patch';
        if (!is_string($provider) || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $provider) !== 1) $errors[] = 'manifest.provider is invalid';
        if (!is_array($requires) || !is_string($requires['core'] ?? null) || !is_string($requires['commerce'] ?? null)) $errors[] = 'manifest.requires must declare core and commerce constraints';
        foreach (['migrations', 'routes'] as $field) {
            $items = $data[$field] ?? [];
            if (!is_array($items)) { $errors[] = 'manifest.' . $field . ' must be an array'; continue; }
            foreach ($items as $item) if (!is_string($item) || !$this->safePath($item)) $errors[] = 'manifest.' . $field . ' contains an unsafe path';
        }
        foreach (['capabilities', 'permissions'] as $field) {
            $items = $data[$field] ?? [];
            if (!is_array($items)) { $errors[] = 'manifest.' . $field . ' must be an array'; continue; }
            foreach ($items as $item) if (!is_string($item) || preg_match('/^[a-z0-9][a-z0-9._:-]*$/', $item) !== 1) $errors[] = 'manifest.' . $field . ' contains an invalid value';
        }
        if ($errors !== []) return null;
        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, string> $relative
     * @param list<string> $errors */
    private function providerFile(array $relative, string $id, string $provider, array &$errors): void
    {
        [$vendor, $name] = explode('/', $id, 2);
        $base = 'App\\Extensions\\' . $this->namespacePart($vendor) . '\\' . $this->namespacePart($name) . '\\';
        if (!str_starts_with($provider, $base)) {
            $errors[] = 'manifest.provider must match the package namespace ' . $base;
            return;
        }
        $path = str_replace('\\', '/', substr($provider, strlen($base))) . '.php';
        if (!array_key_exists($path, $relative)) {
            $errors[] = 'manifest.provider file is missing: ' . $path;
        }
    }

    /** @param array<string, string> $relative
     * @param list<string> $errors */
    private function checksums(ZipArchive $zip, array $relative, string $raw, array &$errors): void
    {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $errors[] = 'checksums.json is not valid JSON';
            return;
        }
        if (!is_array($data)) { $errors[] = 'checksums.json must contain an object'; return; }
        $listed = [];
        foreach ($data as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1 || !$this->safePath($path)) {
                $errors[] = 'checksums.json contains an invalid entry';
                continue;
            }
            if (!array_key_exists($path, $relative)) { $errors[] = 'Checksummed file is missing: ' . $path; continue; }
            $bytes = $zip->getFromName($relative[$path]);
            if (!is_string($bytes) || hash('sha256', $bytes) !== $hash) { $errors[] = 'Checksum mismatch: ' . $path; continue; }
            $listed[$path] = true;
        }
        foreach (array_keys($relative) as $path) {
            if (in_array($path, self::METADATA, true)) continue;
            if (!isset($listed[$path])) $errors[] = 'Unchecksummed file: ' . $path;
        }
    }

    /** @param list<string> $errors
     * @param list<string> $warnings */
    private function signature(ZipArchive $zip, string $prefix, string $manifestRaw, string $checksumsRaw, bool $required, array &$errors, array &$warnings): bool
    {
        $raw = $zip->getFromName($prefix . 'signature.json');
        if (!is_string($raw)) {
            if ($required) $errors[] = 'signature.json is required for this package';
            else $warnings[] = 'Package is unsigned';
            return false;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) { $errors[] = 'signature.json is not valid JSON'; return false; }
        if (!is_array($data) || ($data['algorithm'] ?? null) !== 'ed25519' || !is_string($data['key_id'] ?? null) || !is_string($data['signature'] ?? null)) {
            $errors[] = 'signature.json is invalid';
            return false;
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $errors[] = 'Ed25519 verification is unavailable (ext-sodium missing)';
            return false;
        }
        $keys = $this->publicKeys();
        $key = $keys[$data['key_id']] ?? null;
        if ($key === null) { $errors[] = 'Unknown signing key: ' . $data['key_id']; return false; }
        $payload = hash('sha256', $manifestRaw) . "\n" . hash('sha256', $checksumsRaw);
        $signature = base64_decode($data['signature'], true);
        $decodedKey = base64_decode($key, true);
        if (!is_string($signature) || $signature === '' || !is_string($decodedKey) || strlen($decodedKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !sodium_crypto_sign_verify_detached($signature, $payload, $decodedKey)) {
            $errors[] = 'Package signature is invalid';
            return false;
        }
        return true;
    }

    /** @return array<string, string> */
    private function publicKeys(): array
    {
        $keys = [];
        $raw = getenv('EXTENSION_SIGNING_PUBLIC_KEYS');
        if (is_string($raw) && $raw !== '') {
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) foreach ($data as $id => $key) if (is_string($id) && is_string($key)) $keys[$id] = $key;
            } catch (\JsonException) {
                // Invalid key configuration fails closed at verification time.
            }
        }
        $single = getenv('EXTENSION_SIGNING_PUBLIC_KEY');
        if ($keys === [] && is_string($single) && $single !== '') $keys['default'] = $single;
        return $keys;
    }

    /** @return array<string, string>|null */
    private function makeSignature(string $manifestRaw, string $checksumsRaw, ?string $keyId): ?array
    {
        $private = getenv('EXTENSION_SIGNING_PRIVATE_KEY');
        if (!is_string($private) || $private === '') return null;
        if (!function_exists('sodium_crypto_sign_detached')) throw new \RuntimeException('Ed25519 signing is unavailable (ext-sodium missing)');
        $key = base64_decode($private, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new \RuntimeException('EXTENSION_SIGNING_PRIVATE_KEY is invalid');
        $payload = hash('sha256', $manifestRaw) . "\n" . hash('sha256', $checksumsRaw);
        return ['algorithm' => 'ed25519', 'key_id' => $keyId ?? 'default', 'signature' => base64_encode(sodium_crypto_sign_detached($payload, $key))];
    }

    /** @param list<string> $errors
     * @return list<string> */
    private function directoryFiles(string $source, array &$errors): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) continue;
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
            if (in_array($relative, ['checksums.json', 'signature.json'], true)) continue;
            if (str_starts_with($relative, 'dist/') || str_starts_with($relative, '.git/')) continue;
            if (!$this->safePath($relative)) { $errors[] = 'Unsafe path: ' . $relative; continue; }
            $files[] = $relative;
            if (count($files) > self::MAX_FILES) { $errors[] = 'Too many files'; break; }
        }
        sort($files);
        return $files;
    }

    /** @param list<string> $errors */
    private function read(ZipArchive $zip, string $name, array &$errors): ?string
    {
        $data = $zip->getFromName($name);
        if (!is_string($data)) { $errors[] = 'Missing package file: ' . $name; return null; }
        return $data;
    }

    private function extract(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) throw new \RuntimeException('Package archive cannot be opened');
        try {
            $manifestPath = null;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) continue;
                $name = str_replace('\\', '/', (string) $stat['name']);
                if ($name === 'manifest.json' || str_ends_with($name, '/manifest.json')) { $manifestPath = $name; break; }
            }
            $prefix = $manifestPath !== null && dirname($manifestPath) !== '.' ? dirname($manifestPath) . '/' : '';
            if (!mkdir($destination, 0775, true) && !is_dir($destination)) throw new \RuntimeException('Could not create a temporary directory');
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) continue;
                $name = str_replace('\\', '/', (string) $stat['name']);
                if ($name === '' || str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/')) continue;
                if ($prefix !== '' && !str_starts_with($name, $prefix)) continue;
                $relative = $prefix === '' ? $name : substr($name, strlen($prefix));
                if ($relative === '' || !$this->safePath($relative)) continue;
                $target = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) throw new \RuntimeException('Could not create package directory');
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) throw new \RuntimeException('Could not read package entry: ' . $name);
                $out = fopen($target, 'wb');
                if ($out === false) { fclose($stream); throw new \RuntimeException('Could not write package file'); }
                $copied = stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
                if ($copied === false) throw new \RuntimeException('Could not extract package file');
            }
        } finally {
            $zip->close();
        }
    }

    private function targetPath(string $id): string
    {
        [$vendor, $name] = explode('/', $id, 2);
        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Extensions'
            . DIRECTORY_SEPARATOR . $this->namespacePart($vendor) . DIRECTORY_SEPARATOR . $this->namespacePart($name);
    }

    private function backupPath(string $id): string
    {
        $dir = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'extensions-backups';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('Could not create the extension backup directory');
        return $dir . DIRECTORY_SEPARATOR . str_replace('/', '-', $id) . '-' . date('YmdHis');
    }

    private function tempPath(): string
    {
        $dir = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'ext-tmp';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new \RuntimeException('Could not create the extension temp directory');
        return $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));
    }

    /** @param list<string> $errors */
    private function checkTargetDirectory(string $id, array &$errors): void
    {
        [$vendor, $name] = explode('/', $id, 2);
        foreach ([$vendor, $name] as $part) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $part) !== 1 || $this->namespacePart($part) === '') {
                $errors[] = 'Extension id cannot map to a PHP namespace';
            }
        }
    }

    private function namespacePart(string $part): string
    {
        $clean = preg_replace('/[^a-z0-9]+/', ' ', strtolower($part)) ?? '';
        $clean = str_replace(' ', '', ucwords($clean));
        return preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $clean) === 1 ? $clean : '';
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || preg_match('#^[A-Za-z]:#', $path) === 1) return false;
        return preg_match('#^[A-Za-z0-9_./:@+ -]+$#', $path) === 1;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) continue;
            if ($item->isDir()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
