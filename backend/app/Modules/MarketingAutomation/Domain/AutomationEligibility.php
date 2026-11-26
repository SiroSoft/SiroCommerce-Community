<?php

declare(strict_types=1);

namespace App\Modules\MarketingAutomation\Domain;

use App\Support\Arr;

/** Fail-closed policy for consented lifecycle signals. */
final class AutomationEligibility
{
    public static function campaignKey(string $signal, string ...$parts): string
    {
        return $signal . ':' . implode(':', array_map(static fn (string $part): string => trim($part), $parts));
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $preferences
     * @return array{eligible:bool,reasons:array<int,string>}
     */
    public static function abandonedCart(array $cart, array $preferences, string $now, ?string $lastScheduledAt = null, bool $purchaseSuppressed = false): array
    {
        $reasons = [];
        if (Arr::nullableStr($cart, 'customer_id') === null) $reasons[] = 'identity_required';
        if (Arr::int($preferences, 'marketing_email') !== 1) $reasons[] = 'marketing_consent_required';
        if (Arr::str($cart, 'state') !== 'open') $reasons[] = 'cart_not_open';
        $expiresAt = Arr::nullableStr($cart, 'expires_at');
        if ($expiresAt !== null && strtotime($expiresAt) <= strtotime($now)) $reasons[] = 'cart_expired';
        if ($purchaseSuppressed) $reasons[] = 'purchase_suppressed';
        if ($lastScheduledAt !== null && strtotime($lastScheduledAt) >= strtotime($now . ' -7 days')) $reasons[] = 'frequency_cap';
        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * @param array<string,mixed> $preferences
     * @return array{eligible:bool,reasons:array<int,string>}
     */
    public static function wishlist(array $preferences): array
    {
        $eligible = Arr::int($preferences, 'marketing_email') === 1;
        return ['eligible' => $eligible, 'reasons' => $eligible ? [] : ['marketing_consent_required']];
    }
}
