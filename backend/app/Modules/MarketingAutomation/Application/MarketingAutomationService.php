<?php

declare(strict_types=1);

namespace App\Modules\MarketingAutomation\Application;

use App\Models\Cart;
use App\Models\CustomerNotificationPreference;
use App\Models\MarketingAutomationDelivery;
use App\Models\CommerceJob;
use App\Modules\MarketingAutomation\Domain\AutomationEligibility;
use App\Modules\Platform\Infrastructure\V2SideEffectRepository;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Database;

/** Provider-neutral, consent-gated lifecycle intent scheduling. */
final class MarketingAutomationService
{
    /** @return array<string,mixed> */
    public function abandonedCart(string $cartId, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $cart = Cart::find($cartId);
        if ($cart === null) return ['scheduled' => false, 'reason' => 'cart_not_found'];
        $cartData = Arr::row($cart);
        $customerId = Arr::nullableStr($cartData, 'customer_id');
        $preferences = $customerId === null ? [] : $this->preferences($customerId);
        $last = $customerId === null ? null : $this->lastScheduled($customerId, 'abandoned_cart', $now);
        $decision = AutomationEligibility::abandonedCart($cartData, $preferences, $now, $last, $this->purchaseSuppressed($cartId));
        if (!$decision['eligible']) return ['scheduled' => false, 'reasons' => $decision['reasons']];
        return $this->schedule(AutomationEligibility::campaignKey('abandoned_cart', $cartId), 'abandoned_cart', Arr::requiredStr($cartData, 'customer_id'), $cartId, null, $now);
    }

    /** @return array<string,mixed> */
    public function wishlistReengagement(string $customerId, string $productId, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $decision = AutomationEligibility::wishlist($this->preferences($customerId));
        if (!$decision['eligible']) return ['scheduled' => false, 'reasons' => $decision['reasons']];
        $last = $this->lastScheduled($customerId, 'wishlist', $now);
        if ($last !== null) return ['scheduled' => false, 'reasons' => ['frequency_cap']];
        return $this->schedule(AutomationEligibility::campaignKey('wishlist', $customerId, $productId), 'wishlist', $customerId, null, $productId, $now);
    }

    public function suppressAfterPurchase(?string $cartId, ?string $customerId): void
    {
        $query = MarketingAutomationDelivery::query()->where('state', 'planned');
        if ($cartId !== null) $query->where('cart_id', $cartId);
        elseif ($customerId !== null) $query->where('customer_id', $customerId);
        foreach ($query->get() as $delivery) {
            $delivery->fill(['state' => 'suppressed', 'suppressed_at' => date('Y-m-d H:i:s')]);
            $delivery->save();
            $job = CommerceJob::query()->where('idempotency_key', Arr::str(Arr::row($delivery), 'campaign_key'))->first();
            if ($job !== null) (new V2SideEffectRepository())->cancel(Arr::requiredStr(Arr::row($job), 'id'), 'purchase_suppressed');
        }
    }

    /** @return array<string,mixed> */
    private function schedule(string $key, string $signal, string $customerId, ?string $cartId, ?string $productId, string $now): array
    {
        $existing = MarketingAutomationDelivery::query()->where('campaign_key', $key)->first();
        if ($existing !== null) return ['scheduled' => false, 'duplicate' => true, 'campaign_key' => $key];
        Database::transaction(function () use ($key, $signal, $customerId, $cartId, $productId, $now): void {
            $row = new MarketingAutomationDelivery();
            $row->fill(['id' => Ids::new(), 'campaign_key' => $key, 'signal' => $signal, 'customer_id' => $customerId, 'cart_id' => $cartId, 'product_id' => $productId, 'state' => 'planned', 'scheduled_at' => $now, 'created_at' => $now]);
            $row->save();
        });
        (new V2SideEffectRepository())->enqueue(new MarketingAutomationJob($key, ['campaign_key' => $key, 'signal' => $signal, 'customer_id' => $customerId, 'cart_id' => $cartId, 'product_id' => $productId, 'observed_at' => $now]), $now);
        return ['scheduled' => true, 'campaign_key' => $key];
    }

    /** @return array<string,mixed> */
    private function preferences(string $customerId): array
    {
        $row = CustomerNotificationPreference::query()->where('customer_id', $customerId)->first();
        return $row === null ? ['marketing_email' => 0] : Arr::row($row);
    }

    private function lastScheduled(string $customerId, string $signal, string $now): ?string
    {
        $timestamp = strtotime($now . ' -7 days');
        if ($timestamp === false) throw new \InvalidArgumentException('A valid timestamp is required');
        $row = MarketingAutomationDelivery::query()->where('customer_id', $customerId)->where('signal', $signal)->whereIn('state', ['planned', 'sent'])->where('scheduled_at', '>=', date('Y-m-d H:i:s', $timestamp))->orderBy('scheduled_at', 'desc')->first();
        return $row === null ? null : Arr::nullableStr(Arr::row($row), 'scheduled_at');
    }

    private function purchaseSuppressed(string $cartId): bool
    {
        return Cart::query()->where('id', $cartId)->whereIn('state', ['converted', 'expired'])->first() !== null;
    }
}
