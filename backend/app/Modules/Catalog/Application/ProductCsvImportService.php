<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Inventory\Application\InventoryAdminService;
use App\Modules\Promotion\Application\MemberPriceService;
use App\Repositories\ProductRepository;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\Ids;
use Siro\Core\Database;
use Siro\Core\Str;

/**
 * Merchant product CSV import.
 *
 * Rows are validated individually and upserted by SKU. A bad row is reported
 * as {row, error} without aborting the rest of the file. Re-running an
 * unchanged file is safe: rows that compare equal are counted as skipped and
 * no product, variant, or inventory mutation is issued.
 */
final class ProductCsvImportService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS = 500;

    public const COLUMNS = [
        'sku', 'name', 'slug', 'status', 'price_minor', 'compare_price_minor', 'stock',
        'description', 'category', 'min_tier', 'allow_backorder', 'is_preorder',
    ];

    private const STATUSES = ['draft', 'active', 'archived', 'scheduled', 'published', 'out_of_stock', 'backorder'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly InventoryAdminService $inventory,
    ) {}

    /**
     * @return array{created: int, updated: int, skipped: int, errors: array<int, array{row: int, error: string}>, dry_run: bool, rows_seen: int}
     */
    public function import(string $csv, bool $dryRun, CommerceScope $scope, string $actor, string $idempotencyKey): array
    {
        if (strlen($csv) > self::MAX_BYTES) throw new \InvalidArgumentException('CSV exceeds the 2MB limit');
        if ($csv === '') throw new \InvalidArgumentException('CSV content is required');
        $rows = $this->parse($csv);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $seenSkus = [];
        foreach ($rows as $line => $row) {
            $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
            if ($sku !== '' && isset($seenSkus[$sku])) {
                $errors[] = ['row' => $line, 'error' => 'sku is duplicated in this import'];
                continue;
            }
            if ($sku !== '') $seenSkus[$sku] = true;
            try {
                $outcome = $this->importRow($row, $line, $dryRun, $scope, $actor, $idempotencyKey);
                if ($outcome === 'created') $created++;
                elseif ($outcome === 'updated') $updated++;
                else $skipped++;
            } catch (\InvalidArgumentException $e) {
                $errors[] = ['row' => $line, 'error' => $e->getMessage()];
            } catch (\Throwable $e) {
                $errors[] = ['row' => $line, 'error' => 'Row could not be imported: ' . $e->getMessage()];
            }
        }
        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'dry_run' => $dryRun,
            'rows_seen' => count($rows),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('Unable to open CSV source');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '');
        if (!is_array($header)) throw new \InvalidArgumentException('CSV header is required');
        $columns = [];
        foreach ($header as $value) {
            $column = strtolower(trim((string) $value));
            if ($column === '') continue;
            if (!in_array($column, self::COLUMNS, true)) throw new \InvalidArgumentException('Unsupported CSV column: ' . $column);
            if (in_array($column, $columns, true)) throw new \InvalidArgumentException('Duplicate CSV column: ' . $column);
            $columns[] = $column;
        }
        if (!in_array('sku', $columns, true)) throw new \InvalidArgumentException('CSV header must include sku');
        $rows = [];
        $line = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;
            if ($line > self::MAX_ROWS + 1) throw new \InvalidArgumentException('CSV exceeds the 500 row limit');
            if ($values === [null]) continue;
            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = is_scalar($values[$index] ?? null) ? (string) $values[$index] : '';
            }
            $hasValue = false;
            foreach ($row as $value) if (trim($value) !== '') { $hasValue = true; break; }
            if ($hasValue) $rows[$line] = $row;
        }
        fclose($stream);
        return $rows;
    }

    /**
     * @param array<string, string> $row
     */
    private function importRow(array $row, int $line, bool $dryRun, CommerceScope $scope, string $actor, string $idempotencyKey): string
    {
        $sku = strtoupper(trim($row['sku'] ?? ''));
        if ($sku === '') throw new \InvalidArgumentException('sku is required');
        if (strlen($sku) > 64) throw new \InvalidArgumentException('sku must be 64 characters or fewer');

        $name = trim($row['name'] ?? '');
        $slug = trim($row['slug'] ?? '');
        $status = strtolower(trim($row['status'] ?? ''));
        $description = trim($row['description'] ?? '');
        $categorySlug = trim($row['category'] ?? '');
        $minTier = strtolower(trim($row['min_tier'] ?? ''));
        $priceRaw = trim($row['price_minor'] ?? '');
        $compareRaw = trim($row['compare_price_minor'] ?? '');
        $stockRaw = trim($row['stock'] ?? '');
        $allowBackorderRaw = trim($row['allow_backorder'] ?? '');
        $isPreorderRaw = trim($row['is_preorder'] ?? '');

        if ($name === '') $nameRequired = true; else $nameRequired = false;
        if ($status !== '' && !in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('status must be one of ' . implode(', ', self::STATUSES));
        if (mb_strlen($description) > 4000) throw new \InvalidArgumentException('description must be 4000 characters or fewer');
        $price = $this->integerField($priceRaw, 'price_minor');
        $compare = $this->integerField($compareRaw, 'compare_price_minor');
        $stock = $this->integerField($stockRaw, 'stock');
        $allowBackorder = $this->boolField($allowBackorderRaw, 'allow_backorder');
        $isPreorder = $this->boolField($isPreorderRaw, 'is_preorder');
        if ($minTier !== '' && MemberPriceService::normalizeTier($minTier) === null) throw new \InvalidArgumentException('min_tier must be bronze|silver|gold|platinum');
        if ($slug !== '') {
            $slug = Str::slug($slug);
            if ($slug === '') throw new \InvalidArgumentException('slug is invalid');
        }
        $categoryId = '';
        if ($categorySlug !== '') {
            $categoryId = (string) ($this->products->categoryIdBySlug($categorySlug) ?? '');
            if ($categoryId === '') throw new \InvalidArgumentException('category not found: ' . $categorySlug);
        }

        $variant = $this->products->findVariantBySku($sku);
        if ($variant === null) {
            if ($nameRequired) throw new \InvalidArgumentException('name is required for a new sku');
            $productId = Ids::new();
            $slug = $slug !== '' ? $slug : Str::slug($name);
            if ($slug === '') throw new \InvalidArgumentException('slug could not be generated from name');
            $slug = $this->uniqueSlug($slug, $scope);
            $effectivePrice = $price ?? 0;
            if ($compare !== null && $compare < $effectivePrice) throw new \InvalidArgumentException('compare_price_minor must be greater than or equal to price_minor');
            if ($dryRun) return 'created';
            $this->atomic(function () use ($productId, $name, $slug, $status, $description, $minTier, $allowBackorder, $isPreorder, $categoryId, $scope, $sku, $effectivePrice, $compare, $stock, $actor, $idempotencyKey, $line): void {
                $this->products->store([
                    'id' => $productId,
                    'name' => $name,
                    'slug' => $slug,
                    'status' => $status !== '' ? $status : 'draft',
                    'type' => 'simple',
                    'description' => $description,
                    'min_tier' => $minTier !== '' ? $minTier : null,
                    'allow_backorder' => $allowBackorder ?? 0,
                    'is_preorder' => $isPreorder ?? 0,
                ] + ($categoryId !== '' ? ['category_id' => $categoryId] : []) + $scope->metadata());
                $createdVariant = $this->products->storeVariant([
                    'id' => Ids::new(),
                    'product_id' => $productId,
                    'sku' => $sku,
                    'price_minor' => $effectivePrice,
                    'compare_price_minor' => $compare,
                    'currency' => $scope->currency,
                ]);
                $variantId = Arr::str($createdVariant, 'id');
                if ($variantId !== '' && $stock !== null) {
                    $this->inventory->setOnHand($variantId, $stock, $actor, 'default', $idempotencyKey . ':stock:' . $sku . ':' . $line);
                }
            });
            return 'created';
        }

        $productId = Arr::str($variant, 'product_id');
        $product = $productId === '' ? null : $this->products->findInScope($productId, $scope);
        if ($product === null) throw new \InvalidArgumentException('sku belongs to another commerce scope');
        $productChanges = [];
        if ($name !== '' && $name !== Arr::str($product, 'name')) $productChanges['name'] = $name;
        if ($slug !== '' && $slug !== Arr::str($product, 'slug')) {
            if ($this->products->slugTakenInScope($slug, $scope, $productId)) throw new \InvalidArgumentException('slug already exists: ' . $slug);
            $productChanges['slug'] = $slug;
        }
        if ($status !== '' && $status !== Arr::str($product, 'status')) $productChanges['status'] = $status;
        if ($description !== '' && $description !== Arr::str($product, 'description')) $productChanges['description'] = $description;
        if ($categoryId !== '' && $categoryId !== Arr::str($product, 'category_id')) $productChanges['category_id'] = $categoryId;
        if ($minTier !== '' && $minTier !== Arr::str($product, 'min_tier')) $productChanges['min_tier'] = $minTier;
        if ($allowBackorder !== null && $allowBackorder !== Arr::int($product, 'allow_backorder')) $productChanges['allow_backorder'] = $allowBackorder;
        if ($isPreorder !== null && $isPreorder !== Arr::int($product, 'is_preorder')) $productChanges['is_preorder'] = $isPreorder;

        $existingPrice = Arr::int($variant, 'price_minor');
        $compareValue = $variant['compare_price_minor'] ?? null;
        $existingCompare = is_numeric($compareValue) ? (int) $compareValue : null;
        $effectivePrice = $price ?? $existingPrice;
        $effectiveCompare = $compare !== null ? $compare : $existingCompare;
        if ($effectiveCompare !== null && $effectiveCompare < $effectivePrice) throw new \InvalidArgumentException('compare_price_minor must be greater than or equal to price_minor');
        $variantChanges = [];
        if ($price !== null && $price !== $existingPrice) $variantChanges['price_minor'] = $price;
        if ($compare !== null && $compare !== $existingCompare) $variantChanges['compare_price_minor'] = $compare;
        $variantId = Arr::str($variant, 'id');
        $currentOnHand = $stock === null ? null : $this->products->variantOnHand($variantId);
        $stockChanged = $stock !== null && $stock !== $currentOnHand;

        if ($productChanges === [] && $variantChanges === [] && !$stockChanged) return 'skipped';
        if ($dryRun) return 'updated';
        $this->atomic(function () use ($productId, $productChanges, $variantId, $variantChanges, $stockChanged, $stock, $actor, $idempotencyKey, $sku, $line): void {
            if ($productChanges !== []) $this->products->updateProduct($productId, $productChanges);
            if ($variantChanges !== []) $this->products->updateVariant($variantId, $variantChanges);
            if ($stockChanged && $variantId !== '') {
                $this->inventory->setOnHand($variantId, $stock, $actor, 'default', $idempotencyKey . ':stock:' . $sku . ':' . $line);
            }
        });
        return 'updated';
    }

    /** Keep a single row's product/variant/inventory mutations atomic. */
    private function atomic(callable $callback): void
    {
        Database::transaction($callback);
    }

    private function integerField(string $raw, string $field): ?int
    {
        if ($raw === '') return null;
        if (!preg_match('/^\d+$/', $raw)) throw new \InvalidArgumentException($field . ' must be a non-negative integer');
        return (int) $raw;
    }

    private function boolField(string $raw, string $field): ?int
    {
        if ($raw === '') return null;
        $normalized = strtolower($raw);
        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) return 1;
        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) return 0;
        throw new \InvalidArgumentException($field . ' must be a boolean (1/0)');
    }

    private function uniqueSlug(string $slug, CommerceScope $scope): string
    {
        $candidate = $slug;
        $suffix = 1;
        while ($this->products->slugTakenInScope($candidate, $scope)) {
            $suffix++;
            $candidate = $slug . '-' . $suffix;
            if ($suffix > 1000) throw new \InvalidArgumentException('Unable to generate a unique slug');
        }
        return $candidate;
    }
}
