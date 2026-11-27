<?php

declare(strict_types=1);

namespace App\Extensions\Siro\AbandonedCart;

use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/** Thin HTTP layer: validate -> service. Served under /api/ext/siro/abandoned-cart/. */
final class AbandonedCartController extends Controller
{
    public function __construct(private readonly AbandonedCartService $service = new AbandonedCartService())
    {
    }

    public function overview(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->overview($scope), 'Abandoned cart overview');
    }

    public function snapshots(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->snapshotsTable($scope), 'Abandoned carts');
    }

    public function deliveries(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        }
        return $this->success($this->service->deliveriesTable($scope), 'Reminder deliveries');
    }

    public function createCoupon(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => 'nullable|string|max:64',
            'cart_id' => 'nullable|string|max:64',
            'rules' => 'nullable|string',
        ]);
        $rules = Arr::str($data, 'rules', '');
        $input = [
            'code' => Arr::str($data, 'code'),
            'cart_id' => Arr::str($data, 'cart_id'),
            'rules' => $rules !== '' ? $rules : null,
        ];
        try {
            $coupon = $this->service->createCoupon($input);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->error('Coupon could not be created: ' . $exception->getMessage(), 422);
        }
        try {
            \Siro\Core\Audit::log('extension.abandoned-cart.coupon_created', ['code' => Arr::str($coupon, 'code'), 'cart_id' => Arr::str($input, 'cart_id')]);
        } catch (\Throwable) {
            // Audit is best effort; the coupon is already created.
        }
        return $this->created($coupon, 'Coupon created');
    }
}
