<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\UrlRewrite;
use App\Support\Arr;
use Siro\Core\Middleware\MiddlewareInterface;
use Siro\Core\Request;
use Siro\Core\Response;

final class UrlRewriteMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $path = '/' . ltrim($request->path(), '/');
        // Only handle GET for rewrites
        if ($request->method() === 'GET' && $path !== '/') {
            $storeId = trim((string) $request->header('X-Store-Id', 'default')) ?: 'default';
            $channelId = trim((string) $request->header('X-Channel-Id', 'web')) ?: 'web';
            try {
                $row = UrlRewrite::query()->where('request_path', $path)->where('store_id', $storeId)->where('channel_id', $channelId)->first();
                if ($row === null) {
                    // try default scope
                    $row = UrlRewrite::query()->where('request_path', $path)->where('store_id', 'default')->where('channel_id', 'web')->first();
                }
                if ($row !== null) {
                    $target = Arr::str(Arr::row($row), 'target_path');
                    $type = Arr::int(Arr::row($row), 'redirect_type', 301);
                    if ($target !== '' && $target !== $path) {
                        return new Response('', $type, ['Location' => $target]);
                    }
                }
            } catch (\Throwable $e) {}
        }
        return $next($request);
    }
}
