<?php

declare(strict_types=1);

namespace Tests\Support;

final class OwnedContentRoutes
{
    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            'GET /api/products', 'GET /api/products/{slug}', 'GET /api/pages/{slug}', 'GET /api/menus/{key}',
            'POST /api/headless/graphql', 'GET /api/products/{id}/reviews', 'GET /api/v2/catalog/brands', 'GET /api/v2/catalog/categories',
            'GET /api/admin/pages', 'POST /api/admin/pages', 'PUT /api/admin/pages/{id}',
            'GET /api/admin/pages/{id}/preview', 'POST /api/admin/pages/{id}/blocks', 'PUT /api/admin/blocks/{id}',
             'GET /api/admin/cms-revisions/{type}/{id}', 'POST /api/v2/headless/pages',
             'GET /api/admin/cms-revisions/{type}/{id}/diff', 'POST /api/admin/pages/{id}/autosave', 'POST /api/admin/pages/{id}/merge/{revision}',
             'GET /sitemap.xml', 'GET /robots.txt',
            'POST /api/admin/pages/{id}/publish', 'POST /api/admin/pages/{id}/restore/{revision}',
            'GET /api/admin/seo/health', 'GET /api/admin/seo/{type}/{id}/schema', 'GET /api/admin/seo/{type}/{id}',
            'PUT /api/admin/seo/{type}/{id}', 'POST /api/admin/redirects', 'GET /api/admin/products/{id}/media',
            'POST /api/admin/products/{id}/media', 'PUT /api/admin/products/{id}/media/order',
            'DELETE /api/admin/products/{product}/media/{media}',
        ];
    }
}
