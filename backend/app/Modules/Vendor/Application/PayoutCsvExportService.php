<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application;

use App\Models\Vendor;
use App\Support\Arr;

/**
 * Bank-bulk payout CSV.
 *
 * One row describes one payout batch. The CSV is UTF-8 with a BOM and CRLF
 * endings for spreadsheet compatibility. No bank API is called.
 */
final class PayoutCsvExportService
{
    public const COLUMNS = [
        'payout_id', 'vendor', 'amount_minor', 'currency', 'period_start', 'period_end', 'status',
    ];

    public function __construct(private readonly VendorPayoutService $payouts)
    {
    }

    /** @return array{csv: string, rows: int} */
    public function export(string $payoutId): array
    {
        $payout = $this->payouts->payout($payoutId);
        $vendorId = Arr::str($payout, 'vendor_id');
        $vendor = $vendorId === '' ? null : Vendor::find($vendorId);
        $vendorName = $vendor === null ? $vendorId : Arr::str(Arr::row($vendor), 'name', $vendorId);
        return $this->build([$payout], $vendorId === '' ? [] : [$vendorId => $vendorName]);
    }

    /**
     * @param array<int, array<string, mixed>> $payouts
     * @param array<string, string> $vendorNames
     * @return array{csv: string, rows: int}
     */
    public function build(array $payouts, array $vendorNames = []): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create payout export');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::COLUMNS, ',', '"', '', "\r\n");
        foreach ($payouts as $payout) {
            $vendorId = Arr::str($payout, 'vendor_id');
            $values = [
                Arr::str($payout, 'id'),
                $vendorNames[$vendorId] ?? $vendorId,
                (string) Arr::int($payout, 'amount_minor'),
                Arr::str($payout, 'currency', 'VND'),
                Arr::str($payout, 'period_start'),
                Arr::str($payout, 'period_end'),
                Arr::str($payout, 'state'),
            ];
            fputcsv($stream, $values, ',', '"', '', "\r\n");
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return [
            'csv' => $csv === false ? "\xEF\xBB\xBF" : $csv,
            'rows' => count($payouts),
        ];
    }
}
