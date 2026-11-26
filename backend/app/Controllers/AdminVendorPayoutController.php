<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Vendor\Application\PayoutCsvExportService;
use App\Modules\Vendor\Application\VendorPayoutReconciliationService;
use App\Modules\Vendor\Application\VendorPayoutService;
use App\Modules\Vendor\Domain\VendorPayoutConflict;
use App\Modules\Vendor\Domain\VendorPayoutNotFound;
use App\Resources\VendorLedgerResource;
use App\Resources\VendorPayoutResource;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Admin/owner vendor commission ledger and manual payout batches. Snapshots
 * live on order_lines; this surface never mutates order totals or payments.
 */
final class AdminVendorPayoutController extends Controller
{
    public function __construct(
        private readonly VendorPayoutService $payouts,
        private readonly PayoutCsvExportService $payoutExport,
    ) {
    }

    public function ledger(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        try {
            $result = $this->payouts->ledger($id, [
                'state' => $request->queryString('state', ''),
                'from' => $request->queryString('from', ''),
                'to' => $request->queryString('to', ''),
            ]);
        } catch (VendorPayoutNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success([
            'entries' => array_map(static fn (array $entry): array => VendorLedgerResource::make($entry), $result['entries']),
            'totals' => $result['totals'],
        ], 'Vendor ledger');
    }

    public function payouts(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        try {
            $rows = $this->payouts->payouts($id);
        } catch (VendorPayoutNotFound $e) {
            return $this->error($e->getMessage(), 404);
        }
        return $this->success(array_map(static fn (array $row): array => VendorPayoutResource::make($row), $rows), 'Vendor payouts');
    }

    public function createPayout(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'period_start' => 'required|string',
            'period_end' => 'required|string',
        ]);
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            return $this->error('A valid Idempotency-Key is required', 422, ['idempotency_key' => ['A valid Idempotency-Key is required']]);
        }
        try {
            $payout = $this->payouts->createPayout(Arr::requiredParam($request, 'id'), Arr::requiredStr($data, 'period_start'), Arr::requiredStr($data, 'period_end'), $key);
        } catch (VendorPayoutNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (VendorPayoutConflict $e) {
            return $this->error($e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->created(VendorPayoutResource::make($payout), 'Vendor payout created');
    }

    /** GET /api/admin/vendor-payouts/{id}/export.csv — UTF-8 BOM bank-bulk CSV. */
    public function exportCsv(Request $request): Response
    {
        try {
            $result = $this->payoutExport->export(Arr::requiredParam($request, 'id'));
        } catch (VendorPayoutNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 500);
        }
        return Response::raw($result['csv'], 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="vendor-payout-' . date('Ymd-His') . '.csv"')
            ->header('X-Export-Rows', (string) $result['rows']);
    }

    public function reconcile(Request $request): Response
    {
        $data = $request->inputAll();
        $csv = $data['csv'] ?? null;
        if (!is_string($csv) || trim($csv) === '') return $this->error('csv is required', 422);
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            return $this->error('A valid Idempotency-Key is required', 422);
        }
        try {
            $result = (new VendorPayoutReconciliationService($this->payouts))->reconcile($csv, filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success($result, $result['ok'] ? 'Payouts reconciled' : 'Payouts reconciled with errors');
    }

    public function markPaid(Request $request): Response
    {
        try {
            $payout = $this->payouts->markPaid(Arr::requiredParam($request, 'id'));
        } catch (VendorPayoutNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (VendorPayoutConflict $e) {
            return $this->error($e->getMessage(), 409);
        }
        return $this->success(VendorPayoutResource::make($payout), 'Vendor payout marked paid');
    }

    public function cancel(Request $request): Response
    {
        try {
            $payout = $this->payouts->cancel(Arr::requiredParam($request, 'id'));
        } catch (VendorPayoutNotFound $e) {
            return $this->error($e->getMessage(), 404);
        } catch (VendorPayoutConflict $e) {
            return $this->error($e->getMessage(), 409);
        }
        return $this->success(VendorPayoutResource::make($payout), 'Vendor payout cancelled');
    }
}
