<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

/**
 * Required payload keys per hook (spec: fail-closed on missing keys — a hook
 * firing without its contract is a programming error, never silent).
 */
final class HookSchemas
{
    /** @return array<string, list<string>> */
    public static function required(): array
    {
        return [
            HookName::PricingBeforeQuote->value => ['currency', 'lines'],
            HookName::OrderBeforeTransition->value => ['order_id', 'from', 'to'],
            HookName::CatalogProductSaved->value => ['product_id'],
            HookName::PaymentBeforeCapture->value => ['payment_id'],
            HookName::OrderDelivered->value => ['order_id'],
            HookName::CustomerRegistered->value => ['customer_id', 'email'],
            HookName::CheckoutBeforePlace->value => ['cart_id'],
            HookName::RefundBeforeIssue->value => ['order_id', 'amount_minor'],
            HookName::SubscriptionBeforeRenew->value => ['subscription_id'],
            HookName::InventoryBeforeReserve->value => ['variant_id', 'qty'],
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function assert(HookName $hook, array $payload): void
    {
        foreach (self::required()[$hook->value] ?? [] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException("Hook {$hook->value} requires payload key: {$key}");
            }
        }
    }
}
