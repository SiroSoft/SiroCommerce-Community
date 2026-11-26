<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Platform\Application\AdminSubscriptionPlanService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Admin/owner/finance plan CRUD; routes are gated by cap:subscriptions. */
final class AdminSubscriptionPlanController extends Controller
{
    public function __construct(private readonly AdminSubscriptionPlanService $plans) {}

    public function index(Request $request): Response
    {
        try {
            return $this->success($this->plans->list($request->queryString('state', '')), 'Subscription plans');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'required|string',
            'amount_minor' => 'required|integer',
            'currency' => 'required|string',
            'interval' => 'required|string',
            'interval_count' => 'nullable|integer',
            'trial_period' => 'nullable|string',
            'trial_period_count' => 'nullable|integer',
            'state' => 'nullable|string',
            'provider' => 'nullable|string',
        ]);
        try {
            return $this->created($this->plans->create($data), 'Subscription plan created');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function update(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'name' => 'nullable|string',
            'amount_minor' => 'nullable|integer',
            'currency' => 'nullable|string',
            'interval' => 'nullable|string',
            'interval_count' => 'nullable|integer',
            'trial_period' => 'nullable|string',
            'trial_period_count' => 'nullable|integer',
            'state' => 'nullable|string',
            'provider' => 'nullable|string',
        ]);
        try {
            $updated = $this->plans->update(Arr::requiredParam($request, 'id'), $data);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $updated === null ? $this->error('Subscription plan not found', 404) : $this->success($updated, 'Subscription plan updated');
    }

    public function destroy(Request $request): Response
    {
        try {
            $deleted = $this->plans->delete(Arr::requiredParam($request, 'id'));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 409);
        }
        return $deleted ? $this->noContent() : $this->error('Subscription plan not found', 404);
    }
}
