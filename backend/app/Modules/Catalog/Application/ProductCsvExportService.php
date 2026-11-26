<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Repositories\ProductRepository;
use App\Support\CommerceScope;

/**
 * Merchant product CSV export.
 *
 * The export is generated from the native catalog read model and capped at
 * 10,000 rows so a single admin request can never stream an unbounded table.
 * CSV is UTF-8 with a BOM for Excel (Vietnamese locale) compatibility.
 */
final class ProductCsvExportService
{
    public const MAX_ROWS = 10000;

    public const COLUMNS = [
        'sku', 'name', 'slug', 'status', 'price_minor', 'compare_price_minor', 'stock',
        'description', 'category', 'min_tier', 'allow_backorder', 'is_preorder',
    ];

    public function __construct(private readonly ProductRepository $products) {}

    /**
     * @return array{csv: string, rows: int, total: int, truncated: bool}
     */
    public function export(CommerceScope $scope, int $limit = self::MAX_ROWS): array
    {
        $limit = max(1, min($limit, self::MAX_ROWS));
        $result = $this->products->exportAdminCsvRows($scope, $limit);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) throw new \RuntimeException('Unable to create product export');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::COLUMNS, ',', '"', '', "\r\n");
        foreach ($result['rows'] as $row) {
            $values = [];
            foreach (self::COLUMNS as $column) {
                $value = $row[$column] ?? '';
                $values[] = is_scalar($value) ? $value : '';
            }
            fputcsv($stream, $values, ',', '"', '', "\r\n");
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return [
            'csv' => $csv === false ? "\xEF\xBB\xBF" : $csv,
            'rows' => count($result['rows']),
            'total' => $result['total'],
            'truncated' => $result['truncated'],
        ];
    }
}
