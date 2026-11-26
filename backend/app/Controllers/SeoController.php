<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UrlRewrite;
use App\Modules\Seo\Application\SeoService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * SEO Engine endpoints (spec 05): metadata, redirects, sitemap, health.
 */
final class SeoController extends Controller
{
    public function __construct(private readonly SeoService $seo)
    {
    }

    public function sitemap(Request $request): Response
    {
        $base = (string) \Siro\Core\Env::get('APP_URL', 'http://localhost:8080');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->seo->sitemapUrls($base) as $u) {
            $loc = $u['loc'];
            $lastmod = $u['lastmod'];
            $xml .= '<url><loc>' . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . '</loc><lastmod>' . $lastmod . '</lastmod></url>' . "\n";
        }
        $xml .= '</urlset>';
        return Response::raw($xml, 'application/xml');
    }

    public function robots(Request $request): Response
    {
        $body = "User-agent: *\nAllow: /\nDisallow: /api/admin/\nDisallow: /checkout\nSitemap: " . rtrim((string) \Siro\Core\Env::get('APP_URL', 'http://localhost:8080'), '/') . "/sitemap.xml\n";
        return Response::raw($body, 'text/plain');
    }

    public function showMeta(Request $request): Response
    {
        $meta = $this->seo->meta(Arr::requiredParam($request, 'type'), Arr::requiredParam($request, 'id'));
        if ($meta === null) {
            return $this->error('SEO meta not found', 404);
        }
        return $this->success($meta, 'SEO meta');
    }

    public function saveMeta(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'canonical' => 'nullable|string',
            'robots' => 'nullable|string',
            'og_image' => 'nullable|string',
            'schema_json' => 'nullable|string',
        ]);
        try { $meta = $this->seo->saveMeta(Arr::requiredParam($request, 'type'), Arr::requiredParam($request, 'id'), $data); } catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        return $this->success($meta, 'SEO meta saved');
    }

    public function saveRedirect(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'from_path' => 'required|string',
            'to_path' => 'required|string',
            'code' => 'nullable|integer',
        ]);
        try { return $this->success($this->seo->saveRedirect($data), 'Redirect saved'); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    /**
     * Public redirect lookup used by the storefront edge middleware. It only
     * ever returns a same-origin absolute path, so a poisoned rewrite row can
     * never become an open redirect.
     */
    public function resolveRedirect(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 400);
        }
        $path = trim((string) $request->query('path', ''));
        if ($path === '' || strlen($path) > 2048 || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return $this->success(['target' => null], 'Redirect resolved');
        }
        $row = UrlRewrite::query()->where('request_path', $path)->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->first();
        if ($row === null) {
            $row = UrlRewrite::query()->where('request_path', $path)->where('store_id', 'default')->where('channel_id', 'web')->first();
        }
        if ($row === null) {
            return $this->success(['target' => null], 'Redirect resolved');
        }
        $rowData = Arr::row($row);
        $target = Arr::str($rowData, 'target_path');
        if (!$this->isInternalRedirectPath($target) || $target === $path) {
            return $this->success(['target' => null], 'Redirect resolved');
        }
        return $this->success([
            'target' => $target,
            'code' => in_array(Arr::int($rowData, 'redirect_type', 301), [301, 302], true) ? Arr::int($rowData, 'redirect_type', 301) : 301,
        ], 'Redirect resolved');
    }

    private function isInternalRedirectPath(string $target): bool
    {
        return $target !== ''
            && strlen($target) <= 2048
            && $target[0] === '/'
            && !str_starts_with($target, '//')
            && !str_contains($target, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $target) !== 1
            && preg_match('~^/[A-Za-z0-9/_\-?=&.%+#]*$~', $target) === 1;
    }

    public function health(Request $request): Response
    {
        return $this->success($this->seo->health(), 'SEO health');
    }
}
