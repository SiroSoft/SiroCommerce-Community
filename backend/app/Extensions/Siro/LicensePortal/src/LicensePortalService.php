<?php

declare(strict_types=1);

namespace App\Extensions\Siro\LicensePortal;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Modules\Extension\Application\ExtensionLicenseService;
use App\Modules\Extension\Application\ExtensionScope;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\Ids;
use App\Support\Mailer;
use Siro\Core\Database;
use Siro\Core\Env;

final class LicensePortalService
{
    /** @var list<string> */
    public const STATES = ['pending', 'issued', 'revoked'];

    public const MAX_LIST = 100;

    public const MAX_DOCUMENT_BYTES = 65536;

    public const MAX_IDEMPOTENCY_KEY = 128;

    public const DEFAULT_TTL_HOURS = 168;

    public const MAX_TTL_HOURS = 720;

    private const METADATA_COLUMNS = 'id, store_id, channel_id, customer_id, order_id, order_line_id, sku, source_sku, license_id, license_reference, license_expires_at, token_expires_at, token_used_at, revoked_at, state, created_at, updated_at';

    private const FULL_COLUMNS = self::METADATA_COLUMNS . ', license_document, token_hash, idempotency_key';

    private static bool $listening = false;

    public static function listen(bool $on): void
    {
        self::$listening = $on;
    }

    /** @param mixed $payload */
    public static function onOrderPaid(mixed $payload): void
    {
        if (!self::$listening) {
            return;
        }
        try {
            (new self())->syncOrderPaid(self::stringKeyed($payload));
        } catch (\Throwable $exception) {
            error_log('[license-portal] order.paid sync failed: ' . $exception->getMessage());
        }
    }

    /** @param mixed $payload */
    public static function onOrderTransitioned(mixed $payload): void
    {
        $input = self::stringKeyed($payload);
        if (!self::$listening || ($input['to'] ?? null) !== 'paid') {
            return;
        }
        try {
            (new self())->syncOrderPaid($input);
        } catch (\Throwable $exception) {
            error_log('[license-portal] order.transitioned sync failed: ' . $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private static function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public function syncOrderPaid(array $payload): array
    {
        $orderId = $this->nonEmptyString($payload['order_id'] ?? null);
        if ($orderId === null) {
            return [];
        }
        $model = Order::query()->where('id', $orderId)->first();
        if (!$model instanceof Order) {
            return [];
        }
        $order = $model->toArray();
        $scope = $this->resolveOrderScope($payload, $order);
        if ($scope === null) {
            return [];
        }

        $customerId = $this->nonEmptyString($order['customer_id'] ?? null);
        $created = [];
        foreach ($this->targetsForOrder($orderId, $order) as $target) {
            $sku = $target['sku'];
            $existing = $this->findByOrderSku($scope, $orderId, $sku);
            if ($existing !== null) {
                $created[] = $this->present($existing);
                continue;
            }
            $key = 'order.paid:' . $orderId . ':' . $sku;
            $now = date('Y-m-d H:i:s');
            $row = ExtensionScope::merge([
                'id' => Ids::new(),
                'customer_id' => $customerId,
                'order_id' => $orderId,
                'order_line_id' => $target['order_line_id'],
                'sku' => $sku,
                'source_sku' => $target['source_sku'],
                'license_id' => null,
                'license_document' => null,
                'license_reference' => null,
                'license_expires_at' => null,
                'token_hash' => null,
                'token_expires_at' => null,
                'token_used_at' => null,
                'revoked_at' => null,
                'state' => 'pending',
                'idempotency_key' => $this->bounded($key, self::MAX_IDEMPOTENCY_KEY),
                'created_at' => $now,
                'updated_at' => $now,
            ], $scope);
            try {
                Database::table('ext_license_portal_deliveries')->insert($row);
                $created[] = $this->present($row);
            } catch (\Throwable) {
                $winner = $this->findByOrderSku($scope, $orderId, $sku);
                if ($winner !== null) {
                    $created[] = $this->present($winner);
                }
            }
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{delivery: array<string, mixed>, token: string|null, download_url: string|null, email_sent: bool, replayed: bool}
     */
    public function assign(CommerceScope $scope, array $data, ?string $idempotencyKey): array
    {
        $key = $this->cleanIdempotencyKey($idempotencyKey);
        $existing = $this->findByIdempotency($scope, $key);
        if ($existing !== null) {
            return [
                'delivery' => $this->present($existing),
                'token' => null,
                'download_url' => null,
                'email_sent' => false,
                'replayed' => true,
            ];
        }

        $customerId = $this->cleanOptional($data['customer_id'] ?? null, 'customer_id', 64);
        $orderId = $this->cleanOptional($data['order_id'] ?? null, 'order_id', 64);
        $orderLineId = $this->cleanOptional($data['order_line_id'] ?? null, 'order_line_id', 26);
        if ($customerId === null && $orderId === null) {
            throw new \InvalidArgumentException('customer_id or order_id is required');
        }
        $orderRow = $this->orderRow($scope, $orderId);
        if ($customerId === null && $orderRow !== null) {
            $customerId = $this->nonEmptyString($orderRow['customer_id'] ?? null);
        }

        $reference = $this->cleanOptional($data['license_reference'] ?? null, 'license_reference', 255);
        $license = $this->licenseDocument($data['license'] ?? null);
        $licenseId = null;
        $licenseExpiresAt = null;
        if ($license !== null) {
            $validated = $this->validateLicenseDocument($license);
            $license = $validated['raw'];
            $licenseId = $validated['license_id'];
            $licenseExpiresAt = $validated['expires_at'];
        }
        if ($license === null && $reference === null) {
            throw new \InvalidArgumentException('license or license_reference is required');
        }

        $sku = $this->cleanOptional($data['sku'] ?? null, 'sku', 64);
        $sourceSku = $this->cleanOptional($data['source_sku'] ?? null, 'source_sku', 64);
        $ttl = $this->ttlHours($data['token_ttl_hours'] ?? null);

        $token = null;
        $tokenHash = null;
        $tokenExpiresAt = null;
        $state = 'pending';
        if ($license !== null) {
            $token = $this->newToken();
            $tokenHash = hash('sha256', $token);
            $tokenExpiresAt = date('Y-m-d H:i:s', time() + ($ttl * 3600));
            $state = 'issued';
        }

        $now = date('Y-m-d H:i:s');
        $row = ExtensionScope::merge([
            'id' => Ids::new(),
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'order_line_id' => $orderLineId,
            'sku' => $sku,
            'source_sku' => $sourceSku,
            'license_id' => $licenseId,
            'license_document' => $license,
            'license_reference' => $reference,
            'license_expires_at' => $licenseExpiresAt,
            'token_hash' => $tokenHash,
            'token_expires_at' => $tokenExpiresAt,
            'token_used_at' => null,
            'revoked_at' => null,
            'state' => $state,
            'idempotency_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ], $scope);

        try {
            Database::table('ext_license_portal_deliveries')->insert($row);
        } catch (\Throwable $exception) {
            $winner = $this->findByIdempotency($scope, $key)
                ?? ($orderId !== null && $sku !== null ? $this->findByOrderSku($scope, $orderId, $sku) : null);
            if ($winner !== null) {
                return [
                    'delivery' => $this->present($winner),
                    'token' => null,
                    'download_url' => null,
                    'email_sent' => false,
                    'replayed' => true,
                ];
            }
            throw new \RuntimeException('License delivery could not be saved', 0, $exception);
        }

        $downloadUrl = $token !== null ? $this->downloadUrl($token) : null;
        $emailSent = false;
        $sendEmail = $this->truthy($data['send_email'] ?? false);
        $explicitEmail = $this->nonEmptyString($data['email'] ?? null);
        if ($token !== null && $downloadUrl !== null && ($sendEmail || $explicitEmail !== null)) {
            $email = $this->resolveEmail($explicitEmail, $orderRow, $customerId);
            if ($email !== null) {
                $emailSent = $this->sendLink($email, $downloadUrl, $sku ?? $licenseId ?? 'Siro license', $tokenExpiresAt);
            }
        }

        return [
            'delivery' => $this->present($row),
            'token' => $token,
            'download_url' => $downloadUrl,
            'email_sent' => $emailSent,
            'replayed' => false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(CommerceScope $scope, string $state = 'all', ?string $customerId = null, ?string $orderId = null): array
    {
        $state = strtolower(trim($state));
        if ($state === '') {
            $state = 'all';
        }
        if ($state !== 'all' && !in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('state must be pending, issued, revoked or all');
        }
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $sql = 'SELECT ' . self::METADATA_COLUMNS . ' FROM ext_license_portal_deliveries WHERE ' . $where;
        $bind = $params;
        if ($state !== 'all') {
            $sql .= ' AND state = ?';
            $bind[] = $state;
        }
        $customerId = $customerId !== null ? trim($customerId) : '';
        if ($customerId !== '') {
            $sql .= ' AND customer_id = ?';
            $bind[] = $customerId;
        }
        $orderId = $orderId !== null ? trim($orderId) : '';
        if ($orderId !== '') {
            $sql .= ' AND order_id = ?';
            $bind[] = $orderId;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . self::MAX_LIST;

        $result = [];
        foreach ($this->rows($sql, $bind) as $row) {
            $result[] = $this->present($row);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function revoke(CommerceScope $scope, string $id): ?array
    {
        $row = $this->findRow($scope, $id);
        if ($row === null) {
            return null;
        }
        if ($this->nonEmptyString($row['revoked_at'] ?? null) !== null) {
            return $this->present($row);
        }
        $now = date('Y-m-d H:i:s');
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'UPDATE ext_license_portal_deliveries SET revoked_at = ?, state = ?, updated_at = ? WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('License delivery could not be revoked');
        }
        $stmt->execute(array_merge([$now, 'revoked', $now], $params, [$id]));
        $updated = $this->findRow($scope, $id);
        if ($updated === null) {
            throw new \RuntimeException('License delivery could not be revoked');
        }

        return $this->present($updated);
    }

    /**
     * @return array{delivery: array<string, mixed>, token: string, download_url: string}
     */
    public function rotate(CommerceScope $scope, string $id, mixed $ttlInput = null): array
    {
        $row = $this->findRow($scope, $id);
        if ($row === null) {
            throw new \InvalidArgumentException('License delivery not found');
        }
        if ($this->nonEmptyString($row['revoked_at'] ?? null) !== null) {
            throw new \RuntimeException('Revoked license delivery cannot be rotated');
        }
        $document = $row['license_document'] ?? null;
        if (!is_string($document) || trim($document) === '') {
            throw new \InvalidArgumentException('License delivery has no signed license document');
        }
        $ttl = $this->ttlHours($ttlInput);
        $token = $this->newToken();
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttl * 3600));
        $now = date('Y-m-d H:i:s');
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));
        $stmt = Database::connection()->prepare(
            'UPDATE ext_license_portal_deliveries SET token_hash = ?, token_expires_at = ?, token_used_at = NULL, state = ?, updated_at = ? WHERE ' . $where . ' AND id = ?'
        );
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('License delivery link could not be rotated');
        }
        $stmt->execute(array_merge([$tokenHash, $expiresAt, 'issued', $now], $params, [$id]));
        $updated = $this->findRow($scope, $id);
        if ($updated === null) {
            throw new \RuntimeException('License delivery link could not be rotated');
        }

        return [
            'delivery' => $this->present($updated),
            'token' => $token,
            'download_url' => $this->downloadUrl($token),
        ];
    }

    /**
     * @return array{document: string|null, filename: string|null, reason: string}
     */
    public function download(string $token): array
    {
        $token = trim($token);
        if (preg_match('/^[A-Fa-f0-9]{32,128}$/', $token) !== 1) {
            return ['document' => null, 'filename' => null, 'reason' => 'not_found'];
        }
        $hash = hash('sha256', $token);
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::FULL_COLUMNS . ' FROM ext_license_portal_deliveries WHERE token_hash = ? LIMIT 1'
        );
        if (!$stmt instanceof \PDOStatement) {
            return ['document' => null, 'filename' => null, 'reason' => 'not_found'];
        }
        $stmt->execute([$hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['document' => null, 'filename' => null, 'reason' => 'not_found'];
        }
        if ($this->nonEmptyString($row['revoked_at'] ?? null) !== null || ($row['state'] ?? null) === 'revoked') {
            return ['document' => null, 'filename' => null, 'reason' => 'revoked'];
        }
        if ($this->nonEmptyString($row['token_used_at'] ?? null) !== null) {
            return ['document' => null, 'filename' => null, 'reason' => 'used'];
        }
        $expiresAt = $this->timestamp($row['token_expires_at'] ?? null);
        if ($expiresAt !== null && $expiresAt <= time()) {
            return ['document' => null, 'filename' => null, 'reason' => 'expired'];
        }
        $document = $row['license_document'] ?? null;
        if (!is_string($document) || trim($document) === '') {
            return ['document' => null, 'filename' => null, 'reason' => 'unavailable'];
        }
        $id = is_string($row['id'] ?? null) ? $row['id'] : '';
        $now = date('Y-m-d H:i:s');
        $update = Database::connection()->prepare(
            'UPDATE ext_license_portal_deliveries SET token_used_at = ?, updated_at = ? WHERE id = ? AND token_hash = ? AND token_used_at IS NULL AND revoked_at IS NULL'
        );
        if (!$update instanceof \PDOStatement) {
            return ['document' => null, 'filename' => null, 'reason' => 'unavailable'];
        }
        $update->execute([$now, $now, $id, $hash]);
        if ($update->rowCount() !== 1) {
            return ['document' => null, 'filename' => null, 'reason' => 'used'];
        }
        $licenseId = is_string($row['license_id'] ?? null) ? $row['license_id'] : '';
        $base = $licenseId !== '' ? $licenseId : $id;
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $base);
        $filename = 'license-' . (is_string($safe) && $safe !== '' ? $safe : 'document') . '.json';

        return ['document' => $document, 'filename' => $filename, 'reason' => 'ok'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $tokenHash = $row['token_hash'] ?? null;
        $usedAt = $this->nonEmptyString($row['token_used_at'] ?? null);
        $revokedAt = $this->nonEmptyString($row['revoked_at'] ?? null);
        $expiresAt = $this->timestamp($row['token_expires_at'] ?? null);
        $hasActiveToken = is_string($tokenHash) && $tokenHash !== ''
            && $usedAt === null
            && $revokedAt === null
            && ($expiresAt === null || $expiresAt > time());

        return [
            'id' => $this->stringValue($row['id'] ?? null),
            'store_id' => $this->stringValue($row['store_id'] ?? null),
            'channel_id' => $this->stringValue($row['channel_id'] ?? null),
            'customer_id' => $this->nullableValue($row['customer_id'] ?? null),
            'order_id' => $this->nullableValue($row['order_id'] ?? null),
            'order_line_id' => $this->nullableValue($row['order_line_id'] ?? null),
            'sku' => $this->nullableValue($row['sku'] ?? null),
            'source_sku' => $this->nullableValue($row['source_sku'] ?? null),
            'license_id' => $this->nullableValue($row['license_id'] ?? null),
            'license_reference' => $this->nullableValue($row['license_reference'] ?? null),
            'license_expires_at' => $this->nullableValue($row['license_expires_at'] ?? null),
            'token_expires_at' => $this->nullableValue($row['token_expires_at'] ?? null),
            'token_used_at' => $this->nullableValue($row['token_used_at'] ?? null),
            'revoked_at' => $this->nullableValue($row['revoked_at'] ?? null),
            'state' => $this->stringValue($row['state'] ?? null),
            'has_license' => is_string($row['license_document'] ?? null) && trim((string) $row['license_document']) !== '',
            'has_active_token' => $hasActiveToken,
            'created_at' => $this->nullableValue($row['created_at'] ?? null),
            'updated_at' => $this->nullableValue($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $order
     */
    private function resolveOrderScope(array $payload, array $order): ?CommerceScope
    {
        $orderStore = $this->nonEmptyString($order['store_id'] ?? null);
        $orderChannel = $this->nonEmptyString($order['channel_id'] ?? null);
        $payloadStore = $this->nonEmptyString($payload['store_id'] ?? null);
        $payloadChannel = $this->nonEmptyString($payload['channel_id'] ?? null);
        if ($orderStore !== null && $payloadStore !== null && $orderStore !== $payloadStore) {
            return null;
        }
        if ($orderChannel !== null && $payloadChannel !== null && $orderChannel !== $payloadChannel) {
            return null;
        }
        $storeId = $orderStore ?? $payloadStore;
        $channelId = $orderChannel ?? $payloadChannel;
        if ($storeId === null || $channelId === null) {
            return null;
        }

        return new CommerceScope($storeId, $channelId, 'en', 'VND');
    }

    /**
     * @param array<string, mixed> $order
     * @return list<array{sku: string, source_sku: string|null, order_line_id: string|null}>
     */
    private function targetsForOrder(string $orderId, array $order): array
    {
        $targets = [];
        $seen = [];
        foreach (['metadata_json', 'license_metadata_json', 'licenses_json'] as $field) {
            $metadata = $this->decodeJson($order[$field] ?? null);
            foreach ($this->metadataTargets($metadata) as $sku) {
                $key = 'metadata:' . $sku;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $targets[] = ['sku' => $sku, 'source_sku' => null, 'order_line_id' => null];
            }
        }
        foreach (OrderLine::query()->where('order_id', $orderId)->get() as $model) {
            $line = Arr::row($model);
            $lineId = $this->nonEmptyString($line['id'] ?? null);
            $sourceSku = $this->nonEmptyString($line['sku'] ?? null);
            $components = $this->decodeJson($line['components_json'] ?? null);
            $licenseSku = $this->licenseSkuFromComponents($components);
            if ($licenseSku === null && $sourceSku !== null) {
                $licenseSku = $this->mapSku($sourceSku);
            }
            if ($licenseSku === null) {
                continue;
            }
            $key = $lineId ?? $licenseSku;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $targets[] = ['sku' => $licenseSku, 'source_sku' => $sourceSku, 'order_line_id' => $lineId];
        }

        return $targets;
    }

    /** @return list<string> */
    private function metadataTargets(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }
        $skus = [];
        $add = function (mixed $value) use (&$skus): void {
            $sku = $this->cleanSku($value);
            if ($sku !== null) {
                $skus[] = $sku;
            }
        };
        $add($metadata['license_sku'] ?? null);
        $add($metadata['license'] ?? null);
        foreach (['licenses', 'license_skus'] as $key) {
            $list = $metadata[$key] ?? null;
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $item) {
                if (is_array($item)) {
                    $add($item['sku'] ?? null);
                    continue;
                }
                $add($item);
            }
        }

        return array_values(array_unique($skus));
    }

    private function licenseSkuFromComponents(mixed $components): ?string
    {
        if (!is_array($components)) {
            return null;
        }
        $candidates = [
            $components['license_sku'] ?? null,
            $components['siro_license_sku'] ?? null,
            $components['license'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['sku'] ?? ($candidate['license_sku'] ?? null);
            }
            $sku = $this->cleanSku($candidate);
            if ($sku !== null) {
                return $sku;
            }
        }

        return null;
    }

    private function mapSku(string $sourceSku): ?string
    {
        $sourceSku = trim($sourceSku);
        if ($sourceSku === '') {
            return null;
        }
        $config = (new Extension())->config();
        $map = is_array($config['sku_map'] ?? null) ? $config['sku_map'] : [];
        $envMap = $this->decodeJson(Env::get('LICENSE_PORTAL_SKU_MAP'));
        if (is_array($envMap)) {
            $map = array_merge($map, $envMap);
        }
        $needle = strtolower($sourceSku);
        foreach ($map as $from => $to) {
            if (is_string($from) && strtolower($from) === $needle) {
                $mapped = $this->cleanSku($to);
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        }
        $prefixes = is_array($config['license_sku_prefixes'] ?? null) ? $config['license_sku_prefixes'] : [];
        foreach ($prefixes as $prefix) {
            if (!is_string($prefix) || $prefix === '') {
                continue;
            }
            if (str_starts_with($needle, strtolower($prefix))) {
                $rest = substr($sourceSku, strlen($prefix));
                $mapped = $this->cleanSku($rest);
                if ($mapped !== null) {
                    return $mapped;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderRow(CommerceScope $scope, ?string $orderId): ?array
    {
        if ($orderId === null) {
            return null;
        }
        $model = Order::query()->where('id', $orderId)->first();
        if (!$model instanceof Order) {
            return null;
        }
        $row = $model->toArray();
        $storeId = $this->nonEmptyString($row['store_id'] ?? null);
        $channelId = $this->nonEmptyString($row['channel_id'] ?? null);
        if ($storeId !== null && $storeId !== $scope->storeId) {
            throw new \InvalidArgumentException('Order does not belong to this store');
        }
        if ($channelId !== null && $channelId !== $scope->channelId) {
            throw new \InvalidArgumentException('Order does not belong to this channel');
        }

        return $row;
    }

    /**
     * @return array{raw: string, license_id: string|null, expires_at: string|null}
     */
    private function validateLicenseDocument(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('License document is empty');
        }
        if (strlen($raw) > self::MAX_DOCUMENT_BYTES) {
            throw new \InvalidArgumentException('License document is too large');
        }
        $document = $this->decodeJson($raw);
        if (!is_array($document) || !is_array($document['payload'] ?? null) || !is_string($document['signature'] ?? null)) {
            throw new \InvalidArgumentException('License document is malformed');
        }
        $status = (new ExtensionLicenseService())->load($raw);
        if (!in_array($status['reason'], ['valid', 'grace', 'expired', 'not_yet_valid'], true)) {
            throw new \InvalidArgumentException('License document is not cryptographically valid');
        }
        /** @var array<string, mixed> $payload */
        $payload = $document['payload'];
        $licenseId = $this->nonEmptyString($payload['license_id'] ?? null);
        if ($licenseId !== null) {
            $licenseId = $this->bounded($licenseId, 128);
        }
        $expiresAt = $this->normalizeDateTime($payload['expires_at'] ?? null);

        return ['raw' => $raw, 'license_id' => $licenseId, 'expires_at' => $expiresAt];
    }

    private function licenseDocument(mixed $license): ?string
    {
        if (is_string($license)) {
            $value = trim($license);

            return $value === '' ? null : $value;
        }
        if (is_array($license)) {
            try {
                return json_encode($license, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        return null;
    }

    private function cleanIdempotencyKey(?string $key): string
    {
        $key = $key !== null ? trim($key) : '';
        if ($key === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            throw new \InvalidArgumentException('A valid Idempotency-Key is required');
        }

        return $key;
    }

    private function ttlHours(mixed $value): int
    {
        $config = (new Extension())->config();
        $default = isset($config['token_ttl_hours']) && is_int($config['token_ttl_hours'])
            ? $config['token_ttl_hours']
            : self::DEFAULT_TTL_HOURS;
        $max = isset($config['max_token_ttl_hours']) && is_int($config['max_token_ttl_hours'])
            ? $config['max_token_ttl_hours']
            : self::MAX_TTL_HOURS;
        if ($max < 1) {
            $max = self::MAX_TTL_HOURS;
        }
        if ($default < 1 || $default > $max) {
            $default = min(self::DEFAULT_TTL_HOURS, $max);
        }
        if ($value === null) {
            return $default;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) trim($value);
        }
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException('token_ttl_hours must be a positive integer');
        }

        return min($value, $max);
    }

    /**
     * @param array<string, mixed>|null $orderRow
     */
    private function resolveEmail(?string $explicit, ?array $orderRow, ?string $customerId): ?string
    {
        $candidates = [$explicit];
        if ($orderRow !== null) {
            $candidates[] = $this->nonEmptyString($orderRow['guest_email'] ?? null);
            $customerId = $customerId ?? $this->nonEmptyString($orderRow['customer_id'] ?? null);
        }
        if ($customerId !== null) {
            $model = Customer::query()->where('id', $customerId)->first();
            if ($model instanceof Customer) {
                $candidates[] = $this->nonEmptyString($model->toArray()['email'] ?? null);
            }
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== null && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    private function sendLink(string $email, string $url, string $sku, ?string $expiresAt): bool
    {
        $subject = 'Your Siro license download link';
        $text = "Your Siro license is ready.\n\n"
            . 'SKU: ' . $sku . "\n"
            . 'Download (single use): ' . $url . "\n";
        if ($expiresAt !== null) {
            $text .= 'Link expires: ' . $expiresAt . " UTC\n";
        }
        $text .= "\nThe link can be used once. Contact support if it expires.";
        try {
            return Mailer::send($email, $subject, $text);
        } catch (\Throwable) {
            return false;
        }
    }

    private function downloadUrl(string $token): string
    {
        $path = '/api/ext/siro/license-portal/download/' . $token;
        $base = trim((string) (Env::get('APP_URL', '') ?? ''));
        if ($base !== '' && filter_var($base, FILTER_VALIDATE_URL) !== false) {
            return rtrim($base, '/') . $path;
        }

        return $path;
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return array<string, mixed>|null */
    private function findByIdempotency(CommerceScope $scope, string $key): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));

        return $this->first(
            'SELECT ' . self::FULL_COLUMNS . ' FROM ext_license_portal_deliveries WHERE ' . $where . ' AND idempotency_key = ? LIMIT 1',
            array_merge($params, [$key])
        );
    }

    /** @return array<string, mixed>|null */
    private function findByOrderSku(CommerceScope $scope, string $orderId, string $sku): ?array
    {
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));

        return $this->first(
            'SELECT ' . self::FULL_COLUMNS . ' FROM ext_license_portal_deliveries WHERE ' . $where . ' AND order_id = ? AND sku = ? LIMIT 1',
            array_merge($params, [$orderId, $sku])
        );
    }

    /** @return array<string, mixed>|null */
    private function findRow(CommerceScope $scope, string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        [$where, $params] = ExtensionScope::where(ExtensionScope::fromScope($scope));

        return $this->first(
            'SELECT ' . self::FULL_COLUMNS . ' FROM ext_license_portal_deliveries WHERE ' . $where . ' AND id = ? LIMIT 1',
            array_merge($params, [$id])
        );
    }

    private function cleanOptional(mixed $value, string $field, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a string');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $max) {
            throw new \InvalidArgumentException($field . ' is too long');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException($field . ' contains control characters');
        }

        return $value;
    }

    private function cleanSku(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 64 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function nullableValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function bounded(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || (!str_starts_with($value, '{') && !str_starts_with($value, '['))) {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $timestamp = $this->timestamp($value);

        return $timestamp !== null ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = Database::connection()->prepare($sql);
        if (!$stmt instanceof \PDOStatement) {
            return [];
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $clean[$key] = $value;
                }
            }
            $result[] = $clean;
        }

        return $result;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    private function first(string $sql, array $params = []): ?array
    {
        $rows = $this->rows($sql, $params);

        return $rows[0] ?? null;
    }
}
