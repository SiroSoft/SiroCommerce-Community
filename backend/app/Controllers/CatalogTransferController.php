<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\Catalog\Application\CatalogTransferService;
use App\Modules\Catalog\Application\ProductCsvExportService;
use App\Modules\Catalog\Application\ProductCsvImportService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
final class CatalogTransferController extends Controller
{
    public function __construct(
        private readonly CatalogTransferService $transfer,
        private readonly ProductCsvExportService $productExport,
        private readonly ProductCsvImportService $productImport,
    ) {}
    public function import(Request $request): Response
    {
        $data = $request->validate(['format' => 'required|string', 'source' => 'required|string', 'validate_only' => 'nullable|boolean']);
        try { $scope = CommerceScope::fromRequest($request); return $this->success($this->transfer->import(Arr::requiredStr($data, 'format'), Arr::requiredStr($data, 'source'), (bool) ($data['validate_only'] ?? false), $scope), 'Catalog import completed'); }
        catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    public function export(Request $request): Response
    {
        try {
            $format = strtolower($request->queryString('format', 'jsonl'));
            $scope = CommerceScope::fromRequest($request);
            return $this->success(['format' => $format, 'schema_version' => CatalogTransferService::SCHEMA_VERSION, 'content' => $this->transfer->export($format, $scope)], 'Catalog export generated');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
    }

    /** GET /api/admin/products/export.csv — UTF-8 BOM CSV, capped at 10k rows. */
    public function exportProducts(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $limit = $request->queryInt('limit', ProductCsvExportService::MAX_ROWS);
            $result = $this->productExport->export($scope, $limit);
            $response = Response::raw($result['csv'], 'text/csv; charset=utf-8');
            return $response
                ->header('Content-Disposition', 'attachment; filename="products-' . date('Ymd-His') . '.csv"')
                ->header('X-Export-Rows', (string) $result['rows'])
                ->header('X-Export-Total', (string) $result['total'])
                ->header('X-Export-Truncated', $result['truncated'] ? '1' : '0');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 500); }
    }

    /** POST /api/admin/products/import — multipart file or JSON {csv}, optional ?dry_run=1. */
    public function importProducts(Request $request): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            return $this->error('A valid Idempotency-Key is required', 422, ['Idempotency-Key' => ['Required header: 1-128 characters [A-Za-z0-9._:-]']]);
        }
        try {
            $scope = CommerceScope::fromRequest($request);
            $csv = '';
            $file = $request->file('file');
            if ($file !== null) {
                if (!$file->isValid()) return $this->error('Uploaded CSV file is invalid', 422);
                if ($file->getSize() > ProductCsvImportService::MAX_BYTES) return $this->error('CSV exceeds the 2MB limit', 422);
                $contents = file_get_contents($file->getPathname());
                if ($contents === false) return $this->error('Unable to read uploaded CSV', 422);
                $csv = $contents;
            } else {
                $data = $request->validate(['csv' => 'nullable|string']);
                if (!array_key_exists('csv', $data) || !is_string($data['csv'])) {
                    return $this->error('Provide a CSV file upload or a csv string', 422);
                }
                $csv = $data['csv'];
            }
            $dryRunRaw = $request->queryString('dry_run', '');
            if ($dryRunRaw === '') {
                $body = $request->body();
                $dryRunRaw = is_scalar($body['dry_run'] ?? null) ? (string) $body['dry_run'] : '0';
            }
            $dryRun = in_array(strtolower($dryRunRaw), ['1', 'true', 'yes', 'on'], true);
            $user = $request->user();
            $actor = is_array($user) && is_string($user['email'] ?? null) ? $user['email'] : 'admin';
            $result = $this->productImport->import($csv, $dryRun, $scope, $actor, $key);
            return $this->success($result, $dryRun ? 'Product CSV dry run completed' : 'Product CSV import completed');
        } catch (CommerceScopeException $e) { return $this->error($e->getMessage(), 400); }
        catch (\InvalidArgumentException $e) { return $this->error($e->getMessage(), 422); }
        catch (\RuntimeException $e) { return $this->error($e->getMessage(), 500); }
    }
}
