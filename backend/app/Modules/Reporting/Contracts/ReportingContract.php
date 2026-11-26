<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

final class ReportingContract
{
    /** @return list<string> */
    public static function dashboardDimensions(): array
    {
        return ['overview', 'failures', 'profitability', 'cohorts', 'retention', 'repeat-purchase', 'attribution', 'sla', 'inventory-turnover', 'widgets'];
    }

    /** @return list<string> */
    public static function exportDatasets(): array
    {
        return ['orders', 'payments', 'refunds', 'subscriptions', 'b2b', 'promotions', 'fulfillment', 'inventory'];
    }

    /** @return array<string, mixed> */
    public static function descriptor(): array
    {
        return ['schema_version' => 1, 'read_only' => true, 'max_window_days' => 366, 'dimensions' => self::dashboardDimensions(), 'export_datasets' => self::exportDatasets(), 'scope' => ['tenant_id', 'store_id', 'channel_id']];
    }
}
