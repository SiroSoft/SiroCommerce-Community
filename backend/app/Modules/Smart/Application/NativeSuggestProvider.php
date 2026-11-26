<?php

declare(strict_types=1);

namespace App\Modules\Smart\Application;

use App\Modules\Catalog\Domain\CatalogSearchText;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Modules\Smart\Contracts\SuggestProvider;
use Siro\Core\Database;

/**
 * Native suggest: normalized prefix-first query ranking from the query log
 * plus tier-gated product cards. Fail-closed to empty on any error.
 */
final class NativeSuggestProvider implements SuggestProvider
{
    /** @return array{queries: list<array{query: string, submits: int, clicks: int}>, products: list<array<string, mixed>>} */
    public function suggest(string $query, int $limit, ?string $viewerTier): array
    {
        $normalized = SmartQuery::normalize($query) ?? '';
        if ($normalized === '') return ['queries' => [], 'products' => []];
        $limit = min(max($limit, 1), 8);
        try {
            return ['queries' => $this->queries($normalized, $limit), 'products' => $this->products($normalized, $viewerTier)];
        } catch (\Throwable) {
            return ['queries' => [], 'products' => []];
        }
    }

    /** @return list<array{query: string, submits: int, clicks: int}> */
    private function queries(string $normalized, int $limit): array
    {
        $pdo = Database::connection();
        $out = [];
        $seen = [];
        $rank = 'ORDER BY (clicks * 3 + submits) DESC LIMIT ' . $limit;
        $prefix = $pdo->prepare('SELECT query, submits, clicks FROM smart_search_terms WHERE query LIKE ? ' . $rank);
        $prefix->execute([$normalized . '%']);
        foreach ($prefix->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) continue;
            $text = (string) ($row['query'] ?? '');
            if ($text === '' || isset($seen[$text])) continue;
            $seen[$text] = true;
            $out[] = ['query' => $text, 'submits' => (int) ($row['submits'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0)];
        }
        if (count($out) < $limit) {
            $contains = $pdo->prepare('SELECT query, submits, clicks FROM smart_search_terms WHERE query LIKE ? ' . $rank);
            $contains->execute(['%' . $normalized . '%']);
            foreach ($contains->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row) || count($out) >= $limit) break;
                $text = (string) ($row['query'] ?? '');
                if ($text === '' || isset($seen[$text])) continue;
                $seen[$text] = true;
                $out[] = ['query' => $text, 'submits' => (int) ($row['submits'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0)];
            }
        }
        if (count($out) < $limit) $this->appendTypoQueries($normalized, $limit, $out, $seen);
        return $out;
    }

    /** @param list<array{query: string, submits: int, clicks: int}> $out
     *  @param array<string, bool> $seen */
    private function appendTypoQueries(string $normalized, int $limit, array &$out, array &$seen): void
    {
        try {
            $tokens = CatalogSearchText::tokens($normalized, true);
            if ($tokens === []) return;
            $full = CatalogSearchText::fold($normalized);
            $statement = Database::connection()->query('SELECT query, submits, clicks FROM smart_search_terms ORDER BY (clicks * 3 + submits) DESC, query ASC LIMIT 5000');
            if ($statement === false) return;
            $candidates = [];
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) continue;
                $text = is_string($row['query'] ?? null) ? $row['query'] : '';
                if ($text === '' || isset($seen[$text])) continue;
                $folded = CatalogSearchText::fold($text);
                if ($folded === '' || !CatalogSearchText::matchesTokens($folded, $tokens)) continue;
                $submits = is_numeric($row['submits'] ?? null) ? (int) $row['submits'] : 0;
                $clicks = is_numeric($row['clicks'] ?? null) ? (int) $row['clicks'] : 0;
                $candidates[] = ['query' => $text, 'submits' => $submits, 'clicks' => $clicks, 'score' => CatalogSearchText::nameScore($full, $tokens, $folded), 'rank' => $clicks * 3 + $submits];
            }
            usort($candidates, static function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
                if ($a['rank'] !== $b['rank']) return $b['rank'] <=> $a['rank'];
                return strcmp((string) $a['query'], (string) $b['query']);
            });
            foreach ($candidates as $candidate) {
                if (count($out) >= $limit) break;
                $text = (string) $candidate['query'];
                if (isset($seen[$text])) continue;
                $seen[$text] = true;
                $out[] = ['query' => $text, 'submits' => (int) $candidate['submits'], 'clicks' => (int) $candidate['clicks']];
            }
        } catch (\Throwable) {
        }
    }

    /** @return list<array<string, mixed>> */
    private function products(string $normalized, ?string $viewerTier): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id, name, slug, currency, min_tier FROM products WHERE status = 'active' AND archived_at IS NULL AND LOWER(name) LIKE ? ORDER BY name ASC LIMIT 8");
        $stmt->execute(['%' . mb_strtolower($normalized) . '%']);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || count($out) >= 4) break;
            $productId = (string) ($row['id'] ?? '');
            if ($productId === '') continue;
            if (!MemberPriceService::tierCanSee($viewerTier, MemberPriceService::normalizeTier((string) ($row['min_tier'] ?? '')))) continue;
            $card = $this->card($productId, $row);
            if ($card !== []) $out[] = $card;
        }
        return $out;
    }

    /** @param array<string, mixed> $product @return array<string, mixed> */
    private function card(string $productId, array $product): array
    {
        try {
            $pdo = Database::connection();
            $variant = $pdo->prepare('SELECT id, price_minor FROM product_variants WHERE product_id = ? ORDER BY price_minor ASC LIMIT 1');
            $variant->execute([$productId]);
            $variantRow = $variant->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($variantRow)) return [];
            $image = $pdo->prepare('SELECT url FROM product_images WHERE product_id = ? ORDER BY sort ASC LIMIT 1');
            $image->execute([$productId]);
            $img = $image->fetch(\PDO::FETCH_ASSOC);
            return [
                'product_id' => $productId,
                'variant_id' => (string) ($variantRow['id'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
                'slug' => (string) ($product['slug'] ?? ''),
                'price_minor' => (int) ($variantRow['price_minor'] ?? 0),
                'currency' => (string) ($product['currency'] ?? 'VND'),
                'image' => is_array($img) ? (string) ($img['url'] ?? '') : '',
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
