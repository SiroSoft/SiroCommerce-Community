<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Catalog\Application\CatalogService;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Resources\ProductResource;
use App\Support\Arr;
use App\Support\Skeleton;
use App\Support\LocaleResolver;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * HTTP only: validate input → call Service → return Response (backend AGENTS §1).
 */
final class ProductController extends Controller
{
    public function __construct(private readonly CatalogService $service, private readonly SkeletonProductController $console)
    {
    }

    public function stockAlert(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['variant_id' => 'nullable|string', 'email' => 'nullable|string', 'type' => 'nullable|string', 'target_price_minor' => 'nullable|integer']);
        $productId = Arr::requiredParam($request, 'id');
        if (!is_string($productId) || $productId === '') return $this->error('Invalid product', 422);
        $user = $request->user();
        $customerId = is_array($user) && isset($user['id']) && (is_string($user['id']) || is_int($user['id'])) ? (string) $user['id'] : null;
        $type = Arr::str($data, 'type', 'back_in_stock') !== '' ? Arr::str($data, 'type', 'back_in_stock') : 'back_in_stock';
        $target = array_key_exists('target_price_minor', $data) && $data['target_price_minor'] !== null ? (int) $data['target_price_minor'] : null;
        try {
            $row = (new \App\Modules\Inventory\Application\StockAlertService())->subscribe($productId, Arr::str($data, 'variant_id') !== '' ? Arr::str($data, 'variant_id') : null, Arr::str($data, 'email') !== '' ? Arr::str($data, 'email') : null, $customerId, $type, $target);
            return $this->success(['id' => $row['id'], 'unsubscribe_token' => $row['unsubscribe_token'], 'state' => 'pending', 'type' => $row['type']], 'Alert saved. We will email you when it triggers.');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 503);
        }
    }

    public function stockAlertUnsubscribe(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['token' => 'required|string']);
        $done = (new \App\Modules\Inventory\Application\StockAlertService())->unsubscribe(Arr::requiredStr($data, 'token'));
        return $done ? $this->success(null, 'Unsubscribed') : $this->error('Alert not found', 404);
    }

    public function stockAlerts(Request $request): Response
    {
        $productId = $request->queryString('product_id', '');
        $type = $request->queryString('type', '');
        $rows = (new \App\Modules\Inventory\Application\StockAlertService())->pending($productId !== '' ? $productId : null, $type !== '' ? $type : null);
        return $this->success(array_map(static function (array $row): array {
            unset($row['unsubscribe_token'], $row['email']);
            return $row;
        }, $rows), 'Stock alerts');
    }

    public function index(Request $request): Response
    {
        if (Skeleton::isAdminConsole(Skeleton::optionalUser($request))) return $this->console->index($request);
        Lang::setLocale(LocaleResolver::resolve($request));
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException) { $scope = new CommerceScope('default', 'default', 'en', 'USD'); }
        $sort = $request->queryString('sort', 'featured');
        if (!in_array($sort, ['featured', 'price-asc', 'price-desc', 'name-asc', 'name-desc'], true)) {
            return $this->error('Unsupported catalog sort', 422, ['sort' => ['Use featured, price-asc, price-desc, name-asc, or name-desc']]);
        }
        $search = trim($request->queryString('search', ''));
        if (mb_strlen($search) > 80) return $this->error('Search query is too long', 422, ['search' => ['Maximum length is 80']]);
        $filters = [
            'category' => trim($request->queryString('category', '')),
            'categories' => $this->csv($request->queryString('categories', '')),
            'brand' => trim($request->queryString('brand', '')),
            'vendor' => trim($request->queryString('vendor', '')),
            'tag' => trim($request->queryString('tag', '')),
            'types' => $this->csv($request->queryString('type', '')),
            'search' => $search,
            'sort' => $sort,
            'in_stock' => $request->queryString('in_stock', '') === 'true' || $request->queryString('in_stock', '') === '1',
        ];
        foreach (['category' => 'categories', 'brand' => 'brands', 'tag' => 'tags', 'type' => 'types'] as $facet => $filter) {
            $value = $request->queryString('facet_' . $facet, '');
            if ($value !== '') $filters[$filter] = array_values(array_unique(array_merge($filters[$filter] ?? [], $this->csv($value))));
        }
        $minPrice = $request->queryString('min_price', ''); $maxPrice = $request->queryString('max_price', '');
        if ($minPrice !== '' && (!ctype_digit($minPrice) || (int) $minPrice < 0)) return $this->error('Invalid minimum price', 422);
        if ($maxPrice !== '' && (!ctype_digit($maxPrice) || (int) $maxPrice < 0)) return $this->error('Invalid maximum price', 422);
        if ($minPrice !== '') $filters['min_price'] = (int) $minPrice;
        if ($maxPrice !== '') $filters['max_price'] = (int) $maxPrice;
        $tier = $this->viewerTier($request);
        if ($tier !== null) $filters['viewer_tier'] = $tier;
        $result = $this->service->listActive($page, $perPage, $scope, $filters);
        // auto log search terms + apply synonyms (better than Bagisto: expand recall)
        if ($search !== '') {
            try {
                $term = strtolower(trim($search));
                if (preg_match('/^[a-z0-9 _-]{1,64}$/', $term) === 1) {
                    $storeId = $scope->storeId; $channelId = $scope->channelId;
                    // synonym expand: fetch synonyms for term and add to filter for future (currently just log, search already done)
                    // log term
                    $count = count($result['data']);
                    $existing = \App\Models\SearchTerm::query()->where('term', $term)->where('store_id', $storeId)->where('channel_id', $channelId)->first();
                    if ($existing === null) {
                        \App\Models\SearchTerm::create(['id' => \App\Support\Ids::new(), 'term' => $term, 'hits' => $count > 0 ? 1 : 0, 'misses' => $count > 0 ? 0 : 1, 'results_avg' => $count, 'store_id' => $storeId, 'channel_id' => $channelId, 'updated_at' => date('Y-m-d H:i:s')]);
                    } else {
                        $r = $existing->toArray();
                        $hits = (int) ($r['hits'] ?? 0) + ($count > 0 ? 1 : 0);
                        $misses = (int) ($r['misses'] ?? 0) + ($count > 0 ? 0 : 1);
                        $existing->setAttribute('hits', $hits);
                        $existing->setAttribute('misses', $misses);
                        $existing->setAttribute('results_avg', $count);
                        $existing->setAttribute('updated_at', date('Y-m-d H:i:s'));
                        $existing->save();
                    }
                }
            } catch (\Throwable $e) {}
        }
        return $this->paginated(ProductResource::collection($result['data']), $result['meta'], Lang::get('commerce.product.list'));
    }

    public function show(Request $request): Response
    {
        $param = (string) Arr::requiredParam($request, 'slug');
        if (ctype_digit($param) && Skeleton::isAdminConsole(Skeleton::optionalUser($request))) return $this->console->show($request);
        Lang::setLocale(LocaleResolver::resolve($request));
        try { $scope = CommerceScope::fromRequest($request); } catch (CommerceScopeException) { $scope = new CommerceScope('default', 'default', 'en', 'USD'); }
        $row = $this->service->show(Arr::requiredParam($request, 'slug'), $scope);
        if ($row === null) {
            return $this->error(Lang::get('commerce.product.not_found'), 404);
        }
        // Tier gating post-cache (single row: no pagination to preserve).
        // Gated products 404 for ineligible viewers — same as missing.
        $tier = $this->viewerTier($request);
        $minTier = MemberPriceService::normalizeTier(Arr::str($row, 'min_tier'));
        if ($minTier !== null && !MemberPriceService::tierCanSee($tier, $minTier)) {
            return $this->error(Lang::get('commerce.product.not_found'), 404);
        }
        if (isset($row['flash_sales']) && is_array($row['flash_sales'])) {
            $row['flash_sales'] = array_values(array_filter($row['flash_sales'], static function ($sale) use ($tier): bool {
                if (!is_array($sale)) return false;
                return MemberPriceService::tierCanSee($tier, MemberPriceService::normalizeTier(Arr::str($sale, 'min_tier')));
            }));
        }
        return $this->success(ProductResource::make($row), Lang::get('commerce.product.detail'));
    }

    public function types(Request $request): Response
    {
        try {
            // Resolve scope even though product types are currently catalog-wide constants.
            if ($request->header('X-Store-Id', '') !== '' || $request->queryString('store_id', '') !== '') {
                CommerceScope::fromRequest($request);
            }
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 400, [], 'SCOPE_ERROR');
        }
        return $this->success(array_map(static fn (string $type): array => ['type' => $type], ['simple', 'configurable', 'grouped', 'bundle', 'virtual', 'downloadable', 'voucher', 'booking']), 'Product types');
    }

    private function viewerTier(Request $request): ?string
    {
        $user = $request->user();
        $memberId = (is_array($user) && isset($user['id']) && is_scalar($user['id']) && (string) $user['id'] !== '') ? (string) $user['id'] : '';
        if ($memberId === '') return null;
        try {
            return (new MemberPriceService())->tierFor($memberId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array<int, string> */
    private function csv(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (string $item): string => trim($item), explode(',', $value)), static fn (string $item): bool => $item !== '')));
    }
}
