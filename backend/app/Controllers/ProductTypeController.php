<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Catalog\Application\DigitalDeliveryService;
use App\Modules\Catalog\Application\ProductTypeService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ProductTypeController extends Controller
{
    public function __construct(private readonly ProductTypeService $types, private readonly DigitalDeliveryService $downloads) {}

    public function bundleQuote(Request $request): Response
    {
        $currency = $request->query('currency', 'VND');
        if (!is_string($currency)) $currency = 'VND';
        try { return $this->success($this->types->quoteBundle(Arr::requiredParam($request, 'id'), $request->queryInt('qty', 1), $currency), 'Bundle quote'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function download(Request $request): Response
    {
        try {
            $range = $this->range($request->header('Range', ''));
            $start = $range === null ? null : $range[0];
            $end = $range === null ? null : $range[1];
            $delivery = $this->downloads->resolve(Arr::requiredParam($request, 'token'), $start, $end);
            $content = $delivery['content'] ?? null;
            if (!is_string($content)) throw new \RuntimeException('Digital asset is unavailable');
            $response = Response::raw($content, is_string($delivery['mime'] ?? null) ? $delivery['mime'] : 'application/octet-stream', $range === null ? 200 : 206)->header('Content-Disposition', 'attachment; filename="' . addcslashes(is_string($delivery['filename'] ?? null) ? $delivery['filename'] : 'download', '"\\') . '"')->header('Cache-Control', 'private, no-store')->header('Accept-Ranges', 'bytes')->header('X-Content-Type-Options', 'nosniff');
            if ($range !== null) $response->header('Content-Range', 'bytes ' . Arr::int($delivery, 'start') . '-' . Arr::int($delivery, 'end') . '/' . Arr::int($delivery, 'size'));
            return $response;
        }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 404, [], 'NOT_FOUND'); }
        catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'limit reached') ? 409 : 404;
            return $this->error($e->getMessage(), $status, [], $status === 409 ? 'CONFLICT' : 'NOT_FOUND');
        }
    }

    /** @return array{0: int, 1: int}|null */
    private function range(mixed $header): ?array
    {
        if (!is_string($header) || $header === '') return null;
        if (preg_match('/^bytes=(\d+)-(\d+)$/', trim($header), $m) !== 1) throw new \InvalidArgumentException('Invalid byte range');
        return [(int) $m[1], (int) $m[2]];
    }

    public function issueDownload(Request $request): Response
    {
        $data = $request->validate(['asset_id' => 'required|string', 'order_id' => 'required|string', 'download_limit' => 'nullable|integer|min:1', 'ttl_seconds' => 'nullable|integer|min:1']);
        try { return $this->created($this->downloads->issue(Arr::requiredStr($data, 'asset_id'), Arr::requiredStr($data, 'order_id'), is_int($data['download_limit'] ?? null) ? $data['download_limit'] : null, is_int($data['ttl_seconds'] ?? null) ? $data['ttl_seconds'] : 604800), 'Download issued'); }
        catch (\RuntimeException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;
            return $this->error($e->getMessage(), $status, [], $status === 404 ? 'NOT_FOUND' : 'INVALID_ARGUMENT');
        }
    }
}
