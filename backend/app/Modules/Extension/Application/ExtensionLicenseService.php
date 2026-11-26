<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use Siro\Core\Env;

/**
 * Offline extension entitlement evaluation.
 *
 * A license document is a JSON envelope:
 *
 *     {"payload": {...}, "signature": "<base64 RSA-SHA256>"}
 *
 * The signature covers the canonical JSON encoding of `payload`
 * (JSON_UNESCAPED_SLASHES, original key order). Public keys are supplied by
 * `EXTENSION_LICENSE_PUBLIC_KEY` (PEM) or `EXTENSION_LICENSE_PUBLIC_KEY_FILE`.
 * The license is read from an explicit path, then `EXTENSION_LICENSE_FILE`,
 * then the private portal store (`storage/licenses/active.json`), then the
 * community edition.
 *
 * No raw signature or public key material is ever returned or logged.
 */
final class ExtensionLicenseService
{
    public const EDITION_COMMUNITY = 'community';

    public const SOURCE_ENV = 'env';

    public const SOURCE_STORAGE = 'storage';

    public const SOURCE_NONE = 'none';

    private const DEFAULT_GRACE_DAYS = 30;

    private const LICENSED_EDITIONS = ['pro', 'enterprise'];

    private ExtensionLicenseStore $store;

    /** @var array<string, array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}> */
    private array $cache = [];

    public function __construct(?ExtensionLicenseStore $store = null)
    {
        $this->store = $store ?? new ExtensionLicenseStore();
    }

    /**
     * Resolve and evaluate the active license.
     *
     * Resolution order: explicit path, `EXTENSION_LICENSE_FILE`, the private
     * portal store, then the community edition. Missing license configuration
     * never throws.
     *
     * @return array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}
     */
    public function status(?string $path = null): array
    {
        if ($path !== null && trim($path) !== '') {
            return $this->load($path);
        }

        $envPath = $this->env('EXTENSION_LICENSE_FILE');
        if ($envPath !== null) {
            return $this->load($envPath);
        }

        if ($this->store->exists()) {
            $stored = $this->store->read();
            if ($stored === null) {
                return $this->invalid('malformed_license');
            }

            return $this->load($stored);
        }

        return $this->community('community');
    }

    /**
     * Effective license source for status output: env override, portal store or
     * community/no license. Never exposes a filesystem path.
     */
    public function source(): string
    {
        if ($this->env('EXTENSION_LICENSE_FILE') !== null) {
            return self::SOURCE_ENV;
        }
        if ($this->store->exists()) {
            return self::SOURCE_STORAGE;
        }

        return self::SOURCE_NONE;
    }

    public function stored(): bool
    {
        return $this->store->exists();
    }

    public function store(): ExtensionLicenseStore
    {
        return $this->store;
    }

    /**
     * Evaluate a license document supplied either as raw JSON or a file path.
     *
     * @return array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}
     */
    public function load(string $jsonOrPath): array
    {
        $cacheKey = $this->cacheKey($jsonOrPath);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $raw = $this->looksLikeJson($jsonOrPath) ? trim($jsonOrPath) : $this->readFile($jsonOrPath);
        if ($raw === null) {
            return $this->cache[$cacheKey] = $this->invalid('license_file_not_found');
        }

        return $this->cache[$cacheKey] = $this->evaluate($raw);
    }

    /**
     * Community edition is always entitled. Licensed editions require a valid
     * (or in-grace) license that lists the extension and, when supplied, the
     * feature.
     */
    public function entitled(string $extensionId, ?string $feature = null): bool
    {
        $status = $this->status();
        if (!$status['ok']) {
            return false;
        }
        if ($status['edition'] === self::EDITION_COMMUNITY) {
            return true;
        }
        if (!in_array('*', $status['extensions'], true) && !in_array($extensionId, $status['extensions'], true)) {
            return false;
        }
        if ($feature !== null
            && !in_array('*', $status['features'], true)
            && !in_array($feature, $status['features'], true)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}
     */
    private function evaluate(string $raw): array
    {
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->invalid('malformed_license');
        }
        if (!is_array($document)) {
            return $this->invalid('malformed_license');
        }

        $payloadValue = $document['payload'] ?? null;
        $signatureValue = $document['signature'] ?? null;
        if (!is_array($payloadValue) || !is_string($signatureValue) || trim($signatureValue) === '') {
            return $this->invalid('malformed_license');
        }

        $signature = base64_decode(trim($signatureValue), true);
        if (!is_string($signature) || $signature === '') {
            return $this->invalid('invalid_signature');
        }

        $publicKey = $this->publicKey();
        if ($publicKey === null) {
            return $this->invalid('public_key_missing');
        }
        $key = @openssl_pkey_get_public($publicKey);
        if ($key === false) {
            return $this->invalid('public_key_invalid');
        }

        /** @var array<string, mixed> $payload */
        $payload = $payloadValue;
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $verified = @openssl_verify($canonical, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return $this->invalid('invalid_signature');
        }

        return $this->claims($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}
     */
    private function claims(array $payload): array
    {
        $edition = $payload['edition'] ?? null;
        if (!is_string($edition) || !in_array($edition, self::LICENSED_EDITIONS, true)) {
            return $this->invalid('invalid_edition');
        }

        $licenseId = $payload['license_id'] ?? null;
        if (!is_string($licenseId) || trim($licenseId) === '') {
            return $this->invalid('malformed_license');
        }

        $extensions = $this->stringList($payload['extensions'] ?? null);
        if ($extensions === null) {
            return $this->invalid('malformed_license');
        }

        $features = $this->stringList($payload['features'] ?? []);
        if ($features === null) {
            return $this->invalid('malformed_license');
        }

        $notBefore = $this->timestamp($payload['not_before'] ?? null);
        $expiresAt = $this->timestamp($payload['expires_at'] ?? null);
        $graceDays = $this->graceDays($payload['grace_days'] ?? self::DEFAULT_GRACE_DAYS);
        if ($notBefore === null || $expiresAt === null || $graceDays === null) {
            return $this->invalid('malformed_license');
        }

        $now = time();
        $graceUntil = $expiresAt + ($graceDays * 86400);
        $result = [
            'ok' => true,
            'edition' => $edition,
            'license_id' => $licenseId,
            'extensions' => $extensions,
            'features' => $features,
            'expires_at' => $this->formatTimestamp($expiresAt),
            'grace_until' => $this->formatTimestamp($graceUntil),
            'reason' => 'valid',
        ];

        if ($now < $notBefore) {
            $result['ok'] = false;
            $result['reason'] = 'not_yet_valid';
        } elseif ($now > $graceUntil) {
            $result['ok'] = false;
            $result['reason'] = 'expired';
        } elseif ($now > $expiresAt) {
            $result['reason'] = 'grace';
        }

        return $result;
    }

    /**
     * @return array<int, string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if ($value === '*') {
            return ['*'];
        }
        if (!is_array($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                return null;
            }
            $items[] = trim($item);
        }

        return $items;
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
            try {
                return (new \DateTimeImmutable($value))->getTimestamp();
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private function graceDays(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            $days = (int) trim($value);

            return $days >= 0 ? $days : null;
        }

        return null;
    }

    private function formatTimestamp(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function publicKey(): ?string
    {
        $inline = $this->env('EXTENSION_LICENSE_PUBLIC_KEY');
        if ($inline !== null) {
            return $this->normalizePem($inline);
        }

        $file = $this->env('EXTENSION_LICENSE_PUBLIC_KEY_FILE');
        if ($file === null) {
            return null;
        }
        $contents = $this->readFile($file);

        return $contents !== null ? $this->normalizePem($contents) : null;
    }

    private function normalizePem(string $pem): string
    {
        $pem = trim($pem);
        if (str_contains($pem, '\\n')) {
            $pem = str_replace('\\n', "\n", $pem);
        }

        return $pem;
    }

    private function env(string $key): ?string
    {
        $value = Env::get($key);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function readFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    private function looksLikeJson(string $value): bool
    {
        return str_starts_with(ltrim($value), '{');
    }

    private function cacheKey(string $jsonOrPath): string
    {
        $publicKey = $this->publicKey() ?? '';
        if ($this->looksLikeJson($jsonOrPath)) {
            return 'json:' . sha1($jsonOrPath) . ':' . sha1($publicKey);
        }

        $stat = @stat($jsonOrPath);
        $stamp = is_array($stat) ? $stat['mtime'] . ':' . $stat['size'] : 'missing';

        return 'file:' . $jsonOrPath . ':' . $stamp . ':' . sha1($publicKey);
    }

    /**
     * @return array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}
     */
    private function community(string $reason): array
    {
        return [
            'ok' => true,
            'edition' => self::EDITION_COMMUNITY,
            'license_id' => null,
            'extensions' => [],
            'features' => [],
            'expires_at' => null,
            'grace_until' => null,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{ok: bool, edition: string, license_id: string|null, extensions: array<int, string>, features: array<int, string>, expires_at: string|null, grace_until: string|null, reason: string}
     */
    private function invalid(string $reason): array
    {
        return [
            'ok' => false,
            'edition' => self::EDITION_COMMUNITY,
            'license_id' => null,
            'extensions' => [],
            'features' => [],
            'expires_at' => null,
            'grace_until' => null,
            'reason' => $reason,
        ];
    }
}
