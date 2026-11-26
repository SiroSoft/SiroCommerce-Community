<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Database;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Public no-model smart reads (spec SMART_SKILLS_SPEC): precomputed tables
 * only, strict limits, privacy-safe aggregates. Cold start returns [].
 */
final class SmartDiscoveryController extends Controller
{
    public function suggest(Request $request): Response
    {
        $query = trim($request->queryString('q', ''));
        if ($query === '' || mb_strlen($query) > 64) return $this->withProducts([], []);
        try {
            $result = (new \App\Modules\Smart\Application\NativeSuggestProvider())->suggest($query, 8, $this->viewerTier($request));
            return $this->withProducts($result['queries'], $result['products']);
        } catch (\Throwable) {
            return $this->withProducts([], []);
        }
    }

    /** @param list<array<string, mixed>> $queries @param list<array<string, mixed>> $products */
    private function withProducts(array $queries, array $products): Response
    {
        return Response::json(['success' => true, 'message' => 'Suggestions', 'data' => $queries, 'products' => $products]);
    }

    public function affinities(Request $request): Response
    {
        $variantId = Arr::requiredParam($request, 'id');
        if (!is_string($variantId) || $variantId === '' || strlen($variantId) > 64) return $this->error('Invalid product', 422);
        $tier = $this->viewerTier($request);
        try {
            // Gated base product: fail closed with empty (same as cold start).
            if (!$this->variantVisible($variantId, $tier)) return $this->success([], 'Frequently bought together');
            $stmt = Database::connection()->prepare('SELECT product_a, product_b, orders, score FROM smart_affinities WHERE product_a = ? OR product_b = ? ORDER BY score DESC LIMIT 4');
            $stmt->execute([$variantId, $variantId]);
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) continue;
                $other = (string) ($row['product_a'] ?? '') === $variantId ? (string) ($row['product_b'] ?? '') : (string) ($row['product_a'] ?? '');
                if ($other === '' || !$this->variantVisible($other, $tier)) continue;
                $out[] = ['variant_id' => $other, 'orders' => (int) ($row['orders'] ?? 0), 'score' => (int) ($row['score'] ?? 0)] + $this->variantCard($other);
            }
            return $this->success($out, 'Frequently bought together');
        } catch (\Throwable) {
            return $this->success([], 'Frequently bought together');
        }
    }

    public function reviewSummary(Request $request): Response
    {
        $productId = Arr::requiredParam($request, 'id');
        if (!is_string($productId) || $productId === '' || strlen($productId) > 64) return $this->error('Invalid product', 422);
        if (!$this->productVisible($productId, $this->viewerTier($request))) return $this->success(null, 'No summary yet');
        try {
            $stmt = Database::connection()->prepare('SELECT rating_avg, rating_count, pos_count, neg_count, aspects_json, excerpts_json, computed_at FROM smart_review_rollups WHERE product_id = ? LIMIT 1');
            $stmt->execute([$productId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) return $this->success(null, 'No summary yet');
            return $this->success([
                'product_id' => $productId,
                'rating_avg' => (float) ($row['rating_avg'] ?? 0),
                'rating_count' => (int) ($row['rating_count'] ?? 0),
                'pos_count' => (int) ($row['pos_count'] ?? 0),
                'neg_count' => (int) ($row['neg_count'] ?? 0),
                'aspects' => json_decode((string) ($row['aspects_json'] ?? '[]'), true) ?? [],
                'excerpts' => json_decode((string) ($row['excerpts_json'] ?? '{}'), true) ?? [],
                'computed_at' => $row['computed_at'] ?? null,
            ], 'Review summary');
        } catch (\Throwable) {
            return $this->success(null, 'No summary yet');
        }
    }

    private function viewerTier(Request $request): ?string
    {
        $user = $request->user();
        $memberId = (is_array($user) && isset($user['id']) && is_scalar($user['id']) && (string) $user['id'] !== '') ? (string) $user['id'] : '';
        if ($memberId === '') return null;
        try {
            return (new \App\Modules\Promotion\Application\MemberPriceService())->tierFor($memberId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function variantVisible(string $variantId, ?string $tier): bool
    {
        try {
            $variant = \App\Models\ProductVariant::query()->where('id', $variantId)->first();
            if ($variant === null) return false;
            return $this->productVisible(Arr::str(Arr::row($variant), 'product_id'), $tier);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function productVisible(string $productId, ?string $tier): bool
    {
        if ($productId === '') return false;
        try {
            $product = \App\Models\Product::query()->where('id', $productId)->first();
            // Orphan aggregates (rollup without a product row) keep legacy
            // behavior; only an existing gated product filters.
            if ($product === null) return true;
            $minTier = \App\Modules\Promotion\Application\MemberPriceService::normalizeTier(Arr::str(Arr::row($product), 'min_tier'));
            return \App\Modules\Promotion\Application\MemberPriceService::tierCanSee($tier, $minTier);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function variantCard(string $variantId): array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT v.price_minor, v.product_id, p.name, p.slug, p.currency FROM product_variants v LEFT JOIN products p ON p.id = v.product_id WHERE v.id = ? LIMIT 1');
            $stmt->execute([$variantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row)) return [];
            $image = Database::connection()->prepare('SELECT url, alt FROM product_images WHERE product_id = ? ORDER BY sort ASC LIMIT 1');
            $image->execute([(string) ($row['product_id'] ?? '')]);
            $img = $image->fetch(\PDO::FETCH_ASSOC);
            return [
                'product_id' => (string) ($row['product_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'price_minor' => (int) ($row['price_minor'] ?? 0),
                'currency' => (string) ($row['currency'] ?? 'VND'),
                'image' => is_array($img) ? (string) ($img['url'] ?? '') : '',
                'image_alt' => is_array($img) ? (string) ($img['alt'] ?? '') : '',
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
