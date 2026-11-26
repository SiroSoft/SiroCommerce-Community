<?php
declare(strict_types=1);
namespace App\Modules\Catalog\Application;
use App\Modules\Catalog\Contracts\CatalogAdminPort;
use App\Modules\Inventory\Application\InventoryAdminService;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use App\Models\Vendor;
use App\Support\Arr;
use Siro\Core\Audit;
use Siro\Core\Metrics;
use Siro\Core\Str;
final class CatalogAdminService
{
    public function __construct(private readonly CatalogAdminPort $catalog, private readonly InventoryAdminService $inventory) {}
    /** @return array{data: array<int, array<string, mixed>>, meta: array{page: int, per_page: int, total: int, last_page: int}} */
    public function list(int $page, int $perPage): array { return $this->catalog->activePage($page, $perPage); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $name = Arr::requiredStr($data, 'name'); $slug = Arr::str($data, 'slug', Str::slug($name));
        if ($this->catalog->bySlug($slug) !== null) throw new \InvalidArgumentException('Slug already taken');
        $type = Arr::str($data, 'type', 'simple');
         $allowed = ['simple', 'configurable', 'grouped', 'bundle', 'virtual', 'downloadable', 'voucher'];
        if (!in_array($type, $allowed, true)) throw new \InvalidArgumentException('Unsupported product type');
         $minTier = trim(Arr::str($data, 'min_tier'));
        if ($minTier !== '' && \App\Modules\Promotion\Application\MemberPriceService::normalizeTier($minTier) === null) throw new \InvalidArgumentException('min_tier must be bronze|silver|gold|platinum');
        if (array_key_exists('vendor_id', $data)) $data['vendor_id'] = $this->normalizeVendorId($data['vendor_id']);
         $product = $this->catalog->create(['name' => $name, 'slug' => $slug, 'status' => Arr::str($data, 'status', 'draft'), 'type' => $type] + array_intersect_key($data, array_flip(['store_id', 'channel_id', 'locale', 'currency', 'category_id', 'description', 'tags_json', 'allow_backorder', 'is_preorder', 'meta_title', 'meta_description', 'vendor_id'])) + ($minTier !== '' ? ['min_tier' => strtolower($minTier)] : []));
        $product['variants'] = [];
        if (in_array($type, ['simple', 'configurable', 'virtual', 'downloadable'], true)) {
             $variant = $this->catalog->createVariant(['product_id' => Arr::requiredStr($product, 'id'), 'sku' => Arr::str($data, 'sku', strtoupper($slug) . '-STD'), 'price_minor' => Arr::int($data, 'price_minor'), 'compare_price_minor' => Arr::int($data, 'compare_price_minor') ?: null]);
            $this->inventory->setOnHand(Arr::requiredStr($variant, 'id'), Arr::int($data, 'stock'), 'admin');
            $product['variants'] = [$variant];
        }
        Audit::log('commerce.catalog.created', ['product_id' => $product['id'], 'type' => $type]); Metrics::counter('commerce_catalog_mutations_total', 1, ['operation' => 'create']);
        Hooks::notify(HookName::CatalogProductSaved, ['product_id' => Arr::requiredStr($product, 'id'), 'operation' => 'create']);
        return $product;
    }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        if (array_key_exists('vendor_id', $data)) $data['vendor_id'] = $this->normalizeVendorId($data['vendor_id']);
        $updated = $this->catalog->update($id, $data);
        if ($updated !== null) Hooks::notify(HookName::CatalogProductSaved, ['product_id' => $id, 'operation' => 'update']);
        return $updated;
    }
    public function delete(string $id): bool { return $this->catalog->delete($id); }

    private function normalizeVendorId(mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_scalar($value)) throw new \InvalidArgumentException('Vendor id must be a string');
        $vendorId = trim((string) $value);
        if ($vendorId === '') return null;
        if (Vendor::find($vendorId) === null) throw new \InvalidArgumentException('Vendor not found');
        return $vendorId;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addComponent(array $data): array { return $this->catalog->addComponent($data); }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addDigitalAsset(array $data): array { return $this->catalog->addDigitalAsset($data); }
}
