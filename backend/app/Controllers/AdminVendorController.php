<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Vendor\Application\VendorService;
use App\Modules\Vendor\Domain\VendorConflict;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Marketplace lite vendor administration (admin/owner). Vendor records are
 * commerce-wide; product ownership is stored on products.vendor_id.
 */
final class AdminVendorController extends Controller
{
    public function __construct(private readonly VendorService $vendors)
    {
    }

    public function index(Request $request): Response
    {
        $page = max($request->queryInt('page', 1), 1);
        $perPage = min(max($request->queryInt('per_page', 20), 1), 100);
        $status = $request->queryString('status', '');
        $q = $request->queryString('q', '') ?: $request->queryString('search', '');
        try {
            $result = $this->vendors->list($status, $q, $page, $perPage);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['status' => [$e->getMessage()]]);
        }
        return $this->paginated($result['data'], $result['meta'], 'Vendors');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string',
            'slug' => 'nullable|string',
            'status' => 'nullable|string',
            'commission_bps' => 'nullable|integer',
            'contact_email' => 'nullable|string',
            'description' => 'nullable|string',
            'owner_customer_id' => 'nullable|string',
        ]);
        try {
            return $this->created($this->vendors->create($data), 'Vendor created');
        } catch (VendorConflict $e) {
            return $this->error($e->getMessage(), 422, ['slug' => [$e->getMessage()]]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string',
            'slug' => 'nullable|string',
            'status' => 'nullable|string',
            'commission_bps' => 'nullable|integer',
            'contact_email' => 'nullable|string',
            'description' => 'nullable|string',
            'owner_customer_id' => 'nullable|string',
        ]);
        $id = Arr::requiredParam($request, 'id');
        try {
            $updated = $this->vendors->update($id, $data);
        } catch (VendorConflict $e) {
            return $this->error($e->getMessage(), 422, ['slug' => [$e->getMessage()]]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        if ($updated === null) return $this->error('Vendor not found', 404);
        return $this->success($updated, 'Vendor updated');
    }

    public function destroy(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        try {
            $deleted = $this->vendors->delete($id);
        } catch (VendorConflict $e) {
            return $this->error($e->getMessage(), 409);
        }
        if (!$deleted) return $this->error('Vendor not found', 404);
        return $this->noContent();
    }
}
