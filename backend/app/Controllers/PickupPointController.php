<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Fulfillment\Application\PickupPointService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Click & Collect points: public active list + staff CRUD (spec CLICK_COLLECT_SPEC). */
final class PickupPointController extends Controller
{
    public function __construct(private readonly PickupPointService $points)
    {
    }

    public function index(Request $request): Response
    {
        unset($request);
        return $this->success($this->points->active(), 'Pickup points');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => 'required|string',
            'name' => 'required|string',
            'store_id' => 'nullable|string',
            'source_id' => 'nullable|string',
            'address_line' => 'nullable|string',
            'city' => 'nullable|string',
            'sla_hours' => 'nullable|integer',
        ]);
        $key = $this->key($request);
        if ($key === null) return $this->error('A valid Idempotency-Key is required', 422);
        try {
            return $this->created($this->points->create($data, $key), 'Pickup point created');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request): Response
    {
        $code = Arr::requiredParam($request, 'code');
        if (!is_string($code) || $code === '') return $this->error('Invalid point', 422);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string',
            'store_id' => 'nullable|string',
            'source_id' => 'nullable|string',
            'address_line' => 'nullable|string',
            'city' => 'nullable|string',
            'sla_hours' => 'nullable|integer',
            'state' => 'nullable|string',
        ]);
        if ($this->key($request) === null) return $this->error('A valid Idempotency-Key is required', 422);
        try {
            return $this->success($this->points->update($code, $data), 'Pickup point updated');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
    }

    private function key(Request $request): ?string
    {
        $key = $request->header('idempotency-key', '');
        return is_string($key) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) === 1 ? $key : null;
    }
}
