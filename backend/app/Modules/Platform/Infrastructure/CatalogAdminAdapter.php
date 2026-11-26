<?php
declare(strict_types=1);
namespace App\Modules\Platform\Infrastructure;
use App\Modules\Catalog\Contracts\CatalogAdminPort;
use App\Modules\Catalog\Contracts\CatalogTaxonomyPort;
use App\Modules\Catalog\Contracts\CatalogCompletenessPort;
use App\Repositories\ProductRepository;
final class CatalogAdminAdapter implements CatalogAdminPort, CatalogTaxonomyPort, CatalogCompletenessPort
{
    public function __construct(private readonly ProductRepository $products) {}
    public function activePage(int $page, int $perPage): array { return $this->products->paginateActive($page, $perPage); }
    public function bySlug(string $slug): ?array { return $this->products->findBySlug($slug); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array { return $this->products->store($data); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array { return $this->products->updateProduct($id, $data); }
    public function delete(string $id): bool { return $this->products->deleteProduct($id); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createVariant(array $data): array { return $this->products->storeVariant($data); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addComponent(array $data): array { return $this->products->storeComponent($data); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addDigitalAsset(array $data): array { return $this->products->storeDigitalAsset($data); }
    public function components(string $productId): array { return $this->products->components($productId); }
    public function digitalAsset(string $assetId): ?array { return $this->products->digitalAsset($assetId); }
    public function brands(bool $includeArchived = false): array { return $this->products->brands($includeArchived); }
    public function categories(bool $includeArchived = false): array { return $this->products->categories($includeArchived); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addOption(string $productId, array $data): array { return $this->products->storeOption($data + ['product_id' => $productId]); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addOptionValue(string $optionId, array $data): array { return $this->products->storeOptionValue($data + ['option_id' => $optionId]); }
    public function options(string $productId): array { return $this->products->options($productId); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addMedia(string $productId, array $data): array { return $this->products->storeImage($data + ['product_id' => $productId]); }
    public function media(string $productId): array { return $this->products->images($productId); }
    public function removeMedia(string $productId, string $mediaId): ?array { return $this->products->deleteImage($productId, $mediaId); }
    /** @param array<int, string> $mediaIds */
    public function reorderMedia(string $productId, array $mediaIds): void { $this->products->reorderImages($productId, $mediaIds); }
    public function productBySlug(string $slug): ?array { return $this->products->findBySlug($slug); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function importProduct(array $data): array { return $this->products->store($data); }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addVariant(string $productId, array $data): array { return $this->products->storeVariant($data + ['product_id' => $productId]); }
    public function assignVariantOptions(string $variantId, array $optionValueIds): void
    {
        foreach ($optionValueIds as $optionValueId) {
            $value = \App\Models\ProductOptionValue::find($optionValueId);
            if ($value === null) throw new \InvalidArgumentException('Option value not found');
            $valueRow = $value->toArray();
            $optionId = $valueRow['option_id'] ?? null;
            if (!is_string($optionId)) throw new \InvalidArgumentException('Option value has no option');
            $this->products->storeVariantOption($variantId, $optionId, $optionValueId);
        }
    }
    public function variants(string $productId): array { return $this->products->variants($productId); }
    /** @param array<int, string> $optionValueIds */
    public function variantCombinationExists(string $productId, array $optionValueIds): bool { return $this->products->variantCombinationExists($productId, $optionValueIds); }
    /** @param array<int, string> $optionValueIds */
    public function optionValuesBelongToProduct(string $productId, array $optionValueIds): bool { return $this->products->optionValuesBelongToProduct($productId, $optionValueIds); }
}
