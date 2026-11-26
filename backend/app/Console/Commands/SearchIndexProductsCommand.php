<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Modules\Search\Application\CatalogIndexDocumentBuilder;
use App\Modules\Search\Application\CatalogIndexSchema;
use App\Modules\Search\Infrastructure\MeiliCatalogIndexClient;
use App\Support\Arr;
use Siro\Core\App;
use Siro\Core\Commands\CommandInterface;
use Siro\Core\Commands\CommandSupport;
use Siro\Core\Env;
use Siro\Core\HttpResponse;

/**
 * Indexes the storefront catalog into Meilisearch. Runs as a full upsert by
 * default; --fresh deletes and recreates the index before applying settings.
 * Domain writes are not hooked in real time: run this command on the daily
 * schedule documented in docs/engineering/SEARCH_MEILI.md.
 */
final class SearchIndexProductsCommand implements CommandInterface
{
    use CommandSupport;

    private const BATCH = 500;

    public string $signature = 'search:index-products';
    public string $description = 'Index catalog products into Meilisearch (siro_products)';

    public function __construct(private readonly string $basePath) {}

    /** @param array<int, string> $args */
    public function run(array $args): int
    {
        $fresh = in_array('--fresh', $args, true);
        $batchSize = $this->batchSize($args);
        try {
            $app = new App($this->basePath);
            $app->boot();
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()]);
        }

        $baseUrl = trim((string) Env::get('SEARCH_URL', ''));
        $apiKey = trim((string) Env::get('SEARCH_API_KEY', ''));
        $catalogProvider = strtolower(trim((string) Env::get('CATALOG_SEARCH_PROVIDER', 'native')));
        if ($catalogProvider !== 'meili' && !$fresh && !in_array('--force', $args, true)) {
            $summary = [
                'status' => 'SKIP',
                'reason' => 'CATALOG_SEARCH_PROVIDER is not meili; pass --force to index anyway',
                'index' => CatalogIndexSchema::INDEX,
            ];
            $this->write((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return 0;
        }
        if ($baseUrl === '' || $apiKey === '') {
            return $this->fail(['error' => 'SEARCH_URL and SEARCH_API_KEY must be configured']);
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            return $this->fail(['error' => 'SEARCH_URL must be an http(s) URL']);
        }

        $client = new MeiliCatalogIndexClient($baseUrl, $apiKey);
        $errors = [];
        $settingsApplied = false;
        if ($fresh) {
            try {
                $delete = $client->deleteIndex();
                if ($delete->status() !== 404) {
                    $this->assertOk($delete, 'delete index');
                    $task = $this->taskUid($delete);
                    if ($task !== null) $client->waitTask($task);
                }
                $create = $client->createIndex();
                // 409 means the index already exists (e.g. a retried release).
                if ($create->status() !== 409) $this->assertOk($create, 'create index');
                $task = $this->taskUid($create);
                if ($task !== null) $client->waitTask($task);
                $settings = $client->updateSettings(CatalogIndexSchema::settings());
                $this->assertOk($settings, 'apply settings');
                $task = $this->taskUid($settings);
                if ($task !== null) $client->waitTask($task);
                $settingsApplied = true;
            } catch (\Throwable $e) {
                return $this->fail(['fresh' => true, 'error' => $e->getMessage()], $errors);
            }
        }

        $builder = new CatalogIndexDocumentBuilder();
        $indexed = 0;
        $failed = 0;
        $batches = 0;
        $lastId = null;
        do {
            $query = Product::query()->orderBy('id');
            if ($lastId !== null) $query->where('id', '>', $lastId);
            $rows = $query->limit($batchSize)->get();
            if ($rows === []) break;
            $batches++;

            $products = [];
            $productIds = [];
            $categoryIds = [];
            foreach ($rows as $model) {
                $row = Arr::row($model);
                $id = Arr::str($row, 'id');
                if ($id === '') continue;
                $lastId = $id;
                $products[] = $row;
                $productIds[] = $id;
                $categoryId = Arr::str($row, 'category_id');
                if ($categoryId !== '') $categoryIds[] = $categoryId;
            }
            if ($products === []) break;

            [$variantsByProduct, $stockByProduct] = $this->variantData($productIds);
            $categorySlugs = $this->categorySlugs($categoryIds);
            $documents = [];
            foreach ($products as $product) {
                $id = Arr::str($product, 'id');
                $stock = $stockByProduct[$id] ?? 0;
                $inStock = Arr::int($product, 'allow_backorder') === 1 || $stock > 0;
                $documents[] = $builder->build(
                    $product,
                    $variantsByProduct[$id] ?? [],
                    $categorySlugs[Arr::str($product, 'category_id')] ?? '',
                    $inStock
                );
            }

            try {
                $response = $client->addDocuments($documents);
                $this->assertOk($response, 'index documents');
                $task = $this->taskUid($response);
                if ($task !== null) $client->waitTask($task);
                $indexed += count($documents);
            } catch (\Throwable $e) {
                $failed += count($documents);
                $errors[] = 'batch ' . $batches . ': ' . $e->getMessage();
            }
        } while (count($rows) === $batchSize);

        $summary = [
            'status' => $failed === 0 && $errors === [] ? 'PASS' : 'FAIL',
            'index' => CatalogIndexSchema::INDEX,
            'fresh' => $fresh,
            'settings_applied' => $settingsApplied,
            'batches' => $batches,
            'documents_indexed' => $indexed,
            'documents_failed' => $failed,
            'errors' => array_slice($errors, 0, 20),
        ];
        $this->write((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $summary['status'] === 'PASS' ? 0 : 1;
    }

    /**
     * @param array<int, string> $productIds
     * @return array{0: array<string, array<int, array<string, mixed>>>, 1: array<string, int>}
     */
    private function variantData(array $productIds): array
    {
        $variantsByProduct = [];
        $variantIds = [];
        foreach (ProductVariant::query()->whereIn('product_id', $productIds)->orderBy('id')->get() as $model) {
            $variant = Arr::row($model);
            $productId = Arr::str($variant, 'product_id');
            $variantId = Arr::str($variant, 'id');
            if ($productId === '') continue;
            $variantsByProduct[$productId][] = $variant;
            if ($variantId !== '') $variantIds[] = $variantId;
        }
        $stockByVariant = [];
        if ($variantIds !== []) {
            foreach (InventoryItem::query()->whereIn('variant_id', $variantIds)->get() as $model) {
                $item = Arr::row($model);
                $variantId = Arr::str($item, 'variant_id');
                if ($variantId === '') continue;
                $stockByVariant[$variantId] = ($stockByVariant[$variantId] ?? 0)
                    + max(0, Arr::int($item, 'on_hand') - Arr::int($item, 'reserved'));
            }
        }
        $stockByProduct = [];
        foreach ($variantsByProduct as $productId => $variants) {
            $stock = 0;
            foreach ($variants as $variant) $stock += $stockByVariant[Arr::str($variant, 'id')] ?? 0;
            $stockByProduct[$productId] = $stock;
        }
        return [$variantsByProduct, $stockByProduct];
    }

    /** @param array<int, string> $categoryIds
     * @return array<string, string> */
    private function categorySlugs(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_filter($categoryIds, static fn (string $id): bool => $id !== '')));
        $slugs = [];
        if ($ids === []) return $slugs;
        foreach (Category::query()->whereIn('id', $ids)->get() as $model) {
            $row = Arr::row($model);
            $id = Arr::str($row, 'id');
            if ($id !== '') $slugs[$id] = Arr::str($row, 'slug');
        }
        return $slugs;
    }

    private function assertOk(HttpResponse $response, string $action): void
    {
        if ($response->status() >= 200 && $response->status() < 300) return;
        $decoded = json_decode($response->body(), true);
        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : substr($response->body(), 0, 300);
        throw new \RuntimeException($action . ' failed (' . (string) $response->status() . '): ' . $message);
    }

    private function taskUid(HttpResponse $response): ?int
    {
        $decoded = json_decode($response->body(), true);
        if (!is_array($decoded)) return null;
        $taskUid = $decoded['taskUid'] ?? null;
        return is_int($taskUid) ? $taskUid : (is_numeric($taskUid) ? (int) $taskUid : null);
    }

    /** @param array<string, mixed> $extra
     * @param array<int, string> $errors */
    private function fail(array $extra, array $errors = []): int
    {
        $summary = ['status' => 'FAIL'] + $extra + ['errors' => $errors];
        $this->write((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return 1;
    }

    /** @param array<int, string> $args */
    private function batchSize(array $args): int
    {
        foreach ($args as $arg) {
            if (preg_match('/^--batch=(\d+)$/', $arg, $match) === 1) return min(1000, max(1, (int) $match[1]));
        }
        return self::BATCH;
    }
}
