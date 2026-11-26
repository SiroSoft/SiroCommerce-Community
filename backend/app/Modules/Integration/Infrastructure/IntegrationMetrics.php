<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure;

use Siro\Core\Metrics;

/** Low-cardinality delivery metrics; correlation IDs remain in headers, never labels. */
final class IntegrationMetrics
{
    public static function delivery(string $eventName, string $state): void
    {
        $event = preg_replace('/\.v[0-9]+$/', '', $eventName) ?: 'unknown';
        $safeState = in_array($state, ['delivered', 'pending', 'dead'], true) ? $state : 'unknown';
        Metrics::counter('integration_webhook_delivery_total', 1, ['event' => $event, 'state' => $safeState]);
    }
}
