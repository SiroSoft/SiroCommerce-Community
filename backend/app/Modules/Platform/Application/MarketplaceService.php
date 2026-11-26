<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Support\CommerceScope;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/** Marketplace read/write model. Payment and payout provider execution is deliberately external-blocked. */
final class MarketplaceService
{
    /** @param array<string,mixed> $data */
    /** @return array<string,mixed> */
    public function onboard(array $data, CommerceScope $scope, string $key, string $actor): array
    {
        $code = trim(Arr::str($data, 'code')); $name = trim(Arr::str($data, 'name'));
        if ($code === '' || $name === '') throw new \InvalidArgumentException('Vendor code and name are required');
        if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $code)) throw new \InvalidArgumentException('Invalid vendor code');
        $db = Database::connection(); $requestKey = $scope->key() . ':' . $key;
        $q = $db->prepare('SELECT * FROM commerce_vendors WHERE code = ? AND store_id = ? AND (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL))');
        $q->execute([$code, $scope->storeId, $scope->tenantId, $scope->tenantId]); $existing = $q->fetch(\PDO::FETCH_ASSOC);
        $profile = is_array($data['profile'] ?? null) ? $data['profile'] : [];
        if (is_array($existing)) {
            if (($existing['profile_json'] ?? '') !== json_encode($profile, JSON_THROW_ON_ERROR) && $key !== '') throw new \RuntimeException('Vendor already exists with different onboarding data');
            return self::row($existing);
        }
        $id = Ids::new(); $now = date('Y-m-d H:i:s');
        $db->prepare('INSERT INTO commerce_vendors (id, code, name, seller_id, tenant_id, store_id, state, status, profile_json, version, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')->execute([$id, $code, $name, $scope->sellerId, $scope->tenantId, $scope->storeId, 'pending', 'pending', json_encode($profile, JSON_THROW_ON_ERROR), $now, $now]);
        $this->audit('vendor.onboarded', $actor, $scope, ['vendor_id' => $id, 'request_key' => $requestKey]);
        return $this->vendor($id, $scope) ?? ['id' => $id, 'status' => 'pending'];
    }

    /** @param array<string,mixed> $data */
    /** @return array<string,mixed> */
    public function updateProfile(string $id, array $data, CommerceScope $scope, string $key, string $actor): array
    {
        $vendor = $this->vendor($id, $scope); if ($vendor === null) throw new \RuntimeException('Vendor not found');
        $version = Arr::int($data, 'version'); if ($version !== Arr::int($vendor, 'version')) throw new \RuntimeException('Vendor profile version conflict');
        $profile = is_array($data['profile'] ?? null) ? $data['profile'] : [];
        $name = trim(Arr::str($data, 'name', Arr::str($vendor, 'name')));
        Database::connection()->prepare('UPDATE commerce_vendors SET name = ?, profile_json = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?')->execute([$name, json_encode($profile, JSON_THROW_ON_ERROR), date('Y-m-d H:i:s'), $id, $version]);
        $this->audit('vendor.profile.updated', $actor, $scope, ['vendor_id' => $id, 'idempotency_key' => $key]);
        return $this->vendor($id, $scope) ?? [];
    }

    /** @return array<string,mixed> */
    public function status(string $id, string $status, CommerceScope $scope, string $key, string $actor): array
    {
        if (!in_array($status, ['pending', 'active', 'suspended', 'closed'], true)) throw new \InvalidArgumentException('Unsupported vendor status');
        if ($this->vendor($id, $scope) === null) throw new \RuntimeException('Vendor not found');
        Database::connection()->prepare('UPDATE commerce_vendors SET status = ?, state = ?, version = version + 1, updated_at = ? WHERE id = ?')->execute([$status, $status, date('Y-m-d H:i:s'), $id]);
        $this->audit('vendor.status.changed', $actor, $scope, ['vendor_id' => $id, 'status' => $status, 'idempotency_key' => $key]);
        return $this->vendor($id, $scope) ?? [];
    }

    /** @param array<string,mixed> $data */
    /** @return array<string,mixed> */
    public function catalog(string $vendorId, array $data, CommerceScope $scope, string $key, string $actor): array
    {
        if ($this->vendor($vendorId, $scope) === null) throw new \RuntimeException('Vendor not found');
        $product = trim(Arr::str($data, 'product_id')); $visibility = Arr::str($data, 'visibility', 'draft');
        if ($product === '' || !in_array($visibility, ['draft', 'visible', 'hidden'], true)) throw new \InvalidArgumentException('Product and supported visibility are required');
        $db = Database::connection(); $existing = $db->prepare('SELECT * FROM commerce_vendor_catalog WHERE vendor_id = ? AND product_id = ? AND store_id = ? AND channel_id = ?'); $existing->execute([$vendorId, $product, $scope->storeId, $scope->channelId]); $row = $existing->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) { if (Arr::int($data, 'version') !== Arr::int($row, 'version')) throw new \RuntimeException('Catalog ownership version conflict'); $db->prepare('UPDATE commerce_vendor_catalog SET visibility = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?')->execute([$visibility, date('Y-m-d H:i:s'), Arr::str($row, 'id'), Arr::int($row, 'version')]); return $this->catalogRow(Arr::str($row, 'id'), $scope); }
        $id = Ids::new(); $db->prepare('INSERT INTO commerce_vendor_catalog (id, vendor_id, product_id, tenant_id, seller_id, store_id, channel_id, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $vendorId, $product, $scope->tenantId, $scope->sellerId, $scope->storeId, $scope->channelId, $visibility, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]); $this->audit('catalog.ownership.changed', $actor, $scope, ['vendor_id' => $vendorId, 'product_id' => $product, 'idempotency_key' => $key]); return $this->catalogRow($id, $scope);
    }

    /** @param array<string,mixed> $data */
    /** @return array<string,mixed> */
    public function commission(array $data, CommerceScope $scope, string $key, string $actor): array
    {
        $vendor = trim(Arr::str($data, 'vendor_id')); $gross = Arr::int($data, 'gross_minor', -1); $rate = Arr::int($data, 'rate_bps', -1); $currency = strtoupper(Arr::str($data, 'currency', 'VND'));
        if ($this->vendor($vendor, $scope) === null || $gross < 0 || $rate < 0 || $rate > 10000) throw new \InvalidArgumentException('Invalid commission request');
        $fingerprint = hash('sha256', json_encode([$vendor, $data['order_id'] ?? null, $gross, $rate, $currency, $scope->key()], JSON_THROW_ON_ERROR)); $requestKey = $scope->key() . ':' . $key; $db = Database::connection();
        $q = $db->prepare('SELECT * FROM commerce_commission_ledger WHERE vendor_id = ? AND request_key = ?'); $q->execute([$vendor, $requestKey]); $old = $q->fetch(\PDO::FETCH_ASSOC); if (is_array($old)) { if (($old['fingerprint'] ?? '') !== $fingerprint) throw new \RuntimeException('Idempotency key conflicts with commission request'); return self::row($old); }
        $commission = intdiv($gross * $rate + 5000, 10000); $id = Ids::new(); $db->prepare('INSERT INTO commerce_commission_ledger (id, vendor_id, order_id, seller_order_id, request_key, fingerprint, gross_minor, commission_minor, net_minor, rate_bps, currency, tenant_id, seller_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $vendor, Arr::str($data, 'order_id'), null, $requestKey, $fingerprint, $gross, $commission, $gross - $commission, $rate, $currency, $scope->tenantId, $scope->sellerId, $scope->storeId, $scope->channelId, date('Y-m-d H:i:s')]); $this->audit('commission.posted', $actor, $scope, ['ledger_id' => $id, 'vendor_id' => $vendor]); return $this->ledger($id, $scope);
    }

    /** @return array<string,mixed>|null */ public function vendor(string $id, CommerceScope $scope): ?array { $q = Database::connection()->prepare('SELECT * FROM commerce_vendors WHERE id = ? AND store_id = ? AND (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) AND (seller_id = ? OR (seller_id IS NULL AND ? IS NULL))'); $q->execute([$id, $scope->storeId, $scope->tenantId, $scope->tenantId, $scope->sellerId, $scope->sellerId]); $r = $q->fetch(\PDO::FETCH_ASSOC); return is_array($r) ? self::row($r) : null; }
    /** @return array<int,array<string,mixed>> */ public function ledgerList(CommerceScope $scope, int $limit, int $offset): array { $q = Database::connection()->prepare('SELECT * FROM commerce_commission_ledger WHERE tenant_id = ? AND seller_id = ? AND store_id = ? AND channel_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'); $q->execute([$scope->tenantId, $scope->sellerId, $scope->storeId, $scope->channelId, $limit, $offset]); return self::rows($q->fetchAll(\PDO::FETCH_ASSOC)); }
    /** @return array<string,mixed> */ public function ledger(string $id, CommerceScope $scope): array { $q = Database::connection()->prepare('SELECT * FROM commerce_commission_ledger WHERE id = ? AND store_id = ? AND channel_id = ?'); $q->execute([$id, $scope->storeId, $scope->channelId]); $r = $q->fetch(\PDO::FETCH_ASSOC); if (!is_array($r)) throw new \RuntimeException('Commission ledger entry not found'); return self::row($r); }
    /** @return array<string,mixed> */ public function payout(string $id, CommerceScope $scope): array { $q = Database::connection()->prepare('SELECT * FROM commerce_payouts WHERE id = ? AND store_id = ? AND channel_id = ?'); $q->execute([$id, $scope->storeId, $scope->channelId]); $r = $q->fetch(\PDO::FETCH_ASSOC); if (!is_array($r)) throw new \RuntimeException('Payout not found'); return self::row($r); }
    /** @param array<string,mixed> $data @return array<string,mixed> */ public function createPayout(array $data, CommerceScope $scope, string $key, string $actor): array { $vendor = Arr::str($data, 'vendor_id'); if ($this->vendor($vendor, $scope) === null) throw new \RuntimeException('Vendor not found'); $id = Ids::new(); $db = Database::connection(); $request = $scope->key() . ':' . $key; $q = $db->prepare('SELECT * FROM commerce_payouts WHERE idempotency_key = ?'); $q->execute([$request]); $old = $q->fetch(\PDO::FETCH_ASSOC); if (is_array($old)) return self::row($old); $db->prepare('INSERT INTO commerce_payouts (id, vendor_id, amount_minor, currency, state, provider_status, metadata_json, tenant_id, seller_id, store_id, channel_id, idempotency_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $vendor, Arr::int($data, 'amount_minor'), strtoupper(Arr::str($data, 'currency', 'VND')), 'pending', 'BLOCKED_EXTERNAL', json_encode(['provider' => null, 'reason' => 'No live payout provider configured'], JSON_THROW_ON_ERROR), $scope->tenantId, $scope->sellerId, $scope->storeId, $scope->channelId, $request, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]); $this->audit('payout.created', $actor, $scope, ['payout_id' => $id]); return $this->payout($id, $scope); }
    public function payoutTransition(string $id, string $state, CommerceScope $scope, string $key, string $actor): array { if (in_array($state, ['processing', 'paid'], true)) throw new \RuntimeException('Payout provider execution is BLOCKED_EXTERNAL'); if (!in_array($state, ['eligible', 'held'], true)) throw new \InvalidArgumentException('Unsupported payout state'); $p = $this->payout($id, $scope); if (($p['state'] ?? '') === $state) return $p; if (($p['state'] ?? '') !== 'pending') throw new \RuntimeException('Invalid payout state transition'); Database::connection()->prepare('UPDATE commerce_payouts SET state = ?, updated_at = ? WHERE id = ? AND state = ?')->execute([$state, date('Y-m-d H:i:s'), $id, 'pending']); $this->audit('payout.state.changed', $actor, $scope, ['payout_id' => $id, 'state' => $state, 'idempotency_key' => $key]); return $this->payout($id, $scope); }
    /** @return array<int,array<string,mixed>> */ public function sellerOrders(CommerceScope $scope, int $limit, int $offset): array { $q = Database::connection()->prepare('SELECT * FROM commerce_seller_orders WHERE tenant_id = ? AND seller_id = ? AND store_id = ? AND channel_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'); $q->execute([$scope->tenantId, $scope->sellerId, $scope->storeId, $scope->channelId, $limit, $offset]); return self::rows($q->fetchAll(\PDO::FETCH_ASSOC)); }
    /** @param array<string,mixed> $data */ public function splitOrder(array $data, CommerceScope $scope, string $key, string $actor): array { $order = trim((string) ($data['order_id'] ?? '')); $parts = is_array($data['parts'] ?? null) ? $data['parts'] : []; if ($order === '' || $parts === []) throw new \InvalidArgumentException('order_id and parts are required'); $db = Database::connection(); $rows = []; foreach ($parts as $part) { if (!is_array($part)) throw new \InvalidArgumentException('Invalid seller order part'); $vendor = (string) ($part['vendor_id'] ?? ''); if ($this->vendor($vendor, $scope) === null) throw new \RuntimeException('Vendor not found'); $id = $order . '-' . $vendor; $exists = $db->prepare('SELECT * FROM commerce_seller_orders WHERE id = ?'); $exists->execute([$id]); if (is_array($exists->fetch(\PDO::FETCH_ASSOC))) { $rows[] = $id; continue; } $subtotal = (int) ($part['subtotal_minor'] ?? 0); $commission = (int) ($part['commission_minor'] ?? 0); $db->prepare('INSERT INTO commerce_seller_orders (id, order_id, vendor_id, subtotal_minor, commission_minor, net_minor, currency, line_ids_json, tenant_id, seller_id, store_id, channel_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, $order, $vendor, $subtotal, $commission, $subtotal - $commission, strtoupper((string) ($data['currency'] ?? 'VND')), json_encode($part['line_ids'] ?? [], JSON_THROW_ON_ERROR), $scope->tenantId, $scope->sellerId, $scope->storeId, $scope->channelId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]); $rows[] = $id; } $this->audit('order.seller_split.created', $actor, $scope, ['order_id' => $order, 'seller_order_ids' => $rows, 'idempotency_key' => $key]); return ['order_id' => $order, 'seller_order_ids' => $rows, 'scope' => $scope->metadata()]; }
    /** @param array<string,mixed> $payload */ private function audit(string $action, string $actor, CommerceScope $scope, array $payload): void { Database::connection()->prepare('INSERT INTO commerce_audit_events (id, action, actor, payload_json, tenant_id, seller_id, site_id, store_id, channel_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([Ids::new(), $action, $actor, json_encode($payload, JSON_THROW_ON_ERROR), $scope->tenantId, $scope->sellerId, $scope->siteId, $scope->storeId, $scope->channelId, date('Y-m-d H:i:s')]); }
    private function catalogRow(string $id, CommerceScope $scope): array { $q = Database::connection()->prepare('SELECT * FROM commerce_vendor_catalog WHERE id = ? AND store_id = ? AND channel_id = ?'); $q->execute([$id, $scope->storeId, $scope->channelId]); return self::row($q->fetch(\PDO::FETCH_ASSOC)); }
    /** @param mixed $r @return array<string,mixed> */ private static function row(mixed $r): array { return is_array($r) ? $r : []; }
    /** @param mixed $rows @return array<int,array<string,mixed>> */ private static function rows(mixed $rows): array { $out = []; if (is_array($rows)) foreach ($rows as $row) if (is_array($row)) $out[] = $row; return $out; }
}
