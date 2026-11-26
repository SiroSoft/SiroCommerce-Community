<?php
declare(strict_types=1);
namespace App\Modules\Cart\Application;

use App\Modules\Cart\Contracts\CartCommandPort;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Checkout\Contracts\PromotionPort;
use App\Modules\Promotion\Application\PromotionRuleService;
use App\Modules\Shipping\Application\ShippingFeeAdjusterRegistry;
use App\Support\Arr;
use App\Support\Ids;
use App\Modules\Shared\Domain\Money;
use Siro\Core\Event;

final class CartService
{
    public function __construct(private readonly CartCommandPort $carts, private readonly PricingEngine $pricing, private readonly PromotionPort $promotions, private readonly ?PromotionRuleService $promotionRules = null) {}
    /** @return array<string, mixed> */
    public function open(?string $customerId = null, string $currency = 'VND'): array
    {
        $token = bin2hex(random_bytes(32));
        $result = $this->carts->create(['id' => Ids::new(), 'customer_id' => $customerId, 'access_token_hash' => hash('sha256', $token), 'currency' => strtoupper($currency), 'state' => 'open', 'expires_at' => date('Y-m-d H:i:s', time() + 2592000)]);
        unset($result['access_token_hash']);
        $result['access_token'] = $token;
        return $result;
    }

    /** @return array<string, mixed> */
    public function show(string $cartId, string $token): array
    {
        if (!$this->owns($cartId, $token)) throw new \RuntimeException('Cart access is not authorized');
        $cart = $this->carts->find($cartId);
        if ($cart === null) throw new \RuntimeException('Cart not available');
        unset($cart['access_token_hash']);
        $lines = $this->carts->lines($cartId);
        $images = $this->carts->primaryImages(array_values(array_filter(array_map(static fn (array $line): string => Arr::str($line, 'product_id'), $lines))));
        $subtotal = 0;
        foreach ($lines as $line) $subtotal += Arr::int($line, 'unit_price_minor') * Arr::requiredInt($line, 'qty');
        $cart['lines'] = array_values(array_map(static function (array $line) use ($images): array {
            $out = [
                'id' => Arr::str($line, 'id'),
                'variant_id' => Arr::str($line, 'variant_id'),
                'product_id' => Arr::str($line, 'product_id', Arr::str($line, 'variant_id')),
                'slug' => Arr::str($line, 'slug'),
                'sku' => Arr::str($line, 'sku'),
                'name' => Arr::str($line, 'name'),
                'image' => $images[Arr::str($line, 'product_id', Arr::str($line, 'variant_id'))] ?? '',
                'qty' => Arr::requiredInt($line, 'qty'),
                'unit_price' => Arr::requiredInt($line, 'unit_price_minor'),
                'line_total' => Arr::requiredInt($line, 'unit_price_minor') * Arr::requiredInt($line, 'qty'),
            ];
            // Surface booking/flash context so the cart page can show which slot
            // was booked and which lines carry a flash deal price.
            foreach (['slot_start', 'booking_slot_id', 'flash_sale_id'] as $extra) {
                $value = Arr::nullableStr($line, $extra);
                if ($value !== null && $value !== '') $out[$extra] = $value;
            }
            return $out;
        }, $lines));
        $cart['subtotal'] = $subtotal;
        $cart['state'] = Arr::str($cart, 'state');
        return $cart;
    }

    public function setLineQuantity(string $cartId, string $token, string $lineId, int $quantity): array
    {
        if (!$this->owns($cartId, $token)) throw new \RuntimeException('Cart access is not authorized');
        if ($quantity < 1 || $quantity > 99) throw new \InvalidArgumentException('Quantity must be between 1 and 99');
        // P0: re-validate booking capacity on qty increase (prevents add-1-then-update-to-99 bypass)
        $target = null;
        foreach ($this->carts->lines($cartId) as $l) {
            if (Arr::str($l, 'id') === $lineId) { $target = $l; break; }
        }
        if ($target !== null) {
            $variantId = Arr::str($target, 'variant_id');
            $slotStart = Arr::nullableStr($target, 'slot_start');
            if ($slotStart !== null && $slotStart !== '') {
                $this->assertBookingCapacity($variantId, $slotStart, $quantity, 0, Arr::str($target, 'id'));
            }
        }
        $this->carts->setLineQuantity($cartId, $lineId, $quantity);
        return $this->show($cartId, $token);
    }

    /**
     * Shared booking capacity check.
     * Counts durable reservations + ALL open cart lines holding the same slot
     * (not just the current cart), minus $excludeQty already held by the line
     * being updated. Throws when capacity would be exceeded.
     */
    private function assertBookingCapacity(string $variantId, string $slotStart, int $wantedQty, int $excludeQty = 0, ?string $excludeLineId = null): void
    {
        if (strtotime($slotStart) === false) throw new \InvalidArgumentException('Invalid booking slot_start');
        $norm = date('Y-m-d H:i:s', strtotime($slotStart));
        $slotQuery = \App\Models\BookingSlot::query()->where('variant_id', $variantId)->where('starts_at', $norm);
        if (method_exists($slotQuery, 'lockForUpdate')) $slotQuery = $slotQuery->lockForUpdate();
        $slot = $slotQuery->first();
        if ($slot === null) throw new \RuntimeException('Booking slot not available');
        $capacity = Arr::int(Arr::row($slot), 'capacity');
        $reserved = 0;
        $resQuery = \App\Models\BookingReservation::query()->where('variant_id', $variantId)->where('slot_start', $norm)->whereIn('state', ['reserved','committed']);
        if (method_exists($resQuery, 'lockForUpdate')) $resQuery = $resQuery->lockForUpdate();
        foreach ($resQuery->get() as $r) $reserved += Arr::int(Arr::row($r), 'qty');
        // Count every cart line across all carts holding this slot. Converted carts
        // are rare and only make the check stricter, i.e. fail-closed.
        $heldInCarts = 0;
        try {
            foreach (\App\Models\CartLine::query()->where('variant_id', $variantId)->where('slot_start', $norm)->get() as $cl) {
                $row = Arr::row($cl);
                if ($excludeLineId !== null && Arr::str($row, 'id') === $excludeLineId) continue;
                $heldInCarts += Arr::int($row, 'qty');
            }
        } catch (\Throwable $e) {
            // If cart_lines table shape differs, fall back to reservations-only check.
        }
        // add: wanted=delta, held includes existing lines → held+wanted = total after merge.
        // update: wanted=new qty, held excludes this line → held+wanted = total after update.
        if ($reserved + $heldInCarts + $wantedQty > $capacity) {
            throw new \RuntimeException('Booking slot is fully booked');
        }
    }

    private function isBookingProduct(string $variantId): bool
    {
        try {
            $variantRow = \App\Models\ProductVariant::query()->where('id', $variantId)->first();
            if ($variantRow === null) return false;
            $prodId = Arr::str(Arr::row($variantRow), 'product_id');
            $product = \App\Models\Product::query()->where('id', $prodId)->first();
            return $product !== null && Arr::str(Arr::row($product), 'type') === 'booking';
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function removeLine(string $cartId, string $token, string $lineId): array
    {
        if (!$this->owns($cartId, $token)) throw new \RuntimeException('Cart access is not authorized');
        $this->carts->removeLine($cartId, $lineId);
        return $this->show($cartId, $token);
    }

    public function owns(string $cartId, string $token): bool
    {
        return $this->carts->accessTokenIsValid($cartId, $token);
    }

    public function ownsForCheckoutReplay(string $cartId, string $token): bool
    {
        if (!method_exists($this->carts, 'accessTokenIsValidForReplay')) return false;
        return $this->carts->accessTokenIsValidForReplay($cartId, $token);
    }
    /** @return array<string, mixed> */
    public function addLine(string $cartId, string $variantId, int $qty, ?string $slotStart = null): array
    {
        if ($qty <= 0) throw new \InvalidArgumentException('qty must be > 0');
        if ($qty > 99) throw new \InvalidArgumentException('Quantity must be between 1 and 99');
        $cart = $this->carts->find($cartId);
        if ($cart === null || Arr::str($cart, 'state') !== 'open') throw new \RuntimeException('Cart not available');
        $resolved = $this->carts->resolveVariant($variantId) ?? $variantId;
        $variantPrice = $this->carts->price($resolved);
        if ($variantPrice === null) throw new \RuntimeException('Variant not found');
        $price = $variantPrice;
        $bookingSlotId = null;
        // P0: booking products REQUIRE slot_start — no silent fallback to simple line.
        if ($this->isBookingProduct($resolved)) {
            if ($slotStart === null || trim($slotStart) === '') throw new \InvalidArgumentException('Booking slot_start is required');
            if (strtotime($slotStart) === false) throw new \InvalidArgumentException('Invalid booking slot_start');
            $norm = date('Y-m-d H:i:s', strtotime($slotStart));
            $slotQuery = \App\Models\BookingSlot::query()->where('variant_id', $resolved)->where('starts_at', $norm);
            if (method_exists($slotQuery, 'lockForUpdate')) $slotQuery = $slotQuery->lockForUpdate();
            $slot = $slotQuery->first();
            if ($slot === null) throw new \RuntimeException('Booking slot not available');
            $slotRow = Arr::row($slot);
            $bookingSlotId = Arr::str($slotRow, 'id');
            $slotPrice = Arr::int($slotRow, 'price_minor', -1);
            if ($slotPrice >= 0) $price = $slotPrice;
            // Capacity check counts reservations + ALL carts holding the slot.
            $this->assertBookingCapacity($resolved, $slotStart, $qty);
            $slotStart = $norm;
        } elseif ($slotStart !== null && trim($slotStart) !== '') {
            if (strtotime($slotStart) === false) throw new \InvalidArgumentException('Invalid booking slot_start');
            $slotStart = date('Y-m-d H:i:s', strtotime($slotStart));
        } else {
            $slotStart = null;
        }
        // Flash-sale override (soft cap check; hard enforcement happens at place with lock).
        $flashSaleId = null;
        if ($bookingSlotId === null) {
            try {
                $deals = (new \App\Modules\Promotion\Application\FlashSaleService())->activeFor([$resolved]);
                $deal = $deals[$resolved] ?? null;
                if ($deal !== null) {
                    if (($deal['remaining'] ?? null) !== null && (int) $deal['remaining'] <= 0) throw new \RuntimeException('Flash sale is sold out');
                    $price = (int) $deal['sale_price_minor'];
                    $flashSaleId = (string) $deal['sale_id'];
                }
            } catch (\RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
            }
        }
        $extra = [];
        if ($slotStart !== null && $slotStart !== '') $extra['slot_start'] = $slotStart;
        if ($bookingSlotId !== null) $extra['booking_slot_id'] = $bookingSlotId;
        if ($flashSaleId !== null) $extra['flash_sale_id'] = $flashSaleId;
        return $this->carts->addLine($cartId, $resolved, $qty, $price, $extra);
    }
    /**
     * @param array<string, mixed>|null $destination
     * @param mixed $giftCodes string|string[]|null
     * @param array<mixed>|null $scope
     * @return array{subtotal_minor: int, discount_minor: int, promotion_discount: int, promotion_rule: array<string, mixed>|null, gift_discount_minor: int, gift_code: string|null, gift_tenders: array<int, array{code: string, covered_minor: int}>, member_discount_minor: int, member_tier: string|null, member_deal_discount_minor: int, member_deal: array{id: string, name: string, percent_off: int}|null, shipping_minor: int, tax_minor: int, total_minor: int, pickup: array<string, mixed>|null, lines: array<int, array<string, mixed>>}
     */
    public function quote(string $cartId, ?string $coupon = null, ?array $destination = null, ?string $memberCustomerId = null, ?string $giftCode = null, mixed $giftCodes = null, ?string $fulfillmentMethod = null, ?string $pickupPoint = null, ?array $scope = null, ?string $shippingMethod = null): array
    {
        $cart = $this->carts->find($cartId); $lines = $this->carts->lines($cartId);
        if ($cart === null || Arr::str($cart, 'state') !== 'open' || $lines === []) throw new \RuntimeException('Cart is empty or expired');
        $pricingLines = [];
        foreach ($lines as $line) {
            $pricingLine = ['variant_id' => Arr::requiredStr($line, 'variant_id'), 'sku' => Arr::str($line, 'sku'), 'name' => Arr::str($line, 'name'), 'qty' => Arr::requiredInt($line, 'qty'), 'unit_price_minor' => Arr::requiredInt($line, 'unit_price_minor')];
            foreach (['product_id', 'category_id'] as $scopeKey) {
                $scopeValue = Arr::str($line, $scopeKey);
                if ($scopeValue !== '') $pricingLine[$scopeKey] = $scopeValue;
            }
            $pricingLines[] = $pricingLine;
        }
        $cartCurrency = Arr::str($cart, 'currency', 'VND');
        $pricingContext = ['scope' => ['currency' => $cartCurrency]];
        if (is_array($destination) && $destination !== []) $pricingContext['destination'] = $destination;
        $snapshot = $this->pricing->quote(Ids::new(), $cartCurrency, $pricingLines, $pricingContext);
        // Native member pricing first; coupons evaluate on the member-reduced base.
        $memberDiscount = 0;
        $memberTier = null;
        $memberId = ($memberCustomerId !== null && $memberCustomerId !== '') ? $memberCustomerId : Arr::str($cart, 'customer_id');
        try {
            $member = (new \App\Modules\Promotion\Application\MemberPriceService())->discountFor($memberId, $pricingLines, $cartCurrency);
            $memberDiscount = min(Arr::int($member, 'discount_minor'), $snapshot->subtotal->amountMinor);
            $memberTier = Arr::nullableStr($member, 'tier');
            if ($memberDiscount <= 0) $memberTier = null;
        } catch (\Throwable $e) {
            $memberDiscount = 0;
            $memberTier = null;
        }
        // Tier visibility recheck (carts are anonymous; membership is known
        // here): gated lines fail the quote even if added before the gate.
        $viewerTier = (new \App\Modules\Promotion\Application\MemberPriceService())->tierFor($memberId);
        $variantIds = [];
        foreach ($pricingLines as $pricingLine) $variantIds[] = Arr::str($pricingLine, 'variant_id');
        foreach (\App\Modules\Promotion\Application\MemberPriceService::productTiers($variantIds) as $vid => $minTier) {
            if (!\App\Modules\Promotion\Application\MemberPriceService::tierCanSee($viewerTier, $minTier)) {
                throw new \RuntimeException('Product requires ' . ($minTier ?? 'member') . ' membership');
            }
        }
        // Member deals (tier windows, birthdays) apply on the member-reduced
        // base, before coupons — best single deal wins, no stacking.
        $dealDiscount = 0;
        $dealRow = null;
        try {
            $deal = (new \App\Modules\Promotion\Application\MemberDealService())->discountFor($memberId, max(0, $snapshot->subtotal->amountMinor - $memberDiscount));
            $dealDiscount = min(Arr::int($deal, 'discount_minor'), max(0, $snapshot->subtotal->amountMinor - $memberDiscount));
            $dealRow = $deal['deal'];
            if ($dealDiscount <= 0) $dealRow = null;
        } catch (\Throwable $e) {
            $dealDiscount = 0;
            $dealRow = null;
        }
        $couponBase = max(0, $snapshot->subtotal->amountMinor - $memberDiscount - $dealDiscount);
        $discount = 0;
        if ($coupon !== null && $coupon !== '') { $preview = $this->promotions->preview($coupon, $couponBase); if (isset($preview['error'])) { $error = $preview['error']; throw new \RuntimeException(is_scalar($error) ? (string) $error : 'Promotion preview failed'); } $discount = Arr::int($preview, 'discount_minor'); if ($discount < 0 || $discount > $couponBase) throw new \RuntimeException('Promotion returned an invalid discount'); }
        // Price rules run after coupons/member pricing and before gift tender.
        $promotionBase = max(0, $couponBase - $discount);
        $promotion = $this->promotionRules()->bestFor($pricingLines, $promotionBase);
        $promotionDiscount = $promotion !== null ? (int) $promotion['discount'] : 0;
        $promotionRule = $promotion !== null ? [
            'id' => Arr::str($promotion['rule'], 'id'),
            'name' => Arr::str($promotion['rule'], 'name'),
            'kind' => Arr::str($promotion['rule'], 'kind'),
            'discount' => $promotionDiscount,
        ] : null;
        // Gift/credit tender last: balance preview only (no reservation on quote).
        // Split tender: codes apply in order, each on the shrinking remainder.
        $giftDiscount = 0;
        $giftTenders = [];
        $giftOwner = ($memberCustomerId !== null && $memberCustomerId !== '') ? $memberCustomerId : Arr::str($cart, 'customer_id');
        $codes = \App\Modules\Promotion\Application\GiftCreditService::normalizeCodes($giftCode, $giftCodes);
        if ($codes !== []) {
            $remaining = max(0, $promotionBase - $promotionDiscount);
            foreach ($codes as $code) {
                $gift = (new \App\Modules\Promotion\Application\GiftCreditService())->balance($code, $giftOwner !== '' ? $giftOwner : null);
                if ($gift === null) throw new \RuntimeException('Gift code is unknown, bound, or inactive');
                if (strtoupper((string) ($gift['currency'] ?? '')) !== strtoupper($cartCurrency)) throw new \RuntimeException('Gift currency mismatch');
                $covered = min((int) ($gift['balance_minor'] ?? 0), $remaining);
                if ($covered <= 0) throw new \RuntimeException('Gift balance is empty');
                $giftDiscount += $covered;
                $remaining -= $covered;
                $giftTenders[] = ['code' => $code, 'covered_minor' => $covered];
            }
        }
        // Click & Collect: pickup zeroes shipping, but only when every line
        // is fully available at the point's source (fail-closed shortfalls).
        $pickup = null;
        $shippingMinor = $snapshot->shipping->amountMinor;
        $fulfillment = $fulfillmentMethod !== null ? strtolower(trim($fulfillmentMethod)) : '';
        if ($fulfillment !== '' && !in_array($fulfillment, ['pickup', 'cod', 'standard', 'express'], true)) {
            throw new \InvalidArgumentException('Fulfillment method is invalid');
        }
        if ($fulfillment === 'pickup') {
            if ($pickupPoint === null || trim($pickupPoint) === '') throw new \InvalidArgumentException('Pickup point is required for pickup');
            $points = new \App\Modules\Fulfillment\Application\PickupPointService();
            $point = $points->activePoint($pickupPoint);
            if ($point === null) throw new \RuntimeException('Pickup point is unknown or disabled');
            $shortfalls = $this->pickupShortfalls($pricingLines, Arr::str($point, 'source_id', 'default'));
            if ($shortfalls !== []) throw new \RuntimeException('Not available at ' . Arr::str($point, 'code') . ': ' . implode(', ', $shortfalls));
            $shippingMinor = 0;
            $pickup = ['code' => Arr::str($point, 'code'), 'name' => Arr::str($point, 'name'), 'address_line' => Arr::nullableStr($point, 'address_line'), 'city' => Arr::nullableStr($point, 'city'), 'sla_hours' => Arr::int($point, 'sla_hours', 24), 'ready_by' => $points->readyBy($point)];
        } elseif ($pickupPoint !== null && trim($pickupPoint) !== '') {
            throw new \InvalidArgumentException('Pickup point requires pickup fulfillment');
        }
        if ($pickup === null) {
            $method = ShippingFeeAdjusterRegistry::normalizeMethod($shippingMethod)
                ?? ShippingFeeAdjusterRegistry::normalizeMethod($fulfillment)
                ?? 'standard';
            $adjustment = ShippingFeeAdjusterRegistry::fee($this->shippingScope($scope, $cart, $cartCurrency), $snapshot->subtotal->amountMinor, $method);
            $shippingMinor += $adjustment;
        }
         $total = $snapshot->grandTotal->subtract(new Money($discount + $giftDiscount + $promotionDiscount, $snapshot->grandTotal->currency))->subtract(new Money($memberDiscount + $dealDiscount, $snapshot->grandTotal->currency))->add(new Money($shippingMinor - $snapshot->shipping->amountMinor, $snapshot->grandTotal->currency));
         $result = ['subtotal_minor' => $snapshot->subtotal->amountMinor, 'discount_minor' => $discount, 'promotion_discount' => $promotionDiscount, 'promotion_rule' => $promotionRule, 'gift_discount_minor' => $giftDiscount, 'gift_code' => count($codes) === 1 ? $codes[0] : null, 'gift_tenders' => $giftTenders, 'member_discount_minor' => $memberDiscount, 'member_tier' => $memberTier, 'member_deal_discount_minor' => $dealDiscount, 'member_deal' => $dealRow !== null ? ['id' => Arr::str($dealRow, 'id'), 'name' => Arr::str($dealRow, 'name'), 'percent_off' => Arr::int($dealRow, 'percent_off')] : null, 'shipping_minor' => $shippingMinor, 'tax_minor' => $snapshot->tax->amountMinor, 'total_minor' => $total->amountMinor, 'pickup' => $pickup, 'lines' => $lines];
        Event::emit('cart.quoted', ['cart_id' => $cartId, 'total_minor' => $result['total_minor']]);
        return $result;
    }

    private function promotionRules(): PromotionRuleService
    {
        return $this->promotionRules ?? new PromotionRuleService();
    }

    /**
     * @param array<mixed>|null $scope
     * @param array<string, mixed> $cart
     * @return array<string, mixed>
     */
    private function shippingScope(?array $scope, array $cart, string $currency): array
    {
        $normalized = [];
        foreach ($scope ?? [] as $key => $value) {
            if (is_string($key)) $normalized[$key] = $value;
        }
        if (Arr::str($normalized, 'store_id') === '') $normalized['store_id'] = Arr::str($cart, 'store_id', 'default');
        if (Arr::str($normalized, 'channel_id') === '') $normalized['channel_id'] = Arr::str($cart, 'channel_id', 'web');
        if (Arr::str($normalized, 'locale') === '') $normalized['locale'] = 'en';
        if (Arr::str($normalized, 'currency') === '') $normalized['currency'] = $currency;
        return $normalized;
    }

    /**
     * Lines the point source cannot fully cover, for the 422 message.
     * @param array<int, array<string, mixed>> $lines @return list<string>
     */
    private function pickupShortfalls(array $lines, string $sourceId): array
    {
        $short = [];
        foreach ($lines as $line) {
            $variant = Arr::requiredStr($line, 'variant_id');
            $need = Arr::requiredInt($line, 'qty');
            $have = 0;
            foreach (\App\Models\InventoryItem::query()->where('variant_id', $variant)->where('source_id', $sourceId !== '' ? $sourceId : 'default')->get() as $item) {
                $row = Arr::row($item);
                $have += max(0, Arr::int($row, 'on_hand') - Arr::int($row, 'reserved'));
            }
            if ($have < $need) $short[] = Arr::str($line, 'sku', $variant) . " (need {$need}, have {$have})";
        }
        return $short;
    }
}
