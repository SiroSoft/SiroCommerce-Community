<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

final class CustomerEligibility
{
    /** @param array<string, mixed> $rules
     * @param array<string, mixed> $customer
     */
    public static function allows(array $rules, array $customer): bool
    {
        foreach (['customer_ids' => 'id', 'customer_groups' => 'group'] as $rule => $field) {
            if (isset($rules[$rule]) && is_array($rules[$rule]) && !in_array($customer[$field] ?? null, $rules[$rule], true)) return false;
        }
        if (isset($rules['requires_verified_email']) && $rules['requires_verified_email'] === true && ($customer['email_verified'] ?? false) !== true) return false;
        if (isset($rules['minimum_order_count']) && is_numeric($rules['minimum_order_count']) && (!is_numeric($customer['order_count'] ?? null) || (int) $customer['order_count'] < (int) $rules['minimum_order_count'])) return false;
        $country = $customer['country'] ?? '';
        $countries = isset($rules['countries']) && is_array($rules['countries']) ? array_values(array_filter($rules['countries'], 'is_string')) : null;
        if ($countries !== null && !in_array(is_string($country) ? strtoupper($country) : '', array_map('strtoupper', $countries), true)) return false;
        return true;
    }
}
