<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Cart\Application\CartService;
use App\Support\Arr;
use App\Support\CommerceScope;
use App\Support\LocaleResolver;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;

final class CartController extends Controller
{
    public function __construct(private readonly CartService $service)
    {
    }

    public function store(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        /** @var array<string, mixed> $data */
        $data = $request->validate(['currency' => 'nullable|string|size:3']);
        $cart = $this->service->open(
            null,
            Arr::str($data, 'currency', 'VND')
        );
        return $this->created($cart, Lang::get('commerce.cart.priced'));
    }

    public function show(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $cartId = Arr::requiredParam($request, 'id');
        try {
            $cart = $this->service->show($cartId, (string) $request->header('x-cart-token', ''));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage() === 'Cart access is not authorized' ? 403 : 404);
        }
        return $this->success($cart, Lang::get('commerce.cart.priced'));
    }

    public function addLine(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $cartId = Arr::requiredParam($request, 'id');
        if (!$this->service->owns($cartId, (string) $request->header('x-cart-token', ''))) return $this->error('Cart access is not authorized', 403);
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'variant_id' => 'required|string',
            'qty' => 'required|integer',
            'slot_start' => 'nullable|string',
        ]);
        try {
            $line = $this->service->addLine(
                $cartId,
                Arr::requiredStr($data, 'variant_id'),
                Arr::requiredInt($data, 'qty'),
                Arr::nullableStr($data, 'slot_start')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $status = $msg === 'Cart access is not authorized' ? 403 : ($msg === 'Cart not available' || $msg === 'Variant not found' ? 404 : 422);
            return $this->error($msg, $status);
        }
        return $this->created($line, Lang::get('commerce.cart.priced'));
    }

    public function updateLine(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $cartId = Arr::requiredParam($request, 'id');
        $lineId = Arr::requiredParam($request, 'line');
        $data = $request->validate(['qty' => 'required|integer']);
        try {
            $cart = $this->service->setLineQuantity($cartId, (string) $request->header('x-cart-token', ''), $lineId, Arr::requiredInt($data, 'qty'));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage() === 'Cart access is not authorized' ? 403 : 404);
        }
        return $this->success($cart, Lang::get('commerce.cart.priced'));
    }

    public function removeLine(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $cartId = Arr::requiredParam($request, 'id');
        $lineId = Arr::requiredParam($request, 'line');
        try {
            $cart = $this->service->removeLine($cartId, (string) $request->header('x-cart-token', ''), $lineId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getMessage() === 'Cart access is not authorized' ? 403 : 404);
        }
        return $this->success($cart, Lang::get('commerce.cart.priced'));
    }

    public function quote(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $cartId = Arr::requiredParam($request, 'id');
        if (!$this->service->owns($cartId, (string) $request->header('x-cart-token', ''))) return $this->error('Cart access is not authorized', 403);
        $coupon = $request->queryString('coupon', '');
        $gift = $request->queryString('gift', '');
        // Repeated ?gift=A&gift=B (or comma-separated) stack a split tender.
        $giftRaw = $request->query('gift');
        $method = $request->queryString('fulfillment_method', '');
        $point = $request->queryString('pickup_point', '');
        $shippingMethod = $request->queryString('shipping_method', '');
        $scope = null;
        try {
            $scope = CommerceScope::fromRequest($request)->metadata();
        } catch (\Throwable) {
            $scope = null;
        }
        $destination = $this->destination($request);
        $quoteUser = $request->user();
        $memberId = (is_array($quoteUser) && isset($quoteUser['id']) && is_scalar($quoteUser['id']) && (string) $quoteUser['id'] !== '') ? (string) $quoteUser['id'] : '';
        try {
            $result = $this->service->quote(
                $cartId,
                $coupon !== '' ? $coupon : null,
                $destination,
                $memberId !== '' ? $memberId : null,
                $gift !== '' ? $gift : null,
                is_array($giftRaw) || (is_string($giftRaw) && str_contains($giftRaw, ',')) ? $giftRaw : null,
                $method !== '' ? $method : null,
                $point !== '' ? $point : null,
                $scope,
                $shippingMethod !== '' ? $shippingMethod : null
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
        return $this->success(
            [
                     'subtotal_minor' => $result['subtotal_minor'],
                     'discount_minor' => $result['discount_minor'],
                     'promotion_discount' => $result['promotion_discount'],
                     'promotion_rule' => $result['promotion_rule'] ?? null,
                     'gift_discount_minor' => $result['gift_discount_minor'],
                     'gift_code' => $result['gift_code'] ?? null,
                     'gift_tenders' => $result['gift_tenders'],
                     'member_deal_discount_minor' => $result['member_deal_discount_minor'],
                     'member_deal' => $result['member_deal'] ?? null,
                     'pickup' => $result['pickup'] ?? null,
                     'member_discount_minor' => $result['member_discount_minor'],
                     'member_tier' => $result['member_tier'] ?? null,
                     'shipping_minor' => $result['shipping_minor'],
                     'tax_minor' => $result['tax_minor'],
                     'total_minor' => $result['total_minor'],
                'lines' => $result['lines'],
            ],
            Lang::get('commerce.cart.priced')
        );
    }

    /** @return array<string, string>|null */
    private function destination(Request $request): ?array
    {
        $country = strtoupper(trim($request->queryString('destination_country', '')));
        if ($country === '') return null;
        $destination = ['country' => $country];
        foreach (['region' => 'destination_region', 'postal_code' => 'destination_postal_code'] as $field => $query) {
            $value = trim($request->queryString($query, ''));
            if ($value !== '') $destination[$field] = $value;
        }
        return $destination;
    }
}
