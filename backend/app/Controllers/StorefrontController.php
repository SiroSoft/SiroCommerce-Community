<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use App\Support\Arr;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class StorefrontController extends Controller
{
    public function context(Request $request): Response
    {
        try {
            $scope = CommerceScope::fromRequest($request);
        } catch (CommerceScopeException $e) {
            return $this->error($e->getMessage(), 400, [], 'SCOPE_ERROR');
        }

        $user = $request->user();
        $customer = is_array($user)
            ? [
                'id' => (string) ($user['id'] ?? ''),
                'name' => Arr::str($user, 'name'),
                'email' => Arr::str($user, 'email'),
            ]
            : ['id' => 'guest', 'name' => '', 'email' => ''];

        return $this->success([
            'organization' => ['id' => $scope->storeId, 'name' => $scope->storeId, 'role' => 'buyer'],
            'customer' => $customer,
            'store' => [
                'id' => $scope->storeId,
                'name' => $scope->storeId,
                'currency' => $scope->currency,
                'locale' => $scope->locale,
            ],
            'pricing' => [],
            'credit' => ['available' => false, 'reason' => 'Provider credit terms are unavailable in the storefront.'],
            'payout' => ['available' => false, 'reason' => 'Vendor payout details are unavailable in the storefront.'],
            'payment' => ['available' => false, 'reason' => 'Payment provider capabilities are reported by checkout.'],
        ], 'Storefront context');
    }
}
