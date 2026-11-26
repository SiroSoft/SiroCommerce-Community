<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Catalog\Application\CatalogCompletenessService;
use App\Modules\Catalog\Contracts\CatalogCompletenessPort;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

final class AdminCatalogController extends Controller
{
    public function __construct(private readonly CatalogCompletenessService $catalog, private readonly CatalogCompletenessPort $reader, private readonly ProductRepository $products) {}

    private function scopedProduct(Request $request, string $key = 'id'): ?string
    {
        $scope = CommerceScope::fromRequest($request);
        $id = Arr::requiredParam($request, $key);
        return $this->products->findInScope($id, $scope) === null ? null : $id;
    }

    public function options(Request $request): Response
    {
        $data = $request->validate(['name' => 'required|string', 'sort' => 'nullable|integer']);
        $id = $this->scopedProduct($request); if ($id === null) return $this->error('Product not found', 404);
        try { return $this->created($this->catalog->addOption($id, $data), 'Option created'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function optionValue(Request $request): Response
    {
        $data = $request->validate(['value' => 'required|string', 'sort' => 'nullable|integer', 'surcharge_minor' => 'nullable|integer', 'currency' => 'nullable|string']);
        $product = $this->scopedProduct($request, 'product'); if ($product === null) return $this->error('Product not found', 404);
        if (!$this->products->optionBelongsToProduct(Arr::requiredParam($request, 'option'), $product)) return $this->error('Option not found', 404);
        try { return $this->created($this->catalog->addOptionValue($product, Arr::requiredParam($request, 'option'), $data), 'Option value created'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function listOptions(Request $request): Response
    {
        $id = $this->scopedProduct($request); if ($id === null) return $this->error('Product not found', 404);
        return $this->success($this->reader->options($id), 'Options');
    }

    public function media(Request $request): Response
    {
        $product = $this->scopedProduct($request); if ($product === null) return $this->error('Product not found', 404);
        $file = $request->file('file');
        if ($file !== null) {
            $data = $request->validate(['alt' => 'nullable|string', 'title' => 'nullable|string', 'role' => 'nullable|string', 'sort' => 'nullable|integer']);
            try { return $this->created($this->catalog->addUploadedMedia($product, $file, $data), 'Media uploaded'); }
            catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        }
        $data = $request->validate(['url' => 'required|string', 'alt' => 'nullable|string', 'title' => 'nullable|string', 'role' => 'nullable|string', 'type' => 'nullable|string', 'mime_type' => 'nullable|string', 'width' => 'nullable|integer', 'height' => 'nullable|integer', 'size_bytes' => 'nullable|integer', 'checksum' => 'nullable|string', 'sort' => 'nullable|integer']);
        try { return $this->created($this->catalog->addMedia($product, $data), 'Media created'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function deleteMedia(Request $request): Response
    {
        $product = $this->scopedProduct($request, 'product'); if ($product === null) return $this->error('Product not found', 404);
        try { $media = $this->catalog->removeMedia($product, Arr::requiredParam($request, 'media')); }
        catch (\RuntimeException) { return $this->error('Media cleanup is pending; retry the operation', 503); }
        return $media === null ? $this->error('Media not found', 404) : $this->noContent();
    }

    public function reorderMedia(Request $request): Response
    {
        $product = $this->scopedProduct($request); if ($product === null) return $this->error('Product not found', 404);
        $data = $request->validate(['media_ids' => 'required|array']);
        $ids = $data['media_ids'] ?? [];
        if (!is_array($ids) || array_filter($ids, 'is_string') !== $ids) return $this->error('media_ids must contain strings', 422);
        try { $this->catalog->reorderMedia($product, array_values($ids)); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $this->success(null, 'Media reordered');
    }

    public function listMedia(Request $request): Response
    {
        $id = $this->scopedProduct($request); if ($id === null) return $this->error('Product not found', 404);
        return $this->success($this->reader->media($id), 'Media');
    }

    public function variants(Request $request): Response
    {
        $id = $this->scopedProduct($request); if ($id === null) return $this->error('Product not found', 404);
        return $this->success($this->reader->variants($id), 'Variants');
    }

    public function variant(Request $request): Response
    {
        $data = $request->validate([
            'sku' => 'required|string', 'price_minor' => 'required|integer', 'currency' => 'nullable|string',
            'compare_price_minor' => 'nullable|integer', 'tax_class' => 'nullable|string', 'weight_g' => 'nullable|integer',
            'length_mm' => 'nullable|integer', 'width_mm' => 'nullable|integer', 'height_mm' => 'nullable|integer',
            'option_value_ids' => 'nullable|array',
        ]);
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : $scope->currency;
        if ($currency !== $scope->currency) return $this->error('Variant currency must match the active commerce scope', 422, ['currency' => ['Must match the active commerce scope currency']]);
        $data['currency'] = $currency;
        if ($data['price_minor'] < 0 || (isset($data['compare_price_minor']) && $data['compare_price_minor'] < $data['price_minor'])) return $this->error('Compare-at price must be greater than or equal to price', 422, ['compare_price_minor' => ['Must be greater than or equal to price']]);
        $id = $this->scopedProduct($request); if ($id === null) return $this->error('Product not found', 404);
        try { return $this->created($this->catalog->addVariant($id, $data), 'Variant created'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function import(Request $request): Response
    {
        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'rows' => 'required|array',
            'rows.*.name' => 'required|string',
            'rows.*.slug' => 'nullable|string',
            'rows.*.type' => 'nullable|string',
            'rows.*.price_minor' => 'nullable|integer',
            'rows.*.compare_price_minor' => 'nullable|integer',
            'rows.*.currency' => 'nullable|string',
            'validate_only' => 'nullable|boolean',
        ]);
        $rows = $input['rows'] ?? [];
        if (!is_array($rows)) return $this->error('rows must be an array', 422);
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        return $this->success($this->catalog->bulkImport(array_values($rows), $scope, (bool) ($input['validate_only'] ?? false)), 'Catalog import completed');
    }
}
