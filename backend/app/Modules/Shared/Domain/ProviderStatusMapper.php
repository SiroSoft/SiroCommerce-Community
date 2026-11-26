<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain;

/** Maps provider vocabulary to the small, fail-closed internal vocabulary. */
final class ProviderStatusMapper
{
    public static function mapPayment(mixed $status): ?string
    {
        if (!is_string($status)) return null;
        return ['authorized' => 'authorized', 'authorised' => 'authorized', 'approved' => 'authorized', 'captured' => 'captured', 'succeeded' => 'captured', 'completed' => 'captured', 'partially_captured' => 'partially_captured', 'refunded' => 'refunded', 'partially_refunded' => 'partially_refunded', 'voided' => 'voided', 'cancelled' => 'voided', 'canceled' => 'voided', 'failed' => 'failed', 'declined' => 'failed', 'rejected' => 'failed', 'pending' => 'unknown', 'processing' => 'unknown', 'in_progress' => 'unknown', 'unknown' => 'unknown'][strtolower(trim($status))] ?? null;
    }

    public static function mapShipping(mixed $status): ?string
    {
        if (!is_string($status)) return null;
        return ['pre-transit' => 'label_created', 'label_created' => 'label_created', 'picked_up' => 'picked_up', 'in_transit' => 'in_transit', 'out_for_delivery' => 'out_for_delivery', 'delivered' => 'delivered', 'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'returned' => 'returned', 'failure' => 'exception', 'failed' => 'exception', 'exception' => 'exception'][strtolower(trim($status))] ?? null;
    }

    public static function mapTax(mixed $status): ?string
    {
        if (!is_string($status)) return null;
        return ['calculated' => 'calculated', 'quoted' => 'calculated', 'failed' => 'failed', 'unknown' => 'unknown'][strtolower(trim($status))] ?? null;
    }
}
