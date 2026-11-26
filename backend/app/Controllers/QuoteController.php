<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Checkout\Application\QuoteService;
use App\Modules\Cart\Application\CartService;
use App\Support\Arr;
use App\Support\CommerceScope;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes, private readonly CartService $carts) {}

    public function create(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate(['cart_id' => 'required|string', 'coupon' => 'nullable|string', 'context' => 'nullable|array']);
        $cartId = Arr::requiredStr($data, 'cart_id');
        if (!$this->carts->owns($cartId, (string) $request->header('x-cart-token', ''))) return $this->error('Cart access is not authorized', 403);
        try {
            $scope = CommerceScope::fromRequest($request);
            $context = is_array($data['context'] ?? null) ? $data['context'] : [];
            $context['scope'] = $scope->metadata();
            return $this->success($this->quotes->quote($cartId, Arr::nullableStr($data, 'coupon'), $context), 'Quote created');
        }
        catch (\InvalidArgumentException|\RuntimeException $e) { return $this->error($e->getMessage(), 422); }
    }
}
