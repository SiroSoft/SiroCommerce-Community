<?php
declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Refund;
use App\Support\Arr;
use App\Support\CommerceScope;

/** Customer-safe financial reads. Provider evidence never leaves this service. */
final class FinancialDocumentQueryService
{
    /** @param list<string> $aliasIds
     * @return array<string, mixed>|null */
    public function forCustomer(string $customerId, string $orderId, ?CommerceScope $scope = null, array $aliasIds = []): ?array
    {
        $query = Order::query()->where('id', $orderId)->whereIn('customer_id', array_values(array_unique(array_merge([$customerId], $aliasIds))));
        if ($scope !== null) $query->where('store_id', $scope->storeId)->where('channel_id', $scope->channelId);
        $order = $query->first();
        if ($order === null) return null;
        $row = Arr::row($order);
        $invoices = [];
        foreach (Invoice::query()->where('order_id', $orderId)->orderBy('created_at', 'asc')->get() as $invoice) $invoices[] = $this->invoice(Arr::row($invoice));
        $memos = [];
        foreach (CreditMemo::query()->where('order_id', $orderId)->orderBy('created_at', 'asc')->get() as $memo) $memos[] = $this->memo(Arr::row($memo));
        $refunds = [];
        foreach (Refund::query()->where('order_id', $orderId)->orderBy('created_at', 'asc')->get() as $refund) $refunds[] = $this->refund(Arr::row($refund));
        return ['order_id' => $orderId, 'currency' => Arr::str($row, 'currency', 'VND'), 'totals' => ['grand_total_minor' => Arr::int($row, 'grand_total_minor'), 'refunded_minor' => array_sum(array_map(static fn (array $item): int => (int) ($item['amount_minor'] ?? 0), $refunds))], 'invoices' => $invoices, 'receipts' => $invoices, 'credit_memos' => $memos, 'refund_timeline' => $refunds, 'available' => $invoices !== [] || $memos !== [] || $refunds !== []];
    }

    /** @param list<string> $aliasIds
     * @return array<string, mixed>|null */
    public function documentForCustomer(string $customerId, string $type, string $id, ?CommerceScope $scope = null, array $aliasIds = []): ?array
    {
        $document = match ($type) {
            'invoice' => Invoice::find($id),
            'credit-memo' => CreditMemo::find($id),
            default => null,
        };
        if ($document === null) return null;
        $row = Arr::row($document);
        $result = $this->forCustomer($customerId, Arr::requiredStr($row, 'order_id'), $scope, $aliasIds);
        if ($result === null) return null;
        $collection = $type === 'invoice' ? $result['invoices'] : $result['credit_memos'];
        foreach (is_array($collection) ? $collection : [] as $item) if (is_array($item) && ($item['id'] ?? null) === $id) return $item + ['download' => ['available' => false, 'reason' => 'document_file_not_configured', 'content_type' => 'application/pdf', 'filename' => ($item['number'] ?? $type) . '.pdf']];
        return null;
    }

    /** @return array<string, mixed> */
    private function invoice(array $row): array { return ['id' => Arr::str($row, 'id'), 'number' => Arr::str($row, 'number'), 'state' => Arr::str($row, 'state', 'unknown'), 'currency' => Arr::str($row, 'currency', 'VND'), 'minor_unit' => Arr::int($row, 'minor_unit'), 'subtotal_minor' => Arr::int($row, 'subtotal_minor'), 'discount_minor' => Arr::int($row, 'discount_minor'), 'shipping_minor' => Arr::int($row, 'shipping_minor'), 'tax_minor' => Arr::int($row, 'tax_minor'), 'amount_minor' => Arr::int($row, 'amount_minor'), 'issued_at' => $row['issued_at'] ?? null, 'created_at' => $row['created_at'] ?? null, 'download' => ['available' => false, 'reason' => 'document_file_not_configured', 'content_type' => 'application/pdf', 'filename' => Arr::str($row, 'number', 'invoice') . '.pdf']]; }
    /** @return array<string, mixed> */
    private function memo(array $row): array { return ['id' => Arr::str($row, 'id'), 'number' => Arr::str($row, 'number'), 'state' => Arr::str($row, 'state', 'unknown'), 'currency' => Arr::str($row, 'currency', 'VND'), 'minor_unit' => Arr::int($row, 'minor_unit'), 'amount_minor' => Arr::int($row, 'amount_minor'), 'reason' => Arr::str($row, 'reason'), 'created_at' => $row['created_at'] ?? null, 'download' => ['available' => false, 'reason' => 'document_file_not_configured', 'content_type' => 'application/pdf', 'filename' => Arr::str($row, 'number', 'credit-memo') . '.pdf']]; }
    /** @return array<string, mixed> */
    private function refund(array $row): array { return ['id' => Arr::str($row, 'id'), 'state' => Arr::str($row, 'state', 'unknown'), 'currency' => Arr::str($row, 'currency', 'VND'), 'minor_unit' => Arr::int($row, 'minor_unit'), 'amount_minor' => Arr::int($row, 'amount_minor'), 'reason' => Arr::str($row, 'reason'), 'created_at' => $row['created_at'] ?? null]; }
}
