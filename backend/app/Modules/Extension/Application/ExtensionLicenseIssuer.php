<?php

declare(strict_types=1);

namespace App\Modules\Extension\Application;

use Siro\Core\Env;

/**
 * Offline extension license issuance.
 *
 * Produces the signed JSON envelope consumed by {@see ExtensionLicenseService}:
 *
 *     {"payload": {...}, "signature": "<base64 RSA-SHA256>"}
 *
 * The signature covers the canonical JSON encoding of `payload`
 * (JSON_UNESCAPED_SLASHES, deterministic key order). The RSA private key is read
 * from `EXTENSION_LICENSE_PRIVATE_KEY` (PEM) or
 * `EXTENSION_LICENSE_PRIVATE_KEY_FILE`. The private key is never returned,
 * logged, or embedded in the issued document.
 */
final class ExtensionLicenseIssuer
{
    private const LICENSED_EDITIONS = ['pro', 'enterprise'];

    private const ISO8601 = 'Y-m-d\TH:i:s\Z';

    /**
     * Issue a license document as a JSON string.
     *
     * @param array<string, mixed> $payload
     * @throws \InvalidArgumentException when the payload is invalid
     * @throws \RuntimeException when the private key is missing, invalid, or signing fails
     */
    public function issue(array $payload): string
    {
        return json_encode(
            $this->issueDocument($payload),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Issue a license document as a validated payload plus signature.
     *
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, signature: string}
     * @throws \InvalidArgumentException when the payload is invalid
     * @throws \RuntimeException when the private key is missing, invalid, or signing fails
     */
    public function issueDocument(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);
        $privateKey = $this->privateKey();
        $canonical = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $signature = '';
        if (!@openssl_sign($canonical, $signature, $privateKey, OPENSSL_ALGO_SHA256) || !is_string($signature)) {
            throw new \RuntimeException('Unable to sign extension license payload.');
        }

        return [
            'payload' => $normalized,
            'signature' => base64_encode($signature),
        ];
    }

    /**
     * Validate and normalize a license payload into deterministic field order.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array
    {
        $licenseId = $this->requiredString($payload, 'license_id');
        $customerId = $this->requiredString($payload, 'customer_id');

        $edition = $this->requiredString($payload, 'edition');
        $edition = strtolower($edition);
        if (!in_array($edition, self::LICENSED_EDITIONS, true)) {
            throw $this->invalid('edition must be one of: ' . implode(', ', self::LICENSED_EDITIONS));
        }

        $extensions = $this->stringList($payload['extensions'] ?? null, 'extensions', false);
        $features = $this->stringList($payload['features'] ?? [], 'features', true);

        $issuedAt = $this->timestamp($payload['issued_at'] ?? null, 'issued_at');
        $notBefore = $this->timestamp($payload['not_before'] ?? null, 'not_before');
        $expiresAt = $this->timestamp($payload['expires_at'] ?? null, 'expires_at');
        $graceDays = $this->graceDays($payload['grace_days'] ?? null);

        if ($issuedAt > $expiresAt) {
            throw $this->invalid('issued_at must not be later than expires_at');
        }
        if ($notBefore >= $expiresAt) {
            throw $this->invalid('not_before must be earlier than expires_at');
        }
        if ($expiresAt <= time()) {
            throw $this->invalid('expires_at must be in the future');
        }

        $limits = $payload['limits'] ?? null;
        if ($limits !== null && !is_array($limits)) {
            throw $this->invalid('limits must be an object/array when supplied');
        }

        $kid = null;
        if (array_key_exists('kid', $payload)) {
            $kid = $this->requiredString($payload, 'kid');
        }

        $normalized = [
            'license_id' => $licenseId,
            'customer_id' => $customerId,
            'edition' => $edition,
            'extensions' => $extensions,
            'features' => $features,
        ];
        if ($limits !== null) {
            /** @var array<string, mixed> $limits */
            $normalized['limits'] = $limits;
        }
        $normalized['issued_at'] = $this->iso8601($issuedAt);
        $normalized['not_before'] = $this->iso8601($notBefore);
        $normalized['expires_at'] = $this->iso8601($expiresAt);
        $normalized['grace_days'] = $graceDays;
        if ($kid !== null) {
            $normalized['kid'] = $kid;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw $this->invalid($field . ' must be a non-empty string');
        }

        return trim($value);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value, string $field, bool $allowEmpty): array
    {
        if ($value === '*') {
            return ['*'];
        }
        if (!is_array($value)) {
            throw $this->invalid($field . ' must be a list of strings or "*"');
        }
        if ($value === [] && !$allowEmpty) {
            throw $this->invalid($field . ' must not be empty');
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw $this->invalid($field . ' must contain only non-empty strings');
            }
            if (trim($item) === '*') {
                return ['*'];
            }
            $items[] = trim($item);
        }

        return $items;
    }

    private function timestamp(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                throw $this->invalid($field . ' must be a timestamp');
            }
            if (preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }
            try {
                return (new \DateTimeImmutable($value))->getTimestamp();
            } catch (\Exception) {
                throw $this->invalid($field . ' must be a valid ISO8601 timestamp');
            }
        }

        throw $this->invalid($field . ' must be a valid ISO8601 timestamp');
    }

    private function graceDays(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw $this->invalid('grace_days must be a non-negative integer');
    }

    private function iso8601(int $timestamp): string
    {
        return gmdate(self::ISO8601, $timestamp);
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $pem = $this->env('EXTENSION_LICENSE_PRIVATE_KEY');
        if ($pem === null) {
            $file = $this->env('EXTENSION_LICENSE_PRIVATE_KEY_FILE');
            if ($file !== null) {
                $pem = $this->readFile($file);
            }
        }
        if ($pem === null) {
            throw new \RuntimeException(
                'Extension license private key is not configured (EXTENSION_LICENSE_PRIVATE_KEY or EXTENSION_LICENSE_PRIVATE_KEY_FILE).'
            );
        }

        $key = @openssl_pkey_get_private($this->normalizePem($pem));
        if ($key === false) {
            throw new \RuntimeException('Extension license private key is invalid.');
        }

        return $key;
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

    private function invalid(string $reason): \InvalidArgumentException
    {
        return new \InvalidArgumentException('Invalid extension license payload: ' . $reason . '.');
    }
}
