<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Models\Product;
use App\Models\ProductVoucherCode;
use App\Models\ProductVersion;
use App\Support\Arr;
use App\Support\Ids;
use App\Modules\Catalog\Domain\CatalogConflict;
use Siro\Core\Database;

final class ProductLifecycleService
{
    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function transition(string $productId, array $data): array
    {
        $product = Product::find($productId);
        if ($product === null) throw new \RuntimeException('Product not found');
        $state = Arr::requiredStr(Arr::row($data), 'state');
        if (!in_array($state, ['draft', 'scheduled', 'published', 'archived'], true)) throw new \InvalidArgumentException('Unsupported product lifecycle state');
        $publishAt = Arr::nullableStr(Arr::row($data), 'publish_at');
        if ($state === 'scheduled' && $publishAt === null) throw new \InvalidArgumentException('Scheduled products require publish_at');
        if ($state === 'published' && $publishAt !== null && strtotime($publishAt) === false) throw new \InvalidArgumentException('Invalid publish_at');
        $current = Arr::row($product);
        $expected = array_key_exists('version', $data) ? Arr::int(Arr::row($data), 'version') : Arr::int($current, 'version', 1);
        $changes = ['publish_state' => $state, 'status' => $state === 'published' ? 'active' : ($state === 'archived' ? 'archived' : 'draft'), 'publish_at' => $publishAt, 'archived_at' => $state === 'archived' ? date('Y-m-d H:i:s') : null, 'version' => $expected + 1];
        if (Product::query()->where('id', $productId)->where('version', $expected)->update($changes) !== 1) {
            $actual = Product::find($productId);
            throw new CatalogConflict($productId, $expected, Arr::int(Arr::row($actual), 'version', 1));
        }
        $updated = Product::find($productId);
        $updatedRow = Arr::row($updated);
        ProductVersion::create(['id' => Ids::new(), 'product_id' => $productId, 'version' => Arr::int($updatedRow, 'version', $expected + 1), 'state' => $state, 'approval_status' => Arr::str($updatedRow, 'approval_status', 'pending'), 'snapshot_json' => json_encode($updatedRow, JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
        return $updatedRow;
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
    public function approve(string $productId, array $data): array
    {
        $status = Arr::requiredStr(Arr::row($data), 'approval_status');
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) throw new \InvalidArgumentException('Unsupported approval status');
        $product = Product::find($productId);
        if ($product === null) throw new \RuntimeException('Product not found');
        $current = Arr::row($product);
        $expected = array_key_exists('version', $data) ? Arr::int(Arr::row($data), 'version') : Arr::int($current, 'version', 1);
        if (Product::query()->where('id', $productId)->where('version', $expected)->update(['approval_status' => $status, 'version' => $expected + 1]) !== 1) throw new CatalogConflict($productId, $expected, Arr::int(Arr::row(Product::find($productId)), 'version', 1));
        $updated = Arr::row(Product::find($productId));
        ProductVersion::create(['id' => Ids::new(), 'product_id' => $productId, 'version' => Arr::int($updated, 'version'), 'state' => Arr::str($updated, 'publish_state', 'draft'), 'approval_status' => $status, 'snapshot_json' => json_encode($updated, JSON_THROW_ON_ERROR), 'created_at' => date('Y-m-d H:i:s')]);
        return $updated;
    }

    /** @return array<int, array<string, mixed>> */
    public function versions(string $productId): array
    {
        return array_map(static fn (mixed $row): array => Arr::row($row), ProductVersion::query()->where('product_id', $productId)->orderBy('version', 'desc')->get());
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data
     * @return array<string, mixed> */
    public function addVoucher(string $productId, array $data, string $idempotencyKey): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $product = Product::find($productId);
        if ($product === null || Arr::str($product->toArray(), 'type') !== 'voucher') throw new \InvalidArgumentException('Product is not a voucher');
        $code = strtoupper(trim(Arr::requiredStr(Arr::row($data), 'code')));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{3,63}$/', $code)) throw new \InvalidArgumentException('Voucher code format is invalid');
        $row = Database::transaction(function () use ($productId, $code, $data, $idempotencyKey): array {
            $existing = ProductVoucherCode::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $existingRow = Arr::row($existing);
                if (Arr::str($existingRow, 'product_id') !== $productId || Arr::str($existingRow, 'code_hash') !== hash('sha256', $code)) throw new \InvalidArgumentException('Idempotency key already used');
                return $existingRow;
            }
            $hash = hash('sha256', $code);
            if (ProductVoucherCode::query()->where('product_id', $productId)->where('code_hash', $hash)->first() !== null) throw new \InvalidArgumentException('Voucher code already exists');
            $mask = strlen($code) <= 4 ? str_repeat('*', strlen($code)) : substr($code, 0, 2) . str_repeat('*', strlen($code) - 4) . substr($code, -2);
            $model = ProductVoucherCode::create(['id' => Ids::new(), 'product_id' => $productId, 'code_hash' => $hash, 'code_mask' => $mask, 'state' => 'available', 'expires_at' => Arr::nullableStr(Arr::row($data), 'expires_at'), 'created_at' => date('Y-m-d H:i:s'), 'idempotency_key' => $idempotencyKey]);
            return Arr::row($model);
        });
        return is_array($row) ? Arr::row($row) : throw new \RuntimeException('Voucher code could not be created');
    }

    /** @return array<int, array<string, mixed>> */
    public function vouchers(string $productId): array
    {
        return array_map(static function ($row): array {
            $voucher = Arr::row($row);
            return [
                'id' => Arr::str($voucher, 'id'),
                'product_id' => Arr::str($voucher, 'product_id'),
                'code_mask' => Arr::str($voucher, 'code_mask'),
                'state' => Arr::str($voucher, 'state'),
                'expires_at' => $voucher['expires_at'] ?? null,
                'redeemed_by' => $voucher['redeemed_by'] ?? null,
                'redeemed_order_id' => $voucher['redeemed_order_id'] ?? null,
                'redeemed_at' => $voucher['redeemed_at'] ?? null,
                'created_at' => $voucher['created_at'] ?? null,
            ];
        }, ProductVoucherCode::query()->where('product_id', $productId)->orderBy('created_at')->get());
    }
}
