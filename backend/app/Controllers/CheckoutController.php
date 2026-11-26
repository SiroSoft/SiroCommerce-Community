<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Checkout\Application\CheckoutService;
use App\Modules\Cart\Application\CartService;
use App\Support\Arr;
use App\Support\LocaleResolver;
use App\Support\PublicErrorMessage;
use App\Support\CommerceScope;
use Siro\Core\Controller;
use Siro\Core\Lang;
use Siro\Core\Request;
use Siro\Core\Response;

final class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $checkout, private readonly CartService $carts)
    {
    }

    public function place(Request $request): Response
    {
        Lang::setLocale(LocaleResolver::resolve($request));
        $key = $request->header('Idempotency-Key', '');
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $key) !== 1) {
            $message = Lang::get('commerce.checkout.idempotency_required');
            return $this->error($message, 422, ['Idempotency-Key' => [$message]]);
        }
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'cart_id' => 'required|string',
            'guest_email' => 'nullable|string',
            'coupon_code' => 'nullable|string',
            'gift_code' => 'nullable|string',
            'gift_codes' => 'nullable|array',
            'fulfillment_method' => 'nullable|string',
            'pickup_point_code' => 'nullable|string',
            'scope' => 'nullable|array',
            'payment_method' => 'nullable|string',
            'payment_provider' => 'nullable|string',
        ]);
        $cartId = Arr::requiredStr($data, 'cart_id');
        $cartToken = (string) $request->header('x-cart-token', '');
        if (!$this->carts->owns($cartId, $cartToken) && !$this->carts->ownsForCheckoutReplay($cartId, $cartToken)) return $this->error('Cart access is not authorized', 403);
        $coupon = Arr::nullableStr($data, 'coupon_code');
        $gift = Arr::nullableStr($data, 'gift_code');
        $gifts = $data['gift_codes'] ?? null;
        if (($gift !== null && $gift !== '') && is_array($gifts) && $gifts !== []) return $this->error('Use gift_code or gift_codes, not both', 422);
        $guestEmail = Arr::nullableStr($data, 'guest_email');
        if ($guestEmail !== null && trim($guestEmail) !== '' && (filter_var($guestEmail, FILTER_VALIDATE_EMAIL) === false || strlen($guestEmail) > 254)) {
            return $this->error('A valid guest email is required', 422, ['guest_email' => ['Enter a valid email address']]);
        }
        $requestedProvider = Arr::nullableStr($data, 'payment_provider', Arr::nullableStr($data, 'payment_method'));
        $placeUser = $request->user();
        $memberId = (is_array($placeUser) && isset($placeUser['id']) && is_scalar($placeUser['id']) && (string) $placeUser['id'] !== '') ? (string) $placeUser['id'] : '';
        try {
            $scope = CommerceScope::fromRequest($request);
            $order = $this->checkout->place(
                $cartId,
                $key,
                $guestEmail,
                $coupon !== null && $coupon !== '' ? $coupon : null,
                $scope->metadata(),
                $requestedProvider,
                $memberId !== '' ? $memberId : null,
                $gift !== null && $gift !== '' ? $gift : null,
                is_array($gifts) ? $gifts : null,
                Arr::nullableStr($data, 'fulfillment_method'),
                Arr::nullableStr($data, 'pickup_point_code')
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error(PublicErrorMessage::from($e->getMessage(), 'Checkout could not be completed'), 422);
        }
        return $this->created($order, Lang::get('commerce.checkout.placed'));
    }
}
