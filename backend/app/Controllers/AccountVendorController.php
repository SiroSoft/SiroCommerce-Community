<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Vendor\Application\VendorPayoutService;
use App\Modules\Vendor\Domain\VendorPayoutNotFound;
use App\Repositories\VendorRepository;
use App\Resources\VendorLedgerResource;
use App\Resources\VendorPayoutResource;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Read-only vendor self-service for owners linked by vendors.owner_customer_id.
 * Vendors without an owner remain admin-only.
 */
final class AccountVendorController extends Controller
{
    public function __construct(private readonly VendorPayoutService $payouts, private readonly VendorRepository $vendors)
    {
    }

    public function ledger(Request $request): Response
    {
        $vendor = $this->ownerVendor($request);
        if ($vendor === null) return $this->error('Vendor not found', 404);
        try {
            $result = $this->payouts->ledger(Arr::requiredStr($vendor, 'id'), [
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
            'vendor_id' => Arr::requiredStr($vendor, 'id'),
            'entries' => array_map(static fn (array $entry): array => VendorLedgerResource::make($entry), $result['entries']),
            'totals' => $result['totals'],
        ], 'Vendor ledger');
    }

    public function payouts(Request $request): Response
    {
        $vendor = $this->ownerVendor($request);
        if ($vendor === null) return $this->error('Vendor not found', 404);
        try {
            $rows = $this->payouts->payouts(Arr::requiredStr($vendor, 'id'));
        } catch (VendorPayoutNotFound $e) {
            return $this->error($e->getMessage(), 404);
        }
        return $this->success(array_map(static fn (array $row): array => VendorPayoutResource::make($row), $rows), 'Vendor payouts');
    }

    /** @return array<string, mixed>|null */
    private function ownerVendor(Request $request): ?array
    {
        $user = $request->user();
        if (!is_array($user)) return null;
        $id = $user['id'] ?? null;
        if (!is_int($id) && !is_string($id)) return null;
        $ownerId = trim((string) $id);
        if ($ownerId === '') return null;
        return $this->vendors->findByOwnerCustomerId($ownerId);
    }
}
