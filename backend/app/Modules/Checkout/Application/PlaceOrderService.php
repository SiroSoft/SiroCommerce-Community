<?php
declare(strict_types=1);
namespace App\Modules\Checkout\Application;

use App\Modules\Checkout\Contracts\CartPort;
use App\Modules\Catalog\Domain\ProductTypeDefinition;
use App\Modules\Checkout\Contracts\InventoryPort;
use App\Modules\Checkout\Contracts\OrderPort;
use App\Modules\Checkout\Contracts\PromotionPort;
use App\Modules\Checkout\Contracts\OrderStatePort;
use App\Modules\Checkout\Contracts\CheckoutHandler;
use App\Modules\Promotion\Application\PromotionRuleService;
use App\Modules\Payment\Application\PaymentOperationService;
use App\Modules\Payment\Contracts\PaymentProvider;
use App\Modules\Payment\Domain\PaymentOperation;
use App\Modules\Shared\Domain\Money;
use App\Modules\Shared\Domain\Currency;
use App\Modules\Vendor\Domain\VendorCommission;
use App\Modules\Pricing\Contracts\PricingSnapshotStore;
use App\Support\Arr;
use App\Support\DatabaseRetry;
use App\Support\Ids;
use App\Modules\Shared\Hooks\HookName;
use App\Modules\Shared\Hooks\Hooks;
use Siro\Core\Database;
use Siro\Core\Event;

/** Native checkout orchestration. Persistence and integrations are ports. */
final class PlaceOrderService implements CheckoutHandler
{
    public function __construct(
        private readonly CartPort $carts,
        private readonly OrderPort $orders,
        private readonly InventoryPort $inventory,
        private readonly PromotionPort $promotions,
        private readonly PaymentOperationService $payments,
        private readonly PaymentProvider $paymentProvider,
        private readonly OrderStatePort $states,
        private readonly PricingSnapshotStore $snapshots,
        private readonly ?PromotionRuleService $promotionRules = null,
    ) {}

    /** @param array<string, string>|null $scope @param mixed $giftCodes string|string[]|null @return array<string, mixed> */
    public function place(string $cartId, string $idempotencyKey, ?string $guestEmail = null, ?string $couponCode = null, ?array $scope = null, ?string $paymentProvider = null, ?string $memberCustomerId = null, ?string $giftCode = null, mixed $giftCodes = null, ?string $fulfillmentMethod = null, ?string $pickupPoint = null): array
    {
        if ($idempotencyKey === '') throw new \InvalidArgumentException('Idempotency-Key is required');
        $chosenProvider = strtolower(trim($paymentProvider ?? 'cod'));
        if ($chosenProvider === '') $chosenProvider = 'cod';
        // Normalize via registry canonical; map 'card' to default offline for now (cod) if no live gateway
        if ($chosenProvider === 'card') $chosenProvider = 'cod';
        try {
            $chosenProvider = \App\Modules\Payment\Infrastructure\PaymentProviderRegistry::canonical($chosenProvider);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Unsupported payment provider: ' . $chosenProvider);
        }
        $issuedGuestToken = null;
        /** @var array<string, mixed> $checkout */
        $checkout = [];
        $existing = $this->orders->byIdempotency($idempotencyKey);
        if ($existing !== null) return $existing;
        // Risk/compliance modules may veto the place (reason surfaces as 422)
        // before any reservation, payment, or order row is written.
        Hooks::fire(HookName::CheckoutBeforePlace, ['cart_id' => $cartId, 'guest_email' => $guestEmail, 'coupon_code' => $couponCode, 'payment_provider' => $chosenProvider, 'member_customer_id' => $memberCustomerId]);
        try {
            $checkout = DatabaseRetry::transaction(function () use ($cartId, $idempotencyKey, $guestEmail, $couponCode, $scope, &$issuedGuestToken, $chosenProvider, $memberCustomerId, $giftCode, $giftCodes, $fulfillmentMethod, $pickupPoint): array {
            $existing = $this->orders->byIdempotency($idempotencyKey);
            if ($existing !== null) return $existing;
            $cart = $this->carts->find($cartId);
            if ($cart === null || Arr::str($cart, 'state') !== 'open') throw new \RuntimeException('Cart not available');
            $cartCurrency = Currency::normalize(Arr::str($cart, 'currency', 'VND'));
            $lines = $this->carts->lines($cartId);
            if ($lines === []) throw new \RuntimeException('Cart is empty');
            $snapshot = $this->snapshots->current('cart', $cartId);
            $snapshotValid = is_array($snapshot) && ($expiresAt = Arr::nullableStr($snapshot, 'expires_at')) !== null && strtotime($expiresAt) > time();
             $subtotalMoney = new Money(0, $cartCurrency);
            foreach ($lines as $line) {
                $productType = ProductTypeDefinition::fromLine($line);
                $qty = Arr::requiredInt($line, 'qty');
                $unitPrice = Arr::requiredInt($line, 'unit_price_minor');
                if ($qty <= 0 || $unitPrice < 0) throw new \InvalidArgumentException('Invalid cart line');
                if ($productType->type === 'bundle' && $productType->bundlePricingMode === 'component_sum') {
                    $components = ProductTypeDefinition::normalizeComponents($line['components'] ?? []);
                    foreach ($components as $component) {
                        $componentUnit = Arr::int($component, 'unit_price_minor', -1);
                        if ($componentUnit < 0) throw new \RuntimeException('Bundle component price is unavailable');
                         $subtotalMoney = $subtotalMoney->add((new Money($componentUnit, $subtotalMoney->currency))->multiply($qty)->multiply(Arr::requiredInt($component, 'qty')));
                    }
                } else {
                     $subtotalMoney = $subtotalMoney->add((new Money($unitPrice, $subtotalMoney->currency))->multiply($qty));
                }
            }
             $subtotal = $subtotalMoney->amountMinor;
             $discount = 0; $shipping = 0; $tax = 0; $grandTotal = $subtotal; $couponId = null; $promotionEvidence = []; $promotionReservation = null; $giftDiscount = 0; $giftCodesNormalized = \App\Modules\Promotion\Application\GiftCreditService::normalizeCodes($giftCode, $giftCodes);
            // Click & Collect: pickup re-validates the point at place time
            // (it may have been disabled since the quote) and reserves from
            // the point's source instead of the default pool.
            $pickupCode = null;
            $pickupSource = 'default';
            if ($fulfillmentMethod !== null && trim($fulfillmentMethod) !== '') {
                if (strtolower(trim($fulfillmentMethod)) !== 'pickup') throw new \InvalidArgumentException('Fulfillment method is invalid');
                if ($pickupPoint === null || trim($pickupPoint) === '') throw new \InvalidArgumentException('Pickup point is required for pickup');
                $points = new \App\Modules\Fulfillment\Application\PickupPointService();
                $point = $points->activePoint($pickupPoint);
                if ($point === null) throw new \RuntimeException('Pickup point is unknown or disabled');
                $pickupCode = Arr::str($point, 'code');
                $pickupSource = Arr::str($point, 'source_id', 'default') !== '' ? Arr::str($point, 'source_id', 'default') : 'default';
            } elseif ($pickupPoint !== null && trim($pickupPoint) !== '') {
                throw new \InvalidArgumentException('Pickup point requires pickup fulfillment');
            }
            $currencyForPromotion = Currency::normalize(Arr::str($cart, 'currency', 'VND'));
            // Member first: coupons evaluate on the member-reduced base (same as quote).
            $memberDiscount = 0;
            $memberTier = null;
            try {
                $memberLines = [];
                foreach ($lines as $line) $memberLines[] = ['variant_id' => Arr::requiredStr($line, 'variant_id'), 'qty' => Arr::requiredInt($line, 'qty'), 'unit_price_minor' => Arr::requiredInt($line, 'unit_price_minor')];
                // Authenticated member id wins; guest carts carry no customer_id.
                $memberId = ($memberCustomerId !== null && $memberCustomerId !== '') ? $memberCustomerId : Arr::str($cart, 'customer_id');
                $member = (new \App\Modules\Promotion\Application\MemberPriceService())->discountFor($memberId, $memberLines, $currencyForPromotion);
                $memberDiscount = max(0, min(Arr::int($member, 'discount_minor'), $subtotal));
                $memberTier = Arr::nullableStr($member, 'tier');
                if ($memberDiscount <= 0) $memberTier = null;
            } catch (\Throwable $e) {
                $memberDiscount = 0;
                $memberTier = null;
            }
            // Tier visibility recheck (same rule as quote): membership may have
            // changed between quote and place; gated lines fail the order.
            $viewerTier = (new \App\Modules\Promotion\Application\MemberPriceService())->tierFor($memberId);
            $placeVariantIds = [];
            foreach ($lines as $line) $placeVariantIds[] = Arr::requiredStr($line, 'variant_id');
            foreach (\App\Modules\Promotion\Application\MemberPriceService::productTiers($placeVariantIds) as $vid => $minTier) {
                if (!\App\Modules\Promotion\Application\MemberPriceService::tierCanSee($viewerTier, $minTier)) {
                    throw new \RuntimeException('Product requires ' . ($minTier ?? 'member') . ' membership');
                }
            }
            // Member deal after tier prices, before coupons (same as quote).
            $dealDiscount = 0;
            $dealRow = null;
            try {
                $deal = (new \App\Modules\Promotion\Application\MemberDealService())->discountFor($memberId, max(0, $subtotal - $memberDiscount));
                $dealDiscount = min(Arr::int($deal, 'discount_minor'), max(0, $subtotal - $memberDiscount));
                $dealRow = $deal['deal'];
                if ($dealDiscount <= 0) $dealRow = null;
            } catch (\Throwable $e) {
                $dealDiscount = 0;
                $dealRow = null;
            }
            $couponBase = max(0, $subtotal - $memberDiscount - $dealDiscount);
            if ($couponCode !== null && $couponCode !== '') {
                $preview = $this->promotions->preview($couponCode, $couponBase, $currencyForPromotion, $lines, ['customer_id' => Arr::str($cart, 'customer_id'), 'customer_group_id' => Arr::str($cart, 'customer_group_id'), 'cart_id' => $cartId, 'request_id' => $idempotencyKey]);
                if (isset($preview['error'])) {
                    $error = $preview['error'];
                    throw new \RuntimeException(is_scalar($error) ? (string) $error : 'Promotion preview failed');
                }
                $discount = Arr::int(Arr::row($preview), 'discount_minor'); $couponId = Arr::nullableStr(Arr::row($preview), 'coupon_id'); $promotionEvidence[] = $preview;
                if ($discount < 0 || $discount > $couponBase) throw new \RuntimeException('Promotion returned an invalid discount');
            }
            // Price rules run after coupons/member pricing and before gift tender.
            $promotionBase = max(0, $couponBase - $discount);
            $promotion = $this->promotionRules()->bestFor($lines, $promotionBase);
            $promotionDiscount = $promotion !== null ? (int) $promotion['discount'] : 0;
            $promotionRule = $promotion !== null ? [
                'id' => Arr::str($promotion['rule'], 'id'),
                'name' => Arr::str($promotion['rule'], 'name'),
                'kind' => Arr::str($promotion['rule'], 'kind'),
                'discount' => $promotionDiscount,
            ] : null;
            // Gift/credit tender last, on the promotion-reduced base. Redeemed
            // inside the order transaction so a failure rolls the ledger back.
            // Split tender: each code covers min(balance, remainder) in order,
            // with its own idempotent ledger key per code.
            $giftKeys = [];
            if ($giftCodesNormalized !== []) {
                $giftOwner = ($memberCustomerId !== null && $memberCustomerId !== '') ? $memberCustomerId : Arr::str($cart, 'customer_id');
                $giftService = new \App\Modules\Promotion\Application\GiftCreditService();
                $giftRemaining = max(0, $promotionBase - $promotionDiscount);
                foreach ($giftCodesNormalized as $code) {
                    $giftBalance = $giftService->balance($code, $giftOwner !== '' ? $giftOwner : null);
                    if ($giftBalance === null) throw new \RuntimeException('Gift code is unknown, bound, or inactive');
                    if (strtoupper((string) ($giftBalance['currency'] ?? '')) !== strtoupper($currencyForPromotion)) throw new \RuntimeException('Gift currency mismatch');
                    $covered = min((int) ($giftBalance['balance_minor'] ?? 0), $giftRemaining);
                    if ($covered <= 0) throw new \RuntimeException('Gift balance is empty');
                    $giftKey = 'gift:' . $idempotencyKey . ':' . $code;
                    $giftService->redeemCode($code, $giftOwner !== '' ? $giftOwner : null, $covered, $currencyForPromotion, $giftKey, null);
                    $giftKeys[] = $giftKey;
                    $giftDiscount += $covered;
                    $giftRemaining -= $covered;
                    $promotionEvidence[] = ['gift_code' => $code, 'gift_discount_minor' => $covered];
                }
            }
            if ($snapshotValid && !$this->snapshotMatchesCart($snapshot, $lines, $currencyForPromotion, $couponCode, $giftCodesNormalized)) $snapshotValid = false;
            if ($snapshotValid) {
                $subtotal = Arr::int($snapshot, 'subtotal_minor', $subtotal);
                $discount = max(0, min(Arr::int($snapshot, 'discount_minor', $discount), $subtotal));
                $memberDiscount = max(0, min($memberDiscount, $subtotal - $discount));
                $dealDiscount = max(0, min($dealDiscount, $subtotal - $discount - $memberDiscount));
                $promotionDiscount = max(0, min($promotionDiscount, $subtotal - $discount - $memberDiscount - $dealDiscount));
                // Method is place-time truth: a snapshot priced for delivery
                // must not leak its shipping into a pickup order.
                $shipping = $pickupCode !== null ? 0 : Arr::int($snapshot, 'shipping_minor');
                $tax = Arr::int($snapshot, 'tax_minor');
                $taxInclusive = ($snapshot['tax_inclusive'] ?? false) === true;
                 $grandTotalMoney = (new Money($subtotal, $currencyForPromotion))->subtract(new Money($discount, $currencyForPromotion))->subtract(new Money($giftDiscount, $currencyForPromotion))->subtract(new Money($memberDiscount + $dealDiscount + $promotionDiscount, $currencyForPromotion))->add(new Money($shipping, $currencyForPromotion));
                  if (!$taxInclusive) $grandTotalMoney = $grandTotalMoney->add(new Money($tax, $currencyForPromotion));
                  $grandTotal = $grandTotalMoney->amountMinor;
             } else {
                  $memberDiscount = max(0, min($memberDiscount, $subtotal - $discount));
                  $dealDiscount = max(0, min($dealDiscount, $subtotal - $discount - $memberDiscount));
                  $promotionDiscount = max(0, min($promotionDiscount, $subtotal - $discount - $memberDiscount - $dealDiscount));
                  $giftDiscount = max(0, min($giftDiscount, $subtotal - $discount - $memberDiscount - $dealDiscount - $promotionDiscount));
                  $grandTotal = (new Money($subtotal, $currencyForPromotion))->subtract(new Money($discount, $currencyForPromotion))->subtract(new Money($giftDiscount, $currencyForPromotion))->subtract(new Money($memberDiscount + $dealDiscount + $promotionDiscount, $currencyForPromotion))->amountMinor;
             }
            if ($memberTier !== null) $promotionEvidence[] = ['member_tier' => $memberTier, 'member_discount_minor' => $memberDiscount];
            if ($dealRow !== null) $promotionEvidence[] = ['member_deal_id' => Arr::str($dealRow, 'id'), 'member_deal_name' => Arr::str($dealRow, 'name'), 'member_deal_percent' => Arr::int($dealRow, 'percent_off'), 'member_deal_discount_minor' => $dealDiscount];
            if ($promotionRule !== null) {
                $promotionRule['discount'] = $promotionDiscount;
                $promotionEvidence[] = ['type' => 'promotion_rule', 'rule' => $promotionRule, 'promotion_discount_minor' => $promotionDiscount, 'evidence' => $promotion['evidence']];
            }
            if ($grandTotal < 0) throw new \RuntimeException('Checkout total cannot be negative');
            $reservations = []; $bookingReservations = []; $flashRecords = [];
            $flashSales = new \App\Modules\Promotion\Application\FlashSaleService();
            try {
                foreach ($lines as $line) {
                    $productType = ProductTypeDefinition::fromLine($line);
                    if ($productType->type === 'booking') {
                        $variantId = Arr::requiredStr($line, 'variant_id');
                        $qty = Arr::requiredInt($line, 'qty');
                        $slotStart = Arr::nullableStr($line, 'slot_start');
                        if ($slotStart === null || trim($slotStart) === '') {
                            // P0: booking lines MUST carry a slot — no silent inventory fallback.
                            throw new \RuntimeException('Booking slot_start is required');
                        } else {
                            $slotQuery = \App\Models\BookingSlot::query()->where('variant_id', $variantId)->where('starts_at', $slotStart);
                            if (method_exists($slotQuery, 'lockForUpdate')) $slotQuery = $slotQuery->lockForUpdate();
                            $slot = $slotQuery->first();
                            if ($slot === null) throw new \RuntimeException('Booking slot not found');
                            $slotRow = Arr::row($slot);
                            $capacity = Arr::int($slotRow, 'capacity');
                            $reserved = 0;
                            $resQuery = \App\Models\BookingReservation::query()->where('variant_id', $variantId)->where('slot_start', $slotStart)->whereIn('state', ['reserved','committed']);
                            if (method_exists($resQuery, 'lockForUpdate')) $resQuery = $resQuery->lockForUpdate();
                            foreach ($resQuery->get() as $r) $reserved += Arr::int(Arr::row($r), 'qty');
                            if ($reserved + $qty > $capacity) throw new \RuntimeException('Booking slot is fully booked');
                            $brId = \App\Support\Ids::new();
                            \App\Models\BookingReservation::create(['id' => $brId, 'variant_id' => $variantId, 'slot_start' => $slotStart, 'order_id' => $cartId, 'qty' => $qty, 'state' => 'reserved', 'created_at' => date('Y-m-d H:i:s')]);
                            $bookingReservations[] = ['id' => $brId, 'variant_id' => $variantId, 'slot_start' => $slotStart];
                        }
                        continue;
                    }
                    if ($productType->type === 'bundle') {
                        $components = ProductTypeDefinition::normalizeComponents($line['components'] ?? []);
                        foreach ($components as $component) {
                            $reservations[] = $this->inventory->reserve(Arr::requiredStr($component, 'component_variant_id'), Arr::requiredInt($component, 'qty') * Arr::requiredInt($line, 'qty'), $cartId, $pickupSource, $idempotencyKey . ':inventory:' . count($reservations), $scope);
                        }
                    } else {
                        $reservations[] = $this->inventory->reserve(Arr::requiredStr($line, 'variant_id'), Arr::requiredInt($line, 'qty'), $cartId, $pickupSource, $idempotencyKey . ':inventory:' . count($reservations), $scope);
                    }
                    // Flash-sale: re-validate price + cap with lock, then record the sale.
                    $lineFlashId = Arr::nullableStr($line, 'flash_sale_id');
                    if ($lineFlashId !== null && $lineFlashId !== '' && $productType->type !== 'booking') {
                        $lineVariant = Arr::requiredStr($line, 'variant_id');
                        $lineQty = Arr::requiredInt($line, 'qty');
                        $linePrice = Arr::requiredInt($line, 'unit_price_minor');
                        $deals = $flashSales->activeFor([$lineVariant], null, $viewerTier);
                        $deal = $deals[$lineVariant] ?? null;
                        if ($deal === null || (string) $deal['sale_id'] !== $lineFlashId) throw new \RuntimeException('Flash sale is no longer available');
                        if ((int) $deal['sale_price_minor'] !== $linePrice) throw new \RuntimeException('Flash sale price changed');
                        $recorded = $flashSales->recordSale($lineVariant, $lineQty);
                        $flashRecords[] = ['variant_id' => $lineVariant, 'sale_id' => (string) $recorded['sale_id'], 'qty' => $lineQty];
                    }
                }
                $orderId = Ids::new();
                $currency = Currency::normalize(Arr::str($cart, 'currency', 'VND'));
                // The rule usage increment and the order rows share this
                // transaction. Replays return the existing order above and
                // therefore never increment twice.
                if ($promotionRule !== null) $this->promotionRules()->incrementUsage(Arr::requiredStr($promotionRule, 'id'));
                // The order ID is known before reserving usage, so reconciliation can
                // always join the usage row to the real order.
                if ($couponId !== null) { $promotionReservation = $orderId; $this->promotions->reserveUsage($couponId, $promotionReservation, $discount); }
                // Member attribution: an authenticated member owns the order even
                // when the cart was opened anonymously. The member id is the
                // same identity member pricing, loyalty snapshots, and gift
                // ownership already key on, so refunds-to-credit land in the
                // wallet the member actually sees.
                $orderCustomerId = ($memberCustomerId !== null && $memberCustomerId !== '') ? $memberCustomerId : Arr::nullableStr($cart, 'customer_id');
                $issuedGuestToken = $orderCustomerId === null ? bin2hex(random_bytes(32)) : null;
                $orderEvidence = $snapshotValid ? json_decode(Arr::str($snapshot, 'promotion_evidence_json', '[]'), true) : $promotionEvidence;
                if (!is_array($orderEvidence)) $orderEvidence = $promotionEvidence;
                if ($promotionRule !== null && !$this->hasPromotionRuleEvidence($orderEvidence)) {
                    $orderEvidence[] = ['type' => 'promotion_rule', 'rule' => $promotionRule, 'promotion_discount_minor' => $promotionDiscount, 'evidence' => $promotion['evidence']];
                }
                 $this->orders->create(['id' => $orderId, 'number' => Ids::orderNumber(), 'customer_id' => $orderCustomerId, 'guest_email' => $guestEmail, 'guest_access_token' => $issuedGuestToken, 'currency' => $currency, 'store_id' => $scope['store_id'] ?? null, 'channel_id' => $scope['channel_id'] ?? null, 'tenant_id' => $scope['tenant_id'] ?? null, 'seller_id' => $scope['seller_id'] ?? null, 'site_id' => $scope['site_id'] ?? null, 'pickup_point_code' => $pickupCode, 'minor_unit' => Currency::minorUnit($currency), 'tax_inclusive' => $snapshotValid && ($snapshot['tax_inclusive'] ?? false) === true ? 1 : 0, 'tax_evidence_json' => $snapshotValid ? Arr::str($snapshot, 'taxes_json', '{}') : '{}', 'promotion_evidence_json' => json_encode($orderEvidence, JSON_THROW_ON_ERROR), 'promotion_rule_json' => $promotionRule !== null ? json_encode($promotionRule, JSON_THROW_ON_ERROR) : null, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'shipping_minor' => $shipping, 'tax_minor' => $tax, 'grand_total_minor' => $grandTotal, 'state' => 'pending', 'idempotency_key' => $idempotencyKey, 'created_at' => date('Y-m-d H:i:s')]);
                  foreach ($lines as $line) {
                      $orderLine = $line;
                      if (ProductTypeDefinition::fromLine($line)->type === 'bundle') {
                          $orderLine['components'] = ProductTypeDefinition::normalizeComponents($line['components'] ?? []);
                      }
                      $lineQty = Arr::requiredInt($orderLine, 'qty');
                      $lineUnit = Arr::requiredInt($orderLine, 'unit_price_minor');
                      $lineVendorId = Arr::nullableStr($orderLine, 'vendor_id');
                      $lineGross = $lineUnit * $lineQty;
                      $lineCommission = $lineVendorId === null ? null : VendorCommission::minor($lineGross, Arr::int($orderLine, 'vendor_commission_bps'));
                      $this->orders->addLine(['id' => Ids::new(), 'order_id' => $orderId, 'variant_id' => Arr::requiredStr($orderLine, 'variant_id'), 'sku' => Arr::str($orderLine, 'sku'), 'name' => Arr::str($orderLine, 'name'), 'qty' => $lineQty, 'unit_price_minor' => $lineUnit, 'product_type' => Arr::str($orderLine, 'product_type', 'simple'), 'components_json' => json_encode($orderLine['components'] ?? [], JSON_THROW_ON_ERROR), 'vendor_id' => $lineVendorId, 'vendor_commission_minor' => $lineCommission]);
                  }
                $paymentId = Ids::new();
                  $this->orders->payment(['id' => $paymentId, 'order_id' => $orderId, 'provider' => $chosenProvider, 'amount_minor' => $grandTotal, 'currency' => $currency, 'minor_unit' => Currency::minorUnit($currency), 'state' => 'pending', 'provider_ref' => null, 'idempotency_key' => $idempotencyKey . ':pay']);
                  return ['order_id' => $orderId, 'payment_id' => $paymentId, 'provider' => $chosenProvider, 'grand_total' => $grandTotal, 'currency' => $currency, 'reservations' => $reservations, 'booking_reservations' => $bookingReservations, 'promotion_reservation' => $promotionReservation, 'promotion_rule' => $promotionRule, 'promotion_discount' => $promotionDiscount, 'gift_discount_minor' => $giftDiscount, 'gift_keys' => $giftKeys, 'flash_records' => $flashRecords];
             } catch (\Throwable $e) {
                 foreach ($reservations as $reservation) $this->inventory->release(Arr::requiredStr($reservation, 'id'), 'checkout-failed');
                 foreach ($bookingReservations as $br) { try { $row = \App\Models\BookingReservation::query()->where('id', Arr::str($br, 'id'))->first(); if ($row !== null) { $row->setAttribute('state', 'rolled_back'); $row->save(); } } catch (\Throwable $ex) {} }
                 foreach ($flashRecords as $fr) $flashSales->releaseSale(Arr::str($fr, 'variant_id'), Arr::str($fr, 'sale_id'), (int) $fr['qty']);
                 if ($promotionReservation !== null) $this->promotions->rollbackUsage($promotionReservation);
                 foreach ($giftKeys as $giftKey) { try { (new \App\Modules\Promotion\Application\GiftCreditService())->rollback($giftKey); } catch (\Throwable $ex) {} }
                 throw $e;
             }
            });
        } catch (\Throwable $e) {
            // A concurrent request may have won the unique idempotency insert.
            $existing = $this->orders->byIdempotency($idempotencyKey);
            if ($existing !== null) {
            $checkout = $existing;
            } else {
                throw $e;
            }
        }
        $checkout = Arr::row($checkout);
         if (!isset($checkout['payment_id'])) {
             $result = $checkout;
             /** @var array<string, mixed> $normalized */
             $normalized = [];
         foreach ($result as $key => $value) $normalized[$key] = $value;
             if ($issuedGuestToken !== null) $normalized['guest_access_token'] = $issuedGuestToken;
             return $normalized;
         }
          $orderId = Arr::requiredStr($checkout, 'order_id');
          $paymentId = Arr::requiredStr($checkout, 'payment_id');
          // Persisted provider determines actual authorization (cod vs bank_transfer vs gateway)
          $providerForAuth = Arr::str($checkout, 'provider', $chosenProvider ?? 'cod');
          if ($providerForAuth === '') $providerForAuth = $chosenProvider ?? 'cod';
          $auth = $this->payments->execute(new PaymentOperation(Ids::new(), $paymentId, 'authorize', new Money(Arr::int($checkout, 'grand_total'), Arr::requiredStr($checkout, 'currency')), $idempotencyKey . ':pay'), $this->paymentProvider, ['order_id' => $orderId, 'provider' => $providerForAuth]);
         if (!$auth->ok || !is_array($auth->value)) {
              // Unknown provider outcomes are durable in payment_operations. Do not
              // roll them back with checkout inventory/promotion work.
              if (($auth->code ?? '') === 'payment.operation_unknown') return $this->orders->find($orderId) ?? throw new \RuntimeException('Order lost after unknown payment');
              foreach ($this->rows($checkout['reservations'] ?? []) as $reservation) $this->inventory->release(Arr::requiredStr($reservation, 'id'), 'checkout-payment-failed');
              foreach ($this->rows($checkout['booking_reservations'] ?? []) as $br) { try { $row = \App\Models\BookingReservation::query()->where('id', Arr::str($br, 'id'))->first(); if ($row !== null) { $row->setAttribute('state', 'rolled_back'); $row->save(); } } catch (\Throwable $e) {} }
              foreach ($this->rows($checkout['flash_records'] ?? []) as $fr) { try { (new \App\Modules\Promotion\Application\FlashSaleService())->releaseSale(Arr::str($fr, 'variant_id'), Arr::str($fr, 'sale_id'), (int) $fr['qty']); } catch (\Throwable $e) {} }
               if (($promotionReservation = Arr::nullableStr($checkout, 'promotion_reservation')) !== null) $this->promotions->rollbackUsage($promotionReservation);
               foreach (is_array($checkout['gift_keys'] ?? null) ? $checkout['gift_keys'] : [] as $giftKey) { try { (new \App\Modules\Promotion\Application\GiftCreditService())->rollback((string) $giftKey); } catch (\Throwable $e) {} }
               throw new \RuntimeException($auth->message ?? 'Payment authorization failed');
          }
          $authValue = Arr::row($auth->value);
          DatabaseRetry::transaction(function () use ($cartId, $checkout, $orderId, $paymentId, $authValue): void {
              $this->orders->updatePayment($paymentId, Arr::str($authValue, 'state', 'authorized'), Arr::nullableStr($authValue, 'provider_ref'));
              $this->states->transition($orderId, 'awaiting_payment', 'checkout', 'placed');
               foreach ($this->rows($checkout['reservations'] ?? []) as $reservation) $this->inventory->commit(Arr::requiredStr($reservation, 'id'), $orderId);
               foreach ($this->rows($checkout['booking_reservations'] ?? []) as $br) { try { $row = \App\Models\BookingReservation::query()->where('id', Arr::str($br, 'id'))->first(); if ($row !== null) { $row->setAttribute('state', 'committed'); $row->setAttribute('order_id', $orderId); $row->save(); } } catch (\Throwable $e) {} }
              $this->carts->converted($cartId);
              if (($promotionReservation = Arr::nullableStr($checkout, 'promotion_reservation')) !== null) $this->promotions->commitUsage($promotionReservation);
          });
         $result = $this->orders->find($orderId);
         if ($result === null) throw new \RuntimeException('Order lost after create');
          Event::emit('order.placed', ['order_id' => $orderId, 'cart_id' => $cartId, 'customer_id' => Arr::nullableStr($checkout, 'customer_id'), 'total_minor' => Arr::int($checkout, 'grand_total')]);
         /** @var array<string, mixed> $normalized */
         $normalized = [];
         foreach ($result as $key => $value) $normalized[$key] = $value;
        if ($issuedGuestToken !== null) $normalized['guest_access_token'] = $issuedGuestToken;
        return $normalized;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<int, array<string, mixed>> $lines
     */
    /** @param list<string> $giftCodes */
    private function snapshotMatchesCart(array $snapshot, array $lines, string $currency, ?string $couponCode, array $giftCodes = []): bool
    {
        if (strtoupper(Arr::str($snapshot, 'currency')) !== $currency) return false;
        foreach ($lines as $line) {
            if (Arr::str($line, 'product_type') === 'bundle') {
                return $couponCode === null ? Arr::int($snapshot, 'discount_minor') === 0 : true;
            }
        }
        $storedLinesRaw = json_decode(Arr::str($snapshot, 'lines_json'), true);
        $storedLines = [];
        if (is_array($storedLinesRaw)) {
            foreach ($storedLinesRaw as $storedLine) {
                if (!is_array($storedLine)) return false;
                $normalized = [];
                foreach ($storedLine as $key => $value) if (is_string($key)) $normalized[$key] = $value;
                $storedLines[] = $normalized;
            }
        }
        if (count($storedLines) !== count($lines)) return false;
        foreach (array_values($lines) as $index => $line) {
            $stored = $storedLines[$index] ?? [];
            if (Arr::str($stored, 'variant_id') !== Arr::str($line, 'variant_id') || Arr::int($stored, 'qty') !== Arr::int($line, 'qty') || Arr::int($stored, 'unit_price_minor') !== Arr::int($line, 'unit_price_minor')) return false;
            // P0: booking slot change must invalidate the price snapshot.
            if (Arr::nullableStr($stored, 'slot_start') !== Arr::nullableStr($line, 'slot_start')) return false;
        }
        $evidence = json_decode(Arr::str($snapshot, 'promotion_evidence_json', '[]'), true);
        if (!is_array($evidence)) return false;
        $snapshotCode = null;
        foreach ($evidence as $entry) {
            if (is_array($entry)) {
                $nested = $entry['evidence'] ?? null;
                if (is_array($nested)) {
                    $nestedRow = [];
                    foreach ($nested as $key => $value) if (is_string($key)) $nestedRow[$key] = $value;
                    $snapshotCode = Arr::nullableStr($nestedRow, 'code');
                }
            }
        }
        if ($couponCode === null) {
            if (Arr::int($snapshot, 'discount_minor') !== 0) return false;
        } elseif ($snapshotCode !== $couponCode) {
            return false;
        }
        $snapshotGifts = [];
        foreach ($evidence as $entry) {
            if (is_array($entry) && isset($entry['gift_code']) && is_string($entry['gift_code'])) $snapshotGifts[] = strtoupper($entry['gift_code']);
        }
        sort($snapshotGifts);
        $wanted = array_map('strtoupper', $giftCodes);
        sort($wanted);
        return $snapshotGifts === $wanted;
    }

    private function promotionRules(): PromotionRuleService
    {
        return $this->promotionRules ?? new PromotionRuleService();
    }

    /** @param array<int|string, mixed> $evidence */
    private function hasPromotionRuleEvidence(array $evidence): bool
    {
        foreach ($evidence as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'promotion_rule') return true;
        }
        return false;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) return [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = [];
        foreach ($value as $item) if (is_array($item)) {
            $row = [];
            foreach ($item as $key => $entry) if (is_string($key)) $row[$key] = $entry;
            $rows[] = $row;
        }
        return $rows;
    }
}
