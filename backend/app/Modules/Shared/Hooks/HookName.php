<?php

declare(strict_types=1);

namespace App\Modules\Shared\Hooks;

enum HookName: string
{
    case PricingBeforeQuote = 'pricing.before_quote';
    case OrderBeforeTransition = 'order.before_transition';
    case CatalogProductSaved = 'catalog.product.saved';
    case PaymentBeforeCapture = 'payment.before_capture';
    case OrderDelivered = 'order.delivered';
    case CustomerRegistered = 'customer.registered';
    case CheckoutBeforePlace = 'checkout.before_place';
    case RefundBeforeIssue = 'refund.before_issue';
    case SubscriptionBeforeRenew = 'subscription.before_renew';
    case InventoryBeforeReserve = 'inventory.before_reserve';

    /** @return list<self> */
    public static function catalog(): array
    {
        return self::cases();
    }
}
