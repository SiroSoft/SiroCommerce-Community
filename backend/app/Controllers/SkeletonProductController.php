<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Modules\Catalog\Application\CatalogAdminService;
use App\Modules\Inventory\Application\InventoryAdminService;
use App\Repositories\ProductRepository;
use App\Support\Arr;
use App\Support\Skeleton;
use Siro\Core\Str;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin-console product surface (skeleton API dialect): numeric ids,
 * major-unit money, aggregated stock. Read/write; storage stays canonical.
 */
final class SkeletonProductController extends Controller
{
    public function __construct(private readonly CatalogAdminService $products, private readonly InventoryAdminService $inventory)
    {
    }

    public function index(Request $request): Response
    {
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        $search = trim($request->queryString('search', '') ?: $request->queryString('q', ''));
        $query = Product::query()->orderBy('id', 'asc');
        if ($search !== '') $query->whereRaw('(name LIKE ? OR slug LIKE ?)', ['%' . $search . '%', '%' . $search . '%']);
        $total = $query->count();
        $rows = $query->limit($perPage)->offset(($page - 1) * $perPage)->get();
        $data = array_map(fn ($model): array => self::row($model->toArray()), $rows);
        return $this->paginated($data, ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => (int) max(1, ceil($total / $perPage))], 'Products list');
    }

    public function show(Request $request): Response
    {
        $raw = '';
        foreach (['id', 'slug'] as $key) {
            try { $raw = (string) Arr::requiredParam($request, $key); break; }
            catch (\Throwable) { continue; }
        }
        $row = $raw === '' ? null : self::find($raw);
        if ($row === null) return $this->error('Product not found', 404);
        return $this->success(self::row($row), 'Product detail');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string', 'slug' => 'nullable|string', 'sku' => 'nullable|string',
            'description' => 'nullable|string', 'short_description' => 'nullable|string',
            'price' => 'nullable|numeric', 'compare_price' => 'nullable|numeric', 'stock' => 'nullable|integer',
            'status' => 'nullable|string', 'is_active' => 'nullable|boolean', 'category_id' => 'nullable|integer',
            'type' => 'nullable|string', 'vendor_id' => 'nullable|string',
        ]);
        $name = trim(Arr::requiredStr($data, 'name'));
        if ($name === '') return $this->error('Name is required', 422, ['name' => ['Name is required']]);
        $priceMinor = Skeleton::minor($data['price'] ?? 0) ?? 0;
        $compareMinor = Skeleton::minor($data['compare_price'] ?? null);
        if ($compareMinor !== null && $compareMinor < $priceMinor) return $this->error('Compare-at price must be greater than or equal to price', 422, ['compare_price' => ['Must be greater than or equal to price']]);
        $stock = isset($data['stock']) ? (int) $data['stock'] : 0;
        if ($stock < 0) return $this->error('Stock cannot be negative', 422, ['stock' => ['Must be zero or greater']]);
        $status = Arr::str($data, 'status', '');
        if ($status === '' && isset($data['is_active'])) $status = $data['is_active'] ? 'active' : 'draft';
        if ($status === '') $status = 'draft';
        $categoryId = isset($data['category_id']) ? Skeleton::resolveId('categories', (int) $data['category_id']) : null;
        $payload = [
            'name' => $name, 'slug' => Arr::str($data, 'slug', Str::slug($name)), 'status' => $status,
            'type' => 'simple', 'store_id' => 'default', 'channel_id' => 'default', 'locale' => 'en', 'currency' => 'VND',
            'sku' => Arr::str($data, 'sku', ''), 'price_minor' => $priceMinor,
            'stock' => $stock, 'description' => trim(Arr::str($data, 'description', '') ?: Arr::str($data, 'short_description', '')),
        ];
        if ($compareMinor !== null) $payload['compare_price_minor'] = $compareMinor;
        if ($categoryId !== null) $payload['category_id'] = $categoryId;
        if (array_key_exists('vendor_id', $data)) $payload['vendor_id'] = $data['vendor_id'];
        try { $product = $this->products->create($payload); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, ['slug' => [$e->getMessage()]]); }
        $row = self::find(Arr::str($product, 'id'));
        return $this->created(self::row($row ?? $product), 'Product created');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string', 'slug' => 'nullable|string', 'description' => 'nullable|string',
            'price' => 'nullable|numeric', 'compare_price' => 'nullable|numeric', 'stock' => 'nullable|integer',
            'status' => 'nullable|string', 'is_active' => 'nullable|boolean', 'vendor_id' => 'nullable|string',
            'subscription_plan_ids' => 'nullable|array',
        ]);
        $id = self::resolve((string) Arr::requiredParam($request, 'id'));
        if ($id === null) return $this->error('Product not found', 404);
        $patch = [];
        if (isset($data['name']) && trim((string) $data['name']) !== '') $patch['name'] = trim((string) $data['name']);
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') $patch['slug'] = trim((string) $data['slug']);
        if (isset($data['status']) && trim((string) $data['status']) !== '') $patch['status'] = trim((string) $data['status']);
        elseif (isset($data['is_active'])) $patch['status'] = $data['is_active'] ? 'active' : 'draft';
        if (isset($data['description']) && is_string($data['description'])) $patch['description'] = $data['description'];
        if (array_key_exists('vendor_id', $data)) $patch['vendor_id'] = $data['vendor_id'];
        if ($patch !== []) {
            $updated = $this->products->update($id, $patch);
            if ($updated === null) return $this->error('Product not found', 404);
        }
        $variant = ProductVariant::query()->where('product_id', $id)->orderBy('id', 'asc')->first();
        if ($variant !== null) {
            $priceMinor = array_key_exists('price', $data) ? Skeleton::minor($data['price']) : null;
            $compareMinor = array_key_exists('compare_price', $data) ? Skeleton::minor($data['compare_price']) : null;
            if ($priceMinor !== null || $compareMinor !== null) {
                $variantPatch = [];
                if ($priceMinor !== null) $variantPatch['price_minor'] = $priceMinor;
                if ($compareMinor !== null) $variantPatch['compare_price_minor'] = $compareMinor;
                if ($variantPatch !== []) {
                    $oldPrice = (int) ($variant->getAttribute('price_minor') ?? 0);
                    $variant->update($variantPatch);
                    // Strictly-lower base price wakes pending price-drop alerts.
                    if ($priceMinor !== null && $priceMinor < $oldPrice) {
                        try {
                            (new \App\Modules\Inventory\Application\StockAlertService())->checkPriceDrop((string) $variant->getAttribute('id'), $priceMinor);
                        } catch (\Throwable) {
                        }
                    }
                }
            }
            if (isset($data['stock'])) {
                $stock = (int) $data['stock'];
                if ($stock < 0) return $this->error('Stock cannot be negative', 422, ['stock' => ['Must be zero or greater']]);
                $this->inventory->setOnHand((string) $variant->getAttribute('id'), $stock, 'admin-console');
            }
        }
        $row = self::find($id);
        if ($row === null) return $this->error('Product not found', 404);
        if (array_key_exists('subscription_plan_ids', $data)) {
            if (!is_array($data['subscription_plan_ids'])) return $this->error('subscription_plan_ids must be an array', 422);
            try {
                (new ProductRepository())->syncSubscriptionPlans($id, array_values($data['subscription_plan_ids']));
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }
            $row = self::find($id) ?? $row;
        }
        return $this->success(self::row($row), 'Product updated');
    }

    public function destroy(Request $request): Response
    {
        $id = self::resolve((string) Arr::requiredParam($request, 'id'));
        if ($id === null) return $this->error('Product not found', 404);
        if (!$this->products->delete($id)) return $this->error('Product not found', 404);
        return $this->noContent();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function row(array $row): array
    {
        $id = Arr::str($row, 'id');
        $variant = ProductVariant::query()->where('product_id', $id)->where('status', 'active')->orderBy('id', 'asc')->first();
        $variantRow = $variant === null ? [] : $variant->toArray();
        $stock = null;
        if ($variant !== null) {
            $stockRow = Database::connection()->prepare('SELECT COALESCE(SUM(on_hand-reserved),0) FROM inventory_items WHERE variant_id=?');
            $stockRow->execute([(string) $variant->getAttribute('id')]);
            $stock = (int) $stockRow->fetchColumn();
        }
        $image = ProductImage::query()->where('product_id', $id)->orderBy('sort', 'asc')->first();
        $status = Arr::str($row, 'status', 'draft');
        $categoryId = Arr::str($row, 'category_id', '');
        $vendorId = Arr::str($row, 'vendor_id');
        $subscriptionPlanIds = [];
        foreach (ProductSubscriptionPlan::query()->where('product_id', $id)->orderBy('sort', 'asc')->orderBy('id', 'asc')->get() as $link) {
            $planId = Arr::str(Arr::row($link), 'plan_id');
            if ($planId !== '' && !in_array($planId, $subscriptionPlanIds, true)) $subscriptionPlanIds[] = $planId;
        }
        return [
            'id' => Skeleton::num($id), 'name' => Arr::str($row, 'name'), 'slug' => Arr::str($row, 'slug'),
            'sku' => Arr::str($variantRow, 'sku', ''), 'description' => Arr::str($row, 'description', ''),
            'image' => $image === null ? null : Arr::str($image->toArray(), 'url'),
            'price' => Skeleton::major((int) ($variantRow['price_minor'] ?? 0)),
            'compare_price' => isset($variantRow['compare_price_minor']) && $variantRow['compare_price_minor'] !== null ? Skeleton::major((int) $variantRow['compare_price_minor']) : null,
            'stock' => $stock, 'status' => $status, 'is_active' => $status === 'active', 'is_featured' => false,
            'category_id' => $categoryId === '' ? null : Skeleton::num($categoryId),
            'vendor_id' => $vendorId === '' ? null : $vendorId,
            'subscription_plan_ids' => $subscriptionPlanIds,
            'created_at' => Skeleton::date($row['created_at'] ?? null), 'updated_at' => Skeleton::date($row['updated_at'] ?? null),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $idOrNum): ?array
    {
        $id = ctype_digit($idOrNum) ? Skeleton::resolveId('products', (int) $idOrNum) : $idOrNum;
        if ($id === null) return null;
        $row = Product::find($id);
        if ($row === null) return null;
        /** @var array<string, mixed> $out */
        $out = $row->toArray();
        return $out;
    }

    private static function resolve(string $idOrNum): ?string
    {
        if (!ctype_digit($idOrNum)) {
            $row = Product::find($idOrNum);
            return $row === null ? null : $idOrNum;
        }
        return Skeleton::resolveId('products', (int) $idOrNum);
    }
}
