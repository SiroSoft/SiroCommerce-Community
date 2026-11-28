<?php

declare(strict_types=1);

namespace App\Extensions\Siro\ShopperAssistant;

use App\Support\CommerceScope;
use App\Support\CommerceScopeException;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

/**
 * Guest-capable, throttled storefront assistant endpoint. It attaches the
 * signed-in user when present, but the service only adds order facts after
 * ownership or a guest order token is verified.
 */
final class ShopperAssistantController extends Controller
{
    public function __construct(private readonly ShopperAssistantService $service = new ShopperAssistantService())
    {
    }

    public function ask(Request $request): Response
    {
        $data = $request->validate([
            'question' => 'required|string|min:3|max:1000',
            'product_id' => 'nullable|string|max:64',
            'product_slug' => 'nullable|string|max:160',
            'order_id' => 'nullable|string|max:64',
            'order_token' => 'nullable|string|max:256',
        ]);
        if (!isset($data['order_token']) || !is_string($data['order_token']) || $data['order_token'] === '') {
            $headerToken = $request->header('x-order-token', '');
            if (is_string($headerToken) && $headerToken !== '') {
                $data['order_token'] = $headerToken;
            }
        }
        try {
            $scope = CommerceScope::fromRequest($request);
            $user = $request->user();
            $result = $this->service->ask($scope, $data, is_array($user) ? $user : null);
        } catch (CommerceScopeException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            return $this->error($exception->getMessage(), 409);
        }

        return $this->success($result, 'Shopper assistant response');
    }
}
