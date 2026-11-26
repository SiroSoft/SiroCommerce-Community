<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Dev\Application\DevService;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Developer superpowers (spec 09). Admin-only: trace, doctor, recovery.
 * Merchant UI stays business-safe; technical detail lives here.
 */
final class DevController extends Controller
{
    public function __construct(
        private readonly DevService $runtime,
    ) {
    }

    public function trace(Request $request): Response
    {
        try {
            $report = $this->runtime->trace(Arr::requiredParam($request, 'id'));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 404);
        }
        return $this->success($report, 'Flight record');
    }

    public function doctor(Request $request): Response
    {
        $scope = $request->queryString('scope', 'all');
        $allowed = ['all', 'inventory', 'payment', 'seo'];
        if (!in_array($scope, $allowed, true) && !str_starts_with($scope, 'order:')) {
            return $this->error('Unknown scope', 422, ['scope' => ['Use all, inventory, payment, seo, order:{id}']]);
        }
        return $this->success($this->runtime->diagnose($scope), 'Diagnosis');
    }

    public function recover(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'action' => 'required|string',
            'order_id' => 'nullable|string',
            'dry_run' => 'nullable|integer',
        ]);
        $user = $request->user();
        /** @var array<string, mixed>|null $user */
        $actor = is_array($user) ? Arr::str($user, 'email', 'admin') : 'admin';
        $dryRun = Arr::int($data, 'dry_run', 1) === 1;
        $action = Arr::requiredStr($data, 'action');
        if ($action === 'release-expired') {
            return $this->success($this->runtime->releaseExpired($dryRun, $actor), 'Recovery result');
        }
        if ($action === 'rescue-order') {
            $orderId = Arr::nullableStr($data, 'order_id');
            if ($orderId === null || $orderId === '') {
                return $this->error('order_id required', 422, ['order_id' => ['order_id required']]);
            }
            try {
                $report = $this->runtime->rescue($orderId, $actor, $dryRun);
            } catch (\RuntimeException $e) {
                return $this->error($e->getMessage(), 404);
            }
            return $this->success($report, 'Recovery result');
        }
        return $this->error('Unknown action', 422, ['action' => ['Use release-expired, rescue-order']]);
    }
}
