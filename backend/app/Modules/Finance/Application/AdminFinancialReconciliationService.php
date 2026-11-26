<?php
declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\Arr;
use App\Support\CommerceScope;

final class AdminFinancialReconciliationService
{
    /** @return array{data: array<int, array<string,mixed>>, meta: array<string,int>} */
    public function list(CommerceScope $scope, int $page, int $perPage): array
    {
        $rows = [];
        foreach (Order::query()->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId)->get() as $order) {
            $orderRow = Arr::row($order); $orderId = Arr::str($orderRow, 'id');
            foreach (Invoice::query()->where('order_id', $orderId)->get() as $invoice) {
                $row = Arr::row($invoice); $credited = 0;
                foreach (CreditMemo::query()->where('invoice_id', Arr::str($row, 'id'))->whereIn('state', ['issued', 'pending'])->get() as $memo) $credited += Arr::int(Arr::row($memo), 'amount_minor');
                $amount = Arr::int($row, 'amount_minor');
                $rows[] = ['invoice_id' => Arr::str($row, 'id'), 'invoice_number' => Arr::str($row, 'number'), 'order_id' => $orderId, 'state' => Arr::str($row, 'state', 'unknown'), 'currency' => Arr::str($row, 'currency', 'VND'), 'minor_unit' => Arr::int($row, 'minor_unit'), 'amount_minor' => $amount, 'credited_minor' => $credited, 'reconciliation' => $credited > $amount ? 'over_credited' : ($credited === $amount ? 'balanced' : 'open'), 'evidence_available' => Arr::str($row, 'evidence_json') !== ''];
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['invoice_id'] ?? ''), (string) ($a['invoice_id'] ?? '')));
        $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $total = count($rows); $offset = ($page - 1) * $perPage;
        return ['data' => array_slice($rows, $offset, $perPage), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }
}
