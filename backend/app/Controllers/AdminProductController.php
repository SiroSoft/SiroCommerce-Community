<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Catalog\Application\CatalogAdminService;
use App\Modules\Catalog\Domain\CatalogConflict;
use App\Support\Arr;
use App\Support\LocaleResolver;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Repositories\ProductRepository;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;
use Siro\Core\Str;
use App\Modules\Shared\Domain\Currency;

/**
 * Merchant product admin (spec 04: create simple product ≤ 60s).
 * Routes gated by `auth:admin,owner,marketer` + DemoGuard.
 */
final class AdminProductController extends Controller
{
    public function __construct(
        private readonly CatalogAdminService $products,
        private readonly ProductRepository $productRows,
    ) {
    }

    public function index(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $filters = [
            'search' => $request->queryString('search', '') ?: $request->queryString('q', ''),
            'status' => $request->queryString('status', ''),
            'category_id' => $request->queryString('category_id', ''),
            'in_stock' => $request->queryString('in_stock', '') === '1',
        ];
        foreach (['min_price', 'max_price'] as $key) {
            $value = $request->queryString($key, '');
            if ($value !== '' && is_numeric($value)) $filters[$key] = (int) $value;
        }
        $result = $this->productRows->paginateAdmin($page, $perPage, $scope, $filters);
        return $this->paginated($result['data'], $result['meta'], Lang::get('commerce.product.list'));
    }

    public function store(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string',
            'slug' => 'nullable|string',
            'status' => 'nullable|string',
            'vendor_id' => 'nullable|string',
            'price_minor' => 'nullable|integer',
            'price' => 'nullable|numeric',
            'compare_price_minor' => 'nullable|integer',
            'compare_price' => 'nullable|numeric',
            'stock' => 'nullable|integer',
            'sku' => 'nullable|string',
            'type' => 'nullable|string',
            'category_id' => 'nullable|string',
            'description' => 'nullable|string',
            'tags' => 'nullable|array',
            'min_tier' => 'nullable|string',
            'allow_backorder' => 'nullable|boolean',
            'is_preorder' => 'nullable|boolean',
            'meta_title' => 'nullable|string',
            'meta_description' => 'nullable|string',
            'publish_state' => 'nullable|string',
            'publish_at' => 'nullable|string',
            'delivery_metadata' => 'nullable|array',
            'eligibility_rules' => 'nullable|array',
        ]);
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $minorUnit = Currency::minorUnit($scope->currency);
        if (array_key_exists('price', $data)) { $data['price_minor'] = (int) round((float) $data['price'] * (10 ** $minorUnit)); unset($data['price']); }
        if (array_key_exists('compare_price', $data)) { $data['compare_price_minor'] = (int) round((float) $data['compare_price'] * (10 ** $minorUnit)); unset($data['compare_price']); }
        if (isset($data['price_minor']) && $data['price_minor'] < 0) return $this->error('Price cannot be negative', 422, ['price_minor' => ['Must be zero or greater']]);
        if (isset($data['stock']) && $data['stock'] < 0) return $this->error('Stock cannot be negative', 422, ['stock' => ['Must be zero or greater']]);
        if (isset($data['compare_price_minor']) && (!isset($data['price_minor']) || $data['compare_price_minor'] < $data['price_minor'])) return $this->error('Compare-at price must be greater than or equal to price', 422, ['compare_price_minor' => ['Must be greater than or equal to price']]);
        if (isset($data['tags']) && !is_array($data['tags'])) return $this->error('Tags must contain strings', 422);
        if (isset($data['tags'])) {
            $tags = array_values(array_filter(array_map(static fn (mixed $tag): string => is_string($tag) ? trim($tag) : '', $data['tags']), static fn (string $tag): bool => $tag !== ''));
            $data['tags_json'] = json_encode(array_values(array_unique($tags)), JSON_THROW_ON_ERROR); unset($data['tags']);
        }
        foreach (['delivery_metadata' => 'delivery_metadata_json', 'eligibility_rules' => 'eligibility_rules_json'] as $input => $column) {
            if (isset($data[$input]) && is_array($data[$input])) { $data[$column] = json_encode($data[$input], JSON_THROW_ON_ERROR); unset($data[$input]); }
        }
        try { $product = $this->products->create($scope->metadata() + $data); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422, str_contains($e->getMessage(), 'Slug') ? ['slug' => [$e->getMessage()]] : []); }
        return $this->created($product, Lang::get('commerce.product.detail'));
    }

    public function component(Request $request): Response
    {
        $data = $request->validate(['product_id' => 'required|string', 'component_variant_id' => 'required|string', 'qty' => 'required|integer']);
        try { $scope = CommerceScope::fromRequest($request); if ($this->productRows->findInScope(Arr::requiredStr($data, 'product_id'), $scope) === null) return $this->error('Product not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        if (!is_int($data['qty'] ?? null) || $data['qty'] <= 0) return $this->error('Component quantity must be positive', 422);
        return $this->created($this->products->addComponent($data), 'Product component added');
    }

    public function digitalAsset(Request $request): Response
    {
        $data = $request->validate(['variant_id' => 'required|string', 'name' => 'required|string', 'url' => 'required|string', 'access_key' => 'required|string', 'download_limit' => 'nullable|integer', 'expires_at' => 'nullable|string']);
        try { $scope = CommerceScope::fromRequest($request); if (!$this->productRows->variantInScope(Arr::requiredStr($data, 'variant_id'), $scope)) return $this->error('Variant not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        return $this->created($this->products->addDigitalAsset($data), 'Digital asset added');
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
            'status' => 'nullable|string',
            'vendor_id' => 'nullable|string',
            'version' => 'nullable|integer',
            'min_tier' => 'nullable|string',
            'is_preorder' => 'nullable|boolean',
            'subscription_plan_ids' => 'nullable|array',
        ]);
        try { $scope = CommerceScope::fromRequest($request); $id = Arr::requiredParam($request, 'id'); if ($this->productRows->findInScope($id, $scope) === null) return $this->error('Product not found', 404); $updated = $this->products->update($id, $data); }
        catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (CatalogConflict $e) { return $this->error($e->getMessage(), 409, ['version' => [$e->actualVersion]]); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        if ($updated === null) {
            return $this->error(Lang::get('commerce.product.not_found'), 404);
        }
        if (array_key_exists('subscription_plan_ids', $data)) {
            if (!is_array($data['subscription_plan_ids'])) return $this->error('subscription_plan_ids must be an array', 422);
            try {
                $this->productRows->syncSubscriptionPlans($id, array_values($data['subscription_plan_ids']));
                $updated['subscription_plan_ids'] = $this->productRows->subscriptionPlanIdsForProduct($id);
            } catch (\InvalidArgumentException $e) {
                return $this->error($e->getMessage(), 422);
            }
        }
        return $this->success($updated, Lang::get('commerce.product.detail'));
    }

    public function destroy(Request $request): Response
    {
        try { $scope = CommerceScope::fromRequest($request); $id = Arr::requiredParam($request, 'id'); if ($this->productRows->findInScope($id, $scope) === null) return $this->error('Product not found', 404); } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        $ok = $this->products->delete($id);
        if (!$ok) {
            return $this->error(Lang::get('commerce.product.not_found'), 404);
        }
        return $this->noContent();
    }
}
