<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Catalog\Application\CatalogTaxonomyService;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Repositories\ProductRepository;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;

final class CatalogTaxonomyController extends Controller
{
    public function __construct(private readonly CatalogTaxonomyService $catalog, private readonly ProductRepository $products) {}

    public function brands(Request $request): Response { return $this->success($this->catalog->brands(), 'Brands'); }
    public function categories(Request $request): Response { return $this->success($this->catalog->categories(), 'Categories'); }
    public function tags(Request $request): Response
    {
        try {
            return $this->success($this->products->tags($this->scope($request)), 'Tags');
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 400, [], 'SCOPE_ERROR');
        }
    }

    private function scope(Request $request): CommerceScope
    {
        if ($request->header('X-Store-Id', '') === '' && $request->queryString('store_id', '') === '') {
            return new CommerceScope('default', 'default', 'en', 'USD');
        }
        return CommerceScope::fromRequest($request);
    }
}
