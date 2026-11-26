<?php

declare(strict_types=1);

namespace App\Resources;

use Siro\Core\Resource;

/** Stable, deliberately aggregate-only reporting contract. */
final class ReportResource extends Resource
{
    public function toArray(): array
    {
        $allowed = ['orders', 'sales_minor', 'refunds_minor', 'sales_minor_by_currency', 'refunds_minor_by_currency', 'inventory', 'failures', 'by_state', 'window'];
        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $this->data)) $out[$key] = $this->data[$key];
        }
        return $out;
    }
}
