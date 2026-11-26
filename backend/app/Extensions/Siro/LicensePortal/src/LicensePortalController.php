<?php

declare(strict_types=1);

namespace App\Extensions\Siro\LicensePortal;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class LicensePortalController extends Controller
{
    public function __construct(private readonly LicensePortalService $service = new LicensePortalService())
    {
    }

    public function assign(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'customer_id' => 'nullable|string|max:64',
            'order_id' => 'nullable|string|max:64',
            'order_line_id' => 'nullable|string|max:26',
            'sku' => 'nullable|string|max:64',
            'source_sku' => 'nullable|string|max:64',
            'license_reference' => 'nullable|string|max:255',
            'email' => 'nullable|string|max:254',
            'send_email' => 'nullable',
            'token_ttl_hours' => 'nullable|integer|min:1|max:8760',
        ]);
        $data['license'] = $request->input('license');
        try {
            $scope = CommerceScope::fromRequest($request);
            $key = $request->header('idempotency-key', '');
            $result = $this->service->assign($scope, $data, is_string($key) && $key !== '' ? $key : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        $idempotent = $result['replayed'];

        return $this->success($result, $idempotent ? 'License delivery already assigned' : 'License delivery assigned', $idempotent ? 200 : 201);
    }

    public function index(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $rows = $this->service->list(
                $scope,
                $request->queryString('state', 'all'),
                $request->queryString('customer_id', ''),
                $request->queryString('order_id', ''),
            );
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        return $this->success($rows, 'License deliveries');
    }

    public function revoke(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = $request->param('id', '');
            $row = $this->service->revoke($scope, is_string($id) ? $id : '');
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }
        if ($row === null) {
            return $this->error('License delivery not found', 404);
        }

        return $this->success($row, 'License delivery revoked');
    }

    public function rotate(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $id = $request->param('id', '');
            $result = $this->service->rotate($scope, is_string($id) ? $id : '', $request->input('token_ttl_hours'));
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }

        return $this->success($result, 'License download link rotated');
    }

    public function download(Request $request): Response
    {
        $token = $request->param('token', '');
        $result = $this->service->download(is_string($token) ? $token : '');
        if ($result['reason'] === 'ok' && $result['document'] !== null) {
            $filename = $result['filename'] ?? 'license.json';

            return Response::raw($result['document'], 'application/json; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->header('Cache-Control', 'no-store')
                ->header('X-Content-Type-Options', 'nosniff');
        }
        if ($result['reason'] === 'not_found') {
            return $this->error('License link not found', 404);
        }
        if ($result['reason'] === 'unavailable') {
            return $this->error('License document is not available', 409);
        }

        return $this->error('License link is no longer available', 410);
    }

    public function overview(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
            $rows = $this->service->list($scope);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        $pending = 0;
        $issued = 0;
        $revoked = 0;
        $downloaded = 0;
        $active = 0;
        foreach ($rows as $row) {
            $state = $row['state'] ?? null;
            if ($state === 'pending') {
                $pending++;
            } elseif ($state === 'issued') {
                $issued++;
            } elseif ($state === 'revoked') {
                $revoked++;
            }
            if (($row['token_used_at'] ?? null) !== null) {
                $downloaded++;
            }
            if (($row['has_active_token'] ?? false) === true) {
                $active++;
            }
        }
        $columns = [
            ['key' => 'customer_id', 'label' => 'Customer'],
            ['key' => 'order_id', 'label' => 'Order'],
            ['key' => 'sku', 'label' => 'SKU'],
            ['key' => 'license_id', 'label' => 'License'],
            ['key' => 'state', 'label' => 'State'],
            ['key' => 'token_expires_at', 'label' => 'Link expires'],
            ['key' => 'created_at', 'label' => 'Created'],
        ];
        $tableRows = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $tableRow = [];
            foreach ($columns as $column) {
                $tableRow[$column['key']] = $this->stringValue($row[$column['key']] ?? null);
            }
            $tableRows[] = $tableRow;
        }

        return $this->success([
            'stats' => [
                ['label' => 'Deliveries', 'value' => (string) count($rows)],
                ['label' => 'Pending', 'value' => (string) $pending],
                ['label' => 'Issued', 'value' => (string) $issued],
                ['label' => 'Revoked', 'value' => (string) $revoked],
                ['label' => 'Downloaded', 'value' => (string) $downloaded],
                ['label' => 'Active links', 'value' => (string) $active],
            ],
            'table' => [
                'columns' => $columns,
                'rows' => $tableRows,
            ],
        ], 'License portal overview');
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
