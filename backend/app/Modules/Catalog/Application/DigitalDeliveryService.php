<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CatalogAdminPort;
use App\Modules\Catalog\Contracts\DigitalDeliveryStore;
use App\Modules\Catalog\Contracts\DigitalDeliveryTransport;
use App\Support\Arr;

final class DigitalDeliveryService
{
    public function __construct(private readonly CatalogAdminPort $catalog, private readonly DigitalDeliveryStore $downloads, private readonly ?DigitalDeliveryTransport $transport = null) {}

    /** @return array<string, mixed> */
    public function issue(string $assetId, string $orderId, ?int $limit = null, int $ttlSeconds = 604800): array
    {
        if ($assetId === '' || $orderId === '') throw new \InvalidArgumentException('Asset and order are required');
        if ($ttlSeconds <= 0) throw new \InvalidArgumentException('Download expiry must be positive');
        $asset = $this->catalog->digitalAsset($assetId);
        if ($asset === null) throw new \RuntimeException('Digital asset not found');
        if ($limit !== null && $limit <= 0) throw new \InvalidArgumentException('Download limit must be positive');
        $assetLimit = $asset['download_limit'] ?? null;
        if ($limit === null && is_numeric($assetLimit)) $limit = (int) $assetLimit;
        if (is_numeric($assetLimit) && $limit !== null) $limit = min($limit, (int) $assetLimit);
        $this->assertAssetReady($asset);
        $assetExpiry = Arr::str($asset, 'expires_at');
        $expiresAt = time() + $ttlSeconds;
        if ($assetExpiry !== '') {
            $assetTimestamp = strtotime($assetExpiry);
            if ($assetTimestamp === false || $assetTimestamp <= time()) throw new \RuntimeException('Digital asset expired');
            $expiresAt = min($expiresAt, $assetTimestamp);
        }
        $token = bin2hex(random_bytes(32));
        /** @var array<string, mixed> $row */
        $row = $this->downloads->issue(['asset_id' => $assetId, 'order_id' => $orderId, 'token_hash' => hash('sha256', $token), 'download_count' => 0, 'download_limit' => $limit, 'expires_at' => date('Y-m-d H:i:s', $expiresAt)]);
        return ['id' => Arr::requiredStr($row, 'id'), 'token' => $token, 'asset_id' => $assetId, 'order_id' => $orderId, 'expires_at' => $row['expires_at'] ?? null, 'download_url' => '/api/v2/downloads/' . $token];
    }

    /** @return array<string, mixed> */
    public function resolve(string $token, ?int $start = null, ?int $end = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new \InvalidArgumentException('Invalid download token');
        $row = $this->downloads->byTokenHash(hash('sha256', $token));
        if ($row === null) throw new \RuntimeException('Download token not found');
        if (Arr::dt($row, 'revoked_at') !== '') throw new \RuntimeException('Download token revoked');
        $expires = Arr::str($row, 'expires_at');
        if ($expires !== '') {
            $tokenTimestamp = strtotime($expires);
            if ($tokenTimestamp === false || $tokenTimestamp <= time()) throw new \RuntimeException('Download token expired');
        }
        $limit = $row['download_limit'] ?? null;
        if (is_numeric($limit) && Arr::int($row, 'download_count') >= (int) $limit) throw new \RuntimeException('Download limit reached');
        $asset = $this->catalog->digitalAsset(Arr::requiredStr($row, 'asset_id'));
        if ($asset === null) throw new \RuntimeException('Digital asset not found');
        $this->assertAssetReady($asset);
        if (Arr::dt($asset, 'revoked_at') !== '') throw new \RuntimeException('Digital asset revoked');
        $assetExpiry = Arr::str($asset, 'expires_at');
        if ($assetExpiry !== '') {
            $assetTimestamp = strtotime($assetExpiry);
            if ($assetTimestamp === false || $assetTimestamp <= time()) throw new \RuntimeException('Digital asset expired');
        }
        if ($this->transport === null) throw new \RuntimeException('Protected digital delivery transport is not configured');
        if (($start === null) !== ($end === null) || ($start !== null && ($start < 0 || $end < $start))) throw new \InvalidArgumentException('Invalid byte range');
        if ($start !== null) {
            if (!$this->transport instanceof \App\Modules\Catalog\Contracts\SignedDigitalDeliveryTransport) throw new \RuntimeException('Ranged digital delivery transport is not configured');
            $delivery = $this->transport->readRange($asset, $start, (int) $end);
        } else $delivery = $this->transport->read($asset);
        $this->validateDelivery($asset, $delivery);
        if ($start !== null) {
            $size = Arr::int($delivery, 'size', -1);
            if ($size < 1 || Arr::int($delivery, 'start', -1) !== $start || Arr::int($delivery, 'end', -1) !== $end || strlen($delivery['content']) !== $end - $start + 1) throw new \RuntimeException('Digital delivery range contract is invalid');
        }
        if (!$this->downloads->consume(Arr::requiredStr($row, 'id'), is_numeric($limit) ? (int) $limit : null)) throw new \RuntimeException('Download limit reached');
        if (method_exists($this->downloads, 'recordEvidence')) $this->downloads->recordEvidence(Arr::requiredStr($row, 'id'), ['order_id' => Arr::requiredStr($row, 'order_id'), 'asset_id' => Arr::requiredStr($row, 'asset_id'), 'consumed_at' => date('Y-m-d H:i:s')]);
        return ['content' => $delivery['content'], 'mime' => $delivery['mime'], 'filename' => $delivery['filename'], 'download_count' => Arr::int($row, 'download_count') + 1, 'start' => $start, 'end' => $end, 'size' => $start === null ? strlen($delivery['content']) : Arr::int($delivery, 'size')];
    }

    /** @return array{url: string, expires_at: string, headers?: array<string, string>} */
    public function signedUrl(string $token, int $ttlSeconds = 300): array
    {
        if ($ttlSeconds <= 0 || $ttlSeconds > 900) throw new \InvalidArgumentException('Signed URL expiry is invalid');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new \InvalidArgumentException('Invalid download token');
        $row = $this->downloads->byTokenHash(hash('sha256', $token));
        if ($row === null || Arr::dt($row, 'revoked_at') !== '') throw new \RuntimeException('Download token is unavailable');
        $tokenExpiry = Arr::str($row, 'expires_at');
        if ($tokenExpiry !== '') {
            $tokenTimestamp = strtotime($tokenExpiry);
            if ($tokenTimestamp === false || $tokenTimestamp <= time()) throw new \RuntimeException('Download token expired');
        }
        $limit = $row['download_limit'] ?? null;
        if (is_numeric($limit) && Arr::int($row, 'download_count') >= (int) $limit) throw new \RuntimeException('Download limit reached');
        $asset = $this->catalog->digitalAsset(Arr::requiredStr($row, 'asset_id'));
        if ($asset === null) throw new \RuntimeException('Digital asset not found');
        $this->assertAssetReady($asset);
        if (Arr::dt($asset, 'revoked_at') !== '') throw new \RuntimeException('Digital asset revoked');
        $assetExpiry = Arr::str($asset, 'expires_at');
        if ($assetExpiry !== '') {
            $assetTimestamp = strtotime($assetExpiry);
            if ($assetTimestamp === false || $assetTimestamp <= time()) throw new \RuntimeException('Digital asset expired');
        }
        if (!$this->transport instanceof \App\Modules\Catalog\Contracts\SignedDigitalDeliveryTransport) throw new \RuntimeException('Signed digital delivery transport is not configured');
        $signed = $this->transport->signed($asset, $ttlSeconds);
        $url = $signed['url'];
        $expires = $signed['expires_at'];
        $timestamp = strtotime($expires);
        if (!preg_match('#^https://#i', $url) || $timestamp === false || $timestamp <= time() || $timestamp > time() + $ttlSeconds) throw new \RuntimeException('Signed digital delivery contract is invalid');
        return $signed;
    }

    public function revoke(string $downloadId): void
    {
        if (!method_exists($this->downloads, 'revoke')) throw new \RuntimeException('Download revocation is unavailable');
        $this->downloads->revoke($downloadId);
    }

    /** @param array{content: string, mime: string, filename: string} $delivery */
    /**
     * @param array<string, mixed> $asset
     * @param array{content: string, mime: string, filename: string} $delivery
     */
    private function validateDelivery(array $asset, array $delivery): void
    {
        $filename = $delivery['filename'];
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, "\0")) throw new \RuntimeException('Digital asset filename is unsafe');
        $mime = strtolower(trim($delivery['mime']));
        if ($mime === '' || str_contains($mime, ';') || preg_match('/^(text\/html|application\/x-httpd-php|application\/javascript)(;|$)/', $mime) === 1) throw new \RuntimeException('Digital asset content type is not permitted');
        if (isset($asset['content_type']) && is_string($asset['content_type']) && $asset['content_type'] !== '' && strtolower($asset['content_type']) !== $mime) throw new \RuntimeException('Digital asset content type mismatch');
        if (isset($asset['size_bytes']) && is_numeric($asset['size_bytes']) && (int) $asset['size_bytes'] !== strlen($delivery['content'])) throw new \RuntimeException('Digital asset size mismatch');
        $digest = Arr::str($asset, 'content_sha256');
        if ($digest !== '' && !hash_equals(strtolower($digest), hash('sha256', $delivery['content']))) throw new \RuntimeException('Digital asset integrity check failed');
    }

    /** @param array<string, mixed> $asset */
    private function assertAssetReady(array $asset): void
    {
        if (Arr::str($asset, 'malware_scan_status') !== 'clean' || Arr::dt($asset, 'malware_scanned_at') === '') throw new \RuntimeException('Digital asset is not delivery-ready');
    }
}
