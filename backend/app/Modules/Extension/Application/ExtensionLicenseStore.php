<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use Siro\Core\Env;

/**
 * Private storage for the active extension license envelope.
 *
 * The portal writes `BASE_PATH/storage/licenses/active.json` (mode 0600, never
 * under the public web root). Writes go through a same-directory temporary file
 * and rename to avoid exposing a partially written document. The raw license is
 * never logged and this class never exposes key material.
 *
 * `EXTENSION_LICENSE_FILE` remains the runtime override; it is handled by
 * {@see ExtensionLicenseService} and this store never touches that path.
 */
final class ExtensionLicenseStore
{
    public const MAX_BYTES = 65536;

    private const FILE_MODE = 0600;

    private const DIR_MODE = 0700;

    private string $path;

    public function __construct(?string $basePath = null, ?string $path = null)
    {
        if ($path !== null && trim($path) !== '') {
            $this->path = $path;
            return;
        }

        $override = $this->env('EXTENSION_LICENSE_STORE_FILE');
        if ($override !== null) {
            $this->path = $override;
            return;
        }

        $base = $basePath;
        if ($base === null || trim($base) === '') {
            $base = defined('BASE_PATH') && is_string(BASE_PATH)
                ? BASE_PATH
                : dirname(__DIR__, 4);
        }

        $this->path = rtrim($base, '/\\')
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'licenses'
            . DIRECTORY_SEPARATOR . 'active.json';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Read the stored envelope, returning null when missing, unreadable, too
     * large, or not a JSON object.
     */
    public function read(): ?string
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            return null;
        }
        $contents = @file_get_contents($this->path);
        if (!is_string($contents)) {
            return null;
        }

        try {
            return $this->normalize($contents);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Atomically persist a signed license envelope.
     *
     * @throws \InvalidArgumentException when the document is not a JSON object within the size limit
     * @throws \RuntimeException when the filesystem write fails
     */
    public function write(string $json): void
    {
        $document = $this->normalize($json);
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, self::DIR_MODE, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to prepare the license storage directory.');
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($this->path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the license storage file.');
        }

        try {
            $written = @fwrite($handle, $document);
            @fflush($handle);
            @chmod($temporary, self::FILE_MODE);
        } finally {
            @fclose($handle);
        }

        if ($written !== strlen($document)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to write the license storage file.');
        }

        if (!$this->replace($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to commit the license storage file.');
        }
        @chmod($this->path, self::FILE_MODE);
    }

    /**
     * Remove the stored envelope. Idempotent: a missing file is not an error.
     */
    public function remove(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }
        if (!@unlink($this->path)) {
            throw new \RuntimeException('Unable to remove the license storage file.');
        }

        return true;
    }

    private function replace(string $temporary, string $target): bool
    {
        if (@rename($temporary, $target)) {
            return true;
        }
        // Windows cannot rename over an existing file; replace best-effort.
        if (is_file($target) && @unlink($target)) {
            return @rename($temporary, $target);
        }

        return false;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function normalize(string $json): string
    {
        $json = trim($json);
        if ($json === '') {
            throw new \InvalidArgumentException('License document is empty.');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('License document is too large.');
        }

        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('License document is not valid JSON.');
        }
        if (!$decoded instanceof \stdClass) {
            throw new \InvalidArgumentException('License document must be a JSON object.');
        }

        return $json;
    }

    private function env(string $key): ?string
    {
        $value = Env::get($key);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
