<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Application;

use App\Modules\Cart\Contracts\CartCommandPort;
use App\Modules\Catalog\Domain\ProductTypeDefinition;
use App\Modules\Checkout\Contracts\PromotionPort;
use App\Modules\Promotion\Application\PromotionRuleService;
use App\Modules\Pricing\Application\PricingEngine;
use App\Modules\Shipping\Application\ShippingFeeAdjusterRegistry;
use App\Modules\Pricing\Contracts\PricingSnapshotStore;
use App\Modules\Pricing\Domain\PricingSnapshot as DomainSnapshot;
use App\Modules\Shared\Domain\Money;
use App\Support\Arr;
use App\Support\Ids;

final class QuoteService
{
    public function __construct(
        private readonly CartCommandPort $carts,
        private readonly PricingEngine $pricing,
        private readonly PromotionPort $promotions,
        private readonly PricingSnapshotStore $snapshots,
        private readonly ?PromotionRuleService $promotionRules = null,
    ) {}

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $context */
    public function quote(string $cartId, ?string $coupon = null, array $context = []): array
    {
        $cart = $this->carts->find($cartId);
        $lines = $this->carts->lines($cartId);
        if ($cart === null || $lines === []) throw new \RuntimeException('Cart is empty');
        $pricingLines = [];
        foreach ($lines as $line) {
            $productType = ProductTypeDefinition::fromLine($line);
            $sourceLines = [$line];
            $components = [];
            if ($productType->type === 'bundle') {
                $components = ProductTypeDefinition::normalizeComponents($line['components'] ?? []);
            }
            if ($productType->type === 'bundle' && $productType->bundlePricingMode === 'component_sum') {
                $sourceLines = [];
                foreach ($components as $component) {
                    $componentQty = Arr::requiredInt($component, 'qty') * Arr::requiredInt($line, 'qty');
                    $unit = Arr::int($component, 'unit_price_minor', -1);
                    if ($componentQty <= 0 || $unit < 0) throw new \RuntimeException('Bundle component price is unavailable');
                    $sourceLines[] = ['variant_id' => Arr::requiredStr($component, 'component_variant_id'), 'sku' => Arr::str($component, 'sku'), 'name' => Arr::str($component, 'name', Arr::str($line, 'name')), 'qty' => $componentQty, 'unit_price_minor' => $unit, 'product_id' => Arr::str($line, 'product_id'), 'parent_variant_id' => Arr::str($line, 'variant_id'), 'product_type' => 'bundle_component'];
                }
                if ($sourceLines === []) throw new \RuntimeException('Bundle has no components');
            }
            foreach ($sourceLines as $line) {
            $pricingLine = ['variant_id' => Arr::requiredStr($line, 'variant_id'), 'sku' => Arr::str($line, 'sku'), 'name' => Arr::str($line, 'name'), 'qty' => Arr::requiredInt($line, 'qty'), 'unit_price_minor' => Arr::requiredInt($line, 'unit_price_minor')];
            foreach (['product_id', 'category_id'] as $scopeKey) {
                $scopeValue = Arr::str($line, $scopeKey);
                if ($scopeValue !== '') $pricingLine[$scopeKey] = $scopeValue;
            }
            $taxClass = Arr::str($line, 'tax_class');
            if ($taxClass !== '') $pricingLine['tax_class'] = $taxClass;
            $pricingLines[] = $pricingLine;
            }
        }
        $currency = Arr::str($cart, 'currency', 'VND');
        $context['scope'] = is_array($context['scope'] ?? null) ? $context['scope'] : [];
        $context['scope']['currency'] = $currency;
        $base = $this->pricing->quote(Ids::new(), $currency, $pricingLines, $context);
        $fulfillment = is_string($context['fulfillment_method'] ?? null) ? strtolower(trim($context['fulfillment_method'])) : '';
        $method = ShippingFeeAdjusterRegistry::normalizeMethod($context['shipping_method'] ?? null)
            ?? ShippingFeeAdjusterRegistry::normalizeMethod($context['method'] ?? null)
            ?? ShippingFeeAdjusterRegistry::normalizeMethod($context['payment_method'] ?? null)
            ?? ShippingFeeAdjusterRegistry::normalizeMethod($context['payment_provider'] ?? null)
            ?? 'standard';
        $shippingAdjustment = $fulfillment === 'pickup'
            ? 0
            : ShippingFeeAdjusterRegistry::fee($this->shippingScope($context['scope'], $currency), $base->subtotal->amountMinor, $method);
        $shipping = new Money($base->shipping->amountMinor + $shippingAdjustment, $currency);
        $discount = 0; $rules = [];
        if ($coupon !== null && $coupon !== '') {
            $preview = $this->promotions->preview($coupon, $base->subtotal->amountMinor, $currency, $pricingLines, ['customer_id' => Arr::str($cart, 'customer_id'), 'customer_group_id' => Arr::str($cart, 'customer_group_id'), 'cart_id' => $cartId]);
            if (isset($preview['error'])) throw new \RuntimeException(is_scalar($preview['error']) ? (string) $preview['error'] : 'Promotion preview failed');
            $discount = Arr::int($preview, 'discount_minor');
            if ($discount < 0 || $discount > $base->subtotal->amountMinor) throw new \RuntimeException('Promotion returned an invalid discount');
            $rules[] = ['type' => 'coupon', 'evidence' => $preview, 'discount_minor' => $discount];
        }
        $promotionBase = max(0, $base->subtotal->amountMinor - $discount);
        $promotion = $this->promotionRules()->bestFor($pricingLines, $promotionBase);
        $promotionDiscount = $promotion !== null ? (int) $promotion['discount'] : 0;
        $promotionRule = $promotion !== null ? [
            'id' => Arr::str($promotion['rule'], 'id'),
            'name' => Arr::str($promotion['rule'], 'name'),
            'kind' => Arr::str($promotion['rule'], 'kind'),
            'discount' => $promotionDiscount,
        ] : null;
        if ($promotion !== null) $rules[] = ['type' => 'promotion_rule', 'evidence' => $promotion['evidence'], 'discount_minor' => $promotionDiscount, 'rule' => $promotionRule];
        $snapshot = new DomainSnapshot($base->id, $base->engineVersion, $base->lines, $base->subtotal, new Money($discount, $currency), $shipping, $base->tax, new Money($base->grandTotal->amountMinor + $shippingAdjustment - $discount - $promotionDiscount, $currency), $rules, $base->taxes, date('Y-m-d H:i:s', time() + 900));
        $saved = $this->snapshots->save(['id' => $snapshot->id, 'owner_type' => 'cart', 'owner_id' => $cartId, 'currency' => $currency, 'engine_version' => $snapshot->engineVersion, 'lines_json' => json_encode($snapshot->lines, JSON_THROW_ON_ERROR), 'rules_json' => json_encode($snapshot->rules, JSON_THROW_ON_ERROR), 'promotion_evidence_json' => json_encode($rules, JSON_THROW_ON_ERROR), 'taxes_json' => json_encode($snapshot->taxes, JSON_THROW_ON_ERROR), 'subtotal_minor' => $snapshot->subtotal->amountMinor, 'discount_minor' => $snapshot->discount->amountMinor, 'shipping_minor' => $snapshot->shipping->amountMinor, 'tax_minor' => $snapshot->tax->amountMinor, 'grand_total_minor' => $snapshot->grandTotal->amountMinor, 'expires_at' => $snapshot->expiresAt, 'created_at' => date('Y-m-d H:i:s')]);
        return $saved + ['lines' => $snapshot->lines, 'rules' => $snapshot->rules, 'taxes' => $snapshot->taxes, 'promotion_discount' => $promotionDiscount, 'promotion_rule' => $promotionRule];
    }

    private function promotionRules(): PromotionRuleService
    {
        return $this->promotionRules ?? new PromotionRuleService();
    }

    /**
     * @param array<mixed> $scope
     * @return array<string, mixed>
     */
    private function shippingScope(array $scope, string $currency): array
    {
        $normalized = [];
        foreach ($scope as $key => $value) {
            if (is_string($key)) $normalized[$key] = $value;
        }
        if (Arr::str($normalized, 'store_id') === '') $normalized['store_id'] = 'default';
        if (Arr::str($normalized, 'channel_id') === '') $normalized['channel_id'] = 'web';
        if (Arr::str($normalized, 'locale') === '') $normalized['locale'] = 'en';
        if (Arr::str($normalized, 'currency') === '') $normalized['currency'] = $currency;
        return $normalized;
    }
}
