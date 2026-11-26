<?php

declare(strict_types=1);

namespace App\Modules\Vendor\Application;

use App\Modules\Vendor\Domain\VendorPayoutConflict;
use App\Modules\Vendor\Domain\VendorPayoutNotFound;
use App\Support\Arr;

/**
 * Bank/provider payout reconciliation. CSV contract:
 * payout_id,status,amount_minor,currency,reference,reason
 * status: paid | failed | returned (aliases: settled/success, rejected, reversed).
 */
final class VendorPayoutReconciliationService
{
    private const MAX_ROWS = 5000;

    public function __construct(private readonly VendorPayoutService $payouts)
    {
    }

    /**
     * @return array{ok: bool, processed: int, paid: int, failed: int, returned: int, skipped: int, errors: int, results: list<array<string, mixed>>}
     */
    public function reconcile(string $csv, bool $dryRun = false): array
    {
        $summary = ['ok' => true, 'processed' => 0, 'paid' => 0, 'failed' => 0, 'returned' => 0, 'skipped' => 0, 'errors' => 0, 'results' => []];
        $rows = $this->rows($csv);
        if ($rows === []) {
            $summary['ok'] = false;
            $summary['errors'] = 1;
            $summary['results'][] = ['row' => 0, 'ok' => false, 'error' => 'CSV has no payout rows'];
            return $summary;
        }
        foreach ($rows as $index => $row) {
            $summary['processed']++;
            $payoutId = $this->field($row, 'payout_id');
            $status = strtolower($this->field($row, 'status'));
            $result = ['row' => $index + 2, 'payout_id' => $payoutId, 'status' => $status, 'ok' => false];
            if ($payoutId === '' || $status === '') {
                $summary['errors']++;
                $result['error'] = 'payout_id and status are required';
                $summary['results'][] = $result;
                continue;
            }
            try {
                $payout = $this->payouts->payout($payoutId);
                $current = Arr::str($payout, 'state');
                $amount = $this->field($row, 'amount_minor');
                if ($amount !== '' && (int) $amount !== Arr::int($payout, 'amount_minor')) {
                    throw new \InvalidArgumentException('Amount does not match the payout');
                }
                $currency = strtoupper($this->field($row, 'currency'));
                if ($currency !== '' && $currency !== strtoupper(Arr::str($payout, 'currency'))) {
                    throw new \InvalidArgumentException('Currency does not match the payout');
                }
                $reference = $this->field($row, 'reference');
                $reason = $this->field($row, 'reason');
                $normalized = $this->normalizeStatus($status);
                if ($normalized === 'paid') {
                    if ($current === 'paid') { $summary['skipped']++; $result['action'] = 'already_paid'; $result['ok'] = true; }
                    elseif ($current === 'pending') {
                        $summary['paid']++;
                        $result['action'] = $dryRun ? 'would_mark_paid' : 'marked_paid';
                        $result['ok'] = true;
                        if (!$dryRun) $this->payouts->markPaid($payoutId, $reference === '' ? null : $reference);
                    } else {
                        throw new VendorPayoutConflict('Cannot mark paid from state ' . $current);
                    }
                } elseif ($normalized === 'failed') {
                    if ($current === 'failed') { $summary['skipped']++; $result['action'] = 'already_failed'; $result['ok'] = true; }
                    elseif ($current === 'pending') {
                        $summary['failed']++;
                        $result['action'] = $dryRun ? 'would_mark_failed' : 'marked_failed';
                        $result['ok'] = true;
                        if (!$dryRun) $this->payouts->markFailed($payoutId, $reason === '' ? null : $reason, $reference === '' ? null : $reference);
                    } else {
                        throw new VendorPayoutConflict('Cannot mark failed from state ' . $current);
                    }
                } elseif ($normalized === 'returned') {
                    if ($current === 'returned') { $summary['skipped']++; $result['action'] = 'already_returned'; $result['ok'] = true; }
                    elseif ($current === 'paid') {
                        $summary['returned']++;
                        $result['action'] = $dryRun ? 'would_mark_returned' : 'marked_returned';
                        $result['ok'] = true;
                        if (!$dryRun) $this->payouts->markReturned($payoutId, $reason === '' ? null : $reason, $reference === '' ? null : $reference);
                    } else {
                        throw new VendorPayoutConflict('Cannot mark returned from state ' . $current);
                    }
                } else {
                    throw new \InvalidArgumentException('Unsupported reconciliation status: ' . $status);
                }
            } catch (VendorPayoutNotFound) {
                $summary['errors']++;
                $result['error'] = 'Payout not found';
            } catch (VendorPayoutConflict|\InvalidArgumentException $exception) {
                $summary['errors']++;
                $result['error'] = $exception->getMessage();
            }
            $summary['results'][] = $result;
        }
        $summary['ok'] = $summary['errors'] === 0;
        return $summary;
    }

    /** @return array<int, array<string, string>> */
    private function rows(string $csv): array
    {
        if (strlen($csv) > 2 * 1024 * 1024) throw new \InvalidArgumentException('Reconciliation CSV exceeds the 2 MB limit');
        $csv = ltrim($csv, "\xEF\xBB\xBF");
        $split = preg_split('/\r\n|\n|\r/', trim($csv));
        $lines = is_array($split) ? $split : [];
        if (count($lines) < 2) return [];
        $headerRow = str_getcsv((string) array_shift($lines));
        $header = [];
        foreach ($headerRow as $value) $header[] = strtolower(trim($value ?? ''));
        if (!in_array('payout_id', $header, true) || !in_array('status', $header, true)) return [];
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $values = str_getcsv($line);
            $row = [];
            foreach ($header as $index => $name) {
                if ($name === '') continue;
                $row[$name] = trim($values[$index] ?? '');
            }
            $rows[] = $row;
            if (count($rows) > self::MAX_ROWS) throw new \InvalidArgumentException('Reconciliation CSV exceeds 5,000 rows');
        }
        return $rows;
    }

    /** @param array<string, string> $row */
    private function field(array $row, string $name): string
    {
        return trim($row[$name] ?? '');
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'paid', 'settled', 'success', 'completed' => 'paid',
            'failed', 'rejected', 'error' => 'failed',
            'returned', 'reversed', 'chargeback' => 'returned',
            default => $status,
        };
    }
}
