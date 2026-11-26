<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Promotion\Application\MemberDealService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Scheduled + lifecycle member deals (spec MEMBER_DEALS_SPEC). Staff only. */
final class MemberDealController extends Controller
{
    public function __construct(private readonly MemberDealService $deals)
    {
    }

    public function index(Request $request): Response
    {
        unset($request);
        return $this->success($this->deals->list(), 'Member deals');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string',
            'kind' => 'nullable|string',
            'min_tier' => 'nullable|string',
            'percent_off' => 'required|integer',
            'starts_at' => 'nullable|string',
            'ends_at' => 'nullable|string',
        ]);
        $key = $this->key($request);
        if ($key === null) return $this->error('A valid Idempotency-Key is required', 422);
        try {
            return $this->created($this->deals->create($data, $key), 'Member deal created');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request): Response
    {
        $id = Arr::requiredParam($request, 'id');
        if (!is_string($id) || $id === '') return $this->error('Invalid deal', 422);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string',
            'min_tier' => 'nullable|string',
            'percent_off' => 'nullable|integer',
            'starts_at' => 'nullable|string',
            'ends_at' => 'nullable|string',
            'state' => 'nullable|string',
        ]);
        if ($this->key($request) === null) return $this->error('A valid Idempotency-Key is required', 422);
        try {
            return $this->success($this->deals->update($id, $data), 'Member deal updated');
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
